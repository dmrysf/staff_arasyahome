<?php

declare(strict_types=1);

// Organization and IAM boundaries (API 2.22.0, migration 021) against a dedicated MySQL/MariaDB test database:
// source-scoped production document authority (default deny, root-only grants, authorization version and audit),
// internet versus B2B isolation for operators and approvers (reads, commands, queues, live notifications),
// self-approval, the temporary backup, scope revocation and inactive identities, CEO versus root boundaries,
// role ceilings, hierarchy and multi-department membership that grant nothing, and the production exception
// accountability record (one decision wins, identity UUIDs, audit reference, resulting transition).

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
    fwrite(STDOUT, "SKIP MySQL organization IAM integration: ARASYA_TEST_DB_NAME is not configured.\n");
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
/** @return list<array{event: string, id: int|null, data: array<string, mixed>}> */
function sseFrames(string $raw): array
{
    $frames = [];
    foreach (preg_split("/\n\n/", trim($raw)) ?: [] as $block) {
        $frame = ['event' => 'message', 'id' => null, 'data' => []];
        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, 'event: ')) {
                $frame['event'] = substr($line, 7);
            } elseif (str_starts_with($line, 'id: ')) {
                $frame['id'] = (int) substr($line, 4);
            } elseif (str_starts_with($line, 'data: ')) {
                $frame['data'] = json_decode(substr($line, 6), true) ?? [];
            }
        }
        if ($frame['event'] !== 'message' || $frame['data'] !== []) {
            $frames[] = $frame;
        }
    }
    return $frames;
}

const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';
$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN]);
$pdo = Connection::create($config);
$migrations = dirname(__DIR__) . '/database/migrations';
(new MigrationRunner($pdo))->migrate($migrations);
require __DIR__ . '/HandoffSchemaFixture.php';

// ---- Migration 021: additive, recorded once, grants nothing -------------------------------------------
restorePreDocumentScopesTestSchema($pdo);
$grantsBefore = $pdo->query('SELECT * FROM role_permissions ORDER BY role_id, permission_id')->fetchAll(PDO::FETCH_ASSOC);
$assignmentsBefore = $pdo->query('SELECT * FROM employee_role_assignments ORDER BY employee_uuid, role_id')->fetchAll(PDO::FETCH_ASSOC);
$ordersBefore = $pdo->query('SELECT order_uuid, document_status, document_version, production_version FROM operational_orders ORDER BY order_uuid')->fetchAll(PDO::FETCH_ASSOC);
check((new MigrationRunner($pdo))->migrate($migrations) === ['021_document_scopes.sql'], '020 -> 021 official additive upgrade');
check((new MigrationRunner($pdo))->migrate($migrations) === [], '021 recorded exactly once');
check($pdo->query('SELECT * FROM role_permissions ORDER BY role_id, permission_id')->fetchAll(PDO::FETCH_ASSOC) === $grantsBefore, '021 changes no role grant');
check($pdo->query('SELECT * FROM employee_role_assignments ORDER BY employee_uuid, role_id')->fetchAll(PDO::FETCH_ASSOC) === $assignmentsBefore, '021 assigns no role');
check($pdo->query('SELECT order_uuid, document_status, document_version, production_version FROM operational_orders ORDER BY order_uuid')->fetchAll(PDO::FETCH_ASSOC) === $ordersBefore, '021 changes no order');
check((int) $pdo->query('SELECT COUNT(*) FROM employee_document_scopes')->fetchColumn() === 0, '021 grants nobody a scope');
$capabilityCheck = false;
try {
    $pdo->exec("INSERT INTO employee_document_scopes (employee_uuid, capability, source_key, granted_at, granted_by_employee_uuid) SELECT employee_uuid, 'everything', 'trendhome', UTC_TIMESTAMP(6), employee_uuid FROM employees LIMIT 1");
} catch (PDOException) {
    $capabilityCheck = true;
}
check($capabilityCheck, 'a scope capability is operate or approve only');

foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) {
    (new SqlFileRunner($pdo))->run($seed);
}
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
foreach (['production_document_blocks', 'production_document_events', 'production_document_prints'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE operational_orders SET active_document_revision_uuid = NULL');
$pdo->exec('UPDATE production_document_revisions SET request_uuid = NULL');
$pdo->exec('UPDATE production_document_revision_requests SET previous_request_uuid = NULL');
$pdo->exec('UPDATE production_exception_decisions SET previous_decision_uuid = NULL');
$pdo->exec('DELETE FROM production_document_revision_requests');
$pdo->exec('DELETE FROM production_document_revisions');
foreach (['live_events', 'production_exception_idempotency', 'production_quality_events', 'production_exception_events', 'production_exception_decisions', 'production_exception_lines'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE operational_orders SET open_exception_uuid = NULL');
foreach (['production_exceptions', 'cutting_transfers', 'cutting_facts', 'analytics_ownership_intervals', 'analytics_order_projection', 'responsibility_assignments', 'organization_principals', 'order_activity_events', 'order_operation_idempotency', 'employee_order_relations', 'order_qr_references', 'operational_order_items', 'order_projection_receipts'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec("DELETE FROM operational_orders WHERE source_key <> 'b2b'");
$pdo->exec('DELETE FROM system_root_identity');

$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => DASHBOARD_ORIGIN], $who['cookie'], $query);
$send = static function (array $who, string $method, string $path, ?array $json = null, ?string $key = null, string $origin = DASHBOARD_ORIGIN) use ($kernel): array {
    $headers = ['origin' => $origin, 'x-csrf-token' => $who['csrf']];
    if ($key !== null) {
        $headers['idempotency-key'] = $key;
    }
    return T::call($kernel, $method, $path, $json, $headers, $who['cookie']);
};
$login = static function (string $username, string $password) use ($kernel): array {
    $response = T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => DASHBOARD_ORIGIN]);
    if ($response['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $response['headers']['Set-Cookie'] ?? '', $match)) {
        throw new RuntimeException("Login failed for {$username}: " . json_encode($response['body']));
    }
    return ['cookie' => rawurldecode($match[1]), 'csrf' => (string) $response['body']['csrfToken'], 'employeeUuid' => (string) $response['body']['employee']['employeeUuid']];
};
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string {
    $keys++;
    return sprintf('oiam-%s-%s-%04d', $label, $suffix, $keys);
};
$authorizationVersion = static function (string $uuid) use ($pdo): int {
    $statement = $pdo->prepare('SELECT authorization_version FROM employees WHERE employee_uuid = ?');
    $statement->execute([$uuid]);
    return (int) $statement->fetchColumn();
};

// ---- Identities: role-based TEST identities, configured only through central IAM ---------------------
$rootUsername = 'arasya.root.oiam.' . $suffix;
$rootTemporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-oiam-root', $rootUsername);
$first = $login($rootUsername, $rootTemporary);
checkOk($send($first, 'POST', '/auth/password', ['currentPassword' => $rootTemporary, 'newPassword' => 'Organization root passphrase 2026!']), 'root password');
$root = $login($rootUsername, 'Organization root passphrase 2026!');
$roleIds = [];
foreach (checkOk($get($root, '/management/roles'), 'roles')['items'] as $role) {
    $roleIds[$role['key']] = (int) $role['id'];
}
$password = 'organization passphrase 2026';
$admin = $container->employeeAdmin();
$identity = static function (string $name, array $stages, array $applications, array $roles) use ($admin, $send, $root, $password, $suffix): string {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test');
    checkOk($send($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications]), "{$name} applications");
    checkOk($send($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
    return $employee->employeeUuid;
};
$operator = $roleIds['production-documents-operator'];
$approverRole = $roleIds['document-revision-approver'];
$ids = [
    'webop' => $identity('webop', [], ['staff', 'dashboard'], [$operator]),
    'b2bop' => $identity('b2bop', [], ['staff', 'dashboard'], [$operator]),
    'noscopeop' => $identity('noscopeop', [], ['staff', 'dashboard'], [$operator]),
    'webapprover' => $identity('webapprover', [], ['dashboard'], [$approverRole]),
    'b2bapprover' => $identity('b2bapprover', [], ['dashboard'], [$approverRole]),
    'mixedapprover' => $identity('mixedapprover', [], ['dashboard'], [$approverRole]),
    'noscopeapprover' => $identity('noscopeapprover', [], ['dashboard'], [$approverRole]),
    'dual' => $identity('dual', [], ['staff', 'dashboard'], [$operator, $approverRole]),
    'backup' => $identity('backup', [], ['dashboard'], []),
    'ceo' => $identity('ceo', [], ['dashboard'], [$roleIds['ceo']]),
    'director' => $identity('director', [], ['dashboard'], [$roleIds['operations-director']]),
    'managera' => $identity('managera', [], ['dashboard'], [$roleIds['operations-manager']]),
    'managerb' => $identity('managerb', [], ['dashboard'], [$roleIds['operations-manager']]),
    'cutter' => $identity('cutter', ['material-preparation'], ['staff'], []),
    'tailor' => $identity('tailor', ['workshop-receiving'], ['staff'], []),
];
$who = [];
foreach (array_keys($ids) as $name) {
    $who[$name] = $login("{$name}.{$suffix}", $password);
}
checkOk($send($root, 'PUT', '/management/organization/ceo', ['employeeId' => $ids['ceo']], $key('ceo')), 'root designates the CEO principal');

// ---- Scope management: root only, validated, audited, versioned, idempotent ----------------------------
$scopePath = static fn (string $name): string => "/management/employees/{$ids[$name]}/document-scopes";
$internet = ['outletperdele', 'trendhome'];
checkError($send($who['ceo'], 'PUT', $scopePath('webapprover'), ['operate' => [], 'approve' => $internet]), 403, 'ROOT_ONLY', 'the CEO principal is not root and cannot widen document scopes');
checkError($send($who['director'], 'PUT', $scopePath('webapprover'), ['operate' => [], 'approve' => $internet]), 403, 'ROOT_ONLY', 'an employee administrator cannot widen document scopes');
checkError($send($who['webapprover'], 'PUT', $scopePath('webapprover'), ['operate' => [], 'approve' => $internet]), 403, 'ROOT_ONLY', 'nobody grants a scope to themselves');
checkError($send($root, 'PUT', "/management/employees/{$root['employeeUuid']}/document-scopes", ['operate' => ['trendhome'], 'approve' => []]), 403, 'ROOT_PROTECTED', 'root is above scopes and stays protected');
checkError($send($root, 'PUT', $scopePath('webapprover'), ['operate' => [], 'approve' => ['germany']]), 400, 'UNKNOWN_SOURCE', 'an unknown source cannot be scoped');
checkError($send($root, 'PUT', $scopePath('webapprover'), ['approve' => $internet]), 400, 'INVALID_REQUEST', 'both capabilities are always stated');
checkError($send($root, 'PUT', $scopePath('webapprover'), ['operate' => [], 'approve' => $internet, 'everything' => true]), 400, 'INVALID_REQUEST', 'unknown fields fail closed');
checkError($send($root, 'PUT', $scopePath('webapprover'), ['operate' => [], 'approve' => ["trendhome' OR '1'='1"]]), 400, 'INVALID_REQUEST', 'source keys are validated');
check((int) $pdo->query('SELECT COUNT(*) FROM employee_document_scopes')->fetchColumn() === 0, 'refused scope changes write nothing');
$versionBefore = $authorizationVersion($ids['webapprover']);
$detail = checkOk($send($root, 'PUT', $scopePath('webapprover'), ['operate' => [], 'approve' => ['trendhome', 'outletperdele']]), 'root scopes the internet approver');
check($detail['documentScopes'] === ['operate' => [], 'approve' => $internet], 'the scope is returned sorted and exact');
check($authorizationVersion($ids['webapprover']) === $versionBefore + 1, 'a scope change increments the authorization version');
$auditRows = static function (string $uuid) use ($pdo): array {
    $statement = $pdo->prepare("SELECT actor_employee_uuid, actor_type, metadata_json FROM iam_audit_events WHERE action = 'employee.document_scopes_changed' AND target_id = ? ORDER BY created_at, event_id");
    $statement->execute([$uuid]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$audit = $auditRows($ids['webapprover']);
check(count($audit) === 1 && $audit[0]['actor_employee_uuid'] === $root['employeeUuid'] && $audit[0]['actor_type'] === 'root'
    // JSON columns normalise key order, so the comparison is by key, not by position.
    && json_decode((string) $audit[0]['metadata_json'], true) == ['before' => ['operate' => [], 'approve' => []], 'after' => ['operate' => [], 'approve' => $internet]], 'the change is audited with before and after');
checkOk($send($root, 'PUT', $scopePath('webapprover'), ['operate' => [], 'approve' => $internet]), 'the same scope again');
check(count($auditRows($ids['webapprover'])) === 1 && $authorizationVersion($ids['webapprover']) === $versionBefore + 1, 'an unchanged scope writes no audit and keeps the version');
check(checkOk($get($root, "/management/employees/{$root['employeeUuid']}"), 'root view')['documentScopes'] === null, 'root has no stored scope: it reaches every source');
foreach ([
    'webop' => ['operate' => $internet, 'approve' => []],
    'b2bop' => ['operate' => ['b2b'], 'approve' => []],
    'b2bapprover' => ['operate' => [], 'approve' => ['b2b']],
    'mixedapprover' => ['operate' => ['trendhome'], 'approve' => ['b2b']],
    'dual' => ['operate' => $internet, 'approve' => $internet],
] as $name => $scopes) {
    checkOk($send($root, 'PUT', $scopePath($name), $scopes), "root scopes {$name}");
}

// ---- Orders: two internet sources and one B2B handoff -----------------------------------------------------
$changed = static fn (int $minute): string => sprintf('2026-10-08T07:%02d:00Z', $minute);
$curtain = static fn (float $meters): array => [['id' => 701, 'line' => 1, 'name' => 'Draperie TEST', 'sku' => 'DT-1', 'color' => 'Bej', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => $meters, 'quantity' => 1]];
$ingest = static function (string $source, string $id, int $minute, float $meters, string $stage = 'material-preparation') use ($kernel, $changed, $curtain, $suffix): void {
    $payload = T::sourceOrder($id, "{$source}-{$id}-{$minute}-{$suffix}", $changed($minute), T::stage($stage), 'processing', 'active', $curtain($meters), $id);
    $payload['order']['delivery'] = ['name' => 'TEST Client', 'street' => 'Str. Test 1', 'city' => 'Cluj-Napoca', 'postalCode' => '400000', 'country' => 'RO', 'phone' => '0722000111'];
    check((T::ingest($kernel, $source, $payload)['body']['outcome'] ?? null) === 'applied', "ingest {$source}:{$id}@{$minute}");
};
$ingest('trendhome', '9101', 1, 8.0);
$ingest('trendhome', '9102', 1, 4.0);
$ingest('outletperdele', '9201', 1, 6.0);
$document = static fn (string $globalId): string => '/production-documents/orders/' . rawurlencode($globalId);
$state = static function (string $globalId) use ($pdo): array {
    $statement = $pdo->prepare('SELECT document_status, document_version FROM operational_orders WHERE global_order_id = ?');
    $statement->execute([$globalId]);
    return $statement->fetch(PDO::FETCH_ASSOC);
};

// Default deny: a document permission without a scope reaches no order.
checkError($get($who['noscopeop'], $document('trendhome:9101')), 404, 'ORDER_NOT_FOUND', 'an unscoped operator cannot read a document');
checkError($send($who['noscopeop'], 'POST', $document('trendhome:9101') . '/generate', ['expectedDocumentVersion' => 0], $key('noscope-gen')), 404, 'ORDER_NOT_FOUND', 'an unscoped operator cannot generate');
check(checkOk($get($who['noscopeop'], '/production-documents/lookup', ['number' => '9101']), 'unscoped lookup')['items'] === [], 'an unscoped lookup finds nothing');
check($state('trendhome:9101')['document_status'] === 'none', 'refused generation wrote nothing');
checkError($get($who['b2bop'], $document('trendhome:9101')), 404, 'ORDER_NOT_FOUND', 'a B2B operator cannot read an internet document');
checkError($send($who['b2bop'], 'POST', $document('trendhome:9101') . '/generate', ['expectedDocumentVersion' => 0], $key('b2b-gen')), 404, 'ORDER_NOT_FOUND', 'a B2B operator cannot generate an internet document');
foreach (['trendhome:9101', 'trendhome:9102', 'outletperdele:9201'] as $globalId) {
    checkOk($send($who['webop'], 'POST', $document($globalId) . '/generate', ['expectedDocumentVersion' => 0], $key('gen')), "the internet operator generates revision 1 of {$globalId}", 201);
}
check(array_column(checkOk($get($who['webop'], '/production-documents/lookup', ['number' => '9101']), 'scoped lookup')['items'], 'orderId') === ['trendhome:9101'], 'a scoped lookup finds the order');

// Content changes make three documents stale.
$ingest('trendhome', '9101', 2, 10.0);
$ingest('trendhome', '9102', 2, 5.0);
$ingest('outletperdele', '9201', 2, 7.0);
check($state('trendhome:9101')['document_status'] === 'stale' && $state('outletperdele:9201')['document_status'] === 'stale', 'changed content blocks the documents');
// The shared test database keeps B2B orders of earlier runs; only their source matters here.
check(array_diff(array_column(checkOk($get($who['b2bop'], '/production-documents/attention'), 'b2b attention')['items'], 'source'), ['b2b']) === [], 'internet work never appears in the B2B worklist');
$webAttention = array_column(checkOk($get($who['webop'], '/production-documents/attention'), 'web attention')['items'], 'orderId');
sort($webAttention);
check($webAttention === ['outletperdele:9201', 'trendhome:9101', 'trendhome:9102'], 'the internet worklist shows its own sources');

// Live notifications follow the scope of every reader.
$cursor = static function (array $reader) use ($get): int {
    $frames = sseFrames((string) $get($reader, '/live/events')['raw']);
    check(($frames[0]['event'] ?? null) === 'ready', 'stream cursor');
    return (int) $frames[0]['data']['cursor'];
};
$events = static function (array $reader, int $after) use ($get): array {
    return array_values(array_filter(sseFrames((string) $get($reader, '/live/events', ['after' => (string) $after])['raw']), static fn (array $f): bool => $f['id'] !== null));
};
$cursors = [];
foreach (['webapprover', 'b2bapprover', 'noscopeapprover', 'webop', 'b2bop'] as $name) {
    $cursors[$name] = $cursor($who[$name]);
}
$request = static function (string $name, string $globalId) use ($send, $document, $state, $key, $who): array {
    return checkOk($send($who[$name], 'POST', $document($globalId) . '/revision-requests', ['expectedDocumentVersion' => (int) $state($globalId)['document_version'], 'comment' => 'TEST'], $key('req')), "{$name} requests a revision of {$globalId}", 201);
};
checkError($send($who['b2bop'], 'POST', $document('trendhome:9101') . '/revision-requests', ['expectedDocumentVersion' => (int) $state('trendhome:9101')['document_version']], $key('b2b-req')), 404, 'ORDER_NOT_FOUND', 'a B2B operator cannot request an internet revision');
$requestId = $request('webop', 'trendhome:9101')['request']['id'];
$types = static fn (array $frames): array => array_map(static fn (array $f): string => $f['event'] . ':' . ($f['data']['orderId'] ?? ''), $frames);
check(in_array('document.revision_requested:trendhome:9101', $types($events($who['webapprover'], $cursors['webapprover'])), true), 'the scoped approver is notified');
check($events($who['b2bapprover'], $cursors['b2bapprover']) === [] && $events($who['noscopeapprover'], $cursors['noscopeapprover']) === [], 'approvers of other sources and unscoped approvers are not notified');
check(in_array('document.changed:trendhome:9101', $types($events($who['webop'], $cursors['webop'])), true), 'scoped requesters are notified');
check(!in_array('document.changed:trendhome:9101', $types($events($who['b2bop'], $cursors['b2bop'])), true), 'requesters of other sources are not notified');
check((string) $pdo->query("SELECT scope_source_key FROM live_events WHERE event_type = 'document.revision_requested' ORDER BY event_seq DESC LIMIT 1")->fetchColumn() === 'trendhome', 'document notifications carry their source');

// Queues and decisions follow the approval scope; operating a source never approves it.
$queue = static fn (string $name): array => checkOk($get($who[$name], '/production-documents/revision-requests', ['view' => 'pending']), "{$name} queue");
check(array_column($queue('webapprover')['items'], 'id') === [$requestId] && $queue('webapprover')['pendingCount'] === 1, 'the internet approver sees the internet request');
check($queue('b2bapprover')['items'] === [] && $queue('b2bapprover')['pendingCount'] === 0, 'the B2B approver does not see it');
check($queue('noscopeapprover')['items'] === [] && $queue('noscopeapprover')['pendingCount'] === 0, 'an unscoped approver sees nothing');
check(checkOk($get($who['noscopeapprover'], '/management/me'), 'unscoped me')['capabilities']['approveDocumentRevisions'] === false, 'an unscoped approver is not offered the queue');
check(checkOk($get($who['webapprover'], '/management/me'), 'web me')['capabilities']['approveDocumentRevisions'] === true, 'a scoped approver is offered the queue');
check(count($queue('mixedapprover')['items']) === 0, 'operating a source does not add it to the approval queue');
checkError($get($who['b2bapprover'], "/production-documents/revision-requests/{$requestId}"), 404, 'DOCUMENT_REQUEST_NOT_FOUND', 'an out-of-scope approver cannot open the request');
checkError($get($who['b2bop'], "/production-documents/revision-requests/{$requestId}"), 404, 'DOCUMENT_REQUEST_NOT_FOUND', 'an out-of-scope operator cannot open the request');
$decide = static fn (string $name, string $id, string $decision, ?string $comment = null) => $send($who[$name], 'POST', "/production-documents/revision-requests/{$id}/decision", array_filter(['expectedVersion' => 1, 'decision' => $decision, 'comment' => $comment], static fn ($v) => $v !== null), $key('decide'));
checkError($decide('b2bapprover', $requestId, 'approve'), 404, 'DOCUMENT_REQUEST_NOT_FOUND', 'the B2B approver cannot decide an internet request');
checkError($decide('noscopeapprover', $requestId, 'approve'), 404, 'DOCUMENT_REQUEST_NOT_FOUND', 'an unscoped approver cannot decide');
checkError($decide('mixedapprover', $requestId, 'approve'), 403, 'DOCUMENT_SCOPE_DENIED', 'an operator of the source without approval scope cannot decide');
checkError($decide('webop', $requestId, 'approve'), 403, 'UNAUTHORIZED_ACTION', 'a requester without the approval permission cannot decide');
$pending = $pdo->query('SELECT status, version, decided_by_employee_uuid FROM production_document_revision_requests WHERE request_uuid = ' . $pdo->quote($requestId))->fetch(PDO::FETCH_ASSOC);
check($pending === ['status' => 'pending', 'version' => 1, 'decided_by_employee_uuid' => null], 'refused decisions changed nothing');
$approved = checkOk($decide('webapprover', $requestId, 'approve'), 'the internet approver approves');
check($approved['status'] === 'approved' && $approved['decidedVia'] === 'revision_approver', 'approved through the primary approver authority');
checkError($decide('webapprover', $requestId, 'approve'), 409, 'DOCUMENT_REQUEST_RESOLVED', 'a second decision is refused');

// No self-approval, even with both roles and both scopes.
$selfId = $request('dual', 'trendhome:9102')['request']['id'];
checkError($decide('dual', $selfId, 'approve'), 403, 'SELF_DECISION_DENIED', 'nobody approves their own request');

// Revoking a scope applies at once, including to an open queue.
$outletRequest = $request('webop', 'outletperdele:9201')['request']['id'];
check(in_array($outletRequest, array_column($queue('webapprover')['items'], 'id'), true), 'the outlet request reaches the internet approver');
$versionBefore = $authorizationVersion($ids['webapprover']);
checkOk($send($root, 'PUT', $scopePath('webapprover'), ['operate' => [], 'approve' => ['trendhome']]), 'root narrows the internet approver to Trendhome');
check($authorizationVersion($ids['webapprover']) === $versionBefore + 1, 'narrowing increments the authorization version');
check(!in_array($outletRequest, array_column($queue('webapprover')['items'], 'id'), true), 'the outlet request leaves the queue immediately');
checkError($decide('webapprover', $outletRequest, 'approve'), 404, 'DOCUMENT_REQUEST_NOT_FOUND', 'the narrowed approver cannot decide it');

// A temporary backup appointed by the CEO decides only inside a root-granted scope.
checkOk($send($who['ceo'], 'POST', '/management/organization/responsibilities', ['responsibility' => 'document_revision_backup_approver', 'employeeId' => $ids['backup'], 'endsAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 7 * 86400)], $key('backup')), 'the CEO appoints a temporary document backup', 201);
checkError($decide('backup', $outletRequest, 'approve'), 404, 'DOCUMENT_REQUEST_NOT_FOUND', 'a backup without a scope decides nothing');
checkOk($send($root, 'PUT', $scopePath('backup'), ['operate' => [], 'approve' => ['outletperdele']]), 'root scopes the backup to OutletPerdele');
$backupDecision = checkOk($decide('backup', $outletRequest, 'reject', 'TEST rejection'), 'the scoped backup decides');
check($backupDecision['status'] === 'rejected' && $backupDecision['decidedVia'] === 'backup_approver', 'decided through the backup authority');

// An inactive approver keeps nothing.
checkOk($send($root, 'POST', "/management/employees/{$ids['dual']}/deactivate"), 'root deactivates the dual identity');
$inactive = $decide('dual', $selfId, 'reject', 'TEST');
check(in_array($inactive['status'], [401, 403], true) && $pdo->query('SELECT status FROM production_document_revision_requests WHERE request_uuid = ' . $pdo->quote($selfId))->fetchColumn() === 'pending', 'an inactive identity cannot decide');

// B2B: the B2B handoff issues revision 1; only B2B-scoped identities work on it.
$b2b = static fn (string $method, string $path, array $body = []): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'oiam-b2b-' . bin2hex(random_bytes(8))], $root['cookie']);
$company = checkOk($b2b('POST', '/b2b/companies', ['legalName' => "TEST Company {$suffix}", 'countryCode' => 'RO', 'taxIdentifier' => 'OIAM' . $suffix,
    'contact' => ['name' => 'TEST contact', 'isPrimary' => true], 'address' => ['type' => 'delivery', 'countryCode' => 'RO', 'city' => 'TEST city', 'addressLine1' => 'TEST address', 'isPrimary' => true]]), 'B2B company', 201)['companyId'];
$companyDetail = checkOk(T::call($kernel, 'GET', "/b2b/companies/{$company}", null, [], $root['cookie']), 'B2B company detail');
$draft = checkOk($b2b('POST', '/b2b/orders', ['companyId' => $company, 'currencyCode' => 'RON', 'contactId' => $companyDetail['contacts'][0]['id'], 'deliveryAddressId' => $companyDetail['addresses'][0]['id'], 'lines' => [[
    'productCode' => 'CURTAIN', 'productName' => null, 'variant' => 'Wave', 'color' => 'Alb', 'kind' => 'curtain', 'width' => '200', 'height' => '260', 'quantity' => 1, 'meters' => '4.5',
    'pricingUnit' => 'meter', 'unitPriceNet' => '10.00', 'discountPercent' => '0', 'vatPercent' => '19', 'notes' => null, 'productionNotes' => null,
]]]), 'B2B draft', 201)['detail']['order'];
$final = checkOk($b2b('POST', "/b2b/orders/{$draft['id']}/finalize", ['expectedVersion' => $draft['version']]), 'B2B finalize')['detail']['order'];
$b2bGlobal = checkOk($b2b('POST', "/b2b/orders/{$final['id']}/production", ['expectedVersion' => $final['version']]), 'B2B handoff', 201)['production']['operationalOrderId'];
check($state($b2bGlobal)['document_status'] === 'active', 'the B2B handoff issued revision 1');
checkOk($send($root, 'POST', $document($b2bGlobal) . '/revoke', ['expectedDocumentVersion' => (int) $state($b2bGlobal)['document_version'], 'reason' => 'TEST scope isolation'], $key('revoke')), 'root revokes the B2B document');
checkError($get($who['webop'], $document($b2bGlobal)), 404, 'ORDER_NOT_FOUND', 'an internet operator cannot read a B2B document');
checkError($send($who['webop'], 'POST', $document($b2bGlobal) . '/revision-requests', ['expectedDocumentVersion' => (int) $state($b2bGlobal)['document_version']], $key('web-b2b')), 404, 'ORDER_NOT_FOUND', 'an internet operator cannot request a B2B revision');
$b2bRequest = $request('b2bop', $b2bGlobal)['request']['id'];
checkError($decide('webapprover', $b2bRequest, 'approve'), 404, 'DOCUMENT_REQUEST_NOT_FOUND', 'the internet approver cannot decide a B2B revision');
check(array_column($queue('b2bapprover')['items'], 'id') === [$b2bRequest], 'the B2B approver sees only the B2B request');
check(checkOk($decide('b2bapprover', $b2bRequest, 'approve'), 'the B2B approver approves')['status'] === 'approved', 'B2B revision approved inside its scope');
$rootQueue = checkOk($get($root, '/production-documents/revision-requests', ['view' => 'history']), 'root history');
check(count(array_intersect([$requestId, $outletRequest, $b2bRequest], array_column($rootQueue['items'], 'id'))) === 3, 'root break-glass reaches every source');

// ---- CEO, root and role ceilings ---------------------------------------------------------------------------
checkError($send($who['ceo'], 'PUT', '/management/organization/ceo', ['employeeId' => $ids['director']], $key('ceo-again')), 403, 'ROOT_ONLY', 'only root designates the CEO');
check(checkOk($get($who['ceo'], '/management/me'), 'CEO me')['capabilities']['approveDocumentRevisions'] === false, 'the CEO principal is not a document approver');
checkError($send($who['director'], 'PUT', "/management/employees/{$ids['webop']}/roles", ['roleIds' => [$approverRole]]), 403, 'AUTHORITY_EXCEEDED', 'an administrator cannot hand out a permission it does not hold');
checkError($send($who['director'], 'PUT', "/management/employees/{$ids['director']}/roles", ['roleIds' => []]), 403, 'SELF_MODIFICATION_DENIED', 'nobody changes their own roles');
checkError($send($who['director'], 'PUT', "/management/employees/{$ids['ceo']}/roles", ['roleIds' => []]), 403, 'AUTHORITY_EXCEEDED', 'an administrator cannot touch a higher-ranked identity');
checkError($send($who['managera'], 'PUT', "/management/employees/{$ids['managerb']}/roles", ['roleIds' => []]), 403, 'UNAUTHORIZED_ACTION', 'an operations manager administers nobody');

// ---- Hierarchy and multi-department membership grant nothing -----------------------------------------------
$effective = static fn (string $name): array => [
    checkOk($get($root, "/management/employees/{$ids[$name]}"), "{$name} view")['rolePermissions'],
    checkOk($get($root, "/management/employees/{$ids[$name]}"), "{$name} view")['applications'],
];
$cutterBefore = $effective('cutter');
$managerBefore = $effective('managera');
checkOk($send($root, 'PUT', "/management/employees/{$ids['cutter']}/manager", ['managerId' => $ids['managera']]), 'root sets a direct manager');
$departments = array_map(static fn (array $d): int => (int) $d['id'], array_slice(array_values(array_filter(checkOk($get($root, '/management/departments'), 'departments')['items'], static fn (array $d): bool => $d['status'] === 'active')), 0, 3));
$cutterDetail = checkOk($get($root, "/management/employees/{$ids['cutter']}"), 'cutter view');
$secondary = array_values(array_filter($departments, static fn (int $d): bool => $d !== (int) $cutterDetail['department']['id']));
checkOk($send($root, 'PUT', "/management/employees/{$ids['cutter']}/secondary-departments", ['departmentIds' => $secondary]), 'root adds additional functions');
check($effective('cutter') === $cutterBefore && $effective('managera') === $managerBefore, 'a manager line and additional departments change no permission or application');
check((int) $pdo->query('SELECT COUNT(*) FROM employees WHERE display_name = ' . $pdo->quote('Test cutter'))->fetchColumn() >= 1
    && (int) $pdo->query('SELECT COUNT(*) FROM employees WHERE username = ' . $pdo->quote("cutter.{$suffix}"))->fetchColumn() === 1, 'one identity carries every function');
checkError($get($who['managera'], '/management/employees'), 403, 'UNAUTHORIZED_ACTION', 'being a manager in the hierarchy does not open the employee directory');

// ---- Production exception accountability: one decision wins, recorded with identities ------------------------
$ingest('trendhome', '9301', 1, 9.0);
$exceptionOrder = 'trendhome:9301';
$version = static fn () => (int) $pdo->query('SELECT production_version FROM operational_orders WHERE global_order_id = ' . $pdo->quote($exceptionOrder))->fetchColumn();
$qr = static fn (): string => 'ARASYA:Q1:' . $pdo->query("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = " . $pdo->quote($exceptionOrder) . " AND q.status = 'active'")->fetchColumn();
$staff = static fn (string $name, string $action, array $body) => $send($who[$name], 'POST', '/orders/' . rawurlencode($exceptionOrder) . "/{$action}", $body, $key($action), T::ORIGIN);
checkOk($staff('cutter', 'claim', T::cuttingClaim($pdo, $exceptionOrder, $ids['cutter'], $version())), 'cutter claims');
checkOk($staff('cutter', 'transition', ['expectedVersion' => $version()]), 'cutter hands over to tailoring intake');
checkOk($staff('tailor', 'claim', ['expectedVersion' => $version()]), 'tailor accepts at intake');
$line = (string) $pdo->query('SELECT i.item_uuid FROM operational_order_items i JOIN operational_orders o ON o.order_uuid = i.order_uuid WHERE o.global_order_id = ' . $pdo->quote($exceptionOrder))->fetchColumn();
$exceptionId = checkOk($staff('tailor', 'fault-reports', ['expectedVersion' => $version(), 'itemIds' => [$line], 'reasonKey' => 'wrong-cut']), 'tailor reports a cutting fault', 201)['id'];
$exceptionVersion = static fn (): int => (int) $pdo->query('SELECT version FROM production_exceptions WHERE exception_uuid = ' . $pdo->quote($exceptionId))->fetchColumn();
checkOk($send($who['cutter'], 'POST', "/production-exceptions/{$exceptionId}/acknowledge", ['expectedVersion' => $exceptionVersion(), 'confirmed' => true, 'qrToken' => $qr(), 'comment' => 'Confirm că eroarea îmi aparține și refac lucrarea.'], $key('ack'), T::ORIGIN), 'the responsible cutter acknowledges with the QR');
$exceptionDecision = static fn (string $name) => $send($who[$name], 'POST', "/management/production-exceptions/{$exceptionId}/decision", ['expectedVersion' => $exceptionVersion(), 'decision' => 'approve'], $key('xdecide'));
checkError($send($who['webapprover'], 'POST', "/management/production-exceptions/{$exceptionId}/decision", ['expectedVersion' => $exceptionVersion(), 'decision' => 'approve'], $key('xdoc')), 403, 'UNAUTHORIZED_ACTION', 'a document approver is not a production exception approver');
checkError($send($who['ceo'], 'POST', "/management/production-exceptions/{$exceptionId}/cancel", ['expectedVersion' => $exceptionVersion(), 'reason' => 'TEST'], $key('xcancel')), 403, 'ROOT_ONLY', 'the CEO principal cannot use root recovery');
checkOk($exceptionDecision('managera'), 'manager A approves');
$second = $send($who['managerb'], 'POST', "/management/production-exceptions/{$exceptionId}/decision", ['expectedVersion' => $exceptionVersion() - 1, 'decision' => 'approve'], $key('xsecond'));
checkError($second, 409, 'EXCEPTION_ALREADY_RESOLVED', 'manager B cannot decide again');
check((int) $pdo->query("SELECT COUNT(*) FROM production_exception_decisions WHERE exception_uuid = " . $pdo->quote($exceptionId) . " AND status = 'approved'")->fetchColumn() === 1, 'exactly one successful decision');
$view = checkOk($get($who['managerb'], "/management/production-exceptions/{$exceptionId}"), 'manager view');
$record = $view['accountability'][0] ?? [];
$auditEvent = $pdo->query("SELECT event_id, request_id, actor_label, metadata_json FROM iam_audit_events WHERE action = 'production.exception.approved' AND target_id = " . $pdo->quote($exceptionId))->fetch(PDO::FETCH_ASSOC);
$metadata = json_decode((string) $auditEvent['metadata_json'], true);
check(count($view['accountability']) === 1 && $record['decision'] === 'approved' && $record['via'] === 'operations_manager', 'one accountability record: approved by an operations manager');
check($record['decidedBy'] === ['id' => $ids['managera'], 'displayName' => "Test managera (managera.{$suffix})"] && $auditEvent['actor_label'] === $record['decidedBy']['displayName'], 'the decision actor is the authenticated identity UUID with the label recorded at decision time');
check($record['reportedBy']['id'] === $ids['tailor'] && $record['responsible']['id'] === $ids['cutter'] && $view['detector']['id'] === $ids['tailor'] && $view['responsible']['id'] === $ids['cutter'], 'reporter and responsible employee are identities');
check($record['order'] === ['id' => $exceptionOrder, 'orderNumber' => '9301', 'source' => 'trendhome'] && $record['stage']['id'] === 'workshop-receiving'
    && $record['requestedAction']['type'] === 'return_to_cutting' && $record['requestedAction']['to']['id'] === 'material-preparation' && $record['reason']['key'] === 'wrong-cut', 'order, stage, requested action and reason');
check($record['transition'] === ['from' => 'workshop-receiving', 'to' => 'material-preparation'] && $record['auditEventId'] === $auditEvent['event_id'] && $record['requestId'] === $auditEvent['request_id'] && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', (string) $record['decidedAt']) === 1, 'resulting transition, audit reference and server time');
check($metadata['reasonKey'] === 'wrong-cut' && $metadata['detector'] === $ids['tailor'] && $metadata['responsible'] === $ids['cutter'] && $metadata['stage'] == ['before' => 'workshop-receiving', 'after' => 'material-preparation'], 'the IAM audit event names the reason and the involved identities');
check((string) $pdo->query('SELECT production_stage_id FROM operational_orders WHERE global_order_id = ' . $pdo->quote($exceptionOrder))->fetchColumn() === 'material-preparation', 'the approved return moved the order back to cutting');

check(!str_contains((string) $pdo->query("SELECT GROUP_CONCAT(metadata_json) FROM iam_audit_events WHERE action IN ('employee.document_scopes_changed', 'production.exception.approved')")->fetchColumn(), 'password'), 'the new audit metadata carries no secret');

fwrite(STDOUT, "OK MySQL organization IAM integration ({$checks} checks)\n");
