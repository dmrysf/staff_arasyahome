<?php
declare(strict_types=1);
use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__).'/bootstrap.php'; require __DIR__.'/OperationsTestSupport.php';
$db=T::requireTestDatabase(); if($db===null){echo "SKIP MySQL B2B production: no test database.\n";exit;}
$checks=0;
function check(bool $v,string $why): void { global $checks; $checks++; if(!$v) throw new RuntimeException('FAIL '.$why); }
function status(array $r,int $expected,string $why): array { check($r['status']===$expected,$why.' '.json_encode($r['body'])); return $r['body']??[]; }
function error(array $r,int $s,string $code): void { $b=status($r,$s,$code); check(($b['error']['code']??null)===$code,$code); }
$config=T::config($db);$pdo=Connection::create($config);$migrations=dirname(__DIR__).'/database/migrations';(new MigrationRunner($pdo))->migrate($migrations);
require __DIR__.'/HandoffSchemaFixture.php';restorePreHandoffTestSchema($pdo);
$grantsBefore=$pdo->query('SELECT * FROM role_permissions ORDER BY role_id,permission_id')->fetchAll();
$financeBefore=$pdo->query('SELECT * FROM b2b_account_movements ORDER BY movement_uuid')->fetchAll();
check((new MigrationRunner($pdo))->migrate($migrations)===['012_b2b_production_handoff.sql','013_b2b_projects.sql','014_production_exceptions.sql','015_cutting_pool.sql','016_management_analytics.sql','017_production_documents.sql','018_production_authority.sql','019_production_qr_authority.sql', '020_production_document_authority.sql', '021_document_scopes.sql'],'011→012→013→014→015 official additive upgrade');
check((new MigrationRunner($pdo))->migrate($migrations)===[],'012 recorded exactly once');
check($pdo->query("SELECT rp.* FROM role_permissions rp JOIN roles r ON r.role_id=rp.role_id WHERE rp.permission_id NOT IN (SELECT permission_id FROM permissions WHERE permission_key = 'production.manage_authority') AND r.role_key NOT IN ('operations-manager','analytics-reader','production-documents-operator','document-revision-approver') ORDER BY rp.role_id,rp.permission_id")->fetchAll()===$grantsBefore,'012/013 grant no roles; 014 grants only its new operations-manager template');
check($pdo->query('SELECT * FROM b2b_account_movements ORDER BY movement_uuid')->fetchAll()===$financeBefore,'012 leaves financial evidence unchanged');
foreach(glob(dirname(__DIR__).'/database/seeds/*.sql') as $f)(new SqlFileRunner($pdo))->run($f);
$c=new Container($config,$pdo);$k=$c->kernel();$suffix=bin2hex(random_bytes(5));$pdo->exec('DELETE FROM system_root_identity');
$name='handoff.root.'.$suffix;$temp=(new RootBootstrapService($pdo,$c->passwordHasher(),$c->clock()))->bootstrap('handoff-test',$name);
$root=T::login($k,$name,$temp);status(T::call($k,'POST','/auth/password',['currentPassword'=>$temp,'newPassword'=>'Handoff test passphrase 2026!'],['x-csrf-token'=>$root['csrf']],$root['cookie']),200,'password');
$root=T::login($k,$name,'Handoff test passphrase 2026!');
$send=fn($method,$path,$body=[],?string $key=null,$headers=[])=>T::call($k,$method,$path,$body,['x-csrf-token'=>$root['csrf'],'idempotency-key'=>$key??('handoff-'.bin2hex(random_bytes(8))),...$headers],$root['cookie']);
$get=fn($path,$query=[])=>T::call($k,'GET',$path,null,[],$root['cookie'],$query);
$rows=function($sql,$params=[])use($pdo){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);};
$snapshot=function(array $tables)use($rows){$out=[];foreach($tables as $table){$r=array_map(serialize(...),$rows('SELECT * FROM '.$table));sort($r);$out[$table]=hash('sha256',implode('\n',$r));}return $out;};
$externalBefore=$snapshot(['order_sources']);
$financialTables=['b2b_account_movements','b2b_account_allocations','b2b_account_allocation_releases','b2b_account_activity_events','b2b_account_idempotency'];
$createdCompany=status($send('POST','/b2b/companies',['legalName'=>'Frozen Company '.$suffix,'countryCode'=>'RO','taxIdentifier'=>'HAND'.$suffix,
    'contact'=>['name'=>'Frozen contact','isPrimary'=>true],
    'address'=>['type'=>'delivery','countryCode'=>'RO','city'=>'Frozen city','addressLine1'=>'Frozen address','isPrimary'=>true]]),201,'company');
