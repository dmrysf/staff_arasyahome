<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

use Arasya\Operations\Integration\SourceOrderPayloadMapper;

/**
 * Validates the shop's SmartBill invoice answers (YD SOFT 2.17.0 `InvoiceSummary`, `InvoiceActions`; API 2.43.0) and
 * copies ONLY the known fields. Anything malformed rejects the whole answer (SOURCE_RESPONSE_INVALID). The state is
 * relayed to the browser once and never stored, logged or cached by Operations (it holds the document number and the
 * SmartBill link); only its revision hash and the codes are kept in the audit tables.
 */
final class SourceInvoice
{
    use ValidatesSourceAnswer;

    public const ACTIONS = ['issue', 'send_mail', 'reissue', 'resolve'];
    public const REASONS = ['order_changed', 'customer_data', 'wrong_document', 'other'];
    public const RESOLUTIONS = ['document' => ['not_issued', 'issued'], 'mail' => ['sent', 'not_sent']];
    /** Every blocker and refusal code the shop may name (anything else is SOURCE_ERROR). */
    public const CODES = [
        'invalid_request', 'invoice_disabled', 'invoice_observe', 'revision_changed', 'order_busy', 'idempotency_conflict', 'internal_error',
        'smartbill_missing', 'smartbill_version_unsupported', 'smartbill_not_connected', 'smartbill_series_missing', 'currency_mismatch',
        'operation_in_progress', 'operation_unknown', 'document_conflict', 'order_closed', 'document_missing', 'document_exists', 'mail_in_progress',
        'php_incompatible', 'billing_email_invalid', 'finance_unavailable', 'payment_not_cleared', 'zero_total_unsupported', 'finance_attention',
        'finance_pending', 'cashing_mismatch', 'nothing_to_resolve', 'resend_confirmation_required', 'reason_required', 'reissue_attestation_required',
        'smartbill_refused', 'smartbill_precheck', 'mail_refused',
    ];
    public const OPERATION_STATES = ['none', 'in_flight', 'issued', 'unknown'];
    public const MAIL_STATES = ['none', 'smartbill', 'in_flight', 'sent', 'unknown', 'failed'];
    public const RESULTS = ['issued', 'unknown', 'in_progress', 'sent', 'not_issued', 'not_sent'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    private const BLOCKER_GROUPS = ['issue', 'sendMail', 'reissue', 'resolve'];
    private const HISTORY_KINDS = ['issue', 'reissue', 'superseded', 'send_mail', 'auto_mail', 'resolve_document', 'resolve_mail'];

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
            'blockers' => array_combine(self::BLOCKER_GROUPS, array_map(static function (string $group) use ($blockers): array {
                $codes = $blockers[$group] ?? null;
                if (!is_array($codes) || !array_is_list($codes) || count($codes) > 40) {
                    throw self::invalid();
                }
                return array_map(static fn (mixed $code): string => self::choice($code, self::CODES), $codes);
            }, self::BLOCKER_GROUPS)),
        ];
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
