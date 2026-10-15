<?php
// Shows the password of ONE synthetic simulation account to the person at an interactive terminal, for the first
// sign-in. It refuses pipes, files and logs (stdout must be a TTY), production or invalid manifests, and lists only
// account names when none is given. Real employee credentials never exist in a simulation manifest.
// Usage: php simulation/account-password.php /private/manifest.json [account-name|root]
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
if (PHP_SAPI!=='cli' || $argc<2 || $argc>3) throw new RuntimeException('Usage: php simulation/account-password.php /private/manifest.json [account-name|root]');
$manifest=Arasya\Operations\Simulation\HostedManifest::load($argv[1]);
$accounts=$manifest['accounts']??[];
if ($argc===2) {
    foreach (array_keys($accounts) as $name) echo $name . ' → ' . $accounts[$name]['username'] . "\n";
    echo "root → " . ($manifest['root']['username']??'(not initialized)') . "\n";
    exit(0);
}
if (!stream_isatty(STDOUT)) { fwrite(STDERR,"SIMULATION_PASSWORD_REQUIRES_TERMINAL\n"); exit(2); }
$account=$argv[2]==='root' ? ($manifest['root']??null) : ($accounts[$argv[2]]??null);
if (!is_array($account) || !isset($account['username'],$account['password'])) { fwrite(STDERR,"SIMULATION_ACCOUNT_UNKNOWN\n"); exit(2); }
echo $account['username'] . "\n" . $account['password'] . "\n";
