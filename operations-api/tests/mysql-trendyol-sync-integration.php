<?php

declare(strict_types=1);

// Trendyol Seller API synchronization contract on a disposable database, with a recording fixture transport and an
// injected clock: read-only GET calls, persisted cursor with overlap, pagination, duplicates, out-of-order updates,
// cancellations, independent production state, advisory locking and failure without cursor movement.
use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\TrendyolCredentials;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Integration\Trendyol\TrendyolClient;
use Arasya\Operations\Integration\Trendyol\TrendyolSynchronizer;
use Arasya\Operations\Integration\Trendyol\TrendyolTransport;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$db = T::requireTestDatabase();
if ($db === null) { echo "SKIP Trendyol sync integration: test database not configured\n"; exit; }
$config = T::config($db);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $file) (new SqlFileRunner($pdo))->run($file);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };

$writer = (new Container($config, $pdo))->projectionWriter();
$clock = new class implements Clock { public DateTimeImmutable $instant; public function now(): DateTimeImmutable { return $this->instant; } };
$transport = new class implements TrendyolTransport {
    /** @var list<array{url: string, headers: array<string, string>}> */
    public array $calls = [];
    /** @var list<array{status: int, body: string}> */
    public array $responses = [];
    public function get(string $url, array $headers): array { $this->calls[] = ['url' => $url, 'headers' => $headers]; return array_shift($this->responses) ?? ['status' => 500, 'body' => '']; }
};
$credentials = TrendyolCredentials::fromValues('123456', 'fixture-key', 'fixture-secret', '');
$sync = new TrendyolSynchronizer($pdo, new TrendyolClient($credentials, $transport), $writer, $clock);
$base = random_int(100_000_000, 900_000_000) * 10;
$package = static function (int $offset, int $modifiedMillis, string $status = 'Picking') use ($base): array {
    return ['id' => $base + $offset, 'orderNumber' => (string) ($base + $offset + 7), 'orderDate' => $modifiedMillis - 60_000, 'lastModifiedDate' => $modifiedMillis,
        'status' => $status, 'shipmentPackageStatus' => $status, 'lines' => [['id' => $base + $offset + 1, 'quantity' => 2, 'productName' => 'Perdea test', 'merchantSku' => 'PT-1', 'price' => 249.9]]];
};
$page = static fn (array $content, int $totalPages): array => ['status' => 200, 'body' => json_encode(['content' => $content, 'totalPages' => $totalPages], JSON_THROW_ON_ERROR)];
$query = static function (string $url): array { parse_str((string) parse_url($url, PHP_URL_QUERY), $q); return $q; };
$cursor = static fn (): ?string => ($v = $pdo->query("SELECT sync_cursor_at FROM order_sources WHERE source_key = 'trendyol'")->fetchColumn()) === false ? null : $v;
$order = static function (int $offset) use ($pdo, $base): array|false {
    $s = $pdo->prepare('SELECT production_stage_id, operational_status, source_commerce_status_code, production_version FROM operational_orders WHERE global_order_id = ?');
    $s->execute(['trendyol:' . ($base + $offset)]);
    return $s->fetch(PDO::FETCH_ASSOC);
};
$pdo->exec("UPDATE order_sources SET sync_cursor_at = NULL WHERE source_key = 'trendyol'");
$t0 = new DateTimeImmutable('2026-10-05 08:00:00', new DateTimeZone('UTC'));
$ms = static fn (DateTimeImmutable $at): int => $at->getTimestamp() * 1000;

// First run: no cursor, 14-day lookback, two pages, one cancelled package.
$clock->instant = $t0;
$transport->responses = [$page([$package(10, $ms($t0) - 5000), $package(20, $ms($t0) - 4000, 'Cancelled')], 2), $page([$package(30, $ms($t0) - 3000)], 2)];
$counts = $sync->run();
$check($counts === ['applied' => 3, 'duplicate' => 0, 'out_of_order' => 0, 'rejected' => 0], 'first run applies all packages of both pages: ' . json_encode($counts));
$check(count($transport->calls) === 2, 'both pages were requested');
$first = $query($transport->calls[0]['url']);
$check(str_starts_with($transport->calls[0]['url'], 'https://apigw.trendyol.com/integration/order/sellers/123456/orders?'), 'the Seller API shipment-package endpoint is used');
$check((int) $first['startDate'] === $ms($t0->modify('-14 days')) && (int) $first['endDate'] === $ms($t0) && $first['page'] === '0' && $first['size'] === '200' && $first['orderByField'] === 'PackageLastModifiedDate' && $first['orderByDirection'] === 'ASC', 'first window is the 14-day lookback ordered by modification');
$check($query($transport->calls[1]['url'])['page'] === '1', 'the second page is requested by number');
$check($transport->calls[0]['headers']['Authorization'] === 'Basic ' . base64_encode('fixture-key:fixture-secret') && $transport->calls[0]['headers']['User-Agent'] === '123456 - SelfIntegration', 'seller authentication headers');
$check($cursor() === $t0->format('Y-m-d H:i:s.u'), 'the cursor is persisted at the run time');
$check(($o = $order(10)) !== false && $o['production_stage_id'] === 'waiting' && $o['operational_status'] === 'in_progress' && $o['source_commerce_status_code'] === 'Picking', 'a new package enters production at waiting with its commerce status');
$check(($o = $order(20)) !== false && $o['operational_status'] === 'unavailable', 'a cancelled package is unavailable');

