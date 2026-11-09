<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\Http\ApiException;
use Closure;

/**
 * Finance decision at B2B commercial finalization (API 2.64.0; design in docs/finance-v0.4.0.md section 10). It runs inside
 * the finalization transaction, under the company row lock that Finance owner decisions also take, so a colour or
 * limit change and a finalization serialise. Order type selects the manufacturing route only: every type is checked the
 * same way.
 *
 *   off      (default) nothing is evaluated; the snapshot records mode off
 *   shadow   evaluated and recorded; a refusal is only recorded (outcome WOULD_BLOCK), the order is finalized
 *   enforce  a refusal rejects the finalization (409 FINANCE_NOT_ELIGIBLE): no status change, no receivable, no snapshot
 *
 * A credit order needs the Finance profile ELIGIBLE (CreditService::profileData, read from the same database, never from
 * the browser), the order currency equal to the verified account currency, and the order gross total within the available
 * credit (approved limit minus the current ledger exposure, before this order's receivable). The snapshot stores the basis
 * with the finalized order. Prepaid orders and CEO exceptions are not modelled here yet (separate decision).
 */
final readonly class FinanceGate
{
    public const OFF = 'off';
    public const SHADOW = 'shadow';
    public const ENFORCE = 'enforce';
    public const MODES = [self::OFF, self::SHADOW, self::ENFORCE];

    /** @param Closure(string): array<string, mixed>|null $profile company id => CreditService::profileData() */
    public function __construct(private string $mode = self::OFF, private ?Closure $profile = null)
    {
    }

    public function mode(): string
    {
        return in_array($this->mode, self::MODES, true) && ($this->mode === self::OFF || $this->profile !== null) ? $this->mode : self::OFF;
    }

    /**
     * @param array<string, mixed> $order the locked b2b_orders row (totals already calculated)
     * @return array<string, mixed> the decision snapshot stored with the finalized order
     */
    public function decide(string $companyId, array $order, string $now): array
    {
        $mode = $this->mode();
        $base = ['mode' => $mode, 'decidedAt' => $now, 'orderType' => $order['order_type'], 'orderCurrency' => $order['currency_code'], 'orderGross' => $order['gross_total']];
        if ($mode === self::OFF) {
            return $base + ['outcome' => 'NOT_EVALUATED'];
        }
        $profile = ($this->profile)($companyId);
        $reasons = array_values((array) ($profile['eligibilityBlockers'] ?? []));
        if (($profile['accountCurrency'] ?? null) !== null && $profile['accountCurrency'] !== $order['currency_code']) {
            $reasons[] = 'ORDER_CURRENCY_MISMATCH';
        }
        $available = $profile['availableCredit'] ?? null;
        if ($available !== null && AccountMoney::fromDecimal((string) ($order['gross_total'] ?? '0')) > AccountMoney::fromDecimal((string) $available)) {
            $reasons[] = 'CREDIT_LIMIT_EXCEEDED';
        }
        $reasons = array_values(array_unique($reasons));
        $snapshot = $base + [
            'outcome' => $reasons === [] ? 'ALLOWED' : ($mode === self::ENFORCE ? 'BLOCKED' : 'WOULD_BLOCK'),
            'reasons' => $reasons,
            'profile' => array_intersect_key($profile, array_flip(['verificationStatus', 'registeredCountry', 'accountCurrency', 'reconciliationStatus', 'riskColor',
                'creditLimitStatus', 'approvedCreditLimit', 'creditLimitCurrency', 'currentExposure', 'availableCredit', 'exposureBasis', 'creditPolicyVersion',
                'creditReviewRequired', 'creditPurchaseEligibility', 'eligibilityBlockers'])),
        ];
        if ($mode === self::ENFORCE && $reasons !== []) {
            throw new ApiException(409, 'FINANCE_NOT_ELIGIBLE', 'Finance does not allow this order to be finalized on credit.', ['reasons' => $reasons]);
        }
        return $snapshot;
    }
}
