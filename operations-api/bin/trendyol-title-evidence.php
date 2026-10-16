<?php

declare(strict_types=1);

// Read-only evidence for Romanian production titles. For the given Trendyol shipment packages (or barcodes) it reads
// the order lines (Order V2, GET) and the seller's approved catalogue (GET .../products/approved) once with
// `Accept-Language: ro` and once with `en`, on the given storefront, and prints them side by side. It opens no
// database connection and sends only GET requests: it cannot change Trendyol or Arasya. The report holds product
// data only (no credentials, no customer data).
//
//   php bin/trendyol-title-evidence.php --store-front=<CODE> --packages=<id>[,<id>...] [--json]
//   php bin/trendyol-title-evidence.php --store-front=<CODE> --barcodes=<barcode>[,<barcode>...] [--json]
//
// Exit codes: 0 report printed, 1 configuration or Trendyol failure, 2 invalid options.

use Arasya\Operations\Config\Config;
use Arasya\Operations\Integration\Trendyol\StreamTrendyolTransport;
use Arasya\Operations\Integration\Trendyol\TrendyolCatalogueClient;
use Arasya\Operations\Integration\Trendyol\TrendyolClient;
use Arasya\Operations\Integration\Trendyol\TrendyolTitleEvidence;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is available only through PHP CLI.\n");
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

$storeFront = null;
$packages = [];
$barcodes = [];
$json = false;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--store-front=([A-Z]{2,5})$/D', $argument, $m) === 1 && $storeFront === null) { $storeFront = $m[1]; continue; }
    if (preg_match('/^--packages=([0-9]{1,15}(?:,[0-9]{1,15}){0,49})$/D', $argument, $m) === 1 && $packages === []) { $packages = array_map('intval', explode(',', $m[1])); continue; }
    if (preg_match('/^--barcodes=([\x21-\x2B\x2D-\x7E]{1,64}(?:,[\x21-\x2B\x2D-\x7E]{1,64}){0,49})$/D', $argument, $m) === 1 && $barcodes === []) { $barcodes = explode(',', $m[1]); continue; }
    if ($argument === '--json' && !$json) { $json = true; continue; }
    fwrite(STDERR, "TITLE_EVIDENCE_INVALID_OPTIONS\n");
    exit(2);
}
if ($storeFront === null || ($packages === []) === ($barcodes === [])) {
    fwrite(STDERR, "TITLE_EVIDENCE_INVALID_OPTIONS\n");
    exit(2);
}

try {
    $config = Config::fromEnvironment();
} catch (RuntimeException) {
    fwrite(STDERR, "CONFIGURATION_INVALID\n");
    exit(1);
}
if ($config->trendyol === null) {
    fwrite(STDERR, "TRENDYOL_NOT_CONFIGURED\n");
    exit(1);
}
$transport = new StreamTrendyolTransport();
$evidence = new TrendyolTitleEvidence(new TrendyolClient($config->trendyol, $transport), new TrendyolCatalogueClient($config->trendyol, $transport));
try {
    $report = $packages !== []
        ? $evidence->forPackages(array_values(array_unique($packages)), $storeFront)
        : $evidence->compare(array_map(static fn (string $barcode): array => ['packageId' => null, 'lineId' => null, 'barcode' => $barcode, 'productCode' => null, 'productSize' => null, 'productColor' => null, 'orderTitle' => null], array_values(array_unique($barcodes))), $storeFront);
} catch (RuntimeException $error) {
    fwrite(STDERR, (preg_match('/^TRENDYOL_[A-Z_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'TRENDYOL_TITLE_EVIDENCE_FAILED') . "\n");
    exit(1);
}
if ($json) {
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}
fwrite(STDOUT, sprintf("Storefront %s · %d lines · matched %d · missing %d · ambiguous %d · Romanian differs from the order title on %d · Romanian differs from English on %d\n",
    $report['storeFrontCode'], $report['summary']['lines'], $report['summary']['matched'], $report['summary']['missing'], $report['summary']['ambiguous'],
    $report['summary']['romanianDiffersFromOrder'], $report['summary']['romanianDiffersFromEnglish']));
foreach ($report['lines'] as $line) {
    fwrite(STDOUT, sprintf("- package %s line %s · barcode %s · %s\n    order: %s\n    ro:    %s\n    en:    %s\n",
        $line['packageId'] ?? '-', $line['lineId'] ?? '-', $line['barcode'] ?? '-', $line['status'], $line['orderTitle'] ?? '-',
        $line['catalogue']['titleRo'] ?? '-', $line['catalogue']['titleEn'] ?? '-'));
}
