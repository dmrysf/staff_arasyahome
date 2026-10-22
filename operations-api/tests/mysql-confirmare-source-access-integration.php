<?php

declare(strict_types=1);

// Confirmare source access (migration 038, API 2.40.0, Phase 4A) on a disposable database, through the HTTP kernel, with
// an in-process TEST shop that speaks protocol v1 exactly like YD SOFT. Covers: who may read the shop order (assignee,
// supervisor; never an unassigned operator, another store's operator, a user without scope, nor a legacy order), the
// detail capabilities, the audit (one row per read, never a telephone number or an amount), no-store answers, the
// permission re-checked after the shop answered (revoked scope, reassignment), an unavailable or untrusted shop, stale
// prices, the rate limit, a failing audit (nothing shown), the shop's contact fact deciding the contact condition, the
// financial attention fact, and server logs without customer data or secrets.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Production\ConfirmationGate;
use Arasya\Operations\Production\WorkflowCohortActivation;
use Arasya\Operations\Tests\MutableClock;
use Arasya\Operations\Tests\OperationsTestSupport as T;
use Arasya\Operations\Tests\SourceAccessFakeShop;
use Arasya\Operations\Config\Config;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';
require __DIR__ . '/SourceAccessFakeShop.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL Confirmare source access integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
// Every server log line of this run goes to one file, scanned at the end for customer data and secrets.
$logFile = tempnam(sys_get_temp_dir(), 'arasya-source-access-log-');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

