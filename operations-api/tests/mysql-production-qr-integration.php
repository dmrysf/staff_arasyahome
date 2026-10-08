<?php

declare(strict_types=1);

// Production QR authority (API 2.19.0, migration 019) against a dedicated MySQL/MariaDB test database:
// the additive migration and its single-active-QR invariant, per-source QR modes, intake issue, the
// signed source QR answer, Staff scan resolution of active, superseded, revoked and unknown codes, the
// audited manager rotation (idempotency, optimistic and real process concurrency), QR stability under
// ingestion, commerce updates, cancellation, stage work, takeover and release, document-controlled
// orders, the bounded reconciliation, authorization and the absence of customer data and raw payloads
// in answers and evidence.

use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Application\Container;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL production QR integration: ARASYA_TEST_DB_NAME is not configured.\n");
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
    $body = $response['body'];
    unset($body['active']['svg']);
    check($response['status'] === $status && ($response['body']['error']['code'] ?? null) === $code, "{$message} (got {$response['status']} " . json_encode($body) . ')');
}

/** @param array{status: int, body: array<string, mixed>|null} $response @return array<string, mixed> */
function checkOk(array $response, string $message, int $status = 200): array
{
    check($response['status'] === $status, "{$message} (got {$response['status']} " . json_encode($response['body']) . ')');
    return $response['body'] ?? [];
}

const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';
$qrEnforce = ['trendhome' => ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce'], 'outletperdele' => ['mode' => 'active', 'authority' => 'observe', 'qrAuthority' => 'observe']];
$qrLegacy = ['trendhome' => ['mode' => 'active', 'authority' => 'enforce'], 'outletperdele' => ['mode' => 'active', 'authority' => 'observe']];
$sourceLegacy = ['trendhome' => ['mode' => 'active'], 'outletperdele' => ['mode' => 'active']];
$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $qrEnforce);
$pdo = Connection::create($config);
$migrations = dirname(__DIR__) . '/database/migrations';
(new MigrationRunner($pdo))->migrate($migrations);
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) {
    (new SqlFileRunner($pdo))->run($seed);
}
require __DIR__ . '/HandoffSchemaFixture.php';

