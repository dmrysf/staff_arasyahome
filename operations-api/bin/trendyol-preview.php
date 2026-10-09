<?php

declare(strict_types=1);

// Read-only Trendyol preview. Reads one window of shipment packages (GET only) and prints how intake would
// classify them against a proposed baseline. It opens no database connection: it cannot create an intake row,
// a production order, a QR, a document or a cursor. The report holds no credentials and no customer name,
// address, phone or email.
//
//   php bin/trendyol-preview.php [--from=<ISO-8601>] [--to=<ISO-8601>] [--baseline=<ISO-8601>] [--json]
//
// Defaults: the last 24 hours, baseline = now (so every package in the window shows as historical).

use Arasya\Operations\Config\Config;
use Arasya\Operations\Integration\Trendyol\StreamTrendyolTransport;
use Arasya\Operations\Integration\Trendyol\TrendyolClient;
use Arasya\Operations\Integration\Trendyol\TrendyolPreview;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is available only through PHP CLI.\n");
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['from:', 'to:', 'baseline:', 'json']);
$utc = new DateTimeZone('UTC');
$parse = static function (mixed $value, DateTimeImmutable $default) use ($utc): DateTimeImmutable {
    if (!is_string($value)) {
        return $default;
    }
    $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $value) ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, $utc);
    if ($parsed === false) {
        fwrite(STDERR, "Invalid date: use ISO-8601, for example 2026-10-09T08:00:00Z.\n");
        exit(2);
    }
    return $parsed->setTimezone($utc);
};
$now = new DateTimeImmutable('now', $utc);
$to = $parse($options['to'] ?? null, $now);
$from = $parse($options['from'] ?? null, $to->modify('-24 hours'));
$baseline = $parse($options['baseline'] ?? null, $now);

try {
    $config = Config::fromEnvironment();
} catch (RuntimeException) {
    fwrite(STDERR, "CONFIGURATION_INVALID\n");
    exit(1);
}
if ($config->trendyol === null) {
    fwrite(STDOUT, "TRENDYOL_NOT_CONFIGURED\n");
    exit(0);
}
try {
    $report = (new TrendyolPreview(new TrendyolClient($config->trendyol, new StreamTrendyolTransport())))->run($from, $to, $baseline, $now);
} catch (RuntimeException $error) {
    fwrite(STDERR, (preg_match('/^TRENDYOL_[A-Z_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'TRENDYOL_PREVIEW_FAILED') . "\n");
    exit(1);
}
if (array_key_exists('json', $options)) {
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}
fwrite(STDOUT, sprintf("TRENDYOL_PREVIEW window=%s..%s baseline=%s pages=%d totalElements=%s truncated=%s\n",
    $report['window']['start'], $report['window']['end'], $report['baseline'], $report['pages'], $report['totalElements'] ?? '?', $report['truncated'] ? 'yes' : 'no'));
foreach ($report['counts'] as $name => $count) {
    fwrite(STDOUT, sprintf("  %-20s %d\n", $name, $count));
}
fwrite(STDOUT, sprintf("orderDate evidence: aheadOfClock=%d (non-zero proves GMT+3 wall time) nearActivation=%d\n",
    $report['evidence']['orderDateAheadOfClock'], $report['evidence']['nearActivation']));
fwrite(STDOUT, "Fields present: " . json_encode($report['fields'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
foreach ($report['packages'] as $package) {
    fwrite(STDOUT, sprintf("%s  #%s  %-12s ordered %s  -> %s%s\n", $package['packageId'], $package['orderNumber'], $package['status'], $package['orderDate'] ?? '?', $package['classification'], $package['orderDateNearActivation'] ? ' (near activation: check Seller Panel)' : ''));
    foreach ($package['lines'] as $line) {
        $hint = $line['sizeSuggestion'] === null ? '' : sprintf(' [suggestion %sx%s cm]', $line['sizeSuggestion']['width'], $line['sizeSuggestion']['height']);
        fwrite(STDOUT, sprintf("    %d x %s | %s | %s | %s%s\n", $line['quantity'], $line['productName'], $line['stockCode'] ?? '-', $line['productSize'] ?? '-', $line['productColor'] ?? '-', $hint));
    }
}
