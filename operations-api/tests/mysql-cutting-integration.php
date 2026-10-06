<?php
declare(strict_types=1);
use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
$db = T::requireTestDatabase();
if ($db === null) { echo "SKIP cutting integration: test database not configured\n"; exit; }
$config = T::config($db);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $file) (new SqlFileRunner($pdo))->run($file);
// Independent disposable fixtures can run twice in the full suite. Do not inherit a previous
// fixture's anonymous pairing quota; the production limiter itself remains unchanged.
$pdo->exec("DELETE FROM api_rate_limit_buckets WHERE bucket_scope='cutting-pair'");
$checks = 0;
$check = static function(bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function(array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, $label . ' HTTP ' . $r['status'] . ' ' . ($r['body']['error']['code'] ?? '')); return $r['body'] ?? []; };
$error = static function(array $r, int $status, string $code) use ($check): void { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, $code . ' ' . json_encode($r['body'])); };
$container = new Container($config, $pdo); $kernel = $container->kernel();
$suffix = bin2hex(random_bytes(5)); $password = 'cutting test passphrase 2026';
$pdo->exec('DELETE FROM system_root_identity');
$rootName = 'cutting.root.' . $suffix;
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('cutting-test', $rootName);
$root = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => $password], ['x-csrf-token' => $root['csrf']], $root['cookie']), 'root password');
$root = T::login($kernel, $rootName, $password);
$post = static fn(array $who, string $path, array $body, ?string $key = null): array => T::call($kernel, 'POST', $path, $body, ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? ('cut-test-' . bin2hex(random_bytes(12)))], $who['cookie']);
$get = static fn(array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, [], $who['cookie'], $query);
$create = static function(string $name, array $stages, array $apps, string $role = 'employee') use ($container, $post, $root, $password, $suffix, $pdo, $kernel, $ok): array {
    $e = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'cutting-test');
    $ok(T::call($kernel, 'PUT', "/management/employees/{$e->employeeUuid}/applications", ['applications' => $apps], ['x-csrf-token' => $root['csrf']], $root['cookie']), 'application access');
    if ($role !== 'employee') {
        $s = $pdo->prepare('SELECT role_id FROM roles WHERE role_key = ?'); $s->execute([$role]);
        $ok(T::call($kernel, 'PUT', "/management/employees/{$e->employeeUuid}/roles", ['roleIds' => [(int) $s->fetchColumn()]], ['x-csrf-token' => $root['csrf']], $root['cookie']), 'narrow role');
    }
    return T::login($kernel, "{$name}.{$suffix}", $password);
};
$a = $create('cut-a', ['material-preparation'], ['staff']);
$b = $create('cut-b', ['material-preparation'], ['staff']);
$tailor = $create('cut-tailor', ['workshop-receiving'], ['staff']);
$manager = $create('cut-denisa', [], ['dashboard'], 'operations-manager');
$manager2 = $create('cut-hikmet', [], ['dashboard'], 'operations-manager');
$ceo = $create('cut-mesut', [], ['dashboard'], 'ceo');
$order = static function(string $label, string $stage = 'material-preparation') use ($suffix, $kernel, $pdo, $ok): array {
    $sourceId = "cut-{$label}-{$suffix}"; $id = 'trendhome:' . $sourceId;
    $ok(T::ingest($kernel, 'trendhome', T::sourceOrder($sourceId, 'evt-' . $sourceId, gmdate('Y-m-d\TH:i:s\Z', time() - 200), T::stage($stage))), 'source ingest');
    $s = $pdo->prepare('SELECT o.order_uuid, o.production_version, q.qr_reference FROM operational_orders o JOIN order_qr_references q ON q.order_uuid = o.order_uuid WHERE o.global_order_id = ? AND q.status = \'active\''); $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC);
    return ['id' => $id, 'uuid' => $r['order_uuid'], 'qr' => 'ARASYA:Q1:' . $r['qr_reference'], 'version' => (int) $r['production_version'], 'sourceId' => $sourceId];
};
$o = $order('one'); $other = $order('two');
$pool = $ok($get($a, '/cutting/pool'), 'pool');
$check(in_array($o['id'], array_column($pool['items'], 'id'), true), 'stage-2 order enters pool');
$error($get($tailor, '/cutting/pool'), 403, 'UNAUTHORIZED_ACTION');
$error($post($a, '/orders/' . $o['id'] . '/claim', ['expectedVersion' => 1]), 422, 'CUTTING_QR_REQUIRED');
$error($post($a, '/orders/' . $o['id'] . '/claim', ['expectedVersion' => 1, 'qrToken' => $other['qr']]), 409, 'QR_ORDER_MISMATCH');
$intent = ['expectedVersion' => 1, 'qrToken' => $o['qr']]; $claimKey = 'cutting-claim-' . $suffix;
$claimed = $ok($post($a, '/orders/' . $o['id'] . '/claim', $intent, $claimKey), 'QR claim');
$check(json_encode($claimed) === json_encode($ok($post($a, '/orders/' . $o['id'] . '/claim', $intent, $claimKey), 'claim retry')), 'lost-response replay exact');
$error($post($b, '/orders/' . $o['id'] . '/claim', ['expectedVersion' => 1, 'qrToken' => $o['qr']]), 409, 'ORDER_CHANGED');
$error($post($a, '/orders/' . $other['id'] . '/claim', ['expectedVersion' => 1, 'qrToken' => $other['qr']]), 409, 'CUTTING_MULTIPLE_CONFIRMATION_REQUIRED');
$ok($post($a, '/orders/' . $other['id'] . '/claim', ['expectedVersion' => 1, 'qrToken' => $other['qr'], 'confirmedMultiple' => true, 'ownedCount' => 1]), 'explicit multiple claim');
$error($post($a, '/cutting/orders/' . $o['id'] . '/transfers', ['expectedVersion' => 2, 'targetId' => $a['employeeUuid'], 'reasonKey' => 'illness']), 422, 'INELIGIBLE_TRANSFER_TARGET');
$error($post($a, '/cutting/orders/' . $o['id'] . '/transfers', ['expectedVersion' => 2, 'targetId' => $b['employeeUuid'], 'reasonKey' => 'other']), 422, 'COMMENT_REQUIRED');
$transfer = $ok($post($a, '/cutting/orders/' . $o['id'] . '/transfers', ['expectedVersion' => 2, 'targetId' => $b['employeeUuid'], 'reasonKey' => 'illness']), 'request transfer', 201);
$id = $transfer['id'];
$owner = static function() use ($pdo, $o): mixed { $s = $pdo->prepare('SELECT production_owner_employee_uuid FROM operational_orders WHERE order_uuid = ?'); $s->execute([$o['uuid']]); return $s->fetchColumn(); };
$check($owner() === $a['employeeUuid'], 'request leaves owner');
$error($post($a, '/orders/' . $o['id'] . '/transition', ['expectedVersion' => 3]), 409, 'CUTTING_TRANSFER_PENDING');
$error($post($manager, '/management/cutting/transfers/' . $id . '/decision', ['expectedVersion' => 1, 'decision' => 'reject']), 422, 'COMMENT_REQUIRED');
$approved = $ok($post($manager, '/management/cutting/transfers/' . $id . '/decision', ['expectedVersion' => 1, 'decision' => 'approve']), 'manager approves');
$check($approved['status'] === 'approved' && $owner() === $a['employeeUuid'], 'approval does not transfer owner');
$error($post($manager2, '/management/cutting/transfers/' . $id . '/decision', ['expectedVersion' => 1, 'decision' => 'approve']), 409, 'TRANSFER_CHANGED');
$error($post($a, '/cutting/transfers/' . $id . '/accept', ['expectedVersion' => 2, 'confirmed' => true]), 403, 'UNAUTHORIZED_ACTION');
$error($post($b, '/cutting/transfers/' . $id . '/verify', ['expectedVersion' => 2, 'qrToken' => $o['qr']]), 409, 'TRANSFER_STATE_INVALID');
$ok($post($b, '/cutting/transfers/' . $id . '/accept', ['expectedVersion' => 2, 'confirmed' => true]), 'target accepts');
$check($owner() === $a['employeeUuid'], 'acceptance leaves owner');
$error($post($b, '/cutting/transfers/' . $id . '/verify', ['expectedVersion' => 3, 'qrToken' => $other['qr']]), 409, 'QR_ORDER_MISMATCH');
$verifyKey = 'cutting-verify-' . $suffix;
$verified = $ok($post($b, '/cutting/transfers/' . $id . '/verify', ['expectedVersion' => 3, 'qrToken' => $o['qr']], $verifyKey), 'target verifies same QR');
$check($verified['status'] === 'completed' && $owner() === $b['employeeUuid'], 'QR atomic sole owner transfer');
$ok($post($b, '/cutting/transfers/' . $id . '/verify', ['expectedVersion' => 3, 'qrToken' => $o['qr']], $verifyKey), 'QR lost-response replay');
$error($post($a, '/orders/' . $o['id'] . '/transition', ['expectedVersion' => 6]), 409, 'ORDER_ALREADY_CLAIMED');
$ok($post($b, '/orders/' . $o['id'] . '/transition', ['expectedVersion' => 6]), 'final cutter completes 2 to 3');
$s = $pdo->prepare("SELECT employee_uuid, meters_snapshot FROM order_activity_events WHERE order_uuid = ? AND action = 'stage_completed' AND from_stage_id = 'material-preparation' ORDER BY production_version_after DESC LIMIT 1"); $s->execute([$o['uuid']]); $completion = $s->fetch(PDO::FETCH_ASSOC);
$check($completion['employee_uuid'] === $b['employeeUuid'] && $completion['meters_snapshot'] === '8.400', 'whole-order KPI to final cutter');
$error($get($manager, '/management/employees'), 403, 'UNAUTHORIZED_ACTION');
$error($get($manager, '/management/cutting/devices'), 403, 'ROOT_REQUIRED');
$error($get($ceo, '/management/cutting/devices'), 403, 'ROOT_REQUIRED');
$deviceKey = 'cutting-device-' . $suffix;
$device = $ok($post($root, '/management/cutting/devices', ['name' => 'Zona de tăiere perdele'], $deviceKey), 'Root creates device', 201);
$replayed = $ok($post($root, '/management/cutting/devices', ['name' => 'Zona de tăiere perdele'], $deviceKey), 'device create retry', 201);
$check($replayed['id'] === $device['id'] && !isset($replayed['pairingCode']), 'pair plaintext never persisted for replay');
$pair = $ok(T::call($kernel, 'POST', '/display/cutting/pair', ['code' => $device['pairingCode']]), 'one-time pairing');
$error(T::call($kernel, 'POST', '/display/cutting/pair', ['code' => $device['pairingCode']]), 422, 'PAIRING_INVALID');
$s = $pdo->prepare("SELECT COUNT(*) FROM production_exception_idempotency WHERE response_json LIKE ?"); $s->execute(['%' . $device['pairingCode'] . '%']); $check((int) $s->fetchColumn() === 0, 'pairing code absent from persistence');
// Display uses a different cookie, never the employee-session helper.
$repair = $ok($post($root, '/management/cutting/devices/' . $device['id'] . '/re-pair', ['confirmed' => true]), 'fresh Root re-pair');
$pairResponse = T::call($kernel, 'POST', '/display/cutting/pair', ['code' => $repair['pairingCode']]); $ok($pairResponse, 'pair replacement');
preg_match('/^arasya_cutting_display=([^;]+);/', $pairResponse['headers']['Set-Cookie'] ?? '', $cookieMatch);
$check(isset($cookieMatch[1]) && str_contains($pairResponse['headers']['Set-Cookie'], 'HttpOnly'), 'scoped HttpOnly cookie');
$display = static function(string $path, array $query = []) use ($kernel, $cookieMatch): array { $r = $kernel->handle(new Request('GET', $path, ['origin' => T::ORIGIN], ['arasya_cutting_display' => $cookieMatch[1]], '', '127.0.0.1', 'cutting-test', 'cutting-display-test', $query)); return ['status' => $r->status, 'body' => $r->payload, 'raw' => $r->body]; };
$snapshot = $ok($display('/display/cutting/snapshot'), 'sanitized snapshot');
$check($snapshot['title'] === 'Zona de tăiere perdele' && !str_contains(json_encode($snapshot), 'customer') && !str_contains(json_encode($snapshot), 'employeeUuid'), 'no customer or IAM identity in board');
$error($display('/auth/session'), 401, 'NO_SESSION');
$stream = $display('/live/events', ['scope' => 'cutting-display']); $check($stream['status'] === 200 && str_contains($stream['raw'], 'event: ready') && !str_contains($stream['raw'], 'transferId'), 'no historical replay or transfer details to display');
$liveCursor = (new Arasya\Operations\Quality\LiveEvents($pdo))->latestSequence();
(new Arasya\Operations\Quality\LiveEvents($pdo))->cuttingChanged(gmdate('Y-m-d H:i:s'));
$invalidation = $display('/live/events', ['scope' => 'cutting-display', 'after' => (string) $liveCursor]);
$check(str_contains($invalidation['raw'], "event: cutting.changed\ndata: {}\n"), 'empty sanitized invalidation is a JSON object accepted by the shared live client');
$ok($post($root, '/management/cutting/devices/' . $device['id'] . '/revoke', ['confirmed' => true]), 'Root revokes device');
$error($display('/display/cutting/snapshot'), 401, 'DISPLAY_SESSION_INVALID');
$cancel = $order('cancel');
$ok(T::ingest($kernel, 'trendhome', T::sourceOrder($cancel['sourceId'], 'cancel-' . $suffix, gmdate('Y-m-d\TH:i:s\Z'), null, 'cancelled', 'cancelled')), 'source cancellation before cutting');
$check(!in_array($cancel['id'], array_column($ok($get($b, '/cutting/pool'), 'fresh pool')['items'], 'id'), true), 'cancelled unclaimed order absent');
$ok(T::ingest($kernel, 'trendhome', T::sourceOrder($other['sourceId'], 'cancel-started-' . $suffix, gmdate('Y-m-d\TH:i:s\Z'), null, 'cancelled', 'cancelled')), 'source cancellation after start');
$s = $pdo->prepare('SELECT production_owner_employee_uuid, operational_status, production_stage_id FROM operational_orders WHERE order_uuid = ?'); $s->execute([$other['uuid']]); $preserved = $s->fetch(PDO::FETCH_ASSOC);
$check($preserved['production_owner_employee_uuid'] === $a['employeeUuid'] && $preserved['operational_status'] !== 'unavailable' && $preserved['production_stage_id'] === 'material-preparation', 'started cancellation retains canonical owner and production');

