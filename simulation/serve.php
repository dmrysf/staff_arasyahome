<?php
// Router for the same Operations API; configuration is provided by the isolated launcher.
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
try {
    if (getenv('ARASYA_APP_ENV')!=='simulation') throw new RuntimeException('SIMULATION_ENV_REQUIRED');
    $container=new Arasya\Operations\Application\Container();
    if ($container->config()->environment!=='simulation') throw new RuntimeException('SIMULATION_ENV_REQUIRED');
    $container->kernel()->handle($container->requestFactory()->fromGlobals())->send();
} catch (Throwable) {
    Arasya\Operations\Http\Response::json(['error'=>['code'=>'SIMULATION_ISOLATION_FAILURE','message'=>'Simulation configuration was refused.']],503)->send();
}
