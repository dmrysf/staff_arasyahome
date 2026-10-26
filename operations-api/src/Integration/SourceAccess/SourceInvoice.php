<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

use Arasya\Operations\Integration\SourceOrderPayloadMapper;

/**
 * Validates the shop's SmartBill invoice answers (YD SOFT 2.17.0 `InvoiceSummary`, `InvoiceActions`; API 2.43.0) and
 * copies ONLY the known fields. Anything malformed rejects the whole answer (SOURCE_RESPONSE_INVALID). The state is
 * relayed to the browser once and never stored, logged or cached by Operations (it holds the document number and the
 * SmartBill link); only its revision hash and the codes are kept in the audit tables.
 *
 * API 2.44.0 (YD SOFT 2.18.0): the operator's advance proforma (`proforma`: the advance, the proforma, its e-mail, whether
 * SmartBill invoiced it, the blockers of its actions). A shop that does not send it yet (2.17.0) answers `proforma: null`
 * here, and no proforma action is offered for it.
 *
 * API 2.45.0 (YD SOFT 2.19.0, owner master update): the customer's telephone acceptance of the total, the operator's
 * acceptance of a smaller verified advance, the proforma cancelled in SmartBill (and its unknown state), the one money
 * calculation (`money`), the invoicing deadline, the active AWB's collection and the e-Factura state (never tracked).
 * A 2.18.0 shop sends none of them: they read as absent, and its actions read `not_supported`.
 */
final class SourceInvoice
{
    use ValidatesSourceAnswer;

