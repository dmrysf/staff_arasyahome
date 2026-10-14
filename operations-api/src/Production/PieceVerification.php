<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Http\ApiException;
use PDO;

/** Current-cycle defect resolution, independent of whether pieces travel on the order or a track. */
final readonly class PieceVerification
{
    public function __construct(private PDO $pdo) {}

    public function requireFor(string $unit, int $cycle, string $order, string $exception, string $stage): void
    {
        $this->pdo->prepare('INSERT INTO production_unit_verifications (unit_uuid, rework_cycle, order_uuid, exception_uuid, detected_stage_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$unit, $cycle, $order, $exception, $stage]);
    }

    /** Caller holds order lock and carrier ownership; never reuse a confirmation from an earlier cycle. */
    public function complete(ProductionWorkflow $workflow, string $order, ?string $track, string $current, bool $confirmed, string $actor, int $version, string $requestId, string $now): bool
    {
        $rows = $this->pdo->prepare("SELECT v.unit_uuid, v.rework_cycle, v.detected_stage_id FROM production_unit_verifications v
            INNER JOIN production_work_units u ON u.unit_uuid = v.unit_uuid AND u.rework_cycle = v.rework_cycle
            WHERE u.order_uuid = ? AND u.status = 'active' AND u.track_uuid <=> ? AND v.confirmed_at IS NULL FOR UPDATE");
        $rows->execute([$order, $track]);
        $ordinals = [];
        foreach ($workflow->stages as $stage) $ordinals[$stage->id] = $stage->ordinal;
        $due = array_filter($rows->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool => ($ordinals[$current] ?? 0) >= ($ordinals[$row['detected_stage_id']] ?? PHP_INT_MAX));
        if ($due !== [] && !$confirmed) {
            throw new ApiException(422, 'DEFECT_RESOLUTION_REQUIRED', 'Confirmă că defectul inițial a fost remediat la bucățile din acest ciclu.');
        }
        $update = $this->pdo->prepare('UPDATE production_unit_verifications SET confirmed_at = ?, confirmed_by_employee_uuid = ?, confirmed_stage_id = ?, carrier_version_before = ?, request_id = ? WHERE unit_uuid = ? AND rework_cycle = ? AND confirmed_at IS NULL');
        foreach ($due as $row) $update->execute([$now, $actor, $current, $version, mb_substr($requestId, 0, 100), $row['unit_uuid'], $row['rework_cycle']]);
        return $due !== [];
    }

    /** A replacement inherits the unresolved physical incident, not a fictitious completion. */
    public function transfer(string $original, string $replacement, int $cycle): void
    {
        $this->pdo->prepare('INSERT INTO production_unit_verifications (unit_uuid, rework_cycle, order_uuid, exception_uuid, detected_stage_id)
            SELECT ?, rework_cycle, order_uuid, exception_uuid, detected_stage_id FROM production_unit_verifications WHERE unit_uuid = ? AND rework_cycle = ? AND confirmed_at IS NULL')
            ->execute([$replacement, $original, $cycle]);
    }
}
