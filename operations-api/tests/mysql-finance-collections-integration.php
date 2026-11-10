<?php
declare(strict_types=1);
// Finance collections (API 2.68.0, migration 052): four-eyes ledger requests, real bank transaction identity, refunds,
// the B2B read-only account mode and independent order-type / finance-gate switches. Disposable database only.
use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/HandoffSchemaFixture.php';
$db = T::requireTestDatabase();
if ($db === null) { echo "SKIP MySQL Finance collections: ARASYA_TEST_DB_NAME not configured.\n"; exit; }
$checks = 0;
function check(bool $v, string $m): void { global $checks; $checks++; if (!$v) throw new RuntimeException('FAIL ' . $m); }
function ok(array $r, int $s, string $m): array { check($r['status'] === $s, $m . ' got ' . $r['status'] . ' ' . json_encode($r['body'])); return $r['body'] ?? []; }
function error(array $r, int $s, string $code, string $m = ''): array { ok($r, $s, $code . ' ' . $m); check(($r['body']['error']['code'] ?? null) === $code, "{$code} {$m} got " . json_encode($r['body'])); return $r['body']['error']; }
function refused(PDO $pdo, string $sql, array $params = []): int { try { $pdo->prepare($sql)->execute($params); } catch (PDOException $e) { return (int) ($e->errorInfo[1] ?? 0); } throw new RuntimeException('FAIL schema accepted: ' . $sql); }

$config = T::config($db);
$pdo = Connection::create($config);
$dir = dirname(__DIR__) . '/database/migrations';
(new MigrationRunner($pdo))->migrate($dir);
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') as $f) (new SqlFileRunner($pdo))->run($f);

// ---- M. Migration 052: additive, recorded once, grants nothing, keeps existing ledger rows -------------------------
$ledgerHash = static fn (): string => hash('sha256', serialize($pdo->query('SELECT * FROM b2b_account_movements ORDER BY movement_number')->fetchAll(PDO::FETCH_ASSOC)));
$beforeLedger = $ledgerHash();
restorePreFinanceCollectionsTestSchema($pdo);
$grants = $pdo->query('SELECT * FROM role_permissions ORDER BY role_id, permission_id')->fetchAll();
check((new MigrationRunner($pdo))->migrate($dir) === ['052_finance_collections.sql'], 'M1 052 applies on its own');
check((new MigrationRunner($pdo))->migrate($dir) === [], 'M2 052 is recorded once');
(new SqlFileRunner($pdo))->run($dir . '/052_finance_collections.sql');
check($ledgerHash() === $beforeLedger, 'M3 052 (and its re-run) leaves every existing ledger row unchanged');
check($pdo->query('SELECT * FROM role_permissions ORDER BY role_id, permission_id')->fetchAll() === $grants, 'M4 052 grants nothing to any role');
check((int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key IN ('finance.collections.record','finance.collections.approve','finance.ledger.request','finance.ledger.approve','finance.refunds.request','finance.refunds.approve','finance.refunds.payout','finance.reconciliation.manage') AND category = 'finance' AND role_grantable = 1")->fetchColumn() === 8, 'M5 eight role-grantable Finance permissions');
check((int) $pdo->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.permission_key LIKE 'finance.collections.%' OR p.permission_key LIKE 'finance.refunds.%' OR p.permission_key LIKE 'finance.ledger.%' OR p.permission_key = 'finance.reconciliation.manage'")->fetchColumn() === 0, 'M6 nobody holds them');
check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'finance\\_ledger\\_%' AND (DELETE_RULE NOT IN ('RESTRICT', 'NO ACTION') OR UPDATE_RULE NOT IN ('RESTRICT', 'NO ACTION'))")->fetchColumn() === 0, 'M7 no cascading foreign keys');

// ---- Identities ------------------------------------------------------------------------------------------------------
$args = get_object_vars($config);
$kernelWith = static fn (array $over): \Arasya\Operations\Http\ApiKernel => (new Container(new Config(...array_replace($args, $over)), $pdo))->kernel();
$base = new Container($config, $pdo);
$off = $base->kernel();                                                     // production defaults for collections (disabled)
$on = $kernelWith(['financeCollections' => 'enabled']);                     // Finance collections switched on
$suffix = bin2hex(random_bytes(4));
$pdo->exec('DELETE FROM system_root_identity');
$rootUser = 'collections.root.' . $suffix;
$temporary = (new RootBootstrapService($pdo, $base->passwordHasher(), $base->clock()))->bootstrap('collections-test', $rootUser);
$first = T::login($off, $rootUser, $temporary);
ok(T::call($off, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Collections root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 200, 'root password');
$root = T::login($off, $rootUser, 'Collections root passphrase 2026!');
$call = static function (array $who, string $method, string $path, ?array $json = null, array $query = [], ?string $key = null, ?\Arasya\Operations\Http\ApiKernel $k = null) use ($on): array {
    return T::call($k ?? $on, $method, $path, $json, $method === 'GET' ? [] : ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? ('col-' . bin2hex(random_bytes(10)))], $who['cookie'], $query);
};
$roleIds = [];
$role = static function (string $name, array $permissions) use ($call, $root, $suffix, &$roleIds): int {
    return $roleIds[$name] = (int) ok($call($root, 'POST', '/management/roles', ['name' => "{$name} {$suffix}", 'description' => null, 'authorityRank' => 200, 'permissions' => $permissions]), 201, "role {$name}")['role']['id'];
};
$password = 'collections test passphrase 2026';
$admin = $base->employeeAdmin();
$person = static function (string $name, array $applications, array $permissions) use ($admin, $call, $root, $password, $suffix, $on, $role): array {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test');
    ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications]), 200, "{$name} applications");
    if ($permissions !== []) ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => [$role($name, $permissions)]]), 200, "{$name} roles");
    return T::login($on, "{$name}.{$suffix}", $password) + ['id' => $employee->employeeUuid];
};
$view = 'finance.accounts.view';
$recorder = $person('recorder', ['finance'], [$view, 'finance.collections.record']);
$approver = $person('approver', ['finance'], [$view, 'finance.collections.approve']);
$approver2 = $person('approvertwo', ['finance'], [$view, 'finance.collections.approve']);
$both = $person('bothroles', ['finance'], [$view, 'finance.collections.record', 'finance.collections.approve']);
$corrector = $person('corrector', ['finance'], [$view, 'finance.ledger.request']);
$controller = $person('controller', ['finance'], [$view, 'finance.ledger.approve']);
$refunder = $person('refunder', ['finance'], [$view, 'finance.refunds.request']);
$refundApprover = $person('refundapp', ['finance'], [$view, 'finance.refunds.approve']);
$cashier = $person('cashier', ['finance'], [$view, 'finance.refunds.payout']);
$reconciler = $person('reconciler', ['finance'], [$view, 'finance.reconciliation.manage']);
$viewer = $person('viewer', ['finance'], [$view]);
$b2bClerk = $person('b2bclerk', ['b2b'], ['b2b.companies.view', 'b2b.accounts.view', 'b2b.accounts.record_payment', 'b2b.accounts.adjust', 'b2b.accounts.reverse']);

