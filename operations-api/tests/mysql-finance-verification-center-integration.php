<?php

declare(strict_types=1);

// Finance V0.3.1 against a disposable MySQL/MariaDB database with synthetic data only: legacy customer list staging
// (1,437 synthetic rows), XX/ZZ review metadata, reviewer separation and the supervisory route, restricted field history,
// default-deny risk and limit authority, and the opening-balance double-counting fix (finance_arasyahome#18).

use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Finance\Import\ImportNormalizer;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$db = T::requireTestDatabase();
if ($db === null) {
    echo "SKIP MySQL Finance verification centre: ARASYA_TEST_DB_NAME not configured.\n";
    exit;
}
$checks = 0;
function check(bool $value, string $message): void { global $checks; $checks++; if (!$value) throw new RuntimeException('FAIL ' . $message); }
function ok(array $r, int $status, string $message): array { check($r['status'] === $status, $message . ' got ' . $r['status'] . ' ' . json_encode($r['body'])); return $r['body'] ?? []; }
function err(array $r, int $status, string $code, string $message): void { ok($r, $status, $message); check(($r['body']['error']['code'] ?? null) === $code, $message . ' code ' . json_encode($r['body'])); }
function cui(string $base): string { $body = str_pad($base, 9, '0', STR_PAD_LEFT); $key = '753217532'; $sum = 0; for ($i = 0; $i < 9; $i++) { $sum += (int) $body[$i] * (int) $key[$i]; } $c = ($sum * 10) % 11; return $base . ($c === 10 ? 0 : $c); }

$db = substr($db, 0, 40) . '_finance_v031';
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

// ---- 048: additive, re-runnable, grants nothing ---------------------------------------------------------------
$grants = $pdo->query('SELECT * FROM role_permissions ORDER BY role_id, permission_id')->fetchAll();
$appGrants = $pdo->query('SELECT * FROM employee_application_access ORDER BY employee_uuid, application_key')->fetchAll();
(new SqlFileRunner($pdo))->run($dir . '/048_finance_verification_center.sql');
check($pdo->query('SELECT * FROM role_permissions ORDER BY role_id, permission_id')->fetchAll() === $grants, '048 re-run grants no role anything');
check($pdo->query('SELECT * FROM employee_application_access ORDER BY employee_uuid, application_key')->fetchAll() === $appGrants, '048 grants no application access');
check((int) $scalar("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.permission_key IN ('finance.companies.supervise', 'finance.audit.sensitive')") === 0, 'new permissions granted to nobody');
check((int) $scalar("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('finance_case_flags', 'finance_case_field_changes', 'finance_case_recusals', 'finance_case_comments', 'finance_opening_reconciliations', 'finance_opening_reconciliation_lines') AND (DELETE_RULE NOT IN ('RESTRICT', 'NO ACTION') OR UPDATE_RULE NOT IN ('RESTRICT', 'NO ACTION'))") === 0, 'no cascading foreign keys on the new tables');

// ---- Normalizer units ----------------------------------------------------------------------------------------
foreach (['ZZ Ana', 'ZZZZZ ANA SRL', 'zzz ana', 'XXXX Beta', '  XX-Beta', '*ZZ Gamma'] as $name) check(ImportNormalizer::hasLegacyPassivePrefix($name), "prefix detected: {$name}");
foreach (['Z Ana', 'Zeta SRL', 'X Beta', 'Alfa XX', 'Azz'] as $name) check(!ImportNormalizer::hasLegacyPassivePrefix($name), "no prefix: {$name}");
check(ImportNormalizer::looksCancelled('Alfa ANULAT') && ImportNormalizer::looksCancelled('Beta İptal') && !ImportNormalizer::looksCancelled('Anul Nou SRL'), 'cancelled-looking labels');
check(ImportNormalizer::phoneKey('TEL') === null && ImportNormalizer::phoneKey('0000000000') === null && ImportNormalizer::phoneKey('0721 000 001') !== null, 'placeholder phones never produce a match key');
$map = [0 => 'legacyCustomerCode', 1 => 'legalName', 2 => 'phone'];
$legacy = ImportNormalizer::normalize($map, ['C9', 'ZZZ  Ana   SRL', 'TEL'], 'CSV', true);
check($legacy['outcome'] === 'incomplete' && $legacy['values']['legalName'] === null && $legacy['values']['sourceCustomerName'] === 'ZZZ  Ana   SRL' && $legacy['values']['countryCode'] === null, 'label stays source data, not a legal name; no country inferred');
check(in_array('EMPLOYEE_REPORTED_PASSIVE_UNVERIFIED', $legacy['flags'], true) && in_array('PLACEHOLDER_PHONE', $legacy['flags'], true) && in_array('MISSING_COUNTRY', $legacy['flags'], true), 'review flags on a legacy row');
check(ImportNormalizer::normalize($map, ['C9', '', ''], 'CSV', true)['outcome'] === 'incomplete', 'a nameless legacy row still enters staging');
check(ImportNormalizer::normalize($map, ['', '', ''], 'CSV', true)['outcome'] === 'rejected', 'only a row with nothing mapped is refused');

// ---- Identities --------------------------------------------------------------------------------------------------
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
$person = static function (string $name, array $permissions) use ($admin, $call, $root, $password, $suffix, $kernel): array {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test');
    ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['finance']]), 200, "{$name} applications");
    if ($permissions !== []) {
        $roleId = (int) ok($call($root, 'POST', '/management/roles', ['name' => "{$name} {$suffix}", 'description' => null, 'authorityRank' => 200, 'permissions' => $permissions]), 201, "role {$name}")['role']['id'];
        ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => [$roleId]]), 200, "{$name} roles");
    }
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['id' => $employee->employeeUuid];
};
$view = 'finance.companies.view';
$importer = $person('importer', [$view, 'finance.companies.import']);
$reviewerA = $person('reviewera', [$view, 'finance.companies.review', 'finance.companies.resolve_duplicates']);
$reviewerB = $person('reviewerb', [$view, 'finance.companies.review']);
$verifier = $person('verifier', [$view, 'finance.companies.verify']);
$both = $person('both', [$view, 'finance.companies.review', 'finance.companies.verify']);
$supervisor = $person('supervisor', [$view, 'finance.companies.verify', 'finance.companies.supervise']);
$auditor = $person('auditor', [$view, 'finance.audit.view']);
$sensitive = $person('sensitive', [$view, 'finance.audit.view', 'finance.audit.sensitive']);
$noGrant = $person('nogrant', []);
$acc1 = $person('accone', ['finance.accounts.view', 'finance.accounts.reconcile', 'finance.accounts.propose_opening_balance']);
$acc2 = $person('acctwo', ['finance.accounts.view', 'finance.accounts.reconcile', 'finance.accounts.verify_opening_balance', 'finance.accounts.post_approved_opening_balance']);
$financeAdmin = $person('finadmin', ['finance.risk.view', 'finance.risk.propose', 'finance.risk.approve', 'finance.credit.view', 'finance.credit.propose', 'finance.credit.verify', 'finance.credit.approve', 'finance.credit.revoke']);
$authorityPerson = $person('authority', ['finance.risk.view', 'finance.risk.approve', 'finance.credit.view', 'finance.credit.approve', 'finance.credit.revoke']);
$analyst = $person('analyst', ['finance.risk.view', 'finance.risk.propose']);

$args = [];
foreach ((new ReflectionClass(Config::class))->getConstructor()->getParameters() as $parameter) {
    $name = $parameter->getName();
    $args[$name] = match ($name) { 'financeOpeningBalancePosting' => 'enabled', 'financeRiskLimitAuthorityUuids' => $authorityPerson['id'], default => $config->{$name} };
}
$live = (new Container(new Config(...$args), $pdo))->kernel();

$productionSnapshot = static function () use ($pdo): string {
    $out = [];
    foreach (['operational_orders', 'operational_order_items', 'order_activity_events', 'production_workflows', 'order_finance_actions', 'employee_finance_scopes', 'finance_verification_requests', 'b2b_orders', 'b2b_order_lines'] as $table) {
        $rows = array_map(serialize(...), $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC));
        sort($rows, SORT_STRING);
        $out[] = $table . ':' . hash('sha256', implode("\n", $rows));
    }
    return implode('|', $out);
};
$before = $productionSnapshot();
$rolesBefore = $rows('SELECT * FROM employee_role_assignments ORDER BY employee_uuid, role_id');
$applicationsBefore = $rows('SELECT * FROM employee_application_access ORDER BY employee_uuid, application_key');

