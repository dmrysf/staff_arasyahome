<?php
declare(strict_types=1);
// Owner scenarios A-G (2026-11-10) against the B2B current-account ledger itself, through the B2B write routes that exist
// while ARASYA_B2B_ACCOUNT_WRITES is enabled (the transition period). They pin down the ledger arithmetic and document
// the legacy single-person path; F and G also prove that `finance_only` closes that path on the server. The four-eyes
// Finance flow for the same scenarios is mysql-finance-collections-integration.php (API 2.68.0).
use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__).'/bootstrap.php';require __DIR__.'/OperationsTestSupport.php';
$db=T::requireTestDatabase();if($db===null){echo "SKIP finance scenarios: ARASYA_TEST_DB_NAME not configured.\n";exit;}
$checks=0;
function check(bool $v,string $m):void{global $checks;$checks++;if(!$v)throw new RuntimeException('FAIL '.$m);}
function status(array $r,int $s,string $m):array{if($r['status']!==$s)throw new RuntimeException("FAIL $m got ".json_encode($r['body']??$r));return $r['body']??[];}
function code(array $r):?string{return $r['body']['error']['code']??null;}

$config=T::config($db);$pdo=Connection::create($config);$dir=dirname(__DIR__).'/database/migrations';(new MigrationRunner($pdo))->migrate($dir);
foreach(glob(dirname(__DIR__).'/database/seeds/*.sql') as $f)(new SqlFileRunner($pdo))->run($f);
$c=new Container($config,$pdo);$k=$c->kernel();$suffix=bin2hex(random_bytes(5));$pdo->exec('DELETE FROM system_root_identity');$user='scenario.root.'.$suffix;
$pw=(new RootBootstrapService($pdo,$c->passwordHasher(),$c->clock()))->bootstrap('scenario-test',$user);$first=T::login($k,$user,$pw);
status(T::call($k,'POST','/auth/password',['currentPassword'=>$pw,'newPassword'=>'Scenario test passphrase 2026!'],['x-csrf-token'=>$first['csrf']],$first['cookie']),200,'root password');
$root=T::login($k,$user,'Scenario test passphrase 2026!');
$get=fn(string $path,array $query=[])=>T::call($k,'GET',$path,null,[],$root['cookie'],$query);
$send=fn(string $method,string $path,array $data,?string $key=null)=>T::call($k,$method,$path,$data,['x-csrf-token'=>$root['csrf'],'idempotency-key'=>$key??('scenario-'.bin2hex(random_bytes(8)))],$root['cookie']);
// The same database with the B2B account write routes closed (ARASYA_B2B_ACCOUNT_WRITES=finance_only).
$closed=(new Container(new \Arasya\Operations\Config\Config(...[...get_object_vars($config),'b2bAccountWrites'=>'finance_only']),$pdo))->kernel();
$sendClosed=fn(string $method,string $path,array $data)=>T::call($closed,$method,$path,$data,['x-csrf-token'=>$root['csrf'],'idempotency-key'=>'scenario-closed-'.bin2hex(random_bytes(8))],$root['cookie']);
$today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Bucharest')))->format('Y-m-d');
$rows=function(string $sql,array $params=[]) use($pdo): array {$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);};

/** A fresh company per scenario keeps every balance independent. */
$company=fn(string $tag)=>status($send('POST','/b2b/companies',['legalName'=>"Scenario $tag $suffix",'displayName'=>$tag,'countryCode'=>'RO','taxIdentifier'=>'SC'.$tag.$suffix]),201,"company $tag")['companyId'];
/** Finalized RON order with an exact gross total: meters x 10.00 net, VAT 25 %. 80 m = 1,000.00 gross. */
$order=function(string $co,string $meters) use($send,$rows): array {
    $line=['productCode'=>'CURTAIN','productName'=>null,'variant'=>null,'color'=>null,'kind'=>'curtain','width'=>'200','height'=>'260','quantity'=>1,'meters'=>$meters,'pricingUnit'=>'meter','unitPriceNet'=>'10.00','discountPercent'=>'0','vatPercent'=>'25','notes'=>null];
    $o=status($send('POST','/b2b/orders',['companyId'=>$co,'currencyCode'=>'RON','orderType'=>'FULL_TAILORING','contactId'=>null,'billingAddressId'=>null,'deliveryAddressId'=>null,'notes'=>null,'lines'=>[$line]]),201,'draft')['detail']['order'];
    status($send('POST',"/b2b/orders/{$o['id']}/finalize",['expectedVersion'=>1]),200,'finalize');
    $r=$rows("SELECT movement_uuid,amount FROM b2b_account_movements WHERE order_uuid=? AND movement_type='order_receivable'",[$o['id']])[0];
    return ['order'=>$o['id'],'receivable'=>$r['movement_uuid'],'gross'=>$r['amount']];
};
$sum=fn(string $co)=>status($get("/b2b/accounts/$co"),200,'summary')['currencies']['RON'];
$openOf=function(string $co,string $kind,string $id) use($get): ?string {foreach(status($get("/b2b/accounts/$co/open-items",['currency'=>'RON']),200,'open items')[$kind] as $i) if($i['id']===$id) return $i['openAmount'];return null;};
$pay=fn(string $co,array $body,?string $key=null)=>$send('POST',"/b2b/accounts/$co/payments",$body+['currencyCode'=>'RON','valueDate'=>$today],$key);