// ---- B2B data: companies and finalized orders (exact gross totals: meters x 10.00 net, VAT 25 %) -------------------------
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d');
$company = static fn (string $tag, string $country = 'RO') => ok($call($root, 'POST', '/b2b/companies', ['legalName' => "Collections {$tag} {$suffix}", 'displayName' => $tag, 'countryCode' => $country, 'taxIdentifier' => 'COL' . $tag . $suffix]), 201, "company {$tag}")['companyId'];
$rows = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };
$order = static function (string $co, string $meters, string $currency = 'RON', ?\Arasya\Operations\Http\ApiKernel $k = null) use ($call, $root, $rows): array {
    $line = ['productCode' => 'CURTAIN', 'productName' => null, 'variant' => null, 'color' => null, 'kind' => 'curtain', 'width' => '200', 'height' => '260', 'quantity' => 1, 'meters' => $meters,
        'pricingUnit' => 'meter', 'unitPriceNet' => '10.00', 'discountPercent' => '0', 'vatPercent' => '25', 'notes' => null];
    $o = ok($call($root, 'POST', '/b2b/orders', ['companyId' => $co, 'currencyCode' => $currency, 'orderType' => 'FULL_TAILORING', 'contactId' => null, 'billingAddressId' => null, 'deliveryAddressId' => null, 'notes' => null, 'lines' => [$line]], [], null, $k), 201, 'draft')['detail']['order'];
    ok($call($root, 'POST', "/b2b/orders/{$o['id']}/finalize", ['expectedVersion' => 1], [], null, $k), 200, 'finalize');
    $r = $rows("SELECT movement_uuid, amount FROM b2b_account_movements WHERE order_uuid = ? AND movement_type = 'order_receivable'", [$o['id']])[0];
    return ['order' => $o['id'], 'receivable' => $r['movement_uuid'], 'gross' => $r['amount']];
};
$summary = static fn (string $co, string $currency = 'RON') => ok($call($viewer, 'GET', "/finance/ledger/companies/{$co}"), 200, 'finance ledger summary')['currencies'][$currency];
$openOf = static function (string $co, string $kind, string $id, string $currency = 'RON') use ($call, $viewer): ?string {
    foreach (ok($call($viewer, 'GET', "/finance/ledger/companies/{$co}/open-items", null, ['currency' => $currency]), 200, 'open items')[$kind] as $i) if ($i['id'] === $id) return $i['openAmount'];
    return null;
};
$bank = static fn (string $tx, string $account = 'ING-RON-01', string $provider = 'ING') => ['provider' => $provider, 'accountKey' => $account, 'transactionId' => $tx];
$payment = static fn (string $co, string $amount, ?array $bankTx, array $allocations = [], array $extra = []) => ['kind' => 'PAYMENT', 'companyId' => $co, 'currencyCode' => 'RON', 'amount' => $amount, 'valueDate' => $today,
    'method' => 'bank_transfer', 'bankTransaction' => $bankTx, 'allocations' => $allocations] + $extra;
$movementCount = static fn (string $co): int => (int) $rows('SELECT COUNT(*) n FROM b2b_account_movements WHERE company_uuid = ?', [$co])[0]['n'];

// ---- D. Production default: collections disabled; reads work, every command refuses ----------------------------------
$dco = $company('D');
error($call($recorder, 'POST', '/finance/ledger-requests', $payment($dco, '1.00', $bank('D-0001')), [], null, $off), 409, 'FINANCE_COLLECTIONS_DISABLED', 'D1');
check(ok($call($viewer, 'GET', '/finance/ledger-requests', null, [], null, $off), 200, 'D2 list on the default kernel')['collectionsEnabled'] === false, 'D2 the list says collections are off');
check(ok($call($viewer, 'GET', '/finance/access', null, [], null, $off), 200, 'access')['collectionsEnabled'] === false, 'D3 /finance/access reports the switch');

// ---- 1 (A). 1,000 RON order, 300 RON collected: posted only after the second person, remaining 700 ----------------------
$a = $company('A'); $oa = $order($a, '80');
check($oa['gross'] === '1000.00', '1 order gross 1000.00');
$ra = ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($a, '300.00', $bank('OP-A-0001'), [['receivableId' => $oa['receivable'], 'amount' => '300.00']], ['externalReference' => 'Plata factura A'])), 201, '1 create')['request'];
check($ra['status'] === 'PENDING_APPROVAL' && preg_match('/^FIN-TX-\d{6,}$/', $ra['code']) === 1, '1 a new request waits for approval');
check($summary($a)['balance'] === '1000.00' && $movementCount($a) === 1, '1 nothing reaches the ledger before approval');
$queue = ok($call($approver, 'GET', '/finance/ledger-requests', null, ['awaitingMe' => '1']), 200, '1 approver queue')['items'];
check(in_array($ra['id'], array_column($queue, 'id'), true), '1 the request is in the approver queue');
check(!in_array($ra['id'], array_column(ok($call($recorder, 'GET', '/finance/ledger-requests', null, ['awaitingMe' => '1']), 200, 'recorder queue')['items'], 'id'), true), '1 never in the queue of its creator');
$detail = ok($call($recorder, 'GET', "/finance/ledger-requests/{$ra['id']}"), 200, '1 detail');
check($detail['decision']['canApprove'] === false && $detail['decision']['canWithdraw'] === true, '1 the creator may withdraw but not approve');
$approved = ok($call($approver, 'POST', "/finance/ledger-requests/{$ra['id']}/approve", ['expectedVersion' => 1]), 200, '1 approve');
check($approved['request']['status'] === 'APPROVED' && count($approved['allocationIds']) === 1, '1 approved with its allocation');
check($summary($a)['balance'] === '700.00' && $openOf($a, 'receivables', $oa['receivable']) === '700.00', '1 collected 300.00, remaining 700.00');
$posted = $rows('SELECT * FROM b2b_account_movements WHERE movement_uuid = ?', [$approved['movementId']])[0];
$snap = json_decode($posted['source_snapshot'], true);
check($posted['movement_type'] === 'payment' && $posted['amount'] === '300.00' && $posted['created_by_employee_uuid'] === $approver['id'] && $snap['sourceType'] === 'finance_request'
    && $snap['requestCode'] === $ra['code'] && $snap['recordedBy'] === $recorder['id'] && $snap['approvedBy'] === $approver['id'] && $snap['bankTransactionId'] === 'OP-A-0001', '1 one ledger payment through AccountLedger, with the request, both people and the bank identity');
check(count($rows("SELECT 1 FROM b2b_account_activity_events WHERE movement_uuid = ? AND action = 'payment_recorded'", [$approved['movementId']])) === 1, '1 B2B account activity records the posting');

// ---- 2 (B). 350 RON advance before the order; allocated after finalization; remaining 650 ------------------------------
$b = $company('B');
$rb = ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($b, '350.00', $bank('OP-B-0001'))), 201, '2 advance request')['request'];
$pb = ok($call($approver, 'POST', "/finance/ledger-requests/{$rb['id']}/approve", ['expectedVersion' => 1]), 200, '2 approve advance')['movementId'];
check($summary($b)['balance'] === '-350.00', '2 the advance alone is company credit');
$ob = $order($b, '80');
error($call($recorder, 'POST', "/finance/ledger/companies/{$b}/allocations", ['paymentId' => $pb, 'allocations' => [['receivableId' => $ob['receivable'], 'amount' => '350.00']]]), 403, 'UNAUTHORIZED_ACTION', '2 recording does not include reconciliation');
ok($call($reconciler, 'POST', "/finance/ledger/companies/{$b}/allocations", ['paymentId' => $pb, 'allocations' => [['receivableId' => $ob['receivable'], 'amount' => '350.00']]]), 201, '2 allocate');
check($summary($b)['balance'] === '650.00' && $openOf($b, 'receivables', $ob['receivable']) === '650.00' && $openOf($b, 'payments', $pb) === null, '2 remaining 650.00, advance fully used');

