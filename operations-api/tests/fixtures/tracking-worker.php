<?php
declare(strict_types=1);
// Concurrent signed customer tracking reads against the integration database (Trendhome tracking enforce).
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/OperationsTestSupport.php';
[, $db, $start, $rounds] = $argv;
$kernel = T::kernel(T::config($db, [T::ORIGIN, 'http://127.0.0.1:4174'], ['trendhome' => ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'trackingAuthority' => 'enforce'], 'outletperdele' => ['mode' => 'active', 'authority' => 'enforce']]));
while (microtime(true) < (float) $start) usleep(500);
$ok = 0;
$hashes = [];
for ($i = 0; $i < (int) $rounds; $i++) {
    $r = T::ingest($kernel, 'trendhome', ['orderIds' => ['8101', '8102', '999999']], null, null, 'orders/tracking');
    if ($r['status'] === 200) {
        $ok++;
        $hashes[md5((string) json_encode($r['body']['orders']))] = true;
    }
}
echo json_encode(['ok' => $ok, 'hash' => count($hashes) === 1 ? array_key_first($hashes) : 'mixed'], JSON_THROW_ON_ERROR);
