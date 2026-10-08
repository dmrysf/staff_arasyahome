<?php
declare(strict_types=1);
// One concurrent signed source print against the integration database (Trendhome document enforce).
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/OperationsTestSupport.php';
[, $db, $orderId, $label, $start] = $argv;
$kernel = T::kernel(T::config($db, [T::ORIGIN, 'http://127.0.0.1:4174'], ['trendhome' => ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce'], 'outletperdele' => ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce']]));
while (microtime(true) < (float) $start) usleep(500);
$r = T::ingest($kernel, 'trendhome', ['orderId' => $orderId, 'issue' => true, 'revisionNumber' => null, 'actor' => ['id' => 9, 'name' => 'Worker ' . $label]], null, null, 'orders/document');
echo json_encode(['status' => $r['status'], 'error' => $r['body']['error']['code'] ?? null, 'issued' => $r['body']['document']['issued'] ?? null, 'printNumber' => $r['body']['document']['printNumber'] ?? null], JSON_THROW_ON_ERROR);