// ---- 3 (C). 1,100 RON on a 1,000 RON order: the order is settled, 100 RON stays company credit --------------------------
$c = $company('C'); $oc = $order($c, '80');
error($call($recorder, 'POST', '/finance/ledger-requests', $payment($c, '1100.00', $bank('OP-C-0001'), [['receivableId' => $oc['receivable'], 'amount' => '1100.00']])), 409, 'ALLOCATION_EXCEEDS_OUTSTANDING', '3 over-allocation refused at entry');
error($call($recorder, 'POST', '/finance/ledger-requests', $payment($c, '1000.00', $bank('OP-C-0001'), [['receivableId' => $oc['receivable'], 'amount' => '600.00'], ['receivableId' => $oc['receivable'], 'amount' => '400.00']])), 422, 'VALIDATION_FAILED', '3 one receivable twice');
$rc = ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($c, '1100.00', $bank('OP-C-0001'), [['receivableId' => $oc['receivable'], 'amount' => '1000.00']])), 201, '3 overpayment request')['request'];
$pc = ok($call($approver, 'POST', "/finance/ledger-requests/{$rc['id']}/approve", ['expectedVersion' => 1]), 200, '3 approve')['movementId'];
check($summary($c)['balance'] === '-100.00' && $openOf($c, 'receivables', $oc['receivable']) === null && $openOf($c, 'payments', $pc) === '100.00' && $summary($c)['unallocatedPayments'] === '100.00', '3 settled order, 100.00 unallocated company credit');

// ---- 4 (D). One 2,000 RON payment split across two orders ---------------------------------------------------------------
$d = $company('Split'); $d1 = $order($d, '80'); $d2 = $order($d, '80');
$rd = ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($d, '2000.00', $bank('OP-D-0001'), [['receivableId' => $d1['receivable'], 'amount' => '1000.00'], ['receivableId' => $d2['receivable'], 'amount' => '1000.00']])), 201, '4 split request')['request'];
check(count(ok($call($approver, 'POST', "/finance/ledger-requests/{$rd['id']}/approve", ['expectedVersion' => 1]), 200, '4 approve')['allocationIds']) === 2, '4 two allocations');
check($summary($d)['balance'] === '0.00' && $openOf($d, 'receivables', $d1['receivable']) === null && $openOf($d, 'receivables', $d2['receivable']) === null, '4 both orders settled');

// ---- 11. Wrong company or wrong currency never settles ---------------------------------------------------------------
$other = $company('Other'); $oo = $order($other, '10');
error($call($recorder, 'POST', '/finance/ledger-requests', $payment($d, '10.00', $bank('OP-X-0001'), [['receivableId' => $oo['receivable'], 'amount' => '10.00']])), 404, 'RECEIVABLE_NOT_FOUND', '11 another company\'s order');
$eurOrder = $order($d, '10', 'EUR');
error($call($recorder, 'POST', '/finance/ledger-requests', $payment($d, '10.00', $bank('OP-X-0002'), [['receivableId' => $eurOrder['receivable'], 'amount' => '10.00']])), 409, 'CURRENCY_MISMATCH', '11 a RON payment cannot settle an EUR order');
error($call($reconciler, 'POST', "/finance/ledger/companies/{$other}/allocations", ['paymentId' => $pc, 'allocations' => [['receivableId' => $oo['receivable'], 'amount' => '10.00']]]), 404, 'PAYMENT_NOT_FOUND', '11 another company\'s payment');
error($call($refunder, 'POST', '/finance/ledger-requests', ['kind' => 'REFUND', 'companyId' => $other, 'sourcePaymentId' => $pc, 'amount' => '10.00', 'valueDate' => $today, 'method' => 'bank_transfer', 'note' => 'Wrong company test']), 404, 'PAYMENT_NOT_FOUND', '11 refund of another company\'s payment');

// ---- 6 (F). Four eyes: never the creator, also with both roles, also root; the database refuses it too ---------------
$f = $company('F'); $of = $order($f, '80');
$rf = ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($f, '500.00', $bank('OP-F-0001'), [['receivableId' => $of['receivable'], 'amount' => '500.00']])), 201, '6 request')['request'];
error($call($recorder, 'POST', "/finance/ledger-requests/{$rf['id']}/approve", ['expectedVersion' => 1]), 403, 'UNAUTHORIZED_ACTION', '6 a recorder has no approval permission');
$own = ok($call($both, 'POST', '/finance/ledger-requests', $payment($f, '20.00', $bank('OP-F-0002'))), 201, '6 two-role person enters')['request'];
error($call($both, 'POST', "/finance/ledger-requests/{$own['id']}/approve", ['expectedVersion' => 1]), 403, 'FOUR_EYES_REQUIRED', '6 holding both roles never approves one\'s own entry');
error($call($both, 'POST', "/finance/ledger-requests/{$own['id']}/reject", ['expectedVersion' => 1, 'reason' => 'Own rejection attempt']), 403, 'FOUR_EYES_REQUIRED', '6 nor rejects it');
$rootOwn = ok($call($root, 'POST', '/finance/ledger-requests', $payment($f, '30.00', $bank('OP-F-0003'))), 201, '6 root enters')['request'];
error($call($root, 'POST', "/finance/ledger-requests/{$rootOwn['id']}/approve", ['expectedVersion' => 1]), 403, 'FOUR_EYES_REQUIRED', '6 root never approves its own entry');
check($summary($f)['balance'] === '1000.00', '6 nothing was posted');
check(in_array(refused($pdo, 'UPDATE finance_ledger_requests SET status = \'REJECTED\', decided_by_employee_uuid = created_by_employee_uuid, decided_at = UTC_TIMESTAMP(6), decision_reason = \'x\' WHERE request_uuid = ?', [$own['id']]), [3819, 4025], true), '6 the database refuses a decision by the creator');
ok($call($approver, 'POST', "/finance/ledger-requests/{$own['id']}/approve", ['expectedVersion' => 1]), 200, '6 a second person approves the two-role entry');

// ---- 8. Two approvers at the same moment: one posts, the other is told it is decided ------------------------------------
$race = static function (array $jobs) use ($db): array {
    $start = (string) (microtime(true) + 0.4); $workers = [];
    foreach ($jobs as [$who, $path, $body, $key]) {
        $proc = proc_open([PHP_BINARY, __DIR__ . '/fixtures/finance-collections-worker.php', $db, 'POST', $path, json_encode($body), $key, $who['cookie'], $who['csrf'], $start], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]); $workers[] = [$proc, $pipes];
    }
    $out = [];
    foreach ($workers as [$proc, $pipes]) { $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($proc); $out[] = json_decode((string) $o, true) ?? ['status' => 0, 'error' => trim($e)]; }
    return $out;
};
$r8 = ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($f, '100.00', $bank('OP-F-0008'))), 201, '8 request')['request'];
$results = $race([[$approver, "/finance/ledger-requests/{$r8['id']}/approve", ['expectedVersion' => 1], 'race-approve-a-' . $suffix . '-0001'], [$approver2, "/finance/ledger-requests/{$r8['id']}/approve", ['expectedVersion' => 1], 'race-approve-b-' . $suffix . '-0002']]);
$statuses = array_column($results, 'status'); sort($statuses);
check($statuses === [200, 409], '8 one approval wins, the other gets 409: ' . json_encode($results));
check(in_array(array_values(array_filter($results, static fn (array $r): bool => $r['status'] === 409))[0]['error'], ['LEDGER_REQUEST_NOT_PENDING', 'VERSION_CONFLICT'], true), '8 the loser is told the request was decided');
check(count($rows("SELECT 1 FROM b2b_account_movements WHERE source_snapshot LIKE ?", ['%' . $r8['id'] . '%'])) === 1, '8 exactly one ledger movement');

