<?php
declare(strict_types=1);

use Arasya\Operations\Iam\CredentialFile;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Management\FactoryApproval;
use Arasya\Operations\Management\FactoryOnboardingPlan;
use Arasya\Operations\Management\FactoryProvisioner;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
// Parse before bootstrap. Unknown/missing options cannot accidentally become an apply or create a credential file.
$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--apply', '--json'], true)) { $key = substr($argument, 2); $value = true; }
    elseif (preg_match('/^--(group|expected-plan|approval-file|credentials-dir)=(.+)$/D', $argument, $match)) { $key = $match[1]; $value = $match[2]; }
    else { fwrite(STDERR, "FACTORY_INVALID_OPTIONS\n"); exit(2); }
    if (isset($options[$key])) { fwrite(STDERR, "FACTORY_DUPLICATE_OPTION\n"); exit(2); }
    $options[$key] = $value;
}
$apply = isset($options['apply']);
if (($apply && (!isset($options['group'], $options['expected-plan'], $options['approval-file']) || !preg_match('/^[a-f0-9]{64}$/D', $options['expected-plan']))) || (!$apply && array_intersect(array_keys($options), ['expected-plan', 'approval-file', 'credentials-dir']) !== [])) {
    fwrite(STDERR, "FACTORY_SEPARATE_GROUP_APPROVAL_REQUIRED\n"); exit(2);
}
try {
    $container = require __DIR__ . '/cli-bootstrap.php';
    $root = dirname(__DIR__);
    $matrix = FactoryOnboardingPlan::fromFiles($root . '/database/reference/factory-onboarding.json', $root . '/database/reference/organization-roster.json');
    $provisioner = new FactoryProvisioner($container->pdo(), $container->managementService(), $container->employeeRepository(), new IamAuditLogger($container->pdo()), $container->clock(), $matrix);
    $group = $options['group'] ?? null;
    if (!$apply) {
        $container->pdo()->exec('SET TRANSACTION READ ONLY');
        $container->pdo()->beginTransaction();
        try { $result = $provisioner->plan($group); } finally { $container->pdo()->rollBack(); }
    } else {
        $home = (string) getenv('HOME');
        $forbidden = [$root, dirname($root), $home . '/public_html', ...(glob($home . '/*.arasyahome.ro', GLOB_ONLYDIR) ?: [])];
        $approval = FactoryApproval::fromFile($options['approval-file'], $group, $options['expected-plan'], $container->clock()->now(), $forbidden);
        $credentials = new CredentialFile($options['credentials-dir'] ?? $home . '/arasya-onboarding', $forbidden, $container->clock()->now());
        $result = $provisioner->apply($approval, $credentials);
    }
    // Result types contain IAM metadata and the private file path only. Passwords never enter this result.
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(($result['conflicts'] ?? []) === [] ? 0 : 1);
} catch (Throwable $error) {
    // Never echo exception details from PDO, CredentialSink or an argument. These may carry confidential values.
    $code = $error instanceof RuntimeException && preg_match('/^FACTORY_[A-Z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'FACTORY_OPERATION_FAILED';
    fwrite(STDERR, $code . "\n"); exit(1);
}
