<?php

declare(strict_types=1);

// Source advance payment gate (migration 025, API 2.29.0) on a disposable database, through the HTTP kernel.
// Trend Home runs the gate in ENFORCE, Outlet Perdele in OBSERVE, and a third kernel runs every gate OFF.
// Covers the signed contract extension (validation, replay, out-of-order, absence), every production start the
// gate guards (signed source print, Staff document generation, cutting intake, stage-1 claim, manager reassign),
// online and not-required orders, source isolation, the reversal after production started, and the views.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL payment gate integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}

const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $body = $r['body']; unset($body['document']['pdf']); $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($body)); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce'];
$settings = static fn (string $trendhome, string $outlet): array => ['trendhome' => $full + ['paymentGate' => $trendhome], 'outletperdele' => $full + ['paymentGate' => $outlet]];
$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $settings('enforce', 'observe'));
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$row = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetch(PDO::FETCH_ASSOC) ?: []; };

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$offKernel = (new Container(T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $settings('off', 'off')), $pdo))->kernel();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "gate.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-gate-root', $rootName);
$dash = static function (string $username, string $password) use ($kernel): array {
    $r = T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => DASHBOARD_ORIGIN]);
    if ($r['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $r['headers']['Set-Cookie'] ?? '', $m)) throw new RuntimeException("login {$username}");
    return ['cookie' => rawurldecode($m[1]), 'csrf' => (string) $r['body']['csrfToken']];
};
$first = $dash($rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Gate root passphrase 2026!'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = $dash($rootName, 'Gate root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null, ?object $k = null): array => T::call($k ?? $kernel, $method, $path, $body, ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $root['csrf'], 'idempotency-key' => 'gate-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$roleIds = [];
foreach ($ok($asRoot('GET', '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
$password = 'payment gate passphrase 2026';
$uuids = [];
$identity = static function (string $name, array $stages, array $apps, array $roles = []) use ($container, $asRoot, $ok, $password, $suffix, &$uuids): void {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test-gate');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]), "{$name} applications");
    if ($roles !== []) $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
};
$identity('gatecutter', ['material-preparation'], ['staff']);
$identity('gatewaiter', ['waiting'], ['staff']);
$identity('gateoperator', [], ['staff', 'dashboard'], [$roleIds['production-documents-operator']]);
$ok($asRoot('PUT', "/management/employees/{$uuids['gateoperator']}/document-scopes", ['operate' => ['outletperdele', 'trendhome'], 'approve' => []]), 'operator document scope');
$cutter = T::login($kernel, "gatecutter.{$suffix}", $password);
$waiter = T::login($kernel, "gatewaiter.{$suffix}", $password);
$operator = T::login($kernel, "gateoperator.{$suffix}", $password);
$staff = static fn (array $who, string $method, string $path, ?array $body = null, ?object $k = null): array => T::call($k ?? $kernel, $method, $path, $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => 'gate-' . bin2hex(random_bytes(10))], $who['cookie']);

// ---- Fixtures ----------------------------------------------------------------------------------------------------
$minute = 0;
$payload = static function (string $id, ?array $payment, ?int $ago = null, ?string $event = null) use ($suffix, &$minute): array {
    $minute++;
    $p = T::sourceOrder($id, $event ?? "gate-{$id}-{$suffix}-{$minute}", gmdate('Y-m-d\TH:i:s\Z', time() - ($ago ?? (7200 - $minute * 30))), null, 'processing', 'active', [[
        'id' => (int) substr(preg_replace('/\D/', '', $id), -6) * 10 + 1, 'line' => 1, 'name' => 'Perdea Voal Alb', 'sku' => 'PV-ALB', 'color' => 'Alb', 'width' => 3, 'height' => 2.75, 'unit' => 'm', 'quantity' => 1,
        'options' => [['label' => 'buc', 'value' => '2 buc.']],
    ]]);
    if ($payment !== null) $p['order']['payment'] = $payment;
    return $p;
};
$cod = static fn (string $status, int $revision = 0, ?string $at = null): array => ['method' => 'cod', 'advanceStatus' => $status, 'advanceRevision' => $revision] + ($at === null ? [] : ['advanceConfirmedAt' => $at]);
$online = ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0];
$send = static function (string $source, string $id, ?array $payment, ?object $k = null, ?int $ago = null, ?string $event = null) use ($kernel, $payload, $ok): array {
    return $ok(T::ingest($k ?? $kernel, $source, $payload($id, $payment, $ago, $event)), "ingest {$source}:{$id}");
};
$gate = static fn (string $globalId): array => $row('SELECT payment_method, advance_status, advance_revision, advance_confirmed_at, payment_synced_at, payment_reversed_at, production_stage_id, production_owner_employee_uuid, document_status FROM operational_orders WHERE global_order_id = ?', [$globalId]);
$qr = static fn (string $globalId): string => 'ARASYA:Q1:' . (string) $one("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? AND q.status = 'active'", [$globalId]);
$revisions = static fn (string $globalId): int => (int) $one('SELECT COUNT(*) FROM production_document_revisions r JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE o.global_order_id = ?', [$globalId]);
$sourcePrint = static fn (string $source, string $id, ?object $k = null): array => T::ingest($k ?? $kernel, $source, ['orderId' => $id, 'issue' => true, 'revisionNumber' => null, 'actor' => ['id' => 7, 'name' => 'Operator Magazin']], null, null, 'orders/document');
$sourceStates = static fn (string $source, array $ids, ?object $k = null): array => $ok(T::ingest($k ?? $kernel, $source, ['orderIds' => $ids], null, null, 'orders/documents'), "{$source} document states");
$generate = static fn (string $globalId, ?object $k = null): array => $staff($operator, 'POST', '/production-documents/orders/' . rawurlencode($globalId) . '/generate', ['expectedDocumentVersion' => 0], $k);
$intake = static function (string $globalId, ?object $k = null) use ($staff, $cutter, $qr, $one, &$uuids): array {
    // The cutter confirms each additional job explicitly, as the Staff scanner does.
    $owned = (int) $one("SELECT COUNT(*) FROM operational_orders WHERE production_owner_employee_uuid = ? AND production_stage_id = 'material-preparation' AND production_completed_at IS NULL", [$uuids['gatecutter']]);
    return $staff($cutter, 'POST', '/cutting/intake', ['qrToken' => $qr($globalId)] + ($owned > 0 ? ['confirmedMultiple' => true, 'ownedCount' => $owned] : []), $k);
};
$base = 910000 + random_int(0, 8999) * 10;
$id = static fn (int $n): string => (string) ($base + $n);

// ---- Heartbeat advertises the gate per source ------------------------------------------------------------------
$heartbeat = static fn (string $source, ?object $k = null): array => $ok(T::ingest($k ?? $kernel, $source, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')], null, null, 'heartbeat'), "{$source} heartbeat");
$check($heartbeat('trendhome')['paymentGateMode'] === 'enforce' && $heartbeat('outletperdele')['paymentGateMode'] === 'observe' && $heartbeat('trendhome', $offKernel)['paymentGateMode'] === 'off', 'the heartbeat states each source\'s own gate mode');

// ---- Contract: strict validation, nothing stored on refusal ----------------------------------------------------
foreach ([
    'online with an advance' => ['method' => 'online', 'advanceStatus' => 'waiting', 'advanceRevision' => 0],
    'unknown method' => ['method' => 'card', 'advanceStatus' => 'not_required', 'advanceRevision' => 0],
    'unknown status' => ['method' => 'cod', 'advanceStatus' => 'paid', 'advanceRevision' => 0],
    'negative revision' => ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => -1],
    'string revision' => ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => '1'],
    'confirmation time without confirmation' => ['method' => 'cod', 'advanceStatus' => 'partial', 'advanceRevision' => 1, 'advanceConfirmedAt' => '2026-10-13T08:00:00Z'],
    'amount field' => ['method' => 'cod', 'advanceStatus' => 'confirmed', 'advanceRevision' => 1, 'amount' => 120],
    'missing revision' => ['method' => 'cod', 'advanceStatus' => 'waiting'],
    'not an object' => 'confirmed',
] as $label => $bad) {
    $invalid = $payload($id(1), null);
    $invalid['order']['payment'] = $bad;
    $error(T::ingest($kernel, 'trendhome', $invalid), 422, 'SOURCE_PAYLOAD_INVALID', "contract refuses {$label}");
}
$check($one('SELECT COUNT(*) FROM operational_orders WHERE global_order_id = ?', ["trendhome:{$id(1)}"]) == 0, 'a refused payload creates no order');

// ---- Trend Home ENFORCE: a waiting advance blocks every production start ----------------------------------------
$waitingId = "trendhome:{$id(1)}";
$check($send('trendhome', $id(1), $cod('waiting'))['outcome'] === 'applied', 'COD order with a waiting advance is ingested');
$stored = $gate($waitingId);
$check($stored['payment_method'] === 'cod' && $stored['advance_status'] === 'waiting' && (int) $stored['advance_revision'] === 0 && $stored['advance_confirmed_at'] === null && $stored['payment_synced_at'] !== null && $stored['production_stage_id'] === 'waiting', 'the payment facts are stored with the order: ' . json_encode($stored));
$state = $sourceStates('trendhome', [$id(1)])['orders'][0];
$check($state['issueAllowed'] === false && $state['paymentGate'] === 'waiting' && $state['documentStatus'] === 'none', 'the source sees that it may not issue the ticket: ' . json_encode($state));
$refused = $error($sourcePrint('trendhome', $id(1)), 409, 'AVANS_NEVALIDAT', 'the signed source cannot print revision 1');
$check(($refused['error']['message'] ?? '') === 'Comanda nu poate intra în producție. Avansul necesar nu a fost confirmat.' && ($refused['error']['details']['paymentGate'] ?? null) === 'waiting', 'the refusal is Romanian and machine-readable: ' . json_encode($refused));
$error($generate($waitingId), 409, 'AVANS_NEVALIDAT', 'a document operator cannot generate revision 1 in Staff');
$check($revisions($waitingId) === 0 && $gate($waitingId)['document_status'] === 'none', 'no document revision exists');
$error($intake($waitingId), 409, 'AVANS_NEVALIDAT', 'the cutting QR intake cannot bypass the gate (the order has no document yet, which never opens it)');
$version = (int) $one('SELECT production_version FROM operational_orders WHERE global_order_id = ?', [$waitingId]);
$error($staff($waiter, 'POST', '/orders/' . rawurlencode($waitingId) . '/claim', ['expectedVersion' => $version]), 409, 'AVANS_NEVALIDAT', 'a stage-1 employee cannot claim it');
$error($asRoot('PUT', '/management/orders/' . rawurlencode($waitingId) . '/owner', ['employeeId' => $uuids['gatewaiter'], 'expectedVersion' => $version]), 409, 'AVANS_NEVALIDAT', 'root cannot assign it either');
$after = $gate($waitingId);
$check($after['production_stage_id'] === 'waiting' && $after['production_owner_employee_uuid'] === null && (int) $one('SELECT production_version FROM operational_orders WHERE global_order_id = ?', [$waitingId]) === $version, 'stage, owner and production version are unchanged');
$view = $ok($staff($waiter, 'GET', '/orders/' . rawurlencode($waitingId)), 'stage-1 employee reads the order');
$check(($view['paymentGate'] ?? null) === ['state' => 'waiting', 'label' => 'Avans neachitat', 'mode' => 'enforce', 'blocking' => true, 'reversedAt' => null] && ($view['employeeActionBlockedReason'] ?? null) === 'payment_gate_closed', 'Staff shows the gate state and reason, without amounts: ' . json_encode($view['paymentGate'] ?? null));
$check(!str_contains(json_encode($view), 'amount') && !str_contains(json_encode($view), 'advanceRevision'), 'the factory view carries no payment amount or confirmation detail');

// Partial and unknown are closed too.
$partialId = "trendhome:{$id(2)}";
$send('trendhome', $id(2), $cod('partial', 1));
$check(($error($sourcePrint('trendhome', $id(2)), 409, 'AVANS_NEVALIDAT', 'a partial advance blocks')['error']['details']['paymentGate'] ?? null) === 'partial', 'partial is reported as partial');
$unknownId = "trendhome:{$id(3)}";
$send('trendhome', $id(3), null);
$check($gate($unknownId)['payment_method'] === null && $gate($unknownId)['advance_status'] === null, 'an order whose source stated nothing has no payment facts');
$check(($error($sourcePrint('trendhome', $id(3)), 409, 'AVANS_NEVALIDAT', 'unknown payment blocks')['error']['details']['paymentGate'] ?? null) === 'unknown', 'a missing statement is never read as a confirmed advance');
$error($intake($unknownId), 409, 'AVANS_NEVALIDAT', 'unknown payment blocks the cutting intake');

// ---- Confirmation opens the gate; revision 1, intake ------------------------------------------------------------
$confirmedAt = gmdate('Y-m-d\TH:i:s\Z', time() - 600);
$check($send('trendhome', $id(1), $cod('confirmed', 2, $confirmedAt))['outcome'] === 'applied', 'the confirmation is a newer signed snapshot of the same order');
$stored = $gate($waitingId);
$check($stored['advance_status'] === 'confirmed' && (int) $stored['advance_revision'] === 2 && $stored['advance_confirmed_at'] !== null, 'the confirmed advance is stored: ' . json_encode($stored));
$check($sourceStates('trendhome', [$id(1)])['orders'][0]['issueAllowed'] === true, 'the source may now issue the ticket');
$printed = $ok($sourcePrint('trendhome', $id(1)), 'the source prints revision 1 after the confirmation');
$check($printed['document']['issued'] === true && $revisions($waitingId) === 1, 'exactly one revision 1');
$ok($sourcePrint('trendhome', $id(1)), 'a reprint');
$check($revisions($waitingId) === 1, 'a reprint never creates a second revision');
$started = $ok($intake($waitingId), 'the cutter receives the confirmed order');
$check($started['outcome'] === 'started' && $gate($waitingId)['production_stage_id'] === 'material-preparation' && $gate($waitingId)['production_owner_employee_uuid'] === $uuids['gatecutter'], 'cutting started exactly once');
$check(!array_key_exists('blocking', $started['order']['paymentGate'] ?? []) || $started['order']['paymentGate']['blocking'] === false, 'the gate shows open after the start');

// ---- Replay, out-of-order and absence never change the gate -----------------------------------------------------
$replay = $payload($id(4), $cod('confirmed', 1, $confirmedAt), 3000, "gate-replay-{$id(4)}-{$suffix}");
$check($ok(T::ingest($kernel, 'trendhome', $replay), 'first delivery')['outcome'] === 'applied', 'the confirmation is applied once');
$check($ok(T::ingest($kernel, 'trendhome', $replay), 'replayed delivery')['outcome'] === 'duplicate', 'a replayed confirmation is a duplicate');
$check((int) $gate("trendhome:{$id(4)}")['advance_revision'] === 1, 'a replay never counts a confirmation twice');
$check($send('trendhome', $id(4), $cod('waiting'), null, 5000)['outcome'] === 'out_of_order' && $gate("trendhome:{$id(4)}")['advance_status'] === 'confirmed', 'a late, older waiting snapshot never closes a newer confirmation');
$check($send('trendhome', $id(4), null, null, 100)['outcome'] === 'applied' && $gate("trendhome:{$id(4)}")['advance_status'] === 'confirmed', 'a snapshot without payment facts (older source build) keeps the stored facts');

// ---- Online and not-required orders are open -------------------------------------------------------------------
$send('trendhome', $id(5), $online);
$check($ok($sourcePrint('trendhome', $id(5)), 'online order prints')['document']['issued'] === true, 'an online order needs no advance');
$check($ok($intake("trendhome:{$id(5)}"), 'online intake')['outcome'] === 'started', 'an online order enters cutting');
$send('trendhome', $id(6), $cod('not_required'));
$check($ok($generate("trendhome:{$id(6)}"), 'not-required generate', 201) !== [] && $revisions("trendhome:{$id(6)}") === 1, 'a COD order whose source stated no advance requirement is open');

// ---- Source isolation: an Outlet confirmation never opens a Trend Home order ------------------------------------
$send('trendhome', $id(7), $cod('waiting'));
$send('outletperdele', $id(7), $cod('confirmed', 1, $confirmedAt));
$check($gate("trendhome:{$id(7)}")['advance_status'] === 'waiting' && $gate("outletperdele:{$id(7)}")['advance_status'] === 'confirmed', 'same source order id, two sources, two independent gates');
$error($sourcePrint('trendhome', $id(7)), 409, 'AVANS_NEVALIDAT', 'the Trend Home order stays closed');
$wrongSignature = $payload($id(7), $cod('confirmed', 9, $confirmedAt), 50);
$error(T::ingest($kernel, 'trendhome', $wrongSignature, T::OUTLET_SECRET), 401, 'SOURCE_SIGNATURE_INVALID', 'a confirmation signed with another source\'s secret is refused');
$check($gate("trendhome:{$id(7)}")['advance_status'] === 'waiting', 'an invalidly signed confirmation never opens the gate');

// ---- Outlet OBSERVE: shown, never refused ----------------------------------------------------------------------
$send('outletperdele', $id(8), $cod('waiting'));
$observeState = $sourceStates('outletperdele', [$id(8)])['orders'][0];
$check($observeState['issueAllowed'] === true && $observeState['paymentGate'] === 'waiting', 'observe: the state is visible but issuing stays allowed');
$check($ok($sourcePrint('outletperdele', $id(8)), 'observe print')['document']['issued'] === true, 'observe never refuses');
$observeView = $ok($intake("outletperdele:{$id(8)}"), 'observe intake');
$check(($observeView['order']['paymentGate']['mode'] ?? null) === 'observe' && $observeView['order']['paymentGate']['blocking'] === false, 'observe: the order shows the gate without blocking');

// ---- OFF: nothing new is shown or refused ----------------------------------------------------------------------
$send('trendhome', $id(9), $cod('waiting'), $offKernel);
$check($ok($sourcePrint('trendhome', $id(9), $offKernel), 'off print')['document']['issued'] === true, 'off: a waiting advance is not enforced');
$offStates = $sourceStates('trendhome', [$id(9)], $offKernel)['orders'][0];
$check(array_key_exists('paymentGate', $offStates) && $offStates['paymentGate'] === null, 'off: the source sees no gate state');
$offView = $ok($intake("trendhome:{$id(9)}", $offKernel), 'off intake');
$check(!array_key_exists('paymentGate', $offView['order']), 'off: the order view is unchanged');

// ---- Reversal after production started: recorded, never rewound ------------------------------------------------
$check($send('trendhome', $id(1), $cod('partial', 3))['outcome'] === 'applied', 'the source reports the advance reduced after cutting started');
$reversed = $gate($waitingId);
$check($reversed['production_stage_id'] === 'material-preparation' && $reversed['production_owner_employee_uuid'] === $uuids['gatecutter'] && $reversed['payment_reversed_at'] !== null && $reversed['document_status'] === 'active', 'stage, owner and document stay; the reversal is recorded: ' . json_encode($reversed));
$cutterView = $ok($staff($cutter, 'GET', '/orders/' . rawurlencode($waitingId)), 'cutter reads the reversed order');
$check($cutterView['paymentGate']['state'] === 'partial' && $cutterView['paymentGate']['blocking'] === false && $cutterView['paymentGate']['reversedAt'] !== null && ($cutterView['employeeAllowedAction']['id'] ?? null) === 'complete_stage', 'work already started continues and the reversal is visible');
$send('trendhome', $id(1), $cod('confirmed', 4, $confirmedAt));
$check($gate($waitingId)['payment_reversed_at'] === null, 'a renewed confirmation clears the reversal flag');

// ---- Stage-1 actions open after confirmation (claim and manager assignment) -------------------------------------
$send('trendhome', $id(10), $cod('confirmed', 1, $confirmedAt));
$v10 = (int) $one('SELECT production_version FROM operational_orders WHERE global_order_id = ?', ["trendhome:{$id(10)}"]);
$ok($asRoot('PUT', '/management/orders/' . rawurlencode("trendhome:{$id(10)}") . '/owner', ['employeeId' => $uuids['gatewaiter'], 'expectedVersion' => $v10]), 'root assigns a confirmed stage-1 order');
$check($gate("trendhome:{$id(10)}")['production_owner_employee_uuid'] === $uuids['gatewaiter'], 'the assignment applies');

// ---- Database constraints reject inconsistent facts -------------------------------------------------------------
$uuid = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', ["trendhome:{$id(5)}"]);
foreach ([
    "UPDATE operational_orders SET advance_status = 'waiting' WHERE order_uuid = ?" => 'online with a waiting advance',
    "UPDATE operational_orders SET advance_status = NULL WHERE order_uuid = ?" => 'half-stated facts',
    "UPDATE operational_orders SET payment_method = 'card' WHERE order_uuid = ?" => 'unknown method',
    "UPDATE operational_orders SET advance_confirmed_at = UTC_TIMESTAMP(6) WHERE order_uuid = ?" => 'confirmation time without confirmation',
] as $sql => $label) {
    $refusedSql = false;
    try { $pdo->prepare($sql)->execute([$uuid]); } catch (PDOException) { $refusedSql = true; }
    $check($refusedSql, "the schema refuses {$label}");
}

printf("PASS MySQL payment gate integration: %d checks (contract, enforce/observe/off, document, intake, claim, reassign, replay, out-of-order, isolation, reversal, constraints).\n", $checks);
