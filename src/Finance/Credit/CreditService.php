<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Credit;

use Arasya\Operations\B2B\AccountMoney;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Finance\FinanceAccess as A;
use Arasya\Operations\Finance\FinanceInput as In;
use Arasya\Operations\Finance\FinanceStore;
use Arasya\Operations\Finance\Risk\DecisionAuthority;
use Arasya\Operations\Finance\Risk\RiskService;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\ApplicationAccess;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Credit limits: proposal -> second-person verification -> approval by a third role-holder (proposer, verifier and
 * approver all distinct). A limit is bound to the verified account currency and to the reconciled onboarding and active
 * risk classification it was based on; any change of those makes the decision stale. No approved row means NO APPROVED
 * LIMIT (not zero). RED or UNVERIFIED companies get no limit. Exposure is the ledger balance in the limit currency only;
 * other currencies are shown separately and never converted.
 *
 * CEO exceptions are recorded here for a future B2B integration only; nothing in B2B reads them yet.
 */
final readonly class CreditService
{
    public function __construct(private FinanceStore $store, private RiskService $risk, private string $ceoEmployeeUuid, private DecisionAuthority $authority)
    {
    }

    public function propose(EmployeeIdentity $actor, array $input, string $key, string $request): array
    {
        In::only($input, ['companyId', 'amount', 'currency', 'effectiveFrom', 'reviewDate', 'expiresOn', 'reason', 'evidence']);
        $companyId = In::uuid($input, 'companyId');
        $cents = In::money($input, 'amount', true);
        $currency = (string) In::enum($input, 'currency', ['RON', 'EUR']);
        $from = (string) In::date($input, 'effectiveFrom');
        $review = In::date($input, 'reviewDate', false);
        $expires = In::date($input, 'expiresOn', false);
        if ($review !== null && $review < $from) {
            In::fail('reviewDate', 'before_effective_from');
        }
        if ($expires !== null && $expires < $from) {
            In::fail('expiresOn', 'before_effective_from');
        }
        $reason = (string) In::reason($input);
        $evidence = In::evidence($input);
        return $this->store->mutate($actor, 'credit_propose', $input, $key, [A::CREDIT_PROPOSE], function (string $now) use ($actor, $companyId, $cents, $currency, $from, $review, $expires, $reason, $evidence, $key, $request): array {
            $this->store->lock('b2b_companies', 'company_uuid', $companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
            [$onboarding, $assessment] = $this->basis($companyId, $currency);
            $s = $this->store->pdo()->prepare("SELECT COUNT(*) FROM finance_credit_limits WHERE company_uuid = ? AND status IN ('PROPOSED', 'UNDER_REVIEW')");
            $s->execute([$companyId]);
            if ((int) $s->fetchColumn() > 0) {
                throw new ApiException(409, 'CREDIT_PROPOSAL_PENDING', 'A credit limit proposal for this company is already in review.');
            }
            $id = Uuid::v4();
            $this->store->pdo()->prepare("INSERT INTO finance_credit_limits (limit_uuid, company_uuid, currency_code, amount, status, effective_from, review_date, expires_on, onboarding_uuid, onboarding_version,
                    risk_assessment_uuid, policy_uuid, reason, proposed_by_employee_uuid, proposed_at, version) VALUES (?, ?, ?, ?, 'PROPOSED', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)")
                ->execute([$id, $companyId, $currency, AccountMoney::format($cents), $from, $review, $expires, $onboarding['onboarding_uuid'], (int) $onboarding['version'],
                    $assessment['assessment_uuid'], $assessment['policy_uuid'], $reason, $actor->employeeUuid, $now]);
            $this->store->evidence('credit', $id, $evidence, $actor, $now);
            $this->store->event('credit', $id, $companyId, $actor, 'credit_proposed', null, 1, $reason, ['currency' => $currency, 'evidence' => count($evidence)], $request, $key, $now);
            return ['status' => 201, 'limitId' => $id];
        });
    }

    /** verify | approve | reject | suspend | reinstate | revoke */
    public function decide(EmployeeIdentity $actor, string $id, string $decision, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = In::reason($input, 'reason', !in_array($decision, ['verify', 'approve'], true));
        $permission = match ($decision) {
            'verify' => A::CREDIT_VERIFY, 'approve' => A::CREDIT_APPROVE, 'suspend', 'reinstate', 'revoke' => A::CREDIT_REVOKE, 'reject' => null,
        };
        return $this->store->mutate($actor, 'credit_' . $decision . ':' . $id, $input, $key, $permission === null ? [] : [$permission], function (string $now) use ($actor, $id, $decision, $version, $reason, $key, $request): array {
            if (in_array($decision, ['approve', 'suspend', 'reinstate', 'revoke'], true)) {
                $this->authority->require($actor);
            }
            $l = $this->store->lock('finance_credit_limits', 'limit_uuid', $id, 'LIMIT_NOT_FOUND', 'Credit limit was not found.');
            FinanceStore::expectVersion($l, $version);
            $pdo = $this->store->pdo();
            $set = [];
            switch ($decision) {
                case 'verify':
                    $this->expect($l, ['PROPOSED']);
                    if ($l['proposed_by_employee_uuid'] === $actor->employeeUuid) {
                        throw new ApiException(403, 'FOUR_EYES_REQUIRED', 'The proposer cannot verify the credit limit.');
                    }
                    $this->assertBasis($l);
                    $set = ['status' => 'UNDER_REVIEW', 'verified_by_employee_uuid' => $actor->employeeUuid, 'verified_at' => $now];
                    break;
                case 'approve':
                    $this->expect($l, ['UNDER_REVIEW']);
                    if (in_array($actor->employeeUuid, [$l['proposed_by_employee_uuid'], $l['verified_by_employee_uuid']], true)) {
                        throw new ApiException(403, 'FOUR_EYES_REQUIRED', 'The approver must differ from the proposer and the verifier.');
                    }
                    $this->store->lock('b2b_companies', 'company_uuid', (string) $l['company_uuid'], 'COMPANY_NOT_FOUND', 'Company was not found.');
                    $this->assertBasis($l);
                    $pdo->prepare("UPDATE finance_credit_limits SET status = 'SUPERSEDED', active_company_uuid = NULL, ended_by_employee_uuid = ?, ended_at = ?, end_reason = 'superseded', version = version + 1
                        WHERE active_company_uuid = ?")->execute([$actor->employeeUuid, $now, $l['company_uuid']]);
                    $set = ['status' => 'APPROVED', 'active_company_uuid' => $l['company_uuid'], 'approved_by_employee_uuid' => $actor->employeeUuid, 'approved_at' => $now];
                    break;
                case 'reject':
                    $this->expect($l, ['PROPOSED', 'UNDER_REVIEW']);
                    A::require($this->store->authorization(), $actor, $l['status'] === 'PROPOSED' ? A::CREDIT_VERIFY : A::CREDIT_APPROVE);
                    $set = ['status' => 'REJECTED', 'ended_by_employee_uuid' => $actor->employeeUuid, 'ended_at' => $now, 'end_reason' => $reason];
                    break;
                case 'suspend':
                    $this->expect($l, ['APPROVED']);
                    $set = ['status' => 'SUSPENDED', 'end_reason' => $reason];
                    break;
                case 'reinstate':
                    $this->expect($l, ['SUSPENDED']);
                    $this->assertBasis($l);
                    $set = ['status' => 'APPROVED', 'end_reason' => null];
                    break;
                case 'revoke':
                    $this->expect($l, ['APPROVED', 'SUSPENDED']);
                    $set = ['status' => 'REVOKED', 'active_company_uuid' => null, 'ended_by_employee_uuid' => $actor->employeeUuid, 'ended_at' => $now, 'end_reason' => $reason];
                    break;
            }
            $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($set)));
            $pdo->prepare("UPDATE finance_credit_limits SET {$sets}, version = version + 1 WHERE limit_uuid = ?")->execute([...array_values($set), $id]);
            $this->store->event('credit', $id, (string) $l['company_uuid'], $actor, 'credit_' . match ($decision) { 'verify' => 'verified', 'approve' => 'approved', 'reject' => 'rejected', 'suspend' => 'suspended', 'reinstate' => 'reinstated', 'revoke' => 'revoked' },
                (int) $l['version'], (int) $l['version'] + 1, $reason, null, $request, $key, $now);
            return ['status' => 200, 'limitId' => $id, 'version' => (int) $l['version'] + 1];
        });
    }

    // ---- CEO exceptions (data model only) -------------------------------------------------------------------

    public function requestException(EmployeeIdentity $actor, array $input, string $key, string $request): array
    {
        In::only($input, ['companyId', 'orderId', 'amount', 'currency', 'expiresAt', 'reason']);
        $companyId = In::uuid($input, 'companyId');
        $orderId = In::uuid($input, 'orderId');
        $cents = In::money($input, 'amount');
        $currency = (string) In::enum($input, 'currency', ['RON', 'EUR']);
        $expires = $input['expiresAt'] ?? null;
        if (!is_string($expires) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?Z$/D', $expires) !== 1) {
            In::fail('expiresAt', 'invalid');
        }
        $expiresAt = (new \DateTimeImmutable($expires))->setTimezone(new \DateTimeZone('UTC'));
        $reason = (string) In::reason($input);
        return $this->store->mutate($actor, 'exception_request', $input, $key, [A::CREDIT_PROPOSE], function (string $now) use ($actor, $companyId, $orderId, $cents, $currency, $expiresAt, $reason, $key, $request): array {
            $nowAt = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
            if ($expiresAt <= $nowAt || $expiresAt > $nowAt->modify('+30 days')) {
                In::fail('expiresAt', 'out_of_range');
            }
            $this->store->lock('b2b_companies', 'company_uuid', $companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
            $s = $this->store->pdo()->prepare('SELECT company_uuid, currency_code FROM b2b_orders WHERE order_uuid = ?');
            $s->execute([$orderId]);
            $order = $s->fetch(PDO::FETCH_ASSOC);
            if (!$order || $order['company_uuid'] !== $companyId) {
                throw new ApiException(404, 'ORDER_NOT_FOUND', 'The order was not found for this company.');
            }
            $profile = $this->profileData($companyId);
            if ($profile['riskColor'] !== 'RED') {
                throw new ApiException(409, 'EXCEPTION_NOT_APPLICABLE', 'CEO exceptions apply only to RED companies.');
            }
            if ($profile['accountCurrency'] !== $currency || $order['currency_code'] !== $currency) {
                throw new ApiException(422, 'CURRENCY_MISMATCH', 'The exception currency must match the account and the order currency.');
            }
            $snapshot = $this->snapshot($profile, $orderId, $cents, $currency);
            $id = Uuid::v4();
            $this->store->pdo()->prepare("INSERT INTO finance_credit_exceptions (exception_uuid, company_uuid, order_uuid, active_order_uuid, currency_code, amount, snapshot_json, snapshot_sha256, status, reason, expires_at,
                    requested_by_employee_uuid, requested_at, version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'REQUESTED', ?, ?, ?, ?, 1)")
                ->execute([$id, $companyId, $orderId, $orderId, $currency, AccountMoney::format($cents), FinanceStore::json($snapshot), hash('sha256', FinanceStore::json($snapshot), true),
                    $reason, $expiresAt->format('Y-m-d H:i:s.u'), $actor->employeeUuid, $now]);
            $this->store->event('exception', $id, $companyId, $actor, 'exception_requested', null, 1, $reason, null, $request, $key, $now);
            return ['status' => 201, 'exceptionId' => $id, 'snapshotSha256' => hash('sha256', FinanceStore::json($snapshot))];
        });
    }

    /** approve | reject: only the configured CEO identity; withdraw: the requester. */
    public function decideException(EmployeeIdentity $actor, string $id, string $decision, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason', 'snapshotSha256']);
        $version = In::version($input);
        $reason = In::reason($input, 'reason', $decision !== 'approve');
        $seen = $input['snapshotSha256'] ?? null;
        if ($decision === 'approve' && (!is_string($seen) || preg_match('/^[0-9a-f]{64}$/D', $seen) !== 1)) {
            In::fail('snapshotSha256', 'required');
        }
        return $this->store->mutate($actor, 'exception_' . $decision . ':' . $id, $input, $key, [], function (string $now) use ($actor, $id, $decision, $version, $reason, $seen, $key, $request): array {
            $e = $this->store->lock('finance_credit_exceptions', 'exception_uuid', $id, 'EXCEPTION_NOT_FOUND', 'Exception was not found.');
            FinanceStore::expectVersion($e, $version);
            if ($e['status'] !== 'REQUESTED') {
                throw new ApiException(409, 'EXCEPTION_DECIDED', 'The exception was already decided.');
            }
            if ($decision === 'withdraw') {
                if ($e['requested_by_employee_uuid'] !== $actor->employeeUuid) {
                    throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Only the requester can withdraw the request.');
                }
                $set = ['status' => 'WITHDRAWN', 'active_order_uuid' => null, 'decision_reason' => $reason];
            } else {
                $this->requireCeo($actor);
                if ($e['requested_by_employee_uuid'] === $actor->employeeUuid) {
                    throw new ApiException(403, 'FOUR_EYES_REQUIRED', 'The CEO cannot decide an exception they requested.');
                }
                if ($decision === 'approve') {
                    if (new \DateTimeImmutable((string) $e['expires_at'], new \DateTimeZone('UTC')) <= new \DateTimeImmutable($now, new \DateTimeZone('UTC'))) {
                        throw new ApiException(409, 'EXCEPTION_EXPIRED', 'The exception request expired.');
                    }
                    $current = $this->snapshot($this->profileData((string) $e['company_uuid']), (string) $e['order_uuid'], AccountMoney::fromDecimal((string) $e['amount']), (string) $e['currency_code']);
                    $hash = hash('sha256', FinanceStore::json($current));
                    if (!hash_equals(bin2hex((string) $e['snapshot_sha256']), $hash) || !hash_equals($hash, (string) $seen)) {
                        throw new ApiException(409, 'EXCEPTION_SNAPSHOT_STALE', 'The financial position changed since the request. A new request is needed.');
                    }
                    $set = ['status' => 'APPROVED', 'decided_by_employee_uuid' => $actor->employeeUuid, 'decided_at' => $now, 'decision_reason' => $reason];
                } else {
                    $set = ['status' => 'REJECTED', 'active_order_uuid' => null, 'decided_by_employee_uuid' => $actor->employeeUuid, 'decided_at' => $now, 'decision_reason' => $reason];
                }
            }
            $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($set)));
            $this->store->pdo()->prepare("UPDATE finance_credit_exceptions SET {$sets}, version = version + 1 WHERE exception_uuid = ?")->execute([...array_values($set), $id]);
            $this->store->event('exception', $id, (string) $e['company_uuid'], $actor, 'exception_' . ($decision === 'approve' ? 'approved' : ($decision === 'reject' ? 'rejected' : 'withdrawn')), (int) $e['version'], (int) $e['version'] + 1, $reason, null, $request, $key, $now);
            return ['status' => 200, 'exceptionId' => $id, 'version' => (int) $e['version'] + 1];
        });
    }

    // ---- reads ---------------------------------------------------------------------------------------------

    /**
     * Future B2B contract (read-only, not consumed by B2B yet). Unknown values are null or explicit status strings,
     * never 0: NO_APPROVED_LIMIT is not a zero limit and UNVERIFIED is not GREEN.
     */
    public function profile(EmployeeIdentity $actor, string $companyId): array
    {
        A::require($this->store->authorization(), $actor, A::CREDIT_VIEW);
        if (!In::isUuid($companyId)) {
            throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
        }
        return $this->profileData($companyId);
    }

    public function detail(EmployeeIdentity $actor, string $companyId): array
    {
        $profile = $this->profile($actor, $companyId);
        $pdo = $this->store->pdo();
        $s = $pdo->prepare('SELECT l.*, p.display_name AS proposer, v.display_name AS verifier, a.display_name AS approver, en.display_name AS ender, pol.policy_number, r.classification
            FROM finance_credit_limits l JOIN employees p ON p.employee_uuid = l.proposed_by_employee_uuid LEFT JOIN employees v ON v.employee_uuid = l.verified_by_employee_uuid
            LEFT JOIN employees a ON a.employee_uuid = l.approved_by_employee_uuid LEFT JOIN employees en ON en.employee_uuid = l.ended_by_employee_uuid
            LEFT JOIN finance_risk_policies pol ON pol.policy_uuid = l.policy_uuid JOIN finance_risk_assessments r ON r.assessment_uuid = l.risk_assessment_uuid
            WHERE l.company_uuid = ? ORDER BY l.proposed_at DESC LIMIT 50');
        $s->execute([$companyId]);
        $limits = array_map(fn (array $l): array => $this->presentLimit($l) + ['evidence' => $this->store->evidenceOf('credit', (string) $l['limit_uuid']), 'history' => $this->store->history('credit', (string) $l['limit_uuid'], 50)], $s->fetchAll(PDO::FETCH_ASSOC));
        $s = $pdo->prepare('SELECT x.*, r.display_name AS requester, d.display_name AS decider, o.order_code FROM finance_credit_exceptions x JOIN employees r ON r.employee_uuid = x.requested_by_employee_uuid
            LEFT JOIN employees d ON d.employee_uuid = x.decided_by_employee_uuid JOIN b2b_orders o ON o.order_uuid = x.order_uuid WHERE x.company_uuid = ? ORDER BY x.requested_at DESC LIMIT 50');
        $s->execute([$companyId]);
        $exceptions = array_map(static fn (array $x): array => ['id' => $x['exception_uuid'], 'orderId' => $x['order_uuid'], 'orderCode' => $x['order_code'], 'amount' => $x['amount'], 'currency' => $x['currency_code'],
            'status' => $x['status'], 'reason' => $x['reason'], 'expiresAt' => FinanceStore::iso($x['expires_at']), 'requestedBy' => $x['requester'], 'requestedAt' => FinanceStore::iso($x['requested_at']),
            'decidedBy' => $x['decider'], 'decidedAt' => FinanceStore::iso($x['decided_at']), 'decisionReason' => $x['decision_reason'], 'snapshotSha256' => bin2hex((string) $x['snapshot_sha256']),
            'version' => (int) $x['version']], $s->fetchAll(PDO::FETCH_ASSOC));
        return ['profile' => $profile, 'limits' => $limits, 'exceptions' => $exceptions, 'ceoApproverConfigured' => $this->ceoEmployeeUuid !== '',
            'actorIsCeoApprover' => $this->ceoEmployeeUuid !== '' && $this->ceoEmployeeUuid === $actor->employeeUuid];
    }

    public function queue(EmployeeIdentity $actor, array $q): array
    {
        A::require($this->store->authorization(), $actor, A::CREDIT_VIEW);
        In::only($q, ['status']);
        $status = $q['status'] ?? 'PROPOSED,UNDER_REVIEW';
        $statuses = explode(',', (string) $status);
        if (array_diff($statuses, ['PROPOSED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'SUSPENDED', 'REVOKED', 'SUPERSEDED']) !== []) {
            In::fail('status', 'invalid');
        }
        $s = $this->store->pdo()->prepare('SELECT l.*, p.display_name AS proposer, v.display_name AS verifier, a.display_name AS approver, en.display_name AS ender, pol.policy_number, r.classification,
                b.company_code, b.legal_name FROM finance_credit_limits l JOIN employees p ON p.employee_uuid = l.proposed_by_employee_uuid LEFT JOIN employees v ON v.employee_uuid = l.verified_by_employee_uuid
            LEFT JOIN employees a ON a.employee_uuid = l.approved_by_employee_uuid LEFT JOIN employees en ON en.employee_uuid = l.ended_by_employee_uuid
            LEFT JOIN finance_risk_policies pol ON pol.policy_uuid = l.policy_uuid JOIN finance_risk_assessments r ON r.assessment_uuid = l.risk_assessment_uuid JOIN b2b_companies b ON b.company_uuid = l.company_uuid
            WHERE l.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ') ORDER BY l.proposed_at DESC LIMIT 200');
        $s->execute($statuses);
        $items = array_map(fn (array $l): array => $this->presentLimit($l) + ['companyCode' => $l['company_code'], 'legalName' => $l['legal_name']], $s->fetchAll(PDO::FETCH_ASSOC));
        $x = $this->store->pdo()->query("SELECT x.exception_uuid, x.company_uuid, x.amount, x.currency_code, x.expires_at, x.requested_at, x.version, x.snapshot_sha256, b.legal_name, o.order_code
            FROM finance_credit_exceptions x JOIN b2b_companies b ON b.company_uuid = x.company_uuid JOIN b2b_orders o ON o.order_uuid = x.order_uuid WHERE x.status = 'REQUESTED' ORDER BY x.requested_at LIMIT 100");
        $exceptions = array_map(static fn (array $r): array => ['id' => $r['exception_uuid'], 'companyId' => $r['company_uuid'], 'legalName' => $r['legal_name'], 'orderCode' => $r['order_code'], 'amount' => $r['amount'],
            'currency' => $r['currency_code'], 'expiresAt' => FinanceStore::iso($r['expires_at']), 'requestedAt' => FinanceStore::iso($r['requested_at']), 'version' => (int) $r['version'],
            'snapshotSha256' => bin2hex((string) $r['snapshot_sha256'])], $x->fetchAll(PDO::FETCH_ASSOC));
        return ['items' => $items, 'exceptions' => $exceptions];
    }

    /** @return array<string, mixed> */
    public function profileData(string $companyId): array
    {
        $pdo = $this->store->pdo();
        $s = $pdo->prepare('SELECT company_uuid, country_code FROM b2b_companies WHERE company_uuid = ?');
        $s->execute([$companyId]);
        $company = $s->fetch(PDO::FETCH_ASSOC);
        if (!$company) {
            throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
        }
        $s = $pdo->prepare("SELECT status, approved_at FROM finance_company_cases WHERE company_uuid = ? ORDER BY status = 'APPROVED' DESC, updated_at DESC LIMIT 1");
        $s->execute([$companyId]);
        $case = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $verification = $case === null ? 'NOT_STARTED' : ($case['status'] === 'APPROVED' ? 'VERIFIED' : ($case['status'] === 'REJECTED' ? 'REJECTED' : 'IN_PROGRESS'));
        $s = $pdo->prepare('SELECT status, account_currency, version FROM finance_account_onboardings WHERE company_uuid = ?');
        $s->execute([$companyId]);
        $o = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $s = $pdo->prepare("SELECT a.assessment_uuid, a.classification, a.version, p.policy_number FROM finance_risk_assessments a LEFT JOIN finance_risk_policies p ON p.policy_uuid = a.policy_uuid WHERE a.active_company_uuid = ?");
        $s->execute([$companyId]);
        $risk = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $s = $pdo->prepare('SELECT l.*, p.policy_number FROM finance_credit_limits l LEFT JOIN finance_risk_policies p ON p.policy_uuid = l.policy_uuid WHERE l.active_company_uuid = ?');
        $s->execute([$companyId]);
        $limit = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $currency = $o['account_currency'] ?? null;
        $exposure = null;
        if ($currency !== null && $o['status'] === 'RECONCILED') {
            $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END), 0) FROM b2b_account_movements WHERE company_uuid = ? AND currency_code = ?");
            $s->execute([$companyId, $currency]);
            $exposure = AccountMoney::fromDecimal((string) $s->fetchColumn());
        }
        $limitStatus = 'NO_APPROVED_LIMIT';
        $approved = null;
        $available = null;
        if ($limit !== null) {
            $expired = $limit['expires_on'] !== null && $limit['expires_on'] < $this->store->today();
            $notYet = $limit['effective_from'] > $this->store->today();
            $limitStatus = $expired ? 'EXPIRED' : ($limit['status'] === 'SUSPENDED' ? 'SUSPENDED' : ($notYet ? 'NOT_YET_EFFECTIVE' : 'APPROVED'));
            $approved = (string) $limit['amount'];
            if ($limitStatus === 'APPROVED' && $exposure !== null && $limit['currency_code'] === $currency) {
                $available = AccountMoney::format(AccountMoney::fromDecimal((string) $limit['amount']) - max(0, $exposure));
            }
        }
        return [
            'companyId' => $companyId,
            'verificationStatus' => $verification,
            'registeredCountry' => $company['country_code'],
            'accountCurrency' => $currency,
            'reconciliationStatus' => $o['status'] ?? 'NOT_OPENED',
            'riskColor' => $risk['classification'] ?? 'UNVERIFIED',
            'creditLimitStatus' => $limitStatus,
            'approvedCreditLimit' => $approved,
            'creditLimitCurrency' => $limit['currency_code'] ?? null,
            'currentExposure' => $exposure === null ? null : AccountMoney::format($exposure),
            'availableCredit' => $available,
            'exposureBasis' => 'ledger_balance_only',
            'creditPolicyVersion' => isset($limit['policy_number']) ? (int) $limit['policy_number'] : null,
            'creditReviewRequired' => $limit !== null && ($risk === null || $limit['risk_assessment_uuid'] !== $risk['assessment_uuid'] || $o === null || (int) $limit['onboarding_version'] !== (int) $o['version']),
            'lastVerifiedAt' => $case !== null && $case['status'] === 'APPROVED' ? FinanceStore::iso($case['approved_at']) : null,
        ];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function basis(string $companyId, string $currency): array
    {
        $pdo = $this->store->pdo();
        $s = $pdo->prepare("SELECT COUNT(*) FROM finance_company_cases WHERE company_uuid = ? AND status = 'APPROVED'");
        $s->execute([$companyId]);
        if ((int) $s->fetchColumn() === 0) {
            throw new ApiException(409, 'COMPANY_NOT_VERIFIED', 'The company identity is not verified.');
        }
        $s = $pdo->prepare('SELECT * FROM finance_account_onboardings WHERE company_uuid = ? FOR UPDATE');
        $s->execute([$companyId]);
        $o = $s->fetch(PDO::FETCH_ASSOC);
        if (!$o || $o['status'] !== 'RECONCILED') {
            throw new ApiException(409, 'ACCOUNT_NOT_RECONCILED', 'A credit limit needs a reconciled current account.');
        }
        if ($o['account_currency'] !== $currency) {
            throw new ApiException(422, 'CURRENCY_MISMATCH', 'The credit limit currency must be the verified account currency.', ['accountCurrency' => $o['account_currency']]);
        }
        $s = $pdo->prepare('SELECT * FROM finance_risk_assessments WHERE active_company_uuid = ? FOR UPDATE');
        $s->execute([$companyId]);
        $a = $s->fetch(PDO::FETCH_ASSOC);
        if (!$a) {
            throw new ApiException(409, 'RISK_NOT_ASSESSED', 'The company is UNVERIFIED for risk; approve a classification first.');
        }
        if ($a['classification'] === 'RED') {
            throw new ApiException(409, 'RED_RISK_NO_CREDIT', 'A RED company receives no credit limit; only order-bound CEO exceptions exist.');
        }
        return [$o, $a];
    }

    private function assertBasis(array $l): void
    {
        [$o, $a] = $this->basis((string) $l['company_uuid'], (string) $l['currency_code']);
        if ((int) $o['version'] !== (int) $l['onboarding_version'] || $a['assessment_uuid'] !== $l['risk_assessment_uuid']) {
            throw new ApiException(409, 'CREDIT_BASIS_STALE', 'Reconciliation or risk classification changed after the proposal.');
        }
    }

    private function expect(array $l, array $statuses): void
    {
        if (!in_array($l['status'], $statuses, true)) {
            throw new ApiException(409, 'INVALID_TRANSITION', 'This status change is not allowed.', ['from' => $l['status']]);
        }
    }

    private function requireCeo(EmployeeIdentity $actor): void
    {
        if ($this->ceoEmployeeUuid === '') {
            throw new ApiException(409, 'CEO_APPROVER_NOT_CONFIGURED', 'No CEO exception approver is configured.');
        }
        if ($actor->employeeUuid !== $this->ceoEmployeeUuid || !$actor->hasApplication(ApplicationAccess::FINANCE)) {
            throw new ApiException(403, 'CEO_APPROVAL_REQUIRED', 'Only the configured CEO can decide this exception.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(array $profile, string $orderId, int $cents, string $currency): array
    {
        return ['orderId' => $orderId, 'amount' => AccountMoney::format($cents), 'currency' => $currency, 'riskColor' => $profile['riskColor'], 'currentExposure' => $profile['currentExposure'],
            'creditLimitStatus' => $profile['creditLimitStatus'], 'approvedCreditLimit' => $profile['approvedCreditLimit'], 'reconciliationStatus' => $profile['reconciliationStatus']];
    }

    /** @return array<string, mixed> */
    private function presentLimit(array $l): array
    {
        $today = $this->store->today();
        $effective = $l['status'] === 'APPROVED' && $l['expires_on'] !== null && $l['expires_on'] < $today ? 'EXPIRED' : $l['status'];
        return ['id' => $l['limit_uuid'], 'companyId' => $l['company_uuid'], 'currency' => $l['currency_code'], 'amount' => $l['amount'], 'status' => $l['status'], 'effectiveStatus' => $effective,
            'effectiveFrom' => $l['effective_from'], 'reviewDate' => $l['review_date'], 'expiresOn' => $l['expires_on'], 'riskClassification' => $l['classification'],
            'policyNumber' => $l['policy_number'] === null ? null : (int) $l['policy_number'], 'reason' => $l['reason'],
            'proposedBy' => $l['proposer'], 'proposedAt' => FinanceStore::iso($l['proposed_at']), 'verifiedBy' => $l['verifier'], 'verifiedAt' => FinanceStore::iso($l['verified_at']),
            'approvedBy' => $l['approver'], 'approvedAt' => FinanceStore::iso($l['approved_at']), 'endedBy' => $l['ender'], 'endedAt' => FinanceStore::iso($l['ended_at']), 'endReason' => $l['end_reason'],
            'version' => (int) $l['version']];
    }
}
