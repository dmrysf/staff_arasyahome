<?php
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
$m=json_decode((string)file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
if (($m['environment']['ARASYA_APP_ENV']??'')!=='simulation') throw new RuntimeException('SIMULATION_REQUIRED');
$count=(int)$argv[2]; if (!in_array($count,[50,200,500],true)) throw new RuntimeException('Unsupported load profile.');
$base=(int)$argv[3]; $payloads=[];
for ($i=0;$i<$count;$i++) {
    $source=$i%2===0?'trendhome':'outletperdele';
    $p=Arasya\Operations\Simulation\ScenarioCatalog::woo($i%2===0?'WOO-TH-001':'WOO-OP-001',$m['sessionId'],8000+$i,$base);
    $p['order']['id']='SIMLOAD-' . $m['sessionId'] . '-' . $count . '-' . $i;
    $p['order']['number']='TEST-LOAD-' . $count . '-' . $i;
    $p['eventId']='sim-load-' . $m['sessionId'] . '-' . $count . '-' . $i;
    $payloads[]=['source'=>$source,'payload'=>$p];
}
echo json_encode($payloads,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
