<?php

declare(strict_types=1);

use Arasya\Operations\Support\CliInput;
use Arasya\Operations\Support\Uuid;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['employee::']);
$employeeUuid = is_string($options['employee'] ?? null) ? $options['employee'] : CliInput::prompt('Employee UUID');
$container->employeeAdmin()->setStatus($employeeUuid, 'active', 'cli-' . Uuid::v4());
fwrite(STDOUT, "Employee {$employeeUuid} is active and must authenticate normally.\n");

