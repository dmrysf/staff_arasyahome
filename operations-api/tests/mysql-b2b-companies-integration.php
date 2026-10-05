<?php

declare(strict_types=1);

// B2B Companies V1 against a dedicated MySQL/MariaDB test database: the migration 009 upgrade path, Central IAM
// authorization (b2b.access plus one narrow company permission), company identity and server-generated codes,
// tax-identifier duplicate protection, list search/filter/keyset pagination, detail, update, deactivate and
// reactivate, contacts and addresses with safe primary rules, optimistic concurrency, idempotency (including
// simultaneous retries), immutable activity, privacy boundaries, and the invariant that no company operation
// touches production orders, Staff data or sources.

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
    fwrite(STDOUT, "SKIP MySQL B2B companies integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $message);
    }
}

/** @param array{status: int, body: array<string, mixed>|null} $response */
function checkError(array $response, int $status, string $code, string $message): void
{
    check($response['status'] === $status && ($response['body']['error']['code'] ?? null) === $code, "{$message} (got {$response['status']} " . json_encode($response['body']) . ')');
}

/** @param array{status: int, body: array<string, mixed>|null} $response @return array<string, mixed> */
function checkStatus(array $response, int $status, string $message): array
{
    check($response['status'] === $status, "{$message} (got {$response['status']} " . json_encode($response['body']) . ')');
    return $response['body'] ?? [];
}

