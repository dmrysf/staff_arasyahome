<?php

declare(strict_types=1);

namespace Arasya\Operations\Employee;

final readonly class EmployeeIdentity
{
    /**
     * @param list<string> $permissions Effective permissions: application baselines, active roles, or the full catalog for root.
     * @param list<string> $allowedStageIds
     * @param list<string> $applications Active applications the identity may enter.
     * @param list<string> $roleKeys
     * @param array<string, list<string>> $stageSourceScopes Source restriction per granted stage (migration 023). A stage
     *        absent here keeps its legacy meaning (every order source); a stage present here reaches only the listed
     *        sources. Scopes never grant a stage: only stages in $allowedStageIds are considered.
     */
    public function __construct(
        public string $employeeUuid,
        public ?string $employeeCode,
        public string $username,
        public string $usernameNormalized,
        public string $passwordHash,
        public string $displayName,
        public string $departmentKey,
        public string $departmentName,
        public string $departmentStatus,
        public string $roleKey,
        public string $roleStatus,
        public string $status,
        public array $permissions,
        public array $allowedStageIds,
        public array $applications = ['staff'],
        public bool $mustChangePassword = false,
        public int $authorizationVersion = 1,
        public bool $isRoot = false,
        public ?string $positionTitle = null,
        public ?string $managerUuid = null,
        public array $roleKeys = [],
        public int $authorityRank = 0,
        public int $departmentId = 0,
        public array $stageSourceScopes = [],
    ) {
    }

    /** Whether the employee may work at the stage on orders of the source: the stage grant AND its source scope. */
    public function worksAt(string $stageId, string $sourceKey): bool
    {
        if (!in_array($stageId, $this->allowedStageIds, true)) {
            return false;
        }
        $sources = $this->stageSourceScopes[$stageId] ?? null;
        return $sources === null || in_array($sourceKey, $sources, true);
    }

    /**
     * The sources the employee reaches at a granted stage: null for every source (a legacy, unrestricted grant), a
     * list for a scoped grant, or an empty list when the stage is not granted at all.
     *
     * @return list<string>|null
     */
    public function sourcesAt(string $stageId): ?array
    {
        if (!in_array($stageId, $this->allowedStageIds, true)) {
            return [];
        }
        return $this->stageSourceScopes[$stageId] ?? null;
    }

    /**
     * The sources the employee currently works on at any granted stage: null for every source (at least one
     * unrestricted grant), the union of the scoped sources otherwise, and an empty list without any stage grant.
     * Used only to decide whether a historical order relation may still show the order; it never grants a stage.
     *
     * @return list<string>|null
     */
    public function reachableSources(): ?array
    {
        $sources = [];
        foreach (array_unique($this->allowedStageIds) as $stageId) {
            $scoped = $this->stageSourceScopes[$stageId] ?? null;
            if ($scoped === null) {
                return null;
            }
            array_push($sources, ...$scoped);
        }
        $sources = array_values(array_unique($sources));
        sort($sources);
        return $sources;
    }

    public function reachesSource(string $sourceKey): bool
    {
        $sources = $this->reachableSources();
        return $sources === null || in_array($sourceKey, $sources, true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * An identity may work when it is active and its department is active. Roles do not gate the
     * account: an inactive role only stops contributing permissions. The root identity cannot be
     * locked out through its department.
     */
    public function isOperationallyActive(): bool
    {
        return $this->status === 'active' && ($this->isRoot || $this->departmentStatus === 'active');
    }

    public function inactiveReason(): ?string
    {
        if ($this->status !== 'active') {
            return 'employee_inactive';
        }
        if (!$this->isRoot && $this->departmentStatus !== 'active') {
            return 'department_inactive';
        }
        return null;
    }

    public function hasApplication(string $applicationKey): bool
    {
        return in_array($applicationKey, $this->applications, true);
    }
}
