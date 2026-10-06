<?php
declare(strict_types=1);
use Arasya\Operations\Application\Container;
use Arasya\Operations\B2B\OrderCalculator;
use Arasya\Operations\B2B\ProductionSheetPdf;
use Arasya\Operations\B2B\ProjectProposalPdf;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__).'/bootstrap.php'; require __DIR__.'/OperationsTestSupport.php';
$db=T::requireTestDatabase(); if($db===null){echo "SKIP MySQL B2B projects: no test database.\n";exit;}
$checks=0;
function check(bool $v,string $why): void { global $checks; $checks++; if(!$v) throw new RuntimeException('FAIL '.$why); }
function status(array $r,int $expected,string $why): array { check($r['status']===$expected,$why.' '.json_encode($r['body'])); return json_decode(json_encode($r['body']??[]),true); }
function error(array $r,int $s,string $code): array { $b=status($r,$s,$code); check(($b['error']['code']??null)===$code,$code.' '.json_encode($b)); return $b; }
$config=T::config($db);$pdo=Connection::create($config);$migrations=dirname(__DIR__).'/database/migrations';
(new MigrationRunner($pdo))->migrate($migrations);
require __DIR__.'/HandoffSchemaFixture.php';restorePreProjectsTestSchema($pdo);
$grantsBefore=$pdo->query('SELECT * FROM role_permissions ORDER BY role_id,permission_id')->fetchAll();
$commercialBefore=array_map(fn($t)=>$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(),['b2b_orders','b2b_order_lines','b2b_account_movements','operational_orders']);
check((new MigrationRunner($pdo))->migrate($migrations)===['013_b2b_projects.sql'],'012→013 official additive upgrade');
check((new MigrationRunner($pdo))->migrate($migrations)===[],'013 recorded exactly once');
check($pdo->query('SELECT * FROM role_permissions ORDER BY role_id,permission_id')->fetchAll()===$grantsBefore,'013 changes no grant');
check(array_map(fn($t)=>$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(),['b2b_orders','b2b_order_lines','b2b_account_movements','operational_orders'])===$commercialBefore,'013 rewrites no commercial, financial or production row');
foreach(glob(dirname(__DIR__).'/database/seeds/*.sql') as $f)(new SqlFileRunner($pdo))->run($f);
$projectPermissions=['b2b.projects.view','b2b.projects.create','b2b.projects.update','b2b.projects.archive','b2b.projects.convert'];
$in="'".implode("','",$projectPermissions)."'";
check((int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE role_grantable=1 AND permission_key IN ($in)")->fetchColumn()===5,'five role-grantable project permissions');
check((int)$pdo->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.permission_key IN ($in)")->fetchColumn()===0,'013 grants no role');
$c=new Container($config,$pdo);$k=$c->kernel();$suffix=bin2hex(random_bytes(5));$pdo->exec('DELETE FROM system_root_identity');
$name='project.root.'.$suffix;$temp=(new RootBootstrapService($pdo,$c->passwordHasher(),$c->clock()))->bootstrap('project-test',$name);
$root=T::login($k,$name,$temp);status(T::call($k,'POST','/auth/password',['currentPassword'=>$temp,'newPassword'=>'Project test passphrase 2026!'],['x-csrf-token'=>$root['csrf']],$root['cookie']),200,'password');
$root=T::login($k,$name,'Project test passphrase 2026!');
$send=fn($method,$path,$body=[],?string $key=null,$headers=[],$who=null)=>T::call($k,$method,$path,$body,['x-csrf-token'=>($who??$root)['csrf'],'idempotency-key'=>$key??('project-'.bin2hex(random_bytes(8))),...$headers],($who??$root)['cookie']);
$get=fn($path,$query=[],$who=null)=>T::call($k,'GET',$path,null,[],($who??$root)['cookie'],$query);
$rows=function($sql,$params=[])use($pdo){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);};
$snapshot=function(array $tables)use($rows){$out=[];foreach($tables as $table){$r=array_map(serialize(...),$rows('SELECT * FROM '.$table));sort($r);$out[$table]=hash('sha256',implode('\n',$r));}return $out;};
$questions=fn(): int=>(int)$pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
$financialTables=['b2b_account_movements','b2b_account_allocations','b2b_account_allocation_releases','b2b_account_activity_events'];
$productionTables=['operational_orders','operational_order_items','order_qr_references','order_activity_events','b2b_production_handoffs'];
$projectTables=['b2b_projects','b2b_project_zones','b2b_project_rooms','b2b_project_openings','b2b_project_treatments','b2b_project_orders','b2b_project_order_lines'];
$uuid=fn()=>Arasya\Operations\Support\Uuid::v4();
$rootIdentity=fn(string $id)=>(new Arasya\Operations\Employee\PdoEmployeeRepository($pdo))->findByUuid($id);
$company=status($send('POST','/b2b/companies',['legalName'=>'Project Hotel SRL '.$suffix,'countryCode'=>'RO','taxIdentifier'=>'PRJ'.$suffix]),201,'company')['companyId'];

// Quick wholesale stays a plain Classic order with no project dependency.
$beforeProjects=$snapshot($projectTables);
$wholesale=status($send('POST','/b2b/orders',['companyId'=>$company,'currencyCode'=>'RON','lines'=>[['productCode'=>'FAB-100','productName'=>'Stofă X','variant'=>null,'color'=>null,
    'kind'=>'other','width'=>null,'height'=>null,'quantity'=>1,'meters'=>'100','pricingUnit'=>'meter','unitPriceNet'=>'12.50','discountPercent'=>'0','vatPercent'=>'19','notes'=>null,'productionNotes'=>null]]]),201,'quick wholesale draft')['detail']['order'];
$wholesale=status($send('POST',"/b2b/orders/{$wholesale['id']}/finalize",['expectedVersion'=>$wholesale['version']]),200,'quick wholesale finalize')['detail']['order'];
check($wholesale['origin']===null && $wholesale['calculation']['totals']['gross']==='1487.50' && $wholesale['lines'][0]['meters']==='100.000','wholesale: total meters, no origin');
check($snapshot($projectTables)===$beforeProjects,'quick wholesale touches no project table');

// Project creation and validation.
error($send('POST','/b2b/projects',['companyId'=>$company,'name'=>'X','propertyType'=>'castle']),422,'VALIDATION_FAILED');
error($send('POST','/b2b/projects',['companyId'=>$company,'name'=>'X','propertyType'=>'hotel','stage'=>'x']),400,'INVALID_REQUEST');
$createKey='create-project-'.$suffix;
$created=status($send('POST','/b2b/projects',['companyId'=>$company,'name'=>'Hotel Marea Neagră','propertyType'=>'hotel','currencyCode'=>'RON','siteAddress'=>'Constanța, Bd. Mamaia 1','customerReference'=>'HM-2026'],$createKey),201,'project');
$project=$created['projectId'];
check($created['detail']['project']['code']===sprintf('B2B-PRJ-%06d',(int)$rows('SELECT project_number FROM b2b_projects WHERE project_uuid=?',[$project])[0]['project_number']) && $created['detail']['project']['status']==='draft','code and draft status');
check(status($send('POST','/b2b/projects',['companyId'=>$company,'name'=>'Hotel Marea Neagră','propertyType'=>'hotel','currencyCode'=>'RON','siteAddress'=>'Constanța, Bd. Mamaia 1','customerReference'=>'HM-2026'],$createKey),201,'create replay')['projectId']===$project,'create replay returns the same project');
check(count($rows('SELECT 1 FROM b2b_projects WHERE company_uuid=?',[$company]))===1,'create replay does not duplicate');
$p="/b2b/projects/$project";
$changes=fn(array $ops,?string $key=null,$who=null)=>$send('POST',"$p/changes",['operations'=>$ops],$key,[],$who);

// Hierarchy in one atomic batch: 2 floors, room, 2 windows, 2 treatments each.
$floor1=$uuid();$floor2=$uuid();$room=$uuid();$w1=$uuid();$w2=$uuid();
$treatment=fn(string $type,string $code,array $extra=[])=>array_replace(['treatmentType'=>$type,'panelLayout'=>'pair','productCode'=>$code,'productName'=>'Produs '.$code,'variant'=>'Wave','color'=>'Ivory',
    'width'=>'180','height'=>'250','quantity'=>1,'meters'=>'5.4','pricingUnit'=>'meter','unitPriceNet'=>'45.00','discountPercent'=>'10','vatPercent'=>'19','notes'=>'Notă','productionNotes'=>'Tiv 10 cm'],$extra);
$ids=[];
$ops=[['op'=>'zone.create','id'=>$floor1,'fields'=>['name'=>'Etaj 1','zoneType'=>'floor','level'=>1]],['op'=>'zone.create','id'=>$floor2,'fields'=>['name'=>'Etaj 2','zoneType'=>'floor','level'=>2]],
    ['op'=>'room.create','id'=>$room,'zoneId'=>$floor1,'fields'=>['name'=>'Camera 101','widthCm'=>'420','lengthCm'=>'510','ceilingHeightCm'=>'280']]];
foreach([$w1,$w2] as $i=>$w) {
    $ops[]=['op'=>'opening.create','id'=>$w,'roomId'=>$room,'fields'=>['name'=>'Fereastra '.($i+1),'openingType'=>'window','wallIndex'=>1,'width'=>'160','height'=>'240','sillHeight'=>'40','offsetLeft'=>(string)(50+200*$i),'mounting'=>'ceiling','railType'=>'Șină dublă']];
    foreach([['sheer','VOAL-1'],['blackout','BLK-2']] as [$type,$code]) { $ids[]=$id=$uuid(); $ops[]=['op'=>'treatment.create','id'=>$id,'openingId'=>$w,'fields'=>$treatment($type,$code)]; }
}
$money=$snapshot($financialTables);
$batch=status($changes($ops,'hierarchy-'.$suffix),200,'hierarchy batch');
check($batch['revision']===2 && $batch['versions'][$room]===1 && count($batch['versions'])===9,'batch versions and revision');
$replayed=status($changes($ops,'hierarchy-'.$suffix),200,'batch replay');
check(json_encode($replayed['created'])===json_encode($batch['created']) && json_encode($replayed['versions'])===json_encode($batch['versions']),'replay keeps the original response shape');
$dupKey='dup-replay-'.$suffix;
$firstDup=T::call($k,'POST',"$p/changes",['operations'=>[['op'=>'treatment.duplicate','id'=>$ids[0]]]],['x-csrf-token'=>$root['csrf'],'idempotency-key'=>$dupKey],$root['cookie']);
$againDup=T::call($k,'POST',"$p/changes",['operations'=>[['op'=>'treatment.duplicate','id'=>$ids[0]]]],['x-csrf-token'=>$root['csrf'],'idempotency-key'=>$dupKey],$root['cookie']);
check(json_encode($firstDup['body'])===json_encode($againDup['body']),'index-keyed created map survives the replay as an object');
$dupId=status($firstDup,200,'dup')['created'][0][0];
status($changes([['op'=>'treatment.remove','id'=>$dupId,'expectedVersion'=>1]]),200,'remove replay probe');
check(count($rows('SELECT 1 FROM b2b_project_treatments WHERE project_uuid=?',[$project]))===4,'batch replay creates nothing twice');
error($changes([$ops[0]],'hierarchy-'.$suffix),409,'IDEMPOTENCY_CONFLICT');
$t0=$rows('SELECT * FROM b2b_project_treatments WHERE treatment_uuid=?',[$ids[0]])[0];
check($t0['meters']==='5.400' && $t0['product_code']==='VOAL-1' && $t0['treatment_type']==='sheer','Classic line language persisted');
error($changes([['op'=>'treatment.create','id'=>$uuid(),'openingId'=>$w1,'fields'=>$treatment('curtain-3d','X')]]),422,'UNSUPPORTED_TREATMENT');
$bad=error($changes([['op'=>'opening.create','id'=>$uuid(),'roomId'=>$room,'fields'=>['name'=>'F','width'=>'-1']]]),422,'VALIDATION_FAILED');
check(isset($bad['error']['details']['fields']['operations.0.fields.width']),'invalid measurement named by operation and field');
error($changes([['op'=>'treatment.create','id'=>$uuid(),'openingId'=>$w1,'fields'=>$treatment('sheer','X',['meters'=>'1.2345'])]]),422,'VALIDATION_FAILED');

// Repetition: 19 copies of room 101 -> 20 rooms; queries do not grow with the number of copies.
$names=array_map(fn($n)=>'Camera '.$n,range(102,120));
$q=$questions();$dup=status($changes([['op'=>'room.duplicate','id'=>$room,'names'=>$names]]),200,'duplicate room x19');$q19=$questions()-$q;
check(count($dup['created'][0])===19,'19 new rooms');
$q=$questions();status($changes([['op'=>'room.duplicate','id'=>$room,'zoneId'=>$floor2,'names'=>['Camera 201']]]),200,'duplicate room x1');$q1=$questions()-$q;
check($q19<=$q1+2,"duplication query count is constant ($q19 vs $q1)");
$outline=status($get($p),200,'outline');
check($outline['project']['counts']===['zones'=>2,'rooms'=>21,'openings'=>42,'treatments'=>84,'orderedTreatments'=>0],'counts after repetition '.json_encode($outline['project']['counts']));
check(array_column($outline['zones'][0]['rooms'],'name')===['Camera 101',...$names] && $outline['zones'][0]['rooms'][5]['openingCount']===2,'numbering and order');
$copy=$dup['created'][0][0];
$copyRoom=status($get("$p/rooms/$copy"),200,'copy room');
$copyOpening=$copyRoom['room']['openings'][0];$copyTreatment=$copyOpening['treatments'][0];
check($copyRoom['room']['copiedFromId']===$room && $copyTreatment['copiedFromId']===$ids[0] && $copyTreatment['id']!==$ids[0],'copies are new rows with a trace');
// Independence: editing a copy never changes its source or siblings.
status($changes([['op'=>'treatment.update','id'=>$copyTreatment['id'],'expectedVersion'=>1,'fields'=>$treatment('sheer','VOAL-EDIT')]]),200,'edit copy');
check($rows('SELECT product_code FROM b2b_project_treatments WHERE treatment_uuid=?',[$ids[0]])[0]['product_code']==='VOAL-1','source unchanged');
check((int)$rows("SELECT COUNT(*) c FROM b2b_project_treatments WHERE project_uuid=? AND product_code='VOAL-EDIT'",[$project])[0]['c']===1,'siblings unchanged');
// Read cost does not grow with project size.
$q=$questions();status($get($p),200,'outline');$qOutline=$questions()-$q;
$q=$questions();status($get("$p/commercial"),200,'commercial');$qCommercial=$questions()-$q;
$small=status($send('POST','/b2b/projects',['companyId'=>$company,'name'=>'Apartament mic','propertyType'=>'apartment']),201,'small project')['projectId'];
$sz=$uuid();$sr=$uuid();$so=$uuid();
status($send('POST',"/b2b/projects/$small/changes",['operations'=>[['op'=>'zone.create','id'=>$sz,'fields'=>['name'=>'Parter']],['op'=>'room.create','id'=>$sr,'zoneId'=>$sz,'fields'=>['name'=>'Living']],
    ['op'=>'opening.create','id'=>$so,'roomId'=>$sr,'fields'=>['name'=>'F1']],['op'=>'treatment.create','id'=>$uuid(),'openingId'=>$so,'fields'=>$treatment('sheer','S')]]]),200,'small structure');
$q=$questions();status($get("/b2b/projects/$small"),200,'small outline');$qSmallOutline=$questions()-$q;
$q=$questions();status($get("/b2b/projects/$small/commercial"),200,'small commercial');$qSmallCommercial=$questions()-$q;
check($qOutline===$qSmallOutline && $qCommercial===$qSmallCommercial,"no N+1 reads: 1 room vs 21 rooms (outline $qSmallOutline/$qOutline, commercial $qSmallCommercial/$qCommercial)");

// Version conflicts are atomic: a stale op rolls the whole batch back.
$before=$snapshot($projectTables);
$conflict=error($changes([['op'=>'room.update','id'=>$room,'expectedVersion'=>1,'fields'=>['name'=>'Camera 101 A']],
    ['op'=>'treatment.update','id'=>$copyTreatment['id'],'expectedVersion'=>1,'fields'=>$treatment('sheer','STALE')]]),409,'PROJECT_CHANGED');
check($conflict['error']['details']['operation']===1 && $conflict['error']['details']['currentVersion']===2,'conflict names operation and version');
check($snapshot($projectTables)===$before,'no partial batch');
error($changes([['op'=>'room.update','id'=>$uuid(),'expectedVersion'=>1,'fields'=>['name'=>'Ghost']]]),409,'PROJECT_CHANGED');

// Opening repetition, apply room configuration, treatment set copy, reorder, removal.
$rep=status($changes([['op'=>'opening.duplicate','id'=>$w1,'names'=>array_map(fn($n)=>"Fereastra $n",range(3,10))]]),200,'opening x8');
check(count($rep['created'][0])===8 && (int)$rows('SELECT COUNT(*) c FROM b2b_project_openings WHERE room_uuid=?',[$room])[0]['c']===10,'repeated openings');
$targets=array_slice($dup['created'][0],1,3);
status($changes([['op'=>'room.apply','sourceId'=>$room,'targetIds'=>$targets,'mode'=>'replace']]),200,'apply replace');
foreach($targets as $target) check((int)$rows('SELECT COUNT(*) c FROM b2b_project_openings WHERE room_uuid=?',[$target])[0]['c']===10,'replace copies all openings');
status($changes([['op'=>'room.apply','sourceId'=>$room,'targetIds'=>[$targets[0]],'mode'=>'append']]),200,'apply append');
check((int)$rows('SELECT COUNT(*) c FROM b2b_project_openings WHERE room_uuid=?',[$targets[0]])[0]['c']===20,'append keeps existing');
$tOpenings=array_column($rows('SELECT opening_uuid FROM b2b_project_openings WHERE room_uuid=? ORDER BY position',[$targets[1]]),'opening_uuid');
status($changes([['op'=>'treatment.copySet','sourceOpeningId'=>$w1,'targetOpeningIds'=>array_slice($tOpenings,0,2),'mode'=>'replace']]),200,'copy set');
$current=array_column($rows('SELECT treatment_uuid FROM b2b_project_treatments WHERE opening_uuid=? ORDER BY position',[$w1]),'treatment_uuid');
status($changes([['op'=>'reorder','level'=>'treatment','parentId'=>$w1,'ids'=>array_reverse($current)]]),200,'reorder');
check(array_column($rows('SELECT treatment_uuid FROM b2b_project_treatments WHERE opening_uuid=? ORDER BY position',[$w1]),'treatment_uuid')===array_reverse($current),'reordered');
error($changes([['op'=>'reorder','level'=>'treatment','parentId'=>$w1,'ids'=>[$current[0]]]]),409,'PROJECT_CHANGED');
$limit=error($changes([['op'=>'opening.duplicate','id'=>$w1,'names'=>array_map(fn($n)=>"X$n",range(1,60))]]),422,'PROJECT_LIMIT_EXCEEDED');
check($limit['error']['details']['level']==='opening','per-room limit');
$moved=$dup['created'][0][18];
status($changes([['op'=>'room.move','id'=>$moved,'expectedVersion'=>1,'zoneId'=>$floor2]]),200,'move room');
$removeRoom=$dup['created'][0][17];
status($changes([['op'=>'room.remove','id'=>$removeRoom,'expectedVersion'=>1]]),200,'remove room subtree');
check($rows('SELECT 1 FROM b2b_project_openings WHERE room_uuid=?',[$removeRoom])===[],'subtree removed');
check($snapshot($financialTables)===$money,'workspace edits never touch the ledger');

// Commercial projection uses the canonical Classic calculator.
$detail=status($get("$p/rooms/$room"),200,'room');
$lines=[];foreach($detail['room']['openings'] as $o) foreach($o['treatments'] as $t) $lines[]=['id'=>null,'productCode'=>$t['productCode'],'productName'=>$t['productName'],'variant'=>$t['variant'],'color'=>$t['color'],'kind'=>$t['kind'],
    'width'=>$t['width'],'height'=>$t['height'],'quantity'=>$t['quantity'],'meters'=>$t['meters'],'pricingUnit'=>$t['pricingUnit'],'unitPriceNet'=>$t['unitPriceNet'],'discountPercent'=>$t['discountPercent'],'vatPercent'=>$t['vatPercent'],'notes'=>null,'productionNotes'=>null];
$expected=OrderCalculator::calculate('RON',$lines);
$commercial=status($get("$p/commercial"),200,'commercial');
$roomSummary=array_values(array_filter($commercial['zones'][0]['rooms'],fn($r)=>$r['id']===$room))[0];
check($roomSummary['totals']===$expected['totals'],'room projection = Classic calculation '.json_encode([$roomSummary['totals'],$expected['totals']]));
check($detail['room']['openings'][1]['treatments'][0]['totals']===['baseNet'=>'243.00','discountNet'=>'24.30','net'=>'218.70','vat'=>'41.55','gross'=>'260.25'],'exact line money '.json_encode($detail['room']['openings'][1]['treatments'][0]['totals']));
check($commercial['treatmentCount']===count($commercial['treatments']) && $commercial['complete'],'project projection complete');

// Scene contract: renderer neutral, centimetres, no money.
$scene=status($get("$p/scene",['roomId'=>$room]),200,'scene');
check($scene['schema']==='arasya.scene/1' && $scene['unit']==='cm' && $scene['rooms'][0]['dimensions']['width']===420 && $scene['rooms'][0]['openings'][0]['treatments'][0]['layer']===1,'scene structure');
$sceneJson=json_encode($scene);
foreach(['unitPrice','gross','vat','net','totals','mesh','camera','shader'] as $forbidden) check(!str_contains($sceneJson,'"'.$forbidden),"scene has no $forbidden");

// Conversion to a Classic draft: exactly once, traceable, multiple orders per project.
$roomTreatments=array_merge(...array_map(fn($o)=>array_column($o['treatments'],'id'),array_slice($detail['room']['openings'],0,2)));
$revision=status($get($p),200,'outline')['project']['revision'];
error($send('POST',"$p/orders",['treatmentIds'=>$roomTreatments,'expectedRevision'=>$revision-1]),409,'PROJECT_CHANGED');
error($send('POST',"$p/orders",['treatmentIds'=>[],'expectedRevision'=>$revision]),422,'INVALID_PROJECT_SCOPE');
error($send('POST',"$p/orders",['treatmentIds'=>[$uuid()],'expectedRevision'=>$revision]),422,'INVALID_PROJECT_SCOPE');
error($send('POST',"$p/orders",['treatmentIds'=>$roomTreatments,'expectedRevision'=>$revision,'currencyCode'=>'EUR']),400,'INVALID_REQUEST');
$money=$snapshot($financialTables);$production=$snapshot($productionTables);
$convertKey='convert-'.$suffix;
$converted=status($send('POST',"$p/orders",['treatmentIds'=>$roomTreatments,'expectedRevision'=>$revision],$convertKey),201,'convert');
$orderId=$converted['orderId'];
check(status($send('POST',"$p/orders",['treatmentIds'=>$roomTreatments,'expectedRevision'=>$revision],$convertKey),201,'convert replay')['orderId']===$orderId,'replay returns same order');
check(count($rows('SELECT 1 FROM b2b_project_orders WHERE project_uuid=?',[$project]))===1,'one order for one intent');
$dupScope=error($send('POST',"$p/orders",['treatmentIds'=>[$roomTreatments[0]],'expectedRevision'=>$revision+1]),409,'ORDER_ALREADY_CREATED_FROM_SCOPE');
check($dupScope['error']['details']['treatmentIds']===[$roomTreatments[0]],'conflict names the treatment');
$order=status($get("/b2b/orders/$orderId"),200,'converted order')['order'];
check($order['status']==='draft' && count($order['lines'])===4 && $order['currencyCode']==='RON' && $order['customerReference']==='HM-2026','plain Classic draft');
check($order['lines'][0]['productCode']===$detail['room']['openings'][0]['treatments'][0]['productCode'] && $order['lines'][0]['meters']==='5.400' && $order['lines'][0]['kind']==='drapery','lines in tree order with Classic kind');
check($order['calculation']['totals']===OrderCalculator::calculate('RON',array_slice($lines,0,4))['totals'],'Classic engine priced the converted draft');
check($order['origin']['projectId']===$project && $order['origin']['lines'][$order['lines'][0]['id']]['room']['name']==='Camera 101'
    && $order['origin']['lines'][$order['lines'][0]['id']]['opening']['width']==='160.000','line trace');
check($snapshot($financialTables)===$money && $snapshot($productionTables)===$production,'conversion touches neither ledger nor production');
$projectOrders=status($get("$p/orders"),200,'project orders')['items'];
check(count($projectOrders)===1 && $projectOrders[0]['code']===$order['code'] && $projectOrders[0]['lineCount']===4,'project -> orders trace');
// Concurrent conversions of the same scope with different keys: exactly one order.
$copyIds=array_column($rows('SELECT t.treatment_uuid FROM b2b_project_treatments t JOIN b2b_project_openings o ON o.opening_uuid=t.opening_uuid WHERE o.room_uuid=? ORDER BY o.position,t.position',[$copy]),'treatment_uuid');
$revision=status($get($p),200,'outline')['project']['revision'];
$start=(string)(microtime(true)+0.3);$workers=[];
foreach(['race-a-','race-b-'] as $prefix){$proc=proc_open([PHP_BINARY,__DIR__.'/fixtures/b2b-order-worker.php',$db,'POST',"$p/orders",json_encode(['treatmentIds'=>$copyIds,'expectedRevision'=>$revision]),$prefix.$suffix,$root['cookie'],$root['csrf'],$start],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$proc,$pipes];}
$results=[];foreach($workers as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'worker '.$err);$results[]=json_decode($out,true);}
usort($results,fn($a,$b)=>$a['status']<=>$b['status']);
check($results[0]['status']===201 && $results[1]['status']===409 && in_array($results[1]['error'],['PROJECT_CHANGED','ORDER_ALREADY_CREATED_FROM_SCOPE'],true),'race: one conversion '.json_encode($results));
check(count($rows('SELECT 1 FROM b2b_project_orders WHERE project_uuid=?',[$project]))===2,'project has many orders over time');
$second=$results[0]['orderId'];

// Snapshot boundaries: finalize, then project edits never change the order; production gets the frozen location.
$order=status($send('POST',"/b2b/orders/$orderId/finalize",['expectedVersion'=>$order['version']]),200,'finalize converted')['detail']['order'];
$frozen=$rows('SELECT * FROM b2b_order_lines WHERE order_uuid=? ORDER BY line_number',[$orderId]);$frozenOrder=$rows('SELECT * FROM b2b_orders WHERE order_uuid=?',[$orderId]);
$roomRead=status($get("$p/rooms/$room"),200,'room')['room'];$t=$roomRead['openings'][0]['treatments'][0];
status($changes([['op'=>'treatment.update','id'=>$t['id'],'expectedVersion'=>$t['version'],'fields'=>$treatment('blackout','CHANGED',['unitPriceNet'=>'99.00','width'=>'999'])],
    ['op'=>'room.update','id'=>$room,'expectedVersion'=>$roomRead['version'],'fields'=>['name'=>'Camera 101 redenumită']]]),200,'edit after finalize');
check($rows('SELECT * FROM b2b_order_lines WHERE order_uuid=? ORDER BY line_number',[$orderId])===$frozen && $rows('SELECT * FROM b2b_orders WHERE order_uuid=?',[$orderId])===$frozenOrder,'finalized order unchanged by project edits');
$money=$snapshot($financialTables);
$submitted=status($send('POST',"/b2b/orders/$orderId/production",['expectedVersion'=>$order['version']]),201,'explicit production submit');
check($snapshot($financialTables)===$money,'submission leaves ledger unchanged');
$global=$submitted['production']['operationalOrderId'];
$op=$rows('SELECT * FROM operational_orders WHERE global_order_id=?',[$global])[0];
$items=$rows('SELECT * FROM operational_order_items WHERE order_uuid=? ORDER BY line_number',[$op['order_uuid']]);
$ctx=json_decode($items[0]['production_context'],true);
check($ctx['project']['room']['name']==='Camera 101' && $ctx['project']['opening']['name']==='Fereastra 1' && $ctx['project']['zone']['level']===1 && $ctx['project']['project']['code']===$outline['project']['code'],'frozen project location in production');
check(!preg_match('/unitPrice|gross|net"|vat|balance|payment|amount/i',$items[0]['production_context']),'no money in production context');
status($changes([['op'=>'room.update','id'=>$room,'expectedVersion'=>$roomRead['version']+1,'fields'=>['name'=>'Camera 101 bis']]]),200,'edit after production');
check($rows('SELECT production_context FROM operational_order_items WHERE order_uuid=? ORDER BY line_number',[$op['order_uuid']])===array_map(fn($i)=>['production_context'=>$i['production_context']],$items),'production snapshot immutable');
// Staff resolves the canonical QR and sees the project location per item.
$workflow=(new Arasya\Operations\Production\PdoProductionWorkflowRepository($pdo))->current();
$employee=$c->employeeAdmin()->create('Atelier test','atelier.'.$suffix,null,'pregatire-material','employee','Atelier test passphrase',array_map(fn($s)=>$s->id,$workflow->stages),'project-test');
$operator=T::login($k,'atelier.'.$suffix,'Atelier test passphrase');
$qr=$c->projectionWriter()->qrPayloadFor($global);
$resolved=status(T::call($k,'POST','/orders/resolve-qr',['token'=>$qr],['x-csrf-token'=>$operator['csrf']],$operator['cookie']),200,'Staff resolves project-origin QR');
check($resolved['id']===$global && count($rows('SELECT 1 FROM order_qr_references WHERE order_uuid=?',[$op['order_uuid']]))===1,'one canonical order QR, no per-item QR');
$staff=status(T::call($k,'GET','/orders/'.$global,null,[],$operator['cookie']),200,'Staff detail');
check($staff['products'][0]['productionContext']['project']['opening']['name']==='Fereastra 1','Staff sees project location after scan');
// Production sheet: manufacturing snapshot only, canonical QR, no money.
$sheet=(new Arasya\Operations\B2B\ProductionQueries($pdo,new Arasya\Operations\Authorization\AuthorizationService(),new Arasya\Operations\Production\ProductionWorkflowService(new Arasya\Operations\Production\PdoProductionWorkflowRepository($pdo))))
    ->sheet($rootIdentity($rows('SELECT employee_uuid FROM system_root_identity')[0]['employee_uuid']),$orderId,new DateTimeImmutable());
check($sheet['qrPayload']===$qr && $sheet['projects'][0]['code']===$outline['project']['code'] && count($sheet['items'])===4,'sheet dataset');
$keys=[];array_walk_recursive($sheet,function($v,$key)use(&$keys){$keys[]=(string)$key;});
check(array_intersect(array_map('strtolower',$keys),['unitpricenet','gross','net','vat','totals','balance','payment','amount','currencycode','discountpercent'])===[],'sheet has no money fields');
$pdf=T::call($k,'GET',"/b2b/orders/$orderId/production-sheet.pdf",null,[],$root['cookie'],['lang'=>'tr']);
check($pdf['status']===200,'sheet download');
error($get("/b2b/orders/{$wholesale['id']}/production-sheet.pdf"),409,'PRODUCTION_NOT_SUBMITTED');
$big=$sheet;$big['items']=[];
for($i=1;$i<=120;$i++){$item=$sheet['items'][$i%4];$item['lineNumber']=$i;$item['context']['project']['room']['id']='room-'.intdiv($i,6);$item['context']['project']['room']['name']='Camera '.(100+intdiv($i,6));$big['items'][]=$item;}
$bigPdf=ProductionSheetPdf::render($big,'ro');
preg_match('/\/Type \/Pages \/Kids \[[^\]]*\] \/Count (\d+)/',$bigPdf,$m);
check(str_starts_with($bigPdf,'%PDF-1.4') && (int)$m[1]>=20 && (int)$m[1]<=45,'120-item sheet paginates without splitting blocks ('.$m[1].' pages)');
// Cancelled orders free their treatments; the converted second order is a normal draft.
$secondOrder=status($get("/b2b/orders/$second"),200,'second')['order'];
status($send('POST',"/b2b/orders/$second/cancel",['expectedVersion'=>$secondOrder['version']]),200,'cancel second draft');
$revision=status($get($p),200,'outline')['project']['revision'];
status($send('POST',"$p/orders",['treatmentIds'=>$copyIds,'expectedRevision'=>$revision]),201,'cancelled scope can be ordered again');

// Proposal PDF: 20 identical rooms print once with their count.
$proposal=T::call($k,'GET',"$p/proposal.pdf",null,[],$root['cookie'],['lang'=>'ro']);
check($proposal['status']===200,'proposal download');
$data=(new Arasya\Operations\B2B\ProjectQueries($pdo,new Arasya\Operations\Authorization\AuthorizationService(),$c->clock()))->proposal($rootIdentity($rows('SELECT employee_uuid FROM system_root_identity')[0]['employee_uuid']),$project);
$pages=fn(string $pdf)=>preg_match('/\/Type \/Pages \/Kids \[[^\]]*\] \/Count (\d+)/',$pdf,$m)?(int)$m[1]:0;
check($pages(ProjectProposalPdf::render($data,'ro'))>=2,'full proposal renders');
$sample=null;foreach($data['zones'][0]['rooms'] as $r) if(count($r['openings'])===2) { $sample=$r; break; }
$data['zones']=[array_replace($data['zones'][0],['rooms'=>array_map(fn($n)=>array_replace($sample,['name'=>"Camera $n"]),range(101,120))])];
$grouped=$pages(ProjectProposalPdf::render($data,'ro'));
foreach($data['zones'][0]['rooms'] as $i=>&$r) $r['openings'][0]['name'].=' '.$i;
unset($r);
$distinct=$pages(ProjectProposalPdf::render($data,'tr'));
check(ProjectProposalPdf::roomRange(['Camera 101','Camera 104','Camera 105','Camera 106','Camera 120'])==='Camera 101, Camera 104–106, Camera 120','group title never hides missing rooms');
check(ProjectProposalPdf::roomDimensions(['widthCm'=>'400.000','lengthCm'=>null,'ceilingHeightCm'=>null],['dim_width'=>'lățime','dim_length'=>'lungime','dim_ceiling'=>'tavan'])==='lățime 400 cm','partial dimensions are named');
check($grouped>=1 && $grouped*2<$distinct,"identical rooms grouped ($grouped vs $distinct pages)");

// Status lifecycle and permissions.
$detail=status($get($p),200,'outline')['project'];
status($send('POST',"$p/status",['status'=>'active','expectedVersion'=>$detail['version']]),200,'activate');
$archived=status($send('POST',"$p/status",['status'=>'archived','expectedVersion'=>$detail['version']+1]),200,'archive')['detail'];
check($archived['capabilities']['canUpdate']===false && $archived['project']['archivedAt']!==null,'archived is read-only');
error($changes([['op'=>'zone.update','id'=>$floor1,'expectedVersion'=>1,'fields'=>['name'=>'X']]]),409,'PROJECT_NOT_EDITABLE');
error($send('POST',"$p/orders",['treatmentIds'=>[$ids[1]],'expectedRevision'=>$archived['project']['revision']]),409,'PROJECT_NOT_EDITABLE');
status($send('POST',"$p/status",['status'=>'active','expectedVersion'=>$archived['project']['version']]),200,'reactivate');
$make=function(string $label,array $permissions,array $apps=['b2b'])use($send,$c,$suffix,$k){$r=status($send('POST','/management/roles',['name'=>$label.$suffix,'authorityRank'=>200,'permissions'=>$permissions]),201,'role')['role']['id'];$e=$c->employeeAdmin()->create($label,$label.'.'.$suffix,null,'pregatire-material','employee','Project user passphrase',[],'test');status($send('PUT',"/management/employees/{$e->employeeUuid}/applications",['applications'=>$apps]),200,'app');status($send('PUT',"/management/employees/{$e->employeeUuid}/roles",['roleIds'=>[$r]]),200,'roles');return T::login($k,$label.'.'.$suffix,'Project user passphrase')+['id'=>$e->employeeUuid];};
$viewer=$make('viewer',['b2b.projects.view']);
status($get($p,[],$viewer),200,'viewer reads');
error($changes([['op'=>'zone.update','id'=>$floor1,'expectedVersion'=>1,'fields'=>['name'=>'X']]],null,$viewer),403,'UNAUTHORIZED_ACTION');
error($send('POST','/b2b/projects',['companyId'=>$company,'name'=>'X','propertyType'=>'other'],null,[],$viewer),403,'UNAUTHORIZED_ACTION');
$converter=$make('converter',['b2b.projects.view','b2b.projects.convert']);
$revision=status($get($p),200,'outline')['project']['revision'];
error($send('POST',"$p/orders",['treatmentIds'=>[$ids[1]],'expectedRevision'=>$revision],null,[],$converter),403,'UNAUTHORIZED_ACTION');
$editor=$make('editor',['b2b.projects.view','b2b.projects.update']);
error($send('POST',"$p/status",['status'=>'archived','expectedVersion'=>$archived['project']['version']+1],null,[],$editor),403,'UNAUTHORIZED_ACTION');
$staffOnly=$make('staffonly',['b2b.projects.view'],['staff']);
error($get($p,[],$staffOnly),403,'APPLICATION_ACCESS_DENIED');
error($get('/b2b/projects',[],$make('none',[])),403,'UNAUTHORIZED_ACTION');
$access=status(T::call($k,'GET','/b2b/access',null,[],$editor['cookie']),200,'access');
check(in_array('b2b.projects.update',$access['permissions'],true) && !in_array('b2b.projects.convert',$access['permissions'],true),'access lists project permissions');
error($send('POST',"$p/changes",['operations'=>[]],null,['x-csrf-token'=>'invalid']),403,'CSRF_INVALID');
error($send('POST',"$p/changes",['operations'=>[['op'=>'zone.create','id'=>$uuid(),'fields'=>['name'=>'Z']]]],null,['origin'=>'https://evil.invalid']),403,'ORIGIN_DENIED');
error($send('POST',"$p/changes",['operations'=>[['op'=>'zone.create','id'=>$uuid(),'fields'=>['name'=>'Z']]]],null,['idempotency-key'=>'']),400,'INVALID_IDEMPOTENCY_KEY');
$activity=[];$cursor=null;
do { $page=status($get("$p/activity",['limit'=>'100']+($cursor?['cursor'=>$cursor]:[])),200,'activity'); $activity=[...$activity,...$page['items']]; $cursor=$page['nextCursor']; } while($cursor!==null);
$actions=array_column($activity,'action');
check(count($activity)===count($rows('SELECT 1 FROM b2b_project_activity_events WHERE project_uuid=?',[$project])),'activity pages are complete');
check(!array_diff(['project_created','zone_created','room_duplicated','opening_duplicated','room_configuration_applied','treatment_set_copied','children_reordered','order_created','project_status_changed','room_removed','room_moved'],$actions),'every operation is audited');
$audit=json_encode($activity);
check(!str_contains($audit,'45.00') && !str_contains($audit,'99.00') && !str_contains($audit,'VOAL-EDIT'),'audit records field names, never values');
echo "PASS MySQL B2B projects $checks checks\n";
