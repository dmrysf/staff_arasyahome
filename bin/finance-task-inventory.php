<?php

declare(strict_types=1);

// Finance task inventory (API 2.50.0, owner decision 2026-10-29). Read-only by default.
//
//   php bin/finance-task-inventory.php [--source=trendhome] [--report]       what a run would do (dry run), and with
//                                                                              --report the reconciliation of the covered
//                                                                              orders (orders submitted from 2026-10-22
//                                                                              00:00 Europe/Bucharest)
//   php bin/finance-task-inventory.php [--source=trendhome] --apply --yes     creates the planned tasks and resolves the
//                                                                              cleared ones
//
// It turns the existing open finance work of the covered orders into tasks assigned to named employees: a bank transfer
// recorded through Arasya that waits for its second employee (second_check, assigned to its recorder, checked by another
// employee with `approve`) and an exceptional money state of a Confirmare order (exception). Nothing from a guess: a
// transfer without an Arasya record, an order outside the scope or without submission evidence, an order outside
// Confirmare with a money attention and an order with an open task are listed as skipped with their reason, for a manual
// review. It never writes the shop, never changes a finance action, an order, a document, an AWB, a production state or
// a cohort, and never deletes an earlier decision. Idempotent. Prints order numbers and codes only (no customer data).
// Applying it on production is a separate owner authorization.

use Arasya\Operations\Confirmare\FinanceTaskScope;
use Arasya\Operations\Integration\SourceRegistry;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['source:', 'report', 'apply', 'yes']);
$source = is_string($options['source'] ?? null) ? $options['source'] : null;
if (($source !== null && preg_match(SourceRegistry::KEY_PATTERN, $source) !== 1) || (isset($options['apply']) && !isset($options['yes']))) {
    fwrite(STDERR, "Usage: php bin/finance-task-inventory.php [--source=<key>] [--report] [--apply --yes]\n");
    exit(2);
}
$sources = $source !== null ? [$source] : array_values(array_intersect(['trendhome', 'outletperdele'], $container->config()->sourceKeys));
$inventory = $container->financeTaskInventory();
$result = ['scopeFromUtc' => FinanceTaskScope::FROM_UTC, 'sources' => $sources, 'dryRun' => !isset($options['apply'])];
if (isset($options['report'])) {
    $result['report'] = $inventory->report($sources);
}
if (isset($options['apply'])) {
    $result += $inventory->apply($sources, 'cli-' . gmdate('YmdHis'));
} else {
    $result['plan'] = $inventory::publicPlan($inventory->plan($sources));
}
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
exit(0);
