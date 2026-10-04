<?php

declare(strict_types=1);

// Central IAM lifecycle against a dedicated MySQL/MariaDB test database: protected root identity,
// application access, RBAC authority ceiling, privilege escalation, immediate authorization changes,
// forced password change, shared sessions across Staff, Dashboard and B2B origins, and the immutable IAM audit.

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
    fwrite(STDOUT, "SKIP MySQL IAM integration: ARASYA_TEST_DB_NAME is not configured.\n");
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

/** @param array{status: int, body: array<string, mixed>|null} $response */
function checkOk(array $response, string $message, int $status = 200): array
{
    check($response['status'] === $status, "{$message} (got {$response['status']} " . json_encode($response['body']) . ')');
    return $response['body'] ?? [];
}

const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';
const B2B_ORIGIN = 'http://127.0.0.1:4177';

$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN, B2B_ORIGIN]);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
$seedFiles = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seedFiles, SORT_STRING);
foreach ($seedFiles as $seedFile) {
    (new SqlFileRunner($pdo))->run($seedFile);
}
// Seeding twice must not re-impose or duplicate anything.
foreach ($seedFiles as $seedFile) {
    (new SqlFileRunner($pdo))->run($seedFile);
}

// ---- Upgrade path to migration 008 (B2B application) ------------------------------------------
// Rebuild the pre-008 catalog in this test database, then apply 008 exactly once on top of it.
$applicationsBefore = $pdo->query("SELECT application_key, name, status, access_permission_key, sort_order FROM applications WHERE application_key <> 'b2b' ORDER BY application_key")->fetchAll();
$permissionsBefore = $pdo->query("SELECT permission_key, role_grantable FROM permissions WHERE permission_key <> 'b2b.access' ORDER BY permission_key")->fetchAll();
$pdo->exec("DELETE FROM employee_application_access WHERE application_key = 'b2b'");
$pdo->exec("DELETE rp FROM role_permissions rp INNER JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.permission_key = 'b2b.access'");
$pdo->exec("DELETE FROM applications WHERE application_key = 'b2b'");
$pdo->exec("DELETE FROM permissions WHERE permission_key = 'b2b.access'");
$pdo->exec("DELETE FROM schema_migrations WHERE migration_name = '008_b2b_application.sql'");
$upgraded = (new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
check($upgraded === ['008_b2b_application.sql'], 'a pre-008 database applies exactly migration 008 (got ' . implode(', ', $upgraded) . ')');
check((new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations') === [], 'migration 008 is recorded once');
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/migrations/008_b2b_application.sql');
foreach ($seedFiles as $seedFile) {
    (new SqlFileRunner($pdo))->run($seedFile);
}
$b2bApplication = $pdo->query("SELECT name, description, status, access_permission_key, sort_order FROM applications WHERE application_key = 'b2b'")->fetchAll();
check(count($b2bApplication) === 1 && $b2bApplication[0]['name'] === 'B2B' && $b2bApplication[0]['status'] === 'active' && $b2bApplication[0]['access_permission_key'] === 'b2b.access' && (int) $b2bApplication[0]['sort_order'] === 30, 'migration 008 registers one active B2B application, also when re-run');
$b2bPermission = $pdo->query("SELECT category, role_grantable FROM permissions WHERE permission_key = 'b2b.access'")->fetchAll();
check(count($b2bPermission) === 1 && $b2bPermission[0]['category'] === 'applications' && (int) $b2bPermission[0]['role_grantable'] === 0, 'b2b.access exists once and is never role-grantable');
check($pdo->query("SELECT application_key, name, status, access_permission_key, sort_order FROM applications WHERE application_key <> 'b2b' ORDER BY application_key")->fetchAll() == $applicationsBefore, 'migration 008 leaves the existing applications unchanged');
check($pdo->query("SELECT permission_key, role_grantable FROM permissions WHERE permission_key <> 'b2b.access' ORDER BY permission_key")->fetchAll() == $permissionsBefore, 'migration 008 leaves the existing permissions unchanged');
check((int) $pdo->query("SELECT COUNT(*) FROM employee_application_access WHERE application_key = 'b2b'")->fetchColumn() === 0, 'migration 008 grants B2B access to nobody');
// Business tables arrive only with migration 009 (B2B Companies V1); 008 itself creates no table.
check(preg_match('/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', (string) file_get_contents(dirname(__DIR__) . '/database/migrations/008_b2b_application.sql')) !== 1, 'migration 008 creates no B2B business tables');

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);

// Integration databases are reused between runs; the root singleton is reset only in this test database.
$pdo->exec('DELETE FROM system_root_identity');

$asDashboard = static fn (array $who, string $method, string $path, ?array $json = null, array $query = []): array => T::call(
    $kernel,
    $method,
    $path,
    $json,
    ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $who['csrf']],
    $who['cookie'],
    $query,
);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => DASHBOARD_ORIGIN], $who['cookie'], $query);
$loginAt = static function (string $username, string $password, string $origin) use ($kernel): array {
    $response = T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => $origin]);
    if ($response['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $response['headers']['Set-Cookie'] ?? '', $match)) {
        throw new RuntimeException("Login failed for {$username}: " . json_encode($response['body']));
    }
    return ['cookie' => rawurldecode($match[1]), 'csrf' => (string) $response['body']['csrfToken'], 'body' => $response['body']];
};
$changePassword = static function (array $who, string $current, string $new) use ($kernel): array {
    $response = T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $current, 'newPassword' => $new], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $who['csrf']], $who['cookie']);
    if ($response['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $response['headers']['Set-Cookie'] ?? '', $match)) {
        throw new RuntimeException('Password change failed: ' . json_encode($response['body']));
    }
    return ['cookie' => rawurldecode($match[1]), 'csrf' => (string) $response['body']['csrfToken'], 'body' => $response['body']];
};
$auditCount = static function (string $action, string $targetId) use ($pdo): int {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM iam_audit_events WHERE action = :action AND target_id = :target_id');
    $statement->execute(['action' => $action, 'target_id' => $targetId]);
    return (int) $statement->fetchColumn();
};