    public const ACTIONS = ['issue', 'send_mail', 'reissue', 'resolve'];
    public const PROFORMA_ACTIONS = ['proforma_issue', 'proforma_send_mail', 'proforma_check', 'proforma_resolve', 'proforma_cancel', 'total_accept', 'advance_accept', 'proforma_remind'];
    /** Why the operator cancels the advance proforma (2.19.0); `expired_unpaid` is the cycle worker's own (2.20.0). */
    public const CANCEL_REASONS = ['order_changed', 'customer_request', 'wrong_details', 'duplicate', 'other', 'expired_unpaid'];
    public const REASONS = ['order_changed', 'customer_data', 'wrong_document', 'other'];
    public const RESOLUTIONS = ['document' => ['not_issued', 'issued'], 'mail' => ['sent', 'not_sent']];
    /** A proforma's unknown outcome a manager may close (2.19.0 adds its cancellation in SmartBill). */
    public const PROFORMA_RESOLUTIONS = ['document' => ['not_issued', 'issued'], 'mail' => ['sent', 'not_sent'], 'cancel' => ['cancelled', 'not_cancelled']];
    /** Every blocker and refusal code the shop may name (anything else is SOURCE_ERROR). */
    public const CODES = [
        'invalid_request', 'invoice_disabled', 'invoice_observe', 'revision_changed', 'order_busy', 'idempotency_conflict', 'internal_error',
        'smartbill_missing', 'smartbill_version_unsupported', 'smartbill_not_connected', 'smartbill_series_missing', 'currency_mismatch',
        'operation_in_progress', 'operation_unknown', 'document_conflict', 'order_closed', 'document_missing', 'document_exists', 'mail_in_progress',
        'php_incompatible', 'billing_email_invalid', 'finance_unavailable', 'payment_not_cleared', 'zero_total_unsupported', 'finance_attention',
        'finance_pending', 'cashing_mismatch', 'nothing_to_resolve', 'resend_confirmation_required', 'reason_required', 'reissue_attestation_required',
        'smartbill_refused', 'smartbill_precheck', 'mail_refused',
        // R3 (2026-10-26): SmartBill answered 429 to one call: nothing was made or sent; the shop names the wait (retryAfter).
        'smartbill_rate_limited', 'mail_rate_limited',
        // 2.18.0: the final document must be an invoice; the advance proforma.
        'final_document_not_invoice', 'proforma_operation_unknown', 'proforma_invoiced', 'proforma_status_unknown', 'proforma_series_missing',
        'advance_not_required', 'advance_already_collected', 'proforma_exists', 'proforma_missing', 'proforma_outdated', 'replace_confirmation_required',
        // 2.18.0 (owner decision): the advance is paid by bank transfer to the shop's own account named on the proforma.
        'proforma_bank_missing', 'proforma_series_not_approved', 'payment_reference_invalid',
        // 2.19.0 (master update): telephone acceptance, smaller advance, cancellation in SmartBill, approved company/series.
        'total_not_accepted', 'total_already_accepted', 'declaration_required', 'commercial_record_damaged', 'financial_unknown',
        'advance_not_received', 'advance_already_accepted', 'payment_verifying', 'low_advance_confirmation_required', 'cancel_unknown',
        'advance_payment_received', 'invoice_exists', 'no_payment_confirmation_required', 'proforma_check_unknown', 'cancel_refused',
        'smartbill_company_not_approved', 'invoice_series_not_approved',
        // Owner business rules 2026-10-25: the explicit advance decision after a revision; the shipping against
        // WooCommerce's current configuration.
        'advance_decision_required', 'advance_decision_not_applicable', 'shipping_mismatch',
        // YD SOFT 2.20.0 (owner master decisions 2026-10-25): the reminders of the advance proforma.
        'proforma_not_sent', 'reminder_already_sent',
        // An action a shop of an older version does not know (never offered).
        'not_supported',
    ];
    /** The operator's decision when a revision leaves the verified money below the new advance (owner, 2026-10-25). */
    public const ADVANCE_DECISIONS = ['accept_received', 'request_difference'];
    /** The shipping against WooCommerce's current configuration (YD SOFT ShippingCheck). */
    public const SHIPPING_STATES = ['none', 'match', 'mismatch', 'unverifiable'];
    public const SHIPPING_CODES = ['shipping_cost_mismatch', 'free_shipping_threshold_lost', 'shipping_rate_unavailable', 'shipping_method_unsupported', 'free_shipping_unverifiable', 'shipping_line_not_found', 'shipping_check_failed'];
    /** Where the advance stands, from the shop's money evidence only (never from the proforma). */
    public const ADVANCE_STATES = ['waiting', 'partial', 'paid'];
    public const IBAN_PATTERN = '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D';
    public const PAYMENT_REFERENCE_PATTERN = '/^[A-Z]{2,4}[A-Z0-9]{1,20}$/D';
    public const OPERATION_STATES = ['none', 'in_flight', 'issued', 'unknown'];
    public const MAIL_STATES = ['none', 'smartbill', 'in_flight', 'sent', 'unknown', 'failed'];
    public const RESULTS = ['issued', 'unknown', 'in_progress', 'sent', 'not_issued', 'not_sent', 'invoiced', 'not_invoiced', 'cancelled', 'not_cancelled', 'accepted', 'cancel_unknown', 'reminded'];
    public const DEADLINE_LEVELS = ['none', 'notice', 'warning', 'critical', 'overdue'];
    public const COD_STATES = ['none', 'match', 'mismatch', 'delivered', 'unknown'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    private const BLOCKER_GROUPS = ['issue', 'sendMail', 'reissue', 'resolve'];
    private const HISTORY_KINDS = ['issue', 'reissue', 'superseded', 'send_mail', 'auto_mail', 'resolve_document', 'resolve_mail'];
    private const PROFORMA_GROUPS = ['proformaIssue', 'proformaSendMail', 'proformaCheck', 'proformaResolve'];
    /** 2.19.0 groups: a 2.18.0 shop does not send them (`not_supported`). */
    private const PROFORMA_NEW_GROUPS = ['proformaCancel', 'totalAccept', 'advanceAccept'];
    private const PROFORMA_HISTORY = ['proforma_issue', 'superseded', 'proforma_mail', 'check', 'proforma_resolve_document', 'proforma_resolve_mail', 'proforma_cancel', 'proforma_resolve_cancel', 'proforma_remind'];
    private const CHANGE_KINDS = ['added', 'removed', 'changed', 'shipping'];

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
        $smartbill = self::map($data['smartbill'] ?? null);
        $financial = self::map($data['financial'] ?? null);
        $document = self::map($data['document'] ?? null);
        $operation = self::map($data['operation'] ?? null);
        $mail = self::map($data['mail'] ?? null);
        $blockers = self::map($data['blockers'] ?? null);
        $state = self::choice($financial['state'] ?? null, SourceOrderPayloadMapper::FINANCIAL_STATES);
        $exists = self::bool($document['exists'] ?? null);
        $link = $document['link'] ?? null;
        return [
            'readAt' => self::instant($data['readAt'] ?? null),
            'revision' => self::pattern($data['revision'] ?? null, self::FINGERPRINT),
            'mode' => self::choice($data['mode'] ?? null, ['observe', 'enforce']),
            'smartbill' => [
                'available' => self::bool($smartbill['available'] ?? null),
                'reason' => ($smartbill['reason'] ?? null) === null ? null : self::choice($smartbill['reason'], self::CODES),
                'documentType' => self::choice($smartbill['documentType'] ?? null, ['invoice', 'proforma']),
                'autoEmail' => self::bool($smartbill['autoEmail'] ?? null),
                'cashing' => self::bool($smartbill['cashing'] ?? null),
                'draft' => self::bool($smartbill['draft'] ?? null),
                'mailSupported' => self::bool($smartbill['mailSupported'] ?? null),
                'autoIssue' => self::bool($smartbill['autoIssue'] ?? null),
            ],
            'order' => [
                'id' => $sourceOrderId,
                'number' => $orderNumber,
                'status' => self::text($order['status'] ?? null, 40),
                'total' => self::money($order['total'] ?? null),
                'currency' => self::pattern($order['currency'] ?? null, '/^[A-Z]{3}$/D'),
                'billingEmail' => self::bool($order['billingEmail'] ?? null),
            ],
            'financial' => [
                'state' => $state,
                'basis' => ($financial['basis'] ?? null) === null ? null : self::choice($financial['basis'], SourceOrderPayloadMapper::FINANCIAL_BASES),
                'attention' => ($financial['attention'] ?? null) === null ? null : self::choice($financial['attention'], [...SourceOrderPayloadMapper::FINANCIAL_ATTENTION_OPEN, ...SourceOrderPayloadMapper::FINANCIAL_ATTENTION_CLEARED]),
                'pending' => self::bool($financial['pending'] ?? null),
            ],
            'document' => [
                'exists' => $exists,
                'type' => ($document['type'] ?? null) === null ? null : self::choice($document['type'], ['invoice', 'proforma']),
                'series' => self::optionalText($document['series'] ?? null, 40),
                'number' => self::optionalText($document['number'] ?? null, 40),
                'status' => ($document['status'] ?? null) === null ? null : self::choice($document['status'], ['final', 'draft']),
                'conflict' => self::bool($document['conflict'] ?? null),
                'through' => ($document['through'] ?? null) === null ? null : self::choice($document['through'], ['arasya', 'shop']),
                'issuedAt' => self::optionalInstant($document['issuedAt'] ?? null),
                'issuedBy' => ($document['issuedBy'] ?? null) === null ? null : self::pattern($document['issuedBy'], self::UUID),
                'total' => ($document['total'] ?? null) === null ? null : self::money($document['total']),
                'currency' => ($document['currency'] ?? null) === null ? null : self::pattern($document['currency'], '/^[A-Z]{3}$/D'),
                'outdated' => ($document['outdated'] ?? null) === null ? null : self::bool($document['outdated']),
                'link' => $link === null ? null : self::link($link),
            ],
            'operation' => [
                'state' => self::choice($operation['state'] ?? null, self::OPERATION_STATES),
                'kind' => ($operation['kind'] ?? null) === null ? null : self::choice($operation['kind'], ['issue', 'reissue']),
                'startedAt' => self::optionalInstant($operation['startedAt'] ?? null),
                'by' => ($operation['by'] ?? null) === null ? null : self::pattern($operation['by'], self::UUID),
                'error' => ($operation['error'] ?? null) === null ? null : self::pattern($operation['error'], '/^[a-z_]{2,40}$/D'),
            ],
            'mail' => [
                'state' => self::choice($mail['state'] ?? null, self::MAIL_STATES),
                'at' => self::optionalInstant($mail['at'] ?? null),
                'by' => ($mail['by'] ?? null) === null ? null : self::pattern($mail['by'], self::UUID),
                'count' => self::count($mail['count'] ?? null),
            ],
            'history' => self::list($data['history'] ?? null, 50, static function (array $entry): array {
                $reason = $entry['reason'] ?? null;
                if ($reason !== null) {
                    $reason = self::map($reason);
                    $reason = [
                        'code' => self::choice($reason['code'] ?? null, [...self::REASONS, 'resolution']),
                        'note' => self::text($reason['note'] ?? null, 300, true),
                    ];
                }
                return [
                    'kind' => self::choice($entry['kind'] ?? null, self::HISTORY_KINDS),
                    'at' => self::optionalInstant($entry['at'] ?? null),
                    'by' => ($entry['by'] ?? null) === null ? null : self::pattern($entry['by'], self::UUID),
                    'outcome' => self::pattern($entry['outcome'] ?? null, '/^[a-z_]{2,20}$/D'),
                    'reason' => $reason,
                    'series' => self::optionalText($entry['series'] ?? null, 40),
                    'number' => self::optionalText($entry['number'] ?? null, 40),
                ];
            }),
            'blockers' => self::groups($blockers, self::BLOCKER_GROUPS),
            'proforma' => ($data['proforma'] ?? null) === null ? null : self::proforma(self::map($data['proforma'])),
            // 2.19.0: ANAF e-Factura is never tracked by the shop; only that statement is accepted.
            'eFactura' => array_key_exists('eFactura', $data) ? ['state' => self::choice(self::map($data['eFactura'])['state'] ?? null, ['not_tracked'])] : null,
        ];
    }

