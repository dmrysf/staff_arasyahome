<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Ledger;

use Arasya\Operations\B2B\AccountLedger;
use Arasya\Operations\B2B\AccountMoney;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Finance\FinanceAccess as A;
use Arasya\Operations\Finance\FinanceInput as In;
use Arasya\Operations\Finance\FinanceStore;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;

/**
 * Finance ledger requests (API 2.68.0): payments, adjustments, reversals and refunds that reach the B2B current-account
 * ledger only after a second person approves them. The ledger stays B2B\AccountLedger; this class never writes
 * b2b_account_movements itself and keeps no balance of its own.
 *
 * Rules, each enforced here and backed by the database (migration 052):
 *  - Four eyes: the approver (or the person rejecting) is never the creator, the submitter or the last editor. No role,
 *    root status or configuration bypasses it; the CHECK refuses such a row even if the application tried.
 *  - Exactly once: approval locks the company row, then the request row; the request posts one movement (unique column)
 *    in the same transaction, and the per-actor Idempotency-Key replays a retried approval instead of posting again.
 *  - One real bank transaction, one active request: the normalized identity is unique while the request is alive. An
 *    exact repeat is refused outright and no acknowledgement can override it. Similar free-text references or the same
 *    amount near the same date are only flagged; the approver must acknowledge them explicitly.
 *  - Permissions are re-read inside the transaction (FinanceStore::mutate), so a revoked approver is refused at once.
 */
