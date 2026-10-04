<?php

declare(strict_types=1);

use Arasya\Operations\Order\GlobalOrderId;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['order:', 'rotate']);
$order = $options['order'] ?? null;
if (!is_string($order)) {
    fwrite(STDERR, "Usage: php bin/order-qr.php --order=<source:order-id> [--rotate]\n");
    exit(2);
}
try {
    $globalId = GlobalOrderId::fromString($order)->toString();
} catch (InvalidArgumentException) {
    fwrite(STDERR, "Invalid global order ID.\n");
    exit(2);
}
$writer = $container->projectionWriter();
$payload = array_key_exists('rotate', $options) ? $writer->rotateQrReference($globalId) : $writer->qrPayloadFor($globalId);
if ($payload === null) {
    fwrite(STDERR, "ORDER_QR_NOT_FOUND\n");
    exit(1);
}
fwrite(STDOUT, $payload . "\n");
