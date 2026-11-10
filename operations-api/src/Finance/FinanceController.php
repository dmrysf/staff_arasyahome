<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance;

use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Finance\Account\OnboardingQueries;
use Arasya\Operations\Finance\Account\OnboardingService;
use Arasya\Operations\Finance\Company\CaseQueries;
use Arasya\Operations\Finance\Company\CaseService;
use Arasya\Operations\Finance\Credit\CreditService;
use Arasya\Operations\Finance\Import\ImportService;
use Arasya\Operations\Finance\Risk\OwnerDecisionService;
use Arasya\Operations\Finance\Risk\RiskService;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Iam\ApplicationAccess;
use Arasya\Operations\Security\ApiRateLimiter;
use Arasya\Operations\Security\CsrfGuard;
use PDO;

/**
 * /finance/* routes. Every request: session, Finance application grant, finance.access, then the exact operation
 * permission inside the service. Writes also need the exact origin (kernel), CSRF and an Idempotency-Key, and are
 * rate limited per employee. Every response is Cache-Control: no-store. Staff and B2B rights never apply here.
 */
final readonly class FinanceController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private AuthenticationService $auth,
        private AuthorizationService $authorization,
        private Config $config,
        private RequestContext $context,
        private PDO $pdo,
        private CsrfGuard $csrf,
        private ApiRateLimiter $rateLimiter,
        private ImportService $imports,
        private CaseService $cases,
        private CaseQueries $caseQueries,
        private OnboardingService $onboarding,
        private OnboardingQueries $onboardingQueries,
        private RiskService $risk,
        private CreditService $credit,
        private OwnerDecisionService $owner,
        private \Arasya\Operations\Finance\Ledger\LedgerRequestService $ledgerRequests,
        private \Arasya\Operations\Finance\Ledger\LedgerRequestQueries $ledgerQueries,
        private \Arasya\Operations\Finance\Ledger\ReconciliationService $reconciliation,
        private \Arasya\Operations\B2B\AccountQueries $ledgerView,
    ) {
    }

    public function handle(Request $request): Response
    {
        $session = $this->auth->authenticate($request->cookie($this->config->cookieName()) ?? '', $request->ipAddress, $request->userAgent, $request->requestId);
        $actor = $session->employee;
        $this->context->authenticatedAs($actor->employeeUuid);
        FinanceAccess::gate($this->authorization, $actor);
        $path = $request->path;
        $q = $this->query($request);

        if ($request->method === 'GET') {
            if (preg_match('#^/finance/imports/(' . self::UUID . ')/rejections\.csv$#D', $path, $m) === 1) {
                    return Response::file($this->imports->rejectionsCsv($actor, $m[1]), ['Content-Type' => 'text/csv; charset=utf-8',
                        'Content-Disposition' => 'attachment; filename="finance-import-' . substr($m[1], 0, 8) . '-review.csv"', 'Cache-Control' => 'no-store, private']);
            }
            return $this->secure(match (true) {
                $path === '/finance/access' => $this->access($actor, $q),
                $path === '/finance/overview' => $this->caseQueries->summary($actor),
                $path === '/finance/companies' => $this->companies($actor, $q),
                $path === '/finance/company-search' => $this->companySearch($actor, $q),
                $path === '/finance/b2b/accounts' => $this->accounts($actor, $q),
                $path === '/finance/b2b/gate-decisions' => $this->gateDecisions($actor, $q),
                $path === '/finance/imports' => $this->imports->list($actor, $q),
                (bool) preg_match('#^/finance/imports/(' . self::UUID . ')$#D', $path, $m) => $this->imports->detail($actor, $m[1]),
                (bool) preg_match('#^/finance/imports/(' . self::UUID . ')/rows$#D', $path, $m) => $this->imports->rows($actor, $m[1], $q),
                $path === '/finance/cases' => $this->caseQueries->list($actor, $q),
                $path === '/finance/cases/facets' => $this->caseQueries->facets($actor),
                (bool) preg_match('#^/finance/cases/(' . self::UUID . ')$#D', $path, $m) => $this->caseQueries->detail($actor, $m[1]),
                $path === '/finance/accounts' => $this->onboardingQueries->list($actor, $q),
                (bool) preg_match('#^/finance/accounts/(' . self::UUID . ')$#D', $path, $m) => $this->onboardingQueries->detail($actor, $m[1]),
                $path === '/finance/risk' => $this->risk->queue($actor, $q),
                $path === '/finance/risk/policies' => $this->risk->policies($actor),
                (bool) preg_match('#^/finance/risk/companies/(' . self::UUID . ')$#D', $path, $m) => $this->risk->view($actor, $m[1]),
                $path === '/finance/credit' => $this->credit->queue($actor, $q),
                (bool) preg_match('#^/finance/credit/companies/(' . self::UUID . ')$#D', $path, $m) => $this->credit->detail($actor, $m[1]),
                (bool) preg_match('#^/finance/companies/(' . self::UUID . ')/credit-profile$#D', $path, $m) => $this->credit->profile($actor, $m[1]),
                $path === '/finance/audit' => $this->audit($actor, $q),
                $path === '/finance/refused-attempts' => $this->refusedAttempts($actor, $q),
                $path === '/finance/owner/companies' => $this->owner->list($actor, $q),
                (bool) preg_match('#^/finance/owner/companies/(' . self::UUID . ')$#D', $path, $m) => $this->owner->detail($actor, $m[1]),
                // API 2.68.0: ledger requests, the company ledger (same read model as B2B) and the bank reconciliation view.
                $path === '/finance/ledger-requests' => $this->ledgerQueries->list($actor, $q),
                (bool) preg_match('#^/finance/ledger-requests/(' . self::UUID . ')$#D', $path, $m) => $this->ledgerQueries->detail($actor, $m[1]),
                $path === '/finance/reconciliation' => $this->ledgerQueries->reconciliation($actor, $q),
                (bool) preg_match('#^/finance/ledger/companies/(' . self::UUID . ')$#D', $path, $m) => $this->ledgerView->summary($actor, $m[1]),
                (bool) preg_match('#^/finance/ledger/companies/(' . self::UUID . ')/movements$#D', $path, $m) => $this->ledgerView->movements($actor, $m[1], $q),
                (bool) preg_match('#^/finance/ledger/companies/(' . self::UUID . ')/movements/(' . self::UUID . ')$#D', $path, $m) => $this->ledgerView->movementDetail($actor, $m[1], $m[2]),
                (bool) preg_match('#^/finance/ledger/companies/(' . self::UUID . ')/open-items$#D', $path, $m) => $this->ledgerView->openItems($actor, $m[1], $q),
                default => throw new ApiException(404, 'NOT_FOUND', 'Finance route not found.'),
            });
        }
        if ($request->method !== 'POST') {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed.');
        }
        $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
        if (!$this->rateLimiter->hit('finance-write', $actor->employeeUuid, 600, 60)) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many Finance changes. Wait a moment.');
        }
        $key = $request->header('idempotency-key') ?? '';
        $id = $request->requestId;
        if ($path === '/finance/imports') {
            return $this->secure($this->upload($request, $actor, $q, $key, $id), 201);
        }
        // A command without fields (for example the next analysis chunk) may send no body at all.
        $body = $request->body === '' ? [] : $request->json(65_536);
        $result = match (true) {
            (bool) preg_match('#^/finance/imports/(' . self::UUID . ')/(mapping|analyze|confirm|cancel)$#D', $path, $m) => match ($m[2]) {
                'mapping' => $this->imports->map($actor, $m[1], $body, $key, $id),
                'analyze' => $this->imports->analyze($actor, $m[1], $body, $key, $id),
                'confirm' => $this->imports->confirm($actor, $m[1], $body, $key, $id),
                'cancel' => $this->imports->cancel($actor, $m[1], $body, $key, $id),
            },
            $path === '/finance/cases' => $this->cases->createManual($actor, $body, $key, $id),
            $path === '/finance/cases/from-b2b' => $this->cases->sweepB2b($actor, $body, $key, $id),
            (bool) preg_match('#^/finance/cases/(' . self::UUID . ')/duplicates/(' . self::UUID . ')/resolve$#D', $path, $m) => $this->cases->resolveDuplicate($actor, $m[1], $m[2], $body, $key, $id),
            (bool) preg_match('#^/finance/cases/(' . self::UUID . ')/flags/(' . self::UUID . ')/resolve$#D', $path, $m) => $this->cases->resolveFlag($actor, $m[1], $m[2], $body, $key, $id),
            (bool) preg_match('#^/finance/cases/(' . self::UUID . ')/supervision$#D', $path, $m) => $this->cases->supervision($actor, $m[1], $body, $key, $id),
            (bool) preg_match('#^/finance/cases/(' . self::UUID . ')/comments$#D', $path, $m) => $this->cases->comment($actor, $m[1], $body, $key, $id),
            (bool) preg_match('#^/finance/cases/(' . self::UUID . ')/(claim|release|update|checklist|request-information|mark-duplicate|return|submit|approve|reject|reopen|link)$#D', $path, $m) => match ($m[2]) {
                'claim' => $this->cases->claim($actor, $m[1], $body, $key, $id),
                'release' => $this->cases->release($actor, $m[1], $body, $key, $id),
                'update' => $this->cases->update($actor, $m[1], $body, $key, $id),
                'checklist' => $this->cases->checklist($actor, $m[1], $body, $key, $id),
                'request-information', 'return' => $this->cases->setAside($actor, $m[1], 'NEEDS_INFORMATION', $body, $key, $id),
                'mark-duplicate' => $this->cases->setAside($actor, $m[1], 'SUSPECTED_DUPLICATE', $body, $key, $id),
                'submit' => $this->cases->submit($actor, $m[1], $body, $key, $id),
                'approve' => $this->cases->approve($actor, $m[1], $body, $key, $id),
                'reject' => $this->cases->reject($actor, $m[1], $body, $key, $id),
                'reopen' => $this->cases->reopen($actor, $m[1], $body, $key, $id),
                'link' => $this->cases->linkCompany($actor, $m[1], $body, $key, $id),
            },
            $path === '/finance/accounts' => $this->onboarding->open($actor, $body, $key, $id),
            (bool) preg_match('#^/finance/accounts/(' . self::UUID . ')/(draft|overlap|submit|verify|return|post|dispute|reopen|currency-issue|correct-currency)$#D', $path, $m) => match ($m[2]) {
                'draft' => $this->onboarding->draft($actor, $m[1], $body, $key, $id),
                'overlap' => $this->onboarding->reconcileOverlap($actor, $m[1], $body, $key, $id),
                'submit' => $this->onboarding->submit($actor, $m[1], $body, $key, $id),
                'verify' => $this->onboarding->verify($actor, $m[1], $body, $key, $id),
                'return' => $this->onboarding->returnProposal($actor, $m[1], $body, $key, $id),
                'post' => $this->onboarding->post($actor, $m[1], $body, $key, $id),
                'dispute' => $this->onboarding->dispute($actor, $m[1], $body, $key, $id),
                'reopen' => $this->onboarding->reopen($actor, $m[1], $body, $key, $id),
                'currency-issue' => $this->onboarding->resolveCurrencyIssue($actor, $m[1], $body, $key, $id),
                'correct-currency' => $this->onboarding->correctCurrency($actor, $m[1], $body, $key, $id),
            },
            $path === '/finance/risk' => $this->risk->propose($actor, $body, $key, $id),
            (bool) preg_match('#^/finance/risk/(' . self::UUID . ')/(approve|reject|suspend)$#D', $path, $m) => $this->risk->decide($actor, $m[1], $m[2], $body, $key, $id),
            $path === '/finance/risk/policies' => $this->risk->createPolicy($actor, $body, $key, $id),
            (bool) preg_match('#^/finance/risk/policies/(' . self::UUID . ')/(approve|retire)$#D', $path, $m) => $this->risk->decidePolicy($actor, $m[1], $m[2], $body, $key, $id),
            $path === '/finance/credit' => $this->credit->propose($actor, $body, $key, $id),
            (bool) preg_match('#^/finance/credit/(' . self::UUID . ')/(verify|approve|reject|suspend|reinstate|revoke)$#D', $path, $m) => $this->credit->decide($actor, $m[1], $m[2], $body, $key, $id),
            $path === '/finance/credit/exceptions' => $this->credit->requestException($actor, $body, $key, $id),
            (bool) preg_match('#^/finance/owner/companies/(' . self::UUID . ')/risk$#D', $path, $m) => $this->owner->decideColor($actor, $m[1], $body, $key, $id),
            (bool) preg_match('#^/finance/owner/companies/(' . self::UUID . ')/credit$#D', $path, $m) => $this->owner->decideLimit($actor, $m[1], $body, $key, $id),
            (bool) preg_match('#^/finance/credit/exceptions/(' . self::UUID . ')/(approve|reject|withdraw)$#D', $path, $m) => $this->credit->decideException($actor, $m[1], $m[2], $body, $key, $id),
            $path === '/finance/ledger-requests' => $this->ledgerRequests->create($actor, $body, $key, $id),
            (bool) preg_match('#^/finance/ledger-requests/(' . self::UUID . ')/(update|submit|withdraw|approve|reject|payout|cancel-payout)$#D', $path, $m) => match ($m[2]) {
                'update' => $this->ledgerRequests->update($actor, $m[1], $body, $key, $id),
                'submit' => $this->ledgerRequests->submit($actor, $m[1], $body, $key, $id),
                'withdraw' => $this->ledgerRequests->withdraw($actor, $m[1], $body, $key, $id),
                'approve' => $this->ledgerRequests->approve($actor, $m[1], $body, $key, $id),
                'reject' => $this->ledgerRequests->reject($actor, $m[1], $body, $key, $id),
                'payout' => $this->ledgerRequests->payout($actor, $m[1], $body, $key, $id),
                'cancel-payout' => $this->ledgerRequests->cancelPayout($actor, $m[1], $body, $key, $id),
            },
            (bool) preg_match('#^/finance/ledger/companies/(' . self::UUID . ')/allocations$#D', $path, $m) => $this->reconciliation->allocate($actor, $m[1], $body, $key, $id),
            (bool) preg_match('#^/finance/ledger/companies/(' . self::UUID . ')/allocations/(' . self::UUID . ')/release$#D', $path, $m) => $this->reconciliation->release($actor, $m[1], $m[2], $body, $key, $id),
            default => throw new ApiException(404, 'NOT_FOUND', 'Finance route not found.'),
        };
        $status = (int) ($result['status'] ?? 200);
        unset($result['status']);
        return $this->secure($result, $status);
    }

    /** @return array<string, mixed> */
    private function upload(Request $request, \Arasya\Operations\Employee\EmployeeIdentity $actor, array $q, string $key, string $id): array
    {
        $type = strtolower(trim(explode(';', $request->header('content-type') ?? '')[0]));
        $format = match ($type) {
            'text/csv', 'application/csv', 'text/plain' => 'csv',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => throw new ApiException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Upload a CSV or XLSX file.'),
        };
        FinanceInput::only($q, ['source', 'encoding', 'name', 'profile']);
        $result = $this->imports->upload($actor, [
            'body' => $request->body, 'format' => $format, 'fileName' => (string) ($q['name'] ?? 'import.' . $format),
            'source' => (string) ($q['source'] ?? ($format === 'xlsx' ? 'XLSX' : 'CSV')), 'encoding' => (string) ($q['encoding'] ?? 'utf-8'), 'profile' => (string) ($q['profile'] ?? 'STANDARD'),
        ], $key, $id);
        unset($result['status']);
        return $result;
    }

    /** @return array<string, string> */
    private function query(Request $request): array
    {
        $out = [];
        foreach ($request->query as $name => $value) {
            if (!is_string($name) || !is_string($value) || strlen($value) > 400) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Invalid filter.');
            }
            if ($value !== '') {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function access(\Arasya\Operations\Employee\EmployeeIdentity $actor, array $q): array
    {
        if ($q !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Unexpected filters.');
        }
        return [
            'application' => ApplicationAccess::FINANCE,
            'employee' => ['displayName' => $actor->displayName, 'username' => $actor->username],
            'authorizationVersion' => $actor->authorizationVersion,
            'capabilities' => FinanceAccess::capabilities($this->authorization, $actor),
            'openingBalancePostingEnabled' => $this->config->financeOpeningBalancePostingEnabled(),
            'collectionsEnabled' => $this->config->financeCollectionsEnabled(),
            'riskLimitAuthority' => in_array($actor->employeeUuid, $this->config->financeRiskLimitAuthorityList(), true),
            'owner' => $this->owner->ownerStatus($actor),
        ];
    }

    /** Canonical B2B companies with their Finance state. Financial columns only for account viewers. */
    private function companies(\Arasya\Operations\Employee\EmployeeIdentity $actor, array $q): array
    {
        FinanceAccess::require($this->authorization, $actor, FinanceAccess::COMPANIES_VIEW);
        [$size, $cursor] = $this->page($q);
        $statement = $this->pdo->prepare(
            "SELECT c.company_uuid, c.company_code, c.legal_name, c.country_code, c.status,
                (SELECT f.status FROM finance_company_cases f WHERE f.company_uuid = c.company_uuid ORDER BY f.status = 'APPROVED' DESC, f.updated_at DESC LIMIT 1) AS verification,
                o.status AS onboarding_status, o.account_currency, r.classification, l.amount AS limit_amount, l.currency_code AS limit_currency, l.status AS limit_status
             FROM b2b_companies c
             LEFT JOIN finance_account_onboardings o ON o.company_uuid = c.company_uuid
             LEFT JOIN finance_risk_assessments r ON r.active_company_uuid = c.company_uuid
             LEFT JOIN finance_credit_limits l ON l.active_company_uuid = c.company_uuid
             WHERE c.company_code > :cursor ORDER BY c.company_code LIMIT " . ($size + 1)
        );
        $statement->execute(['cursor' => $cursor]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasNext = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);
        $financial = FinanceAccess::can($this->authorization, $actor, FinanceAccess::ACCOUNTS_VIEW);
        $credit = FinanceAccess::can($this->authorization, $actor, FinanceAccess::CREDIT_VIEW);
        return [
            'items' => array_map(static fn (array $r): array => [
                'companyId' => (string) $r['company_uuid'], 'companyCode' => (string) $r['company_code'], 'legalName' => (string) $r['legal_name'],
                'countryCode' => (string) $r['country_code'], 'companyStatus' => (string) $r['status'],
                'verificationStatus' => $r['verification'] === null ? 'NOT_STARTED' : ($r['verification'] === 'APPROVED' ? 'VERIFIED' : 'IN_PROGRESS'),
                'accountCurrency' => $r['account_currency'],
                'reconciliationStatus' => $financial ? ($r['onboarding_status'] ?? 'NOT_OPENED') : null,
                'riskColor' => $credit ? ($r['classification'] ?? 'UNVERIFIED') : null,
                'creditLimit' => $credit ? ($r['limit_status'] === null ? ['status' => 'NO_APPROVED_LIMIT'] : ['status' => $r['limit_status'], 'amount' => $r['limit_amount'], 'currency' => $r['limit_currency']]) : null,
            ], $rows),
            'nextCursor' => $hasNext && $rows !== [] ? (string) $rows[count($rows) - 1]['company_code'] : null,
        ];
    }

    private function companySearch(\Arasya\Operations\Employee\EmployeeIdentity $actor, array $q): array
    {
        FinanceAccess::require($this->authorization, $actor, FinanceAccess::COMPANIES_VIEW);
        FinanceInput::only($q, ['q']);
        $term = trim((string) ($q['q'] ?? ''));
        if (mb_strlen($term) < 2 || mb_strlen($term) > 120) {
            FinanceInput::fail('q', 'invalid');
        }
        $s = $this->pdo->prepare('SELECT company_uuid, company_code, legal_name, country_code, tax_identifier, status FROM b2b_companies
            WHERE legal_name LIKE ? OR company_code = ? OR tax_identifier_normalized = ? ORDER BY legal_name LIMIT 20');
        $s->execute(['%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%', strtoupper($term), strtoupper((string) preg_replace('/^RO(?=\d)|[^A-Za-z0-9]+/i', '', $term))]);
        return ['items' => array_map(static fn (array $r): array => ['companyId' => $r['company_uuid'], 'companyCode' => $r['company_code'], 'legalName' => $r['legal_name'],
            'countryCode' => $r['country_code'], 'taxIdentifier' => $r['tax_identifier'], 'companyStatus' => $r['status']], $s->fetchAll(PDO::FETCH_ASSOC))];
    }

    /** Existing ledger-derived balances per currency (read-only, never combined). */
    private function accounts(\Arasya\Operations\Employee\EmployeeIdentity $actor, array $q): array
    {
        FinanceAccess::require($this->authorization, $actor, FinanceAccess::ACCOUNTS_VIEW);
        [$size, $cursor] = $this->page($q);
        $statement = $this->pdo->prepare(
            "SELECT c.company_uuid, c.company_code, c.legal_name, c.status,
                COALESCE(SUM(CASE WHEN m.currency_code='RON' THEN CASE m.direction WHEN 'debit' THEN m.amount ELSE -m.amount END ELSE 0 END),0) AS ron,
                COALESCE(SUM(CASE WHEN m.currency_code='EUR' THEN CASE m.direction WHEN 'debit' THEN m.amount ELSE -m.amount END ELSE 0 END),0) AS eur
             FROM b2b_companies c LEFT JOIN b2b_account_movements m ON m.company_uuid=c.company_uuid
             WHERE c.company_code > :cursor GROUP BY c.company_uuid,c.company_code,c.legal_name,c.status
             ORDER BY c.company_code LIMIT " . ($size + 1)
        );
        $statement->execute(['cursor' => $cursor]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasNext = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);
        return [
            'items' => array_map(static fn (array $r): array => [
                'companyId' => (string) $r['company_uuid'], 'companyCode' => (string) $r['company_code'], 'legalName' => (string) $r['legal_name'],
                'companyStatus' => (string) $r['status'], 'balances' => ['RON' => (string) $r['ron'], 'EUR' => (string) $r['eur']],
            ], $rows),
            'nextCursor' => $hasNext && $rows !== [] ? (string) $rows[count($rows) - 1]['company_code'] : null,
        ];
    }

    /** Refused money commands (RefusedAttemptRecorder), newest first, 100 per page; `code` filters, `before` pages by id. */
    private function refusedAttempts(\Arasya\Operations\Employee\EmployeeIdentity $actor, array $q): array
    {
        FinanceAccess::require($this->authorization, $actor, FinanceAccess::AUDIT_VIEW);
        FinanceInput::only($q, ['code', 'before']);
        $where = [];
        $params = [];
        if (isset($q['code'])) {
            if (!in_array($q['code'], RefusedAttemptRecorder::CODES, true)) {
                FinanceInput::fail('code', 'invalid');
            }
            $where[] = 'r.error_code = ?';
            $params[] = $q['code'];
        }
        if (isset($q['before'])) {
            if (!is_string($q['before']) || preg_match('/^[1-9]\d{0,18}$/D', $q['before']) !== 1) {
                throw new ApiException(400, 'INVALID_CURSOR', 'Invalid cursor.');
            }
            $where[] = 'r.attempt_id < ?';
            $params[] = (int) $q['before'];
        }
        $statement = $this->pdo->prepare('SELECT r.attempt_id, r.employee_uuid, e.display_name, r.http_method, r.route, r.http_status, r.error_code, r.request_id, r.occurred_at
            FROM finance_refused_attempts r LEFT JOIN employees e ON e.employee_uuid = r.employee_uuid'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY r.attempt_id DESC LIMIT 101');
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > 100;
        $rows = array_slice($rows, 0, 100);
        return ['items' => array_map(static fn (array $r): array => [
            'id' => (int) $r['attempt_id'], 'employeeId' => $r['employee_uuid'], 'employee' => $r['display_name'], 'method' => $r['http_method'],
            'route' => $r['route'], 'status' => (int) $r['http_status'], 'code' => $r['error_code'], 'requestId' => $r['request_id'], 'at' => $r['occurred_at'],
        ], $rows), 'nextBefore' => $more ? (string) $rows[99]['attempt_id'] : null];
    }

    private function audit(\Arasya\Operations\Employee\EmployeeIdentity $actor, array $q): array
    {
        FinanceAccess::require($this->authorization, $actor, FinanceAccess::AUDIT_VIEW);
        FinanceInput::only($q, ['entity', 'cursor']);
        $where = [];
        $params = [];
        if (isset($q['entity'])) {
            if (!in_array($q['entity'], ['import', 'case', 'onboarding', 'risk', 'policy', 'credit', 'exception', 'ledger_request', 'allocation'], true)) {
                FinanceInput::fail('entity', 'invalid');
            }
            $where[] = 'a.entity_type = ?';
            $params[] = $q['entity'];
        }
        if (isset($q['cursor'])) {
            $decoded = base64_decode(strtr($q['cursor'], '-_', '+/'), true);
            if (!is_string($decoded) || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6})\|(' . self::UUID . ')$/D', $decoded, $m) !== 1) {
                throw new ApiException(400, 'INVALID_CURSOR', 'Invalid cursor.');
            }
            $where[] = '(a.occurred_at < ? OR (a.occurred_at = ? AND a.event_id < ?))';
            array_push($params, $m[1], $m[1], $m[2]);
        }
        $s = $this->pdo->prepare('SELECT a.*, e.display_name, b.company_code, b.legal_name FROM finance_activity_events a JOIN employees e ON e.employee_uuid = a.actor_employee_uuid
            LEFT JOIN b2b_companies b ON b.company_uuid = a.company_uuid' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY a.occurred_at DESC, a.event_id DESC LIMIT 101');
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > 100) {
            $next = rtrim(strtr(base64_encode($rows[99]['occurred_at'] . '|' . $rows[99]['event_id']), '+/', '-_'), '=');
            $rows = array_slice($rows, 0, 100);
        }
        return ['items' => array_map(static fn (array $r): array => ['id' => $r['event_id'], 'entity' => $r['entity_type'], 'entityId' => $r['entity_uuid'], 'action' => $r['action'],
            'companyCode' => $r['company_code'], 'companyName' => $r['legal_name'], 'actor' => $r['display_name'], 'versionBefore' => $r['version_before'] === null ? null : (int) $r['version_before'],
            'versionAfter' => $r['version_after'] === null ? null : (int) $r['version_after'], 'reason' => $r['reason'],
            'details' => $r['details_json'] === null ? null : json_decode((string) $r['details_json'], true), 'requestId' => $r['request_id'], 'at' => FinanceStore::iso($r['occurred_at'])], $rows),
            'nextCursor' => $next];
    }

    /**
     * The B2B finance gate journal, newest first, for the shadow analysis that owner decision D2 needs. Read-only.
     * The B2B module owns the query (FinanceGateJournal::page); Finance only checks the permission and the filters.
     * Reason codes, outcome and what changed since finalization only, never limit, exposure or order amounts.
     * @return array<string, mixed>
     */
    private function gateDecisions(\Arasya\Operations\Employee\EmployeeIdentity $actor, array $q): array
    {
        FinanceAccess::require($this->authorization, $actor, FinanceAccess::CREDIT_VIEW);
        FinanceInput::only($q, ['outcome', 'phase', 'cursor']);
        if (isset($q['outcome']) && !in_array($q['outcome'], \Arasya\Operations\B2B\FinanceGateJournal::RECORDED, true)) {
            FinanceInput::fail('outcome', 'invalid');
        }
        if (isset($q['phase']) && !in_array($q['phase'], ['finalization', 'production_submission'], true)) {
            FinanceInput::fail('phase', 'invalid');
        }
        $after = null;
        if (isset($q['cursor'])) {
            $decoded = base64_decode(strtr($q['cursor'], '-_', '+/'), true);
            if (!is_string($decoded) || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6})\|(' . self::UUID . ')$/D', $decoded, $m) !== 1) {
                throw new ApiException(400, 'INVALID_CURSOR', 'Invalid cursor.');
            }
            $after = [$m[1], $m[2]];
        }
        $page = \Arasya\Operations\B2B\FinanceGateJournal::page($this->pdo, $q['outcome'] ?? null, $q['phase'] ?? null, $after);
        return [
            'items' => array_map(static function (array $item): array {
                $item['at'] = FinanceStore::iso($item['decidedAt']);
                unset($item['decidedAt']);
                return $item;
            }, $page['items']),
            'summary' => $page['summary'],
            'nextCursor' => $page['next'] === null ? null : rtrim(strtr(base64_encode($page['next'][0] . '|' . $page['next'][1]), '+/', '-_'), '='),
        ];
    }

    /** @return array{0: int, 1: string} */
    private function page(array $q): array
    {
        FinanceInput::only($q, ['limit', 'cursor']);
        $size = $q['limit'] ?? '25';
        if (!in_array($size, ['25', '50', '100'], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Invalid page size.');
        }
        $cursor = $q['cursor'] ?? '';
        if ($cursor !== '' && preg_match('/^B2B-[0-9]{6,12}$/D', $cursor) !== 1) {
            throw new ApiException(400, 'INVALID_CURSOR', 'Invalid cursor.');
        }
        return [(int) $size, $cursor];
    }

    /** @param array<string, mixed> $payload */
    private function secure(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status, ['Cache-Control' => 'no-store, private']);
    }
}
