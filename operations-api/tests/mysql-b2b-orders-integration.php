<?php
declare(strict_types=1);
use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__).'/bootstrap.php';require __DIR__.'/OperationsTestSupport.php';
$db=T::requireTestDatabase();if($db===null){echo "SKIP MySQL B2B orders: ARASYA_TEST_DB_NAME not configured.\n";exit;}
$checks=0;function check(bool $v,string $m):void{global $checks;$checks++;if(!$v)throw new RuntimeException('FAIL '.$m);}
function status(array $r,int $s,string $m):array{check($r['status']===$s,$m.' got '.json_encode($r));return $r['body']??[];}
function error(array $r,int $s,string $c):void{status($r,$s,$c);check(($r['body']['error']['code']??null)===$c,$c);}
$config=T::config($db);$pdo=Connection::create($config);$dir=dirname(__DIR__).'/database/migrations';(new MigrationRunner($pdo))->migrate($dir);
check(is_file($dir.'/010_b2b_orders.sql'),'migration 010 exists');
foreach(['b2b_order_idempotency','b2b_order_activity_events','b2b_order_lines','b2b_orders','b2b_order_number_sequence'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
$pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.permission_key LIKE 'b2b.orders.%'");$pdo->exec("DELETE FROM permissions WHERE permission_key LIKE 'b2b.orders.%'");$pdo->exec("DELETE FROM schema_migrations WHERE migration_name='010_b2b_orders.sql'");
$grants=$pdo->query('SELECT * FROM role_permissions ORDER BY role_id,permission_id')->fetchAll();check((new MigrationRunner($pdo))->migrate($dir)===['010_b2b_orders.sql'],'009→010 upgrade');check((new MigrationRunner($pdo))->migrate($dir)===[],'recorded once');(new SqlFileRunner($pdo))->run($dir.'/010_b2b_orders.sql');check($pdo->query('SELECT * FROM role_permissions ORDER BY role_id,permission_id')->fetchAll()===$grants,'no automatic grants');
foreach(glob(dirname(__DIR__).'/database/seeds/*.sql') as $f)(new SqlFileRunner($pdo))->run($f);
$c=new Container($config,$pdo);$k=$c->kernel();$suffix=bin2hex(random_bytes(5));$pdo->exec('DELETE FROM system_root_identity');$user='order.root.'.$suffix;$pw=(new RootBootstrapService($pdo,$c->passwordHasher(),$c->clock()))->bootstrap('order-test',$user);$first=T::login($k,$user,$pw);status(T::call($k,'POST','/auth/password',['currentPassword'=>$pw,'newPassword'=>'Order test passphrase 2026!'],['x-csrf-token'=>$first['csrf']],$first['cookie']),200,'change root password');$root=T::login($k,$user,'Order test passphrase 2026!');
$get=fn(string $path,array $query=[])=>T::call($k,'GET',$path,null,[],$root['cookie'],$query);
$send=fn(string $method,string $path,array $data,?string $key=null,array $headers=[])=>T::call($k,$method,$path,$data,['x-csrf-token'=>$root['csrf'],'idempotency-key'=>$key??('order-test-'.bin2hex(random_bytes(8))),...$headers],$root['cookie']);
error(T::call($k,'GET','/b2b/orders'),401,'SESSION_EXPIRED');
$before=$pdo->query('SELECT COUNT(*) FROM operational_orders')->fetchColumn();
$productionSnapshot=static function() use($pdo): array {
    $out=[];
    foreach(['operational_orders','operational_order_items','order_activity_events','order_operation_idempotency','production_workflows','production_stages','employee_order_relations','order_projection_receipts','order_sources'] as $table) {
        // serialize preserves binary request hashes as well as textual/decimal columns.
        $rows=array_map(serialize(...),$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));
        sort($rows,SORT_STRING); $out[$table]=hash('sha256',implode("\n",$rows));
    }
    return $out;
};
$productionBefore=$productionSnapshot();
$company=status($send('POST','/b2b/companies',['legalName'=>'Order company '.$suffix,'displayName'=>'Display','countryCode'=>'RO','taxIdentifier'=>'ORD'.$suffix,'internalNotes'=>'private-company-secret']),201,'create company')['companyId'];
$contact=status($send('POST',"/b2b/companies/$company/contacts",['name'=>'Contact snapshot']),201,'contact')['contactId'];
$line=['productCode'=>'CURTAIN','productName'=>null,'variant'=>null,'color'=>null,'kind'=>'curtain','width'=>'200','height'=>'260','quantity'=>4,'meters'=>'13.5','pricingUnit'=>'meter','unitPriceNet'=>'10.00','discountPercent'=>'0','vatPercent'=>'19','notes'=>null];
$fields=['companyId'=>$company,'currencyCode'=>'RON','contactId'=>$contact,'billingAddressId'=>null,'deliveryAddressId'=>null,'notes'=>'commercial-notes','lines'=>[$line]];
$key='order-replay-'.$suffix;$created=status($send('POST','/b2b/orders',$fields,$key),201,'create');$id=$created['orderId'];$o=$created['detail']['order'];check($o['calculation']['totals']['net']==='135.00','meter totals');check($o['version']===1&&$o['status']==='draft'&&preg_match('/^B2B-ORD-\d{6,}$/',$o['code']),'identity');check(!str_contains(json_encode($o),'private-company-secret'),'snapshot privacy');check(status($send('POST','/b2b/orders',$fields,$key),201,'replay')['orderId']===$id,'replay identity');error($send('POST','/b2b/orders',$fields+['actor'=>'evil']),400,'INVALID_REQUEST');error($send('POST','/b2b/orders',$fields,null,['x-csrf-token'=>'invalid']),403,'CSRF_INVALID');
error($send('PUT',"/b2b/orders/$id",array_replace($fields,['currencyCode'=>'EUR','expectedVersion'=>1])),409,'CURRENCY_CHANGE_REQUIRES_EMPTY_PRICING');
$cleared=array_replace($fields,['lines'=>[array_replace($line,['unitPriceNet'=>null])],'expectedVersion'=>1]);$saved=status($send('PUT',"/b2b/orders/$id",$cleared),200,'clear price')['detail']['order'];check($saved['version']===2&&!$saved['calculation']['complete'],'incomplete persisted');error($send('POST',"/b2b/orders/$id/finalize",['expectedVersion'=>2]),422,'VALIDATION_FAILED');
$eu=array_replace($fields,['currencyCode'=>'EUR','lines'=>$cleared['lines'],'expectedVersion'=>2]);status($send('PUT',"/b2b/orders/$id",$eu),200,'safe currency change');$eu=array_replace($fields,['currencyCode'=>'EUR','expectedVersion'=>3]);$saveKey='order-save-'.$suffix;status($send('PUT',"/b2b/orders/$id",$eu,$saveKey),200,'save price');status($send('PUT',"/b2b/orders/$id",$eu,$saveKey),200,'save replay before version');error($send('PUT',"/b2b/orders/$id",$eu),409,'ORDER_CHANGED');
$finalKey='order-final-'.$suffix;$final=status($send('POST',"/b2b/orders/$id/finalize",['expectedVersion'=>4],$finalKey),200,'finalize')['detail']['order'];check($final['status']==='finalized'&&$final['version']===5,'finalization');status($send('POST',"/b2b/orders/$id/finalize",['expectedVersion'=>4],$finalKey),200,'final replay');error($send('PUT',"/b2b/orders/$id",array_replace($eu,['expectedVersion'=>5])),409,'ORDER_FINALIZED');
$pdo->prepare("UPDATE b2b_companies SET legal_name='Fresh company' WHERE company_uuid=?")->execute([$company]);$pdo->prepare("UPDATE b2b_company_contacts SET status='inactive' WHERE contact_uuid=?")->execute([$contact]);check(status($get("/b2b/orders/$id"),200,'historical detail')['order']['companySnapshot']['legalName']===$final['companySnapshot']['legalName'],'historical snapshot immutable');
$dupe=status($send('POST',"/b2b/orders/$id/duplicate",['expectedVersion'=>5]),201,'duplicate')['detail']['order'];check($dupe['id']!==$id&&$dupe['sourceOrderId']===$id&&$dupe['status']==='draft'&&$dupe['version']===1&&$dupe['contactId']===null&&$dupe['companySnapshot']['legalName']==='Fresh company','duplicate uses fresh active snapshots');
$history=status($get('/b2b/orders',['companyId'=>$company,'status'=>'all']),200,'history');check(count($history['items'])===2,'history filter');$activity=status($get("/b2b/orders/$id/activity"),200,'activity');check(count($activity['items'])>=5&&!str_contains(json_encode($activity),'commercial-notes'),'activity field names only');check($pdo->query('SELECT COUNT(*) FROM operational_orders')->fetchColumn()===$before,'production isolated');
check(!str_contains((string)$pdo->query('SELECT response_json FROM b2b_order_idempotency LIMIT 1')->fetchColumn(),'Snapshot'),'replay stores references');
// Stable row identities and all line intents participate in aggregate concurrency and replay.
$draftId=$dupe['id'];
$detail=fn($orderId)=>status($get("/b2b/orders/$orderId"),200,'detail')['order'];
$version=fn($orderId)=>$detail($orderId)['version'];
$lineKey='line-create-'.$suffix;
$lineBody=$line+['expectedVersion'=>1];
$added=status($send('POST',"/b2b/orders/$draftId/lines",$lineBody,$lineKey),200,'line create')['detail']['order'];
check(count($added['lines'])===2 && $added['lines'][0]['id']===$dupe['lines'][0]['id'],'stable line UUID');
$uuid=$added['lines'][1]['id'];
status($send('POST',"/b2b/orders/$draftId/lines",$lineBody,$lineKey),200,'line create replay');
check(count($detail($draftId)['lines'])===2,'no duplicate on replay');
error($send('POST',"/b2b/orders/$draftId/lines",array_replace($lineBody,['quantity'=>5]),$lineKey),409,'IDEMPOTENCY_CONFLICT');
$updatedLine=array_replace($line,['productCode'=>'UPDATED','quantity'=>2,'expectedVersion'=>2]);
$lineUpdateKey='line-update-'.$suffix;
status($send('PUT',"/b2b/orders/$draftId/lines/$uuid",$updatedLine,$lineUpdateKey),200,'line update');
status($send('PUT',"/b2b/orders/$draftId/lines/$uuid",$updatedLine,$lineUpdateKey),200,'line update replay');
check($detail($draftId)['lines'][1]['id']===$uuid,'line update identity');
$lineDupeKey='line-duplicate-'.$suffix;
$duplicated=status($send('POST',"/b2b/orders/$draftId/lines/$uuid/duplicate",['expectedVersion'=>3],$lineDupeKey),200,'duplicate line')['detail']['order'];
status($send('POST',"/b2b/orders/$draftId/lines/$uuid/duplicate",['expectedVersion'=>3],$lineDupeKey),200,'duplicate line replay');
check(count($duplicated['lines'])===3 && $duplicated['lines'][2]['id']!==$uuid,'new line identity');
$ids=array_reverse(array_column($duplicated['lines'],'id'));
$reorderKey='line-reorder-'.$suffix;
status($send('POST',"/b2b/orders/$draftId/lines/reorder",['lineIds'=>$ids,'expectedVersion'=>4],$reorderKey),200,'reorder');
status($send('POST',"/b2b/orders/$draftId/lines/reorder",['lineIds'=>$ids,'expectedVersion'=>4],$reorderKey),200,'reorder replay');
check(array_column($detail($draftId)['lines'],'id')===$ids,'exact ordering');
error($send('POST',"/b2b/orders/$draftId/lines/reorder",['lineIds'=>[$uuid,$uuid],'expectedVersion'=>5]),422,'VALIDATION_FAILED');
$removeKey='line-remove-'.$suffix;
status($send('POST',"/b2b/orders/$draftId/lines/$uuid/remove",['expectedVersion'=>5],$removeKey),200,'remove');
status($send('POST',"/b2b/orders/$draftId/lines/$uuid/remove",['expectedVersion'=>5],$removeKey),200,'remove replay');
check(count($detail($draftId)['lines'])===2,'removed only once');
error($send('PUT',"/b2b/orders/$draftId/lines/00000000-0000-4000-8000-000000000000",array_replace($line,['expectedVersion'=>6])),404,'ORDER_LINE_NOT_FOUND');
$cancelKey='order-cancel-'.$suffix;
status($send('POST',"/b2b/orders/$draftId/cancel",['expectedVersion'=>6],$cancelKey),200,'cancel draft');
status($send('POST',"/b2b/orders/$draftId/cancel",['expectedVersion'=>6],$cancelKey),200,'cancel replay');
$cancelled=$detail($draftId);
check($cancelled['status']==='cancelled' && $cancelled['cancelledAt']!==null && count($cancelled['lines'])===2,'cancel preserves data');
error($send('POST',"/b2b/orders/$draftId/lines",$line+['expectedVersion'=>7]),409,'ORDER_CANCELLED');
error($send('POST',"/b2b/orders/$draftId/finalize",['expectedVersion'=>7]),409,'ORDER_CANCELLED');
status($send('POST',"/b2b/orders/$draftId/duplicate",['expectedVersion'=>7]),201,'duplicate cancelled order');
$snapshotBefore=$detail($id);
status($send('POST',"/b2b/orders/$id/cancel",['expectedVersion'=>5]),200,'cancel finalized order');
$snapshotAfter=$detail($id);
foreach(['companySnapshot','contactSnapshot','lines','calculation','finalizedAt','currencyCode'] as $f)
    check($snapshotBefore[$f]===$snapshotAfter[$f],"cancel preserves finalized $f");
error($send('DELETE',"/b2b/orders/$id",[]),405,'METHOD_NOT_ALLOWED');
error($send('PATCH',"/b2b/orders/$id",['notes'=>'x']),405,'METHOD_NOT_ALLOWED');
error($send('POST','/b2b/orders',$fields,null,['origin'=>'https://evil.invalid']),403,'ORIGIN_DENIED');
error($send('POST','/b2b/orders',$fields,null,['idempotency-key'=>'']),400,'INVALID_IDEMPOTENCY_KEY');
error($get('/b2b/orders',['status'=>'production']),422,'VALIDATION_FAILED');
error($get('/b2b/orders',['currency'=>'USD']),422,'VALIDATION_FAILED');
error($get('/b2b/orders',['from'=>'2026-02-30']),422,'VALIDATION_FAILED');
error($get('/b2b/orders',['from'=>'2026-10-05','to'=>'2026-10-04']),422,'VALIDATION_FAILED');
error($get('/b2b/orders',['cursor'=>'broken']),400,'INVALID_CURSOR');
error($get('/b2b/orders',['extra'=>'x']),400,'INVALID_REQUEST');

// Freeze full fiscal/contact/address identity, then change the live records.
$pdo->prepare("UPDATE b2b_company_contacts SET status='active' WHERE contact_uuid=?")->execute([$contact]);
$address=status($send('POST',"/b2b/companies/$company/addresses",[
    'type'=>'billing','countryCode'=>'RO','city'=>'Bucuresti','addressLine1'=>'Historical street',
]),201,'billing address')['addressId'];
$delivery=status($send('POST',"/b2b/companies/$company/addresses",[
    'type'=>'delivery','countryCode'=>'RO','city'=>'Cluj','addressLine1'=>'Delivery street',
]),201,'delivery address')['addressId'];
$pdo->prepare("UPDATE b2b_companies SET registration_number='REG-ORIGINAL',vat_number='VAT-ORIGINAL' WHERE company_uuid=?")->execute([$company]);
$snapshotFields=array_replace($fields,['billingAddressId'=>$address,'deliveryAddressId'=>$delivery,'customerReference'=>'REFERENCE-UNIQUE',
    'productionNotes'=>'production-instructions','lines'=>[array_replace($line,['productionNotes'=>'line-instructions'])]]);
$snapOrder=status($send('POST','/b2b/orders',$snapshotFields),201,'snapshot draft')['detail']['order'];
$snapId=$snapOrder['id'];
$frozen=status($send('POST',"/b2b/orders/$snapId/finalize",['expectedVersion'=>1]),200,'snapshot finalize')['detail']['order'];
$pdo->prepare("UPDATE b2b_companies SET registration_number='REG-CHANGED',vat_number='VAT-CHANGED',tax_identifier='CHANGED' WHERE company_uuid=?")->execute([$company]);
$pdo->prepare("UPDATE b2b_company_contacts SET full_name='Changed person' WHERE contact_uuid=?")->execute([$contact]);
$pdo->prepare("UPDATE b2b_company_addresses SET address_line_1='Changed street' WHERE company_uuid=?")->execute([$company]);
check($detail($snapId)===$frozen,'finalized detail completely frozen');
check($frozen['companySnapshot']['registrationNumber']==='REG-ORIGINAL','registration snapshot');
check($frozen['billingAddressSnapshot']['addressLine1']==='Historical street','billing snapshot');
check($frozen['deliveryAddressSnapshot']['addressLine1']==='Delivery street','delivery snapshot');
foreach(['lines','currencyCode','notes','productionNotes','customerReference','contactId','billingAddressId','deliveryAddressId'] as $f) {
    error($send('PUT',"/b2b/orders/$snapId",array_replace($snapshotFields,[$f=>$snapshotFields[$f],'expectedVersion'=>2])),409,'ORDER_FINALIZED');
}
$dupKey='snapshot-duplicate-'.$suffix;
$snapshotCopy=status($send('POST',"/b2b/orders/$snapId/duplicate",['expectedVersion'=>2],$dupKey),201,'copy snapshot')['detail']['order'];
status($send('POST',"/b2b/orders/$snapId/duplicate",['expectedVersion'=>2],$dupKey),201,'copy replay');
check($snapshotCopy['lines'][0]['id']!==$frozen['lines'][0]['id'] && $snapshotCopy['finalizedAt']===null && $snapshotCopy['cancelledAt']===null,'fresh copy identity and lifecycle');
$pdo->prepare("UPDATE b2b_companies SET status='inactive' WHERE company_uuid=?")->execute([$company]);
error($send('POST','/b2b/orders',$fields),409,'COMPANY_INACTIVE');
error($send('POST',"/b2b/orders/$snapId/duplicate",['expectedVersion'=>2]),409,'COMPANY_INACTIVE');
status($get("/b2b/orders/$snapId"),200,'inactive company history retained');
status($get('/b2b/orders',['companyId'=>$company,'status'=>'all']),200,'inactive history list');
$pdo->prepare("UPDATE b2b_companies SET status='active' WHERE company_uuid=?")->execute([$company]);
foreach(['REFERENCE-UNIQUE','B2B-ORD-','CURTAIN','REG-ORIGINAL','Fresh company'] as $search) {
    $found=status($get('/b2b/orders',['search'=>$search]),200,'search '.$search);
    if($search!=='REG-ORIGINAL') check(count($found['items'])>0,'search result '.$search);
}

// Real simultaneous HTTP mutations on separate database connections.
function race(array $jobs,array $root,string $db): array {
    $start=(string)(microtime(true)+0.3); $workers=[];
    foreach($jobs as [$method,$path,$body,$key]) {
        $cmd=[PHP_BINARY,__DIR__.'/fixtures/b2b-order-worker.php',$db,$method,$path,json_encode($body),$key,$root['cookie'],$root['csrf'],$start];
        $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc)) throw new RuntimeException('Race worker failed to start.');
        fclose($pipes[0]); $workers[]=[$proc,$pipes];
    }
    $results=[];
    foreach($workers as [$proc,$pipes]) {
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($proc)===0,'race process '.$err);
        $results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);
    }
    return $results;
}
$r=race([['POST','/b2b/orders',$fields,'concurrent-create-a-'.$suffix],['POST','/b2b/orders',$fields,'concurrent-create-b-'.$suffix]],$root,$db);
check(array_column($r,'status')===[201,201] && $r[0]['orderId']!==$r[1]['orderId'] && $r[0]['code']!==$r[1]['code'],'concurrent unique order numbering');
$retry='concurrent-retry-'.$suffix;
$r=race([['POST','/b2b/orders',$fields,$retry],['POST','/b2b/orders',$fields,$retry]],$root,$db);
check(array_column($r,'status')===[201,201] && $r[0]['orderId']===$r[1]['orderId'],'concurrent replay produces one order');
$raceId=$r[0]['orderId'];
$r=race([['PUT',"/b2b/orders/$raceId",$fields+['expectedVersion'=>1],'race-update-a-'.$suffix],
    ['POST',"/b2b/orders/$raceId/finalize",['expectedVersion'=>1],'race-finalize-b-'.$suffix]],$root,$db);
