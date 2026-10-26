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
$bankKey = 'cyc-bank-' . bin2hex(random_bytes(8));
$bank = $ok($call($who['cfin'], 'POST', '/confirmare/finance/bank-check', ['source' => 'trendhome'], $bankKey), 'bank check up to now');
$again = $ok($call($who['cfin'], 'POST', '/confirmare/finance/bank-check', ['source' => 'trendhome'], $bankKey), 'the same bank check again (retry)');
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

// ---- Cleanup (later suites share the database in CI) ------------------------------------------------------------------------
$clear();
fwrite(STDOUT, "MySQL Confirmare cycle integration: {$checks} checks passed.\n");
