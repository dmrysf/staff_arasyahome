<?php

declare(strict_types=1);

use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\AuthMaintenance;

$arguments = array_slice($argv, 1);
if (array_diff($arguments, ['--dry-run']) !== []) {
    fwrite(STDERR, "Usage: php bin/maintenance.php [--dry-run]\n");
    exit(2);
}
$dryRun = in_array('--dry-run', $arguments, true);
$container = require __DIR__ . '/cli-bootstrap.php';
$config = Config::fromEnvironment();
$report = (new AuthMaintenance(
    $container->pdo(),
    $config->sessionRecordRetentionDays,
    $config->loginAttemptRetentionDays,
    $config->rateLimitRetentionDays,
    $config->authAuditRetentionDays,
    AuthMaintenance::DEFAULT_BATCH_SIZE,
    $config->idempotencyRetentionDays,
))->run($dryRun);

fwrite(STDOUT, ($dryRun ? 'DRY_RUN' : 'DELETED') . " sessions={$report['sessions']} login_attempts={$report['login_attempts']} rate_limit_buckets={$report['rate_limit_buckets']} idempotency_keys={$report['idempotency_keys']} api_rate_limit_buckets={$report['api_rate_limit_buckets']} b2b_idempotency_keys={$report['b2b_idempotency_keys']} b2b_order_idempotency_keys={$report['b2b_order_idempotency_keys']}\n");
fwrite(STDOUT, $report['audit_events'] === null
    ? "AUDIT_RETENTION_NOT_CONFIGURED\n"
    : ($dryRun ? 'DRY_RUN' : 'DELETED') . " audit_events={$report['audit_events']}\n");
