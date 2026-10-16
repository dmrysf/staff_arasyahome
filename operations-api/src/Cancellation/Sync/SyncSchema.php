<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation\Sync;

use PDO;
use WeakMap;

/** Whether migration 034 is present on this connection (one cached lookup). Without it no operation can exist. */
final class SyncSchema
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
            self::$ready[$pdo] = $pdo->query("SHOW TABLES LIKE 'order_cancellation_sync_operations'")->fetchColumn() !== false;
        }
        return self::$ready[$pdo];
    }
}
