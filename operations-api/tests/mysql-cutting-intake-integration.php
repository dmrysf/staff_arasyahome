<?php
declare(strict_types=1);

// QR cutting intake (POST /cutting/intake) on a disposable database, end to end through the HTTP kernel:
// one scan by an eligible cutter moves a stage-1 Trendhome or OutletPerdele order to cutting and makes the cutter
// its owner in one transaction, without a waiting-stage grant. Covers the canonical events, cutting facts and
// analytics, idempotent replay and key conflicts, the multiple-job confirmation, parallel scans, every refusal
// (identity, scope, QR, order state, source authority), the ordinary stage-1 operations, cutting completion and
// the later stages. Trendyol approvals are covered in mysql-trendyol-intake-integration.php.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$db = T::requireTestDatabase();
if ($db === null) { echo "SKIP cutting intake integration: test database not configured\n"; exit; }
$config = T::config($db);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
$seeds = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seeds, SORT_STRING);
foreach ($seeds as $seed) (new SqlFileRunner($pdo))->run($seed);
$pdo->exec("DELETE FROM api_rate_limit_buckets WHERE bucket_scope = 'order-lookup'");

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, $label . ' HTTP ' . $r['status'] . ' ' . json_encode($r['body'])); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$rows = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$suffix = bin2hex(random_bytes(4));
$password = 'cutting intake passphrase 2026';
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "intake.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-intake-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Intake root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Intake root passphrase 2026!');
$asRoot = static fn (string $method, string $path, array $body): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'intake-' . bin2hex(random_bytes(8))], $root['cookie']);
$roleIds = [];
foreach ($ok(T::call($kernel, 'GET', '/management/roles', null, [], $root['cookie']), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
$uuids = [];
$identity = static function (string $name, array $stages, array $apps = ['staff'], array $roles = [], string $department = 'pregatire-material') use ($container, $asRoot, $ok, $password, $suffix, $kernel, &$uuids): array {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, $department, 'employee', $password, $stages, 'test-intake');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]), "{$name} applications");
    if ($roles !== []) $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
    return T::login($kernel, "{$name}.{$suffix}", $password);
};
$keys = 0;
$key = static function (string $label) use (&$keys, $suffix): string { $keys++; return sprintf('intake-%s-%s-%04d', $label, $suffix, $keys); };
$intake = static fn (array $who, array $body, ?string $idem = null, $k = null): array => T::call($k ?? $kernel, 'POST', '/cutting/intake', $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'intake-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$post = static fn (array $who, string $path, array $body, ?string $idem = null): array => T::call($kernel, 'POST', $path, $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem ?? 'intake-any-' . bin2hex(random_bytes(10))], $who['cookie']);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $query);

// Four cutters with only the cutting stage (the onboarding matrix gives them nothing else), plus the other roles.
$cutters = [];
foreach ([1, 2, 3, 4] as $n) $cutters[$n] = $identity("cutter{$n}", ['material-preparation']);
$outletCutter = $identity('cutoutlet', ['material-preparation']);
$ok($asRoot('PUT', "/management/employees/{$uuids['cutoutlet']}/stage-scopes", ['stageId' => 'material-preparation', 'sources' => ['outletperdele'], 'expectedSources' => null]), 'outlet-only cutting scope');
$outletCutter = T::login($kernel, "cutoutlet.{$suffix}", $password);
$waiter = $identity('waiter', ['waiting']);
$tailor = $identity('tailor', ['workshop-receiving']);
$noScan = $identity('noscan', ['material-preparation']);
$inactive = $identity('inactive', ['material-preparation']);
$temporaryCutter = $identity('tempcut', ['material-preparation']);
$ceo = $identity('ceo', [], ['dashboard'], [$roleIds['ceo']]);
foreach ($cutters as $n => $cutter) {
    $session = $ok($get($cutter, '/auth/session'), "cutter{$n} session")['employee'];
    $check($session['allowedStageIds'] === ['material-preparation'] && !in_array('trendyol.orders.view', $session['permissions'], true), "cutter{$n} holds no waiting grant and no Trendyol permission");
}

// Orders: stage 1 and cutting, Trendhome and OutletPerdele.
$order = static function (string $tag, string $source, string $stage = 'waiting', string $availability = 'active', $k = null) use ($suffix, $kernel, $ok, $one): array {
    $number = "CI{$tag}{$suffix}";
    $ok(T::ingest($k ?? $kernel, $source, T::sourceOrder($number, "evt-ci-{$tag}-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 300), T::stage($stage), 'processing', $availability)), "ingest {$tag}");
    $id = "{$source}:{$number}";
    return ['id' => $id, 'uuid' => (string) $one('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$id]), 'qr' => 'ARASYA:Q1:' . (string) $one("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? AND q.status = 'active'", [$id])];
};
$state = static fn (array $o): array => $rows('SELECT production_stage_id AS stage, production_owner_employee_uuid AS owner, production_version AS version, production_claimed_at AS claimedAt, cutting_first_claimed_at AS firstClaim, production_completed_at AS completed FROM operational_orders WHERE order_uuid = ?', [$o['uuid']])[0];
$events = static fn (array $o): array => $rows('SELECT action, employee_uuid, from_stage_id, to_stage_id, production_version_before, production_version_after FROM order_activity_events WHERE order_uuid = ? ORDER BY production_version_after, action', [$o['uuid']]);
$facts = static fn (array $o): array => $rows('SELECT fact_type, production_version, employee_uuid FROM cutting_facts WHERE order_uuid = ? ORDER BY production_version, fact_type', [$o['uuid']]);
$qrRows = static fn (array $o): array => $rows('SELECT qr_reference, status FROM order_qr_references WHERE order_uuid = ? ORDER BY qr_reference', [$o['uuid']]);
$w1 = $order('W1', 'trendhome');
$o1 = $order('O1', 'outletperdele');
$w2 = $order('W2', 'trendhome');
$w3 = $order('W3', 'trendhome');
$m1 = $order('M1', 'trendhome', 'material-preparation');
$w6 = $order('W6', 'trendhome');
$check($state($w1)['stage'] === 'waiting' && $state($w1)['owner'] === null && $state($o1)['stage'] === 'waiting', 'Trendhome and OutletPerdele orders start at stage 1 without an owner');
$check($get($cutters[1], '/orders/' . rawurlencode($w1['id']))['status'] === 404 && $post($cutters[1], '/orders/resolve-qr', ['token' => $w1['qr']])['status'] === 404, 'the ordinary routes still hide stage 1 from a cutter');
$overviewStage = static function () use ($get, $ceo, $ok): array { $out = []; foreach ($ok($get($ceo, '/management/production-overview'), 'overview')['stages'] as $s) $out[$s['id']] = $s; return $out; };
$before = $overviewStage();
$poolBefore = $ok($get($cutters[1], '/cutting/pool'), 'pool before')['total'];

// ---- Scenario A: one scan, stage 1 -> cutting, owned by the scanning cutter ------------------------------------
$qrBefore = $qrRows($w1);
$documentsBefore = (int) $one('SELECT COUNT(*) FROM production_document_revisions WHERE order_uuid = ?', [$w1['uuid']]);
$intakeKey = $key('a');
$started = $ok($intake($cutters[1], ['qrToken' => $w1['qr']], $intakeKey), 'Trendhome stage-1 intake');
$after = $state($w1);
$check($started['outcome'] === 'started' && $started['order']['id'] === $w1['id'] && $started['order']['productionStageId'] === 'material-preparation' && ($started['order']['employeeAllowedAction']['id'] ?? null) === 'complete_stage', 'the response is the committed cutting job: ' . json_encode($started));
$check($after['stage'] === 'material-preparation' && $after['owner'] === $uuids['cutter1'] && (int) $after['version'] === 3 && $after['claimedAt'] !== null && $after['firstClaim'] !== null && $after['completed'] === null, 'stage 2, owned by the cutter, cutting started, not completed');
$check($events($w1) === [
    ['action' => 'stage_completed', 'employee_uuid' => $uuids['cutter1'], 'from_stage_id' => 'waiting', 'to_stage_id' => 'material-preparation', 'production_version_before' => 1, 'production_version_after' => 2],
    ['action' => 'claimed', 'employee_uuid' => $uuids['cutter1'], 'from_stage_id' => 'material-preparation', 'to_stage_id' => null, 'production_version_before' => 2, 'production_version_after' => 3],
], 'two canonical events, each with its own production version: ' . json_encode($events($w1)));
$check($facts($w1) === [
    ['fact_type' => 'pool_entered', 'production_version' => 2, 'employee_uuid' => null],
    ['fact_type' => 'first_claim', 'production_version' => 3, 'employee_uuid' => $uuids['cutter1']],
    ['fact_type' => 'interval_started', 'production_version' => 3, 'employee_uuid' => $uuids['cutter1']],
], 'cutting facts: pool entry, first claim and work start: ' . json_encode($facts($w1)));
$check($qrRows($w1) === $qrBefore && (int) $one('SELECT COUNT(*) FROM production_document_revisions WHERE order_uuid = ?', [$w1['uuid']]) === $documentsBefore && (int) $one('SELECT COUNT(*) FROM operational_orders WHERE global_order_id = ?', [$w1['id']]) === 1, 'no new QR, document revision or order');
$audit = $rows("SELECT actor_employee_uuid, target_id, metadata_json, request_id FROM iam_audit_events WHERE action = 'cutting.intake_from_qr' AND target_id = ?", [$w1['id']]);
$auditFacts = json_decode($audit[0]['metadata_json'] ?? '{}', true);
$check(count($audit) === 1 && $audit[0]['actor_employee_uuid'] === $uuids['cutter1'] && $auditFacts['source'] === 'trendhome' && $auditFacts['fromStage'] === 'waiting' && $auditFacts['toStage'] === 'material-preparation' && $auditFacts['productionVersionBefore'] === 1 && $auditFacts['productionVersionAfter'] === 3 && $audit[0]['request_id'] !== '', 'one IAM audit marks the QR intake');
$check(!str_contains(json_encode($audit) . json_encode($rows('SELECT metadata_json FROM auth_audit_events WHERE request_id = ?', [$audit[0]['request_id']])), substr($w1['qr'], 10)), 'the QR value is never written to an audit');
$check((string) $one('SELECT relation_type FROM employee_order_relations WHERE order_uuid = ? AND employee_uuid = ?', [$w1['uuid'], $uuids['cutter1']]) !== '', 'the order is the cutter\'s own work');
$check($ok($get($cutters[1], '/orders/' . rawurlencode($w1['id'])), 'cutter reads own job')['employeeAllowedAction']['id'] === 'complete_stage', 'the cutting workspace opens the job');
$check(in_array($w1['id'], array_column($ok($get($cutters[1], '/orders/mine'), 'mine')['items'], 'id'), true), 'the job is in the cutter\'s own list');
$queue = $ok($get($cutters[1], '/orders/stage-queue', ['stage' => 'material-preparation']), 'cutting queue');
$check(in_array($w1['id'], array_column(array_filter($queue['items'], static fn (array $i): bool => ($i['employeeAllowedAction']['id'] ?? null) === 'complete_stage'), 'id'), true) && $queue['counts']['mine'] >= 1, 'the cutting dashboard counts it as the cutter\'s');
$pool = $ok($get($cutters[2], '/cutting/pool'), 'pool after');
$check(!in_array($w1['id'], array_column($pool['items'], 'id'), true) && $pool['total'] === $poolBefore, 'it never sits in the unassigned cutting pool');
$check($ok($get($cutters[1], '/cutting/pool'), 'own pool')['ownedCount'] === 1, 'the cutter has one unfinished cutting job');
$analytics = $rows('SELECT employee_uuid, stage_id FROM analytics_ownership_intervals WHERE order_uuid = ?', [$w1['uuid']]);
$check(count($analytics) === 1 && $analytics[0]['employee_uuid'] === $uuids['cutter1'] && $analytics[0]['stage_id'] === 'material-preparation', 'analytics open one cutting interval for the cutter: ' . json_encode($analytics));
$mid = $overviewStage();
$check($mid['waiting']['active'] === $before['waiting']['active'] - 1 && $mid['material-preparation']['active'] === $before['material-preparation']['active'] + 1 && $mid['material-preparation']['unassigned'] === $before['material-preparation']['unassigned'], 'management overview: one order left stage 1, entered cutting already assigned');

// ---- Retries, key reuse and repeated scans ----------------------------------------------------------------------
$replayed = $ok($intake($cutters[1], ['qrToken' => $w1['qr']], $intakeKey), 'lost-response retry');
$check(json_encode($replayed) === json_encode($started), 'an identical retry returns the committed result');
$check($state($w1) === $after && count($events($w1)) === 2, 'and changes nothing');
$error($intake($cutters[1], ['qrToken' => $w1['qr'], 'confirmedMultiple' => true, 'ownedCount' => 1], $intakeKey), 409, 'IDEMPOTENCY_CONFLICT', 'a key reused for another intent');
$mine = $ok($intake($cutters[1], ['qrToken' => $w1['qr']], $key('again')), 'second scan by the owner');
$check($mine['outcome'] === 'already_mine' && $state($w1) === $after && count($events($w1)) === 2 && count($facts($w1)) === 3, 'scanning an own job again starts nothing');
$taken = $error($intake($cutters[2], ['qrToken' => $w1['qr']], $key('other')), 409, 'ORDER_ALREADY_CLAIMED', 'another cutter cannot take an owned job');
$check(!isset($taken['order']) && !str_contains(json_encode($taken), 'cutter1') && $state($w1)['owner'] === $uuids['cutter1'], 'the refusal names nobody and transfers nothing');
$error($post($cutters[2], '/orders/' . rawurlencode($m1['id']) . '/claim', ['expectedVersion' => (int) $state($m1)['version'], 'qrToken' => $w3['qr']]), 409, 'QR_ORDER_MISMATCH', 'the ordinary cutting claim still refuses a QR of another order');
$error($intake($cutters[1], ['qrToken' => $w1['qr'], 'orderId' => $o1['id']]), 400, 'INVALID_REQUEST', 'the intake takes no order id, so no other order can be substituted');
$error($intake($cutters[1], ['qrToken' => $w1['qr']], 'short'), 400, 'INVALID_IDEMPOTENCY_KEY', 'an idempotency key is required');
$check(T::call($kernel, 'POST', '/cutting/intake', ['qrToken' => $o1['qr']], ['idempotency-key' => $key('csrf')], $cutters[1]['cookie'])['status'] === 403 && $state($o1)['stage'] === 'waiting', 'CSRF is required');
$check(T::call($kernel, 'POST', '/cutting/intake', ['qrToken' => $o1['qr']], ['idempotency-key' => $key('anon')])['status'] === 401, 'no session, no intake');
$error($get($cutters[1], '/cutting/intake'), 404, 'NOT_FOUND', 'the route answers only POST');

// ---- Scenario C: an unfinished job needs the explicit confirmation, then the same scan continues ---------------
$confirm = $error($intake($cutters[1], ['qrToken' => $o1['qr']], $key('c1')), 409, 'CUTTING_MULTIPLE_CONFIRMATION_REQUIRED', 'OutletPerdele intake with one unfinished job');
$check(($confirm['error']['details']['ownedCount'] ?? null) === 1 && $state($o1)['stage'] === 'waiting' && $state($o1)['owner'] === null && (int) $state($o1)['version'] === 1, 'the warning carries the real count and nothing changed');
$error($intake($cutters[1], ['qrToken' => $o1['qr'], 'confirmedMultiple' => true, 'ownedCount' => 2], $key('c2')), 409, 'CUTTING_MULTIPLE_CONFIRMATION_REQUIRED', 'a confirmation for another count is refused (count re-read under the lock)');
$error($intake($cutters[3], ['qrToken' => $w3['qr'], 'confirmedMultiple' => true, 'ownedCount' => 1], $key('c3')), 409, 'CUTTING_OWNED_COUNT_CHANGED', 'a confirmation from a stale count when nothing is owned any more');
$check($state($w3)['stage'] === 'waiting', 'still nothing changed');
$confirmed = $ok($intake($cutters[1], ['qrToken' => $o1['qr'], 'confirmedMultiple' => true, 'ownedCount' => 1], $key('c4')), 'confirmed OutletPerdele intake (same scanned QR, no second scan)');
$check($confirmed['outcome'] === 'started' && $state($o1)['stage'] === 'material-preparation' && $state($o1)['owner'] === $uuids['cutter1'], 'OutletPerdele: stage 2 and the exact owner');
$check((int) $one("SELECT COUNT(*) FROM iam_audit_events WHERE action = 'cutting.multiple_claim_confirmed' AND actor_employee_uuid = ?", [$uuids['cutter1']]) === 1, 'the explicit multiple-job confirmation is audited');

// ---- Scenario B: two cutters scan the same label at the same time; one cutter double-submits ------------------
$parallel = static function (array $calls) use ($db, $check): array {
    if (!function_exists('pcntl_fork')) return [];
    $files = []; $children = [];
    foreach ($calls as $index => [$who, $body, $idem]) {
        $files[$index] = tempnam(sys_get_temp_dir(), 'intake');
        $pid = pcntl_fork();
        if ($pid === 0) {
            $childKernel = (new Container(T::config($db)))->kernel();
            usleep(20000);
            $response = T::call($childKernel, 'POST', '/cutting/intake', $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem], $who['cookie']);
            file_put_contents($files[$index], json_encode(['status' => $response['status'], 'body' => $response['body']]));
            // End without destructors: the inherited parent connection must not be closed by the child.
            posix_kill(posix_getpid(), SIGKILL);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) pcntl_waitpid($pid, $status);
    $results = [];
    foreach ($files as $index => $file) { $results[$index] = json_decode((string) file_get_contents($file), true); unlink($file); }
    $check(count($results) === count($calls), 'every parallel request answered');
    return $results;
};
$race = $parallel([[$cutters[3], ['qrToken' => $w2['qr']], $key('race3')], [$cutters[4], ['qrToken' => $w2['qr']], $key('race4')]]);
if ($race === []) {
    echo "NOT RUN parallel scans: pcntl is not available\n";
    $ok($intake($cutters[3], ['qrToken' => $w2['qr']]), 'sequential fallback');
    $error($intake($cutters[4], ['qrToken' => $w2['qr']]), 409, 'ORDER_ALREADY_CLAIMED', 'sequential fallback loser');
    $winnerName = 'cutter3';
} else {
    $winners = array_values(array_filter($race, static fn (array $r): bool => $r['status'] === 200));
    $losers = array_values(array_filter($race, static fn (array $r): bool => $r['status'] !== 200));
    $check(count($winners) === 1 && $winners[0]['body']['outcome'] === 'started', 'exactly one parallel scan wins: ' . json_encode($race));
    $check(count($losers) === 1 && $losers[0]['status'] === 409 && in_array($losers[0]['body']['error']['code'] ?? '', ['ORDER_ALREADY_CLAIMED', 'ORDER_CHANGED'], true), 'the other gets a clear refusal: ' . json_encode($losers));
    $winnerName = $race[0]['status'] === 200 ? 'cutter3' : 'cutter4';
}
$check($state($w2)['owner'] === $uuids[$winnerName] && (int) $state($w2)['version'] === 3, 'one owner, one handoff');
$check(array_count_values(array_column($events($w2), 'action')) === ['stage_completed' => 1, 'claimed' => 1] && array_count_values(array_column($facts($w2), 'fact_type')) === ['pool_entered' => 1, 'first_claim' => 1, 'interval_started' => 1], 'no duplicate transition, claim or work start');
$loser = $winnerName === 'cutter3' ? 4 : 3;
$sameKey = $key('double');
$double = $parallel([[$cutters[$loser], ['qrToken' => $w6['qr']], $sameKey], [$cutters[$loser], ['qrToken' => $w6['qr']], $sameKey]]);
if ($double !== []) {
    $check($double[0]['status'] === 200 && $double[1]['status'] === 200 && json_encode($double[0]['body']) === json_encode($double[1]['body']), 'a double-submitted scan answers twice with the one committed result: ' . json_encode($double));
    $check(count($events($w6)) === 2 && $state($w6)['owner'] === $uuids["cutter{$loser}"], 'and mutates once');
} else {
    $ok($intake($cutters[$loser], ['qrToken' => $w6['qr']], $sameKey), 'double-submit fallback');
}

// ---- Scenario D: an unowned order already in cutting is claimed by the same single scan ------------------------
$m1Before = $state($m1);
$claimed = $ok($intake($cutters[2], ['qrToken' => $m1['qr']], $key('d')), 'unowned cutting order');
$check($claimed['outcome'] === 'claimed' && $state($m1)['owner'] === $uuids['cutter2'] && (int) $state($m1)['version'] === (int) $m1Before['version'] + 1 && array_column($events($m1), 'action') === ['claimed'], 'claimed once, no stage change');

// ---- Refusals: identity, scope, QR and order state --------------------------------------------------------------
$denied = $error($intake($waiter, ['qrToken' => $w3['qr']]), 403, 'CUTTING_INTAKE_NOT_ALLOWED', 'a stage-1 employee is not a cutter');
$check(!isset($denied['order']) && !str_contains(json_encode($denied), $w3['id']), 'and learns nothing about the order');
$error($intake($tailor, ['qrToken' => $w3['qr']]), 403, 'CUTTING_INTAKE_NOT_ALLOWED', 'a workshop employee is not a cutter');
$error($intake($root, ['qrToken' => $w3['qr']]), 403, 'CUTTING_INTAKE_NOT_ALLOWED', 'root is never treated as a cutter');
$error($intake($ceo, ['qrToken' => $w3['qr']]), 403, 'APPLICATION_ACCESS_DENIED', 'a Dashboard-only identity has no Staff access');
$pdo->prepare("UPDATE employees SET status = 'inactive' WHERE employee_uuid = ?")->execute([$uuids['inactive']]);
$check(in_array($intake($inactive, ['qrToken' => $w3['qr']])['status'], [401, 403], true) && $state($w3)['stage'] === 'waiting', 'an inactive account changes nothing');
$pdo->prepare('UPDATE employees SET must_change_password = 1, authorization_version = authorization_version + 1 WHERE employee_uuid = ?')->execute([$uuids['tempcut']]);
$check(in_array($intake($temporaryCutter, ['qrToken' => $w3['qr']])['status'], [401, 403], true) && $state($w3)['stage'] === 'waiting', 'a pending password change changes nothing');
$ok($asRoot('PUT', "/management/employees/{$uuids['noscan']}/stages", ['stageIds' => []]), 'cutting stage removed');
$noScan = T::login($kernel, "noscan.{$suffix}", $password);
$error($intake($noScan, ['qrToken' => $w3['qr']]), 403, 'CUTTING_INTAKE_NOT_ALLOWED', 'without the cutting stage');
$error($intake($outletCutter, ['qrToken' => $w3['qr']]), 404, 'ORDER_NOT_FOUND', 'a cutter scoped to OutletPerdele does not reach a Trendhome order');
$check($state($w3)['stage'] === 'waiting' && $state($w3)['owner'] === null, 'no refusal changed the order');
foreach (['not-a-code', '8680003012345', '7330009998887776', 'https://trendhome.ro/wp-admin/post.php?post=123&action=edit', 'arasya:61833'] as $bad) {
    $error($intake($cutters[3], ['qrToken' => $bad]), 400, 'INVALID_QR', "not an Arasya production QR: {$bad}");
}
$error($intake($cutters[3], ['qrToken' => 'ARASYA:Q1:' . str_repeat('A', 26)]), 404, 'UNKNOWN_QR', 'an unknown Arasya QR');
$error($intake($cutters[3], []), 400, 'INVALID_REQUEST', 'the QR is required');
$error($intake($cutters[3], ['qrToken' => $w3['qr'], 'confirmedMultiple' => 'yes']), 400, 'INVALID_REQUEST', 'strict input types');
// A retired QR (rotation) never resolves, and the active revision number is shown only to who may receive the order.
$pdo->prepare("UPDATE order_qr_references SET status = 'revoked', revoked_at = UTC_TIMESTAMP(6) WHERE order_uuid = ?")->execute([$w3['uuid']]);
$check(in_array(($intake($cutters[3], ['qrToken' => $w3['qr']])['body']['error']['code'] ?? ''), ['EXPIRED_QR', 'QR_REVOKED', 'QR_SUPERSEDED'], true) && $state($w3)['stage'] === 'waiting', 'a revoked QR is refused');
$pdo->prepare("UPDATE order_qr_references SET status = 'active', revoked_at = NULL WHERE order_uuid = ?")->execute([$w3['uuid']]);
// Unavailable orders and sources.
$gone = $order('U1', 'trendhome', 'waiting', 'cancelled');
$error($intake($cutters[3], ['qrToken' => $gone['qr']]), 409, 'ORDER_UNAVAILABLE', 'an order the source cancelled');
$check($state($gone)['stage'] === 'waiting', 'stays at stage 1');
// Later stages keep the ordinary scan.
$later = $order('L1', 'trendhome', 'workshop-receiving');
$error($intake($cutters[3], ['qrToken' => $later['qr']]), 409, 'CUTTING_INTAKE_NOT_APPLICABLE', 'a stage-3 order is not a cutting intake');
$check($ok($post($tailor, '/orders/resolve-qr', ['token' => $later['qr']]), 'tailor resolves stage 3')['employeeAllowedAction']['id'] === 'claim', 'the ordinary stage-3 scan is unchanged');

// Source-managed orders under an enforced production-authority boundary stay with the source.
$enforcing = (new Container(T::config($db, [T::ORIGIN], ['trendhome' => ['mode' => 'active', 'authority' => 'enforce'], 'outletperdele' => ['mode' => 'active']])))->kernel();
$legacyManaged = $order('S1', 'trendhome');
$check((string) $one('SELECT production_authority FROM operational_orders WHERE order_uuid = ?', [$legacyManaged['uuid']]) === 'source', 'a legacy-mode order is source-managed');
$error($intake($cutters[3], ['qrToken' => $legacyManaged['qr']], null, $enforcing), 409, 'PRODUCTION_AUTHORITY_SOURCE', 'enforcement refuses the intake of a source-managed order');
$check($state($legacyManaged)['stage'] === 'waiting' && $state($legacyManaged)['owner'] === null, 'no authority change, no handoff');

// ---- Existing stage-1 operations are unchanged -------------------------------------------------------------------
$w7 = $order('W7', 'trendhome');
$ok($post($waiter, '/orders/' . rawurlencode($w7['id']) . '/claim', ['expectedVersion' => 1]), 'stage-1 claim by a waiting-stage employee');
$error($intake($cutters[3], ['qrToken' => $w7['qr']]), 409, 'ORDER_ALREADY_CLAIMED', 'a stage-1 order claimed by someone else is not received');
$ok($post($waiter, '/orders/' . rawurlencode($w7['id']) . '/transition', ['expectedVersion' => 2]), 'stage-1 completion by the waiting-stage employee');
$check($state($w7)['stage'] === 'material-preparation' && $state($w7)['owner'] === null, 'the existing handover still leaves the order unassigned in the cutting pool');

// ---- Scanning starts cutting; completing it still moves exactly one stage, later stages unchanged --------------
$check($state($w1)['stage'] === 'material-preparation', 'scanning never completed cutting');
$done = $ok($post($cutters[1], '/orders/' . rawurlencode($w1['id']) . '/transition', ['expectedVersion' => 3]), 'cutting completion');
$check($done['productionStageId'] === 'workshop-receiving' && $state($w1)['owner'] === null && (int) $state($w1)['version'] === 4, 'completion moves to stage 3 and frees the order');
$completedFact = $rows("SELECT employee_uuid, active_seconds_snapshot FROM cutting_facts WHERE order_uuid = ? AND fact_type = 'completed'", [$w1['uuid']]);
$check(count($completedFact) === 1 && $completedFact[0]['employee_uuid'] === $uuids['cutter1'] && $completedFact[0]['active_seconds_snapshot'] !== null, 'the cutting completion fact is measured from the intake');
$ok($post($tailor, '/orders/' . rawurlencode($w1['id']) . '/claim', ['expectedVersion' => 4]), 'stage-3 claim');
$ok($post($tailor, '/orders/' . rawurlencode($w1['id']) . '/transition', ['expectedVersion' => 5]), 'stage-3 completion');
$check($state($w1)['stage'] === 'labeling', 'stage 3 hands over to stage 4 as before');
$error($intake($cutters[1], ['qrToken' => $w1['qr']]), 409, 'CUTTING_INTAKE_NOT_APPLICABLE', 'a finished cutting job is never taken again');

echo "PASS {$checks} cutting intake checks\n";
