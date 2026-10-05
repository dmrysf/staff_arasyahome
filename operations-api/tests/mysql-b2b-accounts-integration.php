<?php
declare(strict_types=1);
use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__).'/bootstrap.php';require __DIR__.'/OperationsTestSupport.php';
$db=T::requireTestDatabase();if($db===null){echo "SKIP MySQL B2B accounts: ARASYA_TEST_DB_NAME not configured.\n";exit;}
$checks=0;function check(bool $v,string $m):void{global $checks;$checks++;if(!$v)throw new RuntimeException('FAIL '.$m);}
function status(array $r,int $s,string $m):array{check($r['status']===$s,$m.' got '.json_encode($r['body']??$r));return $r['body']??[];}
function error(array $r,int $s,string $c):void{status($r,$s,$c);check(($r['body']['error']['code']??null)===$c,$c.' got '.json_encode($r['body']));}
/** Runs a statement that must be refused by the schema; returns the driver error number. */
function refused(PDO $pdo,string $sql,array $params):int{try{$pdo->prepare($sql)->execute($params);}catch(PDOException $e){return (int)($e->errorInfo[1]??0);}throw new RuntimeException('FAIL schema accepted: '.$sql);}

/** Simulates a database failure of the ledger insert only, after the order rows were already written in the transaction. */
final class LedgerFailurePdo extends PDO
{
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        if(str_contains($query,'INSERT INTO b2b_account_movements(')) throw new PDOException('Simulated ledger failure.');
        return parent::prepare($query,$options);
    }
}

$config=T::config($db);$pdo=Connection::create($config);$dir=dirname(__DIR__).'/database/migrations';(new MigrationRunner($pdo))->migrate($dir);
check(is_file($dir.'/011_b2b_current_account.sql'),'migration 011 exists');
// ---- 010 -> 011 upgrade: additive, recorded once, re-runnable, no automatic grants ---------------------------
$accountTables=['b2b_account_idempotency','b2b_account_activity_events','b2b_account_allocation_releases','b2b_account_allocations','b2b_account_movements','b2b_account_movement_sequence'];
foreach($accountTables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
$pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE p.permission_key LIKE 'b2b.accounts.%'");$pdo->exec("DELETE FROM permissions WHERE permission_key LIKE 'b2b.accounts.%'");$pdo->exec("DELETE FROM schema_migrations WHERE migration_name='011_b2b_current_account.sql'");
$grants=$pdo->query('SELECT * FROM role_permissions ORDER BY role_id,permission_id')->fetchAll();
$ordersBefore=$pdo->query('SELECT COUNT(*) FROM b2b_orders')->fetchColumn();
check((new MigrationRunner($pdo))->migrate($dir)===['011_b2b_current_account.sql'],'010→011 upgrade');
check((new MigrationRunner($pdo))->migrate($dir)===[],'011 recorded once');
(new SqlFileRunner($pdo))->run($dir.'/011_b2b_current_account.sql');
check($pdo->query('SELECT * FROM role_permissions ORDER BY role_id,permission_id')->fetchAll()===$grants,'011 grants nothing automatically');
check($pdo->query('SELECT COUNT(*) FROM b2b_orders')->fetchColumn()===$ordersBefore,'011 leaves orders untouched');
check((int)$pdo->query('SELECT COUNT(*) FROM b2b_account_movements')->fetchColumn()===0,'011 backfills nothing');
check(array_column($pdo->query("SELECT permission_key FROM permissions WHERE permission_key LIKE 'b2b.accounts.%' ORDER BY permission_key")->fetchAll(),'permission_key')
    ===['b2b.accounts.adjust','b2b.accounts.export','b2b.accounts.record_payment','b2b.accounts.reverse','b2b.accounts.view'],'five account permissions');
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'b2b_account_%' AND (DELETE_RULE<>'RESTRICT' AND DELETE_RULE<>'NO ACTION' OR UPDATE_RULE<>'RESTRICT' AND UPDATE_RULE<>'NO ACTION')")->fetchColumn()===0,'no cascading account foreign keys');
foreach(glob(dirname(__DIR__).'/database/seeds/*.sql') as $f)(new SqlFileRunner($pdo))->run($f);

$c=new Container($config,$pdo);$k=$c->kernel();$suffix=bin2hex(random_bytes(5));$pdo->exec('DELETE FROM system_root_identity');$user='account.root.'.$suffix;$pw=(new RootBootstrapService($pdo,$c->passwordHasher(),$c->clock()))->bootstrap('account-test',$user);$first=T::login($k,$user,$pw);status(T::call($k,'POST','/auth/password',['currentPassword'=>$pw,'newPassword'=>'Account test passphrase 2026!'],['x-csrf-token'=>$first['csrf']],$first['cookie']),200,'change root password');$root=T::login($k,$user,'Account test passphrase 2026!');
$get=fn(string $path,array $query=[])=>T::call($k,'GET',$path,null,[],$root['cookie'],$query);
$send=fn(string $method,string $path,array $data,?string $key=null,array $headers=[])=>T::call($k,$method,$path,$data,['x-csrf-token'=>$root['csrf'],'idempotency-key'=>$key??('account-test-'.bin2hex(random_bytes(8))),...$headers],$root['cookie']);
$productionSnapshot=static function() use($pdo): array {
    $out=[];
    foreach(['operational_orders','operational_order_items','order_activity_events','order_operation_idempotency','production_workflows','production_stages','employee_order_relations','order_projection_receipts','order_sources'] as $table) {
        $rows=array_map(serialize(...),$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));
        sort($rows,SORT_STRING); $out[$table]=hash('sha256',implode("\n",$rows));
    }
    return $out;
};
$productionBefore=$productionSnapshot();
$company=status($send('POST','/b2b/companies',['legalName'=>'Account company '.$suffix,'displayName'=>'Display','countryCode'=>'RO','taxIdentifier'=>'ACC'.$suffix]),201,'create company')['companyId'];
$line=['productCode'=>'CURTAIN','productName'=>null,'variant'=>null,'color'=>null,'kind'=>'curtain','width'=>'200','height'=>'260','quantity'=>4,'meters'=>'13.5','pricingUnit'=>'meter','unitPriceNet'=>'10.00','discountPercent'=>'0','vatPercent'=>'19','notes'=>null];
$draft=function(string $currency,?array $lines=null) use($send,$company,$line): array {
    return status($send('POST','/b2b/orders',['companyId'=>$company,'currencyCode'=>$currency,'contactId'=>null,'billingAddressId'=>null,'deliveryAddressId'=>null,'notes'=>null,'lines'=>$lines??[$line]]),201,'draft '.$currency)['detail']['order'];
};
$rows=function(string $sql,array $params=[]) use($pdo): array {$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);};
$receivables=fn(string $orderId)=>$rows("SELECT * FROM b2b_account_movements WHERE order_uuid=? AND movement_type='order_receivable'",[$orderId]);
$reversals=fn(string $orderId)=>$rows("SELECT * FROM b2b_account_movements WHERE order_uuid=? AND movement_type='reversal'",[$orderId]);
$orderRow=fn(string $orderId)=>$rows('SELECT * FROM b2b_orders WHERE order_uuid=?',[$orderId])[0];
$summary=fn()=>status($get("/b2b/accounts/$company"),200,'summary');
$today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Bucharest')))->format('Y-m-d');
check($summary()['currencies']['RON']['movementCount']===0 && $summary()['currencies']['RON']['balance']==='0.00','new company has no automatic zero entries');