// Real processes / independent connections: same-order claim, different-order actor count,
// decision, acceptance, verification and one-time pairing races.
$race = static function(array $jobs) use ($db): array {
    $running = []; $start = microtime(true) + 1;
    foreach ($jobs as $job) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/cutting-worker.php'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['file','/dev/null','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start race worker.');
        fwrite($pipes[0], json_encode($job + ['db' => $db, 'start' => $start], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        $running[] = [$process, $pipes[1]];
    }
    $results = [];
    foreach ($running as [$process, $pipe]) {
        $raw = stream_get_contents($pipe); fclose($pipe);
        if (proc_close($process) !== 0) throw new RuntimeException('Race worker failed (no sensitive request logged).');
        $results[] = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }
    return $results;
};
$job = static fn(array $who, string $path, array $body, ?string $key = null): array => ['path' => $path, 'body' => $body, 'cookie' => $who['cookie'], 'headers' => ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? 'race-cut-' . bin2hex(random_bytes(12))]];
$statuses = static function(array $results): array { $values = array_column($results, 'status'); sort($values); return $values; };
$r1 = $create('race-a', ['material-preparation'], ['staff']); $r2 = $create('race-b', ['material-preparation'], ['staff']);
$rOrder = $order('claim-race');
$results = $race([$job($r1, '/orders/' . $rOrder['id'] . '/claim', ['expectedVersion'=>1,'qrToken'=>$rOrder['qr']]), $job($r2, '/orders/' . $rOrder['id'] . '/claim', ['expectedVersion'=>1,'qrToken'=>$rOrder['qr']])]);
$check($statuses($results) === [200,409], 'simultaneous QR claim has exactly one winner');
$s = $pdo->prepare("SELECT COUNT(*) FROM cutting_facts WHERE order_uuid = ? AND fact_type = 'first_claim'"); $s->execute([$rOrder['uuid']]); $check((int)$s->fetchColumn() === 1, 'race records exactly one first claim');
$samePerson = $create('race-count', ['material-preparation'], ['staff']); $c1 = $order('count-one'); $c2 = $order('count-two');
$results = $race([$job($samePerson, '/orders/' . $c1['id'] . '/claim', ['expectedVersion'=>1,'qrToken'=>$c1['qr'],'ownedCount'=>0]), $job($samePerson, '/orders/' . $c2['id'] . '/claim', ['expectedVersion'=>1,'qrToken'=>$c2['qr'],'ownedCount'=>0])]);
$check($statuses($results) === [200,409] && in_array('CUTTING_MULTIPLE_CONFIRMATION_REQUIRED', array_column($results,'code'), true), 'same employee parallel different-order claims recheck current count');
$loser = $results[0]['status'] === 200 ? $c2 : $c1;
$ok($post($samePerson, '/orders/' . $loser['id'] . '/claim', ['expectedVersion'=>1,'qrToken'=>$loser['qr'],'ownedCount'=>1,'confirmedMultiple'=>true]), 'count re-confirmed after race');
$s = $pdo->prepare("SELECT COUNT(*) FROM iam_audit_events WHERE actor_employee_uuid = ? AND action = 'cutting.multiple_claim_confirmed'"); $s->execute([$samePerson['employeeUuid']]); $check((int)$s->fetchColumn() === 1, 'multiple consent is durably audited');
for ($i = 3; $i <= 6; $i++) { $extra = $order('no-cap-' . $i); $ok($post($samePerson, '/orders/' . $extra['id'] . '/claim', ['expectedVersion'=>1,'qrToken'=>$extra['qr'],'ownedCount'=>$i-1,'confirmedMultiple'=>true]), 'no arbitrary active-work maximum'); }
$check($ok($get($samePerson, '/cutting/pool'), 'server count')['ownedCount'] === 6, 'all six whole orders owned');
$ok(T::call($kernel, 'POST', '/auth/logout', ['confirmed'=>true], ['x-csrf-token'=>$samePerson['csrf']], $samePerson['cookie']), 'logout');
$samePerson = T::login($kernel, 'race-count.' . $suffix, $password);
$check($ok($get($samePerson, '/cutting/pool'), 'restored count')['ownedCount'] === 6, 'ownership survives logout/login');

