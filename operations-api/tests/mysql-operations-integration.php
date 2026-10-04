<?php

declare(strict_types=1);

// HTTP-level Staff operations lifecycle against a dedicated MySQL test database:
// signed source ingestion, freshness, QR/lookup, claim, N -> N+1 transitions,
// idempotency, concurrency, cross-employee isolation and persisted activity.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Integration\Trendyol\TrendyolOrderMapper;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL operations integration: ARASYA_TEST_DB_NAME is not configured.\n");
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

$config = T::config($dbName);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
$seedFiles = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seedFiles, SORT_STRING);
foreach ($seedFiles as $seedFile) {
    (new SqlFileRunner($pdo))->run($seedFile);
}
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$admin = $container->employeeAdmin();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$password = 'integration passphrase 2026';
$employee = static fn (string $name, array $stages): string => $admin->create("Angajat {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test')->username;

$alice = T::login($kernel, $employee('alice', ['material-preparation']), $password);
$bob = T::login($kernel, $employee('bob', ['material-preparation']), $password);
$carol = T::login($kernel, $employee('carol', ['workshop-receiving']), $password);
$dan = T::login($kernel, $employee('dan', ['delivery']), $password);
$scanner = T::login($kernel, $employee('scanner', ['material-preparation']), $password);

$orderA = "A{$suffix}";
$globalA = "trendhome:{$orderA}";
$mutate = static function (array $who, string $operation, string $globalId, int $expectedVersion, ?string $key = null, array $headers = []) use ($kernel): array {
    return T::call($kernel, 'POST', '/orders/' . rawurlencode($globalId) . '/' . $operation, ['expectedVersion' => $expectedVersion], [
        'x-csrf-token' => $who['csrf'],
        'idempotency-key' => $key ?? 'key-' . bin2hex(random_bytes(12)),
        ...$headers,
    ], $who['cookie']);
};
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $query);

// ---- Signed source ingestion -------------------------------------------------
$payload = T::sourceOrder($orderA, "evt-a1-{$suffix}", '2026-10-04T08:00:00.000Z', T::stage('material-preparation'));
checkError(T::ingest($kernel, 'trendhome', $payload, 'wrong-secret-wrong-secret-wrong-secret-xx'), 401, 'SOURCE_SIGNATURE_INVALID', 'wrong source secret is rejected');
checkError(T::ingest($kernel, 'trendhome', $payload, null, time() - 3600), 401, 'SOURCE_SIGNATURE_INVALID', 'stale signed timestamp is rejected');
checkError(T::ingest($kernel, 'trendyol', $payload), 503, 'SOURCE_NOT_CONFIGURED', 'unconfigured source cannot push');
checkError(T::ingest($kernel, 'trendhome', [...$payload, 'unexpected' => true]), 422, 'SOURCE_PAYLOAD_INVALID', 'unknown payload fields are rejected');
$applied = T::ingest($kernel, 'trendhome', $payload);
check($applied['status'] === 200 && $applied['body']['outcome'] === 'applied' && $applied['body']['globalOrderId'] === $globalA, 'first signed event is applied');
$qrA = (string) $applied['body']['qr'];
check(preg_match('/^ARASYA:Q1:[A-Z2-7]{26}$/D', $qrA) === 1, 'ingestion returns an opaque QR payload');
check(T::ingest($kernel, 'trendhome', $payload)['body']['outcome'] === 'duplicate', 'replayed event is a duplicate');
$older = T::sourceOrder($orderA, "evt-a0-{$suffix}", '2026-10-04T07:00:00.000Z', T::stage('workshop-receiving'));
check(T::ingest($kernel, 'trendhome', $older)['body']['outcome'] === 'out_of_order', 'older event never rolls state');
$commerce = T::sourceOrder($orderA, "evt-a2-{$suffix}", '2026-10-04T08:05:00.000Z', T::stage('material-preparation'), 'completed');
check(T::ingest($kernel, 'trendhome', $commerce)['body']['outcome'] === 'applied', 'commerce-only update applies');
$orderRow = $pdo->prepare('SELECT production_stage_id, production_version, version, source_commerce_status_code FROM operational_orders WHERE global_order_id = ?');
$orderRow->execute([$globalA]);
$row = $orderRow->fetch();
check($row['production_stage_id'] === 'material-preparation' && (int) $row['production_version'] === 1 && (int) $row['version'] === 2 && $row['source_commerce_status_code'] === 'completed', 'commerce status never moves production or production version');
checkError(T::ingest($kernel, 'trendhome', T::sourceOrder($orderA, "evt-a3-{$suffix}", '2026-10-04T08:06:00.000Z', T::stage('cutting'))), 422, 'SOURCE_STAGE_UNKNOWN', 'unknown source stage is rejected without guessing');
checkError(T::ingest($kernel, 'trendhome', T::sourceOrder($orderA, "evt-a4-{$suffix}", '2026-10-04T08:05:00.000Z', T::stage('material-preparation'), 'cancelled')), 409, 'SOURCE_REVISION_CONFLICT', 'same timestamp with conflicting payload is a revision conflict');

