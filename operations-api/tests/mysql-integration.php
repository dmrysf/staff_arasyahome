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
use Arasya\Operations\Production\PdoProductionWorkflowRepository;
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
$migrationRunner = new MigrationRunner($pdo);
$lockOwner = Connection::create($config);
$lockHeld = (int) $lockOwner->query("SELECT GET_LOCK('arasya_operations_migration', 0)")->fetchColumn() === 1;
if (!$lockHeld) {
    throw new RuntimeException('Could not establish the migration lock test fixture.');
}
try {
    try {
        $migrationRunner->migrate(dirname(__DIR__) . '/database/migrations');
        throw new RuntimeException('A second migration owner unexpectedly acquired the advisory lock.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'lock')) {
            throw $error;
        }
    }
} finally {
    $lockOwner->query("SELECT RELEASE_LOCK('arasya_operations_migration')")->fetchColumn();
    $lockOwner = null;
}
$migrationFixture = sys_get_temp_dir() . '/arasya-migration-001-' . bin2hex(random_bytes(6));
mkdir($migrationFixture, 0700, true);
copy(dirname(__DIR__) . '/database/migrations/001_auth_foundation.sql', $migrationFixture . '/001_auth_foundation.sql');
try {
    $migrationRunner->migrate($migrationFixture);
    $pdo->exec('DROP TABLE IF EXISTS order_projection_receipts');
    $pdo->exec('DROP TABLE IF EXISTS employee_order_relations');
    $pdo->exec('DROP TABLE IF EXISTS operational_order_items');
    $pdo->exec('DROP TABLE IF EXISTS operational_orders');
    $pdo->exec('DROP TABLE IF EXISTS order_sources');
    $pdo->exec('DROP TABLE IF EXISTS production_stages');
    $pdo->exec('DROP TABLE IF EXISTS production_workflows');
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name = '002_canonical_production_workflow.sql'");
    $pdo->exec("DELETE FROM schema_migrations WHERE migration_name = '003_operational_orders.sql'");
    $applied = $migrationRunner->migrate(dirname(__DIR__) . '/database/migrations');
    if ($applied !== ['002_canonical_production_workflow.sql', '003_operational_orders.sql']) {
        throw new RuntimeException('An existing 001 schema did not apply only migrations 002 and 003.');
    }
    if ($migrationRunner->migrate(dirname(__DIR__) . '/database/migrations') !== []) {
        throw new RuntimeException('A second migration run was not idempotent.');
    }
} finally {
    unlink($migrationFixture . '/001_auth_foundation.sql');
    rmdir($migrationFixture);
}

$seedRunner = new SqlFileRunner($pdo);
$seedFiles = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seedFiles, SORT_STRING);
foreach ($seedFiles as $seedFile) {
    $seedRunner->run($seedFile);
}

$expectedStages = [
    [1, 'waiting', 'În așteptare'],
    [2, 'material-preparation', 'Pregătire material'],
    [3, 'workshop-receiving', 'Primire atelier'],
    [4, 'labeling', 'Etichetare'],
    [5, 'material-straightening', 'Îndreptare material'],
    [6, 'bottom-hem', 'Tivul de jos'],
    [7, 'side-hem', 'Tivul lateral'],
    [8, 'ironing', 'Călcare'],
    [9, 'height', 'Înălțime'],
    [10, 'header-tape', 'Rejansă'],
    [11, 'sewing-finishing', 'Finisare coasere'],
    [12, 'quality-control', 'Control calitate'],
    [13, 'packing', 'Împachetare'],
    [14, 'delivery', 'Livrare'],
];
$workflow = (new PdoProductionWorkflowRepository($pdo))->current();
if ($workflow === null || $workflow->id !== 'curtain-production' || $workflow->version !== 1) {
    throw new RuntimeException('Canonical production workflow was not seeded.');
}
if (array_map(static fn ($stage): array => [$stage->ordinal, $stage->id, $stage->label], $workflow->stages) !== $expectedStages) {
    throw new RuntimeException('Canonical production stages do not match the required 14-stage catalog.');
}

$pdo->exec("UPDATE production_stages SET display_name = 'Verificare calitate' WHERE stage_id = 'quality-control'");
$pdo->exec("UPDATE production_workflows SET name = 'Flux administrat' WHERE workflow_key = 'curtain-production'");
$seedRunner->run(dirname(__DIR__) . '/database/seeds/002_production_workflow.sql');
if ($pdo->query("SELECT display_name FROM production_stages WHERE stage_id = 'quality-control'")->fetchColumn() !== 'Verificare calitate'
    || $pdo->query("SELECT name FROM production_workflows WHERE workflow_key = 'curtain-production'")->fetchColumn() !== 'Flux administrat') {
    throw new RuntimeException('Repeated workflow seed execution overwrote mutable catalog data.');
}
$pdo->exec("UPDATE production_stages SET display_name = 'Control calitate' WHERE stage_id = 'quality-control'");
$pdo->exec("UPDATE production_workflows SET name = 'Flux producție Arasya' WHERE workflow_key = 'curtain-production'");

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
    ['material-preparation'],
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

