<?php

declare(strict_types=1);

// B2B order types (migration 050, API 2.67.0): FULL_TAILORING keeps the canonical route; CUT_ONLY and FULL_ROLL_BAG are
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

// ---- M: gate configuration, journal, release check, CEO exception, concurrency -----------------------------------------
$journal = static fn (string $order): array => $rows('SELECT phase, mode, outcome, reasons_json, snapshot_json FROM b2b_finance_gate_decisions WHERE order_uuid = ? ORDER BY decided_at, decision_uuid', [$order]);
$kinds = static fn (string $order): array => array_map(static fn (array $r): string => "{$r['phase']}/{$r['mode']}/{$r['outcome']}", $journal($order));
$kernelWith = static fn (array $over): \Arasya\Operations\Http\ApiKernel => (new Container(new Config(...array_replace($args, ['financeRiskLimitAuthorityUuids' => $owner['id']], $over)), $pdo))->kernel();
$read = static fn (string $order, ?\Arasya\Operations\Http\ApiKernel $k = null): array => ok($call($root, 'GET', "/b2b/orders/{$order}", null, [], null, $k), 200, 'order read')['order'];
$handoffs = static fn (string $order): array => $rows('SELECT finance_decision FROM b2b_production_handoffs WHERE b2b_order_uuid = ?', [$order]);
$submitWith = static fn (array $o, \Arasya\Operations\Http\ApiKernel $k, ?string $key = null): array => $call($root, 'POST', "/b2b/orders/{$o['id']}/production", ['expectedVersion' => $o['version']], [], $key, $k);
$reconcileZero = static function (string $company) use ($call, $acc1, $acc2): void {
    $o = ok($call($acc1, 'POST', '/finance/accounts', ['companyId' => $company]), 201, 'open account')['onboardingId'];
    $v = ok($call($acc1, 'POST', "/finance/accounts/{$o}/draft", ['expectedVersion' => 1, 'balanceKind' => 'ZERO', 'amount' => '0', 'asOfDate' => '2026-11-01', 'note' => 'Synthetic zero',
        'evidence' => [['kind' => 'correspondence', 'sourceSystem' => 'MANUAL', 'reference' => 'Synthetic balance confirmation release']]]), 200, 'zero draft')['version'];
    $v = ok($call($acc1, 'POST', "/finance/accounts/{$o}/submit", ['expectedVersion' => $v]), 200, 'zero submit')['version'];
    check(ok($call($acc2, 'POST', "/finance/accounts/{$o}/verify", ['expectedVersion' => $v]), 200, 'zero verify')['onboardingStatus'] === 'RECONCILED', 'reconciled account');
};
$ownerColor = static fn (string $company, string $class): array => ok($call($owner, 'POST', "/finance/owner/companies/{$company}/risk", ['expectedState' => $state($company), 'classification' => $class, 'effect' => 'EFFECTIVE', 'reason' => "Synthetic owner {$class}"], [], null, $enforce), 201, "owner {$class}");
$ownerLimit = static fn (string $company, array $body): array => ok($call($owner, 'POST', "/finance/owner/companies/{$company}/credit", ['expectedState' => $state($company), 'effect' => 'EFFECTIVE', 'reason' => 'Synthetic owner limit decision'] + $body, [], null, $enforce), $body['action'] === 'SET' ? 201 : 200, "owner limit {$body['action']}");

// M1. Journal: every evaluated decision is recorded, a refusal too although its transaction rolled back; off writes nothing.
check($kinds($k['id']) === ['finalization/enforce/BLOCKED'] && in_array('IDENTITY_NOT_VERIFIED', json_decode($journal($k['id'])[0]['reasons_json'], true), true), 'M1. a refused finalization is journaled');
check($kinds($s['id']) === ['finalization/shadow/WOULD_BLOCK'] && $kinds($l1['id']) === ['finalization/enforce/ALLOWED'] && $kinds($cutOnly['id']) === [], 'M1. shadow and allowed decisions journaled; mode off writes nothing');
check(json_decode($handoffs($l1['id'])[0]['finance_decision'], true)['outcome'] === 'NOT_EVALUATED', 'M1. a release with the check off records NOT_EVALUATED on the handoff');
check($read($k['id'], $enforce)['financeGate'] === ['configured' => true, 'finalizationMode' => 'enforce', 'releaseMode' => 'enforce', 'preview' => ['phase' => 'finalization', 'mode' => 'enforce', 'outcome' => 'BLOCKED',
    'reasons' => json_decode($journal($k['id'])[0]['reasons_json'], true)]], 'M1. the draft previews its refusal with the reason codes');