$orderOutlet = "O{$suffix}";
$outlet = T::ingest($kernel, 'outletperdele', T::sourceOrder($orderOutlet, "evt-o1-{$suffix}", '2026-10-04T08:00:00Z', null, 'processing', 'active', null, "#{$orderA}"));
check($outlet['body']['outcome'] === 'applied', 'source without production block is applied');
$orderRow->execute(["outletperdele:{$orderOutlet}"]);
check($orderRow->fetch()['production_stage_id'] === 'waiting', 'orders without explicit production stage start at waiting');
T::ingest($kernel, 'outletperdele', T::sourceOrder($orderOutlet, "evt-o2-{$suffix}", '2026-10-04T08:10:00Z', T::stage('labeling'), 'processing', 'active', null, "#{$orderA}"));
T::ingest($kernel, 'outletperdele', T::sourceOrder($orderOutlet, "evt-o3-{$suffix}", '2026-10-04T08:20:00Z', T::stage('material-preparation'), 'processing', 'active', null, "#{$orderA}"));
$orderRow->execute(["outletperdele:{$orderOutlet}"]);
check($orderRow->fetch()['production_stage_id'] === 'labeling', 'explicit source stage moves forward only');

$heartbeat = T::ingest($kernel, 'trendhome', ['sentAt' => '2026-10-04T08:00:00Z'], null, null, 'heartbeat');
check($heartbeat['status'] === 200, 'signed heartbeat is accepted');

// ---- Freshness ---------------------------------------------------------------
$detail = $get($alice, '/orders/' . rawurlencode($globalA));
check($detail['status'] === 200 && $detail['body']['freshness']['status'] === 'fresh', 'freshly contacted source is fresh');
$pdo->exec("UPDATE order_sources SET last_contact_at = UTC_TIMESTAMP(6) - INTERVAL 30 MINUTE WHERE source_key = 'trendhome'");
check($get($alice, '/orders/' . rawurlencode($globalA))['body']['freshness']['status'] === 'stale', 'missed heartbeats make orders stale');
$pdo->exec("UPDATE order_sources SET last_contact_at = UTC_TIMESTAMP(6) - INTERVAL 3 HOUR WHERE source_key = 'trendhome'");
check($get($alice, '/orders/' . rawurlencode($globalA))['body']['freshness']['status'] === 'source_unavailable', 'a silent source becomes unavailable');
T::ingest($kernel, 'trendhome', ['sentAt' => '2026-10-04T08:00:00Z'], null, null, 'heartbeat');
check($get($alice, '/orders/' . rawurlencode($globalA))['body']['freshness']['status'] === 'fresh', 'the next heartbeat recovers freshness deterministically');

