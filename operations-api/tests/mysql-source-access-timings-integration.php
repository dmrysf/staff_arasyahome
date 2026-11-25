<?php

declare(strict_types=1);

// API 2.81.0 (P0 2026-11-25, connection and performance), migration 062: every call from Operations to a shop is measured
// (source_access_timings: host, route, milliseconds, HTTP status, outcome, request id; never a body), and a shop that
// stopped answering does not hold every screen and worker for its timeout: after four failures without an answer in 60 s
// a READ to that shop fails at once (SOURCE_UNREACHABLE), per host, while an ACTION is always sent (its outcome stays
// what the shop says). A failed measurement never fails the call. Also: one confirmare paid-routing run at a time.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Integration\SourceAccess\MeasuredSourceAccessTransport;
use Arasya\Operations\Integration\SourceAccess\SourceAccessException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessProtocol;
use Arasya\Operations\Integration\SourceAccess\SourceAccessTransport;
use Arasya\Operations\Tests\MutableClock;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL source access timings integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };

$config = T::config($dbName);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
$pdo->exec('DELETE FROM source_access_timings');
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$all = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };

/** A TEST shop transport: answers signed, unsigned (a host error page) or not at all, and counts what reached it. */
final class TimingTestShops implements SourceAccessTransport
{
    /** @var array<string, string> host => 'ok' | 'down' | 'host_error' */
    public array $mode = [];
    /** @var list<string> */
    public array $calls = [];

    public function post(string $baseUrl, string $path, array $headers, string $body): array
    {
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $this->calls[] = $host . $path;
        return match ($this->mode[$host] ?? 'ok') {
            'down' => throw new SourceAccessException('SOURCE_UNREACHABLE'),
            'host_error' => ['status' => 503, 'headers' => [], 'body' => '<html>Service Unavailable</html>'],
            default => ['status' => 200, 'headers' => [strtolower(SourceAccessProtocol::HEADER_SIGNATURE) => 'sig'], 'body' => '{"ok":true}'],
        };
    }
}

$clock = new MutableClock(new DateTimeImmutable('2026-11-25 12:00:00', new DateTimeZone('UTC')));
$shops = new TimingTestShops();
$measured = new MeasuredSourceAccessTransport($shops, $pdo, $clock);
$th = 'https://trendhome.example';
$op = 'https://outletperdele.example';
$read = '/wp-json/yd-arasya/v1/confirmare/finance';
$action = '/wp-json/yd-arasya/v1/confirmare/finance-action';
$send = static function (string $base, string $path, string $requestId = 'test-req-1') use ($measured): string {
    try {
        $measured->post($base, $path, [], '{"requestId":"' . $requestId . '","orderId":"123","customer":{"name":"TEST Client"}}');
        return 'answered';
    } catch (SourceAccessException $e) {
        return $e->codeName;
    }
};

// ---- 1. Measured: one row per call, no body ------------------------------------------------------------------------------
$check($send($th, $read, 'test-req-ok') === 'answered', 'a signed answer passes through');
$rows = $all('SELECT * FROM source_access_timings');
$check(count($rows) === 1 && $rows[0]['shop_host'] === 'trendhome.example' && $rows[0]['route'] === 'finance' && (int) $rows[0]['http_status'] === 200 && $rows[0]['outcome'] === 'ok'
    && $rows[0]['request_id'] === 'test-req-ok' && (int) $rows[0]['duration_ms'] >= 0, 'the call is measured: host, route, status, outcome, request id');
$check(array_keys($rows[0]) === ['timing_id', 'called_at', 'shop_host', 'route', 'duration_ms', 'http_status', 'outcome', 'request_id'] && !str_contains((string) json_encode($rows), 'TEST Client'),
    'no body, order or customer field is stored');
$shops->mode['trendhome.example'] = 'host_error';
$send($th, $read);
$check((string) $one('SELECT outcome FROM source_access_timings ORDER BY timing_id DESC LIMIT 1') === 'host_error', 'an unsigned 5xx page of the host counts as a host error');

// ---- 2. The breaker: reads to a shop that stopped answering fail at once; actions are always sent -------------------------
$pdo->exec('DELETE FROM source_access_timings');
$shops->mode['trendhome.example'] = 'down';
for ($n = 0; $n < MeasuredSourceAccessTransport::BREAKER_FAILURES; $n++) {
    $check($send($th, $read) === 'SOURCE_UNREACHABLE', "failure {$n} reaches the shop");
    $clock->advance('+2 seconds');
}
$reached = count($shops->calls);
$check($send($th, $read) === 'SOURCE_UNREACHABLE' && count($shops->calls) === $reached && (string) $one('SELECT outcome FROM source_access_timings ORDER BY timing_id DESC LIMIT 1') === 'breaker_open',
    'after four failures without an answer the next read fails at once, without waiting for the shop (breaker_open)');
