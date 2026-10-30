<?php

declare(strict_types=1);

// Confirmare source access (API 2.40.0, Phase 4A) without a database: the protocol v1 strings (shared vectors with YD
// SOFT tests/source-access/run.php), the configuration allowlist, the SSRF guard of the transport, the client's trust
// rules (a signature for this very request, nothing else) and the strict order detail. Run: php tests/source-access.php

use Arasya\Operations\Config\Config;
use Arasya\Operations\Integration\SourceAccess\SourceAccessClient;
use Arasya\Operations\Integration\SourceAccess\SourceAccessException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessProtocol;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
use Arasya\Operations\Integration\SourceAccess\SourceAccessTarget;
use Arasya\Operations\Integration\SourceAccess\SourceFinance;
use Arasya\Operations\Integration\SourceAccess\SourceInvoice;
use Arasya\Operations\Integration\SourceAccess\SourceOrderDetail;
use Arasya\Operations\Integration\SourceAccess\SourceOrderEdit;
use Arasya\Operations\Integration\SourceAccess\StreamSourceAccessTransport;
use Arasya\Operations\Tests\MutableClock;
use Arasya\Operations\Tests\SourceAccessFakeShop;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestDoubles.php';
require __DIR__ . '/SourceAccessFakeShop.php';

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
};
$throws = static function (callable $run, string $code, string $label) use ($check): void {
    try {
        $run();
        $check(false, "{$label}: expected {$code}, nothing thrown");
    } catch (SourceAccessException $e) {
        $check($e->codeName === $code, "{$label}: expected {$code}, got {$e->codeName}");
    }
};

// ---- 1. Protocol v1: the shared vectors (the same constants are asserted in YD SOFT) ----------------------------------
const VECTOR_KEY = 'arasya-access-test-key-0123456789abcdef-vector';
const VECTOR_NONCE = '0123456789abcdef0123456789abcdef';
$vectorBody = '{"orderId":"1234","orderNumber":"1234","actor":{"employeeId":"00000000-0000-4000-8000-000000000001","role":"assignee"},"requestId":"req-vector-1"}';
$check(SourceAccessProtocol::sign(VECTOR_KEY, SourceAccessProtocol::requestString('/yd-arasya/v1/confirmare/detail', 'trendhome', 'th-2026-a', 1790000000, VECTOR_NONCE, $vectorBody))
    === 'v1=eb17a521bd7a0308249d288b1ff9f4e555c73d9d81382c2bd19eda8ea285580d', 'request vector');
$check(SourceAccessProtocol::sign(VECTOR_KEY, SourceAccessProtocol::responseString('trendhome', 'th-2026-a', VECTOR_NONCE, 404, '{"error":{"code":"order_not_found"}}'))
    === 'v1=c81ee94ff37d3cd71080203d94cb8fa437dfcbddb11aa6d6ea4e414085c5387d', 'response vector');
$signed = SourceAccessProtocol::sign(VECTOR_KEY, 'x');
$check(SourceAccessProtocol::matches(VECTOR_KEY, 'x', $signed) && !SourceAccessProtocol::matches(VECTOR_KEY, 'y', $signed) && !SourceAccessProtocol::matches('other-key-0123456789abcdef0123456789', 'x', $signed)
    && !SourceAccessProtocol::matches(VECTOR_KEY, 'x', strtoupper($signed)) && !SourceAccessProtocol::matches(VECTOR_KEY, 'x', ''), 'signature comparison is exact');

// ---- 2. Configuration: the allowlist fails closed -------------------------------------------------------------------
$event = 'trendhome-event-secret-0123456789abcdef0123';
$accessKey = 'trendhome-access-key-0123456789abcdef-abcdef';
$config = static fn (string $environment, array $access): Config => new Config(
    environment: $environment, appSecret: str_repeat('a', 32), dbHost: 'x', dbPort: 3306, dbName: 'x', dbUser: 'x', dbPassword: 'x',
    allowedOrigins: [$environment === 'production' ? 'https://staff.example' : 'http://127.0.0.1:4174'], sessionTtlSeconds: 3600, sessionTouchIntervalSeconds: 300,
    loginUsernameLimit: 5, loginIpLimit: 30, loginWindowSeconds: 900, trustProxy: false, trustedProxies: [],
    sourceSecrets: ['trendhome' => $event, 'outletperdele' => 'outlet-event-secret-0123456789abcdef012345'],
    sourceAccess: $access,
);
$registry = static fn (string $environment, array $raw): SourceAccessRegistry => SourceAccessRegistry::fromConfig($config($environment, ['trendhome' => $raw]));
$issue = static fn (string $environment, array $raw): ?string => $registry($environment, $raw)->issues()[0]['code'] ?? null;
$good = ['mode' => 'read', 'url' => 'https://trendhome.example', 'key' => "th-2026-a:{$accessKey}"];
$target = $registry('production', $good)->find('trendhome');
$check($target instanceof SourceAccessTarget && $target->baseUrl === 'https://trendhome.example' && $target->kid === 'th-2026-a' && !$target->allowsEdit(), 'a valid production target');
$check(!str_contains(print_r($target, true), $accessKey) && !str_contains(var_export($target->__debugInfo(), true), $accessKey), 'the key never appears in a dump');
$check($registry('production', ['mode' => 'off'] + $good)->find('trendhome') === null && $registry('production', [])->issues() === [], 'off (and absent) is no target and no issue');
$check(SourceAccessRegistry::fromConfig($config('production', []))->find('trendhome') === null, 'default: no source access at all');
foreach ([
    ['production', ['url' => 'http://trendhome.example'], 'invalid_url', 'production refuses plain HTTP'],
    ['production', ['url' => 'https://trendhome.example:8443'], 'invalid_url', 'production refuses a non-standard port'],
    ['production', ['url' => 'https://127.0.0.1'], 'invalid_url', 'production refuses an address literal'],
    ['production', ['url' => 'https://localhost'], 'invalid_url', 'production refuses localhost'],
    ['production', ['url' => 'https://trendhome.example/wp-json'], 'invalid_url', 'no path in the base URL'],
    ['production', ['url' => 'https://user:pass@trendhome.example'], 'invalid_url', 'no credentials in the URL'],
    ['production', ['url' => 'https://trendhome.example?x=1'], 'invalid_url', 'no query'],
    ['production', ['url' => 'ftp://trendhome.example'], 'invalid_url', 'only http(s)'],
    ['test', ['url' => 'http://10.0.0.5'], 'invalid_url', 'outside production plain HTTP only to loopback'],
    ['production', ['key' => $accessKey], 'invalid_key', 'the key needs a key id'],
    ['production', ['key' => 'TH:' . $accessKey], 'invalid_key', 'key ids are lower case'],
    ['production', ['key' => 'th-2026-a:short'], 'invalid_key', 'a short key is refused'],
    ['production', ['key' => 'th-2026-a:CHANGE_ME-0123456789abcdef0123456789'], 'invalid_key', 'a placeholder key is refused'],
    ['production', ['key' => "th-2026-a:{$event}"], 'key_reuses_event_secret', 'the event secret is never an access key'],
    ['production', ['mode' => 'write'], 'invalid_mode', 'unknown modes are refused'],
] as [$environment, $change, $code, $label]) {
    $check($registry($environment, $change + $good)->find('trendhome') === null && $issue($environment, $change + $good) === $code, $label);
}
$check($registry('test', ['url' => 'http://127.0.0.1:8099'] + $good)->find('trendhome')?->baseUrl === 'http://127.0.0.1:8099', 'outside production a loopback test shop is allowed');
$check(SourceAccessRegistry::fromConfig($config('production', ['unknownshop' => $good]))->issues()[0]['code'] === 'source_not_configured', 'only a configured signed source can have access');
$check(!str_contains(json_encode($registry('production', ['key' => "th-2026-a:{$event}"] + $good)->issues()), $event), 'an issue never echoes a value');
// API 2.41.0: the finance mode of a target (default off; an unknown value keeps the whole source off).
$check($target->finance === 'off' && !$target->allowsFinanceRead() && !$target->allowsFinanceRecord(), 'finance is off unless configured');
$check($registry('production', ['finance' => 'read'] + $good)->find('trendhome')?->allowsFinanceRead() === true && $registry('production', ['finance' => 'read'] + $good)->find('trendhome')?->allowsFinanceRecord() === false, 'finance read: the summary only');
$check($registry('production', ['finance' => 'RECORD '] + $good)->find('trendhome')?->allowsFinanceRecord() === true, 'finance record (case and spaces normalized)');
$check($registry('production', ['finance' => 'refund'] + $good)->find('trendhome') === null && $issue('production', ['finance' => 'refund'] + $good) === 'invalid_finance_mode', 'an unknown finance mode keeps the source off, reported by code');
$check($registry('production', ['mode' => 'off', 'finance' => 'record'] + $good)->find('trendhome') === null, 'finance never works without source access');