// ---- Authentication, origin and CSRF ------------------------------------------
checkError(T::call($kernel, 'GET', '/orders/mine'), 401, 'SESSION_EXPIRED', 'orders require a session');
checkError(T::call($kernel, 'GET', '/activity/mine'), 401, 'SESSION_EXPIRED', 'activity requires a session');
checkError($mutate($alice, 'claim', $globalA, 1, null, ['x-csrf-token' => 'forged']), 403, 'CSRF_INVALID', 'claim requires the session CSRF token');
checkError(T::call($kernel, 'POST', '/orders/' . rawurlencode($globalA) . '/claim', ['expectedVersion' => 1], ['origin' => 'https://evil.example', 'x-csrf-token' => $alice['csrf'], 'idempotency-key' => 'key-origin-check-0001'], $alice['cookie']), 403, 'ORIGIN_DENIED', 'mutations require the exact Staff origin');
checkError(T::call($kernel, 'POST', '/orders/' . rawurlencode($globalA) . '/claim', ['expectedVersion' => 1], ['x-csrf-token' => $alice['csrf']], $alice['cookie']), 400, 'INVALID_IDEMPOTENCY_KEY', 'mutations require an idempotency key');
checkError(T::call($kernel, 'POST', '/orders/' . rawurlencode($globalA) . '/claim', ['expectedVersion' => 1, 'toStageId' => 'delivery'], ['x-csrf-token' => $alice['csrf'], 'idempotency-key' => 'key-extra-field-0001'], $alice['cookie']), 400, 'INVALID_REQUEST', 'browser cannot submit a destination stage');
checkError(T::call($kernel, 'POST', '/orders/resolve-qr', ['token' => $qrA], ['x-csrf-token' => 'forged'], $alice['cookie']), 403, 'CSRF_INVALID', 'QR resolution requires CSRF');

// ---- Lookup ------------------------------------------------------------------
$lookup = $get($alice, '/orders/lookup', ['code' => $orderA]);
check($lookup['status'] === 200 && $lookup['body']['id'] === $globalA && ($lookup['body']['employeeAllowedAction']['id'] ?? null) === 'claim', 'manual order number lookup resolves an eligible order with a claim action');
check(!isset($lookup['body']['employeeRelation']) && !str_contains(json_encode($lookup['body']), $bob['employeeUuid']), 'lookup exposes no other employee identity');
check($get($alice, '/orders/lookup', ['code' => "  #{$orderA} "])['body']['id'] === $globalA, 'lookup tolerates # and whitespace only');
check($get($alice, '/orders/lookup', ['code' => "TRENDHOME:{$orderA}"])['body']['id'] === $globalA, 'lookup accepts the global reference with a case-insensitive source');
checkError($get($alice, '/orders/lookup', ['code' => "' OR 1=1 --"]), 400, 'INVALID_LOOKUP_CODE', 'SQL injection input is rejected');
checkError($get($alice, '/orders/lookup', ['code' => '%']), 400, 'INVALID_LOOKUP_CODE', 'wildcards are rejected');
checkError($get($alice, '/orders/lookup', ['code' => str_repeat('9', 200)]), 400, 'INVALID_LOOKUP_CODE', 'oversized codes are rejected');
checkError($get($alice, '/orders/lookup', ['code' => "NX{$suffix}"]), 404, 'ORDER_NOT_FOUND', 'unknown order code is not found');
checkError($get($carol, '/orders/lookup', ['code' => $orderA]), 404, 'ORDER_NOT_FOUND', 'an order outside the employee stages is not found');
// Outlet order number "#A..." normalizes to the same lookup code; it is invisible to Alice (labeling), so no ambiguity leaks.
$pdo->prepare("UPDATE operational_orders SET production_stage_id = 'material-preparation' WHERE global_order_id = ?")->execute(["outletperdele:{$orderOutlet}"]);
checkError($get($alice, '/orders/lookup', ['code' => $orderA]), 409, 'ORDER_AMBIGUOUS', 'two visible orders sharing a number are reported as ambiguous');
$pdo->prepare("UPDATE operational_orders SET production_stage_id = 'labeling' WHERE global_order_id = ?")->execute(["outletperdele:{$orderOutlet}"]);

