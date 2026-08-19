<?php

declare(strict_types=1);

use Arasya\Operations\Application\Container;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is available only through PHP CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

return new Container();