// ---- 3. Transport: SSRF guard (no network: every refusal happens before a connection) -------------------------------
$resolver = static fn (array $map): Closure => static fn (string $host): array => $map[$host] ?? [];
$path = '/wp-json/yd-arasya/v1/confirmare/detail';
foreach ([
    ['10.0.0.8', 'a private address'], ['192.168.1.20', 'a private address'], ['172.16.4.1', 'a private address'], ['127.0.0.1', 'loopback in production'],
    ['169.254.169.254', 'the metadata address'], ['100.64.0.1', 'carrier-grade NAT'], ['0.0.0.0', 'the unspecified address'], ['::1', 'IPv6 loopback'],
    ['fd00::1', 'IPv6 unique local'], ['fe80::1', 'IPv6 link local'], ['::ffff:10.0.0.1', 'an IPv4-mapped address'], ['198.18.0.1', 'the benchmark range'],
] as [$address, $label]) {
    $throws(static fn () => (new StreamSourceAccessTransport(false, 2, $resolver(['shop.example' => [$address]])))->post('https://shop.example', $path, [], '{}'), 'SOURCE_ADDRESS_REFUSED', "refuses {$label} ({$address})");
}
$throws(static fn () => (new StreamSourceAccessTransport(false, 2, $resolver(['shop.example' => ['93.184.216.34', '10.0.0.8']])))->post('https://shop.example', $path, [], '{}'), 'SOURCE_ADDRESS_REFUSED', 'one private record among public ones is refused (no rebinding mix)');
$throws(static fn () => (new StreamSourceAccessTransport(false, 2, $resolver([])))->post('https://shop.example', $path, [], '{}'), 'SOURCE_UNREACHABLE', 'an unresolvable host is unreachable');
$throws(static fn () => (new StreamSourceAccessTransport(false, 2))->post('https://127.0.0.1', $path, [], '{}'), 'SOURCE_ADDRESS_REFUSED', 'an address literal is checked too');
$throws(static fn () => (new StreamSourceAccessTransport(false, 2))->post('http://shop.example', $path, [], '{}'), 'SOURCE_ACCESS_NOT_CONFIGURED', 'production transport never speaks plain HTTP');
$throws(static fn () => (new StreamSourceAccessTransport(true, 2, $resolver(['shop.example' => ['93.184.216.34']])))->post('http://shop.example', $path, [], '{}'), 'SOURCE_ADDRESS_REFUSED', 'plain HTTP only ever reaches loopback');
foreach (['/wp-json/wc/v3/orders/1', '/wp-json/yd-arasya/v1/../wc/v3/orders', '/wp-admin/admin-ajax.php', '/wp-json/yd-arasya/v1/confirmare/detail?x=1', '/wp-json/yd-arasya/v1/confirmare/'] as $badPath) {
    $throws(static fn () => (new StreamSourceAccessTransport(false, 2, $resolver(['shop.example' => ['93.184.216.34']])))->post('https://shop.example', $badPath, [], '{}'), 'SOURCE_ACCESS_NOT_CONFIGURED', "only source access paths ({$badPath})");
}
$throws(static fn () => (new StreamSourceAccessTransport(false, 2, $resolver(['shop.example' => ['93.184.216.34']])))->post('https://shop.example', $path, ["X-Evil\r\nHost" => 'x'], '{}'), 'SOURCE_ACCESS_NOT_CONFIGURED', 'no header injection');
$throws(static fn () => (new StreamSourceAccessTransport(false, 2, $resolver(['shop.example' => ['93.184.216.34']])))->post('https://shop.example', $path, ['X-A' => "v\r\nInjected: 1"], '{}'), 'SOURCE_ACCESS_NOT_CONFIGURED', 'no header value injection');
$throws(static fn () => (new StreamSourceAccessTransport(false, 2))->post('https://user@shop.example', $path, [], '{}'), 'SOURCE_ACCESS_NOT_CONFIGURED', 'no credentials');