// Second run: overlap of ten minutes; the same versions are duplicates, a newer version applies, an older one is out of order.
$t1 = $t0->modify('+5 minutes'); $clock->instant = $t1; $transport->calls = [];
$transport->responses = [$page([$package(10, $ms($t0) - 5000), $package(30, $ms($t0) - 2000, 'Shipped'), $package(20, $ms($t0) - 9000, 'Picking')], 1)];
$counts = $sync->run();
$check($counts === ['applied' => 1, 'duplicate' => 1, 'out_of_order' => 1, 'rejected' => 0], 'overlap run: duplicate, update and out-of-order: ' . json_encode($counts));
$check((int) $query($transport->calls[0]['url'])['startDate'] === $ms($t0->modify('-600 seconds')) && (int) $query($transport->calls[0]['url'])['endDate'] === $ms($t1), 'the next window starts ten minutes before the cursor');
$check(($o = $order(30)) !== false && $o['source_commerce_status_code'] === 'Shipped' && $o['production_stage_id'] === 'waiting', 'a commerce update never moves production');
$check(($o = $order(20)) !== false && $o['operational_status'] === 'unavailable', 'an older out-of-order package does not reopen a cancelled one');
$check((int) $pdo->query("SELECT COUNT(*) FROM operational_orders WHERE global_order_id IN ('trendyol:" . ($base + 10) . "','trendyol:" . ($base + 20) . "','trendyol:" . ($base + 30) . "')")->fetchColumn() === 3, 'repeated synchronization creates no duplicate order');

// A malformed package is rejected without stopping the run.
$t2 = $t1->modify('+5 minutes'); $clock->instant = $t2;
$transport->responses = [$page([['id' => 'bad'], $package(40, $ms($t1))], 1)];
$check($sync->run() === ['applied' => 1, 'duplicate' => 0, 'out_of_order' => 0, 'rejected' => 1], 'a malformed package is counted as rejected and the others apply');

// A failure on a later page leaves the cursor where it was, so the next run repeats the window.
$t3 = $t2->modify('+5 minutes'); $clock->instant = $t3;
$transport->responses = [$page([$package(50, $ms($t2))], 2), ['status' => 503, 'body' => '']];
try { $sync->run(); $check(false, 'an unavailable Seller API must fail the run'); } catch (RuntimeException $e) { $check($e->getMessage() === 'TRENDYOL_UNAVAILABLE', 'the failure is reported as TRENDYOL_UNAVAILABLE'); }
$check($cursor() === $t2->format('Y-m-d H:i:s.u'), 'a failed run does not move the cursor');
$transport->responses = [['status' => 401, 'body' => '']];
try { $sync->run(); $check(false, 'rejected credentials must fail'); } catch (RuntimeException $e) { $check($e->getMessage() === 'TRENDYOL_AUTH_FAILED' && !str_contains($e->getMessage(), 'fixture-secret'), 'rejected credentials fail without exposing the secret'); }
$check($cursor() === $t2->format('Y-m-d H:i:s.u'), 'an authentication failure does not move the cursor');

// Only one synchronization runs at a time.
$other = Connection::create($config);
$other->query("SELECT GET_LOCK('arasya_trendyol_sync', 0)")->fetchColumn();
$transport->calls = [];
try { $sync->run(); $check(false, 'a concurrent run must be refused'); } catch (RuntimeException) { $check($transport->calls === [], 'a concurrent run is refused before calling Trendyol'); }
$other->query("SELECT RELEASE_LOCK('arasya_trendyol_sync')");

// More pages than one run may read: the cursor stops at the last package read, so the next run continues from there.
$t4 = $t3->modify('+5 minutes'); $clock->instant = $t4; $transport->calls = [];
$transport->responses = [];
for ($p = 0; $p < 50; $p++) $transport->responses[] = $page([$package(1000 + $p * 10, $ms($t2) + $p * 1000)], 60);
$sync->run();
$check(count($transport->calls) === 50, 'one run reads at most 50 pages');
$lastRead = (new DateTimeImmutable('@' . intdiv($ms($t2) + 49 * 1000, 1000)))->setTimezone(new DateTimeZone('UTC'));
$check($cursor() === $lastRead->format('Y-m-d H:i:s.u'), 'a truncated run keeps the cursor at the last package read instead of skipping unread pages: ' . $cursor());
$transport->calls = []; $transport->responses = [$page([], 1)];
$sync->run();
$check((int) $query($transport->calls[0]['url'])['startDate'] === $ms($lastRead->modify('-600 seconds')), 'the next run resumes from the truncated position');
$check($cursor() === $t4->format('Y-m-d H:i:s.u'), 'a complete run moves the cursor to the run time');

echo "PASS {$checks} Trendyol synchronization checks\n";
