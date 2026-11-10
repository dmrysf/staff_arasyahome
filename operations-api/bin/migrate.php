<?php

declare(strict_types=1);

use Arasya\Operations\Database\MigrationRunner;

// php bin/migrate.php [--lock-wait-timeout=SECONDS]
//
// --lock-wait-timeout (1-600) limits how long one DDL statement waits for a metadata lock in this session. Without it the
// server default applies (MariaDB and MySQL: 86400 s). An ALTER that waits for its exclusive metadata lock behind an open
// transaction also holds back every later read and write of that table, so on a live database run with a short value:
// a statement that cannot get its lock fails instead of stalling production. DDL commits on its own, so statements before
// the failed one stay applied; the file is not recorded and runs again on the next start (guarded migrations skip what
// already exists).
$timeout = null;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--lock-wait-timeout=([1-9][0-9]{0,2})$/', $argument, $match) === 1 && (int) $match[1] <= 600) {
        $timeout = (int) $match[1];
        continue;
    }
    fwrite(STDERR, "Usage: php bin/migrate.php [--lock-wait-timeout=SECONDS]   (SECONDS 1-600)\n");
    exit(2);
}

$container = require __DIR__ . '/cli-bootstrap.php';
try {
    $pdo = $container->pdo();
} catch (Throwable) {
    // Never print the connection exception: its trace carries the connection arguments.
    fwrite(STDERR, "Refused: the database connection could not be opened.\n");
    exit(1);
}
if ($timeout !== null) {
    $pdo->exec('SET SESSION lock_wait_timeout = ' . $timeout);
    fwrite(STDOUT, "Session lock_wait_timeout: {$timeout} s\n");
}
try {
    $applied = (new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration stopped: ' . $error->getMessage() . "\n"
        . "Statements before the failed one stay applied (DDL commits on its own). The file is not recorded in schema_migrations.\n"
        . "Check: php bin/migration-status.php. Fix the cause (for a lock wait: the blocking transaction), then run again.\n");
    exit(1);
}
fwrite(STDOUT, $applied === [] ? "Database schema is current.\n" : 'Applied: ' . implode(', ', $applied) . PHP_EOL);