// ---- 4. Client: only an answer signed for this request is trusted -----------------------------------------------------
$clock = new MutableClock(new DateTimeImmutable('@1790000000'));
$shopKey = 'trendhome-access-key-0123456789abcdef-abcdef';
$shop = new SourceAccessFakeShop('trendhome', 'th-2026-a', $shopKey, ['1234' => ['id' => '1234', 'pricing' => 'sha256:' . str_repeat('a', 64)]], static fn (): int => 1790000000);
$client = new SourceAccessClient($shop, $clock);
$targetFor = static fn (string $key = 'trendhome-access-key-0123456789abcdef-abcdef', string $kid = 'th-2026-a', string $source = 'trendhome'): SourceAccessTarget => new SourceAccessTarget($source, 'read', 'https://trendhome.example', $kid, $key);
$payload = ['orderId' => '1234', 'orderNumber' => '1234', 'actor' => ['employeeId' => '00000000-0000-4000-8000-000000000001', 'role' => 'assignee'], 'requestId' => 'req-1'];
$detail = $client->call($targetFor(), SourceAccessClient::ROUTE_DETAIL, $payload);
$check(($detail['order']['id'] ?? null) === '1234' && count($shop->nonces) === 1, 'a signed request is answered and its nonce recorded');
$client->call($targetFor(), SourceAccessClient::ROUTE_DETAIL, $payload);
$check(count($shop->nonces) === 2, 'every request carries a fresh nonce (no replay of our own requests)');
$throws(static fn () => $client->call($targetFor('wrong-access-key-0123456789abcdef0123456789'), SourceAccessClient::ROUTE_DETAIL, $payload), 'SOURCE_ACCESS_DENIED', 'a wrong key is denied by the shop');
$throws(static fn () => $client->call($targetFor(kid: 'th-2026-b'), SourceAccessClient::ROUTE_DETAIL, $payload), 'SOURCE_ACCESS_DENIED', 'an unknown key id is denied');
$throws(static fn () => $client->call($targetFor(source: 'outletperdele'), SourceAccessClient::ROUTE_DETAIL, $payload), 'SOURCE_ACCESS_DENIED', 'a request signed for another store is denied (cross-store)');
$throws(static fn () => $client->call($targetFor(), '/wc/v3/orders', $payload), 'SOURCE_ACCESS_NOT_CONFIGURED', 'no route outside the reviewed list');
$late = new SourceAccessClient($shop, new MutableClock(new DateTimeImmutable('@1790000061')));
$throws(static fn () => $late->call($targetFor(), SourceAccessClient::ROUTE_DETAIL, $payload), 'SOURCE_ACCESS_DENIED', 'an expired request (61 s) is denied');
$shop->tamper = ['sign' => false];
$throws(static fn () => $client->call($targetFor(), SourceAccessClient::ROUTE_DETAIL, $payload), 'SOURCE_RESPONSE_UNTRUSTED', 'an unsigned 200 is not trusted');
$shop->tamper = ['nonce' => str_repeat('f', 32)];
$throws(static fn () => $client->call($targetFor(), SourceAccessClient::ROUTE_DETAIL, $payload), 'SOURCE_RESPONSE_UNTRUSTED', 'an answer signed for another request (replayed answer) is not trusted');
$shop->tamper = ['body' => '{"protocol":1,"order":{"id":"9999"}}', 'sign' => false];
$throws(static fn () => $client->call($targetFor(), SourceAccessClient::ROUTE_DETAIL, $payload), 'SOURCE_RESPONSE_UNTRUSTED', 'a body changed after signing is not trusted');
$shop->tamper = ['body' => 'not json'];
$throws(static fn () => $client->call($targetFor(), SourceAccessClient::ROUTE_DETAIL, $payload), 'SOURCE_RESPONSE_INVALID', 'a signed non-JSON answer is invalid');
$throws(static fn () => $client->call($targetFor(), SourceAccessClient::ROUTE_DETAIL, ['orderId' => '1', 'orderNumber' => '1'] + $payload), 'SOURCE_ORDER_NOT_FOUND', 'an unknown order is not found');
$shop->orders['1235'] = ['id' => '1235', 'eligible' => false];
$throws(static fn () => $client->call($targetFor(), SourceAccessClient::ROUTE_DETAIL, ['orderId' => '1235', 'orderNumber' => '1235'] + $payload), 'SOURCE_ORDER_NOT_ELIGIBLE', 'a legacy order is not eligible');
$shop->tamper = ['status' => 500, 'body' => '{"error":{"code":"weird <script>"}}'];
try {
    $client->call($targetFor(), SourceAccessClient::ROUTE_DETAIL, $payload);
    $check(false, 'an unknown error code throws');
} catch (SourceAccessException $e) {
    $check($e->codeName === 'SOURCE_ERROR' && $e->sourceCode === null && !str_contains($e->getMessage(), 'script'), 'unknown upstream text is never relayed');
}

// ---- 5. The detail: strict, only known fields, only this order --------------------------------------------------------
$raw = SourceAccessFakeShop::detail(['id' => '1234', 'pricing' => 'sha256:' . str_repeat('b', 64)], 1790000000);
$normalized = SourceOrderDetail::from($raw + ['secretField' => 'x'], '1234', '1234');
$check($normalized['contact']['billing']['phone'] === '0700000123' && $normalized['contact']['phoneAvailable'] === true && count($normalized['lines']) === 2 && $normalized['totals']['total'] === '719.00', 'the detail keeps contact, lines and totals');
$check(!array_key_exists('secretField', $normalized), 'unknown fields are dropped');
$invalid = static function (array $data, string $label) use ($throws): void {
    $throws(static fn () => SourceOrderDetail::from($data, '1234', '1234'), 'SOURCE_RESPONSE_INVALID', $label);
};
$invalid(['order' => ['id' => '9999', 'number' => '9999'] + $raw['order']] + $raw, 'an answer about another order is refused');
$invalid(['protocol' => 2] + $raw, 'an unknown protocol version is refused');
$bad = $raw;
$bad['totals']['total'] = '7e2';
$invalid($bad, 'a malformed amount is refused');
$bad = $raw;
$bad['contact']['billing']['phone'] = '0700<script>';
$invalid($bad, 'a malformed telephone number is refused');
$bad = $raw;
$bad['lines'][0]['quantity'] = -1;
$invalid($bad, 'a negative quantity is refused');
$bad = $raw;
$bad['lines'][0]['name'] = "Draperie\x00";
$invalid($bad, 'control characters are refused');
$bad = $raw;
$bad['version'] = 'md5:abc';
$invalid($bad, 'a malformed version is refused');
$bad = $raw;
$bad['lines'] = array_fill(0, 201, $raw['lines'][0]);
$invalid($bad, 'too many lines are refused');
$bad = $raw;
$bad['payment']['financial']['state'] = 'paid';
$invalid($bad, 'an unknown financial state is refused');

