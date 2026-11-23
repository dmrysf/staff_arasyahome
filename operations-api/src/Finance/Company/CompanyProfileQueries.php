<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Company;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\B2B\AccountQueries;
use Arasya\Operations\B2B\FinanceOrderReadModel;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Finance\Credit\CreditService;
use Arasya\Operations\Finance\FinanceAccess as Access;
use Arasya\Operations\Finance\FinanceInput;
use Arasya\Operations\Http\ApiException;
use PDO;

final readonly class CompanyProfileQueries
{
    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private AccountQueries $ledger,
        private CreditService $credit,
    ) {
    }

    /** @return array<string, mixed> */
    public function read(EmployeeIdentity $actor, string $companyId, array $filters = []): array
    {
        Access::require($this->authorization, $actor, Access::COMPANIES_VIEW);
        FinanceInput::only($filters, []);
        if (!FinanceInput::isUuid($companyId)) {
            throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
        }
        $query = $this->pdo->prepare(
            "SELECT c.company_uuid, c.company_code, c.legal_name, c.display_name, c.country_code, c.status, c.tax_identifier,
                    o.account_currency, o.status AS reconciliation_status, r.classification,
                    (SELECT f.status FROM finance_company_cases f WHERE f.company_uuid = c.company_uuid
                     ORDER BY f.status = 'APPROVED' DESC, f.updated_at DESC LIMIT 1) AS verification
             FROM b2b_companies c
             LEFT JOIN finance_account_onboardings o ON o.company_uuid = c.company_uuid
             LEFT JOIN finance_risk_assessments r ON r.active_company_uuid = c.company_uuid
             WHERE c.company_uuid = ?"
        );
        $query->execute([$companyId]);
        $company = $query->fetch(PDO::FETCH_ASSOC);
        if ($company === false) {
            throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
        }

        $canViewCredit = Access::can($this->authorization, $actor, Access::CREDIT_VIEW);
        $canViewRisk = $canViewCredit || Access::can($this->authorization, $actor, Access::RISK_VIEW);
        $credit = $canViewCredit ? $this->credit->profile($actor, $companyId) : null;
        $profile = [
            'company' => [
                'id' => $company['company_uuid'],
                'code' => $company['company_code'],
                'legalName' => $company['legal_name'],
                'displayName' => $company['display_name'],
                'countryCode' => $company['country_code'],
                'status' => $company['status'],
                'taxIdentifier' => $company['tax_identifier'],
            ],
            'verificationStatus' => match ($company['verification']) {
                null => 'NOT_STARTED',
                'APPROVED' => 'VERIFIED',
                'REJECTED' => 'REJECTED',
                default => 'IN_PROGRESS',
            },
            'account' => null,
            'riskColor' => $canViewRisk ? ($credit['riskColor'] ?? $company['classification'] ?? 'UNVERIFIED') : null,
            'credit' => $credit === null ? null : [
                'status' => $credit['creditLimitStatus'],
                'amount' => $credit['approvedCreditLimit'],
                'currency' => $credit['creditLimitCurrency'],
            ],
        ];
        if (Access::can($this->authorization, $actor, Access::ACCOUNTS_VIEW)) {
            $currency = $company['account_currency'];
            $totals = $currency === null ? null : $this->ledger->summary($actor, $companyId)['currencies'][$currency] ?? null;
            $profile['account'] = [
                'currency' => $currency,
                'reconciliationStatus' => $company['reconciliation_status'] ?? 'NOT_OPENED',
                'balance' => $totals['balance'] ?? null,
                'outstandingReceivables' => $totals['outstandingReceivables'] ?? null,
            ];
            $profile['lastOrder'] = (new FinanceOrderReadModel($this->pdo, $this->authorization))->latest($actor, $companyId);
        }
        return $profile;
    }
}