$company=$createdCompany['companyId'];$companyDetail=status($get('/b2b/companies/'.$company),200,'company identities');
$contact=$companyDetail['contacts'][0]['id'];$address=$companyDetail['addresses'][0]['id'];
$line=['productCode'=>'CURTAIN','productName'=>null,'variant'=>'Wave','color'=>'Alb','kind'=>'curtain','width'=>'200','height'=>'260','quantity'=>4,'meters'=>'13.5','pricingUnit'=>'meter','unitPriceNet'=>'10.00','discountPercent'=>'0','vatPercent'=>'19','notes'=>'Line note','productionNotes'=>'Line workshop note'];
$draft=function(array $lines=[],string $currency='RON')use($send,$company,$contact,$address,$line){return status($send('POST','/b2b/orders',['companyId'=>$company,'currencyCode'=>$currency,'contactId'=>$contact,'deliveryAddressId'=>$address,'productionNotes'=>'Order workshop note','lines'=>$lines?:[$line]]),201,'draft')['detail']['order'];};
$finalize=function(array $o)use($send){return status($send('POST',"/b2b/orders/{$o['id']}/finalize",['expectedVersion'=>$o['version']]),200,'finalize')['detail']['order'];};
$o=$draft([$line,array_replace($line,['kind'=>'drapery','productCode'=>'D-1']),array_replace($line,['kind'=>'other','productCode'=>'O-1'])]);
$path="/b2b/orders/{$o['id']}/production";
error($send('POST',$path,['expectedVersion'=>1]),409,'PRODUCTION_NOT_ELIGIBLE');
$o=$finalize($o);$frozen=$rows('SELECT * FROM b2b_orders WHERE order_uuid=?',[$o['id']]);
error($send('POST',$path,['expectedVersion'=>$o['version']+1]),409,'ORDER_CHANGED');
check($rows("SELECT * FROM operational_orders WHERE source_key='b2b' AND source_order_id=?",[$o['id']])===[],'finalize never auto-submits');
$receivable=$rows("SELECT movement_uuid FROM b2b_account_movements WHERE order_uuid=? AND movement_type='order_receivable'",[$o['id']])[0]['movement_uuid'];
status($send('POST',"/b2b/accounts/$company/payments",['currencyCode'=>'RON','amount'=>'20.00','method'=>'cash','valueDate'=>date('Y-m-d'),
    'allocations'=>[['receivableId'=>$receivable,'amount'=>'20.00']]]),201,'preexisting payment/allocation');
