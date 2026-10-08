<?php

declare(strict_types=1);

use Arasya\Operations\Config\Config;
use Arasya\Operations\Config\ConfigLoader;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationStatus;
use Arasya\Operations\Http\HealthController;
use Arasya\Operations\Integration\SourceRegistry;
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

try {
    $applications = (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE application_key IN ('staff', 'dashboard', 'b2b') AND status = 'active'")->fetchColumn();
    $report($applications === 3 ? 'OK' : 'FAIL', 'iam_applications');
    $report((int) $pdo->query('SELECT COUNT(*) FROM system_root_identity')->fetchColumn() === 1 ? 'OK' : 'WARN', 'iam_root_identity');
    $b2bPermissions = (int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key IN ('b2b.companies.view', 'b2b.companies.create', 'b2b.companies.update', 'b2b.companies.manage_status') AND role_grantable = 1")->fetchColumn();
    $report($b2bPermissions === 4 ? 'OK' : 'FAIL', 'b2b_company_permissions');
    $orderPermissions = (int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key IN ('b2b.orders.view','b2b.orders.create','b2b.orders.update','b2b.orders.manage_status') AND role_grantable=1")->fetchColumn();
    $report($orderPermissions === 4 ? 'OK' : 'FAIL', 'b2b_order_permissions');
    $accountPermissions = (int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key IN ('b2b.accounts.view','b2b.accounts.record_payment','b2b.accounts.adjust','b2b.accounts.reverse','b2b.accounts.export') AND role_grantable=1")->fetchColumn();
    $report($accountPermissions === 5 ? 'OK' : 'FAIL', 'b2b_account_permissions');
    $projectPermissions = (int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key IN ('b2b.projects.view','b2b.projects.create','b2b.projects.update','b2b.projects.archive','b2b.projects.convert') AND role_grantable=1")->fetchColumn();
    $report($projectPermissions === 5 ? 'OK' : 'FAIL', 'b2b_project_permissions');
    $exceptionPermissions = (int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE (permission_key IN ('production.exceptions.approve','orders.lookup_exact') AND role_grantable=1) OR (permission_key IN ('orders.report_fault','orders.acknowledge_fault') AND role_grantable=0)")->fetchColumn();
    $report($exceptionPermissions === 4 ? 'OK' : 'FAIL', 'production_exception_permissions');
    $reasons = (int) $pdo->query("SELECT COUNT(*) FROM production_fault_reasons WHERE status = 'active'")->fetchColumn();
    $policy = $pdo->query('SELECT approval_mode FROM production_exception_policy WHERE singleton_id = 1')->fetchColumn();
    $report($reasons > 0 && $policy === 'blocking' ? 'OK' : 'FAIL', 'production_exception_policy');
    $ceo = (int) $pdo->query("SELECT COUNT(*) FROM organization_principals WHERE principal_key = 'ceo'")->fetchColumn();
    $report($ceo === 1 ? 'OK' : 'WARN', 'organization_ceo_principal');
    $documentPermissions = (int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key IN ('production.documents.generate','production.documents.reprint','production.documents.request_revision','production.documents.approve_revision','production.documents.view_history') AND role_grantable=1")->fetchColumn();
    $report($documentPermissions === 5 ? 'OK' : 'FAIL', 'production_document_permissions');
    // Two active revisions of one order would be a document-integrity failure; the unique key prevents it.
    $duplicateActive = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT active_order_uuid FROM production_document_revisions WHERE active_order_uuid IS NOT NULL GROUP BY active_order_uuid HAVING COUNT(*) > 1) d')->fetchColumn();
    $report($duplicateActive === 0 ? 'OK' : 'FAIL', 'production_document_single_active');
    // Until root assigns the approver template, revision requests can only wait (WARN, not a failure).
    $approvers = (int) $pdo->query("SELECT COUNT(*) FROM employee_role_assignments era JOIN role_permissions rp ON rp.role_id = era.role_id JOIN permissions p ON p.permission_id = rp.permission_id JOIN employees e ON e.employee_uuid = era.employee_uuid WHERE p.permission_key = 'production.documents.approve_revision' AND e.status = 'active'")->fetchColumn();
    $report($approvers > 0 ? 'OK' : 'WARN', 'production_document_revision_approver');
    // Production QR authority (019): one active QR per order is a database invariant; check it anyway.
    $qrSchema = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_qr_references' AND INDEX_NAME = 'uq_order_qr_references_active'")->fetchColumn();
    $report($qrSchema > 0 ? 'OK' : 'FAIL', 'production_qr_single_active_index');
    $duplicateQr = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT order_uuid FROM order_qr_references WHERE status = 'active' GROUP BY order_uuid HAVING COUNT(*) > 1) d")->fetchColumn();
    $report($duplicateQr === 0 ? 'OK' : 'FAIL', 'production_qr_single_active');
    $missingQr = (int) $pdo->query("SELECT COUNT(*) FROM operational_orders o WHERE o.production_authority = 'operations' AND NOT EXISTS (SELECT 1 FROM order_qr_references q WHERE q.order_uuid = o.order_uuid AND q.status = 'active') AND o.document_status IN ('none', 'active')")->fetchColumn();
    $report($missingQr === 0 ? 'OK' : 'WARN', 'production_qr_operations_active');
    $report(PHP_INT_SIZE >= 8 ? 'OK' : 'FAIL', 'b2b_fixed_point_int64');
} catch (Throwable) {
    $report('FAIL', 'iam_applications');
}
foreach (['https://staff.arasyahome.ro', 'https://dashboard.arasyahome.ro', 'https://b2b.arasyahome.ro'] as $origin) {
    if ($config->isProduction()) {
        $report(in_array($origin, $config->allowedOrigins, true) ? 'OK' : 'WARN', 'cors_origin_' . parse_url($origin, PHP_URL_HOST));
    }
}

// Signed source registry: one line per usable source and one WARN per configuration issue (never a secret).
$sourceRegistry = SourceRegistry::fromConfig($config);
foreach ($sourceRegistry->all() as $definition) {
    $report('OK', "source_signing_{$definition->key}");
    $report('OK', 'source_mode_' . $definition->key . '_' . ($definition->enabled ? $definition->mode->value : 'disabled'));
    $report('OK', 'source_authority_' . $definition->key . '_' . $definition->authorityMode->value);
    $report('OK', 'source_qr_authority_' . $definition->key . '_' . $definition->qrAuthorityMode->value);
}
foreach ($sourceRegistry->issues() as $issue) {
    $report('WARN', 'source_config_' . $issue['code'] . ($issue['sourceKey'] === null ? '' : '_' . $issue['sourceKey']));
}
$report($config->trendyol !== null ? 'OK' : 'WARN', 'trendyol_credentials');
try {
    $sources = $pdo->query("SELECT source_key, source_type, status, last_contact_at FROM order_sources ORDER BY source_key")->fetchAll();
    foreach ($sources as $source) {
        if($source['source_key']==='b2b' && $source['source_type']==='internal') {
            $report($source['status']==='active'?'OK':'WARN','source_internal_b2b');
            continue;
        }
        $definition = $sourceRegistry->find((string) $source['source_key']);
        if ($definition !== null && $definition->canIngest() && $source['status'] !== 'active') {
            $report('WARN', 'source_inactive_in_database_' . $source['source_key']);
        }
        $contact = $source['last_contact_at'] === null ? null : new DateTimeImmutable((string) $source['last_contact_at'], new DateTimeZone('UTC'));
        $fresh = $source['status'] === 'active' && $contact !== null && time() - $contact->getTimestamp() <= $config->sourceFreshSeconds;
        $report($fresh ? 'OK' : 'WARN', 'source_contact_' . $source['source_key']);
    }
    // An active signed source also needs its order_sources row before real ingestion can write.
    $registered = array_column($sources, 'source_key');
    foreach ($sourceRegistry->all() as $definition) {
        if ($definition->canIngest() && !in_array($definition->key, $registered, true)) {
            $report('WARN', 'source_missing_in_database_' . $definition->key);
        }
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
    $valid = is_string($sourceCommit) && preg_match('/^[0-9a-f]{40}$/', $sourceCommit) === 1 && $version === HealthController::VERSION;
    $releaseDirectory = basename(dirname(__DIR__));
    if (preg_match('/^[0-9a-f]{40}$/', $releaseDirectory) === 1) {
        $valid = $valid && hash_equals($releaseDirectory, $sourceCommit);
    }
    $report($valid ? 'OK' : 'FAIL', 'active_release_metadata');
} catch (Throwable) {
    $report('FAIL', 'active_release_metadata');
}

exit($failed ? 1 : 0);
