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
    public const B2B = 'b2b';

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
            'orders.report_fault',
            'orders.acknowledge_fault',
            'profile.view_self',
        ],
        self::DASHBOARD => [
            'dashboard.access',
            'dashboard.overview.view',
            'profile.view_self',
        ],
        self::B2B => [
            'b2b.access',
            'profile.view_self',
        ],
    ];

    /**
     * Staff production permissions are only usable inside the Staff application, even when a role
     * also carries them, so a Dashboard-only identity can never drive production through the API.
     * Likewise the B2B company permissions are only usable with B2B application access.
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
        'orders.report_fault' => self::STAFF,
        'orders.acknowledge_fault' => self::STAFF,
        'production.exceptions.approve' => self::DASHBOARD,
        'production.manage_authority' => self::DASHBOARD,
        'orders.lookup_exact' => self::DASHBOARD,
        'b2b.companies.view' => self::B2B,
        'b2b.companies.create' => self::B2B,
        'b2b.companies.update' => self::B2B,
        'b2b.companies.manage_status' => self::B2B,
        'b2b.orders.view' => self::B2B,
        'b2b.orders.create' => self::B2B,
        'b2b.orders.update' => self::B2B,
        'b2b.orders.manage_status' => self::B2B,
        'b2b.accounts.view' => self::B2B,
        'b2b.accounts.record_payment' => self::B2B,
        'b2b.accounts.adjust' => self::B2B,
        'b2b.accounts.reverse' => self::B2B,
        'b2b.accounts.export' => self::B2B,
        'b2b.production.view' => self::B2B,
        'b2b.production.submit' => self::B2B,
        'b2b.projects.view' => self::B2B,
        'b2b.projects.create' => self::B2B,
        'b2b.projects.update' => self::B2B,
        'b2b.projects.archive' => self::B2B,
        'b2b.projects.convert' => self::B2B,
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
