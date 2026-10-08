<?php

declare(strict_types=1);

// Employee onboarding (API 2.23.0) against a dedicated MySQL/MariaDB test database: the owner-authorized
// onboarding plan applied by the root CLI provisioner (dry run, conflicts, idempotent apply, one identity per
// person, name-based usernames, inactive-by-default identities, confirmed managers without cycles, department
// oversight, custom finance roles, the only authorized grants, the CEO principal distinct from root, DR7/DR9 and
// Germany safeguards), the private credential file, credentials absent from audit records, the forced first-login
// password change with its policy and session rotation, the password-change rate limit, deactivation and
// recovery, and the TEST first-login probe. Every person name carries a per-run token so runs never collide.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\CredentialFile;
use Arasya\Operations\Iam\CredentialSink;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Management\OrganizationOnboarding;
use Arasya\Operations\Management\OrganizationProvisioner;
use Arasya\Operations\Management\OrganizationReconciler;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL organization onboarding integration: ARASYA_TEST_DB_NAME is not configured.\n");
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
function checkOk(array $response, string $message, int $status = 200): array
{
    check($response['status'] === $status, "{$message} (got {$response['status']} " . json_encode($response['body']) . ')');
    return $response['body'] ?? [];
}
function refused(callable $action): string
{
    try {
        $action();
    } catch (RuntimeException $error) {
        return $error->getMessage();
    }
    return '';
}

/** Collects credentials in memory, like the private file but inspectable by the test. */
final class MemoryCredentials implements CredentialSink
{
    /** @var list<array<string, mixed>> */
    public array $items = [];

    public function write(array $credential): void
    {
        $this->items[] = $credential;
    }

    public function location(): string
    {
        return 'memory';
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return array<string, string> username => temporary password */
    public function byUsername(): array
    {
        return array_column($this->items, 'temporaryPassword', 'username');
    }
}

const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';
$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN]);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) {
    (new SqlFileRunner($pdo))->run($seed);
}
$pdo->exec('DELETE FROM organization_principals');
// Custom roles left by an earlier run on this shared test database (other suites may have edited them).
$stale = implode(', ', array_map('intval', $pdo->query("SELECT role_id FROM roles WHERE role_key IN ('director-financiar', 'contabilitate')")->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
$pdo->exec("DELETE FROM employee_role_assignments WHERE role_id IN ({$stale})");
$pdo->exec("DELETE FROM role_permissions WHERE role_id IN ({$stale})");
$pdo->exec("DELETE FROM roles WHERE role_id IN ({$stale})");
$pdo->exec('DELETE FROM system_root_identity');
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$suffix = substr(bin2hex(random_bytes(6)), 0, 8);
$token = 'Q' . strtoupper(substr($suffix, 0, 6));

$call = static fn (array $who, string $method, string $path, ?array $json = null, ?string $key = null): array => T::call($kernel, $method, $path, $json, ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $who['csrf']] + ($key === null ? [] : ['idempotency-key' => $key]), $who['cookie']);
$get = static fn (array $who, string $path): array => T::call($kernel, 'GET', $path, null, ['origin' => DASHBOARD_ORIGIN], $who['cookie']);
$rawLogin = static fn (string $username, string $password): array => T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => DASHBOARD_ORIGIN]);
$session = static function (array $response): array {
    preg_match('/^arasya_session=([^;]+);/', $response['headers']['Set-Cookie'] ?? '', $match);
    return ['cookie' => rawurldecode($match[1] ?? ''), 'csrf' => (string) ($response['body']['csrfToken'] ?? ''), 'employeeUuid' => (string) ($response['body']['employee']['employeeUuid'] ?? '')];
};
$login = static function (string $username, string $password) use ($rawLogin, $session): array {
    $response = $rawLogin($username, $password);
    check($response['status'] === 200, "login {$username} (got {$response['status']} " . json_encode($response['body']) . ')');
    return $session($response);
};

$rootUsername = 'arasya.root.onb.' . $suffix;
$rootTemporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-onboarding-root', $rootUsername);
$rootFirst = $login($rootUsername, $rootTemporary);
checkOk($call($rootFirst, 'POST', '/auth/password', ['currentPassword' => $rootTemporary, 'newPassword' => 'Onboarding root passphrase 2026!']), 'root password');
$root = $login($rootUsername, 'Onboarding root passphrase 2026!');
$rootUuid = $root['employeeUuid'];

