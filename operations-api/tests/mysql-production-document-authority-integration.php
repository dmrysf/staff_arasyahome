<?php

declare(strict_types=1);

// Production document (PDF) authority (API 2.20.0, migration 020) against a dedicated MySQL/MariaDB test
// database: the additive migration and its attribution checks, per-source document modes, the signed
// read-only document state, the signed source print (revision 1 only, never a later revision; idempotent
// under retries and real process concurrency), Staff refusal of competing documents during the cutover,
// commerce updates that never replace a document, stale and revoked documents refused to the source,
// approval-bound revisions, the non-recording preview, the root console revoke with its QR consequence,
// source isolation, authorization and the absence of customer data in signed answers.

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
    fwrite(STDOUT, "SKIP MySQL production document authority integration: ARASYA_TEST_DB_NAME is not configured.\n");
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
    unset($body['document']['pdf']);
    check($response['status'] === $status && ($response['body']['error']['code'] ?? null) === $code, "{$message} (got {$response['status']} " . json_encode($body) . ')');
}

/** @param array{status: int, body: array<string, mixed>|null} $response @return array<string, mixed> */
function checkOk(array $response, string $message, int $status = 200): array
{
    $body = $response['body'];
    unset($body['document']['pdf']);
    check($response['status'] === $status, "{$message} (got {$response['status']} " . json_encode($body) . ')');
    return $response['body'] ?? [];
}