// ---- QR ----------------------------------------------------------------------
$qr = static fn (array $who, string $token): array => T::call($kernel, 'POST', '/orders/resolve-qr', ['token' => $token], ['x-csrf-token' => $who['csrf']], $who['cookie']);
check($qr($alice, $qrA)['body']['id'] === $globalA, 'valid QR resolves the normalized order');
check($qr($alice, strtolower($qrA))['body']['id'] === $globalA, 'QR payload is case-insensitive');
checkError($qr($alice, 'arasya:61833'), 400, 'INVALID_QR', 'legacy/arbitrary QR text is invalid');
checkError($qr($alice, 'ARASYA:Q1:AAAAAAAAAAAAAAAAAAAAAAAAAA'), 404, 'UNKNOWN_QR', 'unregistered QR reference is unknown');
checkError($qr($carol, $qrA), 404, 'ORDER_NOT_FOUND', 'QR of an unauthorized order is not found');
checkError(T::call($kernel, 'POST', '/orders/resolve-qr', ['token' => $qrA, 'employeeUuid' => $bob['employeeUuid']], ['x-csrf-token' => $alice['csrf']], $alice['cookie']), 400, 'INVALID_REQUEST', 'browser cannot supply an employee identity');
$rotated = $container->projectionWriter()->rotateQrReference("outletperdele:{$orderOutlet}");
$oldOutletQr = (string) $outlet['body']['qr'];
check($rotated !== $oldOutletQr, 'QR rotation issues a new reference');
$pdo->prepare("UPDATE operational_orders SET production_stage_id = 'material-preparation' WHERE global_order_id = ?")->execute(["outletperdele:{$orderOutlet}"]);
checkError($qr($alice, $oldOutletQr), 410, 'EXPIRED_QR', 'a revoked QR reference is expired');
$pdo->prepare("UPDATE operational_orders SET production_stage_id = 'labeling' WHERE global_order_id = ?")->execute(["outletperdele:{$orderOutlet}"]);

// ---- Claim -------------------------------------------------------------------
$claimKey = 'key-claim-alice-' . $suffix;
$claim = $mutate($alice, 'claim', $globalA, 1, $claimKey);
check($claim['status'] === 200 && $claim['body']['productionVersion'] === 2 && $claim['body']['employeeRelation']['type'] === 'claimed' && $claim['body']['employeeAllowedAction']['id'] === 'complete_stage', 'eligible employee claims the order');
$replay = $mutate($alice, 'claim', $globalA, 1, $claimKey);
// JSON numbers replayed from storage may decode as int instead of float (300 vs 300.0); compare JSON semantics.
check($replay['status'] === 200 && $replay['body'] == $claim['body'], 'same idempotency key returns the original result');
checkError($mutate($alice, 'claim', $globalA, 2, $claimKey), 409, 'IDEMPOTENCY_CONFLICT', 'same key with a different payload conflicts');
checkError($mutate($bob, 'claim', $globalA, 2), 409, 'ORDER_ALREADY_CLAIMED', 'a second employee cannot claim an owned order');
check($get($bob, '/orders/' . rawurlencode($globalA))['body']['employeeActionBlockedReason'] === 'claimed_by_other', 'other employees see why the action is blocked');
checkError($mutate($bob, 'transition', $globalA, 2), 409, 'ORDER_ALREADY_CLAIMED', 'a non-owner cannot complete the stage');
checkError($mutate($carol, 'claim', $globalA, 2), 404, 'ORDER_NOT_FOUND', 'an unrelated employee cannot claim by changing the URL');