// ---- The real plan files, isolated per run by a name token ---------------------------------------------
$reference = dirname(__DIR__) . '/database/reference';
$realPlan = json_decode((string) file_get_contents("{$reference}/organization-onboarding.json"), true, 16, JSON_THROW_ON_ERROR);
$realRoster = json_decode((string) file_get_contents("{$reference}/organization-roster.json"), true, 16, JSON_THROW_ON_ERROR);
OrganizationOnboarding::fromArrays($realPlan, $realRoster);
// DR7 and DR9 are wholesale sales locations on the shared B2B platform, not blocked applications.
// A future owner-approved B2B grant must be structurally valid without an unrequested shop-isolation milestone.
$dr7Pilot = $realPlan;
foreach ($dr7Pilot['people'] as &$entry) {
    if ($entry['name'] === 'RADUCANU STELUTA') {
        $entry['status'] = 'active';
        $entry['applications'] = ['b2b'];
    }
}
unset($entry);
check(OrganizationOnboarding::fromArrays($dr7Pilot, $realRoster) instanceof OrganizationOnboarding,
    'a DR7 B2B application grant is allowed when explicitly authorized; no artificial shop isolation gate');

$tokenize = static function (array $plan, array $roster, string $token): array {
    $rename = static fn (string $name): string => "{$name} {$token}";
    foreach ($roster['people'] as &$person) {
        $person['name'] = $rename($person['name']);
    }
    unset($person);
    foreach ($plan['people'] as &$person) {
        $person['name'] = $rename($person['name']);
        $person['username'] = OrganizationOnboarding::username($person['name']);
        if (isset($person['manager'])) {
            $person['manager'] = $rename($person['manager']);
        }
    }
    unset($person);
    return [$plan, $roster];
};
[$plan, $roster] = $tokenize($realPlan, $realRoster, $token);
$onboarding = OrganizationOnboarding::fromArrays($plan, $roster);
$management = $container->managementService();
$provisioner = new OrganizationProvisioner($pdo, $management, $container->organizationService(), new OrganizationReconciler($pdo, $management, $container->employeeRepository()), $container->employeeRepository());
$u = static fn (string $name): string => OrganizationOnboarding::username("{$name} {$token}");
$uuidOf = static function (string $username) use ($pdo): string {
    $statement = $pdo->prepare('SELECT employee_uuid FROM employees WHERE username_normalized = ?');
    $statement->execute([$username]);
    return (string) $statement->fetchColumn();
};
$count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

// ---- Conflicts refuse the whole run: another person on a planned username, a duplicate identity ------------
[$conflictPlan, $conflictRoster] = $tokenize($realPlan, $realRoster, $token . 'X');
$conflicting = OrganizationOnboarding::fromArrays($conflictPlan, $conflictRoster);
$admin = $container->employeeAdmin();
$admin->create('Somebody Else Entirely', OrganizationOnboarding::username("COJOCARU ION {$token}X"), null, 'pregatire-material', 'employee', 'conflict passphrase 2026', [], 'test');
$admin->create("Ion Cojocaru {$token}X", "second.account.{$suffix}", null, 'pregatire-material', 'employee', 'conflict passphrase 2026', [], 'test');
$dry = $provisioner->plan($conflicting);
check(count($dry['conflicts']) === 2, 'a username held by another person and a second identity of the same person are both conflicts: ' . json_encode($dry['conflicts']));
$employeesBefore = $count('SELECT COUNT(*) FROM employees');
$auditBefore = $count('SELECT COUNT(*) FROM iam_audit_events');
$message = refused(fn () => $provisioner->apply($conflicting, new MemoryCredentials(), 'test-onboarding-conflict'));
check(str_contains($message, 'nothing changed') && $count('SELECT COUNT(*) FROM employees') === $employeesBefore && $count('SELECT COUNT(*) FROM iam_audit_events') === $auditBefore, 'a conflicting plan is refused before any change');

// ---- Dry run: complete, read-only --------------------------------------------------------------------
$dry = $provisioner->plan($onboarding);
check($dry['conflicts'] === [], 'the real plan has no conflict: ' . json_encode($dry['conflicts']));
$s = $dry['summary'];
check($s['people'] === 46 && $s['create'] === 46 && $s['wantedActive'] === 6 && $s['wantedInactive'] === 40 && $s['credentials'] === 6, 'dry run: 46 people to create, 6 active with a credential, 40 inactive');
check($s['managerLinks'] === 8 && $s['managerLinksToSet'] === 8, 'dry run: eight confirmed reporting lines');
check($dry['principal'] === ['username' => $u('YEMAN MESUT'), 'current' => null, 'action' => 'designate'], 'dry run: the CEO principal is designated once');
check($count('SELECT COUNT(*) FROM employees') === $employeesBefore && $count('SELECT COUNT(*) FROM iam_audit_events') === $auditBefore, 'the dry run changes nothing');
check(!str_contains(json_encode($dry), 'temporaryPassword'), 'the dry run carries no credential');

