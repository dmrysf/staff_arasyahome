<?php
declare(strict_types=1);
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__,2).'/bootstrap.php';
require dirname(__DIR__).'/OperationsTestSupport.php';
[, $db,$method,$path,$body,$key,$cookie,$csrf,$start]=$argv;
$kernel=T::kernel(T::config($db));
while(microtime(true)<(float)$start) usleep(500);
$r=T::call($kernel,$method,$path,json_decode($body,true,32,JSON_THROW_ON_ERROR),
    ['x-csrf-token'=>$csrf,'idempotency-key'=>$key],$cookie);
echo json_encode(['status'=>$r['status'],'error'=>$r['body']['error']['code']??null,
    'orderId'=>$r['body']['orderId']??null,'code'=>$r['body']['detail']['order']['code']??null],JSON_THROW_ON_ERROR);
