<?php

declare(strict_types=1);

// Romanian title evidence gate (no database, no network): the catalogue reader sends only documented GET requests
// with `storeFrontCode` and `Accept-Language`, a line is matched only to exactly one catalogue variant of its own
// barcode in both languages, titles are compared byte for byte, ambiguity and absence are reported (never guessed),
// and the report carries no credentials. The CLI refuses invalid options before reading any configuration.
use Arasya\Operations\Config\TrendyolCredentials;
use Arasya\Operations\Integration\Trendyol\TrendyolCatalogueClient;
use Arasya\Operations\Integration\Trendyol\TrendyolClient;
use Arasya\Operations\Integration\Trendyol\TrendyolTitleEvidence;
use Arasya\Operations\Integration\Trendyol\TrendyolTransport;

require dirname(__DIR__) . '/bootstrap.php';

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); } };
$throws = static function (callable $call, string $code, string $label) use ($check): void {
    try { $call(); $check(false, "{$label}: no exception"); } catch (RuntimeException $error) { $check($error->getMessage() === $code, "{$label}: {$error->getMessage()}"); }
};

/** A recording transport: answers per Accept-Language from fixed catalogue pages, or from fixed order pages. */
final class RecordingTransport implements TrendyolTransport
{
    /** @var list<array{url: string, headers: array<string, string>}> */
    public array $requests = [];
    /** @param array<string, array<string, mixed>> $catalogue @param array<string, mixed>|null $orders */
    public function __construct(public array $catalogue, public ?array $orders = null, public int $status = 200)
    {
    }
    public function get(string $url, array $headers): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];
        if (str_contains($url, '/v2/orders')) return ['status' => 200, 'body' => json_encode($this->orders, JSON_THROW_ON_ERROR)];
        return ['status' => $this->status, 'body' => json_encode($this->catalogue[$headers['Accept-Language'] ?? 'none'] ?? ['content' => []], JSON_THROW_ON_ERROR)];
    }
}

$credentials = TrendyolCredentials::fromValues('123456', 'key-not-in-reports', 'secret-not-in-reports', 'https://apigw.example.test') ?? throw new LogicException('credentials');
$content = static fn (int $contentId, string $title, array $variants): array => ['contentId' => $contentId, 'productMainId' => "PM-{$contentId}", 'title' => $title, 'variants' => $variants];
$variant = static fn (int $id, string $barcode): array => ['variantId' => $id, 'barcode' => $barcode, 'stockCode' => "SC-{$barcode}"];
$titleRo = 'Draperie din in, gri închis – 300 × 260 cm (ăâîșț)';
$catalogue = [
    'ro' => ['content' => [
        $content(11, $titleRo, [$variant(111, 'Bella-4402-10'), $variant(112, 'Bella-4402-11')]),
        $content(21, 'Perdea voal albă', [$variant(211, 'DUP-1')]),
        $content(22, 'Perdea voal albă (dublură)', [$variant(221, 'DUP-1')]),
        $content(31, 'Doar în română', [$variant(311, 'RO-ONLY')]),
    ]],
    'en' => ['content' => [
        $content(11, 'Linen curtain, dark grey – 300 × 260 cm', [$variant(111, 'Bella-4402-10'), $variant(112, 'Bella-4402-11')]),
        $content(21, 'White voile curtain', [$variant(211, 'DUP-1')]),
        $content(22, 'White voile curtain (duplicate)', [$variant(221, 'DUP-1')]),
    ]],
];
$transport = new RecordingTransport($catalogue);
$evidence = new TrendyolTitleEvidence(new TrendyolClient($credentials, $transport), new TrendyolCatalogueClient($credentials, $transport));
$line = static fn (string $barcode, string $orderTitle, int $lineId): array => ['packageId' => 900, 'lineId' => $lineId, 'barcode' => $barcode, 'productCode' => $barcode, 'productSize' => null, 'productColor' => null, 'orderTitle' => $orderTitle];
$report = $evidence->compare([
    $line('Bella-4402-10', 'Linen curtain, dark grey – 300 × 260 cm', 1),
    $line('Bella-4402-11', 'Linen curtain, dark grey – 300 × 260 cm', 2),
    $line('DUP-1', 'White voile curtain', 3),
    $line('RO-ONLY', 'Something', 4),
    $line('UNKNOWN', 'Unknown product', 5),
], 'RO');

