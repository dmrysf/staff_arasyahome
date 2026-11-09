<?php

declare(strict_types=1);

// B2B order types (migration 050, API 2.64.0): FULL_TAILORING keeps the canonical route; CUT_ONLY and FULL_ROLL_BAG are
// completed at stage 2 by an authorized stage 2 employee and the server then moves them to stage 14 (Livrare), where a
// Livrare employee receives them and completes the handover. Stages 3-13 are recorded as not applicable, never completed.
// Real API (HTTP kernel) on a disposable database with synthetic people, companies and orders only.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$db = T::requireTestDatabase();
if ($db === null) {
    echo "SKIP MySQL B2B order types: ARASYA_TEST_DB_NAME not configured.\n";
    exit;
}
$checks = 0;
function check(bool $value, string $message): void { global $checks; $checks++; if (!$value) throw new RuntimeException('FAIL ' . $message); }
function ok(array $r, int $status, string $message): array { check($r['status'] === $status, $message . ' got ' . $r['status'] . ' ' . json_encode($r['body'])); return $r['body'] ?? []; }
function err(array $r, int $status, string $code, string $message): array { ok($r, $status, $message); check(($r['body']['error']['code'] ?? null) === $code, $message . ' code ' . json_encode($r['body'])); return $r['body']; }
function cui(string $base): string { $body = str_pad($base, 9, '0', STR_PAD_LEFT); $key = '753217532'; $sum = 0; for ($i = 0; $i < 9; $i++) { $sum += (int) $body[$i] * (int) $key[$i]; } $c = ($sum * 10) % 11; return $base . ($c === 10 ? 0 : $c); }
function refused(PDO $pdo, string $sql, array $params = []): bool { try { $pdo->prepare($sql)->execute($params); return false; } catch (PDOException) { return true; } }

$db = substr($db, 0, 40) . '_order_types';
$server = new PDO('mysql:host=' . (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('ARASYA_TEST_DB_PORT') ?: '3306'), (string) getenv('ARASYA_TEST_DB_USER'), (string) getenv('ARASYA_TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `{$db}`");
$server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$config = T::config($db);
$pdo = Connection::create($config);
$dir = dirname(__DIR__) . '/database/migrations';
(new MigrationRunner($pdo))->migrate($dir);
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') as $file) (new SqlFileRunner($pdo))->run($file);
$rows = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };
$scalar = static function (string $sql, array $params = []) use ($rows): mixed { $r = $rows($sql, $params); return $r === [] ? null : array_values($r[0])[0]; };

// ---- People -----------------------------------------------------------------------------------------------------------
$base = new Container($config, $pdo);
$kernel = $base->kernel();
$suffix = bin2hex(random_bytes(4));
$pdo->exec('DELETE FROM system_root_identity');
$temporary = (new RootBootstrapService($pdo, $base->passwordHasher(), $base->clock()))->bootstrap('order-types-test', 'types.root.' . $suffix);
$first = T::login($kernel, 'types.root.' . $suffix, $temporary);
ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Order types root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 200, 'root password');
$root = T::login($kernel, 'types.root.' . $suffix, 'Order types root passphrase 2026!');
$call = static fn (array $who, string $method, string $path, ?array $json = null, array $query = [], ?string $key = null, ?\Arasya\Operations\Http\ApiKernel $k = null): array
    => T::call($k ?? $kernel, $method, $path, $json, $method === 'GET' ? [] : ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? ('types-' . bin2hex(random_bytes(10)))], $who['cookie'], $query);
$password = 'order types test passphrase 2026';
$admin = $base->employeeAdmin();
$worker = static function (string $name, string $department, array $stages) use ($admin, $kernel, $password, $suffix): array {
    $employee = $admin->create("Synthetic {$name}", "{$name}.{$suffix}", null, $department, 'employee', $password, $stages, 'order-types-test');
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['id' => $employee->employeeUuid];
};
$workshopStages = ['workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing'];
$intake = $worker('intake', 'pregatire-material', ['waiting']);
$cutter = $worker('cutter', 'pregatire-material', ['material-preparation']);
$tailor = $worker('tailor', 'pregatire-material', $workshopStages);
$livrare = $worker('livrare', 'pregatire-material', ['delivery']);
// Has the stage 2 grant but is not a stage 2 (cutting) employee: the cutting eligibility refuses it.
$wrongDepartment = $worker('wrongdept', 'conducere', ['material-preparation']);

// ---- Commercial setup -------------------------------------------------------------------------------------------------
$companyId = ok($call($root, 'POST', '/b2b/companies', ['legalName' => 'Synthetic Types SRL ' . $suffix, 'countryCode' => 'RO', 'taxIdentifier' => 'TYPES' . $suffix,
    'address' => ['type' => 'delivery', 'countryCode' => 'RO', 'city' => 'Brasov', 'addressLine1' => 'Str. Test 1', 'isPrimary' => true]]), 201, 'company')['companyId'];
