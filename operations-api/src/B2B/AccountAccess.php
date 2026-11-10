<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Iam\ApplicationAccess;

/**
 * Current-account authorization: the B2B application gate (b2b.access) plus one narrow account permission.
 * Root holds everything through the existing root semantics. No generic admin permission exists.
 */
final class AccountAccess
{
    public const VIEW = 'b2b.accounts.view';
    public const RECORD_PAYMENT = 'b2b.accounts.record_payment';
    public const ADJUST = 'b2b.accounts.adjust';
    public const REVERSE = 'b2b.accounts.reverse';
    public const EXPORT = 'b2b.accounts.export';
    public const ALL = [self::VIEW, self::RECORD_PAYMENT, self::ADJUST, self::REVERSE, self::EXPORT];

    public static function require(AuthorizationService $authorization, EmployeeIdentity $actor, string ...$permissions): void
    {
        $authorization->requireApplication($actor, ApplicationAccess::B2B);
        $authorization->require($actor, 'b2b.access');
        foreach ($permissions as $permission) {
            $authorization->require($actor, $permission);
        }
    }

    /** What the client may offer; the server still checks every request. @return array<string, bool> */
    public static function capabilities(AuthorizationService $authorization, EmployeeIdentity $actor, bool $writesEnabled = true): array
    {
        return [
            'canView' => $authorization->can($actor, self::VIEW),
            'canRecordPayment' => $writesEnabled && $authorization->can($actor, self::RECORD_PAYMENT),
            'canAdjust' => $writesEnabled && $authorization->can($actor, self::ADJUST),
            'canReverse' => $writesEnabled && $authorization->can($actor, self::REVERSE),
            'canExport' => $authorization->can($actor, self::EXPORT),
        ];
    }

    /**
     * Permissions the B2B client may offer. With ARASYA_B2B_ACCOUNT_WRITES=finance_only (API 2.68.0) the write permissions are
     * left out, so even an older B2B release hides the buttons; the server refuses the routes either way.
     * @return list<string>
     */
    public static function granted(AuthorizationService $authorization, EmployeeIdentity $actor, bool $writesEnabled = true): array
    {
        return array_values(array_filter(self::ALL, static fn (string $permission): bool => $authorization->can($actor, $permission)
            && ($writesEnabled || in_array($permission, [self::VIEW, self::EXPORT], true))));
    }
}
