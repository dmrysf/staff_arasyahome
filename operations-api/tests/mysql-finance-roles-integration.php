<?php
declare(strict_types=1);
// Finance roles (docs/finance-iam-matrix.md section 3) exactly as created in production on 2026-11-11 through
// POST /management/roles: separation of duties, four eyes (also for root), default deny, the production switches
// (collections and opening balance posting disabled, empty decision authority list), revocation on the next request,
// and negative controls proving the checks catch an over-permissive role. Disposable database, synthetic people only.
use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
$db = T::requireTestDatabase();
if ($db === null) { echo "SKIP MySQL Finance roles: ARASYA_TEST_DB_NAME not configured.\n"; exit; }
$checks = 0; $log = [];
function check(bool $v, string $m): void { global $checks, $log; $checks++; $log[] = $m; if (!$v) throw new RuntimeException('FAIL ' . $m); }
function ok(array $r, int $s, string $m): array { check($r['status'] === $s, $m . ' got ' . $r['status'] . ' ' . json_encode($r['body'])); return $r['body'] ?? []; }
function err(array $r, int $s, string $code, string $m): array { check($r['status'] === $s && (($r['body']['error']['code'] ?? null) === $code), "{$m} [{$s} {$code}] got {$r['status']} " . json_encode($r['body']['error']['code'] ?? $r['body'])); return $r['body']['error']; }
function cui(string $base): string { $body = str_pad($base, 9, '0', STR_PAD_LEFT); $key = '753217532'; $sum = 0; for ($i = 0; $i < 9; $i++) { $sum += (int) $body[$i] * (int) $key[$i]; } $c = ($sum * 10) % 11; return $base . ($c === 10 ? 0 : $c); }

// The production role definitions (name => [expected key, description, permissions]), identical to the roles created in production.
const ROLES = [
    'Finance Operator' => ['finance-operator', 'Operator Finance: importuri, cazuri de verificare, onboarding, propunere sold inițial.', ['finance.companies.view', 'finance.companies.import', 'finance.companies.review', 'finance.companies.resolve_duplicates', 'finance.accounts.view', 'finance.accounts.reconcile', 'finance.accounts.propose_opening_balance']],
    'Finance Verifier' => ['finance-verifier', 'Verificator Finance: a doua persoană pentru cazuri și solduri inițiale pe care nu le-a pregătit.', ['finance.companies.view', 'finance.companies.verify', 'finance.accounts.view', 'finance.accounts.verify_opening_balance']],
    'Finance Poster' => ['finance-poster', 'Înregistrare sold inițial verificat (doar când înregistrarea este activată pe server).', ['finance.accounts.view', 'finance.accounts.post_approved_opening_balance']],
    'Finance Supervisor' => ['finance-supervisor', 'Supervizor Finance: supraveghere, revizuire independentă, citire jurnal verificare B2B.', ['finance.companies.view', 'finance.companies.supervise', 'finance.accounts.view', 'finance.risk.view', 'finance.credit.view', 'finance.audit.view']],
    'Finance Owner Decision' => ['finance-owner-decision', 'Decizie risc și limită: efectivă doar pentru persoanele din lista de autoritate a serverului.', ['finance.companies.view', 'finance.accounts.view', 'finance.risk.view', 'finance.risk.approve', 'finance.credit.view', 'finance.credit.approve', 'finance.credit.revoke']],
    'Finance Auditor' => ['finance-auditor', 'Auditor Finance: doar citire, inclusiv valorile vechi și noi din audit.', ['finance.companies.view', 'finance.accounts.view', 'finance.risk.view', 'finance.credit.view', 'finance.audit.view', 'finance.audit.sensitive']],
    'Finance Cashier' => ['finance-cashier', 'Încasări: introduce încasări și cereri de rambursare, alocă pe comenzi.', ['finance.accounts.view', 'finance.collections.record', 'finance.refunds.request', 'finance.reconciliation.manage']],
    'Finance Controller' => ['finance-controller', 'Verificare încasări: a doua persoană pentru încasări, corecții și rambursări.', ['finance.accounts.view', 'finance.collections.approve', 'finance.ledger.approve', 'finance.refunds.approve']],
    'Finance Payout' => ['finance-payout', 'Plăți rambursări: confirmă plata băncii pentru o rambursare aprobată.', ['finance.accounts.view', 'finance.refunds.payout']],
    'Finance Corrections' => ['finance-corrections', 'Cereri corecții: cere ajustări și stornări, aprobate de altă persoană.', ['finance.accounts.view', 'finance.ledger.request']],
];