// API 2.51.0: the line picture, only https on the shop's own host; an older shop sends none.
$check(array_key_exists('image', $normalized['lines'][0]) && $normalized['lines'][0]['image'] === null, 'a line without a picture (an older shop) has image null');
$pictured = static function (mixed $image, ?string $host = 'www.trendhome.ro') use ($raw): mixed {
    $data = $raw;
    $data['lines'][0]['image'] = $image;
    return SourceOrderDetail::from($data, '1234', '1234', $host)['lines'][0]['image'];
};
$good = ['url' => 'https://www.trendhome.ro/wp-content/uploads/2026/10/draperie-gri-600x600.jpg', 'width' => 600, 'height' => 600, 'kind' => 'variation'];
$check($pictured($good) === $good, 'a variation picture on the shop host is relayed as it is');
$check($pictured(['url' => 'https://cdn.trendhome.ro/a.jpg'] + $good) !== null && $pictured(['url' => 'https://trendhome.ro/a.jpg'] + $good) !== null, 'the shop domain and its subdomains qualify');
foreach ([
    'another host' => 'https://evil.example/a.jpg',
    'a look-alike host' => 'https://trendhome.ro.evil.example/a.jpg',
    'a suffix host' => 'https://eviltrendhome.ro/a.jpg',
    'plain http' => 'http://www.trendhome.ro/a.jpg',
    'credentials' => 'https://user:pw@www.trendhome.ro/a.jpg',
    'a port' => 'https://www.trendhome.ro:8443/a.jpg',
    'javascript' => 'javascript:alert(1)',
    'a quote' => 'https://www.trendhome.ro/a".jpg',
] as $label => $url) {
    $check($pictured(['url' => $url] + $good) === null, "a picture address with {$label} is dropped (the order is still shown)");
}
$check($pictured($good, null) === null, 'without a known shop host no picture is relayed');
$throws(static fn () => $pictured(['kind' => 'gallery'] + $good), 'SOURCE_RESPONSE_INVALID', 'an unknown picture kind is refused');
$throws(static fn () => $pictured(['width' => '600'] + $good), 'SOURCE_RESPONSE_INVALID', 'a malformed picture size is refused');
$throws(static fn () => $pictured('https://www.trendhome.ro/a.jpg'), 'SOURCE_RESPONSE_INVALID', 'a picture that is not an object is refused');

// ---- 5b. Phase 4B: strict operations, edit answers, refusals ---------------------------------------------------------
$invalidOps = static function (mixed $ops, string $label) use ($check): void {
    try {
        SourceOrderEdit::operations($ops);
        $check(false, "{$label}: accepted");
    } catch (InvalidArgumentException) {
        $check(true, $label);
    }
};
$good = [['op' => 'update_line', 'lineId' => '11', 'quantity' => 2, 'fields' => ['lungimea' => '3.5', 'manopera' => '13:Manopera']], ['op' => 'add_line', 'productId' => '601', 'quantity' => 1], ['op' => 'remove_line', 'lineId' => '12'], ['op' => 'set_shipping', 'shippingLineId' => '19', 'method' => 'free_shipping:4']];
$check(SourceOrderEdit::operations($good) === $good, 'the four operations of Phase 4B are accepted as sent');
$invalidOps([['op' => 'update_line', 'lineId' => '11', 'subtotal' => '1.00']], 'no price field exists (no price override)');
$invalidOps([['op' => 'set_total', 'total' => '1.00']], 'no other operation exists');
$invalidOps([['op' => 'update_line', 'lineId' => '11'], ['op' => 'remove_line', 'lineId' => '11']], 'one change per line');
$invalidOps([['op' => 'add_line', 'productId' => '601']], 'an added line names its quantity');
$invalidOps([['op' => 'update_line', 'lineId' => '11', 'fields' => ['x' => ['nested']]]], 'field values are strings');
$invalidOps(array_fill(0, 21, ['op' => 'remove_line', 'lineId' => '1']), 'at most 20 operations');
$check(SourceOrderEdit::auditCodes($good)[0] === ['op' => 'update_line', 'lineId' => '11', 'quantity' => 2, 'fields' => ['lungimea', 'manopera']], 'the audit keeps field names, never values');
$editShop = new SourceAccessFakeShop('trendhome', 'th-2026-a', $shopKey, ['123' => ['id' => '123']], static fn (): int => 1790000000);
$editClient = new SourceAccessClient($editShop, $clock);
$base = ['orderId' => '123', 'orderNumber' => '123', 'actor' => ['employeeId' => '00000000-0000-4000-8000-0000000000a1', 'role' => 'assignee'], 'requestId' => 'r1'];
$catalog = SourceOrderEdit::catalog($editClient->call($targetFor(), SourceAccessClient::ROUTE_CATALOG, $base));
$check($catalog['lines'][0]['calculator']['fields'][1]['options'][1]['value'] === '18:Manopera Rejansa Bara', 'the catalog validates (calculator options)');
$preview = $editClient->call($targetFor(), SourceAccessClient::ROUTE_PREVIEW, $base + ['expectedVersion' => 'sha256:' . hash('sha256', 'version-123'), 'operations' => $good]);
$valid = SourceOrderEdit::result($preview, false);
$check($valid['after']['total'] === '754.00' && $valid['previewToken'] === $preview['previewToken'], 'a preview validates');
foreach ([
    'an applicable preview with blockers' => ['blockers' => [['code' => 'out_of_stock']]],
    'an amount that is not money' => ['after' => ['total' => '754'] + $preview['after']],
    'an unknown money impact' => ['financial' => ['impact' => 'refund_now'] + $preview['financial']],
    'an added line with a before' => ['lines' => [['state' => 'added'] + $preview['lines'][0]]],
    'a malformed token' => ['previewToken' => 'x'],
    'another protocol' => ['protocol' => 2],
] as $label => $change) {
    $throws(static fn () => SourceOrderEdit::result(array_replace($preview, $change), false), 'SOURCE_RESPONSE_INVALID', "{$label} is rejected whole");
}
$throws(static fn () => SourceOrderEdit::result($preview, true), 'SOURCE_RESPONSE_INVALID', 'a preview is never taken for an applied edit');
$throws(static fn () => SourceOrderEdit::refusal(['error' => ['code' => 'please_retry_with_admin']]), 'SOURCE_ERROR', 'an unknown refusal code is not relayed');
$check(SourceOrderEdit::refusal(['error' => ['code' => 'version_changed'], 'version' => 'sha256:' . str_repeat('a', 64)]) === ['code' => 'version_changed', 'version' => 'sha256:' . str_repeat('a', 64), 'blockers' => []], 'a known refusal carries the new version');
$refusal = $editClient->exchange($targetFor(), SourceAccessClient::ROUTE_PREVIEW, $base + ['expectedVersion' => 'sha256:' . str_repeat('0', 64), 'operations' => $good]);
$check($refusal['status'] === 409 && $refusal['body']['error']['code'] === 'version_changed', 'exchange returns a signed refusal');
$editShop->tamper = ['sign' => false, 'status' => 409];
$throws(static fn () => $editClient->exchange($targetFor(), SourceAccessClient::ROUTE_PREVIEW, $base + ['expectedVersion' => 'x', 'operations' => $good]), 'SOURCE_RESPONSE_UNTRUSTED', 'an unsigned refusal is not trusted either');
$throws(static fn () => $editClient->call($targetFor(), '/yd-arasya/v1/confirmare/refund', $base), 'SOURCE_ACCESS_NOT_CONFIGURED', 'a route outside the reviewed list is never called');

