<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Document\DocumentScopePolicy;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Iam\TemporaryPasswordGenerator;
use Arasya\Operations\Security\PasswordHasher;
use Arasya\Operations\Security\UsernameNormalizer;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;
use Throwable;

/**
 * Server-authoritative identity and access management.
 *
 * Authority model:
 * - The root identity (system_root_identity) holds every permission and application. No management
 *   request may modify it, not even one made by root; its password changes only through /auth/password
 *   or the server CLI recovery command.
 * - Every other actor has an authority ceiling: the highest authority_rank among its active roles.
 *   It may manage only identities whose highest role rank is strictly below that ceiling, never itself,
 *   may assign only roles ranked strictly below its ceiling whose permissions it holds, and may put into
 *   roles only role-grantable permissions it holds. Application access is granted only for applications
 *   the actor can enter itself.
 * - Organisational hierarchy (manager, department) never grants authority.
 */
final readonly class ManagementService
{
    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private IamAuditLogger $audit,
        private PasswordHasher $passwords,
        private UsernameNormalizer $usernames,
        private Clock $clock,
        private string $apiVersion,
    ) {
    }

    // ---------------------------------------------------------------- actor & catalogue

    /** @return array<string, mixed> */
    public function me(EmployeeIdentity $actor): array
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        return [
            'employee' => ['id' => $actor->employeeUuid, 'username' => $actor->username, 'displayName' => $actor->displayName, 'positionTitle' => $actor->positionTitle, 'department' => $actor->departmentName],
            'isRoot' => $actor->isRoot,
            'authorityRank' => $actor->isRoot ? null : $actor->authorityRank,
            'permissions' => $actor->permissions,
            'grantablePermissions' => $this->grantablePermissions($actor),
            'applications' => $actor->applications,
            'authorizationVersion' => $actor->authorizationVersion,
        ];
    }

    /** @return array<string, mixed> */
    public function permissions(EmployeeIdentity $actor): array
    {
        $this->require($actor, 'roles.view');
        $grantable = $this->grantablePermissions($actor);
        $rows = $this->pdo->query('SELECT permission_key, category, label, description, role_grantable FROM permissions ORDER BY category, permission_key')->fetchAll();
        return ['items' => array_map(static fn (array $row): array => [
            'key' => (string) $row['permission_key'],
            'category' => (string) $row['category'],
            'label' => (string) ($row['label'] ?? $row['permission_key']),
            'description' => (string) $row['description'],
            'roleGrantable' => (int) $row['role_grantable'] === 1,
            'grantableByMe' => in_array((string) $row['permission_key'], $grantable, true),
        ], $rows)];
    }

    /** @return array<string, mixed> */
    public function applications(EmployeeIdentity $actor): array
    {
        $this->require($actor, 'applications.view');
        $rows = $this->pdo->query(
            "SELECT a.application_key, a.name, a.description, a.status, a.access_permission_key,
                    (SELECT COUNT(*) FROM employee_application_access eaa INNER JOIN employees e ON e.employee_uuid = eaa.employee_uuid AND e.status = 'active'
                     WHERE eaa.application_key = a.application_key) AS user_count
             FROM applications a ORDER BY a.sort_order, a.application_key",
        )->fetchAll();
        return ['items' => array_map(static fn (array $row): array => [
            'key' => (string) $row['application_key'],
            'name' => (string) $row['name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'status' => (string) $row['status'],
            'accessPermission' => (string) $row['access_permission_key'],
            'userCount' => (int) $row['user_count'],
        ], $rows)];
    }

    /** @return array<string, mixed> */
    public function overview(EmployeeIdentity $actor): array
    {
        $this->require($actor, 'dashboard.overview.view');
        $count = fn (string $sql): int => (int) $this->pdo->query($sql)->fetchColumn();
        // Company-wide counts are organisation analytics: only identities that may view employees get them.
        // Narrow roles (for example the operations manager) receive null.
        $result = [
            'counts' => !$this->authorization->can($actor, 'employees.view') ? null : [
                'activeEmployees' => $count("SELECT COUNT(*) FROM employees WHERE status = 'active'"),
                'dashboardUsers' => $count("SELECT COUNT(*) FROM employee_application_access eaa INNER JOIN employees e ON e.employee_uuid = eaa.employee_uuid AND e.status = 'active' WHERE eaa.application_key = 'dashboard'"),
                'staffUsers' => $count("SELECT COUNT(*) FROM employee_application_access eaa INNER JOIN employees e ON e.employee_uuid = eaa.employee_uuid AND e.status = 'active' WHERE eaa.application_key = 'staff'"),
                'departments' => $count("SELECT COUNT(*) FROM departments WHERE status = 'active'"),
                'roles' => $count("SELECT COUNT(*) FROM roles WHERE status = 'active'"),
            ],
            'applications' => $this->pdo->query("SELECT application_key AS `key`, name, status FROM applications ORDER BY sort_order")->fetchAll(),
            'recentAudit' => null,
        ];
        if ($this->authorization->can($actor, 'iam.audit.view')) {
            $result['recentAudit'] = $this->audit($actor, ['limit' => '8'])['items'];
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function system(EmployeeIdentity $actor): array
    {
        $this->require($actor, 'system.view');
        $migrations = $this->pdo->query('SELECT migration_name FROM schema_migrations ORDER BY migration_name')->fetchAll(PDO::FETCH_COLUMN);
        return [
            'apiVersion' => $this->apiVersion,
            'database' => 'ok',
            'migrations' => ['applied' => count($migrations), 'latest' => $migrations === [] ? null : (string) end($migrations)],
            'applications' => (int) $this->pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'active'")->fetchColumn(),
            'rootConfigured' => (int) $this->pdo->query('SELECT COUNT(*) FROM system_root_identity')->fetchColumn() === 1,
        ];
    }

    // ---------------------------------------------------------------- employees

    /** @param array<string, string> $filters @return array<string, mixed> */
    public function listEmployees(EmployeeIdentity $actor, array $filters): array
    {
        $this->require($actor, 'employees.view');
        $where = ['1 = 1'];
        $params = [];
        if (($filters['search'] ?? '') !== '') {
            $search = mb_substr(trim($filters['search']), 0, 80);
            $where[] = '(e.display_name LIKE :search_name OR e.username LIKE :search_user OR e.position_title LIKE :search_title)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $params += ['search_name' => $like, 'search_user' => $like, 'search_title' => $like];
        }
        if (in_array($filters['status'] ?? '', ['active', 'inactive', 'suspended'], true)) {
            $where[] = 'e.status = :status';
            $params['status'] = $filters['status'];
        }
        if (ctype_digit($filters['departmentId'] ?? '')) {
            $where[] = 'e.department_id = :department_id';
            $params['department_id'] = (int) $filters['departmentId'];
        }
        if (preg_match('/^[a-z][a-z0-9-]{1,39}$/D', $filters['application'] ?? '') === 1) {
            $where[] = '(EXISTS (SELECT 1 FROM employee_application_access f WHERE f.employee_uuid = e.employee_uuid AND f.application_key = :application) OR EXISTS (SELECT 1 FROM system_root_identity fr WHERE fr.employee_uuid = e.employee_uuid))';
            $params['application'] = $filters['application'];
        }
        if (ctype_digit($filters['roleId'] ?? '')) {
            $where[] = 'EXISTS (SELECT 1 FROM employee_role_assignments fra WHERE fra.employee_uuid = e.employee_uuid AND fra.role_id = :role_id)';
            $params['role_id'] = (int) $filters['roleId'];
        }
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
        $offset = ctype_digit($filters['cursor'] ?? '') ? (int) $filters['cursor'] : 0;
        $statement = $this->pdo->prepare(
            'SELECT e.employee_uuid FROM employees e WHERE ' . implode(' AND ', $where)
            . ' ORDER BY (SELECT COUNT(*) FROM system_root_identity o WHERE o.employee_uuid = e.employee_uuid) DESC, e.display_name, e.employee_uuid'
            . ' LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
        );
        $statement->execute($params);
        $ids = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $next = count($ids) > $limit ? (string) ($offset + $limit) : null;
        $ids = array_slice($ids, 0, $limit);
        $totalStatement = $this->pdo->prepare('SELECT COUNT(*) FROM employees e WHERE ' . implode(' AND ', $where));
        $totalStatement->execute($params);
        return ['items' => $this->summaries($actor, $ids), 'nextCursor' => $next, 'total' => (int) $totalStatement->fetchColumn()];
    }

    /** @return array<string, mixed> */
    public function getEmployee(EmployeeIdentity $actor, string $employeeId): array
    {
        $this->require($actor, 'employees.view');
        $summary = $this->summaries($actor, [$this->validUuid($employeeId)])[0] ?? throw new ApiException(404, 'EMPLOYEE_NOT_FOUND', 'Employee was not found.');
        $permissions = $this->pdo->prepare(
            "SELECT DISTINCT p.permission_key FROM employee_role_assignments era
             INNER JOIN roles r ON r.role_id = era.role_id AND r.status = 'active'
             INNER JOIN role_permissions rp ON rp.role_id = r.role_id
             INNER JOIN permissions p ON p.permission_id = rp.permission_id AND p.role_grantable = 1
             WHERE era.employee_uuid = :id ORDER BY p.permission_key",
        );
        $permissions->execute(['id' => $summary['id']]);
        $summary['rolePermissions'] = $summary['isRoot'] ? ['*'] : array_map('strval', $permissions->fetchAll(PDO::FETCH_COLUMN));
        $secondary = $this->pdo->prepare('SELECT d.department_id, d.name FROM employee_secondary_departments esd INNER JOIN departments d ON d.department_id = esd.department_id WHERE esd.employee_uuid = :id ORDER BY d.name');
        $secondary->execute(['id' => $summary['id']]);
        $summary['secondaryDepartments'] = array_map(static fn (array $row): array => ['id' => (int) $row['department_id'], 'name' => (string) $row['name']], $secondary->fetchAll());
        // Root reaches every source; a stored scope would be meaningless for it.
        $summary['documentScopes'] = $summary['isRoot'] ? null : (new DocumentScopePolicy($this->pdo))->scopesOf((string) $summary['id']);
        // Granted stages narrowed to named sources (migration 023); a stage absent here reaches every source.
        $summary['stageSourceScopes'] = $summary['isRoot'] ? null : (object) $this->stageScopesOf((string) $summary['id']);
        // The grantable sources, for the root-only scope editor; nobody else can change a scope.
        $summary['documentScopeSources'] = $actor->isRoot && !$summary['isRoot'] ? array_map(
            static fn (array $row): array => ['key' => (string) $row['source_key'], 'name' => (string) $row['display_name']],
            $this->pdo->query("SELECT source_key, display_name FROM order_sources WHERE status = 'active' ORDER BY display_name, source_key")->fetchAll(PDO::FETCH_ASSOC),
        ) : null;
        return $summary;
    }

    /**
     * Order sources an identity's production document permissions reach (migration 021). Root only: a
     * scope widens what a document permission can touch, and only root may decide which sales channel's
     * documents a person operates on or approves. A scope grants no permission by itself, and a
     * permission without a scope reaches nothing.
     *
     * @param array<string, mixed> $input {operate: list<string>, approve: list<string>}
     * @return array<string, mixed>
     */
    public function setDocumentScopes(EmployeeIdentity $actor, string $employeeId, array $input, string $requestId): array
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        if (!$actor->isRoot) {
            throw new ApiException(403, 'ROOT_ONLY', 'Only the principal administrator can change document scopes.');
        }
        $wanted = [];
        foreach (DocumentScopePolicy::CAPABILITIES as $capability) {
            $sources = $this->stringList($input[$capability] ?? null, $capability);
            sort($sources);
            $wanted[$capability] = $sources;
        }
        $this->mutateEmployee($actor, $employeeId, function (array $target, string $now) use ($actor, $wanted, $requestId): void {
            $known = $this->pdo->query("SELECT source_key FROM order_sources WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($wanted as $sources) {
                if (array_diff($sources, $known) !== []) {
                    throw new ApiException(400, 'UNKNOWN_SOURCE', 'A document scope names an unknown or inactive order source.');
                }
            }
            $before = (new DocumentScopePolicy($this->pdo))->scopesOf($target['employee_uuid']);
            if ($before === $wanted) {
                return;
            }
            $this->pdo->prepare('DELETE FROM employee_document_scopes WHERE employee_uuid = :id')->execute(['id' => $target['employee_uuid']]);
            $insert = $this->pdo->prepare('INSERT INTO employee_document_scopes (employee_uuid, capability, source_key, granted_at, granted_by_employee_uuid) VALUES (:id, :capability, :source, :now, :actor)');
            foreach ($wanted as $capability => $sources) {
                foreach ($sources as $source) {
                    $insert->execute(['id' => $target['employee_uuid'], 'capability' => $capability, 'source' => $source, 'now' => $now, 'actor' => $actor->employeeUuid]);
                }
            }
            $this->bumpAuthorization($target['employee_uuid'], $now);
            $this->audit->record($actor, 'employee.document_scopes_changed', 'employee', $target['employee_uuid'], $this->label($target), ['before' => $before, 'after' => $wanted], $requestId, $now);
        });
        return $this->getEmployee($actor, $employeeId);
    }

    /**
     * Source scopes of the target's granted stages (migration 023). Root only, like document scopes: a scope narrows
     * a stage grant to named order sources, and removing a scope widens it back to every source, which is a grant.
     *
     * `$scopes` maps granted stage ids to non-empty source lists and replaces every scope of the employee; a stage
     * omitted from it is unscoped. A scope never grants a stage: the stage must already be granted. An empty list
     * is refused, because "no source" is expressed by removing the stage grant itself.
     *
     * @return array<string, mixed>
     */
    public function setStageScopes(EmployeeIdentity $actor, string $employeeId, mixed $scopes, string $requestId): array
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        if (!$actor->isRoot) {
            throw new ApiException(403, 'ROOT_ONLY', 'Only the principal administrator can change stage source scopes.');
        }
        if (!is_array($scopes) || ($scopes !== [] && array_is_list($scopes))) {
            throw new ApiException(400, 'VALIDATION_FAILED', 'scopes must be an object of stage ids to source lists.');
        }
        $wanted = [];
        foreach ($scopes as $stageId => $sources) {
            $list = $this->stringList($sources, 'scopes');
            if ($list === []) {
                throw new ApiException(400, 'VALIDATION_FAILED', 'A stage scope needs at least one source; remove the stage grant to revoke the stage.');
            }
            sort($list);
            $wanted[(string) $stageId] = array_values(array_unique($list));
        }
        ksort($wanted);
        $this->mutateEmployee($actor, $employeeId, function (array $target, string $now) use ($actor, $wanted, $requestId): void {
            $granted = $this->column('SELECT stage_id FROM employee_stage_access WHERE employee_uuid = :id ORDER BY stage_id', $target['employee_uuid']);
            if (array_diff(array_keys($wanted), $granted) !== []) {
                throw new ApiException(400, 'STAGE_NOT_GRANTED', 'A stage scope names a stage the employee does not hold.');
            }
            $known = $this->pdo->query("SELECT source_key FROM order_sources WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($wanted as $sources) {
                if (array_diff($sources, $known) !== []) {
                    throw new ApiException(400, 'UNKNOWN_SOURCE', 'A stage scope names an unknown or inactive order source.');
                }
            }
            $before = $this->stageScopesOf($target['employee_uuid']);
            if ($before === $wanted) {
                return;
            }
            $this->pdo->prepare('DELETE FROM employee_stage_source_scopes WHERE employee_uuid = :id')->execute(['id' => $target['employee_uuid']]);
            $insert = $this->pdo->prepare('INSERT INTO employee_stage_source_scopes (employee_uuid, stage_id, source_key, granted_at, granted_by_employee_uuid) VALUES (:id, :stage, :source, :now, :actor)');
            foreach ($wanted as $stageId => $sources) {
                foreach ($sources as $source) {
                    $insert->execute(['id' => $target['employee_uuid'], 'stage' => $stageId, 'source' => $source, 'now' => $now, 'actor' => $actor->employeeUuid]);
                }
            }
            $this->bumpAuthorization($target['employee_uuid'], $now);
            $this->audit->record($actor, 'employee.stage_scopes_changed', 'employee', $target['employee_uuid'], $this->label($target), ['before' => (object) $before, 'after' => (object) $wanted], $requestId, $now);
        });
        return $this->getEmployee($actor, $employeeId);
    }

    /** @return array<string, list<string>> stage id => sorted sources, stages sorted */
    private function stageScopesOf(string $employeeUuid): array
    {
        $statement = $this->pdo->prepare('SELECT stage_id, source_key FROM employee_stage_source_scopes WHERE employee_uuid = :id ORDER BY stage_id, source_key');
        $statement->execute(['id' => $employeeUuid]);
        $scopes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $scopes[(string) $row['stage_id']][] = (string) $row['source_key'];
        }
        return $scopes;
    }

    /**
     * Additional organisational functions of one identity (for example Depozit + Montaj). Membership
     * only: no permission, application or stage follows from it. Same authority rules as a profile edit.
     *
     * @return array<string, mixed>
     */
    public function setSecondaryDepartments(EmployeeIdentity $actor, string $employeeId, mixed $departmentIds, string $requestId): array
    {
        $this->require($actor, 'employees.update');
        $ids = $this->intList($departmentIds, 'departmentIds');
        if (count($ids) > 10) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Too many departments.');
        }
        $this->mutateEmployee($actor, $employeeId, function (array $target, string $now) use ($actor, $ids, $requestId): void {
            foreach ($ids as $id) {
                if ($this->activeDepartmentId($id) === (int) $target['department_id']) {
                    throw new ApiException(400, 'INVALID_REQUEST', 'The primary department is not an additional function.');
                }
            }
            $before = $this->column('SELECT department_id FROM employee_secondary_departments WHERE employee_uuid = :id ORDER BY department_id', $target['employee_uuid']);
            $this->pdo->prepare('DELETE FROM employee_secondary_departments WHERE employee_uuid = :id')->execute(['id' => $target['employee_uuid']]);
            $insert = $this->pdo->prepare('INSERT INTO employee_secondary_departments (employee_uuid, department_id, created_at) VALUES (:id, :department, :now)');
            foreach ($ids as $id) {
                $insert->execute(['id' => $target['employee_uuid'], 'department' => $id, 'now' => $now]);
            }
            sort($ids);
            $this->audit->record($actor, 'employee.secondary_departments_changed', 'employee', $target['employee_uuid'], $this->label($target), ['departmentIds' => ['before' => array_map('intval', $before), 'after' => $ids]], $requestId, $now);
        });
        return $this->getEmployee($actor, $employeeId);
    }

    /** @param array<string, mixed> $input @return array{employee: array<string, mixed>, temporaryPassword: string} */
    public function createEmployee(EmployeeIdentity $actor, array $input, string $requestId): array
    {
        $this->require($actor, 'employees.create');
        $displayName = $this->text($input['displayName'] ?? null, 2, 160, 'displayName');
        $username = trim($this->text($input['username'] ?? null, 3, 120, 'username'));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,119}$/D', $username) !== 1) {
            throw new ApiException(400, 'INVALID_USERNAME', 'Username may contain letters, digits, dot, dash and underscore.');
        }
        $normalized = $this->usernames->normalize($username);
        $positionTitle = $this->optionalText($input['positionTitle'] ?? null, 120, 'positionTitle');
        $status = $input['status'] ?? 'active';
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Status must be active or inactive.');
        }
        $applications = $this->stringList($input['applications'] ?? [], 'applications');
        $roleIds = $this->intList($input['roleIds'] ?? [], 'roleIds');
        $stageIds = $this->stringList($input['stageIds'] ?? [], 'stageIds');
        $managerId = $input['managerId'] ?? null;
        if ($applications !== []) {
            $this->require($actor, 'employees.manage_applications');
        }
        if ($roleIds !== []) {
            $this->require($actor, 'employees.manage_roles');
            $this->require($actor, 'roles.assign');
        }
        if ($stageIds !== []) {
            $this->require($actor, 'employees.manage_stages');
        }
        if ($managerId !== null) {
            $this->require($actor, 'employees.manage_hierarchy');
        }

        $password = TemporaryPasswordGenerator::generate(20);
        $uuid = Uuid::v4();
        $this->transaction(function () use ($actor, $uuid, $username, $normalized, $displayName, $positionTitle, $status, $input, $applications, $roleIds, $stageIds, $managerId, $password, $requestId): void {
            $exists = $this->pdo->prepare('SELECT COUNT(*) FROM employees WHERE username_normalized = :username');
            $exists->execute(['username' => $normalized]);
            if ((int) $exists->fetchColumn() !== 0) {
                throw new ApiException(409, 'USERNAME_TAKEN', 'The username is already in use.');
            }
            $departmentId = $this->activeDepartmentId($input['departmentId'] ?? null);
            $roles = $this->assignableRoles($actor, $roleIds);
            $this->assertApplicationsGrantable($actor, [], $applications);
            $stages = $this->canonicalStages($stageIds);
            $manager = $managerId === null ? null : $this->validUuid(is_string($managerId) ? $managerId : '');
            if ($manager !== null) {
                $this->lockEmployee($manager, 'MANAGER_NOT_FOUND');
            }
            $now = $this->now();
            $legacyRoleId = (int) $this->pdo->query("SELECT role_id FROM roles WHERE role_key = 'employee'")->fetchColumn();
            $this->pdo->prepare(
                'INSERT INTO employees (employee_uuid, employee_code, username, username_normalized, password_hash, display_name, position_title, manager_employee_uuid, department_id, role_id, status, created_at, updated_at, password_changed_at, must_change_password, authorization_version)
                 VALUES (:uuid, NULL, :username, :normalized, :hash, :display_name, :position_title, :manager, :department_id, :role_id, :status, :created_at, :updated_at, :changed_at, 1, 1)',
            )->execute([
                'uuid' => $uuid, 'username' => $username, 'normalized' => $normalized, 'hash' => $this->passwords->hash($password),
                'display_name' => $displayName, 'position_title' => $positionTitle, 'manager' => $manager, 'department_id' => $departmentId,
                'role_id' => $legacyRoleId, 'status' => $status, 'created_at' => $now, 'updated_at' => $now, 'changed_at' => $now,
            ]);
            $this->writeApplications($uuid, $applications, $now);
            $this->writeRoles($uuid, array_keys($roles), $now);
            $this->writeStages($uuid, $stages, $now);
            $this->audit->record($actor, 'employee.created', 'employee', $uuid, "{$displayName} ({$username})", [
                'applications' => $applications,
                'roles' => array_values(array_map(static fn (array $role): string => $role['role_key'], $roles)),
                'stages' => $stages,
                'departmentId' => $departmentId,
                'status' => $status,
            ], $requestId, $now);
        });
        return ['employee' => $this->getEmployee($actor, $uuid), 'temporaryPassword' => $password];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function updateEmployee(EmployeeIdentity $actor, string $employeeId, array $input, string $requestId): array
    {
        $this->require($actor, 'employees.update');
        if ($input === []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Nothing to update.');
        }
        $this->mutateEmployee($actor, $employeeId, function (array $target, string $now) use ($input, $actor, $requestId): void {
            $changes = [];
            $params = ['uuid' => $target['employee_uuid'], 'now' => $now];
            if (array_key_exists('displayName', $input)) {
                $params['display_name'] = $this->text($input['displayName'], 2, 160, 'displayName');
                $changes['display_name = :display_name'] = ['displayName', $target['display_name'], $params['display_name']];
            }
            if (array_key_exists('positionTitle', $input)) {
                $params['position_title'] = $this->optionalText($input['positionTitle'], 120, 'positionTitle');
                $changes['position_title = :position_title'] = ['positionTitle', $target['position_title'], $params['position_title']];
            }
            if (array_key_exists('departmentId', $input)) {
                $params['department_id'] = $this->activeDepartmentId($input['departmentId']);
                $changes['department_id = :department_id'] = ['departmentId', (int) $target['department_id'], $params['department_id']];
            }
            $this->pdo->prepare('UPDATE employees SET ' . implode(', ', array_keys($changes)) . ', updated_at = :now WHERE employee_uuid = :uuid')->execute($params);
            $metadata = [];
            foreach ($changes as [$field, $before, $after]) {
                $metadata[$field] = ['before' => $before, 'after' => $after];
            }
            $this->audit->record($actor, 'employee.updated', 'employee', $target['employee_uuid'], $this->label($target), $metadata, $requestId, $now);
        });
        return $this->getEmployee($actor, $employeeId);
    }

    /** @return array<string, mixed> */
    public function setStatus(EmployeeIdentity $actor, string $employeeId, string $status, string $requestId): array
    {
        $this->require($actor, $status === 'active' ? 'employees.activate' : 'employees.deactivate');
        $this->mutateEmployee($actor, $employeeId, function (array $target, string $now) use ($actor, $status, $requestId): void {
            $this->pdo->prepare('UPDATE employees SET status = :status, authorization_version = authorization_version + 1, updated_at = :now WHERE employee_uuid = :uuid')
                ->execute(['status' => $status, 'now' => $now, 'uuid' => $target['employee_uuid']]);
            $revoked = 0;
            if ($status !== 'active') {
                $revoked = $this->revokeSessions($target['employee_uuid'], $now);
            }
            $this->audit->record($actor, $status === 'active' ? 'employee.activated' : 'employee.deactivated', 'employee', $target['employee_uuid'], $this->label($target), ['before' => $target['status'], 'after' => $status, 'revokedSessions' => $revoked], $requestId, $now);
        });
        return $this->getEmployee($actor, $employeeId);
    }

    /** @return array{temporaryPassword: string} */
    public function resetPassword(EmployeeIdentity $actor, string $employeeId, string $requestId): array
    {
        $this->require($actor, 'employees.reset_password');
        $password = TemporaryPasswordGenerator::generate(20);
        $this->mutateEmployee($actor, $employeeId, function (array $target, string $now) use ($actor, $password, $requestId): void {
            $this->pdo->prepare(
                'UPDATE employees SET password_hash = :hash, password_changed_at = :now, must_change_password = 1,
                        authorization_version = authorization_version + 1, updated_at = :updated WHERE employee_uuid = :uuid',
            )->execute(['hash' => $this->passwords->hash($password), 'now' => $now, 'updated' => $now, 'uuid' => $target['employee_uuid']]);
            $revoked = $this->revokeSessions($target['employee_uuid'], $now);
            $this->audit->record($actor, 'employee.password_reset', 'employee', $target['employee_uuid'], $this->label($target), ['revokedSessions' => $revoked], $requestId, $now);
        });
        return ['temporaryPassword' => $password];
    }

    /** @param list<mixed> $applications @return array<string, mixed> */
    public function setApplications(EmployeeIdentity $actor, string $employeeId, mixed $applications, string $requestId): array
    {
        $this->require($actor, 'employees.manage_applications');
        $keys = $this->stringList($applications, 'applications');
        $this->mutateEmployee($actor, $employeeId, function (array $target, string $now) use ($actor, $keys, $requestId): void {
            $before = $this->column('SELECT application_key FROM employee_application_access WHERE employee_uuid = :id ORDER BY application_key', $target['employee_uuid']);
            $this->assertApplicationsGrantable($actor, $before, $keys);
            $this->pdo->prepare('DELETE FROM employee_application_access WHERE employee_uuid = :id')->execute(['id' => $target['employee_uuid']]);
            $this->writeApplications($target['employee_uuid'], $keys, $now);
            $this->bumpAuthorization($target['employee_uuid'], $now);
            $after = $keys;
            sort($after);
            $this->audit->record($actor, 'employee.applications_changed', 'employee', $target['employee_uuid'], $this->label($target), ['before' => $before, 'after' => $after], $requestId, $now);
        });
        return $this->getEmployee($actor, $employeeId);
    }

    /** @return array<string, mixed> */
    public function setRoles(EmployeeIdentity $actor, string $employeeId, mixed $roleIds, string $requestId): array
    {
        $this->require($actor, 'employees.manage_roles');
        $this->require($actor, 'roles.assign');
        $ids = $this->intList($roleIds, 'roleIds');
        $this->mutateEmployee($actor, $employeeId, function (array $target, string $now) use ($actor, $ids, $requestId): void {
            $roles = $this->assignableRoles($actor, $ids);
            $before = $this->column('SELECT r.role_key FROM employee_role_assignments era INNER JOIN roles r ON r.role_id = era.role_id WHERE era.employee_uuid = :id ORDER BY r.role_key', $target['employee_uuid']);
            $this->pdo->prepare('DELETE FROM employee_role_assignments WHERE employee_uuid = :id')->execute(['id' => $target['employee_uuid']]);
            $this->writeRoles($target['employee_uuid'], array_keys($roles), $now);
            $this->bumpAuthorization($target['employee_uuid'], $now);
            $after = array_values(array_map(static fn (array $role): string => $role['role_key'], $roles));
            sort($after);
            $this->audit->record($actor, 'employee.roles_changed', 'employee', $target['employee_uuid'], $this->label($target), ['before' => $before, 'after' => $after], $requestId, $now);
        });
        return $this->getEmployee($actor, $employeeId);
    }

    /** @return array<string, mixed> */
    public function setStages(EmployeeIdentity $actor, string $employeeId, mixed $stageIds, string $requestId): array
    {
        $this->require($actor, 'employees.manage_stages');
        $ids = $this->stringList($stageIds, 'stageIds');
        $this->mutateEmployee($actor, $employeeId, function (array $target, string $now) use ($actor, $ids, $requestId): void {
            $stages = $this->canonicalStages($ids);
            $before = $this->column('SELECT stage_id FROM employee_stage_access WHERE employee_uuid = :id ORDER BY stage_id', $target['employee_uuid']);
            $scopesBefore = $this->stageScopesOf($target['employee_uuid']);
            $this->pdo->prepare('DELETE FROM employee_stage_access WHERE employee_uuid = :id')->execute(['id' => $target['employee_uuid']]);
            $this->writeStages($target['employee_uuid'], $stages, $now);
            // A removed stage takes its source scope with it; a kept stage keeps its scope unchanged. Re-adding a
            // stage later grants it unscoped (every source) until root narrows it again.
            $removedScopes = array_values(array_diff(array_keys($scopesBefore), $stages));
            if ($removedScopes !== []) {
                $this->pdo->prepare('DELETE FROM employee_stage_source_scopes WHERE employee_uuid = ? AND stage_id IN (' . implode(',', array_fill(0, count($removedScopes), '?')) . ')')
                    ->execute([$target['employee_uuid'], ...$removedScopes]);
            }
            $this->bumpAuthorization($target['employee_uuid'], $now);
            $this->audit->record($actor, 'employee.stages_changed', 'employee', $target['employee_uuid'], $this->label($target), ['before' => $before, 'after' => $stages] + ($removedScopes === [] ? [] : ['stageScopesRemoved' => array_intersect_key($scopesBefore, array_flip($removedScopes))]), $requestId, $now);
        });
        return $this->getEmployee($actor, $employeeId);
    }

    /** @return array<string, mixed> */
    public function setManager(EmployeeIdentity $actor, string $employeeId, mixed $managerId, string $requestId): array
    {
        $this->require($actor, 'employees.manage_hierarchy');
        $manager = $managerId === null ? null : $this->validUuid(is_string($managerId) ? $managerId : '');
        $this->mutateEmployee($actor, $employeeId, function (array $target, string $now) use ($actor, $manager, $requestId): void {
            if ($manager !== null) {
                $this->lockEmployee($manager, 'MANAGER_NOT_FOUND');
                // Walk up from the new manager; reaching the target would close a reporting cycle.
                $cursor = $manager;
                for ($depth = 0; $cursor !== null && $depth < 64; $depth++) {
                    if ($cursor === $target['employee_uuid']) {
                        throw new ApiException(409, 'MANAGER_CYCLE', 'The reporting line would form a cycle.');
                    }
                    $next = $this->pdo->prepare('SELECT manager_employee_uuid FROM employees WHERE employee_uuid = :id');
                    $next->execute(['id' => $cursor]);
                    $value = $next->fetchColumn();
                    $cursor = $value === false || $value === null ? null : (string) $value;
                }
            }
            $this->pdo->prepare('UPDATE employees SET manager_employee_uuid = :manager, updated_at = :now WHERE employee_uuid = :uuid')
                ->execute(['manager' => $manager, 'now' => $now, 'uuid' => $target['employee_uuid']]);
            $this->audit->record($actor, 'employee.manager_changed', 'employee', $target['employee_uuid'], $this->label($target), ['before' => $target['manager_employee_uuid'], 'after' => $manager], $requestId, $now);
        });
        return $this->getEmployee($actor, $employeeId);
    }

    // ---------------------------------------------------------------- roles

    /** @return array<string, mixed> */
    public function listRoles(EmployeeIdentity $actor): array
    {
        $this->require($actor, 'roles.view');
        $rows = $this->pdo->query(
            "SELECT r.role_id, r.role_key, r.name, r.description, r.authority_rank, r.is_template, r.status,
                    (SELECT COUNT(*) FROM employee_role_assignments era INNER JOIN employees e ON e.employee_uuid = era.employee_uuid WHERE era.role_id = r.role_id) AS user_count
             FROM roles r ORDER BY r.authority_rank DESC, r.name",
        )->fetchAll();
        $permissions = $this->rolePermissionMap();
        return ['items' => array_map(fn (array $row): array => $this->roleView($actor, $row, $permissions[(int) $row['role_id']] ?? []), $rows)];
    }

    /** @return array<string, mixed> */
    public function getRole(EmployeeIdentity $actor, int $roleId): array
    {
        $this->require($actor, 'roles.view');
        foreach ($this->listRoles($actor)['items'] as $role) {
            if ($role['id'] === $roleId) {
                return $role;
            }
        }
        throw new ApiException(404, 'ROLE_NOT_FOUND', 'Role was not found.');
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createRole(EmployeeIdentity $actor, array $input, string $requestId): array
    {
        $this->require($actor, 'roles.create');
        $name = $this->text($input['name'] ?? null, 2, 120, 'name');
        $description = $this->optionalText($input['description'] ?? null, 255, 'description');
        $rank = $this->rank($input['authorityRank'] ?? null);
        $permissions = $this->stringList($input['permissions'] ?? [], 'permissions');
        $roleId = 0;
        $this->transaction(function () use ($actor, $name, $description, $rank, $permissions, $requestId, &$roleId): void {
            $this->assertRankBelowCeiling($actor, $rank);
            $permissionIds = $this->grantablePermissionIds($actor, $permissions);
            $now = $this->now();
            $key = $this->uniqueKey('roles', 'role_key', $name);
            $this->pdo->prepare(
                "INSERT INTO roles (role_key, name, description, authority_rank, is_template, status, created_at, updated_at)
                 VALUES (:key, :name, :description, :rank, 0, 'active', :created_at, :updated_at)",
            )->execute(['key' => $key, 'name' => $name, 'description' => $description, 'rank' => $rank, 'created_at' => $now, 'updated_at' => $now]);
            $roleId = (int) $this->pdo->lastInsertId();
            $this->writeRolePermissions($roleId, $permissionIds, $now);
            $sorted = $permissions;
            sort($sorted);
            $this->audit->record($actor, 'role.created', 'role', (string) $roleId, $name, ['authorityRank' => $rank, 'permissions' => $sorted], $requestId, $now);
        });
        return ['role' => $this->getRole($actor, $roleId)];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function updateRole(EmployeeIdentity $actor, int $roleId, array $input, string $requestId): array
    {
        $this->require($actor, 'roles.update');
        if ($input === []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Nothing to update.');
        }
        $this->transaction(function () use ($actor, $roleId, $input, $requestId): void {
            $role = $this->lockRole($roleId);
            $this->assertRankBelowCeiling($actor, (int) $role['authority_rank']);
            $now = $this->now();
            $metadata = [];
            $sets = [];
            $params = ['id' => $roleId, 'now' => $now];
            if (array_key_exists('name', $input)) {
                $params['name'] = $this->text($input['name'], 2, 120, 'name');
                $sets[] = 'name = :name';
                $metadata['name'] = ['before' => $role['name'], 'after' => $params['name']];
            }
            if (array_key_exists('description', $input)) {
                $params['description'] = $this->optionalText($input['description'], 255, 'description');
                $sets[] = 'description = :description';
            }
            if (array_key_exists('authorityRank', $input)) {
                $params['rank'] = $this->rank($input['authorityRank']);
                $this->assertRankBelowCeiling($actor, $params['rank']);
                $sets[] = 'authority_rank = :rank';
                $metadata['authorityRank'] = ['before' => (int) $role['authority_rank'], 'after' => $params['rank']];
            }
            if (array_key_exists('status', $input)) {
                if (!in_array($input['status'], ['active', 'inactive'], true)) {
                    throw new ApiException(400, 'INVALID_REQUEST', 'Status must be active or inactive.');
                }
                $params['status'] = $input['status'];
                $sets[] = 'status = :status';
                $metadata['status'] = ['before' => $role['status'], 'after' => $input['status']];
            }
            if (array_key_exists('permissions', $input)) {
                $permissions = $this->stringList($input['permissions'], 'permissions');
                $permissionIds = $this->grantablePermissionIds($actor, $permissions);
                $before = $this->column('SELECT p.permission_key FROM role_permissions rp INNER JOIN permissions p ON p.permission_id = rp.permission_id WHERE rp.role_id = :id ORDER BY p.permission_key', (string) $roleId);
                // Permissions the actor cannot grant are kept untouched instead of being silently dropped.
                if (!$actor->isRoot) {
                    $foreign = array_diff($before, $this->grantablePermissions($actor));
                    if ($foreign !== []) {
                        throw new ApiException(403, 'AUTHORITY_EXCEEDED', 'The role contains permissions outside your authority.');
                    }
                }
                $this->pdo->prepare('DELETE FROM role_permissions WHERE role_id = :id')->execute(['id' => $roleId]);
                $this->writeRolePermissions($roleId, $permissionIds, $now);
                $after = $permissions;
                sort($after);
                $metadata['permissions'] = ['before' => $before, 'after' => $after];
            }
            if ($sets !== []) {
                $this->pdo->prepare('UPDATE roles SET ' . implode(', ', $sets) . ', updated_at = :now WHERE role_id = :id')->execute($params);
            }
            $this->pdo->prepare(
                'UPDATE employees e INNER JOIN employee_role_assignments era ON era.employee_uuid = e.employee_uuid AND era.role_id = :id
                 SET e.authorization_version = e.authorization_version + 1',
            )->execute(['id' => $roleId]);
            $this->audit->record($actor, 'role.updated', 'role', (string) $roleId, (string) ($params['name'] ?? $role['name']), $metadata, $requestId, $now);
        });
        return ['role' => $this->getRole($actor, $roleId)];
    }

    public function deleteRole(EmployeeIdentity $actor, int $roleId, string $requestId): void
    {
        $this->require($actor, 'roles.delete');
        $this->transaction(function () use ($actor, $roleId, $requestId): void {
            $role = $this->lockRole($roleId);
            $this->assertRankBelowCeiling($actor, (int) $role['authority_rank']);
            $used = $this->pdo->prepare('SELECT (SELECT COUNT(*) FROM employee_role_assignments WHERE role_id = :a) + (SELECT COUNT(*) FROM employees WHERE role_id = :b)');
            $used->execute(['a' => $roleId, 'b' => $roleId]);
            if ((int) $used->fetchColumn() > 0) {
                throw new ApiException(409, 'ROLE_IN_USE', 'The role is still assigned.');
            }
            $now = $this->now();
            $this->pdo->prepare('DELETE FROM role_permissions WHERE role_id = :id')->execute(['id' => $roleId]);
            $this->pdo->prepare('DELETE FROM roles WHERE role_id = :id')->execute(['id' => $roleId]);
            $this->audit->record($actor, 'role.deleted', 'role', (string) $roleId, (string) $role['name'], ['authorityRank' => (int) $role['authority_rank']], $requestId, $now);
        });
    }

    // ---------------------------------------------------------------- departments

    /** @return array<string, mixed> */
    public function listDepartments(EmployeeIdentity $actor): array
    {
        $this->require($actor, 'departments.view');
        $rows = $this->pdo->query(
            "SELECT d.department_id, d.department_key, d.name, d.description, d.status, d.parent_department_id,
                    (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.department_id) AS employee_count,
                    (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.department_id AND e.status = 'active') AS active_count
             FROM departments d ORDER BY d.name",
        )->fetchAll();
        return ['items' => array_map(static fn (array $row): array => [
            'id' => (int) $row['department_id'],
            'key' => (string) $row['department_key'],
            'name' => (string) $row['name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'status' => (string) $row['status'],
            'parentId' => $row['parent_department_id'] === null ? null : (int) $row['parent_department_id'],
            'employeeCount' => (int) $row['employee_count'],
            'activeEmployeeCount' => (int) $row['active_count'],
        ], $rows)];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createDepartment(EmployeeIdentity $actor, array $input, string $requestId): array
    {
        $this->require($actor, 'departments.create');
        $name = $this->text($input['name'] ?? null, 2, 120, 'name');
        $description = $this->optionalText($input['description'] ?? null, 255, 'description');
        $id = 0;
        $this->transaction(function () use ($actor, $name, $description, $input, $requestId, &$id): void {
            $parent = ($input['parentId'] ?? null) === null ? null : $this->departmentRow($input['parentId'])['department_id'];
            $now = $this->now();
            $this->pdo->prepare(
                "INSERT INTO departments (department_key, name, description, parent_department_id, status, created_at, updated_at)
                 VALUES (:key, :name, :description, :parent, 'active', :created_at, :updated_at)",
            )->execute(['key' => $this->uniqueKey('departments', 'department_key', $name), 'name' => $name, 'description' => $description, 'parent' => $parent, 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->record($actor, 'department.created', 'department', (string) $id, $name, ['parentId' => $parent], $requestId, $now);
        });
        return ['department' => $this->department($actor, $id)];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function updateDepartment(EmployeeIdentity $actor, int $departmentId, array $input, string $requestId): array
    {
        $this->require($actor, 'departments.update');
        if ($input === []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Nothing to update.');
        }
        $this->transaction(function () use ($actor, $departmentId, $input, $requestId): void {
            $department = $this->departmentRow($departmentId, true);
            $now = $this->now();
            $sets = [];
            $params = ['id' => $departmentId, 'now' => $now];
            $metadata = [];
            if (array_key_exists('name', $input)) {
                $params['name'] = $this->text($input['name'], 2, 120, 'name');
                $sets[] = 'name = :name';
                $metadata['name'] = ['before' => $department['name'], 'after' => $params['name']];
            }
            if (array_key_exists('description', $input)) {
                $params['description'] = $this->optionalText($input['description'], 255, 'description');
                $sets[] = 'description = :description';
            }
            if (array_key_exists('parentId', $input)) {
                $parent = $input['parentId'] === null ? null : (int) $this->departmentRow($input['parentId'])['department_id'];
                for ($cursor = $parent, $depth = 0; $cursor !== null && $depth < 64; $depth++) {
                    if ($cursor === $departmentId) {
                        throw new ApiException(409, 'DEPARTMENT_CYCLE', 'The department tree would form a cycle.');
                    }
                    $row = $this->departmentRow($cursor);
                    $cursor = $row['parent_department_id'] === null ? null : (int) $row['parent_department_id'];
                }
                $params['parent'] = $parent;
                $sets[] = 'parent_department_id = :parent';
                $metadata['parentId'] = ['before' => $department['parent_department_id'], 'after' => $parent];
            }
            if (array_key_exists('status', $input)) {
                if (!in_array($input['status'], ['active', 'inactive'], true)) {
                    throw new ApiException(400, 'INVALID_REQUEST', 'Status must be active or inactive.');
                }
                if ($input['status'] === 'inactive' && $this->activeDepartmentMembers($departmentId) > 0) {
                    throw new ApiException(409, 'DEPARTMENT_IN_USE', 'Move active employees out of the department first.');
                }
                $params['status'] = $input['status'];
                $sets[] = 'status = :status';
                $metadata['status'] = ['before' => $department['status'], 'after' => $input['status']];
            }
            if ($sets !== []) {
                $this->pdo->prepare('UPDATE departments SET ' . implode(', ', $sets) . ', updated_at = :now WHERE department_id = :id')->execute($params);
            }
            $this->audit->record($actor, 'department.updated', 'department', (string) $departmentId, (string) ($params['name'] ?? $department['name']), $metadata, $requestId, $now);
        });
        return ['department' => $this->department($actor, $departmentId)];
    }

    public function deleteDepartment(EmployeeIdentity $actor, int $departmentId, string $requestId): void
    {
        $this->require($actor, 'departments.delete');
        $this->transaction(function () use ($actor, $departmentId, $requestId): void {
            $department = $this->departmentRow($departmentId, true);
            $used = $this->pdo->prepare('SELECT (SELECT COUNT(*) FROM employees WHERE department_id = :a) + (SELECT COUNT(*) FROM departments WHERE parent_department_id = :b)');
            $used->execute(['a' => $departmentId, 'b' => $departmentId]);
            if ((int) $used->fetchColumn() > 0) {
                throw new ApiException(409, 'DEPARTMENT_IN_USE', 'The department still has employees or sub-departments.');
            }
            $now = $this->now();
            $this->pdo->prepare('DELETE FROM departments WHERE department_id = :id')->execute(['id' => $departmentId]);
            $this->audit->record($actor, 'department.deleted', 'department', (string) $departmentId, (string) $department['name'], [], $requestId, $now);
        });
    }

    // ---------------------------------------------------------------- audit

    /** @param array<string, string> $filters @return array<string, mixed> */
    public function audit(EmployeeIdentity $actor, array $filters): array
    {
        $this->require($actor, 'iam.audit.view');
        $where = ['1 = 1'];
        $params = [];
        if (preg_match('/^[0-9a-f-]{36}$/D', $filters['actorId'] ?? '') === 1) {
            $where[] = 'actor_employee_uuid = :actor';
            $params['actor'] = $filters['actorId'];
        }
        if (preg_match('/^[a-z_.]{3,80}$/D', $filters['action'] ?? '') === 1) {
            $where[] = 'action = :action';
            $params['action'] = $filters['action'];
        }
        if (preg_match('/^[a-z_]{3,40}$/D', $filters['targetType'] ?? '') === 1) {
            $where[] = 'target_type = :target_type';
            $params['target_type'] = $filters['targetType'];
        }
        if (preg_match('/^[A-Za-z0-9-]{1,100}$/D', $filters['targetId'] ?? '') === 1) {
            $where[] = 'target_id = :target_id';
            $params['target_id'] = $filters['targetId'];
        }
        if (($filters['search'] ?? '') !== '') {
            $where[] = '(actor_label LIKE :search_actor OR target_label LIKE :search_target)';
            $like = '%' . addcslashes(mb_substr(trim($filters['search']), 0, 80), '%_\\') . '%';
            $params += ['search_actor' => $like, 'search_target' => $like];
        }
        foreach (['from' => '>=', 'to' => '<'] as $name => $operator) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $filters[$name] ?? '') === 1) {
                $date = new \DateTimeImmutable($filters[$name], new \DateTimeZone('Europe/Bucharest'));
                if ($name === 'to') {
                    $date = $date->modify('+1 day');
                }
                $where[] = "created_at {$operator} :{$name}";
                $params[$name] = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            }
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6})\|([0-9a-f-]{36})$/D', (string) base64_decode($filters['cursor'] ?? '', true), $cursor) === 1) {
            $where[] = '(created_at < :cursor_time OR (created_at = :cursor_time_eq AND event_id < :cursor_id))';
            $params += ['cursor_time' => $cursor[1], 'cursor_time_eq' => $cursor[1], 'cursor_id' => $cursor[2]];
        }
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
        $statement = $this->pdo->prepare(
            'SELECT event_id, actor_employee_uuid, actor_label, actor_type, action, target_type, target_id, target_label, metadata_json, created_at
             FROM iam_audit_events WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, event_id DESC LIMIT ' . ($limit + 1),
        );
        $statement->execute($params);
        $rows = $statement->fetchAll();
        $next = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $last = end($rows);
            $next = base64_encode("{$last['created_at']}|{$last['event_id']}");
        }
        return ['items' => array_map(static fn (array $row): array => [
            'id' => (string) $row['event_id'],
            'actorId' => $row['actor_employee_uuid'] === null ? null : (string) $row['actor_employee_uuid'],
            'actorLabel' => (string) $row['actor_label'],
            'actorType' => (string) $row['actor_type'],
            'action' => (string) $row['action'],
            'targetType' => (string) $row['target_type'],
            'targetId' => (string) $row['target_id'],
            'targetLabel' => (string) $row['target_label'],
            'metadata' => $row['metadata_json'] === null ? null : json_decode((string) $row['metadata_json'], true),
            'createdAt' => (new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        ], $rows), 'nextCursor' => $next];
    }

    // ---------------------------------------------------------------- authority helpers

    private function require(EmployeeIdentity $actor, string $permission): void
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        $this->authorization->require($actor, $permission);
    }

    /** @return list<string> */
    private function grantablePermissions(EmployeeIdentity $actor): array
    {
        $keys = array_map('strval', $this->pdo->query('SELECT permission_key FROM permissions WHERE role_grantable = 1 ORDER BY permission_key')->fetchAll(PDO::FETCH_COLUMN));
        return $actor->isRoot ? $keys : array_values(array_intersect($keys, $actor->permissions));
    }

    private function assertRankBelowCeiling(EmployeeIdentity $actor, int $rank): void
    {
        if (!$actor->isRoot && $rank >= $actor->authorityRank) {
            throw new ApiException(403, 'AUTHORITY_EXCEEDED', 'The role is at or above your authority.');
        }
    }

    /** @param list<string> $keys @return list<int> */
    private function grantablePermissionIds(EmployeeIdentity $actor, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $statement = $this->pdo->prepare("SELECT permission_id, permission_key, role_grantable FROM permissions WHERE permission_key IN ({$placeholders})");
        $statement->execute($keys);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['permission_key']] = $row;
        }
        foreach ($keys as $key) {
            if (!isset($rows[$key])) {
                throw new ApiException(400, 'UNKNOWN_PERMISSION', 'Unknown permission.');
            }
        }
        $grantable = $this->grantablePermissions($actor);
        foreach ($keys as $key) {
            if ((int) $rows[$key]['role_grantable'] !== 1 || !in_array($key, $grantable, true)) {
                throw new ApiException(403, 'PERMISSION_NOT_GRANTABLE', 'A requested permission cannot be granted by you.');
            }
        }
        return array_map(static fn (string $key): int => (int) $rows[$key]['permission_id'], $keys);
    }

    /** @param list<int> $roleIds @return array<int, array<string, mixed>> */
    private function assignableRoles(EmployeeIdentity $actor, array $roleIds): array
    {
        $roles = [];
        foreach ($roleIds as $roleId) {
            $statement = $this->pdo->prepare('SELECT role_id, role_key, name, authority_rank, status FROM roles WHERE role_id = :id');
            $statement->execute(['id' => $roleId]);
            $role = $statement->fetch();
            if (!is_array($role) || $role['status'] !== 'active') {
                throw new ApiException(400, 'UNKNOWN_ROLE', 'Unknown or inactive role.');
            }
            $this->assertRankBelowCeiling($actor, (int) $role['authority_rank']);
            if (!$actor->isRoot) {
                $permissions = $this->column('SELECT p.permission_key FROM role_permissions rp INNER JOIN permissions p ON p.permission_id = rp.permission_id WHERE rp.role_id = :id', (string) $roleId);
                if (array_diff($permissions, $this->grantablePermissions($actor)) !== []) {
                    throw new ApiException(403, 'AUTHORITY_EXCEEDED', 'The role grants permissions you do not hold.');
                }
            }
            $roles[(int) $role['role_id']] = $role;
        }
        return $roles;
    }

    /** @param list<string> $before @param list<string> $after */
    private function assertApplicationsGrantable(EmployeeIdentity $actor, array $before, array $after): void
    {
        foreach ($after as $key) {
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM applications WHERE application_key = :key AND status = 'active'");
            $statement->execute(['key' => $key]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new ApiException(400, 'UNKNOWN_APPLICATION', 'Unknown or inactive application.');
            }
        }
        if ($actor->isRoot) {
            return;
        }
        foreach (array_merge(array_diff($after, $before), array_diff($before, $after)) as $changed) {
            if (!$actor->hasApplication($changed)) {
                throw new ApiException(403, 'AUTHORITY_EXCEEDED', 'You cannot manage access to an application you cannot use.');
            }
        }
    }

    /** @param callable(array<string, mixed>, string): void $change */
    private function mutateEmployee(EmployeeIdentity $actor, string $employeeId, callable $change): void
    {
        $uuid = $this->validUuid($employeeId);
        $this->transaction(function () use ($actor, $uuid, $change): void {
            $target = $this->lockEmployee($uuid, 'EMPLOYEE_NOT_FOUND');
            $isRoot = $this->pdo->prepare('SELECT COUNT(*) FROM system_root_identity WHERE employee_uuid = :id');
            $isRoot->execute(['id' => $uuid]);
            if ((int) $isRoot->fetchColumn() !== 0) {
                throw new ApiException(403, 'ROOT_PROTECTED', 'The principal administrator is a protected system identity.');
            }
            if (!$actor->isRoot) {
                if ($uuid === $actor->employeeUuid) {
                    throw new ApiException(403, 'SELF_MODIFICATION_DENIED', 'You cannot change your own access.');
                }
                $rank = $this->pdo->prepare('SELECT COALESCE(MAX(r.authority_rank), 0) FROM employee_role_assignments era INNER JOIN roles r ON r.role_id = era.role_id WHERE era.employee_uuid = :id');
                $rank->execute(['id' => $uuid]);
                if ((int) $rank->fetchColumn() >= $actor->authorityRank) {
                    throw new ApiException(403, 'AUTHORITY_EXCEEDED', 'The employee is at or above your authority.');
                }
            }
            $change($target, $this->now());
        });
    }

    // ---------------------------------------------------------------- persistence helpers

    /** @param callable(): void $operation */
    private function transaction(callable $operation): void
    {
        $this->pdo->beginTransaction();
        try {
            $operation();
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) {
                throw new ApiException(409, 'CONFLICT', 'The change conflicts with existing data.');
            }
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    private function lockEmployee(string $uuid, string $notFoundCode): array
    {
        $statement = $this->pdo->prepare('SELECT employee_uuid, username, display_name, position_title, department_id, manager_employee_uuid, status FROM employees WHERE employee_uuid = :id FOR UPDATE');
        $statement->execute(['id' => $uuid]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException($notFoundCode === 'EMPLOYEE_NOT_FOUND' ? 404 : 400, $notFoundCode, 'Employee was not found.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function lockRole(int $roleId): array
    {
        $statement = $this->pdo->prepare('SELECT role_id, role_key, name, authority_rank, status FROM roles WHERE role_id = :id FOR UPDATE');
        $statement->execute(['id' => $roleId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'ROLE_NOT_FOUND', 'Role was not found.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function departmentRow(mixed $id, bool $lock = false): array
    {
        if (!is_int($id) || $id < 1) {
            throw new ApiException(400, 'UNKNOWN_DEPARTMENT', 'Unknown department.');
        }
        $statement = $this->pdo->prepare('SELECT department_id, name, status, parent_department_id FROM departments WHERE department_id = :id' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException($lock ? 404 : 400, $lock ? 'DEPARTMENT_NOT_FOUND' : 'UNKNOWN_DEPARTMENT', 'Unknown department.');
        }
        return $row;
    }

    private function activeDepartmentId(mixed $id): int
    {
        $row = $this->departmentRow($id);
        if ($row['status'] !== 'active') {
            throw new ApiException(400, 'UNKNOWN_DEPARTMENT', 'The department is inactive.');
        }
        return (int) $row['department_id'];
    }

    private function activeDepartmentMembers(int $departmentId): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM employees WHERE department_id = :id AND status = 'active'");
        $statement->execute(['id' => $departmentId]);
        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function department(EmployeeIdentity $actor, int $id): array
    {
        foreach ($this->listDepartments($actor)['items'] as $department) {
            if ($department['id'] === $id) {
                return $department;
            }
        }
        throw new ApiException(404, 'DEPARTMENT_NOT_FOUND', 'Department was not found.');
    }

    /** @param list<string> $stageIds @return list<string> */
    private function canonicalStages(array $stageIds): array
    {
        $valid = array_map('strval', $this->pdo->query(
            "SELECT ps.stage_id FROM production_stages ps INNER JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id
             WHERE ps.status = 'active' AND pw.status = 'active' ORDER BY ps.ordinal",
        )->fetchAll(PDO::FETCH_COLUMN));
        foreach ($stageIds as $stageId) {
            if (!in_array($stageId, $valid, true)) {
                throw new ApiException(400, 'UNKNOWN_STAGE', 'Unknown production stage.');
            }
        }
        return array_values(array_filter($valid, static fn (string $stage): bool => in_array($stage, $stageIds, true)));
    }

    /** @param list<string> $keys */
    private function writeApplications(string $uuid, array $keys, string $now): void
    {
        $statement = $this->pdo->prepare('INSERT INTO employee_application_access (employee_uuid, application_key, granted_at) VALUES (:id, :key, :now)');
        foreach ($keys as $key) {
            $statement->execute(['id' => $uuid, 'key' => $key, 'now' => $now]);
        }
    }

    /** @param list<int> $roleIds */
    private function writeRoles(string $uuid, array $roleIds, string $now): void
    {
        $statement = $this->pdo->prepare('INSERT INTO employee_role_assignments (employee_uuid, role_id, assigned_at) VALUES (:id, :role, :now)');
        foreach ($roleIds as $roleId) {
            $statement->execute(['id' => $uuid, 'role' => $roleId, 'now' => $now]);
        }
    }

    /** @param list<string> $stages */
    private function writeStages(string $uuid, array $stages, string $now): void
    {
        $statement = $this->pdo->prepare('INSERT INTO employee_stage_access (employee_uuid, stage_id, created_at) VALUES (:id, :stage, :now)');
        foreach ($stages as $stage) {
            $statement->execute(['id' => $uuid, 'stage' => $stage, 'now' => $now]);
        }
    }

    /** @param list<int> $permissionIds */
    private function writeRolePermissions(int $roleId, array $permissionIds, string $now): void
    {
        $statement = $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (:role, :permission, :now)');
        foreach (array_unique($permissionIds) as $permissionId) {
            $statement->execute(['role' => $roleId, 'permission' => $permissionId, 'now' => $now]);
        }
    }

    private function bumpAuthorization(string $uuid, string $now): void
    {
        $this->pdo->prepare('UPDATE employees SET authorization_version = authorization_version + 1, updated_at = :now WHERE employee_uuid = :id')->execute(['now' => $now, 'id' => $uuid]);
    }

    private function revokeSessions(string $uuid, string $now): int
    {
        $statement = $this->pdo->prepare('UPDATE auth_sessions SET revoked_at = :now WHERE employee_uuid = :id AND revoked_at IS NULL');
        $statement->execute(['now' => $now, 'id' => $uuid]);
        return $statement->rowCount();
    }

    /** @return list<string> */
    private function column(string $sql, string $id): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int, list<string>> */
    private function rolePermissionMap(): array
    {
        $map = [];
        foreach ($this->pdo->query('SELECT rp.role_id, p.permission_key FROM role_permissions rp INNER JOIN permissions p ON p.permission_id = rp.permission_id ORDER BY p.permission_key')->fetchAll() as $row) {
            $map[(int) $row['role_id']][] = (string) $row['permission_key'];
        }
        return $map;
    }

    /** @param array<string, mixed> $row @param list<string> $permissions @return array<string, mixed> */
    private function roleView(EmployeeIdentity $actor, array $row, array $permissions): array
    {
        $manageable = $actor->isRoot || ((int) $row['authority_rank'] < $actor->authorityRank && array_diff($permissions, $this->grantablePermissions($actor)) === []);
        return [
            'id' => (int) $row['role_id'],
            'key' => (string) $row['role_key'],
            'name' => (string) $row['name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'authorityRank' => (int) $row['authority_rank'],
            'isTemplate' => (int) $row['is_template'] === 1,
            'status' => (string) $row['status'],
            'userCount' => (int) $row['user_count'],
            'permissionCount' => count($permissions),
            'permissions' => $permissions,
            'manageable' => $manageable,
        ];
    }

    /** @param list<string> $ids @return list<array<string, mixed>> */
    private function summaries(EmployeeIdentity $actor, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT e.employee_uuid, e.username, e.display_name, e.position_title, e.status, e.must_change_password, e.authorization_version,
                    e.last_login_at, e.created_at, e.updated_at, e.manager_employee_uuid, m.display_name AS manager_name,
                    d.department_id, d.name AS department_name,
                    (SELECT COUNT(*) FROM system_root_identity sri WHERE sri.employee_uuid = e.employee_uuid) AS is_root
             FROM employees e
             INNER JOIN departments d ON d.department_id = e.department_id
             LEFT JOIN employees m ON m.employee_uuid = e.manager_employee_uuid
             WHERE e.employee_uuid IN ({$placeholders})",
        );
        $statement->execute($ids);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['employee_uuid']] = $row;
        }
        $related = function (string $sql) use ($ids, $placeholders): array {
            $query = $this->pdo->prepare(str_replace('{ids}', $placeholders, $sql));
            $query->execute($ids);
            $map = [];
            foreach ($query->fetchAll() as $row) {
                $map[(string) $row['employee_uuid']][] = $row;
            }
            return $map;
        };
        $applications = $related('SELECT eaa.employee_uuid, eaa.application_key FROM employee_application_access eaa INNER JOIN applications a ON a.application_key = eaa.application_key WHERE eaa.employee_uuid IN ({ids}) ORDER BY a.sort_order');
        $roles = $related('SELECT era.employee_uuid, r.role_id, r.role_key, r.name, r.authority_rank, r.status FROM employee_role_assignments era INNER JOIN roles r ON r.role_id = era.role_id WHERE era.employee_uuid IN ({ids}) ORDER BY r.authority_rank DESC');
        $stages = $related('SELECT esa.employee_uuid, esa.stage_id FROM employee_stage_access esa INNER JOIN production_stages ps ON ps.stage_id = esa.stage_id WHERE esa.employee_uuid IN ({ids}) ORDER BY ps.ordinal');
        $allApplications = array_map('strval', $this->pdo->query("SELECT application_key FROM applications WHERE status = 'active' ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN));
        $result = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null) {
                continue;
            }
            $isRoot = (int) $row['is_root'] === 1;
            $employeeRoles = $roles[$id] ?? [];
            $rank = $employeeRoles === [] ? 0 : max(array_map(static fn (array $role): int => (int) $role['authority_rank'], $employeeRoles));
            $result[] = [
                'id' => $id,
                'username' => (string) $row['username'],
                'displayName' => (string) $row['display_name'],
                'positionTitle' => $row['position_title'] === null ? null : (string) $row['position_title'],
                'status' => (string) $row['status'],
                'isRoot' => $isRoot,
                'department' => ['id' => (int) $row['department_id'], 'name' => (string) $row['department_name']],
                'manager' => $row['manager_employee_uuid'] === null ? null : ['id' => (string) $row['manager_employee_uuid'], 'displayName' => (string) $row['manager_name']],
                'applications' => $isRoot ? $allApplications : array_map(static fn (array $application): string => (string) $application['application_key'], $applications[$id] ?? []),
                'roles' => array_map(static fn (array $role): array => ['id' => (int) $role['role_id'], 'key' => (string) $role['role_key'], 'name' => (string) $role['name'], 'authorityRank' => (int) $role['authority_rank'], 'status' => (string) $role['status']], $employeeRoles),
                'stageIds' => array_map(static fn (array $stage): string => (string) $stage['stage_id'], $stages[$id] ?? []),
                'mustChangePassword' => (int) $row['must_change_password'] === 1,
                'authorizationVersion' => (int) $row['authorization_version'],
                'lastLoginAt' => $row['last_login_at'] === null ? null : (new \DateTimeImmutable((string) $row['last_login_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
                'createdAt' => (new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
                'manageable' => !$isRoot && ($actor->isRoot || ($id !== $actor->employeeUuid && $rank < $actor->authorityRank)),
            ];
        }
        return $result;
    }

    // ---------------------------------------------------------------- input helpers

    private function validUuid(string $value): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) !== 1) {
            throw new ApiException(404, 'EMPLOYEE_NOT_FOUND', 'Employee was not found.');
        }
        return $value;
    }

    private function text(mixed $value, int $min, int $max, string $field): string
    {
        if (!is_string($value) || mb_strlen(trim($value)) < $min || mb_strlen(trim($value)) > $max || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new ApiException(400, 'INVALID_REQUEST', "Field {$field} is invalid.");
        }
        return trim($value);
    }

    private function optionalText(mixed $value, int $max, string $field): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        return $this->text($value, 1, $max, $field);
    }

    private function rank(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 999) {
            throw new ApiException(400, 'INVALID_REQUEST', 'authorityRank must be an integer between 1 and 999.');
        }
        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new ApiException(400, 'INVALID_REQUEST', "Field {$field} must be a list.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $item) !== 1) {
                throw new ApiException(400, 'INVALID_REQUEST', "Field {$field} contains an invalid value.");
            }
        }
        return array_values(array_unique($value));
    }

    /** @return list<int> */
    private function intList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            throw new ApiException(400, 'INVALID_REQUEST', "Field {$field} must be a list.");
        }
        foreach ($value as $item) {
            if (!is_int($item) || $item < 1) {
                throw new ApiException(400, 'INVALID_REQUEST', "Field {$field} contains an invalid value.");
            }
        }
        return array_values(array_unique($value));
    }

    private function uniqueKey(string $table, string $column, string $name): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name), '-'));
        $base = substr($base === '' ? 'item' : $base, 0, 48);
        $allowed = ['roles:role_key', 'departments:department_key'];
        if (!in_array("{$table}:{$column}", $allowed, true)) {
            throw new \LogicException('Unsafe key lookup.');
        }
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = $attempt === 0 ? $base : $base . '-' . bin2hex(random_bytes(2));
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :key");
            $statement->execute(['key' => $candidate]);
            if ((int) $statement->fetchColumn() === 0) {
                return $candidate;
            }
        }
        throw new ApiException(409, 'CONFLICT', 'Could not allocate a unique key.');
    }

    /** @param array<string, mixed> $row */
    private function label(array $row): string
    {
        return "{$row['display_name']} ({$row['username']})";
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
