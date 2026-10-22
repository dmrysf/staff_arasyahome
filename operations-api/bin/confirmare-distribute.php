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
// its own audit event. --dry-run only reads. Prints counts only (no customer data, no names).

use Arasya\Operations\Integration\SourceRegistry;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['source:', 'dry-run']);
$source = is_string($options['source'] ?? null) ? $options['source'] : null;
if ($source !== null && preg_match(SourceRegistry::KEY_PATTERN, $source) !== 1) {
    fwrite(STDERR, "Usage: php bin/confirmare-distribute.php [--source=<key>] [--dry-run]\n");
    exit(2);
}
$result = $container->confirmareService()->distribute($source, isset($options['dry-run']));
fwrite(STDOUT, json_encode($result + ['dryRun' => isset($options['dry-run'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
exit(0);
