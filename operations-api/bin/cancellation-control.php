<?php

declare(strict_types=1);

// Cancellation control operator tool (migration 033, API 2.35.0).
//
//   php bin/cancellation-control.php audit [--details=1]
//   php bin/cancellation-control.php stop-legacy --order=<global order id> --operator=<name> --reason=<text> --confirm=STOP-PRODUCTION
//
// audit is read-only: the stopped orders by category and the legacy candidates, open orders whose source reported a
// cancellation before migration 033 while production was never stopped (API 2.34.0 kept such an order workable once
// cutting had started). --details=1 lists them by global order id, stage and report time (no customer data).
//
// stop-legacy stops exactly one such order, named explicitly, after a person approved it. It records the stored source
// report time as the cancellation time and changes no stage, owner, exception, track, document or QR. There is no bulk
// mode on purpose. The Dashboard local stop (production.cancellation.manage) is the ordinary path for everything else.
//
// Exit codes: 0 done; 1 failed; 2 invalid invocation or refused (nothing changed). Messages never echo an argument value.

use Arasya\Operations\Cancellation\CancellationControl;
use Arasya\Operations\Cancellation\CancellationView;

$commands = [
    'audit' => [[], ['details']],
    'stop-legacy' => [['order', 'operator', 'reason', 'confirm'], []],
];
$refuse = static function (string $message): never {
    fwrite(STDERR, "Refused: {$message}\nUsage: php bin/cancellation-control.php audit|stop-legacy [--name=value ...]\n");
    exit(2);
};
$arguments = array_slice($argv, 1);
$command = $arguments === [] ? 'audit' : (string) array_shift($arguments);
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
if ($command === 'stop-legacy' && $options['confirm'] !== 'STOP-PRODUCTION') {
    $refuse('the confirmation phrase does not match.');
}
if (isset($options['details']) && !in_array($options['details'], ['0', '1'], true)) {
    $refuse('--details must be 0 or 1.');
}

$container = require __DIR__ . '/cli-bootstrap.php';
$pdo = $container->pdo();
$print = static fn (array $payload) => fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
$legacyWhere = "o.production_completed_at IS NULL AND o.cancellation_state IS NULL AND o.operational_status <> 'unavailable' AND o.source_reported_unavailable_at IS NOT NULL";
try {
    if ($command === 'audit') {
        $controls = $pdo->query('SELECT hold_status, origin, source_status, source_request_status, production_phase, physical_status FROM order_cancellation_controls')->fetchAll(PDO::FETCH_ASSOC);
        $counts = array_fill_keys(CancellationView::CATEGORIES, 0);
        foreach ($controls as $control) {
            $counts[CancellationView::category($control)]++;
        }
        $legacy = $pdo->query("SELECT o.global_order_id, o.production_stage_id, o.production_owner_employee_uuid IS NOT NULL AS owned, o.source_reported_unavailable_at
            FROM operational_orders o WHERE {$legacyWhere} ORDER BY o.source_reported_unavailable_at, o.global_order_id")->fetchAll(PDO::FETCH_ASSOC);
        $payload = [
            'controls' => $counts,
            'stoppedOpen' => (int) $pdo->query("SELECT COUNT(*) FROM operational_orders WHERE cancellation_state = 'held' AND production_completed_at IS NULL")->fetchColumn(),
            'legacyUnstopped' => count($legacy),
            'sourceUnavailableWithoutControl' => (int) $pdo->query("SELECT COUNT(*) FROM operational_orders WHERE production_completed_at IS NULL AND cancellation_state IS NULL AND operational_status = 'unavailable'")->fetchColumn(),
        ];
        if (($options['details'] ?? '0') === '1') {
            $payload['legacy'] = array_map(static fn (array $row): array => ['order' => (string) $row['global_order_id'], 'stage' => (string) $row['production_stage_id'],
                'owned' => (int) $row['owned'] === 1, 'reportedAt' => CancellationView::iso((string) $row['source_reported_unavailable_at'])], $legacy);
        }
        $print($payload);
        exit(0);
    }
    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT o.order_uuid, o.source_commerce_status_code, o.source_commerce_status_label, o.source_reported_unavailable_at FROM operational_orders o WHERE o.global_order_id = ? AND ' . $legacyWhere . ' FOR UPDATE');
    $statement->execute([$options['order']]);
    $order = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($order)) {
        $pdo->rollBack();
        fwrite(STDERR, "Refused: the order is not an open, unstopped order whose source reported a cancellation.\n");
        exit(2);
    }
    (new CancellationControl($pdo, $container->clock()))->onSourceAvailability((string) $order['order_uuid'], true, false, [
        'reason' => 'legacy_source_cancellation', 'statusCode' => $order['source_commerce_status_code'], 'statusLabel' => $order['source_commerce_status_label'],
        'eventRef' => 'operator-cli', 'previousReportedAt' => $order['source_reported_unavailable_at'],
        'operator' => mb_substr(trim($options['operator']), 0, 80), 'note' => mb_substr(trim($options['reason']), 0, 200),
    ]);
    $pdo->commit();
    $print(['order' => $options['order'], 'stopped' => true]);
    exit(0);
} catch (Throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Failed: the cancellation control command could not complete.\n");
    exit(1);
}