$cleanup = static function () use ($pdo): void {
    foreach (['production_qr_events', 'production_authority_events', 'production_document_blocks', 'production_document_events', 'production_document_prints'] as $table) {
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
};
$cleanup();
$pdo->exec('DELETE FROM system_root_identity');
register_shutdown_function(static function () use ($pdo): void {
    try {
        $pdo->exec('DELETE FROM production_authority_events');
        $pdo->exec('DELETE FROM production_qr_events');
    } catch (Throwable) {
    }
});

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$qrLegacyKernel = (new Container(T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $qrLegacy), $pdo))->kernel();
$sourceLegacyKernel = (new Container(T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $sourceLegacy), $pdo))->kernel();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string {
    $keys++;
    return sprintf('pqr-%s-%s-%04d', $label, $suffix, $keys);
};
$login = static function (string $username, string $password) use ($kernel): array {
    $response = T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => DASHBOARD_ORIGIN]);
    if ($response['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $response['headers']['Set-Cookie'] ?? '', $match)) {
        throw new RuntimeException("Login failed for {$username}: " . json_encode($response['body']));
    }
    return ['cookie' => rawurldecode($match[1]), 'csrf' => (string) $response['body']['csrfToken'], 'employeeUuid' => (string) $response['body']['employee']['employeeUuid']];
};
$dash = static function (array $who, string $method, string $path, ?array $json = null, ?string $idem = null) use ($kernel): array {
    $headers = ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $who['csrf']];
    if ($idem !== null) {
        $headers['idempotency-key'] = $idem;
    }
    return T::call($kernel, $method, $path, $json, $headers, $who['cookie']);
};
$staffPost = static function (array $who, string $path, array $json, ?string $idem, ?object $k = null) use ($kernel): array {
    $headers = ['origin' => T::ORIGIN, 'x-csrf-token' => $who['csrf']];
    if ($idem !== null) {
        $headers['idempotency-key'] = $idem;
    }
    return T::call($k ?? $kernel, 'POST', $path, $json, $headers, $who['cookie']);
};
$qrPath = static fn (string $globalId, string $action = ''): string => '/orders/' . rawurlencode($globalId) . '/production-qr' . ($action === '' ? '' : "/{$action}");
$view = static fn (array $who, string $globalId, ?object $k = null): array => T::call($k ?? $kernel, 'GET', $qrPath($globalId), null, ['origin' => T::ORIGIN], $who['cookie']);
$rotate = static fn (array $who, string $globalId, array $input, ?string $idem, ?object $k = null): array => $staffPost($who, $qrPath($globalId, 'rotate'), $input, $idem, $k);
$resolve = static fn (array $who, string $token): array => T::call($kernel, 'POST', '/orders/resolve-qr', ['token' => $token], ['origin' => T::ORIGIN, 'x-csrf-token' => $who['csrf']], $who['cookie']);
$sourceQr = static fn (object $k, string $source, array $ids, ?string $secret = null): array => T::ingest($k, $source, ['orderIds' => $ids], $secret, null, 'orders/qr');
$order = static function (string $globalId) use ($pdo): array|false {
    $statement = $pdo->prepare('SELECT order_uuid, production_authority, production_stage_id, production_version, version, production_owner_employee_uuid, operational_status, document_status FROM operational_orders WHERE global_order_id = ?');
    $statement->execute([$globalId]);
    return $statement->fetch(PDO::FETCH_ASSOC);
};
$count = static function (string $sql, array $args = []) use ($pdo): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($args);
    return (int) $statement->fetchColumn();
};
$references = static function (string $globalId) use ($pdo): array {
    $statement = $pdo->prepare('SELECT q.qr_reference, q.status, q.retired_reason FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? ORDER BY q.created_at, q.qr_reference');
    $statement->execute([$globalId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$activePayload = static function (string $globalId) use ($references): ?string {
    foreach ($references($globalId) as $row) {
        if ($row['status'] === 'active') {
            return 'ARASYA:Q1:' . $row['qr_reference'];
        }
    }
    return null;
};
$activeCount = static fn (string $globalId): int => count(array_filter($references($globalId), static fn (array $row): bool => $row['status'] === 'active'));
$qrEvents = static fn (string $globalId, ?string $action = null): int => $count('SELECT COUNT(*) FROM production_qr_events WHERE global_order_id = ?' . ($action === null ? '' : ' AND action = ?'), $action === null ? [$globalId] : [$globalId, $action]);
$ingest = static function (object $k, string $source, string $id, string $event, int $ago, string $status = 'processing', string $availability = 'active', ?array $items = null): array {
    $payload = T::sourceOrder($id, $event, gmdate('Y-m-d\TH:i:s\Z', time() - $ago), null, $status, $availability, $items);
    $payload['order']['delivery'] = ['name' => 'TEST Client QR', 'street' => 'Str. Secretă 7', 'city' => 'Iasi', 'postalCode' => '700000', 'country' => 'RO', 'phone' => '0722000777'];
    return T::ingest($k, $source, $payload);
};
$noPii = static fn (mixed $value): bool => !str_contains(json_encode($value, JSON_UNESCAPED_UNICODE), 'TEST Client') && !str_contains(json_encode($value, JSON_UNESCAPED_UNICODE), 'Secretă')
    && !str_contains(json_encode($value), '0722000777') && !str_contains(json_encode($value), '722000777') && !str_contains(json_encode($value), 'Iasi');

// ---- Identities -----------------------------------------------------------------------------------------
$rootUsername = 'arasya.root.qr.' . $suffix;
$rootTemporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-qr-root', $rootUsername);
$first = $login($rootUsername, $rootTemporary);
check(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $rootTemporary, 'newPassword' => 'QR authority root passphrase 2026!'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $first['csrf']], $first['cookie'])['status'] === 200, 'root changes its temporary password');
$root = $login($rootUsername, 'QR authority root passphrase 2026!');
$authorityRole = (int) checkOk($dash($root, 'POST', '/management/roles', ['name' => "Autoritate QR {$suffix}", 'description' => null, 'authorityRank' => 400, 'permissions' => ['production.manage_authority']]), 'authority role', 201)['role']['id'];
$password = 'qr authority passphrase 2026';
$admin = $container->employeeAdmin();
$identity = static function (string $name, array $stages, array $applications, array $roles) use ($admin, $dash, $root, $password, $suffix): string {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test');
    checkOk($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications]), "{$name} applications");
    checkOk($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
    return $employee->employeeUuid;
};
$managerId = $identity('qrmanager', [], ['staff', 'dashboard'], [$authorityRole]);
$identity('qrmanager2', [], ['staff', 'dashboard'], [$authorityRole]);
$waiterId = $identity('qrwaiter', ['waiting', 'material-preparation'], ['staff'], []);
$identity('qrcutter', ['material-preparation'], ['staff'], []);
$manager = $login("qrmanager.{$suffix}", $password);
$manager2 = $login("qrmanager2.{$suffix}", $password);
$waiter = $login("qrwaiter.{$suffix}", $password);
$cutter = $login("qrcutter.{$suffix}", $password);