// ---- 9. Connection lost during approval: a retry with the same key replays; a failure leaves nothing --------------------
$r9 = ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($f, '40.00', $bank('OP-F-0009'), [['receivableId' => $of['receivable'], 'amount' => '40.00']])), 201, '9 request')['request'];
final class AllocationFailurePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'INSERT INTO b2b_account_allocations(')) throw new PDOException('Simulated failure after the payment insert.');
        return parent::prepare($query, $options);
    }
}
$failing = new AllocationFailurePdo(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->dbHost, $config->dbPort, $config->dbName), $config->dbUser, $config->dbPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false]);
$failing->exec("SET time_zone = '+00:00'");
$broken = (new Container(new Config(...array_replace($args, ['financeCollections' => 'enabled'])), $failing))->kernel();
$key9 = 'approve-nine-' . $suffix . '-retry';
$before9 = $movementCount($f);
error($call($approver, 'POST', "/finance/ledger-requests/{$r9['id']}/approve", ['expectedVersion' => 1], [], $key9, $broken), 500, 'INTERNAL_ERROR', '9 failure during posting');
check($movementCount($f) === $before9 && LedgerStatus($pdo, $r9['id']) === 'PENDING_APPROVAL', '9 the failed approval left no movement and the request pending');
function LedgerStatus(PDO $pdo, string $id): string { $s = $pdo->prepare('SELECT status FROM finance_ledger_requests WHERE request_uuid = ?'); $s->execute([$id]); return (string) $s->fetchColumn(); }
$first9 = ok($call($approver, 'POST', "/finance/ledger-requests/{$r9['id']}/approve", ['expectedVersion' => 1], [], $key9), 200, '9 the same key succeeds once the database works');
$replay9 = ok($call($approver, 'POST', "/finance/ledger-requests/{$r9['id']}/approve", ['expectedVersion' => 1], [], $key9), 200, '9 retry after a lost answer');
check(($replay9['replayed'] ?? false) === true && $replay9['movementId'] === $first9['movementId'], '9 the retry replays the recorded result');
error($call($approver, 'POST', "/finance/ledger-requests/{$r9['id']}/approve", ['expectedVersion' => 1]), 409, 'LEDGER_REQUEST_NOT_PENDING', '9 a new key does not post again');
error($call($approver, 'POST', "/finance/ledger-requests/{$r9['id']}/approve", ['expectedVersion' => 2], [], $key9), 409, 'IDEMPOTENCY_CONFLICT', '9 the key with another body');
check($movementCount($f) === $before9 + 1, '9 one movement in total');

// ---- 7 (G). The same bank transaction again ---------------------------------------------------------------------------
$g = $company('G'); $og = $order($g, '80');
$rg = ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($g, '400.00', $bank('OP 2026/11-0001'))), 201, '7 first entry')['request'];
error($call($recorder, 'POST', '/finance/ledger-requests', $payment($g, '400.00', $bank('OP2026110001'))), 409, 'BANK_TRANSACTION_ALREADY_RECORDED', '7 same transaction id written differently, new key');
error($call($both, 'POST', '/finance/ledger-requests', $payment($g, '400.00', $bank('op-2026-11-0001'))), 409, 'BANK_TRANSACTION_ALREADY_RECORDED', '7 another person, same transaction');
$pg = ok($call($approver, 'POST', "/finance/ledger-requests/{$rg['id']}/approve", ['expectedVersion' => 1]), 200, '7 approve the first')['movementId'];
error($call($recorder, 'POST', '/finance/ledger-requests', $payment($g, '400.00', $bank('OP2026110001'))), 409, 'BANK_TRANSACTION_ALREADY_RECORDED', '7 still refused once posted');
$pdo->exec('INSERT INTO finance_ledger_request_sequence (created_at) VALUES (UTC_TIMESTAMP(6))'); $n = (int) $pdo->lastInsertId();
$dupKey = $rows('SELECT bank_transaction_key FROM finance_ledger_requests WHERE request_uuid = ?', [$rg['id']])[0]['bank_transaction_key'];
check(refused($pdo, "INSERT INTO finance_ledger_requests (request_uuid, request_number, request_code, kind, status, company_uuid, currency_code, amount, direction, value_date, payment_method, bank_provider, bank_account_key, bank_transaction_id, bank_transaction_key, active_bank_transaction_key,
    created_by_employee_uuid, created_at, last_edited_by_employee_uuid, updated_at, submitted_by_employee_uuid, submitted_at) VALUES (UUID(), ?, ?, 'PAYMENT', 'PENDING_APPROVAL', ?, 'RON', '400.00', 'credit', CURRENT_DATE, 'bank_transfer', 'ING', 'ING-RON-01', 'x', ?, ?, ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6))",
    [$n, 'FIN-TX-T' . $n, $g, $dupKey, $dupKey, $recorder['id'], $recorder['id'], $recorder['id']]) === 1062, '7 the database refuses a second active row for the same bank transaction');