// ---- Apply --------------------------------------------------------------------------------------------
$credentials = new MemoryCredentials();
$result = $provisioner->apply($onboarding, $credentials, 'cli-onboarding-test-' . $suffix);
$usernames = array_map(static fn (array $p): string => $p['username'], $plan['people']);
$in = implode(', ', array_map([$pdo, 'quote'], $usernames));
$rows = $pdo->query("SELECT e.employee_uuid, e.username_normalized, e.display_name, e.status, e.must_change_password, e.manager_employee_uuid, d.name AS department FROM employees e JOIN departments d ON d.department_id = e.department_id WHERE e.username_normalized IN ({$in})")->fetchAll(PDO::FETCH_ASSOC);
$byUser = array_column($rows, null, 'username_normalized');
check(count($rows) === 46 && count(array_unique(array_column($rows, 'username_normalized'))) === 46, '46 identities, one per person, unique usernames');
foreach ($plan['people'] as $person) {
    check(preg_match('/^[a-z0-9]+(\.[a-z0-9]+)+$/D', $byUser[$person['username']]['username_normalized']) === 1, "lowercase dotted username {$person['username']}");
    check($byUser[$person['username']]['display_name'] === $person['name'], "the full name is preserved: {$person['name']}");
}
check(isset($byUser[$u('PARASCHIV STANICA-LUCIAN')]) && str_starts_with($u('PARASCHIV STANICA-LUCIAN'), 'paraschiv.stanica.lucian.'), 'a hyphenated given name becomes dotted parts');
$active = array_keys(array_filter($byUser, static fn (array $r): bool => $r['status'] === 'active'));
sort($active);
$expectedActive = array_map($u, ['NITA CRISTINA', 'VOICAN DENISA NICOLETA', 'YEMAN MESUT', 'YEMAN ZELAL', 'YERLIKAYA HIKMET', 'YETIS SINEM']);
sort($expectedActive);
check($active === $expectedActive, 'exactly the six authorized identities are active');
check(count(array_filter($byUser, static fn (array $r): bool => (int) $r['must_change_password'] === 1)) === 46, 'every new identity must change its password at the first login');
check($count("SELECT COUNT(*) FROM employees WHERE username_normalized IN ({$in}) AND (employee_code IS NOT NULL)") === 0, 'no corporate code or e-mail is required');
$issued = array_keys($credentials->byUsername());
sort($issued);
check($credentials->count() === 6 && $issued === $expectedActive, 'credentials are issued only for the six active identities');
foreach ($credentials->byUsername() as $temporary) {
    check(strlen($temporary) >= 20 && preg_match('/[A-Z]/', $temporary) === 1 && preg_match('/[a-z]/', $temporary) === 1 && preg_match('/[2-9]/', $temporary) === 1, 'each temporary password is long, random and mixed');
}
check(count(array_unique($credentials->byUsername())) === 6, 'every temporary password is individual');
$auditText = implode("\n", $pdo->query('SELECT CONCAT_WS(" ", action, target_label, metadata_json) FROM iam_audit_events')->fetchAll(PDO::FETCH_COLUMN));
$authAuditText = implode("\n", $pdo->query('SELECT CONCAT_WS(" ", event_type, COALESCE(metadata_json, "")) FROM auth_audit_events')->fetchAll(PDO::FETCH_COLUMN));
foreach ($credentials->byUsername() as $temporary) {
    check(!str_contains($auditText, $temporary) && !str_contains($authAuditText, $temporary) && !str_contains(json_encode($result), $temporary), 'a temporary password never reaches the audit records or the provisioning report');
}
check($count("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'employee.created' AND request_id = 'cli-onboarding-test-{$suffix}'") === 46, 'every identity creation is audited as root with the CLI request ID');

