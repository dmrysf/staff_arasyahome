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
        public string $roleKey,
        public string $status,
        public array $permissions,
        public array $allowedStageIds,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