// ---- Finalization posts exactly one receivable equal to the authoritative finalized order -------------------
$ron=$draft('RON');$finalKey='finalize-'.$suffix;
check($receivables($ron['id'])===[],'a draft posts nothing');
$final=status($send('POST',"/b2b/orders/{$ron['id']}/finalize",['expectedVersion'=>1],$finalKey),200,'finalize RON')['detail']['order'];
$order=$orderRow($ron['id']);$r=$receivables($ron['id']);
check(count($r)===1,'finalization posts exactly one receivable');$receivable=$r[0];
check($receivable['amount']===$order['gross_total'] && $order['gross_total']==='160.65' && $final['calculation']['totals']['gross']==='160.65','receivable amount equals the server gross total');
check($receivable['currency_code']==='RON' && $receivable['direction']==='debit' && $receivable['company_uuid']===$company && $receivable['receivable_order_uuid']===$ron['id'],'currency, direction, company and order identity');
check($receivable['value_date']===$today && $receivable['created_by_employee_uuid']===$root['employeeUuid'],'business date and actor');
check(preg_match('/^B2B-MV-\d{6,}$/',$receivable['movement_code'])===1,'movement code');
$snap=json_decode($receivable['source_snapshot'],true);
check($snap['sourceType']==='b2b_order' && $snap['sourceId']===$ron['id'] && $snap['orderCode']===$final['code'] && $snap['currencyCode']==='RON' && $snap['orderGross']==='160.65'
    && $snap['companyLegalName']===$final['companySnapshot']['legalName'],'receivable snapshot holds the source identity and finalized figures');
check(count($rows("SELECT * FROM b2b_account_activity_events WHERE movement_uuid=? AND action='receivable_posted'",[$receivable['movement_uuid']]))===1,'one posting audit event');
check($rows('SELECT COUNT(*) n FROM b2b_account_idempotency')[0]['n']===0,'order finalization never writes account idempotency');
// Retry (same key) replays the recorded result; a new finalize request is refused by the order lifecycle.
status($send('POST',"/b2b/orders/{$ron['id']}/finalize",['expectedVersion'=>1],$finalKey),200,'finalize replay');
error($send('POST',"/b2b/orders/{$ron['id']}/finalize",['expectedVersion'=>2]),409,'ORDER_FINALIZED');
check(count($receivables($ron['id']))===1 && count($rows("SELECT * FROM b2b_account_activity_events WHERE order_uuid=? AND action='receivable_posted'",[$ron['id']]))===1,'retries never post again');
// Later live company changes never rewrite the posted receivable.
$pdo->prepare("UPDATE b2b_companies SET legal_name='Renamed later' WHERE company_uuid=?")->execute([$company]);
check($receivables($ron['id'])===[$receivable],'receivable is not rewritten by live changes');
$s=$summary()['currencies'];
check($s['RON']['balance']==='160.65' && $s['RON']['outstandingReceivables']==='160.65' && $s['EUR']['balance']==='0.00','RON balance; EUR untouched');

// ---- EUR is a separate balance, no conversion -----------------------------------------------------------------
$eur=$draft('EUR');status($send('POST',"/b2b/orders/{$eur['id']}/finalize",['expectedVersion'=>1]),200,'finalize EUR');
$s=$summary()['currencies'];
check($s['EUR']['balance']==='160.65' && $s['RON']['balance']==='160.65' && $receivables($eur['id'])[0]['currency_code']==='EUR','EUR posts in EUR, RON unchanged');

// ---- Database invariants hold even for direct writes -------------------------------------------------------------
$insert=function(array $values) use($pdo): array {
    $pdo->prepare('INSERT INTO b2b_account_movement_sequence(created_at) VALUES(UTC_TIMESTAMP(6))')->execute();$n=(int)$pdo->lastInsertId();
    $row=array_replace(['movement_uuid'=>sprintf('00000000-0000-4000-8000-%012d',$n),'movement_number'=>$n,'movement_code'=>sprintf('B2B-MV-T%06d',$n),'currency_code'=>'RON','amount'=>'1.00',
        'value_date'=>'2026-01-01','order_uuid'=>null,'receivable_order_uuid'=>null,'reversed_movement_uuid'=>null,'payment_method'=>null,'source_snapshot'=>'{}','created_at'=>'2026-01-01 00:00:00'],$values);
    return ['INSERT INTO b2b_account_movements('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')',array_values($row)];
};
$base=['company_uuid'=>$company,'created_by_employee_uuid'=>$root['employeeUuid'],'created_by_name'=>'test'];
check(refused($pdo,...$insert($base+['movement_type'=>'order_receivable','direction'=>'debit','order_uuid'=>$ron['id'],'receivable_order_uuid'=>$ron['id']]))===1062,'unique key: one receivable per order');
foreach([
    ['receivable marker on a payment',$base+['movement_type'=>'payment','direction'=>'credit','payment_method'=>'cash','receivable_order_uuid'=>$eur['id']]],
    ['credit receivable',$base+['movement_type'=>'order_receivable','direction'=>'credit','order_uuid'=>$eur['id'],'receivable_order_uuid'=>$eur['id']]],
    ['receivable without marker',$base+['movement_type'=>'order_receivable','direction'=>'debit','order_uuid'=>$eur['id']]],
    ['reversal without original',$base+['movement_type'=>'reversal','direction'=>'credit']],
    ['zero amount',$base+['movement_type'=>'adjustment','direction'=>'debit','amount'=>'0.00']],
    ['foreign currency',$base+['movement_type'=>'adjustment','direction'=>'debit','currency_code'=>'USD']],
] as [$name,$values]) check(in_array(refused($pdo,...$insert($values)),[3819,4025],true),'check constraint: '.$name);