// ---- 5b. Finance answers (API 2.41.0): strict, whole or nothing --------------------------------------------------------
$financeShop = new SourceAccessFakeShop('trendhome', 'th-2026-a', $shopKey, ['123' => ['id' => '123', 'finance' => ['cod' => false, 'total' => '650.00', 'paid' => '500.00', 'original' => '500.00', 'pricing' => 'sha256:' . str_repeat('a', 64)]]], static fn (): int => 1790000000);
$summary = $financeShop->financeSummary('123', 1790000000);
$valid = SourceFinance::summary($summary, '123', '123');
$check($valid['totals'] === ['current' => '650.00', 'original' => '500.00', 'refunded' => '0.00'] && $valid['balance']['outstanding'] === '150.00' && $valid['financial']['attention'] === 'additional_payment_due', 'a valid finance summary');
$broken = static function (callable $change) use ($summary): array { $copy = $summary; $change($copy); return $copy; };
foreach ([
    'another order' => static function (array &$s): void { $s['order']['id'] = '124'; },
    'an electronic refund offered' => static function (array &$s): void { $s['capabilities']['electronicRefund'] = true; },
    'an amount as a number' => static function (array &$s): void { $s['totals']['current'] = 650.0; },
    'an open attention with cleared' => static function (array &$s): void { $s['financial'] = ['state' => 'cleared', 'basis' => 'online_payment', 'attention' => 'transfer_pending']; },
    'a cleared attention with waiting' => static function (array &$s): void { $s['financial']['attention'] = 'refund_liability'; },
    'an unknown attention' => static function (array &$s): void { $s['financial']['attention'] = 'refund_now'; },
    'a COD order without its advance' => static function (array &$s): void { $s['order']['cod'] = true; $s['advance'] = null; },
    'a malformed revision' => static function (array &$s): void { $s['revision'] = 'v1'; },
    'an entry with an unknown state' => static function (array &$s): void { $s['entries'] = [['id' => '00000000-0000-4000-9000-000000000001', 'kind' => 'transfer_in', 'purpose' => 'additional_payment', 'amount' => '1.00', 'reference' => 'OP 1', 'valueDate' => '2026-10-22', 'state' => 'paid', 'submittedBy' => '00000000-0000-4000-8000-000000000001', 'submittedAt' => '2026-10-22T10:00:00Z', 'decidedBy' => null, 'decidedAt' => null, 'decisionCode' => null]]; },
] as $label => $change) {
    $throws(static fn () => SourceFinance::summary($broken($change), '123', '123'), 'SOURCE_RESPONSE_INVALID', "a finance summary with {$label} is refused");
}
$cardRefund = $broken(static function (array &$s): void { $s['entries'] = [['id' => '00000000-0000-4000-9000-000000000002', 'kind' => 'refund_out', 'purpose' => 'refund', 'amount' => '500.00', 'reference' => 'NTP-1', 'valueDate' => '2026-10-25', 'state' => 'submitted', 'submittedBy' => '00000000-0000-4000-8000-000000000001', 'submittedAt' => '2026-10-25T10:00:00Z', 'decidedBy' => null, 'decidedAt' => null, 'decisionCode' => null, 'flags' => ['card_full_refund']]]; });
$check(SourceFinance::summary($cardRefund, '123', '123')['entries'][0]['flags'] === ['card_full_refund'], 'the NETOPIA full card refund entry (YD SOFT 2.20.0) keeps the finance read of its order readable');
$throws(static fn () => SourceFinance::summary($broken(static function (array &$s): void { $s['entries'] = [['id' => '00000000-0000-4000-9000-000000000002', 'kind' => 'refund_out', 'purpose' => 'refund', 'amount' => '500.00', 'reference' => 'NTP-1', 'valueDate' => '2026-10-25', 'state' => 'submitted', 'submittedBy' => '00000000-0000-4000-8000-000000000001', 'submittedAt' => '2026-10-25T10:00:00Z', 'decidedBy' => null, 'decidedAt' => null, 'decisionCode' => null, 'flags' => ['refund_now']]]; }), '123', '123'), 'SOURCE_RESPONSE_INVALID', 'an unknown entry flag is still refused');
$check(SourceFinance::refusal(['error' => ['code' => 'revision_changed'], 'revision' => 'sha256:' . str_repeat('b', 64)]) === ['code' => 'revision_changed', 'revision' => 'sha256:' . str_repeat('b', 64)], 'a refusal with the current revision');
$throws(static fn () => SourceFinance::refusal(['error' => ['code' => 'paid_anyway']]), 'SOURCE_ERROR', 'an unknown refusal code is a source error');
// YD SOFT 2.16 safety refusals: valid only as a 409 answer to their own actions, a protocol violation anywhere else.
$rev = 'sha256:' . str_repeat('c', 64);
foreach (SourceFinance::ACTION_REFUSALS as $code => $actions) {
    foreach ($actions as $own) {
        $check(SourceFinance::refusal(['error' => ['code' => $code], 'revision' => $rev], $own, 409) === ['code' => $code, 'revision' => $rev], "{$code} is a refusal of {$own}");
    }
    foreach (array_diff(['submit_transfer', 'decide_transfer', 'submit_refund', 'decide_refund', 'acknowledge_liability'], $actions) as $other) {
        $throws(static fn () => SourceFinance::refusal(['error' => ['code' => $code]], $other, 409), 'SOURCE_ERROR', "{$code} answered to {$other} is a source error");
    }
    foreach ([400, 403, 404, 410, 422, 423, 429] as $status) {
        $throws(static fn () => SourceFinance::refusal(['error' => ['code' => $code]], $actions[0], $status), 'SOURCE_ERROR', "{$code} with HTTP {$status} is a source error");
    }
    $throws(static fn () => SourceFinance::refusal(['error' => ['code' => $code]]), 'SOURCE_ERROR', "{$code} without its action and status is a source error");
    $throws(static fn () => SourceFinance::refusal(['error' => ['code' => $code]], $actions[0]), 'SOURCE_ERROR', "{$code} without its status is a source error");
}
$check(array_keys(SourceFinance::ACTION_REFUSALS) === ['finance_open', 'order_cancelled'] && array_diff(array_keys(SourceFinance::ACTION_REFUSALS), SourceFinance::REFUSALS) === [], 'the action-bound codes are exactly the two safety refusals, both known');
foreach (array_diff(SourceFinance::REFUSALS, array_keys(SourceFinance::ACTION_REFUSALS)) as $code) {
    $check(SourceFinance::refusal(['error' => ['code' => $code]]) === ['code' => $code, 'revision' => null] && SourceFinance::refusal(['error' => ['code' => $code]], 'submit_transfer', 422) === ['code' => $code, 'revision' => null], "{$code} keeps its meaning for any action and status (older shops)");
}
$throws(static fn () => SourceFinance::applied(['protocol' => 1, 'applied' => true, 'action' => 'decide_transfer', 'entryId' => '00000000-0000-4000-9000-000000000001', 'summary' => $summary], 'submit_transfer', '123', '123'), 'SOURCE_RESPONSE_INVALID', 'an answer for another action is refused');
$check(SourceFinance::status(['state' => 'absent']) === ['state' => 'absent'], 'an action the shop never received');

