<?php

declare(strict_types=1);

// Outbound cancellation synchronization operator tool (migration 034, API 2.36.0).
//
//   php bin/cancellation-sync.php status
//   php bin/cancellation-sync.php run [--limit=20]
//   php bin/cancellation-sync.php reconcile --dry-run=1
//   php bin/cancellation-sync.php reconcile --dry-run=0 --operator=<name>
//   php bin/cancellation-sync.php enable  --source=trendyol|trendhome|outletperdele --operator=<name> --reason=<text> --confirm=ENABLE-CANCELLATION-SYNC
//   php bin/cancellation-sync.php disable --source=trendyol|trendhome|outletperdele --operator=<name> --reason=<text>
//
// status is read-only: the server switch, every provider's flag, configuration and mechanism, and operations by state.
// run is the worker (cron): it contacts a provider only while ARASYA_CANCELLATION_SYNC=enabled AND the provider's flag
// is enabled; otherwise it only recovers expired leases and escalates expired waits.
// reconcile lists findings (--dry-run=1 changes nothing); --dry-run=0 records them and confirms open operations whose
// source cancellation is already stored. It never calls a provider and never stops, releases or reopens an order.
// enable / disable change one provider flag with an audit row (production_feature_flag_events). Enabling is an
// outward-facing decision: it needs the confirmation phrase, and still sends nothing while the server switch is off.
// Disabling needs no phrase and stops new outbound calls at the worker's next lease (an in-flight call finishes).
//
// Exit codes: 0 done; 1 failed; 2 invalid invocation or refused (nothing changed). Messages never echo an argument
// value, credentials or customer data.

use Arasya\Operations\Cancellation\Sync\ProviderRegistry;
use Arasya\Operations\Cancellation\Sync\SyncSchema;

