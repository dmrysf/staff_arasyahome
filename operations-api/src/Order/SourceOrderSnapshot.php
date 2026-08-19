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
        // Centralize identity validation
        new GlobalOrderId($this->sourceKey, $this->sourceOrderId);
        if ($this->sourceEventId === '' || strlen($this->sourceEventId) > 191 || preg_match('/[[:cntrl:]]/', $this->sourceEventId) === 1) {
            throw new \InvalidArgumentException('Invalid source event ID.');
        }
        if ($this->sourceSchemaVersion < 1) {
            throw new \InvalidArgumentException('Schema version must be >= 1.');
        }
        if ($this->orderNumber === '' || mb_strlen($this->orderNumber) > 120) {
            throw new \InvalidArgumentException('Invalid order number.');
        }
        if ($this->productionStageId === '' || strlen($this->productionStageId) > 100 || preg_match('/^[a-z0-9_-]+$/', $this->productionStageId) !== 1) {
            throw new \InvalidArgumentException('Invalid production stage ID.');
        }
        if ($this->sourceCommerceStatusCode !== null && mb_strlen($this->sourceCommerceStatusCode) > 100) {
            throw new \InvalidArgumentException('Commerce status code too long.');
        }
        if ($this->sourceCommerceStatusLabel !== null && mb_strlen($this->sourceCommerceStatusLabel) > 160) {
            throw new \InvalidArgumentException('Commerce label too long.');
        }
        if ($this->productionNotes !== null && mb_strlen($this->productionNotes) > 4000) {
            throw new \InvalidArgumentException('Production notes too long.');
        }
        if (!in_array($this->operationalStatus, ['in_progress', 'handed_over', 'unavailable'], true)) {
            throw new \InvalidArgumentException('Invalid operational status.');
        }
        
        $itemKeys = [];
        $lineKeys = [];
        foreach ($this->items as $item) {
            if (!$item instanceof OperationalOrderItem) {
                throw new \InvalidArgumentException('Items must be OperationalOrderItem instances.');
            }
            if (isset($itemKeys[$item->sourceItemId])) {
                throw new \InvalidArgumentException('Duplicate source item ID.');
            }
            $itemKeys[$item->sourceItemId] = true;
            
            if (isset($lineKeys[$item->lineNumber])) {
                throw new \InvalidArgumentException('Duplicate line number.');
            }
            $lineKeys[$item->lineNumber] = true;
        }
    }

    public function globalId(): GlobalOrderId
    {
        return new GlobalOrderId($this->sourceKey, $this->sourceOrderId);
    }
}