check($read($k['id'])['financeGate'] === ['configured' => true, 'finalizationMode' => 'off', 'releaseMode' => 'off', 'preview' => ['phase' => 'finalization', 'mode' => 'off', 'outcome' => 'NOT_EVALUATED', 'reasons' => []]], 'M1. mode off previews nothing');
check(refused($pdo, "UPDATE b2b_finance_gate_decisions SET mode = 'off' WHERE order_uuid = ?", [$k['id']]) && refused($pdo, "UPDATE b2b_finance_gate_decisions SET outcome = 'WOULD_BLOCK' WHERE order_uuid = ?", [$k['id']]), 'M1. the journal refuses an impossible mode or outcome');

// M2. An unknown mode is a configuration failure on the gated actions only. It is never read as off.
foreach (['enforced', 'OFF', 'on', '1', ' enforce'] as $bad) {
    $broken = $kernelWith(['b2bFinanceGate' => $bad]);
    $d = $draft('CUT_ONLY', $eligible, null, $broken);
    err($finalize($d, $broken), 503, 'FINANCE_GATE_MISCONFIGURED', "M2. finalization with gate '{$bad}'");
    $after = $read($d['id'], $broken);
    check($after['status'] === 'draft' && $after['financeDecision'] === null && $receivables($d['id']) === 0 && $kinds($d['id']) === [], "M2. '{$bad}': nothing finalized, no receivable");
    check($after['financeGate'] === ['configured' => false, 'finalizationMode' => null, 'releaseMode' => null, 'preview' => ['phase' => null, 'mode' => null, 'outcome' => 'CONFIGURATION_INVALID', 'reasons' => []]], "M2. '{$bad}' is reported to the screen");
}
ok($call($cutter, 'GET', '/orders/' . $lg, null, [], null, $broken), 200, 'M2. the factory API keeps working with a broken gate');
ok($call($root, 'GET', '/b2b/orders', null, [], null, $broken), 200, 'M2. the B2B list keeps working with a broken gate');
$brokenRelease = $kernelWith(['b2bFinanceReleaseGate' => 'enforced']);
$br = ok($finalize($draft('CUT_ONLY', $blockedCompany, null, $brokenRelease), $brokenRelease), 200, 'M2. finalization is governed by its own (off) mode')['detail']['order'];
err($submitWith($br, $brokenRelease), 503, 'FINANCE_GATE_MISCONFIGURED', 'M2. submission with an unknown release mode');
check($handoffs($br['id']) === [] && $rows('SELECT 1 FROM operational_orders WHERE global_order_id = ?', ['b2b:' . $br['id']]) === [], 'M2. no handoff, no canonical order, no QR');

// M3. Release check: eligibility is decided again at production submission, under the company lock.
$second = $verifiedCompany('Synthetic Release SRL ' . $suffix, 7400000 + random_int(1, 99999));
$reconcileZero($second);
$ownerColor($second, 'GREEN');
$ownerLimit($second, ['action' => 'SET', 'amount' => '200.00', 'currency' => 'RON']);
$r1 = ok($finalize($draft('CUT_ONLY', $second, null, $enforce), $enforce), 200, 'M3. first order finalized')['detail']['order'];
$r2 = ok($finalize($draft('FULL_TAILORING', $second, null, $enforce), $enforce), 200, 'M3. second order finalized')['detail']['order'];
check($r1['financeDecision']['outcome'] === 'ALLOWED' && $r1['financeDecision']['phase'] === 'finalization' && $r2['financeDecision']['profile']['availableCredit'] === '122.65', 'M3. both within the 200.00 limit');
check($read($r1['id'], $enforce)['financeGate']['preview'] === ['phase' => 'production_submission', 'mode' => 'enforce', 'outcome' => 'ALLOWED', 'reasons' => []], 'M3. a finalized order previews its release check');
$ownerColor($second, 'RED');
$stopKey = 'types-release-' . bin2hex(random_bytes(8));
$stop = err($submitWith($r1, $enforce, $stopKey), 409, 'FINANCE_RELEASE_BLOCKED', 'M3. RED after finalization stops the release');
check($stop['error']['details'] === ['reasons' => ['RISK_RED'], 'phase' => 'production_submission'], 'M3. the refusal names RISK_RED and its phase, no amount');
err($submitWith($r1, $enforce, $stopKey), 409, 'FINANCE_RELEASE_BLOCKED', 'M3. the same request again is refused again (nothing was stored)');
$afterStop = $read($r1['id'], $enforce);
check($afterStop['status'] === 'finalized' && $afterStop['productionSubmitted'] === false && $afterStop['financeReleaseDecision'] === null && $receivables($r1['id']) === 1
    && $handoffs($r1['id']) === [] && $rows('SELECT 1 FROM operational_orders WHERE global_order_id = ?', ['b2b:' . $r1['id']]) === [], 'M3. the order stays finalized: no handoff, no canonical order, no QR, receivable untouched');
