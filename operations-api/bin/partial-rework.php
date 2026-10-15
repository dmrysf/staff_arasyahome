<?php

declare(strict_types=1);

// Controlled activation of the per-piece capabilities, migrations 028, 029 and 031 (operator only; changes no order stage,
// permission or grant). Every flag and cohort change is audited in production_feature_flag_events.
//
//   php bin/partial-rework.php status
//   php bin/partial-rework.php enable --operator=<name> --confirm=ENABLE-PARTIAL-REWORK [--reason=<text>]
//   php bin/partial-rework.php disable --operator=<name> [--reason=<text>]
//   php bin/partial-rework.php routing-enable --operator=<name> --confirm=ENABLE-PIECE-ROUTING [--reason=<text>]
//   php bin/partial-rework.php routing-disable --operator=<name> [--reason=<text>]
//   php bin/partial-rework.php assisted-enable --operator=<name> --confirm=ENABLE-PERDEA-ASSISTED-CHECKPOINTS [--reason=<text>]
//   php bin/partial-rework.php assisted-disable --operator=<name> [--reason=<text>]
//   php bin/partial-rework.php cohort-add --feature=partial_rework|piece_routing|perdea_assisted_checkpoints --order=<global order id> --operator=<name> --reason=<text>
//   php bin/partial-rework.php cohort-remove --feature=partial_rework|piece_routing|perdea_assisted_checkpoints --order=<global order id> --operator=<name> --reason=<text>
//   php bin/partial-rework.php cohort-list --feature=partial_rework|piece_routing|perdea_assisted_checkpoints
//   php bin/partial-rework.php backfill --operator=<name> [--limit=<orders>] [--dry-run=1]
//   php bin/partial-rework.php rollback-check
//   php bin/partial-rework.php route-audit [--limit=<orders>] [--details=1]
//
// Enable globally only after API 2.32.0, Staff and Dashboard are all live, the database backup was restored once in a
// test and the pilot cohort was verified. A pilot cohort names orders by their global id; nothing is hardcoded.
// Disabling refuses new partial reports / new route splits and leaves every piece already on a track on its way.
// perdea_assisted_checkpoints (route policy version 2, API 2.34.0) needs Staff with the checkpoint screens; an order that
// already has a checkpoint keeps policy version 2 when the flag or its cohort membership is withdrawn.
//
// backfill writes nothing about the physical plan: piece counts are never derived from commercial quantities. With
// --dry-run=1 it only reports, per open order, the lines whose plan needs an authorized confirmation (with the source
// evidence) and the pieces that would be materialized for lines already confirmed. Without --dry-run it materializes
// the pieces of confirmed lines only (idempotent).
//
// rollback-check exits 3 when an API older than 2.32.0 must NOT be activated: active tracks (pieces away from their
// order), pieces that were replaced, or confirmed plans whose pieces differ from the commercial quantity exist, which an
// older API would silently ignore; or (API older than 2.34.0) an open order already has assisted PERDEA checkpoints. Exit 0 means no per-piece state would be lost by such a rollback.
//
// route-audit is read-only (RouteAudit): it classifies every open order for the per-piece model (unresolved exception,
// conflicting data, requires verified historical stage evidence, requires plan confirmation, safe identity
// materialization) and reports which earlier stages have an order-level completion a verifier could review. It works
// before and after migrations 028/029/030 and changes nothing. --details=1 adds the per-order rows.
//
// Exit codes: 0 done; 1 failed; 2 invalid invocation (nothing read or changed); 3 rollback unsafe. Messages never echo an
// argument value.

use Arasya\Operations\Production\FeatureActivation;
use Arasya\Operations\Production\ManufacturingPlans;
use Arasya\Operations\Production\WorkUnits;

