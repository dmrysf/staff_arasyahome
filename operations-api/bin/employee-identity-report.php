<?php

declare(strict_types=1);

// Read-only inventory of employee identities for an IAM reconciliation. Prints no password hash, token,
// cookie or session secret. Usage: php bin/employee-identity-report.php --username=a --username=b
use Arasya\Operations\Employee\EmployeeRetirement;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['username:']);
$usernames = array_values(array_filter((array) ($options['username'] ?? []), 'is_string'));
if ($usernames === []) {
    fwrite(STDERR, "Usage: php bin/employee-identity-report.php --username=<username> [--username=<username> ...]\n");
    exit(2);
}
$pdo = $container->pdo();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->beginTransaction();
$retirement = new EmployeeRetirement($pdo, $container->employeeAdmin(), new \Arasya\Operations\Iam\IamAuditLogger($pdo), $container->clock());
$supervisors = (new \Arasya\Operations\Quality\TailoringSupervisorPolicy($pdo, $container->clock()))->appointedUuids();
$report = [];
foreach ($usernames as $username) {
    $inventory = $retirement->inventory($username);
    if ($inventory !== null) {
        $inventory['tailoringQualitySupervisorNow'] = in_array($inventory['employee']['employee_uuid'], $supervisors, true);
    }
    $report[$username] = $inventory;
}
$pdo->rollBack();
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
