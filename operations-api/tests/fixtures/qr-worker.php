<?php
declare(strict_types=1);
// One concurrent production QR request against the integration database (Trendhome QR enforce).
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/OperationsTestSupport.php';
[, $db, $path, $body, $key, $cookie, $csrf, $start] = $argv;
$kernel = T::kernel(T::config($db, [T::ORIGIN, 'http://127.0.0.1:4174'], ['trendhome' => ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce'], 'outletperdele' => ['mode' => 'active', 'authority' => 'observe', 'qrAuthority' => 'observe']]));
while (microtime(true) < (float) $start) usleep(500);
$r = T::call($kernel, 'POST', $path, json_decode($body, true, 32, JSON_THROW_ON_ERROR), ['origin' => T::ORIGIN, 'x-csrf-token' => $csrf, 'idempotency-key' => $key], $cookie);
echo json_encode(['status' => $r['status'], 'error' => $r['body']['error']['code'] ?? null, 'revision' => $r['body']['active']['revision'] ?? null], JSON_THROW_ON_ERROR);