$existingCui = cui('3141592');
$asRoot = static fn (string $method, string $path, array $json): array => T::call($kernel, $method, $path, $json, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'fin-test-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$existing = ok($asRoot('POST', '/b2b/companies', ['legalName' => 'Omega Synthetic SRL', 'countryCode' => 'RO', 'taxIdentifier' => 'RO' . $existingCui]), 201, 'existing B2B company')['companyId'];
$existingRow = $pdo->query('SELECT * FROM b2b_companies WHERE company_uuid = ' . $pdo->quote($existing))->fetch(PDO::FETCH_ASSOC);

// ---- Synthetic 1,437-row legacy list ---------------------------------------------------------------------------------
$source = [];
for ($i = 1; $i <= 1437; $i++) {
    $source[$i] = ['code' => 'L' . $i, 'name' => "Synthetic Customer {$i} SRL", 'phone' => $i % 3 === 0 ? 'TEL' : ($i % 3 === 1 ? '' : '07' . str_pad((string) $i, 8, '0', STR_PAD_LEFT))];
}
// Code L3 three times (1,435 distinct codes): two identical rows and one with a different name.
$source[3] = ['code' => 'L3', 'name' => 'Opening Retail Label', 'phone' => 'TEL'];
$source[700] = ['code' => 'L3', 'name' => 'Opening Retail Label', 'phone' => 'TEL'];
$source[1200] = ['code' => 'L3', 'name' => 'Another Retail Label', 'phone' => 'TEL'];
$prefixRows = range(10, 85);
foreach ($prefixRows as $n => $i) {
    $mark = $n < 65 ? str_repeat('Z', 2 + $n % 4) : str_repeat('X', 2 + $n % 3);
    $source[$i]['name'] = $mark . ' Synthetic  Prefixed ' . $i . ($n < 14 ? ($n % 2 === 0 ? ' ANULAT' : ' IPTAL') : '');
}
$source[50]['name'] = 'ZZZ Passive Claim Subject SRL';
$source[100]['phone'] = '0733 000 111'; $source[101]['phone'] = '0733000111'; // a shared phone with different names
$source[120]['name'] = ''; // no name at all
$source[121]['name'] = '.'; // placeholder
$csv = "Cod client;Client;Telefon\n";
foreach ($source as $row) $csv .= "{$row['code']};{$row['name']};{$row['phone']}\n";
check(count($source) === 1437 && count(array_unique(array_column($source, 'code'))) === 1435, 'synthetic fixture matches the shape of the private list (no real data)');

$upload = static fn (array $who, string $body, array $query = []): array => T::call($kernel, 'POST', '/finance/imports', null, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => 'fin-test-up-' . bin2hex(random_bytes(10)), 'content-type' => 'text/csv'], $who['cookie'], $query, $body);
err($upload($importer, $csv, ['profile' => 'NOPE']), 422, 'VALIDATION_FAILED', 'unknown profile refused');
$batch = ok($upload($importer, $csv, ['source' => 'OTHER_LEGACY', 'name' => 'synthetic-list.csv', 'profile' => 'LEGACY_CUSTOMER_LIST']), 201, 'legacy list uploaded')['batchId'];
$mapping = ['0' => 'legacyCustomerCode', '1' => 'legalName', '2' => 'phone'];
ok($call($importer, 'POST', "/finance/imports/{$batch}/mapping", ['expectedVersion' => 1, 'mapping' => $mapping]), 200, 'mapped');
$analyze = static function (string $id) use ($call, $importer): int { for ($i = 0; $i < 50; $i++) { $r = ok($call($importer, 'POST', "/finance/imports/{$id}/analyze", null), 200, 'analysis chunk'); if ($r['done']) return $r['version']; } throw new RuntimeException('analysis did not finish'); };
$version = $analyze($batch);
check((int) $scalar('SELECT COUNT(*) FROM finance_company_cases') === 0, 'analysis stages nothing');
$detail = ok($call($importer, 'GET', "/finance/imports/{$batch}"), 200, 'batch detail');
check($detail['profile'] === 'LEGACY_CUSTOMER_LIST' && $detail['counts']['incomplete'] === 1437 && $detail['counts']['rejected'] === 0 && $detail['counts']['duplicateInFile'] === 0, 'all 1,437 rows are stageable; none refused or dropped as duplicates');
$stage = static function (string $id, int $version) use ($call, $importer): int {
    $staged = 0;
    for ($i = 0; $i < 50; $i++) { $r = ok($call($importer, 'POST', "/finance/imports/{$id}/confirm", ['expectedVersion' => $version, 'confirm' => true]), 200, 'confirm chunk'); $staged += $r['staged'] ?? 0; if ($r['done']) return $staged; }
    throw new RuntimeException('confirmation did not finish');
};
$companiesBefore = (int) $scalar('SELECT COUNT(*) FROM b2b_companies');
check($stage($batch, $version) === 1437, '1. all 1,437 synthetic rows staged');
$cases = $rows('SELECT c.*, r.line_no FROM finance_company_cases c JOIN finance_import_rows r ON r.row_uuid = c.import_row_uuid WHERE r.batch_uuid = ? ORDER BY c.case_number', [$batch]);
check(count($cases) === 1437, '1. no row lost');
check(array_column($cases, 'line_no') == range(1, 1437), '2. case numbers follow the original row order');
$same = true; foreach ($cases as $c) { $src = $source[(int) $c['line_no']]; if ($c['source_customer_code'] !== $src['code'] || ($c['source_customer_name'] ?? '') !== $src['name']) { $same = false; } }
check($same, '2/7. every legacy code and label is kept exactly, X/Z prefixes and double spaces included');
$byLine = array_column($cases, null, 'line_no');
check((int) $scalar("SELECT COUNT(DISTINCT case_uuid) FROM finance_company_cases WHERE legacy_customer_code = 'L3'") === 3, '3. a repeated legacy code stays three separate records');
check($byLine[3]['source_customer_name'] === 'Opening Retail Label' && $byLine[700]['source_customer_name'] === 'Opening Retail Label' && $byLine[3]['case_uuid'] !== $byLine[700]['case_uuid'], '3. identical rows are never merged');
check($byLine[1200]['source_customer_name'] === 'Another Retail Label', '3. a later row cannot overwrite an earlier one');
check((int) $scalar('SELECT COUNT(*) FROM b2b_companies') === $companiesBefore && (int) $scalar('SELECT COUNT(*) FROM finance_company_cases WHERE company_uuid IS NOT NULL') === 0, '4. missing CUI: staging only, no company');
check((int) $scalar('SELECT COUNT(*) FROM finance_company_cases WHERE legal_name IS NOT NULL') === 0 && (int) $scalar('SELECT COUNT(*) FROM finance_company_cases WHERE tax_normalized IS NOT NULL') === 0, 'a label never becomes a legal name; no tax identifier fabricated');
check((int) $scalar('SELECT COUNT(*) FROM finance_company_cases WHERE country_code IS NOT NULL') === 0 && (int) $scalar('SELECT COUNT(*) FROM finance_account_onboardings') === 0, '5. missing country gives no country and no RON account');
check((int) $scalar('SELECT COUNT(*) FROM b2b_account_movements') === 0, 'import writes no ledger movement');
$facets = ok($call($reviewerA, 'GET', '/finance/cases/facets'), 200, 'facets');
check($facets['flags']['EMPLOYEE_REPORTED_PASSIVE_UNVERIFIED'] === 76 && $facets['flags']['CANCELLED_LOOKING_NAME'] === 14, '6. 76 passive-claim flags, 14 cancelled-looking labels ' . json_encode($facets['flags']));
check($facets['flags']['REPEATED_LEGACY_CODE'] === 3 && $facets['flags']['MISSING_TAX_IDENTIFIER'] === 1437 && $facets['flags']['PLACEHOLDER_PHONE'] >= 479, 'informational indicators are real counts');
check($facets['duplicatesOpen'] === (int) $scalar("SELECT COUNT(*) FROM finance_company_cases WHERE duplicate_review = 'open'") && $facets['duplicatesOpen'] >= 3, 'the duplicate count equals the duplicate queue');
check($facets['flags']['REPEATED_PHONE'] === 2 && (int) $scalar("SELECT COUNT(DISTINCT case_uuid) FROM finance_company_cases WHERE phone_key = (SELECT phone_key FROM finance_company_cases WHERE case_uuid = ?)", [$byLine[100]['case_uuid']]) === 2, 'a shared phone is two records, not one');
check($byLine[100]['status'] === 'NEEDS_INFORMATION' && $byLine[101]['status'] === 'NEEDS_INFORMATION', 'a shared phone alone merges nothing and suspects nothing strong');
$p = $byLine[50];
check($p['status'] === 'NEEDS_INFORMATION' && $p['review_priority'] === 'P0', '7. the prefix is metadata: the workflow status stays a normal one');
check((int) $scalar("SELECT COUNT(*) FROM finance_company_cases WHERE status NOT IN ('NEEDS_INFORMATION', 'SUSPECTED_DUPLICATE')") === 0, '7. no case is inactive, approved or rejected because of a label');
check((int) $scalar('SELECT COUNT(*) FROM finance_risk_assessments') === 0 && (int) $scalar('SELECT COUNT(*) FROM finance_credit_limits') === 0, '8. no RED or any risk colour, no limit');
check($byLine[120]['source_customer_name'] === null && $byLine[120]['source_customer_code'] !== null, 'a nameless row is staged with its code');
$queue = ok($call($reviewerA, 'GET', '/finance/cases', null, ['flag' => 'EMPLOYEE_REPORTED_PASSIVE_UNVERIFIED', 'limit' => '100', 'sort' => 'source']), 200, 'XX/ZZ queue');
check(count($queue['items']) === 76 && $queue['nextCursor'] === null && $queue['items'][0]['flags'] !== [], 'the XX/ZZ queue lists exactly the flagged records');
check(count(ok($call($reviewerA, 'GET', '/finance/cases', null, ['q' => 'L3']), 200, 'code search')['items']) === 3, 'search by legacy code');
check(count(ok($call($reviewerA, 'GET', '/finance/cases', null, ['q' => 'Passive Claim Subject']), 200, 'name search')['items']) === 1, 'search by original name');
check(count(ok($call($reviewerA, 'GET', '/finance/cases', null, ['q' => '0733 000 111']), 200, 'phone search')['items']) === 2, 'search by phone finds both records');
check(count(ok($call($reviewerA, 'GET', '/finance/cases', null, ['batch' => $batch, 'priority' => 'P0', 'limit' => '25', 'sort' => 'source']), 200, 'batch + priority')['items']) === 25, 'batch and priority filters');
check(count(ok($call($reviewerA, 'GET', '/finance/cases', null, ['country' => 'NONE', 'limit' => '25']), 200, 'no country')['items']) === 25, 'unknown-country filter');
$page2 = ok($call($reviewerA, 'GET', '/finance/cases', null, ['limit' => '25', 'sort' => 'source']), 200, 'source order');
check($page2['items'][0]['sourceCustomerCode'] === 'L1' && ok($call($reviewerA, 'GET', '/finance/cases', null, ['limit' => '25', 'sort' => 'source', 'cursor' => $page2['nextCursor']]), 200, 'next')['items'][0]['sourceCustomerCode'] === 'L26', 'source-order keyset pagination');

