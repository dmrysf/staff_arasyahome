<?php

declare(strict_types=1);

// Finance V0.4.0 (migration 049): owner-direct risk colour and purchase-limit decisions (finance_arasyahome#19).
// Two synthetic decision makers stand in for Mesut Yeman and Zelal Yeman: each decides alone, neither approves the
// other, every decision is journalled. Everyone else (accountant, Finance administrator, root, look-alike names) is
// refused. Synthetic data only; no real employee, customer or UUID.

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
if ($db === null) {
    echo "SKIP MySQL Finance owner decisions: ARASYA_TEST_DB_NAME not configured.\n";
    exit;
}
$checks = 0;
function check(bool $value, string $message): void { global $checks; $checks++; if (!$value) throw new RuntimeException('FAIL ' . $message); }
function ok(array $r, int $status, string $message): array { check($r['status'] === $status, $message . ' got ' . $r['status'] . ' ' . json_encode($r['body'])); return $r['body'] ?? []; }
function err(array $r, int $status, string $code, string $message): array { ok($r, $status, $message); check(($r['body']['error']['code'] ?? null) === $code, $message . ' code ' . json_encode($r['body'])); return $r['body']; }
function cui(string $base): string { $body = str_pad($base, 9, '0', STR_PAD_LEFT); $key = '753217532'; $sum = 0; for ($i = 0; $i < 9; $i++) { $sum += (int) $body[$i] * (int) $key[$i]; } $c = ($sum * 10) % 11; return $base . ($c === 10 ? 0 : $c); }
function refused(PDO $pdo, string $sql, array $params = []): bool { try { $pdo->prepare($sql)->execute($params); return false; } catch (PDOException) { return true; } }

$db = substr($db, 0, 40) . '_finance_v040';
$server = new PDO('mysql:host=' . (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('ARASYA_TEST_DB_PORT') ?: '3306'), (string) getenv('ARASYA_TEST_DB_USER'), (string) getenv('ARASYA_TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `{$db}`");
$server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$config = T::config($db);
$pdo = Connection::create($config);
$dir = dirname(__DIR__) . '/database/migrations';
(new MigrationRunner($pdo))->migrate($dir);
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') as $file) (new SqlFileRunner($pdo))->run($file);
$scalar = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$rows = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };

// ---- 049: additive, re-runnable, grants nothing -------------------------------------------------------------------
$grants = $rows('SELECT * FROM role_permissions ORDER BY role_id, permission_id');
$appGrants = $rows('SELECT * FROM employee_application_access ORDER BY employee_uuid, application_key');
$permissionsBefore = $rows('SELECT permission_key, role_grantable FROM permissions ORDER BY permission_key');
(new SqlFileRunner($pdo))->run($dir . '/049_finance_owner_risk_limit_decisions.sql');
check($rows('SELECT * FROM role_permissions ORDER BY role_id, permission_id') === $grants && $rows('SELECT * FROM employee_application_access ORDER BY employee_uuid, application_key') === $appGrants, '24. 049 re-run grants nothing');
check($rows('SELECT permission_key, role_grantable FROM permissions ORDER BY permission_key') === $permissionsBefore, '049 adds no permission');
$constraints = array_column($rows("SELECT constraint_name AS name FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name IN ('finance_risk_assessments', 'finance_credit_limits', 'finance_owner_decisions') AND constraint_type = 'CHECK'"), 'name');
foreach (['chk_finance_risk_separation_049', 'chk_finance_credit_verified_049', 'chk_finance_credit_approved_049', 'chk_finance_credit_risk_basis_049', 'chk_finance_risk_mode', 'chk_finance_credit_mode', 'chk_finance_owner_decisions_effect'] as $name) check(in_array($name, $constraints, true), "constraint {$name}");
foreach (['chk_finance_risk_separation', 'chk_finance_credit_verified', 'chk_finance_credit_approved'] as $name) check(!in_array($name, $constraints, true), "047 constraint {$name} replaced");
check((int) $scalar("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_owner_decisions' AND (DELETE_RULE NOT IN ('RESTRICT', 'NO ACTION') OR UPDATE_RULE NOT IN ('RESTRICT', 'NO ACTION'))") === 0, 'no cascading foreign keys');
check((int) $scalar('SELECT @@foreign_key_checks') === 1, 'foreign keys stay enforced');

// ---- Identities -----------------------------------------------------------------------------------------------------
$base = new Container($config, $pdo);
$kernel = $base->kernel();
$suffix = bin2hex(random_bytes(4));
$pdo->exec('DELETE FROM system_root_identity');
$rootUser = 'finance.root.' . $suffix;
$temporary = (new RootBootstrapService($pdo, $base->passwordHasher(), $base->clock()))->bootstrap('finance-test', $rootUser);
$first = T::login($kernel, $rootUser, $temporary);
ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Finance root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 200, 'root password');
$root = T::login($kernel, $rootUser, 'Finance root passphrase 2026!');
$call = static fn (array $who, string $method, string $path, ?array $json = null, array $query = [], ?string $key = null, ?\Arasya\Operations\Http\ApiKernel $k = null): array
    => T::call($k ?? $kernel, $method, $path, $json, $method === 'GET' ? [] : ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? ('fin-test-' . bin2hex(random_bytes(10)))], $who['cookie'], $query);
$password = 'finance test passphrase 2026';
$admin = $base->employeeAdmin();
$roles = [];
$person = static function (string $name, array $permissions, string $display = '') use ($admin, $call, $root, $password, $suffix, $kernel, &$roles): array {
    $employee = $admin->create($display !== '' ? $display : "Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test');
    ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['finance']]), 200, "{$name} applications");
    if ($permissions !== []) {
        $roleId = (int) ok($call($root, 'POST', '/management/roles', ['name' => "{$name} {$suffix}", 'description' => null, 'authorityRank' => 200, 'permissions' => $permissions]), 201, "role {$name}")['role']['id'];
        $roles[$name] = $roleId;
        ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => [$roleId]]), 200, "{$name} roles");
    }
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['id' => $employee->employeeUuid, 'username' => "{$name}.{$suffix}"];
};
$ownerPermissions = ['finance.risk.view', 'finance.risk.approve', 'finance.credit.view', 'finance.credit.approve', 'finance.credit.revoke'];
$view = 'finance.companies.view';
$ownerA = $person('ownera', $ownerPermissions, 'Synthetic Owner A');
$ownerB = $person('ownerb', $ownerPermissions, 'Synthetic Owner B');
$ownerC = $person('ownerc', [...$ownerPermissions, $view, 'finance.companies.review', 'finance.companies.verify'], 'Synthetic Owner C');
$reviewer = $person('reviewer', [$view, 'finance.companies.review']);
$verifier = $person('verifier', [$view, 'finance.companies.verify']);
$acc1 = $person('accone', ['finance.accounts.view', 'finance.accounts.reconcile', 'finance.accounts.propose_opening_balance']);
$acc2 = $person('acctwo', ['finance.accounts.view', 'finance.accounts.reconcile', 'finance.accounts.verify_opening_balance', 'finance.accounts.post_approved_opening_balance']);
$accountant = $person('accountant', ['finance.risk.view', 'finance.risk.propose', 'finance.credit.view', 'finance.credit.propose']);
$finAdmin = $person('finadmin', ['finance.risk.view', 'finance.risk.propose', 'finance.risk.approve', 'finance.credit.view', 'finance.credit.propose', 'finance.credit.verify', 'finance.credit.approve', 'finance.credit.revoke', 'finance.audit.view', 'finance.audit.sensitive']);
$readonly = $person('readonly', ['finance.risk.view', 'finance.credit.view']);
$sensitive = $person('sensitive', ['finance.risk.view', 'finance.credit.view', 'finance.audit.view', 'finance.audit.sensitive']);
$lookalike = $person('mesut.yeman', $ownerPermissions, 'Mesut Yeman');

