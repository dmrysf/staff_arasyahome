<?php

declare(strict_types=1);

// Confirmare workflow cohort of the signed WooCommerce sources (API 2.37.0).
//
//   php bin/workflow-cohort.php --source=trendhome --status          read-only: activation state and history
//   php bin/workflow-cohort.php [--source=trendhome] --diagnose      read-only: cohort counts and the orders a person must
//                                                                    review (transition window, undetermined, conflicts)
//   php bin/workflow-cohort.php --source=trendhome --readiness       read-only: every readiness check of the source and
//                                                                    which ones fail (API 2.45.0, ConfirmareReadiness)
//   php bin/workflow-cohort.php --source=trendhome --schedule --at=2026-11-02T07:00:00Z --actor="Name" [--reason="..."]
//   php bin/workflow-cohort.php --source=trendhome --activate --actor="Name"     commits the scheduled instant
//   php bin/workflow-cohort.php --source=trendhome --cancel --actor="Name" --reason="..."
//   php bin/workflow-cohort.php --source=trendhome --suspend --actor="Name" --reason="..."
//   php bin/workflow-cohort.php --source=trendhome --resume --actor="Name" [--reason="..."]
//
// The first activation is two-phase. --schedule records a future instant (at least 15 minutes ahead); every source
// heartbeat then reports it and YD SOFT arms its local fence for it and reports that back. --activate commits the
// instant only after that report and still before the instant, so no order can reach the legacy flow in between.
// After the first activation an order submitted since then is a Confirmare order for good: --suspend holds new orders
// (closed, not distributed) and --resume ends that; neither hands an order back to the legacy flow.
// API 2.45.0: every activation step (schedule, activate, resume) is refused until the source's readiness passes
// (ConfirmareReadiness: schema, permission and authorities, ingestion, operators, source access, finance with four eyes,
// the production document, invoicing when the source invoices through Arasya, and the shop's signed readiness report).
// One source can be activated while another stays off. Status, diagnosis and readiness open a read-only transaction and
// print no customer data (order numbers and global ids only).

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Production\ConfirmareReadiness;
use Arasya\Operations\Production\WorkflowCohortActivation;
use Arasya\Operations\Support\Uuid;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['source:', 'status', 'diagnose', 'readiness', 'schedule', 'at:', 'activate', 'cancel', 'suspend', 'resume', 'actor:', 'reason:']);
$commands = array_values(array_intersect(['status', 'diagnose', 'readiness', 'schedule', 'activate', 'cancel', 'suspend', 'resume'], array_keys($options)));
$source = is_string($options['source'] ?? null) ? $options['source'] : null;
if (count($commands) !== 1 || ($source === null && $commands[0] !== 'diagnose') || ($source !== null && preg_match(SourceRegistry::KEY_PATTERN, $source) !== 1)) {
    fwrite(STDERR, "Usage: php bin/workflow-cohort.php [--source=<key>] --status|--diagnose|--readiness|--schedule --at=<ISO>|--activate|--cancel|--suspend|--resume [--actor=\"Name\"] [--reason=\"...\"]\n");
    exit(2);
}
$command = $commands[0];
$registry = SourceRegistry::fromConfig($container->config());
$pdo = $container->pdo();
$readiness = new ConfirmareReadiness($pdo, SourceAccessRegistry::fromConfig($container->config()), $container->employeeRepository(), new AuthorizationService());
$service = new WorkflowCohortActivation($pdo, $container->clock(), $readiness);
try {
    if ($command === 'readiness') {
        if ($source === null) {
            fwrite(STDERR, "--readiness needs --source.\n");
            exit(2);
        }
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        $report = $readiness->evaluate($registry->find($source), $container->clock()->now());
        $pdo->rollBack();
        fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        exit($report['ready'] ? 0 : 1);
    }
    if ($command === 'status' || $command === 'diagnose') {
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        $keys = $source !== null ? [$source] : ['trendhome', 'outletperdele'];
        $report = [];
        foreach ($keys as $key) {
            $diagnosis = $service->diagnose($key, $registry->find($key));
            $report[] = $command === 'status'
                ? array_intersect_key($diagnosis, array_flip(['sourceKey', 'businessCutover', 'state', 'activatedAt', 'scheduledAt', 'sourceArming', 'permitted', 'configMismatch', 'workflowReady', 'readiness', 'history', 'ordersByCohort', 'awaitingConfirmation']))
                : $diagnosis;
        }
        $pdo->rollBack();
        fwrite(STDOUT, json_encode(count($report) === 1 ? $report[0] : $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    }
    $actor = is_string($options['actor'] ?? null) ? $options['actor'] : '';
    $reason = is_string($options['reason'] ?? null) ? $options['reason'] : null;
    $requestId = 'cli-' . Uuid::v4();
    $definition = $registry->find((string) $source);
    $result = match ($command) {
        'schedule' => $service->schedule($definition, (static function (mixed $at): DateTimeImmutable {
            if (!is_string($at) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?Z$/D', $at) !== 1) {
                throw new ApiException(422, 'INVALID_INSTANT', 'Give --at as a UTC instant, for example 2026-11-02T07:00:00Z.');
            }
            return new DateTimeImmutable($at);
        })($options['at'] ?? null), $actor, $reason, $requestId),
        'activate' => $service->activate($definition, $actor, $reason, $requestId),
        'cancel' => $service->cancelSchedule((string) $source, $actor, (string) $reason, $requestId),
        'suspend' => $service->suspend((string) $source, $actor, (string) $reason, $requestId),
        default => $service->resume($definition, $actor, $reason, $requestId),
    };
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
} catch (ApiException $error) {
    fwrite(STDERR, $error->errorCode . ': ' . $error->getMessage() . "\n");
    exit(3);
}
