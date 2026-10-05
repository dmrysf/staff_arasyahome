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
        public ?array $productionContext = null,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->itemUuid) !== 1) {
            throw new \InvalidArgumentException('Invalid UUID.');
        }
        if ($this->sourceItemId === '' || mb_strlen($this->sourceItemId) > 128) {
            throw new \InvalidArgumentException('Invalid source item ID.');
        }
        if ($this->lineNumber < 1) {
            throw new \InvalidArgumentException('Invalid line number.');
        }
        if ($this->name === '' || mb_strlen($this->name) > 255) {
            throw new \InvalidArgumentException('Invalid name.');
        }
        if ($this->productCode !== null && mb_strlen($this->productCode) > 120) {
            throw new \InvalidArgumentException('Invalid product code.');
        }
        if ($this->variant !== null && mb_strlen($this->variant) > 160) {
            throw new \InvalidArgumentException('Invalid variant.');
        }
        if ($this->color !== null && mb_strlen($this->color) > 160) {
            throw new \InvalidArgumentException('Invalid color.');
        }
        if ($this->quantity < 1) {
            throw new \InvalidArgumentException('Invalid quantity.');
        }
        if ($this->widthValue !== null && ($this->widthValue < 0 || is_infinite($this->widthValue) || is_nan($this->widthValue))) {
            throw new \InvalidArgumentException('Invalid width.');
        }
        if ($this->heightValue !== null && ($this->heightValue < 0 || is_infinite($this->heightValue) || is_nan($this->heightValue))) {
            throw new \InvalidArgumentException('Invalid height.');
        }
        if ($this->meters !== null && ($this->meters < 0 || is_infinite($this->meters) || is_nan($this->meters))) {
            throw new \InvalidArgumentException('Invalid meters.');
        }
        if ($this->measurementUnit !== null && !in_array($this->measurementUnit, ['mm', 'cm', 'm'], true)) {
            throw new \InvalidArgumentException('Invalid measurement unit.');
        }
    }
}
