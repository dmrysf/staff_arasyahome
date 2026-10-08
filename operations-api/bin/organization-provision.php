<?php

declare(strict_types=1);

// Provisions the owner-authorized onboarding plan (database/reference/organization-onboarding.json) on top of
// the membership roster, through the official IAM services as root (validated and IAM-audited, request IDs
// "cli-onboarding-...").
//
//   php bin/organization-provision.php                       dry run (default): prints the plan, changes nothing
//   php bin/organization-provision.php --json                dry run as JSON
//   php bin/organization-provision.php --apply               creates missing identities, departments, roles,
//                                                            confirmed managers and authorized grants
//   php bin/organization-provision.php --reissue=USERNAME    new one-time password for one active identity
//   php bin/organization-provision.php --probe               creates a TEST identity without any access for the
//                                                            live first-login check
//   php bin/organization-provision.php --retire-probe=USER   deactivates that TEST identity
//
// Temporary passwords are never printed. They are written to a new 0600 file in --credentials-dir (default
// ~/arasya-onboarding, 0700, never inside the release or a web root); only its path is printed. Hand each page
// to its person individually, then delete the file.

use Arasya\Operations\Iam\CredentialFile;
use Arasya\Operations\Management\OrganizationOnboarding;
use Arasya\Operations\Management\OrganizationProvisioner;
use Arasya\Operations\Management\OrganizationReconciler;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['apply', 'json', 'reissue:', 'probe', 'retire-probe:', 'credentials-dir:']);
$known = ['--apply', '--json', '--probe'];
foreach (array_slice($argv, 1) as $argument) {
    if (!in_array($argument, $known, true) && preg_match('/^--(reissue|retire-probe|credentials-dir)=.+$/', $argument) !== 1) {
        fwrite(STDERR, "Usage: php bin/organization-provision.php [--json] [--apply | --reissue=USERNAME | --probe | --retire-probe=USERNAME] [--credentials-dir=DIR]\n");
        exit(2);
    }
}
$modes = array_intersect(['apply', 'reissue', 'probe', 'retire-probe'], array_keys($options));
if (count($modes) > 1) {
    fwrite(STDERR, "Choose one of --apply, --reissue, --probe or --retire-probe.\n");
    exit(2);
}
$mode = $modes === [] ? 'dry-run' : array_values($modes)[0];

$reference = dirname(__DIR__) . '/database/reference';
$onboarding = OrganizationOnboarding::fromFiles("{$reference}/organization-onboarding.json", "{$reference}/organization-roster.json");
$management = $container->managementService();
$provisioner = new OrganizationProvisioner(
    $container->pdo(),
    $management,
    $container->organizationService(),
    new OrganizationReconciler($container->pdo(), $management, $container->employeeRepository()),
    $container->employeeRepository(),
);
$requestId = 'cli-onboarding-' . bin2hex(random_bytes(6));
$credentials = static function () use ($options): CredentialFile {
    $home = (string) (getenv('HOME') ?: '');
    $directory = is_string($options['credentials-dir'] ?? null) ? $options['credentials-dir'] : $home . '/arasya-onboarding';
    return new CredentialFile($directory, [dirname(__DIR__, 2), dirname(__DIR__), $home . '/public_html', ...(glob($home . '/*.arasyahome.ro', GLOB_ONLYDIR) ?: [])], new DateTimeImmutable('now', new DateTimeZone('UTC')));
};

if ($mode === 'reissue' || $mode === 'probe' || $mode === 'retire-probe') {
    if ($mode === 'retire-probe') {
        $provisioner->retireProbe((string) $options['retire-probe'], $requestId);
        fwrite(STDOUT, "Probe identity {$options['retire-probe']} is inactive.\n");
        exit(0);
    }
    $sink = $credentials();
    $username = $mode === 'probe' ? $provisioner->createProbe($onboarding, $sink, $requestId) : $provisioner->reissue((string) $options['reissue'], $sink, $requestId);
    fwrite(STDOUT, ($mode === 'probe' ? 'TEST probe identity created: ' : 'Credential reissued: ') . "{$username}\nCredential file (0600, hand over individually, then delete): {$sink->location()}\nRequest: {$requestId}\n");
    exit(0);
}

