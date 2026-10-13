<?php

declare(strict_types=1);

use Arasya\Operations\Employee\StaffLocale;
use Arasya\Operations\Security\UsernameNormalizer;
use Arasya\Operations\Support\Uuid;

/*
 * Staff interface language of named identities (migration 027).
 *
 *   php bin/set-employee-locale.php --assign=<employee uuid>,<username>,<ro|tr|en|si|default> [--assign=...] [--apply]
 *
 * Without --apply it only reads and prints the plan. Every assignment names BOTH the immutable employee UUID and the
 * exact username; one mismatch, an unknown identity, the root identity or a duplicate refuses the whole run before
 * anything is written. With --apply all assignments are written in one transaction through the audited IAM service
 * as the root identity (audit action employee.staff_locale_changed). The language is presentation only: roles,
 * stages, passwords, sessions and account status are never touched. Output is JSON without any secret.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

$apply = false;
$assignments = [];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply' && !$apply) { $apply = true; continue; }
    if (preg_match('/^--assign=([0-9a-f-]{36}),([^,\s]{1,120}),(ro|tr|en|si|default)$/D', $argument, $match) === 1) {
        $assignments[] = ['employeeUuid' => $match[1], 'username' => $match[2], 'locale' => $match[3] === 'default' ? null : $match[3]];
        continue;
    }
    fwrite(STDERR, "LOCALE_INVALID_OPTIONS\n");
    exit(2);
}
if ($assignments === [] || count(array_unique(array_column($assignments, 'employeeUuid'))) !== count($assignments)) {
    fwrite(STDERR, "LOCALE_ASSIGNMENTS_REQUIRED\n");
    exit(2);
}

try {
    $container = require __DIR__ . '/cli-bootstrap.php';
    $pdo = $container->pdo();
    $normalizer = new UsernameNormalizer();
    $plan = [];
    $problems = [];
    foreach ($assignments as $assignment) {
        $statement = $pdo->prepare('SELECT e.employee_uuid, e.username, e.username_normalized, e.display_name, e.status, e.staff_locale,
                (SELECT COUNT(*) FROM system_root_identity sri WHERE sri.employee_uuid = e.employee_uuid) AS is_root
            FROM employees e WHERE e.employee_uuid = :uuid');
        $statement->execute(['uuid' => $assignment['employeeUuid']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $problems[] = ['employeeUuid' => $assignment['employeeUuid'], 'problem' => 'EMPLOYEE_NOT_FOUND'];
            continue;
        }
        if ((string) $row['username_normalized'] !== $normalizer->normalize($assignment['username'])) {
            $problems[] = ['employeeUuid' => $assignment['employeeUuid'], 'problem' => 'USERNAME_MISMATCH', 'username' => (string) $row['username']];
            continue;
        }
        if ((int) $row['is_root'] !== 0) {
            $problems[] = ['employeeUuid' => $assignment['employeeUuid'], 'problem' => 'ROOT_PROTECTED'];
            continue;
        }
        $before = is_string($row['staff_locale']) ? $row['staff_locale'] : null;
        $plan[] = [
            'employeeUuid' => (string) $row['employee_uuid'],
            'username' => (string) $row['username'],
            'displayName' => (string) $row['display_name'],
            'status' => (string) $row['status'],
            'before' => $before ?? StaffLocale::DEFAULT . ' (default)',
            'after' => $assignment['locale'] ?? StaffLocale::DEFAULT . ' (default)',
            'change' => $before !== $assignment['locale'],
        ];
    }
    if ($problems !== []) {
        fwrite(STDOUT, json_encode(['applied' => false, 'problems' => $problems, 'plan' => $plan], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        exit(1);
    }
    if (!$apply) {
        fwrite(STDOUT, json_encode(['applied' => false, 'readOnly' => true, 'plan' => $plan], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    }

    $rootId = $pdo->query('SELECT employee_uuid FROM system_root_identity WHERE singleton_id = 1')->fetchColumn();
    $root = is_string($rootId) ? $container->employeeRepository()->findByUuid($rootId) : null;
    if ($root === null || !$root->isRoot || !$root->isOperationallyActive() || $root->mustChangePassword) {
        throw new RuntimeException('LOCALE_ROOT_NOT_READY');
    }
    $requestId = 'cli-locale-' . Uuid::v4();
    $management = $container->managementService();
    $ids = array_column($assignments, 'employeeUuid');
    $management->withLockedEmployees($root, $ids, function () use ($management, $root, $assignments, $requestId): void {
        foreach ($assignments as $assignment) {
            $management->setStaffLocale($root, $assignment['employeeUuid'], $assignment['locale'], $requestId);
        }
    });
    $after = [];
    foreach ($assignments as $assignment) {
        $after[$assignment['employeeUuid']] = $container->employeeRepository()->findByUuid($assignment['employeeUuid'])?->staffLocale;
    }
    fwrite(STDOUT, json_encode(['applied' => true, 'requestId' => $requestId, 'plan' => $plan, 'sessionLocale' => $after], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
} catch (Throwable $error) {
    // Never echo PDO or exception details: they may carry configuration values.
    $code = $error instanceof RuntimeException && preg_match('/^LOCALE_[A-Z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'LOCALE_OPERATION_FAILED';
    fwrite(STDERR, $code . "\n");
    exit(1);
}
