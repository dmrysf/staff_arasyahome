<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

interface OperationalOrderRepository
{
    /**
     * Orders with an active direct relation to the employee, newest relation first.
     *
     * @return array{items: list<OperationalOrder>, nextCursor: string|null}
     */
    public function listMine(string $employeeUuid, int $limit, ?string $cursor): array;

    /** Loads an order with only the given employee's relation attached. Visibility is decided by OrderAccessPolicy. */
    public function findByGlobalId(string $employeeUuid, string $globalOrderId): ?OperationalOrder;

    /** @return array{status: 'missing'|'revoked'|'expired'|'active', order: OperationalOrder|null} */
    public function findByQrReference(string $employeeUuid, QrReference $reference): array;

    /** @return list<OperationalOrder> exact lookup-code matches, bounded by $max */
    public function findByLookupCode(string $employeeUuid, string $lookupCode, int $max): array;
}
