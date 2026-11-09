<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Account;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Finance\FinanceAccess as A;
use Arasya\Operations\Finance\FinanceInput as In;
use Arasya\Operations\Finance\FinanceStore;
use Arasya\Operations\Http\ApiException;
use PDO;

/** Current-account onboarding read models: the queue of verified companies and one account's reconciliation view. */
final readonly class OnboardingQueries
{
    public const STATUSES = ['NOT_OPENED', 'NO_INFORMATION', 'AWAITING_RECONCILIATION', 'DISPUTED', 'SUBMITTED', 'VERIFIED', 'RECONCILED'];

    public function __construct(private FinanceStore $store, private OnboardingService $service)
    {
    }

    /** Verified (approved) canonical companies with their onboarding state; NOT_OPENED when none exists yet. */
    public function list(EmployeeIdentity $actor, array $q): array
    {
        A::require($this->store->authorization(), $actor, A::ACCOUNTS_VIEW);
        In::only($q, ['status', 'currency', 'q', 'cursor', 'limit']);
        $where = ["EXISTS (SELECT 1 FROM finance_company_cases c WHERE c.company_uuid = b.company_uuid AND c.status = 'APPROVED')"];
        $params = [];
        if (isset($q['status'])) {
            if (!in_array($q['status'], self::STATUSES, true)) {
                In::fail('status', 'invalid');
            }
            if ($q['status'] === 'NOT_OPENED') {
                $where[] = 'o.onboarding_uuid IS NULL';
            } else {
                $where[] = 'o.status = ?';
                $params[] = $q['status'];
            }
        }
        if (isset($q['currency'])) {
            if (!in_array($q['currency'], ['RON', 'EUR'], true)) {
                In::fail('currency', 'invalid');
            }
            $where[] = "(o.account_currency = ? OR (o.onboarding_uuid IS NULL AND (b.country_code = 'RO') = (? = 'RON')))";
            array_push($params, $q['currency'], $q['currency']);
        }
        if (isset($q['q'])) {
            $term = trim((string) $q['q']);
            if (mb_strlen($term) < 2 || mb_strlen($term) > 120) {
                In::fail('q', 'invalid');
            }
            $where[] = '(b.legal_name LIKE ? OR b.company_code = ? OR b.tax_identifier_normalized = ?)';
            array_push($params, '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%', strtoupper($term), strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $term)));
        }
        $cursor = (string) ($q['cursor'] ?? '');
        if ($cursor !== '') {
            if (preg_match('/^B2B-\d{6,12}$/D', $cursor) !== 1) {
                throw new ApiException(400, 'INVALID_CURSOR', 'Invalid cursor.');
            }
            $where[] = 'b.company_code > ?';
            $params[] = $cursor;
        }
        $limit = (int) ($q['limit'] ?? 50);
        if (!in_array($limit, [25, 50, 100], true)) {
            In::fail('limit', 'invalid');
        }
        $s = $this->store->pdo()->prepare('SELECT b.company_uuid, b.company_code, b.legal_name, b.country_code, b.status AS company_status, o.onboarding_uuid, o.status, o.account_currency,
                o.balance_kind, o.amount, o.as_of_date, o.currency_issue, o.updated_at, o.version
            FROM b2b_companies b LEFT JOIN finance_account_onboardings o ON o.company_uuid = b.company_uuid
            WHERE ' . implode(' AND ', $where) . ' ORDER BY b.company_code LIMIT ' . ($limit + 1));
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) > $limit ? (string) $rows[$limit - 1]['company_code'] : null;
        $rows = array_slice($rows, 0, $limit);
        return ['items' => array_map(static fn (array $r): array => [
            'companyId' => $r['company_uuid'], 'companyCode' => $r['company_code'], 'legalName' => $r['legal_name'], 'countryCode' => $r['country_code'], 'companyStatus' => $r['company_status'],
            'onboardingId' => $r['onboarding_uuid'], 'status' => $r['status'] ?? 'NOT_OPENED',
            'accountCurrency' => $r['account_currency'] ?? ($r['country_code'] === 'RO' ? 'RON' : 'EUR'),
            'balanceKind' => $r['balance_kind'], 'amount' => $r['amount'], 'asOfDate' => $r['as_of_date'], 'currencyIssue' => $r['currency_issue'],
            'updatedAt' => FinanceStore::iso($r['updated_at']), 'version' => $r['version'] === null ? null : (int) $r['version'],
        ], $rows), 'nextCursor' => $next, 'postingEnabled' => $this->service->postingEnabled()];
    }

    public function detail(EmployeeIdentity $actor, string $id): array
    {
        A::require($this->store->authorization(), $actor, A::ACCOUNTS_VIEW);
        if (!In::isUuid($id)) {
            throw new ApiException(404, 'ONBOARDING_NOT_FOUND', 'Onboarding was not found.');
        }
        $pdo = $this->store->pdo();
        $s = $pdo->prepare('SELECT o.*, b.company_code, b.legal_name, b.country_code AS company_country, b.status AS company_status,
                pe.display_name AS proposed_name, ve.display_name AS verified_name, po.display_name AS posted_name, m.movement_code
            FROM finance_account_onboardings o JOIN b2b_companies b ON b.company_uuid = o.company_uuid
            LEFT JOIN employees pe ON pe.employee_uuid = o.proposed_by_employee_uuid LEFT JOIN employees ve ON ve.employee_uuid = o.verified_by_employee_uuid
            LEFT JOIN employees po ON po.employee_uuid = o.posted_by_employee_uuid LEFT JOIN b2b_account_movements m ON m.movement_uuid = o.posted_movement_uuid
            WHERE o.onboarding_uuid = ?');
        $s->execute([$id]);
        $o = $s->fetch(PDO::FETCH_ASSOC);
        if (!$o) {
            throw new ApiException(404, 'ONBOARDING_NOT_FOUND', 'Onboarding was not found.');
        }
        // Source references: what imports declared for this company. Informational only, never posted.
        $s = $pdo->prepare('SELECT case_code, source_system, declared_currency, declared_debit, declared_credit, last_transaction_date, legacy_customer_code, status
            FROM finance_company_cases WHERE company_uuid = ? AND (declared_debit IS NOT NULL OR declared_credit IS NOT NULL) ORDER BY created_at LIMIT 50');
        $s->execute([$o['company_uuid']]);
        $declared = array_map(static fn (array $r): array => ['caseCode' => $r['case_code'], 'source' => $r['source_system'], 'currency' => $r['declared_currency'],
            'debit' => $r['declared_debit'], 'credit' => $r['declared_credit'], 'lastTransactionDate' => $r['last_transaction_date'], 'legacyCustomerCode' => $r['legacy_customer_code'],
            'caseStatus' => $r['status'], 'currencyMatches' => $r['declared_currency'] === null ? null : $r['declared_currency'] === $o['account_currency']], $s->fetchAll(PDO::FETCH_ASSOC));
        $auth = $this->store->authorization();
        $actions = [];
        $mine = $o['proposed_by_employee_uuid'] === $actor->employeeUuid;
        if (A::can($auth, $actor, A::ACCOUNTS_PROPOSE) && in_array($o['status'], ['NO_INFORMATION', 'AWAITING_RECONCILIATION', 'DISPUTED'], true)) {
            $actions[] = 'draft';
        }
        if (A::can($auth, $actor, A::ACCOUNTS_PROPOSE) && $o['status'] === 'AWAITING_RECONCILIATION' && $o['balance_kind'] !== null) {
            $actions[] = 'submit';
        }
        if (A::can($auth, $actor, A::ACCOUNTS_VERIFY) && $o['status'] === 'SUBMITTED' && !$mine) {
            array_push($actions, 'verify', 'return');
        }
        if (A::can($auth, $actor, A::ACCOUNTS_VERIFY) && $o['status'] === 'VERIFIED') {
            $actions[] = 'return';
        }
        if (A::can($auth, $actor, A::ACCOUNTS_POST) && $o['status'] === 'VERIFIED' && !$mine && $this->service->postingEnabled()) {
            $actions[] = 'post';
        }
        if (A::can($auth, $actor, A::ACCOUNTS_RECONCILE)) {
            if (in_array($o['status'], ['NO_INFORMATION', 'AWAITING_RECONCILIATION', 'SUBMITTED'], true)) {
                $actions[] = 'dispute';
            }
            if ($o['currency_issue'] === 'open') {
                $actions[] = 'resolve_currency_issue';
            }
            if (in_array($o['status'], ['RECONCILED', 'VERIFIED'], true)) {
                $actions[] = 'reopen';
            }
            if ($o['company_country'] !== $o['country_code']) {
                $actions[] = 'correct_currency';
            }
        }
        return [
            'onboarding' => [
                'id' => $o['onboarding_uuid'], 'companyId' => $o['company_uuid'], 'companyCode' => $o['company_code'], 'legalName' => $o['legal_name'], 'companyStatus' => $o['company_status'],
                'countryCode' => $o['country_code'], 'companyCountryNow' => $o['company_country'], 'accountCurrency' => $o['account_currency'], 'status' => $o['status'],
                'balanceKind' => $o['balance_kind'], 'amount' => $o['amount'], 'asOfDate' => $o['as_of_date'], 'overlapResolution' => $o['overlap_resolution'],
                'currencyIssue' => $o['currency_issue'], 'currencyIssueNote' => $o['currency_issue_note'], 'note' => $o['proposal_note'], 'disputeReason' => $o['dispute_reason'],
                'proposedBy' => $o['proposed_name'], 'proposedAt' => FinanceStore::iso($o['proposed_at']), 'verifiedBy' => $o['verified_name'], 'verifiedAt' => FinanceStore::iso($o['verified_at']),
                'postedBy' => $o['posted_name'], 'postedAt' => FinanceStore::iso($o['posted_at']), 'postedMovementCode' => $o['movement_code'], 'cycle' => (int) $o['cycle_no'],
                'version' => (int) $o['version'], 'proposedByMe' => $mine,
            ],
            'ledger' => $this->service->ledgerView((string) $o['company_uuid'], (string) $o['account_currency'], $o['as_of_date']),
            'declared' => $declared,
            'evidence' => $this->store->evidenceOf('onboarding', $id),
            'history' => $this->store->history('onboarding', $id),
            'actions' => $actions,
            'postingEnabled' => $this->service->postingEnabled(),
        ];
    }
}