// 13. Reimport: same file refused; a re-export with the same rows finds them all and stages only the new row.
err($upload($importer, $csv, ['profile' => 'LEGACY_CUSTOMER_LIST']), 409, 'DUPLICATE_UPLOAD', '13. the same file cannot be imported twice');
$batch2 = ok($upload($importer, $csv . "LNEW;Brand New Synthetic SRL;\n", ['source' => 'OTHER_LEGACY', 'profile' => 'LEGACY_CUSTOMER_LIST']), 201, 're-export uploaded')['batchId'];
ok($call($importer, 'POST', "/finance/imports/{$batch2}/mapping", ['expectedVersion' => 1, 'mapping' => $mapping]), 200, 're-export mapped');
$v2 = $analyze($batch2);
check(ok($call($importer, 'GET', "/finance/imports/{$batch2}"), 200, 'detail 2')['counts']['known'] === 1437, '13. all earlier rows, repeats included, are recognised');
check($stage($batch2, $v2) === 1 && (int) $scalar('SELECT COUNT(*) FROM finance_company_cases') === 1438, '13. reimport duplicates nothing');
check((int) $scalar("SELECT COUNT(*) FROM finance_import_rows WHERE batch_uuid = ? AND outcome = 'known' AND case_uuid IS NOT NULL", [$batch2]) === 1437, '13. reimported rows keep a provenance link to their case');

// ---- Passive claim, invalid tax identity, restricted history -----------------------------------------------------------
$cid = $p['case_uuid'];
$get = fn (array $who, string $id) => ok($call($who, 'GET', "/finance/cases/{$id}"), 200, 'case detail');
$d = $get($reviewerA, $cid);
$flag = array_values(array_filter($d['flags'], static fn (array $f): bool => $f['code'] === 'EMPLOYEE_REPORTED_PASSIVE_UNVERIFIED'))[0];
check($d['source']['fileSha256'] === hash('sha256', $csv) && $d['source']['line'] === 50 && $d['source']['importedBy'] === 'Test importer' && $d['source']['cells'][1] === 'ZZZ Passive Claim Subject SRL', 'provenance: file hash, line, importer, raw cells');
check($d['accountBalanceVerified'] === false, 'balance is explicitly unverified');
check($flag['status'] === 'open' && $d['case']['sourceCustomerName'] === 'ZZZ Passive Claim Subject SRL' && $d['case']['legalName'] === null, 'flag open; registered name still empty');
$v = $d['case']['version'];
err($call($importer, 'POST', "/finance/cases/{$cid}/claim", ['expectedVersion' => $v]), 403, 'UNAUTHORIZED_ACTION', '10. an importer cannot take review work');
$v = ok($call($reviewerA, 'POST', "/finance/cases/{$cid}/claim", ['expectedVersion' => $v]), 200, 'claim')['version'];
err($call($reviewerA, 'POST', "/finance/cases/{$cid}/update", ['expectedVersion' => $v, 'fields' => ['legacyCustomerCode' => 'OTHER']]), 409, 'SOURCE_CODE_IMMUTABLE', 'the imported code cannot be rewritten');
$v = ok($call($reviewerA, 'POST', "/finance/cases/{$cid}/update", ['expectedVersion' => $v, 'fields' => ['phone' => '0740 100 200']]), 200, 'edit with no legal name yet')['version'];
$v = ok($call($reviewerA, 'POST', "/finance/cases/{$cid}/update", ['expectedVersion' => $v, 'fields' => ['legalName' => 'Verified Registered Name SRL', 'countryCode' => 'RO', 'taxIdentifier' => '18547291', 'addressLine' => 'Str. Sintetic 1', 'city' => 'Cluj']]), 200, 'registered identity entered')['version'];
$evidence = [['kind' => 'registry_extract', 'sourceSystem' => 'REGISTRY', 'reference' => 'Synthetic registry extract V031', 'documentDate' => '2026-11-01']];
err($call($reviewerA, 'POST', "/finance/cases/{$cid}/checklist", ['expectedVersion' => $v, 'item' => 'tax_id', 'state' => 'done', 'evidence' => $evidence]), 409, 'TAX_IDENTIFIER_INVALID', '12. an invalid tax identity cannot be verified');
$good = cui('1854729');
$v = ok($call($reviewerA, 'POST', "/finance/cases/{$cid}/update", ['expectedVersion' => $v, 'fields' => ['taxIdentifier' => $good], 'reason' => 'Corrected from the registry extract', 'evidence' => $evidence]), 200, 'tax corrected with reason')['version'];
$complete = static function (array $who, string $id, int $version) use ($call, $evidence): int {
    foreach (['legal_name', 'country', 'tax_id', 'duplicate_search', 'address', 'contact', 'sources', 'documents', 'conflicts'] as $item) {
        $body = ['expectedVersion' => $version, 'item' => $item, 'state' => 'done'];
        if ($item === 'tax_id') $body['evidence'] = $evidence;
        $version = ok($call($who, 'POST', "/finance/cases/{$id}/checklist", $body), 200, "checklist {$item}")['version'];
    }
    return $version;
};
$v = $complete($reviewerA, $cid, $v);
err($call($reviewerA, 'POST', "/finance/cases/{$cid}/submit", ['expectedVersion' => $v]), 409, 'PASSIVE_CLAIM_UNRESOLVED', 'the employee claim blocks submission');
err($call($reviewerA, 'POST', "/finance/cases/{$cid}/flags/{$flag['id']}/resolve", ['expectedVersion' => $v, 'resolution' => 'GENUINELY_INACTIVE', 'note' => 'Colleague says so']), 422, 'VALIDATION_FAILED', 'a verbal statement without evidence is refused');
err($call($reviewerA, 'POST', "/finance/cases/{$cid}/flags/{$flag['id']}/resolve", ['expectedVersion' => $v, 'resolution' => 'NOT_AN_ISSUE', 'note' => 'Nothing to see', 'evidence' => $evidence]), 422, 'VALIDATION_FAILED', 'a passive claim needs a real outcome');
$v = ok($call($reviewerA, 'POST', "/finance/cases/{$cid}/flags/{$flag['id']}/resolve", ['expectedVersion' => $v, 'resolution' => 'UNDETERMINED', 'note' => 'Documents not yet available']), 200, 'undetermined')['version'];
err($call($reviewerA, 'POST', "/finance/cases/{$cid}/submit", ['expectedVersion' => $v]), 409, 'PASSIVE_CLAIM_UNRESOLVED', 'UNDETERMINED keeps the case unverified');
$v = ok($call($reviewerA, 'POST', "/finance/cases/{$cid}/flags/{$flag['id']}/resolve", ['expectedVersion' => $v, 'resolution' => 'ACTIVE', 'note' => 'Synthetic SAGA ledger shows activity', 'evidence' => [['kind' => 'saga_ledger', 'sourceSystem' => 'SAGA_EXPORT', 'reference' => 'Synthetic partner ledger P1']]]), 200, 'independent outcome')['version'];
$v = ok($call($reviewerA, 'POST', "/finance/cases/{$cid}/submit", ['expectedVersion' => $v]), 200, 'submitted')['version'];
err($call($reviewerA, 'POST', "/finance/cases/{$cid}/approve", ['expectedVersion' => $v]), 403, 'UNAUTHORIZED_ACTION', 'reviewer cannot approve');
$approved = ok($call($verifier, 'POST', "/finance/cases/{$cid}/approve", ['expectedVersion' => $v]), 200, 'second person approves');
$company = $pdo->query('SELECT * FROM b2b_companies WHERE company_uuid = ' . $pdo->quote($approved['companyId']))->fetch(PDO::FETCH_ASSOC);
check($approved['canonical'] === 'created_inactive' && $company['status'] === 'inactive' && $company['legal_name'] === 'Verified Registered Name SRL' && preg_match('/^B2B-\d{6}$/D', $company['company_code']) === 1, 'canonical company: verified name, inactive, B2B code generation');
check($scalar('SELECT source_customer_name FROM finance_company_cases WHERE case_uuid = ?', [$cid]) === 'ZZZ Passive Claim Subject SRL', 'the original label survives verification unchanged');
$history = $get($sensitive, $cid);
$changes = array_column(array_reverse($history['fieldChanges']), null, 'field');
check($changes['taxIdentifier']['old'] === '18547291' && $changes['taxIdentifier']['new'] === $good && $changes['taxIdentifier']['actor'] === 'Test reviewera' && $changes['taxIdentifier']['reason'] === 'Corrected from the registry extract' && $changes['taxIdentifier']['evidence'] === 1, 'restricted history: old, new, who, why, evidence');
check($get($reviewerA, $cid)['fieldChanges'] === null && $get($auditor, $cid)['fieldChanges'] === null, 'old and new values only for finance.audit.sensitive');
$eventsJson = json_encode($rows('SELECT details_json, reason FROM finance_activity_events WHERE entity_uuid = ?', [$cid]));
check(!str_contains($eventsJson, '18547291') && !str_contains($eventsJson, '0740') && !str_contains($eventsJson, 'Verified Registered'), 'the general event log carries field names, not values');
$actions = array_column($history['history'], 'action');
foreach (['case_created', 'case_triaged', 'case_claimed', 'case_updated', 'checklist_updated', 'flag_resolved', 'case_submitted', 'case_approved'] as $action) check(in_array($action, $actions, true), "history records {$action}");
check($history['case']['createdBy'] === 'Test importer' && $history['case']['submittedBy'] === 'Test reviewera' && $history['case']['approvedBy'] === 'Test verifier', 'who imported, submitted and verified');