$check($send($th, $action) === 'SOURCE_UNREACHABLE' && count($shops->calls) === $reached + 1, 'an action to the same shop is still sent (its outcome stays the shop\'s, never a guessed "not sent")');
$check($send($op, $read) === 'answered', 'the other shop is not affected (per host)');
$clock->advance('+' . (MeasuredSourceAccessTransport::BREAKER_SECONDS + 1) . ' seconds');
$shops->mode['trendhome.example'] = 'ok';
$reached = count($shops->calls);
$check($send($th, $read) === 'answered' && count($shops->calls) === $reached + 1, 'after the window the next read goes to the shop again');
$shops->mode['trendhome.example'] = 'down';
for ($n = 0; $n < MeasuredSourceAccessTransport::BREAKER_FAILURES; $n++) {
    $send($th, $read);
}
$reached = count($shops->calls);
$check($send($th, $read) === 'SOURCE_UNREACHABLE' && count($shops->calls) === $reached + 1, 'an answer in the window keeps the breaker closed (an occasional failure never stops a working shop)');
$shops->mode['trendhome.example'] = 'ok';

// ---- 3. Off and without a database: a plain pass-through ----------------------------------------------------------------
$pdo->exec('DELETE FROM source_access_timings');
$shops->mode['outletperdele.example'] = 'down';
$plain = new MeasuredSourceAccessTransport($shops, $pdo, $clock, false);
for ($n = 0; $n < MeasuredSourceAccessTransport::BREAKER_FAILURES + 2; $n++) {
    try { $plain->post($op, $read, [], '{}'); } catch (SourceAccessException) {}
}
$check((int) $one("SELECT COUNT(*) FROM source_access_timings WHERE outcome = 'source_unreachable'") === MeasuredSourceAccessTransport::BREAKER_FAILURES + 2
    && (int) $one("SELECT COUNT(*) FROM source_access_timings WHERE outcome = 'breaker_open'") === 0, 'with the breaker off every call is sent and measured');
$noDb = new MeasuredSourceAccessTransport($shops, null, $clock);
$shops->mode['outletperdele.example'] = 'ok';
$check($noDb->post($op, $read, [], '{}')['status'] === 200, 'without a database the call passes through');
$pdo->exec('RENAME TABLE source_access_timings TO source_access_timings_away');
try {
    $check($measured->post($op, $read, [], '{}')['status'] === 200, 'a failed measurement (no table) never fails the call');
} finally {
    $pdo->exec('RENAME TABLE source_access_timings_away TO source_access_timings');
}

// ---- 4. The container measures every shop call in production; tests with their own transport get no breaker ---------------
$container = new Container($config, $pdo, $shops);
$transport = (new ReflectionMethod($container, 'shopTransport'))->invoke($container);
$check($transport instanceof MeasuredSourceAccessTransport && (new ReflectionProperty($transport, 'breaker'))->getValue($transport) === false, 'a test transport is measured, without the breaker');
$production = new Container($config, $pdo);
$live = (new ReflectionMethod($production, 'shopTransport'))->invoke($production);
$check($live instanceof MeasuredSourceAccessTransport && (new ReflectionProperty($live, 'breaker'))->getValue($live) === true, 'the production transport is measured, with the breaker');

// ---- 5. Paid routing: one run at a time ---------------------------------------------------------------------------------
$holder = Connection::create($config);
$check((int) $holder->query("SELECT GET_LOCK('arasya_confirmare_paid_routing', 0)")->fetchColumn() === 1, 'another run holds the paid-routing lock');
$skipped = $production->confirmareInvoice()->routePaid(50);
$check($skipped['seen'] === 0 && ($skipped['skipped']['another_run'] ?? 0) === 1, 'a second paid-routing run does nothing while one is active: ' . json_encode($skipped));
$holder->query("SELECT RELEASE_LOCK('arasya_confirmare_paid_routing')");
$ran = $production->confirmareInvoice()->routePaid(50);
$check(!isset($ran['skipped']['another_run']), 'after it ends the next run routes again: ' . json_encode($ran));

$pdo->exec('DELETE FROM source_access_timings');
fwrite(STDOUT, "PASS MySQL source access timings integration: {$checks} checks (measured calls without a body, per-host read breaker, actions always sent, recovery, pass-through without a database, a failed measurement never fails a call, one paid-routing run at a time).\n");
