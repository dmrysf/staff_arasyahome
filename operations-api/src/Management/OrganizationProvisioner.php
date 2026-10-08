<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Document\DocumentScopePolicy;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\EmployeeRepository;
use Arasya\Operations\Iam\CredentialSink;
use PDO;
use RuntimeException;

/**
 * Applies the owner-authorized onboarding plan (OrganizationOnboarding) through the official IAM services as
 * the root identity, so every change is validated, authorized and written to the IAM audit exactly like a root
 * Dashboard action (request IDs start with "cli-").
 *
 * Idempotent and additive:
 * - a person is created once, matched by the name-based username; an existing identity is used only when it is
 *   the same person (same name tokens), otherwise the whole run is refused;
 * - applications, roles and document scopes are only added, never removed; a deactivated identity is never
 *   reactivated; a different existing manager or CEO principal is never replaced (reported as drift instead);
 * - production stages, passwords of existing identities and root are never touched.
 *
 * Temporary passwords come from the server-side generator inside ManagementService::createEmployee. Only
 * identities created active get one written to the CredentialSink; an inactive identity's password is
 * discarded at once (activation later goes through an administrator password reset).
 */
final readonly class OrganizationProvisioner
{
    public const PROBE_PREFIX = 'test.onboarding.';

    public function __construct(
        private PDO $pdo,
        private ManagementService $management,
        private OrganizationService $organization,
        private OrganizationReconciler $reconciler,
        private EmployeeRepository $employees,
    ) {
    }

    /** @return array<string, mixed> read-only plan; never changes anything */
    public function plan(OrganizationOnboarding $onboarding): array
    {
        $conflicts = [];
        $drift = [];
        $membership = $this->reconciler->plan($onboarding->roster());
        $departmentIds = [];
        $departments = [];
        foreach ($membership['departments'] as $department) {
            $departmentIds[$department['key']] = $department['existingId'];
            if (in_array($department['action'], ['ambiguous', 'inactive'], true)) {
                $conflicts[] = "department {$department['name']} is {$department['action']}";
            }
        }
        $rows = $this->departmentRows();
        foreach ($onboarding->departments() as $key => $wanted) {
            $id = $departmentIds[$key] ?? null;
            $current = $id === null ? null : $rows[$id];
            $wantedParent = $wanted['parent'];
            $currentParentKey = $current === null || $current['parent_department_id'] === null ? null : array_search((int) $current['parent_department_id'], $departmentIds, true);
            $wantedDescription = $wanted['description'] ?? ($current === null ? $wanted['location'] : null);
            $changes = [];
            if ($wantedParent !== null && $currentParentKey !== $wantedParent) {
                $changes['parent'] = ['before' => $currentParentKey === false ? '(other)' : $currentParentKey, 'after' => $wantedParent];
            }
            if ($wanted['description'] !== null && ($current['description'] ?? null) !== $wanted['description']) {
                $changes['description'] = ['before' => $current['description'] ?? null, 'after' => $wanted['description']];
            }
            $departments[] = ['key' => $key, 'name' => $wanted['name'], 'id' => $id, 'action' => $id === null ? 'create' : 'exists', 'changes' => $changes, 'description' => $wantedDescription];
        }

        $roles = [];
        foreach ($onboarding->roles() as $key => $role) {
            $existing = $this->roleRow($key);
            if ($existing === null) {
                $roles[] = ['key' => $key, 'name' => $role['name'], 'action' => 'create', 'authorityRank' => $role['authorityRank'], 'permissions' => $role['permissions']];
                continue;
            }
            $permissions = $this->rolePermissions((int) $existing['role_id']);
            if ($existing['status'] !== 'active' || (int) $existing['authority_rank'] !== $role['authorityRank'] || $permissions !== $role['permissions']) {
                $conflicts[] = "role {$key} exists with a different rank, status or permission set";
            }
            $roles[] = ['key' => $key, 'name' => $role['name'], 'action' => 'exists', 'authorityRank' => $role['authorityRank'], 'permissions' => $role['permissions']];
        }
        $knownRoles = array_flip(array_merge(array_keys($onboarding->roles()), $this->activeRoleKeys()));
        $sources = $this->pdo->query("SELECT source_key FROM order_sources WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);

        $byUsername = [];
        $byNameKey = [];
        foreach ($this->identities() as $identity) {
            $byUsername[$identity['username_normalized']] = $identity;
            if ($identity['is_root'] === 0) {
                $byNameKey[OrganizationRoster::nameKey($identity['display_name'])][] = $identity;
            }
        }
        $ceo = $this->pdo->query("SELECT e.username_normalized FROM organization_principals p INNER JOIN employees e ON e.employee_uuid = p.employee_uuid WHERE p.principal_key = 'ceo'")->fetchColumn();
        $ceo = $ceo === false ? null : (string) $ceo;
        $people = [];
        $principal = null;
        $usernames = array_column($onboarding->people(), 'username', 'name');
        foreach ($onboarding->people() as $name => $person) {
            $identity = $byUsername[$person['username']] ?? null;
            $nameKey = OrganizationRoster::nameKey($name);
            $action = 'create';
            if ($identity !== null) {
                $action = 'exists';
                if ($identity['is_root'] === 1 || OrganizationRoster::nameKey($identity['display_name']) !== $nameKey) {
                    $conflicts[] = "username {$person['username']} already belongs to another identity";
                    $action = 'conflict';
                }
            }
            foreach ($byNameKey[$nameKey] ?? [] as $other) {
                if ($other['username_normalized'] !== $person['username']) {
                    $conflicts[] = "{$name} already has another identity ({$other['username_normalized']}); no duplicate is created";
                    $action = 'conflict';
                }
            }
            foreach ($person['roles'] as $role) {
                if (!isset($knownRoles[$role])) {
                    $conflicts[] = "{$name}: role {$role} does not exist and is not defined by the plan";
                }
            }
            foreach ($person['documentScopes'] as $capability => $scoped) {
                if (array_diff($scoped, $sources) !== []) {
                    $conflicts[] = "{$name}: {$capability} scope names an unknown or inactive source";
                }
            }
            $current = $identity !== null && $action === 'exists' ? $this->access($identity['employee_uuid']) : null;
            $wantedManager = $person['manager'] === null ? null : $usernames[$person['manager']];
            $currentManager = $current['manager'] ?? null;
            if ($current !== null) {
                if ($person['status'] === 'active' && $identity['status'] !== 'active') {
                    $drift[] = "{$person['username']} is {$identity['status']}: it is not reactivated and receives no grant";
                }
                if ($wantedManager !== null && $currentManager !== null && $currentManager !== $wantedManager) {
                    $drift[] = "{$person['username']} reports to {$currentManager}; the plan's {$wantedManager} is not forced";
                }
                foreach (['applications', 'roles'] as $field) {
                    $extra = array_values(array_diff($current[$field], $person[$field]));
                    if ($extra !== []) {
                        $drift[] = "{$person['username']} holds {$field} outside the plan (kept): " . implode(', ', $extra);
                    }
                }
                if ($current['stages'] !== []) {
                    $drift[] = "{$person['username']} holds production stages (kept, never managed here): " . implode(', ', $current['stages']);
                }
            }
            $grantable = $current === null ? $person['status'] === 'active' : ($person['status'] === 'active' && $identity['status'] === 'active');
            $add = static fn (array $wanted, array $have): array => $grantable ? array_values(array_diff($wanted, $have)) : [];
            if ($person['principal'] === 'ceo') {
                $principal = ['username' => $person['username'], 'current' => $ceo, 'action' => match (true) {
                    $ceo === $person['username'] => 'designated',
                    $ceo !== null => 'conflict',
                    default => 'designate',
                }];
                if ($ceo !== null && $ceo !== $person['username']) {
                    $conflicts[] = "the CEO principal is already {$ceo}; it is never replaced by this tool";
                }
            }
            $people[] = [
                'name' => $name,
                'username' => $person['username'],
                'employeeId' => $identity['employee_uuid'] ?? null,
                'action' => $action,
                'status' => ['wanted' => $person['status'], 'current' => $identity['status'] ?? null],
                'department' => $person['department'],
                'title' => $person['title'],
                'additionalDepartments' => $person['additionalDepartments'],
                'excluded' => $person['excluded'],
                'manager' => ['wanted' => $wantedManager, 'current' => $currentManager, 'set' => $wantedManager !== null && $currentManager === null],
                'applications' => ['wanted' => $person['applications'], 'add' => $add($person['applications'], $current['applications'] ?? [])],
                'roles' => ['wanted' => $person['roles'], 'add' => $add($person['roles'], $current['roles'] ?? [])],
                'documentScopes' => [
                    'wanted' => $person['documentScopes'],
                    'add' => ['operate' => $add($person['documentScopes']['operate'], $current['documentScopes']['operate'] ?? []), 'approve' => $add($person['documentScopes']['approve'], $current['documentScopes']['approve'] ?? [])],
                ],
                'principal' => $person['principal'],
                'credential' => $action === 'create' && $person['status'] === 'active',
            ];
        }
        $count = static fn (callable $filter): int => count(array_filter($people, $filter));
        return [
            'departments' => $departments,
            'roles' => $roles,
            'people' => $people,
            'principal' => $principal,
            'blockedApplications' => $onboarding->blockedApplications(),
            'pending' => $onboarding->pending(),
            'conflicts' => array_values(array_unique($conflicts)),
            'drift' => $drift,
            'summary' => [
                'people' => count($people),
                'create' => $count(static fn (array $p): bool => $p['action'] === 'create'),
                'exists' => $count(static fn (array $p): bool => $p['action'] === 'exists'),
                'wantedActive' => $count(static fn (array $p): bool => $p['status']['wanted'] === 'active'),
                'wantedInactive' => $count(static fn (array $p): bool => $p['status']['wanted'] === 'inactive'),
                'credentials' => $count(static fn (array $p): bool => $p['credential']),
                'managerLinks' => $count(static fn (array $p): bool => $p['manager']['wanted'] !== null),
                'managerLinksToSet' => $count(static fn (array $p): bool => $p['manager']['set']),
                'departmentsToCreate' => count(array_filter($departments, static fn (array $d): bool => $d['action'] === 'create')),
                'rolesToCreate' => count(array_filter($roles, static fn (array $r): bool => $r['action'] === 'create')),
                'conflicts' => count(array_unique($conflicts)),
            ],
        ];
    }

    /** @return array{plan: array<string, mixed>, applied: list<string>, credentials: int, credentialFile: string} */
    public function apply(OrganizationOnboarding $onboarding, CredentialSink $credentials, string $requestId): array
    {
        $plan = $this->plan($onboarding);
        if ($plan['conflicts'] !== []) {
            throw new RuntimeException("Onboarding refused, nothing changed:\n- " . implode("\n- ", $plan['conflicts']));
        }
        $root = $this->root();
        $applied = [];

        // 1. Departments (created through the roster reconciler, then hierarchy and oversight text).
        foreach ($this->reconciler->apply($onboarding->roster(), $requestId)['applied'] as $line) {
            $applied[] = $line;
        }
        $ids = $this->departmentIds($onboarding);
        foreach ($onboarding->departments() as $key => $wanted) {
            $row = $this->departmentRows()[$ids[$key]];
            $update = [];
            if ($wanted['parent'] !== null && (int) ($row['parent_department_id'] ?? 0) !== $ids[$wanted['parent']]) {
                $update['parentId'] = $ids[$wanted['parent']];
            }
            if ($wanted['description'] !== null && $row['description'] !== $wanted['description']) {
                $update['description'] = $wanted['description'];
            }
            if ($update !== []) {
                $this->management->updateDepartment($root, $ids[$key], $update, $requestId);
                $applied[] = "department updated: {$wanted['name']} (" . implode(', ', array_keys($update)) . ')';
            }
        }

        // 2. Custom roles composed from existing permissions.
        foreach ($onboarding->roles() as $key => $role) {
            if ($this->roleRow($key) !== null) {
                continue;
            }
            $created = $this->management->createRole($root, ['name' => $role['name'], 'description' => $role['description'], 'authorityRank' => $role['authorityRank'], 'permissions' => $role['permissions']], $requestId);
            if (($created['role']['key'] ?? null) !== $key) {
                throw new RuntimeException("Role {$key} could not be created under its planned key.");
            }
            $applied[] = "role created: {$key}";
        }

        // 3. Identities. Temporary passwords leave this method only through the credential sink.
        $people = $onboarding->people();
        foreach ($plan['people'] as $entry) {
            if ($entry['action'] !== 'create') {
                continue;
            }
            $person = $people[$entry['name']];
            $result = $this->management->createEmployee($root, [
                'displayName' => $person['name'],
                'username' => $person['username'],
                'positionTitle' => $person['title'],
                'departmentId' => $ids[$person['department']],
                'status' => $person['status'],
            ], $requestId);
            if ($person['status'] === 'active') {
                $credentials->write(['name' => $person['name'], 'username' => $person['username'], 'temporaryPassword' => $result['temporaryPassword'], 'applications' => $person['applications'], 'reason' => 'Cont nou']);
            }
            unset($result);
            $applied[] = "identity created ({$person['status']}): {$person['username']}";
        }

        // 4. Additional departments and titles for every roster person (same reconciler as before).
        foreach ($this->reconciler->apply($onboarding->roster(), $requestId)['applied'] as $line) {
            $applied[] = $line;
        }

        // 5. Confirmed reporting lines, 6. authorized access, 7. CEO principal (re-planned on current data).
        $after = $this->plan($onboarding);
        $uuids = array_column($after['people'], 'employeeId', 'username');
        foreach ($after['people'] as $entry) {
            if ($entry['manager']['set']) {
                $this->management->setManager($root, $entry['employeeId'], $uuids[$entry['manager']['wanted']], $requestId);
                $applied[] = "manager set: {$entry['username']} -> {$entry['manager']['wanted']}";
            }
        }
        foreach ($after['people'] as $entry) {
            $current = $this->access($entry['employeeId']);
            if ($entry['applications']['add'] !== []) {
                $this->management->setApplications($root, $entry['employeeId'], array_values(array_unique([...$current['applications'], ...$entry['applications']['add']])), $requestId);
                $applied[] = "applications granted: {$entry['username']} +" . implode(', +', $entry['applications']['add']);
            }
            if ($entry['roles']['add'] !== []) {
                $roleIds = array_map(fn (string $key): int => (int) ($this->roleRow($key)['role_id'] ?? throw new RuntimeException("Unknown role {$key}.")), array_values(array_unique([...$current['roles'], ...$entry['roles']['add']])));
                $this->management->setRoles($root, $entry['employeeId'], $roleIds, $requestId);
                $applied[] = "roles granted: {$entry['username']} +" . implode(', +', $entry['roles']['add']);
            }
            $scopeAdd = $entry['documentScopes']['add'];
            if ($scopeAdd['operate'] !== [] || $scopeAdd['approve'] !== []) {
                $scopes = $current['documentScopes'];
                $this->management->setDocumentScopes($root, $entry['employeeId'], [
                    'operate' => array_values(array_unique([...$scopes['operate'], ...$scopeAdd['operate']])),
                    'approve' => array_values(array_unique([...$scopes['approve'], ...$scopeAdd['approve']])),
                ], $requestId);
                $applied[] = "document scopes granted: {$entry['username']} operate[" . implode(', ', $scopeAdd['operate']) . '] approve[' . implode(', ', $scopeAdd['approve']) . ']';
            }
        }
        if (($after['principal']['action'] ?? null) === 'designate') {
            $this->organization->designateCeo($root, $uuids[$after['principal']['username']], substr('cli-onboarding-ceo-' . bin2hex(random_bytes(8)), 0, 60), $requestId);
            $applied[] = "CEO principal designated: {$after['principal']['username']}";
        }
        return ['plan' => $this->plan($onboarding), 'applied' => $applied, 'credentials' => $credentials->count(), 'credentialFile' => $credentials->location()];
    }

    /**
     * Administrative recovery: a fresh one-time password for one active, non-root identity (the same audited
     * reset as the Dashboard: every session is revoked and the password must be changed at the next login).
     */
    public function reissue(string $username, CredentialSink $credentials, string $requestId): string
    {
        $identity = $this->identityByUsername($username);
        if ($identity['is_root'] === 1) {
            throw new RuntimeException('Root is never reset by this tool; use bin/recover-root-password.php.');
        }
        if ($identity['status'] !== 'active') {
            throw new RuntimeException("{$identity['username_normalized']} is {$identity['status']}: activate it first, then reissue its credential.");
        }
        $reset = $this->management->resetPassword($this->root(), $identity['employee_uuid'], $requestId);
        $access = $this->access($identity['employee_uuid']);
        $credentials->write(['name' => $identity['display_name'], 'username' => $identity['username_normalized'], 'temporaryPassword' => $reset['temporaryPassword'], 'applications' => $access['applications'], 'reason' => 'Resetare parolă']);
        return $identity['username_normalized'];
    }

    /**
     * A designated, clearly named TEST identity for the live first-login check: active, no application, role,
     * stage, scope or manager, so it can sign in and change its password but reaches no company data.
     */
    public function createProbe(OrganizationOnboarding $onboarding, CredentialSink $credentials, string $requestId): string
    {
        $suffix = bin2hex(random_bytes(3));
        $username = self::PROBE_PREFIX . $suffix;
        $ids = $this->departmentIds($onboarding);
        $result = $this->management->createEmployee($this->root(), [
            'displayName' => "TEST Verificare prima autentificare {$suffix}",
            'username' => $username,
            'positionTitle' => 'Identitate de test (fără acces)',
            'departmentId' => $ids['conducere'] ?? throw new RuntimeException('Department Conducere is required.'),
            'status' => 'active',
        ], $requestId);
        $credentials->write(['name' => "TEST Verificare prima autentificare {$suffix}", 'username' => $username, 'temporaryPassword' => $result['temporaryPassword'], 'applications' => [], 'reason' => 'Test prima autentificare']);
        return $username;
    }

    /** Deactivates a first-login probe identity (only TEST probe usernames are accepted). */
    public function retireProbe(string $username, string $requestId): void
    {
        if (!str_starts_with($username, self::PROBE_PREFIX)) {
            throw new RuntimeException('Only TEST onboarding probe identities can be retired here.');
        }
        $identity = $this->identityByUsername($username);
        if ($identity['status'] === 'active') {
            $this->management->setStatus($this->root(), $identity['employee_uuid'], 'inactive', $requestId);
        }
    }

    private function root(): EmployeeIdentity
    {
        $uuid = $this->pdo->query('SELECT employee_uuid FROM system_root_identity WHERE singleton_id = 1')->fetchColumn();
        $root = is_string($uuid) ? $this->employees->findByUuid($uuid) : null;
        if ($root === null || !$root->isRoot || !$root->isOperationallyActive()) {
            throw new RuntimeException('An active root identity is required.');
        }
        return $root;
    }

    /** @return array<string, int> roster department key => department id */
    private function departmentIds(OrganizationOnboarding $onboarding): array
    {
        $ids = [];
        foreach ($this->reconciler->plan($onboarding->roster())['departments'] as $department) {
            if ($department['existingId'] === null) {
                throw new RuntimeException("Department {$department['name']} is missing.");
            }
            $ids[$department['key']] = (int) $department['existingId'];
        }
        return $ids;
    }

    /** @return array<int, array<string, mixed>> */
    private function departmentRows(): array
    {
        $rows = [];
        foreach ($this->pdo->query('SELECT department_id, name, description, parent_department_id, status FROM departments')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['department_id']] = $row;
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function roleRow(string $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT role_id, role_key, authority_rank, status FROM roles WHERE role_key = ?');
        $statement->execute([$key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<string> */
    private function rolePermissions(int $roleId): array
    {
        $statement = $this->pdo->prepare('SELECT p.permission_key FROM role_permissions rp INNER JOIN permissions p ON p.permission_id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.permission_key');
        $statement->execute([$roleId]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    private function activeRoleKeys(): array
    {
        return array_map('strval', $this->pdo->query("SELECT role_key FROM roles WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array{employee_uuid: string, username_normalized: string, display_name: string, status: string, is_root: int}> */
    private function identities(): array
    {
        return array_map(static fn (array $row): array => [
            'employee_uuid' => (string) $row['employee_uuid'],
            'username_normalized' => (string) $row['username_normalized'],
            'display_name' => (string) $row['display_name'],
            'status' => (string) $row['status'],
            'is_root' => (int) $row['is_root'],
        ], $this->pdo->query(
            'SELECT e.employee_uuid, e.username_normalized, e.display_name, e.status, (r.employee_uuid IS NOT NULL) AS is_root
             FROM employees e LEFT JOIN system_root_identity r ON r.employee_uuid = e.employee_uuid ORDER BY e.username_normalized',
        )->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{employee_uuid: string, username_normalized: string, display_name: string, status: string, is_root: int} */
    private function identityByUsername(string $username): array
    {
        foreach ($this->identities() as $identity) {
            if ($identity['username_normalized'] === mb_strtolower(trim($username))) {
                return $identity;
            }
        }
        throw new RuntimeException('Unknown username.');
    }

    /** @return array{applications: list<string>, roles: list<string>, stages: list<string>, documentScopes: array{operate: list<string>, approve: list<string>}, manager: string|null} */
    private function access(string $uuid): array
    {
        $column = function (string $sql) use ($uuid): array {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$uuid]);
            return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        };
        $manager = $this->pdo->prepare('SELECT m.username_normalized FROM employees e INNER JOIN employees m ON m.employee_uuid = e.manager_employee_uuid WHERE e.employee_uuid = ?');
        $manager->execute([$uuid]);
        $managerName = $manager->fetchColumn();
        return [
            'applications' => $column('SELECT application_key FROM employee_application_access WHERE employee_uuid = ? ORDER BY application_key'),
            'roles' => $column('SELECT r.role_key FROM employee_role_assignments era INNER JOIN roles r ON r.role_id = era.role_id WHERE era.employee_uuid = ? ORDER BY r.role_key'),
            'stages' => $column('SELECT stage_id FROM employee_stage_access WHERE employee_uuid = ? ORDER BY stage_id'),
            'documentScopes' => (new DocumentScopePolicy($this->pdo))->scopesOf($uuid),
            'manager' => $managerName === false ? null : (string) $managerName,
        ];
    }
}
