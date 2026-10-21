<?php

declare(strict_types=1);

// Confirmare workflow cohort of the signed WooCommerce sources (API 2.37.0).
//
//   php bin/workflow-cohort.php --source=trendhome --status          read-only: activation state and history
//   php bin/workflow-cohort.php [--source=trendhome] --diagnose      read-only: cohort counts and the orders a person must
//                                                                    review (transition window, undetermined, conflicts)
//   php bin/workflow-cohort.php --source=trendhome --activate --actor="Name" [--reason="..."]
//   php bin/workflow-cohort.php --source=trendhome --suspend --actor="Name" --reason="..."
//
// Activation records the source's actual Confirmare activation instant (server clock). In this release it is always
// refused: the Confirmare workflow that approves orders is not available yet (ConfirmationGate::WORKFLOW_READY), so an
// activation would stop the source's production. Status and diagnosis open a read-only transaction and print no
// customer data (order numbers and global ids only).

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Production\WorkflowCohortActivation;
use Arasya\Operations\Support\Uuid;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['source:', 'status', 'diagnose', 'activate', 'suspend', 'actor:', 'reason:']);
$commands = array_values(array_intersect(['status', 'diagnose', 'activate', 'suspend'], array_keys($options)));
$source = is_string($options['source'] ?? null) ? $options['source'] : null;
if (count($commands) !== 1 || ($source === null && $commands[0] !== 'diagnose') || ($source !== null && preg_match(SourceRegistry::KEY_PATTERN, $source) !== 1)) {
    fwrite(STDERR, "Usage: php bin/workflow-cohort.php [--source=<key>] --status|--diagnose|--activate|--suspend [--actor=\"Name\"] [--reason=\"...\"]\n");
    exit(2);
}
$command = $commands[0];
$registry = SourceRegistry::fromConfig($container->config());
$pdo = $container->pdo();
$service = new WorkflowCohortActivation($pdo, $container->clock());
try {
    if ($command === 'status' || $command === 'diagnose') {
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        $keys = $source !== null ? [$source] : ['trendhome', 'outletperdele'];
        $report = [];
        foreach ($keys as $key) {
            $diagnosis = $service->diagnose($key, $registry->find($key));
            $report[] = $command === 'status'
                ? array_intersect_key($diagnosis, array_flip(['sourceKey', 'businessCutover', 'state', 'activatedAt', 'permitted', 'configMismatch', 'workflowReady', 'history', 'ordersByCohort', 'awaitingConfirmation']))
                : $diagnosis;
        }
        $pdo->rollBack();
        fwrite(STDOUT, json_encode(count($report) === 1 ? $report[0] : $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    }
    $actor = is_string($options['actor'] ?? null) ? $options['actor'] : '';
    $reason = is_string($options['reason'] ?? null) ? $options['reason'] : null;
    $result = $command === 'activate'
        ? $service->activate($registry->find((string) $source), $actor, $reason, 'cli-' . Uuid::v4())
        : $service->suspend((string) $source, $actor, (string) $reason, 'cli-' . Uuid::v4());
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
} catch (ApiException $error) {
    fwrite(STDERR, $error->errorCode . ': ' . $error->getMessage() . "\n");
    exit(3);
}