// Similar text is only flagged: the approver must acknowledge it; an exact identity can never be acknowledged.
$similar = ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($g, '400.00', $bank('OP-2026-11-0099'), [], ['externalReference' => 'OP 2026/11-0001'])), 201, '7 similar reference, other identity');
check(count($similar['possibleDuplicates']) >= 1 && in_array('same_reference', $similar['possibleDuplicates'][0]['reasons'], true), '7 the similar entry is flagged for review');
$dupErr = error($call($approver, 'POST', "/finance/ledger-requests/{$similar['request']['id']}/approve", ['expectedVersion' => 1]), 409, 'POSSIBLE_DUPLICATE_UNACKNOWLEDGED', '7 approval needs an explicit acknowledgement');
check(($dupErr['details']['possibleDuplicates'][0]['code'] ?? null) === $rows('SELECT movement_code FROM b2b_account_movements WHERE movement_uuid = ?', [$pg])[0]['movement_code'], '7 the approver sees which entry looks the same');
$ack = ok($call($approver, 'POST', "/finance/ledger-requests/{$similar['request']['id']}/approve", ['expectedVersion' => 1, 'acknowledgePossibleDuplicates' => true]), 200, '7 acknowledged as a different payment');
check($ack['request']['possibleDuplicatesAcknowledged'] === true, '7 the acknowledgement is recorded');
error($call($recorder, 'POST', '/finance/ledger-requests', $payment($g, '400.00', ['provider' => 'ING', 'accountKey' => 'RO49 AAAA 1B31 0075 9384 0000', 'transactionId' => 'OP-9'])), 422, 'VALIDATION_FAILED', '7 an IBAN is never stored as the account key');
error($call($recorder, 'POST', '/finance/ledger-requests', $payment($g, '10.00', null)), 422, 'VALIDATION_FAILED', '7 a bank transfer needs its bank identity');
// A reversal (four eyes) frees the identity, so the money can be recorded correctly again.
$rev = ok($call($corrector, 'POST', '/finance/ledger-requests', ['kind' => 'REVERSAL', 'companyId' => $g, 'targetMovementId' => $pg, 'valueDate' => $today, 'note' => 'Recorded on the wrong company']), 201, '7 reversal request')['request'];
check($rev['amount'] === '400.00' && $rev['currencyCode'] === 'RON', '7 the reversal takes amount and currency from the movement');
error($call($corrector, 'POST', '/finance/ledger-requests', ['kind' => 'REVERSAL', 'companyId' => $g, 'targetMovementId' => $pg, 'valueDate' => $today, 'note' => 'Second attempt']), 409, 'REVERSAL_ALREADY_REQUESTED', '7 one open reversal per movement');
error($call($corrector, 'POST', "/finance/ledger-requests/{$rev['id']}/approve", ['expectedVersion' => 1]), 403, 'UNAUTHORIZED_ACTION', '7 a requester cannot approve corrections');
ok($call($controller, 'POST', "/finance/ledger-requests/{$rev['id']}/approve", ['expectedVersion' => 1]), 200, '7 the controller approves the reversal');
check(LedgerStatus($pdo, $rg['id']) === 'REVERSED' && $rows('SELECT active_bank_transaction_key FROM finance_ledger_requests WHERE request_uuid = ?', [$rg['id']])[0]['active_bank_transaction_key'] === null, '7 the original request is REVERSED and its identity free');
ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($g, '400.00', $bank('OP2026110001'))), 201, '7 the same money can now be recorded once, correctly');
error($call($corrector, 'POST', '/finance/ledger-requests', ['kind' => 'REVERSAL', 'companyId' => $g, 'targetMovementId' => $og['receivable'], 'valueDate' => $today, 'note' => 'Receivable attempt']), 409, 'ORDER_RECEIVABLE_FOLLOWS_ORDER', '7 receivables follow their order');

// ---- 5 (E) and 10. Cancelled order, refund request, second-person approval, payout -------------------------------------
$e = $company('E'); $oe = $order($e, '80');
$re = ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($e, '1000.00', $bank('OP-E-0001'), [['receivableId' => $oe['receivable'], 'amount' => '1000.00']])), 201, '5 payment')['request'];
$pe = ok($call($approver, 'POST', "/finance/ledger-requests/{$re['id']}/approve", ['expectedVersion' => 1]), 200, '5 approve payment')['movementId'];
ok($call($root, 'POST', "/b2b/orders/{$oe['order']}/cancel", ['expectedVersion' => 2]), 200, '5 cancel the finalized order');
check($summary($e)['balance'] === '-1000.00' && $openOf($e, 'payments', $pe) === '1000.00', '5 receivable reversed, the payment is company credit again');
error($call($refunder, 'POST', '/finance/ledger-requests', ['kind' => 'REFUND', 'companyId' => $e, 'sourcePaymentId' => $pe, 'amount' => '1000.01', 'valueDate' => $today, 'method' => 'bank_transfer', 'note' => 'Too much']), 409, 'REFUND_EXCEEDS_UNALLOCATED_PAYMENT', '10 more than the payment');
$rr = ok($call($refunder, 'POST', '/finance/ledger-requests', ['kind' => 'REFUND', 'companyId' => $e, 'sourcePaymentId' => $pe, 'amount' => '1000.00', 'valueDate' => $today, 'method' => 'bank_transfer', 'note' => 'Order cancelled, money back']), 201, '10 refund request')['request'];
check($rr['status'] === 'PENDING_APPROVAL' && $summary($e)['balance'] === '-1000.00', '10 a refund request changes nothing');
error($call($refunder, 'POST', "/finance/ledger-requests/{$rr['id']}/approve", ['expectedVersion' => 1]), 403, 'UNAUTHORIZED_ACTION', '10 the requester cannot approve');
$rra = ok($call($refundApprover, 'POST', "/finance/ledger-requests/{$rr['id']}/approve", ['expectedVersion' => 1]), 200, '10 second person approves');
check($rra['request']['status'] === 'APPROVED' && $rra['request']['payout'] === null, '10 approved is not paid: the payout is a separate step');
$refundRow = $rows('SELECT * FROM b2b_account_movements WHERE movement_uuid = ?', [$rra['movementId']])[0];
check($refundRow['movement_type'] === 'refund' && $refundRow['direction'] === 'debit' && $refundRow['payment_method'] === 'bank_transfer', '10 a refund movement, never a deleted payment');
check($summary($e)['balance'] === '0.00' && $openOf($e, 'payments', $pe) === null, '10 balance 0.00, the payment is fully used by the refund');
check(count($rows('SELECT 1 FROM b2b_account_movements WHERE movement_uuid = ?', [$pe])) === 1, '10 the original payment still exists');
error($call($refunder, 'POST', "/finance/ledger-requests/{$rr['id']}/payout", ['expectedVersion' => 2, 'bankTransaction' => $bank('OUT-E-0001'), 'paidOn' => $today]), 403, 'UNAUTHORIZED_ACTION', '10 payout needs the payout permission');
$paid = ok($call($cashier, 'POST', "/finance/ledger-requests/{$rr['id']}/payout", ['expectedVersion' => 2, 'bankTransaction' => $bank('OUT-E-0001'), 'paidOn' => $today]), 200, '10 payout recorded');
check($paid['request']['status'] === 'PAID_OUT' && $paid['request']['payout']['transactionId'] === 'OUT-E-0001', '10 paid out with its outgoing bank identity');
$allocationOfRefund = $rows('SELECT allocation_uuid FROM b2b_account_allocations WHERE receivable_movement_uuid = ?', [$rra['movementId']])[0]['allocation_uuid'];
error($call($reconciler, 'POST', "/finance/ledger/companies/{$e}/allocations/{$allocationOfRefund}/release", ['reason' => 'Try to free the refund']), 409, 'REFUND_ALLOCATION_FIXED', '10 the refund allocation cannot be released');
// A second refund with the same outgoing transaction id is refused; a cancelled payout restores the credit.
$c2 = $company('E2'); $p2 = ok($call($approver, 'POST', '/finance/ledger-requests/' . ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($c2, '50.00', $bank('OP-E2-0001'))), 201, '10 E2 payment')['request']['id'] . '/approve', ['expectedVersion' => 1]), 200, '10 E2 approve')['movementId'];
$rr2 = ok($call($refunder, 'POST', '/finance/ledger-requests', ['kind' => 'REFUND', 'companyId' => $c2, 'sourcePaymentId' => $p2, 'amount' => '50.00', 'valueDate' => $today, 'method' => 'bank_transfer', 'note' => 'Customer asked']), 201, '10 E2 refund')['request'];
ok($call($refundApprover, 'POST', "/finance/ledger-requests/{$rr2['id']}/approve", ['expectedVersion' => 1]), 200, '10 E2 approve refund');
error($call($cashier, 'POST', "/finance/ledger-requests/{$rr2['id']}/payout", ['expectedVersion' => 2, 'bankTransaction' => $bank('OUT-E-0001'), 'paidOn' => $today]), 409, 'BANK_TRANSACTION_ALREADY_RECORDED', '10 one outgoing transfer pays one refund');
error($call($refunder, 'POST', "/finance/ledger-requests/{$rr2['id']}/cancel-payout", ['expectedVersion' => 2, 'reason' => 'Own cancel']), 403, 'UNAUTHORIZED_ACTION', '10 the requester cannot cancel');
ok($call($refundApprover, 'POST', "/finance/ledger-requests/{$rr2['id']}/cancel-payout", ['expectedVersion' => 2, 'reason' => 'Customer kept the credit']), 200, '10 cancel the unpaid refund');
check(LedgerStatus($pdo, $rr2['id']) === 'REVERSED' && $summary($c2)['balance'] === '-50.00' && $openOf($c2, 'payments', $p2) === '50.00', '10 cancelled refund: reversal posted, credit back');
// An approved refund whose payout is not recorded yet stays open (it is used by the rollback floor check at the end).
$c3 = $company('E3'); $p3 = ok($call($approver, 'POST', '/finance/ledger-requests/' . ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($c3, '30.00', $bank('OP-E3-0001'))), 201, '10 E3 payment')['request']['id'] . '/approve', ['expectedVersion' => 1]), 200, '10 E3 approve')['movementId'];
$rr3 = ok($call($refunder, 'POST', '/finance/ledger-requests', ['kind' => 'REFUND', 'companyId' => $c3, 'sourcePaymentId' => $p3, 'amount' => '30.00', 'valueDate' => $today, 'method' => 'bank_transfer', 'note' => 'Payout next week']), 201, '10 E3 refund')['request'];
ok($call($refundApprover, 'POST', "/finance/ledger-requests/{$rr3['id']}/approve", ['expectedVersion' => 1]), 200, '10 E3 approve refund');
check(LedgerStatus($pdo, $rr3['id']) === 'APPROVED' && $summary($c3)['balance'] === '0.00', '10 E3 approved, payout still pending');