$flow = $order('transfer-race'); $ok($post($r2, '/orders/' . $flow['id'] . '/claim', T::cuttingClaim($pdo, $flow['id'], $r2['employeeUuid'],1)), 'race transfer initial claim');
$target = $create('race-target', ['material-preparation'], ['staff']);
$requested = $ok($post($r2, '/cutting/orders/' . $flow['id'] . '/transfers', ['expectedVersion'=>2,'targetId'=>$target['employeeUuid'],'reasonKey'=>'unavailable']), 'race request',201);
$tid = $requested['id'];
$error($post($target, '/cutting/orders/' . $flow['id'] . '/transfers', ['expectedVersion'=>3,'targetId'=>$r1['employeeUuid'],'reasonKey'=>'illness']),409,'ORDER_CHANGED');
$results = $race([$job($manager, '/management/cutting/transfers/' . $tid . '/decision', ['expectedVersion'=>1,'decision'=>'approve']), $job($manager2, '/management/cutting/transfers/' . $tid . '/decision', ['expectedVersion'=>1,'decision'=>'approve'])]);
$check($statuses($results) === [200,409], 'two managers one committed decision');
$acceptKey = 'race-accept-' . $suffix;
$results = $race([$job($target, '/cutting/transfers/' . $tid . '/accept', ['expectedVersion'=>2,'confirmed'=>true],$acceptKey), $job($target, '/cutting/transfers/' . $tid . '/accept', ['expectedVersion'=>2,'confirmed'=>true],$acceptKey)]);
$check($statuses($results) === [200,200] && array_unique(array_column($results,'version')) === [3], 'double accept same key replays one version');
$error($post($target, '/cutting/transfers/' . $tid . '/verify', ['expectedVersion'=>3]),422,'CUTTING_QR_REQUIRED');
$raceVerify = 'race-verify-' . $suffix;
$results = $race([$job($target, '/cutting/transfers/' . $tid . '/verify', ['expectedVersion'=>3,'qrToken'=>$flow['qr']],$raceVerify), $job($target, '/cutting/transfers/' . $tid . '/verify', ['expectedVersion'=>3,'qrToken'=>$flow['qr']],$raceVerify)]);
$check($statuses($results) === [200,200], 'duplicate transfer QR commits once and replays');
$s = $pdo->prepare("SELECT COUNT(*) FROM order_activity_events WHERE order_uuid=? AND action='owner_reassigned'"); $s->execute([$flow['uuid']]); $check((int)$s->fetchColumn() === 1, 'one ownership activity, no duplicate KPI');
$error($post($target, '/cutting/transfers/' . $tid . '/verify', ['expectedVersion'=>3,'qrToken'=>$other['qr']],$raceVerify),409,'IDEMPOTENCY_CONFLICT');
$error(T::call($kernel,'PUT','/management/orders/' . rawurlencode($flow['id']) . '/owner', ['expectedVersion'=>6,'employeeId'=>$r1['employeeUuid']], ['x-csrf-token'=>$manager['csrf'],'idempotency-key'=>'manager-reassign-'.$suffix],$manager['cookie']),403,'UNAUTHORIZED_ACTION');

