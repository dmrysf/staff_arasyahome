<?php

declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
use Arasya\Operations\Application\Container;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Simulation\ScenarioCatalog;
use Arasya\Operations\Simulation\ScenarioService;
use Arasya\Operations\Integration\Trendyol\TrendyolPackage;
use Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore;

if (PHP_SAPI!=='cli' || $argc!==3) throw new RuntimeException('Usage: php simulation/factory-test.php private-manifest report-directory');
$manifest=json_decode((string)file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
if (($manifest['environment']['ARASYA_APP_ENV']??'')!=='simulation') throw new RuntimeException('SIMULATION_ENV_REQUIRED');
foreach ($manifest['environment'] as $k=>$v) putenv($k . '=' . $v);
$container=new Container(); $pdo=$container->pdo(); $kernel=$container->kernel(); $service=new ScenarioService($container);
$report=['environmentId'=>$manifest['sessionId'],'date'=>gmdate(DATE_ATOM),'apiVersion'=>'2.30.0','staffVersion'=>'2.25.0','results'=>[]];
$out=$argv[2]; if (!is_dir($out)) mkdir($out,0700,true);
$checks=0; $keys=0;
$check=static function(bool $condition,string $id,string $expected,mixed $actual=null)use(&$report,&$checks):void {
    $checks++; $report['results'][]=['id'=>$id,'result'=>$condition?'PASS':'FAIL','expected'=>$expected,'actual'=>$actual];
    if (!$condition) throw new RuntimeException('FAIL ' . $id);
};
$call=static function(string $who,string $method,string $path,?array $body=null,?string $key=null,?string $csrf=null)use($manifest,$container,$kernel,&$sessions,&$keys):array {
    $headers=['origin'=>'http://127.0.0.1:4175','content-type'=>'application/json'];
    $s=$sessions[$who]??null;
    if ($s!==null) $headers['x-csrf-token']=$csrf??$s['csrf'];
    if ($method!=='GET') $headers['idempotency-key']=$key??'factory-' . $manifest['sessionId'] . '-' . ++$keys;
    $r=$kernel->handle(new Request($method,$path,$headers,$s===null?[]:[$container->config()->cookieName()=>$s['cookie']],$body===null?'':json_encode($body,JSON_THROW_ON_ERROR),'127.0.0.1','factory-test','factory-' . $keys));
    return ['status'=>$r->status,'body'=>$r->payload,'headers'=>$r->headers,'raw'=>$r->body];
};
$ok=static function(array $r,string $id,int $status=200)use($check):array {
    $check($r['status']===$status,$id,'HTTP ' . $status,['status'=>$r['status'],'code'=>$r['body']['error']['code']??null]);
    return $r['body']??[];
};
$one=static function(string $sql,array $params=[])use($pdo):mixed { $s=$pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$state=static fn(string $id):array=> (function()use($pdo,$id){$s=$pdo->prepare('SELECT * FROM operational_orders WHERE global_order_id=?');$s->execute([$id]);return $s->fetch();})();
$version=static fn(string $id):int=>(int)$one('SELECT production_version FROM operational_orders WHERE global_order_id=?',[$id]);
$qr=static fn(string $id):string=>'ARASYA:Q1:' . $one("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid=q.order_uuid WHERE o.global_order_id=? AND q.status='active'",[$id]);
try {
    $sessions=[];
    foreach ($manifest['accounts'] as $who=>$account) {
        if ($who==='inactive') continue;
        $r=$call('', 'POST','/auth/login',['username'=>$account['username'],'password'=>$account['password']]);
        $ok($r,'AUTH-' . $who); preg_match('/^arasya_sim_session=([^;]+)/',$r['headers']['Set-Cookie']??'',$m);
        $sessions[$who]=['cookie'=>rawurldecode($m[1]),'csrf'=>$r['body']['csrfToken'],'employeeUuid'=>$r['body']['employee']['employeeUuid']];
        $check($r['body']['employee']['locale']===$account['locale'],'LOCALE-' . $who,$account['locale'],$r['body']['employee']['locale']);
    }
    $r=$call('','POST','/auth/login',['username'=>$manifest['accounts']['inactive']['username'],'password'=>$manifest['accounts']['inactive']['password']]);
    $check($r['status']===401,'AUTH-inactive','Inactive account denied',$r['status']);
    $r=$call('temporary','GET','/orders/mine'); $check($r['status']===403,'AUTH-temporary','Password change required',$r['status']);
    $overview=$ok($call('controller','GET','/simulation/overview'),'CENTER-controller');
    $check(count($overview['scenarios'])===20,'CATALOG-20','4 Woo + 16 Trendyol',count($overview['scenarios']));
    foreach ($overview['runs'] as $run) foreach ($run['evidence']['setup'] as $c) $check($c['pass'],'SETUP-' . $run['scenario_id'] . '-' . $c['name'],'Canonical fixture check passes');
    $r=$call('outsider','GET','/simulation/overview'); $check($r['status']===403,'CENTER-outsider','No simulation controller privilege',$r['status']);
    $r=$call('controller','POST','/simulation/scenarios/WOO-TH-001/pause',['expectedReplay'=>1],null,'forged'); $check($r['status']===403,'CENTER-CSRF','Reject forged CSRF',$r['status']);
    $r=$call('controller','POST','/simulation/scenarios/WOO-TH-001/reset',['expectedReplay'=>0]); $check($r['status']===409,'CENTER-stale','Stale control cannot reset',$r['status']);
    $before=count($overview['orders']);
    $overview=$ok($call('controller','POST','/simulation/scenarios/WOO-OP-002/reset',['expectedReplay'=>1]),'CENTER-replay');
    $check(count($overview['orders'])===$before,'CENTER-reset-isolation','Other active scenarios preserved',count($overview['orders']));
    $report['results'][]=['id'=>'WOO-OP-002-routing','result'=>'BLOCKED','expected'=>'Approved standard-only business routing','actual'=>'Existing order-level curtain workflow; no new bypass added'];

    // Same real production operations at all fourteen immutable stages, including denial, stale and replay.
    $global=$one("SELECT order_id FROM simulation_runs WHERE scenario_id='WOO-TH-001'");
    $stages=$ok($call('waiting','GET','/production/workflow'),'WORKFLOW-canonical')['stages'];
    foreach ($stages as $stage) {
        $who=$stage['id']; $current=$state($global);
        $check($current['production_stage_id']===$who,'STAGE-' . $who,'Canonical current stage',$current['production_stage_id']);
        $denied=$call('outsider','POST','/orders/' . rawurlencode($global) . '/claim',['expectedVersion'=>$version($global)]);
        $check(in_array($denied['status'],[403,404],true),'DENY-' . $who,'Unauthorized worker denied',$denied['status']);
        $body=['expectedVersion'=>$version($global)];
        if ($who==='material-preparation') $body+=['qrToken'=>$qr($global),'confirmedMultiple'=>false,'ownedCount'=>0];
        $claim=$ok($call($who,'POST','/orders/' . rawurlencode($global) . '/claim',$body),'CLAIM-' . $who);
        $v=$version($global); $key='factory-transition-' . $who;
        $stale=$call($who,'POST','/orders/' . rawurlencode($global) . '/transition',['expectedVersion'=>$v-1]);
        $check($stale['status']===409 && ($stale['body']['error']['code']??'')==='ORDER_CHANGED','STALE-' . $who,'Stale version rejected',$stale['body']['error']['code']??null);
        $check($version($global)===$v,'STALE-NOWRITE-' . $who,'Rejected command leaves version unchanged');
        $first=$call($who,'POST','/orders/' . rawurlencode($global) . '/transition',['expectedVersion'=>$v],$key); $ok($first,'COMPLETE-' . $who);
        $repeat=$call($who,'POST','/orders/' . rawurlencode($global) . '/transition',['expectedVersion'=>$v],$key); $ok($repeat,'REPLAY-' . $who);
        $check($repeat['body']===$first['body'],'REPLAY-SAME-' . $who,'Exact committed replay');
    }
    $check($state($global)['production_completed_at']!==null,'DELIVERY-completed','Production completed once');
    $check((int)$one("SELECT COUNT(*) FROM order_activity_events e JOIN operational_orders o ON o.order_uuid=e.order_uuid WHERE o.global_order_id=? AND e.action IN ('stage_completed','production_completed')",[$global])===14,'AUDIT-14','Exactly fourteen completion events');

    // A printed source order changes after claim: same order/owner/stage, blocked document, approved revision.
    $docGlobal=$one("SELECT order_id FROM simulation_runs WHERE scenario_id='WOO-TH-002'");
    $docPath='/production-documents/orders/' . rawurlencode($docGlobal);
    $ok($call('document-operator','POST',$docPath . '/generate',['expectedDocumentVersion'=>0]),'DOC-first',201);
    $ok($call('waiting','POST','/orders/' . rawurlencode($docGlobal) . '/claim',['expectedVersion'=>$version($docGlobal)]),'DOC-owner');
    $oldQr=$qr($docGlobal); $before=$state($docGlobal);
    $env=$overview['session']; $payload=ScenarioCatalog::woo('WOO-TH-002',$manifest['sessionId'],1,(int)$env['baseline_ms']);
    $payload['eventId']='sim-document-update'; $payload['changedAt']=gmdate('Y-m-d\TH:i:s\Z',intdiv((int)$env['baseline_ms'],1000)+2); $payload['order']['items'][0]['width']=2.8;
    $ok($service->ingest('trendhome',$payload),'DOC-ingest-update');
    $after=$state($docGlobal);
    $check($after['document_status']==='stale' && $after['production_owner_employee_uuid']===$before['production_owner_employee_uuid'] && $after['production_stage_id']===$before['production_stage_id'],'DOC-stale-owner-preserved','Changed content blocks, keeps owner/stage');
    $r=$call('waiting','POST','/orders/' . rawurlencode($docGlobal) . '/transition',['expectedVersion'=>$version($docGlobal)]);
    $check(($r['body']['error']['code']??'')==='ORDER_BLOCKED_BY_DOCUMENT','DOC-stage-block','Stale document blocks production',$r['body']['error']['code']??null);
    $request=$ok($call('document-operator','POST',$docPath . '/revision-requests',['expectedDocumentVersion'=>(int)$after['document_version']]),'DOC-request',201)['request'];
    $ok($call('document-approver','POST','/production-documents/revision-requests/' . $request['id'] . '/decision',['expectedVersion'=>1,'decision'=>'approve']),'DOC-independent-approval');
    $ok($call('document-operator','POST',$docPath . '/generate',['expectedDocumentVersion'=>(int)$state($docGlobal)['document_version'],'requestId'=>$request['id']]),'DOC-revision-two',201);
    $check($oldQr!==$qr($docGlobal),'QR-new-revision','New revision rotates QR');
    $r=$call('waiting','POST','/orders/resolve-qr',['token'=>$oldQr]);
    $check(($r['body']['error']['code']??'')==='DOCUMENT_SUPERSEDED','QR-old-rejected','Old document QR rejected',$r['body']['error']['code']??null);
    $pdf=$call('document-operator','POST',$docPath . '/print',['revisionNumber'=>2]);
    $check($pdf['status']===200 && str_starts_with($pdf['raw']??'','%PDF'),'PDF-real-artifact','Generated PDF bytes');
    file_put_contents($out . '/TEST-WOO-TH-002-R2.pdf',$pdf['raw']);

    // Canonical Trendyol status classes and millisecond baseline edge, explicit expectations from contract.
    $store=new TrendyolIntakeStore($pdo,$container->projectionWriter(),$container->clock());
    $expected=['Created'=>'received','Picking'=>'received','Invoiced'=>'received','Awaiting'=>'deferred','Verified'=>'deferred','ReadyToShip'=>'received','Shipped'=>'ignored','Delivered'=>'ignored','AtCollectionPoint'=>'ignored','UnDelivered'=>'ignored','Returned'=>'ignored','UnDeliveredAndReturned'=>'ignored','Cancelled'=>'ignored','UnSupplied'=>'ignored','UnPacked'=>'ignored','Unexpected'=>'received'];
    $n=0;
    foreach ($expected as $status=>$wanted) {
        $raw=ScenarioCatalog::trendyol('TY-01',$manifest['sessionId'],9000+ ++$n,(int)$env['baseline_ms']); $raw['shipmentPackageStatus']=$status;
        $actual=$store->record(TrendyolPackage::fromApi($raw),(int)$env['baseline_ms']); $check($actual===$wanted,'STATUS-' . $status,$wanted,$actual);
        $check($one('SELECT COUNT(*) FROM operational_orders WHERE source_order_id=?',[(string)$raw['shipmentPackageId']])==0,'STATUS-no-auto-' . $status,'No automatic production');
    }
    foreach ([-1=>'ignored',0=>'received',1=>'received'] as $delta=>$wanted) {
        $raw=ScenarioCatalog::trendyol('TY-16',$manifest['sessionId'],9500+$delta,(int)$env['baseline_ms']); $raw['orderDate']=(int)$env['baseline_ms']+$delta;
        $actual=$store->record(TrendyolPackage::fromApi($raw),(int)$env['baseline_ms']); $check($actual===$wanted,'BASELINE-' . $delta,$wanted,$actual);
    }
    foreach (ScenarioCatalog::TY as $id=>$definition) {
        $q=$pdo->prepare('SELECT package_id FROM simulation_runs WHERE scenario_id=?'); $q->execute([$id]); $packageId=(string)$q->fetchColumn();
        if ($id==='TY-16') { $check($one('SELECT reason FROM trendyol_ignored_packages WHERE package_id=?',[$packageId])==='historical',$id . '-historical','Ignored permanently'); continue; }
        $path='/trendyol/packages/' . $packageId;
        $detail=$ok($call('trendyol-approver','GET',$path),$id . '-detail');
        if (in_array($id,['TY-05','TY-06','TY-13','TY-14'],true)) {
            if ($id==='TY-05') $r=$call('trendyol-preparer','PUT',$path . '/lines/' . $detail['lines'][0]['lineId'],['expectedVersion'=>$detail['version'],'kind'=>'curtain','widthCm'=>100,'heightCm'=>250,'productionTitle'=>'']);
            elseif ($id==='TY-06') $r=$call('trendyol-preparer','PUT',$path . '/lines/' . $detail['lines'][0]['lineId'],['expectedVersion'=>$detail['version'],'kind'=>'curtain','productionTitle'=>'TEST Perdea']);
            else $r=$call('trendyol-approver','POST',$path . '/release',['expectedVersion'=>$detail['version'],'confirm'=>true]);
            $check($r['status']>=400 && $r['status']<500,$id . '-safe-denial','Missing/review data cannot release',$r['body']['error']['code']??null); continue;
        }
        foreach ($detail['lines'] as $line) {
            $size=$line['productSize']??'100x250'; preg_match('/(\d+)\s*(?:x|X|×)\s*(\d+)/u',$size,$m);
            $detail=$ok($call('trendyol-preparer','PUT',$path . '/lines/' . $line['lineId'],['expectedVersion'=>$detail['version'],'kind'=>'curtain','productionTitle'=>'TEST Perdea ' . $size,'widthCm'=>(int)$m[1],'heightCm'=>(int)$m[2]]),$id . '-prepare');
        }
        if (in_array($id,['TY-08','TY-09','TY-12'],true)) {
            $ok($call('controller','POST','/simulation/scenarios/' . $id . '/fault',['expectedReplay'=>1]),$id . '-fault');
            $r=$call('trendyol-approver','POST',$path . '/release',['expectedVersion'=>$detail['version'],'confirm'=>true]);
            $check($r['status']>=400,$id . '-fresh-denial','Fresh verification refuses changed/cancelled/unavailable fixture',$r['body']['error']['code']??null);
            $check((int)$one('SELECT COUNT(*) FROM operational_orders WHERE source_key=\'trendyol\' AND source_order_id=?',[$packageId])===0,$id . '-no-production','No stale release');
            // Restore fault transport, preserving all current package fixtures.
            if ($id==='TY-12') {
                $packages=[]; foreach (ScenarioCatalog::TY as $restoreId=>$def) $packages[]=ScenarioCatalog::trendyol($restoreId,$manifest['sessionId'],1,(int)$env['baseline_ms']);
                file_put_contents($container->config()->trendyolFixtureFile,json_encode(['packages'=>$packages],JSON_THROW_ON_ERROR));
            }
            continue;
        }
        $key='factory-release-' . $id;
        $released=$ok($call('trendyol-approver','POST',$path . '/release',['expectedVersion'=>$detail['version'],'confirm'=>true],$key),$id . '-release',201);
        $again=$ok($call('trendyol-approver','POST',$path . '/release',['expectedVersion'=>$detail['version'],'confirm'=>true],$key),$id . '-duplicate',201);
        $check($released===$again,$id . '-replay','Duplicate returns same canonical release');
        $check((int)$one('SELECT COUNT(*) FROM operational_orders WHERE source_key=\'trendyol\' AND source_order_id=?',[$packageId])===1,$id . '-canonical','One canonical order');
        if ($id==='TY-07') $check($one('SELECT product_code FROM trendyol_package_lines WHERE package_id=? LIMIT 1',[$packageId])==='TEST-TY-100x250',$id . '-sku','Placeholder skipped; real SKU preserved');
        if ($id==='TY-10') {
            $raw=ScenarioCatalog::trendyol($id,$manifest['sessionId'],1,(int)$env['baseline_ms']); $raw['shipmentPackageStatus']='Cancelled'; $raw['lastModifiedDate']+=2000;
            $store->record(TrendyolPackage::fromApi($raw),(int)$env['baseline_ms']);
            $check($one('SELECT operational_status FROM operational_orders WHERE source_order_id=?',[$packageId])==='unavailable',$id . '-cancel','Released order preserved as unavailable');
        }
    }
    foreach ($service->invariants() as $id=>$pass) $check($pass,'INVARIANT-' . $id,'Database invariant holds');
    // The SQL principal itself cannot read or create another schema, independent of application guards.
    try { $pdo->query('SELECT * FROM mysql.user'); $check(false,'DB-no-global-read','Permission denied'); } catch (PDOException) { $check(true,'DB-no-global-read','Permission denied'); }
    try { $pdo->exec('CREATE DATABASE simulation_escape_forbidden'); $check(false,'DB-no-other-schema','Permission denied'); } catch (PDOException) { $check(true,'DB-no-other-schema','Permission denied'); }
} catch (Throwable $error) {
    $report['error']=$error instanceof RuntimeException && str_starts_with($error->getMessage(),'FAIL ')?$error->getMessage():get_class($error);
    fwrite(STDERR,'Factory test stopped: ' . $report['error'] . "\n");
} finally {
    $report['counts']=array_count_values(array_column($report['results'],'result'));
    file_put_contents($out . '/factory.json',json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    file_put_contents($out . '/factory.md',"# Factory simulation verification\n\n" . implode("\n",array_map(static fn(array $r):string=>'- ' . $r['result'] . ' `' . $r['id'] . '`: ' . $r['expected'],$report['results'])) . "\n\nHuman acceptance, iPhone Safari and Android devices: NOT VERIFIED.\n");
}
echo json_encode(['counts'=>$report['counts'],'error'=>$report['error']??null],JSON_THROW_ON_ERROR) . "\n";
exit(isset($report['error'])?1:0);