check($kinds($r1['id']) === ['finalization/enforce/ALLOWED', 'production_submission/enforce/BLOCKED', 'production_submission/enforce/BLOCKED'] && $afterStop['financeGate']['preview']['outcome'] === 'BLOCKED', 'M3. each refused release is journaled');
$ownerColor($second, 'GREEN');
ok($submitWith($r1, $enforce), 201, 'M3. released after the owner restored GREEN');
$released = $read($r1['id'], $enforce);
check($released['productionSubmitted'] === true && $released['financeReleaseDecision']['outcome'] === 'ALLOWED' && $released['financeReleaseDecision']['phase'] === 'production_submission'
    && $released['financeReleaseDecision']['grandfathered'] === false && $released['financeReleaseDecision']['changedSinceFinalization'] === [] && $released['financeReleaseDecision']['finalization']['outcome'] === 'ALLOWED'
    && $released['financeGate']['preview'] === null, 'M3. the release decision is stored with the handoff');
// The exposure already holds this order's receivable: a lowered limit stops the release, an exactly filled limit does not.
$ownerLimit($second, ['action' => 'SET', 'amount' => '100.00', 'currency' => 'RON']);
$low = err($submitWith($r2, $enforce), 409, 'FINANCE_RELEASE_BLOCKED', 'M3. limit lowered under the exposure');
check($low['error']['details']['reasons'] === ['CREDIT_LIMIT_EXCEEDED'], 'M3. CREDIT_LIMIT_EXCEEDED at release');
$ownerLimit($second, ['action' => 'SET', 'amount' => '154.70', 'currency' => 'RON']);
ok($submitWith($r2, $enforce), 201, 'M3. an exactly filled limit still releases the order');
$exact = $read($r2['id'], $enforce)['financeReleaseDecision'];
check($exact['outcome'] === 'ALLOWED' && $exact['changedSinceFinalization'] === ['approvedCreditLimit'] && $exact['profile']['availableCredit'] === '0.00', 'M3. the changed limit is reported with the decision');
$full = err($finalize($draft('CUT_ONLY', $second, null, $enforce), $enforce), 409, 'FINANCE_NOT_ELIGIBLE', 'M3. no credit left for a further order');
check(in_array('CREDIT_LIMIT_EXCEEDED', $full['error']['details']['reasons'], true) && $full['error']['details']['phase'] === 'finalization', 'M3. the next order is refused at finalization');
// A revoked limit; the release mode may be reviewed separately from the finalization mode.
$ownerLimit($second, ['action' => 'SET', 'amount' => '400.00', 'currency' => 'RON']);
$r4 = ok($finalize($draft('FULL_ROLL_BAG', $second, null, $enforce), $enforce), 200, 'M3. fourth order finalized')['detail']['order'];
$ownerLimit($second, ['action' => 'REVOKE']);
$revoked = err($submitWith($r4, $enforce), 409, 'FINANCE_RELEASE_BLOCKED', 'M3. revoked limit stops the release');
check(in_array('NO_APPROVED_LIMIT', $revoked['error']['details']['reasons'], true), 'M3. NO_APPROVED_LIMIT at release');
$releaseShadow = $kernelWith(['b2bFinanceGate' => 'enforce', 'b2bFinanceReleaseGate' => 'shadow']);
check($read($r4['id'], $releaseShadow)['financeGate']['releaseMode'] === 'shadow', 'M3. the release mode is its own reviewed value');
ok($submitWith($r4, $releaseShadow), 201, 'M3. release mode shadow records and lets the order through');
check($read($r4['id'], $releaseShadow)['financeReleaseDecision']['outcome'] === 'WOULD_BLOCK' && array_slice($kinds($r4['id']), -1) === ['production_submission/shadow/WOULD_BLOCK'], 'M3. WOULD_BLOCK stored and journaled');
// An order already in production is never re-evaluated and never stopped by Finance.
$g1 = (string) $scalar('SELECT o.global_order_id FROM b2b_production_handoffs h JOIN operational_orders o ON o.order_uuid = h.operational_order_uuid WHERE h.b2b_order_uuid = ?', [$r1['id']]);
$before = count($journal($r1['id']));
ok($submitWith($r1, $enforce), 200, 'M3. a repeated submission of a released order answers 200');
check(count($journal($r1['id'])) === $before && count($handoffs($r1['id'])) === 1, 'M3. no second handoff and no new decision');
$toCutting($g1);
ok($claim($cutter, $g1), 200, 'M3. stage 2 claim although the customer has no limit now');
check(ok($complete($cutter, $g1), 200, 'M3. stage 2 done')['productionStageId'] === 'delivery', 'M3. production goes on: Finance never stops a released order');
// Grandfathered: finalized without an evaluated decision (gate off). At release it is evaluated like any other order.
$gf = ok($finalize($draft('CUT_ONLY', $blockedCompany)), 200, 'M3. finalized while the gate was off')['detail']['order'];
check($gf['financeDecision']['outcome'] === 'NOT_EVALUATED', 'M3. no decision at finalization');
$gfStop = err($submitWith($gf, $enforce), 409, 'FINANCE_RELEASE_BLOCKED', 'M3. a grandfathered order of an unverified customer is not released under enforce');
check(in_array('IDENTITY_NOT_VERIFIED', $gfStop['error']['details']['reasons'], true) && json_decode(array_slice($journal($gf['id']), -1)[0]['snapshot_json'], true)['grandfathered'] === true, 'M3. journaled as grandfathered');
$releaseOff = $kernelWith(['b2bFinanceGate' => 'enforce', 'b2bFinanceReleaseGate' => 'off']);
ok($submitWith($gf, $releaseOff), 201, 'M3. with the release check reviewed off it is released');
check($read($gf['id'], $releaseOff)['financeReleaseDecision']['outcome'] === 'NOT_EVALUATED' && $read($gf['id'], $releaseOff)['financeReleaseDecision']['grandfathered'] === true, 'M3. recorded as not evaluated');

