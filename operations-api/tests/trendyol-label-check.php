<?php

declare(strict_types=1);

// Proof, without any network, that bin/trendyol-label-check.php only reads: it sends GET and nothing else, reaches only the
// documented package list, the common label query and the Trendyol CDN, cannot create a label, shipment, status or
// tracking number, opens no database, writes no file and reports no credential or customer value.

const ARASYA_LABEL_CHECK_LIBRARY = true;
$script = dirname(__DIR__) . '/bin/trendyol-label-check.php';
require $script;

$checks = 0;
function ok(bool $value, string $message): void { global $checks; $checks++; if (!$value) { fwrite(STDERR, "FAIL {$message}\n"); exit(1); } }

// ---- The source: comments removed, what remains is what can run --------------------------------------------------------
$code = '';
foreach (token_get_all((string) file_get_contents($script)) as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    $code .= is_array($token) ? $token[1] : $token;
}
ok(preg_match_all("/'method'\\s*=>\\s*'([A-Za-z]+)'/", $code, $verbs) === 1 && $verbs[1] === ['GET'], 'exactly one HTTP verb exists: GET');
ok(preg_match('/\b(POST|PUT|PATCH|DELETE)\b/', $code) === 0, 'no write verb appears in the code');
ok(preg_match("/'content'\\s*=>/", $code) === 0, 'no request body can be sent');
ok(preg_match_all('#/integration/[A-Za-z0-9/._-]+#', $code, $paths) === 2 && $paths[0] === ['/integration/order/sellers/', '/integration/sellers/'], 'only the two documented API paths are built: ' . json_encode($paths[0]));
ok(preg_match_all('#/v2/orders|/common-label/[a-z]+#', $code, $services) === 2 && $services[0] === ['/v2/orders', '/common-label/query'], 'only the package list and the label query');
foreach (['createCommonLabel', 'common-label/create', 'shipment-packages', 'update-tracking', 'unsupplied', 'split', 'send-invoice', 'cargo-provider', 'fancourier.ro', 'selfawb', 'PDO', 'mysqli', 'curl_', 'fsockopen', 'file_put_contents', 'fwrite($h', 'fopen', 'unlink', 'rename(', 'mkdir', 'exec(', 'system(', 'passthru', 'proc_open', 'popen', 'secrets.json', 'var_dump', 'print_r', 'error_log'] as $forbidden) {
    if ($forbidden === 'exec(' || $forbidden === 'system(') { ok(preg_match('/\b' . preg_quote($forbidden, '/') . '/', $code) === 0, "no {$forbidden}"); continue; }
    ok(!str_contains($code, $forbidden), "no {$forbidden}");
}
ok(CHECK_API_HOST === 'apigw.trendyol.com' && CHECK_LABEL_HOSTS === ['cdn.dsmcdn.com'] && CHECK_MAX_LABEL_CALLS === 24 && CHECK_MAX_PAGES === 3 && CHECK_SAMPLES_PER_GROUP === 2, 'hosts and limits are fixed');

