<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use Arasya\Operations\Auth\AuthenticatedSession;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Production\ProductionQrLedger;
use Arasya\Operations\Production\ProductionWorkflow;
use Arasya\Operations\Production\ProductionWorkflowService;
use Arasya\Operations\Quality\ExceptionQueries;
use Arasya\Operations\Security\ApiRateLimiter;
use Arasya\Operations\Security\CsrfGuard;
use RuntimeException;

final readonly class OperationalOrderController
{
    private const LOOKUP_LIMIT = 60;
    private const LOOKUP_WINDOW_SECONDS = 60;
    /** Open orders read per stage-queue request; counts beyond it are reported as incomplete, never guessed. */
    private const STAGE_QUEUE_SCAN = 200;
    private const STAGE_QUEUE_ITEMS = 30;

    public function __construct(
        private OperationalOrderRepository $repository,
        private OrderSerializer $serializer,
        private AuthenticationService $auth,
        private AuthorizationService $authorization,
        private Config $config,
        private RequestContext $context,
        private OrderAccessPolicy $policy,
        private ProductionWorkflowService $workflows,
        private OrderOperationsService $operations,
        private CsrfGuard $csrf,
        private ApiRateLimiter $rateLimiter,
        private ?ExceptionQueries $quality = null,
        private ?\Arasya\Operations\Document\DocumentQueries $documents = null,
        private ?\PDO $qrLedger = null,
        private ?\Arasya\Operations\Production\ProductionAuthorityModes $modes = null,
        private ?\Arasya\Operations\Production\ReworkTrackService $tracks = null,
    ) {
    }

    public function listMine(Request $request): Response
    {
        $employee = $this->authenticate($request)->employee;
        $this->authorization->require($employee, 'orders.view_mine');

        $limitParam = $request->query('limit', '50') ?? '50';
        if (!ctype_digit($limitParam) || (int) $limitParam < 1 || (int) $limitParam > 100) {
            throw new ApiException(400, 'INVALID_LIMIT', 'Limit must be an integer between 1 and 100.');
        }
        // A relation lists an order only while the employee still reaches its source (the same rule as canView): an
        // order of a source revoked by a narrowed scope or a removed stage never returns its operational details here.
        $sources = $employee->isOperationallyActive() ? $employee->reachableSources() : [];
        $result = $this->repository->listMine($employee->employeeUuid, (int) $limitParam, $request->query('cursor'), $sources);
        $workflow = $this->workflowOrNull();
        $visible = array_values(array_filter($result['items'], fn (OperationalOrder $order): bool => $this->policy->canView($employee, $order)));
        $pieces = $this->piecesOf($visible, $employee, $workflow);

        return Response::json([
            'items' => array_map(fn (OperationalOrder $order): array => $this->present($employee, $order, $workflow, $pieces[$order->orderUuid] ?? null), $visible),
            'nextCursor' => $result['nextCursor'],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Read-only queue of one production stage for the Staff department dashboards. The stage must be one of the
     * employee's allowed stages, a source-scoped grant reads only its sources; every order passes OrderAccessPolicy::canView (so Trendyol orders at the initial
     * stage stay limited to Trendyol personnel) and carries the same action evaluation as the order screen.
     * Nothing is claimed or changed here.
     */
    public function stageQueue(Request $request): Response
    {
        $employee = $this->authenticate($request)->employee;
        $this->authorization->require($employee, 'orders.view_mine');
        $stageId = $this->allowedStage($employee, $request->query('stage') ?? '');
        $workflow = $this->workflowOrNull();
        // A source-scoped grant is applied in the query itself, so other sources never reach the scan window.
        $rows = $this->repository->listOpenAtStage($employee->employeeUuid, $stageId, self::STAGE_QUEUE_SCAN + 1, $employee->sourcesAt($stageId));
        $complete = count($rows) <= self::STAGE_QUEUE_SCAN;
        $buckets = ['mine' => [], 'available' => [], 'blocked' => [], 'claimedByOthers' => []];
        foreach (array_slice($rows, 0, self::STAGE_QUEUE_SCAN) as $order) {
            if (!$this->policy->canView($employee, $order)) {
                continue;
            }
            $decision = $this->policy->evaluate($employee, $order, $workflow);
            $bucket = match (true) {
                $order->productionOwnerEmployeeUuid === $employee->employeeUuid => 'mine',
                $decision['action'] === OrderAccessPolicy::ACTION_CLAIM => 'available',
                $decision['blockedReason'] === 'claimed_by_other' => 'claimedByOthers',
                default => 'blocked',
            };
            $buckets[$bucket][] = [$order, $decision];
        }
        $total = $this->repository->countOpenByStage($employee->employeeUuid, [$stageId], $this->authorization->can($employee, OrderAccessPolicy::TRENDYOL_VIEW), $employee->stageSourceScopes)[$stageId]['total'] ?? 0;
        $items = array_slice(array_merge(...array_values($buckets)), 0, self::STAGE_QUEUE_ITEMS);
        $pieces = $this->piecesOf(array_map(static fn (array $entry): OperationalOrder => $entry[0], $items), $employee, $workflow);
        return Response::json([
            'stageId' => $stageId,
            'counts' => ['total' => $total] + array_map('count', $buckets),
            'countsComplete' => $complete,
            'items' => array_map(fn (array $entry): array => $this->serializer->serializeOrder($entry[0], $entry[1], pieces: $pieces[$entry[0]->orderUuid] ?? null), $items),
        ] + $this->trackQueue($employee, $stageId, $workflow), 200, ['Cache-Control' => 'private, no-store']);
    }

    /** Open-order totals for each of the employee's allowed stages, in workflow order (one grouped query). */
    public function stageSummary(Request $request): Response
    {
        $employee = $this->authenticate($request)->employee;
        $this->authorization->require($employee, 'orders.view_mine');
        $allowed = $employee->isOperationallyActive() ? $employee->allowedStageIds : [];
        $workflow = $this->workflowOrNull();
        $ordered = $workflow === null ? $allowed : array_values(array_filter(array_map(static fn ($stage): string => $stage->id, $workflow->stages), static fn (string $id): bool => in_array($id, $allowed, true)));
        $counts = $this->repository->countOpenByStage($employee->employeeUuid, $ordered, $this->authorization->can($employee, OrderAccessPolicy::TRENDYOL_VIEW), $employee->stageSourceScopes);
        // Pieces in rework are separate work at their own stage, counted apart so an order is never counted twice.
        $rework = $this->tracks?->countByStage($employee->employeeUuid, $ordered, $employee->stageSourceScopes) ?? [];
        return Response::json([
            'stages' => array_map(static fn (string $id): array => ['stageId' => $id, 'total' => $counts[$id]['total'] ?? 0, 'mine' => $counts[$id]['mine'] ?? 0,
                'reworkTotal' => $rework[$id]['total'] ?? 0, 'reworkMine' => $rework[$id]['mine'] ?? 0], $ordered),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, string $globalIdString): Response
    {
        $employee = $this->authenticate($request)->employee;
        $this->authorization->require($employee, 'orders.view_mine');
        $order = $this->visibleOrder($employee, $this->parseOrderId($globalIdString));
        return $this->orderResponse($employee, $order);
    }

    public function lookup(Request $request): Response
    {
        $employee = $this->authenticate($request)->employee;
        $this->authorization->require($employee, 'orders.scan');
        $this->limitLookups($employee);
        $code = $request->query('code') ?? '';
        if (strlen($code) > 128) {
            throw new ApiException(400, 'INVALID_LOOKUP_CODE', 'The order code is not valid.');
        }

        if (str_contains($code, ':')) {
            try {
                [$sourceKey, $sourceOrderId] = explode(':', trim($code), 2);
                $globalId = new GlobalOrderId(strtolower($sourceKey), $sourceOrderId);
            } catch (\InvalidArgumentException) {
                throw new ApiException(400, 'INVALID_LOOKUP_CODE', 'The order code is not valid.');
            }
            return $this->orderResponse($employee, $this->visibleOrder($employee, $globalId));
        }

        $lookupCode = OrderLookupCode::normalizeInput($code);
        if ($lookupCode === null) {
            throw new ApiException(400, 'INVALID_LOOKUP_CODE', 'The order code is not valid.');
        }
        $visible = array_values(array_filter(
            $this->repository->findByLookupCode($employee->employeeUuid, $lookupCode, 20),
            fn (OperationalOrder $order): bool => $this->policy->canView($employee, $order),
        ));
        if ($visible === []) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        }
        if (count($visible) > 1) {
            throw new ApiException(409, 'ORDER_AMBIGUOUS', 'Several orders share this number. Scan the QR code or include the source.');
        }
        return $this->orderResponse($employee, $visible[0]);
    }

    public function resolveQr(Request $request): Response
    {
        $session = $this->authenticate($request);
        $employee = $session->employee;
        $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
        $this->authorization->require($employee, 'orders.scan');
        $this->limitLookups($employee);
        $input = $request->json(1024);
        if (array_keys($input) !== ['token'] || !is_string($input['token'])) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
        }
        $label = UnitLabelReference::parsePayload($input['token']);
        if ($label !== null && $this->tracks !== null) {
            return $this->resolvePieceLabel($employee, $label);
        }
        $reference = QrReference::parsePayload($input['token']);
        if ($reference === null) {
            throw new ApiException(400, 'INVALID_QR', 'The scanned code is not an Arasya order code.');
        }
        $resolved = $this->repository->findByQrReference($employee->employeeUuid, $reference);
        if ($resolved['status'] === 'missing') {
            throw new ApiException(404, 'UNKNOWN_QR', 'The scanned code is not registered.');
        }
        if ($resolved['status'] !== 'active') {
            $this->rejectInactiveQr($employee, $reference, $request, fn (OperationalOrder $order): bool => $this->policy->canView($employee, $order));
        }
        $order = $resolved['order'];
        if ($order === null || !$this->policy->canView($employee, $order)) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        }
        return $this->orderResponse($employee, $order);
    }

    /**
     * POST /cutting/intake: an eligible cutter scans the active Arasya QR once and receives the order into cutting
     * (stage 1 -> cutting and the claim in one transaction, see OrderOperationsService::cuttingIntake). The scan
     * itself is the intent: no order id or version comes from the client, only the QR and, when the cutter already
     * has unfinished cutting work, the explicit multiple-job confirmation. Same authentication, CSRF and lookup
     * rate limit as the ordinary QR resolution.
     */
    public function cuttingIntake(Request $request): Response
    {
        $session = $this->authenticate($request);
        $employee = $session->employee;
        $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
        $this->authorization->require($employee, 'orders.scan');
        if (!$this->policy->mayUseCuttingIntake($employee)) {
            throw OrderOperationsService::intakeNotAllowed();
        }
        $this->limitLookups($employee);
        $input = $request->json(2048);
        if (array_diff(array_keys($input), ['qrToken', 'confirmedMultiple', 'ownedCount']) !== [] || !is_string($input['qrToken'] ?? null)
            || (isset($input['confirmedMultiple']) && !is_bool($input['confirmedMultiple'])) || (isset($input['ownedCount']) && (!is_int($input['ownedCount']) || $input['ownedCount'] < 0))) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
        }
        $reference = QrReference::parsePayload($input['qrToken']);
        if ($reference === null) {
            throw new ApiException(400, 'INVALID_QR', 'The scanned code is not an Arasya order code.');
        }
        $resolved = $this->repository->findByQrReference($employee->employeeUuid, $reference);
        if ($resolved['status'] === 'missing') {
            throw new ApiException(404, 'UNKNOWN_QR', 'The scanned code is not registered.');
        }
        if ($resolved['status'] !== 'active') {
            $this->rejectInactiveQr($employee, $reference, $request, fn (OperationalOrder $order): bool => $this->policy->canView($employee, $order) || $this->policy->reachesForCuttingIntake($employee, $order));
        }
        $order = $resolved['order'] ?? throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        $intent = array_intersect_key($input, array_flip(['confirmedMultiple', 'ownedCount']));
        $payload = $this->operations->cuttingIntake($employee, $order->globalId, $reference, $intent, $request->header('idempotency-key') ?? '', OperationContext::fromRequest($request));
        return Response::json($payload, 200, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * A scanned piece label resolves its order, like the order QR, plus the exact piece (and its track) that was
     * scanned, so the Staff screen opens the work of that piece and never guesses between pieces.
     */
    private function resolvePieceLabel(EmployeeIdentity $employee, UnitLabelReference $label): Response
    {
        $resolved = (new \Arasya\Operations\Production\WorkUnits($this->qrLedger ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Orders API is not ready.')))->resolveLabel($label);
        if ($resolved === null) {
            throw new ApiException(404, 'UNKNOWN_QR', 'The scanned code is not registered.');
        }
        if ($resolved['status'] !== 'active') {
            throw new ApiException(410, 'EXPIRED_QR', 'The scanned code is no longer valid.');
        }
        $order = $this->repository->findByGlobalId($employee->employeeUuid, $resolved['globalOrderId']);
        if ($order === null || !$this->policy->canView($employee, $order)) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        }
        $body = $this->orderResponse($employee, $order)->payload ?? [];
        $body['scannedPiece'] = ['unitId' => $resolved['unitUuid'], 'trackId' => $resolved['trackUuid']];
        return Response::json($body, 200, ['Cache-Control' => 'private, no-store']);
    }

    /** POST /production-tracks/{id}/claim | complete, GET /production-tracks/{id}/labels: work on a piece in rework. */
    public function track(Request $request, string $trackId, string $action): Response
    {
        $tracks = $this->tracks ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Piece tracking is not ready.');
        $session = $this->authenticate($request);
        $employee = $session->employee;
        if ($request->method === 'GET' && $action === 'labels') {
            $this->authorization->require($employee, 'orders.view_mine');
            return Response::json($tracks->labels($employee, $trackId), 200, ['Cache-Control' => 'private, no-store']);
        }
        if ($request->method !== 'POST' || !in_array($action, ['claim', 'complete'], true)) {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
        }
        $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
        $input = $request->json(2048);
        $allowed = $action === 'claim' ? ['expectedVersion', 'qrToken'] : ['expectedVersion', 'defectResolved'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
        }
        $key = $request->header('idempotency-key') ?? '';
        $payload = $action === 'claim' ? $tracks->claim($employee, $trackId, $input, $key, $request->requestId) : $tracks->complete($employee, $trackId, $input, $key, $request->requestId);
        return Response::json($payload, 200, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Pieces in rework at one stage (the stage queue's second list), bucketed like the orders.
     * @return array{trackItems: list<array<string, mixed>>, trackCounts: array<string, int>}
     */
    private function trackQueue(EmployeeIdentity $employee, string $stageId, ?ProductionWorkflow $workflow): array
    {
        if ($this->tracks === null) {
            return ['trackItems' => [], 'trackCounts' => ['total' => 0, 'mine' => 0, 'available' => 0, 'blocked' => 0, 'claimedByOthers' => 0]];
        }
        $rows = $this->tracks->atStage($stageId, $employee->sourcesAt($stageId), self::STAGE_QUEUE_SCAN);
        $orderRows = [];
        foreach ($this->tracks->orderRows(array_values(array_unique(array_column($rows, 'order_uuid')))) as $row) {
            $orderRows[(string) $row['order_uuid']] = $row;
        }
        $buckets = ['mine' => [], 'available' => [], 'blocked' => [], 'claimedByOthers' => []];
        $orders = [];
        foreach ($rows as $row) {
            $orderRow = $orderRows[(string) $row['order_uuid']] ?? null;
            $order = $orderRow === null ? null : ($orders[(string) $row['order_uuid']] ??= $this->repository->findByGlobalId($employee->employeeUuid, (string) $orderRow['global_order_id']));
            if ($order === null || !$this->policy->canView($employee, $order)) {
                continue;
            }
            $pieces = $this->tracks->piecesFor([$orderRow], $employee, $workflow)[$order->orderUuid] ?? null;
            $track = null;
            foreach ($pieces['tracks'] ?? [] as $candidate) {
                if ($candidate['id'] === $row['track_uuid']) {
                    $track = $candidate;
                }
            }
            if ($track === null) {
                continue;
            }
            $bucket = match (true) {
                ($track['owner']['isMe'] ?? false) === true => 'mine',
                ($track['allowedAction']['id'] ?? null) === \Arasya\Operations\Production\ReworkTrackService::ACTION_CLAIM => 'available',
                ($track['blockedReason'] ?? null) === 'claimed_by_other' => 'claimedByOthers',
                default => 'blocked',
            };
            $buckets[$bucket][] = ['trackId' => $track['id'], 'order' => $this->serializer->serializeOrder($order, $this->policy->evaluate($employee, $order, $workflow), pieces: $pieces)];
        }
        return [
            'trackItems' => array_slice(array_merge(...array_values($buckets)), 0, self::STAGE_QUEUE_ITEMS),
            'trackCounts' => ['total' => array_sum(array_map('count', $buckets))] + array_map('count', $buckets),
        ];
    }

    /** @param list<OperationalOrder> $orders @return array<string, array<string, mixed>> */
    private function piecesOf(array $orders, EmployeeIdentity $employee, ?ProductionWorkflow $workflow, bool $detailed = false): array
    {
        if ($this->tracks === null || $orders === []) {
            return [];
        }
        $pieces = $this->tracks->piecesFor($this->tracks->orderRows(array_map(static fn (OperationalOrder $order): string => $order->orderUuid, $orders)), $employee, $workflow);
        if ($detailed && $this->quality !== null) {
            // Single-order views: the open request of each piece in rework as this employee sees it (role, actions),
            // so a scan guides the addressed employee to that exact request, like the order's own open request.
            foreach ($pieces as &$view) {
                foreach ($view['tracks'] as &$track) {
                    if ($track['openException'] !== null) {
                        $track['openException'] = $this->quality->viewerSummary($track['openException']['id'], $employee->employeeUuid) ?? $track['openException'];
                    }
                }
                unset($track);
            }
            unset($view);
        }
        return $pieces;
    }

    public function claim(Request $request, string $globalIdString): Response
    {
        return $this->mutate($request, $globalIdString, 'orders.claim', OrderOperationsService::OPERATION_CLAIM);
    }

    public function transition(Request $request, string $globalIdString): Response
    {
        return $this->mutate($request, $globalIdString, 'orders.advance_stage', OrderOperationsService::OPERATION_TRANSITION);
    }

    private function mutate(Request $request, string $globalIdString, string $permission, string $operation): Response
    {
        $session = $this->authenticate($request);
        $employee = $session->employee;
        $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
        $this->authorization->require($employee, $permission);
        $orderId = $this->parseOrderId($globalIdString);
        $input = $request->json(2048);
        $allowed = $operation === OrderOperationsService::OPERATION_CLAIM ? ['expectedVersion', 'qrToken', 'confirmedMultiple', 'ownedCount'] : ['expectedVersion'];
        if (array_diff(array_keys($input), $allowed) !== [] || !is_int($input['expectedVersion'] ?? null) || $input['expectedVersion'] < 1
            || (isset($input['qrToken']) && !is_string($input['qrToken'])) || (isset($input['confirmedMultiple']) && !is_bool($input['confirmedMultiple']))
            || (isset($input['ownedCount']) && (!is_int($input['ownedCount']) || $input['ownedCount'] < 0))) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
        }
        $idempotencyKey = $request->header('idempotency-key') ?? '';
        $context = OperationContext::fromRequest($request);
        $payload = $operation === OrderOperationsService::OPERATION_CLAIM
            ? $this->operations->claim($employee, $orderId, $input['expectedVersion'], $idempotencyKey, $context, $input)
            : $this->operations->completeCurrentStage($employee, $orderId, $input['expectedVersion'], $idempotencyKey, $context);
        return Response::json($payload, 200, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * A scanned QR that is not active never resolves an order: a replaced or revoked document revision or QR says
     * so clearly (the active revision only to an employee who may see the order), anything else is expired. The
     * refusal is recorded in the QR ledger without the scanned payload.
     *
     * @param callable(OperationalOrder): bool $mayViewOrder
     */
    private function rejectInactiveQr(EmployeeIdentity $employee, QrReference $reference, Request $request, callable $mayViewOrder): never
    {
        $qr = $this->qrLedger === null ? null : ProductionQrLedger::state($this->qrLedger, $reference->value);
        $visible = $qr === null ? null : $this->repository->findByGlobalId($employee->employeeUuid, $qr['globalOrderId']);
        $mayView = $visible !== null && $mayViewOrder($visible);
        if ($qr !== null && $this->qrLedger !== null) {
            // Evidence of the refused scan (employee, order, revision hint), never the scanned payload.
            ProductionQrLedger::record($this->qrLedger, ['order_uuid' => $qr['orderUuid'], 'global_order_id' => $qr['globalOrderId'], 'source_key' => $qr['sourceKey']],
                ProductionQrLedger::ACTION_SCAN_REJECTED, $reference->value, null, $employee->employeeUuid, 'scan_' . $qr['state'], ProductionQrLedger::modeLabel($this->modes, $qr['sourceKey']), $request->requestId, $this->now());
        }
        // A QR of a replaced or revoked document revision says so clearly; the active revision
        // number is shown only to an employee who may see the order.
        $state = $this->documents?->qrRevision($reference->value);
        if ($state !== null && $state['status'] !== 'active') {
            if (!$mayView) {
                $state['activeRevisionNumber'] = null;
            }
            throw \Arasya\Operations\Document\DocumentGuard::invalidQr($state);
        }
        // A QR replaced by a manager rotation (superseded) or revoked never resolves the order.
        if ($qr !== null && in_array($qr['state'], [ProductionQrLedger::RETIRED_SUPERSEDED, ProductionQrLedger::RETIRED_REVOKED], true)) {
            throw ProductionQrLedger::invalid($qr, $mayView ? $qr['activeRevision'] : null);
        }
        throw new ApiException(410, 'EXPIRED_QR', 'The scanned code is no longer valid.');
    }

    private function authenticate(Request $request): AuthenticatedSession
    {
        $session = $this->auth->authenticate(
            $request->cookie($this->config->cookieName()) ?? '',
            $request->ipAddress,
            $request->userAgent,
            $request->requestId,
        );
        $this->context->authenticatedAs($session->employee->employeeUuid);
        return $session;
    }

    private function allowedStage(EmployeeIdentity $employee, string $stageId): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D', $stageId) !== 1) {
            throw new ApiException(400, 'INVALID_STAGE', 'The stage is not valid.');
        }
        if (!$employee->isOperationallyActive() || !in_array($stageId, $employee->allowedStageIds, true)) {
            throw new ApiException(403, 'STAGE_NOT_ALLOWED', 'This stage is not assigned to the employee.');
        }
        return $stageId;
    }

    private function parseOrderId(string $globalIdString): GlobalOrderId
    {
        try {
            return GlobalOrderId::fromString($globalIdString);
        } catch (\InvalidArgumentException) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        }
    }

    private function visibleOrder(EmployeeIdentity $employee, GlobalOrderId $globalId): OperationalOrder
    {
        $order = $this->repository->findByGlobalId($employee->employeeUuid, $globalId->toString());
        if ($order === null || !$this->policy->canView($employee, $order)) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        }
        return $order;
    }

    private function orderResponse(EmployeeIdentity $employee, OperationalOrder $order): Response
    {
        return Response::json(
            $this->serializer->serializeOrder($order, $this->policy->evaluate($employee, $order, $workflow = $this->workflowOrNull()), $this->quality?->orderQuality($order->orderUuid, $order->openExceptionUuid, $employee->employeeUuid), $this->documents?->summary($order->orderUuid), $this->piecesOf([$order], $employee, $workflow, true)[$order->orderUuid] ?? null),
            200,
            ['Cache-Control' => 'private, no-store'],
        );
    }

    /** @return array<string, mixed> */
    private function present(EmployeeIdentity $employee, OperationalOrder $order, ?ProductionWorkflow $workflow, ?array $pieces = null): array
    {
        return $this->serializer->serializeOrder($order, $this->policy->evaluate($employee, $order, $workflow), pieces: $pieces);
    }

    private function workflowOrNull(): ?ProductionWorkflow
    {
        try {
            return $this->workflows->current();
        } catch (RuntimeException) {
            return null;
        }
    }

    private function limitLookups(EmployeeIdentity $employee): void
    {
        if (!$this->rateLimiter->hit('order-lookup', $employee->employeeUuid, self::LOOKUP_LIMIT, self::LOOKUP_WINDOW_SECONDS)) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many lookups. Try again shortly.');
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
