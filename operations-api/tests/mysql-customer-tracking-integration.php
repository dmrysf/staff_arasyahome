<?php

declare(strict_types=1);

// Customer tracking authority (API 2.21.0, no migration) against a dedicated MySQL/MariaDB test database:
// per-source tracking modes and their fallbacks, the signed read-only orders/tracking answer, the customer
// milestone presentation of the 14 canonical stages (driven by real claims and transitions), timestamps,
// rework replay, commerce updates and cancellation, manager takeover, source and cross-source isolation,
// signature failures, the separate rate-limit bucket, read-only behaviour under real process concurrency
// and the absence of customer, employee, document and QR data in the answer.

use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Application\Container;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Integration\SourceTrackingQueries;
use Arasya\Operations\Production\CanonicalProductionWorkflowContract;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL customer tracking integration: ARASYA_TEST_DB_NAME is not configured.\n");
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

const DASHBOARD_ORIGIN = 'http://127.0.0.1:4174';
$settings = static fn (string $tracking, string $outletTracking = 'legacy', string $authority = 'enforce'): array => [
    'trendhome' => ['mode' => 'active', 'authority' => $authority, 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'trackingAuthority' => $tracking],
    'outletperdele' => ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'trackingAuthority' => $outletTracking],
];
$config = T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $settings('enforce', 'enforce'));
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
    foreach (['production_exceptions', 'cutting_transfers', 'cutting_facts', 'analytics_ownership_intervals', 'analytics_order_projection', 'responsibility_assignments', 'organization_principals', 'order_activity_events', 'order_operation_idempotency', 'employee_order_relations', 'order_qr_references', 'operational_order_items', 'order_projection_receipts', 'api_rate_limit_buckets'] as $table) {
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
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string {
    $keys++;
    return sprintf('trk-%s-%s-%04d', $label, $suffix, $keys);
};
$login = static function (string $username, string $password) use ($kernel): array {
    $response = T::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password], ['origin' => DASHBOARD_ORIGIN]);
    if ($response['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $response['headers']['Set-Cookie'] ?? '', $match)) {
        throw new RuntimeException("Login failed for {$username}: " . json_encode($response['body']));
    }
    return ['cookie' => rawurldecode($match[1]), 'csrf' => (string) $response['body']['csrfToken'], 'employeeUuid' => (string) $response['body']['employee']['employeeUuid']];
};
$call = static function (array $who, string $method, string $path, ?array $json = null, ?string $idem = null, string $origin = DASHBOARD_ORIGIN) use ($kernel): array {
    $headers = ['origin' => $origin, 'x-csrf-token' => $who['csrf']];
    if ($idem !== null) {
        $headers['idempotency-key'] = $idem;
    }
    return T::call($kernel, $method, $path, $json, $headers, $who['cookie']);
};
$track = static fn (object $k, string $source, array $ids, ?string $secret = null, ?int $timestamp = null): array => T::ingest($k, $source, ['orderIds' => $ids], $secret, $timestamp, 'orders/tracking');
$one = static fn (object $k, string $source, string $id): array => checkOk($track($k, $source, [$id]), "tracking of {$source}:{$id}")['orders'][0];
$order = static function (string $globalId) use ($pdo): array {
    $statement = $pdo->prepare('SELECT * FROM operational_orders WHERE global_order_id = ?');
    $statement->execute([$globalId]);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
};
$count = static function (string $sql, array $args = []) use ($pdo): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($args);
    return (int) $statement->fetchColumn();
};
$ingest = static function (object $k, string $source, string $id, string $event, int $ago, string $status = 'processing', string $availability = 'active', ?array $production = null): array {
    $payload = T::sourceOrder($id, $event, gmdate('Y-m-d\TH:i:s\Z', time() - $ago), $production, $status, $availability, [[
        'id' => 901, 'line' => 1, 'name' => 'Draperie Secretă Velur', 'sku' => 'DSV-9', 'color' => 'Smarald', 'width' => 3, 'height' => 2.6, 'unit' => 'm', 'quantity' => 1,
    ]]);
    $payload['order']['delivery'] = ['name' => 'TEST Client Urmarire', 'street' => 'Str. Ascunsa 17', 'city' => 'Brasov', 'postalCode' => '500000', 'country' => 'RO', 'phone' => '0744000777'];
    return T::ingest($k, $source, $payload);
};
$noLeak = static function (mixed $value, string $message) use (&$workerName): void {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    foreach (['TEST Client', 'Ascunsa', '0744000777', '744000777', 'Brasov', 'Draperie Secretă', 'DSV-9', 'Smarald', 'Verifică sensul', 'ARASYA:Q1', 'employee', 'Uuid', 'uuid', 'Test tracker', 'label', 'notes', 'document', 'qr'] as $needle) {
        check(!str_contains($json, $needle), "{$message}: the answer must not contain '{$needle}'");
    }
};
$expectedKeys = ['orderId', 'globalOrderId', 'exists', 'productionAuthority', 'trackingAuthority', 'tracking'];
$trackingKeys = ['state', 'milestone', 'milestoneNumber', 'milestoneCount', 'stageNumber', 'stageCount', 'milestones', 'updatedAt'];

