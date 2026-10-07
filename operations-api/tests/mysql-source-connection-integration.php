<?php

declare(strict_types=1);

// Signed YD SOFT source connection foundation against a dedicated MySQL test database:
// per-site secrets and modes, the zero-write validation endpoint, the active-ingestion mode guard,
// idempotency across validate-then-ingest, heartbeat and the unchanged 14-stage workflow.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL source connection integration: ARASYA_TEST_DB_NAME is not configured.\n");
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

$validationConfig = T::config($dbName, [T::ORIGIN], ['trendhome' => ['mode' => 'validation'], 'outletperdele' => ['mode' => 'validation']]);
$pdo = Connection::create($validationConfig);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
$seedFiles = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seedFiles, SORT_STRING);
foreach ($seedFiles as $seedFile) {
    (new SqlFileRunner($pdo))->run($seedFile);
}
$validationKernel = (new Container($validationConfig, $pdo))->kernel();
$activeKernel = (new Container(T::config($dbName), $pdo))->kernel();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);

/**
 * Checksums every table except the rate-limit counters, so any write by the code under test is visible.
 * @return array<string, string>
 */
$snapshot = static function () use ($pdo): array {
    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    $state = [];
    foreach ($tables as $table) {
        if (in_array($table, ['api_rate_limit_buckets', 'rate_limit_buckets'], true)) {
            continue;
        }
        $row = $pdo->query('CHECKSUM TABLE `' . str_replace('`', '', (string) $table) . '`')->fetch(PDO::FETCH_ASSOC);
        $state[(string) $table] = (string) ($row['Checksum'] ?? 'missing');
    }
    return $state;
};
$changedTables = static fn (array $before, array $after): array => array_keys(array_filter($after, static fn (string $sum, string $table): bool => ($before[$table] ?? null) !== $sum, ARRAY_FILTER_USE_BOTH));
$count = static function (string $sql, array $params = []) use ($pdo): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
};

// ---- Canonical workflow is unchanged ---------------------------------------------
$stages = $pdo->query("SELECT ps.stage_id, ps.display_name, ps.ordinal FROM production_stages ps JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id WHERE pw.workflow_key = 'curtain-production' AND pw.version = 1 AND pw.status = 'active' AND ps.status = 'active' ORDER BY ps.ordinal")->fetchAll(PDO::FETCH_ASSOC);
check(array_map(static fn (array $stage): string => $stage['ordinal'] . ':' . $stage['stage_id'] . ':' . $stage['display_name'], $stages) === [
    '1:waiting:În așteptare', '2:material-preparation:Tăiere', '3:workshop-receiving:Primire Croitorie', '4:labeling:Etichetare',
    '5:material-straightening:Îndreptare material', '6:bottom-hem:Tivul de jos', '7:side-hem:Tivul lateral', '8:ironing:Călcare',
    '9:height:Înălțime', '10:header-tape:Rejansă', '11:sewing-finishing:Finisare coasere', '12:quality-control:Control calitate',
    '13:packing:Împachetare', '14:delivery:Livrare',
], 'curtain-production@1 keeps its exact 14 canonical stages');

// ---- Validation mode: authenticate, validate, heartbeat, never write --------------
$orderId = "V{$suffix}";
$globalId = "trendhome:{$orderId}";
$eventId = "evt-v1-{$suffix}";
$payload = T::sourceOrder($orderId, $eventId, '2026-10-07T08:00:00Z');
$payload['order']['delivery'] = ['name' => 'TEST Client Validare', 'street' => 'Str. Test 1', 'city' => 'Cluj-Napoca', 'postalCode' => '400000', 'country' => 'RO', 'phone' => '0722000111'];
$payload['order']['items'][0]['options'] = [['label' => 'Confecționare', 'value' => '2 bucăți']];

