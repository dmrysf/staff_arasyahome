<?php
// Validates the hosted simulation's private manifest (schema 2) without printing any value from it.
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
if (PHP_SAPI!=='cli' || $argc!==2) throw new RuntimeException('Usage: php simulation/verify-hosted-manifest.php /private/manifest.json');
try {
    $manifest=Arasya\Operations\Simulation\HostedManifest::load($argv[1]);
    Arasya\Operations\Config\Config::fromEnvironment(new Arasya\Operations\Config\ConfigLoader($manifest['environment']));
} catch (Throwable $e) {
    // Only the code: never a path, setting or secret from the file.
    fwrite(STDERR,(preg_match('/^SIMULATION_[A-Z_]+$/D',$e->getMessage()) ? $e->getMessage() : 'SIMULATION_MANIFEST_INVALID') . "\n");
    exit(2);
}
echo "SIMULATION_HOSTED_MANIFEST_OK\n";
