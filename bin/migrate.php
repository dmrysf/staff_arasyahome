<?php

declare(strict_types=1);

use Arasya\Operations\Database\MigrationRunner;

$container = require __DIR__ . '/cli-bootstrap.php';
$applied = (new MigrationRunner($container->pdo()))->migrate(dirname(__DIR__) . '/database/migrations');
fwrite(STDOUT, $applied === [] ? "Database schema is current.\n" : 'Applied: ' . implode(', ', $applied) . PHP_EOL);

