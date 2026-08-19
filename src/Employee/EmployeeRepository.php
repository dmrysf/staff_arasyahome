<?php

declare(strict_types=1);

namespace Arasya\Operations\Employee;

interface EmployeeRepository
{
    public function findByNormalizedUsername(string $usernameNormalized): ?EmployeeIdentity;

    public function findByUuid(string $employeeUuid): ?EmployeeIdentity;

    /** @param list<string> $allowedStageIds */
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
    ): EmployeeIdentity;

    public function updateStatus(string $employeeUuid, string $status, string $now): bool;

    public function updatePasswordHash(string $employeeUuid, string $passwordHash, string $now): bool;

    public function markLogin(string $employeeUuid, string $now): void;
}

