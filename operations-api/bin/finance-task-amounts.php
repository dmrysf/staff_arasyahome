<?php

declare(strict_types=1);

// API 2.77.9 (owner 2026-11-25, Contabilitate desk): fills finance_task_amounts, the advance amount the finance employee's
// task list shows, so the list never asks the shop once per row. Meant for cron (for example every 10 minutes).
//
//   php bin/finance-task-amounts.php [--max=60] [--pause-ms=1500]
//
// Reads from the shop, one call at a time with a pause, the advance of the open advance tasks whose stored amount is
// missing or older than 30 minutes, oldest task first. A read only: nothing is decided, recorded or sent. Each read is
// audited in order_finance_reads under the root identity (system_root_identity). A shop that does not answer is skipped
// and asked again on the next run. Prints counts only (no amounts, no customer data).

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['max:', 'pause-ms:']);
$max = isset($options['max']) && is_string($options['max']) && ctype_digit($options['max']) ? max(1, min(500, (int) $options['max'])) : 60;
$pause = isset($options['pause-ms']) && is_string($options['pause-ms']) && ctype_digit($options['pause-ms']) ? min(10000, (int) $options['pause-ms']) : 1500;
$root = $container->pdo()->query('SELECT employee_uuid FROM system_root_identity LIMIT 1')->fetchColumn();
if (!is_string($root) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $root) !== 1) {
    fwrite(STDERR, "The root identity is unavailable.\n");
    exit(1);
}
$started = microtime(true);
$result = $container->financeVerificationRequests()->refreshAmounts($root, $max, $pause, 'finance-amounts-' . gmdate('YmdHis'));
fwrite(STDOUT, json_encode(['at' => gmdate('c'), 'seconds' => round(microtime(true) - $started, 1)] + $result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
exit(0);
