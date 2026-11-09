<?php

declare(strict_types=1);

// Finance V0.3 against a disposable MySQL/MariaDB database with synthetic data only: migration 047 (additive, grants
// nothing), secure CSV/XLSX import (limits, encodings, workbook attacks, formula neutralization, duplicate upload,
// resumable chunks, idempotent reimport), the verification desk (state machine, assignment, checklist with evidence,
// duplicate resolution, four-eyes approval, stale versions, reopen), canonical company creation (inactive) or linking,
// RON/EUR currency policy, opening balance reconciliation (unknown is not zero, verified zero, posting switch,
// duplicate-posting protection, adoption, reversal), risk classification and credit limits (actor separation,
// stale basis, NO APPROVED LIMIT vs zero, RED, expiry, revocation), CEO exception records, Finance IAM isolation and
// the invariant that production, Staff finance and existing grants are untouched.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Finance\Account\CurrencyPolicy;
use Arasya\Operations\Finance\Import\ImportNormalizer;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$db = T::requireTestDatabase();
if ($db === null) {
    echo "SKIP MySQL Finance onboarding: ARASYA_TEST_DB_NAME not configured.\n";
    exit;
}
$checks = 0;
function check(bool $value, string $message): void { global $checks; $checks++; if (!$value) throw new RuntimeException('FAIL ' . $message); }
function ok(array $r, int $status, string $message): array { check($r['status'] === $status, $message . ' got ' . $r['status'] . ' ' . json_encode($r['body'])); return $r['body'] ?? []; }
function err(array $r, int $status, string $code, string $message): void { ok($r, $status, $message); check(($r['body']['error']['code'] ?? null) === $code, $message . ' code ' . json_encode($r['body'])); }
function cui(string $base): string { $body = str_pad($base, 9, '0', STR_PAD_LEFT); $key = '753217532'; $sum = 0; for ($i = 0; $i < 9; $i++) { $sum += (int) $body[$i] * (int) $key[$i]; } $c = ($sum * 10) % 11; return $base . ($c === 10 ? 0 : $c); }

// A dedicated, freshly created database: the suite counts rows and must not see what earlier CI suites left behind.
$db = substr($db, 0, 40) . '_finance_v03';
$server = new PDO('mysql:host=' . (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('ARASYA_TEST_DB_PORT') ?: '3306'), (string) getenv('ARASYA_TEST_DB_USER'), (string) getenv('ARASYA_TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `{$db}`");
$server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$config = T::config($db);
$pdo = Connection::create($config);
$dir = dirname(__DIR__) . '/database/migrations';
(new MigrationRunner($pdo))->migrate($dir);
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') as $file) (new SqlFileRunner($pdo))->run($file);

// ---- 047: additive, re-runnable, grants nothing ---------------------------------------------------------------
$grants = $pdo->query('SELECT * FROM role_permissions ORDER BY role_id, permission_id')->fetchAll();
$appGrants = $pdo->query('SELECT * FROM employee_application_access ORDER BY employee_uuid, application_key')->fetchAll();
(new SqlFileRunner($pdo))->run($dir . '/047_finance_company_onboarding.sql');
check($pdo->query('SELECT * FROM role_permissions ORDER BY role_id, permission_id')->fetchAll() === $grants, '047 re-run grants no role anything');
check($pdo->query('SELECT * FROM employee_application_access ORDER BY employee_uuid, application_key')->fetchAll() === $appGrants, '047 grants no application access');
check((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration_name = '047_finance_company_onboarding.sql'")->fetchColumn() === 1, '047 recorded once');
check((int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key LIKE 'finance.%' AND role_grantable = 1")->fetchColumn() === 19, '19 role-grantable Finance permissions (2 from 046, 17 from 047)');
check((int) $pdo->query("SELECT role_grantable FROM permissions WHERE permission_key = 'finance.access'")->fetchColumn() === 0, 'finance.access stays an application grant');
check((int) $pdo->query("SELECT COUNT(*) FROM finance_risk_policies")->fetchColumn() === 0, 'no risk threshold is seeded');
check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'finance\\_%' AND TABLE_NAME NOT IN ('finance_verification_requests', 'finance_verification_request_events') AND (DELETE_RULE NOT IN ('RESTRICT', 'NO ACTION') OR UPDATE_RULE NOT IN ('RESTRICT', 'NO ACTION'))")->fetchColumn() === 0, 'no cascading Finance foreign keys');

// ---- Currency policy (owner decision) -----------------------------------------------------------------------
check(CurrencyPolicy::forCountry('RO') === 'RON', 'Romania -> RON');
foreach (['DE', 'FR', 'IT', 'TR', 'BG', 'GB', 'US', 'HU', 'MD'] as $country) check(CurrencyPolicy::forCountry($country) === 'EUR', "{$country} -> EUR");
try { CurrencyPolicy::forCountry(null); check(false, 'missing country refused'); } catch (\Arasya\Operations\Http\ApiException $e) { check($e->errorCode === 'VERIFIED_COUNTRY_REQUIRED', 'missing verified country gives no currency'); }
check(ImportNormalizer::amount('1.234,56') === 123456 && ImportNormalizer::amount('1,234.56') === 123456 && ImportNormalizer::amount('-12') === -1200 && ImportNormalizer::amount('abc') === null, 'decimal parsing is exact');
check(ImportNormalizer::validRomanianCui(cui('1854729')) && !ImportNormalizer::validRomanianCui('18547291'), 'Romanian CUI control digit (format only, never "verified")');
check(ImportNormalizer::nameKey('S.C. Alfa Textil S.R.L.') === 'ALFA TEXTIL' && ImportNormalizer::nameKey('ALFA TEXTIL SRL') === 'ALFA TEXTIL', 'legal-form insensitive name key');

// ---- Identities -----------------------------------------------------------------------------------------------
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
$role = static function (string $name, array $permissions) use ($call, $root, $suffix): int {
    return (int) ok($call($root, 'POST', '/management/roles', ['name' => "{$name} {$suffix}", 'description' => null, 'authorityRank' => 200, 'permissions' => $permissions]), 201, "role {$name}")['role']['id'];
};
$password = 'finance test passphrase 2026';
$admin = $base->employeeAdmin();
$person = static function (string $name, array $applications, array $permissions) use ($admin, $call, $root, $password, $suffix, $kernel, $role): array {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test');
    ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications]), 200, "{$name} applications");
    if ($permissions !== []) ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => [$role($name, $permissions)]]), 200, "{$name} roles");
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['id' => $employee->employeeUuid];
};
$importer = $person('importer', ['finance'], ['finance.companies.view', 'finance.companies.import']);
$reviewerA = $person('reviewera', ['finance'], ['finance.companies.view', 'finance.companies.review', 'finance.companies.resolve_duplicates']);
$reviewerB = $person('reviewerb', ['finance'], ['finance.companies.view', 'finance.companies.review']);
$verifier = $person('verifier', ['finance'], ['finance.companies.view', 'finance.companies.verify']);
$both = $person('both', ['finance'], ['finance.companies.view', 'finance.companies.review', 'finance.companies.verify']);
$acc1 = $person('accone', ['finance'], ['finance.accounts.view', 'finance.accounts.reconcile', 'finance.accounts.propose_opening_balance']);
$acc2 = $person('acctwo', ['finance'], ['finance.accounts.view', 'finance.accounts.reconcile', 'finance.accounts.verify_opening_balance', 'finance.accounts.post_approved_opening_balance']);
$analyst = $person('analyst', ['finance'], ['finance.risk.view', 'finance.risk.propose', 'finance.credit.view', 'finance.credit.propose']);
$riskApprover = $person('riskapp', ['finance'], ['finance.risk.view', 'finance.risk.approve', 'finance.credit.view', 'finance.credit.verify']);
$creditApprover = $person('creditapp', ['finance'], ['finance.credit.view', 'finance.credit.approve', 'finance.credit.revoke']);
$auditor = $person('auditor', ['finance'], ['finance.audit.view', 'finance.companies.view']);
$ceo = $person('ceo', ['finance'], []);
$noGrant = $person('nogrant', ['finance'], []);
$staffOnly = $person('staffonly', ['staff'], ['finance.companies.view', 'finance.companies.verify']);
$b2bOnly = $person('b2bonly', ['b2b'], ['b2b.companies.view', 'b2b.accounts.view', 'b2b.accounts.adjust']);

// A second kernel with posting switched on and the CEO approver configured (same database, same sessions).
$args = [];
foreach ((new ReflectionClass(Config::class))->getConstructor()->getParameters() as $parameter) {
    $name = $parameter->getName();
    $args[$name] = match ($name) { 'financeOpeningBalancePosting' => 'enabled', 'financeCeoEmployeeUuid' => $ceo['id'], default => $config->{$name} };
}
$live = (new Container(new Config(...$args), $pdo))->kernel();

$productionSnapshot = static function () use ($pdo): string {
    $out = [];
    foreach (['operational_orders', 'operational_order_items', 'order_activity_events', 'production_workflows', 'order_finance_actions', 'employee_finance_scopes', 'finance_verification_requests', 'employee_role_assignments'] as $table) {
        $rows = array_map(serialize(...), $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC));
        sort($rows, SORT_STRING);
        $out[] = $table . ':' . hash('sha256', implode("\n", $rows));
    }
    return implode('|', $out);
};
$before = $productionSnapshot();

