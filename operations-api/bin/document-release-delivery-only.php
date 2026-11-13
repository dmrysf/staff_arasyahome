<?php

declare(strict_types=1);

// One-time repair for API 2.68.1: releases the production documents that an earlier release blocked only because
// the delivery identity (recipient, address, masked phone) changed at the source. Each stale web shop order is
// checked in its own transaction by DocumentStaleness::releaseDeliveryOnly(): the same revision and QR become
// usable again and the open block ends. Trendyol orders, manufacturing changes and orders with an open revision
// request are left exactly as they are.
// Dry run by default: every check runs and is rolled back, so the report shows exactly what --apply would do.
// Prints order ids, stages and outcomes only, never customer data.

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['apply']);
$apply = array_key_exists('apply', $options);
$pdo = $container->pdo();
$staleness = new Arasya\Operations\Document\DocumentStaleness($pdo, $container->clock());
$requestId = 'document-release-delivery-only-' . gmdate('Ymd\THis\Z');

$candidates = $pdo->query("SELECT order_uuid, global_order_id, order_number, source_key, production_stage_id FROM operational_orders WHERE document_status = 'stale' ORDER BY updated_at")
    ->fetchAll(PDO::FETCH_ASSOC);
$summary = [];
foreach ($candidates as $row) {
    $pdo->beginTransaction();
    try {
        $outcome = $staleness->releaseDeliveryOnly((string) $row['order_uuid'], $requestId);
        $apply && $outcome === 'released' ? $pdo->commit() : $pdo->rollBack();
    } catch (Throwable $error) {
        $pdo->rollBack();
        $outcome = 'error:' . ($error instanceof Arasya\Operations\Http\ApiException ? $error->errorCode : get_class($error));
    }
    $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
    fwrite(STDOUT, json_encode(['order' => $row['global_order_id'], 'stage' => $row['production_stage_id'], 'outcome' => $outcome], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
}
fwrite(STDOUT, json_encode(['dryRun' => !$apply, 'stale' => count($candidates), 'outcomes' => $summary], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