$args = [];
foreach ((new ReflectionClass(Config::class))->getConstructor()->getParameters() as $parameter) {
    $name = $parameter->getName();
    $args[$name] = match ($name) { 'financeRiskLimitAuthorityUuids' => implode(',', [$ownerA['id'], $ownerB['id'], $ownerC['id']]), default => $config->{$name} };
}
$live = (new Container(new Config(...$args), $pdo))->kernel();
try { new Config(...array_replace($args, ['financeRiskLimitAuthorityUuids' => 'Mesut Yeman,Zelal Yeman'])); check(false, 'names refused'); } catch (RuntimeException) { check(true, '12. an allowlist entry must be a UUID, never a name'); }
try { new Config(...array_replace($args, ['financeRiskLimitAuthorityUuids' => $lookalike['username']])); check(false, 'usernames refused'); } catch (RuntimeException) { check(true, '12. an allowlist entry is never a username'); }
check($config->financeRiskLimitAuthorityList() === [] && $config->financeOpeningBalancePostingEnabled() === false, '9/21. defaults: empty allowlist, opening-balance posting off');

$staffBefore = [
    'roles' => $rows('SELECT * FROM employee_role_assignments ORDER BY employee_uuid, role_id'),
    'apps' => $rows('SELECT * FROM employee_application_access ORDER BY employee_uuid, application_key'),
    'grants' => $rows('SELECT * FROM role_permissions ORDER BY role_id, permission_id'),
];

