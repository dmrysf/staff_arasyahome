<?php

declare(strict_types=1);

namespace Arasya\Operations\Authorization;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\ApplicationAccess;

/**
 * Every decision reads the identity loaded for this request, so application access, role, stage and
 * status changes apply on the very next protected request without any cache to expire.
 */
final class AuthorizationService
{
    public function can(EmployeeIdentity $employee, string $permission): bool
    {
        if (!$employee->isOperationallyActive() || $employee->mustChangePassword) {
            return false;
        }
        $application = ApplicationAccess::PERMISSION_APPLICATION[$permission] ?? null;
        if ($application !== null && !$employee->hasApplication($application)) {
            return false;
        }
        return in_array($permission, $employee->permissions, true);
    }

    public function require(EmployeeIdentity $employee, string $permission): void
    {
        $this->requireUsable($employee);
        $application = ApplicationAccess::PERMISSION_APPLICATION[$permission] ?? null;
        if ($application !== null) {
            $this->requireApplication($employee, $application);
        }
        if (!$this->can($employee, $permission)) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');
        }
    }

    public function requireApplication(EmployeeIdentity $employee, string $application): void
    {
        $this->requireUsable($employee);
        if (!$employee->hasApplication($application)) {
            throw new ApiException(403, 'APPLICATION_ACCESS_DENIED', 'The account has no access to this application.');
        }
    }

    /** A temporary password allows only the session and password-change endpoints. */
    private function requireUsable(EmployeeIdentity $employee): void
    {
        if (!$employee->isOperationallyActive()) {
            throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Account is not active.');
        }
        if ($employee->mustChangePassword) {
            throw new ApiException(403, 'PASSWORD_CHANGE_REQUIRED', 'The password must be changed before continuing.');
        }
    }
}
