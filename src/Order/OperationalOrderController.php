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
            // A QR of a replaced or revoked document revision says so clearly; the active revision
            // number is shown only to an employee who may see the order.
            $state = $this->documents?->qrRevision($reference->value);
            if ($state !== null && $state['status'] !== 'active') {
                $visible = $this->repository->findByGlobalId($employee->employeeUuid, $state['orderId']);
                if ($visible === null || !$this->policy->canView($employee, $visible)) {
                    $state['activeRevisionNumber'] = null;
                }
                throw \Arasya\Operations\Document\DocumentGuard::invalidQr($state);
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
}
