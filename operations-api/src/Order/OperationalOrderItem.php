<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

final readonly class OperationalOrderItem
{
    public function __construct(
        public string $itemUuid,
        public string $sourceItemId,
        public int $lineNumber,
        public string $name,
        public ?string $productCode,
        public ?string $variant,
        public ?string $color,
        public ?float $widthValue,
        public ?float $heightValue,
        public ?string $measurementUnit,
        public ?float $meters,
        public int $quantity,
    ) {
    }
}
