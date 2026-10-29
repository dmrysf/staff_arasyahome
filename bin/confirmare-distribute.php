<?php

declare(strict_types=1);

// Automatic distribution of Confirmare orders (API 2.38.0). Meant for cron (for example every minute) once a source is
// active; harmless before: without unassigned Confirmare orders it changes nothing.
//
//   php bin/confirmare-distribute.php [--source=trendhome] [--dry-run]
//
// First returns to the pool every pending assignment whose assignee can no longer work on it (scope removed, account,
// department or application inactive). Then assigns unassigned Confirmare orders, nearest due date first and then FIFO,
// to available operators (no opt-in; leave excludes), the least loaded first: the balance target
// ConfirmareService::TARGET_OPEN_PER_OPERATOR never blocks the queue. Suspended sources are held. Finally raises one
// supervisor alert per assignment without progress (never a reassignment). Every change is one short transaction with
// its own audit event. --dry-run only reads. Prints counts only (no customer data, no names). API 2.50.0: for a source
// with ARASYA_SOURCE_FINANCE_TASKS_<SRC>=auto (off by default) it then runs the finance task inventory
// (bin/finance-task-inventory.php --apply).

use Arasya\Operations\Integration\SourceRegistry;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['source:', 'dry-run']);
$source = is_string($options['source'] ?? null) ? $options['source'] : null;
if ($source !== null && preg_match(SourceRegistry::KEY_PATTERN, $source) !== 1) {
    fwrite(STDERR, "Usage: php bin/confirmare-distribute.php [--source=<key>] [--dry-run]\n");
    exit(2);
}
$result = $container->confirmareService()->distribute($source, isset($options['dry-run']));
// API 2.50.0: a source with ARASYA_SOURCE_FINANCE_TASKS_<SRC>=auto also runs the finance task inventory (off by default).
$registry = \Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry::fromConfig($container->config());
$auto = array_values(array_filter($source !== null ? [$source] : $container->config()->sourceKeys, static fn (string $key): bool => $registry->find($key)?->autoFinanceTasks() === true));
if ($auto !== [] && !isset($options['dry-run'])) {
    $tasks = $container->financeTaskInventory()->apply($auto, 'distribute-' . gmdate('YmdHis'));
    $result['financeTasks'] = ['sources' => $auto, 'created' => $tasks['created'], 'resolved' => $tasks['resolved'], 'skipped' => count($tasks['skipped'])];
}
fwrite(STDOUT, json_encode($result + ['dryRun' => isset($options['dry-run'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
exit(0);
