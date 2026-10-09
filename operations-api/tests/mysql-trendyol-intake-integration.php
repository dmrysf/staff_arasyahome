<?php

declare(strict_types=1);

// Trendyol intake contract on a disposable database, with a recording fixture transport and an injected clock:
// activation gates, historical protection, the read-only Order V2 calls, the intake inbox (never production),
// the Staff workspace with source-scoped permissions, the explicit approval into stage 1 with the Arasya QR and
// document revision 1, the existing claim/transition to stage 2, marketplace changes after approval, and
// isolation from every other source.
use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\TrendyolCredentials;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\Trendyol\TrendyolClient;
use Arasya\Operations\Integration\Trendyol\TrendyolIntakeState;
use Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore;
use Arasya\Operations\Integration\Trendyol\TrendyolIntakeSynchronizer;
use Arasya\Operations\Integration\Trendyol\TrendyolTransport;
use Arasya\Operations\Order\OrderProjectionWriter;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$db = T::requireTestDatabase();
if ($db === null) { echo "SKIP Trendyol intake integration: test database not configured\n"; exit; }
$config = T::config($db);
$pdo = Connection::create($config);
$freshlyApplied = in_array('022_trendyol_intake.sql', (new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations'), true);
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $file) (new SqlFileRunner($pdo))->run($file);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$status = static function (array $response, int $expected, string $label) use ($check): array { $check($response['status'] === $expected, $label . ' ' . json_encode($response['body'])); return $response['body'] ?? []; };
$error = static function (array $response, int $expected, string $code, string $label) use ($check): void { $check($response['status'] === $expected && ($response['body']['error']['code'] ?? null) === $code, "{$label}: expected {$expected} {$code}, got {$response['status']} " . json_encode($response['body'])); };
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$row = static function (string $sql, array $params = []) use ($pdo): array|false { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetch(PDO::FETCH_ASSOC); };

// ---- Clean disposable intake state ---------------------------------------------------------------------------
foreach (['trendyol_intake_events', 'trendyol_package_lines', 'trendyol_ignored_packages', 'trendyol_sync_runs'] as $table) $pdo->exec("DELETE FROM {$table}");
$pdo->exec("DELETE FROM trendyol_packages");
$pdo->exec("DELETE FROM production_exception_idempotency WHERE operation LIKE 'trendyol.%'");
$pdo->exec("UPDATE trendyol_intake_state SET status = 'inactive', baseline_at = NULL, cursor_at = NULL, activated_at = NULL, activated_by = NULL, last_run_at = NULL, last_run_outcome = NULL");
$pdo->exec("UPDATE order_sources SET status = 'active', last_contact_at = NULL WHERE source_key = 'trendyol'");

// ---- Migration 022: additive, templates assigned to nobody ---------------------------------------------------
$templates = static function (string $role) use ($pdo): array {
    $s = $pdo->prepare('SELECT p.permission_key FROM role_permissions rp JOIN roles r ON r.role_id = rp.role_id JOIN permissions p ON p.permission_id = rp.permission_id WHERE r.role_key = ? ORDER BY p.permission_key');
    $s->execute([$role]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
};
$check($templates('trendyol-order-preparer') === ['trendyol.orders.prepare', 'trendyol.orders.view'], 'preparer template: view and prepare');
$check($templates('trendyol-order-approver') === ['trendyol.orders.prepare', 'trendyol.orders.release', 'trendyol.orders.view'], 'approver template: view, prepare and release');
$check(!$freshlyApplied || (int) $one("SELECT COUNT(*) FROM employee_role_assignments era JOIN roles r ON r.role_id = era.role_id WHERE r.role_key LIKE 'trendyol-%'") === 0, '022 assigns the Trendyol templates to nobody');
// A rerun on the same disposable database starts without the previous run's test identities' Trendyol roles.
$pdo->exec("DELETE era FROM employee_role_assignments era JOIN roles r ON r.role_id = era.role_id WHERE r.role_key LIKE 'trendyol-%'");
$check((int) $one("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id JOIN roles r ON r.role_id = rp.role_id WHERE p.permission_key LIKE 'trendyol.%' AND r.role_key NOT LIKE 'trendyol-%'") === 0, 'no existing role receives a Trendyol permission');
$check($one('SELECT status FROM trendyol_intake_state WHERE state_id = 1') === 'inactive', 'intake starts inactive');

// ---- Fixtures -----------------------------------------------------------------------------------------------
$clock = new class implements Clock { public DateTimeImmutable $instant; public function now(): DateTimeImmutable { return $this->instant; } };
$transport = new class implements TrendyolTransport {
    /** @var list<array{url: string, headers: array<string, string>}> */
    public array $calls = [];
    /** @var list<array{status: int, body: string}> */
    public array $responses = [];
    public function get(string $url, array $headers): array { $this->calls[] = ['url' => $url, 'headers' => $headers]; return array_shift($this->responses) ?? ['status' => 200, 'body' => '{"content":[],"totalPages":0,"totalElements":0}']; }
};
$credentials = TrendyolCredentials::fromValues('123456', 'fixture-key', 'fixture-secret', '');
$container = new Container($config, $pdo);
$writer = new OrderProjectionWriter($pdo, $clock, $container->authorityModes());
$sync = new TrendyolIntakeSynchronizer($pdo, new TrendyolClient($credentials, $transport), new TrendyolIntakeStore($pdo, $writer, $clock), $writer, $clock);
$state = new TrendyolIntakeState($pdo, $clock);
$ms = static fn (DateTimeImmutable $at): int => intdiv((int) $at->format('Uu'), 1000);
$base = random_int(100_000_000, 900_000_000) * 10;
$t0 = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime((int) date('G'), 0);
$hours = static fn (int $h): int => $h * 3_600_000;
$package = static function (int $offset, int $orderDate, int $modified, string $status = 'Created', ?array $lines = null) use ($base): array {
    return ['shipmentPackageId' => $base + $offset, 'orderNumber' => (string) ($base + $offset + 7), 'orderDate' => $orderDate, 'lastModifiedDate' => $modified,
        'status' => $status, 'shipmentPackageStatus' => $status, 'channelId' => 1,
        'shipmentAddress' => ['fullName' => 'TEST Client Trendyol', 'address1' => 'Str. Test 9', 'city' => 'Iași', 'phone' => '0744111222', 'email' => 'never@example.invalid'],
        'customerEmail' => 'never@example.invalid', 'identityNumber' => '19999999999', 'packageTotalPrice' => 777.5, 'cargoTrackingNumber' => 7330009998887776,
        'lines' => $lines ?? [['lineId' => $base + $offset + 1, 'quantity' => 1, 'productName' => 'Perdea tul alb 300x260', 'stockCode' => 'PT-300', 'barcode' => '868000' . $offset, 'productSize' => '300x260', 'productColor' => 'Alb', 'lineUnitPrice' => 388.75]]];
};
$page = static fn (array $content, int $totalPages = 1): array => ['status' => 200, 'body' => json_encode(['content' => $content, 'totalPages' => $totalPages, 'totalElements' => count($content), 'page' => 0, 'size' => 200], JSON_THROW_ON_ERROR)];
$query = static function (string $url): array { parse_str((string) parse_url($url, PHP_URL_QUERY), $q); return $q; };
$trendyolOrders = static fn (): int => (int) $one("SELECT COUNT(*) FROM operational_orders WHERE source_key = 'trendyol'");
$otherSources = static function () use ($pdo): string {
    $rows = $pdo->query("SELECT global_order_id, production_stage_id, production_version, version, operational_status, document_status FROM operational_orders WHERE source_key <> 'trendyol' ORDER BY global_order_id")->fetchAll(PDO::FETCH_ASSOC);
    $qr = $pdo->query("SELECT q.qr_reference, q.status FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.source_key <> 'trendyol' ORDER BY q.qr_reference")->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256', serialize([$rows, $qr, $pdo->query('SELECT * FROM employee_document_scopes ORDER BY employee_uuid, capability, source_key')->fetchAll(PDO::FETCH_ASSOC)]));
};
$othersBefore = $otherSources();
$trendyolOrdersBefore = $trendyolOrders();

// ---- Gates: nothing is read before an explicit activation ----------------------------------------------------
$check($container->trendyolIntakeSynchronizer() === null, 'without credentials and switch there is no synchronizer');
$credentialsOnly = new Container(new \Arasya\Operations\Config\Config(...[...(array) $config, 'trendyol' => $credentials]), $pdo);
$check($credentialsOnly->trendyolIntakeSynchronizer() === null, 'credentials alone never enable intake');
$switched = new Container(new \Arasya\Operations\Config\Config(...[...(array) $config, 'trendyol' => $credentials, 'trendyolIntake' => 'enabled']), $pdo);
$check($switched->trendyolIntakeSynchronizer() !== null, 'credentials plus the switch build the synchronizer');
$check((new Container(new \Arasya\Operations\Config\Config(...[...(array) $config, 'trendyol' => $credentials, 'trendyolIntake' => 'Enabled']), $pdo))->trendyolIntakeSynchronizer() === null, 'only the exact value enabled turns the switch on');
$clock->instant = $t0;
try { $sync->run(); $check(false, 'an inactive intake must refuse'); } catch (RuntimeException $e) { $check($e->getMessage() === 'TRENDYOL_INTAKE_NOT_ACTIVE' && $transport->calls === [], 'inactive intake refuses before calling Trendyol'); }

// ---- Activation: forward-only baseline ---------------------------------------------------------------------
$refused = static function (callable $call, string $code, string $label) use ($check): void { try { $call(); $check(false, $label); } catch (RuntimeException $e) { $check($e->getMessage() === $code, "{$label}: {$e->getMessage()}"); } };
$refused(fn () => $state->activate($t0->modify('-1 hour'), 'Test operator'), 'TRENDYOL_BASELINE_IN_PAST', 'a baseline an hour ago would import history');
$refused(fn () => $state->activate($t0, ''), 'TRENDYOL_OPERATOR_REQUIRED', 'activation names its operator');
$activated = $state->activate($t0, 'Test operator');
$check($activated['status'] === 'active' && $activated['baselineAt'] === $t0->format('Y-m-d H:i:s.u') && $activated['cursorAt'] === $activated['baselineAt'], 'activation records the baseline and starts the cursor there');
$refused(fn () => $state->activate($t0->modify('+1 minute'), 'Test operator'), 'TRENDYOL_INTAKE_ALREADY_ACTIVE', 'a second activation is refused');
$check($state->pause('Test operator')['status'] === 'paused', 'pause');
try { $sync->run(); $check(false, 'a paused intake must refuse'); } catch (RuntimeException $e) { $check($e->getMessage() === 'TRENDYOL_INTAKE_NOT_ACTIVE' && $transport->calls === [], 'paused intake refuses before calling Trendyol'); }
$refused(fn () => $state->activate($t0->modify('-1 minute'), 'Test operator'), 'TRENDYOL_BASELINE_BACKWARDS', 'the baseline never moves backwards');
$check($state->resume('Test operator')['status'] === 'active', 'resume');
$check((int) $one("SELECT COUNT(*) FROM trendyol_intake_events WHERE package_id IS NULL AND action IN ('intake_activated','intake_paused','intake_resumed')") === 3, 'every activation change is recorded');

// ---- First run: historical, ineligible, deferred and malformed packages never become work -------------------
$baseMs = $ms($t0);
$t1 = $t0->modify('+5 minutes'); $clock->instant = $t1;
$transport->responses = [$page([
    $package(10, $baseMs - $hours(48), $baseMs + 1000, 'Created'),          // ordered two days before activation
    $package(20, $baseMs - $hours(30), $baseMs + 2000, 'Delivered'),        // historical and delivered
    $package(30, $baseMs + $hours(4), $baseMs + 3000, 'Created'),           // new order: intake work
    $package(40, $baseMs + $hours(4), $baseMs + 4000, 'Shipped'),           // new but already shipped
    $package(50, $baseMs + $hours(4), $baseMs + 5000, 'Awaiting'),          // payment pending
    $package(60, $baseMs + $hours(1), $baseMs + 6000, 'Created'),           // inside the GMT+3 ambiguity: historical
    ['shipmentPackageId' => 'malformed'],
])];
$counts = $sync->run();
$check($counts['received'] === 1 && $counts['ignored'] === 4 && $counts['deferred'] === 1 && $counts['rejected'] === 1 && $counts['pages'] === 1, 'first run classification: ' . json_encode($counts));
$first = $query($transport->calls[0]['url']);
$check(str_starts_with($transport->calls[0]['url'], 'https://apigw.trendyol.com/integration/order/sellers/123456/v2/orders?'), 'the read-only Order V2 endpoint is used');
$check((int) $first['startDate'] === $baseMs - 600_000 && (int) $first['endDate'] === $ms($t1), 'the first window starts at the baseline minus the overlap, never 14 days back');
$check($transport->calls[0]['headers']['User-Agent'] === '123456 - SelfIntegration' && $transport->calls[0]['headers']['Authorization'] === 'Basic ' . base64_encode('fixture-key:fixture-secret'), 'seller authentication headers');
$check($trendyolOrders() === $trendyolOrdersBefore, 'synchronization creates no production order');
$check((int) $one("SELECT COUNT(*) FROM operational_orders WHERE global_order_id LIKE ?", ["trendyol:{$base}%"]) === 0, 'no production order, QR or document for any synchronized package');
$check($one('SELECT intake_status FROM trendyol_packages WHERE package_id = ?', [$base + 30]) === 'pending', 'the new Created package is pending intake work');
$ignored = $pdo->query('SELECT package_id, reason FROM trendyol_ignored_packages ORDER BY package_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$check($ignored === [$base + 10 => 'historical', $base + 20 => 'historical', $base + 40 => 'status_not_eligible', $base + 60 => 'historical'], 'historical and shipped packages are recorded as ignored: ' . json_encode($ignored));
$check($one('SELECT COUNT(*) FROM trendyol_packages WHERE package_id = ?', [$base + 50]) == 0, 'a payment-pending package is not stored yet');
$stored = (string) $one('SELECT delivery_context FROM trendyol_packages WHERE package_id = ?', [$base + 30]) . json_encode($pdo->query('SELECT * FROM trendyol_package_lines')->fetchAll(PDO::FETCH_ASSOC));
foreach (['0744111222', 'never@example', '19999999999', '777.5', '388.75', '7330009998887776'] as $private) $check(!str_contains($stored, $private), "intake never stores {$private}");
$check($one('SELECT cursor_at FROM trendyol_intake_state WHERE state_id = 1') === $t1->format('Y-m-d H:i:s.u'), 'the cursor is the window end');
$check($one("SELECT last_contact_at FROM order_sources WHERE source_key = 'trendyol'") !== null, 'a successful run records the Trendyol heartbeat');
$check($row('SELECT outcome, received, ignored FROM trendyol_sync_runs ORDER BY run_id DESC LIMIT 1') === ['outcome' => 'ok', 'received' => 1, 'ignored' => 4], 'the run is logged');

// ---- Second run: overlap, sticky ignore, deferred becomes work, line changes, withdrawal --------------------
$t2 = $t1->modify('+5 minutes'); $clock->instant = $t2; $transport->calls = [];
$changedLines = [
    ['lineId' => $base + 31, 'quantity' => 1, 'productName' => 'Perdea tul alb 300x260', 'stockCode' => 'PT-300', 'barcode' => '86800030', 'productSize' => '300x260', 'productColor' => 'Alb'],
    ['lineId' => $base + 32, 'quantity' => 2, 'productName' => 'Draperie blackout gri', 'stockCode' => 'DB-1', 'productSize' => '140 x 245 cm', 'productColor' => 'Gri'],
];
$transport->responses = [$page([
    $package(10, $baseMs - $hours(48), $baseMs + 7000, 'Picking'),                 // historical, now Picking: stays ignored
    $package(50, $baseMs + $hours(4), $baseMs + 8000, 'Created'),                  // payment confirmed: becomes work
    $package(70, $baseMs + $hours(5), $baseMs + 9000, 'Created'),                  // another new order
    $package(80, $baseMs + $hours(5), $baseMs + 9500, 'Created'),                  // will be withdrawn
])];
$check($sync->run()['received'] === 3, 'second run receives the confirmed and the new packages');
$check((int) $query($transport->calls[0]['url'])['startDate'] === $ms($t1) - 600_000, 'the next window overlaps ten minutes');
$check($one('SELECT reason FROM trendyol_ignored_packages WHERE package_id = ?', [$base + 10]) === 'historical' && $one('SELECT COUNT(*) FROM trendyol_packages WHERE package_id = ?', [$base + 10]) == 0, 'an ignored historical package is never reclassified');
$check($one('SELECT intake_status FROM trendyol_packages WHERE package_id = ?', [$base + 50]) === 'pending', 'a confirmed payment turns the deferred package into work');

// ---- Staff workspace identities ---------------------------------------------------------------------------
$kernel = $container->kernel();
$suffix = bin2hex(random_bytes(4));
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "trendyol.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-trendyol-root', $rootName);
$firstRoot = T::login($kernel, $rootName, $temporary);
$status(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Trendyol root passphrase 2026!'], ['x-csrf-token' => $firstRoot['csrf']], $firstRoot['cookie']), 200, 'root password');
$root = T::login($kernel, $rootName, 'Trendyol root passphrase 2026!');
$roleIds = [];
foreach ($status(T::call($kernel, 'GET', '/management/roles', null, [], $root['cookie']), 200, 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
$admin = $container->employeeAdmin();
$password = 'trendyol passphrase 2026';
$identity = static function (string $name, array $stages, array $applications, array $roles) use ($admin, $kernel, $root, $password, $suffix, $status): array {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test');
    $status(T::call($kernel, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications], ['x-csrf-token' => $root['csrf']], $root['cookie']), 200, "{$name} applications");
    $status(T::call($kernel, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles], ['x-csrf-token' => $root['csrf']], $root['cookie']), 200, "{$name} roles");
    return T::login($kernel, "{$name}.{$suffix}", $password);
};
$preparer = $identity('typrep', [], ['staff'], [$roleIds['trendyol-order-preparer']]);
$approver = $identity('tyapprove', [], ['staff'], [$roleIds['trendyol-order-approver']]);
$trendyolWaiting = $identity('tywait', ['waiting'], ['staff'], [$roleIds['trendyol-order-preparer']]);
$otherWaiting = $identity('otherwait', ['waiting'], ['staff'], []);
$cutter = $identity('tycut', ['material-preparation'], ['staff'], []);
$dashboardOnly = $identity('tydash', [], ['dashboard'], [$roleIds['trendyol-order-approver']]);
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string { $keys++; return sprintf('ty-%s-%s-%04d', $label, $suffix, $keys); };
$get = static fn (array $who, string $path, array $q = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $q);
$send = static fn (array $who, string $method, string $path, array $body, ?string $idem, array $headers = []): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $who['csrf'], ...($idem === null ? [] : ['idempotency-key' => $idem]), ...$headers], $who['cookie']);

// Source-scoped access: only explicitly authorized Trendyol personnel reach the workspace.
$error($get($otherWaiting, '/trendyol/overview'), 403, 'UNAUTHORIZED_ACTION', 'a Staff employee without Trendyol permission');
$error($get($dashboardOnly, '/trendyol/overview'), 403, 'APPLICATION_ACCESS_DENIED', 'Trendyol permissions work only inside Staff');
$overview = $status($get($preparer, '/trendyol/overview'), 200, 'preparer overview');
$check($overview['intake']['status'] === 'active' && $overview['counts']['pending'] === 4 && $overview['counts']['ignored'] === 4 && $overview['capabilities'] === ['view' => true, 'prepare' => true, 'release' => false], 'overview: state, counts and capabilities ' . json_encode($overview));
$pending = $status($get($preparer, '/trendyol/packages', ['view' => 'pending']), 200, 'pending list')['items'];
$check(array_column($pending, 'packageId') === [(string) ($base + 30), (string) ($base + 50), (string) ($base + 70), (string) ($base + 80)], 'pending work oldest order first');
$check(count($status($get($preparer, '/trendyol/packages', ['view' => 'ignored']), 200, 'ignored list')['items']) === 4, 'ignored packages are visible with their reason');
$error($get($preparer, '/trendyol/packages', ['view' => 'everything']), 400, 'INVALID_VIEW', 'unknown view');
$p30 = "/trendyol/packages/" . ($base + 30);
$detail = $status($get($preparer, $p30), 200, 'detail');
$check($detail['lines'][0]['sizeSuggestion'] === ['width' => '300', 'height' => '260'] && $detail['lines'][0]['prepared'] === null && $detail['readiness'] === ['ready' => false, 'missingLines' => [1], 'marketplaceReleasable' => true], 'detail shows the suggestion, never applies it');
$check($detail['delivery']['phoneMasked'] !== null && !str_contains(json_encode($detail), '0744111222'), 'the workspace shows only the masked phone');
$error($get($preparer, '/trendyol/packages/999'), 404, 'PACKAGE_NOT_FOUND', 'unknown package');

// Preparation: confirmed production data only.
$line30 = "{$p30}/lines/" . ($base + 31);
$error($send($approver, 'POST', "{$p30}/release", ['expectedVersion' => $detail['version'], 'confirm' => true], $key('early')), 422, 'PACKAGE_NOT_PREPARED', 'release needs every line prepared');
$error($send($preparer, 'PUT', $line30, ['expectedVersion' => $detail['version'], 'kind' => 'curtain', 'widthCm' => '300'], $key('no-height')), 422, 'MEASUREMENTS_REQUIRED', 'a curtain needs width and height');
$error($send($preparer, 'PUT', $line30, ['expectedVersion' => $detail['version'], 'kind' => 'curtain', 'widthCm' => '-3', 'heightCm' => '260'], $key('negative')), 422, 'INVALID_MEASUREMENT', 'measurements are positive decimals');
$error($send($preparer, 'PUT', $line30, ['expectedVersion' => $detail['version'], 'kind' => 'curtain', 'widthCm' => '300', 'heightCm' => '260', 'stage' => 'delivery'], $key('extra')), 400, 'INVALID_REQUEST', 'strict body');
$error($send($preparer, 'PUT', $line30, ['expectedVersion' => $detail['version'], 'kind' => 'curtain', 'widthCm' => '300', 'heightCm' => '260'], $key('csrf'), ['x-csrf-token' => 'invalid']), 403, 'CSRF_INVALID', 'CSRF is required');
$error($send($preparer, 'PUT', $line30, ['expectedVersion' => $detail['version'], 'kind' => 'curtain', 'widthCm' => '300', 'heightCm' => '260'], null), 400, 'INVALID_IDEMPOTENCY_KEY', 'an idempotency key is required');
$error($send($otherWaiting, 'PUT', $line30, ['expectedVersion' => $detail['version'], 'kind' => 'curtain', 'widthCm' => '300', 'heightCm' => '260'], $key('outsider')), 403, 'UNAUTHORIZED_ACTION', 'an outsider cannot prepare');
$prepareKey = $key('prepare');
$prepared = $status($send($preparer, 'PUT', $line30, ['expectedVersion' => $detail['version'], 'kind' => 'curtain', 'widthCm' => '298.5', 'heightCm' => '255', 'meters' => '6.2', 'notes' => 'Rejansă 2x'], $prepareKey), 200, 'prepare line');
$check($prepared['version'] === $detail['version'] + 1 && $prepared['lines'][0]['prepared']['widthCm'] === '298.500' && $prepared['lines'][0]['prepared']['preparedBy'] === 'Test typrep' && $prepared['readiness']['ready'] === true, 'the prepared line is recorded with its author');
$check($status($send($preparer, 'PUT', $line30, ['expectedVersion' => $detail['version'], 'kind' => 'curtain', 'widthCm' => '298.5', 'heightCm' => '255', 'meters' => '6.2', 'notes' => 'Rejansă 2x'], $prepareKey), 200, 'replay')['version'] === $prepared['version'], 'the same key replays the same result');
$error($send($preparer, 'PUT', $line30, ['expectedVersion' => $detail['version'], 'kind' => 'other'], $prepareKey), 409, 'IDEMPOTENCY_CONFLICT', 'the same key with another body');
$error($send($preparer, 'PUT', $line30, ['expectedVersion' => $detail['version'], 'kind' => 'other'], $key('stale')), 409, 'PACKAGE_CHANGED', 'a stale version is refused');
$check($prepared['capabilities']['releaseNow'] === false, 'the preparer cannot release');
$error($send($preparer, 'POST', "{$p30}/release", ['expectedVersion' => $prepared['version'], 'confirm' => true], $key('prep-release')), 403, 'UNAUTHORIZED_ACTION', 'releasing needs the release permission');
$error($send($approver, 'POST', "{$p30}/release", ['expectedVersion' => $prepared['version'], 'confirm' => false], $key('unconfirmed')), 422, 'CONFIRMATION_REQUIRED', 'the approval is explicit');
$check($trendyolOrders() === $trendyolOrdersBefore, 'preparation creates no production order');

// Approval: stage 1 order + Arasya QR + document revision 1, in one transaction.
$releaseKey = $key('release');
$released = $status($send($approver, 'POST', "{$p30}/release", ['expectedVersion' => $prepared['version'], 'confirm' => true], $releaseKey), 201, 'release');
$global = 'trendyol:' . ($base + 30);
$check($released['intakeStatus'] === 'released' && $released['production']['globalOrderId'] === $global && $released['production']['stageId'] === 'waiting' && $released['production']['documentStatus'] === 'active' && $released['production']['releasedBy'] === 'Test tyapprove', 'the approval links the production order');
$order = $row('SELECT * FROM operational_orders WHERE global_order_id = ?', [$global]);
$check($order['production_context'] === null, 'no order-level context: existing Staff and Dashboard clients read the order unchanged');
$check($order['production_stage_id'] === 'waiting' && $order['production_authority'] === 'operations' && (int) $order['production_version'] === 1 && $order['operational_status'] === 'in_progress' && $order['source_commerce_status_code'] === 'Created', 'canonical order at stage 1, managed by Operations');
$items = $pdo->query("SELECT * FROM operational_order_items WHERE order_uuid = '{$order['order_uuid']}'")->fetchAll(PDO::FETCH_ASSOC);
$check(count($items) === 1 && $items[0]['width_value'] === '298.500' && $items[0]['height_value'] === '255.000' && $items[0]['measurement_unit'] === 'cm' && $items[0]['meters'] === '6.200' && $items[0]['product_code'] === 'PT-300' && $items[0]['variant'] === '300x260' && json_decode($items[0]['production_context'], true)['kind'] === 'curtain', 'items carry the confirmed measurements, never the Trendyol text');
$qr = $pdo->query("SELECT qr_reference FROM order_qr_references WHERE order_uuid = '{$order['order_uuid']}' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
$revision = $row('SELECT revision_number, qr_reference, snapshot_json, status FROM production_document_revisions WHERE order_uuid = ?', [$order['order_uuid']]);
$check(count($qr) === 1 && $revision['revision_number'] == 1 && $revision['qr_reference'] === $qr[0] && $revision['status'] === 'active', 'one Arasya production QR, bound to document revision 1');
$check(!str_contains($revision['qr_reference'], '86800030') && !str_contains($revision['qr_reference'], '7330009998887776'), 'the Arasya QR is never a Trendyol barcode or cargo number');
$snapshot = json_decode($revision['snapshot_json'], true);
$check($snapshot['order']['source'] === 'trendyol' && $snapshot['lines'][0]['width'] === '298.500' && $snapshot['lines'][0]['productionNotes'] === 'Rejansă 2x' && $snapshot['customer']['phoneMasked'] !== null && !str_contains($revision['snapshot_json'], '0744111222') && !str_contains($revision['snapshot_json'], '777.5'), 'the canonical ticket: measurements, masked phone, no price');
$check($one("SELECT action FROM order_activity_events WHERE global_order_id = ?", [$global]) === 'production_submitted', 'the submission is on the shared production timeline');
$replayed = $status($send($approver, 'POST', "{$p30}/release", ['expectedVersion' => $prepared['version'], 'confirm' => true], $releaseKey), 201, 'release replay');
$check($replayed === $released && (int) $one('SELECT COUNT(*) FROM operational_orders WHERE global_order_id = ?', [$global]) === 1 && (int) $one('SELECT COUNT(*) FROM production_document_revisions WHERE order_uuid = ?', [$order['order_uuid']]) === 1, 'a replayed approval creates nothing twice');
$error($send($approver, 'POST', "{$p30}/release", ['expectedVersion' => $released['version'], 'confirm' => true], $key('again')), 409, 'PACKAGE_ALREADY_RELEASED', 'a second approval is refused');
$error($send($preparer, 'PUT', $line30, ['expectedVersion' => $released['version'], 'kind' => 'other'], $key('after')), 409, 'PACKAGE_NOT_PENDING', 'a released package can no longer be edited');

// Stage 1 visibility is source-scoped; the existing claim/transition hands the order to stage 2 (Tăiere).
$path = '/orders/' . rawurlencode($global);
$error($get($otherWaiting, $path), 404, 'ORDER_NOT_FOUND', 'a waiting-stage employee without Trendyol permission does not see the Trendyol order');
$seen = $status($get($trendyolWaiting, $path), 200, 'Trendyol waiting-stage employee sees the order');
$claimed = $status($send($trendyolWaiting, 'POST', "{$path}/claim", ['expectedVersion' => $seen['productionVersion'] ?? $seen['order']['productionVersion'] ?? 1], $key('claim')), 200, 'claim at stage 1');
$version = (int) $one('SELECT production_version FROM operational_orders WHERE global_order_id = ?', [$global]);
$status($send($trendyolWaiting, 'POST', "{$path}/transition", ['expectedVersion' => $version], $key('transition')), 200, 'complete stage 1');
$check($one('SELECT production_stage_id FROM operational_orders WHERE global_order_id = ?', [$global]) === 'material-preparation', 'the order moved to stage 2 (material-preparation) through the existing transition');
$status($get($cutter, $path), 200, 'from stage 2 on it is ordinary cutting work');
$error($get($otherWaiting, $path), 404, 'ORDER_NOT_FOUND', 'still invisible to an unrelated waiting-stage employee');

// Marketplace changes after approval: commerce only; cancellation makes the order unavailable.
$t3 = $t2->modify('+5 minutes'); $clock->instant = $t3;
$transport->responses = [$page([
    $package(30, $baseMs + $hours(4), $baseMs + 20000, 'Shipped', $changedLines),
    $package(80, $baseMs + $hours(5), $baseMs + 20500, 'Cancelled'),
    $package(70, $baseMs + $hours(5), $baseMs + 21000, 'Created', [...$changedLines]),
])];
$sync->run();
$after = $row('SELECT production_stage_id, production_version, source_commerce_status_code, operational_status FROM operational_orders WHERE global_order_id = ?', [$global]);
$check($after['production_stage_id'] === 'material-preparation' && (int) $after['production_version'] === $version + 1 && $after['source_commerce_status_code'] === 'Shipped' && $after['operational_status'] === 'in_progress', 'a marketplace status never moves production: ' . json_encode($after));
$check((int) $one("SELECT COUNT(*) FROM operational_order_items WHERE order_uuid = ?", [$order['order_uuid']]) === 1 && $one('SELECT changed_after_release FROM trendyol_packages WHERE package_id = ?', [$base + 30]) == 1, 'a content change after approval is flagged, the approved items stay');
$check($one('SELECT document_status FROM operational_orders WHERE global_order_id = ?', [$global]) === 'active', 'the printed document stays valid');
$check($one('SELECT intake_status FROM trendyol_packages WHERE package_id = ?', [$base + 80]) === 'marketplace_cancelled', 'a pending package cancelled in Trendyol leaves the work list');
$check((int) $one('SELECT COUNT(*) FROM trendyol_package_lines WHERE package_id = ?', [$base + 70]) === 2, 'pending lines follow the marketplace');

// Withdrawn, shipped-before-approval and dismissed packages can never be released.
$p80 = $status($get($approver, '/trendyol/packages/' . ($base + 80)), 200, 'cancelled detail');
$error($send($approver, 'POST', '/trendyol/packages/' . ($base + 80) . '/release', ['expectedVersion' => $p80['version'], 'confirm' => true], $key('cancelled')), 409, 'PACKAGE_NOT_PENDING', 'a marketplace-cancelled package cannot be released');
$p50 = $status($get($preparer, '/trendyol/packages/' . ($base + 50)), 200, 'p50');
$p50 = $status($send($preparer, 'PUT', '/trendyol/packages/' . ($base + 50) . '/lines/' . ($base + 51), ['expectedVersion' => $p50['version'], 'kind' => 'other'], $key('p50-line')), 200, 'an other product needs no measurements');
$pdo->prepare("UPDATE trendyol_packages SET marketplace_status = 'Shipped' WHERE package_id = ?")->execute([$base + 50]);
$error($send($approver, 'POST', '/trendyol/packages/' . ($base + 50) . '/release', ['expectedVersion' => $p50['version'], 'confirm' => true], $key('shipped')), 409, 'MARKETPLACE_STATUS_NOT_RELEASABLE', 'a package shipped in the Seller Panel cannot enter production');
$error($send($preparer, 'POST', '/trendyol/packages/' . ($base + 50) . '/dismiss', ['expectedVersion' => $p50['version'], 'reason' => ''], $key('no-reason')), 422, 'REASON_REQUIRED', 'dismissal needs a reason');
$dismissed = $status($send($preparer, 'POST', '/trendyol/packages/' . ($base + 50) . '/dismiss', ['expectedVersion' => $p50['version'], 'reason' => 'Produs din stoc, fără confecție'], $key('dismiss')), 200, 'dismiss');
$check($dismissed['intakeStatus'] === 'dismissed' && $dismissed['dismissal']['reason'] === 'Produs din stoc, fără confecție' && $dismissed['capabilities']['reopenNow'] === true, 'the package left the work list with its reason');
$reopened = $status($send($preparer, 'POST', '/trendyol/packages/' . ($base + 50) . '/reopen', ['expectedVersion' => $dismissed['version']], $key('reopen')), 200, 'reopen');
$check($reopened['intakeStatus'] === 'pending' && $reopened['dismissal'] === null, 'a dismissed package can be reopened');

// The marketplace cancels an approved order: unavailable for production, exactly like other sources.
$t4 = $t3->modify('+5 minutes'); $clock->instant = $t4;
$transport->responses = [$page([$package(30, $baseMs + $hours(4), $baseMs + 30000, 'Cancelled', $changedLines)])];
$sync->run();
$check($one('SELECT source_reported_unavailable_at FROM operational_orders WHERE global_order_id = ?', [$global]) !== null && $one('SELECT production_stage_id FROM operational_orders WHERE global_order_id = ?', [$global]) === 'material-preparation', 'a cancellation after approval is reported to production without moving the stage');

// ---- Sliced windows, truncation and failures ----------------------------------------------------------------
$pdo->prepare('UPDATE trendyol_intake_state SET cursor_at = ? WHERE state_id = 1')->execute([$t4->modify('-20 days')->format('Y-m-d H:i:s.u')]);
$pdo->prepare('UPDATE trendyol_intake_state SET baseline_at = ? WHERE state_id = 1')->execute([$t4->modify('-21 days')->format('Y-m-d H:i:s.u')]);
$t5 = $t4->modify('+1 minute'); $clock->instant = $t5; $transport->calls = [];
$sync->run();
$check(count($transport->calls) === 2, 'a 20-day gap is read in two slices');
foreach ($transport->calls as $call) { $q = $query($call['url']); $check((int) $q['endDate'] - (int) $q['startDate'] <= 14 * 86_400_000, 'every slice respects the two-week limit'); }
$check((int) $query($transport->calls[1]['url'])['endDate'] === $ms($t5) && $one('SELECT cursor_at FROM trendyol_intake_state WHERE state_id = 1') === $t5->format('Y-m-d H:i:s.u'), 'the last slice ends now and the cursor follows');
$pdo->prepare('UPDATE trendyol_intake_state SET baseline_at = ? WHERE state_id = 1')->execute([$t0->format('Y-m-d H:i:s.u')]);

$t6 = $t5->modify('+5 minutes'); $clock->instant = $t6; $transport->calls = [];
$transport->responses = [];
for ($p = 0; $p < 50; $p++) $transport->responses[] = $page([$package(1000 + $p * 10, $baseMs - $hours(100), $ms($t5) + $p * 1000, 'Delivered')], 60);
$truncated = $sync->run();
$lastRead = (new DateTimeImmutable('@' . intdiv($ms($t5) + 49 * 1000, 1000)))->setTimezone(new DateTimeZone('UTC'));
$check(count($transport->calls) === 50 && $truncated['truncated'] === true && $one('SELECT cursor_at FROM trendyol_intake_state WHERE state_id = 1') === $lastRead->format('Y-m-d H:i:s.u'), 'more than 10,000 packages: the cursor stops at the last package read');
$check($one('SELECT last_run_outcome FROM trendyol_intake_state WHERE state_id = 1') === 'truncated', 'a truncated run is visible to the operator');

$cursorBefore = $one('SELECT cursor_at FROM trendyol_intake_state WHERE state_id = 1');
foreach ([[503, 'TRENDYOL_UNAVAILABLE'], [429, 'TRENDYOL_RATE_LIMITED'], [401, 'TRENDYOL_AUTH_FAILED'], [426, 'TRENDYOL_UPGRADE_REQUIRED']] as [$code, $expected]) {
    $transport->responses = [['status' => $code, 'body' => '']];
    try { $sync->run(); $check(false, "HTTP {$code} must fail"); } catch (RuntimeException $e) { $check($e->getMessage() === $expected && !str_contains($e->getMessage(), 'fixture-secret'), "HTTP {$code} fails as {$expected}"); }
    $check($one('SELECT cursor_at FROM trendyol_intake_state WHERE state_id = 1') === $cursorBefore && $one('SELECT last_run_outcome FROM trendyol_intake_state WHERE state_id = 1') === $expected, "HTTP {$code} leaves the cursor and records the outcome");
}
$other = Connection::create($config);
$other->query("SELECT GET_LOCK('arasya_trendyol_sync', 0)")->fetchColumn();
$transport->calls = [];
try { $sync->run(); $check(false, 'a concurrent run must be refused'); } catch (RuntimeException $e) { $check($e->getMessage() === 'TRENDYOL_SYNC_ALREADY_RUNNING' && $transport->calls === [], 'a concurrent run is refused before calling Trendyol'); }
$other->query("SELECT RELEASE_LOCK('arasya_trendyol_sync')");

// ---- No other path creates a Trendyol production order ------------------------------------------------------
try {
    $writer->apply(new SourceOrderSnapshot('trendyol', (string) ($base + 70), 'direct', 1, $t6, 'X', null, 'Created', 'Created', null, 'in_progress', null, []));
    $check(false, 'the shared writer must refuse Trendyol');
} catch (ApiException $e) { $check($e->errorCode === 'MARKETPLACE_APPROVAL_ONLY', 'the shared projection writer refuses Trendyol snapshots'); }
$error(T::ingest($kernel, 'trendyol', T::sourceOrder('1', 'e1', gmdate('Y-m-d\TH:i:s\Z'))), 503, 'SOURCE_NOT_CONFIGURED', 'Trendyol cannot be pushed through a signed source route');
$check((int) $one("SELECT COUNT(*) FROM operational_orders WHERE source_key = 'trendyol'") === $trendyolOrdersBefore + 1, 'exactly one Trendyol production order exists: the approved one');
$check($otherSources() === $othersBefore, 'Trendhome, OutletPerdele and B2B orders, QR codes and document scopes are untouched');
$check((int) $one("SELECT COUNT(*) FROM employee_role_assignments era JOIN roles r ON r.role_id = era.role_id JOIN employees e ON e.employee_uuid = era.employee_uuid WHERE r.role_key LIKE 'trendyol-%' AND e.username NOT LIKE ?", ["%.{$suffix}"]) === 0, 'only the disposable test identities hold Trendyol roles');

// Leave the shared disposable database as later suites expect it: the intake rows and the approved Trendyol test
// orders (with their QR, document revision and events) are removed, child rows first.
$pdo->exec('DELETE FROM trendyol_package_lines');
$pdo->exec('DELETE FROM trendyol_packages');
$created = $pdo->query("SELECT order_uuid FROM operational_orders WHERE source_key = 'trendyol'")->fetchAll(PDO::FETCH_COLUMN);
if ($created !== []) {
    $in = implode(', ', array_map([$pdo, 'quote'], $created));
    $pdo->exec("UPDATE operational_orders SET active_document_revision_uuid = NULL WHERE order_uuid IN ({$in})");
    foreach (['production_document_blocks', 'production_document_prints', 'production_document_events'] as $table) $pdo->exec("DELETE FROM {$table} WHERE order_uuid IN ({$in})");
    $pdo->exec("UPDATE production_document_revisions SET request_uuid = NULL WHERE order_uuid IN ({$in})");
    $pdo->exec("UPDATE production_document_revision_requests SET previous_request_uuid = NULL WHERE order_uuid IN ({$in})");
    foreach (['production_document_revision_requests', 'production_document_revisions', 'production_qr_events', 'order_qr_references', 'production_quality_events', 'production_exceptions', 'order_activity_events',
        'order_operation_idempotency', 'employee_order_relations', 'production_authority_events', 'cutting_transfers', 'cutting_facts', 'analytics_ownership_intervals', 'analytics_order_projection', 'operational_order_items'] as $table) {
        $pdo->exec("DELETE FROM {$table} WHERE order_uuid IN ({$in})");
    }
    $pdo->exec("DELETE FROM operational_orders WHERE order_uuid IN ({$in})");
}

echo "PASS {$checks} Trendyol intake checks\n";
