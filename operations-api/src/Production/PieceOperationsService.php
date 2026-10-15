<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Cutting\CuttingLifecycle;
use Arasya\Operations\Document\DocumentGuard;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Order\QrReference;
use Arasya\Operations\Order\UnitLabelReference;
use Arasya\Operations\Quality\ApproverPolicy;
use Arasya\Operations\Quality\IdempotencyStore;
use Arasya\Operations\Quality\TailoringSupervisorPolicy;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;
use Throwable;

/**
 * Commands and reads on the manufacturing manifest and on physical pieces (API 2.32.0):
 *
 *   plan view / confirm   the manufacturing plan of each commercial line; confirmation by the appointed tailoring
 *                         quality supervisor of the order's source (Staff) or an operations approver (Dashboard, the
 *                         ApproverPolicy rules); never inferred from a job title
 *   custody               who physically holds a piece, where (a registered location) and whether it is held on purpose
 *                         (inspection, waiting for a companion in rework, reunion, quarantine). A custody change never
 *                         changes a production stage, owner or version, and never marks a piece defective
 *   reunite               the corrected piece and its companions are physically together (a location event only: no
 *                         stage completion and no logical merge)
 *   material              actual, waste, remnant and corrections of fabric per piece and cycle (MaterialLedger)
 *   history               lineage, labels, per-piece stage ledger, custody and material of one piece (audit)
 *   route evidence        verified historical evidence for the stages of a piece before its ledger started (030)
 *
 * Every command runs in one transaction that locks the order row first, then the employee row, re-reads the employee's
 * authority, replays a committed result for the same idempotency key, checks the expected version and writes the
 * audit rows before commit.
 */
