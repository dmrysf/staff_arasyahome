<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Risk;

use Arasya\Operations\B2B\AccountMoney;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\B2B\CountryCodes;
use Arasya\Operations\Finance\Credit\CreditService;
use Arasya\Operations\Finance\FinanceAccess as A;
use Arasya\Operations\Finance\FinanceInput as In;
use Arasya\Operations\Finance\FinanceStore;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Owner-direct risk colour and purchase-limit decisions (Finance V0.4.0, finance_arasyahome#19).
 *
 * Owner decision 2026-11-08: Mesut Yeman and Zelal Yeman each decide alone; neither needs the other's approval, and a
 * later decision by either one replaces the earlier one. Authority is the provisioned UUID allowlist verified against
 * Central IAM inside the transaction (DecisionAuthority::verify) plus the matching Finance permission; nothing else
 * (role, root, name) authorizes. Every command is one transaction under the company lock with an Idempotency-Key, an
 * expected-state token (stale -> 409 with the current state), an OWNER_DIRECT row in the existing risk or credit table
 * and an insert-only journal row. Nothing is ever classified or limited automatically.
 *
 * An owner command is EFFECTIVE only on a sufficient basis: a colour needs a verified company identity (GREEN also a
 * reconciled account without an open currency issue); a limit needs a verified identity, a reconciled account and the
 * account currency of the registered country (RO -> RON, otherwise EUR). Without that basis the owner can record a
 * NOT_EFFECTIVE instruction, which never becomes effective by itself. Company verification and opening balances keep
 * their own independent checks; this service never touches them.
 */
final readonly class OwnerDecisionService
{
    private const COLORS = ['GREEN', 'YELLOW', 'RED'];

    public function __construct(private FinanceStore $store, private RiskService $risk, private CreditService $credit, private DecisionAuthority $authority)
    {
    }

    // ---- commands ------------------------------------------------------------------------------------------------

    public function decideColor(EmployeeIdentity $actor, string $companyId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedState', 'classification', 'reason', 'effect']);
        $expected = self::stateToken($input);
        $classification = (string) In::enum($input, 'classification', self::COLORS);
        $effect = (string) In::enum($input, 'effect', ['EFFECTIVE', 'NOT_EFFECTIVE']);
        $reason = (string) In::reason($input);
        return $this->store->mutate($actor, 'owner_risk:' . $companyId, $input, $key, [A::RISK_VIEW, A::RISK_APPROVE], function (string $now) use ($actor, $companyId, $expected, $classification, $effect, $reason, $key, $request): array {
            $this->authority->verify($actor);
            $company = $this->store->lock('b2b_companies', 'company_uuid', $companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
            $state = $this->state($companyId);
            $this->expectState($state, $expected);
            $facts = $this->risk->indicators($companyId);
            $previous = ['classification' => $state['risk']['classification'] ?? 'UNVERIFIED', 'decisionMode' => $state['risk']['decision_mode'] ?? null];
            $basis = $this->basis($company, $facts, $state);
            $pdo = $this->store->pdo();
            if ($effect === 'NOT_EFFECTIVE') {
                $id = $this->journal($actor, $companyId, $state, 'RISK', 'INSTRUCT', null, null, $previous, ['classification' => $classification], null, $reason, $basis, $request, $key, $now);
                $this->store->event('risk', $id, $companyId, $actor, 'owner_risk_instruction_recorded', null, null, $reason, ['classification' => $classification], $request, $key, $now);
                return ['status' => 201, 'decisionId' => $id, 'effect' => 'NOT_EFFECTIVE', 'state' => $this->compact($companyId)];
            }
            if ($facts['verification'] !== 'VERIFIED') {
                throw new ApiException(409, 'COMPANY_NOT_VERIFIED', 'The company identity is not verified, so a colour cannot be effective yet. Record it as a non-effective instruction.');
            }
            if ($classification === 'GREEN' && ($facts['reconciliation'] !== 'RECONCILED' || $facts['currencyIssue'] === 'open')) {
                throw new ApiException(409, 'GREEN_REQUIRES_RECONCILIATION', 'GREEN requires a reconciled current account without an open currency issue. Record it as a non-effective instruction.');
            }
            if ($state['risk'] !== null && $state['risk']['classification'] === $classification) {
                throw new ApiException(409, 'NO_CHANGE', 'The company already has this colour.');
            }
            if ($state['risk'] !== null) {
                $pdo->prepare("UPDATE finance_risk_assessments SET status = 'SUPERSEDED', active_company_uuid = NULL, version = version + 1 WHERE assessment_uuid = ? AND active_company_uuid = ?")
                    ->execute([$state['risk']['assessment_uuid'], $companyId]);
                $this->store->event('risk', (string) $state['risk']['assessment_uuid'], $companyId, $actor, 'risk_superseded_by_owner', (int) $state['risk']['version'], (int) $state['risk']['version'] + 1, $reason, null, $request, $key, $now);
            }
            $assessment = Uuid::v4();
            $pdo->prepare("INSERT INTO finance_risk_assessments (assessment_uuid, company_uuid, classification, status, decision_mode, active_company_uuid, indicators_json, basis_json, policy_uuid, reason,
                    proposed_by_employee_uuid, proposed_at, decided_by_employee_uuid, decided_at, decision_reason, version) VALUES (?, ?, ?, 'APPROVED', 'OWNER_DIRECT', ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, 1)")
                ->execute([$assessment, $companyId, $classification, $companyId, FinanceStore::json($facts['values']), FinanceStore::json($facts['basis']), $reason,
                    $actor->employeeUuid, $now, $actor->employeeUuid, $now, $reason]);
            $id = $this->journal($actor, $companyId, $state, 'RISK', 'SET', $assessment, null, $previous, ['classification' => $classification], null, $reason, $basis, $request, $key, $now);
            $this->store->event('risk', $assessment, $companyId, $actor, 'risk_owner_decided', null, 1, $reason, ['classification' => $classification, 'previous' => $previous['classification'], 'decisionId' => $id], $request, $key, $now);
            return ['status' => 201, 'decisionId' => $id, 'effect' => 'EFFECTIVE', 'state' => $this->compact($companyId)];
        });
    }

    /** action: SET (new or changed limit, 0.00 allowed) | SUSPEND | REINSTATE | REVOKE */
    public function decideLimit(EmployeeIdentity $actor, string $companyId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedState', 'action', 'amount', 'currency', 'effectiveFrom', 'reviewDate', 'expiresOn', 'reason', 'effect']);
        $expected = self::stateToken($input);
        $action = (string) In::enum($input, 'action', ['SET', 'SUSPEND', 'REINSTATE', 'REVOKE']);
        $effect = (string) In::enum($input, 'effect', ['EFFECTIVE', 'NOT_EFFECTIVE']);
        $reason = (string) In::reason($input);
        if ($effect === 'NOT_EFFECTIVE' && $action !== 'SET') {
            In::fail('effect', 'only_for_set');
        }
        $cents = null;
        $currency = null;
        $from = null;
        $review = null;
        $expires = null;
        if ($action === 'SET') {
            $cents = In::money($input, 'amount', true);
            $currency = (string) In::enum($input, 'currency', ['RON', 'EUR']);
            $from = In::date($input, 'effectiveFrom', false) ?? $this->store->today();
            $review = In::date($input, 'reviewDate', false);
            $expires = In::date($input, 'expiresOn', false);
            if ($review !== null && $review < $from) {
                In::fail('reviewDate', 'before_effective_from');
            }
            if ($expires !== null && $expires < $from) {
                In::fail('expiresOn', 'before_effective_from');
            }
        } else {
            foreach (['amount', 'currency', 'effectiveFrom', 'reviewDate', 'expiresOn'] as $field) {
                if (array_key_exists($field, $input)) {
                    In::fail($field, 'not_allowed');
                }
            }
        }
        $permission = $action === 'SET' ? A::CREDIT_APPROVE : A::CREDIT_REVOKE;
        return $this->store->mutate($actor, 'owner_credit:' . $companyId, $input, $key, [A::CREDIT_VIEW, $permission], function (string $now) use ($actor, $companyId, $expected, $action, $effect, $reason, $cents, $currency, $from, $review, $expires, $key, $request): array {
            $this->authority->verify($actor);
            $company = $this->store->lock('b2b_companies', 'company_uuid', $companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
            $state = $this->state($companyId);
            $this->expectState($state, $expected);
            $facts = $this->risk->indicators($companyId);
            $basis = $this->basis($company, $facts, $state);
            $limit = $state['limit'];
            $previous = $limit === null ? ['status' => 'NO_APPROVED_LIMIT'] : ['status' => $limit['status'], 'amount' => (string) $limit['amount'], 'currency' => $limit['currency_code'],
                'effectiveFrom' => $limit['effective_from'], 'reviewDate' => $limit['review_date'], 'expiresOn' => $limit['expires_on'], 'decisionMode' => $limit['decision_mode']];
            $pdo = $this->store->pdo();
            if ($action === 'SET') {
                $registered = CountryCodes::normalize((string) ($company['country_code'] ?? ''));
                $expectedCurrency = $registered === null ? null : ($registered === 'RO' ? 'RON' : 'EUR');
                if ($expectedCurrency !== null && $expectedCurrency !== $currency) {
                    throw new ApiException(422, 'CURRENCY_MISMATCH', 'The limit currency must follow the registered country (Romania RON, every other country EUR).', ['expectedCurrency' => $expectedCurrency]);
                }
                $new = ['status' => 'APPROVED', 'amount' => AccountMoney::format((int) $cents), 'currency' => $currency, 'effectiveFrom' => $from, 'reviewDate' => $review, 'expiresOn' => $expires];
                if ($effect === 'NOT_EFFECTIVE') {
                    $new['status'] = 'INSTRUCTION';
                    $id = $this->journal($actor, $companyId, $state, 'CREDIT', 'INSTRUCT', null, null, $previous, $new, $currency, $reason, $basis + ['currencyConfirmedByCountry' => $expectedCurrency !== null], $request, $key, $now);
                    $this->store->event('credit', $id, $companyId, $actor, 'owner_credit_instruction_recorded', null, null, $reason, ['currency' => $currency], $request, $key, $now);
                    return ['status' => 201, 'decisionId' => $id, 'effect' => 'NOT_EFFECTIVE', 'state' => $this->compact($companyId)];
                }
                $onboarding = $this->effectiveLimitBasis($companyId, $facts, (string) $currency);
                if ($limit !== null && $limit['status'] === 'APPROVED' && (string) $limit['amount'] === $new['amount'] && $limit['currency_code'] === $currency
                    && $limit['effective_from'] === $from && $limit['review_date'] === $review && $limit['expires_on'] === $expires) {
                    throw new ApiException(409, 'NO_CHANGE', 'The company already has exactly this limit.');
                }
                if ($limit !== null) {
                    $pdo->prepare("UPDATE finance_credit_limits SET status = 'SUPERSEDED', active_company_uuid = NULL, ended_by_employee_uuid = ?, ended_at = ?, end_reason = 'superseded', version = version + 1 WHERE limit_uuid = ? AND active_company_uuid = ?")
                        ->execute([$actor->employeeUuid, $now, $limit['limit_uuid'], $companyId]);
                    $this->store->event('credit', (string) $limit['limit_uuid'], $companyId, $actor, 'credit_superseded_by_owner', (int) $limit['version'], (int) $limit['version'] + 1, $reason, null, $request, $key, $now);
                }
                $target = Uuid::v4();
                $pdo->prepare("INSERT INTO finance_credit_limits (limit_uuid, company_uuid, currency_code, amount, status, decision_mode, active_company_uuid, effective_from, review_date, expires_on,
                        onboarding_uuid, onboarding_version, risk_assessment_uuid, policy_uuid, reason, proposed_by_employee_uuid, proposed_at, verified_by_employee_uuid, verified_at,
                        approved_by_employee_uuid, approved_at, version) VALUES (?, ?, ?, ?, 'APPROVED', 'OWNER_DIRECT', ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 1)")
                    ->execute([$target, $companyId, $currency, $new['amount'], $companyId, $from, $review, $expires, $onboarding['onboarding_uuid'], (int) $onboarding['version'],
                        $state['risk']['assessment_uuid'] ?? null, $reason, $actor->employeeUuid, $now, $actor->employeeUuid, $now, $actor->employeeUuid, $now]);
                $id = $this->journal($actor, $companyId, $state, 'CREDIT', 'SET', null, $target, $previous, $new, $currency, $reason, $basis, $request, $key, $now);
                $this->store->event('credit', $target, $companyId, $actor, 'credit_owner_decided', null, 1, $reason, ['currency' => $currency, 'decisionId' => $id], $request, $key, $now);
                return ['status' => 201, 'decisionId' => $id, 'effect' => 'EFFECTIVE', 'state' => $this->compact($companyId)];
            }
            if ($limit === null) {
                throw new ApiException(409, 'NO_ACTIVE_LIMIT', 'The company has no approved limit to change.');
            }
            $set = match ($action) {
                'SUSPEND' => $limit['status'] === 'APPROVED' ? ['status' => 'SUSPENDED', 'end_reason' => $reason] : null,
                'REINSTATE' => $limit['status'] === 'SUSPENDED' ? ['status' => 'APPROVED', 'end_reason' => null] : null,
                'REVOKE' => ['status' => 'REVOKED', 'active_company_uuid' => null, 'ended_by_employee_uuid' => $actor->employeeUuid, 'ended_at' => $now, 'end_reason' => $reason],
            };
            if ($set === null) {
                throw new ApiException(409, 'INVALID_TRANSITION', 'This status change is not allowed.', ['from' => $limit['status']]);
            }
            if ($action === 'REINSTATE') {
                $onboarding = $this->effectiveLimitBasis($companyId, $facts, (string) $limit['currency_code']);
                if ((int) $onboarding['version'] !== (int) $limit['onboarding_version']) {
                    throw new ApiException(409, 'CREDIT_BASIS_STALE', 'The reconciled account changed since this limit was set. Set a new limit on the current basis.');
                }
            }
            $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($set)));
            $pdo->prepare("UPDATE finance_credit_limits SET {$sets}, version = version + 1 WHERE limit_uuid = ?")->execute([...array_values($set), $limit['limit_uuid']]);
            $new = ['status' => $set['status'], 'amount' => (string) $limit['amount'], 'currency' => $limit['currency_code']];
            $id = $this->journal($actor, $companyId, $state, 'CREDIT', $action, null, (string) $limit['limit_uuid'], $previous, $new, (string) $limit['currency_code'], $reason, $basis, $request, $key, $now);
            $this->store->event('credit', (string) $limit['limit_uuid'], $companyId, $actor, 'credit_owner_' . strtolower($action === 'REINSTATE' ? 'reinstated' : ($action === 'SUSPEND' ? 'suspended' : 'revoked')),
                (int) $limit['version'], (int) $limit['version'] + 1, $reason, ['decisionId' => $id], $request, $key, $now);
            return ['status' => 200, 'decisionId' => $id, 'effect' => 'EFFECTIVE', 'state' => $this->compact($companyId)];
        });
    }

    // ---- reads ---------------------------------------------------------------------------------------------------

    /** Canonical companies with their colour and limit state. */
    public function list(EmployeeIdentity $actor, array $q): array
    {
        $this->requireView($actor);
        In::only($q, ['q', 'color', 'limit', 'cursor']);
        $where = [];
        $params = [];
        $term = trim((string) ($q['q'] ?? ''));
        if ($term !== '') {
            if (mb_strlen($term) > 100) {
                In::fail('q', 'too_long');
            }
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $parts = ['c.legal_name LIKE ?', 'c.display_name LIKE ?', 'c.company_code = ?',
                'EXISTS (SELECT 1 FROM finance_company_cases f WHERE f.company_uuid = c.company_uuid AND (f.legacy_customer_code = ? OR f.source_customer_code = ?))'];
            array_push($params, $like, $like, strtoupper($term), $term, $term);
            $tax = preg_replace('/[^A-Z0-9]/', '', strtoupper($term)) ?? '';
            if ($tax !== '' && A::can($this->store->authorization(), $actor, A::COMPANIES_VIEW)) {
                $parts[] = 'c.tax_identifier_normalized = ?';
                $params[] = $tax;
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        $color = $q['color'] ?? null;
        if ($color !== null) {
            if (!in_array($color, ['GREEN', 'YELLOW', 'RED', 'UNVERIFIED'], true)) {
                In::fail('color', 'invalid');
            }
            if ($color === 'UNVERIFIED') {
                $where[] = 'r.assessment_uuid IS NULL';
            } else {
                $where[] = 'r.classification = ?';
                $params[] = $color;
            }
        }
        $limitFilter = $q['limit'] ?? null;
        if ($limitFilter !== null) {
            $where[] = match ($limitFilter) {
                'NO_APPROVED_LIMIT' => 'l.limit_uuid IS NULL',
                'APPROVED' => "l.status = 'APPROVED'",
                'SUSPENDED' => "l.status = 'SUSPENDED'",
                'ZERO' => "l.limit_uuid IS NOT NULL AND l.amount = 0",
                default => In::fail('limit', 'invalid'),
            };
        }
        $cursor = $q['cursor'] ?? null;
        if ($cursor !== null) {
            if (!is_string($cursor) || preg_match('/^B2B-[0-9]{6,12}$/D', $cursor) !== 1) {
                In::fail('cursor', 'invalid');
            }
            $where[] = 'c.company_code > ?';
            $params[] = $cursor;
        }
        $sql = "SELECT c.company_uuid, c.company_code, c.legal_name, c.country_code, c.status,
                (SELECT f.status FROM finance_company_cases f WHERE f.company_uuid = c.company_uuid ORDER BY f.status = 'APPROVED' DESC, f.updated_at DESC LIMIT 1) AS verification,
                r.classification, r.decision_mode AS risk_mode, l.status AS limit_status, l.amount AS limit_amount, l.currency_code AS limit_currency, l.decision_mode AS limit_mode
            FROM b2b_companies c
            LEFT JOIN finance_risk_assessments r ON r.active_company_uuid = c.company_uuid
            LEFT JOIN finance_credit_limits l ON l.active_company_uuid = c.company_uuid"
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY c.company_code LIMIT 51';
        $s = $this->store->pdo()->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) > 50 ? (string) $rows[49]['company_code'] : null;
        $rows = array_slice($rows, 0, 50);
        return ['items' => array_map(static fn (array $r): array => [
            'companyId' => $r['company_uuid'], 'companyCode' => $r['company_code'], 'legalName' => $r['legal_name'], 'countryCode' => $r['country_code'], 'companyStatus' => $r['status'],
            'verification' => self::verificationOf($r['verification']), 'riskColor' => $r['classification'] ?? 'UNVERIFIED', 'riskMode' => $r['risk_mode'],
            'limitStatus' => $r['limit_status'] ?? 'NO_APPROVED_LIMIT', 'limitAmount' => $r['limit_amount'], 'limitCurrency' => $r['limit_currency'], 'limitMode' => $r['limit_mode'],
        ], $rows), 'nextCursor' => $next];
    }

    public function detail(EmployeeIdentity $actor, string $companyId): array
    {
        $this->requireView($actor);
        if (!In::isUuid($companyId)) {
            throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
        }
        $pdo = $this->store->pdo();
        $s = $pdo->prepare('SELECT * FROM b2b_companies WHERE company_uuid = ?');
        $s->execute([$companyId]);
        $company = $s->fetch(PDO::FETCH_ASSOC);
        if (!$company) {
            throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
        }
        $auth = $this->store->authorization();
        $canRisk = A::can($auth, $actor, A::RISK_VIEW);
        $canCredit = A::can($auth, $actor, A::CREDIT_VIEW);
        $sensitive = A::can($auth, $actor, A::AUDIT_SENSITIVE);
        $profile = $this->credit->profileData($companyId);
        $facts = $this->risk->indicators($companyId);
        $state = $this->state($companyId);
        $registered = CountryCodes::normalize((string) ($company['country_code'] ?? ''));
        $owner = $this->ownerStatus($actor);

        $s = $pdo->prepare("SELECT c.case_uuid, c.case_code, c.status, c.legacy_customer_code, c.source_customer_code, c.duplicate_review,
                (SELECT GROUP_CONCAT(f.flag_code ORDER BY f.flag_code) FROM finance_case_flags f WHERE f.case_uuid = c.case_uuid AND f.status = 'open') AS open_flags
            FROM finance_company_cases c WHERE c.company_uuid = ? ORDER BY c.updated_at DESC LIMIT 20");
        $s->execute([$companyId]);
        $cases = array_map(static fn (array $c): array => ['caseId' => $c['case_uuid'], 'code' => $c['case_code'], 'status' => $c['status'], 'legacyCode' => $c['legacy_customer_code'] ?? $c['source_customer_code'],
            'duplicateReview' => $c['duplicate_review'], 'openFlags' => $c['open_flags'] === null ? [] : explode(',', (string) $c['open_flags'])], $s->fetchAll(PDO::FETCH_ASSOC));

        $warnings = [];
        if ($profile['verificationStatus'] !== 'VERIFIED') {
            $warnings[] = 'IDENTITY_NOT_VERIFIED';
        }
        if ($registered === null) {
            $warnings[] = 'REGISTERED_COUNTRY_MISSING';
        }
        if ($profile['reconciliationStatus'] !== 'RECONCILED') {
            $warnings[] = 'ACCOUNT_NOT_RECONCILED';
        }
        if ($facts['currencyIssue'] === 'open') {
            $warnings[] = 'CURRENCY_ISSUE_OPEN';
        }
        if ($facts['values']['otherCurrencyBalance'] !== null) {
            $warnings[] = 'OTHER_CURRENCY_BALANCE';
        }
        foreach ($cases as $case) {
            if (in_array('EMPLOYEE_REPORTED_PASSIVE_UNVERIFIED', $case['openFlags'], true)) {
                $warnings[] = 'PASSIVE_CLAIM_UNVERIFIED';
            }
            if ($case['duplicateReview'] === 'open') {
                $warnings[] = 'DUPLICATE_REVIEW_OPEN';
            }
        }
        $warnings = array_values(array_unique($warnings));

        $colorHistory = null;
        $recommendedColors = null;
        if ($canRisk) {
            $s = $pdo->prepare('SELECT a.*, p.display_name AS proposer, d.display_name AS decider FROM finance_risk_assessments a JOIN employees p ON p.employee_uuid = a.proposed_by_employee_uuid
                LEFT JOIN employees d ON d.employee_uuid = a.decided_by_employee_uuid WHERE a.company_uuid = ? ORDER BY a.proposed_at DESC, a.assessment_uuid LIMIT 100');
            $s->execute([$companyId]);
            $colorHistory = [];
            $recommendedColors = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $item = ['id' => $a['assessment_uuid'], 'classification' => $a['classification'], 'status' => $a['status'], 'decisionMode' => $a['decision_mode'], 'reason' => $a['reason'],
                    'proposedBy' => $a['proposer'], 'proposedAt' => FinanceStore::iso($a['proposed_at']), 'decidedBy' => $a['decider'], 'decidedAt' => FinanceStore::iso($a['decided_at']),
                    'decisionReason' => $a['decision_reason'], 'effective' => $a['status'] === 'APPROVED'];
                if ($a['status'] === 'PROPOSED') {
                    $recommendedColors[] = $item;
                } else {
                    $colorHistory[] = $item;
                }
            }
        }
        $limitHistory = null;
        $recommendedLimits = null;
        if ($canCredit) {
            $s = $pdo->prepare('SELECT l.*, p.display_name AS proposer, v.display_name AS verifier, a.display_name AS approver, en.display_name AS ender FROM finance_credit_limits l
                JOIN employees p ON p.employee_uuid = l.proposed_by_employee_uuid LEFT JOIN employees v ON v.employee_uuid = l.verified_by_employee_uuid
                LEFT JOIN employees a ON a.employee_uuid = l.approved_by_employee_uuid LEFT JOIN employees en ON en.employee_uuid = l.ended_by_employee_uuid
                WHERE l.company_uuid = ? ORDER BY l.proposed_at DESC, l.limit_uuid LIMIT 100');
            $s->execute([$companyId]);
            $limitHistory = [];
            $recommendedLimits = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $item = ['id' => $l['limit_uuid'], 'amount' => $l['amount'], 'currency' => $l['currency_code'], 'status' => $l['status'], 'decisionMode' => $l['decision_mode'],
                    'effectiveFrom' => $l['effective_from'], 'reviewDate' => $l['review_date'], 'expiresOn' => $l['expires_on'], 'reason' => $l['reason'],
                    'proposedBy' => $l['proposer'], 'proposedAt' => FinanceStore::iso($l['proposed_at']), 'verifiedBy' => $l['verifier'], 'approvedBy' => $l['approver'],
                    'approvedAt' => FinanceStore::iso($l['approved_at']), 'endedBy' => $l['ender'], 'endedAt' => FinanceStore::iso($l['ended_at']), 'endReason' => $l['end_reason'],
                    'effective' => in_array($l['status'], ['APPROVED', 'SUSPENDED'], true)];
                if (in_array($l['status'], ['PROPOSED', 'UNDER_REVIEW'], true)) {
                    $recommendedLimits[] = $item;
                } else {
                    $limitHistory[] = $item;
                }
            }
        }
        $s = $pdo->prepare('SELECT d.*, e.display_name FROM finance_owner_decisions d JOIN employees e ON e.employee_uuid = d.actor_employee_uuid WHERE d.company_uuid = ? ORDER BY d.sequence_no DESC LIMIT 200');
        $s->execute([$companyId]);
        $decisions = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $d) {
            if (($d['subject'] === 'RISK' && !$canRisk) || ($d['subject'] === 'CREDIT' && !$canCredit)) {
                continue;
            }
            $item = ['id' => $d['decision_uuid'], 'sequence' => (int) $d['sequence_no'], 'type' => $d['decision_type'], 'subject' => $d['subject'], 'action' => $d['action'], 'effect' => $d['effect'],
                'previous' => json_decode((string) $d['previous_json'], true), 'new' => json_decode((string) $d['new_json'], true), 'currency' => $d['currency_code'], 'reason' => $d['reason'],
                'basis' => json_decode((string) $d['basis_json'], true), 'actor' => $d['display_name'], 'decidedAt' => FinanceStore::iso($d['decided_at']), 'requestId' => $d['request_id']];
            if ($sensitive) {
                $item['actorSnapshot'] = json_decode((string) $d['actor_snapshot_json'], true);
                $item['authorityFingerprint'] = bin2hex((string) $d['authority_fingerprint']);
                $item['stateBefore'] = $d['state_before'];
                $item['stateAfter'] = $d['state_after'];
            }
            $decisions[] = $item;
        }
        $pending = [];
        foreach (['RISK', 'CREDIT'] as $subject) {
            foreach ($decisions as $d) {
                if ($d['subject'] !== $subject) {
                    continue;
                }
                if ($d['action'] === 'INSTRUCT') {
                    $pending[] = $d;
                }
                break;
            }
        }
        $colorOptions = [];
        foreach (self::COLORS as $color) {
            $colorOptions[$color] = $facts['verification'] !== 'VERIFIED' ? 'COMPANY_NOT_VERIFIED'
                : ($color === 'GREEN' && ($facts['reconciliation'] !== 'RECONCILED' || $facts['currencyIssue'] === 'open') ? 'GREEN_REQUIRES_RECONCILIATION' : 'EFFECTIVE_ALLOWED');
        }
        $limitBlock = $facts['verification'] !== 'VERIFIED' ? 'COMPANY_NOT_VERIFIED' : ($registered === null ? 'VERIFIED_COUNTRY_REQUIRED'
            : ($facts['reconciliation'] !== 'RECONCILED' ? 'ACCOUNT_NOT_RECONCILED' : ($facts['currencyIssue'] === 'open' ? 'CURRENCY_ISSUE_OPEN'
            : ($facts['accountCurrency'] !== ($registered === 'RO' ? 'RON' : 'EUR') ? 'CURRENCY_MISMATCH' : 'EFFECTIVE_ALLOWED'))));
        return [
            'company' => ['id' => $companyId, 'code' => $company['company_code'], 'legalName' => $company['legal_name'], 'displayName' => $company['display_name'],
                'countryCode' => $company['country_code'], 'status' => $company['status'], 'taxIdentifier' => A::can($auth, $actor, A::COMPANIES_VIEW) ? $company['tax_identifier'] : null],
            'profile' => $profile,
            'facts' => ['verification' => $facts['verification'], 'reconciliation' => $facts['reconciliation'], 'accountCurrency' => $facts['accountCurrency'], 'currencyIssue' => $facts['currencyIssue'],
                'values' => $canRisk ? $facts['values'] : null],
            'expectedCurrency' => $registered === null ? null : ($registered === 'RO' ? 'RON' : 'EUR'),
            'cases' => $cases,
            'warnings' => $warnings,
            'risk' => $canRisk ? ['classification' => $state['risk']['classification'] ?? 'UNVERIFIED', 'decisionMode' => $state['risk']['decision_mode'] ?? null,
                'decidedBy' => $state['risk'] === null ? null : $this->name((string) $state['risk']['decided_by_employee_uuid']), 'decidedAt' => FinanceStore::iso($state['risk']['decided_at'] ?? null),
                'history' => $colorHistory, 'recommendations' => $recommendedColors, 'options' => $colorOptions] : null,
            'credit' => $canCredit ? ['status' => $profile['creditLimitStatus'], 'amount' => $profile['approvedCreditLimit'], 'currency' => $profile['creditLimitCurrency'],
                'decisionMode' => $state['limit']['decision_mode'] ?? null, 'effectiveFrom' => $state['limit']['effective_from'] ?? null, 'reviewDate' => $state['limit']['review_date'] ?? null,
                'expiresOn' => $state['limit']['expires_on'] ?? null, 'history' => $limitHistory, 'recommendations' => $recommendedLimits, 'effectiveBlock' => $limitBlock] : null,
            'decisions' => $decisions,
            'pendingInstructions' => $pending,
            'owner' => $owner,
            'state' => $state['token'],
        ];
    }

    /** What the actor may do here. Informational for the screen; every command re-checks on the server. */
    public function ownerStatus(EmployeeIdentity $actor): array
    {
        $auth = $this->store->authorization();
        $allowed = $this->authority->allows($actor);
        $verified = false;
        if ($allowed) {
            try {
                $this->authority->verify($actor);
                $verified = true;
            } catch (ApiException) {
                $verified = false;
            }
        }
        return [
            'authorityConfigured' => $this->authority->configured(),
            'decisionMaker' => $verified,
            'canDecideColor' => $verified && A::can($auth, $actor, A::RISK_APPROVE) && A::can($auth, $actor, A::RISK_VIEW),
            'canSetLimit' => $verified && A::can($auth, $actor, A::CREDIT_APPROVE) && A::can($auth, $actor, A::CREDIT_VIEW),
            'canChangeLimitStatus' => $verified && A::can($auth, $actor, A::CREDIT_REVOKE) && A::can($auth, $actor, A::CREDIT_VIEW),
            'canRecommendColor' => A::can($auth, $actor, A::RISK_PROPOSE),
            'canRecommendLimit' => A::can($auth, $actor, A::CREDIT_PROPOSE),
        ];
    }

    // ---- internals -----------------------------------------------------------------------------------------------

    private function requireView(EmployeeIdentity $actor): void
    {
        $auth = $this->store->authorization();
        A::gate($auth, $actor);
        if (!A::can($auth, $actor, A::RISK_VIEW) && !A::can($auth, $actor, A::CREDIT_VIEW)) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');
        }
    }

    /** @return array{risk: array<string, mixed>|null, limit: array<string, mixed>|null, sequence: int, token: string} */
    private function state(string $companyId): array
    {
        $pdo = $this->store->pdo();
        $s = $pdo->prepare('SELECT * FROM finance_risk_assessments WHERE active_company_uuid = ?');
        $s->execute([$companyId]);
        $risk = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $s = $pdo->prepare('SELECT * FROM finance_credit_limits WHERE active_company_uuid = ?');
        $s->execute([$companyId]);
        $limit = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $s = $pdo->prepare('SELECT COALESCE(MAX(sequence_no), 0) FROM finance_owner_decisions WHERE company_uuid = ?');
        $s->execute([$companyId]);
        $sequence = (int) $s->fetchColumn();
        $token = hash('sha256', 'risk:' . ($risk === null ? '-' : $risk['assessment_uuid'] . ':' . $risk['version'])
            . '|credit:' . ($limit === null ? '-' : $limit['limit_uuid'] . ':' . $limit['version']) . '|seq:' . $sequence);
        return ['risk' => $risk, 'limit' => $limit, 'sequence' => $sequence, 'token' => $token];
    }

    private function expectState(array $state, string $expected): void
    {
        if (!hash_equals($state['token'], $expected)) {
            throw new ApiException(409, 'OWNER_STATE_STALE', 'The colour or limit changed since you opened this customer. Review the current state and decide again.', ['currentState' => $state['token']]);
        }
    }

    private static function stateToken(array $input): string
    {
        $value = $input['expectedState'] ?? null;
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            In::fail('expectedState', 'required');
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private function effectiveLimitBasis(string $companyId, array $facts, string $currency): array
    {
        if ($facts['verification'] !== 'VERIFIED') {
            throw new ApiException(409, 'COMPANY_NOT_VERIFIED', 'The company identity is not verified, so a limit cannot be effective yet. Record it as a non-effective instruction.');
        }
        $s = $this->store->pdo()->prepare('SELECT * FROM finance_account_onboardings WHERE company_uuid = ? FOR UPDATE');
        $s->execute([$companyId]);
        $o = $s->fetch(PDO::FETCH_ASSOC);
        if (!$o || $o['status'] !== 'RECONCILED') {
            throw new ApiException(409, 'ACCOUNT_NOT_RECONCILED', 'An effective limit needs a reconciled current account. Record it as a non-effective instruction.');
        }
        if ($o['currency_issue'] === 'open') {
            throw new ApiException(409, 'CURRENCY_ISSUE_OPEN', 'The account has an open currency issue.');
        }
        if ($o['account_currency'] !== $currency) {
            throw new ApiException(422, 'CURRENCY_MISMATCH', 'The limit currency must be the verified account currency.', ['expectedCurrency' => $o['account_currency']]);
        }
        return $o;
    }

    /** Verification, reconciliation and ledger basis stored with every owner decision. @return array<string, mixed> */
    private function basis(array $company, array $facts, array $state): array
    {
        return ['verification' => $facts['verification'], 'caseId' => $facts['basis']['caseId'], 'caseVersion' => $facts['basis']['caseVersion'],
            'reconciliation' => $facts['reconciliation'], 'onboardingId' => $facts['basis']['onboardingId'], 'onboardingVersion' => $facts['basis']['onboardingVersion'],
            'accountCurrency' => $facts['accountCurrency'], 'currencyIssue' => $facts['currencyIssue'], 'registeredCountry' => $company['country_code'],
            'companyVersion' => (int) $company['version'], 'ledger' => $facts['basis']['ledger'],
            'activeRiskId' => $state['risk']['assessment_uuid'] ?? null, 'activeLimitId' => $state['limit']['limit_uuid'] ?? null];
    }

    private function journal(EmployeeIdentity $actor, string $companyId, array $state, string $subject, string $action, ?string $assessment, ?string $limit, array $previous, array $new,
        ?string $currency, string $reason, array $basis, string $request, string $key, string $now): string
    {
        $id = Uuid::v4();
        $after = $this->state($companyId);
        $sequence = $state['sequence'] + 1;
        $tokenAfter = hash('sha256', 'risk:' . ($after['risk'] === null ? '-' : $after['risk']['assessment_uuid'] . ':' . $after['risk']['version'])
            . '|credit:' . ($after['limit'] === null ? '-' : $after['limit']['limit_uuid'] . ':' . $after['limit']['version']) . '|seq:' . $sequence);
        $snapshot = ['employeeUuid' => $actor->employeeUuid, 'username' => $actor->username, 'displayName' => $actor->displayName, 'authorizationVersion' => $actor->authorizationVersion,
            'roleKeys' => $actor->roleKeys, 'isRoot' => $actor->isRoot];
        $this->store->pdo()->prepare('INSERT INTO finance_owner_decisions (decision_uuid, company_uuid, sequence_no, decision_type, subject, action, effect, assessment_uuid, limit_uuid, previous_json, new_json,
                currency_code, reason, basis_json, actor_employee_uuid, actor_snapshot_json, authority_fingerprint, state_before, state_after, request_id, idempotency_key, decided_at)
                VALUES (?, ?, ?, \'OWNER_DIRECT\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $companyId, $sequence, $subject, $action, $action === 'INSTRUCT' ? 'NOT_EFFECTIVE' : 'EFFECTIVE', $assessment, $limit, FinanceStore::json($previous), FinanceStore::json($new),
                $currency, $reason, FinanceStore::json($basis), $actor->employeeUuid, FinanceStore::json($snapshot), $this->authority->fingerprint(), $state['token'], $tokenAfter,
                substr($request, 0, 100), $key, $now]);
        return $id;
    }

    /** Authoritative state after a command. @return array<string, mixed> */
    private function compact(string $companyId): array
    {
        $state = $this->state($companyId);
        $profile = $this->credit->profileData($companyId);
        return ['riskColor' => $state['risk']['classification'] ?? 'UNVERIFIED', 'riskMode' => $state['risk']['decision_mode'] ?? null,
            'creditLimitStatus' => $profile['creditLimitStatus'], 'approvedCreditLimit' => $profile['approvedCreditLimit'], 'creditLimitCurrency' => $profile['creditLimitCurrency'],
            'creditPurchaseEligibility' => $profile['creditPurchaseEligibility'], 'sequence' => $state['sequence'], 'state' => $state['token']];
    }

    private function name(string $employeeUuid): ?string
    {
        $s = $this->store->pdo()->prepare('SELECT display_name FROM employees WHERE employee_uuid = ?');
        $s->execute([$employeeUuid]);
        $name = $s->fetchColumn();
        return is_string($name) ? $name : null;
    }

    private static function verificationOf(?string $status): string
    {
        return $status === null ? 'NOT_STARTED' : ($status === 'APPROVED' ? 'VERIFIED' : ($status === 'REJECTED' ? 'REJECTED' : 'IN_PROGRESS'));
    }
}