// ---- Finance IAM isolation -------------------------------------------------------------------------------------
err(T::call($kernel, 'GET', '/finance/cases'), 401, 'SESSION_EXPIRED', 'anonymous is refused');
err($call($staffOnly, 'GET', '/finance/cases'), 403, 'APPLICATION_ACCESS_DENIED', 'Staff-only identity with Finance permissions is refused');
err($call($b2bOnly, 'GET', '/finance/b2b/accounts'), 403, 'APPLICATION_ACCESS_DENIED', 'B2B-only identity is refused');
$access = ok($call($noGrant, 'GET', '/finance/access'), 200, 'Finance gate without operation grants');
check(array_filter($access['capabilities']) === [] && $access['openingBalancePostingEnabled'] === false, 'default-deny: every capability false, posting off');
err($call($noGrant, 'GET', '/finance/cases'), 403, 'UNAUTHORIZED_ACTION', 'Finance app alone reads nothing');
err($call($noGrant, 'POST', '/finance/cases', ['legalName' => 'X']), 403, 'UNAUTHORIZED_ACTION', 'Finance app alone writes nothing');
$r = $call($reviewerA, 'GET', '/finance/cases');
check(($r['headers']['Cache-Control'] ?? '') === 'no-store, private', 'financial responses are no-store');
err(T::call($kernel, 'POST', '/finance/cases', ['legalName' => 'X'], ['idempotency-key' => 'fin-test-nocsrf-0000001'], $reviewerA['cookie']), 403, 'CSRF_INVALID', 'CSRF required');
err(T::call($kernel, 'POST', '/finance/cases', ['legalName' => 'X'], ['origin' => 'https://evil.example', 'x-csrf-token' => $reviewerA['csrf'], 'idempotency-key' => 'fin-test-origin-000001'], $reviewerA['cookie']), 403, 'ORIGIN_DENIED', 'exact origin required');
err($call($reviewerA, 'PUT', '/finance/cases', ['legalName' => 'X']), 405, 'METHOD_NOT_ALLOWED', 'no PUT');

// An existing active B2B company (created through B2B by root) to test canonical matching.
$existingCui = cui('3141592');
$existing = ok(T::call($kernel, 'POST', '/b2b/companies', ['legalName' => 'Omega Distributie SRL', 'countryCode' => 'RO', 'taxIdentifier' => 'RO' . $existingCui],
    ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'fin-test-b2b-' . $suffix . '-0001'], $root['cookie']), 201, 'existing B2B company')['companyId'];
$companiesBefore = (int) $pdo->query('SELECT COUNT(*) FROM b2b_companies')->fetchColumn();

// ---- Import: CSV -----------------------------------------------------------------------------------------------
$alfaCui = cui('1854729');
$betaVat = 'DE123456789';
$csv = "Denumire;CUI;Tara;Adresa;Localitate;Telefon;Email;Sold;Moneda;Cod client\n"
    . "Alfa Textil SRL;RO {$alfaCui};Romania;Str. Test 1;Cluj-Napoca;0721 000 001;alfa@example.test;1.234,56;RON;C001\n"
    . "Beta Heimtex GmbH;{$betaVat};Germania;Teststr. 2;Berlin;+49 30 000000;;;EUR;C002\n"
    . "ALFA TEXTIL S.R.L.;{$alfaCui};RO;Str. Test 1;Cluj;;;;;C003\n"
    . "Gamma Ev Tekstil;;Turcia;Test Cad. 3;Istanbul;;;;;C004\n"
    . ";12345;RO;;;;;;;C005\n"
    . "\"=HYPERLINK(\"\"http://example.invalid\"\")\";;RO;;;;;;;C006\n"
    . "Omega Distributie;{$existingCui};RO;Str. Omega 7;Iasi;;;;;C007\n";
$upload = static fn (array $who, string $body, array $query = [], string $type = 'text/csv', ?string $key = null): array => T::call($kernel, 'POST', '/finance/imports', null,
    ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? ('fin-test-up-' . bin2hex(random_bytes(10))), 'content-type' => $type], $who['cookie'], $query, $body);
