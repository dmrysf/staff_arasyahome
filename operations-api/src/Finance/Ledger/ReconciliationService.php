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

/**
 * Allocation of posted payments to order receivables, and release of an allocation, from Finance (API 2.68.0).
 * Allocation is reconciliation detail: it never changes a balance, so it is a single-person action with its own
 * permission (finance.reconciliation.manage), audited in both the B2B account activity and the Finance trail.
 * The checks are the ledger's own (AccountLedger::assertAllocatable, releasableAllocation) under the company lock.
 */
final readonly class ReconciliationService
{
    private AccountLedger $ledger;

    public function __construct(private FinanceStore $store, private bool $enabled)
    {
        $this->ledger = new AccountLedger($store->pdo());
    }

    public function allocate(EmployeeIdentity $actor, string $companyId, array $input, string $key, string $request): array
    {
        $this->requireEnabled();
        In::only($input, ['paymentId', 'allocations']);
        $paymentId = In::uuid($input, 'paymentId');
        $value = $input['allocations'] ?? null;
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 50) {
            In::fail('allocations', 'invalid');
        }
        $allocations = [];
        foreach ($value as $i => $item) {
            if (!is_array($item) || array_is_list($item)) {
                In::fail("allocations.{$i}", 'invalid');
            }
            In::only($item, ['receivableId', 'amount']);
            $allocations[] = ['receivableId' => In::uuid($item, 'receivableId'), 'amount' => In::money($item, 'amount')];
        }
        return $this->store->mutate($actor, 'reconciliation_allocate:' . $companyId, $input, $key, [A::RECONCILIATION_MANAGE],
            function (string $now) use ($actor, $companyId, $paymentId, $allocations, $key, $request): array {
                $company = $this->store->lock('b2b_companies', 'company_uuid', $companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
                $payment = $this->ledger->movement($paymentId, true);
                if ($payment === null) {
                    throw new ApiException(404, 'PAYMENT_NOT_FOUND', 'Payment was not found.');
                }
                $this->ledger->assertAllocatable((string) $company['company_uuid'], $payment, $allocations);
                $ids = [];
                foreach ($allocations as $allocation) {
                    $ids[] = $this->ledger->allocate((string) $company['company_uuid'], (string) $payment['currency_code'], $paymentId, $allocation['receivableId'], $allocation['amount'], $actor, $now, $request, $key);
                }
                $this->store->event('allocation', $paymentId, $companyId, $actor, 'payment_allocated', null, null, null,
                    ['allocations' => count($ids), 'total' => AccountMoney::format(array_sum(array_column($allocations, 'amount')))], $request, $key, $now);
                return ['status' => 201, 'companyId' => $companyId, 'paymentId' => $paymentId, 'allocationIds' => $ids];
            });
    }

    public function release(EmployeeIdentity $actor, string $companyId, string $allocationId, array $input, string $key, string $request): array
    {
        $this->requireEnabled();
        In::only($input, ['reason']);
        $reason = (string) In::reason($input);
        if (!In::isUuid($allocationId)) {
            throw new ApiException(404, 'ALLOCATION_NOT_FOUND', 'Allocation was not found.');
        }
        return $this->store->mutate($actor, 'reconciliation_release:' . $allocationId, $input, $key, [A::RECONCILIATION_MANAGE],
            function (string $now) use ($actor, $companyId, $allocationId, $reason, $key, $request): array {
                $company = $this->store->lock('b2b_companies', 'company_uuid', $companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
                $allocation = $this->ledger->releasableAllocation((string) $company['company_uuid'], $allocationId);
                $this->ledger->release((string) $company['company_uuid'], $allocationId, 'manual', $reason, (string) $allocation['currency_code'], $actor, $now, $request, $key);
                $this->store->event('allocation', $allocationId, $companyId, $actor, 'allocation_released', null, null, $reason, null, $request, $key, $now);
                return ['status' => 200, 'companyId' => $companyId, 'allocationIds' => [$allocationId]];
            });
    }

    private function requireEnabled(): void
    {
        if (!$this->enabled) {
            throw new ApiException(409, 'FINANCE_COLLECTIONS_DISABLED', 'Finance collections are not enabled on this server.');
        }
    }
}