const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';
$settings = static fn (string $document, string $outletDocument = 'legacy'): array => [
    'trendhome' => ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => $document],
    'outletperdele' => ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => $outletDocument],
];
$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $settings('enforce'));
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

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$observeKernel = (new Container(T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $settings('observe')), $pdo))->kernel();
$legacyKernel = (new Container(T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $settings('legacy')), $pdo))->kernel();
$qrObserveKernel = (new Container(T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], ['trendhome' => ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'observe', 'documentAuthority' => 'enforce']]), $pdo))->kernel();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string {
    $keys++;
    return sprintf('pda-%s-%s-%04d', $label, $suffix, $keys);
};
$login = static function (string $username, string $password) use ($kernel): array {
    $response = T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => DASHBOARD_ORIGIN]);
    if ($response['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $response['headers']['Set-Cookie'] ?? '', $match)) {
        throw new RuntimeException("Login failed for {$username}: " . json_encode($response['body']));
    }
    return ['cookie' => rawurldecode($match[1]), 'csrf' => (string) $response['body']['csrfToken'], 'employeeUuid' => (string) $response['body']['employee']['employeeUuid']];
};
$call = static function (array $who, string $method, string $path, ?array $json = null, ?string $idem = null, ?object $k = null, string $origin = DASHBOARD_ORIGIN) use ($kernel): array {
    $headers = ['origin' => $origin, 'x-csrf-token' => $who['csrf']];
    if ($idem !== null) {
        $headers['idempotency-key'] = $idem;
    }
    return T::call($k ?? $kernel, $method, $path, $json, $headers, $who['cookie']);
};
$docPath = static fn (string $globalId, string $action = ''): string => '/production-documents/orders/' . rawurlencode($globalId) . ($action === '' ? '' : "/{$action}");
$states = static fn (object $k, string $source, array $ids, ?string $secret = null): array => T::ingest($k, $source, ['orderIds' => $ids], $secret, null, 'orders/documents');
$sourcePrint = static fn (object $k, string $source, array $input, ?string $secret = null): array => T::ingest($k, $source, $input + ['issue' => true, 'revisionNumber' => null, 'actor' => ['id' => 7, 'name' => 'Operator Magazin']], $secret, null, 'orders/document');
$count = static function (string $sql, array $args = []) use ($pdo): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($args);
    return (int) $statement->fetchColumn();
};
$order = static function (string $globalId) use ($pdo): array {
    $statement = $pdo->prepare('SELECT order_uuid, production_authority, production_stage_id, production_version, document_status, document_version, operational_status FROM operational_orders WHERE global_order_id = ?');
    $statement->execute([$globalId]);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
};
$activeQr = static function (string $globalId) use ($pdo): ?string {
    $statement = $pdo->prepare("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? AND q.status = 'active'");
    $statement->execute([$globalId]);
    $reference = $statement->fetchColumn();
    return is_string($reference) ? 'ARASYA:Q1:' . $reference : null;
};
$revisions = static function (string $globalId) use ($pdo): array {
    $statement = $pdo->prepare('SELECT r.revision_number, r.status, r.qr_reference, r.generated_by_employee_uuid, r.generated_by_source_key, r.generated_by_source_actor FROM production_document_revisions r JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE o.global_order_id = ? ORDER BY r.revision_number');
    $statement->execute([$globalId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$prints = static fn (string $globalId): int => $count('SELECT COUNT(*) FROM production_document_prints p JOIN operational_orders o ON o.order_uuid = p.order_uuid WHERE o.global_order_id = ?', [$globalId]);
$ingest = static function (object $k, string $source, string $id, string $event, int $ago, string $status = 'processing', string $availability = 'active', ?array $items = null): array {
    $payload = T::sourceOrder($id, $event, gmdate('Y-m-d\TH:i:s\Z', time() - $ago), null, $status, $availability, $items ?? [[
        'id' => 801, 'line' => 1, 'name' => 'Perdea Voal Alb', 'sku' => 'PV-ALB', 'color' => 'Alb', 'width' => 3, 'height' => 2.75, 'unit' => 'm', 'quantity' => 1,
        'options' => [['label' => 'buc', 'value' => '2 buc.'], ['label' => 'Manopera', 'value' => 'Manopera Rejansa Normal 6cm']],
    ]]);
    $payload['order']['delivery'] = ['name' => 'TEST Client PDF', 'street' => 'Str. Ascunsă 9', 'city' => 'Cluj', 'postalCode' => '400000', 'country' => 'RO', 'phone' => '0733000999'];
    return T::ingest($k, $source, $payload);
};
$noPii = static fn (mixed $value): bool => !str_contains(json_encode($value, JSON_UNESCAPED_UNICODE), 'TEST Client') && !str_contains(json_encode($value, JSON_UNESCAPED_UNICODE), 'Ascunsă')
    && !str_contains(json_encode($value), '0733000999') && !str_contains(json_encode($value), '733000999') && !str_contains(json_encode($value), 'Cluj');

// ---- Identities -----------------------------------------------------------------------------------------
$rootUsername = 'arasya.root.pdf.' . $suffix;
$rootTemporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-pdf-root', $rootUsername);
$first = $login($rootUsername, $rootTemporary);
check(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $rootTemporary, 'newPassword' => 'PDF authority root passphrase 2026!'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $first['csrf']], $first['cookie'])['status'] === 200, 'root changes its temporary password');
$root = $login($rootUsername, 'PDF authority root passphrase 2026!');
$roleIds = [];
foreach (checkOk($call($root, 'GET', '/management/roles'), 'roles')['items'] as $role) {
    $roleIds[$role['key']] = (int) $role['id'];
}
$password = 'pdf authority passphrase 2026';
$admin = $container->employeeAdmin();
$identity = static function (string $name, array $stages, array $applications, array $roles) use ($admin, $call, $root, $password, $suffix): string {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test');
    checkOk($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications]), "{$name} applications");
    checkOk($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
    return $employee->employeeUuid;
};
$operatorId = $identity('pdfoperator', [], ['staff', 'dashboard'], [$roleIds['production-documents-operator']]);
$approverId = $identity('pdfapprover', [], ['dashboard'], [$roleIds['document-revision-approver']]);
$identity('pdfworker', ['waiting', 'material-preparation'], ['staff'], []);
$operator = $login("pdfoperator.{$suffix}", $password);
$approver = $login("pdfapprover.{$suffix}", $password);
$worker = $login("pdfworker.{$suffix}", $password);

