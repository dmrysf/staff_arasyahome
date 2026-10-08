<?php

declare(strict_types=1);

namespace Arasya\Operations\Quality;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\EmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Management\OrderOwnershipService;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Order\QrReference;
use Arasya\Operations\Production\ProductionStage;
use Arasya\Operations\Production\ProductionWorkflow;
use Arasya\Operations\Production\ProductionWorkflowService;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Cutting fault return flow, strict blocking mode (Production Exceptions V1).
 *
 *   report (tailoring intake employee who accepted the order)
 *     -> awaiting_acknowledgment          order blocked at workshop-receiving
 *   acknowledge + QR of the same order (responsible cutting employee, derived from history)
 *     -> awaiting_approval                order still blocked at workshop-receiving
 *   decide (one operations manager, backup approver or root; first valid decision wins)
 *     -> approved: in the same transaction the order moves workshop-receiving -> material-preparation,
 *        is returned to the responsible employee, the rework cycle and quality facts are written
 *     -> rejected: the order is unblocked and stays at workshop-receiving
 *   rereview (responsible or detector, after a rejection, mandatory comment)
 *     -> awaiting_approval with a new linked decision attempt; the rejection stays in history
 *   cancel (root only, recovery for a request that can no longer progress)
 *
 * Every command locks the order row first and then the exception row, replays a committed result
 * for the same actor idempotency key, checks expected versions and writes append-only events
 * before commit. History is never rewritten: the only state that moves backwards is the current
 * production stage, and only through approve.
 */
