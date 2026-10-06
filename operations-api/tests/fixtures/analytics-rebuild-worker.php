<?php
declare(strict_types=1);
use Arasya\Operations\Tests\OperationsTestSupport as T;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Analytics\AnalyticsCapture;
require dirname(__DIR__,2).'/bootstrap.php';
require dirname(__DIR__).'/OperationsTestSupport.php';
$job=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
if (!str_contains(strtolower($job['db']),'test')) throw new RuntimeException('Disposable test database required');
$pdo=Connection::create(T::config($job['db']));
$pdo->beginTransaction();
try {
    // Exactly the same canonical lock + projection transaction used by the official rebuild.
    (new AnalyticsCapture($pdo))->refreshOrder($job['orderId']);
    echo "locked\n"; flush();
    usleep(300000);
    $pdo->commit();
    echo "committed\n";
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); exit(1); }