// ---- Migration 020: additive; employee-generated history is untouched --------------------------------------
check(($ingest($legacyKernel, 'trendhome', '7001', "th-7001-a-{$suffix}", 900)['body']['outcome'] ?? null) === 'applied', 'seed order before the 020 upgrade');
checkOk($call($operator, 'POST', $docPath('trendhome:7001', 'generate'), ['expectedDocumentVersion' => 0], $key('seed-gen'), $legacyKernel, T::ORIGIN), 'legacy: an employee generates revision 1 as in 2.16', 201);
restorePreDocumentAuthorityTestSchema($pdo);
$historyBefore = $pdo->query('SELECT revision_uuid, order_uuid, revision_number, status, qr_reference, generated_by_employee_uuid, generated_at FROM production_document_revisions ORDER BY revision_uuid')->fetchAll(PDO::FETCH_ASSOC);
$qrBefore = $pdo->query('SELECT qr_reference, status FROM order_qr_references ORDER BY qr_reference')->fetchAll(PDO::FETCH_ASSOC);
$ordersBefore = $pdo->query('SELECT global_order_id, production_authority, production_stage_id, production_version, document_status, document_version FROM operational_orders ORDER BY global_order_id')->fetchAll(PDO::FETCH_ASSOC);
check((new MigrationRunner($pdo))->migrate($migrations) === ['020_production_document_authority.sql'], '019 -> 020 official additive upgrade');
check((new MigrationRunner($pdo))->migrate($migrations) === [], '020 recorded exactly once');
check($pdo->query('SELECT revision_uuid, order_uuid, revision_number, status, qr_reference, generated_by_employee_uuid, generated_at FROM production_document_revisions ORDER BY revision_uuid')->fetchAll(PDO::FETCH_ASSOC) === $historyBefore, '020 rewrites no document revision');
check($pdo->query('SELECT qr_reference, status FROM order_qr_references ORDER BY qr_reference')->fetchAll(PDO::FETCH_ASSOC) === $qrBefore, '020 changes no QR');
check($pdo->query('SELECT global_order_id, production_authority, production_stage_id, production_version, document_status, document_version FROM operational_orders ORDER BY global_order_id')->fetchAll(PDO::FETCH_ASSOC) === $ordersBefore, '020 changes no order');
$attribution = false;
try {
    $pdo->exec("UPDATE production_document_revisions SET generated_by_source_key = 'trendhome', generated_by_source_actor = '#1 X'");
} catch (PDOException) {
    $attribution = true;
}
check($attribution, 'a revision cannot carry both an employee and a source attribution');

// ---- Legacy: the source keeps its ticket; the signed contract only reads -------------------------------------
check(($ingest($legacyKernel, 'trendhome', '7101', "th-7101-a-{$suffix}", 800)['body']['outcome'] ?? null) === 'applied', 'legacy: new operations order');
$legacyState = checkOk($states($legacyKernel, 'trendhome', ['7101', '424242']), 'legacy document state');
check($legacyState['documentAuthorityMode'] === 'legacy' && $legacyState['orders'][0]['documentAuthority'] === 'source' && $legacyState['orders'][0]['issueAllowed'] === false && $legacyState['orders'][0]['documentStatus'] === 'none', 'legacy: the source ticket stays the authority');
check($legacyState['orders'][1] === ['orderId' => '424242', 'globalOrderId' => 'trendhome:424242', 'exists' => false, 'productionAuthority' => null, 'documentAuthority' => null, 'documentStatus' => null, 'issueAllowed' => false, 'activeRevision' => null, 'request' => null], 'an unknown order is reported, never an error');
checkError($sourcePrint($legacyKernel, 'trendhome', ['orderId' => '7101']), 409, 'DOCUMENT_AUTHORITY_SOURCE', 'legacy: the source cannot obtain an Arasya document');
check($revisions('trendhome:7101') === [] && $prints('trendhome:7101') === 0, 'legacy: nothing was created');