// ---- 5c. YD SOFT 2.18.0 (owner decision: the advance is paid by bank transfer): strict transfer details, advance state,
// payment reference and the shop's record of unmatched transfers ---------------------------------------------------------
$pfShop = new SourceAccessFakeShop('trendhome', 'th-2026-a', $shopKey, ['777' => ['id' => '777', 'number' => '777', 'invoice' => ['cleared' => false, 'documentType' => 'invoice', 'autoEmail' => false, 'document' => null, 'op' => 'none', 'opKey' => null, 'mail' => 'none', 'mailCount' => 0, 'history' => [],
    'cod' => true, 'proformaInvoiced' => false, 'pf' => ['document' => ['series' => 'PRF', 'number' => '0007', 'at' => 1790000000, 'by' => '00000000-0000-4000-8000-0000000000a1'], 'op' => 'issued', 'opKey' => null, 'mail' => 'none', 'mailCount' => 0, 'invoiced' => null, 'history' => []]]]]);
$invoiceState = $pfShop->invoiceSummary('777', 1790000000, 'operator');
$pf = SourceInvoice::summary($invoiceState, '777', '777')['proforma'];
$check($pf['transfer'] === ['iban' => 'RO49AAAA1B31007593840000', 'bank' => 'Banca TEST', 'beneficiary' => 'TEST Trend Home SRL', 'reference' => 'TH777', 'bic' => null] && $pf['document']['reference'] === 'TH777' && $pf['advance']['state'] === 'waiting' && $pf['advance']['verifying'] === false, 'the proforma transfer details and the advance state validate');
$older = $invoiceState;
unset($older['proforma']['transfer'], $older['proforma']['advance']['state'], $older['proforma']['advance']['verifying'], $older['proforma']['document']['reference']);
foreach (['money', 'totalAcceptance', 'advanceAcceptance', 'lowAdvance', 'cancel', 'cancelled', 'superseded', 'deadline', 'cod'] as $field) {
    unset($older['proforma'][$field]);
}
unset($older['proforma']['blockers']['proformaCancel'], $older['proforma']['blockers']['totalAccept'], $older['proforma']['blockers']['advanceAccept'], $older['eFactura']);
$olderPf = SourceInvoice::summary($older, '777', '777')['proforma'];
$check($olderPf['transfer'] === null && $olderPf['advance']['state'] === null && $olderPf['advance']['verifying'] === false, 'a shop before the bank transfer decision: no transfer details, no advance state');
$check($olderPf['money'] === null && $olderPf['totalAcceptance'] === null && $olderPf['cancelled'] === [] && $olderPf['deadline'] === null && $olderPf['blockers']['proformaCancel'] === ['not_supported'] && $olderPf['blockers']['advanceAccept'] === ['not_supported']
    && SourceInvoice::summary($older, '777', '777')['eFactura'] === null, 'a 2.18.0 shop (no master update fields): nothing invented, its new actions are not_supported');
// ---- 5d. YD SOFT 2.19.0 (owner master update): the one money calculation, acceptances, cancellation, deadline, AWB -----
$m = SourceInvoice::summary($invoiceState, '777', '777');
$check($m['proforma']['money'] === ['currency' => 'RON', 'percentage' => 30.0, 'known' => true, 'total' => '719.00', 'advanceRequired' => '215.70', 'received' => '0.00', 'refunded' => '0.00', 'net' => '0.00', 'remaining' => '719.00', 'excess' => '0.00', 'advanceGap' => '215.70']
    && $m['proforma']['totalAcceptance']['state'] === 'accepted' && $m['proforma']['deadline']['state'] === 'none' && $m['eFactura'] === ['state' => 'not_tracked'] && $m['proforma']['blockers']['proformaCancel'] === [], 'the master update fields validate (money adds up: owed 719.00, advance missing 215.70)');
