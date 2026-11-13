<?php

declare(strict_types=1);

// The Confirmare operating cycle (API 2.46.0, migration 043, owner master decisions 2026-10-25) on a disposable
// database, through the HTTP kernel, with in-process TEST shops (no SmartBill anywhere). Covers: migration 043 additive and
// re-runnable; at most three unanswered calls per cycle and never two within minutes; the third one issues and e-mails the
// advance proforma marked unconfirmed (production stays closed: the telephone confirmation is still required); the
// timer from the real e-mail; reminders at 24 h and 72 h exactly once (worker re-runs, a lost answer reconciled from the
// shop's stored answer, a missed first reminder skipped); the 120 h expiry waits for a bank check covering it and for any
// money in verification; then the proforma is cancelled in SmartBill once and the order's cancellation is requested in the
// shop; an unknown cancellation waits for a manager; a paid advance closes the cycle; the operator's own proforma starts a
// timer too; proformas Arasya never sent (backfill) are never touched; one worker per cycle; the bank check and the
// cycles are shop-scoped; the audit keeps codes only.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\SourceAccess\SourceAccessTransport;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Production\WorkflowCohortActivation;
use Arasya\Operations\Tests\MutableClock;
use Arasya\Operations\Tests\OperationsTestSupport as T;
use Arasya\Operations\Tests\SourceAccessFakeShop;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';
require __DIR__ . '/SourceAccessFakeShop.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL Confirmare cycle integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
$logFile = tempnam(sys_get_temp_dir(), 'arasya-cycle-log-');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

const CYC_KEY = 'trendhome-access-key-test-0123456789abcdef-cyc';
const CYC_PHONE = '0744555666';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($r['body'])); return is_array($r['body']) ? $r['body'] : []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };

final class CycleTestShops implements SourceAccessTransport
{
    /** @param array<string, SourceAccessFakeShop> $shops */
    public function __construct(public array $shops)
    {
    }

