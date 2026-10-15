<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;

/**
 * Assisted PERDEA checkpoints at bottom-hem (6) and side-hem (7), route policy version 2 (API 2.34.0, migration 031).
 *
 * Under policy version 2 a PERDEA piece is not exempted from stages 6 and 7: a helper (any production employee who
 * reaches the order's source) may take the piece, do the work and bring it back, and the stage employee (or the
 * tailoring quality supervisor) checks the piece and records the result. One checkpoint exists per physical piece,
 * production cycle and stage, so one confirmation never covers another piece, another stage or a later cycle.
 *
 *   (no row)        awaiting_work   În așteptarea lucrării auxiliare
 *   taken_for_work                  Preluată pentru lucrare                (helper, piece scan)
 *   work_returned                   Înapoi la punctul de verificare        (the same helper, piece scan)
 *   verified                        Verificată – lucrare efectuată         (stage employee / supervisor, piece scan)
 *   not_performed                   Neefectuată – necesită intervenție     (stage employee / supervisor, piece scan)
 *
 * The carrier holding a PERDEA piece (the order or a track) can complete stage 6 or 7 only when every PERDEA piece on it
 * is verified for that stage in its current cycle (requireVerified). Nothing advances by time, by refresh or by a
 * scheduler. A verification is never a stage completion of the piece: PieceRouting writes no production_unit_stage_log
 * row for it, so neither stage nor piece-work credit follows from it; the verifier and the recorded helper are counted
 * separately (employee analytics).
 *
 * The policy applies to an order when the perdea_assisted_checkpoints feature applies to it (global flag or audited
 * pilot cohort), and stays with the order once it has a checkpoint row. Historical exemptions of policy version 1 are
 * never rewritten. Runs inside the caller's transaction after the caller locked the order row.
 */