// ---- The wire: what the real HTTP function hands to PHP ------------------------------------------------------------------
final class RecordingHttps
{
    public static array $opened = [];
    public mixed $context;
    private bool $done = false;
    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool { self::$opened[] = ['url' => $path, 'mode' => $mode, 'options' => stream_context_get_options($this->context)]; return true; }
    public function stream_read(int $count): string|false { if ($this->done) return ''; $this->done = true; return 'body'; }
    public function stream_eof(): bool { return $this->done; }
    public function stream_stat(): array { return []; }
    public function stream_set_option(int $option, int $a, ?int $b): bool { return false; }
}
stream_wrapper_unregister('https');
stream_wrapper_register('https', RecordingHttps::class);
check_http_get('https://apigw.trendyol.com/integration/sellers/1/common-label/query?id=7330000000001', ['Authorization' => 'Basic x'], 1000);
check_http_get('https://cdn.dsmcdn.com/a/b.pdf', ['Accept' => 'application/pdf'], 1000);
foreach (RecordingHttps::$opened as $open) {
    ok($open['options']['http']['method'] === 'GET', 'the wire method is GET');
    ok(!array_key_exists('content', $open['options']['http']), 'the wire request has no body');
    ok($open['options']['http']['follow_location'] === 0 && $open['options']['http']['max_redirects'] === 0, 'no redirect is followed');
    ok($open['options']['ssl']['verify_peer'] === true && $open['options']['ssl']['verify_peer_name'] === true, 'certificates are verified');
    ok($open['mode'] === 'rb' || $open['mode'] === 'r', 'read mode');
}
ok(count(RecordingHttps::$opened) === 2, 'two requests were opened');
$before = count(RecordingHttps::$opened);
foreach (['http://apigw.trendyol.com/x', 'http://cdn.dsmcdn.com/x', 'https://evil.example/x', 'https://apigw.trendyol.com.evil.example/x', 'https://cdn.dsmcdn.com:8443/x', 'https://u@cdn.dsmcdn.com/x', 'https://u:p@apigw.trendyol.com/x', 'https://127.0.0.1/x', 'https://169.254.169.254/latest', 'https://api.fancourier.ro/x', 'file:///etc/passwd', ''] as $refused) {
    try { check_http_get($refused, [], 10); ok(false, "allowed {$refused}"); } catch (RuntimeException $error) { ok($error->getMessage() === 'HOST_REFUSED', "refused {$refused}"); }
}
ok(count(RecordingHttps::$opened) === $before, 'a refused host never reaches the network layer');
stream_wrapper_restore('https');

// ---- A full run against official-shaped answers ------------------------------------------------------------------------
$pdf = "%PDF-1.4\n1 0 obj<</Type/Page/MediaBox[0 0 283.46 425.2]>>endobj\n" . str_repeat('x', 80) . "\n%%EOF\n";
$package = static fn (int $id, string $provider, string $country, string $status, int|string|null $tracking): array => ['shipmentPackageId' => $id, 'orderNumber' => 'ORD-SECRET-' . $id, 'cargoProviderName' => $provider, 'status' => $status, 'shipmentPackageStatus' => $status,
    'cargoTrackingNumber' => $tracking, 'shipmentAddress' => ['countryCode' => $country, 'fullName' => 'SECRET NAME', 'address1' => 'SECRET STREET', 'phone' => '0744SECRET'], 'customerEmail' => 'secret@example.invalid', 'packageTotalPrice' => 777.5];
$list = [$package(9001, 'FANCOURIER', 'RO', 'Picking', 7330000000000001), $package(9002, 'FANCOURIER', 'RO', 'Shipped', 7330000000000002), $package(9003, 'FANEX', 'RO', 'Picking', 7330000000000003), $package(9004, 'FANCOURIER', 'GR', 'Picking', 7330000000000004),
    $package(9005, 'FANCOURIER', 'BG', 'Delivered', 7330000000000005), $package(9006, 'FANCOURIER', 'RO', 'Cancelled', 7330000000000006), $package(9007, 'SAMEDAY', 'BG', 'Picking', null), $package(9008, 'FANEX', 'GR', 'Picking', 7330000000000008), $package(9009, 'FANCOURIER', 'CZ', 'Picking', '7330000000000009')];
