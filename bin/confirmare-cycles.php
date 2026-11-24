<?php

declare(strict_types=1);

/**
 * The Confirmare cycle worker (API 2.46.0): advance proforma reminders after 24 h and 72 h, the 120 h expiry with the
 * bank check, the cancellation in SmartBill and the order's cancellation request (ConfirmareCycles), and since API 2.77.0
 * the AWB readiness of orders at stage 12-14 (ConfirmareAwbReadiness), since API 2.77.4 the lost answers of shop invoice
 * actions (ConfirmareLostAnswers) and stale production clearance (ConfirmareClearanceSweep). Cron, every 5 minutes. One run at a time (server-wide advisory lock); each cycle is also claimed on its own row.
 *
 *   php bin/confirmare-cycles.php [--limit=100]
 *
 * Prints counts only: no order number, customer data or amount.
 */

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['limit:']);
$limit = isset($options['limit']) && is_string($options['limit']) && ctype_digit($options['limit']) ? (int) $options['limit'] : 100;
$pdo = $container->pdo();
if ((int) $pdo->query("SELECT GET_LOCK('arasya_confirmare_cycles', 0)")->fetchColumn() !== 1) {
    fwrite(STDOUT, "confirmare-cycles: another run is active\n");
    exit(0);
}
try {
    $cycles = $container->confirmareCycles();
    if (!$cycles->installed()) {
        fwrite(STDOUT, "confirmare-cycles: migration 043 is not applied; nothing to do\n");
        exit(0);
    }
    $result = $cycles->run($limit, 'cli-confirmare-cycles-' . bin2hex(random_bytes(6)));
    // Reactivated orders whose shop event arrived: their cancellation hold is released (ConfirmareOperations).
    $released = $container->confirmareOperations()->settleReactivations($limit);
    // API 2.77.0: orders at stage 12-14 without their AWB are routed and invoiced (ConfirmareAwbReadiness); an order read
    // within the last hour with nothing to do is not asked again.
    $prepared = ['routed' => 0, 'invoiced' => 0, 'ready' => 0, 'blocked' => 0];
    try {
        foreach ($container->confirmareAwbReadiness()->run(true, null, $limit, true) as $report) {
            $prepared['routed'] += in_array('routed', $report['steps'], true) ? 1 : 0;
            $prepared['invoiced'] += in_array('invoice_issued', $report['steps'], true) ? 1 : 0;
            $prepared['ready'] += $report['state'] === 'ready' ? 1 : 0;
            $prepared['blocked'] += in_array($report['state'], ['blocked', 'error'], true) ? 1 : 0;
        }
    } catch (Throwable $e) {
        fwrite(STDOUT, "confirmare-cycles: AWB readiness failed\n");
    }
    // API 2.77.4: an invoice action whose answer the shop sent too late (total acceptance, proforma, final invoice) is
    // settled with the shop's stored answer, so the order is not blocked by `invoice_action_open` for days.
    $lost = ['applied' => 0, 'rejected' => 0, 'open' => 0];
    try {
        $lost = $container->confirmareLostAnswers()->run(20) + $lost;
    } catch (Throwable $e) {
        fwrite(STDOUT, "confirmare-cycles: lost answers failed\n");
    }
    // API 2.77.4: an approved order whose money is cleared but whose clearance flag was computed while a finance action was
    // still unsettled is recomputed (every condition again; it only opens production when all hold).
    $sweep = ['cleared' => 0];
    try {
        $sweep = $container->confirmareClearanceSweep()->run();
    } catch (Throwable $e) {
        fwrite(STDOUT, "confirmare-cycles: clearance sweep failed\n");
    }
    fwrite(STDOUT, sprintf("confirmare-cycles: seen %d, advanced %d, skipped %d, reactivations released %d, awb readiness routed %d, invoiced %d, ready %d, blocked %d, lost answers settled %d, refused %d, still open %d, clearance opened %d\n",
        $result['seen'], $result['advanced'], $result['skipped'], $released, $prepared['routed'], $prepared['invoiced'], $prepared['ready'], $prepared['blocked'], $lost['applied'], $lost['rejected'], $lost['open'], $sweep['cleared']));
} finally {
    $pdo->query("SELECT RELEASE_LOCK('arasya_confirmare_cycles')");
}