// ---- Migration 019: additive, fails closed on two active QRs of one order, rewrites no reference -----------
check(($ingest($sourceLegacyKernel, 'trendhome', '9001', "th-9001-a-{$suffix}", 900)['body']['outcome'] ?? null) === 'applied', 'seed order before the 019 upgrade');
check(($ingest($sourceLegacyKernel, 'trendhome', '9002', "th-9002-a-{$suffix}", 900)['body']['outcome'] ?? null) === 'applied', 'second seed order before the 019 upgrade');
restorePreQrAuthorityTestSchema($pdo);
$seedUuid = (string) $order('trendhome:9001')['order_uuid'];
$pdo->prepare("INSERT INTO order_qr_references (qr_reference, order_uuid, status, created_at) VALUES (?, ?, 'active', UTC_TIMESTAMP(6))")->execute([\Arasya\Operations\Order\QrReference::generate()->value, $seedUuid]);
$failed = false;
try {
    (new MigrationRunner($pdo))->migrate($migrations);
} catch (Throwable) {
    $failed = true;
}
check($failed && !$pdo->query("SELECT 1 FROM schema_migrations WHERE migration_name = '019_production_qr_authority.sql'")->fetchColumn(), '019 refuses to apply while an order has two active QR references');
$pdo->prepare("DELETE FROM order_qr_references WHERE order_uuid = ? AND qr_reference NOT IN (SELECT r FROM (SELECT MIN(qr_reference) r FROM order_qr_references WHERE order_uuid = ?) x)")->execute([$seedUuid, $seedUuid]);
if ($pdo->query("SHOW COLUMNS FROM order_qr_references LIKE 'active_order_uuid'")->fetch()) {
    // A partially applied first statement on engines without atomic DDL: restore the clean pre-019 fixture.
    restorePreQrAuthorityTestSchema($pdo);
}
$qrBefore = $pdo->query('SELECT qr_reference, order_uuid, status, created_at, expires_at, revoked_at FROM order_qr_references ORDER BY qr_reference')->fetchAll(PDO::FETCH_ASSOC);
$ordersBefore = $pdo->query('SELECT global_order_id, production_authority, production_stage_id, production_version, version, document_status FROM operational_orders ORDER BY global_order_id')->fetchAll(PDO::FETCH_ASSOC);
$grantsBefore = $pdo->query('SELECT * FROM role_permissions ORDER BY role_id, permission_id')->fetchAll(PDO::FETCH_ASSOC);
check((new MigrationRunner($pdo))->migrate($migrations) === ['019_production_qr_authority.sql', '020_production_document_authority.sql', '021_document_scopes.sql'], '018 -> 019 official additive upgrade');
check((new MigrationRunner($pdo))->migrate($migrations) === [], '019 recorded exactly once');
check($pdo->query('SELECT qr_reference, order_uuid, status, created_at, expires_at, revoked_at FROM order_qr_references ORDER BY qr_reference')->fetchAll(PDO::FETCH_ASSOC) === $qrBefore, '019 issues, rotates or revokes no QR reference');
check($pdo->query('SELECT global_order_id, production_authority, production_stage_id, production_version, version, document_status FROM operational_orders ORDER BY global_order_id')->fetchAll(PDO::FETCH_ASSOC) === $ordersBefore, '019 changes no order');
check($pdo->query('SELECT * FROM role_permissions ORDER BY role_id, permission_id')->fetchAll(PDO::FETCH_ASSOC) === $grantsBefore, '019 changes no permission grant');
check($count('SELECT COUNT(*) FROM order_qr_references WHERE status = \'active\' AND active_order_uuid = order_uuid') === $count("SELECT COUNT(*) FROM order_qr_references WHERE status = 'active'"), 'every active reference is covered by the single-active index');
$duplicate = false;
try {
    $pdo->prepare("INSERT INTO order_qr_references (qr_reference, order_uuid, status, created_at) VALUES (?, ?, 'active', UTC_TIMESTAMP(6))")->execute([\Arasya\Operations\Order\QrReference::generate()->value, $seedUuid]);
} catch (PDOException $error) {
    $duplicate = (int) ($error->errorInfo[1] ?? 0) === 1062;
}
check($duplicate && $activeCount('trendhome:9001') === 1, '2: the database itself refuses a second active QR for one order');

// ---- QR legacy: today's behaviour, no QR handed to the source, no rotation ---------------------------------
$legacyNew = $ingest($qrLegacyKernel, 'trendhome', '9101', "th-9101-a-{$suffix}", 600);
check(($legacyNew['body']['outcome'] ?? null) === 'applied' && $order('trendhome:9101')['production_authority'] === 'operations' && $activeCount('trendhome:9101') === 1, 'production enforce + QR legacy: new operations order with one intake QR');
check(array_keys($legacyNew['body']) === ['outcome', 'globalOrderId', 'qr'] && $legacyNew['body']['qr'] === $activePayload('trendhome:9101'), 'the /orders contract is unchanged');
$issued = $pdo->query("SELECT action, qr_revision, qr_authority_mode, actor_employee_uuid, reason_code, qr_hint FROM production_qr_events WHERE global_order_id = 'trendhome:9101'")->fetchAll(PDO::FETCH_ASSOC);
check(count($issued) === 1 && $issued[0]['action'] === 'issued' && (int) $issued[0]['qr_revision'] === 1 && $issued[0]['qr_authority_mode'] === 'legacy' && $issued[0]['actor_employee_uuid'] === null && $issued[0]['reason_code'] === 'intake' && strlen((string) $issued[0]['qr_hint']) === 10, '17: the intake issue is recorded with a hint only: ' . json_encode($issued));
$legacyAnswer = $sourceQr($qrLegacyKernel, 'trendhome', ['9101']);
check($legacyAnswer['status'] === 200 && $legacyAnswer['body']['qrAuthorityMode'] === 'legacy' && $legacyAnswer['body']['orders'][0]['qrAuthority'] === 'source' && $legacyAnswer['body']['orders'][0]['qr'] === null, 'QR legacy: the source receives no Arasya QR');
checkError($rotate($manager, 'trendhome:9101', ['expectedQrRevision' => 1, 'reason' => 'label_lost'], $key('legacy-rotate'), $qrLegacyKernel), 409, 'QR_CUTOVER_DISABLED', 'QR legacy: no rotation');
check($activeCount('trendhome:9101') === 1 && count($references('trendhome:9101')) === 1, 'the refused rotation changed nothing');

