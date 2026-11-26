<?php

declare(strict_types=1);

// API 2.82.0 (Operation Simplification V1): orders approved before the green decision existed that still wait for their
// advance proforma (cash on delivery, the customer's confirmation recorded, not paid, no proforma e-mailed, no active
// proforma cycle, no open invoice action, not closed or stopped) get the same follow-up a green decision opens: the
// customer's acceptance of the total in the shop, then the proforma through the existing cycle, as the operator who
// approved the order. Never a second proforma: an existing one is re-used by the cycle.
//
//   php bin/confirmare-decision-backfill.php            dry run (default): counts and the reasons, nothing is written
//   php bin/confirmare-decision-backfill.php --apply    opens the follow-ups (the distribute cron runs them within a minute)
//
// --apply is a live change: run it only with the owner's PRODUCTION GO. Prints counts only (no order data).

$container = require __DIR__ . '/cli-bootstrap.php';
$apply = in_array('--apply', $argv, true);
$pdo = $container->pdo();
if (!\Arasya\Operations\Confirmare\ConfirmareDecisionFollowUps::installed($pdo)) {
    fwrite(STDERR, "Migration 063 is not applied.\n");
    exit(1);
}
$rows = $pdo->query("SELECT o.order_uuid, o.global_order_id, o.source_key, c.version, c.decided_by_employee_uuid
    FROM order_confirmations c JOIN operational_orders o ON o.order_uuid = c.order_uuid
    WHERE c.status = 'approved' AND c.call_confirmed_at IS NOT NULL AND c.decided_by_employee_uuid IS NOT NULL
      AND o.workflow_cohort = 'confirmare' AND o.payment_method = 'cod' AND COALESCE(o.source_financial_state, '') <> 'cleared'
      AND o.cancellation_state IS NULL AND o.operational_status <> 'unavailable' AND o.source_reported_unavailable_at IS NULL
      AND COALESCE(o.source_commerce_status_code, '') NOT IN ('cancelled', 'refunded', 'failed', 'trash', 'completed')
      AND o.production_stage_id = 'waiting'
      AND NOT EXISTS (SELECT 1 FROM confirmare_proforma_cycles p WHERE p.order_uuid = o.order_uuid AND (p.active_order_uuid IS NOT NULL OR p.sent_at IS NOT NULL))
      AND NOT EXISTS (SELECT 1 FROM order_invoice_actions i WHERE i.order_uuid = o.order_uuid AND ((i.action = 'proforma_send_mail' AND i.state = 'applied') OR i.state IN ('pending', 'unknown')))
      AND NOT EXISTS (SELECT 1 FROM confirmare_decision_followups f WHERE f.order_uuid = o.order_uuid AND (f.active_order_uuid IS NOT NULL OR f.kind = 'confirmed'))
    ORDER BY c.decided_at")->fetchAll(PDO::FETCH_ASSOC);
$result = ['candidates' => count($rows), 'bySource' => [], 'opened' => 0, 'apply' => $apply];
foreach ($rows as $row) {
    $result['bySource'][$row['source_key']] = ($result['bySource'][$row['source_key']] ?? 0) + 1;
}
if ($apply) {
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    foreach ($rows as $row) {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
            $lock->execute([$row['order_uuid']]);
            \Arasya\Operations\Confirmare\ConfirmareDecisionFollowUps::open($pdo, $row, 'confirmed', (string) $row['decided_by_employee_uuid'], (int) $row['version'], $now, 0);
            $pdo->commit();
            $result['opened']++;
        } catch (Throwable) {
            $pdo->rollBack();
        }
    }
}
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
exit(0);
