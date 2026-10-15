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
 *   cancel (root only, recovery for a request that can no longer progress; a supervised case whose product
 *     already left its owner is never released by cancellation, see cancel())
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
    public const TYPE_TAILORING = 'tailoring_defect';

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

    /**
     * A defect report. Staff 2.26.0+ names the exact physical pieces (`units`), optionally on a rework track
     * (`trackId`); older clients name whole lines (`itemIds`, every piece of those lines that travels with the order).
     *
     * When the named pieces are all the pieces of the carrier (the order, or the track), the report acts on that
     * carrier exactly as before: it is blocked and, if approved, the whole carrier returns to cutting. When they are
     * only some of its pieces (one defective curtain of two), those pieces leave the carrier on a new rework track at
     * the same stage with the same owner, the report acts on that track only, and the rest of the order is not blocked
     * and keeps its progress. Partial reports are refused until the partial_rework flag is enabled.
     *
     * @param array<string, mixed> $input @return array<string, mixed>
     */
    public function report(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId): array
    {
        $this->authorization->require($actor, 'orders.report_fault');
        IdempotencyStore::requireKey($key);
        $globalId = $this->globalId($globalOrderId);
        $expected = $this->positiveInt($input['expectedVersion'] ?? null, 'expectedVersion');
        $unitIds = array_key_exists('units', $input) ? $this->unitIds($input['units']) : null;
        $trackId = $input['trackId'] ?? null;
        if ($trackId !== null && (!\Arasya\Operations\Production\WorkUnits::isUuid($trackId) || $unitIds === null)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'trackId must be a piece track id and requires units.');
        }
        if ($unitIds === null) {
            $itemIds = $this->itemIds($input['itemIds'] ?? null);
        } elseif (array_key_exists('itemIds', $input)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Name either the pieces or the lines, not both.');
        } else {
            $itemIds = [];
        }
        $reasonKey = is_string($input['reasonKey'] ?? null) ? $input['reasonKey'] : throw new ApiException(400, 'INVALID_REQUEST', 'reasonKey is required.');
        $comment = $this->comment($input['comment'] ?? null, false);
        // API 2.32.0: an administrative recovery names the earlier report of the same physical defect, whose return was
        // withdrawn by mistake; the defect is then not counted a second time.
        $recovers = $input['recoversExceptionId'] ?? null;
        if ($recovers !== null && (!is_string($recovers) || !\Arasya\Operations\Production\WorkUnits::isUuid($recovers))) {
            throw new ApiException(400, 'INVALID_REQUEST', 'recoversExceptionId trebuie să identifice o cerere.');
        }
        if ($recovers !== null && $comment === null) {
            throw new ApiException(422, 'COMMENT_REQUIRED', 'Explică de ce este recuperată cererea anterioară.');
        }
        $workflow = $this->workflow();
        // Line reports keep their original hash, so a retry from an older client replays exactly as before.
        $hash = $unitIds === null
            ? IdempotencyStore::hash('fault.report', $globalId, $recovers === null ? [$expected, $itemIds, $reasonKey, $comment] : [$expected, $itemIds, $reasonKey, $comment, $recovers])
            : IdempotencyStore::hash('fault.report', $globalId, $recovers === null ? [$expected, 'units', $unitIds, $trackId, $reasonKey, $comment] : [$expected, 'units', $unitIds, $trackId, $reasonKey, $comment, $recovers]);

        return $this->transaction(function () use ($actor, $globalId, $expected, $unitIds, $trackId, $itemIds, $reasonKey, $comment, $recovers, $workflow, $key, $hash, $requestId): array {
            $order = $this->lockOrderByGlobalId($globalId);
            $actor = $this->lockActor($actor, $order, 'staff');
            $this->authorization->require($actor, 'orders.report_fault');
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'fault.report', $globalId, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $orderUuid = (string) $order['order_uuid'];
            $now = $this->now();
            $carrier = self::orderCarrier($order);
            if ($trackId !== null) {
                $track = $this->tracks()->lock((string) $trackId);
                if ($track === null || $track['order_uuid'] !== $orderUuid || $track['status'] !== 'active') {
                    throw new ApiException(404, 'TRACK_NOT_FOUND', 'The piece was not found.');
                }
                $carrier = self::trackCarrier($track);
            }
            if ((int) $carrier['production_version'] !== $expected) {
                throw new ApiException(409, $trackId === null ? 'ORDER_CHANGED' : 'TRACK_CHANGED', 'The order changed. Reload it before continuing.');
            }
            $this->assertOrderOpenForProduction($order);
            // With an appointed tailoring quality supervisor, Trendyol, Trendhome and OutletPerdele defects found at
            // stages 3-12 go to the supervisor first. Without one (and always for B2B) the original intake-only
            // return to cutting applies unchanged.
            $supervised = TailoringSupervisorPolicy::supervisedFlowApplies((string) $order['source_key']) && $this->supervisors()->isActive();
            $stageId = (string) $carrier['production_stage_id'];
            if ($supervised ? !in_array($stageId, TailoringSupervisorPolicy::TAILORING_STAGES, true) : $stageId !== self::DETECTION_STAGE) {
                throw new ApiException(409, 'EXCEPTION_STAGE_INVALID', $supervised ? 'A defect can only be reported during tailoring (stages 3-12).' : 'A cutting fault can only be reported at tailoring intake.');
            }
            if ($carrier['open_exception_uuid'] !== null) {
                throw new ApiException(409, 'EXCEPTION_ALREADY_OPEN', 'This order already has an open return request.');
            }
            if ($carrier['production_owner_employee_uuid'] !== $actor->employeeUuid || !$actor->worksAt($stageId, (string) $order['source_key'])) {
                throw new ApiException(403, 'FAULT_REPORT_NOT_ALLOWED', $supervised ? 'Only the employee who owns the order at its current stage can report a defect.' : 'Only the tailoring intake employee who accepted the order can return it to cutting.');
            }
            $reason = $this->reason($reasonKey);
            if ((int) $reason['requires_comment'] === 1 && $comment === null) {
                throw new ApiException(422, 'COMMENT_REQUIRED', 'A comment is required for this reason.');
            }

            // The exact pieces exist only when the manufacturing plan of every line of the order is known (the commercial
            // quantity is not the number of pieces). Line reports name every piece of the lines that is here, on the order;
            // without a known plan they keep their order-level meaning (no pieces are invented).
            $unknownLines = (new \Arasya\Operations\Production\ManufacturingPlans($this->pdo))->unknownLines($orderUuid);
            if ($unitIds === null && (new \Arasya\Operations\Production\FeatureActivation($this->pdo))->appliesTo(\Arasya\Operations\Production\FeatureActivation::PARTIAL_REWORK, $orderUuid)) {
                throw new ApiException(422, 'EXACT_PIECES_REQUIRED', 'Selectează explicit bucățile defecte. Un raport pe linie nu poate muta bucățile conforme.');
            }
            $piecewise = $unknownLines === [];
            if (!$piecewise && ($unitIds !== null || $this->units()->activeTrackCount($orderUuid) > 0)) {
                throw new ApiException(409, 'PLAN_CONFIRMATION_REQUIRED', 'Planul de producție al comenzii trebuie confirmat (numărul real de bucăți al fiecărui produs) înainte de a raporta bucăți.', ['itemIds' => $unknownLines]);
            }
            $selected = [];
            $carrierUnits = [];
            $partial = false;
            if ($piecewise) {
                $this->units()->ensure($orderUuid, $now);
                $carrierUnits = $this->units()->carrierUnits($orderUuid, $carrier['track_uuid']);
                if ($unitIds === null) {
                    $this->faultLines($orderUuid, $itemIds, false);
                    $unitIds = array_values(array_map(static fn (array $unit): string => (string) $unit['unit_uuid'], array_filter($carrierUnits, static fn (array $unit): bool => in_array($unit['item_uuid'], $itemIds, true))));
                    if ($unitIds === []) {
                        throw new ApiException(422, 'FAULT_LINES_INVALID', 'Every selected line must have pieces that travel with this order.');
                    }
                    $selected = $this->units()->requireCarrierUnits($orderUuid, null, $unitIds);
                    if (count($selected) < count($carrierUnits) && (new \Arasya\Operations\Production\FeatureActivation($this->pdo))->appliesTo(\Arasya\Operations\Production\FeatureActivation::PARTIAL_REWORK, $orderUuid)) {
                        throw new ApiException(422, 'EXACT_PIECES_REQUIRED', 'Selectează explicit bucățile defecte. Un raport pe linie nu poate muta bucățile conforme.');
                    }
                } else {
                    $selected = $this->units()->requireCarrierUnits($orderUuid, $carrier['track_uuid'], $unitIds);
                    $partial = count($selected) < count($carrierUnits);
                    if ($partial && !(new \Arasya\Operations\Production\FeatureActivation($this->pdo))->appliesTo(\Arasya\Operations\Production\FeatureActivation::PARTIAL_REWORK, $orderUuid)) {
                        throw new ApiException(409, 'PARTIAL_REWORK_DISABLED', 'Reporting only some pieces is not enabled yet. Select every piece or ask a manager.');
                    }
                }
                $itemIds = array_values(array_unique(array_map(static fn (array $unit): string => (string) $unit['item_uuid'], $selected)));
                sort($itemIds, SORT_STRING);
            }
            $linked = $recovers === null ? null : $this->recoveredException($orderUuid, $recovers, $selected, $itemIds);

            $responsible = $this->responsibleHandoff($orderUuid, $carrier['track_uuid']);
            if ($responsible === null) {
                throw new ApiException(409, 'RESPONSIBLE_EMPLOYEE_UNKNOWN', 'The order has no recorded cutting handoff, so no responsible employee can be determined.');
            }
            if ($responsible['employee_uuid'] === $actor->employeeUuid) {
                throw new ApiException(409, 'SELF_FAULT_REPORT_DENIED', 'An employee cannot report a cutting fault attributed to themselves.');
            }
            // Supervised flow: a line without source meters is still reported, never refused, so every source
            // (Trendyol lines may carry only width and height) uses the same defect workflow. Its meterage stays
            // unknown (NULL, counted in unmeasured_line_count); it is never recorded as a measured zero.
            $lines = $this->faultLines($orderUuid, $itemIds, !$supervised);
            // The fabric of the named pieces comes from the confirmed plan; a whole line is the line's meters. Some pieces
            // of a line with an unknown per-piece fabric are unmeasured: never k/q of the line, never zero.
            [$meters, $unmeasured] = $piecewise ? $this->scopeFabric($orderUuid, $lines, $selected) : [self::scopeMeters($lines), count(array_filter($lines, static fn (array $line): bool => !self::measured($line['meters'])))];
            $uuid = Uuid::v4();
            $status = $supervised ? 'awaiting_supervisor_review' : 'awaiting_acknowledgment';

            $this->pdo->prepare(
                "INSERT INTO production_exceptions (
                    exception_uuid, exception_type, order_uuid, global_order_id, source_key, order_number_snapshot, detected_stage_id, return_stage_id,
                    status, version, detector_employee_uuid, detected_owner_employee_uuid, responsible_employee_uuid, responsible_activity_event_id, arrival_number,
                    reason_key, reason_label_snapshot, detector_comment, line_count, fault_meters, unmeasured_line_count, production_version_at_report, reported_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            )->execute([
                $uuid, $supervised ? self::TYPE_TAILORING : 'cutting_fault', $orderUuid, $globalId, $order['source_key'], $order['order_number'], $stageId, self::RETURN_STAGE,
                $status, $actor->employeeUuid, $supervised ? $actor->employeeUuid : null, $responsible['employee_uuid'], $responsible['event_id'], $responsible['arrival'],
                $reason['reason_key'], $reason['label'], $comment, count($lines), $meters, $unmeasured, $expected, $now, $now,
            ]);
            $insertLine = $this->pdo->prepare(
                'INSERT INTO production_exception_lines (exception_uuid, item_uuid, line_number, name_snapshot, product_code_snapshot, variant_snapshot, color_snapshot, quantity_snapshot, meters_snapshot)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            foreach ($lines as $line) {
                $insertLine->execute([$uuid, $line['item_uuid'], $line['line_number'], $line['name'], $line['product_code'], $line['variant'], $line['color'], $line['quantity'], self::measured($line['meters']) ? $line['meters'] : null]);
            }

            $selectedIds = array_map(static fn (array $unit): string => (string) $unit['unit_uuid'], $selected);
            if ($linked !== null) {
                $this->pdo->prepare("INSERT INTO production_exception_links (exception_uuid, linked_exception_uuid, link_type, linked_by_employee_uuid, reason, created_at) VALUES (?, ?, 'administrative_recovery', ?, ?, ?)")
                    ->execute([$uuid, $linked['exception_uuid'], $actor->employeeUuid, (string) $comment, $now]);
            }
            $actsOn = $carrier;
            if ($partial) {
                // Only the named pieces leave: a new track at the same stage with the same owner, blocked by this report.
                $cycle = max(array_map(static fn (array $unit): int => (int) $unit['rework_cycle'], $selected));
                $newTrack = $this->tracks()->split($orderUuid, $carrier['track_uuid'], $selectedIds, $stageId, $actor->employeeUuid, $uuid, $cycle, $actor->employeeUuid, $key, $requestId, $now);
                $this->units()->issueLabels($orderUuid, $selectedIds, $actor->employeeUuid, $now);
                $actsOn = self::trackCarrier($this->tracks()->lock($newTrack) ?? throw new RuntimeException('The new track could not be reloaded.'));
            } else {
                $this->updateCarrier($orderUuid, $carrier, ['open_exception_uuid' => $uuid], $now, 'open_exception_uuid IS NULL');
                $this->activity($order, $carrier, $workflow, 'fault_reported', $actor->employeeUuid, $this->stage($workflow, $stageId), null, null, null, $expected, $unmeasured === 0 ? $meters : null, $key, $requestId, $now, $uuid);
            }
            if ($piecewise) {
                // Quantities are physical pieces (the plan), not commercial units.
                $this->pdo->prepare(
                    'INSERT INTO production_exception_scopes (exception_uuid, order_uuid, track_uuid, reported_on_track_uuid, affected_quantity, carrier_quantity_at_report, order_quantity_at_report, partial, responsible_track_event_uuid, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                )->execute([$uuid, $orderUuid, $actsOn['track_uuid'], $carrier['track_uuid'], count($selected), count($carrierUnits), $this->orderPieceCount($orderUuid), $partial ? 1 : 0, $responsible['track_event_uuid'], $now]);
                $counts = [];
                foreach ($this->units()->pieces($orderUuid) as $piece) {
                    $counts[$piece['id']] = $piece['count'];
                }
                $insertUnit = $this->pdo->prepare('INSERT INTO production_exception_units (exception_uuid, unit_uuid, item_uuid, unit_index, unit_count_snapshot, rework_cycle_at_report) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($selected as $unit) {
                    // unit_index is the deliverable slot ("Bucata 1/2"), also for a replacement piece.
                    $insertUnit->execute([$uuid, $unit['unit_uuid'], $unit['item_uuid'], (int) $unit['slot'], $counts[$unit['unit_uuid']] ?? (int) $unit['slot'], (int) $unit['rework_cycle']]);
                }
            }

            $this->event($uuid, 'reported', $actor->employeeUuid, 1, [
                'itemIds' => array_column($lines, 'item_uuid'),
                'lines' => array_map(static fn (array $line): array => ['itemId' => $line['item_uuid'], 'lineNumber' => (int) $line['line_number'], 'meters' => self::measured($line['meters']) ? (string) $line['meters'] : null], $lines),
                'units' => $selectedIds,
                'affectedQuantity' => count($selected),
                'partial' => $partial,
                'trackId' => $actsOn['track_uuid'],
                'reportedOnTrackId' => $carrier['track_uuid'],
                'faultMeters' => $meters,
                'unmeasuredLineCount' => $unmeasured,
                'reason' => ['key' => $reason['reason_key'], 'label' => $reason['label']],
                'responsibleEmployeeId' => $responsible['employee_uuid'],
                'responsibleActivityEventId' => $responsible['event_id'],
                'responsibleTrackEventId' => $responsible['track_event_uuid'],
                'arrivalNumber' => $responsible['arrival'],
                'productionVersion' => $expected,
                'detectedStage' => $stageId,
                'flow' => $supervised ? 'supervised' : 'intake',
                'piecewise' => $piecewise,
                'unknownPlanLines' => $unknownLines,
            ] + ($linked === null ? [] : ['administrativeRecoveryOf' => $linked['exception_uuid']]), $requestId, $now);

            $payload = $this->livePayload($uuid, $order, $status, 1);
            if ($supervised) {
                // Internal to tailoring: only the supervisor is asked to act. No manager is involved yet.
                foreach ($this->supervisors()->appointedUuids() as $supervisorUuid) {
                    $this->live->toEmployee($supervisorUuid, 'exception.review_required', $payload, $now);
                }
            } else {
                $this->live->toEmployee($responsible['employee_uuid'], 'exception.acknowledgment_required', $payload, $now);
                $this->live->toApprovers('exception.waiting_worker', $payload, $now);
            }
            $this->live->toEmployee($actor->employeeUuid, 'exception.updated', $payload, $now);

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
        $reference = self::scannedCode($input['qrToken'] ?? null);
        $comment = $this->comment($input['comment'] ?? null, false);
        $position = $input['responsibility'] ?? null;
        if ($position !== null && !in_array($position, ['accepted', 'disputed'], true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'responsibility must be accepted or disputed.');
        }
        $hash = IdempotencyStore::hash('fault.acknowledge', $exceptionUuid, $position === null ? [$expected, $reference->value, $comment] : [$expected, $reference->value, $comment, $position]);

        return $this->transaction(function () use ($actor, $exceptionUuid, $expected, $reference, $comment, $position, $key, $hash, $requestId): array {
            [$order, $exception, $carrier] = $this->lockException($exceptionUuid);
            $actor = $this->lockActor($actor, $order, 'staff');
            $this->authorization->require($actor, 'orders.acknowledge_fault');
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'fault.acknowledge', $exceptionUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $tailoring = $exception['exception_type'] === self::TYPE_TAILORING;
            // Intake flow: the responsible cutting employee confirms the fault. Supervised flow: the receiver the
            // supervisor addressed records physical receipt; only the responsible employee states a position on
            // responsibility, and receipt never means admitting a fault.
            if ($exception[$tailoring ? 'receiver_employee_uuid' : 'responsible_employee_uuid'] !== $actor->employeeUuid) {
                throw new ApiException(403, 'EXCEPTION_NOT_ASSIGNED', 'This request is not addressed to you.');
            }
            $this->assertStatus($exception, ['awaiting_acknowledgment'], $expected);
            if ($tailoring) {
                $this->requireEligibleCutter($actor->employeeUuid, (string) $exception['source_key'], 403, 'RECEIVER_NOT_ELIGIBLE');
                $isResponsible = $exception['responsible_employee_uuid'] === $actor->employeeUuid;
                if ($isResponsible && $position === null) {
                    throw new ApiException(400, 'INVALID_REQUEST', 'responsibility must be accepted or disputed.');
                }
                if (!$isResponsible && $position !== null) {
                    throw new ApiException(400, 'INVALID_REQUEST', 'Only the responsible cutting employee states a position on responsibility.');
                }
                if ($position === 'disputed' && $comment === null) {
                    throw new ApiException(422, 'COMMENT_REQUIRED', 'Explain why you dispute the responsibility.');
                }
                $position ??= 'not_stated';
            } elseif ($position !== null) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
            }
            $now = $this->now();
            $this->verifyQr($order, $exception, $reference, $now);
            $version = $expected + 2;
            $this->pdo->prepare(
                "UPDATE production_exceptions SET status = 'awaiting_approval', version = ?, acknowledged_at = ?, acknowledgment_comment = ?, qr_reference_verified = ?, qr_verified_at = ?,
                        physical_custodian_employee_uuid = " . ($tailoring ? '?' : 'physical_custodian_employee_uuid') . ', responsibility_position = ' . ($tailoring ? '?' : 'responsibility_position') . ", updated_at = ?
                 WHERE exception_uuid = ? AND version = ? AND status = 'awaiting_acknowledgment'",
            )->execute([$version, $now, $comment, $reference->value, $now, ...($tailoring ? [$actor->employeeUuid, $position] : []), $now, $exceptionUuid, $expected]);
            if ($tailoring) (new \Arasya\Operations\Production\PieceCustodyReceipt($this->pdo))->forException($exceptionUuid, $actor->employeeUuid, (string) $carrier['production_stage_id'], $requestId, $key, $now);
            $decisionUuid = $this->openDecision($exceptionUuid, null, 'acknowledged', $actor->employeeUuid, null, $now);
            $this->event($exceptionUuid, 'acknowledged', $actor->employeeUuid, $expected + 1, ['qrVerified' => true, 'qrReference' => $reference->value, 'comment' => $comment]
                + ($tailoring ? ['physicalReceipt' => true, 'responsibility' => $position] : []), $requestId, $now);
            $this->event($exceptionUuid, 'approval_requested', $actor->employeeUuid, $version, ['attempt' => 1, 'decisionId' => $decisionUuid], $requestId, $now);

            $payload = $this->livePayload($exceptionUuid, $order, 'awaiting_approval', $version);
            $this->live->toApprovers('exception.approval_pending', $payload, $now);
            $this->notify($exception, 'exception.updated', $payload, $now, [$actor->employeeUuid]);

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
        $attribution = $input['attribution'] ?? null;
        if ($attribution !== null && ($decision !== 'approve' || !in_array($attribution, ['cutter', 'none'], true))) {
            throw new ApiException(400, 'INVALID_REQUEST', 'attribution must be cutter or none, and only with an approval.');
        }
        $workflow = $this->workflow();
        $hash = IdempotencyStore::hash('fault.decide', $exceptionUuid, $attribution === null ? [$expected, $decision, $comment] : [$expected, $decision, $comment, $attribution]);

        return $this->transaction(function () use ($actor, $via, $exceptionUuid, $expected, $decision, $comment, $attribution, $workflow, $key, $hash, $requestId): array {
            [$order, $exception, $carrier] = $this->lockException($exceptionUuid);
            $actor = $this->lockActor($actor, $order, 'dashboard');
            $via = $this->approvers->require($actor);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'fault.decide', $exceptionUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            // Authority is re-evaluated inside the transaction: a backup window that ended or a role
            // removed while the screen was open no longer decides anything.
            if ($this->approvers->via($this->employees->findByUuid($actor->employeeUuid) ?? $actor) === null) {
                throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');
            }
            $tailoring = $exception['exception_type'] === self::TYPE_TAILORING;
            if (in_array($exception['status'], ['approved', 'rejected', 'cancelled', 'return_rejected', 'restored', 'dismissed'], true)) {
                throw new ApiException(409, 'EXCEPTION_ALREADY_RESOLVED', 'This request was already resolved.', ['status' => $exception['status']]);
            }
            $this->assertStatus($exception, ['awaiting_approval'], $expected);
            // Separation of duties: nobody involved in the case decides it, including the tailoring supervisor
            // and the cutting employee who physically received the product.
            if (in_array($actor->employeeUuid, self::involved($exception), true)) {
                throw new ApiException(403, 'SELF_DECISION_DENIED', 'You cannot decide a request in which you are involved.');
            }
            if ($tailoring && $decision === 'approve') {
                if ($attribution === null) {
                    throw new ApiException(422, 'ATTRIBUTION_REQUIRED', 'State whether cutting responsibility is established.');
                }
                if ($attribution === 'cutter' && !$this->reasonAttributable((string) ($exception['confirmed_reason_key'] ?? $exception['reason_key']))) {
                    throw new ApiException(422, 'ATTRIBUTION_NOT_ALLOWED', 'A material defect cannot be attributed to the cutting employee.');
                }
            } elseif (!$tailoring && $attribution !== null) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
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
            $productionVersion = (int) $carrier['production_version'];
            $onTrack = $carrier['track_uuid'] !== null;

            $this->pdo->prepare("UPDATE production_exception_decisions SET status = ?, decided_by_employee_uuid = ?, decided_via = ?, decision_comment = ?, decided_at = ? WHERE decision_uuid = ? AND status = 'pending'")
                ->execute([$decision === 'approve' ? 'approved' : 'rejected', $actor->employeeUuid, $via, $comment, $now, $attempt['decision_uuid']]);
            $detectionStage = $this->stage($workflow, self::DETECTION_STAGE);

            if ($decision === 'reject') {
                // Intake flow: the rejection unblocks the order at stage 3. Supervised flow: the order stays blocked
                // at stage 3 under the supervisor, because the product may physically be with the cutting employee;
                // the supervisor must restore it (with a QR scan) or ask for a new decision.
                $statusAfter = $tailoring ? 'return_rejected' : 'rejected';
                $this->pdo->prepare('UPDATE production_exceptions SET status = ?, version = ?, resolved_at = ?, updated_at = ? WHERE exception_uuid = ? AND version = ?')
                    ->execute([$statusAfter, $version, $tailoring ? null : $now, $now, $exceptionUuid, $expected]);
                if ($carrier['open_exception_uuid'] === $exceptionUuid) {
                    $this->updateCarrier($orderUuid, $carrier, ['open_exception_uuid' => $tailoring ? $exceptionUuid : null], $now, 'open_exception_uuid = ' . $this->pdo->quote($exceptionUuid));
                    $this->activity($order, $carrier, $workflow, 'fault_rejected', $actor->employeeUuid, $detectionStage, null, null, null, $productionVersion, null, $key, $requestId, $now, $exceptionUuid);
                }
                $this->event($exceptionUuid, 'rejected', $actor->employeeUuid, $version, ['attempt' => (int) $attempt['attempt_number'], 'via' => $via, 'comment' => $comment]
                    + ($tailoring ? ['orderBlocked' => true, 'physicalCustodian' => $exception['physical_custodian_employee_uuid']] : []), $requestId, $now);
                $this->audit->record($actor, 'production.exception.rejected', 'production_exception', $exceptionUuid, ExceptionQueries::number((int) $exception['exception_number']) . " · {$order['order_number']}",
                    ['attempt' => (int) $attempt['attempt_number'], 'via' => $via, 'order' => $order['global_order_id'], 'reasonKey' => $exception['reason_key'],
                        'detector' => $exception['detector_employee_uuid'], 'responsible' => $exception['responsible_employee_uuid']]
                        + ($tailoring ? ['supervisor' => $exception['supervisor_employee_uuid'], 'receiver' => $exception['receiver_employee_uuid']] : [])
                        + ['stage' => ['before' => self::DETECTION_STAGE, 'after' => self::DETECTION_STAGE],
                        'status' => ['before' => 'awaiting_approval', 'after' => $statusAfter]], $requestId, $now);
                $type = 'exception.rejected';
            } else {
                $this->assertOrderOpenForProduction($order);
                if ($carrier['production_stage_id'] !== self::DETECTION_STAGE || $carrier['open_exception_uuid'] !== $exceptionUuid) {
                    throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. The request cannot be approved.');
                }
                $returnStage = $this->stage($workflow, self::RETURN_STAGE);
                $cycles = $this->pdo->prepare('SELECT COUNT(*) FROM production_exceptions WHERE order_uuid = ? AND rework_cycle IS NOT NULL');
                $cycles->execute([$orderUuid]);
                $cycle = (int) $cycles->fetchColumn() + 1;

                if ($tailoring) {
                    // The rework goes to the cutting employee who physically holds the product. Ownership is never
                    // forced onto an employee who can no longer cut: the decision is refused and stays pending.
                    $assignee = $this->requireEligibleCutter((string) $exception['receiver_employee_uuid'], (string) $order['source_key'], 409, 'RETURN_ASSIGNEE_UNAVAILABLE');
                } else {
                    // Lock the responsible employee so a concurrent deactivation is seen before assignment.
                    $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$exception['responsible_employee_uuid']]);
                    $responsible = $this->employees->findByUuid((string) $exception['responsible_employee_uuid']);
                    $assignee = $responsible !== null && OrderOwnershipService::isEligibleOwner($responsible, self::RETURN_STAGE, (string) $order['source_key']) ? $responsible->employeeUuid : null;
                }
                $previousOwner = $carrier['production_owner_employee_uuid'] === null ? null : (string) $carrier['production_owner_employee_uuid'];

                $this->pdo->prepare("UPDATE production_exceptions SET status = 'approved', version = ?, resolved_at = ?, rework_cycle = ?, attribution = ?, updated_at = ? WHERE exception_uuid = ? AND version = ?")
                    ->execute([$version, $now, $cycle, $tailoring ? $attribution : null, $now, $exceptionUuid, $expected]);
                // Only the carrier moves: the whole order when the report named every piece on it, otherwise the track
                // holding exactly the reported pieces. A track's pieces start a new rework cycle, and the stage where
                // the defect was found must later confirm that it is resolved.
                $pieceCycle = $onTrack ? (int) $carrier['rework_cycle'] + 1 : null;
                $this->updateCarrier($orderUuid, $carrier, [
                    'production_stage_id' => self::RETURN_STAGE,
                    'production_owner_employee_uuid' => $assignee,
                    'production_claimed_at' => $assignee === null ? null : $now,
                    'production_changed_at' => $now,
                    'open_exception_uuid' => null,
                ], $now, "production_stage_id = '" . self::DETECTION_STAGE . "' AND open_exception_uuid = " . $this->pdo->quote($exceptionUuid), $onTrack ? [
                    'rework_cycle' => $pieceCycle,
                    'verify_stage_id' => (string) $exception['detected_stage_id'],
                    'verify_exception_uuid' => $exceptionUuid,
                    'resolution_confirmed_at' => null,
                    'resolution_confirmed_by_employee_uuid' => null,
                ] : []);
                if ($onTrack) {
                    $this->pdo->prepare("UPDATE production_work_units SET rework_cycle = ?, updated_at = ? WHERE track_uuid = ? AND status = 'active'")->execute([$pieceCycle, $now, $carrier['track_uuid']]);
                    $carrier['rework_cycle'] = $pieceCycle;
                } else {
                    // The whole order returns: its materialized pieces start a new production cycle too, so every stage they
                    // repeat is recorded in that cycle and no completion of the earlier cycle is reused.
                    $this->pdo->prepare("UPDATE production_work_units SET rework_cycle = rework_cycle + 1, updated_at = ? WHERE order_uuid = ? AND track_uuid IS NULL AND status = 'active'")->execute([$now, $orderUuid]);
                }
                $cycleUnits = $this->pdo->prepare("SELECT unit_uuid, rework_cycle FROM production_work_units WHERE order_uuid = ? AND status = 'active' AND track_uuid <=> ?");
                $cycleUnits->execute([$orderUuid, $carrier['track_uuid']]);
                foreach ($cycleUnits->fetchAll(PDO::FETCH_ASSOC) as $cycleUnit) {
                    $this->units()->startCycle((string) $cycleUnit['unit_uuid'], (int) $cycleUnit['rework_cycle'], self::RETURN_STAGE, $now);
                    (new \Arasya\Operations\Production\PieceVerification($this->pdo))->requireFor((string) $cycleUnit['unit_uuid'], (int) $cycleUnit['rework_cycle'], $orderUuid, $exceptionUuid, (string) $exception['detected_stage_id']);
                }
                if ($previousOwner !== null) {
                    $this->deactivateRelationIfIdle($previousOwner, $orderUuid, $now);
                }
                if ($assignee !== null) {
                    $this->pdo->prepare(
                        "INSERT INTO employee_order_relations (employee_uuid, order_uuid, relation_type, status, started_at, last_action_at, updated_at)
                         VALUES (?, ?, 'assigned', 'active', ?, ?, ?)
                         ON DUPLICATE KEY UPDATE relation_type = VALUES(relation_type), status = 'active', last_action_at = VALUES(last_action_at), updated_at = VALUES(updated_at)",
                    )->execute([$assignee, $orderUuid, $now, $now, $now]);
                    if (!$onTrack) {
                        (new \Arasya\Operations\Cutting\CuttingLifecycle($this->pdo))->claimed($orderUuid, $productionVersion + 1, $assignee, $now);
                    }
                }
                if (!$onTrack) {
                    // Cutting pool facts are keyed by the order's production version; a track has its own history.
                    (new \Arasya\Operations\Cutting\CuttingLifecycle($this->pdo))->fact($orderUuid, $productionVersion + 1, 'pool_entered', null, $now);
                }
                $meters = (string) $exception['fault_meters'];
                $this->activity($order, $carrier, $workflow, 'fault_returned', $actor->employeeUuid, $detectionStage, $returnStage, $previousOwner, $assignee, $productionVersion, self::activityMeters($exception), $key, $requestId, $now, $exceptionUuid, $onTrack ? ['reworkCycle' => $pieceCycle] : null);
                // Intake flow: fault and detection facts as before. Supervised flow: detection was credited when the
                // supervisor verified the defect; a cutting fault exists only when the manager established cutting
                // responsibility, and it is attributed to the historical responsible employee, not the receiver.
                $facts = $tailoring
                    ? ($attribution === 'cutter' ? ['cutting_fault' => $exception['responsible_employee_uuid']] : [])
                    : ['cutting_fault' => $exception['responsible_employee_uuid'], 'fault_detected' => $exception['detector_employee_uuid']];
                $quality = $this->pdo->prepare('INSERT INTO production_quality_events (event_uuid, event_type, employee_uuid, order_uuid, exception_uuid, rework_cycle, is_repeat, line_count, meters, unmeasured_line_count, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($facts as $type => $employee) {
                    $quality->execute([Uuid::v4(), $type, $employee, $orderUuid, $exceptionUuid, $cycle, $cycle >= 2 ? 1 : 0, (int) $exception['line_count'], $meters, (int) $exception['unmeasured_line_count'], $now]);
                }
                $this->event($exceptionUuid, 'approved', $actor->employeeUuid, $version, [
                    'attempt' => (int) $attempt['attempt_number'], 'via' => $via, 'comment' => $comment, 'reworkCycle' => $cycle, 'repeated' => $cycle >= 2,
                    'transition' => ['from' => self::DETECTION_STAGE, 'to' => self::RETURN_STAGE], 'assignedTo' => $assignee,
                    'productionVersion' => ['before' => $productionVersion, 'after' => $productionVersion + 1],
                    'carrier' => $onTrack ? ['type' => 'track', 'trackId' => $carrier['track_uuid'], 'pieceCycle' => $pieceCycle] : ['type' => 'order'],
                    'units' => $this->exceptionUnitIds($exceptionUuid),
                ] + ($tailoring ? ['attribution' => $attribution] : []), $requestId, $now);
                $this->audit->record($actor, 'production.exception.approved', 'production_exception', $exceptionUuid, ExceptionQueries::number((int) $exception['exception_number']) . " · {$order['order_number']}", [
                    'attempt' => (int) $attempt['attempt_number'], 'via' => $via, 'order' => $order['global_order_id'], 'reworkCycle' => $cycle,
                    'reasonKey' => $exception['reason_key'], 'detector' => $exception['detector_employee_uuid'], 'responsible' => $exception['responsible_employee_uuid'],
                ] + ($tailoring ? ['confirmedReasonKey' => $exception['confirmed_reason_key'], 'supervisor' => $exception['supervisor_employee_uuid'], 'receiver' => $exception['receiver_employee_uuid'], 'attribution' => $attribution] : []) + [
                    'stage' => ['before' => self::DETECTION_STAGE, 'after' => self::RETURN_STAGE],
                    'owner' => ['before' => $previousOwner, 'after' => $assignee],
                    'status' => ['before' => 'awaiting_approval', 'after' => 'approved'],
                    'carrier' => $onTrack ? ['type' => 'track', 'trackId' => $carrier['track_uuid']] : ['type' => 'order'],
                    'affectedQuantity' => count($this->exceptionUnitIds($exceptionUuid)),
                ], $requestId, $now);
                $type = 'exception.approved';
            }

            $payload = $this->livePayload($exceptionUuid, $order, $decision === 'approve' ? 'approved' : ($tailoring ? 'return_rejected' : 'rejected'), $version);
            $this->notify($exception, $type, $payload, $now, [(string) $exception['responsible_employee_uuid']]);
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
            [$order, $exception, $carrier] = $this->lockException($exceptionUuid);
            $actor = $this->lockActor($actor, $order, 'staff');
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'fault.rereview', $exceptionUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            // Supervised flow: only the supervisor asks the managers again; the order never left the supervisor's
            // blocked custody. Intake flow: the responsible employee or the detector, on the unblocked order.
            $tailoring = $exception['exception_type'] === self::TYPE_TAILORING;
            if ($tailoring) {
                $this->requireSupervisor($actor, $exception);
            } elseif (!in_array($actor->employeeUuid, [$exception['responsible_employee_uuid'], $exception['detector_employee_uuid']], true)) {
                throw new ApiException(403, 'EXCEPTION_NOT_ASSIGNED', 'This request is not addressed to you.');
            }
            $this->assertStatus($exception, [$tailoring ? 'return_rejected' : 'rejected'], $expected);
            $this->assertOrderOpenForProduction($order);
            if ($carrier['production_stage_id'] !== self::DETECTION_STAGE || $carrier['open_exception_uuid'] !== ($tailoring ? $exceptionUuid : null)) {
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
            $productionVersion = (int) $carrier['production_version'];
            $this->pdo->prepare("UPDATE production_exceptions SET status = 'awaiting_approval', version = ?, resolved_at = NULL, updated_at = ? WHERE exception_uuid = ? AND version = ? AND status = ?")
                ->execute([$version, $now, $exceptionUuid, $expected, $exception['status']]);
            $decisionUuid = $this->openDecision($exceptionUuid, (string) $last['decision_uuid'], 'rereview', $actor->employeeUuid, $comment, $now);
            $this->updateCarrier((string) $order['order_uuid'], $carrier, ['open_exception_uuid' => $exceptionUuid], $now, $tailoring ? 'open_exception_uuid = ' . $this->pdo->quote($exceptionUuid) : 'open_exception_uuid IS NULL');
            $this->activity($order, $carrier, $workflow, 'fault_rereview_requested', $actor->employeeUuid, $this->stage($workflow, self::DETECTION_STAGE), null, null, null, $productionVersion, null, $key, $requestId, $now, $exceptionUuid);
            $this->event($exceptionUuid, 'rereview_requested', $actor->employeeUuid, $version, ['attempt' => (int) $last['attempt_number'] + 1, 'previousDecisionId' => $last['decision_uuid'], 'decisionId' => $decisionUuid, 'comment' => $comment], $requestId, $now);

            $payload = $this->livePayload($exceptionUuid, $order, 'awaiting_approval', $version);
            $this->live->toApprovers('exception.approval_pending', $payload + ['rereview' => true], $now);
            $this->notify($exception, 'exception.updated', $payload, $now, [(string) $exception['responsible_employee_uuid']]);

            $response = $this->queries->detail($exceptionUuid, $actor->employeeUuid, false, $tailoring);
            $this->idempotency->store($actor->employeeUuid, $key, 'fault.rereview', $exceptionUuid, $hash, $response, $now);
            return $response;
        });
    }

    /**
     * Root-only recovery for a request that can no longer progress (for example the responsible employee left).
     *
     * Intake flow, and a supervised report before custody (the order never moved, its owner still holds the
     * product): cancelled, and the order is unblocked where it is. A supervised case after custody (the product
     * is with the supervisor or a cutting employee, the order at stage 3 without an owner) is never released by
     * cancellation: root names the tailoring employee who receives the product back, the case waits for that
     * employee's QR receipt (awaiting_restore_receipt) and ends as `cancelled` only then (receiveRestore). Root
     * can address another recipient while the handover is pending. No manager decision is involved.
     * @param array<string, mixed> $input @return array<string, mixed>
     */
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
        $recipientId = $this->optionalEmployeeId($input['recipientEmployeeId'] ?? null, 'recipientEmployeeId');
        $workflow = $this->workflow();
        $hash = IdempotencyStore::hash('fault.cancel', $exceptionUuid, $recipientId === null ? [$expected, $reason] : [$expected, $reason, $recipientId]);

        return $this->transaction(function () use ($actor, $exceptionUuid, $expected, $reason, $recipientId, $workflow, $key, $hash, $requestId): array {
            [$order, $exception, $carrier] = $this->lockException($exceptionUuid);
            $actor = $this->lockActor($actor, $order, 'dashboard');
            if (!$actor->isRoot) throw new ApiException(403, 'ROOT_ONLY', 'Numai administratorul principal poate anula cererea.');
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'fault.cancel', $exceptionUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $this->assertStatus($exception, ExceptionQueries::OPEN_STATUSES, $expected);
            $now = $this->now();
            $version = $expected + 1;
            $label = ExceptionQueries::number((int) $exception['exception_number']) . " · {$order['order_number']}";
            if (self::custodyMoved($exception)) {
                return $this->recover($actor, $order, $exception, $carrier, $reason, $recipientId, $version, $label, $key, $hash, $requestId, $now);
            }
            if ($recipientId !== null) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
            }
            if ($exception['exception_type'] === self::TYPE_TAILORING
                && ($carrier['open_exception_uuid'] !== $exceptionUuid || $carrier['production_stage_id'] !== $exception['detected_stage_id'] || $carrier['production_owner_employee_uuid'] !== $exception['detected_owner_employee_uuid'])) {
                // Before custody the order must still be where it was reported, with the same owner; anything else
                // is not a state cancellation can safely release.
                throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. The request cannot be cancelled safely.');
            }
            $this->pdo->prepare("UPDATE production_exception_decisions SET status = 'cancelled', decided_by_employee_uuid = ?, decided_via = 'root', decision_comment = ?, decided_at = ? WHERE exception_uuid = ? AND status = 'pending'")
                ->execute([$actor->employeeUuid, $reason, $now, $exceptionUuid]);
            $this->pdo->prepare("UPDATE production_exceptions SET status = 'cancelled', version = ?, resolved_at = ?, cancelled_by_employee_uuid = ?, cancel_reason = ?, updated_at = ? WHERE exception_uuid = ? AND version = ?")
                ->execute([$version, $now, $actor->employeeUuid, $reason, $now, $exceptionUuid, $expected]);
            if ($carrier['open_exception_uuid'] === $exceptionUuid) {
                $this->updateCarrier((string) $order['order_uuid'], $carrier, ['open_exception_uuid' => null], $now, 'open_exception_uuid = ' . $this->pdo->quote($exceptionUuid));
                $this->activity($order, $carrier, $workflow, 'fault_cancelled', $actor->employeeUuid, $this->stage($workflow, (string) $carrier['production_stage_id']), null, null, null, (int) $carrier['production_version'], null, $key, $requestId, $now, $exceptionUuid);
                $this->rejoinIfAligned($order, $carrier, 'cancelled', $actor->employeeUuid, $key, $requestId, $now);
            }
            $this->event($exceptionUuid, 'cancelled', $actor->employeeUuid, $version, ['reason' => $reason, 'statusBefore' => $exception['status']], $requestId, $now);
            $this->audit->record($actor, 'production.exception.cancelled', 'production_exception', $exceptionUuid, $label,
                ['order' => $order['global_order_id'], 'status' => ['before' => $exception['status'], 'after' => 'cancelled']], $requestId, $now);
            $payload = $this->livePayload($exceptionUuid, $order, 'cancelled', $version);
            $this->notify($exception, 'exception.updated', $payload, $now, [(string) $exception['responsible_employee_uuid']]);
            $this->live->toApprovers('exception.resolved', $payload, $now);
            $response = $this->queries->detail($exceptionUuid, null, true);
            $this->idempotency->store($actor->employeeUuid, $key, 'fault.cancel', $exceptionUuid, $hash, $response, $now);
            return $response;
        });
    }

    /** Whether the product left its owner at detection: a supervised case after the supervisor took custody. @param array<string, mixed> $exception */
    public static function custodyMoved(array $exception): bool
    {
        return ($exception['exception_type'] ?? null) === self::TYPE_TAILORING && ($exception['status'] ?? null) !== 'awaiting_supervisor_review';
    }

    /**
     * Root recovery of a supervised case after custody: the order stays blocked at stage 3 without an owner and the
     * product is addressed to a tailoring employee, who unblocks it only by a QR receipt.
     * @param array<string, mixed> $order @param array<string, mixed> $exception @return array<string, mixed>
     */
    private function recover(EmployeeIdentity $actor, array $order, array $exception, array $carrier, string $reason, ?string $recipientId, int $version, string $label, string $key, string $hash, string $requestId, string $now): array
    {
        $exceptionUuid = (string) $exception['exception_uuid'];
        if ($recipientId === null) {
            throw new ApiException(422, 'RECIPIENT_REQUIRED', 'The product is no longer with the employee who reported it. Name the employee who receives it back.');
        }
        $this->assertHeldAtCustodyStage($carrier, $exceptionUuid);
        $custodian = $exception['physical_custodian_employee_uuid'] === null ? null : (string) $exception['physical_custodian_employee_uuid'];
        $recipient = $this->restoreRecipient($exception, $recipientId, $custodian);
        $this->pdo->prepare("UPDATE production_exception_decisions SET status = 'cancelled', decided_by_employee_uuid = ?, decided_via = 'root', decision_comment = ?, decided_at = ? WHERE exception_uuid = ? AND status = 'pending'")
            ->execute([$actor->employeeUuid, $reason, $now, $exceptionUuid]);
        $this->pdo->prepare("UPDATE production_exceptions SET status = 'awaiting_restore_receipt', version = ?, cancelled_by_employee_uuid = ?, cancel_reason = ?, restore_recipient_employee_uuid = ?, restore_requested_at = ?, updated_at = ?
             WHERE exception_uuid = ? AND version = ?")
            ->execute([$version, $actor->employeeUuid, $reason, $recipient, $now, $now, $exceptionUuid, (int) $exception['version']]);
        $this->event($exceptionUuid, 'recovery_requested', $actor->employeeUuid, $version, [
            'reason' => $reason, 'statusBefore' => $exception['status'], 'targetStage' => $exception['detected_stage_id'], 'recipient' => $recipient,
            'previousRecipient' => $exception['restore_recipient_employee_uuid'], 'physicalCustodian' => $custodian, 'orderBlocked' => true,
        ], $requestId, $now);
        $this->audit->record($actor, 'production.exception.recovery_requested', 'production_exception', $exceptionUuid, $label, [
            'order' => $order['global_order_id'], 'recipient' => $recipient, 'physicalCustodian' => $custodian, 'targetStage' => $exception['detected_stage_id'],
            'status' => ['before' => $exception['status'], 'after' => 'awaiting_restore_receipt'],
        ], $requestId, $now);
        $payload = $this->livePayload($exceptionUuid, $order, 'awaiting_restore_receipt', $version);
        $this->live->toEmployee($recipient, 'exception.restore_receipt_required', $payload, $now);
        $previous = $exception['restore_recipient_employee_uuid'];
        $this->notify($exception, 'exception.updated', $payload, $now, [...($custodian !== null ? [$custodian] : []), ...(is_string($previous) ? [$previous] : []), ...($exception['return_requested_at'] !== null ? [(string) $exception['responsible_employee_uuid']] : [])], [$recipient]);
        $this->live->toApprovers('exception.resolved', $payload, $now);
        $response = $this->queries->detail($exceptionUuid, null, true);
        $this->idempotency->store($actor->employeeUuid, $key, 'fault.cancel', $exceptionUuid, $hash, $response, $now);
        return $response;
    }

    // ---------------------------------------------------------------- supervised tailoring defects
    //
    //   report (owner at stages 3-12)            -> awaiting_supervisor_review   order blocked, owner unchanged
    //   review dismiss (supervisor)              -> dismissed                    order unblocked at the same stage and owner
    //   review accept + QR (supervisor)          -> in_supervisor_custody        order moved to stage 3 (no owner), still blocked;
    //                                                                            detection credited to the detector
    //   return (supervisor)                      -> awaiting_acknowledgment      addressed to the responsible cutting employee
    //                                                                            (or, when unavailable, an eligible substitute)
    //   acknowledge + QR (receiver)              -> awaiting_approval            physical receipt; one manager decision opened
    //   decide approve (manager)                 -> approved                     stage 3 -> stage 2, owned by the receiver
    //   decide reject (manager)                  -> return_rejected              order stays blocked under the supervisor
    //   rereview (supervisor)                    -> awaiting_approval            new linked decision attempt
    //   restore + QR (supervisor)                -> awaiting_restore_receipt     still blocked at stage 3, no owner; addressed to
    //                                                                            the owner at detection (or an eligible substitute)
    //   receive + QR (that employee)             -> restored                     back to the detected stage, owned by the
    //                                                                            employee who physically received it, unblocked
    //   root cancel after custody                -> awaiting_restore_receipt     same handover; ends as cancelled on receipt
    //
    // No operations-manager decision exists anywhere in this flow except the stage 3 -> stage 2 return.

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function supervisorReview(EmployeeIdentity $actor, string $exceptionId, array $input, string $key, string $requestId): array
    {
        $this->authorization->requireApplication($actor, 'staff');
        IdempotencyStore::requireKey($key);
        $exceptionUuid = $this->uuid($exceptionId);
        $expected = $this->positiveInt($input['expectedVersion'] ?? null, 'expectedVersion');
        $decision = $input['decision'] ?? null;
        if (!in_array($decision, ['accept', 'dismiss'], true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'decision must be accept or dismiss.');
        }
        $accept = $decision === 'accept';
        $comment = $this->comment($input['comment'] ?? null, !$accept);
        $reasonKey = $input['reasonKey'] ?? null;
        $token = $input['qrToken'] ?? null;
        if ($accept && (!is_string($reasonKey) || $reasonKey === '')) {
            throw new ApiException(400, 'INVALID_REQUEST', 'reasonKey is required.');
        }
        if ($accept && !is_string($token)) {
            throw new ApiException(400, 'QR_REQUIRED', 'The order QR code must be scanned.');
        }
        if (!$accept && ($reasonKey !== null || $token !== null)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
        }
        $reference = $accept ? self::scannedCode($token) : null;
        $workflow = $this->workflow();
        $hash = IdempotencyStore::hash('defect.review', $exceptionUuid, [$expected, $decision, $reasonKey, $reference?->value, $comment]);

        return $this->transaction(function () use ($actor, $exceptionUuid, $expected, $accept, $comment, $reasonKey, $reference, $workflow, $key, $hash, $requestId): array {
            [$order, $exception, $carrier] = $this->lockException($exceptionUuid);
            $actor = $this->lockActor($actor, $order, 'staff');
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'defect.review', $exceptionUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $this->requireSupervisor($actor, $exception);
            $this->assertStatus($exception, ['awaiting_supervisor_review'], $expected);
            if ($exception['detector_employee_uuid'] === $actor->employeeUuid) {
                throw new ApiException(403, 'SELF_REVIEW_DENIED', 'You cannot review a defect you reported.');
            }
            $detectedStage = (string) $exception['detected_stage_id'];
            if ($carrier['open_exception_uuid'] !== $exceptionUuid || $carrier['production_stage_id'] !== $detectedStage) {
                throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
            }
            $now = $this->now();
            $version = $expected + 1;
            $orderUuid = (string) $order['order_uuid'];
            $productionVersion = (int) $carrier['production_version'];
            $from = $this->stage($workflow, $detectedStage);

            if (!$accept) {
                // Before any custody transfer: the order never moved, so it is simply unblocked where it is,
                // with the same owner. A dismissed report is not a confirmed defect and creates no quality fact.
                $this->pdo->prepare("UPDATE production_exceptions SET status = 'dismissed', version = ?, supervisor_employee_uuid = ?, supervisor_reviewed_at = ?, supervisor_comment = ?, resolved_at = ?, updated_at = ?
                     WHERE exception_uuid = ? AND version = ? AND status = 'awaiting_supervisor_review'")
                    ->execute([$version, $actor->employeeUuid, $now, $comment, $now, $now, $exceptionUuid, $expected]);
                $this->updateCarrier($orderUuid, $carrier, ['open_exception_uuid' => null], $now, 'open_exception_uuid = ' . $this->pdo->quote($exceptionUuid));
                $this->activity($order, $carrier, $workflow, 'defect_dismissed', $actor->employeeUuid, $from, null, null, null, $productionVersion, null, $key, $requestId, $now, $exceptionUuid);
                $rejoined = $this->rejoinIfAligned($order, $carrier, 'dismissed', $actor->employeeUuid, $key, $requestId, $now);
                $this->event($exceptionUuid, 'supervisor_dismissed', $actor->employeeUuid, $version, ['comment' => $comment, 'stage' => $detectedStage, 'owner' => $carrier['production_owner_employee_uuid']]
                    + ($carrier['track_uuid'] === null ? [] : ['trackId' => $carrier['track_uuid'], 'rejoinedOrder' => $rejoined]), $requestId, $now);
                $payload = $this->livePayload($exceptionUuid, $order, 'dismissed', $version);
                $this->notify($exception, 'exception.dismissed', $payload, $now, [$actor->employeeUuid]);
            } else {
                $this->assertOrderOpenForProduction($order);
                $this->verifyQr($order, $exception, $reference, $now);
                $reason = $this->reason((string) $reasonKey);
                if ((int) $reason['requires_comment'] === 1 && $comment === null) {
                    throw new ApiException(422, 'COMMENT_REQUIRED', 'A comment is required for this reason.');
                }
                $previousOwner = $carrier['production_owner_employee_uuid'] === null ? null : (string) $carrier['production_owner_employee_uuid'];
                $this->pdo->prepare("UPDATE production_exceptions SET status = 'in_supervisor_custody', version = ?, supervisor_employee_uuid = ?, supervisor_reviewed_at = ?, supervisor_comment = ?,
                        confirmed_reason_key = ?, confirmed_reason_label_snapshot = ?, physical_custodian_employee_uuid = ?, updated_at = ?
                     WHERE exception_uuid = ? AND version = ? AND status = 'awaiting_supervisor_review'")
                    ->execute([$version, $actor->employeeUuid, $now, $comment, $reason['reason_key'], $reason['label'], $actor->employeeUuid, $now, $exceptionUuid, $expected]);
                // Internal tailoring return to stage 3 (or custody at stage 3 without a stage change). Not a
                // stage completion and not a manager decision: the order has no production owner while the
                // supervisor holds the case, and stays blocked.
                $this->updateCarrier($orderUuid, $carrier, [
                    'production_stage_id' => TailoringSupervisorPolicy::CUSTODY_STAGE,
                    'production_owner_employee_uuid' => null,
                    'production_claimed_at' => null,
                    'production_changed_at' => $now,
                ], $now, 'open_exception_uuid = ' . $this->pdo->quote($exceptionUuid) . ' AND production_stage_id = ' . $this->pdo->quote($detectedStage));
                if ($previousOwner !== null) {
                    $this->deactivateRelationIfIdle($previousOwner, $orderUuid, $now);
                }
                $this->activity($order, $carrier, $workflow, 'defect_custody_taken', $actor->employeeUuid, $from, $this->stage($workflow, TailoringSupervisorPolicy::CUSTODY_STAGE), $previousOwner, null, $productionVersion, self::activityMeters($exception), $key, $requestId, $now, $exceptionUuid);
                // The supervisor verified a real defect: the detection belongs to the detector from now on,
                // whatever the managers later decide about cutting responsibility.
                // An administrative recovery of a physical defect already verified (and credited) in an earlier request is
                // the same incident: its detection is not credited a second time.
                $recovered = $this->recoveryOrigin($exceptionUuid);
                $alreadyCredited = false;
                if ($recovered !== null) {
                    $credited = $this->pdo->prepare("SELECT 1 FROM production_quality_events WHERE exception_uuid = ? AND event_type = 'fault_detected'");
                    $credited->execute([$recovered]);
                    $alreadyCredited = $credited->fetchColumn() !== false;
                }
                if (!$alreadyCredited) {
                    $cycles = $this->pdo->prepare('SELECT COUNT(*) FROM production_exceptions WHERE order_uuid = ? AND rework_cycle IS NOT NULL');
                    $cycles->execute([$orderUuid]);
                    $this->pdo->prepare('INSERT INTO production_quality_events (event_uuid, event_type, employee_uuid, order_uuid, exception_uuid, rework_cycle, is_repeat, line_count, meters, unmeasured_line_count, occurred_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)')
                        ->execute([Uuid::v4(), 'fault_detected', $exception['detector_employee_uuid'], $orderUuid, $exceptionUuid, (int) $cycles->fetchColumn() >= 1 ? 1 : 0, (int) $exception['line_count'], (string) $exception['fault_meters'], (int) $exception['unmeasured_line_count'], $now]);
                }
                (new \Arasya\Operations\Production\PieceCustodyReceipt($this->pdo))->forException($exceptionUuid, $actor->employeeUuid, TailoringSupervisorPolicy::CUSTODY_STAGE, $requestId, $key, $now);
                $this->event($exceptionUuid, 'supervisor_accepted', $actor->employeeUuid, $version, [
                    'qrVerified' => true, 'confirmedReason' => ['key' => $reason['reason_key'], 'label' => $reason['label']], 'comment' => $comment,
                    'detectionCredited' => !$alreadyCredited, 'administrativeRecoveryOf' => $recovered,
                    'transition' => ['from' => $detectedStage, 'to' => TailoringSupervisorPolicy::CUSTODY_STAGE], 'previousOwner' => $previousOwner, 'physicalCustodian' => $actor->employeeUuid,
                    'productionVersion' => ['before' => $productionVersion, 'after' => $productionVersion + 1],
                ], $requestId, $now);
                $payload = $this->livePayload($exceptionUuid, $order, 'in_supervisor_custody', $version);
                $this->notify($exception, 'exception.custody_taken', $payload, $now, [$actor->employeeUuid]);
            }
            $response = $this->queries->detail($exceptionUuid, $actor->employeeUuid, false, true);
            $this->idempotency->store($actor->employeeUuid, $key, 'defect.review', $exceptionUuid, $hash, $response, $now);
            return $response;
        });
    }

    /** Supervisor prepares the stage 3 -> stage 2 return; the managers decide only after physical receipt. @param array<string, mixed> $input @return array<string, mixed> */
    public function requestReturn(EmployeeIdentity $actor, string $exceptionId, array $input, string $key, string $requestId): array
    {
        $this->authorization->requireApplication($actor, 'staff');
        IdempotencyStore::requireKey($key);
        $exceptionUuid = $this->uuid($exceptionId);
        $expected = $this->positiveInt($input['expectedVersion'] ?? null, 'expectedVersion');
        $receiverId = $input['receiverEmployeeId'] ?? null;
        if ($receiverId !== null && (!is_string($receiverId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $receiverId) !== 1)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'receiverEmployeeId is invalid.');
        }
        $comment = $this->comment($input['comment'] ?? null, false);
        $hash = IdempotencyStore::hash('defect.return', $exceptionUuid, [$expected, $receiverId, $comment]);

        return $this->transaction(function () use ($actor, $exceptionUuid, $expected, $receiverId, $comment, $key, $hash, $requestId): array {
            [$order, $exception, $carrier] = $this->lockException($exceptionUuid);
            $actor = $this->lockActor($actor, $order, 'staff');
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'defect.return', $exceptionUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $this->requireSupervisor($actor, $exception);
            $this->assertStatus($exception, ['in_supervisor_custody'], $expected);
            $this->assertOrderOpenForProduction($order);
            if ($carrier['production_stage_id'] !== self::DETECTION_STAGE || $carrier['open_exception_uuid'] !== $exceptionUuid) {
                throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
            }
            $source = (string) $exception['source_key'];
            $responsible = (string) $exception['responsible_employee_uuid'];
            $responsibleAvailable = $this->eligibleCutter($responsible, $source);
            // The product goes to the cutting employee of the historical handoff. A substitute receiver can be
            // named only when that employee can no longer cut; responsibility never moves with the product.
            if ($receiverId === null || $receiverId === $responsible) {
                if (!$responsibleAvailable) {
                    throw new ApiException(409, 'RESPONSIBLE_UNAVAILABLE', 'The responsible cutting employee cannot receive the product now. Choose an available cutting employee.');
                }
                $receiver = $responsible;
            } else {
                if ($responsibleAvailable) {
                    throw new ApiException(422, 'RECEIVER_NOT_ALLOWED', 'The responsible cutting employee is available and receives the product.');
                }
                if (in_array($receiverId, [$exception['detector_employee_uuid'], $actor->employeeUuid], true)) {
                    throw new ApiException(422, 'RECEIVER_INVALID', 'Choose an available cutting employee.');
                }
                $this->requireEligibleCutter($receiverId, $source, 422, 'RECEIVER_INVALID');
                $receiver = $receiverId;
            }
            $now = $this->now();
            $version = $expected + 1;
            $this->pdo->prepare("UPDATE production_exceptions SET status = 'awaiting_acknowledgment', version = ?, return_requested_at = ?, receiver_employee_uuid = ?, updated_at = ?
                 WHERE exception_uuid = ? AND version = ? AND status = 'in_supervisor_custody'")
                ->execute([$version, $now, $receiver, $now, $exceptionUuid, $expected]);
            $this->event($exceptionUuid, 'return_requested', $actor->employeeUuid, $version, ['receiver' => $receiver, 'responsible' => $responsible, 'substitute' => $receiver !== $responsible, 'comment' => $comment], $requestId, $now);
            $payload = $this->livePayload($exceptionUuid, $order, 'awaiting_acknowledgment', $version);
            $this->live->toEmployee($receiver, 'exception.acknowledgment_required', $payload, $now);
            $this->notify($exception, 'exception.updated', $payload, $now, $receiver === $responsible ? [$actor->employeeUuid] : [$actor->employeeUuid, $responsible], [$receiver]);
            $this->live->toApprovers('exception.waiting_worker', $payload, $now);
            $response = $this->queries->detail($exceptionUuid, $actor->employeeUuid, false, true);
            $this->idempotency->store($actor->employeeUuid, $key, 'defect.return', $exceptionUuid, $hash, $response, $now);
            return $response;
        });
    }

    /**
     * Supervisor ends the case inside tailoring, first half of a two-party handover: the supervisor holds the
     * product (QR scan) and addresses it to the tailoring employee who must receive it back. The order stays
     * blocked at stage 3 without a production owner until that employee scans the QR and confirms receipt
     * (receiveRestore). The recipient is the owner at detection while they can still work at the detected stage;
     * only when they cannot does the supervisor name an eligible employee of that stage. Allowed while the case
     * is in custody, before the cutting return is received, after the managers rejected the return, and again
     * while the handover is pending (to address another recipient). No manager decision is involved.
     * @param array<string, mixed> $input @return array<string, mixed>
     */
    public function restore(EmployeeIdentity $actor, string $exceptionId, array $input, string $key, string $requestId): array
    {
        $this->authorization->requireApplication($actor, 'staff');
        IdempotencyStore::requireKey($key);
        $exceptionUuid = $this->uuid($exceptionId);
        $expected = $this->positiveInt($input['expectedVersion'] ?? null, 'expectedVersion');
        $reference = self::scannedCode($input['qrToken'] ?? null);
        $comment = $this->comment($input['comment'] ?? null, true);
        $recipientId = $this->optionalEmployeeId($input['recipientEmployeeId'] ?? null, 'recipientEmployeeId');
        // Restoring while the cutting employee has not yet received the product withdraws the return to cutting
        // (no manager decision will follow). That must be a deliberate choice, never a side effect of the screen.
        $cancelReturn = $input['cancelReturn'] ?? null;
        if ($cancelReturn !== null && $cancelReturn !== true) {
            throw new ApiException(400, 'INVALID_REQUEST', 'cancelReturn must be true when present.');
        }
        $hash = IdempotencyStore::hash('defect.restore', $exceptionUuid, $recipientId === null ? [$expected, $reference->value, $comment] : [$expected, $reference->value, $comment, $recipientId]);

        return $this->transaction(function () use ($actor, $exceptionUuid, $expected, $reference, $comment, $recipientId, $cancelReturn, $key, $hash, $requestId): array {
            [$order, $exception, $carrier] = $this->lockException($exceptionUuid);
            $actor = $this->lockActor($actor, $order, 'staff');
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'defect.restore', $exceptionUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $this->requireSupervisor($actor, $exception);
            $this->assertStatus($exception, ['in_supervisor_custody', 'awaiting_acknowledgment', 'return_rejected', 'awaiting_restore_receipt'], $expected);
            if ($exception['status'] === 'awaiting_acknowledgment' && $cancelReturn !== true) {
                throw new ApiException(422, 'RETURN_CANCEL_CONFIRMATION_REQUIRED', 'Produsul este trimis la tăiere și așteaptă primirea. Confirmă că anulezi trimiterea la tăiere înainte de a-l preda înapoi la croitorie.');
            }
            if ($exception['status'] === 'awaiting_restore_receipt' && $exception['cancelled_by_employee_uuid'] !== null) {
                // A root recovery is under way; only root addresses it again.
                throw new ApiException(409, 'EXCEPTION_STATE_INVALID', 'The request is not in a state that allows this action.', ['status' => $exception['status']]);
            }
            $this->assertOrderOpenForProduction($order);
            $this->assertHeldAtCustodyStage($carrier, $exceptionUuid);
            $now = $this->now();
            $this->verifyQr($order, $exception, $reference, $now);
            $recipient = $this->restoreRecipient($exception, $recipientId, $actor->employeeUuid);
            $version = $expected + 1;
            $previousCustodian = $exception['physical_custodian_employee_uuid'];
            $previousRecipient = $exception['restore_recipient_employee_uuid'];
            $this->pdo->prepare("UPDATE production_exceptions SET status = 'awaiting_restore_receipt', version = ?, physical_custodian_employee_uuid = ?, restore_recipient_employee_uuid = ?, restore_requested_at = ?, updated_at = ?
                 WHERE exception_uuid = ? AND version = ?")
                ->execute([$version, $actor->employeeUuid, $recipient, $now, $now, $exceptionUuid, $expected]);
            $this->event($exceptionUuid, 'restore_requested', $actor->employeeUuid, $version, [
                'comment' => $comment, 'qrVerified' => true, 'statusBefore' => $exception['status'], 'targetStage' => $exception['detected_stage_id'],
                'recipient' => $recipient, 'originalOwner' => $exception['detected_owner_employee_uuid'], 'substitute' => $recipient !== $exception['detected_owner_employee_uuid'],
                'previousRecipient' => $previousRecipient, 'physicalCustodian' => ['before' => $previousCustodian, 'after' => $actor->employeeUuid], 'orderBlocked' => true,
            ] + ($exception['status'] === 'awaiting_acknowledgment' ? ['returnCancelled' => true, 'cancelledReceiver' => $exception['receiver_employee_uuid']] : []), $requestId, $now);
            $payload = $this->livePayload($exceptionUuid, $order, 'awaiting_restore_receipt', $version);
            $this->live->toEmployee($recipient, 'exception.restore_receipt_required', $payload, $now);
            $extra = [$actor->employeeUuid, ...($exception['return_requested_at'] !== null ? [(string) $exception['responsible_employee_uuid']] : []), ...(is_string($previousRecipient) ? [$previousRecipient] : [])];
            $this->notify($exception, 'exception.updated', $payload, $now, $extra, [$recipient]);
            if ($exception['status'] === 'awaiting_acknowledgment') {
                $this->live->toApprovers('exception.resolved', $payload, $now);
            }
            $response = $this->queries->detail($exceptionUuid, $actor->employeeUuid, false, true);
            $this->idempotency->store($actor->employeeUuid, $key, 'defect.restore', $exceptionUuid, $hash, $response, $now);
            return $response;
        });
    }

    /**
     * Second half of the handover: the addressed tailoring employee physically received the product, scanned its
     * QR and confirms. Only now, in one transaction, the order returns to the detected stage, owned by this
     * employee, and is unblocked. The order is never released unowned to a stage queue. A supervisor restoration
     * ends as `restored`, a root recovery as `cancelled`. No manager decision is involved.
     * @param array<string, mixed> $input @return array<string, mixed>
     */
    public function receiveRestore(EmployeeIdentity $actor, string $exceptionId, array $input, string $key, string $requestId): array
    {
        $this->authorization->requireApplication($actor, 'staff');
        IdempotencyStore::requireKey($key);
        $exceptionUuid = $this->uuid($exceptionId);
        $expected = $this->positiveInt($input['expectedVersion'] ?? null, 'expectedVersion');
        if (($input['confirmed'] ?? null) !== true) {
            throw new ApiException(400, 'CONFIRMATION_REQUIRED', 'The receipt must be confirmed explicitly.');
        }
        $reference = self::scannedCode($input['qrToken'] ?? null);
        $comment = $this->comment($input['comment'] ?? null, false);
        $workflow = $this->workflow();
        $hash = IdempotencyStore::hash('defect.receive', $exceptionUuid, [$expected, $reference->value, $comment]);

        return $this->transaction(function () use ($actor, $exceptionUuid, $expected, $reference, $comment, $workflow, $key, $hash, $requestId): array {
            [$order, $exception, $carrier] = $this->lockException($exceptionUuid);
            $actor = $this->lockActor($actor, $order, 'staff');
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'defect.receive', $exceptionUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            if ($exception['exception_type'] !== self::TYPE_TAILORING || $exception['restore_recipient_employee_uuid'] !== $actor->employeeUuid) {
                throw new ApiException(403, 'EXCEPTION_NOT_ASSIGNED', 'This request is not addressed to you.');
            }
            $this->assertStatus($exception, ['awaiting_restore_receipt'], $expected);
            $this->assertOrderOpenForProduction($order);
            $this->assertHeldAtCustodyStage($carrier, $exceptionUuid);
            $now = $this->now();
            $this->verifyQr($order, $exception, $reference, $now);
            $target = (string) $exception['detected_stage_id'];
            // Re-checked with the employee row locked: a deactivation or a removed stage committed meanwhile
            // leaves the order blocked, waiting for a new recipient.
            if (!$this->eligibleOwnerAt($actor->employeeUuid, $target, (string) $order['source_key'])) {
                throw new ApiException(409, 'RESTORE_RECIPIENT_UNAVAILABLE', 'You cannot take this order at its stage now.');
            }
            $outcome = $exception['cancelled_by_employee_uuid'] === null ? 'restored' : 'cancelled';
            $version = $expected + 1;
            $orderUuid = (string) $order['order_uuid'];
            $productionVersion = (int) $carrier['production_version'];
            $previousCustodian = $exception['physical_custodian_employee_uuid'];
            $this->pdo->prepare("UPDATE production_exceptions SET status = ?, version = ?, physical_custodian_employee_uuid = ?, restore_received_at = ?, resolved_at = ?, updated_at = ?
                 WHERE exception_uuid = ? AND version = ? AND status = 'awaiting_restore_receipt'")
                ->execute([$outcome, $version, $actor->employeeUuid, $now, $now, $now, $exceptionUuid, $expected]);
            $this->updateCarrier($orderUuid, $carrier, [
                'production_stage_id' => $target,
                'production_owner_employee_uuid' => $actor->employeeUuid,
                'production_claimed_at' => $now,
                'production_changed_at' => $now,
                'open_exception_uuid' => null,
            ], $now, "production_stage_id = '" . self::DETECTION_STAGE . "' AND production_owner_employee_uuid IS NULL AND open_exception_uuid = " . $this->pdo->quote($exceptionUuid));
            $this->pdo->prepare(
                "INSERT INTO employee_order_relations (employee_uuid, order_uuid, relation_type, status, started_at, last_action_at, updated_at)
                 VALUES (?, ?, 'assigned', 'active', ?, ?, ?)
                 ON DUPLICATE KEY UPDATE relation_type = VALUES(relation_type), status = 'active', last_action_at = VALUES(last_action_at), updated_at = VALUES(updated_at)",
            )->execute([$actor->employeeUuid, $orderUuid, $now, $now, $now]);
            $this->activity($order, $carrier, $workflow, 'defect_restored', $actor->employeeUuid, $this->stage($workflow, self::DETECTION_STAGE), $this->stage($workflow, $target), null, $actor->employeeUuid, $productionVersion, null, $key, $requestId, $now, $exceptionUuid);
            // The pieces came back without any rework: when the rest of the order is exactly there (same stage, same
            // owner), they travel with it again; otherwise they continue on their own track.
            $carrier = ['production_stage_id' => $target, 'production_owner_employee_uuid' => $actor->employeeUuid, 'production_version' => $productionVersion + 1] + $carrier;
            $rejoined = $this->rejoinIfAligned($order, $carrier, 'restored', $actor->employeeUuid, $key, $requestId, $now);
            (new \Arasya\Operations\Production\PieceCustodyReceipt($this->pdo))->forException($exceptionUuid, $actor->employeeUuid, $target, $requestId, $key, $now);
            $this->event($exceptionUuid, 'restore_received', $actor->employeeUuid, $version, [
                'qrVerified' => true, 'qrReference' => $reference->value, 'comment' => $comment, 'outcome' => $outcome,
                'transition' => ['from' => self::DETECTION_STAGE, 'to' => $target], 'owner' => $actor->employeeUuid,
                'physicalCustodian' => ['before' => $previousCustodian, 'after' => $actor->employeeUuid],
                'productionVersion' => ['before' => $productionVersion, 'after' => $productionVersion + 1],
            ] + ($carrier['track_uuid'] === null ? [] : ['trackId' => $carrier['track_uuid'], 'rejoinedOrder' => $rejoined]), $requestId, $now);
            $payload = $this->livePayload($exceptionUuid, $order, $outcome, $version);
            $this->notify($exception, 'exception.restored', $payload, $now, [$actor->employeeUuid, ...(is_string($previousCustodian) ? [$previousCustodian] : []), ...($exception['return_requested_at'] !== null ? [(string) $exception['responsible_employee_uuid']] : [])]);
            if ($outcome === 'cancelled') {
                $this->live->toApprovers('exception.resolved', $payload, $now);
            }
            $response = $this->queries->detail($exceptionUuid, $actor->employeeUuid, false);
            $this->idempotency->store($actor->employeeUuid, $key, 'defect.receive', $exceptionUuid, $hash, $response, $now);
            return $response;
        });
    }

    /** Tailoring employees who may receive the product back when the owner at detection cannot. @return list<array{id: string, displayName: string}> */
    public function restoreRecipients(EmployeeIdentity $actor, string $exceptionId, bool $root = false): array
    {
        $this->authorization->requireApplication($actor, $root ? 'dashboard' : 'staff');
        $exception = $this->queries->raw($this->uuid($exceptionId)) ?? throw new ApiException(404, 'EXCEPTION_NOT_FOUND', 'The request was not found.');
        if ($root ? !$actor->isRoot : !$this->supervisorFor($actor, $exception)) {
            throw new ApiException(404, 'EXCEPTION_NOT_FOUND', 'The request was not found.');
        }
        if ($exception['exception_type'] !== self::TYPE_TAILORING) {
            return [];
        }
        $stage = (string) $exception['detected_stage_id'];
        $source = (string) $exception['source_key'];
        $original = $this->employees->findByUuid((string) $exception['detected_owner_employee_uuid']);
        if ($original !== null && OrderOwnershipService::isEligibleOwner($original, $stage, $source)) {
            return [['id' => $original->employeeUuid, 'displayName' => $original->displayName, 'original' => true]];
        }
        $handingOver = $root ? $exception['physical_custodian_employee_uuid'] : $actor->employeeUuid;
        $candidates = $this->pdo->prepare("SELECT e.employee_uuid FROM employees e INNER JOIN employee_stage_access s ON s.employee_uuid = e.employee_uuid AND s.stage_id = ? WHERE e.status = 'active' ORDER BY e.display_name, e.employee_uuid LIMIT 200");
        $candidates->execute([$stage]);
        $items = [];
        foreach ($candidates->fetchAll(PDO::FETCH_COLUMN) as $uuid) {
            $employee = $uuid === $handingOver ? null : $this->employees->findByUuid((string) $uuid);
            if ($employee !== null && OrderOwnershipService::isEligibleOwner($employee, $stage, $source)) {
                $items[] = ['id' => $employee->employeeUuid, 'displayName' => $employee->displayName, 'original' => false];
            }
        }
        return $items;
    }

    /** Additional information on an open supervised case by anyone involved; never changes the state. @param array<string, mixed> $input @return array<string, mixed> */
    public function addNote(EmployeeIdentity $actor, string $exceptionId, array $input, string $key, string $requestId): array
    {
        $this->authorization->requireApplication($actor, 'staff');
        IdempotencyStore::requireKey($key);
        $exceptionUuid = $this->uuid($exceptionId);
        $expected = $this->positiveInt($input['expectedVersion'] ?? null, 'expectedVersion');
        $comment = $this->comment($input['comment'] ?? null, true);
        $hash = IdempotencyStore::hash('defect.note', $exceptionUuid, [$expected, $comment]);

        return $this->transaction(function () use ($actor, $exceptionUuid, $expected, $comment, $key, $hash, $requestId): array {
            [$order, $exception, $carrier] = $this->lockException($exceptionUuid);
            $actor = $this->lockActor($actor, $order, 'staff');
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'defect.note', $exceptionUuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            if ($exception['exception_type'] !== self::TYPE_TAILORING) {
                throw new ApiException(409, 'EXCEPTION_STATE_INVALID', 'The request is not in a state that allows this action.', ['status' => $exception['status']]);
            }
            $supervisor = $this->supervisorFor($actor, $exception);
            $visibleToResponsible = $exception['return_requested_at'] !== null && $exception['responsible_employee_uuid'] === $actor->employeeUuid;
            if (!$supervisor && !$visibleToResponsible && !in_array($actor->employeeUuid, [$exception['detector_employee_uuid'], $exception['receiver_employee_uuid'], $exception['restore_recipient_employee_uuid']], true)) {
                throw new ApiException(403, 'EXCEPTION_NOT_ASSIGNED', 'This request is not addressed to you.');
            }
            $this->assertStatus($exception, ExceptionQueries::OPEN_STATUSES, $expected);
            $now = $this->now();
            $version = $expected + 1;
            $this->pdo->prepare('UPDATE production_exceptions SET version = ?, updated_at = ? WHERE exception_uuid = ? AND version = ?')->execute([$version, $now, $exceptionUuid, $expected]);
            $this->event($exceptionUuid, 'note_added', $actor->employeeUuid, $version, ['comment' => $comment], $requestId, $now);
            $payload = $this->livePayload($exceptionUuid, $order, (string) $exception['status'], $version);
            $this->notify($exception, 'exception.updated', $payload, $now, [...($exception['return_requested_at'] !== null ? [(string) $exception['responsible_employee_uuid']] : []), ...(is_string($exception['restore_recipient_employee_uuid']) ? [$exception['restore_recipient_employee_uuid']] : [])]);
            $response = $this->queries->detail($exceptionUuid, $actor->employeeUuid, false, $supervisor);
            $this->idempotency->store($actor->employeeUuid, $key, 'defect.note', $exceptionUuid, $hash, $response, $now);
            return $response;
        });
    }

    /** Cutting employees the supervisor may address when the responsible one cannot receive the product. @return list<array{id: string, displayName: string}> */
    public function substituteReceivers(EmployeeIdentity $actor, string $exceptionId): array
    {
        $this->authorization->requireApplication($actor, 'staff');
        $exception = $this->queries->raw($this->uuid($exceptionId)) ?? throw new ApiException(404, 'EXCEPTION_NOT_FOUND', 'The request was not found.');
        if (!$this->supervisorFor($actor, $exception)) {
            throw new ApiException(404, 'EXCEPTION_NOT_FOUND', 'The request was not found.');
        }
        $source = (string) $exception['source_key'];
        if ($this->eligibleCutter((string) $exception['responsible_employee_uuid'], $source)) {
            return [];
        }
        $candidates = $this->pdo->prepare("SELECT e.employee_uuid FROM employees e INNER JOIN employee_stage_access s ON s.employee_uuid = e.employee_uuid AND s.stage_id = ? WHERE e.status = 'active' ORDER BY e.display_name, e.employee_uuid LIMIT 200");
        $candidates->execute([self::RETURN_STAGE]);
        $items = [];
        foreach ($candidates->fetchAll(PDO::FETCH_COLUMN) as $uuid) {
            if (in_array($uuid, [$exception['detector_employee_uuid'], $actor->employeeUuid], true) || !$this->eligibleCutter((string) $uuid, $source)) {
                continue;
            }
            $employee = $this->employees->findByUuid((string) $uuid);
            if ($employee !== null) {
                $items[] = ['id' => $employee->employeeUuid, 'displayName' => $employee->displayName];
            }
        }
        return $items;
    }

    // ---------------------------------------------------------------- helpers

    private function supervisors(): TailoringSupervisorPolicy
    {
        return new TailoringSupervisorPolicy($this->pdo, $this->clock);
    }

    /** Whether this actor (re-loaded) supervises the case's source now. @param array<string, mixed> $exception */
    private function supervisorFor(EmployeeIdentity $actor, array $exception): bool
    {
        if ($exception['exception_type'] !== self::TYPE_TAILORING) {
            return false;
        }
        $fresh = $this->employees->findByUuid($actor->employeeUuid);
        return $fresh !== null && $this->supervisors()->supervises($fresh, (string) $exception['source_key']);
    }

    /**
     * Re-checks the supervisor appointment, Staff access and stage-3 source scope inside the transaction,
     * with the employee row locked, so a revocation or deactivation committed meanwhile is respected.
     * @param array<string, mixed> $exception
     */
    private function requireSupervisor(EmployeeIdentity $actor, array $exception): void
    {
        if ($exception['exception_type'] !== self::TYPE_TAILORING) {
            throw new ApiException(409, 'EXCEPTION_STATE_INVALID', 'The request is not in a state that allows this action.', ['status' => $exception['status']]);
        }
        $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$actor->employeeUuid]);
        if (!$this->supervisorFor($actor, $exception)) {
            throw new ApiException(403, 'SUPERVISOR_REQUIRED', 'Only the appointed tailoring quality supervisor can do this.');
        }
    }

    /** Whether the employee can take cutting work for this source now (same rules as cutting claims). */
    private function eligibleCutter(string $employeeUuid, string $sourceKey): bool
    {
        $employee = $this->employees->findByUuid($employeeUuid);
        return $employee !== null
            && \Arasya\Operations\Cutting\CuttingLifecycle::eligible($employee)
            && OrderOwnershipService::isEligibleOwner($employee, self::RETURN_STAGE, $sourceKey);
    }

    private function requireEligibleCutter(string $employeeUuid, string $sourceKey, int $status, string $code): string
    {
        $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$employeeUuid]);
        if (!$this->eligibleCutter($employeeUuid, $sourceKey)) {
            throw new ApiException($status, $code, 'This cutting employee cannot take the product now.');
        }
        return $employeeUuid;
    }

    /** Whether the employee can own the order at this stage for this source now; the employee row is locked first. */
    private function eligibleOwnerAt(string $employeeUuid, string $stageId, string $sourceKey): bool
    {
        $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$employeeUuid]);
        $employee = $this->employees->findByUuid($employeeUuid);
        return $employee !== null && OrderOwnershipService::isEligibleOwner($employee, $stageId, $sourceKey);
    }

    /**
     * The tailoring employee who receives the product back: the owner at detection while they can still work at
     * the detected stage; otherwise an eligible employee of that stage named explicitly. Never the person who
     * hands the product over, so the handover always involves two people. @param array<string, mixed> $exception
     */
    private function restoreRecipient(array $exception, ?string $requested, ?string $handingOver): string
    {
        $stage = (string) $exception['detected_stage_id'];
        $source = (string) $exception['source_key'];
        $original = (string) $exception['detected_owner_employee_uuid'];
        $originalAvailable = $original !== $handingOver && $this->eligibleOwnerAt($original, $stage, $source);
        if ($requested === null || $requested === $original) {
            if (!$originalAvailable) {
                throw new ApiException(409, 'RESTORE_RECIPIENT_UNAVAILABLE', 'The employee who had the order cannot receive it now. Choose an available employee of that stage.');
            }
            return $original;
        }
        if ($originalAvailable) {
            throw new ApiException(422, 'RECIPIENT_NOT_ALLOWED', 'The employee who had the order is available and receives it.');
        }
        if ($requested === $handingOver || !$this->eligibleOwnerAt($requested, $stage, $source)) {
            throw new ApiException(422, 'RECIPIENT_INVALID', 'Choose an available employee of the stage where the defect was found.');
        }
        return $requested;
    }

    /** A blocked supervised case: the carrier (order or track) waits at stage 3 without a production owner. @param array<string, mixed> $order */
    private function assertHeldAtCustodyStage(array $order, string $exceptionUuid): void
    {
        if ($order['production_stage_id'] !== self::DETECTION_STAGE || $order['production_owner_employee_uuid'] !== null || $order['open_exception_uuid'] !== $exceptionUuid) {
            throw new ApiException(409, 'ORDER_CHANGED', 'The order changed. Reload it before continuing.');
        }
    }

    private function optionalEmployeeId(mixed $value, string $field): ?string
    {
        if ($value !== null && (!is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) !== 1)) {
            throw new ApiException(400, 'INVALID_REQUEST', "{$field} is invalid.");
        }
        return $value;
    }

    /**
     * Parses a scanned code: the order QR (ARASYA:Q1:) or the label of one piece (ARASYA:U1:). Anything else is not an
     * Arasya production code.
     */
    private static function scannedCode(mixed $token): QrReference|\Arasya\Operations\Order\UnitLabelReference
    {
        if (!is_string($token)) {
            throw new ApiException(400, 'QR_REQUIRED', 'The order QR code must be scanned.');
        }
        return QrReference::parsePayload($token) ?? \Arasya\Operations\Order\UnitLabelReference::parsePayload($token)
            ?? throw new ApiException(422, 'INVALID_QR', 'The scanned code is not an Arasya order code.');
    }

    /**
     * The scanned code must identify what is physically handed over: the active Arasya QR of this order (with a usable
     * production document), or the active label of a piece named in this request. A label of another piece, of a piece
     * outside the request or of another order fails closed.
     *
     * @param array<string, mixed> $order @param array<string, mixed> $exception
     */
    private function verifyQr(array $order, array $exception, QrReference|\Arasya\Operations\Order\UnitLabelReference|null $reference, string $now): void
    {
        if ($reference === null) {
            throw new ApiException(400, 'QR_REQUIRED', 'The order QR code must be scanned.');
        }
        \Arasya\Operations\Document\DocumentGuard::assertOpen($order);
        if ($reference instanceof \Arasya\Operations\Order\UnitLabelReference) {
            $label = $this->units()->resolveLabel($reference);
            if ($label === null || $label['status'] !== 'active') {
                throw new ApiException(422, 'INVALID_QR', 'The scanned piece label is not valid.');
            }
            if ($label['orderUuid'] !== $exception['order_uuid']) {
                throw new ApiException(422, 'QR_ORDER_MISMATCH', 'The scanned code belongs to a different order.');
            }
            $named = $this->pdo->prepare('SELECT 1 FROM production_exception_units WHERE exception_uuid = ? AND unit_uuid = ?');
            $named->execute([$exception['exception_uuid'], $label['unitUuid']]);
            if ($named->fetchColumn() === false) {
                throw new ApiException(422, 'UNIT_LABEL_MISMATCH', 'Eticheta scanată aparține altei bucăți.');
            }
            return;
        }
        \Arasya\Operations\Document\DocumentGuard::assertQrUsable($this->pdo, $reference->value);
        $qr = $this->pdo->prepare('SELECT order_uuid, status, expires_at FROM order_qr_references WHERE qr_reference = ?');
        $qr->execute([$reference->value]);
        $qrRow = $qr->fetch(PDO::FETCH_ASSOC);
        if (!is_array($qrRow) || $qrRow['status'] !== 'active' || ($qrRow['expires_at'] !== null && (string) $qrRow['expires_at'] <= $now)) {
            throw new ApiException(422, 'INVALID_QR', 'The scanned code is not a valid order code.');
        }
        if ($qrRow['order_uuid'] !== $exception['order_uuid']) {
            throw new ApiException(422, 'QR_ORDER_MISMATCH', 'The scanned code belongs to a different order.');
        }
    }

    private function reasonAttributable(string $reasonKey): bool
    {
        $statement = $this->pdo->prepare('SELECT cutting_attributable FROM production_fault_reasons WHERE reason_key = ?');
        $statement->execute([$reasonKey]);
        return (int) $statement->fetchColumn() === 1;
    }

    /** Everyone with a role in the case; none of them may decide it. @param array<string, mixed> $exception @return list<string> */
    public static function involved(array $exception): array
    {
        return array_values(array_filter([
            $exception['detector_employee_uuid'] ?? null,
            $exception['responsible_employee_uuid'] ?? null,
            $exception['supervisor_employee_uuid'] ?? null,
            $exception['receiver_employee_uuid'] ?? null,
            $exception['detected_owner_employee_uuid'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    /**
     * Live notice to the people of the case (detector, supervisor, receiver, plus extra), once each. The
     * responsible cutting employee is only told once the supervisor sent the return.
     *
     * @param array<string, mixed> $exception @param array<string, mixed> $payload @param list<string> $extra @param list<string> $skip
     */
    private function notify(array $exception, string $type, array $payload, string $now, array $extra, array $skip = []): void
    {
        $recipients = array_unique(array_filter([
            (string) $exception['detector_employee_uuid'],
            $exception['supervisor_employee_uuid'] ?? null,
            $exception['receiver_employee_uuid'] ?? null,
            ...$extra,
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));
        foreach (array_diff($recipients, $skip) as $recipient) {
            $this->live->toEmployee($recipient, $type, $payload, $now);
        }
    }

    /** @template T @param callable(): T $operation @return T */
    /** Current application/source authority, serialized with IAM writes after the order lock. */
    private function lockActor(EmployeeIdentity $actor, array $order, string $application): EmployeeIdentity
    {
        $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$actor->employeeUuid]);
        $fresh = $this->employees->findByUuid($actor->employeeUuid);
        if ($fresh === null || !$fresh->isOperationallyActive() || $fresh->mustChangePassword) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Contul nu mai este autorizat.');
        }
        $this->authorization->requireApplication($fresh, $application);
        if ($application === 'staff' && !$fresh->reachesSource((string) $order['source_key'])) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
        }
        return $fresh;
    }

    private function transaction(callable $operation): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            // lockException() first locates the order. Subsequent IAM, scope and decision reads must see commits
            // made while this request waited for that order, not a snapshot from the initial lookup.
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
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

    /**
     * Locks the order row, then the exception row, then the track the exception acts on, if any (the same order every
     * command uses). The third element is the carrier: the production state the exception moves. For an exception
     * on the order it is the order row itself; for an exception on a rework track it is the track, presented with the
     * same keys (stage, owner, open exception, version) so every rule below reads one shape.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
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
        $scope = $this->pdo->prepare('SELECT track_uuid FROM production_exception_scopes WHERE exception_uuid = ?');
        $scope->execute([$exceptionUuid]);
        $trackUuid = $scope->fetchColumn();
        if (!is_string($trackUuid)) {
            return [$order, $exception, self::orderCarrier($order)];
        }
        $track = $this->tracks()->lock($trackUuid) ?? throw new RuntimeException('The track of an exception is missing.');
        return [$order, $exception, self::trackCarrier($track)];
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private static function orderCarrier(array $order): array
    {
        return [
            'track_uuid' => null,
            'production_stage_id' => $order['production_stage_id'],
            'production_owner_employee_uuid' => $order['production_owner_employee_uuid'],
            'open_exception_uuid' => $order['open_exception_uuid'],
            'production_version' => (int) $order['production_version'],
            'rework_cycle' => null,
            'track_status' => null,
        ];
    }

    /** @param array<string, mixed> $track @return array<string, mixed> */
    private static function trackCarrier(array $track): array
    {
        return [
            'track_uuid' => (string) $track['track_uuid'],
            'production_stage_id' => (string) $track['stage_id'],
            'production_owner_employee_uuid' => $track['owner_employee_uuid'] === null ? null : (string) $track['owner_employee_uuid'],
            'open_exception_uuid' => $track['open_exception_uuid'] === null ? null : (string) $track['open_exception_uuid'],
            'production_version' => (int) $track['version'],
            'rework_cycle' => (int) $track['rework_cycle'],
            'track_status' => (string) $track['status'],
        ];
    }

    private function tracks(): \Arasya\Operations\Production\ProductionTracks
    {
        return new \Arasya\Operations\Production\ProductionTracks($this->pdo);
    }

    private function units(): \Arasya\Operations\Production\WorkUnits
    {
        return new \Arasya\Operations\Production\WorkUnits($this->pdo);
    }

    /**
     * updateOrder() for the carrier: the order row, or the track (same column meaning, track column names). Columns
     * only a track has may be passed in $trackOnly; they are ignored for an order carrier.
     *
     * @param array<string, mixed> $carrier @param array<string, string|null> $columns @param array<string, string|int|null> $trackOnly
     */
    private function updateCarrier(string $orderUuid, array $carrier, array $columns, string $now, string $guard, array $trackOnly = []): void
    {
        if ($carrier['track_uuid'] === null) {
            $this->updateOrder($orderUuid, (int) $carrier['production_version'], $columns, $now, $guard);
            return;
        }
        $map = ['production_stage_id' => 'stage_id', 'production_owner_employee_uuid' => 'owner_employee_uuid', 'production_claimed_at' => 'claimed_at', 'production_changed_at' => 'changed_at', 'open_exception_uuid' => 'open_exception_uuid'];
        $mapped = [];
        foreach ($columns as $column => $value) {
            $mapped[$map[$column] ?? throw new RuntimeException("Unknown carrier column {$column}.")] = $value;
        }
        $this->tracks()->update((string) $carrier['track_uuid'], (int) $carrier['production_version'], $mapped + $trackOnly, $now, strtr($guard, ['production_stage_id' => 'stage_id', 'production_owner_employee_uuid' => 'owner_employee_uuid']));
    }

    /**
     * After a report ended without rework (dismissed, cancelled before custody, product received back), a track rejoins
     * the order when it is exactly where the order is. @param array<string, mixed> $order @param array<string, mixed> $carrier
     */
    private function rejoinIfAligned(array $order, array $carrier, string $reason, string $actorUuid, string $key, string $requestId, string $now): bool
    {
        if ($carrier['track_uuid'] === null) {
            return false;
        }
        return $this->tracks()->mergeIfAligned((string) $carrier['track_uuid'], $order, $reason, $actorUuid, $key, $requestId, $now);
    }

    /** @return list<string> */
    private function exceptionUnitIds(string $exceptionUuid): array
    {
        $statement = $this->pdo->prepare('SELECT unit_uuid FROM production_exception_units WHERE exception_uuid = ? ORDER BY item_uuid, unit_index');
        $statement->execute([$exceptionUuid]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * An employee who handed a carrier on keeps the order relation while they still own another carrier of the same
     * order (the order itself or a track): their work list must not lose work they still hold.
     */
    private function deactivateRelationIfIdle(string $employeeUuid, string $orderUuid, string $now): void
    {
        $owns = $this->pdo->prepare("SELECT (SELECT COUNT(*) FROM operational_orders WHERE order_uuid = ? AND production_owner_employee_uuid = ?) + (SELECT COUNT(*) FROM production_tracks WHERE order_uuid = ? AND status = 'active' AND owner_employee_uuid = ?)");
        $owns->execute([$orderUuid, $employeeUuid, $orderUuid, $employeeUuid]);
        if ((int) $owns->fetchColumn() > 0) {
            return;
        }
        $this->pdo->prepare("UPDATE employee_order_relations SET status = 'inactive', updated_at = ? WHERE employee_uuid = ? AND order_uuid = ?")->execute([$now, $employeeUuid, $orderUuid]);
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
     * cutting result (also after a future transfer between cutting employees). For pieces on a rework track, a re-cut
     * on the track (or on a track it was split from) is the latest handoff; otherwise the order's own cutting handoff
     * applies, because those pieces were cut with the order. responsible_activity_event_id always references the
     * order's handoff (its foreign key); the track handoff, when it applies, is returned as track_event_uuid.
     *
     * @return array{employee_uuid: string, event_id: string, arrival: int, track_event_uuid: ?string}|null
     */
    private function responsibleHandoff(string $orderUuid, ?string $trackUuid = null): ?array
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
        $arrivals = (int) $count->fetchColumn();
        $result = ['employee_uuid' => (string) $row['employee_uuid'], 'event_id' => (string) $row['event_id'], 'arrival' => $arrivals, 'track_event_uuid' => null];
        if ($trackUuid === null) {
            return $result;
        }
        $lineage = $this->tracks()->lineage($trackUuid);
        $marks = implode(',', array_fill(0, count($lineage), '?'));
        $track = $this->pdo->prepare("SELECT event_uuid, employee_uuid FROM production_track_events WHERE track_uuid IN ({$marks}) AND action = 'stage_completed' AND from_stage_id = ? AND to_stage_id = ? ORDER BY occurred_at DESC, version_after DESC LIMIT 1");
        $track->execute([...$lineage, self::RETURN_STAGE, self::DETECTION_STAGE]);
        $recut = $track->fetch(PDO::FETCH_ASSOC);
        if (!is_array($recut)) {
            return $result;
        }
        $trackCount = $this->pdo->prepare("SELECT COUNT(*) FROM production_track_events WHERE track_uuid IN ({$marks}) AND action = 'stage_completed' AND from_stage_id = ? AND to_stage_id = ?");
        $trackCount->execute([...$lineage, self::RETURN_STAGE, self::DETECTION_STAGE]);
        return ['employee_uuid' => (string) $recut['employee_uuid'], 'event_id' => (string) $row['event_id'], 'arrival' => $arrivals + (int) $trackCount->fetchColumn(), 'track_event_uuid' => (string) $recut['event_uuid']];
    }

    /**
     * Exact decimal sum of the measured lines of a line report (whole lines: exactly each line's meters). Lines without
     * source meters are unmeasured and contribute nothing. @param list<array<string, mixed>> $lines
     */
    private static function scopeMeters(array $lines): string
    {
        $total = 0;
        foreach ($lines as $line) {
            if (self::measured($line['meters'])) {
                $total += \Arasya\Operations\Production\ManufacturingPlans::thousandths((string) $line['meters']);
            }
        }
        return \Arasya\Operations\Production\ManufacturingPlans::fromThousandths($total);
    }

    /** Physical pieces of the order (every carrier), from the materialized plan. */
    private function orderPieceCount(string $orderUuid): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM production_work_units WHERE order_uuid = ? AND status = 'active'");
        $statement->execute([$orderUuid]);
        return (int) $statement->fetchColumn();
    }

    /**
     * Exact decimal fabric of the named pieces from the confirmed plan. Every piece of a line: the line's meters (when the
     * source sent them). Some pieces: the sum of their planned fabric when every one is known. Otherwise the line is
     * unmeasured (it contributes nothing and is counted in unmeasured_line_count), never k/q of the line.
     *
     * @param list<array<string, mixed>> $lines @param list<array<string, mixed>> $selected @return array{0: string, 1: int}
     */
    private function scopeFabric(string $orderUuid, array $lines, array $selected): array
    {
        $plans = [];
        foreach ((new \Arasya\Operations\Production\ManufacturingPlans($this->pdo))->lines($orderUuid) as $line) {
            $plans[(string) $line['itemId']] = $line;
        }
        $total = 0;
        $unmeasured = 0;
        foreach ($lines as $line) {
            $plan = $plans[(string) $line['item_uuid']] ?? null;
            $slots = array_values(array_map(static fn (array $unit): int => (int) $unit['slot'], array_filter($selected, static fn (array $unit): bool => $unit['item_uuid'] === $line['item_uuid'])));
            $count = $plan === null ? 0 : (int) $plan['plan']['pieceCount'];
            if ($count > 0 && count($slots) === $count && self::measured($line['meters'])) {
                $total += \Arasya\Operations\Production\ManufacturingPlans::thousandths((string) $line['meters']);
                continue;
            }
            $fabric = [];
            foreach ($plan['plan']['pieces'] ?? [] as $piece) {
                $fabric[(int) $piece['index']] = $piece['fabricMeters'];
            }
            $sum = 0;
            foreach ($slots as $slot) {
                if (($fabric[$slot] ?? null) === null) {
                    $unmeasured++;
                    continue 2;
                }
                $sum += \Arasya\Operations\Production\ManufacturingPlans::thousandths((string) $fabric[$slot]);
            }
            $total += $sum;
        }
        return [\Arasya\Operations\Production\ManufacturingPlans::fromThousandths($total), $unmeasured];
    }

    /**
     * The earlier request an administrative recovery refers to: same order, a verified tailoring defect (the supervisor
     * accepted it) that ended without any rework (restored or cancelled), not yet recovered.
     * @return array<string, mixed>
     */
    private function recoveredException(string $orderUuid, string $exceptionUuid, array $selected, array $itemIds): array
    {
        $statement = $this->pdo->prepare('SELECT exception_uuid, order_uuid, exception_type, status, supervisor_reviewed_at, rework_cycle FROM production_exceptions WHERE exception_uuid = ? FOR UPDATE');
        $statement->execute([$exceptionUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['order_uuid'] !== $orderUuid) {
            throw new ApiException(422, 'RECOVERY_TARGET_INVALID', 'Cererea recuperată trebuie să aparțină acestei comenzi.');
        }
        if ($row['exception_type'] !== self::TYPE_TAILORING || $row['supervisor_reviewed_at'] === null || $row['rework_cycle'] !== null || !in_array($row['status'], ['restored', 'cancelled'], true)) {
            throw new ApiException(422, 'RECOVERY_TARGET_INVALID', 'Poate fi recuperat numai un defect verificat, restituit sau anulat, fără refacere.');
        }
        if ($selected === []) throw new ApiException(422, 'EXACT_PIECES_REQUIRED', 'Recuperarea incidentului necesită selectarea exactă a bucăților verificate.');
        $lines = $this->pdo->prepare('SELECT item_uuid FROM production_exception_lines WHERE exception_uuid = ?');
        $lines->execute([$exceptionUuid]);
        $oldUnits = $this->pdo->prepare('SELECT unit_uuid FROM production_exception_units WHERE exception_uuid = ?');
        $oldUnits->execute([$exceptionUuid]);
        $originalUnits = $oldUnits->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($itemIds, $lines->fetchAll(PDO::FETCH_COLUMN)) !== []
            || ($originalUnits !== [] && array_diff(array_column($selected, 'unit_uuid'), $originalUnits) !== [])) {
            throw new ApiException(422, 'RECOVERY_TARGET_INVALID', 'Bucățile recuperate trebuie să aparțină incidentului fizic original.');
        }
        if ($this->recoveryOrigin($exceptionUuid) !== null) throw new ApiException(409, 'RECOVERY_ALREADY_EXISTS', 'Incidentul are deja o recuperare administrativă.');
        $linked = $this->pdo->prepare("SELECT 1 FROM production_exception_links WHERE linked_exception_uuid = ? AND link_type = 'administrative_recovery'");
        $linked->execute([$exceptionUuid]);
        if ($linked->fetchColumn() !== false) {
            throw new ApiException(409, 'RECOVERY_ALREADY_EXISTS', 'Această cerere are deja o recuperare administrativă.');
        }
        return $row;
    }

    private function recoveryOrigin(string $exceptionUuid): ?string
    {
        $statement = $this->pdo->prepare("SELECT linked_exception_uuid FROM production_exception_links WHERE exception_uuid = ? AND link_type = 'administrative_recovery'");
        $statement->execute([$exceptionUuid]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    /** @return list<string> */
    private function unitIds(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > self::MAX_LINES * 10) {
            throw new ApiException(400, 'INVALID_REQUEST', 'units must list the defective pieces.');
        }
        foreach ($value as $id) {
            if (!\Arasya\Operations\Production\WorkUnits::isUuid($id)) {
                throw new ApiException(400, 'INVALID_REQUEST', 'units must list the defective pieces.');
            }
        }
        if (count(array_unique($value)) !== count($value)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'A piece can be selected only once.');
        }
        $ids = $value;
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @param list<string> $itemIds @return list<array<string, mixed>> */
    private function faultLines(string $orderUuid, array $itemIds, bool $requireMeters = true): array
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
            if ($requireMeters && !self::measured($line['meters'])) {
                throw new ApiException(422, 'FAULT_LINE_WITHOUT_METERS', 'A selected line has no meters, so the fault scope cannot be measured.');
            }
        }
        return $lines;
    }

    /** A source line is measured only when it carries positive meters; NULL or zero means the source sent none. */
    private static function measured(mixed $meters): bool
    {
        return $meters !== null && preg_match('/^0*\.?0*$/', (string) $meters) !== 1;
    }

    /**
     * Meters written on an activity event of a request: the fault scope only when every line was measured, so a
     * partial sum is never shown as the scope of the request. @param array<string, mixed> $exception
     */
    private static function activityMeters(array $exception): ?string
    {
        return (int) ($exception['unmeasured_line_count'] ?? 0) === 0 ? (string) $exception['fault_meters'] : null;
    }

    /** Exact decimal sum of the measured lines, computed by the database: whole-line meters, never quantity x meters. @param list<string> $itemIds */
    private function sumMeters(string $orderUuid, array $itemIds): string
    {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $statement = $this->pdo->prepare("SELECT CAST(COALESCE(SUM(meters), 0) AS DECIMAL(12,3)) FROM operational_order_items WHERE order_uuid = ? AND item_uuid IN ({$placeholders})");
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

    /**
     * The immutable production event of a carrier change: order_activity_events for the order (unchanged meaning for
     * every existing report), production_track_events for a track (its own version sequence and rework cycle).
     *
     * @param array<string, mixed> $order @param array<string, mixed> $carrier
     */
    private function activity(array $order, array $carrier, ProductionWorkflow $workflow, string $action, string $actorUuid, ProductionStage $from, ?ProductionStage $to, ?string $previousOwner, ?string $newOwner, int $versionBefore, ?string $meters, string $key, string $requestId, string $now, ?string $exceptionUuid = null, ?array $metadata = null): void
    {
        if ($carrier['track_uuid'] !== null) {
            $this->tracks()->event((string) $carrier['track_uuid'], (string) $order['order_uuid'], $action, $actorUuid, $from->id, $to?->id, $previousOwner, $newOwner, $versionBefore,
                (int) $carrier['rework_cycle'], $exceptionUuid, (($metadata ?? []) + ($meters === null ? [] : ['meters' => $meters])) ?: null, $requestId, $key, $now);
            return;
        }
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
