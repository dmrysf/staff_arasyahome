<?php
declare(strict_types=1);
/**
 * Production Intelligence V2 (API 2.70.0): imports the line pricing basis of orders Arasya already holds from a shop
 * export (YD SOFT 2.30.0 `wp yd-arasya meter-basis export`). Facts only: no order, item, version, QR, document or
 * Confirmare is touched, and a fact a real order event stated is never overwritten. Dry run by default.
 *
 *   php bin/meter-facts-import.php --source=trendhome|outletperdele --file=<export.json> [--apply]
 *
 * After `--apply`, the automatic run (`meter-ledger.php --changed --apply`) or the backfill (`meter-ledger.php --apply`)
 * books the imported lines at their order's own sale date. Prints counts only, never an order.
 */
$container = require __DIR__ . '/cli-bootstrap.php';
try {
    $apply = false; $source = null; $file = null;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--apply') { $apply = true; continue; }
        if (preg_match('/^--source=([a-z]+)$/D', $argument, $m) === 1) { $source = $m[1]; continue; }
        if (preg_match('/^--file=(.+)$/D', $argument, $m) === 1) { $file = $m[1]; continue; }
        throw new InvalidArgumentException('Use --source=trendhome|outletperdele --file=<export.json> [--apply]');
    }
    if ($source === null || $file === null) {
        throw new InvalidArgumentException('--source and --file are required');
    }
    if (!is_file($file) || !is_readable($file) || filesize($file) > 64 * 1024 * 1024) {
        throw new InvalidArgumentException('The export file cannot be read');
    }
    $import = new Arasya\Operations\Intelligence\MeterFactImport($container->pdo(), $container->clock());
    $report = $import->run($source, (string) file_get_contents($file), !$apply);
    if ($apply && $report['recorded'] > 0) {
        (new Arasya\Operations\Iam\IamAuditLogger($container->pdo()))->record(null, 'production_intelligence.meter_facts_imported', 'order_source', $source, $source,
            ['origin' => $report['origin'], 'orders' => $report['orders'], 'recorded' => $report['recorded'], 'alreadyStated' => $report['alreadyStated'],
             'ordersMissing' => $report['ordersMissing'], 'itemsMissing' => $report['itemsMissing'], 'byKind' => $report['byKind']],
            'cli-' . bin2hex(random_bytes(8)), $container->clock()->now()->format('Y-m-d H:i:s.u'));
    }
    fwrite(STDOUT, ($apply ? 'APPLIED' : 'DRY RUN (nothing written)') . " {$source}: orders={$report['orders']} ordersNotInArasya={$report['ordersMissing']} lines={$report['items']}"
        . " recorded={$report['recorded']} alreadyStated={$report['alreadyStated']} linesNotOnOrder={$report['itemsMissing']} byKind=" . json_encode($report['byKind'])
        . " origin={$report['origin']}. Orders, items, QR, documents and Confirmare unchanged.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Meter fact import failed (' . get_class($error) . '): ' . ($error instanceof InvalidArgumentException || $error instanceof RuntimeException ? rtrim($error->getMessage(), '.') : 'see logs') . ". Nothing was written by the failing order; no secrets are printed.\n");
    exit(1);
}
