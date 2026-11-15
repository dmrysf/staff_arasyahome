<?php
declare(strict_types=1);
/**
 * CEO Production Intelligence (API 2.73.0, migration 055): loads one shop's catalog categories from a read-only shop
 * export (format arasya-product-categories v1) into product_catalog_categories. Reports read it to group lines by
 * category; no order, item, document, Confirmare or production table is touched. Dry run by default.
 *
 *   php bin/product-category-import.php --source=trendhome|outletperdele --file=<export.json> [--apply]
 *
 * Prints counts only.
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
    if (!is_file($file) || !is_readable($file) || filesize($file) > 32 * 1024 * 1024) {
        throw new InvalidArgumentException('The export file cannot be read');
    }
    $report = (new Arasya\Operations\Intelligence\ProductCategoryImport($container->pdo(), $container->clock()))->run($source, (string) file_get_contents($file), !$apply);
    if ($apply && ($report['added'] + $report['changed'] + $report['removed']) > 0) {
        (new Arasya\Operations\Iam\IamAuditLogger($container->pdo()))->record(null, 'production_intelligence.product_categories_imported', 'order_source', $source, $source,
            ['importRef' => $report['importRef'], 'products' => $report['products'], 'added' => $report['added'], 'changed' => $report['changed'], 'removed' => $report['removed'], 'ambiguous' => $report['ambiguous']],
            'cli-' . bin2hex(random_bytes(8)), $container->clock()->now()->format('Y-m-d H:i:s.u'));
    }
    fwrite(STDOUT, ($apply ? 'APPLIED' : 'DRY RUN (nothing written)') . " {$source}: products={$report['products']} ambiguous={$report['ambiguous']} added={$report['added']}"
        . " changed={$report['changed']} removed={$report['removed']} unchanged={$report['unchanged']} byCategory=" . json_encode($report['byCategory'], JSON_UNESCAPED_UNICODE)
        . " importRef={$report['importRef']}. Orders, items, documents and production unchanged.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Product category import failed (' . get_class($error) . '): ' . ($error instanceof InvalidArgumentException || $error instanceof RuntimeException ? rtrim($error->getMessage(), '.') : 'see logs') . ". Nothing was written; no secrets are printed.\n");
    exit(1);
}
