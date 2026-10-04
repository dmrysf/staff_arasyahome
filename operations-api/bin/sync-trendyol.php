<?php

declare(strict_types=1);

$container = require __DIR__ . '/cli-bootstrap.php';
$synchronizer = $container->trendyolSynchronizer();
if ($synchronizer === null) {
    fwrite(STDOUT, "TRENDYOL_NOT_CONFIGURED\n");
    exit(0);
}
try {
    $counts = $synchronizer->run();
} catch (RuntimeException $error) {
    $code = preg_match('/^[A-Z_]+$/', $error->getMessage()) === 1 ? $error->getMessage() : 'TRENDYOL_SYNC_FAILED';
    fwrite(STDERR, $code . "\n");
    exit(1);
}
fwrite(STDOUT, sprintf("TRENDYOL_SYNC applied=%d duplicate=%d out_of_order=%d rejected=%d\n", $counts['applied'], $counts['duplicate'], $counts['out_of_order'], $counts['rejected']));
