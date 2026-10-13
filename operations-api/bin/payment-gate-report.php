<?php

declare(strict_types=1);

// Read-only backlog report of the source advance payment gate (API 2.29.0). Counts the open orders of each signed
// source by gate state, document state and production stage, and lists the order numbers that need a person:
// a closed gate (waiting, partial, unknown, unverified) with no document yet, and reversals after production started.
// Opens a read-only transaction; prints no customer data, no amount and no QR.
//
//   php bin/payment-gate-report.php [--source=trendhome] [--json]

use Arasya\Operations\Production\PaymentGate;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['source:', 'json']);
$pdo = $container->pdo();
$modes = $container->authorityModes();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->beginTransaction();
$where = "o.source_key IN ('trendhome', 'outletperdele') AND o.production_completed_at IS NULL AND o.operational_status <> 'unavailable'";
$params = [];
if (is_string($options['source'] ?? null)) {
    $where .= ' AND o.source_key = ?';
    $params[] = $options['source'];
}
$statement = $pdo->prepare("SELECT o.source_key, o.order_number, o.production_stage_id, o.production_authority, o.document_status, o.payment_method, o.advance_status, o.payment_reversed_at
    FROM operational_orders o WHERE {$where} ORDER BY o.source_key, o.order_number");
$statement->execute($params);
$report = [];
foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $source = (string) $row['source_key'];
    $state = PaymentGate::state($row['payment_method'], $row['advance_status']);
    $stage = $row['production_stage_id'] === 'waiting' ? 'stage1' : 'started';
    $key = "{$state}|document_{$row['document_status']}|{$stage}";
    $report[$source]['mode'] ??= PaymentGate::modeFor($modes, $source)->value;
    $report[$source]['counts'][$key] = ($report[$source]['counts'][$key] ?? 0) + 1;
    if (PaymentGate::isClosed($state) && $row['document_status'] === 'none' && $stage === 'stage1' && $row['production_authority'] === 'operations') {
        $report[$source]['closedAtStage1'][$state][] = (string) $row['order_number'];
    }
    if ($row['payment_reversed_at'] !== null) {
        $report[$source]['reversedAfterStart'][] = (string) $row['order_number'];
    }
    if (PaymentGate::isClosed($state) && $row['document_status'] !== 'none' && $stage === 'stage1') {
        $report[$source]['documentedButClosed'][] = (string) $row['order_number'];
    }
}
$pdo->rollBack();
foreach ($report as &$entry) {
    ksort($entry['counts']);
}
unset($entry);
if (isset($options['json'])) {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}
foreach ($report as $source => $entry) {
    fwrite(STDOUT, "== {$source} (gate {$entry['mode']})\n");
    foreach ($entry['counts'] as $key => $count) {
        fwrite(STDOUT, sprintf("  %-48s %5d\n", str_replace('|', '  ', $key), $count));
    }
    foreach ($entry['closedAtStage1'] ?? [] as $state => $numbers) {
        fwrite(STDOUT, sprintf("  closed at stage 1 (%s, no document): %d — %s\n", $state, count($numbers), implode(' ', array_slice($numbers, 0, 300))));
    }
    if (($entry['documentedButClosed'] ?? []) !== []) {
        fwrite(STDOUT, '  documented before the gate, advance not confirmed: ' . implode(' ', $entry['documentedButClosed']) . "\n");
    }
    if (($entry['reversedAfterStart'] ?? []) !== []) {
        fwrite(STDOUT, '  advance reversed after production started: ' . implode(' ', $entry['reversedAfterStart']) . "\n");
    }
}
