<?php

declare(strict_types=1);

// API 2.80.0 (owner 2026-11-25): the system's payment tracking for finance (FinanceVerificationRequests::track). Meant for
// cron (for example every 15 minutes, 07:00-20:00 Bucharest):
//
//   php bin/finance-payment-tracking.php [--apply] [--max=40] [--pause-ms=1500]
//
// - a payment not found (task closed as not received) is looked for again the next morning: a new task for finance;
// - an order whose advance proforma was really sent and that never had a finance task gets one, so the operator no
//   longer sends it to finance by hand.
// Without --apply it only counts (no shop call, no write). With --apply each candidate is read from the shop (one call at
// a time, with a pause), audited in order_finance_reads under the root identity, and created through the same rules as
// an operator's request (one open task per order; a covered, cancelled or unknown order is refused). Prints counts only.

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['apply', 'max:', 'pause-ms:']);
$apply = isset($options['apply']);
$max = isset($options['max']) && is_string($options['max']) && ctype_digit($options['max']) ? max(1, min(200, (int) $options['max'])) : 40;
$pause = isset($options['pause-ms']) && is_string($options['pause-ms']) && ctype_digit($options['pause-ms']) ? min(10000, (int) $options['pause-ms']) : 1500;
$pdo = $container->pdo();
$root = $pdo->query('SELECT employee_uuid FROM system_root_identity LIMIT 1')->fetchColumn();
if (!is_string($root) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $root) !== 1) {
    fwrite(STDERR, "The root identity is unavailable.\n");
    exit(1);
}
if ((int) $pdo->query("SELECT GET_LOCK('arasya_finance_payment_tracking', 0)")->fetchColumn() !== 1) {
    fwrite(STDOUT, "finance-payment-tracking: another run is active\n");
    exit(0);
}
try {
    $started = microtime(true);
    $result = $container->financeVerificationRequests()->track($root, $max, $pause, 'finance-tracking-' . gmdate('YmdHis'), $apply);
    fwrite(STDOUT, json_encode(['at' => gmdate('c'), 'apply' => $apply, 'seconds' => round(microtime(true) - $started, 1)] + $result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} finally {
    $pdo->query("SELECT RELEASE_LOCK('arasya_finance_payment_tracking')");
}
exit(0);
