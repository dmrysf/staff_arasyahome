<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;

/**
 * Informational PERDEA auxiliary-work status at bottom-hem (6) and side-hem (7), route policy version 2 (API 2.34.0,
 * migration 031).
 *
 * Under policy version 2 a PERDEA piece is not exempted from stages 6 and 7. Its auxiliary work may be done by another
 * employee; the stage employee (or the tailoring quality supervisor) can record per physical piece, stage and
 * production cycle whether it was done:
 *
 *   unmarked        Neconfirmată   (never marked; also stored when the carrier leaves the stage unmarked)
 *   performed       Efectuată
 *   not_performed   Neefectuată
 *
 * The status is information, never a gate: no stage transition, track completion, packing or delivery depends on it,
 * and it is never a manufacturing completion (PieceRouting writes no production_unit_stage_log row for the PERDEA piece
 * at those stages, so nobody earns stage or piece-work credit for it). A stage-6 mark never stands for stage 7, a
 * cycle-1 mark never for cycle 2, one mark never covers another piece.
 *
 * The policy applies to an order when the perdea_assisted_checkpoints feature applies to it (global flag or audited
 * pilot cohort), and stays with the order once it has a status row. Historical exemptions of policy version 1 are never
 * rewritten. Runs inside the caller's transaction after the caller locked the order row.
 */
