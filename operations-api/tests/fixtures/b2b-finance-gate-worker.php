<?php
declare(strict_types=1);
// One HTTP mutation on its own database connection, against a kernel whose B2B finance gate is switched on.
// Synthetic test databases only (OperationsTestSupport refuses any other database name).
use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__,2).'/bootstrap.php';
require dirname(__DIR__).'/OperationsTestSupport.php';
[, $db,$method,$path,$body,$key,$cookie,$csrf,$start,$authority,$mode]=$argv;
$config=T::config($db);
$args=[];
foreach((new ReflectionClass(Config::class))->getConstructor()->getParameters() as $parameter) $args[$parameter->getName()]=$config->{$parameter->getName()};
$config=new Config(...array_replace($args,['financeRiskLimitAuthorityUuids'=>$authority,'b2bFinanceGate'=>$mode]));
$kernel=(new Container($config,Connection::create($config)))->kernel();
while(microtime(true)<(float)$start) usleep(500);
$r=T::call($kernel,$method,$path,json_decode($body,true,32,JSON_THROW_ON_ERROR),['x-csrf-token'=>$csrf,'idempotency-key'=>$key],$cookie);
echo json_encode(['status'=>$r['status'],'error'=>$r['body']['error']['code']??null,'reasons'=>$r['body']['error']['details']['reasons']??null,
    'orderId'=>$r['body']['orderId']??null],JSON_THROW_ON_ERROR);
