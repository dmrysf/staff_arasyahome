<?php
// Creates the hosted simulation's private manifest (schema 2) and fresh test secrets in a NEW private session
// folder `.../arasya-simulation/sessions/<session id>`. The DB password arrives via STDIN, never as an argument, and no
// secret value is ever printed. The origins are fixed: Staff https://sim.arasyahome.ro, Dashboard
// https://dashboard.sim.arasyahome.ro, API https://api.sim.arasyahome.ro; none can be supplied by the caller.
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
use Arasya\Operations\Simulation\HostedManifest;
use Arasya\Operations\Simulation\IsolationGuard;
if (PHP_SAPI!=='cli' || $argc!==5) throw new RuntimeException('Usage: private-session-directory session-id db-name db-user < private-db-password-file');
umask(077);
$dir=$argv[1]; $id=$argv[2];
if (!str_starts_with($dir,'/') || !preg_match('/^[a-f0-9]{12}$/D',$id) || file_exists($dir) || basename($dir)!==$id) throw new RuntimeException('A fresh private directory named after the session ID is required.');
if (!preg_match('/^(?:[a-z0-9]+_)?arasya_sim_test_' . $id . '$/D',$argv[3]) || !preg_match('/^(?:[a-z0-9]+_)?sim_' . $id . '$/D',$argv[4])) throw new RuntimeException('SIMULATION_DATABASE_BOUNDARY');
$password=trim((string)stream_get_contents(STDIN)); if ($password==='') throw new RuntimeException('Database password is required on STDIN.');
// The parent `sessions` folder must already exist inside the private arasya-simulation root (created 0700 by the owner).
$parent=realpath(dirname($dir));
if ($parent===false || basename($parent)!=='sessions' || basename(dirname($parent))!=='arasya-simulation' || HostedManifest::insideWebRoot($parent) || (fileperms($parent)&0077)!==0) throw new RuntimeException('SIMULATION_PRIVATE_BOUNDARY');
mkdir($dir,0700); chmod($dir,0700); $dir=realpath($dir);
$fixture=$dir . '/marketplace.json';file_put_contents($fixture,'{"packages":[]}');chmod($fixture,0600);
$env=['ARASYA_APP_ENV'=>'simulation','ARASYA_LINE_ROUTING'=>'enforce','ARASYA_APP_SECRET'=>'sim:' . bin2hex(random_bytes(32)),
'ARASYA_DB_HOST'=>'localhost','ARASYA_DB_PORT'=>'3306','ARASYA_DB_NAME'=>$argv[3],'ARASYA_DB_USER'=>$argv[4],'ARASYA_DB_PASSWORD'=>$password,
'ARASYA_ALLOWED_ORIGINS'=>HostedManifest::ALLOWED_ORIGINS,'ARASYA_SOURCE_SECRET_TRENDHOME'=>'sim:' . bin2hex(random_bytes(32)),
'ARASYA_SOURCE_SECRET_OUTLETPERDELE'=>'sim:' . bin2hex(random_bytes(32)),'ARASYA_SOURCE_MODE_TRENDHOME'=>'active','ARASYA_SOURCE_MODE_OUTLETPERDELE'=>'active',
'ARASYA_SOURCE_AUTHORITY_TRENDHOME'=>'enforce','ARASYA_SOURCE_AUTHORITY_OUTLETPERDELE'=>'enforce','ARASYA_SOURCE_QR_AUTHORITY_TRENDHOME'=>'enforce','ARASYA_SOURCE_QR_AUTHORITY_OUTLETPERDELE'=>'enforce',
'ARASYA_SOURCE_DOCUMENT_AUTHORITY_TRENDHOME'=>'enforce','ARASYA_SOURCE_DOCUMENT_AUTHORITY_OUTLETPERDELE'=>'enforce','ARASYA_TRENDYOL_FIXTURE_FILE'=>$fixture,
'ARASYA_TRENDYOL_INTAKE'=>'enabled','ARASYA_LOGIN_USERNAME_LIMIT'=>'50','ARASYA_LOGIN_IP_LIMIT'=>'500'];
$manifest=['schemaVersion'=>HostedManifest::SCHEMA_VERSION,'kind'=>HostedManifest::KIND,'sessionId'=>$id,
'origins'=>['staff'=>IsolationGuard::STAFF_ORIGIN,'dashboard'=>IsolationGuard::DASHBOARD_ORIGIN,'api'=>IsolationGuard::API_ORIGIN],
'database'=>['name'=>$argv[3],'user'=>$argv[4],'host'=>'localhost'],'paths'=>['session'=>$dir,'fixture'=>$fixture],
'environment'=>$env,'accounts'=>[]];
HostedManifest::assertValid($manifest,$dir);
Arasya\Operations\Config\Config::fromEnvironment(new Arasya\Operations\Config\ConfigLoader($env));
$file=$dir . '/private.json'; file_put_contents($file,json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));chmod($file,0600);
HostedManifest::load($file);
echo "SIMULATION_PRIVATE_MANIFEST_CREATED\n";
