<?php

declare(strict_types=1);

use Arasya\Operations\Support\CliInput;
use Arasya\Operations\Support\Uuid;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['employee::', 'status::']);
$employeeUuid = is_string($options['employee'] ?? null) ? $options['employee'] : CliInput::prompt('Employee UUID');
$status = is_string($options['status'] ?? null) ? $options['status'] : CliInput::prompt('Disabled status', 'inactive');
if (!in_array($status, ['inactive', 'suspended'], true)) {
    throw new RuntimeException('Disable status must be inactive or suspended.');
}
$container->employeeAdmin()->setStatus($employeeUuid, $status, 'cli-' . Uuid::v4());
fwrite(STDOUT, "Employee {$employeeUuid} is {$status}; active sessions were revoked.\n");