// ---- Companies --------------------------------------------------------------------------------------------------------
$evidence = [['kind' => 'registry_extract', 'sourceSystem' => 'REGISTRY', 'reference' => 'Synthetic registry extract V040', 'documentDate' => '2026-11-01']];
$complete = static function (array $who, string $id, int $version) use ($call, $evidence): int {
    foreach (['legal_name', 'country', 'tax_id', 'duplicate_search', 'address', 'contact', 'sources', 'documents', 'conflicts'] as $item) {
        $body = ['expectedVersion' => $version, 'item' => $item, 'state' => 'done'];
        if ($item === 'tax_id') $body['evidence'] = $evidence;
        $version = ok($call($who, 'POST', "/finance/cases/{$id}/checklist", $body), 200, "checklist {$item}")['version'];
    }
    return $version;
};
$counter = 7100000;
$verified = static function (string $name, string $country = 'RO') use ($call, $reviewer, $verifier, $complete, &$counter): string {
    $counter += 7919;
    $tax = $country === 'RO' ? cui((string) $counter) : $country . $counter . '1';
    $id = ok($call($reviewer, 'POST', '/finance/cases', ['legalName' => $name, 'countryCode' => $country, 'taxIdentifier' => $tax, 'addressLine' => 'Str. V 4', 'city' => 'Brasov']), 201, 'case ' . $name)['caseId'];
    $v = ok($call($reviewer, 'GET', "/finance/cases/{$id}"), 200, 'case detail')['case']['version'];
    $v = ok($call($reviewer, 'POST', "/finance/cases/{$id}/claim", ['expectedVersion' => $v]), 200, 'claim ' . $name)['version'];
    $v = $complete($reviewer, $id, $v);
    $v = ok($call($reviewer, 'POST', "/finance/cases/{$id}/submit", ['expectedVersion' => $v]), 200, 'submit ' . $name)['version'];
    return ok($call($verifier, 'POST', "/finance/cases/{$id}/approve", ['expectedVersion' => $v]), 200, 'approve ' . $name)['companyId'];
};
$reconcileZero = static function (string $company) use ($call, $acc1, $acc2): void {
    $o = ok($call($acc1, 'POST', '/finance/accounts', ['companyId' => $company]), 201, 'open account')['onboardingId'];
    $v = ok($call($acc1, 'POST', "/finance/accounts/{$o}/draft", ['expectedVersion' => 1, 'balanceKind' => 'ZERO', 'amount' => '0', 'asOfDate' => '2026-11-01', 'note' => 'Synthetic zero',
        'evidence' => [['kind' => 'correspondence', 'sourceSystem' => 'MANUAL', 'reference' => 'Synthetic balance confirmation V040']]]), 200, 'zero drafted')['version'];
    $v = ok($call($acc1, 'POST', "/finance/accounts/{$o}/submit", ['expectedVersion' => $v]), 200, 'zero submitted')['version'];
    check(ok($call($acc2, 'POST', "/finance/accounts/{$o}/verify", ['expectedVersion' => $v]), 200, 'zero verified')['onboardingStatus'] === 'RECONCILED', 'reconciled');
};
$cRO = $verified('Owner Decision Alfa SRL');
$reconcileZero($cRO);
$cDE = $verified('Owner Decision Beta GmbH', 'DE');
$reconcileZero($cDE);
$cPending = $verified('Owner Decision Gamma SRL');
$asRoot = static fn (string $method, string $path, array $json): array => T::call($kernel, $method, $path, $json, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'fin-test-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$cUnverified = ok($asRoot('POST', '/b2b/companies', ['legalName' => 'Owner Decision Delta SRL', 'countryCode' => 'RO', 'taxIdentifier' => 'RO' . cui('4242424')]), 201, 'B2B company without Finance verification')['companyId'];

$casesBefore = $rows('SELECT * FROM finance_company_cases ORDER BY case_uuid');
$flagsBefore = $rows('SELECT * FROM finance_case_flags ORDER BY flag_uuid');
$onboardingsBefore = $rows('SELECT * FROM finance_account_onboardings ORDER BY onboarding_uuid');
$knownCompanies = implode(',', array_map([$pdo, 'quote'], array_column($rows('SELECT company_uuid FROM b2b_companies'), 'company_uuid')));
$productionSnapshot = static function () use ($pdo, $knownCompanies): string {
    $out = [];
    // b2b_companies: the rows that existed before (an independently verified case later creates its own new company).
    foreach (['operational_orders', 'operational_order_items', 'order_qr_references', 'production_workflows', 'order_finance_actions', 'employee_finance_scopes', 'b2b_orders', 'b2b_order_lines', 'b2b_account_movements', 'b2b_account_allocations', 'b2b_companies'] as $table) {
        $data = array_map(serialize(...), $pdo->query('SELECT * FROM ' . $table . ($table === 'b2b_companies' ? " WHERE company_uuid IN ({$knownCompanies})" : ''))->fetchAll(PDO::FETCH_ASSOC));
        sort($data, SORT_STRING);
        $out[] = $table . ':' . hash('sha256', implode("\n", $data));
    }
    return implode('|', $out);
};
$productionBefore = $productionSnapshot();

$detail = static fn (array $who, string $company, ?\Arasya\Operations\Http\ApiKernel $k = null): array => ok($call($who, 'GET', "/finance/owner/companies/{$company}", null, [], null, $k ?? $GLOBALS['live']), 200, 'owner detail');
$color = static fn (array $who, string $company, string $state, string $classification, string $effect = 'EFFECTIVE', ?string $key = null, ?\Arasya\Operations\Http\ApiKernel $k = null): array
    => $call($who, 'POST', "/finance/owner/companies/{$company}/risk", ['expectedState' => $state, 'classification' => $classification, 'effect' => $effect, 'reason' => "Synthetic owner decision {$classification}"], [], $key, $k ?? $GLOBALS['live']);
$limit = static fn (array $who, string $company, string $state, array $body, ?string $key = null, ?\Arasya\Operations\Http\ApiKernel $k = null): array
    => $call($who, 'POST', "/finance/owner/companies/{$company}/credit", ['expectedState' => $state] + $body + ['effect' => 'EFFECTIVE', 'reason' => 'Synthetic owner limit decision'], [], $key, $k ?? $GLOBALS['live']);
$profile = static fn (string $company): array => ok($call($sensitive, 'GET', "/finance/companies/{$company}/credit-profile", null, [], null, $GLOBALS['live']), 200, 'profile');

// ---- Access contract ---------------------------------------------------------------------------------------------------
$accessA = ok($call($ownerA, 'GET', '/finance/access', null, [], null, $live), 200, 'owner access');
check($accessA['owner']['decisionMaker'] === true && $accessA['owner']['canDecideColor'] === true && $accessA['owner']['canSetLimit'] === true, 'a provisioned owner is recognised by UUID');
$accessDefault = ok($call($ownerA, 'GET', '/finance/access'), 200, 'owner access without allowlist');
check($accessDefault['owner']['decisionMaker'] === false && $accessDefault['owner']['authorityConfigured'] === false && $accessDefault['openingBalancePostingEnabled'] === false, '9/21. empty allowlist: nobody decides; posting off');
check(ok($call($finAdmin, 'GET', '/finance/access', null, [], null, $live), 200, 'admin access')['owner']['decisionMaker'] === false, 'a Finance administrator is not a decision maker');

// ---- Unverified identity: only a non-effective instruction --------------------------------------------------------------
$d = $detail($ownerA, $cUnverified);
check($d['risk']['classification'] === 'UNVERIFIED' && $d['credit']['status'] === 'NO_APPROVED_LIMIT' && $d['credit']['amount'] === null, 'a new company starts UNVERIFIED with NO_APPROVED_LIMIT, never GREEN or 0');
check($d['risk']['options']['RED'] === 'COMPANY_NOT_VERIFIED' && $d['credit']['effectiveBlock'] === 'COMPANY_NOT_VERIFIED' && in_array('IDENTITY_NOT_VERIFIED', $d['warnings'], true), 'the screen explains why nothing can be effective');
err($color($ownerA, $cUnverified, $d['state'], 'RED'), 409, 'COMPANY_NOT_VERIFIED', 'an unverified company gets no effective colour');
$instr = ok($color($ownerA, $cUnverified, $d['state'], 'RED', 'NOT_EFFECTIVE'), 201, 'non-effective colour instruction');
check($instr['effect'] === 'NOT_EFFECTIVE' && $instr['state']['riskColor'] === 'UNVERIFIED', 'the instruction changes no colour');
$d = $detail($ownerB, $cUnverified);
err($limit($ownerB, $cUnverified, $d['state'], ['action' => 'SET', 'amount' => '1000.00', 'currency' => 'RON']), 409, 'COMPANY_NOT_VERIFIED', 'an unverified company gets no effective limit');
err($limit($ownerB, $cUnverified, $d['state'], ['action' => 'SET', 'amount' => '1000.00', 'currency' => 'EUR', 'effect' => 'NOT_EFFECTIVE']), 422, 'CURRENCY_MISMATCH', '15. even an instruction follows the registered country');
ok($limit($ownerB, $cUnverified, $d['state'], ['action' => 'SET', 'amount' => '1000.00', 'currency' => 'RON', 'effect' => 'NOT_EFFECTIVE']), 201, 'non-effective limit instruction');
$d = $detail($ownerA, $cUnverified);
check(count($d['pendingInstructions']) === 2 && $d['credit']['status'] === 'NO_APPROVED_LIMIT' && $d['risk']['classification'] === 'UNVERIFIED', 'both instructions pending, neither effective');
check((int) $scalar('SELECT COUNT(*) FROM finance_risk_assessments WHERE company_uuid = ?', [$cUnverified]) === 0 && (int) $scalar('SELECT COUNT(*) FROM finance_credit_limits WHERE company_uuid = ?', [$cUnverified]) === 0, 'no risk or credit row behind an instruction');

// ---- 1-3. Colours: each owner alone -------------------------------------------------------------------------------------
$d = $detail($ownerA, $cRO);
check($d['risk']['options'] === ['GREEN' => 'EFFECTIVE_ALLOWED', 'YELLOW' => 'EFFECTIVE_ALLOWED', 'RED' => 'EFFECTIVE_ALLOWED'] && $d['owner']['canDecideColor'] === true, 'verified and reconciled: every colour may be effective');
$r = ok($color($ownerA, $cRO, $d['state'], 'GREEN'), 201, 'owner A: UNVERIFIED to GREEN');
check($r['effect'] === 'EFFECTIVE' && $r['state']['riskColor'] === 'GREEN' && $r['state']['riskMode'] === 'OWNER_DIRECT', 'GREEN effective immediately');
err($color($ownerA, $cRO, $r['state']['state'], 'GREEN'), 409, 'NO_CHANGE', 'the same colour again is no decision');
$r = ok($color($ownerA, $cRO, $r['state']['state'], 'RED'), 201, '1. owner A: GREEN to RED');
check($r['state']['riskColor'] === 'RED', '1. RED effective');
$r = ok($color($ownerB, $cRO, $detail($ownerB, $cRO)['state'], 'YELLOW'), 201, '2. owner B: RED to YELLOW, no approval from A');
check($r['state']['riskColor'] === 'YELLOW', '2. YELLOW effective');
$r = ok($color($ownerB, $cRO, $r['state']['state'], 'RED'), 201, 'owner B: YELLOW to RED');
$r = ok($color($ownerA, $cRO, $r['state']['state'], 'GREEN'), 201, 'owner A reverses owner B: RED to GREEN');
$history = $rows('SELECT classification, status, decision_mode, proposed_by_employee_uuid, decided_by_employee_uuid, proposed_at = decided_at AS same_instant FROM finance_risk_assessments WHERE company_uuid = ? ORDER BY proposed_at', [$cRO]);
check(array_column($history, 'classification') === ['GREEN', 'RED', 'YELLOW', 'RED', 'GREEN'] && array_column($history, 'status') === ['SUPERSEDED', 'SUPERSEDED', 'SUPERSEDED', 'SUPERSEDED', 'APPROVED'], 'every colour stays in history; one is active');
check(array_unique(array_column($history, 'decision_mode')) === ['OWNER_DIRECT'], 'all owner-direct');
foreach ($history as $h) check($h['proposed_by_employee_uuid'] === $h['decided_by_employee_uuid'] && (int) $h['same_instant'] === 1, '3. one actor, one command, no second approver');
check(array_column($history, 'decided_by_employee_uuid') === [$ownerA['id'], $ownerA['id'], $ownerB['id'], $ownerB['id'], $ownerA['id']], 'each decision names its own owner');
check((int) $scalar("SELECT COUNT(*) FROM finance_risk_assessments WHERE company_uuid = ? AND status IN ('PROPOSED', 'REJECTED')", [$cRO]) === 0, '3. no pending approval anywhere');
check((int) $scalar('SELECT COUNT(*) FROM finance_risk_assessments WHERE active_company_uuid = ?', [$cRO]) === 1, 'never two active colours');

// GREEN needs a reconciled account; RED or YELLOW only a verified identity.
$d = $detail($ownerA, $cPending);
check($d['risk']['options']['GREEN'] === 'GREEN_REQUIRES_RECONCILIATION' && $d['risk']['options']['RED'] === 'EFFECTIVE_ALLOWED', 'verified, not reconciled');
err($color($ownerA, $cPending, $d['state'], 'GREEN'), 409, 'GREEN_REQUIRES_RECONCILIATION', 'GREEN needs a reconciled account');
ok($color($ownerA, $cPending, $d['state'], 'YELLOW'), 201, 'YELLOW on a verified company');

// ---- 4-5, 16. Limits: each owner alone; zero is not "no limit" -------------------------------------------------------------
$d = $detail($ownerA, $cRO);
check($d['credit']['status'] === 'NO_APPROVED_LIMIT' && $d['expectedCurrency'] === 'RON' && $d['credit']['effectiveBlock'] === 'EFFECTIVE_ALLOWED', 'RON company without a limit');
err($limit($ownerA, $cRO, $d['state'], ['action' => 'SET', 'amount' => '5000.00', 'currency' => 'EUR']), 422, 'CURRENCY_MISMATCH', '15. a Romanian company is limited in RON');
err($limit($ownerA, $cRO, $d['state'], ['action' => 'SET', 'amount' => '5000.555', 'currency' => 'RON']), 422, 'VALIDATION_FAILED', 'money is an exact decimal with two places or fewer');
err($limit($ownerA, $cRO, $d['state'], ['action' => 'SET', 'amount' => 5000, 'currency' => 'RON']), 422, 'VALIDATION_FAILED', 'money is never a JSON number');
$r = ok($limit($ownerA, $cRO, $d['state'], ['action' => 'SET', 'amount' => '5000.00', 'currency' => 'RON']), 201, '4. owner A sets 5,000 RON');
check($r['state']['creditLimitStatus'] === 'APPROVED' && $r['state']['approvedCreditLimit'] === '5000.00' && $r['state']['creditLimitCurrency'] === 'RON', '4. effective immediately');
$r = ok($limit($ownerB, $cRO, $r['state']['state'], ['action' => 'SET', 'amount' => '7500.00', 'currency' => 'RON']), 201, '5. owner B changes it to 7,500 RON');
check($r['state']['approvedCreditLimit'] === '7500.00', '5. new amount effective');
$p = $profile($cRO);
check($p['creditPurchaseEligibility'] === 'ELIGIBLE' && $p['eligibilityBlockers'] === [] && $p['eligibilityEnforced'] === false && $p['availableCredit'] === '7500.00', 'GREEN, verified, reconciled, positive limit: eligible in the (unenforced) contract');
$r = ok($color($ownerB, $cRO, $r['state']['state'], 'RED'), 201, 'owner B: RED while a limit exists');
$p = $profile($cRO);
check($p['riskColor'] === 'RED' && $p['creditLimitStatus'] === 'APPROVED' && $p['creditPurchaseEligibility'] === 'BLOCKED' && in_array('RISK_RED', $p['eligibilityBlockers'], true), '17. RED with a stored limit is not purchasing eligibility');
$r = ok($limit($ownerA, $cRO, $r['state']['state'], ['action' => 'SUSPEND']), 200, 'owner A suspends');
check($r['state']['creditLimitStatus'] === 'SUSPENDED', 'suspended');
err($limit($ownerA, $cRO, $r['state']['state'], ['action' => 'SUSPEND']), 409, 'INVALID_TRANSITION', 'suspended twice');
$r = ok($limit($ownerB, $cRO, $r['state']['state'], ['action' => 'REINSTATE']), 200, 'owner B reinstates');
check($r['state']['creditLimitStatus'] === 'APPROVED' && $r['state']['approvedCreditLimit'] === '7500.00', 'reinstated');
$r = ok($limit($ownerA, $cRO, $r['state']['state'], ['action' => 'REVOKE']), 200, 'owner A revokes');
check($r['state']['creditLimitStatus'] === 'NO_APPROVED_LIMIT' && $r['state']['approvedCreditLimit'] === null, 'revoked: no approved limit');
err($limit($ownerA, $cRO, $r['state']['state'], ['action' => 'REVOKE']), 409, 'NO_ACTIVE_LIMIT', 'nothing left to revoke');
err($limit($ownerA, $cRO, $r['state']['state'], ['action' => 'REVOKE', 'amount' => '1.00']), 422, 'VALIDATION_FAILED', 'status changes carry no amount');
$r = ok($limit($ownerB, $cRO, $r['state']['state'], ['action' => 'SET', 'amount' => '0.00', 'currency' => 'RON']), 201, '16. explicit zero');
$p = $profile($cRO);
check($p['creditLimitStatus'] === 'APPROVED' && $p['approvedCreditLimit'] === '0.00' && in_array('LIMIT_ZERO', $p['eligibilityBlockers'], true), '16. APPROVED_LIMIT = 0.00 differs from NO_APPROVED_LIMIT');
$limits = $rows('SELECT amount, status, decision_mode, proposed_by_employee_uuid AS p, verified_by_employee_uuid AS v, approved_by_employee_uuid AS a FROM finance_credit_limits WHERE company_uuid = ? ORDER BY proposed_at', [$cRO]);
check(array_column($limits, 'amount') === ['5000.00', '7500.00', '0.00'] && array_column($limits, 'status') === ['SUPERSEDED', 'REVOKED', 'APPROVED'], 'limit history preserved: superseded, revoked, current');
foreach ($limits as $l) check($l['decision_mode'] === 'OWNER_DIRECT' && $l['p'] === $l['v'] && $l['v'] === $l['a'], 'one owner per limit, no verifier or approver beside them');

$d = $detail($ownerA, $cDE);
check($d['expectedCurrency'] === 'EUR' && $d['credit']['status'] === 'NO_APPROVED_LIMIT', 'German company: EUR, no limit');
err($limit($ownerA, $cDE, $d['state'], ['action' => 'SET', 'amount' => '900.00', 'currency' => 'RON']), 422, 'CURRENCY_MISMATCH', '15. a non-Romanian company is limited in EUR');
$p = $profile($cDE);
check($p['creditLimitStatus'] === 'NO_APPROVED_LIMIT' && $p['approvedCreditLimit'] === null && in_array('NO_APPROVED_LIMIT', $p['eligibilityBlockers'], true) && in_array('RISK_NOT_ASSIGNED', $p['eligibilityBlockers'], true), '16. no approved limit is null, never 0.00');
ok($limit($ownerA, $cDE, $d['state'], ['action' => 'SET', 'amount' => '900.00', 'currency' => 'EUR', 'reviewDate' => '2027-05-01']), 201, 'EUR limit without any colour');
$p = $profile($cDE);
check($p['creditLimitStatus'] === 'APPROVED' && $p['creditLimitCurrency'] === 'EUR' && $p['creditPurchaseEligibility'] === 'BLOCKED' && in_array('RISK_NOT_ASSIGNED', $p['eligibilityBlockers'], true), 'a limit without a colour is still not eligibility');

// Verified, not reconciled: the limit can only be an instruction, and reconciling later does not activate it.
$d = $detail($ownerB, $cPending);
check($d['credit']['effectiveBlock'] === 'ACCOUNT_NOT_RECONCILED', 'limit blocked until reconciliation');
err($limit($ownerB, $cPending, $d['state'], ['action' => 'SET', 'amount' => '2000.00', 'currency' => 'RON']), 409, 'ACCOUNT_NOT_RECONCILED', 'no effective limit without a reconciled account');
ok($limit($ownerB, $cPending, $d['state'], ['action' => 'SET', 'amount' => '2000.00', 'currency' => 'RON', 'effect' => 'NOT_EFFECTIVE']), 201, 'intended limit recorded');
$reconcileZero($cPending);
$p = $profile($cPending);
check($p['creditLimitStatus'] === 'NO_APPROVED_LIMIT' && $p['approvedCreditLimit'] === null, 'a later reconciliation never activates a pending instruction');
ok($limit($ownerB, $cPending, $detail($ownerB, $cPending)['state'], ['action' => 'SET', 'amount' => '2000.00', 'currency' => 'RON']), 201, 'only a fresh owner decision makes it effective');

// ---- 13. Two owners on the same customer ----------------------------------------------------------------------------------------
$seen = $detail($ownerA, $cDE)['state'];
check($detail($ownerB, $cDE)['state'] === $seen, 'both owners see the same state');
$winner = ok($color($ownerA, $cDE, $seen, 'YELLOW'), 201, '13. owner A decides first');
$late = err($color($ownerB, $cDE, $seen, 'RED'), 409, 'OWNER_STATE_STALE', '13. owner B on the old state gets an explicit conflict');
check($late['error']['details']['currentState'] === $winner['state']['state'], '13. the conflict carries the current state');
err($limit($ownerB, $cDE, $seen, ['action' => 'SET', 'amount' => '950.00', 'currency' => 'EUR']), 409, 'OWNER_STATE_STALE', '13. a limit on the old state is refused too');
ok($color($ownerB, $cDE, $winner['state']['state'], 'RED'), 201, '13. owner B decides again on the current state');
$seq = array_map('intval', array_column($rows('SELECT sequence_no FROM finance_owner_decisions WHERE company_uuid = ? ORDER BY sequence_no', [$cDE]), 'sequence_no'));
check($seq === range(1, count($seq)), '13. gap-free per-company sequence');
check(array_column($rows('SELECT classification FROM finance_risk_assessments WHERE company_uuid = ? ORDER BY proposed_at', [$cDE]), 'classification') === ['YELLOW', 'RED'], '13. both decisions kept in order');

// ---- 14. Idempotency ------------------------------------------------------------------------------------------------------------
$state = $detail($ownerA, $cDE)['state'];
$key = 'fin-test-owner-retry-' . $suffix;
$once = ok($color($ownerA, $cDE, $state, 'YELLOW', 'EFFECTIVE', $key), 201, 'first try');
$count = (int) $scalar('SELECT COUNT(*) FROM finance_owner_decisions WHERE company_uuid = ?', [$cDE]);
$again = ok($color($ownerA, $cDE, $state, 'YELLOW', 'EFFECTIVE', $key), 201, '14. retry after a lost response');
check(($again['replayed'] ?? false) === true && $again['decisionId'] === $once['decisionId'], '14. the retry replays the same decision');
check((int) $scalar('SELECT COUNT(*) FROM finance_owner_decisions WHERE company_uuid = ?', [$cDE]) === $count && (int) $scalar('SELECT COUNT(*) FROM finance_risk_assessments WHERE active_company_uuid = ?', [$cDE]) === 1, '14. no extra decision or active colour');
err($color($ownerA, $cDE, $state, 'GREEN', 'EFFECTIVE', $key), 409, 'IDEMPOTENCY_CONFLICT', '14. the same key for another decision is refused');

// ---- 6-12. Everyone else is refused ----------------------------------------------------------------------------------------------
$state = $detail($ownerA, $cRO)['state'];
$riskBefore = $rows('SELECT * FROM finance_risk_assessments ORDER BY assessment_uuid');
$creditBefore = $rows('SELECT * FROM finance_credit_limits ORDER BY limit_uuid');
$journalBefore = (int) $scalar('SELECT COUNT(*) FROM finance_owner_decisions');
err($color($accountant, $cRO, $state, 'GREEN'), 403, 'UNAUTHORIZED_ACTION', '6. an ordinary Finance employee gets 403');
err($limit($accountant, $cRO, $state, ['action' => 'SET', 'amount' => '1.00', 'currency' => 'RON']), 403, 'UNAUTHORIZED_ACTION', '6. no limit either');
err($color($finAdmin, $cRO, $state, 'GREEN'), 403, 'RISK_LIMIT_AUTHORITY_REQUIRED', '7. a Finance administrator with every permission gets 403');
err($limit($finAdmin, $cRO, $state, ['action' => 'REVOKE']), 403, 'RISK_LIMIT_AUTHORITY_REQUIRED', '7. nor suspend, reinstate or revoke');
// Root holds every active application and the full permission catalogue, so only the allowlist stops it.
err($color($root, $cRO, $state, 'GREEN'), 403, 'RISK_LIMIT_AUTHORITY_REQUIRED', '8. root outside the allowlist is refused by the allowlist itself');
err($limit($root, $cRO, $state, ['action' => 'REVOKE']), 403, 'RISK_LIMIT_AUTHORITY_REQUIRED', '8. root cannot change a limit either');
check(ok($call($root, 'GET', '/finance/access', null, [], null, $live), 200, 'root access')['owner']['decisionMaker'] === false, '8. root is not a decision maker');
err($color($ownerA, $cRO, $state, 'YELLOW', 'EFFECTIVE', null, $kernel), 403, 'RISK_LIMIT_AUTHORITY_REQUIRED', '9. an empty allowlist refuses even the owner identity');
err($limit($ownerB, $cRO, $state, ['action' => 'REVOKE'], null, $kernel), 403, 'RISK_LIMIT_AUTHORITY_REQUIRED', '9. empty allowlist: no limit change');
err($color($lookalike, $cRO, $state, 'YELLOW'), 403, 'RISK_LIMIT_AUTHORITY_REQUIRED', '12. the display name "Mesut Yeman" grants nothing');
err($call($ownerA, 'POST', "/finance/owner/companies/{$cRO}/risk", ['expectedState' => $state, 'classification' => 'YELLOW', 'effect' => 'EFFECTIVE', 'reason' => 'Synthetic', 'actorUuid' => $ownerB['id']], [], null, $live), 400, 'INVALID_REQUEST', '12. a client-supplied identity is refused');
err($color($readonly, $cRO, $state, 'YELLOW'), 403, 'UNAUTHORIZED_ACTION', 'a read-only employee cannot decide by direct HTTP');
check($rows('SELECT * FROM finance_risk_assessments ORDER BY assessment_uuid') === $riskBefore && $rows('SELECT * FROM finance_credit_limits ORDER BY limit_uuid') === $creditBefore
    && (int) $scalar('SELECT COUNT(*) FROM finance_owner_decisions') === $journalBefore, 'refused attempts leave no trace in risk, credit or the journal');

// ---- 10-11. Revocation takes effect on the next request -----------------------------------------------------------------------------
$state = $detail($ownerC, $cDE)['state'];
$ownerCId = $ownerC['id'];
ok($call($root, 'PUT', "/management/employees/{$ownerCId}/applications", ['applications' => []]), 200, 'owner C loses the Finance application');
$revoked = $color($ownerC, $cDE, $state, 'GREEN');
check($revoked['status'] === 403 || $revoked['status'] === 401, '10. revoked Finance access stops the very next decision ' . json_encode($revoked['body']));
ok($call($root, 'PUT', "/management/employees/{$ownerC['id']}/applications", ['applications' => ['finance']]), 200, 'application restored');
$ownerC = T::login($kernel, $ownerC['username'], $password) + ['id' => $ownerC['id'], 'username' => $ownerC['username']];
ok($call($root, 'PUT', "/management/employees/{$ownerC['id']}/roles", ['roleIds' => []]), 200, 'owner C loses the Finance role');
err($color($ownerC, $cDE, $state, 'GREEN'), 403, 'UNAUTHORIZED_ACTION', '11. without the Finance permission the allowlist alone is not enough');
ok($call($root, 'PUT', "/management/employees/{$ownerC['id']}/roles", ['roleIds' => [$roles['ownerc']]]), 200, 'role restored');
$ownerC = T::login($kernel, $ownerC['username'], $password) + ['id' => $ownerC['id'], 'username' => $ownerC['username']];
check($detail($ownerC, $cDE)['owner']['decisionMaker'] === true, 'restored owner is recognised again');
ok($call($root, 'POST', "/management/employees/{$ownerC['id']}/deactivate"), 200, 'owner C deactivated');
$inactive = $color($ownerC, $cDE, $state, 'GREEN');
check(in_array($inactive['status'], [401, 403], true), '11. a deactivated employee cannot decide ' . json_encode($inactive['body']));

// ---- 19. Employee recommendations are never effective ----------------------------------------------------------------------------------
$recommendation = ok($call($accountant, 'POST', '/finance/risk', ['companyId' => $cPending, 'classification' => 'RED', 'reason' => 'Synthetic employee recommendation'], [], null, $live), 201, 'accountant recommends RED')['assessmentId'];
$d = $detail($ownerA, $cPending);
check($d['risk']['classification'] === 'YELLOW' && count($d['risk']['recommendations']) === 1 && $d['risk']['recommendations'][0]['id'] === $recommendation && $d['risk']['recommendations'][0]['effective'] === false, '19. the recommendation is listed as not effective; the colour is unchanged');
check($scalar('SELECT status FROM finance_risk_assessments WHERE assessment_uuid = ?', [$recommendation]) === 'PROPOSED' && $scalar('SELECT decision_mode FROM finance_risk_assessments WHERE assessment_uuid = ?', [$recommendation]) === 'REVIEWED', '19. stored as a REVIEWED proposal');
err($call($accountant, 'POST', "/finance/risk/{$recommendation}/approve", ['expectedVersion' => 1], [], null, $live), 403, 'UNAUTHORIZED_ACTION', '19. the employee cannot approve it');
err($call($finAdmin, 'POST', "/finance/risk/{$recommendation}/approve", ['expectedVersion' => 1], [], null, $live), 403, 'RISK_LIMIT_AUTHORITY_REQUIRED', '19. a Finance administrator cannot approve it');
check($profile($cPending)['riskColor'] === 'YELLOW', '19. nothing became effective by itself');
$credRec = ok($call($accountant, 'POST', '/finance/credit', ['companyId' => $cPending, 'amount' => '99999.00', 'currency' => 'RON', 'effectiveFrom' => '2026-11-01', 'reason' => 'Synthetic employee limit recommendation'], [], null, $live), 201, 'accountant recommends a limit')['limitId'];
$d = $detail($ownerB, $cPending);
check($d['credit']['amount'] === '2000.00' && count($d['credit']['recommendations']) === 1 && $d['credit']['recommendations'][0]['id'] === $credRec && $d['credit']['recommendations'][0]['effective'] === false, '19. the limit recommendation is not effective');
check($profile($cPending)['approvedCreditLimit'] === '2000.00', '19. the owner limit stays in force');

// ---- 20. Company verification keeps its own independent check ----------------------------------------------------------------------------
ok($call($root, 'POST', "/management/employees/{$ownerCId}/activate"), 200, 'owner C reactivated');
$ownerC = T::login($kernel, 'ownerc.' . $suffix, $password) + ['id' => $ownerCId];
$caseId = ok($call($reviewer, 'POST', '/finance/cases', ['legalName' => 'Owner Decision Epsilon SRL', 'countryCode' => 'RO', 'taxIdentifier' => cui('7777771'), 'addressLine' => 'Str. E 5', 'city' => 'Cluj']), 201, 'case')['caseId'];
$v = ok($call($ownerC, 'GET', "/finance/cases/{$caseId}"), 200, 'case')['case']['version'];
$v = ok($call($ownerC, 'POST', "/finance/cases/{$caseId}/claim", ['expectedVersion' => $v]), 200, 'owner claims')['version'];
$v = $complete($ownerC, $caseId, $v);
$v = ok($call($ownerC, 'POST', "/finance/cases/{$caseId}/submit", ['expectedVersion' => $v]), 200, 'owner submits')['version'];
err($call($ownerC, 'POST', "/finance/cases/{$caseId}/approve", ['expectedVersion' => $v], [], null, $live), 403, 'FOUR_EYES_REQUIRED', '20. an owner cannot verify a company they prepared');
ok($call($verifier, 'POST', "/finance/cases/{$caseId}/approve", ['expectedVersion' => $v], [], null, $live), 200, '20. an independent verifier approves');

// ---- Audit journal --------------------------------------------------------------------------------------------------------------------------
$journal = $rows('SELECT * FROM finance_owner_decisions WHERE company_uuid = ? ORDER BY sequence_no', [$cRO]);
check(count($journal) === 12, 'every owner command on Alfa journalled (' . count($journal) . ')');
foreach ($journal as $j) {
    check($j['decision_type'] === 'OWNER_DIRECT' && $j['request_id'] !== '' && strlen($j['idempotency_key']) >= 16 && strlen((string) $j['authority_fingerprint']) === 32 && $j['state_before'] !== $j['state_after'], 'journal row complete');
    $snapshot = json_decode((string) $j['actor_snapshot_json'], true);
    check(in_array($snapshot['employeeUuid'], [$ownerA['id'], $ownerB['id']], true) && $snapshot['employeeUuid'] === $j['actor_employee_uuid'] && $snapshot['isRoot'] === false, 'actor UUID and identity snapshot');
    $basisJ = json_decode((string) $j['basis_json'], true);
    check($basisJ['verification'] === 'VERIFIED' && $basisJ['reconciliation'] === 'RECONCILED' && $basisJ['accountCurrency'] === 'RON', 'verification and reconciliation basis captured');
}
check(json_decode((string) $journal[1]['previous_json'], true)['classification'] === 'GREEN' && json_decode((string) $journal[1]['new_json'], true)['classification'] === 'RED', 'previous and new colour');
$setLimit = array_values(array_filter($journal, static fn (array $j): bool => $j['subject'] === 'CREDIT' && $j['action'] === 'SET'));
check(json_decode((string) $setLimit[1]['previous_json'], true)['amount'] === '5000.00' && json_decode((string) $setLimit[1]['new_json'], true)['amount'] === '7500.00' && $setLimit[1]['currency_code'] === 'RON', 'previous and new limit with currency');
check($journal[0]['state_after'] === $journal[1]['state_before'], 'versions chain: one decision\'s after is the next one\'s before');
// Integrity: every OWNER_DIRECT row has a journal entry by the same actor, so a write outside this path is detectable.
check((int) $scalar("SELECT COUNT(*) FROM finance_risk_assessments a WHERE a.decision_mode = 'OWNER_DIRECT' AND NOT EXISTS (SELECT 1 FROM finance_owner_decisions d WHERE d.assessment_uuid = a.assessment_uuid AND d.action = 'SET' AND d.actor_employee_uuid = a.decided_by_employee_uuid)") === 0, 'every owner colour is journalled');
check((int) $scalar("SELECT COUNT(*) FROM finance_credit_limits l WHERE l.decision_mode = 'OWNER_DIRECT' AND NOT EXISTS (SELECT 1 FROM finance_owner_decisions d WHERE d.limit_uuid = l.limit_uuid AND d.action = 'SET' AND d.actor_employee_uuid = l.approved_by_employee_uuid)") === 0, 'every owner limit is journalled');
$plain = $detail($readonly, $cRO);
check(count($plain['decisions']) === 12 && !array_key_exists('actorSnapshot', $plain['decisions'][0]) && $plain['owner']['decisionMaker'] === false && $plain['owner']['canDecideColor'] === false, 'a read-only viewer sees decisions without the identity snapshot');
$full = $detail($sensitive, $cRO);
check(isset($full['decisions'][0]['actorSnapshot']['username'], $full['decisions'][0]['authorityFingerprint']), 'the restricted viewer sees the snapshot');
$acc = $detail($accountant, $cRO);
check($acc['owner']['canDecideColor'] === false && $acc['owner']['canSetLimit'] === false && $acc['owner']['canRecommendColor'] === true, 'an accountant sees recommendation rights only');
err($call($acc1, 'GET', "/finance/owner/companies/{$cRO}", null, [], null, $live), 403, 'UNAUTHORIZED_ACTION', 'without risk or credit view there is no owner screen');
$ids = static fn (array $q): array => array_column(ok($call($ownerA, 'GET', '/finance/owner/companies', null, $q, null, $GLOBALS['live']), 200, 'owner list ' . json_encode($q))['items'], 'companyId');
check(count($ids(['q' => 'Owner Decision'])) >= 5, 'search by name');
check(in_array($cRO, $ids(['color' => 'RED']), true) && !in_array($cPending, $ids(['color' => 'RED']), true), 'filter by colour');
$code = (string) $scalar('SELECT company_code FROM b2b_companies WHERE company_uuid = ?', [$cDE]);
check($ids(['q' => $code]) === [$cDE], 'search by canonical code');
check(in_array($cRO, $ids(['limit' => 'ZERO']), true) && !in_array($cDE, $ids(['limit' => 'ZERO']), true), 'filter explicit zero limits');
check(in_array($cUnverified, $ids(['limit' => 'NO_APPROVED_LIMIT', 'color' => 'UNVERIFIED']), true), 'filter no approved limit and no colour');
err($call($ownerA, 'GET', '/finance/owner/companies', null, ['color' => 'BLUE'], null, $live), 422, 'VALIDATION_FAILED', 'unknown colour filter refused');

// ---- Database guards (independent of the API) ---------------------------------------------------------------------------------------------
$emp = [$ownerA['id'], $ownerB['id']];
$now = '2026-11-09 10:00:00.000000';
$later = '2026-11-09 10:00:01.000000';
$riskSql = "INSERT INTO finance_risk_assessments (assessment_uuid, company_uuid, classification, status, decision_mode, active_company_uuid, indicators_json, basis_json, reason, proposed_by_employee_uuid, proposed_at, decided_by_employee_uuid, decided_at, version)
    VALUES (UUID(), ?, 'RED', ?, ?, NULL, '{}', '{}', 'x', ?, ?, ?, ?, 1)";
check(refused($pdo, $riskSql, [$cDE, 'SUPERSEDED', 'OWNER_DIRECT', $emp[0], $now, $emp[1], $now]), 'DB: an OWNER_DIRECT colour needs one actor');
check(refused($pdo, $riskSql, [$cDE, 'SUPERSEDED', 'OWNER_DIRECT', $emp[0], $now, $emp[0], $later]), 'DB: an OWNER_DIRECT colour is decided in the same command');
check(refused($pdo, $riskSql, [$cDE, 'REJECTED', 'OWNER_DIRECT', $emp[0], $now, $emp[0], $now]), 'DB: an OWNER_DIRECT row is never a rejection');
check(refused($pdo, $riskSql, [$cDE, 'SUPERSEDED', 'REVIEWED', $emp[0], $now, $emp[0], $now]), 'DB: REVIEWED keeps the 047 separation');
check(refused($pdo, $riskSql, [$cDE, 'SUPERSEDED', 'ANYTHING', $emp[0], $now, $emp[1], $now]), 'DB: only two decision modes');
check(refused($pdo, "INSERT INTO finance_risk_assessments (assessment_uuid, company_uuid, classification, status, decision_mode, indicators_json, basis_json, reason, proposed_by_employee_uuid, proposed_at, version)
    VALUES (UUID(), ?, 'RED', 'PROPOSED', 'OWNER_DIRECT', '{}', '{}', 'x', ?, ?, 1)", [$cDE, $emp[0], $now]), 'DB: an OWNER_DIRECT row is never a pending proposal');
$ob = $scalar('SELECT onboarding_uuid FROM finance_account_onboardings WHERE company_uuid = ?', [$cDE]);
$creditSql = "INSERT INTO finance_credit_limits (limit_uuid, company_uuid, currency_code, amount, status, decision_mode, active_company_uuid, effective_from, onboarding_uuid, onboarding_version, risk_assessment_uuid, reason,
    proposed_by_employee_uuid, proposed_at, verified_by_employee_uuid, verified_at, approved_by_employee_uuid, approved_at, version) VALUES (UUID(), ?, 'EUR', '1.00', 'SUPERSEDED', ?, NULL, '2026-11-01', ?, 1, NULL, 'x', ?, ?, ?, ?, ?, ?, 1)";
check(refused($pdo, $creditSql, [$cDE, 'OWNER_DIRECT', $ob, $emp[0], $now, $emp[1], $now, $emp[0], $now]), 'DB: an OWNER_DIRECT limit has no separate verifier');
check(refused($pdo, $creditSql, [$cDE, 'REVIEWED', $ob, $emp[0], $now, $emp[1], $now, $finAdmin['id'], $now]), 'DB: a REVIEWED limit still needs its classification');
check(!refused($pdo, $creditSql, [$cDE, 'OWNER_DIRECT', $ob, $emp[0], $now, $emp[0], $now, $emp[0], $now]), 'DB: the narrow OWNER_DIRECT shape is accepted (test row)');
$pdo->prepare("DELETE FROM finance_credit_limits WHERE company_uuid = ? AND reason = 'x'")->execute([$cDE]);
check(refused($pdo, "INSERT INTO finance_owner_decisions (decision_uuid, company_uuid, sequence_no, subject, action, effect, previous_json, new_json, reason, basis_json, actor_employee_uuid, actor_snapshot_json, authority_fingerprint, state_before, state_after, request_id, idempotency_key, decided_at)
    VALUES (UUID(), ?, 999, 'RISK', 'SET', 'EFFECTIVE', '{}', '{}', 'xyz', '{}', ?, '{}', REPEAT('a', 32), REPEAT('a', 64), REPEAT('b', 64), 'r', 'fin-test-0000000000', ?)", [$cDE, $emp[0], $now]), 'DB: an effective journal row must point at its decision');
check(refused($pdo, "INSERT INTO finance_owner_decisions (decision_uuid, company_uuid, sequence_no, subject, action, effect, previous_json, new_json, reason, basis_json, actor_employee_uuid, actor_snapshot_json, authority_fingerprint, state_before, state_after, request_id, idempotency_key, decided_at)
    VALUES (UUID(), ?, 1, 'RISK', 'INSTRUCT', 'NOT_EFFECTIVE', '{}', '{}', 'xyz', '{}', ?, '{}', REPEAT('a', 32), REPEAT('a', 64), REPEAT('b', 64), 'r', 'fin-test-0000000000', ?)", [$cDE, $emp[0], $now]), 'DB: one sequence number per company');

// ---- 18, 20, 21, 24, 25. Nothing else changed ----------------------------------------------------------------------------------------------------
check($rows('SELECT * FROM finance_case_flags ORDER BY flag_uuid') === $flagsBefore, '18. review flags (XX/ZZ included) unchanged by owner decisions');
$knownCases = array_column($casesBefore, 'case_uuid');
check(array_values(array_filter($rows('SELECT * FROM finance_company_cases ORDER BY case_uuid'), static fn (array $c): bool => in_array($c['case_uuid'], $knownCases, true))) === $casesBefore, '20. owner decisions change no verification case');
$knownOnboardings = array_column($onboardingsBefore, 'onboarding_uuid');
$changedOnboarding = (string) $scalar('SELECT onboarding_uuid FROM finance_account_onboardings WHERE company_uuid = ?', [$cPending]);
$keepOnboarding = static fn (array $list): array => array_values(array_filter($list, static fn (array $o): bool => in_array($o['onboarding_uuid'], $knownOnboardings, true) && $o['onboarding_uuid'] !== $changedOnboarding));
check($keepOnboarding($rows('SELECT * FROM finance_account_onboardings ORDER BY onboarding_uuid')) === $keepOnboarding($onboardingsBefore), 'owner decisions change no reconciliation');
check($productionSnapshot() === $productionBefore, '25. Staff production, QR, B2B orders, ledger and companies untouched');
$known = array_flip(array_column($staffBefore['roles'], 'employee_uuid'));
$keep = static fn (array $list): array => array_values(array_filter($list, static fn (array $r): bool => isset($known[$r['employee_uuid']]) && $r['employee_uuid'] !== $ownerCId));
check($keep($rows('SELECT * FROM employee_role_assignments ORDER BY employee_uuid, role_id')) === $keep($staffBefore['roles']), '24. no role assignment changed except the revocation test on owner C');
check(array_map('intval', array_column($rows('SELECT role_id FROM employee_role_assignments WHERE employee_uuid = ?', [$ownerCId]), 'role_id')) === [$roles['ownerc']], 'owner C has exactly its role back');
check($rows('SELECT * FROM role_permissions ORDER BY role_id, permission_id') === $staffBefore['grants'], '24. role permissions unchanged');
check((int) $scalar("SELECT COUNT(*) FROM b2b_account_movements WHERE movement_type = 'opening_balance'") === 0, '21. no opening balance was posted');

// ---- 23. Upgrade 048 -> 049 with existing V0.3 rows ------------------------------------------------------------------------------------------------
// A REVIEWED colour and limit through the unchanged three-person path, then the schema is put back to its 048 shape
// (test-only restore) and 049 runs again over those rows.
$assess = ok($call($accountant, 'POST', '/finance/risk', ['companyId' => $cDE, 'classification' => 'YELLOW', 'reason' => 'Synthetic reviewed proposal'], [], null, $live), 201, 'REVIEWED proposal')['assessmentId'];
ok($call($ownerB, 'POST', "/finance/risk/{$assess}/approve", ['expectedVersion' => 1], [], null, $live), 200, 'an owner may approve an employee recommendation on the V0.3 path');
check($scalar('SELECT decision_mode FROM finance_risk_assessments WHERE assessment_uuid = ?', [$assess]) === 'REVIEWED', 'the V0.3 path stays REVIEWED');
$reviewedLimit = ok($call($accountant, 'POST', '/finance/credit', ['companyId' => $cDE, 'amount' => '800.00', 'currency' => 'EUR', 'effectiveFrom' => '2026-11-01', 'reason' => 'Synthetic reviewed limit'], [], null, $live), 201, 'REVIEWED limit proposed')['limitId'];
ok($call($finAdmin, 'POST', "/finance/credit/{$reviewedLimit}/verify", ['expectedVersion' => 1], [], null, $live), 200, 'second person verifies');
ok($call($ownerA, 'POST', "/finance/credit/{$reviewedLimit}/approve", ['expectedVersion' => 2], [], null, $live), 200, 'third person (an owner) approves on the V0.3 path');
check($scalar('SELECT decision_mode FROM finance_credit_limits WHERE limit_uuid = ?', [$reviewedLimit]) === 'REVIEWED' && $profile($cDE)['approvedCreditLimit'] === '800.00', 'the three-person path still works and stays REVIEWED');
// Reduce the database to rows a 048 schema can hold (REVIEWED only; nothing may point at an owner row).
$pdo->exec('DELETE FROM finance_owner_decisions');
$pdo->prepare('DELETE FROM finance_credit_limits WHERE limit_uuid = ?')->execute([$credRec]);
$pdo->exec("DELETE FROM finance_credit_limits WHERE decision_mode = 'OWNER_DIRECT'");
$pdo->exec("DELETE FROM finance_risk_assessments WHERE decision_mode = 'OWNER_DIRECT'");
$pdo->exec('DROP TABLE finance_owner_decisions');
foreach (['finance_risk_assessments' => ['chk_finance_risk_separation_049', 'chk_finance_risk_mode'], 'finance_credit_limits' => ['chk_finance_credit_verified_049', 'chk_finance_credit_approved_049', 'chk_finance_credit_risk_basis_049', 'chk_finance_credit_mode']] as $table => $names) {
    foreach ($names as $name) $pdo->exec("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
}
$pdo->exec('ALTER TABLE finance_risk_assessments DROP COLUMN decision_mode');
$pdo->exec('ALTER TABLE finance_credit_limits DROP COLUMN decision_mode');
// Test-only restore of the 048 shape: MySQL refuses NULL -> NOT NULL on a foreign-key column while checks are on.
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->exec('ALTER TABLE finance_credit_limits MODIFY risk_assessment_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("ALTER TABLE finance_risk_assessments ADD CONSTRAINT chk_finance_risk_separation CHECK (status NOT IN ('APPROVED', 'SUPERSEDED') OR decided_by_employee_uuid <> proposed_by_employee_uuid)");
$pdo->exec("ALTER TABLE finance_credit_limits ADD CONSTRAINT chk_finance_credit_verified CHECK (status IN ('PROPOSED', 'REJECTED') OR (verified_by_employee_uuid IS NOT NULL AND verified_by_employee_uuid <> proposed_by_employee_uuid))");
$pdo->exec("ALTER TABLE finance_credit_limits ADD CONSTRAINT chk_finance_credit_approved CHECK (status NOT IN ('APPROVED', 'SUSPENDED', 'REVOKED', 'SUPERSEDED') OR (approved_by_employee_uuid IS NOT NULL
        AND approved_by_employee_uuid <> proposed_by_employee_uuid AND approved_by_employee_uuid <> verified_by_employee_uuid))");
$pdo->exec("DELETE FROM schema_migrations WHERE migration_name = '049_finance_owner_risk_limit_decisions.sql'");
$riskRows = $rows('SELECT * FROM finance_risk_assessments ORDER BY assessment_uuid');
$creditRows = $rows('SELECT * FROM finance_credit_limits ORDER BY limit_uuid');
check(count($riskRows) >= 2 && count($creditRows) >= 1, '23. the 048 fixture holds REVIEWED history');
check((new MigrationRunner($pdo))->migrate($dir) === ['049_finance_owner_risk_limit_decisions.sql'], '23. 048 -> 049 applies exactly 049');
check((new MigrationRunner($pdo))->migrate($dir) === [], '23. recorded once');
(new SqlFileRunner($pdo))->run($dir . '/049_finance_owner_risk_limit_decisions.sql');
$strip = static fn (array $list): array => array_map(static function (array $r): array { check(($r['decision_mode'] ?? null) === 'REVIEWED', '23. existing rows become REVIEWED'); unset($r['decision_mode']); return $r; }, $list);
check($strip($rows('SELECT * FROM finance_risk_assessments ORDER BY assessment_uuid')) === $riskRows, '23. every existing colour row preserved');
check($strip($rows('SELECT * FROM finance_credit_limits ORDER BY limit_uuid')) === $creditRows, '23. every existing limit row preserved');
check(refused($pdo, $riskSql, [$cDE, 'SUPERSEDED', 'REVIEWED', $emp[0], $now, $emp[0], $now]), '23. after the upgrade REVIEWED separation still holds');
check((int) $scalar('SELECT COUNT(*) FROM finance_owner_decisions') === 0, '23. the journal starts empty');
check($rows('SELECT * FROM role_permissions ORDER BY role_id, permission_id') === $staffBefore['grants'], '23/24. the upgrade grants nothing');
// The upgraded schema serves owner decisions again.
$fresh = $detail($ownerA, $cRO);
ok($color($ownerA, $cRO, $fresh['state'], 'YELLOW'), 201, '23. owner decision after the upgrade');

foreach (['Kitap1', 'Arasya_Finance_1437', 'Arasya_Cari'] as $private) check(!str_contains((string) file_get_contents(__FILE__), $private . '.'), 'no private file is referenced by this synthetic test');
$server->exec("DROP DATABASE IF EXISTS `{$db}`");
echo "PASS MySQL Finance V0.4.0 owner-direct risk colour and purchase-limit decisions ({$checks} checks).\n";
