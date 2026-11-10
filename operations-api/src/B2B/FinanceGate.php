<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\Http\ApiException;
use Closure;

/**
 * Finance decision for a B2B credit order (API 2.67.0; design in docs/b2b-finance-gate.md). Every B2B order posts a
 * receivable at finalization, so every B2B order is a credit order here; order type selects the manufacturing route only.
 *
 * Two checks, both under the company row lock that Finance owner decisions also take, so a colour or limit change and
 * an order action serialise:
 *
 *   finalization (decide)             before the status change and the receivable
 *   production submission (recheck)   before the canonical production order, its QR and its ticket exist
 *
 * Modes, read from reviewed server configuration only (ARASYA_B2B_FINANCE_GATE, ARASYA_B2B_FINANCE_RELEASE_GATE):
 *
 *   off      nothing is evaluated; the snapshot records NOT_EVALUATED
 *   shadow   evaluated and recorded; a refusal is only recorded (WOULD_BLOCK), the action goes on
 *   enforce  a refusal is returned as BLOCKED and the caller rejects the action (409)
 *
 * Any other value is a configuration failure: the action is refused with 503 FINANCE_GATE_MISCONFIGURED. It is never
 * read as off. The release mode defaults to the finalization mode when its key is absent.
 *
 * The basis is CreditService::profileData, read from the same database, never from the browser. A decision never
 * creates a shipment, a delivery, a ledger movement or a stage event.
 */