// M4. CEO exception: order-bound, approved by the configured CEO only, covers credit reasons of a RED company.
$ceo = $financePerson('ceo', ['finance.credit.view']);
$analyst = $financePerson('analyst', ['finance.credit.view', 'finance.credit.propose']);
$withCeo = $kernelWith(['b2bFinanceGate' => 'enforce', 'financeCeoEmployeeUuid' => $ceo['id']]);
$ownerColor($second, 'RED');
$expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + 5 * 86400);
$exception = static function (array $o, string $amount) use ($call, $analyst, $ceo, $second, $expiresAt, $withCeo): string {
    $x = ok($call($analyst, 'POST', '/finance/credit/exceptions', ['companyId' => $second, 'orderId' => $o['id'], 'amount' => $amount, 'currency' => 'RON', 'expiresAt' => $expiresAt, 'reason' => 'Synthetic strategic order'], [], null, $withCeo), 201, 'exception requested');
    ok($call($ceo, 'POST', "/finance/credit/exceptions/{$x['exceptionId']}/approve", ['expectedVersion' => 1, 'snapshotSha256' => $x['snapshotSha256'], 'reason' => 'Approved for this order only'], [], null, $withCeo), 200, 'CEO approves');
    return $x['exceptionId'];
};
$x1 = $draft('CUT_ONLY', $second, null, $withCeo);
$red = err($finalize($x1, $withCeo), 409, 'FINANCE_NOT_ELIGIBLE', 'M4. RED without an exception');
check(in_array('RISK_RED', $red['error']['details']['reasons'], true), 'M4. RISK_RED');
$exception($x1, '50.00');
err($finalize($x1, $withCeo), 409, 'FINANCE_NOT_ELIGIBLE', 'M4. an exception smaller than the order does not cover it');
$x2 = $draft('CUT_ONLY', $second, null, $withCeo);
$x2Exception = $exception($x2, '100.00');
$pdo->prepare('UPDATE finance_credit_exceptions SET expires_at = ? WHERE exception_uuid = ?')->execute(['2026-01-01 00:00:00.000000', $x2Exception]);
err($finalize($x2, $withCeo), 409, 'FINANCE_NOT_ELIGIBLE', 'M4. an expired exception does not cover the order');
$x3 = $draft('CUT_ONLY', $second, null, $withCeo);
$x3Exception = $exception($x3, '77.35');
err($finalize($x1, $withCeo), 409, 'FINANCE_NOT_ELIGIBLE', 'M4. an exception of another order is never used');
$x3Done = ok($finalize($x3, $withCeo), 200, 'M4. finalized under the approved exception')['detail']['order'];
check($x3Done['financeDecision']['outcome'] === 'ALLOWED_BY_CEO_EXCEPTION' && $x3Done['financeDecision']['exception']['id'] === $x3Exception && $x3Done['financeDecision']['exception']['amount'] === '77.35'
    && in_array('RISK_RED', $x3Done['financeDecision']['reasons'], true) && $receivables($x3['id']) === 1 && array_slice($kinds($x3['id']), -1) === ['finalization/enforce/ALLOWED_BY_CEO_EXCEPTION'], 'M4. the exception and the covered reasons are stored; one receivable');
ok($submitWith($x3Done, $withCeo), 201, 'M4. released under the same exception');
check($read($x3['id'], $withCeo)['financeReleaseDecision']['outcome'] === 'ALLOWED_BY_CEO_EXCEPTION', 'M4. release decision names the exception');
check((int) $scalar("SELECT COUNT(*) FROM b2b_account_movements WHERE company_uuid = ? AND movement_type <> 'order_receivable'", [$second]) === 0
    && $rows("SELECT 1 FROM order_activity_events e JOIN operational_orders o ON o.order_uuid = e.order_uuid WHERE o.global_order_id = ? AND e.action NOT IN ('production_submitted')", ['b2b:' . $x3['id']]) === [], 'M4. a finance decision posts no other movement and no stage event');