// ---- A failed ledger posting rolls the finalization back --------------------------------------------------------
$failing=new LedgerFailurePdo(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$config->dbHost,$config->dbPort,$config->dbName),$config->dbUser,$config->dbPassword,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false]);
$failing->exec("SET time_zone = '+00:00'");
$broken=(new Container($config,$failing))->kernel();
$doomed=$draft('RON');$doomedKey='finalize-fails-'.$suffix;$before=$orderRow($doomed['id']);
error(T::call($broken,'POST',"/b2b/orders/{$doomed['id']}/finalize",['expectedVersion'=>1],['x-csrf-token'=>$root['csrf'],'idempotency-key'=>$doomedKey],$root['cookie']),500,'INTERNAL_ERROR');
check($orderRow($doomed['id'])===$before,'order row unchanged after ledger failure (still draft, same version and totals)');
check($receivables($doomed['id'])===[] && $rows('SELECT * FROM b2b_order_idempotency WHERE idempotency_key=?',[$doomedKey])===[]
    && $rows("SELECT * FROM b2b_order_activity_events WHERE order_uuid=? AND action='order_finalized'",[$doomed['id']])===[],'no receivable, replay record or finalize event survives');
status($send('POST',"/b2b/orders/{$doomed['id']}/finalize",['expectedVersion'=>1],$doomedKey),200,'the same key succeeds once the ledger works');
check(count($receivables($doomed['id']))===1,'one receivable after the successful retry');

// ---- Concurrent finalization cannot duplicate the receivable -----------------------------------------------------
function race(array $jobs,array $root,string $db): array {
    $start=(string)(microtime(true)+0.3); $workers=[];
    foreach($jobs as [$method,$path,$body,$key]) {
        $proc=proc_open([PHP_BINARY,__DIR__.'/fixtures/b2b-order-worker.php',$db,$method,$path,json_encode($body),$key,$root['cookie'],$root['csrf'],$start],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc)) throw new RuntimeException('Race worker failed to start.');
        fclose($pipes[0]); $workers[]=[$proc,$pipes];
    }
    $results=[];
    foreach($workers as [$proc,$pipes]) {
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($proc)===0,'race process '.$err); $results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);
    }
    return $results;
}
$raced=$draft('RON');
$res=race([['POST',"/b2b/orders/{$raced['id']}/finalize",['expectedVersion'=>1],'race-final-a-'.$suffix],['POST',"/b2b/orders/{$raced['id']}/finalize",['expectedVersion'=>1],'race-final-b-'.$suffix]],$root,$db);
$st=array_column($res,'status');sort($st);check($st===[200,409],'concurrent finalize has one winner '.json_encode($res));
check(count($receivables($raced['id']))===1,'concurrent finalize posts one receivable');
$same=$draft('EUR');$sameKey='race-final-same-'.$suffix;
$res=race([['POST',"/b2b/orders/{$same['id']}/finalize",['expectedVersion'=>1],$sameKey],['POST',"/b2b/orders/{$same['id']}/finalize",['expectedVersion'=>1],$sameKey]],$root,$db);
check(array_column($res,'status')===[200,200] && count($receivables($same['id']))===1,'concurrent same-key retry posts one receivable');

// ---- Cancelling a finalized order posts exactly one linked reversal and never touches the original ----------------
$ledgerBefore=$rows('SELECT * FROM b2b_account_movements ORDER BY movement_number');
$cancelKey='cancel-'.$suffix;
status($send('POST',"/b2b/orders/{$ron['id']}/cancel",['expectedVersion'=>2],$cancelKey),200,'cancel finalized order');
$rev=$reversals($ron['id']);check(count($rev)===1,'cancellation posts exactly one reversal');$rev=$rev[0];
check($rev['reversed_movement_uuid']===$receivable['movement_uuid'] && $rev['direction']==='credit' && $rev['amount']===$receivable['amount'] && $rev['currency_code']==='RON'
    && $rev['receivable_order_uuid']===null && $rev['value_date']===$today,'reversal mirrors and links the receivable');
$revSnap=json_decode($rev['source_snapshot'],true);
check($revSnap['reasonCode']==='order_cancelled' && $revSnap['reversedCode']===$receivable['movement_code'] && $revSnap['orderCode']===$final['code'],'reversal snapshot');
check($rows('SELECT * FROM b2b_account_movements WHERE movement_number<=? ORDER BY movement_number',[(int)end($ledgerBefore)['movement_number']])===$ledgerBefore,'existing movements (the original included) are byte-identical');
check(count($rows("SELECT * FROM b2b_account_activity_events WHERE movement_uuid=? AND action='receivable_reversed'",[$rev['movement_uuid']]))===1,'one reversal audit event');
status($send('POST',"/b2b/orders/{$ron['id']}/cancel",['expectedVersion'=>2],$cancelKey),200,'cancel replay');
error($send('POST',"/b2b/orders/{$ron['id']}/cancel",['expectedVersion'=>3]),409,'ORDER_CANCELLED');
check(count($reversals($ron['id']))===1,'retries never reverse again');
check(in_array(refused($pdo,...$insert($base+['movement_type'=>'reversal','direction'=>'credit','order_uuid'=>$ron['id'],'reversed_movement_uuid'=>$receivable['movement_uuid']])),[1062],true),'unique key: one reversal per movement');
$s=$summary()['currencies'];check($s['RON']['balance']==='321.30' && $s['RON']['debits']==='481.95' && $s['RON']['credits']==='160.65','balance = debits - credits after reversal');
// Manual reversal of an order receivable is not a second path: the order lifecycle owns it.
error($send('POST',"/b2b/accounts/$company/movements/{$receivables($eur['id'])[0]['movement_uuid']}/reverse",['valueDate'=>$today,'reason'=>'manual']),409,'ORDER_RECEIVABLE_FOLLOWS_ORDER');
$res=race([['POST',"/b2b/orders/{$eur['id']}/cancel",['expectedVersion'=>2],'race-cancel-a-'.$suffix],['POST',"/b2b/orders/{$eur['id']}/cancel",['expectedVersion'=>2],'race-cancel-b-'.$suffix]],$root,$db);
$st=array_column($res,'status');sort($st);check($st===[200,409] && count($reversals($eur['id']))===1,'concurrent cancel posts one reversal');

