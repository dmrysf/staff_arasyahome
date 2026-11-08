<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Iam\ApplicationAccess;

/**
 * Finance authorization: the Finance application grant plus finance.access (never role-grantable) plus one exact
 * operation permission. Staff and B2B permissions never authorize anything here. Every check reads the identity
 * loaded for the current request, so a revoked grant stops the very next request.
 */
final class FinanceAccess
{
    public const ACCESS = 'finance.access';

    public const COMPANIES_VIEW = 'finance.companies.view';
    public const COMPANIES_IMPORT = 'finance.companies.import';
    public const COMPANIES_REVIEW = 'finance.companies.review';
    public const COMPANIES_VERIFY = 'finance.companies.verify';
    public const COMPANIES_RESOLVE_DUPLICATES = 'finance.companies.resolve_duplicates';
    public const COMPANIES_SUPERVISE = 'finance.companies.supervise';

    public const ACCOUNTS_VIEW = 'finance.accounts.view';
    public const ACCOUNTS_RECONCILE = 'finance.accounts.reconcile';
    public const ACCOUNTS_PROPOSE = 'finance.accounts.propose_opening_balance';
    public const ACCOUNTS_VERIFY = 'finance.accounts.verify_opening_balance';
    public const ACCOUNTS_POST = 'finance.accounts.post_approved_opening_balance';

    public const RISK_VIEW = 'finance.risk.view';
    public const RISK_PROPOSE = 'finance.risk.propose';
    public const RISK_APPROVE = 'finance.risk.approve';

    public const CREDIT_VIEW = 'finance.credit.view';
    public const CREDIT_PROPOSE = 'finance.credit.propose';
    public const CREDIT_VERIFY = 'finance.credit.verify';
    public const CREDIT_APPROVE = 'finance.credit.approve';
    public const CREDIT_REVOKE = 'finance.credit.revoke';

    public const AUDIT_VIEW = 'finance.audit.view';
    public const AUDIT_SENSITIVE = 'finance.audit.sensitive';

    /** Capability names returned to the client (the server still checks every request). */
    public const CAPABILITIES = [
        'canViewCompanies' => self::COMPANIES_VIEW,
        'canImportCompanies' => self::COMPANIES_IMPORT,
        'canReviewCompanies' => self::COMPANIES_REVIEW,
        'canVerifyCompanies' => self::COMPANIES_VERIFY,
        'canResolveDuplicates' => self::COMPANIES_RESOLVE_DUPLICATES,
        'canSuperviseCompanies' => self::COMPANIES_SUPERVISE,
        'canViewAccounts' => self::ACCOUNTS_VIEW,
        'canReconcileAccounts' => self::ACCOUNTS_RECONCILE,
        'canProposeOpeningBalance' => self::ACCOUNTS_PROPOSE,
        'canVerifyOpeningBalance' => self::ACCOUNTS_VERIFY,
        'canPostOpeningBalance' => self::ACCOUNTS_POST,
        'canViewRisk' => self::RISK_VIEW,
        'canProposeRisk' => self::RISK_PROPOSE,
        'canApproveRisk' => self::RISK_APPROVE,
        'canViewCredit' => self::CREDIT_VIEW,
        'canProposeCredit' => self::CREDIT_PROPOSE,
        'canVerifyCredit' => self::CREDIT_VERIFY,
        'canApproveCredit' => self::CREDIT_APPROVE,
        'canRevokeCredit' => self::CREDIT_REVOKE,
        'canViewAudit' => self::AUDIT_VIEW,
        'canViewSensitiveAudit' => self::AUDIT_SENSITIVE,
    ];

    public static function gate(AuthorizationService $authorization, EmployeeIdentity $actor): void
    {
        $authorization->requireApplication($actor, ApplicationAccess::FINANCE);
        $authorization->require($actor, self::ACCESS);
    }

    public static function require(AuthorizationService $authorization, EmployeeIdentity $actor, string ...$permissions): void
    {
        self::gate($authorization, $actor);
        foreach ($permissions as $permission) {
            $authorization->require($actor, $permission);
        }
    }

    public static function can(AuthorizationService $authorization, EmployeeIdentity $actor, string $permission): bool
    {
        return $authorization->can($actor, self::ACCESS) && $authorization->can($actor, $permission);
    }

    /** @return array<string, bool> */
    public static function capabilities(AuthorizationService $authorization, EmployeeIdentity $actor): array
    {
        $out = [];
        foreach (self::CAPABILITIES as $name => $permission) {
            $out[$name] = self::can($authorization, $actor, $permission);
        }
        return $out;
    }
}