final readonly class AssistedCheckpoints
{
    public const AWAITING = 'awaiting_work';
    public const TAKEN = 'taken_for_work';
    public const RETURNED = 'work_returned';
    public const VERIFIED = 'verified';
    public const NOT_PERFORMED = 'not_performed';
    public const ACTIONS = ['take', 'return', 'verify'];
    public const OUTCOMES = ['performed', 'not_performed'];

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

    /** Whether route policy version 2 governs the order (feature applies, or the order already has a checkpoint). */
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
     * The assisted checkpoints a carrier (the order: $trackUuid null, or one track) needs at $stageId, with their state.
     * null: the stage has no assisted requirement for this order. planRequired: a line of the order carrier has no
     * confirmed plan, so its pieces (possibly PERDEA) cannot be checked one by one; the stage cannot complete.
     *
     * @return array{stageId: string, policyVersion: int, planRequired: bool, required: int, verified: int, pieces: list<array<string, mixed>>}|null
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
        $planRequired = $trackUuid === null && array_filter($lines, static fn (array $line): bool => !$line['known']) !== [];
        $states = $this->states($orderUuid);
        $pieces = [];
        foreach ((new WorkUnits($this->pdo))->carrierUnits($orderUuid, $trackUuid) as $unit) {
            $line = $lines[(string) $unit['item_uuid']] ?? null;
            if ($line === null || !$line['known'] || ProductRoutePolicy::requirement($workflow, (string) $line['plan']['productType'], $stageId, true) !== ProductRoutePolicy::ASSISTED_CHECKPOINT) {
                continue;
            }
            $state = $states[(string) $unit['unit_uuid'] . '|' . (int) $unit['rework_cycle'] . '|' . $stageId] ?? null;
            $pieces[] = self::view($unit, $line, $stageId, $state, $viewerUuid);
        }
        return [
            'stageId' => $stageId,
            'policyVersion' => ProductRoutePolicy::VERSION_ASSISTED,
            'planRequired' => $planRequired,
            'required' => count($pieces),
            'verified' => count(array_filter($pieces, static fn (array $piece): bool => $piece['status'] === self::VERIFIED)),
            'pieces' => $pieces,
        ];
    }

    /** Refuses the completion of $stageId by the carrier while a PERDEA piece on it is not verified (current cycle). */
    public function requireVerified(ProductionWorkflow $workflow, string $orderUuid, ?string $trackUuid, string $stageId): void
    {
        $carrier = $this->carrier($workflow, $orderUuid, $trackUuid, $stageId);
        if ($carrier === null) {
            return;
        }
        if ($carrier['planRequired']) {
            throw new ApiException(409, 'PLAN_CONFIRMATION_REQUIRED', 'Confirmă planul de producție: la această etapă fiecare perdea se verifică pe bucată.');
        }
        $pending = array_values(array_filter($carrier['pieces'], static fn (array $piece): bool => $piece['status'] !== self::VERIFIED));
        if ($pending !== []) {
            throw new ApiException(409, 'ASSISTED_VERIFICATION_REQUIRED', 'Lucrarea auxiliară pentru această perdea nu a fost confirmată.', [
                'stageId' => $stageId,
                'pieces' => array_map(static fn (array $piece): array => ['unitId' => $piece['unitId'], 'piece' => $piece['piece'], 'lineNumber' => $piece['lineNumber'], 'status' => $piece['status']], $pending),
            ]);
        }
    }

    /** Unit ids (in $units) whose stage $stageId is an assisted checkpoint under policy version 2. @param list<array<string, mixed>> $units with product_type @return list<string> */
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

    /** Verified checkpoints of an order: "unit|cycle|stage" => true. @return array<string, true> */
    public function verifiedKeys(string $orderUuid): array
    {
        $keys = [];
        foreach ($this->states($orderUuid) as $key => $state) {
            if ($state['status'] === self::VERIFIED) {
                $keys[$key] = true;
            }
        }
        return $keys;
    }

    /** Current checkpoint rows of an order, keyed "unit|cycle|stage". @return array<string, array<string, mixed>> */
    public function states(string $orderUuid): array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT c.*, h.display_name AS helper_name, v.display_name AS verifier_name FROM production_assisted_checkpoints c
                 LEFT JOIN employees h ON h.employee_uuid = c.helper_employee_uuid LEFT JOIN employees v ON v.employee_uuid = c.verified_by_employee_uuid
                 WHERE c.order_uuid = ?',
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

    /** The checkpoint row of one piece cycle stage, locked. @return array<string, mixed>|null */
    public function lockRow(string $unitUuid, int $cycle, string $stageId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM production_assisted_checkpoints WHERE unit_uuid = ? AND rework_cycle = ? AND stage_id = ? FOR UPDATE');
        $statement->execute([$unitUuid, $cycle, $stageId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Applies one checkpoint action after the caller checked identity, authority, scan, version and stage. Returns the
     * new version. take: helper takes the piece (from awaiting or after a negative check). return: the same helper brings
     * it back. verify: the stage employee records performed / not_performed (never while the piece is with the helper,
     * never by the recorded helper; a positive result can be withdrawn while the carrier is still at the stage).
     *
     * @param array<string, mixed> $order @param array<string, mixed> $unit @param array<string, mixed>|null $current locked row
     */
    public function apply(array $order, array $unit, ?string $trackUuid, string $stageId, ?array $current, string $action, ?string $outcome, string $actorUuid, string $via, string $scanned, ?string $note, string $requestId, string $key, string $now): int
    {
        $before = $current === null ? null : (string) $current['status'];
        $helper = $current === null || $current['helper_employee_uuid'] === null ? null : (string) $current['helper_employee_uuid'];
        $state = $before ?? self::AWAITING;
        if ($action === 'take') {
            if (!in_array($state, [self::AWAITING, self::NOT_PERFORMED], true)) {
                throw self::stateError($state);
            }
            $after = self::TAKEN;
            $columns = ['status' => $after, 'helper_employee_uuid' => $actorUuid, 'taken_at' => $now, 'returned_at' => null, 'verified_by_employee_uuid' => null, 'verified_at' => null, 'verified_via' => null];
            $helper = $actorUuid;
        } elseif ($action === 'return') {
            if ($state !== self::TAKEN) {
                throw self::stateError($state);
            }
            if ($helper !== $actorUuid) {
                throw new ApiException(403, 'ASSISTED_HELPER_MISMATCH', 'Numai angajatul care a preluat perdeaua o poate preda înapoi.');
            }
            $after = self::RETURNED;
            $columns = ['status' => $after, 'returned_at' => $now];
        } else {
            if ($state === self::TAKEN) {
                throw new ApiException(409, 'ASSISTED_WORK_IN_PROGRESS', 'Perdeaua este încă la lucrarea auxiliară. Verific-o după ce este adusă înapoi.');
            }
            if ($helper !== null && $helper === $actorUuid && in_array($state, [self::RETURNED, self::VERIFIED], true)) {
                throw new ApiException(403, 'ASSISTED_SELF_VERIFICATION', 'Cine a efectuat lucrarea auxiliară nu o poate verifica.');
            }
            $after = $outcome === 'performed' ? self::VERIFIED : self::NOT_PERFORMED;
            if ($after === $state) {
                throw self::stateError($state);
            }
            $columns = ['status' => $after, 'verified_by_employee_uuid' => $actorUuid, 'verified_at' => $now, 'verified_via' => $via];
            if ($after === self::NOT_PERFORMED) {
                // The helper of a rejected attempt keeps no credit for it: the next attempt records its own helper.
                $columns += ['helper_employee_uuid' => null, 'taken_at' => null, 'returned_at' => null];
            }
        }
        $version = $current === null ? 1 : (int) $current['version'] + 1;
        if ($current === null) {
            $row = $columns + ['helper_employee_uuid' => null, 'taken_at' => null, 'returned_at' => null, 'verified_by_employee_uuid' => null, 'verified_at' => null, 'verified_via' => null];
            $this->pdo->prepare(
                'INSERT INTO production_assisted_checkpoints (unit_uuid, rework_cycle, stage_id, order_uuid, status, helper_employee_uuid, taken_at, returned_at, verified_by_employee_uuid, verified_at, verified_via, carrier_track_uuid, policy_version, version, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
            )->execute([
                $unit['unit_uuid'], (int) $unit['rework_cycle'], $stageId, $order['order_uuid'], $row['status'], $row['helper_employee_uuid'], $row['taken_at'], $row['returned_at'],
                $row['verified_by_employee_uuid'], $row['verified_at'], $row['verified_via'], $trackUuid, ProductRoutePolicy::VERSION_ASSISTED, $now, $now,
            ]);
        } else {
            $set = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($columns)));
            $update = $this->pdo->prepare("UPDATE production_assisted_checkpoints SET {$set}, carrier_track_uuid = ?, version = ?, updated_at = ? WHERE unit_uuid = ? AND rework_cycle = ? AND stage_id = ? AND version = ?");
            $update->execute([...array_values($columns), $trackUuid, $version, $now, $unit['unit_uuid'], (int) $unit['rework_cycle'], $stageId, (int) $current['version']]);
            if ($update->rowCount() !== 1) {
                throw new ApiException(409, 'ASSISTED_CHECKPOINT_CHANGED', 'Verificarea perdelei s-a schimbat. Reîncarcă înainte de a continua.');
            }
        }
        $this->pdo->prepare(
            'INSERT INTO production_assisted_checkpoint_events (event_uuid, unit_uuid, rework_cycle, stage_id, order_uuid, action, status_before, status_after, actor_employee_uuid, helper_employee_uuid, actor_via, carrier_track_uuid, version_after, scanned, note, request_id, idempotency_key, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            Uuid::v4(), $unit['unit_uuid'], (int) $unit['rework_cycle'], $stageId, $order['order_uuid'], $after, $before, $after, $actorUuid, $helper, $via, $trackUuid, $version, $scanned,
            $note, mb_substr($requestId, 0, 100), mb_substr($key, 0, 128), $now,
        ]);
        return $version;
    }

    /** History of the checkpoints of one piece (every cycle and stage). @return list<array<string, mixed>> */
    public function history(string $unitUuid): array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT e.rework_cycle, e.stage_id, e.action, e.status_before, e.version_after, e.actor_via, e.scanned, e.note, e.occurred_at, a.display_name AS actor_name, h.display_name AS helper_name
                 FROM production_assisted_checkpoint_events e INNER JOIN employees a ON a.employee_uuid = e.actor_employee_uuid LEFT JOIN employees h ON h.employee_uuid = e.helper_employee_uuid
                 WHERE e.unit_uuid = ? ORDER BY e.occurred_at, e.version_after',
            );
            $statement->execute([$unitUuid]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return [];
        }
        return array_map(static fn (array $row): array => [
            'reworkCycle' => (int) $row['rework_cycle'], 'stageId' => (string) $row['stage_id'], 'action' => (string) $row['action'], 'statusBefore' => $row['status_before'] ?? self::AWAITING,
            'version' => (int) $row['version_after'], 'by' => (string) $row['actor_name'], 'via' => (string) $row['actor_via'], 'helper' => $row['helper_name'], 'scanned' => (string) $row['scanned'],
            'note' => $row['note'], 'at' => self::iso((string) $row['occurred_at']),
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
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
            'status' => $state === null ? self::AWAITING : (string) $state['status'],
            'version' => $state === null ? 0 : (int) $state['version'],
            'helper' => $state === null || $state['helper_employee_uuid'] === null ? null : ['displayName' => (string) $state['helper_name'], 'isMe' => $viewerUuid !== null && $state['helper_employee_uuid'] === $viewerUuid],
            'takenAt' => $state === null || $state['taken_at'] === null ? null : self::iso((string) $state['taken_at']),
            'returnedAt' => $state === null || $state['returned_at'] === null ? null : self::iso((string) $state['returned_at']),
            'verifiedBy' => $state === null || $state['verified_by_employee_uuid'] === null ? null : ['displayName' => (string) $state['verifier_name'], 'isMe' => $viewerUuid !== null && $state['verified_by_employee_uuid'] === $viewerUuid],
            'verifiedAt' => $state === null || $state['verified_at'] === null ? null : self::iso((string) $state['verified_at']),
        ];
    }

    private static function stateError(string $state): ApiException
    {
        return new ApiException(409, 'ASSISTED_CHECKPOINT_STATE', match ($state) {
            self::TAKEN => 'Perdeaua este deja preluată pentru lucrare.',
            self::RETURNED => 'Perdeaua a fost adusă înapoi și așteaptă verificarea.',
            self::VERIFIED => 'Perdeaua este deja verificată la această etapă.',
            self::NOT_PERFORMED => 'Lucrarea a fost marcată neefectuată; perdeaua trebuie preluată din nou pentru lucrare.',
            default => 'Perdeaua nu a fost încă preluată pentru lucrare.',
        }, ['status' => $state]);
    }

    private static function iso(string $value): string
    {
        return substr(str_replace(' ', 'T', $value), 0, 23) . 'Z';
    }
}