$result = $mode === 'apply' ? (static function () use ($provisioner, $onboarding, $credentials, $requestId): array {
    $sink = $credentials();
    return $provisioner->apply($onboarding, $sink, $requestId);
})() : ['plan' => $provisioner->plan($onboarding), 'applied' => [], 'credentials' => 0, 'credentialFile' => null];

if (array_key_exists('json', $options)) {
    fwrite(STDOUT, json_encode(['mode' => $mode, 'requestId' => $mode === 'apply' ? $requestId : null] + $result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit($result['plan']['conflicts'] === [] ? 0 : 1);
}

$plan = $result['plan'];
$s = $plan['summary'];
fwrite(STDOUT, sprintf("Organisation onboarding (%s)\nPeople %d: create %d, existing %d. Wanted active %d, inactive %d. Credentials to issue %d. Manager links %d (to set %d). Departments to create %d. Roles to create %d. Conflicts %d.\n\n",
    $mode === 'apply' ? 'APPLIED' : 'dry run, nothing changed', $s['people'], $s['create'], $s['exists'], $s['wantedActive'], $s['wantedInactive'], $s['credentials'], $s['managerLinks'], $s['managerLinksToSet'], $s['departmentsToCreate'], $s['rolesToCreate'], $s['conflicts']));
foreach ($plan['departments'] as $department) {
    fwrite(STDOUT, sprintf("  department %-26s %-7s %s\n", $department['name'], $department['action'], $department['changes'] === [] ? '' : json_encode($department['changes'], JSON_UNESCAPED_UNICODE)));
}
foreach ($plan['roles'] as $role) {
    fwrite(STDOUT, sprintf("  role       %-26s %-7s rank %d: %s\n", $role['key'], $role['action'], $role['authorityRank'], implode(', ', $role['permissions'])));
}
fwrite(STDOUT, "\n");
foreach ($plan['people'] as $person) {
    $grants = array_filter([
        $person['applications']['wanted'] === [] ? null : 'apps ' . implode('+', $person['applications']['wanted']),
        $person['roles']['wanted'] === [] ? null : 'roles ' . implode('+', $person['roles']['wanted']),
        $person['documentScopes']['wanted']['approve'] === [] ? null : 'approve ' . implode('+', $person['documentScopes']['wanted']['approve']),
        $person['documentScopes']['wanted']['operate'] === [] ? null : 'operate ' . implode('+', $person['documentScopes']['wanted']['operate']),
        $person['principal'] === null ? null : 'principal ' . $person['principal'],
        $person['manager']['wanted'] === null ? null : 'manager ' . $person['manager']['wanted'],
        $person['excluded'] ? 'EXCLUDED' : null,
    ]);
    fwrite(STDOUT, sprintf("  %-7s %-8s %-48s %-24s %s\n", $person['action'], $person['status']['current'] ?? $person['status']['wanted'], $person['username'], $person['department'], implode(' · ', $grants)));
}
if ($plan['principal'] !== null) {
    fwrite(STDOUT, "\n  CEO principal: {$plan['principal']['username']} ({$plan['principal']['action']})\n");
}
foreach ($plan['blockedApplications'] as $department => $rule) {
    fwrite(STDOUT, "  blocked: {$department} " . implode(', ', $rule['applications']) . " — {$rule['reason']}\n");
}
foreach ($plan['conflicts'] as $conflict) {
    fwrite(STDOUT, "  CONFLICT: {$conflict}\n");
}
foreach ($plan['drift'] as $drift) {
    fwrite(STDOUT, "  drift: {$drift}\n");
}
foreach ($plan['pending'] as $pending) {
    fwrite(STDOUT, "  pending owner decision: {$pending}\n");
}
foreach ($result['applied'] as $line) {
    fwrite(STDOUT, "  applied: {$line}\n");
}
if ($mode === 'apply') {
    fwrite(STDOUT, "\nCredentials issued: {$result['credentials']}. File (0600, hand over individually, then delete): {$result['credentialFile']}\nRequest: {$requestId}\n");
}
exit($plan['conflicts'] === [] ? 0 : 1);