final readonly class FinanceGate
{
    public const OFF = 'off';
    public const SHADOW = 'shadow';
    public const ENFORCE = 'enforce';
    public const MODES = [self::OFF, self::SHADOW, self::ENFORCE];
    public const FINALIZATION = 'finalization';
    public const SUBMISSION = 'production_submission';
    /**
     * Refusal reasons an approved CEO exception covers: credit reasons only. Identity, account and currency reasons
     * are never covered, and an exception exists only for a RED company (CreditService::requestException).
     */
    public const EXCEPTION_COVERS = ['RISK_RED', 'NO_APPROVED_LIMIT', 'LIMIT_EXPIRED', 'LIMIT_SUSPENDED', 'LIMIT_NOT_YET_EFFECTIVE', 'LIMIT_ZERO',
        'CREDIT_REVIEW_REQUIRED', 'NO_AVAILABLE_CREDIT', 'CREDIT_LIMIT_EXCEEDED'];
    private const PROFILE_FIELDS = ['verificationStatus', 'registeredCountry', 'accountCurrency', 'reconciliationStatus', 'riskColor',
        'creditLimitStatus', 'approvedCreditLimit', 'creditLimitCurrency', 'currentExposure', 'availableCredit', 'exposureBasis', 'creditPolicyVersion',
        'creditReviewRequired', 'creditPurchaseEligibility', 'eligibilityBlockers'];
    /** Basis fields whose change between finalization and submission is reported with the release decision. */
    private const TRACKED = ['verificationStatus', 'accountCurrency', 'reconciliationStatus', 'riskColor', 'creditLimitStatus', 'approvedCreditLimit', 'creditLimitCurrency'];

    /**
     * @param Closure(string): array<string, mixed>|null $profile company id => CreditService::profileData()
     * @param string|null $releaseMode production submission mode; null inherits $mode
     * @param Closure(string): (array<string, mixed>|null)|null $exception order id => the APPROVED CEO exception row, or null
     */
    public function __construct(private string $mode = self::OFF, private ?Closure $profile = null, private ?string $releaseMode = null, private ?Closure $exception = null)
    {
    }

    /** The finalization mode. An unknown value, or an active mode without a Finance profile reader, is refused. */
    public function mode(): string
    {
        return $this->checked($this->mode);
    }

    /** The production submission mode (the finalization mode when ARASYA_B2B_FINANCE_RELEASE_GATE is absent). */
    public function releaseMode(): string
    {
        return $this->checked($this->releaseMode ?? $this->mode);
    }

    /** Whether both configured modes are usable; false means every gated action answers 503 until it is corrected. */
    public function configured(): bool
    {
        try {
            $this->mode();
            $this->releaseMode();
            return true;
        } catch (ApiException) {
            return false;
        }
    }

    /**
     * Finalization decision on the saved totals, before this order's receivable exists.
     * @param array<string, mixed> $order the locked b2b_orders row (totals already calculated)
     * @return array<string, mixed> the decision snapshot; outcome BLOCKED means the caller must reject the finalization
     */
    public function decide(string $companyId, array $order, string $now): array
    {
        $mode = $this->mode();
        $base = ['mode' => $mode, 'phase' => self::FINALIZATION, 'decidedAt' => $now, 'orderType' => $order['order_type'] ?? null,
            'orderCurrency' => $order['currency_code'], 'orderGross' => $order['gross_total']];
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
        return $this->conclude($base, $mode, $profile, array_values(array_unique($reasons)), $companyId, $order, $now);
    }

    /**
     * Release decision at explicit production submission. The order is finalized, so its own receivable is already in
     * the ledger exposure: the order is within credit while the exposure does not exceed the approved limit. It detects
     * a colour, limit, verification, account or exposure change since finalization, and an expired CEO exception.
     * An order finalized without an evaluated decision (before activation, or in mode off) is evaluated the same way.
     * @param array<string, mixed> $order the locked, finalized b2b_orders row
     * @return array<string, mixed> the decision snapshot; outcome BLOCKED means the caller must reject the submission
     */
    public function recheck(string $companyId, array $order, string $now): array
    {
        $mode = $this->releaseMode();
        $earlier = is_string($order['finance_decision'] ?? null) ? json_decode($order['finance_decision'], true) : null;
        $earlier = is_array($earlier) ? $earlier : null;
        $evaluated = in_array($earlier['outcome'] ?? null, ['ALLOWED', 'ALLOWED_BY_CEO_EXCEPTION', 'WOULD_BLOCK'], true);
        $base = ['mode' => $mode, 'phase' => self::SUBMISSION, 'decidedAt' => $now, 'orderType' => $order['order_type'] ?? null,
            'orderCurrency' => $order['currency_code'], 'orderGross' => $order['gross_total'],
            'finalization' => ['outcome' => $earlier['outcome'] ?? null, 'mode' => $earlier['mode'] ?? null, 'decidedAt' => $earlier['decidedAt'] ?? null],
            'grandfathered' => !$evaluated];
        if ($mode === self::OFF) {
            return $base + ['outcome' => 'NOT_EVALUATED'];
        }
        $profile = ($this->profile)($companyId);
        // NO_AVAILABLE_CREDIT is a statement about the next order; this order's receivable is already counted.
        $reasons = array_values(array_diff((array) ($profile['eligibilityBlockers'] ?? []), ['NO_AVAILABLE_CREDIT']));
        if (($profile['accountCurrency'] ?? null) !== null && $profile['accountCurrency'] !== $order['currency_code']) {
            $reasons[] = 'ORDER_CURRENCY_MISMATCH';
        }
        $available = $profile['availableCredit'] ?? null;
        if ($available !== null && AccountMoney::fromDecimal((string) $available) < 0) {
            $reasons[] = 'CREDIT_LIMIT_EXCEEDED';
        }
        $changes = [];
        if ($evaluated) {
            foreach (self::TRACKED as $field) {
                if (($earlier['profile'][$field] ?? null) !== ($profile[$field] ?? null)) {
                    $changes[] = $field;
                }
            }
        }
        return $this->conclude($base + ['changedSinceFinalization' => $changes], $mode, $profile, array_values(array_unique($reasons)), $companyId, $order, $now);
    }

    /**
     * The part of a stored snapshot the B2B order detail shows (owner decision D6, default): outcome, mode, phase, reason
     * codes and what changed, never the Finance basis. The approved limit, the exposure, the available credit, the order
     * gross and the CEO exception amount stay in the database and the journal for Finance and audit. A B2B order viewer
     * is not a Finance or account viewer, so the detail must not reveal them.
     * @param array<string, mixed>|null $snapshot
     * @return array<string, mixed>|null
     */
    public static function forOrderView(?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }
        $view = array_intersect_key($snapshot, array_flip(['phase', 'mode', 'outcome', 'reasons', 'decidedAt', 'orderType', 'changedSinceFinalization', 'grandfathered']));
        if (is_array($snapshot['finalization'] ?? null)) {
            $view['finalization'] = array_intersect_key($snapshot['finalization'], array_flip(['outcome', 'mode', 'decidedAt']));
        }
        if (is_array($snapshot['exception'] ?? null) && isset($snapshot['exception']['id'])) {
            $view['exceptionId'] = $snapshot['exception']['id'];
        }
        return $view;
    }

    /** The refusal a caller throws for a BLOCKED snapshot, after it rolled back. Reason codes only, no amounts. */
    public static function refusal(array $snapshot): ApiException
    {
        return ($snapshot['phase'] ?? null) === self::SUBMISSION
            ? new ApiException(409, 'FINANCE_RELEASE_BLOCKED', 'Finance does not allow this order to enter production now.', ['reasons' => $snapshot['reasons'] ?? [], 'phase' => self::SUBMISSION])
            : new ApiException(409, 'FINANCE_NOT_ELIGIBLE', 'Finance does not allow this order to be finalized on credit.', ['reasons' => $snapshot['reasons'] ?? [], 'phase' => self::FINALIZATION]);
    }

    /**
     * @param array<string, mixed> $base @param array<string, mixed> $profile @param list<string> $reasons @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function conclude(array $base, string $mode, array $profile, array $reasons, string $companyId, array $order, string $now): array
    {
        $snapshot = $base + ['reasons' => $reasons, 'profile' => array_intersect_key($profile, array_flip(self::PROFILE_FIELDS))];
        if ($reasons === []) {
            return $snapshot + ['outcome' => 'ALLOWED'];
        }
        $exception = $this->coveringException($reasons, $companyId, $order, $now);
        if ($exception !== null) {
            return $snapshot + ['outcome' => 'ALLOWED_BY_CEO_EXCEPTION', 'exception' => $exception];
        }
        return $snapshot + ['outcome' => $mode === self::ENFORCE ? 'BLOCKED' : 'WOULD_BLOCK'];
    }

    /**
     * An APPROVED CEO exception bound to this order allows it only when every refusal reason is a credit reason, the
     * company is RED now, the exception has not expired, and its currency and amount cover the order.
     * @param list<string> $reasons @param array<string, mixed> $order
     * @return array<string, mixed>|null
     */
    private function coveringException(array $reasons, string $companyId, array $order, string $now): ?array
    {
        if ($this->exception === null || !in_array('RISK_RED', $reasons, true) || array_diff($reasons, self::EXCEPTION_COVERS) !== []) {
            return null;
        }
        $row = ($this->exception)((string) $order['order_uuid']);
        if ($row === null || ($row['status'] ?? null) !== 'APPROVED' || ($row['company_uuid'] ?? null) !== $companyId
            || ($row['currency_code'] ?? null) !== $order['currency_code'] || (string) ($row['expires_at'] ?? '') <= $now
            || AccountMoney::fromDecimal((string) $row['amount']) < AccountMoney::fromDecimal((string) ($order['gross_total'] ?? '0'))) {
            return null;
        }
        return ['id' => $row['exception_uuid'], 'amount' => (string) $row['amount'], 'currency' => $row['currency_code'], 'expiresAt' => (string) $row['expires_at']];
    }

    private function checked(string $mode): string
    {
        if (!in_array($mode, self::MODES, true) || ($mode !== self::OFF && $this->profile === null)) {
            throw new ApiException(503, 'FINANCE_GATE_MISCONFIGURED', 'The B2B finance gate configuration is invalid. The action is refused until it is corrected.');
        }
        return $mode;
    }
}