// ---- Root bootstrap ----------------------------------------------------------------------
$bootstrap = new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock());
$rootPassword = $bootstrap->bootstrap('test-root-bootstrap', 'arasya.root.owner.' . $suffix);
check(strlen($rootPassword) >= 24 && preg_match('/[A-Z]/', $rootPassword) === 1 && preg_match('/[a-z]/', $rootPassword) === 1 && preg_match('/\d/', $rootPassword) === 1, 'root password is long and mixed');
$secondRoot = null;
try {
    $bootstrap->bootstrap('test-root-second', 'arasya.root.second.' . $suffix);
} catch (RuntimeException $error) {
    $secondRoot = $error->getMessage();
}
check($secondRoot !== null && (int) $pdo->query('SELECT COUNT(*) FROM system_root_identity')->fetchColumn() === 1, 'a second root bootstrap fails and exactly one root exists');
$plainStored = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE password_hash = :plain');
$plainStored->execute(['plain' => $rootPassword]);
check((int) $plainStored->fetchColumn() === 0, 'root password is not stored in plaintext');
$cliEmployee = $container->employeeAdmin()->create('Angajat CLI', "cli.{$suffix}", null, 'pregatire-material', 'employee', 'cli passphrase 2026', ['waiting'], 'test');
check($cliEmployee->applications === ['staff'] && !$cliEmployee->hasApplication('dashboard'), 'CLI-provisioned employees keep Staff access only');
$duplicateRoot = null;
try {
    $pdo->prepare('INSERT INTO system_root_identity (singleton_id, employee_uuid, created_at) VALUES (1, :id, UTC_TIMESTAMP(6))')->execute(['id' => $cliEmployee->employeeUuid]);
} catch (PDOException $error) {
    $duplicateRoot = $error->getCode();
}
check($duplicateRoot !== null, 'the database itself rejects a second root row');

// ---- First login and forced password change --------------------------------------------------
$root = $loginAt('arasya.root.owner.' . $suffix, $rootPassword, DASHBOARD_ORIGIN);
check(($root['body']['employee']['mustChangePassword'] ?? null) === true && ($root['body']['employee']['isRoot'] ?? null) === true, 'root first login reports a required password change');
checkError($get($root, '/management/me'), 403, 'PASSWORD_CHANGE_REQUIRED', 'management is blocked until the password changes');
checkError($get($root, '/orders/mine'), 403, 'PASSWORD_CHANGE_REQUIRED', 'Staff is blocked until the password changes');
checkError(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => 'wrong password value', 'newPassword' => 'Root new passphrase 2026!'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $root['csrf']], $root['cookie']), 400, 'CURRENT_PASSWORD_INVALID', 'wrong current password is rejected');
checkError(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $rootPassword, 'newPassword' => 'short'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $root['csrf']], $root['cookie']), 400, 'PASSWORD_POLICY', 'weak new password is rejected');
checkError(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $rootPassword, 'newPassword' => 'Root new passphrase 2026!'], ['origin' => DASHBOARD_ORIGIN], $root['cookie']), 403, 'CSRF_INVALID', 'password change requires CSRF');
$oldRootCookie = $root['cookie'];
$rootNewPassword = 'Root new passphrase 2026!';
$root = $changePassword($root, $rootPassword, $rootNewPassword);
check(($root['body']['employee']['mustChangePassword'] ?? null) === false, 'password change clears the requirement');
checkError($get(['cookie' => $oldRootCookie, 'csrf' => ''], '/management/me'), 401, 'SESSION_EXPIRED', 'the temporary-password session is revoked after the change');
checkError(T::call($kernel, 'POST', '/auth/login', ['username' => 'arasya.root.owner.' . $suffix, 'password' => $rootPassword], ['origin' => DASHBOARD_ORIGIN]), 401, 'INVALID_CREDENTIALS', 'the temporary password no longer works');
$me = checkOk($get($root, '/management/me'), 'root reads management/me');
check($me['isRoot'] === true && in_array('system.manage', $me['permissions'], true) && in_array('dashboard', $me['applications'], true) && in_array('staff', $me['applications'], true) && in_array('b2b', $me['applications'], true) && in_array('b2b.access', $me['permissions'], true), 'root holds every permission and application, B2B included');

