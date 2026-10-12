<?php

declare(strict_types=1);

// Tailoring quality supervision (migration 024, API 2.28.0) against a dedicated MySQL/MariaDB test database.
// The rule under test: defects found at stages 3-12 are handled inside tailoring by the appointed supervisor
// without any operations-manager decision; only the final workshop-receiving -> material-preparation return
// needs one. Also covers physical custody versus production ownership, both rejection recoveries, separate
// KPI attribution (detector, supervisor, responsible cutter, receiver, manager), substitutes for an
// unavailable cutter, revoked authority, idempotent replays and concurrent commands.

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
    fwrite(STDOUT, "SKIP MySQL tailoring supervision integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}
const DASHBOARD = 'http://127.0.0.1:4174';
$config = T::config($dbName, [T::ORIGIN, DASHBOARD]);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
$seeds = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seeds, SORT_STRING);
foreach ($seeds as $seed) {
    (new SqlFileRunner($pdo))->run($seed);
}
foreach (['live_events', 'production_exception_idempotency', 'production_quality_events', 'production_exception_events', 'production_exception_lines'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE production_exception_decisions SET previous_decision_uuid = NULL');
$pdo->exec('DELETE FROM production_exception_decisions');
$pdo->exec('UPDATE operational_orders SET open_exception_uuid = NULL');
foreach (['production_exceptions', 'responsibility_assignments', 'organization_principals'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
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
$password = 'tailoring supervision passphrase 2026';
$rootName = "supervision.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-supervision-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Supervision root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Supervision root passphrase 2026!');
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string { $keys++; return sprintf('sup-%s-%s-%04d', $label, $suffix, $keys); };
$dash = static fn (array $who, string $method, string $path, ?array $body = null, ?string $idem = null): array => T::call($kernel, $method, $path, $body, ['origin' => DASHBOARD, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'sup-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$dashGet = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => DASHBOARD], $who['cookie'], $query);
$post = static fn (array $who, string $path, array $body, ?string $idem = null): array => T::call($kernel, 'POST', $path, $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'sup-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $query);
$roleIds = [];
foreach ($ok($dashGet($root, '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];

// ---- Identities ------------------------------------------------------------------------------------------------
$tailoringStages = ['workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control'];
$uuids = [];
$identity = static function (string $name, string $display, array $stages, array $apps = ['staff'], array $roles = []) use ($container, $dash, $root, $ok, $password, $suffix, $kernel, &$uuids): array {
    $employee = $container->employeeAdmin()->create($display, "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test-supervision');
    $uuids[$name] = $employee->employeeUuid;
    $ok($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]), "{$name} applications");
    if ($roles !== []) $ok($dash($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['employeeUuid' => $employee->employeeUuid];
};
$cutterA = $identity('cuttera', 'Test Cutter A', ['material-preparation']);
$cutterB = $identity('cutterb', 'Test Cutter B', ['material-preparation']);
$tailor = $identity('tailor', 'Test Tailor Seven', $tailoringStages);
$tailor2 = $identity('tailor2', 'Test Tailor Two', $tailoringStages);
// The original supervisor identity and a replacement with the same display name: only an explicit
// appointment of a UUID grants supervision, never the name.
$iuliana = $identity('iuliana', "VASILOAIA IULIANA {$suffix}", $tailoringStages);
$iuliana1 = $identity('iuliana1', "VASILOAIA IULIANA {$suffix}", $tailoringStages);
$noIntake = $identity('nointake', 'Test Label Only', ['labeling']);
$denisa = $identity('denisa', 'Test Denisa', [], ['dashboard'], [$roleIds['operations-manager']]);
$hikmet = $identity('hikmet', 'Test Hikmet', [], ['dashboard'], [$roleIds['operations-manager']]);
$ceo = $identity('ceo', 'Test CEO', [], ['dashboard'], [$roleIds['ceo']]);
$ok($dash($root, 'PUT', '/management/organization/ceo', ['employeeId' => $uuids['ceo']]), 'root designates the CEO');

// ---- Orders --------------------------------------------------------------------------------------------------
$lines = static fn (): array => [
    ['id' => 1, 'line' => 1, 'name' => 'Voal A', 'sku' => 'SKU-1', 'color' => 'Ivory', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => 5.0, 'quantity' => 2],
    ['id' => 2, 'line' => 2, 'name' => 'Voal B', 'sku' => 'SKU-2', 'color' => 'Ivory', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => 7.0, 'quantity' => 2],
    ['id' => 3, 'line' => 3, 'name' => 'Draperie C', 'sku' => 'SKU-3', 'color' => 'Gri', 'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => 8.0, 'quantity' => 1],
];
$order = static function (string $tag, string $source) use ($suffix, $kernel, $ok, $one, $rows, $lines): array {
    $number = "TS{$tag}{$suffix}";
    $ok(T::ingest($kernel, $source, T::sourceOrder($number, "evt-ts-{$tag}-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 300), T::stage('material-preparation'), 'processing', 'active', $lines(), $number)), "ingest {$tag}");
    $id = "{$source}:{$number}";
    $uuid = (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$id]);
    return ['id' => $id, 'uuid' => $uuid, 'path' => '/orders/' . rawurlencode($id),
        'qr' => 'ARASYA:Q1:' . (string) $one("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$uuid]),
        'items' => array_column($rows('SELECT item_uuid FROM operational_order_items WHERE order_uuid = ? ORDER BY line_number', [$uuid]), 'item_uuid')];
};
$state = static fn (array $o): array => $rows('SELECT production_stage_id AS stage, production_owner_employee_uuid AS owner, production_version AS version, open_exception_uuid AS exception FROM operational_orders WHERE order_uuid = ?', [$o['uuid']])[0];
$claim = static function (array $who, array $o) use ($post, $ok, $state, $pdo, $key): void {
    $ok($post($who, $o['path'] . '/claim', T::cuttingClaim($pdo, $o['id'], $who['employeeUuid'] ?? '', (int) $state($o)['version']), $key('claim')), "claim {$o['id']}");
};
$advance = static function (array $who, array $o) use ($post, $ok, $state, $key): void {
    $ok($post($who, $o['path'] . '/transition', ['expectedVersion' => (int) $state($o)['version']], $key('move')), "transition {$o['id']}");
};
// Cut by the given cutter, then carried through tailoring by the tailor up to the target stage (owned there).
$bring = static function (array $o, array $cutter, string $target) use ($claim, $advance, $state, $tailor, $check): void {
    $claim($cutter, $o);
    $advance($cutter, $o);
    while (true) {
        $claim($tailor, $o);
        if ($state($o)['stage'] === $target) break;
        $advance($tailor, $o);
    }
    $check($state($o)['stage'] === $target, "order at {$target}");
};
$session = static fn (array $who): array => $ok($get($who, '/auth/session'), 'session')['employee'];
$exVersion = static fn (string $id): int => (int) $one('SELECT version FROM production_exceptions WHERE exception_uuid = ?', [$id]);
$report = static fn (array $who, array $o, array $items, string $reason, ?string $comment, ?string $idem = null) => $post($who, $o['path'] . '/fault-reports', array_filter(['expectedVersion' => (int) $state($o)['version'], 'itemIds' => $items, 'reasonKey' => $reason, 'comment' => $comment], static fn ($v) => $v !== null), $idem);
$review = static fn (array $who, string $id, array $body, ?string $idem = null) => $post($who, "/production-exceptions/{$id}/review", ['expectedVersion' => $exVersion($id)] + $body, $idem);
$sendReturn = static fn (array $who, string $id, array $body = [], ?string $idem = null) => $post($who, "/production-exceptions/{$id}/return", ['expectedVersion' => $exVersion($id)] + $body, $idem);
$ack = static fn (array $who, string $id, string $qr, array $body = [], ?string $idem = null) => $post($who, "/production-exceptions/{$id}/acknowledge", ['expectedVersion' => $exVersion($id), 'confirmed' => true, 'qrToken' => $qr] + $body, $idem);
$decide = static fn (array $who, string $id, array $body, ?string $idem = null) => $dash($who, 'POST', "/management/production-exceptions/{$id}/decision", ['expectedVersion' => $exVersion($id)] + $body, $idem);
$restore = static fn (array $who, string $id, string $qr, string $comment, ?string $idem = null) => $post($who, "/production-exceptions/{$id}/restore", ['expectedVersion' => $exVersion($id), 'qrToken' => $qr, 'comment' => $comment], $idem);
$quality = static fn (string $id): array => $rows('SELECT event_type, employee_uuid, rework_cycle, is_repeat, line_count, meters FROM production_quality_events WHERE exception_uuid = ? ORDER BY event_type', [$id]);
$decisions = static fn (string $id): int => (int) $one('SELECT COUNT(*) FROM production_exception_decisions WHERE exception_uuid = ?', [$id]);
$cursor = static function (array $who) use ($get): int {
    $frames = (string) $get($who, '/live/events')['raw'];
    preg_match('/"cursor":(\d+)/', $frames, $m);
    return (int) ($m[1] ?? 0);
};
$liveTypes = static function (array $who, int $after) use ($get): array {
    preg_match_all('/^event: ([a-z._]+)$/m', (string) $get($who, '/live/events', ['after' => (string) $after])['raw'], $m);
    return array_values(array_filter($m[1], static fn (string $t): bool => $t !== 'cursor'));
};

// ---- Before any appointment: the original intake-only flow is unchanged ----------------------------------------
$pre = $order('PRE', 'trendhome');
$bring($pre, $cutterA, 'side-hem');
$error($report($tailor, $pre, [$pre['items'][0]], 'cut-meterage', null), 409, 'EXCEPTION_STAGE_INVALID', 'without a supervisor a stage-7 report is not available (intake-only flow)');
$check($ok($get($iuliana, '/production-exceptions/capabilities'), 'caps before') === ['tailoringSupervisor' => false, 'supervisedSources' => [], 'supervisedFlowActive' => false], 'nobody supervises before the appointment');
$error($get($iuliana, '/production-exceptions/supervisor', ['view' => 'review']), 403, 'SUPERVISOR_REQUIRED', 'the supervisor list needs the appointment');

// ---- Appointment: CEO or root only, one explicit UUID, never a name ---------------------------------------------
$appoint = static fn (array $who, string $employee, ?string $endsAt = null) => $dash($who, 'POST', '/management/organization/responsibilities', array_filter(['responsibility' => 'tailoring_quality_supervisor', 'employeeId' => $employee, 'endsAt' => $endsAt], static fn ($v) => $v !== null));
$error($appoint($denisa, $uuids['iuliana']), 403, 'UNAUTHORIZED_ACTION', 'an operations manager cannot appoint the supervisor');
$error($appoint($ceo, $uuids['nointake']), 422, 'EMPLOYEE_NEEDS_TAILORING_INTAKE', 'the supervisor must work at tailoring intake');
$error($appoint($ceo, $uuids['denisa']), 422, 'EMPLOYEE_NEEDS_TAILORING_INTAKE', 'a Dashboard-only manager cannot be the supervisor');
$appointed = $ok($appoint($ceo, $uuids['iuliana']), 'the CEO appoints the original identity', 201);
$active = array_values(array_filter($appointed['responsibilities'], static fn (array $r): bool => $r['responsibility'] === 'tailoring_quality_supervisor' && $r['state'] === 'active'));
$check(count($active) === 1 && $active[0]['employee']['id'] === $uuids['iuliana'], 'exactly one active supervisor appointment, for the original UUID');
$check($ok($get($iuliana, '/production-exceptions/capabilities'), 'caps') === ['tailoringSupervisor' => true, 'supervisedSources' => ['trendyol', 'trendhome', 'outletperdele'], 'supervisedFlowActive' => true], 'the appointed UUID supervises the three canonical sources');
$check($ok($get($iuliana1, '/production-exceptions/capabilities'), 'caps duplicate')['tailoringSupervisor'] === false, 'the same display name on another UUID grants nothing');
$check($ok($get($tailor2, '/production-exceptions/capabilities'), 'caps worker')['tailoringSupervisor'] === false, 'working in the same stages grants nothing');
$check($session($iuliana)['permissions'] === $session($iuliana1)['permissions'] && !in_array('production.exceptions.approve', $session($iuliana)['permissions'], true), 'the appointment adds no permission and no approval right');
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'responsibility.assigned' AND metadata_json LIKE '%tailoring_quality_supervisor%'") >= 1, 'the appointment is audited');

// ==== Scenario 1: stage 7 -> report -> supervisor -> stage 3 (no manager) -> cutter receipt -> manager -> stage 2 ====
$a = $order('A', 'trendhome');
$bring($a, $cutterA, 'side-hem');
$beforeReport = $state($a);
$check($beforeReport['owner'] === $tailor['employeeUuid'] && $beforeReport['exception'] === null, 'stage 7 (side-hem) is owned by the tailor');
$cursorSup = $cursor($iuliana);
$cursorMgr = $cursor($denisa);
$cursorCut = $cursor($cutterA);
$error($report($tailor2, $a, [$a['items'][2]], 'cut-meterage', null), 403, 'FAULT_REPORT_NOT_ALLOWED', 'only the owner at the current stage reports');
$error($report($cutterA, $a, [$a['items'][2]], 'cut-meterage', null), 403, 'FAULT_REPORT_NOT_ALLOWED', 'the cutter cannot report');
$reportKey = $key('report-a');
$reported = $ok($report($tailor, $a, [$a['items'][1], $a['items'][2]], 'cut-meterage', 'Lungimea este mai scurtă cu 20 cm', $reportKey), 'the stage-7 worker reports', 201);
$xa = $reported['id'];
$check($reported['type'] === 'tailoring_defect' && $reported['status'] === 'awaiting_supervisor_review' && $reported['detectedStage']['id'] === 'side-hem' && $reported['faultMeters'] === '15.000' && $reported['role'] === 'detector', 'the report waits for the supervisor, lines 7 + 8 m: ' . json_encode($reported));
$check($post($tailor, $a['path'] . '/fault-reports', ['expectedVersion' => (int) $beforeReport['version'], 'itemIds' => [$a['items'][2], $a['items'][1]], 'reasonKey' => 'cut-meterage', 'comment' => 'Lungimea este mai scurtă cu 20 cm'], $reportKey)['body'] === $reported, 'a lost report answer is replayed, never duplicated');
$check((int) $one('SELECT COUNT(*) FROM production_exceptions WHERE order_uuid = ?', [$a['uuid']]) === 1, 'one report exists');
$afterReport = $state($a);
$check($afterReport['stage'] === 'side-hem' && $afterReport['owner'] === $tailor['employeeUuid'] && $afterReport['exception'] === $xa && (int) $afterReport['version'] === (int) $beforeReport['version'] + 1, 'the order stays at stage 7 with its owner, blocked');
$error($post($tailor, $a['path'] . '/transition', ['expectedVersion' => (int) $afterReport['version']]), 409, 'ORDER_BLOCKED_BY_EXCEPTION', 'the worker cannot finish the stage while the report is open');
$check($quality($xa) === [], 'an unverified report creates no KPI fact');
$check($decisions($xa) === 0, 'no manager decision exists for an internal report');
$check(in_array('exception.review_required', $liveTypes($iuliana, $cursorSup), true), 'the supervisor is notified in Staff');
$check(!in_array('exception.waiting_worker', $liveTypes($denisa, $cursorMgr), true) && !in_array('exception.approval_pending', $liveTypes($denisa, $cursorMgr), true), 'managers are not involved in an internal report');
$check(array_values(array_filter($liveTypes($cutterA, $cursorCut), static fn (string $t): bool => str_starts_with($t, 'exception.'))) === [], 'the cutter is not told about an internal report (only the generic cutting board refresh)');
foreach (['pending', 'waiting'] as $view) $check(!in_array($xa, array_column($ok($dashGet($denisa, '/management/production-exceptions', ['view' => $view]), "manager {$view}")['items'], 'id'), true), "the manager {$view} queue does not contain the internal report");
$error($get($cutterA, "/production-exceptions/{$xa}"), 404, 'EXCEPTION_NOT_FOUND', 'the case stays internal to tailoring until the return');
$check(!in_array($xa, array_column($ok($get($cutterA, '/production-exceptions/mine'), 'cutter list')['items'], 'id'), true), 'not in the cutter list yet');
$detectorView = $ok($get($tailor, "/production-exceptions/{$xa}"), 'detector view');
$check($detectorView['actions']['canReview'] === false && $detectorView['actions']['canAddNote'] === true, 'the detector follows the report and may add information');
$ok($post($tailor, "/production-exceptions/{$xa}/notes", ['expectedVersion' => $exVersion($xa), 'comment' => 'Produsul este pe masa 3.']), 'the worker adds information');
$reviewList = $ok($get($iuliana, '/production-exceptions/supervisor', ['view' => 'review']), 'supervisor review tab');
$check(array_column($reviewList['items'], 'id') === [$xa] && $reviewList['items'][0]['actions']['canReview'] === true, 'the case is in "De verificat"');
$supView = $ok($get($iuliana, "/production-exceptions/{$xa}"), 'supervisor opens the case');
$check($supView['cuttingHandoff']['employee'] === 'Test Cutter A' && $supView['responsible']['displayName'] === 'Test Cutter A' && $supView['currentStage']['id'] === 'side-hem', 'the cutting handoff is derived from history');
$noteVersion = $state($a);
$check($noteVersion['stage'] === 'side-hem' && $noteVersion['owner'] === $tailor['employeeUuid'] && $noteVersion['version'] === $afterReport['version'], 'viewing and notes never change stage, ownership or the production version');
$error($review($iuliana1, $xa, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $a['qr']]), 403, 'SUPERVISOR_REQUIRED', 'the duplicate identity cannot supervise');
$error($review($tailor2, $xa, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $a['qr']]), 403, 'SUPERVISOR_REQUIRED', 'an ordinary worker cannot supervise');
$error($review($iuliana, $xa, ['decision' => 'accept', 'reasonKey' => 'cut-meterage']), 400, 'QR_REQUIRED', 'acceptance needs the product (QR scan)');
$error($review($iuliana, $xa, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $pre['qr']]), 422, 'QR_ORDER_MISMATCH', 'the QR of another order is refused');
$error($review($iuliana, $xa, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => 'ARASYA:Q1:AAAAAAAAAAAAAAAAAAAAAAAAAA']), 422, 'INVALID_QR', 'an unknown QR is refused');
$error($review($iuliana, $xa, ['decision' => 'dismiss']), 422, 'COMMENT_REQUIRED', 'a dismissal needs a reason');
$acceptKey = $key('accept-a');
$versionBeforeAccept = $exVersion($xa);
$accepted = $ok($review($iuliana, $xa, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $a['qr'], 'comment' => 'Confirm, metraj scurt'], $acceptKey), 'the supervisor accepts');
$check($accepted['status'] === 'in_supervisor_custody' && $accepted['confirmedReason']['key'] === 'cut-meterage' && $accepted['supervisor']['displayName'] === "VASILOAIA IULIANA {$suffix}" && $accepted['physicalCustodian']['displayName'] === "VASILOAIA IULIANA {$suffix}", 'the supervisor holds the case and the product');
$check($post($iuliana, "/production-exceptions/{$xa}/review", ['expectedVersion' => $versionBeforeAccept, 'decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $a['qr'], 'comment' => 'Confirm, metraj scurt'], $acceptKey)['body'] === $accepted, 'a lost answer is replayed with the same key');
$custody = $state($a);
$check($custody['stage'] === 'workshop-receiving' && $custody['owner'] === null && $custody['exception'] === $xa && (int) $custody['version'] === (int) $afterReport['version'] + 1, 'stage 7 -> stage 3 under supervisor custody, no production owner, still blocked');
$check($decisions($xa) === 0, 'the internal 7 -> 3 return created no manager decision');
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE target_id = ? AND action LIKE 'production.exception.%'", [$xa]) === 0, 'no manager approval audit for an internal move');
$check($quality($xa) == [['event_type' => 'fault_detected', 'employee_uuid' => $tailor['employeeUuid'], 'rework_cycle' => null, 'is_repeat' => 0, 'line_count' => 2, 'meters' => '15.000']], 'the verified detection is credited to the detector, once: ' . json_encode($quality($xa)));
$custodyEvent = $rows("SELECT action, employee_uuid, from_stage_id, to_stage_id, previous_owner_employee_uuid, new_owner_employee_uuid FROM order_activity_events WHERE order_uuid = ? AND action = 'defect_custody_taken'", [$a['uuid']]);
$check($custodyEvent === [['action' => 'defect_custody_taken', 'employee_uuid' => $iuliana['employeeUuid'], 'from_stage_id' => 'side-hem', 'to_stage_id' => 'workshop-receiving', 'previous_owner_employee_uuid' => $tailor['employeeUuid'], 'new_owner_employee_uuid' => null]], 'an explicit exceptional-return event, not a stage completion');
$check((int) $one("SELECT COUNT(*) FROM order_activity_events WHERE order_uuid = ? AND action = 'stage_completed' AND from_stage_id = 'bottom-hem' AND to_stage_id = 'side-hem'", [$a['uuid']]) === 1, 'the stage-7 history is kept');
$error($post($tailor2, $a['path'] . '/claim', ['expectedVersion' => (int) $custody['version']]), 409, 'ORDER_BLOCKED_BY_EXCEPTION', 'nobody can claim the order at stage 3 while the supervisor holds it');
$check(array_column($ok($get($iuliana, '/production-exceptions/supervisor', ['view' => 'custody']), 'custody tab')['items'], 'id') === [$xa], 'the case is in "În grija mea"');
$error($sendReturn($iuliana, $xa, ['receiverEmployeeId' => $cutterB['employeeUuid']]), 422, 'RECEIVER_NOT_ALLOWED', 'no other receiver while the responsible cutter is available');
$error($sendReturn($tailor, $xa), 403, 'SUPERVISOR_REQUIRED', 'the detector cannot send the return');
$returned = $ok($sendReturn($iuliana, $xa, ['comment' => 'Vino să verifici produsul']), 'the supervisor sends the return');
$check($returned['status'] === 'awaiting_acknowledgment' && $returned['receiver']['displayName'] === 'Test Cutter A', 'addressed to the cutter of the historical handoff');
$check($decisions($xa) === 0 && $state($a)['stage'] === 'workshop-receiving', 'still no decision and still stage 3 before the physical receipt');
$cutterView = $ok($get($cutterA, "/production-exceptions/{$xa}"), 'cutter sees the return');
$check($cutterView['role'] === 'responsible' && $cutterView['actions']['canAcknowledge'] === true && $cutterView['actions']['statesResponsibility'] === true, 'the responsible cutter records receipt and states a position');
$check(in_array($xa, array_column($ok($dashGet($denisa, '/management/production-exceptions', ['view' => 'waiting']), 'waiting')['items'], 'id'), true), 'managers see the return in preparation');
$error($ack($cutterB, $xa, $a['qr']), 403, 'EXCEPTION_NOT_ASSIGNED', 'another cutter cannot receive it');
$error($ack($cutterA, $xa, $a['qr']), 400, 'INVALID_REQUEST', 'the responsible cutter states accepted or disputed');
$error($ack($cutterA, $xa, $a['qr'], ['responsibility' => 'disputed']), 422, 'COMMENT_REQUIRED', 'a dispute needs an explanation');
$error($ack($cutterA, $xa, $pre['qr'], ['responsibility' => 'accepted']), 422, 'QR_ORDER_MISMATCH', 'only the active label of this order is accepted');
$cursorMgr = $cursor($denisa);
$received = $ok($ack($cutterA, $xa, $a['qr'], ['responsibility' => 'accepted']), 'the cutter scans the product');
$check($received['status'] === 'awaiting_approval' && $received['physicalCustodian']['displayName'] === 'Test Cutter A' && $received['responsibilityPosition'] === 'accepted' && $decisions($xa) === 1, 'physical receipt recorded; now, and only now, one manager decision is open');
$check(in_array('exception.approval_pending', $liveTypes($denisa, $cursorMgr), true), 'the managers are notified');
$check($state($a)['stage'] === 'workshop-receiving' && $state($a)['exception'] === $xa, 'stage 3 until a manager decides');
$error($post($cutterA, $a['path'] . '/claim', ['expectedVersion' => (int) $state($a)['version']]), 409, 'ORDER_BLOCKED_BY_EXCEPTION', 'no rework before the approval');
// Separation of duties: the supervisor is refused even when she also holds the manager role.
$ok($dash($root, 'PUT', "/management/employees/{$uuids['iuliana']}/applications", ['applications' => ['staff', 'dashboard']]), 'temporary Dashboard access');
$ok($dash($root, 'PUT', "/management/employees/{$uuids['iuliana']}/roles", ['roleIds' => [$roleIds['employee'], $roleIds['operations-manager']]]), 'temporary manager role');
$iulianaDash = T::login($kernel, "iuliana.{$suffix}", $password);
$error($decide($iulianaDash, $xa, ['decision' => 'approve', 'attribution' => 'cutter']), 403, 'SELF_DECISION_DENIED', 'the supervisor cannot approve the 3 -> 2 return of her own case');
$ok($dash($root, 'PUT', "/management/employees/{$uuids['iuliana']}/roles", ['roleIds' => [$roleIds['employee']]]), 'manager role removed again');
$ok($dash($root, 'PUT', "/management/employees/{$uuids['iuliana']}/applications", ['applications' => ['staff']]), 'Dashboard access removed again');
$iuliana = T::login($kernel, "iuliana.{$suffix}", $password) + ['employeeUuid' => $uuids['iuliana']];
$error($decide($tailor, $xa, ['decision' => 'approve', 'attribution' => 'cutter']), 403, 'APPLICATION_ACCESS_DENIED', 'a Staff worker cannot decide');
$error($decide($ceo, $xa, ['decision' => 'approve', 'attribution' => 'cutter']), 403, 'UNAUTHORIZED_ACTION', 'the CEO without approval authority cannot decide');
$error($decide($denisa, $xa, ['decision' => 'approve']), 422, 'ATTRIBUTION_REQUIRED', 'the manager states whether cutting responsibility is established');
$managerView = $ok($dashGet($denisa, "/management/production-exceptions/{$xa}"), 'manager detail');
$check($managerView['detectedStage']['id'] === 'side-hem' && $managerView['supervisor'] !== null && $managerView['receiver']['displayName'] === 'Test Cutter A' && $managerView['actions']['requiresAttribution'] === true && $managerView['actions']['canAttributeToCutter'] === true && $managerView['actions']['canDecide'] === true, 'the manager sees detector, supervisor, receiver, stage 7 origin and the attribution choice');
$approveKey = $key('approve-a');
$expectedA = $exVersion($xa);
$approved = $ok($decide($denisa, $xa, ['decision' => 'approve', 'attribution' => 'cutter'], $approveKey), 'Denisa approves the 3 -> 2 return');
$error($decide($hikmet, $xa, ['decision' => 'reject', 'comment' => 'Prea târziu']), 409, 'EXCEPTION_ALREADY_RESOLVED', 'a second manager cannot decide again');
$check($dash($denisa, 'POST', "/management/production-exceptions/{$xa}/decision", ['expectedVersion' => $expectedA, 'decision' => 'approve', 'attribution' => 'cutter'], $approveKey)['body']['status'] === 'approved', 'the approval replays');
$rework = $state($a);
$check($approved['status'] === 'approved' && $approved['reworkCycle'] === 1 && $approved['attribution'] === 'cutter' && $rework['stage'] === 'material-preparation' && $rework['owner'] === $cutterA['employeeUuid'] && $rework['exception'] === null, 'only after the approval: stage 2, owned by the receiving cutter, unblocked');
$check($quality($xa) == [
    ['event_type' => 'cutting_fault', 'employee_uuid' => $cutterA['employeeUuid'], 'rework_cycle' => 1, 'is_repeat' => 0, 'line_count' => 2, 'meters' => '15.000'],
    ['event_type' => 'fault_detected', 'employee_uuid' => $tailor['employeeUuid'], 'rework_cycle' => null, 'is_repeat' => 0, 'line_count' => 2, 'meters' => '15.000'],
], 'detector and responsible cutter have separate facts; the supervisor and the manager have none: ' . json_encode($quality($xa)));
$check((int) $one('SELECT COUNT(*) FROM production_quality_events WHERE employee_uuid IN (?, ?)', [$uuids['iuliana'], $uuids['denisa']]) === 0, 'the supervisor never receives detection credit');
$activity = array_column($rows('SELECT action FROM order_activity_events WHERE order_uuid = ? ORDER BY production_version_after', [$a['uuid']]), 'action');
$check(array_slice($activity, -3) === ['fault_reported', 'defect_custody_taken', 'fault_returned'], 'history appended: report, internal return, approved return: ' . json_encode($activity));
$intervals = $rows('SELECT employee_uuid, stage_id, ended_at IS NULL AS open FROM analytics_ownership_intervals WHERE order_uuid = ? ORDER BY start_version', [$a['uuid']]);
$last = array_slice($intervals, -2);
$check($last[0]['employee_uuid'] === $tailor['employeeUuid'] && $last[0]['stage_id'] === 'side-hem' && (int) $last[0]['open'] === 0 && $last[1]['employee_uuid'] === $cutterA['employeeUuid'] && $last[1]['stage_id'] === 'material-preparation' && (int) $last[1]['open'] === 1, 'ownership intervals: the stage-7 interval closed at custody, rework opened at approval');
// Rework and normal production again, without any approval.
$decisionsBefore = (int) $one('SELECT COUNT(*) FROM production_exception_decisions');
$advance($cutterA, $a);
$claim($tailor, $a);
$check($ok($get($tailor, $a['path']), 'rework arrival')['productionQuality']['reworkCycles'] === 1, 'the rework cycle stays visible');
$advance($tailor, $a);
$check($state($a)['stage'] === 'labeling' && (int) $one('SELECT COUNT(*) FROM production_exception_decisions') === $decisionsBefore, 'normal 2 -> 3 -> 4 needs no approval');

// ==== Scenario 2: supervisor rejection before custody (stage 5, OutletPerdele) =====================================
$b = $order('B', 'outletperdele');
$bring($b, $cutterB, 'material-straightening');
$xb = $ok($report($tailor, $b, [$b['items'][0]], 'cut-color', null), 'stage-5 report', 201)['id'];
$dismissed = $ok($review($iuliana, $xb, ['decision' => 'dismiss', 'comment' => 'Culoarea corespunde comenzii']), 'the supervisor dismisses');
$sb = $state($b);
$check($dismissed['status'] === 'dismissed' && $sb['stage'] === 'material-straightening' && $sb['owner'] === $tailor['employeeUuid'] && $sb['exception'] === null, 'A: stage 5 stays, the worker keeps the order, unblocked');
$check($quality($xb) === [] && $decisions($xb) === 0, 'A: a dismissed report is neither a confirmed defect nor a manager matter');
$check($ok($get($tailor, "/production-exceptions/{$xb}"), 'detector sees dismissal')['supervisorComment'] === 'Culoarea corespunde comenzii', 'A: the worker sees why');
$advance($tailor, $b);
$check($state($b)['stage'] === 'bottom-hem', 'A: normal 5 -> 6 continues');

// ==== Scenario 3: stage 3 detection, dispute, manager rejection, supervisor restores (Scenario B) ==================
$c = $order('C', 'trendhome');
$bring($c, $cutterA, 'workshop-receiving');
$xc = $ok($report($tailor, $c, [$c['items'][0]], 'cut-meterage', null), 'stage-3 report', 201)['id'];
$ok($review($iuliana, $xc, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $c['qr']]), 'accepted at stage 3');
$sc = $state($c);
$check($sc['stage'] === 'workshop-receiving' && $sc['owner'] === null, 'at stage 3 custody moves without a redundant stage change');
$check($rows("SELECT from_stage_id, to_stage_id FROM order_activity_events WHERE order_uuid = ? AND action = 'defect_custody_taken'", [$c['uuid']]) === [['from_stage_id' => 'workshop-receiving', 'to_stage_id' => 'workshop-receiving']], 'custody event at stage 3');
$ok($sendReturn($iuliana, $xc), 'return sent');
$disputed = $ok($ack($cutterA, $xc, $c['qr'], ['responsibility' => 'disputed', 'comment' => 'Am tăiat după fișa de producție']), 'the cutter receives but disputes');
$check($disputed['responsibilityPosition'] === 'disputed' && $disputed['status'] === 'awaiting_approval', 'receipt is recorded separately from fault admission');
$rejected = $ok($decide($hikmet, $xc, ['decision' => 'reject', 'comment' => 'Fișa era corectă']), 'Hikmet rejects the cutting return');
$sc = $state($c);
$check($rejected['status'] === 'return_rejected' && $sc['stage'] === 'workshop-receiving' && $sc['owner'] === null && $sc['exception'] === $xc, 'B: the order stays blocked at stage 3 under the supervisor');
$check($rejected['physicalCustodian']['displayName'] === 'Test Cutter A' && $rejected['decisions'][0]['status'] === 'rejected', 'B: the product is still recorded with the cutter; the rejection is kept');
$check($quality($xc) == [['event_type' => 'fault_detected', 'employee_uuid' => $tailor['employeeUuid'], 'rework_cycle' => null, 'is_repeat' => 0, 'line_count' => 1, 'meters' => '5.000']], 'B: the detection stays credited, no cutting fault');
$error($post($tailor, "/production-exceptions/{$xc}/rereview", ['expectedVersion' => $exVersion($xc), 'comment' => 'Vă rog']), 403, 'SUPERVISOR_REQUIRED', 'B: only the supervisor asks the managers again');
$error($post($tailor2, $c['path'] . '/claim', ['expectedVersion' => (int) $sc['version']]), 409, 'ORDER_BLOCKED_BY_EXCEPTION', 'B: never unblocked while custody and ownership disagree');
$check(in_array($xc, array_column($ok($get($iuliana, '/production-exceptions/supervisor', ['view' => 'custody']), 'custody')['items'], 'id'), true), 'B: back in "În grija mea"');
$error($restore($iuliana, $xc, $c['qr'], ''), 422, 'COMMENT_REQUIRED', 'B: restoring needs an explanation');
$restored = $ok($restore($iuliana, $xc, $c['qr'], 'Produsul a revenit la croitorie, se continuă'), 'B: the supervisor restores with the product in hand');
$sc = $state($c);
$check($restored['status'] === 'restored' && $sc['stage'] === 'workshop-receiving' && $sc['owner'] === $tailor['employeeUuid'] && $sc['exception'] === null, 'B: back at the detected stage with the original owner');
$check($restored['physicalCustodian']['displayName'] === "VASILOAIA IULIANA {$suffix}", 'B: the physical return to tailoring is recorded by the QR scan');
$check($quality($xc) == [['event_type' => 'fault_detected', 'employee_uuid' => $tailor['employeeUuid'], 'rework_cycle' => null, 'is_repeat' => 0, 'line_count' => 1, 'meters' => '5.000']], 'B: KPI unchanged by the restoration');
$advance($tailor, $c);
$check($state($c)['stage'] === 'labeling', 'B: normal production continues');

// ==== Scenario 4: material defect, rejection, supervisor re-review, approval without cutter attribution ==========
$d = $order('D', 'outletperdele');
$bring($d, $cutterB, 'bottom-hem');
$xd = $ok($report($tailor, $d, [$d['items'][2]], 'cut-color', 'Pată pe material'), 'stage-6 report', 201)['id'];
$ok($review($iuliana, $xd, ['decision' => 'accept', 'reasonKey' => 'fabric-defect', 'qrToken' => $d['qr']]), 'the supervisor confirms a fabric defect');
$check($state($d)['stage'] === 'workshop-receiving', '6 -> 3 without a manager');
$ok($sendReturn($iuliana, $xd), 'return');
$ok($ack($cutterB, $xd, $d['qr'], ['responsibility' => 'disputed', 'comment' => 'Materialul a venit pătat']), 'receipt with dispute');
$ok($decide($denisa, $xd, ['decision' => 'reject', 'comment' => 'Verificați din nou stocul']), 'rejected');
$again = $ok($post($iuliana, "/production-exceptions/{$xd}/rereview", ['expectedVersion' => $exVersion($xd), 'comment' => 'Stocul nu are alt rulou, trebuie tăiat din nou']), 'the supervisor asks again');
$check($again['status'] === 'awaiting_approval' && array_column($again['decisions'], 'status') === ['rejected', 'pending'], 'a linked second attempt; the rejection stays');
$check(($ok($dashGet($hikmet, "/management/production-exceptions/{$xd}"), 'detail')['actions']['canAttributeToCutter'] ?? null) === false, 'the manager cannot attribute a fabric defect to the cutter');
$error($decide($hikmet, $xd, ['decision' => 'approve', 'attribution' => 'cutter']), 422, 'ATTRIBUTION_NOT_ALLOWED', 'a material defect is never a cutter mistake');
$approvedD = $ok($decide($hikmet, $xd, ['decision' => 'approve', 'attribution' => 'none']), 'approved as material replacement');
$check($approvedD['status'] === 'approved' && $state($d)['stage'] === 'material-preparation' && $state($d)['owner'] === $cutterB['employeeUuid'], 'the cutter re-cuts the material');
$check(array_column($quality($xd), 'event_type') === ['fault_detected'], 'no cutting fault for a material defect; the detection stays');

// ==== Scenario 5: unavailable cutter, substitute receiver, ownership never forced onto an inactive account =========
$e = $order('E', 'trendhome');
$bring($e, $cutterB, 'ironing');
$xe = $ok($report($tailor, $e, [$e['items'][0]], 'cut-meterage', null), 'stage-8 report', 201)['id'];
$ok($review($iuliana, $xe, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $e['qr']]), 'accepted');
$ok($dash($root, 'POST', "/management/employees/{$uuids['cutterb']}/deactivate"), 'the responsible cutter leaves');
$error($sendReturn($iuliana, $xe), 409, 'RESPONSIBLE_UNAVAILABLE', 'the supervisor must name an available cutter');
$receivers = $ok($get($iuliana, "/production-exceptions/{$xe}/receivers"), 'available cutters')['items'];
$check(in_array($uuids['cuttera'], array_column($receivers, 'id'), true) && !in_array($uuids['cutterb'], array_column($receivers, 'id'), true), 'only eligible cutters are offered');
$error($sendReturn($iuliana, $xe, ['receiverEmployeeId' => $uuids['tailor2']]), 422, 'RECEIVER_INVALID', 'a tailor is not a cutting receiver');
$ok($sendReturn($iuliana, $xe, ['receiverEmployeeId' => $uuids['cuttera']]), 'substitute receiver');
$error($ack($cutterA, $xe, $e['qr'], ['responsibility' => 'accepted']), 400, 'INVALID_REQUEST', 'a substitute never states responsibility for someone else');
$sub = $ok($ack($cutterA, $xe, $e['qr']), 'the substitute receives the product');
$check($sub['responsibilityPosition'] === 'not_stated' && $sub['responsible']['displayName'] === 'Test Cutter B' && $sub['receiver']['displayName'] === 'Test Cutter A', 'receiver and responsible stay separate');
$ok($dash($root, 'POST', "/management/employees/{$uuids['cuttera']}/deactivate"), 'the receiver is deactivated too');
$pendingVersion = $exVersion($xe);
$error($decide($denisa, $xe, ['decision' => 'approve', 'attribution' => 'cutter']), 409, 'RETURN_ASSIGNEE_UNAVAILABLE', 'ownership is never forced onto an inactive account');
$check($exVersion($xe) === $pendingVersion && $state($e)['stage'] === 'workshop-receiving' && (int) $one("SELECT COUNT(*) FROM production_exception_decisions WHERE exception_uuid = ? AND status = 'pending'", [$xe]) === 1, 'the refused approval changed nothing; the decision stays pending');
$ok($dash($root, 'POST', "/management/employees/{$uuids['cuttera']}/activate"), 'receiver reactivated');
$cutterA = T::login($kernel, "cuttera.{$suffix}", $password) + ['employeeUuid' => $uuids['cuttera']];
$ok($decide($denisa, $xe, ['decision' => 'approve', 'attribution' => 'cutter']), 'approved');
$check($state($e)['owner'] === $uuids['cuttera'] && $quality($xe)[0]['event_type'] === 'cutting_fault' && $quality($xe)[0]['employee_uuid'] === $uuids['cutterb'], 'rework goes to the receiver; the fault stays with the historical responsible cutter');
$ok($dash($root, 'POST', "/management/employees/{$uuids['cutterb']}/activate"), 'cutter B reactivated');
$cutterB = T::login($kernel, "cutterb.{$suffix}", $password) + ['employeeUuid' => $uuids['cutterb']];

// ==== Scenario 6: concurrency, every stage 3-12, revoked authority ==============================================
$f = $order('F', 'trendhome');
$bring($f, $cutterA, 'height');
$xf = $ok($report($tailor, $f, [$f['items'][1]], 'cut-meterage', null), 'stage-9 report', 201)['id'];
$versionF = $exVersion($xf);
$startAt = (string) (microtime(true) + 0.4);
$workers = [];
foreach ([['accept', ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $f['qr']]], ['dismiss', ['decision' => 'dismiss', 'comment' => 'Respins în paralel']]] as $index => [$label, $body]) {
    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/b2b-order-worker.php', $dbName, 'POST', "/production-exceptions/{$xf}/review", json_encode(['expectedVersion' => $versionF] + $body, JSON_THROW_ON_ERROR), $key("race-{$index}"), $iuliana['cookie'], $iuliana['csrf'], $startAt], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
}
$statuses = [];
foreach ($workers as [$process, $pipes]) {
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $statuses[] = json_decode((string) $out, true)['status'] ?? 0;
}
sort($statuses);
$check($statuses === [200, 409], 'two conflicting supervisor actions on one version: exactly one wins: ' . json_encode($statuses));
$check((int) $one('SELECT COUNT(*) FROM production_exception_events WHERE exception_uuid = ? AND action IN (?, ?)', [$xf, 'supervisor_accepted', 'supervisor_dismissed']) === 1 && count($quality($xf)) <= 1, 'exactly one outcome was written');
foreach (['labeling', 'material-straightening', 'header-tape', 'sewing-finishing', 'quality-control'] as $n => $stage) {
    $g = $order("G{$n}", $n % 2 === 0 ? 'trendhome' : 'outletperdele');
    $bring($g, $cutterA, $stage);
    $xg = $ok($report($tailor, $g, [$g['items'][0]], 'cut-meterage', null), "report at {$stage}", 201)['id'];
    $ok($review($iuliana, $xg, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $g['qr']]), "accept at {$stage}");
    $check($state($g)['stage'] === 'workshop-receiving' && $decisions($xg) === 0, "{$stage} -> stage 3 without a manager decision");
}
$h = $order('H', 'trendhome');
$bring($h, $cutterA, 'labeling');
$advance($tailor, $h);
$claim($tailor, $h);
$advance($tailor, $h);
$claim($tailor, $h);
$check($state($h)['stage'] === 'bottom-hem', 'ordinary tailoring stages advance without any approval');
$assignment = array_values(array_filter($ok($dashGet($ceo, '/management/organization'), 'organization')['responsibilities'], static fn (array $r): bool => $r['responsibility'] === 'tailoring_quality_supervisor' && $r['state'] === 'active'))[0];
$xh = $ok($report($tailor, $h, [$h['items'][0]], 'cut-meterage', null), 'report before revocation', 201)['id'];
$ok($dash($ceo, 'POST', "/management/organization/responsibilities/{$assignment['id']}/revoke", ['reason' => 'Test revocare']), 'the CEO revokes the appointment');
$error($review($iuliana, $xh, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $h['qr']]), 403, 'SUPERVISOR_REQUIRED', 'a revoked supervisor can no longer act');
$check($ok($get($iuliana, '/production-exceptions/capabilities'), 'caps revoked')['tailoringSupervisor'] === false, 'capabilities follow the revocation');
$ok($appoint($ceo, $uuids['iuliana1']), 'a substitute is appointed explicitly', 201);
$error($review($iuliana, $xh, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $h['qr']]), 403, 'SUPERVISOR_REQUIRED', 'the original identity is not a second supervisor');
$ok($appoint($ceo, $uuids['iuliana']), 'the original identity is appointed again; the substitute ends', 201);
$check((int) $one("SELECT COUNT(*) FROM responsibility_assignments WHERE responsibility_key = 'tailoring_quality_supervisor' AND revoked_at IS NULL AND (ends_at IS NULL OR ends_at > UTC_TIMESTAMP(6))") === 1, 'exactly one identity can supervise at any time');
$error($review($iuliana1, $xh, ['decision' => 'accept', 'reasonKey' => 'cut-meterage', 'qrToken' => $h['qr']]), 403, 'SUPERVISOR_REQUIRED', 'the replaced substitute lost the authority');
$ok($review($iuliana, $xh, ['decision' => 'dismiss', 'comment' => 'Verificat, fără defect']), 'the restored supervisor continues');

// ---- Invariants --------------------------------------------------------------------------------------------------
$check((int) $one("SELECT COUNT(*) FROM production_exception_decisions d JOIN production_exceptions x ON x.exception_uuid = d.exception_uuid WHERE x.exception_type = 'tailoring_defect' AND x.acknowledged_at IS NULL") === 0, 'no manager decision exists for any case before physical receipt at stage 3');
$check((int) $one("SELECT COUNT(*) FROM production_quality_events q JOIN production_exceptions x ON x.exception_uuid = q.exception_uuid WHERE x.status = 'dismissed'") === 0, 'dismissed reports never count');
$check((int) $one("SELECT COUNT(*) FROM order_activity_events WHERE action IN ('defect_custody_taken', 'defect_restored', 'defect_dismissed') AND to_stage_id = 'material-preparation'") === 0, 'no internal action ever reaches stage 2');
$check((int) $one("SELECT COUNT(*) FROM order_activity_events a JOIN production_exceptions x ON x.order_uuid = a.order_uuid WHERE a.action = 'fault_returned' AND x.exception_type = 'tailoring_defect' AND x.status = 'approved' AND a.employee_uuid NOT IN (?, ?)", [$uuids['denisa'], $uuids['hikmet']]) === 0, 'every 3 -> 2 return was committed by a manager decision');
$audit = $pdo->query("SELECT metadata_json FROM iam_audit_events WHERE action LIKE 'production.exception.%' OR action LIKE 'responsibility.%'")->fetchAll(PDO::FETCH_COLUMN);
$check($audit !== [] && preg_match('/password|csrf|cookie|token|hash/i', implode('', $audit)) !== 1, 'audit metadata contains no secrets');
$events = $pdo->query("SELECT metadata_json FROM production_exception_events WHERE metadata_json IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$check(preg_match('/password|csrf|cookie/i', implode('', $events)) !== 1, 'timeline metadata contains no secrets');

fwrite(STDOUT, "OK MySQL tailoring supervision integration ({$checks} checks)\n");