// Departments, membership and hierarchy
$secondary = static function (string $username) use ($pdo, $uuidOf): array {
    $statement = $pdo->prepare('SELECT d.name FROM employee_secondary_departments s JOIN departments d ON d.department_id = s.department_id WHERE s.employee_uuid = ? ORDER BY d.name');
    $statement->execute([$uuidOf($username)]);
    return $statement->fetchAll(PDO::FETCH_COLUMN);
};
check($secondary($u('PARASCHIV STANICA-LUCIAN')) === ['Depozit', 'Montaj'] && $secondary($u('BARBU PAUL')) === ['Montaj'] && $secondary($u('YETIS SINEM')) === ['Conducere'], 'multi-department people keep one identity with additional departments');
check($byUser[$u('YETIS SINEM')]['department'] === 'Vânzări Online' && $byUser[$u('MILITARU ALEXANDRA')]['department'] === 'Croitorie Dragon', 'primary departments follow the roster');
$parent = static fn (string $name): ?string => ($value = $pdo->query('SELECT p.name FROM departments d JOIN departments p ON p.department_id = d.parent_department_id WHERE d.name = ' . $pdo->quote($name))->fetchColumn()) === false ? null : (string) $value;
check($parent('Croitorie') === 'Operațiuni' && $parent('Tăiere') === 'Operațiuni' && $parent('Depozit') === 'Operațiuni' && $parent('Croitorie Dragon') === 'Operațiuni' && $parent('Montaj') === null, 'production departments sit under Operațiuni; installation stays unassigned');
check(str_contains((string) $pdo->query("SELECT description FROM departments WHERE name = 'Depozit'")->fetchColumn(), 'YERLIKAYA HIKMET'), 'department oversight is visible on the department');
$managerOf = static fn (string $name): ?string => ($id = $byUser[$u($name)]['manager_employee_uuid']) === null ? null : array_search($id, array_column($rows, 'employee_uuid', 'username_normalized'), true);
check($managerOf('BLEGU DANIELA NICOLETA') === $u('YETIS SINEM') && $managerOf('IANCU IULIANA') === $u('YETIS SINEM') && $managerOf('IVAN IRINA') === $u('YETIS SINEM'), 'internet sales report to the online sales director');
foreach (['VOICAN DENISA NICOLETA', 'YERLIKAYA HIKMET', 'YETIS SINEM', 'YEMAN ZELAL', 'PARASCHIV CRISTINA NICOLETA'] as $name) {
    check($managerOf($name) === $u('YEMAN MESUT'), "{$name} reports to the CEO");
}
check($managerOf('YEMAN MESUT') === null && $managerOf('NITA CRISTINA') === null && $managerOf('BUZATU ANDREEA') === null && $managerOf('RADUCANU STELUTA') === null && $managerOf('COJOCARU ION') === null, 'unconfirmed reporting lines stay empty');
$linked = count(array_filter($rows, static fn (array $r): bool => $r['manager_employee_uuid'] !== null));
check($linked === 8, 'exactly eight manager links');
foreach ($rows as $row) {
    $seen = [];
    for ($cursor = $row['employee_uuid']; $cursor !== null; $cursor = ($pdo->query('SELECT manager_employee_uuid FROM employees WHERE employee_uuid = ' . $pdo->quote($cursor))->fetchColumn() ?: null)) {
        check(!isset($seen[$cursor]), 'no reporting cycle');
        $seen[$cursor] = true;
    }
}