// ---- Registry: modes, fallbacks and independence ---------------------------------------------------------------
$registry = static fn (array $sources): SourceRegistry => SourceRegistry::fromConfig(T::config($dbName, [T::ORIGIN], $sources));
$default = $registry(['trendhome' => ['mode' => 'active', 'authority' => 'enforce']]);
check($default->find('trendhome')->trackingAuthorityMode->value === 'legacy' && $default->issues() === [], 'the default tracking mode is legacy without issues');
$fallback = $registry(['trendhome' => ['mode' => 'active', 'authority' => 'observe', 'trackingAuthority' => 'enforce']]);
check($fallback->find('trendhome')->trackingAuthorityMode->value === 'observe' && in_array(['code' => 'tracking_authority_requires_production_enforce', 'sourceKey' => 'trendhome'], $fallback->issues(), true), 'tracking enforce without production enforce is reported and runs as observe');
$invalid = $registry(['trendhome' => ['mode' => 'active', 'authority' => 'enforce', 'trackingAuthority' => 'on']]);
check($invalid->find('trendhome') !== null && $invalid->find('trendhome')->trackingAuthorityMode->value === 'legacy' && in_array(['code' => 'invalid_tracking_authority_mode', 'sourceKey' => 'trendhome'], $invalid->issues(), true), 'an unreadable tracking mode keeps the source usable in legacy');
$independent = $registry(['trendhome' => ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'legacy', 'documentAuthority' => 'legacy', 'trackingAuthority' => 'enforce']]);
$definition = $independent->find('trendhome');
check($definition->trackingAuthorityMode->value === 'enforce' && $definition->qrAuthorityMode->value === 'legacy' && $definition->documentAuthorityMode->value === 'legacy' && $definition->authorityMode->value === 'enforce', 'the tracking mode is independent of the QR and document modes');
check(count(SourceTrackingQueries::MILESTONES) === 6 && array_keys(SourceTrackingQueries::STAGE_MILESTONES) === array_keys(CanonicalProductionWorkflowContract::STAGES), 'every one of the 14 canonical stages has exactly one customer milestone');
check(count(CanonicalProductionWorkflowContract::STAGES) === 14, 'the canonical workflow still has 14 stages');