// ---- Catalog, applications, workflow ----------------------------------------------------------
$permissions = checkOk($get($root, '/management/permissions'), 'permission catalog');
$catalogKeys = array_column($permissions['items'], 'key');
check(in_array('employees.manage_roles', $catalogKeys, true) && in_array('staff.access', $catalogKeys, true), 'catalog contains management and application permissions');
$applications = checkOk($get($root, '/management/applications'), 'applications');
check(array_column($applications['items'], 'key') === ['staff', 'dashboard', 'b2b'], 'staff, dashboard and b2b are the registered applications');
$roles = checkOk($get($root, '/management/roles'), 'roles');
$roleId = static function (string $key) use ($roles): int {
    foreach ($roles['items'] as $role) {
        if ($role['key'] === $key) {
            return (int) $role['id'];
        }
    }
    throw new RuntimeException("Role {$key} is missing.");
};
check(count(array_filter($roles['items'], static fn (array $role): bool => $role['isTemplate'])) >= 5, 'role templates exist');
$departments = checkOk($get($root, '/management/departments'), 'departments');
$departmentId = (int) $departments['items'][0]['id'];
$workflow = checkOk($get($root, '/production/workflow'), 'canonical workflow for the Dashboard');
check(count($workflow['stages']) === 14, 'Dashboard receives the 14 canonical stages from the API');

// ---- Employee creation with temporary passwords ------------------------------------------------
$create = static function (array $actor, string $name, array $applications, array $roleKeys, array $stages) use ($asDashboard, $roleId, $departmentId, $suffix): array {
    return $asDashboard($actor, 'POST', '/management/employees', [
        'displayName' => "Test {$name}",
        'username' => "{$name}.{$suffix}",
        'departmentId' => $departmentId,
        'positionTitle' => 'Test',
        'managerId' => null,
        'applications' => $applications,
        'roleIds' => array_map($roleId, $roleKeys),
        'stageIds' => $stages,
        'status' => 'active',
    ]);
};
$ceoCreated = checkOk($create($root, 'ceo', ['dashboard', 'staff'], ['ceo'], []), 'root creates a CEO', 201);
check(strlen($ceoCreated['temporaryPassword']) >= 16 && $ceoCreated['employee']['mustChangePassword'] === true, 'creation returns a strong temporary password once');
$ceoId = $ceoCreated['employee']['id'];
check(!str_contains(json_encode($get($root, "/management/employees/{$ceoId}")['body']), $ceoCreated['temporaryPassword']), 'the temporary password cannot be read back');
$managerCreated = checkOk($create($root, 'manager', ['dashboard'], ['department-manager'], []), 'root creates a manager', 201);
$staffCreated = checkOk($create($root, 'worker', ['staff'], ['employee'], ['material-preparation']), 'root creates a Staff-only employee', 201);
$workerId = $staffCreated['employee']['id'];
checkError($create($root, 'worker', ['staff'], [], []), 409, 'USERNAME_TAKEN', 'duplicate usernames are rejected');
checkError($create($root, 'ghost', ['staff'], [], ['not-a-stage']), 400, 'UNKNOWN_STAGE', 'Dashboard cannot invent a production stage');
checkError($asDashboard($root, 'POST', '/management/employees', ['displayName' => 'Test X', 'username' => "x.{$suffix}", 'departmentId' => $departmentId, 'positionTitle' => null, 'managerId' => null, 'applications' => ['finance'], 'roleIds' => [], 'stageIds' => [], 'status' => 'active']), 400, 'UNKNOWN_APPLICATION', 'unregistered applications are rejected');
checkError($asDashboard($root, 'POST', '/management/employees', ['displayName' => 'Test X', 'username' => "y.{$suffix}", 'departmentId' => $departmentId, 'positionTitle' => null, 'managerId' => null, 'applications' => [], 'roleIds' => [], 'stageIds' => [], 'status' => 'active', 'isRoot' => true]), 400, 'INVALID_REQUEST', 'unknown privileged fields are rejected');

