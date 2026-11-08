<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Risk;

use Arasya\Operations\B2B\AccountMoney;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Finance\FinanceAccess as A;
use Arasya\Operations\Finance\FinanceInput as In;
use Arasya\Operations\Finance\FinanceStore;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Risk classification (UNVERIFIED / GREEN / YELLOW / RED). Classifications are human decisions with two people:
 * a proposal and a separate approval of exactly the facts it was based on. Factual indicators are computed from the
 * ledger and the verification state. Risk policies are versioned drafts holding management thresholds as data; even an
 * approved policy only produces a suggestion for the analyst. Nothing is classified automatically and no threshold
 * is built in.
 */
final readonly class RiskService
{
    public const INDICATORS = ['outstanding', 'daysSinceOldestOpenReceivable', 'unallocatedPayments', 'creditUtilizationPercent', 'otherCurrencyBalance', 'reconciliationAgeDays'];
    private const RANK = ['YELLOW' => 1, 'RED' => 2];

    public function __construct(private FinanceStore $store, private DecisionAuthority $authority)
    {
    }

    /** Factual indicators of one company in its account currency (no thresholds applied). @return array<string, mixed> */
    public function indicators(string $companyId): array
    {
        $pdo = $this->store->pdo();
        $s = $pdo->prepare('SELECT o.*, (SELECT c.version FROM finance_company_cases c WHERE c.company_uuid = o.company_uuid AND c.status = \'APPROVED\' ORDER BY c.approved_at DESC LIMIT 1) AS case_version,
                (SELECT c.case_uuid FROM finance_company_cases c WHERE c.company_uuid = o.company_uuid AND c.status = \'APPROVED\' ORDER BY c.approved_at DESC LIMIT 1) AS case_uuid_approved
            FROM finance_account_onboardings o WHERE o.company_uuid = ?');
        $s->execute([$companyId]);
        $o = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $s = $pdo->prepare("SELECT case_uuid, version FROM finance_company_cases WHERE company_uuid = ? AND status = 'APPROVED' ORDER BY approved_at DESC LIMIT 1");
        $s->execute([$companyId]);
        $case = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $currency = $o['account_currency'] ?? null;
        $balances = [];
        $s = $pdo->prepare("SELECT currency_code, COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END), 0) balance FROM b2b_account_movements WHERE company_uuid = ? GROUP BY currency_code");
        $s->execute([$companyId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $balances[$r['currency_code']] = AccountMoney::fromDecimal((string) $r['balance']);
        }
        $outstanding = $currency === null ? null : ($balances[$currency] ?? 0);
        $other = null;
        foreach ($balances as $code => $cents) {
            if ($code !== $currency && $cents !== 0) {
                $other = ($other ?? []) + [$code => AccountMoney::format($cents)];
            }
        }
        $oldest = null;
        $unallocated = null;
        if ($currency !== null) {
            $s = $pdo->prepare("SELECT MIN(m.value_date) FROM b2b_account_movements m
                WHERE m.company_uuid = ? AND m.currency_code = ? AND m.direction = 'debit' AND m.movement_type IN ('order_receivable', 'opening_balance', 'adjustment')
                AND NOT EXISTS (SELECT 1 FROM b2b_account_movements r WHERE r.reversed_movement_uuid = m.movement_uuid)
                AND m.amount > COALESCE((SELECT SUM(a.amount) FROM b2b_account_allocations a LEFT JOIN b2b_account_allocation_releases x ON x.allocation_uuid = a.allocation_uuid
                    WHERE a.receivable_movement_uuid = m.movement_uuid AND x.allocation_uuid IS NULL), 0)");
            $s->execute([$companyId, $currency]);
            $date = $s->fetchColumn();
            if (is_string($date)) {
                $oldest = (int) (new \DateTimeImmutable($date))->diff(new \DateTimeImmutable($this->store->today()))->format('%a');
            }
            $s = $pdo->prepare("SELECT COALESCE(SUM(m.amount - COALESCE((SELECT SUM(a.amount) FROM b2b_account_allocations a LEFT JOIN b2b_account_allocation_releases x ON x.allocation_uuid = a.allocation_uuid
                    WHERE a.payment_movement_uuid = m.movement_uuid AND x.allocation_uuid IS NULL), 0)), 0)
                FROM b2b_account_movements m WHERE m.company_uuid = ? AND m.currency_code = ? AND m.movement_type = 'payment'
                AND NOT EXISTS (SELECT 1 FROM b2b_account_movements r WHERE r.reversed_movement_uuid = m.movement_uuid)");
            $s->execute([$companyId, $currency]);
            $unallocated = AccountMoney::fromDecimal((string) $s->fetchColumn());
        }
        $s = $pdo->prepare('SELECT amount, currency_code FROM finance_credit_limits WHERE active_company_uuid = ? AND status = \'APPROVED\'');
        $s->execute([$companyId]);
        $limit = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $utilization = null;
        if ($limit !== null && $outstanding !== null && $limit['currency_code'] === $currency && AccountMoney::fromDecimal((string) $limit['amount']) > 0) {
            $utilization = round(max(0, $outstanding) * 100 / AccountMoney::fromDecimal((string) $limit['amount']), 1);
        }
        $reconciledAge = null;
        if ($o !== null && $o['status'] === 'RECONCILED') {
            $reconciledAge = (int) (new \DateTimeImmutable((string) ($o['posted_at'] ?? $o['verified_at'])))->diff(new \DateTimeImmutable($this->store->now()))->format('%a');
        }
        $ledgerParts = [];
        foreach ($balances as $code => $cents) {
            $ledgerParts[] = $code . ':' . $cents;
        }
        $s = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(movement_number), 0) FROM b2b_account_movements WHERE company_uuid = ?');
        $s->execute([$companyId]);
        [$count, $last] = $s->fetch(PDO::FETCH_NUM);
        return [
            'verification' => $case === null ? 'UNVERIFIED' : 'VERIFIED',
            'reconciliation' => $o['status'] ?? 'NOT_OPENED',
            'accountCurrency' => $currency,
            'currencyIssue' => $o['currency_issue'] ?? null,
            'values' => [
                'outstanding' => $outstanding === null ? null : AccountMoney::format($outstanding),
                'daysSinceOldestOpenReceivable' => $oldest,
                'unallocatedPayments' => $unallocated === null ? null : AccountMoney::format($unallocated),
                'creditUtilizationPercent' => $utilization,
                'otherCurrencyBalance' => $other,
                'reconciliationAgeDays' => $reconciledAge,
            ],
            'notes' => ['dueDatesNotRecorded'],
            'basis' => [
                'caseId' => $case['case_uuid'] ?? null, 'caseVersion' => $case === null ? null : (int) $case['version'],
                'onboardingId' => $o['onboarding_uuid'] ?? null, 'onboardingVersion' => $o === null ? null : (int) $o['version'], 'onboardingStatus' => $o['status'] ?? null,
                'ledger' => hash('sha256', implode('|', $ledgerParts) . '|' . $count . '|' . $last),
            ],
        ];
    }

    public function propose(EmployeeIdentity $actor, array $input, string $key, string $request): array
    {
        In::only($input, ['companyId', 'classification', 'reason', 'policyId', 'evidence']);
        $companyId = In::uuid($input, 'companyId');
        $classification = (string) In::enum($input, 'classification', ['GREEN', 'YELLOW', 'RED']);
        $reason = (string) In::reason($input);
        $policyId = ($input['policyId'] ?? null) === null ? null : In::uuid($input, 'policyId');
        $evidence = In::evidence($input);
        return $this->store->mutate($actor, 'risk_propose', $input, $key, [A::RISK_PROPOSE], function (string $now) use ($actor, $companyId, $classification, $reason, $policyId, $evidence, $key, $request): array {
            $this->store->lock('b2b_companies', 'company_uuid', $companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
            $facts = $this->indicators($companyId);
            if ($facts['verification'] !== 'VERIFIED') {
                throw new ApiException(409, 'COMPANY_NOT_VERIFIED', 'An unverified company stays UNVERIFIED; verify its identity first.');
            }
            if ($classification === 'GREEN' && ($facts['reconciliation'] !== 'RECONCILED' || $facts['currencyIssue'] === 'open')) {
                throw new ApiException(409, 'GREEN_REQUIRES_RECONCILIATION', 'GREEN requires a reconciled current account without open currency issues.');
            }
            $s = $this->store->pdo()->prepare("SELECT COUNT(*) FROM finance_risk_assessments WHERE company_uuid = ? AND status = 'PROPOSED'");
            $s->execute([$companyId]);
            if ((int) $s->fetchColumn() > 0) {
                throw new ApiException(409, 'RISK_PROPOSAL_PENDING', 'A risk proposal for this company is already waiting for approval.');
            }
            if ($policyId !== null) {
                $this->approvedPolicy($policyId);
            }
            $id = Uuid::v4();
            $this->store->pdo()->prepare("INSERT INTO finance_risk_assessments (assessment_uuid, company_uuid, classification, status, indicators_json, basis_json, policy_uuid, reason, proposed_by_employee_uuid, proposed_at, version)
                VALUES (?, ?, ?, 'PROPOSED', ?, ?, ?, ?, ?, ?, 1)")
                ->execute([$id, $companyId, $classification, FinanceStore::json($facts['values']), FinanceStore::json($facts['basis']), $policyId, $reason, $actor->employeeUuid, $now]);
            $this->store->evidence('risk', $id, $evidence, $actor, $now);
            $this->store->event('risk', $id, $companyId, $actor, 'risk_proposed', null, 1, $reason, ['classification' => $classification], $request, $key, $now);
            return ['status' => 201, 'assessmentId' => $id];
        });
    }

    public function decide(EmployeeIdentity $actor, string $id, string $decision, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = In::reason($input, 'reason', $decision !== 'approve');
        return $this->store->mutate($actor, 'risk_' . $decision . ':' . $id, $input, $key, [A::RISK_APPROVE], function (string $now) use ($actor, $id, $decision, $version, $reason, $key, $request): array {
            if ($decision !== 'reject') {
                $this->authority->require($actor);
            }
            $a = $this->store->lock('finance_risk_assessments', 'assessment_uuid', $id, 'ASSESSMENT_NOT_FOUND', 'Risk assessment was not found.');
            FinanceStore::expectVersion($a, $version);
            $pdo = $this->store->pdo();
            if ($decision === 'suspend') {
                if ($a['status'] !== 'APPROVED') {
                    throw new ApiException(409, 'ASSESSMENT_NOT_ACTIVE', 'Only the active classification can be suspended.');
                }
                $pdo->prepare("UPDATE finance_risk_assessments SET status = 'SUSPENDED', active_company_uuid = NULL, decision_reason = ?, version = version + 1 WHERE assessment_uuid = ?")->execute([$reason, $id]);
                $this->store->event('risk', $id, $a['company_uuid'], $actor, 'risk_suspended', (int) $a['version'], (int) $a['version'] + 1, $reason, null, $request, $key, $now);
                return ['status' => 200, 'assessmentId' => $id, 'version' => (int) $a['version'] + 1];
            }
            if ($a['status'] !== 'PROPOSED') {
                throw new ApiException(409, 'ASSESSMENT_DECIDED', 'The proposal was already decided.');
            }
            if ($decision === 'reject') {
                $pdo->prepare("UPDATE finance_risk_assessments SET status = 'REJECTED', decided_by_employee_uuid = ?, decided_at = ?, decision_reason = ?, version = version + 1 WHERE assessment_uuid = ?")
                    ->execute([$actor->employeeUuid, $now, $reason, $id]);
                $this->store->event('risk', $id, $a['company_uuid'], $actor, 'risk_rejected', (int) $a['version'], (int) $a['version'] + 1, $reason, null, $request, $key, $now);
                return ['status' => 200, 'assessmentId' => $id, 'version' => (int) $a['version'] + 1];
            }
            if ($a['proposed_by_employee_uuid'] === $actor->employeeUuid) {
                throw new ApiException(403, 'FOUR_EYES_REQUIRED', 'The proposer cannot approve the classification.');
            }
            $this->store->lock('b2b_companies', 'company_uuid', (string) $a['company_uuid'], 'COMPANY_NOT_FOUND', 'Company was not found.');
            $facts = $this->indicators((string) $a['company_uuid']);
            if ($facts['basis'] != json_decode((string) $a['basis_json'], true)) {
                throw new ApiException(409, 'RISK_BASIS_STALE', 'Verification, reconciliation or ledger changed after the proposal. Propose again on current facts.');
            }
            if ($a['classification'] === 'GREEN' && ($facts['reconciliation'] !== 'RECONCILED' || $facts['currencyIssue'] === 'open')) {
                throw new ApiException(409, 'GREEN_REQUIRES_RECONCILIATION', 'GREEN requires a reconciled current account.');
            }
            if ($a['policy_uuid'] !== null) {
                $this->approvedPolicy((string) $a['policy_uuid']);
            }
            $pdo->prepare("UPDATE finance_risk_assessments SET status = 'SUPERSEDED', active_company_uuid = NULL, version = version + 1 WHERE active_company_uuid = ?")->execute([$a['company_uuid']]);
            $pdo->prepare("UPDATE finance_risk_assessments SET status = 'APPROVED', active_company_uuid = company_uuid, decided_by_employee_uuid = ?, decided_at = ?, decision_reason = ?, version = version + 1 WHERE assessment_uuid = ?")
                ->execute([$actor->employeeUuid, $now, $reason, $id]);
            $this->store->event('risk', $id, $a['company_uuid'], $actor, 'risk_approved', (int) $a['version'], (int) $a['version'] + 1, $reason, ['classification' => $a['classification']], $request, $key, $now);
            return ['status' => 200, 'assessmentId' => $id, 'version' => (int) $a['version'] + 1];
        });
    }

    // ---- policies ------------------------------------------------------------------------------------------

    public function createPolicy(EmployeeIdentity $actor, array $input, string $key, string $request): array
    {
        In::only($input, ['title', 'definition']);
        $title = (string) In::text($input, 'title', 160, true);
        $definition = self::definition($input['definition'] ?? null);
        return $this->store->mutate($actor, 'risk_policy_create', $input, $key, [A::RISK_PROPOSE], function (string $now) use ($actor, $title, $definition, $key, $request): array {
            $pdo = $this->store->pdo();
            $number = (int) $pdo->query('SELECT COALESCE(MAX(policy_number), 0) + 1 FROM finance_risk_policies FOR UPDATE')->fetchColumn();
            $id = Uuid::v4();
            $pdo->prepare("INSERT INTO finance_risk_policies (policy_uuid, policy_number, title, definition_json, status, created_by_employee_uuid, created_at, version) VALUES (?, ?, ?, ?, 'draft', ?, ?, 1)")
                ->execute([$id, $number, $title, FinanceStore::json($definition), $actor->employeeUuid, $now]);
            $this->store->event('policy', $id, null, $actor, 'policy_drafted', null, 1, null, ['number' => $number, 'rules' => count($definition['rules'])], $request, $key, $now);
            return ['status' => 201, 'policyId' => $id, 'number' => $number];
        });
    }

    public function decidePolicy(EmployeeIdentity $actor, string $id, string $decision, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = In::reason($input, 'reason', false);
        return $this->store->mutate($actor, 'risk_policy_' . $decision . ':' . $id, $input, $key, [A::RISK_APPROVE], function (string $now) use ($actor, $id, $decision, $version, $reason, $key, $request): array {
            $p = $this->store->lock('finance_risk_policies', 'policy_uuid', $id, 'POLICY_NOT_FOUND', 'Risk policy was not found.');
            FinanceStore::expectVersion($p, $version);
            $pdo = $this->store->pdo();
            if ($decision === 'approve') {
                if ($p['status'] !== 'draft') {
                    throw new ApiException(409, 'POLICY_NOT_DRAFT', 'Only a draft policy can be approved.');
                }
                if ($p['created_by_employee_uuid'] === $actor->employeeUuid) {
                    throw new ApiException(403, 'FOUR_EYES_REQUIRED', 'The author cannot approve the policy.');
                }
                $pdo->prepare("UPDATE finance_risk_policies SET status = 'retired', retired_at = ?, version = version + 1 WHERE status = 'approved'")->execute([$now]);
                $pdo->prepare("UPDATE finance_risk_policies SET status = 'approved', approved_by_employee_uuid = ?, approved_at = ?, version = version + 1 WHERE policy_uuid = ?")->execute([$actor->employeeUuid, $now, $id]);
            } else {
                if ($p['status'] !== 'approved') {
                    throw new ApiException(409, 'POLICY_NOT_APPROVED', 'Only the approved policy can be retired.');
                }
                $pdo->prepare("UPDATE finance_risk_policies SET status = 'retired', retired_at = ?, version = version + 1 WHERE policy_uuid = ?")->execute([$now, $id]);
            }
            $this->store->event('policy', $id, null, $actor, 'policy_' . ($decision === 'approve' ? 'approved' : 'retired'), (int) $p['version'], (int) $p['version'] + 1, $reason, null, $request, $key, $now);
            return ['status' => 200, 'policyId' => $id, 'version' => (int) $p['version'] + 1];
        });
    }

    /** Suggestion of the approved policy for given indicators; never stored as a classification. */
    public function suggestion(array $values): ?array
    {
        $s = $this->store->pdo()->query("SELECT policy_uuid, policy_number, definition_json FROM finance_risk_policies WHERE status = 'approved' LIMIT 1");
        $policy = $s->fetch(PDO::FETCH_ASSOC);
        if (!$policy) {
            return null;
        }
        $worst = null;
        $triggered = [];
        foreach ((array) json_decode((string) $policy['definition_json'], true)['rules'] as $rule) {
            $value = $values[$rule['indicator']] ?? null;
            if ($value === null || is_array($value)) {
                continue;
            }
            $v = (float) $value;
            $t = (float) $rule['value'];
            $hit = match ($rule['operator']) { '>=' => $v >= $t, '>' => $v > $t, '<=' => $v <= $t, '<' => $v < $t };
            if ($hit) {
                $triggered[] = $rule;
                if ($worst === null || self::RANK[$rule['classification']] > self::RANK[$worst]) {
                    $worst = $rule['classification'];
                }
            }
        }
        return ['policyId' => $policy['policy_uuid'], 'policyNumber' => (int) $policy['policy_number'], 'suggested' => $worst ?? 'NO_RULE_TRIGGERED', 'triggered' => $triggered];
    }

    public function view(EmployeeIdentity $actor, string $companyId): array
    {
        A::require($this->store->authorization(), $actor, A::RISK_VIEW);
        if (!In::isUuid($companyId)) {
            throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
        }
        $facts = $this->indicators($companyId);
        $s = $this->store->pdo()->prepare('SELECT a.*, p.display_name AS proposer, d.display_name AS decider, pol.policy_number FROM finance_risk_assessments a
            JOIN employees p ON p.employee_uuid = a.proposed_by_employee_uuid LEFT JOIN employees d ON d.employee_uuid = a.decided_by_employee_uuid
            LEFT JOIN finance_risk_policies pol ON pol.policy_uuid = a.policy_uuid WHERE a.company_uuid = ? ORDER BY a.proposed_at DESC LIMIT 50');
        $s->execute([$companyId]);
        $items = array_map(fn (array $a): array => self::presentAssessment($a) + ['evidence' => $this->store->evidenceOf('risk', (string) $a['assessment_uuid'])], $s->fetchAll(PDO::FETCH_ASSOC));
        $active = null;
        foreach ($items as $item) {
            if ($item['status'] === 'APPROVED') {
                $active = $item;
            }
        }
        return ['companyId' => $companyId, 'classification' => $active['classification'] ?? 'UNVERIFIED', 'active' => $active, 'facts' => $facts,
            'suggestion' => $this->suggestion($facts['values']), 'assessments' => $items];
    }

    public function queue(EmployeeIdentity $actor, array $q): array
    {
        A::require($this->store->authorization(), $actor, A::RISK_VIEW);
        In::only($q, ['status']);
        $status = $q['status'] ?? 'PROPOSED';
        if (!in_array($status, ['PROPOSED', 'APPROVED', 'REJECTED', 'SUSPENDED', 'SUPERSEDED'], true)) {
            In::fail('status', 'invalid');
        }
        $s = $this->store->pdo()->prepare('SELECT a.*, p.display_name AS proposer, d.display_name AS decider, pol.policy_number, b.company_code, b.legal_name FROM finance_risk_assessments a
            JOIN employees p ON p.employee_uuid = a.proposed_by_employee_uuid LEFT JOIN employees d ON d.employee_uuid = a.decided_by_employee_uuid
            LEFT JOIN finance_risk_policies pol ON pol.policy_uuid = a.policy_uuid JOIN b2b_companies b ON b.company_uuid = a.company_uuid
            WHERE a.status = ? ORDER BY a.proposed_at DESC LIMIT 200');
        $s->execute([$status]);
        return ['items' => array_map(static fn (array $a): array => self::presentAssessment($a) + ['companyCode' => $a['company_code'], 'legalName' => $a['legal_name']], $s->fetchAll(PDO::FETCH_ASSOC))];
    }

    public function policies(EmployeeIdentity $actor): array
    {
        A::require($this->store->authorization(), $actor, A::RISK_VIEW);
        $s = $this->store->pdo()->query('SELECT p.*, c.display_name AS creator, a.display_name AS approver FROM finance_risk_policies p JOIN employees c ON c.employee_uuid = p.created_by_employee_uuid
            LEFT JOIN employees a ON a.employee_uuid = p.approved_by_employee_uuid ORDER BY p.policy_number DESC LIMIT 100');
        return ['items' => array_map(static fn (array $p): array => ['id' => $p['policy_uuid'], 'number' => (int) $p['policy_number'], 'title' => $p['title'], 'status' => $p['status'],
            'definition' => json_decode((string) $p['definition_json'], true), 'createdBy' => $p['creator'], 'createdAt' => FinanceStore::iso($p['created_at']),
            'approvedBy' => $p['approver'], 'approvedAt' => FinanceStore::iso($p['approved_at']), 'version' => (int) $p['version']], $s->fetchAll(PDO::FETCH_ASSOC)), 'indicators' => self::INDICATORS];
    }

    /** @return array<string, mixed> */
    private static function presentAssessment(array $a): array
    {
        return ['id' => $a['assessment_uuid'], 'companyId' => $a['company_uuid'], 'classification' => $a['classification'], 'status' => $a['status'],
            'indicators' => json_decode((string) $a['indicators_json'], true), 'reason' => $a['reason'], 'policyNumber' => $a['policy_number'] === null ? null : (int) $a['policy_number'],
            'proposedBy' => $a['proposer'], 'proposedAt' => FinanceStore::iso($a['proposed_at']), 'decidedBy' => $a['decider'], 'decidedAt' => FinanceStore::iso($a['decided_at']),
            'decisionReason' => $a['decision_reason'], 'version' => (int) $a['version']];
    }

    private function approvedPolicy(string $policyId): void
    {
        $s = $this->store->pdo()->prepare("SELECT status FROM finance_risk_policies WHERE policy_uuid = ?");
        $s->execute([$policyId]);
        if ($s->fetchColumn() !== 'approved') {
            throw new ApiException(409, 'POLICY_NOT_APPROVED', 'Only the approved risk policy may be referenced.');
        }
    }

    /** @return array{rules: list<array{indicator: string, operator: string, value: string, classification: string}>} */
    private static function definition(mixed $value): array
    {
        if (!is_array($value) || array_keys($value) !== ['rules'] || !is_array($value['rules']) || !array_is_list($value['rules']) || count($value['rules']) > 30) {
            In::fail('definition', 'invalid');
        }
        $rules = [];
        foreach ($value['rules'] as $rule) {
            if (!is_array($rule)) {
                In::fail('definition', 'invalid_rule');
            }
            In::only($rule, ['indicator', 'operator', 'value', 'classification']);
            $indicator = In::enum($rule, 'indicator', array_values(array_diff(self::INDICATORS, ['otherCurrencyBalance'])));
            $operator = In::enum($rule, 'operator', ['>=', '>', '<=', '<']);
            $threshold = $rule['value'] ?? null;
            if (!is_string($threshold) || preg_match('/^\d{1,12}(\.\d{1,2})?$/D', $threshold) !== 1) {
                In::fail('definition', 'invalid_value');
            }
            $rules[] = ['indicator' => (string) $indicator, 'operator' => (string) $operator, 'value' => $threshold, 'classification' => (string) In::enum($rule, 'classification', ['YELLOW', 'RED'])];
        }
        return ['rules' => $rules];
    }
}
