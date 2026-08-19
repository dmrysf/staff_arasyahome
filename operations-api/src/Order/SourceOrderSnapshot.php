<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use DateTimeImmutable;

final readonly class SourceOrderSnapshot
{
    /** @param list<OperationalOrderItem> $items */
    public function __construct(
        public string $sourceKey,
        public string $sourceOrderId,
        public string $sourceEventId,
        public int $sourceSchemaVersion,
        public DateTimeImmutable $sourceChangedAt,
        public string $orderNumber,
        public string $productionStageId,
        public ?string $sourceCommerceStatusCode,
        public ?string $sourceCommerceStatusLabel,
        public ?string $productionNotes,
        public string $operationalStatus,
        public ?DateTimeImmutable $acceptedAt,
        public array $items,
    ) {
    }

    public function globalId(): GlobalOrderId
    {
        return new GlobalOrderId($this->sourceKey, $this->sourceOrderId);
    }
}