$ceo = $loginAt("ceo.{$suffix}", $ceoCreated['temporaryPassword'], DASHBOARD_ORIGIN);
$ceo = $changePassword($ceo, $ceoCreated['temporaryPassword'], 'Ceo passphrase 2026!!');
$manager = $loginAt("manager.{$suffix}", $managerCreated['temporaryPassword'], DASHBOARD_ORIGIN);
$manager = $changePassword($manager, $managerCreated['temporaryPassword'], 'Manager passphrase 2026!');
$worker = $loginAt("worker.{$suffix}", $staffCreated['temporaryPassword'], T::ORIGIN);
$worker = $changePassword($worker, $staffCreated['temporaryPassword'], 'Worker passphrase 2026!');

// ---- Application access and shared sessions ---------------------------------------------------
checkError($get($worker, '/management/me'), 403, 'APPLICATION_ACCESS_DENIED', 'a Staff-only identity cannot enter the Dashboard');
checkOk(T::call($kernel, 'GET', '/orders/mine', null, ['origin' => T::ORIGIN], $worker['cookie']), 'the Staff-only identity uses Staff');
checkError(T::call($kernel, 'GET', '/orders/mine', null, ['origin' => T::ORIGIN], $manager['cookie']), 403, 'APPLICATION_ACCESS_DENIED', 'a Dashboard-only identity cannot use Staff routes');
$sharedSession = T::call($kernel, 'GET', '/auth/session', null, ['origin' => T::ORIGIN], $ceo['cookie']);
check($sharedSession['status'] === 200 && in_array('staff', $sharedSession['body']['employee']['applications'], true), 'a Dashboard login session is recognized from the Staff origin');
checkOk(T::call($kernel, 'GET', '/orders/mine', null, ['origin' => T::ORIGIN], $ceo['cookie']), 'the same session serves Staff');
$evil = T::call($kernel, 'GET', '/auth/session', null, ['origin' => 'https://evil.example'], $ceo['cookie']);
check(!isset($evil['headers']['Access-Control-Allow-Origin']), 'unknown origins get no credentialed CORS headers');
checkError(T::call($kernel, 'PUT', "/management/employees/{$workerId}/applications", ['applications' => ['staff', 'dashboard']], ['origin' => 'https://evil.example', 'x-csrf-token' => $root['csrf']], $root['cookie']), 403, 'ORIGIN_DENIED', 'unknown origins cannot mutate');
checkError(T::call($kernel, 'PUT', "/management/employees/{$workerId}/applications", ['applications' => ['staff', 'dashboard']], ['origin' => DASHBOARD_ORIGIN], $root['cookie']), 403, 'CSRF_INVALID', 'mutations without CSRF are rejected');
$preflight = T::call($kernel, 'OPTIONS', '/management/employees', null, ['origin' => DASHBOARD_ORIGIN, 'access-control-request-method' => 'PATCH', 'access-control-request-headers' => 'content-type,x-csrf-token']);
check($preflight['status'] === 204 && ($preflight['headers']['Access-Control-Allow-Origin'] ?? '') === DASHBOARD_ORIGIN && str_contains($preflight['headers']['Access-Control-Allow-Methods'] ?? '', 'PATCH'), 'Dashboard preflight for PATCH is allowed for the exact origin');

$versionBefore = (int) $get($root, "/management/employees/{$workerId}")['body']['authorizationVersion'];
checkOk($asDashboard($root, 'PUT', "/management/employees/{$workerId}/applications", ['applications' => ['staff', 'dashboard']]), 'root grants Dashboard access');
checkOk($get($worker, '/management/me'), 'the grant applies on the next request without a new login');
check((int) $get($root, "/management/employees/{$workerId}")['body']['authorizationVersion'] > $versionBefore, 'application access changes the authorization version');
checkOk($asDashboard($root, 'PUT', "/management/employees/{$workerId}/applications", ['applications' => ['dashboard']]), 'root removes Staff access');
checkError(T::call($kernel, 'GET', '/orders/mine', null, ['origin' => T::ORIGIN], $worker['cookie']), 403, 'APPLICATION_ACCESS_DENIED', 'Staff access removal applies on the next Staff request');
checkOk($get($worker, '/management/me'), 'the Dashboard session stays valid while Dashboard access remains');
checkOk($asDashboard($root, 'PUT', "/management/employees/{$workerId}/applications", ['applications' => ['staff']]), 'root restores Staff only');
checkError($get($worker, '/management/me'), 403, 'APPLICATION_ACCESS_DENIED', 'Dashboard access removal applies on the next request');
check($auditCount('employee.applications_changed', $workerId) === 3, 'every application access change is audited');