// An identity change on an existing value without a reason raises an indicator (and accuses nobody).
$x = $byLine[200]['case_uuid'];
$xv = $get($reviewerB, $x)['case']['version'];
$xv = ok($call($reviewerB, 'POST', "/finance/cases/{$x}/claim", ['expectedVersion' => $xv]), 200, 'claim x')['version'];
$xv = ok($call($reviewerB, 'POST', "/finance/cases/{$x}/update", ['expectedVersion' => $xv, 'fields' => ['legalName' => 'First Name SRL']]), 200, 'first name')['version'];
check(!in_array('UNEXPLAINED_IDENTITY_CHANGE', array_column($get($reviewerB, $x)['flags'], 'code'), true), 'filling an empty field is not a change');
$xv = ok($call($reviewerB, 'POST', "/finance/cases/{$x}/update", ['expectedVersion' => $xv, 'fields' => ['legalName' => 'Second Name SRL']]), 200, 'second name')['version'];
check(in_array('UNEXPLAINED_IDENTITY_CHANGE', array_column($get($reviewerB, $x)['flags'], 'code'), true), 'an unexplained identity change is flagged');
$identityFlag = array_values(array_filter($get($reviewerB, $x)['flags'], static fn (array $f): bool => $f['code'] === 'UNEXPLAINED_IDENTITY_CHANGE'))[0];
$xv = ok($call($reviewerB, 'POST', "/finance/cases/{$x}/flags/{$identityFlag['id']}/resolve", ['expectedVersion' => $xv, 'resolution' => 'EXPLAINED', 'note' => 'Synthetic typo correction']), 200, 'explained')['version'];
$xv = ok($call($reviewerB, 'POST', "/finance/cases/{$x}/update", ['expectedVersion' => $xv, 'fields' => ['legalName' => 'Third Name SRL']]), 200, 'third name')['version'];
check(array_values(array_filter($get($reviewerB, $x)['flags'], static fn (array $f): bool => $f['code'] === 'UNEXPLAINED_IDENTITY_CHANGE'))[0]['status'] === 'open', 'a new unexplained change reopens the indicator');
$xv = ok($call($reviewerB, 'POST', "/finance/cases/{$x}/release", ['expectedVersion' => $xv]), 200, 'release x')['version'];
check($get($reviewerB, $x)['case']['status'] === 'NEEDS_REVIEW', 'released');

// ---- Reviewer separation and the supervisory route ------------------------------------------------------------------------
$manual = static function (string $name, string $tax, array $who) use ($call, $get, $complete): array {
    $id = ok($call($who, 'POST', '/finance/cases', ['legalName' => $name, 'countryCode' => 'RO', 'taxIdentifier' => $tax, 'addressLine' => 'Str. Sintetic 2', 'city' => 'Arad']), 201, 'manual case')['caseId'];
    $v = $get($who, $id)['case']['version'];
    $v = ok($call($who, 'POST', "/finance/cases/{$id}/claim", ['expectedVersion' => $v]), 200, 'claim')['version'];
    return [$id, $complete($who, $id, $v)];
};
[$own, $ov] = $manual('Own Case SRL', cui('1414213'), $both);
$ov = ok($call($both, 'POST', "/finance/cases/{$own}/submit", ['expectedVersion' => $ov]), 200, 'both submits')['version'];
err($call($both, 'POST', "/finance/cases/{$own}/approve", ['expectedVersion' => $ov]), 403, 'FOUR_EYES_REQUIRED', '9. an editor cannot approve the own case');
check(!in_array('approve', $get($both, $own)['actions'], true), '9. approve is not offered');
// Same identity, later holding only a verify role: edit history still counts.
[$swap, $sv] = $manual('Role Switch SRL', cui('2718281'), $both);
$sv = ok($call($both, 'POST', "/finance/cases/{$swap}/submit", ['expectedVersion' => $sv]), 200, 'submit swap')['version'];
$verifierOnlyRole = (int) ok($call($root, 'POST', '/management/roles', ['name' => "verifyonly {$suffix}", 'description' => null, 'authorityRank' => 200, 'permissions' => [$view, 'finance.companies.verify']]), 201, 'verify only role')['role']['id'];
ok($call($root, 'PUT', "/management/employees/{$both['id']}/roles", ['roleIds' => [$verifierOnlyRole]]), 200, 'role switched');
$both2 = T::login($kernel, "both.{$suffix}", $password) + ['id' => $both['id']];
err($call($both2, 'POST', "/finance/cases/{$swap}/approve", ['expectedVersion' => $sv]), 403, 'FOUR_EYES_REQUIRED', 'switching roles does not bypass separation');
// A duplicate resolver or checklist editor who later verifies is refused as well.
$rd = $byLine[3]['case_uuid'];
$dd = $get($reviewerA, $rd);
check($dd['case']['duplicateReview'] === 'open' && $byLine[700]['status'] === 'SUSPECTED_DUPLICATE' && count($dd['comparison']['sameCode']) === 2, 'the same-code records are offered for comparison');
$rv = ok($call($reviewerA, 'POST', "/finance/cases/{$rd}/claim", ['expectedVersion' => $dd['case']['version']]), 200, 'claim duplicate')['version'];
foreach ($dd['duplicates'] as $candidate) {
    if ($candidate['status'] === 'open' && $candidate['strength'] !== 'weak') {
        $rv = ok($call($reviewerA, 'POST', "/finance/cases/{$rd}/duplicates/{$candidate['id']}/resolve", ['expectedVersion' => $rv, 'decision' => 'kept_separate', 'reason' => 'Separate synthetic records', 'evidence' => $evidence]), 200, 'kept separate')['version'];
    }
}
$dd = $get($reviewerA, $rd);
check($dd['decisions'][0]['decision'] === 'kept_separate' && $dd['duplicates'][0]['resolvedBy'] === 'Test reviewera' && $dd['duplicates'][0]['reason'] === 'Separate synthetic records', '11. resolution keeps who, why and the candidate');
check((int) $scalar("SELECT COUNT(*) FROM finance_company_cases WHERE legacy_customer_code = 'L3' AND status <> 'REJECTED'") === 3, '11. nothing merged');
check((int) $scalar("SELECT COUNT(*) FROM finance_activity_events WHERE entity_uuid = ? AND action = 'duplicate_kept_separate'", [$rd]) >= 1, '11. duplicate decision audited');

