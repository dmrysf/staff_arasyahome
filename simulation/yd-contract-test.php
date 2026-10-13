<?php
// Executes the ACTUAL YD SOFT mapper and signer from its separately checked-out repository.
// Never loads its ApiClient: that client is hard-pinned to production by design.
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
require __DIR__ . '/woocommerce-fixtures.php';
use Arasya\Operations\Application\Container;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Simulation\ScenarioCatalog;
use YdSoft\Enterprise\Integration\Arasya\OrderPayloadMapper;
use YdSoft\Enterprise\Integration\Arasya\RequestSigner;
if (PHP_SAPI!=='cli' || $argc!==4) throw new RuntimeException('Usage: manifest yd-soft-root report-directory');
$manifest=json_decode((string)file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
foreach ($manifest['environment'] as $key=>$value) putenv($key . '=' . $value);
$yd=realpath($argv[2]);
if ($yd===false || !is_file($yd . '/includes/Integration/Arasya/OrderPayloadMapper.php')) throw new RuntimeException('YD_SOFT_REAL_CHECKOUT_REQUIRED');
require $yd . '/includes/Integration/Arasya/OrderPayloadMapper.php'; require $yd . '/includes/Integration/Arasya/RequestSigner.php';
$container=new Container(); $pdo=$container->pdo(); $kernel=$container->kernel(); $results=[];
$check=static function(bool $pass,string $id)use(&$results):void { $results[]=['id'=>$id,'result'=>$pass?'PASS':'FAIL']; if (!$pass) throw new RuntimeException('FAIL ' . $id); };
try {
    foreach (ScenarioCatalog::WOO as $id=>[$source,$title,$specs]) {
        $payload=OrderPayloadMapper::map(ScenarioCatalog::wooOrder($id,$manifest['sessionId'],9100),'sim-yd-' . $id,'2026-10-13T12:00:00Z');
        $check(!isset($payload['production']),$id . '-no-stage-mapping');
        $check(count($payload['order']['items'])===count($specs),$id . '-line-count');
        foreach ($specs as $index=>[$name,$color,$quantity,$width,$height]) {
            $item=$payload['order']['items'][$index];
            $check($item['quantity']===$quantity,$id . '-quantity-' . $index);
            $check(($item['color']??null)===$color,$id . '-variation-' . $index);
            $check($width===null ? !isset($item['width'],$item['height'],$item['unit']) : $item['width']===(float)str_replace(',','.',$width) && $item['height']===(float)str_replace(',','.',$height) && $item['unit']==='m',$id . '-measurements-' . $index);
            // YD's current mapper sends piece/labor options, not calculated fabric consumption.
            $check(!array_key_exists('meters',$item),$id . '-no-invented-meters-' . $index);
            if ($width!==null) $check($item['options']===[['label'=>'buc','value'=>'2 buc.'],['label'=>'Manopera','value'=>'Rejansă 10 cm']],$id . '-manufacturing-options-' . $index);
        }
        $body=OrderPayloadMapper::encode($payload); $timestamp=(string)$container->clock()->now()->getTimestamp();
        $r=$kernel->handle(new Request('POST','/integrations/sources/' . $source . '/orders',[
            'content-type'=>'application/json','x-arasya-timestamp'=>$timestamp,'x-arasya-signature'=>RequestSigner::sign($container->config()->sourceSecrets[$source],$timestamp,$body)],[],$body,'127.0.0.1','yd-fixture-test','sim-yd-' . $id));
        $check($r->status===200 && ($r->payload['outcome']??'')==='applied',$id . '-signed-real-yd-ingestion');
        $q=$pdo->prepare('SELECT COUNT(*) FROM operational_order_items i JOIN operational_orders o ON o.order_uuid=i.order_uuid WHERE o.global_order_id=?'); $q->execute([$r->payload['globalOrderId']]);
        $check((int)$q->fetchColumn()===count($specs),$id . '-database-lines');
    }
} catch (Throwable $e) { $failure=$e instanceof RuntimeException? $e->getMessage():get_class($e); }
$out=$argv[3]; if (!is_dir($out)) mkdir($out,0700,true);
file_put_contents($out . '/yd-contract.json',json_encode(['ydMapperSha256'=>hash_file('sha256',$yd . '/includes/Integration/Arasya/OrderPayloadMapper.php'),'results'=>$results,'error'=>$failure??null],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo json_encode(['checks'=>count($results),'error'=>$failure??null],JSON_THROW_ON_ERROR) . "\n"; exit(isset($failure)?1:0);
