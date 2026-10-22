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
