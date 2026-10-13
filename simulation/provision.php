<?php
// CLI only. Provisions a fresh schema on the disposable server created by simulation-local.mjs.
// It verifies the server datadir before executing DDL. No DROP or live configuration fallback exists.
declare(strict_types=1);
use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Simulation\ScenarioCatalog;
use Arasya\Operations\Simulation\ScenarioService;

require dirname(__DIR__) . '/operations-api/bootstrap.php';
if (PHP_SAPI!=='cli' || $argc!==4) throw new RuntimeException('Usage: php simulation/provision.php runtime session port');
$runtime=realpath($argv[1]); $id=$argv[2]; $port=(int)$argv[3];
if ($runtime===false || !preg_match('/^[a-f0-9]{12}$/D',$id) || $port<1024 || $port>65535) throw new RuntimeException('SIMULATION_PROVISION_ARGUMENTS');
$socket=$runtime . '/sim-mariadb.sock';
if (getenv('GITHUB_ACTIONS') === 'true' && getenv('ARASYA_SIMULATION_CI_SERVER') === 'dedicated') {
    // The workflow's disposable MySQL service, never an application or deployment secret.
    $server=new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4','root',(string)getenv('ARASYA_SIMULATION_CI_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    // Published service ports reach MySQL from the Docker bridge, not its internal loopback.
    $accountHost=explode('@',(string)$server->query('SELECT USER()')->fetchColumn(),2)[1];
} else {
    $accountHost='127.0.0.1';
    $server=new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $datadir=$server->query("SHOW VARIABLES LIKE 'datadir'")->fetch(PDO::FETCH_NUM)[1];
    if (realpath($datadir)!==realpath($runtime . '/mariadb')) throw new RuntimeException('SIMULATION_SERVER_BOUNDARY');
}
$db=(getenv('ARASYA_SIMULATION_E2E') === 'true' ? 'arasya_sim_e2e_test_' : 'arasya_sim_test_') . $id; $user='sim_' . $id; $password=bin2hex(random_bytes(32));
$sessionDir=$runtime . '/sessions/' . $id;
if (file_exists($sessionDir)) throw new RuntimeException('SIMULATION_SESSION_EXISTS');
mkdir($sessionDir,0700,true);
$fixture=$sessionDir . '/marketplace.json'; file_put_contents($fixture,'{"packages":[]}'); chmod($fixture,0600);
$env=[
 'ARASYA_APP_ENV'=>'simulation','ARASYA_SIMULATION_CLOCK_MODE'=>(string)(getenv('ARASYA_SIMULATION_CLOCK_MODE')?:'realtime'),'ARASYA_APP_SECRET'=>'sim:' . bin2hex(random_bytes(32)),
 'ARASYA_DB_HOST'=>'127.0.0.1','ARASYA_DB_PORT'=>(string)$port,'ARASYA_DB_NAME'=>$db,'ARASYA_DB_USER'=>$user,'ARASYA_DB_PASSWORD'=>$password,
 'ARASYA_ALLOWED_ORIGINS'=>'http://127.0.0.1:4175,http://127.0.0.1:4173',
 'ARASYA_SOURCE_SECRET_TRENDHOME'=>'sim:' . bin2hex(random_bytes(32)), 'ARASYA_SOURCE_SECRET_OUTLETPERDELE'=>'sim:' . bin2hex(random_bytes(32)),
 'ARASYA_SOURCE_MODE_TRENDHOME'=>'active','ARASYA_SOURCE_MODE_OUTLETPERDELE'=>'active',
 'ARASYA_SOURCE_AUTHORITY_TRENDHOME'=>'enforce','ARASYA_SOURCE_AUTHORITY_OUTLETPERDELE'=>'enforce',
 'ARASYA_SOURCE_QR_AUTHORITY_TRENDHOME'=>'enforce','ARASYA_SOURCE_QR_AUTHORITY_OUTLETPERDELE'=>'enforce',
 'ARASYA_SOURCE_DOCUMENT_AUTHORITY_TRENDHOME'=>'enforce','ARASYA_SOURCE_DOCUMENT_AUTHORITY_OUTLETPERDELE'=>'enforce',
 'ARASYA_TRENDYOL_FIXTURE_FILE'=>$fixture,'ARASYA_TRENDYOL_INTAKE'=>'enabled',
 'ARASYA_LOGIN_USERNAME_LIMIT'=>'50','ARASYA_LOGIN_IP_LIMIT'=>'500',
];
foreach ($env as $key=>$value) putenv($key . '=' . $value);
$config=Config::fromEnvironment();
$server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$account="'{$user}'@" . $server->quote($accountHost);
$server->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $server->quote($password));
$grantDb=str_replace('_','\\_',$db);
$server->exec("GRANT ALL PRIVILEGES ON `{$grantDb}`.* TO " . $account);
$pdo=new PDO("mysql:host=127.0.0.1;port={$port};dbname={$db};charset=utf8mb4",$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require __DIR__ . '/initialize-session.php';
