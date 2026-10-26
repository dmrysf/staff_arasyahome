<?php

declare(strict_types=1);

// Race worker of the cancellation synchronization suite (test databases only). Waits until a shared start instant, then
// runs the outbound worker once in its own process and connection, against the Trendyol fixture file, with the server
// switch enabled, and prints the run summary as one JSON line.
//
//   php tests/fixtures/cancellation-sync-worker.php <db> <start> <fixture file> <clock offset seconds>

use Arasya\Operations\Cancellation\Sync\CancellationSyncWorker;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/OperationsTestSupport.php';

[, $db, $start, $fixture, $offset] = $argv;
$config = new Config(...[...(array) T::config($db), 'trendyolFixtureFile' => $fixture, 'cancellationSync' => 'enabled']);
$container = new \Arasya\Operations\Application\Container($config);
$clock = new class ((int) $offset) implements Clock {
    public function __construct(private int $offset)
    {
    }

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+{$this->offset} seconds");
    }
};
while (microtime(true) < (float) $start) {
    usleep(300);
}
try {
    echo json_encode((new CancellationSyncWorker($container->pdo(), $clock, $container->cancellationProviders()))->run(), JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['error' => get_class($error)], JSON_THROW_ON_ERROR);
}
