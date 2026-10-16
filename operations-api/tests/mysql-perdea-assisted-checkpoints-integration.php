<?php

declare(strict_types=1);

// Informational PERDEA auxiliary-work status at bottom-hem (6) and side-hem (7), route policy version 2 (migration 031, API 2.34.0),
// against a dedicated MySQL/MariaDB test database, end to end through the HTTP kernel and the operator CLIs. Every
// assertion reads committed rows.
//
//   A0  migration 031 re-run, checksums 028-031, a single 028 prefix
//   A1  PERDEA only: stages 6 and 7 appear; leaving them unmarked, marked or marked not done never blocks (Scenario E);
//       unmarked passage is recorded as unmarked; stage authority, optional scan, stale versions, idempotent replay,
//       unchecking, audited events, the policy sticks to the order; packing and delivery never wait for the status
//   A2  mixed DRAPERII + 2 PERDEA pieces: Scenarios A-D, per-piece and per-stage statuses, DRAPERII completion kept
//   A3  one defective PERDEA piece of two: supervisor, cutter receipt, unauthorized and authorized approval, repair,
//       replay 2..7 without marks, cycle-2 status independent, defect resolution and rework packing gates intact
//   A4  historical transition: a version-1 exemption stays valid, nothing is backfilled or rewritten
//   A5  feature off: orders outside the cohort keep version 1 or the full route exactly as before
//   A6  unconfirmed plan under version 2: line-level information only, nothing invented, nothing blocked
//   A7  concurrent marks, a real physical hold still blocks, deactivation
//   A8  management overview: genuine blockers with who must act, PERDEA status shown separately as information
//   A9  employee KPI: a mark is not manufacturing, no helper credit
//   A10 rollback guards, operator CLI, route audit

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
    fwrite(STDOUT, "SKIP MySQL PERDEA assisted checkpoints integration: ARASYA_TEST_DB_NAME is not configured.\n");
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
$pdo->exec("UPDATE production_feature_flags SET enabled = 0 WHERE flag_key IN ('partial_rework', 'piece_routing', 'perdea_assisted_checkpoints')");

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
$rootName = "assisted.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-assisted-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Manifest root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Manifest root passphrase 2026!');
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string { $keys++; return sprintf('pa-%s-%s-%04d', $label, $suffix, $keys); };
$dash = static fn (array $who, string $method, string $path, ?array $body = null, ?string $idem = null): array => T::call($kernel, $method, $path, $body, ['origin' => DASHBOARD, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'pa-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$dashGet = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => DASHBOARD], $who['cookie'], $query);
$post = static fn (array $who, string $path, array $body, ?string $idem = null): array => T::call($kernel, 'POST', $path, $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'pa-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $query);
$roleIds = [];
foreach ($ok($dashGet($root, '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];

$tailoringStages = ['workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control'];
$uuids = [];
$identity = static function (string $name, string $display, array $stages, array $apps = ['staff'], array $roles = [], string $department = 'pregatire-material') use ($container, $dash, $root, $ok, $password, $suffix, $kernel, &$uuids): array {
    $employee = $container->employeeAdmin()->create($display, "{$name}.{$suffix}", null, $department, 'employee', $password, $stages, 'test-assisted');
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
    $number = "119{$tag}{$suffix}";
    $items = [];
    foreach ($lines as $i => $line) {
        $items[] = new OperationalOrderItem(Uuid::v4(), "line-{$tag}-{$i}", $i + 1, $line['name'], $line['code'], null, $line['color'], $line['width'], $line['height'], 'cm', $line['meters'], $line['quantity'],
            ['notes' => null, 'productionNotes' => null, 'marketplaceTitle' => $line['name'], 'barcode' => null] + ($line['kind'] === null ? [] : ['kind' => $line['kind']]));
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $pdo->beginTransaction();
    $uuid = $container->projectionWriter()->createInternal(new SourceOrderSnapshot('trendyol', "pkg-a{$tag}-{$suffix}", "approval-a{$tag}-{$suffix}", 1, $now, $number, 'waiting', 'Created', 'Created', null, 'in_progress', $now, $items), []);
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
$assisted = FeatureActivation::PERDEA_ASSISTED_CHECKPOINTS;
$checkpoint = static fn (string $unit, int $cycle, string $stage): ?array => $rows('SELECT * FROM production_assisted_checkpoints WHERE unit_uuid = ? AND rework_cycle = ? AND stage_id = ?', [$unit, $cycle, $stage])[0] ?? null;
$cpVersion = static fn (string $unit, int $cycle, string $stage): int => (int) ($checkpoint($unit, $cycle, $stage)['version'] ?? 0);
$cpStatus = static fn (string $unit, int $cycle, string $stage): ?string => $checkpoint($unit, $cycle, $stage)['status'] ?? null;
// One informational mark of a piece at stage 6 / 7: performed, not_performed or unmarked; the scan is optional.
$mark = static function (array $who, string $unit, string $stage, string $status, ?string $qr = null, int $cycle = 1, ?string $idem = null, ?int $version = null) use ($post, $cpVersion): array {
    return $post($who, "/production-units/{$unit}/assisted-checkpoint", ['stageId' => $stage, 'status' => $status, 'expectedVersion' => $version ?? $cpVersion($unit, $cycle, $stage)] + ($qr === null ? [] : ['qrToken' => $qr]), $idem);
};
$stageLog = static fn (string $unit, int $cycle, string $stage): int => (int) $one('SELECT COUNT(*) FROM production_unit_stage_log WHERE unit_uuid = ? AND rework_cycle = ? AND stage_id = ?', [$unit, $cycle, $stage]);
$transition = static fn (array $who, array $o): array => $post($who, $o['path'] . '/transition', ['expectedVersion' => (int) $state($o)['version']]);
$finish = static function (array $o) use ($claim, $advance, $state, $check, $finisher): void {
    $guard = 0;
    while ($state($o)['completed'] === null) {
        $check(in_array($state($o)['stage'], ['packing', 'delivery'], true), 'finish starts at packing');
        $claim($finisher, $o);
        $advance($finisher, $o);
        $check(++$guard < 4, 'finish terminates');
    }
};

// ==== A0. Migration 031 is additive, created disabled and re-runnable =================================================
$check((int) $one("SELECT enabled FROM production_feature_flags WHERE flag_key = 'perdea_assisted_checkpoints'") === 0, '34. the PERDEA status policy is created disabled');
$tables31 = (int) $one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
(new SqlFileRunner($pdo))->run($migrations . '/031_production_assisted_checkpoints.sql');
$check((int) $one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') === $tables31 && (int) $one("SELECT COUNT(*) FROM production_feature_flags WHERE flag_key = 'perdea_assisted_checkpoints'") === 1, '35. migration 031 re-runs: no table or flag twice');
foreach (['028_production_work_units.sql', '029_production_manifest_routing.sql', '030_production_route_reconciliation.sql', '031_production_assisted_checkpoints.sql'] as $file) {
    $check((string) $one('SELECT checksum FROM schema_migrations WHERE migration_name = ?', [$file]) === hash_file('sha256', $migrations . '/' . $file), "35. the recorded {$file} checksum equals the file");
}
$check($rows("SELECT migration_name FROM schema_migrations WHERE migration_name LIKE '028\\_%'") === [['migration_name' => '028_production_work_units.sql']], '35. exactly one migration uses the 028 prefix');

// ==== A1. PERDEA only: stages 6 and 7 appear, the status is optional and never blocks =================================
$a1 = $trendyol('K', [$line('Perdea voal ivory', 'P-900', 1, 3.0, 'curtain')]);
$cohort($a1, FeatureActivation::PIECE_ROUTING, $assisted);
$ok($confirm($iuliana, $a1, $a1['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'o bucată']), 'A1 plan');
$u1 = WorkUnits::unitUuid($a1['items'][0], 1);
$cut($murat, $a1);
$bring($a1, 'material-straightening', $staffBy);
$claim($tailor2, $a1);
$advance($tailor2, $a1);
$check($state($a1)['stage'] === 'bottom-hem' && $decisions($a1) === [], 'policy v2: a PERDEA goes 5 -> 6, no stage_not_applicable decision');
$plan1 = $planOf($iuliana, $a1);
$check($plan1['perdeaAssistedCheckpoints'] === true && $plan1['routePolicyVersion'] === 2 && $plan1['lines'][0]['assistedStages'] === ['bottom-hem', 'side-hem'] && $plan1['lines'][0]['notApplicableStages'] === []
    && $plan1['lines'][0]['pieces'][0]['assistedCheckpoint']['status'] === 'unmarked', 'the plan shows 6 and 7 with an unmarked informational status');
$view1 = $ok($get($hemmer, $a1['path']), 'A1 order view')['pieces']['assistedCheckpoints'];
$check($view1['informational'] === true && $view1['total'] === 1 && $view1['unmarked'] === 1 && $view1['pieces'][0]['piece'] === '1/1', 'the order view shows the status as information');
$check($ok($get($hemmer, $a1['path']), 'A1 order')['employeeAllowedAction']['id'] === 'claim', '5.1 the stage action is offered without any mark');
$claim($hemmer, $a1);
$advance($hemmer, $a1);
$check($state($a1)['stage'] === 'side-hem', 'Scenario E: a PERDEA-only order leaves stage 6 without any mark');
$check($cpStatus($u1, 1, 'bottom-hem') === 'unmarked' && $checkpoint($u1, 1, 'bottom-hem')['marked_by_employee_uuid'] === null && $stageLog($u1, 1, 'bottom-hem') === 0, 'passing unmarked is recorded as unmarked (no fabricated mark, no manufacturing completion)');
$check((int) $one('SELECT COUNT(*) FROM production_assisted_checkpoint_events WHERE unit_uuid = ?', [$u1]) === 0, 'no mark event was fabricated');
$error($mark($hemmer, $u1, 'bottom-hem', 'performed'), 409, 'ASSISTED_STAGE_MISMATCH', 'a mark belongs to the stage the piece is at');
$error($mark($labeler, $u1, 'side-hem', 'performed'), 403, 'ASSISTED_MARK_NOT_ALLOWED', '6. existing stage authority only: an employee of another stage cannot mark');
$error($post($hemmer, "/production-units/{$u1}/assisted-checkpoint", ['stageId' => 'side-hem', 'status' => 'done', 'expectedVersion' => 0]), 400, 'INVALID_REQUEST', 'only the three statuses exist');
$error($mark($hemmer, $u1, 'side-hem', 'performed', 'ARASYA:Q1:' . str_repeat('A', 26)), 422, 'INVALID_QR', '10. a scan, when sent, must identify the piece');
$markKey = $key('mark');
$marked1 = $ok($mark($hemmer, $u1, 'side-hem', 'performed', null, 1, $markKey), '3.2 the stage employee marks Efectuată without any scan or helper');
$check($ok($mark($hemmer, $u1, 'side-hem', 'performed', null, 1, $markKey, 0), 'replay') === $marked1 && (int) $one('SELECT COUNT(*) FROM production_assisted_checkpoint_events WHERE unit_uuid = ?', [$u1]) === 1, 'a retried mark (lost answer) replays; one event');
$check($marked1['checkpoint']['status'] === 'performed' && $marked1['lines'][0]['pieces'][0]['assistedCheckpoint']['markedBy']['isMe'] === true, 'the saved state names the employee');
$error($mark($hemmer, $u1, 'side-hem', 'performed'), 409, 'ASSISTED_CHECKPOINT_STATE', 'the same status twice is a stale screen');
$error($mark($hemmer, $u1, 'side-hem', 'not_performed', null, 1, null, 0), 409, 'ASSISTED_CHECKPOINT_CHANGED', 'a stale version is refused');
$ok($mark($hemmer, $u1, 'side-hem', 'unmarked'), '3.2 the checkbox can be unchecked');
$ok($mark($hemmer, $u1, 'side-hem', 'not_performed', $a1['qr']), 'Neefectuată with an optional order scan');
$check(array_map(static fn (array $r): string => $r['status_before'] . '>' . $r['status_after'], $rows('SELECT status_before, status_after FROM production_assisted_checkpoint_events WHERE unit_uuid = ? ORDER BY version_after', [$u1])) === ['unmarked>performed', 'performed>unmarked', 'unmarked>not_performed'], '12. every change is an audited event');
$features->setCohortMember($assisted, $a1['uuid'], false, 'test-operator', 'withdraw the pilot membership mid-route', $now());
$check($ok($get($hemmer, $a1['path']), 'A1 view after withdrawal')['pieces']['assistedCheckpoints']['notPerformed'] === 1, '13.1 the policy stays with the order after the cohort is withdrawn');
$claim($hemmer, $a1);
$advance($hemmer, $a1);
$check($state($a1)['stage'] === 'ironing' && $cpStatus($u1, 1, 'side-hem') === 'not_performed' && $stageLog($u1, 1, 'side-hem') === 0, '3.3 a negative status is kept visible and does not block');
$check((int) $one('SELECT COUNT(*) FROM production_exceptions WHERE order_uuid = ?', [$a1['uuid']]) === 0, '3.3 a negative status is never a quality exception');
$bring($a1, 'packing', $staffBy);
$finish($a1);
$check($state($a1)['completed'] !== null, '28. packing and delivery never wait for the PERDEA status');

// ==== A2. Mixed DRAPERII + PERDEA (2 pieces): Scenarios A-D =============================================================
$a2 = $trendyol('L', [$line('Perdea in', 'P-910', 1, 4.0, 'curtain'), $line('Draperie catifea', 'D-910', 1, 4.0, 'drapery')]);
$cohort($a2, FeatureActivation::PIECE_ROUTING, $assisted);
$ok($confirm($iuliana, $a2, $a2['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 2, 'equalSplit' => true, 'note' => 'două panouri']), 'A2 perdea plan');
$ok($confirm($iuliana, $a2, $a2['items'][1], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 1, 'note' => 'o bucată']), 'A2 draperie plan');
[$p2a, $p2b, $d2] = [WorkUnits::unitUuid($a2['items'][0], 1), WorkUnits::unitUuid($a2['items'][0], 2), WorkUnits::unitUuid($a2['items'][1], 1)];
$cut($murat, $a2);
$bring($a2, 'material-straightening', $staffBy);
$claim($tailor2, $a2);
$advance($tailor2, $a2);
$check($state($a2)['stage'] === 'bottom-hem' && (int) $one('SELECT COUNT(*) FROM production_tracks WHERE order_uuid = ?', [$a2['uuid']]) === 0, 'the mixed basket stays together at stage 6');
$error($mark($hemmer, $d2, 'bottom-hem', 'performed'), 409, 'ASSISTED_CHECKPOINT_NOT_APPLICABLE', 'a DRAPERII piece has its manufacturing, not a PERDEA status');
$ok($mark($hemmer, $p2a, 'bottom-hem', 'performed'), 'piece 1 marked Efectuată');
$check($state($a2)['stage'] === 'bottom-hem' && $cpStatus($p2b, 1, 'bottom-hem') === null, 'Scenario D / 3.6: the mark moves nothing and never covers piece 2');
$view2 = $ok($get($hemmer, $a2['path']), 'A2 view')['pieces']['assistedCheckpoints'];
$check(array_column($view2['pieces'], 'status') === ['performed', 'unmarked'] && $view2['performed'] === 1 && $view2['unmarked'] === 1, '3.6 exact piece states');
$claim($hemmer, $a2);
$advance($hemmer, $a2);
$check($state($a2)['stage'] === 'side-hem' && $stageLog($d2, 1, 'bottom-hem') === 1 && $stageLog($p2a, 1, 'bottom-hem') === 0 && $cpStatus($p2b, 1, 'bottom-hem') === 'unmarked', 'Scenario A/B: DRAPERII done, PERDEA piece 2 unmarked: the order advances; DRAPERII keeps its completion');
$check($cpStatus($p2a, 1, 'side-hem') === null, '3.5 the stage-6 mark never marks stage 7');
$ok($mark($hemmer, $p2b, 'side-hem', 'not_performed'), 'piece 2 Neefectuată at stage 7');
$claim($hemmer, $a2);
$advance($hemmer, $a2);
$check($state($a2)['stage'] === 'ironing' && $cpStatus($p2b, 1, 'side-hem') === 'not_performed' && $cpStatus($p2a, 1, 'side-hem') === 'unmarked', 'Scenario C: a negative PERDEA status does not block; it stays visible');
$bring($a2, 'packing', $staffBy);
$finish($a2);
$check($state($a2)['completed'] !== null, '28. the mixed basket ships with unmarked and negative PERDEA statuses');

// ==== A3. One defective PERDEA piece of two: genuine quality gates intact, cycle-2 status independent ==================
$a3 = $trendyol('M', [$line('Perdea voal două panouri', 'P-920', 1, 5.0, 'curtain')]);
$cohort($a3, FeatureActivation::PIECE_ROUTING, FeatureActivation::PARTIAL_REWORK, $assisted);
$ok($confirm($iuliana, $a3, $a3['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 2, 'equalSplit' => true, 'note' => 'două panouri egale']), 'A3 plan');
[$p3a, $p3b] = [WorkUnits::unitUuid($a3['items'][0], 1), WorkUnits::unitUuid($a3['items'][0], 2)];
$cut($murat, $a3);
$bring($a3, 'bottom-hem', $staffBy);
$ok($mark($hemmer, $p3a, 'bottom-hem', 'performed'), 'cycle-1 mark of piece A at stage 6');
$bring($a3, 'ironing', $staffBy);
$claim($kadyrova, $a3);
$x3 = $ok($report($kadyrova, $a3, ['units' => [$p3a], 'comment' => 'Tiv inegal pe panoul 1']), '7.1 stage 8 reports exactly piece A of 2', 201);
$check($x3['orderScope']['affectedQuantity'] === 1 && $x3['orderScope']['orderQuantity'] === 2, '7. 1 bucată defectă din 2');
$t3 = (string) $x3['orderScope']['trackId'];
$label3 = (string) $ok($get($iuliana, "/production-tracks/{$t3}/labels"), 'A3 labels')['labels'][0]['payload'];
$error($trackClaim($murat, $t3, $label3), 409, 'ORDER_BLOCKED_BY_EXCEPTION', '7. nothing is cut before supervisor, receipt and approval');
$ok($review($iuliana, $x3['id'], ['decision' => 'accept', 'reasonKey' => 'material-defect', 'qrToken' => $label3]), '7.3 the supervisor accepts the exact piece');
$ok($sendReturn($iuliana, $x3['id']), '7.4 return to cutting');
$ok($ack($murat, $x3['id'], $label3, ['responsibility' => 'accepted']), '7.5 the cutter receives the piece with its label');
$unauthorized = $decide($hemmer + ['dashboard' => true], $x3['id'], ['decision' => 'approve', 'attribution' => 'none']);
$check(in_array($unauthorized['status'], [401, 403], true) && $one('SELECT status FROM production_exceptions WHERE exception_uuid = ?', [$x3['id']]) === 'awaiting_approval', '8.1 a stage employee cannot approve: ' . $unauthorized['status']);
$ok($decide($denisa, $x3['id'], ['decision' => 'approve', 'attribution' => 'none']), '7.7 the eligible manager approves');
$check($track($t3)['stage_id'] === 'material-preparation' && (int) $track($t3)['rework_cycle'] === 2 && $state($a3)['stage'] === 'ironing', '7.8 only piece A returns to cutting; piece B keeps stage 8');
$ok($trackComplete($murat, $t3, ['pieceOutcome' => 'repair']), '7.9 the piece is repaired');
$guard = 0;
while (($stage3 = $track($t3)['stage_id']) !== 'ironing') {
    $who3 = $staffBy($stage3);
    $ok($trackClaim($who3, $t3, $label3), "repaired piece claimed at {$stage3}");
    $ok($trackComplete($who3, $t3), "repaired piece completed at {$stage3} (no PERDEA mark needed)");
    $check(++$guard < 10, 'terminates');
}
$replay3 = array_map(static fn (array $e): string => $e['from_stage_id'], $rows("SELECT from_stage_id FROM production_track_events WHERE track_uuid = ? AND action = 'stage_completed' ORDER BY version_after", [$t3]));
$check($replay3 === ['material-preparation', 'workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem'], '7.11 the repaired PERDEA piece replays 2..7 (6 and 7 included, unmarked allowed): ' . json_encode($replay3));
$check($cpStatus($p3a, 1, 'bottom-hem') === 'performed' && $cpStatus($p3a, 2, 'bottom-hem') === 'unmarked' && $cpStatus($p3a, 2, 'side-hem') === 'unmarked', '3.7 cycle-1 status is kept and never reused for cycle 2');
$ok($trackClaim($ironer, $t3, $label3), 'ironing claims the repaired piece');
$error($trackComplete($ironer, $t3), 422, 'DEFECT_RESOLUTION_REQUIRED', '7.13 the defect resolution confirmation stays mandatory');
$ok($post($kadyrova, '/production-units/reunite', ['unitIds' => [$p3a, $p3b], 'confirmed' => true]), '7.15 physical reunion at stage 8 (no stage change)');
$ok($trackComplete($ironer, $t3, ['defectResolved' => true]), 'resolved and moving on');
$check($cpStatus($p3b, 1, 'bottom-hem') === 'unmarked' && $cpStatus($p3b, 2, 'bottom-hem') === null, '7.14 the healthy piece keeps its history and gets no cycle-2 row');
$advance($kadyrova, $a3);
$bring($a3, 'packing', $staffBy);
$error($post($finisher, $a3['path'] . '/claim', ['expectedVersion' => (int) $state($a3)['version']]), 409, 'ORDER_PIECES_IN_REWORK', '7.17 packing stays blocked while real rework is unresolved');
$guard = 0;
while (($stage3 = $track($t3)['stage_id']) !== 'packing') {
    $ok($trackClaim($finisher, $t3, $label3), "repaired piece claimed at {$stage3}");
    $ok($trackComplete($finisher, $t3), "repaired piece completed at {$stage3}");
    $check(++$guard < 10, 'terminates');
}
$finish($a3);
$check($state($a3)['completed'] !== null, '28. the order ships once the real rework is done; no PERDEA mark was needed');
$history3 = $ok($get($iuliana, "/production-units/{$p3a}/history"), 'A3 piece history');
$check(count($history3['assistedCheckpoints']) === 1 && $history3['assistedCheckpoints'][0]['status'] === 'performed' && $history3['assistedCheckpoints'][0]['reworkCycle'] === 1, '12. the piece history shows the real marks with their cycle');

// ==== A4. Historical transition: a version-1 exemption stays valid; nothing is backfilled =============================
$a4 = $trendyol('N', [$line('Perdea veche', 'P-930', 1, 2.0, 'curtain')]);
$cohort($a4, FeatureActivation::PIECE_ROUTING);
$ok($confirm($iuliana, $a4, $a4['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'o bucată']), 'A4 plan');
$u4 = WorkUnits::unitUuid($a4['items'][0], 1);
$cut($murat, $a4);
$bring($a4, 'ironing', $staffBy);
$d4 = $rows('SELECT kind, policy_version, skipped_stages_json FROM production_route_decisions WHERE order_uuid = ?', [$a4['uuid']]);
$check(count($d4) === 1 && $d4[0]['kind'] === 'stage_not_applicable' && (int) $d4[0]['policy_version'] === 1 && $ledger($u4, 1)['bottom-hem'] === 'not_applicable', '13.2 version 1 skipped 6/7 with an auditable exemption');
$cohort($a4, $assisted);
$bring($a4, 'packing', $staffBy);
$finish($a4);
$check($state($a4)['completed'] !== null && (int) $one('SELECT COUNT(*) FROM production_assisted_checkpoints WHERE order_uuid = ?', [$a4['uuid']]) === 0
    && $rows('SELECT kind, policy_version, skipped_stages_json FROM production_route_decisions WHERE order_uuid = ?', [$a4['uuid']]) === $d4 && $ledger($u4, 1)['side-hem'] === 'not_applicable', '13.2 no status is fabricated, no exemption rewritten');

// ==== A5. Feature off: unchanged behaviour ==========================================================================
$a5 = $trendyol('O', [$line('Perdea fără pilot', 'P-940', 1, 2.0, 'curtain')]);
$ok($confirm($iuliana, $a5, $a5['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'o bucată']), 'A5 plan');
$u5 = WorkUnits::unitUuid($a5['items'][0], 1);
$cut($murat, $a5);
$bring($a5, 'bottom-hem', $staffBy);
$error($mark($hemmer, $u5, 'bottom-hem', 'performed'), 409, 'ASSISTED_CHECKPOINT_NOT_APPLICABLE', '34. outside the cohort no status exists');
$check($ok($get($hemmer, $a5['path']), 'A5 view')['pieces']['assistedCheckpoints'] === null, '34. the order view shows none');
$claim($hemmer, $a5);
$advance($hemmer, $a5);
$check($state($a5)['stage'] === 'side-hem' && $stageLog($u5, 1, 'bottom-hem') === 1 && $cpStatus($u5, 1, 'bottom-hem') === null, '34. flags off: the existing full route, exactly as before');

// ==== A6. Version 2 with an unconfirmed plan: line-level information, nothing invented, nothing blocked =================
$a6 = $trendyol('P', [$line('Perdea neconfirmată', 'P-950', 1, 2.0, 'curtain')]);
$cohort($a6, $assisted);
$cut($murat, $a6);
$bring($a6, 'bottom-hem', $staffBy);
$view6 = $ok($get($hemmer, $a6['path']), 'A6 view')['pieces']['assistedCheckpoints'];
$check($view6['pieces'] === [] && count($view6['unconfirmedLines']) === 1 && $view6['unconfirmedLines'][0]['code'] === 'P-950', '3.6 an unknown piece count shows the line, never invented pieces');
$claim($hemmer, $a6);
$advance($hemmer, $a6);
$check($state($a6)['stage'] === 'side-hem' && (int) $one('SELECT COUNT(*) FROM production_work_units WHERE order_uuid = ?', [$a6['uuid']]) === 0, 'the order advances; no piece identity is fabricated');

// ==== A7. Concurrency, physical hold, deactivation ====================================================================
$a7 = $trendyol('Q', [$line('Perdea concurență', 'P-960', 1, 2.0, 'curtain')]);
$cohort($a7, FeatureActivation::PIECE_ROUTING, $assisted);
$ok($confirm($iuliana, $a7, $a7['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'o bucată']), 'A7 plan');
$u7 = WorkUnits::unitUuid($a7['items'][0], 1);
$cut($murat, $a7);
$bring($a7, 'bottom-hem', $staffBy);
$body7 = ['stageId' => 'bottom-hem', 'status' => 'performed', 'expectedVersion' => 0];
$raced = $race([[$hemmer, "/production-units/{$u7}/assisted-checkpoint", $body7, $key('race'), null], [$tailor2, "/production-units/{$u7}/assisted-checkpoint", $body7, $key('race'), null]]);
$statuses = array_column($raced, 'status');
sort($statuses);
$check($statuses === [200, 409] && in_array('ASSISTED_CHECKPOINT_CHANGED', array_column($raced, 'error'), true), '14. two stage employees mark the same piece at once: exactly one wins: ' . json_encode($raced));
$check((int) $one('SELECT COUNT(*) FROM production_assisted_checkpoint_events WHERE unit_uuid = ?', [$u7]) === 1, '14. one event');
$claim($hemmer, $a7);
$ok($post($iuliana, "/production-units/{$u7}/custody", ['action' => 'hold', 'holdReason' => 'inspection', 'expectedVersion' => 0, 'qrToken' => $a7['qr']]), 'the supervisor holds the curtain');
$error($transition($hemmer, $a7), 409, 'ORDER_PIECES_HELD', '5.1 a real physical hold still blocks the stage');
$ok($mark($hemmer, $u7, 'bottom-hem', 'not_performed'), 'the informational status can still be recorded while held');
$ok($post($iuliana, "/production-units/{$u7}/custody", ['action' => 'release', 'expectedVersion' => 1, 'qrToken' => $a7['qr']]), 'released');
$hemmer2 = $identity('hemmer2', 'Test Second Hemmer', ['bottom-hem', 'side-hem']);
$ok($dash($root, 'POST', "/management/employees/{$uuids['hemmer2']}/deactivate"), 'the second hemmer leaves');
$gone = $mark($hemmer2, $u7, 'bottom-hem', 'performed');
$check(in_array($gone['status'], [401, 403], true) && $cpStatus($u7, 1, 'bottom-hem') === 'not_performed', '27. a deactivated employee with an open page changes nothing: ' . $gone['status']);
$error($mark($kadyrova, $u7, 'side-hem', 'performed'), 409, 'ASSISTED_STAGE_MISMATCH', 'no mark for a stage the piece has not reached');

// ==== A8. Management overview: genuine blockers apart from PERDEA information =========================================
$a8 = $trendyol('R', [$line('Draperie cu defect', 'D-970', 1, 3.0, 'drapery')]);
$cohort($a8, FeatureActivation::PARTIAL_REWORK);
$ok($confirm($iuliana, $a8, $a8['items'][0], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 1, 'note' => 'o bucată']), 'A8 plan');
$cut($murat, $a8);
$bring($a8, 'ironing', $staffBy);
$claim($kadyrova, $a8);
$x8 = $ok($report($kadyrova, $a8, ['units' => [WorkUnits::unitUuid($a8['items'][0], 1)], 'comment' => 'Pată']), 'a genuine defect report', 201);
$overview = $ok($dashGet($root, '/management/production-overview'), 'production overview');
$blocked8 = array_values(array_filter($overview['blockers']['items'], static fn (array $item): bool => $item['globalOrderId'] === $a8['id']));
$check(count($blocked8) === 1 && $blocked8[0]['action'] === 'supervisor_review' && $blocked8[0]['exception']['status'] === 'awaiting_supervisor_review', '9.1 a genuine defect is a blocker with who must act: ' . json_encode($blocked8));
$check(array_filter($overview['blockers']['items'], static fn (array $item): bool => in_array($item['globalOrderId'], [$a1['id'], $a2['id'], $a6['id']], true) || str_contains($item['reason'], 'assisted')) === [], '9.2 PERDEA status is never a blocker');
$held = array_values(array_filter($overview['blockers']['items'], static fn (array $item): bool => $item['globalOrderId'] === $a7['id']));
$check($held === [], 'a released hold is no longer listed');
$check($overview['perdeaInformation']['informational'] === true && in_array($a7['id'], array_column($overview['perdeaInformation']['items'], 'orderId'), true)
    && $overview['perdeaInformation']['counts']['notPerformed'] >= 1, '9.2 the PERDEA status is shown separately as information');
$check($dashGet($denisa, '/management/production-overview')['status'] === 403, 'the overview keeps its existing permission (production.view)');

// ==== A9. Employee KPI: a mark is not manufacturing ===================================================================
$employees9 = [];
$cursor9 = null;
do {
    $page9 = $ok($dashGet($root, '/management/analytics/employees', ['period' => 'month', 'limit' => '100'] + ($cursor9 === null ? [] : ['cursor' => $cursor9])), 'analytics employees');
    foreach ($page9['items'] as $item9) $employees9[$item9['id']] = $item9;
    $cursor9 = $page9['nextCursor'] ?? null;
} while ($cursor9 !== null);
$h9 = $employees9[$uuids['hemmer']];
$check($h9['assistedCheckpoints']['performed'] === (int) $one("SELECT COUNT(*) FROM production_assisted_checkpoints WHERE marked_by_employee_uuid = ? AND status = 'performed'", [$uuids['hemmer']])
    && $h9['assistedCheckpoints']['notPerformed'] === (int) $one("SELECT COUNT(*) FROM production_assisted_checkpoints WHERE marked_by_employee_uuid = ? AND status = 'not_performed'", [$uuids['hemmer']]), '11.2 marks are counted apart: ' . json_encode($h9['assistedCheckpoints']));
$check(!array_key_exists('helperWork', $h9['assistedCheckpoints']), '11.3 no helper credit exists');
$marks9 = implode(',', array_fill(0, 2, '?'));
$expectedStage6 = (int) $one("SELECT COUNT(*) FROM order_activity_events WHERE employee_uuid = ? AND action = 'stage_completed' AND from_stage_id = 'bottom-hem' AND order_uuid NOT IN ({$marks9})", [$uuids['hemmer'], $a1['uuid'], $a3['uuid']]);
$check(($h9['stageCompletions']['bottom-hem'] ?? 0) === $expectedStage6 && $expectedStage6 === 4, '11.1 PERDEA-only transitions earn no stage completion; DRAPERII and flag-off work does: ' . json_encode($h9['stageCompletions']));
$check($h9['assistedCheckpoints']['stageTransitions'] === 6 && !isset($h9['trackStageCompletions']['bottom-hem']) && !isset($h9['trackStageCompletions']['side-hem']), '11.2 PERDEA-only transitions (order and track) are counted apart: ' . json_encode([$h9['assistedCheckpoints'], $h9['trackStageCompletions']]));
$check((int) $one("SELECT COUNT(*) FROM production_unit_stage_log l INNER JOIN production_assisted_checkpoints c ON c.unit_uuid = l.unit_uuid AND c.rework_cycle = l.rework_cycle AND c.stage_id = l.stage_id") === 0, '11.2 no PERDEA status row has a manufacturing completion for the same stage and cycle');
foreach (['hemmer', 'tailor2', 'labeler'] as $who9) {
    $row9 = $employees9[$uuids[$who9]];
    $check($row9['pieceWork']['order'] + $row9['pieceWork']['route'] + $row9['pieceWork']['rework'] === (int) $one("SELECT COUNT(*) FROM production_unit_stage_log WHERE employee_uuid = ? AND outcome = 'completed'", [$uuids[$who9]]), "11.4 piece work of {$who9} equals its own completions");
}
$departments9 = $ok($dashGet($root, '/management/analytics/departments', ['period' => 'month', 'limit' => '100']), 'analytics departments')['items'];
$check(array_sum(array_map(static fn (array $d): int => $d['assistedCheckpoints']['performed'], $departments9)) === (int) $one("SELECT COUNT(*) FROM production_assisted_checkpoints WHERE status = 'performed'"), '11.5 department totals equal the performed marks');

// ==== A10. Rollback guard, operator CLI, route audit ==================================================================
// Open orders with a ticket schema 2 document (API 2.34.2) raise the rollback floor above the per-piece floors.
$rollbackFloor = static fn (string $floor): string => ($cli('check-rollback.php', '0.0.0')['json']['state']['openOrdersWithTicketSchema2'] ?? 0) > 0 && version_compare($floor, '2.34.2', '<') ? '2.34.2' : $floor;
$check($cli('check-rollback.php', '2.33.0')['code'] === 3 && $cli('check-rollback.php', $rollbackFloor('2.34.0'))['code'] === 0, '35. an API older than 2.34.0 is refused while an open order follows policy v2');
$original31 = (string) $one("SELECT checksum FROM schema_migrations WHERE migration_name = '031_production_assisted_checkpoints.sql'");
$pdo->exec("UPDATE schema_migrations SET checksum = REPEAT('0', 64) WHERE migration_name = '031_production_assisted_checkpoints.sql'");
try {
    $check($cli('check-rollback.php', '2.34.0')['code'] === 1, '35. an unknown 031 refuses rollback');
} finally {
    $pdo->prepare("UPDATE schema_migrations SET checksum = ? WHERE migration_name = '031_production_assisted_checkpoints.sql'")->execute([$original31]);
}
$rollback10 = $cli('partial-rework.php', 'rollback-check');
$check($rollback10['code'] === 3 && ($rollback10['json']['openOrdersWithAssistedCheckpoints'] ?? 0) >= 1, '35. the operator rollback check names the open policy-v2 orders: ' . $rollback10['out']);
$check($cli('partial-rework.php', 'assisted-enable', '--operator=test', '--confirm=WRONG')['code'] === 2, '34. global activation needs the exact confirmation phrase');
$status10 = $cli('partial-rework.php', 'status');
$check($status10['code'] === 0 && $status10['json']['perdeaAssistedCheckpoints'] === false && $status10['json']['perdeaAssistedCheckpointsCohort'] >= 4, '34. the flag stays off globally; only the audited cohort is active: ' . $status10['out']);
$cohortCli = $cli('partial-rework.php', 'cohort-add', '--feature=perdea_assisted_checkpoints', '--order=' . $a5['id'], '--operator=test-operator', '--reason=pilot');
$check($cohortCli['code'] === 0 && $cohortCli['json']['member'] === true && (int) $one("SELECT COUNT(*) FROM production_feature_flag_events WHERE feature_key = 'perdea_assisted_checkpoints' AND action = 'cohort_added' AND order_uuid = ?", [$a5['uuid']]) === 1, '34. cohort changes are audited');
$audit10 = $cli('partial-rework.php', 'route-audit', '--details=1');
$byOrder10 = array_column($audit10['json']['orders'] ?? [], null, 'order');
$check($audit10['code'] === 0 && ($byOrder10[$a6['id']]['routePolicyVersion'] ?? null) === 2 && ($byOrder10[$a7['id']]['routePolicyVersion'] ?? null) === 2 && $audit10['json']['schema']['assistedCheckpoints'] === true
    && !in_array('bottom-hem', $byOrder10[$a6['id']]['missingEvidence'] ?? ['bottom-hem'], true), '19.2 the route audit reports the policy and never asks evidence for the PERDEA status');

fwrite(STDOUT, "OK MySQL PERDEA assisted checkpoints integration ({$checks} checks)\n");
