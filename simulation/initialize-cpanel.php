<?php
// Initializes an EMPTY, pre-created, schema-only cPanel database. No server-level SQL or DROP.
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
if (PHP_SAPI!=='cli' || $argc!==2) throw new RuntimeException('Usage: php simulation/initialize-cpanel.php /private/manifest.json');
$file=realpath($argv[1]);
if ($file===false || (fileperms($file)&0077)!==0) throw new RuntimeException('SIMULATION_PRIVATE_MANIFEST_REQUIRED');
$manifest=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR); $env=$manifest['environment']??[];
if (($env['ARASYA_APP_ENV']??'')!=='simulation') throw new RuntimeException('SIMULATION_ENV_REQUIRED');
$id=(string)($manifest['sessionId']??'');
if (!preg_match('/^[a-f0-9]{12}$/D',$id)) throw new RuntimeException('SIMULATION_SESSION_ID_INVALID');
foreach ($env as $key=>$value) putenv($key . '=' . $value);
$config=Arasya\Operations\Config\Config::fromEnvironment(new Arasya\Operations\Config\ConfigLoader($env));
$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$config->dbHost,$config->dbPort,$config->dbName),$config->dbUser,$config->dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$sessionDir=dirname($file);
require __DIR__ . '/initialize-session.php';
