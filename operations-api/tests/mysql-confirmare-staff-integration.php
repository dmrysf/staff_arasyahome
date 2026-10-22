<?php

declare(strict_types=1);

// Confirmare Staff workspace server side (migration 037, API 2.39.0, Phase 3) on a disposable database, through the HTTP
// kernel. Covers: the Confirmare scopes in the session (route visibility), the recorded contact attempts (append-only,
// assignee or supervisor only, bound to the reviewed order and version, idempotent, never a cancellation), the approval
// that needs the latest attempt `confirmed` on the current review (no fake call), stale review after a shop change
// (options included), reopening, the order detail (lines, history, customer block only for the assignee or a supervisor,
// never a full telephone number), the queue without personal data and without legacy or other-channel orders, the
// supervisor team view and alerts, double submissions and concurrent reassignment.

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
    fwrite(STDOUT, "SKIP MySQL Confirmare Staff integration: ARASYA_TEST_DB_NAME is not configured.\n");
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
foreach (['order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$service = $container->confirmareService();
$registry = SourceRegistry::fromConfig($config);
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "cs.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-cs-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Confirmare staff root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Confirmare staff root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'cs-root-' . bin2hex(random_bytes(10))], $root['cookie']);
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
    fwrite(STDOUT, "SKIP MySQL Confirmare Staff integration: needs a clock at least 10 minutes after 2026-10-22 00:00 Bucharest.\n");
    exit(0);
}
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
$activation = static fn (DateTimeImmutable $at): WorkflowCohortActivation => new WorkflowCohortActivation($pdo, new MutableClock($at));
$check(ConfirmationGate::WORKFLOW_READY === false, 'Phase 3 keeps WORKFLOW_READY false');
foreach (['trendhome', 'outletperdele'] as $src) {
    $activation($activatedAt->modify('-20 minutes'))->schedule($registry->find($src), $activatedAt, 'Test Owner', 'staff test', 'cs-test', true);
    T::ingest($kernel, $src, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    $activation($activatedAt->modify('-1 minute'))->activate($registry->find($src), 'Test Owner', 'staff test', 'cs-test', true);
}
$base = 870000 + random_int(0, 8999) * 10;
$id = static fn (int $n): string => (string) ($base + $n);
$th = static fn (int $n): string => 'trendhome:' . $id($n);
$submitted = static fn (int $seconds): array => ['submittedAt' => $iso($activatedAt->modify("+{$seconds} seconds")), 'evidence' => 'checkout'];
for ($n = 1; $n <= 8; $n++) $ok($send('trendhome', $payload($id($n), $submitted($n), ['payment' => $n === 3 ? 'cod' : 'online'])), "trendhome order {$n}");
$ok($send('outletperdele', $payload($id(50), $submitted(50))), 'outlet order');
$ok($send('trendhome', $payload($id(60), ['submittedAt' => $iso($activatedAt->modify('-1 hour')), 'evidence' => 'checkout'])), 'legacy order (before the activation)');
$ok($send('trendhome', $payload($id(70), null)), 'undetermined order (no submission evidence)');
$check($service->distribute()['assigned'] === 9, 'the server distributes (never the browser)');

// ---- 3. The queue: no personal data, no legacy, no other channel, no other source ----------------------------------
$poolA = $ok($get($opA, '/confirmare/pool', ['view' => 'mine']), 'pool A');
$poolJson = json_encode($poolA, JSON_UNESCAPED_UNICODE);
$check(!str_contains($poolJson, 'Popescu') && !str_contains($poolJson, '0744') && !str_contains($poolJson, 'Exemplu') && !str_contains($poolJson, 'Brașov'), 'the queue carries no customer data');
$check(array_filter($poolA['items'], static fn (array $i): bool => $i['sourceKey'] !== 'trendhome') === [], 'an operator\'s queue holds only their source');
$supAll = $ok($get($sup, '/confirmare/pool', ['view' => 'all']), 'supervisor all');
$supIds = array_column($supAll['items'], 'globalOrderId');
$check(!in_array($th(60), $supIds, true) && !in_array('outletperdele:' . $id(50), $supIds, true) && in_array($th(70), $supIds, true), 'no legacy order and no other-source order, the undetermined one for the supervisor');
$check(array_filter($supAll['items'], static fn (array $i): bool => !in_array($i['sourceKey'], ['trendhome', 'outletperdele'], true)) === [], 'never a Trendyol or B2B order');
$check(isset($poolA['items'][0]['financial']['open'], $poolA['items'][0]['pricingAvailable']) && array_key_exists('contact', $poolA['items'][0]) && $poolA['items'][0]['contact'] === null, 'queue items carry the financial condition, the pricing availability and the latest contact (none yet)');
$error($get($opO, '/confirmare/orders/' . rawurlencode($th(1))), 404, 'CONFIRMARE_NOT_FOUND', 'another source\'s order is invisible (no cross-store leak)');
$error($get($none, '/confirmare/pool', ['view' => 'open']), 403, 'CONFIRMARE_SCOPE_DENIED', 'no scope, no queue');

// ---- 4. The detail: lines, history and the customer block --------------------------------------------------------
$mineA = array_column($ok($get($opA, '/confirmare/pool', ['view' => 'mine']), 'mine A')['items'], 'globalOrderId');
$mineB = array_column($ok($get($opB, '/confirmare/pool', ['view' => 'mine']), 'mine B')['items'], 'globalOrderId');
$aOrder = $mineA[0];
$bOrder = $mineB[0];
$dA = $detail($opA, $aOrder);
$check(count($dA['lines']) === 2 && $dA['lines'][0]['lineId'] === '8001' && $dA['lines'][0]['options'] === [['label' => 'Rejansă', 'value' => 'Inele']] && $dA['lines'][0]['width'] === 3.0 && $dA['lines'][0]['confirmed'] === false, 'the detail lists every current line with its measurements and source options');
$check(!str_contains(json_encode($dA['lines']), 'price') && !preg_match('/"(total|amount|subtotal)"/', json_encode($dA)), 'no price or amount is ever shown by Arasya');
$check($dA['customer']['visible'] === true && $dA['customer']['name'] === 'Popescu Testclient' && $dA['customer']['phoneMasked'] === '07** *** ***' && $dA['customer']['phoneAvailable'] === false, 'the assignee sees the delivery identity and the masked phone only');
$check(!str_contains(json_encode($dA), '0744123987') && !str_contains(json_encode($dA), '123987'), 'the full telephone number is never returned (Arasya never stores it)');
$dB = $detail($opA, $bOrder);
$check($dB['customer'] === ['visible' => false, 'phoneAvailable' => false] && !str_contains(json_encode($dB, JSON_UNESCAPED_UNICODE), 'Popescu'), 'a colleague\'s order shows no customer data to another operator');
$check($detail($sup, $bOrder)['customer']['visible'] === true, 'a supervisor of the source sees it');
$check($dA['review']['callRecorded'] === false && $dA['contacts'] === [] && $dA['source']['freshness'] !== null && $dA['source']['commerceStatusLabel'] === 'Processing', 'no call recorded yet; the source status and freshness are shown');

// ---- 5. Recording contact attempts -----------------------------------------------------------------------------
$contact = static fn (array $who, string $g, string $outcome, ?string $fingerprint = null, ?int $v = null, ?string $key = null): array => $act($who, $g, 'contact', ['expectedVersion' => $v ?? $version($g), 'outcome' => $outcome, 'reviewFingerprint' => $fingerprint ?? $detail($who, $g)['review']['fingerprint']], $key);
$fpA = $dA['review']['fingerprint'];
$error($contact($opA, $aOrder, 'cancelled'), 422, 'INVALID_OUTCOME', 'only the known outcomes (no cancellation by a missed call)');
$error($act($opA, $aOrder, 'contact', ['expectedVersion' => $version($aOrder), 'outcome' => 'no_answer', 'reviewFingerprint' => $fpA, 'note' => 'a sunat']), 400, 'INVALID_REQUEST', 'no free text, no duration, no recording');
$error($act($opA, $aOrder, 'contact', ['expectedVersion' => $version($aOrder), 'outcome' => 'no_answer', 'reviewFingerprint' => $fpA, 'durationSeconds' => 60]), 400, 'INVALID_REQUEST', 'a call duration is refused');
$error($contact($opA, $aOrder, 'no_answer', $fpA, null, 'short'), 400, 'INVALID_IDEMPOTENCY_KEY', 'a recording needs an Idempotency-Key');
$error($contact($opA, $aOrder, 'no_answer', $fpA, $version($aOrder) - 1), 409, 'CONFIRMARE_CHANGED', 'a recording on a stale view is refused');
$error($contact($opA, $aOrder, 'no_answer', str_repeat('a', 64)), 409, 'CONFIRMARE_REVIEW_STALE', 'a recording about another version of the order is refused');
$error($contact($opA, $bOrder, 'no_answer'), 409, 'CONFIRMARE_NOT_ASSIGNED_TO_YOU', 'only the assignee (or a supervisor) records a call');
$error($contact($opO, $aOrder, 'no_answer', $fpA), 404, 'CONFIRMARE_NOT_FOUND', 'another source\'s operator finds nothing');
$error($contact($sup, $th(70), 'no_answer'), 409, 'CONFIRMARE_UNDETERMINED', 'an undetermined order is not called here');
$versionBefore = $version($aOrder);
$key = 'cs-contact-' . bin2hex(random_bytes(8));
$first = $ok($contact($opA, $aOrder, 'no_answer', $fpA, null, $key), 'record no answer');
$check($first['contact']['attempt'] === 1 && $first['contact']['outcome'] === 'no_answer' && $first['contact']['by'] === 'Test csopa' && $first['contact']['current'] === true && $first['item']['status'] === 'pending', 'a missed call is recorded and the order stays pending (never cancelled)');
$replay = $ok($contact($opA, $aOrder, 'no_answer', $fpA, null, $key), 'double submission');
$check($replay['contact'] === $first['contact'] && $contactRows($aOrder) === 1, 'a double click replays the same attempt and records nothing new');
$error($contact($opA, $aOrder, 'confirmed', $fpA, null, $key), 409, 'IDEMPOTENCY_CONFLICT', 'a key reused for another outcome is refused');
$check($version($aOrder) === $versionBefore && $one('SELECT assigned_employee_uuid FROM order_confirmations WHERE global_order_id = ?', [$aOrder]) === $uuids['csopa'], 'a recorded attempt changes neither the version nor the assignment');
$poolItem = array_values(array_filter($ok($get($opA, '/confirmare/pool', ['view' => 'mine']), 'mine after call')['items'], static fn (array $i): bool => $i['globalOrderId'] === $aOrder))[0];
$check($poolItem['contact'] === ['outcome' => 'no_answer', 'at' => $first['contact']['at'], 'attempts' => 1, 'current' => true], 'the queue shows the latest outcome (no customer data)');

// ---- 6. Approval needs a real recorded confirmation (no fake call) ------------------------------------------------
$approveBody = static fn (array $who, string $g): array => ['expectedVersion' => $version($g), 'customerCall' => 'confirmed', 'confirmedLineIds' => $detail($who, $g)['review']['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $detail($who, $g)['review']['fingerprint']];
$error($act($opA, $aOrder, 'approve', $approveBody($opA, $aOrder)), 409, 'CONFIRMARE_CALL_NOT_RECORDED', 'stating customerCall alone is not a recorded confirmation');
$ok($contact($opA, $aOrder, 'callback', $fpA), 'the customer asks to be called later');
$error($act($opA, $aOrder, 'approve', $approveBody($opA, $aOrder)), 409, 'CONFIRMARE_CALL_NOT_RECORDED', 'a callback is no confirmation');
$confirmed = $ok($contact($opA, $aOrder, 'confirmed', $fpA), 'the customer confirms');
$check($detail($opA, $aOrder)['review']['callRecorded'] === true, 'the review shows the recorded confirmation');
$ok($contact($opA, $aOrder, 'needs_correction', $fpA), 'then the customer wants a change');
$error($act($opA, $aOrder, 'approve', $approveBody($opA, $aOrder)), 409, 'CONFIRMARE_CALL_NOT_RECORDED', 'the latest attempt decides: a needed correction blocks the approval');
$check(array_column($detail($opA, $aOrder)['contacts'], 'outcome') === ['needs_correction', 'confirmed', 'callback', 'no_answer'], 'the history lists every attempt, newest first');
// The shop corrects the order (another option): the earlier attempts no longer describe it.
$ok($send('trendhome', $payload(explode(':', $aOrder)[1], null, ['option' => 'Rejansă creion'])), 'the shop changes an option');
$fpA2 = $detail($opA, $aOrder)['review']['fingerprint'];
$check($fpA2 !== $fpA, 'a changed option changes the review fingerprint (API 2.39.0)');
$check(array_unique(array_column($detail($opA, $aOrder)['contacts'], 'current')) === [false], 'every earlier attempt is stale after the change');
$error($contact($opA, $aOrder, 'confirmed', $fpA), 409, 'CONFIRMARE_REVIEW_STALE', 'a confirmation about the old order is refused');
$error($act($opA, $aOrder, 'approve', ['priceVerified' => true] + $approveBody($opA, $aOrder)), 409, 'CONFIRMARE_CALL_NOT_RECORDED', 'the approval needs a confirmation of the changed order');
$fresh = $ok($contact($opA, $aOrder, 'confirmed', $fpA2), 'the customer confirms the corrected order');
$error($act($opA, $aOrder, 'approve', ['priceVerified' => false] + $approveBody($opA, $aOrder)), 422, 'CONFIRMARE_PRICE_NOT_VERIFIED', 'the operator must state the price review');
$approveKey = 'cs-approve-' . bin2hex(random_bytes(8));
$body = $approveBody($opA, $aOrder);
$approved = $ok($act($opA, $aOrder, 'approve', $body, $approveKey), 'approve');
$again = $ok($act($opA, $aOrder, 'approve', $body, $approveKey), 'double-click approval');
$check($approved === $again && (int) $one("SELECT COUNT(*) FROM order_confirmation_events WHERE global_order_id = ? AND action = 'approved'", [$aOrder]) === 1, 'a double-click approval approves once');
$callRow = $all('SELECT call_confirmed_at, call_confirmed_by_employee_uuid FROM order_confirmations WHERE global_order_id = ?', [$aOrder])[0];
$check(str_starts_with((new DateTimeImmutable($callRow['call_confirmed_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'), substr($fresh['contact']['at'], 0, 19)) && $callRow['call_confirmed_by_employee_uuid'] === $uuids['csopa'], 'the approval takes the call time and caller from the recorded attempt');
$error($contact($opA, $aOrder, 'no_answer', $fpA2), 409, 'CONFIRMARE_NOT_PENDING', 'no attempt is recorded on a decided order');
$check(array_map(static fn (array $l): bool => $l['confirmed'], $detail($sup, $aOrder)['lines']) === [true, true], 'every line shows its confirmation after the approval');
// Payment stays independent: a confirmed COD order without its advance is approved but production stays closed.
$cod = $th(3);
$codOwner = $one('SELECT assigned_employee_uuid FROM order_confirmations WHERE global_order_id = ?', [$cod]) === $uuids['csopa'] ? $opA : $opB;
$ok($contact($codOwner, $cod, 'confirmed'), 'COD customer confirms');
$ok($act($codOwner, $cod, 'approve', $approveBody($codOwner, $cod)), 'approve COD');
$codDetail = $detail($sup, $cod);
$check($codDetail['production'] === ['cleared' => false, 'missing' => ['payment']] && $codDetail['financial']['open'] === false, 'a confirmed customer never clears a missing advance');
$followIds = static fn (array $who): array => array_column($ok($get($who, '/confirmare/pool', ['view' => 'followup']), 'followup')['items'], 'globalOrderId');
$otherOp = $codOwner === $opA ? $opB : $opA;
$check($followIds($codOwner) === [$cod] && $followIds($otherOp) === [] && in_array($cod, $followIds($sup), true) && !in_array($aOrder, $followIds($sup), true), 'the follow-up list holds approvals still waiting (own ones for an operator, the source\'s for a supervisor), never a cleared one');

// Without a customer telephone number from the shop nobody can confirm the order on the telephone: neither a
// confirmed attempt nor an approval (owner condition 2026-10-22: no approval without customer contact information).
$check($detail($opA, $aOrder)['review']['contactAvailable'] === true, 'an order with a customer telephone number can be confirmed');
$noPhone = $payload($id(80), $submitted(80));
unset($noPhone['order']['delivery']['phone']);
$ok($send('trendhome', $noPhone), 'an order arrives without a customer telephone number');
$ok($act($sup, $th(80), 'reassign', ['expectedVersion' => $version($th(80)), 'employeeId' => $uuids['csopa']]), 'the supervisor gives it to A');
$noPhoneDetail = $detail($opA, $th(80));
$check($noPhoneDetail['review']['contactAvailable'] === false && $noPhoneDetail['customer']['phoneMasked'] === null && $noPhoneDetail['customer']['phoneAvailable'] === false, 'the review states that no telephone number is known');
$error($contact($opA, $th(80), 'confirmed'), 409, 'CONFIRMARE_CONTACT_UNAVAILABLE', 'a confirmed call cannot be recorded without a telephone number');
$check($contactRows($th(80)) === 0, 'and nothing was recorded');
$ok($contact($opA, $th(80), 'needs_correction'), 'the operator can still say the order needs a correction');
$error($act($opA, $th(80), 'approve', $approveBody($opA, $th(80))), 409, 'CONFIRMARE_CONTACT_UNAVAILABLE', 'no approval without a customer telephone number');
$check($one('SELECT status FROM order_confirmations WHERE global_order_id = ?', [$th(80)]) === 'pending', 'the order stays pending, production stays closed');
$ok($act($sup, $th(80), 'reject', ['expectedVersion' => $version($th(80)), 'reasonCode' => 'order_details_wrong']), 'the supervisor rejects it (the shop must add the number)');

// ---- 7. Reopening invalidates earlier confirmations ---------------------------------------------------------------
$rOrder = array_values(array_filter($mineB, static fn (string $g): bool => $g !== $cod))[0];
$ok($contact($opB, $rOrder, 'confirmed'), 'B records a confirmation');
$ok($act($opB, $rOrder, 'reject', ['expectedVersion' => $version($rOrder), 'reasonCode' => 'order_details_wrong']), 'B rejects anyway');
$ok($act($sup, $rOrder, 'reopen', ['expectedVersion' => $version($rOrder)]), 'the supervisor reopens');
$ok($act($sup, $rOrder, 'reassign', ['expectedVersion' => $version($rOrder), 'employeeId' => $uuids['csopb']]), 'and gives it back to B');
$check($detail($opB, $rOrder)['review']['callRecorded'] === false && $detail($opB, $rOrder)['contacts'][0]['current'] === false, 'a confirmation recorded before the reopening no longer counts');
$error($act($opB, $rOrder, 'approve', $approveBody($opB, $rOrder)), 409, 'CONFIRMARE_CALL_NOT_RECORDED', 'the reopened order needs a new call');

// ---- 8. Supervisor: team, leave, alerts, concurrent reassignment --------------------------------------------------
$team = $ok($get($sup, '/confirmare/team'), 'team');
$names = array_column($team['operators'], 'displayName');
sort($names);
$check($names === ['Test csopa', 'Test csopb'] && $team['unassigned'] === ['trendhome' => 0] && $team['undetermined'] === ['trendhome' => 1], 'the supervisor sees only the operators of the supervised source and what waits: ' . json_encode($team));
$check($team['operators'][0]['canWork'] === true && $team['operators'][0]['available'] === true && is_int($team['operators'][0]['open']), 'with whether each can work, is available and how much is open');
$error($get($opA, '/confirmare/team'), 403, 'CONFIRMARE_SCOPE_DENIED', 'an operator never sees the team');
$ok($call($sup, 'PUT', '/confirmare/availability', ['available' => false, 'employeeId' => $uuids['csopb']]), 'B on leave');
$teamLeave = array_column($ok($get($sup, '/confirmare/team'), 'team after leave')['operators'], null, 'displayName');
$check($teamLeave['Test csopb']['available'] === false && $teamLeave['Test csopb']['open'] === 0, 'leave shows in the team view and B\'s orders went back');
$ok($call($sup, 'PUT', '/confirmare/availability', ['available' => true, 'employeeId' => $uuids['csopb']]), 'B is back');
// Two supervisors (here the supervisor and root) reassign the same order on the same version: one wins.
$moving = array_values(array_filter(array_column($ok($get($sup, '/confirmare/pool', ['view' => 'open']), 'open')['items'], 'globalOrderId'), static fn (string $g): bool => $g !== $th(70)))[0];
$v = $version($moving);
$firstMove = $act($sup, $moving, 'reassign', ['expectedVersion' => $v, 'employeeId' => $uuids['csopb']]);
$secondMove = $act($root, $moving, 'reassign', ['expectedVersion' => $v, 'employeeId' => $uuids['csopa']]);
$check(($firstMove['status'] === 200 || ($firstMove['body']['error']['code'] ?? '') === 'CONFIRMARE_CHANGED') && $secondMove['status'] === 409 && ($secondMove['body']['error']['code'] ?? '') === 'CONFIRMARE_CHANGED' && ($secondMove['body']['error']['details']['currentVersion'] ?? null) === $version($moving), 'a concurrent reassignment on the same version gets 409 with the current version to reload');
$stale = array_values(array_filter(array_column($ok($get($opA, '/confirmare/pool', ['view' => 'mine']), 'mine A later')['items'], 'globalOrderId'), static fn (string $g): bool => $g !== $cod))[0];
$pdo->prepare('UPDATE order_confirmations SET updated_at = ? WHERE global_order_id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 3 * 3600), $stale]);
$ok($contact($opA, $stale, 'no_answer'), 'the operator tries to call');
$service->distribute('trendhome');
$alert = array_values(array_filter($ok($get($sup, '/confirmare/alerts'), 'alerts')['items'], static fn (array $a): bool => $a['globalOrderId'] === $stale))[0] ?? null;
$check($alert !== null && $alert['lastContactAt'] !== null && $alert['orderNumber'] === explode(':', $stale)[1], 'the inactivity alert still rises (rule unchanged) and shows the last recorded attempt');

// ---- 9. Append-only, no customer data in the contact table -------------------------------------------------------
$source = (string) file_get_contents(dirname(__DIR__) . '/src/Confirmare/ConfirmareService.php');
$check(preg_match('/(UPDATE|DELETE FROM)\s+order_confirmation_contacts/i', $source) === 0, 'no code path updates or deletes a contact attempt');
$columns = array_column($all("SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_confirmation_contacts'"), 'c');
$check(array_filter($columns, static fn (string $c): bool => preg_match('/phone|name|note|text|duration|recording|email|address/i', $c) === 1 && $c !== 'idempotency_key') === [], 'the contact table has no customer, free-text, duration or recording column: ' . implode(',', $columns));
$check(str_contains(json_encode($all('SELECT * FROM order_confirmation_contacts'), JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '', 'Popescu') === false, 'no customer data is stored with an attempt');
foreach (["UPDATE order_confirmation_contacts SET outcome = 'cancelled' LIMIT 1" => 'an unknown outcome', "INSERT INTO order_confirmation_contacts (contact_uuid, order_uuid, global_order_id, source_key, attempt_number, outcome, employee_uuid, review_fingerprint, confirmation_version, idempotency_key, request_hash, recorded_at) SELECT UUID(), order_uuid, global_order_id, source_key, attempt_number, outcome, employee_uuid, review_fingerprint, confirmation_version, CONCAT(idempotency_key, 'x'), request_hash, recorded_at FROM order_confirmation_contacts LIMIT 1" => 'a second row for the same attempt number'] as $sql => $label) {
    try { $pdo->exec($sql); $refused = false; } catch (PDOException) { $refused = true; }
    $check($refused, "the database refuses {$label}");
}
$check(ConfirmationGate::WORKFLOW_READY === false, 'still no activation is possible');

// Leave the shared disposable database as the next suites expect it: no Confirmare state.
foreach (['order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

fwrite(STDOUT, "PASS MySQL Confirmare Staff integration: {$checks} checks (session scopes, queue without personal data, legacy or other channels, detail lines and customer block, recorded contact attempts, approval needs a recorded confirmation on the current review and a customer telephone number, stale review after a shop change, reopening, payment independence, double submissions, team, leave, concurrent reassignment, alerts, append-only storage).\n");