// ---- Heartbeat and contract surface -------------------------------------------------------------------------------
$beat = checkOk(T::ingest($kernel, 'trendhome', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')], null, null, 'heartbeat'), 'heartbeat');
check($beat['trackingAuthorityMode'] === 'enforce' && $beat['documentAuthorityMode'] === 'enforce' && $beat['contract']['stageCount'] === 14, 'the heartbeat reports the tracking mode');
check(checkOk(T::ingest($legacyKernel, 'trendhome', ['sentAt' => gmdate('Y-m-d\TH:i:s\Z')], null, null, 'heartbeat'), 'legacy heartbeat')['trackingAuthorityMode'] === 'legacy', 'the legacy heartbeat reports legacy');

// ---- Identities ---------------------------------------------------------------------------------------------------
$rootUsername = 'arasya.root.tracking.' . $suffix;
$rootTemporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-tracking-root', $rootUsername);
$first = $login($rootUsername, $rootTemporary);
check(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $rootTemporary, 'newPassword' => 'Tracking root passphrase 2026!'], ['origin' => DASHBOARD_ORIGIN, 'x-csrf-token' => $first['csrf']], $first['cookie'])['status'] === 200, 'root changes its temporary password');
$root = $login($rootUsername, 'Tracking root passphrase 2026!');
$roleIds = [];
foreach (checkOk($call($root, 'GET', '/management/roles'), 'roles')['items'] as $role) {
    $roleIds[$role['key']] = (int) $role['id'];
}
$password = 'tracking passphrase 2026';
$admin = $container->employeeAdmin();
$identity = static function (string $name, array $stages, array $applications, array $roles) use ($admin, $call, $root, $password, $suffix): string {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test');
    checkOk($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications]), "{$name} applications");
    checkOk($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
    return $employee->employeeUuid;
};
$identity('tracker', array_keys(CanonicalProductionWorkflowContract::STAGES), ['staff'], []);
$identity('director', [], ['staff', 'dashboard'], [$roleIds['operations-director']]);
$worker = $login("tracker.{$suffix}", $password);
$director = $login("director.{$suffix}", $password);
$advance = static function (string $globalId) use ($call, $worker, $order, $key, $pdo): void {
    $path = '/orders/' . rawurlencode($globalId);
    checkOk($call($worker, 'POST', "{$path}/claim", T::cuttingClaim($pdo, $globalId, $worker['employeeUuid'], (int) $order($globalId)['production_version']), $key('claim'), T::ORIGIN), "claim {$globalId} at " . $order($globalId)['production_stage_id']);
    checkOk($call($worker, 'POST', "{$path}/transition", ['expectedVersion' => (int) $order($globalId)['production_version']], $key('transition'), T::ORIGIN), "complete {$globalId} at " . $order($globalId)['production_stage_id']);
};

// ---- Legacy: nothing about Arasya production leaves the API ---------------------------------------------------------
check(($ingest($legacyKernel, 'trendhome', '8101', "th-8101-a-{$suffix}", 900)['body']['outcome'] ?? null) === 'applied', 'an operations order exists');
checkError($track($legacyKernel, 'trendhome', ['8101']), 409, 'TRACKING_CUTOVER_INACTIVE', 'legacy: the tracking answer is refused');
checkError($track($kernel, 'outletperdele', ['8101'], T::TRENDHOME_SECRET), 401, 'SOURCE_SIGNATURE_INVALID', 'another source secret is refused');
checkError($track($kernel, 'trendhome', ['8101'], T::OUTLET_SECRET), 401, 'SOURCE_SIGNATURE_INVALID', 'the tracking answer requires the source signature');
checkError($track($kernel, 'trendhome', ['8101'], null, time() - 3600), 401, 'SOURCE_SIGNATURE_INVALID', 'a replayed (old) signed request is refused');
checkError($track($kernel, 'unknownshop', ['8101']), 503, 'SOURCE_NOT_CONFIGURED', 'an unknown source is refused');
checkError(T::call($kernel, 'GET', '/integrations/sources/trendhome/orders/tracking'), 404, 'NOT_FOUND', 'the tracking route is POST only');
checkError(T::ingest($kernel, 'trendhome', ['orderIds' => ['8101'], 'email' => 'x@example.com'], null, null, 'orders/tracking'), 422, 'SOURCE_PAYLOAD_INVALID', 'only order ids are accepted (no customer credential reaches Arasya)');
checkError($track($kernel, 'trendhome', array_map('strval', range(1, 51))), 422, 'SOURCE_PAYLOAD_INVALID', 'at most 50 orders per request');
checkError($track($kernel, 'trendhome', ['8101', '8101']), 422, 'SOURCE_PAYLOAD_INVALID', 'order ids must be unique');
checkError($track($kernel, 'trendhome', ['81 01']), 422, 'SOURCE_PAYLOAD_INVALID', 'order ids are validated');

