<?php

declare(strict_types=1);

namespace Arasya\Operations\Employee;

use Arasya\Operations\Iam\ApplicationAccess;
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

            // CLI provisioning predates central IAM and always created Staff employees.
            $this->pdo->prepare('INSERT INTO employee_role_assignments (employee_uuid, role_id, assigned_at) VALUES (:employee_uuid, :role_id, :assigned_at)')
                ->execute(['employee_uuid' => $employeeUuid, 'role_id' => $roleId, 'assigned_at' => $now]);
            $this->pdo->prepare("INSERT INTO employee_application_access (employee_uuid, application_key, granted_at) VALUES (:employee_uuid, 'staff', :granted_at)")
                ->execute(['employee_uuid' => $employeeUuid, 'granted_at' => $now]);

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
        $statement = $this->pdo->prepare('UPDATE employees SET status = :status, authorization_version = authorization_version + 1, updated_at = :updated_at WHERE employee_uuid = :employee_uuid');
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

    public function completePasswordChange(string $employeeUuid, string $passwordHash, string $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE employees SET password_hash = :password_hash, password_changed_at = :changed_at, must_change_password = 0,
                    authorization_version = authorization_version + 1, updated_at = :updated_at
             WHERE employee_uuid = :employee_uuid',
        );
        $statement->execute(['password_hash' => $passwordHash, 'changed_at' => $now, 'updated_at' => $now, 'employee_uuid' => $employeeUuid]);
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
                    e.position_title, e.manager_employee_uuid, e.must_change_password, e.authorization_version, e.department_id,
                    d.department_key, d.name AS department_name, d.status AS department_status,
                    r.role_key, r.status AS role_status,
                    (SELECT COUNT(*) FROM system_root_identity sri WHERE sri.employee_uuid = e.employee_uuid) AS is_root
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
        $uuid = (string) $row['employee_uuid'];
        $isRoot = (int) $row['is_root'] === 1;

        $applicationStatement = $this->pdo->prepare(
            $isRoot
                ? "SELECT a.application_key FROM applications a WHERE a.status = 'active' AND :employee_uuid IS NOT NULL ORDER BY a.sort_order, a.application_key"
                : "SELECT a.application_key
                   FROM employee_application_access eaa
                   INNER JOIN applications a ON a.application_key = eaa.application_key AND a.status = 'active'
                   WHERE eaa.employee_uuid = :employee_uuid
                   ORDER BY a.sort_order, a.application_key",
        );
        $applicationStatement->execute(['employee_uuid' => $uuid]);
        $applications = array_map('strval', $applicationStatement->fetchAll(PDO::FETCH_COLUMN));

        $roleStatement = $this->pdo->prepare(
            "SELECT r.role_key, r.authority_rank
             FROM employee_role_assignments era
             INNER JOIN roles r ON r.role_id = era.role_id AND r.status = 'active'
             WHERE era.employee_uuid = :employee_uuid
             ORDER BY r.authority_rank DESC, r.role_key",
        );
        $roleStatement->execute(['employee_uuid' => $uuid]);
        $roles = $roleStatement->fetchAll();
        $roleKeys = array_map(static fn (array $role): string => (string) $role['role_key'], $roles);
        $authorityRank = $roles === [] ? 0 : max(array_map(static fn (array $role): int => (int) $role['authority_rank'], $roles));

        if ($isRoot) {
            $permissions = array_map('strval', $this->pdo->query('SELECT permission_key FROM permissions ORDER BY permission_key')->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $permissionStatement = $this->pdo->prepare(
                "SELECT DISTINCT p.permission_key
                 FROM employee_role_assignments era
                 INNER JOIN roles r ON r.role_id = era.role_id AND r.status = 'active'
                 INNER JOIN role_permissions rp ON rp.role_id = r.role_id
                 INNER JOIN permissions p ON p.permission_id = rp.permission_id AND p.role_grantable = 1
                 WHERE era.employee_uuid = :employee_uuid",
            );
            $permissionStatement->execute(['employee_uuid' => $uuid]);
            $permissions = array_values(array_unique([
                ...array_map('strval', $permissionStatement->fetchAll(PDO::FETCH_COLUMN)),
                ...ApplicationAccess::baselineFor($applications),
            ]));
            sort($permissions, SORT_STRING);
        }

        $stageStatement = $this->pdo->prepare(
            "SELECT esa.stage_id
             FROM employee_stage_access esa
             INNER JOIN production_stages ps ON ps.stage_id = esa.stage_id AND ps.status = 'active'
             INNER JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id AND pw.status = 'active'
             WHERE esa.employee_uuid = :employee_uuid
             ORDER BY ps.ordinal",
        );
        $stageStatement->execute(['employee_uuid' => $uuid]);
        $stages = array_map('strval', $stageStatement->fetchAll(PDO::FETCH_COLUMN));
        // Source scopes narrow granted stages only (migration 023); a scope row of an ungranted stage is ignored.
        $stageSourceScopes = [];
        if ($stages !== []) {
            $scopeStatement = $this->pdo->prepare('SELECT stage_id, source_key FROM employee_stage_source_scopes WHERE employee_uuid = :employee_uuid ORDER BY stage_id, source_key');
            $scopeStatement->execute(['employee_uuid' => $uuid]);
            foreach ($scopeStatement->fetchAll(PDO::FETCH_ASSOC) as $scope) {
                if (in_array((string) $scope['stage_id'], $stages, true)) {
                    $stageSourceScopes[(string) $scope['stage_id']][] = (string) $scope['source_key'];
                }
            }
        }

        return new EmployeeIdentity(
            employeeUuid: $uuid,
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
            applications: $applications,
            mustChangePassword: (int) $row['must_change_password'] === 1,
            authorizationVersion: (int) $row['authorization_version'],
            isRoot: $isRoot,
            positionTitle: $row['position_title'] === null ? null : (string) $row['position_title'],
            managerUuid: $row['manager_employee_uuid'] === null ? null : (string) $row['manager_employee_uuid'],
            roleKeys: $roleKeys,
            authorityRank: $isRoot ? PHP_INT_MAX : $authorityRank,
            departmentId: (int) $row['department_id'],
            stageSourceScopes: $stageSourceScopes,
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
