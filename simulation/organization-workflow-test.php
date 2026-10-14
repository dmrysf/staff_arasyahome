<?php

declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
use Arasya\Operations\Application\Container;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Simulation\ScenarioCatalog;
use Arasya\Operations\Simulation\ScenarioService;
use Arasya\Operations\Integration\Trendyol\TrendyolPackage;
use Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore;

if (PHP_SAPI!=='cli' || !in_array($argc,[3,4],true)) throw new RuntimeException('Usage: php simulation/organization-test.php private-manifest report-directory');
$runTag=bin2hex(random_bytes(6));
$manifest=json_decode((string)file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
if (($manifest['environment']['ARASYA_APP_ENV']??'')!=='simulation') throw new RuntimeException('SIMULATION_ENV_REQUIRED');
foreach ($manifest['environment'] as $k=>$v) putenv($k . '=' . $v);
$container=new Container(); $pdo=$container->pdo(); $kernel=$container->kernel(); $service=new ScenarioService($container);
$report=['environmentId'=>$manifest['sessionId'],'date'=>gmdate(DATE_ATOM),'apiVersion'=>'2.30.0','staffVersion'=>'2.25.0','results'=>[]];
$out=$argv[2]; if (!is_dir($out)) mkdir($out,0700,true);
$checks=0; $keys=0; $sessions=[];
$check=static function(bool $condition,string $id,string $expected,mixed $actual=null)use(&$report,&$checks):void {
    $checks++; $report['results'][]=['id'=>$id,'result'=>$condition?'PASS':'FAIL','expected'=>$expected,'actual'=>$actual];
    if (!$condition) throw new RuntimeException('FAIL ' . $id);
};
$call=static function(string $who,string $method,string $path,?array $body=null,?string $key=null,?string $csrf=null)use($manifest,$container,$kernel,&$sessions,&$keys,$runTag):array {
    $headers=['origin'=>explode(',',(string)getenv('ARASYA_ALLOWED_ORIGINS'))[0],'content-type'=>'application/json'];
    $s=$sessions[$who]??null;
    if ($s!==null) $headers['x-csrf-token']=$csrf??$s['csrf'];
    if ($method!=='GET') $headers['idempotency-key']=$key??'organization-' . $manifest['sessionId'] . '-' . $runTag . '-' . ++$keys;
    $r=$kernel->handle(new Request($method,$path,$headers,$s===null?[]:[$container->config()->cookieName()=>$s['cookie']],$body===null?'':json_encode($body,JSON_THROW_ON_ERROR),'127.0.0.1','organization-test','factory-' . $keys));
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
$parallel=static function(array $calls,string $name)use($manifest,$out,&$sessions,$check,$pdo):array {
    $barrier=$out . '/' . $name . '.barrier'; if (file_exists($barrier)) unlink($barrier);
    $workers=[]; $markers=[];
    $path=$calls[0][1];
    if (preg_match('#^/management/production-exceptions/([0-9a-f-]+)/#',$path,$match)) {
        $pdo->beginTransaction();$q=$pdo->prepare('SELECT o.order_uuid FROM operational_orders o JOIN production_exceptions e ON e.order_uuid=o.order_uuid WHERE e.exception_uuid=? FOR UPDATE');$q->execute([$match[1]]);
    } elseif (preg_match('#^/orders/([^/]+)/#',$path,$match)) {
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
        $check($exit===0,$name . '-worker-exit','Worker exits normally'); $results[]=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    }
    unlink($barrier); foreach ($markers as $files) foreach ($files as $file) unlink($file);
    $check(count(array_unique(array_column($results,'pid')))===2,$name . '-separate-processes','Independent process/PDO');
    $check(max(array_column($results,'startedAt')) < min(array_column($results,'endedAt')),$name . '-requests-overlap','Real requests overlap');
    return $results;
};
$seq=($argv[3]??'')==='negative'?3000:2000;
$fixture=static function(string $scenario)use(&$seq,$manifest,$service,$pdo,$call,$ok,$container):string {
    $n=++$seq;$baseline=(int)$pdo->query('SELECT baseline_ms FROM simulation_environment')->fetchColumn();
    if(isset(ScenarioCatalog::WOO[$scenario])) {
        $payload=ScenarioCatalog::woo($scenario,$manifest['sessionId'],$n,$baseline);
        return $ok($service->ingest(ScenarioCatalog::WOO[$scenario][0],$payload),'fixture-' . $n)['globalOrderId'];
    }
    $raw=ScenarioCatalog::trendyol('TY-01',$manifest['sessionId'],$n,$baseline);
    (new TrendyolIntakeStore($pdo,$container->projectionWriter(),$container->clock()))->record(TrendyolPackage::fromApi($raw),$baseline);
    $file=$container->config()->trendyolFixtureFile;$transport=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);$transport['packages'][]=$raw;file_put_contents($file,json_encode($transport,JSON_THROW_ON_ERROR));
    $path='/trendyol/packages/' . $raw['shipmentPackageId'];
    $detail=$ok($call('trendyol-preparer','GET',$path),'TY-preparation-view');
    foreach($detail['lines'] as $line) $detail=$ok($call('trendyol-preparer','PUT',$path . '/lines/' . $line['lineId'],['expectedVersion'=>$detail['version'],'kind'=>'curtain','productionTitle'=>'TEST Perdea variant 100x250','widthCm'=>100,'heightCm'=>250]),'TY-explicit-kind-dimensions');
    $ok($call('trendyol-approver','POST',$path . '/release',['expectedVersion'=>$detail['version'],'confirm'=>true]),'TY-independent-release',201);
    $q=$pdo->prepare("SELECT global_order_id FROM operational_orders WHERE source_key='trendyol' AND source_order_id=?");$q->execute([(string)$raw['shipmentPackageId']]);return (string)$q->fetchColumn();
};
$getOrder=static fn(string $who,string $id):array=>$ok($call($who,'GET','/orders/' . rawurlencode($id)),'worker-real-order');
$confirm=static function(string $who,string $id)use($getOrder,$call,$ok):void {
    $o=$getOrder($who,$id);$items=[];foreach($o['lineReadiness']['lines'] as $line) if($line['requiredAtCurrentStage']) $items[]=['itemId'=>$line['itemId'],'completedQuantity'=>$line['quantity']];
    if($items) $ok($call($who,'POST','/orders/' . rawurlencode($id) . '/line-checks',['expectedVersion'=>$o['productionVersion'],'items'=>$items]),'owner-real-line-check');
};
$claim=static function(string $who,string $id)use($call,$ok,$state,$qr,$version):void {
    if($state($id)['production_owner_employee_uuid']!==null) return;
    $input=['expectedVersion'=>$version($id)];
    if($who==='material-preparation') $input+=['qrToken'=>$qr($id),'confirmedMultiple'=>true,'ownedCount'=>count($ok($call($who,'GET','/cutting/pool'),'cutting-pool')['mine']??[])];
    // Exact owned count is a server invariant (read-only SQL in this isolated verifier).
    if($who==='material-preparation') { global $one,$sessions;$input['ownedCount']=(int)$one("SELECT COUNT(*) FROM operational_orders WHERE production_stage_id='material-preparation' AND production_owner_employee_uuid=? AND production_completed_at IS NULL",[$sessions[$who]['employeeUuid']]); }
    $ok($call($who,'POST','/orders/' . rawurlencode($id) . '/claim',$input),'owner-real-claim');
};
$advance=static function(string $who,string $id)use($confirm,$call,$ok,$version):void {$confirm($who,$id);$ok($call($who,'POST','/orders/' . rawurlencode($id) . '/transition',['expectedVersion'=>$version($id)]),'owner-real-transition');};
$bring=static function(string $id,string $target)use($state,$claim,$advance):void {
    while(true) {$who=$state($id)['production_stage_id'];$claim($who,$id);if($who===$target) break;$advance($who,$id);}
};
$finish=static function(string $id,string $label)use($state,$claim,$advance,$check,$one):void {
    while($state($id)['production_completed_at']===null){$who=$state($id)['production_stage_id'];global $sessions;if($who==='material-preparation' && $state($id)['production_owner_employee_uuid']===($sessions['cutting-second']['employeeUuid']??null)) $who='cutting-second';$claim($who,$id);$advance($who,$id);}
    $o=$state($id);$check($o['open_exception_uuid']===null && $o['production_owner_employee_uuid']===null,$label . '-final-ownership','Completed without open exception or owner');
    $check((int)$one("SELECT COUNT(*) FROM order_activity_events WHERE order_uuid=? AND action='production_completed'",[$o['order_uuid']])===1,$label . '-single-completion','Exactly one final completion');
    $check((int)$one("SELECT COUNT(*) FROM order_qr_references WHERE order_uuid=? AND status='active'",[$o['order_uuid']])===1,$label . '-active-qr','Exactly one active QR');
};
$exVersion=static fn(string $id):int=>(int)$one('SELECT version FROM production_exceptions WHERE exception_uuid=?',[$id]);
$ex=static function(string $who,string $id,string $action,array $body=[],?string $key=null)use($call,$exVersion):array {
    $path=$action==='decision'?'/management/production-exceptions/':'/production-exceptions/';
    return $call($who,'POST',$path . $id . '/' . $action,['expectedVersion'=>$exVersion($id)]+$body,$key);
};
$reportDefect=static function(string $id,string $who)use($call,$getOrder,$version,$ok):array {
    $o=$getOrder($who,$id);return $ok($call($who,'POST','/orders/' . rawurlencode($id) . '/fault-reports',['expectedVersion'=>$version($id),'itemIds'=>[$o['products'][0]['id']],'reasonKey'=>'cut-meterage','comment'=>'TEST defect in actual product']),'report-defect',201);
};
$accept=static fn(string $id,string $global):array=>$ok($ex('tailoring-supervisor',$id,'review',['decision'=>'accept','reasonKey'=>'cut-meterage','qrToken'=>$qr($global),'comment'=>'TEST verified defect']),'supervisor-QR-custody');
$receiveCut=static function(string $id,string $global)use($ex,$ok,$qr):void {
    $ok($ex('tailoring-supervisor',$id,'return',['comment'=>'TEST return requested']),'supervisor-return-request');
    $ok($ex('material-preparation',$id,'acknowledge',['confirmed'=>true,'qrToken'=>$qr($global),'responsibility'=>'accepted']),'responsible-cutter-QR-receipt');
};
$restore=static function(string $id,string $global,string $who)use($ex,$ok,$qr,$check,$state):void {
    $ok($ex('tailoring-supervisor',$id,'restore',['qrToken'=>$qr($global),'comment'=>'TEST restoration']),'supervisor-restore-handover');
    $check($state($global)['open_exception_uuid']===$id,'handover-network-gap-blocked','The order stays blocked between authenticated receipts');
    $version=(int)$state($global)['production_version'];$key='org-receive-' . $id;$input=['confirmed'=>true,'qrToken'=>$qr($global)];
    $r=$ex($who,$id,'receive',$input,$key);$ok($r,'tailor-receives-restoration');
    // Same exact expected exception version/key on retry after an interruption.
    global $call;$again=$call($who,'POST','/production-exceptions/' . $id . '/receive',['expectedVersion'=>$r['body']['version']-1]+$input,$key);
    $check($again['status']===200 && $again['body']===$r['body'],'handover-lost-answer-replay','Committed handover is replayed exactly');
    $check((int)$state($global)['production_version']===$version+1,'handover-once','One production-version increment');
};
try {
    $manifest['accounts']['root']=$manifest['root']+['locale'=>'ro'];
    foreach($manifest['accounts'] as $who=>$a){if($who==='inactive'||$who==='temporary') continue;$r=$call('','POST','/auth/login',['username'=>$a['username'],'password'=>$a['password']]);$ok($r,'authenticate-' . $who);preg_match('/^arasya_sim_session=([^;]+)/',$r['headers']['Set-Cookie'],$m);$sessions[$who]=['cookie'=>rawurldecode($m[1]),'csrf'=>$r['body']['csrfToken'],'employeeUuid'=>$r['body']['employee']['employeeUuid']];}
    if (($argv[3]??'')!=='negative') {
    $check(count($manifest['organizationProfiles'])===46,'organization-full-roster','All 46 reference profiles reproduced without personal names');
    $reference=\Arasya\Operations\Management\OrganizationOnboarding::fromFiles(dirname(__DIR__) . '/operations-api/database/reference/organization-onboarding.json',dirname(__DIR__) . '/operations-api/database/reference/organization-roster.json');
    foreach($manifest['organizationProfiles'] as $profile) {
        $e=$container->employeeRepository()->findByNormalizedUsername($profile['username']);
        $check($e!==null && $e->status===$profile['status'] && $e->departmentName===$reference->departments()[$profile['department']]['name'],'organization-membership-' . $profile['username'],'Same status and department');
        $actualStages=$e->allowedStageIds;sort($actualStages);$expectedStages=$profile['status']==='active'?$profile['stages']:[];sort($expectedStages);$check($actualStages===$expectedStages,'organization-stages-' . $profile['username'],'Exact approved factory stage set');
        foreach($expectedStages as $stage) {$scopes=$e->sourcesAt($stage);sort($scopes);$check($scopes===['outletperdele','trendhome','trendyol'],'organization-sources-' . $profile['username'] . '-' . $stage,'Exact per-stage source scope');}
        $apps=$e->applications;sort($apps);$expected=$profile['applications'];sort($expected);$check($apps===$expected,'organization-applications-' . $profile['username'],'Exact authorized application set');
        $roles=$e->roleKeys;$check(array_diff($profile['roles'],$roles)===[] && array_diff($roles,[...$profile['roles'],'employee'])===[],'organization-roles-' . $profile['username'],'Intended role grants only');
        $manager=$e->managerUuid===null?null:$container->employeeRepository()->findByUuid($e->managerUuid)->username;$check($manager===$profile['manager'],'organization-manager-' . $profile['username'],'Confirmed reporting line retained, no inferred manager');
    }
    $stages=array_column($ok($call('waiting','GET','/production/workflow'),'canonical-fourteen')['stages'],'id');$check(count($stages)===14,'canonical-14','No canonical stages changed');
$cap=$ok($call('stage-supervisor','GET','/production-exceptions/capabilities'),'stage-supervisor-capabilities');$check(!$cap['tailoringSupervisor'],'stage-supervisor-not-appointed-quality-supervisor','IAM supervisor template does not grant the explicit quality appointment');
    // Normal flows for every requested source/product profile; fabric partial quantities cannot hand over.
    foreach(['WOO-TH-001','WOO-TH-002','WOO-OP-001','WOO-OP-002','TY-01'] as $scenario) {
        $id=$fixture($scenario);$bring($id,$scenario==='WOO-OP-002'?'packing':'material-preparation');$who=$state($id)['production_stage_id'];$path='/orders/' . rawurlencode($id);
        $denied=$call($who,'POST',$path . '/transition',['expectedVersion'=>$version($id)]);$check($denied['status']===409 && $denied['body']['error']['code']==='LINES_UNFINISHED',$scenario . '-no-premature-handover','Original whole-order bypass now refused');
        $o=$getOrder($who,$id);$line=$o['lineReadiness']['lines'][0];
        if($line['quantity']>1){$ok($call($who,'POST',$path . '/line-checks',['expectedVersion'=>$version($id),'items'=>[['itemId'=>$line['itemId'],'completedQuantity'=>1]]]),'partial-quantity');$denied=$call($who,'POST',$path . '/transition',['expectedVersion'=>$version($id)]);$check($denied['status']===409,'partial-not-complete','Partial quantity cannot complete whole order');}
        $bad=$call('controller','POST',$path . '/line-checks',['expectedVersion'=>$version($id),'items'=>[['itemId'=>$line['itemId'],'completedQuantity'=>$line['quantity']]]]);$check(in_array($bad['status'],[403,404],true),'controller-cannot-check','Fixture controller cannot impersonate stage worker');
        $finish($id,$scenario . '-normal');
        $order=$getOrder('packing',$id);$check($order['lineReadiness']['readyForPacking'] && count(array_filter($order['lineReadiness']['lines'],static fn($l)=>$l['packed']))===count($order['products']),$scenario . '-all-items-packed','Every identity, variant and complete quantity checked in packing');
    }
    // Every supported tailoring stage, for each eligible source. Each accepted request completes real rework.
    foreach(['WOO-TH-001','WOO-TH-002','WOO-OP-001','TY-01'] as $scenario) foreach(\Arasya\Operations\Quality\TailoringSupervisorPolicy::TAILORING_STAGES as $target) {
        $id=$fixture($scenario);$bring($id,$target);$request=$reportDefect($id,$target);$x=$request['id'];$path='/orders/' . rawurlencode($id);
        $check($state($id)['open_exception_uuid']===$x && $state($id)['production_stage_id']===$target,$scenario . '-' . $target . '-blocked-report','Report blocks without direct stage mutation');
        $wrong=$ex($target,$x,'review',['decision'=>'accept','reasonKey'=>'cut-meterage','qrToken'=>$qr($id)]);$check($wrong['status']===403,'reporter-cannot-review','No self-supervisor approval');
        $accept($x,$id);$check($state($id)['production_stage_id']==='workshop-receiving' && $state($id)['production_owner_employee_uuid']===null,'supervisor-custody-stage3','Actual custody transaction and no worker ownership');
        $premature=$ex('manager',$x,'decision',['decision'=>'approve','attribution'=>'cutter','comment'=>'TEST']);$check($premature['status']===409,'no-manager-before-receipt','Manager cannot return to cutting before physical receipt');
        $receiveCut($x,$id);
        $wrong=$ex('tailoring-supervisor',$x,'decision',['decision'=>'approve','attribution'=>'cutter','comment'=>'TEST']);$check($wrong['status']===403,'supervisor-not-manager','Distinct manager authority');
        $approver=($target==='labeling'?'backup-approver':($target==='quality-control'?'root':'manager'));
        $decision=$ok($ex($approver,$x,'decision',['decision'=>'approve','attribution'=>'cutter','comment'=>'TEST independent manager approval']),'independent-manager-approved');
        $check($state($id)['production_stage_id']==='material-preparation' && $state($id)['production_owner_employee_uuid']===$sessions['material-preparation']['employeeUuid'],'controlled-stage3-to2','Only authorized decision returns to cutter ownership');
        $check((int)$one('SELECT COUNT(*) FROM production_line_checks WHERE order_uuid=?',[$state($id)['order_uuid']])===0,'rework-invalidates-line-checks','Previous cutting/tailoring/packing checks cannot satisfy rework');
        $finish($id,$scenario . '-' . $target . '-rework');
    }
    // Supervisor dismissal and manager rejection/restoration for every fabric source/product profile.
    foreach(['WOO-TH-001','WOO-TH-002','WOO-OP-001','TY-01'] as $scenario) foreach(['dismiss','manager-reject'] as $branch) {
        $id=$fixture($scenario);$bring($id,'material-straightening');$x=$reportDefect($id,'material-straightening')['id'];
        if($branch==='dismiss') {$ok($ex('tailoring-supervisor',$x,'review',['decision'=>'dismiss','comment'=>'TEST defect not confirmed']),'supervisor-rejects');$check($state($id)['production_stage_id']==='material-straightening' && $state($id)['open_exception_uuid']===null,'dismiss-no-backward-jump','Original stage/worker retained');}
        else {$accept($x,$id);$receiveCut($x,$id);$ok($ex('manager',$x,'decision',['decision'=>'reject','comment'=>'TEST manager rejects return']),'manager-rejects');$check($state($id)['production_stage_id']==='workshop-receiving' && $state($id)['open_exception_uuid']===$x,'manager-reject-stays-blocked','No false cutting return');$restore($x,$id,'material-straightening');}
        $finish($id,$scenario . '-' . $branch);
    }
    // Concurrent independent manager decisions on a real ready-for-approval defect.
    $id=$fixture('WOO-OP-001');$bring($id,'material-straightening');$x=$reportDefect($id,'material-straightening')['id'];$accept($x,$id);$receiveCut($x,$id);
    $input=['expectedVersion'=>$exVersion($x),'decision'=>'approve','attribution'=>'cutter','comment'=>'TEST concurrency'];$path='/management/production-exceptions/' . $x . '/decision';
    $race=$parallel([['manager',$path,$input],['manager-second',$path,$input]],'two-independent-managers');$statuses=array_column($race,'status');sort($statuses);$check($statuses===[200,409],'manager-single-winner','One committed approval, one resolved/stale denial',$race);
    $check((int)$one("SELECT COUNT(*) FROM production_exception_decisions WHERE exception_uuid=? AND status='approved'",[$x])===1,'manager-decision-once','One approved decision');$finish($id,'concurrent-rework');
    }
    require __DIR__ . '/organization-negative-cases.php';
    // Explicit source contract: missing, zero, one-sided, unknown class reject before projection.
    foreach([['manufacturingType'=>'unknown'],['width'=>null],['height'=>0],['unit'=>'inch']] as $i=>$change){$payload=ScenarioCatalog::woo('WOO-TH-001',$manifest['sessionId'],9000+$i,(int)$pdo->query('SELECT baseline_ms FROM simulation_environment')->fetchColumn());$payload['order']['items'][0]=array_replace($payload['order']['items'][0],$change);$r=$service->ingest('trendhome',$payload);$check($r['status']===422,'BR002-invalid-' . $i,'Explicit custom classification validates required dimensions before persistence');}
    $payload=ScenarioCatalog::woo('WOO-TH-001',$manifest['sessionId'],9100,(int)$pdo->query('SELECT baseline_ms FROM simulation_environment')->fetchColumn());foreach($payload['order']['items'] as &$line) unset($line['manufacturingType']);unset($line);$id=$ok($service->ingest('trendhome',$payload),'legacy-contract-read')['globalOrderId'];$claim('waiting',$id);$r=$call('waiting','POST','/orders/' . rawurlencode($id) . '/transition',['expectedVersion'=>$version($id)]);$check($r['status']===409 && $r['body']['error']['code']==='PRODUCT_CLASSIFICATION_REQUIRED','BR002-unclassified-fail-closed','No name guessing, no production readiness for unknown kind');
    foreach($service->invariants() as $name=>$pass) $check($pass,'final-' . $name,'Canonical DB invariant');
    $report['coverage']=['organizationProfiles'=>46,'fabricSources'=>3,'fabricProductProfiles'=>4,'allSupportedTailoringStages'=>10,'acceptedReworkCases'=>40,'dismissRestoreCases'=>8,'normalProductProfiles'=>5,'physicalHumanAcceptance'=>'NOT VERIFIED'];
} catch(Throwable $e) {$report['error']=$e instanceof RuntimeException&&str_starts_with($e->getMessage(),'FAIL ')?$e->getMessage():get_class($e);fwrite(STDERR,'Organization verification failed: ' . $e->getMessage() . "\n");}
finally {$report['counts']=array_count_values(array_column($report['results'],'result'));file_put_contents($out . '/organization-workflow.json',json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));}
echo json_encode(['counts'=>$report['counts'],'error'=>$report['error']??null],JSON_THROW_ON_ERROR) . "\n";exit(isset($report['error'])?1:0);