$before = $snapshot();
for ($attempt = 0; $attempt < 3; $attempt++) {
    $validated = T::ingest($validationKernel, 'trendhome', $payload, null, null, 'orders/validate');
    check($validated['status'] === 200 && $validated['body'] === [
        'ok' => true, 'schemaVersion' => 1, 'sourceKey' => 'trendhome', 'mode' => 'validation',
        'workflow' => ['id' => 'curtain-production', 'version' => 1, 'stageCount' => 14], 'itemCount' => 1,
    ], 'validation answers the contract summary only (got ' . json_encode($validated['body']) . ')');
    foreach ([$orderId, 'TEST Client', '0722', 'DV-302', 'Draperie', $eventId, 'bucăți', 'Verifică'] as $private) {
        check(!str_contains((string) $validated['raw'], $private), "validation never echoes payload data ({$private})");
    }
}
$withStage = T::sourceOrder("S{$suffix}", "evt-s1-{$suffix}", '2026-10-07T08:00:00Z', T::stage('material-preparation'));
check(T::ingest($validationKernel, 'trendhome', $withStage, null, null, 'orders/validate')['status'] === 200, 'an explicit canonical stage validates');
checkError(T::ingest($validationKernel, 'trendhome', T::sourceOrder("S{$suffix}", "evt-s2-{$suffix}", '2026-10-07T08:00:00Z', T::stage('cutting')), null, null, 'orders/validate'), 422, 'SOURCE_STAGE_UNKNOWN', 'an unknown stage is rejected without guessing');
$withEmail = $payload;
$withEmail['order']['delivery']['email'] = 'client@example.com';
checkError(T::ingest($validationKernel, 'trendhome', $withEmail, null, null, 'orders/validate'), 422, 'SOURCE_PAYLOAD_INVALID', 'email is rejected by validation');
checkError(T::ingest($validationKernel, 'trendhome', $payload), 403, 'SOURCE_NOT_ACTIVE', 'a validation source cannot use real ingestion');
checkError(T::ingest($validationKernel, 'trendhome', $payload, T::OUTLET_SECRET, null, 'orders/validate'), 401, 'SOURCE_SIGNATURE_INVALID', 'the OutletPerdele secret cannot authenticate as Trendhome');
checkError(T::ingest($validationKernel, 'outletperdele', $payload, T::TRENDHOME_SECRET, null, 'orders/validate'), 401, 'SOURCE_SIGNATURE_INVALID', 'the Trendhome secret cannot authenticate as OutletPerdele');
checkError(T::ingest($validationKernel, 'trendhome', $payload, null, time() - 301, 'orders/validate'), 401, 'SOURCE_SIGNATURE_INVALID', 'an expired timestamp is rejected');
checkError(T::ingest($validationKernel, 'trendhome', $payload, null, time() + 301, 'orders/validate'), 401, 'SOURCE_SIGNATURE_INVALID', 'a future timestamp outside the skew is rejected');
checkError(T::ingest($validationKernel, 'trendhome', $payload, null, null, 'orders/validate', 'v1=' . str_repeat('0', 63)), 401, 'SOURCE_SIGNATURE_INVALID', 'a malformed signature is rejected');
check(T::ingest($validationKernel, 'outletperdele', $payload, null, null, 'orders/validate')['body']['sourceKey'] === 'outletperdele', 'OutletPerdele validates with its own secret');
$after = $snapshot();
check($changedTables($before, $after) === [], 'validation and the mode guard write nothing (changed: ' . implode(', ', $changedTables($before, $after)) . ')');
check($count('SELECT COUNT(*) FROM operational_orders WHERE global_order_id = ?', [$globalId]) === 0, 'validation creates no canonical order');
check($count('SELECT COUNT(*) FROM order_projection_receipts WHERE source_key = ? AND source_event_id = ?', ['trendhome', $eventId]) === 0, 'validation consumes no projection receipt');