// ---- A new operations order: received ----------------------------------------------------------------------------
$fresh = $one($kernel, 'trendhome', '8101');
check(array_keys($fresh) === $expectedKeys && array_keys($fresh['tracking']) === $trackingKeys, 'the answer has exactly the documented fields: ' . json_encode($fresh));
check($fresh['globalOrderId'] === 'trendhome:8101' && $fresh['exists'] === true && $fresh['productionAuthority'] === 'operations' && $fresh['trackingAuthority'] === 'arasya', 'an operations order is tracked by Arasya');
check($fresh['tracking']['state'] === 'active' && $fresh['tracking']['milestone'] === 'received' && $fresh['tracking']['milestoneNumber'] === 1 && $fresh['tracking']['milestoneCount'] === 6 && $fresh['tracking']['stageNumber'] === 1 && $fresh['tracking']['stageCount'] === 14, 'a waiting order is "received"');
check($fresh['tracking']['milestones'][0] === ['key' => 'received', 'reached' => true, 'reachedAt' => '2026-10-01T08:00:00Z'] && $fresh['tracking']['milestones'][1] === ['key' => 'materials', 'reached' => false, 'reachedAt' => null], 'received carries the order acceptance time; later milestones are pending');
$noLeak($fresh, 'fresh order');
$observed = $one($observeKernel, 'trendhome', '8101');
check($observed['trackingAuthority'] === 'arasya' && $observed['tracking'] === $fresh['tracking'], 'observe returns the same answer for private comparison');

// ---- Unknown, source-managed and cross-source orders ----------------------------------------------------------------
$unknown = $one($kernel, 'trendhome', '999999');
check($unknown === ['orderId' => '999999', 'globalOrderId' => 'trendhome:999999', 'exists' => false, 'productionAuthority' => null, 'trackingAuthority' => null, 'tracking' => null], 'an unknown order is reported, never an error');
check(($ingest($legacyKernel, 'trendhome', '8102', "th-8102-a-{$suffix}", 900)['body']['outcome'] ?? null) === 'applied', 'second order');
$pdo->exec("UPDATE operational_orders SET production_authority = 'source' WHERE global_order_id = 'trendhome:8102'");
$source = $one($kernel, 'trendhome', '8102');
check($source['exists'] === true && $source['productionAuthority'] === 'source' && $source['trackingAuthority'] === 'source' && $source['tracking'] === null, 'a source-managed order keeps the source tracking: no Arasya progress is returned');
$cross = $one($kernel, 'outletperdele', '8101');
check($cross['globalOrderId'] === 'outletperdele:8101' && $cross['exists'] === false && $cross['tracking'] === null, 'the same Woo id at another source never reveals the Trendhome order');
check(($ingest($kernel, 'outletperdele', '8101', "op-8101-a-{$suffix}", 900)['body']['outcome'] ?? null) === 'applied', 'OutletPerdele has its own order 8101');
$both = checkOk($track($kernel, 'outletperdele', ['8101']), 'outlet 8101')['orders'][0];
check($both['globalOrderId'] === 'outletperdele:8101' && $both['exists'] === true, 'each source reads only its own global order id');
$outletLegacy = (new Container(T::config($dbName, [T::ORIGIN, DASHBOARD_ORIGIN], $settings('enforce', 'legacy')), $pdo))->kernel();
checkError($track($outletLegacy, 'outletperdele', ['8101']), 409, 'TRACKING_CUTOVER_INACTIVE', 'one source in enforce never opens tracking for another source still in legacy');
check($one($outletLegacy, 'trendhome', '8101')['trackingAuthority'] === 'arasya', 'and Trendhome keeps its enforce answer');

