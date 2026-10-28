<?php

declare(strict_types=1);

// Staff interface language per identity (migration 027) against a dedicated MySQL/MariaDB test database: the default
// is Romanian; the language follows the account into every session (login, session read, refresh, a second device);
// only the audited IAM path or the employee's own choice (PUT /auth/locale) changes it; it grants nothing and leaves authorization, sessions and passwords untouched;
// the CLI refuses a UUID/username mismatch before anything is written; and API code reads Romanian before 027 runs.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL Staff locale integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
$config = T::config($dbName);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) {
    (new SqlFileRunner($pdo))->run($seed);
}
$pdo->exec('DELETE FROM system_root_identity');

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$suffix = bin2hex(random_bytes(4));
$rootName = "locale.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-locale-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$check(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Locale root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie'])['status'] === 200, 'root password changed');
$root = T::login($kernel, $rootName, 'Locale root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'locale-' . bin2hex(random_bytes(8))], $root['cookie']);

$password = 'locale passphrase 2026';
$stages = ['workshop-receiving', 'labeling'];
$make = static function (string $name) use ($container, $password, $stages, $asRoot, $check, $suffix): string {
    $uuid = $container->employeeAdmin()->create(strtoupper($name) . " {$suffix}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test-locale')->employeeUuid;
    $check($asRoot('PUT', "/management/employees/{$uuid}/applications", ['applications' => ['staff']])['status'] === 200, "{$name}: Staff access");
    return $uuid;
};
$turkish = $make('turkish');
$sinhala = $make('sinhala');
$english = $make('english');
$other = $make('other');

// ---- Default: Romanian for every identity, before and after the column exists ----------------------------------
$session = T::login($kernel, "turkish.{$suffix}", $password);
$read = T::call($kernel, 'GET', '/auth/session', null, [], $session['cookie']);
$check($read['status'] === 200 && $read['body']['employee']['locale'] === 'ro', 'an identity without a language reads Romanian');
$check($one('SELECT staff_locale FROM employees WHERE employee_uuid = ?', [$turkish]) === null, 'nothing is stored for the default');
$view = $asRoot('GET', "/management/employees/{$other}");
$check($view['status'] === 200 && $view['body']['staffLocale'] === 'ro', 'the IAM view reports the default language');

// ---- Assignment through the audited IAM route ----------------------------------------------------------------
$before = $pdo->prepare('SELECT employee_uuid, authorization_version, password_hash, must_change_password, status, role_id, department_id FROM employees WHERE employee_uuid IN (?, ?, ?, ?) ORDER BY employee_uuid');
$before->execute([$turkish, $sinhala, $english, $other]);
$snapshot = $before->fetchAll(PDO::FETCH_ASSOC);
$stageRows = static fn (): array => $pdo->query('SELECT employee_uuid, stage_id FROM employee_stage_access ORDER BY employee_uuid, stage_id')->fetchAll(PDO::FETCH_ASSOC);
$roleRows = static fn (): array => $pdo->query('SELECT employee_uuid, role_id FROM employee_role_assignments ORDER BY employee_uuid, role_id')->fetchAll(PDO::FETCH_ASSOC);
$appRows = static fn (): array => $pdo->query('SELECT employee_uuid, application_key FROM employee_application_access ORDER BY employee_uuid, application_key')->fetchAll(PDO::FETCH_ASSOC);
$stagesBefore = $stageRows();
$rolesBefore = $roleRows();
$appsBefore = $appRows();
$liveSessions = (int) $one('SELECT COUNT(*) FROM auth_sessions WHERE employee_uuid = ? AND revoked_at IS NULL', [$turkish]);

$set = $asRoot('PUT', "/management/employees/{$turkish}/staff-locale", ['locale' => 'tr']);
$check($set['status'] === 200 && $set['body']['staffLocale'] === 'tr', 'root assigns Turkish');
$check($asRoot('PUT', "/management/employees/{$sinhala}/staff-locale", ['locale' => 'si'])['body']['staffLocale'] === 'si', 'root assigns Sinhala');
$check($asRoot('PUT', "/management/employees/{$english}/staff-locale", ['locale' => 'en'])['body']['staffLocale'] === 'en', 'root assigns English');
$audit = $pdo->prepare("SELECT metadata_json FROM iam_audit_events WHERE action = 'employee.staff_locale_changed' AND target_id = ?");
$audit->execute([$turkish]);
$events = $audit->fetchAll(PDO::FETCH_COLUMN);
$check(count($events) === 1 && json_decode((string) $events[0], true)['staffLocale'] == ['before' => null, 'after' => 'tr'], 'the change is audited with before and after');
$asRoot('PUT', "/management/employees/{$turkish}/staff-locale", ['locale' => 'tr']);
$audit->execute([$turkish]);
$check(count($audit->fetchAll(PDO::FETCH_COLUMN)) === 1, 'an unchanged value writes and audits nothing');

// ---- The language grants nothing and changes nothing else -----------------------------------------------------
$before->execute([$turkish, $sinhala, $english, $other]);
$check($before->fetchAll(PDO::FETCH_ASSOC) === $snapshot, 'authorization version, password, status, role and department are unchanged');
$check($stageRows() === $stagesBefore && $roleRows() === $rolesBefore && $appRows() === $appsBefore, 'stages, roles and applications are unchanged');
$check((int) $one('SELECT COUNT(*) FROM auth_sessions WHERE employee_uuid = ? AND revoked_at IS NULL', [$turkish]) === $liveSessions, 'no session was revoked');
$check($one('SELECT staff_locale FROM employees WHERE employee_uuid = ?', [$other]) === null, 'the other identity keeps the default');

// ---- The language follows the account into every session ------------------------------------------------------
$read = T::call($kernel, 'GET', '/auth/session', null, [], $session['cookie']);
$check($read['status'] === 200 && $read['body']['employee']['locale'] === 'tr', 'the open session reads Turkish on its next session read');
$refresh = T::call($kernel, 'POST', '/auth/refresh', null, ['x-csrf-token' => $session['csrf']], $session['cookie']);
$check($refresh['status'] === 200 && ($refresh['body']['employee']['locale'] ?? null) === 'tr', 'a refreshed session keeps Turkish');
$secondDevice = T::call($kernel, 'POST', '/auth/login', ['username' => "turkish.{$suffix}", 'password' => $password]);
$check($secondDevice['status'] === 200 && $secondDevice['body']['employee']['locale'] === 'tr', 'a login from another device starts in Turkish');
$check(T::call($kernel, 'POST', '/auth/login', ['username' => "sinhala.{$suffix}", 'password' => $password])['body']['employee']['locale'] === 'si', 'the Sinhala identity starts in Sinhala');
$check(T::call($kernel, 'POST', '/auth/login', ['username' => "english.{$suffix}", 'password' => $password])['body']['employee']['locale'] === 'en', 'the English identity starts in English');
$check(T::call($kernel, 'POST', '/auth/login', ['username' => "other.{$suffix}", 'password' => $password])['body']['employee']['locale'] === 'ro', 'any other identity starts in Romanian');

// ---- Validation, protection, authority --------------------------------------------------------------------------
foreach (['de', 'TR', 'tr-TR', '', 7, true] as $invalid) {
    $check($asRoot('PUT', "/management/employees/{$other}/staff-locale", ['locale' => $invalid])['status'] === 422, 'an unsupported language is refused: ' . json_encode($invalid));
}
$check($asRoot('PUT', "/management/employees/{$other}/staff-locale", ['language' => 'tr'])['status'] === 400, 'an unknown field is refused');
$rootUuid = (string) $one('SELECT employee_uuid FROM system_root_identity');
$check($asRoot('PUT', "/management/employees/{$rootUuid}/staff-locale", ['locale' => 'tr'])['status'] === 403, 'the root identity is protected');
$worker = T::login($kernel, "other.{$suffix}", $password);
$denied = T::call($kernel, 'PUT', "/management/employees/{$turkish}/staff-locale", ['locale' => 'ro'], ['x-csrf-token' => $worker['csrf'], 'idempotency-key' => 'locale-worker-' . $suffix], $worker['cookie']);
$check(in_array($denied['status'], [403, 404], true) && $one('SELECT staff_locale FROM employees WHERE employee_uuid = ?', [$turkish]) === 'tr', 'an employee without IAM rights cannot change a language');
$check($asRoot('PUT', "/management/employees/{$english}/staff-locale", ['locale' => null])['body']['staffLocale'] === 'ro', 'null returns an identity to the default');
$check($one('SELECT staff_locale FROM employees WHERE employee_uuid = ?', [$english]) === null, 'the default is stored as NULL');
$asRoot('PUT', "/management/employees/{$english}/staff-locale", ['locale' => 'en']);
try {
    $pdo->prepare('UPDATE employees SET staff_locale = ? WHERE employee_uuid = ?')->execute(['de', $other]);
    $check(false, 'the database refuses an unsupported language');
} catch (PDOException) {
    $check(true, 'the database refuses an unsupported language');
}

// ---- Self-service (API 2.49.0): the employee chooses the language of their own account ------------------------
$self = T::login($kernel, "turkish.{$suffix}", $password);
$asSelf = static fn (?array $body, ?string $csrf = null): array => T::call($kernel, 'PUT', '/auth/locale', $body, ['x-csrf-token' => $csrf ?? $self['csrf']], $self['cookie']);
$selfAudit = static fn (): int => (int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'employee.staff_locale_changed' AND target_id = ?", [$turkish]);
$before->execute([$turkish, $sinhala, $english, $other]);
$snapshot = $before->fetchAll(PDO::FETCH_ASSOC);
$stagesBefore = $stageRows();
$rolesBefore = $roleRows();
$appsBefore = $appRows();
$liveSessions = (int) $one('SELECT COUNT(*) FROM auth_sessions WHERE employee_uuid = ? AND revoked_at IS NULL', [$turkish]);
$auditsBefore = $selfAudit();
$switched = $asSelf(['locale' => 'ro']);
$check($switched['status'] === 200 && $switched['body']['employee']['locale'] === 'ro' && ($switched['body']['csrfToken'] ?? '') === $self['csrf'], 'the employee switches their own account to Romanian and keeps the session');
$check($one('SELECT staff_locale FROM employees WHERE employee_uuid = ?', [$turkish]) === 'ro', 'the own choice is stored');
$row = $pdo->prepare("SELECT actor_employee_uuid, actor_type, metadata_json FROM iam_audit_events WHERE action = 'employee.staff_locale_changed' AND target_id = ? ORDER BY created_at DESC, event_id DESC LIMIT 1");
$row->execute([$turkish]);
$event = $row->fetch(PDO::FETCH_ASSOC);
$check($selfAudit() === $auditsBefore + 1 && $event['actor_employee_uuid'] === $turkish && $event['actor_type'] === 'employee' && json_decode((string) $event['metadata_json'], true) == ['staffLocale' => ['before' => 'tr', 'after' => 'ro'], 'self' => true], 'the own change is audited with the employee as actor');
$check(T::call($kernel, 'GET', '/auth/session', null, [], $self['cookie'])['body']['employee']['locale'] === 'ro', 'the next session read follows the own choice');
$back = $asSelf(['locale' => 'tr']);
$check($back['status'] === 200 && $back['body']['employee']['locale'] === 'tr' && $selfAudit() === $auditsBefore + 2, 'the employee switches back to Turkish');
$check($asSelf(['locale' => 'tr'])['status'] === 200 && $selfAudit() === $auditsBefore + 2, 'choosing the language in effect writes and audits nothing');
$plain = T::login($kernel, "other.{$suffix}", $password);
$check(T::call($kernel, 'PUT', '/auth/locale', ['locale' => 'si'], ['x-csrf-token' => $plain['csrf']], $plain['cookie'])['status'] === 200 && T::call($kernel, 'PUT', '/auth/locale', ['locale' => 'ro'], ['x-csrf-token' => $plain['csrf']], $plain['cookie'])['status'] === 200, 'another employee switches between languages too');
$check($one('SELECT staff_locale FROM employees WHERE employee_uuid = ?', [$other]) === 'ro', 'choosing Romanian after another language stores ro');
$check(T::call($kernel, 'PUT', '/auth/locale', ['locale' => 'ro'], ['x-csrf-token' => $plain['csrf']], $plain['cookie'])['status'] === 200 && (int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'employee.staff_locale_changed' AND target_id = ?", [$other]) === 2, 'the repeated choice writes nothing');
$pdo->prepare('UPDATE employees SET staff_locale = NULL WHERE employee_uuid = ?')->execute([$other]);
$check(T::call($kernel, 'PUT', '/auth/locale', ['locale' => 'ro'], ['x-csrf-token' => $plain['csrf']], $plain['cookie'])['status'] === 200 && $one('SELECT staff_locale FROM employees WHERE employee_uuid = ?', [$other]) === null, 'Romanian over the NULL default keeps NULL');
foreach (['de', 'TR', 'tr-TR', '', 7, true, null] as $invalid) {
    $check($asSelf(['locale' => $invalid])['status'] === 422, 'an unsupported own language is refused: ' . json_encode($invalid));
}
$check($asSelf(['locale' => 'ro', 'employeeId' => $other])['status'] === 400 && $asSelf(['language' => 'ro'])['status'] === 400, 'unknown fields are refused (no other target)');
$check($asSelf(['locale' => 'ro'], 'wrong-csrf')['status'] === 403, 'the CSRF token is required');
$check(T::call($kernel, 'PUT', '/auth/locale', ['locale' => 'ro'], [], null)['status'] === 401, 'no session, no change');
$check($one('SELECT staff_locale FROM employees WHERE employee_uuid = ?', [$turkish]) === 'tr', 'refused calls wrote nothing');
$before->execute([$turkish, $sinhala, $english, $other]);
$check($before->fetchAll(PDO::FETCH_ASSOC) === $snapshot, 'own language: authorization version, password, status, role and department are unchanged');
$check($stageRows() === $stagesBefore && $roleRows() === $rolesBefore && $appRows() === $appsBefore, 'own language: stages, roles and applications are unchanged');
$check((int) $one('SELECT COUNT(*) FROM auth_sessions WHERE employee_uuid = ? AND revoked_at IS NULL', [$turkish]) === $liveSessions, 'own language: no session was revoked');
$dashboardOnly = $container->employeeAdmin()->create("DASH {$suffix}", "dash.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test-locale')->employeeUuid;
$check($asRoot('PUT', "/management/employees/{$dashboardOnly}/applications", ['applications' => ['dashboard']])['status'] === 200, 'dash: Dashboard access only');
$dash = T::login($kernel, "dash.{$suffix}", $password);
$check(T::call($kernel, 'PUT', '/auth/locale', ['locale' => 'tr'], ['x-csrf-token' => $dash['csrf']], $dash['cookie'])['status'] === 403 && $one('SELECT staff_locale FROM employees WHERE employee_uuid = ?', [$dashboardOnly]) === null, 'an identity without Staff access cannot set a Staff language');

// ---- CLI: read-only plan, exact UUID+username match, one audited transaction -----------------------------------
$cli = static function (array $arguments) use ($dbName): array {
    // The CLI reads its configuration like production does; here it is pointed at the test database only.
    $home = sys_get_temp_dir() . '/arasya-locale-cli-' . bin2hex(random_bytes(4));
    mkdir($home, 0700);
    $environment = ['HOME' => $home, 'PATH' => (string) getenv('PATH'), 'ARASYA_APP_SECRET' => str_repeat('t', 32), 'ARASYA_ALLOWED_ORIGINS' => 'https://staff.example.test',
        'ARASYA_DB_HOST' => (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1'), 'ARASYA_DB_PORT' => (string) (getenv('ARASYA_TEST_DB_PORT') ?: 3306),
        'ARASYA_DB_NAME' => $dbName, 'ARASYA_DB_USER' => (string) getenv('ARASYA_TEST_DB_USER'), 'ARASYA_DB_PASSWORD' => (string) getenv('ARASYA_TEST_DB_PASSWORD')];
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/set-employee-locale.php', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $code = proc_close($process);
    rmdir($home);
    return ['code' => $code, 'out' => json_decode((string) $out, true), 'err' => trim((string) $err)];
};
$plan = $cli(["--assign={$other},other.{$suffix},si"]);
$check($plan['code'] === 0 && $plan['out']['applied'] === false && $plan['out']['plan'][0]['after'] === 'si', 'the CLI prints a read-only plan by default: ' . $plan['err']);
$check($one('SELECT staff_locale FROM employees WHERE employee_uuid = ?', [$other]) === null, 'the plan writes nothing');
$mismatch = $cli(["--assign={$other},other.{$suffix},si", "--assign={$turkish},english.{$suffix},en", '--apply']);
$check($mismatch['code'] === 1 && $mismatch['out']['applied'] === false && $mismatch['out']['problems'][0]['problem'] === 'USERNAME_MISMATCH', 'a UUID/username mismatch refuses the whole run');
$check($one('SELECT staff_locale FROM employees WHERE employee_uuid = ?', [$other]) === null && $one('SELECT staff_locale FROM employees WHERE employee_uuid = ?', [$turkish]) === 'tr', 'nothing was written by the refused run');
$check($cli(["--assign={$rootUuid},{$rootName},tr", '--apply'])['out']['problems'][0]['problem'] === 'ROOT_PROTECTED', 'the CLI refuses the root identity');
$applied = $cli(["--assign={$other},OTHER.{$suffix},si", '--apply']);
$check($applied['code'] === 0 && $applied['out']['applied'] === true && $applied['out']['sessionLocale'][$other] === 'si', 'the CLI applies through the audited IAM path: ' . $applied['err']);
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'employee.staff_locale_changed' AND target_id = ? AND actor_type = 'root'", [$other]) === 1, 'the CLI change is audited as root');
$check(!str_contains(json_encode($applied['out']), 'password'), 'the CLI output carries no secret');
$again = $cli(["--assign={$other},other.{$suffix},si", '--apply']);
$check($again['code'] === 0 && $again['out']['plan'][0]['change'] === false && (int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'employee.staff_locale_changed' AND target_id = ? AND actor_type = 'root'", [$other]) === 1, 'a repeated CLI run changes and audits nothing');
$check($cli(['--assign=not-a-uuid,x,tr'])['err'] === 'LOCALE_INVALID_OPTIONS', 'malformed options are refused');
$check($cli(["--assign={$other},other.{$suffix},de"])['err'] === 'LOCALE_INVALID_OPTIONS', 'an unsupported CLI language is refused');

// ---- Code activated before migration 027 still logs everyone in, in Romanian ----------------------------------
$pdo->exec('ALTER TABLE employees DROP CONSTRAINT chk_employees_staff_locale');
$pdo->exec('ALTER TABLE employees DROP COLUMN staff_locale');
$pdo->exec("DELETE FROM schema_migrations WHERE migration_name = '027_employee_staff_locale.sql'");
$legacy = (new PdoEmployeeRepository($pdo))->findByUuid($turkish);
$check($legacy !== null && $legacy->staffLocale === 'ro', 'without the column every identity reads Romanian');
$check((new Container($config, $pdo))->kernel()->handle(new \Arasya\Operations\Http\Request('POST', '/auth/login', ['origin' => T::ORIGIN, 'content-type' => 'application/json', 'user-agent' => 'operations-integration'], [], json_encode(['username' => "turkish.{$suffix}", 'password' => $password]), '127.0.0.1', 'operations-integration', 'test-legacy', []))->status === 200, 'login works before migration 027');
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
$check($one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'staff_locale'") == 1, 'migration 027 re-applies cleanly');

printf("PASS MySQL Staff locale integration: %d checks.\n", $checks);
