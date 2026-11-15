<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use Arasya\Operations\Support\Clock;
use InvalidArgumentException;
use JsonException;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Loads one shop's catalog categories into `product_catalog_categories` (API 2.73.0, migration 055). Reports only.
 *
 * Input: a read-only shop export, JSON `{"format": "arasya-product-categories", "formatVersion": 1, "source": "trendhome",
 * "generatedAt": "...", "products": [{"code": "...", "name": "...", "category": "Draperii", "subcategory": "...",
 * "rule": "single_main_category|primary_category"}]}`. The shop decides the category (main category = its single
 * non-marketing top-level category, the Yoast primary category between several); products without one are simply absent.
 *
 * The whole file is validated before anything is written. A code listed twice with different categories is ambiguous
 * and left out (reported), never guessed. `--apply` replaces that shop's rows in one transaction; the same file again
 * writes nothing. Dry run by default. No order, item, document, Confirmare or production table is touched.
 */
final readonly class ProductCategoryImport
{
    public const FORMAT = 'arasya-product-categories';
    public const FORMAT_VERSION = 1;
    public const SOURCES = ['trendhome', 'outletperdele'];
    private const MAX_PRODUCTS = 100000;
    private const LOCK = 'arasya_product_category_import';

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /** @return array{dryRun: bool, importRef: string, products: int, ambiguous: int, added: int, changed: int, removed: int, unchanged: int, byCategory: array<string, int>} */
    public function run(string $sourceKey, string $json, bool $dryRun): array
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Invalid product category import context');
        }
        [$rows, $ambiguous] = self::parse($sourceKey, $json);
        $ref = hash('sha256', $json);
        if ((int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK . "', 0)")->fetchColumn() !== 1) {
            throw new RuntimeException('Another product category import is running');
        }
        try {
            $current = [];
            $s = $this->pdo->prepare('SELECT product_code, category_label, subcategory_label, product_name, category_rule FROM product_catalog_categories WHERE source_key = ?');
            $s->execute([$sourceKey]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $current[mb_strtolower((string) $row['product_code'])] = $row;
            }
            $report = ['dryRun' => $dryRun, 'importRef' => $ref, 'products' => count($rows), 'ambiguous' => $ambiguous, 'added' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 0, 'byCategory' => []];
            $write = [];
            foreach ($rows as $key => $row) {
                $report['byCategory'][$row['category']] = ($report['byCategory'][$row['category']] ?? 0) + 1;
                $old = $current[$key] ?? null;
                if ($old === null) {
                    $report['added']++;
                    $write[] = $row;
                } elseif ([$old['category_label'], $old['subcategory_label'], $old['product_name'], $old['category_rule']] !== [$row['category'], $row['subcategory'], $row['name'], $row['rule']]) {
                    $report['changed']++;
                    $write[] = $row;
                } else {
                    $report['unchanged']++;
                }
            }
            $removed = array_diff_key($current, $rows);
            $report['removed'] = count($removed);
            ksort($report['byCategory']);
            if ($dryRun || ($write === [] && $removed === [])) {
                return $report;
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $this->pdo->beginTransaction();
            try {
                $delete = $this->pdo->prepare('DELETE FROM product_catalog_categories WHERE source_key = ? AND product_code = ?');
                foreach ($removed as $old) {
                    $delete->execute([$sourceKey, $old['product_code']]);
                }
                $upsert = $this->pdo->prepare('INSERT INTO product_catalog_categories (source_key, product_code, category_label, subcategory_label, product_name, category_rule, import_ref, imported_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE product_code = VALUES(product_code), category_label = VALUES(category_label), subcategory_label = VALUES(subcategory_label),
                    product_name = VALUES(product_name), category_rule = VALUES(category_rule), import_ref = VALUES(import_ref), imported_at = VALUES(imported_at)');
                foreach ($write as $row) {
                    $upsert->execute([$sourceKey, $row['code'], $row['category'], $row['subcategory'], $row['name'], $row['rule'], $ref, $now]);
                }
                $this->pdo->commit();
            } catch (Throwable $error) {
                $this->pdo->rollBack();
                throw $error;
            }
            return $report;
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
        }
    }

    /** @return array{0: array<string, array{code: string, name: string|null, category: string, subcategory: string|null, rule: string}>, 1: int} */
    public static function parse(string $sourceKey, string $json): array
    {
        if (!in_array($sourceKey, self::SOURCES, true)) {
            throw new InvalidArgumentException('Unknown source');
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The export is not valid JSON');
        }
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ($data['formatVersion'] ?? null) !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('Unsupported export format');
        }
        if (($data['source'] ?? null) !== $sourceKey) {
            throw new InvalidArgumentException('The export belongs to another source');
        }
        $products = $data['products'] ?? null;
        if (!is_array($products) || !array_is_list($products) || count($products) > self::MAX_PRODUCTS) {
            throw new InvalidArgumentException('Invalid product list');
        }
        $text = static function (mixed $value, int $max, bool $required, string $field, int $index): ?string {
            if ($value === null && !$required) {
                return null;
            }
            if (!is_string($value) || ($value = trim(preg_replace('/\s+/u', ' ', $value) ?? '')) === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                if (!$required && is_string($value) && trim($value) === '') {
                    return null;
                }
                throw new InvalidArgumentException("Invalid {$field} at product {$index}");
            }
            return $value;
        };
        $rows = []; $conflicts = [];
        foreach ($products as $index => $product) {
            if (!is_array($product) || array_diff(array_keys($product), ['code', 'name', 'category', 'subcategory', 'rule']) !== []) {
                throw new InvalidArgumentException("Invalid product {$index}");
            }
            $row = ['code' => (string) $text($product['code'] ?? null, 120, true, 'code', $index), 'name' => $text($product['name'] ?? null, 255, false, 'name', $index),
                'category' => (string) $text($product['category'] ?? null, 120, true, 'category', $index), 'subcategory' => $text($product['subcategory'] ?? null, 255, false, 'subcategory', $index),
                'rule' => $product['rule'] ?? null];
            if (!in_array($row['rule'], ['single_main_category', 'primary_category'], true)) {
                throw new InvalidArgumentException("Invalid rule at product {$index}");
            }
            $key = mb_strtolower($row['code']);
            if (isset($conflicts[$key])) {
                continue;
            }
            if (isset($rows[$key]) && $rows[$key]['category'] !== $row['category']) {
                $conflicts[$key] = true;
                unset($rows[$key]);
                continue;
            }
            $rows[$key] ??= $row;
        }
        ksort($rows);
        return [$rows, count($conflicts)];
    }
}
