<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Cutting\CuttingLifecycle;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;

/**
 * Verified historical stage evidence for pieces materialized after cutting (migration 030, API 2.33.0).
 *
 * The per-piece ledger of a production cycle starts at the stage where the piece was materialized. When that is later
 * than cutting (an order already in production when its plan was confirmed), the applicable stages from cutting up to
 * the ledger start have no per-piece completion, and the packing gate refuses the order (route_incomplete). This class
 * never fills that gap by itself. An authorized verifier (the appointed tailoring quality supervisor of the source or an
 * operations approver) reviews the genuine evidence of each such stage for one exact piece and records it:
 *
 *   order_activity        the order-level completion of that stage recorded at the time (order_activity_events,
 *                         stage_completed from that stage, before the piece's ledger started); the event is referenced,
 *                         never copied or changed
 *   physical_inspection   the verifier inspected the piece and the operation of that stage is physically present
 *
 * Stages the verifier cannot support are recorded as uncertain and keep blocking packing. Stages at or after the ledger
 * start, stages that do not apply to the piece's product type and stages already completed in the ledger can never be
 * reconciled. No employee is credited with work, no stage log row is written, no order moves.
 */
final readonly class RouteReconciliation
{
    public const ORDER_ACTIVITY = 'order_activity';
    public const PHYSICAL_INSPECTION = 'physical_inspection';

    public function __construct(private PDO $pdo)
    {
    }

    /** Whether migration 030 is applied. */
    public function available(): bool
    {
        return $this->pdo->query("SHOW TABLES LIKE 'production_route_reconciliation_stages'")->fetchColumn() !== false;
    }

    /**
     * Reconciled stages per piece cycle of an order. @return array<string, array<string, string>> "unit|cycle" => stage => evidence kind
     */
    public function reconciled(string $orderUuid): array
    {
        try {
            $statement = $this->pdo->prepare('SELECT unit_uuid, rework_cycle, stage_id, evidence_kind FROM production_route_reconciliation_stages WHERE order_uuid = ?');
            $statement->execute([$orderUuid]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return [];
        }
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['unit_uuid'] . '|' . (int) $row['rework_cycle']][(string) $row['stage_id']] = (string) $row['evidence_kind'];
        }
        return $result;
    }

    /**
     * The applicable stages of a piece cycle that precede its ledger start (from cutting onward), in workflow order.
     * Only these can ever be reconciled. @return list<string>
     */
    public static function preLedgerStages(ProductionWorkflow $workflow, string $productType, string $ledgerStart): array
    {
        $first = ProductRoutePolicy::canonical($workflow) ? CuttingLifecycle::STAGE : $workflow->stages[0]->id;
        $route = ProductRoutePolicy::route($workflow, $productType);
        $stages = [];
        $inside = false;
        foreach ($workflow->stages as $stage) {
            if ($stage->id === $ledgerStart) {
                return $stages;
            }
            $inside = $inside || $stage->id === $first;
            if ($inside && in_array($stage->id, $route, true)) {
                $stages[] = $stage->id;
            }
        }
        return $stages;
    }

    /**
     * Route evidence of every active piece of an order: where its ledger starts, which pre-ledger stages are reconciled,
     * which still need evidence, the latest uncertain declaration and the order-level completions recorded before the
     * ledger started (candidates the verifier may review). Read-only. @return array<string, array<string, mixed>> unit => evidence
     */
    public function evidence(ProductionWorkflow $workflow, string $orderUuid): array
    {
        try {
            $statement = $this->pdo->prepare(
                "SELECT u.unit_uuid, u.item_uuid, u.rework_cycle, c.started_stage_id, c.started_at
                 FROM production_work_units u LEFT JOIN production_unit_cycles c ON c.unit_uuid = u.unit_uuid AND c.rework_cycle = u.rework_cycle
                 WHERE u.order_uuid = ? AND u.status = 'active'",
            );
            $statement->execute([$orderUuid]);
            $units = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return [];
        }
        if ($units === []) {
            return [];
        }
        $types = [];
        foreach ((new ManufacturingPlans($this->pdo))->lines($orderUuid) as $line) {
            $types[(string) $line['itemId']] = (string) $line['plan']['productType'];
        }
        $log = $this->pdo->prepare("SELECT unit_uuid, rework_cycle, stage_id FROM production_unit_stage_log WHERE order_uuid = ? AND outcome = 'completed'");
        $log->execute([$orderUuid]);
        $completed = [];
        foreach ($log->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $completed[(string) $row['unit_uuid'] . '|' . (int) $row['rework_cycle']][(string) $row['stage_id']] = true;
        }
        $reconciled = $this->reconciled($orderUuid);
        $uncertain = $this->uncertain($orderUuid);
        $activity = $this->pdo->prepare(
            "SELECT a.event_id, a.from_stage_id, a.occurred_at, e.display_name FROM order_activity_events a INNER JOIN employees e ON e.employee_uuid = a.employee_uuid
             WHERE a.order_uuid = ? AND a.action = 'stage_completed' ORDER BY a.occurred_at, a.event_id",
        );
        $activity->execute([$orderUuid]);
        $events = $activity->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($units as $unit) {
            $key = (string) $unit['unit_uuid'] . '|' . (int) $unit['rework_cycle'];
            $start = $unit['started_stage_id'] === null ? null : (string) $unit['started_stage_id'];
            $pre = $start === null ? [] : self::preLedgerStages($workflow, $types[(string) $unit['item_uuid']] ?? ProductRoutePolicy::UNKNOWN, $start);
            $done = $reconciled[$key] ?? [];
            $candidates = [];
            foreach ($events as $event) {
                if (in_array($event['from_stage_id'], $pre, true) && !isset($done[$event['from_stage_id']]) && (string) $event['occurred_at'] <= (string) $unit['started_at']) {
                    $candidates[] = ['stageId' => (string) $event['from_stage_id'], 'activityEventId' => (string) $event['event_id'], 'employee' => (string) $event['display_name'], 'at' => self::iso((string) $event['occurred_at'])];
                }
            }
            $result[(string) $unit['unit_uuid']] = [
                'reworkCycle' => (int) $unit['rework_cycle'],
                'ledgerStartStageId' => $start,
                'preLedgerStages' => $pre,
                'reconciledStages' => array_map(static fn (string $stage, string $kind): array => ['stageId' => $stage, 'evidence' => $kind], array_keys($done), array_values($done)),
                'missingStages' => array_values(array_filter($pre, static fn (string $stage): bool => !isset($done[$stage]) && !isset($completed[$key][$stage]))),
                'uncertainStages' => $uncertain[$key] ?? [],
                'candidates' => $candidates,
            ];
        }
        return $result;
    }

    /**
     * Records one verification of one piece cycle (caller holds the order lock and validated the actor). Returns the
     * reconciliation id. @param array{stages: list<array{stageId: string, evidence: string, activityEventId: ?string}>, uncertainStages: list<string>, note: string} $request
     */
    public function record(ProductionWorkflow $workflow, array $order, array $unit, string $productType, array $request, string $actorUuid, string $via, string $requestId, string $now): string
    {
        $cycle = (int) $unit['rework_cycle'];
        $start = $this->pdo->prepare('SELECT started_stage_id, started_at FROM production_unit_cycles WHERE unit_uuid = ? AND rework_cycle = ?');
        $start->execute([$unit['unit_uuid'], $cycle]);
        $ledger = $start->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ledger)) {
            throw new ApiException(409, 'ROUTE_LEDGER_MISSING', 'Bucata nu are încă un registru de etape; confirmă planul de producție.');
        }
        $pre = self::preLedgerStages($workflow, $productType, (string) $ledger['started_stage_id']);
        $completed = $this->pdo->prepare("SELECT stage_id FROM production_unit_stage_log WHERE unit_uuid = ? AND rework_cycle = ? AND outcome = 'completed'");
        $completed->execute([$unit['unit_uuid'], $cycle]);
        $already = array_flip($completed->fetchAll(PDO::FETCH_COLUMN));
        $reconciled = $this->reconciled((string) $order['order_uuid'])[(string) $unit['unit_uuid'] . '|' . $cycle] ?? [];
        $named = [...array_column($request['stages'], 'stageId'), ...$request['uncertainStages']];
        if (count($named) !== count(array_unique($named))) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Fiecare etapă poate apărea o singură dată.');
        }
        foreach ($named as $stageId) {
            if (!in_array($stageId, $pre, true) || isset($already[$stageId])) {
                throw new ApiException(422, 'RECONCILIATION_STAGE_INVALID', 'Numai etapele aplicabile dinaintea începerii registrului bucății, fără finalizare înregistrată, pot fi reconciliate.', ['stageId' => $stageId, 'allowed' => $pre]);
            }
            if (isset($reconciled[$stageId])) {
                throw new ApiException(409, 'RECONCILIATION_STAGE_RECORDED', 'Etapa are deja o dovadă verificată pentru această bucată.', ['stageId' => $stageId]);
            }
        }
        foreach ($request['stages'] as $stage) {
            if ($stage['evidence'] !== self::ORDER_ACTIVITY) {
                continue;
            }
            $event = $this->pdo->prepare("SELECT occurred_at FROM order_activity_events WHERE event_id = ? AND order_uuid = ? AND action = 'stage_completed' AND from_stage_id = ?");
            $event->execute([$stage['activityEventId'], $order['order_uuid'], $stage['stageId']]);
            $at = $event->fetchColumn();
            if (!is_string($at) || $at > (string) $ledger['started_at']) {
                throw new ApiException(422, 'RECONCILIATION_EVIDENCE_INVALID', 'Evenimentul ales nu este finalizarea acestei etape pentru această comandă înainte de începerea registrului bucății.', ['stageId' => $stage['stageId']]);
            }
        }
        $uuid = Uuid::v4();
        $this->pdo->prepare(
            'INSERT INTO production_route_reconciliations (reconciliation_uuid, order_uuid, unit_uuid, rework_cycle, ledger_start_stage_id, supported_stages_json, uncertain_stages_json, verified_by_employee_uuid, verified_via, note, request_id, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $uuid, $order['order_uuid'], $unit['unit_uuid'], $cycle, $ledger['started_stage_id'],
            json_encode(array_map(static fn (array $stage): array => ['stageId' => $stage['stageId'], 'evidence' => $stage['evidence'], 'activityEventId' => $stage['activityEventId']], $request['stages']), JSON_THROW_ON_ERROR),
            json_encode($request['uncertainStages'], JSON_THROW_ON_ERROR), $actorUuid, $via, $request['note'], mb_substr($requestId, 0, 100), $now,
        ]);
        $insert = $this->pdo->prepare('INSERT INTO production_route_reconciliation_stages (unit_uuid, rework_cycle, stage_id, reconciliation_uuid, order_uuid, evidence_kind, activity_event_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($request['stages'] as $stage) {
            $insert->execute([$unit['unit_uuid'], $cycle, $stage['stageId'], $uuid, $order['order_uuid'], $stage['evidence'], $stage['activityEventId']]);
        }
        return $uuid;
    }

    /** @param array<string, mixed> $input @return array{stages: list<array{stageId: string, evidence: string, activityEventId: ?string}>, uncertainStages: list<string>, note: string} */
    public static function parse(array $input): array
    {
        $stages = $input['stages'] ?? [];
        $uncertain = $input['uncertainStages'] ?? [];
        if (!is_array($stages) || !array_is_list($stages) || !is_array($uncertain) || !array_is_list($uncertain) || count($stages) + count($uncertain) === 0 || count($stages) + count($uncertain) > 20) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Precizează cel puțin o etapă susținută de dovezi sau declarată incertă.');
        }
        $parsed = [];
        foreach ($stages as $stage) {
            if (!is_array($stage) || array_diff(array_keys($stage), ['stageId', 'evidence', 'activityEventId']) !== [] || !is_string($stage['stageId'] ?? null)
                || !in_array($stage['evidence'] ?? null, [self::ORDER_ACTIVITY, self::PHYSICAL_INSPECTION], true)) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Fiecare etapă are stageId și evidence (order_activity sau physical_inspection).');
            }
            $event = $stage['activityEventId'] ?? null;
            if ($stage['evidence'] === self::ORDER_ACTIVITY ? !(is_string($event) && WorkUnits::isUuid($event)) : $event !== null) {
                throw new ApiException(400, 'INVALID_REQUEST', 'order_activity necesită activityEventId; physical_inspection nu are activityEventId.');
            }
            $parsed[] = ['stageId' => $stage['stageId'], 'evidence' => $stage['evidence'], 'activityEventId' => $event];
        }
        foreach ($uncertain as $stage) {
            if (!is_string($stage)) {
                throw new ApiException(400, 'INVALID_REQUEST', 'uncertainStages enumeră identificatori de etapă.');
            }
        }
        $note = is_string($input['note'] ?? null) ? trim($input['note']) : '';
        if (mb_strlen($note) < 10 || mb_strlen($note) > 500) {
            throw new ApiException(422, 'COMMENT_REQUIRED', 'Descrie dovezile verificate (10-500 de caractere).');
        }
        return ['stages' => $parsed, 'uncertainStages' => array_values($uncertain), 'note' => $note];
    }

    /** @return array<string, list<string>> "unit|cycle" => stages any verification declared uncertain that are still unsupported */
    private function uncertain(string $orderUuid): array
    {
        $statement = $this->pdo->prepare('SELECT unit_uuid, rework_cycle, uncertain_stages_json FROM production_route_reconciliations WHERE order_uuid = ? ORDER BY occurred_at, reconciliation_uuid');
        try {
            $statement->execute([$orderUuid]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return [];
        }
        $reconciled = $this->reconciled($orderUuid);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) $row['unit_uuid'] . '|' . (int) $row['rework_cycle'];
            foreach (json_decode((string) $row['uncertain_stages_json'], true) ?: [] as $stage) {
                if (!isset($reconciled[$key][$stage]) && !in_array($stage, $result[$key] ?? [], true)) {
                    $result[$key][] = (string) $stage;
                }
            }
        }
        return $result;
    }

    private static function iso(string $value): string
    {
        return substr(str_replace(' ', 'T', $value), 0, 23) . 'Z';
    }
}
