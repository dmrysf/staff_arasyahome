<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
final class ProductionAccess
{
    public const VIEW='b2b.production.view';
    public const SUBMIT='b2b.production.submit';
    public const ALL=[self::VIEW,self::SUBMIT];
    public static function require(AuthorizationService $auth,EmployeeIdentity $actor,string $permission): void
    {
        OrderAccess::require($auth,$actor,OrderAccess::VIEW);
        $auth->require($actor,$permission);
    }
    public static function granted(AuthorizationService $auth,EmployeeIdentity $actor): array
    {
        return array_values(array_filter(self::ALL,static fn($p)=>$auth->can($actor,$p)));
    }
}