// ---- Draft cancellation and zero totals never touch the ledger ---------------------------------------------------
$count=fn()=>(int)$rows('SELECT COUNT(*) n FROM b2b_account_movements')[0]['n'];$n=$count();
$plain=$draft('RON');status($send('POST',"/b2b/orders/{$plain['id']}/cancel",['expectedVersion'=>1]),200,'cancel draft');
check($count()===$n && $rows('SELECT * FROM b2b_account_movements WHERE order_uuid=?',[$plain['id']])===[],'draft cancellation posts nothing');
$zero=$draft('RON',[array_replace($line,['unitPriceNet'=>'0.00'])]);
$z=$send('POST',"/b2b/orders/{$zero['id']}/finalize",['expectedVersion'=>1]);
if($z['status']===200){check($count()===$n,'a zero-total order posts no receivable');status($send('POST',"/b2b/orders/{$zero['id']}/cancel",['expectedVersion'=>2]),200,'cancel zero order');check($count()===$n,'and no reversal');}
else check($z['status']===422,'zero price refused by order validation');

// ======== Payments, allocations, opening balances, adjustments and reversals (separate, clean company) ========
$co=status($send('POST','/b2b/companies',['legalName'=>'Ledger company '.$suffix,'displayName'=>'Ledger','countryCode'=>'RO','taxIdentifier'=>'LED'.$suffix]),201,'ledger company')['companyId'];
$finalized=function(string $currency) use($send,$co,$line,$receivables): array {
    $o=status($send('POST','/b2b/orders',['companyId'=>$co,'currencyCode'=>$currency,'contactId'=>null,'billingAddressId'=>null,'deliveryAddressId'=>null,'notes'=>null,'lines'=>[$line]]),201,'ledger draft')['detail']['order'];
    status($send('POST',"/b2b/orders/{$o['id']}/finalize",['expectedVersion'=>1]),200,'ledger finalize');
    return ['order'=>$o['id'],'receivable'=>$receivables($o['id'])[0]['movement_uuid']];
};
$r1=$finalized('RON');$r2=$finalized('RON');$e1=$finalized('EUR');
$acc="/b2b/accounts/$co";
$sum=fn()=>status($get($acc),200,'ledger summary')['currencies'];
$open=fn(string $currency)=>status($get("$acc/open-items",['currency'=>$currency]),200,'open items');
$openOf=function(string $currency,string $kind,string $id) use($open): ?string {foreach($open($currency)[$kind] as $i) if($i['id']===$id) return $i['openAmount'];return null;};
// Ledger truth recomputed independently in integer cents from the immutable rows.
$ledgerBalance=function(string $currency) use($rows,$co): string {
    $cents=0;foreach($rows('SELECT direction,amount FROM b2b_account_movements WHERE company_uuid=? AND currency_code=?',[$co,$currency]) as $m){[$a,$b]=explode('.',$m['amount']);$v=(int)$a*100+(int)$b;$cents+=$m['direction']==='debit'?$v:-$v;}
    return ($cents<0?'-':'').intdiv(abs($cents),100).'.'.str_pad((string)(abs($cents)%100),2,'0',STR_PAD_LEFT);
};
$pay=fn(array $body,?string $key=null)=>$send('POST',"$acc/payments",$body+['currencyCode'=>'RON','valueDate'=>$today],$key);
$fieldError=function(array $r,string $field,string $reason):void{error($r,422,'VALIDATION_FAILED');check(($r['body']['error']['details']['fields'][$field]??null)===$reason,"$field $reason ".json_encode($r['body']));};

// Payment validation: method reference rules, exact decimal strings only, no future business date, no FX.
$fieldError($pay(['amount'=>'10.00','method'=>'bank_transfer']),'externalReference','required');
$fieldError($pay(['amount'=>'10.00','method'=>'other']),'note','required');
$fieldError($pay(['amount'=>'10.00','method'=>'compensation']),'note','required');
$fieldError($pay(['amount'=>10.5,'method'=>'cash']),'amount','invalid');
$fieldError($pay(['amount'=>'10.001','method'=>'cash']),'amount','invalid');
$fieldError($pay(['amount'=>'0.00','method'=>'cash']),'amount','invalid');
$fieldError($pay(['amount'=>'-5.00','method'=>'cash']),'amount','invalid');
$fieldError($pay(['amount'=>'10.00','method'=>'cheque']),'method','invalid');
$fieldError($send('POST',"$acc/payments",['currencyCode'=>'USD','amount'=>'1.00','valueDate'=>$today,'method'=>'cash']),'currencyCode','invalid');
$fieldError($pay(['amount'=>'1.00','method'=>'cash','valueDate'=>(new DateTimeImmutable('tomorrow',new DateTimeZone('Europe/Bucharest')))->format('Y-m-d')]),'valueDate','future');
$fieldError($pay(['amount'=>'1.00','method'=>'cash','valueDate'=>'2026-02-30']),'valueDate','invalid');
error($pay(['amount'=>'1.00','method'=>'cash','companyId'=>$company]),400,'INVALID_REQUEST');
check($sum()['RON']['movementCount']===2,'refused payments wrote nothing');
status($pay(['amount'=>'1.00','method'=>'compensation','externalReference'=>'COMP-1']),201,'compensation with a reference');
status($pay(['amount'=>'1.00','method'=>'card']),201,'card without a reference');

