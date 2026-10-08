<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use RuntimeException;

/**
 * The owner-authorized onboarding plan (database/reference/organization-onboarding.json) on top of the
 * membership roster (organization-roster.json).
 *
 * The roster says where each person belongs. The plan adds what the owner explicitly authorized: one stable
 * username per person, confirmed reporting lines, department oversight, and the only applications, roles,
 * document scopes and principal designation to grant. Validation is strict and fails closed: an unknown field
 * (for example production stages), a username that does not follow the naming rule, a reporting cycle, an
 * active identity without an application, access for an excluded person or a blocked application (B2B for
 * the DR7/DR9 shops) rejects the whole plan.
 */
final class OrganizationOnboarding
{
    public const APPLICATIONS = ['b2b', 'dashboard', 'staff'];
    private const PERSON_FIELDS = ['name', 'username', 'status', 'manager', 'applications', 'roles', 'documentScopes', 'principal'];
    private const ROLE_KEY = '/^[a-z][a-z0-9-]{1,47}$/D';
    private const SOURCE_KEY = '/^[a-z0-9][a-z0-9-]{0,39}$/D';

    /**
     * @param array<string, array<string, mixed>> $people by roster name, roster membership merged in
     * @param array<string, array<string, mixed>> $departments by roster key
     * @param array<string, array<string, mixed>> $roles custom roles by key
     * @param array<string, array{applications: list<string>, reason: string}> $blocked
     * @param list<string> $pending
     */
    private function __construct(
        private OrganizationRoster $roster,
        private array $people,
        private array $departments,
        private array $roles,
        private array $blocked,
        private array $pending,
    ) {
    }

