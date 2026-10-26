<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation;

use PDO;
use WeakMap;

/**
 * Whether migration 033 is present on this connection. Only the read-only management views use it, so a partially
 * migrated test or rehearsal database (a schema without 033 cannot hold a stop) keeps answering; every production
 * command requires 033 and fails closed without it. One cached lookup per connection.
 */
final class CancellationSchema
{
    /** @var WeakMap<PDO, bool>|null */
    private static ?WeakMap $ready = null;

    private function __construct()
    {
    }

    public static function ready(PDO $pdo): bool
    {
        self::$ready ??= new WeakMap();
        if (!isset(self::$ready[$pdo])) {
            self::$ready[$pdo] = $pdo->query("SHOW COLUMNS FROM operational_orders LIKE 'production_stopped_at'")->fetchColumn() !== false
                && $pdo->query("SHOW TABLES LIKE 'order_cancellation_controls'")->fetchColumn() !== false;
        }
        return self::$ready[$pdo];
    }
}
