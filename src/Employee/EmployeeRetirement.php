<?php

declare(strict_types=1);

namespace Arasya\Operations\Employee;

use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Support\Clock;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Server-console inventory and retirement of one duplicate employee identity (no HTTP route).
 *
 * - inventory: read-only facts about one username, never a password hash, token or session secret.
 * - plan: every inbound foreign-key reference to the UUID, found from the live schema, split into
 *   administrative associations (application access, roles, stages, source scopes, secondary departments)
 *   and history. Any history reference (sessions, audit, production, quality, responsibilities, ...)
 *   blocks physical deletion; nothing is ever re-pointed to another UUID and no history is deleted.
 * - disable: status inactive and every session revoked, through the audited EmployeeAdminService. This
 *   writes an authentication audit row for the UUID, so a disabled identity keeps its history and can no
 *   longer be deleted: disabling is the outcome whenever deletion is not possible.
 * - delete: only for an identity whose references are all administrative (it never signed in, owns no
 *   history and holds no session). Writes an access-restricted JSON backup first (without the password
 *   hash), re-checks the references with the employee row locked, removes the associations and the
 *   employee row in one transaction and records `employee.deleted` in the IAM audit, whose target is plain
 *   text and therefore survives the deletion.
 */
final readonly class EmployeeRetirement
{
    public const ADMINISTRATIVE = ['employee_application_access', 'employee_role_assignments', 'employee_stage_access', 'employee_stage_source_scopes', 'employee_secondary_departments'];
    private const SAFE_COLUMNS = 'e.employee_uuid, e.employee_code, e.username, e.username_normalized, e.display_name, e.position_title, e.manager_employee_uuid, e.status,
        e.must_change_password, e.authorization_version, e.created_at, e.updated_at, e.password_changed_at, e.last_login_at';

    public function __construct(private PDO $pdo, private EmployeeAdminService $admin, private IamAuditLogger $audit, private Clock $clock)
    {
    }

    /** @return array<string, mixed>|null */
    public function inventory(string $username): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::SAFE_COLUMNS . ', d.department_key, d.name AS department_name, r.role_key AS primary_role
            FROM employees e INNER JOIN departments d ON d.department_id = e.department_id INNER JOIN roles r ON r.role_id = e.role_id
            WHERE e.username_normalized = ?');
        $statement->execute([mb_strtolower(trim($username))]);
        $employee = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($employee)) {
            return null;
        }
        $uuid = (string) $employee['employee_uuid'];
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        return [
            'employee' => $employee,
            'isRoot' => $this->count('SELECT COUNT(*) FROM system_root_identity WHERE employee_uuid = ?', [$uuid]) > 0,
            'applications' => $this->column('SELECT application_key FROM employee_application_access WHERE employee_uuid = ? ORDER BY application_key', [$uuid]),
            'roles' => $this->column('SELECT r.role_key FROM employee_role_assignments a INNER JOIN roles r ON r.role_id = a.role_id WHERE a.employee_uuid = ? ORDER BY r.role_key', [$uuid]),
            'permissions' => $this->column("SELECT DISTINCT p.permission_key FROM employee_role_assignments a INNER JOIN roles r ON r.role_id = a.role_id AND r.status = 'active'
                INNER JOIN role_permissions rp ON rp.role_id = r.role_id INNER JOIN permissions p ON p.permission_id = rp.permission_id WHERE a.employee_uuid = ? ORDER BY p.permission_key", [$uuid]),
            'stages' => $this->column('SELECT stage_id FROM employee_stage_access WHERE employee_uuid = ? ORDER BY stage_id', [$uuid]),
            'sourceScopes' => $this->rows('SELECT stage_id, source_key FROM employee_stage_source_scopes WHERE employee_uuid = ? ORDER BY stage_id, source_key', [$uuid]),
            'secondaryDepartments' => $this->column('SELECT d.department_key FROM employee_secondary_departments s INNER JOIN departments d ON d.department_id = s.department_id WHERE s.employee_uuid = ? ORDER BY d.department_key', [$uuid]),
            'responsibilities' => array_map(static fn (array $row): array => $row + ['active' => $row['revoked_at'] === null && (string) $row['starts_at'] <= $now && ($row['ends_at'] === null || (string) $row['ends_at'] > $now)],
                $this->rows('SELECT responsibility_key, starts_at, ends_at, revoked_at FROM responsibility_assignments WHERE employee_uuid = ? ORDER BY created_at', [$uuid])),
            'principals' => $this->column('SELECT principal_key FROM organization_principals WHERE employee_uuid = ?', [$uuid]),
            'directReports' => $this->count('SELECT COUNT(*) FROM employees WHERE manager_employee_uuid = ?', [$uuid]),
            'activeSessions' => $this->count('SELECT COUNT(*) FROM auth_sessions WHERE employee_uuid = ? AND revoked_at IS NULL AND expires_at > ?', [$uuid, $now]),
            'openOwnedOrders' => $this->count('SELECT COUNT(*) FROM operational_orders WHERE production_owner_employee_uuid = ? AND production_completed_at IS NULL', [$uuid]),
            'iamAuditAsTarget' => $this->count("SELECT COUNT(*) FROM iam_audit_events WHERE target_id = ?", [$uuid]),
            'references' => $this->references($uuid),
        ];
    }

    /** @return array{employeeUuid: string, username: string, status: string, references: array<string, int>, blockers: array<string, int>, activeResponsibilities: list<string>, deletable: bool} */
    public function plan(string $employeeUuid, string $username): array
    {
        $employee = $this->target($employeeUuid, $username);
        $references = $this->references($employeeUuid);
        $blockers = array_filter($references, static fn (int $count, string $reference): bool => !in_array(explode('.', $reference)[0], self::ADMINISTRATIVE, true), ARRAY_FILTER_USE_BOTH);
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $active = $this->column('SELECT responsibility_key FROM responsibility_assignments WHERE employee_uuid = ? AND revoked_at IS NULL AND (ends_at IS NULL OR ends_at > ?)', [$employeeUuid, $now]);
        return [
            'employeeUuid' => $employeeUuid,
            'username' => (string) $employee['username'],
            'status' => (string) $employee['status'],
            'references' => $references,
            'blockers' => $blockers,
            'activeResponsibilities' => $active,
            'deletable' => $blockers === [],
        ];
    }

    /** Inactive status and revoked sessions; history untouched. @return array<string, mixed> */
    public function disable(string $employeeUuid, string $username, string $requestId): array
    {
        $plan = $this->plan($employeeUuid, $username);
        if ($plan['activeResponsibilities'] !== []) {
            throw new RuntimeException('The identity holds an active responsibility (' . implode(', ', $plan['activeResponsibilities']) . '); revoke it in Dashboard first.');
        }
        if ($plan['status'] !== 'inactive') {
            $this->admin->setStatus($employeeUuid, 'inactive', $requestId);
        } else {
            $this->admin->revokeSessions($employeeUuid, $requestId);
        }
        return $this->plan($employeeUuid, $username);
    }

    /** @return array{backup: string, sha256: string, removed: array<string, int>} */
    public function delete(string $employeeUuid, string $username, string $backupDirectory, string $requestId): array
    {
        $plan = $this->plan($employeeUuid, $username);
        if ($plan['activeResponsibilities'] !== []) {
            throw new RuntimeException('The identity holds an active responsibility (' . implode(', ', $plan['activeResponsibilities']) . '); revoke it in Dashboard first.');
        }
        if (!$plan['deletable']) {
            throw new RuntimeException('Physical deletion is blocked by history references: ' . json_encode($plan['blockers']));
        }
        $backup = $this->backup($employeeUuid, $backupDirectory);
        $removed = [];
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT username, display_name FROM employees WHERE employee_uuid = ? FOR UPDATE');
            $lock->execute([$employeeUuid]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (string) $row['username'] !== $plan['username']) {
                throw new RuntimeException('The identity changed; run the plan again.');
            }
            // Re-check under the lock: a login, session or production action since the plan blocks deletion.
            $blockers = array_filter($this->references($employeeUuid), static fn (int $count, string $reference): bool => !in_array(explode('.', $reference)[0], self::ADMINISTRATIVE, true), ARRAY_FILTER_USE_BOTH);
            if ($blockers !== []) {
                throw new RuntimeException('Physical deletion is blocked by history references: ' . json_encode($blockers));
            }
            foreach (self::ADMINISTRATIVE as $table) {
                $statement = $this->pdo->prepare("DELETE FROM {$table} WHERE employee_uuid = ?");
                $statement->execute([$employeeUuid]);
                $removed[$table] = $statement->rowCount();
            }
            $delete = $this->pdo->prepare('DELETE FROM employees WHERE employee_uuid = ?');
            $delete->execute([$employeeUuid]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('The identity was not deleted.');
            }
            $this->audit->record(null, 'employee.deleted', 'employee', $employeeUuid, "{$row['display_name']} ({$row['username']})", [
                'reason' => 'duplicate identity retired', 'statusBefore' => $plan['status'], 'removedAssociations' => $removed, 'backupSha256' => $backup['sha256'],
            ], $requestId, $this->clock->now()->format('Y-m-d H:i:s.u'));
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $backup + ['removed' => $removed];
    }

    /** @return array{backup: string, sha256: string} */
    private function backup(string $employeeUuid, string $directory): array
    {
        if ($directory === '' || (!is_dir($directory) && !mkdir($directory, 0700, true))) {
            throw new RuntimeException('The backup directory is not available.');
        }
        chmod($directory, 0700);
        $data = [
            'employeeUuid' => $employeeUuid,
            'createdAt' => $this->clock->now()->format(DATE_ATOM),
            'employee' => $this->rows('SELECT ' . self::SAFE_COLUMNS . ', e.department_id, e.role_id FROM employees e WHERE e.employee_uuid = ?', [$employeeUuid])[0] ?? null,
        ];
        foreach (self::ADMINISTRATIVE as $table) {
            $data[$table] = $this->rows("SELECT * FROM {$table} WHERE employee_uuid = ?", [$employeeUuid]);
        }
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = rtrim($directory, '/') . '/employee-' . $employeeUuid . '-' . $this->clock->now()->format('Ymd\THis') . '.json';
        $previous = umask(0077);
        try {
            if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException('The backup could not be written.');
            }
        } finally {
            umask($previous);
        }
        chmod($path, 0600);
        $hash = hash('sha256', $json);
        if (hash_file('sha256', $path) !== $hash || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('The backup could not be verified.');
        }
        return ['backup' => $path, 'sha256' => $hash];
    }

    /** @return array<string, mixed> */
    private function target(string $employeeUuid, string $username): array
    {
        $statement = $this->pdo->prepare('SELECT employee_uuid, username, username_normalized, status FROM employees WHERE employee_uuid = ?');
        $statement->execute([$employeeUuid]);
        $employee = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($employee) || (string) $employee['username_normalized'] !== mb_strtolower(trim($username))) {
            throw new RuntimeException('The UUID and the username do not belong to the same identity.');
        }
        if ($this->count('SELECT COUNT(*) FROM system_root_identity WHERE employee_uuid = ?', [$employeeUuid]) > 0) {
            throw new RuntimeException('The root identity is protected.');
        }
        return $employee;
    }

    /** Every non-zero inbound foreign-key reference to the employee, discovered from the live schema. @return array<string, int> */
    private function references(string $employeeUuid): array
    {
        $statement = $this->pdo->query(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
             WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'employees' AND REFERENCED_COLUMN_NAME = 'employee_uuid'
             ORDER BY TABLE_NAME, COLUMN_NAME",
        );
        $references = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $fk) {
            if (preg_match('/^[a-z0-9_]+$/', (string) $fk['t']) !== 1 || preg_match('/^[a-z0-9_]+$/', (string) $fk['c']) !== 1) {
                continue;
            }
            $count = $this->count("SELECT COUNT(*) FROM `{$fk['t']}` WHERE `{$fk['c']}` = ?", [$employeeUuid]);
            if ($count > 0) {
                $references["{$fk['t']}.{$fk['c']}"] = $count;
            }
        }
        return $references;
    }

    /** @param list<mixed> $params */
    private function count(string $sql, array $params): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    /** @param list<mixed> $params @return list<string> */
    private function column(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<mixed> $params @return list<array<string, mixed>> */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