// Conflict of interest.
$co = $byLine[300]['case_uuid'];
$cv = $get($reviewerB, $co)['case']['version'];
$cv = ok($call($reviewerB, 'POST', "/finance/cases/{$co}/claim", ['expectedVersion' => $cv]), 200, 'B claims')['version'];
err($call($reviewerB, 'POST', "/finance/cases/{$co}/supervision", ['expectedVersion' => $cv, 'declaration' => 'recuse_other', 'reason' => 'Trying to remove someone', 'employeeId' => $reviewerA['id']]), 403, 'UNAUTHORIZED_ACTION', 'only a supervisor can name another employee');
err($call($noGrant, 'POST', "/finance/cases/{$co}/supervision", ['expectedVersion' => $cv, 'declaration' => 'request_supervision', 'reason' => 'No rights here']), 403, 'UNAUTHORIZED_ACTION', '10. no grant, no route');
$cv = ok($call($reviewerB, 'POST', "/finance/cases/{$co}/supervision", ['expectedVersion' => $cv, 'declaration' => 'self_conflict', 'reason' => 'I handled this historical record']), 200, 'self conflict')['version'];
$dc = $get($reviewerB, $co);
check($dc['case']['status'] === 'NEEDS_REVIEW' && $dc['supervision']['required'] === true && $dc['supervision']['actorRecused'] === true && $dc['supervision']['recusals'] === null, 'released to the queue; recusal details hidden from an ordinary reviewer');
check(!in_array('claim', $dc['actions'], true), 'claim not offered to a recused employee');
err($call($reviewerB, 'POST', "/finance/cases/{$co}/claim", ['expectedVersion' => $cv]), 403, 'CONFLICT_OF_INTEREST', 'a recused employee cannot take the case back');
check($get($supervisor, $co)['supervision']['recusals'][0]['employee'] === 'Test reviewerb', 'the supervisor sees who stepped away');
$cv = ok($call($reviewerA, 'POST', "/finance/cases/{$co}/claim", ['expectedVersion' => $cv]), 200, 'A claims')['version'];
$cv = ok($call($reviewerA, 'POST', "/finance/cases/{$co}/update", ['expectedVersion' => $cv, 'fields' => ['legalName' => 'Supervised Case SRL', 'countryCode' => 'RO', 'taxIdentifier' => cui('1732050'), 'addressLine' => 'Str. S 3', 'city' => 'Iasi']]), 200, 'A edits')['version'];
$cv = $complete($reviewerA, $co, $cv);
$cv = ok($call($reviewerA, 'POST', "/finance/cases/{$co}/submit", ['expectedVersion' => $cv]), 200, 'A submits')['version'];
err($call($verifier, 'POST', "/finance/cases/{$co}/approve", ['expectedVersion' => $cv]), 403, 'SUPERVISOR_REQUIRED', 'an ordinary verifier cannot close a supervised case');
check(!in_array('approve', $get($verifier, $co)['actions'], true) && in_array('approve', $get($supervisor, $co)['actions'], true), 'approve offered to the supervisor only');
err($call($verifier, 'POST', "/finance/cases/{$co}/reject", ['expectedVersion' => $cv, 'reason' => 'Closing it another way']), 403, 'SUPERVISOR_REQUIRED', 'an ordinary verifier cannot reject a supervised case either');
err($call($verifier, 'POST', "/finance/cases/{$co}/return", ['expectedVersion' => $cv, 'reason' => 'Returning it another way']), 403, 'SUPERVISOR_REQUIRED', 'nor return it');
ok($call($supervisor, 'POST', "/finance/cases/{$co}/approve", ['expectedVersion' => $cv]), 200, 'independent supervisor approves');
check(ok($call($reviewerA, 'POST', "/finance/cases/{$co}/comments", ['body' => 'Synthetic review comment']), 201, 'comment')['commentId'] !== '' && $get($reviewerA, $co)['comments'][0]['body'] === 'Synthetic review comment', 'comments are kept');
err($call($auditor, 'POST', "/finance/cases/{$co}/comments", ['body' => 'Auditors only read']), 403, 'UNAUTHORIZED_ACTION', 'auditors do not write');
err($call($reviewerA, 'GET', '/finance/audit'), 403, 'UNAUTHORIZED_ACTION', '10. audit is permission-restricted');

// 14. Linking keeps the canonical B2B identity untouched.
$lk = $byLine[400]['case_uuid'];
$lv = $get($reviewerA, $lk)['case']['version'];
$lv = ok($call($reviewerA, 'POST', "/finance/cases/{$lk}/claim", ['expectedVersion' => $lv]), 200, 'claim link')['version'];
$lv = ok($call($reviewerA, 'POST', "/finance/cases/{$lk}/update", ['expectedVersion' => $lv, 'fields' => ['countryCode' => 'DE'], 'reason' => 'Synthetic conflicting country']), 200, 'edit link')['version'];
$lv = ok($call($reviewerA, 'POST', "/finance/cases/{$lk}/link", ['expectedVersion' => $lv, 'companyId' => $existing, 'reason' => 'Same synthetic customer', 'evidence' => $evidence]), 200, 'linked')['version'];
check($pdo->query('SELECT * FROM b2b_companies WHERE company_uuid = ' . $pdo->quote($existing))->fetch(PDO::FETCH_ASSOC) == $existingRow, '14. the existing B2B company is not edited');
check(in_array('CONFLICTING_SOURCE_DATA', array_column($get($reviewerA, $lk)['flags'], 'code'), true), 'conflicting source against the canonical company is flagged');
check(array_column($get($sensitive, $lk)['fieldChanges'], 'new', 'field')['companyId'] === $existing, 'the link itself is in the restricted history');
$lk2 = $byLine[401]['case_uuid'];
$l2 = $get($reviewerA, $lk2)['case']['version'];
$l2 = ok($call($reviewerA, 'POST', "/finance/cases/{$lk2}/claim", ['expectedVersion' => $l2]), 200, 'claim link 2')['version'];
ok($call($reviewerA, 'POST', "/finance/cases/{$lk2}/link", ['expectedVersion' => $l2, 'companyId' => $existing, 'reason' => 'Also the same synthetic customer']), 200, 'linked 2');
check(in_array('MULTIPLE_RECORDS_SAME_COMPANY', array_column($get($reviewerA, $lk2)['flags'], 'code'), true), 'several historical records on one company are flagged');
check($get($reviewerA, $lk2)['comparison']['relationship']['otherCases'] === 1 && $get($reviewerA, $lk2)['comparison']['relationship']['movements'] === null, 'B2B relationship shown; ledger facts stay behind the account permission');

