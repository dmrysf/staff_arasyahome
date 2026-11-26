<?php

declare(strict_types=1);

// API 2.82.0 (Operation Simplification V1): fills the operator desk's display copy (order total, lines, warnings) of the
// pending Confirmare orders, so the desk list never asks a shop once per row. Meant for cron (for example every 10 minutes).
//
//   php bin/confirmare-display.php [--max=40] [--pause-ms=1500]
//
// Reads the shop's order view, one call at a time with a pause, for pending orders without a copy, with a copy read on other
// prices, or older than 6 hours, oldest first. Audited in order_confirmation_source_reads under the root identity. A shop
// that does not answer is skipped for the rest of the run (and the per-host read breaker of API 2.81.0 still applies).
// Prints counts only (no amounts, no customer data).

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['max:', 'pause-ms:']);
$max = isset($options['max']) && is_string($options['max']) && ctype_digit($options['max']) ? max(1, min(500, (int) $options['max'])) : 40;
$pause = isset($options['pause-ms']) && is_string($options['pause-ms']) && ctype_digit($options['pause-ms']) ? min(10000, (int) $options['pause-ms']) : 1500;
$pdo = $container->pdo();
$root = $pdo->query('SELECT employee_uuid FROM system_root_identity LIMIT 1')->fetchColumn();
if (!is_string($root) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $root) !== 1) {
    fwrite(STDERR, "The root identity is unavailable.\n");
    exit(1);
}
if ((int) $pdo->query("SELECT GET_LOCK('arasya_confirmare_display', 0)")->fetchColumn() !== 1) {
    fwrite(STDOUT, "confirmare-display: another run is active\n");
    exit(0);
}
try {
    $started = microtime(true);
    $result = $container->confirmareSourceAccess()->refreshDisplay($root, $max, $pause, 'confirmare-display-' . gmdate('YmdHis'));
    fwrite(STDOUT, json_encode(['at' => gmdate('c'), 'seconds' => round(microtime(true) - $started, 1)] + $result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} finally {
    $pdo->query("SELECT RELEASE_LOCK('arasya_confirmare_display')");
}
exit(0);
