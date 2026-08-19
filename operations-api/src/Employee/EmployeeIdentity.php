<?php

declare(strict_types=1);

namespace Arasya\Operations\Employee;

final readonly class EmployeeIdentity
{
    /** @param list<string> $permissions @param list<string> $allowedStageIds */
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
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOperationallyActive(): bool
    {
        return $this->status === 'active'
            && $this->roleStatus === 'active'
            && $this->departmentStatus === 'active';
    }

    public function inactiveReason(): ?string
    {
        if ($this->status !== 'active') {
            return 'employee_inactive';
        }
        if ($this->roleStatus !== 'active') {
            return 'role_inactive';
        }
        if ($this->departmentStatus !== 'active') {
            return 'department_inactive';
        }
        return null;
    }
}
