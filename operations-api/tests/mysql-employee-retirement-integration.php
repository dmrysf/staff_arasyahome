<?php

declare(strict_types=1);

// Employee identity reconciliation against a dedicated MySQL/MariaDB test database: two identities with the
// same display name are inspected separately, the disabled original is reactivated with its own UUID (old
// sessions stay dead), and a duplicate is deleted only when nothing but administrative associations refer
// to it; any history keeps it disabled and is reported as the exact blocker. Nothing is re-pointed.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Employee\EmployeeRetirement;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL employee retirement integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
$config = T::config($dbName);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) {
    (new SqlFileRunner($pdo))->run($seed);
}
$pdo->exec("DELETE FROM responsibility_assignments WHERE responsibility_key = 'tailoring_quality_supervisor'");
$pdo->exec('DELETE FROM system_root_identity');

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, $label . ' HTTP ' . $r['status']); return $r['body'] ?? []; };
$fails = static function (callable $action, string $needle, string $label) use ($check): void {
    try { $action(); $check(false, "{$label}: no error"); } catch (RuntimeException $error) { $check(str_contains($error->getMessage(), $needle), "{$label}: " . $error->getMessage()); }
};
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$suffix = bin2hex(random_bytes(4));
$rootName = "retire.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-retire-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Retirement root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Retirement root passphrase 2026!');
$rootUuid = (string) $one('SELECT employee_uuid FROM system_root_identity');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'retire-' . bin2hex(random_bytes(8))], $root['cookie']);
$department = (int) $one("SELECT department_id FROM departments WHERE department_key = 'pregatire-material'");
$retirement = new EmployeeRetirement($pdo, $container->employeeAdmin(), new IamAuditLogger($pdo), $container->clock());
$password = 'retirement passphrase 2026';
$stages = ['workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control'];
$display = "VASILOAIA IULIANA {$suffix}";

