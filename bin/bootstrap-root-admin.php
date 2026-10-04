<?php

declare(strict_types=1);

// Creates the single protected root identity "arasya.root.owner" and prints its one-time initial
// password exactly once. Refuses to run when a root identity already exists. CLI only.

use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Support\Uuid;

$container = require __DIR__ . '/cli-bootstrap.php';
$password = (new RootBootstrapService($container->pdo(), $container->passwordHasher(), $container->clock()))->bootstrap('cli-' . Uuid::v4());
fwrite(STDOUT, "Root identity created.\n");
fwrite(STDOUT, 'Username: ' . RootBootstrapService::ROOT_USERNAME . "\n");
fwrite(STDOUT, "Initial password (shown once, change it at first login): {$password}\n");