// ---- Observe: Staff documents for operations orders only; the source cannot issue ----------------------------
check(($ingest($observeKernel, 'trendhome', '7201', "th-7201-a-{$suffix}", 700)['body']['outcome'] ?? null) === 'applied', 'observe: new operations order');
$pdo->prepare("UPDATE operational_orders SET production_authority = 'source' WHERE global_order_id = 'trendhome:7101'")->execute();
checkError($call($operator, 'POST', $docPath('trendhome:7101', 'generate'), ['expectedDocumentVersion' => 0], $key('gen-source'), $observeKernel, T::ORIGIN), 409, 'DOCUMENT_AUTHORITY_SOURCE', 'observe: no competing Arasya document for an order the source still manages');
$intake = $activeQr('trendhome:7201');
$observed = checkOk($call($operator, 'POST', $docPath('trendhome:7201', 'generate'), ['expectedDocumentVersion' => 0], $key('gen-observe'), $observeKernel, T::ORIGIN), 'observe: Staff generates revision 1 of an operations order', 201);
check($observed['activeRevision']['number'] === 1 && $activeQr('trendhome:7201') === $intake && $revisions('trendhome:7201')[0]['qr_reference'] === substr((string) $intake, 10), 'revision 1 adopts the intake QR');
checkError($sourcePrint($observeKernel, 'trendhome', ['orderId' => '7201']), 409, 'DOCUMENT_CUTOVER_INACTIVE', 'observe: the source still prints its own ticket');
$observeState = checkOk($states($observeKernel, 'trendhome', ['7201']), 'observe state')['orders'][0];
check($observeState['documentAuthority'] === 'arasya' && $observeState['documentStatus'] === 'active' && $observeState['activeRevision']['number'] === 1 && $observeState['activeRevision']['qrRevision'] === 1 && strlen($observeState['activeRevision']['qrHint']) === 10 && $observeState['issueAllowed'] === false, 'observe: the source reads the Arasya revision to log it');
check($noPii($observeState) && !str_contains(json_encode($observeState), substr((string) $intake, 10)), 'the signed state carries no customer data and no QR value');

