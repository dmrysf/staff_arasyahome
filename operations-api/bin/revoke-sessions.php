<?php

declare(strict_types=1);

use Arasya\Operations\Support\CliInput;
use Arasya\Operations\Support\Uuid;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['employee::']);
$employeeUuid = is_string($options['employee'] ?? null) ? $options['employee'] : CliInput::prompt('Employee UUID');
$count = $container->employeeAdmin()->revokeSessions($employeeUuid, 'cli-' . Uuid::v4());
fwrite(STDOUT, "Revoked {$count} active session(s) for {$employeeUuid}.\n");

