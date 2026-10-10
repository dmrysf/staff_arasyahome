<?php

declare(strict_types=1);

// Source-scoped production stage authorization (migration 023) on a disposable database, end to end through the
// HTTP kernel: root-only scope management, the stage-and-source rule on every order path (detail, lookup, QR,
// stage queue and summary, claim, transition, cutting pool and transfers, management ownership, fault reports),
// document isolation, legacy unscoped grants unchanged, multi-source and mixed grants, revocation and inactivity.

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
    fwrite(STDOUT, "SKIP stage source scopes: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $message);
    }
};
// Bodies are read as the browser reads them (JSON), so objects such as empty scope maps compare as arrays.
$status = static function (array $response, int $expected, string $message) use ($check): array {
    $check($response['status'] === $expected, "{$message} (got {$response['status']} " . json_encode($response['body']) . ')');
    return json_decode(json_encode($response['body'] ?? []), true);
};
$error = static function (array $response, int $expected, string $code, string $message) use ($check): void {
    $check($response['status'] === $expected && ($response['body']['error']['code'] ?? null) === $code, "{$message} (got {$response['status']} " . json_encode($response['body']) . ')');
};

$config = T::config($dbName);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
$seeds = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seeds, SORT_STRING);
foreach ($seeds as $seed) {
    (new SqlFileRunner($pdo))->run($seed);
}
$container = new Container($config, $pdo);
$kernel = $container->kernel();
$one = static function (string $sql, array $params = []) use ($pdo): mixed {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
};

// ---- Migration 023 is additive ---------------------------------------------------------------------------------
$columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_stage_source_scopes' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
$check($columns === ['employee_uuid', 'stage_id', 'source_key', 'granted_at', 'granted_by_employee_uuid'], 'migration 023 creates the scope table: ' . json_encode($columns));
$foreignKeys = $pdo->query("SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_stage_source_scopes' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY REFERENCED_TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$check($foreignKeys === ['employees', 'employees', 'order_sources', 'production_stages'], 'scope rows reference employees, stages and sources: ' . json_encode($foreignKeys));
$stageAccessColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_stage_access' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
$check($stageAccessColumns === ['employee_uuid', 'stage_id', 'created_at'], 'existing stage grants are not altered');
$pdo->exec('DELETE FROM employee_stage_source_scopes');

// ---- Identities ------------------------------------------------------------------------------------------------
$suffix = bin2hex(random_bytes(4));
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "scope.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-scope-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$status(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Scope root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 200, 'root password');
$root = T::login($kernel, $rootName, 'Scope root passphrase 2026!');
$roleIds = [];
foreach ($status(T::call($kernel, 'GET', '/management/roles', null, [], $root['cookie']), 200, 'roles')['items'] as $role) {
    $roleIds[$role['key']] = (int) $role['id'];
}
$admin = $container->employeeAdmin();
$password = 'scope passphrase 2026';
$asRoot = static fn (string $method, string $path, array $body): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'scope-' . bin2hex(random_bytes(8))], $root['cookie']);
$uuids = [];
$identity = static function (string $name, array $stages, array $applications = ['staff'], array $roles = []) use ($admin, $asRoot, $status, $password, $suffix, $kernel, &$uuids): array {
    $employee = $admin->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test');
    $uuids[$name] = $employee->employeeUuid;
    $status($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $applications]), 200, "{$name} applications");
    if ($roles !== []) {
        $status($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), 200, "{$name} roles");
    }
    return T::login($kernel, "{$name}.{$suffix}", $password);
};
$login = static fn (string $name): array => T::login($kernel, "{$name}.{$suffix}", $password);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $query);
$send = static fn (array $who, string $method, string $path, array $body, ?string $key = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? 'scope-' . bin2hex(random_bytes(8))], $who['cookie']);
$claimBody = static fn (array $who, string $globalId, int $version): array => T::cuttingClaim($pdo, $globalId, $who['employeeUuid'], $version);
$version = static fn (string $globalId): int => (int) $one('SELECT production_version FROM operational_orders WHERE global_order_id = ?', [$globalId]);
$stageOf = static fn (string $globalId): string => (string) $one('SELECT production_stage_id FROM operational_orders WHERE global_order_id = ?', [$globalId]);
$scopeOf = static fn (array $who, string $stage = 'waiting'): array => array_column($status($get($who, '/orders/stage-queue', ['stage' => $stage]), 200, "stage queue {$stage}")['items'], 'id');
// Shared disposable databases hold other suites' orders too, so reach is checked per order and totals against SQL.
$sees = static fn (array $who, string $globalId): bool => $get($who, '/orders/' . rawurlencode($globalId))['status'] === 200;
$openAt = static fn (string $stage, ?array $sources = null): int => (int) $one("SELECT COUNT(*) FROM operational_orders WHERE production_stage_id = ? AND production_completed_at IS NULL AND operational_status <> 'unavailable'"
    . ($sources === null ? '' : ' AND source_key IN (' . implode(',', array_fill(0, count($sources), '?')) . ')') . " AND NOT (source_key = 'trendyol' AND production_stage_id = 'waiting')", [$stage, ...($sources ?? [])]);
