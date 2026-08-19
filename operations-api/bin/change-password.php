<?php

declare(strict_types=1);

use Arasya\Operations\Support\CliInput;
use Arasya\Operations\Support\Uuid;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['employee::']);
$employeeUuid = is_string($options['employee'] ?? null) ? $options['employee'] : CliInput::prompt('Employee UUID');
$password = CliInput::secret('New password');
$confirmation = CliInput::secret('Confirm password');
if (!hash_equals($password, $confirmation)) {
    throw new RuntimeException('Password confirmation does not match.');
}
$container->employeeAdmin()->changePassword($employeeUuid, $password, 'cli-' . Uuid::v4());
fwrite(STDOUT, "Password changed and sessions revoked for {$employeeUuid}.\n");

