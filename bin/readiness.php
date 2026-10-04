<?php

declare(strict_types=1);

use Arasya\Operations\Config\Config;
use Arasya\Operations\Config\ConfigLoader;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationStatus;
use Arasya\Operations\Production\PdoProductionWorkflowRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

$failed = false;
$report = static function (string $state, string $check) use (&$failed): void {
    if ($state === 'FAIL') {
        $failed = true;
    }
    fwrite(STDOUT, "{$state} {$check}\n");
};

$report(PHP_VERSION_ID >= 80200 ? 'OK' : 'FAIL', 'php_version');
$extensions = ['json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql'];
$missing = array_values(array_filter($extensions, static fn (string $extension): bool => !extension_loaded($extension)));
$report($missing === [] ? 'OK' : 'FAIL', 'required_extensions');

$loader = new ConfigLoader(null, dirname(__DIR__));
try {
    $configPath = $loader->privateFilePath();
    $config = Config::fromEnvironment($loader);
    $report('OK', 'private_config_policy');
    if ($configPath !== null) {
        $permissions = fileperms($configPath);
        $report(is_int($permissions) && ($permissions & 0o077) === 0 ? 'OK' : 'WARN', 'private_config_permissions');
    } else {
        $report('WARN', 'private_config_file_absent');
    }
} catch (Throwable) {
    $report('FAIL', 'private_config');
    exit(1);
}

try {
    $pdo = Connection::create($config);
    $pdo->query('SELECT 1')->fetchColumn();
    $report('OK', 'database');
} catch (Throwable) {
    $report('FAIL', 'database');
    exit(1);
}

try {
    $statuses = (new MigrationStatus($pdo))->inspect(dirname(__DIR__) . '/database/migrations');
    $pending = array_filter($statuses, static fn (array $status): bool => $status['status'] !== 'APPLIED');
    $report($pending === [] ? 'OK' : 'FAIL', 'migrations');
} catch (Throwable) {
    $report('FAIL', 'migrations');
}

try {
    $workflow = (new PdoProductionWorkflowRepository($pdo))->current();
    $report($workflow !== null && $workflow->id === 'curtain-production' && $workflow->version === 1 ? 'OK' : 'FAIL', 'canonical_workflow');
} catch (Throwable) {
    $report('FAIL', 'canonical_workflow');
}

foreach (['trendhome', 'outletperdele'] as $sourceKey) {
    $report(isset($config->sourceSecrets[$sourceKey]) ? 'OK' : 'WARN', "source_signing_{$sourceKey}");
}
$report($config->trendyol !== null ? 'OK' : 'WARN', 'trendyol_credentials');
try {
    $sources = $pdo->query("SELECT source_key, status, last_contact_at FROM order_sources ORDER BY source_key")->fetchAll();
    foreach ($sources as $source) {
        $contact = $source['last_contact_at'] === null ? null : new DateTimeImmutable((string) $source['last_contact_at'], new DateTimeZone('UTC'));
        $fresh = $source['status'] === 'active' && $contact !== null && time() - $contact->getTimestamp() <= $config->sourceFreshSeconds;
        $report($fresh ? 'OK' : 'WARN', 'source_contact_' . $source['source_key']);
    }
} catch (Throwable) {
    $report('FAIL', 'source_registry');
}

try {
    $releasePath = dirname(__DIR__) . '/release.json';
    if (!is_file($releasePath)) {
        throw new RuntimeException('Release metadata is missing.');
    }
    $release = json_decode((string) file_get_contents($releasePath), true, flags: JSON_THROW_ON_ERROR);
    $sourceCommit = is_array($release) ? ($release['sourceCommit'] ?? null) : null;
    $version = is_array($release) ? ($release['version'] ?? null) : null;
    $valid = is_string($sourceCommit) && preg_match('/^[0-9a-f]{40}$/', $sourceCommit) === 1 && $version === '2.2.0';
    $releaseDirectory = basename(dirname(__DIR__));
    if (preg_match('/^[0-9a-f]{40}$/', $releaseDirectory) === 1) {
        $valid = $valid && hash_equals($releaseDirectory, $sourceCommit);
    }
    $report($valid ? 'OK' : 'FAIL', 'active_release_metadata');
} catch (Throwable) {
    $report('FAIL', 'active_release_metadata');
}

exit($failed ? 1 : 0);
