<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use PDO;

/** The shop sources the root intervention desk acts on (API 2.57.0): the active signed WooCommerce sources, never B2B or Trendyol. */
final class RootInterventionSources
{
    /** @return list<string> */
    public static function of(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT source_key FROM order_sources WHERE status = 'active' AND source_key NOT IN ('b2b', 'trendyol') ORDER BY source_key")->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }
}
