<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Http\ApiException;

/**
 * Advance payment gate of a signed commerce source order (Trend Home, Outlet Perdele).
 *
 * The source states the payment facts in its signed order snapshot: the payment method class and the advance
 * status it holds (not_required/waiting/partial/confirmed), with a monotonic confirmation revision. The class is
 * `cod` (cash on delivery, advance rules), `online` (a payment gateway the source verified, and the payment is
 * recorded as received) or `unverified` (any other gateway, or an online payment that is pending, on hold or
 * failed). Arasya never infers payment: an order whose source has not stated them is UNKNOWN, and a WooCommerce
 * status, an Arasya order, a QR or a document never count as a confirmed advance or payment.
 *
 * Open:   verified online payment, an advance that is not required, a confirmed advance.
 * Closed: an advance that is waiting, partial or unknown; an unverified payment.
 *
 * With the source's gate in ENFORCE mode a closed gate refuses the first production document and every action
 * that starts production from stage 1 (claim, stage completion, cutting intake, reassignment). Every check runs
 * on the order row read under its FOR UPDATE lock, inside the action's own transaction, so a stale screen or a
 * concurrent payment update can never open it. Orders already past stage 1 are never stopped or rewound by a
 * later change; a reversal is recorded (payment_reversed_at) and shown to managers instead.
 */
final class PaymentGate
{
    public const BLOCKED_REASON = 'payment_gate_closed';
    public const ERROR_CODE = 'AVANS_NEVALIDAT';

    public const STATE_ONLINE = 'online';
    public const STATE_NOT_REQUIRED = 'not_required';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_WAITING = 'waiting';
    public const STATE_PARTIAL = 'partial';
    public const STATE_UNKNOWN = 'unknown';
    public const STATE_UNVERIFIED = 'unverified';

    public const METHODS = ['cod', 'online', 'unverified'];
    public const ADVANCE_STATUSES = ['not_required', 'waiting', 'partial', 'confirmed'];

    private const LABELS = [
        self::STATE_ONLINE => 'Plată online',
        self::STATE_NOT_REQUIRED => 'Fără avans',
        self::STATE_CONFIRMED => 'Avans confirmat',
        self::STATE_WAITING => 'Avans neachitat',
        self::STATE_PARTIAL => 'Avans parțial',
        self::STATE_UNKNOWN => 'Avans neverificat',
        self::STATE_UNVERIFIED => 'Plată neconfirmată',
    ];

    private const MESSAGES = [
        self::STATE_WAITING => 'Comanda nu poate intra în producție. Avansul necesar nu a fost confirmat.',
        self::STATE_PARTIAL => 'Comanda nu poate intra în producție. Avansul a fost achitat doar parțial.',
        self::STATE_UNKNOWN => 'Comanda nu poate intra în producție. Magazinul nu a transmis încă starea avansului.',
        self::STATE_UNVERIFIED => 'Comanda nu poate intra în producție. Plata comenzii nu este confirmată de magazin.',
    ];

    private function __construct()
    {
    }

    /** The gate state of stored payment facts; NULL facts are UNKNOWN, never confirmed. */
    public static function state(?string $method, ?string $advanceStatus): string
    {
        if ($method === null || $advanceStatus === null) {
            return self::STATE_UNKNOWN;
        }
        if ($method === 'online') {
            return self::STATE_ONLINE;
        }
        if ($method === 'unverified') {
            return self::STATE_UNVERIFIED;
        }
        return match ($advanceStatus) {
            'not_required' => self::STATE_NOT_REQUIRED,
            'confirmed' => self::STATE_CONFIRMED,
            'partial' => self::STATE_PARTIAL,
            default => self::STATE_WAITING,
        };
    }

    public static function isClosed(string $state): bool
    {
        return in_array($state, [self::STATE_WAITING, self::STATE_PARTIAL, self::STATE_UNKNOWN, self::STATE_UNVERIFIED], true);
    }

    public static function modeFor(?ProductionAuthorityModes $modes, string $sourceKey): PaymentGateMode
    {
        return $modes?->paymentGateModeFor($sourceKey) ?? PaymentGateMode::Off;
    }

    /** Whether production may not start for these facts: the source enforces the gate and it is closed. */
    public static function blocksStart(?ProductionAuthorityModes $modes, string $sourceKey, ?string $method, ?string $advanceStatus): bool
    {
        return self::modeFor($modes, $sourceKey) === PaymentGateMode::Enforce && self::isClosed(self::state($method, $advanceStatus));
    }

    /**
     * Refuses a production start for a locked operational_orders row whose gate is enforced and closed.
     *
     * @param array<string, mixed> $order
     */
    public static function assertOpen(?ProductionAuthorityModes $modes, array $order): void
    {
        $method = isset($order['payment_method']) ? (string) $order['payment_method'] : null;
        $advance = isset($order['advance_status']) ? (string) $order['advance_status'] : null;
        if (self::blocksStart($modes, (string) $order['source_key'], $method, $advance)) {
            throw self::closed(self::state($method, $advance));
        }
    }

    public static function closed(string $state): ApiException
    {
        return new ApiException(409, self::ERROR_CODE, self::MESSAGES[$state] ?? self::MESSAGES[self::STATE_UNKNOWN], ['paymentGate' => $state]);
    }

    /**
     * The factory view of the gate: no method details beyond the class, no amounts. Null when the source's gate
     * is OFF (the order shows nothing new).
     *
     * @return array{state: string, label: string, mode: string, blocking: bool, reversedAt: string|null}|null
     */
    public static function view(?ProductionAuthorityModes $modes, string $sourceKey, ?string $method, ?string $advanceStatus, string $stageId, ?string $reversedAt): ?array
    {
        $mode = self::modeFor($modes, $sourceKey);
        if (!$mode->isVisible()) {
            return null;
        }
        $state = self::state($method, $advanceStatus);
        return [
            'state' => $state,
            'label' => self::LABELS[$state],
            'mode' => $mode->value,
            'blocking' => $mode === PaymentGateMode::Enforce && self::isClosed($state) && $stageId === \Arasya\Operations\Order\OrderProjectionWriter::INITIAL_STAGE_ID,
            'reversedAt' => $reversedAt === null ? null : (new \DateTimeImmutable($reversedAt, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
