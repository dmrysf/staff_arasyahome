<?php

declare(strict_types=1);

use Arasya\Operations\Database\MigrationStatus;

$container = require __DIR__ . '/cli-bootstrap.php';
$statuses = (new MigrationStatus($container->pdo()))->inspect(dirname(__DIR__) . '/database/migrations');
foreach ($statuses as $status) {
    fwrite(STDOUT, sprintf("%s %s %s\n", $status['status'], $status['name'], $status['checksum']));
}