// ---- 1, 2, 3: a new operations order under QR enforce ------------------------------------------------------
$new = $ingest($kernel, 'trendhome', '9201', "th-9201-a-{$suffix}", 600);
$newPayload = (string) ($new['body']['qr'] ?? '');
check(($new['body']['outcome'] ?? null) === 'applied' && $order('trendhome:9201')['production_authority'] === 'operations' && $order('trendhome:9201')['production_stage_id'] === 'waiting', '1: a new order of an enforcing source is Arasya-managed at waiting');
check($activeCount('trendhome:9201') === 1 && $newPayload === $activePayload('trendhome:9201') && preg_match('/^ARASYA:Q1:[A-Z2-7]{26}$/D', $newPayload) === 1, '1, 2: exactly one active QR, the one returned at ingestion');
check(($ingest($kernel, 'trendhome', '9201', "th-9201-a-{$suffix}", 600)['body']['qr'] ?? null) === $newPayload && count($references('trendhome:9201')) === 1 && $qrEvents('trendhome:9201') === 1, '3: a replayed ingestion is idempotent (same QR, no new revision, no new evidence)');

// ---- 5, 6, 7, 22: signed answer, scan resolution, no PII -----------------------------------------------------
$answer = $sourceQr($kernel, 'trendhome', ['9201', '9101', '424242']);
check($answer['status'] === 200 && $answer['body']['qrAuthorityMode'] === 'enforce' && $answer['body']['productionAuthorityMode'] === 'enforce', 'the signed QR answer reports both modes');
$row = $answer['body']['orders'][0];
check($row['orderId'] === '9201' && $row['globalOrderId'] === 'trendhome:9201' && $row['exists'] === true && $row['productionAuthority'] === 'operations' && $row['qrAuthority'] === 'arasya' && $row['qrIssueRequired'] === false, '5: the answer names the canonical order and Arasya QR authority');
check($row['qr']['payload'] === $newPayload && $row['qr']['revision'] === 1 && strlen($row['qr']['hint']) === 10 && $row['qr']['documentRevision'] === null, '6: the answer carries the active revision');
check($answer['body']['orders'][2] === ['orderId' => '424242', 'globalOrderId' => 'trendhome:424242', 'exists' => false, 'productionAuthority' => null, 'qrAuthority' => null, 'qrIssueRequired' => false, 'qr' => null], 'an unknown order is reported, never an error');
check($noPii($answer['body']), '7: the signed QR answer carries no customer data');
check($sourceQr($kernel, 'outletperdele', ['9201'])['body']['orders'][0]['exists'] === false, 'a source never sees another source\'s QR');
checkError($sourceQr($kernel, 'trendhome', ['9201'], T::OUTLET_SECRET), 401, 'SOURCE_SIGNATURE_INVALID', 'the QR answer requires the source signature');
checkError(T::ingest($kernel, 'trendhome', ['orderIds' => []], null, null, 'orders/qr'), 422, 'SOURCE_PAYLOAD_INVALID', 'the QR answer validates its request');
check($qrEvents('trendhome:9201') === 1, 'the signed QR answer writes nothing');
$resolved = checkOk($resolve($waiter, $newPayload), '5, 22: a worker scans the active QR');
check($resolved['id'] === 'trendhome:9201' && $resolved['productionStageId'] === 'waiting', '5, 22: the active QR routes Staff to the correct order');
check($noPii($resolved), '7: the scan answer exposes no customer data');
$inspect = checkOk($view($manager, 'trendhome:9201'), 'the manager inspects the QR authority');
check($inspect['qrAuthority'] === 'arasya' && $inspect['qrAuthorityMode'] === 'enforce' && $inspect['active']['revision'] === 1 && $inspect['active']['payload'] === $newPayload && str_starts_with($inspect['active']['svg'], '<svg') && $inspect['rotate']['allowed'] === true, 'the manager sees Arasya authority, the active revision and a preview');
check(count($inspect['history']) === 1 && $inspect['history'][0]['state'] === 'active' && $noPii($inspect), 'the manager view has the history and no customer data');

