<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

use Arasya\Operations\Support\Clock;
use JsonException;

/**
 * Signs one source access request, sends it through the transport and accepts only an answer the shop signed for this
 * very request (its nonce). `call` returns the decoded JSON of a 200 answer; anything else is a SourceAccessException
 * with a fixed code. `exchange` (Phase 4B edits) also returns the shop's signed refusals, which carry what the operator
 * must see (the blockers, the new order version). Nothing here logs or stores a request or response body.
 */
final readonly class SourceAccessClient
{
    /** Routes Operations may call (WordPress route, without `/wp-json`). Nothing else exists. */
    public const ROUTE_DETAIL = '/yd-arasya/v1/confirmare/detail';
    public const ROUTE_CATALOG = '/yd-arasya/v1/confirmare/catalog';
    public const ROUTE_PRODUCT = '/yd-arasya/v1/confirmare/product';
    public const ROUTE_PREVIEW = '/yd-arasya/v1/confirmare/preview';
    public const ROUTE_APPLY = '/yd-arasya/v1/confirmare/apply';
    public const ROUTE_EDIT_STATUS = '/yd-arasya/v1/confirmare/edit-status';
    /** Confirmare finance (API 2.41.0, Phase 5): the financial summary, a finance action and its stored answer. */
    public const ROUTE_FINANCE = '/yd-arasya/v1/confirmare/finance';
    public const ROUTE_FINANCE_ACTION = '/yd-arasya/v1/confirmare/finance-action';
    public const ROUTE_FINANCE_STATUS = '/yd-arasya/v1/confirmare/finance-status';
    /** SmartBill invoicing (API 2.43.0): the invoice state, an invoice action and its stored answer. */
    public const ROUTE_INVOICE = '/yd-arasya/v1/confirmare/invoice';
    public const ROUTE_INVOICE_ACTION = '/yd-arasya/v1/confirmare/invoice-action';
    public const ROUTE_INVOICE_STATUS = '/yd-arasya/v1/confirmare/invoice-status';
    /** The operator's advance proforma (API 2.44.0, YD SOFT 2.18.0). */
    public const ROUTE_PROFORMA_ACTION = '/yd-arasya/v1/confirmare/proforma-action';
    /** YD SOFT 2.18.0: the shop's bank transfers that match no order (no order fields; finance only). */
    public const ROUTE_TRANSFER_EXCEPTIONS = '/yd-arasya/v1/confirmare/transfer-exceptions';
    public const ROUTE_TRANSFER_EXCEPTION_ACTION = '/yd-arasya/v1/confirmare/transfer-exception-action';
    public const ROUTES = [self::ROUTE_DETAIL, self::ROUTE_CATALOG, self::ROUTE_PRODUCT, self::ROUTE_PREVIEW, self::ROUTE_APPLY, self::ROUTE_EDIT_STATUS, self::ROUTE_FINANCE, self::ROUTE_FINANCE_ACTION, self::ROUTE_FINANCE_STATUS,
        self::ROUTE_INVOICE, self::ROUTE_INVOICE_ACTION, self::ROUTE_INVOICE_STATUS, self::ROUTE_PROFORMA_ACTION, self::ROUTE_TRANSFER_EXCEPTIONS, self::ROUTE_TRANSFER_EXCEPTION_ACTION];

    /** Shop error codes Operations understands (anything else is SOURCE_ERROR). */
    private const SOURCE_ERRORS = [
        'access_denied' => 'SOURCE_ACCESS_DENIED',
        'rate_limited' => 'SOURCE_RATE_LIMITED',
        'access_disabled' => 'SOURCE_DISABLED',
        'not_ready' => 'SOURCE_DISABLED',
        'audit_unavailable' => 'SOURCE_DISABLED',
        'order_not_found' => 'SOURCE_ORDER_NOT_FOUND',
        'order_not_eligible' => 'SOURCE_ORDER_NOT_ELIGIBLE',
        'invalid_request' => 'SOURCE_ERROR',
        'internal_error' => 'SOURCE_ERROR',
    ];

    public function __construct(private SourceAccessTransport $transport, private Clock $clock)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function call(SourceAccessTarget $target, string $route, array $payload): array
    {
        ['status' => $status, 'body' => $decoded] = $this->exchange($target, $route, $payload);
        if ($status !== 200) {
            $code = $decoded['error']['code'] ?? null;
            $known = is_string($code) && isset(self::SOURCE_ERRORS[$code]) ? $code : null;
            throw new SourceAccessException($known === null ? 'SOURCE_ERROR' : self::SOURCE_ERRORS[$known], $known);
        }
        return $decoded;
    }

    /**
     * Any answer the shop signed for this request, decoded. Transport failures and answers that are not signed for this
     * request or not JSON are SourceAccessExceptions (nothing is known about what the shop did).
     *
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function exchange(SourceAccessTarget $target, string $route, array $payload): array
    {
        if (!in_array($route, self::ROUTES, true)) {
            throw new SourceAccessException('SOURCE_ACCESS_NOT_CONFIGURED');
        }
        try {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SourceAccessException('SOURCE_ACCESS_NOT_CONFIGURED');
        }
        $timestamp = $this->clock->now()->getTimestamp();
        $nonce = bin2hex(random_bytes(16));
        $headers = [
            SourceAccessProtocol::HEADER_KEY => $target->kid,
            SourceAccessProtocol::HEADER_TIMESTAMP => (string) $timestamp,
            SourceAccessProtocol::HEADER_NONCE => $nonce,
            SourceAccessProtocol::HEADER_SIGNATURE => SourceAccessProtocol::sign($target->key(), SourceAccessProtocol::requestString($route, $target->sourceKey, $target->kid, $timestamp, $nonce, $body)),
        ];
        $response = $this->transport->post($target->baseUrl, '/wp-json' . $route, $headers, $body);
        $status = $response['status'];
        $signature = $response['headers'][strtolower(SourceAccessProtocol::HEADER_SIGNATURE)] ?? '';
        if ($status === 401 && $signature === '') {
            // The shop could not authenticate us: the one answer it never signs.
            throw new SourceAccessException('SOURCE_ACCESS_DENIED');
        }
        if (!SourceAccessProtocol::matches($target->key(), SourceAccessProtocol::responseString($target->sourceKey, $target->kid, $nonce, $status, $response['body']), $signature)) {
            throw new SourceAccessException('SOURCE_RESPONSE_UNTRUSTED');
        }
        try {
            $decoded = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SourceAccessException('SOURCE_RESPONSE_INVALID');
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new SourceAccessException('SOURCE_RESPONSE_INVALID');
        }
        return ['status' => $status, 'body' => $decoded];
    }
}