    /**
     * @param array<string, mixed> $proforma
     * @return array<string, mixed>
     */
    private static function proforma(array $proforma): array
    {
        $advance = self::map($proforma['advance'] ?? null);
        $document = $proforma['document'] ?? null;
        $mail = self::map($proforma['mail'] ?? null);
        $invoiced = $proforma['invoiced'] ?? null;
        $percentage = $advance['percentage'] ?? null;
        if ($percentage !== null && ((!is_int($percentage) && !is_float($percentage)) || $percentage < 0 || $percentage > 100)) {
            throw self::invalid();
        }
        if ($document !== null) {
            $document = self::map($document);
            $document = [
                'series' => self::optionalText($document['series'] ?? null, 40),
                'number' => self::optionalText($document['number'] ?? null, 40),
                'amount' => ($document['amount'] ?? null) === null ? null : self::money($document['amount']),
                'currency' => ($document['currency'] ?? null) === null ? null : self::pattern($document['currency'], '/^[A-Z]{3}$/D'),
                'reference' => ($document['reference'] ?? null) === null ? null : self::pattern($document['reference'], self::PAYMENT_REFERENCE_PATTERN),
                'issuedAt' => self::optionalInstant($document['issuedAt'] ?? null),
                'issuedBy' => ($document['issuedBy'] ?? null) === null ? null : self::pattern($document['issuedBy'], self::UUID),
                'outdated' => self::bool($document['outdated'] ?? null),
                // YD SOFT 2.20.0: issued after three unanswered calls, before any telephone confirmation.
                'unconfirmed' => array_key_exists('unconfirmed', $document) ? self::bool($document['unconfirmed']) : false,
                'link' => ($document['link'] ?? null) === null ? null : self::link($document['link']),
            ];
        }
        $transfer = $proforma['transfer'] ?? null;
        if ($transfer !== null) {
            $transfer = self::map($transfer);
            $transfer = [
                'iban' => self::pattern($transfer['iban'] ?? null, self::IBAN_PATTERN),
                'bank' => self::text($transfer['bank'] ?? null, 100),
                'beneficiary' => self::text($transfer['beneficiary'] ?? null, 100),
                'reference' => ($transfer['reference'] ?? null) === null ? null : self::pattern($transfer['reference'], self::PAYMENT_REFERENCE_PATTERN),
                'bic' => ($transfer['bic'] ?? null) === null ? null : self::pattern($transfer['bic'], '/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/D'),
            ];
        }
        if ($invoiced !== null) {
            $invoiced = self::map($invoiced);
            $invoiced = [
                'invoiced' => ($invoiced['invoiced'] ?? null) === null ? null : self::bool($invoiced['invoiced']),
                'at' => self::optionalInstant($invoiced['at'] ?? null),
            ];
        }
        return [
            'advance' => [
                'required' => ($advance['required'] ?? null) === null ? null : self::money($advance['required']),
                'received' => ($advance['received'] ?? null) === null ? null : self::money($advance['received']),
                'outstanding' => ($advance['outstanding'] ?? null) === null ? null : self::money($advance['outstanding']),
                'percentage' => $percentage === null ? null : (float) $percentage,
                'cod' => ($advance['cod'] ?? null) === null ? null : self::bool($advance['cod']),
                // A 2.18.0 shop before the bank transfer decision sends neither: no state, nothing in verification.
                'state' => ($advance['state'] ?? null) === null ? null : self::choice($advance['state'], self::ADVANCE_STATES),
                'verifying' => array_key_exists('verifying', $advance) ? self::bool($advance['verifying']) : false,
                // 2.19.0: the operator accepted the smaller verified advance (the state stays `partial`).
                'accepted' => array_key_exists('accepted', $advance) ? self::bool($advance['accepted']) : false,
            ],
            'transfer' => $transfer,
            'state' => self::choice($proforma['state'] ?? null, self::OPERATION_STATES),
            'document' => $document,
            'mail' => [
                'state' => self::choice($mail['state'] ?? null, self::MAIL_STATES),
                'at' => self::optionalInstant($mail['at'] ?? null),
                'by' => ($mail['by'] ?? null) === null ? null : self::pattern($mail['by'], self::UUID),
                'count' => self::count($mail['count'] ?? null),
            ],
            'invoiced' => $invoiced,
            'error' => ($proforma['error'] ?? null) === null ? null : self::pattern($proforma['error'], '/^[a-z_]{2,40}$/D'),
            'history' => self::list($proforma['history'] ?? null, 50, static fn (array $entry): array => [
                'kind' => self::choice($entry['kind'] ?? null, self::PROFORMA_HISTORY),
                'at' => self::optionalInstant($entry['at'] ?? null),
                'by' => ($entry['by'] ?? null) === null ? null : self::pattern($entry['by'], self::UUID),
                'outcome' => self::pattern($entry['outcome'] ?? null, '/^[a-z_]{2,20}$/D'),
                'series' => self::optionalText($entry['series'] ?? null, 40),
                'number' => self::optionalText($entry['number'] ?? null, 40),
                'reason' => ($entry['reason'] ?? null) === null ? null : self::choice($entry['reason'], self::CANCEL_REASONS),
            ]),
            'blockers' => self::groups(self::map($proforma['blockers'] ?? null), self::PROFORMA_GROUPS) + self::newGroups(self::map($proforma['blockers'] ?? null)),
            // 2.19.0 (absent from a 2.18.0 shop).
            'money' => ($proforma['money'] ?? null) === null ? null : self::moneyPosition(self::map($proforma['money'])),
            'totalAcceptance' => ($proforma['totalAcceptance'] ?? null) === null ? null : self::totalAcceptance(self::map($proforma['totalAcceptance'])),
            'advanceAcceptance' => ($proforma['advanceAcceptance'] ?? null) === null ? null : self::advanceAcceptance(self::map($proforma['advanceAcceptance'])),
            'lowAdvance' => array_key_exists('lowAdvance', $proforma) ? self::bool($proforma['lowAdvance']) : false,
            'cancel' => ($proforma['cancel'] ?? null) === null ? null : self::cancelState(self::map($proforma['cancel'])),
            'cancelled' => self::list($proforma['cancelled'] ?? [], 50, static fn (array $entry): array => [
                'series' => self::optionalText($entry['series'] ?? null, 40),
                'number' => self::optionalText($entry['number'] ?? null, 40),
                'amount' => ($entry['amount'] ?? null) === null ? null : self::money($entry['amount']),
                'currency' => ($entry['currency'] ?? null) === null ? null : self::pattern($entry['currency'], '/^[A-Z]{3}$/D'),
                'cancelledAt' => self::optionalInstant($entry['cancelledAt'] ?? null),
                'cancelledBy' => ($entry['cancelledBy'] ?? null) === null ? null : self::pattern($entry['cancelledBy'], self::UUID),
                'reason' => ($entry['reason'] ?? null) === null ? null : self::choice($entry['reason'], self::CANCEL_REASONS),
            ]),
            'superseded' => self::list($proforma['superseded'] ?? [], 50, static fn (array $entry): array => [
                'series' => self::optionalText($entry['series'] ?? null, 40),
                'number' => self::optionalText($entry['number'] ?? null, 40),
                'amount' => ($entry['amount'] ?? null) === null ? null : self::money($entry['amount']),
                'supersededAt' => self::optionalInstant($entry['supersededAt'] ?? null),
            ]),
            'deadline' => ($proforma['deadline'] ?? null) === null ? null : self::deadline(self::map($proforma['deadline'])),
            'cod' => ($proforma['cod'] ?? null) === null ? null : self::cod(self::map($proforma['cod'])),
            // Owner business rule 2026-10-25 (absent from a shop before it).
            'shipping' => ($proforma['shipping'] ?? null) === null ? null : self::shipping(self::map($proforma['shipping'])),
        ];
    }