$calls = [];
$pauses = 0;
$http = static function (string $url, array $headers, int $max) use (&$calls, $list, $pdf): array {
    $calls[] = [$url, $headers];
    if (str_contains($url, '/v2/orders')) return ['status' => 200, 'body' => json_encode(['content' => $list, 'totalPages' => 1]), 'headers' => []];
    if (str_contains($url, 'cdn.dsmcdn.com/redirect')) return ['status' => 302, 'body' => '', 'headers' => []];
    if (str_contains($url, 'cdn.dsmcdn.com')) return ['status' => 200, 'body' => $pdf, 'headers' => ['content-type' => 'application/pdf', 'cache-control' => 'max-age=3600']];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $label = static fn (string $url): array => ['status' => 200, 'body' => json_encode(['data' => [['format' => 'PDF', 'label' => $url]]]), 'headers' => []];
    return match ($query['id']) {
        '7330000000000001', '7330000000000002' => $label("https://cdn.dsmcdn.com/x/SECRETPATH{$query['id']}.pdf"),
        '7330000000000003' => ['status' => 400, 'body' => json_encode(['errors' => [['key' => 'cargo.tracking.notfound', 'message' => 'cargo 7330000000000003 not found for SECRET']]]), 'headers' => []],
        '7330000000000004' => $label('https://evil.example/SECRETPATH.pdf'),
        '7330000000000008' => $label('https://cdn.dsmcdn.com/redirect/SECRETPATH.pdf'),
        '7330000000000009' => ['status' => 200, 'body' => json_encode(['data' => [['format' => 'ZPL', 'label' => 'https://cdn.dsmcdn.com/x/SECRETPATH.zpl']]]), 'headers' => []],
        default => ['status' => 404, 'body' => '{}', 'headers' => []],
    };
};
$auth = ['Authorization' => 'Basic SECRETAUTH', 'User-Agent' => '123456 - SelfIntegration'];
$report = check_run($http, 'https://apigw.trendyol.com', '123456', $auth, null, 1_790_000_000_000, static function (int $seconds) use (&$pauses): void { $pauses += $seconds; });
ok($report['outcome'] === 'COMPLETED' && $report['packages'] === 9 && $report['listPages'] === 1 && $pauses > 0, 'the run completes and paces its calls');
foreach ($calls as [$url, $headers]) {
    $allowed = preg_match('#^https://apigw\.trendyol\.com/integration/order/sellers/123456/v2/orders\?startDate=\d+&endDate=\d+&page=\d&size=200&orderByField=PackageLastModifiedDate&orderByDirection=ASC$#D', $url) === 1
        || preg_match('#^https://apigw\.trendyol\.com/integration/sellers/123456/common-label/query\?id=\d+$#D', $url) === 1
        || str_starts_with($url, 'https://cdn.dsmcdn.com/');
    ok($allowed, "only documented reads: {$url}");
    if (str_starts_with($url, 'https://cdn.dsmcdn.com/')) ok(!isset($headers['Authorization']) && !str_contains(json_encode($headers), '123456'), 'no credential or seller id reaches the CDN');
}
$asked = array_map(static fn (array $call): ?string => preg_match('/query\?id=(\d+)$/', $call[0], $m) === 1 ? $m[1] : null, $calls);
ok(!in_array('7330000000000006', $asked, true), 'a cancelled package is never asked for a label');
ok(count(array_filter($calls, static fn (array $call): bool => str_contains($call[0], 'evil.example'))) === 0, 'a label URL outside the list is not downloaded');
ok(count(array_filter($calls, static fn (array $call): bool => isset($call[1]['storeFrontCode']))) > 0 && count(array_filter($calls, static fn (array $call): bool => str_contains($call[0], 'common-label') && !isset($call[1]['storeFrontCode']))) > 0, 'the label query is tried with and without storeFrontCode');
$text = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
foreach (['SECRET', '7330000000', 'ORD-', '9001', '0744', 'Basic', '123456', 'evil.example/', '777.5', 'dsmcdn.com/x', '.pdf'] as $forbidden) ok(!str_contains($text, $forbidden), "the report has no {$forbidden}");
$verdicts = [];
foreach ($report['verdicts'] as $row) $verdicts[$row['provider'] . '|' . $row['country']] = $row['verdict'];
ok($verdicts === ['FANCOURIER|RO' => 'SUPPORTED_PDF', 'FANEX|RO' => 'NOT_SUPPORTED_NO_LABEL', 'FANCOURIER|GR' => 'LABEL_ANSWERED_PDF_NOT_CONFIRMED', 'FANCOURIER|BG' => 'NO_LABEL_ONLY_FINISHED_PACKAGES_TESTED', 'SAMEDAY|BG' => 'UNTESTED_NO_ELIGIBLE_PACKAGE', 'FANEX|GR' => 'LABEL_ANSWERED_PDF_NOT_CONFIRMED', 'FANCOURIER|CZ' => 'LABEL_ANSWERED_PDF_NOT_CONFIRMED'], 'verdicts ' . json_encode($verdicts));
$documents = [];
foreach ($report['documents'] as $row) $documents[$row['provider'] . '|' . $row['country']] = $row;
ok($documents['FANCOURIER|RO']['pageSizes'] === ['283.5 x 425.2 pt (100 x 150 mm)'] && $documents['FANCOURIER|RO']['pages'] === 1 && $documents['FANCOURIER|RO']['validPdf'] === true, 'the PDF structure is reported');
ok($documents['FANCOURIER|GR']['outcome'] === 'HOST_NOT_ALLOWLISTED_NOT_DOWNLOADED' && $documents['FANEX|GR']['outcome'] === 'DOWNLOAD_HTTP_302', 'a foreign host and a redirect are reported, never followed');
ok(!isset($documents['FANCOURIER|CZ']), 'a ZPL answer is not downloaded as a PDF');
$rejected = array_values(array_filter($report['attempts'], static fn (array $row): bool => $row['provider'] === 'FANEX' && $row['country'] === 'RO'));
ok($rejected[0]['http'] === 400 && $rejected[0]['outcome'] === 'NO_LABEL' && $rejected[0]['hint'] === 'cargo.tracking.notfound', 'only the error key is kept, never the free-text message');
ok(check_hint(json_encode(['errors' => [['key' => 'Ion Popescu 0744']]])) === null && check_hint(json_encode(['exception' => 'ClientApiAuthenticationException'])) === 'ClientApiAuthenticationException', 'a value that is not a code is dropped');