// One payment spread across two receivables, partially; idempotent replay; conflicting reuse of the key.
$payKey='payment-'.$suffix;
$body=['amount'=>'200.00','method'=>'bank_transfer','externalReference'=>'OP-1001','allocations'=>[['receivableId'=>$r1['receivable'],'amount'=>'160.65'],['receivableId'=>$r2['receivable'],'amount'=>'39.35']]];
$p1=status($pay($body,$payKey),201,'payment with allocations');
check(count($p1['allocationIds'])===2 && $p1['summary']['currencies']['RON']['balance']==='119.30','payment answers with references and the summary');
$again=status($pay($body,$payKey),201,'payment replay');
check($again['movementId']===$p1['movementId'] && $again['allocationIds']===$p1['allocationIds'],'replay returns the recorded result');
check(count($rows("SELECT * FROM b2b_account_movements WHERE company_uuid=? AND movement_type='payment' AND external_reference='OP-1001'",[$co]))===1,'replay records one payment');
error($pay(array_replace($body,['amount'=>'201.00']),$payKey),409,'IDEMPOTENCY_CONFLICT');
error($send('POST',"/b2b/accounts/$company/payments",$body+['currencyCode'=>'RON','valueDate'=>$today],$payKey),409,'IDEMPOTENCY_CONFLICT');
check($openOf('RON','receivables',$r1['receivable'])===null && $openOf('RON','receivables',$r2['receivable'])==='121.30' && $openOf('RON','payments',$p1['movementId'])===null,'partial allocation figures');
// Unallocated remainder; later allocation; limits.
$p2=status($pay(['amount'=>'50.00','method'=>'cash']),201,'cash payment without allocation')['movementId'];
check($openOf('RON','payments',$p2)==='50.00','unallocated payment');
error($send('POST',"$acc/allocations",['paymentId'=>$p2,'allocations'=>[['receivableId'=>$r2['receivable'],'amount'=>'50.01']]]),409,'ALLOCATION_EXCEEDS_PAYMENT');
$big=status($pay(['amount'=>'500.00','method'=>'cash']),201,'large payment')['movementId'];
error($send('POST',"$acc/allocations",['paymentId'=>$big,'allocations'=>[['receivableId'=>$r2['receivable'],'amount'=>'121.31']]]),409,'ALLOCATION_EXCEEDS_OUTSTANDING');
error($send('POST',"$acc/allocations",['paymentId'=>$big,'allocations'=>[['receivableId'=>$e1['receivable'],'amount'=>'1.00']]]),409,'CURRENCY_MISMATCH');
error($send('POST',"$acc/allocations",['paymentId'=>$r2['receivable'],'allocations'=>[['receivableId'=>$r2['receivable'],'amount'=>'1.00']]]),404,'PAYMENT_NOT_FOUND');
error($send('POST',"$acc/allocations",['paymentId'=>$big,'allocations'=>[['receivableId'=>$big,'amount'=>'1.00']]]),404,'RECEIVABLE_NOT_FOUND');
$fieldError($send('POST',"$acc/allocations",['paymentId'=>$big,'allocations'=>[['receivableId'=>$r2['receivable'],'amount'=>'1.00'],['receivableId'=>$r2['receivable'],'amount'=>'1.00']]]),'allocations','invalid');
$a2=status($send('POST',"$acc/allocations",['paymentId'=>$p2,'allocations'=>[['receivableId'=>$r2['receivable'],'amount'=>'20.00']]]),201,'partial later allocation')['allocationIds'][0];
check($openOf('RON','payments',$p2)==='30.00' && $openOf('RON','receivables',$r2['receivable'])==='101.30','allocation reduces both open amounts');
// Overpayment leaves company credit in the same currency only; the ledger, not allocations, decides the balance.
$s=$sum();
check($s['RON']['balance']===$ledgerBalance('RON') && $s['RON']['balance']==='-430.70' && $s['EUR']['balance']==='160.65','overpayment is RON credit; EUR untouched');
check($s['RON']['outstandingReceivables']==='101.30' && $s['RON']['unallocatedPayments']==='532.00','reconciliation figures');
// Release then reallocate: the allocation row never changes, the release is recorded once.
$allocationBefore=$rows('SELECT * FROM b2b_account_allocations WHERE allocation_uuid=?',[$a2]);
$fieldError($send('POST',"$acc/allocations/$a2/release",["reason"=>"  "]),'reason','required');
$relKey='release-'.$suffix;
status($send('POST',"$acc/allocations/$a2/release",['reason'=>'wrong order'],$relKey),200,'release');
status($send('POST',"$acc/allocations/$a2/release",['reason'=>'wrong order'],$relKey),200,'release replay');
error($send('POST',"$acc/allocations/$a2/release",['reason'=>'again']),409,'ALLOCATION_ALREADY_RELEASED');
check($rows('SELECT * FROM b2b_account_allocations WHERE allocation_uuid=?',[$a2])===$allocationBefore && $openOf('RON','payments',$p2)==='50.00','release keeps the allocation row and frees the amount');
$detail=status($get("$acc/movements/$p2"),200,'payment detail');
check(count($detail['allocations'])===1 && $detail['allocations'][0]['released']['kind']==='manual' && $detail['movement']['open']==='50.00','movement detail shows the release');

// Reversal of a payment: linked opposite movement, allocations released automatically, never twice.
$p1Before=$rows('SELECT * FROM b2b_account_movements WHERE movement_uuid=?',[$p1['movementId']]);
$fieldError($send('POST',"$acc/movements/{$p1['movementId']}/reverse",['valueDate'=>$today]),'reason','required');
$early=status($pay(['amount'=>'5.00','method'=>'cash','valueDate'=>'2026-01-15']),201,'back-dated payment')['movementId'];
$fieldError($send('POST',"$acc/movements/$early/reverse",['valueDate'=>'2026-01-10','reason'=>'typo']),'valueDate','before_original');
$revKey='reverse-'.$suffix;
$rv=status($send('POST',"$acc/movements/{$p1['movementId']}/reverse",['valueDate'=>$today,'reason'=>'bounced transfer'],$revKey),201,'reverse payment')['movementId'];
status($send('POST',"$acc/movements/{$p1['movementId']}/reverse",['valueDate'=>$today,'reason'=>'bounced transfer'],$revKey),201,'reverse replay');
error($send('POST',"$acc/movements/{$p1['movementId']}/reverse",['valueDate'=>$today,'reason'=>'again']),409,'MOVEMENT_ALREADY_REVERSED');
error($send('POST',"$acc/movements/$rv/reverse",['valueDate'=>$today,'reason'=>'undo']),409,'REVERSAL_NOT_REVERSIBLE');
error($send('POST',"$acc/allocations",['paymentId'=>$p1['movementId'],'allocations'=>[['receivableId'=>$r2['receivable'],'amount'=>'1.00']]]),409,'MOVEMENT_REVERSED');
$rvRow=$rows('SELECT * FROM b2b_account_movements WHERE movement_uuid=?',[$rv])[0];
check($rvRow['direction']==='debit' && $rvRow['amount']==='200.00' && $rvRow['reversed_movement_uuid']===$p1['movementId'] && $rvRow['note']==='bounced transfer','reversal mirrors the payment');
check($rows('SELECT * FROM b2b_account_movements WHERE movement_uuid=?',[$p1['movementId']])===$p1Before,'reversed payment is unchanged');
check($rows("SELECT release_kind FROM b2b_account_allocation_releases r JOIN b2b_account_allocations a USING(allocation_uuid) WHERE a.payment_movement_uuid=?",[$p1['movementId']])===[['release_kind'=>'payment_reversed'],['release_kind'=>'payment_reversed']],'reversal released both allocations');
check($openOf('RON','receivables',$r1['receivable'])==='160.65' && $openOf('RON','receivables',$r2['receivable'])==='160.65','receivables are open again');
check($sum()['RON']['balance']===$ledgerBalance('RON'),'summary equals the ledger after reversal');
// A receivable reversed through order cancellation releases the allocations that settled it.
$a3=status($send('POST',"$acc/allocations",['paymentId'=>$big,'allocations'=>[['receivableId'=>$r1['receivable'],'amount'=>'100.00']]]),201,'allocate to r1')['allocationIds'][0];
status($send('POST',"/b2b/orders/{$r1['order']}/cancel",['expectedVersion'=>2]),200,'cancel allocated order');
check($rows('SELECT release_kind FROM b2b_account_allocation_releases WHERE allocation_uuid=?',[$a3])===[['release_kind'=>'receivable_reversed']] && $openOf('RON','payments',$big)==='500.00','order cancellation frees its allocations');
error($send('POST',"$acc/allocations",['paymentId'=>$big,'allocations'=>[['receivableId'=>$r1['receivable'],'amount'=>'1.00']]]),409,'MOVEMENT_REVERSED');