// ---- B2B application: same central session, its own application gate -----------------------------
$fromB2b = static fn (array $who, string $method, string $path, ?array $json = null): array => T::call($kernel, $method, $path, $json, ['origin' => B2B_ORIGIN, 'x-csrf-token' => $who['csrf']], $who['cookie']);
$rootB2b = $fromB2b($root, 'GET', '/b2b/access');
check($rootB2b['status'] === 200 && $rootB2b['body']['application'] === 'b2b' && $rootB2b['body']['employee']['isRoot'] === true && ($rootB2b['headers']['Access-Control-Allow-Origin'] ?? '') === B2B_ORIGIN, 'root enters B2B through the existing root semantics, from the exact B2B origin');
check(!str_contains((string) json_encode($rootB2b['body']), 'password') && !isset($rootB2b['body']['employee']['permissions']), 'the B2B gate returns no secret and no permission catalog');
$b2bPreflight = T::call($kernel, 'OPTIONS', '/auth/logout', null, ['origin' => B2B_ORIGIN, 'access-control-request-method' => 'POST', 'access-control-request-headers' => 'content-type,x-csrf-token']);
check($b2bPreflight['status'] === 204 && ($b2bPreflight['headers']['Access-Control-Allow-Origin'] ?? '') === B2B_ORIGIN && ($b2bPreflight['headers']['Access-Control-Allow-Credentials'] ?? '') === 'true', 'the B2B origin passes the credentialed preflight');
checkError(T::call($kernel, 'OPTIONS', '/auth/logout', null, ['origin' => 'https://b2b.arasyahome.ro.evil.example', 'access-control-request-method' => 'POST']), 403, 'ORIGIN_DENIED', 'a B2B look-alike origin is denied');
checkError(T::call($kernel, 'POST', '/auth/login', ['username' => 'x', 'password' => 'y'], ['origin' => 'https://evil.example']), 403, 'ORIGIN_DENIED', 'hostile origins still cannot log in');
$workerSessionFromB2b = T::call($kernel, 'GET', '/auth/session', null, ['origin' => B2B_ORIGIN], $worker['cookie']);
check($workerSessionFromB2b['status'] === 200 && !in_array('b2b', $workerSessionFromB2b['body']['employee']['applications'], true), 'the existing Staff session is the same session seen from the B2B origin');
checkError($fromB2b($worker, 'GET', '/b2b/access'), 403, 'APPLICATION_ACCESS_DENIED', 'an identity without B2B access is refused by the server');
checkError($fromB2b($manager, 'GET', '/b2b/access'), 403, 'APPLICATION_ACCESS_DENIED', 'Dashboard access does not imply B2B access');
checkError($asDashboard($ceo, 'PUT', "/management/employees/{$workerId}/applications", ['applications' => ['staff', 'b2b']]), 403, 'AUTHORITY_EXCEEDED', 'an administrator without B2B access cannot grant it');
checkOk($asDashboard($root, 'PUT', "/management/employees/{$workerId}/applications", ['applications' => ['staff', 'b2b']]), 'root grants B2B access');
$workerB2b = checkOk($fromB2b($worker, 'GET', '/b2b/access'), 'the B2B grant applies on the next request without a new login');
check($workerB2b['employee']['isRoot'] === false && $workerB2b['employee']['username'] === "worker.{$suffix}", 'the B2B gate names the central identity');
$workerAfterGrant = T::call($kernel, 'GET', '/auth/session', null, ['origin' => B2B_ORIGIN], $worker['cookie'])['body']['employee'];
check(in_array('b2b.access', $workerAfterGrant['permissions'], true) && !in_array('dashboard.access', $workerAfterGrant['permissions'], true), 'B2B access adds b2b.access and nothing from the Dashboard');
checkError($get($worker, '/management/me'), 403, 'APPLICATION_ACCESS_DENIED', 'B2B access does not open the Dashboard');
checkOk(T::call($kernel, 'GET', '/orders/mine', null, ['origin' => T::ORIGIN], $worker['cookie']), 'Staff keeps working next to B2B');
checkOk($asDashboard($root, 'PUT', "/management/employees/{$workerId}/applications", ['applications' => ['staff']]), 'root removes B2B access');
checkError($fromB2b($worker, 'GET', '/b2b/access'), 403, 'APPLICATION_ACCESS_DENIED', 'B2B access removal applies on the next B2B request');
check($auditCount('employee.applications_changed', $workerId) === 5, 'B2B grants and removals are audited like every application change');
checkError($asDashboard($root, 'PUT', "/management/employees/{$me['employee']['id']}/applications", ['applications' => ['staff', 'dashboard']]), 403, 'ROOT_PROTECTED', 'root B2B access cannot be removed');

