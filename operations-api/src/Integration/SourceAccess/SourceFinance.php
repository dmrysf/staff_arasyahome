<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

use Arasya\Operations\Integration\SourceOrderPayloadMapper;

/**
 * Validates the shop's finance answers (YD SOFT 2.15.0 `FinanceSummary`, `FinanceActions`; API 2.41.0) and copies ONLY
 * the known fields. Anything malformed rejects the whole answer (SOURCE_RESPONSE_INVALID): an employee never decides on
 * half-trusted money figures. The summary is relayed to the browser once and never stored, logged or cached by Operations
 * (it holds amounts and bank references); only its revision hash and the entry ids are kept in the audit tables.
 */
final class SourceFinance
{
    use ValidatesSourceAnswer;

    public const ACTIONS = ['submit_transfer', 'decide_transfer', 'acknowledge_liability', 'submit_refund', 'decide_refund'];
    public const PURPOSES = ['advance', 'additional_payment'];
    public const REJECT_REASONS = ['not_received', 'amount_mismatch', 'wrong_order', 'duplicate', 'other'];
    /** Shop refusals of a finance action Operations understands (anything else is SOURCE_ERROR). */
    public const REFUSALS = [
        'invalid_request', 'finance_record_disabled', 'revision_changed', 'order_busy', 'idempotency_conflict', 'finance_unavailable', 'financial_unknown',
        'transfer_pending', 'refund_pending', 'purpose_not_allowed', 'advance_not_required', 'payment_not_verified', 'nothing_due', 'amount_exceeds_balance',
        'duplicate_reference', 'reference_invalid', 'value_date_invalid', 'entry_not_found', 'entry_not_pending', 'same_employee', 'advance_record_failed',
        'no_liability', 'already_acknowledged', 'liability_not_acknowledged', 'amount_exceeds_liability', 'refund_exceeds_order', 'refund_disabled',
        'production_not_complete', 'refund_record_failed', 'internal_error', 'finance_open', 'order_cancelled',
    ];
    /**
     * Refusals of the YD SOFT safety rules (owner rule 2 and K-1), valid only as a 409 answer to the actions that can
     * produce them. Anywhere else they are a protocol violation (SOURCE_ERROR), never read as a decision:
     * - finance_open: a transfer or a refund of the order is recorded and not decided yet; a new refund is not recorded
     *   and a refund is not verified into WooCommerce until it is decided (the open entry reaches Arasya as the order's
     *   transfer_pending / refund_pending attention);
     * - order_cancelled: the shop cancelled the order; no new transfer is recorded and no transfer is verified as money
     *   (a pending one can still be rejected).
     * Both mean "nothing was recorded"; neither is a payment, a refund or a cancellation made by this action.
     */
    public const ACTION_REFUSALS = [
        'finance_open' => ['submit_refund', 'decide_refund'],
        'order_cancelled' => ['submit_transfer', 'decide_transfer'],
    ];
    private const ID = '/^[0-9]{1,20}$/D';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    private const MAX_ENTRIES = 200;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function summary(array $data, string $sourceOrderId, string $orderNumber): array
    {
        if (($data['protocol'] ?? null) !== 1) {
            throw self::invalid();
        }
        $order = self::map($data['order'] ?? null);
        if (self::text($order['id'] ?? null, 20) !== $sourceOrderId || self::text($order['number'] ?? null, 64) !== $orderNumber) {
            // An answer about another order is never shown.
            throw self::invalid();
        }
        $totals = self::map($data['totals'] ?? null);
        $received = self::map($data['received'] ?? null);
        $payment = self::map($data['payment'] ?? null);
        $balance = self::map($data['balance'] ?? null);
        $financial = self::map($data['financial'] ?? null);
        $capabilities = self::map($data['capabilities'] ?? null);
        $advance = $data['advance'] ?? null;
        $cod = self::bool($order['cod'] ?? null);
        $state = self::choice($financial['state'] ?? null, SourceOrderPayloadMapper::FINANCIAL_STATES);
        $attention = ($financial['attention'] ?? null) === null ? null
            : self::choice($financial['attention'], $state === 'cleared' ? SourceOrderPayloadMapper::FINANCIAL_ATTENTION_CLEARED : SourceOrderPayloadMapper::FINANCIAL_ATTENTION_OPEN);
        $summary = [
            'readAt' => self::instant($data['readAt'] ?? null),
            'revision' => self::pattern($data['revision'] ?? null, self::FINGERPRINT),
            'currency' => self::pattern($data['currency'] ?? null, '/^[A-Z]{3}$/D'),
            'order' => [
                'id' => $sourceOrderId,
                'number' => $orderNumber,
                'status' => self::text($order['status'] ?? null, 40),
                'modifiedAt' => self::optionalInstant($order['modifiedAt'] ?? null),
                'method' => self::text($order['method'] ?? null, 100, true),
                'methodTitle' => self::text($order['methodTitle'] ?? null, 200, true),
                'cod' => $cod,
            ],
            'totals' => [
                'current' => self::optionalMoney($totals['current'] ?? null),
                'original' => self::optionalMoney($totals['original'] ?? null),
                'refunded' => self::optionalMoney($totals['refunded'] ?? null),
            ],
            'received' => [
                'online' => self::optionalMoney($received['online'] ?? null),
                'advance' => self::optionalMoney($received['advance'] ?? null),
                'transfers' => self::optionalMoney($received['transfers'] ?? null),
                'net' => self::optionalMoney($received['net'] ?? null),
            ],
            'payment' => [
                'onlineVerified' => self::bool($payment['onlineVerified'] ?? null),
                'paidRecorded' => self::bool($payment['paidRecorded'] ?? null),
                'evidence' => self::choice($payment['evidence'] ?? null, ['netopia_ipn', 'gateway', 'none']),
            ],
            'advance' => $advance === null ? null : self::advance($advance),
            'balance' => [
                'outstanding' => self::optionalMoney($balance['outstanding'] ?? null),
                'refundLiability' => self::money($balance['refundLiability'] ?? null),
                'acknowledged' => self::bool($balance['acknowledged'] ?? null),
            ],
            'financial' => [
                'state' => $state,
                'basis' => ($financial['basis'] ?? null) === null ? null : self::choice($financial['basis'], SourceOrderPayloadMapper::FINANCIAL_BASES),
                'attention' => $attention,
            ],
            'entries' => self::list($data['entries'] ?? null, self::MAX_ENTRIES, static function (array $entry): array {
                $kind = self::choice($entry['kind'] ?? null, ['transfer_in', 'refund_out', 'liability_ack']);
                return [
                    'id' => self::pattern($entry['id'] ?? null, self::UUID),
                    'kind' => $kind,
                    'purpose' => self::choice($entry['purpose'] ?? null, ['advance', 'additional_payment', 'refund', 'liability']),
                    'amount' => self::money($entry['amount'] ?? null),
                    'reference' => self::text($entry['reference'] ?? null, 64, $kind === 'liability_ack'),
                    'valueDate' => ($entry['valueDate'] ?? null) === null ? null : self::pattern($entry['valueDate'], '/^\d{4}-\d{2}-\d{2}$/D'),
                    'state' => self::choice($entry['state'] ?? null, ['submitted', 'verified', 'rejected']),
                    'submittedBy' => self::pattern($entry['submittedBy'] ?? null, self::UUID),
                    'submittedAt' => self::instant($entry['submittedAt'] ?? null),
                    'decidedBy' => ($entry['decidedBy'] ?? null) === null ? null : self::pattern($entry['decidedBy'], self::UUID),
                    'decidedAt' => self::optionalInstant($entry['decidedAt'] ?? null),
                    'decisionCode' => ($entry['decisionCode'] ?? null) === null ? null : self::pattern($entry['decisionCode'], '/^[a-z_]{2,40}$/D'),
                ];
            }),
            'capabilities' => [
                'record' => self::bool($capabilities['record'] ?? null),
                'refunds' => self::choice($capabilities['refunds'] ?? null, ['off', 'manual']),
                'electronicRefund' => self::bool($capabilities['electronicRefund'] ?? null),
            ],
        ];
        if ($summary['capabilities']['electronicRefund'] !== false || ($cod && $advance === null)) {
            // No electronic refund exists; a cash-on-delivery summary always carries its advance.
            throw self::invalid();
        }
        return $summary;
    }