// Requests: GET only (the transport has no other verb), documented path and headers, one request per language.
$check(count($transport->requests) === 2, 'one catalogue request per language for up to 50 barcodes');
foreach ($transport->requests as $index => $request) {
    $language = TrendyolCatalogueClient::LANGUAGES[$index];
    $check(str_starts_with($request['url'], 'https://apigw.example.test/integration/product/sellers/123456/products/approved?'), 'documented approved-products path: ' . $request['url']);
    parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
    $check($query['barcodes'] === 'Bella-4402-10,Bella-4402-11,DUP-1,RO-ONLY,UNKNOWN' && $query['size'] === '100', 'the barcodes filter carries every line barcode once');
    $check($request['headers']['storeFrontCode'] === 'RO' && $request['headers']['Accept-Language'] === $language, "storeFrontCode and Accept-Language {$language} are sent as documented");
    $check($request['headers']['Authorization'] === 'Basic ' . base64_encode('key-not-in-reports:secret-not-in-reports'), 'basic authentication like the order client');
}

$byLine = array_column($report['lines'], null, 'lineId');
$check($byLine[1]['status'] === 'matched' && $byLine[1]['catalogue']['titleRo'] === $titleRo, 'the Romanian seller title is returned byte for byte, diacritics included');
$check($byLine[1]['catalogue']['contentId'] === 11 && $byLine[1]['catalogue']['variantId'] === 111 && $byLine[1]['catalogue']['stockCode'] === 'SC-Bella-4402-10', 'the match names its exact content and variant');
$check($byLine[1]['romanianDiffersFromOrder'] === true && $byLine[1]['orderTitleEqualsEnglish'] === true && $byLine[1]['romanianDiffersFromEnglish'] === true, 'the order title equals the English listing, the Romanian one differs');
$check($byLine[2]['status'] === 'matched' && $byLine[2]['catalogue']['variantId'] === 112, 'another variant of the same content gets its own variant, never the neighbour\'s');
$check($byLine[3]['status'] === 'ambiguous' && $byLine[3]['catalogue'] === null && $byLine[3]['candidates'] === ['ro' => 2, 'en' => 2], 'a barcode in two contents is ambiguous, never picked');
$check($byLine[4]['status'] === 'missing' && $byLine[4]['candidates'] === ['ro' => 1, 'en' => 0], 'a title present in one language only is not evidence');
$check($byLine[5]['status'] === 'missing' && $byLine[5]['catalogue'] === null, 'an unknown barcode is missing');
$check($report['summary'] === ['lines' => 5, 'matched' => 2, 'missing' => 2, 'ambiguous' => 1, 'romanianDiffersFromOrder' => 2, 'romanianDiffersFromEnglish' => 2], 'summary: ' . json_encode($report['summary']));
$encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$check(!str_contains($encoded, 'key-not-in-reports') && !str_contains($encoded, 'secret-not-in-reports') && !str_contains($encoded, 'Basic '), 'no credential in the report');

// Byte-for-byte: a decomposed "ș" is a different title, nothing is normalized or translated.
$nfd = new RecordingTransport(['ro' => ['content' => [$content(41, "Perdea s\u{0326}ifon", [$variant(411, 'NFD-1')])]], 'en' => ['content' => [$content(41, 'Chiffon curtain', [$variant(411, 'NFD-1')])]]]);
$nfdReport = (new TrendyolTitleEvidence(new TrendyolClient($credentials, $nfd), new TrendyolCatalogueClient($credentials, $nfd)))->compare([$line('NFD-1', "Perdea \u{0219}ifon", 1)], 'RO');
$check($nfdReport['lines'][0]['catalogue']['titleRo'] === "Perdea s\u{0326}ifon" && $nfdReport['lines'][0]['romanianDiffersFromOrder'] === true, 'titles are compared byte for byte');