$config = T::config($db);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') as $f) (new SqlFileRunner($pdo))->run($f);
$args = get_object_vars($config);
$kernelWith = static fn (array $over): \Arasya\Operations\Http\ApiKernel => (new Container(new Config(...array_replace($args, $over)), $pdo))->kernel();
$base = new Container($config, $pdo);
// Production switches: collections disabled, opening balance posting disabled, empty authority list, no CEO.
$prod = $kernelWith(['financeCollections' => 'disabled', 'financeOpeningBalancePosting' => 'disabled', 'financeRiskLimitAuthorityUuids' => '', 'financeCeoEmployeeUuid' => '']);
$on = $kernelWith(['financeCollections' => 'enabled', 'financeOpeningBalancePosting' => 'disabled', 'financeRiskLimitAuthorityUuids' => '', 'financeCeoEmployeeUuid' => '']);
$posting = $kernelWith(['financeCollections' => 'disabled', 'financeOpeningBalancePosting' => 'enabled', 'financeRiskLimitAuthorityUuids' => '', 'financeCeoEmployeeUuid' => '']);
$suffix = bin2hex(random_bytes(3));
$pdo->exec('DELETE FROM system_root_identity');
$rootUser = 'finroles.root.' . $suffix;
$temporary = (new RootBootstrapService($pdo, $base->passwordHasher(), $base->clock()))->bootstrap('finance-roles-test', $rootUser);
$first = T::login($prod, $rootUser, $temporary);
ok(T::call($prod, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Finance roles root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 200, 'root password');
$root = T::login($prod, $rootUser, 'Finance roles root passphrase 2026!');
$call = static function (array $who, string $method, string $path, ?array $json = null, ?\Arasya\Operations\Http\ApiKernel $k = null, array $query = []) use ($prod): array {
    return T::call($k ?? $prod, $method, $path, $json, $method === 'GET' ? [] : ['x-csrf-token' => $who['csrf'], 'idempotency-key' => 'finroles-' . bin2hex(random_bytes(10))], $who['cookie'], $query);
};

// ---- R. Role creation exactly as in production ---------------------------------------------------------------------
$grantsBefore = $pdo->query('SELECT role_id, permission_id FROM role_permissions ORDER BY 1, 2')->fetchAll(PDO::FETCH_NUM);
$assignBefore = (int) $pdo->query('SELECT COUNT(*) FROM employee_role_assignments')->fetchColumn();
$financeAppsBefore = $pdo->query("SELECT employee_uuid FROM employee_application_access WHERE application_key = 'finance' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$rootHasRow = (int) $pdo->query("SELECT COUNT(*) FROM employee_application_access eaa JOIN system_root_identity s ON s.employee_uuid = eaa.employee_uuid WHERE eaa.application_key = 'finance'")->fetchColumn();
err($call($root, 'POST', '/management/roles', ['name' => 'Finance Access Attempt', 'description' => null, 'authorityRank' => 200, 'permissions' => ['finance.access']]), 403, 'PERMISSION_NOT_GRANTABLE', 'R0 finance.access cannot be given through a role');
$roleId = [];
foreach (ROLES as $name => [$key, $description, $permissions]) {
    $r = ok($call($root, 'POST', '/management/roles', ['name' => $name, 'description' => $description, 'authorityRank' => 200, 'permissions' => $permissions]), 201, "R1 create {$key}")['role'];
    check($r['key'] === $key, "R1 {$name} gets the key {$key} (got {$r['key']})");
    $stored = $pdo->prepare('SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id WHERE rp.role_id = ? ORDER BY 1');
    $stored->execute([$r['id']]);
    $want = $permissions; sort($want);
    check($stored->fetchAll(PDO::FETCH_COLUMN) === $want, "R2 {$key} holds exactly its permissions, nothing else");
    $roleId[$key] = (int) $r['id'];
}
check((int) $pdo->query('SELECT COUNT(*) FROM employee_role_assignments')->fetchColumn() === $assignBefore, 'R3 creating roles assigns nobody');
check($pdo->query("SELECT employee_uuid FROM employee_application_access WHERE application_key = 'finance' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN) === $financeAppsBefore, 'R4 creating roles grants no Finance application');
$other = $pdo->query('SELECT role_id, permission_id FROM role_permissions WHERE role_id NOT IN (' . implode(',', $roleId) . ') ORDER BY 1, 2')->fetchAll(PDO::FETCH_NUM);
check($other === $grantsBefore, 'R5 no existing role changed');

// ---- People: one synthetic employee per role (Finance application + that role), plus combinations ----------------------
$admin = $base->employeeAdmin();
$password = 'finance roles synthetic passphrase 2026';
$person = static function (string $name, array $applications, array $roleKeys) use ($admin, $call, $root, $password, $suffix, $prod, &$roleId): array {
    $employee = $admin->create("Finroles {$name}", "finroles.{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test');
    ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications]), 200, "{$name} applications");
    if ($roleKeys !== []) ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => array_map(fn ($k) => $roleId[$k], $roleKeys)]), 200, "{$name} roles");
    return T::login($prod, "finroles.{$name}.{$suffix}", $password) + ['id' => $employee->employeeUuid];
};
$p = [];
foreach (ROLES as [$key]) $p[$key] = $person(str_replace('finance-', '', $key), ['finance'], [$key]);
$opVer = $person('opver', ['finance'], ['finance-operator', 'finance-verifier']);
$cashCtl = $person('cashctl', ['finance'], ['finance-cashier', 'finance-controller']);
$ctlCorr = $person('ctlcorr', ['finance'], ['finance-controller', 'finance-corrections']);
$cashPay = $person('cashpay', ['finance'], ['finance-cashier', 'finance-payout']);
$noApp = $person('noapp', ['staff'], ['finance-operator']);
$appOnly = $person('apponly', ['finance'], []);
$staffOnly = $person('staffonly', ['staff'], []);

