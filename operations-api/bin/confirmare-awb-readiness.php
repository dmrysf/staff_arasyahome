<?php

declare(strict_types=1);

/**
 * AWB readiness of Confirmare orders (API 2.77.0, owner 2026-11-19): every approved Trend Home / Outlet Perdele order at
 * stage 12-14 without its FAN Courier AWB is routed to a shop employee when nobody routed it, gets its final invoice when
 * the shop has none, and has its lost invoice/AWB answers settled (ConfirmareAwbReadiness). The AWB itself stays the
 * employee's. Also run by bin/confirmare-cycles.php every 5 minutes.
 *
 *   php bin/confirmare-awb-readiness.php [--apply] [--source=trendhome|outletperdele] [--limit=300]
 *
 * Without --apply nothing is written except the shop read audit. Prints order numbers, codes and counts only.
 */

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['apply', 'source:', 'limit:']);
$apply = array_key_exists('apply', $options);
$source = isset($options['source']) && is_string($options['source']) ? $options['source'] : null;
if ($source !== null && !in_array($source, \Arasya\Operations\Confirmare\ConfirmareAwbReadiness::SOURCES, true)) {
    fwrite(STDERR, "confirmare-awb-readiness: --source must be trendhome or outletperdele\n");
    exit(2);
}
$limit = isset($options['limit']) && is_string($options['limit']) && ctype_digit($options['limit']) ? (int) $options['limit'] : 300;
$pdo = $container->pdo();
if ((int) $pdo->query("SELECT GET_LOCK('arasya_confirmare_cycles', 30)")->fetchColumn() !== 1) {
    fwrite(STDOUT, "confirmare-awb-readiness: the Confirmare cycle worker is active; try again\n");
    exit(1);
}
try {
    $reports = $container->confirmareAwbReadiness()->run($apply, $source, $limit);
    $counts = [];
    foreach ($reports as $report) {
        $counts[$report['state']] = ($counts[$report['state']] ?? 0) + 1;
        fwrite(STDOUT, sprintf("%s %s stage=%s%s state=%s steps=%s blockers=%s\n", $report['source'], $report['orderNumber'], $report['stage'], $report['completed'] ? '(completed)' : '',
            $report['state'], implode(',', $report['steps']) ?: '-', implode(',', $report['blockers']) ?: '-'));
    }
    ksort($counts);
    fwrite(STDOUT, sprintf("confirmare-awb-readiness: %s, %d orders%s\n", $apply ? 'applied' : 'dry run', count($reports),
        $counts === [] ? '' : ' (' . implode(', ', array_map(static fn (string $k, int $v): string => "{$k} {$v}", array_keys($counts), $counts)) . ')'));
} finally {
    $pdo->query("SELECT RELEASE_LOCK('arasya_confirmare_cycles')");
}
