<?php
declare(strict_types=1);
/**
 * Production Intelligence V2 (owner K7): keeps an order out of every meter report. The order itself is never changed or
 * deleted. Dry run by default; `--apply` records the decision and an IAM audit event. `--list` prints the exclusions.
 *
 *   php bin/meter-exclusion.php --order=<source:order-id> --reason=test_order|duplicate_order|not_a_sale --by="<name>" [--note="..."] [--apply]
 *   php bin/meter-exclusion.php --list
 */
$container = require __DIR__ . '/cli-bootstrap.php';
try {
    $pdo = $container->pdo();
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (in_array($argument, ['--apply', '--list'], true)) { $options[substr($argument, 2)] = true; continue; }
        if (preg_match('/^--(order|reason|by|note)=(.+)$/D', $argument, $m) === 1) { $options[$m[1]] = $m[2]; continue; }
        throw new InvalidArgumentException('Unknown argument.');
    }
    if (isset($options['list'])) {
        foreach ($pdo->query('SELECT o.global_order_id, o.order_number, x.reason, x.decided_by_label, x.decided_at FROM production_meter_exclusions x JOIN operational_orders o ON o.order_uuid = x.order_uuid ORDER BY x.decided_at') as $row) {
            fwrite(STDOUT, "{$row['global_order_id']} #{$row['order_number']} {$row['reason']} by {$row['decided_by_label']} at {$row['decided_at']}Z\n");
        }
        exit(0);
    }
    foreach (['order', 'reason', 'by'] as $required) {
        if (!isset($options[$required]) || !is_string($options[$required])) throw new InvalidArgumentException("--{$required} is required.");
    }
    if (!in_array($options['reason'], ['test_order', 'duplicate_order', 'not_a_sale'], true)) throw new InvalidArgumentException('Invalid --reason.');
    if (mb_strlen($options['by']) > 160 || mb_strlen($options['note'] ?? '') > 300) throw new InvalidArgumentException('--by or --note too long.');
    $s = $pdo->prepare('SELECT order_uuid, global_order_id, order_number FROM operational_orders WHERE global_order_id = ?');
    $s->execute([$options['order']]);
    $order = $s->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new InvalidArgumentException('Order not found.');
    if (!isset($options['apply'])) {
        fwrite(STDOUT, "DRY RUN (nothing written): would exclude {$order['global_order_id']} #{$order['order_number']} ({$options['reason']}).\n");
        exit(0);
    }
    $now = $container->clock()->now()->format('Y-m-d H:i:s.u');
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT IGNORE INTO production_meter_exclusions (order_uuid, reason, note, decided_by_label, decided_at) VALUES (?, ?, ?, ?, ?)');
    $insert->execute([$order['order_uuid'], $options['reason'], $options['note'] ?? null, $options['by'], $now]);
    if ($insert->rowCount() === 1) {
        (new Arasya\Operations\Iam\IamAuditLogger($pdo))->record(null, 'production_intelligence.order_excluded', 'operational_order', $order['order_uuid'], (string) $order['global_order_id'],
            ['reason' => $options['reason'], 'decidedBy' => $options['by']], 'cli-' . bin2hex(random_bytes(8)), $now);
    }
    $pdo->commit();
    fwrite(STDOUT, ($insert->rowCount() === 1 ? 'EXCLUDED' : 'ALREADY EXCLUDED') . ": {$order['global_order_id']}. The order itself is unchanged; run bin/meter-ledger.php to reverse its sold entries.\n");
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Meter exclusion failed (' . get_class($error) . '): ' . ($error instanceof InvalidArgumentException ? rtrim($error->getMessage(), ".") : 'see logs') . ". No secrets are printed.\n");
    exit(1);
}