    public static function fromFiles(string $planPath, string $rosterPath): self
    {
        $plan = file_get_contents($planPath);
        $roster = file_get_contents($rosterPath);
        if ($plan === false || $roster === false) {
            throw new RuntimeException('The onboarding plan or the roster is not readable.');
        }
        return self::fromArrays(json_decode($plan, true, 16, JSON_THROW_ON_ERROR), json_decode($roster, true, 16, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $roster */
    public static function fromArrays(array $plan, array $roster): self
    {
        $membership = OrganizationRoster::fromArray($roster);
        $rosterPeople = [];
        foreach ($roster['people'] as $person) {
            $rosterPeople[(string) $person['name']] = $person;
        }
        $rosterDepartments = [];
        foreach ($roster['departments'] as $department) {
            $rosterDepartments[(string) $department['key']] = $department;
        }

        $roles = [];
        foreach ($plan['roles'] ?? [] as $key => $role) {
            if (!is_string($key) || preg_match(self::ROLE_KEY, $key) !== 1 || !is_array($role)) {
                throw new RuntimeException("Invalid custom role key: {$key}");
            }
            $permissions = $role['permissions'] ?? null;
            $rank = $role['authorityRank'] ?? null;
            if (!is_string($role['name'] ?? null) || !is_int($rank) || $rank < 1 || $rank >= 900 || !is_array($permissions) || $permissions === []) {
                throw new RuntimeException("Custom role {$key} needs a name, a rank between 1 and 899 and permissions.");
            }
            foreach ($permissions as $permission) {
                if (!is_string($permission) || str_ends_with($permission, '.access') || preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/D', $permission) !== 1) {
                    throw new RuntimeException("Custom role {$key} lists an invalid permission (application access is never a role permission).");
                }
            }
            if (count(array_unique($permissions)) !== count($permissions)) {
                throw new RuntimeException("Custom role {$key} repeats a permission.");
            }
            sort($permissions);
            $roles[$key] = ['key' => $key, 'name' => $role['name'], 'description' => $role['description'] ?? null, 'authorityRank' => $rank, 'permissions' => $permissions];
        }

        $departments = [];
        foreach ($rosterDepartments as $key => $department) {
            $departments[$key] = ['key' => $key, 'name' => (string) $department['name'], 'location' => $department['location'] ?? null, 'parent' => null, 'description' => null];
        }
        foreach ($plan['departments'] ?? [] as $key => $settings) {
            if (!isset($departments[$key]) || !is_array($settings) || array_diff(array_keys($settings), ['parent', 'description']) !== []) {
                throw new RuntimeException("Invalid department settings: {$key}");
            }
            $parent = $settings['parent'] ?? null;
            if ($parent !== null && (!isset($departments[$parent]) || $parent === $key)) {
                throw new RuntimeException("Department {$key} names an unknown parent.");
            }
            $description = $settings['description'] ?? null;
            if ($description !== null && (!is_string($description) || mb_strlen($description) > 255)) {
                throw new RuntimeException("Department {$key} description must be text of at most 255 characters.");
            }
            $departments[$key]['parent'] = $parent;
            $departments[$key]['description'] = $description;
        }
        foreach (array_keys($departments) as $key) {
            $seen = [];
            for ($cursor = $key; $cursor !== null; $cursor = $departments[$cursor]['parent']) {
                if (isset($seen[$cursor])) {
                    throw new RuntimeException("Department hierarchy forms a cycle at {$key}.");
                }
                $seen[$cursor] = true;
            }
        }

        $blocked = [];
        foreach ($plan['blockedApplications'] ?? [] as $key => $rule) {
            $applications = $rule['applications'] ?? null;
            if (!isset($departments[$key]) || !is_array($applications) || array_diff($applications, self::APPLICATIONS) !== [] || !is_string($rule['reason'] ?? null)) {
                throw new RuntimeException("Invalid blocked application rule: {$key}");
            }
            $blocked[$key] = ['applications' => array_values($applications), 'reason' => $rule['reason']];
        }

        $people = [];
        $usernames = [];
        $principals = 0;
        foreach ($plan['people'] ?? [] as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), self::PERSON_FIELDS) !== []) {
                throw new RuntimeException('An onboarding entry has an unknown field (production stages are never part of the plan): ' . json_encode($entry, JSON_UNESCAPED_UNICODE));
            }
            $name = (string) ($entry['name'] ?? '');
            if (!isset($rosterPeople[$name]) || isset($people[$name])) {
                throw new RuntimeException("Onboarding entry is not exactly one roster person: {$name}");
            }
            $username = (string) ($entry['username'] ?? '');
            if ($username !== self::username($name) || isset($usernames[$username])) {
                throw new RuntimeException("Username for {$name} must be the unique name-based username " . self::username($name));
            }
            $usernames[$username] = $name;
            $status = $entry['status'] ?? null;
            $applications = self::list($entry['applications'] ?? [], $name, 'applications');
            $personRoles = self::list($entry['roles'] ?? [], $name, 'roles');
            if (!in_array($status, ['active', 'inactive'], true) || array_diff($applications, self::APPLICATIONS) !== []) {
                throw new RuntimeException("Invalid status or application for {$name}.");
            }
            if (($status === 'active') !== ($applications !== [])) {
                throw new RuntimeException("{$name}: an identity is active exactly when an application is authorized.");
            }
            foreach ($personRoles as $role) {
                if (preg_match(self::ROLE_KEY, $role) !== 1) {
                    throw new RuntimeException("{$name}: invalid role key {$role}.");
                }
            }
            $scopes = ['operate' => [], 'approve' => []];
            if (isset($entry['documentScopes'])) {
                if (!is_array($entry['documentScopes']) || array_keys($entry['documentScopes']) !== ['operate', 'approve']) {
                    throw new RuntimeException("{$name}: document scopes state operate and approve, in that order.");
                }
                foreach (['operate', 'approve'] as $capability) {
                    $scopes[$capability] = self::list($entry['documentScopes'][$capability], $name, $capability);
                    foreach ($scopes[$capability] as $source) {
                        if (preg_match(self::SOURCE_KEY, $source) !== 1) {
                            throw new RuntimeException("{$name}: invalid source key {$source}.");
                        }
                    }
                }
            }
            $principal = $entry['principal'] ?? null;
            if ($principal !== null) {
                if ($principal !== 'ceo' || ++$principals > 1 || !in_array('dashboard', $applications, true)) {
                    throw new RuntimeException("{$name}: only one CEO principal, with Dashboard access.");
                }
            }
            if (($personRoles !== [] || $scopes !== ['operate' => [], 'approve' => []]) && $status !== 'active') {
                throw new RuntimeException("{$name}: roles and scopes are granted only to an active identity.");
            }
            $rosterPerson = $rosterPeople[$name];
            $excluded = ($rosterPerson['rollout'] ?? null) === 'excluded';
            if ($excluded && ($status !== 'inactive' || $personRoles !== [] || $principal !== null)) {
                throw new RuntimeException("{$name} is excluded from the rollout and receives no access.");
            }
            $memberships = [$rosterPerson['department'], ...($rosterPerson['additionalDepartments'] ?? [])];
            foreach ($memberships as $department) {
                $refused = array_intersect($applications, $blocked[$department]['applications'] ?? []);
                if ($refused !== []) {
                    throw new RuntimeException("{$name}: application " . implode(', ', $refused) . " is blocked for {$department}: {$blocked[$department]['reason']}");
                }
            }
            $people[$name] = [
                'name' => $name,
                'username' => $username,
                'status' => $status,
                'manager' => isset($entry['manager']) ? (string) $entry['manager'] : null,
                'applications' => $applications,
                'roles' => $personRoles,
                'documentScopes' => $scopes,
                'principal' => $principal,
                'department' => (string) $rosterPerson['department'],
                'title' => $rosterPerson['title'] ?? null,
                'additionalDepartments' => array_values($rosterPerson['additionalDepartments'] ?? []),
                'excluded' => $excluded,
            ];
        }
        if (count($people) !== count($rosterPeople)) {
            throw new RuntimeException('The onboarding plan must list every roster person exactly once (' . count($people) . ' of ' . count($rosterPeople) . ').');
        }
        foreach ($people as $name => $person) {
            if ($person['manager'] === null) {
                continue;
            }
            if (!isset($people[$person['manager']]) || $person['manager'] === $name) {
                throw new RuntimeException("{$name}: the manager must be another roster person.");
            }
            $seen = [$name => true];
            for ($cursor = $person['manager']; $cursor !== null; $cursor = $people[$cursor]['manager']) {
                if (isset($seen[$cursor])) {
                    throw new RuntimeException("Reporting lines form a cycle at {$name}.");
                }
                $seen[$cursor] = true;
            }
        }
        $pending = array_values(array_filter($plan['pending'] ?? [], 'is_string'));
        return new self($membership, $people, $departments, $roles, $blocked, $pending);
    }

