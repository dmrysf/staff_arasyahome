<?php

declare(strict_types=1);

// Confirmare controlled shop edits (migration 038, API 2.40.0, Phase 4B) on a disposable database, through the HTTP
// kernel, with an in-process TEST shop that speaks protocol v1 like YD SOFT (the fake never prices anything: an edit moves
// the total by a fixed TEST amount). Covers: who may edit (assignee, supervisor; never an unassigned operator, another
// store, a read-only store, a decided or legacy order), strict operations, previews never stored, apply only with a
// preview token and an Idempotency-Key, replay and conflicting keys, the shop's refusals (version changed, stale preview,
// stock gone, production started), simultaneous edits, lost answers and 5xx reconciled with the shop's stored answer, a
// request the shop never received, a supervisor settling a reassigned operator's edit, the approval blocked until the
// shop's order update arrived and the review redone, the audit (codes only), the rate limit, and logs without amounts.

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
    fwrite(STDOUT, "SKIP MySQL Confirmare source edit integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
// Every server log line of this run goes to one file, scanned at the end for customer data and secrets.
$logFile = tempnam(sys_get_temp_dir(), 'arasya-source-edit-log-');
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
// Trend Home: source access `edit`. Outlet Perdele: `read` only.
$config = new Config(...[...get_object_vars($base), 'sourceAccess' => [
    'trendhome' => ['mode' => 'edit', 'url' => 'https://trendhome.example', 'key' => 'th-test:' . SA_KEY],
    'outletperdele' => ['mode' => 'read', 'url' => 'https://outletperdele.example', 'key' => 'op-test:' . SA_KEY . '-outlet'],
]]);
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
$rootName = "se.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-se-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Source edit root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Source edit root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'se-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$password = 'source edit passphrase 2026';
$uuids = [];
foreach (['seopa', 'seopb', 'seopo', 'sesup', 'senone'] as $name) {
    // Generic test identities: access only from the scopes granted below, never from a name.
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test-se');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['staff']]), "{$name} applications");
}
$scopes = ['seopa' => ['operate' => ['trendhome'], 'supervise' => []], 'seopb' => ['operate' => ['trendhome'], 'supervise' => []], 'seopo' => ['operate' => ['outletperdele'], 'supervise' => []], 'sesup' => ['operate' => [], 'supervise' => ['trendhome']]];
foreach ($scopes as $name => $grant) $ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/confirmare-scopes", $grant), "grant {$name}");
$login = static fn (string $name): array => T::login($kernel, "{$name}.{$suffix}", $password);
$opA = $login('seopa');
$opB = $login('seopb');
$opO = $login('seopo');
$sup = $login('sesup');
$none = $login('senone');
$get = static fn (array $who, string $path): array => T::call($kernel, 'GET', $path, null, ['origin' => SA_ORIGIN], $who['cookie']);
$read = static fn (array $who, string $globalId): array => $get($who, '/confirmare/orders/' . rawurlencode($globalId) . '/source');
$detail = static fn (array $who, string $globalId): array => $ok($get($who, '/confirmare/orders/' . rawurlencode($globalId)), "detail {$globalId}");
$readRows = static fn (string $globalId): array => $all('SELECT * FROM order_confirmation_source_reads WHERE global_order_id = ? ORDER BY read_at, read_uuid', [$globalId]);

// ---- Fixtures: Confirmare orders of both sources, a legacy order ------------------------------------------------------
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    fwrite(STDOUT, "SKIP MySQL Confirmare source edit integration: needs a clock after the Confirmare cutover.\n");
    exit(0);
}
$check(T::activationNeedsReadiness($pdo, $registry->find('trendhome')), 'API 2.45.0: activation needs the source readiness (Phase 4)');
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
foreach (['trendhome', 'outletperdele'] as $src) {
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-20 minutes'))))->schedule($registry->find($src), $activatedAt, 'Test Owner', 'source edit test', 'se-test', true);
    T::ingest($kernel, $src, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-1 minute'))))->activate($registry->find($src), 'Test Owner', 'source edit test', 'se-test', true);
}
$heartbeat = T::ingest($kernel, 'trendhome', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')], null, null, 'heartbeat');
$check(in_array('contact', $heartbeat['body']['orderFacts'] ?? [], true), 'the heartbeat announces the contact fact');
$numberBase = T::freeOrderBase($pdo, 960000, 989999, 100); // ids base+1..base+60; a block no earlier run used
$id = static fn (int $n): string => (string) ($numberBase + $n);
$th = static fn (int $n): string => 'trendhome:' . $id($n);
$tick = 0;
$changedAt = [];
$payload = static function (string $orderId, ?array $submission, array $extra = []) use ($suffix, &$tick, &$changedAt): array {
    $tick++;
    $changedAt[$orderId] = $extra['changedAt'] ?? gmdate('Y-m-d\TH:i:s', time() - 4000 + $tick) . 'Z';
    $p = T::sourceOrder($orderId, "se-{$orderId}-{$suffix}-{$tick}", $changedAt[$orderId], null, 'processing', 'active', [[
        'id' => 9101, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'width' => 3, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1,
    ]]);
    unset($p['order']['notes']);
    $fingerprint = $extra['pricing'] ?? 'sha256:' . hash('sha256', "se-price-{$orderId}");
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
    $shop->orders[$id($n)] = ['id' => $id($n), 'phone' => SA_PHONE, 'pricing' => 'sha256:' . hash('sha256', "se-price-{$id($n)}"), 'modifiedAt' => $changedAt[$id($n)], 'eligible' => $n !== 60];
}
$assignee = static fn (string $globalId): ?string => ($v = $one('SELECT assigned_employee_uuid FROM order_confirmations WHERE global_order_id = ?', [$globalId])) === false ? null : $v;
// Deterministic: 1, 2 and 3 with operator A, 4 with operator B.
foreach ([1 => 'seopa', 2 => 'seopa', 3 => 'seopa', 4 => 'seopb'] as $n => $who) {
    if ($assignee($th($n)) !== $uuids[$who]) {
        $ok($asRoot('POST', '/confirmare/orders/' . rawurlencode($th($n)) . '/reassign', ['expectedVersion' => (int) $one('SELECT version FROM order_confirmations WHERE global_order_id = ?', [$th($n)]), 'employeeId' => $uuids[$who]]), "assign {$n}");
    }
}
$outletOrder = 'outletperdele:' . $id(50);
$check($assignee($outletOrder) === $uuids['seopo'], 'the outlet order is with the outlet operator');
$shop->orders[$id(50)] = ['id' => $id(50), 'phone' => SA_PHONE];

$shop->products['IN-SE-01'] = ['id' => '601', 'name' => 'Inele perdea', 'sku' => 'IN-SE-01', 'type' => 'simple', 'purchasable' => true, 'inStock' => true, 'stock' => 40, 'variations' => [], 'calculator' => null];
$path = static fn (string $globalId, string $tail): string => '/confirmare/orders/' . rawurlencode($globalId) . '/source/' . $tail;
$post = static fn (array $who, string $globalId, string $tail, array $body, ?string $key = null): array => T::call($kernel, 'POST', $path($globalId, $tail), $body,
    ['origin' => SA_ORIGIN, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? 'se-edit-' . bin2hex(random_bytes(10))], $who['cookie']);
$catalog = static fn (array $who, string $globalId): array => $get($who, $path($globalId, 'catalog'));
$version = static fn (string $n): string => 'sha256:' . hash('sha256', 'version-' . $n);
$ops = static fn (int $n, string $length): array => [['op' => 'update_line', 'lineId' => (string) ((int) $id($n) * 10 + 1), 'fields' => ['lungimea' => $length]]];
$editRows = static fn (string $globalId): array => $all('SELECT * FROM order_confirmation_source_edits WHERE global_order_id = ? ORDER BY requested_at, edit_uuid', [$globalId]);
$refused = static function (array $r, string $reason, string $label) use ($check): array {
    $check($r['status'] === 409 && ($r['body']['error']['code'] ?? '') === 'CONFIRMARE_EDIT_REFUSED' && ($r['body']['error']['details']['reason'] ?? '') === $reason, "{$label}: expected refusal {$reason}, got {$r['status']} " . json_encode($r['body']));
    return $r['body']['error']['details'] ?? [];
};

// ---- 1. Capabilities and who may edit ------------------------------------------------------------------------------
$d1 = $detail($opA, $th(1));
$check($d1['sourceAccess'] === ['configured' => true, 'read' => true, 'edit' => true, 'changePending' => false, 'openEdits' => []], 'the assignee may change the order (shop access `edit`)');
$check($detail($opO, $outletOrder)['sourceAccess']['edit'] === false, 'a read-only shop offers no change');
$requestsBefore = count($shop->requests);
$error($catalog($opB, $th(1)), 403, 'CONFIRMARE_SOURCE_ACCESS_DENIED', 'an unassigned operator of the same source may not change it');
$error($catalog($opO, $th(1)), 404, 'CONFIRMARE_NOT_FOUND', 'another store\'s operator does not even see it');
$error($catalog($none, $th(1)), 404, 'CONFIRMARE_NOT_FOUND', 'without a Confirmare scope nothing is visible');
$error($catalog($sup, $th(60)), 404, 'CONFIRMARE_NOT_FOUND', 'a legacy order is never changed through Confirmare');
$error($catalog($opO, $outletOrder), 409, 'CONFIRMARE_SOURCE_EDIT_DISABLED', 'a store with read-only access refuses changes');
$check(count($shop->requests) === $requestsBefore, 'refused requests never reach the shop');
$cat = $ok($catalog($opA, $th(1)), 'catalog');
$check($cat['catalog']['editable'] === true && $cat['catalog']['lines'][0]['calculator']['fields'][0]['name'] === 'lungimea' && $cat['catalog']['lines'][0]['calculator']['fields'][0]['value'] === '3'
    && $cat['catalog']['shippingOptions'][1]['method'] === 'free_shipping:4', 'the catalog: the calculator fields with their current values and the zone\'s shipping methods');
$check($ok($catalog($sup, $th(4)), 'supervisor catalog')['catalog']['version'] === $version($id(4)), 'a supervisor of the source may change any of its orders');

// ---- 2. One product of the same shop by exact SKU ------------------------------------------------------------------
$product = $ok($post($opA, $th(1), 'product', ['sku' => 'IN-SE-01']), 'product');
$check($product['product']['id'] === '601' && $product['product']['inStock'] === true && $product['product']['calculator'] === null, 'a product of the same shop by its exact SKU, with its stock');
$refused($post($opA, $th(1), 'product', ['sku' => 'NO-SUCH']), 'product_not_found', 'an unknown SKU');
$error($post($opA, $th(1), 'product', ['sku' => "bad\nsku"]), 422, 'CONFIRMARE_EDIT_INVALID', 'a malformed SKU never reaches the shop');
$error($post($opA, $th(1), 'product', ['sku' => 'IN-SE-01', 'search' => 'perdea']), 400, 'INVALID_REQUEST', 'no search parameter exists');

// ---- 3. Preview: strict operations, computed by the shop, never stored ---------------------------------------------
$requestsBefore = count($shop->requests);
foreach ([
    [['op' => 'set_price', 'lineId' => '1', 'price' => '1.00']],
    [['op' => 'update_line', 'lineId' => '1', 'price' => '1.00']],
    [['op' => 'update_line', 'lineId' => 'abc']],
    [['op' => 'update_line', 'lineId' => '1', 'quantity' => 0]],
    [['op' => 'update_line', 'lineId' => '1', 'quantity' => 101]],
    [['op' => 'update_line', 'lineId' => '1', 'fields' => ['lungimea' => 3.5]]],
    [['op' => 'remove_line', 'lineId' => '1'], ['op' => 'update_line', 'lineId' => '1']],
    [['op' => 'add_line', 'productId' => '601']],
    [['op' => 'set_shipping', 'shippingLineId' => '9', 'method' => 'https://evil']],
    [],
] as $i => $bad) {
    $error($post($opA, $th(1), 'preview', ['expectedVersion' => $version($id(1)), 'operations' => $bad]), 422, 'CONFIRMARE_EDIT_INVALID', "invalid operation set {$i} (no price override, ids, quantity 1..100, string values, one change per line)");
}
$error($post($opA, $th(1), 'preview', ['expectedVersion' => 'v1', 'operations' => $ops(1, '3.5')]), 422, 'CONFIRMARE_EDIT_INVALID', 'the order version is required');
$check(count($shop->requests) === $requestsBefore, 'invalid edits never reach the shop');
$details = $refused($post($opA, $th(1), 'preview', ['expectedVersion' => 'sha256:' . str_repeat('0', 64), 'operations' => $ops(1, '3.5')]), 'version_changed', 'a preview on an old order version');
$check($details['version'] === $version($id(1)), 'the refusal names the current shop version');
$preview = $ok($post($opA, $th(1), 'preview', ['expectedVersion' => $version($id(1)), 'operations' => $ops(1, '3.5')]), 'preview')['preview'];
$check($preview['before']['total'] === '719.00' && $preview['after']['total'] === '754.00' && $preview['lines'][0]['state'] === 'changed' && $preview['financial']['impact'] === 'additional_payment_due'
    && preg_match('/^[0-9a-f]{64}$/', $preview['previewToken']) === 1, 'the preview: totals before and after, the changed line, the money impact and a token');
$check($editRows($th(1)) === [] && (int) $one('SELECT COUNT(*) FROM order_confirmation_source_edits') === 0, 'a preview changes and stores nothing');

// ---- 4. Apply: only the previewed edit, with an Idempotency-Key ----------------------------------------------------
$applyBody = ['expectedVersion' => $version($id(1)), 'operations' => $ops(1, '3.5'), 'previewToken' => $preview['previewToken']];
$error($post($opA, $th(1), 'apply', $applyBody, 'short'), 400, 'INVALID_IDEMPOTENCY_KEY', 'no apply without a valid Idempotency-Key');
$error($post($opA, $th(1), 'apply', ['expectedVersion' => $version($id(1)), 'operations' => $ops(1, '3.5')]), 422, 'CONFIRMARE_EDIT_INVALID', 'no apply without a preview');
$refused($post($opA, $th(1), 'apply', ['previewToken' => str_repeat('a', 64)] + $applyBody, 'se-stale-' . $suffix . '-000001'), 'preview_stale', 'a token of another preview');
$staleRow = $editRows($th(1))[0];
$check($staleRow['state'] === 'rejected' && $staleRow['outcome'] === 'preview_stale' && $staleRow['completed_at'] !== null, 'a refused edit is audited as rejected with the shop\'s reason');
$key1 = 'se-apply-' . $suffix . '-000001';
$applied = $ok($post($opA, $th(1), 'apply', $applyBody, $key1), 'apply');
$check($applied['state'] === 'applied' && $applied['result']['after']['total'] === '754.00' && $applied['result']['financialState']['attention'] === 'additional_payment_due', 'applied exactly as previewed; the shop states the money now due (never moved)');
$row = $editRows($th(1))[1];
$check($row['state'] === 'applied' && $row['employee_uuid'] === $uuids['seopa'] && $row['access_role'] === 'assignee' && $row['source_version_before'] === $version($id(1))
    && $row['source_version_after'] === $shop->orders[$id(1)]['version'] && $row['source_modified_after'] !== null && $row['idempotency_key'] === $key1, 'the audit row: who, as what, versions before and after');
$check(json_decode($row['operations_json'], true) === [['op' => 'update_line', 'lineId' => (string) ((int) $id(1) * 10 + 1), 'fields' => ['lungimea']]], 'the audit keeps the operation and the field NAME, never the measurement or an amount');
$replay = $ok($post($opA, $th(1), 'apply', $applyBody, $key1), 'replay');
$check($replay === ['state' => 'applied', 'idempotencyKey' => $key1, 'result' => null] && count($editRows($th(1))) === 2, 'a retried apply (same key) changes nothing and stores nothing new');
$error($post($opA, $th(1), 'apply', ['operations' => $ops(1, '4')] + $applyBody, $key1), 409, 'IDEMPOTENCY_CONFLICT', 'the same key for another edit is refused');

// ---- 5. Until Arasya received the shop's update: no other change, no approval ---------------------------------------
$d1 = $detail($opA, $th(1));
$check($d1['sourceAccess']['changePending'] === true && $d1['sourceAccess']['openEdits'] === [], 'the applied edit waits for the shop\'s order update');
$error($post($opA, $th(1), 'preview', ['expectedVersion' => $shop->orders[$id(1)]['version'], 'operations' => $ops(1, '4')]), 409, 'CONFIRMARE_SHOP_CHANGE_PENDING', 'no further change before the update arrived');
$cmd = static fn (array $who, string $globalId, string $action, array $body): array => T::call($kernel, 'POST', '/confirmare/orders/' . rawurlencode($globalId) . '/' . $action, $body, ['origin' => SA_ORIGIN, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => 'se-' . $action . '-' . bin2hex(random_bytes(8))], $who['cookie']);
$approve = static fn (array $who, string $globalId, array $d): array => $cmd($who, $globalId, 'approve', ['expectedVersion' => $d['version'], 'customerCall' => 'confirmed', 'confirmedLineIds' => $d['review']['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $d['review']['fingerprint']]);
$oldReview = $d1['review']['fingerprint'];
$ok($cmd($opA, $th(1), 'contact', ['expectedVersion' => $d1['version'], 'outcome' => 'confirmed', 'reviewFingerprint' => $oldReview]), 'a confirmed call on the pre-edit order');
$ok($read($opA, $th(1)), 'shop read after the edit, before its update');
$error($approve($opA, $th(1), $detail($opA, $th(1))), 409, 'CONFIRMARE_SHOP_CHANGE_PENDING', 'no approval before Arasya received the edited order');
// The shop's order update arrives (its modification time is the edit's).
$ok(T::ingest($kernel, 'trendhome', $payload($id(1), $submitted(1), ['contact' => ['phone' => true], 'pricing' => $shop->orders[$id(1)]['pricing'], 'changedAt' => $shop->orders[$id(1)]['modifiedAt']])), 'the edited order arrives');
$d1 = $detail($opA, $th(1));
$check($d1['sourceAccess']['changePending'] === false, 'the edit is settled once the update arrived');
$check($d1['review']['fingerprint'] !== $oldReview, 'the edit invalidated the Confirmare review');
$stale = $cmd($opA, $th(1), 'approve', ['expectedVersion' => $d1['version'], 'customerCall' => 'confirmed', 'confirmedLineIds' => $d1['review']['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $oldReview]);
$check($stale['status'] === 409 && in_array($stale['body']['error']['code'], ['CONFIRMARE_REVIEW_STALE', 'CONFIRMARE_SHOP_NOT_REVIEWED'], true), 'an approval on the pre-edit review is refused');
$pdo->prepare('UPDATE order_confirmation_source_reads SET read_at = ? WHERE global_order_id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 30), $th(1)]);
$error($approve($opA, $th(1), $d1), 409, 'CONFIRMARE_SHOP_NOT_REVIEWED', 'a shop read from before the edit does not count');

// ---- 6. Simultaneous edits, refusals of the shop, blockers ---------------------------------------------------------
$v2 = $version($id(4));
$pB = $ok($post($opB, $th(4), 'preview', ['expectedVersion' => $v2, 'operations' => $ops(4, '3.2')]), 'operator B preview')['preview'];
$pS = $ok($post($sup, $th(4), 'preview', ['expectedVersion' => $v2, 'operations' => $ops(4, '2.8')]), 'supervisor preview')['preview'];
$check($ok($post($opB, $th(4), 'apply', ['expectedVersion' => $v2, 'operations' => $ops(4, '3.2'), 'previewToken' => $pB['previewToken']]), 'first apply')['state'] === 'applied', 'the first of two simultaneous edits applies');
$error($post($sup, $th(4), 'apply', ['expectedVersion' => $v2, 'operations' => $ops(4, '2.8'), 'previewToken' => $pS['previewToken']]), 409, 'CONFIRMARE_SHOP_CHANGE_PENDING', 'the second waits for the first to settle');
$ok(T::ingest($kernel, 'trendhome', $payload($id(4), $submitted(4), ['contact' => ['phone' => true], 'pricing' => $shop->orders[$id(4)]['pricing'], 'changedAt' => $shop->orders[$id(4)]['modifiedAt']])), 'order 4 update');
$refused($post($sup, $th(4), 'apply', ['expectedVersion' => $v2, 'operations' => $ops(4, '2.8'), 'previewToken' => $pS['previewToken']]), 'version_changed', 'then the shop refuses it on the changed version');
$v2 = $shop->orders[$id(4)]['version'];
$p = $ok($post($opB, $th(4), 'preview', ['expectedVersion' => $v2, 'operations' => $ops(4, '3.0')]), 'preview before stock runs out')['preview'];
$check($p['applicable'] === true, 'in stock at preview time');
$shop->editBlockers = [['code' => 'out_of_stock', 'productId' => '501', 'available' => 0, 'needed' => 3]];
$refused($post($opB, $th(4), 'apply', ['expectedVersion' => $v2, 'operations' => $ops(4, '3.0'), 'previewToken' => $p['previewToken']]), 'preview_stale', 'stock exhausted after the preview: the edit is refused');
$blocked = $ok($post($opB, $th(4), 'preview', ['expectedVersion' => $v2, 'operations' => $ops(4, '3.0')]), 'preview with a blocker')['preview'];
$check($blocked['applicable'] === false && $blocked['blockers'][0] === ['code' => 'out_of_stock', 'productId' => '501', 'available' => 0, 'needed' => 3], 'the preview shows the blocker');
$details = $refused($post($opB, $th(4), 'apply', ['expectedVersion' => $v2, 'operations' => $ops(4, '3.0'), 'previewToken' => $blocked['previewToken']]), 'blocked', 'a blocked edit');
$check($details['blockers'][0]['code'] === 'out_of_stock', 'the refusal carries the blockers');
$shop->editBlockers = [];
$shop->editRefusal = 'production_started';
$refused($post($opB, $th(4), 'preview', ['expectedVersion' => $v2, 'operations' => $ops(4, '3.0')]), 'production_started', 'the shop refuses an order whose legacy production started');
$shop->editRefusal = null;

// ---- 7. Lost answers, 5xx and a request the shop never received: reconciliation ----------------------------------
$v3 = $version($id(2));
$p3 = $ok($post($opA, $th(2), 'preview', ['expectedVersion' => $v3, 'operations' => $ops(2, '3.3')]), 'order 2 preview')['preview'];
$shop->afterApply = 'answer_lost';
$lostKey = 'se-lost-' . $suffix . '-000001';
$lost = $ok($post($opA, $th(2), 'apply', ['expectedVersion' => $v3, 'operations' => $ops(2, '3.3'), 'previewToken' => $p3['previewToken']], $lostKey), 'lost answer');
$check($lost === ['state' => 'unknown', 'idempotencyKey' => $lostKey] && $editRows($th(2))[0]['state'] === 'unknown', 'a lost answer leaves the edit unknown, never assumed');
$d2 = $detail($opA, $th(2));
$check($d2['sourceAccess']['changePending'] === true && $d2['sourceAccess']['openEdits'][0]['idempotencyKey'] === $lostKey && $d2['sourceAccess']['openEdits'][0]['mine'] === true, 'the detail offers to check the unsettled change');
$error($post($opA, $th(2), 'preview', ['expectedVersion' => $v3, 'operations' => $ops(2, '3.4')]), 409, 'CONFIRMARE_SHOP_CHANGE_PENDING', 'no other change while one is unknown');
$settled = $ok($post($opA, $th(2), 'reconcile', ['idempotencyKey' => $lostKey]), 'reconcile');
$check($settled['state'] === 'applied' && $settled['result']['after']['total'] === '754.00' && $editRows($th(2))[0]['state'] === 'applied', 'reconciliation reads the shop\'s stored answer: applied');
$ok(T::ingest($kernel, 'trendhome', $payload($id(2), $submitted(2), ['contact' => ['phone' => true], 'pricing' => $shop->orders[$id(2)]['pricing'], 'changedAt' => $shop->orders[$id(2)]['modifiedAt']])), 'order 2 update');
$v3 = $shop->orders[$id(2)]['version'];
$p3 = $ok($post($opA, $th(2), 'preview', ['expectedVersion' => $v3, 'operations' => $ops(2, '3.6')]), 'order 2 preview again')['preview'];
$shop->afterApply = 'crash';
$crashKey = 'se-crash-' . $suffix . '-00001';
$check($ok($post($opA, $th(2), 'apply', ['expectedVersion' => $v3, 'operations' => $ops(2, '3.6'), 'previewToken' => $p3['previewToken']], $crashKey), 'crash')['state'] === 'unknown', 'a 5xx after the shop committed is unknown, not a refusal');
// The operator is reassigned before checking: only a supervisor settles it now.
$ok($asRoot('POST', '/confirmare/orders/' . rawurlencode($th(2)) . '/reassign', ['expectedVersion' => (int) $one('SELECT version FROM order_confirmations WHERE global_order_id = ?', [$th(2)]), 'employeeId' => $uuids['seopb']]), 'reassign order 2');
$error($post($opA, $th(2), 'reconcile', ['idempotencyKey' => $crashKey]), 403, 'CONFIRMARE_SOURCE_ACCESS_DENIED', 'the former assignee can no longer act');
$error($post($opB, $th(2), 'reconcile', ['idempotencyKey' => $crashKey]), 404, 'CONFIRMARE_EDIT_NOT_FOUND', 'the new assignee does not see another operator\'s change');
$check($detail($sup, $th(2))['sourceAccess']['openEdits'][0] === ['idempotencyKey' => $crashKey, 'state' => 'unknown', 'requestedAt' => $detail($sup, $th(2))['sourceAccess']['openEdits'][0]['requestedAt'], 'mine' => false], 'a supervisor sees the unsettled change of another operator');
$check($ok($post($sup, $th(2), 'reconcile', ['idempotencyKey' => $crashKey]), 'supervisor reconcile')['state'] === 'applied', 'a supervisor settles it with the shop\'s stored answer');
$last = end($shop->requests);
$check($last['route'] === '/yd-arasya/v1/confirmare/edit-status' && $last['body']['editEmployeeId'] === $uuids['seopa'] && $last['body']['actor'] === ['employeeId' => $uuids['sesup'], 'role' => 'supervisor'], 'the shop learns who asks (the supervisor) and whose change it was');
$ok(T::ingest($kernel, 'trendhome', $payload($id(2), $submitted(2), ['contact' => ['phone' => true], 'pricing' => $shop->orders[$id(2)]['pricing'], 'changedAt' => $shop->orders[$id(2)]['modifiedAt']])), 'order 2 update again');
$v3 = $shop->orders[$id(2)]['version'];
$p3 = $ok($post($opB, $th(2), 'preview', ['expectedVersion' => $v3, 'operations' => $ops(2, '3.7')]), 'order 2 preview by B')['preview'];
$shop->fail = 'SOURCE_UNREACHABLE';
$neverKey = 'se-never-' . $suffix . '-00001';
$check($ok($post($opB, $th(2), 'apply', ['expectedVersion' => $v3, 'operations' => $ops(2, '3.7'), 'previewToken' => $p3['previewToken']], $neverKey), 'unreachable')['state'] === 'unknown', 'an unreachable shop: unknown');
$shop->fail = null;
$check($ok($post($opB, $th(2), 'reconcile', ['idempotencyKey' => $neverKey]), 'too early')['state'] === 'unknown', 'the shop has no record yet: still unknown within the request window');
$pdo->prepare('UPDATE order_confirmation_source_edits SET requested_at = ? WHERE idempotency_key = ?')->execute([gmdate('Y-m-d H:i:s', time() - 600), $neverKey]);
$refused($post($opB, $th(2), 'reconcile', ['idempotencyKey' => $neverKey]), 'not_received', 'after the window: never received, nothing changed');
$never = $all('SELECT state, outcome FROM order_confirmation_source_edits WHERE idempotency_key = ?', [$neverKey]);
$check($never === [['state' => 'rejected', 'outcome' => 'not_received']], 'audited as rejected (not received)');
$shop->fail = 'SOURCE_ADDRESS_REFUSED';
$error($post($opB, $th(2), 'apply', ['expectedVersion' => $v3, 'operations' => $ops(2, '3.7'), 'previewToken' => $p3['previewToken']]), 503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'a refused shop address: nothing was sent');
$shop->fail = null;

// ---- 8. Decided orders, revoked access, rate limit ------------------------------------------------------------------
$ok($cmd($opA, $th(3), 'reject', ['expectedVersion' => (int) $one('SELECT version FROM order_confirmations WHERE global_order_id = ?', [$th(3)]), 'reasonCode' => 'customer_cancelled']), 'reject order 3');
$error($catalog($sup, $th(3)), 409, 'CONFIRMARE_NOT_PENDING', 'a decided order is never changed here');
$shop->during = static function () use ($pdo, $uuids): void { $pdo->prepare('DELETE FROM employee_confirmare_scopes WHERE employee_uuid = ?')->execute([$uuids['seopb']]); };
$revoked = $post($opB, $th(4), 'preview', ['expectedVersion' => $shop->orders[$id(4)]['version'], 'operations' => $ops(4, '3.1')]);
$error($revoked, 403, 'CONFIRMARE_SOURCE_ACCESS_DENIED', 'a scope revoked while the shop computed the preview shows nothing');
$check(!str_contains(json_encode($revoked['body']), '754.00'), 'no amount leaks');
$shop->during = null;
$ok($asRoot('PUT', "/management/employees/{$uuids['seopb']}/confirmare-scopes", $scopes['seopb']), 'scope restored');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$limited = null;
for ($i = 0; $i < 31; $i++) {
    $limited = $catalog($sup, $th(4));
}
$error($limited, 429, 'RATE_LIMITED', 'more than 30 shop edit requests a minute are refused');
$pdo->exec('DELETE FROM api_rate_limit_buckets');

// ---- 9. Append-only audit, nothing of the customer stored, logs clean -----------------------------------------------
$code = (string) file_get_contents(dirname(__DIR__) . '/src/Confirmare/ConfirmareSourceEdits.php');
$check(preg_match_all('/UPDATE order_confirmation_source_edits/', $code) === 1 && preg_match('/DELETE FROM order_confirmation_source_edits/i', $code) === 0
    && str_contains($code, "WHERE edit_uuid = ? AND state IN ('pending', 'unknown')"), 'one completion statement (pending or unknown only), never a delete');
$auditDump = json_encode($all('SELECT * FROM order_confirmation_source_edits'), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
$check(!str_contains($auditDump, '"754.00"') && !str_contains($auditDump, '"719.00"') && !str_contains($auditDump, '3.5"') && !str_contains($auditDump, SA_PHONE) && !str_contains($auditDump, SA_KEY), 'the edit audit holds no amount, measurement value, telephone number or key');
$projection = json_encode($all('SELECT * FROM operational_orders WHERE global_order_id LIKE ?', ['trendhome:' . substr($id(1), 0, 4) . '%']), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
// Exact JSON values: a DATETIME(6) such as 10:12:35.000000 is no amount.
$check(!str_contains($projection, '"754.00"') && !str_contains($projection, '"35.00"') && !str_contains($projection, '"719.00"'), 'the order projection stores no amount of an edit');
$check(T::activationNeedsReadiness($pdo, $registry->find('trendhome')), 'API 2.45.0: activation still needs the source readiness');
$log = (string) file_get_contents($logFile);
$check($log !== '' && str_contains($log, '/source/'), 'the run was logged');
$check(!str_contains($log, '754.00') && !str_contains($log, '719.00') && !str_contains($log, SA_PHONE) && !str_contains($log, SA_KEY) && !str_contains($log, 'lungimea'), 'server logs hold no amount, telephone number, field or key');
@unlink($logFile);

// Leave the shared disposable database as the next suites expect it: no Confirmare state.
foreach (['order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

fwrite(STDOUT, "PASS MySQL Confirmare source edit integration ({$checks} checks)\n");
