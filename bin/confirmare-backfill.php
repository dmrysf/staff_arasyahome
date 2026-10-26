<?php

declare(strict_types=1);

// The 22 October backfill (owner decision K-06, API 2.46.0): orders a shop submitted from 2026-10-22 00:00
// Europe/Bucharest until its first Confirmare activation, still before production, are taken into Confirmare; orders
// already in production keep their stage and documents (ConfirmareBackfill). Read-only unless --apply.
//
//   php bin/confirmare-backfill.php --source=trendhome|outletperdele [--apply] [--limit=500]
//
// Run the shop's own backfill first (`wp yd-arasya backfill --apply`) so every order of the window reached Arasya.
// Prints counts and order numbers only: no customer data, no amounts.

use Arasya\Operations\Confirmare\ConfirmareBackfill;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['source:', 'apply', 'limit:']);
$source = is_string($options['source'] ?? null) ? $options['source'] : '';
if (!in_array($source, ['trendhome', 'outletperdele'], true)) {
    fwrite(STDERR, "Usage: php bin/confirmare-backfill.php --source=trendhome|outletperdele [--apply] [--limit=500]\n");
    exit(2);
}
$limit = isset($options['limit']) && is_string($options['limit']) && ctype_digit($options['limit']) ? (int) $options['limit'] : 500;
$result = (new ConfirmareBackfill($container->pdo(), $container->clock()))->run($source, isset($options['apply']), $limit);
fwrite(STDOUT, json_encode($result + ['apply' => isset($options['apply'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
exit(0);