$queueSources = static fn (array $queue): array => array_values(array_unique(array_map(static fn (string $id): string => explode(':', $id, 2)[0], array_column($queue['items'], 'id'))));

// ---- Orders: Trendhome, OutletPerdele and B2B at `waiting` and at `material-preparation` --------------------
$orders = [];
foreach (['SW1' => ['trendhome', 'waiting'], 'SW2' => ['trendhome', 'waiting'], 'OW1' => ['outletperdele', 'waiting'], 'SM1' => ['trendhome', 'material-preparation'], 'OM1' => ['outletperdele', 'material-preparation']] as $tag => [$source, $stage]) {
    $number = "{$tag}{$suffix}";
    $applied = T::ingest($kernel, $source, T::sourceOrder($number, "evt-{$tag}-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 120), T::stage($stage)));
    $check(($applied['body']['outcome'] ?? null) === 'applied', "order {$tag} ingested");
    $orders[$tag] = "{$source}:{$number}";
}
$company = $status($asRoot('POST', '/b2b/companies', ['legalName' => "Firma Scope {$suffix}", 'countryCode' => 'RO', 'taxIdentifier' => 'SCOPE' . strtoupper($suffix)]), 201, 'b2b company')['companyId'];
$b2b = $status($asRoot('POST', '/b2b/orders', ['companyId' => $company, 'currencyCode' => 'RON', 'lines' => [['productCode' => 'B2B-SCOPE', 'kind' => 'curtain', 'quantity' => 1, 'meters' => '5.0', 'pricingUnit' => 'meter', 'unitPriceNet' => '10.00', 'vatPercent' => '19', 'width' => '200', 'height' => '250']]]), 201, 'b2b order')['detail']['order'];
$b2b = $status($asRoot('POST', '/b2b/orders/' . $b2b['id'] . '/finalize', ['expectedVersion' => $b2b['version']]), 200, 'b2b finalize')['detail']['order'];
$status($asRoot('POST', '/b2b/orders/' . $b2b['id'] . '/production', ['expectedVersion' => $b2b['version']]), 201, 'b2b production handoff');
$orders['BW1'] = 'b2b:' . $b2b['id'];
$check($stageOf($orders['BW1']) === 'waiting', 'the B2B handoff enters stage 1');
$qr = static fn (string $globalId): string => 'ARASYA:Q1:' . (string) $one("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? AND q.status = 'active'", [$globalId]);

$tyonly = $identity('tyonly', ['waiting'], ['staff'], [$roleIds['trendyol-order-approver'], $roleIds['production-documents-operator']]);
$legacy = $identity('legacy', ['waiting']);
$multi = $identity('multi', ['waiting']);
$mixed = $identity('mixed', ['waiting', 'material-preparation']);
$cutScoped = $identity('cutscoped', ['material-preparation']);
$cutLegacy = $identity('cutlegacy', ['material-preparation']);
$manager = $identity('manager', [], ['staff', 'dashboard'], [$roleIds['department-manager']]);

// Effective access of an unscoped (legacy) grant before any scope exists anywhere.
$reach = static fn (array $who): array => array_map(static fn (string $tag): bool => $sees($who, $orders[$tag]), ['SW1' => 'SW1', 'SW2' => 'SW2', 'OW1' => 'OW1', 'BW1' => 'BW1']);
$legacyBefore = $reach($legacy) + ['total' => $status($get($legacy, '/orders/stage-queue', ['stage' => 'waiting']), 200, 'legacy queue')['counts']['total']];
$check($legacyBefore === ['SW1' => true, 'SW2' => true, 'OW1' => true, 'BW1' => true, 'total' => $openAt('waiting')], 'a legacy waiting grant reaches every source: ' . json_encode($legacyBefore));

// ---- Root-only scope management --------------------------------------------------------------------------------
$scopePath = static fn (string $name): string => "/management/employees/{$uuids[$name]}/stage-scopes";
$error($send($manager, 'PUT', $scopePath('tyonly'), ['scopes' => ['waiting' => ['trendyol']]]), 403, 'ROOT_ONLY', 'a department manager cannot change stage scopes');
$error($send($tyonly, 'PUT', $scopePath('tyonly'), ['scopes' => ['waiting' => ['trendhome', 'trendyol']]]), 403, 'APPLICATION_ACCESS_DENIED', 'an employee cannot widen the own scope');
$error($asRoot('PUT', $scopePath('tyonly'), ['scopes' => ['packing' => ['trendyol']]]), 400, 'STAGE_NOT_GRANTED', 'a scope never grants a stage');
$error($asRoot('PUT', $scopePath('tyonly'), ['scopes' => ['waiting' => []]]), 400, 'VALIDATION_FAILED', 'an empty scope is refused (remove the stage instead)');
$error($asRoot('PUT', $scopePath('tyonly'), ['scopes' => ['waiting' => ['amazon']]]), 400, 'UNKNOWN_SOURCE', 'unknown sources are refused');
$error($asRoot('PUT', $scopePath('tyonly'), ['scopes' => [['trendyol']]]), 400, 'VALIDATION_FAILED', 'scopes must be keyed by stage');
$error($asRoot('PUT', $scopePath('tyonly'), ['scopes' => ['waiting' => ['trendyol']], 'isRoot' => true]), 400, 'INVALID_REQUEST', 'unknown body fields are refused');
$check((int) $one('SELECT COUNT(*) FROM employee_stage_source_scopes') === 0, 'refused requests write nothing');
$updated = $status($asRoot('PUT', $scopePath('tyonly'), ['scopes' => ['waiting' => ['trendyol']]]), 200, 'root scopes waiting to Trendyol');
$check(($updated['stageSourceScopes']['waiting'] ?? null) === ['trendyol'], 'the management view shows the scope');
$audit = $pdo->prepare("SELECT metadata_json FROM iam_audit_events WHERE action = 'employee.stage_scopes_changed' AND target_id = ? ORDER BY created_at DESC LIMIT 1");
$audit->execute([$uuids['tyonly']]);
$details = json_decode((string) $audit->fetchColumn(), true);
$check(($details['after']['waiting'] ?? null) === ['trendyol'], 'the scope change is audited with before and after');
$status($asRoot('PUT', $scopePath('multi'), ['scopes' => ['waiting' => ['outletperdele', 'trendhome']]]), 200, 'multi-source scope');
$status($asRoot('PUT', $scopePath('mixed'), ['scopes' => ['waiting' => ['b2b']]]), 200, 'mixed: waiting scoped, cutting unscoped');
$status($asRoot('PUT', $scopePath('cutscoped'), ['scopes' => ['material-preparation' => ['outletperdele']]]), 200, 'scoped cutter');
$tyonly = $login('tyonly');
$session = $status($get($tyonly, '/auth/session'), 200, 'scoped session');
$check(($session['employee']['stageSourceScopes']['waiting'] ?? null) === ['trendyol'] && $session['employee']['allowedStageIds'] === ['waiting'], 'the session carries the stage scope');

// ---- Trendyol-only waiting grant: nothing of another source is reachable -------------------------------------------
$waitingQueue = $status($get($tyonly, '/orders/stage-queue', ['stage' => 'waiting']), 200, 'Trendyol-only waiting queue');
$trendyolWaiting = (int) $one("SELECT COUNT(*) FROM operational_orders WHERE production_stage_id = 'waiting' AND source_key = 'trendyol' AND production_completed_at IS NULL AND operational_status <> 'unavailable'");
$check(array_diff($queueSources($waitingQueue), ['trendyol']) === [] && $waitingQueue['counts']['total'] === $trendyolWaiting, 'the queue and its total count only Trendyol orders');
$summary = $status($get($tyonly, '/orders/stage-summary'), 200, 'Trendyol-only summary');
$check($summary['stages'] === [['stageId' => 'waiting', 'total' => $trendyolWaiting, 'mine' => 0]], 'the summary counts no other source');
$denied = [];
foreach (['SW1', 'OW1', 'BW1'] as $tag) {
    $path = '/orders/' . rawurlencode($orders[$tag]);
    $versionBefore = $version($orders[$tag]);
    $error($get($tyonly, $path), 404, 'ORDER_NOT_FOUND', "detail of {$tag} is not found");
    $error($send($tyonly, 'POST', "{$path}/claim", ['expectedVersion' => $versionBefore]), 404, 'ORDER_NOT_FOUND', "claim of {$tag} is refused as not found");
    $error($send($tyonly, 'POST', "{$path}/transition", ['expectedVersion' => $versionBefore]), 404, 'ORDER_NOT_FOUND', "transition of {$tag} is refused as not found");
    $error($send($tyonly, 'POST', '/orders/resolve-qr', ['token' => $qr($orders[$tag])]), 404, 'ORDER_NOT_FOUND', "the QR of {$tag} reveals nothing");
    $error($get($tyonly, '/production-documents/orders/' . rawurlencode($orders[$tag])), 404, 'ORDER_NOT_FOUND', "the document of {$tag} is not reachable (Trendyol document scope only)");
    $check($version($orders[$tag]) === $versionBefore && $stageOf($orders[$tag]) === 'waiting', "{$tag} is unchanged");
    $denied[] = $orders[$tag];
}
$error($get($tyonly, '/orders/lookup', ['code' => "SW1{$suffix}"]), 404, 'ORDER_NOT_FOUND', 'manual lookup of another source is not found');
$responses = json_encode([$get($tyonly, '/orders/' . rawurlencode($orders['SW1']))['body'], $send($tyonly, 'POST', '/orders/resolve-qr', ['token' => $qr($orders['OW1'])])['body']]);
$check(!str_contains($responses, "SW1{$suffix}") && !str_contains($responses, "OW1{$suffix}"), 'denials carry no order number of another source');
$error($get($tyonly, '/orders/stage-queue', ['stage' => 'material-preparation']), 403, 'STAGE_NOT_ALLOWED', 'a scope never opens another stage');
$error($get($tyonly, '/management/employees'), 403, 'APPLICATION_ACCESS_DENIED', 'stage scopes never reach administration');
$check($status($get($tyonly, '/trendyol/overview'), 200, 'Trendyol inbox stays available')['capabilities']['release'] === true, 'the Trendyol role still works next to the scope');
$pdo->prepare('INSERT INTO employee_stage_source_scopes (employee_uuid, stage_id, source_key, granted_at, granted_by_employee_uuid) VALUES (?, ?, ?, UTC_TIMESTAMP(6), ?)')->execute([$uuids['tyonly'], 'packing', 'trendhome', $root['employeeUuid']]);
$tyonly = $login('tyonly');
$check($status($get($tyonly, '/auth/session'), 200, 'session with an orphan scope')['employee']['allowedStageIds'] === ['waiting'], 'a scope row of an ungranted stage grants nothing');
$error($get($tyonly, '/orders/stage-queue', ['stage' => 'packing']), 403, 'STAGE_NOT_ALLOWED', 'an orphan scope opens no queue');
$pdo->prepare("DELETE FROM employee_stage_source_scopes WHERE employee_uuid = ? AND stage_id = 'packing'")->execute([$uuids['tyonly']]);

// ---- Legacy grant: exactly the same access as before any scope was introduced ----------------------------------
$legacyAfter = $reach($legacy) + ['total' => $status($get($legacy, '/orders/stage-queue', ['stage' => 'waiting']), 200, 'legacy queue after')['counts']['total']];
$check($legacyAfter === $legacyBefore, 'the legacy waiting grant reaches the same orders after scopes exist elsewhere');
$legacyClaim = $claimBody($legacy, $orders['SW1'], $version($orders['SW1']));
$claimed = $send($legacy, 'POST', '/orders/' . rawurlencode($orders['SW1']) . '/claim', $legacyClaim, 'legacy-claim-' . $suffix);
$status($claimed, 200, 'legacy claim at waiting');
$replay = $send($legacy, 'POST', '/orders/' . rawurlencode($orders['SW1']) . '/claim', $legacyClaim, 'legacy-claim-' . $suffix);
$check($replay['status'] === 200 && json_encode($replay['body']) === json_encode($claimed['body']), 'an idempotent replay returns the same claim');
$status($send($legacy, 'POST', '/orders/' . rawurlencode($orders['SW1']) . '/transition', ['expectedVersion' => $version($orders['SW1'])]), 200, 'legacy stage-1 hand-off');
$check($stageOf($orders['SW1']) === 'material-preparation', 'the legacy hand-off reaches cutting');

// ---- Multi-source and mixed grants -------------------------------------------------------------------------------
$multi = $login('multi');
$multiQueue = $status($get($multi, '/orders/stage-queue', ['stage' => 'waiting']), 200, 'multi queue');
$check($sees($multi, $orders['SW2']) && $sees($multi, $orders['OW1']) && !$sees($multi, $orders['BW1']) && $multiQueue['counts']['total'] === $openAt('waiting', ['outletperdele', 'trendhome'])
    && array_diff($queueSources($multiQueue), ['outletperdele', 'trendhome']) === [], 'a multi-source grant reaches exactly its sources');
$error($get($multi, '/orders/' . rawurlencode($orders['BW1'])), 404, 'ORDER_NOT_FOUND', 'the multi-source employee does not see B2B');
$status($send($multi, 'POST', '/orders/' . rawurlencode($orders['OW1']) . '/claim', ['expectedVersion' => $version($orders['OW1'])]), 200, 'multi-source claim of OutletPerdele');
$mixed = $login('mixed');
$mixedQueue = $status($get($mixed, '/orders/stage-queue', ['stage' => 'waiting']), 200, 'mixed waiting queue');
$check($queueSources($mixedQueue) === ['b2b'] && $mixedQueue['counts']['total'] === $openAt('waiting', ['b2b']) && $sees($mixed, $orders['BW1']) && !$sees($mixed, $orders['SW2']), 'the scoped waiting grant of a mixed employee reaches only B2B');
$check($sees($mixed, $orders['SM1']) && $sees($mixed, $orders['OM1']) && $status($get($mixed, '/orders/stage-queue', ['stage' => 'material-preparation']), 200, 'mixed cutting queue')['counts']['total'] === $openAt('material-preparation'), 'the unscoped cutting grant of the same employee keeps every source');
$status($send($mixed, 'POST', '/orders/' . rawurlencode($orders['BW1']) . '/claim', ['expectedVersion' => $version($orders['BW1'])]), 200, 'mixed employee claims B2B at waiting');
$error($send($mixed, 'POST', '/orders/' . rawurlencode($orders['SW2']) . '/claim', ['expectedVersion' => $version($orders['SW2'])]), 404, 'ORDER_NOT_FOUND', 'mixed employee cannot claim Trendhome at waiting');

// ---- Cutting: pool, claim with the original QR, transfers ----------------------------------------------------------
$cutScoped = $login('cutscoped');
$pool = $status($get($cutScoped, '/cutting/pool'), 200, 'scoped cutting pool');
$check(array_unique(array_column($pool['items'], 'source')) === ['outletperdele'] && $pool['total'] === (int) $one("SELECT COUNT(*) FROM operational_orders o JOIN order_sources s ON s.source_key = o.source_key WHERE o.production_stage_id = 'material-preparation' AND o.source_key = 'outletperdele' AND o.production_owner_employee_uuid IS NULL AND o.production_completed_at IS NULL AND o.operational_status <> 'unavailable' AND o.source_reported_unavailable_at IS NULL AND o.open_exception_uuid IS NULL AND o.document_status IN ('none', 'active') AND s.status = 'active'"), 'the scoped cutter pool lists and counts only OutletPerdele');
$legacyPool = $status($get($cutLegacy, '/cutting/pool'), 200, 'legacy cutting pool');
$check(in_array('trendhome', array_column($legacyPool['items'], 'source'), true), 'the legacy cutter pool keeps every source');
$error($send($cutScoped, 'POST', '/orders/' . rawurlencode($orders['SM1']) . '/claim', $claimBody($cutScoped, $orders['SM1'], $version($orders['SM1']))), 404, 'ORDER_NOT_FOUND', 'a scoped cutter cannot claim Trendhome even with its QR');
$status($send($cutScoped, 'POST', '/orders/' . rawurlencode($orders['OM1']) . '/claim', $claimBody($cutScoped, $orders['OM1'], $version($orders['OM1']))), 200, 'a scoped cutter claims OutletPerdele with the QR');
$status($send($cutLegacy, 'POST', '/orders/' . rawurlencode($orders['SM1']) . '/claim', $claimBody($cutLegacy, $orders['SM1'], $version($orders['SM1']))), 200, 'legacy cutter claims Trendhome');
$error($send($cutLegacy, 'POST', '/cutting/orders/' . rawurlencode($orders['SM1']) . '/transfers', ['expectedVersion' => $version($orders['SM1']), 'targetId' => $uuids['cutscoped'], 'reasonKey' => 'illness']), 422, 'INELIGIBLE_TRANSFER_TARGET', 'a Trendhome order cannot be transferred to an OutletPerdele-only cutter');

// ---- Management ownership follows the same rule ---------------------------------------------------------------------
$owners = $status($get($root, '/management/orders/' . rawurlencode($orders['SM1']) . '/eligible-owners'), 200, 'eligible owners of Trendhome cutting');
$ownerIds = array_column($owners['items'], 'id');
$check(!in_array($uuids['cutscoped'], $ownerIds, true) && in_array($uuids['mixed'], $ownerIds, true), 'a scoped cutter is not offered for another source, an unscoped one is');
$error($asRoot('PUT', '/management/orders/' . rawurlencode($orders['SM1']) . '/owner', ['employeeId' => $uuids['cutscoped'], 'expectedVersion' => $version($orders['SM1'])]), 422, 'EMPLOYEE_NOT_ELIGIBLE_FOR_STAGE', 'root cannot assign another source to a scoped cutter');
$omOwners = array_column($status($get($root, '/management/orders/' . rawurlencode($orders['SW2']) . '/eligible-owners'), 200, 'eligible owners at waiting')['items'], 'id');
$check(in_array($uuids['legacy'], $omOwners, true) && in_array($uuids['multi'], $omOwners, true) && !in_array($uuids['tyonly'], $omOwners, true) && !in_array($uuids['mixed'], $omOwners, true), 'waiting owners for Trendhome: legacy and multi, never Trendyol-only or B2B-only');

// ---- Revocation, re-grant and inactivity ----------------------------------------------------------------------------
$status($asRoot('PUT', $scopePath('multi'), ['scopes' => ['waiting' => ['trendhome']]]), 200, 'narrow multi to Trendhome');
$multi = $login('multi');
$narrowed = $status($get($multi, '/orders/stage-queue', ['stage' => 'waiting']), 200, 'narrowed queue');
$check($queueSources($narrowed) === ['trendhome'] && $narrowed['counts']['total'] === $openAt('waiting', ['trendhome']) && !$sees($multi, $orders['BW1']), 'a narrowed scope drops the source from the queue at once');
$blocked = $send($multi, 'POST', '/orders/' . rawurlencode($orders['OW1']) . '/transition', ['expectedVersion' => $version($orders['OW1'])]);
$check($blocked['status'] === 403 && $stageOf($orders['OW1']) === 'waiting', 'an order claimed before the narrowing can no longer be advanced (' . $blocked['status'] . ')');
$status($asRoot('PUT', "/management/employees/{$uuids['tyonly']}/stages", ['stageIds' => []]), 200, 'remove the waiting stage of the Trendyol-only employee');
$check((int) $one('SELECT COUNT(*) FROM employee_stage_source_scopes WHERE employee_uuid = ?', [$uuids['tyonly']]) === 0, 'removing a stage removes its scope');
$audit->execute([$uuids['tyonly']]);
$removedAudit = $pdo->prepare("SELECT metadata_json FROM iam_audit_events WHERE action = 'employee.stages_changed' AND target_id = ? ORDER BY created_at DESC LIMIT 1");
$removedAudit->execute([$uuids['tyonly']]);
$check((json_decode((string) $removedAudit->fetchColumn(), true)['stageScopesRemoved']['waiting'] ?? null) === ['trendyol'], 'the removed scope is in the stage audit');
$tyonly = $login('tyonly');
$error($get($tyonly, '/orders/stage-queue', ['stage' => 'waiting']), 403, 'STAGE_NOT_ALLOWED', 'without the stage nothing remains');
$status($asRoot('PUT', "/management/employees/{$uuids['mixed']}/stages", ['stageIds' => ['waiting', 'material-preparation', 'packing']]), 200, 'add a stage to the mixed employee');
$check($one("SELECT GROUP_CONCAT(source_key) FROM employee_stage_source_scopes WHERE employee_uuid = ? AND stage_id = 'waiting'", [$uuids['mixed']]) === 'b2b', 'changing other stages keeps an existing scope');
$pdo->prepare("UPDATE employees SET status = 'inactive' WHERE employee_uuid = ?")->execute([$uuids['cutscoped']]);
$error($get($cutScoped, '/orders/stage-queue', ['stage' => 'material-preparation']), 401, 'ACCOUNT_INACTIVE', 'an inactive scoped employee cannot act');

// ---- Leave the shared disposable database clean for later suites -----------------------------------------------------
$pdo->exec('DELETE FROM employee_stage_source_scopes');

fwrite(STDOUT, "PASS {$checks} stage source scope checks\n");
