<?php

declare(strict_types=1);

// Trendyol intake synchronization (cron through scripts/trendyol-intake-active.sh, never a web request).
// Reads shipment packages with GET requests only and writes them into the intake inbox; it never creates a
// production order, a QR or a document. It does nothing, and calls nothing, unless credentials are configured,
// ARASYA_TRENDYOL_INTAKE is 'enabled' and `bin/trendyol-intake.php activate` recorded a baseline.

$container = require __DIR__ . '/cli-bootstrap.php';
$config = $container->config();
if ($config->trendyol === null) {
    fwrite(STDOUT, "TRENDYOL_NOT_CONFIGURED\n");
    exit(0);
}
$synchronizer = $container->trendyolIntakeSynchronizer();
if ($synchronizer === null) {
    fwrite(STDOUT, "TRENDYOL_INTAKE_DISABLED\n");
    exit(0);
}
if ($container->trendyolIntakeState()->read()['status'] !== 'active') {
    fwrite(STDOUT, "TRENDYOL_INTAKE_NOT_ACTIVE\n");
    exit(0);
}
try {
    $counts = $synchronizer->run();
} catch (RuntimeException $error) {
    $code = preg_match('/^TRENDYOL_[A-Z_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'TRENDYOL_SYNC_FAILED';
    fwrite(STDERR, $code . "\n");
    exit(1);
}
fwrite(STDOUT, sprintf(
    "TRENDYOL_INTAKE received=%d updated=%d unchanged=%d ignored=%d deferred=%d rejected=%d pages=%d%s\n",
    $counts['received'], $counts['updated'], $counts['unchanged'], $counts['ignored'], $counts['deferred'], $counts['rejected'], $counts['pages'], $counts['truncated'] ? ' truncated=1' : '',
));