$b2bCreated = checkOk($create($root, 'vanzari', ['b2b'], [], []), 'root creates a B2B-only employee', 201);
$b2bUser = $loginAt("vanzari.{$suffix}", $b2bCreated['temporaryPassword'], B2B_ORIGIN);
check($b2bUser['body']['employee']['mustChangePassword'] === true && $b2bUser['body']['employee']['applications'] === ['b2b'], 'a B2B-only identity logs in from the B2B origin with a temporary password');
checkError($fromB2b($b2bUser, 'GET', '/b2b/access'), 403, 'PASSWORD_CHANGE_REQUIRED', 'B2B is blocked until the temporary password changes');
$b2bChanged = T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $b2bCreated['temporaryPassword'], 'newPassword' => 'Vanzari passphrase 2026!'], ['origin' => B2B_ORIGIN, 'x-csrf-token' => $b2bUser['csrf']], $b2bUser['cookie']);
check($b2bChanged['status'] === 200 && preg_match('/^arasya_session=([^;]+);/', $b2bChanged['headers']['Set-Cookie'] ?? '', $b2bCookie) === 1 && str_contains($b2bChanged['headers']['Set-Cookie'], 'HttpOnly') && str_contains($b2bChanged['headers']['Set-Cookie'], 'SameSite=Lax'), 'the password change from the B2B origin rotates the same HttpOnly session cookie');
$b2bUser = ['cookie' => rawurldecode($b2bCookie[1]), 'csrf' => (string) $b2bChanged['body']['csrfToken']];
checkOk($fromB2b($b2bUser, 'GET', '/b2b/access'), 'the B2B-only identity enters B2B');
checkError(T::call($kernel, 'GET', '/orders/mine', null, ['origin' => T::ORIGIN], $b2bUser['cookie']), 403, 'APPLICATION_ACCESS_DENIED', 'B2B access cannot drive production through Staff routes');
checkError(T::call($kernel, 'POST', '/orders/trendhome:1/claim', ['expectedVersion' => 1], ['origin' => B2B_ORIGIN, 'x-csrf-token' => $b2bUser['csrf'], 'idempotency-key' => 'b2b-claim-' . $suffix], $b2bUser['cookie']), 403, 'APPLICATION_ACCESS_DENIED', 'a B2B identity cannot claim production orders');
checkError($get($b2bUser, '/management/orders'), 403, 'APPLICATION_ACCESS_DENIED', 'a B2B identity cannot read Dashboard production data');
checkOk($asDashboard($root, 'POST', "/management/employees/{$b2bCreated['employee']['id']}/deactivate"), 'root deactivates the B2B-only employee');
checkError($fromB2b($b2bUser, 'GET', '/b2b/access'), 401, 'SESSION_EXPIRED', 'deactivation revokes the session and ends B2B access on the next request');
checkError(T::call($kernel, 'GET', '/b2b/access', null, ['origin' => B2B_ORIGIN]), 401, 'SESSION_EXPIRED', 'the B2B gate needs the central session cookie, like every protected route');

// ---- Staff stages --------------------------------------------------------------------------
checkOk($asDashboard($root, 'PUT', "/management/employees/{$workerId}/stages", ['stageIds' => ['material-preparation', 'workshop-receiving']]), 'root assigns stages');
$profile = checkOk(T::call($kernel, 'GET', '/employees/me', null, ['origin' => T::ORIGIN], $worker['cookie']), 'Staff profile after stage change');
check($profile['allowedStageIds'] === ['material-preparation', 'workshop-receiving'], 'stage change applies to Staff immediately');
checkError($asDashboard($root, 'PUT', "/management/employees/{$workerId}/stages", ['stageIds' => ['delivery', 'shipping']]), 400, 'UNKNOWN_STAGE', 'unknown stages are rejected atomically');
check($auditCount('employee.stages_changed', $workerId) === 1, 'stage assignment is audited once');

