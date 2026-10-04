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
        public string $operationalStatus, // source availability: 'in_progress', 'handed_over', 'unavailable'
        public OrderFreshness $freshness,
        public int $version,
        public ?DateTimeImmutable $acceptedAt,
        public DateTimeImmutable $updatedAt,
        public array $items = [],
        public ?EmployeeOrderRelation $relation = null,
        public int $productionVersion = 1,
        public ?string $productionOwnerEmployeeUuid = null,
        public ?DateTimeImmutable $productionCompletedAt = null,
    ) {
    }

    public function isProductionCompleted(): bool
    {
        return $this->productionCompletedAt !== null;
    }

    /** Staff-facing order status; production completion is represented as handed over. */
    public function staffStatus(): string
    {
        if ($this->operationalStatus === 'unavailable') {
            return 'unavailable';
        }
        return $this->isProductionCompleted() ? 'handed_over' : $this->operationalStatus;
    }

    public function totalMeters(): ?float
    {
        $total = null;
        foreach ($this->items as $item) {
            if ($item->meters !== null) {
                $total = ($total ?? 0.0) + $item->meters;
            }
        }
        return $total;
    }
}