// Rejection and Root recovery preserve both canonical owner and immutable decision history.
$rejectOrder = $order('reject'); $ok($post($a, '/orders/' . $rejectOrder['id'] . '/claim', T::cuttingClaim($pdo,$rejectOrder['id'],$a['employeeUuid'],1)), 'rejection setup');
$tr = $ok($post($a, '/cutting/orders/' . $rejectOrder['id'] . '/transfers', ['expectedVersion'=>2,'targetId'=>$b['employeeUuid'],'reasonKey'=>'other','comment'=>'Consultație medicală']), 'commented request',201);
$rejected = $ok($post($manager, '/management/cutting/transfers/' . $tr['id'] . '/decision', ['expectedVersion'=>1,'decision'=>'reject','comment'=>'Colegul nu poate prelua acum']), 'reject');
$check($rejected['status']==='rejected' && $rejected['decisionComment']==='Colegul nu poate prelua acum', 'rejection snapshot retained');
$tr = $ok($post($a, '/cutting/orders/' . $rejectOrder['id'] . '/transfers', ['expectedVersion'=>4,'targetId'=>$b['employeeUuid'],'reasonKey'=>'illness']), 'second request after rejection',201);
$error($post($manager, '/management/cutting/transfers/' . $tr['id'] . '/cancel', ['expectedVersion'=>1,'comment'=>'Recovery attempt']),403,'ROOT_REQUIRED');
$error($post($root, '/management/cutting/transfers/' . $tr['id'] . '/cancel', ['expectedVersion'=>1]),422,'COMMENT_REQUIRED');
$recovered = $ok($post($root, '/management/cutting/transfers/' . $tr['id'] . '/cancel', ['expectedVersion'=>1,'comment'=>'Cerere blocată · recuperare tehnică']), 'Root safe recovery');
$check($recovered['status']==='cancelled' && count($recovered['history'])===2 && str_ends_with($recovered['history'][1]['at'],'Z'), 'Root cancellation appends reason with UTC history');
$s = $pdo->prepare('SELECT production_owner_employee_uuid FROM operational_orders WHERE order_uuid=?'); $s->execute([$rejectOrder['uuid']]); $check($s->fetchColumn()===$a['employeeUuid'],'rejection and recovery never change owner');

