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

    /**
     * Open orders (not completed, not unavailable) currently at one production stage, longest waiting first,
     * with only the given employee's relation attached. Read-only and bounded by $max; the caller decides visibility.
     *
     * @return list<OperationalOrder>
     */
    public function listOpenAtStage(string $employeeUuid, string $stageId, int $max): array;

    /**
     * Open-order counts per stage for the given stages: total and owned by the employee. Trendyol orders at the
     * initial stage are counted only when $includeTrendyolIntake is true (the same rule as OrderAccessPolicy::canView).
     *
     * @param list<string> $stageIds
     * @return array<string, array{total: int, mine: int}>
     */
    public function countOpenByStage(string $employeeUuid, array $stageIds, bool $includeTrendyolIntake): array;
}