    /** The name-based username: every name part, lowercase ASCII, joined by dots ("PARASCHIV STANICA-LUCIAN" -> "paraschiv.stanica.lucian"). */
    public static function username(string $name): string
    {
        $map = ['Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T', 'Ç' => 'C', 'Ğ' => 'G', 'İ' => 'I', 'Ö' => 'O', 'Ü' => 'U', 'Ä' => 'A', 'É' => 'E'];
        $ascii = strtolower(strtr(mb_strtoupper(trim($name)), $map));
        return trim((string) preg_replace('/[^a-z0-9]+/', '.', $ascii), '.');
    }

    public function roster(): OrganizationRoster
    {
        return $this->roster;
    }

    /** @return array<string, array<string, mixed>> */
    public function people(): array
    {
        return $this->people;
    }

    /** @return array<string, array<string, mixed>> */
    public function departments(): array
    {
        return $this->departments;
    }

    /** @return array<string, array<string, mixed>> */
    public function roles(): array
    {
        return $this->roles;
    }

    /** @return array<string, array{applications: list<string>, reason: string}> */
    public function blockedApplications(): array
    {
        return $this->blocked;
    }

    /** @return list<string> */
    public function pending(): array
    {
        return $this->pending;
    }

    /** @return list<string> sorted, unique strings */
    private static function list(mixed $value, string $name, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException("{$name}: {$field} must be a list.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new RuntimeException("{$name}: {$field} must contain text values.");
            }
        }
        $sorted = $value;
        sort($sorted);
        if (count(array_unique($sorted)) !== count($sorted)) {
            throw new RuntimeException("{$name}: {$field} repeats a value.");
        }
        return $sorted;
    }
}
