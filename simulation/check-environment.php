<?php
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
if (PHP_SAPI!=='cli' || $argc!==2) throw new RuntimeException('Private manifest required.');
$manifest=json_decode((string)file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
$values=$manifest['environment']??[];
if (($values['ARASYA_APP_ENV']??'')!=='simulation') throw new RuntimeException('SIMULATION_ENV_REQUIRED');
putenv('ARASYA_APP_ENV=simulation');
$container=new Arasya\Operations\Application\Container(Arasya\Operations\Config\Config::fromEnvironment(new Arasya\Operations\Config\ConfigLoader($values)));
$checks=(new Arasya\Operations\Simulation\ScenarioService($container))->invariants();
foreach ($checks as $key=>$pass) echo ($pass?'PASS ':'FAIL ') . $key . "\n";
exit(in_array(false,$checks,true)?1:0);