// Target eligibility is revalidated after approval, not a snapshot of request-time access.
$changing = $create('changing-target',['material-preparation'],['staff']); $changeOrder = $order('changing');
$ok($post($a, '/orders/' . $changeOrder['id'] . '/claim', T::cuttingClaim($pdo,$changeOrder['id'],$a['employeeUuid'],1)), 'eligibility setup');
$tr = $ok($post($a, '/cutting/orders/' . $changeOrder['id'] . '/transfers', ['expectedVersion'=>2,'targetId'=>$changing['employeeUuid'],'reasonKey'=>'illness']), 'eligibility request',201);
$ok($post($manager, '/management/cutting/transfers/' . $tr['id'] . '/decision', ['expectedVersion'=>1,'decision'=>'approve']), 'eligibility approved');
$ok(T::call($kernel,'PUT', '/management/employees/' . $changing['employeeUuid'] . '/stages',['stageIds'=>['workshop-receiving']],['x-csrf-token'=>$root['csrf']],$root['cookie']), 'Root removes cutting eligibility');
$changing = T::login($kernel,'changing-target.' . $suffix,$password);
$error($post($changing,'/cutting/transfers/'.$tr['id'].'/accept',['expectedVersion'=>2,'confirmed'=>true]),422,'INELIGIBLE_TRANSFER_TARGET');
$ok(T::call($kernel,'PUT','/management/employees/'.$changing['employeeUuid'].'/stages',['stageIds'=>['material-preparation']],['x-csrf-token'=>$root['csrf']],$root['cookie']),'restore eligibility');
$ok(T::call($kernel,'PUT','/management/employees/'.$changing['employeeUuid'].'/applications',['applications'=>['dashboard']],['x-csrf-token'=>$root['csrf']],$root['cookie']),'remove Staff');
$changing = T::login($kernel,'changing-target.'.$suffix,$password);
$error($post($changing,'/cutting/transfers/'.$tr['id'].'/accept',['expectedVersion'=>2,'confirmed'=>true]),403,'APPLICATION_ACCESS_DENIED');
$ok(T::call($kernel,'POST','/management/employees/'.$changing['employeeUuid'].'/deactivate',null,['x-csrf-token'=>$root['csrf']],$root['cookie']),'inactive target');
$error($post($changing,'/cutting/transfers/'.$tr['id'].'/accept',['expectedVersion'=>2,'confirmed'=>true]),401,'SESSION_EXPIRED');
$ok($post($root,'/management/cutting/transfers/'.$tr['id'].'/cancel',['expectedVersion'=>2,'comment'=>'Destinatarul nu mai este eligibil']), 'recover eligibility-change request');