// ---- 12. A revoked approver cannot decide a pending request -----------------------------------------------------------
$r12 = ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($f, '60.00', $bank('OP-F-0012'))), 201, '12 request')['request'];
ok($call($root, 'PUT', "/management/employees/{$approver2['id']}/roles", ['roleIds' => []]), 200, '12 revoke the approver role');
error($call($approver2, 'POST', "/finance/ledger-requests/{$r12['id']}/approve", ['expectedVersion' => 1]), 403, 'UNAUTHORIZED_ACTION', '12 refused on the very next request');
check(LedgerStatus($pdo, $r12['id']) === 'PENDING_APPROVAL', '12 the request stays pending');
ok($call($root, 'PUT', "/management/employees/{$approver2['id']}/applications", ['applications' => []]), 200, '12 remove the Finance application');
error($call($approver2, 'GET', '/finance/ledger-requests'), 403, 'APPLICATION_ACCESS_DENIED', '12 no Finance read either');

// ---- Draft, edit, submit, withdraw; adjustments ------------------------------------------------------------------------
$draft = ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($f, '70.00', $bank('OP-F-0070')) + ['submit' => false]), 201, 'draft')['request'];
check($draft['status'] === 'DRAFT', 'a draft is not in any queue');
error($call($approver, 'POST', "/finance/ledger-requests/{$draft['id']}/approve", ['expectedVersion' => 1]), 409, 'LEDGER_REQUEST_NOT_PENDING', 'a draft cannot be approved');
error($call($both, 'POST', "/finance/ledger-requests/{$draft['id']}/update", ['expectedVersion' => 1, 'valueDate' => $today, 'currencyCode' => 'RON', 'amount' => '71.00', 'method' => 'cash']), 403, 'NOT_REQUEST_OWNER', 'only the creator edits');
$edited = ok($call($recorder, 'POST', "/finance/ledger-requests/{$draft['id']}/update", ['expectedVersion' => 1, 'valueDate' => $today, 'currencyCode' => 'RON', 'amount' => '71.00', 'method' => 'cash']), 200, 'edit draft')['request'];
check($edited['amount'] === '71.00' && $edited['bankTransaction'] === null && $edited['version'] === 2, 'the draft was replaced and its bank identity released');
ok($call($recorder, 'POST', "/finance/ledger-requests/{$draft['id']}/submit", ['expectedVersion' => 2]), 200, 'submit');
ok($call($recorder, 'POST', "/finance/ledger-requests/{$draft['id']}/withdraw", ['expectedVersion' => 3, 'reason' => 'Entered twice']), 200, 'withdraw');
check(LedgerStatus($pdo, $draft['id']) === 'WITHDRAWN', 'withdrawn');
$parked = ok($call($recorder, 'POST', '/finance/ledger-requests', ['kind' => 'PAYMENT', 'companyId' => $f, 'currencyCode' => 'RON', 'amount' => '12.00', 'valueDate' => $today, 'method' => 'cash', 'submit' => false]), 201, 'a draft left open for the rollback floor check')['request'];
check($parked['status'] === 'DRAFT', 'the parked draft stays a draft');
$adj = ok($call($corrector, 'POST', '/finance/ledger-requests', ['kind' => 'ADJUSTMENT', 'companyId' => $f, 'currencyCode' => 'RON', 'amount' => '5.00', 'direction' => 'credit', 'valueDate' => $today, 'note' => 'Rounding difference agreed']), 201, 'adjustment request')['request'];
error($call($approver, 'POST', "/finance/ledger-requests/{$adj['id']}/approve", ['expectedVersion' => 1]), 403, 'UNAUTHORIZED_ACTION', 'a payment verifier does not approve corrections');
$fBefore = $summary($f)['balance'];
ok($call($controller, 'POST', "/finance/ledger-requests/{$adj['id']}/reject", ['expectedVersion' => 1, 'reason' => 'Not agreed with the customer']), 200, 'reject adjustment');
check($summary($f)['balance'] === $fBefore && LedgerStatus($pdo, $adj['id']) === 'REJECTED', 'a rejected correction posts nothing');