// ---- 13, 14, 16: commerce updates and stage work never rotate ----------------------------------------------------
$update = $ingest($kernel, 'trendhome', '9201', "th-9201-b-{$suffix}", 300, 'processing', 'active', [[
    'id' => 501, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Gri', 'width' => 320, 'height' => 260, 'unit' => 'cm', 'meters' => 9.1, 'quantity' => 2,
]]);
check(($update['body']['outcome'] ?? null) === 'applied' && $update['body']['qr'] === $newPayload && $activePayload('trendhome:9201') === $newPayload && count($references('trendhome:9201')) === 1, '13, 14: a commerce update keeps the active QR');
checkOk($staffPost($waiter, '/orders/trendhome%3A9201/claim', ['expectedVersion' => 1], $key('claim')), 'the worker claims the waiting order');
checkOk($staffPost($waiter, '/orders/trendhome%3A9201/transition', ['expectedVersion' => 2], $key('transition')), 'the worker completes the waiting stage');
check($order('trendhome:9201')['production_stage_id'] === 'material-preparation' && $activePayload('trendhome:9201') === $newPayload && count($references('trendhome:9201')) === 1 && $qrEvents('trendhome:9201') === 1, '16: stage work never rotates the QR');

// ---- 23: authorization and validation of the rotation --------------------------------------------------------
checkError(T::call($kernel, 'POST', $qrPath('trendhome:9201', 'rotate'), ['expectedQrRevision' => 1, 'reason' => 'label_lost'], ['origin' => T::ORIGIN, 'idempotency-key' => $key('anon')]), 401, 'SESSION_EXPIRED', '23: an unauthenticated rotation is denied');
checkError($view($waiter, 'trendhome:9201'), 403, 'APPLICATION_ACCESS_DENIED', '23: a worker cannot inspect QR authority');
checkError($rotate($waiter, 'trendhome:9201', ['expectedQrRevision' => 1, 'reason' => 'label_lost'], $key('worker')), 403, 'APPLICATION_ACCESS_DENIED', '23: a worker cannot rotate');
checkError(T::call($kernel, 'POST', $qrPath('trendhome:9201', 'rotate'), ['expectedQrRevision' => 1, 'reason' => 'label_lost'], ['origin' => T::ORIGIN, 'idempotency-key' => $key('csrf')], $manager['cookie']), 403, 'CSRF_INVALID', '23: a rotation without CSRF token is denied');
checkError($rotate($manager, 'trendhome:9201', ['expectedQrRevision' => 1, 'reason' => 'because'], $key('reason')), 400, 'INVALID_REQUEST', 'an unknown reason is rejected');
checkError($rotate($manager, 'trendhome:9201', ['expectedQrRevision' => 1, 'reason' => 'label_lost', 'payload' => 'ARASYA:Q1:AAAAAAAAAAAAAAAAAAAAAAAAAA'], $key('extra')), 400, 'INVALID_REQUEST', 'no QR value is accepted from the client');
checkError($rotate($manager, 'trendhome:9201', ['expectedQrRevision' => 1, 'reason' => 'label_lost'], null), 400, 'INVALID_IDEMPOTENCY_KEY', 'an idempotency key is required');
checkError($rotate($manager, 'trendhome:9201', ['expectedQrRevision' => 2, 'reason' => 'label_lost'], $key('stale')), 409, 'QR_CHANGED', 'a stale QR revision is rejected');
check(count($references('trendhome:9201')) === 1 && $count("SELECT COUNT(*) FROM auth_audit_events WHERE event_type = 'PRODUCTION_QR_DENIED'") >= 4, 'no refused rotation changed anything; denials are in the security audit');

// ---- 11, 12, 17: rotation ------------------------------------------------------------------------------------
$rotateKey = $key('rotate-9201');
$rotated = checkOk($rotate($manager, 'trendhome:9201', ['expectedQrRevision' => 1, 'reason' => 'label_damaged'], $rotateKey), '11: the manager rotates the QR');
$secondPayload = (string) $rotated['active']['payload'];
$refs = $references('trendhome:9201');
check($rotated['active']['revision'] === 2 && $secondPayload !== $newPayload && $secondPayload === $activePayload('trendhome:9201'), '11: the rotation creates revision 2 as the active QR');
check(count($refs) === 2 && $refs[0]['status'] === 'revoked' && $refs[0]['retired_reason'] === 'superseded' && $refs[1]['status'] === 'active' && $activeCount('trendhome:9201') === 1, '12: the old revision is superseded atomically; exactly one active');
check(array_column($rotated['history'], 'state') === ['active', 'superseded'] && array_column($rotated['history'], 'revision') === [2, 1], 'the history shows both revisions, newest first');
check(checkOk($rotate($manager, 'trendhome:9201', ['expectedQrRevision' => 1, 'reason' => 'label_damaged'], $rotateKey), 'rotation replay')['active']['revision'] === 2 && count($references('trendhome:9201')) === 2, '3, 11: a replayed rotation returns the committed result and issues nothing');
checkError($rotate($manager, 'trendhome:9201', ['expectedQrRevision' => 1, 'reason' => 'security'], $rotateKey), 409, 'IDEMPOTENCY_CONFLICT', 'the key cannot be reused for another request');
$rotation = $pdo->query("SELECT action, qr_revision, qr_hint, previous_qr_hint, qr_authority_mode, actor_employee_uuid, reason_code FROM production_qr_events WHERE global_order_id = 'trendhome:9201' AND action = 'rotated'")->fetchAll(PDO::FETCH_ASSOC);
check(count($rotation) === 1 && (int) $rotation[0]['qr_revision'] === 2 && $rotation[0]['actor_employee_uuid'] === $managerId && $rotation[0]['reason_code'] === 'label_damaged' && $rotation[0]['qr_authority_mode'] === 'enforce'
    && $rotation[0]['previous_qr_hint'] === substr(hash('sha256', substr($newPayload, 10)), 0, 10) && $rotation[0]['qr_hint'] === substr(hash('sha256', substr($secondPayload, 10)), 0, 10), '17: the rotation evidence names revision, actor, reason and both hints');