// ---- Transition --------------------------------------------------------------
checkError($mutate($alice, 'transition', $globalA, 1), 409, 'ORDER_CHANGED', 'stale expected version is rejected');
$transitionKey = 'key-transition-alice-' . $suffix;
$transition = $mutate($alice, 'transition', $globalA, 2, $transitionKey);
check($transition['status'] === 200 && $transition['body']['productionStageId'] === 'workshop-receiving' && $transition['body']['productionVersion'] === 3, 'server advances exactly N -> N+1');
check($transition['body']['employeeRelation']['type'] === 'handover_out' && !isset($transition['body']['employeeAllowedAction']) && $transition['body']['employeeActionBlockedReason'] === 'stage_not_allowed', 'completion hands the order to the next stage');
check($mutate($alice, 'transition', $globalA, 2, $transitionKey)['body'] == $transition['body'], 'transition retry with the same key is deterministic');
checkError($mutate($alice, 'transition', $globalA, 3), 403, 'UNAUTHORIZED_ACTION', 'an employee cannot continue into a stage they are not allowed');
$mine = $get($alice, '/orders/mine');
check($mine['status'] === 200 && in_array($globalA, array_column($mine['body']['items'], 'id'), true), '/orders/mine keeps handed-over orders of the employee');
check(!in_array($globalA, array_column($get($bob, '/orders/mine')['body']['items'], 'id'), true), '/orders/mine never shows another employee\'s work');
checkError($mutate($carol, 'transition', $globalA, 3), 409, 'INVALID_STAGE_TRANSITION', 'the next stage must be claimed before completion');
$carolClaim = $mutate($carol, 'claim', $globalA, 3);
check($carolClaim['body']['employeeRelation']['type'] === 'handover_in', 'the next stage employee receives the handover');
checkError($mutate($carol, 'transition', $globalA, 3), 409, 'ORDER_CHANGED', 'no N -> N+2 with an outdated version');
$carolDone = $mutate($carol, 'transition', $globalA, 4);
check($carolDone['body']['productionStageId'] === 'labeling', 'second transition reaches only the following stage');
checkError($get($bob, '/orders/' . rawurlencode($globalA)), 404, 'ORDER_NOT_FOUND', 'cross-employee detail access is not found');
T::ingest($kernel, 'trendhome', T::sourceOrder($orderA, "evt-a9-{$suffix}", '2026-10-04T09:00:00.000Z', T::stage('packing')));
$orderRow->execute([$globalA]);
$row = $orderRow->fetch();
check($row['production_stage_id'] === 'labeling' && (int) $row['production_version'] === 5, 'source stage cannot overwrite Operations-owned production');

// ---- Concurrency (separate PHP processes, separate connections) ---------------
$raceOrder = "R{$suffix}";
T::ingest($kernel, 'trendhome', T::sourceOrder($raceOrder, "evt-r1-{$suffix}", '2026-10-04T08:00:00Z', T::stage('material-preparation')));
$race = static function (array $jobs) use ($dbName): array {
    $start = microtime(true) + 1.0;
    $processes = [];
    foreach ($jobs as [$who, $operation, $orderId, $version, $key]) {
        $command = [PHP_BINARY, __DIR__ . '/fixtures/operation-worker.php', $dbName, $operation, $orderId, (string) $version, $key, $who['cookie'], $who['csrf'], (string) $start];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $processes[] = [$process, $pipes[1]];
    }
    $results = [];
    foreach ($processes as [$process, $stdout]) {
        $results[] = json_decode(trim((string) stream_get_contents($stdout)), true, 8, JSON_THROW_ON_ERROR);
        fclose($stdout);
        proc_close($process);
    }
    return $results;
};
$claims = $race([[$alice, 'claim', "trendhome:{$raceOrder}", 1, 'key-race-a-' . $suffix], [$bob, 'claim', "trendhome:{$raceOrder}", 1, 'key-race-b-' . $suffix]]);
$winners = array_values(array_filter($claims, static fn (array $result): bool => $result['status'] === 200));
$losers = array_values(array_filter($claims, static fn (array $result): bool => $result['status'] === 409 && in_array($result['code'], ['ORDER_ALREADY_CLAIMED', 'ORDER_CHANGED'], true)));
check(count($winners) === 1 && count($losers) === 1, 'two employees racing to claim produce exactly one owner: ' . json_encode($claims));
$owner = $claims[0]['status'] === 200 ? $alice : $bob;
$sameKey = 'key-race-transition-' . $suffix;
$transitions = $race([[$owner, 'transition', "trendhome:{$raceOrder}", 2, $sameKey], [$owner, 'transition', "trendhome:{$raceOrder}", 2, $sameKey]]);
check($transitions[0]['status'] === 200 && $transitions[1]['status'] === 200 && $transitions[0] === $transitions[1] && $transitions[0]['stage'] === 'workshop-receiving', 'simultaneous retries with one key commit once: ' . json_encode($transitions));
$events = $pdo->prepare("SELECT COUNT(*) FROM order_activity_events WHERE global_order_id = ? AND action = 'stage_completed'");
$events->execute(["trendhome:{$raceOrder}"]);
check((int) $events->fetchColumn() === 1, 'a duplicated simultaneous transition writes one activity event');