$position = ['currency' => 'RON', 'percentage' => 30, 'total' => '1000.00', 'advanceRequired' => '300.00', 'received' => '250.00', 'refunded' => '0.00', 'net' => '250.00', 'remaining' => '750.00', 'excess' => '0.00', 'advanceGap' => '50.00', 'known' => true];
$check(SourceInvoice::moneyPosition($position)['remaining'] === '750.00' && SourceInvoice::moneyPosition(['received' => '1050.00', 'net' => '1050.00', 'remaining' => '0.00', 'excess' => '50.00', 'advanceGap' => '0.00'] + $position)['excess'] === '50.00', 'the owner\'s examples validate: 1000 / 250 owes 750; 1000 / 1050 owes 0 with 50 excess');
foreach ([
    'owed computed wrong (700 instead of 750)' => ['remaining' => '700.00'],
    'a negative amount owed' => ['remaining' => '-50.00'],
    'both owed and excess' => ['excess' => '10.00'],
    'net not received minus refunded' => ['refunded' => '10.00'],
    'the advance difference wrong' => ['advanceGap' => '0.00'],
    'a percentage above 100' => ['percentage' => 130],
] as $label => $change) {
    $throws(static fn () => SourceInvoice::moneyPosition($change + $position), 'SOURCE_RESPONSE_INVALID', "a money calculation with {$label} is refused (never shown to an employee)");
}
foreach ([
    'an unknown cancellation state' => static function (array &$s): void { $s['proforma']['cancel'] = ['state' => 'maybe', 'at' => null, 'by' => null, 'reason' => 'other']; },
    'an unknown cancellation reason' => static function (array &$s): void { $s['proforma']['cancelled'] = [['series' => 'PRF', 'number' => '1', 'amount' => '1.00', 'currency' => 'RON', 'cancelledAt' => null, 'cancelledBy' => null, 'reason' => 'whim']]; },
    'a deadline without a date' => static function (array &$s): void { $s['proforma']['deadline'] = ['state' => 'open', 'firstCollection' => '2026-10-24', 'deadline' => null, 'daysLeft' => 3, 'level' => 'warning']; },
    'an unknown deadline level' => static function (array &$s): void { $s['proforma']['deadline'] = ['state' => 'open', 'firstCollection' => '2026-10-24', 'deadline' => '2026-11-15', 'daysLeft' => 3, 'level' => 'relaxed']; },
    'an AWB state "fixed"' => static function (array &$s): void { $s['proforma']['cod'] = ['state' => 'fixed', 'awb' => null, 'awbCod' => null, 'expected' => null, 'status' => null]; },
    'e-Factura "sent"' => static function (array &$s): void { $s['eFactura'] = ['state' => 'sent']; },
    'an acceptance state "maybe"' => static function (array &$s): void { $s['proforma']['totalAcceptance']['state'] = 'maybe'; },
    'a change of an unknown kind' => static function (array &$s): void { $s['proforma']['totalAcceptance']['changes'] = [['kind' => 'price_hack', 'name' => 'x']]; },
    'an unknown action blocker' => static function (array &$s): void { $s['proforma']['blockers']['advanceAccept'] = ['because']; },
    'a BIC that is not one' => static function (array &$s): void { $s['proforma']['transfer']['bic'] = 'ING BANK'; },
    'advanceDecisionRequired as text' => static function (array &$s): void { $s['proforma']['totalAcceptance']['advanceDecisionRequired'] = 'yes'; },
    'an unknown shipping state' => static function (array &$s): void { $s['proforma']['shipping'] = ['state' => 'fine', 'code' => null, 'current' => '25.00', 'expected' => '25.00']; },
    'a shipping mismatch without its code' => static function (array &$s): void { $s['proforma']['shipping'] = ['state' => 'mismatch', 'code' => null, 'current' => '25.00', 'expected' => '35.00']; },
    'an unknown shipping code' => static function (array &$s): void { $s['proforma']['shipping'] = ['state' => 'mismatch', 'code' => 'cheaper', 'current' => '25.00', 'expected' => null]; },
    'a shipping match with a code' => static function (array &$s): void { $s['proforma']['shipping'] = ['state' => 'match', 'code' => 'shipping_cost_mismatch', 'current' => '25.00', 'expected' => '25.00']; },
    'an advance acceptance of an unknown kind' => static function (array &$s): void { $s['proforma']['advanceAcceptance'] = ['at' => null, 'by' => null, 'required' => '215.70', 'received' => '200.00', 'reason' => null, 'low' => false, 'kind' => 'automatic']; },
] as $label => $change) {
    $copy = $invoiceState;
    $change($copy);
    $throws(static fn () => SourceInvoice::summary($copy, '777', '777'), 'SOURCE_RESPONSE_INVALID', "a state with {$label} is refused");
}
$kept = $invoiceState;
$kept['proforma']['advanceAcceptance'] = ['at' => '2026-10-25T09:00:00Z', 'by' => '00000000-0000-4000-8000-0000000000a1', 'required' => '215.70', 'received' => '200.00', 'reason' => null, 'low' => false, 'kind' => 'kept'];
$older = $invoiceState;
$older['proforma']['advanceAcceptance'] = ['at' => '2026-10-25T09:00:00Z', 'by' => '00000000-0000-4000-8000-0000000000a1', 'required' => '215.70', 'received' => '200.00', 'reason' => null, 'low' => false];
unset($older['proforma']['totalAcceptance']['advanceDecisionRequired'], $older['proforma']['shipping']);
$check(SourceInvoice::summary($kept, '777', '777')['proforma']['advanceAcceptance']['kind'] === 'kept' && SourceInvoice::summary($older, '777', '777')['proforma']['advanceAcceptance']['kind'] === 'accepted'
    && SourceInvoice::summary($older, '777', '777')['proforma']['totalAcceptance']['advanceDecisionRequired'] === null && SourceInvoice::summary($older, '777', '777')['proforma']['shipping'] === null,
    'an advance kept after a revision validates; a summary without the new fields reads as an operator acceptance and no shipping check (owner business rule 2026-10-25)');
$check(SourceInvoice::shipping(['state' => 'mismatch', 'code' => 'free_shipping_threshold_lost', 'current' => '0.00', 'expected' => null])['code'] === 'free_shipping_threshold_lost'
    && SourceInvoice::shipping(['state' => 'unverifiable', 'code' => 'shipping_method_unsupported', 'current' => '19.99', 'expected' => null])['state'] === 'unverifiable'
    && in_array('shipping_mismatch', SourceInvoice::CODES, true) && in_array('advance_decision_required', SourceInvoice::CODES, true), 'the shipping check states validate; the new refusal codes are known');
$check(SourceInvoice::deadline(['state' => 'open', 'firstCollection' => '2026-10-24', 'deadline' => '2026-11-15', 'daysLeft' => -1, 'level' => 'overdue'])['level'] === 'overdue'
    && SourceInvoice::cod(['state' => 'mismatch', 'awb' => '2345678', 'awbCod' => '750.00', 'expected' => '700.00', 'status' => 'AWB creat'])['state'] === 'mismatch', 'an overdue deadline and an AWB collecting the old amount validate (shown, never hidden)');