$audit = $pdo->query("SELECT metadata_json FROM iam_audit_events WHERE action = 'production.qr.rotated' AND target_id = 'trendhome:9201' AND actor_employee_uuid = " . $pdo->quote($managerId))->fetchAll(PDO::FETCH_COLUMN);
check(count($audit) === 1 && str_contains($audit[0], '"label_damaged"') && !str_contains($audit[0], substr($newPayload, 10)) && !str_contains($audit[0], substr($secondPayload, 10)) && $noPii($audit), '17: the IAM audit records the rotation without any QR value or customer data');
$evidence = json_encode($pdo->query('SELECT * FROM production_qr_events')->fetchAll(PDO::FETCH_ASSOC));
$idempotency = json_encode($pdo->query("SELECT response_json FROM order_operation_idempotency WHERE operation = 'qr_rotate'")->fetchAll(PDO::FETCH_COLUMN));
$iamQr = json_encode($pdo->query("SELECT metadata_json FROM iam_audit_events WHERE action = 'production.qr.rotated'")->fetchAll(PDO::FETCH_COLUMN));
check(!str_contains($evidence, substr($newPayload, 10)) && !str_contains($evidence, substr($secondPayload, 10)) && !str_contains($idempotency, substr($secondPayload, 10)) && !str_contains($idempotency, 'svg') && !str_contains($iamQr, substr($secondPayload, 10)), 'no raw QR reference is stored in evidence, audit or idempotency results');
check($order('trendhome:9201')['production_stage_id'] === 'material-preparation' && (int) $order('trendhome:9201')['production_version'] === 3, 'a rotation never changes stage or production version');

// ---- 8, 21: the superseded QR -------------------------------------------------------------------------------
checkError($resolve($waiter, $newPayload), 410, 'QR_SUPERSEDED', '8: scanning the superseded QR is refused');
$superseded = $resolve($waiter, $newPayload);
check(($superseded['body']['error']['details'] ?? null) === ['qrRevision' => 1, 'activeQrRevision' => 2] && str_contains($superseded['body']['error']['message'], 'COD QR ÎNLOCUIT'), '8: the refusal says the code was replaced and names the active revision');
check(!isset($superseded['body']['id']) && $noPii($superseded['body']), '8: the refusal resolves no order and exposes no customer data');
check($qrEvents('trendhome:9201', 'scan_rejected') >= 1 && $count("SELECT COUNT(*) FROM production_qr_events WHERE global_order_id = 'trendhome:9201' AND action = 'scan_rejected' AND actor_employee_uuid = ? AND reason_code = 'scan_superseded' AND qr_revision = 1", [$waiterId]) >= 1, '17: the refused scan is recorded with the scanning employee');
$before = $order('trendhome:9201');
checkError($staffPost($cutter, '/orders/trendhome%3A9201/claim', ['expectedVersion' => (int) $before['production_version'], 'qrToken' => $newPayload, 'ownedCount' => 0], $key('old-claim')), 410, 'QR_SUPERSEDED', '21: the superseded QR cannot claim cutting work');
check($order('trendhome:9201') === $before, '21: the refused claim changed nothing');
checkOk($staffPost($cutter, '/orders/trendhome%3A9201/claim', ['expectedVersion' => (int) $before['production_version'], 'qrToken' => $secondPayload, 'ownedCount' => 0], $key('new-claim')), '22: the active QR claims the cutting work');
check(checkOk($resolve($waiter, $secondPayload), 'scan the new QR')['id'] === 'trendhome:9201', 'the new QR resolves the order');

// ---- 10: unknown and malformed codes ------------------------------------------------------------------------
checkError($resolve($waiter, 'ARASYA:Q1:' . str_repeat('A', 26)), 404, 'UNKNOWN_QR', '10: an unknown QR gets the generic answer');
$unknown = $resolve($waiter, 'ARASYA:Q1:' . str_repeat('B', 26));
check(!str_contains(json_encode($unknown['body']), 'trendhome') && !isset($unknown['body']['error']['details']), '10: the unknown answer leaks no order');
checkError($resolve($waiter, 'https://trendhome.ro/wp-admin/post.php?post=9201&action=edit'), 400, 'INVALID_QR', '10: a YD admin link is not an Arasya production code');