// ---- Final stage completion ----------------------------------------------------
$finalOrder = "F{$suffix}";
T::ingest($kernel, 'trendhome', T::sourceOrder($finalOrder, "evt-f1-{$suffix}", '2026-10-04T08:00:00Z', T::stage('delivery')));
$danClaim = $mutate($dan, 'claim', "trendhome:{$finalOrder}", 1);
check($danClaim['body']['employeeAllowedAction']['id'] === 'complete_production', 'the final stage offers production completion');
$completed = $mutate($dan, 'transition', "trendhome:{$finalOrder}", 2);
check($completed['body']['productionStageId'] === 'delivery' && $completed['body']['status'] === 'handed_over' && isset($completed['body']['productionCompletedAt']) && $completed['body']['employeeRelation']['type'] === 'completed', 'delivery completion marks the order completed without a new status');
checkError($mutate($dan, 'transition', "trendhome:{$finalOrder}", 3), 409, 'INVALID_STAGE_TRANSITION', 'a completed order cannot move further');

// ---- Unavailable order and workflow --------------------------------------------
$cancelled = "C{$suffix}";
T::ingest($kernel, 'trendhome', T::sourceOrder($cancelled, "evt-c1-{$suffix}", '2026-10-04T08:00:00Z', T::stage('material-preparation'), 'cancelled', 'cancelled'));
checkError($mutate($alice, 'claim', "trendhome:{$cancelled}", 1), 409, 'ORDER_UNAVAILABLE', 'cancelled orders cannot be claimed');
$pdo->exec("UPDATE production_stages SET status = 'inactive' WHERE stage_id = 'ironing'");
try {
    checkError($mutate($carol, 'claim', $globalA, 5), 503, 'WORKFLOW_UNAVAILABLE', 'an invalid active workflow blocks mutations');
} finally {
    $pdo->exec("UPDATE production_stages SET status = 'active' WHERE stage_id = 'ironing'");
}