// ---- RBAC and privilege escalation ---------------------------------------------------------
$managerMe = checkOk($get($manager, '/management/me'), 'manager reads management/me');
check($managerMe['isRoot'] === false && !in_array('system.manage', $managerMe['permissions'], true), 'manager is not root');
checkError($asDashboard($manager, 'PUT', "/management/employees/{$workerId}/roles", ['roleIds' => [$roleId('ceo')]]), 403, 'AUTHORITY_EXCEEDED', 'a manager cannot assign a role above its authority');
checkError($asDashboard($manager, 'PUT', "/management/employees/{$managerCreated['employee']['id']}/roles", ['roleIds' => [$roleId('department-manager'), $roleId('operations-director')]]), 403, 'SELF_MODIFICATION_DENIED', 'a manager cannot change its own roles');
checkError($asDashboard($manager, 'POST', '/management/roles', ['name' => 'Escaladare', 'description' => null, 'authorityRank' => 100, 'permissions' => ['employees.view', 'system.view']]), 403, 'UNAUTHORIZED_ACTION', 'a manager without roles.create cannot create roles');
checkError($asDashboard($ceo, 'POST', '/management/roles', ['name' => 'Peste CEO', 'description' => null, 'authorityRank' => 950, 'permissions' => ['employees.view']]), 403, 'AUTHORITY_EXCEEDED', 'a CEO cannot create a role at or above its own rank');
checkError($asDashboard($ceo, 'POST', '/management/roles', ['name' => 'Sistem', 'description' => null, 'authorityRank' => 200, 'permissions' => ['system.manage']]), 403, 'PERMISSION_NOT_GRANTABLE', 'nobody can put system.manage into a role');
checkError($asDashboard($ceo, 'POST', '/management/roles', ['name' => 'Necunoscut', 'description' => null, 'authorityRank' => 200, 'permissions' => ['employees.everything']]), 400, 'UNKNOWN_PERMISSION', 'unknown permission strings are rejected');
checkError($asDashboard($ceo, 'POST', '/management/roles', ['name' => 'Acces', 'description' => null, 'authorityRank' => 200, 'permissions' => ['dashboard.access']]), 403, 'PERMISSION_NOT_GRANTABLE', 'application access cannot be smuggled into a role');
$customRole = checkOk($asDashboard($ceo, 'POST', '/management/roles', ['name' => 'Coordonator test ' . $suffix, 'description' => 'Rol de test', 'authorityRank' => 200, 'permissions' => ['employees.view', 'production.view']]), 'CEO creates a role inside its authority', 201);
checkOk($asDashboard($root, 'PUT', "/management/employees/{$workerId}/roles", ['roleIds' => [$roleId('employee'), (int) $customRole['role']['id']]]), 'root assigns the custom role');
check($auditCount('employee.roles_changed', $workerId) === 1, 'role assignment is audited');
$workerVersion = (int) $get($root, "/management/employees/{$workerId}")['body']['authorizationVersion'];
checkOk($asDashboard($ceo, 'PATCH', "/management/roles/{$customRole['role']['id']}", ['permissions' => ['employees.view']]), 'CEO narrows the role');
check((int) $get($root, "/management/employees/{$workerId}")['body']['authorizationVersion'] > $workerVersion, 'role permission changes bump holders\' authorization version');
check($auditCount('role.updated', (string) $customRole['role']['id']) === 1, 'role permission change is audited');
checkError($asDashboard($manager, 'PATCH', "/management/employees/{$ceoId}", ['positionTitle' => 'Fost CEO']), 403, 'AUTHORITY_EXCEEDED', 'a manager cannot modify the CEO');

// ---- Root invariant -------------------------------------------------------------------------
$rootId = $me['employee']['id'];
foreach ([[$ceo, 'CEO'], [$manager, 'manager'], [$root, 'root itself']] as [$actor, $label]) {
    checkError($asDashboard($actor, 'POST', "/management/employees/{$rootId}/deactivate"), 403, 'ROOT_PROTECTED', "{$label} cannot deactivate root");
    checkError($asDashboard($actor, 'PUT', "/management/employees/{$rootId}/applications", ['applications' => ['staff']]), 403, 'ROOT_PROTECTED', "{$label} cannot remove root application access");
    checkError($asDashboard($actor, 'PUT', "/management/employees/{$rootId}/roles", ['roleIds' => []]), 403, 'ROOT_PROTECTED', "{$label} cannot change root roles");
    checkError($asDashboard($actor, 'POST', "/management/employees/{$rootId}/password-reset"), 403, 'ROOT_PROTECTED', "{$label} cannot reset the root password through the Dashboard");
}
checkError($asDashboard($root, 'POST', '/management/roles', ['name' => 'root', 'description' => null, 'authorityRank' => 999, 'permissions' => ['system.manage']]), 403, 'PERMISSION_NOT_GRANTABLE', 'no role can carry root-only authority');
check((int) $pdo->query('SELECT COUNT(*) FROM system_root_identity')->fetchColumn() === 1, 'still exactly one root');
$rootRow = $pdo->prepare('SELECT status FROM employees WHERE employee_uuid = :id');
$rootRow->execute(['id' => $rootId]);
check($rootRow->fetchColumn() === 'active', 'root remains active');

