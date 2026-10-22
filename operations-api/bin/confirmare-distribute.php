<?php

declare(strict_types=1);

// Automatic distribution of Confirmare orders (API 2.38.0). Meant for cron (for example every minute) once a source is
// active; harmless before: without unassigned Confirmare orders it changes nothing.
//
//   php bin/confirmare-distribute.php [--source=trendhome] [--dry-run]
//
// First returns to the pool every pending assignment whose assignee can no longer work on it (scope removed, account,
// department or application inactive). Then assigns unassigned Confirmare orders, oldest first, to operators who hold
// the source's `operate` scope and accept new orders, fewest open assignments first, at most
// ConfirmareService::MAX_OPEN_PER_OPERATOR each. Every change is one short transaction with its own audit event.
// --dry-run only reads. Prints counts only (no customer data, no names).

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