$pdo->prepare('INSERT IGNORE INTO employee_stage_access (employee_uuid, stage_id, created_at) VALUES (:employee_uuid, :stage_id, UTC_TIMESTAMP(6))')->execute([
    'employee_uuid' => $employee->employeeUuid,
    'stage_id' => 'cutting',
]);
$reloadedEmployee = $employees->findByUuid($employee->employeeUuid);
if ($reloadedEmployee === null || $reloadedEmployee->allowedStageIds !== ['material-preparation']) {
    throw new RuntimeException('Legacy employee stage access was not filtered against the active canonical workflow.');
}

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

fwrite(STDOUT, "PASS MySQL ordered migrations, idempotent workflow seed, canonical stage access, authentication and operational-status lifecycles.\n");

$writer = new \Arasya\Operations\Order\OrderProjectionWriter($pdo, $clock);
$sourceKey = 'trendhome';
$globalIdStr = "$sourceKey:TH100";

// 1. First projection (Applied)
$snapshot1 = new \Arasya\Operations\Order\SourceOrderSnapshot(
    $sourceKey,
    'TH100',
    'ev-101',
    1,
    new DateTimeImmutable('2026-08-19T10:00:00.000Z'),
    '#100',
    'material-preparation',
    'processing',
    'Processing',
    'Notes',
    'in_progress',
    new DateTimeImmutable('2026-08-19T10:00:00.000Z'),
    [
        new \Arasya\Operations\Order\OperationalOrderItem(\Arasya\Operations\Support\Uuid::v4(), 'item-1', 1, 'Item 1', 'P1', null, null, 1.5, 2.5, 'm', 3.0, 2)
    ]
);

if ($writer->apply($snapshot1) !== 'applied') {
    throw new RuntimeException('First projection should be applied.');
}

// 2. Duplicate Projection (Duplicate)
if ($writer->apply($snapshot1) !== 'duplicate') {
    throw new RuntimeException('Duplicate projection should return duplicate.');
}

// 3. Different Event ID, Same Payload (Duplicate)
$snapshot3 = clone $snapshot1;
$snapshot3->sourceEventId = 'ev-102';
if ($writer->apply($snapshot3) !== 'duplicate') {
    throw new RuntimeException('Different event same payload should return duplicate.');
}

// 4. Same Event ID, Different Payload (Conflict)
$snapshot4 = clone $snapshot1;
$snapshot4->orderNumber = '#100-changed';
try {
    $writer->apply($snapshot4);
    throw new RuntimeException('Same event different payload should throw SOURCE_EVENT_CONFLICT.');
} catch (\Arasya\Operations\Http\ApiException $e) {
    if ($e->errorCode !== 'SOURCE_EVENT_CONFLICT') throw $e;
}

// 5. Revision Conflict (Same timestamp, different payload)
$snapshot5 = clone $snapshot1;
$snapshot5->sourceEventId = 'ev-103';
$snapshot5->orderNumber = '#100-changed';
try {
    $writer->apply($snapshot5);
    throw new RuntimeException('Revision conflict should throw SOURCE_REVISION_CONFLICT.');
} catch (\Arasya\Operations\Http\ApiException $e) {
    if ($e->errorCode !== 'SOURCE_REVISION_CONFLICT') throw $e;
}

// 6. Out of Order Update (Older timestamp)
$snapshot6 = clone $snapshot1;
$snapshot6->sourceEventId = 'ev-104';
$snapshot6->sourceChangedAt = new DateTimeImmutable('2026-08-19T09:00:00.000Z');
if ($writer->apply($snapshot6) !== 'out_of_order') {
    throw new RuntimeException('Out of order projection should return out_of_order.');
}

// 7. Successful Update preserving Item UUIDs
$snapshot7 = clone $snapshot1;
$snapshot7->sourceEventId = 'ev-105';
$snapshot7->sourceChangedAt = new DateTimeImmutable('2026-08-19T11:00:00.000Z');
$snapshot7->orderNumber = '#100-updated';
// Create items with completely different UUIDs but same source_item_id
$snapshot7->items = [
    new \Arasya\Operations\Order\OperationalOrderItem(\Arasya\Operations\Support\Uuid::v4(), 'item-1', 1, 'Item 1 Updated', 'P1', null, null, 1.5, 2.5, 'm', 3.0, 2),
    new \Arasya\Operations\Order\OperationalOrderItem(\Arasya\Operations\Support\Uuid::v4(), 'item-2', 2, 'Item 2', 'P2', null, null, null, null, null, null, 1)
];

if ($writer->apply($snapshot7) !== 'applied') {
    throw new RuntimeException('Valid update should be applied.');
}

$stmt = $pdo->prepare('SELECT item_uuid FROM operational_order_items WHERE source_item_id = ?');
$stmt->execute(['item-1']);
if ($stmt->fetchColumn() !== $snapshot1->items[0]->itemUuid) {
    throw new RuntimeException('Item UUID was not preserved across update.');
}

fwrite(STDOUT, "PASS OrderProjectionWriter semantics and item UUID preservation.\n");