final readonly class LedgerRequestService
{
    public const KINDS = ['PAYMENT', 'ADJUSTMENT', 'REVERSAL', 'REFUND'];
    public const ACTIVE = ['DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'PAID_OUT'];
    public const METHODS = ['bank_transfer', 'cash', 'card', 'compensation', 'other'];
    public const REFUND_METHODS = ['bank_transfer', 'cash', 'card', 'other'];
    /** Methods whose money movement always has a provider transaction id. */
    public const BANK_METHODS = ['bank_transfer', 'card'];
    public const REVERSIBLE = ['payment', 'adjustment', 'opening_balance'];
    private const PERMISSIONS = [
        'PAYMENT' => [A::COLLECTIONS_RECORD, A::COLLECTIONS_APPROVE],
        'ADJUSTMENT' => [A::LEDGER_REQUEST, A::LEDGER_APPROVE],
        'REVERSAL' => [A::LEDGER_REQUEST, A::LEDGER_APPROVE],
        'REFUND' => [A::REFUNDS_REQUEST, A::REFUNDS_APPROVE],
    ];

    private AccountLedger $ledger;

    public function __construct(private FinanceStore $store, private bool $enabled)
    {
        $this->ledger = new AccountLedger($store->pdo());
    }

    public static function requestPermission(string $kind): string
    {
        return self::PERMISSIONS[$kind][0];
    }

    public static function approvePermission(string $kind): string
    {
        return self::PERMISSIONS[$kind][1];
    }

    // ---- Commands --------------------------------------------------------------------------------------------------

    public function create(EmployeeIdentity $actor, array $input, string $key, string $request): array
    {
        $kind = (string) In::enum($input, 'kind', self::KINDS);
        $this->requireEnabled();
        $companyId = In::uuid($input, 'companyId');
        $data = $this->payload($kind, $input);
        $submit = array_key_exists('submit', $input) ? In::bool($input, 'submit') : true;
        $evidence = In::evidence($input);
        return $this->store->mutate($actor, 'ledger_request_create', $input, $key, [self::requestPermission($kind)],
            function (string $now) use ($actor, $kind, $companyId, $data, $submit, $evidence, $key, $request): array {
                $company = $this->lockCompany($companyId);
                $data = $this->prepare($kind, $company, $data);
                $this->assertBankIdentityFree($data['bankKey'], null);
                $pdo = $this->store->pdo();
                $pdo->prepare('INSERT INTO finance_ledger_request_sequence (created_at) VALUES (?)')->execute([$now]);
                $number = (int) $pdo->lastInsertId();
                $id = Uuid::v4();
                $status = $submit ? 'PENDING_APPROVAL' : 'DRAFT';
                try {
                    $pdo->prepare('INSERT INTO finance_ledger_requests (request_uuid, request_number, request_code, kind, status, company_uuid, currency_code, amount, direction, value_date,
                        payment_method, bank_provider, bank_account_key, bank_transaction_id, bank_transaction_key, active_bank_transaction_key, external_reference, note, allocations_json,
                        target_movement_uuid, active_reversal_target, created_by_employee_uuid, created_at, last_edited_by_employee_uuid, updated_at, submitted_by_employee_uuid, submitted_at, version)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')->execute([
                        $id, $number, self::code($number), $kind, $status, $companyId, $data['currency'], AccountMoney::format($data['cents']), $data['direction'], $data['valueDate'],
                        $data['method'], $data['bank']['provider'] ?? null, $data['bank']['accountKey'] ?? null, $data['bank']['transactionId'] ?? null, $data['bankKey'], $data['bankKey'],
                        $data['externalReference'], $data['note'], $data['allocations'] === [] ? null : FinanceStore::json(self::allocationsOut($data['allocations'])),
                        $data['target'], $kind === 'REVERSAL' ? $data['target'] : null, $actor->employeeUuid, $now, $actor->employeeUuid, $now,
                        $submit ? $actor->employeeUuid : null, $submit ? $now : null,
                    ]);
                } catch (PDOException $e) {
                    throw self::uniqueConflict($e) ?? $e;
                }
                $this->store->evidence('ledger_request', $id, $evidence, $actor, $now);
                $this->store->event('ledger_request', $id, $companyId, $actor, 'ledger_request_created', null, 1, null, ['kind' => $kind, 'status' => $status], $request, $key, $now);
                $row = $this->row($id);
                return ['status' => 201, 'request' => LedgerRequestQueries::present($row), 'possibleDuplicates' => $this->possibleDuplicates($row)];
            });
    }

    /** Replace the content of one's own draft. Kind and company never change. */
    public function update(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        $this->requireEnabled();
        $peek = $this->peek($id);
        $version = In::version($input);
        $body = $input;
        unset($body['expectedVersion']);
        $body['kind'] = $peek['kind'];
        $body['companyId'] = $peek['company_uuid'];
        $data = $this->payload($peek['kind'], $body);
        return $this->store->mutate($actor, 'ledger_request_update:' . $id, $input, $key, [self::requestPermission($peek['kind'])],
            function (string $now) use ($actor, $id, $version, $data, $key, $request): array {
                [$company, $r] = $this->lockCompanyAndRequest($id);
                FinanceStore::expectVersion($r, $version);
                $this->requireOwner($r, $actor);
                if ($r['status'] !== 'DRAFT') {
                    throw new ApiException(409, 'LEDGER_REQUEST_NOT_DRAFT', 'Only a draft can be edited.', ['status' => $r['status']]);
                }
                $data = $this->prepare((string) $r['kind'], $company, $data);
                $this->assertBankIdentityFree($data['bankKey'], $id);
                try {
                    $this->write($r, [
                        'currency_code' => $data['currency'], 'amount' => AccountMoney::format($data['cents']), 'direction' => $data['direction'], 'value_date' => $data['valueDate'],
                        'payment_method' => $data['method'], 'bank_provider' => $data['bank']['provider'] ?? null, 'bank_account_key' => $data['bank']['accountKey'] ?? null,
                        'bank_transaction_id' => $data['bank']['transactionId'] ?? null, 'bank_transaction_key' => $data['bankKey'], 'active_bank_transaction_key' => $data['bankKey'],
                        'external_reference' => $data['externalReference'], 'note' => $data['note'],
                        'allocations_json' => $data['allocations'] === [] ? null : FinanceStore::json(self::allocationsOut($data['allocations'])),
                        'target_movement_uuid' => $data['target'], 'active_reversal_target' => $r['kind'] === 'REVERSAL' ? $data['target'] : null,
                        'last_edited_by_employee_uuid' => $actor->employeeUuid,
                    ], $now);
                } catch (PDOException $e) {
                    throw self::uniqueConflict($e) ?? $e;
                }
                $this->store->event('ledger_request', $id, (string) $r['company_uuid'], $actor, 'ledger_request_edited', (int) $r['version'], (int) $r['version'] + 1, null, null, $request, $key, $now);
                $row = $this->row($id);
                return ['status' => 200, 'request' => LedgerRequestQueries::present($row), 'possibleDuplicates' => $this->possibleDuplicates($row)];
            });
    }

    public function submit(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion']);
        $version = In::version($input);
        $this->requireEnabled();
        $peek = $this->peek($id);
        return $this->store->mutate($actor, 'ledger_request_submit:' . $id, $input, $key, [self::requestPermission($peek['kind'])],
            function (string $now) use ($actor, $id, $version, $key, $request): array {
                [$company, $r] = $this->lockCompanyAndRequest($id);
                FinanceStore::expectVersion($r, $version);
                $this->requireOwner($r, $actor);
                if ($r['status'] !== 'DRAFT') {
                    throw new ApiException(409, 'LEDGER_REQUEST_NOT_DRAFT', 'Only a draft can be submitted.', ['status' => $r['status']]);
                }
                $this->write($r, ['status' => 'PENDING_APPROVAL', 'submitted_by_employee_uuid' => $actor->employeeUuid, 'submitted_at' => $now], $now);
                $this->store->event('ledger_request', $id, (string) $r['company_uuid'], $actor, 'ledger_request_submitted', (int) $r['version'], (int) $r['version'] + 1, null, null, $request, $key, $now);
                $row = $this->row($id);
                return ['status' => 200, 'request' => LedgerRequestQueries::present($row), 'possibleDuplicates' => $this->possibleDuplicates($row)];
            });
    }

    /** The creator takes back a draft or a pending request. Its bank identity becomes free again. */
    public function withdraw(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = In::reason($input, 'reason', false);
        $peek = $this->peek($id);
        return $this->store->mutate($actor, 'ledger_request_withdraw:' . $id, $input, $key, [self::requestPermission($peek['kind'])],
            function (string $now) use ($actor, $id, $version, $reason, $key, $request): array {
                [, $r] = $this->lockCompanyAndRequest($id);
                FinanceStore::expectVersion($r, $version);
                $this->requireOwner($r, $actor);
                if (!in_array($r['status'], ['DRAFT', 'PENDING_APPROVAL'], true)) {
                    throw new ApiException(409, 'LEDGER_REQUEST_NOT_OPEN', 'Only a draft or a pending request can be withdrawn.', ['status' => $r['status']]);
                }
                $this->write($r, ['status' => 'WITHDRAWN', 'closed_by_employee_uuid' => $actor->employeeUuid, 'closed_at' => $now,
                    'active_bank_transaction_key' => null, 'active_reversal_target' => null], $now);
                $this->store->event('ledger_request', $id, (string) $r['company_uuid'], $actor, 'ledger_request_withdrawn', (int) $r['version'], (int) $r['version'] + 1, $reason, null, $request, $key, $now);
                return ['status' => 200, 'request' => LedgerRequestQueries::present($this->row($id))];
            });
    }

    public function reject(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = (string) In::reason($input);
        $peek = $this->peek($id);
        return $this->store->mutate($actor, 'ledger_request_reject:' . $id, $input, $key, [self::approvePermission($peek['kind'])],
            function (string $now) use ($actor, $id, $version, $reason, $peek, $key, $request): array {
                [, $r] = $this->lockCompanyAndRequest($id);
                $this->decidable($r, $peek, $version, $actor);
                $this->write($r, ['status' => 'REJECTED', 'decided_by_employee_uuid' => $actor->employeeUuid, 'decided_at' => $now, 'decision_reason' => $reason,
                    'active_bank_transaction_key' => null, 'active_reversal_target' => null], $now);
                $this->store->event('ledger_request', $id, (string) $r['company_uuid'], $actor, 'ledger_request_rejected', (int) $r['version'], (int) $r['version'] + 1, $reason, null, $request, $key, $now);
                return ['status' => 200, 'request' => LedgerRequestQueries::present($this->row($id))];
            });
    }

    /**
     * Second-person approval. Everything the creator stated is checked again against the locked account, then the ledger
     * movement (and, for payments, the allocations) is posted through AccountLedger in this same transaction.
     */
    public function approve(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'acknowledgePossibleDuplicates', 'allocationMode']);
        $version = In::version($input);
        $acknowledged = array_key_exists('acknowledgePossibleDuplicates', $input) && In::bool($input, 'acknowledgePossibleDuplicates');
        $allocationMode = (string) (In::enum($input, 'allocationMode', ['as_requested', 'none'], false) ?? 'as_requested');
        $this->requireEnabled();
        $peek = $this->peek($id);
        return $this->store->mutate($actor, 'ledger_request_approve:' . $id, $input, $key, [self::approvePermission($peek['kind'])],
            function (string $now) use ($actor, $id, $version, $acknowledged, $allocationMode, $peek, $key, $request): array {
                [$company, $r] = $this->lockCompanyAndRequest($id);
                $this->decidable($r, $peek, $version, $actor);
                $duplicates = $this->possibleDuplicates($r);
                if ($duplicates !== [] && !$acknowledged) {
                    throw new ApiException(409, 'POSSIBLE_DUPLICATE_UNACKNOWLEDGED', 'Similar entries exist. Compare them and confirm that this is a different payment.', ['possibleDuplicates' => $duplicates]);
                }
                $cents = AccountMoney::fromDecimal((string) $r['amount']);
                $currency = (string) $r['currency_code'];
                $allocationIds = [];
                $snapshot = self::snapshot($r, $company, $actor);
                switch ($r['kind']) {
                    case 'PAYMENT':
                        $movement = $this->ledger->insertMovement((string) $company['company_uuid'], $currency, 'payment', 'credit', $cents, (string) $r['value_date'], null, null,
                            (string) $r['payment_method'], $r['external_reference'] ?? $r['bank_transaction_id'], $r['note'], $snapshot, $actor, $now);
                        $this->ledger->activity((string) $company['company_uuid'], 'payment_recorded', $actor, $request, $key, $now, $movement, null, null, $currency);
                        $allocations = $allocationMode === 'none' ? [] : self::allocationsIn($r['allocations_json']);
                        if ($allocations !== []) {
                            $this->ledger->assertAllocatable((string) $company['company_uuid'], (array) $this->ledger->movement($movement), $allocations);
                            foreach ($allocations as $allocation) {
                                $allocationIds[] = $this->ledger->allocate((string) $company['company_uuid'], $currency, $movement, $allocation['receivableId'], $allocation['amount'], $actor, $now, $request, $key);
                            }
                        }
                        break;
                    case 'ADJUSTMENT':
                        if ($company['status'] !== 'active' && $r['direction'] === 'debit') {
                            throw new ApiException(409, 'COMPANY_INACTIVE', 'The company is inactive: a debit adjustment is not allowed.');
                        }
                        $movement = $this->ledger->insertMovement((string) $company['company_uuid'], $currency, 'adjustment', (string) $r['direction'], $cents, (string) $r['value_date'], null, null,
                            null, null, $r['note'], $snapshot, $actor, $now);
                        $this->ledger->activity((string) $company['company_uuid'], 'adjustment_posted', $actor, $request, $key, $now, $movement, null, null, $currency);
                        break;
                    case 'REVERSAL':
                        $target = $this->reversible((string) $company['company_uuid'], (string) $r['target_movement_uuid'], true);
                        if ((string) $r['value_date'] < (string) $target['value_date']) {
                            throw new ApiException(409, 'REVERSAL_DATE_BEFORE_ORIGINAL', 'The reversal date cannot be before the original movement.');
                        }
                        $movement = $this->ledger->reverse($company, $target, (string) $r['value_date'], $r['note'], 'finance_request', 'movement_reversed', $actor, $now, $request, $key);
                        $this->closeReversedRequest((string) $target['movement_uuid'], $movement, $actor, $now, $request, $key);
                        break;
                    case 'REFUND':
                        $payment = $this->refundSource((string) $company['company_uuid'], (string) $r['target_movement_uuid'], $currency, $cents);
                        $movement = $this->ledger->insertMovement((string) $company['company_uuid'], $currency, 'refund', 'debit', $cents, (string) $r['value_date'], null, null,
                            (string) $r['payment_method'], $r['external_reference'], $r['note'], $snapshot + ['sourcePaymentCode' => $payment['movement_code']], $actor, $now);
                        $this->ledger->activity((string) $company['company_uuid'], 'refund_posted', $actor, $request, $key, $now, $movement, null, null, $currency);
                        $allocationIds[] = $this->ledger->allocate((string) $company['company_uuid'], $currency, (string) $payment['movement_uuid'], $movement, $cents, $actor, $now, $request, $key);
                        break;
                    default:
                        throw new ApiException(409, 'LEDGER_REQUEST_KIND_UNKNOWN', 'Unknown request kind.');
                }
                $this->write($r, ['status' => 'APPROVED', 'decided_by_employee_uuid' => $actor->employeeUuid, 'decided_at' => $now, 'posted_movement_uuid' => $movement,
                    'possible_duplicates_acknowledged' => $duplicates !== [] ? 1 : 0, 'active_reversal_target' => null], $now);
                $this->store->event('ledger_request', $id, (string) $r['company_uuid'], $actor, 'ledger_request_approved', (int) $r['version'], (int) $r['version'] + 1, null,
                    ['movementId' => $movement, 'allocations' => count($allocationIds), 'duplicatesAcknowledged' => count($duplicates), 'allocationMode' => $allocationMode], $request, $key, $now);
                return ['status' => 200, 'request' => LedgerRequestQueries::present($this->row($id)), 'movementId' => $movement, 'allocationIds' => $allocationIds];
            });
    }

    /** The bank or the cash desk paid out an approved refund. Recorded by someone other than the requester. */
    public function payout(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'bankTransaction', 'paidOn', 'note']);
        $version = In::version($input);
        $bank = (array) BankTransaction::parse($input, 'bankTransaction', true);
        $paidOn = (string) In::date($input, 'paidOn');
        if ($paidOn > $this->store->today()) {
            In::fail('paidOn', 'future');
        }
        $note = In::text($input, 'note', 2000, false, true);
        $this->requireEnabled();
        return $this->store->mutate($actor, 'ledger_request_payout:' . $id, $input, $key, [A::REFUNDS_PAYOUT],
            function (string $now) use ($actor, $id, $version, $bank, $paidOn, $note, $key, $request): array {
                [, $r] = $this->lockCompanyAndRequest($id);
                FinanceStore::expectVersion($r, $version);
                if ($r['kind'] !== 'REFUND' || $r['status'] !== 'APPROVED') {
                    throw new ApiException(409, 'REFUND_NOT_AWAITING_PAYOUT', 'Only an approved refund waits for its payout.', ['status' => $r['status']]);
                }
                if ($r['created_by_employee_uuid'] === $actor->employeeUuid) {
                    throw new ApiException(403, 'FOUR_EYES_REQUIRED', 'The person who requested the refund cannot confirm its payout.');
                }
                $payoutKey = BankTransaction::key('OUT', $bank, (string) $r['currency_code']);
                $s = $this->store->pdo()->prepare('SELECT request_code FROM finance_ledger_requests WHERE payout_bank_transaction_key = ?');
                $s->execute([$payoutKey]);
                if (($code = $s->fetchColumn()) !== false) {
                    throw new ApiException(409, 'BANK_TRANSACTION_ALREADY_RECORDED', 'This bank transaction is already recorded.', ['requestCode' => $code]);
                }
                try {
                    $this->write($r, ['status' => 'PAID_OUT', 'payout_bank_provider' => $bank['provider'], 'payout_bank_account_key' => $bank['accountKey'],
                        'payout_bank_transaction_id' => $bank['transactionId'], 'payout_bank_transaction_key' => $payoutKey,
                        'payout_recorded_by_employee_uuid' => $actor->employeeUuid, 'payout_recorded_at' => $now], $now);
                } catch (PDOException $e) {
                    throw self::uniqueConflict($e) ?? $e;
                }
                $this->store->event('ledger_request', $id, (string) $r['company_uuid'], $actor, 'refund_paid_out', (int) $r['version'], (int) $r['version'] + 1, $note, ['paidOn' => $paidOn], $request, $key, $now);
                return ['status' => 200, 'request' => LedgerRequestQueries::present($this->row($id))];
            });
    }

    /** An approved refund that will not be paid out: the refund movement is reversed and the payment credit returns. */
    public function cancelPayout(EmployeeIdentity $actor, string $id, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = (string) In::reason($input);
        $this->requireEnabled();
        return $this->store->mutate($actor, 'ledger_request_cancel_payout:' . $id, $input, $key, [A::REFUNDS_APPROVE],
            function (string $now) use ($actor, $id, $version, $reason, $key, $request): array {
                [$company, $r] = $this->lockCompanyAndRequest($id);
                FinanceStore::expectVersion($r, $version);
                if ($r['kind'] !== 'REFUND' || $r['status'] !== 'APPROVED') {
                    throw new ApiException(409, 'REFUND_NOT_AWAITING_PAYOUT', 'Only an approved refund that was not paid out can be cancelled.', ['status' => $r['status']]);
                }
                if ($r['created_by_employee_uuid'] === $actor->employeeUuid) {
                    throw new ApiException(403, 'FOUR_EYES_REQUIRED', 'The person who requested the refund cannot cancel it.');
                }
                $refund = $this->ledger->movement((string) $r['posted_movement_uuid'], true);
                if ($refund === null || $this->ledger->reversalOf((string) $refund['movement_uuid']) !== null) {
                    throw new ApiException(409, 'MOVEMENT_ALREADY_REVERSED', 'The refund movement was already reversed.');
                }
                $reversal = $this->ledger->reverse($company, $refund, $this->store->today() < (string) $refund['value_date'] ? (string) $refund['value_date'] : $this->store->today(),
                    $reason, 'refund_cancelled', 'movement_reversed', $actor, $now, $request, $key);
                $this->write($r, ['status' => 'REVERSED', 'reversal_movement_uuid' => $reversal, 'closed_by_employee_uuid' => $actor->employeeUuid, 'closed_at' => $now], $now);
                $this->store->event('ledger_request', $id, (string) $r['company_uuid'], $actor, 'refund_cancelled', (int) $r['version'], (int) $r['version'] + 1, $reason, ['movementId' => $reversal], $request, $key, $now);
                return ['status' => 200, 'request' => LedgerRequestQueries::present($this->row($id)), 'movementId' => $reversal];
            });
    }

    // ---- Possible duplicates (review only, never a block) ------------------------------------------------------------

    /**
     * Payments of the same company and currency that look like the same money: an equal reference or transaction id
     * (normalized), or the same amount within three days. Exact bank identities never get here: they are unique.
     * @return list<array<string, mixed>>
     */
    public function possibleDuplicates(array $r): array
    {
        if ($r['kind'] !== 'PAYMENT' || !in_array($r['status'], ['DRAFT', 'PENDING_APPROVAL', 'APPROVED'], true)) {
            return [];
        }
        $refs = array_values(array_unique(array_filter([BankTransaction::normalized($r['external_reference']), BankTransaction::normalized($r['bank_transaction_id'])], static fn (string $v): bool => strlen($v) >= 4)));
        $refSql = $refs === [] ? '0' : "UPPER(REGEXP_REPLACE(COALESCE(%s, ''), '[^A-Za-z0-9]', '')) IN (" . implode(',', array_fill(0, count($refs), '?')) . ')';
        $pdo = $this->store->pdo();
        $out = [];
        $ledger = $pdo->prepare("SELECT m.movement_uuid, m.movement_code, m.amount, m.value_date, m.external_reference FROM b2b_account_movements m
            WHERE m.company_uuid = ? AND m.currency_code = ? AND m.movement_type = 'payment' AND (m.movement_uuid <> ? )
              AND NOT EXISTS (SELECT 1 FROM b2b_account_movements x WHERE x.reversed_movement_uuid = m.movement_uuid)
              AND ((m.amount = ? AND ABS(DATEDIFF(m.value_date, ?)) <= 3) OR " . sprintf($refSql, 'm.external_reference') . ')
            ORDER BY m.value_date DESC LIMIT 20');
        $ledger->execute([$r['company_uuid'], $r['currency_code'], (string) ($r['posted_movement_uuid'] ?? ''), $r['amount'], $r['value_date'], ...$refs]);
        foreach ($ledger->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $out[] = ['source' => 'ledger', 'id' => $m['movement_uuid'], 'code' => $m['movement_code'], 'amount' => $m['amount'], 'valueDate' => $m['value_date'],
                'reasons' => self::reasons($r, (string) $m['amount'], (string) $m['value_date'], [$m['external_reference']], $refs)];
        }
        $requests = $pdo->prepare("SELECT request_uuid, request_code, amount, value_date, status, external_reference, bank_transaction_id FROM finance_ledger_requests
            WHERE company_uuid = ? AND currency_code = ? AND kind = 'PAYMENT' AND request_uuid <> ? AND status IN ('DRAFT', 'PENDING_APPROVAL', 'APPROVED')
              AND ((amount = ? AND ABS(DATEDIFF(value_date, ?)) <= 3) OR " . sprintf($refSql, 'external_reference') . ' OR ' . sprintf($refSql, 'bank_transaction_id') . ')
            ORDER BY value_date DESC LIMIT 20');
        $requests->execute([$r['company_uuid'], $r['currency_code'], $r['request_uuid'], $r['amount'], $r['value_date'], ...$refs, ...$refs]);
        foreach ($requests->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $out[] = ['source' => 'request', 'id' => $q['request_uuid'], 'code' => $q['request_code'], 'amount' => $q['amount'], 'valueDate' => $q['value_date'], 'status' => $q['status'],
                'reasons' => self::reasons($r, (string) $q['amount'], (string) $q['value_date'], [$q['external_reference'], $q['bank_transaction_id']], $refs)];
        }
        return $out;
    }

    // ---- Validation and preparation --------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function payload(string $kind, array $input): array
    {
        $allowed = ['kind', 'companyId', 'valueDate', 'note', 'evidence', 'submit', 'expectedVersion'];
        $allowed = match ($kind) {
            'PAYMENT' => [...$allowed, 'currencyCode', 'amount', 'method', 'bankTransaction', 'externalReference', 'allocations'],
            'ADJUSTMENT' => [...$allowed, 'currencyCode', 'amount', 'direction'],
            'REVERSAL' => [...$allowed, 'targetMovementId'],
            'REFUND' => [...$allowed, 'amount', 'method', 'sourcePaymentId', 'externalReference'],
        };
        In::only($input, $allowed);
        $valueDate = (string) In::date($input, 'valueDate');
        if ($valueDate > $this->store->today()) {
            In::fail('valueDate', 'future');
        }
        $data = ['kind' => $kind, 'valueDate' => $valueDate, 'currency' => null, 'cents' => 0, 'direction' => null, 'method' => null, 'bank' => null, 'bankKey' => null,
            'externalReference' => null, 'note' => null, 'allocations' => [], 'target' => null];
        switch ($kind) {
            case 'PAYMENT':
                $data['currency'] = (string) In::enum($input, 'currencyCode', ['RON', 'EUR']);
                $data['cents'] = In::money($input, 'amount');
                $data['direction'] = 'credit';
                $data['method'] = (string) In::enum($input, 'method', self::METHODS);
                $data['bank'] = BankTransaction::parse($input, 'bankTransaction', in_array($data['method'], self::BANK_METHODS, true));
                $data['bankKey'] = $data['bank'] === null ? null : BankTransaction::key('IN', $data['bank'], $data['currency']);
                $data['externalReference'] = In::text($input, 'externalReference', 160);
                $data['note'] = In::text($input, 'note', 2000, $data['method'] === 'other', true);
                if ($data['method'] === 'compensation' && $data['externalReference'] === null && $data['note'] === null) {
                    In::fail('note', 'required');
                }
                $data['allocations'] = self::allocationInput($input['allocations'] ?? [], $data['cents']);
                break;
            case 'ADJUSTMENT':
                $data['currency'] = (string) In::enum($input, 'currencyCode', ['RON', 'EUR']);
                $data['cents'] = In::money($input, 'amount');
                $data['direction'] = (string) In::enum($input, 'direction', ['debit', 'credit']);
                $data['note'] = (string) In::reason($input, 'note');
                break;
            case 'REVERSAL':
                $data['target'] = In::uuid($input, 'targetMovementId');
                $data['note'] = (string) In::reason($input, 'note');
                break;
            case 'REFUND':
                $data['target'] = In::uuid($input, 'sourcePaymentId');
                $data['cents'] = In::money($input, 'amount');
                $data['direction'] = 'debit';
                $data['method'] = (string) In::enum($input, 'method', self::REFUND_METHODS);
                $data['externalReference'] = In::text($input, 'externalReference', 160);
                $data['note'] = (string) In::reason($input, 'note');
                break;
        }
        return $data;
    }

    /** Checks against the locked account that the creator's statement is possible right now; fills derived fields. */
    private function prepare(string $kind, array $company, array $data): array
    {
        $companyId = (string) $company['company_uuid'];
        switch ($kind) {
            case 'PAYMENT':
                $total = 0;
                foreach ($data['allocations'] as $allocation) {
                    $receivable = $this->ledger->movement($allocation['receivableId']);
                    if ($receivable === null || $receivable['company_uuid'] !== $companyId || $receivable['movement_type'] !== 'order_receivable') {
                        throw new ApiException(404, 'RECEIVABLE_NOT_FOUND', 'Receivable was not found.');
                    }
                    if ($receivable['currency_code'] !== $data['currency']) {
                        throw new ApiException(409, 'CURRENCY_MISMATCH', 'A payment can only settle receivables in its own currency.');
                    }
                    if ($this->ledger->reversalOf((string) $receivable['movement_uuid']) !== null) {
                        throw new ApiException(409, 'MOVEMENT_REVERSED', 'The receivable was reversed.');
                    }
                    if ($allocation['amount'] > $this->ledger->outstanding((string) $receivable['movement_uuid'], AccountMoney::fromDecimal($receivable['amount']))) {
                        throw new ApiException(409, 'ALLOCATION_EXCEEDS_OUTSTANDING', 'The allocation exceeds the outstanding receivable amount.');
                    }
                    $total += $allocation['amount'];
                }
                break;
            case 'ADJUSTMENT':
                if ($company['status'] !== 'active' && $data['direction'] === 'debit') {
                    throw new ApiException(409, 'COMPANY_INACTIVE', 'The company is inactive: a debit adjustment is not allowed.');
                }
                break;
            case 'REVERSAL':
                $target = $this->reversible($companyId, (string) $data['target'], false);
                if ($data['valueDate'] < (string) $target['value_date']) {
                    In::fail('valueDate', 'before_original');
                }
                $data['currency'] = (string) $target['currency_code'];
                $data['cents'] = AccountMoney::fromDecimal((string) $target['amount']);
                $s = $this->store->pdo()->prepare('SELECT request_code FROM finance_ledger_requests WHERE active_reversal_target = ?');
                $s->execute([$data['target']]);
                if (($code = $s->fetchColumn()) !== false) {
                    throw new ApiException(409, 'REVERSAL_ALREADY_REQUESTED', 'A reversal of this movement is already waiting.', ['requestCode' => $code]);
                }
                break;
            case 'REFUND':
                $payment = $this->ledger->movement((string) $data['target']);
                if ($payment === null || $payment['company_uuid'] !== $companyId || $payment['movement_type'] !== 'payment') {
                    throw new ApiException(404, 'PAYMENT_NOT_FOUND', 'Payment was not found.');
                }
                $data['currency'] = (string) $payment['currency_code'];
                $this->refundSource($companyId, (string) $data['target'], $data['currency'], $data['cents']);
                break;
        }
        return $data;
    }

    /** A reversible movement of this company: payment, adjustment or opening balance, not yet reversed. */
    private function reversible(string $companyId, string $movementId, bool $lock): array
    {
        $target = $this->ledger->movement($movementId, $lock);
        if ($target === null || $target['company_uuid'] !== $companyId) {
            throw new ApiException(404, 'MOVEMENT_NOT_FOUND', 'Movement was not found.');
        }
        if ($target['movement_type'] === 'order_receivable') {
            throw new ApiException(409, 'ORDER_RECEIVABLE_FOLLOWS_ORDER', 'Cancel the commercial order to reverse its receivable.');
        }
        if ($target['movement_type'] === 'refund') {
            throw new ApiException(409, 'REFUND_FOLLOWS_REFUND_REQUEST', 'A refund is cancelled through its refund request.');
        }
        if (!in_array($target['movement_type'], self::REVERSIBLE, true)) {
            throw new ApiException(409, 'REVERSAL_NOT_REVERSIBLE', 'A reversal cannot be reversed. Post a new movement instead.');
        }
        if ($this->ledger->reversalOf((string) $target['movement_uuid']) !== null) {
            throw new ApiException(409, 'MOVEMENT_ALREADY_REVERSED', 'The movement was already reversed.');
        }
        return $target;
    }

    /** The payment a refund comes from: unreversed, enough unallocated money, and the company really holds that credit. */
    private function refundSource(string $companyId, string $paymentId, string $currency, int $cents): array
    {
        $payment = $this->ledger->movement($paymentId, true);
        if ($payment === null || $payment['company_uuid'] !== $companyId || $payment['movement_type'] !== 'payment') {
            throw new ApiException(404, 'PAYMENT_NOT_FOUND', 'Payment was not found.');
        }
        if ($payment['currency_code'] !== $currency) {
            throw new ApiException(409, 'CURRENCY_MISMATCH', 'A refund is paid in the currency of its payment.');
        }
        if ($this->ledger->reversalOf((string) $payment['movement_uuid']) !== null) {
            throw new ApiException(409, 'MOVEMENT_REVERSED', 'The payment was reversed.');
        }
        if ($cents > $this->ledger->available((string) $payment['movement_uuid'], AccountMoney::fromDecimal((string) $payment['amount']))) {
            throw new ApiException(409, 'REFUND_EXCEEDS_UNALLOCATED_PAYMENT', 'The refund exceeds the unallocated part of the payment.');
        }
        $s = $this->store->pdo()->prepare("SELECT COALESCE(SUM(CASE direction WHEN 'debit' THEN amount ELSE -amount END), 0) FROM b2b_account_movements WHERE company_uuid = ? AND currency_code = ?");
        $s->execute([$companyId, $currency]);
        $balance = AccountMoney::fromDecimal((string) $s->fetchColumn());
        if ($cents > -$balance) {
            throw new ApiException(409, 'REFUND_EXCEEDS_CREDIT', 'The company does not hold that much credit in this currency.');
        }
        return $payment;
    }

    /** @return list<array{receivableId: string, amount: int}> */
    private static function allocationInput(mixed $value, int $paymentCents): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            In::fail('allocations', 'invalid');
        }
        $out = [];
        $seen = [];
        $total = 0;
        foreach ($value as $i => $item) {
            if (!is_array($item) || array_is_list($item)) {
                In::fail("allocations.{$i}", 'invalid');
            }
            In::only($item, ['receivableId', 'amount']);
            $id = In::uuid($item, 'receivableId');
            if (isset($seen[$id])) {
                In::fail("allocations.{$i}.receivableId", 'duplicate');
            }
            $seen[$id] = true;
            $cents = In::money($item, 'amount');
            $total += $cents;
            $out[] = ['receivableId' => $id, 'amount' => $cents];
        }
        if ($total > $paymentCents) {
            throw new ApiException(409, 'ALLOCATION_EXCEEDS_PAYMENT', 'The allocations exceed the payment amount.');
        }
        return $out;
    }

    /** @return list<array{receivableId: string, amount: string}> */
    private static function allocationsOut(array $allocations): array
    {
        return array_map(static fn (array $a): array => ['receivableId' => $a['receivableId'], 'amount' => AccountMoney::format($a['amount'])], $allocations);
    }

    /** @return list<array{receivableId: string, amount: int}> */
    private static function allocationsIn(?string $json): array
    {
        if ($json === null) {
            return [];
        }
        return array_map(static fn (array $a): array => ['receivableId' => (string) $a['receivableId'], 'amount' => AccountMoney::fromDecimal((string) $a['amount'])],
            (array) json_decode($json, true, 8, JSON_THROW_ON_ERROR));
    }

    // ---- Helpers ---------------------------------------------------------------------------------------------------

    private function requireEnabled(): void
    {
        if (!$this->enabled) {
            throw new ApiException(409, 'FINANCE_COLLECTIONS_DISABLED', 'Finance collections are not enabled on this server.');
        }
    }

    /** Reads kind and company without a lock so the right permission can be required; both never change. */
    private function peek(string $id): array
    {
        if (!In::isUuid($id)) {
            throw new ApiException(404, 'LEDGER_REQUEST_NOT_FOUND', 'Request was not found.');
        }
        $s = $this->store->pdo()->prepare('SELECT kind, company_uuid FROM finance_ledger_requests WHERE request_uuid = ?');
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ApiException(404, 'LEDGER_REQUEST_NOT_FOUND', 'Request was not found.');
        }
        return $row;
    }

    private function decidable(array $r, array $peek, int $version, EmployeeIdentity $actor): void
    {
        if ($r['kind'] !== $peek['kind']) {
            throw new ApiException(409, 'VERSION_CONFLICT', 'The record changed. Reload it before continuing.');
        }
        if ($r['status'] !== 'PENDING_APPROVAL') {
            throw new ApiException(409, 'LEDGER_REQUEST_NOT_PENDING', 'This request is no longer waiting for approval.', ['status' => $r['status'], 'currentVersion' => (int) $r['version']]);
        }
        FinanceStore::expectVersion($r, $version);
        if (in_array($actor->employeeUuid, [$r['created_by_employee_uuid'], $r['submitted_by_employee_uuid'], $r['last_edited_by_employee_uuid']], true)) {
            throw new ApiException(403, 'FOUR_EYES_REQUIRED', 'A request is approved or rejected by a second person, never by the person who entered it.');
        }
    }

    private function requireOwner(array $r, EmployeeIdentity $actor): void
    {
        if ($r['created_by_employee_uuid'] !== $actor->employeeUuid) {
            throw new ApiException(403, 'NOT_REQUEST_OWNER', 'Only the person who entered the request can change it.');
        }
    }

    private function assertBankIdentityFree(?string $bankKey, ?string $self): void
    {
        if ($bankKey === null) {
            return;
        }
        $s = $this->store->pdo()->prepare('SELECT request_uuid, request_code, status FROM finance_ledger_requests WHERE active_bank_transaction_key = ? FOR UPDATE');
        $s->execute([$bankKey]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['request_uuid'] !== $self) {
            throw new ApiException(409, 'BANK_TRANSACTION_ALREADY_RECORDED', 'This bank transaction is already recorded.', ['requestCode' => $row['request_code'], 'status' => $row['status']]);
        }
    }

    /** A Finance payment request whose movement was just reversed becomes REVERSED and frees its bank identity. */
    private function closeReversedRequest(string $movementId, string $reversalId, EmployeeIdentity $actor, string $now, string $request, string $key): void
    {
        $s = $this->store->pdo()->prepare("SELECT * FROM finance_ledger_requests WHERE posted_movement_uuid = ? AND status = 'APPROVED' FOR UPDATE");
        $s->execute([$movementId]);
        $origin = $s->fetch(PDO::FETCH_ASSOC);
        if (!$origin) {
            return;
        }
        $this->write($origin, ['status' => 'REVERSED', 'reversal_movement_uuid' => $reversalId, 'closed_by_employee_uuid' => $actor->employeeUuid, 'closed_at' => $now,
            'active_bank_transaction_key' => null], $now);
        $this->store->event('ledger_request', (string) $origin['request_uuid'], (string) $origin['company_uuid'], $actor, 'ledger_request_reversed', (int) $origin['version'], (int) $origin['version'] + 1, null,
            ['movementId' => $reversalId], $request, $key, $now);
    }

    private function lockCompany(string $companyId): array
    {
        return $this->store->lock('b2b_companies', 'company_uuid', $companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
    }

    /** Company row first, exactly like every B2B ledger writer, then the request. @return array{0: array, 1: array} */
    private function lockCompanyAndRequest(string $id): array
    {
        $peek = $this->peek($id);
        $company = $this->lockCompany((string) $peek['company_uuid']);
        return [$company, $this->store->lock('finance_ledger_requests', 'request_uuid', $id, 'LEDGER_REQUEST_NOT_FOUND', 'Request was not found.')];
    }

    private function row(string $id): array
    {
        return LedgerRequestQueries::fetch($this->store->pdo(), $id);
    }

    /** @param array<string, mixed> $columns */
    private function write(array $r, array $columns, string $now): void
    {
        $columns['updated_at'] = $now;
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($columns)));
        $s = $this->store->pdo()->prepare("UPDATE finance_ledger_requests SET {$sets}, version = version + 1 WHERE request_uuid = ? AND version = ?");
        $s->execute([...array_values($columns), $r['request_uuid'], $r['version']]);
        if ($s->rowCount() !== 1) {
            throw new ApiException(409, 'VERSION_CONFLICT', 'The record changed. Reload it before continuing.');
        }
    }

    private static function snapshot(array $r, array $company, EmployeeIdentity $approver): array
    {
        return array_filter([
            'sourceType' => 'finance_request', 'requestId' => $r['request_uuid'], 'requestCode' => $r['request_code'], 'kind' => $r['kind'],
            'recordedBy' => $r['created_by_employee_uuid'], 'approvedBy' => $approver->employeeUuid,
            'bankProvider' => $r['bank_provider'], 'bankAccountKey' => $r['bank_account_key'], 'bankTransactionId' => $r['bank_transaction_id'],
            'companyCode' => $company['company_code'], 'companyLegalName' => $company['legal_name'], 'taxIdentifier' => $company['tax_identifier'], 'countryCode' => $company['country_code'],
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** @param list<?string> $theirRefs @param list<string> $ourRefs @return list<string> */
    private static function reasons(array $r, string $amount, string $date, array $theirRefs, array $ourRefs): array
    {
        $out = [];
        $theirs = array_map(static fn (?string $v): string => BankTransaction::normalized($v), $theirRefs);
        if ($ourRefs !== [] && array_intersect($ourRefs, $theirs) !== []) {
            $out[] = 'same_reference';
        }
        if (AccountMoney::fromDecimal($amount) === AccountMoney::fromDecimal((string) $r['amount'])
            && abs((new \DateTimeImmutable($date))->diff(new \DateTimeImmutable((string) $r['value_date']))->days) <= 3) {
            $out[] = 'same_amount_near_date';
        }
        return $out;
    }

    private static function uniqueConflict(PDOException $e): ?ApiException
    {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
            return null;
        }
        $message = $e->getMessage();
        return match (true) {
            str_contains($message, 'uq_finance_ledger_active_bank'), str_contains($message, 'uq_finance_ledger_payout_bank') => new ApiException(409, 'BANK_TRANSACTION_ALREADY_RECORDED', 'This bank transaction is already recorded.'),
            str_contains($message, 'uq_finance_ledger_reversal_target') => new ApiException(409, 'REVERSAL_ALREADY_REQUESTED', 'A reversal of this movement is already waiting.'),
            default => null,
        };
    }

    public static function code(int $number): string
    {
        return sprintf('FIN-TX-%06d', $number);
    }
}
