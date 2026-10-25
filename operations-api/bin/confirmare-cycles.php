<?php

declare(strict_types=1);

/**
 * The Confirmare cycle worker (API 2.46.0): advance proforma reminders after 24 h and 72 h, the 120 h expiry with the
 * bank check, the cancellation in SmartBill and the order's cancellation request (ConfirmareCycles). Cron, every 5
 * minutes. One run at a time (server-wide advisory lock); each cycle is also claimed on its own row.
 *
 *   php bin/confirmare-cycles.php [--limit=100]
 *
 * Prints counts only: no order number, customer data or amount.
 */

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['limit:']);
$limit = isset($options['limit']) && is_string($options['limit']) && ctype_digit($options['limit']) ? (int) $options['limit'] : 100;
$pdo = $container->pdo();
if ((int) $pdo->query("SELECT GET_LOCK('arasya_confirmare_cycles', 0)")->fetchColumn() !== 1) {
    fwrite(STDOUT, "confirmare-cycles: another run is active\n");
    exit(0);
}
try {
    $cycles = $container->confirmareCycles();
    if (!$cycles->installed()) {
        fwrite(STDOUT, "confirmare-cycles: migration 043 is not applied; nothing to do\n");
        exit(0);
    }
    $result = $cycles->run($limit, 'cli-confirmare-cycles-' . bin2hex(random_bytes(6)));
    // Reactivated orders whose shop event arrived: their cancellation hold is released (ConfirmareOperations).
    $released = $container->confirmareOperations()->settleReactivations($limit);
    fwrite(STDOUT, sprintf("confirmare-cycles: seen %d, advanced %d, skipped %d, reactivations released %d\n", $result['seen'], $result['advanced'], $result['skipped'], $released));
} finally {
    $pdo->query("SELECT RELEASE_LOCK('arasya_confirmare_cycles')");
}