const B2B_ORIGIN = 'http://127.0.0.1:4177';
const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';
const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN, B2B_ORIGIN]);
$pdo = Connection::create($config);
$migrations = dirname(__DIR__) . '/database/migrations';
// Reconstruct the pre-009 fixture, including when earlier CI suites applied the additive 010 tables.
// This guarded disposable database belongs to the integration suite.
(new MigrationRunner($pdo))->migrate($migrations);
foreach(['b2b_account_idempotency','b2b_account_activity_events','b2b_account_allocation_releases','b2b_account_allocations','b2b_account_movements','b2b_account_movement_sequence'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
$pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.permission_key LIKE 'b2b.accounts.%'");$pdo->exec("DELETE FROM permissions WHERE permission_key LIKE 'b2b.accounts.%'");$pdo->exec("DELETE FROM schema_migrations WHERE migration_name='011_b2b_current_account.sql'");
foreach (['b2b_order_idempotency','b2b_order_activity_events','b2b_order_lines','b2b_orders','b2b_order_number_sequence'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.permission_key LIKE 'b2b.orders.%'");
$pdo->exec("DELETE FROM permissions WHERE permission_key LIKE 'b2b.orders.%'");
$pdo->exec("DELETE FROM schema_migrations WHERE migration_name='010_b2b_orders.sql'");
$companyMigrations = sys_get_temp_dir().'/arasya-company-migrations-'.bin2hex(random_bytes(8));
mkdir($companyMigrations,0700);
foreach (glob($migrations.'/*.sql') as $file) {
    if (basename($file) < '010') copy($file,$companyMigrations.'/'.basename($file));
}
register_shutdown_function(static function() use($companyMigrations): void {
    foreach (glob($companyMigrations.'/*.sql') as $file) unlink($file);
    rmdir($companyMigrations);
});
$migrations=$companyMigrations;

// ---- Upgrade path to migration 009 ---------------------------------------------------------------
// Rebuild the pre-009 state in this test database, then apply 009 exactly once on top of it.
foreach (['b2b_company_activity_events', 'b2b_company_idempotency', 'b2b_company_contacts', 'b2b_company_addresses', 'b2b_companies', 'b2b_company_number_sequence'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec("DELETE rp FROM role_permissions rp INNER JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.permission_key LIKE 'b2b.companies.%'");
$pdo->exec("DELETE FROM permissions WHERE permission_key LIKE 'b2b.companies.%'");
$pdo->exec("DELETE FROM schema_migrations WHERE migration_name = '009_b2b_companies.sql'");
$permissionsBefore = $pdo->query('SELECT permission_key, category, role_grantable FROM permissions ORDER BY permission_key')->fetchAll();
$rolePermissionsBefore = $pdo->query('SELECT role_id, permission_id FROM role_permissions ORDER BY role_id, permission_id')->fetchAll();
$applicationsBefore = $pdo->query('SELECT application_key, name, status, access_permission_key FROM applications ORDER BY application_key')->fetchAll();
$grantsBefore = $pdo->query('SELECT employee_uuid, application_key FROM employee_application_access ORDER BY employee_uuid, application_key')->fetchAll();
$upgraded = (new MigrationRunner($pdo))->migrate($migrations);
check($upgraded === ['009_b2b_companies.sql'], 'a pre-009 database applies exactly migration 009 (got ' . implode(', ', $upgraded) . ')');
check((new MigrationRunner($pdo))->migrate($migrations) === [], 'migration 009 is recorded once');
$b2bPermissions = $pdo->query("SELECT permission_key, category, role_grantable FROM permissions WHERE permission_key LIKE 'b2b.%' ORDER BY permission_key")->fetchAll();
check($b2bPermissions == [
    ['permission_key' => 'b2b.access', 'category' => 'applications', 'role_grantable' => 0],
    ['permission_key' => 'b2b.companies.create', 'category' => 'b2b', 'role_grantable' => 1],
    ['permission_key' => 'b2b.companies.manage_status', 'category' => 'b2b', 'role_grantable' => 1],
    ['permission_key' => 'b2b.companies.update', 'category' => 'b2b', 'role_grantable' => 1],
    ['permission_key' => 'b2b.companies.view', 'category' => 'b2b', 'role_grantable' => 1],
], 'migration 009 adds exactly four narrow role-grantable company permissions; b2b.access keeps its semantics');
check($pdo->query("SELECT permission_key, category, role_grantable FROM permissions WHERE permission_key NOT LIKE 'b2b.companies.%' ORDER BY permission_key")->fetchAll() == $permissionsBefore, 'migration 009 leaves existing permissions unchanged');
check($pdo->query('SELECT role_id, permission_id FROM role_permissions ORDER BY role_id, permission_id')->fetchAll() == $rolePermissionsBefore, 'migration 009 gives the new permissions to no role');
check($pdo->query('SELECT application_key, name, status, access_permission_key FROM applications ORDER BY application_key')->fetchAll() == $applicationsBefore, 'migration 009 leaves the applications unchanged');
check($pdo->query('SELECT employee_uuid, application_key FROM employee_application_access ORDER BY employee_uuid, application_key')->fetchAll() == $grantsBefore, 'migration 009 grants no application access');
check((int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key IN ('b2b.admin', 'b2b.manage_all')")->fetchColumn() === 0, 'no broad B2B permission exists');
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'b2b%' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
check($tables === ['b2b_companies', 'b2b_company_activity_events', 'b2b_company_addresses', 'b2b_company_contacts', 'b2b_company_idempotency', 'b2b_company_number_sequence'], 'migration 009 creates only the company domain tables (got ' . implode(', ', $tables) . ')');
check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND (table_name LIKE 'b2b_order%' OR table_name LIKE '%ledger%' OR table_name LIKE '%payment%' OR table_name LIKE '%invoice%' OR table_name LIKE '%inventory%')")->fetchColumn() === 0, 'no order, ledger, payment, invoice or inventory table exists');
(new SqlFileRunner($pdo))->run($migrations . '/009_b2b_companies.sql');
check((int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key LIKE 'b2b.companies.%'")->fetchColumn() === 4, 'migration 009 is safe to run twice');

$seedFiles = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seedFiles, SORT_STRING);
foreach ($seedFiles as $seedFile) {
    (new SqlFileRunner($pdo))->run($seedFile);
}
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');

$orderStateSql = 'SELECT COUNT(*) AS orders, COALESCE(SUM(production_version), 0) AS production, COALESCE(SUM(version), 0) AS versions FROM operational_orders';
$orderStateBefore = $pdo->query($orderStateSql)->fetch();
$staffStateSql = '(SELECT COUNT(*) FROM order_activity_events) + (SELECT COUNT(*) FROM order_operation_idempotency) + (SELECT COUNT(*) FROM employee_order_relations) + (SELECT COUNT(*) FROM order_projection_receipts)';
$staffStateBefore = (int) $pdo->query("SELECT {$staffStateSql}")->fetchColumn();

$keyCounter = 0;
$newKey = static function () use (&$keyCounter, $suffix): string {
    $keyCounter++;
    return sprintf('b2b-key-%s-%06d', $suffix, $keyCounter);
};
$get = static fn (?array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => B2B_ORIGIN], $who['cookie'] ?? null, $query);
$send = static function (?array $who, string $method, string $path, ?array $json = null, ?string $key = null, array $headers = []) use ($kernel, $newKey): array {
    return T::call($kernel, $method, $path, $json, ['origin' => B2B_ORIGIN, 'x-csrf-token' => $who['csrf'] ?? null, 'idempotency-key' => $key ?? $newKey(), ...$headers], $who['cookie'] ?? null);
};
$asDashboard = static fn (array $who, string $method, string $path, ?array $json = null): array => T::call($kernel, $method, $path, $json, ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $who['csrf']], $who['cookie']);
$login = static function (string $username, string $password) use ($kernel): array {
    $response = T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => B2B_ORIGIN]);
    if ($response['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $response['headers']['Set-Cookie'] ?? '', $match)) {
        throw new RuntimeException("Login failed for {$username}: " . json_encode($response['body']));
    }
    return ['cookie' => rawurldecode($match[1]), 'csrf' => (string) $response['body']['csrfToken']];
};

// ---- Identities ------------------------------------------------------------------------------------
$rootUsername = 'arasya.root.owner.' . $suffix;
$rootTemporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-b2b-companies-root', $rootUsername);
$first = $login($rootUsername, $rootTemporary);
checkStatus(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $rootTemporary, 'newPassword' => 'Companies root passphrase 2026!'], ['origin' => B2B_ORIGIN, 'x-csrf-token' => $first['csrf']], $first['cookie']), 200, 'root changes its temporary password');
$root = $login($rootUsername, 'Companies root passphrase 2026!');
$rootId = (string) $pdo->query('SELECT employee_uuid FROM system_root_identity')->fetchColumn();

$catalog = checkStatus($asDashboard($root, 'GET', '/management/permissions'), 200, 'permission catalog');
$catalogKeys = array_column($catalog['items'], 'roleGrantable', 'key');
foreach (['b2b.companies.view', 'b2b.companies.create', 'b2b.companies.update', 'b2b.companies.manage_status'] as $permission) {
    check(($catalogKeys[$permission] ?? null) === true, "{$permission} is a role-grantable entry of the Dashboard permission catalog");
}
check(($catalogKeys['b2b.access'] ?? null) === false, 'b2b.access stays an application grant, never a role permission');

$role = static function (string $name, array $permissions) use ($asDashboard, $root, $suffix): int {
    $created = checkStatus($asDashboard($root, 'POST', '/management/roles', ['name' => "{$name} {$suffix}", 'description' => null, 'authorityRank' => 200, 'permissions' => $permissions]), 201, "role {$name}");
    return (int) $created['role']['id'];
};
$password = 'companies passphrase 2026';
$admin = $container->employeeAdmin();
$identity = static function (string $name, array $applications, array $roles) use ($admin, $asDashboard, $root, $password, $suffix, $login): array {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test');
    checkStatus($asDashboard($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications]), 200, "{$name} applications");
    if ($roles !== []) {
        checkStatus($asDashboard($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), 200, "{$name} roles");
    }
    return $login("{$name}.{$suffix}", $password) + ['id' => $employee->employeeUuid];
};
$allCompanyPermissions = ['b2b.companies.view', 'b2b.companies.create', 'b2b.companies.update', 'b2b.companies.manage_status'];
$sellerRole = $role('Vanzari B2B', $allCompanyPermissions);
$seller = $identity('seller', ['b2b'], [$sellerRole]);
$viewer = $identity('viewer', ['b2b'], [$role('Vizualizare B2B', ['b2b.companies.view'])]);
$creator = $identity('creator', ['b2b'], [$role('Creare B2B', ['b2b.companies.view', 'b2b.companies.create'])]);
$createOnly = $identity('createonly', ['b2b'], [$role('Doar creare B2B', ['b2b.companies.create'])]);
$editor = $identity('editor', ['b2b'], [$role('Editare B2B', ['b2b.companies.view', 'b2b.companies.update'])]);
$statusManager = $identity('status', ['b2b'], [$role('Status B2B', ['b2b.companies.view', 'b2b.companies.manage_status'])]);
$gateOnly = $identity('gateonly', ['b2b'], []);
$noApp = $identity('noapp', ['dashboard', 'staff'], [$sellerRole]);

// ---- Authorization ---------------------------------------------------------------------------------
checkError($get(null, '/b2b/companies'), 401, 'SESSION_EXPIRED', 'an anonymous request is refused');
checkError($send(['cookie' => null, 'csrf' => null], 'POST', '/b2b/companies', ['legalName' => 'X', 'countryCode' => 'RO', 'taxIdentifier' => '1']), 401, 'SESSION_EXPIRED', 'an anonymous mutation is refused');
checkError($get($noApp, '/b2b/companies'), 403, 'APPLICATION_ACCESS_DENIED', 'company permissions without B2B application access do nothing');
checkError($send($noApp, 'POST', '/b2b/companies', ['legalName' => 'X', 'countryCode' => 'RO', 'taxIdentifier' => '1']), 403, 'APPLICATION_ACCESS_DENIED', 'a role cannot create companies without B2B access');
checkError($get($gateOnly, '/b2b/companies'), 403, 'UNAUTHORIZED_ACTION', 'b2b.access alone does not open companies');
check(checkStatus($get($gateOnly, '/b2b/access'), 200, 'the B2B gate still opens with b2b.access alone')['permissions'] === [], 'the gate reports no company permission');
check(checkStatus($get($viewer, '/b2b/access'), 200, 'viewer gate')['permissions'] === ['b2b.companies.view'], 'the gate reports exactly the usable company permissions');
check(checkStatus($get($root, '/b2b/access'), 200, 'root gate')['permissions'] === $allCompanyPermissions, 'root holds every company permission through the existing root semantics');
$viewerList = checkStatus($get($viewer, '/b2b/companies'), 200, 'view permission lists companies');
check($viewerList['capabilities'] === ['canView' => true, 'canCreate' => false, 'canUpdate' => false, 'canManageStatus' => false], 'list capabilities mirror the permissions');
checkError($send($viewer, 'POST', '/b2b/companies', ['legalName' => 'Viewer SRL', 'countryCode' => 'RO', 'taxIdentifier' => '900001']), 403, 'UNAUTHORIZED_ACTION', 'view permission cannot create');
checkError($send($seller, 'POST', '/b2b/companies', ['legalName' => 'X', 'countryCode' => 'RO', 'taxIdentifier' => '1'], null, ['x-csrf-token' => 'forged']), 403, 'CSRF_INVALID', 'a mutation without a valid CSRF token is refused');
checkError($send($seller, 'POST', '/b2b/companies', ['legalName' => 'X', 'countryCode' => 'RO', 'taxIdentifier' => '1'], null, ['origin' => 'https://evil.example']), 403, 'ORIGIN_DENIED', 'a mutation from a foreign origin is refused');
checkError($send($seller, 'POST', '/b2b/companies', ['legalName' => 'X', 'countryCode' => 'RO', 'taxIdentifier' => '1'], 'short'), 400, 'INVALID_IDEMPOTENCY_KEY', 'a mutation without a valid Idempotency-Key is refused');
check((int) $pdo->query('SELECT COUNT(*) FROM b2b_companies')->fetchColumn() === 0, 'refused requests created nothing');

// ---- Company creation ------------------------------------------------------------------------------
$created = checkStatus($send($creator, 'POST', '/b2b/companies', ['legalName' => 'Mobila Lux SRL', 'countryCode' => 'ro', 'taxIdentifier' => 'RO 12345678']), 201, 'create permission creates a company with only the required fields');
$mobila = $created['company'];
check(preg_match(UUID_V4, $mobila['id']) === 1 && $created['companyId'] === $mobila['id'], 'the company id is a server-generated UUID');
check(preg_match('/^B2B-\d{6}$/D', $mobila['code']) === 1, 'the company code is server-generated (got ' . $mobila['code'] . ')');
check($mobila['countryCode'] === 'RO' && $mobila['taxIdentifier'] === 'RO 12345678' && $mobila['status'] === 'active' && $mobila['version'] === 1, 'identity, fiscal data, status and version are stored as normalized');
check($mobila['createdBy']['id'] === $creator['id'] && $mobila['updatedBy']['id'] === $creator['id'], 'the creating employee is recorded');
check($created['contacts'] === [] && $created['addresses'] === [], 'contact and address are optional');
check(array_keys($created) === ['companyId', 'company', 'contacts', 'addresses', 'capabilities'], 'the response has no fake orders, balances, payments or product history');
checkError($send($creator, 'POST', '/b2b/companies', ['legalName' => 'X', 'countryCode' => 'RO', 'taxIdentifier' => '777', 'code' => 'B2B-999999']), 400, 'INVALID_REQUEST', 'a client cannot choose the company code');
checkError($send($creator, 'POST', '/b2b/companies', ['legalName' => 'X', 'countryCode' => 'RO', 'taxIdentifier' => '777', 'id' => '00000000-0000-4000-8000-000000000001']), 400, 'INVALID_REQUEST', 'a client cannot choose the company id');
$invalid = $send($creator, 'POST', '/b2b/companies', ['legalName' => '', 'countryCode' => 'XX', 'website' => 'javascript:alert(1)']);
checkError($invalid, 422, 'VALIDATION_FAILED', 'invalid company input is refused');
check(($invalid['body']['error']['details']['fields'] ?? null) === ['countryCode' => 'invalid', 'legalName' => 'required', 'taxIdentifier' => 'required', 'website' => 'invalid'], 'validation names each field with a stable reason');
checkError($send($createOnly, 'POST', '/b2b/companies', ['legalName' => 'Fara tax', 'countryCode' => 'RO']), 422, 'VALIDATION_FAILED', 'the tax identifier is required');

$full = checkStatus($send($seller, 'POST', '/b2b/companies', [
    'legalName' => 'Perdele Design SRL', 'displayName' => 'Perdele Design', 'countryCode' => 'RO', 'taxIdentifier' => '23456789',
    'vatNumber' => 'RO23456789', 'registrationNumber' => 'J40/1234/2020', 'website' => 'perdele-design.ro', 'internalNotes' => 'Client recomandat de showroom.',
    'contact' => ['name' => 'Ana Pop', 'jobTitle' => 'Achiziții', 'email' => ' Ana.Pop@Perdele-Design.RO ', 'phone' => '+40 721 000 111', 'isPrimary' => true],
    'address' => ['type' => 'billing', 'label' => 'Sediu', 'countryCode' => 'RO', 'countyRegion' => 'București', 'city' => 'București', 'postalCode' => '010101', 'addressLine1' => 'Str. Exemplu 1', 'isPrimary' => true],
]), 201, 'a company is created together with a primary contact and a primary address');
$perdele = $full['company'];
check($perdele['website'] === 'https://perdele-design.ro' && $perdele['internalNotes'] === 'Client recomandat de showroom.', 'website is normalized and the internal note stored');
check(count($full['contacts']) === 1 && $full['contacts'][0]['email'] === 'ana.pop@perdele-design.ro' && $full['contacts'][0]['isPrimary'] === true && $full['contactId'] === $full['contacts'][0]['id'], 'the first contact is stored, canonical and primary');
check(count($full['addresses']) === 1 && $full['addresses'][0]['type'] === 'billing' && $full['addresses'][0]['isPrimary'] === true && $full['addressId'] === $full['addresses'][0]['id'], 'the first address is stored and primary');
check((int) substr($perdele['code'], 4) === (int) substr($mobila['code'], 4) + 1, 'company codes follow the server sequence');
$createdOnly = checkStatus($send($createOnly, 'POST', '/b2b/companies', ['legalName' => 'Creat Fara Vizualizare SRL', 'countryCode' => 'RO', 'taxIdentifier' => '34567890']), 201, 'create without view permission works');
check(array_keys($createdOnly) === ['companyId'], 'without view permission the response carries only the new id');

// ---- Duplicates ------------------------------------------------------------------------------------
$duplicate = $send($creator, 'POST', '/b2b/companies', ['legalName' => 'Alt Nume SRL', 'countryCode' => 'RO', 'taxIdentifier' => '12345678']);
checkError($duplicate, 409, 'COMPANY_TAX_ID_ALREADY_EXISTS', 'the same normalized Romanian tax identifier is refused');
check(($duplicate['body']['error']['details']['company'] ?? null) === ['id' => $mobila['id'], 'code' => $mobila['code']], 'the conflict names the existing company for a viewer');
checkError($send($creator, 'POST', '/b2b/companies', ['legalName' => 'X', 'countryCode' => 'RO', 'taxIdentifier' => 'ro-12.345.678']), 409, 'COMPANY_TAX_ID_ALREADY_EXISTS', 'formatting and the RO prefix do not hide a duplicate');
$blindDuplicate = $send($createOnly, 'POST', '/b2b/companies', ['legalName' => 'X', 'countryCode' => 'RO', 'taxIdentifier' => '12345678']);
checkError($blindDuplicate, 409, 'COMPANY_TAX_ID_ALREADY_EXISTS', 'a creator without view permission gets the stable conflict');
check(!isset($blindDuplicate['body']['error']['details']), 'but no details of the existing company');
$sameName = checkStatus($send($creator, 'POST', '/b2b/companies', ['legalName' => 'Mobila Lux SRL', 'countryCode' => 'RO', 'taxIdentifier' => '45678901']), 201, 'the same legal name with another tax identifier is allowed');
$turkish = checkStatus($send($creator, 'POST', '/b2b/companies', ['legalName' => 'Mobilya Lüks A.Ş.', 'countryCode' => 'TR', 'taxIdentifier' => '12345678']), 201, 'the same raw tax identifier in another country is allowed');
check($turkish['company']['countryCode'] === 'TR', 'the Turkish company keeps its country');
check((int) $pdo->query('SELECT COUNT(*) FROM b2b_companies')->fetchColumn() === 5, 'duplicates were not silently merged or created');

$race = static function (array $jobs) use ($dbName): array {
    $start = microtime(true) + 1.0;
    $processes = [];
    foreach ($jobs as [$who, $method, $path, $body, $key]) {
        $command = [PHP_BINARY, __DIR__ . '/fixtures/b2b-company-worker.php', $dbName, B2B_ORIGIN, $method, $path, json_encode($body, JSON_THROW_ON_ERROR), $key, $who['cookie'], $who['csrf'], (string) $start];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $processes[] = [$process, $pipes[1]];
    }
    $results = [];
    foreach ($processes as [$process, $stdout]) {
        $results[] = json_decode(trim((string) stream_get_contents($stdout)), true, 8, JSON_THROW_ON_ERROR);
        fclose($stdout);
        proc_close($process);
    }
    return $results;
};
$racing = $race([
    [$seller, 'POST', '/b2b/companies', ['legalName' => 'Cursa A SRL', 'countryCode' => 'RO', 'taxIdentifier' => '56789012'], $newKey()],
    [$creator, 'POST', '/b2b/companies', ['legalName' => 'Cursa B SRL', 'countryCode' => 'RO', 'taxIdentifier' => 'RO56789012'], $newKey()],
]);
$statuses = array_column($racing, 'status');
sort($statuses);
check($statuses === [201, 409] && in_array('COMPANY_TAX_ID_ALREADY_EXISTS', array_column($racing, 'code'), true), 'two employees creating the same fiscal identity at once produce one company: ' . json_encode($racing));
check((int) $pdo->query("SELECT COUNT(*) FROM b2b_companies WHERE tax_identifier_normalized = '56789012'")->fetchColumn() === 1, 'the race stored one company');
try {
    $pdo->prepare("INSERT INTO b2b_company_number_sequence (created_at) VALUES (UTC_TIMESTAMP(6))")->execute();
    $number = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO b2b_companies (company_uuid, company_number, company_code, legal_name, country_code, tax_identifier, tax_identifier_normalized, created_at, updated_at, created_by_employee_uuid, updated_by_employee_uuid)
        VALUES (UUID(), ?, ?, 'Direct', 'RO', '12345678', '12345678', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?, ?)")->execute([$number, 'B2B-T' . $number, $rootId, $rootId]);
    check(false, 'the database accepted a duplicate fiscal identity');
} catch (PDOException $error) {
    check((int) ($error->errorInfo[1] ?? 0) === 1062, 'the unique key refuses a duplicate fiscal identity even outside the API');
}

// ---- Idempotency on creation -----------------------------------------------------------------------
$retryKey = $newKey();
$retryBody = ['legalName' => 'Retry SRL', 'countryCode' => 'RO', 'taxIdentifier' => '67890123'];
$firstTry = checkStatus($send($seller, 'POST', '/b2b/companies', $retryBody, $retryKey), 201, 'first creation attempt');
$secondTry = checkStatus($send($seller, 'POST', '/b2b/companies', $retryBody, $retryKey), 201, 'a browser retry with the same key replays');
check($secondTry['companyId'] === $firstTry['companyId'] && $secondTry['company']['code'] === $firstTry['company']['code'], 'the retry returns the same company');
check((int) $pdo->query("SELECT COUNT(*) FROM b2b_companies WHERE tax_identifier_normalized = '67890123'")->fetchColumn() === 1, 'the retry created no duplicate');
checkError($send($seller, 'POST', '/b2b/companies', ['legalName' => 'Altceva SRL', 'countryCode' => 'RO', 'taxIdentifier' => '78901234'], $retryKey), 409, 'IDEMPOTENCY_CONFLICT', 'reusing a key for another request is refused');
$doubleKey = $newKey();
$doubleClick = $race([
    [$seller, 'POST', '/b2b/companies', ['legalName' => 'Dublu Click SRL', 'countryCode' => 'RO', 'taxIdentifier' => '89012345'], $doubleKey],
    [$seller, 'POST', '/b2b/companies', ['legalName' => 'Dublu Click SRL', 'countryCode' => 'RO', 'taxIdentifier' => '89012345'], $doubleKey],
]);
check(array_column($doubleClick, 'status') === [201, 201] && $doubleClick[0]['companyId'] === $doubleClick[1]['companyId'], 'a simultaneous double click creates one company: ' . json_encode($doubleClick));
$doubleId = (string) $doubleClick[0]['companyId'];
$eventCount = static function (string $companyId, ?string $action = null) use ($pdo): int {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM b2b_company_activity_events WHERE company_uuid = ?' . ($action === null ? '' : ' AND action = ?'));
    $statement->execute($action === null ? [$companyId] : [$companyId, $action]);
    return (int) $statement->fetchColumn();
};
check($eventCount($doubleId, 'company_created') === 1, 'a double click writes one creation event');

// ---- List, search, filters and pagination ----------------------------------------------------------
$listed = static fn (array $who, array $query = []): array => checkStatus($get($who, '/b2b/companies', $query), 200, 'list ' . json_encode($query));
$codes = static fn (array $list): array => array_column($list['items'], 'code');
$all = $listed($viewer);
check(count($all['items']) === 8 && $all['nextCursor'] === null, 'the default list shows the active companies (got ' . count($all['items']) . ')');
$names = array_column($all['items'], 'legalName');
$sorted = $names;
sort($sorted, SORT_STRING | SORT_FLAG_CASE);
check($names === $sorted, 'the list is ordered by legal name');
$perdeleRow = array_values(array_filter($all['items'], static fn (array $item): bool => $item['id'] === $perdele['id']))[0];
check($perdeleRow['city'] === 'București' && $perdeleRow['primaryContact'] === ['name' => 'Ana Pop'] && $perdeleRow['taxIdentifier'] === '23456789' && $perdeleRow['countryCode'] === 'RO' && $perdeleRow['status'] === 'active' && isset($perdeleRow['updatedAt']), 'a row shows code, name, tax id, city, country, primary contact, status and last update');
check(!isset($perdeleRow['internalNotes']) && !isset($perdeleRow['email']), 'the list carries no internal notes or contact details');
check($codes($listed($viewer, ['search' => $mobila['code']])) === [$mobila['code']], 'search by company code');
check($codes($listed($viewer, ['search' => strtolower($mobila['code'])])) === [$mobila['code']], 'search by company code ignores case');
check(in_array($mobila['code'], $codes($listed($viewer, ['search' => (string) (int) substr($mobila['code'], 4)])), true), 'search by company number');
check(count($listed($viewer, ['search' => 'mobila lux'])['items']) === 2, 'search by legal name finds both same-name companies');
check($codes($listed($viewer, ['search' => 'Perdele Design'])) === [$perdele['code']], 'search by commercial name');
check($codes($listed($viewer, ['search' => '23456789'])) === [$perdele['code']], 'search by tax identifier');
check($codes($listed($viewer, ['search' => 'RO 2345'])) === [$perdele['code']], 'search by a prefixed partial tax identifier');
check($codes($listed($viewer, ['search' => 'bucur'])) === [$perdele['code']], 'search by city');
check($listed($viewer, ['search' => '100%_'])['items'] === [], 'LIKE wildcards in the search text are literal');
check($codes($listed($viewer, ['country' => 'tr'])) === [$turkish['company']['code']], 'country filter');
checkError($get($viewer, '/b2b/companies', ['country' => 'XX']), 422, 'VALIDATION_FAILED', 'an invalid country filter is refused');
checkError($get($viewer, '/b2b/companies', ['status' => 'deleted']), 422, 'VALIDATION_FAILED', 'an invalid status filter is refused');
checkError($get($viewer, '/b2b/companies', ['cursor' => 'not-a-cursor!']), 400, 'INVALID_CURSOR', 'a forged cursor is refused');
for ($i = 1; $i <= 26; $i++) {
    checkStatus($send($seller, 'POST', '/b2b/companies', ['legalName' => sprintf('Paginare %02d SRL', $i), 'countryCode' => 'BG', 'taxIdentifier' => sprintf('BG%09d', $i)]), 201, "pagination company {$i}");
}
$page1 = $listed($viewer, ['country' => 'BG', 'limit' => '25']);
check(count($page1['items']) === 25 && is_string($page1['nextCursor']), 'keyset pagination returns a full first page and a cursor');
$page2 = $listed($viewer, ['country' => 'BG', 'limit' => '25', 'cursor' => $page1['nextCursor']]);
check(count($page2['items']) === 1 && $page2['nextCursor'] === null && $page2['items'][0]['legalName'] === 'Paginare 26 SRL', 'the second page continues without overlap');
checkError($get($viewer, '/b2b/companies', ['limit' => '1000']), 422, 'VALIDATION_FAILED', 'page size is bounded');
$explain = $pdo->query("EXPLAIN SELECT company_uuid FROM b2b_companies c WHERE c.status = 'active' ORDER BY c.legal_name, c.company_uuid LIMIT 51")->fetchAll();
check(in_array($explain[0]['key'] ?? null, ['idx_b2b_companies_status_name', 'idx_b2b_companies_name'], true) || (int) $pdo->query('SELECT COUNT(*) FROM b2b_companies')->fetchColumn() < 100, 'the default list can use the status/name index (EXPLAIN key ' . json_encode($explain[0]['key'] ?? null) . ')');

// ---- Detail ----------------------------------------------------------------------------------------
$detail = checkStatus($get($viewer, "/b2b/companies/{$perdele['id']}"), 200, 'detail');
check(array_keys($detail) === ['company', 'contacts', 'addresses', 'capabilities'], 'the detail has identity, contacts, addresses and capabilities only');
check(array_keys($detail['company']) === ['id', 'code', 'legalName', 'displayName', 'countryCode', 'taxIdentifier', 'vatNumber', 'registrationNumber', 'website', 'internalNotes', 'status', 'statusChangedAt', 'createdAt', 'updatedAt', 'createdBy', 'updatedBy', 'version'], 'the company block has the documented fields');
check($detail['company']['internalNotes'] === 'Client recomandat de showroom.' && $detail['company']['vatNumber'] === 'RO23456789' && $detail['company']['registrationNumber'] === 'J40/1234/2020', 'the detail carries fiscal data and the internal note for an authorized viewer');
checkError($get($viewer, '/b2b/companies/00000000-0000-4000-8000-000000000000'), 404, 'COMPANY_NOT_FOUND', 'an unknown company is not found');
checkError($get($viewer, '/b2b/companies/B2B-000001'), 404, 'COMPANY_NOT_FOUND', 'the public identity is the UUID, not the code');
checkError($get($gateOnly, "/b2b/companies/{$perdele['id']}"), 403, 'UNAUTHORIZED_ACTION', 'the detail needs view permission');
checkError($get($createOnly, "/b2b/companies/{$perdele['id']}"), 403, 'UNAUTHORIZED_ACTION', 'create permission alone does not reveal companies');
checkError($get($noApp, "/b2b/companies/{$perdele['id']}/activity"), 403, 'APPLICATION_ACCESS_DENIED', 'activity needs B2B application access');

// ---- Update and optimistic concurrency -------------------------------------------------------------
$companyBody = static fn (array $company, array $changes = []): array => array_merge([
    'legalName' => $company['legalName'], 'displayName' => $company['displayName'], 'countryCode' => $company['countryCode'], 'taxIdentifier' => $company['taxIdentifier'],
    'vatNumber' => $company['vatNumber'] ?? null, 'registrationNumber' => $company['registrationNumber'] ?? null, 'website' => $company['website'] ?? null,
    'internalNotes' => $company['internalNotes'] ?? null, 'expectedVersion' => $company['version'],
], $changes);
$mobilaDetail = checkStatus($get($viewer, "/b2b/companies/{$mobila['id']}"), 200, 'mobila detail')['company'];
checkError($send($creator, 'PUT', "/b2b/companies/{$mobila['id']}", $companyBody($mobilaDetail, ['displayName' => 'Mobila Lux'])), 403, 'UNAUTHORIZED_ACTION', 'create permission cannot update');
checkError($send($editor, 'PUT', "/b2b/companies/{$mobila['id']}", ['legalName' => 'Partial']), 400, 'INVALID_REQUEST', 'an update must carry every company field; there is no partial patch');
checkError($send($editor, 'PATCH', "/b2b/companies/{$mobila['id']}", ['displayName' => 'x']), 405, 'METHOD_NOT_ALLOWED', 'there is no generic PATCH');
$updated = checkStatus($send($editor, 'PUT', "/b2b/companies/{$mobila['id']}", $companyBody($mobilaDetail, ['displayName' => 'Mobila Lux', 'internalNotes' => 'Plătește la livrare.'])), 200, 'update permission edits the company');
check($updated['company']['displayName'] === 'Mobila Lux' && $updated['company']['version'] === 2 && $updated['company']['updatedBy']['id'] === $editor['id'] && $updated['company']['code'] === $mobila['code'], 'the update bumps the version, records the editor and keeps the code');
$stale = $send($seller, 'PUT', "/b2b/companies/{$mobila['id']}", $companyBody($mobilaDetail, ['legalName' => 'Overwrite SRL']));
checkError($stale, 409, 'COMPANY_CHANGED', 'a stale company version is refused');
check(checkStatus($get($viewer, "/b2b/companies/{$mobila['id']}"), 200, 'after stale')['company']['legalName'] === 'Mobila Lux SRL', 'the stale edit overwrote nothing');
$noop = checkStatus($send($editor, 'PUT', "/b2b/companies/{$mobila['id']}", $companyBody($updated['company'])), 200, 'an unchanged update succeeds');
check($noop['company']['version'] === 2 && $eventCount($mobila['id'], 'company_updated') === 1, 'an unchanged update writes no version and no event');
checkError($send($editor, 'PUT', "/b2b/companies/{$mobila['id']}", $companyBody($updated['company'], ['taxIdentifier' => '23456789'])), 409, 'COMPANY_TAX_ID_ALREADY_EXISTS', 'an update cannot take another company\'s fiscal identity');
$updateKey = $newKey();
$retried = $companyBody($updated['company'], ['website' => 'https://mobila-lux.ro']);
$updatedAgain = checkStatus($send($editor, 'PUT', "/b2b/companies/{$mobila['id']}", $retried, $updateKey), 200, 'update with a key');
$replayed = checkStatus($send($editor, 'PUT', "/b2b/companies/{$mobila['id']}", $retried, $updateKey), 200, 'a retried update replays instead of reporting a conflict');
check($replayed['company']['version'] === $updatedAgain['company']['version'] && $eventCount($mobila['id'], 'company_updated') === 2, 'the retried update wrote once');

// ---- Status ----------------------------------------------------------------------------------------
$current = checkStatus($get($viewer, "/b2b/companies/{$mobila['id']}"), 200, 'current')['company'];
checkError($send($editor, 'POST', "/b2b/companies/{$mobila['id']}/deactivate", ['expectedVersion' => $current['version']]), 403, 'UNAUTHORIZED_ACTION', 'update permission cannot deactivate');
checkError($send($statusManager, 'POST', "/b2b/companies/{$mobila['id']}/deactivate", ['expectedVersion' => 1]), 409, 'COMPANY_CHANGED', 'a stale status change is refused');
$statusKey = $newKey();
$deactivated = checkStatus($send($statusManager, 'POST', "/b2b/companies/{$mobila['id']}/deactivate", ['expectedVersion' => $current['version']], $statusKey), 200, 'status permission deactivates');
check($deactivated['company']['status'] === 'inactive' && $deactivated['company']['statusChangedAt'] !== null, 'the company is inactive');
checkStatus($send($statusManager, 'POST', "/b2b/companies/{$mobila['id']}/deactivate", ['expectedVersion' => $current['version']], $statusKey), 200, 'a retried deactivation replays');
check($eventCount($mobila['id'], 'company_deactivated') === 1, 'a retried deactivation writes one event');
checkError($send($statusManager, 'POST', "/b2b/companies/{$mobila['id']}/deactivate", ['expectedVersion' => $deactivated['company']['version']]), 409, 'STATUS_UNCHANGED', 'deactivating twice is refused');
check(!in_array($mobila['code'], $codes($listed($viewer)), true), 'an inactive company leaves the default list');
check(in_array($mobila['code'], $codes($listed($viewer, ['status' => 'inactive'])), true) && !in_array($perdele['code'], $codes($listed($viewer, ['status' => 'inactive'])), true), 'the inactive filter shows only inactive companies');
check(in_array($mobila['code'], $codes($listed($viewer, ['status' => 'all', 'search' => 'Mobila'])), true), 'the all filter includes inactive companies');
check(checkStatus($get($viewer, "/b2b/companies/{$mobila['id']}"), 200, 'inactive detail')['company']['status'] === 'inactive', 'an inactive company stays readable in history');
$reactivated = checkStatus($send($root, 'POST', "/b2b/companies/{$mobila['id']}/reactivate", ['expectedVersion' => $deactivated['company']['version']]), 200, 'root reactivates naturally');
check($reactivated['company']['status'] === 'active' && in_array($mobila['code'], $codes($listed($viewer)), true), 'the reactivated company is back in the default list');
checkError($send($root, 'DELETE', "/b2b/companies/{$mobila['id']}"), 405, 'METHOD_NOT_ALLOWED', 'there is no delete endpoint');
checkError($send($root, 'POST', "/b2b/companies/{$mobila['id']}/delete", []), 404, 'NOT_FOUND', 'there is no delete action');
check((int) $pdo->query("SELECT COUNT(*) FROM b2b_companies WHERE company_uuid = " . $pdo->quote($mobila['id']))->fetchColumn() === 1, 'the company row still exists');

// ---- Contacts --------------------------------------------------------------------------------------
$companyPath = "/b2b/companies/{$perdele['id']}";
$contactBody = static fn (array $contact, array $changes = []): array => array_merge([
    'name' => $contact['name'], 'jobTitle' => $contact['jobTitle'], 'email' => $contact['email'], 'phone' => $contact['phone'], 'isPrimary' => $contact['isPrimary'], 'expectedVersion' => $contact['version'],
], $changes);
$contactOf = static fn (array $detail, string $id): array => array_values(array_filter($detail['contacts'], static fn (array $contact): bool => $contact['id'] === $id))[0];
checkError($send($viewer, 'POST', "{$companyPath}/contacts", ['name' => 'Nu']), 403, 'UNAUTHORIZED_ACTION', 'view permission cannot add contacts');
checkError($send($editor, 'POST', "{$companyPath}/contacts", ['name' => 'Ion', 'email' => 'not-an-email']), 422, 'VALIDATION_FAILED', 'an invalid e-mail is refused');
$contactKey = $newKey();
$ion = checkStatus($send($editor, 'POST', "{$companyPath}/contacts", ['name' => 'Ion Ionescu', 'jobTitle' => 'Director', 'phone' => '0722 000 222'], $contactKey), 201, 'update permission adds a contact');
checkStatus($send($editor, 'POST', "{$companyPath}/contacts", ['name' => 'Ion Ionescu', 'jobTitle' => 'Director', 'phone' => '0722 000 222'], $contactKey), 201, 'a retried contact creation replays');
check(count(array_filter($ion['contacts'], static fn (array $c): bool => $c['name'] === 'Ion Ionescu')) === 1 && $eventCount($perdele['id'], 'contact_created') === 2, 'the retry created no duplicate contact');
$ionId = $ion['contactId'];
$anaId = $full['contactId'];
$promoted = checkStatus($send($editor, 'PUT', "{$companyPath}/contacts/{$ionId}", $contactBody($contactOf($ion, $ionId), ['isPrimary' => true])), 200, 'a second contact becomes primary');
check($contactOf($promoted, $ionId)['isPrimary'] === true && $contactOf($promoted, $anaId)['isPrimary'] === false, 'exactly one primary contact remains');
checkError($send($editor, 'PUT', "{$companyPath}/contacts/{$anaId}", $contactBody($contactOf($ion, $anaId), ['jobTitle' => 'Vechi'])), 409, 'CONTACT_CHANGED', 'a stale contact version is refused (losing primary bumped it)');
$contactRace = $race([
    [$editor, 'POST', "{$companyPath}/contacts", ['name' => 'Cursa Unu', 'isPrimary' => true], $newKey()],
    [$seller, 'POST', "{$companyPath}/contacts", ['name' => 'Cursa Doi', 'isPrimary' => true], $newKey()],
]);
check(array_column($contactRace, 'status') === [201, 201], 'two simultaneous primary contacts both save: ' . json_encode($contactRace));
$primaries = $pdo->prepare('SELECT COUNT(*) FROM b2b_company_contacts WHERE company_uuid = ? AND is_primary = 1');
$primaries->execute([$perdele['id']]);
check((int) $primaries->fetchColumn() === 1, 'racing requests still leave exactly one primary contact');
try {
    $pdo->prepare("INSERT INTO b2b_company_contacts (contact_uuid, company_uuid, full_name, is_primary, primary_company_uuid, status, created_at, updated_at, created_by_employee_uuid, updated_by_employee_uuid)
        VALUES (UUID(), ?, 'Direct', 1, ?, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?, ?)")->execute([$perdele['id'], $perdele['id'], $rootId, $rootId]);
    check(false, 'the database accepted a second primary contact');
} catch (PDOException $error) {
    check((int) ($error->errorInfo[1] ?? 0) === 1062, 'the database refuses a second primary contact even outside the API');
}
$fresh = checkStatus($get($viewer, $companyPath), 200, 'detail after race');
$primaryContact = array_values(array_filter($fresh['contacts'], static fn (array $c): bool => $c['isPrimary']))[0];
$deactivatedContact = checkStatus($send($editor, 'POST', "{$companyPath}/contacts/{$primaryContact['id']}/deactivate", ['expectedVersion' => $primaryContact['version']]), 200, 'the primary contact is deactivated');
$after = $contactOf($deactivatedContact, $primaryContact['id']);
check($after['status'] === 'inactive' && $after['isPrimary'] === false && $deactivatedContact['company']['status'] === 'active', 'a deactivated contact loses primary and the company stays valid');
checkError($send($editor, 'PUT', "{$companyPath}/contacts/{$after['id']}", $contactBody($after, ['isPrimary' => true])), 422, 'VALIDATION_FAILED', 'an inactive contact cannot become primary');
$reactivatedContact = checkStatus($send($editor, 'POST', "{$companyPath}/contacts/{$after['id']}/reactivate", ['expectedVersion' => $after['version']]), 200, 'the contact is reactivated');
check($contactOf($reactivatedContact, $after['id'])['status'] === 'active' && $contactOf($reactivatedContact, $after['id'])['isPrimary'] === false, 'reactivation does not restore primary');
checkError($send($editor, 'POST', "{$companyPath}/contacts/{$after['id']}/reactivate", ['expectedVersion' => $contactOf($reactivatedContact, $after['id'])['version']]), 409, 'STATUS_UNCHANGED', 'reactivating twice is refused');
checkError($send($editor, 'PUT', "/b2b/companies/{$mobila['id']}/contacts/{$ionId}", $contactBody($contactOf($promoted, $ionId))), 404, 'CONTACT_NOT_FOUND', 'a contact cannot be edited through another company');
checkError($send($editor, 'DELETE', "{$companyPath}/contacts/{$ionId}"), 405, 'METHOD_NOT_ALLOWED', 'contacts cannot be deleted');

// ---- Addresses -------------------------------------------------------------------------------------
$addressBody = static fn (array $address, array $changes = []): array => array_merge([
    'type' => $address['type'], 'label' => $address['label'], 'countryCode' => $address['countryCode'], 'countyRegion' => $address['countyRegion'], 'city' => $address['city'],
    'postalCode' => $address['postalCode'], 'addressLine1' => $address['addressLine1'], 'addressLine2' => $address['addressLine2'], 'isPrimary' => $address['isPrimary'], 'expectedVersion' => $address['version'],
], $changes);
$addressOf = static fn (array $detail, string $id): array => array_values(array_filter($detail['addresses'], static fn (array $address): bool => $address['id'] === $id))[0];
checkError($send($editor, 'POST', "{$companyPath}/addresses", ['type' => 'warehouse', 'countryCode' => 'RO', 'city' => 'Cluj', 'addressLine1' => 'x']), 422, 'VALIDATION_FAILED', 'an unknown address type is refused');
$addressKey = $newKey();
$deliveryBody = ['type' => 'delivery', 'label' => 'Depozit', 'countryCode' => 'RO', 'city' => 'Cluj-Napoca', 'addressLine1' => 'Str. Depozitului 5', 'isPrimary' => true];
$delivery = checkStatus($send($editor, 'POST', "{$companyPath}/addresses", $deliveryBody, $addressKey), 201, 'a primary delivery address is added next to the billing address');
checkStatus($send($editor, 'POST', "{$companyPath}/addresses", $deliveryBody, $addressKey), 201, 'a retried address creation replays');
check(count($delivery['addresses']) === 2 && $eventCount($perdele['id'], 'address_created') === 2, 'the retry created no duplicate address');
$billingId = $full['addressId'];
$deliveryId = $delivery['addressId'];
check($addressOf($delivery, $billingId)['isPrimary'] === true && $addressOf($delivery, $deliveryId)['isPrimary'] === true, 'billing and delivery each keep their own primary address');
$secondBilling = checkStatus($send($editor, 'POST', "{$companyPath}/addresses", ['type' => 'billing', 'countryCode' => 'RO', 'city' => 'Iași', 'addressLine1' => 'Bd. Nou 2', 'isPrimary' => true]), 201, 'a new primary billing address');
check($addressOf($secondBilling, $billingId)['isPrimary'] === false && $addressOf($secondBilling, $secondBilling['addressId'])['isPrimary'] === true && $addressOf($secondBilling, $deliveryId)['isPrimary'] === true, 'a new primary billing address replaces only the billing primary');
$office = checkStatus($send($editor, 'POST', "{$companyPath}/addresses", ['type' => 'office', 'countryCode' => 'TR', 'city' => 'İstanbul', 'addressLine1' => 'Ofis Sok. 3']), 201, 'a foreign office address');
check(count($office['addresses']) === 4, 'multiple addresses are supported');
$officeAddress = $addressOf($office, $office['addressId']);
$retyped = checkStatus($send($editor, 'PUT', "{$companyPath}/addresses/{$officeAddress['id']}", $addressBody($officeAddress, ['type' => 'delivery', 'isPrimary' => true])), 200, 'an address changes type and becomes the primary delivery address');
check($addressOf($retyped, $officeAddress['id'])['type'] === 'delivery' && $addressOf($retyped, $officeAddress['id'])['isPrimary'] === true && $addressOf($retyped, $deliveryId)['isPrimary'] === false, 'the previous primary delivery address stepped down');
checkError($send($editor, 'PUT', "{$companyPath}/addresses/{$deliveryId}", $addressBody($addressOf($delivery, $deliveryId), ['label' => 'Vechi'])), 409, 'ADDRESS_CHANGED', 'a stale address version is refused');
$deliveryNow = $addressOf($retyped, $deliveryId);
$deactivatedAddress = checkStatus($send($editor, 'POST', "{$companyPath}/addresses/{$deliveryNow['id']}/deactivate", ['expectedVersion' => $deliveryNow['version']]), 200, 'an address is deactivated');
check($addressOf($deactivatedAddress, $deliveryNow['id'])['status'] === 'inactive', 'the address is inactive');
$addressAfter = $addressOf($deactivatedAddress, $deliveryNow['id']);
checkStatus($send($editor, 'POST', "{$companyPath}/addresses/{$addressAfter['id']}/reactivate", ['expectedVersion' => $addressAfter['version']]), 200, 'the address is reactivated');
try {
    $pdo->prepare("INSERT INTO b2b_company_addresses (address_uuid, company_uuid, address_type, country_code, city, address_line_1, is_primary, primary_company_uuid, primary_address_type, status, created_at, updated_at, created_by_employee_uuid, updated_by_employee_uuid)
        VALUES (UUID(), ?, 'billing', 'RO', 'X', 'Y', 1, ?, 'billing', 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), ?, ?)")->execute([$perdele['id'], $perdele['id'], $rootId, $rootId]);
    check(false, 'the database accepted a second primary billing address');
} catch (PDOException $error) {
    check((int) ($error->errorInfo[1] ?? 0) === 1062, 'the database refuses a second primary address of one type');
}
try {
    $pdo->prepare('UPDATE b2b_company_contacts SET is_primary = 1 WHERE contact_uuid = ?')->execute([$anaId]);
    check(false, 'the database accepted a primary flag without its unique marker');
} catch (PDOException $error) {
    check(in_array((int) ($error->errorInfo[1] ?? 0), [3819, 4025], true), 'the database keeps the primary flag and its unique marker consistent');
}
try {
    $pdo->prepare('UPDATE b2b_company_addresses SET is_primary = 1 WHERE address_uuid = ?')->execute([$billingId]);
    check(false, 'the database accepted a primary address without its unique marker');
} catch (PDOException $error) {
    check(in_array((int) ($error->errorInfo[1] ?? 0), [3819, 4025], true), 'the database keeps the primary address flag and its marker consistent');
}
try {
    $pdo->prepare("UPDATE b2b_company_addresses SET status = 'inactive' WHERE address_uuid = ?")->execute([$secondBilling['addressId']]);
    check(false, 'the database accepted an inactive primary address');
} catch (PDOException $error) {
    check(in_array((int) ($error->errorInfo[1] ?? 0), [3819, 4025], true), 'the database refuses an inactive primary address');
}

// ---- Activity --------------------------------------------------------------------------------------
$activity = checkStatus($get($viewer, "{$companyPath}/activity"), 200, 'activity');
$actions = array_column($activity['items'], 'action');
foreach (['company_created', 'contact_created', 'contact_updated', 'contact_deactivated', 'contact_reactivated', 'address_created', 'address_updated', 'address_deactivated', 'address_reactivated'] as $action) {
    check(in_array($action, $actions, true), "activity records {$action}");
}
$mobilaActions = array_column(checkStatus($get($viewer, "/b2b/companies/{$mobila['id']}/activity", ['limit' => '100']), 200, 'mobila activity')['items'], 'action');
check(array_reverse($mobilaActions) === ['company_created', 'company_updated', 'company_updated', 'company_deactivated', 'company_reactivated'], 'company events are stable keys in order: ' . json_encode($mobilaActions));
$mobilaUpdate = array_values(array_filter(checkStatus($get($viewer, "/b2b/companies/{$mobila['id']}/activity"), 200, 'mobila activity')['items'], static fn (array $e): bool => $e['action'] === 'company_updated'));
check($mobilaUpdate[1]['changedFields'] === ['displayName', 'internalNotes'] && $mobilaUpdate[1]['actor']['id'] === $editor['id'], 'an update records the actor and the changed field names');
check(str_starts_with($mobilaUpdate[1]['requestId'], 'test-'), 'events carry the request id');
$rawEvents = json_encode($pdo->query('SELECT * FROM b2b_company_activity_events')->fetchAll(), JSON_UNESCAPED_UNICODE);
foreach (['Plătește la livrare', 'ana.pop@perdele-design.ro', '+40 721 000 111', 'Str. Exemplu 1', 'Client recomandat'] as $sensitive) {
    check(!str_contains($rawEvents, $sensitive), "activity stores no sensitive value ({$sensitive})");
}
$idempotencyRows = json_encode($pdo->query('SELECT operation, response_json FROM b2b_company_idempotency')->fetchAll(), JSON_UNESCAPED_UNICODE);
check(!str_contains($idempotencyRows, 'ana.pop') && !str_contains($idempotencyRows, 'Client recomandat') && !str_contains($idempotencyRows, 'Str. Exemplu'), 'idempotency results store references, not company data');
$activityPage = checkStatus($get($viewer, "{$companyPath}/activity", ['limit' => '25']), 200, 'activity page 1');
check(count($activityPage['items']) <= 25 && ($activityPage['nextCursor'] === null || is_string($activityPage['nextCursor'])), 'activity is paginated');
$routes = array_filter([
    $send($root, 'PUT', "{$companyPath}/activity", []),
    $send($root, 'DELETE', "{$companyPath}/activity"),
    $send($root, 'POST', "{$companyPath}/activity", []),
], static fn (array $response): bool => $response['status'] < 400);
check($routes === [], 'activity cannot be changed through the API');

// ---- Privacy and isolation -------------------------------------------------------------------------
check(!str_contains(json_encode(checkStatus($asDashboard($root, 'GET', '/management/production-overview'), 200, 'production overview still works')), 'Perdele Design'), 'production overview carries no company data');
check(!str_contains(json_encode(checkStatus($asDashboard($root, 'GET', '/management/orders'), 200, 'production order list still works')), 'Perdele Design'), 'the production order list carries no company data');
check(!str_contains(json_encode(checkStatus($asDashboard($root, 'GET', '/management/audit', ['limit' => '100']), 200, 'IAM audit')), 'Perdele'), 'the IAM audit is not used as company history');
$health = T::call($kernel, 'GET', '/health');
check($health['status'] === 200 && !str_contains(json_encode($health['body']), 'b2b_') && !str_contains(json_encode($health['body']), 'Perdele'), 'the public health endpoint carries no company data');
checkError($get($seller, '/orders/mine'), 403, 'APPLICATION_ACCESS_DENIED', 'B2B access opens no Staff route');
checkError($get($seller, '/management/me'), 403, 'APPLICATION_ACCESS_DENIED', 'B2B access opens no Dashboard route');
$denied = $get($gateOnly, $companyPath);
check(!str_contains(json_encode($denied['body']), 'Perdele') && !str_contains(json_encode($denied['body']), $perdele['code']), 'an unauthorized error body carries no company data');

// ---- Access removal --------------------------------------------------------------------------------
checkStatus($asDashboard($root, 'PUT', "/management/employees/{$seller['id']}/applications", ['applications' => []]), 200, 'root removes B2B access from the seller');
checkError($get($seller, '/b2b/companies'), 403, 'APPLICATION_ACCESS_DENIED', 'a removed B2B grant closes companies on the next request');
checkError($send($seller, 'POST', '/b2b/companies', ['legalName' => 'Dupa SRL', 'countryCode' => 'RO', 'taxIdentifier' => '99887766']), 403, 'APPLICATION_ACCESS_DENIED', 'and closes mutations');
checkStatus($asDashboard($root, 'POST', "/management/employees/{$viewer['id']}/deactivate"), 200, 'root deactivates the viewer');
checkError($get($viewer, '/b2b/companies'), 401, 'SESSION_EXPIRED', 'a deactivated identity loses its session');

// ---- Production invariants -------------------------------------------------------------------------
check($pdo->query($orderStateSql)->fetch() == $orderStateBefore, 'no company operation changed production orders');
check((int) $pdo->query("SELECT {$staffStateSql}")->fetchColumn() === $staffStateBefore, 'no company operation wrote Staff activity, claims or source receipts');

fwrite(STDOUT, "PASS MySQL B2B companies integration ({$checks} checks)\n");