final readonly class AssistedCheckpoints
{
    public const UNMARKED = 'unmarked';
    public const PERFORMED = 'performed';
    public const NOT_PERFORMED = 'not_performed';
    public const STATUSES = [self::UNMARKED, self::PERFORMED, self::NOT_PERFORMED];

    public function __construct(private PDO $pdo)
    {
    }

    public function available(): bool
    {
        try {
            $statement = $this->pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $statement->execute(['production_assisted_checkpoints']);
            return $statement->fetchColumn() !== false;
        } catch (PDOException) {
            return false;
        }
    }

    /** Whether route policy version 2 governs the order (feature applies, or the order already has a status row). */
    public function appliesTo(string $orderUuid): bool
    {
        if ((new FeatureActivation($this->pdo))->appliesTo(FeatureActivation::PERDEA_ASSISTED_CHECKPOINTS, $orderUuid)) {
            return true;
        }
        try {
            $statement = $this->pdo->prepare('SELECT 1 FROM production_assisted_checkpoints WHERE order_uuid = ? LIMIT 1');
            $statement->execute([$orderUuid]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return false;
        }
    }

    /**
     * The PERDEA status of a carrier (the order: $trackUuid null, or one track) at $stageId, for display only. null: the
     * stage has no PERDEA status for this order. unconfirmedLines: lines of the order carrier without a confirmed piece
     * plan whose source classification is PERDEA or unknown; they are listed per line, never as invented pieces.
     *
     * @return array{stageId: string, policyVersion: int, informational: true, total: int, performed: int, notPerformed: int, unmarked: int, pieces: list<array<string, mixed>>, unconfirmedLines: list<array<string, mixed>>}|null
     */
    public function carrier(ProductionWorkflow $workflow, string $orderUuid, ?string $trackUuid, string $stageId, ?string $viewerUuid = null): ?array
    {
        if (!ProductRoutePolicy::assistedStage($workflow, $stageId) || !$this->appliesTo($orderUuid)) {
            return null;
        }
        $lines = [];
        foreach ((new ManufacturingPlans($this->pdo))->lines($orderUuid) as $line) {
            $lines[(string) $line['itemId']] = $line;
        }
        $unconfirmed = [];
        if ($trackUuid === null) {
            foreach ($lines as $line) {
                if (!$line['known'] && in_array($line['plan']['productType'], [ProductRoutePolicy::PERDEA, ProductRoutePolicy::UNKNOWN], true)) {
                    $unconfirmed[] = ['itemId' => (string) $line['itemId'], 'lineNumber' => (int) $line['lineNumber'], 'name' => (string) $line['name'], 'code' => $line['code'], 'productType' => (string) $line['plan']['productType']];
                }
            }
        }
        $states = $this->states($orderUuid);
        $pieces = [];
        foreach ((new WorkUnits($this->pdo))->carrierUnits($orderUuid, $trackUuid) as $unit) {
            $line = $lines[(string) $unit['item_uuid']] ?? null;
            if ($line === null || !$line['known'] || ProductRoutePolicy::requirement($workflow, (string) $line['plan']['productType'], $stageId, true) !== ProductRoutePolicy::ASSISTED_CHECKPOINT) {
                continue;
            }
            $pieces[] = self::view($unit, $line, $stageId, $states[(string) $unit['unit_uuid'] . '|' . (int) $unit['rework_cycle'] . '|' . $stageId] ?? null, $viewerUuid);
        }
        if ($pieces === [] && $unconfirmed === []) {
            return null;
        }
        $count = static fn (string $status): int => count(array_filter($pieces, static fn (array $piece): bool => $piece['status'] === $status));
        return [
            'stageId' => $stageId,
            'policyVersion' => ProductRoutePolicy::VERSION_ASSISTED,
            'informational' => true,
            'total' => count($pieces),
            'performed' => $count(self::PERFORMED),
            'notPerformed' => $count(self::NOT_PERFORMED),
            'unmarked' => $count(self::UNMARKED),
            'pieces' => $pieces,
            'unconfirmedLines' => $unconfirmed,
        ];
    }

    /** Unit ids (in $units) whose stage $stageId carries a PERDEA status under policy version 2. @param list<array<string, mixed>> $units with product_type @return list<string> */
    public static function checkpointUnits(ProductionWorkflow $workflow, array $units, string $stageId): array
    {
        $ids = [];
        foreach ($units as $unit) {
            if (ProductRoutePolicy::requirement($workflow, (string) ($unit['product_type'] ?? ProductRoutePolicy::UNKNOWN), $stageId, true) === ProductRoutePolicy::ASSISTED_CHECKPOINT) {
                $ids[] = (string) $unit['unit_uuid'];
            }
        }
        return $ids;
    }

    /**
     * When a carrier leaves stage 6 / 7, every PERDEA piece on it without a status row gets an "unmarked" row: the
     * history then shows the stage was passed without a mark (never a fabricated "performed"). Existing marks stay.
     * @param list<array<string, mixed>> $units carrier units (unit_uuid, rework_cycle)
     */
    public function recordPassed(array $units, array $unitIds, string $orderUuid, ?string $trackUuid, string $stageId, string $now): void
    {
        if ($unitIds === []) {
            return;
        }
        $insert = $this->pdo->prepare(
            "INSERT IGNORE INTO production_assisted_checkpoints (unit_uuid, rework_cycle, stage_id, order_uuid, status, marked_by_employee_uuid, marked_at, marked_via, carrier_track_uuid, policy_version, version, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'unmarked', NULL, NULL, NULL, ?, ?, 1, ?, ?)",
        );
        foreach ($units as $unit) {
            if (in_array((string) $unit['unit_uuid'], $unitIds, true)) {
                $insert->execute([$unit['unit_uuid'], (int) $unit['rework_cycle'], $stageId, $orderUuid, $trackUuid, ProductRoutePolicy::VERSION_ASSISTED, $now, $now]);
            }
        }
    }

    /** Current status rows of an order, keyed "unit|cycle|stage". @return array<string, array<string, mixed>> */
    public function states(string $orderUuid): array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT c.*, m.display_name AS marker_name FROM production_assisted_checkpoints c
                 LEFT JOIN employees m ON m.employee_uuid = c.marked_by_employee_uuid WHERE c.order_uuid = ?',
            );
            $statement->execute([$orderUuid]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return [];
        }
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['unit_uuid'] . '|' . (int) $row['rework_cycle'] . '|' . (string) $row['stage_id']] = $row;
        }
        return $result;
    }

    /** The status row of one piece cycle stage, locked. @return array<string, mixed>|null */
    public function lockRow(string $unitUuid, int $cycle, string $stageId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM production_assisted_checkpoints WHERE unit_uuid = ? AND rework_cycle = ? AND stage_id = ? FOR UPDATE');
        $statement->execute([$unitUuid, $cycle, $stageId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Records one mark after the caller checked identity, stage authority, version and stage. Returns the new version.
     * Setting the status it already has is refused (the screen is stale), so every event is a real change.
     *
     * @param array<string, mixed> $order @param array<string, mixed> $unit @param array<string, mixed>|null $current locked row
     */
    public function mark(array $order, array $unit, ?string $trackUuid, string $stageId, ?array $current, string $status, string $actorUuid, string $via, ?string $scanned, ?string $note, string $requestId, string $key, string $now): int
    {
        $before = $current === null ? self::UNMARKED : (string) $current['status'];
        if ($before === $status) {
            throw new ApiException(409, 'ASSISTED_CHECKPOINT_STATE', 'Starea lucrării auxiliare este deja salvată. Reîncarcă comanda.', ['status' => $before]);
        }
        $version = $current === null ? 1 : (int) $current['version'] + 1;
        if ($current === null) {
            $this->pdo->prepare(
                'INSERT INTO production_assisted_checkpoints (unit_uuid, rework_cycle, stage_id, order_uuid, status, marked_by_employee_uuid, marked_at, marked_via, carrier_track_uuid, policy_version, version, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
            )->execute([$unit['unit_uuid'], (int) $unit['rework_cycle'], $stageId, $order['order_uuid'], $status, $actorUuid, $now, $via, $trackUuid, ProductRoutePolicy::VERSION_ASSISTED, $now, $now]);
        } else {
            $update = $this->pdo->prepare('UPDATE production_assisted_checkpoints SET status = ?, marked_by_employee_uuid = ?, marked_at = ?, marked_via = ?, carrier_track_uuid = ?, version = ?, updated_at = ?
                WHERE unit_uuid = ? AND rework_cycle = ? AND stage_id = ? AND version = ?');
            $update->execute([$status, $actorUuid, $now, $via, $trackUuid, $version, $now, $unit['unit_uuid'], (int) $unit['rework_cycle'], $stageId, (int) $current['version']]);
            if ($update->rowCount() !== 1) {
                throw new ApiException(409, 'ASSISTED_CHECKPOINT_CHANGED', 'Starea perdelei s-a schimbat. Reîncarcă înainte de a continua.');
            }
        }
        $this->pdo->prepare(
            'INSERT INTO production_assisted_checkpoint_events (event_uuid, unit_uuid, rework_cycle, stage_id, order_uuid, status_before, status_after, actor_employee_uuid, actor_via, carrier_track_uuid, version_after, scanned, note, request_id, idempotency_key, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([Uuid::v4(), $unit['unit_uuid'], (int) $unit['rework_cycle'], $stageId, $order['order_uuid'], $before, $status, $actorUuid, $via, $trackUuid, $version, $scanned, $note, mb_substr($requestId, 0, 100), mb_substr($key, 0, 128), $now]);
        return $version;
    }

    /** History of the marks of one piece (every cycle and stage). @return list<array<string, mixed>> */
    public function history(string $unitUuid): array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT e.rework_cycle, e.stage_id, e.status_before, e.status_after, e.version_after, e.actor_via, e.scanned, e.note, e.occurred_at, a.display_name AS actor_name
                 FROM production_assisted_checkpoint_events e INNER JOIN employees a ON a.employee_uuid = e.actor_employee_uuid
                 WHERE e.unit_uuid = ? ORDER BY e.occurred_at, e.version_after',
            );
            $statement->execute([$unitUuid]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return [];
        }
        return array_map(static fn (array $row): array => [
            'reworkCycle' => (int) $row['rework_cycle'], 'stageId' => (string) $row['stage_id'], 'statusBefore' => (string) $row['status_before'], 'status' => (string) $row['status_after'],
            'version' => (int) $row['version_after'], 'by' => (string) $row['actor_name'], 'via' => (string) $row['actor_via'], 'scanned' => $row['scanned'],
            'note' => $row['note'], 'at' => self::iso((string) $row['occurred_at']),
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Informational PERDEA statuses of active orders for the management overview (never blockers). @param list<string> $orderUuids
     * @return array{counts: array{performed: int, notPerformed: int, unmarked: int}, items: list<array<string, mixed>>}
     */
    public function overview(array $orderUuids, int $limit): array
    {
        $empty = ['counts' => ['performed' => 0, 'notPerformed' => 0, 'unmarked' => 0], 'items' => []];
        if ($orderUuids === []) {
            return $empty;
        }
        $marks = implode(',', array_fill(0, count($orderUuids), '?'));
        try {
            $statement = $this->pdo->prepare(
                "SELECT c.unit_uuid, c.rework_cycle, c.stage_id, c.status, c.marked_at, c.updated_at, m.display_name AS marker_name, o.global_order_id, o.order_number,
                        u.item_uuid, COALESCE(r.slot_index, u.unit_index) AS slot, i.line_number, i.name, i.product_code
                 FROM production_assisted_checkpoints c
                 INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid
                 INNER JOIN production_work_units u ON u.unit_uuid = c.unit_uuid
                 LEFT JOIN production_unit_replacements r ON r.replacement_unit_uuid = u.unit_uuid
                 LEFT JOIN operational_order_items i ON i.item_uuid = u.item_uuid
                 LEFT JOIN employees m ON m.employee_uuid = c.marked_by_employee_uuid
                 WHERE c.order_uuid IN ({$marks}) AND u.status = 'active' ORDER BY c.updated_at DESC, c.unit_uuid, c.stage_id",
            );
            $statement->execute($orderUuids);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return $empty;
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $counts = ['performed' => 0, 'notPerformed' => 0, 'unmarked' => 0];
        foreach ($rows as $row) {
            $counts[match ((string) $row['status']) { self::PERFORMED => 'performed', self::NOT_PERFORMED => 'notPerformed', default => 'unmarked' }]++;
        }
        return ['counts' => $counts, 'items' => array_map(static fn (array $row): array => [
            'orderId' => (string) $row['global_order_id'], 'orderNumber' => (string) $row['order_number'], 'unitId' => (string) $row['unit_uuid'],
            'lineNumber' => $row['line_number'] === null ? null : (int) $row['line_number'], 'name' => $row['name'], 'code' => $row['product_code'], 'piece' => (int) $row['slot'],
            'reworkCycle' => (int) $row['rework_cycle'], 'stageId' => (string) $row['stage_id'], 'status' => (string) $row['status'],
            'markedBy' => $row['marker_name'], 'markedAt' => $row['marked_at'] === null ? null : self::iso((string) $row['marked_at']),
        ], array_slice($rows, 0, $limit))];
    }

    /** @param array<string, mixed> $unit @param array<string, mixed> $line @param array<string, mixed>|null $state @return array<string, mixed> */
    private static function view(array $unit, array $line, string $stageId, ?array $state, ?string $viewerUuid): array
    {
        $count = (int) $line['plan']['pieceCount'];
        return [
            'unitId' => (string) $unit['unit_uuid'],
            'itemId' => (string) $unit['item_uuid'],
            'lineNumber' => (int) $line['lineNumber'],
            'name' => (string) $line['name'],
            'code' => $line['code'],
            'index' => (int) $unit['slot'],
            'count' => $count,
            'piece' => (int) $unit['slot'] . '/' . $count,
            'reworkCycle' => (int) $unit['rework_cycle'],
            'stageId' => $stageId,
            'status' => $state === null ? self::UNMARKED : (string) $state['status'],
            'version' => $state === null ? 0 : (int) $state['version'],
            'markedBy' => $state === null || $state['marked_by_employee_uuid'] === null ? null : ['displayName' => (string) $state['marker_name'], 'isMe' => $viewerUuid !== null && $state['marked_by_employee_uuid'] === $viewerUuid],
            'markedAt' => $state === null || $state['marked_at'] === null ? null : self::iso((string) $state['marked_at']),
        ];
    }

    private static function iso(string $value): string
    {
        return substr(str_replace(' ', 'T', $value), 0, 23) . 'Z';
    }
}
