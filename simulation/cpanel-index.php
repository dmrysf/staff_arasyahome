<?php
// Only this file belongs in api.sim.arasyahome.ro. All application code and secrets are private.
declare(strict_types=1);
ini_set('display_errors','0');
try {
    $release=realpath((string)getenv('ARASYA_SIMULATION_RELEASE_ROOT'));
    $file=realpath((string)getenv('ARASYA_SIMULATION_MANIFEST'));
    $web=realpath(__DIR__);
    if ($release===false || !preg_match('/^[a-f0-9]{40}$/D',basename($release)) || basename(dirname($release))!=='releases' || basename(dirname($release,2))!=='arasya-simulation') throw new RuntimeException('SIMULATION_RELEASE_BOUNDARY');
    if ($file===false || $web===false || str_starts_with($file,$web . '/') || str_starts_with($release,$web . '/') || (fileperms($file)&0077)!==0) throw new RuntimeException('SIMULATION_PRIVATE_BOUNDARY');
    if (!in_array(strtolower(explode(':',$_SERVER['HTTP_HOST']??'')[0]),['api.sim.arasyahome.ro'],true)) throw new RuntimeException('SIMULATION_HOST_BOUNDARY');
    require $release . '/operations-api/bootstrap.php';
    $manifest=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR); $values=$manifest['environment']??[];
    if (($values['ARASYA_APP_ENV']??'')!=='simulation') throw new RuntimeException('SIMULATION_ENV_REQUIRED');
    putenv('ARASYA_APP_ENV=simulation');
    $config=Arasya\Operations\Config\Config::fromEnvironment(new Arasya\Operations\Config\ConfigLoader($values));
    $container=new Arasya\Operations\Application\Container($config);
    $container->kernel()->handle($container->requestFactory()->fromGlobals())->send();
} catch (Throwable) {
    http_response_code(503); header('Content-Type: application/json'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    echo '{"error":{"code":"SIMULATION_ISOLATION_FAILURE","message":"The isolated environment could not start."}}';
}
