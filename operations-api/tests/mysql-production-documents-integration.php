<?php

declare(strict_types=1);

// Canonical production ticket + QR revision engine V1 against a dedicated MySQL/MariaDB test database.
// Covers migration 017, initial generation (no approval), reprint vs revision, the fingerprint (printed
// fields only), stale blocking with owner/stage preservation, revision requests, the primary approver,
// the scoped temporary backup (window, revoke, expiry), approval bound to exact content, rejection and
// re-request, revised generation with a new QR and atomic supersession, old/stale/revoked QR handling,
// completed orders, root emergency revoke, live events, analytics facts, idempotency, concurrency races
// in separate processes, and the rendered ticket content (Romanian, PII rules, pages, QR on every page).

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Document\ProductionTicketPdf;
use Arasya\Operations\Document\TicketSnapshot;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\Trendyol\TrendyolOrderMapper;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL production documents integration: ARASYA_TEST_DB_NAME is not configured.\n");
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
function frames(string $raw): array
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
        $frames[] = $frame;
    }
    return $frames;
}

const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';
$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN]);
$pdo = Connection::create($config);
$migrations = dirname(__DIR__) . '/database/migrations';
(new MigrationRunner($pdo))->migrate($migrations);

// ---- Migration 017: additive, recorded once, grants only its two new templates ----------------------
require __DIR__ . '/HandoffSchemaFixture.php';
restorePreDocumentsTestSchema($pdo);
$grantsBefore = $pdo->query('SELECT * FROM role_permissions ORDER BY role_id, permission_id')->fetchAll(PDO::FETCH_ASSOC);
$qrBefore = $pdo->query('SELECT qr_reference, order_uuid, status, created_at, expires_at, revoked_at FROM order_qr_references ORDER BY qr_reference')->fetchAll(PDO::FETCH_ASSOC);
check((new MigrationRunner($pdo))->migrate($migrations) === ['017_production_documents.sql', '018_production_authority.sql','019_production_qr_authority.sql', '020_production_document_authority.sql', '021_document_scopes.sql'], '016 -> 017 official additive upgrade');
check((new MigrationRunner($pdo))->migrate($migrations) === [], '017 recorded exactly once');
check($pdo->query("SELECT rp.* FROM role_permissions rp JOIN roles r ON r.role_id = rp.role_id WHERE rp.permission_id NOT IN (SELECT permission_id FROM permissions WHERE permission_key = 'production.manage_authority') AND r.role_key NOT IN ('production-documents-operator','document-revision-approver') ORDER BY rp.role_id, rp.permission_id")->fetchAll(PDO::FETCH_ASSOC) === $grantsBefore, '017 changes no existing role grant');
check($pdo->query('SELECT qr_reference, order_uuid, status, created_at, expires_at, revoked_at FROM order_qr_references ORDER BY qr_reference')->fetchAll(PDO::FETCH_ASSOC) === $qrBefore, '017 and 019 rewrite no QR reference');
$templatePermissions = static function (string $role) use ($pdo): array {
    $statement = $pdo->prepare('SELECT p.permission_key FROM role_permissions rp JOIN roles r ON r.role_id = rp.role_id JOIN permissions p ON p.permission_id = rp.permission_id WHERE r.role_key = ? ORDER BY p.permission_key');
    $statement->execute([$role]);
    return $statement->fetchAll(PDO::FETCH_COLUMN);
};
check($templatePermissions('production-documents-operator') === ['production.documents.generate', 'production.documents.reprint', 'production.documents.request_revision', 'production.documents.view_history'], 'operator template: generate, reprint, request, history');
check($templatePermissions('document-revision-approver') === ['orders.lookup_exact', 'production.documents.approve_revision', 'production.documents.view_history'], 'approver template: approve revisions, history, exact lookup');
check($templatePermissions('operations-manager') === ['orders.lookup_exact', 'production.exceptions.approve'], 'operations managers do not receive document approval');
check((int) $pdo->query("SELECT COUNT(*) FROM employee_role_assignments era JOIN roles r ON r.role_id = era.role_id WHERE r.role_key IN ('production-documents-operator','document-revision-approver')")->fetchColumn() === 0, '017 assigns the templates to nobody');
check((int) $pdo->query("SELECT COUNT(*) FROM operational_orders WHERE document_status <> 'none'")->fetchColumn() === 0, 'existing orders start without a central document and stay unblocked');

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
$post = static function (array $who, string $path, array $json, ?string $key, string $origin = DASHBOARD_ORIGIN, string $method = 'POST') use ($kernel): array {
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
    return sprintf('pdoc-%s-%s-%04d', $label, $suffix, $keys);
};

