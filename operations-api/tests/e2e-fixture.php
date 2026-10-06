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
$claim = T::call($kernel, 'POST', '/orders/' . rawurlencode('outletperdele:70003') . '/claim', ['expectedVersion' => 1], ['origin' => $origin, 'x-csrf-token' => $bobSession['csrf'], 'idempotency-key' => 'e2e-fixture-claim-70003'], $bobSession['cookie']);
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
$staffStep=static function(string $user,string $action,int $version,string $key)use($kernel,$password,$origin):void{
    $s=T::login($kernel,$user,$password);
    $r=T::call($kernel,'POST','/orders/'.rawurlencode('trendhome:70005').'/'.$action,['expectedVersion'=>$version],['origin'=>$origin,'x-csrf-token'=>$s['csrf'],'idempotency-key'=>$key],$s['cookie']);
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

echo json_encode([
    'password' => $password,
    'users' => ['ana' => 'ana.e2e', 'bogdan' => 'bogdan.e2e', 'mihai' => 'mihai.e2e', 'dashboardOnly' => 'dora.e2e', 'temporary' => 'teodor.e2e'],
    'orders' => ['flow' => '70001', 'qr' => '70002', 'claimedByOther' => '70003', 'conflict' => '70004'],
    'qr' => $qr,
    'b2b' => ['id'=>'b2b:'.$b2bOrder['id'],'code'=>$b2bOrder['code'],'username'=>'operator.b2b.e2e'],
    'exceptions' => ['order' => '70005', 'cutter' => 'crama.e2e', 'intake' => 'oprea.e2e', 'manager' => 'denisa.e2e'],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
