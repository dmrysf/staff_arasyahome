<?php
// Initializes an EMPTY, pre-created, schema-only cPanel database. No server-level SQL or DROP.
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
use Arasya\Operations\Simulation\HostedManifest;
if (PHP_SAPI!=='cli' || $argc!==2) throw new RuntimeException('Usage: php simulation/initialize-cpanel.php /private/manifest.json');
umask(077);
$file=realpath($argv[1]);
if ($file===false) throw new RuntimeException('SIMULATION_PRIVATE_MANIFEST_REQUIRED');
$manifest=HostedManifest::load($file); $env=$manifest['environment']; $id=$manifest['sessionId'];
// Initialization rewrites the manifest with the synthetic accounts; the hosted identity and origins stay as created.
$hostedManifest=HostedManifest::metadata($manifest);
foreach ($env as $key=>$value) putenv($key . '=' . $value);
$config=Arasya\Operations\Config\Config::fromEnvironment(new Arasya\Operations\Config\ConfigLoader($env));
$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$config->dbHost,$config->dbPort,$config->dbName),$config->dbUser,$config->dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$sessionDir=dirname($file);
require __DIR__ . '/initialize-session.php';
HostedManifest::load($file);