// ---- A: 1,000 RON order, 300 RON collected -> collected 300, remaining 700 -------------------------------------
$a=$company('A');$oa=$order($a,'80');check($oa['gross']==='1000.00','A order gross 1000.00');
$pa=status($pay($a,['amount'=>'300.00','method'=>'bank_transfer','externalReference'=>'A-OP-1','allocations'=>[['receivableId'=>$oa['receivable'],'amount'=>'300.00']]]),201,'A payment');
check($sum($a)['balance']==='700.00' && $openOf($a,'receivables',$oa['receivable'])==='700.00','A partial payment: balance 700.00, order open 700.00');

// ---- B: 350 RON advance recorded before the order exists, allocated after finalization -> remaining 650 ----------
$b=$company('B');
$pb=status($pay($b,['amount'=>'350.00','method'=>'bank_transfer','externalReference'=>'B-OP-1']),201,'B advance before order')['movementId'];
check($sum($b)['balance']==='-350.00','B advance alone is company credit (-350.00)');
$ob=$order($b,'80');
status($send('POST',"/b2b/accounts/$b/allocations",['paymentId'=>$pb,'allocations'=>[['receivableId'=>$ob['receivable'],'amount'=>'350.00']]]),201,'B allocate advance');
check($sum($b)['balance']==='650.00' && $openOf($b,'receivables',$ob['receivable'])==='650.00','B advance: balance 650.00, order open 650.00');

// ---- C: 1,100 RON paid on a 1,000 RON order -> order settled, 100 RON stays company credit ----------------------
$cc=$company('C');$oc=$order($cc,'80');
$over=$pay($cc,['amount'=>'1100.00','method'=>'bank_transfer','externalReference'=>'C-OP-1','allocations'=>[['receivableId'=>$oc['receivable'],'amount'=>'1100.00']]]);
check($over['status']===409 && code($over)==='ALLOCATION_EXCEEDS_OUTSTANDING','C allocation above the order total is refused');
$pc=status($pay($cc,['amount'=>'1100.00','method'=>'bank_transfer','externalReference'=>'C-OP-1','allocations'=>[['receivableId'=>$oc['receivable'],'amount'=>'1000.00']]]),201,'C overpayment')['movementId'];
check($sum($cc)['balance']==='-100.00' && $openOf($cc,'receivables',$oc['receivable'])===null && $openOf($cc,'payments',$pc)==='100.00','C overpayment: order settled, 100.00 unallocated credit, balance -100.00');

// ---- D: one 2,000 RON payment split across two 1,000 RON orders ---------------------------------------------------
$d=$company('D');$d1=$order($d,'80');$d2=$order($d,'80');
status($pay($d,['amount'=>'2000.00','method'=>'bank_transfer','externalReference'=>'D-OP-1','allocations'=>[['receivableId'=>$d1['receivable'],'amount'=>'1000.00'],['receivableId'=>$d2['receivable'],'amount'=>'1000.00']]]),201,'D split payment');
check($sum($d)['balance']==='0.00' && $openOf($d,'receivables',$d1['receivable'])===null && $openOf($d,'receivables',$d2['receivable'])===null,'D one payment settles two orders, balance 0.00');
$cross=$company('Dx');$dx=$order($cross,'80');
$wrong=$pay($d,['amount'=>'10.00','method'=>'cash','allocations'=>[['receivableId'=>$dx['receivable'],'amount'=>'10.00']]]);
check($wrong['status']>=400,'D a payment cannot settle another company\'s order (got '.$wrong['status'].' '.code($wrong).')');

