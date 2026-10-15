<?php

declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
use Arasya\Operations\Config\Config;
use Arasya\Operations\Config\ConfigLoader;
use Arasya\Operations\Config\TrendyolCredentials;
use Arasya\Operations\Integration\Trendyol\StreamTrendyolTransport;
$fixture=tempnam(sys_get_temp_dir(),'sim-isolation-'); file_put_contents($fixture,'{"packages":[]}');
$base=['environment'=>'simulation','appSecret'=>'sim:' . str_repeat('a',64),'dbHost'=>'127.0.0.1','dbPort'=>13361,'dbName'=>'arasya_sim_test_012345abcdef','dbUser'=>'sim_012345abcdef','dbPassword'=>'test-only',
'allowedOrigins'=>['http://127.0.0.1:4175'],'sessionTtlSeconds'=>36000,'sessionTouchIntervalSeconds'=>300,'loginUsernameLimit'=>5,'loginIpLimit'=>30,'loginWindowSeconds'=>900,'trustProxy'=>false,'trustedProxies'=>[],
'sourceSecrets'=>['trendhome'=>'sim:' . str_repeat('b',64)],'trendyolFixtureFile'=>$fixture];
$passed=0;
$refuse=static function(string $name,array $changed)use($base,&$passed):void {
    try { new Config(...array_replace($base,$changed)); }
    catch (RuntimeException) { $passed++; echo "PASS {$name}\n"; return; }
    throw new RuntimeException('FAIL ' . $name);
};
new Config(...$base); $passed++;
foreach (['live','arasya_operations_test','arasya_sim_test_012345abcdef;dbname=live'] as $db) $refuse('production_or_injected_database',['dbName'=>$db]);
$refuse('privileged_user',['dbUser'=>'root']);
$refuse('other_session_user',['dbUser'=>'sim_abcdef012345']);
$refuse('production_db_host',['dbHost'=>'api.arasyahome.ro']);
$refuse('production_origin',['allowedOrigins'=>['https://staff.arasyahome.ro']]);
// Hosted origins: exactly the Staff and Dashboard simulation frontends, never a production, lookalike or mixed set.
new Config(...array_replace($base,['allowedOrigins'=>['https://sim.arasyahome.ro','https://dashboard.sim.arasyahome.ro']])); $passed++; echo "PASS hosted_staff_and_dashboard_origins\n";
new Config(...array_replace($base,['allowedOrigins'=>['https://dashboard.sim.arasyahome.ro']])); $passed++; echo "PASS hosted_dashboard_origin\n";
foreach ([
  'production_dashboard_origin'=>['https://dashboard.arasyahome.ro'],
  'production_api_origin'=>['https://api.arasyahome.ro'],
  'simulation_api_as_browser_origin'=>['https://api.sim.arasyahome.ro'],
  'arbitrary_external_origin'=>['https://evil.example'],
  'lookalike_suffix'=>['https://dashboard.sim.arasyahome.ro.evil.example'],
  'lookalike_prefix'=>['https://evildashboard.sim.arasyahome.ro'],
  'other_simulation_subdomain'=>['https://x.sim.arasyahome.ro'],
  'http_hosted_origin'=>['http://dashboard.sim.arasyahome.ro'],
  'hosted_origin_with_port'=>['https://dashboard.sim.arasyahome.ro:8443'],
  'hosted_origin_with_slash'=>['https://dashboard.sim.arasyahome.ro/'],
  'hosted_origin_with_path'=>['https://sim.arasyahome.ro/app'],
  'hosted_origin_with_credentials'=>['https://user@sim.arasyahome.ro'],
  'uppercase_hosted_origin'=>['https://SIM.arasyahome.ro'],
  'wildcard_origin'=>['*'],
  'null_origin'=>['null'],
  'empty_origin'=>[''],
  'no_origin'=>[],
  'duplicate_origin'=>['https://sim.arasyahome.ro','https://sim.arasyahome.ro'],
  'mixed_hosted_and_loopback'=>['https://sim.arasyahome.ro','http://127.0.0.1:4175'],
  'mixed_hosted_and_production'=>['https://sim.arasyahome.ro','https://dashboard.arasyahome.ro'],
  'https_loopback'=>['https://127.0.0.1:4175'],
  'loopback_without_port'=>['http://127.0.0.1'],
  'private_network_origin'=>['http://192.168.1.2:4175'],
] as $name=>$origins) $refuse($name,['allowedOrigins'=>$origins]);
$refuse('trusted_proxy_header',['trustProxy'=>true,'trustedProxies'=>['127.0.0.1']]);
$refuse('trusted_proxy_list',['trustedProxies'=>['10.0.0.1']]);
$refuse('production_application_secret',['appSecret'=>str_repeat('p',64)]);
$refuse('production_source_secret',['sourceSecrets'=>['trendhome'=>str_repeat('p',64)]]);
$refuse('real_trendyol_credentials',['trendyol'=>TrendyolCredentials::fromValues('123456','real-key','real-secret','')]);
$refuse('missing_fixture',['trendyolFixtureFile'=>null]);
$refuse('production_cannot_use_fixture',['environment'=>'production','allowedOrigins'=>['https://staff.arasyahome.ro']]);
$values=(new ConfigLoader(['ARASYA_APP_ENV'=>'simulation','HOME'=>'/does/not/exist','ARASYA_CONFIG_FILE'=>'/production/secrets.json','DB_NAME'=>'production','ARASYA_DB_NAME'=>$base['dbName']]))->load();
if (($values['ARASYA_DB_NAME']??null)!==$base['dbName'] || isset($values['DB_NAME'])) throw new RuntimeException('FAIL configuration_fallback');
$passed++; echo "PASS no_private_configuration_or_alias_fallback\n";
$previous=getenv('ARASYA_APP_ENV'); putenv('ARASYA_APP_ENV=simulation');
try { (new StreamTrendyolTransport())->get('https://apigw.trendyol.com',[]); throw new LogicException('FAIL outbound'); }
catch (RuntimeException $e) { if ($e->getMessage()!=='SIMULATION_OUTBOUND_DENIED') throw $e; $passed++; echo "PASS real_transport_refuses_before_network\n"; }
finally { $previous===false ? putenv('ARASYA_APP_ENV') : putenv('ARASYA_APP_ENV=' . $previous); unlink($fixture); }
echo "PASS isolation {$passed} checks\n";