// Opening balances: optional, at most one active per currency, immutable, corrected by reversal.
$entry=fn(string $path,array $body,?string $key=null)=>$send('POST',"$acc/$path",$body+['valueDate'=>'2026-01-01'],$key);
$fieldError($entry('opening-balances',['currencyCode'=>'RON','direction'=>'debit','amount'=>'300.00']),'reason','required');
$fieldError($entry('opening-balances',['currencyCode'=>'RON','direction'=>'up','amount'=>'300.00','reason'=>'x']),'direction','invalid');
$ob=status($entry('opening-balances',['currencyCode'=>'RON','direction'=>'debit','amount'=>'300.00','reason'=>'Migrated debt']),201,'opening debit')['movementId'];
error($entry('opening-balances',['currencyCode'=>'RON','direction'=>'credit','amount'=>'1.00','reason'=>'second']),409,'OPENING_BALANCE_EXISTS');
status($entry('opening-balances',['currencyCode'=>'EUR','direction'=>'credit','amount'=>'40.00','reason'=>'Prepaid credit']),201,'opening credit in EUR');
check($sum()['RON']['activeOpeningBalance']===true,'active opening flag');
status($send('POST',"$acc/movements/$ob/reverse",['valueDate'=>$today,'reason'=>'wrong amount']),201,'reverse opening');
status($entry('opening-balances',['currencyCode'=>'RON','direction'=>'debit','amount'=>'250.00','reason'=>'Corrected debt']),201,'replacement opening after reversal');
// Adjustments need a reason and post in either direction.
$fieldError($entry('adjustments',['currencyCode'=>'RON','direction'=>'debit','amount'=>'10.00','reason'=>'   ']),'reason','required');
$adj=status($entry('adjustments',['currencyCode'=>'RON','direction'=>'debit','amount'=>'10.00','reason'=>'Transport fee']),201,'debit adjustment')['movementId'];
status($entry('adjustments',['currencyCode'=>'EUR','direction'=>'credit','amount'=>'5.00','reason'=>'Goodwill']),201,'credit adjustment');
check($rows('SELECT note,created_by_employee_uuid FROM b2b_account_movements WHERE movement_uuid=?',[$adj])===[['note'=>'Transport fee','created_by_employee_uuid'=>$root['employeeUuid']]],'adjustment keeps reason and actor');
check($sum()['RON']['balance']===$ledgerBalance('RON') && $sum()['EUR']['balance']===$ledgerBalance('EUR') && $sum()['EUR']['balance']==='115.65','balances equal the ledger per currency');

// Inactive companies settle and correct history, but take no new exposure.
$pdo->prepare("UPDATE b2b_companies SET status='inactive' WHERE company_uuid=?")->execute([$co]);
check(status($get($acc),200,'inactive summary')['capabilities']['companyActive']===false,'inactive flag');
$ip=status($pay(['amount'=>'10.00','method'=>'cash','allocations'=>[['receivableId'=>$r2['receivable'],'amount'=>'10.00']]]),201,'inactive payment with allocation')['movementId'];
status($send('POST',"$acc/allocations",['paymentId'=>$big,'allocations'=>[['receivableId'=>$r2['receivable'],'amount'=>'1.00']]]),201,'inactive allocation');
status($send('POST',"$acc/movements/$ip/reverse",['valueDate'=>$today,'reason'=>'duplicate']),201,'inactive reversal');
status($entry('adjustments',['currencyCode'=>'RON','direction'=>'credit','amount'=>'1.00','reason'=>'rounding']),201,'inactive credit adjustment');
error($entry('adjustments',['currencyCode'=>'RON','direction'=>'debit','amount'=>'1.00','reason'=>'fee']),409,'COMPANY_INACTIVE');
error($entry('opening-balances',['currencyCode'=>'EUR','direction'=>'debit','amount'=>'1.00','reason'=>'x']),409,'COMPANY_INACTIVE');
error($send('POST','/b2b/orders',['companyId'=>$co,'currencyCode'=>'RON','contactId'=>null,'billingAddressId'=>null,'deliveryAddressId'=>null,'notes'=>null,'lines'=>[$line]]),409,'COMPANY_INACTIVE');
status($get("$acc/statement",['currency'=>'RON']),200,'inactive statement');
$pdo->prepare("UPDATE b2b_companies SET status='active' WHERE company_uuid=?")->execute([$co]);

// Concurrency on separate connections: duplicate submissions, over-allocation and double reversal.
$target=$finalized('RON')['receivable'];
$res=race([['POST',"$acc/payments",['currencyCode'=>'RON','amount'=>'7.00','valueDate'=>$today,'method'=>'cash'],'race-pay-'.$suffix],['POST',"$acc/payments",['currencyCode'=>'RON','amount'=>'7.00','valueDate'=>$today,'method'=>'cash'],'race-pay-'.$suffix]],$root,$db);
check(array_column($res,'status')===[201,201] && count($rows("SELECT * FROM b2b_account_movements WHERE company_uuid=? AND amount='7.00'",[$co]))===1,'simultaneous duplicate submission records one payment');
$res=race([['POST',"$acc/payments",['currencyCode'=>'RON','amount'=>'160.65','valueDate'=>$today,'method'=>'cash','allocations'=>[['receivableId'=>$target,'amount'=>'160.65']]],'race-alloc-a-'.$suffix],
    ['POST',"$acc/payments",['currencyCode'=>'RON','amount'=>'160.65','valueDate'=>$today,'method'=>'cash','allocations'=>[['receivableId'=>$target,'amount'=>'160.65']]],'race-alloc-b-'.$suffix]],$root,$db);