foreach ([
    'an IBAN with spaces' => static function (array &$s): void { $s['proforma']['transfer']['iban'] = 'RO49 AAAA 1B31 0075 9384 0000'; },
    'an IBAN that is a link' => static function (array &$s): void { $s['proforma']['transfer']['iban'] = 'https://pay.example/RO49'; },
    'a lower-case reference' => static function (array &$s): void { $s['proforma']['transfer']['reference'] = 'th777'; },
    'a reference with words' => static function (array &$s): void { $s['proforma']['document']['reference'] = 'TH 777 PAY'; },
    'an advance "settled"' => static function (array &$s): void { $s['proforma']['advance']['state'] = 'settled'; },
    'verifying as text' => static function (array &$s): void { $s['proforma']['advance']['verifying'] = 'yes'; },
    'a bank name with a control character' => static function (array &$s): void { $s['proforma']['transfer']['bank'] = "Banca\x07"; },
] as $label => $change) {
    $copy = $invoiceState;
    $change($copy);
    $throws(static fn () => SourceInvoice::summary($copy, '777', '777'), 'SOURCE_RESPONSE_INVALID', "a proforma with {$label} is refused");
}
$exception = ['id' => '00000000-0000-4000-9000-0000000000e1', 'amount' => '250.00', 'currency' => 'RON', 'reference' => 'OP EXC 1', 'valueDate' => '2026-10-24', 'reason' => 'unmatched', 'state' => 'open',
    'submittedBy' => '00000000-0000-4000-8000-0000000000f1', 'submittedAt' => '2026-10-24T09:00:00Z', 'resolvedBy' => null, 'resolvedAt' => null, 'resolution' => null, 'order' => null];
$listing = static fn (array $items): array => ['protocol' => 1, 'readAt' => '2026-10-24T09:00:00Z', 'available' => true, 'currency' => 'RON', 'exceptions' => $items];
$check(SourceFinance::exceptions($listing([$exception]))['exceptions'][0]['state'] === 'open', 'an open unmatched transfer validates');
$closed = ['state' => 'resolved', 'resolution' => 'recorded_on_order', 'resolvedBy' => '00000000-0000-4000-8000-0000000000f2', 'resolvedAt' => '2026-10-24T10:00:00Z', 'order' => ['id' => '777', 'number' => '777']] + $exception;
$check(SourceFinance::exceptions($listing([$closed]))['exceptions'][0]['order'] === ['id' => '777', 'number' => '777'], 'a transfer closed on its order validates');
foreach ([
    'an open one with a resolution' => ['resolution' => 'returned_to_payer'] + $exception,
    'a closed one without a resolution' => ['state' => 'resolved'] + $exception,
    'recorded on no order' => ['order' => null] + $closed,
    'an unknown reason' => ['reason' => 'gift'] + $exception,
    'an amount as a number' => ['amount' => 250.0] + $exception,
    'a reference with a quote' => ['reference' => "OP '1"] + $exception,
] as $label => $item) {
    $throws(static fn () => SourceFinance::exceptions($listing([$item])), 'SOURCE_RESPONSE_INVALID', "an exception {$label} is refused");
}
$throws(static fn () => SourceFinance::exceptionApplied(['protocol' => 1, 'applied' => true, 'action' => 'resolve_exception', 'entryId' => $exception['id'], 'listing' => $listing([$exception])], 'record_exception'), 'SOURCE_RESPONSE_INVALID', 'an answer for another exception action is refused');
$financeWithProforma = $summary;
$financeWithProforma['order']['cod'] = true;
$financeWithProforma['advance'] = ['percentage' => 30, 'required' => '195.00', 'collected' => '0.00', 'outstanding' => '195.00', 'onDelivery' => '650.00', 'paymentReference' => 'TH123', 'proforma' => ['series' => 'PRF', 'number' => '0007', 'amount' => '195.00', 'currency' => 'RON', 'reference' => 'TH123']];
$check(SourceFinance::summary($financeWithProforma, '123', '123')['advance']['paymentReference'] === 'TH123', 'the finance summary carries the payment reference and the proforma');
$financeWithProforma['advance']['paymentReference'] = 'TH 123';
$throws(static fn () => SourceFinance::summary($financeWithProforma, '123', '123'), 'SOURCE_RESPONSE_INVALID', 'a malformed payment reference is refused');
$financeClient = new SourceAccessClient($financeShop, $clock);
$financeTarget = new SourceAccessTarget('trendhome', 'read', 'https://trendhome.example', 'th-2026-a', $shopKey, 'record');
$read = SourceFinance::summary($financeClient->call($financeTarget, SourceAccessClient::ROUTE_FINANCE, ['orderId' => '123', 'orderNumber' => '123', 'actor' => ['employeeId' => '00000000-0000-4000-8000-000000000001', 'role' => 'finance'], 'requestId' => 'req-fin-1']), '123', '123');
$check($read['revision'] === $summary['revision'], 'the client reads a signed finance summary through the reviewed route');

// ---- 6. Transport over a real loopback socket (test environment only): POST, no redirect, size and time bounds ----------
$port = 18000 + random_int(0, 1999);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/source-access-loopback-router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
try {
    $deadline = microtime(true) + 5;
    while (@fsockopen('127.0.0.1', $port) === false && microtime(true) < $deadline) {
        usleep(50_000);
    }
    $loopback = new StreamSourceAccessTransport(true, 2);
    $answer = $loopback->post("http://127.0.0.1:{$port}", $path, ['X-Test-Mode' => 'ok'], '{"a":1}');
    $echo = json_decode($answer['body'], true);
    $check($answer['status'] === 200 && $echo['method'] === 'POST' && $echo['path'] === $path && $echo['body'] === '{"a":1}' && $echo['host'] === "127.0.0.1:{$port}", 'a loopback POST carries the exact body and host');
    $redirect = $loopback->post("http://127.0.0.1:{$port}", $path, ['X-Test-Mode' => 'redirect'], '{}');
    $check($redirect['status'] === 302 && str_contains($redirect['body'], 'redirect'), 'a redirect is returned as is, never followed');
    $throws(static fn () => $loopback->post("http://127.0.0.1:{$port}", $path, ['X-Test-Mode' => 'huge'], '{}'), 'SOURCE_RESPONSE_TOO_LARGE', 'an oversized answer is refused');
    $started = microtime(true);
    $throws(static fn () => $loopback->post("http://127.0.0.1:{$port}", $path, ['X-Test-Mode' => 'slow'], '{}'), 'SOURCE_UNREACHABLE', 'a slow shop times out');
    $check(microtime(true) - $started < 3.5, 'the timeout is bounded');
    $throws(static fn () => (new StreamSourceAccessTransport(false, 2))->post("http://127.0.0.1:{$port}", $path, [], '{}'), 'SOURCE_ACCESS_NOT_CONFIGURED', 'the production transport refuses the loopback test shop');
    $throws(static fn () => $loopback->post('http://127.0.0.1:1', $path, [], '{}'), 'SOURCE_UNREACHABLE', 'a closed port is unreachable');
} finally {
    proc_terminate($server);
}

fwrite(STDOUT, "PASS source access protocol, configuration, SSRF guard, client trust, detail and edit answers ({$checks} checks)\n");