// Final 2 -> 3 completer is also the existing cutting-fault responsible identity.
$ok($post($tailor,'/orders/'.$o['id'].'/claim',['expectedVersion'=>7]),'intake claims transferred completion');
$s=$pdo->prepare('SELECT item_uuid FROM operational_order_items WHERE order_uuid=? ORDER BY line_number'); $s->execute([$o['uuid']]); $items=$s->fetchAll(PDO::FETCH_COLUMN);
$fault=$ok($post($tailor,'/orders/'.$o['id'].'/fault-reports',['expectedVersion'=>8,'itemIds'=>[$items[0]],'reasonKey'=>'wrong-cut']),'fault after transfer',201);
$check($fault['responsible']['displayName']==='Test cut-b','fault derives final cutter, never first owner');
$s=$pdo->prepare("SELECT employee_uuid, meters, item_count_snapshot, active_seconds_snapshot FROM cutting_facts WHERE order_uuid=? AND fact_type='completed'");$s->execute([$o['uuid']]);$fact=$s->fetch(PDO::FETCH_ASSOC);
$check($fact['employee_uuid']===$b['employeeUuid'] && $fact['meters']==='8.400' && (int)$fact['item_count_snapshot']===1 && $fact['active_seconds_snapshot']!==null,'completion immutable whole meters/item count/active seconds');
$s=$pdo->prepare("SELECT COUNT(*) FROM cutting_facts WHERE order_uuid=? AND fact_type IN ('interval_started','interval_ended')");$s->execute([$o['uuid']]);$check((int)$s->fetchColumn()===4,'raw intervals for both owners retained');

