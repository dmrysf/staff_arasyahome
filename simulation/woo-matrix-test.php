<?php
// Deterministic boundary matrix. HTTP and database results, with no invented manufacturing limits.
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Simulation\ScenarioCatalog;
use Arasya\Operations\Simulation\ScenarioService;
$m=json_decode((string)file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR); foreach ($m['environment'] as $k=>$v) putenv($k . '=' . $v);
$c=new Container(); $pdo=$c->pdo(); $env=$pdo->query('SELECT * FROM simulation_environment')->fetch(); $results=[]; $seq=0;
$cases=[
 ['metre',['width'=>1.8,'height'=>2.5,'unit'=>'m'],200],['centimetre',['width'=>180,'height'=>250,'unit'=>'cm'],200],['millimetre',['width'=>1800,'height'=>2500,'unit'=>'mm'],200],
 ['width-only',['width'=>1.8,'height'=>null],422],['height-only',['width'=>null,'height'=>2.5],422],['zero-custom-rejected',['width'=>0],422],
 ['minimum-precision-current-contract',['width'=>0.001],200],['maximum-current-contract',['width'=>1000000],200],
 ['negative',['width'=>-1],422],['over-contract-maximum',['width'=>1000001],422],['numeric-string-wire-invalid',['width'=>'1.8'],422],['decimal-comma-wire-invalid',['width'=>'1,80'],422],['empty-string-wire-invalid',['width'=>''],422],['nonnumeric',['width'=>'fabric'],422],['unsupported-unit',['unit'=>'inch'],422],
 ['quantity-one',['quantity'=>1],200],['quantity-two',['quantity'=>2],200],['quantity-five',['quantity'=>5],200],['quantity-maximum',['quantity'=>1000000],200],
 ['quantity-zero',['quantity'=>0],422],['quantity-negative',['quantity'=>-1],422],['quantity-fractional',['quantity'=>1.5],422],['quantity-over-maximum',['quantity'=>1000001],422],
];
try {
 foreach (['legacy','observe','enforce'] as $mode) {
    $config=new Config(...array_replace(get_object_vars($c->config()),['sourceSettings'=>['trendhome'=>['mode'=>'active','authority'=>$mode],'outletperdele'=>['mode'=>'active','authority'=>$mode]]]));
    $service=new ScenarioService(new Container($config,$pdo));
    foreach (['trendhome'=>'WOO-TH-001','outletperdele'=>'WOO-OP-001'] as $source=>$scenario) foreach ($cases as [$case,$change,$expected]) {
        $payload=ScenarioCatalog::woo($scenario,$m['sessionId'],6000+ ++$seq,(int)$env['baseline_ms']);
        $payload['order']['id']='SIM-MATRIX-' . $m['sessionId'] . '-' . $seq; $payload['eventId']='sim-matrix-' . $m['sessionId'] . '-' . $seq;
        $payload['order']['number']='TEST-MATRIX-' . $seq; $payload['order']['items'][0]=array_replace($payload['order']['items'][0],$change);
        $r=$service->ingest($source,$payload); $pass=$r['status']===$expected; $actual=['http'=>$r['status'],'code'=>$r['body']['error']['code']??null];
        $q=$pdo->prepare('SELECT o.order_uuid,o.production_authority,o.production_stage_id FROM operational_orders o WHERE o.source_key=? AND o.source_order_id=?');$q->execute([$source,$payload['order']['id']]);$order=$q->fetch();
        if ($expected===422) $pass=$pass && $order===false;
        else {
            $pass=$pass && is_array($order) && $order['production_authority']===($mode==='enforce'?'operations':'source') && $order['production_stage_id']==='waiting';
            $q=$pdo->prepare('SELECT width_value,height_value,measurement_unit,quantity FROM operational_order_items WHERE order_uuid=? AND line_number=1');$q->execute([$order['order_uuid']]);$item=$q->fetch();
            foreach (['width'=>'width_value','height'=>'height_value'] as $field=>$column) $pass=$pass && (($payload['order']['items'][0][$field]??null)===null ? $item[$column]===null : abs((float)$item[$column]-(float)$payload['order']['items'][0][$field])<0.00001);
            $pass=$pass && $item['measurement_unit']===($payload['order']['items'][0]['unit']??null) && (int)$item['quantity']===$payload['order']['items'][0]['quantity'];
        }
        $results[]=['id'=>$source . '-' . $mode . '-' . $case,'result'=>$pass?'PASS':'FAIL','expectedHttp'=>$expected,'actual'=>$actual];
        if (!$pass) throw new RuntimeException('FAIL ' . $case);
    }
 }
} catch(Throwable $e) { $failure=$e instanceof RuntimeException?$e->getMessage():get_class($e); }
$out=$argv[2]; if (!is_dir($out)) mkdir($out,0700,true);
file_put_contents($out . '/woo-matrix.json',json_encode(['environmentId'=>$m['sessionId'],'results'=>$results,'error'=>$failure??null,
'limits'=>'Explicit custom fabrics reject zero, missing and one-sided dimensions; valid units and quantities are preserved. Legacy unclassified contracts require an explicit live rollout and are not readiness acceptance evidence.'],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo json_encode(['checks'=>count($results),'error'=>$failure??null],JSON_THROW_ON_ERROR) . "\n";exit(isset($failure)?1:0);
