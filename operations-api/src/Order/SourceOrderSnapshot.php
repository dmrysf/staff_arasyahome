<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use DateTimeImmutable;

final readonly class SourceOrderSnapshot
{
    /**
     * @param list<OperationalOrderItem> $items
     * @param array{name: string|null, company: string|null, addressLines: list<string>, phoneMasked: string|null}|null $delivery printable delivery identity (masked phone only)
     * @param array{method: string, advanceStatus: string, advanceRevision: int, advanceConfirmedAt: DateTimeImmutable|null}|null $payment
     *        the source's advance payment facts (no amounts); null when the source did not state them
     * @param array{submittedAt: DateTimeImmutable, evidence: string}|null $submission
     *        when the source first held the order in a submitted status, and how it knows; null when not stated
     * @param string|null $pricingFingerprint `sha256:<hex>` of the source's prices (order.pricing, API 2.38.0), never an amount
     * @param string|null $dueDate the source's due date (order.dueDate, Y-m-d, API 2.38.0)
     * @param array{state: string, basis: string|null, pricingFingerprint: string|null}|null $financial
     *        the source's Confirmare financial clearance (order.financial, API 2.38.0, no amounts; `attention` API 2.40.0); null when not stated
     * @param bool|null $contactPhone whether the shop holds a callable customer telephone number (order.contact.phone,
     *        API 2.40.0); null when not stated
     */
    public function __construct(
        public string $sourceKey,
        public string $sourceOrderId,
        public string $sourceEventId,
        public int $sourceSchemaVersion,
        public DateTimeImmutable $sourceChangedAt,
        public string $orderNumber,
        public ?string $productionStageId,
        public ?string $sourceCommerceStatusCode,
        public ?string $sourceCommerceStatusLabel,
        public ?string $productionNotes,
        public string $operationalStatus,
        public ?DateTimeImmutable $acceptedAt,
        public array $items,
        public ?array $delivery = null,
        public ?array $payment = null,
        public ?array $submission = null,
        public ?string $pricingFingerprint = null,
        public ?string $dueDate = null,
        public ?array $financial = null,
        public ?bool $contactPhone = null,
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
        if ($this->productionStageId !== null && ($this->productionStageId === '' || strlen($this->productionStageId) > 100 || preg_match('/^[a-z0-9_-]+$/D', $this->productionStageId) !== 1)) {
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