err($upload($reviewerB, $csv), 403, 'UNAUTHORIZED_ACTION', 'review permission cannot import');
err($upload($importer, ''), 422, 'FILE_EMPTY', 'empty file refused');
err($upload($importer, "Denumire\n"), 422, 'FILE_EMPTY', 'header without rows refused');
err($upload($importer, "Denumire;CUI\nCaf\xE9 Test;1\n"), 422, 'INVALID_ENCODING', 'invalid UTF-8 refused');
err($upload($importer, "PK\x03\x04garbage"), 422, 'FILE_TYPE_MISMATCH', 'a ZIP is not a CSV');
err($upload($importer, str_repeat('a', 4_194_305)), 413, 'FILE_TOO_LARGE', 'size limit enforced');
err($upload($importer, $csv, [], 'application/pdf'), 415, 'UNSUPPORTED_MEDIA_TYPE', 'only CSV and XLSX');
$win = ok($upload($importer, "Denumire;CUI;Tara\nTest \xBAirag SRL;1;RO\n", ['encoding' => 'windows-1250']), 201, 'windows-1250 accepted');
$batch = ok($upload($importer, $csv, ['source' => 'SAGA_EXPORT', 'name' => '../../clienti saga.csv']), 201, 'CSV uploaded')['batchId'];
err($upload($importer, $csv, ['source' => 'SAGA_EXPORT']), 409, 'DUPLICATE_UPLOAD', 'same file cannot be imported twice');
$detail = ok($call($importer, 'GET', "/finance/imports/{$batch}"), 200, 'import detail');
check($detail['fileName'] === 'clienti saga.csv' && $detail['source'] === 'SAGA_EXPORT' && $detail['rowCount'] === 7 && $detail['status'] === 'uploaded', 'sanitized name, provenance and row count');
$suggested = (array) $detail['suggestedMapping'];
check(($suggested[0] ?? null) === 'legalName' && ($suggested[1] ?? null) === 'taxIdentifier' && ($suggested[2] ?? null) === 'countryCode' && ($suggested[7] ?? null) === 'historicalDebit' && ($suggested[9] ?? null) === 'legacyCustomerCode', 'Romanian headers are recognized');
err($call($importer, 'POST', "/finance/imports/{$batch}/analyze", null), 409, 'IMPORT_NOT_MAPPED', 'analysis needs a mapping');
err($call($importer, 'POST', "/finance/imports/{$batch}/mapping", ['expectedVersion' => 1, 'mapping' => ['0' => 'legalName', '1' => 'legalName']]), 422, 'VALIDATION_FAILED', 'a field mapped twice');
err($call($importer, 'POST', "/finance/imports/{$batch}/mapping", ['expectedVersion' => 1, 'mapping' => ['1' => 'taxIdentifier']]), 422, 'VALIDATION_FAILED', 'company name mapping required');
ok($call($importer, 'POST', "/finance/imports/{$batch}/mapping", ['expectedVersion' => 1, 'mapping' => $suggested]), 200, 'mapping saved');
$analyze = static function (string $batchId) use ($call, $importer): int {
    for ($i = 0; $i < 200; $i++) {
        $r = ok($call($importer, 'POST', "/finance/imports/{$batchId}/analyze", null), 200, 'analysis chunk');
        if ($r['done']) return $r['version'];
    }
    throw new RuntimeException('analysis did not finish');
};
$version = $analyze($batch);
$rows = ok($call($importer, 'GET', "/finance/imports/{$batch}/rows"), 200, 'dry-run preview')['items'];
$outcomes = array_column($rows, 'outcome', 'line');
check($outcomes === [1 => 'ready', 2 => 'ready', 3 => 'duplicate_in_file', 4 => 'incomplete', 5 => 'rejected', 6 => 'incomplete', 7 => 'ready'], 'row outcomes ' . json_encode($outcomes));
check($rows[6]['duplicateStrength'] === 'exact' && $rows[0]['duplicateStrength'] === 'none', 'existing company detected as exact match');
check($rows[0]['values']['historicalDebit'] === '1234.56' && $rows[0]['values']['taxNormalized'] === $alfaCui && $rows[1]['values']['countryCode'] === 'DE', 'normalized values');
check(($rows[3]['issues']['taxIdentifier'] ?? null) === 'missing', 'missing tax identifier is an issue, not a failure');
$report = T::call($kernel, 'GET', "/finance/imports/{$batch}/rejections.csv", null, [], $importer['cookie']);
check($report['status'] === 200 && str_contains($report['raw'], "'=HYPERLINK") && !preg_match('/(^|,)"?=/m', $report['raw']), 'rejection report neutralizes formulas');
check((int) $pdo->query('SELECT COUNT(*) FROM finance_company_cases')->fetchColumn() === 0, 'analysis stages nothing');
err($call($importer, 'POST', "/finance/imports/{$batch}/confirm", ['expectedVersion' => $version]), 422, 'VALIDATION_FAILED', 'explicit confirmation required');
err($call($importer, 'POST', "/finance/imports/{$batch}/confirm", ['expectedVersion' => $version - 1, 'confirm' => true]), 409, 'IMPORT_CHANGED', 'confirmation of a stale preview refused');
$confirmKey = 'fin-test-confirm-' . $suffix . '-1';
$confirmed = ok($call($importer, 'POST', "/finance/imports/{$batch}/confirm", ['expectedVersion' => $version, 'confirm' => true], [], $confirmKey), 200, 'confirmed');
check($confirmed['done'] === true && $confirmed['staged'] === 5, 'five rows staged');
$replay = ok($call($importer, 'POST', "/finance/imports/{$batch}/confirm", ['expectedVersion' => $version, 'confirm' => true], [], $confirmKey), 200, 'confirm replay');
check($replay['replayed'] === true && (int) $pdo->query('SELECT COUNT(*) FROM finance_company_cases')->fetchColumn() === 5, 'retry never stages twice');
err($call($importer, 'POST', "/finance/imports/{$batch}/cancel", ['expectedVersion' => $confirmed['version'], 'reason' => 'too late']), 409, 'IMPORT_NOT_CANCELLABLE', 'completed import cannot be cancelled');
$case = static function (int $line) use ($pdo, $batch): array {
    $s = $pdo->prepare('SELECT c.* FROM finance_company_cases c JOIN finance_import_rows r ON r.row_uuid = c.import_row_uuid WHERE r.batch_uuid = ? AND r.line_no = ?');
    $s->execute([$batch, $line]);
    return $s->fetch(PDO::FETCH_ASSOC);
};
check($case(1)['status'] === 'NEEDS_REVIEW' && $case(4)['status'] === 'NEEDS_INFORMATION' && $case(7)['status'] === 'SUSPECTED_DUPLICATE', 'triage by completeness and duplicates');
check($case(1)['declared_debit'] === '1234.56' && $case(1)['declared_currency'] === 'RON' && $case(1)['source_system'] === 'SAGA_EXPORT', 'declared amount kept as source data');
check((int) $pdo->query('SELECT COUNT(*) FROM b2b_account_movements')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM finance_credit_limits')->fetchColumn() === 0, 'import never books balances or limits');
check((int) $pdo->query('SELECT COUNT(*) FROM b2b_companies')->fetchColumn() === $companiesBefore, 'import never creates companies');

// Idempotent reimport: the same normalized record from a different file is "known", only the new row is staged.
$batch2 = ok($upload($importer, "Denumire;CUI;Tara;Adresa;Localitate;Telefon;Email;Sold;Moneda;Cod client\n"
    . "Alfa Textil SRL;RO {$alfaCui};Romania;Str. Test 1;Cluj-Napoca;0721 000 001;alfa@example.test;1.234,56;RON;C001\n"
    . 'Delta Prod SRL;' . cui('2718281') . ";RO;Str. Delta 4;Brasov;;;;;C008\n", ['source' => 'SAGA_EXPORT']), 201, 'second file')['batchId'];
ok($call($importer, 'POST', "/finance/imports/{$batch2}/mapping", ['expectedVersion' => 1, 'mapping' => $suggested]), 200, 'second mapping');
$v2 = $analyze($batch2);
check(array_column(ok($call($importer, 'GET', "/finance/imports/{$batch2}/rows"), 200, 'preview 2')['items'], 'outcome') === ['known', 'ready'], 'reimport marks the known record');
check(ok($call($importer, 'POST', "/finance/imports/{$batch2}/confirm", ['expectedVersion' => $v2, 'confirm' => true]), 200, 'confirm 2')['staged'] === 1, 'only the new record staged');
// Cancel before staging frees the file for a new upload.
$cancelCsv = "Denumire;Tara\nEpsilon SRL;RO\n";
$batch3 = ok($upload($importer, $cancelCsv), 201, 'third file')['batchId'];
ok($call($importer, 'POST', "/finance/imports/{$batch3}/cancel", ['expectedVersion' => 1, 'reason' => 'wrong export']), 200, 'cancelled');
ok($upload($importer, $cancelCsv), 201, 'cancelled file can be uploaded again');
ok($call($importer, 'POST', "/finance/imports/{$win['batchId']}/cancel", ['expectedVersion' => 1, 'reason' => 'encoding test only']), 200, 'cancel encoding test');

// ---- Import: XLSX and workbook attacks -------------------------------------------------------------------------
$xlsx = static function (array $extra = [], ?string $sheet = null): string {
    $path = tempnam(sys_get_temp_dir(), 'fxl');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Clienti" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Denumire</t></si><si><t>Tara</t></si><si><t>Zeta Perdele SRL</t></si></sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet ?? '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="inlineStr"><is><t>RO</t></is></c><c r="C2"><f>1+1</f><v>2</v></c></row></sheetData></worksheet>');
    foreach ($extra as $name => $content) {
        if (str_starts_with($content, 'FILE:')) $zip->addFile(substr($content, 5), $name); else $zip->addFromString($name, $content);
    }
    $zip->close();
    $body = (string) file_get_contents($path);
    unlink($path);
    return $body;
};
$xtype = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
$xBatch = ok($upload($importer, $xlsx(), ['name' => 'clienti.xlsx'], $xtype), 201, 'XLSX uploaded')['batchId'];
$xDetail = ok($call($importer, 'GET', "/finance/imports/{$xBatch}"), 200, 'XLSX detail');
check($xDetail['format'] === 'xlsx' && $xDetail['headers'] === ['Denumire', 'Tara'] && $xDetail['sample'][0] === ['Zeta Perdele SRL', 'RO', '2'], 'XLSX cells read, formula never evaluated (cached value only)');
err($upload($importer, "PK\x03\x04" . random_bytes(200), [], $xtype), 422, 'MALFORMED_WORKBOOK', 'malformed workbook refused');
err($upload($importer, $xlsx(['xl/vbaProject.bin' => 'macro']), [], $xtype), 422, 'EMBEDDED_CONTENT', 'macro workbook refused');
err($upload($importer, $xlsx(['xl/embeddings/oleObject1.bin' => 'x']), [], $xtype), 422, 'EMBEDDED_CONTENT', 'embedded objects refused');
err($upload($importer, $xlsx([], '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa">]><worksheet><sheetData><row><c><v>&a;</v></c></row></sheetData></worksheet>'), [], $xtype), 422, 'MALFORMED_WORKBOOK', 'DOCTYPE/entities refused');
$bombFile = tempnam(sys_get_temp_dir(), 'fxb');
$handle = fopen($bombFile, 'wb');
for ($i = 0; $i < 20; $i++) fwrite($handle, str_repeat('A', 1_000_000));
fclose($handle);
err($upload($importer, $xlsx(['xl/bomb.xml' => 'FILE:' . $bombFile]), [], $xtype), 422, 'ARCHIVE_TOO_LARGE', 'ZIP bomb (expansion ratio) refused');
unlink($bombFile);
ok($call($importer, 'POST', "/finance/imports/{$xBatch}/cancel", ['expectedVersion' => 1, 'reason' => 'xlsx test only']), 200, 'cancel xlsx');

// ---- Verification desk -----------------------------------------------------------------------------------------
$summary = ok($call($reviewerA, 'GET', '/finance/overview'), 200, 'overview');
check($summary['cases'] === 6 && $summary['suspectedDuplicates'] === 1 && $summary['missingInformation'] === 2 && $summary['accounts'] === null, 'real counts; account counts hidden without account permission');
check(count(ok($call($reviewerA, 'GET', '/finance/cases', null, ['missing' => 'tax']), 200, 'filter missing tax')['items']) === 2, 'missing-field filter');
check(count(ok($call($reviewerA, 'GET', '/finance/cases', null, ['q' => $alfaCui]), 200, 'search by tax id')['items']) === 1, 'tax identifier search');
$page = ok($call($reviewerA, 'GET', '/finance/cases', null, ['limit' => '25']), 200, 'queue');
check($page['nextCursor'] === null && count($page['items']) === 6, 'keyset page');
$c1 = $case(1)['case_uuid'];
$get = fn (array $who, string $id) => ok($call($who, 'GET', "/finance/cases/{$id}"), 200, 'case detail');
$d = $get($reviewerA, $c1);
check($d['actions'] === ['claim'] && $d['source']['line'] === 1 && $d['source']['cells'][0] === 'Alfa Textil SRL', 'source row and permitted actions');
check(!in_array('approve', $get($verifier, $c1)['actions'], true), 'no approve before submission');
$v = $d['case']['version'];
err($call($reviewerA, 'POST', "/finance/cases/{$c1}/approve", ['expectedVersion' => $v]), 403, 'UNAUTHORIZED_ACTION', 'reviewer cannot approve');
err($call($verifier, 'POST', "/finance/cases/{$c1}/approve", ['expectedVersion' => $v]), 409, 'INVALID_TRANSITION', 'NEEDS_REVIEW -> APPROVED impossible');
err($call($reviewerA, 'POST', "/finance/cases/{$c1}/claim", ['expectedVersion' => $v + 5]), 409, 'CASE_CHANGED', 'stale claim refused');
ok($call($reviewerA, 'POST', "/finance/cases/{$c1}/claim", ['expectedVersion' => $v]), 200, 'reviewer A claims');
err($call($reviewerB, 'POST', "/finance/cases/{$c1}/claim", ['expectedVersion' => $v + 1]), 409, 'CASE_ASSIGNED', 'second reviewer cannot take it');
err($call($reviewerB, 'POST', "/finance/cases/{$c1}/update", ['expectedVersion' => $v + 1, 'fields' => ['phone' => '0721 999 999']]), 409, 'CASE_NOT_ASSIGNED_TO_YOU', 'only the assignee edits');
$v = ok($call($reviewerA, 'POST', "/finance/cases/{$c1}/update", ['expectedVersion' => $v + 1, 'fields' => ['phone' => '0721 999 999', 'contactName' => 'Ana Test']]), 200, 'draft saved')['version'];
$d = $get($reviewerA, $c1);
check($d['case']['fields']['phone'] === '0721 999 999' && ((array) $d['case']['fieldMeta'])['phone']['by'] === 'Test reviewera', 'field-level last editor');
err($call($reviewerA, 'POST', "/finance/cases/{$c1}/update", ['expectedVersion' => $v, 'fields' => ['countryCode' => 'XX']]), 422, 'VALIDATION_FAILED', 'invalid country refused');
err($call($reviewerA, 'POST', "/finance/cases/{$c1}/submit", ['expectedVersion' => $v]), 409, 'CHECKLIST_INCOMPLETE', 'checklist required');
err($call($reviewerA, 'POST', "/finance/cases/{$c1}/checklist", ['expectedVersion' => $v, 'item' => 'tax_id', 'state' => 'done']), 422, 'VALIDATION_FAILED', 'tax verification needs evidence');
err($call($reviewerA, 'POST', "/finance/cases/{$c1}/checklist", ['expectedVersion' => $v, 'item' => 'contact', 'state' => 'na']), 422, 'VALIDATION_FAILED', 'not applicable needs a note');
$evidence = [['kind' => 'registry_extract', 'sourceSystem' => 'REGISTRY', 'reference' => 'Synthetic registry extract TEST-001', 'documentDate' => '2026-11-01']];
$complete = static function (array $who, string $id, int $version, bool $taxVerified = true) use ($call, $evidence): int {
    foreach (['legal_name', 'country', 'tax_id', 'duplicate_search', 'address', 'contact', 'sources', 'documents', 'conflicts'] as $item) {
        $body = ['expectedVersion' => $version, 'item' => $item, 'state' => $item === 'tax_id' && !$taxVerified ? 'pending' : 'done'];
        if ($item === 'tax_id' && $taxVerified) $body['evidence'] = $evidence;
        $version = ok($call($who, 'POST', "/finance/cases/{$id}/checklist", $body), 200, "checklist {$item}")['version'];
    }
    return $version;
};
$v = $complete($reviewerA, $c1, $v);
check($pdo->query("SELECT tax_status FROM finance_company_cases WHERE case_uuid = " . $pdo->quote($c1))->fetchColumn() === 'verified', 'tax verified only with evidence');
$submitted = ok($call($reviewerA, 'POST', "/finance/cases/{$c1}/submit", ['expectedVersion' => $v]), 200, 'submitted')['version'];
err($call($verifier, 'POST', "/finance/cases/{$c1}/approve", ['expectedVersion' => $submitted - 1]), 409, 'CASE_CHANGED', 'approval of a stale version refused');
$approveKey = 'fin-test-approve-' . $suffix;
$approved = ok($call($verifier, 'POST', "/finance/cases/{$c1}/approve", ['expectedVersion' => $submitted], [], $approveKey), 200, 'second person approves');
check($approved['canonical'] === 'created_inactive', 'verified tax id creates a canonical company');
$alfa = $approved['companyId'];
$company = $pdo->query('SELECT * FROM b2b_companies WHERE company_uuid = ' . $pdo->quote($alfa))->fetch(PDO::FETCH_ASSOC);
check($company['status'] === 'inactive' && $company['tax_identifier_normalized'] === $alfaCui && $company['country_code'] === 'RO', 'canonical company is inactive (B2B ordering unaffected)');
check((int) $pdo->query("SELECT COUNT(*) FROM b2b_company_activity_events WHERE company_uuid = " . $pdo->quote($alfa) . " AND action = 'company_created'")->fetchColumn() === 1, 'B2B creation audited');
check(ok($call($verifier, 'POST', "/finance/cases/{$c1}/approve", ['expectedVersion' => $submitted], [], $approveKey), 200, 'approve replay')['replayed'] === true
    && (int) $pdo->query('SELECT COUNT(*) FROM b2b_companies')->fetchColumn() === $companiesBefore + 1, 'replay creates no second company');
err($call($verifier, 'POST', "/finance/cases/{$c1}/approve", ['expectedVersion' => $submitted], [], $approveKey . 'x'), 409, 'CASE_CHANGED', 'a second approval is refused');
$decisions = $pdo->query("SELECT decision FROM finance_case_decisions WHERE case_uuid = " . $pdo->quote($c1) . ' ORDER BY decided_at')->fetchAll(PDO::FETCH_COLUMN);
check($decisions === ['submitted', 'approved'], 'immutable decision trail');

// Four-eyes: a person with review and verify who edited the case cannot approve it.
$cM = ok($call($both, 'POST', '/finance/cases', ['legalName' => 'Kappa Stofe SRL', 'countryCode' => 'RO', 'taxIdentifier' => cui('1414213'), 'addressLine' => 'Str. K 1', 'city' => 'Arad']), 201, 'manual case')['caseId'];
$v = $get($both, $cM)['case']['version'];
ok($call($both, 'POST', "/finance/cases/{$cM}/claim", ['expectedVersion' => $v]), 200, 'both claims');
$v = $complete($both, $cM, $v + 1);
$v = ok($call($both, 'POST', "/finance/cases/{$cM}/submit", ['expectedVersion' => $v]), 200, 'both submits')['version'];
err($call($both, 'POST', "/finance/cases/{$cM}/approve", ['expectedVersion' => $v]), 403, 'FOUR_EYES_REQUIRED', 'submitter cannot be final verifier');
check(!in_array('approve', $get($both, $cM)['actions'], true), 'approve not offered to the submitter');
err($call($verifier, 'POST', "/finance/cases/{$cM}/return", ['expectedVersion' => $v, 'reason' => '']), 422, 'VALIDATION_FAILED', 'return needs a reason');
$v = ok($call($verifier, 'POST', "/finance/cases/{$cM}/return", ['expectedVersion' => $v, 'reason' => 'Registry extract date unclear']), 200, 'verifier returns')['version'];
check($get($verifier, $cM)['case']['status'] === 'NEEDS_INFORMATION', 'returned for information');

// Duplicates: the row matching the existing B2B company is linked, never merged; similar names with different ids kept separate.
$c7 = $case(7)['case_uuid'];
$d7 = $get($reviewerA, $c7);
$candidate = $d7['duplicates'][0];
check($candidate['strength'] === 'exact' && $candidate['match']['kind'] === 'company' && $candidate['match']['id'] === $existing && in_array('tax_id', $candidate['indicators'], true), 'exact candidate with comparison data');
$v = $d7['case']['version'];
ok($call($reviewerA, 'POST', "/finance/cases/{$c7}/claim", ['expectedVersion' => $v]), 200, 'claim duplicate case');
err($call($reviewerA, 'POST', "/finance/cases/{$c7}/checklist", ['expectedVersion' => $v + 1, 'item' => 'duplicate_search', 'state' => 'done']), 409, 'DUPLICATES_UNRESOLVED', 'duplicate search needs a decision');
err($call($reviewerA, 'POST', "/finance/cases/{$c7}/duplicates/{$candidate['id']}/resolve", ['expectedVersion' => $v + 1, 'decision' => 'dismissed', 'reason' => 'looks different']), 409, 'DECISION_NOT_ALLOWED', 'exact match cannot be dismissed');
err($call($reviewerB, 'POST', "/finance/cases/{$c7}/duplicates/{$candidate['id']}/resolve", ['expectedVersion' => $v + 1, 'decision' => 'linked', 'reason' => 'same CUI']), 403, 'UNAUTHORIZED_ACTION', 'duplicate resolution needs its permission');
$v = ok($call($reviewerA, 'POST', "/finance/cases/{$c7}/duplicates/{$candidate['id']}/resolve", ['expectedVersion' => $v + 1, 'decision' => 'linked', 'reason' => 'Same CUI as the B2B company']), 200, 'linked')['version'];
$v = $complete($reviewerA, $c7, $v);
$v = ok($call($reviewerA, 'POST', "/finance/cases/{$c7}/submit", ['expectedVersion' => $v]), 200, 'submit linked')['version'];
$linked = ok($call($verifier, 'POST', "/finance/cases/{$c7}/approve", ['expectedVersion' => $v]), 200, 'approve linked');
check($linked['canonical'] === 'linked' && $linked['companyId'] === $existing, 'linked to the existing canonical company');
$omega = $existing;
check((int) $pdo->query('SELECT COUNT(*) FROM b2b_companies')->fetchColumn() === $companiesBefore + 1, 'linking creates no company');
check((int) $pdo->query('SELECT version FROM b2b_companies WHERE company_uuid = ' . $pdo->quote($existing))->fetchColumn() === 1, 'the existing B2B company is never edited');

$delta = $pdo->query("SELECT case_uuid FROM finance_company_cases WHERE legal_name = 'Delta Prod SRL'")->fetchColumn();
$cD2 = ok($call($reviewerB, 'POST', '/finance/cases', ['legalName' => 'DELTA PROD S.R.L.', 'countryCode' => 'RO', 'taxIdentifier' => cui('1732050')]), 201, 'similar name, other CUI');
check($cD2['caseStatus'] === 'SUSPECTED_DUPLICATE', 'same normalized name in the same country is a strong candidate');
$dD2 = $get($reviewerA, $cD2['caseId']);
check($dD2['duplicates'][0]['strength'] === 'strong' && $dD2['duplicates'][0]['match']['id'] === $delta && !in_array('tax_id', $dD2['duplicates'][0]['indicators'], true), 'strong, not exact: different tax ids');
ok($call($reviewerA, 'POST', "/finance/cases/{$cD2['caseId']}/claim", ['expectedVersion' => $dD2['case']['version']]), 200, 'claim similar');
ok($call($reviewerA, 'POST', "/finance/cases/{$cD2['caseId']}/duplicates/{$dD2['duplicates'][0]['id']}/resolve", ['expectedVersion' => $dD2['case']['version'] + 1, 'decision' => 'kept_separate', 'reason' => 'Different CUI, different registry entries', 'evidence' => $evidence]), 200, 'kept separate with evidence');
check($pdo->query("SELECT duplicate_review FROM finance_company_cases WHERE case_uuid = " . $pdo->quote($cD2['caseId']))->fetchColumn() === 'resolved', 'duplicate review resolved');

// Reject, reopen, staging prospect.
$c4 = $case(4)['case_uuid'];
err($call($reviewerA, 'POST', "/finance/cases/{$c4}/reject", ['expectedVersion' => (int) $case(4)['version'], 'reason' => 'Not a customer']), 403, 'UNAUTHORIZED_ACTION', 'reviewer cannot reject');
ok($call($verifier, 'POST', "/finance/cases/{$c4}/reject", ['expectedVersion' => (int) $case(4)['version'], 'reason' => 'Not a customer, test row']), 200, 'verifier rejects');
$rv = (int) $pdo->query('SELECT version FROM finance_company_cases WHERE case_uuid = ' . $pdo->quote($c1))->fetchColumn();
ok($call($verifier, 'POST', "/finance/cases/{$c1}/reopen", ['expectedVersion' => $rv, 'reason' => 'Address changed after registry update']), 200, 'reopened');
check($get($verifier, $c1)['case']['status'] === 'REOPENED' && $get($verifier, $c1)['case']['approvedBy'] === null, 'earlier approval no longer counts');
ok($call($reviewerA, 'POST', "/finance/cases/{$c1}/claim", ['expectedVersion' => $rv + 1]), 200, 'reclaim after reopen');
ok($call($reviewerA, 'POST', "/finance/cases/{$c1}/submit", ['expectedVersion' => $rv + 2]), 200, 'resubmit');
$again = ok($call($verifier, 'POST', "/finance/cases/{$c1}/approve", ['expectedVersion' => $rv + 3]), 200, 'reapprove');
check($again['canonical'] === 'linked' && $again['companyId'] === $alfa, 'reapproval keeps the same canonical company');
$cP = ok($call($reviewerB, 'POST', '/finance/cases', ['legalName' => 'Lambda Interiors Ltd', 'countryCode' => 'GB', 'taxIdentifier' => 'GB123456789']), 201, 'prospect case')['caseId'];
$pv = $get($reviewerB, $cP)['case']['version'];
ok($call($reviewerB, 'POST', "/finance/cases/{$cP}/claim", ['expectedVersion' => $pv]), 200, 'claim prospect');
$pv = $complete($reviewerB, $cP, $pv + 1, false);
$pv = ok($call($reviewerB, 'POST', "/finance/cases/{$cP}/submit", ['expectedVersion' => $pv]), 200, 'submit prospect')['version'];
$prospect = ok($call($verifier, 'POST', "/finance/cases/{$cP}/approve", ['expectedVersion' => $pv]), 200, 'approve prospect');
check($prospect['canonical'] === 'staging_prospect' && $prospect['companyId'] === null, 'unverified tax id stays a prospect, no fabricated company');

// Existing B2B companies enter the desk without a duplicate case.
$sweep = ok($call($reviewerA, 'POST', '/finance/cases/from-b2b', ['limit' => 50]), 200, 'B2B sweep');
check($sweep['created'] === 0 && $sweep['remaining'] === 0, 'companies already covered by a case are skipped');

// Beta (DE) through verification for the EUR path.
$cB = $case(2)['case_uuid'];
$bv = (int) $case(2)['version'];
ok($call($reviewerA, 'POST', "/finance/cases/{$cB}/claim", ['expectedVersion' => $bv]), 200, 'claim beta');
$bv = $complete($reviewerA, $cB, $bv + 1);
$bv = ok($call($reviewerA, 'POST', "/finance/cases/{$cB}/submit", ['expectedVersion' => $bv]), 200, 'submit beta')['version'];
$beta = ok($call($verifier, 'POST', "/finance/cases/{$cB}/approve", ['expectedVersion' => $bv]), 200, 'approve beta')['companyId'];

// ---- Current-account onboarding ---------------------------------------------------------------------------------
$asRoot = static fn (string $method, string $path, array $json): array => T::call($kernel, $method, $path, $json, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'fin-test-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$sigma = ok($asRoot('POST', '/b2b/companies', ['legalName' => 'Sigma Test SRL', 'countryCode' => 'RO', 'taxIdentifier' => cui('1123581')]), 201, 'unverified B2B company')['companyId'];
err($call($acc1, 'POST', '/finance/accounts', ['companyId' => $sigma]), 409, 'COMPANY_NOT_VERIFIED', 'onboarding needs a verified identity');
err($call($reviewerA, 'POST', '/finance/accounts', ['companyId' => $alfa]), 403, 'UNAUTHORIZED_ACTION', 'reviewers cannot open accounts');
$oA = ok($call($acc1, 'POST', '/finance/accounts', ['companyId' => $alfa]), 201, 'open RO account');
check($oA['accountCurrency'] === 'RON', 'Romanian company -> RON account');
err($call($acc1, 'POST', '/finance/accounts', ['companyId' => $alfa]), 409, 'ONBOARDING_EXISTS', 'one onboarding per company');
$oB = ok($call($acc1, 'POST', '/finance/accounts', ['companyId' => $beta]), 201, 'open DE account');
check($oB['accountCurrency'] === 'EUR', 'German company -> EUR account');
$profile = ok($call($creditApprover, 'GET', "/finance/companies/{$alfa}/credit-profile"), 200, 'credit profile');
check($profile['reconciliationStatus'] === 'NO_INFORMATION' && $profile['currentExposure'] === null && $profile['creditLimitStatus'] === 'NO_APPROVED_LIMIT'
    && $profile['approvedCreditLimit'] === null && $profile['availableCredit'] === null && $profile['riskColor'] === 'UNVERIFIED' && $profile['verificationStatus'] === 'VERIFIED', 'unknown is never zero; no limit is not a zero limit');
$oaId = $oA['onboardingId'];
$od = ok($call($acc1, 'GET', "/finance/accounts/{$oaId}"), 200, 'onboarding detail');
check($od['onboarding']['status'] === 'NO_INFORMATION' && $od['onboarding']['amount'] === null && $od['declared'][0]['debit'] === '1234.56' && $od['declared'][0]['currencyMatches'] === true, 'import declaration shown as a reference only');
check(in_array('draft', $od['actions'], true) && !in_array('post', $od['actions'], true) && $od['postingEnabled'] === false, 'actions and posting switch');
$tz = new DateTimeZone('Europe/Bucharest');
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$yesterday = (new DateTimeImmutable('now', $tz))->modify('-1 day')->format('Y-m-d');
$tomorrow = (new DateTimeImmutable('now', $tz))->modify('+1 day')->format('Y-m-d');
$ledgerEvidence = [['kind' => 'saga_ledger', 'sourceSystem' => 'SAGA_EXPORT', 'reference' => 'Synthetic partner ledger TEST-77', 'documentDate' => $yesterday, 'amount' => '1500.00', 'currency' => 'RON']];
$draft = static fn (array $who, string $id, int $version, string $kind, string $amount, string $asOf, array $evidence = []) => $call($who, 'POST', "/finance/accounts/{$id}/draft",
    ['expectedVersion' => $version, 'balanceKind' => $kind, 'amount' => $amount, 'asOfDate' => $asOf, 'note' => 'Synthetic opening balance for tests', 'evidence' => $evidence]);
err($draft($acc1, $oaId, 1, 'ZERO', '5.00', $yesterday), 422, 'VALIDATION_FAILED', 'ZERO must be zero');
err($draft($acc1, $oaId, 1, 'DEBIT', '0', $yesterday), 422, 'VALIDATION_FAILED', 'a debit must be positive');
err($draft($acc1, $oaId, 1, 'DEBIT', '1500.001', $yesterday), 422, 'VALIDATION_FAILED', 'exact cents only');
err($draft($acc1, $oaId, 1, 'DEBIT', '1500.00', $tomorrow), 422, 'VALIDATION_FAILED', 'no future cut-off');
err($draft($acc2, $oaId, 1, 'DEBIT', '1500.00', $yesterday), 403, 'UNAUTHORIZED_ACTION', 'verifier cannot draft');
err($call($acc1, 'POST', "/finance/accounts/{$oaId}/submit", ['expectedVersion' => 1]), 409, 'ONBOARDING_NOT_DRAFTED', 'nothing to submit');
$d1 = ok($draft($acc1, $oaId, 1, 'DEBIT', '1500.00', $yesterday, $ledgerEvidence), 200, 'opening drafted');
check($d1['overlapResolution'] === 'no_prior_movements', 'no ledger movement before the cut-off');
$v = ok($call($acc1, 'POST', "/finance/accounts/{$oaId}/submit", ['expectedVersion' => $d1['version']]), 200, 'opening submitted')['version'];
err($call($acc1, 'POST', "/finance/accounts/{$oaId}/verify", ['expectedVersion' => $v]), 403, 'UNAUTHORIZED_ACTION', 'proposer has no verify permission');
ok($asRoot('POST', "/b2b/accounts/{$alfa}/payments", ['currencyCode' => 'RON', 'amount' => '100.00', 'valueDate' => $today, 'method' => 'cash', 'externalReference' => null, 'note' => null, 'allocations' => []]), 201, 'a B2B payment arrives meanwhile');
err($call($acc2, 'POST', "/finance/accounts/{$oaId}/verify", ['expectedVersion' => $v]), 409, 'LEDGER_CHANGED_SINCE_SUBMISSION', 'verification of a stale ledger view refused');
$v = ok($call($acc2, 'POST', "/finance/accounts/{$oaId}/return", ['expectedVersion' => $v, 'reason' => 'Ledger changed, review again']), 200, 'returned')['version'];
$v = ok($draft($acc1, $oaId, $v, 'DEBIT', '1500.00', $yesterday), 200, 'redrafted')['version'];
$v = ok($call($acc1, 'POST', "/finance/accounts/{$oaId}/submit", ['expectedVersion' => $v]), 200, 'resubmitted')['version'];
$v = ok($call($acc2, 'POST', "/finance/accounts/{$oaId}/verify", ['expectedVersion' => $v]), 200, 'second person verifies')['version'];
check($pdo->query('SELECT status FROM finance_account_onboardings WHERE onboarding_uuid = ' . $pdo->quote($oaId))->fetchColumn() === 'VERIFIED', 'verified, not yet posted');
check((int) $pdo->query("SELECT COUNT(*) FROM b2b_account_movements WHERE movement_type = 'opening_balance'")->fetchColumn() === 0, 'verification posts nothing');
err($call($acc2, 'POST', "/finance/accounts/{$oaId}/post", ['expectedVersion' => $v]), 409, 'FINANCE_POSTING_DISABLED', 'posting is off by default');
err($call($acc1, 'POST', "/finance/accounts/{$oaId}/post", ['expectedVersion' => $v], [], null, $live), 403, 'UNAUTHORIZED_ACTION', 'proposer has no post permission');
$postKey = 'fin-test-post-' . $suffix;
$posted = ok($call($acc2, 'POST', "/finance/accounts/{$oaId}/post", ['expectedVersion' => $v], [], $postKey, $live), 200, 'posted with the switch on');
$movement = $pdo->query('SELECT * FROM b2b_account_movements WHERE movement_uuid = ' . $pdo->quote($posted['movementId']))->fetch(PDO::FETCH_ASSOC);
check($movement['movement_type'] === 'opening_balance' && $movement['direction'] === 'debit' && $movement['amount'] === '1500.00' && $movement['currency_code'] === 'RON' && $movement['value_date'] === $yesterday
    && json_decode($movement['source_snapshot'], true)['financeOnboardingId'] === $oaId && $posted['adopted'] === false, 'one exact opening_balance movement in the existing ledger');
check((int) $pdo->query("SELECT COUNT(*) FROM b2b_account_activity_events WHERE movement_uuid = " . $pdo->quote($posted['movementId']) . " AND action = 'opening_balance_posted'")->fetchColumn() === 1, 'ledger activity recorded');
check(ok($call($acc2, 'POST', "/finance/accounts/{$oaId}/post", ['expectedVersion' => $v], [], $postKey, $live), 200, 'post replay')['movementId'] === $posted['movementId']
    && (int) $pdo->query("SELECT COUNT(*) FROM b2b_account_movements WHERE movement_type = 'opening_balance'")->fetchColumn() === 1, 'retry never posts twice');
err($call($acc2, 'POST', "/finance/accounts/{$oaId}/post", ['expectedVersion' => $v + 1], [], null, $live), 409, 'ONBOARDING_NOT_VERIFIED', 'a reconciled account cannot be posted again');
err($call($acc2, 'POST', "/finance/accounts/{$oaId}/post", ['expectedVersion' => $v + 1, 'x' => 1], [], null, $live), 400, 'INVALID_REQUEST', 'unknown field refused');
$profile = ok($call($creditApprover, 'GET', "/finance/companies/{$alfa}/credit-profile"), 200, 'profile after posting');
check($profile['reconciliationStatus'] === 'RECONCILED' && $profile['currentExposure'] === '1400.00' && $profile['accountCurrency'] === 'RON', 'exposure from the ledger in the account currency');
err($call($acc1, 'POST', "/finance/accounts/{$oaId}/reopen", ['expectedVersion' => $v + 1, 'reason' => 'Correct the amount']), 403, 'UNAUTHORIZED_ACTION', 'reversing a posted opening needs the post permission');
$reopened = ok($call($acc2, 'POST', "/finance/accounts/{$oaId}/reopen", ['expectedVersion' => $v + 1, 'reason' => 'Correct the amount after review']), 200, 'reopened with reversal');
$reversal = $pdo->query('SELECT * FROM b2b_account_movements WHERE movement_uuid = ' . $pdo->quote((string) $reopened['reversalId']))->fetch(PDO::FETCH_ASSOC);
check($reversal['movement_type'] === 'reversal' && $reversal['reversed_movement_uuid'] === $posted['movementId'] && $reversal['direction'] === 'credit' && $reversal['amount'] === '1500.00', 'correction is a reversal movement');
check($pdo->query('SELECT amount FROM b2b_account_movements WHERE movement_uuid = ' . $pdo->quote($posted['movementId']))->fetchColumn() === '1500.00', 'the original movement is preserved');
$o = $pdo->query('SELECT * FROM finance_account_onboardings WHERE onboarding_uuid = ' . $pdo->quote($oaId))->fetch(PDO::FETCH_ASSOC);
check($o['status'] === 'NO_INFORMATION' && $o['amount'] === null && (int) $o['cycle_no'] === 2 && $o['posted_movement_uuid'] === null, 'new cycle, unknown again');
$v = (int) $o['version'];
$v = ok($draft($acc1, $oaId, $v, 'DEBIT', '1450.00', $yesterday, $ledgerEvidence), 200, 'corrected draft')['version'];
$v = ok($call($acc1, 'POST', "/finance/accounts/{$oaId}/submit", ['expectedVersion' => $v]), 200, 'corrected submit')['version'];
$v = ok($call($acc2, 'POST', "/finance/accounts/{$oaId}/verify", ['expectedVersion' => $v]), 200, 'corrected verify')['version'];
$v = ok($call($acc2, 'POST', "/finance/accounts/{$oaId}/post", ['expectedVersion' => $v], [], null, $live), 200, 'corrected post')['version'];
check(ok($call($creditApprover, 'GET', "/finance/companies/{$alfa}/credit-profile"), 200, 'profile corrected')['currentExposure'] === '1350.00', 'corrected exposure: 1450 - 100');

// Verified zero (EUR) posts nothing.
$obId = $oB['onboardingId'];
$zv = ok($draft($acc1, $obId, 1, 'ZERO', '0', $yesterday, [['kind' => 'correspondence', 'sourceSystem' => 'MANUAL', 'reference' => 'Synthetic balance confirmation TEST-Z']]), 200, 'zero drafted')['version'];
$zv = ok($call($acc1, 'POST', "/finance/accounts/{$obId}/submit", ['expectedVersion' => $zv]), 200, 'zero submitted')['version'];
check(ok($call($acc2, 'POST', "/finance/accounts/{$obId}/verify", ['expectedVersion' => $zv]), 200, 'zero verified')['onboardingStatus'] === 'RECONCILED', 'a verified zero is reconciled');
check((int) $pdo->query('SELECT COUNT(*) FROM b2b_account_movements WHERE company_uuid = ' . $pdo->quote($beta))->fetchColumn() === 0, 'a verified zero posts no movement');
check(ok($call($creditApprover, 'GET', "/finance/companies/{$beta}/credit-profile"), 200, 'beta profile')['currentExposure'] === '0.00', 'verified zero is a real 0.00');

// Legacy multi-currency + an opening already in the ledger (RO company with an EUR movement and a B2B-posted opening).
ok($asRoot('POST', "/b2b/accounts/{$omega}/payments", ['currencyCode' => 'EUR', 'amount' => '50.00', 'valueDate' => $yesterday, 'method' => 'cash', 'externalReference' => null, 'note' => null, 'allocations' => []]), 201, 'legacy EUR movement');
$b2bOpening = ok($asRoot('POST', "/b2b/accounts/{$omega}/opening-balances", ['currencyCode' => 'RON', 'direction' => 'debit', 'amount' => '200.00', 'valueDate' => $yesterday, 'reason' => 'B2B opening']), 201, 'B2B opening')['movementId'];
$oO = ok($call($acc1, 'POST', '/finance/accounts', ['companyId' => $omega]), 201, 'open Omega');
$ooId = $oO['onboardingId'];
$oDetail = ok($call($acc1, 'GET', "/finance/accounts/{$ooId}"), 200, 'Omega detail');
check($oO['accountCurrency'] === 'RON' && $oDetail['onboarding']['currencyIssue'] === 'open' && ((array) $oDetail['ledger']['currencies'])['EUR']['balance'] === '-50.00', 'foreign-currency history flagged, shown separately, never converted');
err($call($analyst, 'POST', '/finance/risk', ['companyId' => $omega, 'classification' => 'GREEN', 'reason' => 'Looks fine']), 409, 'GREEN_REQUIRES_RECONCILIATION', 'GREEN needs reconciliation');
err($call($analyst, 'POST', '/finance/risk', ['companyId' => $sigma, 'classification' => 'YELLOW', 'reason' => 'Unknown']), 409, 'COMPANY_NOT_VERIFIED', 'an unverified company stays UNVERIFIED');
err($draft($acc1, $ooId, 1, 'ZERO', '0', $yesterday), 409, 'LEDGER_OPENING_DIFFERS', 'a different opening already in the ledger is detected');
$ov = ok($draft($acc1, $ooId, 1, 'DEBIT', '200.00', $yesterday, [['kind' => 'other', 'sourceSystem' => 'EXISTING_B2B', 'reference' => 'B2B opening movement']]), 200, 'matching draft')['version'];
err($call($acc1, 'POST', "/finance/accounts/{$ooId}/submit", ['expectedVersion' => $ov]), 409, 'CURRENCY_ISSUE_OPEN', 'currency inconsistency must be resolved first');
$ov = ok($call($acc1, 'POST', "/finance/accounts/{$ooId}/currency-issue", ['expectedVersion' => $ov, 'note' => 'EUR advance kept in EUR; no conversion authorized']), 200, 'currency issue resolved')['version'];
$ov = ok($call($acc1, 'POST', "/finance/accounts/{$ooId}/submit", ['expectedVersion' => $ov]), 200, 'Omega submitted')['version'];
$ov = ok($call($acc2, 'POST', "/finance/accounts/{$ooId}/verify", ['expectedVersion' => $ov]), 200, 'Omega verified')['version'];
$adopt = ok($call($acc2, 'POST', "/finance/accounts/{$ooId}/post", ['expectedVersion' => $ov], [], null, $live), 200, 'identical opening adopted');
check($adopt['adopted'] === true && $adopt['movementId'] === $b2bOpening && (int) $pdo->query("SELECT COUNT(*) FROM b2b_account_movements WHERE company_uuid = " . $pdo->quote($omega) . " AND movement_type = 'opening_balance'")->fetchColumn() === 1, 'already represented debt is never posted twice');
err($call($acc2, 'POST', "/finance/accounts/{$ooId}/reopen", ['expectedVersion' => $adopt['version'], 'reason' => 'try to reverse']), 409, 'ADOPTED_OPENING', 'an opening posted in B2B is corrected in B2B');

// ---- Risk classification ----------------------------------------------------------------------------------------
$riskId = ok($call($analyst, 'POST', '/finance/risk', ['companyId' => $beta, 'classification' => 'GREEN', 'reason' => 'Reconciled, verified zero, new customer']), 201, 'GREEN proposed')['assessmentId'];
err($call($analyst, 'POST', '/finance/risk', ['companyId' => $beta, 'classification' => 'YELLOW', 'reason' => 'second']), 409, 'RISK_PROPOSAL_PENDING', 'one proposal at a time');
err($call($analyst, 'POST', "/finance/risk/{$riskId}/approve", ['expectedVersion' => 1]), 403, 'UNAUTHORIZED_ACTION', 'proposer cannot approve');
ok($call($riskApprover, 'POST', "/finance/risk/{$riskId}/approve", ['expectedVersion' => 1]), 200, 'GREEN approved');
check(ok($call($analyst, 'GET', "/finance/risk/companies/{$beta}"), 200, 'risk view')['classification'] === 'GREEN', 'classification active');
$staleId = ok($call($analyst, 'POST', '/finance/risk', ['companyId' => $alfa, 'classification' => 'YELLOW', 'reason' => 'Older receivable']), 201, 'YELLOW proposed')['assessmentId'];
ok($asRoot('POST', "/b2b/accounts/{$alfa}/payments", ['currencyCode' => 'RON', 'amount' => '10.00', 'valueDate' => $today, 'method' => 'cash', 'externalReference' => null, 'note' => null, 'allocations' => []]), 201, 'ledger changes after proposal');
err($call($riskApprover, 'POST', "/finance/risk/{$staleId}/approve", ['expectedVersion' => 1]), 409, 'RISK_BASIS_STALE', 'decision on stale facts refused');
ok($call($riskApprover, 'POST', "/finance/risk/{$staleId}/reject", ['expectedVersion' => 1, 'reason' => 'Facts changed']), 200, 'stale proposal rejected');
$alfaRisk = ok($call($analyst, 'POST', '/finance/risk', ['companyId' => $alfa, 'classification' => 'YELLOW', 'reason' => 'Older receivable, current facts']), 201, 'YELLOW again')['assessmentId'];
ok($call($riskApprover, 'POST', "/finance/risk/{$alfaRisk}/approve", ['expectedVersion' => 1]), 200, 'YELLOW approved');
err($call($analyst, 'POST', '/finance/risk/policies', ['title' => 'Draft', 'definition' => ['rules' => [['indicator' => 'shoeSize', 'operator' => '>', 'value' => '1', 'classification' => 'RED']]]]), 422, 'VALIDATION_FAILED', 'unknown indicator refused');
$policy = ok($call($analyst, 'POST', '/finance/risk/policies', ['title' => 'Synthetic draft policy', 'definition' => ['rules' => [['indicator' => 'outstanding', 'operator' => '>=', 'value' => '1000', 'classification' => 'YELLOW']]]]), 201, 'policy drafted');
check(ok($call($analyst, 'GET', "/finance/risk/companies/{$alfa}"), 200, 'view without policy')['suggestion'] === null, 'a draft policy suggests nothing');
err($call($analyst, 'POST', "/finance/risk/policies/{$policy['policyId']}/approve", ['expectedVersion' => 1]), 403, 'UNAUTHORIZED_ACTION', 'author cannot approve policy');
ok($call($riskApprover, 'POST', "/finance/risk/policies/{$policy['policyId']}/approve", ['expectedVersion' => 1]), 200, 'policy approved');
$view = ok($call($analyst, 'GET', "/finance/risk/companies/{$alfa}"), 200, 'view with policy');
check($view['suggestion']['suggested'] === 'YELLOW' && $view['classification'] === 'YELLOW', 'approved policy only suggests; the classification stays the human decision');
check((int) $pdo->query("SELECT COUNT(*) FROM finance_risk_assessments WHERE company_uuid = " . $pdo->quote($alfa))->fetchColumn() === 2, 'no automatic classification was written');

// ---- Credit limits ----------------------------------------------------------------------------------------------
$creditEvidence = [['kind' => 'contract', 'sourceSystem' => 'MANUAL', 'reference' => 'Synthetic framework contract TEST-C1']];
$propose = static fn (string $company, string $amount, string $currency, array $extra = []) => $call($analyst, 'POST', '/finance/credit', array_replace(['companyId' => $company, 'amount' => $amount, 'currency' => $currency, 'effectiveFrom' => $today, 'reason' => 'Synthetic limit proposal', 'evidence' => $creditEvidence], $extra));
err($propose($beta, '10000.00', 'RON'), 422, 'CURRENCY_MISMATCH', 'limit currency must be the account currency');
err($propose($sigma, '100.00', 'RON'), 409, 'COMPANY_NOT_VERIFIED', 'unverified companies get no limit');
$limit = ok($propose($beta, '10000.00', 'EUR'), 201, 'limit proposed')['limitId'];
err($propose($beta, '5.00', 'EUR'), 409, 'CREDIT_PROPOSAL_PENDING', 'one proposal at a time');
err($call($analyst, 'POST', "/finance/credit/{$limit}/verify", ['expectedVersion' => 1]), 403, 'UNAUTHORIZED_ACTION', 'proposer has no verify permission');
ok($call($riskApprover, 'POST', "/finance/credit/{$limit}/verify", ['expectedVersion' => 1]), 200, 'verified');
err($call($riskApprover, 'POST', "/finance/credit/{$limit}/approve", ['expectedVersion' => 2]), 403, 'UNAUTHORIZED_ACTION', 'verifier has no approve permission');
ok($call($creditApprover, 'POST', "/finance/credit/{$limit}/approve", ['expectedVersion' => 2]), 200, 'approved by a third person');
$bp = ok($call($creditApprover, 'GET', "/finance/companies/{$beta}/credit-profile"), 200, 'beta profile');
check($bp['creditLimitStatus'] === 'APPROVED' && $bp['approvedCreditLimit'] === '10000.00' && $bp['creditLimitCurrency'] === 'EUR' && $bp['availableCredit'] === '10000.00' && $bp['riskColor'] === 'GREEN' && $bp['creditReviewRequired'] === false, 'approved limit and available credit');
$zero = ok($propose($beta, '0', 'EUR'), 201, 'zero limit proposed')['limitId'];
ok($call($riskApprover, 'POST', "/finance/credit/{$zero}/verify", ['expectedVersion' => 1]), 200, 'zero verified');
ok($call($creditApprover, 'POST', "/finance/credit/{$zero}/approve", ['expectedVersion' => 2]), 200, 'zero approved');
$bp = ok($call($creditApprover, 'GET', "/finance/companies/{$beta}/credit-profile"), 200, 'zero profile');
check($bp['creditLimitStatus'] === 'APPROVED' && $bp['approvedCreditLimit'] === '0.00' && $bp['availableCredit'] === '0.00', 'an approved zero limit is explicit');
check($pdo->query('SELECT status FROM finance_credit_limits WHERE limit_uuid = ' . $pdo->quote($limit))->fetchColumn() === 'SUPERSEDED', 'previous limit superseded');
$expired = ok($propose($beta, '5000.00', 'EUR', ['effectiveFrom' => '2026-01-01', 'expiresOn' => '2026-01-31']), 201, 'expiring limit')['limitId'];
ok($call($riskApprover, 'POST', "/finance/credit/{$expired}/verify", ['expectedVersion' => 1]), 200, 'expiring verified');
ok($call($creditApprover, 'POST', "/finance/credit/{$expired}/approve", ['expectedVersion' => 2]), 200, 'expiring approved');
$bp = ok($call($creditApprover, 'GET', "/finance/companies/{$beta}/credit-profile"), 200, 'expired profile');
check($bp['creditLimitStatus'] === 'EXPIRED' && $bp['availableCredit'] === null, 'an expired limit gives no available credit');
err($call($creditApprover, 'POST', "/finance/credit/{$expired}/revoke", ['expectedVersion' => 3]), 422, 'VALIDATION_FAILED', 'revocation needs a reason');
ok($call($creditApprover, 'POST', "/finance/credit/{$expired}/revoke", ['expectedVersion' => 3, 'reason' => 'Test revocation']), 200, 'revoked');
check(ok($call($creditApprover, 'GET', "/finance/companies/{$beta}/credit-profile"), 200, 'revoked profile')['creditLimitStatus'] === 'NO_APPROVED_LIMIT', 'revoked -> NO APPROVED LIMIT');
$staleLimit = ok($propose($alfa, '3000.00', 'RON'), 201, 'Alfa limit proposed')['limitId'];
$green = ok($call($analyst, 'POST', '/finance/risk', ['companyId' => $alfa, 'classification' => 'GREEN', 'reason' => 'Paid recently']), 201, 'new risk proposal')['assessmentId'];
ok($call($riskApprover, 'POST', "/finance/risk/{$green}/approve", ['expectedVersion' => 1]), 200, 'risk changes after the limit proposal');
err($call($riskApprover, 'POST', "/finance/credit/{$staleLimit}/verify", ['expectedVersion' => 1]), 409, 'CREDIT_BASIS_STALE', 'limit on a changed risk basis refused');
$red = ok($call($analyst, 'POST', '/finance/risk', ['companyId' => $omega, 'classification' => 'RED', 'reason' => 'Serious overdue debt (synthetic)']), 201, 'RED proposed')['assessmentId'];
ok($call($riskApprover, 'POST', "/finance/risk/{$red}/approve", ['expectedVersion' => 1]), 200, 'RED approved');
err($propose($omega, '100.00', 'RON'), 409, 'RED_RISK_NO_CREDIT', 'RED gets no credit limit');

// ---- CEO exception records (no B2B enforcement) ---------------------------------------------------------------
$line = ['productCode' => 'CURTAIN', 'productName' => null, 'variant' => null, 'color' => null, 'kind' => 'curtain', 'width' => '200', 'height' => '260', 'quantity' => 1, 'meters' => '2', 'pricingUnit' => 'meter', 'unitPriceNet' => '10.00', 'discountPercent' => '0', 'vatPercent' => '19', 'notes' => null];
$order = ok($asRoot('POST', '/b2b/orders', ['companyId' => $omega, 'currencyCode' => 'RON', 'contactId' => null, 'billingAddressId' => null, 'deliveryAddressId' => null, 'notes' => null, 'lines' => [$line]]), 201, 'draft B2B order')['detail']['order']['id'];
$orderBefore = $pdo->query('SELECT * FROM b2b_orders WHERE order_uuid = ' . $pdo->quote($order))->fetch(PDO::FETCH_ASSOC);
$expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d\TH:i:s\Z');
err($call($analyst, 'POST', '/finance/credit/exceptions', ['companyId' => $beta, 'orderId' => $order, 'amount' => '500.00', 'currency' => 'EUR', 'expiresAt' => $expiresAt, 'reason' => 'x reason']), 404, 'ORDER_NOT_FOUND', 'exception bound to the company order');
err($call($analyst, 'POST', '/finance/credit/exceptions', ['companyId' => $omega, 'orderId' => $order, 'amount' => '500.00', 'currency' => 'EUR', 'expiresAt' => $expiresAt, 'reason' => 'x reason']), 422, 'CURRENCY_MISMATCH', 'exception currency must match');
$exception = ok($call($analyst, 'POST', '/finance/credit/exceptions', ['companyId' => $omega, 'orderId' => $order, 'amount' => '500.00', 'currency' => 'RON', 'expiresAt' => $expiresAt, 'reason' => 'Strategic order (synthetic)']), 201, 'exception requested');
err($call($ceo, 'POST', "/finance/credit/exceptions/{$exception['exceptionId']}/approve", ['expectedVersion' => 1, 'snapshotSha256' => $exception['snapshotSha256']]), 409, 'CEO_APPROVER_NOT_CONFIGURED', 'no CEO approver configured by default');
err($call($creditApprover, 'POST', "/finance/credit/exceptions/{$exception['exceptionId']}/approve", ['expectedVersion' => 1, 'snapshotSha256' => $exception['snapshotSha256']], [], null, $live), 403, 'CEO_APPROVAL_REQUIRED', 'no role can substitute the CEO');
err($call($ceo, 'POST', "/finance/credit/exceptions/{$exception['exceptionId']}/approve", ['expectedVersion' => 1, 'snapshotSha256' => str_repeat('0', 64)], [], null, $live), 409, 'EXCEPTION_SNAPSHOT_STALE', 'the CEO approves exactly the snapshot shown');
ok($call($ceo, 'POST', "/finance/credit/exceptions/{$exception['exceptionId']}/approve", ['expectedVersion' => 1, 'snapshotSha256' => $exception['snapshotSha256'], 'reason' => 'Approved for this order only'], [], null, $live), 200, 'CEO approves');
check($pdo->query('SELECT * FROM b2b_orders WHERE order_uuid = ' . $pdo->quote($order))->fetch(PDO::FETCH_ASSOC) == $orderBefore, 'the B2B order is not touched (no enforcement yet)');

// ---- Revocation, idempotency, audit, invariants ----------------------------------------------------------------
$reuse = 'fin-test-reuse-' . $suffix;
ok($call($reviewerB, 'POST', '/finance/cases', ['legalName' => 'Reuse One SRL'], [], $reuse), 201, 'first use of a key');
err($call($reviewerB, 'POST', '/finance/cases', ['legalName' => 'Reuse Two SRL'], [], $reuse), 409, 'IDEMPOTENCY_CONFLICT', 'a key reused for another body is refused');
ok($call($root, 'PUT', "/management/employees/{$reviewerB['id']}/applications", ['applications' => []]), 200, 'Finance access revoked');
err($call($reviewerB, 'GET', '/finance/cases'), 403, 'APPLICATION_ACCESS_DENIED', 'revocation applies on the next request');
err($call($reviewerA, 'GET', '/finance/audit'), 403, 'UNAUTHORIZED_ACTION', 'audit needs its permission');
$audit = ok($call($auditor, 'GET', '/finance/audit'), 200, 'audit trail');
$actions = array_column($audit['items'], 'action');
check(in_array('case_approved', $actions, true) && in_array('opening_posted', $actions, true) && in_array('credit_approved', $actions, true) && $audit['nextCursor'] !== null, 'audit trail covers decisions and pages');
$auditJson = json_encode($pdo->query('SELECT details_json, reason FROM finance_activity_events')->fetchAll(PDO::FETCH_ASSOC));
check(!str_contains($auditJson, 'alfa@example.test') && !str_contains($auditJson, '0721'), 'audit details hold no contact values');
check($productionSnapshot() === $before, 'production, Staff finance and role assignments untouched');
check((int) $pdo->query("SELECT COUNT(*) FROM b2b_companies WHERE status = 'active'")->fetchColumn() === 2, 'Finance activated no company (only the two B2B-created ones are active)');

$server->exec("DROP DATABASE IF EXISTS `{$db}`");
echo "PASS MySQL Finance V0.3 company onboarding, current accounts, risk and credit ({$checks} checks).\n";
