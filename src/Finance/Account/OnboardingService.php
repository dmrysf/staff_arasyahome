<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Account;

use Arasya\Operations\B2B\AccountLedger;
use Arasya\Operations\B2B\AccountMoney;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Finance\FinanceAccess as A;
use Arasya\Operations\Finance\FinanceInput as In;
use Arasya\Operations\Finance\FinanceStore;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Current-account onboarding of a verified canonical company. The B2B ledger stays the only balance: Finance holds a
 * reviewed opening-balance proposal and, once two people agreed and posting is switched on, writes exactly one
 * opening_balance movement through the existing AccountLedger primitives (or adopts an identical one already there).
 *
 * Unknown is never zero: NO_INFORMATION carries no amount; a zero is only ever a verified ZERO.
 * Duplicate protection: one onboarding per company (unique), one posted movement per onboarding (unique), the ledger's
 * own "one active opening per currency" rule re-checked under the company lock, ledger movements already dated on or
 * before the cut-off must be explicitly excluded from the opening amount, and the ledger fingerprint taken at
 * submission must be unchanged at verification and posting.
 */
final readonly class OnboardingService
{
    private AccountLedger $ledger;

    public function __construct(private FinanceStore $store, private bool $postingEnabled)
    {
        $this->ledger = new AccountLedger($store->pdo());
    }

    public function postingEnabled(): bool
    {
        return $this->postingEnabled;
    }

    public function open(EmployeeIdentity $actor, array $input, string $key, string $request): array
    {
        In::only($input, ['companyId']);
        $companyId = In::uuid($input, 'companyId');
        return $this->store->mutate($actor, 'onboarding_open', $input, $key, [A::ACCOUNTS_RECONCILE], function (string $now) use ($actor, $companyId, $key, $request): array {
            $company = $this->lockCompany($companyId);
            $case = $this->approvedCase($companyId);
            if ($case['country_code'] !== $company['country_code']) {
                throw new ApiException(409, 'COUNTRY_MISMATCH', 'The verified country differs from the company record. Correct the company record first.');
            }
            $s = $this->store->pdo()->prepare('SELECT onboarding_uuid FROM finance_account_onboardings WHERE company_uuid = ?');
            $s->execute([$companyId]);
            if (($existing = $s->fetchColumn()) !== false) {
                throw new ApiException(409, 'ONBOARDING_EXISTS', 'The current account is already being onboarded.', ['onboardingId' => (string) $existing]);
            }
            $currency = CurrencyPolicy::forCountry($company['country_code']);
            $ledger = $this->ledgerView($companyId, $currency);
            $id = Uuid::v4();
            $this->store->pdo()->prepare("INSERT INTO finance_account_onboardings (onboarding_uuid, company_uuid, case_uuid, country_code, account_currency, status, currency_issue,
                    created_by_employee_uuid, created_at, updated_at, version) VALUES (?, ?, ?, ?, ?, 'NO_INFORMATION', ?, ?, ?, ?, 1)")
                ->execute([$id, $companyId, $case['case_uuid'], $company['country_code'], $currency, $ledger['otherCurrencyMovements'] > 0 ? 'open' : 'none', $actor->employeeUuid, $now, $now]);
            $this->store->event('onboarding', $id, $companyId, $actor, 'onboarding_opened', null, 1, null, ['currency' => $currency, 'country' => $company['country_code'], 'currencyIssue' => $ledger['otherCurrencyMovements'] > 0], $request, $key, $now);
            return ['status' => 201, 'onboardingId' => $id, 'accountCurrency' => $currency];
        });
    }

    /** Draft (or redraft) of the opening balance proposal with its evidence. */
    public function draft(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'balanceKind', 'amount', 'asOfDate', 'note', 'evidence']);
        $version = In::version($input);
        $kind = (string) In::enum($input, 'balanceKind', ['ZERO', 'DEBIT', 'CREDIT']);
        $cents = $kind === 'ZERO' ? In::money($input, 'amount', true) : In::money($input, 'amount');
        if ($kind === 'ZERO' && $cents !== 0) {
            In::fail('amount', 'must_be_zero');
        }
        $asOf = (string) In::date($input, 'asOfDate');
        $note = In::reason($input, 'note');
        $evidence = In::evidence($input);
        return $this->store->mutate($actor, 'onboarding_draft:' . $id, $input, $key, [A::ACCOUNTS_PROPOSE], function (string $now) use ($actor, $id, $version, $kind, $cents, $asOf, $note, $evidence, $key, $request): array {
            $o = $this->lockOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            if (!in_array($o['status'], ['NO_INFORMATION', 'AWAITING_RECONCILIATION', 'DISPUTED'], true)) {
                throw new ApiException(409, 'ONBOARDING_NOT_EDITABLE', 'The proposal can no longer be edited.');
            }
            $this->approvedCase((string) $o['company_uuid']);
            if ($asOf > $this->store->today()) {
                In::fail('asOfDate', 'future');
            }
            $ledger = $this->ledgerView((string) $o['company_uuid'], (string) $o['account_currency'], $asOf);
            $opening = $ledger['activeOpening'];
            if ($opening !== null && ($kind === 'ZERO' || $opening['direction'] !== strtolower($kind) || AccountMoney::fromDecimal($opening['amount']) !== $cents)) {
                throw new ApiException(409, 'LEDGER_OPENING_DIFFERS', 'The ledger already holds a different opening balance in this currency. Resolve it in the ledger before proposing another.', ['opening' => $opening]);
            }
            $overlap = $ledger['movementsOnOrBeforeCutoff'] > 0 ? 'excluded_from_opening' : 'no_prior_movements';
            $this->store->evidence('onboarding', $id, $evidence, $actor, $now);
            $this->update($o, ['status' => 'AWAITING_RECONCILIATION', 'balance_kind' => $kind, 'amount' => AccountMoney::format($cents), 'as_of_date' => $asOf, 'overlap_resolution' => $overlap,
                'proposal_note' => $note, 'dispute_reason' => null, 'proposed_by_employee_uuid' => $actor->employeeUuid, 'proposed_at' => $now, 'ledger_fingerprint' => null], $now);
            $this->store->event('onboarding', $id, (string) $o['company_uuid'], $actor, 'opening_drafted', (int) $o['version'], (int) $o['version'] + 1, null,
                ['kind' => $kind, 'overlap' => $overlap, 'evidence' => count($evidence)], $request, $key, $now);
            return ['status' => 200, 'onboardingId' => $id, 'version' => (int) $o['version'] + 1, 'overlapResolution' => $overlap];
        });
    }

    public function resolveCurrencyIssue(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'note']);
        $version = In::version($input);
        $note = (string) In::reason($input, 'note');
        return $this->store->mutate($actor, 'onboarding_currency_issue:' . $id, $input, $key, [A::ACCOUNTS_RECONCILE], function (string $now) use ($actor, $id, $version, $note, $key, $request): array {
            $o = $this->lockOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            if ($o['currency_issue'] !== 'open') {
                throw new ApiException(409, 'NO_CURRENCY_ISSUE', 'There is no open currency inconsistency.');
            }
            $this->update($o, ['currency_issue' => 'resolved', 'currency_issue_note' => $note], $now);
            $this->store->event('onboarding', $id, (string) $o['company_uuid'], $actor, 'currency_issue_resolved', (int) $o['version'], (int) $o['version'] + 1, $note, null, $request, $key, $now);
            return ['status' => 200, 'onboardingId' => $id, 'version' => (int) $o['version'] + 1];
        });
    }

    public function dispute(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = (string) In::reason($input);
        return $this->store->mutate($actor, 'onboarding_dispute:' . $id, $input, $key, [A::ACCOUNTS_RECONCILE], function (string $now) use ($actor, $id, $version, $reason, $key, $request): array {
            $o = $this->lockOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            if (!in_array($o['status'], ['NO_INFORMATION', 'AWAITING_RECONCILIATION', 'SUBMITTED'], true)) {
                throw new ApiException(409, 'ONBOARDING_NOT_DISPUTABLE', 'This onboarding cannot be marked disputed now.');
            }
            $this->update($o, ['status' => 'DISPUTED', 'dispute_reason' => $reason, 'ledger_fingerprint' => null], $now);
            $this->store->event('onboarding', $id, (string) $o['company_uuid'], $actor, 'balance_disputed', (int) $o['version'], (int) $o['version'] + 1, $reason, null, $request, $key, $now);
            return ['status' => 200, 'onboardingId' => $id, 'version' => (int) $o['version'] + 1];
        });
    }

    public function submit(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion']);
        $version = In::version($input);
        return $this->store->mutate($actor, 'onboarding_submit:' . $id, $input, $key, [A::ACCOUNTS_PROPOSE], function (string $now) use ($actor, $id, $version, $key, $request): array {
            $o = $this->lockOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            if ($o['status'] !== 'AWAITING_RECONCILIATION' || $o['balance_kind'] === null) {
                throw new ApiException(409, 'ONBOARDING_NOT_DRAFTED', 'Draft the opening balance first.');
            }
            if ($o['currency_issue'] === 'open') {
                throw new ApiException(409, 'CURRENCY_ISSUE_OPEN', 'Resolve the currency inconsistency first.');
            }
            if (count($this->store->evidenceOf('onboarding', $id)) === 0) {
                throw new ApiException(409, 'EVIDENCE_REQUIRED', 'Link at least one supporting evidence reference.');
            }
            $this->approvedCase((string) $o['company_uuid']);
            $ledger = $this->ledgerView((string) $o['company_uuid'], (string) $o['account_currency'], (string) $o['as_of_date']);
            $this->update($o, ['status' => 'SUBMITTED', 'proposed_by_employee_uuid' => $actor->employeeUuid, 'proposed_at' => $now, 'ledger_fingerprint' => hex2bin($ledger['fingerprint'])], $now);
            $this->store->event('onboarding', $id, (string) $o['company_uuid'], $actor, 'opening_submitted', (int) $o['version'], (int) $o['version'] + 1, null, null, $request, $key, $now);
            return ['status' => 200, 'onboardingId' => $id, 'version' => (int) $o['version'] + 1];
        });
    }

    /** Second-person verification. A verified ZERO is reconciled at once (nothing to post). */
    public function verify(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = In::reason($input, 'reason', false);
        return $this->store->mutate($actor, 'onboarding_verify:' . $id, $input, $key, [A::ACCOUNTS_VERIFY], function (string $now) use ($actor, $id, $version, $reason, $key, $request): array {
            $o = $this->lockOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            if ($o['status'] !== 'SUBMITTED') {
                throw new ApiException(409, 'ONBOARDING_NOT_SUBMITTED', 'Only a submitted proposal can be verified.');
            }
            $this->requireSecondPerson($o, $actor);
            $this->approvedCase((string) $o['company_uuid']);
            $this->assertLedgerUnchanged($o);
            $status = $o['balance_kind'] === 'ZERO' ? 'RECONCILED' : 'VERIFIED';
            $this->update($o, ['status' => $status, 'verified_by_employee_uuid' => $actor->employeeUuid, 'verified_at' => $now], $now);
            $this->store->event('onboarding', $id, (string) $o['company_uuid'], $actor, 'opening_verified', (int) $o['version'], (int) $o['version'] + 1, $reason, ['result' => $status], $request, $key, $now);
            return ['status' => 200, 'onboardingId' => $id, 'version' => (int) $o['version'] + 1, 'onboardingStatus' => $status];
        });
    }

    /** Returns a submitted proposal to the drafter (verifier disagrees). */
    public function returnProposal(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = (string) In::reason($input);
        return $this->store->mutate($actor, 'onboarding_return:' . $id, $input, $key, [A::ACCOUNTS_VERIFY], function (string $now) use ($actor, $id, $version, $reason, $key, $request): array {
            $o = $this->lockOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            if (!in_array($o['status'], ['SUBMITTED', 'VERIFIED'], true)) {
                throw new ApiException(409, 'ONBOARDING_NOT_SUBMITTED', 'Only a submitted or verified proposal can be returned.');
            }
            $this->update($o, ['status' => 'AWAITING_RECONCILIATION', 'verified_by_employee_uuid' => null, 'verified_at' => null, 'ledger_fingerprint' => null, 'dispute_reason' => $reason], $now);
            $this->store->event('onboarding', $id, (string) $o['company_uuid'], $actor, 'opening_returned', (int) $o['version'], (int) $o['version'] + 1, $reason, null, $request, $key, $now);
            return ['status' => 200, 'onboardingId' => $id, 'version' => (int) $o['version'] + 1];
        });
    }

    /**
     * Posts the verified opening balance to the B2B ledger. Disabled unless ARASYA_FINANCE_OPENING_BALANCE_POSTING=enabled.
     * Lock order matches B2B (company row first). An identical active opening already in the ledger is adopted instead.
     */
    public function post(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion']);
        $version = In::version($input);
        A::require($this->store->authorization(), $actor, A::ACCOUNTS_POST);
        if (!$this->postingEnabled) {
            throw new ApiException(409, 'FINANCE_POSTING_DISABLED', 'Posting opening balances is not enabled. The verified proposal stays ready.');
        }
        return $this->store->mutate($actor, 'onboarding_post:' . $id, $input, $key, [A::ACCOUNTS_POST], function (string $now) use ($actor, $id, $version, $key, $request): array {
            $peek = $this->store->pdo()->prepare('SELECT company_uuid FROM finance_account_onboardings WHERE onboarding_uuid = ?');
            $peek->execute([In::isUuid($id) ? $id : '']);
            $companyId = $peek->fetchColumn();
            if ($companyId === false) {
                throw new ApiException(404, 'ONBOARDING_NOT_FOUND', 'Onboarding was not found.');
            }
            $company = $this->lockCompany((string) $companyId);
            $o = $this->lockOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            if ($o['status'] !== 'VERIFIED' || !in_array($o['balance_kind'], ['DEBIT', 'CREDIT'], true)) {
                throw new ApiException(409, 'ONBOARDING_NOT_VERIFIED', 'Only a verified debit or credit opening balance can be posted.');
            }
            if ($o['proposed_by_employee_uuid'] === $actor->employeeUuid) {
                throw new ApiException(403, 'FOUR_EYES_REQUIRED', 'The proposer cannot post the opening balance.');
            }
            $this->approvedCase((string) $o['company_uuid']);
            if (CurrencyPolicy::forCountry($company['country_code']) !== $o['account_currency']) {
                throw new ApiException(409, 'CURRENCY_POLICY_MISMATCH', 'The company country changed; correct the account currency first.');
            }
            $this->assertLedgerUnchanged($o);
            $ledger = $this->ledgerView((string) $o['company_uuid'], (string) $o['account_currency'], (string) $o['as_of_date']);
            $cents = AccountMoney::fromDecimal((string) $o['amount']);
            $direction = strtolower((string) $o['balance_kind']);
            $opening = $ledger['activeOpening'];
            if ($opening !== null) {
                if ($opening['direction'] !== $direction || AccountMoney::fromDecimal($opening['amount']) !== $cents) {
                    throw new ApiException(409, 'LEDGER_OPENING_DIFFERS', 'The ledger already holds a different opening balance.');
                }
                $movement = (string) $opening['movementId'];
                $action = 'opening_adopted';
            } else {
                $movement = $this->ledger->insertMovement((string) $o['company_uuid'], (string) $o['account_currency'], 'opening_balance', $direction, $cents, (string) $o['as_of_date'],
                    null, null, null, 'FIN-' . substr($id, 0, 8), (string) $o['proposal_note'],
                    ['sourceType' => 'finance_onboarding', 'financeOnboardingId' => $id, 'financeCycle' => (int) $o['cycle_no'], 'companyCode' => $company['company_code'],
                        'companyLegalName' => $company['legal_name'], 'taxIdentifier' => $company['tax_identifier'], 'countryCode' => $company['country_code']], $actor, $now);
                $this->ledger->activity((string) $o['company_uuid'], 'opening_balance_posted', $actor, $request, $key, $now, $movement, null, null, (string) $o['account_currency']);
                $action = 'opening_posted';
            }
            $this->update($o, ['status' => 'RECONCILED', 'posted_by_employee_uuid' => $actor->employeeUuid, 'posted_at' => $now, 'posted_movement_uuid' => $movement], $now);
            $this->store->event('onboarding', $id, (string) $o['company_uuid'], $actor, $action, (int) $o['version'], (int) $o['version'] + 1, null, ['movementId' => $movement], $request, $key, $now);
            return ['status' => 200, 'onboardingId' => $id, 'version' => (int) $o['version'] + 1, 'movementId' => $movement, 'adopted' => $action === 'opening_adopted'];
        });
    }

    /**
     * Correction after reconciliation: a Finance-posted opening is reversed in the ledger (a reversal movement, nothing
     * is overwritten); a verified zero is simply reopened. The onboarding returns to NO_INFORMATION in a new cycle.
     */
    public function reopen(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = (string) In::reason($input);
        return $this->store->mutate($actor, 'onboarding_reopen:' . $id, $input, $key, [A::ACCOUNTS_RECONCILE], function (string $now) use ($actor, $id, $version, $reason, $key, $request): array {
            $peek = $this->store->pdo()->prepare('SELECT company_uuid FROM finance_account_onboardings WHERE onboarding_uuid = ?');
            $peek->execute([In::isUuid($id) ? $id : '']);
            $companyId = $peek->fetchColumn();
            if ($companyId === false) {
                throw new ApiException(404, 'ONBOARDING_NOT_FOUND', 'Onboarding was not found.');
            }
            $company = $this->lockCompany((string) $companyId);
            $o = $this->lockOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            if (!in_array($o['status'], ['RECONCILED', 'VERIFIED'], true)) {
                throw new ApiException(409, 'ONBOARDING_NOT_RECONCILED', 'Only a verified or reconciled onboarding can be reopened.');
            }
            $reversal = null;
            if ($o['posted_movement_uuid'] !== null) {
                A::require($this->store->authorization(), $actor, A::ACCOUNTS_POST);
                $original = $this->ledger->movement((string) $o['posted_movement_uuid'], true);
                $snapshot = $original === null ? [] : (array) json_decode((string) $original['source_snapshot'], true);
                if ($original !== null && ($snapshot['financeOnboardingId'] ?? null) === $id && $this->ledger->reversalOf((string) $original['movement_uuid']) === null) {
                    $reversal = $this->ledger->reverse($company, $original, $this->store->today(), $reason, 'finance_onboarding_reopened', 'movement_reversed', $actor, $now, $request, $key);
                } elseif ($original !== null && ($snapshot['financeOnboardingId'] ?? null) !== $id) {
                    // An adopted opening belongs to B2B: it is never reversed from Finance.
                    throw new ApiException(409, 'ADOPTED_OPENING', 'This opening balance was posted in B2B; correct it there.');
                }
            }
            $this->update($o, ['status' => 'NO_INFORMATION', 'balance_kind' => null, 'amount' => null, 'as_of_date' => null, 'overlap_resolution' => null, 'ledger_fingerprint' => null,
                'proposal_note' => null, 'dispute_reason' => $reason, 'proposed_by_employee_uuid' => null, 'proposed_at' => null, 'verified_by_employee_uuid' => null, 'verified_at' => null,
                'posted_by_employee_uuid' => null, 'posted_at' => null, 'posted_movement_uuid' => null, 'cycle_no' => (int) $o['cycle_no'] + 1], $now);
            $this->store->event('onboarding', $id, (string) $o['company_uuid'], $actor, 'onboarding_reopened', (int) $o['version'], (int) $o['version'] + 1, $reason, ['reversalId' => $reversal], $request, $key, $now);
            return ['status' => 200, 'onboardingId' => $id, 'version' => (int) $o['version'] + 1, 'reversalId' => $reversal];
        });
    }

    /** Audited correction when the verified registered country of the company changed: currency re-derived, new cycle. */
    public function correctCurrency(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = (string) In::reason($input);
        return $this->store->mutate($actor, 'onboarding_currency:' . $id, $input, $key, [A::ACCOUNTS_RECONCILE], function (string $now) use ($actor, $id, $version, $reason, $key, $request): array {
            $o = $this->lockOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            $company = $this->lockCompany((string) $o['company_uuid']);
            $currency = CurrencyPolicy::forCountry($company['country_code']);
            if ($company['country_code'] === $o['country_code']) {
                throw new ApiException(409, 'NO_COUNTRY_CHANGE', 'The registered country did not change.');
            }
            if ($o['posted_movement_uuid'] !== null) {
                throw new ApiException(409, 'POSTED_OPENING_EXISTS', 'Reopen the onboarding (reversing the posted opening) before correcting the currency.');
            }
            $this->approvedCase((string) $o['company_uuid']);
            $this->update($o, ['country_code' => $company['country_code'], 'account_currency' => $currency, 'status' => 'NO_INFORMATION', 'balance_kind' => null, 'amount' => null, 'as_of_date' => null,
                'overlap_resolution' => null, 'ledger_fingerprint' => null, 'proposed_by_employee_uuid' => null, 'proposed_at' => null, 'verified_by_employee_uuid' => null, 'verified_at' => null,
                'cycle_no' => (int) $o['cycle_no'] + 1], $now);
            $this->store->event('onboarding', $id, (string) $o['company_uuid'], $actor, 'currency_corrected', (int) $o['version'], (int) $o['version'] + 1, $reason,
                ['from' => $o['account_currency'], 'to' => $currency, 'country' => $company['country_code']], $request, $key, $now);
            return ['status' => 200, 'onboardingId' => $id, 'version' => (int) $o['version'] + 1, 'accountCurrency' => $currency];
        });
    }

    /**
     * What the ledger already holds for the company: per currency totals, the active opening in the account currency,
     * movements dated on or before the cut-off, foreign-currency movements, and a fingerprint of all of it.
     * @return array<string, mixed>
     */
    public function ledgerView(string $companyId, string $currency, ?string $cutoff = null): array
    {
        $pdo = $this->store->pdo();
        $s = $pdo->prepare("SELECT currency_code, COUNT(*) n, MAX(movement_number) last_number,
                COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0) debits, COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) credits,
                MIN(value_date) first_date, MAX(value_date) last_date
            FROM b2b_account_movements WHERE company_uuid = ? GROUP BY currency_code ORDER BY currency_code");
        $s->execute([$companyId]);
        $currencies = [];
        $other = 0;
        $parts = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $balance = AccountMoney::fromDecimal((string) $r['debits']) - AccountMoney::fromDecimal((string) $r['credits']);
            $currencies[$r['currency_code']] = ['movementCount' => (int) $r['n'], 'debits' => AccountMoney::format(AccountMoney::fromDecimal((string) $r['debits'])),
                'credits' => AccountMoney::format(AccountMoney::fromDecimal((string) $r['credits'])), 'balance' => AccountMoney::format($balance),
                'firstValueDate' => $r['first_date'], 'lastValueDate' => $r['last_date']];
            if ($r['currency_code'] !== $currency) {
                $other += (int) $r['n'];
            }
            $parts[] = $r['currency_code'] . ':' . $r['n'] . ':' . $r['last_number'] . ':' . $r['debits'] . ':' . $r['credits'];
        }
        $s = $pdo->prepare("SELECT m.movement_uuid, m.movement_code, m.direction, m.amount, m.value_date, m.source_snapshot FROM b2b_account_movements m
            WHERE m.company_uuid = ? AND m.currency_code = ? AND m.movement_type = 'opening_balance'
            AND NOT EXISTS (SELECT 1 FROM b2b_account_movements r WHERE r.reversed_movement_uuid = m.movement_uuid) ORDER BY m.movement_number LIMIT 1");
        $s->execute([$companyId, $currency]);
        $opening = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $prior = 0;
        if ($cutoff !== null) {
            $s = $pdo->prepare("SELECT COUNT(*) FROM b2b_account_movements WHERE company_uuid = ? AND currency_code = ? AND value_date <= ? AND movement_type <> 'opening_balance'");
            $s->execute([$companyId, $currency, $cutoff]);
            $prior = (int) $s->fetchColumn();
        }
        return [
            'accountCurrency' => $currency,
            'currencies' => (object) $currencies,
            'activeOpening' => $opening === null ? null : ['movementId' => $opening['movement_uuid'], 'code' => $opening['movement_code'], 'direction' => $opening['direction'],
                'amount' => $opening['amount'], 'valueDate' => $opening['value_date'], 'postedByFinance' => (json_decode((string) $opening['source_snapshot'], true)['sourceType'] ?? null) === 'finance_onboarding'],
            'movementsOnOrBeforeCutoff' => $prior,
            'otherCurrencyMovements' => $other,
            'fingerprint' => hash('sha256', implode('|', $parts)),
        ];
    }

    private function assertLedgerUnchanged(array $o): void
    {
        $now = $this->ledgerView((string) $o['company_uuid'], (string) $o['account_currency'], (string) $o['as_of_date']);
        if ($o['ledger_fingerprint'] === null || !hash_equals(bin2hex((string) $o['ledger_fingerprint']), $now['fingerprint'])) {
            throw new ApiException(409, 'LEDGER_CHANGED_SINCE_SUBMISSION', 'The ledger changed after the proposal was submitted. Return it and review again.');
        }
    }

    private function requireSecondPerson(array $o, EmployeeIdentity $actor): void
    {
        if ($o['proposed_by_employee_uuid'] === $actor->employeeUuid) {
            throw new ApiException(403, 'FOUR_EYES_REQUIRED', 'The proposer cannot verify the opening balance.');
        }
        $s = $this->store->pdo()->prepare("SELECT COUNT(*) FROM finance_activity_events WHERE entity_type = 'onboarding' AND entity_uuid = ? AND actor_employee_uuid = ? AND action IN ('opening_drafted', 'opening_submitted')
            AND occurred_at >= COALESCE((SELECT MAX(e2.occurred_at) FROM finance_activity_events e2 WHERE e2.entity_type = 'onboarding' AND e2.entity_uuid = ? AND e2.action IN ('onboarding_reopened', 'currency_corrected')), '1970-01-01')");
        $s->execute([$o['onboarding_uuid'], $actor->employeeUuid, $o['onboarding_uuid']]);
        if ((int) $s->fetchColumn() > 0) {
            throw new ApiException(403, 'FOUR_EYES_REQUIRED', 'An employee who drafted this proposal cannot verify it.');
        }
    }

    /** The approved verification case behind the company; onboarding stops while identity is not verified. */
    private function approvedCase(string $companyId): array
    {
        $s = $this->store->pdo()->prepare("SELECT * FROM finance_company_cases WHERE company_uuid = ? AND status = 'APPROVED' ORDER BY approved_at DESC LIMIT 1");
        $s->execute([$companyId]);
        $case = $s->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            throw new ApiException(409, 'COMPANY_NOT_VERIFIED', 'The company identity is not verified (approved) in Finance.');
        }
        return $case;
    }

    private function lockCompany(string $companyId): array
    {
        return $this->store->lock('b2b_companies', 'company_uuid', $companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
    }

    private function lockOnboarding(string $id): array
    {
        return $this->store->lock('finance_account_onboardings', 'onboarding_uuid', $id, 'ONBOARDING_NOT_FOUND', 'Onboarding was not found.');
    }

    /** @param array<string, mixed> $columns */
    private function update(array $o, array $columns, string $now): void
    {
        $columns['updated_at'] = $now;
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($columns)));
        $s = $this->store->pdo()->prepare("UPDATE finance_account_onboardings SET {$sets}, version = version + 1 WHERE onboarding_uuid = ? AND version = ?");
        $s->execute([...array_values($columns), $o['onboarding_uuid'], $o['version']]);
        if ($s->rowCount() !== 1) {
            throw new ApiException(409, 'VERSION_CONFLICT', 'The record changed. Reload it before continuing.');
        }
    }
}
