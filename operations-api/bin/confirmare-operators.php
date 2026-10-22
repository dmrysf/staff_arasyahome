<?php

declare(strict_types=1);

// Read-only view of who can confirm orders (API 2.38.0). Opens a read-only transaction; prints no password, token,
// session, customer or order data.
//
//   php bin/confirmare-operators.php                       every identity with a Confirmare scope, per source
//   php bin/confirmare-operators.php --username=a.b [...]  existing identities by username (to grant scopes to the
//                                                          right existing account and never create a duplicate)
//
// Usernames are given by the operator on the command line; no identity is named in code.

use Arasya\Operations\Confirmare\ConfirmareScopePolicy;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['username:']);
$usernames = array_values(array_filter((array) ($options['username'] ?? []), 'is_string'));
$pdo = $container->pdo();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->beginTransaction();
$scopes = new ConfirmareScopePolicy($pdo);
$describe = static function (array $row) use ($pdo, $scopes): array {
    $apps = $pdo->prepare('SELECT application_key FROM employee_application_access WHERE employee_uuid = ? ORDER BY application_key');
    $apps->execute([$row['employee_uuid']]);
    $working = $pdo->prepare('SELECT COUNT(*) FROM employees e WHERE e.employee_uuid = ? AND ' . ConfirmareScopePolicy::workingIdentitySql('e'));
    $working->execute([$row['employee_uuid']]);
    $state = $pdo->prepare('SELECT available FROM confirmare_operator_state WHERE employee_uuid = ?');
    $state->execute([$row['employee_uuid']]);
    $open = $pdo->prepare("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'");
    $open->execute([$row['employee_uuid']]);
    return [
        'employeeId' => (string) $row['employee_uuid'],
        'username' => (string) $row['username'],
        'displayName' => (string) $row['display_name'],
        'status' => (string) $row['status'],
        'mustChangePassword' => (int) $row['must_change_password'] === 1,
        'applications' => $apps->fetchAll(PDO::FETCH_COLUMN),
        'canWorkNow' => (int) $working->fetchColumn() > 0,
        'confirmareScopes' => $scopes->scopesOf((string) $row['employee_uuid']),
        'available' => ($available = $state->fetchColumn()) === false || (int) $available === 1,
        'openAssignments' => (int) $open->fetchColumn(),
    ];
};
$select = 'SELECT employee_uuid, username, display_name, status, must_change_password FROM employees';
if ($usernames !== []) {
    $report = [];
    $normalizer = new \Arasya\Operations\Security\UsernameNormalizer();
    foreach ($usernames as $username) {
        $statement = $pdo->prepare($select . ' WHERE username_normalized = ?');
        $statement->execute([$normalizer->normalize($username)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $report[] = is_array($row) ? $describe($row) : ['username' => $username, 'exists' => false];
    }
} else {
    $statement = $pdo->query($select . ' WHERE employee_uuid IN (SELECT employee_uuid FROM employee_confirmare_scopes) ORDER BY display_name');
    $report = array_map($describe, $statement->fetchAll(PDO::FETCH_ASSOC));
}
$pdo->rollBack();
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
exit(0);