// Roles, applications, scopes, principal
$access = static function (string $username) use ($pdo, $uuidOf): array {
    $id = $uuidOf($username);
    $column = static function (string $sql) use ($pdo, $id): array {
        $statement = $pdo->prepare($sql);
        $statement->execute([$id]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    };
    return [
        'apps' => $column('SELECT application_key FROM employee_application_access WHERE employee_uuid = ? ORDER BY application_key'),
        'roles' => $column('SELECT r.role_key FROM employee_role_assignments a JOIN roles r ON r.role_id = a.role_id WHERE a.employee_uuid = ? ORDER BY r.role_key'),
        'stages' => $column('SELECT stage_id FROM employee_stage_access WHERE employee_uuid = ?'),
        'scopes' => $column("SELECT CONCAT(capability, ':', source_key) FROM employee_document_scopes WHERE employee_uuid = ? ORDER BY capability, source_key"),
    ];
};
check($access($u('YEMAN MESUT')) === ['apps' => ['dashboard'], 'roles' => ['ceo'], 'stages' => [], 'scopes' => []], 'CEO: Dashboard and the CEO template');
check($access($u('VOICAN DENISA NICOLETA')) === ['apps' => ['dashboard'], 'roles' => ['operations-manager'], 'stages' => [], 'scopes' => []], 'production director: operations manager, no stage');
check($access($u('YERLIKAYA HIKMET')) === ['apps' => ['dashboard'], 'roles' => ['operations-manager'], 'stages' => [], 'scopes' => []], 'warehouse/operations manager: operations manager, no stage');
check($access($u('YETIS SINEM')) === ['apps' => ['dashboard'], 'roles' => ['document-revision-approver'], 'stages' => [], 'scopes' => ['approve:outletperdele', 'approve:trendhome']], 'online sales director: approver for the two internet sources only');
check($access($u('YEMAN ZELAL')) === ['apps' => ['b2b'], 'roles' => ['director-financiar'], 'stages' => [], 'scopes' => []], 'finance director: B2B finance role');
check($access($u('NITA CRISTINA')) === ['apps' => ['b2b'], 'roles' => ['contabilitate'], 'stages' => [], 'scopes' => []], 'accounting: narrower B2B role');
$rolePermissions = static fn (string $key): array => $pdo->query('SELECT p.permission_key FROM role_permissions rp JOIN roles r ON r.role_id = rp.role_id JOIN permissions p ON p.permission_id = rp.permission_id WHERE r.role_key = ' . $pdo->quote($key) . ' ORDER BY p.permission_key')->fetchAll(PDO::FETCH_COLUMN);
check(array_diff($rolePermissions('contabilitate'), $rolePermissions('director-financiar')) === [] && !in_array('b2b.accounts.adjust', $rolePermissions('contabilitate'), true) && !in_array('b2b.accounts.reverse', $rolePermissions('contabilitate'), true), 'accounting is strictly narrower than the finance director (no adjust, no reverse)');
check(in_array('b2b.accounts.reverse', $rolePermissions('director-financiar'), true) && !in_array('b2b.companies.create', $rolePermissions('director-financiar'), true), 'the finance role manages current accounts but not companies');
foreach ($plan['people'] as $person) {
    if ($person['status'] === 'inactive') {
        check($access($person['username']) === ['apps' => [], 'roles' => [], 'stages' => [], 'scopes' => []], "no access for inactive {$person['username']}");
    }
}
foreach (['RADUCANU STELUTA', 'STEFAN CORNELIA', 'STEREA DANIEL', 'BARAGAN CRISTINA', 'DAN NICULINA', 'MANASRA MOHAMED', 'YEMAN FURKAN'] as $name) {
    check($byUser[$u($name)]['status'] === 'inactive' && !in_array('b2b', $access($u($name))['apps'], true), "{$name}: in the directory, inactive, no B2B grant");
}
check($count("SELECT COUNT(*) FROM employee_document_scopes s JOIN employees e ON e.employee_uuid = s.employee_uuid WHERE e.username_normalized IN ({$in}) AND s.source_key IN ('b2b', 'trendyol')") === 0, 'no b2b or trendyol document scope');
$ceo = (string) $pdo->query("SELECT employee_uuid FROM organization_principals WHERE principal_key = 'ceo'")->fetchColumn();
check($ceo === $uuidOf($u('YEMAN MESUT')) && $ceo !== $rootUuid && $count('SELECT COUNT(*) FROM system_root_identity WHERE employee_uuid = ' . $pdo->quote($ceo)) === 0, 'the CEO principal is the CEO identity, distinct from root');
check($count('SELECT COUNT(*) FROM system_root_identity') === 1 && $count('SELECT COUNT(*) FROM system_root_identity WHERE employee_uuid = ' . $pdo->quote($rootUuid)) === 1, 'root is unchanged');

// ---- Idempotent second apply ---------------------------------------------------------------------------
$auditBefore = $count('SELECT COUNT(*) FROM iam_audit_events');
$again = $provisioner->apply($onboarding, $second = new MemoryCredentials(), 'cli-onboarding-again-' . $suffix);
check($again['applied'] === [] && $second->count() === 0 && $count('SELECT COUNT(*) FROM iam_audit_events') === $auditBefore, 'a second apply changes nothing, issues no credential and writes no audit');
check($again['plan']['summary']['create'] === 0 && $again['plan']['summary']['exists'] === 46 && $again['plan']['drift'] === [], 'the second plan sees 46 existing identities without drift');

// ---- First login: forced password change, policy, session rotation, then access -------------------------
$sinem = $u('YETIS SINEM');
$temporary = $credentials->byUsername()[$sinem];
checkError($rawLogin($sinem, 'not the password at all'), 401, 'INVALID_CREDENTIALS', 'a wrong password is refused');
$inactiveLogin = $rawLogin($u('IVAN IRINA'), 'anything at all here');
check($inactiveLogin['status'] === 401, 'an inactive identity cannot sign in');
$first = $rawLogin($sinem, $temporary);
check($first['status'] === 200 && $first['body']['employee']['mustChangePassword'] === true, 'the temporary password signs in and requires a change');
$firstSession = $session($first);
checkError($get($firstSession, '/production-documents/revision-requests'), 403, 'PASSWORD_CHANGE_REQUIRED', 'no company data before the personal password');
checkError($get($firstSession, '/management/me'), 403, 'PASSWORD_CHANGE_REQUIRED', 'no Dashboard before the personal password');
checkError($call($firstSession, 'POST', '/auth/password', ['currentPassword' => 'wrong current 2026', 'newPassword' => 'Sinem personal passphrase 2026']), 400, 'CURRENT_PASSWORD_INVALID', 'the current password is verified');
checkError($call($firstSession, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'short-2026']), 400, 'PASSWORD_POLICY', 'the minimum length is enforced by the server');
checkError($call($firstSession, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => "my {$sinem} password"]), 400, 'PASSWORD_POLICY', 'the username cannot be part of the password');
checkError($call($firstSession, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => $temporary]), 400, 'PASSWORD_POLICY', 'the temporary password cannot be kept');
checkError($call($firstSession, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Sinem personal passphrase 2026', 'confirmation' => 'x']), 400, 'INVALID_REQUEST', 'unknown fields fail closed');
check($count('SELECT must_change_password FROM employees WHERE username_normalized = ' . $pdo->quote($sinem)) === 1, 'the flag stays until a successful change');
$changed = $call($firstSession, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Sinem personal passphrase 2026']);
check($changed['status'] === 200 && $changed['body']['employee']['mustChangePassword'] === false, 'the personal password clears the flag');
check(str_starts_with($changed['body']['employee']['username'] ?? $sinem, $sinem), 'the change answers for the same identity');
checkError($get($firstSession, '/management/me'), 401, 'SESSION_EXPIRED', 'the temporary session is revoked');
$sinemSession = $session($changed);
$me = checkOk($get($sinemSession, '/management/me'), 'the new session reaches the Dashboard');
check(($me['capabilities']['approveDocumentRevisions'] ?? null) === true && ($me['capabilities']['approveExceptions'] ?? false) === false, 'the online sales director approves documents, not production exceptions');
checkError($rawLogin($sinem, $temporary), 401, 'INVALID_CREDENTIALS', 'the temporary password no longer works');
$sinemAgain = $login($sinem, 'Sinem personal passphrase 2026');
checkOk($get($sinemAgain, '/production-documents/revision-requests'), 'the personal password works across sessions');
checkError($call($sinemAgain, 'PUT', '/management/employees/' . $uuidOf($u('IVAN IRINA')) . '/roles', ['roleIds' => [1]]), 403, 'UNAUTHORIZED_ACTION', 'a normal employee cannot grant roles');
checkError($call($sinemAgain, 'PUT', '/management/employees/' . $uuidOf($sinem) . '/document-scopes', ['operate' => ['b2b'], 'approve' => ['b2b', 'trendyol']]), 403, 'ROOT_ONLY', 'nobody widens their own document scope');
$voluntary = $call($sinemAgain, 'POST', '/auth/password', ['currentPassword' => 'Sinem personal passphrase 2026', 'newPassword' => 'Sinem second passphrase 2026']);
check($voluntary['status'] === 200 && $voluntary['body']['employee']['mustChangePassword'] === false, 'a voluntary change stays possible after onboarding');

// The CEO and the managers after their own first login
$personal = static function (string $name, string $password) use ($credentials, $u, $login, $call, $session): array {
    $username = $u($name);
    $first = $login($username, $credentials->byUsername()[$username]);
    $changed = $call($first, 'POST', '/auth/password', ['currentPassword' => $credentials->byUsername()[$username], 'newPassword' => $password]);
    check($changed['status'] === 200, "{$name} sets a personal password");
    return $session($changed);
};
$mesut = $personal('YEMAN MESUT', 'Mesut personal passphrase 2026');
checkOk($get($mesut, '/management/organization'), 'the CEO principal manages the organisation');
checkOk($get($mesut, '/management/employees'), 'the CEO sees the employee directory');
checkError($call($mesut, 'PUT', '/management/employees/' . $uuidOf($sinem) . '/document-scopes', ['operate' => [], 'approve' => ['b2b']]), 403, 'ROOT_ONLY', 'the CEO is not root: no document scope');
checkError($call($mesut, 'PUT', '/management/organization/ceo', ['employeeId' => $uuidOf($u('YEMAN ZELAL'))], 'onb-ceo-' . $suffix . '-000001'), 403, 'ROOT_ONLY', 'the CEO cannot move the principal');
checkError($call($mesut, 'PUT', "/management/employees/{$rootUuid}/roles", ['roleIds' => []]), 403, 'ROOT_PROTECTED', 'the CEO cannot touch root');
$denisa = $personal('VOICAN DENISA NICOLETA', 'Denisa personal passphrase 2026');
$denisaMe = checkOk($get($denisa, '/management/me'), 'the production director reaches the Dashboard');
check(($denisaMe['capabilities']['approveExceptions'] ?? null) === true && ($denisaMe['capabilities']['approveDocumentRevisions'] ?? false) === false, 'the production director decides production exceptions, not document revisions');
checkOk($get($denisa, '/management/production-exceptions'), 'the production director sees the exception queue');
checkError($get($denisa, '/orders/mine'), 403, 'APPLICATION_ACCESS_DENIED', 'no Staff stage work without a Staff grant');
checkError($call($denisa, 'PUT', '/management/employees/' . $uuidOf($u('BUZATU ANDREEA')) . '/stages', ['stageIds' => ['material-preparation']]), 403, 'UNAUTHORIZED_ACTION', 'a manager without stage management assigns no stage');
$zelal = $personal('YEMAN ZELAL', 'Zelal personal passphrase 2026');
$b2b = checkOk(T::call($kernel, 'GET', '/b2b/access', null, ['origin' => DASHBOARD_ORIGIN], $zelal['cookie']), 'the finance director reaches B2B');
check(in_array('b2b.accounts.reverse', $b2b['permissions'], true) && !in_array('b2b.companies.create', $b2b['permissions'], true), 'B2B finance permissions only');
checkError($get($zelal, '/management/me'), 403, 'APPLICATION_ACCESS_DENIED', 'the finance director has no Dashboard');

// ---- Password-change rate limit (own small limit) ---------------------------------------------------------
$strict = new Config(environment: 'test', appSecret: str_repeat('t', 32), dbHost: $config->dbHost, dbPort: $config->dbPort, dbName: $config->dbName, dbUser: $config->dbUser, dbPassword: $config->dbPassword,
    allowedOrigins: [T::ORIGIN, DASHBOARD_ORIGIN], sessionTtlSeconds: 36_000, sessionTouchIntervalSeconds: 300, loginUsernameLimit: 4, loginIpLimit: 500, loginWindowSeconds: 900, trustProxy: false, trustedProxies: []);
$strictKernel = (new Container($strict, $pdo))->kernel();
$probeCredentials = new MemoryCredentials();
$probe = $provisioner->createProbe($onboarding, $probeCredentials, 'cli-onboarding-probe-' . $suffix);
check(str_starts_with($probe, OrganizationProvisioner::PROBE_PREFIX) && $access($probe) === ['apps' => [], 'roles' => [], 'stages' => [], 'scopes' => []], 'the TEST probe identity has no access at all');
$probeLogin = T::call($strictKernel, 'POST', '/auth/login', ['username' => $probe, 'password' => $probeCredentials->byUsername()[$probe]], ['origin' => DASHBOARD_ORIGIN]);
check($probeLogin['status'] === 200 && $probeLogin['body']['employee']['mustChangePassword'] === true, 'the probe signs in with its temporary password');
$probeSession = $session($probeLogin);
$attempt = static fn (string $current): array => T::call($strictKernel, 'POST', '/auth/password', ['currentPassword' => $current, 'newPassword' => 'Probe personal passphrase 2026'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $probeSession['csrf']], $probeSession['cookie']);
// A right current password with a refused new one does not use up the limit.
for ($i = 1; $i <= 6; $i++) {
    checkError(T::call($strictKernel, 'POST', '/auth/password', ['currentPassword' => $probeCredentials->byUsername()[$probe], 'newPassword' => 'short'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $probeSession['csrf']], $probeSession['cookie']), 400, 'PASSWORD_POLICY', "policy refusal {$i} is not rate limited");
}
for ($i = 1; $i <= 4; $i++) {
    checkError($attempt("wrong current {$i} 2026"), 400, 'CURRENT_PASSWORD_INVALID', "wrong current password {$i}");
}
checkError($attempt('wrong current 5 2026'), 429, 'RATE_LIMITED', 'guessing the current password is rate limited');
checkError($attempt($probeCredentials->byUsername()[$probe]), 429, 'RATE_LIMITED', 'the limit holds even for the right password until the window ends');
check($count("SELECT COUNT(*) FROM auth_audit_events WHERE event_type = 'AUTH_ACCOUNT_BLOCKED' AND employee_uuid = " . $pdo->quote($uuidOf($probe))) >= 2, 'blocked password changes are audited');
$pdo->exec('DELETE FROM auth_rate_limit_buckets');
$probeChanged = $call($probeSession, 'POST', '/auth/password', ['currentPassword' => $probeCredentials->byUsername()[$probe], 'newPassword' => 'Probe personal passphrase 2026']);
check($probeChanged['status'] === 200 && $probeChanged['body']['employee']['mustChangePassword'] === false, 'the probe completes the first-login change');
checkError($get($session($probeChanged), '/management/me'), 403, 'APPLICATION_ACCESS_DENIED', 'the probe reaches no application data');
$provisioner->retireProbe($probe, 'cli-onboarding-retire-' . $suffix);
$ended = $get($session($probeChanged), '/management/me');
check($ended['status'] === 401 && in_array($ended['body']['error']['code'] ?? '', ['SESSION_EXPIRED', 'ACCOUNT_INACTIVE'], true), 'a retired probe is inactive and its sessions end');
check(str_contains(refused(fn () => $provisioner->retireProbe($sinem, 'x')), 'Only TEST'), 'only probe identities can be retired by the probe command');

// ---- Deactivation and recovery -------------------------------------------------------------------------
$hikmet = $u('YERLIKAYA HIKMET');
$hikmetSession = $personal('YERLIKAYA HIKMET', 'Hikmet personal passphrase 2026');
checkOk($call($root, 'POST', '/management/employees/' . $uuidOf($hikmet) . '/deactivate'), 'root deactivates an identity');
$ended = $get($hikmetSession, '/management/me');
check($ended['status'] === 401 && in_array($ended['body']['error']['code'] ?? '', ['SESSION_EXPIRED', 'ACCOUNT_INACTIVE'], true), 'deactivation ends the sessions at once');
checkError($rawLogin($hikmet, 'Hikmet personal passphrase 2026'), 401, 'ACCOUNT_INACTIVE', 'a deactivated identity cannot sign in');
check(str_contains(refused(fn () => $provisioner->reissue($hikmet, new MemoryCredentials(), 'x')), 'activate it first'), 'no credential for an inactive identity');
$third = $provisioner->apply($onboarding, $thirdCredentials = new MemoryCredentials(), 'cli-onboarding-third-' . $suffix);
check($third['applied'] === [] && $thirdCredentials->count() === 0 && $count('SELECT COUNT(*) FROM employees WHERE status = \'inactive\' AND username_normalized = ' . $pdo->quote($hikmet)) === 1, 'the provisioner never reactivates a deactivated identity');
check(in_array("{$hikmet} is inactive: it is not reactivated and receives no grant", $third['plan']['drift'], true), 'the deactivation is reported as drift');
checkOk($call($root, 'POST', '/management/employees/' . $uuidOf($hikmet) . '/activate'), 'root reactivates the identity');
$recovery = new MemoryCredentials();
$provisioner->reissue($hikmet, $recovery, 'cli-onboarding-reissue-' . $suffix);
checkError($rawLogin($hikmet, 'Hikmet personal passphrase 2026'), 401, 'INVALID_CREDENTIALS', 'a reissued credential replaces the compromised password');
$recovered = $login($hikmet, $recovery->byUsername()[$hikmet]);
checkError($get($recovered, '/management/me'), 403, 'PASSWORD_CHANGE_REQUIRED', 'a reissued credential is one-time again');
check($count("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'employee.password_reset' AND request_id = 'cli-onboarding-reissue-{$suffix}'") === 1, 'the recovery is audited');
check(str_contains(refused(fn () => $provisioner->reissue($rootUsername, new MemoryCredentials(), 'x')), 'Root is never reset'), 'root is never reset by the provisioner');

// ---- The private credential file ----------------------------------------------------------------------
$base = sys_get_temp_dir() . '/arasya-onboarding-test-' . $suffix;
$file = new CredentialFile($base . '/private', [$base . '/release'], new DateTimeImmutable('2026-10-08 10:00:00', new DateTimeZone('UTC')));
check($file->count() === 0 && str_contains($file->location(), 'no credential issued'), 'no file is written before a credential exists');
$file->write(['name' => 'TEST Person', 'username' => 'test.person', 'temporaryPassword' => 'Temp-Pass-For-Test-2026', 'applications' => ['dashboard'], 'reason' => 'Cont nou']);
$file->write(['name' => 'TEST Other', 'username' => 'test.other', 'temporaryPassword' => 'Other-Pass-For-Test-2026', 'applications' => ['b2b'], 'reason' => 'Cont nou']);
$path = $file->location();
check((fileperms($path) & 0777) === 0600 && (fileperms($base . '/private') & 0777) === 0700, 'the credential file is 0600 in a 0700 directory');
$content = (string) file_get_contents($path);
check(substr_count($content, "\f") === 1 && str_contains($content, 'Parolă temporară:  Other-Pass-For-Test-2026') && str_contains($content, 'https://dashboard.arasyahome.ro'), 'one printable page per person with the application address');
mkdir($base . '/release/inside', 0700, true);
check(str_contains(refused(fn () => new CredentialFile($base . '/release/inside', [$base . '/release'], new DateTimeImmutable())), 'never written inside'), 'credentials are never written inside the release');
check(str_contains(refused(fn () => new CredentialFile('relative/dir', [], new DateTimeImmutable())), 'absolute'), 'the credential directory is absolute');
unlink($path);
rmdir($base . '/private');
rmdir($base . '/release/inside');
rmdir($base . '/release');
rmdir($base);

fwrite(STDOUT, "OK MySQL organization onboarding integration ({$checks} checks)\n");
