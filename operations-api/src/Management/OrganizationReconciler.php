<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Employee\EmployeeRepository;
use Arasya\Operations\Http\ApiException;
use PDO;
use RuntimeException;

/**
 * Loads identities for OrganizationRoster and, only when explicitly asked, applies the membership
 * part of the plan through the official IAM service as the root identity (so every change is
 * authorised, validated and written to the IAM audit like a Dashboard change).
 *
 * Applying never creates an identity, never changes a password, role, application, stage or status,
 * and never touches ambiguous or missing people.
 */
final readonly class OrganizationReconciler
{
    public function __construct(private PDO $pdo, private ManagementService $management, private EmployeeRepository $employees)
    {
    }

    /** @return array<string, mixed> */
    public function plan(OrganizationRoster $roster): array
    {
        return $roster->plan($this->identities(), $this->departments());
    }

    /** @return array{plan: array<string, mixed>, applied: list<string>} */
    public function apply(OrganizationRoster $roster, string $requestId): array
    {
        $rootUuid = $this->pdo->query('SELECT employee_uuid FROM system_root_identity WHERE singleton_id = 1')->fetchColumn();
        $root = is_string($rootUuid) ? $this->employees->findByUuid($rootUuid) : null;
        if ($root === null || !$root->isRoot) {
            throw new RuntimeException('The root identity is required to apply the roster.');
        }
        $applied = [];
        $plan = $this->plan($roster);
        $ids = [];
        foreach ($plan['departments'] as $department) {
            if ($department['action'] === 'create') {
                $created = $this->management->createDepartment($root, ['name' => $department['name'], 'description' => $department['location']], $requestId);
                $ids[$department['key']] = (int) $created['department']['id'];
                $applied[] = "department created: {$department['name']}";
            } elseif ($department['action'] === 'exists') {
                $ids[$department['key']] = (int) $department['existingId'];
            }
        }
        foreach ($plan['matched'] as $person) {
            if ($person['changes'] === []) {
                continue;
            }
            if (!isset($ids[$person['department']]) || array_diff($person['additionalDepartments'], array_keys($ids)) !== []) {
                $applied[] = "skipped (department not usable): {$person['name']}";
                continue;
            }
            $update = [];
            if (isset($person['changes']['department'])) {
                $update['departmentId'] = $ids[$person['department']];
            }
            if (isset($person['changes']['positionTitle'])) {
                $update['positionTitle'] = $person['title'];
            }
            try {
                if ($update !== []) {
                    $this->management->updateEmployee($root, $person['employeeId'], $update, $requestId);
                }
                if (isset($person['changes']['additionalDepartments'])) {
                    $this->management->setSecondaryDepartments($root, $person['employeeId'], array_map(static fn (string $key): int => $ids[$key], $person['additionalDepartments']), $requestId);
                }
                $applied[] = "membership updated: {$person['name']} ({$person['username']})";
            } catch (ApiException $error) {
                $applied[] = "skipped ({$error->errorCode}): {$person['name']}";
            }
        }
        return ['plan' => $this->plan($roster), 'applied' => $applied];
    }

    /** @return list<array<string, mixed>> */
    private function identities(): array
    {
        $rows = $this->pdo->query(
            'SELECT e.employee_uuid, e.username, e.display_name, e.position_title, e.department_id, d.name AS department_name, e.status
             FROM employees e INNER JOIN departments d ON d.department_id = e.department_id
             WHERE NOT EXISTS (SELECT 1 FROM system_root_identity r WHERE r.employee_uuid = e.employee_uuid)
             ORDER BY e.display_name, e.employee_uuid',
        )->fetchAll(PDO::FETCH_ASSOC);
        $secondary = [];
        foreach ($this->pdo->query('SELECT esd.employee_uuid, d.name FROM employee_secondary_departments esd INNER JOIN departments d ON d.department_id = esd.department_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $secondary[(string) $row['employee_uuid']][] = (string) $row['name'];
        }
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['employee_uuid'],
            'username' => (string) $row['username'],
            'displayName' => (string) $row['display_name'],
            'positionTitle' => $row['position_title'] === null ? null : (string) $row['position_title'],
            'departmentId' => (int) $row['department_id'],
            'departmentName' => (string) $row['department_name'],
            'status' => (string) $row['status'],
            'secondaryDepartmentNames' => $secondary[(string) $row['employee_uuid']] ?? [],
        ], $rows);
    }

    /** @return list<array{id: int, name: string, status: string}> */
    private function departments(): array
    {
        return array_map(static fn (array $row): array => ['id' => (int) $row['department_id'], 'name' => (string) $row['name'], 'status' => (string) $row['status']],
            $this->pdo->query('SELECT department_id, name, status FROM departments ORDER BY department_id')->fetchAll(PDO::FETCH_ASSOC));
    }
}
