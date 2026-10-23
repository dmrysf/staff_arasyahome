<?php

declare(strict_types=1);

use Arasya\Operations\Application\Container;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is available only through PHP CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

try {
    return new Container();
} catch (Throwable $error) {
    // Never let PHP print the uncaught trace: it carries the configuration constructor arguments (the first characters of
    // the database password and the application secret). Configuration messages name the variable, never its value.
    $fromConfig = $error instanceof RuntimeException && str_ends_with(str_replace('\\', '/', $error->getFile()), '/src/Config/Config.php');
    fwrite(STDERR, 'Refused: ' . ($fromConfig ? $error->getMessage() : 'the service configuration could not be loaded.') . "\n");
    exit(1);
}