// ---- Real stage work through all 14 stages -----------------------------------------------------------------------------
$before = $order('trendhome:8101');
$answers = [];
$expected = [
    'material-preparation' => ['materials', 2], 'workshop-receiving' => ['materials', 3], 'labeling' => ['production', 4], 'material-straightening' => ['production', 5],
    'bottom-hem' => ['production', 6], 'side-hem' => ['production', 7], 'ironing' => ['production', 8], 'height' => ['production', 9], 'header-tape' => ['production', 10],
    'sewing-finishing' => ['production', 11], 'quality-control' => ['quality', 12], 'packing' => ['packing', 13], 'delivery' => ['packing', 14],
];
foreach ($expected as $stage => [$milestone, $number]) {
    $advance('trendhome:8101');
    check($order('trendhome:8101')['production_stage_id'] === $stage, "the order is now at {$stage}");
    $answer = $one($kernel, 'trendhome', '8101');
    check($answer['tracking']['milestone'] === $milestone && $answer['tracking']['stageNumber'] === $number && $answer['tracking']['state'] === 'active', "{$stage} is presented as {$milestone} (stage {$number}/14): " . json_encode($answer['tracking']));
    $answers[$stage] = $answer;
}
check($answers['delivery']['tracking']['milestone'] === 'packing', 'the internal delivery stage is never presented as shipped');
$materials = $count("SELECT COUNT(*) FROM order_activity_events e JOIN operational_orders o ON o.order_uuid = e.order_uuid WHERE o.global_order_id = 'trendhome:8101' AND e.to_stage_id = 'material-preparation'");
check($materials === 1 && $answers['workshop-receiving']['tracking']['milestones'][1]['reachedAt'] === $answers['material-preparation']['tracking']['milestones'][1]['reachedAt'], 'a milestone keeps the time it was first entered while stages inside it advance');
foreach ($answers['packing']['tracking']['milestones'] as $index => $milestone) {
    check($milestone['reached'] === ($index <= 4) && ($index > 4 || $milestone['reachedAt'] !== null), "milestone {$milestone['key']} reached state at packing");
}
$advance('trendhome:8101');
$done = $one($kernel, 'trendhome', '8101');
check($done['tracking']['state'] === 'completed' && $done['tracking']['milestone'] === 'ready' && $done['tracking']['milestoneNumber'] === 6 && $done['tracking']['stageNumber'] === 14, 'a completed production is "ready" (never shipped)');
check($done['tracking']['milestones'][5]['reachedAt'] !== null && $done['tracking']['updatedAt'] === $done['tracking']['milestones'][5]['reachedAt'], 'ready carries the completion time');
$noLeak($done, 'completed order');
$after = $order('trendhome:8101');
check((int) $after['production_version'] === (int) $before['production_version'] + 28, 'only the 28 real claims and transitions changed the production version');

