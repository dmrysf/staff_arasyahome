<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Cutting\CuttingLifecycle;
use Arasya\Operations\Document\DocumentGuard;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\OrderAccessPolicy;
use Arasya\Operations\Order\QrReference;
use Arasya\Operations\Order\UnitLabelReference;
use Arasya\Operations\Quality\LiveEvents;
use Arasya\Operations\Support\Clock;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Ordinary production work on a rework track: the same claim and stage completion an order has, for exactly the
 * pieces on the track, through the canonical workflow, one stage at a time.
 *
 *   claim     an employee of the track's current stage (and the track's source) takes the unowned track; the scan
 *             of the piece label (ARASYA:U1:) of a piece on this track, or of the order QR together with the
 *             explicitly chosen track, is required, so the physical piece in hand is always the one recorded
 *   complete  the owner completes the current stage; the track moves to the next canonical stage without an owner.
 *             At the stage where the defect was found, the owner must also confirm that the defect is resolved.
 *             Arriving at packing, the track waits for the order and rejoins it there (ProductionTracks::mergeAtGate)
 *
 * No stage is ever skipped and no earlier completion is reused: each completion is a new versioned track event with
 * its own employee, time and rework cycle. Every command locks the order row first (the lock every production
 * mutation uses), then the employee row, then the track; replays a committed result for the same idempotency key;
 * checks the expected track version; and reports success only after commit.
 */
