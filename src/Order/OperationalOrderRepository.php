<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

interface OperationalOrderRepository
{
    /**
     * @param string $employeeUuid
     * @param list<string> $allowedStageIds
     * @param int $limit
     * @param string|null $cursor
     * @return array{items: list<OperationalOrder>, nextCursor: string|null}
     */
    public function listMine(string $employeeUuid, array $allowedStageIds, int $limit, ?string $cursor): array;

    public function findMineByGlobalId(string $employeeUuid, array $allowedStageIds, string $globalOrderId): ?OperationalOrder;
}