$commands = [
    'status' => [[], []],
    'enable' => [['operator', 'confirm'], ['reason']],
    'disable' => [['operator'], ['reason']],
    'routing-enable' => [['operator', 'confirm'], ['reason']],
    'routing-disable' => [['operator'], ['reason']],
    'assisted-enable' => [['operator', 'confirm'], ['reason']],
    'assisted-disable' => [['operator'], ['reason']],
    'cohort-add' => [['feature', 'order', 'operator', 'reason'], []],
    'cohort-remove' => [['feature', 'order', 'operator', 'reason'], []],
    'cohort-list' => [['feature'], []],
    'backfill' => [['operator'], ['limit', 'dry-run']],
    'rollback-check' => [[], []],
    'route-audit' => [[], ['limit', 'details']],
];
$refuse = static function (string $message): never {
    fwrite(STDERR, "Refused: {$message}\nUsage: php bin/partial-rework.php status|enable|disable|routing-enable|routing-disable|assisted-enable|assisted-disable|cohort-add|cohort-remove|cohort-list|backfill|rollback-check|route-audit [--name=value ...]\n");
    exit(2);
};
$arguments = array_slice($argv, 1);
$command = $arguments === [] ? 'status' : (string) array_shift($arguments);
if (!array_key_exists($command, $commands)) {
    $refuse('unsupported command.');
}
[$required, $optional] = $commands[$command];
$options = [];
foreach ($arguments as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/sD', (string) $argument, $match) !== 1) {
        $refuse('unexpected argument; options are --name=value.');
    }
    if (!in_array($match[1], [...$required, ...$optional], true) || array_key_exists($match[1], $options)) {
        $refuse("option --{$match[1]} is not accepted here.");
    }
    $options[$match[1]] = $match[2];
}
foreach ($required as $name) {
    if (trim($options[$name] ?? '') === '') {
        $refuse("--{$name} is required.");
    }
}
if ($command === 'enable' && $options['confirm'] !== 'ENABLE-PARTIAL-REWORK') {
    $refuse('the confirmation phrase does not match.');
}
if ($command === 'routing-enable' && $options['confirm'] !== 'ENABLE-PIECE-ROUTING') {
    $refuse('the confirmation phrase does not match.');
}
if ($command === 'assisted-enable' && $options['confirm'] !== 'ENABLE-PERDEA-ASSISTED-CHECKPOINTS') {
    $refuse('the confirmation phrase does not match.');
}
if (isset($options['feature']) && !in_array($options['feature'], ['partial_rework', 'piece_routing', 'perdea_assisted_checkpoints'], true)) {
    $refuse('--feature must be partial_rework, piece_routing or perdea_assisted_checkpoints.');
}
if (isset($options['dry-run']) && !in_array($options['dry-run'], ['0', '1'], true)) {
    $refuse('--dry-run must be 0 or 1.');
}
if (isset($options['details']) && !in_array($options['details'], ['0', '1'], true)) {
    $refuse('--details must be 0 or 1.');
}
$limit = isset($options['limit']) ? (ctype_digit($options['limit']) && (int) $options['limit'] > 0 ? (int) $options['limit'] : $refuse('--limit must be a positive integer.')) : 500;
$operator = mb_substr(trim($options['operator'] ?? 'operator'), 0, 80);
$reason = mb_substr(trim($options['reason'] ?? ''), 0, 200);

