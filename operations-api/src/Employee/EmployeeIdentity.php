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
    ) {
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
