<?php

declare(strict_types=1);

// The 1 November cutoff (owner decision 2026-11-02, API 2.55.0): Trend Home and Outlet Perdele Confirmare orders submitted
// before 2026-11-01 00:00 Europe/Bucharest that nobody has worked yet leave Confirmare and return to the established
// WordPress-managed flow (ConfirmareCutoff); worked orders keep everything. Read-only unless --apply.
//
//   php bin/confirmare-cutoff.php --source=trendhome|outletperdele [--apply]
//
// Then run the shop's side with the printed order numbers (`wp yd-arasya confirmare withdraw`, YD SOFT 2.26.0) so the
// shop treats them as established orders again (CurieRO AWB, no source access). Prints order numbers and counts only.

use Arasya\Operations\Confirmare\ConfirmareCutoff;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['source:', 'apply']);
$source = is_string($options['source'] ?? null) ? $options['source'] : '';
if (!in_array($source, ConfirmareCutoff::SOURCES, true)) {
    fwrite(STDERR, "Usage: php bin/confirmare-cutoff.php --source=trendhome|outletperdele [--apply]\n");
    exit(2);
}
$result = (new ConfirmareCutoff($container->pdo(), $container->clock()))->run($source, isset($options['apply']), new DateTimeImmutable(ConfirmareCutoff::CUTOFF_UTC));
fwrite(STDOUT, json_encode($result + ['apply' => isset($options['apply'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
exit(0);