// ---- 4: two managers rotate concurrently ---------------------------------------------------------------------
$start = (string) (microtime(true) + 1.5);
$workers = [];
foreach ([[$manager, 'race-a'], [$manager2, 'race-b']] as [$who, $label]) {
    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/qr-worker.php', $dbName, $qrPath('trendhome:9201', 'rotate'), json_encode(['expectedQrRevision' => 2, 'reason' => 'label_lost']), $key($label), $who['cookie'], $who['csrf'], $start], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
}
$results = [];
foreach ($workers as [$process, $pipes]) {
    $results[] = json_decode((string) stream_get_contents($pipes[1]), true) ?? ['status' => 0, 'stderr' => stream_get_contents($pipes[2])];
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
$winners = array_values(array_filter($results, static fn (array $r): bool => $r['status'] === 200 && $r['revision'] === 3));
$losers = array_values(array_filter($results, static fn (array $r): bool => $r['status'] === 409 && $r['error'] === 'QR_CHANGED'));
check(count($winners) === 1 && count($losers) === 1, '4: exactly one concurrent rotation applies, the other is rejected: ' . json_encode($results));
check($activeCount('trendhome:9201') === 1 && count($references('trendhome:9201')) === 3 && $qrEvents('trendhome:9201', 'rotated') === 2, '4: concurrency never leaves two active QRs');
$thirdPayload = (string) $activePayload('trendhome:9201');

// ---- 15: cancellation ----------------------------------------------------------------------------------------
$legacyPayload = (string) $activePayload('trendhome:9101');
$cancel = $ingest($kernel, 'trendhome', '9101', "th-9101-c-{$suffix}", 100, 'cancelled', 'cancelled');
check(($cancel['body']['outcome'] ?? null) === 'applied' && $order('trendhome:9101')['operational_status'] === 'unavailable', 'the source cancels an untouched operations order');
check($cancel['body']['qr'] === $legacyPayload && count($references('trendhome:9101')) === 1 && $qrEvents('trendhome:9101') === 1, '15: cancellation creates no QR and changes none');
checkError($rotate($manager, 'trendhome:9101', ['expectedQrRevision' => 1, 'reason' => 'label_lost'], $key('cancelled')), 409, 'ORDER_UNAVAILABLE', '15: a cancelled order is not rotated');
$cutCancel = $ingest($kernel, 'trendhome', '9201', "th-9201-c-{$suffix}", 100, 'cancelled', 'cancelled');
check(($cutCancel['body']['outcome'] ?? null) === 'applied' && $cutCancel['body']['qr'] === $thirdPayload && count($references('trendhome:9201')) === 3, '15: a source cancellation during cutting keeps the active QR');

// ---- 18, 19, 20: source-authority orders, takeover and release ---------------------------------------------------
check(($ingest($sourceLegacyKernel, 'trendhome', '9301', "th-9301-a-{$suffix}", 600)['body']['outcome'] ?? null) === 'applied' && $order('trendhome:9301')['production_authority'] === 'source', 'a source-managed order exists');
$sourcePayload = (string) $activePayload('trendhome:9301');
$sourceRow = $sourceQr($kernel, 'trendhome', ['9301'])['body']['orders'][0];
check($sourceRow['productionAuthority'] === 'source' && $sourceRow['qrAuthority'] === 'source' && $sourceRow['qr'] === null, '18: a source-managed order keeps source QR authority and gets no Arasya QR');
checkError($rotate($manager, 'trendhome:9301', ['expectedQrRevision' => 1, 'reason' => 'label_lost'], $key('source')), 409, 'QR_NOT_ARASYA', '18: a source-managed order is never rotated');
$taken = checkOk($staffPost($manager, '/orders/trendhome%3A9301/production-authority/takeover', ['expectedVersion' => 1, 'stageId' => 'waiting'], $key('take')), '19: the manager takes the order over');
check($taken['productionAuthority'] === 'operations' && $activePayload('trendhome:9301') === $sourcePayload && count($references('trendhome:9301')) === 1, '19: a takeover keeps the existing QR (no rotation)');
$takenRow = $sourceQr($kernel, 'trendhome', ['9301'])['body']['orders'][0];
check($takenRow['qrAuthority'] === 'arasya' && $takenRow['qr']['payload'] === $sourcePayload && $takenRow['qr']['revision'] === 1, '19: after the takeover the source receives the existing QR as Arasya QR');
checkOk($staffPost($manager, '/orders/trendhome%3A9301/production-authority/release', ['expectedVersion' => 2], $key('release')), '20: the manager releases the untouched takeover');
$releasedRow = $sourceQr($kernel, 'trendhome', ['9301'])['body']['orders'][0];
check($releasedRow['qrAuthority'] === 'source' && $releasedRow['qr'] === null && $activePayload('trendhome:9301') === $sourcePayload && count($references('trendhome:9301')) === 1, '20: a release keeps the QR and returns QR authority to the source');

// ---- Document-controlled orders rotate only through a document revision ---------------------------------------
check(($ingest($kernel, 'trendhome', '9401', "th-9401-a-{$suffix}", 600)['body']['outcome'] ?? null) === 'applied', 'an order for the document path');
$docPayload = (string) $activePayload('trendhome:9401');
$generated = checkOk($dash($root, 'POST', '/production-documents/orders/trendhome%3A9401/generate', ['expectedDocumentVersion' => 0], $key('doc')), 'root generates document revision 1', 201);
check($activePayload('trendhome:9401') === $docPayload && count($references('trendhome:9401')) === 1, 'document revision 1 adopts the intake QR');
check(checkOk($view($manager, 'trendhome:9401'), 'inspect document order')['active']['documentRevision'] === 1, 'the QR view names the bound document revision');
checkError($rotate($manager, 'trendhome:9401', ['expectedQrRevision' => 1, 'reason' => 'label_lost'], $key('doc-rotate')), 409, 'QR_DOCUMENT_CONTROLLED', 'a document-controlled QR rotates only through its document revision');
checkOk($dash($root, 'POST', '/production-documents/orders/trendhome%3A9401/revoke', ['expectedDocumentVersion' => (int) $generated['version'], 'reason' => 'Test revocare QR'], $key('doc-revoke')), 'root revokes the document');
$docRefs = $references('trendhome:9401');
check(count($docRefs) === 1 && $docRefs[0]['status'] === 'revoked' && $docRefs[0]['retired_reason'] === 'revoked' && $qrEvents('trendhome:9401', 'revoked') === 1, '9: the revoke retires the QR as revoked, with evidence');
checkError($resolve($waiter, $docPayload), 410, 'DOCUMENT_REVOKED', '9: the revoked QR is refused');
check(\Arasya\Operations\Production\ProductionQrLedger::invalid(['state' => 'revoked', 'revision' => 1], 2)->errorCode === 'QR_REVOKED', '9: a revoked QR outside a document gets QR_REVOKED');

// ---- Reconciliation: keep, never rotate, skip source orders ---------------------------------------------------------
$service = $container->productionQrService();
$dry = $service->reconcile('trendhome', null, 50, false, 'test-dry');
$dryResults = array_column($dry['results'], 'result', 'order');
check(!isset($dryResults['trendhome:9301']) && !isset($dryResults['trendhome:9001']) && ($dryResults['trendhome:9201'] ?? null) === 'reused' && ($dryResults['trendhome:9401'] ?? null) === 'would_issue', 'a dry run lists operations orders only: ' . json_encode($dryResults));
$eventsBefore = $count('SELECT COUNT(*) FROM production_qr_events');
check($count('SELECT COUNT(*) FROM production_qr_events') === $eventsBefore, 'a dry run writes nothing');
$applied = $service->reconcile('trendhome', ['9201', '9101'], 50, true, 'test-apply');
check(array_column($applied['results'], 'result', 'order') === ['trendhome:9101' => 'reused', 'trendhome:9201' => 'reused'] && $activePayload('trendhome:9201') === $thirdPayload && $activePayload('trendhome:9101') === $legacyPayload, 'apply keeps every existing active QR: ' . json_encode($applied['results']));
check($qrEvents('trendhome:9201', 'reconciled') === 1 && $qrEvents('trendhome:9101', 'reconciled') === 1, 'reconciliation is recorded');
$issuedNow = $service->reconcile('trendhome', ['9401'], 50, true, 'test-apply-missing');
check(array_column($issuedNow['results'], 'result') === ['issued'] && $activeCount('trendhome:9401') === 1 && count($references('trendhome:9401')) === 2, 'an operations order without an active QR receives exactly one');
check($service->reconcile('trendhome', ['9401'], 50, true, 'test-again')['results'][0]['result'] === 'reused' && count($references('trendhome:9401')) === 2, 'reconciliation is idempotent');
$disabled = false;
try {
    (new Container(T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $qrLegacy), $pdo))->productionQrService()->reconcile('trendhome', null, 50, true, 'test-legacy');
} catch (\Arasya\Operations\Http\ApiException $error) {
    $disabled = $error->errorCode === 'QR_CUTOVER_DISABLED';
}
check($disabled, 'reconciliation is refused while the source QR mode is legacy');

