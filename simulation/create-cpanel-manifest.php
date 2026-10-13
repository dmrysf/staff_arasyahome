<?php
// Creates test secrets in a private folder; DB password arrives via STDIN, never command arguments.
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
if (PHP_SAPI!=='cli' || $argc!==5) throw new RuntimeException('Usage: private-directory session-id db-name db-user < private-db-password-file');
$dir=$argv[1]; $id=$argv[2];
if (!str_starts_with($dir,'/') || !preg_match('/^[a-f0-9]{12}$/D',$id) || file_exists($dir)) throw new RuntimeException('A fresh private directory and session ID are required.');
if (!preg_match('/^(?:[a-z0-9]+_)?arasya_sim_test_' . $id . '$/D',$argv[3]) || !preg_match('/^(?:[a-z0-9]+_)?sim_' . $id . '$/D',$argv[4])) throw new RuntimeException('SIMULATION_DATABASE_BOUNDARY');
$password=trim((string)stream_get_contents(STDIN)); if ($password==='') throw new RuntimeException('Database password is required on STDIN.');
mkdir($dir,0700,true); $fixture=$dir . '/marketplace.json';file_put_contents($fixture,'{"packages":[]}');chmod($fixture,0600);
$env=['ARASYA_APP_ENV'=>'simulation','ARASYA_LINE_ROUTING'=>'enforce','ARASYA_APP_SECRET'=>'sim:' . bin2hex(random_bytes(32)),
'ARASYA_DB_HOST'=>'localhost','ARASYA_DB_PORT'=>'3306','ARASYA_DB_NAME'=>$argv[3],'ARASYA_DB_USER'=>$argv[4],'ARASYA_DB_PASSWORD'=>$password,
'ARASYA_ALLOWED_ORIGINS'=>'https://sim.arasyahome.ro','ARASYA_SOURCE_SECRET_TRENDHOME'=>'sim:' . bin2hex(random_bytes(32)),
'ARASYA_SOURCE_SECRET_OUTLETPERDELE'=>'sim:' . bin2hex(random_bytes(32)),'ARASYA_SOURCE_MODE_TRENDHOME'=>'active','ARASYA_SOURCE_MODE_OUTLETPERDELE'=>'active',
'ARASYA_SOURCE_AUTHORITY_TRENDHOME'=>'enforce','ARASYA_SOURCE_AUTHORITY_OUTLETPERDELE'=>'enforce','ARASYA_SOURCE_QR_AUTHORITY_TRENDHOME'=>'enforce','ARASYA_SOURCE_QR_AUTHORITY_OUTLETPERDELE'=>'enforce',
'ARASYA_SOURCE_DOCUMENT_AUTHORITY_TRENDHOME'=>'enforce','ARASYA_SOURCE_DOCUMENT_AUTHORITY_OUTLETPERDELE'=>'enforce','ARASYA_TRENDYOL_FIXTURE_FILE'=>$fixture,
'ARASYA_TRENDYOL_INTAKE'=>'enabled','ARASYA_LOGIN_USERNAME_LIMIT'=>'50','ARASYA_LOGIN_IP_LIMIT'=>'500'];
Arasya\Operations\Config\Config::fromEnvironment(new Arasya\Operations\Config\ConfigLoader($env));
$file=$dir . '/private.json'; file_put_contents($file,json_encode(['sessionId'=>$id,'environment'=>$env,'accounts'=>[]],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));chmod($file,0600);
echo "SIMULATION_PRIVATE_MANIFEST_CREATED\n";
