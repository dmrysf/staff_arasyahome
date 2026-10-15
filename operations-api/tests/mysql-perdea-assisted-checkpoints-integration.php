<?php

declare(strict_types=1);

// Assisted PERDEA checkpoints at bottom-hem (6) and side-hem (7), route policy version 2 (migration 031, API 2.34.0),
// against a dedicated MySQL/MariaDB test database, end to end through the HTTP kernel and the operator CLIs. Every
// assertion reads committed rows.
//
//   A1  PERDEA only (tests 02, 04, 05): stages 6 and 7 appear and never complete without a verified checkpoint; helper
//       take / return, negative check, separate stage-6 and stage-7 checks, self-verification and wrong-stage refusals,
//       stale versions, idempotent replay, no per-piece completion and no route decision at 6/7, policy sticks to the order
//   A2  mixed DRAPERII + 2 PERDEA pieces (tests 03, 06): no route split, the basket waits for every PERDEA piece one by
//       one, DRAPERII keeps its manufacturing completion, a DRAPERII piece has no checkpoint
//   A3  PERDEA rework of one of two pieces (tests 07, 08, 14, 16): the repaired piece replays 2..8 with its own cycle-2
//       checks at 6 and 7; a cycle-1 check never satisfies cycle 2; the healthy piece keeps its state; packing gate
//   A4  historical transition (test 24): a version-1 exemption stays valid, nothing is backfilled or rewritten
//   A5  feature off (test 34): orders outside the cohort keep version 1 or the full route exactly as before
//   A6  unconfirmed plan under version 2 fails closed at stage 6
//   A7  concurrency, physical hold and deactivation (tests 26, 27)
//   A8  employee KPI (test 30): verification is not manufacturing, the helper is credited only for verified work
//   A9  migration 031 re-run, rollback guards, CLI activation, route audit and piece history (tests 34, 35)

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
// One checkpoint action on a piece: take / return (helper) or verify (stage employee) with an explicit stage and version.
$act = static function (array $who, string $unit, string $stage, string $action, string $qr, ?string $outcome = null, int $cycle = 1, ?string $idem = null, ?int $version = null) use ($post, $cpVersion): array {
    return $post($who, "/production-units/{$unit}/assisted-checkpoint", ['action' => $action, 'stageId' => $stage, 'expectedVersion' => $version ?? $cpVersion($unit, $cycle, $stage), 'qrToken' => $qr] + ($outcome === null ? [] : ['outcome' => $outcome]), $idem);
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
$check((int) $one("SELECT enabled FROM production_feature_flags WHERE flag_key = 'perdea_assisted_checkpoints'") === 0, '34. the assisted checkpoint policy is created disabled');
$tables31 = (int) $one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
(new SqlFileRunner($pdo))->run($migrations . '/031_production_assisted_checkpoints.sql');
$check((int) $one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()') === $tables31 && (int) $one("SELECT COUNT(*) FROM production_feature_flags WHERE flag_key = 'perdea_assisted_checkpoints'") === 1, '35. migration 031 re-runs: no table or flag twice');
$check((string) $one("SELECT checksum FROM schema_migrations WHERE migration_name = '030_production_route_reconciliation.sql'") === hash_file('sha256', $migrations . '/030_production_route_reconciliation.sql'), '035. the 030 checksum is unchanged');

// ==== A1. PERDEA only: mandatory, separate assisted checkpoints at stages 6 and 7 ======================================
$a1 = $trendyol('K', [$line('Perdea voal ivory', 'P-900', 1, 3.0, 'curtain')]);
$cohort($a1, FeatureActivation::PIECE_ROUTING, $assisted);
$ok($confirm($iuliana, $a1, $a1['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'o bucată']), 'A1 plan');
$u1 = WorkUnits::unitUuid($a1['items'][0], 1);
$cut($murat, $a1);
$bring($a1, 'material-straightening', $staffBy);
$claim($tailor2, $a1);
$advance($tailor2, $a1);
$check($state($a1)['stage'] === 'bottom-hem', '02. under policy version 2 a PERDEA goes 5 -> 6 (no automatic skip)');
$check($decisions($a1) === [], '02. no stage_not_applicable decision is written for the new policy');
$plan1 = $planOf($iuliana, $a1);
$check($plan1['perdeaAssistedCheckpoints'] === true && $plan1['routePolicyVersion'] === 2 && $plan1['lines'][0]['assistedStages'] === ['bottom-hem', 'side-hem'] && $plan1['lines'][0]['notApplicableStages'] === []
    && in_array('bottom-hem', $plan1['lines'][0]['route'], true) && in_array('side-hem', $plan1['lines'][0]['route'], true), '02. the authoritative route shows 6 and 7 as assisted checkpoints: ' . json_encode($plan1['lines'][0]['route']));
$check($plan1['lines'][0]['pieces'][0]['assistedCheckpoint']['status'] === 'awaiting_work' && $plan1['lines'][0]['pieces'][0]['assistedCheckpoint']['stageId'] === 'bottom-hem', 'the piece shows its current checkpoint: awaiting the auxiliary work');
$view1 = $ok($get($hemmer, $a1['path']), 'A1 order view')['pieces'];
$check($view1['perdeaAssistedCheckpoints'] === true && $view1['assistedCheckpoints']['required'] === 1 && $view1['assistedCheckpoints']['verified'] === 0 && $view1['assistedCheckpoints']['pieces'][0]['piece'] === '1/1', '16. the stage queue order view shows the pending PERDEA check');
$claim($hemmer, $a1);
$blocked1 = $error($transition($hemmer, $a1), 409, 'ASSISTED_VERIFICATION_REQUIRED', '02. stage 6 cannot complete without the PERDEA verification');
$check(($blocked1['error']['message'] ?? '') === 'Lucrarea auxiliară pentru această perdea nu a fost confirmată.' && $blocked1['error']['details']['pieces'][0]['unitId'] === $u1 && $blocked1['error']['details']['pieces'][0]['status'] === 'awaiting_work', '23. the exact piece and its state come back in Romanian: ' . json_encode($blocked1));
$before1 = $state($a1);
$ok($get($hemmer, $a1['path']), 'a refresh');
$check($state($a1) === $before1 && $stageLog($u1, 1, 'bottom-hem') === 0 && $checkpoint($u1, 1, 'bottom-hem') === null, '05. nothing advances or completes by itself (no timeout, refresh or scheduler)');
$error($act($labeler, $u1, 'side-hem', 'take', $a1['qr']), 409, 'ASSISTED_STAGE_MISMATCH', '3.8 the stage is named explicitly and must be the piece stage');
$error($post($labeler, "/production-units/{$u1}/assisted-checkpoint", ['action' => 'verify', 'stageId' => 'bottom-hem', 'expectedVersion' => 0, 'qrToken' => $a1['qr']]), 400, 'INVALID_REQUEST', 'a verification needs an explicit outcome');
$error($post($labeler, "/production-units/{$u1}/assisted-checkpoint", ['action' => 'take', 'stageId' => 'bottom-hem', 'expectedVersion' => 0]), 400, 'INVALID_REQUEST', 'the piece scan is required');
$error($act($labeler, $u1, 'bottom-hem', 'take', 'ARASYA:Q1:' . str_repeat('A', 26)), 422, 'INVALID_QR', 'an unknown code identifies no piece');
$taken1 = $ok($act($labeler, $u1, 'bottom-hem', 'take', $a1['qr']), '3.7 the helper from another area takes the curtain');
$check($taken1['checkpoint']['version'] === 1 && $checkpoint($u1, 1, 'bottom-hem')['status'] === 'taken_for_work' && $checkpoint($u1, 1, 'bottom-hem')['helper_employee_uuid'] === $uuids['labeler'], '3.7 collection recorded with the helper and time');
$error($act($hemmer, $u1, 'bottom-hem', 'verify', $a1['qr'], 'performed'), 409, 'ASSISTED_WORK_IN_PROGRESS', 'a piece still with the helper cannot be verified');
$error($act($hemmer, $u1, 'bottom-hem', 'return', $a1['qr']), 403, 'ASSISTED_HELPER_MISMATCH', 'nobody else can declare the return for the helper');
$returnKey = $key('return');
$returned1 = $ok($act($labeler, $u1, 'bottom-hem', 'return', $a1['qr'], null, 1, $returnKey), '3.7 the helper brings it back to the verification point');
$check($ok($act($labeler, $u1, 'bottom-hem', 'return', $a1['qr'], null, 1, $returnKey, 1), 'replay') === $returned1 && (int) $one('SELECT COUNT(*) FROM production_assisted_checkpoint_events WHERE unit_uuid = ?', [$u1]) === 2, '13/26. a retried return (lost answer) replays; no second event');
$error($act($labeler, $u1, 'bottom-hem', 'return', $a1['qr'], null, 1, null, 1), 409, 'ASSISTED_CHECKPOINT_CHANGED', '21.3 a stale version is refused');
$error($act($labeler, $u1, 'bottom-hem', 'verify', $a1['qr'], 'performed'), 403, 'ASSISTED_VERIFY_NOT_ALLOWED', 'the helper of another area cannot verify the stage');
$verified1 = $ok($act($hemmer, $u1, 'bottom-hem', 'verify', $a1['qr'], 'performed'), '3.2 the stage employee verifies the returned curtain');
$check($verified1['checkpoint']['version'] === 3 && $verified1['lines'][0]['pieces'][0]['assistedCheckpoint']['status'] === 'verified' && $verified1['lines'][0]['pieces'][0]['assistedCheckpoint']['helper']['displayName'] === 'Test Labeler'
    && $verified1['lines'][0]['pieces'][0]['assistedCheckpoint']['verifiedBy']['isMe'] === true, '3.3 the verifier and the helper are recorded apart');
$check($state($a1)['stage'] === 'bottom-hem', '05. a verification itself never moves the order');
$advance($hemmer, $a1);
$check($state($a1)['stage'] === 'side-hem' && $stageLog($u1, 1, 'bottom-hem') === 0, '3.3 the stage completes; the verification is not logged as a manufacturing completion of the PERDEA piece');
// The policy stays with the order once it has a checkpoint, even if the pilot membership is withdrawn.
$features->setCohortMember($assisted, $a1['uuid'], false, 'test-operator', 'withdraw the pilot membership mid-route', $now());
$claim($hemmer, $a1);
$error($transition($hemmer, $a1), 409, 'ASSISTED_VERIFICATION_REQUIRED', '3.8 a stage-6 verification never satisfies stage 7; 24.2 the policy is not lost with the cohort');
$ok($act($tailor2, $u1, 'side-hem', 'take', $a1['qr']), 'a helper who also works at stage 7 takes it');
$ok($act($tailor2, $u1, 'side-hem', 'return', $a1['qr']), 'and returns it');
$error($act($tailor2, $u1, 'side-hem', 'verify', $a1['qr'], 'performed'), 403, 'ASSISTED_SELF_VERIFICATION', 'the helper never verifies their own work');
$ok($act($hemmer, $u1, 'side-hem', 'verify', $a1['qr'], 'not_performed'), '04. the stage employee records: not performed');
$np1 = $checkpoint($u1, 1, 'side-hem');
$check($np1['status'] === 'not_performed' && $np1['helper_employee_uuid'] === null && $np1['verified_by_employee_uuid'] === $uuids['hemmer'], '04. the rejected attempt credits no helper');
$error($transition($hemmer, $a1), 409, 'ASSISTED_VERIFICATION_REQUIRED', '04. a negative verification blocks progression');
$error($act($hemmer, $u1, 'side-hem', 'verify', $a1['qr'], 'not_performed'), 409, 'ASSISTED_CHECKPOINT_STATE', 'no duplicate negative check');
$ok($act($tailor2, $u1, 'side-hem', 'take', $a1['qr']), '04. the curtain goes back for the work');
$ok($act($tailor2, $u1, 'side-hem', 'return', $a1['qr']), '04. returned again');
$ok($act($hemmer, $u1, 'side-hem', 'verify', $a1['qr'], 'performed'), '04. the authorized positive check');
$advance($hemmer, $a1);
$check($state($a1)['stage'] === 'ironing' && $stageLog($u1, 1, 'side-hem') === 0, '04. progression after the positive check only');
$check(array_column($rows('SELECT action FROM production_assisted_checkpoint_events WHERE unit_uuid = ? AND stage_id = ? ORDER BY version_after', [$u1, 'side-hem']), 'action') === ['taken_for_work', 'work_returned', 'not_performed', 'taken_for_work', 'work_returned', 'verified'], '14. the complete side-hem history is append-only');
$check($checkpoint($u1, 1, 'side-hem')['helper_employee_uuid'] === $uuids['tailor2'] && $checkpoint($u1, 1, 'bottom-hem')['helper_employee_uuid'] === $uuids['labeler'], 'each stage keeps its own helper');
$check($decisions($a1) === [] && $activity($a1) === ['waiting>material-preparation', 'material-preparation>workshop-receiving', 'workshop-receiving>labeling', 'labeling>material-straightening', 'material-straightening>bottom-hem', 'bottom-hem>side-hem', 'side-hem>ironing'], '02. stages 6 and 7 appear in the order history: ' . json_encode($activity($a1)));
$bring($a1, 'packing', $staffBy);
$finish($a1);
$check($state($a1)['completed'] !== null, '28. packing accepts verified checkpoints as the PERDEA evidence of 6 and 7');

// ==== A2. Mixed DRAPERII + PERDEA (2 pieces): the basket waits for every piece ==========================================
$a2 = $trendyol('L', [$line('Perdea in', 'P-910', 1, 4.0, 'curtain'), $line('Draperie catifea', 'D-910', 1, 4.0, 'drapery')]);
$cohort($a2, FeatureActivation::PIECE_ROUTING, $assisted);
$ok($confirm($iuliana, $a2, $a2['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 2, 'equalSplit' => true, 'note' => 'două panouri']), 'A2 perdea plan');
$ok($confirm($iuliana, $a2, $a2['items'][1], ['expectedRevision' => 0, 'productType' => 'draperii', 'pieceCount' => 1, 'note' => 'o bucată']), 'A2 draperie plan');
[$p2a, $p2b, $d2] = [WorkUnits::unitUuid($a2['items'][0], 1), WorkUnits::unitUuid($a2['items'][0], 2), WorkUnits::unitUuid($a2['items'][1], 1)];
$cut($murat, $a2);
$bring($a2, 'material-straightening', $staffBy);
$claim($tailor2, $a2);
$advance($tailor2, $a2);
$check($state($a2)['stage'] === 'bottom-hem' && (int) $one('SELECT COUNT(*) FROM production_tracks WHERE order_uuid = ?', [$a2['uuid']]) === 0 && $decisions($a2) === [], '03. the mixed basket stays together at stage 6 (no route split under version 2)');
$error($act($hemmer, $d2, 'bottom-hem', 'verify', $a2['qr'], 'performed'), 409, 'ASSISTED_CHECKPOINT_NOT_APPLICABLE', '3.3 a DRAPERII piece has manufacturing, not an assisted check');
$ok($act($hemmer, $p2a, 'bottom-hem', 'verify', $a2['qr'], 'performed'), '06. piece 1 verified (the helper did not use the application)');
$check($state($a2)['stage'] === 'bottom-hem', '3.5 DRAPERII pending + PERDEA verified: the stage is not complete');
$claim($hemmer, $a2);
$blocked2 = $error($transition($hemmer, $a2), 409, 'ASSISTED_VERIFICATION_REQUIRED', '3.5 DRAPERII done + PERDEA piece 2 pending: blocked');
$check(count($blocked2['error']['details']['pieces']) === 1 && $blocked2['error']['details']['pieces'][0]['unitId'] === $p2b && $blocked2['error']['details']['pieces'][0]['piece'] === '2/2', '06. one confirmation never covers the second physical piece: ' . json_encode($blocked2['error']['details']));
$view2 = $ok($get($hemmer, $a2['path']), 'A2 view')['pieces']['assistedCheckpoints'];
$check($view2['required'] === 2 && $view2['verified'] === 1 && array_column($view2['pieces'], 'status') === ['verified', 'awaiting_work'], '16.2 exact piece states are visible');
$ok($act($hemmer, $p2b, 'bottom-hem', 'verify', $a2['qr'], 'performed'), 'piece 2 verified');
$advance($hemmer, $a2);
$check($state($a2)['stage'] === 'side-hem' && $stageLog($d2, 1, 'bottom-hem') === 1 && $stageLog($p2a, 1, 'bottom-hem') === 0 && $stageLog($p2b, 1, 'bottom-hem') === 0, '3.3 DRAPERII keeps its manufacturing completion; PERDEA only its verification');
$claim($hemmer, $a2);
$ok($act($hemmer, $p2a, 'side-hem', 'verify', $a2['qr'], 'performed'), 'stage 7 piece 1');
$ok($act($hemmer, $p2b, 'side-hem', 'verify', $a2['qr'], 'not_performed'), 'stage 7 piece 2 not done');
$blocked2b = $error($transition($hemmer, $a2), 409, 'ASSISTED_VERIFICATION_REQUIRED', '3.5 DRAPERII done + PERDEA explicitly not done: blocked');
$check($blocked2b['error']['details']['pieces'][0]['status'] === 'not_performed', 'the negative state is reported');
$ok($act($hemmer, $p2b, 'side-hem', 'verify', $a2['qr'], 'performed'), 'after the work, a positive check');
$advance($hemmer, $a2);
$check($state($a2)['stage'] === 'ironing', '3.5 DRAPERII complete + every PERDEA verified: eligible');
$bring($a2, 'packing', $staffBy);
$finish($a2);
$check($state($a2)['completed'] !== null, '28. a mixed basket with every requirement satisfied ships as one order');

// Verifies every PERDEA piece of the carrier at the current assisted stage and completes the stage.
$passAssisted = static function (array $o, array $units, string $qr) use ($state, $claim, $advance, $act, $ok, $hemmer, $check): void {
    foreach (['bottom-hem', 'side-hem'] as $stage) {
        $check($state($o)['stage'] === $stage, "order at {$stage}");
        foreach ($units as $unit) $ok($act($hemmer, $unit, $stage, 'verify', $qr, 'performed'), "verify {$stage}");
        $claim($hemmer, $o);
        $advance($hemmer, $o);
    }
};

// ==== A3. PERDEA rework of one of two pieces: cycle-2 checks at 6 and 7, healthy companion untouched ====================
$a3 = $trendyol('M', [$line('Perdea voal două panouri', 'P-920', 1, 5.0, 'curtain')]);
$cohort($a3, FeatureActivation::PIECE_ROUTING, FeatureActivation::PARTIAL_REWORK, $assisted);
$ok($confirm($iuliana, $a3, $a3['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 2, 'equalSplit' => true, 'note' => 'două panouri egale']), 'A3 plan');
[$p3a, $p3b] = [WorkUnits::unitUuid($a3['items'][0], 1), WorkUnits::unitUuid($a3['items'][0], 2)];
$cut($murat, $a3);
$bring($a3, 'bottom-hem', $staffBy);
$passAssisted($a3, [$p3a, $p3b], $a3['qr']);
$check($state($a3)['stage'] === 'ironing', 'both pieces passed 6 and 7 with their own checks');
$claim($kadyrova, $a3);
$x3 = $ok($report($kadyrova, $a3, ['units' => [$p3a], 'comment' => 'Tiv inegal pe panoul 1']), '08. stage 8 reports exactly piece 1 of 2', 201);
$check($x3['orderScope']['affectedQuantity'] === 1 && $x3['orderScope']['orderQuantity'] === 2, '08. 1 bucată defectă din 2');
$t3 = (string) $x3['orderScope']['trackId'];
$label3 = (string) $ok($get($iuliana, "/production-tracks/{$t3}/labels"), 'A3 labels')['labels'][0]['payload'];
$ok($review($iuliana, $x3['id'], ['decision' => 'accept', 'reasonKey' => 'material-defect', 'qrToken' => $label3]), '09. the supervisor accepts the exact piece with its label');
$ok($sendReturn($iuliana, $x3['id']), '09. return to cutting');
$ok($ack($murat, $x3['id'], $label3, ['responsibility' => 'accepted']), '10. the cutter confirms receipt of that piece');
$ok($decide($denisa, $x3['id'], ['decision' => 'approve', 'attribution' => 'none']), '11. the manager approves');
$check($track($t3)['stage_id'] === 'material-preparation' && (int) $track($t3)['rework_cycle'] === 2 && $state($a3)['stage'] === 'ironing', '08. only piece 1 returns; piece 2 keeps stage 8');
$ok($trackComplete($murat, $t3, ['pieceOutcome' => 'repair']), '14. the piece is repaired');
$guard = 0;
while (($stage3 = $track($t3)['stage_id']) !== 'bottom-hem') {
    $who3 = $staffBy($stage3);
    $ok($trackClaim($who3, $t3, $label3), "repaired piece claimed at {$stage3}");
    $ok($trackComplete($who3, $t3), "repaired piece completed at {$stage3}");
    $check(++$guard < 10, 'terminates');
}
$check($track($t3)['stage_id'] === 'bottom-hem', '07. the repaired PERDEA piece replays stage 6 (no automatic bypass in rework)');
$ok($trackClaim($hemmer, $t3, $label3), 'stage 6 claims the repaired piece');
$blocked3 = $error($trackComplete($hemmer, $t3), 409, 'ASSISTED_VERIFICATION_REQUIRED', '07/15. a cycle-1 check never satisfies cycle 2');
$check($blocked3['error']['details']['pieces'][0]['unitId'] === $p3a && $checkpoint($p3a, 1, 'bottom-hem')['status'] === 'verified', 'the cycle-1 history is preserved and not reused');
$error($act($hemmer, $p3a, 'bottom-hem', 'verify', 'ARASYA:U1:' . str_repeat('B', 26), 'performed', 2), 422, 'INVALID_QR', '22.1 an unknown piece label identifies nothing');
$ok($act($hemmer, $p3a, 'bottom-hem', 'verify', $label3, 'performed', 2), '07. cycle-2 check at stage 6 with the piece label');
$ok($trackComplete($hemmer, $t3), 'stage 6 of the repaired piece');
$ok($trackClaim($hemmer, $t3, $label3), 'stage 7 claims the repaired piece');
$error($trackComplete($hemmer, $t3), 409, 'ASSISTED_VERIFICATION_REQUIRED', '07. stage 7 needs its own cycle-2 check');
$ok($act($hemmer, $p3a, 'side-hem', 'verify', $label3, 'performed', 2), '07. cycle-2 check at stage 7');
$ok($trackComplete($hemmer, $t3), 'stage 7 of the repaired piece');
$check($track($t3)['stage_id'] === 'ironing' && $stageLog($p3a, 2, 'bottom-hem') === 0 && $stageLog($p3a, 2, 'side-hem') === 0 && $stageLog($p3a, 2, 'material-straightening') === 1, '10. the corrected piece reaches stage 8 through every stage; 6/7 by verification only');
$ok($trackClaim($ironer, $t3, $label3), 'ironing claims the repaired piece');
$error($trackComplete($ironer, $t3), 422, 'DEFECT_RESOLUTION_REQUIRED', '10.1 the defect resolution is confirmed for the current cycle');
$ok($post($kadyrova, '/production-units/reunite', ['unitIds' => [$p3a, $p3b], 'confirmed' => true]), '16. physical reunion at stage 8 (no stage change)');
$ok($trackComplete($ironer, $t3, ['defectResolved' => true]), '16. resolved and moving on');
$check($checkpoint($p3b, 1, 'bottom-hem')['status'] === 'verified' && $checkpoint($p3b, 2, 'bottom-hem') === null, '11. the healthy piece keeps its cycle-1 checks and needs no new one');
$advance($kadyrova, $a3);
$bring($a3, 'packing', $staffBy);
$error($post($finisher, $a3['path'] . '/claim', ['expectedVersion' => (int) $state($a3)['version']]), 409, 'ORDER_PIECES_IN_REWORK', '28. packing waits for the repaired piece');
$guard = 0;
while (($stage3 = $track($t3)['stage_id']) !== 'packing') {
    $ok($trackClaim($finisher, $t3, $label3), "repaired piece claimed at {$stage3}");
    $ok($trackComplete($finisher, $t3), "repaired piece completed at {$stage3}");
    $check(++$guard < 10, 'terminates');
}
// Packing gate: remove the verified cycle-2 stage-7 check for a moment; packing must fail closed.
$saved3 = $checkpoint($p3a, 2, 'side-hem');
$pdo->prepare("UPDATE production_assisted_checkpoints SET status = 'not_performed' WHERE unit_uuid = ? AND rework_cycle = 2 AND stage_id = 'side-hem'")->execute([$p3a]);
$error($post($finisher, $a3['path'] . '/claim', ['expectedVersion' => (int) $state($a3)['version']]), 409, 'ASSISTED_VERIFICATION_REQUIRED', '20/28. packing refuses a PERDEA piece without its verified stage-7 check');
$pdo->prepare('UPDATE production_assisted_checkpoints SET status = ? WHERE unit_uuid = ? AND rework_cycle = 2 AND stage_id = ?')->execute([$saved3['status'], $p3a, 'side-hem']);
$finish($a3);
$check($state($a3)['completed'] !== null && (int) $one('SELECT COUNT(*) FROM production_assisted_checkpoints WHERE unit_uuid = ?', [$p3a]) === 4, '07. piece 1: four separate checks (two stages x two cycles)');
$history3 = $ok($get($iuliana, "/production-units/{$p3a}/history"), 'A3 piece history');
$check(count($history3['assistedCheckpoints']) === 4 && array_column($history3['assistedCheckpoints'], 'reworkCycle') === [1, 1, 2, 2], '14. the piece history shows every check with its cycle: ' . json_encode(array_column($history3['assistedCheckpoints'], 'reworkCycle')));

// ==== A4. Historical transition: a version-1 exemption stays valid; nothing is backfilled =============================
$a4 = $trendyol('N', [$line('Perdea veche', 'P-930', 1, 2.0, 'curtain')]);
$cohort($a4, FeatureActivation::PIECE_ROUTING);
$ok($confirm($iuliana, $a4, $a4['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'o bucată']), 'A4 plan');
$u4 = WorkUnits::unitUuid($a4['items'][0], 1);
$cut($murat, $a4);
$bring($a4, 'ironing', $staffBy);
$d4 = $rows('SELECT kind, policy_version, skipped_stages_json FROM production_route_decisions WHERE order_uuid = ?', [$a4['uuid']]);
$check(count($d4) === 1 && $d4[0]['kind'] === 'stage_not_applicable' && (int) $d4[0]['policy_version'] === 1 && $ledger($u4, 1)['bottom-hem'] === 'not_applicable', '24. version 1 skipped 6/7 with an auditable exemption');
$cohort($a4, $assisted);
$bring($a4, 'packing', $staffBy);
$finish($a4);
$check($state($a4)['completed'] !== null, '24. the order adopted version 2 after its exemption: the historical exemption stays valid');
$check((int) $one('SELECT COUNT(*) FROM production_assisted_checkpoints WHERE order_uuid = ?', [$a4['uuid']]) === 0 && $rows('SELECT kind, policy_version, skipped_stages_json FROM production_route_decisions WHERE order_uuid = ?', [$a4['uuid']]) === $d4 && $ledger($u4, 1)['side-hem'] === 'not_applicable', '24. no checkpoint is fabricated, no decision or ledger row is rewritten');

// ==== A5. Feature off: unchanged behaviour ==========================================================================
$a5 = $trendyol('O', [$line('Perdea fără pilot', 'P-940', 1, 2.0, 'curtain')]);
$ok($confirm($iuliana, $a5, $a5['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'o bucată']), 'A5 plan');
$u5 = WorkUnits::unitUuid($a5['items'][0], 1);
$cut($murat, $a5);
$bring($a5, 'bottom-hem', $staffBy);
$error($act($hemmer, $u5, 'bottom-hem', 'verify', $a5['qr'], 'performed'), 409, 'ASSISTED_CHECKPOINT_NOT_APPLICABLE', '34. outside the cohort no checkpoint exists');
$check($ok($get($hemmer, $a5['path']), 'A5 view')['pieces']['assistedCheckpoints'] === null, '34. the order view shows no checkpoint');
$claim($hemmer, $a5);
$advance($hemmer, $a5);
$check($state($a5)['stage'] === 'side-hem' && $stageLog($u5, 1, 'bottom-hem') === 1, '34. flags off: the existing full route, exactly as before');

// ==== A6. Version 2 with an unconfirmed plan fails closed at stage 6 ==================================================
$a6 = $trendyol('P', [$line('Perdea neconfirmată', 'P-950', 1, 2.0, 'curtain')]);
$cohort($a6, $assisted);
$cut($murat, $a6);
$bring($a6, 'bottom-hem', $staffBy);
$claim($hemmer, $a6);
$error($transition($hemmer, $a6), 409, 'PLAN_CONFIRMATION_REQUIRED', '20.3 unknown pieces are not treated as verified');
$check((int) $one('SELECT COUNT(*) FROM production_work_units WHERE order_uuid = ?', [$a6['uuid']]) === 0, '4. no piece is invented for an unconfirmed plan');

// ==== A7. Concurrency, physical hold, deactivation ====================================================================
$a7 = $trendyol('Q', [$line('Perdea concurență', 'P-960', 1, 2.0, 'curtain')]);
$cohort($a7, FeatureActivation::PIECE_ROUTING, $assisted);
$ok($confirm($iuliana, $a7, $a7['items'][0], ['expectedRevision' => 0, 'productType' => 'perdea', 'pieceCount' => 1, 'note' => 'o bucată']), 'A7 plan');
$u7 = WorkUnits::unitUuid($a7['items'][0], 1);
$cut($murat, $a7);
$bring($a7, 'bottom-hem', $staffBy);
$body7 = ['action' => 'verify', 'stageId' => 'bottom-hem', 'outcome' => 'performed', 'expectedVersion' => 0, 'qrToken' => $a7['qr']];
$raced = $race([[$hemmer, "/production-units/{$u7}/assisted-checkpoint", $body7, $key('race'), null], [$tailor2, "/production-units/{$u7}/assisted-checkpoint", $body7, $key('race'), null]]);
$statuses = array_column($raced, 'status');
sort($statuses);
$check($statuses === [200, 409] && in_array('ASSISTED_CHECKPOINT_CHANGED', array_column($raced, 'error'), true), '26. two stage employees verify the same curtain at once: exactly one wins: ' . json_encode($raced));
$check((int) $one('SELECT COUNT(*) FROM production_assisted_checkpoint_events WHERE unit_uuid = ?', [$u7]) === 1, '26. one event');
$ok($post($iuliana, "/production-units/{$u7}/custody", ['action' => 'hold', 'holdReason' => 'inspection', 'expectedVersion' => 0, 'qrToken' => $a7['qr']]), 'the supervisor holds the curtain');
$error($act($hemmer, $u7, 'bottom-hem', 'verify', $a7['qr'], 'not_performed'), 409, 'ORDER_PIECES_HELD', '21.4 a held piece cannot be verified');
$ok($post($iuliana, "/production-units/{$u7}/custody", ['action' => 'release', 'expectedVersion' => 1, 'qrToken' => $a7['qr']]), 'released');
$hemmer2 = $identity('hemmer2', 'Test Second Hemmer', ['bottom-hem', 'side-hem']);
$ok($dash($root, 'POST', "/management/employees/{$uuids['hemmer2']}/deactivate"), 'the second hemmer leaves');
$gone = $act($hemmer2, $u7, 'bottom-hem', 'verify', $a7['qr'], 'not_performed');
$check(in_array($gone['status'], [401, 403], true) && $checkpoint($u7, 1, 'bottom-hem')['status'] === 'verified', '27. a deactivated employee with an open page changes nothing: ' . $gone['status']);
$error($act($kadyrova, $u7, 'side-hem', 'verify', $a7['qr'], 'performed'), 409, 'ASSISTED_STAGE_MISMATCH', 'no check of a stage the piece has not reached');

// ==== A8. Employee KPI: verification is not manufacturing ============================================================
$employees8 = [];
$cursor8 = null;
do {
    $page8 = $ok($dashGet($root, '/management/analytics/employees', ['period' => 'month', 'limit' => '100'] + ($cursor8 === null ? [] : ['cursor' => $cursor8])), 'analytics employees');
    foreach ($page8['items'] as $item8) $employees8[$item8['id']] = $item8;
    $cursor8 = $page8['nextCursor'] ?? null;
} while ($cursor8 !== null);
$h8 = $employees8[$uuids['hemmer']];
$check($h8['assistedCheckpoints']['verified'] === (int) $one("SELECT COUNT(*) FROM production_assisted_checkpoints WHERE verified_by_employee_uuid = ? AND status = 'verified'", [$uuids['hemmer']]), '30. verifications are counted as verifications: ' . json_encode($h8['assistedCheckpoints']));
$check($h8['assistedCheckpoints']['notPerformed'] === 2, '30. negative checks are counted apart, never as work');
$check($employees8[$uuids['labeler']]['assistedCheckpoints']['helperWork'] === 1 && $employees8[$uuids['tailor2']]['assistedCheckpoints']['helperWork'] === 1, '3.3 the recorded helper is credited once for verified work only (the rejected attempt is not credited)');
$marks8 = implode(',', array_fill(0, 2, '?'));
$expectedStage6 = (int) $one("SELECT COUNT(*) FROM order_activity_events WHERE employee_uuid = ? AND action = 'stage_completed' AND from_stage_id = 'bottom-hem' AND order_uuid NOT IN ({$marks8})", [$uuids['hemmer'], $a1['uuid'], $a3['uuid']]);
$check(($h8['stageCompletions']['bottom-hem'] ?? 0) === $expectedStage6 && $expectedStage6 === 2, '30. a PERDEA-only transition at 6 earns no stage completion; a mixed basket (DRAPERII work) does: ' . json_encode($h8['stageCompletions']));
$check($h8['assistedCheckpoints']['stageTransitions'] === 6 && !isset($h8['trackStageCompletions']['bottom-hem']) && !isset($h8['trackStageCompletions']['side-hem']), '30. the PERDEA-only transitions (order and track) are reported as verification transitions: ' . json_encode([$h8['assistedCheckpoints'], $h8['trackStageCompletions']]));
$check((int) $one('SELECT COUNT(*) FROM production_unit_stage_log l INNER JOIN production_assisted_checkpoints c ON c.unit_uuid = l.unit_uuid AND c.rework_cycle = l.rework_cycle AND c.stage_id = l.stage_id') === 0, '30. no verified PERDEA piece has a manufacturing completion for the same stage and cycle');
foreach (['hemmer', 'tailor2', 'labeler'] as $who8) {
    $row8 = $employees8[$uuids[$who8]];
    $check($row8['pieceWork']['order'] + $row8['pieceWork']['route'] + $row8['pieceWork']['rework'] === (int) $one("SELECT COUNT(*) FROM production_unit_stage_log WHERE employee_uuid = ? AND outcome = 'completed'", [$uuids[$who8]]), "30. piece work of {$who8} equals its own completions");
}
$departments8 = $ok($dashGet($root, '/management/analytics/departments', ['period' => 'month', 'limit' => '100']), 'analytics departments')['items'];
$check(array_sum(array_map(static fn (array $d): int => $d['assistedCheckpoints']['verified'], $departments8)) === (int) $one("SELECT COUNT(*) FROM production_assisted_checkpoints WHERE status = 'verified'"), '17.6 department totals equal the verified checks');

// ==== A9. Rollback guard, operator CLI, route audit ===================================================================
$check($cli('check-rollback.php', '2.33.0')['code'] === 3 && $cli('check-rollback.php', '2.34.0')['code'] === 0, '35. an API older than 2.34.0 is refused while an open order has checkpoints');
$original31 = (string) $one("SELECT checksum FROM schema_migrations WHERE migration_name = '031_production_assisted_checkpoints.sql'");
$pdo->exec("UPDATE schema_migrations SET checksum = REPEAT('0', 64) WHERE migration_name = '031_production_assisted_checkpoints.sql'");
try {
    $check($cli('check-rollback.php', '2.34.0')['code'] === 1, '35. an unknown 031 refuses rollback');
} finally {
    $pdo->prepare("UPDATE schema_migrations SET checksum = ? WHERE migration_name = '031_production_assisted_checkpoints.sql'")->execute([$original31]);
}
$rollback9 = $cli('partial-rework.php', 'rollback-check');
$check($rollback9['code'] === 3 && ($rollback9['json']['openOrdersWithAssistedCheckpoints'] ?? 0) >= 1, '35. the operator rollback check names the open checkpoint orders: ' . $rollback9['out']);
$check($cli('partial-rework.php', 'assisted-enable', '--operator=test', '--confirm=WRONG')['code'] === 2, '34. global activation needs the exact confirmation phrase');
$status9 = $cli('partial-rework.php', 'status');
$check($status9['code'] === 0 && $status9['json']['perdeaAssistedCheckpoints'] === false && $status9['json']['perdeaAssistedCheckpointsCohort'] >= 5, '34. the flag stays off globally; only the audited cohort is active: ' . $status9['out']);
$cohortCli = $cli('partial-rework.php', 'cohort-add', '--feature=perdea_assisted_checkpoints', '--order=' . $a5['id'], '--operator=test-operator', '--reason=pilot');
$check($cohortCli['code'] === 0 && $cohortCli['json']['member'] === true && (int) $one("SELECT COUNT(*) FROM production_feature_flag_events WHERE feature_key = 'perdea_assisted_checkpoints' AND action = 'cohort_added' AND order_uuid = ?", [$a5['uuid']]) === 1, '34. cohort changes are audited');
$audit9 = $cli('partial-rework.php', 'route-audit', '--details=1');
$byOrder9 = array_column($audit9['json']['orders'] ?? [], null, 'order');
$check($audit9['code'] === 0 && ($byOrder9[$a6['id']]['routePolicyVersion'] ?? null) === 2 && ($byOrder9[$a7['id']]['routePolicyVersion'] ?? null) === 2 && $audit9['json']['schema']['assistedCheckpoints'] === true, '19.2 the read-only route audit reports the policy version per order');

fwrite(STDOUT, "OK MySQL PERDEA assisted checkpoints integration ({$checks} checks)\n");