// ---- Enforce: the source obtains revision 1 through the signed contract ------------------------------------
check(($ingest($kernel, 'trendhome', '7301', "th-7301-a-{$suffix}", 600)['body']['outcome'] ?? null) === 'applied', 'enforce: new operations order');
$state = checkOk($states($kernel, 'trendhome', ['7301']), 'enforce state')['orders'][0];
check($state['documentAuthority'] === 'arasya' && $state['documentStatus'] === 'none' && $state['issueAllowed'] === true && $state['activeRevision'] === null, 'enforce: the source may request revision 1');
checkError($sourcePrint($kernel, 'trendhome', ['orderId' => '7301', 'issue' => false]), 409, 'DOCUMENT_NOT_GENERATED', 'without issue no document is created');
checkError(T::ingest($kernel, 'trendhome', ['orderId' => '7301', 'issue' => true, 'revisionNumber' => null, 'actor' => ['id' => 7, 'name' => 'Op'], 'pdf' => 'x'], null, null, 'orders/document'), 422, 'SOURCE_PAYLOAD_INVALID', 'the source cannot send document content');
checkError($sourcePrint($kernel, 'trendhome', ['orderId' => '7301'], T::OUTLET_SECRET), 401, 'SOURCE_SIGNATURE_INVALID', 'the source print requires the source signature');
check($revisions('trendhome:7301') === [], 'refused calls created nothing');
$printed = checkOk($sourcePrint($kernel, 'trendhome', ['orderId' => '7301']), 'enforce: the source prints the Arasya ticket');
$document = $printed['document'];
$pdf = base64_decode((string) $document['pdf'], true);
check(is_string($pdf) && str_starts_with($pdf, '%PDF-') && hash('sha256', $pdf) === $document['sha256'] && $document['filename'] === 'ARASYA-7301-R1.pdf', 'the answer carries the rendered PDF with its checksum and a filename without customer data');
check($document['orderId'] === '7301' && $document['globalOrderId'] === 'trendhome:7301', 'the print answer names exactly the requested order');
check($document['issued'] === true && $document['printNumber'] === 1 && $document['documentStatus'] === 'active' && $document['activeRevision']['number'] === 1 && $document['activeRevision']['prints'] === 1, 'revision 1 issued and printed once');
$r1 = $revisions('trendhome:7301');
check(count($r1) === 1 && $r1[0]['generated_by_employee_uuid'] === null && $r1[0]['generated_by_source_key'] === 'trendhome' && $r1[0]['generated_by_source_actor'] === '#7 Operator Magazin' && 'ARASYA:Q1:' . $r1[0]['qr_reference'] === $activeQr('trendhome:7301'), 'revision 1 is attributed to the source operator and prints the active QR');
$printRow = $pdo->query("SELECT print_kind, printed_by_employee_uuid, printed_by_source_key, printed_by_source_actor FROM production_document_prints p JOIN operational_orders o ON o.order_uuid = p.order_uuid WHERE o.global_order_id = 'trendhome:7301'")->fetch(PDO::FETCH_ASSOC);
check($printRow === ['print_kind' => 'print', 'printed_by_employee_uuid' => null, 'printed_by_source_key' => 'trendhome', 'printed_by_source_actor' => '#7 Operator Magazin'], 'the print is attributed to the source operator');
$again = checkOk($sourcePrint($kernel, 'trendhome', ['orderId' => '7301']), 'a retry or reprint')['document'];
check($again['issued'] === false && $again['printNumber'] === 2 && count($revisions('trendhome:7301')) === 1 && base64_decode((string) $again['pdf']) === $pdf, 'a retry never creates a second revision: same revision, same bytes, recorded as a reprint');
check($noPii(array_diff_key($again, ['pdf' => true])), 'the signed print answer carries customer data only inside the PDF');
$history = checkOk($call($operator, 'GET', $docPath('trendhome:7301')), 'Staff document view of the source-issued revision');
check($history['activeRevision']['generatedBy'] === 'Trendhome · #7 Operator Magazin' || str_ends_with((string) $history['activeRevision']['generatedBy'], '· #7 Operator Magazin'), 'Staff names the source operator: ' . json_encode($history['activeRevision']['generatedBy']));
check(in_array('trendhome · #7 Operator Magazin', array_column($history['history'], 'actorName'), true), 'the history names the source operator');
checkError($call($operator, 'POST', $docPath('trendhome:7301', 'generate'), ['expectedDocumentVersion' => (int) $order('trendhome:7301')['document_version']], $key('gen-dup'), null, T::ORIGIN), 409, 'DOCUMENT_ALREADY_ACTIVE', 'Staff cannot create a second current document');
checkError($sourcePrint($kernel, 'trendhome', ['orderId' => '7301', 'revisionNumber' => 2]), 409, 'DOCUMENT_REVISION_NOT_ACTIVE', 'a print of a revision that is not active is refused');
check(checkOk($sourcePrint($kernel, 'trendhome', ['orderId' => '7301', 'revisionNumber' => 1]), 'expected revision')['document']['activeRevision']['number'] === 1, 'the expected active revision prints');

