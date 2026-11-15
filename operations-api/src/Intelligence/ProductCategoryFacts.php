<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use Arasya\Operations\Order\SourceOrderSnapshot;
use PDO;
use PDOException;

/**
 * Keeps `product_catalog_categories` (migration 055) current from the orders themselves (API 2.74.0, owner request
 * 2026-11-15): every web shop order event that carries `order.items[].category` (orderFacts `productCategory`, YD SOFT
 * 2.31.0) states the shop catalog category of its product codes, by the same rule as the catalog export. A new product
 * gets its category with its first order and a category moved in WooCommerce follows with the next order of that product.
 * No cron, no extra request, no new endpoint.
 *
 * Runs inside the projection transaction, like MeterFacts, and deliberately outside every production path: the fact is
 * not part of the projection hash, a line, a printed document or a Confirmare clearance, so receiving it never changes an
 * order version or makes a document stale. Only reports read the table.
 *
 * - A new code is added; a code whose category, subcategory or rule changed is updated, but only by a NEWER source
 *   change: `imported_at` holds the instant the stored category is valid from (the source `changedAt` of the order event
 *   that stated it, or the import time of a catalog import). A delayed or replayed older event (a queue that drained out
 *   of order, an event retried after an Arasya rollback, an old order edited without a new event) never turns a category
 *   back. Equal instants keep the stored category. The guard is in the upsert itself, so concurrent orders cannot race.
 *   An unchanged code is not written at all (no row lock churn).
 * - The product name comes from the catalog export and is kept; an order only names a code it adds.
 * - `import_ref` = sha256 of the source event, so each row says which order event last changed it.
 * - Only Trendhome and Outlet Perdele (the table's CHECK). Before migration 055 nothing is stored (1146 ignored).
 * - A full catalog import (bin/product-category-import.php) still replaces a shop's rows with that export: run it from a
 *   fresh export only, never from an older file.
 */
final readonly class ProductCategoryFacts
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param string $nowSql the receipt time (unused for ordering: only the source change time orders categories) */
    public function record(SourceOrderSnapshot $snapshot, string $nowSql): void
    {
        if ($snapshot->productCategories === null || $snapshot->productCategories === [] || !in_array($snapshot->sourceKey, ProductCategoryImport::SOURCES, true)) {
            return;
        }
        try {
            $upsert = $this->pdo->prepare(
                'INSERT INTO product_catalog_categories (source_key, product_code, category_label, subcategory_label, product_name, category_rule, import_ref, imported_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    import_ref = IF(VALUES(imported_at) > imported_at, VALUES(import_ref), import_ref),
                    category_label = IF(VALUES(imported_at) > imported_at, VALUES(category_label), category_label),
                    subcategory_label = IF(VALUES(imported_at) > imported_at, VALUES(subcategory_label), subcategory_label),
                    category_rule = IF(VALUES(imported_at) > imported_at, VALUES(category_rule), category_rule),
                    imported_at = IF(VALUES(imported_at) > imported_at, VALUES(imported_at), imported_at)'
            );
            $current = $this->current($snapshot->sourceKey, array_column($snapshot->productCategories, 'code'));
            $ref = hash('sha256', 'order-event' . "\x1f" . $snapshot->sourceKey . "\x1f" . $snapshot->sourceEventId);
            // The instant the stated category is valid from: the source change of this event (never the receipt time).
            $validFrom = $snapshot->sourceChangedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            foreach ($snapshot->productCategories as $category) {
                $old = $current[mb_strtolower($category['code'])] ?? null;
                if ($old !== null && ([$old['category_label'], $old['subcategory_label'], $old['category_rule']] === [$category['label'], $category['subcategory'], $category['rule']]
                    || (string) $old['imported_at'] >= $validFrom)) {
                    continue;
                }
                $upsert->execute([$snapshot->sourceKey, $category['code'], $category['label'], $category['subcategory'], mb_substr($category['name'], 0, 255), $category['rule'], $ref, $validFrom]);
            }
        } catch (PDOException $error) {
            // 1146: migration 055 is not applied; the category is report evidence only and never blocks ingestion.
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) {
                throw $error;
            }
        }
    }

    /** @param list<string> $codes @return array<string, array<string, mixed>> lower(code) => row */
    private function current(string $sourceKey, array $codes): array
    {
        $marks = implode(',', array_fill(0, count($codes), '?'));
        $s = $this->pdo->prepare("SELECT product_code, category_label, subcategory_label, category_rule, imported_at FROM product_catalog_categories WHERE source_key = ? AND product_code IN ({$marks})");
        $s->execute([$sourceKey, ...$codes]);
        $rows = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[mb_strtolower((string) $row['product_code'])] = $row;
        }
        return $rows;
    }
}
