<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;

final readonly class OperationalOrderController
{
    public function __construct(
        private OperationalOrderRepository $repository,
        private OrderSerializer $serializer,
        private AuthenticationService $auth,
        private AuthorizationService $authorization,
        private Config $config,
        private RequestContext $context,
    ) {
    }

    public function listMine(Request $request): Response
    {
        $session = $this->auth->authenticate(
            $request->cookie($this->config->cookieName()) ?? '',
            $request->ipAddress,
            $request->userAgent,
            $request->requestId,
        );
        $this->context->authenticatedAs($session->employee->employeeUuid);
        $this->authorization->require($session->employee, 'orders.view_mine');

        $limitParam = $request->query('limit', '50');
        if (!ctype_digit($limitParam) || (int)$limitParam < 1 || (int)$limitParam > 100) {
            throw new ApiException(400, 'INVALID_LIMIT', 'Limit must be an integer between 1 and 100.');
        }
        $limit = (int)$limitParam;
        
        $cursor = $request->query('cursor');

        $result = $this->repository->listMine($session->employee->employeeUuid, $session->employee->allowedStageIds, $limit, $cursor);

        return Response::json([
            'items' => array_map(fn($order) => $this->serializer->serializeOrder($order), $result['items']),
            'nextCursor' => $result['nextCursor'],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, string $globalIdString): Response
    {
        $session = $this->auth->authenticate(
            $request->cookie($this->config->cookieName()) ?? '',
            $request->ipAddress,
            $request->userAgent,
            $request->requestId,
        );
        $this->context->authenticatedAs($session->employee->employeeUuid);
        $this->authorization->require($session->employee, 'orders.view_mine');

        try {
            $globalOrderId = GlobalOrderId::fromString($globalIdString);
        } catch (\InvalidArgumentException) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        }

        $order = $this->repository->findMineByGlobalId($session->employee->employeeUuid, $session->employee->allowedStageIds, $globalOrderId->toString());

        if ($order === null) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        }

        return Response::json($this->serializer->serializeOrder($order), 200, ['Cache-Control' => 'private, no-store']);
    }
}