$heartbeat = T::ingest($validationKernel, 'trendhome', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')], null, null, 'heartbeat');
check($heartbeat['status'] === 200 && $heartbeat['body'] === ['ok' => true, 'sourceKey' => 'trendhome', 'mode' => 'validation', 'contract' => ['schemaVersion' => 1, 'workflowId' => 'curtain-production', 'workflowVersion' => 1, 'stageCount' => 14]], 'a validation source heartbeat reports its mode and contract');
check($changedTables($after, $snapshot()) === ['order_sources'], 'heartbeat only records source contact');
foreach ([T::TRENDHOME_SECRET, T::OUTLET_SECRET] as $secret) {
    check(!str_contains((string) $heartbeat['raw'], $secret) && !str_contains((string) $validated['raw'], $secret), 'responses never carry a source secret');
}

// ---- Active mode: the same event, validated first, is ingested exactly once ----------
$applied = T::ingest($activeKernel, 'trendhome', $payload);
check($applied['status'] === 200 && $applied['body']['outcome'] === 'applied' && $applied['body']['globalOrderId'] === $globalId, 'the validated event is still applied by real ingestion with the same eventId');
$qr = (string) $applied['body']['qr'];
check(preg_match('/^ARASYA:Q1:[A-Z2-7]{26}$/D', $qr) === 1, 'ingestion still returns the opaque QR payload');
$retry = T::ingest($activeKernel, 'trendhome', $payload);
check($retry['status'] === 200 && $retry['body'] === ['outcome' => 'duplicate', 'globalOrderId' => $globalId, 'qr' => $qr], 'a response-lost retry is a duplicate with the same order and QR');
check($count('SELECT COUNT(*) FROM operational_orders WHERE global_order_id = ?', [$globalId]) === 1, 'a duplicate event never duplicates the canonical order');
check($count('SELECT COUNT(*) FROM order_projection_receipts WHERE source_key = ? AND source_event_id = ?', ['trendhome', $eventId]) === 1, 'exactly one receipt exists for the event');
check($count("SELECT COUNT(*) FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? AND q.status = 'active'", [$globalId]) === 1, 'exactly one active QR exists');
$row = $pdo->prepare('SELECT production_stage_id, operational_status, document_context FROM operational_orders WHERE global_order_id = ?');
$row->execute([$globalId]);
$order = $row->fetch(PDO::FETCH_ASSOC);
check($order['production_stage_id'] === 'waiting' && $order['operational_status'] === 'in_progress', 'a new order without a production block starts in waiting');
check(!str_contains((string) $order['document_context'], '0722000111') && !str_contains((string) $order['document_context'], 'example.com'), 'delivery phone stays masked and email absent');

$beforeRevalidate = $snapshot();
check(T::ingest($activeKernel, 'trendhome', $payload, null, null, 'orders/validate')['body']['mode'] === 'active', 'an active source may still validate');
check($changedTables($beforeRevalidate, $snapshot()) === [], 'validation after ingestion still writes nothing');
check(T::ingest($activeKernel, 'trendhome', $payload)['body']['outcome'] === 'duplicate', 'validation never disturbs the stored receipt');

// Out-of-order and cancellation semantics are unchanged.
check(T::ingest($activeKernel, 'trendhome', T::sourceOrder($orderId, "evt-v0-{$suffix}", '2026-10-07T07:00:00Z'))['body']['outcome'] === 'out_of_order', 'an older event stays out of order');
$cancelled = T::ingest($activeKernel, 'trendhome', T::sourceOrder($orderId, "evt-v2-{$suffix}", '2026-10-07T09:00:00Z', null, 'cancelled', 'cancelled'));
check($cancelled['body']['outcome'] === 'applied' && $cancelled['body']['qr'] === $qr, 'cancellation is applied and keeps the QR');
$row->execute([$globalId]);
check($row->fetch(PDO::FETCH_ASSOC)['operational_status'] === 'unavailable', 'cancellation still marks the order unavailable');

// ---- Disabled, unknown and unregistered sources fail closed -------------------------
$disabledKernel = (new Container(T::config($dbName, [T::ORIGIN], ['trendhome' => ['mode' => 'active', 'enabled' => 'false'], 'outletperdele' => ['mode' => 'active']]), $pdo))->kernel();
foreach (['orders', 'orders/validate', 'heartbeat'] as $route) {
    checkError(T::ingest($disabledKernel, 'trendhome', $route === 'heartbeat' ? ['sentAt' => 'now'] : $payload, null, null, $route), 503, 'SOURCE_NOT_CONFIGURED', "a disabled source fails closed on {$route}");
    checkError(T::ingest($activeKernel, 'unknown-site', $payload, null, null, $route), 503, 'SOURCE_NOT_CONFIGURED', "an unknown source fails closed on {$route}");
    checkError(T::ingest($activeKernel, 'b2b', $payload, null, null, $route), 503, 'SOURCE_NOT_CONFIGURED', "the internal B2B key fails closed on {$route}");
}
$futureSecret = 'perdele-noi-integration-secret-0123456789abcdef';
$futureKernel = (new Container(T::config($dbName, [T::ORIGIN], ['perdele-noi' => ['mode' => 'active', 'displayName' => 'Perdele Noi']], ['perdele-noi' => $futureSecret]), $pdo))->kernel();
$future = T::sourceOrder("F{$suffix}", "evt-f1-{$suffix}", '2026-10-07T08:00:00Z');
check(T::ingest($futureKernel, 'perdele-noi', $future, $futureSecret, null, 'orders/validate')['body']['sourceKey'] === 'perdele-noi', 'a future configured site validates without new code');
checkError(T::ingest($futureKernel, 'perdele-noi', $future, $futureSecret), 404, 'SOURCE_UNKNOWN', 'real ingestion still requires the order_sources row');
checkError(T::ingest($futureKernel, 'trendhome', $future), 503, 'SOURCE_NOT_CONFIGURED', 'a site missing from this registry is unknown');
check($count('SELECT COUNT(*) FROM operational_orders WHERE source_key = ?', ['perdele-noi']) === 0, 'an unregistered source wrote nothing');

fwrite(STDOUT, "PASS MySQL signed source connection foundation ({$checks} checks).\n");