// M5. Concurrency: real simultaneous requests on separate connections.
$gateRace = static function (array $jobs) use ($db, $owner): array {
    $start = (string) (microtime(true) + 0.4);
    $workers = [];
    foreach ($jobs as [$who, $method, $path, $body, $key]) {
        $proc = proc_open([PHP_BINARY, __DIR__ . '/fixtures/b2b-finance-gate-worker.php', $db, $method, $path, json_encode($body), $key, $who['cookie'], $who['csrf'], $start, $owner['id'], 'enforce'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('Race worker failed to start.');
        fclose($pipes[0]);
        $workers[] = [$proc, $pipes];
    }
    $results = [];
    foreach ($workers as [$proc, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($proc) === 0, 'race process ' . $error);
        $results[] = json_decode($out, true, 32, JSON_THROW_ON_ERROR);
    }
    return $results;
};
$third = $verifiedCompany('Synthetic Race SRL ' . $suffix, 7500000 + random_int(1, 99999));
$reconcileZero($third);
$ownerColor($third, 'GREEN');
$ownerLimit($third, ['action' => 'SET', 'amount' => '100.00', 'currency' => 'RON']);
$companyReceivables = static fn (string $company): int => (int) $scalar("SELECT COUNT(*) FROM b2b_account_movements WHERE company_uuid = ? AND movement_type = 'order_receivable'", [$company]);
$c1 = $draft('CUT_ONLY', $third, null, $enforce);
$c2 = $draft('CUT_ONLY', $third, null, $enforce);
$race = $gateRace([[$root, 'POST', "/b2b/orders/{$c1['id']}/finalize", ['expectedVersion' => $c1['version']], 'types-race-a-' . $suffix], [$root, 'POST', "/b2b/orders/{$c2['id']}/finalize", ['expectedVersion' => $c2['version']], 'types-race-b-' . $suffix]]);
$statuses = array_column($race, 'status');
sort($statuses);
$loser = $race[0]['status'] === 409 ? $race[0] : $race[1];
check($statuses === [200, 409] && $loser['error'] === 'FINANCE_NOT_ELIGIBLE' && in_array('CREDIT_LIMIT_EXCEEDED', $loser['reasons'], true) && $companyReceivables($third) === 1, 'M5. two simultaneous finalizations cannot both use the same credit');
$outcomes = array_merge($kinds($c1['id']), $kinds($c2['id']));
sort($outcomes);
check($outcomes === ['finalization/enforce/ALLOWED', 'finalization/enforce/BLOCKED'], 'M5. one ALLOWED and one BLOCKED decision');
// Double click: the same finalization twice at once is one finalization, one receivable, one decision.
$ownerLimit($third, ['action' => 'SET', 'amount' => '300.00', 'currency' => 'RON']);
$c3 = $draft('CUT_ONLY', $third, null, $enforce);
$double = $gateRace(array_fill(0, 2, [$root, 'POST', "/b2b/orders/{$c3['id']}/finalize", ['expectedVersion' => $c3['version']], 'types-double-' . $suffix]));
check(array_column($double, 'status') === [200, 200] && $receivables($c3['id']) === 1 && $kinds($c3['id']) === ['finalization/enforce/ALLOWED'], 'M5. a double click finalizes once');
// A limit decision racing a finalization: the order is decided on one consistent basis, whichever commits first.
$c4 = $draft('CUT_ONLY', $third, null, $enforce);
$gateRace([[$root, 'POST', "/b2b/orders/{$c4['id']}/finalize", ['expectedVersion' => $c4['version']], 'types-vs-limit-' . $suffix],
    [$owner, 'POST', "/finance/owner/companies/{$third}/credit", ['expectedState' => $state($third), 'action' => 'SET', 'amount' => '160.00', 'currency' => 'RON', 'effect' => 'EFFECTIVE', 'reason' => 'Synthetic racing limit'], 'types-limit-race-' . $suffix]]);
$c4After = $read($c4['id'], $enforce);
check($c4After['status'] === 'finalized'
    ? ($c4After['financeDecision']['outcome'] === 'ALLOWED' && $c4After['financeDecision']['profile']['approvedCreditLimit'] === '300.00' && $c4After['financeDecision']['profile']['availableCredit'] === '145.30' && $receivables($c4['id']) === 1)
    : ($c4After['status'] === 'draft' && $receivables($c4['id']) === 0 && $kinds($c4['id']) === ['finalization/enforce/BLOCKED']), 'M5. finalized on the old limit or refused on the new one, never a mix');
// Two simultaneous releases of one order: one handoff, one canonical order.
$ownerLimit($third, ['action' => 'SET', 'amount' => '900.00', 'currency' => 'RON']);
$c5 = ok($finalize($draft('CUT_ONLY', $third, null, $enforce), $enforce), 200, 'M5. order for the release race')['detail']['order'];
$releases = $gateRace([[$root, 'POST', "/b2b/orders/{$c5['id']}/production", ['expectedVersion' => $c5['version']], 'types-rel-a-' . $suffix], [$root, 'POST', "/b2b/orders/{$c5['id']}/production", ['expectedVersion' => $c5['version']], 'types-rel-b-' . $suffix]]);
$relStatuses = array_column($releases, 'status');
sort($relStatuses);
check($relStatuses === [200, 201] && count($handoffs($c5['id'])) === 1 && (int) $scalar('SELECT COUNT(*) FROM operational_orders WHERE global_order_id = ?', ['b2b:' . $c5['id']]) === 1
    && count(array_filter($kinds($c5['id']), static fn (string $kind): bool => str_starts_with($kind, 'production_submission/'))) === 1, 'M5. two simultaneous releases: one handoff, one canonical order, one release decision');

// The decision is taken under the company row lock. While another transaction holds that lock (as a Finance owner
// decision does), the action waits on it: it reads no finance basis and writes nothing until the lock is released.
$whileCompanyLocked = static function (string $company, string $path, array $body, string $key, callable $held) use ($config, $db, $owner, $root, $pdo): array {
    $locker = Connection::create($config);
    $locker->beginTransaction();
    $locker->prepare('SELECT company_uuid FROM b2b_companies WHERE company_uuid = ? FOR UPDATE')->execute([$company]);
    $proc = proc_open([PHP_BINARY, __DIR__ . '/fixtures/b2b-finance-gate-worker.php', $db, 'POST', $path, json_encode($body), $key, $root['cookie'], $root['csrf'], (string) microtime(true), $owner['id'], 'enforce'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('Lock worker failed to start.');
    fclose($pipes[0]);
    $waiting = false;
    for ($i = 0; $i < 100 && !$waiting; $i++) {
        usleep(100_000);
        foreach ($pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $thread) {
            $thread = array_change_key_case($thread);
            if (($thread['db'] ?? null) === $db && preg_match('/b2b_companies\s+WHERE\s+company_uuid\s*=.*FOR UPDATE/i', (string) ($thread['info'] ?? '')) === 1) $waiting = true;
        }
    }
    try {
        check($waiting, 'M5. the action waits on the company row lock');
        $held();
    } finally {
        $locker->commit();
        $out = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
    }
    check($exit === 0, 'lock worker ' . $error);
    return json_decode($out, true, 32, JSON_THROW_ON_ERROR);
};
$c6 = $draft('CUT_ONLY', $third, null, $enforce);
$c6Result = $whileCompanyLocked($third, "/b2b/orders/{$c6['id']}/finalize", ['expectedVersion' => $c6['version']], 'types-locked-finalize-' . $suffix, static function () use ($scalar, $c6, $kinds, $receivables): void {
    check($scalar('SELECT status FROM b2b_orders WHERE order_uuid = ?', [$c6['id']]) === 'draft' && $kinds($c6['id']) === [] && $receivables($c6['id']) === 0, 'M5. nothing is decided or posted while the company is locked');
});
check($c6Result['status'] === 200 && $kinds($c6['id']) === ['finalization/enforce/ALLOWED'] && $receivables($c6['id']) === 1, 'M5. the finalization is decided once the lock is released');
$c6Done = $read($c6['id'], $enforce);
$c6Release = $whileCompanyLocked($third, "/b2b/orders/{$c6['id']}/production", ['expectedVersion' => $c6Done['version']], 'types-locked-release-' . $suffix, static function () use ($handoffs, $c6, $kinds): void {
    check($handoffs($c6['id']) === [] && $kinds($c6['id']) === ['finalization/enforce/ALLOWED'], 'M5. no release decision and no handoff while the company is locked');
});
check($c6Release['status'] === 201 && count($handoffs($c6['id'])) === 1 && array_slice($kinds($c6['id']), -1) === ['production_submission/enforce/ALLOWED'], 'M5. the release is decided once the lock is released');

// M6. Finance reads the journal for the shadow analysis (D2): credit viewers only, reason codes only, newest first.
err($call($reviewer, 'GET', '/finance/b2b/gate-decisions'), 403, 'UNAUTHORIZED_ACTION', 'M6. the journal needs finance.credit.view');
$all = (int) $pdo->query('SELECT COUNT(*) FROM b2b_finance_gate_decisions')->fetchColumn();
$journalRead = ok($call($owner, 'GET', '/finance/b2b/gate-decisions'), 200, 'M6. journal read');
check(count($journalRead['items']) === min($all, 100) && array_sum(array_column($journalRead['summary'], 'count')) === $all, 'M6. items and summary cover the journal');
$times = array_column($journalRead['items'], 'at');
$sorted = $times;
rsort($sorted);
check($times === $sorted, 'M6. newest first');
$leak = false;
array_walk_recursive($journalRead['items'], static function ($value, $key) use (&$leak): void {
    $leak = $leak || in_array($key, ['approvedCreditLimit', 'currentExposure', 'availableCredit', 'orderGross', 'amount', 'profile'], true);
});
check(!$leak && !array_key_exists('profile', $journalRead['items'][0]), 'M6. no limit, exposure or amount in the journal read');
$blockedRead = ok($call($owner, 'GET', '/finance/b2b/gate-decisions', null, ['outcome' => 'BLOCKED', 'phase' => 'finalization']), 200, 'M6. filtered read');
check($blockedRead['items'] !== [] && array_unique(array_map(static fn (array $i): string => $i['phase'] . '/' . $i['outcome'], $blockedRead['items'])) === ['finalization/BLOCKED'], 'M6. outcome and phase filters');
check(array_column($blockedRead['summary'], 'count') === [(int) $pdo->query("SELECT COUNT(*) FROM b2b_finance_gate_decisions WHERE outcome = 'BLOCKED' AND phase = 'finalization'")->fetchColumn()], 'M6. the summary follows the filters');
$kRow = array_values(array_filter($blockedRead['items'], static fn (array $i): bool => $i['orderId'] === $k['id']))[0] ?? null;
check($kRow !== null && in_array('IDENTITY_NOT_VERIFIED', $kRow['reasons'], true) && $kRow['mode'] === 'enforce' && is_string($kRow['orderCode']), 'M6. a refusal reads with its reason codes');
err($call($owner, 'GET', '/finance/b2b/gate-decisions', null, ['outcome' => 'NOT_EVALUATED']), 422, 'VALIDATION_FAILED', 'M6. an unknown outcome filter is refused');
err($call($owner, 'GET', '/finance/b2b/gate-decisions', null, ['cursor' => 'bad']), 400, 'INVALID_CURSOR', 'M6. a malformed cursor is refused');
err($call($owner, 'GET', '/finance/b2b/gate-decisions', null, ['companyId' => $eligible]), 400, 'INVALID_REQUEST', 'M6. an unknown filter is refused');

// ---- N. Rollback preflight: an older API ignores the order type, so open short-route work needs 2.67.0 -----------------
$cliEnvironment = ['HOME' => (string) getenv('HOME'), 'ARASYA_APP_ENV' => 'test', 'ARASYA_APP_SECRET' => str_repeat('t', 32), 'ARASYA_ALLOWED_ORIGINS' => T::ORIGIN,
    'ARASYA_DB_HOST' => (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1'), 'ARASYA_DB_PORT' => (string) (getenv('ARASYA_TEST_DB_PORT') ?: '3306'),
    'ARASYA_DB_NAME' => $db, 'ARASYA_DB_USER' => (string) getenv('ARASYA_TEST_DB_USER'), 'ARASYA_DB_PASSWORD' => (string) getenv('ARASYA_TEST_DB_PASSWORD'),
    'ARASYA_SOURCE_SECRET_TRENDHOME' => T::TRENDHOME_SECRET, 'ARASYA_SOURCE_MODE_TRENDHOME' => 'active'];
$rollback = static function (string $target) use ($cliEnvironment): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/check-rollback.php', $target], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $cliEnvironment);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'json' => json_decode($out, true)];
};
$shortOpen = (int) $scalar("SELECT (SELECT COUNT(*) FROM operational_orders WHERE service_route IN ('CUT_ONLY', 'FULL_ROLL_BAG') AND production_completed_at IS NULL AND production_stage_id <> 'delivery')
    + (SELECT COUNT(*) FROM b2b_orders c WHERE c.status = 'finalized' AND c.order_type IN ('CUT_ONLY', 'FULL_ROLL_BAG') AND NOT EXISTS (SELECT 1 FROM b2b_production_handoffs h WHERE h.b2b_order_uuid = c.order_uuid))");
$previous = $rollback('2.66.0');
check($shortOpen > 0 && $previous['code'] === 3 && $previous['json']['safe'] === false && $previous['json']['requiredFloor'] === '2.67.0' && $previous['json']['state']['openShortRouteB2bOrders'] === $shortOpen, 'N. open short-route orders refuse a rollback to 2.66.0');
check($rollback('2.67.0')['code'] === 0, 'N. the same release is always a safe target');
// Finish or cancel the open short-route work: a short-route order at Livrare, a completed one and a full-tailoring one add no floor.
$pdo->exec("UPDATE operational_orders SET production_stage_id = 'delivery' WHERE service_route IN ('CUT_ONLY', 'FULL_ROLL_BAG') AND production_completed_at IS NULL");
$pdo->exec("UPDATE b2b_orders c SET c.order_type = 'FULL_TAILORING' WHERE c.status = 'finalized' AND c.order_type IN ('CUT_ONLY', 'FULL_ROLL_BAG') AND NOT EXISTS (SELECT 1 FROM b2b_production_handoffs h WHERE h.b2b_order_uuid = c.order_uuid)");
$cleared = $rollback('2.66.0');
check($cleared['json']['state']['openShortRouteB2bOrders'] === 0 && $cleared['json']['requiredFloor'] !== '2.67.0', 'N. without open short-route work, 2.67.0 is no longer the floor');

// ---- 11. Migration 050: real 049 -> 050 upgrade over the rows written above, then a re-run -------------------------
require __DIR__ . '/HandoffSchemaFixture.php';
$tables = ['b2b_orders' => 'order_uuid', 'b2b_production_handoffs' => 'b2b_order_uuid', 'operational_orders' => 'order_uuid', 'production_route_decisions' => 'decision_uuid', 'order_activity_events' => 'event_id', 'b2b_account_movements' => 'movement_uuid', 'order_qr_references' => 'qr_reference'];
restorePreB2BOrderTypesTestSchema($pdo);
check($rows("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name IN ('order_type', 'service_route', 'finance_decision')") === []
    && $rows("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'b2b_finance_gate_decisions'") === [], '11. pre-050 schema');
// The live shape: 051 (Trendyol AWB station) is already applied while 050 is pending. The runner applies by name, not by position.
$awbRecord = $rows("SELECT checksum, applied_at FROM schema_migrations WHERE migration_name = '051_trendyol_awb_station.sql'");
check(count($awbRecord) === 1 && $rows("SELECT 1 FROM schema_migrations WHERE migration_name = '050_b2b_order_types.sql'") === [], '11. 051 applied, 050 pending');
$awbSchema = $rows("SELECT table_name AS t, column_name AS c, column_type AS ty FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'trendyol_awb_print_events' ORDER BY ordinal_position");
$awbGrants = $rows("SELECT r.role_key, p.permission_key FROM role_permissions rp JOIN roles r ON r.role_id = rp.role_id JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.permission_key LIKE 'trendyol.awb.%' ORDER BY 1, 2");
$before = [];
foreach ($tables as $table => $key) $before[$table] = $rows("SELECT * FROM {$table} ORDER BY {$key}");
check(count($before['b2b_orders']) >= 8 && count($before['operational_orders']) >= 7, '11. the 049 fixture holds orders');
check((new MigrationRunner($pdo))->migrate($dir) === ['050_b2b_order_types.sql'], '11. 049 -> 050 applies exactly 050');
check((new MigrationRunner($pdo))->migrate($dir) === [], '11. recorded once');
(new SqlFileRunner($pdo))->run($dir . '/050_b2b_order_types.sql');
check($rows("SELECT checksum, applied_at FROM schema_migrations WHERE migration_name = '051_trendyol_awb_station.sql'") === $awbRecord, '11. 050 after 051 leaves the 051 record untouched');
check($rows("SELECT table_name AS t, column_name AS c, column_type AS ty FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'trendyol_awb_print_events' ORDER BY ordinal_position") === $awbSchema
    && $rows("SELECT r.role_key, p.permission_key FROM role_permissions rp JOIN roles r ON r.role_id = rp.role_id JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.permission_key LIKE 'trendyol.awb.%' ORDER BY 1, 2") === $awbGrants, '11. 050 changes nothing of the AWB station');
check((int) $scalar('SELECT COUNT(*) FROM b2b_finance_gate_decisions') === 0, '11. the decision journal starts empty');
foreach ($tables as $table => $key) {
    $after = array_map(static function (array $r): array { foreach (['order_type', 'finance_decision', 'service_route'] as $column) { if (array_key_exists($column, $r)) { check($r[$column] === null, "11. existing rows get no {$column}"); unset($r[$column]); } } return $r; }, $rows("SELECT * FROM {$table} ORDER BY {$key}"));
    check($after === $before[$table], "11. every existing {$table} row preserved");
}
$constraints = array_column($rows("SELECT constraint_name AS name FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_type = 'CHECK'"), 'name');
foreach (['chk_b2b_orders_order_type', 'chk_b2b_orders_finance_decision', 'chk_b2b_production_handoffs_order_type', 'chk_b2b_production_handoffs_finance_decision', 'chk_operational_orders_service_route', 'chk_production_route_decisions_reason_050', 'chk_b2b_finance_gate_mode', 'chk_b2b_finance_gate_outcome', 'chk_b2b_finance_gate_mode_outcome'] as $name) check(in_array($name, $constraints, true), "11. {$name}");
check(!in_array('chk_production_route_decisions_reason', $constraints, true), '11. the 029 reason CHECK is replaced');
check((int) $scalar('SELECT @@foreign_key_checks') === 1, '11. foreign keys stay enforced');

$server->exec("DROP DATABASE IF EXISTS `{$db}`");
echo "PASS MySQL B2B order types: {$checks} checks (three types, frozen type, 1 -> 2 -> 14 for CUT_ONLY and FULL_ROLL_BAG by an authorized stage 2 employee, Livrare receive and handover, stages 3-13 not applicable, cutting credit only for cutting, replay and double click, stop hold, historical full route, source isolation, finance off/shadow/enforce, unknown mode refused, decision journal and its Finance read, release check at production submission, CEO exception, concurrent finalizations and releases, 050 applied after 051).\n";