// Scope, expiry, CSRF and exact-Origin boundaries for display devices.
$error(T::call($kernel,'GET','/display/cutting/snapshot'),401,'DISPLAY_SESSION_INVALID');
$sinem=$create('cut-sinem',[],['dashboard'],'operations-director');
foreach ([$manager,$manager2,$ceo,$sinem] as $who) {
    $error($post($who,'/management/cutting/devices',['name'=>'Forbidden device']),403,'ROOT_REQUIRED');
    $error(T::call($kernel,'PUT','/management/cutting/thresholds',['expectedVersion'=>1,'thresholds'=>[10,20,30]],['x-csrf-token'=>$who['csrf'],'idempotency-key'=>'deny-threshold-'.bin2hex(random_bytes(8))],$who['cookie']),403,'ROOT_REQUIRED');
}
$error(T::call($kernel,'POST','/management/cutting/devices',['name'=>'No CSRF'],['idempotency-key'=>'missing-csrf-'.$suffix],$root['cookie']),403,'CSRF_INVALID');
$error(T::call($kernel,'POST','/display/cutting/pair',['code'=>'0000000000000000'],['origin'=>'https://evil.example']),403,'ORIGIN_DENIED');
$expired=$ok($post($root,'/management/cutting/devices',['name'=>'Expired challenge']),'expiring code',201);
$pdo->prepare('UPDATE cutting_display_devices SET pairing_expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE device_uuid=?')->execute([$expired['id']]);
$error(T::call($kernel,'POST','/display/cutting/pair',['code'=>$expired['pairingCode']]),422,'PAIRING_INVALID');
$raceDevice=$ok($post($root,'/management/cutting/devices',['name'=>'Pairing race fixture']),'pair race device',201);
$pairJobs=[['path'=>'/display/cutting/pair','body'=>['code'=>$raceDevice['pairingCode']],'headers'=>[]],['path'=>'/display/cutting/pair','body'=>['code'=>$raceDevice['pairingCode']],'headers'=>[]]];
$pairResults=$race($pairJobs);$check($statuses($pairResults)===[200,422],'one-time challenge two browsers exactly one winner '.json_encode($pairResults));
$pairedAgain=$ok($post($root,'/management/cutting/devices/'.$device['id'].'/re-pair',['confirmed'=>true]),'fresh display');
$r=T::call($kernel,'POST','/display/cutting/pair',['code'=>$pairedAgain['pairingCode']]);$ok($r,'fresh pairing');preg_match('/^arasya_cutting_display=([^;]+);/',$r['headers']['Set-Cookie'],$displayCookie);
$displayCall=static function(string $method,string $path,?array $body=null,array $query=[]) use($kernel,$displayCookie):array {$r=$kernel->handle(new Request($method,$path,['origin'=>T::ORIGIN,'content-type'=>'application/json'],['arasya_cutting_display'=>$displayCookie[1]],$body===null?'':json_encode($body),'127.0.0.1','cutting-display-test','display-scope-test',$query));return ['status'=>$r->status,'body'=>$r->payload,'raw'=>$r->body];};
foreach (['/orders/mine','/cutting/pool','/management/me','/management/employees','/management/permissions'] as $path) $check($displayCall('GET',$path)['status']===401,'display cannot access '.$path);
foreach (['/orders/'.$other['id'].'/claim','/cutting/transfers/'.$tid.'/verify','/management/cutting/devices'] as $path) $check($displayCall('POST',$path,['expectedVersion'=>1])['status']===401,'display cannot mutate '.$path);
$pdo->prepare('UPDATE cutting_display_devices SET session_expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE device_uuid=?')->execute([$device['id']]);
$error($displayCall('GET','/display/cutting/snapshot'),401,'DISPLAY_SESSION_INVALID');
$pdo->prepare('UPDATE cutting_display_devices SET session_expires_at=UTC_TIMESTAMP(6)+INTERVAL 1 DAY WHERE device_uuid=?')->execute([$device['id']]);
$ok($post($root,'/management/cutting/devices/'.$device['id'].'/re-pair',['confirmed'=>true]),'repair revokes old cookie immediately');
$error($displayCall('GET','/live/events',null,['scope'=>'cutting-display']),401,'DISPLAY_SESSION_INVALID');

