<?php

declare(strict_types=1);

// Builds a deterministic, disposable database for the real-API Chromium E2E suite and
// prints the fixture (credentials, order numbers, QR payloads) as JSON. It refuses any
// database whose name does not contain "e2e" and "test"; it never touches production.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$dbName = (string) getenv('ARASYA_E2E_DB_NAME');
if (preg_match('/^[a-z0-9_]*e2e[a-z0-9_]*test[a-z0-9_]*$|^[a-z0-9_]*test[a-z0-9_]*e2e[a-z0-9_]*$/D', $dbName) !== 1) {
    fwrite(STDERR, "ARASYA_E2E_DB_NAME must be a dedicated database whose name contains both 'e2e' and 'test'.\n");
    exit(2);
}
$host = (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int) (getenv('ARASYA_TEST_DB_PORT') ?: 3306);
$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), (string) getenv('ARASYA_TEST_DB_USER'), (string) getenv('ARASYA_TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
$server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$origin = (string) (getenv('ARASYA_E2E_ORIGIN') ?: 'http://127.0.0.1:4174');
$config = T::config($dbName, array_values(array_unique([$origin, T::ORIGIN])));
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
$seeds = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($seeds, SORT_STRING);
foreach ($seeds as $seed) {
    (new SqlFileRunner($pdo))->run($seed);
}

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$admin = $container->employeeAdmin();
$password = 'e2e passphrase 2026';
$admin->create('Ana Popescu', 'ana.e2e', 'E2E-ANA', 'pregatire-material', 'employee', $password, ['material-preparation'], 'e2e');
$admin->create('Bogdan Ionescu', 'bogdan.e2e', 'E2E-BOB', 'pregatire-material', 'employee', $password, ['material-preparation'], 'e2e');
$admin->create('Mihai Stan', 'mihai.e2e', 'E2E-MIH', 'pregatire-material', 'employee', $password, ['workshop-receiving'], 'e2e');
// Central IAM: a Dashboard-only identity and an identity that still has a temporary password.
$dora = $admin->create('Dora Manager', 'dora.e2e', 'E2E-DOR', 'pregatire-material', 'employee', $password, [], 'e2e');
$pdo->prepare("DELETE FROM employee_application_access WHERE employee_uuid = :id")->execute(['id' => $dora->employeeUuid]);
$pdo->prepare("INSERT INTO employee_application_access (employee_uuid, application_key, granted_at) VALUES (:id, 'dashboard', UTC_TIMESTAMP(6))")->execute(['id' => $dora->employeeUuid]);
$temporary = $admin->create('Teodor Nou', 'teodor.e2e', 'E2E-TEO', 'pregatire-material', 'employee', $password, ['material-preparation'], 'e2e');
$pdo->prepare('UPDATE employees SET must_change_password = 1 WHERE employee_uuid = :id')->execute(['id' => $temporary->employeeUuid]);

$qr = [];
$changedAt = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
foreach (['70001' => 'trendhome', '70002' => 'trendhome', '70003' => 'outletperdele', '70004' => 'outletperdele'] as $number => $source) {
    $response = T::ingest($kernel, $source, T::sourceOrder((string) $number, "e2e-{$number}-1", $changedAt, T::stage('material-preparation'), 'processing', 'active', [[
        'id' => (int) $number * 10, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => "DV-{$number}", 'color' => 'Bej',
        'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => 8.4, 'quantity' => 1,
    ]]));
    if (($response['body']['outcome'] ?? null) !== 'applied') {
        throw new RuntimeException('E2E source ingestion failed: ' . json_encode($response['body']));
    }
    $qr[$number] = $response['body']['qr'];
}
// Another employee already works on 70003 so Ana sees a blocked action.
$bobSession = T::login($kernel, 'bogdan.e2e', $password);
$claim = T::call($kernel, 'POST', '/orders/' . rawurlencode('outletperdele:70003') . '/claim', T::cuttingClaim($pdo,'outletperdele:70003',$bobSession['employeeUuid'],1), ['origin' => $origin, 'x-csrf-token' => $bobSession['csrf'], 'idempotency-key' => 'e2e-fixture-claim-70003'], $bobSession['cookie']);
if ($claim['status'] !== 200) {
    throw new RuntimeException('E2E fixture claim failed: ' . json_encode($claim['body']));
}

// Explicit B2B handoff through the real commercial commands; never inbound simulation or direct SQL order creation.
$rootPassword=(new Arasya\Operations\Iam\RootBootstrapService($pdo,$container->passwordHasher(),$container->clock()))->bootstrap('staff-b2b-e2e');
$root=T::login($kernel,Arasya\Operations\Iam\RootBootstrapService::ROOT_USERNAME,$rootPassword);
$change=T::call($kernel,'POST','/auth/password',['currentPassword'=>$rootPassword,'newPassword'=>'staff root fixture permanent 2026'],['origin'=>$origin,'x-csrf-token'=>$root['csrf']],$root['cookie']);
if($change['status']!==200) throw new RuntimeException('Staff B2B fixture root password change failed.');
$root=T::login($kernel,Arasya\Operations\Iam\RootBootstrapService::ROOT_USERNAME,'staff root fixture permanent 2026');
$commercial=static function(string $path,array $body)use($kernel,$root,$origin):array {
    $r=T::call($kernel,'POST',$path,$body,['origin'=>$origin,'x-csrf-token'=>$root['csrf'],'idempotency-key'=>'staff-fixture-'.bin2hex(random_bytes(8))],$root['cookie']);
    if($r['status']>=300) throw new RuntimeException('Staff B2B fixture command failed: '.json_encode($r['body']));
    return $r['body'];
};
$b2bCompany=$commercial('/b2b/companies',['legalName'=>'Companie înghețată E2E','countryCode'=>'RO','taxIdentifier'=>'STAFFHANDOFF1'])['companyId'];
$b2bOrder=$commercial('/b2b/orders',['companyId'=>$b2bCompany,'currencyCode'=>'RON','productionNotes'=>'Notă atelier înghețată',
    'lines'=>[['productCode'=>'B2B-CURTAIN','kind'=>'curtain','quantity'=>4,'meters'=>'13.5','pricingUnit'=>'meter','unitPriceNet'=>'10.00','vatPercent'=>'19',
        'width'=>'200','height'=>'260','notes'=>'Notă linie înghețată','productionNotes'=>'Instrucțiune producție înghețată']]])['detail']['order'];
$b2bOrder=$commercial('/b2b/orders/'.$b2bOrder['id'].'/finalize',['expectedVersion'=>$b2bOrder['version']])['detail']['order'];
$commercial('/b2b/orders/'.$b2bOrder['id'].'/production',['expectedVersion'=>$b2bOrder['version']]);
$admin->create('Operator B2B','operator.b2b.e2e',null,'pregatire-material','employee',$password,['waiting','material-preparation'],'e2e');

// Production exceptions: a four-line order (5/7/8/9 m) cut by a dedicated cutting employee and accepted at
// tailoring intake by a dedicated intake employee, plus one operations manager. Everything goes through the real API commands.
$lines=[];foreach([[1,'Voal A',5],[2,'Voal B',7],[3,'Draperie C',8],[4,'Draperie D',9]] as [$n,$name,$m]) $lines[]=['id'=>700050+$n,'line'=>$n,'name'=>$name,'sku'=>"EX-{$n}",'color'=>'Ivory','width'=>300,'height'=>260,'unit'=>'cm','meters'=>$m,'quantity'=>2];
$exceptionOrder=T::ingest($kernel,'trendhome',T::sourceOrder('70005','e2e-70005-1',$changedAt,T::stage('material-preparation'),'processing','active',$lines));
if(($exceptionOrder['body']['outcome']??null)!=='applied') throw new RuntimeException('E2E exception order ingestion failed.');
$qr['70005']=$exceptionOrder['body']['qr'];
$staffStep=static function(string $user,string $action,int $version,string $key)use($kernel,$password,$origin,$pdo):void{
    $s=T::login($kernel,$user,$password);
    $input=$action==='claim'?T::cuttingClaim($pdo,'trendhome:70005',$s['employeeUuid'],$version):['expectedVersion'=>$version];
    $r=T::call($kernel,'POST','/orders/'.rawurlencode('trendhome:70005').'/'.$action,$input,['origin'=>$origin,'x-csrf-token'=>$s['csrf'],'idempotency-key'=>$key],$s['cookie']);
    if($r['status']!==200) throw new RuntimeException('E2E exception order step failed: '.json_encode($r['body']));
};
$admin->create('Crama Florin','crama.e2e',null,'pregatire-material','employee',$password,['material-preparation'],'e2e');
$admin->create('Oprea Doina','oprea.e2e',null,'pregatire-material','employee',$password,['workshop-receiving'],'e2e');
$staffStep('crama.e2e','claim',1,'e2e-fixture-70005-claim-c');
$staffStep('crama.e2e','transition',2,'e2e-fixture-70005-cut-c');
$staffStep('oprea.e2e','claim',3,'e2e-fixture-70005-intake-o');
$opsman=$admin->create('Denisa Operațiuni','denisa.e2e',null,'pregatire-material','employee',$password,[],'e2e');
$opsRole=(int)$pdo->query("SELECT role_id FROM roles WHERE role_key='operations-manager'")->fetchColumn();
foreach([['applications',['applications'=>['dashboard']]],['roles',['roleIds'=>[$opsRole]]]] as [$what,$body]){
    $r=T::call($kernel,'PUT',"/management/employees/{$opsman->employeeUuid}/{$what}",$body,['origin'=>$origin,'x-csrf-token'=>$root['csrf']],$root['cookie']);
    if($r['status']!==200) throw new RuntimeException('E2E operations manager setup failed: '.json_encode($r['body']));
}

// Cutting milestone fixtures are isolated from the legacy Ana/Bogdan and fault-return flows.
$murat=$admin->create('Murat Tăiere','cut.murat.e2e',null,'pregatire-material','employee',$password,['material-preparation'],'e2e');
$andrea=$admin->create('Andrea Tăiere','cut.andrea.e2e',null,'pregatire-material','employee',$password,['material-preparation'],'e2e');
for($i=0;$i<77;$i++) {
    $number=(string)(71000+$i);
    $r=T::ingest($kernel,'trendhome',T::sourceOrder($number,'cut-e2e-'.$number,$changedAt,T::stage('material-preparation'),'processing','active',$i===0?$lines:null));
    if(($r['body']['outcome']??null)!=='applied') throw new RuntimeException('Cutting fixture ingestion failed.');
    $qr[$number]=$r['body']['qr'];
    if($i<10) {
        $who=T::login($kernel,'cut.murat.e2e',$password);
        $r=T::call($kernel,'POST','/orders/'.rawurlencode('trendhome:'.$number).'/claim',T::cuttingClaim($pdo,'trendhome:'.$number,$who['employeeUuid'],1),['origin'=>$origin,'x-csrf-token'=>$who['csrf'],'idempotency-key'=>'cut-fixture-'.$number],$who['cookie']);
        if($r['status']!==200) throw new RuntimeException('Cutting fixture QR claim failed.');
    }
}

// Production documents: a channel requester (Staff), the revision approver (Dashboard, decides through
// the API in the browser test) and a cutter who owns order 72001; order 72002 already has revision 2.
$roleId=static fn(string $key):int=>(int)$pdo->query("SELECT role_id FROM roles WHERE role_key=".$pdo->quote($key))->fetchColumn();
$iam=static function(string $employee,array $applications,array $roles)use($kernel,$root,$origin):void {
    foreach([['applications',['applications'=>$applications]],['roles',['roleIds'=>$roles]]] as [$what,$body]){
        $r=T::call($kernel,'PUT',"/management/employees/{$employee}/{$what}",$body,['origin'=>$origin,'x-csrf-token'=>$root['csrf']],$root['cookie']);
        if($r['status']!==200) throw new RuntimeException('E2E document IAM setup failed: '.json_encode($r['body']));
    }
};
$online=$admin->create('Ioana Online','online.doc.e2e',null,'pregatire-material','employee',$password,[],'e2e');
$iam($online->employeeUuid,['staff'],[$roleId('production-documents-operator')]);
$approver=$admin->create('Sinem Aprobare','sinem.doc.e2e',null,'pregatire-material','employee',$password,[],'e2e');
$iam($approver->employeeUuid,['dashboard'],[$roleId('document-revision-approver')]);
// Since API 2.22 a document permission reaches only the sources root scoped (default deny).
foreach([[$online->employeeUuid,['operate'=>['trendhome'],'approve'=>[]]],[$approver->employeeUuid,['operate'=>[],'approve'=>['trendhome']]]] as [$scoped,$scopes]){
    $r=T::call($kernel,'PUT',"/management/employees/{$scoped}/document-scopes",$scopes,['origin'=>$origin,'x-csrf-token'=>$root['csrf']],$root['cookie']);
    if($r['status']!==200) throw new RuntimeException('E2E document scope setup failed: '.json_encode($r['body']));
}
$docCutter=$admin->create('Murat Document','doc.cutter.e2e',null,'pregatire-material','employee',$password,['material-preparation'],'e2e');
foreach(['72001'=>8.0,'72002'=>5.0] as $number=>$meters) {
    $r=T::ingest($kernel,'trendhome',T::e2eDocumentOrder((string)$number,$meters,1));
    if(($r['body']['outcome']??null)!=='applied') throw new RuntimeException('E2E document order ingestion failed.');
    $qr[$number]=$r['body']['qr'];
}
$cutterSession=T::login($kernel,'doc.cutter.e2e',$password);
$claim=T::call($kernel,'POST','/orders/'.rawurlencode('trendhome:72001').'/claim',T::cuttingClaim($pdo,'trendhome:72001',$cutterSession['employeeUuid'],1),['origin'=>$origin,'x-csrf-token'=>$cutterSession['csrf'],'idempotency-key'=>'doc-fixture-72001-claim'],$cutterSession['cookie']);
if($claim['status']!==200) throw new RuntimeException('E2E document order claim failed.');
$docCall=static function(array $who,string $path,array $body)use($kernel,$origin):array {
    $r=T::call($kernel,'POST',$path,$body,['origin'=>$origin,'x-csrf-token'=>$who['csrf'],'idempotency-key'=>'doc-fixture-'.bin2hex(random_bytes(8))],$who['cookie']);
    if($r['status']>=300) throw new RuntimeException('E2E document fixture step failed: '.$path.' '.json_encode($r['body']));
    return $r['body'];
};
$onlineSession=T::login($kernel,'online.doc.e2e',$password);
$approverSession=T::login($kernel,'sinem.doc.e2e',$password);
$doc='/production-documents/orders/'.rawurlencode('trendhome:72002');
$docCall($onlineSession,"{$doc}/generate",['expectedDocumentVersion'=>0]);
$r=T::ingest($kernel,'trendhome',T::e2eDocumentOrder('72002',6.5,2));
if(($r['body']['outcome']??null)!=='applied') throw new RuntimeException('E2E document change failed.');
$state=$pdo->query("SELECT document_version FROM operational_orders WHERE global_order_id='trendhome:72002'")->fetchColumn();
$request=$docCall($onlineSession,"{$doc}/revision-requests",['expectedDocumentVersion'=>(int)$state])['request'];
$docCall($approverSession,"/production-documents/revision-requests/{$request['id']}/decision",['expectedVersion'=>1,'decision'=>'approve']);
$state=$pdo->query("SELECT document_version FROM operational_orders WHERE global_order_id='trendhome:72002'")->fetchColumn();
$docCall($onlineSession,"{$doc}/generate",['expectedDocumentVersion'=>(int)$state,'requestId'=>$request['id']]);
$qr['72002-r2']='ARASYA:Q1:'.$pdo->query("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid=q.order_uuid WHERE o.global_order_id='trendhome:72002' AND q.status='active'")->fetchColumn();

// Trendyol intake: activated with a baseline, two packages written through the real intake store (no HTTP): a new
// order that is preparation work and a historical one that stays ignored. Trendyol personnel are set up through
// central IAM; an unrelated waiting-stage employee must never see the Trendyol work.
$trendyolClock=new class implements Arasya\Operations\Support\Clock { public function now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('UTC')); } };
$trendyolBaseline=(new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 minute');
(new Arasya\Operations\Integration\Trendyol\TrendyolIntakeState($pdo,$trendyolClock))->activate($trendyolBaseline,'E2E fixture');
$trendyolStore=new Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore($pdo,$container->projectionWriter(),$trendyolClock);
$trendyolMs=intdiv((int)$trendyolBaseline->format('Uu'),1000);
$trendyolMarketplace=[];
foreach([[73001,$trendyolMs+5*3600000,'Created'],[73002,$trendyolMs-48*3600000,'Delivered']] as [$packageId,$orderDate,$status]){
    $trendyolRaw=['shipmentPackageId'=>$packageId,'orderNumber'=>'TY'.$packageId,'orderDate'=>$orderDate,'lastModifiedDate'=>$trendyolMs+1000,
        'shipmentPackageStatus'=>$status,'shipmentAddress'=>['fullName'=>'TEST Client Trendyol','address1'=>'Str. Test 9','city'=>'Iași','phone'=>'0744111222'],
        'lines'=>[['lineId'=>$packageId*10+1,'quantity'=>1,'productName'=>'Perdea tul alb 300x260','stockCode'=>'TY-PT-300','productSize'=>'300x260','productColor'=>'Alb']]];
    $trendyolStore->record(Arasya\Operations\Integration\Trendyol\TrendyolPackage::fromApi($trendyolRaw),$trendyolMs);
    $trendyolMarketplace[]=$trendyolRaw;
}
// The approval verifies the package against Trendyol: the E2E API answers from this fixture file
// (ARASYA_TRENDYOL_FIXTURE_FILE, refused in production).
$trendyolFixtureFile=dirname(__DIR__,2).'/e2e/.runtime/trendyol-fixture.json';
if(!is_dir(dirname($trendyolFixtureFile))) mkdir(dirname($trendyolFixtureFile),0700,true);
file_put_contents($trendyolFixtureFile,json_encode(['packages'=>$trendyolMarketplace],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
$tyApprover=$admin->create('Ilinca Trendyol','ty.approve.e2e',null,'pregatire-material','employee',$password,['waiting'],'e2e');
$iam($tyApprover->employeeUuid,['staff'],[$roleId('trendyol-order-approver'),$roleId('production-documents-operator')]);
$r=T::call($kernel,'PUT',"/management/employees/{$tyApprover->employeeUuid}/document-scopes",['operate'=>['trendyol'],'approve'=>[]],['origin'=>$origin,'x-csrf-token'=>$root['csrf']],$root['cookie']);
if($r['status']!==200) throw new RuntimeException('E2E Trendyol scope setup failed: '.json_encode($r['body']));
$admin->create('Paul Așteptare','ty.outsider.e2e',null,'pregatire-material','employee',$password,['waiting'],'e2e');
$admin->create('Tudor Tăiere','ty.cutter.e2e',null,'pregatire-material','employee',$password,['material-preparation'],'e2e');
// Department dashboards: a Trendyol preparer without approval, a multi-stage sewing employee and a supervisor
// (Staff + Dashboard, read-only production overview) with two stages.
$tyPreparer=$admin->create('Ayla Pregătire','ty.prep.e2e',null,'pregatire-material','employee',$password,[],'e2e');
$iam($tyPreparer->employeeUuid,['staff'],[$roleId('trendyol-order-preparer')]);
// Source-scoped stage grant (migration 023): the Trendyol operator profile, `waiting` granted already scoped to
// Trendyol in one root request, so the identity never holds an unrestricted waiting grant.
$tyScoped=$admin->create('Selin Trendyol','ty.scoped.e2e',null,'pregatire-material','employee',$password,[],'e2e');
$iam($tyScoped->employeeUuid,['staff'],[$roleId('trendyol-order-approver')]);
$r=T::call($kernel,'PUT',"/management/employees/{$tyScoped->employeeUuid}/stages",['stageIds'=>['waiting'],'stageScopes'=>['waiting'=>['trendyol']]],['origin'=>$origin,'x-csrf-token'=>$root['csrf']],$root['cookie']);
if($r['status']!==200) throw new RuntimeException('E2E stage scope setup failed: '.json_encode($r['body']));
$admin->create('Ciprian Croitorie','sew.e2e',null,'pregatire-material','employee',$password,['bottom-hem','side-hem','header-tape'],'e2e');
$supervisor=$admin->create('Sorina Supervizor','supervisor.e2e',null,'pregatire-material','employee',$password,['quality-control','packing'],'e2e');
$iam($supervisor->employeeUuid,['staff','dashboard'],[$roleId('supervisor')]);

echo json_encode([
    'password' => $password,
    'dashboards' => ['scoped' => 'ty.scoped.e2e', 'preparer' => 'ty.prep.e2e', 'sewing' => 'sew.e2e', 'supervisor' => 'supervisor.e2e', 'multi' => 'operator.b2b.e2e'],
    'trendyol' => ['package' => '73001', 'orderNumber' => 'TY73001', 'ignored' => '73002', 'approver' => 'ty.approve.e2e', 'outsider' => 'ty.outsider.e2e', 'cutter' => 'ty.cutter.e2e'],
    'users' => ['ana' => 'ana.e2e', 'bogdan' => 'bogdan.e2e', 'mihai' => 'mihai.e2e', 'dashboardOnly' => 'dora.e2e', 'temporary' => 'teodor.e2e'],
    'orders' => ['flow' => '70001', 'qr' => '70002', 'claimedByOther' => '70003', 'conflict' => '70004'],
    'qr' => $qr,
    'b2b' => ['id'=>'b2b:'.$b2bOrder['id'],'code'=>$b2bOrder['code'],'username'=>'operator.b2b.e2e'],
    'documents' => ['order' => '72001', 'superseded' => '72002', 'requester' => 'online.doc.e2e', 'approver' => 'sinem.doc.e2e', 'cutter' => 'doc.cutter.e2e'],
    'exceptions' => ['order' => '70005', 'cutter' => 'crama.e2e', 'intake' => 'oprea.e2e', 'manager' => 'denisa.e2e'],
    'cutting' => ['order'=>'71000','source'=>'trendhome','owner'=>'cut.murat.e2e','target'=>'cut.andrea.e2e',
        'root'=>['username'=>Arasya\Operations\Iam\RootBootstrapService::ROOT_USERNAME,'password'=>'staff root fixture permanent 2026']],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
