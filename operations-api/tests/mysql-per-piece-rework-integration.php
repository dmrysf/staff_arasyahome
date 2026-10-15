<?php

declare(strict_types=1);

// Per-piece rework (migration 028, API 2.31.0) against a dedicated MySQL/MariaDB test database, end to end through
// the HTTP kernel. The primary scenario is a synthetic replica of the business shape of Trendyol order 11709027615
// (EX-000001): one line "Draperie de Textura Nisip Turcesc Ivory", code 12754-21, Crem, 250 x 260 cm, no source
// meters, quantity 2. Piece A is defective at ironing (stage 8), piece B is healthy. Only piece A goes through the
// supervisor, the cutter's physical receipt, one manager approval and the full replay 2 -> 3 -> 4 -> 5 -> 6 -> 7 -> 8,
// with a new versioned event at every stage; piece B keeps its stage and history; the order cannot be packed until A
// rejoins it at packing. Further scenarios: five products with one or two faulty pieces, five identical pieces with
// two faulty, rejection and deliberate withdrawal, dismissal, repeat rework of the same piece, wrong labels, stale
// versions, duplicate submissions, concurrent claims and decisions, inactive receiver, missing permission,
// disabled flag (controlled activation and rollback), migration re-run, and concurrent orders of three sources.

use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Application\Container;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Production\WorkUnits;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL per-piece rework integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
const DASHBOARD = 'http://127.0.0.1:4174';
$config = T::config($dbName, [T::ORIGIN, DASHBOARD]);
$pdo = Connection::create($config);
$migrations = dirname(__DIR__) . '/database/migrations';
(new MigrationRunner($pdo))->migrate($migrations);
$seeds = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seeds, SORT_STRING);
foreach ($seeds as $seed) {
    (new SqlFileRunner($pdo))->run($seed);
}
$pdo->exec('DELETE FROM system_root_identity');

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, $label . ' HTTP ' . $r['status'] . ' ' . json_encode($r['body'])); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$rows = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$suffix = bin2hex(random_bytes(4));
$password = 'per piece rework passphrase 2026';
$rootName = "piece.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-piece-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Piece root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Piece root passphrase 2026!');
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string { $keys++; return sprintf('pc-%s-%s-%04d', $label, $suffix, $keys); };
$dash = static fn (array $who, string $method, string $path, ?array $body = null, ?string $idem = null): array => T::call($kernel, $method, $path, $body, ['origin' => DASHBOARD, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'pc-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$dashGet = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => DASHBOARD], $who['cookie'], $query);
$post = static fn (array $who, string $path, array $body, ?string $idem = null): array => T::call($kernel, 'POST', $path, $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'pc-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $query);
$roleIds = [];
foreach ($ok($dashGet($root, '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];

// ---- Identities (display names as in the incident; identities are this test's own UUIDs) -----------------------
$tailoringStages = ['workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control'];
$uuids = [];
$identity = static function (string $name, string $display, array $stages, array $apps = ['staff'], array $roles = [], string $department = 'pregatire-material') use ($container, $dash, $root, $ok, $password, $suffix, $kernel, &$uuids): array {
    $employee = $container->employeeAdmin()->create($display, "{$name}.{$suffix}", null, $department, 'employee', $password, $stages, 'test-piece');
    $uuids[$name] = $employee->employeeUuid;
    $ok($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]), "{$name} applications");
    if ($roles !== []) $ok($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['employeeUuid' => $employee->employeeUuid, 'name' => $display];
};
$murat = $identity('murat', 'REJEPOV AYMYRAT', ['material-preparation']);
$cutter2 = $identity('cutter2', 'Test Cutter Two', ['material-preparation']);
$kadyrova = $identity('kadyrova', 'KADYROVA ZULFIYA', $tailoringStages);
$tailor2 = $identity('tailor2', 'Test Tailor Two', $tailoringStages);
$tailor3 = $identity('tailor3', 'Test Tailor Three', $tailoringStages);
$labeler = $identity('labeler', 'Test Labeler', ['labeling']);
$finisher = $identity('finisher', 'Test Finisher', ['height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery']);
$iuliana = $identity('iuliana', 'VASILOAIA IULIANA', $tailoringStages);
$denisa = $identity('denisa', 'VOICAN DENISA NICOLETA', [], ['dashboard'], [$roleIds['operations-manager']]);
$hikmet = $identity('hikmet', 'Test Second Manager', [], ['dashboard'], [$roleIds['operations-manager']]);
$viewer = $identity('viewer', 'Test Dashboard Without Approval', [], ['dashboard']);
$ceo = $identity('ceo', 'Test CEO', [], ['dashboard'], [$roleIds['ceo']]);
$ok($dash($root, 'PUT', '/management/organization/ceo', ['employeeId' => $uuids['ceo']]), 'root designates the CEO');
$ok($dash($ceo, 'POST', '/management/organization/responsibilities', ['responsibility' => 'tailoring_quality_supervisor', 'employeeId' => $uuids['iuliana']]), 'the CEO appoints the supervisor', 201);

// ---- Orders ----------------------------------------------------------------------------------------------------
// A Trendyol order exactly as the approval of a prepared package creates it (same writer, same transaction rule).
$trendyol = static function (string $tag, array $lines) use ($container, $pdo, $one, $rows, $suffix): array {
    $number = "117{$tag}{$suffix}";
    $items = [];
    foreach ($lines as $i => $line) {
        $items[] = new OperationalOrderItem(Uuid::v4(), "line-{$tag}-{$i}", $i + 1, $line['name'], $line['code'], null, $line['color'], $line['width'], $line['height'], 'cm', $line['meters'], $line['quantity'],
            ['kind' => 'drapery', 'notes' => null, 'productionNotes' => null, 'marketplaceTitle' => $line['name'], 'barcode' => null]);
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $pdo->beginTransaction();
    $uuid = $container->projectionWriter()->createInternal(new SourceOrderSnapshot('trendyol', "pkg-{$tag}-{$suffix}", "approval-{$tag}-{$suffix}", 1, $now, $number, 'waiting', 'Created', 'Created', null, 'in_progress', $now, $items), []);
    $pdo->commit();
    $id = (string) $one('SELECT global_order_id FROM operational_orders WHERE order_uuid = ?', [$uuid]);
    return ['id' => $id, 'uuid' => $uuid, 'number' => $number, 'path' => '/orders/' . rawurlencode($id), 'source' => 'trendyol',
        'qr' => 'ARASYA:Q1:' . (string) $one("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$uuid]),
        'items' => array_column($rows('SELECT item_uuid FROM operational_order_items WHERE order_uuid = ? ORDER BY line_number', [$uuid]), 'item_uuid')];
};
$signed = static function (string $tag, string $source, array $lines) use ($suffix, $kernel, $ok, $one, $rows): array {
    $number = "PC{$tag}{$suffix}";
    $payloadLines = [];
    foreach ($lines as $i => $line) {
        $payloadLines[] = ['id' => $i + 1, 'line' => $i + 1, 'name' => $line['name'], 'sku' => $line['code'], 'color' => $line['color'], 'width' => $line['width'], 'height' => $line['height'], 'unit' => 'cm', 'meters' => $line['meters'], 'quantity' => $line['quantity']];
    }
    $ok(T::ingest($kernel, $source, T::sourceOrder($number, "evt-pc-{$tag}-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 300), T::stage('material-preparation'), 'processing', 'active', $payloadLines, $number)), "ingest {$tag}");
    $id = "{$source}:{$number}";
    $uuid = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$id]);
    return ['id' => $id, 'uuid' => $uuid, 'number' => $number, 'path' => '/orders/' . rawurlencode($id), 'source' => $source,
        'qr' => 'ARASYA:Q1:' . (string) $one("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$uuid]),
        'items' => array_column($rows('SELECT item_uuid FROM operational_order_items WHERE order_uuid = ? ORDER BY line_number', [$uuid]), 'item_uuid')];
};
$line = static fn (string $name, string $code, int $quantity, ?float $meters = null, string $color = 'Crem'): array => ['name' => $name, 'code' => $code, 'color' => $color, 'width' => 250.0, 'height' => 260.0, 'meters' => $meters, 'quantity' => $quantity];

$state = static fn (array $o): array => $rows('SELECT production_stage_id AS stage, production_owner_employee_uuid AS owner, production_version AS version, open_exception_uuid AS exception, production_completed_at AS completed FROM operational_orders WHERE order_uuid = ?', [$o['uuid']])[0];
$track = static fn (string $trackUuid): array => $rows('SELECT * FROM production_tracks WHERE track_uuid = ?', [$trackUuid])[0];
$unitsOf = static fn (array $o): array => $rows("SELECT unit_uuid, item_uuid, unit_index, track_uuid, rework_cycle FROM production_work_units WHERE order_uuid = ? AND status = 'active' ORDER BY item_uuid, unit_index", [$o['uuid']]);
$claim = static function (array $who, array $o) use ($post, $ok, $state, $pdo, $key): void {
    $ok($post($who, $o['path'] . '/claim', T::cuttingClaim($pdo, $o['id'], $who['employeeUuid'] ?? '', (int) $state($o)['version']), $key('claim')), "claim {$o['id']}");
};
$advance = static function (array $who, array $o) use ($post, $ok, $state, $key): void {
    $ok($post($who, $o['path'] . '/transition', ['expectedVersion' => (int) $state($o)['version']], $key('move')), "transition {$o['id']}");
};
// Trendyol: the cutter receives the order by scanning its QR (stage 1 -> cutting + claim); signed sources start in cutting.
$cut = static function (array $cutter, array $o) use ($post, $ok, $claim, $advance, $state, $key): void {
    if ($state($o)['stage'] === 'waiting') {
        $ok($post($cutter, '/cutting/intake', ['qrToken' => $o['qr']], $key('intake')), "cutting intake {$o['id']}");
    } else {
        $claim($cutter, $o);
    }
    $advance($cutter, $o);
};
$bring = static function (array $o, array $cutter, string $target, array $by) use ($cut, $claim, $advance, $state, $check): void {
    $cut($cutter, $o);
    while (true) {
        $claim($by, $o);
        if ($state($o)['stage'] === $target) break;
        $advance($by, $o);
    }
    $check($state($o)['stage'] === $target, "order at {$target}");
};
$exVersion = static fn (string $id): int => (int) $one('SELECT version FROM production_exceptions WHERE exception_uuid = ?', [$id]);
$trackVersion = static fn (string $id): int => (int) $one('SELECT version FROM production_tracks WHERE track_uuid = ?', [$id]);
$reportUnits = static fn (array $who, array $o, array $units, string $reason, ?string $comment = null, ?string $trackId = null, ?string $idem = null, ?int $version = null) => $post($who, $o['path'] . '/fault-reports',
    array_filter(['expectedVersion' => $version ?? ($trackId === null ? (int) $state($o)['version'] : $trackVersion($trackId)), 'units' => $units, 'trackId' => $trackId, 'reasonKey' => $reason, 'comment' => $comment], static fn ($v) => $v !== null), $idem);
$review = static fn (array $who, string $id, array $body, ?string $idem = null) => $post($who, "/production-exceptions/{$id}/review", ['expectedVersion' => $exVersion($id)] + $body, $idem);
$sendReturn = static fn (array $who, string $id, array $body = [], ?string $idem = null) => $post($who, "/production-exceptions/{$id}/return", ['expectedVersion' => $exVersion($id)] + $body, $idem);
$ack = static fn (array $who, string $id, string $qr, array $body = [], ?string $idem = null) => $post($who, "/production-exceptions/{$id}/acknowledge", ['expectedVersion' => $exVersion($id), 'confirmed' => true, 'qrToken' => $qr] + $body, $idem);
$decide = static fn (array $who, string $id, array $body, ?string $idem = null) => $dash($who, 'POST', "/management/production-exceptions/{$id}/decision", ['expectedVersion' => $exVersion($id)] + $body, $idem);
$restore = static fn (array $who, string $id, string $qr, string $comment, array $extra = [], ?string $idem = null) => $post($who, "/production-exceptions/{$id}/restore", ['expectedVersion' => $exVersion($id), 'qrToken' => $qr, 'comment' => $comment] + $extra, $idem);
$receive = static fn (array $who, string $id, string $qr, ?string $idem = null) => $post($who, "/production-exceptions/{$id}/receive", ['expectedVersion' => $exVersion($id), 'confirmed' => true, 'qrToken' => $qr], $idem);
$trackClaim = static fn (array $who, string $trackId, string $qr, ?string $idem = null, ?int $version = null) => $post($who, "/production-tracks/{$trackId}/claim", ['expectedVersion' => $version ?? $trackVersion($trackId), 'qrToken' => $qr], $idem);
$trackComplete = static fn (array $who, string $trackId, array $extra = [], ?string $idem = null, ?int $version = null) => $post($who, "/production-tracks/{$trackId}/complete", ['expectedVersion' => $version ?? $trackVersion($trackId)] + $extra, $idem);
$labels = static fn (array $who, string $trackId): array => $ok($get($who, "/production-tracks/{$trackId}/labels"), 'piece labels');
$trackEvents = static fn (string $trackId): array => $rows('SELECT action, employee_uuid, from_stage_id, to_stage_id, version_before, version_after, rework_cycle, occurred_at, metadata_json FROM production_track_events WHERE track_uuid = ? ORDER BY version_after', [$trackId]);
$race = static function (array $calls) use ($dbName): array {
    $startAt = (string) (microtime(true) + 0.4);
    $workers = [];
    foreach ($calls as [$who, $path, $body, $idem, $origin]) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/b2b-order-worker.php', $dbName, 'POST', $path, json_encode($body, JSON_THROW_ON_ERROR), $idem, $who['cookie'], $who['csrf'], $startAt, ...($origin === null ? [] : [$origin])], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $results[] = json_decode((string) $out, true) ?? ['status' => 0, 'error' => $out];
    }
    return $results;
};
$cursor = static function (array $who) use ($get): int {
    preg_match('/"cursor":(\d+)/', (string) $get($who, '/live/events')['raw'], $m);
    return (int) ($m[1] ?? 0);
};
$liveTypes = static function (array $who, int $after) use ($get): array {
    preg_match_all('/^event: ([a-z._]+)$/m', (string) $get($who, '/live/events', ['after' => (string) $after])['raw'], $m);
    return array_values(array_filter($m[1], static fn (string $t): bool => $t !== 'cursor'));
};
$piecesOf = static fn (array $who, array $o): array => $ok($get($who, $o['path']), 'order view')['pieces'];
// The full replay of one track from its current stage to $target, one canonical stage at a time, by $by (or per stage).
$replay = static function (string $trackId, string $target, callable $by, string $qr, array $extraAt = []) use ($trackClaim, $trackComplete, $track, $ok, $check): array {
    $done = [];
    while (($stage = $track($trackId)['stage_id']) !== $target) {
        $who = $by($stage);
        $ok($trackClaim($who, $trackId, $qr), "claim piece at {$stage}");
        $ok($trackComplete($who, $trackId, $extraAt[$stage] ?? []), "complete piece at {$stage}");
        $done[] = $stage;
        $check(count($done) < 20, 'the replay terminates');
    }
    return $done;
};
// API 2.32.0: the commercial quantity is not the number of physical pieces. Every order whose pieces are worked on
// individually first gets its manufacturing plan confirmed by an operations manager; here each line is confirmed as
// "quantity" equal pieces (a stated, audited fact), so the per-piece scenarios keep the business shape they test.
$confirmPlan = static function (array $o, ?string $type = 'draperii') use ($dash, $dashGet, $denisa, $ok): array {
    $plan = $ok($dashGet($denisa, $o['path'] . '/manufacturing-plan'), 'plan view');
    foreach ($plan['lines'] as $planLine) {
        $body = ['expectedSourceHash' => $planLine['sourcePlanHash'], 'expectedRevision' => $planLine['plan']['revision'], 'productType' => $type, 'pieceCount' => $planLine['commercialQuantity'], 'note' => 'Plan verificat: bucăți egale, câte una pe unitate comercială'];
        if ($planLine['lineMeters'] !== null) {
            $body['equalSplit'] = true;
        }
        $plan = $ok($dash($denisa, 'POST', $o['path'] . '/manufacturing-plan/lines/' . $planLine['itemId'] . '/confirm', $body), 'plan confirmed');
    }
    return $plan;
};
$stageOrder = ['waiting', 'material-preparation', 'workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery'];

// ==== Migration: additive and re-runnable ========================================================================
$tables = (int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
(new SqlFileRunner($pdo))->run($migrations . '/028_production_work_units.sql');
$check((int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()") === $tables, 'migration 028 re-runs without error and creates nothing twice');
$check((new MigrationRunner($pdo))->migrate($migrations) === [], 'the migration runner sees a current schema');
$check((int) $one("SELECT enabled FROM production_feature_flags WHERE flag_key = 'partial_rework'") === 0, 'per-piece reports start disabled');

// ==== Scenario 1: the two curtains of Trendyol order 11709027615 ================================================
$o = $trendyol('A', [$line('Draperie de Textura Nisip Turcesc Ivory', '12754-21', 2)]);
$check($o['source'] === 'trendyol' && count($o['items']) === 1, 'one Trendyol commercial order with one line');
$confirmPlan($o);
$bring($o, $murat, 'ironing', $kadyrova);
$check($state($o)['owner'] === $uuids['kadyrova'], 'KADYROVA ZULFIYA owns the order at ironing (stage 8)');
$pieces = $piecesOf($kadyrova, $o);
$check($pieces['total'] === 2 && $pieces['onOrder'] === 2 && $pieces['mixedProgress'] === false && $pieces['tracks'] === [], '1. two physical pieces, both with the order: ' . json_encode($pieces));
[$pieceA, $pieceB] = [$pieces['units'][0], $pieces['units'][1]];
$check($pieceA['id'] !== $pieceB['id'] && $pieceA['index'] === 1 && $pieceB['index'] === 2 && $pieceA['count'] === 2 && $pieceA['itemId'] === $pieceB['itemId'], '1. Bucata 1/2 and Bucata 2/2 are distinct identities of the same line');
$check($pieceA['id'] === WorkUnits::unitUuid($o['items'][0], 1), '1. the piece identity is derived from (line, index) and stable before any write');
$check((int) $one("SELECT COUNT(*) FROM production_work_units WHERE order_uuid = ? AND status = 'active'", [$o['uuid']]) === 2, 'the two pieces exist from the confirmation of the plan, with the derived identities');

// Controlled activation: a partial report is refused while the flag is off; the whole carrier is always allowed.
$error($reportUnits($kadyrova, $o, [$pieceA['id']], 'material-defect'), 409, 'PARTIAL_REWORK_DISABLED', 'one piece of two is refused until per-piece rework is enabled');
$check($state($o)['exception'] === null && (int) $one('SELECT COUNT(*) FROM production_exceptions WHERE order_uuid = ?', [$o['uuid']]) === 0, 'the refused report changed nothing');
$pdo->beginTransaction();
$check((new WorkUnits($pdo))->setPartialRework(true, 'test activation', gmdate('Y-m-d H:i:s.000000'))['changed'] === true, 'operator enables per-piece rework');
$pdo->commit();
$check($ok($get($kadyrova, '/production-exceptions/capabilities'), 'caps')['partialRework'] === true, 'Staff sees the capability');

// 3/4. Kadyrova reports exactly piece A.
$error($reportUnits($tailor2, $o, [$pieceA['id']], 'material-defect'), 403, 'FAULT_REPORT_NOT_ALLOWED', 'only the owner at the stage reports');
$error($reportUnits($kadyrova, $o, [$pieceA['id'], $pieceA['id']], 'material-defect'), 400, 'INVALID_REQUEST', 'a piece is selected once');
$error($reportUnits($kadyrova, $o, [WorkUnits::unitUuid($o['items'][0], 3)], 'material-defect'), 422, 'UNITS_INVALID', 'a piece that does not exist fails closed');
$reportKey = $key('report');
$beforeReport = $state($o);
$reported = $ok($reportUnits($kadyrova, $o, [$pieceA['id']], 'material-defect', 'Fir tras pe o singură bucată', null, $reportKey), '3. the stage-8 worker reports piece A', 201);
$x1 = $reported['id'];
$check($ok($reportUnits($kadyrova, $o, [$pieceA['id']], 'material-defect', 'Fir tras pe o singură bucată', null, $reportKey, (int) $beforeReport['version']), 'replay', 201) === $reported, 'a lost report answer is replayed, never duplicated');
$scope = $reported['orderScope'];
$check($scope['partial'] === true && $scope['affectedQuantity'] === 1 && $scope['movingQuantity'] === 1 && $scope['unaffectedQuantity'] === 1 && $scope['wholeOrderMoves'] === false && $scope['carrier'] === 'track', '4. exactly one piece is in the scope: ' . json_encode($scope));
$check(count($reported['affectedPieces']) === 1 && $reported['affectedPieces'][0]['unitId'] === $pieceA['id'] && $reported['affectedPieces'][0]['piece'] === '1/2' && $reported['affectedPieces'][0]['code'] === '12754-21', '4. the request names Bucata 1/2 of 12754-21');
$check($reported['faultMeters'] === null && $reported['unmeasuredLineCount'] === 1, 'a line without source meters is reported with unknown meters, never 0 m');
$check($reported['lines'][0]['quantity'] === 2 && $reported['lines'][0]['affectedQuantity'] === 1, 'the line keeps its commercial quantity 2; 1 of 2 is affected');
$t1 = $scope['trackId'];
$tr = $track($t1);
$check($tr['status'] === 'active' && $tr['stage_id'] === 'ironing' && $tr['owner_employee_uuid'] === $uuids['kadyrova'] && $tr['open_exception_uuid'] === $x1 && (int) $tr['rework_cycle'] === 1, 'piece A is on its own track at ironing, blocked by the report');
$after = $state($o);
$check($after['stage'] === 'ironing' && $after['owner'] === $uuids['kadyrova'] && $after['exception'] === null && (int) $after['version'] === (int) $beforeReport['version'], '7. piece B stays at ironing with KADYROVA, unblocked; the order version is unchanged');
$units = $unitsOf($o);
$check(count($units) === 2 && count(array_filter($units, static fn ($u) => $u['track_uuid'] === $t1)) === 1 && count(array_filter($units, static fn ($u) => $u['track_uuid'] === null)) === 1, 'exactly one piece moved to the track');
$pieces = $piecesOf($kadyrova, $o);
$check($pieces['mixedProgress'] === true && $pieces['onOrder'] === 1 && $pieces['inRework'] === 1 && $pieces['tracks'][0]['openException']['id'] === $x1, 'the order shows mixed progress explicitly');

// 5/6. The supervisor verifies and takes custody of piece A only.
$cursorMurat = $cursor($murat);
$cursorDenisa = $cursor($denisa);
$reviewed = $ok($review($iuliana, $x1, ['decision' => 'accept', 'reasonKey' => 'material-defect', 'qrToken' => $o['qr']]), '5. the supervisor accepts with the order QR');
$check($reviewed['status'] === 'in_supervisor_custody' && $reviewed['currentStage']['id'] === 'workshop-receiving', '6. the case shows the piece at stage 3');
$tr = $track($t1);
$check($tr['stage_id'] === 'workshop-receiving' && $tr['owner_employee_uuid'] === null && $tr['open_exception_uuid'] === $x1, '6. piece A is in supervised custody at stage 3');
$check($state($o)['stage'] === 'ironing' && $state($o)['owner'] === $uuids['kadyrova'], '7. piece B is still at ironing with its owner');
$printed = $labels($iuliana, $t1);
$check(count($printed['labels']) === 1 && $printed['labels'][0]['unitId'] === $pieceA['id'] && $printed['labels'][0]['piece'] === '1/2' && str_starts_with((string) $printed['labels'][0]['payload'], 'ARASYA:U1:') && str_contains((string) $printed['labels'][0]['svg'], '<svg'), 'the supervisor prints the label of Bucata 1/2');
$labelA = (string) $printed['labels'][0]['payload'];

// 8. Return to cutting, addressed to the responsible cutter; an accidental withdrawal is refused.
$returned = $ok($sendReturn($iuliana, $x1, ['comment' => 'Se retaie doar bucata 1/2']), '8. the supervisor sends piece A to cutting');
$check($returned['status'] === 'awaiting_acknowledgment' && $returned['receiver']['displayName'] === 'REJEPOV AYMYRAT', '8. the return is addressed to REJEPOV AYMYRAT');
$error($restore($iuliana, $x1, $o['qr'], 'Predau înapoi'), 422, 'RETURN_CANCEL_CONFIRMATION_REQUIRED', 'a pending return is never withdrawn without an explicit confirmation');
$check(in_array('exception.acknowledgment_required', $liveTypes($murat, $cursorMurat), true), 'the cutter is notified to receive the piece');

// 9. Murat scans the label of the piece in his hand.
$scanned = $ok($post($murat, '/orders/resolve-qr', ['token' => $labelA]), '9. the cutter scans the piece label');
$check(($scanned['scannedPiece']['unitId'] ?? null) === $pieceA['id'] && $scanned['scannedPiece']['trackId'] === $t1, '9. the scan identifies exactly piece A and its track');
$scannedTrack = array_values(array_filter($scanned['pieces']['tracks'], static fn ($t) => $t['id'] === $t1))[0];
$check($scannedTrack['openException']['id'] === $x1 && $scannedTrack['stage']['id'] === 'workshop-receiving', '9. the scan shows the pending return of this piece');
$check(($scannedTrack['openException']['actions']['canAcknowledge'] ?? null) === true && $scannedTrack['openException']['orderScope']['affectedQuantity'] === 1, '9. the scan tells the addressed cutter to confirm the receipt of exactly this piece');
$otherScan = $ok($post($tailor2, '/orders/resolve-qr', ['token' => $labelA]), 'another employee scans the label');
$otherTrack = array_values(array_filter($otherScan['pieces']['tracks'], static fn ($t) => $t['id'] === $t1))[0];
$check(($otherTrack['openException']['actions']['canAcknowledge'] ?? false) === false, 'an uninvolved employee is not offered the receipt');
$detail = $ok($get($murat, "/production-exceptions/{$x1}"), 'the cutter opens the request');
$check($detail['actions']['canAcknowledge'] === true && $detail['orderScope']['affectedQuantity'] === 1 && $detail['affectedPieces'][0]['piece'] === '1/2', 'the request shows 1 piece and allows the receipt');
$check($detail['supervisor']['displayName'] === 'VASILOAIA IULIANA' && $detail['reason']['key'] === 'material-defect', 'the request shows supervisor and reason');
$scanOrder = $ok($post($murat, '/orders/resolve-qr', ['token' => $o['qr']]), 'scan of the order QR');
$check(!isset($scanOrder['scannedPiece']) && count($scanOrder['pieces']['tracks']) === 1, 'the order QR alone names no piece: the client must let the employee choose (fail closed)');
$check((int) $one('SELECT COUNT(*) FROM production_exception_decisions WHERE exception_uuid = ?', [$x1]) === 0, 'no manager decision before the physical receipt');

// 10. Explicit physical receipt; the scan alone never acknowledged anything.
$error($post($murat, "/production-exceptions/{$x1}/acknowledge", ['expectedVersion' => $exVersion($x1), 'confirmed' => false, 'qrToken' => $labelA, 'responsibility' => 'disputed', 'comment' => 'Defect de material'], $key('noconfirm')), 400, 'CONFIRMATION_REQUIRED', 'a scan without the explicit confirmation is refused');
$error($post($murat, "/production-exceptions/{$x1}/acknowledge", ['expectedVersion' => $exVersion($x1) - 1, 'confirmed' => true, 'qrToken' => $labelA, 'responsibility' => 'disputed', 'comment' => 'Defect de material'], $key('stale')), 409, 'EXCEPTION_CHANGED', 'a stale request version is refused');
$ackKey = $key('ack');
$acked = $ok($ack($murat, $x1, $labelA, ['responsibility' => 'disputed', 'comment' => 'Defect de material, nu de tăiere'], $ackKey), '10. the cutter confirms the physical receipt with the piece label');
$check($acked['status'] === 'awaiting_approval' && $acked['responsibilityPosition'] === 'disputed', '10. a disputed responsibility does not prevent the receipt');
$check($ok($post($murat, "/production-exceptions/{$x1}/acknowledge", ['expectedVersion' => 3, 'confirmed' => true, 'qrToken' => $labelA, 'responsibility' => 'disputed', 'comment' => 'Defect de material, nu de tăiere'], $ackKey), 'ack replay') === $acked, 'a duplicate scanner submission replays the committed receipt');
// 11. Exactly one pending decision, visible to the managers after a plain refresh.
$check((int) $one("SELECT COUNT(*) FROM production_exception_decisions WHERE exception_uuid = ? AND status = 'pending'", [$x1]) === 1, '11. exactly one pending manager decision');
$check(in_array('exception.approval_pending', $liveTypes($denisa, $cursorDenisa), true), '11. the approval event reaches the managers');
$pending = $ok($dashGet($denisa, '/management/production-exceptions', ['view' => 'pending']), 'the pending list after a refresh')['items'];
$mine = array_values(array_filter($pending, static fn ($i) => $i['id'] === $x1));
$check(count($mine) === 1 && $mine[0]['orderScope']['affectedQuantity'] === 1 && $mine[0]['orderScope']['wholeOrderMoves'] === false, '11. the manager list shows 1 affected piece, not the whole order');
$managerView = $ok($dashGet($denisa, "/management/production-exceptions/{$x1}"), 'manager detail');
$check($managerView['affectedPieces'][0]['piece'] === '1/2' && $managerView['orderScope']['movingQuantity'] === 1 && $managerView['receiver']['displayName'] === 'REJEPOV AYMYRAT'
    && $managerView['responsible']['displayName'] === 'REJEPOV AYMYRAT' && $managerView['detector']['displayName'] === 'KADYROVA ZULFIYA' && $managerView['supervisor']['displayName'] === 'VASILOAIA IULIANA'
    && $managerView['detectedStage']['id'] === 'ironing' && $managerView['responsibilityPosition'] === 'disputed', '11. the manager sees piece, cutter, receiver, reporter, supervisor, stage and the stated responsibility');
$error($decide($viewer, $x1, ['decision' => 'approve', 'attribution' => 'none']), 403, 'UNAUTHORIZED_ACTION', 'a Dashboard user without the approval permission cannot decide');
$error($decide($denisa, $x1, ['decision' => 'approve', 'attribution' => 'cutter']), 422, 'ATTRIBUTION_NOT_ALLOWED', 'a material defect is never attributed to the cutter');

// 12. Two managers approve at the same moment: the first valid decision wins, the other is refused.
$version = $exVersion($x1);
$results = $race([
    [$denisa, "/management/production-exceptions/{$x1}/decision", ['expectedVersion' => $version, 'decision' => 'approve', 'attribution' => 'none'], $key('race-d'), DASHBOARD],
    [$hikmet, "/management/production-exceptions/{$x1}/decision", ['expectedVersion' => $version, 'decision' => 'approve', 'attribution' => 'none'], $key('race-h'), DASHBOARD],
]);
$statuses = array_column($results, 'status');
sort($statuses);
$check($statuses === [200, 409], '12. exactly one concurrent approval succeeds: ' . json_encode($results));
$check((int) $one("SELECT COUNT(*) FROM production_exception_decisions WHERE exception_uuid = ? AND status = 'approved'", [$x1]) === 1, '12. one approved decision');
$error($decide($denisa, $x1, ['decision' => 'approve', 'attribution' => 'none']), 409, 'EXCEPTION_ALREADY_RESOLVED', 'a second approval is refused');

// 13/14/15. Only piece A is back at cutting, owned by the receiving cutter; piece B is untouched.
$tr = $track($t1);
$check($tr['stage_id'] === 'material-preparation' && $tr['owner_employee_uuid'] === $uuids['murat'] && (int) $tr['rework_cycle'] === 2 && $tr['open_exception_uuid'] === null && $tr['verify_stage_id'] === 'ironing', '13/14. piece A is at cutting (stage 2), owned by REJEPOV AYMYRAT, rework cycle 2');
$check($state($o)['stage'] === 'ironing' && $state($o)['owner'] === $uuids['kadyrova'] && $state($o)['exception'] === null, '15. piece B is still at ironing with KADYROVA');
$cycles = array_column($unitsOf($o), 'rework_cycle', 'unit_uuid');
$check((int) $cycles[$pieceA['id']] === 2 && (int) $cycles[$pieceB['id']] === 1, 'piece A starts cycle 2, piece B stays in cycle 1');
$check(in_array('exception.approved', $liveTypes($murat, $cursorMurat), true), 'the cutter is notified of the approval');
$myList = $ok($get($murat, '/orders/mine'), "the cutter's work list")['items'];
$listed = array_values(array_filter($myList, static fn ($i) => $i['id'] === $o['id']));
$myTrack = $listed === [] ? null : array_values(array_filter($listed[0]['pieces']['tracks'], static fn ($t) => $t['id'] === $t1))[0] ?? null;
$check($myTrack !== null && $myTrack['owner']['isMe'] === true && $myTrack['owner']['displayName'] === 'REJEPOV AYMYRAT' && $myTrack['stage']['id'] === 'material-preparation' && $myTrack['quantity'] === 1 && $myTrack['reworkCycle'] === 2 && ($myTrack['allowedAction']['id'] ?? null) === 'complete_stage',
    '14. the cutter sees the approved return in his list: 1 piece, cutting, owner REJEPOV AYMYRAT');
$check(!isset($listed[0]['employeeAllowedAction']), 'the order itself (piece B) is not his work');
$summary = $ok($get($murat, '/orders/stage-summary'), 'cutting counters')['stages'];
$check($summary[0]['stageId'] === 'material-preparation' && $summary[0]['reworkTotal'] === 1 && $summary[0]['reworkMine'] === 1, 'the cutting counters show the piece in rework: ' . json_encode($summary));
$check((int) $one("SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = ? AND occurred_at >= (SELECT reported_at FROM production_exceptions WHERE exception_uuid = ?)", [$o['uuid'], $x1]) === 0, 'piece A moves wrote nothing into the order (piece B) history');

// 16/17. Murat re-cuts piece A; it arrives at stage 3, unowned — never straight back to ironing.
$cursorKad = $cursor($kadyrova);
$error($trackComplete($murat, $t1, [], null, $trackVersion($t1) - 1), 409, 'TRACK_CHANGED', 'a stale piece version is refused');
$doneKey = $key('recut');
$error($trackComplete($murat, $t1), 422, 'PIECE_OUTCOME_REQUIRED', 'the re-cut must state repair or replacement');
$recut = $ok($trackComplete($murat, $t1, ['pieceOutcome' => 'repair'], $doneKey), '16. the cutter completes stage 2 on piece A');
$check($ok($trackComplete($murat, $t1, ['pieceOutcome' => 'repair'], $doneKey, $trackVersion($t1) - 1), 'complete replay') === $recut, 'a duplicate completion replays the committed result');
$tr = $track($t1);
$check($tr['stage_id'] === 'workshop-receiving' && $tr['owner_employee_uuid'] === null, '17. piece A is at stage 3 without owner');

// 18. Stage 3 claimed explicitly with the label; two employees scanning at once: one wins.
$v3 = $trackVersion($t1);
$results = $race([
    [$tailor2, "/production-tracks/{$t1}/claim", ['expectedVersion' => $v3, 'qrToken' => $labelA], $key('rc-a'), null],
    [$tailor3, "/production-tracks/{$t1}/claim", ['expectedVersion' => $v3, 'qrToken' => $labelA], $key('rc-b'), null],
]);
$statuses = array_column($results, 'status');
sort($statuses);
$check($statuses === [200, 409], '18. two employees scanning the same piece: exactly one claim: ' . json_encode($results));
$owner3 = $track($t1)['owner_employee_uuid'];
$holder = $owner3 === $uuids['tailor2'] ? $tailor2 : $tailor3;
$ok($trackComplete($holder, $t1), '18. stage 3 completed on piece A');
// 19. Labeling: only an employee of that stage; a cutter or a scan of another order fails.
$error($trackClaim($murat, $t1, $labelA), 403, 'UNAUTHORIZED_ACTION', 'a cutter cannot claim the piece at labeling');
$other = $trendyol('B', [$line('Draperie Test', 'X-1', 1)]);
$error($trackClaim($labeler, $t1, $other['qr']), 422, 'QR_ORDER_MISMATCH', 'the QR of another order is refused');
$ok($trackClaim($labeler, $t1, $o['qr']), '19. the labeling employee claims piece A (order QR + the chosen piece)');
$ok($trackComplete($labeler, $t1), '19. labeling completed');
// 20-23. Stages 5, 6, 7 and 8 by an ordinary tailoring employee, each claimed and completed.
$stages = $replay($t1, 'ironing', static fn (string $stage): array => $tailor3, $labelA);
$check($stages === ['material-straightening', 'bottom-hem', 'side-hem'], '20-22. stages 5, 6, 7 completed one by one: ' . json_encode($stages));
$check($track($t1)['stage_id'] === 'ironing', '23. piece A is at ironing again');
// 24/25. Ironing: the defect must be confirmed resolved before the piece moves on.
$ok($trackClaim($tailor2, $t1, $labelA), '24. ironing claimed');
$error($trackComplete($tailor2, $t1), 422, 'DEFECT_RESOLUTION_REQUIRED', '25. the original defect must be confirmed resolved at ironing');
$ok($trackComplete($tailor2, $t1, ['defectResolved' => true]), '25. ironing completed with the defect resolved');
$tr = $track($t1);
$check($tr['stage_id'] === 'height' && $tr['resolution_confirmed_by_employee_uuid'] === $uuids['tailor2'] && $tr['resolution_confirmed_at'] !== null, '25. the resolution is recorded with employee and time');

// 26. Distinct, complete histories: every replay stage is its own versioned event in cycle 2.
$events = $trackEvents($t1);
$completed = array_values(array_filter($events, static fn ($e) => $e['action'] === 'stage_completed'));
$check(array_map(static fn ($e) => $e['from_stage_id'] . '>' . $e['to_stage_id'], $completed) === ['material-preparation>workshop-receiving', 'workshop-receiving>labeling', 'labeling>material-straightening', 'material-straightening>bottom-hem', 'bottom-hem>side-hem', 'side-hem>ironing', 'ironing>height'],
    'D. the replay is exactly 2 > 3 > 4 > 5 > 6 > 7 > 8 > 9, no stage skipped: ' . json_encode(array_column($completed, 'to_stage_id')));
$check(array_unique(array_map(static fn ($e) => (int) $e['rework_cycle'], $completed)) === [2], 'D. every replay completion belongs to rework cycle 2');
$check(count(array_unique(array_column($events, 'version_after'))) === count($events) && $events[0]['action'] === 'created', 'every track event has its own version');
$check($completed[0]['employee_uuid'] === $uuids['murat'] && $completed[2]['employee_uuid'] === $uuids['labeler'] && $completed[6]['employee_uuid'] === $uuids['tailor2'] && json_decode((string) $completed[6]['metadata_json'], true)['defectResolved'] === true, 'D. each completion records its own employee');
$originalCut = (int) $one("SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = ? AND action = 'stage_completed' AND from_stage_id = 'material-preparation'", [$o['uuid']]);
$check($originalCut === 1, 'cycle 1 cutting stays recorded once in the order history; cycle 2 is separate');
// 27/28. Piece B: no extra cutting, no cycle, no exception.
$check((int) $one('SELECT COUNT(*) FROM production_exception_units WHERE unit_uuid = ?', [$pieceB['id']]) === 0 && (int) $cycles[$pieceB['id']] === 1, '28. piece B has no rework and no fault');
$check((int) $one('SELECT COUNT(*) FROM operational_orders WHERE global_order_id = ?', [$o['id']]) === 1, '27. no duplicate commercial order exists');
$check((int) $one('SELECT COALESCE(SUM(quantity), 0) FROM operational_order_items WHERE order_uuid = ?', [$o['uuid']]) === 2, '29. the commercial quantity stays 2');
$check($rows('SELECT event_type, employee_uuid FROM production_quality_events WHERE exception_uuid = ?', [$x1]) === [['event_type' => 'fault_detected', 'employee_uuid' => $uuids['kadyrova']]], 'one detection fact for KADYROVA; no cutting fault (material defect, attribution none)');

// 30. Packing gate: piece B reaches packing first and must wait for piece A.
$ok($post($kadyrova, $o['path'] . '/transition', ['expectedVersion' => (int) $state($o)['version']], $key('b8')), 'piece B: ironing completed');
foreach (['height', 'header-tape', 'sewing-finishing', 'quality-control'] as $stage) {
    $claim($finisher, $o);
    $advance($finisher, $o);
}
$check($state($o)['stage'] === 'packing' && $state($o)['owner'] === null, 'piece B is at packing');
$error($post($finisher, $o['path'] . '/claim', ['expectedVersion' => (int) $state($o)['version']], $key('pack-early')), 409, 'ORDER_PIECES_IN_REWORK', '30. the order cannot be packed while piece A is in rework');
$view = $ok($get($finisher, $o['path']), 'packer view');
$check(($view['employeeActionBlockedReason'] ?? null) === 'pieces_in_rework' && $view['pieces']['mixedProgress'] === true, 'the packer sees why');
$stages = $replay($t1, 'packing', static fn (string $stage): array => $finisher, $labelA);
$check($stages === ['height', 'header-tape', 'sewing-finishing', 'quality-control'], 'piece A completes stages 9-12 itself');
$tr = $track($t1);
$check($tr['status'] === 'merged' && $tr['merged_reason'] === 'packing' && $tr['owner_employee_uuid'] === null, 'piece A rejoined the order at packing');
$check(count(array_filter($unitsOf($o), static fn ($u) => $u['track_uuid'] !== null)) === 0 && $piecesOf($finisher, $o)['mixedProgress'] === false, 'both pieces travel together again');
$claim($finisher, $o);
$advance($finisher, $o);
$claim($finisher, $o);
$advance($finisher, $o);
$check($state($o)['completed'] !== null, '30. the order completes only after every piece was eligible');
$check((int) $one("SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = ? AND action = 'production_completed'", [$o['uuid']]) === 1, 'one completion of the commercial order');

// ==== Scenario 2: five products, one faulty piece of product B ==================================================
$five = $signed('FIVE', 'trendhome', [$line('Produs A', 'A-1', 1, 4.0), $line('Produs B', 'B-1', 2, 6.0), $line('Produs C', 'C-1', 1, 3.0), $line('Produs D', 'D-1', 3, 9.0), $line('Produs E', 'E-1', 1, 2.0)]);
$confirmPlan($five);
$bring($five, $cutter2, 'side-hem', $kadyrova);
$fp = $piecesOf($kadyrova, $five);
$check($fp['total'] === 8, 'eight physical pieces');
$bPiece = array_values(array_filter($fp['units'], static fn ($u) => $u['itemId'] === $five['items'][1] && $u['index'] === 2))[0];
$x2 = $ok($reportUnits($kadyrova, $five, [$bPiece['id']], 'cut-meterage', 'Prea scurtă'), 'one piece of product B', 201);
$check($x2['orderScope']['affectedQuantity'] === 1 && $x2['orderScope']['unaffectedQuantity'] === 7 && $x2['faultMeters'] === '3.000', 'one of eight pieces; the confirmed equal plan gives 3 m for 1 of 2 pieces of a 6 m line');
$t2 = $x2['orderScope']['trackId'];
$ok($review($iuliana, $x2['id'], ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $five['qr']]), 'custody');
$label2 = (string) $labels($iuliana, $t2)['labels'][0]['payload'];
$ok($sendReturn($iuliana, $x2['id']), 'return');
$error($ack($cutter2, $x2['id'], $labelA, ['responsibility' => 'accepted']), 422, 'QR_ORDER_MISMATCH', 'the label of a piece of another order is refused');
$ok($ack($cutter2, $x2['id'], $label2, ['responsibility' => 'accepted']), 'receipt with the right label');
$ok($decide($denisa, $x2['id'], ['decision' => 'approve', 'attribution' => 'cutter']), 'approved, cutter responsible');
$check($state($five)['stage'] === 'side-hem' && $state($five)['owner'] === $uuids['kadyrova'], 'the other seven pieces keep stage 7');
$check(count(array_filter($unitsOf($five), static fn ($u) => $u['track_uuid'] !== null)) === 1, 'only one piece is in rework');
$check((int) $one("SELECT COUNT(*) FROM production_quality_events WHERE exception_uuid = ? AND event_type = 'cutting_fault'", [$x2['id']]) === 1 && (int) $one("SELECT line_count FROM production_quality_events WHERE exception_uuid = ? AND event_type = 'cutting_fault'", [$x2['id']]) === 1, 'one cutting fault for one line');
// Repeat rework of the same piece: re-cut, then a new defect at stage 5 on the track itself (whole track).
$ok($trackComplete($cutter2, $t2, ['pieceOutcome' => 'repair']), 're-cut');
$replay($t2, 'material-straightening', static fn (string $stage): array => $stage === 'labeling' ? $labeler : $tailor2, $label2);
$ok($trackClaim($tailor3, $t2, $label2), 'stage 5 claimed');
$x3 = $ok($reportUnits($tailor3, $five, [$bPiece['id']], 'cut-meterage', 'Din nou scurtă', $t2), 'a second defect on the same piece', 201);
$check($x3['orderScope']['carrier'] === 'track' && $x3['orderScope']['trackId'] === $t2 && $x3['orderScope']['partial'] === false && $x3['affectedPieces'][0]['reworkCycleAtReport'] === 2, 'the report acts on the existing track; cycle 2 is recorded');
$check($x3['repeatedError'] === true || (int) $one('SELECT COUNT(*) FROM production_exceptions WHERE order_uuid = ?', [$five['uuid']]) === 2, 'the earlier incident stays recorded');
$ok($review($iuliana, $x3['id'], ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $label2]), 'custody by the piece label');
$ok($sendReturn($iuliana, $x3['id']), 'return again');
$x3ack = $ok($ack($cutter2, $x3['id'], $label2, ['responsibility' => 'accepted']), 'receipt again');
$check(str_contains(json_encode($x3ack['cuttingHandoff']), 'Test Cutter Two') && ($x3ack['cuttingHandoff']['rework'] ?? false) === true, 'responsibility refers to the re-cut of cycle 2');
$ok($decide($hikmet, $x3['id'], ['decision' => 'approve', 'attribution' => 'cutter']), 'approved again');
$check((int) $track($t2)['rework_cycle'] === 3 && $track($t2)['stage_id'] === 'material-preparation', 'the same piece starts cycle 3; history kept');
$check((int) $one('SELECT COUNT(*) FROM production_exceptions WHERE order_uuid = ? AND rework_cycle IS NOT NULL', [$five['uuid']]) === 2, 'two approved incidents remain separate');

// ==== Scenario 3: two faulty pieces of different products in one case ===========================================
$multi = $signed('MULTI', 'outletperdele', [$line('Produs A', 'A-1', 1, 4.0), $line('Produs B', 'B-1', 2, 6.0), $line('Produs D', 'D-1', 3, 9.0)]);
$confirmPlan($multi);
$bring($multi, $cutter2, 'ironing', $kadyrova);
$mp = $piecesOf($kadyrova, $multi);
$picked = [$mp['units'][1]['id'], $mp['units'][4]['id']];
$x4 = $ok($reportUnits($kadyrova, $multi, $picked, 'material-defect'), 'two pieces of two products in one case', 201);
$check($x4['orderScope']['affectedQuantity'] === 2 && $x4['orderScope']['affectedLineCount'] === 2 && count($x4['affectedPieces']) === 2, 'one case names both pieces exactly');
$t4 = $x4['orderScope']['trackId'];
$check((int) $one('SELECT COUNT(*) FROM production_work_units WHERE track_uuid = ?', [$t4]) === 2, 'both pieces travel on one track');
// Dismissal: nothing was wrong; the pieces rejoin the order where they are.
$ok($review($iuliana, $x4['id'], ['decision' => 'dismiss', 'comment' => 'Nu este defect']), 'the supervisor dismisses');
$check($track($t4)['status'] === 'merged' && $track($t4)['merged_reason'] === 'dismissed' && $piecesOf($kadyrova, $multi)['mixedProgress'] === false, 'dismissed pieces rejoin the order immediately (same stage, same owner)');
$check((int) $one('SELECT COUNT(*) FROM production_quality_events WHERE exception_uuid = ?', [$x4['id']]) === 0, 'a dismissed report creates no quality fact');

// ==== Scenario 4: five identical pieces, two faulty; rejection, then deliberate withdrawal ======================
$same = $signed('SAME', 'trendhome', [$line('Draperie Identica', 'S-5', 5, 10.0)]);
$confirmPlan($same);
$bring($same, $cutter2, 'bottom-hem', $kadyrova);
$sp = $piecesOf($kadyrova, $same);
$x5 = $ok($reportUnits($kadyrova, $same, [$sp['units'][1]['id'], $sp['units'][3]['id']], 'cut-meterage'), 'two of five identical pieces', 201);
$check($x5['orderScope']['affectedQuantity'] === 2 && $x5['orderScope']['unaffectedQuantity'] === 3 && $x5['faultMeters'] === '4.000', '2 of 5 pieces; 2 m each by the confirmed equal plan');
$t5 = $x5['orderScope']['trackId'];
$ok($review($iuliana, $x5['id'], ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $same['qr']]), 'custody');
$ok($sendReturn($iuliana, $x5['id']), 'return');
$ok($ack($cutter2, $x5['id'], $same['qr'], ['responsibility' => 'accepted']), 'receipt with the order QR');
$ok($decide($denisa, $x5['id'], ['decision' => 'reject', 'comment' => 'Se poate remedia la croitorie']), 'the manager rejects');
$check($track($t5)['stage_id'] === 'workshop-receiving' && $track($t5)['open_exception_uuid'] === $x5['id'] && $track($t5)['owner_employee_uuid'] === null, 'a rejection never releases the pieces: they stay blocked under the supervisor');
$check($state($same)['stage'] === 'bottom-hem' && $state($same)['owner'] === $uuids['kadyrova'], 'the three other pieces are unaffected');
$restored = $ok($restore($iuliana, $x5['id'], $same['qr'], 'Revin la tiv'), 'the supervisor hands the pieces back');
$ok($receive($kadyrova, $x5['id'], $same['qr']), 'the worker receives them back');
$check($track($t5)['status'] === 'merged' && $track($t5)['merged_reason'] === 'restored', 'the pieces rejoin the order (same stage, same owner)');
$check(array_unique(array_map(static fn ($u) => (int) $u['rework_cycle'], $unitsOf($same))) === [1], 'no rework cycle for a rejected return');

// Deliberate withdrawal of a pending return (cancelReturn) on a piece while the order moved on.
$x6 = $ok($reportUnits($kadyrova, $same, [$sp['units'][0]['id']], 'cut-meterage'), 'one more piece', 201);
$t6 = $x6['orderScope']['trackId'];
$ok($post($kadyrova, $same['path'] . '/transition', ['expectedVersion' => (int) $state($same)['version']], $key('same-move')), 'the four other pieces move on to side-hem');
$ok($review($iuliana, $x6['id'], ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $same['qr']]), 'custody');
$ok($sendReturn($iuliana, $x6['id']), 'return');
$ok($restore($iuliana, $x6['id'], $same['qr'], 'Anulez trimiterea', ['cancelReturn' => true]), 'deliberate withdrawal');
$ok($receive($kadyrova, $x6['id'], $same['qr']), 'received back');
$check($track($t6)['status'] === 'active' && $track($t6)['stage_id'] === 'bottom-hem' && $track($t6)['owner_employee_uuid'] === $uuids['kadyrova'] && $state($same)['stage'] === 'side-hem', 'the piece is back at bottom-hem on its own track; the rest of the order is further');
$ok($trackComplete($kadyrova, $t6), 'the worker finishes bottom-hem for that piece');
$check($track($t6)['stage_id'] === 'side-hem', 'the piece follows the canonical workflow on its own');

// ==== Scenario 5: inactive receiver; a manager rejection is never an automatic release =========================
$solo = $signed('SOLO', 'trendhome', [$line('Draperie Solo', 'P-2', 2, 6.0)]);
$confirmPlan($solo);
$bring($solo, $cutter2, 'ironing', $kadyrova);
$pp = $piecesOf($kadyrova, $solo);
$x7 = $ok($reportUnits($kadyrova, $solo, [$pp['units'][0]['id']], 'cut-meterage'), 'one piece', 201);
$ok($review($iuliana, $x7['id'], ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $solo['qr']]), 'custody');
$ok($sendReturn($iuliana, $x7['id']), 'return');
$ok($ack($cutter2, $x7['id'], $solo['qr'], ['responsibility' => 'accepted']), 'receipt');
$ok($dash($root, 'POST', "/management/employees/{$uuids['cutter2']}/deactivate"), 'the receiving cutter leaves');
$error($decide($denisa, $x7['id'], ['decision' => 'approve', 'attribution' => 'cutter']), 409, 'RETURN_ASSIGNEE_UNAVAILABLE', 'the piece is never assigned to an inactive cutter');
$check((int) $one("SELECT COUNT(*) FROM production_exception_decisions WHERE exception_uuid = ? AND status = 'pending'", [$x7['id']]) === 1 && $track($x7['orderScope']['trackId'])['stage_id'] === 'workshop-receiving', 'the decision stays pending; nothing moved');
$ok($dash($root, 'POST', "/management/employees/{$uuids['cutter2']}/activate"), 'the cutter is back');

// ==== Rollback: disabling the flag keeps existing tracks working and refuses new partial reports ===============
$pdo->beginTransaction();
(new WorkUnits($pdo))->setPartialRework(false, 'test rollback', gmdate('Y-m-d H:i:s.000000'));
$pdo->commit();
$claim($kadyrova, $same);
$mainSame = array_values(array_filter($unitsOf($same), static fn ($u) => $u['track_uuid'] === null));
$error($reportUnits($kadyrova, $same, [$mainSame[0]['unit_uuid']], 'cut-meterage'), 409, 'PARTIAL_REWORK_DISABLED', 'with the flag off a new partial report is refused');
$check(count($mainSame) === 4, 'four pieces travel with that order');
$ok($trackClaim($kadyrova, $t6, $same['qr']), 'an existing piece in rework still moves with the flag off');
$check($track($t6)['owner_employee_uuid'] === $uuids['kadyrova'], 'existing track keeps working');

// ==== Concurrent ordinary orders of three sources keep their normal flow =========================================
foreach (['trendhome', 'outletperdele'] as $source) {
    $n = $signed('N' . strtoupper(substr($source, 0, 2)), $source, [$line('Normal', 'N-1', 2, 4.0)]);
    $confirmPlan($n);
    $bring($n, $murat, 'labeling', $tailor2);
    $check($piecesOf($tailor2, $n)['mixedProgress'] === false && $piecesOf($tailor2, $n)['total'] === 2, "{$source}: an ordinary order is unaffected");
}
$nt = $trendyol('N', [$line('Normal Trendyol', 'N-2', 1)]);
$bring($nt, $murat, 'labeling', $tailor2);
$check($piecesOf($tailor2, $nt)['tracks'] === [], 'trendyol: an ordinary order is unaffected');

fwrite(STDOUT, "OK MySQL per-piece rework integration ({$checks} checks)\n");
