<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;

/**
 * Project workspace authorization: B2B application access, b2b.access and one narrow project permission.
 * Converting a scope into a commercial draft additionally needs the existing Classic order create permission.
 */
final class ProjectAccess
{
    public const VIEW='b2b.projects.view';
    public const CREATE='b2b.projects.create';
    public const UPDATE='b2b.projects.update';
    public const ARCHIVE='b2b.projects.archive';
    public const CONVERT='b2b.projects.convert';
    public const ALL=[self::VIEW,self::CREATE,self::UPDATE,self::ARCHIVE,self::CONVERT];

    public static function require(AuthorizationService $authorization,EmployeeIdentity $actor,string $permission): void
    {
        OrderAccess::require($authorization,$actor,$permission);
    }

    /** What the client may offer; the server still checks every request. @return array<string,bool> */
    public static function capabilities(AuthorizationService $authorization,EmployeeIdentity $actor): array
    {
        return [
            'canView'=>$authorization->can($actor,self::VIEW),
            'canCreate'=>$authorization->can($actor,self::CREATE),
            'canUpdate'=>$authorization->can($actor,self::UPDATE),
            'canArchive'=>$authorization->can($actor,self::ARCHIVE),
            'canConvert'=>$authorization->can($actor,self::CONVERT) && $authorization->can($actor,OrderAccess::CREATE),
        ];
    }

    /** @return list<string> */
    public static function granted(AuthorizationService $authorization,EmployeeIdentity $actor): array
    {
        return array_values(array_filter(self::ALL,static fn(string $p): bool=>$authorization->can($actor,$p)));
    }
}
