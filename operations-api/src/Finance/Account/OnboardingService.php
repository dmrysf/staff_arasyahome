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
 * own "one active opening per currency" rule re-checked under the company lock, and the ledger fingerprint taken at
 * submission must be unchanged at verification and posting.
 *
 * Double counting: the stated opening balance is a historical snapshot and may already contain receivables or payments
 * the ledger holds. Every ledger movement effective on the cut-off must therefore be reconciled line by line against a
 * source reference (included, partly included or excluded). Only incremental = stated - included - existing opening can
 * ever reach the ledger, and that figure is recomputed under the company lock at submission, verification and posting.
 * A snapshot is never converted into a movement as a whole.
 */
final readonly class OnboardingService
{
    private const MAX_OVERLAP_LINES = 400;

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
            [, $o] = $this->lockCompanyAndOnboarding($id);
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
            $hasPrior = $ledger['movementsOnOrBeforeCutoff'] > 0;
            // With prior movements the comparison needs the line-by-line reconciliation, which also accounts for the opening.
            if (!$hasPrior && $opening !== null && ($kind === 'ZERO' || $opening['direction'] !== strtolower($kind) || AccountMoney::fromDecimal($opening['amount']) !== $cents)) {
                throw new ApiException(409, 'LEDGER_OPENING_DIFFERS', 'The ledger already holds a different opening balance in this currency. Resolve it in the ledger before proposing another.', ['opening' => $opening]);
            }
            $overlap = $hasPrior ? 'reconciliation_required' : 'no_prior_movements';
            $this->store->evidence('onboarding', $id, $evidence, $actor, $now);
            $this->update($o, ['status' => 'AWAITING_RECONCILIATION', 'balance_kind' => $kind, 'amount' => AccountMoney::format($cents), 'as_of_date' => $asOf, 'overlap_resolution' => $overlap, 'overlap_reconciliation_uuid' => null,
                'currency_issue' => $this->currencyIssueNow($o, $ledger),
                'proposal_note' => $note, 'dispute_reason' => null, 'proposed_by_employee_uuid' => $actor->employeeUuid, 'proposed_at' => $now, 'ledger_fingerprint' => null], $now);
            $this->store->event('onboarding', $id, (string) $o['company_uuid'], $actor, 'opening_drafted', (int) $o['version'], (int) $o['version'] + 1, null,
                ['kind' => $kind, 'overlap' => $overlap, 'evidence' => count($evidence)], $request, $key, $now);
            return ['status' => 200, 'onboardingId' => $id, 'version' => (int) $o['version'] + 1, 'overlapResolution' => $overlap];
        });
    }

    /**
     * Records how the stated opening balance treats every ledger movement effective on the cut-off and computes the
     * only amount that may be posted. Each attempt is kept; the latest one is the one in force.
     */
    public function reconcileOverlap(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'lines', 'remaining', 'note', 'evidence']);
        $version = In::version($input);
        $note = (string) In::reason($input, 'note');
        $evidence = In::evidence($input);
        $raw = $input['lines'] ?? [];
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_OVERLAP_LINES) {
            In::fail('lines', 'invalid');
        }
        $decisions = [];
        foreach ($raw as $line) {
            if (!is_array($line)) {
                In::fail('lines', 'invalid');
            }
            In::only($line, ['movementId', 'disposition', 'includedAmount', 'sourceReference', 'note']);
            $movement = In::uuid($line, 'movementId');
            if (isset($decisions[$movement])) {
                In::fail('lines', 'duplicate_movement');
            }
            $decisions[$movement] = self::overlapDecision($line);
        }
        $remaining = null;
        if (isset($input['remaining'])) {
            if (!is_array($input['remaining']) || ($input['remaining']['disposition'] ?? null) === 'PARTIAL') {
                In::fail('remaining', 'invalid');
            }
            In::only($input['remaining'], ['disposition', 'sourceReference', 'note']);
            $remaining = self::overlapDecision($input['remaining']);
        }
        return $this->store->mutate($actor, 'onboarding_overlap:' . $id, $input, $key, [A::ACCOUNTS_PROPOSE], function (string $now) use ($actor, $id, $version, $note, $evidence, $decisions, $remaining, $key, $request): array {
            [, $o] = $this->lockCompanyAndOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            if ($o['status'] !== 'AWAITING_RECONCILIATION' || $o['balance_kind'] === null) {
                throw new ApiException(409, 'ONBOARDING_NOT_DRAFTED', 'Draft the opening balance first.');
            }
            if (count($evidence) + count($this->store->evidenceOf('onboarding', $id)) === 0) {
                throw new ApiException(409, 'EVIDENCE_REQUIRED', 'Link at least one supporting evidence reference.');
            }
            $companyId = (string) $o['company_uuid'];
            $currency = (string) $o['account_currency'];
            $prior = $this->priorMovements($companyId, $currency, (string) $o['as_of_date']);
            $known = array_flip(array_column($prior['effective'], 'movement_uuid'));
            foreach (array_keys($decisions) as $movement) {
                if (!isset($known[$movement])) {
                    throw new ApiException(409, 'OVERLAP_UNKNOWN_MOVEMENT', 'A line refers to a movement that is not effective on the cut-off.', ['movementId' => $movement]);
                }
            }
            $lines = [];
            $included = 0;
            foreach ($prior['effective'] as $m) {
                $cents = AccountMoney::fromDecimal((string) $m['amount']);
                $decision = $decisions[$m['movement_uuid']] ?? $remaining;
                if ($decision === null) {
                    throw new ApiException(409, 'OVERLAP_LINES_INCOMPLETE', 'Every movement effective on the cut-off needs an explicit decision.', ['missing' => (string) $m['movement_code']]);
                }
                $portion = match ($decision['disposition']) { 'INCLUDED' => $cents, 'EXCLUDED' => 0, default => (int) $decision['cents'] };
                if ($decision['disposition'] === 'PARTIAL' && $portion >= $cents) {
                    throw new ApiException(409, 'OVERLAP_PARTIAL_EXCEEDS_MOVEMENT', 'A partly included amount must be smaller than the movement.', ['movement' => (string) $m['movement_code']]);
                }
                $included += $m['direction'] === 'debit' ? $portion : -$portion;
                $lines[] = [$m, $decision['disposition'], $portion, $decision['reference'], $decision['note']];
            }
            foreach ($prior['neutral'] as $m) {
                $lines[] = [$m, 'NEUTRAL_REVERSED', 0, null, null];
            }
            $gross = self::grossSigned($o);
            $ledger = $this->ledgerView($companyId, $currency, (string) $o['as_of_date']);
            $existing = self::openingSigned($ledger['activeOpening']);
            $incremental = $gross - $included - $existing;
            if ($ledger['activeOpening'] !== null && $incremental !== 0) {
                throw new ApiException(409, 'LEDGER_OPENING_DIFFERS', 'The ledger already holds a different opening balance and the reconciliation does not account for the difference.');
            }
            $pdo = $this->store->pdo();
            $reconciliation = Uuid::v4();
            $pdo->prepare('INSERT INTO finance_opening_reconciliations (reconciliation_uuid, onboarding_uuid, cycle_no, currency_code, cutoff_date, gross_signed, included_signed, existing_opening_signed, incremental_signed, lines_fingerprint, note, created_by_employee_uuid, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$reconciliation, $id, $o['cycle_no'], $currency, $o['as_of_date'], AccountMoney::format($gross), AccountMoney::format($included), AccountMoney::format($existing), AccountMoney::format($incremental), hex2bin($prior['fingerprint']), $note, $actor->employeeUuid, $now]);
            $insert = $pdo->prepare('INSERT INTO finance_opening_reconciliation_lines (line_uuid, reconciliation_uuid, movement_uuid, movement_code, movement_type, direction, amount, value_date, disposition, included_amount, source_reference, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($lines as [$m, $disposition, $portion, $reference, $lineNote]) {
                $insert->execute([Uuid::v4(), $reconciliation, $m['movement_uuid'], $m['movement_code'], $m['movement_type'], $m['direction'], $m['amount'], $m['value_date'], $disposition, AccountMoney::format($portion), $reference, $lineNote]);
            }
            $this->store->evidence('onboarding', $id, $evidence, $actor, $now);
            $this->update($o, ['overlap_resolution' => 'reconciled_by_lines', 'overlap_reconciliation_uuid' => $reconciliation], $now);
            $this->store->event('onboarding', $id, $companyId, $actor, 'opening_overlap_reconciled', (int) $o['version'], (int) $o['version'] + 1, null, ['lines' => count($prior['effective']), 'neutral' => count($prior['neutral']), 'evidence' => count($evidence)], $request, $key, $now);
            return ['status' => 200, 'onboardingId' => $id, 'version' => (int) $o['version'] + 1, 'reconciliationId' => $reconciliation, 'grossSigned' => AccountMoney::format($gross), 'includedSigned' => AccountMoney::format($included),
                'existingOpeningSigned' => AccountMoney::format($existing), 'incrementalSigned' => AccountMoney::format($incremental)];
        });
    }

    public function resolveCurrencyIssue(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'note']);
        $version = In::version($input);
        $note = (string) In::reason($input, 'note');
        return $this->store->mutate($actor, 'onboarding_currency_issue:' . $id, $input, $key, [A::ACCOUNTS_RECONCILE], function (string $now) use ($actor, $id, $version, $note, $key, $request): array {
            [, $o] = $this->lockCompanyAndOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            if ($o['currency_issue'] !== 'open') {
                throw new ApiException(409, 'NO_CURRENCY_ISSUE', 'There is no open currency inconsistency.');
            }
            $ledger = $this->ledgerView((string) $o['company_uuid'], (string) $o['account_currency']);
            $this->update($o, ['currency_issue' => 'resolved', 'currency_issue_note' => $note, 'other_currency_fingerprint' => hex2bin($ledger['otherCurrencyFingerprint'])], $now);
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
            [, $o] = $this->lockCompanyAndOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            if ($o['status'] !== 'AWAITING_RECONCILIATION' || $o['balance_kind'] === null) {
                throw new ApiException(409, 'ONBOARDING_NOT_DRAFTED', 'Draft the opening balance first.');
            }
            $this->assertCurrencyIssueClosed($o);
            if (count($this->store->evidenceOf('onboarding', $id)) === 0) {
                throw new ApiException(409, 'EVIDENCE_REQUIRED', 'Link at least one supporting evidence reference.');
            }
            $this->approvedCase((string) $o['company_uuid']);
            $this->assertOverlapReconciled($o);
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
            [, $o] = $this->lockCompanyAndOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            if ($o['status'] !== 'SUBMITTED') {
                throw new ApiException(409, 'ONBOARDING_NOT_SUBMITTED', 'Only a submitted proposal can be verified.');
            }
            $this->requireSecondPerson($o, $actor);
            $this->approvedCase((string) $o['company_uuid']);
            $this->assertLedgerUnchanged($o);
            $this->assertCurrencyIssueClosed($o);
            $position = $this->assertOverlapReconciled($o);
            $status = $o['balance_kind'] === 'ZERO' && $position['incremental'] === 0 ? 'RECONCILED' : 'VERIFIED';
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
            [$company, $o] = $this->lockCompanyAndOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            if ($o['status'] !== 'VERIFIED' || $o['balance_kind'] === null) {
                throw new ApiException(409, 'ONBOARDING_NOT_VERIFIED', 'Only a verified opening balance can be posted.');
            }
            $this->requireSecondPerson($o, $actor);
            $this->approvedCase((string) $o['company_uuid']);
            if (CurrencyPolicy::forCountry($company['country_code']) !== $o['account_currency']) {
                throw new ApiException(409, 'CURRENCY_POLICY_MISMATCH', 'The company country changed; correct the account currency first.');
            }
            $this->assertLedgerUnchanged($o);
            $this->assertCurrencyIssueClosed($o);
            $position = $this->assertOverlapReconciled($o);
            $incremental = $position['incremental'];
            $opening = $position['opening'];
            if ($opening !== null) {
                // Already represented: nothing new is written.
                $movement = (string) $opening['movementId'];
                $action = 'opening_adopted';
            } elseif ($incremental === 0) {
                // The ledger already holds the whole stated balance: posting it again would double count.
                $movement = null;
                $action = 'opening_satisfied_by_ledger';
            } else {
                $movement = $this->ledger->insertMovement((string) $o['company_uuid'], (string) $o['account_currency'], 'opening_balance', $incremental > 0 ? 'debit' : 'credit', abs($incremental), (string) $o['as_of_date'],
                    null, null, null, 'FIN-' . substr($id, 0, 8), (string) $o['proposal_note'],
                    ['sourceType' => 'finance_onboarding', 'financeOnboardingId' => $id, 'financeCycle' => (int) $o['cycle_no'], 'grossOpeningSigned' => AccountMoney::format($position['gross']),
                        'overlapIncludedSigned' => AccountMoney::format($position['included']), 'incrementalSigned' => AccountMoney::format($incremental), 'reconciliationId' => $o['overlap_reconciliation_uuid'], 'companyCode' => $company['company_code'],
                        'companyLegalName' => $company['legal_name'], 'taxIdentifier' => $company['tax_identifier'], 'countryCode' => $company['country_code']], $actor, $now);
                $this->ledger->activity((string) $o['company_uuid'], 'opening_balance_posted', $actor, $request, $key, $now, $movement, null, null, (string) $o['account_currency']);
                $action = 'opening_posted';
            }
            $this->update($o, ['status' => 'RECONCILED', 'posted_by_employee_uuid' => $actor->employeeUuid, 'posted_at' => $now, 'posted_movement_uuid' => $movement], $now);
            $this->store->event('onboarding', $id, (string) $o['company_uuid'], $actor, $action, (int) $o['version'], (int) $o['version'] + 1, null, ['movementId' => $movement], $request, $key, $now);
            return ['status' => 200, 'onboardingId' => $id, 'version' => (int) $o['version'] + 1, 'movementId' => $movement, 'adopted' => $action === 'opening_adopted', 'satisfiedByLedger' => $action === 'opening_satisfied_by_ledger', 'incrementalSigned' => AccountMoney::format($incremental)];
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
            [$company, $o] = $this->lockCompanyAndOnboarding($id);
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
            $this->update($o, ['status' => 'NO_INFORMATION', 'balance_kind' => null, 'amount' => null, 'as_of_date' => null, 'overlap_resolution' => null, 'overlap_reconciliation_uuid' => null, 'ledger_fingerprint' => null,
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
            [$company, $o] = $this->lockCompanyAndOnboarding($id);
            FinanceStore::expectVersion($o, $version);
            $currency = CurrencyPolicy::forCountry($company['country_code']);
            if ($company['country_code'] === $o['country_code']) {
                throw new ApiException(409, 'NO_COUNTRY_CHANGE', 'The registered country did not change.');
            }
            if ($o['posted_movement_uuid'] !== null) {
                throw new ApiException(409, 'POSTED_OPENING_EXISTS', 'Reopen the onboarding (reversing the posted opening) before correcting the currency.');
            }
            $this->approvedCase((string) $o['company_uuid']);
            $this->update($o, ['country_code' => $company['country_code'], 'account_currency' => $currency, 'status' => 'NO_INFORMATION', 'balance_kind' => null, 'amount' => null, 'as_of_date' => null,
                'overlap_resolution' => null, 'overlap_reconciliation_uuid' => null, 'ledger_fingerprint' => null, 'proposed_by_employee_uuid' => null, 'proposed_at' => null, 'verified_by_employee_uuid' => null, 'verified_at' => null,
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
        $otherParts = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $balance = AccountMoney::fromDecimal((string) $r['debits']) - AccountMoney::fromDecimal((string) $r['credits']);
            $currencies[$r['currency_code']] = ['movementCount' => (int) $r['n'], 'debits' => AccountMoney::format(AccountMoney::fromDecimal((string) $r['debits'])),
                'credits' => AccountMoney::format(AccountMoney::fromDecimal((string) $r['credits'])), 'balance' => AccountMoney::format($balance),
                'firstValueDate' => $r['first_date'], 'lastValueDate' => $r['last_date']];
            $part = $r['currency_code'] . ':' . $r['n'] . ':' . $r['last_number'] . ':' . $r['debits'] . ':' . $r['credits'];
            if ($r['currency_code'] !== $currency) {
                $other += (int) $r['n'];
                $otherParts[] = $part;
            }
            $parts[] = $part;
        }
        $s = $pdo->prepare("SELECT m.movement_uuid, m.movement_code, m.direction, m.amount, m.value_date, m.source_snapshot FROM b2b_account_movements m
            WHERE m.company_uuid = ? AND m.currency_code = ? AND m.movement_type = 'opening_balance'
            AND NOT EXISTS (SELECT 1 FROM b2b_account_movements r WHERE r.reversed_movement_uuid = m.movement_uuid) ORDER BY m.movement_number LIMIT 1");
        $s->execute([$companyId, $currency]);
        $opening = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $prior = 0;
        if ($cutoff !== null) {
            $prior = count($this->priorMovements($companyId, $currency, $cutoff)['effective']);
        }
        return [
            'accountCurrency' => $currency,
            'currencies' => (object) $currencies,
            'activeOpening' => $opening === null ? null : ['movementId' => $opening['movement_uuid'], 'code' => $opening['movement_code'], 'direction' => $opening['direction'],
                'amount' => $opening['amount'], 'valueDate' => $opening['value_date'], 'postedByFinance' => (json_decode((string) $opening['source_snapshot'], true)['sourceType'] ?? null) === 'finance_onboarding'],
            'movementsOnOrBeforeCutoff' => $prior,
            'otherCurrencyMovements' => $other,
            'otherCurrencyFingerprint' => hash('sha256', implode('|', $otherParts)),
            'fingerprint' => hash('sha256', implode('|', $parts)),
        ];
    }

    /**
     * Ledger movements of the account currency dated on or before the cut-off, other than opening balances. A movement
     * and its reversal that both fall on or before the cut-off cancel out and are kept apart as neutral. Reversals of an
     * opening balance belong to the opening, not to the history.
     * @return array{effective: list<array<string, mixed>>, neutral: list<array<string, mixed>>, fingerprint: string}
     */
    public function priorMovements(string $companyId, string $currency, string $cutoff): array
    {
        $s = $this->store->pdo()->prepare("SELECT m.movement_uuid, m.movement_code, m.movement_type, m.direction, m.amount, m.value_date, m.reversed_movement_uuid,
                r.value_date AS reversal_date, o.movement_type AS original_type, o.value_date AS original_date
            FROM b2b_account_movements m
            LEFT JOIN b2b_account_movements r ON r.reversed_movement_uuid = m.movement_uuid
            LEFT JOIN b2b_account_movements o ON o.movement_uuid = m.reversed_movement_uuid
            WHERE m.company_uuid = ? AND m.currency_code = ? AND m.value_date <= ? AND m.movement_type <> 'opening_balance' ORDER BY m.movement_number");
        $s->execute([$companyId, $currency, $cutoff]);
        $effective = [];
        $neutral = [];
        $parts = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $m) {
            if ($m['original_type'] === 'opening_balance') {
                continue;
            }
            $cancelled = ($m['reversal_date'] !== null && $m['reversal_date'] <= $cutoff) || ($m['original_date'] !== null && $m['original_date'] <= $cutoff);
            if ($cancelled) {
                $neutral[] = $m;
            } else {
                $effective[] = $m;
            }
            $parts[] = implode(':', [$m['movement_uuid'], $m['direction'], $m['amount'], $m['value_date'], $cancelled ? 'n' : 'e']);
        }
        return ['effective' => $effective, 'neutral' => $neutral, 'fingerprint' => hash('sha256', implode('|', $parts))];
    }

    /** Read-only view of the overlap state for the reconciliation screen. Never throws for a stale state. @return array<string, mixed> */
    public function overlapView(array $o): array
    {
        if ($o['as_of_date'] === null || $o['balance_kind'] === null) {
            return ['state' => 'not_applicable', 'lines' => [], 'neutral' => [], 'reconciliation' => null];
        }
        $prior = $this->priorMovements((string) $o['company_uuid'], (string) $o['account_currency'], (string) $o['as_of_date']);
        $reconciliation = $this->reconciliationOf($o);
        $current = $reconciliation !== null && $this->reconciliationMatches($o, $reconciliation, $prior['fingerprint']);
        $state = $prior['effective'] === [] ? 'none' : ($reconciliation === null ? 'required' : ($current ? 'reconciled' : 'stale'));
        $decided = [];
        if ($reconciliation !== null) {
            $s = $this->store->pdo()->prepare('SELECT movement_uuid, disposition, included_amount, source_reference, note FROM finance_opening_reconciliation_lines WHERE reconciliation_uuid = ?');
            $s->execute([$reconciliation['reconciliation_uuid']]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $decided[$line['movement_uuid']] = $line;
            }
        }
        $present = static fn (array $m): array => ['movementId' => $m['movement_uuid'], 'code' => $m['movement_code'], 'type' => $m['movement_type'], 'direction' => $m['direction'], 'amount' => $m['amount'], 'valueDate' => $m['value_date'],
            'disposition' => $decided[$m['movement_uuid']]['disposition'] ?? null, 'includedAmount' => $decided[$m['movement_uuid']]['included_amount'] ?? null,
            'sourceReference' => $decided[$m['movement_uuid']]['source_reference'] ?? null, 'note' => $decided[$m['movement_uuid']]['note'] ?? null];
        return ['state' => $state, 'lines' => array_map($present, $prior['effective']), 'neutral' => array_map($present, $prior['neutral']), 'reconciliation' => $reconciliation === null ? null : [
            'id' => $reconciliation['reconciliation_uuid'], 'grossSigned' => $reconciliation['gross_signed'], 'includedSigned' => $reconciliation['included_signed'], 'existingOpeningSigned' => $reconciliation['existing_opening_signed'],
            'incrementalSigned' => $reconciliation['incremental_signed'], 'note' => $reconciliation['note'], 'createdBy' => $reconciliation['display_name'], 'createdAt' => FinanceStore::iso($reconciliation['created_at']), 'current' => $current]];
    }

    /**
     * Fails closed unless every movement effective on the cut-off is accounted for by a current reconciliation. Runs under
     * the company lock, so no ledger write can slip between this check and the caller's action.
     * @return array{gross: int, included: int, existing: int, incremental: int, opening: ?array<string, mixed>}
     */
    private function assertOverlapReconciled(array $o): array
    {
        $companyId = (string) $o['company_uuid'];
        $currency = (string) $o['account_currency'];
        $cutoff = (string) $o['as_of_date'];
        $prior = $this->priorMovements($companyId, $currency, $cutoff);
        $reconciliation = $this->reconciliationOf($o);
        $included = 0;
        if ($reconciliation !== null) {
            if (!$this->reconciliationMatches($o, $reconciliation, $prior['fingerprint'])) {
                throw new ApiException(409, 'OVERLAP_RECONCILIATION_STALE', 'The ledger or the proposal changed after the reconciliation. Reconcile the movements again.');
            }
            $included = AccountMoney::fromDecimal((string) $reconciliation['included_signed']);
        } elseif ($prior['effective'] !== [] || $o['overlap_resolution'] !== 'no_prior_movements') {
            throw new ApiException(409, 'OVERLAP_RECONCILIATION_REQUIRED', 'Reconcile the ledger movements dated on or before the cut-off against the stated balance first.', ['movements' => count($prior['effective'])]);
        }
        $ledger = $this->ledgerView($companyId, $currency, $cutoff);
        $opening = $ledger['activeOpening'];
        $gross = self::grossSigned($o);
        $existing = self::openingSigned($opening);
        $incremental = $gross - $included - $existing;
        if ($opening !== null && $opening['valueDate'] > $cutoff) {
            throw new ApiException(409, 'LEDGER_OPENING_AFTER_CUTOFF', 'The ledger opening balance is dated after the proposed cut-off.');
        }
        if ($opening !== null && $incremental !== 0) {
            throw new ApiException(409, 'LEDGER_OPENING_DIFFERS', 'The ledger already holds a different opening balance.');
        }
        return ['gross' => $gross, 'included' => $included, 'existing' => $existing, 'incremental' => $incremental, 'opening' => $opening];
    }

    /** @return array<string, mixed>|null */
    private function reconciliationOf(array $o): ?array
    {
        if ($o['overlap_resolution'] !== 'reconciled_by_lines' || $o['overlap_reconciliation_uuid'] === null) {
            return null;
        }
        $s = $this->store->pdo()->prepare('SELECT r.*, e.display_name FROM finance_opening_reconciliations r JOIN employees e ON e.employee_uuid = r.created_by_employee_uuid WHERE r.reconciliation_uuid = ?');
        $s->execute([$o['overlap_reconciliation_uuid']]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function reconciliationMatches(array $o, array $r, string $linesFingerprint): bool
    {
        return (int) $r['cycle_no'] === (int) $o['cycle_no'] && $r['currency_code'] === $o['account_currency'] && $r['cutoff_date'] === $o['as_of_date']
            && AccountMoney::fromDecimal((string) $r['gross_signed']) === self::grossSigned($o) && hash_equals(bin2hex((string) $r['lines_fingerprint']), $linesFingerprint);
    }

    /** Foreign-currency movements stay separate and need a documented note that still describes the current ledger. */
    private function assertCurrencyIssueClosed(array $o): void
    {
        $ledger = $this->ledgerView((string) $o['company_uuid'], (string) $o['account_currency']);
        if ($this->currencyIssueNow($o, $ledger) === 'open') {
            throw new ApiException(409, 'CURRENCY_ISSUE_OPEN', 'Resolve the currency inconsistency first.');
        }
    }

    private function currencyIssueNow(array $o, array $ledger): string
    {
        if ($ledger['otherCurrencyMovements'] === 0) {
            return $o['currency_issue'] === 'open' ? 'open' : (string) $o['currency_issue'];
        }
        $documented = $o['currency_issue'] === 'resolved' && $o['other_currency_fingerprint'] !== null && hash_equals(bin2hex((string) $o['other_currency_fingerprint']), (string) $ledger['otherCurrencyFingerprint']);
        return $documented ? 'resolved' : 'open';
    }

    private static function grossSigned(array $o): int
    {
        $cents = AccountMoney::fromDecimal((string) $o['amount']);
        return match ($o['balance_kind']) { 'DEBIT' => $cents, 'CREDIT' => -$cents, default => 0 };
    }

    private static function openingSigned(?array $opening): int
    {
        if ($opening === null) {
            return 0;
        }
        $cents = AccountMoney::fromDecimal((string) $opening['amount']);
        return $opening['direction'] === 'debit' ? $cents : -$cents;
    }

    /** @return array{disposition: string, cents: ?int, reference: string, note: ?string} */
    private static function overlapDecision(array $line): array
    {
        $disposition = (string) In::enum($line, 'disposition', ['INCLUDED', 'PARTIAL', 'EXCLUDED']);
        $reference = (string) In::text($line, 'sourceReference', 255, true);
        $note = In::text($line, 'note', 500, $disposition === 'EXCLUDED');
        return ['disposition' => $disposition, 'cents' => $disposition === 'PARTIAL' ? In::money($line, 'includedAmount') : null, 'reference' => $reference, 'note' => $note];
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
        $s = $this->store->pdo()->prepare("SELECT COUNT(*) FROM finance_activity_events WHERE entity_type = 'onboarding' AND entity_uuid = ? AND actor_employee_uuid = ? AND action IN ('opening_drafted', 'opening_overlap_reconciled', 'opening_submitted')
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

    /** Company row first, exactly like every B2B ledger writer, then the onboarding. @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function lockCompanyAndOnboarding(string $id): array
    {
        $peek = $this->store->pdo()->prepare('SELECT company_uuid FROM finance_account_onboardings WHERE onboarding_uuid = ?');
        $peek->execute([In::isUuid($id) ? $id : '']);
        $companyId = $peek->fetchColumn();
        if ($companyId === false) {
            throw new ApiException(404, 'ONBOARDING_NOT_FOUND', 'Onboarding was not found.');
        }
        $company = $this->lockCompany((string) $companyId);
        return [$company, $this->lockOnboarding($id)];
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