const SA_ORIGIN = 'http://127.0.0.1:4174';
const SA_KEY = 'trendhome-access-key-test-0123456789abcdef-ab';
const SA_PHONE = '0711222333';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on'];
$base = T::config($dbName, [T::ORIGIN, SA_ORIGIN], ['trendhome' => $full + ['paymentGate' => 'enforce'], 'outletperdele' => $full + ['paymentGate' => 'off']]);
// Source access for Trend Home only (read); Outlet Perdele stays off.
$config = new Config(...[...get_object_vars($base), 'sourceAccess' => ['trendhome' => ['mode' => 'read', 'url' => 'https://trendhome.example', 'key' => 'th-test:' . SA_KEY]]]);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$all = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };
foreach (['order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

$shop = new SourceAccessFakeShop('trendhome', 'th-test', SA_KEY);
$container = new Container($config, $pdo, $shop);
$kernel = $container->kernel();
$service = $container->confirmareService();
$registry = SourceRegistry::fromConfig($config);
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "sa.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-sa-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Source access root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Source access root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'sa-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$password = 'source access passphrase 2026';
$uuids = [];
foreach (['saopa', 'saopb', 'saopo', 'sasup', 'sanone'] as $name) {
    // Generic test identities: access only from the scopes granted below, never from a name.
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test-sa');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['staff']]), "{$name} applications");
}
$scopes = ['saopa' => ['operate' => ['trendhome'], 'supervise' => []], 'saopb' => ['operate' => ['trendhome'], 'supervise' => []], 'saopo' => ['operate' => ['outletperdele'], 'supervise' => []], 'sasup' => ['operate' => [], 'supervise' => ['trendhome']]];
foreach ($scopes as $name => $grant) $ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/confirmare-scopes", $grant), "grant {$name}");
$login = static fn (string $name): array => T::login($kernel, "{$name}.{$suffix}", $password);
$opA = $login('saopa');
$opB = $login('saopb');
$opO = $login('saopo');
$sup = $login('sasup');
$none = $login('sanone');
$get = static fn (array $who, string $path): array => T::call($kernel, 'GET', $path, null, ['origin' => SA_ORIGIN], $who['cookie']);
$read = static fn (array $who, string $globalId): array => $get($who, '/confirmare/orders/' . rawurlencode($globalId) . '/source');
$detail = static fn (array $who, string $globalId): array => $ok($get($who, '/confirmare/orders/' . rawurlencode($globalId)), "detail {$globalId}");
$readRows = static fn (string $globalId): array => $all('SELECT * FROM order_confirmation_source_reads WHERE global_order_id = ? ORDER BY read_at, read_uuid', [$globalId]);

// ---- Fixtures: Confirmare orders of both sources, a legacy order ------------------------------------------------------
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    fwrite(STDOUT, "SKIP MySQL Confirmare source access integration: needs a clock after the Confirmare cutover.\n");
    exit(0);
}
$check(ConfirmationGate::WORKFLOW_READY === false, 'Phase 4 keeps WORKFLOW_READY false');
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
foreach (['trendhome', 'outletperdele'] as $src) {
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-20 minutes'))))->schedule($registry->find($src), $activatedAt, 'Test Owner', 'source access test', 'sa-test', true);
    T::ingest($kernel, $src, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-1 minute'))))->activate($registry->find($src), 'Test Owner', 'source access test', 'sa-test', true);
}
$heartbeat = T::ingest($kernel, 'trendhome', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')], null, null, 'heartbeat');
$check(in_array('contact', $heartbeat['body']['orderFacts'] ?? [], true), 'the heartbeat announces the contact fact');
$numberBase = T::freeOrderBase($pdo, 930000, 959999, 100); // ids base+1..base+60; a block no earlier run used
$id = static fn (int $n): string => (string) ($numberBase + $n);
$th = static fn (int $n): string => 'trendhome:' . $id($n);
$tick = 0;
$changedAt = [];
$payload = static function (string $orderId, ?array $submission, array $extra = []) use ($suffix, &$tick, &$changedAt): array {
    $tick++;
    $changedAt[$orderId] = gmdate('Y-m-d\TH:i:s', time() - 4000 + $tick) . 'Z';
    $p = T::sourceOrder($orderId, "sa-{$orderId}-{$suffix}-{$tick}", $changedAt[$orderId], null, 'processing', 'active', [[
        'id' => 9101, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'width' => 3, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1,
    ]]);
    unset($p['order']['notes']);
    $fingerprint = $extra['pricing'] ?? 'sha256:' . hash('sha256', "sa-price-{$orderId}");
    $p['order']['pricing'] = ['fingerprint' => $fingerprint];
    $p['order']['payment'] = ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0];
    $p['order']['financial'] = $extra['financial'] ?? ['state' => 'cleared', 'basis' => 'online_payment', 'pricingFingerprint' => $fingerprint];
    $p['order']['delivery'] = ['name' => 'TEST Client Sursa', 'street' => 'Str. Exemplu 11', 'city' => 'Cluj-Napoca', 'postalCode' => '400000', 'country' => 'RO', 'phone' => SA_PHONE];
    if (array_key_exists('contact', $extra)) $p['order']['contact'] = $extra['contact'];
    if ($submission !== null) $p['order']['submission'] = $submission;
    return $p;
};
$submitted = static fn (int $seconds): array => ['submittedAt' => $iso($activatedAt->modify("+{$seconds} seconds")), 'evidence' => 'checkout'];
foreach ([1, 2, 3, 4] as $n) $ok(T::ingest($kernel, 'trendhome', $payload($id($n), $submitted($n), ['contact' => ['phone' => true]])), "trendhome order {$n}");
$ok(T::ingest($kernel, 'outletperdele', $payload($id(50), $submitted(50))), 'outlet order');
$ok(T::ingest($kernel, 'trendhome', $payload($id(60), ['submittedAt' => $iso($activatedAt->modify('-1 hour')), 'evidence' => 'checkout'])), 'legacy order');
$check($service->distribute()['assigned'] === 5, 'the server distributes the Confirmare orders');
foreach ([1, 2, 3, 4, 60] as $n) {
    $shop->orders[$id($n)] = ['id' => $id($n), 'phone' => SA_PHONE, 'pricing' => 'sha256:' . hash('sha256', "sa-price-{$id($n)}"), 'modifiedAt' => $changedAt[$id($n)], 'eligible' => $n !== 60];
}
$assignee = static fn (string $globalId): ?string => ($v = $one('SELECT assigned_employee_uuid FROM order_confirmations WHERE global_order_id = ?', [$globalId])) === false ? null : $v;
// Deterministic: 1, 2 and 3 with operator A, 4 with operator B.
foreach ([1 => 'saopa', 2 => 'saopa', 3 => 'saopa', 4 => 'saopb'] as $n => $who) {
    if ($assignee($th($n)) !== $uuids[$who]) {
        $ok($asRoot('POST', '/confirmare/orders/' . rawurlencode($th($n)) . '/reassign', ['expectedVersion' => (int) $one('SELECT version FROM order_confirmations WHERE global_order_id = ?', [$th($n)]), 'employeeId' => $uuids[$who]]), "assign {$n}");
    }
}
$outletOrder = 'outletperdele:' . $id(50);
$check($assignee($outletOrder) === $uuids['saopo'], 'the outlet order is with the outlet operator');

// ---- 1. Capabilities with the detail ------------------------------------------------------------------------------
$check($detail($opA, $th(1))['sourceAccess'] === ['configured' => true, 'read' => true, 'edit' => false, 'changePending' => false, 'openEdits' => []], 'the assignee may read the shop order (read mode: no edit)');
$check($detail($opA, $th(4))['sourceAccess']['read'] === false, 'a colleague\'s order: no shop access');
$check($detail($sup, $th(4))['sourceAccess']['read'] === true, 'a supervisor of the source may read');
$check($detail($opO, $outletOrder)['sourceAccess'] === ['configured' => false, 'read' => false, 'edit' => false, 'changePending' => false, 'openEdits' => []], 'a source without access configured offers nothing');

// ---- 2. The assignee reads the genuine shop order -------------------------------------------------------------------
$requestsBefore = count($shop->requests);
$response = $read($opA, $th(1));
$body = $ok($response, 'assignee read');
$check($body['available'] === true && $body['role'] === 'assignee' && $body['detail']['contact']['billing']['phone'] === SA_PHONE && $body['detail']['contact']['phoneAvailable'] === true, 'the assignee sees the real telephone number from the shop');
$check($body['detail']['lines'][0]['unitPrice'] === '420.00' && $body['detail']['totals']['total'] === '719.00' && $body['detail']['coupons'][0]['code'] === 'test10' && $body['detail']['shipping'][0]['title'] === 'Livrare prin curier', 'lines, prices, coupons, shipping and totals as the shop holds them');
$check($body['freshness']['pricingMatches'] === true && $body['freshness']['inSync'] === true && $body['freshness']['readAt'] !== null, 'the shop and Arasya agree on prices and the last change');
$check(str_contains($response['headers']['Cache-Control'] ?? '', 'no-store') && ($response['headers']['Pragma'] ?? '') === 'no-cache', 'the answer is never cached (no-store)');
$check(count($shop->requests) === $requestsBefore + 1 && $shop->requests[$requestsBefore]['body']['actor'] === ['employeeId' => $uuids['saopa'], 'role' => 'assignee']
    && $shop->requests[$requestsBefore]['body']['orderNumber'] === $id(1), 'the shop learns which employee asked, in which role, for exactly this order');
$rows = $readRows($th(1));
$check(count($rows) === 1 && $rows[0]['employee_uuid'] === $uuids['saopa'] && $rows[0]['access_role'] === 'assignee' && $rows[0]['outcome'] === 'ok' && $rows[0]['request_id'] !== null, 'one audit row: who, as what, the outcome');

// ---- 3. Who may not read -------------------------------------------------------------------------------------------
$requestsBefore = count($shop->requests);
$error($read($opB, $th(1)), 403, 'CONFIRMARE_SOURCE_ACCESS_DENIED', 'an unassigned operator of the same source is refused');
$error($read($opO, $th(1)), 404, 'CONFIRMARE_NOT_FOUND', 'another store\'s operator does not even see the order');
$error($read($none, $th(1)), 404, 'CONFIRMARE_NOT_FOUND', 'without a Confirmare scope nothing is visible');
$error($read($sup, $th(60)), 404, 'CONFIRMARE_NOT_FOUND', 'a legacy order has no Confirmare record at all');
$check(count($shop->requests) === $requestsBefore, 'a refused read never reaches the shop');
$body = $ok($read($sup, $th(4)), 'supervisor read');
$check($body['available'] === true && $body['role'] === 'supervisor', 'a supervisor of the source reads any of its orders');
$body = $ok($read($opO, $outletOrder), 'outlet read');
$check($body === ['available' => false, 'reason' => 'not_configured'] && $readRows($outletOrder)[0]['outcome'] === 'not_configured', 'a store without source access says so (and the attempt is audited)');
$legacyRow = $all('SELECT o.order_uuid FROM operational_orders o WHERE o.global_order_id = ?', [$th(60)]);
$check(count($legacyRow) === 1 && $one('SELECT COUNT(*) FROM order_confirmations WHERE global_order_id = ?', [$th(60)]) === 0, 'the legacy order keeps its existing workflow (no Confirmare record)');

// ---- 4. Permission re-checked after the shop answered ------------------------------------------------------------
$shop->during = static function () use ($pdo, $uuids): void { $pdo->prepare("DELETE FROM employee_confirmare_scopes WHERE employee_uuid = ?")->execute([$uuids['saopa']]); };
$revoked = $read($opA, $th(2));
$error($revoked, 403, 'CONFIRMARE_SOURCE_ACCESS_DENIED', 'a scope revoked while the shop answered returns nothing');
$check(!str_contains(json_encode($revoked['body']), SA_PHONE) && $readRows($th(2))[0]['outcome'] === 'revoked_during_read', 'no customer data leaks and the attempt is audited');
$shop->during = null;
$ok($asRoot('PUT', "/management/employees/{$uuids['saopa']}/confirmare-scopes", $scopes['saopa']), 'scope restored');
$shop->during = static function () use ($pdo, $uuids, $th): void { $pdo->prepare('UPDATE order_confirmations SET assigned_employee_uuid = ?, version = version + 1 WHERE global_order_id = ?')->execute([$uuids['saopb'], $th(3)]); };
$error($read($opA, $th(3)), 403, 'CONFIRMARE_SOURCE_ACCESS_DENIED', 'a reassignment while the shop answered returns nothing');
$shop->during = null;
$error($read($opA, $th(3)), 403, 'CONFIRMARE_SOURCE_ACCESS_DENIED', 'after the reassignment the former assignee is refused at once');
$check($ok($read($opB, $th(3)), 'new assignee read')['available'] === true, 'the new assignee reads it');

// ---- 5. Shop unavailable, untrusted or changed ------------------------------------------------------------------------
$shop->fail = 'SOURCE_UNREACHABLE';
$check($ok($read($opA, $th(1)), 'unreachable') === ['available' => false, 'reason' => 'source_unreachable'], 'an unreachable shop is stated, nothing invented');
$shop->fail = null;
$shop->tamper = ['sign' => false];
$check($ok($read($opA, $th(1)), 'untrusted') === ['available' => false, 'reason' => 'source_response_untrusted'], 'an unsigned answer is never shown');
$shop->tamper = ['status' => 503, 'body' => '{"error":{"code":"access_disabled"}}'];
$check($ok($read($opA, $th(1)), 'disabled') === ['available' => false, 'reason' => 'source_disabled'], 'a shop with access switched off is stated');
$shop->orders[$id(1)]['pricing'] = 'sha256:' . str_repeat('c', 64);
$shop->orders[$id(1)]['modifiedAt'] = gmdate('Y-m-d\TH:i:s\Z', time() + 5);
$stale = $ok($read($opA, $th(1)), 'stale read');
$check($stale['available'] === true && $stale['freshness']['pricingMatches'] === false && $stale['freshness']['inSync'] === false, 'prices changed in the shop since Arasya\'s last event: shown as not in sync');
$outcomes = array_column($readRows($th(1)), 'outcome');
$check(array_slice($outcomes, -4) === ['source_unreachable', 'source_response_untrusted', 'source_disabled', 'ok'], 'every outcome is audited');

// ---- 6. Rate limit and a failing audit ------------------------------------------------------------------------------
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$limited = null;
for ($i = 0; $i < 31; $i++) {
    $limited = $read($sup, $th(4));
}
$error($limited, 429, 'RATE_LIMITED', 'more than 30 reads a minute are refused');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$pdo->exec('RENAME TABLE order_confirmation_source_reads TO order_confirmation_source_reads_off');
try {
    $failed = $read($opA, $th(1));
} finally {
    $pdo->exec('RENAME TABLE order_confirmation_source_reads_off TO order_confirmation_source_reads');
}
$error($failed, 503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'no audit, no data');
$check(!str_contains(json_encode($failed['body']), SA_PHONE), 'nothing of the customer leaves without its audit row');

// ---- 7. The audit never holds customer data or amounts ----------------------------------------------------------------
$auditDump = json_encode($all('SELECT * FROM order_confirmation_source_reads'), JSON_UNESCAPED_UNICODE);
$check(!str_contains($auditDump, SA_PHONE) && !str_contains($auditDump, '719.00') && !str_contains($auditDump, 'TEST Client') && !str_contains($auditDump, SA_KEY), 'the read audit holds no telephone number, amount, name or key');
$projection = json_encode($all('SELECT * FROM operational_orders WHERE global_order_id LIKE ?', ['trendhome:' . substr($id(1), 0, 4) . '%']), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
$check(!str_contains($projection, SA_PHONE) && !str_contains($projection, '719.00') && !str_contains($projection, '420.00'), 'the order projection never stores the telephone number or an amount');

// ---- 8. The shop's contact fact decides the contact condition -------------------------------------------------------
$noPhone = $id(2);
$ok(T::ingest($kernel, 'trendhome', $payload($noPhone, $submitted(2), ['contact' => ['phone' => false]])), 'the shop states: no callable number');
$d2 = $detail($opA, $th(2));
$check($d2['customer']['phoneMasked'] === '07** *** ***' && $d2['review']['contactAvailable'] === false, 'the shop\'s fact wins over a masked delivery phone');
$error(T::call($kernel, 'POST', '/confirmare/orders/' . rawurlencode($th(2)) . '/contact', ['expectedVersion' => $d2['version'], 'outcome' => 'confirmed', 'reviewFingerprint' => $d2['review']['fingerprint']], ['origin' => SA_ORIGIN, 'x-csrf-token' => $opA['csrf'], 'idempotency-key' => 'sa-contact-' . bin2hex(random_bytes(8))], $opA['cookie']),
    409, 'CONFIRMARE_CONTACT_UNAVAILABLE', 'no confirmed call without a callable number at the shop');
$ok(T::ingest($kernel, 'trendhome', $payload($noPhone, $submitted(2), ['contact' => ['phone' => true]])), 'the number was corrected in the shop');
$check($detail($opA, $th(2))['review']['contactAvailable'] === true, 'the corrected shop fact enables the call again');
$error(T::ingest($kernel, 'trendhome', $payload($noPhone, $submitted(2), ['contact' => ['phone' => '0711']])), 422, 'SOURCE_PAYLOAD_INVALID', 'the contact fact is a boolean, never a number');

// ---- 8b. Approval needs the approver's own shop read showing the prices Arasya holds ---------------------------------
$d2 = $detail($opA, $th(2));
$cmd = static fn (string $action, array $body): array => T::call($kernel, 'POST', '/confirmare/orders/' . rawurlencode($th(2)) . '/' . $action, $body, ['origin' => SA_ORIGIN, 'x-csrf-token' => $opA['csrf'], 'idempotency-key' => 'sa-' . $action . '-' . bin2hex(random_bytes(8))], $opA['cookie']);
$ok($cmd('contact', ['expectedVersion' => $d2['version'], 'outcome' => 'confirmed', 'reviewFingerprint' => $d2['review']['fingerprint']]), 'confirmed call');
$approve = static fn (array $d): array => $cmd('approve', ['expectedVersion' => $d['version'], 'customerCall' => 'confirmed', 'confirmedLineIds' => $d['review']['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $d['review']['fingerprint']]);
$pdo->prepare("DELETE FROM order_confirmation_source_reads WHERE global_order_id = ?")->execute([$th(2)]);
$error($approve($detail($opA, $th(2))), 409, 'CONFIRMARE_SHOP_NOT_REVIEWED', 'no approval without the approver reading the shop order');
$ok($read($sup, $th(2)), 'a supervisor read');
$error($approve($detail($opA, $th(2))), 409, 'CONFIRMARE_SHOP_NOT_REVIEWED', 'another person\'s read does not count for the approver');
$shop->orders[$id(2)]['pricing'] = 'sha256:' . str_repeat('d', 64);
$check($ok($read($opA, $th(2)), 'read with other prices')['freshness']['pricingMatches'] === false, 'the shop shows other prices than Arasya holds');
$error($approve($detail($opA, $th(2))), 409, 'CONFIRMARE_SHOP_NOT_REVIEWED', 'a read showing other prices does not count');
$shop->orders[$id(2)]['pricing'] = 'sha256:' . hash('sha256', "sa-price-{$id(2)}");
$ok($read($opA, $th(2)), 'read with the current prices');
$approved = $ok($approve($detail($opA, $th(2))), 'approval after the shop read');
$check(($approved['status'] ?? null) === 'approved', 'approved after reading the current shop order');
$check(count(array_filter($readRows($th(2)), static fn (array $r): bool => $r['outcome'] === 'ok' && $r['shop_pricing_fingerprint'] !== null)) >= 2, 'the read audit keeps the price hash each read showed (never an amount)');

// ---- 9. Financial attention (a change of the total after a payment) ---------------------------------------------------
$fp = 'sha256:' . hash('sha256', "sa-price-{$id(1)}");
$error(T::ingest($kernel, 'trendhome', $payload($id(1), $submitted(1), ['financial' => ['state' => 'cleared', 'basis' => 'online_payment', 'pricingFingerprint' => $fp, 'attention' => 'refund_due']])), 422, 'SOURCE_PAYLOAD_INVALID', 'an attention never clears');
$error(T::ingest($kernel, 'trendhome', $payload($id(1), $submitted(1), ['financial' => ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $fp, 'attention' => 'refund_please']])), 422, 'SOURCE_PAYLOAD_INVALID', 'unknown attention codes are refused');
$ok(T::ingest($kernel, 'trendhome', $payload($id(1), $submitted(1), ['financial' => ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $fp, 'attention' => 'additional_payment_due']])), 'a repriced paid order');
$d1 = $detail($opA, $th(1));
$check($d1['financial']['attention'] === 'additional_payment_due' && $d1['financial']['open'] === false && $d1['productionCleared'] === false, 'the attention is shown and production stays closed');
$ok(T::ingest($kernel, 'trendhome', $payload($id(1), $submitted(1))), 'settled again');
$check($detail($opA, $th(1))['financial']['attention'] === null, 'the attention disappears with the next statement');

// ---- 10. Server logs: no customer data, no secret --------------------------------------------------------------------
$log = (string) file_get_contents($logFile);
$check($log !== '' && str_contains($log, '/source'), 'the run was logged');
$check(!str_contains($log, SA_PHONE) && !str_contains($log, '0700000123') && !str_contains($log, '719.00') && !str_contains($log, 'TEST Client') && !str_contains($log, SA_KEY) && !str_contains($log, 'Exemplu'), 'server logs hold no telephone number, amount, name, address or key');
@unlink($logFile);

// Leave the shared disposable database as the next suites expect it: no Confirmare state.
foreach (['order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

fwrite(STDOUT, "PASS MySQL Confirmare source access integration ({$checks} checks)\n");
