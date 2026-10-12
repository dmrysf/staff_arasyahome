<?php
declare(strict_types=1);

// All identities/orders are synthetic in an explicitly named test DB. No real employee credential is used.
use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\CredentialSink;
use Arasya\Operations\Iam\CredentialFile;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Management\FactoryApproval;
use Arasya\Operations\Management\FactoryOnboardingPlan;
use Arasya\Operations\Management\FactoryProvisioner;
use Arasya\Operations\Management\OrganizationOnboarding;
use Arasya\Operations\Order\OrderAccessPolicy;
use Arasya\Operations\Order\OperationalOrder;
use Arasya\Operations\Order\OrderFreshness;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Production\PdoProductionWorkflowRepository;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
$db = T::requireTestDatabase();
if ($db === null) { fwrite(STDOUT, "SKIP factory onboarding: ARASYA_TEST_DB_NAME is not configured.\n"); exit(0); }
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException('FAIL ' . $message); };
$refuse = static function (callable $operation, string $code) use ($check): void {
    try { $operation(); } catch (RuntimeException $e) { $check($e->getMessage() === $code, 'Expected ' . $code . ', got ' . $e->getMessage()); return; }
    throw new RuntimeException('Expected refusal ' . $code);
};
final class FactoryTestCredentials implements CredentialSink
{
    public array $items = [];
    public function __construct(private ?int $failAt = null, private ?CredentialSink $file = null) {}
    public function write(array $credential): void {
        if ($this->failAt !== null && count($this->items) === $this->failAt) throw new RuntimeException('simulated confidential sink failure');
        $this->file?->write($credential);
        $this->items[] = $credential;
    }
    public function location(): string { return $this->file?->location() ?? 'memory-only-fixture'; }
    public function count(): int { return count($this->items); }
}
$api = dirname(__DIR__);
$raw = json_decode(file_get_contents($api . '/database/reference/factory-onboarding.json'), true);
$roster = json_decode(file_get_contents($api . '/database/reference/organization-roster.json'), true);
$canonical = FactoryOnboardingPlan::fromArrays($raw, $roster);
$check(count($canonical->people()) === 29, 'canonical matrix has 29');
foreach (['cutting' => 4, 'workshop' => 15, 'packing' => 4, 'internal-handoff' => 6] as $group => $count) $check(count($canonical->people($group)) === $count, 'exact group size');
foreach ([
    static function (&$p): void { $p['sources'][] = 'b2b'; },
    static function (&$p): void { $p['roles'] = ['supervisor']; },
    static function (&$p): void { $p['groups']['cutting']['stageIds'][] = 'waiting'; },
    static function (&$p): void { array_pop($p['groups']['workshop']['stageIds']); },
    static function (&$p): void { $p['groups']['cutting']['names'][0] = 'MANASRA MOHAMED'; },
    static function (&$p): void { $p['groups']['cutting']['names'][0] = $p['groups']['cutting']['names'][1]; },
] as $change) {
    $bad = $raw; $change($bad);
    try { FactoryOnboardingPlan::fromArrays($bad, $roster); throw new LogicException('Invalid matrix accepted'); } catch (RuntimeException) { $check(true, 'matrix fails closed'); }
}