// Limits: many carriers and pages still mean at most 24 label calls and 3 list pages.
$many = [];
for ($i = 0; $i < 60; $i++) $many[] = $package(10000 + $i, "CARRIER{$i}", 'RO', 'Picking', 7330000000100000 + $i);
$count = ['list' => 0, 'label' => 0];
$bounded = check_run(static function (string $url) use (&$count, $many): array {
    if (str_contains($url, '/v2/orders')) { $count['list']++; return ['status' => 200, 'body' => json_encode(['content' => $many, 'totalPages' => 40]), 'headers' => []]; }
    $count['label']++;
    return ['status' => 400, 'body' => '{}', 'headers' => []];
}, 'https://apigw.trendyol.com', '123456', $auth, 'RO', 1_790_000_000_000, static function (int $seconds): void {});
ok($count['list'] === 3 && $count['label'] === 24 && $bounded['labelCalls'] === 24, 'at most 3 list pages and 24 label calls: ' . json_encode($count));
foreach ([[401, 'PACKAGE_LIST_FAILED'], [429, 'PACKAGE_LIST_FAILED'], [500, 'PACKAGE_LIST_FAILED']] as [$status, $outcome]) {
    $failed = check_run(static fn (string $url): array => ['status' => $status, 'body' => 'SECRET', 'headers' => []], 'https://apigw.trendyol.com', '1', $auth, null, 1, static function (int $s): void {});
    ok($failed === ['outcome' => $outcome, 'http' => $status], "HTTP {$status} stops the run before any label call");
}
ok(check_run(static fn (string $url): array => ['status' => 200, 'body' => 'not json', 'headers' => []], 'https://apigw.trendyol.com', '1', $auth, null, 1, static function (int $s): void {}) === ['outcome' => 'PACKAGE_LIST_MALFORMED'], 'a malformed list stops the run');

// PDF structure, also inside compressed object streams; a non-PDF is reported as such.
$compressed = "%PDF-1.5\n1 0 obj<</Length 9>>stream\n" . gzcompress('<</Type/Page/MediaBox[0 0 288 432]>>') . "\nendstream endobj\n" . str_repeat('y', 80) . "\n%%EOF\n";
$shape = check_pdf_shape($compressed);
ok($shape['validPdf'] && $shape['version'] === '1.5' && $shape['pages'] === 1 && $shape['pageSizes'] === ['288.0 x 432.0 pt (102 x 152 mm)'], 'compressed page boxes are read: ' . json_encode($shape));
ok(check_pdf_shape('<html>login</html>')['validPdf'] === false && check_pdf_shape("%PDF-1.4\nno end")['validPdf'] === false, 'an HTML page or a truncated PDF is not valid');

echo "PASS Trendyol label check is read-only ({$checks} checks)\n";