    /**
     * The shop's answer to an applied finance action.
     *
     * @param array<string, mixed> $data
     * @return array{entryId: string, summary: array<string, mixed>}
     */
    public static function applied(array $data, string $action, string $sourceOrderId, string $orderNumber): array
    {
        if (($data['protocol'] ?? null) !== 1 || ($data['applied'] ?? null) !== true || ($data['action'] ?? null) !== $action) {
            throw self::invalid();
        }
        return [
            'entryId' => self::pattern($data['entryId'] ?? null, self::UUID),
            'summary' => self::summary(self::map($data['summary'] ?? null), $sourceOrderId, $orderNumber),
        ];
    }

    /**
     * A signed refusal: its code (unknown codes are SOURCE_ERROR) and the current revision when the shop sent one. A code
     * of ACTION_REFUSALS counts only for its own actions and HTTP 409 ($action and $status of the answer).
     *
     * @param array<string, mixed> $data
     * @return array{code: string, revision: ?string}
     */
    public static function refusal(array $data, ?string $action = null, ?int $status = null): array
    {
        $error = self::map($data['error'] ?? null);
        $code = $error['code'] ?? null;
        if (!is_string($code) || !in_array($code, self::REFUSALS, true)) {
            throw new SourceAccessException('SOURCE_ERROR');
        }
        if (isset(self::ACTION_REFUSALS[$code]) && ($status !== 409 || !in_array($action, self::ACTION_REFUSALS[$code], true))) {
            throw new SourceAccessException('SOURCE_ERROR');
        }
        return ['code' => $code, 'revision' => isset($data['revision']) ? self::pattern($data['revision'], self::FINGERPRINT) : null];
    }

    /**
     * The shop's stored answer of an Idempotency-Key (reconciliation).
     *
     * @param array<string, mixed> $data
     * @return array{state: string, status?: int, result?: array<string, mixed>}
     */
    public static function status(array $data): array
    {
        $state = self::choice($data['state'] ?? null, ['absent', 'applied', 'rejected']);
        if ($state === 'absent') {
            return ['state' => 'absent'];
        }
        $status = $data['status'] ?? null;
        if (!is_int($status) || $status < 200 || $status > 599) {
            throw self::invalid();
        }
        return ['state' => $state, 'status' => $status, 'result' => self::map($data['result'] ?? null)];
    }

    /** @return array<string, mixed> */
    private static function advance(mixed $value): array
    {
        $advance = self::map($value);
        $percentage = $advance['percentage'] ?? null;
        if (!(is_int($percentage) || is_float($percentage)) || $percentage < 0 || $percentage > 100) {
            throw self::invalid();
        }
        return [
            'percentage' => $percentage,
            'required' => self::optionalMoney($advance['required'] ?? null),
            'collected' => self::optionalMoney($advance['collected'] ?? null),
            'outstanding' => self::optionalMoney($advance['outstanding'] ?? null),
            'onDelivery' => self::optionalMoney($advance['onDelivery'] ?? null),
        ];
    }

    private static function optionalMoney(mixed $value): ?string
    {
        return $value === null ? null : self::money($value);
    }
}
