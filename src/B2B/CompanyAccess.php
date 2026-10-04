<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Iam\ApplicationAccess;

/**
 * B2B company authorization: the B2B application gate (b2b.access, unchanged) plus one narrow company permission.
 * Root holds both through the existing root semantics. The permissions are bound to the B2B application in
 * ApplicationAccess::PERMISSION_APPLICATION, so a role carrying them does nothing without B2B access.
 */
final class CompanyAccess
{
    public const VIEW = 'b2b.companies.view';
    public const CREATE = 'b2b.companies.create';
    public const UPDATE = 'b2b.companies.update';
    public const MANAGE_STATUS = 'b2b.companies.manage_status';
    public const ALL = [self::VIEW, self::CREATE, self::UPDATE, self::MANAGE_STATUS];

    public static function require(AuthorizationService $authorization, EmployeeIdentity $actor, string $permission): void
    {
        $authorization->requireApplication($actor, ApplicationAccess::B2B);
        $authorization->require($actor, 'b2b.access');
        $authorization->require($actor, $permission);
    }

    /** What the client may offer; the server still checks every request. @return array<string, bool> */
    public static function capabilities(AuthorizationService $authorization, EmployeeIdentity $actor): array
    {
        return [
            'canView' => $authorization->can($actor, self::VIEW),
            'canCreate' => $authorization->can($actor, self::CREATE),
            'canUpdate' => $authorization->can($actor, self::UPDATE),
            'canManageStatus' => $authorization->can($actor, self::MANAGE_STATUS),
        ];
    }

    /** @return list<string> */
    public static function granted(AuthorizationService $authorization, EmployeeIdentity $actor): array
    {
        return array_values(array_filter(self::ALL, static fn (string $permission): bool => $authorization->can($actor, $permission)));
    }
}
