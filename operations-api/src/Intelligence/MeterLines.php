<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use PDO;

/** Commercial lines of the given orders with their source meter facts and sold-meter contract (read only). */
final class MeterLines
{
    /**
     * @param list<string> $orderUuids
     * @return array<string, list<array{item_uuid: string, line_number: int, product_code: string|null, variant: string|null, name: string,
     *         quantity: int, contract: array{status: string, fabricUnits: int|null, railUnits: int|null}}>>
     */
    public static function forOrders(PDO $pdo, array $orderUuids): array
    {
        $orderUuids = array_values(array_unique($orderUuids));
        $result = [];
        foreach (array_chunk($orderUuids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $s = $pdo->prepare("SELECT i.order_uuid, i.item_uuid, i.line_number, i.name, i.product_code, i.variant, i.quantity, i.width_value, i.measurement_unit, i.meters,
                    JSON_UNQUOTE(JSON_EXTRACT(i.production_context, '$.kind')) AS context_kind, o.source_key, f.basis_kind, f.priced_length,
                    (x.order_uuid IS NOT NULL) AS excluded
                FROM operational_order_items i
                JOIN operational_orders o ON o.order_uuid = i.order_uuid
                LEFT JOIN order_item_meter_facts f ON f.order_uuid = i.order_uuid AND f.source_item_id = i.source_item_id
                LEFT JOIN production_meter_exclusions x ON x.order_uuid = i.order_uuid
                WHERE i.order_uuid IN ({$marks}) ORDER BY i.order_uuid, i.line_number");
            $s->execute($chunk);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[$row['order_uuid']][] = [
                    'item_uuid' => (string) $row['item_uuid'],
                    'line_number' => (int) $row['line_number'],
                    'product_code' => $row['product_code'],
                    'variant' => $row['variant'],
                    'name' => (string) $row['name'],
                    'quantity' => (int) $row['quantity'],
                    'contract' => MeterContract::line([
                        'source_key' => (string) $row['source_key'], 'quantity' => (int) $row['quantity'], 'width_value' => $row['width_value'],
                        'measurement_unit' => $row['measurement_unit'], 'meters' => $row['meters'], 'context_kind' => $row['context_kind'],
                        'basis_kind' => $row['basis_kind'], 'priced_length' => $row['priced_length'], 'excluded' => (int) $row['excluded'] === 1,
                    ]),
                ];
            }
        }
        return $result;
    }

    /**
     * Fabric meters of an order as a whole for production metrics: the sum of its measured lines, and how many of its
     * lines that could carry fabric are unknown (never counted as zero).
     * @param list<array{contract: array{status: string, fabricUnits: int|null}}> $lines
     * @return array{fabricUnits: int, unknownLines: int, measuredLines: int, excluded: bool}
     */
    public static function orderFabric(array $lines): array
    {
        $units = 0; $unknown = 0; $measured = 0; $excluded = false;
        foreach ($lines as $line) {
            $status = $line['contract']['status'];
            if ($status === MeterContract::EXCLUDED) {
                $excluded = true;
                continue;
            }
            if ($line['contract']['fabricUnits'] !== null) {
                $units += $line['contract']['fabricUnits'];
                $measured++;
            } elseif (in_array($status, [MeterContract::UNKNOWN, MeterContract::UNKNOWN_BASIS, MeterContract::UNKNOWN_MARKETPLACE, MeterContract::CONFLICT], true)) {
                $unknown++;
            }
        }
        return ['fabricUnits' => $units, 'unknownLines' => $unknown, 'measuredLines' => $measured, 'excluded' => $excluded];
    }
}
