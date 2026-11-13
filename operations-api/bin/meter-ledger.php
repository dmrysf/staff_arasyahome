<?php
declare(strict_types=1);
/**
 * Production Intelligence V2 (API 2.70.0): brings the insert-only sold-meter ledger in line with the sold-meter contract.
 * Dry run by default: prints what would be written and writes nothing. `--apply` writes the difference entries only;
 * canonical tables are only read (never locked) and a repeated run writes nothing new.
 *
 *   php bin/meter-ledger.php [--apply] [--batch=1..500] [--order=<order_uuid>]   every order (the backfill), or one order
 *   php bin/meter-ledger.php --changed [--apply]                                 the automatic incremental run (cron)
 *
 * `--changed` visits only the orders changed since its stored watermark. Its first `--apply` run stores the watermark and
 * visits nothing, so scheduling it never backfills history. A deployment never schedules it: the cron line is a separate,
 * approved hosting change.
 */
$container = require __DIR__ . '/cli-bootstrap.php';
try {
    $apply = false; $batch = 50; $order = null; $changed = false;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--apply') { $apply = true; continue; }
        if ($argument === '--changed') { $changed = true; continue; }
        if (preg_match('/^--batch=([0-9]{1,3})$/D', $argument, $m) === 1) { $batch = (int) $m[1]; continue; }
        if (preg_match('/^--order=([0-9a-f-]{36})$/D', $argument, $m) === 1) { $order = $m[1]; continue; }
        throw new InvalidArgumentException('Use [--apply] [--batch=1..500] [--order=<order_uuid>] or --changed [--apply]');
    }
    if ($changed && ($order !== null || $batch !== 50)) {
        throw new InvalidArgumentException('--changed takes only --apply');
    }
    $ledger = new Arasya\Operations\Intelligence\SoldMeterLedger($container->pdo(), $container->clock());
    $report = $changed ? $ledger->syncChanged(!$apply) : $ledger->sync(!$apply, $batch, $order);
    $net = array_map(static fn (int $units): string => Arasya\Operations\Intelligence\MeterContract::meters($units), $report['netUnits']);
    $window = !$changed ? '' : ($report['initialised'] ? ' watermark initialised (no order visited, history is the separate backfill)' : " since={$report['since']}") . " watermark={$report['watermark']}";
    fwrite(STDOUT, ($apply ? 'APPLIED' : 'DRY RUN (nothing written)') . ($changed ? ' changed' : '') . ": orders={$report['orders']} entries={$report['entries']} byReason=" . json_encode($report['byReason'])
        . ' net=' . json_encode($net) . $window . ". Canonical tables unchanged.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Sold-meter ledger run failed (' . get_class($error) . '): ' . ($error instanceof InvalidArgumentException || $error instanceof RuntimeException ? rtrim($error->getMessage(), ".") : 'see logs') . ". No secrets are printed.\n");
    exit(1);
}