$container = require __DIR__ . '/cli-bootstrap.php';
$pdo = $container->pdo();
$units = new WorkUnits($pdo);
$features = new FeatureActivation($pdo);
$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
$print = static fn (array $payload) => fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
try {
    if (in_array($command, ['enable', 'disable', 'routing-enable', 'routing-disable', 'assisted-enable', 'assisted-disable'], true)) {
        $feature = str_starts_with($command, 'routing') ? FeatureActivation::PIECE_ROUTING : (str_starts_with($command, 'assisted') ? FeatureActivation::PERDEA_ASSISTED_CHECKPOINTS : FeatureActivation::PARTIAL_REWORK);
        $on = in_array($command, ['enable', 'routing-enable', 'assisted-enable'], true);
        $result = $features->setEnabled($feature, $on, $operator, $reason !== '' ? $reason : ($on ? 'Enabled' : 'Disabled') . ' from the operator CLI', $now);
        $print(['feature' => $feature, 'enabled' => $result['enabled'], 'changed' => $result['changed']] + $units->inventory());
        exit(0);
    }
    if ($command === 'cohort-add' || $command === 'cohort-remove') {
        $order = $pdo->prepare('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?');
        $order->execute([$options['order']]);
        $orderUuid = $order->fetchColumn();
        if (!is_string($orderUuid)) {
            fwrite(STDERR, "Refused: the order does not exist.\n");
            exit(2);
        }
        $result = $features->setCohortMember($options['feature'], $orderUuid, $command === 'cohort-add', $operator, $reason, $now);
        $print(['feature' => $options['feature'], 'member' => $result['member'], 'changed' => $result['changed'], 'cohortSize' => count($features->cohort($options['feature']))]);
        exit(0);
    }
    if ($command === 'cohort-list') {
        $print(['feature' => $options['feature'], 'enabledGlobally' => $features->enabled($options['feature']), 'cohort' => $features->cohort($options['feature'])]);
        exit(0);
    }
    if ($command === 'backfill') {
        $dryRun = ($options['dry-run'] ?? '0') === '1';
        $orders = $pdo->query("SELECT o.order_uuid, o.global_order_id, o.production_stage_id FROM operational_orders o WHERE o.production_completed_at IS NULL AND o.operational_status <> 'unavailable' ORDER BY o.created_at LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
        $plans = new ManufacturingPlans($pdo);
        $report = ['orders' => 0, 'lines' => 0, 'linesNeedingConfirmation' => 0, 'linesWithStalePlan' => 0, 'confirmedLines' => 0, 'piecesToMaterialize' => 0, 'ordersMaterialized' => 0, 'samples' => []];
        foreach ($orders as $order) {
            $report['orders']++;
            $existing = $pdo->prepare("SELECT COUNT(*) FROM production_work_units WHERE order_uuid = ? AND status = 'active'");
            $existing->execute([$order['order_uuid']]);
            $have = (int) $existing->fetchColumn();
            $want = 0;
            foreach ($plans->lines((string) $order['order_uuid']) as $line) {
                $report['lines']++;
                if ($line['known']) {
                    $report['confirmedLines']++;
                    $want += (int) $line['plan']['pieceCount'];
                    continue;
                }
                $report[$line['stale'] ? 'linesWithStalePlan' : 'linesNeedingConfirmation']++;
                if (count($report['samples']) < 20) {
                    $report['samples'][] = ['order' => $order['global_order_id'], 'line' => $line['lineNumber'], 'stage' => $order['production_stage_id'], 'commercialQuantity' => $line['commercialQuantity'],
                        'productType' => $line['plan']['productType'], 'typeSource' => $line['plan']['typeSource'], 'evidence' => $line['proposal']['evidence'], 'stale' => $line['stale']];
                }
            }
            $report['piecesToMaterialize'] += max(0, $want - $have);
            if (!$dryRun && $want > $have) {
                // One short transaction per order, under the same order row lock every production mutation takes.
                $pdo->beginTransaction();
                $pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE')->execute([$order['order_uuid']]);
                $beforeUnits = array_column($units->carrierUnits((string) $order['order_uuid'], null), 'unit_uuid');
                $units->ensure((string) $order['order_uuid'], $now, 'backfill');
                $createdUnits = array_values(array_diff(array_column($units->carrierUnits((string) $order['order_uuid'], null), 'unit_uuid'), $beforeUnits));
                if ($createdUnits !== []) {
                    (new \Arasya\Operations\Iam\IamAuditLogger($pdo))->record(null, 'production.manifest.backfilled', 'order', (string) $order['global_order_id'], (string) $order['global_order_id'],
                        ['operator' => $operator, 'units' => $createdUnits, 'identitySource' => 'backfill', 'historicalCompletionsInferred' => false], 'manifest-backfill-' . bin2hex(random_bytes(12)), $now);
                }
                $pdo->commit();
                if ($createdUnits !== []) $report['ordersMaterialized']++;
            }
        }
        $print(['dryRun' => $dryRun, 'more' => count($orders) === $limit] + $report + $units->inventory());
        exit(0);
    }
    if ($command === 'route-audit') {
        // Read-only: a database-enforced read-only transaction, rolled back.
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        $audit = (new \Arasya\Operations\Production\RouteAudit($pdo))->run(isset($options['limit']) ? $limit : 5000);
        $pdo->rollBack();
        if (($options['details'] ?? '0') !== '1') {
            unset($audit['orders']);
        }
        $print($audit);
        exit(0);
    }
    if ($command === 'rollback-check') {
        $inventory = $units->inventory();
        $diverging = (int) $pdo->query("SELECT COUNT(*) FROM production_line_plan_heads h INNER JOIN production_line_plans p ON p.plan_uuid = h.plan_uuid
            INNER JOIN operational_order_items i ON i.item_uuid = h.item_uuid INNER JOIN operational_orders o ON o.order_uuid = h.order_uuid
            WHERE p.piece_count IS NOT NULL AND p.piece_count <> i.quantity AND o.production_completed_at IS NULL")->fetchColumn();
        $assisted = 0;
        if ((new \Arasya\Operations\Production\AssistedCheckpoints($pdo))->available()) {
            $assisted = (int) $pdo->query('SELECT COUNT(DISTINCT c.order_uuid) FROM production_assisted_checkpoints c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE o.production_completed_at IS NULL')->fetchColumn();
        }
        $unsafe = $inventory['activeTracks'] > 0 || $inventory['replacements'] > 0 || $diverging > 0 || $assisted > 0;
        $print(['rollbackToOlderApiSafe' => !$unsafe, 'activeTracks' => $inventory['activeTracks'], 'activeRouteTracks' => $inventory['activeRouteTracks'],
            'replacements' => $inventory['replacements'], 'openLinesWithPiecesDifferentFromQuantity' => $diverging, 'openOrdersWithAssistedCheckpoints' => $assisted,
            'rule' => 'Do not activate an API older than 2.32.0 while this check fails: it would ignore pieces on tracks, replacements and confirmed piece counts. Do not activate an API older than 2.34.0 while open orders have assisted PERDEA checkpoints: it would not require their verification.']);
        exit($unsafe ? 3 : 0);
    }
    $print(['partialRework' => $features->enabled(FeatureActivation::PARTIAL_REWORK), 'pieceRouting' => $features->enabled(FeatureActivation::PIECE_ROUTING),
        'perdeaAssistedCheckpoints' => $features->enabled(FeatureActivation::PERDEA_ASSISTED_CHECKPOINTS),
        'partialReworkCohort' => count($features->cohort(FeatureActivation::PARTIAL_REWORK)), 'pieceRoutingCohort' => count($features->cohort(FeatureActivation::PIECE_ROUTING)),
        'perdeaAssistedCheckpointsCohort' => count($features->cohort(FeatureActivation::PERDEA_ASSISTED_CHECKPOINTS))] + $units->inventory());
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Failed: ' . get_class($error) . "\n");
    exit(1);
}
