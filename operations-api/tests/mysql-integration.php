<?php

declare(strict_types=1);

use Arasya\Operations\Audit\PdoAuditLogger;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Auth\PdoLoginRateLimiter;
use Arasya\Operations\Auth\PdoSessionRepository;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Security\PasswordHasher;
use Arasya\Operations\Security\SessionTokenManager;
use Arasya\Operations\Security\UsernameNormalizer;
use Arasya\Operations\Support\SystemClock;
use Arasya\Operations\Support\Uuid;

require dirname(__DIR__) . '/bootstrap.php';

if (getenv('ARASYA_TEST_DB_NAME') === false) {
    fwrite(STDOUT, "SKIP MySQL integration test: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}

$dbName = (string) getenv('ARASYA_TEST_DB_NAME');
if (!str_contains(strtolower($dbName), 'test')) {
    throw new RuntimeException('Integration tests require a database name containing "test".');
}

$config = new Config(
    'test',
    str_repeat('t', 32),
    (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1'),
    (int) (getenv('ARASYA_TEST_DB_PORT') ?: 3306),
    $dbName,
    (string) getenv('ARASYA_TEST_DB_USER'),
    (string) getenv('ARASYA_TEST_DB_PASSWORD'),
    ['http://localhost:5173'],
    36_000,
    300,
    5,
    30,
    900,
    false,
    [],
);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
(new SqlFileRunner($pdo))->run(dirname(__DIR__) . '/database/seeds/001_reference_data.sql');

$employees = new PdoEmployeeRepository($pdo);
$sessions = new PdoSessionRepository($pdo);
$passwords = new PasswordHasher();
$tokens = new SessionTokenManager(str_repeat('t', 32));
$clock = new SystemClock();
$suffix = substr(str_replace('-', '', Uuid::v4()), 0, 12);
$employee = $employees->create(
    Uuid::v4(),
    'TEST-' . strtoupper($suffix),
    'integration.' . $suffix,
    'integration.' . $suffix,
    $passwords->hash('integration passphrase 2026'),
    'Integration Employee',
    'pregatire-material',
    'employee',
    ['stage-preparation'],
    $clock->now()->format('Y-m-d H:i:s.u'),
);
$auth = new AuthenticationService(
    $employees,
    $sessions,
    new PdoLoginRateLimiter($pdo, 5, 30, 900, str_repeat('t', 32)),
    new PdoAuditLogger($pdo, str_repeat('t', 32)),
    $passwords,
    $tokens,
    new UsernameNormalizer(),
    $clock,
    36_000,
    300,
);
$login = $auth->login($employee->username, 'integration passphrase 2026', '127.0.0.1', 'mysql-integration', 'mysql-login');
if ($auth->authenticate($login->rawToken, '127.0.0.1', 'mysql-integration', 'mysql-session')->employee->employeeUuid !== $employee->employeeUuid) {
    throw new RuntimeException('Session employee mapping failed.');
}
$storedHash = $pdo->prepare('SELECT token_hash FROM auth_sessions WHERE session_id = :session_id');
$storedHash->execute(['session_id' => $login->session->sessionId]);
$databaseToken = $storedHash->fetchColumn();
if (!is_string($databaseToken) || strlen($databaseToken) !== 32 || hash_equals($databaseToken, $login->rawToken)) {
    throw new RuntimeException('Database session token storage is unsafe.');
}
$refresh = $auth->refresh($login->rawToken, '127.0.0.1', 'mysql-integration', 'mysql-refresh');
try {
    $auth->authenticate($login->rawToken, '127.0.0.1', 'mysql-integration', 'mysql-old-token');
    throw new RuntimeException('Old token remained valid after rotation.');
} catch (\Arasya\Operations\Http\ApiException $error) {
    if ($error->errorCode !== 'SESSION_EXPIRED') {
        throw $error;
    }
}
$current = $auth->authenticate($refresh->rawToken, '127.0.0.1', 'mysql-integration', 'mysql-current');
$auth->logout($current, '127.0.0.1', 'mysql-integration', 'mysql-logout');

$createDepartment = $pdo->prepare(
    "INSERT INTO departments (department_key, name, status, created_at, updated_at)
     VALUES (:department_key, :department_name, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
);
$createRole = $pdo->prepare(
    "INSERT INTO roles (role_key, name, status, created_at, updated_at)
     VALUES (:role_key, :role_name, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
);

$roleSuffix = 'role-' . $suffix;
$roleDepartment = 'department-' . $suffix;
$createDepartment->execute([
    'department_key' => $roleDepartment,
    'department_name' => 'Integration Role Department',
]);
$createRole->execute([
    'role_key' => $roleSuffix,
    'role_name' => 'Integration Role',
]);
$roleEmployee = $employees->create(
    Uuid::v4(),
    'ROLE-' . strtoupper($suffix),
    'role.' . $suffix,
    'role.' . $suffix,
    $passwords->hash('integration role passphrase'),
    'Role Status Employee',
    $roleDepartment,
    $roleSuffix,
    [],
    $clock->now()->format('Y-m-d H:i:s.u'),
);
$roleLogin = $auth->login($roleEmployee->username, 'integration role passphrase', '127.0.0.1', 'mysql-integration', 'role-login');
$pdo->prepare("UPDATE roles SET status = 'inactive' WHERE role_key = :key")->execute(['key' => $roleSuffix]);
try {
    $auth->authenticate($roleLogin->rawToken, '127.0.0.1', 'mysql-integration', 'role-inactive');
    throw new RuntimeException('Inactive role retained an authenticated session.');
} catch (\Arasya\Operations\Http\ApiException $error) {
    if ($error->errorCode !== 'ACCOUNT_INACTIVE') {
        throw $error;
    }
}

$departmentSuffix = 'department-status-' . $suffix;
$departmentRole = 'department-role-' . $suffix;
$createDepartment->execute([
    'department_key' => $departmentSuffix,
    'department_name' => 'Integration Department Status',
]);
$createRole->execute([
    'role_key' => $departmentRole,
    'role_name' => 'Integration Department Role',
]);
$departmentEmployee = $employees->create(
    Uuid::v4(),
    'DEPT-' . strtoupper($suffix),
    'department.' . $suffix,
    'department.' . $suffix,
    $passwords->hash('integration department passphrase'),
    'Department Status Employee',
    $departmentSuffix,
    $departmentRole,
    [],
    $clock->now()->format('Y-m-d H:i:s.u'),
);
$departmentLogin = $auth->login($departmentEmployee->username, 'integration department passphrase', '127.0.0.1', 'mysql-integration', 'department-login');
$pdo->prepare("UPDATE departments SET status = 'inactive' WHERE department_key = :key")->execute(['key' => $departmentSuffix]);
try {
    $auth->authenticate($departmentLogin->rawToken, '127.0.0.1', 'mysql-integration', 'department-inactive');
    throw new RuntimeException('Inactive department retained an authenticated session.');
} catch (\Arasya\Operations\Http\ApiException $error) {
    if ($error->errorCode !== 'ACCOUNT_INACTIVE') {
        throw $error;
    }
}

fwrite(STDOUT, "PASS MySQL migration, authentication and operational-status lifecycles.\n");
