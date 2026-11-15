<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use PDO;

/**
 * Commercial lines of the given orders with everything the CEO reports read (API 2.73.0). Read only.
 *
 * Per line: the sold-meter contract (MeterContract, fabric and rail apart), the Trendyol panel width (TrendyolPanelWidth,
 * Trendyol only, never fabric), the effective product code, the category (ProductCatalog) and the evidence of its meter.
 * An excluded order (K7) is marked and every report leaves it out.
 */
final class CeoLines
{
    public const EVIDENCE_IMPORT = 'shop_fact_import';
    public const EVIDENCE_EVENT = 'shop_fact_event';
    public const EVIDENCE_B2B = 'b2b_entered';
    public const EVIDENCE_PANEL = 'trendyol_panel_verified';
    public const EVIDENCE_NONE = 'none';
    public const UNKNOWN_FABRIC = [MeterContract::UNKNOWN, MeterContract::UNKNOWN_BASIS, MeterContract::CONFLICT];
    /** Trendyol answers these literal placeholders when the seller has no code (migration 026). */
    private const CODE_PLACEHOLDERS = ['merchantsku', 'stockcode', 'sku', 'barcode'];

    /**
     * @param list<string> $orderUuids
     * @return array<string, list<array<string, mixed>>> order uuid => lines
     */
    public static function forOrders(PDO $pdo, ProductCatalog $catalog, array $orderUuids): array
    {
        $orderUuids = array_values(array_unique($orderUuids));
        $result = [];
        foreach (array_chunk($orderUuids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $s = $pdo->prepare("SELECT i.order_uuid, i.item_uuid, i.line_number, i.name, i.product_code, i.variant, i.quantity, i.width_value, i.height_value, i.measurement_unit, i.meters,
                    JSON_UNQUOTE(JSON_EXTRACT(i.production_context, '$.kind')) AS context_kind, o.source_key, f.basis_kind, f.priced_length, f.source_event_id,
                    (x.order_uuid IS NOT NULL) AS excluded,
                    (l.line_id IS NOT NULL) AS ty_linked, l.product_code AS ty_code, l.quantity AS ty_quantity, l.product_size AS ty_size,
                    l.prepared_width_cm AS ty_prepared_width, l.prepared_height_cm AS ty_prepared_height, l.prepared_kind AS ty_prepared_kind
                FROM operational_order_items i
                JOIN operational_orders o ON o.order_uuid = i.order_uuid
                LEFT JOIN order_item_meter_facts f ON f.order_uuid = i.order_uuid AND f.source_item_id = i.source_item_id
                LEFT JOIN production_meter_exclusions x ON x.order_uuid = i.order_uuid
                LEFT JOIN trendyol_packages p ON o.source_key = 'trendyol' AND p.released_order_uuid = i.order_uuid
                LEFT JOIN trendyol_package_lines l ON l.package_id = p.package_id AND CAST(l.line_id AS CHAR) = i.source_item_id
                WHERE i.order_uuid IN ({$marks}) ORDER BY i.order_uuid, i.line_number");
            $s->execute($chunk);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[$row['order_uuid']][] = self::line($row, $catalog);
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function line(array $row, ProductCatalog $catalog): array
    {
        $source = (string) $row['source_key'];
        $excluded = (int) $row['excluded'] === 1;
        $contract = MeterContract::line([
            'source_key' => $source, 'quantity' => (int) $row['quantity'], 'width_value' => $row['width_value'], 'measurement_unit' => $row['measurement_unit'],
            'meters' => $row['meters'], 'context_kind' => $row['context_kind'], 'basis_kind' => $row['basis_kind'], 'priced_length' => $row['priced_length'], 'excluded' => $excluded,
        ]);
        $panel = null;
        $code = self::code($row['product_code'] ?? null);
        if ($source === 'trendyol') {
            $code = self::code($row['ty_code'] ?? null) ?? $code;
            $panel = $excluded ? null : TrendyolPanelWidth::line([
                'width_value' => $row['width_value'], 'height_value' => $row['height_value'], 'measurement_unit' => $row['measurement_unit'], 'quantity' => (int) $row['quantity'],
                'variant' => $row['variant'], 'ty_linked' => (int) $row['ty_linked'] === 1, 'ty_quantity' => $row['ty_quantity'], 'ty_size' => $row['ty_size'],
                'ty_prepared_width' => $row['ty_prepared_width'], 'ty_prepared_height' => $row['ty_prepared_height'],
            ]);
        }
        $category = $catalog->classify(['source_key' => $source, 'product_code' => $code, 'context_kind' => $row['context_kind'], 'ty_prepared_kind' => $row['ty_prepared_kind'] ?? null]);
        $evidence = match (true) {
            $panel !== null && $panel['status'] === TrendyolPanelWidth::VERIFIED => self::EVIDENCE_PANEL,
            $contract['status'] !== MeterContract::MEASURED => self::EVIDENCE_NONE,
            $source === 'b2b' => self::EVIDENCE_B2B,
            str_starts_with((string) ($row['source_event_id'] ?? ''), 'import:') => self::EVIDENCE_IMPORT,
            default => self::EVIDENCE_EVENT,
        };
        return [
            'item_uuid' => (string) $row['item_uuid'], 'line_number' => (int) $row['line_number'], 'source_key' => $source, 'name' => (string) $row['name'],
            'product_code' => $code, 'variant' => $row['variant'], 'quantity' => (int) $row['quantity'], 'excluded' => $excluded,
            'contract' => $contract, 'panel' => $panel, 'evidence' => $evidence] + $category;
    }

    /** A trimmed seller code, or null for an empty value or a Trendyol placeholder. */
    private static function code(mixed $value): ?string
    {
        $code = trim((string) ($value ?? ''));
        return $code === '' || in_array(mb_strtolower($code), self::CODE_PLACEHOLDERS, true) ? null : $code;
    }
}
