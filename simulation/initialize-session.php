<?php
declare(strict_types=1);
// Shared by the local provisioner and the cPanel empty-schema initializer.
use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Simulation\ScenarioCatalog;
use Arasya\Operations\Simulation\ScenarioService;

// Both callers must supply the same context; a missing variable or any PHP warning stops before the next write.
foreach (['config','pdo','env','id','sessionDir'] as $required) if (!isset($$required)) throw new RuntimeException('SIMULATION_INITIALIZER_CONTEXT');
set_error_handler(static function(int $severity,string $message,string $file,int $line): never { throw new ErrorException($message,0,$severity,$file,$line); },E_WARNING|E_NOTICE|E_USER_WARNING|E_USER_NOTICE);
\Arasya\Operations\Simulation\IsolationGuard::identityPrivileges($config,$pdo);
$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=?'); $q->execute([$config->dbName]);
if ((int)$q->fetchColumn()!==0) throw new RuntimeException('SIMULATION_INITIALIZATION_REQUIRES_EMPTY_DATABASE');
$pdo->exec("SET time_zone = '+00:00'");
(new SqlFileRunner($pdo))->run(__DIR__ . '/schema.sql');
$baseline=(int) floor(microtime(true)*1000);
$pdo->prepare('INSERT INTO simulation_environment VALUES(1,?,?,?,?,UTC_TIMESTAMP(6))')->execute([$config->dbName,hash('sha256',$env['ARASYA_APP_SECRET']),$id,$baseline]);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/operations-api/database/migrations');
foreach (glob(dirname(__DIR__) . '/operations-api/database/seeds/*.sql') as $seed) (new SqlFileRunner($pdo))->run($seed);
$pdo->exec("INSERT INTO permissions(permission_key,category,label,description,role_grantable,created_at) VALUES('simulation.manage','simulation','Simulation controller','Control only isolated scenario fixtures',1,UTC_TIMESTAMP(6))");
if (getenv('ARASYA_SIMULATION_EMPTY') === 'true') {
    file_put_contents($sessionDir . '/private.json',json_encode([...($hostedManifest ?? []),'sessionId'=>$id,'environment'=>$env,'accounts'=>[]],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    chmod($sessionDir . '/private.json',0600);
    echo json_encode(['sessionId'=>$id,'privateManifest'=>$sessionDir . '/private.json'],JSON_THROW_ON_ERROR) . "\n";
    exit;
}
foreach (ScenarioCatalog::all() as $scenario) $pdo->prepare('INSERT INTO simulation_runs(scenario_id) VALUES(?)')->execute([$scenario['id']]);
$container=new Container($config,$pdo); $kernel=$container->kernel();
$origin=$config->allowedOrigins[0];
$call=static function(string $method,string $path,array $body=[],?array $session=null,?string $key=null) use($kernel,$config,$origin): array {
    $headers=['origin'=>$origin,'content-type'=>'application/json'];
    if ($session!==null) $headers['x-csrf-token']=$session['csrf'];
    if ($key!==null) $headers['idempotency-key']=$key;
    $r=$kernel->handle(new Request($method,$path,$headers,$session===null?[]:[$config->cookieName()=>$session['cookie']],$method==='GET'?'':json_encode($body,JSON_THROW_ON_ERROR),'127.0.0.1','simulation-provision','sim-provision'));
    if ($r->status>=300) throw new RuntimeException('SIMULATION_SETUP_' . ($r->payload['error']['code']??$r->status));
    return ['body'=>$r->payload,'headers'=>$r->headers];
};
$login=static function(string $username,string $password) use($call): array {
    $r=$call('POST','/auth/login',['username'=>$username,'password'=>$password]);
    preg_match('/^arasya_sim_session=([^;]+)/',$r['headers']['Set-Cookie']??'',$m);
    return ['cookie'=>rawurldecode($m[1]??''),'csrf'=>$r['body']['csrfToken'],'employeeUuid'=>$r['body']['employee']['employeeUuid']];
};
$rootPassword='Sim Root ' . bin2hex(random_bytes(16)) . '!';
$temp=(new RootBootstrapService($pdo,$container->passwordHasher(),$container->clock()))->bootstrap('simulation-provision','sim.root');
$root=$login('sim.root',$temp);
$call('POST','/auth/password',['currentPassword'=>$temp,'newPassword'=>$rootPassword],$root);
$root=$login('sim.root',$rootPassword);
$roles=[];
foreach ($call('GET','/management/roles',[],$root)['body']['items'] as $role) $roles[$role['key']]=$role['id'];
$controllerRole=$call('POST','/management/roles',['name'=>'TEST Simulation Controller','description'=>'Fixture controls only','authorityRank'=>100,'permissions'=>['simulation.manage']],$root)['body']['role']['id'];
$accounts=[];
$create=static function(string $name,array $stages,array $roleIds=[],array $apps=['staff'],string $locale='ro') use($container,$call,$root,&$accounts): string {
    $password='Sim ' . bin2hex(random_bytes(16)) . '!';
    $e=$container->employeeAdmin()->create('TEST ' . $name,'sim.' . $name,null,'pregatire-material','employee',$password,[],'simulation-provision');
    foreach ([['applications',['applications'=>$apps]],['roles',['roleIds'=>$roleIds]]] as [$what,$body]) {
        if ($what==='roles' && $roleIds===[]) continue;
        $call('PUT','/management/employees/' . $e->employeeUuid . '/' . $what,$body,$root);
    }
    if ($stages !== []) {
        $sources=str_starts_with($name,'trendyol-') ? ['trendyol'] : ['trendhome','outletperdele','trendyol'];
        $call('PUT','/management/employees/' . $e->employeeUuid . '/stages',['stageIds'=>$stages,'stageScopes'=>array_fill_keys($stages,$sources)],$root);
    }
    // Locale is account-owned IAM data, not a browser impersonation option.
    $call('PUT','/management/employees/' . $e->employeeUuid . '/staff-locale',['locale'=>$locale],$root);
    $accounts[$name]=['username'=>'sim.' . $name,'password'=>$password,'employeeUuid'=>$e->employeeUuid,'stages'=>$stages,'locale'=>$locale];
    return $e->employeeUuid;
};
$create('controller',[],[$controllerRole]);
$stages=$call('GET','/production/workflow',[],$root)['body']['stages'];
foreach ($stages as $i=>$stage) $create($stage['id'],[$stage['id']],$stage['id']==='waiting'?[$roles['trendyol-order-approver']]:[],['staff'],['ro','tr','en','si'][$i%4]);
$create('cutting-second',['material-preparation']);
$create('outsider',[]);
$inactive=$create('inactive',['material-preparation']); $container->employeeAdmin()->setStatus($inactive,'inactive','simulation-provision');
$temporary=$create('temporary',['material-preparation']); $pdo->prepare('UPDATE employees SET must_change_password=1 WHERE employee_uuid=?')->execute([$temporary]);
$create('trendyol-preparer',[],[$roles['trendyol-order-preparer']]);
$create('trendyol-approver',['waiting'],[$roles['trendyol-order-approver'],$roles['production-documents-operator']]);
$operator=$create('document-operator',[],[$roles['production-documents-operator']]);
$approver=$create('document-approver',[],[$roles['document-revision-approver']],['staff','dashboard']);
foreach ([[$operator,['operate'=>['trendhome','outletperdele','trendyol'],'approve'=>[]]],[$approver,['operate'=>[],'approve'=>['trendhome','outletperdele','trendyol']]],[$accounts['trendyol-approver']['employeeUuid'],['operate'=>['trendyol'],'approve'=>[]]]] as [$uuid,$scopes]) $call('PUT','/management/employees/' . $uuid . '/document-scopes',$scopes,$root);
$create('stage-supervisor',['workshop-receiving'],[$roles['supervisor']],['staff','dashboard']);
$supervisor=$create('tailoring-supervisor',['workshop-receiving']);
$call('POST','/management/organization/responsibilities',['responsibility'=>'tailoring_quality_supervisor','employeeId'=>$supervisor,'startsAt'=>null,'endsAt'=>null],$root,'sim-supervisor-appointment');
$create('manager',[],[$roles['operations-manager']],['staff','dashboard']);
$create('manager-second',[],[$roles['operations-manager']],['dashboard']);
$backup=$create('backup-approver',[],[],['dashboard']);
$docBackup=$create('document-backup',[],[],['dashboard']);
$call('PUT','/management/employees/' . $docBackup . '/document-scopes',['operate'=>[],'approve'=>['trendhome','outletperdele','trendyol']],$root);
$intake=$create('tailoring-intake-responsible',['workshop-receiving']);
foreach ([['operations_backup_approver',$backup],['document_revision_backup_approver',$docBackup],['tailoring_intake_responsible',$intake]] as [$responsibility,$uuid]) $call('POST','/management/organization/responsibilities',['responsibility'=>$responsibility,'employeeId'=>$uuid,'endsAt'=>str_contains($responsibility,'backup')?gmdate('Y-m-d\TH:i:s\Z',time()+7*86400):null],$root,'sim-appoint-' . $responsibility);
require __DIR__ . '/organization-fixtures.php';
(new Arasya\Operations\Integration\Trendyol\TrendyolIntakeState($pdo,$container->clock()))->activate($container->clock()->now(),'SIMULATION setup');
$service=new ScenarioService($container);
foreach (ScenarioCatalog::all() as $scenario) $service->command($scenario['id'],'start',$root['employeeUuid'],0);
$manifest=[...($hostedManifest ?? []),'sessionId'=>$id,'environment'=>$env,'accounts'=>$accounts,'organizationProfiles'=>$organizationProfiles,'root'=>['username'=>'sim.root','password'=>$rootPassword],'sourceCommit'=>(string)(getenv('ARASYA_SIMULATION_SOURCE_COMMIT')?:'not-recorded')];
file_put_contents($sessionDir . '/private.json',json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); chmod($sessionDir . '/private.json',0600);
echo json_encode(['sessionId'=>$id,'privateManifest'=>$sessionDir . '/private.json','accounts'=>array_map(static fn(array $a):array=>array_diff_key($a,['password'=>true]),$accounts)],JSON_THROW_ON_ERROR) . "\n";
