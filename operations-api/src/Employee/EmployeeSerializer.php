<?php

declare(strict_types=1);

namespace Arasya\Operations\Employee;

final class EmployeeSerializer
{
    /** @return array<string, mixed> */
    public static function safe(EmployeeIdentity $employee): array
    {
        return [
            'employeeUuid' => $employee->employeeUuid,
            'employeeCode' => $employee->employeeCode,
            'displayName' => $employee->displayName,
            'username' => $employee->username,
            'department' => $employee->departmentName,
            'departmentKey' => $employee->departmentKey,
            'role' => $employee->roleKey,
            'status' => $employee->status,
            'permissions' => $employee->permissions,
            'allowedStageIds' => $employee->allowedStageIds,
            // Granted stages narrowed to named order sources; a stage absent here reaches every source.
            'stageSourceScopes' => (object) $employee->stageSourceScopes,
            'applications' => $employee->applications,
            'roles' => $employee->roleKeys,
            'positionTitle' => $employee->positionTitle,
            'isRoot' => $employee->isRoot,
            'mustChangePassword' => $employee->mustChangePassword,
            'authorizationVersion' => $employee->authorizationVersion,
            'locale' => 'ro',
        ];
    }
}