// ---- W. B2B account writes: Finance-owned movements, then the read-only mode -----------------------------------------
$b2bOn = $call($b2bClerk, 'POST', "/b2b/accounts/{$a}/movements/{$approved['movementId']}/reverse", ['valueDate' => $today, 'reason' => 'B2B reversal attempt']);
error($b2bOn, 409, 'MOVEMENT_FOLLOWS_FINANCE', 'W1 B2B cannot reverse a payment approved in Finance');
error($call($b2bClerk, 'POST', "/b2b/accounts/{$e}/allocations/{$allocationOfRefund}/release", ['reason' => 'B2B release attempt']), 409, 'REFUND_ALLOCATION_FIXED', 'W2 nor release a refund allocation');
$legacy = ok($call($b2bClerk, 'POST', "/b2b/accounts/{$a}/payments", ['currencyCode' => 'RON', 'amount' => '10.00', 'valueDate' => $today, 'method' => 'bank_transfer', 'externalReference' => 'B2B-LEGACY-1']), 201, 'W3 B2B still writes while writes are enabled');
$readOnly = $kernelWith(['financeCollections' => 'enabled', 'b2bAccountWrites' => 'finance_only']);
foreach ([['payments', ['currencyCode' => 'RON', 'amount' => '1.00', 'valueDate' => $today, 'method' => 'cash']], ['adjustments', ['currencyCode' => 'RON', 'direction' => 'credit', 'amount' => '1.00', 'valueDate' => $today, 'reason' => 'x test']],
    ['opening-balances', ['currencyCode' => 'RON', 'direction' => 'credit', 'amount' => '1.00', 'valueDate' => $today, 'reason' => 'x test']], ['allocations', ['paymentId' => $legacy['movementId'], 'allocations' => [['receivableId' => $oa['receivable'], 'amount' => '1.00']]]],
    ['movements/' . $legacy['movementId'] . '/reverse', ['valueDate' => $today, 'reason' => 'x test']]] as [$route, $body]) {
    error($call($b2bClerk, 'POST', "/b2b/accounts/{$a}/{$route}", $body, [], null, $readOnly), 409, 'ACCOUNT_WRITES_MOVED_TO_FINANCE', "W4 server refuses B2B {$route}");
}
$access = ok($call($b2bClerk, 'GET', '/b2b/access', null, [], null, $readOnly), 200, 'W5 access');
check(in_array('accountsReadOnly', $access['features'], true) && !in_array('b2b.accounts.record_payment', $access['permissions'], true) && in_array('b2b.accounts.view', $access['permissions'], true), 'W5 read-only feature, write permissions not offered');
$caps = ok($call($b2bClerk, 'GET', "/b2b/accounts/{$a}", null, [], null, $readOnly), 200, 'W6 summary')['capabilities'];
check($caps['canView'] && !$caps['canRecordPayment'] && !$caps['canAdjust'] && !$caps['canReverse'] && $caps['writesMovedToFinance'] === true, 'W6 even an older B2B hides the buttons (capabilities)');
$wOrder = $order($a, '10', 'RON', $readOnly);
check($wOrder['gross'] === '125.00', 'W7 order receivables are still posted by finalization in read-only mode');
$pendingForB2b = ok($call($recorder, 'POST', '/finance/ledger-requests', $payment($a, '25.00', $bank('OP-A-0025'))), 201, 'W8 pending Finance payment')['request'];
$fr = ok($call($b2bClerk, 'GET', "/b2b/accounts/{$a}/finance-requests", null, [], null, $readOnly), 200, 'W8 B2B sees Finance requests')['items'];
check($fr[0]['code'] === $pendingForB2b['code'] && $fr[0]['status'] === 'PENDING_APPROVAL' && !array_key_exists('bankTransaction', $fr[0]) && !array_key_exists('note', $fr[0]), 'W8 codes, status and amount only');

// ---- O. Order types and the finance gate are independent switches ------------------------------------------------------
$typesOff = $kernelWith(['b2bOrderTypes' => 'off']);
check(ok($call($root, 'GET', '/b2b/access', null, [], null, $typesOff), 200, 'O1')['features'] === [], 'O1 production defaults announce no order type and no finance panel');
check(ok($call($root, 'GET', '/b2b/access', null, [], null, $on), 200, 'O2')['features'] === ['orderTypes'], 'O2 order types on, finance gate off: only orderTypes');
check(ok($call($root, 'GET', '/b2b/access', null, [], null, $kernelWith(['b2bOrderTypes' => 'off', 'b2bFinanceGate' => 'shadow'])), 200, 'O3')['features'] === ['financeGate'], 'O3 finance gate on, order types off: only financeGate');
$o = static fn (?string $type) => ['companyId' => $a, 'currencyCode' => 'RON', 'orderType' => $type, 'contactId' => null, 'billingAddressId' => null, 'deliveryAddressId' => null, 'notes' => null,
    'lines' => [['productCode' => 'CURTAIN', 'productName' => null, 'variant' => null, 'color' => null, 'kind' => 'curtain', 'width' => '200', 'height' => '260', 'quantity' => 1, 'meters' => '10', 'pricingUnit' => 'meter', 'unitPriceNet' => '10.00', 'discountPercent' => '0', 'vatPercent' => '25', 'notes' => null]]];
$typeErr = error($call($root, 'POST', '/b2b/orders', $o('CUT_ONLY'), [], null, $typesOff), 422, 'VALIDATION_FAILED', 'O4 a type is refused while order types are off');
check(($typeErr['details']['fields']['orderType'] ?? null) === 'disabled', 'O4 named field');
$untyped = ok($call($root, 'POST', '/b2b/orders', $o(null), [], null, $typesOff), 201, 'O5 untyped draft')['detail']['order'];
ok($call($root, 'POST', "/b2b/orders/{$untyped['id']}/finalize", ['expectedVersion' => 1], [], null, $typesOff), 200, 'O5 finalization needs no type when off');
check($rows('SELECT order_type FROM b2b_orders WHERE order_uuid = ?', [$untyped['id']])[0]['order_type'] === null, 'O5 the order stays untyped (full route)');
$typed = ok($call($root, 'POST', '/b2b/orders', $o('CUT_ONLY')), 201, 'O6 typed draft while enabled')['detail']['order'];
$copy = ok($call($root, 'POST', "/b2b/orders/{$typed['id']}/duplicate", ['expectedVersion' => 1], [], null, $typesOff), 201, 'O6 duplicate while off')['orderId'];
check($rows('SELECT order_type FROM b2b_orders WHERE order_uuid = ?', [$copy])[0]['order_type'] === null, 'O6 a copy made while off carries no type');
$needsType = ok($call($root, 'POST', '/b2b/orders', $o(null)), 201, 'O7 untyped draft while enabled')['detail']['order'];
error($call($root, 'POST', "/b2b/orders/{$needsType['id']}/finalize", ['expectedVersion' => 1]), 422, 'VALIDATION_FAILED', 'O7 enabled: finalization still requires the type');

// ---- R. Bank reconciliation view and audit ----------------------------------------------------------------------------
$recon = ok($call($viewer, 'GET', '/finance/reconciliation', null, ['currency' => 'RON', 'from' => $today, 'to' => $today]), 200, 'R1 reconciliation');
$ing = array_values(array_filter($recon['totals'], static fn (array $t): bool => $t['bankAccountKey'] === 'ING-RON-01'))[0];
check((int) $ing['count'] >= 8 && $ing['out'] === '1000.00', 'R1 totals per bank account alias: incoming payments and the paid-out refund');
check(in_array('B2B-LEGACY-1', array_column($recon['withoutBankIdentity'], 'externalReference'), true), 'R2 a payment entered in B2B is listed without bank identity');
check(in_array($pendingForB2b['code'], array_column($recon['pending'], 'requestCode'), true), 'R3 pending requests are listed');
error($call($viewer, 'GET', '/finance/reconciliation', null, ['currency' => 'RON', 'from' => '2025-01-01', 'to' => $today]), 422, 'VALIDATION_FAILED', 'R4 at most one year');
$audit = ok($call($root, 'GET', '/finance/audit', null, ['entity' => 'ledger_request']), 200, 'R5 audit')['items'];
check(in_array('ledger_request_approved', array_column($audit, 'action'), true) && in_array('ledger_request_rejected', array_column($audit, 'action'), true) && in_array('refund_paid_out', array_column($audit, 'action'), true), 'R5 the Finance trail records every decision');
foreach ($audit as $event) check(!str_contains(json_encode($event['details']), 'OP-') && !str_contains(json_encode($event['details']), 'ING-RON'), 'R6 audit details hold no bank identity');
error($call($viewer, 'POST', '/finance/ledger-requests', $payment($a, '1.00', $bank('OP-V-1'))), 403, 'UNAUTHORIZED_ACTION', 'R7 a viewer cannot enter');