// Snapshot coverage is independent of the wall viewport, and business-day reset never changes owner.
$clock = new class implements \Arasya\Operations\Support\Clock { public DateTimeImmutable $instant; public function now(): DateTimeImmutable { return $this->instant; } };
$clock->instant = new DateTimeImmutable(gmdate('Y-m-d').' 10:00:00',new DateTimeZone('UTC'));
$devices = new \Arasya\Operations\Cutting\DisplayDevices($pdo,$config,$clock,new \Arasya\Operations\Employee\PdoEmployeeRepository($pdo),new \Arasya\Operations\Iam\IamAuditLogger($pdo),new \Arasya\Operations\Quality\IdempotencyStore($pdo));
$board = new \Arasya\Operations\Cutting\BoardSnapshot($pdo,$clock,$devices);
$beforeOwner=$ok($get($samePerson,'/cutting/pool'),'before day change')['ownedCount'];
$snapshot=$board->snapshot();$ownCard=array_values(array_filter($snapshot['active'],static fn($c)=>$c['id']===substr(hash('sha256',$c1['uuid']),0,24)))[0];
$check($snapshot['counters']['inProgress']===count($snapshot['active']) && $ownCard['employeeActiveCount']===6,'board includes every multiple assignment without conflating same display names');
// A deterministic fixture isolates 10 minutes of handling from 110 minutes of manager wait.
$blockedOrder=$order('blocked-time');$ok($post($a,'/orders/'.$blockedOrder['id'].'/claim',T::cuttingClaim($pdo,$blockedOrder['id'],$a['employeeUuid'],1)),'blocked time claim');
$blockedTransfer=$ok($post($a,'/cutting/orders/'.$blockedOrder['id'].'/transfers',['expectedVersion'=>2,'targetId'=>$b['employeeUuid'],'reasonKey'=>'illness']),'blocked time request',201);
$pdo->prepare("UPDATE operational_orders SET production_claimed_at='2026-10-05 08:00:00' WHERE order_uuid=?")->execute([$blockedOrder['uuid']]);
$pdo->prepare("UPDATE cutting_transfers SET requested_at='2026-10-05 08:10:00' WHERE transfer_uuid=?")->execute([$blockedTransfer['id']]);
$clock->instant=new DateTimeImmutable('2026-10-05 10:00:00',new DateTimeZone('UTC'));
$snapshot=$board->snapshot();$blockedCard=array_values(array_filter($snapshot['active'],static fn($c)=>$c['id']===substr(hash('sha256',$blockedOrder['uuid']),0,24)))[0];
$check($blockedCard['activeSeconds']===600 && $blockedCard['blockedSeconds']===6600 && $blockedCard['tone']==='blocked' && $blockedCard['state']==='Așteaptă aprobarea','external wait never becomes worker red delay');
$check(!in_array($blockedCard['id'],array_column($snapshot['waiting'],'id'),true),'transfer-blocked owned order never appears in free pool');
$clock->instant=new DateTimeImmutable(gmdate('Y-m-d').' 10:00:00',new DateTimeZone('UTC'));
// All twenty long product codes survive the database engine's default GROUP_CONCAT limit.
$codesOrder=$order('long-codes');$longItems=[];for($i=0;$i<20;$i++)$longItems[]=['id'=>900000+$i,'line'=>$i+1,'name'=>'Voal '.$i,'sku'=>str_repeat('X',70).'-'.$i,'meters'=>1.1,'quantity'=>2];
$ok(T::ingest($kernel,'trendhome',T::sourceOrder($codesOrder['sourceId'],'codes-'.$suffix,gmdate('Y-m-d\TH:i:s\Z'),null,'processing','active',$longItems)),'long codes ingest');
$ok($post($b,'/orders/'.$codesOrder['id'].'/claim',T::cuttingClaim($pdo,$codesOrder['id'],$b['employeeUuid'],1)),'whole twenty-line claim');
$snapshot=$board->snapshot();$codesCard=array_values(array_filter($snapshot['active'],static fn($c)=>$c['id']===substr(hash('sha256',$codesOrder['uuid']),0,24)))[0];
$check(count(explode(' · ',$codesCard['codes']))===20 && $codesCard['meters']==='22.000','no code truncation and no quantity multiplication');
$whole=$ok($post($b,'/orders/'.$codesOrder['id'].'/transition',['expectedVersion'=>2]),'whole twenty-line completion');
$s=$pdo->prepare("SELECT meters,item_count_snapshot FROM cutting_facts WHERE order_uuid=? AND fact_type='completed'");$s->execute([$codesOrder['uuid']]);$wholeFact=$s->fetch(PDO::FETCH_ASSOC);
$check($wholeFact['meters']==='22.000' && (int)$wholeFact['item_count_snapshot']===20,'whole twenty-line result not per-line ownership');
// Pool eligibility is canonical: not another stage, completed, cancelled or an already-owned order.
$ineligible=$order('other-stage','workshop-receiving');$done=$order('done');
$pdo->prepare('UPDATE operational_orders SET production_completed_at=UTC_TIMESTAMP(6) WHERE order_uuid=?')->execute([$done['uuid']]);
$pool=$ok($get($b,'/cutting/pool'),'eligibility pool');
foreach([$ineligible['id'],$done['id'],$cancel['id'],$other['id']] as $absent)$check(!in_array($absent,array_column($pool['items'],'id'),true),'ineligible/complete/unavailable/owned excluded');
$error($post($root,'/orders/'.$loser['id'].'/claim',['expectedVersion'=>2,'qrToken'=>$loser['qr']]),404,'ORDER_NOT_FOUND');
// 67 additional waiting orders: only twenty previews plus a truthful overflow, never a priority.
$many=[];for($i=0;$i<200;$i++)$many[]=$order('pool-many-'.$i);
$snapshot=$board->snapshot();$check(count($snapshot['waiting'])===20 && $snapshot['counters']['waiting']>=67 && $snapshot['waitingOverflow']===$snapshot['counters']['waiting']-20,'waiting preview bounded with full count and overflow');
$clock->instant=$clock->instant->modify('+1 day');$next=$board->snapshot();
$check($next['counters']['completedOrders']===0 && $ok($get($samePerson,'/cutting/pool'),'after day change')['ownedCount']===$beforeOwner,'daily reset changes visual counters, never ownership');
$clock->instant=new DateTimeImmutable('2026-10-11 10:00:00',new DateTimeZone('UTC'));$check(!$board->snapshot()['open'],'Sunday closed from authoritative hours');
$clock->instant=new DateTimeImmutable('2026-10-05 01:59:59',new DateTimeZone('UTC'));$closedKey=$board->stateKey();$clock->instant=$clock->instant->modify('+1 second');$check($closedKey!==$board->stateKey(),'schedule boundary changes cursor state without mutation');
// Dispose only this test's bulk preview fixtures through test SQL, retaining all source/fact history.
$s=$pdo->prepare("UPDATE operational_orders SET production_stage_id='waiting' WHERE order_uuid=?");foreach($many as $row)$s->execute([$row['uuid']]);
$pdo->exec("DELETE FROM api_rate_limit_buckets WHERE bucket_scope='cutting-pair'");
for ($attempt=0;$attempt<10;$attempt++) $error(T::call($kernel,'POST','/display/cutting/pair',['code'=>'invalid-test-code']),422,'PAIRING_INVALID');
$error(T::call($kernel,'POST','/display/cutting/pair',['code'=>'invalid-test-code']),429,'RATE_LIMITED');
echo "PASS {$checks} cutting integration checks\n";