final readonly class ReworkTrackService
{
    public const ACTION_CLAIM = 'claim';
    public const ACTION_COMPLETE = 'complete_stage';
    public const BLOCKED_WAITING_FOR_ORDER = 'waiting_for_order';
    private const MAX_ATTEMPTS = 3;
    private const RETRYABLE_MYSQL_ERRORS = [1205, 1213];

    public function __construct(
        private PDO $pdo,
        private ProductionWorkflowService $workflows,
        private AuthorizationService $authorization,
        private Clock $clock,
        private LiveEvents $live,
        private ?ProductionAuthorityModes $modes = null,
    ) {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function claim(EmployeeIdentity $actor, string $trackId, array $input, string $key, string $requestId): array
    {
        $this->authorization->require($actor, 'orders.claim');
        $this->requireKey($key);
        $trackUuid = $this->uuid($trackId);
        $expected = $this->positiveInt($input['expectedVersion'] ?? null);
        $token = is_string($input['qrToken'] ?? null) ? trim($input['qrToken']) : throw new ApiException(400, 'QR_REQUIRED', 'Scan the label of the piece or the order QR.');
        $hash = hash('sha256', implode('|', ['track.claim', $trackUuid, (string) $expected, strtoupper($token)]), true);
        $workflow = $this->workflow();
        return $this->transaction($actor, $trackUuid, $key, 'claim', $hash, function (EmployeeIdentity $employee, array $order, array $track, string $now) use ($expected, $token, $key, $requestId, $workflow): array {
            $this->requireVersion($track, $expected);
            $decision = $this->evaluate($employee, $track, $order);
            if ($decision['action'] === self::ACTION_COMPLETE) {
                return $this->view($employee, (string) $order['order_uuid'], (string) $track['track_uuid'], $workflow);
            }
            if ($decision['action'] !== self::ACTION_CLAIM) {
                throw self::blocked((string) $decision['blockedReason']);
            }
            $scanned = $this->verifyPieceQr($order, $track, $token);
            $this->tracks()->update((string) $track['track_uuid'], $expected, ['owner_employee_uuid' => $employee->employeeUuid, 'claimed_at' => $now], $now, 'owner_employee_uuid IS NULL AND open_exception_uuid IS NULL');
            $this->relation($employee->employeeUuid, (string) $order['order_uuid'], 'claimed', $now);
            $this->tracks()->event((string) $track['track_uuid'], (string) $order['order_uuid'], 'claimed', $employee->employeeUuid, (string) $track['stage_id'], null, null, $employee->employeeUuid,
                $expected, (int) $track['rework_cycle'], null, ['scanned' => $scanned], $requestId, $key, $now);
            $this->notify($order, $track, [$employee->employeeUuid], $now);
            return $this->view($employee, (string) $order['order_uuid'], (string) $track['track_uuid'], $workflow);
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function complete(EmployeeIdentity $actor, string $trackId, array $input, string $key, string $requestId): array
    {
        $this->authorization->require($actor, 'orders.advance_stage');
        $this->requireKey($key);
        $trackUuid = $this->uuid($trackId);
        $expected = $this->positiveInt($input['expectedVersion'] ?? null);
        $resolved = $input['defectResolved'] ?? null;
        if ($resolved !== null && $resolved !== true) {
            throw new ApiException(400, 'INVALID_REQUEST', 'defectResolved must be true when present.');
        }
        // API 2.32.0: completing the re-cut of a rework cycle declares whether the original pieces were repaired or replaced.
        $outcome = $input['pieceOutcome'] ?? null;
        if ($outcome !== null && !in_array($outcome, PieceReplacement::OUTCOMES, true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'pieceOutcome must be repair or replacement.');
        }
        $disposition = $input['originalDisposition'] ?? null;
        if ($disposition !== null && ($outcome !== PieceReplacement::REPLACEMENT || !in_array($disposition, PieceReplacement::DISPOSITIONS, true))) {
            throw new ApiException(400, 'INVALID_REQUEST', 'originalDisposition (scrapped or quarantined) is only accepted with a replacement.');
        }
        $pieceOutcomes = $input['pieceOutcomes'] ?? null;
        $hash = hash('sha256', implode('|', ['track.complete', $trackUuid, (string) $expected, $resolved === true ? 'resolved' : '']) . ($outcome === null ? '' : '|' . $outcome . '|' . ($disposition ?? '')) . ($pieceOutcomes === null ? '' : '|pieces|' . json_encode($pieceOutcomes, JSON_THROW_ON_ERROR)), true);
        $workflow = $this->workflow();
        return $this->transaction($actor, $trackUuid, $key, 'complete', $hash, function (EmployeeIdentity $employee, array $order, array $track, string $now) use ($expected, $resolved, $outcome, $disposition, $input, $key, $requestId, $workflow): array {
            $this->requireVersion($track, $expected);
            $decision = $this->evaluate($employee, $track, $order);
            if ($decision['action'] === self::ACTION_CLAIM) {
                throw new ApiException(409, 'INVALID_STAGE_TRANSITION', 'The piece must be claimed before its stage can be completed.');
            }
            if ($decision['action'] !== self::ACTION_COMPLETE) {
                throw self::blocked((string) $decision['blockedReason']);
            }
            $current = (string) $track['stage_id'];
            $orderUuid = (string) $order['order_uuid'];
            $replaced = [];
            $replacement = new PieceReplacement($this->pdo);
            $replaced = $replacement->applyOutcomes($orderUuid, (string) $track['track_uuid'], $track['verify_exception_uuid'] === null ? null : (string) $track['verify_exception_uuid'], $current, $input, $employee->employeeUuid, $requestId, $key, $now);
            // The central route policy chooses the next stage of exactly these pieces (the same rules as normal production).
            $routing = new PieceRouting($this->pdo);
            $route = $routing->plan($workflow, $orderUuid, (string) $track['track_uuid'], $current, $now);
            $next = $route['next'];
            if ($next === null) {
                throw new ApiException(409, 'INVALID_STAGE_TRANSITION', 'A piece in rework rejoins its order before the last stage.');
            }
            (new PieceVerification($this->pdo))->complete($workflow, $orderUuid, (string) $track['track_uuid'], $current, $resolved === true, $employee->employeeUuid, $expected, $requestId, $now);
            $columns = ['stage_id' => $next->id, 'owner_employee_uuid' => null, 'claimed_at' => null, 'changed_at' => $now];
            $verification = self::verificationDue($workflow, $track, $current);
            if ($verification) {
                if ($resolved !== true) {
                    throw new ApiException(422, 'DEFECT_RESOLUTION_REQUIRED', 'Confirmă că defectul inițial a fost remediat la această bucată.');
                }
                $columns += ['resolution_confirmed_at' => $now, 'resolution_confirmed_by_employee_uuid' => $employee->employeeUuid];
            }
            $this->tracks()->update((string) $track['track_uuid'], $expected, $columns, $now, 'owner_employee_uuid = ' . $this->pdo->quote($employee->employeeUuid) . ' AND open_exception_uuid IS NULL');
            $this->relation($employee->employeeUuid, $orderUuid, 'handover_out', $now);
            $this->tracks()->event((string) $track['track_uuid'], $orderUuid, 'stage_completed', $employee->employeeUuid, $current, $next->id, $employee->employeeUuid, null,
                $expected, (int) $track['rework_cycle'], $verification ? (string) $track['verify_exception_uuid'] : null,
                ($verification ? ['defectResolved' => true, 'verifiedStage' => $current] : [])
                + ($route['skipped'] === [] ? [] : ['notApplicableStages' => $route['skipped'], 'productTypes' => $route['types']])
                + (isset($input['pieceOutcomes']) ? ['pieceOutcomes' => $input['pieceOutcomes'], 'replacements' => $replaced] : [])
                + ($outcome === null || $current !== CuttingLifecycle::STAGE ? [] : ['pieceOutcome' => $outcome, 'replacements' => $replaced]) ?: null, $requestId, $key, $now);
            $routing->apply($workflow, $route, $orderUuid, $track, $current, $employee->employeeUuid, $expected, $key, $requestId, $now);
            if ($next->id === ProductionTracks::GATE_STAGE) {
                $this->tracks()->mergeAtGate($orderUuid, $employee->employeeUuid, $key, $requestId, $now);
            }
            $this->tracks()->mergeAligned($orderUuid, $employee->employeeUuid, $key, $requestId, $now);
            $this->notify($order, $track, [$employee->employeeUuid], $now);
            return $this->view($employee, (string) $order['order_uuid'], (string) $track['track_uuid'], $workflow);
        });
    }

    /**
     * Printable labels of the pieces on one track, for anyone who may work on the order (the supervisor prints them
     * when taking custody). @return array<string, mixed>
     */
    public function labels(EmployeeIdentity $actor, string $trackId): array
    {
        $this->authorization->requireApplication($actor, 'staff');
        $trackUuid = $this->uuid($trackId);
        $statement = $this->pdo->prepare(
            'SELECT t.track_uuid, t.track_number, t.order_uuid, t.status, t.rework_cycle, o.order_number, o.global_order_id, o.source_key FROM production_tracks t
             INNER JOIN operational_orders o ON o.order_uuid = t.order_uuid WHERE t.track_uuid = ?',
        );
        $statement->execute([$trackUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$actor->reachesSource((string) $row['source_key'])) {
            throw new ApiException(404, 'TRACK_NOT_FOUND', 'The piece was not found.');
        }
        $pieces = array_values(array_filter((new WorkUnits($this->pdo))->pieces((string) $row['order_uuid']), static fn (array $piece): bool => $piece['trackId'] === $trackUuid));
        $labels = (new WorkUnits($this->pdo))->activeLabels(array_column($pieces, 'id'));
        return [
            'trackId' => $trackUuid,
            'orderId' => (string) $row['global_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'reworkCycle' => (int) $row['rework_cycle'],
            'labels' => array_values(array_map(static fn (array $piece): array => [
                'unitId' => $piece['id'],
                'piece' => $piece['index'] . '/' . $piece['count'],
                'name' => $piece['name'],
                'code' => $piece['code'],
                'color' => $piece['color'],
                'payload' => $labels[$piece['id']] ?? null,
                'svg' => isset($labels[$piece['id']]) ? ProductionQrService::svg($labels[$piece['id']]) : null,
            ], $pieces)),
        ];
    }

    /**
     * Action for one employee on one track (same blocking rules as the order: source availability, document, open
     * defect, stage and source authorization, permissions). @param array<string, mixed> $track @param array<string, mixed> $order
     * @return array{action: string|null, blockedReason: string|null}
     */
    public function evaluate(EmployeeIdentity $employee, array $track, array $order): array
    {
        $blocked = static fn (string $reason): array => ['action' => null, 'blockedReason' => $reason];
        if ($track['status'] !== 'active') {
            return $blocked('track_merged');
        }
        if (($order['operational_status'] ?? null) === 'unavailable') {
            return $blocked('order_unavailable');
        }
        if (($order['production_completed_at'] ?? null) !== null) {
            return $blocked('production_completed');
        }
        if ($track['open_exception_uuid'] !== null) {
            return $blocked('exception_pending');
        }
        if (DocumentGuard::isBlocked(isset($order['document_status']) ? (string) $order['document_status'] : null)) {
            return $blocked(DocumentGuard::BLOCKED_REASON);
        }
        if (($order['production_authority'] ?? null) === ProductionAuthority::SOURCE && $this->modes?->modeFor((string) $order['source_key']) === ProductionAuthorityMode::Enforce) {
            return $blocked(OrderAccessPolicy::BLOCKED_AUTHORITY_SOURCE);
        }
        $pieceReason = (new PieceGate($this->pdo))->carrierReason((string) $order['order_uuid'], (string) $track['track_uuid']);
        if ($pieceReason !== null) return $blocked($pieceReason);
        if ($track['stage_id'] === ProductionTracks::GATE_STAGE) {
            return $blocked(self::BLOCKED_WAITING_FOR_ORDER);
        }
        $owner = $track['owner_employee_uuid'];
        if ($owner !== null && $owner !== $employee->employeeUuid) {
            return $blocked('claimed_by_other');
        }
        if (!$employee->worksAt((string) $track['stage_id'], (string) $order['source_key'])) {
            return $blocked('stage_not_allowed');
        }
        if ($owner === null) {
            $cutting = $track['stage_id'] === CuttingLifecycle::STAGE;
            return $this->authorization->can($employee, 'orders.claim') && (!$cutting || CuttingLifecycle::eligible($employee))
                ? ['action' => self::ACTION_CLAIM, 'blockedReason' => null] : $blocked('permission_missing');
        }
        return $this->authorization->can($employee, 'orders.advance_stage') ? ['action' => self::ACTION_COMPLETE, 'blockedReason' => null] : $blocked('permission_missing');
    }

    /**
     * The pieces block of several orders for one viewer: every physical piece with its label and carrier, the active
     * tracks with stage, owner, cycle and the viewer's action. @param list<array<string, mixed>> $orders order rows
     * (order_uuid, source_key, operational_status, production_completed_at, document_status, production_authority)
     * @return array<string, array<string, mixed>>
     */
    public function piecesFor(array $orders, ?EmployeeIdentity $viewer, ?ProductionWorkflow $workflow = null): array
    {
        $uuids = array_map(static fn (array $order): string => (string) $order['order_uuid'], $orders);
        $pieces = (new WorkUnits($this->pdo))->piecesFor($uuids);
        $plans = (new ManufacturingPlans($this->pdo))->linesFor($uuids);
        $features = new FeatureActivation($this->pdo);
        $tracks = $this->tracks()->activeFor($uuids);
        $result = [];
        foreach ($orders as $order) {
            $uuid = (string) $order['order_uuid'];
            $units = $pieces[$uuid] ?? [];
            $orderTracks = $tracks[$uuid] ?? [];
            $views = [];
            foreach ($orderTracks as $track) {
                $unitIds = array_values(array_map(static fn (array $unit): string => $unit['id'], array_filter($units, static fn (array $unit): bool => $unit['trackId'] === $track['track_uuid'])));
                $decision = $viewer === null ? null : $this->evaluate($viewer, $track, $order);
                $views[] = [
                    'id' => (string) $track['track_uuid'],
                    'number' => (int) $track['track_number'],
                    'stage' => ['id' => (string) $track['stage_id'], 'label' => $track['stage_label'] === null ? null : (string) $track['stage_label']],
                    'owner' => $track['owner_employee_uuid'] === null ? null : ['displayName' => (string) $track['owner_name'], 'isMe' => $viewer !== null && $track['owner_employee_uuid'] === $viewer->employeeUuid],
                    'version' => (int) $track['version'],
                    'reworkCycle' => (int) $track['rework_cycle'],
                    'reworked' => $track['verify_stage_id'] !== null,
                    'unitIds' => $unitIds,
                    'quantity' => count($unitIds),
                    'openException' => $track['open_exception_uuid'] === null ? null : ['id' => (string) $track['open_exception_uuid'], 'number' => sprintf('EX-%06d', (int) $track['open_exception_number']), 'status' => (string) $track['open_exception_status']],
                    'waitingForOrder' => $track['stage_id'] === ProductionTracks::GATE_STAGE,
                    'verifyStageId' => $track['verify_stage_id'] === null ? null : (string) $track['verify_stage_id'],
                    'resolutionConfirmed' => $track['resolution_confirmed_at'] !== null,
                    'requiresDefectResolution' => $workflow === null ? ($track['verify_stage_id'] !== null && $track['verify_stage_id'] === $track['stage_id'] && $track['resolution_confirmed_at'] === null) : self::verificationDue($workflow, $track, (string) $track['stage_id']),
                    // The next stage of these pieces under the central route policy (PERDEA after stage 5: ironing).
                    'nextStageId' => $workflow === null || $track['stage_id'] === ProductionTracks::GATE_STAGE ? null : (new PieceRouting($this->pdo))->plan($workflow, (string) $track['order_uuid'], (string) $track['track_uuid'], (string) $track['stage_id'], '')['next']?->id,
                    // API 2.32.0: a route track carries pieces of another product route (no defect); a rework track went through a defect.
                    'kind' => $track['origin_exception_uuid'] === null ? 'route' : 'rework',
                    'requiresPieceOutcome' => $track['stage_id'] === CuttingLifecycle::STAGE && (new PieceReplacement($this->pdo))->outcomeRequired((new WorkUnits($this->pdo))->carrierUnits((string) $track['order_uuid'], (string) $track['track_uuid']), CuttingLifecycle::STAGE),
                    'changedAt' => self::iso((string) $track['changed_at']),
                ] + ($decision === null ? [] : ['allowedAction' => $decision['action'] === null ? null : ['id' => $decision['action']], 'blockedReason' => $decision['blockedReason']]);
            }
            $onOrder = count(array_filter($units, static fn (array $unit): bool => $unit['trackId'] === null));
            $allPlansKnown = isset($plans[$uuid]) && array_filter($plans[$uuid], static fn (array $line): bool => !$line['known']) === [];
            $result[$uuid] = [
                'total' => $allPlansKnown ? count($units) : null,
                'allPlansKnown' => $allPlansKnown,
                'partialRework' => $features->appliesTo(FeatureActivation::PARTIAL_REWORK, $uuid),
                'pieceRouting' => $features->appliesTo(FeatureActivation::PIECE_ROUTING, $uuid),
                'onOrder' => $onOrder,
                'inRework' => count($units) - $onOrder,
                'mixedProgress' => $views !== [],
                'units' => array_map(static fn (array $unit): array => [
                    'id' => $unit['id'], 'itemId' => $unit['itemId'], 'index' => $unit['index'], 'count' => $unit['count'], 'lineNumber' => $unit['lineNumber'],
                    'trackId' => $unit['trackId'], 'reworkCycle' => $unit['reworkCycle'], 'reviewRequired' => $unit['reviewRequired'],
                    'productType' => $unit['productType'], 'fabricMeters' => $unit['fabricMeters'], 'replacementOf' => $unit['replacementOf'],
                    'defectResolution' => $unit['defectResolution'],
                ], $units),
                'requiresDefectResolution' => count(array_filter($units, static fn (array $unit): bool => $unit['trackId'] === null && $unit['defectResolution'] !== null && $unit['defectResolution']['confirmedAt'] === null && (CanonicalProductionWorkflowContract::STAGES[$order['production_stage_id']] ?? 0) >= (CanonicalProductionWorkflowContract::STAGES[$unit['defectResolution']['detectedStageId']] ?? PHP_INT_MAX))) > 0,
                'requiresPieceOutcome' => ($order['production_stage_id'] ?? null) === CuttingLifecycle::STAGE && (new PieceReplacement($this->pdo))->outcomeRequired((new WorkUnits($this->pdo))->carrierUnits($uuid, null), CuttingLifecycle::STAGE),
                'tracks' => $views,
            ];
        }
        return $result;
    }

    /** Order rows of the given orders in the shape piecesFor() and evaluate() need. @param list<string> $orderUuids @return list<array<string, mixed>> */
    public function orderRows(array $orderUuids): array
    {
        if ($orderUuids === []) {
            return [];
        }
        $statement = $this->pdo->prepare('SELECT order_uuid, global_order_id, order_number, source_key, operational_status, production_completed_at, document_status, production_authority,
                production_stage_id, production_owner_employee_uuid, open_exception_uuid FROM operational_orders WHERE order_uuid IN (' . implode(',', array_fill(0, count($orderUuids), '?')) . ')');
        $statement->execute(array_values(array_unique($orderUuids)));
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Active tracks at one stage, for the stage queues: the tracks of the given sources (null: every source).
     * @param list<string>|null $sources @return list<array<string, mixed>> rows with order_uuid and track fields
     */
    public function atStage(string $stageId, ?array $sources, int $limit): array
    {
        if ($sources === []) {
            return [];
        }
        try {
            $statement = $this->pdo->prepare("SELECT t.track_uuid, t.order_uuid, t.owner_employee_uuid FROM production_tracks t INNER JOIN operational_orders o ON o.order_uuid = t.order_uuid
                 WHERE t.status = 'active' AND t.stage_id = ? AND o.operational_status <> 'unavailable' AND o.production_completed_at IS NULL"
                . ($sources === null ? '' : ' AND o.source_key IN (' . implode(',', array_fill(0, count($sources), '?')) . ')') . ' ORDER BY t.changed_at, t.track_number LIMIT ' . max(1, min(250, $limit)));
            $statement->execute([$stageId, ...array_values($sources ?? [])]);
        } catch (PDOException) {
            return [];
        }
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Per-stage totals of active tracks for the given stage conditions (same source scoping as the order counts).
     * @param list<string> $stageIds @param array<string, list<string>|null> $sourcesByStage @return array<string, array{total: int, mine: int}>
     */
    public function countByStage(string $employeeUuid, array $stageIds, array $sourcesByStage): array
    {
        $counts = [];
        $conditions = [];
        $parameters = [$employeeUuid];
        foreach ($stageIds as $stageId) {
            $sources = $sourcesByStage[$stageId] ?? null;
            if ($sources === []) {
                continue;
            }
            $conditions[] = '(t.stage_id = ?' . ($sources === null ? '' : ' AND o.source_key IN (' . implode(',', array_fill(0, count($sources), '?')) . ')') . ')';
            array_push($parameters, $stageId, ...array_values($sources ?? []));
        }
        if ($conditions === []) {
            return $counts;
        }
        try {
            $statement = $this->pdo->prepare("SELECT t.stage_id, COUNT(*) AS total, COALESCE(SUM(t.owner_employee_uuid = ?), 0) AS mine FROM production_tracks t INNER JOIN operational_orders o ON o.order_uuid = t.order_uuid
                 WHERE t.status = 'active' AND o.operational_status <> 'unavailable' AND o.production_completed_at IS NULL AND (" . implode(' OR ', $conditions) . ') GROUP BY t.stage_id');
            $statement->execute($parameters);
        } catch (PDOException) {
            return $counts;
        }
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['stage_id']] = ['total' => (int) $row['total'], 'mine' => (int) $row['mine']];
        }
        return $counts;
    }

    /**
     * Whether completing $current must confirm that the original defect is resolved: the track carries a defect found
     * at verify_stage_id, not yet confirmed, and the track reached that stage (or, when the product route does not
     * apply that stage to these pieces, the first applicable stage after it). @param array<string, mixed> $track
     */
    public static function verificationDue(ProductionWorkflow $workflow, array $track, string $current): bool
    {
        if ($track['verify_stage_id'] === null || $track['resolution_confirmed_at'] !== null) {
            return false;
        }
        $ordinal = static function (string $id) use ($workflow): int {
            foreach ($workflow->stages as $stage) {
                if ($stage->id === $id) {
                    return $stage->ordinal;
                }
            }
            return PHP_INT_MAX;
        };
        return $ordinal($current) >= $ordinal((string) $track['verify_stage_id']);
    }

    public static function nextStage(ProductionWorkflow $workflow, string $stageId): ?ProductionStage
    {
        foreach ($workflow->stages as $index => $stage) {
            if ($stage->id === $stageId) {
                return $workflow->stages[$index + 1] ?? null;
            }
        }
        throw new ApiException(409, 'SOURCE_STAGE_UNKNOWN', 'The stage is not part of the active production workflow.');
    }

    public static function blocked(string $reason): ApiException
    {
        return match ($reason) {
            'claimed_by_other' => new ApiException(409, 'TRACK_ALREADY_CLAIMED', 'Another employee is working on this piece.'),
            'pieces_held' => new ApiException(409, 'ORDER_PIECES_HELD', 'Bucata este reținută fizic.'),
            'pieces_incomplete' => new ApiException(409, 'ORDER_PIECES_INCOMPLETE', 'Identitatea bucății trebuie verificată.'),
            'plan_confirmation_required' => new ApiException(409, 'PLAN_CONFIRMATION_REQUIRED', 'Planul de producție trebuie confirmat după modificarea sursei.'),
            'exception_pending' => new ApiException(409, 'ORDER_BLOCKED_BY_EXCEPTION', 'The piece is waiting for a production exception decision.'),
            self::BLOCKED_WAITING_FOR_ORDER => new ApiException(409, 'TRACK_WAITING_FOR_ORDER', 'Bucata așteaptă restul comenzii la ambalare.'),
            'order_unavailable' => new ApiException(409, 'ORDER_UNAVAILABLE', 'The order is not available for production.'),
            'production_completed', 'track_merged' => new ApiException(409, 'INVALID_STAGE_TRANSITION', 'The piece is no longer in rework.'),
            DocumentGuard::BLOCKED_REASON => DocumentGuard::blocked(),
            OrderAccessPolicy::BLOCKED_AUTHORITY_SOURCE => new ApiException(409, 'PRODUCTION_AUTHORITY_SOURCE', 'Production of this order is still managed by its source.'),
            default => new ApiException(403, 'UNAUTHORIZED_ACTION', 'You cannot work on this piece at its current stage.'),
        };
    }

    /**
     * The scanned code must identify the piece in hand: the active label of a piece on this track, or the active order
     * QR of this order (the track itself was chosen explicitly). Anything else fails closed. @param array<string, mixed> $order @param array<string, mixed> $track
     */
    private function verifyPieceQr(array $order, array $track, string $token): string
    {
        $label = UnitLabelReference::parsePayload($token);
        if ($label !== null) {
            $resolved = (new WorkUnits($this->pdo))->resolveLabel($label);
            if ($resolved === null || $resolved['status'] !== 'active') {
                throw new ApiException(422, 'INVALID_QR', 'The scanned piece label is not valid.');
            }
            if ($resolved['orderUuid'] !== $order['order_uuid'] || $resolved['trackUuid'] !== $track['track_uuid']) {
                throw new ApiException(422, 'UNIT_LABEL_MISMATCH', 'Eticheta scanată aparține altei bucăți.');
            }
            return 'piece_label';
        }
        $reference = QrReference::parsePayload($token) ?? throw new ApiException(422, 'INVALID_QR', 'The scanned code is not an Arasya code.');
        DocumentGuard::assertQrUsable($this->pdo, $reference->value);
        $statement = $this->pdo->prepare("SELECT order_uuid, expires_at FROM order_qr_references WHERE qr_reference = ? AND status = 'active'");
        $statement->execute([$reference->value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || ($row['expires_at'] !== null && (string) $row['expires_at'] <= $this->now())) {
            throw new ApiException(422, 'INVALID_QR', 'The scanned code is not a valid order code.');
        }
        if ($row['order_uuid'] !== $order['order_uuid']) {
            throw new ApiException(422, 'QR_ORDER_MISMATCH', 'The scanned code belongs to a different order.');
        }
        return 'order_qr';
    }

    /**
     * @param callable(EmployeeIdentity, array<string, mixed>, array<string, mixed>, string): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function transaction(EmployeeIdentity $actor, string $trackUuid, string $key, string $operation, string $hash, callable $operation_): array
    {
        for ($attempt = 1; ; $attempt++) {
            // orderOf() runs before the order lock. Do not retain a snapshot from before a competing commit.
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->pdo->beginTransaction();
            try {
                $orderUuid = $this->tracks()->orderOf($trackUuid) ?? throw new ApiException(404, 'TRACK_NOT_FOUND', 'The piece was not found.');
                $lock = $this->pdo->prepare('SELECT order_uuid, global_order_id, order_number, source_key, operational_status, production_completed_at, document_status, production_authority,
                        production_stage_id, production_owner_employee_uuid, open_exception_uuid, production_version FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
                $lock->execute([$orderUuid]);
                $order = $lock->fetch(PDO::FETCH_ASSOC);
                if (!is_array($order)) {
                    throw new ApiException(404, 'TRACK_NOT_FOUND', 'The piece was not found.');
                }
                // Authorization re-read under the employee lock: a revocation committed meanwhile applies here.
                $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$actor->employeeUuid]);
                $employee = (new PdoEmployeeRepository($this->pdo))->findByUuid($actor->employeeUuid) ?? throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Account is not active.');
                if (!$employee->isOperationallyActive() || !$employee->hasApplication('staff') || $employee->mustChangePassword) {
                    throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');
                }
                if (!$employee->reachesSource((string) $order['source_key'])) {
                    throw new ApiException(404, 'TRACK_NOT_FOUND', 'The piece was not found.');
                }
                $track = $this->tracks()->lock($trackUuid) ?? throw new ApiException(404, 'TRACK_NOT_FOUND', 'The piece was not found.');
                $replay = $this->replay($employee->employeeUuid, $key, $operation, $trackUuid, $hash);
                if ($replay !== null) {
                    $this->pdo->rollBack();
                    return $replay;
                }
                $now = $this->now();
                $response = $operation_($employee, $order, $track, $now);
                $this->store($employee->employeeUuid, $key, $operation, $trackUuid, $hash, $response, $now);
                $this->pdo->commit();
                return $response;
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

    /** @return array<string, mixed>|null */
    private function replay(string $employeeUuid, string $key, string $operation, string $trackUuid, string $hash): ?array
    {
        $statement = $this->pdo->prepare('SELECT operation, track_uuid, request_hash, response_json FROM production_track_idempotency WHERE employee_uuid = ? AND idempotency_key = ? FOR UPDATE');
        $statement->execute([$employeeUuid, $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ($row['operation'] !== $operation || $row['track_uuid'] !== $trackUuid || !hash_equals((string) $row['request_hash'], $hash)) {
            throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
        }
        try {
            $decoded = json_decode((string) $row['response_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Stored idempotent result is unreadable.');
        }
        return is_array($decoded) ? $decoded : throw new RuntimeException('Stored idempotent result is unreadable.');
    }

    /** @param array<string, mixed> $response */
    private function store(string $employeeUuid, string $key, string $operation, string $trackUuid, string $hash, array $response, string $now): void
    {
        $statement = $this->pdo->prepare('INSERT INTO production_track_idempotency (employee_uuid, idempotency_key, operation, track_uuid, request_hash, response_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->bindValue(1, $employeeUuid);
        $statement->bindValue(2, $key);
        $statement->bindValue(3, $operation);
        $statement->bindValue(4, $trackUuid);
        $statement->bindValue(5, $hash, PDO::PARAM_LOB);
        $statement->bindValue(6, json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $statement->bindValue(7, $now);
        $statement->execute();
    }

    /** The committed state of the order's pieces, as the viewer sees them. @return array<string, mixed> */
    private function view(EmployeeIdentity $employee, string $orderUuid, string $trackUuid, ProductionWorkflow $workflow): array
    {
        $rows = $this->orderRows([$orderUuid]);
        $pieces = $this->piecesFor($rows, $employee, $workflow)[$orderUuid] ?? [];
        $track = $this->tracks()->lock($trackUuid);
        return [
            'orderId' => (string) $rows[0]['global_order_id'],
            'trackId' => $trackUuid,
            'trackStatus' => $track === null ? null : (string) $track['status'],
            'stageId' => $track === null ? null : (string) $track['stage_id'],
            'pieces' => $pieces,
        ];
    }

    private function relation(string $employeeUuid, string $orderUuid, string $type, string $now): void
    {
        $this->pdo->prepare(
            "INSERT INTO employee_order_relations (employee_uuid, order_uuid, relation_type, status, started_at, last_action_at, updated_at)
             VALUES (?, ?, ?, 'active', ?, ?, ?)
             ON DUPLICATE KEY UPDATE relation_type = VALUES(relation_type), status = 'active', last_action_at = VALUES(last_action_at), updated_at = VALUES(updated_at)",
        )->execute([$employeeUuid, $orderUuid, $type, $now, $now, $now]);
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $track @param list<string> $recipients */
    private function notify(array $order, array $track, array $recipients, string $now): void
    {
        $payload = ['orderId' => (string) $order['global_order_id'], 'orderNumber' => (string) $order['order_number'], 'trackId' => (string) $track['track_uuid']];
        foreach (array_unique(array_filter($recipients)) as $recipient) {
            $this->live->toEmployee($recipient, 'production.track_updated', $payload, $now);
        }
    }

    /** @param array<string, mixed> $track */
    private function requireVersion(array $track, int $expected): void
    {
        if ((int) $track['version'] !== $expected) {
            throw new ApiException(409, 'TRACK_CHANGED', 'The piece in rework changed. Reload it before continuing.', ['version' => (int) $track['version']]);
        }
    }

    private function tracks(): ProductionTracks
    {
        return new ProductionTracks($this->pdo);
    }

    private function workflow(): ProductionWorkflow
    {
        try {
            return $this->workflows->current();
        } catch (RuntimeException) {
            throw new ApiException(503, 'WORKFLOW_UNAVAILABLE', 'Production workflow is not ready.');
        }
    }

    private function requireKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D', $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
    }

    private function uuid(string $value): string
    {
        return WorkUnits::isUuid($value) ? $value : throw new ApiException(404, 'TRACK_NOT_FOUND', 'The piece was not found.');
    }

    private function positiveInt(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : throw new ApiException(400, 'INVALID_REQUEST', 'expectedVersion must be a positive integer.');
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