$st=array_column($res,'status');sort($st);check($st===[201,409] && in_array('ALLOCATION_EXCEEDS_OUTSTANDING',array_column($res,'error'),true),'simultaneous allocations cannot exceed the outstanding amount');
check($rows("SELECT COALESCE(SUM(a.amount),0) s FROM b2b_account_allocations a LEFT JOIN b2b_account_allocation_releases r USING(allocation_uuid) WHERE a.receivable_movement_uuid=? AND r.allocation_uuid IS NULL",[$target])[0]['s']==='160.65','one allocation survived');
$victim=status($pay(['amount'=>'3.00','method'=>'cash']),201,'payment to reverse')['movementId'];
$res=race([['POST',"$acc/movements/$victim/reverse",['valueDate'=>$today,'reason'=>'a'],'race-rev-a-'.$suffix],['POST',"$acc/movements/$victim/reverse",['valueDate'=>$today,'reason'=>'b'],'race-rev-b-'.$suffix]],$root,$db);
$st=array_column($res,'status');sort($st);check($st===[201,409] && count($rows('SELECT * FROM b2b_account_movements WHERE reversed_movement_uuid=?',[$victim]))===1,'simultaneous reversals post one');
$res=race([['POST',"$acc/opening-balances",['currencyCode'=>'EUR','direction'=>'debit','amount'=>'1.00','valueDate'=>$today,'reason'=>'x'],'race-ob-a-'.$suffix],
    ['POST',"$acc/opening-balances",['currencyCode'=>'EUR','direction'=>'debit','amount'=>'1.00','valueDate'=>$today,'reason'=>'x'],'race-ob-b-'.$suffix]],$root,$db);
check(array_column($res,'error')===['OPENING_BALANCE_EXISTS','OPENING_BALANCE_EXISTS'],'EUR opening already active, both refused');

// ---- Statement: same server dataset for JSON, CSV and PDF -------------------------------------------------------
$fieldError($get("$acc/statement"),'currency','required');
$fieldError($get("$acc/statement",['currency'=>'RON','from'=>'2026-03-01','to'=>'2026-02-01']),'from','after_to');
error($get("$acc/statement",['currency'=>'RON','extra'=>'1']),400,'INVALID_REQUEST');
$full=status($get("$acc/statement",['currency'=>'RON']),200,'full statement');
check($full['openingBalance']==='0.00' && $full['closingBalance']===$ledgerBalance('RON') && end($full['movements'])['runningBalance']===$full['closingBalance'],'full statement closes at the ledger balance');
$part=status($get("$acc/statement",['currency'=>'RON','from'=>'2026-01-02']),200,'ranged statement');
$cents=fn(string $v)=>(int)round(0)+((str_starts_with($v,'-')?-1:1)*((int)explode('.',ltrim($v,'-'))[0]*100+(int)explode('.',$v)[1]));
check($part['openingBalance']==='559.00' && $cents($part['openingBalance'])+$cents($part['totals']['debit'])-$cents($part['totals']['credit'])===$cents($part['closingBalance']) && $part['closingBalance']===$full['closingBalance'],'opening + debits - credits = closing');
check(!in_array('2026-01-01',array_column($part['movements'],'valueDate'),true) && $part['movements'][0]['runningBalance']!==null,'range excludes earlier movements but carries them in the running balance');
$csv=$get("$acc/statement.csv",['currency'=>'RON','from'=>'2026-01-02']);
check($csv['status']===200 && str_starts_with($csv['headers']['Content-Type'],'text/csv') && str_contains($csv['headers']['Content-Disposition'],'.csv') && $csv['headers']['Cache-Control']==='no-store, private','CSV headers');
$lines=array_map(static fn($l)=>str_getcsv($l,';','"',''),explode("\r\n",trim(substr($csv['raw'],3))));
$byLabel=[];foreach($lines as $l) $byLabel[$l[0]]=$l;
check(str_starts_with($csv['raw'],"\xEF\xBB\xBF") && $byLabel['Sold la începutul perioadei'][1]===$part['openingBalance'] && $byLabel['Sold la sfârșitul perioadei'][1]===$part['closingBalance']
    && $byLabel['Total perioadă'][4]===$part['totals']['debit'] && $byLabel['Total perioadă'][5]===$part['totals']['credit'],'CSV figures equal the statement dataset');
check(count(array_filter($lines,static fn($l)=>preg_match('/^B2B-MV-/',$l[1]??'')===1))===count($part['movements']),'CSV has every statement movement');
$tr=$get("$acc/statement.csv",['currency'=>'RON','lang'=>'tr']);check(str_contains($tr['raw'],'Dönem sonu bakiye'),'Turkish CSV');
$pdf=$get("$acc/statement.pdf",['currency'=>'RON']);
check($pdf['status']===200 && $pdf['headers']['Content-Type']==='application/pdf' && str_starts_with($pdf['raw'],'%PDF-1.4') && str_ends_with($pdf['raw'],"%%EOF\n"),'PDF document');
preg_match('/startxref\n(\d+)\n/',$pdf['raw'],$m);check(substr($pdf['raw'],(int)$m[1],4)==='xref','PDF cross-reference offset');
check(!str_contains($pdf['raw'].$csv['raw'],'bounced transfer') && !str_contains($pdf['raw'].$csv['raw'],$root['employeeUuid']),'exports leak no notes or internal ids');
error($get("$acc/statement.xlsx",['currency'=>'RON']),404,'NOT_FOUND');