// Package path: the order lines come from the existing read-only Order V2 client (GET by package ids).
$orders = new RecordingTransport($catalogue, ['content' => [['shipmentPackageId' => 900, 'orderNumber' => '11700000001', 'status' => 'Created', 'lastModifiedDate' => 1_790_000_000_000,
    'lines' => [['lineId' => 1, 'productName' => 'Linen curtain, dark grey – 300 × 260 cm', 'barcode' => 'Bella-4402-10', 'sku' => 'Bella-4402-10', 'stockCode' => 'merchantSku', 'merchantSku' => 'merchantSku', 'quantity' => 1]],
    'shipmentAddress' => ['fullName' => 'Customer Name Never Printed', 'address1' => 'Street', 'phone' => '0700000000']]], 'totalPages' => 1, 'totalElements' => 1]);
$fromPackages = (new TrendyolTitleEvidence(new TrendyolClient($credentials, $orders), new TrendyolCatalogueClient($credentials, $orders)))->forPackages([900], 'RO');
$check($fromPackages['lines'][0]['status'] === 'matched' && $fromPackages['lines'][0]['productCode'] === 'Bella-4402-10', 'package lines are read through Order V2 and matched by their barcode');
$check(!str_contains(json_encode($fromPackages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'Customer Name'), 'no customer data in the report');

// Refusals and failures.
$client = new TrendyolCatalogueClient($credentials, new RecordingTransport($catalogue));
$throws(fn () => $client->approvedByBarcodes([], 'RO', 'ro'), 'TRENDYOL_CATALOGUE_REQUEST_INVALID', 'no barcodes');
$throws(fn () => $client->approvedByBarcodes(array_map(static fn (int $i): string => "B{$i}", range(1, 51)), 'RO', 'ro'), 'TRENDYOL_CATALOGUE_REQUEST_INVALID', 'more than 50 barcodes');
$throws(fn () => $client->approvedByBarcodes(['A,B'], 'RO', 'ro'), 'TRENDYOL_CATALOGUE_REQUEST_INVALID', 'a comma inside a barcode');
$throws(fn () => $client->approvedByBarcodes(['A'], 'ro', 'ro'), 'TRENDYOL_CATALOGUE_REQUEST_INVALID', 'a storefront code is upper case');
$throws(fn () => $client->approvedByBarcodes(['A'], 'RO', 'ro_RO'), 'TRENDYOL_CATALOGUE_REQUEST_INVALID', 'only the documented language values');
$throws(fn () => (new TrendyolCatalogueClient($credentials, new RecordingTransport($catalogue, null, 401)))->approvedByBarcodes(['A'], 'RO', 'ro'), 'TRENDYOL_AUTH_FAILED', 'refused credentials');
$throws(fn () => (new TrendyolCatalogueClient($credentials, new RecordingTransport($catalogue, null, 429)))->approvedByBarcodes(['A'], 'RO', 'ro'), 'TRENDYOL_RATE_LIMITED', 'rate limited');
$throws(fn () => (new TrendyolCatalogueClient($credentials, new RecordingTransport($catalogue, null, 503)))->approvedByBarcodes(['A'], 'RO', 'ro'), 'TRENDYOL_UNAVAILABLE', 'unavailable');
$throws(fn () => (new TrendyolCatalogueClient($credentials, new RecordingTransport(['ro' => ['content' => [['contentId' => 1, 'title' => 'x']]]])))->approvedByBarcodes(['A'], 'RO', 'ro'), 'TRENDYOL_MALFORMED_RESPONSE', 'a content without variants');

// CLI: invalid options are refused before any configuration or network access.
$cli = dirname(__DIR__) . '/bin/trendyol-title-evidence.php';
foreach ([[], ['--store-front=RO'], ['--packages=1'], ['--store-front=ro', '--packages=1'], ['--store-front=RO', '--packages=1', '--barcodes=A'], ['--store-front=RO', '--packages=1', '--write']] as $arguments) {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $code);
    $check($code === 2 && end($output) === 'TITLE_EVIDENCE_INVALID_OPTIONS', 'invalid options refused: ' . implode(' ', $arguments));
    $output = [];
}
$source = (string) file_get_contents($cli) . (string) file_get_contents(dirname(__DIR__) . '/src/Integration/Trendyol/TrendyolTitleEvidence.php') . (string) file_get_contents(dirname(__DIR__) . '/src/Integration/Trendyol/TrendyolCatalogueClient.php');
$check(preg_match('/Connection::|new PDO|->pdo\(|->post\(|PUT|DELETE/', $source) !== 1, 'the evidence tools open no database and send no write');

fwrite(STDOUT, "PASS Trendyol title evidence: {$checks} checks\n");