// The original identity: created long ago, used, then disabled.
$original = $container->employeeAdmin()->create($display, "vas.orig.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test-retire')->employeeUuid;
$ok($asRoot('PUT', "/management/employees/{$original}/applications", ['applications' => ['staff']]), 'original Staff access');
$oldSession = T::login($kernel, "vas.orig.{$suffix}", $password);
$container->employeeAdmin()->setStatus($original, 'inactive', 'test-retire-disable');
// The replacement: created in Dashboard with the same display name, never used.
$created = $ok($asRoot('POST', '/management/employees', ['displayName' => $display, 'username' => "vas.orig1.{$suffix}", 'departmentId' => $department, 'positionTitle' => 'Șef croitorie', 'managerId' => null, 'applications' => ['staff'], 'roleIds' => [], 'stageIds' => $stages]), 'replacement created in Dashboard', 201);
$check(!str_contains(json_encode($created['employee']), 'password'), 'the created view carries no password');
$replacement = (string) $one('SELECT employee_uuid FROM employees WHERE username_normalized = ?', ["vas.orig1.{$suffix}"]);
// A second duplicate that signed in once: its history must survive.
$used = $ok($asRoot('POST', '/management/employees', ['displayName' => $display, 'username' => "vas.used.{$suffix}", 'departmentId' => $department, 'positionTitle' => null, 'managerId' => null, 'applications' => ['staff'], 'roleIds' => [], 'stageIds' => ['labeling']]), 'used duplicate', 201);
$usedUuid = (string) $one('SELECT employee_uuid FROM employees WHERE username_normalized = ?', ["vas.used.{$suffix}"]);
$ok($asRoot('POST', "/management/employees/{$usedUuid}/password-reset"), 'password reset for the used duplicate');
$pdo->prepare('UPDATE employees SET password_hash = ?, must_change_password = 0 WHERE employee_uuid = ?')->execute([$container->passwordHasher()->hash($password), $usedUuid]);
$usedSession = T::login($kernel, "vas.used.{$suffix}", $password);

// ---- Inventory: separate UUIDs, no secrets ----------------------------------------------------------------------
$a = $retirement->inventory("vas.orig.{$suffix}");
$b = $retirement->inventory("VAS.ORIG1.{$suffix}");
$check($a !== null && $b !== null && $a['employee']['employee_uuid'] === $original && $b['employee']['employee_uuid'] === $replacement && $original !== $replacement, 'the two usernames resolve to two different immutable UUIDs');
$check($a['employee']['display_name'] === $b['employee']['display_name'], 'the display names are identical, so nothing may be matched by name');
$check($a['employee']['status'] === 'inactive' && $b['employee']['status'] === 'active' && $a['stages'] === $b['stages'] && $a['applications'] === ['staff'], 'status, stages and applications are reported per identity');
$check(!str_contains(json_encode([$a, $b]), 'password_hash') && !str_contains(json_encode([$a, $b]), '$argon') && !str_contains(json_encode([$a, $b]), '$2y$'), 'the inventory never contains a password hash');
$check(isset($a['references']['auth_audit_events.employee_uuid']) && !isset($b['references']['auth_audit_events.employee_uuid']), 'history references are found from the live schema: ' . json_encode([$a['references'], $b['references']]));
$check($retirement->inventory("nobody.{$suffix}") === null, 'an unknown username is reported as missing');

// ---- Reactivate the original with its UUID; old sessions stay revoked -------------------------------------------
$ok(T::call($kernel, 'GET', '/auth/session', null, [], $oldSession['cookie']), 'old session', 401);
$container->employeeAdmin()->setStatus($original, 'active', 'test-retire-enable');
$check((string) $one('SELECT status FROM employees WHERE employee_uuid = ?', [$original]) === 'active', 'the original identity is active again');
$check(T::call($kernel, 'GET', '/auth/session', null, [], $oldSession['cookie'])['status'] === 401, 'a session revoked at deactivation is never revived');
$again = T::login($kernel, "vas.orig.{$suffix}", $password);
$check($ok(T::call($kernel, 'GET', '/auth/session', null, [], $again['cookie']), 'new session')['employee']['employeeUuid'] === $original, 'the original signs in with its own UUID and existing password');

// ---- Guards ---------------------------------------------------------------------------------------------------
$fails(static fn () => $retirement->plan($replacement, "vas.orig.{$suffix}"), 'do not belong to the same identity', 'a UUID with another username is refused');
$fails(static fn () => $retirement->plan($rootUuid, $rootName), 'root identity is protected', 'root is refused');
$ok($asRoot('PUT', '/management/organization/ceo', ['employeeId' => $usedUuid]), 'temporary principal on the used duplicate');
$pdo->prepare("INSERT INTO responsibility_assignments (assignment_uuid, responsibility_key, employee_uuid, starts_at, ends_at, note, created_at, created_by_employee_uuid) VALUES (UUID(), 'tailoring_intake_responsible', ?, UTC_TIMESTAMP(6), NULL, NULL, UTC_TIMESTAMP(6), ?)")->execute([$usedUuid, $rootUuid]);
$fails(static fn () => $retirement->disable($usedUuid, "vas.used.{$suffix}", 'test'), 'active responsibility', 'an identity with an active responsibility must be released in Dashboard first');
$pdo->prepare("UPDATE responsibility_assignments SET revoked_at = UTC_TIMESTAMP(6), revoked_by_employee_uuid = ?, revoke_reason = 'test' WHERE employee_uuid = ?")->execute([$rootUuid, $usedUuid]);
$pdo->exec("DELETE FROM organization_principals WHERE principal_key = 'ceo'");

// ---- Used duplicate: disabled, sessions revoked, deletion blocked by its history ---------------------------------
$historyBefore = (int) $one('SELECT COUNT(*) FROM auth_audit_events WHERE employee_uuid = ?', [$usedUuid]);
$disabled = $retirement->disable($usedUuid, "vas.used.{$suffix}", 'test-retire-used');
$check($disabled['status'] === 'inactive' && !$disabled['deletable'] && isset($disabled['blockers']['auth_sessions.employee_uuid'], $disabled['blockers']['auth_audit_events.employee_uuid'], $disabled['blockers']['responsibility_assignments.employee_uuid']), 'the exact history references block deletion: ' . json_encode($disabled['blockers']));
$check(T::call($kernel, 'GET', '/auth/session', null, [], $usedSession['cookie'])['status'] === 401, 'its sessions are revoked');
$fails(static fn () => $retirement->delete($usedUuid, "vas.used.{$suffix}", sys_get_temp_dir() . "/retire-{$suffix}", 'test'), 'blocked by history references', 'deletion is refused, never forced');
$check((int) $one('SELECT COUNT(*) FROM employees WHERE employee_uuid = ?', [$usedUuid]) === 1 && (int) $one('SELECT COUNT(*) FROM auth_audit_events WHERE employee_uuid = ?', [$usedUuid]) >= $historyBefore, 'the identity and its history remain');

// ---- Unused replacement: verified backup, then a narrow audited deletion -----------------------------------------
$plan = $retirement->plan($replacement, "vas.orig1.{$suffix}");
$check($plan['deletable'] && array_keys($plan['references']) === ['employee_application_access.employee_uuid', 'employee_stage_access.employee_uuid'], 'only administrative associations refer to the replacement: ' . json_encode($plan['references']));
$probe = $container->employeeAdmin()->create("Probe {$suffix}", "probe.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test-retire');
$check(!$retirement->disable($probe->employeeUuid, "probe.{$suffix}", 'test-retire-probe')['deletable'], 'disabling writes authentication history, so a disabled identity is kept, never deleted');
$backupDir = sys_get_temp_dir() . "/arasya-retire-test-{$suffix}";
$createdAudit = (int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE target_id = ? AND action = 'employee.created'", [$replacement]);
$deleted = $retirement->delete($replacement, "vas.orig1.{$suffix}", $backupDir, 'test-retire-delete');
$check(is_file($deleted['backup']) && (fileperms($deleted['backup']) & 0777) === 0600 && (fileperms($backupDir) & 0777) === 0700 && hash_file('sha256', $deleted['backup']) === $deleted['sha256'], 'the backup is written, access-restricted and verified');
$backup = (string) file_get_contents($deleted['backup']);
$check(!str_contains($backup, 'password_hash') && str_contains($backup, $replacement) && str_contains($backup, 'side-hem'), 'the backup holds the identity and its associations, never the password hash');
$check((int) $one('SELECT COUNT(*) FROM employees WHERE employee_uuid = ?', [$replacement]) === 0 && $deleted['removed']['employee_stage_access'] === 10 && $deleted['removed']['employee_application_access'] === 1, 'the replacement and only its administrative associations are gone');
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE target_id = ? AND action = 'employee.created'", [$replacement]) === $createdAudit && $createdAudit === 1, 'the permanent IAM audit of its creation remains');
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE target_id = ? AND action = 'employee.deleted' AND actor_type = 'cli'", [$replacement]) === 1, 'the deletion is audited');
$check($retirement->inventory("vas.orig1.{$suffix}") === null && $retirement->inventory("vas.orig.{$suffix}")['employee']['employee_uuid'] === $original, 'exactly one usable identity remains for the person');
$check((int) $one("SELECT COUNT(*) FROM employees WHERE display_name = ? AND status = 'active'", [$display]) === 1, 'one active identity carries the name');
array_map('unlink', glob($backupDir . '/*') ?: []);
rmdir($backupDir);

fwrite(STDOUT, "OK MySQL employee retirement integration ({$checks} checks)\n");