// ---- Activity ----------------------------------------------------------------
$activity = $get($alice, '/activity/mine', ['range' => 'today']);
check($activity['status'] === 200, 'activity loads');
$aliceActions = array_column(array_filter($activity['body']['items'], static fn (array $item): bool => $item['orderId'] === $globalA), 'action');
check($aliceActions === ['stage_completed', 'claimed'], 'activity lists the employee\'s claim and stage completion newest first');
$completedEntry = array_values(array_filter($activity['body']['items'], static fn (array $item): bool => $item['orderId'] === $globalA && $item['action'] === 'stage_completed'))[0];
check($completedEntry['fromStageId'] === 'material-preparation' && $completedEntry['toStageId'] === 'workshop-receiving' && $completedEntry['fromStageLabelSnapshot'] === 'Pregătire material' && $completedEntry['meters'] === 8.4, 'activity stores stable IDs, label snapshots and meters');
check($activity['body']['summary']['handedOver'] >= 1 && $activity['body']['summary']['processed'] >= 1, 'today summary is computed from persisted events');
foreach ($get($carol, '/activity/mine', ['range' => 'today'])['body']['items'] as $item) {
    check($item['orderId'] !== "trendhome:{$raceOrder}", 'activity contains only the authenticated employee\'s events');
}
$pdo->exec("UPDATE production_stages SET display_name = 'Pregătire materiale (redenumit)' WHERE stage_id = 'material-preparation'");
try {
    $renamed = array_values(array_filter($get($alice, '/activity/mine', ['range' => 'today'])['body']['items'], static fn (array $item): bool => $item['orderId'] === $globalA && $item['action'] === 'stage_completed'))[0];
    check($renamed['fromStageLabelSnapshot'] === 'Pregătire material', 'historical labels do not change after a rename');
} finally {
    $pdo->exec("UPDATE production_stages SET display_name = 'Pregătire material' WHERE stage_id = 'material-preparation'");
}
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d');
check(count($get($alice, '/activity/mine', ['range' => 'custom', 'from' => $today, 'to' => $today])['body']['items']) >= 2, 'custom date range returns today\'s events');
check($get($alice, '/activity/mine', ['range' => 'custom', 'from' => '2020-01-01', 'to' => '2020-01-02'])['body']['items'] === [], 'custom date range filters by date');
checkError($get($alice, '/activity/mine', ['range' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01']), 400, 'INVALID_RANGE', 'invalid calendar dates are rejected');
checkError($get($alice, '/activity/mine', ['range' => 'year']), 400, 'INVALID_RANGE', 'unsupported ranges are rejected');
checkError($get($alice, '/activity/mine', ['range' => 'today', 'employeeUuid' => $bob['employeeUuid']]), 400, 'INVALID_REQUEST', 'activity never accepts a browser employee identity');

// ---- Trendyol adapter contract (fixture; no live credentials) -------------------
$fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/trendyol-packages.json'), true, 64, JSON_THROW_ON_ERROR);
$trendyolPackage = $fixture['content'][0];
$trendyolPackage['id'] = (int) (hexdec(substr($suffix, 0, 7)) + 1000);
check($container->projectionWriter()->apply(TrendyolOrderMapper::map($trendyolPackage)) === 'applied', 'a Trendyol package projects through the shared writer');
$orderRow->execute(['trendyol:' . $trendyolPackage['id']]);
$row = $orderRow->fetch();
check($row['production_stage_id'] === 'waiting' && $row['source_commerce_status_code'] === 'Picking', 'Trendyol commerce status stays independent from production');

// ---- Rate limiting and inactive accounts --------------------------------------
$limited = false;
for ($i = 0; $i < 70 && !$limited; $i++) {
    $limited = $get($scanner, '/orders/lookup', ['code' => "NX{$suffix}"])['status'] === 429;
}
check($limited, 'manual lookups are rate limited per employee');
$pdo->prepare("UPDATE employees SET status = 'inactive' WHERE employee_uuid = ?")->execute([$scanner['employeeUuid']]);
checkError($get($scanner, '/orders/mine'), 401, 'ACCOUNT_INACTIVE', 'inactive employees lose operational access');

// ---- Maintenance against real MySQL (native prepares) ---------------------------
$maintenance = new \Arasya\Operations\Database\AuthMaintenance($pdo, 30, 30, 7, null, 500, 30);
$dryRun = $maintenance->run(true);
check(array_keys($dryRun) === ['sessions', 'login_attempts', 'rate_limit_buckets', 'audit_events', 'idempotency_keys', 'api_rate_limit_buckets'], 'maintenance dry-run reports every retained table on MySQL');
$pdo->exec("UPDATE order_operation_idempotency SET created_at = UTC_TIMESTAMP(6) - INTERVAL 40 DAY WHERE idempotency_key = " . $pdo->quote($claimKey));
check($maintenance->run(false)['idempotency_keys'] >= 1, 'expired idempotency results are pruned on MySQL');
check((int) $pdo->query('SELECT COUNT(*) FROM order_activity_events')->fetchColumn() > 0, 'maintenance never deletes the activity audit');

fwrite(STDOUT, "PASS MySQL Staff operations lifecycle ({$checks} checks).\n");