$statuses=array_column($r,'status'); sort($statuses);
check($statuses===[200,409],'save/finalize race has one winner');
check(in_array('ORDER_CHANGED',array_column($r,'error'),true),'loser sees conflict');
$r=race([['POST',"/b2b/orders/$raceId/cancel",['expectedVersion'=>2],'race-cancel-a-'.$suffix],
    ['POST',"/b2b/orders/$raceId/cancel",['expectedVersion'=>2],'race-cancel-b-'.$suffix]],$root,$db);
$statuses=array_column($r,'status'); sort($statuses); check($statuses===[200,409],'status race has one winner');
$large=status($send('POST','/b2b/orders',array_replace($fields,['lines'=>array_fill(0,100,array_replace($line,['unitPriceNet'=>'0.01','vatPercent'=>'0','pricingUnit'=>'piece']))])),201,'100-line draft')['detail']['order'];
check(count($large['lines'])===100 && count(array_unique(array_column($large['lines'],'id')))===100 && $large['calculation']['totals']['net']==='4.00','100 exact authoritative line totals');
error($send('POST',"/b2b/orders/{$large['id']}/lines",$line+['expectedVersion'=>1]),422,'VALIDATION_FAILED');
for($n=0;$n<25;$n++) status($send('POST','/b2b/orders',array_replace($fields,['lines'=>[]])),201,'pagination draft');
$page=status($get('/b2b/orders',['companyId'=>$company,'limit'=>'25']),200,'first page');
check(count($page['items'])===25 && $page['nextCursor']!==null,'keyset first page');
$next=status($get('/b2b/orders',['companyId'=>$company,'limit'=>'25','cursor'=>$page['nextCursor']]),200,'second page');
check(array_intersect(array_column($page['items'],'id'),array_column($next['items'],'id'))===[],'no overlapping pages');