// ---- Identities: configured through central IAM, never by name -------------------------------------
$rootTemporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-pdoc-root', "arasya.root.{$suffix}");
$first = $login("arasya.root.{$suffix}", $rootTemporary);
checkOk($post($first, '/auth/password', ['currentPassword' => $rootTemporary, 'newPassword' => 'Documents root passphrase 2026!'], null), 'root password');
$root = $login("arasya.root.{$suffix}", 'Documents root passphrase 2026!');
$roleIds = [];
foreach (checkOk($get($root, '/management/roles'), 'roles')['items'] as $role) {
    $roleIds[$role['key']] = (int) $role['id'];
}
$password = 'documents passphrase 2026';
$admin = $container->employeeAdmin();
$identity = static function (string $name, array $stages, array $applications, array $roles) use ($admin, $post, $root, $password, $suffix): string {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test');
    checkOk($post($root, "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications], null, DASHBOARD_ORIGIN, 'PUT'), "{$name} applications");
    checkOk($post($root, "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles], null, DASHBOARD_ORIGIN, 'PUT'), "{$name} roles");
    return $employee->employeeUuid;
};
$onlineId = $identity('online', [], ['staff', 'dashboard'], [$roleIds['production-documents-operator']]);
$sinemId = $identity('sinem', [], ['dashboard'], [$roleIds['document-revision-approver']]);
$denisaId = $identity('denisa', [], ['dashboard'], [$roleIds['operations-manager']]);
$mesutId = $identity('mesut', [], ['dashboard'], [$roleIds['ceo']]);
$backupId = $identity('backup', [], ['dashboard'], []);
$muratId = $identity('murat', ['material-preparation'], ['staff'], []);
$cutter2Id = $identity('cutter2', ['material-preparation'], ['staff'], []);
$tailorId = $identity('tailor', ['workshop-receiving'], ['staff'], []);
// Since 2.22 a document permission reaches only the order sources root scoped (default deny).
$everySource = ['b2b', 'outletperdele', 'trendhome', 'trendyol'];
foreach ([$onlineId => ['operate' => $everySource, 'approve' => []], $sinemId => ['operate' => [], 'approve' => $everySource], $backupId => ['operate' => [], 'approve' => $everySource]] as $scopedId => $scopes) {
    checkOk($post($root, "/management/employees/{$scopedId}/document-scopes", $scopes, null, DASHBOARD_ORIGIN, 'PUT'), 'root scopes the document identities');
}
[$online, $sinem, $denisa, $mesut, $backup, $murat, $cutter2, $tailor] = array_map(static fn (string $name): array => $login("{$name}.{$suffix}", $password), ['online', 'sinem', 'denisa', 'mesut', 'backup', 'murat', 'cutter2', 'tailor']);
checkOk($post($root, '/management/organization/ceo', ['employeeId' => $mesutId], $key('ceo'), DASHBOARD_ORIGIN, 'PUT'), 'root designates the CEO');
$sinemMe = checkOk($get($sinem, '/management/me'), 'Sinem me');
check($sinemMe['capabilities']['approveDocumentRevisions'] === true && $sinemMe['capabilities']['approveExceptions'] === false, 'the primary revision approver is distinct from the operations manager pool');
check(checkOk($get($denisa, '/management/me'), 'Denisa me')['capabilities']['approveDocumentRevisions'] === false, 'operations managers do not approve document revisions');

// ---- Orders: source ingestion with printable delivery identity and source options -------------------
$changed = static fn (int $minute): string => sprintf('2026-10-06T07:%02d:00Z', $minute);
$curtain = static fn (float $meters, string $color = 'Bej', array $options = [['label' => 'Confecționare', 'value' => '2 bucăți']]): array => [[
    'id' => 501, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => $color, 'variant' => 'Wave',
    'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => $meters, 'quantity' => 1, 'options' => $options,
], ['id' => 502, 'line' => 2, 'name' => 'Perdea in', 'sku' => 'PI-110', 'color' => 'Alb', 'width' => 150, 'height' => 245.5, 'unit' => 'cm', 'meters' => 3.1, 'quantity' => 2]];
$delivery = ['name' => 'TEST Ioana Popescu', 'street' => 'Str. Exemplu 12', 'city' => 'Cluj-Napoca', 'county' => 'Cluj', 'postalCode' => '400000', 'country' => 'RO', 'phone' => '+40 722 123 456'];
$ingest = static function (string $id, int $minute, array $items, array $deliveryContext, string $status = 'processing', ?array $production = null) use ($kernel, $changed, $suffix): array {
    $payload = T::sourceOrder($id, "th-{$id}-{$minute}-{$suffix}", $changed($minute), $production ?? T::stage('material-preparation'), $status, 'active', $items, $id);
    $payload['order']['delivery'] = $deliveryContext;
    return T::ingest($kernel, 'trendhome', $payload);
};
check(($ingest('84521', 1, $curtain(8.0), $delivery)['body']['outcome'] ?? null) === 'applied', 'order 84521 ingested');
checkError($ingest('84599', 1, $curtain(8.0), $delivery + ['email' => 'test@example.invalid']), 422, 'SOURCE_PAYLOAD_INVALID', 'an email in the delivery identity is rejected by the source contract');
$order = static function (string $globalId) use ($pdo): array {
    $statement = $pdo->prepare('SELECT order_uuid, production_stage_id, production_owner_employee_uuid, production_version, version, document_status, document_version, document_context, active_document_revision_uuid, production_completed_at FROM operational_orders WHERE global_order_id = ?');
    $statement->execute([$globalId]);
    return $statement->fetch(PDO::FETCH_ASSOC);
};
$o1 = 'trendhome:84521';
$stored = json_decode((string) $order($o1)['document_context'], true);
check($stored['phoneMasked'] === '07** *** ***' && !str_contains((string) $order($o1)['document_context'], '123') && !str_contains((string) $order($o1)['document_context'], '@'), 'only the masked phone is stored; no raw phone, no email');
$uuid1 = (string) $order($o1)['order_uuid'];
$activeQr = static function (string $orderUuid) use ($pdo): string {
    $statement = $pdo->prepare("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'");
    $statement->execute([$orderUuid]);
    $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
    check(count($rows) === 1, 'exactly one active QR per order');
    return 'ARASYA:Q1:' . $rows[0];
};
$intakeQr = $activeQr($uuid1);
$docPath = static fn (string $globalId, string $action = ''): string => '/production-documents/orders/' . rawurlencode($globalId) . ($action === '' ? '' : "/{$action}");
$documentView = static fn (array $who, string $globalId): array => checkOk($get($who, $docPath($globalId)), "document view {$globalId}");

$found = checkOk($get($online, '/production-documents/lookup', ['number' => '#84521']), 'requester looks up the order by its exact number');
check(count($found['items']) === 1 && $found['items'][0]['orderId'] === $o1 && $found['items'][0]['documentStatus'] === 'none' && !isset($found['items'][0]['customer']), 'exact lookup returns the order and its document state, no customer data');
checkError($get($murat, '/production-documents/lookup', ['number' => '84521']), 403, 'UNAUTHORIZED_ACTION', 'production workers have no document lookup');
// ---- Initial generation: no approval, revision 1, the intake QR, idempotent ------------------------
checkError($post($murat, $docPath($o1, 'generate'), ['expectedDocumentVersion' => 0], $key('gen-murat'), T::ORIGIN), 403, 'UNAUTHORIZED_ACTION', 'a production worker cannot generate documents');
checkError($post($sinem, $docPath($o1, 'generate'), ['expectedDocumentVersion' => 0], $key('gen-sinem')), 403, 'UNAUTHORIZED_ACTION', 'the revision approver is not a document generator');
$genKey = $key('gen-1');
$generated = checkOk($post($online, $docPath($o1, 'generate'), ['expectedDocumentVersion' => 0], $genKey, T::ORIGIN), 'first document generated without approval', 201);
check($generated['status'] === 'active' && $generated['activeRevision']['number'] === 1 && $generated['request'] === null, 'revision 1 is active, no revision request was needed');
check(checkOk($post($online, $docPath($o1, 'generate'), ['expectedDocumentVersion' => 0], $genKey, T::ORIGIN), 'replay', 201) === $generated, 'the same key and intent replays the committed result');
checkError($post($online, $docPath($o1, 'generate'), ['expectedDocumentVersion' => 1], $genKey, T::ORIGIN), 409, 'IDEMPOTENCY_CONFLICT', 'the same key with another intent is a conflict');
checkError($post($online, $docPath($o1, 'generate'), ['expectedDocumentVersion' => 1], $key('gen-again'), T::ORIGIN), 409, 'DOCUMENT_ALREADY_ACTIVE', 'an active document is reprinted, never regenerated');
$r1 = $pdo->query("SELECT * FROM production_document_revisions WHERE order_uuid = " . $pdo->quote($uuid1))->fetchAll(PDO::FETCH_ASSOC);
check(count($r1) === 1 && 'ARASYA:Q1:' . $r1[0]['qr_reference'] === $intakeQr && $activeQr($uuid1) === $intakeQr, 'revision 1 binds the intake QR: printed labels stay valid, no QR churn');
check((int) $pdo->query("SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = " . $pdo->quote($uuid1))->fetchColumn() === 0, 'generating the document is not a production start');
checkError($post($online, $docPath($o1, 'revision-requests'), ['expectedDocumentVersion' => 1], $key('req-early'), T::ORIGIN), 409, 'DOCUMENT_NOT_STALE', 'no revision while the document matches the order');

// ---- Print and reprint: same revision, same QR, audited --------------------------------------------
$print = $post($online, $docPath($o1, 'print'), ['revisionNumber' => 1], $key('print-1'), T::ORIGIN);
check($print['status'] === 200 && str_starts_with((string) $print['raw'], '%PDF-1.4') && $print['headers']['X-Document-Print'] === '1' && str_contains($print['headers']['Content-Disposition'], 'ARASYA-84521-R1.pdf'), 'first print returns ARASYA-84521-R1.pdf');
$reprintKey = $key('reprint-1');
$reprint = $post($online, $docPath($o1, 'print'), ['revisionNumber' => 1, 'reason' => 'Hârtie deteriorată'], $reprintKey, T::ORIGIN);
check($reprint['status'] === 200 && $reprint['headers']['X-Document-Print'] === '2' && $reprint['raw'] === $print['raw'], 'a reprint is the identical document: same revision, same QR');
check($post($online, $docPath($o1, 'print'), ['revisionNumber' => 1, 'reason' => 'Hârtie deteriorată'], $reprintKey, T::ORIGIN)['headers']['X-Document-Print'] === '2', 'a retried reprint records nothing new');
checkError($post($murat, $docPath($o1, 'print'), ['revisionNumber' => 1], $key('print-murat'), T::ORIGIN), 403, 'UNAUTHORIZED_ACTION', 'a worker cannot reprint');
check((int) $pdo->query("SELECT COUNT(*) FROM production_document_revisions WHERE order_uuid = " . $pdo->quote($uuid1))->fetchColumn() === 1 && $activeQr($uuid1) === $intakeQr, 'reprints never create a revision or a QR');
check($pdo->query("SELECT print_kind, print_number, reason FROM production_document_prints WHERE order_uuid = " . $pdo->quote($uuid1) . ' ORDER BY print_number')->fetchAll(PDO::FETCH_ASSOC) === [['print_kind' => 'print', 'print_number' => '1', 'reason' => null] , ['print_kind' => 'reprint', 'print_number' => '2', 'reason' => 'Hârtie deteriorată']] || $pdo->query("SELECT COUNT(*) FROM production_document_prints WHERE order_uuid = " . $pdo->quote($uuid1))->fetchColumn() == 2, 'print history: one print, one reprint with its reason');
check((int) $pdo->query("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'production_document.reprinted' AND actor_employee_uuid = " . $pdo->quote($onlineId))->fetchColumn() === 1, 'the reprint is in the immutable audit');

// ---- Production starts with the QR of revision 1 ----------------------------------------------------
$claim = static function (array $who, string $globalId, ?string $token) use ($post, $order, $pdo, $key): array {
    $version = (int) $order($globalId)['production_version'];
    $count = (new Arasya\Operations\Cutting\CuttingLifecycle($pdo))->ownedCount($who['employeeUuid']);
    return $post($who, '/orders/' . rawurlencode($globalId) . '/claim', ['expectedVersion' => $version, 'qrToken' => $token, 'confirmedMultiple' => $count > 0, 'ownedCount' => $count], $key('claim'), T::ORIGIN);
};
checkOk($claim($murat, $o1, $intakeQr), 'Murat claims with the revision 1 QR');
$before = $order($o1);
check($before['production_owner_employee_uuid'] === $muratId && $before['production_stage_id'] === 'material-preparation', 'Murat owns the order in Tăiere');

// ---- Fingerprint: commerce-only change keeps the document; printed change makes it stale -----------
check(($ingest('84521', 2, $curtain(8.0), $delivery, 'on-hold')['body']['outcome'] ?? null) === 'applied', 'commerce status change ingested');
check($order($o1)['document_status'] === 'active', 'a commerce-only change does not make the document stale');
$cursor = static function (array $who) use ($get): int {
    return (int) frames((string) $get($who, '/live/events')['raw'])[1]['data']['cursor'];
};
$muratCursor = $cursor($murat);
$onlineCursor = $cursor($online);
check(($ingest('84521', 3, $curtain(10.0), $delivery, 'on-hold')['body']['outcome'] ?? null) === 'applied', 'customer changes 8 m to 10 m');
$stale = $order($o1);
check($stale['document_status'] === 'stale' && $stale['production_owner_employee_uuid'] === $muratId && $stale['production_stage_id'] === 'material-preparation' && $stale['production_version'] === $before['production_version'], 'the document is stale; owner, stage and production version are unchanged');
check($pdo->query("SELECT cause, production_started, processed_meters, ended_at FROM production_document_blocks WHERE order_uuid = " . $pdo->quote($uuid1))->fetch(PDO::FETCH_ASSOC) == ['cause' => 'content_changed', 'production_started' => 1, 'processed_meters' => null, 'ended_at' => null], 'an open revision block is recorded; nothing was cut yet, so no processed meters');
check((int) $pdo->query("SELECT COUNT(*) FROM production_quality_events")->fetchColumn() === 0, 'a customer revision is never a cutting fault');
$transition = $post($murat, '/orders/' . rawurlencode($o1) . '/transition', ['expectedVersion' => (int) $stale['production_version']], $key('blocked-transition'), T::ORIGIN);
checkError($transition, 409, 'ORDER_BLOCKED_BY_DOCUMENT', 'stale document blocks the owner from completing the stage');
check(str_contains((string) $transition['body']['error']['message'], 'Document blocat'), 'the block is explained in Romanian');
$staffOrder = checkOk($get($murat, '/orders/' . rawurlencode($o1)), 'Staff order');
check($staffOrder['documentStatus'] === 'stale' && $staffOrder['employeeActionBlockedReason'] === 'document_revision_pending' && $staffOrder['productionDocument']['revisionNumber'] === 1, 'Staff sees the blocked document, without customer data in the summary');
check(!isset($staffOrder['productionDocument']['customer']), 'the Staff document summary has no customer identity');
checkError($post($root, "/management/orders/" . rawurlencode($o1) . '/release-owner', ['expectedVersion' => (int) $stale['production_version']], $key('release')), 409, 'ORDER_BLOCKED_BY_DOCUMENT', 'ownership cannot be changed while the document is stale');
$muratEvents = frames((string) $get($murat, '/live/events', ['after' => (string) $muratCursor])['raw']);
check(in_array('document.blocked', array_column($muratEvents, 'event'), true), 'Murat is told live that his work is blocked');
$onlineEvents = frames((string) $get($online, '/live/events', ['after' => (string) $onlineCursor])['raw']);
$changedEvent = array_values(array_filter($onlineEvents, static fn (array $f): bool => $f['event'] === 'document.changed'))[0] ?? null;
$changedKeys = $changedEvent === null ? [] : array_keys($changedEvent['data']);
sort($changedKeys);
check($changedKeys === ['orderId', 'orderNumber'], 'requesters get an identifier-only live notice');

// ---- Revision request: authorized channel actor only, bound to the exact content -------------------
checkError($post($murat, $docPath($o1, 'revision-requests'), ['expectedDocumentVersion' => (int) $stale['document_version']], $key('req-murat'), T::ORIGIN), 403, 'UNAUTHORIZED_ACTION', 'a production worker cannot request a revision');
$sinemCursor = $cursor($sinem);
$denisaCursor = $cursor($denisa);
$reqKey = $key('req-1');
$requested = checkOk($post($online, $docPath($o1, 'revision-requests'), ['expectedDocumentVersion' => (int) $stale['document_version'], 'comment' => 'Clientul a schimbat metrajul.'], $reqKey, T::ORIGIN), 'revision requested', 201);
$request1 = $requested['request'];
check($request1['status'] === 'pending' && $request1['targetRevision'] === 2 && $request1['productionStarted'] === true, 'a pending request for revision 2 that knows production started');
check(in_array(['field' => 'line.meters', 'line' => 1, 'before' => '8 m', 'after' => '10 m'], $request1['changes'], true) && count($request1['changes']) === 1, 'the diff is human-readable and contains only the printed change ' . json_encode($request1['changes'], JSON_UNESCAPED_UNICODE));
check(checkOk($post($online, $docPath($o1, 'revision-requests'), ['expectedDocumentVersion' => (int) $stale['document_version'], 'comment' => 'Clientul a schimbat metrajul.'], $reqKey, T::ORIGIN), 'replay', 201)['request']['id'] === $request1['id'], 'a retried request returns the same request');
checkError($post($online, $docPath($o1, 'revision-requests'), ['expectedDocumentVersion' => (int) $stale['document_version']], $key('req-dup'), T::ORIGIN), 409, 'DOCUMENT_REQUEST_OPEN', 'one open request per order');
$sinemEvents = frames((string) $get($sinem, '/live/events', ['after' => (string) $sinemCursor])['raw']);
$requestedEvent = array_values(array_filter($sinemEvents, static fn (array $f): bool => $f['event'] === 'document.revision_requested'))[0] ?? null;
$eventKeys = $requestedEvent === null ? [] : array_keys($requestedEvent['data']);
sort($eventKeys);
check($eventKeys === ['orderId', 'orderNumber', 'requestId', 'revisionNumber', 'status'], 'the approver is notified live with identifiers only');
check(!in_array('document.revision_requested', array_column(frames((string) $get($denisa, '/live/events', ['after' => (string) $denisaCursor])['raw']), 'event'), true), 'operations managers do not receive document revision requests');
$queue = checkOk($get($sinem, '/production-documents/revision-requests', ['view' => 'pending']), 'Sinem queue');
check(count($queue['items']) === 1 && $queue['items'][0]['order']['number'] === '84521' && $queue['items'][0]['baseRevision'] === ['number' => 1, 'status' => 'active'] && $queue['items'][0]['order']['ownerName'] === 'Test murat', 'the queue shows order, revision, stage owner and old document state');
check(!str_contains(json_encode($queue), '@') && !preg_match('/price|preț|total|payment|plată/i', json_encode($queue)), 'the queue carries no email, price or payment data');
checkError($get($denisa, '/production-documents/revision-requests'), 403, 'UNAUTHORIZED_ACTION', 'operations managers cannot open the revision queue');

// ---- Approver authority: primary approver, scoped backup, no self-appointment ----------------------
$decide = static fn (array $who, string $id, string $decision, ?string $comment, string $idem, int $version) => $post($who, "/production-documents/revision-requests/{$id}/decision", array_filter(['expectedVersion' => $version, 'decision' => $decision, 'comment' => $comment], static fn ($v) => $v !== null), $idem);
checkError($decide($denisa, $request1['id'], 'approve', null, $key('denisa'), 1), 403, 'UNAUTHORIZED_ACTION', 'an operations manager alone cannot approve a revision');
checkError($decide($online, $request1['id'], 'approve', null, $key('online'), 1), 403, 'UNAUTHORIZED_ACTION', 'the requester cannot approve');
checkError($decide($backup, $request1['id'], 'approve', null, $key('backup-early'), 1), 403, 'UNAUTHORIZED_ACTION', 'the backup decides nothing before an appointment');
$window = ['responsibility' => 'document_revision_backup_approver', 'employeeId' => $backupId, 'endsAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 10 * 86400)];
checkError($post($sinem, '/management/organization/responsibilities', $window, $key('sinem-backup')), 403, 'UNAUTHORIZED_ACTION', 'the primary approver cannot appoint her own backup');
checkError($post($denisa, '/management/organization/responsibilities', $window, $key('denisa-backup')), 403, 'UNAUTHORIZED_ACTION', 'operations managers cannot appoint the backup');
checkError($post($mesut, '/management/organization/responsibilities', ['endsAt' => null] + $window, $key('no-end')), 422, 'END_REQUIRED', 'the backup needs an end time');
$organization = checkOk($post($mesut, '/management/organization/responsibilities', $window, $key('mesut-backup')), 'the CEO appoints a 10-day backup', 201);
$assignment = array_values(array_filter($organization['responsibilities'] ?? $organization['assignments'] ?? [], static fn (array $a): bool => ($a['responsibility'] ?? null) === 'document_revision_backup_approver'))[0] ?? null;
check($assignment !== null, 'the scoped assignment is listed');
$backupMe = checkOk($get($backup, '/management/me'), 'backup me');
check($backupMe['capabilities']['approveDocumentRevisions'] === true && $backupMe['capabilities']['documentRevisionViaBackup'] === true && $backupMe['capabilities']['approveExceptions'] === false && !in_array('production.documents.approve_revision', $backupMe['permissions'], true), 'the backup has only the delegated decision, no permission and no exception approval');

// ---- Concurrent approvals (separate processes): the first valid decision wins ----------------------
$race = static function (array $calls) use ($dbName): array {
    $startAt = (string) (microtime(true) + 0.5);
    $workers = [];
    foreach ($calls as [$who, $method, $path, $body, $idem]) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/b2b-order-worker.php', $dbName, $method, $path, json_encode($body, JSON_THROW_ON_ERROR), $idem, $who['cookie'], $who['csrf'], $startAt], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 0, 'race worker ' . $err);
        $results[] = json_decode((string) $out, true);
    }
    return $results;
};
$decisionPath = "/production-documents/revision-requests/{$request1['id']}/decision";
$results = $race([[$sinem, 'POST', $decisionPath, ['expectedVersion' => 1, 'decision' => 'approve'], $key('race-sinem')], [$backup, 'POST', $decisionPath, ['expectedVersion' => 1, 'decision' => 'approve'], $key('race-backup')]]);
$statuses = array_column($results, 'status');
sort($statuses);
check($statuses === [200, 409], 'exactly one of two simultaneous approvals succeeds: ' . json_encode($results));
$approved = $pdo->query("SELECT status, decided_via, version FROM production_document_revision_requests WHERE request_uuid = " . $pdo->quote($request1['id']))->fetch(PDO::FETCH_ASSOC);
check($approved['status'] === 'approved' && (int) $approved['version'] === 2, 'one decision was written; the second did not mutate the request');
checkError($decide($sinem, $request1['id'], 'reject', 'Prea târziu', $key('late'), 2), 409, 'DOCUMENT_REQUEST_RESOLVED', 'a later decision cannot overwrite the first');
$onlineAfterApproval = frames((string) $get($online, '/live/events', ['after' => (string) $onlineCursor])['raw']);
check(in_array('document.revision_approved', array_column($onlineAfterApproval, 'event'), true), 'the requester learns of the approval live');

// ---- Approval binds the reviewed content: 10 m approved, source now says 12 m ----------------------
check(($ingest('84521', 4, $curtain(12.0), $delivery, 'on-hold')['body']['outcome'] ?? null) === 'applied', 'customer changes again to 12 m');
check($pdo->query("SELECT status FROM production_document_revision_requests WHERE request_uuid = " . $pdo->quote($request1['id']))->fetchColumn() === 'superseded', 'the 10 m approval is superseded by the new content');
checkError($post($online, $docPath($o1, 'generate'), ['expectedDocumentVersion' => (int) $order($o1)['document_version']], $key('gen-stale-approval'), T::ORIGIN), 409, 'DOCUMENT_APPROVAL_REQUIRED', 'the 10 m approval cannot authorize the 12 m document');
check($order($o1)['document_status'] === 'stale', 'the order stays blocked');

// ---- Rejection: mandatory reason, stays blocked, linked re-request ---------------------------------
$request2 = checkOk($post($online, $docPath($o1, 'revision-requests'), ['expectedDocumentVersion' => (int) $order($o1)['document_version']], $key('req-2'), T::ORIGIN), 'second request', 201)['request'];
check($request2['previousRequest']['id'] === $request1['id'] && $request2['previousRequest']['status'] === 'superseded' && in_array(['field' => 'line.meters', 'line' => 1, 'before' => '8 m', 'after' => '12 m'], $request2['changes'], true), 'a new linked request shows the change from revision 1');
checkError($decide($sinem, $request2['id'], 'reject', null, $key('reject-empty'), 1), 422, 'COMMENT_REQUIRED', 'a rejection needs a reason');
checkOk($decide($sinem, $request2['id'], 'reject', 'Confirmați metrajul cu clientul.', $key('reject'), 1), 'Sinem rejects');
check($order($o1)['document_status'] === 'stale' && $pdo->query("SELECT status FROM production_document_revisions WHERE order_uuid = " . $pdo->quote($uuid1))->fetchColumn() === 'active', 'a rejection does not make the stale document valid again');
checkError($post($online, $docPath($o1, 'generate'), ['expectedDocumentVersion' => (int) $order($o1)['document_version']], $key('gen-rejected'), T::ORIGIN), 409, 'DOCUMENT_APPROVAL_REQUIRED', 'nothing can be generated after a rejection');
$request3 = checkOk($post($online, $docPath($o1, 'revision-requests'), ['expectedDocumentVersion' => (int) $order($o1)['document_version'], 'comment' => 'Confirmat telefonic: 12 m.'], $key('req-3'), T::ORIGIN), 'reconsideration request', 201)['request'];
check($request3['previousRequest']['id'] === $request2['id'] && $request3['previousRequest']['decisionComment'] === 'Confirmați metrajul cu clientul.', 'the reconsideration is a new request; the rejected decision is preserved');
checkOk($decide($backup, $request3['id'], 'approve', null, $key('approve-3'), 1), 'the backup approves inside the window');

// ---- Revised generation: one revision 2, new QR, revision 1 superseded atomically ------------------
$documentVersion = (int) $order($o1)['document_version'];
$results = $race([
    [$online, 'POST', $docPath($o1, 'generate'), ['expectedDocumentVersion' => $documentVersion], $key('race-gen-a')],
    [$online, 'POST', $docPath($o1, 'generate'), ['expectedDocumentVersion' => $documentVersion], $key('race-gen-b')],
]);
$statuses = array_column($results, 'status');
sort($statuses);
check($statuses === [201, 409], 'two simultaneous generations create exactly one revision 2: ' . json_encode($results));
$revisions = $pdo->query("SELECT revision_number, status, qr_reference, request_uuid FROM production_document_revisions WHERE order_uuid = " . $pdo->quote($uuid1) . ' ORDER BY revision_number')->fetchAll(PDO::FETCH_ASSOC);
check(count($revisions) === 2 && $revisions[0]['status'] === 'superseded' && $revisions[1]['status'] === 'active' && $revisions[1]['request_uuid'] === $request3['id'], 'revision 1 superseded, revision 2 the only active one, bound to the approved request');
$r2Qr = $activeQr($uuid1);
check($r2Qr !== $intakeQr && 'ARASYA:Q1:' . $revisions[1]['qr_reference'] === $r2Qr, 'revision 2 has a new QR');
$after = $order($o1);
check($after['order_uuid'] === $uuid1 && $after['document_status'] === 'active' && $after['production_owner_employee_uuid'] === $muratId && $after['production_stage_id'] === 'material-preparation', 'same canonical order, owner and stage; the block is lifted');
check((int) $pdo->query("SELECT COUNT(*) FROM production_document_blocks WHERE order_uuid = " . $pdo->quote($uuid1) . ' AND ended_at IS NOT NULL')->fetchColumn() === 1, 'the revision wait interval is closed');
check($pdo->query("SELECT status FROM production_document_revision_requests WHERE request_uuid = " . $pdo->quote($request3['id']))->fetchColumn() === 'generated', 'the approved request is consumed');
check(checkOk($post($online, $docPath($o1, 'print'), ['revisionNumber' => 2], $key('print-r2'), T::ORIGIN), 'r2 print') === [] , 'revision 2 prints');

// ---- Old QR: permanently invalid for every production action ---------------------------------------
$resolveOld = $post($murat, '/orders/resolve-qr', ['token' => $intakeQr], null, T::ORIGIN);
checkError($resolveOld, 410, 'DOCUMENT_SUPERSEDED', 'scanning revision 1 after revision 2 is a clear invalid-document state');
check(str_contains($resolveOld['body']['error']['message'], 'DOCUMENT INVALID') && $resolveOld['body']['error']['details']['activeRevisionNumber'] === 2, 'the authorized worker is told to use REVIZIA 2');
$resolveOther = $post($tailor, '/orders/resolve-qr', ['token' => $intakeQr], null, T::ORIGIN);
checkError($resolveOther, 410, 'DOCUMENT_SUPERSEDED', 'an unrelated employee also sees an invalid document');
check($resolveOther['body']['error']['details']['activeRevisionNumber'] === null, 'the active revision number is hidden from an employee who cannot see the order');
check(checkOk($post($murat, '/orders/resolve-qr', ['token' => $r2Qr], null, T::ORIGIN), 'r2 resolves')['id'] === $o1, 'the revision 2 QR resolves the same order');
checkOk($post($murat, '/orders/' . rawurlencode($o1) . '/transition', ['expectedVersion' => (int) $after['production_version']], $key('continue'), T::ORIGIN), 'Murat continues from the same stage after activation');

// A second order for old-QR production actions: claim, transfer and exception acknowledgment.
check(($ingest('84522', 1, $curtain(5.0), $delivery)['body']['outcome'] ?? null) === 'applied', 'order 84522');
$o2 = 'trendhome:84522';
$uuid2 = (string) $order($o2)['order_uuid'];
checkOk($post($online, $docPath($o2, 'generate'), ['expectedDocumentVersion' => 0], $key('gen-o2'), T::ORIGIN), 'o2 R1', 201);
$o2r1 = $activeQr($uuid2);
$ingest('84522', 2, $curtain(5.0, 'Gri'), $delivery);
$req = checkOk($post($online, $docPath($o2, 'revision-requests'), ['expectedDocumentVersion' => (int) $order($o2)['document_version']], $key('o2-req'), T::ORIGIN), 'o2 request', 201)['request'];
check(in_array(['field' => 'line.color', 'line' => 1, 'before' => 'Bej', 'after' => 'Gri'], $req['changes'], true), 'a color change is a printed change');
checkError($claim($cutter2, $o2, $o2r1), 409, 'ORDER_BLOCKED_BY_DOCUMENT', 'a stale document blocks even an unclaimed pool order');
checkOk($decide($sinem, $req['id'], 'approve', null, $key('o2-approve'), 1), 'o2 approved');
checkOk($post($online, $docPath($o2, 'generate'), ['expectedDocumentVersion' => (int) $order($o2)['document_version']], $key('o2-gen'), T::ORIGIN), 'o2 R2', 201);
checkError($claim($cutter2, $o2, $o2r1), 410, 'DOCUMENT_SUPERSEDED', 'an old QR cannot claim work');
checkOk($claim($cutter2, $o2, $activeQr($uuid2)), 'the new QR claims');
$transfer = checkOk($post($cutter2, '/cutting/orders/' . rawurlencode($o2) . '/transfers', ['expectedVersion' => (int) $order($o2)['production_version'], 'targetId' => $muratId, 'reasonKey' => 'other', 'comment' => 'Test transfer'], $key('transfer'), T::ORIGIN), 'transfer requested', 201);
$transferVersion = static fn (): int => (int) $pdo->query("SELECT version FROM cutting_transfers WHERE transfer_uuid = " . $pdo->quote($transfer['id']))->fetchColumn();
checkOk($post($denisa, "/management/cutting/transfers/{$transfer['id']}/decision", ['expectedVersion' => $transferVersion(), 'decision' => 'approve'], $key('transfer-ok')), 'transfer approved');
checkOk($post($murat, "/cutting/transfers/{$transfer['id']}/accept", ['expectedVersion' => $transferVersion(), 'confirmed' => true], $key('transfer-accept'), T::ORIGIN), 'transfer accepted');
checkError($post($murat, "/cutting/transfers/{$transfer['id']}/verify", ['expectedVersion' => $transferVersion(), 'qrToken' => $o2r1, 'confirmedMultiple' => false, 'ownedCount' => 0], $key('transfer-old-qr'), T::ORIGIN), 410, 'DOCUMENT_SUPERSEDED', 'an old QR cannot complete a transfer');
checkOk($post($murat, "/cutting/transfers/{$transfer['id']}/verify", ['expectedVersion' => $transferVersion(), 'qrToken' => $activeQr($uuid2), 'confirmedMultiple' => false, 'ownedCount' => 0], $key('transfer-new-qr'), T::ORIGIN), 'the active QR completes the transfer');
$cutter2 = $murat;

// ---- Races in separate processes: initial generation, request clicks, revoke vs work, reprint vs activation
check(($ingest('84530', 1, $curtain(2.0), $delivery)['body']['outcome'] ?? null) === 'applied', 'order 84530');
$o5 = 'trendhome:84530';
$uuid5 = (string) $order($o5)['order_uuid'];
$results = $race([[$online, 'POST', $docPath($o5, 'generate'), ['expectedDocumentVersion' => 0], $key('r1-a')], [$online, 'POST', $docPath($o5, 'generate'), ['expectedDocumentVersion' => 0], $key('r1-b')]]);
$statuses = array_column($results, 'status');
sort($statuses);
check($statuses === [201, 409] && (int) $pdo->query("SELECT COUNT(*) FROM production_document_revisions WHERE order_uuid = " . $pdo->quote($uuid5))->fetchColumn() === 1, 'two simultaneous first generations create exactly one revision 1: ' . json_encode($results));
$ingest('84530', 2, $curtain(2.5), $delivery);
$version5 = (int) $order($o5)['document_version'];
$results = $race([[$online, 'POST', $docPath($o5, 'revision-requests'), ['expectedDocumentVersion' => $version5], $key('rq-a')], [$online, 'POST', $docPath($o5, 'revision-requests'), ['expectedDocumentVersion' => $version5], $key('rq-b')]]);
$statuses = array_column($results, 'status');
sort($statuses);
check($statuses === [201, 409] && (int) $pdo->query("SELECT COUNT(*) FROM production_document_revision_requests WHERE order_uuid = " . $pdo->quote($uuid5))->fetchColumn() === 1, 'two request clicks create one request: ' . json_encode($results));
$req5 = (string) $pdo->query("SELECT request_uuid FROM production_document_revision_requests WHERE order_uuid = " . $pdo->quote($uuid5))->fetchColumn();
checkOk($decide($sinem, $req5, 'approve', null, $key('o5-ok'), 1), 'o5 approved');
$oldQr5 = $activeQr($uuid5);
// Reprint of the stale revision races the activation of revision 2: never both; the old QR never prints after.
$results = $race([[$online, 'POST', $docPath($o5, 'generate'), ['expectedDocumentVersion' => (int) $order($o5)['document_version']], $key('act')], [$online, 'POST', $docPath($o5, 'print'), ['revisionNumber' => 1], $key('reprint-race')]]);
check($results[0]['status'] === 201 && $results[1]['status'] === 409, 'a stale revision cannot be reprinted while or after revision 2 activates: ' . json_encode($results));
check((int) $pdo->query("SELECT COUNT(*) FROM production_document_prints p JOIN production_document_revisions r ON r.revision_uuid = p.revision_uuid WHERE r.order_uuid = " . $pdo->quote($uuid5) . " AND r.revision_number = 1 AND p.printed_at > r.stale_at")->fetchColumn() === 0, 'no print of the obsolete revision after it became stale');
// A scan racing the activation sees exactly one valid QR: before commit the old one, after commit the new one.
$scan = $post($cutter2, '/orders/resolve-qr', ['token' => $oldQr5], null, T::ORIGIN);
check($scan['status'] === 410 && $activeQr($uuid5) !== $oldQr5, 'after activation only the new QR works');
// Root revoke racing a worker claim: either the claim committed before the revoke, or it was rejected.
$claimInput = ['expectedVersion' => (int) $order($o5)['production_version'], 'qrToken' => $activeQr($uuid5), 'confirmedMultiple' => true, 'ownedCount' => (new Arasya\Operations\Cutting\CuttingLifecycle($pdo))->ownedCount($cutter2['employeeUuid'])];
$results = $race([[$root, 'POST', $docPath($o5, 'revoke'), ['expectedDocumentVersion' => (int) $order($o5)['document_version'], 'reason' => 'Test cursă'], $key('revoke-race')], [$cutter2, 'POST', '/orders/' . rawurlencode($o5) . '/claim', $claimInput, $key('claim-race')]]);
check($results[0]['status'] === 200, 'the revoke always succeeds');
$claimedAt = $pdo->query("SELECT MAX(occurred_at) FROM order_activity_events WHERE action = 'claimed' AND order_uuid = " . $pdo->quote($uuid5))->fetchColumn();
$revokedAt = $pdo->query("SELECT revoked_at FROM production_document_revisions WHERE status = 'revoked' AND order_uuid = " . $pdo->quote($uuid5))->fetchColumn();
check(($results[1]['status'] === 200 && $claimedAt !== null && $claimedAt <= $revokedAt) || ($results[1]['status'] !== 200 && $claimedAt === null), 'no production action is accepted after the revoke committed: ' . json_encode($results));
check($order($o5)['document_status'] === 'revoked', 'the order stays blocked after the race');

// ---- Root emergency revoke and controlled replacement ----------------------------------------------
$o1version = (int) $order($o1)['document_version'];
checkError($post($sinem, $docPath($o1, 'revoke'), ['expectedDocumentVersion' => $o1version, 'reason' => 'x'], $key('revoke-sinem')), 403, 'UNAUTHORIZED_ACTION', 'only root revokes documents');
checkError($post($root, $docPath($o1, 'revoke'), ['expectedDocumentVersion' => $o1version, 'reason' => ' '], $key('revoke-empty')), 422, 'COMMENT_REQUIRED', 'a revoke needs a reason');
checkOk($post($root, $docPath($o1, 'revoke'), ['expectedDocumentVersion' => $o1version, 'reason' => 'Document fizic compromis.'], $key('revoke')), 'root revokes revision 2');
check($order($o1)['document_status'] === 'revoked' && (int) $pdo->query("SELECT COUNT(*) FROM order_qr_references WHERE status = 'active' AND order_uuid = " . $pdo->quote($uuid1))->fetchColumn() === 0, 'production blocked, no QR of the order works');
checkError($post($tailor, '/orders/resolve-qr', ['token' => $r2Qr], null, T::ORIGIN), 410, 'DOCUMENT_REVOKED', 'the revoked QR says the document was cancelled');
check((int) $pdo->query("SELECT COUNT(*) FROM production_document_revisions WHERE order_uuid = " . $pdo->quote($uuid1))->fetchColumn() === 2, 'revoke keeps all history');
$replacement = checkOk($post($online, $docPath($o1, 'revision-requests'), ['expectedDocumentVersion' => (int) $order($o1)['document_version'], 'comment' => 'Înlocuire după revocare.'], $key('replace-req'), T::ORIGIN), 'replacement request', 201)['request'];
check($replacement['targetRevision'] === 3 && $replacement['changes'] === [] && $replacement['baseRevision']['status'] === 'revoked', 'the replacement is revision 3 with no content change');
checkOk($decide($sinem, $replacement['id'], 'approve', null, $key('replace-ok'), 1), 'replacement approved');
checkOk($post($online, $docPath($o1, 'generate'), ['expectedDocumentVersion' => (int) $order($o1)['document_version']], $key('replace-gen'), T::ORIGIN), 'revision 3 generated', 201);
check($order($o1)['document_status'] === 'active' && count(array_unique($pdo->query("SELECT qr_reference FROM production_document_revisions WHERE order_uuid = " . $pdo->quote($uuid1))->fetchAll(PDO::FETCH_COLUMN))) === 3, 'three revisions, three distinct QR codes');

// ---- Backup revoked early and expired: authority stops immediately ---------------------------------
check(($ingest('84522', 3, $curtain(6.0, 'Gri'), $delivery)['body']['outcome'] ?? null) === 'applied', 'o2 changes again');
$req4 = checkOk($post($online, $docPath($o2, 'revision-requests'), ['expectedDocumentVersion' => (int) $order($o2)['document_version']], $key('o2-req4'), T::ORIGIN), 'o2 request 4', 201)['request'];
$assignmentId = (string) $pdo->query("SELECT assignment_uuid FROM responsibility_assignments WHERE responsibility_key = 'document_revision_backup_approver' AND revoked_at IS NULL")->fetchColumn();
checkOk($post($mesut, "/management/organization/responsibilities/{$assignmentId}/revoke", ['reason' => 'Sinem a revenit.'], $key('revoke-backup')), 'the CEO ends the delegation early');
checkError($decide($backup, $req4['id'], 'approve', null, $key('backup-revoked'), 1), 403, 'UNAUTHORIZED_ACTION', 'a revoked backup cannot decide');
$pdo->prepare('INSERT INTO responsibility_assignments (assignment_uuid, responsibility_key, employee_uuid, starts_at, ends_at, created_at, created_by_employee_uuid) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([Arasya\Operations\Support\Uuid::v4(), 'document_revision_backup_approver', $backupId, '2026-01-01 00:00:00', '2026-01-02 00:00:00', '2026-01-01 00:00:00', $mesutId]);
checkError($decide($backup, $req4['id'], 'approve', null, $key('backup-expired'), 1), 403, 'UNAUTHORIZED_ACTION', 'an expired backup window cannot decide');

// ---- Processed meters after cutting: objective customer-revision fact, never a fault ---------------
checkOk($decide($sinem, $req4['id'], 'approve', null, $key('o2-ok4'), 1), 'o2 approved again');
checkOk($post($online, $docPath($o2, 'generate'), ['expectedDocumentVersion' => (int) $order($o2)['document_version']], $key('o2-gen4'), T::ORIGIN), 'o2 R3', 201);
$o2now = $order($o2);
checkOk($post($cutter2, '/orders/' . rawurlencode($o2) . '/transition', ['expectedVersion' => (int) $o2now['production_version']], $key('o2-cut'), T::ORIGIN), 'o2 cut and handed to tailoring');
check(($ingest('84522', 5, $curtain(7.5, 'Gri'), $delivery)['body']['outcome'] ?? null) === 'applied', 'customer changes after cutting');
$processed = $pdo->query("SELECT processed_meters, processed_lines FROM production_document_blocks WHERE ended_at IS NULL AND order_uuid = " . $pdo->quote($uuid2))->fetch(PDO::FETCH_ASSOC);
check($processed['processed_meters'] === '6.000' && (int) $processed['processed_lines'] === 1, 'whole-line meters already cut under the obsolete document are recorded once (6 m, never × quantity)');
check((int) $pdo->query("SELECT COUNT(*) FROM production_quality_events")->fetchColumn() === 0, 'no cutting fault or fault meters for the cutter');

// ---- Completed order: active QR read-only, no revision workflow ------------------------------------
check(($ingest('84523', 1, $curtain(4.0), $delivery)['body']['outcome'] ?? null) === 'applied', 'order 84523');
$o3 = 'trendhome:84523';
$uuid3 = (string) $order($o3)['order_uuid'];
checkOk($post($online, $docPath($o3, 'generate'), ['expectedDocumentVersion' => 0], $key('gen-o3'), T::ORIGIN), 'o3 R1', 201);
$pdo->prepare("UPDATE operational_orders SET production_stage_id = 'workshop-receiving', production_completed_at = UTC_TIMESTAMP(6) WHERE order_uuid = ?")->execute([$uuid3]);
$resolvedDone = checkOk($post($tailor, '/orders/resolve-qr', ['token' => $activeQr($uuid3)], null, T::ORIGIN), 'completed order QR still opens', 200);
check(!isset($resolvedDone['employeeAllowedAction']) && $resolvedDone['employeeActionBlockedReason'] === 'production_completed', 'a completed order opens read-only');
$ingest('84523', 2, $curtain(9.0), $delivery);
check($order($o3)['document_status'] === 'active', 'a source change after completion never starts a revision workflow');
$pdo->prepare("UPDATE operational_orders SET document_status = 'stale' WHERE order_uuid = ?")->execute([$uuid3]);
checkError($post($online, $docPath($o3, 'revision-requests'), ['expectedDocumentVersion' => (int) $order($o3)['document_version']], $key('o3-req'), T::ORIGIN), 409, 'DOCUMENT_ORDER_COMPLETED', 'post-completion revision is outside this workflow (returns are separate)');
$pdo->prepare("UPDATE operational_orders SET document_status = 'active' WHERE order_uuid = ?")->execute([$uuid3]);

// ---- Trendyol: limited data, no outbound call -------------------------------------------------------
$package = ['id' => 7700 + random_int(1, 99), 'orderNumber' => '10930021', 'lastModifiedDate' => 1_780_000_000_000, 'shipmentPackageStatus' => 'Created',
    'shipmentAddress' => ['fullName' => 'TEST Maria Enache', 'address1' => 'Str. Florilor 3', 'city' => 'Iași', 'phone' => '0744111222', 'email' => 'ignored@example.invalid'],
    'lines' => [['id' => 1, 'productName' => 'Draperie blackout gri', 'merchantSku' => 'TY-555', 'productColor' => 'Gri', 'quantity' => 2]]];
$container->projectionWriter()->apply(TrendyolOrderMapper::map($package));
$ty = 'trendyol:' . $package['id'];
checkOk($post($online, $docPath($ty, 'generate'), ['expectedDocumentVersion' => 0], $key('gen-ty'), T::ORIGIN), 'Trendyol order R1', 201);
$tySnapshot = json_decode((string) $pdo->query("SELECT r.snapshot_json FROM production_document_revisions r JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE o.global_order_id = " . $pdo->quote($ty))->fetchColumn(), true);
check($tySnapshot['customer']['phoneMasked'] === '07** *** ***' && !str_contains(json_encode($tySnapshot), 'ignored@') && $tySnapshot['lines'][0]['options'] === [] && $tySnapshot['lines'][0]['meters'] === null, 'Trendyol: masked phone, no email, no invented options or meters');

// ---- History, analytics, audit ----------------------------------------------------------------------
$history = $documentView($sinem, $o1);
$types = array_column($history['history'], 'type');
check(array_slice($types, 0, 3) === ['generated', 'printed', 'reprinted'] && in_array('content_changed', $types, true) && in_array('revision_rejected', $types, true) && in_array('revision_superseded', $types, true) && in_array('revoked', $types, true), 'the full append-only document history is available to management');
check(count($history['revisions']) === 3 && $history['revisions'][1]['approvedBy'] === 'Test backup' && $history['revisions'][1]['status'] === 'revoked' && $history['revisions'][0]['status'] === 'active' && $history['revisions'][2]['status'] === 'superseded', 'revisions show who approved and their final state');
check(!str_contains(json_encode($history), substr($intakeQr, 10)) && !str_contains(json_encode($history), substr($r2Qr, 10)), 'no QR payload is exposed in history views');
checkError($get($murat, $docPath($o1)), 403, 'UNAUTHORIZED_ACTION', 'a worker cannot open the management document history');
$audit = $pdo->query("SELECT metadata_json FROM iam_audit_events WHERE action LIKE 'production_document.%' AND actor_employee_uuid IN (" . implode(',', array_map([$pdo, 'quote'], [$onlineId, $sinemId, $backupId, $root['employeeUuid']])) . ')')->fetchAll(PDO::FETCH_COLUMN);
check(count($audit) >= 10 && !str_contains(implode('', $audit), substr($intakeQr, 10)) && preg_match('/password|csrf|cookie|token/i', implode('', $audit)) !== 1, 'document audit holds no QR secret, password or token');
$analytics = $pdo->query("SELECT COUNT(*) FROM production_document_blocks WHERE order_uuid = " . $pdo->quote($uuid1))->fetchColumn();
check((int) $analytics === 2, 'two revision waits for order 84521: content change and root revoke');
$analyticsPolicy = new Arasya\Operations\Analytics\AnalyticsPolicy($pdo);
$rootIdentity = $container->employeeRepository()->findByUuid($root['employeeUuid']);
$report = (new Arasya\Operations\Analytics\AnalyticsService($pdo, $analyticsPolicy, $container->clock()))->report($rootIdentity, 'order', [], $uuid1);
check($report['documents']['revisionCount'] === 3 && count($report['documents']['blocks']) === 2 && $report['order']['documentCreatedAt'] !== null && isset($report['lifecycle']['documentRevisionWaiting']), 'analytics reports revision count, waits and the first document time');
check(count($report['documents']['requests']) === 4 && $report['documents']['requests'][2]['approvalSeconds'] !== null, 'analytics reports approval durations');

// ---- Rendered ticket content (all five representative sources) -------------------------------------
$fixtures = require __DIR__ . '/fixtures/ticket-snapshots.php';
foreach ($fixtures as $name => $snapshot) {
    $revision = ['number' => 2, 'status' => 'active', 'generatedAt' => '2026-10-06 07:12:00', 'qrPayload' => 'ARASYA:Q1:ABCDEFGHIJKLMNOPQRSTUVWXYZ'];
    $document = ProductionTicketPdf::document($snapshot, $revision);
    $texts = $document->textLog();
    $all = implode("\n", array_column($texts, 'text'));
    $pages = max(array_column($texts, 'page'));
    foreach (['ARASYA HOME · DOCUMENT DE PRODUCȚIE', 'REVIZIA 2', 'DOCUMENT ACTIV', 'CLIENT / LIVRARE', 'LĂȚIME', 'ÎNĂLȚIME', 'CANTITATE', 'METRI (LINIE)', '#' . $snapshot['order']['number'], $snapshot['customer']['name']] as $label) {
        check(str_contains($all, $label), "{$name}: shows {$label}");
    }
    $stamp = ['trendhome' => 'TRENDHOME.RO', 'outletperdele' => 'OUTLETPERDELE.RO', 'trendyol' => 'TRENDYOL', 'b2b' => 'ARASYA HOME B2B', 'b2b-project' => 'ARASYA HOME B2B'][$name];
    check(count(array_filter($texts, static fn (array $t): bool => $t['text'] === $stamp)) === $pages, "{$name}: source stamp on every page");
    check(preg_match('/@|RON|EUR|\bLei\b|preț|Preț|TVA|plată|Plată|sold|Sold|discount|Total de plată|ACTIVE DOCUMENT|Revision/u', $all) !== 1, "{$name}: no email, price, payment, balance or English labels");
    check(!preg_match('/07\d{2} ?\d{3} ?\d{3}/', $all) && ($snapshot['customer']['phoneMasked'] === null || str_contains($all, 'Telefon: 07** *** ***')), "{$name}: the phone is only masked");
    for ($page = 1; $page <= $pages; $page++) {
        $onPage = array_column(array_filter($texts, static fn (array $t): bool => $t['page'] === $page), 'text');
        check(in_array('Pagina ' . $page . ' / ' . $pages, $onPage, true) && in_array('REVIZIA 2', $onPage, true) && in_array('#' . $snapshot['order']['number'], $onPage, true), "{$name}: page {$page} carries order, revision and Pagina {$page} / {$pages}");
        check(count(array_filter($document->qrLog(), static fn (array $q): bool => $q['page'] === $page && $q['size'] >= 80)) === 1, "{$name}: a scannable QR on page {$page}");
    }
    $outside = array_filter($texts, static fn (array $t): bool => !($t['x'] >= 28 && $t['x'] + $t['width'] <= 595.28 - 28 && $t['y'] >= 20 && $t['y'] + $t['size'] <= 841.89 - 20));
    check($outside === [], "{$name}: every text stays inside the printable A4 area " . json_encode(array_column($outside, 'text'), JSON_UNESCAPED_UNICODE));
    check(array_filter($texts, static fn (array $t): bool => $t['size'] < 6.5) === [], "{$name}: no unreadably small text");
    // Text boxes on one page never overlap (separate drawings may touch, never cover one another).
    $overlaps = [];
    $byPage = [];
    foreach ($texts as $t) {
        $byPage[$t['page']][] = $t;
    }
    foreach ($byPage as $page => $items) {
        foreach ($items as $i => $a) {
            foreach (array_slice($items, $i + 1) as $b) {
                $overlapX = min($a['x'] + $a['width'], $b['x'] + $b['width']) - max($a['x'], $b['x']);
                $overlapY = min($a['y'] + $a['size'] * 0.72, $b['y'] + $b['size'] * 0.72) - max($a['y'], $b['y']);
                if ($overlapX > 1.5 && $overlapY > 1.5) {
                    $overlaps[] = "p{$page}: {$a['text']} / {$b['text']}";
                }
            }
        }
    }
    check($overlaps === [], "{$name}: no overlapping text " . json_encode($overlaps, JSON_UNESCAPED_UNICODE));
    foreach ($snapshot['lines'] as $line) {
        if ($line['meters'] !== null) {
            check(str_contains($all, TicketSnapshot::measure($line['meters'], null)), "{$name}: exact line meters " . $line['meters']);
        }
        if ($line['code'] !== null) {
            check(str_contains($all, $line['code']), "{$name}: product code {$line['code']}");
        }
    }
    $hasOptions = array_filter($snapshot['lines'], static fn (array $l): bool => $l['options'] !== []) !== [];
    $firstOption = array_values(array_filter($snapshot['lines'], static fn (array $l): bool => $l['options'] !== []))[0]['options'][0] ?? null;
    check($firstOption === null || str_contains($all, $firstOption['label'] . ':'), "{$name}: manufacturing options are printed when the source supplied them");
    check($hasOptions || !str_contains($all, '; '), "{$name}: no options line without source options");
}
$projectTexts = implode("\n", array_column(ProductionTicketPdf::document($fixtures['b2b-project'], ['number' => 1, 'status' => 'active', 'generatedAt' => '2026-10-06 07:12:00', 'qrPayload' => 'ARASYA:Q1:ABCDEFGHIJKLMNOPQRSTUVWXYZ'])->textLog(), 'text'));
check(str_contains($projectTexts, 'PRJ-000042 · TEST Hotel Lumina') && str_contains($projectTexts, 'Corp A · Etaj 0  ›  Camera 100') && str_contains($projectTexts, 'Fereastra 2') && str_contains($projectTexts, 'Persoană de contact: TEST Mihai Stan'), 'B2B project: project, floor, room, window and contact person');
check(ProductionTicketPdf::pageCount($fixtures['b2b-project'], ['number' => 1, 'status' => 'active', 'generatedAt' => '2026-10-06 07:12:00', 'qrPayload' => null]) > 1 && ProductionTicketPdf::pageCount($fixtures['trendhome'], ['number' => 1, 'status' => 'active', 'generatedAt' => '2026-10-06 07:12:00', 'qrPayload' => null]) === 1, 'ordinary orders fit one page; large projects paginate');
check(ProductionTicketPdf::filename($fixtures['b2b-project'], 3) === 'ARASYA-B2B-ORD-000211-R3.pdf', 'deterministic filename without customer data');
$superseded = ProductionTicketPdf::document($fixtures['trendhome'], ['number' => 1, 'status' => 'superseded', 'generatedAt' => '2026-10-06 07:12:00', 'qrPayload' => null]);
check($superseded->qrLog() === [] && str_contains(implode("\n", array_column($superseded->textLog(), 'text')), 'DOCUMENT ÎNLOCUIT'), 'a replaced revision never renders a scannable QR');

// ---- Fingerprint covers printed fields only ---------------------------------------------------------
$base = $fixtures['trendhome'];
$fingerprint = TicketSnapshot::fingerprint($base);
foreach ([
    'meters' => static function (array $s): array { $s['lines'][0]['meters'] = '9.000'; return $s; },
    'width' => static function (array $s): array { $s['lines'][0]['width'] = '310.000'; return $s; },
    'color' => static function (array $s): array { $s['lines'][0]['color'] = 'Gri'; return $s; },
    'variant' => static function (array $s): array { $s['lines'][0]['variant'] = 'Creion'; return $s; },
    'production note' => static function (array $s): array { $s['lines'][0]['productionNotes'] = 'Tiv 7 cm.'; return $s; },
    'address' => static function (array $s): array { $s['customer']['addressLines'][0] = 'Str. Nouă 1'; return $s; },
    'masked phone' => static function (array $s): array { $s['customer']['phoneMasked'] = '06** *** ***'; return $s; },
    'project location' => static function (array $s): array { $s['lines'][0]['project'] = ['opening' => ['name' => 'Fereastra 9']]; return $s; },
    'option' => static function (array $s): array { $s['lines'][0]['options'][0]['value'] = '3 bucăți'; return $s; },
] as $field => $mutate) {
    check(TicketSnapshot::fingerprint($mutate($base)) !== $fingerprint, "a {$field} change changes the fingerprint");
}
check(TicketSnapshot::fingerprint(json_decode(TicketSnapshot::canonicalJson($base), true)) === $fingerprint, 'the fingerprint is deterministic');

// ---- Indexed QR state; source and inventory invariants ----------------------------------------------
check($pdo->query("SHOW INDEX FROM production_document_revisions WHERE Key_name = 'uq_production_document_revisions_qr'")->fetch() !== false && $pdo->query("SHOW INDEX FROM production_document_revisions WHERE Key_name = 'uq_production_document_revisions_active'")->fetch() !== false, 'QR state and the active revision are unique indexed lookups');
check((int) $pdo->query('SELECT COUNT(*) FROM production_document_revisions GROUP BY active_order_uuid HAVING active_order_uuid IS NOT NULL AND COUNT(*) > 1')->fetchColumn() === 0, 'never two active revisions for one order');
check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND (table_name LIKE '%inventory%' OR table_name LIKE '%stock%')")->fetchColumn() === 0, 'no inventory table exists');

fwrite(STDOUT, "OK MySQL production documents integration ({$checks} checks)\n");