    /**
     * The order's shipping against WooCommerce's current configuration. A mismatch names its code; amounts only for the
     * roles that see them (the shop sends null otherwise).
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public static function shipping(array $value): array
    {
        $shipping = [
            'state' => self::choice($value['state'] ?? null, self::SHIPPING_STATES),
            'code' => ($value['code'] ?? null) === null ? null : self::choice($value['code'], self::SHIPPING_CODES),
            'current' => ($value['current'] ?? null) === null ? null : self::money($value['current']),
            'expected' => ($value['expected'] ?? null) === null ? null : self::money($value['expected']),
        ];
        if (in_array($shipping['state'], ['mismatch', 'unverifiable'], true) !== ($shipping['code'] !== null)) {
            throw self::invalid();
        }
        return $shipping;
    }

    /**
     * The 2.19.0 action groups; a shop that does not send them cannot run those actions.
     *
     * @param array<string, mixed> $blockers
     * @return array<string, list<string>>
     */
    private static function newGroups(array $blockers): array
    {
        $groups = [];
        foreach (self::PROFORMA_NEW_GROUPS as $group) {
            $groups[$group] = array_key_exists($group, $blockers) ? self::groups($blockers, [$group])[$group] : ['not_supported'];
        }
        return $groups;
    }

    /**
     * The shop's one money calculation (YD SOFT MoneyPosition): T, A, P, I, N, K, F, G in the order currency. The answer is
     * refused when its own numbers do not add up (N = P - I, K = MAX(0, T - N), F = MAX(0, N - T), G = MAX(0, A - N)).
     *
     * @param array<string, mixed> $money
     * @return array<string, mixed>
     */
    public static function moneyPosition(array $money): array
    {
        $known = self::bool($money['known'] ?? null);
        $percentage = $money['percentage'] ?? null;
        if ($percentage !== null && ((!is_int($percentage) && !is_float($percentage)) || $percentage < 0 || $percentage > 100)) {
            throw self::invalid();
        }
        $amount = static fn (string $key): ?string => ($money[$key] ?? null) === null ? null : self::money($money[$key]);
        $position = [
            'currency' => self::pattern($money['currency'] ?? null, '/^[A-Z]{3}$/D'),
            'percentage' => $percentage === null ? null : (float) $percentage,
            'known' => $known,
        ];
        foreach (['total', 'advanceRequired', 'received', 'refunded', 'net', 'remaining', 'excess', 'advanceGap'] as $key) {
            $position[$key] = $amount($key);
        }
        if ($known) {
            $c = static fn (?string $value): ?int => $value === null ? null : (int) round(((float) $value) * 100);
            [$t, $a, $p, $i, $n, $k, $f, $g] = array_map($c, [$position['total'], $position['advanceRequired'], $position['received'], $position['refunded'], $position['net'], $position['remaining'], $position['excess'], $position['advanceGap']]);
            if ($t === null || $p === null || $i === null || $n === null || $k === null || $f === null || $t < 0 || $p < 0 || $i < 0 || $k < 0 || $f < 0
                || $n !== $p - $i || $k !== max(0, $t - $n) || $f !== max(0, $n - $t) || ($a !== null && ($g === null || $g !== max(0, $a - $n)))) {
                throw self::invalid();
            }
        }
        return $position;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function totalAcceptance(array $value): array
    {
        return [
            'state' => self::choice($value['state'] ?? null, ['accepted', 'required']),
            'at' => self::optionalInstant($value['at'] ?? null),
            'by' => ($value['by'] ?? null) === null ? null : self::pattern($value['by'], self::UUID),
            'previousTotal' => ($value['previousTotal'] ?? null) === null ? null : self::money($value['previousTotal']),
            'total' => ($value['total'] ?? null) === null ? null : self::money($value['total']),
            'changes' => self::list($value['changes'] ?? [], 40, static fn (array $change): array => [
                'kind' => self::choice($change['kind'] ?? null, self::CHANGE_KINDS),
                'name' => self::text($change['name'] ?? null, 120, true),
            ]),
            'note' => ($value['note'] ?? null) === null ? null : self::text($value['note'], 300),
            // Owner business rule 2026-10-25: whether accepting this total needs the operator's explicit advance decision
            // (verified money above zero but below the standard advance of the new total).
            'advanceDecisionRequired' => ($value['advanceDecisionRequired'] ?? null) === null ? null : self::bool($value['advanceDecisionRequired']),
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function advanceAcceptance(array $value): array
    {
        return [
            'at' => self::optionalInstant($value['at'] ?? null),
            'by' => ($value['by'] ?? null) === null ? null : self::pattern($value['by'], self::UUID),
            'required' => ($value['required'] ?? null) === null ? null : self::money($value['required']),
            'received' => ($value['received'] ?? null) === null ? null : self::money($value['received']),
            'reason' => ($value['reason'] ?? null) === null ? null : self::text($value['reason'], 300),
            'low' => self::bool($value['low'] ?? null),
            // kept: the money paid before a revision, explicitly accepted with the new total; accepted: a later acceptance.
            'kind' => ($value['kind'] ?? null) === null ? 'accepted' : self::choice($value['kind'], ['accepted', 'kept']),
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function cancelState(array $value): array
    {
        return [
            'state' => self::choice($value['state'] ?? null, ['in_flight', 'unknown']),
            'at' => self::optionalInstant($value['at'] ?? null),
            'by' => ($value['by'] ?? null) === null ? null : self::pattern($value['by'], self::UUID),
            'reason' => ($value['reason'] ?? null) === null ? null : self::choice($value['reason'], self::CANCEL_REASONS),
        ];
    }

    /**
     * The invoicing deadline of money received (Romanian days).
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public static function deadline(array $value): array
    {
        $state = self::choice($value['state'] ?? null, ['none', 'open', 'invoiced']);
        $days = $value['daysLeft'] ?? null;
        if ($days !== null && (!is_int($days) || $days < -3660 || $days > 3660)) {
            throw self::invalid();
        }
        $date = static fn (mixed $v): ?string => $v === null ? null : self::pattern($v, '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D');
        $deadline = [
            'state' => $state,
            'firstCollection' => $date($value['firstCollection'] ?? null),
            'deadline' => $date($value['deadline'] ?? null),
            'daysLeft' => $days,
            'level' => ($value['level'] ?? null) === null ? null : self::choice($value['level'], self::DEADLINE_LEVELS),
        ];
        if (($state === 'none') !== ($deadline['deadline'] === null) || ($state === 'open' && ($days === null || $deadline['level'] === null))) {
            throw self::invalid();
        }
        return $deadline;
    }

    /**
     * The active AWB's collection against what is owed.
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public static function cod(array $value): array
    {
        return [
            'state' => self::choice($value['state'] ?? null, self::COD_STATES),
            'awb' => ($value['awb'] ?? null) === null ? null : self::pattern($value['awb'], '/^[A-Za-z0-9-]{1,100}$/D'),
            'awbCod' => ($value['awbCod'] ?? null) === null ? null : self::money($value['awbCod']),
            'expected' => ($value['expected'] ?? null) === null ? null : self::money($value['expected']),
            'status' => ($value['status'] ?? null) === null ? null : self::text($value['status'], 255),
        ];
    }

    /**
     * @param array<string, mixed> $blockers
     * @param list<string> $groups
     * @return array<string, list<string>>
     */
    private static function groups(array $blockers, array $groups): array
    {
        return array_combine($groups, array_map(static function (string $group) use ($blockers): array {
            $codes = $blockers[$group] ?? null;
            if (!is_array($codes) || !array_is_list($codes) || count($codes) > 40) {
                throw self::invalid();
            }
            return array_map(static fn (mixed $code): string => self::choice($code, self::CODES), $codes);
        }, $groups));
    }

    /**
     * The shop's answer to an invoice action that ran: 200 (issued, sent, resolved) or 202 (the outcome is unknown).
     *
     * @param array<string, mixed> $data
     * @return array{applied: bool, result: string, summary: array<string, mixed>}
     */
    public static function answered(array $data, string $action, string $sourceOrderId, string $orderNumber): array
    {
        if (($data['protocol'] ?? null) !== 1 || !is_bool($data['applied'] ?? null) || ($data['action'] ?? null) !== $action) {
            throw self::invalid();
        }
        return [
            'applied' => $data['applied'],
            'result' => self::choice($data['result'] ?? null, self::RESULTS),
            'summary' => self::summary(self::map($data['summary'] ?? null), $sourceOrderId, $orderNumber),
        ];
    }

    /**
     * A signed refusal: its code (unknown codes are SOURCE_ERROR), the blockers and the current revision when sent.
     *
     * @param array<string, mixed> $data
     * @return array{code: string, blockers: list<string>, revision: ?string}
     */
    public static function refusal(array $data): array
    {
        $error = self::map($data['error'] ?? null);
        $code = $error['code'] ?? null;
        if (!is_string($code) || !in_array($code, self::CODES, true)) {
            throw new SourceAccessException('SOURCE_ERROR');
        }
        $blockers = $data['blockers'] ?? [];
        if (!is_array($blockers) || !array_is_list($blockers) || count($blockers) > 40) {
            throw self::invalid();
        }
        return [
            'code' => $code,
            'blockers' => array_map(static fn (mixed $blocker): string => self::choice($blocker, self::CODES), $blockers),
            'revision' => isset($data['revision']) ? self::pattern($data['revision'], self::FINGERPRINT) : null,
            // R3 (2026-10-26): seconds SmartBill asked to wait (Retry-After), only with a rate-limit refusal.
            'retryAfter' => is_int($data['retryAfter'] ?? null) && $data['retryAfter'] >= 1 && $data['retryAfter'] <= 3600 ? $data['retryAfter'] : null,
        ];
    }

    /**
     * The shop's stored answer of an Idempotency-Key (reconciliation).
     *
     * @param array<string, mixed> $data
     * @return array{state: string, status?: int, result?: array<string, mixed>}
     */
    public static function status(array $data): array
    {
        $state = self::choice($data['state'] ?? null, ['absent', 'applied', 'rejected', 'unknown']);
        if (!isset($data['status'])) {
            return ['state' => $state];
        }
        $status = $data['status'];
        if (!is_int($status) || $status < 200 || $status > 599) {
            throw self::invalid();
        }
        return ['state' => $state, 'status' => $status, 'result' => self::map($data['result'] ?? null)];
    }

    /** States of the SmartBill payment reconciliation (YD SOFT 2.21.0, PaymentReconciliation). */
    public const PAYMENT_STATES = ['no_invoice', 'nothing_to_associate', 'matched', 'settled', 'association_pending', 'paid_beyond_verified', 'invoice_total_differs', 'smartbill_inconsistent', 'unknown'];
    private const PAYMENT_SEVERITY = ['no_invoice' => 'ok', 'nothing_to_associate' => 'ok', 'matched' => 'ok', 'settled' => 'ok', 'association_pending' => 'pending',
        'paid_beyond_verified' => 'attention', 'invoice_total_differs' => 'attention', 'smartbill_inconsistent' => 'attention', 'unknown' => 'unknown'];
    private const AMOUNT = '/^-?[0-9]{1,10}\.[0-9]{2}$/D';

    /**
     * The shop's read-only SmartBill payment reconciliation of the final invoice (API 2.47.0): SmartBill's total, paid and
     * unpaid amounts next to Arasya's verified money and what the customer still owes. Amounts pass through to the
     * employee; Operations stores none of them.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function payment(array $data): array
    {
        $state = self::choice($data['state'] ?? null, self::PAYMENT_STATES);
        if (($data['severity'] ?? null) !== self::PAYMENT_SEVERITY[$state]) {
            throw self::invalid();
        }
        $code = $data['code'] ?? null;
        if ($code !== null && (!is_string($code) || preg_match('/^[a-z_]{1,60}$/D', $code) !== 1)) {
            throw self::invalid();
        }
        $amounts = static function (mixed $value, array $keys): ?array {
            if ($value === null) {
                return null;
            }
            if (!is_array($value)) {
                throw self::invalid();
            }
            $out = [];
            foreach ($keys as $key) {
                $amount = $value[$key] ?? null;
                if (!is_string($amount) || preg_match(self::AMOUNT, $amount) !== 1) {
                    throw self::invalid();
                }
                $out[$key] = $amount;
            }
            return $out;
        };
        $invoice = $data['invoice'] ?? null;
        if ($invoice !== null && (!is_array($invoice) || !self::documentField($invoice['series'] ?? null) || !self::documentField($invoice['number'] ?? null))) {
            throw self::invalid();
        }
        $smartbill = $amounts($data['smartbill'] ?? null, ['total', 'paid', 'unpaid']);
        if ($smartbill === null && in_array($state, ['matched', 'settled', 'association_pending', 'paid_beyond_verified', 'invoice_total_differs', 'smartbill_inconsistent', 'nothing_to_associate'], true)) {
            throw self::invalid();
        }
        $currency = $data['currency'] ?? null;
        $checkedAt = $data['checkedAt'] ?? null;
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || !is_string($checkedAt) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $checkedAt) !== 1) {
            throw self::invalid();
        }
        $retry = $data['retryAfter'] ?? null;
        return [
            'state' => $state,
            'severity' => self::PAYMENT_SEVERITY[$state],
            'code' => $code,
            'currency' => $currency,
            'checkedAt' => $checkedAt,
            'invoice' => $invoice === null ? null : ['series' => $invoice['series'], 'number' => $invoice['number']],
            'smartbill' => $smartbill,
            'arasya' => $amounts($data['arasya'] ?? null, ['total', 'verified', 'remaining', 'excess']),
            'retryAfter' => is_int($retry) && $retry >= 1 && $retry <= 3600 ? $retry : null,
        ];
    }

    private static function documentField(mixed $value): bool
    {
        return $value === null || (is_string($value) && preg_match('/^[A-Za-z0-9-]{1,20}$/D', $value) === 1);
    }

    /** A SmartBill Cloud view link: HTTPS on a smartbill.ro host only, without credentials or a port. */
    private static function link(mixed $value): string
    {
        $url = self::text($value, 1000);
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ($host !== 'smartbill.ro' && !str_ends_with($host, '.smartbill.ro'))) {
            throw self::invalid();
        }
        return $url;
    }

    private static function optionalText(mixed $value, int $max): ?string
    {
        return $value === null ? null : self::text($value, $max);
    }

    private static function count(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > 1000) {
            throw self::invalid();
        }
        return $value;
    }
}