// Narrow permission matrix, independent application gate, active identity and replay protection.
$dashboard=fn($method,$path,$body=null)=>$send($method,$path,$body??[]);
$admin=$c->employeeAdmin();
$makeUser=function(string $name,array $permissions,array $applications=['b2b']) use($admin,$dashboard,$suffix,$pdo,$k) {
    $role=status($dashboard('POST','/management/roles',['name'=>$name.$suffix,'description'=>null,'authorityRank'=>200,'permissions'=>$permissions]),201,'permission role')['role']['id'];
    $employee=$admin->create($name,$name.'.'.$suffix,null,'pregatire-material','employee','Orders user passphrase',[],'test');
    status($dashboard('PUT',"/management/employees/{$employee->employeeUuid}/applications",['applications'=>$applications]),200,'applications');
    status($dashboard('PUT',"/management/employees/{$employee->employeeUuid}/roles",['roleIds'=>[$role]]),200,'roles');
    return T::login($k,$name.'.'.$suffix,'Orders user passphrase')+['id'=>$employee->employeeUuid];
};
foreach(['view','create','update','manage_status'] as $permission) {
    $who=$makeUser('order'.$permission,['b2b.orders.'.$permission]);
    $call=fn($method,$path,$body=null,$key=null)=>T::call($k,$method,$path,$body,
        ['x-csrf-token'=>$who['csrf'],'idempotency-key'=>$key??('permissions-'.bin2hex(random_bytes(8)))],$who['cookie']);
    $read=$call('GET',"/b2b/orders/$snapId");
    status($read,$permission==='view'?200:403,'domain view gate');
    $made=$call('POST','/b2b/orders',$fields);
    status($made,$permission==='create'?201:403,'domain create gate');
    if($permission==='create') check($made['body']['detail']===null,'creator without view gets references only');
    $target=status($send('POST','/b2b/orders',$fields),201,'permission draft')['detail']['order'];
    status($call('PUT',"/b2b/orders/{$target['id']}",$fields+['expectedVersion'=>1]),$permission==='update'?200:403,'update gate');
    $v=$permission==='update'?2:1;
    status($call('POST',"/b2b/orders/{$target['id']}/cancel",['expectedVersion'=>$v]),$permission==='manage_status'?200:403,'cancel gate');
}
$noApp=$makeUser('ordernoapp',['b2b.orders.view','b2b.orders.create','b2b.orders.update','b2b.orders.manage_status'],['dashboard']);
error(T::call($k,'GET','/b2b/orders',null,[],$noApp['cookie']),403,'APPLICATION_ACCESS_DENIED');
$revoked=$makeUser('orderrevoked',['b2b.orders.create','b2b.orders.view']);
$revokeKey='revoke-replay-'.$suffix;
$revokeBody=$fields;
$revokeCall=fn()=>T::call($k,'POST','/b2b/orders',$revokeBody,['x-csrf-token'=>$revoked['csrf'],'idempotency-key'=>$revokeKey],$revoked['cookie']);
status($revokeCall(),201,'create before revocation');
status($dashboard('PUT',"/management/employees/{$revoked['id']}/roles",['roleIds'=>[]]),200,'revoke role');
error($revokeCall(),403,'UNAUTHORIZED_ACTION');
status(T::call($k,'POST',"/management/employees/{$revoked['id']}/deactivate",null,['x-csrf-token'=>$root['csrf']],$root['cookie']),200,'deactivate identity');
error(T::call($k,'GET','/b2b/orders',null,[],$revoked['cookie']),401,'SESSION_EXPIRED');
check($pdo->query('SELECT COUNT(*) FROM operational_orders')->fetchColumn()===$before,'no production writes in complete lifecycle');
check($productionSnapshot()===$productionBefore,'all production rows, stages, history and replay values remain byte-identical');
check((int)$pdo->query("SELECT COUNT(*) FROM b2b_order_activity_events WHERE idempotency_key='$retry'")->fetchColumn()===1,'one activity for simultaneous create replay');
echo "PASS MySQL/MariaDB B2B orders integration $checks checks\n";
