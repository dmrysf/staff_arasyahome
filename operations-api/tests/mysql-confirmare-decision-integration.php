<?php

declare(strict_types=1);

// Operation Simplification V1 (API 2.82.0, migration 063) on a disposable database, through the HTTP kernel: the operator's
// one decision (green: the customer confirmed, yellow: waiting, red: the customer refused), the undo before the system acts,
// the four desk lists with their counts, the retired three-unanswered-calls rule, and the decision follow-ups (no shop is
// configured here: the total acceptance and the proforma are skipped as `invoice_disabled`; the refusal stops the order and
// asks for its cancellation only when nobody paid and nothing started).

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

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL Confirmare decision integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}

const STAFF_ORIGIN = 'http://127.0.0.1:4174';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on'];
$settings = ['trendhome' => $full + ['paymentGate' => 'enforce'], 'outletperdele' => $full + ['paymentGate' => 'off']];
$config = T::config($dbName, [T::ORIGIN, STAFF_ORIGIN], $settings);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$all = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };

// Earlier suites on the shared disposable database may have left Confirmare state; this suite starts clean.
foreach (['confirmare_decision_followups', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$service = $container->confirmareService();
$registry = SourceRegistry::fromConfig($config);
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "cd.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-cd-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Confirmare staff root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Confirmare staff root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'cd-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$password = 'confirmare staff passphrase 2026';
$uuids = [];
$identity = static function (string $name, array $apps) use ($container, $asRoot, $ok, $password, $suffix, &$uuids): void {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test-cs');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]), "{$name} applications");
};
// Generic test identities: Confirmare access comes only from scopes granted below, never from a name.
foreach (['csopa', 'csopb', 'csopo', 'cssup', 'csnone'] as $name) $identity($name, ['staff']);
$ok($asRoot('PUT', "/management/employees/{$uuids['csopa']}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]), 'grant A');
$ok($asRoot('PUT', "/management/employees/{$uuids['csopb']}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]), 'grant B');
$ok($asRoot('PUT', "/management/employees/{$uuids['csopo']}/confirmare-scopes", ['operate' => ['outletperdele'], 'supervise' => []]), 'grant outlet operator');
$ok($asRoot('PUT', "/management/employees/{$uuids['cssup']}/confirmare-scopes", ['operate' => [], 'supervise' => ['trendhome']]), 'grant supervisor of one source');
$login = static fn (string $name): array => T::login($kernel, "{$name}.{$suffix}", $password);
$opA = $login('csopa');
$opB = $login('csopb');
$opO = $login('csopo');
$sup = $login('cssup');
$none = $login('csnone');
$call = static fn (array $who, string $method, string $path, ?array $body = null, ?string $key = null): array => T::call($kernel, $method, $path, $body, ['origin' => STAFF_ORIGIN, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? 'cs-' . bin2hex(random_bytes(10))], $who['cookie']);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => STAFF_ORIGIN], $who['cookie'], $query);
$act = static fn (array $who, string $globalId, string $action, array $body, ?string $key = null): array => $call($who, 'POST', '/confirmare/orders/' . rawurlencode($globalId) . '/' . $action, $body, $key);
$detail = static fn (array $who, string $globalId): array => $ok($get($who, '/confirmare/orders/' . rawurlencode($globalId)), "detail {$globalId}");
$version = static fn (string $globalId): int => (int) $one('SELECT version FROM order_confirmations WHERE global_order_id = ?', [$globalId]);
$contactRows = static fn (string $globalId): int => (int) $one('SELECT COUNT(*) FROM order_confirmation_contacts WHERE global_order_id = ?', [$globalId]);

// ---- 1. Session: the Confirmare scopes decide which Staff screens appear (presentation only) ------------------------
$sessionScopes = static fn (array $who): mixed => $get($who, '/auth/session')['body']['employee']['confirmareScopes'] ?? 'missing';
$check($sessionScopes($opA) === ['operate' => ['trendhome'], 'supervise' => []], 'the session names the operator\'s Confirmare scopes');
$check($sessionScopes($sup) === ['operate' => [], 'supervise' => ['trendhome']], 'the session names the supervisor\'s Confirmare scopes');
$check($sessionScopes($none) === ['operate' => [], 'supervise' => []], 'without a scope the session names none (default deny)');
$check($sessionScopes($root) === ['operate' => [], 'supervise' => ['outletperdele', 'trendhome']], 'root supervises every Confirmare source and never operates');
$check(($get($opA, '/employees/me')['body']['confirmareScopes'] ?? null) === ['operate' => ['trendhome'], 'supervise' => []], 'the profile view names them too');

// ---- 2. Fixtures: two Confirmare sources, a legacy order and an undetermined one -----------------------------------
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$tick = 0;
$payload = static function (string $id, ?array $submission, array $extra = []) use ($suffix, &$tick): array {
    $tick++;
    $p = T::sourceOrder($id, "cs-{$id}-{$suffix}-{$tick}", gmdate('Y-m-d\TH:i:s', time() - 5000 + $tick) . '.' . sprintf('%06d', $tick) . 'Z', null, $extra['status'] ?? 'processing', 'active', [[
        'id' => 8001, 'line' => 1, 'name' => 'Perdea Voal Alb', 'sku' => 'PV-ALB', 'color' => 'Alb', 'width' => 3, 'height' => 2.75, 'unit' => 'm', 'quantity' => 1,
        'options' => [['label' => 'Rejansă', 'value' => $extra['option'] ?? 'Inele']],
    ], [
        'id' => 8002, 'line' => 2, 'name' => 'Draperie Bej', 'sku' => 'DR-BEJ', 'color' => 'Bej', 'width' => 2, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1,
    ]]);
    $fingerprint = 'sha256:' . hash('sha256', "cs-price-{$id}-" . ($extra['price'] ?? 'a'));
    $p['order']['pricing'] = ['fingerprint' => $fingerprint];
    $online = ($extra['payment'] ?? 'online') === 'online';
    $p['order']['payment'] = $online ? ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0] : ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0];
    $p['order']['financial'] = $online ? ['state' => 'cleared', 'basis' => 'online_payment', 'pricingFingerprint' => $fingerprint] : ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $fingerprint];
    unset($p['order']['notes']);
    $p['order']['delivery'] = ['name' => 'Popescu Testclient', 'street' => 'Str. Exemplu 9', 'city' => 'Brașov', 'postalCode' => '500000', 'country' => 'RO', 'phone' => '0744123987'];
    if ($submission !== null) $p['order']['submission'] = $submission;
    return $p;
};
$send = static fn (string $source, array $p): array => T::ingest($kernel, $source, $p);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    fwrite(STDOUT, "SKIP MySQL Confirmare decision integration: needs a clock at least 10 minutes after 2026-10-22 00:00 Bucharest.\n");
    exit(0);
}
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
$activation = static fn (DateTimeImmutable $at): WorkflowCohortActivation => new WorkflowCohortActivation($pdo, new MutableClock($at));
$check(T::activationNeedsReadiness($pdo, $registry->find('trendhome')), 'API 2.45.0: activation needs the source readiness (Phase 3)');
foreach (['trendhome', 'outletperdele'] as $src) {
    $activation($activatedAt->modify('-20 minutes'))->schedule($registry->find($src), $activatedAt, 'Test Owner', 'staff test', 'cs-test', true);
    T::ingest($kernel, $src, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    $activation($activatedAt->modify('-1 minute'))->activate($registry->find($src), 'Test Owner', 'staff test', 'cs-test', true);
}
$base = T::freeOrderBase($pdo, 900000, 929999, 100); // ids base+1..base+80; a block no earlier run used
$id = static fn (int $n): string => (string) ($base + $n);
$th = static fn (int $n): string => 'trendhome:' . $id($n);
$submitted = static fn (int $seconds): array => ['submittedAt' => $iso($activatedAt->modify("+{$seconds} seconds")), 'evidence' => 'checkout'];
for ($n = 1; $n <= 9; $n++) $ok($send('trendhome', $payload($id($n), $submitted($n), ['payment' => 'cod'])), "trendhome cod order {$n}");
$ok($send('outletperdele', $payload($id(50), $submitted(50), ['payment' => 'cod'])), 'outlet order');
$decide = static fn (array $who, string $g, array $body, ?string $key = null): array => $act($who, $g, 'decision', ['expectedVersion' => $version($g)] + $body, $key);
$fp = static fn (array $who, string $g): string => $detail($who, $g)['review']['fingerprint'];
$followup = static fn (string $g): array => $all('SELECT kind, state, step, closed_reason, attempts FROM confirmare_decision_followups WHERE global_order_id = ? ORDER BY created_at DESC', [$g]);
$state = static fn (string $g): array => $all('SELECT status, assigned_employee_uuid, decided_by_employee_uuid, call_confirmed_at, call_confirmed_by_employee_uuid, decision_reason_code, hold_at, hold_note, callback_at, production_cleared FROM order_confirmations WHERE global_order_id = ?', [$g])[0];
$clock = $container->clock();

// ---- 3. The desk lists: four lists, every count in one answer, unassigned orders are open to every operator -------------
$desk = $ok($get($opA, '/confirmare/pool', ['view' => 'new']), 'desk new A');
$check($desk['view'] === 'new' && $desk['counts']['new'] === 9 && $desk['counts']['waiting'] === 0 && $desk['counts']['confirmed'] === 0 && $desk['counts']['refused'] === 0 && $desk['counts']['doneToday'] === 0,
    'the desk answers the new list and all four counts: ' . json_encode($desk['counts']));
$check(count($desk['items']) === 9 && $desk['items'][0]['lineCount'] === 2 && $desk['items'][0]['customerName'] === 'Popescu Testclient' && $desk['items'][0]['display'] === null && $desk['items'][0]['waiting'] === null,
    'the rows carry the line count and the customer name from Arasya\'s data; no display copy before a shop read');
$check(array_filter($desk['items'], static fn (array $i): bool => $i['sourceKey'] !== 'trendhome') === [], 'an operator\'s desk holds only their shops');
$check(array_column($desk['items'], 'globalOrderId') === array_map($th, range(1, 9)), 'priority order: the longest waiting first (no due date here)');
$error($get($opA, '/confirmare/pool', ['view' => 'later']), 400, 'INVALID_REQUEST', 'an unknown list is refused');
$error($get($none, '/confirmare/pool', ['view' => 'new']), 403, 'CONFIRMARE_SCOPE_DENIED', 'no scope, no desk');

// ---- 4. Green: one decision, no call record, no ticks, the review the screen showed ------------------------------------
$g1 = $th(1);
$error($decide($opA, $g1, ['decision' => 'maybe']), 422, 'CONFIRMARE_DECISION_INVALID', 'only the three decisions');
$error($decide($opA, $g1, ['decision' => 'confirmed']), 422, 'CONFIRMARE_REVIEW_REQUIRED', 'green needs the review the screen showed');
$error($decide($opA, $g1, ['decision' => 'confirmed', 'reviewFingerprint' => str_repeat('a', 64)]), 409, 'CONFIRMARE_REVIEW_STALE', 'a total that changed in between is refused with the new review');
$check($state($g1)['assigned_employee_uuid'] === null, 'a refused decision takes nothing (the take is in the same transaction)');
$fp1 = $fp($opA, $g1);
$v1 = $version($g1);
$green = $ok($decide($opA, $g1, ['decision' => 'confirmed', 'reviewFingerprint' => $fp1], 'cd-green-first-decision-1'), 'green A');
$s1 = $state($g1);
$check($green['operatorDecision'] === 'confirmed' && $green['status'] === 'approved' && isset($green['undoUntil']) && $s1['status'] === 'approved' && $s1['decided_by_employee_uuid'] === $uuids['csopa']
    && $s1['call_confirmed_by_employee_uuid'] === $uuids['csopa'] && $s1['call_confirmed_at'] !== null && $s1['assigned_employee_uuid'] === null, 'green approves an unassigned order in one step, the customer confirmation recorded as the operator\'s: ' . json_encode([$green, $s1]));
$check($contactRows($g1) === 0, 'no telephone attempt is invented');
$check(($one("SELECT reason_code FROM order_confirmation_events WHERE global_order_id = ? AND action = 'approved'", [$g1])) === 'customer_confirmed'
    && (int) $one("SELECT COUNT(*) FROM order_confirmation_events WHERE global_order_id = ? AND action = 'claimed'", [$g1]) === 1, 'the history shows the take and the customer confirmation');
$check($one('SELECT confirmed_line_ids FROM order_confirmations WHERE global_order_id = ?', [$g1]) === '["8001","8002"]' && $one('SELECT approved_review_fingerprint FROM order_confirmations WHERE global_order_id = ?', [$g1]) === $fp1, 'every current line and the review are bound to the decision');
$missing = $detail($opA, $g1)['production']['missing'] ?? null;
$check($missing === ['payment'], 'production waits only for the money now (the call condition is met by the decision): ' . json_encode($missing));
$check($followup($g1)[0]['kind'] === 'confirmed' && $followup($g1)[0]['state'] === 'pending' && $followup($g1)[0]['step'] === 'accept_total', 'the follow-up waits for the undo period');
$replay = $ok($act($opA, $g1, 'decision', ['expectedVersion' => $v1, 'decision' => 'confirmed', 'reviewFingerprint' => $fp1], 'cd-green-first-decision-1'), 'green replay');
$check($replay['status'] === 'approved' && count($followup($g1)) === 1, 'the same click again answers the recorded decision (no second follow-up)');
$error($decide($opB, $g1, ['decision' => 'confirmed', 'reviewFingerprint' => $fp1]), 409, 'CONFIRMARE_NOT_PENDING', 'a decided order is not decided again');

// ---- 5. Undo before the system acts, and never after ------------------------------------------------------------------
$error($act($opB, $g1, 'decision/undo', ['expectedVersion' => $version($g1)]), 409, 'CONFIRMARE_UNDO_NOT_ALLOWED', 'only one\'s own decision is undone');
$ok($act($opA, $g1, 'decision/undo', ['expectedVersion' => $version($g1)]), 'undo green');
$u1 = $state($g1);
$check($u1['status'] === 'pending' && $u1['assigned_employee_uuid'] === $uuids['csopa'] && $u1['call_confirmed_at'] === null && $followup($g1)[0]['state'] === 'cancelled' && $followup($g1)[0]['closed_reason'] === 'operator_undo',
    'the undo returns the order to the operator and cancels the untouched follow-up');
$ok($decide($opA, $g1, ['decision' => 'confirmed', 'reviewFingerprint' => $fp($opA, $g1)]), 'green again');
$pdo->exec("UPDATE confirmare_decision_followups SET due_at = '2000-01-01 00:00:00' WHERE active_order_uuid IS NOT NULL");
$run = $container->confirmareDecisionFollowUps()->run(50, 'cd-test');
$f1 = $followup($g1)[0];
$check($run['seen'] >= 1 && $f1['state'] === 'done' && $f1['closed_reason'] === 'invoice_disabled', 'without invoicing for the shop the follow-up ends after the decision (nothing to send): ' . json_encode($f1));
$error($act($opA, $g1, 'decision/undo', ['expectedVersion' => $version($g1)]), 409, 'CONFIRMARE_UNDO_TOO_LATE', 'after the system acted the decision is not undone here');

// ---- 6. A colleague's order is never overwritten; supervisors decide any pending order ---------------------------------
$g2 = $th(2);
$ok($act($opB, $g2, 'claim', ['expectedVersion' => $version($g2)]), 'B takes order 2');
$error($decide($opA, $g2, ['decision' => 'confirmed', 'reviewFingerprint' => $fp($sup, $g2)]), 409, 'CONFIRMARE_NOT_ASSIGNED_TO_YOU', 'an order a colleague holds is refused, never overwritten');
$error($decide($opO, $g2, ['decision' => 'waiting']), 404, 'CONFIRMARE_NOT_FOUND', 'another shop\'s operator does not see it');

// ---- 7. Yellow: waiting, optional note and callback, no limit, reopened at once ----------------------------------------
$g3 = $th(3);
$ok($decide($opA, $g3, ['decision' => 'waiting']), 'yellow without anything');
$s3 = $state($g3);
$check($s3['status'] === 'pending' && $s3['hold_at'] !== null && $s3['hold_note'] === null && $s3['assigned_employee_uuid'] === $uuids['csopa'], 'yellow keeps the order pending with the operator; nothing is required');
$check($followup($g3) === [] && (int) $one("SELECT COUNT(*) FROM confirmare_proforma_cycles WHERE order_uuid = (SELECT order_uuid FROM operational_orders WHERE global_order_id = ?)", [$g3]) === 0, 'yellow makes no proforma and no follow-up');
$callback = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
$ok($decide($opA, $g3, ['decision' => 'waiting', 'note' => "  Revine   după-amiază  ", 'callbackAt' => $callback]), 'yellow again with a note and a callback');
$s3 = $state($g3);
$check($s3['hold_note'] === 'Revine după-amiază' && substr((string) $s3['callback_at'], 0, 16) === str_replace('T', ' ', substr($callback, 0, 16)), 'the optional note (one line) and callback are kept');
for ($i = 0; $i < 5; $i++) $ok($decide($opA, $g3, ['decision' => 'waiting']), "yellow {$i}");
$check(true, 'no daily or call limit on waiting decisions');
$error($decide($opA, $g3, ['decision' => 'waiting', 'callbackAt' => 'tomorrow-ish']), 422, 'VALIDATION_FAILED', 'a callback that is not an instant is refused');
$waiting = $ok($get($opA, '/confirmare/pool', ['view' => 'waiting']), 'desk waiting');
$check(array_column($waiting['items'], 'globalOrderId') === [$g3] && $waiting['items'][0]['waiting']['note'] === null && $waiting['counts']['waiting'] === 1, 'the waiting list holds the yellow order');
$newIds = array_column($ok($get($opA, '/confirmare/pool', ['view' => 'new']), 'desk new')['items'], 'globalOrderId');
$check(!in_array($g3, $newIds, true) && !in_array($g1, $newIds, true), 'a waiting or decided order leaves the new list');
$g3fp = $fp($opA, $g3);
$ok($decide($opA, $g3, ['decision' => 'confirmed', 'reviewFingerprint' => $g3fp]), 'the waiting order turns green later');
$check($state($g3)['hold_at'] === null && $state($g3)['status'] === 'approved', 'green clears the waiting mark');

// ---- 8. Red: optional reason, the cancellation after the undo period, only for an order nobody paid -------------------
$g4 = $th(4);
$red = $ok($decide($opA, $g4, ['decision' => 'refused']), 'red without a reason');
$s4 = $state($g4);
$check($red['operatorDecision'] === 'refused' && $s4['status'] === 'rejected' && $s4['decision_reason_code'] === 'customer_cancelled' && $followup($g4)[0]['step'] === 'request_cancel', 'red needs no reason (customer_cancelled by default)');
$error($decide($opA, $th(5), ['decision' => 'refused', 'reasonCode' => 'bored']), 422, 'INVALID_REASON', 'only the known reasons');
$check($container->confirmareDecisionFollowUps()->run(50, 'cd-test')['seen'] === 0, 'nothing happens during the undo period');
$ok($act($opA, $g4, 'decision/undo', ['expectedVersion' => $version($g4)]), 'undo red');
$check($state($g4)['status'] === 'pending' && (int) $one('SELECT COUNT(*) FROM order_cancellation_controls WHERE order_uuid = (SELECT order_uuid FROM operational_orders WHERE global_order_id = ?)', [$g4]) === 0, 'a wrong red click is undone with no stop and no cancellation request');
$ok($decide($opA, $g4, ['decision' => 'refused']), 'red again');
$g5 = $th(5);
$ok($decide($opA, $g5, ['decision' => 'refused', 'reasonCode' => 'duplicate_order']), 'red with a reason');
// Order 5 has verified money: never cancelled automatically.
$pdo->prepare("UPDATE operational_orders SET source_financial_state = 'cleared', source_financial_basis = 'advance_collected' WHERE global_order_id = ?")->execute([$g5]);
$pdo->exec("UPDATE confirmare_decision_followups SET due_at = '2000-01-01 00:00:00' WHERE active_order_uuid IS NOT NULL");
$container->confirmareDecisionFollowUps()->run(50, 'cd-test');
$f4 = $followup($g4)[0];
$f5 = $followup($g5)[0];
$hold4 = $all('SELECT hold_status FROM order_cancellation_controls WHERE order_uuid = (SELECT order_uuid FROM operational_orders WHERE global_order_id = ?)', [$g4]);
$check($f4['state'] === 'done' && in_array($f4['closed_reason'], ['requested', 'source_cancelled'], true) && ($hold4[0]['hold_status'] ?? null) === 'held', 'a refused unpaid order is stopped and its cancellation requested: ' . json_encode([$f4, $hold4]));
$check($f5['state'] === 'done' && $f5['closed_reason'] === 'money_received'
    && (int) $one('SELECT COUNT(*) FROM order_cancellation_controls WHERE order_uuid = (SELECT order_uuid FROM operational_orders WHERE global_order_id = ?)', [$g5]) === 0, 'a refused order with money is never stopped automatically (a person decides)');
$refused = $ok($get($opA, '/confirmare/pool', ['view' => 'refused']), 'desk refused');
$confirmed = $ok($get($opA, '/confirmare/pool', ['view' => 'confirmed']), 'desk confirmed');
$check($refused['counts']['refused'] === 2 && $confirmed['counts']['confirmed'] === 2 && $confirmed['counts']['doneToday'] === 4 && array_column($refused['items'], 'globalOrderId') === [$g5, $g4], 'the decided lists and today\'s count: ' . json_encode($confirmed['counts']));

// ---- 9. The three-unanswered-calls rule is retired ------------------------------------------------------------------------
$g6 = $th(6);
$ok($act($opA, $g6, 'claim', ['expectedVersion' => $version($g6)]), 'A takes order 6');
$fp6 = $fp($opA, $g6);
for ($i = 1; $i <= 4; $i++) {
    $answer = $ok($act($opA, $g6, 'contact', ['expectedVersion' => $version($g6), 'outcome' => 'no_answer', 'reviewFingerprint' => $fp6]), "no answer {$i}");
    $check(($answer['proformaCycle']['reason'] ?? null) === 'rule_retired', "unanswered call {$i} starts no proforma");
}
$check($contactRows($g6) === 4, 'a fourth unanswered call is no longer refused');

// ---- 10. Concurrency: two decisions on the same order, one wins --------------------------------------------------------
$g7 = $th(7);
$fp7 = $fp($opA, $g7);
$v7 = $version($g7);
$first = $act($opA, $g7, 'decision', ['expectedVersion' => $v7, 'decision' => 'confirmed', 'reviewFingerprint' => $fp7]);
$second = $act($opB, $g7, 'decision', ['expectedVersion' => $v7, 'decision' => 'refused']);
$check($first['status'] === 200 && $second['status'] === 409 && $state($g7)['status'] === 'approved', 'the second decision on the same version is refused (CONFIRMARE_CHANGED), nothing is overwritten');

// ---- 11. A verified online payment goes to the shop employee without the operator ---------------------------------------
foreach ([20, 21, 22, 23, 24] as $n) $ok($send('trendhome', $payload($id($n), $submitted($n), ['payment' => 'online'])), "online order {$n}");
$ok($decide($opA, $th(21), ['decision' => 'waiting']), 'an operator already marked order 21 as waiting');
$pdo->prepare("UPDATE operational_orders SET workflow_cohort_reason = 'financing_legacy' WHERE global_order_id = ?")->execute([$th(22)]);
$pdo->prepare("UPDATE operational_orders SET source_financial_attention = 'refund_liability' WHERE global_order_id = ?")->execute([$th(23)]);
$pdo->prepare("UPDATE operational_orders SET source_financial_state = 'waiting', source_financial_basis = NULL WHERE global_order_id = ?")->execute([$th(24)]);
$online = $service->approveVerifiedOnline(50, 'cd-online');
$s20 = $state($th(20));
$check($s20['status'] === 'approved' && $s20['decided_by_employee_uuid'] === null && $s20['call_confirmed_at'] === null && (int) $s20['production_cleared'] === 0
    && $one("SELECT actor_kind FROM order_confirmation_events WHERE global_order_id = ? AND action = 'approved'", [$th(20)]) === 'system'
    && $one("SELECT reason_code FROM order_confirmation_events WHERE global_order_id = ? AND action = 'approved'", [$th(20)]) === 'online_payment_verified',
    'a verified online payment is approved by the system (no employee, no call); production stays closed: ' . json_encode([$online, $s20]));
$check(\Arasya\Operations\Confirmare\ConfirmareService::awaitingCustomerCheck($pdo, (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$th(20)])), 'it waits for the shop employee\'s customer check');
$check(in_array('call', $detail($sup, $th(20))['production']['missing'], true), 'production needs the customer check (routing is not production)');
foreach ([21 => 'waiting', 22 => 'financing', 23 => 'attention', 24 => 'unpaid'] as $n => $label) {
    $check($state($th($n))['status'] === 'pending', "online order {$n} ({$label}) is not approved by the system");
}
$check(($online['skipped']['operator_waiting'] ?? 0) === 0 && $online['approved'] >= 1, 'the waiting, TBI, attention and unpaid orders are not even candidates: ' . json_encode($online));
$routed = $container->confirmareInvoice()->routeOnline(50);
$check($routed['seen'] >= 1 && $routed['routed'] === 0 && isset($routed['skipped']['no_shop_employee']), 'without a shop employee of the shop nothing is routed (no guess): ' . json_encode($routed));
$again = $service->approveVerifiedOnline(50, 'cd-online-2');
$check($again['approved'] === 0, 'a second run approves nothing twice');

// Leave the shared disposable database as the next suites expect it: no decision, cancellation, Confirmare or finance state.
foreach (['confirmare_decision_followups', 'confirmare_order_display', 'finance_task_amounts', 'finance_verification_request_events', 'finance_verification_requests', 'confirmare_cycle_steps', 'confirmare_proforma_cycles',
    'order_cancellation_sync_attempts', 'order_cancellation_discrepancies', 'order_cancellation_sync_operations', 'order_cancellation_events', 'order_cancellation_controls',
    'order_confirmation_source_reads', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations',
    'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
fwrite(STDOUT, "PASS MySQL Confirmare decision integration: {$checks} checks (desk lists and counts, green without a call record on the shown review, undo before the system acts, colleague's order never overwritten, yellow without limits, red with the cancellation only for unpaid orders, retired three-call rule, concurrent decisions).\n");