// ---- Deactivation, password reset, audit hygiene ------------------------------------------------
checkOk($asDashboard($root, 'POST', "/management/employees/{$workerId}/password-reset"), 'root resets the worker password');
checkError(T::call($kernel, 'GET', '/orders/mine', null, ['origin' => T::ORIGIN], $worker['cookie']), 401, 'SESSION_EXPIRED', 'a password reset revokes existing sessions');
checkOk($asDashboard($root, 'POST', "/management/employees/{$workerId}/deactivate"), 'root deactivates the worker');
checkError(T::call($kernel, 'POST', '/auth/login', ['username' => "worker.{$suffix}", 'password' => 'Worker passphrase 2026!'], ['origin' => T::ORIGIN]), 401, 'INVALID_CREDENTIALS', 'the old password stopped working after reset');
check($auditCount('employee.deactivated', $workerId) === 1 && $auditCount('employee.password_reset', $workerId) === 1, 'deactivation and password reset are audited');
$audit = checkOk($get($root, '/management/audit', ['targetId' => $workerId]), 'audit listing');
check(count($audit['items']) >= 6 && $audit['items'][0]['actorLabel'] !== '', 'audit events are listed newest first with actor snapshots');
$allAudit = (string) json_encode($pdo->query('SELECT metadata_json FROM iam_audit_events')->fetchAll(PDO::FETCH_COLUMN));
foreach ([$rootPassword, $rootNewPassword, $ceoCreated['temporaryPassword'], $staffCreated['temporaryPassword'], 'argon2', '$2y$'] as $secret) {
    check(!str_contains($allAudit, $secret), 'no password or hash in the IAM audit');
}
checkError($get($manager, '/management/audit'), 403, 'UNAUTHORIZED_ACTION', 'audit requires iam.audit.view');

// ---- Departments -------------------------------------------------------------------------------
$department = checkOk($asDashboard($root, 'POST', '/management/departments', ['name' => 'Test departament ' . $suffix, 'description' => null, 'parentId' => null]), 'root creates a department', 201);
$deptId = (int) $department['department']['id'];
checkOk($asDashboard($root, 'PATCH', "/management/employees/{$ceoId}", ['departmentId' => $deptId]), 'CEO moves into the new department');
checkError($asDashboard($root, 'PATCH', "/management/departments/{$deptId}", ['status' => 'inactive']), 409, 'DEPARTMENT_IN_USE', 'a department with active employees cannot be deactivated');
checkError($asDashboard($root, 'DELETE', "/management/departments/{$deptId}"), 409, 'DEPARTMENT_IN_USE', 'a used department cannot be deleted');
checkOk($asDashboard($root, 'PATCH', "/management/employees/{$ceoId}", ['departmentId' => $departmentId]), 'CEO moves back');
checkOk($asDashboard($root, 'DELETE', "/management/departments/{$deptId}"), 'an unused department can be deleted');

// ---- Hierarchy is not authorization ------------------------------------------------------------
checkOk($asDashboard($root, 'PUT', "/management/employees/{$ceoId}/manager", ['managerId' => $managerCreated['employee']['id']]), 'root sets an unusual manager relation');
checkError($asDashboard($manager, 'PATCH', "/management/employees/{$ceoId}", ['positionTitle' => 'Subordonat']), 403, 'AUTHORITY_EXCEEDED', 'being the manager of the CEO grants no authority over it');
checkError($asDashboard($root, 'PUT', "/management/employees/{$managerCreated['employee']['id']}/manager", ['managerId' => $ceoId]), 409, 'MANAGER_CYCLE', 'manager cycles are rejected');

$overview = checkOk($get($root, '/management/dashboard'), 'dashboard overview');
check($overview['counts']['activeEmployees'] >= 3 && isset($overview['counts']['dashboardUsers'], $overview['counts']['staffUsers']), 'overview counts are present');

fwrite(STDOUT, "PASS MySQL central IAM lifecycle ({$checks} checks).\n");
