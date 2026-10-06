<?php

declare(strict_types=1);

// Reconciles the canonical organisation roster (database/reference/organization-roster.json) with the
// identities in Central IAM.
//
//   php bin/organization-reconcile.php            dry run (default): prints the plan, changes nothing
//   php bin/organization-reconcile.php --json     dry run as JSON
//   php bin/organization-reconcile.php --apply    applies department/title/additional-department
//                                                 membership for exactly matched people, as root, audited
//
// It never creates identities, never prints or changes passwords, and never assigns roles,
// applications or production stages. Missing and ambiguous people are only reported.

use Arasya\Operations\Management\OrganizationReconciler;
use Arasya\Operations\Management\OrganizationRoster;

$container = require __DIR__ . '/cli-bootstrap.php';
$arguments = array_slice($argv, 1);
foreach ($arguments as $argument) {
    if (!in_array($argument, ['--apply', '--json'], true)) {
        fwrite(STDERR, "Usage: php bin/organization-reconcile.php [--json] [--apply]\n");
        exit(2);
    }
}
$roster = OrganizationRoster::fromFile(dirname(__DIR__) . '/database/reference/organization-roster.json');
$reconciler = new OrganizationReconciler($container->pdo(), $container->managementService(), $container->employeeRepository());
$apply = in_array('--apply', $arguments, true);
$result = $apply ? $reconciler->apply($roster, 'cli-organization-' . bin2hex(random_bytes(6))) : ['plan' => $reconciler->plan($roster), 'applied' => []];

if (in_array('--json', $arguments, true)) {
    fwrite(STDOUT, json_encode(['mode' => $apply ? 'apply' : 'dry-run'] + $result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}
$plan = $result['plan'];
$summary = $plan['summary'];
fwrite(STDOUT, sprintf("Organisation roster reconciliation (%s)\nRoster: %d people. Matched: %d (%d with membership changes). Missing: %d. Ambiguous: %d. Departments to create: %d.\n\n",
    $apply ? 'APPLY' : 'dry run, nothing changed', $plan['rosterSize'], $summary['matched'], $summary['withChanges'], $summary['missing'], $summary['ambiguous'], $summary['departmentsToCreate']));
foreach ($plan['departments'] as $department) {
    fwrite(STDOUT, sprintf("  department %-28s %s\n", $department['name'], $department['action']));
}
foreach ($plan['matched'] as $person) {
    fwrite(STDOUT, sprintf("  matched  %-45s -> %s%s\n", $person['name'], $person['username'], $person['changes'] === [] ? '' : ' ' . json_encode($person['changes'], JSON_UNESCAPED_UNICODE)));
}
foreach ($plan['ambiguous'] as $person) {
    fwrite(STDOUT, sprintf("  AMBIGUOUS %-44s %d identities: %s\n", $person['name'], count($person['candidates']), implode(', ', array_column($person['candidates'], 'username'))));
}
foreach ($plan['missing'] as $person) {
    fwrite(STDOUT, sprintf("  missing  %-45s %s%s\n", $person['name'], $person['department'], $person['title'] === null ? '' : " · {$person['title']}"));
}
foreach ($result['applied'] as $line) {
    fwrite(STDOUT, "  applied: {$line}\n");
}
