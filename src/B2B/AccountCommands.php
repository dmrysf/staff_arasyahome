<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\OrderOperationsService;
use Arasya\Operations\Support\Clock;
use PDO;
use PDOException;
use Throwable;

/**
 * Current-account mutations. Each one: authorize, lock the company row (the same first lock as Companies and
 * Orders), replay an earlier identical request, validate invariants against the serialized account, insert
 * ledger rows and activity, then store a reference-only replay result, all in one transaction.
 * Concurrency rests on that company lock plus unique keys; ledger rows are never edited, so there is no stale
 * version to compare: every check (available payment, outstanding receivable, active opening, reversal) is
 * re-evaluated under the lock and a stale request is refused with a stable conflict code.
 */
final readonly class AccountCommands
{
    private AccountLedger $ledger;

    public function __construct(private PDO $pdo, private AuthorizationService $authorization, private Clock $clock)
    {
        $this->ledger = new AccountLedger($pdo);
    }

    public function recordPayment(EmployeeIdentity $actor, string $companyId, array $input, string $key, string $request): array
    {
        AccountAccess::require($this->authorization, $actor, AccountAccess::RECORD_PAYMENT);
        $data = AccountInput::payment($input, $this->clock->now());
        return $this->transaction($actor, 'payment', $companyId, $input, $key, AccountAccess::RECORD_PAYMENT,
            function (array $company, string $now) use ($actor, $data, $key, $request): array {
                // Inactive companies may still settle historical debt.
                $id = $this->ledger->insertMovement($company['company_uuid'], $data['currencyCode'], 'payment', 'credit', $data['amount'], $data['valueDate'],
                    null, null, $data['method'], $data['externalReference'], $data['note'], self::companySnapshot($company), $actor, $now);
                $this->ledger->activity($company['company_uuid'], 'payment_recorded', $actor, $request, $key, $now, $id, null, null, $data['currencyCode']);
                $allocations = [];
                if ($data['allocations'] !== []) {
                    $payment = $this->ledger->movement($id);
                    $this->ledger->assertAllocatable($company['company_uuid'], $payment, $data['allocations']);
                    foreach ($data['allocations'] as $allocation) {
                        $allocations[] = $this->ledger->allocate($company['company_uuid'], $data['currencyCode'], $id, $allocation['receivableId'], $allocation['amount'], $actor, $now, $request, $key);
                    }
                }
                return ['status' => 201, 'companyId' => $company['company_uuid'], 'movementId' => $id, 'allocationIds' => $allocations];
            });
    }

    public function allocate(EmployeeIdentity $actor, string $companyId, array $input, string $key, string $request): array
    {
        AccountAccess::require($this->authorization, $actor, AccountAccess::RECORD_PAYMENT);
        $data = AccountInput::allocation($input);
        return $this->transaction($actor, 'allocate', $companyId, $input, $key, AccountAccess::RECORD_PAYMENT,
            function (array $company, string $now) use ($actor, $data, $key, $request): array {
                $payment = $this->ledger->movement($data['paymentId'], true);
                if ($payment === null) throw new ApiException(404, 'PAYMENT_NOT_FOUND', 'Payment was not found.');
                $this->ledger->assertAllocatable($company['company_uuid'], $payment, $data['allocations']);
                $ids = [];
                foreach ($data['allocations'] as $allocation) {
                    $ids[] = $this->ledger->allocate($company['company_uuid'], (string) $payment['currency_code'], $data['paymentId'], $allocation['receivableId'], $allocation['amount'], $actor, $now, $request, $key);
                }
                return ['status' => 201, 'companyId' => $company['company_uuid'], 'movementId' => $data['paymentId'], 'allocationIds' => $ids];
            });
    }

    public function releaseAllocation(EmployeeIdentity $actor, string $companyId, string $allocationId, array $input, string $key, string $request): array
    {
        AccountAccess::require($this->authorization, $actor, AccountAccess::RECORD_PAYMENT);
        if (!AccountInput::isUuid($allocationId)) throw new ApiException(404, 'ALLOCATION_NOT_FOUND', 'Allocation was not found.');
        $reason = AccountInput::releaseReason($input);
        return $this->transaction($actor, 'release:' . $allocationId, $companyId, $input, $key, AccountAccess::RECORD_PAYMENT,
            function (array $company, string $now) use ($actor, $allocationId, $reason, $key, $request): array {
                $s = $this->pdo->prepare('SELECT a.*, r.allocation_uuid AS released FROM b2b_account_allocations a
                    LEFT JOIN b2b_account_allocation_releases r ON r.allocation_uuid=a.allocation_uuid WHERE a.allocation_uuid=? FOR UPDATE');
                $s->execute([$allocationId]);
                $allocation = $s->fetch(PDO::FETCH_ASSOC);
                if (!$allocation || $allocation['company_uuid'] !== $company['company_uuid']) throw new ApiException(404, 'ALLOCATION_NOT_FOUND', 'Allocation was not found.');
                if ($allocation['released'] !== null) throw new ApiException(409, 'ALLOCATION_ALREADY_RELEASED', 'The allocation was already released.');
                $this->ledger->release($company['company_uuid'], $allocationId, 'manual', $reason, (string) $allocation['currency_code'], $actor, $now, $request, $key);
                return ['status' => 200, 'companyId' => $company['company_uuid'], 'movementId' => (string) $allocation['payment_movement_uuid'], 'allocationIds' => [$allocationId]];
            });
    }

    public function openingBalance(EmployeeIdentity $actor, string $companyId, array $input, string $key, string $request): array
    {
        AccountAccess::require($this->authorization, $actor, AccountAccess::ADJUST);
        $data = AccountInput::entry($input, $this->clock->now());
        return $this->transaction($actor, 'opening_balance', $companyId, $input, $key, AccountAccess::ADJUST,
            function (array $company, string $now) use ($actor, $data, $key, $request): array {
                if ($company['status'] !== 'active') throw new ApiException(409, 'COMPANY_INACTIVE', 'The company is inactive.');
                if ($this->ledger->activeOpening($company['company_uuid'], $data['currencyCode']) > 0) {
                    throw new ApiException(409, 'OPENING_BALANCE_EXISTS', 'An opening balance already exists for this currency. Reverse it first.');
                }
                $id = $this->ledger->insertMovement($company['company_uuid'], $data['currencyCode'], 'opening_balance', $data['direction'], $data['amount'], $data['valueDate'],
                    null, null, null, null, $data['reason'], self::companySnapshot($company), $actor, $now);
                $this->ledger->activity($company['company_uuid'], 'opening_balance_posted', $actor, $request, $key, $now, $id, null, null, $data['currencyCode']);
                return ['status' => 201, 'companyId' => $company['company_uuid'], 'movementId' => $id, 'allocationIds' => []];
            });
    }

    public function adjustment(EmployeeIdentity $actor, string $companyId, array $input, string $key, string $request): array
    {
        AccountAccess::require($this->authorization, $actor, AccountAccess::ADJUST);
        $data = AccountInput::entry($input, $this->clock->now());
        return $this->transaction($actor, 'adjustment', $companyId, $input, $key, AccountAccess::ADJUST,
            function (array $company, string $now) use ($actor, $data, $key, $request): array {
                // An inactive company may receive credit adjustments, never fresh debit exposure.
                if ($company['status'] !== 'active' && $data['direction'] === 'debit') throw new ApiException(409, 'COMPANY_INACTIVE', 'The company is inactive.');
                $id = $this->ledger->insertMovement($company['company_uuid'], $data['currencyCode'], 'adjustment', $data['direction'], $data['amount'], $data['valueDate'],
                    null, null, null, null, $data['reason'], self::companySnapshot($company), $actor, $now);
                $this->ledger->activity($company['company_uuid'], 'adjustment_posted', $actor, $request, $key, $now, $id, null, null, $data['currencyCode']);
                return ['status' => 201, 'companyId' => $company['company_uuid'], 'movementId' => $id, 'allocationIds' => []];
            });
    }

    public function reverse(EmployeeIdentity $actor, string $companyId, string $movementId, array $input, string $key, string $request): array
    {
        AccountAccess::require($this->authorization, $actor, AccountAccess::REVERSE);
        if (!AccountInput::isUuid($movementId)) throw new ApiException(404, 'MOVEMENT_NOT_FOUND', 'Movement was not found.');
        $data = AccountInput::reversal($input, $this->clock->now());
        return $this->transaction($actor, 'reverse:' . $movementId, $companyId, $input, $key, AccountAccess::REVERSE,
            function (array $company, string $now) use ($actor, $movementId, $data, $key, $request): array {
                $original = $this->ledger->movement($movementId, true);
                if ($original === null || $original['company_uuid'] !== $company['company_uuid']) throw new ApiException(404, 'MOVEMENT_NOT_FOUND', 'Movement was not found.');
                if ($original['movement_type'] === 'reversal') throw new ApiException(409, 'REVERSAL_NOT_REVERSIBLE', 'A reversal cannot be reversed. Post a new movement instead.');
                // An order receivable follows its order: cancelling the order reverses it, so the two can never disagree.
                if ($original['movement_type'] === 'order_receivable') throw new ApiException(409, 'ORDER_RECEIVABLE_FOLLOWS_ORDER', 'Cancel the commercial order to reverse its receivable.');
                if ($this->ledger->reversalOf($movementId) !== null) throw new ApiException(409, 'MOVEMENT_ALREADY_REVERSED', 'The movement was already reversed.');
                if ($data['valueDate'] < (string) $original['value_date']) {
                    throw new ApiException(422, 'VALIDATION_FAILED', 'Some fields are invalid.', ['fields' => ['valueDate' => 'before_original']]);
                }
                $id = $this->ledger->reverse($company, $original, $data['valueDate'], $data['reason'], null, 'movement_reversed', $actor, $now, $request, $key);
                return ['status' => 201, 'companyId' => $company['company_uuid'], 'movementId' => $id, 'allocationIds' => []];
            });
    }

    /** The company identity a manual movement was posted against, kept for statements. @return array<string, mixed> */
    private static function companySnapshot(array $company): array
    {
        return ['companyCode' => $company['company_code'], 'companyLegalName' => $company['legal_name'], 'taxIdentifier' => $company['tax_identifier'], 'countryCode' => $company['country_code']];
    }

    private function transaction(EmployeeIdentity $actor, string $operation, string $companyId, array $body, string $key, string $permission, callable $work): array
    {
        if (!OrderOperationsService::isValidIdempotencyKey($key)) throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key is required.');
        CompanyQueries::uuidOrNotFound($companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
        $hash = hash('sha256', $operation . '|' . $companyId . '|' . OrderStore::json(self::canonical($body)), true);
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->pdo->beginTransaction();
                // Authorization is re-read inside the transaction, so a replay never outlives a revoked permission.
                AccountAccess::require($this->authorization, $actor, $permission);
                $s = $this->pdo->prepare('SELECT * FROM b2b_companies WHERE company_uuid=? FOR UPDATE');
                $s->execute([$companyId]);
                $company = $s->fetch(PDO::FETCH_ASSOC);
                if (!$company) throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
                $s = $this->pdo->prepare('SELECT operation,request_hash,company_uuid,response_json FROM b2b_account_idempotency WHERE employee_uuid=? AND idempotency_key=? FOR UPDATE');
                $s->execute([$actor->employeeUuid, $key]);
                $replay = $s->fetch(PDO::FETCH_ASSOC);
                if ($replay) {
                    if ($replay['operation'] !== $operation || $replay['company_uuid'] !== $companyId || !hash_equals($replay['request_hash'], $hash)) {
                        throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
                    }
                    $this->pdo->rollBack();
                    return OrderStore::decode($replay['response_json']);
                }
                $now = $this->clock->now()->format('Y-m-d H:i:s.u');
                $result = $work($company, $now);
                $this->pdo->prepare('INSERT INTO b2b_account_idempotency(employee_uuid,idempotency_key,operation,request_hash,company_uuid,response_json,created_at) VALUES(?,?,?,?,?,?,?)')
                    ->execute([$actor->employeeUuid, $key, $operation, $hash, $companyId, OrderStore::json($result), $now]);
                $this->pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                if ($e instanceof PDOException && $attempt < 3 && in_array((int) ($e->errorInfo[1] ?? 0), [1062, 1205, 1213], true)) continue;
                throw $e;
            }
        }
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        return array_map(self::canonical(...), $value);
    }
}