// Per-run token keeps this suite safe on CI's shared, disposable test database.
$token = 'F' . strtoupper(bin2hex(random_bytes(3)));
foreach ($roster['people'] as &$person) $person['name'] .= ' ' . $token;
unset($person);
foreach ($raw['groups'] as &$group) foreach ($group['names'] as &$name) $name .= ' ' . $token;
unset($group, $name);
$matrix = FactoryOnboardingPlan::fromArrays($raw, $roster);
$config = T::config($db);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate($api . '/database/migrations');
foreach (glob($api . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$pdo->exec('DELETE FROM system_root_identity');
$c = new Container($config, $pdo); $kernel = $c->kernel();
$rootName = 'test.factory.root.' . strtolower($token);
$temporary = (new RootBootstrapService($pdo, $c->passwordHasher(), $c->clock()))->bootstrap('factory-test', $rootName);
$session = T::login($kernel, $rootName, $temporary);
$response = T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Isolated factory owner phrase 2026!'], ['x-csrf-token' => $session['csrf']], $session['cookie']);
$check($response['status'] === 200, 'fixture root first login');
unset($temporary, $session, $response);
$root = $c->employeeRepository()->findByNormalizedUsername($rootName);
$m = $c->managementService(); $audit = new IamAuditLogger($pdo);
$provisioner = new FactoryProvisioner($pdo, $m, $c->employeeRepository(), $audit, $c->clock(), $matrix);
$departments = [];
foreach ($roster['departments'] as $department) {
    if (!in_array($department['key'], ['taiere', 'croitorie', 'croitorie-dragon', 'depozit', 'logistica-distributie', 'expediere-curierat'], true)) continue;
    $find = $pdo->prepare('SELECT department_id FROM departments WHERE department_key=?'); $find->execute([$department['key']]);
    $id = $find->fetchColumn();
    if ($id === false) $id = $m->createDepartment($root, ['name' => $department['name']], 'test-factory-department')['department']['id'];
    $departments[$department['key']] = (int) $id;
}
$ids = [];
foreach ($matrix->people() as $person) {
    $created = $m->createEmployee($root, ['username' => $person['username'], 'displayName' => $person['name'], 'positionTitle' => 'TEST isolated factory fixture', 'departmentId' => $departments[$person['departmentKey']], 'status' => 'inactive'], 'test-factory-fixture');
    $ids[$person['username']] = $created['employee']['id'];
    unset($created); // Undelivered initial fixture credentials are discarded.
}
$protected = [];
foreach (['ayse-operator' => ['staff'], 'germany-sales' => [], 'b2b-user' => ['b2b']] as $name => $apps) {
    $created = $m->createEmployee($root, ['username' => 'test.protected.' . $name . '.' . strtolower($token), 'displayName' => 'TEST protected ' . $name . ' ' . $token, 'departmentId' => $departments['depozit'], 'applications' => $apps, 'status' => $apps === [] ? 'inactive' : 'active'], 'test-factory-protected');
    $protected[$name] = $created['employee']['id']; unset($created);
}
$roleIds = array_column($m->listRoles($root)['items'], 'id', 'key');
$m->setRoles($root, $protected['ayse-operator'], [$roleIds['trendyol-order-approver'], $roleIds['production-documents-operator']], 'test-protected-roles');
$m->setDocumentScopes($root, $protected['ayse-operator'], ['operate' => ['trendyol'], 'approve' => []], 'test-protected-docs');
$m->setStages($root, $protected['ayse-operator'], ['waiting'], 'test-protected-stage', ['waiting' => ['trendyol']]);
$protectedBefore = array_map(fn($id) => $m->getEmployee($root, $id), $protected);
$snapshot = static function () use ($pdo, $ids): string {
    $data = [];
    foreach (['employees', 'employee_application_access', 'employee_role_assignments', 'employee_stage_access', 'employee_stage_source_scopes', 'auth_sessions'] as $table) {
        $q = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE employee_uuid IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY employee_uuid'); $q->execute(array_values($ids)); $data[$table] = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    $data['auditCount'] = (int) $pdo->query('SELECT COUNT(*) FROM iam_audit_events')->fetchColumn();
    return hash('sha256', serialize($data)); // Session token digests are binary, not UTF-8 JSON strings.
};
$before = $snapshot();
$pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
$all = $provisioner->plan(); $pdo->rollBack();
$check($all['summary'] === ['requested' => 29, 'found' => 29, 'activate' => 29, 'unchanged' => 0, 'conflicts' => 0, 'newIdentities' => 0, 'productionWrites' => 0], 'all-person dry-run');
$check($before === $snapshot(), 'dry-run changes no rows, password, sessions, audit');
$approval = static function (array $plan) use ($c): FactoryApproval {
    $now = $c->clock()->now();
    return FactoryApproval::fromArray(['group' => $plan['group'], 'planSha256' => $plan['planSha256'], 'reference' => 'TEST owner approval ' . $plan['group'], 'approvedBy' => 'TEST owner', 'approvedAt' => $now->modify('-1 second')->format(DATE_ATOM), 'expiresAt' => $now->modify('+1 hour')->format(DATE_ATOM)], $plan['group'], $plan['planSha256'], $now);
};
$cutting = $provisioner->plan('cutting');
$otherConnection = Connection::create($config);
$otherConnection->query("SELECT GET_LOCK('arasya.factory-onboarding',0)");
try {
    $refuse(fn() => $provisioner->apply($approval($cutting), new FactoryTestCredentials()), 'FACTORY_RUN_ALREADY_ACTIVE');
    $check($before === $snapshot(), 'parallel run gate changes nothing');
} finally { $otherConnection->query("SELECT RELEASE_LOCK('arasya.factory-onboarding')"); }
$otherConnection = null;
$now = $c->clock()->now();
$badApproval = ['group' => 'cutting', 'planSha256' => $cutting['planSha256'], 'reference' => 'TEST', 'approvedBy' => 'TEST', 'approvedAt' => $now->modify('-2 hours')->format(DATE_ATOM), 'expiresAt' => $now->modify('-1 hour')->format(DATE_ATOM)];
$refuse(fn() => FactoryApproval::fromArray($badApproval, 'cutting', $cutting['planSha256'], $now), 'FACTORY_APPROVAL_EXPIRED');
$refuse(fn() => FactoryApproval::fromArray($badApproval, 'workshop', $cutting['planSha256'], $now), 'FACTORY_APPROVAL_MISMATCH');
$refuse(fn() => FactoryApproval::fromArray($badApproval, 'cutting', str_repeat('0', 64), $now), 'FACTORY_APPROVAL_MISMATCH');
$privateDirectory = sys_get_temp_dir() . '/arasya-factory-test-' . strtolower($token);
mkdir($privateDirectory, 0700);
$approvalPath = $privateDirectory . '/approval.json';
$validApproval = $badApproval;
$validApproval['approvedAt'] = $now->modify('-1 second')->format(DATE_ATOM);
$validApproval['expiresAt'] = $now->modify('+1 hour')->format(DATE_ATOM);
file_put_contents($approvalPath, json_encode($validApproval)); chmod($approvalPath, 0644);
$refuse(fn() => FactoryApproval::fromFile($approvalPath, 'cutting', $cutting['planSha256'], $now, [$api]), 'FACTORY_APPROVAL_FILE_NOT_PRIVATE');
chmod($approvalPath, 0600); clearstatcache();
$check(FactoryApproval::fromFile($approvalPath, 'cutting', $cutting['planSha256'], $now, [$api])->group === 'cutting', 'private approval file accepted');
$refuse(fn() => FactoryApproval::fromFile($approvalPath, 'cutting', $cutting['planSha256'], $now, [$privateDirectory]), 'FACTORY_APPROVAL_FILE_LOCATION_FORBIDDEN');
symlink($approvalPath, $privateDirectory . '/link.json');
$refuse(fn() => FactoryApproval::fromFile($privateDirectory . '/link.json', 'cutting', $cutting['planSha256'], $now, [$api]), 'FACTORY_APPROVAL_FILE_NOT_PRIVATE');
unlink($privateDirectory . '/link.json'); unlink($approvalPath);

// Credential I/O failure on employee 2 rolls back the WHOLE group, including reset hashes and employee 1 activation.
$sink = new FactoryTestCredentials(1);
$refuse(fn() => $provisioner->apply($approval($cutting), $sink), 'FACTORY_CREDENTIAL_DELIVERY_FAILED');
$check($snapshot() === $before, 'group rollback covers permissions, passwords, status, audit');
$check(!$pdo->inTransaction(), 'failed group leaves no open transaction');
foreach ($sink->items as $item) $check(!password_verify($item['temporaryPassword'], $c->employeeRepository()->findByUuid($ids[$item['username']])->passwordHash), 'orphan file passwords are invalid after rollback');

// Known source refusal, unexpected roles, and scope drift must not remove or widen anything.
$pdo->exec("UPDATE order_sources SET status='inactive' WHERE source_key='trendyol'");
$refuse(fn() => $provisioner->apply($approval($cutting), new FactoryTestCredentials()), 'FACTORY_PLAN_CONFLICT');
$pdo->exec("UPDATE order_sources SET status='active' WHERE source_key='trendyol'");
$personId = $cutting['people'][0]['id'];
$m->setRoles($root, $personId, [$roleIds['supervisor']], 'test-factory-conflict');
$refuse(fn() => $provisioner->apply($approval($cutting), new FactoryTestCredentials()), 'FACTORY_PLAN_CONFLICT');
$m->setRoles($root, $personId, [], 'test-factory-remove-fixture-role');
$stale = $approval($provisioner->plan('cutting'));
$m->setApplications($root, $personId, ['staff'], 'test-factory-stale-read');
$refuse(fn() => $provisioner->apply($stale, new FactoryTestCredentials()), 'FACTORY_PLAN_CHANGED');
$m->setStages($root, $personId, ['material-preparation'], 'test-factory-unscoped-fixture');
$check($provisioner->plan('cutting')['conflicts'] !== [], 'existing unscoped grant is refused');
$m->setStages($root, $personId, [], 'test-factory-reset-fixture');

$credentials = []; $sessions = []; $policy = new OrderAccessPolicy(new AuthorizationService());
$workflow = (new PdoProductionWorkflowRepository($pdo))->current();
$activatedSoFar = 0;
foreach (FactoryOnboardingPlan::STAGES as $group => $stageIds) {
    $plan = $provisioner->plan($group); $permit = $approval($plan);
    $file = $group === 'packing' ? new CredentialFile($privateDirectory, [$api], $c->clock()->now()) : null;
    $sink = new FactoryTestCredentials(file: $file);
    $result = $provisioner->apply($permit, $sink);
    $check($result['activated'] === count($plan['people']) && $sink->count() === count($plan['people']), 'group independently activated');
    $activatedSoFar += count($plan['people']);
    $remaining = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_uuid IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND status='inactive'");
    $remaining->execute(array_values($ids));
    $check((int) $remaining->fetchColumn() === 29 - $activatedSoFar, 'later groups remain inactive until their own approval');
    if ($file !== null) {
        $check((fileperms($privateDirectory) & 0777) === 0700 && (fileperms($file->location()) & 0777) === 0600, 'existing CredentialFile is private');
        $sheet = file_get_contents($file->location());
        $check(substr_count($sheet, "\f") === 3, 'packing has four personal credential pages');
        foreach ($sink->items as $item) $check(str_contains($sheet, $item['temporaryPassword']) && !str_contains(json_encode($result), $item['temporaryPassword']), 'credential confined to private file');
        unset($sheet); unlink($file->location());
    }
    $afterApply = $snapshot(); $repeat = new FactoryTestCredentials();
    $noop = $provisioner->apply($permit, $repeat);
    $check($noop['activated'] === 0 && $repeat->count() === 0 && $afterApply === $snapshot(), 'same approval rerun resets nobody and adds no audit');
    foreach ($sink->items as $item) {
        $credentials[] = $item['temporaryPassword'];
        $login = T::login($kernel, $item['username'], $item['temporaryPassword']);
        $guard = T::call($kernel, 'GET', '/orders/mine', cookie: $login['cookie']);
        $check(($guard['body']['error']['code'] ?? null) === 'PASSWORD_CHANGE_REQUIRED', 'first login cannot read production');
        $changed = T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $item['temporaryPassword'], 'newPassword' => 'Synthetic personal password phrase 2026!'], ['x-csrf-token' => $login['csrf']], $login['cookie']);
        $check($changed['status'] === 200, 'synthetic first login succeeds');
        $old = T::call($kernel, 'GET', '/auth/session', cookie: $login['cookie']);
        $check($old['status'] === 401, 'old session revoked');
        $sessions[$item['username']] = T::login($kernel, $item['username'], 'Synthetic personal password phrase 2026!');
        $employee = $c->employeeRepository()->findByUuid($ids[$item['username']]);
        $check(!$employee->mustChangePassword && $employee->applications === ['staff'] && $employee->roleKeys === [], 'only Staff baseline');
        $check(!in_array('trendyol.orders.release', $employee->permissions, true) && !in_array('production.documents.generate', $employee->permissions, true), 'no marketplace or document admin');
        foreach ($workflow->stages as $stage) foreach (['trendhome', 'outletperdele', 'trendyol', 'b2b'] as $source) {
            $allowed = in_array($stage->id, $stageIds, true) && $source !== 'b2b';
            $stamp = $c->clock()->now();
            $order = new OperationalOrder('00000000-0000-4000-8000-000000000001', new GlobalOrderId($source, 'fixture'), 'TEST', $stage->id, 'Picking', 'Picking', null, 'in_progress', new OrderFreshness('fresh', $stamp, $stamp), 1, null, $stamp, productionAuthority: 'operations');
            $check($employee->worksAt($stage->id, $source) === $allowed, 'exact stage and source intersection');
            $check($policy->canView($employee, $order) === $allowed, 'server visibility');
            $check(($policy->evaluate($employee, $order, $workflow)['action'] === 'claim') === $allowed, 'server claim');
            if ($stage->id === 'waiting') {
                // QR cutting intake: exactly the cutting group receives stage-1 orders of the three sources, without a waiting grant.
                $check(($policy->evaluateCuttingIntake($employee, $order, $workflow)['action'] === 'cutting_intake') === ($group === 'cutting' && $source !== 'b2b'), 'QR cutting intake follows the matrix');
            }
            if ($allowed) {
                $owned = new OperationalOrder($order->orderUuid, $order->globalId, 'TEST', $stage->id, 'Picking', 'Picking', null, 'in_progress', $order->freshness, 1, null, $stamp, productionOwnerEmployeeUuid: $root->employeeUuid, productionAuthority: 'operations');
                $check($policy->evaluate($employee, $owned, $workflow)['blockedReason'] === 'claimed_by_other', 'unauthorized takeover blocked');
            }
        }
        foreach ($workflow->stages as $stage) {
            $queue = T::call($kernel, 'GET', '/orders/stage-queue', cookie: $sessions[$item['username']]['cookie'], query: ['stage' => $stage->id]);
            $check($queue['status'] === (in_array($stage->id, $stageIds, true) ? 200 : 403), 'HTTP stage queue server gate');
        }
    }
    $afterFirstLogin = $snapshot(); $repeat = new FactoryTestCredentials();
    $check($provisioner->apply($permit, $repeat)['activated'] === 0 && $repeat->count() === 0 && $snapshot() === $afterFirstLogin, 'rerun after personal password change does not reset it');
}
rmdir($privateDirectory);
$check(count($credentials) === 29 && count(array_unique($credentials)) === 29, 'all temporary passwords unique');
$events = $pdo->prepare('SELECT action,metadata_json FROM iam_audit_events WHERE target_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'); $events->execute(array_values($ids));
$auditRows = $events->fetchAll(PDO::FETCH_ASSOC); $auditText = json_encode($auditRows);
foreach ($credentials as $password) $check(!str_contains($auditText, $password), 'no temporary password in IAM audit');
$check(count(array_filter($auditRows, static fn($e) => $e['action'] === 'employee.factory_onboarded')) === 29, 'durable audit completion markers');
foreach ($auditRows as $event) if ($event['action'] === 'employee.stages_changed') {
    $metadata = json_decode($event['metadata_json'], true);
    if (!isset($metadata['stageScopesGranted'])) continue; // Deliberately invalid preflight fixture, never an applied rollout.
    foreach ($metadata['effectiveAfter'] as $scope) { $copy = $scope; sort($copy); $expected = FactoryOnboardingPlan::SOURCES; sort($expected); $check($copy === $expected, 'audit never shows an unscoped rollout state'); }
}
$protectedAfter = array_map(fn($id) => $m->getEmployee($root, $id), $protected);
$check(serialize($protectedAfter) === serialize($protectedBefore), 'Ayse, Germany and B2B synthetic identities unchanged');

// Flexible workshop work still follows sequential transitions and ownership, on one synthetic order only.
$workers = $matrix->people('workshop'); $a = $sessions[$workers[0]['username']]; $b = $sessions[$workers[1]['username']];
$sourceId = 'factory-flow-' . strtolower($token); $globalId = 'trendhome:' . $sourceId;
$ingest = T::ingest($kernel, 'trendhome', T::sourceOrder($sourceId, 'factory-event-' . strtolower($token), '2026-10-11T01:00:00.000Z', T::stage('labeling')));
$check($ingest['status'] === 200, 'synthetic workshop order');
$mutate = static function (array $who, string $operation, int $version, array $extra = []) use ($kernel, $globalId, $token): array {
    return T::call($kernel, 'POST', '/orders/' . rawurlencode($globalId) . '/' . $operation, ['expectedVersion' => $version] + $extra, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => 'factory-' . strtolower($token) . '-' . $operation . '-' . $version . '-' . substr($who['employeeUuid'], 0, 8)], $who['cookie']);
};
$check($mutate($a, 'claim', 1)['status'] === 200, 'workshop employee claims labeling');
$skip = $mutate($a, 'transition', 2, ['toStageId' => 'bottom-hem']);
$check(($skip['body']['error']['code'] ?? null) === 'INVALID_REQUEST', 'stage skipping rejected');
$step = $mutate($a, 'transition', 2); $check($step['body']['productionStageId'] === 'material-straightening', 'labeling moves to stage 5');
$check($mutate($b, 'claim', 3)['status'] === 200, 'another worker claims stage 5');
$check($mutate($a, 'claim', 4)['status'] === 409, 'original worker cannot take another active job');
$step = $mutate($b, 'transition', 4); $check($step['body']['productionStageId'] === 'bottom-hem', 'stage 5 moves to stage 6');
$check($mutate($a, 'claim', 5)['status'] === 200, 'same employee later claims stage 6');
$step = $mutate($a, 'transition', 6); $check($step['body']['productionStageId'] === 'side-hem', 'stage 6 moves exactly to 7');
$query = $pdo->prepare('SELECT employee_uuid,action FROM order_activity_events WHERE global_order_id=?'); $query->execute([$globalId]);
$actors = array_unique(array_column($query->fetchAll(PDO::FETCH_ASSOC), 'employee_uuid'));
$check(in_array($a['employeeUuid'], $actors, true) && in_array($b['employeeUuid'], $actors, true), 'both personal identities recorded');

