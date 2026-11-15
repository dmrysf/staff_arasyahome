<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use PDO;
use PDOException;

/**
 * Product category of a commercial line for the CEO reports (API 2.73.0). Read only.
 *
 * The category is never guessed from a product title. Sources, in order:
 * - Trendhome / Outlet Perdele: the shop catalog category of the line's product code (`product_catalog_categories`,
 *   imported from the shop's own WooCommerce categories by bin/product-category-import.php; main category = the single
 *   non-marketing top-level category, the Yoast primary category decides between several);
 * - Trendyol: the shop catalog category when the seller code matches exactly one product category in the shop catalogs,
 *   otherwise the kind the Trendyol employee prepared (curtain -> Perdele, drapery -> Draperii, other -> Alte);
 * - B2B: the production kind the employee entered on the line (curtain / drapery / other), when present.
 * Anything else is "unknown", reported as such and never folded into another category.
 *
 * Migration 055 may not be applied yet: every catalog lookup then answers unknown and the reports keep working.
 */
final class ProductCatalog
{
    public const UNKNOWN = 'unknown';
    public const SOURCE_CATALOG = 'shop_catalog';
    public const SOURCE_CATALOG_MATCH = 'shop_catalog_code_match';
    public const SOURCE_PREPARED = 'trendyol_prepared_kind';
    public const SOURCE_B2B = 'b2b_production_kind';
    public const SOURCE_NONE = 'none';
    private const KIND_LABEL = ['curtain' => 'Perdele', 'drapery' => 'Draperii', 'other' => 'Alte'];

    /** @var array<string, array{label: string, sub: string|null, name: string|null}> "source\x1fcode" => category */
    private array $byCode = [];
    /** @var array<string, list<string>> lower(code) => distinct category labels over both shops */
    private array $labelsByCode = [];
    private bool $available = false;

    public function __construct(PDO $pdo)
    {
        try {
            $rows = $pdo->query('SELECT source_key, product_code, category_label, subcategory_label, product_name FROM product_catalog_categories')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $missing) {
            if ((int) ($missing->errorInfo[1] ?? 0) === 1146) {
                return;
            }
            throw $missing;
        }
        $this->available = true;
        foreach ($rows as $row) {
            $code = mb_strtolower(trim((string) $row['product_code']));
            $this->byCode[$row['source_key'] . "\x1f" . $code] = ['label' => (string) $row['category_label'], 'sub' => $row['subcategory_label'], 'name' => $row['product_name']];
            $this->labelsByCode[$code][(string) $row['category_label']] = true;
        }
    }

    public function available(): bool
    {
        return $this->available;
    }

    /**
     * @param array{source_key: string, product_code: string|null, context_kind?: string|null, ty_prepared_kind?: string|null} $line
     * @return array{category: string, subcategory: string|null, categorySource: string, catalogName: string|null}
     */
    public function classify(array $line): array
    {
        $code = mb_strtolower(trim((string) ($line['product_code'] ?? '')));
        $none = ['category' => self::UNKNOWN, 'subcategory' => null, 'categorySource' => self::SOURCE_NONE, 'catalogName' => null];
        switch ($line['source_key']) {
            case 'trendhome':
            case 'outletperdele':
                $hit = $code === '' ? null : ($this->byCode[$line['source_key'] . "\x1f" . $code] ?? null);
                return $hit === null ? $none : ['category' => $hit['label'], 'subcategory' => $hit['sub'], 'categorySource' => self::SOURCE_CATALOG, 'catalogName' => $hit['name']];
            case 'trendyol':
                $labels = $code === '' ? [] : array_keys($this->labelsByCode[$code] ?? []);
                if (count($labels) === 1) {
                    return ['category' => $labels[0], 'subcategory' => null, 'categorySource' => self::SOURCE_CATALOG_MATCH, 'catalogName' => null];
                }
                $kind = $line['ty_prepared_kind'] ?? null;
                return isset(self::KIND_LABEL[$kind]) ? ['category' => self::KIND_LABEL[$kind], 'subcategory' => null, 'categorySource' => self::SOURCE_PREPARED, 'catalogName' => null] : $none;
            case 'b2b':
                $kind = $line['context_kind'] ?? null;
                return isset(self::KIND_LABEL[$kind]) ? ['category' => self::KIND_LABEL[$kind], 'subcategory' => null, 'categorySource' => self::SOURCE_B2B, 'catalogName' => null] : $none;
            default:
                return $none;
        }
    }
}