// Real process concurrency: two source workers ask for revision 1 of the same new order at the same instant.
check(($ingest($kernel, 'trendhome', '7302', "th-7302-a-{$suffix}", 600)['body']['outcome'] ?? null) === 'applied', 'concurrency order');
$start = (string) (microtime(true) + 1.5);
$workers = [];
foreach (['a', 'b'] as $label) {
    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/document-worker.php', $dbName, '7302', $label, $start], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
check(array_column($results, 'status') === [200, 200] && array_sum(array_map(static fn (array $r): int => $r['issued'] ? 1 : 0, $results)) === 1, 'two concurrent source requests issue exactly one revision: ' . json_encode($results));
check(count($revisions('trendhome:7302')) === 1 && $prints('trendhome:7302') === 2 && $count("SELECT COUNT(*) FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = 'trendhome:7302' AND q.status = 'active'") === 1, 'one revision, two prints, one active QR');

// ---- Commerce updates and stage work never replace the document ------------------------------------------
$qr7301 = $activeQr('trendhome:7301');
$docVersion = (int) $order('trendhome:7301')['document_version'];
check(($ingest($kernel, 'trendhome', '7301', "th-7301-b-{$suffix}", 500, 'on-hold')['body']['outcome'] ?? null) === 'applied', 'a commerce-only update');
check($order('trendhome:7301')['document_status'] === 'active' && count($revisions('trendhome:7301')) === 1 && $activeQr('trendhome:7301') === $qr7301, 'a commerce-only update keeps the approved document and its QR');
checkOk($call($worker, 'POST', '/orders/trendhome%3A7301/claim', ['expectedVersion' => (int) $order('trendhome:7301')['production_version']], $key('claim'), null, T::ORIGIN), 'the worker claims');
checkOk($call($worker, 'POST', '/orders/trendhome%3A7301/transition', ['expectedVersion' => (int) $order('trendhome:7301')['production_version']], $key('transition'), null, T::ORIGIN), 'the worker completes waiting');
check($order('trendhome:7301')['production_stage_id'] === 'material-preparation' && $order('trendhome:7301')['document_status'] === 'active' && count($revisions('trendhome:7301')) === 1 && $activeQr('trendhome:7301') === $qr7301, 'stage work never changes the document or its QR');

// ---- A printed change: stale, refused to the source, approval-bound revision 2 --------------------------------
check(($ingest($kernel, 'trendhome', '7301', "th-7301-c-{$suffix}", 400, 'processing', 'active', [[
    'id' => 801, 'line' => 1, 'name' => 'Perdea Voal Alb', 'sku' => 'PV-ALB', 'color' => 'Alb', 'width' => 3.5, 'height' => 2.75, 'unit' => 'm', 'quantity' => 1,
    'options' => [['label' => 'buc', 'value' => '2 buc.'], ['label' => 'Manopera', 'value' => 'Manopera Rejansa Normal 6cm']],
]])['body']['outcome'] ?? null) === 'applied', 'the customer width changes');
check($order('trendhome:7301')['document_status'] === 'stale' && count($revisions('trendhome:7301')) === 1, 'the printed change makes the document stale; nothing is regenerated by ingestion');
checkError($sourcePrint($kernel, 'trendhome', ['orderId' => '7301']), 409, 'DOCUMENT_REVISION_REQUIRED', 'the source cannot print a stale document');
check(checkOk($states($kernel, 'trendhome', ['7301']), 'stale state')['orders'][0]['activeRevision'] === null, 'a stale document has no printable revision');
$stale = $order('trendhome:7301');
$requested = checkOk($call($operator, 'POST', $docPath('trendhome:7301', 'revision-requests'), ['expectedDocumentVersion' => (int) $stale['document_version'], 'comment' => 'Lățimea s-a schimbat.'], $key('request'), null, T::ORIGIN), 'the operator requests revision 2', 201);
$requestId = (string) $requested['request']['id'];
check(checkOk($states($kernel, 'trendhome', ['7301']), 'pending state')['orders'][0]['request'] === ['status' => 'pending', 'targetRevision' => 2], 'the source sees the pending approval');
checkError($call($operator, 'POST', $docPath('trendhome:7301', 'generate'), ['expectedDocumentVersion' => (int) $order('trendhome:7301')['document_version'], 'requestId' => $requestId], $key('gen-unapproved'), null, T::ORIGIN), 409, 'DOCUMENT_APPROVAL_REQUIRED', 'an unapproved revision cannot be generated');
checkError($call($operator, 'POST', "/production-documents/revision-requests/{$requestId}/decision", ['expectedVersion' => 1, 'decision' => 'approve'], $key('self')), 403, 'UNAUTHORIZED_ACTION', 'the requester cannot approve');
$decideKey = $key('approve');
checkOk($call($approver, 'POST', "/production-documents/revision-requests/{$requestId}/decision", ['expectedVersion' => 1, 'decision' => 'approve'], $decideKey), 'the approver approves');
check(checkOk($call($approver, 'POST', "/production-documents/revision-requests/{$requestId}/decision", ['expectedVersion' => 1, 'decision' => 'approve'], $decideKey), 'approval replay')['status'] === 'approved', 'a replayed approval has no second effect');
checkError($call($approver, 'POST', "/production-documents/revision-requests/{$requestId}/decision", ['expectedVersion' => 1, 'decision' => 'approve'], $key('approve-again')), 409, 'DOCUMENT_REQUEST_RESOLVED', 'a request is decided once');
checkError($sourcePrint($kernel, 'trendhome', ['orderId' => '7301']), 409, 'DOCUMENT_REVISION_REQUIRED', 'the source never generates the approved revision itself');
checkOk($call($operator, 'POST', $docPath('trendhome:7301', 'generate'), ['expectedDocumentVersion' => (int) $order('trendhome:7301')['document_version'], 'requestId' => $requestId], $key('gen-r2'), null, T::ORIGIN), 'the operator generates revision 2', 201);
$r2 = $revisions('trendhome:7301');
check(count($r2) === 2 && $r2[0]['status'] === 'superseded' && $r2[1]['status'] === 'active' && 'ARASYA:Q1:' . $r2[1]['qr_reference'] === $activeQr('trendhome:7301') && $activeQr('trendhome:7301') !== $qr7301, 'revision 2 supersedes revision 1 and binds a new active QR');
$printedR2 = checkOk($sourcePrint($kernel, 'trendhome', ['orderId' => '7301']), 'the source prints revision 2')['document'];
check($printedR2['activeRevision']['number'] === 2 && $printedR2['issued'] === false && $printedR2['printNumber'] === 1 && $printedR2['activeRevision']['qrRevision'] === 2 && $printedR2['filename'] === 'ARASYA-7301-R2.pdf', 'the source prints exactly the approved current revision');
checkError($sourcePrint($kernel, 'trendhome', ['orderId' => '7301', 'revisionNumber' => 1]), 409, 'DOCUMENT_REVISION_NOT_ACTIVE', 'the superseded revision is no longer printable');
$scan = T::call($kernel, 'POST', '/orders/resolve-qr', ['token' => $qr7301], ['origin' => T::ORIGIN, 'x-csrf-token' => $worker['csrf']], $worker['cookie']);
check($scan['status'] === 410 && in_array($scan['body']['error']['code'] ?? null, ['DOCUMENT_SUPERSEDED', 'QR_SUPERSEDED'], true) && $noPii($scan['body']), 'the old ticket QR is refused without customer data: ' . json_encode($scan['body']));

// ---- Preview: no print recorded, permissions -----------------------------------------------------------------
$printsBefore = $prints('trendhome:7301');
$preview = T::call($kernel, 'GET', $docPath('trendhome:7301') . '/revisions/2/preview', null, ['origin' => T::ORIGIN], $operator['cookie']);
check($preview['status'] === 200 && str_starts_with((string) ($preview['headers']['Content-Disposition'] ?? ''), 'inline;') && str_contains((string) $preview['headers']['Content-Disposition'], 'PREVIZUALIZARE') && $prints('trendhome:7301') === $printsBefore, 'the preview is inline and records no print');
check(T::call($kernel, 'GET', $docPath('trendhome:7301') . '/revisions/1/preview', null, ['origin' => T::ORIGIN], $operator['cookie'])['status'] === 200, 'history holders preview a superseded revision');
check(T::call($kernel, 'GET', $docPath('trendhome:7301') . '/revisions/9/preview', null, ['origin' => T::ORIGIN], $operator['cookie'])['status'] === 404, 'an unknown revision is not found');
checkError(T::call($kernel, 'GET', $docPath('trendhome:7301') . '/revisions/2/preview', null, ['origin' => T::ORIGIN], $worker['cookie']), 403, 'UNAUTHORIZED_ACTION', 'a production worker cannot preview documents');
checkError(T::call($kernel, 'GET', $docPath('trendhome:7301') . '/revisions/2/preview', null, ['origin' => T::ORIGIN]), 401, 'SESSION_EXPIRED', 'an anonymous preview is refused');

// ---- Root console revoke: document and QR revoked, the source refused, other orders untouched ------------------
$otherQr = $activeQr('trendhome:7302');
$beforeRevoke = $order('trendhome:7301');
$revokeQr = $activeQr('trendhome:7301');
$rootUuid = (string) $pdo->query('SELECT employee_uuid FROM system_root_identity')->fetchColumn();
$container->documentService()->revoke($container->employeeRepository()->findByUuid($rootUuid), 'trendhome:7301', ['expectedDocumentVersion' => (int) $beforeRevoke['document_version'], 'reason' => 'Test revocare'], $key('revoke'), 'test-revoke');
check($order('trendhome:7301')['document_status'] === 'revoked' && $activeQr('trendhome:7301') === null && $revisions('trendhome:7301')[1]['status'] === 'revoked', 'the root revoke revokes the document and its QR');
check($count("SELECT COUNT(*) FROM production_qr_events WHERE global_order_id = 'trendhome:7301' AND action = 'revoked' AND reason_code = 'document_revoked'") === 1, 'the QR revocation is evidenced');
checkError($sourcePrint($kernel, 'trendhome', ['orderId' => '7301']), 409, 'DOCUMENT_REVOKED', 'the source cannot print a revoked document');
$revokedScan = T::call($kernel, 'POST', '/orders/resolve-qr', ['token' => $revokeQr], ['origin' => T::ORIGIN, 'x-csrf-token' => $worker['csrf']], $worker['cookie']);
check($revokedScan['status'] === 410 && ($revokedScan['body']['error']['code'] ?? null) === 'DOCUMENT_REVOKED' && $noPii($revokedScan['body']), 'the revoked QR is refused as DOCUMENT_REVOKED');
check($activeQr('trendhome:7302') === $otherQr && $order('trendhome:7301')['production_stage_id'] === $beforeRevoke['production_stage_id'] && $order('trendhome:7301')['production_version'] === $beforeRevoke['production_version'], 'the revoke changes no other order and no stage');

// ---- Source isolation, QR prerequisite, cancelled orders ----------------------------------------------------
check(checkOk($states($kernel, 'outletperdele', ['7301']), 'cross-source')['orders'][0]['exists'] === false, 'a source never sees another source\'s document');
checkError($sourcePrint($kernel, 'outletperdele', ['orderId' => '7301']), 404, 'ORDER_NOT_FOUND', 'a source cannot print another source\'s document');
check(checkOk($states($qrObserveKernel, 'trendhome', ['7302']), 'qr observe')['documentAuthorityMode'] === 'observe', 'document enforce without QR enforce runs as observe');
check(($ingest($kernel, 'trendhome', '7401', "th-7401-a-{$suffix}", 300, 'cancelled', 'cancelled')['body']['outcome'] ?? null) === 'applied', 'a cancelled order');
checkError($sourcePrint($kernel, 'trendhome', ['orderId' => '7401']), 409, 'ORDER_UNAVAILABLE', 'no document is issued for an unavailable order');
check(checkOk($states($kernel, 'trendhome', ['7401']), 'cancelled state')['orders'][0]['issueAllowed'] === false, 'an unavailable order is not issuable');

$cleanup();
fwrite(STDOUT, "PASS MySQL production document authority integration: {$checks} checks.\n");
