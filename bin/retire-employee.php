<?php

declare(strict_types=1);

// Retires one duplicate employee identity from the server console. Dry run unless --apply is given.
//   php bin/retire-employee.php --employee=<uuid> --username=<username>                   plan only
//   php bin/retire-employee.php --employee=<uuid> --username=<username> --apply           disable + revoke sessions
//   php bin/retire-employee.php --employee=<uuid> --username=<username> --apply --delete --backup-dir=<private dir>
// With --delete the identity is deleted only when every reference to the UUID is an administrative
// association; otherwise it is disabled instead and the exact history references are listed as the
// blocker. History is never deleted or moved to another UUID.
use Arasya\Operations\Employee\EmployeeRetirement;
use Arasya\Operations\Support\Uuid;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['employee:', 'username:', 'apply', 'delete', 'backup-dir:']);
$employee = is_string($options['employee'] ?? null) ? $options['employee'] : '';
$username = is_string($options['username'] ?? null) ? $options['username'] : '';
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $employee) !== 1 || $username === '') {
    fwrite(STDERR, "Usage: php bin/retire-employee.php --employee=<uuid> --username=<username> [--apply [--delete --backup-dir=<dir>]]\n");
    exit(2);
}
$pdo = $container->pdo();
$retirement = new EmployeeRetirement($pdo, $container->employeeAdmin(), new \Arasya\Operations\Iam\IamAuditLogger($pdo), $container->clock());
$requestId = 'cli-retire-' . Uuid::v4();
try {
    $plan = $retirement->plan($employee, $username);
    $out = ['plan' => $plan];
    if (isset($options['apply'])) {
        if (isset($options['delete']) && $plan['deletable']) {
            // Never used: deleted directly in one audited transaction (disabling first would itself write history).
            $out['deleted'] = $retirement->delete($employee, $username, is_string($options['backup-dir'] ?? null) ? $options['backup-dir'] : '', $requestId);
        } else {
            $out['disabled'] = $retirement->disable($employee, $username, $requestId);
            if (isset($options['delete'])) {
                $out['deleted'] = null;
                $out['deletionBlockedBy'] = $plan['blockers'];
            }
        }
    }
    fwrite(STDOUT, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