// ---- E: cancel a paid, finalized order -> receivable reversed, allocation released, payment becomes credit --------
$e=$company('E');$oe=$order($e,'80');
$pe=status($pay($e,['amount'=>'1000.00','method'=>'bank_transfer','externalReference'=>'E-OP-1','allocations'=>[['receivableId'=>$oe['receivable'],'amount'=>'1000.00']]]),201,'E payment')['movementId'];
status($send('POST',"/b2b/orders/{$oe['order']}/cancel",['expectedVersion'=>2]),200,'E cancel finalized order');
check(count($rows("SELECT 1 FROM b2b_account_movements WHERE order_uuid=? AND movement_type='reversal'",[$oe['order']]))===1,'E one automatic reversal of the receivable');
check($sum($e)['balance']==='-1000.00' && $openOf($e,'payments',$pe)==='1000.00','E payment released: 1000.00 company credit');
check(count($rows("SELECT 1 FROM b2b_account_allocation_releases r JOIN b2b_account_allocations a ON a.allocation_uuid=r.allocation_uuid WHERE a.payment_movement_uuid=?",[$pe]))===1,'E allocation released automatically');
// Refund: there is no refund movement type; returning the money is recorded as a reversal of the payment (or a debit adjustment).
status($send('POST',"/b2b/accounts/$e/movements/$pe/reverse",['valueDate'=>$today,'reason'=>'Money returned to the customer']),201,'E refund as payment reversal');
check($sum($e)['balance']==='0.00','E after the refund reversal: balance 0.00');
// Legacy path: returning money is a payment reversal by one person. API 2.68.0 adds the Finance refund request (own
// movement type 'refund', second approver, separate payout), covered by mysql-finance-collections-integration.php.

// ---- F: four eyes on collections ------------------------------------------------------------------------------------
$f=$company('F');$of=$order($f,'80');
$pf=status($pay($f,['amount'=>'500.00','method'=>'bank_transfer','externalReference'=>'F-OP-1','allocations'=>[['receivableId'=>$of['receivable'],'amount'=>'500.00']]]),201,'F payment')['movementId'];
$approve=$send('POST',"/b2b/accounts/$f/movements/$pf/approve",[]);
check($approve['status']===404 || $approve['status']===405,'F there is no approval endpoint for a payment (got '.$approve['status'].')');
check($sum($f)['balance']==='500.00','F the payment reduces the balance immediately, without a second person');
// Legacy path: single person. With the routes closed the server refuses it; Finance requests need a second approver.
$closedPay=$sendClosed('POST',"/b2b/accounts/$f/payments",['currencyCode'=>'RON','amount'=>'1.00','valueDate'=>$today,'method'=>'cash']);
check($closedPay['status']===409 && code($closedPay)==='ACCOUNT_WRITES_MOVED_TO_FINANCE','F finance_only: the server refuses the single-person B2B payment');
$cols=array_column($rows("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='b2b_account_movements'"),'column_name');
check(!in_array('verified_by_employee_uuid',$cols,true) && !in_array('verification_status',$cols,true),'F the ledger row has no verifier or verification status column');

// ---- G: the same bank transaction submitted twice -----------------------------------------------------------------
$g=$company('G');$og=$order($g,'80');$keyG='bank-G-'.$suffix;
$body=['amount'=>'400.00','method'=>'bank_transfer','externalReference'=>'ING-2026-11-10-0001'];
$g1=status($pay($g,$body,$keyG),201,'G first submission');$g2=status($pay($g,$body,$keyG),201,'G same key replay');
check($g1['movementId']===$g2['movementId'],'G same Idempotency-Key: one payment');
$g3=$pay($g,$body);
if($g3['status']===201){
    check(count($rows("SELECT 1 FROM b2b_account_movements WHERE company_uuid=? AND movement_type='payment' AND external_reference=?",[$g,$body['externalReference']]))===2,'G legacy path: a new key with the same free-text reference records a second payment');
} else {
    throw new RuntimeException('FAIL G legacy B2B path changed unexpectedly: '.code($g3));
}
$closedRepeat=$sendClosed('POST',"/b2b/accounts/$g/payments",$body+['currencyCode'=>'RON','valueDate'=>$today]);
check($closedRepeat['status']===409 && code($closedRepeat)==='ACCOUNT_WRITES_MOVED_TO_FINANCE','G finance_only: the legacy path is closed; Finance refuses an exact bank transaction twice');

echo "PASS MySQL B2B finance scenarios A-G: {$checks} checks (partial, advance, overpayment, split, cancellation, legacy single-person path closed by finance_only).\n";
