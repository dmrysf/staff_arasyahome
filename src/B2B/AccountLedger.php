<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Insert-only current-account ledger primitives. Every method runs inside a transaction opened by the caller,
 * which already holds the company row lock (company -> order -> ledger), so all invariants are evaluated
 * against a serialized view of that company's account. Nothing here updates or deletes ledger rows.
 *
 * Signs: a debit increases what the company owes Arasya, a credit reduces it. Amounts are always positive;
 * the direction column carries the meaning. Balance = debits - credits, per company and currency.
 */
final readonly class AccountLedger
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Posts the receivable of an order that was finalized in the current transaction. One order can hold at most one
     * receivable (unique marker column); a zero total posts nothing. Returns the movement id, or null.
     */
    public function postOrderReceivable(array $company, array $order, EmployeeIdentity $actor, string $now, string $request, string $key): ?string
    {
        $cents = AccountMoney::fromDecimal($order['gross_total']);
        if ($cents <= 0) {
            return null;
        }
        $s = $this->pdo->prepare('SELECT movement_uuid FROM b2b_account_movements WHERE receivable_order_uuid=? FOR UPDATE');
        $s->execute([$order['order_uuid']]);
        $existing = $s->fetchColumn();
        if ($existing !== false) {
            return (string) $existing;
        }
        $snapshot = OrderStore::decode($order['company_snapshot']) ?? [];
        $id = $this->insertMovement($company['company_uuid'], (string) $order['currency_code'], 'order_receivable', 'debit', $cents,
            AccountInput::today(new \DateTimeImmutable($now, new \DateTimeZone('UTC'))), (string) $order['order_uuid'], null, null, null, null, [
                'sourceType' => 'b2b_order', 'sourceId' => $order['order_uuid'], 'orderCode' => $order['order_code'], 'currencyCode' => $order['currency_code'], 'orderNet' => $order['net_total'], 'orderVat' => $order['vat_total'], 'orderGross' => $order['gross_total'],
                'companyCode' => $snapshot['companyCode'] ?? $company['company_code'], 'companyLegalName' => $snapshot['legalName'] ?? $company['legal_name'],
                'taxIdentifier' => $snapshot['taxIdentifier'] ?? $company['tax_identifier'], 'countryCode' => $snapshot['countryCode'] ?? $company['country_code'],
            ], $actor, $now);
        $this->activity($company['company_uuid'], 'receivable_posted', $actor, $request, $key, $now, $id, null, (string) $order['order_uuid'], (string) $order['currency_code']);
        return $id;
    }

    /** Reverses the receivable of an order cancelled after finalization; nothing happens when none was posted. */
    public function reverseOrderReceivable(array $company, string $orderId, EmployeeIdentity $actor, string $now, string $request, string $key): ?string
    {
        $s = $this->pdo->prepare('SELECT * FROM b2b_account_movements WHERE receivable_order_uuid=? FOR UPDATE');
        $s->execute([$orderId]);
        $receivable = $s->fetch(PDO::FETCH_ASSOC);
        if (!$receivable || $this->reversalOf((string) $receivable['movement_uuid']) !== null) {
            return null;
        }
        return $this->reverse($company, $receivable, AccountInput::today(new \DateTimeImmutable($now, new \DateTimeZone('UTC'))), null, 'order_cancelled', 'receivable_reversed', $actor, $now, $request, $key);
    }

    /**
     * Inserts the opposite movement for the same amount and currency, linked to the original, and releases every
     * allocation that depended on the original. The unique reversed_movement_uuid column makes a second reversal fail.
     */
    public function reverse(array $company, array $original, string $valueDate, ?string $reason, ?string $reasonCode, string $action, EmployeeIdentity $actor, string $now, string $request, string $key): string
    {
        $id = $this->insertMovement($company['company_uuid'], (string) $original['currency_code'], 'reversal',
            $original['direction'] === 'debit' ? 'credit' : 'debit', AccountMoney::fromDecimal($original['amount']), $valueDate,
            $original['order_uuid'], (string) $original['movement_uuid'], null, null, $reason,
            ['reversedCode' => $original['movement_code'], 'reversedType' => $original['movement_type'], 'reasonCode' => $reasonCode,
                'orderCode' => (OrderStore::decode($original['source_snapshot']) ?? [])['orderCode'] ?? null],
            $actor, $now);
        $kind = $original['movement_type'] === 'payment' ? 'payment_reversed' : 'receivable_reversed';
        $column = $original['movement_type'] === 'payment' ? 'payment_movement_uuid' : 'receivable_movement_uuid';
        $s = $this->pdo->prepare("SELECT a.allocation_uuid FROM b2b_account_allocations a
            LEFT JOIN b2b_account_allocation_releases r ON r.allocation_uuid=a.allocation_uuid
            WHERE a.{$column}=? AND r.allocation_uuid IS NULL ORDER BY a.created_at,a.allocation_uuid");
        $s->execute([$original['movement_uuid']]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $allocation) {
            $this->release($company['company_uuid'], (string) $allocation, $kind, null, (string) $original['currency_code'], $actor, $now, $request, $key);
        }
        $this->activity($company['company_uuid'], $action, $actor, $request, $key, $now, $id, null, $original['order_uuid'], (string) $original['currency_code']);
        return $id;
    }

    public function allocate(string $companyId, string $currency, string $paymentId, string $receivableId, int $cents, EmployeeIdentity $actor, string $now, string $request, string $key): string
    {
        $id = Uuid::v4();
        $this->pdo->prepare('INSERT INTO b2b_account_allocations(allocation_uuid,company_uuid,currency_code,payment_movement_uuid,receivable_movement_uuid,amount,created_at,created_by_employee_uuid,created_by_name)
            VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id, $companyId, $currency, $paymentId, $receivableId, AccountMoney::format($cents), $now, $actor->employeeUuid, $actor->displayName]);
        $this->activity($companyId, 'allocation_created', $actor, $request, $key, $now, $paymentId, $id, null, $currency);
        return $id;
    }

    public function release(string $companyId, string $allocationId, string $kind, ?string $reason, string $currency, EmployeeIdentity $actor, string $now, string $request, string $key): void
    {
        $this->pdo->prepare('INSERT INTO b2b_account_allocation_releases(allocation_uuid,release_kind,reason,released_at,released_by_employee_uuid,released_by_name) VALUES(?,?,?,?,?,?)')
            ->execute([$allocationId, $kind, $reason, $now, $actor->employeeUuid, $actor->displayName]);
        $this->activity($companyId, 'allocation_released', $actor, $request, $key, $now, null, $allocationId, null, $currency);
    }

    /**
     * Checks a set of allocations from one payment against the serialized account state:
     * same company and currency, an unreversed payment and unreversed order receivables, never more than the
     * payment's unallocated amount and never more than a receivable's outstanding amount.
     * @param list<array{receivableId:string,amount:int}> $allocations
     */
    public function assertAllocatable(string $companyId, array $payment, array $allocations): void
    {
        if ($payment['company_uuid'] !== $companyId || $payment['movement_type'] !== 'payment') {
            throw new ApiException(404, 'PAYMENT_NOT_FOUND', 'Payment was not found.');
        }
        if ($this->reversalOf((string) $payment['movement_uuid']) !== null) {
            throw new ApiException(409, 'MOVEMENT_REVERSED', 'The payment was reversed.');
        }
        $total = array_sum(array_column($allocations, 'amount'));
        if ($total > $this->available((string) $payment['movement_uuid'], AccountMoney::fromDecimal($payment['amount']))) {
            throw new ApiException(409, 'ALLOCATION_EXCEEDS_PAYMENT', 'The allocation exceeds the unallocated payment amount.');
        }
        foreach ($allocations as $allocation) {
            $receivable = $this->movement($allocation['receivableId']);
            if ($receivable === null || $receivable['company_uuid'] !== $companyId || $receivable['movement_type'] !== 'order_receivable') {
                throw new ApiException(404, 'RECEIVABLE_NOT_FOUND', 'Receivable was not found.');
            }
            if ($receivable['currency_code'] !== $payment['currency_code']) {
                throw new ApiException(409, 'CURRENCY_MISMATCH', 'A payment can only settle receivables in its own currency.');
            }
            if ($this->reversalOf((string) $receivable['movement_uuid']) !== null) {
                throw new ApiException(409, 'MOVEMENT_REVERSED', 'The receivable was reversed.');
            }
            if ($allocation['amount'] > $this->outstanding((string) $receivable['movement_uuid'], AccountMoney::fromDecimal($receivable['amount']))) {
                throw new ApiException(409, 'ALLOCATION_EXCEEDS_OUTSTANDING', 'The allocation exceeds the outstanding receivable amount.');
            }
        }
    }

    /** Payment amount not yet allocated (released allocations no longer count). */
    public function available(string $paymentId, int $amount): int
    {
        return $amount - $this->allocated('payment_movement_uuid', $paymentId);
    }

    /** Receivable amount not yet settled by allocations. */
    public function outstanding(string $receivableId, int $amount): int
    {
        return $amount - $this->allocated('receivable_movement_uuid', $receivableId);
    }

    public function movement(string $id, bool $lock = false): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM b2b_account_movements WHERE movement_uuid=?' . ($lock ? ' FOR UPDATE' : ''));
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function reversalOf(string $id): ?string
    {
        $s = $this->pdo->prepare('SELECT movement_uuid FROM b2b_account_movements WHERE reversed_movement_uuid=?');
        $s->execute([$id]);
        $value = $s->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    /** Unreversed opening balances of one company and currency (at most one may be active). */
    public function activeOpening(string $companyId, string $currency): int
    {
        $s = $this->pdo->prepare("SELECT COUNT(*) FROM b2b_account_movements m
            WHERE m.company_uuid=? AND m.currency_code=? AND m.movement_type='opening_balance'
            AND NOT EXISTS (SELECT 1 FROM b2b_account_movements r WHERE r.reversed_movement_uuid=m.movement_uuid)");
        $s->execute([$companyId, $currency]);
        return (int) $s->fetchColumn();
    }

    /** @param array<string, mixed> $snapshot */
    public function insertMovement(string $companyId, string $currency, string $type, string $direction, int $cents, string $valueDate, ?string $orderId,
        ?string $reversedId, ?string $method, ?string $reference, ?string $note, array $snapshot, EmployeeIdentity $actor, string $now): string
    {
        $this->pdo->prepare('INSERT INTO b2b_account_movement_sequence(created_at) VALUES(?)')->execute([$now]);
        $number = (int) $this->pdo->lastInsertId();
        $id = Uuid::v4();
        $this->pdo->prepare('INSERT INTO b2b_account_movements(movement_uuid,movement_number,movement_code,company_uuid,currency_code,movement_type,direction,amount,value_date,
            order_uuid,receivable_order_uuid,reversed_movement_uuid,payment_method,external_reference,note,source_snapshot,created_at,created_by_employee_uuid,created_by_name)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $id, $number, self::code($number), $companyId, $currency, $type, $direction, AccountMoney::format($cents), $valueDate,
            $orderId, $type === 'order_receivable' ? $orderId : null, $reversedId, $method, $reference, $note, OrderStore::json($snapshot),
            $now, $actor->employeeUuid, $actor->displayName,
        ]);
        return $id;
    }

    public function activity(string $companyId, string $action, EmployeeIdentity $actor, string $request, string $key, string $now,
        ?string $movementId = null, ?string $allocationId = null, ?string $orderId = null, ?string $currency = null): void
    {
        $this->pdo->prepare('INSERT INTO b2b_account_activity_events(event_id,company_uuid,movement_uuid,allocation_uuid,order_uuid,currency_code,
            actor_employee_uuid,actor_name,action,request_id,idempotency_key,occurred_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            Uuid::v4(), $companyId, $movementId, $allocationId, $orderId, $currency, $actor->employeeUuid, $actor->displayName, $action,
            mb_substr($request, 0, 100), mb_substr($key, 0, 100), $now,
        ]);
    }

    public static function code(int $number): string
    {
        return sprintf('B2B-MV-%06d', $number);
    }

    private function allocated(string $column, string $id): int
    {
        $s = $this->pdo->prepare("SELECT COALESCE(SUM(a.amount),0) FROM b2b_account_allocations a
            LEFT JOIN b2b_account_allocation_releases r ON r.allocation_uuid=a.allocation_uuid
            WHERE a.{$column}=? AND r.allocation_uuid IS NULL");
        $s->execute([$id]);
        return AccountMoney::fromDecimal((string) $s->fetchColumn());
    }
}
