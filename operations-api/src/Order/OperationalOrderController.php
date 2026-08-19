<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;

final readonly class OperationalOrderController
{
    public function __construct(
        private OperationalOrderRepository $repository,
        private OrderSerializer $serializer,
        private RequestContext $context,
    ) {
    }

    public function listMine(Request $request): Response
    {
        $employee = $this->context->requireEmployee();
        
        if (!in_array('orders.view_mine', $employee->permissions, true)) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Missing required permission.');
        }

        $limit = max(1, min(100, (int)$request->query('limit', '50')));
        $cursor = $request->query('cursor');

        $result = $this->repository->listMine($employee->employeeUuid, $employee->allowedStageIds, $limit, $cursor);

        return Response::json([
            'items' => array_map(fn($order) => $this->serializer->serializeOrder($order), $result['items']),
            'nextCursor' => $result['nextCursor'],
        ]);
    }

    public function show(Request $request, string $globalIdString): Response
    {
        $employee = $this->context->requireEmployee();

        if (!in_array('orders.view_mine', $employee->permissions, true)) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Missing required permission.');
        }

        try {
            $globalOrderId = GlobalOrderId::fromString($globalIdString);
        } catch (\InvalidArgumentException) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        }

        $order = $this->repository->findMineByGlobalId($employee->employeeUuid, $employee->allowedStageIds, $globalOrderId->toString());

        if ($order === null) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        }

        return Response::json($this->serializer->serializeOrder($order));
    }
}