// Already onboarded workers who are manually deactivated must never be reactivated on retry.
$m->setStatus($root, $ids[$workers[0]['username']], 'inactive', 'test-owner-revocation');
$check($provisioner->plan('workshop')['conflicts'] !== [], 'manual deactivation is a hard conflict');

// CLI defaults and missing approval are checked without printing process environment or credentials.
$environment = array_merge(getenv(), ['ARASYA_APP_ENV' => 'test', 'ARASYA_APP_SECRET' => str_repeat('t', 32), 'ARASYA_DB_HOST' => $config->dbHost, 'ARASYA_DB_PORT' => (string) $config->dbPort, 'ARASYA_DB_NAME' => $db, 'ARASYA_DB_USER' => $config->dbUser, 'ARASYA_DB_PASSWORD' => $config->dbPassword, 'ARASYA_ALLOWED_ORIGINS' => T::ORIGIN]);
$cli = static function (array $args) use ($api, $environment): array {
    $process = proc_open([PHP_BINARY, $api . '/bin/factory-provision.php', ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[2]); return [proc_close($process), $out, $error];
};
$beforeCli = $snapshot(); $default = $cli([]);
$check(json_decode($default[1], true)['mode'] === 'dry-run', 'CLI with no flags is read-only');
$check($snapshot() === $beforeCli, 'default CLI does not reset or activate');
foreach ([['--apply'], ['--apply', '--group=cutting'], ['--group=all'], ['--apply=false'], ['--json', '--json']] as $args) {
    $result = $cli($args); $check($result[0] !== 0 && $snapshot() === $beforeCli, 'invalid CLI gate does not mutate');
}
fwrite(STDOUT, "PASS factory provisioning integration ({$checks} checks): four groups, atomic scopes, rollback, approval, idempotency and isolation.\n");
