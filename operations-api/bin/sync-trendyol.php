<?php

declare(strict_types=1);

// Trendyol intake synchronization (cron through scripts/trendyol-intake-active.sh, never a web request).
// Reads shipment packages with GET requests only and writes them into the intake inbox; it never creates a
// production order, a QR or a document. It does nothing, and calls nothing, unless credentials are configured,
// ARASYA_TRENDYOL_INTAKE is 'enabled' and `bin/trendyol-intake.php activate` recorded a baseline.
//
//   php bin/sync-trendyol.php              modification-time synchronization (30-minute overlap)
//   php bin/sync-trendyol.php --reconcile  re-read known pending and recently released packages by ID (GET only)

$container = require __DIR__ . '/cli-bootstrap.php';
$config = $container->config();
$reconcile = in_array('--reconcile', array_slice($argv, 1), true);
if ($config->trendyol === null) {
    fwrite(STDOUT, "TRENDYOL_NOT_CONFIGURED\n");
    exit(0);
}
$runner = $reconcile ? $container->trendyolReconciler() : $container->trendyolIntakeSynchronizer();
if ($runner === null) {
    fwrite(STDOUT, "TRENDYOL_INTAKE_DISABLED\n");
    exit(0);
}
if ($container->trendyolIntakeState()->read()['status'] !== 'active') {
    fwrite(STDOUT, "TRENDYOL_INTAKE_NOT_ACTIVE\n");
    exit(0);
}
try {
    $counts = $runner->run();
} catch (RuntimeException $error) {
    $code = preg_match('/^TRENDYOL_[A-Z_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'TRENDYOL_SYNC_FAILED';
    fwrite(STDERR, $code . "\n");
    exit(1);
}
if ($reconcile) {
    fwrite(STDOUT, sprintf(
        "TRENDYOL_RECONCILE checked=%d updated=%d unchanged=%d missing=%d rejected=%d requests=%d\n",
        $counts['checked'], $counts['updated'], $counts['unchanged'], $counts['missing'], $counts['rejected'], $counts['requests'],
    ));
    exit(0);
}
fwrite(STDOUT, sprintf(
    "TRENDYOL_INTAKE received=%d updated=%d unchanged=%d ignored=%d deferred=%d rejected=%d pages=%d%s\n",
    $counts['received'], $counts['updated'], $counts['unchanged'], $counts['ignored'], $counts['deferred'], $counts['rejected'], $counts['pages'], $counts['truncated'] ? ' truncated=1' : '',
));