// ---- Read-only, repeatable and concurrent ---------------------------------------------------------------------------------
$rowsBefore = $pdo->query('SELECT global_order_id, production_authority, production_stage_id, production_version, version, updated_at, operational_status, last_source_seen_at FROM operational_orders ORDER BY global_order_id')->fetchAll(PDO::FETCH_ASSOC);
$eventsBefore = $count('SELECT COUNT(*) FROM order_activity_events') + $count('SELECT COUNT(*) FROM production_authority_events') + $count('SELECT COUNT(*) FROM order_qr_references');
$contactBefore = $pdo->query("SELECT last_contact_at FROM order_sources WHERE source_key = 'trendhome'")->fetchColumn();
$repeat = [];
for ($i = 0; $i < 5; $i++) {
    $repeat[] = json_encode(checkOk($track($kernel, 'trendhome', ['8101', '8102', '999999']), 'repeat')['orders']);
}
check(count(array_unique($repeat)) === 1, 'repeated requests return identical answers');
$start = (string) (microtime(true) + 1.0);
$workers = [];
foreach (['a', 'b', 'c', 'd'] as $label) {
    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/tracking-worker.php', $dbName, $start, '20'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
}
$results = [];
foreach ($workers as [$process, $pipes]) {
    $results[] = json_decode((string) stream_get_contents($pipes[1]), true) ?? ['stderr' => stream_get_contents($pipes[2])];
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
check(array_column($results, 'ok') === [20, 20, 20, 20] && count(array_unique(array_column($results, 'hash'))) === 1 && $results[0]['hash'] === md5($repeat[0]), 'four concurrent processes read 80 identical answers: ' . json_encode($results));
check($pdo->query('SELECT global_order_id, production_authority, production_stage_id, production_version, version, updated_at, operational_status, last_source_seen_at FROM operational_orders ORDER BY global_order_id')->fetchAll(PDO::FETCH_ASSOC) === $rowsBefore, 'tracking reads change no order');
check($count('SELECT COUNT(*) FROM order_activity_events') + $count('SELECT COUNT(*) FROM production_authority_events') + $count('SELECT COUNT(*) FROM order_qr_references') === $eventsBefore, 'tracking reads create no event and no QR');
check($pdo->query("SELECT last_contact_at FROM order_sources WHERE source_key = 'trendhome'")->fetchColumn() === $contactBefore, 'tracking reads do not even touch the source contact time');
check($count("SELECT COUNT(*) FROM api_rate_limit_buckets WHERE bucket_scope = 'source-tracking'") >= 1, 'tracking requests use their own rate-limit bucket');

// ---- The tracking bucket never starves ingestion ----------------------------------------------------------------------------
$pdo->exec("UPDATE api_rate_limit_buckets SET hits = 100000, window_started_at = UTC_TIMESTAMP(6) WHERE bucket_scope = 'source-tracking'");
checkError($track($kernel, 'trendhome', ['8101']), 429, 'RATE_LIMITED', 'an exhausted tracking bucket refuses tracking');
check(($ingest($kernel, 'trendhome', '8103', "th-8103-a-{$suffix}", 800)['body']['outcome'] ?? null) === 'applied', 'ingestion still works while tracking is rate limited');
$pdo->exec("DELETE FROM api_rate_limit_buckets WHERE bucket_scope = 'source-tracking'");

// ---- Commerce updates, duplicates, out-of-order events and cancellation ------------------------------------------------------
$stage8103 = $order('trendhome:8103');
check(($ingest($kernel, 'trendhome', '8103', "th-8103-b-{$suffix}", 700, 'on-hold')['body']['outcome'] ?? null) === 'applied', 'a commerce-only update (on-hold)');
check(($ingest($kernel, 'trendhome', '8103', "th-8103-b-{$suffix}", 700, 'on-hold')['body']['outcome'] ?? null) === 'duplicate', 'a duplicate event');
check(($ingest($kernel, 'trendhome', '8103', "th-8103-old-{$suffix}", 760, 'processing')['body']['outcome'] ?? null) === 'out_of_order', 'an out-of-order event');
check(($ingest($kernel, 'trendhome', '8103', "th-8103-c-{$suffix}", 600, 'processing', 'active', T::stage('packing'))['body']['outcome'] ?? null) === 'applied', 'a source stage claim for an operations order');
$held = $one($kernel, 'trendhome', '8103');
check($held['tracking']['milestone'] === 'received' && (int) $order('trendhome:8103')['production_version'] === (int) $stage8103['production_version'] && $order('trendhome:8103')['production_stage_id'] === 'waiting', 'commerce updates, duplicates, out-of-order events and source stages never move the Arasya production or its tracking');
check(($ingest($kernel, 'trendhome', '8103', "th-8103-d-{$suffix}", 500, 'cancelled', 'cancelled')['body']['outcome'] ?? null) === 'applied', 'the source cancels the order');
$cancelled = $one($kernel, 'trendhome', '8103');
check($cancelled['tracking']['state'] === 'cancelled' && $cancelled['tracking']['milestone'] === 'received' && $order('trendhome:8103')['production_stage_id'] === 'waiting', 'a cancelled order is reported as cancelled without a production change');

// ---- Manager takeover moves a source order into Arasya tracking ------------------------------------------------------------------
$sourceBefore = $order('trendhome:8102');
$taken = checkOk($call($director, 'POST', '/orders/' . rawurlencode('trendhome:8102') . '/production-authority/takeover', ['expectedVersion' => (int) $sourceBefore['production_version'], 'stageId' => 'sewing-finishing', 'workflowId' => 'curtain-production', 'workflowVersion' => 1], $key('take'), T::ORIGIN), 'the director takes 8102 over at sewing-finishing');
check($taken['productionAuthority'] === 'operations', 'takeover applied');
$takenOver = $one($kernel, 'trendhome', '8102');
check($takenOver['trackingAuthority'] === 'arasya' && $takenOver['tracking']['milestone'] === 'production' && $takenOver['tracking']['stageNumber'] === 11, 'after a takeover the order is tracked by Arasya at the selected stage');
check($takenOver['tracking']['milestones'][1] === ['key' => 'materials', 'reached' => true, 'reachedAt' => null] && $takenOver['tracking']['milestones'][2]['reachedAt'] !== null, 'stages Arasya never saw are reached without an invented time; the takeover time is the production time');

// ---- Rework replay and unknown stages (pure presentation) -------------------------------------------------------------------------
$base = ['production_stage_id' => 'labeling', 'production_completed_at' => null, 'operational_status' => 'in_progress', 'accepted_at' => '2026-10-01 08:00:00.000000', 'created_at' => '2026-10-01 08:00:01.000000', 'production_changed_at' => '2026-10-03 09:00:00.000000'];
$events = [
    ['action' => 'stage_completed', 'to_stage_id' => 'material-preparation', 'occurred_at' => '2026-10-02 08:00:00.000000'],
    ['action' => 'stage_completed', 'to_stage_id' => 'labeling', 'occurred_at' => '2026-10-02 10:00:00.000000'],
    ['action' => 'stage_completed', 'to_stage_id' => 'quality-control', 'occurred_at' => '2026-10-02 12:00:00.000000'],
    ['action' => 'fault_returned', 'to_stage_id' => 'material-preparation', 'occurred_at' => '2026-10-03 07:00:00.000000'],
    ['action' => 'stage_completed', 'to_stage_id' => 'labeling', 'occurred_at' => '2026-10-03 09:00:00.000000'],
];
$rework = SourceTrackingQueries::tracking($base, $events);
check($rework['milestone'] === 'production' && $rework['milestones'][1]['reachedAt'] === '2026-10-03T07:00:00Z' && $rework['milestones'][2]['reachedAt'] === '2026-10-03T09:00:00Z' && $rework['milestones'][3] === ['key' => 'quality', 'reached' => false, 'reachedAt' => null], 'after rework the timeline shows the latest entries and no longer claims quality control: ' . json_encode($rework['milestones']));
$odd = SourceTrackingQueries::tracking(['production_stage_id' => 'retired-stage'] + $base, []);
check($odd['state'] === 'unavailable' && $odd['milestone'] === null && $odd['milestones'] === [], 'an unknown stage is never guessed');

$cleanup();
$pdo->exec('DELETE FROM system_root_identity');
fwrite(STDOUT, "OK customer tracking integration ({$checks} checks)\n");