    public function post(string $baseUrl, string $path, array $headers, string $body): array
    {
        return $this->shops[$baseUrl]->post($baseUrl, $path, $headers, $body);
    }
}

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on', 'paymentGate' => 'off'];
$base = T::config($dbName, [T::ORIGIN], ['trendhome' => $full, 'outletperdele' => $full]);
$config = new Config(...[...get_object_vars($base), 'sourceAccess' => [
    'trendhome' => ['mode' => 'read', 'url' => 'https://trendhome.example', 'key' => 'th-test:' . CYC_KEY, 'finance' => 'record', 'invoice' => 'enforce'],
    'outletperdele' => ['mode' => 'read', 'url' => 'https://outletperdele.example', 'key' => 'op-test:' . CYC_KEY . '-outlet', 'finance' => 'record', 'invoice' => 'enforce'],
]]);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$all = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };
$cycleTables = ['confirmare_cycle_steps', 'confirmare_proforma_cycles', 'confirmare_bank_checks', 'confirmare_reactivations', 'invoice_efactura_tasks', 'order_source_operations'];
$clear = static function () use ($pdo, $cycleTables): void {
    foreach ([...$cycleTables, 'order_cancellation_sync_attempts', 'order_cancellation_sync_operations', 'order_cancellation_events', 'order_cancellation_controls',
        'order_invoice_qr_checks', 'order_shop_assignments', 'order_invoice_actions', 'order_invoice_reads', 'employee_invoice_scopes',
        'order_finance_actions', 'order_finance_reads', 'employee_finance_scopes', 'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts',
        'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    $pdo->exec('DELETE FROM api_rate_limit_buckets');
    $pdo->exec('DELETE FROM confirmare_finance_holds');
    $pdo->exec("DELETE FROM finance_verification_requests WHERE request_uuid = '00000000-0000-4000-8000-0000000c2101'");
    $pdo->exec("UPDATE operational_orders SET cancellation_state = NULL, production_stopped_at = NULL WHERE cancellation_state IS NOT NULL");
    $pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');
};
$clear();

// ---- 1. Migration 043: additive, re-runnable, fail closed before it ------------------------------------------------------
$check((string) $one("SELECT migration_name FROM schema_migrations WHERE migration_name = '043_confirmare_dual_store.sql'") === '043_confirmare_dual_store.sql', 'migration 043 is applied by the normal migration run');
$check((int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('" . implode("', '", $cycleTables) . "')") === 6, 'migration 043 creates its six tables');
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/043_confirmare_dual_store.sql');
$check((int) $one('SELECT COUNT(*) FROM confirmare_proforma_cycles') === 0 && (int) $one('SELECT COUNT(*) FROM confirmare_bank_checks') === 0, 'running 043 again changes nothing; no row is written by the migration');

$th = new SourceAccessFakeShop('trendhome', 'th-test', CYC_KEY);
$op = new SourceAccessFakeShop('outletperdele', 'op-test', CYC_KEY . '-outlet');
$container = new Container($config, $pdo, new CycleTestShops(['https://trendhome.example' => $th, 'https://outletperdele.example' => $op]));
$kernel = $container->kernel();
$service = $container->confirmareService();
$cycles = $container->confirmareCycles();
$registry = SourceRegistry::fromConfig($config);
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "cyc.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-cyc-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Cycle root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Cycle root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'cyc-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$password = 'cycle test passphrase 2026';
$uuids = [];
$identity = static function (string $name) use ($container, $asRoot, $ok, $password, $suffix, &$uuids): void {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test-cyc');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['staff']]), "{$name} applications");
};
foreach (['cop', 'csup', 'cout', 'cfin', 'cfinview', 'cfinout', 'cmgr'] as $name) $identity($name);
$ok($asRoot('PUT', "/management/employees/{$uuids['cop']}/confirmare-scopes", ['operate' => ['trendhome'], 'supervise' => []]), 'confirmare cop');
$ok($asRoot('PUT', "/management/employees/{$uuids['csup']}/confirmare-scopes", ['operate' => [], 'supervise' => ['trendhome']]), 'confirmare csup');
$ok($asRoot('PUT', "/management/employees/{$uuids['cout']}/confirmare-scopes", ['operate' => ['outletperdele'], 'supervise' => []]), 'confirmare cout');
$ok($asRoot('PUT', "/management/employees/{$uuids['cfin']}/finance-scopes", ['view' => ['trendhome'], 'record' => ['trendhome'], 'approve' => []]), 'finance cfin');
$ok($asRoot('PUT', "/management/employees/{$uuids['cfinview']}/finance-scopes", ['view' => ['trendhome'], 'record' => [], 'approve' => []]), 'finance cfinview');
$ok($asRoot('PUT', "/management/employees/{$uuids['cfinout']}/finance-scopes", ['view' => ['outletperdele'], 'record' => ['outletperdele'], 'approve' => []]), 'finance cfinout');
$ok($asRoot('PUT', "/management/employees/{$uuids['cmgr']}/invoice-scopes", ['issue' => [], 'manage' => ['trendhome']]), 'invoice manager cmgr');
$who = [];
foreach (array_keys($uuids) as $name) $who[$name] = T::login($kernel, "{$name}.{$suffix}", $password);

// ---- Fixtures --------------------------------------------------------------------------------------------------------------
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    fwrite(STDOUT, "SKIP MySQL Confirmare cycle integration: needs a clock after the Confirmare cutover.\n");
    exit(0);
}
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
foreach (['trendhome', 'outletperdele'] as $src) {
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-20 minutes'))))->schedule($registry->find($src), $activatedAt, 'Test Owner', 'cycle test', 'cyc-test', true);
    T::ingest($kernel, $src, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-1 minute'))))->activate($registry->find($src), 'Test Owner', 'cycle test', 'cyc-test', true);
}
$numberBase = T::freeOrderBase($pdo, 930000, 959999, 100);
$id = static fn (int $n): string => (string) ($numberBase + $n);
$g = static fn (int $n, string $src = 'trendhome'): string => $src . ':' . $id($n);
$tick = 0;
$payload = static function (string $orderId, int $seconds) use ($suffix, &$tick, $iso, $activatedAt): array {
    $tick++;
    $p = T::sourceOrder($orderId, "cyc-{$orderId}-{$suffix}-{$tick}", gmdate('Y-m-d\TH:i:s', time() - 4000 + $tick) . '.' . sprintf('%06d', $tick) . 'Z', null, 'processing', 'active', [[
        'id' => 9501, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-303', 'color' => 'Gri', 'width' => 3, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1,
    ]]);
    unset($p['order']['notes']);
    $pricing = 'sha256:' . hash('sha256', "cyc-price-{$orderId}");
    $p['order']['pricing'] = ['fingerprint' => $pricing];
    $p['order']['payment'] = ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0];
    $p['order']['financial'] = ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $pricing];
    $p['order']['delivery'] = ['name' => 'TEST Client Ciclu', 'street' => 'Str. Exemplu 9', 'city' => 'Brasov', 'postalCode' => '500000', 'country' => 'RO', 'phone' => CYC_PHONE];
    $p['order']['submission'] = ['submittedAt' => $iso($activatedAt->modify("{$seconds} seconds")), 'evidence' => 'checkout'];
    return $p;
};
foreach (range(1, 9) as $n) {
    $ok(T::ingest($kernel, 'trendhome', $payload($id($n), 60 + $n)), "trendhome order {$n}");
    // The customer did not confirm anything yet: no telephone acceptance of the total.
    $th->orders[$id($n)] = ['id' => $id($n), 'number' => $id($n), 'eligible' => true, 'phone' => CYC_PHONE, 'pricing' => 'sha256:' . hash('sha256', 'cyc-price-' . $id($n)),
        'invoice' => ['cleared' => false, 'cod' => true, 'totalAccepted' => false]];
}
$ok(T::ingest($kernel, 'outletperdele', $payload($id(50), 120)), 'outlet order');
$op->orders[$id(50)] = ['id' => $id(50), 'number' => $id(50), 'eligible' => true, 'phone' => CYC_PHONE, 'pricing' => 'sha256:' . hash('sha256', 'cyc-price-' . $id(50)), 'invoice' => ['cleared' => false, 'cod' => true, 'totalAccepted' => false]];
$check($service->distribute()['assigned'] >= 10, 'the orders go to their shop\'s operator');
$call = static fn (array $w, string $method, string $path, ?array $body = null, ?string $key = null): array => T::call($kernel, $method, $path, $body,
    ['origin' => T::ORIGIN, 'x-csrf-token' => $w['csrf'], 'idempotency-key' => $key ?? 'cyc-' . bin2hex(random_bytes(10))], $w['cookie']);
$get = static fn (array $w, string $path): array => T::call($kernel, 'GET', $path, null, ['origin' => T::ORIGIN], $w['cookie']);
$version = static fn (string $gid): int => (int) $one('SELECT version FROM order_confirmations WHERE global_order_id = ?', [$gid]);
$detail = static function (array $w, string $gid) use ($get, $ok, $pdo): array { $pdo->exec('DELETE FROM api_rate_limit_buckets'); return $ok($get($w, '/confirmare/orders/' . rawurlencode($gid)), "detail {$gid}"); };
$contact = static function (array $w, string $gid, string $outcome, ?string $key = null) use ($call, $detail, $version, $pdo): array {
    $review = $detail($w, $gid)['review'];
    $pdo->exec('DELETE FROM api_rate_limit_buckets');
    return $call($w, 'POST', '/confirmare/orders/' . rawurlencode($gid) . '/contact', ['expectedVersion' => $version($gid), 'outcome' => $outcome, 'reviewFingerprint' => $review['fingerprint']], $key);
};
$noAnswers = static fn (string $gid): int => (int) $one("SELECT COUNT(*) FROM order_confirmation_contacts WHERE global_order_id = ? AND outcome = 'no_answer'", [$gid]);
$orderUuid = static fn (string $gid): string => (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$gid]);
$cycleOf = static fn (string $gid): ?array => ($r = $all('SELECT * FROM confirmare_proforma_cycles WHERE global_order_id = ? ORDER BY cycle_number DESC LIMIT 1', [$gid])) === [] ? null : $r[0];
// TEST ONLY: the timer is moved into the past instead of waiting days.
$age = static function (string $gid, int $hours) use ($pdo): void {
    $pdo->prepare('UPDATE confirmare_proforma_cycles SET sent_at = DATE_SUB(sent_at, INTERVAL ? HOUR), expires_at = DATE_SUB(expires_at, INTERVAL ? HOUR) WHERE global_order_id = ? AND active_order_uuid IS NOT NULL')->execute([$hours, $hours, $gid]);
};
$run = static fn (): array => $cycles->run(100, 'test-cycles');
$reminders = static fn (int $n): int => $th->smartbillReminders['pf:' . $id($n)] ?? 0;
$cancels = static fn (int $n): int => $th->smartbillCancels['pf:' . $id($n)] ?? 0;
$proformas = static fn (int $n): int => $th->smartbillDocuments['pf:' . $id($n)] ?? 0;

// ---- 2. Three unanswered calls, each recorded as its own real call, with no waiting time between them -------------------
$first = $ok($contact($who['cop'], $g(1), 'no_answer'), 'first unanswered call');
$check(($first['proformaCycle']['state'] ?? null) === 'not_started' && ($first['proformaCycle']['reason'] ?? null) === 'calls_remaining', 'one unanswered call starts nothing: ' . json_encode($first['proformaCycle'] ?? null));
$replayKey = 'cyc-replay-' . bin2hex(random_bytes(6));
$ok($contact($who['cop'], $g(1), 'no_answer', $replayKey), 'second unanswered call right after the first: no waiting time (owner decision 2026-10-25)');
$ok($contact($who['cop'], $g(1), 'no_answer', $replayKey), 'the same request sent again (a double click)');
$check($noAnswers($g(1)) === 2, 'a repeated request returns the stored call: one request is one call, never a second one');
$check($cycleOf($g(1)) === null && $proformas(1) === 0, 'two unanswered calls: no proforma');
$third = $ok($contact($who['cop'], $g(1), 'no_answer'), 'third unanswered call');
$c1 = $cycleOf($g(1));
$check(($third['proformaCycle']['state'] ?? null) === 'running' && $c1 !== null && $c1['origin'] === 'no_answer' && $c1['owner_employee_uuid'] === $uuids['cop'] && $c1['sent_at'] !== null,
    'the third unanswered call issues the advance proforma and e-mails it; the timer runs from the e-mail: ' . json_encode($third['proformaCycle'] ?? null));
$check($proformas(1) === 1 && ($th->smartbillMails['pf:' . $id(1)] ?? 0) === 1 && ($th->orders[$id(1)]['invoice']['pf']['document']['unconfirmed'] ?? null) === true,
    'exactly one proforma, one e-mail; the shop marks the proforma unconfirmed (no telephone confirmation)');
$issueRequest = array_values(array_filter($th->requests, static fn (array $r): bool => ($r['body']['action'] ?? null) === 'proforma_issue'))[0]['body'] ?? [];
$check(($issueRequest['unconfirmed'] ?? null) === true && ($issueRequest['actor']['role'] ?? null) === 'operator' && ($issueRequest['actor']['employeeId'] ?? null) === $uuids['cop'] && !isset($issueRequest['amount']),
    'the issue request names the operator, says unconfirmed and carries no amount (the shop computes it)');
$error($contact($who['cop'], $g(1), 'no_answer'), 409, 'CONFIRMARE_CALL_CYCLE_COMPLETE', 'a fourth unanswered call in the same cycle is refused');
$d1 = $detail($who['cop'], $g(1));
$check(($d1['cycle']['calls'] ?? null) === ['noAnswer' => 3, 'max' => 3, 'complete' => true, 'since' => null] && ($d1['cycle']['proforma']['state'] ?? null) === 'running' && $d1['production']['cleared'] === false,
    'the detail shows the call cycle and the running proforma; production stays closed (no confirmed call, no money): ' . json_encode($d1['cycle']['calls'] ?? null));
$ok($contact($who['cop'], $g(1), 'callback'), 'the customer can still be reached later (callback is recorded)');
$check((int) $one("SELECT COUNT(*) FROM order_confirmation_contacts WHERE global_order_id = ?", [$g(1)]) === 4, 'every attempt is one immutable row');

// ---- 3. Reminders at 24 h and 72 h, exactly once --------------------------------------------------------------------------
$run();
$check($reminders(1) === 0 && $cycleOf($g(1))['reminder1_at'] === null, 'right after the e-mail: no reminder');
$age($g(1), 25);
$run();
$run();
$check($reminders(1) === 1 && $cycleOf($g(1))['reminder1_at'] !== null && $cycleOf($g(1))['reminder2_at'] === null, 'after 24 h: one reminder, even with two worker runs: ' . json_encode([$reminders(1), $cycleOf($g(1)), $all('SELECT step, state, outcome FROM confirmare_cycle_steps WHERE order_uuid = ?', [$orderUuid($g(1))])]));
$remindRequest = array_values(array_filter($th->requests, static fn (array $r): bool => ($r['body']['action'] ?? null) === 'proforma_remind'))[0]['body'] ?? [];
$check(($remindRequest['stage'] ?? null) === 1, 'the first reminder is stage 1');
$age($g(1), 48);
$th->afterInvoice = 'answer_lost';
$run();
$check($reminders(1) === 2 && $cycleOf($g(1))['reminder2_at'] === null && (string) $one("SELECT state FROM confirmare_cycle_steps WHERE cycle_uuid = ? AND step = 'remind_2'", [$c1['cycle_uuid']]) === 'unknown',
    'after 72 h the second reminder reached SmartBill but its answer was lost: the step is unknown');
$run();
$check($reminders(1) === 2 && $cycleOf($g(1))['reminder2_at'] !== null, 'the next run reads the shop\'s stored answer: settled, never sent twice');

// ---- 4. The 120 h expiry waits for the bank check, then cancels once ------------------------------------------------------
$c4 = $cycleOf($g(1));
$check((new DateTimeImmutable((string) $c4['expires_at']))->getTimestamp() - (new DateTimeImmutable((string) $c4['sent_at']))->getTimestamp() === 120 * 3600, 'the proforma expires exactly 120 h after it was e-mailed');
$age($g(1), 46);
$run();
$check($cycleOf($g(1))['state'] === 'running' && $cancels(1) === 0, 'at 119 h the proforma is still running: nothing waits, nothing is cancelled');
$age($g(1), 4);
$run();
$check($cycleOf($g(1))['state'] === 'waiting_bank_check' && $cancels(1) === 0, 'expired, but no bank check covers the expiry: nothing is cancelled');
$error($call($who['cfinview'], 'POST', '/confirmare/finance/bank-check', ['source' => 'trendhome']), 403, 'CONFIRMARE_FINANCE_DENIED', 'a finance viewer does not record the bank check');
$error($call($who['cfinout'], 'POST', '/confirmare/finance/bank-check', ['source' => 'trendhome']), 403, 'CONFIRMARE_FINANCE_DENIED', 'Outlet Perdele finance never records a Trend Home bank check');
$error($call($who['cop'], 'POST', '/confirmare/finance/bank-check', ['source' => 'trendhome']), 403, 'CONFIRMARE_FINANCE_DENIED', 'the operator does not record the bank check');
$error($call($who['cfin'], 'POST', '/confirmare/finance/bank-check', ['source' => 'trendhome', 'coversUntil' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]), 422, 'VALIDATION_FAILED', 'a bank check never covers the future');
$early = $ok($call($who['cfin'], 'POST', '/confirmare/finance/bank-check', ['source' => 'trendhome', 'coversUntil' => gmdate('Y-m-d\TH:i:s\Z', time() - 6 * 86400)]), 'a bank check covering only the past');
$run();
$check($cycleOf($g(1))['state'] === 'waiting_bank_check' && $cancels(1) === 0, 'a bank check that ends before the expiry instant does not count');
// API 2.71.0: a bank check that covers an expired proforma is recorded only with the preview the employee was shown.
$preview = static function (array $w, string $src = 'trendhome', ?string $covers = null) use ($kernel, $pdo): array {
    $pdo->exec('DELETE FROM api_rate_limit_buckets');
    return T::call($kernel, 'GET', '/confirmare/finance/bank-check/preview', null, ['origin' => T::ORIGIN], $w['cookie'], ['source' => $src] + ($covers === null ? [] : ['coversUntil' => $covers]));
};
$checksBefore = (int) $one("SELECT COUNT(*) FROM confirmare_bank_checks WHERE source_key = 'trendhome'");
$error($call($who['cfin'], 'POST', '/confirmare/finance/bank-check', ['source' => 'trendhome']), 409, 'CONFIRMARE_BANK_CHECK_PREVIEW_REQUIRED', 'a bank check covering an expired proforma without its preview is refused');
$check((int) $one("SELECT COUNT(*) FROM confirmare_bank_checks WHERE source_key = 'trendhome'") === $checksBefore, 'nothing is recorded without the preview');
$pv = $ok($preview($who['cfin']), 'finance previews the bank check');
$pvOne = array_values(array_filter($pv['items'], static fn (array $i): bool => $i['orderNumber'] === $id(1)));
$check(count($pvOne) === 1 && $pvOne[0]['outcome'] === 'may_cancel' && $pvOne[0]['waitsFor'] === null && $pv['mayCancel'] >= 1 && is_string($pv['fingerprint']) && strlen($pv['fingerprint']) === 64,
    'the preview names the expired order it may cancel: ' . json_encode($pv));
$bankKey = 'cyc-bank-' . bin2hex(random_bytes(8));
$bankBody = ['source' => 'trendhome', 'coversUntil' => $pv['coversUntil'], 'previewFingerprint' => $pv['fingerprint']];
$bank = $ok($call($who['cfin'], 'POST', '/confirmare/finance/bank-check', $bankBody, $bankKey), 'bank check up to now, with the preview it was shown');
$again = $ok($call($who['cfin'], 'POST', '/confirmare/finance/bank-check', $bankBody, $bankKey), 'the same bank check again (retry)');
$check($again['check'] === $bank['check'] && (int) $one("SELECT COUNT(*) FROM confirmare_bank_checks WHERE source_key = 'trendhome'") === 2, 'a retried bank check is recorded once');
$run();
$c1 = $cycleOf($g(1));
$control = $all('SELECT hold_status, origin, source_request_status FROM order_cancellation_controls WHERE order_uuid = ?', [$orderUuid($g(1))]);
$sync = $all('SELECT mechanism, state FROM order_cancellation_sync_operations WHERE order_uuid = ?', [$orderUuid($g(1))]);
$check($c1['state'] === 'cancelled' && $c1['closed_reason'] === 'proforma_expired' && $cancels(1) === 1 && $c1['bank_check_uuid'] !== null,
    'with the bank check: the proforma is cancelled in SmartBill once, the cycle is closed: ' . json_encode($c1));
$check($control !== [] && $control[0]['hold_status'] === 'held' && $control[0]['source_request_status'] === 'pending' && count($sync) === 1,
    'the order\'s cancellation in the shop is requested once through the cancellation sync: ' . json_encode([$control, $sync]));
$cancelRequest = array_values(array_filter($th->requests, static fn (array $r): bool => ($r['body']['action'] ?? null) === 'proforma_cancel'))[0]['body'] ?? [];
$check(($cancelRequest['reasonCode'] ?? null) === 'expired_unpaid' && ($cancelRequest['confirmNoPayment'] ?? null) === true, 'the cancellation names the expiry and states no payment');
$run();
$check($cancels(1) === 1 && count($all('SELECT 1 FROM order_cancellation_sync_operations WHERE order_uuid = ?', [$orderUuid($g(1))])) === 1, 'running again cancels nothing more');

// ---- 5. Money in verification or received: never cancelled ---------------------------------------------------------------
foreach ([2, 3] as $n) {
    $contact($who['cop'], $g($n), 'no_answer'); $contact($who['cop'], $g($n), 'no_answer');
    $ok($contact($who['cop'], $g($n), 'no_answer'), "third call {$n}");
    $check($cycleOf($g($n))['state'] === 'running', "order {$n}: proforma cycle running");
}
$age($g(2), 121);
$th->orders[$id(2)]['invoice']['verifying'] = true;
$run();
$check($cycleOf($g(2))['state'] === 'waiting_finance' && $cancels(2) === 0, 'a transfer being verified: the expired proforma waits (finance decides), nothing cancelled');
$th->orders[$id(2)]['invoice']['verifying'] = false;
$th->orders[$id(2)]['invoice']['cleared'] = true;
$run();
$check($cycleOf($g(2))['state'] === 'paid' && $cycleOf($g(2))['active_order_uuid'] === null && $cancels(2) === 0, 'the verified advance closes the cycle as paid');
// The money arrives between the worker's read and SmartBill: the shop refuses under its finance lock.
$age($g(3), 121);
$th->during = static function (array $input) use ($th, $id): void {
    if (($input['action'] ?? null) === 'proforma_cancel' && ($input['orderId'] ?? null) === $id(3)) {
        $th->orders[$id(3)]['invoice']['verifying'] = true;
    }
};
$run();
$th->during = null;
$check($cancels(3) === 0 && $all('SELECT 1 FROM order_cancellation_controls WHERE order_uuid = ?', [$orderUuid($g(3))]) === [] && $all("SELECT 1 FROM confirmare_cycle_steps WHERE order_uuid = ? AND step = 'expire_cancel'", [$orderUuid($g(3))]) === [],
    'a transfer recorded while the cancellation is asked: the shop refuses before SmartBill (revision changed), nothing is cancelled, the step is forgotten');
$run();
$check($cycleOf($g(3))['state'] === 'waiting_finance' && $cancels(3) === 0, 'the next run sees the transfer in verification: the proforma waits for finance');
$th->orders[$id(3)]['invoice']['verifying'] = false;
$run();
$check($cycleOf($g(3))['state'] === 'cancelled' && $cancels(3) === 1, 'the transfer was rejected by finance (no money): the next run cancels with a new attempt');

// ---- 6. An unknown cancellation waits for a manager ----------------------------------------------------------------------
$contact($who['cop'], $g(4), 'no_answer'); $contact($who['cop'], $g(4), 'no_answer');
$ok($contact($who['cop'], $g(4), 'no_answer'), 'third call 4');
$age($g(4), 121);
$th->proformaCancelOutcome = 'unknown';
$run();
$check($cycleOf($g(4))['state'] === 'cancel_unknown' && $cancels(4) === 1, 'SmartBill did not answer the cancellation: unknown');
$run();
$check($cancels(4) === 1 && $cycleOf($g(4))['state'] === 'cancel_unknown', 'an unknown cancellation is never sent again by the worker');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$revision = $ok($get($who['cmgr'], '/confirmare/orders/' . rawurlencode($g(4)) . '/invoice'), 'manager reads')['summary']['revision'];
$ok($call($who['cmgr'], 'POST', '/confirmare/orders/' . rawurlencode($g(4)) . '/proforma/resolve', ['expectedRevision' => $revision, 'target' => 'cancel', 'resolution' => 'cancelled', 'reasonNote' => 'Verificat în SmartBill: proforma este anulată.']), 'the manager checked SmartBill: cancelled');
$run();
$check($cycleOf($g(4))['state'] === 'cancelled' && count($all('SELECT 1 FROM order_cancellation_sync_operations WHERE order_uuid = ?', [$orderUuid($g(4))])) === 1, 'after the manager\'s resolution the order\'s cancellation is requested');

// ---- 7. A missed first reminder is skipped; a refused reminder is not repeated --------------------------------------------
$contact($who['cop'], $g(5), 'no_answer'); $contact($who['cop'], $g(5), 'no_answer');
$ok($contact($who['cop'], $g(5), 'no_answer'), 'third call 5');
$age($g(5), 80);
$run();
$check($reminders(5) === 1 && (string) $one("SELECT state FROM confirmare_cycle_steps WHERE cycle_uuid = ? AND step = 'remind_1'", [$cycleOf($g(5))['cycle_uuid']]) === 'skipped', 'the worker was down past 72 h: only the second reminder is sent, the first is recorded as skipped');
$contact($who['cop'], $g(6), 'no_answer'); $contact($who['cop'], $g(6), 'no_answer');
$ok($contact($who['cop'], $g(6), 'no_answer'), 'third call 6');
$age($g(6), 25);
$th->reminderOutcome = 'refused';
$run();
$run();
$check($reminders(6) === 0 && $cycleOf($g(6))['reminder1_at'] !== null && $cycleOf($g(6))['last_error'] === 'mail_refused', 'a reminder SmartBill refused is recorded, not repeated');

// ---- 8. One worker per cycle ----------------------------------------------------------------------------------------------
$c6 = $cycleOf($g(6));
$pdo->prepare('UPDATE confirmare_proforma_cycles SET claim_token = ?, claimed_at = UTC_TIMESTAMP(6) WHERE cycle_uuid = ?')->execute(['00000000-0000-4000-8000-00000000c1a1', $c6['cycle_uuid']]);
$check($cycles->advance((string) $c6['cycle_uuid'], 'test-second-worker') === null, 'a cycle held by another worker is skipped');
$pdo->prepare('UPDATE confirmare_proforma_cycles SET claimed_at = DATE_SUB(claimed_at, INTERVAL 10 MINUTE) WHERE cycle_uuid = ?')->execute([$c6['cycle_uuid']]);
$check($cycles->advance((string) $c6['cycle_uuid'], 'test-second-worker') !== null, 'a claim older than five minutes (a dead worker) is taken over');

// ---- 9. The operator's own proforma starts a timer; the backfill never does -----------------------------------------------
$th->orders[$id(7)]['invoice']['totalAccepted'] = true;
$review = $detail($who['cop'], $g(7))['review'];
$ok($call($who['cop'], 'POST', '/confirmare/orders/' . rawurlencode($g(7)) . '/contact', ['expectedVersion' => $version($g(7)), 'outcome' => 'confirmed', 'reviewFingerprint' => $review['fingerprint']]), 'confirmed call 7');
$ok($get($who['cop'], '/confirmare/orders/' . rawurlencode($g(7)) . '/source'), 'shop order 7');
$ok($call($who['cop'], 'POST', '/confirmare/orders/' . rawurlencode($g(7)) . '/approve', ['expectedVersion' => $version($g(7)), 'customerCall' => 'confirmed', 'confirmedLineIds' => $review['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $review['fingerprint']]), 'approve 7');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$rev = $ok($get($who['cop'], '/confirmare/orders/' . rawurlencode($g(7)) . '/invoice'), 'operator reads 7')['summary']['revision'];
$ok($call($who['cop'], 'POST', '/confirmare/orders/' . rawurlencode($g(7)) . '/proforma/issue', ['expectedRevision' => $rev, 'replaceOutdated' => false]), 'operator issues the proforma');
$check($cycleOf($g(7)) === null, 'issuing alone starts no timer (the customer has not received it)');
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$rev = $ok($get($who['cop'], '/confirmare/orders/' . rawurlencode($g(7)) . '/invoice'), 'operator reads 7 again')['summary']['revision'];
$ok($call($who['cop'], 'POST', '/confirmare/orders/' . rawurlencode($g(7)) . '/proforma/send-mail', ['expectedRevision' => $rev, 'confirmResend' => false]), 'operator e-mails the proforma');
$c7 = $cycleOf($g(7));
$check($c7 !== null && $c7['origin'] === 'operator' && $c7['state'] === 'running' && $c7['sent_at'] !== null, 'the operator\'s e-mailed proforma runs the same timer');
// A proforma that existed in the shop before Arasya sent anything (22 October backfill): no cycle, never cancelled.
$th->orders[$id(8)]['invoice']['pf']['document'] = ['series' => 'PRF', 'number' => '0999', 'at' => time() - 9 * 86400, 'by' => $uuids['cop']];
$th->orders[$id(8)]['invoice']['pf']['mail'] = 'sent';
$th->orders[$id(8)]['invoice'] += ['cleared' => false];
$run();
$check($cycleOf($g(8)) === null && $cancels(8) === 0, 'an old proforma Arasya never sent has no timer: no mass cancellation');

// ---- 10. Shop scope, access, audit ----------------------------------------------------------------------------------------
$check($ok($get($who['cout'], '/confirmare/cycles'), 'outlet operator cycles')['items'] === [], 'the Outlet Perdele operator sees no Trend Home cycle');
$thItems = $ok($get($who['cop'], '/confirmare/cycles'), 'trend home operator cycles')['items'];
$check($thItems !== [] && array_unique(array_map(static fn (array $i): string => explode(':', $i['globalOrderId'])[0], $thItems)) === ['trendhome'], 'the Trend Home operator sees the Trend Home cycles');
$error($call($who['cout'], 'POST', '/confirmare/orders/' . rawurlencode($g(6)) . '/cycle/advance'), 404, 'CONFIRMARE_NOT_FOUND', 'another shop\'s operator cannot run a cycle');
$checksView = $ok(T::call($kernel, 'GET', '/confirmare/finance/bank-checks', null, ['origin' => T::ORIGIN], $who['cfinview']['cookie'], ['source' => 'trendhome']), 'finance viewer bank checks');
$check(count($checksView['items']) === 2 && $checksView['canRecord'] === [], 'a finance viewer sees the bank checks, cannot record');
$error(T::call($kernel, 'GET', '/confirmare/finance/bank-checks', null, ['origin' => T::ORIGIN], $who['cop']['cookie'], ['source' => 'outletperdele']), 403, 'CONFIRMARE_FINANCE_DENIED', 'an operator without finance or supervision sees no bank check');
$columns = array_column($all("SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('confirmare_proforma_cycles', 'confirmare_cycle_steps', 'confirmare_bank_checks')"), 'c');
$check(array_intersect($columns, ['amount', 'series', 'number', 'iban', 'email', 'phone', 'customer_name']) === [], 'no amount, document number, bank account or customer field is stored');
$check(!str_contains((string) file_get_contents($logFile), CYC_PHONE), 'the telephone number never reaches the log');

// ---- 11. R1-R5 (2026-10-26): 429 Retry-After, retry after a definite refusal, unknown overdue, failed cycles ----------------
foreach ([11, 12, 13, 14, 15] as $n) {
    $ok(T::ingest($kernel, 'trendhome', $payload($id($n), 200 + $n)), "trendhome order {$n}");
    $th->orders[$id($n)] = ['id' => $id($n), 'number' => $id($n), 'eligible' => true, 'phone' => CYC_PHONE, 'pricing' => 'sha256:' . hash('sha256', 'cyc-price-' . $id($n)),
        'invoice' => ['cleared' => false, 'cod' => true, 'totalAccepted' => false]];
}
$service->distribute();
$three = static function (int $n) use ($contact, $who, $g): void { $contact($who['cop'], $g($n), 'no_answer'); $contact($who['cop'], $g($n), 'no_answer'); $contact($who['cop'], $g($n), 'no_answer'); };
$retry = static fn (array $w, int $n): array => $call($w, 'POST', '/confirmare/orders/' . rawurlencode($g($n)) . '/cycle/retry');
// TEST ONLY: the Retry-After wait passes (the stored instant moves back; nothing waits).
$waited = static function (int $n) use ($pdo, $g): void { $pdo->prepare('UPDATE confirmare_proforma_cycles SET updated_at = DATE_SUB(updated_at, INTERVAL 301 SECOND) WHERE global_order_id = ? AND active_order_uuid IS NOT NULL')->execute([$g($n)]); };
// R3: SmartBill answers 429 once: nothing was made, the step waits Retry-After, then succeeds under a new key.
$th->proformaRateLimits = 1;
$three(11);
$c = $cycleOf($g(11));
$check($c['state'] === 'issue_pending' && $c['last_error'] === 'rate_limited' && $proformas(11) === 0, 'R3: a 429 on the issue (Retry-After 45 s): nothing made, the cycle waits 300 s: ' . json_encode([$c['state'], $c['last_error']]));
$check(($detail($who['cop'], $g(11))['cycle']['proforma']['lastError'] ?? null) === 'rate_limited', 'R3: Staff is shown rate_limited');
$run();
$check($cycleOf($g(11))['state'] === 'issue_pending' && $proformas(11) === 0, 'R3: before Retry-After nothing is sent to SmartBill');
$waited(11);
$run();
$c = $cycleOf($g(11));
$check($c['state'] === 'running' && $proformas(11) === 1 && ($th->smartbillMails['pf:' . $id(11)] ?? 0) === 1 && $c['last_error'] === null, 'R3: after Retry-After the issue is asked again: ONE proforma, ONE e-mail, the timer runs');
// R3: at most three retries, then the cycle closes as rate-limited (never an endless loop).
$th->proformaRateLimits = 4;
$three(12);
foreach ([1, 2, 3] as $_) { $waited(12); $run(); }
$c = $cycleOf($g(12));
$check($c['state'] === 'closed' && $c['closed_reason'] === 'smartbill_rate_limited' && $proformas(12) === 0 && $th->proformaRateLimits === 0, 'R3: four 429 answers: three retries, then closed as smartbill_rate_limited, nothing made: ' . json_encode([$c['state'], $c['closed_reason'], $c['attempts']]));
// R1: the operator starts it again: no new call, the shop's proforma is read first, ONE proforma.
$error($retry($who['cout'], 12), 404, 'CONFIRMARE_NOT_FOUND', 'R1: another shop\'s operator cannot start it again');
$error($retry($who['cfinview'], 12), 404, 'CONFIRMARE_NOT_FOUND', 'R1: a finance viewer cannot start it again');
$again = $ok($retry($who['cop'], 12), 'R1: the operator starts the advance proforma again');
$c = $cycleOf($g(12));
$check(($again['proformaCycle']['cycle'] ?? null) === 2 && $c['state'] === 'running' && $proformas(12) === 1 && $noAnswers($g(12)) === 3 && $c['owner_employee_uuid'] === $uuids['cop'],
    'R1: the retry issues ONE unconfirmed proforma and e-mails it, without a new call; the cycle row names who retried: ' . json_encode($again['proformaCycle'] ?? null));
$retryIssue = array_values(array_filter($th->requests, static fn (array $r): bool => ($r['body']['action'] ?? null) === 'proforma_issue' && ($r['body']['orderId'] ?? null) === $id(12)));
$check(($retryIssue[array_key_last($retryIssue)]['body']['unconfirmed'] ?? null) === true, 'R1: the retried proforma is still marked unconfirmed (no telephone confirmation)');
$error($retry($who['cop'], 12), 409, 'CONFIRMARE_CYCLE_RETRY_REFUSED', 'R1: a second retry while one runs is refused (double click)');
// R1: three cycles per call set at most.
$th->proformaOutcome = 'refused';
$three(13);
$th->proformaOutcome = 'refused';
$ok($retry($who['csup'], 13), 'R1: a supervisor may start it again (refused again)');
$th->proformaOutcome = 'refused';
$ok($retry($who['cop'], 13), 'R1: third cycle (refused again)');
$limit = $error($retry($who['cop'], 13), 409, 'CONFIRMARE_CYCLE_RETRY_REFUSED', 'R1: a fourth cycle for the same three calls is refused');
$check(($limit['error']['details']['reason'] ?? null) === 'retry_limit' && $proformas(13) === 0, 'R1: the refusal says retry_limit; nothing was made in SmartBill');
// R2: the proforma exists, the e-mail was refused: the retry re-sends the SAME proforma.
$th->proformaMailOutcome = 'refused';
$three(14);
$c = $cycleOf($g(14));
$check($c['state'] === 'closed' && $c['closed_reason'] === 'mail_refused' && $proformas(14) === 1 && ($th->smartbillMails['pf:' . $id(14)] ?? 0) === 0, 'R2: one proforma, the e-mail refused: closed as mail_refused, no timer');
$ok($retry($who['cop'], 14), 'R2: the operator starts it again');
$c = $cycleOf($g(14));
$check($c['state'] === 'running' && $proformas(14) === 1 && ($th->smartbillMails['pf:' . $id(14)] ?? 0) === 1, 'R2: the SAME proforma is e-mailed (no second document); the timer runs');
// R2/R3: an e-mail rate-limited once is sent again after Retry-After.
// R4: an unknown issue is never retried; it is counted overdue for a manager after 30 minutes.
$th->proformaOutcome = 'unknown';
$three(15);
$check($cycleOf($g(15))['last_error'] === 'issue_unknown' && $proformas(15) === 1, 'R4: the issue outcome is unknown (SmartBill may have made it)');
$unknownRetry = $error($retry($who['cop'], 15), 409, 'CONFIRMARE_CYCLE_RETRY_REFUSED', 'R4: an unknown outcome cannot be started again');
$check(($unknownRetry['error']['details']['reason'] ?? null) === 'cycle_exists', 'R4: the open unknown cycle stays the only one (cycle_exists)');
$run();
$check($proformas(15) === 1, 'R4: the worker never asks SmartBill again for an unknown issue');
$health = static fn (): array => $ok(T::call($kernel, 'GET', '/confirmare/health', null, ['origin' => T::ORIGIN], $who['csup']['cookie'], ['source' => 'trendhome']), 'supervisor health');
$h = $health();
$check(($h['sources']['trendhome']['proformaUnknownOverdue'] ?? null) === 0, 'R4: a fresh unknown outcome is not overdue yet');
$pdo->prepare('UPDATE confirmare_proforma_cycles SET updated_at = DATE_SUB(updated_at, INTERVAL 31 MINUTE) WHERE global_order_id = ?')->execute([$g(15)]);
$h = $health();
$check(($h['sources']['trendhome']['proformaUnknownOverdue'] ?? null) === 1, 'R4: after 30 minutes the supervisor\'s health counts it overdue: ' . json_encode($h['sources']['trendhome']['proformaUnknownOverdue'] ?? null));
// R5: cycles closed by a refusal in the last 7 days are counted even though nothing is open.
$byReason = $h['sources']['trendhome']['proformaFailed7dByReason'] ?? null;
$check(($h['sources']['trendhome']['proformaFailed7d'] ?? null) === 5 && $byReason == ['mail_refused' => 1, 'smartbill_rate_limited' => 1, 'smartbill_refused' => 3],
    'R5: 5 failed cycles in 7 days by reason (rate-limited 1, refused 3, e-mail refused 1): ' . json_encode([$h['sources']['trendhome']['proformaFailed7d'] ?? null, $byReason]));
// Nothing is retried that is not a definite refusal: a paid or cancelled cycle.
$error($retry($who['cop'], 2), 409, 'CONFIRMARE_CYCLE_RETRY_REFUSED', 'R1: a paid order is never started again');
// R3: a Retry-After longer than the wait is not retried automatically.
$ok(T::ingest($kernel, 'trendhome', $payload($id(16), 216)), 'trendhome order 16');
$th->orders[$id(16)] = ['id' => $id(16), 'number' => $id(16), 'eligible' => true, 'phone' => CYC_PHONE, 'pricing' => 'sha256:' . hash('sha256', 'cyc-price-' . $id(16)), 'invoice' => ['cleared' => false, 'cod' => true, 'totalAccepted' => false]];
$service->distribute();
$th->proformaRateLimits = 1;
$th->rateLimitRetryAfter = 900;
$three(16);
$c = $cycleOf($g(16));
$check($c['state'] === 'closed' && $c['closed_reason'] === 'smartbill_rate_limited' && $proformas(16) === 0, 'R3: Retry-After 900 s (longer than the 300 s wait): no automatic retry, closed as rate-limited');
$th->rateLimitRetryAfter = 45;


// ---- 12. API 2.71.0 finance safety (owner decision 2026-11-13): open finance task, finance hold, bank check preview ------
foreach ([21, 22, 23, 24] as $n) {
    $ok(T::ingest($kernel, 'trendhome', $payload($id($n), 300 + $n)), "trendhome order {$n}");
    $th->orders[$id($n)] = ['id' => $id($n), 'number' => $id($n), 'eligible' => true, 'phone' => CYC_PHONE, 'pricing' => 'sha256:' . hash('sha256', 'cyc-price-' . $id($n)),
        'invoice' => ['cleared' => false, 'cod' => true, 'totalAccepted' => false]];
}
$service->distribute();
foreach (['cfinapp', 'csup2'] as $name) $identity($name);
$ok($asRoot('PUT', "/management/employees/{$uuids['cfinapp']}/finance-scopes", ['view' => ['trendhome'], 'record' => [], 'approve' => ['trendhome']]), 'finance approver cfinapp');
$ok($asRoot('PUT', "/management/employees/{$uuids['csup2']}/confirmare-scopes", ['operate' => [], 'supervise' => ['trendhome']]), 'confirmare csup2');
foreach (['cfinapp', 'csup2'] as $name) $who[$name] = T::login($kernel, "{$name}.{$suffix}", $password);
foreach ([21, 22, 23] as $n) { $three($n); $check($cycleOf($g($n))['state'] === 'running', "S12 order {$n}: proforma running"); }
// Order 21: finance is still looking for the money (an open, assigned finance task). TEST fixture: the task row as the
// operator's /finance-request writes it; the real request is driven end to end by the yd-soft proforma smoke.
$pdo->prepare("INSERT INTO finance_verification_requests (request_uuid, order_uuid, global_order_id, source_key, purpose, state, version, open_order_uuid, assigned_employee_uuid, assigned_at,
        requested_by_employee_uuid, requested_at, request_idempotency_key, ledger, proforma_series, proforma_number, updated_at)
    VALUES (?, ?, ?, 'trendhome', 'advance', 'assigned', 1, ?, ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), ?, 'shop', 'ARS', ?, UTC_TIMESTAMP(6))")
    ->execute(['00000000-0000-4000-8000-0000000c2101', $orderUuid($g(21)), $g(21), $orderUuid($g(21)), $uuids['cfin'], $uuids['cop'], 'cyc-task-' . $suffix, 'pf-' . $id(21)]);
// Order 22: the customer paid by bank transfer before any approval; finance cannot record it on the order (no task), so it
// places a finance hold.
$holds = static fn (array $w, array $body, ?string $key = null): array => $call($w, 'POST', '/confirmare/finance/holds', $body, $key);
$holdBody = static fn (int $n, string $reason = 'early_transfer'): array => ['source' => 'trendhome', 'orderNumber' => $id($n), 'reasonCode' => $reason, 'note' => 'Transfer vazut in extras, comanda neaprobata (TEST).'];
$error($holds($who['cfinview'], $holdBody(22)), 404, 'CONFIRMARE_NOT_FOUND', 'S12: a finance viewer cannot place a hold (nothing disclosed)');
$error($holds($who['cout'], $holdBody(22)), 404, 'CONFIRMARE_NOT_FOUND', 'S12: another shop\'s operator cannot place a hold');
$error($holds($who['cfinout'], $holdBody(22)), 404, 'CONFIRMARE_NOT_FOUND', 'S12: Outlet Perdele finance cannot place a Trend Home hold');
$error($holds($who['cfin'], ['note' => 'x'] + $holdBody(22)), 422, 'VALIDATION_FAILED', 'S12: a hold needs a reason note');
$error($holds($who['cfin'], ['reasonCode' => 'because'] + $holdBody(22)), 422, 'VALIDATION_FAILED', 'S12: a hold needs a known reason code');
$error($holds($who['cfin'], $holdBody(22) + ['amount' => '300.00']), 400, 'INVALID_REQUEST', 'S12: a hold carries no amount (it records no money)');
$error($holds($who['cfin'], ['orderNumber' => '99999999'] + $holdBody(22)), 404, 'CONFIRMARE_NOT_FOUND', 'S12: an unknown order number');
$error($call($who['cfin'], 'POST', '/confirmare/finance/holds', $holdBody(22), 'short'), 400, 'INVALID_IDEMPOTENCY_KEY', 'S12: a hold needs an idempotency key');
$audits = static fn (string $event): int => (int) $one('SELECT COUNT(*) FROM auth_audit_events WHERE event_type = ?', [$event]);
$placedAudits = $audits('confirmare.finance_hold.placed');
$releasedAudits = $audits('confirmare.finance_hold.released');
$holdKey = 'cyc-hold-' . bin2hex(random_bytes(8));
$placed = $ok($holds($who['cfin'], $holdBody(22), $holdKey), 'S12: finance places a hold on order 22');
$check(($placed['created'] ?? null) === true && $placed['hold']['open'] === true && $placed['hold']['placedRole'] === 'finance' && $placed['hold']['reasonCode'] === 'early_transfer' && $placed['hold']['orderNumber'] === $id(22),
    'S12: the hold names who, why and the order: ' . json_encode($placed['hold']));
$replayHold = $ok($holds($who['cfin'], $holdBody(22), $holdKey), 'S12: the same hold request again');
$second = $ok($holds($who['csup'], $holdBody(22, 'customer_reports_payment')), 'S12: a supervisor asks to hold the same order');
$check($replayHold['created'] === false && $replayHold['hold']['id'] === $placed['hold']['id'] && $second['created'] === false && $second['hold']['id'] === $placed['hold']['id']
    && (int) $one('SELECT COUNT(*) FROM confirmare_finance_holds WHERE order_uuid = ?', [$orderUuid($g(22))]) === 1, 'S12: one open hold per order (retries and a second person get the same hold)');
$check($audits('confirmare.finance_hold.placed') === $placedAudits + 1, 'S12: the hold is audited once');
$check((int) $one('SELECT COUNT(*) FROM order_finance_actions WHERE order_uuid = ?', [$orderUuid($g(22))]) === 0, 'S12: the hold records no money on the order');
$check($detail($who['cop'], $g(22))['production']['cleared'] === false, 'S12: the held order stays closed for production');
// All three proformas expire.
foreach ([21, 22, 23] as $n) $age($g($n), 121);
$error($preview($who['cop']), 403, 'CONFIRMARE_FINANCE_DENIED', 'S12: an operator does not preview the bank check');
$error($preview($who['cfinout']), 403, 'CONFIRMARE_FINANCE_DENIED', 'S12: Outlet Perdele finance does not preview a Trend Home bank check');
// Only finance `record` / `approve` of the shop sees the order numbers of the preview (owner 2026-11-13).
$error($preview($who['cfinview']), 403, 'CONFIRMARE_FINANCE_DENIED', 'S12: a finance viewer (no record or approve) does not preview the bank check');
$error($preview($who['csup']), 403, 'CONFIRMARE_FINANCE_DENIED', 'S12: a Confirmare supervisor without finance scopes does not preview the bank check');
$error($preview($who['cout']), 403, 'CONFIRMARE_FINANCE_DENIED', 'S12: another shop\'s operator does not preview the bank check');
$error($preview($root), 403, 'CONFIRMARE_FINANCE_DENIED', 'S12: root does not preview the bank check');
$pv = $ok($preview($who['cfin']), 'S12: finance previews the bank check');
$check($pv['items'] !== [] && array_filter($pv['items'], static fn (array $i): bool => array_keys($i) !== ['orderNumber', 'globalOrderId', 'expiresAt', 'state', 'outcome', 'waitsFor']) === [],
    'S12: a preview item names the order, its expiry and outcome only (no customer, amount or product): ' . json_encode($pv['items'][0] ?? null));
$outcome = static function (array $pv, int $n) use ($id): ?array { foreach ($pv['items'] as $i) { if ($i['orderNumber'] === $id($n)) return [$i['outcome'], $i['waitsFor']]; } return null; };
$check($outcome($pv, 21) === ['waits', 'finance_task_open'] && $outcome($pv, 22) === ['waits', 'finance_hold'] && $outcome($pv, 23) === ['may_cancel', null],
    'S12: the preview shows which orders may be cancelled and which wait (task, hold): ' . json_encode($pv['items']));
$checksBefore = (int) $one("SELECT COUNT(*) FROM confirmare_bank_checks WHERE source_key = 'trendhome'");
$refused = $error($call($who['cfin'], 'POST', '/confirmare/finance/bank-check', ['source' => 'trendhome', 'coversUntil' => $pv['coversUntil']]), 409, 'CONFIRMARE_BANK_CHECK_PREVIEW_REQUIRED', 'S12: no bank check without the preview');
$check(array_keys($refused['error']['details'] ?? []) === ['mayCancel', 'waiting'] && !str_contains(json_encode($refused['error']['details']), $id(23)),
    'S12: the refusal carries counts only, never an order number: ' . json_encode($refused['error']['details'] ?? null));
$error($call($who['cfin'], 'POST', '/confirmare/finance/bank-check', ['source' => 'trendhome', 'coversUntil' => $pv['coversUntil'], 'previewFingerprint' => str_repeat('0', 64)]), 409, 'CONFIRMARE_BANK_CHECK_PREVIEW_STALE', 'S12: no bank check with another preview');
$check((int) $one("SELECT COUNT(*) FROM confirmare_bank_checks WHERE source_key = 'trendhome'") === $checksBefore, 'S12: a refused bank check records nothing');
$ok($call($who['cfin'], 'POST', '/confirmare/finance/bank-check', ['source' => 'trendhome', 'coversUntil' => $pv['coversUntil'], 'previewFingerprint' => $pv['fingerprint']]), 'S12: the bank check with the preview it was shown');
$run();
$controls = static fn (int $n): int => (int) $one('SELECT COUNT(*) FROM order_cancellation_controls WHERE order_uuid = ?', [$orderUuid($g($n))]);
$c21 = $cycleOf($g(21)); $c22 = $cycleOf($g(22)); $c23 = $cycleOf($g(23));
$check($c21['state'] === 'waiting_finance' && $c21['last_error'] === 'finance_task_open' && $cancels(21) === 0 && $controls(21) === 0,
    'S12: an open finance task: the expired proforma waits, nothing is cancelled, the order is not stopped: ' . json_encode([$c21['state'], $c21['last_error']]));
$check($c22['state'] === 'waiting_finance' && $c22['last_error'] === 'finance_hold' && $cancels(22) === 0 && $controls(22) === 0,
    'S12: a finance hold: the expired proforma waits, nothing is cancelled, the order is not stopped: ' . json_encode([$c22['state'], $c22['last_error']]));
$check($c23['state'] === 'cancelled' && $c23['closed_reason'] === 'proforma_expired' && $cancels(23) === 1 && $controls(23) === 1, 'S12: control: without a task or a hold the expiry still cancels as before (K-05)');
$v21 = (int) $cycleOf($g(21))['version'];
$run(); $run();
$check($cycleOf($g(21))['state'] === 'waiting_finance' && (int) $cycleOf($g(21))['version'] === $v21 && $cancels(21) === 0 && $cancels(22) === 0, 'S12: further worker runs change nothing (no churn, still nothing cancelled)');
$att = array_column($ok($get($who['csup'], '/confirmare/cycles'), 'S12 supervisor cycles')['items'], 'waitsFor', 'orderNumber');
$check(($att[$id(21)] ?? null) === 'finance_task_open' && ($att[$id(22)] ?? null) === 'finance_hold', 'S12: the supervisor\'s cycle list says what each waits for');
// Review list: nothing open is forgotten.
$review = static function (array $w) use ($kernel, $pdo): array { $pdo->exec('DELETE FROM api_rate_limit_buckets'); return T::call($kernel, 'GET', '/confirmare/finance/holds', null, ['origin' => T::ORIGIN], $w['cookie'], ['source' => 'trendhome']); };
$error($review($who['cop']), 403, 'CONFIRMARE_HOLD_DENIED', 'S12: an operator has no review list');
$error($review($who['cfinview']), 403, 'CONFIRMARE_HOLD_DENIED', 'S12: a finance viewer has no review list');
$r = $ok($review($who['csup']), 'S12: the supervisor\'s review list');
$expired = array_column($r['expiredProformas'], 'waitsFor', 'orderNumber');
$check(count(array_filter($r['holds'], static fn (array $h): bool => $h['open'] && $h['orderNumber'] === $id(22))) === 1 && ($expired[$id(21)] ?? null) === 'finance_task_open' && ($expired[$id(22)] ?? null) === 'finance_hold'
    && array_filter($r['staleTasks'], static fn (array $t): bool => $t['orderNumber'] === $id(21)) === [] && $r['canRelease'] === ['trendhome'], 'S12: the review list shows the hold and the waiting proformas; a fresh task is not stale yet');
$pdo->prepare('UPDATE finance_verification_requests SET requested_at = DATE_SUB(requested_at, INTERVAL 25 HOUR) WHERE request_uuid = ?')->execute(['00000000-0000-4000-8000-0000000c2101']);
$r = $ok($review($who['cfin']), 'S12: the finance recorder\'s review list');
$stale = array_values(array_filter($r['staleTasks'], static fn (array $t): bool => $t['orderNumber'] === $id(21)));
$check(count($stale) === 1 && $stale[0]['state'] === 'assigned' && $stale[0]['ageHours'] >= 25 && $r['canRelease'] === [] && $r['canPlace'] === ['trendhome'], 'S12: a finance task open for more than 24 h is listed for review: ' . json_encode($stale));
// Release: authorized, reasoned, audited, never by who placed it.
$release = static fn (array $w, string $hold, array $body, ?string $key = null): array => $call($w, 'POST', '/confirmare/finance/holds/' . $hold . '/release', $body, $key);
$relBody = ['reasonCode' => 'no_payment_found', 'note' => 'Verificat extrasul: nicio plata (TEST).'];
$hid = $placed['hold']['id'];
$error($release($who['cop'], $hid, $relBody), 404, 'CONFIRMARE_HOLD_NOT_FOUND', 'S12: an operator cannot release a hold');
$error($release($who['cfin'], $hid, $relBody), 403, 'CONFIRMARE_HOLD_DENIED', 'S12: a finance recorder (not approver) cannot release a hold');
$error($release($who['cfinapp'], $hid, ['reasonCode' => 'whatever'] + $relBody), 422, 'VALIDATION_FAILED', 'S12: a release needs a known reason');
$error($release($who['cfinapp'], $hid, ['note' => ''] + $relBody), 422, 'VALIDATION_FAILED', 'S12: a release needs a note');
$error($release($who['cfinapp'], '00000000-0000-4000-8000-0000000c2199', $relBody), 404, 'CONFIRMARE_HOLD_NOT_FOUND', 'S12: an unknown hold');
// Order 24: a supervisor places a hold and cannot release it themselves.
$three(24);
$h24 = $ok($holds($who['csup'], $holdBody(24, 'customer_reports_payment')), 'S12: a supervisor places a hold on order 24')['hold'];
$error($release($who['csup'], $h24['id'], $relBody), 403, 'CONFIRMARE_HOLD_SAME_EMPLOYEE', 'S12: who placed a hold never releases it');
$fourEyes = false;
try { $pdo->prepare("UPDATE confirmare_finance_holds SET open_order_uuid = NULL, released_by_employee_uuid = placed_by_employee_uuid, released_role = 'supervisor', released_at = UTC_TIMESTAMP(6), release_reason_code = 'other', release_note = 'x', release_idempotency_key = 'k', release_request_id = 'r' WHERE hold_uuid = ?")->execute([$h24['id']]); } catch (PDOException) { $fourEyes = true; }
$check($fourEyes && (int) $one('SELECT COUNT(*) FROM confirmare_finance_holds WHERE hold_uuid = ? AND released_at IS NULL', [$h24['id']]) === 1, 'S12: the database refuses a release by the employee who placed the hold');
// A cancellation request already on its way is stopped by the hold: the order is never stopped.
$pdo->prepare("UPDATE confirmare_proforma_cycles SET state = 'order_cancel_pending' WHERE global_order_id = ? AND active_order_uuid IS NOT NULL")->execute([$g(24)]);
$run();
$check($controls(24) === 0 && $cycleOf($g(24))['last_error'] === 'finance_hold' && $cycleOf($g(24))['state'] === 'order_cancel_pending', 'S12: a hold stops an order cancellation already requested by the worker: the order is not stopped');
$relKey = 'cyc-release-' . bin2hex(random_bytes(8));
$released = $ok($release($who['cfinapp'], $hid, $relBody, $relKey), 'S12: a finance approver releases the hold of order 22');
$check($released['released'] === true && $released['hold']['open'] === false && $released['hold']['releasedRole'] === 'finance' && $released['hold']['releaseReasonCode'] === 'no_payment_found'
    && $released['hold']['releasedBy'] === "Test cfinapp", 'S12: the release names who and why: ' . json_encode($released['hold']));
$check($ok($release($who['cfinapp'], $hid, $relBody, $relKey), 'S12: the same release again')['released'] === false, 'S12: a retried release changes nothing');
$error($release($who['csup2'], $hid, $relBody), 409, 'CONFIRMARE_HOLD_RELEASED', 'S12: a released hold cannot be released again');
$check($audits('confirmare.finance_hold.released') === $releasedAudits + 1, 'S12: the release is audited once');
$run();
$check($cycleOf($g(22))['state'] === 'cancelled' && $cancels(22) === 1 && $controls(22) === 1, 'S12: after the release (no payment found) the covered expiry cancels as before');
$ok($release($who['csup2'], $h24['id'], ['reasonCode' => 'not_customer_payment', 'note' => 'Plata nu apartine clientului (TEST).']), 'S12: another supervisor releases the hold of order 24');
// Production clearance: an open hold keeps an otherwise cleared order closed (order 7: approved, call and lines confirmed).
$pdo->prepare("UPDATE operational_orders SET source_financial_state = 'cleared', source_financial_basis = 'advance_collected', source_financial_pricing = source_pricing_fingerprint WHERE global_order_id = ?")->execute([$g(7)]);
$before = \Arasya\Operations\Confirmare\ConfirmationClearance::missing($pdo, $orderUuid($g(7)));
$h7 = $ok($holds($who['cop'], $holdBody(7, 'customer_reports_payment')), 'S12: the order\'s own operator places a hold on order 7')['hold'];
$during = \Arasya\Operations\Confirmare\ConfirmationClearance::missing($pdo, $orderUuid($g(7)));
$check(!in_array('payment', $before, true) && in_array('payment', $during, true) && (int) $one('SELECT production_cleared FROM order_confirmations WHERE order_uuid = ?', [$orderUuid($g(7))]) === 0 && $h7['placedRole'] === 'operator',
    'S12: a hold closes the payment condition: no automatic production clearance while it is open: ' . json_encode([$before, $during]));
$ok($release($who['csup'], $h7['id'], ['reasonCode' => 'payment_recorded', 'note' => 'Plata inregistrata dupa regulile finance (TEST).']), 'S12: a supervisor releases the hold of order 7');
$after = \Arasya\Operations\Confirmare\ConfirmationClearance::missing($pdo, $orderUuid($g(7)));
$check($after === $before, 'S12: after the release the clearance is what the other conditions say (the hold adds nothing more): ' . json_encode($after));
// A hold is refused once production started or the order is closed.
$pdo->prepare("UPDATE operational_orders SET production_stage_id = 'material-preparation' WHERE global_order_id = ?")->execute([$g(5)]);
$started = $error($holds($who['csup'], $holdBody(5)), 409, 'CONFIRMARE_HOLD_NOT_ALLOWED', 'S12: no hold once production started');
$check(($started['error']['details']['reason'] ?? null) === 'production_started', 'S12: the refusal says production_started');
$pdo->prepare("UPDATE operational_orders SET production_stage_id = 'waiting' WHERE global_order_id = ?")->execute([$g(5)]);
$columns = array_column($all("SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'confirmare_finance_holds'"), 'c');
$check(array_intersect($columns, ['amount', 'iban', 'email', 'phone', 'customer_name', 'reference']) === [], 'S12: a hold stores no amount, bank account or customer field');
$pdo->exec('DELETE FROM confirmare_finance_holds');
$pdo->exec("DELETE FROM finance_verification_requests WHERE request_uuid = '00000000-0000-4000-8000-0000000c2101'");

// ---- Cleanup (later suites share the database in CI) ------------------------------------------------------------------------
$clear();
fwrite(STDOUT, "MySQL Confirmare cycle integration: {$checks} checks passed.\n");
