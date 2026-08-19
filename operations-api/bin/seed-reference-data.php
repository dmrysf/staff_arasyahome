<?php

declare(strict_types=1);

use Arasya\Operations\Database\SqlFileRunner;

$container = require __DIR__ . '/cli-bootstrap.php';
$runner = new SqlFileRunner($container->pdo());
$files = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
    $runner->run($file);
    fwrite(STDOUT, 'Applied reference seed: ' . basename($file) . PHP_EOL);
}