$line = ['productCode' => 'TYPE-1', 'productName' => 'Synthetic fabric', 'variant' => null, 'color' => 'Alb', 'kind' => 'curtain', 'width' => '200', 'height' => '260', 'quantity' => 1,
    'meters' => '6.5', 'pricingUnit' => 'meter', 'unitPriceNet' => '10.00', 'discountPercent' => '0', 'vatPercent' => '19', 'notes' => null, 'productionNotes' => null];
$draft = static fn (?string $type, string $company, ?array $l = null, ?\Arasya\Operations\Http\ApiKernel $k = null): array
    => ok($call($root, 'POST', '/b2b/orders', ['companyId' => $company, 'currencyCode' => 'RON', 'orderType' => $type, 'lines' => [$l ?? $line]], [], null, $k), 201, 'draft ' . ($type ?? 'untyped'))['detail']['order'];
$finalize = static fn (array $o, ?\Arasya\Operations\Http\ApiKernel $k = null): array => $call($root, 'POST', "/b2b/orders/{$o['id']}/finalize", ['expectedVersion' => $o['version']], [], null, $k);
$submit = static function (array $o) use ($call, $root): string {
    $progress = ok($call($root, 'POST', "/b2b/orders/{$o['id']}/production", ['expectedVersion' => $o['version']]), 201, 'submit ' . $o['code'])['production'];
    return $progress['operationalOrderId'];
};
$production = static fn (string $orderId): array => ok($call($root, 'GET', "/b2b/orders/{$orderId}/production"), 200, 'B2B production read')['production'];
$staff = static fn (array $who, string $global): array => $call($who, 'GET', '/orders/' . $global);
$version = static fn (string $global): int => (int) $scalar('SELECT production_version FROM operational_orders WHERE global_order_id = ?', [$global]);
$stageOf = static fn (string $global): string => (string) $scalar('SELECT production_stage_id FROM operational_orders WHERE global_order_id = ?', [$global]);
$claim = static fn (array $who, string $global, ?string $key = null): array => $call($who, 'POST', "/orders/{$global}/claim", T::cuttingClaim($pdo, $global, $who['id'], $version($global)), [], $key);
$complete = static fn (array $who, string $global, ?string $key = null, ?int $expected = null): array => $call($who, 'POST', "/orders/{$global}/transition", ['expectedVersion' => $expected ?? $version($global)], [], $key);
$toCutting = static function (string $global) use ($claim, $complete, $intake, $stageOf): void {
    ok($claim($intake, $global), 200, 'stage 1 claim');
    $moved = ok($complete($intake, $global), 200, 'stage 1 completion');
    check($moved['productionStageId'] === 'material-preparation' && $stageOf($global) === 'material-preparation', 'E. stage 1 always leads to stage 2, never to Livrare');
};
$events = static fn (string $global): array => $rows('SELECT action, from_stage_id, to_stage_id, employee_uuid FROM order_activity_events WHERE global_order_id = ? ORDER BY production_version_after, event_id', [$global]);
$uuidOf = static fn (string $global): string => (string) $scalar('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$global]);
$skippedStages = ['workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing'];

// ---- Draft, freeze and validation -----------------------------------------------------------------------------------
err($call($root, 'POST', '/b2b/orders', ['companyId' => $companyId, 'currencyCode' => 'RON', 'orderType' => 'EXPRESS', 'lines' => [$line]]), 422, 'VALIDATION_FAILED', 'unknown order type refused');
$untyped = $draft(null, $companyId);
check($untyped['orderType'] === null, 'a draft may wait for its type');
$missing = err($finalize($untyped), 422, 'VALIDATION_FAILED', 'finalization needs an explicit type');
check(($missing['error']['details']['fields']['orderType'] ?? null) === 'required', 'the missing type is named');
// The line kind never implies the order type, in either direction.
$kindOther = $draft('FULL_TAILORING', $companyId, array_replace($line, ['kind' => 'other']));
$kindCurtain = $draft('FULL_ROLL_BAG', $companyId, array_replace($line, ['kind' => 'curtain']));
check($kindOther['orderType'] === 'FULL_TAILORING' && $kindOther['lines'][0]['kind'] === 'other' && $kindCurtain['orderType'] === 'FULL_ROLL_BAG' && $kindCurtain['lines'][0]['kind'] === 'curtain', 'product kind and order type stay independent');
$edited = ok($call($root, 'PUT', "/b2b/orders/{$untyped['id']}", array_replace(array_intersect_key($untyped, array_flip(Arasya\Operations\B2B\OrderInput::FIELDS)), ['orderType' => 'CUT_ONLY', 'expectedVersion' => $untyped['version']])), 200, 'type edited on the draft')['detail']['order'];
check($edited['orderType'] === 'CUT_ONLY', 'draft type editable under the version rules');
$withoutType = array_diff_key(array_intersect_key($edited, array_flip(Arasya\Operations\B2B\OrderInput::FIELDS)), ['orderType' => true]);
$kept = ok($call($root, 'PUT', "/b2b/orders/{$edited['id']}", $withoutType + ['expectedVersion' => $edited['version'], 'notes' => 'older screen']), 200, 'save without orderType')['detail']['order'];
check($kept['orderType'] === 'CUT_ONLY', 'a save that omits orderType keeps the type');
$activity = ok($call($root, 'GET', "/b2b/orders/{$edited['id']}/activity"), 200, 'activity')['items'];
check(in_array('orderType', array_merge(...array_map(static fn (array $e): array => $e['changedFields'] ?? [], $activity)), true), 'the type change is in the commercial history');
$cutOnly = ok($finalize($kept), 200, 'finalize CUT_ONLY')['detail']['order'];
check($cutOnly['status'] === 'finalized' && $cutOnly['orderType'] === 'CUT_ONLY' && ($cutOnly['financeDecision']['mode'] ?? null) === 'off' && $cutOnly['financeDecision']['outcome'] === 'NOT_EVALUATED', 'frozen type; finance decision snapshot records mode off');
err($call($root, 'PUT', "/b2b/orders/{$cutOnly['id']}", array_replace(array_intersect_key($cutOnly, array_flip(Arasya\Operations\B2B\OrderInput::FIELDS)), ['orderType' => 'FULL_TAILORING', 'expectedVersion' => $cutOnly['version']])), 409, 'ORDER_FINALIZED', 'the type is frozen at finalization');
check(count($rows("SELECT 1 FROM b2b_account_movements WHERE order_uuid = ? AND movement_type = 'order_receivable'", [$cutOnly['id']])) === 1, 'finalization posts exactly one receivable');
err($call($root, 'POST', "/b2b/orders/{$cutOnly['id']}/production", ['expectedVersion' => $cutOnly['version'], 'orderType' => 'FULL_TAILORING']), 400, 'INVALID_REQUEST', 'a submission cannot carry a route');

// ---- B: CUT_ONLY ------------------------------------------------------------------------------------------------------
$b = $submit($cutOnly);
$bUuid = $uuidOf($b);
check($scalar('SELECT service_route FROM operational_orders WHERE order_uuid = ?', [$bUuid]) === 'CUT_ONLY' && $scalar('SELECT order_type FROM b2b_production_handoffs WHERE operational_order_uuid = ?', [$bUuid]) === 'CUT_ONLY', 'frozen type copied to the canonical order and the handoff');
check($b === 'b2b:' . $cutOnly['id'] && count($rows("SELECT 1 FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$bUuid])) === 1, 'one canonical identity and one QR');
$view = ok($staff($intake, $b), 200, 'Staff reads the typed order');
check(($view['serviceRoute'] ?? null) === ['orderType' => 'CUT_ONLY', 'shortRoute' => true, 'stages' => ['waiting', 'material-preparation', 'delivery']] && ($view['productionContext']['orderType'] ?? null) === 'CUT_ONLY', 'Staff sees the type and the short route');
$p = $production($cutOnly['id']);
check($p['orderType'] === 'CUT_ONLY' && $p['route']['stages'] === ['waiting', 'material-preparation', 'delivery'] && $p['route']['notApplicable'] === $skippedStages && $p['nextStage']['id'] === 'material-preparation' && !$p['stage2Completed'] && $p['delivery'] === 'IN_PRODUCTION', 'B2B shows route, next stage and state');
// E: no direct Livrare before stage 2.
err($staff($livrare, $b), 404, 'ORDER_NOT_FOUND', 'E. Livrare does not see a new order');
err($claim($livrare, $b), 404, 'ORDER_NOT_FOUND', 'E. Livrare cannot claim before stage 2');
$toCutting($b);
err($claim($livrare, $b), 404, 'ORDER_NOT_FOUND', 'E. Livrare cannot claim at stage 2');
err($complete($cutter, $b), 409, 'INVALID_STAGE_TRANSITION', 'E. stage 2 cannot be completed without a claim');
// D: no stage 2 authority.
err($claim($tailor, $b), 404, 'ORDER_NOT_FOUND', 'D. a tailor without stage 2 cannot reach the order');
err($claim($wrongDepartment, $b), 403, 'UNAUTHORIZED_ACTION', 'D. a stage grant without cutting eligibility is refused');
check($stageOf($b) === 'material-preparation' && $scalar('SELECT production_owner_employee_uuid FROM operational_orders WHERE order_uuid = ?', [$bUuid]) === null, 'D. no route or owner change');
ok($claim($cutter, $b), 200, 'B. stage 2 employee claims with the QR');
err($complete($wrongDepartment, $b), 409, 'ORDER_ALREADY_CLAIMED', 'D. another employee cannot complete it');
// F: one completion, replayed.
$beforeComplete = count($events($b));
$key = 'types-complete-' . $suffix;
$expected = $version($b);
$done = ok($complete($cutter, $b, $key, $expected), 200, 'B. cutting completed');
check($done['productionStageId'] === 'delivery' && $stageOf($b) === 'delivery', 'B. material-preparation -> delivery');
$afterComplete = $events($b);
$replayed = ok($complete($cutter, $b, $key, $expected), 200, 'F. lost response replayed');
check($replayed['productionStageId'] === 'delivery' && count($events($b)) === count($afterComplete), 'F. replay writes nothing');
err($complete($cutter, $b, null, $expected), 409, 'ORDER_CHANGED', 'F. a double click with a new key is refused');
check(count($events($b)) === $beforeComplete + 1 && count($rows("SELECT 1 FROM order_activity_events WHERE order_uuid = ? AND action = 'stage_completed' AND from_stage_id = 'material-preparation'", [$bUuid])) === 1, 'F. exactly one transition to Livrare');
$transition = end($afterComplete);
check($transition['action'] === 'stage_completed' && $transition['from_stage_id'] === 'material-preparation' && $transition['to_stage_id'] === 'delivery' && $transition['employee_uuid'] === $cutter['id'], 'B. the stage 2 employee completed stage 2');
check(array_values(array_filter($events($b), static fn (array $e): bool => in_array($e['from_stage_id'], $skippedStages, true) || in_array($e['to_stage_id'], $skippedStages, true))) === [], 'B. no event at stages 3-13');
$decision = $rows("SELECT * FROM production_route_decisions WHERE order_uuid = ? AND reason = 'order_service_route'", [$bUuid]);
check(count($decision) === 1 && $decision[0]['kind'] === 'stage_not_applicable' && $decision[0]['from_stage_id'] === 'material-preparation' && $decision[0]['to_stage_id'] === 'delivery'
    && json_decode((string) $decision[0]['skipped_stages_json'], true) === $skippedStages && $decision[0]['actor_employee_uuid'] === $cutter['id'], 'B. stages 3-13 recorded as not applicable, with the reason');
check($rows("SELECT 1 FROM production_unit_stage_log WHERE order_uuid = ? AND outcome = 'completed' AND stage_id IN ('" . implode("','", $skippedStages) . "')", [$bUuid]) === [], 'B. no piece completion at stages 3-13');
$na = $rows("SELECT DISTINCT stage_id FROM production_unit_stage_log WHERE order_uuid = ? AND outcome = 'not_applicable'", [$bUuid]);
check($na === [] || count($na) === count($skippedStages), 'B. materialized pieces carry not_applicable for every skipped stage');
check(count($rows("SELECT 1 FROM cutting_facts WHERE order_uuid = ? AND fact_type = 'completed' AND employee_uuid = ?", [$bUuid, $cutter['id']])) === 1, 'B. the cutting is counted for the cutter');
check($scalar('SELECT cutting_employee_uuid FROM analytics_order_projection WHERE order_uuid = ?', [$bUuid]) === $cutter['id'], 'B. analytics credit the cutting to the cutter');
$audit = $rows("SELECT * FROM iam_audit_events WHERE action = 'production.service_route_applied' AND target_id = ?", [$b]);
$meta = json_decode((string) ($audit[0]['metadata_json'] ?? 'null'), true);
check(count($audit) === 1 && $audit[0]['actor_employee_uuid'] === $cutter['id'] && $meta['orderType'] === 'CUT_ONLY' && $meta['workType'] === 'cutting' && $meta['b2bOrderId'] === $cutOnly['id']
    && $meta['operationalOrderId'] === $bUuid && $meta['claimedAt'] !== null && $meta['routeDecisionId'] === $decision[0]['decision_uuid'] && $meta['toStage'] === 'delivery'
    && $meta['productionVersionAfter'] === $meta['productionVersionBefore'] + 1 && strlen((string) $meta['idempotencyReference']) === 64, 'B. stage 2 accountability recorded once');
$p = $production($cutOnly['id']);
check($p['stage']['id'] === 'delivery' && $p['stage2Completed'] && $p['delivery'] === 'READY_FOR_LIVRARE' && $p['completedAt'] === null && $p['nextStage'] === null, 'B. ready for Livrare, not handed over');
// G: only Livrare receives it.
// The stage 2 employee still sees the order it handed over (its history) but has no Livrare authority.
err($claim($cutter, $b), 403, 'UNAUTHORIZED_ACTION', 'G. the stage 2 employee cannot take Livrare work');
err($claim($tailor, $b), 404, 'ORDER_NOT_FOUND', 'G. a tailor cannot take Livrare work');
ok($claim($livrare, $b), 200, 'B. Livrare receives the order');
check($production($cutOnly['id'])['delivery'] === 'RECEIVED_BY_LIVRARE', 'B. received by Livrare');
err($complete($tailor, $b), 404, 'ORDER_NOT_FOUND', 'G. no one else completes Livrare');
$handed = ok($complete($livrare, $b), 200, 'B. Livrare completes the handover');
check(isset($handed['productionCompletedAt']) && $production($cutOnly['id'])['delivery'] === 'HANDED_OVER', 'B. handed over only after the Livrare completion');
$last = $events($b);
$last = end($last);
check($last['action'] === 'production_completed' && $last['from_stage_id'] === 'delivery' && $last['employee_uuid'] === $livrare['id'], 'B. the existing final-stage completion is recorded by Livrare');

// ---- C: FULL_ROLL_BAG -------------------------------------------------------------------------------------------------
$roll = ok($finalize($kindCurtain), 200, 'finalize FULL_ROLL_BAG')['detail']['order'];
$c = $submit($roll);
$cUuid = $uuidOf($c);
$toCutting($c);
ok($claim($cutter, $c), 200, 'C. stage 2 employee claims the bag/roll');
$prepared = ok($complete($cutter, $c), 200, 'C. preparation completed');
check($prepared['productionStageId'] === 'delivery', 'C. material-preparation -> delivery');
check($rows("SELECT 1 FROM cutting_facts WHERE order_uuid = ? AND fact_type = 'completed'", [$cUuid]) === [], 'C. no cutting completion, meters or time for a bag/roll');
check($scalar('SELECT cutting_completed_at FROM analytics_order_projection WHERE order_uuid = ?', [$cUuid]) === null, 'C. analytics count no cutting');
$cMeta = json_decode((string) $scalar("SELECT metadata_json FROM iam_audit_events WHERE action = 'production.service_route_applied' AND target_id = ?", [$c]), true);
check($cMeta['orderType'] === 'FULL_ROLL_BAG' && $cMeta['workType'] === 'full_roll_bag_preparation' && $cMeta['stage2EmployeeId'] === $cutter['id'], 'C. the preparation is recorded as preparation, by the stage 2 employee');
check($rows("SELECT 1 FROM production_unit_stage_log WHERE order_uuid = ? AND outcome = 'completed' AND stage_id IN ('" . implode("','", $skippedStages) . "')", [$cUuid]) === [], 'C. no tailoring work recorded');
ok($claim($livrare, $c), 200, 'C. Livrare receives');
ok($complete($livrare, $c), 200, 'C. Livrare hands over');
check($production($roll['id'])['delivery'] === 'HANDED_OVER', 'C. handed over');

// ---- A: FULL_TAILORING ------------------------------------------------------------------------------------------------
$full = ok($finalize($kindOther), 200, 'finalize FULL_TAILORING')['detail']['order'];
$a = $submit($full);
$p = $production($full['id']);
check($p['orderType'] === 'FULL_TAILORING' && !$p['route']['shortRoute'] && count($p['route']['stages']) === 14 && $p['route']['notApplicable'] === [], 'A. the full canonical route');
check(($staff($intake, $a)['body']['serviceRoute'] ?? null) === ['orderType' => 'FULL_TAILORING', 'shortRoute' => false, 'stages' => null], 'A. Staff shows the full route');
$toCutting($a);
ok($claim($cutter, $a), 200, 'A. cutting claim');
check(ok($complete($cutter, $a), 200, 'A. cutting done')['productionStageId'] === 'workshop-receiving', 'A. stage 2 leads to stage 3');
foreach ($workshopStages as $stage) {
    check($stageOf($a) === $stage, "A. at {$stage}");
    ok($claim($tailor, $a), 200, "A. claim {$stage}");
    ok($complete($tailor, $a), 200, "A. complete {$stage}");
}
check($stageOf($a) === 'delivery', 'A. reaches Livrare through every stage');
ok($claim($livrare, $a), 200, 'A. Livrare receives');
ok($complete($livrare, $a), 200, 'A. Livrare hands over');
check($production($full['id'])['delivery'] === 'HANDED_OVER' && $rows("SELECT 1 FROM production_route_decisions WHERE order_uuid = ? AND reason = 'order_service_route'", [$uuidOf($a)]) === [], 'A. no service-route decision');

// ---- H: a production stop blocks the 2 -> 14 transition until released --------------------------------------------
$held = $submit(ok($finalize($draft('CUT_ONLY', $companyId)), 200, 'finalize held')['detail']['order']);
$toCutting($held);
ok($claim($cutter, $held), 200, 'H. claim');
ok($call($root, 'POST', '/management/orders/' . rawurlencode($held) . '/cancellation/stop', ['reason' => 'Clientul a cerut oprirea comenzii.', 'requestSourceCancellation' => false]), 200, 'H. production stop');
err($complete($cutter, $held), 409, 'ORDER_PRODUCTION_STOPPED', 'H. the stop blocks the route');
check($stageOf($held) === 'material-preparation' && $rows("SELECT 1 FROM production_route_decisions WHERE order_uuid = ?", [$uuidOf($held)]) === [], 'H. nothing moved');
$control = static fn () => $rows('SELECT version, physical_status FROM order_cancellation_controls WHERE order_uuid = ?', [$uuidOf($held)])[0];
if ($control()['physical_status'] === 'pending') {
    ok($call($root, 'POST', '/management/orders/' . rawurlencode($held) . '/cancellation/physical', ['expectedVersion' => (int) $control()['version'], 'outcome' => 'secured', 'note' => 'Materialul este la etapa 2, asigurat.', 'qrToken' => 'ARASYA:Q1:' . $scalar("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$uuidOf($held)])]), 200, 'H. physical state recorded');
}
ok($call($root, 'POST', '/management/orders/' . rawurlencode($held) . '/cancellation/release', ['expectedVersion' => (int) $control()['version'], 'reason' => 'Clientul a confirmat comanda din nou.']), 200, 'H. released');
$owner = $scalar('SELECT production_owner_employee_uuid FROM operational_orders WHERE order_uuid = ?', [$uuidOf($held)]);
if ($owner === null) ok($claim($cutter, $held), 200, 'H. claim again after release');
check(ok($complete($cutter, $held), 200, 'H. route after release')['productionStageId'] === 'delivery', 'H. the route resumes once the hold is resolved');

// ---- I: a historical order without a type keeps the full route ------------------------------------------------------
$legacyDraft = $draft('CUT_ONLY', $companyId);
$legacy = ok($finalize($legacyDraft), 200, 'finalize legacy')['detail']['order'];
// Simulate an order finalized before migration 050: no type is stored.
$pdo->prepare('UPDATE b2b_orders SET order_type = NULL WHERE order_uuid = ?')->execute([$legacy['id']]);
$i = $submit($legacy);
check($scalar('SELECT service_route FROM operational_orders WHERE global_order_id = ?', [$i]) === null && $scalar('SELECT order_type FROM b2b_production_handoffs WHERE b2b_order_uuid = ?', [$legacy['id']]) === null, 'I. no type, no service route');
$toCutting($i);
ok($claim($cutter, $i), 200, 'I. claim');
check(ok($complete($cutter, $i), 200, 'I. complete')['productionStageId'] === 'workshop-receiving', 'I. no automatic 2 -> 14 shortcut');
check(!isset($staff($tailor, $i)['body']['serviceRoute']), 'I. Staff shows no service route');

// ---- J: other sources never carry or use a service route ------------------------------------------------------------
$number = (string) random_int(91000000, 91999999);
check((T::ingest($kernel, 'trendhome', T::sourceOrder($number, "types-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 120), T::stage('material-preparation')))['body']['outcome'] ?? null) === 'applied', 'J. TrendHome order');
$th = 'trendhome:' . $number;
check($scalar('SELECT service_route FROM operational_orders WHERE global_order_id = ?', [$th]) === null, 'J. a source order has no service route');
check(refused($pdo, 'UPDATE operational_orders SET service_route = ? WHERE global_order_id = ?', ['CUT_ONLY', $th]), 'J. the database refuses a service route on another source');
check(refused($pdo, 'UPDATE operational_orders SET service_route = ? WHERE global_order_id = ?', ['EXPRESS', $b]), 'J. the database refuses an unknown service route');
check(refused($pdo, 'UPDATE b2b_orders SET order_type = ? WHERE order_uuid = ?', ['EXPRESS', $legacy['id']]), 'J. the database refuses an unknown order type');
ok($claim($cutter, $th), 200, 'J. TrendHome cutting claim');
check(ok($complete($cutter, $th), 200, 'J. TrendHome cutting done')['productionStageId'] === 'workshop-receiving', 'J. TrendHome keeps its route');

// ---- K / L: finance gate ----------------------------------------------------------------------------------------------
$financePerson = static function (string $name, array $permissions, string $app = 'finance') use ($admin, $call, $root, $password, $suffix, $kernel): array {
    $employee = $admin->create("Synthetic {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'order-types-test');
    ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => [$app]]), 200, "{$name} applications");
    $roleId = (int) ok($call($root, 'POST', '/management/roles', ['name' => "{$name} {$suffix}", 'description' => null, 'authorityRank' => 200, 'permissions' => $permissions]), 201, "role {$name}")['role']['id'];
    ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => [$roleId]]), 200, "{$name} roles");
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['id' => $employee->employeeUuid];
};
$reviewer = $financePerson('reviewer', ['finance.companies.view', 'finance.companies.review']);
$verifier = $financePerson('verifier', ['finance.companies.view', 'finance.companies.verify']);
$acc1 = $financePerson('accone', ['finance.accounts.view', 'finance.accounts.reconcile', 'finance.accounts.propose_opening_balance']);
$acc2 = $financePerson('acctwo', ['finance.accounts.view', 'finance.accounts.reconcile', 'finance.accounts.verify_opening_balance']);
$owner = $financePerson('owner', ['finance.risk.view', 'finance.risk.approve', 'finance.credit.view', 'finance.credit.approve', 'finance.credit.revoke']);
$args = [];
foreach ((new ReflectionClass(Config::class))->getConstructor()->getParameters() as $parameter) $args[$parameter->getName()] = $config->{$parameter->getName()};
$withGate = static fn (string $mode): \Arasya\Operations\Http\ApiKernel => (new Container(new Config(...array_replace($args, ['financeRiskLimitAuthorityUuids' => $owner['id'], 'b2bFinanceGate' => $mode])), $pdo))->kernel();
$enforce = $withGate('enforce');
$shadow = $withGate('shadow');
$evidence = [['kind' => 'registry_extract', 'sourceSystem' => 'REGISTRY', 'reference' => 'Synthetic registry extract types', 'documentDate' => '2026-11-01']];
$verifiedCompany = static function (string $name, int $seed) use ($call, $reviewer, $verifier, $evidence, $root): string {
    $id = ok($call($reviewer, 'POST', '/finance/cases', ['legalName' => $name, 'countryCode' => 'RO', 'taxIdentifier' => cui((string) $seed), 'addressLine' => 'Str. V 4', 'city' => 'Brasov']), 201, 'case ' . $name)['caseId'];
    $v = ok($call($reviewer, 'GET', "/finance/cases/{$id}"), 200, 'case')['case']['version'];
    $v = ok($call($reviewer, 'POST', "/finance/cases/{$id}/claim", ['expectedVersion' => $v]), 200, 'claim case')['version'];
    foreach (['legal_name', 'country', 'tax_id', 'duplicate_search', 'address', 'contact', 'sources', 'documents', 'conflicts'] as $item) {
        $body = ['expectedVersion' => $v, 'item' => $item, 'state' => 'done'] + ($item === 'tax_id' ? ['evidence' => $evidence] : []);
        $v = ok($call($reviewer, 'POST', "/finance/cases/{$id}/checklist", $body), 200, "checklist {$item}")['version'];
    }
    $v = ok($call($reviewer, 'POST', "/finance/cases/{$id}/submit", ['expectedVersion' => $v]), 200, 'submit case')['version'];
    $company = ok($call($verifier, 'POST', "/finance/cases/{$id}/approve", ['expectedVersion' => $v]), 200, 'approve case')['companyId'];
    $detail = ok($call($root, 'GET', "/b2b/companies/{$company}"), 200, 'company detail');
    if (($detail['company']['status'] ?? 'active') !== 'active') ok($call($root, 'POST', "/b2b/companies/{$company}/reactivate", ['expectedVersion' => $detail['company']['version']]), 200, 'activate company');
    return $company;
};
$eligible = $verifiedCompany('Synthetic Eligible SRL ' . $suffix, 7300000 + random_int(1, 99999));
$o = ok($call($acc1, 'POST', '/finance/accounts', ['companyId' => $eligible]), 201, 'open account')['onboardingId'];
$v = ok($call($acc1, 'POST', "/finance/accounts/{$o}/draft", ['expectedVersion' => 1, 'balanceKind' => 'ZERO', 'amount' => '0', 'asOfDate' => '2026-11-01', 'note' => 'Synthetic zero',
    'evidence' => [['kind' => 'correspondence', 'sourceSystem' => 'MANUAL', 'reference' => 'Synthetic balance confirmation types']]]), 200, 'zero draft')['version'];
$v = ok($call($acc1, 'POST', "/finance/accounts/{$o}/submit", ['expectedVersion' => $v]), 200, 'zero submit')['version'];
check(ok($call($acc2, 'POST', "/finance/accounts/{$o}/verify", ['expectedVersion' => $v]), 200, 'zero verify')['onboardingStatus'] === 'RECONCILED', 'reconciled account');
$state = static fn (string $company): string => ok($call($owner, 'GET', "/finance/owner/companies/{$company}", null, [], null, $enforce), 200, 'owner detail')['state'];
ok($call($owner, 'POST', "/finance/owner/companies/{$eligible}/risk", ['expectedState' => $state($eligible), 'classification' => 'GREEN', 'effect' => 'EFFECTIVE', 'reason' => 'Synthetic owner GREEN'], [], null, $enforce), 201, 'owner GREEN');
ok($call($owner, 'POST', "/finance/owner/companies/{$eligible}/credit", ['expectedState' => $state($eligible), 'action' => 'SET', 'amount' => '100.00', 'currency' => 'RON', 'effect' => 'EFFECTIVE', 'reason' => 'Synthetic owner limit'], [], null, $enforce), 201, 'owner limit 100.00 RON');
$blockedCompany = $companyId;   // never verified, no colour, no limit

$receivables = static fn (string $order): int => count($rows("SELECT 1 FROM b2b_account_movements WHERE order_uuid = ? AND movement_type = 'order_receivable'", [$order]));
foreach (['CUT_ONLY', 'FULL_ROLL_BAG'] as $type) {
    $k = $draft($type, $blockedCompany, null, $enforce);
    $refusal = err($finalize($k, $enforce), 409, 'FINANCE_NOT_ELIGIBLE', "K. {$type} for a blocked customer");
    check(in_array('IDENTITY_NOT_VERIFIED', $refusal['error']['details']['reasons'], true) && in_array('NO_APPROVED_LIMIT', $refusal['error']['details']['reasons'], true), 'K. the reasons are named');
    $after = ok($call($root, 'GET', "/b2b/orders/{$k['id']}"), 200, 'K. order read')['order'];
    check($after['status'] === 'draft' && $after['financeDecision'] === null && $receivables($k['id']) === 0, "K. {$type}: not finalized, no receivable, no snapshot");
    err($call($root, 'POST', "/b2b/orders/{$k['id']}/production", ['expectedVersion' => $after['version']]), 409, 'PRODUCTION_NOT_ELIGIBLE', "K. {$type} cannot reach production or QR");
    check($rows("SELECT 1 FROM operational_orders WHERE global_order_id = ?", ['b2b:' . $k['id']]) === [], "K. {$type}: no canonical order, no QR");
}
// Shadow: the same refusal is only recorded.
$s = ok($finalize($draft('CUT_ONLY', $blockedCompany, null, $shadow), $shadow), 200, 'shadow finalize')['detail']['order'];
check($s['financeDecision']['mode'] === 'shadow' && $s['financeDecision']['outcome'] === 'WOULD_BLOCK' && in_array('RISK_NOT_ASSIGNED', $s['financeDecision']['reasons'], true) && $receivables($s['id']) === 1, 'shadow records WOULD_BLOCK and finalizes');
// Over the limit: 100.00 RON limit, a 77.35 RON order fits once, a second does not (exposure counts the first).
$l1 = ok($finalize($draft('FULL_ROLL_BAG', $eligible, null, $enforce), $enforce), 200, 'L. eligible finalize')['detail']['order'];
check($l1['financeDecision']['outcome'] === 'ALLOWED' && $l1['financeDecision']['reasons'] === [] && $l1['financeDecision']['profile']['riskColor'] === 'GREEN'
    && $l1['financeDecision']['profile']['availableCredit'] === '100.00' && $l1['financeDecision']['orderType'] === 'FULL_ROLL_BAG' && $receivables($l1['id']) === 1, 'L. ALLOWED with its basis, one receivable');
$over = err($finalize($draft('CUT_ONLY', $eligible, null, $enforce), $enforce), 409, 'FINANCE_NOT_ELIGIBLE', 'K. exposure plus order over the limit');
check(in_array('CREDIT_LIMIT_EXCEEDED', $over['error']['details']['reasons'], true), 'K. CREDIT_LIMIT_EXCEEDED');
// L: the full chain for the allowed order: production, stage 2, Livrare.
$lg = $submit($l1);
$toCutting($lg);
ok($claim($cutter, $lg), 200, 'L. stage 2 claim');
check(ok($complete($cutter, $lg), 200, 'L. stage 2 done')['productionStageId'] === 'delivery', 'L. 2 -> 14');
ok($claim($livrare, $lg), 200, 'L. Livrare receives');
ok($complete($livrare, $lg), 200, 'L. Livrare hands over');
check($production($l1['id'])['delivery'] === 'HANDED_OVER' && $receivables($l1['id']) === 1, 'L. handed over; still exactly one receivable');

// ---- 11. Migration 050: real 049 -> 050 upgrade over the rows written above, then a re-run -------------------------
require __DIR__ . '/HandoffSchemaFixture.php';
$tables = ['b2b_orders' => 'order_uuid', 'b2b_production_handoffs' => 'b2b_order_uuid', 'operational_orders' => 'order_uuid', 'production_route_decisions' => 'decision_uuid', 'order_activity_events' => 'event_id', 'b2b_account_movements' => 'movement_uuid', 'order_qr_references' => 'qr_reference'];
restorePreB2BOrderTypesTestSchema($pdo);
check($rows("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name IN ('order_type', 'service_route', 'finance_decision')") === [], '11. pre-050 schema');
$before = [];
foreach ($tables as $table => $key) $before[$table] = $rows("SELECT * FROM {$table} ORDER BY {$key}");
check(count($before['b2b_orders']) >= 8 && count($before['operational_orders']) >= 7, '11. the 049 fixture holds orders');
check((new MigrationRunner($pdo))->migrate($dir) === ['050_b2b_order_types.sql'], '11. 049 -> 050 applies exactly 050');
check((new MigrationRunner($pdo))->migrate($dir) === [], '11. recorded once');
(new SqlFileRunner($pdo))->run($dir . '/050_b2b_order_types.sql');
foreach ($tables as $table => $key) {
    $after = array_map(static function (array $r): array { foreach (['order_type', 'finance_decision', 'service_route'] as $column) { if (array_key_exists($column, $r)) { check($r[$column] === null, "11. existing rows get no {$column}"); unset($r[$column]); } } return $r; }, $rows("SELECT * FROM {$table} ORDER BY {$key}"));
    check($after === $before[$table], "11. every existing {$table} row preserved");
}
$constraints = array_column($rows("SELECT constraint_name AS name FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_type = 'CHECK'"), 'name');
foreach (['chk_b2b_orders_order_type', 'chk_b2b_orders_finance_decision', 'chk_b2b_production_handoffs_order_type', 'chk_operational_orders_service_route', 'chk_production_route_decisions_reason_050'] as $name) check(in_array($name, $constraints, true), "11. {$name}");
check(!in_array('chk_production_route_decisions_reason', $constraints, true), '11. the 029 reason CHECK is replaced');
check((int) $scalar('SELECT @@foreign_key_checks') === 1, '11. foreign keys stay enforced');

$server->exec("DROP DATABASE IF EXISTS `{$db}`");
echo "PASS MySQL B2B order types: {$checks} checks (three types, frozen type, 1 -> 2 -> 14 for CUT_ONLY and FULL_ROLL_BAG by an authorized stage 2 employee, Livrare receive and handover, stages 3-13 not applicable, cutting credit only for cutting, replay and double click, stop hold, historical full route, source isolation, finance off/shadow/enforce, 049 -> 050 upgrade).\n";
