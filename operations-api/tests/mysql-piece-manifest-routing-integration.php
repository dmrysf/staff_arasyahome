<?php

declare(strict_types=1);

// Manufacturing manifest, product routes, replacement lineage, physical custody, material records, administrative
// recovery and rollback guards (migration 029, API 2.32.0) against a dedicated MySQL/MariaDB test database, end to end
// through the HTTP kernel and the operator CLIs. Every assertion reads committed rows.
//
//   M1  commercial quantity 1, physical pieces 2 (unequal 3 m + 2 m of a 5 m line): nothing invented before an authorized
//       confirmation; the fabric of the defective piece is its planned fabric, never 2.5 m; unknown stays unknown
//   M2  PERDEA route: 5 -> 8 with an auditable stage_not_applicable decision, no events for 6/7; the type is locked
//   M3  DRAPERII route: 5 -> 6 -> 7 -> 8 unchanged; the feature off keeps every route as before
//   M4  mixed PERDEA + DRAPERII order: independent routes without any defect, packing waits for the reunion
//   M5  unknown classification and unconfirmed plans keep the full route and record full_route_unverified
//   M6  PERDEA rework with a full replacement: custody of the healthy companion without a stage change, authorization
//       before cutting, replacement lineage, obsolete label refused, material once, replay 2 -> 3 -> 4 -> 5 -> 8,
//       defect confirmation, physical reunion without a logical merge, healthy piece advancing on its own, exact
//       deliverable count at packing
//   M7  administrative recovery of a withdrawn return: the same physical defect is not credited twice
//   M8  source re-sync: no duplicate pieces, stable identities, a quantity change requires a new confirmation
//   M9  concurrency: two confirmations of the same plan revision, two material records of the same piece
//   M10 migration re-run, rollback guard and disabling the features with pieces still on tracks
//   M12 historical order confirmed after cutting: ORDER_ROUTE_INCOMPLETE until verified per-piece evidence (030),
//       nothing synthesized, uncertain stages keep blocking, route-audit classes, 030 rollback guard and re-run
//   M13 employee KPI: track and piece work reported apart from commercial completions, no credit for not applicable

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Production\FeatureActivation;
use Arasya\Operations\Production\WorkUnits;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL piece manifest and routing integration: ARASYA_TEST_DB_NAME is not configured.\n");
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
// A clean feature state for this suite (earlier suites may have left flags on): global flags off.
$pdo->exec("UPDATE production_feature_flags SET enabled = 0 WHERE flag_key IN ('partial_rework', 'piece_routing')");

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, $label . ' HTTP ' . $r['status'] . ' ' . json_encode($r['body'])); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$rows = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$suffix = bin2hex(random_bytes(4));
$password = 'piece manifest passphrase 2026';
$rootName = "manifest.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-manifest-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Manifest root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Manifest root passphrase 2026!');
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string { $keys++; return sprintf('pm-%s-%s-%04d', $label, $suffix, $keys); };
$dash = static fn (array $who, string $method, string $path, ?array $body = null, ?string $idem = null): array => T::call($kernel, $method, $path, $body, ['origin' => DASHBOARD, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'pm-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$dashGet = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => DASHBOARD], $who['cookie'], $query);
$post = static fn (array $who, string $path, array $body, ?string $idem = null): array => T::call($kernel, 'POST', $path, $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'pm-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $query);
$roleIds = [];
foreach ($ok($dashGet($root, '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];

$tailoringStages = ['workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control'];
$uuids = [];
$identity = static function (string $name, string $display, array $stages, array $apps = ['staff'], array $roles = [], string $department = 'pregatire-material') use ($container, $dash, $root, $ok, $password, $suffix, $kernel, &$uuids): array {
    $employee = $container->employeeAdmin()->create($display, "{$name}.{$suffix}", null, $department, 'employee', $password, $stages, 'test-manifest');
    $uuids[$name] = $employee->employeeUuid;
    $ok($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]), "{$name} applications");
    if ($roles !== []) $ok($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['employeeUuid' => $employee->employeeUuid, 'name' => $display];
};
$murat = $identity('murat', 'REJEPOV AYMYRAT', ['material-preparation']);
$kadyrova = $identity('kadyrova', 'KADYROVA ZULFIYA', $tailoringStages);
$tailor2 = $identity('tailor2', 'Test Tailor Two', $tailoringStages);
$ironer = $identity('ironer', 'Test Ironer', ['ironing']);
$hemmer = $identity('hemmer', 'Test Hemmer', ['bottom-hem', 'side-hem']);
$labeler = $identity('labeler', 'Test Labeler', ['labeling']);
$finisher = $identity('finisher', 'Test Finisher', ['height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery']);
$iuliana = $identity('iuliana', 'VASILOAIA IULIANA', $tailoringStages);
// Denisa: an operations manager by role; no direct manager is configured for the cutter (none is ever needed).
$denisa = $identity('denisa', 'VOICAN DENISA NICOLETA', [], ['dashboard'], [$roleIds['operations-manager']]);
$hikmet = $identity('hikmet', 'Test Second Manager', [], ['dashboard'], [$roleIds['operations-manager']]);
$ceo = $identity('ceo', 'Test CEO', [], ['dashboard'], [$roleIds['ceo']]);
$ok($dash($root, 'PUT', '/management/organization/ceo', ['employeeId' => $uuids['ceo']]), 'root designates the CEO');
$ok($dash($ceo, 'POST', '/management/organization/responsibilities', ['responsibility' => 'tailoring_quality_supervisor', 'employeeId' => $uuids['iuliana']]), 'the CEO appoints the supervisor', 201);
$check($one('SELECT manager_employee_uuid FROM employees WHERE employee_uuid = ?', [$uuids['murat']]) === null, 'the cutter has no direct manager');

// ---- Orders and helpers -------------------------------------------------------------------------------------------
$trendyol = static function (string $tag, array $lines) use ($container, $pdo, $one, $rows, $suffix): array {
    $number = "118{$tag}{$suffix}";
    $items = [];
    foreach ($lines as $i => $line) {
        $items[] = new OperationalOrderItem(Uuid::v4(), "line-{$tag}-{$i}", $i + 1, $line['name'], $line['code'], null, $line['color'], $line['width'], $line['height'], 'cm', $line['meters'], $line['quantity'],
            ['notes' => null, 'productionNotes' => null, 'marketplaceTitle' => $line['name'], 'barcode' => null] + ($line['kind'] === null ? [] : ['kind' => $line['kind']]));
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $pdo->beginTransaction();
    $uuid = $container->projectionWriter()->createInternal(new SourceOrderSnapshot('trendyol', "pkg-m{$tag}-{$suffix}", "approval-m{$tag}-{$suffix}", 1, $now, $number, 'waiting', 'Created', 'Created', null, 'in_progress', $now, $items), []);
    $pdo->commit();
    $id = (string) $one('SELECT global_order_id FROM operational_orders WHERE order_uuid = ?', [$uuid]);
    return ['id' => $id, 'uuid' => $uuid, 'number' => $number, 'path' => '/orders/' . rawurlencode($id), 'source' => 'trendyol',
        'qr' => 'ARASYA:Q1:' . (string) $one("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$uuid]),
        'items' => array_column($rows('SELECT item_uuid FROM operational_order_items WHERE order_uuid = ? ORDER BY line_number', [$uuid]), 'item_uuid')];
};
$line = static fn (string $name, string $code, int $quantity, ?float $meters, ?string $kind, string $color = 'Crem'): array => ['name' => $name, 'code' => $code, 'color' => $color, 'width' => 250.0, 'height' => 260.0, 'meters' => $meters, 'quantity' => $quantity, 'kind' => $kind];
$state = static fn (array $o): array => $rows('SELECT production_stage_id AS stage, production_owner_employee_uuid AS owner, production_version AS version, open_exception_uuid AS exception, production_completed_at AS completed FROM operational_orders WHERE order_uuid = ?', [$o['uuid']])[0];
$track = static fn (string $trackUuid): array => $rows('SELECT * FROM production_tracks WHERE track_uuid = ?', [$trackUuid])[0];
$claim = static function (array $who, array $o) use ($post, $ok, $state, $pdo, $key): void {
    $ok($post($who, $o['path'] . '/claim', T::cuttingClaim($pdo, $o['id'], $who['employeeUuid'] ?? '', (int) $state($o)['version']), $key('claim')), "claim {$o['id']} at " . $state($o)['stage']);
};
$advance = static function (array $who, array $o, array $extra = []) use ($post, $ok, $state, $key): array {
    return $ok($post($who, $o['path'] . '/transition', ['expectedVersion' => (int) $state($o)['version']] + $extra, $key('move')), "transition {$o['id']} at " . $state($o)['stage']);
};
$cut = static function (array $cutter, array $o) use ($post, $ok, $advance, $state, $key): void {
    $ok($post($cutter, '/cutting/intake', ['qrToken' => $o['qr']], $key('intake')), "cutting intake {$o['id']}");
    $advance($cutter, $o);
};
// Moves the order (claim + complete) until it waits at $target; $by(stage) chooses the employee of each stage.
$bring = static function (array $o, string $target, callable $by) use ($claim, $advance, $state, $check): void {
    $guard = 0;
    while (($stage = $state($o)['stage']) !== $target) {
        $who = $by($stage);
        $claim($who, $o);
        $advance($who, $o);
        $check(++$guard < 20, 'bring terminates');
    }
};
$staffBy = static fn (string $stage): array => match ($stage) { 'labeling' => $labeler, 'bottom-hem', 'side-hem' => $hemmer, 'ironing' => $ironer, 'height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery' => $finisher, default => $tailor2 };
$exVersion = static fn (string $id): int => (int) $one('SELECT version FROM production_exceptions WHERE exception_uuid = ?', [$id]);
$trackVersion = static fn (string $id): int => (int) $one('SELECT version FROM production_tracks WHERE track_uuid = ?', [$id]);
$report = static fn (array $who, array $o, array $body, ?string $idem = null) => $post($who, $o['path'] . '/fault-reports', ['expectedVersion' => (int) $state($o)['version'], 'reasonKey' => 'material-defect'] + $body, $idem);
$review = static fn (array $who, string $id, array $body, ?string $idem = null) => $post($who, "/production-exceptions/{$id}/review", ['expectedVersion' => $exVersion($id)] + $body, $idem);
$sendReturn = static fn (array $who, string $id, array $body = [], ?string $idem = null) => $post($who, "/production-exceptions/{$id}/return", ['expectedVersion' => $exVersion($id)] + $body, $idem);
$ack = static fn (array $who, string $id, string $qr, array $body = [], ?string $idem = null) => $post($who, "/production-exceptions/{$id}/acknowledge", ['expectedVersion' => $exVersion($id), 'confirmed' => true, 'qrToken' => $qr] + $body, $idem);
$decide = static fn (array $who, string $id, array $body, ?string $idem = null) => $dash($who, 'POST', "/management/production-exceptions/{$id}/decision", ['expectedVersion' => $exVersion($id)] + $body, $idem);
$restore = static fn (array $who, string $id, string $qr, string $comment, array $extra = [], ?string $idem = null) => $post($who, "/production-exceptions/{$id}/restore", ['expectedVersion' => $exVersion($id), 'qrToken' => $qr, 'comment' => $comment] + $extra, $idem);
$receive = static fn (array $who, string $id, string $qr, ?string $idem = null) => $post($who, "/production-exceptions/{$id}/receive", ['expectedVersion' => $exVersion($id), 'confirmed' => true, 'qrToken' => $qr], $idem);
$trackClaim = static fn (array $who, string $trackId, string $qr, ?string $idem = null) => $post($who, "/production-tracks/{$trackId}/claim", ['expectedVersion' => $trackVersion($trackId), 'qrToken' => $qr], $idem);
$trackComplete = static fn (array $who, string $trackId, array $extra = [], ?string $idem = null) => $post($who, "/production-tracks/{$trackId}/complete", ['expectedVersion' => $trackVersion($trackId)] + $extra, $idem);
$planOf = static fn (array $who, array $o): array => $ok(isset($who['dashboard']) ? $dashGet($who, $o['path'] . '/manufacturing-plan') : $get($who, $o['path'] . '/manufacturing-plan'), 'plan view');
$confirm = static function (array $who, array $o, string $itemId, array $body, ?string $idem = null, bool $dashboard = false) use ($dash, $post, $planOf, $iuliana): array {
    $lines = $planOf($iuliana, $o)['lines'];
    $hashes = array_column($lines, 'sourcePlanHash', 'itemId');
    $body += ['expectedSourceHash' => $hashes[$itemId]];
    return $dashboard ? $dash($who, 'POST', $o['path'] . '/manufacturing-plan/lines/' . $itemId . '/confirm', $body, $idem)
        : $post($who, $o['path'] . '/manufacturing-plan/lines/' . $itemId . '/confirm', $body, $idem);
};
$features = new FeatureActivation($pdo);
$now = static fn (): string => gmdate('Y-m-d H:i:s.000000');
$cohort = static function (array $o, string ...$which) use ($features, $now, $check): void {
    foreach ($which as $feature) {
        $check($features->setCohortMember($feature, $o['uuid'], true, 'test-operator', 'pilot cohort for the test', $now())['member'] === true, "{$feature} cohort");
    }
};
$decisions = static fn (array $o): array => $rows('SELECT kind, from_stage_id, to_stage_id, skipped_stages_json, product_types_json, reason, track_uuid, new_track_uuid, rework_cycle FROM production_route_decisions WHERE order_uuid = ? ORDER BY occurred_at, decision_uuid', [$o['uuid']]);
$activity = static fn (array $o): array => array_map(static fn (array $r): string => $r['from_stage_id'] . '>' . ($r['to_stage_id'] ?? ''), $rows("SELECT from_stage_id, to_stage_id FROM order_activity_events WHERE order_uuid = ? AND action = 'stage_completed' ORDER BY production_version_after", [$o['uuid']]));
$ledger = static fn (string $unit, int $cycle): array => array_column($rows('SELECT stage_id, outcome FROM production_unit_stage_log WHERE unit_uuid = ? AND rework_cycle = ? ORDER BY occurred_at, stage_id', [$unit, $cycle]), 'outcome', 'stage_id');
$cliEnvironment = ['HOME' => (string) getenv('HOME'),
    'ARASYA_APP_ENV' => 'test', 'ARASYA_APP_SECRET' => str_repeat('t', 32), 'ARASYA_ALLOWED_ORIGINS' => T::ORIGIN,
    'ARASYA_DB_HOST' => (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1'), 'ARASYA_DB_PORT' => (string) (getenv('ARASYA_TEST_DB_PORT') ?: '3306'),
    'ARASYA_DB_NAME' => $dbName, 'ARASYA_DB_USER' => (string) getenv('ARASYA_TEST_DB_USER'), 'ARASYA_DB_PASSWORD' => (string) getenv('ARASYA_TEST_DB_PASSWORD'),
];
$cli = static function (string $script, string ...$arguments) use ($cliEnvironment): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/' . $script, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $cliEnvironment);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => $out, 'err' => $err, 'json' => json_decode($out, true)];
};
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
$denisa['dashboard'] = true;
$hikmet['dashboard'] = true;

$supervisorLocation = 'masa-sef-' . $suffix;
$ironingLocation = 'sina-8-' . $suffix;

// Registered physical locations (an operator registers the real places; the software invents none).
$cli('production-locations.php', 'add', '--id=' . $supervisorLocation, '--label=Masa șefului de croitorie', '--kind=supervisor_table', '--operator=test-operator');
$cliAdded = $cli('production-locations.php', 'add', '--id=' . $ironingLocation, '--label=Șina A la călcare', '--kind=rail', '--operator=test-operator', '--stage=ironing');
$check($cliAdded['code'] === 0 && in_array($ironingLocation, array_column($cliAdded['json']['locations'] ?? [], 'location_id'), true), 'the operator registers locations: ' . $cliAdded['out'] . $cliAdded['err']);
$check($cli('production-locations.php', 'add', '--id=Bad Id', '--label=x', '--kind=rail', '--operator=o')['code'] === 2, 'an invalid location id is refused');

// ==== M10a. Migration 029 is re-runnable =============================================================================
$tables = (int) $one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
(new SqlFileRunner($pdo))->run($migrations . '/029_production_manifest_routing.sql');
$check((int) $one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') === $tables && (int) $one("SELECT COUNT(*) FROM production_feature_flags WHERE flag_key = 'piece_routing'") === 1, '37. migration 029 re-runs: no table or flag twice');
$check((int) $one("SELECT enabled FROM production_feature_flags WHERE flag_key = 'piece_routing'") === 0, 'piece routing is created disabled');
$check((string) $one("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'production_tracks' AND column_name = 'origin_exception_uuid'") === 'YES', 'route tracks need no exception');

// ==== M1. Commercial quantity 1, two physical pieces, unequal fabric ===============================================
$m1 = $trendyol('A', [$line('Draperie de Textura Nisip Turcesc Ivory', '12754-21', 1, 5.0, 'drapery')]);
$plan = $planOf($iuliana, $m1);
$l1 = $plan['lines'][0];
$check($l1['commercialQuantity'] === 1 && $l1['plan']['pieceCount'] === null && $l1['requiresConfirmation'] === true && $l1['plan']['revision'] === 0, '2/35. the piece count is unknown until confirmed (never the commercial quantity): ' . json_encode($l1));
$check($l1['plan']['productType'] === 'draperii' && $l1['plan']['typeSource'] === 'source_classification' && $l1['plan']['materialTotal'] === '5.000' && $l1['plan']['materialSource'] === 'source_line', 'the type comes from the preparation classification, the fabric total from the line');
$check($l1['proposal']['evidence'][0] === ['kind' => 'commercial_quantity', 'value' => '1'] && $l1['pieces'] === [], 'the source evidence is shown, no piece is invented');
$check($l1['route'] === ['waiting', 'material-preparation', 'workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery'], 'DRAPERII keeps every canonical stage');
$check((int) $one('SELECT COUNT(*) FROM production_work_units WHERE order_uuid = ?', [$m1['uuid']]) === 0, '35. no physical piece is written for an unknown plan');
$cut($murat, $m1);
$unknownDto1 = $ok($get($murat, $m1['path']), 'unknown physical count order DTO after authorized intake')['pieces'];
$check($unknownDto1['total'] === null && $unknownDto1['allPlansKnown'] === false && $unknownDto1['partialRework'] === false, 'unknown physical total is null, never zero or commercial quantity; activation is per order');
$bring($m1, 'ironing', $staffBy);
$claim($kadyrova, $m1);
$error($report($kadyrova, $m1, ['units' => [WorkUnits::unitUuid($m1['items'][0], 1)]]), 409, 'PLAN_CONFIRMATION_REQUIRED', '35. a piece report waits for the plan, nothing is guessed');
$error($confirm($kadyrova, $m1, $m1['items'][0], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 2, 'note' => 'două bucăți']), 403, 'PLAN_CONFIRMATION_NOT_ALLOWED', 'a tailoring employee cannot confirm a plan');
$error($confirm($iuliana, $m1, $m1['items'][0], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 3, 'equalSplit' => true, 'note' => 'trei bucăți egale']), 422, 'MATERIAL_SPLIT_NOT_EXACT', '3. 5 m in three equal pieces is not exact: refused, never rounded');
$error($confirm($iuliana, $m1, $m1['items'][0], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 2, 'pieces' => [['index' => 1, 'fabricMeters' => '3.000'], ['index' => 2, 'fabricMeters' => '1.000']], 'note' => 'sumă greșită']), 422, 'MATERIAL_SUM_MISMATCH', '3. per-piece fabric must add up to the line');
$error($confirm($iuliana, $m1, $m1['items'][0], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 2, 'pieces' => [['index' => 1, 'fabricMeters' => 3.0], ['index' => 2, 'fabricMeters' => '2.000']], 'note' => 'număr']), 400, 'INVALID_REQUEST', 'decimals travel as strings (no float rounding)');
$confirmKey = $key('plan');
$confirmBody = ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 2, 'note' => 'Plan de croială: două bucăți inegale',
    'pieces' => [['index' => 1, 'width' => '130.000', 'height' => '260.000', 'unit' => 'cm', 'fabricMeters' => '3.000'], ['index' => 2, 'width' => '120.000', 'height' => '260.000', 'unit' => 'cm', 'fabricMeters' => '2.000']]];
$confirmed = $ok($confirm($iuliana, $m1, $m1['items'][0], $confirmBody, $confirmKey), '2. the supervisor confirms 2 physical pieces');
$check($ok($confirm($iuliana, $m1, $m1['items'][0], $confirmBody, $confirmKey), 'replay') === $confirmed, 'a retried confirmation replays the committed plan');
$error($confirm($iuliana, $m1, $m1['items'][0], $confirmBody), 409, 'PLAN_CHANGED', 'a stale plan revision is refused');
$c1 = $confirmed['lines'][0];
$check($c1['commercialQuantity'] === 1 && $c1['plan']['pieceCount'] === 2 && $c1['plan']['revision'] === 1 && $c1['planKnown'] === true && count($c1['pieces']) === 2, '2. one commercial unit, two physical pieces');
$check($c1['pieces'][0]['fabricMeters'] === '3.000' && $c1['pieces'][1]['fabricMeters'] === '2.000' && $c1['pieces'][1]['width'] === '120.000', '3. unequal planned fabric and cut sizes per piece');
$check((int) $one("SELECT COUNT(*) FROM production_work_units WHERE order_uuid = ? AND status = 'active'", [$m1['uuid']]) === 2 && (int) $one('SELECT quantity FROM operational_order_items WHERE item_uuid = ?', [$m1['items'][0]]) === 1, '2. two pieces exist; the commercial line is unchanged');
$check($rows("SELECT started_stage_id FROM production_unit_cycles c INNER JOIN production_work_units u ON u.unit_uuid = c.unit_uuid WHERE u.order_uuid = ?", [$m1['uuid']]) === [['started_stage_id' => 'ironing'], ['started_stage_id' => 'ironing']], 'the per-piece ledger starts where the pieces were confirmed');
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'production.plan.confirmed' AND target_id = ?", [$m1['id']]) === 1, 'the confirmation is audited once');
$cohort($m1, FeatureActivation::PARTIAL_REWORK);
$pieces = $c1['pieces'];
$error($report($kadyrova, $m1, ['itemIds' => [$m1['items'][0]]]), 422, 'EXACT_PIECES_REQUIRED', 'one commercial line with two pieces still needs explicit physical selection on activated orders');
$r1 = $ok($report($kadyrova, $m1, ['units' => [$pieces[1]['id']], 'comment' => 'Fir tras']), '3. the second (2 m) piece is reported', 201);
$check($r1['faultMeters'] === '2.000' && $r1['unmeasuredLineCount'] === 0, '3. the defective piece needs its planned 2 m, never 2.5 m or 5 m: ' . json_encode([$r1['faultMeters'], $r1['unmeasuredLineCount']]));
$check($r1['orderScope']['quantityUnit'] === 'physical_pieces' && $r1['orderScope']['orderQuantity'] === 2 && $r1['orderScope']['commercialOrderQuantity'] === 1 && $r1['orderScope']['affectedQuantity'] === 1 && $r1['orderScope']['partial'] === true, 'the scope counts physical pieces and keeps the commercial quantity apart');
$check($r1['affectedPieces'][0]['piece'] === '2/2' && $r1['affectedPieces'][0]['fabricMeters'] === '2.000' && $r1['affectedPieces'][0]['pieceMeasurements']['width'] === '120.000' && $r1['affectedPieces'][0]['productType'] === 'draperii', 'the manager and the cutter see the exact piece, its cut size and fabric');
$check($state($m1)['exception'] === null && $state($m1)['owner'] === $uuids['kadyrova'], '30. the order (healthy piece) is not blocked; the worker keeps it');
// The same shape with an unknown per-piece fabric: unknown stays unknown.
$m1b = $trendyol('B', [$line('Draperie 5 m', '12754-22', 1, 5.0, 'drapery')]);
$cut($murat, $m1b);
$bring($m1b, 'ironing', $staffBy);
$claim($kadyrova, $m1b);
$ok($confirm($iuliana, $m1b, $m1b['items'][0], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 2, 'note' => 'două bucăți, croiala necunoscută']), 'two pieces, fabric per piece unknown');
$cohort($m1b, FeatureActivation::PARTIAL_REWORK);
$p1b = $planOf($iuliana, $m1b)['lines'][0]['pieces'];
$check($p1b[0]['fabricMeters'] === null && $p1b[1]['fabricMeters'] === null, '3. no 2.5 m is derived from 5 m / 2');
$r1b = $ok($report($kadyrova, $m1b, ['units' => [$p1b[0]['id']]]), 'report with unknown piece fabric', 201);
$check($r1b['faultMeters'] === null && $r1b['unmeasuredLineCount'] === 1 && $r1b['affectedPieces'][0]['fabricMeters'] === null, '3. the requirement is shown as unknown (verification needed), never 2.5 m or 0');

// ==== M2/M3. PERDEA and DRAPERII routes ==============================================================================
$m2 = $trendyol('C', [$line('Perdea voal alb', 'P-100', 1, 3.0, 'curtain')]);
$cohort($m2, FeatureActivation::PIECE_ROUTING);
$cut($murat, $m2);
$bring($m2, 'material-straightening', $staffBy);
$claim($tailor2, $m2);
$advance($tailor2, $m2);
$check($state($m2)['stage'] === 'ironing', '5. after stage 5 a PERDEA goes directly to stage 8');
$check($activity($m2) === ['waiting>material-preparation', 'material-preparation>workshop-receiving', 'workshop-receiving>labeling', 'labeling>material-straightening', 'material-straightening>ironing'], '5. no completion exists for 6 or 7: ' . json_encode($activity($m2)));
$d2 = $decisions($m2);
$check(count($d2) === 1 && $d2[0]['kind'] === 'stage_not_applicable' && json_decode($d2[0]['skipped_stages_json'], true) === ['bottom-hem', 'side-hem'] && json_decode($d2[0]['product_types_json'], true) === ['perdea'] && $d2[0]['reason'] === 'product_route_policy', '5. the route decision is audited: ' . json_encode($d2));
$check((int) $one("SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = ? AND (from_stage_id IN ('bottom-hem','side-hem') OR to_stage_id IN ('bottom-hem','side-hem'))", [$m2['uuid']]) === 0, 'no time, owner or credit at 6/7');
$locked = $planOf($iuliana, $m2)['lines'][0]['plan'];
$check($locked['revision'] === 1 && $locked['typeLocked'] === true && $locked['typeSource'] === 'source_classification', 'the classification used by the route is stored and locked');
$error($confirm($iuliana, $m2, $m2['items'][0], ['expectedRevision' => 1, 'productType' => 'draperii', 'pieceCount' => 1, 'note' => 'schimb tipul']), 409, 'PLAN_TYPE_LOCKED', 'a later edit cannot silently re-route a routed product');
$bring($m2, 'packing', $staffBy);
$error($post($finisher, $m2['path'] . '/claim', ['expectedVersion' => (int) $state($m2)['version']]), 409, 'PLAN_CONFIRMATION_REQUIRED', 'pilot packing needs an authoritative count');
$ok($confirm($iuliana, $m2, $m2['items'][0], ['expectedRevision' => 1, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'O bucată verificată fizic']), 'confirm the plan before packing');
$error($post($finisher, $m2['path'] . '/claim', ['expectedVersion' => (int) $state($m2)['version']]), 409, 'ORDER_ROUTE_INCOMPLETE', 'historical count confirmation at packing cannot fabricate earlier piece completions');
$check($state($m2)['completed'] === null && (int) $one('SELECT COUNT(*) FROM production_unit_stage_log WHERE order_uuid = ?', [$m2['uuid']]) === 0, 'backfill leaves unknown historical work unknown and blocks shipment');
// Without the feature for the order, nothing changes: PERDEA still goes 5 -> 6.
$m2off = $trendyol('D', [$line('Perdea fără pilot', 'P-101', 1, 3.0, 'curtain')]);
$cut($murat, $m2off);
$bring($m2off, 'bottom-hem', $staffBy);
$check($decisions($m2off) === [], 'feature off: the existing full route, no decision');
// DRAPERII (M3): 5 -> 6 -> 7 -> 8, no decision.
$m3 = $trendyol('E', [$line('Draperie blackout', 'D-200', 1, 4.0, 'drapery')]);
$cohort($m3, FeatureActivation::PIECE_ROUTING);
$cut($murat, $m3);
$bring($m3, 'height', $staffBy);
$check(array_slice($activity($m3), 3) === ['labeling>material-straightening', 'material-straightening>bottom-hem', 'bottom-hem>side-hem', 'side-hem>ironing', 'ironing>height'], '6. DRAPERII keeps 5 -> 6 -> 7 -> 8: ' . json_encode($activity($m3)));
$check($decisions($m3) === [], '6. no stage is marked not applicable for DRAPERII');

// ==== M4. Mixed PERDEA + DRAPERII order: independent routes, no defect =============================================
$m4 = $trendyol('F', [$line('Perdea in', 'P-300', 1, 2.0, 'curtain'), $line('Draperie catifea', 'D-300', 1, 4.0, 'drapery')]);
$cohort($m4, FeatureActivation::PIECE_ROUTING);
$ok($confirm($iuliana, $m4, $m4['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'o bucată']), 'perdea plan');
$ok($confirm($iuliana, $m4, $m4['items'][1], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 2, 'equalSplit' => true, 'note' => 'două bucăți egale']), 'draperie plan');
$cut($murat, $m4);
$bring($m4, 'material-straightening', $staffBy);
$claim($tailor2, $m4);
$advance($tailor2, $m4);
$check($state($m4)['stage'] === 'bottom-hem', '7. the DRAPERII pieces of the order go to stage 6');
$d4 = $decisions($m4);
$check(count($d4) === 1 && $d4[0]['kind'] === 'route_split' && $d4[0]['to_stage_id'] === 'ironing' && json_decode($d4[0]['skipped_stages_json'], true) === ['bottom-hem', 'side-hem'] && json_decode($d4[0]['product_types_json'], true) === ['perdea'], '7. the PERDEA piece leaves on its own route: ' . json_encode($d4));
$t4 = (string) $d4[0]['new_track_uuid'];
$tr4 = $track($t4);
$check($tr4['stage_id'] === 'ironing' && $tr4['owner_employee_uuid'] === null && $tr4['origin_exception_uuid'] === null && $tr4['verify_stage_id'] === null, '7. a route track: at stage 8, unowned, no exception, no defect');
$check((int) $one('SELECT COUNT(*) FROM production_exceptions WHERE order_uuid = ?', [$m4['uuid']]) === 0, '7. no artificial defect record exists');
$perdeaUnit = (string) $one('SELECT unit_uuid FROM production_work_units WHERE track_uuid = ?', [$t4]);
$check($ledger($perdeaUnit, 1) === ['labeling' => 'completed', 'material-preparation' => 'completed', 'material-straightening' => 'completed', 'waiting' => 'completed', 'workshop-receiving' => 'completed', 'bottom-hem' => 'not_applicable', 'side-hem' => 'not_applicable'] || (count($ledger($perdeaUnit, 1)) === 7 && $ledger($perdeaUnit, 1)['bottom-hem'] === 'not_applicable' && $ledger($perdeaUnit, 1)['material-straightening'] === 'completed'), '7. the PERDEA piece ledger: completed 1-5, not applicable 6-7: ' . json_encode($ledger($perdeaUnit, 1)));
$view4 = $ok($get($hemmer, $m4['path']), 'order view')['pieces'];
$check($view4['mixedProgress'] === true && $view4['total'] === 3 && $view4['onOrder'] === 2 && $view4['tracks'][0]['kind'] === 'route' && $view4['tracks'][0]['stage']['id'] === 'ironing', '7. the aggregate shows independent progress');
$check((int) $one("SELECT COUNT(*) FROM production_unit_labels WHERE unit_uuid = ? AND status = 'active'", [$perdeaUnit]) === 1, 'a piece travelling apart has its own label');
// The ironing employee works on the PERDEA piece while the DRAPERII pieces are at the hem.
$error($trackClaim($hemmer, $t4, $m4['qr']), 403, 'UNAUTHORIZED_ACTION', 'a hem employee cannot take the PERDEA piece at ironing');
$ok($trackClaim($ironer, $t4, $m4['qr']), '23. the ironing employee claims the PERDEA piece (order QR + the chosen piece)');
$ok($trackComplete($ironer, $t4), 'ironing of the PERDEA piece');
$check($track($t4)['stage_id'] === 'height', 'the PERDEA piece moves on independently');
// The finisher holds the PERDEA piece at stage 9, so the two routes cannot meet before packing.
$ok($trackClaim($finisher, $t4, $m4['qr']), 'the PERDEA piece is being worked on at stage 9');
// The DRAPERII pieces finish everything up to packing while the PERDEA piece is still at stage 9.
$bring($m4, 'packing', $staffBy);
$check($state($m4)['stage'] === 'packing' && $track($t4)['status'] === 'active', 'the order reached packing, the PERDEA piece is not there yet');
$claimed = $post($finisher, $m4['path'] . '/claim', ['expectedVersion' => (int) $state($m4)['version']]);
$error($claimed, 409, 'ORDER_PIECES_NOT_READY', '31/32. packing waits for the PERDEA piece');
$check(($ok($get($finisher, $m4['path']), 'order')['employeeActionBlockedReason'] ?? null) === 'pieces_awaiting_reunion', 'the reason is visible in the order view');
$ok($trackComplete($finisher, $t4), 'PERDEA piece completed at height');
$guard = 0;
while (($stage = $track($t4)['stage_id']) !== 'packing') {
    $ok($trackClaim($finisher, $t4, $m4['qr']), "PERDEA piece claimed at {$stage}");
    $ok($trackComplete($finisher, $t4), "PERDEA piece completed at {$stage}");
    $check(++$guard < 10, 'terminates');
}
$check($track($t4)['status'] === 'merged' && $track($t4)['merged_reason'] === 'packing', '32. the PERDEA piece rejoins the order at packing');
$unit4 = (string) $one("SELECT unit_uuid FROM production_work_units WHERE order_uuid = ? AND status = 'active' LIMIT 1", [$m4['uuid']]);
$ok($post($iuliana, "/production-units/{$unit4}/custody", ['action' => 'hold', 'holdReason' => 'quarantine', 'expectedVersion' => 0, 'qrToken' => $m4['qr']]), 'hold at packing');
$error($post($finisher, $m4['path'] . '/claim', ['expectedVersion' => (int) $state($m4)['version']]), 409, 'ORDER_PIECES_HELD', 'a physically held piece blocks packing');
$ok($post($iuliana, "/production-units/{$unit4}/custody", ['action' => 'release', 'expectedVersion' => 1, 'qrToken' => $m4['qr']]), 'explicit release');
$beforeGate4 = $state($m4);
$missingLog4 = $rows("SELECT * FROM production_unit_stage_log WHERE unit_uuid = ? AND stage_id = 'material-preparation'", [$unit4])[0];
$pdo->prepare('DELETE FROM production_unit_stage_log WHERE log_uuid = ?')->execute([$missingLog4['log_uuid']]);
$error($post($finisher, $m4['path'] . '/claim', ['expectedVersion' => (int) $beforeGate4['version']]), 409, 'ORDER_ROUTE_INCOMPLETE', 'packing refuses a missing applicable current-cycle completion');
$check($state($m4) === $beforeGate4, 'failed packing leaves stage, ownership and version unchanged');
$pdo->prepare('INSERT INTO production_unit_stage_log (' . implode(', ', array_keys($missingLog4)) . ') VALUES (' . implode(', ', array_fill(0, count($missingLog4), '?')) . ')')->execute(array_values($missingLog4));
$pdo->prepare("UPDATE production_work_units SET status = 'retired', retired_at = UTC_TIMESTAMP(6) WHERE unit_uuid = ?")->execute([$unit4]);
$error($post($finisher, $m4['path'] . '/claim', ['expectedVersion' => (int) $beforeGate4['version']]), 409, 'ORDER_PIECES_INCOMPLETE', 'packing refuses a missing deliverable slot');
$pdo->prepare("UPDATE production_work_units SET status = 'active', retired_at = NULL WHERE unit_uuid = ?")->execute([$unit4]);
$claim($finisher, $m4);
$ok($post($iuliana, "/production-units/{$unit4}/custody", ['action' => 'hold', 'holdReason' => 'inspection', 'expectedVersion' => 2, 'qrToken' => $m4['qr']]), 'hold after packing was claimed');
$beforeHeld4 = $state($m4);
$error($post($finisher, $m4['path'] . '/transition', ['expectedVersion' => (int) $beforeHeld4['version']]), 409, 'ORDER_PIECES_HELD', 'a newly held piece also blocks an already owned packing completion');
$check($state($m4) === $beforeHeld4, 'held packing does not advance or release the actor');
$ok($post($iuliana, "/production-units/{$unit4}/custody", ['action' => 'release', 'expectedVersion' => 3, 'qrToken' => $m4['qr']]), 'release before packing completion');
$advance($finisher, $m4);
$check($state($m4)['stage'] === 'delivery', '32. packing is possible once every piece completed its own route');

// ==== M5. Unknown classification / unconfirmed plans keep the full route ===========================================
$m5 = $trendyol('G', [$line('Produs fără clasificare', 'U-1', 1, 2.0, null)]);
$cohort($m5, FeatureActivation::PIECE_ROUTING);
$check($planOf($iuliana, $m5)['lines'][0]['plan']['productType'] === 'unknown' && $planOf($iuliana, $m5)['lines'][0]['typeVerified'] === false, 'no classification without a source classification (never from the title)');
$cut($murat, $m5);
$bring($m5, 'bottom-hem', $staffBy);
$d5 = $decisions($m5);
$check(count($d5) === 1 && $d5[0]['kind'] === 'full_route_unverified' && $d5[0]['reason'] === 'classification_unverified' && $d5[0]['to_stage_id'] === 'bottom-hem', 'unknown type: safe full route, verification recorded: ' . json_encode($d5));
$m5b = $trendyol('H', [$line('Perdea', 'P-500', 1, 2.0, 'curtain'), $line('Draperie', 'D-500', 1, 3.0, 'drapery')]);
$cohort($m5b, FeatureActivation::PIECE_ROUTING);
$cut($murat, $m5b);
$bring($m5b, 'bottom-hem', $staffBy);
$d5b = $decisions($m5b);
$check(count($d5b) === 1 && $d5b[0]['kind'] === 'full_route_unverified' && $d5b[0]['reason'] === 'plan_unverified' && (int) $one('SELECT COUNT(*) FROM production_tracks WHERE order_uuid = ?', [$m5b['uuid']]) === 0, 'mixed without confirmed pieces: no split, the full route, verification recorded');

// ==== M6. PERDEA rework with a full replacement ======================================================================
$m6 = $trendyol('I', [$line('Perdea in 5 m', 'P-600', 1, 5.0, 'curtain')]);
$cohort($m6, FeatureActivation::PIECE_ROUTING, FeatureActivation::PARTIAL_REWORK);
$ok($confirm($denisa, $m6, $m6['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 2, 'equalSplit' => true, 'note' => 'Două panouri egale, verificat pe fișa de croială'], null, true), '12. the manager confirms the plan from Dashboard');
$cut($murat, $m6);
$bring($m6, 'ironing', $staffBy);
$check(array_slice($activity($m6), -1) === ['material-straightening>ironing'], '5. PERDEA normal route 5 -> 8');
$claim($kadyrova, $m6);
$p6 = $planOf($iuliana, $m6)['lines'][0]['pieces'];
[$pa, $pb] = [$p6[0], $p6[1]];
$check($pa['fabricMeters'] === '2.500' && $pb['fabricMeters'] === '2.500', 'equal pieces confirmed: 2.5 m each, only because the plan says so');
$x6 = $ok($report($kadyrova, $m6, ['units' => [$pa['id']], 'comment' => 'Pată pe panou']), '1. the stage-8 worker reports the exact piece', 201);
$t6 = $x6['orderScope']['trackId'];
$labelA = (string) $ok($get($iuliana, "/production-tracks/{$t6}/labels"), 'labels')['labels'][0]['payload'];
// Healthy companion B: the supervisor takes it for inspection at her table. No stage, owner or version changes.
$before = $state($m6);
$error($post($iuliana, "/production-units/{$pb['id']}/custody", ['action' => 'take', 'expectedVersion' => 0, 'locationId' => 'masa-inexistenta', 'qrToken' => $m6['qr']]), 422, 'LOCATION_UNKNOWN', '21. an unregistered location is refused');
$error($post($iuliana, "/production-units/{$pb['id']}/custody", ['action' => 'take', 'expectedVersion' => 0, 'locationId' => $supervisorLocation, 'qrToken' => $labelA]), 422, 'UNIT_LABEL_MISMATCH', 'the label of the other piece is refused');
$cb = $ok($post($iuliana, "/production-units/{$pb['id']}/custody", ['action' => 'take', 'expectedVersion' => 0, 'locationId' => $supervisorLocation, 'qrToken' => $m6['qr']]), '22. the supervisor physically takes the healthy piece');
$ok($post($iuliana, "/production-units/{$pb['id']}/custody", ['action' => 'hold', 'expectedVersion' => 1, 'holdReason' => 'inspection', 'qrToken' => $m6['qr']]), '22. held for inspection');
$check($cb['custody']['custodian']['displayName'] === 'VASILOAIA IULIANA' && $cb['custody']['location']['id'] === $supervisorLocation && $cb['productionStageId'] === 'ironing', '22. custodian and location recorded; the production stage stays');
$after = $state($m6);
$check($after['stage'] === $before['stage'] && $after['owner'] === $before['owner'] && $after['version'] === $before['version'], '22. a custody change is not a production transition');
$check((int) $one('SELECT COUNT(*) FROM production_exception_units WHERE unit_uuid = ?', [$pb['id']]) === 0, '22. inspecting the healthy piece does not make it defective');
$ok($post($kadyrova, "/production-units/{$pb['id']}/custody", ['action' => 'locate', 'expectedVersion' => 2, 'locationId' => $ironingLocation, 'qrToken' => $m6['qr']]), '21. the healthy piece waits on the stage-8 rail');
$error($post($kadyrova, "/production-units/{$pb['id']}/custody", ['action' => 'locate', 'expectedVersion' => 2, 'locationId' => $ironingLocation, 'qrToken' => $m6['qr']]), 409, 'CUSTODY_CHANGED', 'a stale custody version is refused');
// Supervisor, return, receipt; the cutter cannot start before the approval.
$ok($review($iuliana, $x6['id'], ['decision' => 'accept', 'reasonKey' => 'material-defect', 'qrToken' => $labelA]), '10. the supervisor accepts the exact piece');
$ok($post($murat, '/auth/logout', []), '18. cutter signs out before the physical return is requested');
$ok($sendReturn($iuliana, $x6['id']), '10. return addressed to the cutter');
$check($one('SELECT status FROM production_exceptions WHERE exception_uuid = ?', [$x6['id']]) === 'awaiting_acknowledgment'
    && (int) $one('SELECT COUNT(*) FROM production_exception_decisions WHERE exception_uuid = ?', [$x6['id']]) === 0, '18. offline cutter does not prevent the committed return and does not create a receipt or manager decision');
$murat = T::login($kernel, "murat.{$suffix}", $password) + ['name' => 'REJEPOV AYMYRAT'];
$offlineReturn6 = $ok($get($murat, "/production-exceptions/{$x6['id']}"), '18. new cutter session reloads the committed physical return');
$check($offlineReturn6['status'] === 'awaiting_acknowledgment' && $offlineReturn6['actions']['canAcknowledge'] === true && $offlineReturn6['orderScope']['affectedQuantity'] === 1, '18. re-login restores the exact pending piece and explicit receipt action');
$error($trackClaim($murat, $t6, $labelA), 409, 'ORDER_BLOCKED_BY_EXCEPTION', 'the cutter cannot start before the receipt and the approval');
$ack6 = $ok($ack($murat, $x6['id'], $labelA, ['responsibility' => 'disputed', 'comment' => 'Defect de material, nu de croială']), '11. the cutter confirms receipt with the piece label');
$check($ack6['status'] === 'awaiting_approval' && (int) $one("SELECT COUNT(*) FROM production_exception_decisions WHERE exception_uuid = ? AND status = 'pending'", [$x6['id']]) === 1, '11. exactly one pending decision');
$error($trackClaim($murat, $t6, $labelA), 409, 'ORDER_BLOCKED_BY_EXCEPTION', 'receipt is not an approval: still no cutting');
$queue = $ok($dashGet($denisa, '/management/production-exceptions', ['view' => 'pending']), '12. Denisa sees the request')['items'] ?? [];
$check(in_array($x6['id'], array_column($queue, 'id'), true), '12. the eligible manager sees the pending decision without a direct manager');
$ok($decide($denisa, $x6['id'], ['decision' => 'approve', 'attribution' => 'none']), '13. Denisa approves');
$features->setCohortMember(FeatureActivation::PIECE_ROUTING, $m6['uuid'], false, 'test-operator', 'finish existing route with activation disabled', $now());
$tr6 = $track($t6);
$check($tr6['stage_id'] === 'material-preparation' && $tr6['owner_employee_uuid'] === $uuids['murat'] && (int) $tr6['rework_cycle'] === 2, '13. only the defective piece is at stage 2, owned by the cutter, cycle 2');
$check($state($m6)['stage'] === 'ironing' && $state($m6)['owner'] === $uuids['kadyrova'], '13. the healthy piece keeps stage 8 and its owner');
// Re-cut: a full replacement.
$error($trackComplete($murat, $t6), 422, 'PIECE_OUTCOME_REQUIRED', '25/26. the cutter must say whether the piece was repaired or replaced');
$cutKey = $key('recut');
$cutVersion = $trackVersion($t6);
$recut = $ok($trackComplete($murat, $t6, ['pieceOutcome' => 'replacement', 'originalDisposition' => 'scrapped'], $cutKey), '25. the defective piece is replaced');
$check($ok($post($murat, "/production-tracks/{$t6}/complete", ['expectedVersion' => $cutVersion, 'pieceOutcome' => 'replacement', 'originalDisposition' => 'scrapped'], $cutKey), 'replay') === $recut, '19. a retried re-cut (lost answer) replays the committed result; no second replacement');
$lineage = $rows('SELECT replacement_unit_uuid, original_unit_uuid, slot_index, original_disposition, rework_cycle FROM production_unit_replacements WHERE order_uuid = ?', [$m6['uuid']]);
$check(count($lineage) === 1 && $lineage[0]['original_unit_uuid'] === $pa['id'] && (int) $lineage[0]['slot_index'] === 1 && $lineage[0]['original_disposition'] === 'scrapped' && (int) $lineage[0]['rework_cycle'] === 2, '25. one replacement, linked to the original, same slot');
$newA = (string) $lineage[0]['replacement_unit_uuid'];
$check($one('SELECT status FROM production_work_units WHERE unit_uuid = ?', [$pa['id']]) === 'retired' && $one('SELECT status FROM production_work_units WHERE unit_uuid = ?', [$newA]) === 'active' && $one('SELECT track_uuid FROM production_work_units WHERE unit_uuid = ?', [$newA]) === $t6, '25. the original is retired; the replacement travels on the rework track');
$check((int) $one("SELECT COUNT(*) FROM production_work_units WHERE order_uuid = ? AND status = 'active'", [$m6['uuid']]) === 2, '25. exactly two deliverable pieces');
$check((string) $one("SELECT status FROM production_unit_labels WHERE unit_uuid = ?", [$pa['id']]) === 'revoked', '27. the original label is revoked');
$newLabel = 'ARASYA:U1:' . (string) $one("SELECT label_reference FROM production_unit_labels WHERE unit_uuid = ? AND status = 'active'", [$newA]);
$check(strlen($newLabel) === 36, '25. the replacement has its own label');
$error($trackClaim($tailor2, $t6, $labelA), 422, 'INVALID_QR', '27. the obsolete label authorizes nothing');
$error($post($tailor2, '/orders/resolve-qr', ['token' => $labelA]), 410, 'EXPIRED_QR', '27. scanning the obsolete label opens nothing');
$error($post($iuliana, "/production-units/{$pa['id']}/custody", ['action' => 'locate', 'expectedVersion' => 0, 'locationId' => $supervisorLocation, 'qrToken' => $labelA]), 409, 'UNIT_RETIRED', '27. a retired piece accepts no custody action');
$check($ledger($newA, 2) === ['material-preparation' => 'completed'], 'the replacement starts its ledger at cutting in cycle 2');
$material = $rows('SELECT kind, quantity, stock_effect FROM production_material_records WHERE order_uuid = ?', [$m6['uuid']]);
$check($material === [['kind' => 'replacement_planned', 'quantity' => '2.500', 'stock_effect' => 'none']], '28. the planned replacement fabric is recorded once, without stock effect: ' . json_encode($material));
// Material: actual and waste, each once.
$matKey = $key('mat');
$m1rec = $ok($post($murat, "/production-units/{$newA}/material", ['kind' => 'replacement_actual', 'meters' => '2.600'], $matKey), '28. actual fabric of the replacement', 201);
$check($ok($post($murat, "/production-units/{$newA}/material", ['kind' => 'replacement_actual', 'meters' => '2.600'], $matKey), 'replay', 201) === $m1rec, '28. a retry replays the record');
$error($post($murat, "/production-units/{$newA}/material", ['kind' => 'replacement_actual', 'meters' => '2.600']), 409, 'MATERIAL_ALREADY_RECORDED', '28. no second deduction with another key');
$error($post($murat, "/production-units/{$newA}/material", ['kind' => 'actual', 'meters' => '2.600']), 422, 'MATERIAL_KIND_MISMATCH', '28. changing the kind cannot consume replacement fabric twice');
$ok($post($murat, "/production-units/{$pa['id']}/material", ['kind' => 'waste', 'meters' => '2.500', 'note' => 'panou pătat, casat']), '28. waste of the scrapped original', 201);
$error($post($labeler, "/production-units/{$newA}/material", ['kind' => 'waste', 'meters' => '0.100']), 403, 'UNAUTHORIZED_ACTION', 'only cutting or the supervisor records fabric');
$check($rows('SELECT quantity, meters FROM operational_order_items WHERE order_uuid = ?', [$m6['uuid']]) === [['quantity' => 1, 'meters' => '5.000']], '28. the commercial line (quantity, meters) is unchanged');
$check((int) $one("SELECT COUNT(*) FROM production_material_records WHERE order_uuid = ? AND stock_effect <> 'none'", [$m6['uuid']]) === 0, '28. no warehouse stock is changed');
// Healthy piece B continues on its own (23) while the replacement is replayed.
$ok($post($iuliana, "/production-units/{$pb['id']}/custody", ['action' => 'release', 'expectedVersion' => 3, 'qrToken' => $m6['qr']]), 'inspection hold released');
// Replay 3 -> 4 -> 5 -> 8 (PERDEA): stages 6 and 7 not applicable in cycle 2 too.
$guard = 0;
while (($stage = $track($t6)['stage_id']) !== 'ironing') {
    $who = $staffBy($stage);
    $ok($trackClaim($who, $t6, $newLabel), "replacement claimed at {$stage}");
    $ok($trackComplete($who, $t6), "replacement completed at {$stage}");
    $check(++$guard < 10, 'terminates');
}
$completedT6 = array_map(static fn (array $e): string => $e['from_stage_id'] . '>' . $e['to_stage_id'], $rows("SELECT from_stage_id, to_stage_id FROM production_track_events WHERE track_uuid = ? AND action = 'stage_completed' ORDER BY version_after", [$t6]));
$check($completedT6 === ['material-preparation>workshop-receiving', 'workshop-receiving>labeling', 'labeling>material-straightening', 'material-straightening>ironing'], '8. PERDEA rework replay 2 -> 3 -> 4 -> 5 -> 8: ' . json_encode($completedT6));
$cycle2 = $ledger($newA, 2);
$check(($cycle2['bottom-hem'] ?? null) === 'not_applicable' && ($cycle2['side-hem'] ?? null) === 'not_applicable' && ($cycle2['material-straightening'] ?? null) === 'completed' && count($cycle2) === 6, '8. cycle 2 ledger of the replacement: 2,3,4,5 completed, 6,7 not applicable: ' . json_encode($cycle2));
$check(array_unique(array_map('intval', array_column($rows("SELECT rework_cycle FROM production_track_events WHERE track_uuid = ? AND action = 'stage_completed'", [$t6]), 'rework_cycle'))) === [2], 'every replay completion belongs to cycle 2');
// 20. Stage 8: the defect is still there -> no false resolution; then corrected.
$ok($trackClaim($ironer, $t6, $newLabel), 'ironing claimed on the replacement');
$error($trackComplete($ironer, $t6), 422, 'DEFECT_RESOLUTION_REQUIRED', '20. without the confirmation the defect is not marked resolved');
$check($track($t6)['resolution_confirmed_at'] === null && $track($t6)['stage_id'] === 'ironing', '20. nothing moved');
// 24. Physical reunion at stage 8 before the corrected piece moves on: location event only.
$tracksBefore = (int) $one("SELECT COUNT(*) FROM production_tracks WHERE order_uuid = ? AND status = 'active'", [$m6['uuid']]);
$reunion = $ok($post($kadyrova, '/production-units/reunite', ['unitIds' => [$newA, $pb['id']], 'locationId' => $ironingLocation, 'confirmed' => true]), '24. the corrected piece and its companion are physically together');
$check(count($reunion['reunited']) === 2 && (int) $one("SELECT COUNT(*) FROM production_custody_events WHERE order_uuid = ? AND action = 'reunited'", [$m6['uuid']]) === 2, '24. a reunion event per piece');
$check((int) $one("SELECT COUNT(*) FROM production_tracks WHERE order_uuid = ? AND status = 'active'", [$m6['uuid']]) === $tracksBefore && $track($t6)['stage_id'] === 'ironing', '24. no premature logical merge, no stage change');
$ok($trackComplete($ironer, $t6, ['defectResolved' => true]), '20. the original defect is confirmed resolved at stage 8');
$check($track($t6)['resolution_confirmed_by_employee_uuid'] === $uuids['ironer'], 'the resolution is attributed');
// 23. The healthy piece (order) advances to stage 9 on its own and to packing; packing waits for the replacement.
$advance($kadyrova, $m6);
$check($state($m6)['stage'] === 'height', '23. the healthy piece reaches stage 9 while the replacement is elsewhere');
$bring($m6, 'packing', $staffBy);
$error($post($finisher, $m6['path'] . '/claim', ['expectedVersion' => (int) $state($m6)['version']]), 409, 'ORDER_PIECES_IN_REWORK', '31. packing refused while a piece is in rework');
$guard = 0;
while (($stage = $track($t6)['stage_id']) !== 'packing') {
    $ok($trackClaim($finisher, $t6, $newLabel), "replacement claimed at {$stage}");
    $ok($trackComplete($finisher, $t6), "replacement completed at {$stage}");
    $check(++$guard < 10, 'terminates');
}
$check($track($t6)['status'] === 'merged', 'the replacement rejoins at packing');
$claim($finisher, $m6);
$advance($finisher, $m6);
$claim($finisher, $m6);
$advance($finisher, $m6);
$check($state($m6)['completed'] !== null, '32. the order completes with exactly its two valid pieces');
$check($rows("SELECT COUNT(*) AS n FROM production_work_units WHERE order_uuid = ? AND status = 'active'", [$m6['uuid']])[0]['n'] == 2 && (int) $one('SELECT COUNT(*) FROM operational_orders WHERE global_order_id = ?', [$m6['id']]) === 1, '25. two deliverable pieces, one commercial order');
$history = $ok($get($iuliana, "/production-units/{$pa['id']}/history"), 'history of the original');
$check($history['status'] === 'retired' && $history['replacedBy'] === $newA && $history['labels'][0]['status'] === 'revoked' && count($history['material']) === 1, 'the original incident stays auditable');
$check(count($history['stageLedger']) > 0 && count(array_filter($history['stageLedger'], static fn (array $event): bool => $event['outcome'] === 'completed' && $event['employee'] !== null && $event['versionBefore'] !== null)) > 0, 'history contains actual actor/version completions');

// ==== M7. Administrative recovery (EX-000001 shape): a withdrawn return, then the exact piece ======================
$m7 = $trendyol('J', [$line('Draperie de Textura Nisip Turcesc Ivory', '12754-21', 1, null, 'drapery'), $line('Alt produs conform', 'D-701', 1, null, 'drapery')]);
$cut($murat, $m7);
$bring($m7, 'ironing', $staffBy);
$claim($kadyrova, $m7);
$x7 = $ok($report($kadyrova, $m7, ['itemIds' => [$m7['items'][0]]]), 'an older client reports the whole line (plan unknown)', 201);
$check($x7['orderScope']['piecesRecorded'] === false && $x7['orderScope']['wholeOrderMoves'] === true, 'without a plan the report keeps its order-level meaning');
$ok($review($iuliana, $x7['id'], ['decision' => 'accept', 'reasonKey' => 'material-defect', 'qrToken' => $m7['qr']]), 'supervisor accepts');
$check((int) $one("SELECT COUNT(*) FROM production_quality_events WHERE order_uuid = ? AND event_type = 'fault_detected'", [$m7['uuid']]) === 1, 'detection credited once');
$ok($sendReturn($iuliana, $x7['id']), 'return to the cutter');
$ok($restore($iuliana, $x7['id'], $m7['qr'], 'Retrimis din greșeală', ['cancelReturn' => true]), 'the return is withdrawn (the incident)');
$beforeEarly7 = $state($m7);
$error($confirm($iuliana, $m7, $m7['items'][0], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 2, 'note' => 'Prea devreme']), 409, 'PLAN_BLOCKED_BY_EXCEPTION', 'EX-000001: no plan while the order waits at the supervisor stage (its ledger would start there)');
$check($state($m7) === $beforeEarly7 && (int) $one('SELECT COUNT(*) FROM production_work_units WHERE order_uuid = ?', [$m7['uuid']]) === 0, 'EX-000001: the refused confirmation materializes nothing');
$ok($receive($kadyrova, $x7['id'], $m7['qr']), 'the worker receives the order back');
$check($one('SELECT status FROM production_exceptions WHERE exception_uuid = ?', [$x7['id']]) === 'restored' && $state($m7)['stage'] === 'ironing', 'the earlier request is preserved as restored');
$ok($confirm($iuliana, $m7, $m7['items'][0], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 2, 'note' => 'Două bucăți, confirmat fizic']), 'the supervisor confirms the two physical pieces');
$ok($confirm($iuliana, $m7, $m7['items'][1], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 1, 'note' => 'Alt produs, o bucată conformă']), 'the healthy other line has its own plan');
$cohort($m7, FeatureActivation::PARTIAL_REWORK);
$p7 = $planOf($kadyrova, $m7)['lines'][0]['pieces'];
$foreignPiece7 = $planOf($kadyrova, $m7)['lines'][1]['pieces'][0]['id'];
$error($report($kadyrova, $m7, ['units' => [$foreignPiece7], 'recoversExceptionId' => $x7['id'], 'comment' => 'Alt produs, alt incident']), 422, 'RECOVERY_TARGET_INVALID', 'recovery cannot hide a different product defect under original penalty lineage');
$error($report($kadyrova, $m7, ['units' => [$p7[0]['id']], 'recoversExceptionId' => $x7['id']]), 422, 'COMMENT_REQUIRED', 'a recovery is explained');
$error($report($kadyrova, $m7, ['units' => [$p7[0]['id']], 'recoversExceptionId' => $x6['id'], 'comment' => 'alt ordin']), 422, 'RECOVERY_TARGET_INVALID', 'a request of another order cannot be recovered');
$x7b = $ok($report($kadyrova, $m7, ['units' => [$p7[0]['id']], 'recoversExceptionId' => $x7['id'], 'comment' => 'Recuperare administrativă: aceeași bucată defectă']), '34. the worker names the exact piece and the recovered request', 201);
$check($x7b['administrativeRecovery']['recovers'] === $x7['id'], '34. the recovery link is part of the request');
$ok($review($iuliana, $x7b['id'], ['decision' => 'accept', 'reasonKey' => 'material-defect', 'qrToken' => (string) $ok($get($iuliana, "/production-tracks/{$x7b['orderScope']['trackId']}/labels"), 'labels')['labels'][0]['payload']]), 'supervisor accepts the recovery');
$check((int) $one("SELECT COUNT(*) FROM production_quality_events WHERE order_uuid = ? AND event_type = 'fault_detected'", [$m7['uuid']]) === 1, '34. the same physical defect is not credited twice');
$check(json_decode((string) $one("SELECT metadata_json FROM production_exception_events WHERE exception_uuid = ? AND action = 'supervisor_accepted'", [$x7b['id']]), true)['detectionCredited'] === false, '34. the suppression is recorded');
$error($report($kadyrova, $m7, ['units' => [$p7[1]['id']], 'recoversExceptionId' => $x7['id'], 'comment' => 'încă o dată']), 409, 'RECOVERY_ALREADY_EXISTS', '34. one recovery per incident');

// ==== M8. Source re-sync: no duplicate pieces; a quantity change needs a new confirmation =========================
$number = "PM8{$suffix}";
$payloadLine = static fn (int $quantity, string $name): array => [['id' => 801, 'line' => 1, 'name' => $name, 'sku' => 'R-800', 'color' => 'Gri', 'width' => 300, 'height' => 250, 'unit' => 'cm', 'meters' => 6.0, 'quantity' => $quantity]];
$ok(T::ingest($kernel, 'trendhome', T::sourceOrder($number, "evt-pm8a-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 300), T::stage('material-preparation'), 'processing', 'active', $payloadLine(2, 'Draperie R'), $number)), 'ingest');
$m8 = ['id' => "trendhome:{$number}", 'path' => '/orders/' . rawurlencode("trendhome:{$number}")];
$m8['uuid'] = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$m8['id']]);
$item8 = (string) $one('SELECT item_uuid FROM operational_order_items WHERE order_uuid = ?', [$m8['uuid']]);
$check($planOf($iuliana, $m8)['lines'][0]['plan']['productType'] === 'unknown', 'WooCommerce sends no production type: unknown, never guessed');
$ok($confirm($iuliana, $m8, $item8, ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 2, 'equalSplit' => true, 'note' => 'două bucăți egale']), 'confirmed');
$ids8 = array_column($rows("SELECT unit_uuid FROM production_work_units WHERE order_uuid = ? AND status = 'active' ORDER BY unit_index", [$m8['uuid']]), 'unit_uuid');
$ok(T::ingest($kernel, 'trendhome', T::sourceOrder($number, "evt-pm8b-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 200), T::stage('material-preparation'), 'processing', 'active', $payloadLine(2, 'Draperie R (titlu nou)'), $number)), 're-sync with a changed title');
$ok(T::ingest($kernel, 'trendhome', T::sourceOrder($number, "evt-pm8b-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 200), T::stage('material-preparation'), 'processing', 'active', $payloadLine(2, 'Draperie R (titlu nou)'), $number)), 'duplicate delivery');
$check($item8 === (string) $one('SELECT item_uuid FROM operational_order_items WHERE order_uuid = ?', [$m8['uuid']]) && array_column($rows("SELECT unit_uuid FROM production_work_units WHERE order_uuid = ? AND status = 'active' ORDER BY unit_index", [$m8['uuid']]), 'unit_uuid') === $ids8, '29. re-sync keeps the line and both piece identities, no duplicate');
$ok(T::ingest($kernel, 'trendhome', T::sourceOrder($number, "evt-pm8c-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 100), T::stage('material-preparation'), 'processing', 'active', $payloadLine(3, 'Draperie R (titlu nou)'), $number)), 'the source changes the quantity');
$stale = $planOf($iuliana, $m8)['lines'][0];
$check($stale['staleAfterSourceChange'] === true && $stale['planKnown'] === false && $stale['pieces'] === [], '29. a changed commercial quantity needs a new confirmation; pieces are not used meanwhile');
$check((int) $one("SELECT COUNT(*) FROM production_work_units WHERE order_uuid = ? AND status = 'active'", [$m8['uuid']]) === 2, '29. nothing is added or retired by the sync itself');
$re = $ok($confirm($iuliana, $m8, $item8, ['expectedRevision' => 1, 'productType' => 'draperii', 'pieceCount' => 3, 'equalSplit' => true, 'note' => 'trei bucăți după modificarea comenzii']), 'reconfirmed');
$check(array_slice(array_column($re['lines'][0]['pieces'], 'id'), 0, 2) === $ids8 && count($re['lines'][0]['pieces']) === 3 && $re['lines'][0]['plan']['revision'] === 2, '29. existing identities stay; one new piece; the revision is audited');

$oldSourceHash8 = $planOf($iuliana, $m8)['lines'][0]['sourcePlanHash'];
$changedMeasurements = $payloadLine(3, 'Draperie R (titlu nou)');
$changedMeasurements[0]['height'] = 270;
$ok(T::ingest($kernel, 'trendhome', T::sourceOrder($number, "evt-pm8d-{$suffix}", gmdate('Y-m-d\TH:i:s\Z'), T::stage('material-preparation'), 'processing', 'active', $changedMeasurements, $number)), 'measurement change with unchanged commercial quantity');
$error($confirm($iuliana, $m8, $item8, ['expectedSourceHash' => $oldSourceHash8, 'expectedRevision' => 2, 'productType' => 'draperii', 'pieceCount' => 3, 'note' => 'confirmare veche']), 409, 'SOURCE_PLAN_CHANGED', 'source changes after display reject stale confirmation');
$check($planOf($iuliana, $m8)['lines'][0]['staleAfterSourceChange'] === true, 'source measurement changes invalidate the confirmed plan');
$error($post($murat, $m8['path'] . '/claim', ['expectedVersion' => (int) $state($m8)['version']]), 409, 'PLAN_CONFIRMATION_REQUIRED', 'source change blocks physical production until authoritative reconfirmation');
$check(array_column($rows("SELECT unit_uuid FROM production_work_units WHERE order_uuid = ? AND status = 'active' ORDER BY unit_index", [$m8['uuid']]), 'unit_uuid') === array_column($re['lines'][0]['pieces'], 'id'), 'measurement sync preserves physical identities');
$ok($confirm($iuliana, $m8, $item8, ['expectedRevision' => 2, 'productType' => 'draperii', 'pieceCount' => 3, 'equalSplit' => true, 'note' => 'Măsurile verificate înainte de tăiere']), 'unused physical plan may be reconciled before work');
$claim($murat, $m8);
$advance($murat, $m8);
$revisionBefore8 = $planOf($iuliana, $m8)['lines'][0]['plan']['revision'];
$changedMeasurements[0]['height'] = 280;
$ok(T::ingest($kernel, 'trendhome', T::sourceOrder($number, "evt-pm8e-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() + 1), T::stage('material-preparation'), 'processing', 'active', $changedMeasurements, $number)), 'source changes measurements after physical cutting');
$error($confirm($iuliana, $m8, $item8, ['expectedRevision' => $revisionBefore8, 'productType' => 'draperii', 'pieceCount' => 3, 'equalSplit' => true, 'note' => 'Nu transfera etapele vechi']), 409, 'PLAN_PIECE_IN_USE', 'used production evidence cannot silently validate a different source revision');
$check($planOf($iuliana, $m8)['lines'][0]['plan']['revision'] === $revisionBefore8 && $planOf($iuliana, $m8)['lines'][0]['staleAfterSourceChange'] === true, 'unsafe source revision leaves the old identity/plan and reconciliation gate intact');

// ==== M9. Concurrency ================================================================================================
$m9 = $trendyol('K', [$line('Draperie concurentă', 'C-900', 2, 4.0, 'drapery')]);
$body9 = ['expectedSourceHash' => $planOf($iuliana, $m9)['lines'][0]['sourcePlanHash'], 'expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 2, 'equalSplit' => true, 'note' => 'confirmare concurentă'];
$results = $race([
    [$denisa, $m9['path'] . '/manufacturing-plan/lines/' . $m9['items'][0] . '/confirm', $body9, $key('race-a'), DASHBOARD],
    [$hikmet, $m9['path'] . '/manufacturing-plan/lines/' . $m9['items'][0] . '/confirm', $body9 + ['note' => 'confirmare concurentă B'], $key('race-b'), DASHBOARD],
]);
$statuses = array_column($results, 'status');
sort($statuses);
$check($statuses === [200, 409], '33. two concurrent confirmations: exactly one wins: ' . json_encode($results));
$check((int) $one('SELECT COUNT(*) FROM production_line_plans WHERE item_uuid = ?', [$m9['items'][0]]) === 1, 'one plan revision');
$units9 = array_column($rows("SELECT unit_uuid FROM production_work_units WHERE order_uuid = ? ORDER BY unit_index", [$m9['uuid']]), 'unit_uuid');
$check(count($units9) === 2, 'two pieces once');
$pdo->prepare('DELETE FROM production_unit_cycles WHERE unit_uuid = ?')->execute([$units9[1]]);
$pdo->prepare('DELETE FROM production_work_units WHERE unit_uuid = ?')->execute([$units9[1]]);
$previewBefore9 = (int) $one('SELECT COUNT(*) FROM production_unit_stage_log');
$pdo->exec('SET TRANSACTION READ ONLY');
$pdo->beginTransaction();
$preview9 = (new \Arasya\Operations\Production\PieceRouting($pdo))->plan((new \Arasya\Operations\Production\PdoProductionWorkflowRepository($pdo))->current(), $m9['uuid'], null, 'material-preparation', '');
$pdo->rollBack();
$check($preview9['next']?->id === 'workshop-receiving' && (int) $one('SELECT COUNT(*) FROM production_unit_stage_log') === $previewBefore9, 'routing preview operates on a database-enforced read-only transaction');
$check((int) $one('SELECT COUNT(*) FROM production_work_units WHERE order_uuid = ?', [$m9['uuid']]) === 1, 'preview cannot silently materialize a missing physical identity');
$backfill9 = $cli('partial-rework.php', 'backfill', '--operator=test-backfill-operator', '--limit=1000');
$audit9 = json_decode((string) $one("SELECT metadata_json FROM iam_audit_events WHERE action = 'production.manifest.backfilled' AND target_id = ? ORDER BY created_at DESC LIMIT 1", [$m9['id']]), true);
$check($backfill9['code'] === 0 && $audit9['operator'] === 'test-backfill-operator' && $audit9['units'] === [$units9[1]] && $audit9['historicalCompletionsInferred'] === false, 'confirmed-only backfill audits the real operator and exact materialized identity without inventing prior work');
$error($post($murat, "/production-units/{$units9[0]}/material", ['kind' => 'actual', 'meters' => '2.000']), 409, 'CUTTING_NOT_AUTHORIZED', 'no actual cutting material before ownership');
$cut($murat, $m9);
$results = $race([
    [$murat, "/production-units/{$units9[0]}/material", ['kind' => 'actual', 'meters' => '2.000'], $key('mrace-a'), null],
    [$murat, "/production-units/{$units9[0]}/material", ['kind' => 'actual', 'meters' => '2.000'], $key('mrace-b'), null],
]);
$statuses = array_column($results, 'status');
sort($statuses);
$check($statuses === [201, 409] && (int) $one("SELECT COUNT(*) FROM production_material_records WHERE unit_uuid = ? AND kind = 'actual'", [$units9[0]]) === 1, '28/33. two concurrent material records: one deduction: ' . json_encode($results));

// The request authenticates before waiting for the order. A revocation committed while it waits must still win.
$pdo->beginTransaction();
$pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE')->execute([$m9['uuid']]);
$process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/b2b-order-worker.php', $dbName, 'POST', "/production-units/{$units9[1]}/material", json_encode(['kind' => 'actual', 'meters' => '2.000']), $key('iam-wait'), $murat['cookie'], $murat['csrf'], (string) microtime(true)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
fclose($pipes[0]);
$waiting9 = false;
$deadline9 = microtime(true) + 5;
while (microtime(true) < $deadline9) {
    $waiting9 = (int) $one("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND DB = DATABASE() AND INFO LIKE '%FROM operational_orders WHERE order_uuid =%FOR UPDATE%'") > 0;
    if ($waiting9) break;
    usleep(20_000);
}
$pdo->prepare("UPDATE employees SET status = 'inactive' WHERE employee_uuid = ?")->execute([$uuids['murat']]);
$pdo->commit();
$iamResult9 = json_decode((string) stream_get_contents($pipes[1]), true);
fclose($pipes[1]);
proc_close($process);
$pdo->prepare("UPDATE employees SET status = 'active' WHERE employee_uuid = ?")->execute([$uuids['murat']]);
$check($waiting9 && ($iamResult9['status'] ?? null) === 403 && (int) $one("SELECT COUNT(*) FROM production_material_records WHERE unit_uuid = ? AND kind = 'actual'", [$units9[1]]) === 0, 'current IAM revocation after the initial piece lookup prevents persisted consumption: ' . json_encode($iamResult9));

// ==== M10. Rollback guard and disabling the features with pieces on tracks ========================================
$m10 = $trendyol('L', [$line('Perdea', 'P-110', 1, 2.0, 'curtain'), $line('Draperie', 'D-110', 1, 2.0, 'drapery')]);
$cohort($m10, FeatureActivation::PIECE_ROUTING);
$ok($confirm($iuliana, $m10, $m10['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'o bucată']), 'plan');
$ok($confirm($iuliana, $m10, $m10['items'][1], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 1, 'note' => 'o bucată']), 'plan');
$cut($murat, $m10);
$bring($m10, 'bottom-hem', $staffBy);
$t10 = (string) $one("SELECT track_uuid FROM production_tracks WHERE order_uuid = ? AND status = 'active'", [$m10['uuid']]);
$check($t10 !== '' && $track($t10)['stage_id'] === 'ironing', 'a route track exists');
$check1 = $cli('partial-rework.php', 'rollback-check');
$check($check1['code'] === 3 && $check1['json']['rollbackToOlderApiSafe'] === false && $check1['json']['activeTracks'] >= 1, '38. an older API must not be activated while pieces are on tracks: ' . $check1['out']);
$status = $cli('partial-rework.php', 'status');
$check($status['code'] === 0 && $status['json']['pieceRouting'] === false && $status['json']['pieceRoutingCohort'] >= 1, 'status reports flags and cohorts');
$check($cli('partial-rework.php', 'routing-enable', '--operator=test')['code'] === 2, 'global activation needs the confirmation phrase');
$removed = $cli('partial-rework.php', 'cohort-remove', '--feature=piece_routing', '--order=' . $m10['id'], '--operator=test-operator', '--reason=rollback drill');
$check($removed['code'] === 0 && $removed['json']['member'] === false && (int) $one("SELECT COUNT(*) FROM production_feature_flag_events WHERE order_uuid = ? AND action = 'cohort_removed'", [$m10['uuid']]) === 1, 'the cohort change is audited: ' . $removed['out'] . $removed['err']);
$ok($trackClaim($ironer, $t10, $m10['qr']), '38. with the feature off for the order, the piece already on its route track keeps working');
$ok($trackComplete($ironer, $t10), '38. ironing completed');
$dry = $cli('partial-rework.php', 'backfill', '--operator=test', '--dry-run=1', '--limit=1000');
$check($dry['code'] === 0 && $dry['json']['dryRun'] === true && $dry['json']['linesNeedingConfirmation'] >= 1 && is_array($dry['json']['samples']), '35. the backfill dry-run lists lines that need a confirmation and invents nothing: ' . substr($dry['out'], 0, 300));
$unitsBefore = (int) $one('SELECT COUNT(*) FROM production_work_units');
$wet = $cli('partial-rework.php', 'backfill', '--operator=test', '--limit=1000');
$check($wet['code'] === 0 && (int) $one('SELECT COUNT(*) FROM production_work_units') === $unitsBefore, '35/37. the backfill writes no piece for an unconfirmed line and re-runs idempotently');

// ==== M11. Whole-carrier physical rework also requires current-cycle defect resolution ==========================
$m11 = $trendyol('M', [$line('Draperie toate bucățile', 'D-111', 1, 5.0, 'drapery')]);
$ok($confirm($iuliana, $m11, $m11['items'][0], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 2, 'note' => 'Două bucăți fizice']), 'whole-carrier physical plan');
$cohort($m11, FeatureActivation::PARTIAL_REWORK);
$cut($murat, $m11);
$bring($m11, 'ironing', $staffBy);
$claim($kadyrova, $m11);
$units11 = array_column($planOf($iuliana, $m11)['lines'][0]['pieces'], 'id');
$x11 = $ok($report($kadyrova, $m11, ['units' => $units11]), 'all physical pieces reported exactly', 201);
$check($x11['orderScope']['carrier'] === 'order' && $x11['orderScope']['partial'] === false, 'same whole-carrier architecture, no duplicate rework system');
$ok($review($iuliana, $x11['id'], ['decision' => 'accept', 'reasonKey' => 'material-defect', 'qrToken' => $m11['qr']]), 'whole-carrier verification');
$ok($sendReturn($iuliana, $x11['id']), 'whole-carrier return');
$ok($ack($murat, $x11['id'], $m11['qr'], ['responsibility' => 'accepted']), 'whole-carrier physical receipt');
$ok($decide($denisa, $x11['id'], ['decision' => 'approve', 'attribution' => 'none']), 'independent manager approves whole-carrier return');
$cutState11 = $state($m11);
$error($post($murat, $m11['path'] . '/transition', ['expectedVersion' => (int) $cutState11['version'], 'pieceOutcomes' => [['unitId' => $units11[0], 'outcome' => 'repair']]]), 422, 'PIECE_OUTCOMES_INVALID', 'mixed physical outcomes must name every affected piece');
$error($post($murat, $m11['path'] . '/transition', ['expectedVersion' => (int) $cutState11['version'], 'pieceOutcome' => 'replacement']), 422, 'ORIGINAL_DISPOSITION_REQUIRED', 'replacement never invents scrapping');
$check($state($m11) === $cutState11 && (int) $one("SELECT COUNT(*) FROM production_work_units WHERE order_uuid = ? AND status = 'active'", [$m11['uuid']]) === 2, 'invalid declaration rolls back without retiring or duplicating either piece');
$advance($murat, $m11, ['pieceOutcomes' => [['unitId' => $units11[0], 'outcome' => 'repair'], ['unitId' => $units11[1], 'outcome' => 'replacement', 'originalDisposition' => 'quarantined']]]);
$check($one('SELECT status FROM production_work_units WHERE unit_uuid = ?', [$units11[0]]) === 'active' && $one('SELECT status FROM production_work_units WHERE unit_uuid = ?', [$units11[1]]) === 'retired' && (int) $one('SELECT COUNT(*) FROM production_unit_replacements WHERE order_uuid = ?', [$m11['uuid']]) === 1, 'repair retains exact identity; only declared replacement retires its original');
$bring($m11, 'ironing', $staffBy);
$claim($ironer, $m11);
$before11 = $state($m11);
$error($post($ironer, $m11['path'] . '/transition', ['expectedVersion' => (int) $before11['version']]), 422, 'DEFECT_RESOLUTION_REQUIRED', 'old-client omission cannot bypass whole-carrier current-cycle correction');
$check($state($m11) === $before11 && (int) $one('SELECT COUNT(*) FROM production_unit_verifications v INNER JOIN production_work_units u ON u.unit_uuid = v.unit_uuid AND u.rework_cycle = v.rework_cycle WHERE v.order_uuid = ? AND u.status = \'active\' AND v.confirmed_at IS NULL', [$m11['uuid']]) === 2, 'failed completion rolls back stage, owner, version and both verifications');
$advance($ironer, $m11, ['defectResolved' => true]);
$check((int) $one('SELECT COUNT(*) FROM production_unit_verifications WHERE order_uuid = ? AND confirmed_at IS NOT NULL AND confirmed_by_employee_uuid = ? AND rework_cycle = 2', [$m11['uuid'], $uuids['ironer']]) === 2, 'both exact pieces confirmed by actual stage-8 actor in current cycle');
$check($cli('check-rollback.php', '2.31.0')['code'] === 3 && $cli('check-rollback.php', '2.32.0')['code'] === 0, 'current-release rollback guard refuses an unaware API and permits the compatibility floor');
$originalChecksum = (string) $one("SELECT checksum FROM schema_migrations WHERE migration_name = '029_production_manifest_routing.sql'");
try {
    $pdo->exec("UPDATE schema_migrations SET checksum = REPEAT('0', 64) WHERE migration_name = '029_production_manifest_routing.sql'");
    $check($cli('check-rollback.php', '2.31.0')['code'] === 1, 'unknown or incompatible current migration refuses rollback');
} finally {
    $pdo->prepare("UPDATE schema_migrations SET checksum = ? WHERE migration_name = '029_production_manifest_routing.sql'")->execute([$originalChecksum]);
}
$pdo->exec('RENAME TABLE production_unit_custody TO roi35_hidden_custody');
try {
    $check($cli('check-rollback.php', '2.31.0')['code'] === 1, 'missing current production table refuses rollback instead of treating missing state as empty');
} finally {
    $pdo->exec('RENAME TABLE roi35_hidden_custody TO production_unit_custody');
}

// ==== M12. Historical order: plan confirmed after cutting, verified evidence, nothing synthesized =================
// Flags off, no plan: the order went through cutting and stages 3-7 at order level only (the legacy behaviour).
$m12 = $trendyol('N', [$line('Draperie istorică', 'D-120', 1, 5.0, 'drapery'), $line('Perdea istorică', 'P-120', 1, 2.0, 'curtain')]);
$cut($murat, $m12);
$bring($m12, 'ironing', $staffBy);
$eventOf12 = array_column($rows("SELECT event_id, from_stage_id FROM order_activity_events WHERE order_uuid = ? AND action = 'stage_completed' ORDER BY production_version_after", [$m12['uuid']]), 'event_id', 'from_stage_id');
$ok($confirm($iuliana, $m12, $m12['items'][0], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 2, 'note' => 'Două bucăți, confirmat la călcare']), 'J. draperii plan confirmed at stage 8 of a historical order');
$ok($confirm($iuliana, $m12, $m12['items'][1], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'O bucată, confirmat la călcare']), 'J. perdea plan confirmed at stage 8');
$lines12 = $planOf($iuliana, $m12)['lines'];
[$h1, $h2] = [$lines12[0]['pieces'][0], $lines12[0]['pieces'][1]];
$hp = $lines12[1]['pieces'][0];
$drapPre = ['material-preparation', 'workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem'];
$check($h1['routeEvidence']['ledgerStartStageId'] === 'ironing' && $h1['routeEvidence']['missingStages'] === $drapPre && count($h1['routeEvidence']['candidates']) === 6, 'J. the plan view shows the pre-ledger gap and the order-level completions as candidates: ' . json_encode($h1['routeEvidence']));
$check($hp['routeEvidence']['missingStages'] === ['material-preparation', 'workshop-receiving', 'labeling', 'material-straightening'], 'J. a PERDEA piece needs no evidence for stages 6-7');
$bring($m12, 'packing', $staffBy);
$error($post($finisher, $m12['path'] . '/claim', ['expectedVersion' => (int) $state($m12)['version']]), 409, 'ORDER_ROUTE_INCOMPLETE', 'J. a historical order without per-piece evidence cannot be packed');
$logBefore12 = (int) $one('SELECT COUNT(*) FROM production_unit_stage_log WHERE order_uuid = ?', [$m12['uuid']]);
$activityBefore12 = (int) $one('SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = ?', [$m12['uuid']]);
$stateBefore12 = $state($m12);
$fromActivity = static fn (array $stages): array => array_map(static fn (string $stage): array => ['stageId' => $stage, 'evidence' => 'order_activity', 'activityEventId' => $eventOf12[$stage]], $stages);
$reconcile = static fn (array $who, string $unit, array $body, ?string $idem = null, bool $dashboard = false): array => $dashboard ? $dash($who, 'POST', "/production-units/{$unit}/route-evidence", $body, $idem) : $post($who, "/production-units/{$unit}/route-evidence", $body, $idem);
$error($reconcile($finisher, $h1['id'], ['stages' => $fromActivity(['material-preparation']), 'note' => 'Am văzut fișa de croială']), 403, 'RECONCILIATION_NOT_ALLOWED', 'J. a stage worker cannot verify historical evidence');
$error($reconcile($iuliana, $h1['id'], ['stages' => [['stageId' => 'ironing', 'evidence' => 'physical_inspection']], 'note' => 'Călcat, verificat fizic']), 422, 'RECONCILIATION_STAGE_INVALID', 'J. the ledger start stage itself cannot be reconciled');
$error($reconcile($iuliana, $h1['id'], ['stages' => [['stageId' => 'height', 'evidence' => 'physical_inspection']], 'note' => 'Etapă ulterioară, verificat']), 422, 'RECONCILIATION_STAGE_INVALID', 'J. a stage after the ledger start cannot be reconciled');
$error($reconcile($iuliana, $hp['id'], ['stages' => [['stageId' => 'bottom-hem', 'evidence' => 'physical_inspection']], 'note' => 'Tiv de jos verificat fizic']), 422, 'RECONCILIATION_STAGE_INVALID', 'J. a stage not applicable to PERDEA is never reconciled as work');
$error($reconcile($iuliana, $h1['id'], ['stages' => [['stageId' => 'material-preparation', 'evidence' => 'order_activity', 'activityEventId' => $eventOf12['labeling']]], 'note' => 'Eveniment greșit ales']), 422, 'RECONCILIATION_EVIDENCE_INVALID', 'J. evidence must be the completion of that very stage');
$error($reconcile($iuliana, $h1['id'], ['stages' => $fromActivity(['material-preparation']), 'note' => 'scurt']), 422, 'COMMENT_REQUIRED', 'J. the reviewed evidence is described');
$error($reconcile($iuliana, $h1['id'], ['stages' => [['stageId' => 'labeling', 'evidence' => 'order_activity']], 'note' => 'Fără eveniment de referință']), 400, 'INVALID_REQUEST', 'J. order_activity evidence names its event');
$k12 = $key('reconcile');
$r12 = $ok($reconcile($iuliana, $h1['id'], ['stages' => $fromActivity($drapPre), 'note' => 'Fișa de producție și finalizările din sistem verificate pentru bucata 1'], $k12), 'J. the supervisor records verified evidence for piece 1', 201);
$check($ok($reconcile($iuliana, $h1['id'], ['stages' => $fromActivity($drapPre), 'note' => 'Fișa de producție și finalizările din sistem verificate pentru bucata 1'], $k12), 'replay', 201) === $r12, 'J. a retried verification (lost answer) replays the committed result');
$error($reconcile($iuliana, $h1['id'], ['stages' => $fromActivity(['labeling']), 'note' => 'Altă cerere cu aceeași cheie'], $k12), 409, 'IDEMPOTENCY_CONFLICT', 'J. a changed request never reuses an old idempotency key');
$check((int) $one('SELECT COUNT(*) FROM production_route_reconciliations WHERE unit_uuid = ?', [$h1['id']]) === 1, 'J. one verification session for the retried command');
$ok($reconcile($iuliana, $h2['id'], ['stages' => $fromActivity(['material-preparation', 'workshop-receiving', 'material-straightening', 'bottom-hem', 'side-hem']), 'uncertainStages' => ['labeling'], 'note' => 'Eticheta bucății 2 nu poate fi confirmată din documente']), 'J. piece 2: one stage stays uncertain', 201);
$ok($reconcile($iuliana, $hp['id'], ['stages' => $fromActivity(['material-preparation', 'workshop-receiving', 'labeling', 'material-straightening']), 'note' => 'Perdea verificată fizic și în istoricul comenzii']), 'J. the PERDEA piece is verified on its own route', 201);
$p12after = $planOf($iuliana, $m12)['lines'][0]['pieces'][1]['routeEvidence'];
$check($p12after['missingStages'] === ['labeling'] && $p12after['uncertainStages'] === ['labeling'], 'J. the uncertain stage is visible and still missing: ' . json_encode($p12after));
$error($post($finisher, $m12['path'] . '/claim', ['expectedVersion' => (int) $state($m12)['version']]), 409, 'ORDER_ROUTE_INCOMPLETE', 'J. an uncertain stage keeps the order blocked');
$error($reconcile($iuliana, $h2['id'], ['stages' => $fromActivity(['material-preparation']), 'note' => 'A doua oară aceeași etapă']), 409, 'RECONCILIATION_STAGE_RECORDED', 'J. a stage is reconciled once per piece and cycle');
$ok($reconcile($denisa, $h2['id'], ['stages' => [['stageId' => 'labeling', 'evidence' => 'physical_inspection', 'activityEventId' => null]], 'note' => 'Eticheta verificată fizic pe bucata 2 de manager'], null, true), 'J. the operations manager verifies the uncertain stage physically (Dashboard)', 201);
$check((int) $one('SELECT COUNT(*) FROM production_unit_stage_log WHERE order_uuid = ?', [$m12['uuid']]) === $logBefore12 && (int) $one('SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = ?', [$m12['uuid']]) === $activityBefore12, 'J. no completion is synthesized and the original history is unchanged');
$check($state($m12) === $stateBefore12, 'J. verification moves nothing');
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'production.route.reconciled' AND target_id = ?", [$m12['id']]) === 4, 'J. every verification is audited with its verifier');
$audit12 = $cli('partial-rework.php', 'route-audit', '--details=1');
$byOrder12 = array_column($audit12['json']['orders'] ?? [], null, 'order');
$check($audit12['code'] === 0 && ($byOrder12[$m12['id']]['class'] ?? null) === 'requires_stage_evidence' && $byOrder12[$m12['id']]['evidenceAvailable'] === true, 'J. route-audit classifies the historical order: ' . substr($audit12['out'] . $audit12['err'], 0, 300));
$check(($byOrder12[$m7['id']]['class'] ?? null) === 'unresolved_exception', 'J. route-audit reports the order with an open exception first');
$m12b = $trendyol('O', [$line('Draperie nouă', 'D-121', 1, 3.0, 'drapery')]);
$check(array_column(json_decode($cli('partial-rework.php', 'route-audit', '--details=1')['out'], true)['orders'], 'class', 'order')[$m12b['id']] === 'requires_plan_confirmation', 'J. a new order before cutting needs only its plan');
$ok($confirm($iuliana, $m12b, $m12b['items'][0], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 2, 'note' => 'Două bucăți înainte de croire']), 'plan before cutting');
$check(array_column(json_decode($cli('partial-rework.php', 'route-audit', '--details=1')['out'], true)['orders'], 'class', 'order')[$m12b['id']] === 'safe_identity_materialization', 'J. confirmed before cutting: safe identity materialization');
$claim($finisher, $m12);
$advance($finisher, $m12);
$check($state($m12)['stage'] === 'delivery', 'J/K. packing is possible once every piece has its own or verified evidence');
$check($cli('check-rollback.php', '2.32.0')['code'] === 0 && $cli('check-rollback.php', '2.31.0')['code'] === 3, 'J. reconciliation evidence is per-piece state for the rollback guard');
$originalChecksum30 = (string) $one("SELECT checksum FROM schema_migrations WHERE migration_name = '030_production_route_reconciliation.sql'");
try {
    $pdo->exec("UPDATE schema_migrations SET checksum = REPEAT('0', 64) WHERE migration_name = '030_production_route_reconciliation.sql'");
    $check($cli('check-rollback.php', '2.32.0')['code'] === 1, 'J. an unknown 030 refuses rollback');
} finally {
    $pdo->prepare("UPDATE schema_migrations SET checksum = ? WHERE migration_name = '030_production_route_reconciliation.sql'")->execute([$originalChecksum30]);
}
$tables12 = (int) $one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
(new SqlFileRunner($pdo))->run($migrations . '/030_production_route_reconciliation.sql');
$check((int) $one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') === $tables12 && (int) $one('SELECT COUNT(*) FROM production_route_reconciliation_stages WHERE order_uuid = ?', [$m12['uuid']]) === 16, 'J. migration 030 re-runs without change');

// ==== M13. Employee KPI: track and piece work apart from commercial completions ===================================
// The test database may hold employees of earlier suites (CI shares it): read every page, then check this suite's people.
$employees13 = [];
$cursor13 = null;
do {
    $page13 = $ok($dashGet($root, '/management/analytics/employees', ['period' => 'month', 'limit' => '100'] + ($cursor13 === null ? [] : ['cursor' => $cursor13])), 'analytics employees');
    foreach ($page13['items'] as $item13) $employees13[$item13['id']] = $item13;
    $cursor13 = $page13['nextCursor'] ?? null;
} while ($cursor13 !== null);
$check(array_diff(array_values($uuids), array_keys($employees13)) === [], 'KPI: every employee of this suite is reported');
$muratRow = $employees13[$uuids['murat']];
$commercialCuts = (int) $one("SELECT COUNT(*) FROM order_activity_events WHERE employee_uuid = ? AND action = 'stage_completed' AND from_stage_id = 'material-preparation'", [$uuids['murat']]);
$check(($muratRow['stageCompletions']['material-preparation'] ?? 0) === $commercialCuts, 'KPI: commercial cutting completions are only order-level completions (rework is not new commercial production)');
$check(($muratRow['trackStageCompletions']['material-preparation']['rework'] ?? 0) >= 1 && $muratRow['pieceWork']['rework'] >= 1, 'KPI: the cutter is credited with the authorized re-cutting as rework work: ' . json_encode([$muratRow['trackStageCompletions'], $muratRow['pieceWork']]));
$trackTotal = 0;
foreach (array_values($uuids) as $id) {
    $row13 = $employees13[$id];
    $logged = (int) $one("SELECT COUNT(*) FROM production_unit_stage_log WHERE employee_uuid = ? AND outcome = 'completed'", [$id]);
    $check($row13['pieceWork']['order'] + $row13['pieceWork']['route'] + $row13['pieceWork']['rework'] === $logged, "KPI: piece work of {$id} equals its own per-piece completions");
    foreach ($row13['trackStageCompletions'] as $kinds) $trackTotal += array_sum($kinds);
}
$marks13 = implode(',', array_fill(0, count($uuids), '?'));
$check($trackTotal === (int) $one("SELECT COUNT(*) FROM production_track_events WHERE action = 'stage_completed' AND employee_uuid IN ({$marks13})", array_values($uuids)), 'KPI: every track completion is attributed exactly once');
$check((int) $one("SELECT COUNT(*) FROM production_unit_stage_log WHERE outcome = 'not_applicable' AND employee_uuid IS NOT NULL") === 0 && ($employees13[$uuids['hemmer']]['pieceWork']['byStage']['bottom-hem']['route'] ?? 0) === 0, 'KPI: a stage not applicable to PERDEA gives nobody credit');
$check($muratRow['faultLines'] === (int) $one("SELECT COALESCE(SUM(line_count), 0) FROM production_quality_events WHERE employee_uuid = ? AND event_type = 'cutting_fault'", [$uuids['murat']]), 'KPI: receiving or repairing a defect adds no fault to the cutter');
$departments13 = $ok($dashGet($root, '/management/analytics/departments', ['period' => 'month', 'limit' => '100']), 'analytics departments')['items'];
$check(array_sum(array_map(static fn (array $d): int => $d['pieceWork']['rework'], $departments13)) === (int) $one("SELECT COUNT(*) FROM production_unit_stage_log l LEFT JOIN production_tracks t ON t.track_uuid = l.carrier_track_uuid WHERE l.outcome = 'completed' AND (l.rework_cycle > 1 OR t.origin_exception_uuid IS NOT NULL)"), 'KPI: department rework totals equal every recorded rework piece completion');

fwrite(STDOUT, "OK MySQL piece manifest and routing integration ({$checks} checks)\n");