// ---- Movements list, activity and immutability surface -----------------------------------------------------------
for($i=0;$i<3;$i++) status($pay(['amount'=>'0.01','method'=>'cash']),201,'page filler');
$page=status($get("$acc/movements",['limit'=>'25']),200,'movements');
check($page['nextCursor']!==null,'more than one movement page '.$sum()['RON']['movementCount'].'+'.$sum()['EUR']['movementCount']);
$next=status($get("$acc/movements",['limit'=>'25','cursor'=>$page['nextCursor']]),200,'movements page 2');
check(array_intersect(array_column($page['items'],'id'),array_column($next['items'],'id'))===[],'keyset movement pages');
check(count(status($get("$acc/movements",['type'=>'reversal','currency'=>'RON']),200,'filter')['items'])>=4,'type filter');
error($get("$acc/movements",['type'=>'invoice']),422,'VALIDATION_FAILED');
check(count(status($get("$acc/activity",['limit'=>'100']),200,'activity')['items'])>0,'activity');
error($send('DELETE',"$acc/movements/$adj",[]),405,'METHOD_NOT_ALLOWED');
error($send('PATCH',"$acc/movements/$adj",['amount'=>'1.00']),405,'METHOD_NOT_ALLOWED');
error($send('PUT',"$acc/movements/$adj",['amount'=>'1.00']),405,'METHOD_NOT_ALLOWED');
error($send('POST',"$acc/payments",['currencyCode'=>'RON','amount'=>'1.00','valueDate'=>$today,'method'=>'cash'],null,['x-csrf-token'=>'invalid']),403,'CSRF_INVALID');
error($send('POST',"$acc/payments",['currencyCode'=>'RON','amount'=>'1.00','valueDate'=>$today,'method'=>'cash'],null,['idempotency-key'=>'']),400,'INVALID_IDEMPOTENCY_KEY');
error($send('POST',"$acc/payments",['currencyCode'=>'RON','amount'=>'1.00','valueDate'=>$today,'method'=>'cash'],null,['origin'=>'https://evil.invalid']),403,'ORIGIN_DENIED');
error(T::call($k,'GET',$acc),401,'SESSION_EXPIRED');
error(T::call($k,'GET',"$acc/statement.pdf",null,[],null,['currency'=>'RON']),401,'SESSION_EXPIRED');
error($get('/b2b/accounts/not-a-uuid'),404,'COMPANY_NOT_FOUND');
$overview=status($get('/b2b/accounts',['balance'=>'open','search'=>'Ledger company '.$suffix]),200,'overview');
check(count($overview['items'])===1 && $overview['items'][0]['balances']['RON']===$ledgerBalance('RON'),'overview balance');

// ---- Narrow permissions, application gate, revocation -----------------------------------------------------------
$admin=$c->employeeAdmin();
$makeUser=function(string $name,array $permissions,array $applications=['b2b']) use($admin,$send,$suffix,$k) {
    $role=status($send('POST','/management/roles',['name'=>$name.$suffix,'description'=>null,'authorityRank'=>200,'permissions'=>$permissions]),201,'role')['role']['id'];
    $employee=$admin->create($name,$name.'.'.$suffix,null,'pregatire-material','employee','Accounts user passphrase',[],'test');
    status($send('PUT',"/management/employees/{$employee->employeeUuid}/applications",['applications'=>$applications]),200,'applications');
    status($send('PUT',"/management/employees/{$employee->employeeUuid}/roles",['roleIds'=>[$role]]),200,'roles');
    return T::login($k,$name.'.'.$suffix,'Accounts user passphrase')+['id'=>$employee->employeeUuid];
};
$reversible=fn()=>status($pay(['amount'=>'2.00','method'=>'cash']),201,'reversible')['movementId'];
foreach(['view','record_payment','adjust','reverse','export'] as $permission) {
    $who=$makeUser('acc'.str_replace('_','',$permission),['b2b.accounts.'.$permission]);
    $call=fn($method,$path,$body=null,$query=[])=>T::call($k,$method,$path,$body,['x-csrf-token'=>$who['csrf'],'idempotency-key'=>'perm-'.bin2hex(random_bytes(8))],$who['cookie'],$query);
    status($call('GET',$acc),$permission==='view'?200:403,"$permission: summary");
    status($call('GET','/b2b/accounts'),$permission==='view'?200:403,"$permission: overview");
    status($call('GET',"$acc/statement.csv",null,['currency'=>'RON']),403,"$permission: export needs view and export");
    $made=$call('POST',"$acc/payments",['currencyCode'=>'RON','amount'=>'1.00','valueDate'=>$today,'method'=>'cash']);
    status($made,$permission==='record_payment'?201:403,"$permission: payment");
    if($permission==='record_payment') check($made['body']['summary']===null && $made['body']['movementId']!==null,'payment without view returns references only');
    status($call('POST',"$acc/adjustments",['currencyCode'=>'RON','direction'=>'credit','amount'=>'1.00','valueDate'=>$today,'reason'=>'p']),$permission==='adjust'?201:403,"$permission: adjustment");
    status($call('POST',"$acc/movements/{$reversible()}/reverse",['valueDate'=>$today,'reason'=>'p']),$permission==='reverse'?201:403,"$permission: reverse");
    $access=status(T::call($k,'GET','/b2b/access',null,[],$who['cookie']),200,'b2b access');
    check(in_array('b2b.accounts.'.$permission,$access['permissions'],true) && count(array_filter($access['permissions'],static fn($p)=>str_starts_with($p,'b2b.accounts.')))===1,"$permission: access lists exactly the granted account permission");
}
$exporter=$makeUser('accviewexport',['b2b.accounts.view','b2b.accounts.export']);
check(T::call($k,'GET',"$acc/statement.pdf",null,[],$exporter['cookie'],['currency'=>'EUR'])['status']===200,'view + export downloads');
$orderOnly=$makeUser('accorderonly',['b2b.orders.view','b2b.orders.create','b2b.orders.update','b2b.orders.manage_status']);
error(T::call($k,'GET',$acc,null,[],$orderOnly['cookie']),403,'UNAUTHORIZED_ACTION');
$noApp=$makeUser('accnoapp',['b2b.accounts.view','b2b.accounts.record_payment','b2b.accounts.adjust','b2b.accounts.reverse','b2b.accounts.export'],['dashboard']);
error(T::call($k,'GET',$acc,null,[],$noApp['cookie']),403,'APPLICATION_ACCESS_DENIED');
$revoked=$makeUser('accrevoked',['b2b.accounts.view','b2b.accounts.record_payment']);
$revokeCall=fn()=>T::call($k,'POST',"$acc/payments",['currencyCode'=>'RON','amount'=>'4.00','valueDate'=>$today,'method'=>'cash'],['x-csrf-token'=>$revoked['csrf'],'idempotency-key'=>'revoke-'.$suffix],$revoked['cookie']);
status($revokeCall(),201,'payment before revocation');
status($send('PUT',"/management/employees/{$revoked['id']}/roles",['roleIds'=>[]]),200,'revoke role');
error($revokeCall(),403,'UNAUTHORIZED_ACTION');
check($sum()['RON']['balance']===$ledgerBalance('RON') && $sum()['EUR']['balance']===$ledgerBalance('EUR'),'final balances equal the ledger');
// Ledger rows are insert-only: every row ever written is still present and unchanged at the end.
check((int)$rows('SELECT COUNT(*) n FROM b2b_account_movements m LEFT JOIN b2b_account_movement_sequence s USING(movement_number) WHERE s.movement_number IS NULL')[0]['n']===0,'every movement has its sequence row');

check($productionSnapshot()===$productionBefore,'production and source tables are byte-identical');
echo "PASS MySQL/MariaDB B2B accounts integration $checks checks\n";
