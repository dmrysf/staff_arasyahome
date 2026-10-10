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
        $result = $this->repository->listMine($employee->employeeUuid, (int) $limitParam, $request->query('cursor'));
        $workflow = $this->workflowOrNull();

        return Response::json([
            'items' => array_map(fn (OperationalOrder $order): array => $this->present($employee, $order, $workflow), $result['items']),
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
        return Response::json([
            'stageId' => $stageId,
            'counts' => ['total' => $total] + array_map('count', $buckets),
            'countsComplete' => $complete,
            'items' => array_map(fn (array $entry): array => $this->serializer->serializeOrder($entry[0], $entry[1]), $items),
        ], 200, ['Cache-Control' => 'private, no-store']);
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
        return Response::json([
            'stages' => array_map(static fn (string $id): array => ['stageId' => $id, 'total' => $counts[$id]['total'] ?? 0, 'mine' => $counts[$id]['mine'] ?? 0], $ordered),
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
        $reference = QrReference::parsePayload($input['token']);
        if ($reference === null) {
            throw new ApiException(400, 'INVALID_QR', 'The scanned code is not an Arasya order code.');
        }
        $resolved = $this->repository->findByQrReference($employee->employeeUuid, $reference);
        if ($resolved['status'] === 'missing') {
            throw new ApiException(404, 'UNKNOWN_QR', 'The scanned code is not registered.');
        }
        if ($resolved['status'] !== 'active') {
            $qr = $this->qrLedger === null ? null : ProductionQrLedger::state($this->qrLedger, $reference->value);
            $visible = $qr === null ? null : $this->repository->findByGlobalId($employee->employeeUuid, $qr['globalOrderId']);
            $mayView = $visible !== null && $this->policy->canView($employee, $visible);
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
        $order = $resolved['order'];
        if ($order === null || !$this->policy->canView($employee, $order)) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        }
        return $this->orderResponse($employee, $order);
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
            $this->serializer->serializeOrder($order, $this->policy->evaluate($employee, $order, $this->workflowOrNull()), $this->quality?->orderQuality($order->orderUuid, $order->openExceptionUuid, $employee->employeeUuid), $this->documents?->summary($order->orderUuid)),
            200,
            ['Cache-Control' => 'private, no-store'],
        );
    }

    /** @return array<string, mixed> */
    private function present(EmployeeIdentity $employee, OperationalOrder $order, ?ProductionWorkflow $workflow): array
    {
        return $this->serializer->serializeOrder($order, $this->policy->evaluate($employee, $order, $workflow));
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