$commands = [
    'status' => [[], []],
    'run' => [[], ['limit']],
    'reconcile' => [['dry-run'], ['operator']],
    'enable' => [['source', 'operator', 'reason', 'confirm'], []],
    'disable' => [['source', 'operator', 'reason'], []],
];
$refuse = static function (string $message): never {
    fwrite(STDERR, "Refused: {$message}\nUsage: php bin/cancellation-sync.php status|run|reconcile|enable|disable [--name=value ...]\n");
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
if (isset($options['limit']) && (preg_match('/^[1-9][0-9]{0,2}$/D', $options['limit']) !== 1 || (int) $options['limit'] > 100)) {
    $refuse('--limit must be between 1 and 100.');
}
if ($command === 'reconcile') {
    if (!in_array($options['dry-run'], ['0', '1'], true)) {
        $refuse('--dry-run must be 0 or 1.');
    }
    if ($options['dry-run'] === '0' && trim($options['operator'] ?? '') === '') {
        $refuse('--operator is required to record a reconciliation.');
    }
}
if (in_array($command, ['enable', 'disable'], true)) {
    // Checked before the bootstrap loads anything; ProviderRegistry::FLAGS names the same three sources.
    if (!in_array($options['source'], ['trendyol', 'trendhome', 'outletperdele'], true)) {
        $refuse('--source must be trendyol, trendhome or outletperdele.');
    }
    if (mb_strlen(trim($options['reason'])) < 10) {
        $refuse('--reason must have at least 10 characters.');
    }
    if ($command === 'enable' && $options['confirm'] !== 'ENABLE-CANCELLATION-SYNC') {
        $refuse('the confirmation phrase does not match.');
    }
}

$container = require __DIR__ . '/cli-bootstrap.php';
$pdo = $container->pdo();
$print = static fn (array $payload) => fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
if (!SyncSchema::ready($pdo)) {
    fwrite(STDERR, "Refused: migration 034 is not applied.\n");
    exit(2);
}
try {
    $providers = $container->cancellationProviders();
    if ($command === 'status') {
        $states = [];
        foreach ($pdo->query('SELECT source_key, state, COUNT(*) AS total FROM order_cancellation_sync_operations GROUP BY source_key, state')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $states[(string) $row['source_key']][(string) $row['state']] = (int) $row['total'];
        }
        $print([
            'serverSwitch' => $providers->serverEnabled() ? 'enabled' : 'disabled',
            'providers' => array_map(static function (string $source) use ($providers): array {
                $view = $providers->view($source);
                return ['source' => $source, 'mechanism' => $view['mechanism'], 'configured' => $view['configured'], 'flagEnabled' => $view['flagEnabled'], 'active' => $view['active']];
            }, ['trendyol', 'trendhome', 'outletperdele', 'b2b']),
            'operations' => $states,
            'openDiscrepancies' => (int) $pdo->query("SELECT COUNT(*) FROM order_cancellation_discrepancies WHERE state = 'open'")->fetchColumn(),
        ]);
        exit(0);
    }
    if ($command === 'run') {
        $print($container->cancellationSyncWorker()->run((int) ($options['limit'] ?? 20)));
        exit(0);
    }
    if ($command === 'reconcile') {
        $reconciler = $container->cancellationReconciler();
        if ($options['dry-run'] === '1') {
            $findings = $reconciler->scan();
            $counts = ['total' => count($findings)];
            foreach ($findings as $finding) {
                $counts[$finding['kind']] = ($counts[$finding['kind']] ?? 0) + 1;
            }
            $print(['dryRun' => true, 'counts' => $counts, 'findings' => array_map(static fn (array $finding): array => [
                'kind' => $finding['kind'], 'severity' => $finding['severity'], 'order' => $finding['globalOrderId'], 'source' => $finding['source'],
                'operation' => $finding['operationUuid'], 'remediation' => $finding['remediation'], 'beforeBaseline' => $finding['beforeBaseline'],
            ], $findings)]);
            exit(0);
        }
        $result = $reconciler->apply(trim($options['operator']));
        $print(['dryRun' => false, 'counts' => $result['counts'], 'autoConfirmed' => $result['autoConfirmed'], 'resolved' => $result['resolved']]);
        exit(0);
    }
    $flag = ProviderRegistry::FLAGS[$options['source']];
    $enabled = $command === 'enable';
    $operator = mb_substr(trim($options['operator']), 0, 100);
    $reason = mb_substr(trim($options['reason']), 0, 255);
    $now = $container->clock()->now()->format('Y-m-d H:i:s.u');
    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT enabled FROM production_feature_flags WHERE flag_key = ? FOR UPDATE');
    $statement->execute([$flag]);
    $current = $statement->fetchColumn();
    if ($current === false) {
        $pdo->rollBack();
        fwrite(STDERR, "Refused: the provider flag row is missing; apply migration 034 first.\n");
        exit(2);
    }
    $changed = (int) $current !== ($enabled ? 1 : 0);
    if ($changed) {
        $pdo->prepare('UPDATE production_feature_flags SET enabled = ?, changed_by_employee_uuid = NULL, change_reason = ?, changed_at = ? WHERE flag_key = ?')
            ->execute([$enabled ? 1 : 0, mb_substr("{$operator}: {$reason}", 0, 255), $now, $flag]);
        $pdo->prepare('INSERT INTO production_feature_flag_events (event_uuid, feature_key, action, order_uuid, operator, reason, occurred_at) VALUES (?, ?, ?, NULL, ?, ?, ?)')
            ->execute([\Arasya\Operations\Support\Uuid::v4(), $flag, $enabled ? 'enabled' : 'disabled', $operator, $reason, $now]);
    }
    $pdo->commit();
    $print(['source' => $options['source'], 'flagEnabled' => $enabled, 'changed' => $changed, 'serverSwitch' => $providers->serverEnabled() ? 'enabled' : 'disabled',
        'active' => $providers->active($options['source'])]);
    exit(0);
} catch (Throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Failed: the cancellation synchronization command could not complete.\n");
    exit(1);
}
