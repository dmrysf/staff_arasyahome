<?php
// Actual parallel requests, separate PHP/PDO processes and a common start barrier.
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
use Arasya\Operations\Application\Container;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Simulation\ScenarioCatalog;
use Arasya\Operations\Simulation\ScenarioService;
$manifest=json_decode((string)file_get_contents($argv[1]??''),true,32,JSON_THROW_ON_ERROR);
foreach ($manifest['environment'] as $key=>$value) putenv($key . '=' . $value);
$container=new Container(); $pdo=$container->pdo(); $service=new ScenarioService($container); $kernel=$container->kernel();
$out=$argv[2]; if (!is_dir($out)) mkdir($out,0700,true);
$sessions=[]; $checks=[];
$check=static function(bool $pass,string $name,mixed $actual=null)use(&$checks):void { $checks[]=['id'=>$name,'result'=>$pass?'PASS':'FAIL','actual'=>$actual]; if (!$pass) throw new RuntimeException('FAIL ' . $name); };
$call=static function(string $who,string $method,string $path,array $body=[],?string $key=null)use($container,$kernel,&$sessions):array {
    $s=$sessions[$who]??null;
    $r=$kernel->handle(new Request($method,$path,['origin'=>explode(',',(string)getenv('ARASYA_ALLOWED_ORIGINS'))[0],'content-type'=>'application/json','x-csrf-token'=>$s['csrf']??'','idempotency-key'=>$key??'race-setup-' . bin2hex(random_bytes(8))],$s===null?[]:[$container->config()->cookieName()=>$s['cookie']],$method==='GET'?'':json_encode($body,JSON_THROW_ON_ERROR),'127.0.0.1','factory-race','race-setup'));
    return ['status'=>$r->status,'body'=>$r->payload,'headers'=>$r->headers];
};
$one=static function(string $sql,array $params=[])use($pdo):mixed { $s=$pdo->prepare($sql);$s->execute($params);return $s->fetchColumn(); };
$parallel=static function(array $calls,string $name)use($manifest,$out,&$sessions,$check,$pdo):array {
    $barrier=$out . '/' . $name . '.barrier'; if (file_exists($barrier)) unlink($barrier);
    $workers=[]; $markers=[];
    $path=$calls[0][1];
    if (preg_match('#^/orders/([^/]+)/#',$path,$match)) {
        $pdo->beginTransaction(); $q=$pdo->prepare('SELECT order_uuid FROM operational_orders WHERE global_order_id=? FOR UPDATE'); $q->execute([rawurldecode($match[1])]);
    } elseif (preg_match('#^/trendyol/packages/(\d+)/#',$path,$match)) {
        $pdo->beginTransaction(); $q=$pdo->prepare('SELECT package_id FROM trendyol_packages WHERE package_id=? FOR UPDATE'); $q->execute([$match[1]]);
    }

    foreach ($calls as $i=>[$who,$path,$body]) {
        $ready=$barrier . '.' . $i . '.ready'; $started=$barrier . '.' . $i . '.started';
        foreach ([$ready,$started] as $marker) if (file_exists($marker)) unlink($marker);
        $markers[]=[$ready,$started];
        $process=proc_open([PHP_BINARY,__DIR__ . '/race-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$out . '/' . $name . '-' . $i . '.private.log','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('SIMULATION_WORKER_FAILED');
        fwrite($pipes[0],json_encode(['environment'=>$manifest['environment'],'barrier'=>$barrier,'ready'=>$ready,'started'=>$started,'path'=>$path,'body'=>$body,'csrf'=>$sessions[$who]['csrf'],'cookie'=>$sessions[$who]['cookie'],'key'=>'parallel-' . $name . '-' . $i],JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        $workers[]=[$process,$pipes];
    }
    // Both workers have independent requests; barrier release admits an allowed outcome set.
    $deadline=microtime(true)+10;
    foreach ($markers as [$ready,$started]) while (!is_file($ready)) { if (microtime(true)>$deadline) throw new RuntimeException('SIMULATION_WORKER_NOT_READY'); usleep(1000); }
    touch($barrier);
    foreach ($markers as [$ready,$started]) while (!is_file($started)) { if (microtime(true)>$deadline) throw new RuntimeException('SIMULATION_WORKER_NOT_STARTED'); usleep(1000); }
    usleep(20000);
    if ($pdo->inTransaction()) $pdo->commit();
    $results=[];
    foreach ($workers as [$process,$pipes]) {
        $raw=stream_get_contents($pipes[1]); fclose($pipes[1]); $exit=proc_close($process);
        $check($exit===0,$name . '-worker-exit'); $results[]=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    }
    unlink($barrier); foreach ($markers as $files) foreach ($files as $file) unlink($file);
    $check(count(array_unique(array_column($results,'pid')))===2,$name . '-separate-processes');
    $check(max(array_column($results,'startedAt')) < min(array_column($results,'endedAt')),$name . '-requests-overlap');
    return $results;
};
try {
    foreach (['waiting','trendyol-approver','trendyol-preparer'] as $who) {
        $a=$manifest['accounts'][$who]; $r=$call('','POST','/auth/login',['username'=>$a['username'],'password'=>$a['password']]);
        $check($r['status']===200,'login-' . $who); preg_match('/^arasya_sim_session=([^;]+)/',$r['headers']['Set-Cookie'],$m);
        $sessions[$who]=['cookie'=>rawurldecode($m[1]),'csrf'=>$r['body']['csrfToken']];
    }
    $env=$pdo->query('SELECT * FROM simulation_environment')->fetch();
    $payload=ScenarioCatalog::woo('WOO-TH-001',$manifest['sessionId'],9991,(int)$env['baseline_ms']);
    $ingested=$service->ingest('trendhome',$payload); $global=$ingested['body']['globalOrderId'];
    $results=$parallel([['waiting','/orders/' . rawurlencode($global) . '/claim',['expectedVersion'=>1]],['waiting','/orders/' . rawurlencode($global) . '/claim',['expectedVersion'=>1]]],'claim-two-devices');
    $statuses=array_column($results,'status'); sort($statuses); $check($statuses===[200,409],'claim-single-winner',$results);
    $check((int)$one('SELECT production_version FROM operational_orders WHERE global_order_id=?',[$global])===2,'claim-one-version');
    $check((int)$one("SELECT COUNT(*) FROM order_activity_events WHERE global_order_id=? AND action='claimed'",[$global])===1,'claim-one-audit');
    $results=$parallel([['waiting','/orders/' . rawurlencode($global) . '/transition',['expectedVersion'=>2]],['waiting','/orders/' . rawurlencode($global) . '/transition',['expectedVersion'=>2]]],'transition-two-devices');
    $statuses=array_column($results,'status'); sort($statuses); $check($statuses===[200,409],'transition-single-winner',$results);
    $check($one('SELECT production_stage_id FROM operational_orders WHERE global_order_id=?',[$global])==='material-preparation','transition-no-skip');
    $payload=ScenarioCatalog::trendyol('TY-01',$manifest['sessionId'],9991,(int)$env['baseline_ms']);
    $id=(string)$payload['shipmentPackageId'];
    (new Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore($pdo,$container->projectionWriter(),$container->clock()))->record(Arasya\Operations\Integration\Trendyol\TrendyolPackage::fromApi($payload),(int)$env['baseline_ms']);
    $fixture=json_decode((string)file_get_contents($container->config()->trendyolFixtureFile),true,32,JSON_THROW_ON_ERROR); $fixture['packages'][]=$payload;
    file_put_contents($container->config()->trendyolFixtureFile,json_encode($fixture,JSON_THROW_ON_ERROR));
    $r=$call('trendyol-preparer','PUT','/trendyol/packages/' . $id . '/lines/' . $payload['lines'][0]['lineId'],['expectedVersion'=>1,'kind'=>'curtain','productionTitle'=>'TEST Perdea Alb','widthCm'=>100,'heightCm'=>250]);
    $check($r['status']===200,'release-preparation');
    // Two DIFFERENT approvers, two independent GET verifications and two DB transactions.
    $results=$parallel([['waiting','/trendyol/packages/' . $id . '/release',['expectedVersion'=>2,'confirm'=>true]],['trendyol-approver','/trendyol/packages/' . $id . '/release',['expectedVersion'=>2,'confirm'=>true]]],'release-two-approvers');
    $statuses=array_column($results,'status');sort($statuses);$check($statuses===[201,409],'release-single-winner',$results);
    $check((int)$one('SELECT COUNT(*) FROM operational_orders WHERE source_key=\'trendyol\' AND source_order_id=?',[$id])===1,'release-one-canonical-order');
    $check((int)$one("SELECT COUNT(*) FROM order_qr_references q JOIN operational_orders o ON o.order_uuid=q.order_uuid WHERE o.source_key='trendyol' AND o.source_order_id=? AND q.status='active'",[$id])===1,'release-one-active-qr');
    $check((int)$one("SELECT COUNT(*) FROM production_document_revisions r JOIN operational_orders o ON o.order_uuid=r.order_uuid WHERE o.source_key='trendyol' AND o.source_order_id=? AND r.status='active'",[$id])===1,'release-one-active-document');
} catch (Throwable $error) { $failure=$error instanceof RuntimeException? $error->getMessage():get_class($error); }
file_put_contents($out . '/races.json',json_encode(['environmentId'=>$manifest['sessionId'],'results'=>$checks,'error'=>$failure??null],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo json_encode(['checks'=>count($checks),'error'=>$failure??null],JSON_THROW_ON_ERROR) . "\n";
exit(isset($failure)?1:0);