// ---- Issue #19 groundwork: nobody can make a colour or limit effective by default ----------------------------------------
$target = $approved['companyId'];
$assessment = ok($call($analyst, 'POST', '/finance/risk', ['companyId' => $target, 'classification' => 'YELLOW', 'reason' => 'Synthetic non-effective proposal']), 201, 'a proposal is not effective')['assessmentId'];
check(ok($call($analyst, 'GET', "/finance/risk/companies/{$target}"), 200, 'risk view')['classification'] === 'UNVERIFIED', 'still UNVERIFIED');
err($call($financeAdmin, 'POST', "/finance/risk/{$assessment}/approve", ['expectedVersion' => 1]), 403, 'RISK_LIMIT_AUTHORITY_REQUIRED', 'finance.risk.approve alone cannot change a colour');
$rootAttempt = $call($root, 'POST', "/finance/risk/{$assessment}/approve", ['expectedVersion' => 1]);
check($rootAttempt['status'] === 403 && in_array($rootAttempt['body']['error']['code'] ?? '', ['RISK_LIMIT_AUTHORITY_REQUIRED', 'APPLICATION_ACCESS_DENIED'], true), 'root cannot change a colour either ' . json_encode($rootAttempt['body']));
err($call($financeAdmin, 'POST', "/finance/risk/{$assessment}/approve", ['expectedVersion' => 1], [], null, $live), 403, 'RISK_LIMIT_AUTHORITY_REQUIRED', 'a configured allowlist still excludes everyone else');
$unknown = '99999999-9999-4999-8999-999999999999';
foreach (['approve', 'suspend', 'reinstate', 'revoke'] as $decision) err($call($financeAdmin, 'POST', "/finance/credit/{$unknown}/{$decision}", ['expectedVersion' => 1, 'reason' => 'Synthetic attempt']), 403, 'RISK_LIMIT_AUTHORITY_REQUIRED', "credit {$decision} refused before anything is read");
check((int) $scalar('SELECT COUNT(*) FROM finance_risk_assessments WHERE active_company_uuid IS NOT NULL') === 0 && ok($call($financeAdmin, 'GET', '/finance/access'), 200, 'access')['riskLimitAuthority'] === false, 'no effective colour exists');
check(ok($call($authorityPerson, 'GET', '/finance/access', null, [], null, $live), 200, 'authority access')['riskLimitAuthority'] === true, 'the provisioned identity is recognised by UUID');
err($call($authorityPerson, 'POST', "/finance/risk/{$assessment}/approve", ['expectedVersion' => 1]), 403, 'RISK_LIMIT_AUTHORITY_REQUIRED', 'empty allowlist denies even that identity');
ok($call($authorityPerson, 'POST', "/finance/risk/{$assessment}/approve", ['expectedVersion' => 1], [], null, $live), 200, 'only the provisioned UUID passes');
try { new Config(...array_replace($args, ['financeRiskLimitAuthorityUuids' => 'Mesut Yeman'])); check(false, 'names refused'); } catch (RuntimeException) { check(true, 'an allowlist entry must be a UUID, never a name'); }

// ---- Issue #18: opening balance double counting ---------------------------------------------------------------------------
$tz = new DateTimeZone('Europe/Bucharest');
$day = static fn (int $offset): string => (new DateTimeImmutable('now', $tz))->modify("{$offset} days")->format('Y-m-d');
$cutoff = $day(-2);
$counter = 5000000;
$verified = static function (string $name) use ($call, $get, $reviewerA, $verifier, $complete, $pdo, &$counter): string {
    $counter += 7919;
    $id = ok($call($reviewerA, 'POST', '/finance/cases', ['legalName' => $name, 'countryCode' => 'RO', 'taxIdentifier' => cui((string) $counter), 'addressLine' => 'Str. L 1', 'city' => 'Sibiu']), 201, 'case ' . $name)['caseId'];
    $v = $get($reviewerA, $id)['case']['version'];
    $v = ok($call($reviewerA, 'POST', "/finance/cases/{$id}/claim", ['expectedVersion' => $v]), 200, 'claim ' . $name)['version'];
    $v = $complete($reviewerA, $id, $v);
    $v = ok($call($reviewerA, 'POST', "/finance/cases/{$id}/submit", ['expectedVersion' => $v]), 200, 'submit ' . $name)['version'];
    $company = ok($call($verifier, 'POST', "/finance/cases/{$id}/approve", ['expectedVersion' => $v]), 200, 'approve ' . $name)['companyId'];
    // B2B activation is a separate human decision; the disposable test database simulates it so debit history can exist.
    $pdo->prepare("UPDATE b2b_companies SET status = 'active' WHERE company_uuid = ?")->execute([$company]);
    return $company;
};
$open = static fn (string $company): string => ok($call($acc1, 'POST', '/finance/accounts', ['companyId' => $company]), 201, 'open account')['onboardingId'];
$adjust = static fn (string $company, string $direction, string $amount, string $date, string $currency = 'RON'): string => ok($asRoot('POST', "/b2b/accounts/{$company}/adjustments", ['currencyCode' => $currency, 'direction' => $direction, 'amount' => $amount, 'valueDate' => $date, 'reason' => 'Synthetic historical movement']), 201, 'adjustment')['movementId'];
$pay = static fn (string $company, string $amount, string $date, string $currency = 'RON'): string => ok($asRoot('POST', "/b2b/accounts/{$company}/payments", ['currencyCode' => $currency, 'amount' => $amount, 'valueDate' => $date, 'method' => 'cash', 'externalReference' => null, 'note' => null, 'allocations' => []]), 201, 'payment')['movementId'];
$balance = static fn (string $company, string $currency = 'RON'): string => (string) $scalar("SELECT COALESCE(SUM(CASE direction WHEN 'debit' THEN amount ELSE -amount END), 0) FROM b2b_account_movements WHERE company_uuid = ? AND currency_code = ?", [$company, $currency]);
$ledgerEvidence = [['kind' => 'saga_ledger', 'sourceSystem' => 'SAGA_EXPORT', 'reference' => 'Synthetic partner statement', 'documentDate' => $cutoff]];
$draft = static fn (string $id, int $version, string $kind, string $amount, ?array $ev = null) => $call($acc1, 'POST', "/finance/accounts/{$id}/draft", ['expectedVersion' => $version, 'balanceKind' => $kind, 'amount' => $amount, 'asOfDate' => $cutoff, 'note' => 'Synthetic opening statement', 'evidence' => $ev ?? $ledgerEvidence]);
$line = static fn (string $movement, string $disposition, array $extra = []): array => ['movementId' => $movement, 'disposition' => $disposition, 'sourceReference' => 'Synthetic statement line'] + $extra;
$overlap = static fn (string $id, int $version, array $lines, array $extra = [], ?array $who = null) => $call($who ?? $acc1, 'POST', "/finance/accounts/{$id}/overlap", ['expectedVersion' => $version, 'lines' => $lines, 'note' => 'Synthetic reconciliation'] + $extra);
$finish = static function (string $id, int $version) use ($call, $acc1, $acc2, $live): array {
    $v = ok($call($acc1, 'POST', "/finance/accounts/{$id}/submit", ['expectedVersion' => $version]), 200, 'submit')['version'];
    $v = ok($call($acc2, 'POST', "/finance/accounts/{$id}/verify", ['expectedVersion' => $v]), 200, 'verify')['version'];
    return ok($call($acc2, 'POST', "/finance/accounts/{$id}/post", ['expectedVersion' => $v], [], null, $live), 200, 'post');
};

