<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

final readonly class OrderSerializer
{
    public function serializeOrder(OperationalOrder $order): array
    {
        $data = [
            'id' => $order->globalId->toString(),
            'source' => $order->globalId->sourceKey,
            'orderNumber' => $order->orderNumber,
            'productionStageId' => $order->productionStageId,
            'products' => array_map(fn($item) => $this->serializeItem($item), $order->items),
            'status' => $order->operationalStatus,
            'freshness' => [
                'status' => $order->freshness->status,
                'sourceChangedAt' => $order->freshness->sourceChangedAt->format('Y-m-d\TH:i:s.v\Z'),
                'lastSourceSeenAt' => $order->freshness->lastSourceSeenAt->format('Y-m-d\TH:i:s.v\Z'),
            ],
            'version' => $order->version,
            'updatedAt' => $order->updatedAt->format('Y-m-d\TH:i:s.v\Z'),
        ];

        if ($order->sourceCommerceStatusCode !== null) {
            $data['sourceCommerceStatus'] = [
                'code' => $order->sourceCommerceStatusCode,
                'label' => $order->sourceCommerceStatusLabel ?? $order->sourceCommerceStatusCode,
            ];
        }

        if ($order->productionNotes !== null) {
            $data['productionNotes'] = $order->productionNotes;
        }

        if ($order->acceptedAt !== null) {
            $data['acceptedAt'] = $order->acceptedAt->format('Y-m-d\TH:i:s.v\Z');
        }

        if ($order->relation !== null) {
            $data['employeeRelation'] = [
                'employeeUuid' => $order->relation->employeeUuid,
                'type' => $order->relation->type,
                'lastActionAt' => $order->relation->lastActionAt->format('Y-m-d\TH:i:s.v\Z'),
            ];
        }

        return $data;
    }

    private function serializeItem(OperationalOrderItem $item): array
    {
        $data = [
            'id' => $item->itemUuid,
            'name' => $item->name,
            'quantity' => $item->quantity,
        ];
        
        if ($item->productCode !== null) $data['code'] = $item->productCode;
        if ($item->variant !== null) $data['variant'] = $item->variant;
        if ($item->color !== null) $data['color'] = $item->color;
        
        if ($item->widthValue !== null || $item->heightValue !== null || $item->measurementUnit !== null) {
            $data['measurements'] = array_filter([
                'width' => $item->widthValue,
                'height' => $item->heightValue,
                'unit' => $item->measurementUnit,
            ], fn($v) => $v !== null);
        }
        
        if ($item->meters !== null) $data['meters'] = $item->meters;

        return $data;
    }
}
