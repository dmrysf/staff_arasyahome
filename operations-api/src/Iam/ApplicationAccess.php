<?php

declare(strict_types=1);

namespace Arasya\Operations\Iam;

/**
 * Server-defined permissions that come with access to an application. Access itself is granted only
 * through employee_application_access; roles add management permissions on top of these baselines.
 */
final class ApplicationAccess
{
    public const STAFF = 'staff';
    public const DASHBOARD = 'dashboard';

    /** @var array<string, list<string>> */
    public const BASELINE_PERMISSIONS = [
        self::STAFF => [
            'staff.access',
            'orders.scan',
            'orders.view_mine',
            'orders.claim',
            'orders.advance_stage',
            'orders.handover',
            'history.view_mine',
            'profile.view_self',
        ],
        self::DASHBOARD => [
            'dashboard.access',
            'dashboard.overview.view',
            'profile.view_self',
        ],
    ];

    /**
     * Staff production permissions are only usable inside the Staff application, even when a role
     * also carries them, so a Dashboard-only identity can never drive production through the API.
     *
     * @var array<string, string>
     */
    public const PERMISSION_APPLICATION = [
        'orders.scan' => self::STAFF,
        'orders.view_mine' => self::STAFF,
        'orders.claim' => self::STAFF,
        'orders.advance_stage' => self::STAFF,
        'orders.handover' => self::STAFF,
        'history.view_mine' => self::STAFF,
    ];

    /** @param list<string> $applications @return list<string> */
    public static function baselineFor(array $applications): array
    {
        $permissions = [];
        foreach ($applications as $application) {
            foreach (self::BASELINE_PERMISSIONS[$application] ?? [] as $permission) {
                $permissions[$permission] = true;
            }
        }
        return array_keys($permissions);
    }
}
