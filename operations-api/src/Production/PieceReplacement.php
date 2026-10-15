<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Repair versus full replacement of reworked pieces (API 2.32.0), declared by the cutter when the re-cutting of a rework
 * cycle is completed, because only the cutter knows whether new fabric was cut.
 *
 *   repair        the same physical piece continues: same identity, same label, the rework cycle already advanced
 *   replacement   the original piece is retired (scrapped or quarantined) and its labels are revoked; a new piece with a
 *                 new identity and a new label takes the same deliverable slot ("Bucata 1/2" stays 1 of 2), linked to
 *                 the original in production_unit_replacements. The new piece starts its ledger at cutting in the
 *                 current cycle. The planned fabric of the replacement is recorded (unknown stays unknown) without any
 *                 stock or price effect. One replacement per original: a retry can never create a second one.
 *
 * Runs inside the caller's transaction, after the order, employee and carrier locks.
 */
final readonly class PieceReplacement
{
    public const REPAIR = 'repair';
    public const REPLACEMENT = 'replacement';
    public const OUTCOMES = [self::REPAIR, self::REPLACEMENT];
    public const DISPOSITIONS = ['scrapped', 'quarantined'];
    /** Replacement pieces get serial numbers far above any plan slot, so a slot's original identity stays free. */
    private const SERIAL_BASE = 1000;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Whether the cutting completion of these pieces must declare repair or replacement: they are in a rework cycle (> 1)
     * that started at cutting, and cutting was not completed yet in that cycle. @param list<array<string, mixed>> $units
     */
    public function outcomeRequired(array $units, string $cuttingStage): bool
    {
        $check = $this->pdo->prepare(
            "SELECT 1 FROM production_unit_cycles c WHERE c.unit_uuid = ? AND c.rework_cycle = ? AND c.started_stage_id = ?
               AND NOT EXISTS (SELECT 1 FROM production_unit_stage_log l WHERE l.unit_uuid = c.unit_uuid AND l.rework_cycle = c.rework_cycle AND l.stage_id = c.started_stage_id AND l.outcome = 'completed')",
        );
        foreach ($units as $unit) {
            if ((int) $unit['rework_cycle'] < 2) {
                continue;
            }
            $check->execute([$unit['unit_uuid'], (int) $unit['rework_cycle'], $cuttingStage]);
            if ($check->fetchColumn() !== false) {
                return true;
            }
        }
        return false;
    }

    /** Validate exact per-piece outcomes, then apply only the declared replacements. Caller owns the carrier. */
    public function applyOutcomes(string $order, ?string $track, ?string $exception, string $stage, array $input, string $actor, string $requestId, string $key, string $now): array
    {
        $uniform = $input['pieceOutcome'] ?? null;
        $entries = $input['pieceOutcomes'] ?? null;
        if ($entries !== null && ($uniform !== null || isset($input['originalDisposition']))) throw new ApiException(400, 'INVALID_REQUEST', 'Folosește pieceOutcome sau pieceOutcomes, nu ambele.');
        $declared = $uniform !== null || $entries !== null;
        $units = (new WorkUnits($this->pdo))->carrierUnits($order, $track);
        $pending = [];
        if ($stage === \Arasya\Operations\Cutting\CuttingLifecycle::STAGE) {
            foreach ($units as $unit) if ($this->outcomeRequired([$unit], $stage)) $pending[(string) $unit['unit_uuid']] = $unit;
        }
        if ($pending === []) {
            if ($declared) throw new ApiException(422, 'PIECE_OUTCOME_NOT_APPLICABLE', 'Rezultatul se declară numai la tăierea din ciclul de refacere.');
            return [];
        }
        if (!$declared) throw new ApiException(422, 'PIECE_OUTCOME_REQUIRED', 'Declară reparare sau înlocuire pentru fiecare bucată refăcută.');
        if ($entries === null) {
            $entries = array_map(static fn (string $id): array => ['unitId' => $id, 'outcome' => $uniform] + (isset($input['originalDisposition']) ? ['originalDisposition' => $input['originalDisposition']] : []), array_keys($pending));
        }
        if (!is_array($entries) || !array_is_list($entries) || count($entries) !== count($pending)) throw new ApiException(422, 'PIECE_OUTCOMES_INVALID', 'Declară fiecare bucată din acest retur exact o dată.');
        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), ['unitId', 'outcome', 'originalDisposition']) !== []
                || !is_string($entry['unitId'] ?? null) || !isset($pending[$entry['unitId']]) || isset($seen[$entry['unitId']])
                || !in_array($entry['outcome'] ?? null, self::OUTCOMES, true)) throw new ApiException(422, 'PIECE_OUTCOMES_INVALID', 'Rezultatele trebuie să numească exact bucățile din retur.');
            $seen[$entry['unitId']] = true;
            if ($entry['outcome'] === self::REPLACEMENT && !in_array($entry['originalDisposition'] ?? null, self::DISPOSITIONS, true)) throw new ApiException(422, 'ORIGINAL_DISPOSITION_REQUIRED', 'Confirmă casarea sau carantina originalului înlocuit.');
            if ($entry['outcome'] === self::REPAIR && array_key_exists('originalDisposition', $entry)) throw new ApiException(400, 'INVALID_REQUEST', 'Originalul reparat nu este casat.');
        }
        $result = [];
        foreach ($entries as $entry) {
            if ($entry['outcome'] === self::REPLACEMENT) $result = [...$result, ...$this->replace($order, $track, $exception, $entry['originalDisposition'], $actor, $stage, $requestId, $key, $now, [$entry['unitId']])];
        }
        return $result;
    }

    /**
     * Replaces every active piece of the carrier (the caller locked it). @return list<array{original: string, replacement: string, slot: int}>
     */
    public function replace(string $orderUuid, ?string $trackUuid, ?string $exceptionUuid, string $disposition, string $actorUuid, string $cuttingStage, string $requestId, string $key, string $now, ?array $unitIds = null): array
    {
        if (!in_array($disposition, self::DISPOSITIONS, true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'originalDisposition trebuie să fie scrapped sau quarantined.');
        }
        $units = (new WorkUnits($this->pdo))->carrierUnits($orderUuid, $trackUuid);
        $lines = [];
        foreach ((new ManufacturingPlans($this->pdo))->lines($orderUuid) as $line) {
            $lines[(string) $line['itemId']] = $line;
        }
        $result = [];
        foreach ($units as $unit) {
            if ($unitIds !== null && !in_array($unit['unit_uuid'], $unitIds, true)) continue;
            $original = (string) $unit['unit_uuid'];
            $item = (string) $unit['item_uuid'];
            $slot = (int) $unit['slot'];
            $cycle = (int) $unit['rework_cycle'];
            $serial = $this->pdo->prepare('SELECT GREATEST(?, COALESCE(MAX(unit_index), 0)) + 1 FROM production_work_units WHERE item_uuid = ? AND unit_index > ?');
            $serial->execute([self::SERIAL_BASE, $item, self::SERIAL_BASE]);
            $index = (int) $serial->fetchColumn();
            $replacement = Uuid::v4();
            $retire = $this->pdo->prepare("UPDATE production_work_units SET status = 'retired', retired_at = ?, updated_at = ? WHERE unit_uuid = ? AND status = 'active'");
            $retire->execute([$now, $now, $original]);
            if ($retire->rowCount() !== 1) {
                throw new ApiException(409, 'TRACK_CHANGED', 'Bucata în refacere s-a schimbat. Reîncarcă înainte de a continua.');
            }
            $this->pdo->prepare("UPDATE production_unit_labels SET status = 'revoked', revoked_at = ? WHERE unit_uuid = ? AND status = 'active'")->execute([$now, $original]);
            $this->pdo->prepare(
                "INSERT INTO production_work_units (unit_uuid, order_uuid, item_uuid, unit_index, status, track_uuid, rework_cycle, identity_source, review_required, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'active', ?, ?, 'on_demand', 0, ?, ?)",
            )->execute([$replacement, $orderUuid, $item, $index, $trackUuid, $cycle, $now, $now]);
            $this->pdo->prepare(
                'INSERT INTO production_unit_replacements (replacement_unit_uuid, original_unit_uuid, order_uuid, item_uuid, slot_index, track_uuid, exception_uuid, rework_cycle, original_disposition, decided_by_employee_uuid, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([$replacement, $original, $orderUuid, $item, $slot, $trackUuid, $exceptionUuid, $cycle, $disposition, $actorUuid, $now]);
            (new WorkUnits($this->pdo))->startCycle($replacement, $cycle, $cuttingStage, $now);
            (new PieceVerification($this->pdo))->transfer($original, $replacement, $cycle);
            (new PieceCustodyReceipt($this->pdo))->receive($replacement, $orderUuid, $actorUuid, $cuttingStage, ['createdByCutting' => true, 'replacementOf' => $original], $requestId, $key, $now);
            (new WorkUnits($this->pdo))->issueLabels($orderUuid, [$replacement], $actorUuid, $now);
            // Custody of the retired original stays as history; the replacement is with the cutter who made it.
            $fabric = null;
            foreach ($lines[$item]['plan']['pieces'] ?? [] as $piece) {
                if ((int) $piece['index'] === $slot) {
                    $fabric = $piece['fabricMeters'];
                }
            }
            (new MaterialLedger($this->pdo))->recordSystem($orderUuid, $item, $replacement, $exceptionUuid, $cycle, 'replacement_planned', $fabric, 'replacement_planned|' . $original, 'Metraj planificat pentru înlocuire (din planul confirmat).', $actorUuid, $requestId, $key . '.material.' . $slot, $now);
            $result[] = ['original' => $original, 'replacement' => $replacement, 'slot' => $slot];
        }
        return $result;
    }
}