final readonly class CuttingFaultService
{
    public const DETECTION_STAGE = 'workshop-receiving';
    public const RETURN_STAGE = 'material-preparation';

    private const MAX_ATTEMPTS = 3;
    private const RETRYABLE_MYSQL_ERRORS = [1205, 1213];
    private const MAX_LINES = 200;
    private const COMMENT_MAX = 1000;

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private EmployeeRepository $employees,
        private ProductionWorkflowService $workflows,
        private ApproverPolicy $approvers,
        private ExceptionQueries $queries,
        private LiveEvents $live,
        private IdempotencyStore $idempotency,
        private IamAuditLogger $audit,
        private Clock $clock,
    ) {
    }

    // ---------------------------------------------------------------- commands

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function report(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId): array
    {
        $this->authorization->require($actor, 'orders.report_fault');
        IdempotencyStore::requireKey($key);
        $globalId = $this->globalId($globalOrderId);
        $expected = $this->positiveInt($input['expectedVersion'] ?? null, 'expectedVersion');
        $itemIds = $this->itemIds($input['itemIds'] ?? null);
        $reasonKey = is_string($input['reasonKey'] ?? null) ? $input['reasonKey'] : throw new ApiException(400, 'INVALID_REQUEST', 'reasonKey is required.');
        $comment = $this->comment($input['comment'] ?? null, false);
        $workflow = $this->workflow();
        $hash = IdempotencyStore::hash('fault.report', $globalId, [$expected, $itemIds, $reasonKey, $comment]);

        return $this->transaction(function () use ($actor, $globalId, $expected, $itemIds, $reasonKey, $comment, $workflow, $key, $hash, $requestId): array {
            $order = $this->lockOrderByGlobalId($globalId);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'fault.report', $globalId, $hash);
            if ($replay !== null) {
                return $replay;
            }
            if ((int) $order['production_version'] !== $expected) {
                throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
            }
            $this->assertOrderOpenForProduction($order);
            if ($order['production_stage_id'] !== self::DETECTION_STAGE) {
                throw new ApiException(409, 'EXCEPTION_STAGE_INVALID', 'A cutting fault can only be reported at tailoring intake.');
            }
            if ($order['open_exception_uuid'] !== null) {
                throw new ApiException(409, 'EXCEPTION_ALREADY_OPEN', 'This order already has an open return request.');
            }
            if ($order['production_owner_employee_uuid'] !== $actor->employeeUuid || !in_array(self::DETECTION_STAGE, $actor->allowedStageIds, true)) {
                throw new ApiException(403, 'FAULT_REPORT_NOT_ALLOWED', 'Only the tailoring intake employee who accepted the order can return it to cutting.');
            }
            $reason = $this->reason($reasonKey);
            if ((int) $reason['requires_comment'] === 1 && $comment === null) {
                throw new ApiException(422, 'COMMENT_REQUIRED', 'A comment is required for this reason.');
            }
            $responsible = $this->responsibleHandoff((string) $order['order_uuid']);
            if ($responsible === null) {
                throw new ApiException(409, 'RESPONSIBLE_EMPLOYEE_UNKNOWN', 'The order has no recorded cutting handoff, so no responsible employee can be determined.');
            }
            if ($responsible['employee_uuid'] === $actor->employeeUuid) {
                throw new ApiException(409, 'SELF_FAULT_REPORT_DENIED', 'An employee cannot report a cutting fault attributed to themselves.');
            }
            $lines = $this->faultLines((string) $order['order_uuid'], $itemIds);
            $meters = $this->sumMeters((string) $order['order_uuid'], $itemIds);
            $now = $this->now();
            $uuid = Uuid::v4();

            $this->pdo->prepare(
                "INSERT INTO production_exceptions (
                    exception_uuid, exception_type, order_uuid, global_order_id, source_key, order_number_snapshot, detected_stage_id, return_stage_id,
                    status, version, detector_employee_uuid, responsible_employee_uuid, responsible_activity_event_id, arrival_number,
                    reason_key, reason_label_snapshot, detector_comment, line_count, fault_meters, production_version_at_report, reported_at, updated_at
                ) VALUES (?, 'cutting_fault', ?, ?, ?, ?, ?, ?, 'awaiting_acknowledgment', 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            )->execute([
                $uuid, $order['order_uuid'], $globalId, $order['source_key'], $order['order_number'], self::DETECTION_STAGE, self::RETURN_STAGE,
                $actor->employeeUuid, $responsible['employee_uuid'], $responsible['event_id'], $responsible['arrival'],
                $reason['reason_key'], $reason['label'], $comment, count($lines), $meters, $expected, $now, $now,
            ]);
            $insertLine = $this->pdo->prepare(
                'INSERT INTO production_exception_lines (exception_uuid, item_uuid, line_number, name_snapshot, product_code_snapshot, variant_snapshot, color_snapshot, quantity_snapshot, meters_snapshot)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            foreach ($lines as $line) {
                $insertLine->execute([$uuid, $line['item_uuid'], $line['line_number'], $line['name'], $line['product_code'], $line['variant'], $line['color'], $line['quantity'], $line['meters']]);
            }
            $this->event($uuid, 'reported', $actor->employeeUuid, 1, [
                'itemIds' => array_column($lines, 'item_uuid'),
                'lines' => array_map(static fn (array $line): array => ['itemId' => $line['item_uuid'], 'lineNumber' => (int) $line['line_number'], 'meters' => (string) $line['meters']], $lines),
                'faultMeters' => $meters,
                'reason' => ['key' => $reason['reason_key'], 'label' => $reason['label']],
                'responsibleEmployeeId' => $responsible['employee_uuid'],
                'responsibleActivityEventId' => $responsible['event_id'],
                'arrivalNumber' => $responsible['arrival'],
                'productionVersion' => $expected,
            ], $requestId, $now);

            $this->updateOrder((string) $order['order_uuid'], $expected, ['open_exception_uuid' => $uuid], $now, 'open_exception_uuid IS NULL');
            $stage = $this->stage($workflow, self::DETECTION_STAGE);
            $this->activity($order, $workflow, 'fault_reported', $actor->employeeUuid, $stage, null, null, null, $expected, $meters, $key, $requestId, $now);

            $payload = $this->livePayload($uuid, $order, 'awaiting_acknowledgment', 1);
            $this->live->toEmployee($responsible['employee_uuid'], 'exception.acknowledgment_required', $payload, $now);
            $this->live->toEmployee($actor->employeeUuid, 'exception.updated', $payload, $now);
            $this->live->toApprovers('exception.waiting_worker', $payload, $now);

            $response = $this->queries->detail($uuid, $actor->employeeUuid, false);
            $this->idempotency->store($actor->employeeUuid, $key, 'fault.report', $globalId, $hash, $response, $now);
            return $response;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function acknowledge(EmployeeIdentity $actor, string $exceptionId, array $input, string $key, string $requestId): array
    {
        $this->authorization->require($actor, 'orders.acknowledge_fault');
        IdempotencyStore::requireKey($key);
        $exceptionUuid = $this->uuid($exceptionId);
        $expected = $this->positiveInt($input['expectedVersion'] ?? null, 'expectedVersion');
        if (($input['confirmed'] ?? null) !== true) {
            throw new ApiException(400, 'CONFIRMATION_REQUIRED', 'The acknowledgment must be confirmed explicitly.');
        }
        $token = is_string($input['qrToken'] ?? null) ? $input['qrToken'] : throw new ApiException(400, 'QR_REQUIRED', 'The order QR code must be scanned.');
        $reference = QrReference::parsePayload($token) ?? throw new ApiException(422, 'INVALID_QR', 'The scanned code is not an Arasya order code.');
        $comment = $this->comment($input['comment'] ?? null, false);
        $hash = IdempotencyStore::hash('fault.acknowledge', $exceptionUuid, [$expected, $reference->value, $comment]);

        return $this->transaction(function () use ($actor, $exceptionUuid, $expected, $reference, $comment, $key, $hash, $requestId): array {
            [$order, $exception] = $this->lockException($exceptionUuid);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'fault.acknowledge', $exceptionUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            if ($exception['responsible_employee_uuid'] !== $actor->employeeUuid) {
                throw new ApiException(403, 'EXCEPTION_NOT_ASSIGNED', 'This request is not addressed to you.');
            }
            $this->assertStatus($exception, ['awaiting_acknowledgment'], $expected);
            \Arasya\Operations\Document\DocumentGuard::assertOpen($order);
            \Arasya\Operations\Document\DocumentGuard::assertQrUsable($this->pdo, $reference->value);
            $qr = $this->pdo->prepare('SELECT order_uuid, status, expires_at FROM order_qr_references WHERE qr_reference = ?');
            $qr->execute([$reference->value]);
            $qrRow = $qr->fetch(PDO::FETCH_ASSOC);
            $now = $this->now();
            if (!is_array($qrRow) || $qrRow['status'] !== 'active' || ($qrRow['expires_at'] !== null && (string) $qrRow['expires_at'] <= $now)) {
                throw new ApiException(422, 'INVALID_QR', 'The scanned code is not a valid order code.');
            }
            if ($qrRow['order_uuid'] !== $exception['order_uuid']) {
                throw new ApiException(422, 'QR_ORDER_MISMATCH', 'The scanned code belongs to a different order.');
            }
            $version = $expected + 2;
            $this->pdo->prepare(
                "UPDATE production_exceptions SET status = 'awaiting_approval', version = ?, acknowledged_at = ?, acknowledgment_comment = ?, qr_reference_verified = ?, qr_verified_at = ?, updated_at = ?
                 WHERE exception_uuid = ? AND version = ? AND status = 'awaiting_acknowledgment'",
            )->execute([$version, $now, $comment, $reference->value, $now, $now, $exceptionUuid, $expected]);
            $decisionUuid = $this->openDecision($exceptionUuid, null, 'acknowledged', $actor->employeeUuid, null, $now);
            $this->event($exceptionUuid, 'acknowledged', $actor->employeeUuid, $expected + 1, ['qrVerified' => true, 'qrReference' => $reference->value, 'comment' => $comment], $requestId, $now);
            $this->event($exceptionUuid, 'approval_requested', $actor->employeeUuid, $version, ['attempt' => 1, 'decisionId' => $decisionUuid], $requestId, $now);

            $payload = $this->livePayload($exceptionUuid, $order, 'awaiting_approval', $version);
            $this->live->toApprovers('exception.approval_pending', $payload, $now);
            $this->live->toEmployee($actor->employeeUuid, 'exception.updated', $payload, $now);
            $this->live->toEmployee((string) $exception['detector_employee_uuid'], 'exception.updated', $payload, $now);

            $response = $this->queries->detail($exceptionUuid, $actor->employeeUuid, false);
            $this->idempotency->store($actor->employeeUuid, $key, 'fault.acknowledge', $exceptionUuid, $hash, $response, $now);
            return $response;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function decide(EmployeeIdentity $actor, string $exceptionId, array $input, string $key, string $requestId): array
    {
        $via = $this->approvers->require($actor);
        IdempotencyStore::requireKey($key);
        $exceptionUuid = $this->uuid($exceptionId);
        $expected = $this->positiveInt($input['expectedVersion'] ?? null, 'expectedVersion');
        $decision = $input['decision'] ?? null;
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'decision must be approve or reject.');
        }
        $comment = $this->comment($input['comment'] ?? null, $decision === 'reject');
        $workflow = $this->workflow();
        $hash = IdempotencyStore::hash('fault.decide', $exceptionUuid, [$expected, $decision, $comment]);

        return $this->transaction(function () use ($actor, $via, $exceptionUuid, $expected, $decision, $comment, $workflow, $key, $hash, $requestId): array {
            [$order, $exception] = $this->lockException($exceptionUuid);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'fault.decide', $exceptionUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            // Authority is re-evaluated inside the transaction: a backup window that ended or a role
            // removed while the screen was open no longer decides anything.
            if ($this->approvers->via($this->employees->findByUuid($actor->employeeUuid) ?? $actor) === null) {
                throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');
            }
            if (in_array($exception['status'], ['approved', 'rejected', 'cancelled'], true)) {
                throw new ApiException(409, 'EXCEPTION_ALREADY_RESOLVED', 'This request was already resolved.', ['status' => $exception['status']]);
            }
            $this->assertStatus($exception, ['awaiting_approval'], $expected);
            if (in_array($actor->employeeUuid, [$exception['detector_employee_uuid'], $exception['responsible_employee_uuid']], true)) {
                throw new ApiException(403, 'SELF_DECISION_DENIED', 'You cannot decide a request in which you are involved.');
            }
            $pending = $this->pdo->prepare("SELECT decision_uuid, attempt_number FROM production_exception_decisions WHERE exception_uuid = ? AND status = 'pending' FOR UPDATE");
            $pending->execute([$exceptionUuid]);
            $attempt = $pending->fetch(PDO::FETCH_ASSOC);
            if (!is_array($attempt)) {
                throw new RuntimeException('An awaiting request has no pending decision attempt.');
            }
            $now = $this->now();
            $version = $expected + 1;
            $orderUuid = (string) $order['order_uuid'];
            $productionVersion = (int) $order['production_version'];

            $this->pdo->prepare("UPDATE production_exception_decisions SET status = ?, decided_by_employee_uuid = ?, decided_via = ?, decision_comment = ?, decided_at = ? WHERE decision_uuid = ? AND status = 'pending'")
                ->execute([$decision === 'approve' ? 'approved' : 'rejected', $actor->employeeUuid, $via, $comment, $now, $attempt['decision_uuid']]);
            $detectionStage = $this->stage($workflow, self::DETECTION_STAGE);

            if ($decision === 'reject') {
                $this->pdo->prepare("UPDATE production_exceptions SET status = 'rejected', version = ?, resolved_at = ?, updated_at = ? WHERE exception_uuid = ? AND version = ?")
                    ->execute([$version, $now, $now, $exceptionUuid, $expected]);
                if ($order['open_exception_uuid'] === $exceptionUuid) {
                    $this->updateOrder($orderUuid, $productionVersion, ['open_exception_uuid' => null], $now, 'open_exception_uuid = ' . $this->pdo->quote($exceptionUuid));
                    $this->activity($order, $workflow, 'fault_rejected', $actor->employeeUuid, $detectionStage, null, null, null, $productionVersion, null, $key, $requestId, $now);
                }
                $this->event($exceptionUuid, 'rejected', $actor->employeeUuid, $version, ['attempt' => (int) $attempt['attempt_number'], 'via' => $via, 'comment' => $comment], $requestId, $now);
                $this->audit->record($actor, 'production.exception.rejected', 'production_exception', $exceptionUuid, ExceptionQueries::number((int) $exception['exception_number']) . " · {$order['order_number']}",
                    ['attempt' => (int) $attempt['attempt_number'], 'via' => $via, 'order' => $order['global_order_id'], 'reasonKey' => $exception['reason_key'],
                        'detector' => $exception['detector_employee_uuid'], 'responsible' => $exception['responsible_employee_uuid'],
                        'stage' => ['before' => self::DETECTION_STAGE, 'after' => self::DETECTION_STAGE],
                        'status' => ['before' => 'awaiting_approval', 'after' => 'rejected']], $requestId, $now);
                $type = 'exception.rejected';
            } else {
                $this->assertOrderOpenForProduction($order);
                if ($order['production_stage_id'] !== self::DETECTION_STAGE || $order['open_exception_uuid'] !== $exceptionUuid) {
                    throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. The request cannot be approved.');
                }
                $returnStage = $this->stage($workflow, self::RETURN_STAGE);
                $cycles = $this->pdo->prepare('SELECT COUNT(*) FROM production_exceptions WHERE order_uuid = ? AND rework_cycle IS NOT NULL');
                $cycles->execute([$orderUuid]);
                $cycle = (int) $cycles->fetchColumn() + 1;

                // Lock the responsible employee so a concurrent deactivation is seen before assignment.
                $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$exception['responsible_employee_uuid']]);
                $responsible = $this->employees->findByUuid((string) $exception['responsible_employee_uuid']);
                $assignee = $responsible !== null && OrderOwnershipService::isEligibleOwner($responsible, self::RETURN_STAGE) ? $responsible->employeeUuid : null;
                $previousOwner = $order['production_owner_employee_uuid'] === null ? null : (string) $order['production_owner_employee_uuid'];

                $this->pdo->prepare("UPDATE production_exceptions SET status = 'approved', version = ?, resolved_at = ?, rework_cycle = ?, updated_at = ? WHERE exception_uuid = ? AND version = ?")
                    ->execute([$version, $now, $cycle, $now, $exceptionUuid, $expected]);
                $this->updateOrder($orderUuid, $productionVersion, [
                    'production_stage_id' => self::RETURN_STAGE,
                    'production_owner_employee_uuid' => $assignee,
                    'production_claimed_at' => $assignee === null ? null : $now,
                    'production_changed_at' => $now,
                    'open_exception_uuid' => null,
                ], $now, "production_stage_id = '" . self::DETECTION_STAGE . "' AND open_exception_uuid = " . $this->pdo->quote($exceptionUuid));
                if ($previousOwner !== null) {
                    $this->pdo->prepare("UPDATE employee_order_relations SET status = 'inactive', updated_at = ? WHERE employee_uuid = ? AND order_uuid = ?")
                        ->execute([$now, $previousOwner, $orderUuid]);
                }
                if ($assignee !== null) {
                    $this->pdo->prepare(
                        "INSERT INTO employee_order_relations (employee_uuid, order_uuid, relation_type, status, started_at, last_action_at, updated_at)
                         VALUES (?, ?, 'assigned', 'active', ?, ?, ?)
                         ON DUPLICATE KEY UPDATE relation_type = VALUES(relation_type), status = 'active', last_action_at = VALUES(last_action_at), updated_at = VALUES(updated_at)",
                    )->execute([$assignee, $orderUuid, $now, $now, $now]);
                    (new \Arasya\Operations\Cutting\CuttingLifecycle($this->pdo))->claimed($orderUuid, $productionVersion + 1, $assignee, $now);
                }
                (new \Arasya\Operations\Cutting\CuttingLifecycle($this->pdo))->fact($orderUuid, $productionVersion + 1, 'pool_entered', null, $now);
                $meters = (string) $exception['fault_meters'];
                $this->activity($order, $workflow, 'fault_returned', $actor->employeeUuid, $detectionStage, $returnStage, $previousOwner, $assignee, $productionVersion, $meters, $key, $requestId, $now);
                $quality = $this->pdo->prepare('INSERT INTO production_quality_events (event_uuid, event_type, employee_uuid, order_uuid, exception_uuid, rework_cycle, is_repeat, line_count, meters, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                foreach (['cutting_fault' => $exception['responsible_employee_uuid'], 'fault_detected' => $exception['detector_employee_uuid']] as $type => $employee) {
                    $quality->execute([Uuid::v4(), $type, $employee, $orderUuid, $exceptionUuid, $cycle, $cycle >= 2 ? 1 : 0, (int) $exception['line_count'], $meters, $now]);
                }
                $this->event($exceptionUuid, 'approved', $actor->employeeUuid, $version, [
                    'attempt' => (int) $attempt['attempt_number'], 'via' => $via, 'comment' => $comment, 'reworkCycle' => $cycle, 'repeated' => $cycle >= 2,
                    'transition' => ['from' => self::DETECTION_STAGE, 'to' => self::RETURN_STAGE], 'assignedTo' => $assignee,
                    'productionVersion' => ['before' => $productionVersion, 'after' => $productionVersion + 1],
                ], $requestId, $now);
                $this->audit->record($actor, 'production.exception.approved', 'production_exception', $exceptionUuid, ExceptionQueries::number((int) $exception['exception_number']) . " · {$order['order_number']}", [
                    'attempt' => (int) $attempt['attempt_number'], 'via' => $via, 'order' => $order['global_order_id'], 'reworkCycle' => $cycle,
                    'reasonKey' => $exception['reason_key'], 'detector' => $exception['detector_employee_uuid'], 'responsible' => $exception['responsible_employee_uuid'],
                    'stage' => ['before' => self::DETECTION_STAGE, 'after' => self::RETURN_STAGE],
                    'owner' => ['before' => $previousOwner, 'after' => $assignee],
                    'status' => ['before' => 'awaiting_approval', 'after' => 'approved'],
                ], $requestId, $now);
                $type = 'exception.approved';
            }

            $payload = $this->livePayload($exceptionUuid, $order, $decision === 'approve' ? 'approved' : 'rejected', $version);
            $this->live->toEmployee((string) $exception['responsible_employee_uuid'], $type, $payload, $now);
            $this->live->toEmployee((string) $exception['detector_employee_uuid'], $type, $payload, $now);
            $this->live->toApprovers('exception.resolved', $payload + ['decidedBy' => $actor->displayName], $now);

            $response = $this->queries->detail($exceptionUuid, null, true);
            $this->idempotency->store($actor->employeeUuid, $key, 'fault.decide', $exceptionUuid, $hash, $response, $now);
            return $response;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function requestRereview(EmployeeIdentity $actor, string $exceptionId, array $input, string $key, string $requestId): array
    {
        $this->authorization->requireApplication($actor, 'staff');
        IdempotencyStore::requireKey($key);
        $exceptionUuid = $this->uuid($exceptionId);
        $expected = $this->positiveInt($input['expectedVersion'] ?? null, 'expectedVersion');
        $comment = $this->comment($input['comment'] ?? null, true);
        $workflow = $this->workflow();
        $hash = IdempotencyStore::hash('fault.rereview', $exceptionUuid, [$expected, $comment]);

        return $this->transaction(function () use ($actor, $exceptionUuid, $expected, $comment, $workflow, $key, $hash, $requestId): array {
            [$order, $exception] = $this->lockException($exceptionUuid);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'fault.rereview', $exceptionUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            if (!in_array($actor->employeeUuid, [$exception['responsible_employee_uuid'], $exception['detector_employee_uuid']], true)) {
                throw new ApiException(403, 'EXCEPTION_NOT_ASSIGNED', 'This request is not addressed to you.');
            }
            $this->assertStatus($exception, ['rejected'], $expected);
            $this->assertOrderOpenForProduction($order);
            if ($order['production_stage_id'] !== self::DETECTION_STAGE || $order['open_exception_uuid'] !== null) {
                throw new ApiException(409, 'ORDER_CHANGED', 'The order is no longer at tailoring intake; the request cannot be reviewed again.');
            }
            $previous = $this->pdo->prepare("SELECT decision_uuid, attempt_number FROM production_exception_decisions WHERE exception_uuid = ? ORDER BY attempt_number DESC LIMIT 1 FOR UPDATE");
            $previous->execute([$exceptionUuid]);
            $last = $previous->fetch(PDO::FETCH_ASSOC);
            if (!is_array($last)) {
                throw new RuntimeException('A rejected request has no decision.');
            }
            $now = $this->now();
            $version = $expected + 1;
            $productionVersion = (int) $order['production_version'];
            $this->pdo->prepare("UPDATE production_exceptions SET status = 'awaiting_approval', version = ?, resolved_at = NULL, updated_at = ? WHERE exception_uuid = ? AND version = ? AND status = 'rejected'")
                ->execute([$version, $now, $exceptionUuid, $expected]);
            $decisionUuid = $this->openDecision($exceptionUuid, (string) $last['decision_uuid'], 'rereview', $actor->employeeUuid, $comment, $now);
            $this->updateOrder((string) $order['order_uuid'], $productionVersion, ['open_exception_uuid' => $exceptionUuid], $now, 'open_exception_uuid IS NULL');
            $this->activity($order, $workflow, 'fault_rereview_requested', $actor->employeeUuid, $this->stage($workflow, self::DETECTION_STAGE), null, null, null, $productionVersion, null, $key, $requestId, $now);
            $this->event($exceptionUuid, 'rereview_requested', $actor->employeeUuid, $version, ['attempt' => (int) $last['attempt_number'] + 1, 'previousDecisionId' => $last['decision_uuid'], 'decisionId' => $decisionUuid, 'comment' => $comment], $requestId, $now);

            $payload = $this->livePayload($exceptionUuid, $order, 'awaiting_approval', $version);
            $this->live->toApprovers('exception.approval_pending', $payload + ['rereview' => true], $now);
            $this->live->toEmployee((string) $exception['responsible_employee_uuid'], 'exception.updated', $payload, $now);
            $this->live->toEmployee((string) $exception['detector_employee_uuid'], 'exception.updated', $payload, $now);

            $response = $this->queries->detail($exceptionUuid, $actor->employeeUuid, false);
            $this->idempotency->store($actor->employeeUuid, $key, 'fault.rereview', $exceptionUuid, $hash, $response, $now);
            return $response;
        });
    }

    /** Root-only recovery for a request that can no longer progress (for example the responsible employee left). @param array<string, mixed> $input @return array<string, mixed> */
    public function cancel(EmployeeIdentity $actor, string $exceptionId, array $input, string $key, string $requestId): array
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        if (!$actor->isRoot) {
            throw new ApiException(403, 'ROOT_ONLY', 'Only the principal administrator can cancel a request.');
        }
        IdempotencyStore::requireKey($key);
        $exceptionUuid = $this->uuid($exceptionId);
        $expected = $this->positiveInt($input['expectedVersion'] ?? null, 'expectedVersion');
        $reason = $this->comment($input['reason'] ?? null, true);
        $workflow = $this->workflow();
        $hash = IdempotencyStore::hash('fault.cancel', $exceptionUuid, [$expected, $reason]);

        return $this->transaction(function () use ($actor, $exceptionUuid, $expected, $reason, $workflow, $key, $hash, $requestId): array {
            [$order, $exception] = $this->lockException($exceptionUuid);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'fault.cancel', $exceptionUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $this->assertStatus($exception, ExceptionQueries::OPEN_STATUSES, $expected);
            $now = $this->now();
            $version = $expected + 1;
            $this->pdo->prepare("UPDATE production_exception_decisions SET status = 'cancelled', decided_by_employee_uuid = ?, decided_via = 'root', decision_comment = ?, decided_at = ? WHERE exception_uuid = ? AND status = 'pending'")
                ->execute([$actor->employeeUuid, $reason, $now, $exceptionUuid]);
            $this->pdo->prepare("UPDATE production_exceptions SET status = 'cancelled', version = ?, resolved_at = ?, cancelled_by_employee_uuid = ?, cancel_reason = ?, updated_at = ? WHERE exception_uuid = ? AND version = ?")
                ->execute([$version, $now, $actor->employeeUuid, $reason, $now, $exceptionUuid, $expected]);
            if ($order['open_exception_uuid'] === $exceptionUuid) {
                $this->updateOrder((string) $order['order_uuid'], (int) $order['production_version'], ['open_exception_uuid' => null], $now, 'open_exception_uuid = ' . $this->pdo->quote($exceptionUuid));
                $this->activity($order, $workflow, 'fault_cancelled', $actor->employeeUuid, $this->stage($workflow, (string) $order['production_stage_id']), null, null, null, (int) $order['production_version'], null, $key, $requestId, $now);
            }
            $this->event($exceptionUuid, 'cancelled', $actor->employeeUuid, $version, ['reason' => $reason, 'statusBefore' => $exception['status']], $requestId, $now);
            $this->audit->record($actor, 'production.exception.cancelled', 'production_exception', $exceptionUuid, ExceptionQueries::number((int) $exception['exception_number']) . " · {$order['order_number']}",
                ['order' => $order['global_order_id'], 'status' => ['before' => $exception['status'], 'after' => 'cancelled']], $requestId, $now);
            $payload = $this->livePayload($exceptionUuid, $order, 'cancelled', $version);
            $this->live->toEmployee((string) $exception['responsible_employee_uuid'], 'exception.updated', $payload, $now);
            $this->live->toEmployee((string) $exception['detector_employee_uuid'], 'exception.updated', $payload, $now);
            $this->live->toApprovers('exception.resolved', $payload, $now);
            $response = $this->queries->detail($exceptionUuid, null, true);
            $this->idempotency->store($actor->employeeUuid, $key, 'fault.cancel', $exceptionUuid, $hash, $response, $now);
            return $response;
        });
    }

    // ---------------------------------------------------------------- helpers

    /** @template T @param callable(): T $operation @return T */
    private function transaction(callable $operation): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                $result = $operation();
                $this->live->cuttingChanged($this->now());
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

    /** @return array<string, mixed> */
    private function lockOrderByGlobalId(string $globalId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT order_uuid, global_order_id, source_key, order_number, production_stage_id, production_owner_employee_uuid, production_completed_at,
                    operational_status, production_version, open_exception_uuid, document_status
             FROM operational_orders WHERE global_order_id = ? FOR UPDATE',
        );
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
    }

    /** Locks the order row, then the exception row (the same order every command uses). @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function lockException(string $exceptionUuid): array
    {
        $find = $this->pdo->prepare('SELECT global_order_id FROM production_exceptions WHERE exception_uuid = ?');
        $find->execute([$exceptionUuid]);
        $globalId = $find->fetchColumn();
        if (!is_string($globalId)) {
            throw new ApiException(404, 'EXCEPTION_NOT_FOUND', 'The request was not found.');
        }
        $order = $this->lockOrderByGlobalId($globalId);
        $statement = $this->pdo->prepare('SELECT * FROM production_exceptions WHERE exception_uuid = ? FOR UPDATE');
        $statement->execute([$exceptionUuid]);
        $exception = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($exception) || $exception['order_uuid'] !== $order['order_uuid']) {
            throw new ApiException(404, 'EXCEPTION_NOT_FOUND', 'The request was not found.');
        }
        return [$order, $exception];
    }

    /** @param array<string, mixed> $exception @param list<string> $statuses */
    private function assertStatus(array $exception, array $statuses, int $expected): void
    {
        if ((int) $exception['version'] !== $expected) {
            throw new ApiException(409, 'EXCEPTION_CHANGED', 'The request changed. Reload it before continuing.', ['status' => $exception['status'], 'version' => (int) $exception['version']]);
        }
        if (!in_array($exception['status'], $statuses, true)) {
            throw new ApiException(409, 'EXCEPTION_STATE_INVALID', 'The request is not in a state that allows this action.', ['status' => $exception['status']]);
        }
    }

    /** @param array<string, mixed> $order */
    private function assertOrderOpenForProduction(array $order): void
    {
        if ($order['production_completed_at'] !== null) {
            throw new ApiException(409, 'PRODUCTION_COMPLETED', 'Production is already completed.');
        }
        if ($order['operational_status'] === 'unavailable') {
            throw new ApiException(409, 'ORDER_UNAVAILABLE', 'The order is not available for production.');
        }
        \Arasya\Operations\Document\DocumentGuard::assertOpen($order);
    }

    /**
     * The employee who completed the latest material-preparation -> workshop-receiving handoff owns the
     * cutting result (also after a future transfer between cutting employees). @return array{employee_uuid: string, event_id: string, arrival: int}|null
     */
    private function responsibleHandoff(string $orderUuid): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT event_id, employee_uuid FROM order_activity_events
             WHERE order_uuid = ? AND action = 'stage_completed' AND from_stage_id = ? AND to_stage_id = ?
             ORDER BY production_version_after DESC LIMIT 1",
        );
        $statement->execute([$orderUuid, self::RETURN_STAGE, self::DETECTION_STAGE]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = ? AND action = 'stage_completed' AND from_stage_id = ? AND to_stage_id = ?");
        $count->execute([$orderUuid, self::RETURN_STAGE, self::DETECTION_STAGE]);
        return ['employee_uuid' => (string) $row['employee_uuid'], 'event_id' => (string) $row['event_id'], 'arrival' => (int) $count->fetchColumn()];
    }

    /** @param list<string> $itemIds @return list<array<string, mixed>> */
    private function faultLines(string $orderUuid, array $itemIds): array
    {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT item_uuid, line_number, name, product_code, variant, color, quantity, meters FROM operational_order_items
             WHERE order_uuid = ? AND item_uuid IN ({$placeholders}) ORDER BY line_number",
        );
        $statement->execute([$orderUuid, ...$itemIds]);
        $lines = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($lines) !== count($itemIds)) {
            throw new ApiException(422, 'FAULT_LINES_INVALID', 'Every selected line must belong to this order.');
        }
        foreach ($lines as $line) {
            if ($line['meters'] === null || preg_match('/^0*\.?0*$/', (string) $line['meters']) === 1) {
                throw new ApiException(422, 'FAULT_LINE_WITHOUT_METERS', 'A selected line has no meters, so the fault scope cannot be measured.');
            }
        }
        return $lines;
    }

    /** Exact decimal sum computed by the database: whole-line meters, never quantity x meters. @param list<string> $itemIds */
    private function sumMeters(string $orderUuid, array $itemIds): string
    {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $statement = $this->pdo->prepare("SELECT CAST(SUM(meters) AS DECIMAL(12,3)) FROM operational_order_items WHERE order_uuid = ? AND item_uuid IN ({$placeholders})");
        $statement->execute([$orderUuid, ...$itemIds]);
        return (string) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function reason(string $key): array
    {
        $statement = $this->pdo->prepare("SELECT reason_key, label, requires_comment FROM production_fault_reasons WHERE reason_key = ? AND status = 'active'");
        $statement->execute([$key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(422, 'REASON_INVALID', 'The selected reason is not available.');
    }

    private function openDecision(string $exceptionUuid, ?string $previous, string $reason, string $openedBy, ?string $comment, string $now): string
    {
        $uuid = Uuid::v4();
        $next = $this->pdo->prepare('SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM production_exception_decisions WHERE exception_uuid = ?');
        $next->execute([$exceptionUuid]);
        $this->pdo->prepare(
            "INSERT INTO production_exception_decisions (decision_uuid, exception_uuid, attempt_number, previous_decision_uuid, opened_reason, opened_by_employee_uuid, opened_comment, opened_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
        )->execute([$uuid, $exceptionUuid, (int) $next->fetchColumn(), $previous, $reason, $openedBy, $comment, $now]);
        $s=$this->pdo->prepare('SELECT detector_employee_uuid,responsible_employee_uuid FROM production_exceptions WHERE exception_uuid=?');
        $s->execute([$exceptionUuid]);
        (new \Arasya\Operations\Analytics\ApprovalEligibility($this->pdo,$this->employees,$this->approvers))->capture('exception',$uuid,$now,array_values($s->fetch(\PDO::FETCH_ASSOC)));
        return $uuid;
    }

    /** @param array<string, mixed>|null $metadata */
    private function event(string $exceptionUuid, string $action, string $actorUuid, int $versionAfter, ?array $metadata, string $requestId, string $now): void
    {
        $this->pdo->prepare('INSERT INTO production_exception_events (event_uuid, exception_uuid, action, actor_employee_uuid, exception_version_after, metadata_json, request_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([Uuid::v4(), $exceptionUuid, $action, $actorUuid, $versionAfter, $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), mb_substr($requestId, 0, 100), $now]);
    }

    /**
     * Applies the given columns and increments both order versions, guarded by the expected production
     * version and an extra condition. Anything else changed concurrently is a 409, never an overwrite.
     *
     * @param array<string, string|null> $columns
     */
    private function updateOrder(string $orderUuid, int $productionVersion, array $columns, string $now, string $guard): void
    {
        $set = [];
        $values = [];
        foreach ($columns as $column => $value) {
            $set[] = "{$column} = ?";
            $values[] = $value;
        }
        $statement = $this->pdo->prepare(
            'UPDATE operational_orders SET ' . implode(', ', $set) . ', production_version = production_version + 1, version = version + 1, updated_at = ?
             WHERE order_uuid = ? AND production_version = ? AND production_completed_at IS NULL AND ' . $guard,
        );
        $statement->execute([...$values, $now, $orderUuid, $productionVersion]);
        if ($statement->rowCount() !== 1) {
            throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
        }
    }

    /** @param array<string, mixed> $order */
    private function activity(array $order, ProductionWorkflow $workflow, string $action, string $actorUuid, ProductionStage $from, ?ProductionStage $to, ?string $previousOwner, ?string $newOwner, int $versionBefore, ?string $meters, string $key, string $requestId, string $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO order_activity_events (
                event_id, employee_uuid, order_uuid, global_order_id, source_key, order_number_snapshot, action,
                workflow_key, workflow_version, from_stage_id, from_stage_label_snapshot, to_stage_id, to_stage_label_snapshot,
                previous_owner_employee_uuid, new_owner_employee_uuid,
                production_version_before, production_version_after, meters_snapshot, request_id, idempotency_key, occurred_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            Uuid::v4(), $actorUuid, $order['order_uuid'], $order['global_order_id'], $order['source_key'], $order['order_number'], $action,
            $workflow->id, $workflow->version, $from->id, $from->label, $to?->id, $to?->label,
            $previousOwner, $newOwner, $versionBefore, $versionBefore + 1, $meters, mb_substr($requestId, 0, 100), 'x-' . substr(hash('sha256', $action . '|' . $key), 0, 60), $now,
        ]);
        (new \Arasya\Operations\Analytics\AnalyticsCapture($this->pdo))->refreshOrder($order['order_uuid']);
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function livePayload(string $exceptionUuid, array $order, string $status, int $version): array
    {
        return ['exceptionId' => $exceptionUuid, 'orderId' => (string) $order['global_order_id'], 'orderNumber' => (string) $order['order_number'], 'status' => $status, 'version' => $version];
    }

    private function stage(ProductionWorkflow $workflow, string $stageId): ProductionStage
    {
        foreach ($workflow->stages as $stage) {
            if ($stage->id === $stageId) {
                return $stage;
            }
        }
        throw new ApiException(503, 'WORKFLOW_UNAVAILABLE', 'Production workflow is not ready.');
    }

    private function workflow(): ProductionWorkflow
    {
        try {
            return $this->workflows->current();
        } catch (RuntimeException) {
            throw new ApiException(503, 'WORKFLOW_UNAVAILABLE', 'Production workflow is not ready.');
        }
    }

    private function globalId(string $value): string
    {
        try {
            return GlobalOrderId::fromString($value)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        }
    }

    private function uuid(string $value): string
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1
            ? $value
            : throw new ApiException(404, 'EXCEPTION_NOT_FOUND', 'The request was not found.');
    }

    private function positiveInt(mixed $value, string $field): int
    {
        return is_int($value) && $value > 0 ? $value : throw new ApiException(400, 'INVALID_REQUEST', "{$field} must be a positive integer.");
    }

    /** @return list<string> */
    private function itemIds(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > self::MAX_LINES) {
            throw new ApiException(400, 'INVALID_REQUEST', 'itemIds must list the faulty lines.');
        }
        foreach ($value as $id) {
            if (!is_string($id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id) !== 1) {
                throw new ApiException(400, 'INVALID_REQUEST', 'itemIds must list the faulty lines.');
            }
        }
        if (count(array_unique($value)) !== count($value)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'A line can be selected only once.');
        }
        $ids = $value;
        sort($ids, SORT_STRING);
        return $ids;
    }

    private function comment(mixed $value, bool $required): ?string
    {
        if ($value !== null && !is_string($value)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'comment must be text.');
        }
        $text = $value === null ? '' : trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if (mb_strlen($text) > self::COMMENT_MAX) {
            throw new ApiException(400, 'INVALID_REQUEST', 'The comment is too long.');
        }
        if ($text === '') {
            return $required ? throw new ApiException(422, 'COMMENT_REQUIRED', 'A comment is required.') : null;
        }
        if ($required && mb_strlen($text) < 3) {
            throw new ApiException(422, 'COMMENT_REQUIRED', 'A comment is required.');
        }
        return $text;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
