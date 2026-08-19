<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use DateTimeImmutable;

final readonly class OperationalOrder
{
    /** @param list<OperationalOrderItem> $items */
    public function __construct(
        public string $orderUuid,
        public GlobalOrderId $globalId,
        public string $orderNumber,
        public string $productionStageId,
        public ?string $sourceCommerceStatusCode,
        public ?string $sourceCommerceStatusLabel,
        public ?string $productionNotes,
        public string $operationalStatus, // 'in_progress', 'handed_over', 'unavailable'
        public OrderFreshness $freshness,
        public int $version,
        public ?DateTimeImmutable $acceptedAt,
        public DateTimeImmutable $updatedAt,
        public array $items = [],
        public ?EmployeeOrderRelation $relation = null,
    ) {
    }
}
