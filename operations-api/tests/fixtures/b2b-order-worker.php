<?php
declare(strict_types=1);
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__,2).'/bootstrap.php';
require dirname(__DIR__).'/OperationsTestSupport.php';
[, $db,$method,$path,$body,$key,$cookie,$csrf,$start]=$argv;
$origin=$argv[9]??null; // optional: Dashboard routes need their allowed origin
$kernel=T::kernel($origin===null?T::config($db):T::config($db,[T::ORIGIN,$origin]));
while(microtime(true)<(float)$start) usleep(500);
$r=T::call($kernel,$method,$path,json_decode($body,true,32,JSON_THROW_ON_ERROR),
    ['x-csrf-token'=>$csrf,'idempotency-key'=>$key]+($origin===null?[]:['origin'=>$origin]),$cookie);
echo json_encode(['status'=>$r['status'],'error'=>$r['body']['error']['code']??null,
    'orderId'=>$r['body']['orderId']??null,'code'=>$r['body']['detail']['order']['code']??null],JSON_THROW_ON_ERROR);