// The reported scenario: 1,000 RON already in the ledger, an opening of 1,000 RON that includes it.
$c1 = $verified('Overlap One SRL');
$m1 = $adjust($c1, 'debit', '1000.00', $day(-5));
$o1 = $open($c1);
$d1 = ok($draft($o1, 1, 'DEBIT', '1000.00'), 200, 'drafted');
check($d1['overlapResolution'] === 'reconciliation_required', 'a pre-cut-off movement demands reconciliation');
err($call($acc1, 'POST', "/finance/accounts/{$o1}/submit", ['expectedVersion' => $d1['version']]), 409, 'OVERLAP_RECONCILIATION_REQUIRED', 'submission fails closed without reconciliation');
$detailO = ok($call($acc1, 'GET', "/finance/accounts/{$o1}"), 200, 'detail');
check($detailO['overlap']['state'] === 'required' && $detailO['overlap']['lines'][0]['movementId'] === $m1 && in_array('reconcile_overlap', $detailO['actions'], true) && !in_array('submit', $detailO['actions'], true), 'the screen offers the reconciliation, not submission');
err($overlap($o1, $d1['version'], [], [], $acc2), 403, 'UNAUTHORIZED_ACTION', '16. a verifier cannot reconcile');
err($overlap($o1, $d1['version'], []), 409, 'OVERLAP_LINES_INCOMPLETE', 'every movement needs a decision');
err($overlap($o1, $d1['version'], [$line($m1, 'EXCLUDED')]), 422, 'VALIDATION_FAILED', 'an exclusion needs a written explanation');
err($overlap($o1, $d1['version'], [$line($unknown, 'INCLUDED')]), 409, 'OVERLAP_UNKNOWN_MOVEMENT', 'unknown movement refused');
err($overlap($o1, $d1['version'], [['movementId' => $m1, 'disposition' => 'INCLUDED']]), 422, 'VALIDATION_FAILED', 'free text without a source reference is refused');
$r1 = ok($overlap($o1, $d1['version'], [$line($m1, 'INCLUDED')]), 200, 'reconciled');
check($r1['grossSigned'] === '1000.00' && $r1['includedSigned'] === '1000.00' && $r1['incrementalSigned'] === '0.00', 'only the independently justified increment remains: 0.00');
$v = ok($call($acc1, 'POST', "/finance/accounts/{$o1}/submit", ['expectedVersion' => $r1['version']]), 200, 'submit')['version'];
err($call($acc1, 'POST', "/finance/accounts/{$o1}/verify", ['expectedVersion' => $v]), 403, 'UNAUTHORIZED_ACTION', 'proposer cannot verify');
$v = ok($call($acc2, 'POST', "/finance/accounts/{$o1}/verify", ['expectedVersion' => $v]), 200, 'verified')['version'];
err($call($acc2, 'POST', "/finance/accounts/{$o1}/post", ['expectedVersion' => $v]), 409, 'FINANCE_POSTING_DISABLED', '17. the switch is off by default, even for an authorized employee');
err($call($acc1, 'POST', "/finance/accounts/{$o1}/post", ['expectedVersion' => $v], [], null, $live), 403, 'UNAUTHORIZED_ACTION', '16. no post permission, no posting');
err($call($analyst, 'POST', "/finance/accounts/{$o1}/post", ['expectedVersion' => $v], [], null, $live), 403, 'UNAUTHORIZED_ACTION', '16. an unrelated employee cannot post');
$key = 'fin-test-post-' . $suffix;
$p1 = ok($call($acc2, 'POST', "/finance/accounts/{$o1}/post", ['expectedVersion' => $v], [], $key, $live), 200, 'post with the switch on');
check($p1['movementId'] === null && $p1['satisfiedByLedger'] === true && $p1['incrementalSigned'] === '0.00', 'nothing is posted');
check($balance($c1) === '1000.00', '15. the total stays 1,000 RON, never 2,000 RON');
check(ok($call($acc2, 'POST', "/finance/accounts/{$o1}/post", ['expectedVersion' => $v], [], $key, $live), 200, 'replay')['replayed'] === true && $balance($c1) === '1000.00', 'a repeated Idempotency-Key changes nothing');
check((int) $scalar("SELECT COUNT(*) FROM b2b_account_movements WHERE company_uuid = ? AND movement_type = 'opening_balance'", [$c1]) === 0, 'no opening movement exists');
check($rows('SELECT disposition, included_amount, source_reference, movement_code FROM finance_opening_reconciliation_lines WHERE movement_uuid = ?', [$m1])[0]['source_reference'] === 'Synthetic statement line', 'the source reference is preserved per line');

// Partly represented: 1,500 stated, 1,000 of it already booked.
$c2 = $verified('Overlap Two SRL');
$m2 = $adjust($c2, 'debit', '1000.00', $day(-5));
$o2 = $open($c2);
$d2 = ok($draft($o2, 1, 'DEBIT', '1500.00'), 200, 'drafted 2');
$r2 = ok($overlap($o2, $d2['version'], [$line($m2, 'INCLUDED')]), 200, 'reconciled 2');
check($r2['incrementalSigned'] === '500.00', 'incremental 500');
$p2 = $finish($o2, $r2['version']);
$mv2 = $pdo->query('SELECT * FROM b2b_account_movements WHERE movement_uuid = ' . $pdo->quote($p2['movementId']))->fetch(PDO::FETCH_ASSOC);
$snap2 = json_decode($mv2['source_snapshot'], true);
check($mv2['amount'] === '500.00' && $mv2['direction'] === 'debit' && $mv2['movement_type'] === 'opening_balance' && $snap2['grossOpeningSigned'] === '1500.00' && $snap2['overlapIncludedSigned'] === '1000.00', 'only the increment is posted; snapshot and overlap stay distinguishable');
check($balance($c2) === '1500.00', 'total equals the stated balance');
// Reopening reverses only the increment and preserves every original movement.
$rev2 = ok($call($acc2, 'POST', "/finance/accounts/{$o2}/reopen", ['expectedVersion' => $p2['version'], 'reason' => 'Synthetic correction']), 200, 'reopen');
check($balance($c2) === '1000.00' && $scalar('SELECT amount FROM b2b_account_movements WHERE movement_uuid = ?', [$m2]) === '1000.00' && $rev2['reversalId'] !== null, 'reversal preserved; the historical movement is untouched');

// Payment and receivable both included: net 700.
$c3 = $verified('Overlap Three SRL');
$m3a = $adjust($c3, 'debit', '1000.00', $day(-6));
$m3b = $pay($c3, '300.00', $day(-5));
$o3 = $open($c3);
$d3 = ok($draft($o3, 1, 'DEBIT', '700.00'), 200, 'drafted 3');
$r3 = ok($overlap($o3, $d3['version'], [$line($m3a, 'INCLUDED'), $line($m3b, 'INCLUDED')]), 200, 'reconciled 3');
check($r3['includedSigned'] === '700.00' && $r3['incrementalSigned'] === '0.00', 'payment is a negative inclusion');
check($finish($o3, $r3['version'])['satisfiedByLedger'] === true && $balance($c3) === '700.00', 'net receivable preserved');

// Explicit, source-backed exclusion is the only way to add on top of an existing movement.
$c4 = $verified('Overlap Four SRL');
$m4 = $adjust($c4, 'debit', '1000.00', $day(-5));
$o4 = $open($c4);
$d4 = ok($draft($o4, 1, 'DEBIT', '1000.00'), 200, 'drafted 4');
$r4 = ok($overlap($o4, $d4['version'], [$line($m4, 'EXCLUDED', ['note' => 'Statement predates this invoice'])]), 200, 'excluded');
check($r4['incrementalSigned'] === '1000.00', 'an excluded movement leaves the stated amount');
$finish($o4, $r4['version']);
check($balance($c4) === '2000.00', 'a documented exclusion is the only route to both amounts');

// Partial inclusion, and a stated balance below what the ledger holds gives a credit adjustment.
$c5 = $verified('Overlap Five SRL');
$m5 = $adjust($c5, 'debit', '1000.00', $day(-5));
$o5 = $open($c5);
$d5 = ok($draft($o5, 1, 'DEBIT', '800.00'), 200, 'drafted 5');
err($overlap($o5, $d5['version'], [$line($m5, 'PARTIAL', ['includedAmount' => '1000.00'])]), 409, 'OVERLAP_PARTIAL_EXCEEDS_MOVEMENT', 'partial must be smaller than the movement');
$r5 = ok($overlap($o5, $d5['version'], [$line($m5, 'PARTIAL', ['includedAmount' => '400.00'])]), 200, 'partial');
check($r5['incrementalSigned'] === '400.00', '800 - 400 = 400');
$c6 = $verified('Overlap Six SRL');
$m6 = $adjust($c6, 'debit', '1000.00', $day(-5));
$o6 = $open($c6);
$d6 = ok($draft($o6, 1, 'ZERO', '0'), 200, 'drafted 6');
$r6 = ok($overlap($o6, $d6['version'], [$line($m6, 'INCLUDED')]), 200, 'zero reconciled');
check($r6['incrementalSigned'] === '-1000.00', 'a verified zero that includes the movement needs a credit adjustment');
$p6 = $finish($o6, $r6['version']);
check($balance($c6) === '0.00' && $scalar('SELECT direction FROM b2b_account_movements WHERE movement_uuid = ?', [$p6['movementId']]) === 'credit', 'ledger reaches the stated zero');

// Reversal pair before the cut-off cancels out; a later reversal leaves the movement effective.
$c7 = $verified('Overlap Seven SRL');
$m7 = $adjust($c7, 'debit', '900.00', $day(-6));
ok($asRoot('POST', "/b2b/accounts/{$c7}/movements/{$m7}/reverse", ['reason' => 'Synthetic reversal', 'valueDate' => $day(-5)]), 201, 'reverse before cut-off');
$o7 = $open($c7);
$d7 = ok($draft($o7, 1, 'DEBIT', '500.00'), 200, 'drafted 7');
check($d7['overlapResolution'] === 'no_prior_movements', 'a cancelled pair needs no line decision');
check($finish($o7, $d7['version'])['movementId'] !== null && $balance($c7) === '500.00', 'reversed history is not counted');