// ---- Ledger integrity: balance recomputed from immutable rows equals the summary for every test company ----------------
foreach ([$a, $b, $c, $d, $e, $f, $g, $c2] as $co) {
    $cents = 0;
    foreach ($rows("SELECT direction, amount FROM b2b_account_movements WHERE company_uuid = ? AND currency_code = 'RON'", [$co]) as $m) { [$x, $y] = explode('.', $m['amount']); $v = (int) $x * 100 + (int) $y; $cents += $m['direction'] === 'debit' ? $v : -$v; }
    $expected = ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    check($summary($co)['balance'] === $expected, 'ledger truth equals the summary for ' . $co);
}
check((int) $pdo->query("SELECT COUNT(*) FROM finance_ledger_requests WHERE status IN ('APPROVED','PAID_OUT','REVERSED') AND (decided_by_employee_uuid = created_by_employee_uuid OR decided_by_employee_uuid = submitted_by_employee_uuid)")->fetchColumn() === 0, 'no request anywhere was decided by its creator or submitter');
check((int) $pdo->query("SELECT COUNT(*) FROM (SELECT posted_movement_uuid FROM finance_ledger_requests WHERE posted_movement_uuid IS NOT NULL GROUP BY posted_movement_uuid HAVING COUNT(*) > 1) x")->fetchColumn() === 0, 'every movement belongs to at most one request');

// ---- Rollback floor: open Finance work and refund movements need 2.68.0 -------------------------------------------------
$cli = static function (string ...$arguments) use ($config): array {
    $env = ['HOME' => (string) getenv('HOME'), 'ARASYA_APP_ENV' => 'test', 'ARASYA_APP_SECRET' => str_repeat('t', 32), 'ARASYA_ALLOWED_ORIGINS' => T::ORIGIN,
        'ARASYA_DB_HOST' => $config->dbHost, 'ARASYA_DB_PORT' => (string) $config->dbPort, 'ARASYA_DB_NAME' => $config->dbName, 'ARASYA_DB_USER' => $config->dbUser, 'ARASYA_DB_PASSWORD' => $config->dbPassword];
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/check-rollback.php', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    fclose($pipes[0]); $out = (string) stream_get_contents($pipes[1]); $err = (string) stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return ['code' => proc_close($process), 'json' => json_decode($out, true), 'out' => $out . $err];
};
$floor = $cli('2.67.0');
check($floor['code'] === 3 && ($floor['json']['requiredFloor'] ?? null) === '2.68.0' && ($floor['json']['state']['openFinanceCollections'] ?? 0) > 0, 'rollback below 2.68.0 refused while refunds and open requests exist: ' . $floor['out']);
check($cli('2.68.0')['json']['safe'] ?? false, '2.68.0 itself is a safe target');
// Each category on its own: the rows written by the scenarios above are set aside in disposable stash tables and put back
// one category at a time, so every category is proven to pin (or not pin) the floor by itself.
$pdo->exec('DROP TABLE IF EXISTS zz_floor_requests, zz_floor_refunds');
$pdo->exec('CREATE TABLE zz_floor_requests AS SELECT * FROM finance_ledger_requests');
$pdo->exec("CREATE TABLE zz_floor_refunds AS SELECT * FROM b2b_account_movements WHERE movement_type = 'refund'");
$categories = [
    'draft requests' => ["status = 'DRAFT'", null, true],
    'requests waiting for a second person' => ["status = 'PENDING_APPROVAL'", null, true],
    'approved refunds without a recorded payout' => ["kind = 'REFUND' AND status = 'APPROVED'", null, true],
    'refund movements alone' => [null, true, true],
    'completed requests (approved payments and corrections, rejected, withdrawn, paid out, reversed)' => ["status IN ('REJECTED', 'WITHDRAWN', 'PAID_OUT', 'REVERSED') OR (status = 'APPROVED' AND kind <> 'REFUND')", null, false],
    'completed requests with their refund movements' => ["status IN ('REJECTED', 'WITHDRAWN', 'PAID_OUT', 'REVERSED') OR (status = 'APPROVED' AND kind <> 'REFUND')", true, true],
    'nothing from Finance' => [null, null, false],
];
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($categories as $label => [$where, $withRefunds, $pins]) {
    $pdo->exec('DELETE FROM finance_ledger_requests');
    $pdo->exec("DELETE FROM b2b_account_movements WHERE movement_type = 'refund'");
    if ($where !== null) {
        $restored = $pdo->exec("INSERT INTO finance_ledger_requests SELECT * FROM zz_floor_requests WHERE {$where}");
        check($restored > 0, "the scenarios left {$label} to test the floor with ({$restored})");
    }
    if ($withRefunds) check($pdo->exec('INSERT INTO b2b_account_movements SELECT * FROM zz_floor_refunds') > 0, "refund movements restored for {$label}");
    $result = $cli('2.67.0');
    $open = $result['json']['state']['openFinanceCollections'] ?? -1;
    check($pins ? ($result['code'] === 3 && ($result['json']['requiredFloor'] ?? null) === '2.68.0' && $open > 0) : $open === 0,
        ($pins ? 'floor 2.68.0 held by ' : 'no 2.68.0 floor from ') . $label . ': ' . trim($result['out']));
}
$pdo->exec('DELETE FROM finance_ledger_requests');
$pdo->exec('INSERT INTO finance_ledger_requests SELECT * FROM zz_floor_requests');
$pdo->exec('INSERT INTO b2b_account_movements SELECT * FROM zz_floor_refunds');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec('DROP TABLE zz_floor_requests, zz_floor_refunds');
check($cli('2.67.0')['json']['requiredFloor'] === '2.68.0', 'every row put back: the floor is 2.68.0 again');
// Disposable cleanup so later suites on the same test database see their own rollback floors (never done in production:
// the ledger is insert-only there).
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$refunds = "SELECT movement_uuid FROM b2b_account_movements WHERE movement_type = 'refund'";
$pdo->exec("DELETE FROM b2b_account_activity_events WHERE movement_uuid IN (SELECT * FROM ({$refunds}) r) OR movement_uuid IN (SELECT * FROM (SELECT movement_uuid FROM b2b_account_movements WHERE reversed_movement_uuid IN ({$refunds})) v)
    OR allocation_uuid IN (SELECT * FROM (SELECT allocation_uuid FROM b2b_account_allocations WHERE receivable_movement_uuid IN ({$refunds})) a)");
$pdo->exec("DELETE FROM b2b_account_allocation_releases WHERE allocation_uuid IN (SELECT * FROM (SELECT allocation_uuid FROM b2b_account_allocations WHERE receivable_movement_uuid IN ({$refunds})) a)");
$pdo->exec("DELETE FROM b2b_account_allocations WHERE receivable_movement_uuid IN (SELECT * FROM ({$refunds}) r)");
$pdo->exec('DELETE FROM finance_ledger_requests');
$pdo->exec("DELETE FROM b2b_account_movements WHERE reversed_movement_uuid IN (SELECT * FROM ({$refunds}) r)");
$pdo->exec("DELETE FROM b2b_account_movements WHERE movement_type = 'refund'");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
check(($cli('2.67.0')['json']['state']['openFinanceCollections'] ?? -1) === 0, 'after the disposable cleanup no Finance floor remains');

echo "PASS MySQL Finance collections: {$checks} checks (four eyes in API and SQL, one bank transaction once, refunds with payout, races, retries, revocation, B2B read-only mode, independent order types).\n";
