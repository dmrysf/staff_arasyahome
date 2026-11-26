<?php

declare(strict_types=1);

// Automatic distribution of Confirmare orders (API 2.38.0). Meant for cron (for example every minute) once a source is
// active; harmless before: without unassigned Confirmare orders it changes nothing.
//
//   php bin/confirmare-distribute.php [--source=trendhome] [--dry-run]
//
// First returns to the pool every pending assignment whose assignee can no longer work on it (scope removed, account,
// department or application inactive). Then assigns unassigned Confirmare orders, nearest due date first and then FIFO,
// to available operators (no opt-in; leave excludes), the least loaded first: the balance target
// ConfirmareService::TARGET_OPEN_PER_OPERATOR never blocks the queue. Suspended sources are held. Finally raises one
// supervisor alert per assignment without progress (never a reassignment). Every change is one short transaction with
// its own audit event. --dry-run only reads. Prints counts only (no customer data, no names). API 2.50.0: for a source
// with ARASYA_SOURCE_FINANCE_TASKS_<SRC>=auto (off by default) it then runs the finance task inventory
// (bin/finance-task-inventory.php --apply).

use Arasya\Operations\Integration\SourceRegistry;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['source:', 'dry-run']);
$source = is_string($options['source'] ?? null) ? $options['source'] : null;
if ($source !== null && preg_match(SourceRegistry::KEY_PATTERN, $source) !== 1) {
    fwrite(STDERR, "Usage: php bin/confirmare-distribute.php [--source=<key>] [--dry-run]\n");
    exit(2);
}
$result = $container->confirmareService()->distribute($source, isset($options['dry-run']));
// API 2.50.0: a source with ARASYA_SOURCE_FINANCE_TASKS_<SRC>=auto also runs the finance task inventory (off by default).
$registry = \Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry::fromConfig($container->config());
$auto = array_values(array_filter($source !== null ? [$source] : $container->config()->sourceKeys, static fn (string $key): bool => $registry->find($key)?->autoFinanceTasks() === true));
if ($auto !== [] && !isset($options['dry-run'])) {
    $tasks = $container->financeTaskInventory()->apply($auto, 'distribute-' . gmdate('YmdHis'));
    $result['financeTasks'] = ['sources' => $auto, 'created' => $tasks['created'], 'resolved' => $tasks['resolved'], 'skipped' => count($tasks['skipped'])];
}
// API 2.81.0 (P0 2026-11-25): an approved order whose money finance accepted goes to its shop employee within a minute
// instead of the next 5-minute cycle pass (ConfirmareInvoice::routePaid: database only, no shop call, one run at a time).
if (!isset($options['dry-run']) && $source === null) {
    try {
        $paid = $container->confirmareInvoice()->routePaid(50);
        $result['paidRouting'] = ['seen' => $paid['seen'], 'routed' => $paid['routed']];
    } catch (Throwable) {
        $result['paidRouting'] = ['failed' => true];
    }
}
// API 2.82.0 (Operation Simplification V1): a verified online payment goes to the shop employee without the operator (the
// system approval, then the routing); production still waits for the shop employee's customer check.
// Only once the operator decisions are switched on (ARASYA_CONFIRMARE_DECISIONS=enabled): deploying 2.82.0 starts nothing.
$decisionsOn = $container->config()->confirmareDecisionsEnabled();
$result['decisions'] = $decisionsOn ? 'enabled' : 'disabled';
if (!isset($options['dry-run']) && $source === null && $decisionsOn) {
    try {
        $online = $container->confirmareService()->approveVerifiedOnline(50, 'cli-online-' . bin2hex(random_bytes(6)));
        $routed = $container->confirmareInvoice()->routeOnline(50);
        $result['onlineRouting'] = ['approved' => $online['approved'], 'routed' => $routed['routed'], 'skipped' => $online['skipped'] + $routed['skipped']];
    } catch (Throwable) {
        $result['onlineRouting'] = ['failed' => true];
    }
}
// API 2.82.0 (Operation Simplification V1): what follows an operator's decision runs within a minute (the customer's
// acceptance of the total, the advance proforma, the cancellation of a refused order after its undo period).
if (!isset($options['dry-run']) && $source === null && $decisionsOn) {
    try {
        if ((int) $container->pdo()->query("SELECT GET_LOCK('arasya_confirmare_followups', 0)")->fetchColumn() === 1) {
            try {
                $followups = $container->confirmareDecisionFollowUps()->run(30, 'cli-followups-' . bin2hex(random_bytes(6)));
                $result['decisionFollowUps'] = $followups;
            } finally {
                $container->pdo()->query("SELECT RELEASE_LOCK('arasya_confirmare_followups')");
            }
        }
    } catch (Throwable) {
        $result['decisionFollowUps'] = ['failed' => true];
    }
}
fwrite(STDOUT, json_encode($result + ['dryRun' => isset($options['dry-run'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
exit(0);