final readonly class PieceOperationsService
{
    private const MAX_ATTEMPTS = 3;
    private const RETRYABLE_MYSQL_ERRORS = [1205, 1213];
    public const HOLDS = ['companion_rework', 'inspection', 'reunion_wait', 'quarantine'];

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private ApproverPolicy $approvers,
        private IdempotencyStore $idempotency,
        private Clock $clock,
        private ProductionWorkflowService $workflows,
    ) {
    }

    // ------------------------------------------------------------------ manufacturing plan

    /** @return array<string, mixed> */
    public function planView(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $order = $this->order($globalOrderId);
        $this->requirePlanReader($actor, $order);
        return $this->planPayload($actor, $order);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function confirmPlan(EmployeeIdentity $actor, string $globalOrderId, string $itemId, array $input, string $key, string $requestId): array
    {
        IdempotencyStore::requireKey($key);
        if (!WorkUnits::isUuid($itemId)) {
            throw new ApiException(404, 'LINE_NOT_FOUND', 'Linia nu a fost găsită.');
        }
        $request = ManufacturingPlans::parseConfirmation($input);
        $globalId = $this->globalId($globalOrderId);
        $hash = IdempotencyStore::hash('plan.confirm', $globalId . '|' . $itemId, $request);
        return $this->transaction(function () use ($actor, $globalId, $itemId, $request, $key, $hash, $requestId): array {
            $order = $this->lockOrder($globalId);
            $employee = $this->lockEmployee($actor);
            $this->requirePlanReader($employee, $order);
            $replay = $this->idempotency->replay($employee->employeeUuid, $key, 'plan.confirm', $globalId . '|' . $itemId, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $via = $this->planConfirmer($employee, $order);
            if ($order['production_completed_at'] !== null) {
                throw new ApiException(409, 'PRODUCTION_COMPLETED', 'Producția este deja finalizată.');
            }
            // While an order-level quality exception is open, the order may sit at the supervisor's stage instead of its
            // real progress. Pieces materialized then would start their ledger at that stage, and stages the order already
            // passed could never be verified. Confirm once the order is back at its stage (receipt confirmed).
            if ($order['open_exception_uuid'] !== null) {
                throw new ApiException(409, 'PLAN_BLOCKED_BY_EXCEPTION', 'Comanda are o problemă de calitate deschisă. Confirmă planul după ce comanda revine la etapa ei.');
            }
            $now = $this->now();
            $plans = new ManufacturingPlans($this->pdo);
            $before = $plans->line((string) $order['order_uuid'], $itemId) ?? throw new ApiException(404, 'LINE_NOT_FOUND', 'Linia nu a fost găsită.');
            $after = $plans->confirm((string) $order['order_uuid'], $itemId, $request, $employee->employeeUuid, $requestId, $now);
            // The pieces of a confirmed line exist from now on, at the order's current stage (their per-piece ledger starts here).
            (new WorkUnits($this->pdo))->ensure((string) $order['order_uuid'], $now, 'on_demand');
            (new IamAuditLogger($this->pdo))->record($employee, 'production.plan.confirmed', 'order', (string) $order['global_order_id'], '#' . $order['order_number'] . ' · linia ' . $after['lineNumber'], [
                'order' => $order['global_order_id'], 'itemId' => $itemId, 'via' => $via,
                'revision' => ['before' => $before['plan']['revision'], 'after' => $after['plan']['revision']],
                'productType' => ['before' => $before['plan']['productType'], 'after' => $after['plan']['productType']],
                'pieceCount' => ['before' => $before['plan']['pieceCount'], 'after' => $after['plan']['pieceCount']],
                'commercialQuantity' => $after['commercialQuantity'], 'materialTotal' => $after['plan']['materialTotal'], 'note' => $request['note'],
            ], $requestId, $now);
            $response = $this->planPayload($employee, $order);
            $this->idempotency->store($employee->employeeUuid, $key, 'plan.confirm', $globalId . '|' . $itemId, $hash, $response, $now);
            return $response;
        });
    }

    // ------------------------------------------------------------------ custody, location, reunion

    /** Registered physical locations (active), for anyone working in Staff. @return array<string, mixed> */
    public function locations(EmployeeIdentity $actor): array
    {
        $this->authorization->requireApplication($actor, 'staff');
        try {
            $rows = $this->pdo->query("SELECT location_id, label, kind, stage_id FROM production_locations WHERE status = 'active' ORDER BY kind, label")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            $rows = [];
        }
        return ['locations' => array_map(static fn (array $row): array => ['id' => (string) $row['location_id'], 'label' => (string) $row['label'], 'kind' => (string) $row['kind'], 'stageId' => $row['stage_id']], $rows)];
    }

    /**
     * Records who holds a piece, where it is and why it is held. action: take (the actor receives it), locate (it is at
     * a registered location), hold / release (a deliberate production hold). The piece label (or the order QR with
     * this piece chosen) must be scanned. @param array<string, mixed> $input @return array<string, mixed>
     */
    public function custody(EmployeeIdentity $actor, string $unitId, array $input, string $key, string $requestId): array
    {
        IdempotencyStore::requireKey($key);
        $unitUuid = WorkUnits::isUuid($unitId) ? $unitId : throw new ApiException(404, 'UNIT_NOT_FOUND', 'Bucata nu a fost găsită.');
        $action = $input['action'] ?? null;
        if (!in_array($action, ['take', 'locate', 'hold', 'release'], true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'action trebuie să fie take, locate, hold sau release.');
        }
        $expected = $input['expectedVersion'] ?? null;
        if (!is_int($expected) || $expected < 0) {
            throw new ApiException(400, 'INVALID_REQUEST', 'expectedVersion trebuie să fie un număr întreg nenegativ (0 înaintea primei înregistrări de custodie).');
        }
        $location = $input['locationId'] ?? null;
        if ($location !== null && (!is_string($location) || preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/D', $location) !== 1)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'locationId nu este valid.');
        }
        if ($action === 'locate' && $location === null) {
            throw new ApiException(400, 'INVALID_REQUEST', 'locationId este obligatoriu.');
        }
        $hold = $input['holdReason'] ?? null;
        if ($action === 'hold' && !in_array($hold, self::HOLDS, true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'holdReason trebuie să fie una dintre valorile ' . implode(', ', self::HOLDS) . '.');
        }
        if ($action !== 'hold' && $hold !== null) {
            throw new ApiException(400, 'INVALID_REQUEST', 'holdReason este acceptat numai cu hold.');
        }
        $token = is_string($input['qrToken'] ?? null) ? trim($input['qrToken']) : throw new ApiException(400, 'QR_REQUIRED', 'Scanează eticheta bucății sau codul QR al comenzii.');
        $note = is_string($input['note'] ?? null) ? mb_substr(trim($input['note']), 0, 500) : null;
        $hash = IdempotencyStore::hash('piece.custody', $unitUuid, [$action, $expected, $location, $hold, strtoupper($token), $note]);
        return $this->transaction(function () use ($actor, $unitUuid, $action, $expected, $location, $hold, $token, $note, $key, $hash, $requestId): array {
            [$order, $unit] = $this->lockUnit($unitUuid);
            $employee = $this->lockEmployee($actor);
            $this->requirePlanReader($employee, $order);
            $replay = $this->idempotency->replay($employee->employeeUuid, $key, 'piece.custody', $unitUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $stage = $this->pieceStage($order, $unit);
            $this->requireCustodyActor($employee, $order, $unit, $stage);
            $scanned = $this->verifyPieceScan($order, $unit, $token);
            $current = $this->custodyRow($unitUuid);
            if (($current === null ? 0 : (int) $current['version']) !== $expected) {
                throw new ApiException(409, 'CUSTODY_CHANGED', 'Custodia bucății s-a schimbat. Reîncarcă înainte de a continua.');
            }
            if ($location !== null) {
                $this->requireLocation($location);
            }
            $now = $this->now();
            $before = ['custodian' => $current['custodian_employee_uuid'] ?? null, 'location' => $current['location_id'] ?? null, 'hold' => $current['hold_reason'] ?? null];
            $after = match ($action) {
                'take' => ['custodian' => $employee->employeeUuid, 'location' => $location ?? ($before['custodian'] === $employee->employeeUuid ? $before['location'] : null), 'hold' => $before['hold']],
                'locate' => ['custodian' => $before['custodian'], 'location' => $location, 'hold' => $before['hold']],
                'hold' => ['custodian' => $before['custodian'], 'location' => $location ?? $before['location'], 'hold' => $hold],
                'release' => ['custodian' => $before['custodian'], 'location' => $location ?? $before['location'], 'hold' => null],
            };
            if ($action === 'release' && $before['hold'] === null) {
                throw new ApiException(409, 'CUSTODY_STATE_INVALID', 'Bucata nu este reținută.');
            }
            $eventAction = match ($action) { 'take' => 'custody_taken', 'locate' => 'location_confirmed', 'hold' => 'hold_set', 'release' => 'hold_released' };
            $version = $this->writeCustody($order, $unit, $current, $after, $employee->employeeUuid, $now);
            $this->custodyEvent($order, $unit, $eventAction, $employee->employeeUuid, $before, $after, $stage, $version, ['scanned' => $scanned, 'note' => $note], $requestId, $key, $now);
            $response = $this->pieceView($unitUuid);
            $this->idempotency->store($employee->employeeUuid, $key, 'piece.custody', $unitUuid, $hash, $response, $now);
            return $response;
        });
    }

    /**
     * Physical reunion of pieces of one order (a corrected piece back with its healthy companions). A location event per
     * piece; no stage, owner or track changes. @param array<string, mixed> $input @return array<string, mixed>
     */
    public function reunite(EmployeeIdentity $actor, array $input, string $key, string $requestId): array
    {
        IdempotencyStore::requireKey($key);
        $units = $input['unitIds'] ?? null;
        if (!is_array($units) || !array_is_list($units) || count($units) < 2 || count($units) > 50 || count(array_unique($units)) !== count($units)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'unitIds trebuie să enumere cel puțin două bucăți distincte.');
        }
        foreach ($units as $id) {
            if (!WorkUnits::isUuid($id)) {
                throw new ApiException(400, 'INVALID_REQUEST', 'unitIds trebuie să enumere bucăți.');
            }
        }
        sort($units, SORT_STRING);
        $location = $input['locationId'] ?? null;
        if ($location !== null && (!is_string($location) || preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/D', $location) !== 1)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'locationId nu este valid.');
        }
        if (($input['confirmed'] ?? null) !== true) {
            throw new ApiException(400, 'CONFIRMATION_REQUIRED', 'Confirmă că bucățile sunt fizic împreună.');
        }
        $hash = IdempotencyStore::hash('piece.reunite', $units[0], [$units, $location]);
        return $this->transaction(function () use ($actor, $units, $location, $key, $hash, $requestId): array {
            [$order, $first] = $this->lockUnit($units[0]);
            $employee = $this->lockEmployee($actor);
            $this->requirePlanReader($employee, $order);
            $replay = $this->idempotency->replay($employee->employeeUuid, $key, 'piece.reunite', $units[0], $hash);
            if ($replay !== null) {
                return $replay;
            }
            if ($location !== null) {
                $this->requireLocation($location);
            }
            $now = $this->now();
            $rows = [];
            foreach ($units as $unitUuid) {
                $unit = $this->unitRow($unitUuid, true);
                if ($unit === null || $unit['order_uuid'] !== $order['order_uuid'] || $unit['status'] !== 'active') {
                    throw new ApiException(422, 'UNITS_INVALID', 'Fiecare bucată trebuie să fie activă și să aparțină aceleiași comenzi.');
                }
                $rows[] = $unit;
            }
            foreach ($rows as $unit) {
                $this->requireCustodyActor($employee, $order, $unit, $this->pieceStage($order, $unit));
            }
            foreach ($rows as $unit) {
                $current = $this->custodyRow((string) $unit['unit_uuid']);
                $before = ['custodian' => $current['custodian_employee_uuid'] ?? null, 'location' => $current['location_id'] ?? null, 'hold' => $current['hold_reason'] ?? null];
                // Reunion ends a companion / reunion hold; any other hold (inspection, quarantine) stays.
                $after = ['custodian' => $before['custodian'] ?? $employee->employeeUuid, 'location' => $location ?? $before['location'],
                    'hold' => in_array($before['hold'], ['companion_rework', 'reunion_wait'], true) ? null : $before['hold']];
                $version = $this->writeCustody($order, $unit, $current, $after, $employee->employeeUuid, $now);
                $this->custodyEvent($order, $unit, 'reunited', $employee->employeeUuid, $before, $after, $this->pieceStage($order, $unit), $version,
                    ['with' => array_values(array_diff($units, [(string) $unit['unit_uuid']])), 'logicalMerge' => false], $requestId, $key, $now);
            }
            $response = ['orderId' => (string) $order['global_order_id'], 'reunited' => array_map(fn (string $unitUuid): array => $this->pieceView($unitUuid), $units)];
            $this->idempotency->store($employee->employeeUuid, $key, 'piece.reunite', $units[0], $hash, $response, $now);
            return $response;
        });
    }

    // ------------------------------------------------------------------ material

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function recordMaterial(EmployeeIdentity $actor, string $unitId, array $input, string $key, string $requestId): array
    {
        IdempotencyStore::requireKey($key);
        $unitUuid = WorkUnits::isUuid($unitId) ? $unitId : throw new ApiException(404, 'UNIT_NOT_FOUND', 'Bucata nu a fost găsită.');
        $hash = IdempotencyStore::hash('piece.material', $unitUuid, array_intersect_key($input, array_flip(['kind', 'meters', 'note', 'correctsRecordId'])));
        return $this->transaction(function () use ($actor, $unitUuid, $input, $key, $hash, $requestId): array {
            [$order, $unit] = $this->lockUnit($unitUuid, true);
            $employee = $this->lockEmployee($actor);
            $this->requirePlanReader($employee, $order);
            $replay = $this->idempotency->replay($employee->employeeUuid, $key, 'piece.material', $unitUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            // Fabric is recorded by an eligible cutter of the order's source or by the appointed tailoring supervisor.
            $supervisor = (new TailoringSupervisorPolicy($this->pdo, $this->clock))->supervises($employee, (string) $order['source_key']);
            if (!$supervisor && !(CuttingLifecycle::eligible($employee) && $employee->worksAt(CuttingLifecycle::STAGE, (string) $order['source_key']))) {
                throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Numai un croitor de la tăiere sau șeful de croitorie poate înregistra metrajul.');
            }
            if (in_array($input['kind'] ?? null, ['actual', 'replacement_actual'], true)) {
                if ($unit['status'] !== 'active') throw new ApiException(409, 'UNIT_RETIRED', 'Bucata a fost retrasă.');
                $carrier = $unit['track_uuid'] === null
                    ? ['stage_id' => $order['production_stage_id'], 'owner_employee_uuid' => $order['production_owner_employee_uuid'], 'open_exception_uuid' => $order['open_exception_uuid']]
                    : (new ProductionTracks($this->pdo))->lock((string) $unit['track_uuid']);
                $cut = $this->pdo->prepare("SELECT 1 FROM production_unit_stage_log WHERE unit_uuid = ? AND rework_cycle = ? AND stage_id = ? AND outcome = 'completed' AND employee_uuid = ?");
                $cut->execute([$unitUuid, (int) $unit['rework_cycle'], CuttingLifecycle::STAGE, $employee->employeeUuid]);
                $ownsCut = $carrier !== null && $carrier['stage_id'] === CuttingLifecycle::STAGE && $carrier['owner_employee_uuid'] === $employee->employeeUuid && $carrier['open_exception_uuid'] === null;
                if (!$ownsCut && $cut->fetchColumn() === false) throw new ApiException(409, 'CUTTING_NOT_AUTHORIZED', 'Consumul efectiv se înregistrează numai pentru tăierea autorizată a acestei bucăți în ciclul curent.');
            }
            $now = $this->now();
            $record = (new MaterialLedger($this->pdo))->record($unit, $input, $employee->employeeUuid, $requestId, $key, $now);
            $response = ['record' => $record, 'piece' => $this->pieceView($unitUuid)];
            $this->idempotency->store($employee->employeeUuid, $key, 'piece.material', $unitUuid, $hash, $response, $now);
            return $response;
        });
    }

    // ------------------------------------------------------------------ historical route evidence

    /**
     * Records verified historical evidence for stages of one piece that precede its ledger start (RouteReconciliation).
     * Same authority as a plan confirmation. Writes no stage completion, credits no employee, moves nothing.
     * @param array<string, mixed> $input @return array<string, mixed>
     */
    public function reconcileRoute(EmployeeIdentity $actor, string $unitId, array $input, string $key, string $requestId): array
    {
        IdempotencyStore::requireKey($key);
        $unitUuid = WorkUnits::isUuid($unitId) ? $unitId : throw new ApiException(404, 'UNIT_NOT_FOUND', 'Bucata nu a fost găsită.');
        $request = RouteReconciliation::parse($input);
        $hash = IdempotencyStore::hash('route.reconcile', $unitUuid, $request);
        return $this->transaction(function () use ($actor, $unitUuid, $request, $key, $hash, $requestId): array {
            [$order, $unit] = $this->lockUnit($unitUuid);
            $employee = $this->lockEmployee($actor);
            $this->requirePlanReader($employee, $order);
            $replay = $this->idempotency->replay($employee->employeeUuid, $key, 'route.reconcile', $unitUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $via = $this->confirmVia($employee, $order) ?? throw new ApiException(403, 'RECONCILIATION_NOT_ALLOWED', 'Numai șeful autorizat de croitorie sau un manager operațional poate verifica dovezile istorice ale unei bucăți.');
            if ($order['production_completed_at'] !== null) {
                throw new ApiException(409, 'PRODUCTION_COMPLETED', 'Producția este deja finalizată.');
            }
            $reconciliation = new RouteReconciliation($this->pdo);
            if (!$reconciliation->available()) {
                throw new ApiException(503, 'RECONCILIATION_UNAVAILABLE', 'Verificarea dovezilor istorice nu este încă disponibilă.');
            }
            $line = (new ManufacturingPlans($this->pdo))->line((string) $order['order_uuid'], (string) $unit['item_uuid']);
            if ($line === null || !$line['known']) {
                throw new ApiException(409, 'PLAN_CONFIRMATION_REQUIRED', 'Confirmă mai întâi planul de producție al liniei.');
            }
            if ((int) $unit['review_required'] === 1) {
                throw new ApiException(409, 'PIECE_REVIEW_REQUIRED', 'Identitatea bucății necesită mai întâi o verificare.');
            }
            $now = $this->now();
            $id = $reconciliation->record($this->workflow(), $order, $unit, (string) $line['plan']['productType'], $request, $employee->employeeUuid, $via, $requestId, $now);
            (new IamAuditLogger($this->pdo))->record($employee, 'production.route.reconciled', 'order', (string) $order['global_order_id'], '#' . $order['order_number'] . ' · bucata ' . $unit['slot'], [
                'order' => $order['global_order_id'], 'unitId' => $unitUuid, 'reworkCycle' => (int) $unit['rework_cycle'], 'via' => $via, 'reconciliationId' => $id,
                'supported' => array_map(static fn (array $stage): string => $stage['stageId'] . ':' . $stage['evidence'], $request['stages']), 'uncertain' => $request['uncertainStages'],
            ], $requestId, $now);
            $response = ['reconciliationId' => $id] + $this->planPayload($employee, $order);
            $this->idempotency->store($employee->employeeUuid, $key, 'route.reconcile', $unitUuid, $hash, $response, $now);
            return $response;
        });
    }

    // ------------------------------------------------------------------ history

    /** Audit view of one piece (also a retired or replaced one). @return array<string, mixed> */
    public function history(EmployeeIdentity $actor, string $unitId): array
    {
        $unitUuid = WorkUnits::isUuid($unitId) ? $unitId : throw new ApiException(404, 'UNIT_NOT_FOUND', 'Bucata nu a fost găsită.');
        $unit = $this->unitRow($unitUuid, false) ?? throw new ApiException(404, 'UNIT_NOT_FOUND', 'Bucata nu a fost găsită.');
        $order = $this->orderByUuid((string) $unit['order_uuid']);
        $this->requirePlanReader($actor, $order);
        $log = $this->pdo->prepare('SELECT l.rework_cycle, l.stage_id, l.outcome, l.to_stage_id, l.carrier_track_uuid, l.carrier_version_before, l.route_decision_uuid, l.occurred_at, e.display_name
             FROM production_unit_stage_log l LEFT JOIN employees e ON e.employee_uuid = l.employee_uuid WHERE l.unit_uuid = ? ORDER BY l.occurred_at, l.rework_cycle');
        $log->execute([$unitUuid]);
        $events = $this->pdo->prepare('SELECT c.action, c.custodian_before, c.custodian_after, c.location_before, c.location_after, c.hold_before, c.hold_after, c.production_stage_id, c.version_after, c.metadata_json, c.occurred_at, e.display_name
             FROM production_custody_events c INNER JOIN employees e ON e.employee_uuid = c.actor_employee_uuid WHERE c.unit_uuid = ? ORDER BY c.version_after');
        $events->execute([$unitUuid]);
        $labels = $this->pdo->prepare('SELECT label_reference, status, issued_at, revoked_at FROM production_unit_labels WHERE unit_uuid = ? ORDER BY issued_at');
        $labels->execute([$unitUuid]);
        return $this->pieceView($unitUuid) + [
            'stageLedger' => array_map(static fn (array $row): array => [
                'reworkCycle' => (int) $row['rework_cycle'], 'stageId' => (string) $row['stage_id'], 'outcome' => (string) $row['outcome'], 'toStageId' => $row['to_stage_id'],
                'employee' => $row['display_name'], 'trackId' => $row['carrier_track_uuid'], 'versionBefore' => $row['carrier_version_before'] === null ? null : (int) $row['carrier_version_before'],
                'routeDecisionId' => $row['route_decision_uuid'], 'at' => self::iso((string) $row['occurred_at']),
            ], $log->fetchAll(PDO::FETCH_ASSOC)),
            'custodyEvents' => array_map(static fn (array $row): array => [
                'action' => (string) $row['action'], 'by' => (string) $row['display_name'], 'custodian' => ['before' => $row['custodian_before'], 'after' => $row['custodian_after']],
                'location' => ['before' => $row['location_before'], 'after' => $row['location_after']], 'hold' => ['before' => $row['hold_before'], 'after' => $row['hold_after']],
                'productionStageId' => (string) $row['production_stage_id'], 'version' => (int) $row['version_after'],
                'metadata' => $row['metadata_json'] === null ? null : json_decode((string) $row['metadata_json'], true), 'at' => self::iso((string) $row['occurred_at']),
            ], $events->fetchAll(PDO::FETCH_ASSOC)),
            'labels' => array_map(static fn (array $row): array => ['payload' => UnitLabelReference::fromStored((string) $row['label_reference'])->payload(), 'status' => (string) $row['status'],
                'issuedAt' => self::iso((string) $row['issued_at']), 'revokedAt' => $row['revoked_at'] === null ? null : self::iso((string) $row['revoked_at'])], $labels->fetchAll(PDO::FETCH_ASSOC)),
            'material' => (new MaterialLedger($this->pdo))->forUnits([$unitUuid]),
        ];
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function planPayload(EmployeeIdentity $viewer, array $order): array
    {
        $workflow = $this->workflow();
        $lines = (new ManufacturingPlans($this->pdo))->lines((string) $order['order_uuid']);
        $pieces = [];
        foreach ((new WorkUnits($this->pdo))->pieces((string) $order['order_uuid']) as $piece) {
            $pieces[$piece['itemId']][] = $piece;
        }
        $features = new FeatureActivation($this->pdo);
        $evidence = (new RouteReconciliation($this->pdo))->evidence($workflow, (string) $order['order_uuid']);
        foreach ($pieces as &$itemPieces) {
            foreach ($itemPieces as &$piece) {
                $piece['routeEvidence'] = $evidence[$piece['id']] ?? null;
            }
            unset($piece);
        }
        unset($itemPieces);
        return [
            'orderId' => (string) $order['global_order_id'],
            'orderNumber' => (string) $order['order_number'],
            'canConfirm' => $this->confirmVia($viewer, $order) !== null,
            'partialRework' => $features->appliesTo(FeatureActivation::PARTIAL_REWORK, (string) $order['order_uuid']),
            'pieceRouting' => $features->appliesTo(FeatureActivation::PIECE_ROUTING, (string) $order['order_uuid']),
            'allPlansKnown' => array_filter($lines, static fn (array $line): bool => !$line['known']) === [],
            'lines' => array_map(static fn (array $line): array => [
                'itemId' => $line['itemId'], 'sourcePlanHash' => $line['sourcePlanHash'], 'lineNumber' => $line['lineNumber'], 'name' => $line['name'], 'code' => $line['code'], 'variant' => $line['variant'], 'color' => $line['color'],
                'commercialQuantity' => $line['commercialQuantity'],
                'lineMeasurements' => ['width' => $line['width'], 'height' => $line['height'], 'unit' => $line['unit']],
                'lineMeters' => $line['meters'],
                'plan' => $line['plan'],
                'planKnown' => $line['known'],
                'requiresConfirmation' => !$line['known'],
                'staleAfterSourceChange' => $line['stale'],
                'typeVerified' => $line['typeVerified'],
                'route' => ProductRoutePolicy::route($workflow, (string) $line['plan']['productType']),
                'notApplicableStages' => array_values(array_diff(array_map(static fn (ProductionStage $stage): string => $stage->id, $workflow->stages), ProductRoutePolicy::route($workflow, (string) $line['plan']['productType']))),
                'proposal' => $line['proposal'],
                'pieces' => $pieces[$line['itemId']] ?? [],
            ], $lines),
        ];
    }

    /**
     * Who may read the plan and piece history: an operations approver (Dashboard, every source, like the approval queue)
     * or a Staff employee who reaches the order's source. @param array<string, mixed> $order
     */
    private function requirePlanReader(EmployeeIdentity $actor, array $order): void
    {
        if (!$actor->isOperationallyActive()) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
        }
        if ($this->approvers->via($actor) !== null) {
            return;
        }
        if (!$actor->hasApplication('staff') || !$actor->reachesSource((string) $order['source_key'])) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
        }
    }

    /** @param array<string, mixed> $order */
    private function confirmVia(EmployeeIdentity $employee, array $order): ?string
    {
        if ((new TailoringSupervisorPolicy($this->pdo, $this->clock))->supervises($employee, (string) $order['source_key'])) {
            return 'tailoring_quality_supervisor';
        }
        return $this->approvers->via($employee);
    }

    /** @param array<string, mixed> $order */
    private function planConfirmer(EmployeeIdentity $employee, array $order): string
    {
        return $this->confirmVia($employee, $order) ?? throw new ApiException(403, 'PLAN_CONFIRMATION_NOT_ALLOWED', 'Numai șeful autorizat de croitorie sau un manager operațional poate confirma planul de producție.');
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $unit */
    private function requireCustodyActor(EmployeeIdentity $employee, array $order, array $unit, string $stage): void
    {
        if (!$employee->hasApplication('staff') || !$employee->reachesSource((string) $order['source_key'])) {
            throw new ApiException(404, 'UNIT_NOT_FOUND', 'Bucata nu a fost găsită.');
        }
        $supervisor = (new TailoringSupervisorPolicy($this->pdo, $this->clock))->supervises($employee, (string) $order['source_key']);
        if (!$supervisor && !$employee->worksAt($stage, (string) $order['source_key'])) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Numai șeful de croitorie sau un angajat al etapei bucății poate înregistra custodia.');
        }
    }

    /** The production stage of a piece: its track's, or the order's when it travels with the order. @param array<string, mixed> $order @param array<string, mixed> $unit */
    private function pieceStage(array $order, array $unit): string
    {
        if ($unit['track_uuid'] === null) {
            return (string) $order['production_stage_id'];
        }
        $statement = $this->pdo->prepare('SELECT stage_id FROM production_tracks WHERE track_uuid = ?');
        $statement->execute([$unit['track_uuid']]);
        return (string) $statement->fetchColumn();
    }

    /** The scan identifies the piece in hand: its active label, or the active order QR (the piece chosen explicitly). @param array<string, mixed> $order @param array<string, mixed> $unit */
    private function verifyPieceScan(array $order, array $unit, string $token): string
    {
        $label = UnitLabelReference::parsePayload($token);
        if ($label !== null) {
            $resolved = (new WorkUnits($this->pdo))->resolveLabel($label);
            if ($resolved === null || $resolved['status'] !== 'active') {
                throw new ApiException(422, 'INVALID_QR', 'Eticheta scanată a bucății nu este validă.', $resolved !== null && $resolved['replacedBy'] !== null ? ['replaced' => true] : []);
            }
            if ($resolved['unitUuid'] !== $unit['unit_uuid']) {
                throw new ApiException(422, 'UNIT_LABEL_MISMATCH', 'Eticheta scanată aparține altei bucăți.');
            }
            return 'piece_label';
        }
        $reference = QrReference::parsePayload($token) ?? throw new ApiException(422, 'INVALID_QR', 'Codul scanat nu este un cod Arasya.');
        DocumentGuard::assertQrUsable($this->pdo, $reference->value);
        $statement = $this->pdo->prepare("SELECT order_uuid, expires_at FROM order_qr_references WHERE qr_reference = ? AND status = 'active'");
        $statement->execute([$reference->value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || ($row['expires_at'] !== null && (string) $row['expires_at'] <= $this->now())) {
            throw new ApiException(422, 'INVALID_QR', 'Codul scanat nu este un cod valid al comenzii.');
        }
        if ($row['order_uuid'] !== $order['order_uuid']) {
            throw new ApiException(422, 'QR_ORDER_MISMATCH', 'Codul scanat aparține altei comenzi.');
        }
        return 'order_qr';
    }

    private function requireLocation(string $location): void
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM production_locations WHERE location_id = ? AND status = 'active'");
        $statement->execute([$location]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException(422, 'LOCATION_UNKNOWN', 'Locația nu este înregistrată.');
        }
    }

    /** @return array<string, mixed>|null */
    private function custodyRow(string $unitUuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM production_unit_custody WHERE unit_uuid = ? FOR UPDATE');
        $statement->execute([$unitUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $order @param array<string, mixed> $unit @param array<string, mixed>|null $current @param array<string, mixed> $after
     */
    private function writeCustody(array $order, array $unit, ?array $current, array $after, string $actorUuid, string $now): int
    {
        if ($current === null) {
            $this->pdo->prepare('INSERT INTO production_unit_custody (unit_uuid, order_uuid, custodian_employee_uuid, location_id, hold_reason, version, confirmed_by_employee_uuid, confirmed_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)')
                ->execute([$unit['unit_uuid'], $order['order_uuid'], $after['custodian'], $after['location'], $after['hold'], $actorUuid, $now]);
            return 1;
        }
        $update = $this->pdo->prepare('UPDATE production_unit_custody SET custodian_employee_uuid = ?, location_id = ?, hold_reason = ?, version = version + 1, confirmed_by_employee_uuid = ?, confirmed_at = ? WHERE unit_uuid = ? AND version = ?');
        $update->execute([$after['custodian'], $after['location'], $after['hold'], $actorUuid, $now, $unit['unit_uuid'], (int) $current['version']]);
        if ($update->rowCount() !== 1) {
            throw new ApiException(409, 'CUSTODY_CHANGED', 'Custodia bucății s-a schimbat. Reîncarcă înainte de a continua.');
        }
        return (int) $current['version'] + 1;
    }

    /**
     * @param array<string, mixed> $order @param array<string, mixed> $unit @param array<string, mixed> $before @param array<string, mixed> $after @param array<string, mixed> $metadata
     */
    private function custodyEvent(array $order, array $unit, string $action, string $actorUuid, array $before, array $after, string $stage, int $version, array $metadata, string $requestId, string $key, string $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO production_custody_events (event_uuid, unit_uuid, order_uuid, action, actor_employee_uuid, custodian_before, custodian_after, location_before, location_after, hold_before, hold_after,
                production_stage_id, version_after, metadata_json, request_id, idempotency_key, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([Uuid::v4(), $unit['unit_uuid'], $order['order_uuid'], $action, $actorUuid, $before['custodian'], $after['custodian'], $before['location'], $after['location'], $before['hold'], $after['hold'],
            $stage, $version, json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), mb_substr($requestId, 0, 100),
            'c-' . substr(hash('sha256', $action . '|' . $unit['unit_uuid'] . '|' . $key), 0, 60), $now]);
    }

    /** Current view of one piece: identity, lineage, stage, custody. @return array<string, mixed> */
    private function pieceView(string $unitUuid): array
    {
        $unit = $this->unitRow($unitUuid, false) ?? throw new ApiException(404, 'UNIT_NOT_FOUND', 'Bucata nu a fost găsită.');
        $order = $this->orderByUuid((string) $unit['order_uuid']);
        $custody = $this->pdo->prepare('SELECT c.custodian_employee_uuid, c.location_id, c.hold_reason, c.version, c.confirmed_at, e.display_name, l.label AS location_label
             FROM production_unit_custody c LEFT JOIN employees e ON e.employee_uuid = c.custodian_employee_uuid LEFT JOIN production_locations l ON l.location_id = c.location_id WHERE c.unit_uuid = ?');
        $custody->execute([$unitUuid]);
        $row = $custody->fetch(PDO::FETCH_ASSOC);
        $lineage = $this->pdo->prepare('SELECT original_unit_uuid, replacement_unit_uuid, slot_index, original_disposition FROM production_unit_replacements WHERE replacement_unit_uuid = ? OR original_unit_uuid = ?');
        $lineage->execute([$unitUuid, $unitUuid]);
        $replaces = null;
        $replacedBy = null;
        foreach ($lineage->fetchAll(PDO::FETCH_ASSOC) as $link) {
            if ($link['replacement_unit_uuid'] === $unitUuid) {
                $replaces = ['unitId' => (string) $link['original_unit_uuid'], 'disposition' => (string) $link['original_disposition']];
            } else {
                $replacedBy = (string) $link['replacement_unit_uuid'];
            }
        }
        return [
            'unitId' => $unitUuid,
            'orderId' => (string) $order['global_order_id'],
            'itemId' => (string) $unit['item_uuid'],
            'slot' => (int) $unit['slot'],
            'status' => (string) $unit['status'],
            'reworkCycle' => (int) $unit['rework_cycle'],
            'trackId' => $unit['track_uuid'],
            'productionStageId' => $unit['status'] === 'active' ? $this->pieceStage($order, $unit) : null,
            'replaces' => $replaces,
            'replacedBy' => $replacedBy,
            'custody' => is_array($row) ? [
                'custodian' => $row['custodian_employee_uuid'] === null ? null : ['id' => (string) $row['custodian_employee_uuid'], 'displayName' => (string) $row['display_name']],
                'location' => $row['location_id'] === null ? null : ['id' => (string) $row['location_id'], 'label' => (string) $row['location_label']],
                'hold' => $row['hold_reason'], 'version' => (int) $row['version'], 'confirmedAt' => self::iso((string) $row['confirmed_at']),
            ] : ['custodian' => null, 'location' => null, 'hold' => null, 'version' => 0, 'confirmedAt' => null],
        ];
    }

    /** @return array<string, mixed>|null */
    private function unitRow(string $unitUuid, bool $lock): ?array
    {
        $statement = $this->pdo->prepare('SELECT u.*, COALESCE(r.slot_index, u.unit_index) AS slot FROM production_work_units u LEFT JOIN production_unit_replacements r ON r.replacement_unit_uuid = u.unit_uuid WHERE u.unit_uuid = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$unitUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** Locks the order of a piece first, then the piece. @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function lockUnit(string $unitUuid, bool $allowRetired = false): array
    {
        $find = $this->pdo->prepare('SELECT order_uuid FROM production_work_units WHERE unit_uuid = ?');
        $find->execute([$unitUuid]);
        $orderUuid = $find->fetchColumn();
        if (!is_string($orderUuid)) {
            throw new ApiException(404, 'UNIT_NOT_FOUND', 'Bucata nu a fost găsită.');
        }
        $order = $this->lockOrderByUuid($orderUuid);
        $unit = $this->unitRow($unitUuid, true) ?? throw new ApiException(404, 'UNIT_NOT_FOUND', 'Bucata nu a fost găsită.');
        if ($unit['status'] !== 'active' && !$allowRetired) {
            throw new ApiException(409, 'UNIT_RETIRED', 'Bucata a fost retrasă (înlocuită); eticheta ei nu mai autorizează nicio acțiune.');
        }
        return [$order, $unit];
    }

    private function lockEmployee(EmployeeIdentity $actor): EmployeeIdentity
    {
        $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$actor->employeeUuid]);
        $employee = (new PdoEmployeeRepository($this->pdo))->findByUuid($actor->employeeUuid) ?? throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Contul nu este activ.');
        if (!$employee->isOperationallyActive() || $employee->mustChangePassword) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Acces refuzat.');
        }
        return $employee;
    }

    private const ORDER_COLUMNS = 'order_uuid, global_order_id, order_number, source_key, production_stage_id, production_owner_employee_uuid, production_completed_at, open_exception_uuid, operational_status, document_status';

    /** @return array<string, mixed> */
    private function lockOrder(string $globalId): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::ORDER_COLUMNS . ' FROM operational_orders WHERE global_order_id = ? FOR UPDATE');
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
    }

    /** @return array<string, mixed> */
    private function lockOrderByUuid(string $orderUuid): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::ORDER_COLUMNS . ' FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
    }

    /** @return array<string, mixed> */
    private function order(string $globalOrderId): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::ORDER_COLUMNS . ' FROM operational_orders WHERE global_order_id = ?');
        $statement->execute([$this->globalId($globalOrderId)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
    }

    /** @return array<string, mixed> */
    private function orderByUuid(string $orderUuid): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::ORDER_COLUMNS . ' FROM operational_orders WHERE order_uuid = ?');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
    }

    private function globalId(string $value): string
    {
        try {
            return GlobalOrderId::fromString($value)->toString();
        } catch (\InvalidArgumentException) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
        }
    }

    private function workflow(): ProductionWorkflow
    {
        try {
            return $this->workflows->current();
        } catch (\RuntimeException) {
            throw new ApiException(503, 'WORKFLOW_UNAVAILABLE', 'Fluxul de producție nu este disponibil.');
        }
    }

    /** @template T @param callable(): T $operation @return T */
    private function transaction(callable $operation): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            // The piece lookup precedes the order lock. Under REPEATABLE READ that lookup would fix a snapshot
            // before a concurrent command commits, hiding its material/idempotency rows even after we acquire
            // the order lock. Each subsequent read must see committed state and current employee authority.
            // SET TRANSACTION affects only this transaction, not other services sharing this connection.
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->pdo->beginTransaction();
            try {
                $result = $operation();
                $this->pdo->commit();
                return $result;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($error instanceof PDOException && $attempt < self::MAX_ATTEMPTS && in_array((int) ($error->errorInfo[1] ?? 0), self::RETRYABLE_MYSQL_ERRORS, true)) {
                    continue;
                }
                throw $error;
            }
        }
    }

    private static function iso(string $value): string
    {
        return substr(str_replace(' ', 'T', $value), 0, 23) . 'Z';
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