// ---- A. Capabilities: every role sees exactly its permissions; nobody else gets Finance ------------------------------
$capMap = \Arasya\Operations\Finance\FinanceAccess::CAPABILITIES;
foreach (ROLES as [$key, , $permissions]) {
    $caps = ok($call($p[$key], 'GET', '/finance/access'), 200, "A1 {$key} opens Finance")['capabilities'];
    $true = array_keys(array_filter($caps)); sort($true);
    $want = array_keys(array_filter($capMap, fn ($perm) => in_array($perm, $permissions, true))); sort($want);
    check($true === $want, "A1 {$key} capabilities equal its permissions (" . implode(',', $true) . ')');
}
err($call($noApp, 'GET', '/finance/access'), 403, 'APPLICATION_ACCESS_DENIED', 'A2 a Finance role without the Finance application opens nothing');
err($call($noApp, 'GET', '/finance/companies'), 403, 'APPLICATION_ACCESS_DENIED', 'A2 nor reads companies');
err($call($staffOnly, 'GET', '/finance/b2b/accounts'), 403, 'APPLICATION_ACCESS_DENIED', 'A3 a Staff employee sees no Finance data');
$appCaps = ok($call($appOnly, 'GET', '/finance/access'), 200, 'A4 application alone opens the shell')['capabilities'];
check(array_filter($appCaps) === [], 'A4 application alone: no capability (default deny)');
err($call($appOnly, 'GET', '/finance/companies'), 403, 'UNAUTHORIZED_ACTION', 'A4 application alone reads no data');
$rootCaps = ok($call($root, 'GET', '/finance/access'), 200, 'A5 root opens Finance')['capabilities'];
check(count(array_filter($rootCaps)) === count($capMap), 'A5 root holds the whole Finance catalogue (designed behaviour)');