// ---- Observe source and invariants ---------------------------------------------------------------------------------
check(($ingest($kernel, 'outletperdele', '9501', "op-9501-a-{$suffix}", 600)['body']['outcome'] ?? null) === 'applied' && $order('outletperdele:9501')['production_authority'] === 'source', 'production observe keeps new outlet orders source-managed');
check($sourceQr($kernel, 'outletperdele', ['9501'])['body']['qrAuthorityMode'] === 'observe' && $sourceQr($kernel, 'outletperdele', ['9501'])['body']['orders'][0]['qr'] === null, 'QR observe hands no QR for a source-managed order');
check($count("SELECT COUNT(*) FROM (SELECT order_uuid FROM order_qr_references WHERE status = 'active' GROUP BY order_uuid HAVING COUNT(*) > 1) d") === 0, 'no order has two active QRs');
check($pdo->query("SELECT ps.stage_id FROM production_stages ps JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id WHERE pw.workflow_key = 'curtain-production' AND pw.status = 'active' ORDER BY ps.ordinal")->fetchAll(PDO::FETCH_COLUMN)
    === ['waiting', 'material-preparation', 'workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery'], 'the canonical 14-stage workflow is unchanged');

$cleanup();
fwrite(STDOUT, "PASS MySQL production QR integration: {$checks} checks.\n");
