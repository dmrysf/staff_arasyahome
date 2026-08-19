<?php

declare(strict_types=1);

use Arasya\Operations\Support\CliInput;
use Arasya\Operations\Support\Uuid;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['display-name::', 'username::', 'employee-code::', 'department::', 'role::', 'stages::']);
$displayName = is_string($options['display-name'] ?? null) ? $options['display-name'] : CliInput::prompt('Display name');
$username = is_string($options['username'] ?? null) ? $options['username'] : CliInput::prompt('Username');
$employeeCode = is_string($options['employee-code'] ?? null) ? $options['employee-code'] : CliInput::prompt('Employee code (optional)', '');
$department = is_string($options['department'] ?? null) ? $options['department'] : CliInput::prompt('Department key', 'pregatire-material');
$role = is_string($options['role'] ?? null) ? $options['role'] : CliInput::prompt('Role key', 'employee');
$stageInput = is_string($options['stages'] ?? null) ? $options['stages'] : CliInput::prompt('Allowed stage IDs (comma separated)', '');
$password = CliInput::secret('Initial password');
$confirmation = CliInput::secret('Confirm password');
if (!hash_equals($password, $confirmation)) {
    throw new RuntimeException('Password confirmation does not match.');
}

$employee = $container->employeeAdmin()->create(
    $displayName,
    $username,
    $employeeCode === '' ? null : $employeeCode,
    $department,
    $role,
    $password,
    array_values(array_filter(array_map('trim', explode(',', $stageInput)))),
    'cli-' . Uuid::v4(),
);
fwrite(STDOUT, "Employee created: {$employee->employeeUuid} ({$employee->username})\n");

