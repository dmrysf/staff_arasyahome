<?php

declare(strict_types=1);

// Break-glass recovery for the root identity: issues a new one-time password, forces a change at the
// next login and revokes every root session. Server CLI only; no HTTP route reaches this.

use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Support\CliInput;
use Arasya\Operations\Support\Uuid;

$container = require __DIR__ . '/cli-bootstrap.php';
if (!in_array('--confirm', $argv, true) && CliInput::prompt('Type RECOVER to replace the root password') !== 'RECOVER') {
    fwrite(STDERR, "Cancelled.\n");
    exit(1);
}
$password = (new RootBootstrapService($container->pdo(), $container->passwordHasher(), $container->clock()))->recoverPassword('cli-' . Uuid::v4());
fwrite(STDOUT, "Root password replaced; all root sessions were revoked.\n");
fwrite(STDOUT, "One-time password (shown once, change it at the next login): {$password}\n");