// ---- C. Company cases: operator prepares, verifier approves; nobody approves own work -------------------------------
$evidence = [['kind' => 'registry_extract', 'sourceSystem' => 'REGISTRY', 'reference' => 'Synthetic registry extract Finroles', 'documentDate' => '2026-11-01']];
$prepare = static function (array $who, string $name, int $seed) use ($call, $evidence): array {
    $id = ok($call($who, 'POST', '/finance/cases', ['legalName' => $name, 'countryCode' => 'RO', 'taxIdentifier' => cui((string) $seed), 'addressLine' => 'Str. Finroles 1', 'city' => 'Brasov']), 201, "case {$name}")['caseId'];
    $v = ok($call($who, 'GET', "/finance/cases/{$id}"), 200, 'case')['case']['version'];
    $v = ok($call($who, 'POST', "/finance/cases/{$id}/claim", ['expectedVersion' => $v]), 200, 'claim')['version'];
    foreach (['legal_name', 'country', 'tax_id', 'duplicate_search', 'address', 'contact', 'sources', 'documents', 'conflicts'] as $item) {
        $v = ok($call($who, 'POST', "/finance/cases/{$id}/checklist", ['expectedVersion' => $v, 'item' => $item, 'state' => 'done'] + ($item === 'tax_id' ? ['evidence' => $evidence] : [])), 200, "checklist {$item}")['version'];
    }
    return [$id, ok($call($who, 'POST', "/finance/cases/{$id}/submit", ['expectedVersion' => $v]), 200, 'submit')['version']];
};
[$c1, $v1] = $prepare($p['finance-operator'], 'Finroles Alfa SRL ' . $suffix, 7100000 + random_int(1, 99999));
err($call($p['finance-operator'], 'POST', "/finance/cases/{$c1}/approve", ['expectedVersion' => $v1]), 403, 'UNAUTHORIZED_ACTION', 'C1 the operator cannot verify its own case');
$alfa = ok($call($p['finance-verifier'], 'POST', "/finance/cases/{$c1}/approve", ['expectedVersion' => $v1]), 200, 'C2 the verifier approves a case another employee prepared')['companyId'];
check(is_string($alfa) && $alfa !== '', 'C2 a canonical company exists after the second person');
[$c2, $v2] = $prepare($opVer, 'Finroles Beta SRL ' . $suffix, 7200000 + random_int(1, 99999));
err($call($opVer, 'POST', "/finance/cases/{$c2}/approve", ['expectedVersion' => $v2]), 403, 'FOUR_EYES_REQUIRED', 'C3 operator+verifier on one person still cannot approve own case');
[$c3, $v3] = $prepare($root, 'Finroles Gamma SRL ' . $suffix, 7400000 + random_int(1, 99999));
err($call($root, 'POST', "/finance/cases/{$c3}/approve", ['expectedVersion' => $v3]), 403, 'FOUR_EYES_REQUIRED', 'C4 root cannot approve a case it submitted');
foreach (['finance-auditor', 'finance-supervisor', 'finance-cashier', 'finance-controller', 'finance-poster', 'finance-payout', 'finance-corrections', 'finance-owner-decision'] as $k) {
    err($call($p[$k], 'POST', '/finance/cases', ['legalName' => 'Not allowed ' . $k]), 403, 'UNAUTHORIZED_ACTION', "C5 {$k} cannot create a verification case");
}