// Other currency: never combined, separately documented, and new foreign movements reopen the question.
$c8 = $verified('Overlap Eight SRL');
$pay($c8, '50.00', $day(-5), 'EUR');
$o8 = $open($c8);
$d8 = ok($draft($o8, 1, 'DEBIT', '100.00'), 200, 'drafted 8');
err($call($acc1, 'POST', "/finance/accounts/{$o8}/submit", ['expectedVersion' => $d8['version']]), 409, 'CURRENCY_ISSUE_OPEN', 'mixed currency must be documented first');
$v8 = ok($call($acc1, 'POST', "/finance/accounts/{$o8}/currency-issue", ['expectedVersion' => $d8['version'], 'note' => 'Synthetic EUR advance kept in EUR']), 200, 'documented')['version'];
$pay($c8, '60.00', $day(-4), 'EUR');
err($call($acc1, 'POST', "/finance/accounts/{$o8}/submit", ['expectedVersion' => $v8]), 409, 'CURRENCY_ISSUE_OPEN', 'a new foreign movement is not covered by the old note');
check($balance($c8, 'EUR') === '-110.00' && $balance($c8) === '0.00', 'RON and EUR stay separate');

// Existing opening: adopted only when the reconciliation accounts for it exactly.
$c9 = $verified('Overlap Nine SRL');
$opening9 = ok($asRoot('POST', "/b2b/accounts/{$c9}/opening-balances", ['currencyCode' => 'RON', 'direction' => 'debit', 'amount' => '600.00', 'valueDate' => $day(-7), 'reason' => 'Synthetic B2B opening']), 201, 'B2B opening')['movementId'];
$m9 = $adjust($c9, 'debit', '400.00', $day(-5));
$o9 = $open($c9);
$d9 = ok($draft($o9, 1, 'DEBIT', '1200.00'), 200, 'drafted 9');
err($overlap($o9, $d9['version'], [$line($m9, 'INCLUDED')]), 409, 'LEDGER_OPENING_DIFFERS', 'no second opening next to an existing one');
$d9 = ok($draft($o9, $d9['version'], 'DEBIT', '1000.00'), 200, 'redrafted 9');
$r9 = ok($overlap($o9, $d9['version'], [$line($m9, 'INCLUDED')]), 200, 'reconciled 9');
check($r9['existingOpeningSigned'] === '600.00' && $r9['incrementalSigned'] === '0.00', 'existing opening + included movement = stated balance');
$p9 = $finish($o9, $r9['version']);
check($p9['adopted'] === true && $p9['movementId'] === $opening9 && $balance($c9) === '1000.00', 'existing opening adopted, nothing overwritten');

// Concurrent ledger writes.
$c10 = $verified('Overlap Ten SRL');
$m10 = $adjust($c10, 'debit', '1000.00', $day(-5));
$o10 = $open($c10);
$d10 = ok($draft($o10, 1, 'DEBIT', '1000.00'), 200, 'drafted 10');
$r10 = ok($overlap($o10, $d10['version'], [$line($m10, 'INCLUDED')]), 200, 'reconciled 10');
$adjust($c10, 'debit', '50.00', $day(-4));
err($call($acc1, 'POST', "/finance/accounts/{$o10}/submit", ['expectedVersion' => $r10['version']]), 409, 'OVERLAP_RECONCILIATION_STALE', 'a movement after the reconciliation invalidates it');
$c11 = $verified('Overlap Eleven SRL');
$m11 = $adjust($c11, 'debit', '1000.00', $day(-5));
$o11 = $open($c11);
$d11 = ok($draft($o11, 1, 'DEBIT', '1500.00'), 200, 'drafted 11');
$r11 = ok($overlap($o11, $d11['version'], [$line($m11, 'INCLUDED')]), 200, 'reconciled 11');
$v11 = ok($call($acc1, 'POST', "/finance/accounts/{$o11}/submit", ['expectedVersion' => $r11['version']]), 200, 'submitted 11')['version'];
$v11 = ok($call($acc2, 'POST', "/finance/accounts/{$o11}/verify", ['expectedVersion' => $v11]), 200, 'verified 11')['version'];
$adjust($c11, 'debit', '50.00', $day(-4));
err($call($acc2, 'POST', "/finance/accounts/{$o11}/post", ['expectedVersion' => $v11], [], null, $live), 409, 'LEDGER_CHANGED_SINCE_SUBMISSION', 'posting rechecks the ledger inside the transaction');
err($call($acc2, 'POST', "/finance/accounts/{$o11}/post", ['expectedVersion' => $v11 - 1], [], null, $live), 409, 'VERSION_CONFLICT', 'stale version refused');
check($balance($c11) === '1050.00', 'no partial posting');

// Redrafting drops the reconciliation; the stored V0.3 label proves nothing.
$c12 = $verified('Overlap Twelve SRL');
$m12 = $adjust($c12, 'debit', '1000.00', $day(-5));
$o12 = $open($c12);
$d12 = ok($draft($o12, 1, 'DEBIT', '1000.00'), 200, 'drafted 12');
$r12 = ok($overlap($o12, $d12['version'], [$line($m12, 'INCLUDED')]), 200, 'reconciled 12');
$d12b = ok($draft($o12, $r12['version'], 'DEBIT', '1400.00'), 200, 'redrafted 12');
err($call($acc1, 'POST', "/finance/accounts/{$o12}/submit", ['expectedVersion' => $d12b['version']]), 409, 'OVERLAP_RECONCILIATION_REQUIRED', 'a new amount needs a new reconciliation');
check((int) $scalar('SELECT COUNT(*) FROM finance_opening_reconciliations WHERE onboarding_uuid = ?', [$o12]) === 1, 'the earlier reconciliation stays on file');
$pdo->prepare("UPDATE finance_account_onboardings SET overlap_resolution = 'excluded_from_opening' WHERE onboarding_uuid = ?")->execute([$o12]);
err($call($acc1, 'POST', "/finance/accounts/{$o12}/submit", ['expectedVersion' => $d12b['version']]), 409, 'OVERLAP_RECONCILIATION_REQUIRED', "the label 'excluded_from_opening' is not accepted as proof");

// Reconciler cannot be the verifier even with both permissions.
$dual = $person('dualacc', ['finance.accounts.view', 'finance.accounts.reconcile', 'finance.accounts.propose_opening_balance', 'finance.accounts.verify_opening_balance']);
$c13 = $verified('Overlap Thirteen SRL');
$m13 = $adjust($c13, 'debit', '1000.00', $day(-5));
$o13 = $open($c13);
$d13 = ok($draft($o13, 1, 'DEBIT', '1000.00'), 200, 'drafted 13');
$r13 = ok($call($dual, 'POST', "/finance/accounts/{$o13}/overlap", ['expectedVersion' => $d13['version'], 'lines' => [$line($m13, 'INCLUDED')], 'note' => 'Synthetic reconciliation']), 200, 'dual reconciles');
$s13 = ok($call($acc1, 'POST', "/finance/accounts/{$o13}/submit", ['expectedVersion' => $r13['version']]), 200, 'other submits')['version'];
err($call($dual, 'POST', "/finance/accounts/{$o13}/verify", ['expectedVersion' => $s13]), 403, 'FOUR_EYES_REQUIRED', 'whoever reconciled cannot verify');

// ---- Invariants ----------------------------------------------------------------------------------------------------------
check($productionSnapshot() === $before, '18/19. Staff finance, production, QR and B2B orders are untouched');
// Only the role switch this test performs on one employee, and the one employee it adds later, may differ.
$known = array_flip(array_column($rolesBefore, 'employee_uuid'));
$keep = static fn (array $list): array => array_values(array_filter($list, static fn (array $r): bool => isset($known[$r['employee_uuid']]) && $r['employee_uuid'] !== $both['id']));
check($keep($rows('SELECT * FROM employee_role_assignments ORDER BY employee_uuid, role_id')) === $keep($rolesBefore), '18. Finance work changes no role assignment');
$knownApps = array_flip(array_column($applicationsBefore, 'employee_uuid'));
check(array_values(array_filter($rows('SELECT * FROM employee_application_access ORDER BY employee_uuid, application_key'), static fn (array $r): bool => isset($knownApps[$r['employee_uuid']]))) === $applicationsBefore, '18. Finance work changes no application grant');
check((int) $scalar("SELECT COUNT(*) FROM b2b_companies WHERE status = 'active' AND company_uuid <> ? AND legal_name NOT LIKE 'Overlap %'", [$existing]) === 0, 'Finance activated no company (the Overlap fixtures are activated by the test itself)');
foreach (['Kitap1', 'Arasya_Finance_1437', 'Arasya_Cari'] as $private) check(!str_contains((string) file_get_contents(__FILE__), $private . '.'), '20. no private file is referenced by this synthetic test');

$server->exec("DROP DATABASE IF EXISTS `{$db}`");
echo "PASS MySQL Finance V0.3.1 verification centre and opening-balance reconciliation ({$checks} checks).\n";