$money=$snapshot($financialTables);
$pdo->prepare("UPDATE b2b_companies SET legal_name='Changed live',status='inactive' WHERE company_uuid=?")->execute([$company]);
$pdo->prepare("UPDATE b2b_company_contacts SET full_name='Changed live contact' WHERE contact_uuid=?")->execute([$contact]);
$pdo->prepare("UPDATE b2b_company_addresses SET address_line_1='Changed live address' WHERE address_uuid=?")->execute([$address]);
$key='submission-'.$suffix;$submitted=status($send('POST',$path,['expectedVersion'=>$o['version']],$key),201,'submit inactive historical company');
$progress=$submitted['production'];$global=$progress['operationalOrderId'];
check($global==='b2b:'.$o['id'] && $progress['stage']['id']==='waiting' && $progress['stage']['ordinal']===1,'canonical identity/waiting');
check($progress['workflow']==='curtain-production@1' && $progress['totalStages']===14,'canonical workflow');
check($snapshot($financialTables)===$money && $rows('SELECT * FROM b2b_orders WHERE order_uuid=?',[$o['id']])===$frozen,'handoff changes neither finance nor frozen commercial order');
$dashboard=status($get('/management/orders/'.$global),200,'Dashboard canonical trace');
check($dashboard['activity'][0]['action']==='production_submitted' && $dashboard['production']['stage']['id']==='waiting','shared production timeline includes initial handoff');
check($o['contactSnapshot']['name']==='Frozen contact' && $o['deliveryAddressSnapshot']['addressLine1']==='Frozen address','contact/address snapshots stay frozen');
$op=$rows('SELECT * FROM operational_orders WHERE global_order_id=?',[$global])[0];
$context=json_decode($op['production_context'],true);check($context['company']['legalName']==='Frozen Company '.$suffix,'frozen company not live');
check($op['source_key']==='b2b' && $op['source_order_id']===$o['id'] && $op['order_number']===$o['code'] && $op['production_authority']==='operations','traceability');
$items=$rows('SELECT * FROM operational_order_items WHERE order_uuid=? ORDER BY line_number',[$op['order_uuid']]);
check(count($items)===3 && array_column($items,'source_item_id')===array_column($o['lines'],'id'),'all lines/UUIDs preserved');
foreach($items as $n=>$item){$cx=json_decode($item['production_context'],true);check($cx['kind']===$o['lines'][$n]['kind'] && $cx['notes']==='Line note' && $cx['productionNotes']==='Line workshop note','type/notes');check($item['meters']==='13.500' && (int)$item['quantity']===4,'whole-line meters');}
check(!str_contains($op['production_context'],'balance') && !str_contains($items[0]['production_context'],'unitPrice'),'no finance context');
$sideEffects=$snapshot(['operational_orders','operational_order_items','order_qr_references','order_activity_events','b2b_production_handoffs']);
status($send('POST',$path,['expectedVersion'=>$o['version']],$key),201,'same intent replay');
status($send('POST',$path,['expectedVersion'=>$o['version']]),200,'new key same business handoff');
check($snapshot(['operational_orders','operational_order_items','order_qr_references','order_activity_events','b2b_production_handoffs'])===$sideEffects,'no duplicate side effects');
error($send('POST',$path,['expectedVersion'=>$o['version']+1],$key),409,'IDEMPOTENCY_CONFLICT');
error($send('POST',$path,['expectedVersion'=>$o['version'],'stage'=>'delivery']),400,'INVALID_REQUEST');
foreach(['source','workflow','owner','operationalOrderId','companyId'] as $field) error($send('POST',$path,['expectedVersion'=>$o['version'],$field=>'client-controlled']),400,'INVALID_REQUEST');
error($get($path,['stage'=>'delivery']),400,'INVALID_REQUEST');
error($get('/b2b/orders/00000000-0000-4000-8000-000000000001/production'),404,'ORDER_NOT_FOUND');
error($send('POST',$path,['expectedVersion'=>$o['version']],null,['x-csrf-token'=>'invalid']),403,'CSRF_INVALID');
error($send('POST',$path,['expectedVersion'=>$o['version']],null,['origin'=>'https://evil.invalid']),403,'ORIGIN_DENIED');
error($send('POST',$path,['expectedVersion'=>$o['version']],null,['idempotency-key'=>'']),400,'INVALID_IDEMPOTENCY_KEY');
error(T::call($k,'GET',$path),401,'SESSION_EXPIRED');
error($send('PATCH',$path,['stage'=>'delivery']),405,'METHOD_NOT_ALLOWED');
error($send('POST',"/b2b/orders/{$o['id']}/cancel",['expectedVersion'=>$o['version']]),409,'ORDER_ALREADY_IN_PRODUCTION');
check($snapshot($financialTables)===$money && $rows('SELECT * FROM b2b_orders WHERE order_uuid=?',[$o['id']])===$frozen,'blocked cancel never reverses finance');
$detail=status($get("/b2b/orders/{$o['id']}"),200,'detail');check(!$detail['capabilities']['canCancel'] && $detail['order']['productionSubmitted'],'cancel UX server gate');
// Supported Staff operations, not B2B controls, move the canonical stage.
$workflow=(new Arasya\Operations\Production\PdoProductionWorkflowRepository($pdo))->current();
$employee=$c->employeeAdmin()->create('Workshop test','workshop.'.$suffix,null,'pregatire-material','employee','Workshop test passphrase',array_map(fn($s)=>$s->id,$workflow->stages),'handoff-test');
$operator=T::login($k,'workshop.'.$suffix,'Workshop test passphrase');
$staffGet=fn()=>T::call($k,'GET','/orders/'.$global,null,[],$operator['cookie']);
$staff=status($staffGet(),200,'Staff reads B2B order');check($staff['productionContext']['company']['legalName']==='Frozen Company '.$suffix,'Staff frozen company');
$qr=$c->projectionWriter()->qrPayloadFor($global);
check(is_string($qr) && str_starts_with($qr,Arasya\Operations\Order\QrReference::PREFIX),'internal handoff has canonical opaque QR');
$resolved=status(T::call($k,'POST','/orders/resolve-qr',['token'=>$qr],['x-csrf-token'=>$operator['csrf']],$operator['cookie']),200,'Staff resolves B2B QR');
check($resolved['id']===$global,'QR resolves the same canonical B2B identity');
try{$c->projectionWriter()->recordHeartbeat('b2b');throw new RuntimeException('Internal heartbeat allowed');}
catch(Arasya\Operations\Http\ApiException $e){check($e->errorCode==='INTERNAL_SOURCE_ONLY','internal identity rejects external heartbeat');}
foreach([['claim',1],['transition',2]] as [$action,$version]) status(T::call($k,'POST','/orders/'.$global.'/'.$action,['expectedVersion'=>$version],['x-csrf-token'=>$operator['csrf'],'idempotency-key'=>'staff-'.$action.$suffix],$operator['cookie']),200,$action);
$fresh=status($get($path),200,'B2B refresh')['production'];check($fresh['stage']['id']==='material-preparation' && $fresh['stage']['ordinal']===2,'canonical progress visible');
check($snapshot($financialTables)===$money,'Staff stage does not change finance');
// The same internal order follows all remaining canonical stages and completion.
for($stage=2;$stage<=14;$stage++) {
    $current=status($staffGet(),200,'Staff canonical stage');
    foreach(['claim','transition'] as $action) {
        $input=$action==='claim'?T::cuttingClaim($pdo,$global,$operator['employeeUuid'],$current['productionVersion']):['expectedVersion'=>$current['productionVersion']];
        $current=status(T::call($k,'POST','/orders/'.$global.'/'.$action,$input,
            ['x-csrf-token'=>$operator['csrf'],'idempotency-key'=>'full-'.$stage.'-'.$action.$suffix],$operator['cookie']),200,'canonical '.$action);
    }
}
$completed=status($get($path),200,'completed read')['production'];
check($completed['completedAt']!==null && $completed['stage']['id']==='delivery' && $snapshot($financialTables)===$money,'B2B completed progress stays financially isolated');
// Freshness of an internal source never requires external heartbeat.
$pdo->exec("UPDATE order_sources SET last_contact_at='2000-01-01' WHERE source_key='b2b'");
check(status($staffGet(),200,'internal freshness')['freshness']['status']==='fresh','no heartbeat expiry');
$pdo->exec("UPDATE order_sources SET last_contact_at=NULL WHERE source_key='b2b'");
$pdo->prepare("UPDATE b2b_companies SET status='active' WHERE company_uuid=?")->execute([$company]);
$eur=$finalize($draft([],'EUR'));$eurMoney=$snapshot($financialTables);
status($send('POST',"/b2b/orders/{$eur['id']}/production",['expectedVersion'=>$eur['version']]),201,'EUR independent explicit submission');
check($snapshot($financialTables)===$eurMoney,'EUR handoff leaves both currency ledgers unchanged');
$cancel=$finalize($draft());status($send('POST',"/b2b/orders/{$cancel['id']}/cancel",['expectedVersion'=>$cancel['version']]),200,'pre-handoff cancel');
error($send('POST',"/b2b/orders/{$cancel['id']}/production",['expectedVersion'=>$cancel['version']+1]),409,'PRODUCTION_NOT_ELIGIBLE');
check(count($rows("SELECT * FROM b2b_account_movements WHERE order_uuid=? AND movement_type='reversal'",[$cancel['id']]))===1,'accepted cancellation reversal');
// Simulated database failure after canonical creation rolls back all handoff records.
final class HandoffFailurePdo extends PDO { public function prepare(string $q,array $o=[]):PDOStatement|false {if(str_contains($q,'INSERT INTO b2b_production_handoffs'))throw new PDOException('Simulated handoff failure');return parent::prepare($q,$o);} }
$brokenPdo=new HandoffFailurePdo(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$config->dbHost,$config->dbPort,$db),$config->dbUser,$config->dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$broken=(new Container($config,$brokenPdo))->kernel();$doomed=$finalize($draft());$doomedPath="/b2b/orders/{$doomed['id']}/production";$beforeFailure=$snapshot($financialTables);
$failureProduction=$snapshot(['operational_orders','operational_order_items','order_qr_references','order_activity_events','b2b_production_handoffs','b2b_order_idempotency']);
error(T::call($broken,'POST',$doomedPath,['expectedVersion'=>$doomed['version']],['x-csrf-token'=>$root['csrf'],'idempotency-key'=>'failure-'.$suffix],$root['cookie']),500,'INTERNAL_ERROR');
check($rows('SELECT * FROM operational_orders WHERE source_key=? AND source_order_id=?',['b2b',$doomed['id']])===[] && $snapshot($financialTables)===$beforeFailure,'atomic failure no partial order/ledger');
check($snapshot(['operational_orders','operational_order_items','order_qr_references','order_activity_events','b2b_production_handoffs','b2b_order_idempotency'])===$failureProduction,'failure rolls back QR, items, audit, link and replay');
status($send('POST',$doomedPath,['expectedVersion'=>$doomed['version']],'failure-'.$suffix),201,'safe retry');
// Two processes, different keys, one business handoff, one event and one item set.
$raceOrder=$finalize($draft());$start=(string)(microtime(true)+0.3);$workers=[];
foreach(['race-a-','race-b-'] as $prefix){$p=proc_open([PHP_BINARY,__DIR__.'/fixtures/b2b-order-worker.php',$db,'POST',"/b2b/orders/{$raceOrder['id']}/production",json_encode(['expectedVersion'=>$raceOrder['version']]),$prefix.$suffix,$root['cookie'],$root['csrf'],$start],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$p,$pipes];}
$statuses=[];foreach($workers as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,'worker '.$err);$statuses[]=json_decode($out,true)['status'];}
sort($statuses);check($statuses===[200,201],'race existing result');$ro=$rows('SELECT * FROM operational_orders WHERE global_order_id=?',['b2b:'.$raceOrder['id']]);
check(count($ro)===1 && count($rows('SELECT * FROM operational_order_items WHERE order_uuid=?',[$ro[0]['order_uuid']]))===1 && count($rows("SELECT * FROM order_activity_events WHERE order_uuid=? AND action='production_submitted'",[$ro[0]['order_uuid']]))===1,'race exactly-once side effects');
try{$pdo->prepare('INSERT INTO b2b_production_handoffs SELECT * FROM b2b_production_handoffs WHERE b2b_order_uuid=?')->execute([$o['id']]);throw new RuntimeException('duplicate allowed');}catch(PDOException $e){check((int)$e->errorInfo[1]===1062,'DB uniqueness');}
// Deterministic REPEATABLE READ interleaving: the cancel transaction's read view predates
// a committed handoff. Company/order locks alone cannot refresh a non-locking SELECT.
final class HandoffSnapshotPdo extends PDO {
    public ?Closure $concurrentSubmit=null;
    public function beginTransaction(): bool {
        $started=parent::beginTransaction();
        if($this->concurrentSubmit!==null) {
            $submit=$this->concurrentSubmit;$this->concurrentSubmit=null;
            $this->query('SELECT b2b_order_uuid FROM b2b_production_handoffs')->fetchAll();
            $submit();
        }
        return $started;
    }
}
$staleOrder=$finalize($draft());$staleMoney=$snapshot($financialTables);
$stalePdo=new HandoffSnapshotPdo(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$config->dbHost,$config->dbPort,$db),$config->dbUser,$config->dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$stalePdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$stalePdo->concurrentSubmit=fn()=>status($send('POST',"/b2b/orders/{$staleOrder['id']}/production",['expectedVersion'=>$staleOrder['version']]),201,'handoff commits after cancel snapshot');
error(T::call((new Container($config,$stalePdo))->kernel(),'POST',"/b2b/orders/{$staleOrder['id']}/cancel",['expectedVersion'=>$staleOrder['version']],['x-csrf-token'=>$root['csrf'],'idempotency-key'=>'stale-cancel-'.$suffix],$root['cookie']),409,'ORDER_ALREADY_IN_PRODUCTION');
check($snapshot($financialTables)===$staleMoney && $rows('SELECT status FROM b2b_orders WHERE order_uuid=?',[$staleOrder['id']])[0]['status']==='finalized','stale read view cannot reverse a committed handoff');
// The same old read view must not supply pre-finalization line data to a new handoff.
$staleDraft=$draft();$freezeVersion=$staleDraft['version']+2;
$stalePdo->concurrentSubmit=function()use($send,$finalize,$staleDraft,$freezeVersion,$line,$company,$contact,$address) {
    $updated=status($send('PUT',"/b2b/orders/{$staleDraft['id']}",['expectedVersion'=>$staleDraft['version'],
        'companyId'=>$company,'currencyCode'=>'RON','contactId'=>$contact,'deliveryAddressId'=>$address,'productionNotes'=>'Order workshop note',
        'lines'=>[array_replace($line,['id'=>$staleDraft['lines'][0]['id'],'meters'=>'17.125','quantity'=>7])]]),200,'concurrent pre-freeze line edit')['detail']['order'];
    check($finalize($updated)['version']===$freezeVersion,'finalization commits after handoff read view');
};
status(T::call((new Container($config,$stalePdo))->kernel(),'POST',"/b2b/orders/{$staleDraft['id']}/production",['expectedVersion'=>$freezeVersion],['x-csrf-token'=>$root['csrf'],'idempotency-key'=>'stale-lines-'.$suffix],$root['cookie']),201,'handoff reads the locked finalized lines');
$freshItem=$rows("SELECT i.meters,i.quantity FROM operational_order_items i JOIN operational_orders o ON o.order_uuid=i.order_uuid WHERE o.global_order_id=?",['b2b:'.$staleDraft['id']])[0];
check($freshItem['meters']==='17.125' && (int)$freshItem['quantity']===7,'pre-freeze line read view cannot replace frozen production measurements');
// Cancel and submit compete for the same locks. Neither winning outcome can leave a cancelled production order.
for($round=0;$round<3;$round++) {
    $competing=$finalize($draft());$start=(string)(microtime(true)+0.3);$workers=[];
    foreach(['production','cancel'] as $action) {
        $p=proc_open([PHP_BINARY,__DIR__.'/fixtures/b2b-order-worker.php',$db,'POST',"/b2b/orders/{$competing['id']}/$action",json_encode(['expectedVersion'=>$competing['version']]),'compete-'.$round.'-'.$action.$suffix,$root['cookie'],$root['csrf'],$start],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$workers[$action]=[$p,$pipes];
    }
    $results=[];foreach($workers as $action=>[$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,'cancel race worker '.$err);$results[$action]=json_decode($out,true);}
    $state=$rows('SELECT status FROM b2b_orders WHERE order_uuid=?',[$competing['id']])[0]['status'];
    $exists=count($rows('SELECT * FROM b2b_production_handoffs WHERE b2b_order_uuid=?',[$competing['id']]));
    $reversed=count($rows("SELECT * FROM b2b_account_movements WHERE order_uuid=? AND movement_type='reversal'",[$competing['id']]));
    check($state==='finalized' ? $exists===1 && $reversed===0 && $results['production']['status']===201 && $results['cancel']['status']===409
        : $state==='cancelled' && $exists===0 && $reversed===1 && $results['cancel']['status']===200 && $results['production']['status']===409,'cancel/submission lifecycle is serialized');
}
// Narrow IAM, app gate, replay revocation, no production privilege escalation.
$make=function(string $label,array $permissions,array $apps=['b2b'])use($send,$c,$suffix,$k){$r=status($send('POST','/management/roles',['name'=>$label.$suffix,'authorityRank'=>200,'permissions'=>$permissions]),201,'role')['role']['id'];$e=$c->employeeAdmin()->create($label,$label.'.'.$suffix,null,'pregatire-material','employee','Production user passphrase',[],'test');status($send('PUT',"/management/employees/{$e->employeeUuid}/applications",['applications'=>$apps]),200,'app');status($send('PUT',"/management/employees/{$e->employeeUuid}/roles",['roleIds'=>[$r]]),200,'roles');return T::login($k,$label.'.'.$suffix,'Production user passphrase')+['id'=>$e->employeeUuid];};
foreach([['reader',['b2b.orders.view','b2b.production.view'],200,403],['submitter',['b2b.orders.view','b2b.production.submit'],403,200],['commercial',['b2b.orders.view'],403,403]] as [$label,$perms,$read,$write]){$who=$make($label,$perms);status(T::call($k,'GET',$path,null,[],$who['cookie']),$read,'narrow read');status(T::call($k,'POST',$path,['expectedVersion'=>$o['version']],['x-csrf-token'=>$who['csrf'],'idempotency-key'=>'narrow-'.$label.$suffix],$who['cookie']),$write,'narrow submit');error(T::call($k,'POST','/orders/'.$global.'/transition',['expectedProductionVersion'=>3],['x-csrf-token'=>$who['csrf'],'idempotency-key'=>'cannot-stage-'.$label.$suffix],$who['cookie']),403,'APPLICATION_ACCESS_DENIED');}
$who=$make('noapp',['b2b.orders.view','b2b.production.view','b2b.production.submit'],['dashboard']);error(T::call($k,'GET',$path,null,[],$who['cookie']),403,'APPLICATION_ACCESS_DENIED');
$who=$make('revoked',['b2b.orders.view','b2b.production.submit']);$replay=fn()=>T::call($k,'POST',$path,['expectedVersion'=>$o['version']],['x-csrf-token'=>$who['csrf'],'idempotency-key'=>'revoke-'.$suffix],$who['cookie']);status($replay(),200,'before revoke');status($send('PUT',"/management/employees/{$who['id']}/roles",['roleIds'=>[]]),200,'revoke');error($replay(),403,'UNAUTHORIZED_ACTION');
check($snapshot(['order_sources'])===$externalBefore,'source registry bytes unchanged by commands');
echo "PASS MySQL/MariaDB B2B production $checks checks\n";
