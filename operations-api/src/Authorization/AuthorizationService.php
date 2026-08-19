<?php

declare(strict_types=1);

namespace Arasya\Operations\Authorization;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;

final class AuthorizationService
{
    public function can(EmployeeIdentity $employee, string $permission): bool
    {
        return $employee->isOperationallyActive() && in_array($permission, $employee->permissions, true);
    }

    public function require(EmployeeIdentity $employee, string $permission): void
    {
        if (!$this->can($employee, $permission)) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');
        }
    }
}