// ---- O. Opening balance: operator proposes, verifier verifies, poster posts only when enabled ------------------------
$o = ok($call($p['finance-operator'], 'POST', '/finance/accounts', ['companyId' => $alfa]), 201, 'O1 operator opens onboarding')['onboardingId'];
err($call($p['finance-verifier'], 'POST', '/finance/accounts', ['companyId' => $alfa]), 403, 'UNAUTHORIZED_ACTION', 'O1 the verifier cannot open onboarding');
$yesterday = (new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest')))->modify('-1 day')->format('Y-m-d');
$ev = [['kind' => 'saga_ledger', 'sourceSystem' => 'SAGA_EXPORT', 'reference' => 'Synthetic partner ledger Finroles', 'documentDate' => $yesterday, 'amount' => '150.00', 'currency' => 'RON']];
$ov = ok($call($p['finance-operator'], 'POST', "/finance/accounts/{$o}/draft", ['expectedVersion' => 1, 'balanceKind' => 'DEBIT', 'amount' => '150.00', 'asOfDate' => $yesterday, 'note' => 'Synthetic Finroles', 'evidence' => $ev]), 200, 'O2 draft')['version'];
$ov = ok($call($p['finance-operator'], 'POST', "/finance/accounts/{$o}/submit", ['expectedVersion' => $ov]), 200, 'O2 submit')['version'];
err($call($p['finance-operator'], 'POST', "/finance/accounts/{$o}/verify", ['expectedVersion' => $ov]), 403, 'UNAUTHORIZED_ACTION', 'O3 the operator cannot verify its own proposal');
$ov = ok($call($p['finance-verifier'], 'POST', "/finance/accounts/{$o}/verify", ['expectedVersion' => $ov]), 200, 'O4 the verifier verifies another employee\'s proposal')['version'];
err($call($p['finance-poster'], 'POST', "/finance/accounts/{$o}/post", ['expectedVersion' => $ov]), 409, 'FINANCE_POSTING_DISABLED', 'O5 production default: the poster cannot post');
err($call($p['finance-verifier'], 'POST', "/finance/accounts/{$o}/post", ['expectedVersion' => $ov], $posting), 403, 'UNAUTHORIZED_ACTION', 'O6 the verifier cannot post');
ok($call($p['finance-poster'], 'POST', "/finance/accounts/{$o}/post", ['expectedVersion' => $ov], $posting), 200, 'O7 isolated, switch on: the poster posts a verified balance it did not propose');

// ---- L. Collections, corrections, refunds (isolated kernel with collections enabled) --------------------------------
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d');
$co = ok($call($root, 'POST', '/b2b/companies', ['legalName' => "Finroles Ledger {$suffix}", 'displayName' => 'Finroles', 'countryCode' => 'RO', 'taxIdentifier' => 'Finroles' . $suffix]), 201, 'ledger company')['companyId'];
$bank = static fn (string $tx) => ['provider' => 'ING', 'accountKey' => 'ING-RON-01', 'transactionId' => $tx . '-' . bin2hex(random_bytes(3))];
$pay = static fn (string $amount, string $tx) => ['kind' => 'PAYMENT', 'companyId' => $co, 'currencyCode' => 'RON', 'amount' => $amount, 'valueDate' => $today, 'method' => 'bank_transfer', 'bankTransaction' => $bank($tx), 'allocations' => []];
err($call($p['finance-cashier'], 'POST', '/finance/ledger-requests', $pay('1.00', 'Finroles-OFF')), 409, 'FINANCE_COLLECTIONS_DISABLED', 'L0 production default: the cashier cannot record');
$r1 = ok($call($p['finance-cashier'], 'POST', '/finance/ledger-requests', $pay('100.00', 'Finroles-P1'), $on), 201, 'L1 cashier records a payment')['request'];
err($call($p['finance-cashier'], 'POST', "/finance/ledger-requests/{$r1['id']}/approve", ['expectedVersion' => 1], $on), 403, 'UNAUTHORIZED_ACTION', 'L2 the cashier cannot approve its own payment');
$payment = ok($call($p['finance-controller'], 'POST', "/finance/ledger-requests/{$r1['id']}/approve", ['expectedVersion' => 1], $on), 200, 'L3 the controller approves another employee\'s payment')['movementId'];
$r2 = ok($call($cashCtl, 'POST', '/finance/ledger-requests', $pay('10.00', 'Finroles-P2'), $on), 201, 'L4 cashier+controller records')['request'];
err($call($cashCtl, 'POST', "/finance/ledger-requests/{$r2['id']}/approve", ['expectedVersion' => 1], $on), 403, 'FOUR_EYES_REQUIRED', 'L4 cashier+controller cannot approve its own payment');
err($call($cashCtl, 'POST', "/finance/ledger-requests/{$r2['id']}/reject", ['expectedVersion' => 1, 'reason' => 'own'], $on), 403, 'FOUR_EYES_REQUIRED', 'L4 nor reject it');
$adj = ['kind' => 'ADJUSTMENT', 'companyId' => $co, 'currencyCode' => 'RON', 'amount' => '5.00', 'direction' => 'credit', 'valueDate' => $today, 'note' => 'Synthetic Finroles rounding'];
err($call($p['finance-controller'], 'POST', '/finance/ledger-requests', $adj, $on), 403, 'UNAUTHORIZED_ACTION', 'L5 the controller alone cannot request a correction');
$r3 = ok($call($ctlCorr, 'POST', '/finance/ledger-requests', $adj, $on), 201, 'L6 controller+corrections requests a correction')['request'];
err($call($ctlCorr, 'POST', "/finance/ledger-requests/{$r3['id']}/approve", ['expectedVersion' => 1], $on), 403, 'FOUR_EYES_REQUIRED', 'L6 the controller cannot approve a request it created, even with the approve permission');
ok($call($p['finance-controller'], 'POST', "/finance/ledger-requests/{$r3['id']}/approve", ['expectedVersion' => 1], $on), 200, 'L7 another controller approves the correction');
$refund = static fn (string $amount) => ['kind' => 'REFUND', 'companyId' => $co, 'sourcePaymentId' => $payment, 'amount' => $amount, 'valueDate' => $today, 'method' => 'bank_transfer', 'note' => 'Synthetic Finroles refund'];
$f1 = ok($call($p['finance-cashier'], 'POST', '/finance/ledger-requests', $refund('20.00'), $on), 201, 'F1 cashier requests a refund')['request'];
err($call($p['finance-cashier'], 'POST', "/finance/ledger-requests/{$f1['id']}/approve", ['expectedVersion' => 1], $on), 403, 'UNAUTHORIZED_ACTION', 'F2 the requester cannot approve the refund');
ok($call($p['finance-controller'], 'POST', "/finance/ledger-requests/{$f1['id']}/approve", ['expectedVersion' => 1], $on), 200, 'F3 the controller approves the refund');
err($call($p['finance-cashier'], 'POST', "/finance/ledger-requests/{$f1['id']}/payout", ['expectedVersion' => 2, 'bankTransaction' => $bank('Finroles-OUT1'), 'paidOn' => $today], $on), 403, 'UNAUTHORIZED_ACTION', 'F4 the requester cannot record the payout');
err($call($p['finance-controller'], 'POST', "/finance/ledger-requests/{$f1['id']}/payout", ['expectedVersion' => 2, 'bankTransaction' => $bank('Finroles-OUT1'), 'paidOn' => $today], $on), 403, 'UNAUTHORIZED_ACTION', 'F4 the approver cannot record the payout');
ok($call($p['finance-payout'], 'POST', "/finance/ledger-requests/{$f1['id']}/payout", ['expectedVersion' => 2, 'bankTransaction' => $bank('Finroles-OUT1'), 'paidOn' => $today], $on), 200, 'F5 the payout role records the bank payment');
$f2 = ok($call($cashPay, 'POST', '/finance/ledger-requests', $refund('10.00'), $on), 201, 'F6 cashier+payout requests a refund')['request'];
ok($call($p['finance-controller'], 'POST', "/finance/ledger-requests/{$f2['id']}/approve", ['expectedVersion' => 1], $on), 200, 'F6 the controller approves');
err($call($cashPay, 'POST', "/finance/ledger-requests/{$f2['id']}/payout", ['expectedVersion' => 2, 'bankTransaction' => $bank('Finroles-OUT2'), 'paidOn' => $today], $on), 403, 'FOUR_EYES_REQUIRED', 'F6 the requester cannot record its own refund payout, even with the payout permission');
$rootPay = ok($call($root, 'POST', '/finance/ledger-requests', $pay('3.00', 'Finroles-R'), $on), 201, 'L8 root records (isolated)')['request'];
err($call($root, 'POST', "/finance/ledger-requests/{$rootPay['id']}/approve", ['expectedVersion' => 1], $on), 403, 'FOUR_EYES_REQUIRED', 'L8 root cannot approve its own request');
ok($call($p['finance-controller'], 'POST', "/finance/ledger-requests/{$rootPay['id']}/approve", ['expectedVersion' => 1], $on), 200, 'L8 a second person approves root\'s request');

// ---- V. Read-only roles write nothing --------------------------------------------------------------------------------
$writes = [['/finance/cases', ['legalName' => 'X']], ['/finance/accounts', ['companyId' => $alfa]], ['/finance/risk', ['companyId' => $alfa, 'classification' => 'YELLOW', 'reason' => 'Synthetic Finroles risk proposal reason']],
    ['/finance/credit', ['companyId' => $alfa, 'amount' => '1.00', 'currency' => 'RON', 'effectiveFrom' => $today, 'reason' => 'Synthetic Finroles credit proposal reason']], ['/finance/ledger-requests', $pay('1.00', 'Finroles-V')], ["/finance/ledger/companies/{$co}/allocations", ['paymentId' => $payment, 'allocations' => [['receivableId' => '00000000-0000-4000-8000-000000000001', 'amount' => '1.00']]]]];
foreach (['finance-auditor', 'finance-supervisor'] as $k) foreach ($writes as [$path, $body]) {
    $r = $call($p[$k], 'POST', $path, $body, $on);
    check($r['status'] === 403 && in_array($r['body']['error']['code'] ?? '', ['UNAUTHORIZED_ACTION'], true), "V1 {$k} cannot write {$path} (got {$r['status']} " . ($r['body']['error']['code'] ?? '') . ')');
}
foreach (['/finance/companies', '/finance/risk', '/finance/credit', '/finance/audit'] as $path) ok($call($p['finance-supervisor'], 'GET', $path), 200, "V2 supervisor reads {$path}");
ok($call($p['finance-auditor'], 'GET', '/finance/audit'), 200, 'V3 auditor reads the audit trail');
$state = ok($call($p['finance-owner-decision'], 'GET', "/finance/owner/companies/{$alfa}"), 200, 'V4 owner-decision role reads the decision screen')['state'];
$decision = ['expectedState' => $state, 'classification' => 'YELLOW', 'effect' => 'EFFECTIVE', 'reason' => 'Synthetic Finroles owner decision'];
$owner = $call($p['finance-owner-decision'], 'POST', "/finance/owner/companies/{$alfa}/risk", $decision);
check($owner['status'] === 403 && ($owner['body']['error']['code'] ?? '') === 'RISK_LIMIT_AUTHORITY_REQUIRED', 'V4 owner-decision role without the authority list decides nothing (got ' . ($owner['body']['error']['code'] ?? $owner['status']) . ')');
$rootOwner = $call($root, 'POST', "/finance/owner/companies/{$alfa}/risk", $decision);
check($rootOwner['status'] === 403 && ($rootOwner['body']['error']['code'] ?? '') === 'RISK_LIMIT_AUTHORITY_REQUIRED', 'V5 root is no substitute for the authority list');

// ---- N. Negative controls: the checks above detect a drifted role, and the server still holds the line --------------
$overreach = ok($call($root, 'POST', '/management/roles', ['name' => 'Finance Overreach ' . $suffix, 'description' => null, 'authorityRank' => 200,
    'permissions' => [...ROLES['Finance Operator'][2], 'finance.companies.verify']]), 201, 'N1 an over-permissive test role')['role'];
$drift = $person('drift', ['finance'], []);
ok($call($root, 'PUT', "/management/employees/{$drift['id']}/roles", ['roleIds' => [(int) $overreach['id']]]), 200, 'N1 assign the drifted role');
$driftCaps = array_keys(array_filter(ok($call($drift, 'GET', '/finance/access'), 200, 'N1 access')['capabilities'])); sort($driftCaps);
$operatorCaps = array_keys(array_filter($capMap, fn ($perm) => in_array($perm, ROLES['Finance Operator'][2], true))); sort($operatorCaps);
check($driftCaps !== $operatorCaps && in_array('canVerifyCompanies', $driftCaps, true), 'N1 the capability comparison detects the extra verify permission (A1 would fail)');
[$cd, $vd] = $prepare($drift, 'Finroles Drift SRL ' . $suffix, 7500000 + random_int(1, 99999));
err($call($drift, 'POST', "/finance/cases/{$cd}/approve", ['expectedVersion' => $vd]), 403, 'FOUR_EYES_REQUIRED', 'N2 even with the drifted role the preparer never approves its own case');
$noAppCaps = $call($noApp, 'GET', '/finance/access');
check($noAppCaps['status'] !== 200, 'N3 a role alone never opens Finance (a 200 here would mean the application gate is missing)');
ok($call($root, 'PUT', "/management/employees/{$drift['id']}/roles", ['roleIds' => []]), 200, 'N4 remove the drifted role');
ok($call($root, 'DELETE', '/management/roles/' . (int) $overreach['id']), 200, 'N4 delete the test role');
check((int) $pdo->query('SELECT COUNT(*) FROM roles WHERE role_id = ' . (int) $overreach['id'])->fetchColumn() === 0, 'N4 the drifted role is gone');

// ---- X. Revocation applies on the next request -------------------------------------------------------------------------
ok($call($p['finance-operator'], 'GET', '/finance/companies'), 200, 'X0 operator reads before revocation');
ok($call($root, 'PUT', "/management/employees/{$p['finance-operator']['id']}/roles", ['roleIds' => []]), 200, 'X1 root removes the role');
err($call($p['finance-operator'], 'GET', '/finance/companies'), 403, 'UNAUTHORIZED_ACTION', 'X1 next request refused, no new login');
ok($call($root, 'PUT', "/management/employees/{$p['finance-verifier']['id']}/applications", ['applications' => []]), 200, 'X2 root removes the Finance application');
err($call($p['finance-verifier'], 'GET', '/finance/access'), 403, 'APPLICATION_ACCESS_DENIED', 'X2 next request refused');

echo "PASS MySQL Finance roles: {$checks} checks (ten matrix roles with exact permissions, application gate, default deny, four eyes for operator/verifier, cashier/controller, corrections, refunds and root, read-only roles, owner decisions without the authority list, production switches, negative drift controls, revocation on the next request).\n";
