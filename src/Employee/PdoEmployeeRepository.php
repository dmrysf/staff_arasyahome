<?php

declare(strict_types=1);

namespace Arasya\Operations\Employee;

use PDO;
use RuntimeException;
use Throwable;

final class PdoEmployeeRepository implements EmployeeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByNormalizedUsername(string $usernameNormalized): ?EmployeeIdentity
    {
        return $this->find('e.username_normalized = :value', $usernameNormalized);
    }

    public function findByUuid(string $employeeUuid): ?EmployeeIdentity
    {
        return $this->find('e.employee_uuid = :value', $employeeUuid);
    }

    public function create(
        string $employeeUuid,
        ?string $employeeCode,
        string $username,
        string $usernameNormalized,
        string $passwordHash,
        string $displayName,
        string $departmentKey,
        string $roleKey,
        array $allowedStageIds,
        string $now,
    ): EmployeeIdentity {
        $this->pdo->beginTransaction();
        try {
            $departmentId = $this->lookupId('departments', 'department_id', 'department_key', $departmentKey);
            $roleId = $this->lookupId('roles', 'role_id', 'role_key', $roleKey);
            $statement = $this->pdo->prepare(
                'INSERT INTO employees (employee_uuid, employee_code, username, username_normalized, password_hash, display_name, department_id, role_id, status, created_at, updated_at, password_changed_at)
                 VALUES (:employee_uuid, :employee_code, :username, :username_normalized, :password_hash, :display_name, :department_id, :role_id, :status, :created_at, :updated_at, :password_changed_at)',
            );
            $statement->execute([
                'employee_uuid' => $employeeUuid,
                'employee_code' => $employeeCode,
                'username' => $username,
                'username_normalized' => $usernameNormalized,
                'password_hash' => $passwordHash,
                'display_name' => $displayName,
                'department_id' => $departmentId,
                'role_id' => $roleId,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
                'password_changed_at' => $now,
            ]);

            $stageStatement = $this->pdo->prepare(
                'INSERT INTO employee_stage_access (employee_uuid, stage_id, created_at)
                 SELECT :employee_uuid, ps.stage_id, :created_at
                 FROM production_stages ps
                 INNER JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id
                 WHERE ps.stage_id = :stage_id AND ps.status = \'active\' AND pw.status = \'active\'',
            );
            foreach (array_values(array_unique($allowedStageIds)) as $stageId) {
                $stageStatement->execute(['employee_uuid' => $employeeUuid, 'stage_id' => $stageId, 'created_at' => $now]);
                if ($stageStatement->rowCount() !== 1) {
                    throw new RuntimeException("Unknown or inactive canonical stage ID: {$stageId}");
                }
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return $this->findByUuid($employeeUuid) ?? throw new RuntimeException('Created employee could not be loaded.');
    }

    public function updateStatus(string $employeeUuid, string $status, string $now): bool
    {
        $statement = $this->pdo->prepare('UPDATE employees SET status = :status, updated_at = :updated_at WHERE employee_uuid = :employee_uuid');
        $statement->execute(['status' => $status, 'updated_at' => $now, 'employee_uuid' => $employeeUuid]);
        return $statement->rowCount() === 1;
    }

    public function updatePasswordHash(string $employeeUuid, string $passwordHash, string $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE employees SET password_hash = :password_hash, password_changed_at = :changed_at, updated_at = :updated_at WHERE employee_uuid = :employee_uuid',
        );
        $statement->execute([
            'password_hash' => $passwordHash,
            'changed_at' => $now,
            'updated_at' => $now,
            'employee_uuid' => $employeeUuid,
        ]);
        return $statement->rowCount() === 1;
    }

    public function markLogin(string $employeeUuid, string $now): void
    {
        $statement = $this->pdo->prepare('UPDATE employees SET last_login_at = :last_login_at WHERE employee_uuid = :employee_uuid');
        $statement->execute(['last_login_at' => $now, 'employee_uuid' => $employeeUuid]);
    }

    private function find(string $where, string $value): ?EmployeeIdentity
    {
        $statement = $this->pdo->prepare(
            "SELECT e.employee_uuid, e.employee_code, e.username, e.username_normalized, e.password_hash, e.display_name, e.status,
                    d.department_key, d.name AS department_name, d.status AS department_status,
                    r.role_key, r.status AS role_status
             FROM employees e
             INNER JOIN departments d ON d.department_id = e.department_id
             INNER JOIN roles r ON r.role_id = e.role_id
             WHERE {$where}
             LIMIT 1",
        );
        $statement->execute(['value' => $value]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        $permissionStatement = $this->pdo->prepare(
            "SELECT p.permission_key
             FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.permission_id
             INNER JOIN roles r ON r.role_id = rp.role_id AND r.status = 'active'
             INNER JOIN employees e ON e.role_id = r.role_id
             WHERE e.employee_uuid = :employee_uuid
             ORDER BY p.permission_key",
        );
        $permissionStatement->execute(['employee_uuid' => $row['employee_uuid']]);
        $permissions = array_map('strval', $permissionStatement->fetchAll(PDO::FETCH_COLUMN));

        $stageStatement = $this->pdo->prepare(
            "SELECT esa.stage_id
             FROM employee_stage_access esa
             INNER JOIN production_stages ps ON ps.stage_id = esa.stage_id AND ps.status = 'active'
             INNER JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id AND pw.status = 'active'
             WHERE esa.employee_uuid = :employee_uuid
             ORDER BY ps.ordinal",
        );
        $stageStatement->execute(['employee_uuid' => $row['employee_uuid']]);
        $stages = array_map('strval', $stageStatement->fetchAll(PDO::FETCH_COLUMN));

        return new EmployeeIdentity(
            employeeUuid: (string) $row['employee_uuid'],
            employeeCode: $row['employee_code'] === null ? null : (string) $row['employee_code'],
            username: (string) $row['username'],
            usernameNormalized: (string) $row['username_normalized'],
            passwordHash: (string) $row['password_hash'],
            displayName: (string) $row['display_name'],
            departmentKey: (string) $row['department_key'],
            departmentName: (string) $row['department_name'],
            departmentStatus: (string) $row['department_status'],
            roleKey: (string) $row['role_key'],
            roleStatus: (string) $row['role_status'],
            status: (string) $row['status'],
            permissions: $permissions,
            allowedStageIds: $stages,
        );
    }

    private function lookupId(string $table, string $idColumn, string $keyColumn, string $key): int
    {
        $allowed = [
            'departments:department_id:department_key',
            'roles:role_id:role_key',
        ];
        if (!in_array("{$table}:{$idColumn}:{$keyColumn}", $allowed, true)) {
            throw new RuntimeException('Unsafe reference lookup.');
        }
        $statement = $this->pdo->prepare("SELECT {$idColumn} FROM {$table} WHERE {$keyColumn} = :key AND status = 'active' LIMIT 1");
        $statement->execute(['key' => $key]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("Unknown or inactive reference key: {$key}");
        }
        return (int) $id;
    }
}
