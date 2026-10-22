<?php

declare(strict_types=1);

// Confirmare workflow cohort (migration 035, API 2.37.0, Phase 1) on a disposable database, through the HTTP kernel.
// Covers: activation refusals (Phase 1 workflow not ready, permission, business date), the per-source activation
// instant, the cohort boundaries, source isolation for the same numeric order id, unpaid intake and drafts, missing and
// future evidence, every production start the gate guards (signed source print, Staff generation, cutting intake,
// stage-1 claim, manager reassign, manager takeover), payment and confirmation together, repeated updates, replay,
// out-of-order, cancellation, suspension, a switched-off permission, approval through the stored decision only,
// orders that existed before the activation, concurrent ingestion around an activation, the resumable migration, the
// read-only diagnosis and the rollback floor.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Production\WorkflowCohortActivation;
use Arasya\Operations\Tests\MutableClock;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL Confirmare cohort integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}

const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $body = $r['body']; unset($body['document']['pdf']); $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($body)); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$apiError = static function (Closure $call, string $code, string $label) use ($check): void {
    try { $call(); } catch (ApiException $e) { $check($e->errorCode === $code, "{$label}: expected {$code}, got {$e->errorCode}"); return; }
    $check(false, "{$label}: expected {$code}, nothing thrown");
};

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce'];
$settings = static fn (string $homeConfirmare, string $outletConfirmare, string $authority = 'enforce'): array => [
    'trendhome' => ['authority' => $authority] + $full + ['paymentGate' => 'enforce', 'confirmare' => $homeConfirmare],
    'outletperdele' => $full + ['paymentGate' => 'off', 'confirmare' => $outletConfirmare],
];
$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $settings('on', 'off'));
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$row = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetch(PDO::FETCH_ASSOC) ?: []; };

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$offKernel = (new Container(T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $settings('off', 'off')), $pdo))->kernel();
$observeKernel = (new Container(T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $settings('off', 'off', 'observe')), $pdo))->kernel();
$registry = SourceRegistry::fromConfig($config);
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "cf.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-cf-root', $rootName);
$dash = static function (string $username, string $password) use ($kernel): array {
    $r = T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => DASHBOARD_ORIGIN]);
    if ($r['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $r['headers']['Set-Cookie'] ?? '', $m)) throw new RuntimeException("login {$username}");
    return ['cookie' => rawurldecode($m[1]), 'csrf' => (string) $r['body']['csrfToken']];
};
$first = $dash($rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Confirmare root passphrase 2026!'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = $dash($rootName, 'Confirmare root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null, ?object $k = null): array => T::call($k ?? $kernel, $method, $path, $body, ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $root['csrf'], 'idempotency-key' => 'cf-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$roleIds = [];
foreach ($ok($asRoot('GET', '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
$password = 'confirmare cohort passphrase 2026';
$uuids = [];
$identity = static function (string $name, array $stages, array $apps, array $roles = []) use ($container, $asRoot, $ok, $password, $suffix, &$uuids): void {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test-cf');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]), "{$name} applications");
    if ($roles !== []) $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
};
$identity('cfcutter', ['material-preparation'], ['staff']);
$identity('cfwaiter', ['waiting'], ['staff']);
$identity('cfoperator', [], ['staff', 'dashboard'], [$roleIds['production-documents-operator']]);
$ok($asRoot('PUT', "/management/employees/{$uuids['cfoperator']}/document-scopes", ['operate' => ['outletperdele', 'trendhome'], 'approve' => []]), 'operator document scope');
$cutter = T::login($kernel, "cfcutter.{$suffix}", $password);
$waiter = T::login($kernel, "cfwaiter.{$suffix}", $password);
$operator = T::login($kernel, "cfoperator.{$suffix}", $password);
$staff = static fn (array $who, string $method, string $path, ?array $body = null, ?object $k = null): array => T::call($k ?? $kernel, $method, $path, $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => 'cf-' . bin2hex(random_bytes(10))], $who['cookie']);

// ---- Fixtures ----------------------------------------------------------------------------------------------------
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$tick = 0;
$payload = static function (string $id, string $status, ?array $submission, ?array $payment, array $extra = []) use ($suffix, &$tick): array {
    $tick++;
    $p = T::sourceOrder($id, $extra['event'] ?? "cf-{$id}-{$suffix}-{$tick}", $extra['changedAt'] ?? gmdate('Y-m-d\TH:i:s', time() - 5000 + $tick) . '.' . sprintf('%06d', $tick) . 'Z', null, $status, $extra['availability'] ?? 'active', $extra['items'] ?? [[
        'id' => 7001, 'line' => 1, 'name' => 'Perdea Voal Alb', 'sku' => 'PV-ALB', 'color' => 'Alb', 'width' => 3, 'height' => 2.75, 'unit' => 'm', 'quantity' => 1,
        'options' => [['label' => 'buc', 'value' => '2 buc.']],
    ]]);
    unset($p['order']['notes']);
    if ($submission !== null) $p['order']['submission'] = $submission;
    if ($payment !== null) $p['order']['payment'] = $payment;
    return $p;
};
$submitted = static fn (DateTimeImmutable $at, string $kind = 'store_api'): array => ['submittedAt' => $iso($at), 'evidence' => $kind];
$cod = static fn (string $status, int $revision = 0, ?string $at = null): array => ['method' => 'cod', 'advanceStatus' => $status, 'advanceRevision' => $revision] + ($at === null ? [] : ['advanceConfirmedAt' => $at]);
$online = ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0];
$unverified = ['method' => 'unverified', 'advanceStatus' => 'waiting', 'advanceRevision' => 0];
$send = static fn (string $source, array $p, ?object $k = null): array => T::ingest($k ?? $kernel, $source, $p);
$order = static fn (string $globalId): array => $row(
    'SELECT o.order_uuid, o.workflow_cohort, o.workflow_cohort_reason, o.workflow_cohort_at, o.source_submitted_at, o.source_submission_evidence, o.production_stage_id, o.production_authority,
            o.production_owner_employee_uuid, o.production_version, o.version, o.document_status, o.operational_status, o.source_commerce_status_code, o.payment_method, o.advance_status, c.status AS confirmation, c.version AS confirmation_version
     FROM operational_orders o LEFT JOIN order_confirmations c ON c.order_uuid = o.order_uuid WHERE o.global_order_id = ?', [$globalId]);
$count = static fn (string $globalId): int => (int) $one('SELECT COUNT(*) FROM operational_orders WHERE global_order_id = ?', [$globalId]);
$qr = static fn (string $globalId): string => 'ARASYA:Q1:' . (string) $one("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? AND q.status = 'active'", [$globalId]);
$revisions = static fn (string $globalId): int => (int) $one('SELECT COUNT(*) FROM production_document_revisions r JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE o.global_order_id = ?', [$globalId]);
$sourcePrint = static fn (string $source, string $id, ?object $k = null): array => T::ingest($k ?? $kernel, $source, ['orderId' => $id, 'issue' => true, 'revisionNumber' => null, 'actor' => ['id' => 7, 'name' => 'Operator Magazin']], null, null, 'orders/document');
$sourceStates = static fn (string $source, array $ids): array => $ok(T::ingest($kernel, $source, ['orderIds' => $ids], null, null, 'orders/documents'), "{$source} document states");
$generate = static fn (string $globalId): array => $staff($operator, 'POST', '/production-documents/orders/' . rawurlencode($globalId) . '/generate', ['expectedDocumentVersion' => 0]);
$intake = static function (string $globalId) use ($staff, $cutter, $qr, $one, &$uuids): array {
    $owned = (int) $one("SELECT COUNT(*) FROM operational_orders WHERE production_owner_employee_uuid = ? AND production_stage_id = 'material-preparation' AND production_completed_at IS NULL", [$uuids['cfcutter']]);
    return $staff($cutter, 'POST', '/cutting/intake', ['qrToken' => $qr($globalId)] + ($owned > 0 ? ['confirmedMultiple' => true, 'ownedCount' => $owned] : []));
};
$claim = static fn (string $globalId): array => $staff($waiter, 'POST', '/orders/' . rawurlencode($globalId) . '/claim', ['expectedVersion' => (int) $one('SELECT production_version FROM operational_orders WHERE global_order_id = ?', [$globalId])]);
$assign = static fn (string $globalId): array => $asRoot('PUT', '/management/orders/' . rawurlencode($globalId) . '/owner', ['employeeId' => $uuids['cfwaiter'], 'expectedVersion' => (int) $one('SELECT production_version FROM operational_orders WHERE global_order_id = ?', [$globalId])]);
$heartbeat = static fn (string $source, ?object $k = null): array => $ok(T::ingest($k ?? $kernel, $source, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')], null, null, 'heartbeat'), "{$source} heartbeat");
$approve = static function (string $globalId) use ($pdo, $uuids): void {
    // Phase 2 will record approvals through the Confirmare workflow; the test writes the stored decision directly to prove
    // that this decision, and nothing else, opens the gate.
    $pdo->prepare("UPDATE order_confirmations c JOIN operational_orders o ON o.order_uuid = c.order_uuid SET c.status = 'approved', c.decided_at = UTC_TIMESTAMP(6), c.decided_by_employee_uuid = ?, c.version = c.version + 1, c.updated_at = UTC_TIMESTAMP(6) WHERE o.global_order_id = ?")->execute([$uuids['cfoperator'], $globalId]);
};
$activation = static fn (DateTimeImmutable $at): WorkflowCohortActivation => new WorkflowCohortActivation($pdo, new MutableClock($at));
$base = 640000 + random_int(0, 8999) * 10;
$id = static fn (int $n): string => (string) ($base + $n);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
// Every instant is anchored on the activation instant A (never before the business date, at most two hours ago) and
// stays at or before now, so the suite gives the same answers whenever it runs after 2026-10-22 00:00 Bucharest.
$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    fwrite(STDOUT, "SKIP MySQL Confirmare cohort integration: needs a clock at least 10 minutes after 2026-10-22 00:00 Bucharest.\n");
    exit(0);
}
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
$window = $now->getTimestamp() - $activatedAt->getTimestamp();
$at = static fn (float $seconds): DateTimeImmutable => $activatedAt->modify(sprintf('%+d seconds', (int) round($seconds)));

// ---- Before any activation: nothing new is classified into Confirmare --------------------------------------------
$check($heartbeat('trendhome')['confirmare'] === ['state' => 'inactive', 'activatedAt' => null] && $heartbeat('outletperdele')['confirmare']['state'] === 'inactive', 'heartbeat: no source is active');
$receipts = (int) $one('SELECT COUNT(*) FROM order_projection_receipts');
$error($send('trendhome', $payload($id(1), 'checkout-draft', null, null)), 422, 'SOURCE_ORDER_DRAFT', 'a checkout draft is never a customer order');
$error($send('outletperdele', $payload($id(1), 'auto-draft', null, null)), 422, 'SOURCE_ORDER_DRAFT', 'an admin draft is never a customer order');
$check($count("trendhome:{$id(1)}") === 0 && $count("outletperdele:{$id(1)}") === 0 && (int) $one('SELECT COUNT(*) FROM order_projection_receipts') === $receipts, 'refused intake stores nothing, not even a receipt');
// Inert until activated: an unpaid order (which YD SOFT sends only on a manual request) is received exactly as before.
$check($ok($send('outletperdele', $payload($id(3), 'pending', $submitted($at(30)), $cod('waiting'))), 'unpaid order before activation')['outcome'] === 'applied'
    && $order("outletperdele:{$id(3)}")['workflow_cohort'] === 'legacy' && $order("outletperdele:{$id(3)}")['confirmation'] === null, 'before activation an unpaid order keeps the earlier behaviour (legacy, no Confirmare)');
$preId = "trendhome:{$id(2)}";
$check($ok($send('trendhome', $payload($id(2), 'processing', $submitted($at(-3 * 3600)), $online)), 'pre-activation processing order')['outcome'] === 'applied', 'the established flow keeps receiving processing orders');
$pre = $order($preId);
$check($pre['workflow_cohort'] === 'legacy' && $pre['workflow_cohort_reason'] === 'not_activated' && $pre['confirmation'] === null && $pre['production_authority'] === 'operations', 'created before activation: legacy, no Confirmare row: ' . json_encode($pre));
$check($ok($intake($preId), 'pre-activation order enters cutting')['outcome'] === 'started' && $order($preId)['production_stage_id'] === 'material-preparation', 'a legacy order starts production exactly as before');
$preQr = $qr($preId);
$preVersion = (int) $order($preId)['production_version'];

// ---- Activation is refused in Phase 1 and without its prerequisites ------------------------------------------------
$apiError(fn () => $activation($activatedAt)->activate($registry->find('trendhome'), 'Test Owner', null, 'cf-test'), 'CONFIRMARE_WORKFLOW_NOT_READY', 'Phase 1: the Confirmare workflow is not ready, so no source can be activated');
$apiError(fn () => $activation($activatedAt)->activate($registry->find('outletperdele'), 'Test Owner', null, 'cf-test', true), 'CONFIRMARE_NOT_PERMITTED', 'a source without ARASYA_SOURCE_CONFIRMARE=on cannot be activated');
// API 2.38.0: nobody may confirm the source's orders yet, so an activation would stop them all.
$apiError(fn () => $activation($activatedAt)->activate($registry->find('trendhome'), 'Test Owner', null, 'cf-test', true), 'CONFIRMARE_NO_OPERATOR', 'no activation before somebody can confirm the source\'s orders');
$ok($asRoot('PUT', "/management/employees/{$uuids['cfoperator']}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]), 'a Confirmare operator for trendhome');
$apiError(fn () => $activation(new DateTimeImmutable('2026-10-21T20:59:59.999999Z'))->activate($registry->find('trendhome'), 'Test Owner', null, 'cf-test', true), 'CONFIRMARE_BEFORE_CUTOVER', 'no activation before 2026-10-22 00:00 Bucharest');
$apiError(fn () => $activation($activatedAt)->activate($registry->find('trendhome'), '', null, 'cf-test', true), 'ACTOR_REQUIRED', 'an activation names the person who records it');
$apiError(fn () => $activation($activatedAt)->suspend('trendhome', 'Test Owner', 'nothing to suspend', 'cf-test'), 'CONFIRMARE_NOT_ACTIVE', 'nothing to suspend');
$check((int) $one('SELECT COUNT(*) FROM workflow_cohort_activations') === 0, 'refused activations record nothing');
$error(T::ingest($kernel, 'trendhome', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmare' => 'active'], null, null, 'heartbeat'), 422, 'SOURCE_PAYLOAD_INVALID', 'a source can never announce an activation itself');

// ---- Trend Home is activated (test harness: workflow ready); Outlet Perdele is not -----------------------------------
$activated = $activation($activatedAt)->activate($registry->find('trendhome'), 'Test Owner', 'Phase 1 integration', 'cf-test', true);
$check($activated['effectiveAt'] === $iso($activatedAt), 'the activation instant is the recorded server clock: ' . json_encode($activated));
$apiError(fn () => $activation($at(1))->activate($registry->find('trendhome'), 'Test Owner', null, 'cf-test', true), 'CONFIRMARE_ALREADY_ACTIVE', 'an active source is not activated twice');
$check($heartbeat('trendhome')['confirmare'] === ['state' => 'active', 'activatedAt' => $iso($activatedAt)] && $heartbeat('outletperdele')['confirmare']['state'] === 'inactive', 'heartbeat: only Trend Home is active, with its instant');

// The three instants around the activation (microsecond precision).
$boundary = [
    $id(10) => [$activatedAt->modify('-1 second'), 'legacy', 'submitted_before_activation'],
    $id(11) => [$activatedAt->modify('-1 microsecond'), 'legacy', 'submitted_before_activation'],
    $id(12) => [$activatedAt, 'confirmare', 'submitted_after_activation'],
    $id(13) => [$activatedAt->modify('+1 second'), 'confirmare', 'submitted_after_activation'],
];
foreach ($boundary as $orderId => [$instant, $cohort, $reason]) {
    $orderId = (string) $orderId;
    $ok($send('trendhome', $payload($orderId, 'processing', $submitted($instant, 'checkout'), $online)), "boundary order {$orderId}");
    $stored = $order("trendhome:{$orderId}");
    $check($stored['workflow_cohort'] === $cohort && $stored['workflow_cohort_reason'] === $reason && $stored['source_submission_evidence'] === 'checkout'
        && (new DateTimeImmutable($stored['source_submitted_at'] . 'Z')) == $instant, "{$iso($instant)} is {$cohort}: " . json_encode($stored));
    $check(($stored['confirmation'] === 'pending') === ($cohort === 'confirmare'), "a Confirmare row exists only for the Confirmare order {$orderId}");
}

$check($ok($intake("trendhome:{$id(10)}"), 'boundary legacy order intake')['outcome'] === 'started', 'the order submitted one second before the activation keeps the established flow');

// The same numeric WooCommerce id on both sources: two orders, each classified by its own source.
$twin = $id(20);
$twinAt = $at(60);
$ok($send('trendhome', $payload($twin, 'pending', $submitted($twinAt), $cod('waiting'))), 'Trend Home unpaid order after activation');
$ok($send('outletperdele', $payload($twin, 'processing', $submitted($twinAt), $cod('waiting'))), 'Outlet Perdele processing order with the same id');
$home = $order("trendhome:{$twin}");
$outlet = $order("outletperdele:{$twin}");
$check($home['order_uuid'] !== $outlet['order_uuid'] && $home['workflow_cohort'] === 'confirmare' && $home['confirmation'] === 'pending' && $outlet['workflow_cohort'] === 'legacy' && $outlet['workflow_cohort_reason'] === 'not_activated' && $outlet['confirmation'] === null, 'source-scoped identity: ' . json_encode([$home, $outlet]));
$check($home['production_stage_id'] === 'waiting' && $home['production_authority'] === 'operations' && $home['document_status'] === 'none' && $home['source_commerce_status_code'] === 'pending', 'the unpaid Confirmare order is canonical at stage 1, Arasya-managed, without a document');
$check(str_starts_with($qr("trendhome:{$twin}"), 'ARASYA:Q1:'), 'it keeps the intake QR every canonical order receives (the QR alone starts nothing)');
$check($ok($intake("outletperdele:{$twin}"), 'Outlet order intake')['outcome'] === 'started', 'the inactive source\'s order keeps its established flow (payment gate off there)');

// ---- Every production start is closed for the unconfirmed order -------------------------------------------------
$cfId = "trendhome:{$twin}";
$state = $sourceStates('trendhome', [$twin])['orders'][0];
$check($state['issueAllowed'] === false && $state['documentStatus'] === 'none', 'the source may not issue the ticket: ' . json_encode($state));
$refused = $error($sourcePrint('trendhome', $twin), 409, 'CONFIRMARE_NECESARA', 'the signed source cannot print revision 1');
$check(($refused['error']['details']['workflowCohort'] ?? null) === 'confirmare' && str_contains($refused['error']['message'] ?? '', 'confirmată'), 'the refusal is Romanian and machine-readable: ' . json_encode($refused));
$error($generate($cfId), 409, 'CONFIRMARE_NECESARA', 'a document operator cannot generate revision 1');
$error($intake($cfId), 409, 'CONFIRMARE_NECESARA', 'the cutting QR intake cannot bypass the confirmation');
$error($claim($cfId), 409, 'CONFIRMARE_NECESARA', 'a stage-1 employee cannot claim it');
$error($assign($cfId), 409, 'CONFIRMARE_NECESARA', 'root cannot assign it either');
$view = $ok($staff($waiter, 'GET', '/orders/' . rawurlencode($cfId)), 'stage-1 employee reads the order');
$check(($view['employeeActionBlockedReason'] ?? null) === 'confirmation_required' && ($view['employeeAllowedAction'] ?? null) === null, 'Staff sees why: ' . json_encode([$view['employeeActionBlockedReason'] ?? null]));
$after = $order($cfId);
$check($after['production_stage_id'] === 'waiting' && $after['production_owner_employee_uuid'] === null && (int) $after['production_version'] === (int) $home['production_version'] && $revisions($cfId) === 0, 'stage, owner, production version and documents are unchanged');

// Payment received does not replace the confirmation: the advance is confirmed, the order is processing, still closed.
$ok($send('trendhome', $payload($twin, 'processing', $submitted($twinAt), $cod('confirmed', 1, gmdate('Y-m-d\TH:i:s\Z', time() - 60)))), 'the advance is confirmed later');
$paid = $order($cfId);
$check($paid['advance_status'] === 'confirmed' && $paid['source_commerce_status_code'] === 'processing' && $paid['workflow_cohort'] === 'confirmare' && $paid['confirmation'] === 'pending', 'payment never changes the cohort or the confirmation');
$error($sourcePrint('trendhome', $twin), 409, 'CONFIRMARE_NECESARA', 'a paid but unconfirmed order still cannot start');
$error($intake($cfId), 409, 'CONFIRMARE_NECESARA', 'nor through the cutting intake');

// ---- Repeated updates, replay, out-of-order and changed evidence never reclassify --------------------------------
$fixed = $payload($twin, 'processing', $submitted($twinAt), $cod('confirmed', 1, gmdate('Y-m-d\TH:i:s\Z', time() - 60)), ['event' => "cf-replay-{$twin}-{$suffix}", 'items' => [[
    'id' => 7001, 'line' => 1, 'name' => 'Perdea Voal Alb', 'sku' => 'PV-ALB', 'color' => 'Alb', 'width' => 3.5, 'height' => 2.75, 'unit' => 'm', 'quantity' => 2,
], ['id' => 7002, 'line' => 2, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'variant' => 'Culoare: Bej', 'color' => 'Bej', 'width' => 2, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1]]]);
$check($ok($send('trendhome', $fixed), 'item change')['outcome'] === 'applied', 'mixed simple and variable items change while the order waits');
$check((int) $one('SELECT COUNT(*) FROM operational_order_items i JOIN operational_orders o ON o.order_uuid = i.order_uuid WHERE o.global_order_id = ?', [$cfId]) === 2 && $order($cfId)['workflow_cohort'] === 'confirmare', 'the changed items are stored, the cohort stays');
$check($ok($send('trendhome', $fixed), 'replayed item change')['outcome'] === 'duplicate', 'a replay is a duplicate');
$late = $payload($twin, 'pending', $submitted($twinAt), $cod('waiting'), ['changedAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
$check($ok($send('trendhome', $late), 'late older event')['outcome'] === 'out_of_order', 'an older event is out of order');
$moved = $payload($twin, 'processing', $submitted($activatedAt->modify('-1 day'), 'observed'), $cod('confirmed', 1, gmdate('Y-m-d\TH:i:s\Z', time() - 60)));
$check($ok($send('trendhome', $moved), 'event with other evidence')['outcome'] === 'applied', 'a later event stating other evidence is applied as an update');
$updated = $order($cfId);
$check($updated['workflow_cohort'] === 'confirmare' && $updated['workflow_cohort_at'] === $home['workflow_cohort_at'] && $updated['source_submitted_at'] === $home['source_submitted_at'] && $updated['source_submission_evidence'] === 'store_api'
    && $updated['confirmation'] === 'pending' && (int) $updated['confirmation_version'] === 1 && (int) $updated['version'] > (int) $home['version'] && $updated['production_stage_id'] === 'waiting', 'cohort, evidence and confirmation are decided once: ' . json_encode($updated));
$check((int) $one('SELECT COUNT(*) FROM operational_order_items i JOIN operational_orders o ON o.order_uuid = i.order_uuid WHERE o.global_order_id = ?', [$cfId]) === 1, 'the newest snapshot\'s items are kept');

// ---- Missing, future and inconsistent evidence fail closed --------------------------------------------------------
$ok($send('trendhome', $payload($id(30), 'processing', null, $online)), 'processing order without evidence (older source build)');
$missing = $order("trendhome:{$id(30)}");
$check($missing['workflow_cohort'] === 'undetermined' && $missing['workflow_cohort_reason'] === 'submission_evidence_missing' && $missing['confirmation'] === 'pending', 'no evidence while active: undetermined, never guessed');
$undetermined = $error($sourcePrint('trendhome', $id(30)), 409, 'CONFIRMARE_NECESARA', 'an undetermined order cannot start');
$check(($undetermined['error']['details']['workflowCohort'] ?? null) === 'undetermined' && str_contains($undetermined['error']['message'] ?? '', 'verificare'), 'the refusal says it needs a review');
$approve("trendhome:{$id(30)}");
$error($intake("trendhome:{$id(30)}"), 409, 'CONFIRMARE_NECESARA', 'even an approved undetermined order stays closed until its cohort is resolved');
$error($send('trendhome', $payload($id(31), 'pending', null, $cod('waiting'))), 409, 'SOURCE_INTAKE_NOT_ELIGIBLE', 'an unpaid order without evidence is not received');
$ok($send('trendhome', $payload($id(32), 'processing', $submitted($now->modify('+1 hour')), $online)), 'evidence from the future');
$check($order("trendhome:{$id(32)}")['workflow_cohort_reason'] === 'submission_evidence_future', 'a source clock far ahead is not trusted');
$ok($send('trendhome', $payload($id(33), 'processing', $submitted($at(30), 'predates_recorder'), $online)), 'a creation instant after the activation');
$check($order("trendhome:{$id(33)}")['workflow_cohort_reason'] === 'submission_evidence_inconsistent', 'a creation-time stand-in after the activation is inconsistent');
$ok($send('trendhome', $payload($id(34), 'processing', $submitted($activatedAt->modify('-3 days'), 'predates_recorder'), $online)), 'an old order first ingested after the activation');
$check($order("trendhome:{$id(34)}")['workflow_cohort'] === 'legacy' && $ok($intake("trendhome:{$id(34)}"), 'old order intake')['outcome'] === 'started', 'an order submitted before the activation and paid later stays legacy and keeps its flow');
$error($send('trendhome', $payload($id(35), 'pending', $submitted($activatedAt->modify('-1 hour')), $cod('waiting'))), 409, 'SOURCE_INTAKE_NOT_ELIGIBLE', 'an older unpaid order is never pulled into Confirmare');

// ---- Payment states of new orders: intake never needs a payment ----------------------------------------------------
foreach ([
    $id(40) => ['pending', $cod('waiting'), 'COD, advance pending'],
    $id(41) => ['processing', $cod('partial', 1), 'COD, advance partially received'],
    $id(42) => ['processing', $cod('confirmed', 1, gmdate('Y-m-d\TH:i:s\Z', time() - 120)), 'COD, advance fully received'],
    $id(43) => ['pending', $unverified, 'NETOPIA payment pending'],
    $id(44) => ['processing', $online, 'NETOPIA captured'],
    $id(45) => ['on-hold', $unverified, 'NETOPIA partial / on hold'],
] as $orderId => [$status, $payment, $label]) {
    $orderId = (string) $orderId;
    $check($ok($send('trendhome', $payload($orderId, $status, $submitted($at(120)), $payment)), $label)['outcome'] === 'applied', "{$label}: received");
    $stored = $order("trendhome:{$orderId}");
    $check($stored['workflow_cohort'] === 'confirmare' && $stored['confirmation'] === 'pending' && $stored['production_stage_id'] === 'waiting', "{$label}: waits for Confirmare");
    $error($intake("trendhome:{$orderId}"), 409, 'CONFIRMARE_NECESARA', "{$label}: cannot start");
}
$check($sourceStates('trendhome', [$id(44)])['orders'][0]['issueAllowed'] === false, 'a paid (online) but unconfirmed order: the source may not issue its ticket');
// A failed payment retried: failed is reported as unavailable, the retry makes it available again; cohort unchanged.
$ok($send('trendhome', $payload($id(43), 'failed', $submitted($at(120)), $unverified, ['availability' => 'cancelled'])), 'payment failed');
$check($order("trendhome:{$id(43)}")['operational_status'] === 'unavailable', 'a failed payment makes the order unavailable');
$ok($send('trendhome', $payload($id(43), 'pending', $submitted($at(120)), $unverified)), 'payment retried');
$retried = $order("trendhome:{$id(43)}");
$check($retried['operational_status'] === 'in_progress' && $retried['workflow_cohort'] === 'confirmare' && $retried['confirmation'] === 'pending', 'the retry keeps the same order, cohort and confirmation');
// Cancellation before confirmation.
$ok($send('trendhome', $payload($id(40), 'cancelled', $submitted($at(120)), $cod('waiting'), ['availability' => 'cancelled'])), 'cancelled before confirmation');
$cancelled = $order("trendhome:{$id(40)}");
$check($cancelled['operational_status'] === 'unavailable' && $cancelled['production_stage_id'] === 'waiting' && $cancelled['confirmation'] === 'pending' && $revisions("trendhome:{$id(40)}") === 0, 'a cancellation before confirmation changes nothing else');

// ---- Approval (the stored decision) is the only thing that opens the gate ------------------------------------------
$approve($cfId);
$check($sourceStates('trendhome', [$twin])['orders'][0]['issueAllowed'] === true, 'after the decision and the confirmed advance the source may issue');
$check($ok($sourcePrint('trendhome', $twin), 'approved order prints')['document']['issued'] === true && $revisions($cfId) === 1, 'exactly one revision 1');
$check($ok($intake($cfId), 'approved order enters cutting')['outcome'] === 'started' && $order($cfId)['production_stage_id'] === 'material-preparation', 'production starts once');
$approve("trendhome:{$id(41)}");
$error($intake("trendhome:{$id(41)}"), 409, 'AVANS_NEVALIDAT', 'an approved order still respects the advance payment gate');
$approve("trendhome:{$id(44)}");
$ok($claim("trendhome:{$id(44)}"), 'an approved, paid order can be claimed at stage 1');

// ---- Manager takeover cannot skip the confirmation (an order created under observe keeps source authority) -------
$ok($send('trendhome', $payload($id(50), 'processing', $submitted($at(180)), $online), $observeKernel), 'Confirmare order created while production authority is observed');
$source = $order("trendhome:{$id(50)}");
$check($source['workflow_cohort'] === 'confirmare' && $source['production_authority'] === 'source', 'the cohort never depends on the authority mode');
$error($asRoot('POST', '/orders/' . rawurlencode("trendhome:{$id(50)}") . '/production-authority/takeover', ['expectedVersion' => (int) $source['production_version'], 'stageId' => 'material-preparation'], $observeKernel), 409, 'CONFIRMARE_NECESARA', 'a takeover to a later stage is refused');
$taken = $ok($asRoot('POST', '/orders/' . rawurlencode("trendhome:{$id(50)}") . '/production-authority/takeover', ['expectedVersion' => (int) $source['production_version'], 'stageId' => 'waiting'], $observeKernel), 'a takeover at stage 1 is allowed');
$check($order("trendhome:{$id(50)}")['production_authority'] === 'operations' && $order("trendhome:{$id(50)}")['production_stage_id'] === 'waiting' && $taken !== [], 'it only moves the authority');

// ---- Permission switched off after the activation: classification continues (no silent fallback) -----------------
$ok($send('trendhome', $payload($id(60), 'processing', $submitted($at(200)), $online), $offKernel), 'order received while ARASYA_SOURCE_CONFIRMARE is off');
$check($order("trendhome:{$id(60)}")['workflow_cohort'] === 'confirmare' && $heartbeat('trendhome', $offKernel)['confirmare']['state'] === 'active', 'the recorded activation, not the switch, decides');
$error($intake("trendhome:{$id(60)}"), 409, 'CONFIRMARE_NECESARA', 'and its production stays closed');

// ---- Orders that existed before the activation keep everything ---------------------------------------------------
$preNow = $order($preId);
$ok($send('trendhome', $payload($id(2), 'processing', $submitted($at(-3 * 3600)), $online, ['items' => [['id' => 7001, 'line' => 1, 'name' => 'Perdea Voal Alb', 'sku' => 'PV-ALB', 'color' => 'Alb', 'width' => 3, 'height' => 2.75, 'unit' => 'm', 'quantity' => 1, 'options' => [['label' => 'buc', 'value' => '2 buc.']]]]])), 'pre-activation order update');
$preNow = $order($preId);
$check($preNow['workflow_cohort'] === 'legacy' && $preNow['production_stage_id'] === 'material-preparation' && (int) $preNow['production_version'] === $preVersion && $qr($preId) === $preQr && $preNow['confirmation'] === null, 'an Arasya-managed older order keeps its true stage, version and QR');
$pdo->prepare('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE global_order_id = ?')->execute(["trendhome:{$id(42)}"]);
$check($order("trendhome:{$id(42)}")['workflow_cohort'] === null, 'fixture: an order created before API 2.37.0 has no cohort');
$pdo->prepare('DELETE FROM order_confirmations WHERE global_order_id = ?')->execute(["trendhome:{$id(42)}"]);
$ok($generate("trendhome:{$id(42)}"), 'a pre-cohort order generates its document', 201);
$check($revisions("trendhome:{$id(42)}") === 1 && $ok($intake("trendhome:{$id(42)}"), 'pre-cohort intake')['outcome'] === 'started', 'a pre-cohort order is never stopped by the gate');

// ---- Suspension: new orders legacy again; existing Confirmare orders stay closed ---------------------------------
$suspendedAt = $at($window * 0.5);
$activation($suspendedAt)->suspend('trendhome', 'Test Owner', 'integration test suspension', 'cf-test');
$check($heartbeat('trendhome')['confirmare'] === ['state' => 'suspended', 'activatedAt' => null], 'heartbeat: suspended');
$ok($send('trendhome', $payload($id(70), 'processing', $submitted($suspendedAt->modify('+10 seconds')), $online)), 'order while suspended');
$check($order("trendhome:{$id(70)}")['workflow_cohort'] === 'legacy' && $order("trendhome:{$id(70)}")['workflow_cohort_reason'] === 'suspended', 'a suspension is recorded per order');
$ok($send('trendhome', $payload($id(71), 'pending', $submitted($suspendedAt->modify('+10 seconds')), $cod('waiting'))), 'unpaid order while suspended');
$check($order("trendhome:{$id(71)}")['workflow_cohort'] === 'legacy' && $order("trendhome:{$id(71)}")['confirmation'] === null, 'a suspended source keeps the earlier behaviour, never a Confirmare row');
$error($intake("trendhome:{$id(60)}"), 409, 'CONFIRMARE_NECESARA', 'a suspension never opens an existing Confirmare order');
$check((int) $one('SELECT COUNT(*) FROM workflow_cohort_activations WHERE source_key = ?', ['trendhome']) === 2, 'the history is append-only');
$reactivatedAt = $at($window * 0.75);
$activation($reactivatedAt)->activate($registry->find('trendhome'), 'Test Owner', 'reactivated', 'cf-test', true);
$ok($send('trendhome', $payload($id(72), 'processing', $submitted($reactivatedAt->modify('-10 seconds')), $online)), 'order between suspension and reactivation');
$ok($send('trendhome', $payload($id(73), 'processing', $submitted($reactivatedAt->modify('+10 seconds')), $online)), 'order after reactivation');
$check($order("trendhome:{$id(72)}")['workflow_cohort'] === 'legacy' && $order("trendhome:{$id(73)}")['workflow_cohort'] === 'confirmare', 'the latest activation instant decides');

// ---- Concurrency: duplicate deliveries in parallel, and an ingestion racing an activation ------------------------
$parallel = static function (array $jobs) use ($dbName): array {
    if (!function_exists('pcntl_fork')) return [];
    $files = []; $children = [];
    foreach ($jobs as $index => $job) {
        $files[$index] = tempnam(sys_get_temp_dir(), 'cohort');
        $pid = pcntl_fork();
        if ($pid === 0) {
            $result = $job($dbName);
            file_put_contents($files[$index], json_encode($result));
            posix_kill(posix_getpid(), SIGKILL);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) pcntl_waitpid($pid, $status);
    $results = [];
    foreach ($files as $index => $file) { $results[$index] = json_decode((string) file_get_contents($file), true); unlink($file); }
    return $results;
};
$same = $payload($id(80), 'pending', $submitted($reactivatedAt->modify('+20 seconds')), $cod('waiting'), ['event' => "cf-parallel-{$id(80)}-{$suffix}"]);
$ingestJob = static fn (array $p, int $delay = 0): Closure => static function (string $db) use ($p, $delay, $settings): array {
    usleep(20000 + $delay);
    $k = (new Container(T::config($db, [T::ORIGIN, DASHBOARD_ORIGIN], $settings('on', 'off'))))->kernel();
    $r = T::ingest($k, 'trendhome', $p);
    return ['status' => $r['status'], 'body' => $r['body']];
};
$results = $parallel([$ingestJob($same), $ingestJob($same), $ingestJob($same)]);
if ($results === []) {
    echo "NOT RUN parallel deliveries: pcntl is not available\n";
} else {
    $outcomes = array_map(static fn (array $r): string => $r['status'] === 200 ? (string) $r['body']['outcome'] : (string) ($r['body']['error']['code'] ?? $r['status']), $results);
    sort($outcomes);
    // Pre-existing behaviour (unchanged on main): simultaneous first deliveries of one new event may lose to an InnoDB
    // deadlock (HTTP 500, which YD SOFT retries with back-off). Exactly one wins; nothing is half-written.
    $check(count(array_filter($outcomes, static fn (string $o): bool => $o === 'applied')) === 1 && array_diff($outcomes, ['applied', 'duplicate', 'INTERNAL_ERROR']) === [], 'parallel duplicate deliveries: exactly one applied: ' . json_encode($outcomes));
    $check($count("trendhome:{$id(80)}") === 1 && (int) $one('SELECT COUNT(*) FROM order_confirmations WHERE global_order_id = ?', ["trendhome:{$id(80)}"]) === 1, 'exactly one order and one Confirmare row');
    $check($ok($send('trendhome', $same), 'the retry of a losing delivery')['outcome'] === 'duplicate' && $count("trendhome:{$id(80)}") === 1, 'the retry is a duplicate');

    // Activation racing an ingestion: the activation holds its source lock while the ingestion of an order submitted
    // after the activation instant arrives; the ingestion waits and then sees the activation. Outlet Perdele is used
    // (permitted for this race only).
    $lockHolder = Connection::create($config);
    $lockHolder->beginTransaction();
    $lockHolder->query("SELECT source_key FROM order_sources WHERE source_key = 'outletperdele' FOR UPDATE")->fetchAll();
    $raceAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $racer = $payload($id(81), 'processing', $submitted($raceAt), null);
    $raceJob = static function (string $db) use ($racer, $settings): array {
        $k = (new Container(T::config($db, [T::ORIGIN, DASHBOARD_ORIGIN], $settings('on', 'on'))))->kernel();
        $started = microtime(true);
        $r = T::ingest($k, 'outletperdele', $racer);
        return ['status' => $r['status'], 'body' => $r['body'], 'waited' => microtime(true) - $started];
    };
    $pid = pcntl_fork();
    if ($pid === 0) {
        file_put_contents(sys_get_temp_dir() . "/cohort-race-{$suffix}", json_encode($raceJob($dbName)));
        posix_kill(posix_getpid(), SIGKILL);
    }
    usleep(400000);
    $check($count("outletperdele:{$id(81)}") === 0, 'the ingestion waits for the source lock');
    $lockHolder->prepare("INSERT INTO workflow_cohort_activations (event_uuid, source_key, action, effective_at, actor_label, reason, request_id, recorded_at) VALUES (?, 'outletperdele', 'activated', ?, 'Race', NULL, 'race', ?)")
        ->execute([\Arasya\Operations\Support\Uuid::v4(), $raceAt->format('Y-m-d H:i:s.u'), $raceAt->format('Y-m-d H:i:s.u')]);
    $lockHolder->commit();
    pcntl_waitpid($pid, $status);
    $race = json_decode((string) file_get_contents(sys_get_temp_dir() . "/cohort-race-{$suffix}"), true);
    unlink(sys_get_temp_dir() . "/cohort-race-{$suffix}");
    $check($race['status'] === 200 && $race['waited'] >= 0.3, 'the racing ingestion completed after the activation committed: ' . json_encode($race));
    $raced = $order("outletperdele:{$id(81)}");
    $check($raced['workflow_cohort'] === 'confirmare' && $raced['workflow_cohort_reason'] === 'submitted_after_activation' && $raced['confirmation'] === 'pending', 'it was classified against the committed activation: ' . json_encode($raced));
}

// ---- The migration is resumable and the diagnosis is read-only ----------------------------------------------------
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/035_workflow_cohort.sql');
$check((int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'operational_orders' AND column_name LIKE 'workflow_cohort%'") === 3, 'a repeated 035 run changes nothing');
// A conflict only an older API or a direct change could create: a Confirmare order past stage 1 without approval.
$pdo->prepare("UPDATE operational_orders SET production_stage_id = 'material-preparation' WHERE global_order_id = ?")->execute(["trendhome:{$id(73)}"]);
$checksum = static fn (): string => json_encode($pdo->query('CHECKSUM TABLE operational_orders, order_confirmations, workflow_cohort_activations, order_projection_receipts, production_document_revisions, order_qr_references')->fetchAll(PDO::FETCH_ASSOC));
$beforeDiagnosis = $checksum();
$diagnosis = (new WorkflowCohortActivation($pdo, $container->clock()))->diagnose('trendhome', $registry->find('trendhome'));
$check($checksum() === $beforeDiagnosis, 'the diagnosis writes nothing');
$listed = static fn (array $entries): array => array_column($entries, 'globalOrderId');
$check($diagnosis['state'] === 'active' && $diagnosis['activatedAt'] === $iso($reactivatedAt) && count($diagnosis['history']) === 3 && $diagnosis['workflowReady'] === false, 'state and history: ' . json_encode(array_intersect_key($diagnosis, array_flip(['state', 'activatedAt', 'history']))));
$check(in_array("trendhome:{$id(73)}", $listed($diagnosis['conflicts']), true), 'a Confirmare order with production history is a conflict for an operator');
$check(in_array("trendhome:{$id(30)}", $listed($diagnosis['undetermined']), true) && in_array("trendhome:{$id(32)}", $listed($diagnosis['undetermined']), true), 'undetermined orders are listed');
$check(in_array("trendhome:{$id(10)}", $listed($diagnosis['transitionWindow']['withProductionHistory']), true) && !in_array("trendhome:{$id(34)}", array_merge($listed($diagnosis['transitionWindow']['withProductionHistory']), $listed($diagnosis['transitionWindow']['withoutProductionHistory'])), true), 'a legacy order submitted after the business date and already in production is reported; one submitted before it is not');
$check(in_array("trendhome:{$id(70)}", $listed($diagnosis['transitionWindow']['withoutProductionHistory']), true), 'a legacy order received after the business date without production history is listed for review');
$check(!str_contains(json_encode($diagnosis), 'Perdea') && !str_contains(json_encode($diagnosis), 'phoneMasked'), 'the diagnosis carries no product or customer data');
$check($diagnosis['awaitingConfirmation'] >= 5 && $diagnosis['ordersByCohort']['confirmare'] >= 10, 'counts: ' . json_encode([$diagnosis['awaitingConfirmation'], $diagnosis['ordersByCohort']]));

// ---- Operator CLIs and the rollback floor ------------------------------------------------------------------------
$cliEnvironment = ['HOME' => (string) getenv('HOME'),
    'ARASYA_APP_ENV' => 'test', 'ARASYA_APP_SECRET' => str_repeat('t', 32), 'ARASYA_ALLOWED_ORIGINS' => T::ORIGIN,
    'ARASYA_DB_HOST' => (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1'), 'ARASYA_DB_PORT' => (string) (getenv('ARASYA_TEST_DB_PORT') ?: '3306'),
    'ARASYA_DB_NAME' => $dbName, 'ARASYA_DB_USER' => (string) getenv('ARASYA_TEST_DB_USER'), 'ARASYA_DB_PASSWORD' => (string) getenv('ARASYA_TEST_DB_PASSWORD'),
    'ARASYA_SOURCE_SECRET_TRENDHOME' => T::TRENDHOME_SECRET, 'ARASYA_SOURCE_MODE_TRENDHOME' => 'active', 'ARASYA_SOURCE_AUTHORITY_TRENDHOME' => 'enforce',
    'ARASYA_SOURCE_QR_AUTHORITY_TRENDHOME' => 'enforce', 'ARASYA_SOURCE_DOCUMENT_AUTHORITY_TRENDHOME' => 'enforce', 'ARASYA_SOURCE_CONFIRMARE_TRENDHOME' => 'on',
];
$cli = static function (string $script, string ...$arguments) use ($cliEnvironment): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/' . $script, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $cliEnvironment);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => $out, 'err' => $err, 'json' => json_decode($out, true)];
};
$activationsBefore = (int) $one('SELECT COUNT(*) FROM workflow_cohort_activations');
$refusedCli = $cli('workflow-cohort.php', '--source=trendhome', '--activate', '--actor=Test Owner');
$check($refusedCli['code'] === 3 && str_contains($refusedCli['err'], 'CONFIRMARE_WORKFLOW_NOT_READY') && (int) $one('SELECT COUNT(*) FROM workflow_cohort_activations') === $activationsBefore, 'the operator CLI refuses every activation in Phase 1: ' . $refusedCli['err']);
$statusCli = $cli('workflow-cohort.php', '--source=trendhome', '--status');
$check($statusCli['code'] === 0 && ($statusCli['json']['state'] ?? null) === 'active' && ($statusCli['json']['permitted'] ?? null) === true && !isset($statusCli['json']['conflicts']), 'the status is read-only JSON: ' . $statusCli['out']);
$usage = $cli('workflow-cohort.php', '--activate');
$check($usage['code'] === 2, 'an activation without a source is a usage error');
$floor = $cli('check-rollback.php', '2.36.0');
$check($floor['code'] === 3 && ($floor['json']['requiredFloor'] ?? null) === '2.37.0' && ($floor['json']['state']['ordersAwaitingConfirmare'] ?? 0) > 0 && ($floor['json']['state']['confirmareActivations'] ?? 0) > 0, 'rollback below 2.37.0 is refused while Confirmare orders wait: ' . $floor['out'] . $floor['err']);
$check($cli('check-rollback.php', '2.37.0')['code'] === 0, 'rollback to 2.37.0 itself is allowed');

// ---- Database constraints -----------------------------------------------------------------------------------------
foreach ([
    "UPDATE operational_orders SET workflow_cohort = 'maybe' WHERE global_order_id = 'trendhome:{$id(70)}'" => 'an unknown cohort',
    "UPDATE operational_orders SET workflow_cohort_reason = NULL WHERE global_order_id = 'trendhome:{$id(70)}'" => 'a cohort without its reason',
    "UPDATE order_confirmations SET status = 'approved', decided_at = NULL WHERE global_order_id = 'trendhome:{$id(80)}'" => 'a decision without its time',
    "INSERT INTO workflow_cohort_activations (event_uuid, source_key, action, effective_at, actor_label, recorded_at) VALUES (UUID(), 'trendhome', 'activated', '2026-10-21 20:59:59', 'x', UTC_TIMESTAMP(6))" => 'an activation before the business date',
    "INSERT INTO workflow_cohort_activations (event_uuid, source_key, action, effective_at, actor_label, recorded_at) VALUES (UUID(), 'trendhome', 'paused', UTC_TIMESTAMP(6), 'x', UTC_TIMESTAMP(6))" => 'an unknown activation action',
] as $sql => $label) {
    try { $pdo->exec($sql); $check(false, "the database refuses {$label}"); } catch (PDOException) { $check(true, "the database refuses {$label}"); }
}

// ---- Teardown (disposable shared test database only): later suites assert their own rollback floors ---------------
$suiteOrders = array_column($pdo->query("SELECT order_uuid FROM operational_orders WHERE workflow_cohort IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC), 'order_uuid');
$pdo->exec('DELETE FROM order_confirmation_events');
$pdo->exec('DELETE FROM order_confirmations');
$pdo->exec('DELETE FROM workflow_cohort_activations');
$pdo->exec("UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL");
$check(($cli('check-rollback.php', '0.0.0')['json']['requiredFloor'] ?? null) !== '2.37.0' && count($suiteOrders) > 0, 'after the teardown no Confirmare state raises the rollback floor');

// An interrupted 035 (tables created, the ALTER not applied) completes on the next run; nothing else changes.
$pdo->exec('ALTER TABLE operational_orders DROP CONSTRAINT chk_operational_orders_workflow_cohort_complete, DROP CONSTRAINT chk_operational_orders_workflow_cohort,
    DROP KEY idx_operational_orders_cohort, DROP COLUMN source_submission_evidence, DROP COLUMN source_submitted_at, DROP COLUMN workflow_cohort_at,
    DROP COLUMN workflow_cohort_reason, DROP COLUMN workflow_cohort');
$ordersBefore = (int) $one('SELECT COUNT(*) FROM operational_orders');
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/035_workflow_cohort.sql');
$check((int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'operational_orders' AND column_name IN ('workflow_cohort', 'workflow_cohort_reason', 'workflow_cohort_at', 'source_submitted_at', 'source_submission_evidence')") === 5
    && (int) $one('SELECT COUNT(*) FROM operational_orders') === $ordersBefore && (int) $one('SELECT COUNT(*) FROM operational_orders WHERE workflow_cohort IS NOT NULL') === 0, 'an interrupted 035 completes on the next run without touching orders');

fwrite(STDOUT, "PASS MySQL Confirmare cohort integration: {$checks} checks (activation, boundaries, isolation, unpaid intake, drafts, evidence, every production start, payment, updates, replay, out-of-order, cancellation, approval, takeover, switch-off, suspension, older orders, concurrency, migration, diagnosis, CLIs, rollback floor, constraints).\n");
