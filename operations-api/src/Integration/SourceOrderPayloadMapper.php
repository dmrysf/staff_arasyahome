<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration;

use Arasya\Operations\Document\DeliveryContext;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Maps the versioned first-party source order contract (schemaVersion 1) to a
 * normalized SourceOrderSnapshot. Every field is validated strictly; unknown
 * production stages are never guessed from labels or commerce status.
 */
final class SourceOrderPayloadMapper
{
    private const ROOT_KEYS = ['schemaVersion', 'eventId', 'changedAt', 'order', 'production'];
    private const ORDER_KEYS = ['id', 'number', 'status', 'availability', 'notes', 'acceptedAt', 'items', 'delivery', 'payment', 'submission', 'pricing', 'dueDate', 'financial', 'contact'];
    /**
     * Optional order facts this server accepts (heartbeat `orderFacts`, API 2.38.0). API 2.40.0: `contact` (a callable
     * telephone number exists) and `financialAttention` (order.financial.attention after a change of the total).
     */
    public const ORDER_FACTS = ['pricing', 'dueDate', 'financial', 'contact', 'financialAttention'];
    /** Why a Confirmare order needs a financial decision after its total changed (order.financial.attention, API 2.40.0). */
    public const FINANCIAL_ATTENTION = ['additional_payment_due', 'refund_due'];
    /** Confirmare financial clearance (order.financial, API 2.38.0): states and bases only, never an amount. */
    public const FINANCIAL_STATES = ['cleared', 'waiting', 'partial', 'unknown'];
    public const FINANCIAL_BASES = ['online_payment', 'zero_total', 'advance_collected'];
    private const SUBMISSION_KEYS = ['submittedAt', 'evidence'];
    private const PAYMENT_KEYS = ['method', 'advanceStatus', 'advanceRevision', 'advanceConfirmedAt'];
    private const MAX_ADVANCE_REVISION = 1000000;
    private const ITEM_KEYS = ['id', 'line', 'name', 'sku', 'variant', 'color', 'width', 'height', 'unit', 'meters', 'quantity', 'options'];
    private const MAX_OPTIONS = 20;
    private const MAX_ITEMS = 200;

    /** @param array<string, mixed> $payload */
    public static function map(string $sourceKey, array $payload): SourceOrderSnapshot
    {
        self::allowKeys($payload, self::ROOT_KEYS, ['schemaVersion', 'eventId', 'changedAt', 'order']);
        if ($payload['schemaVersion'] !== 1) {
            throw new ApiException(422, 'SOURCE_SCHEMA_UNSUPPORTED', 'Only source schema version 1 is supported.');
        }
        $order = self::object($payload['order'], 'order');
        self::allowKeys($order, self::ORDER_KEYS, ['id', 'number', 'availability', 'items']);

        $stageId = null;
        if (array_key_exists('production', $payload) && $payload['production'] !== null) {
            $production = self::object($payload['production'], 'production');
            self::allowKeys($production, ['workflowKey', 'workflowVersion', 'stageId', 'stageLabel'], ['workflowKey', 'workflowVersion', 'stageId']);
            if ($production['workflowKey'] !== 'curtain-production' || $production['workflowVersion'] !== 1 || !is_string($production['stageId'])) {
                throw new ApiException(422, 'SOURCE_STAGE_UNKNOWN', 'The source production stage is not part of curtain-production@1.');
            }
            $stageId = $production['stageId'];
        }

        $availability = $order['availability'];
        $operationalStatus = match ($availability) {
            'active' => 'in_progress',
            'cancelled' => 'unavailable',
            default => throw self::invalid('order.availability'),
        };

        $status = null;
        if (array_key_exists('status', $order) && $order['status'] !== null) {
            $status = self::object($order['status'], 'order.status');
            self::allowKeys($status, ['code', 'label'], ['code']);
        }

        $rawItems = $order['items'];
        if (!is_array($rawItems) || !array_is_list($rawItems) || count($rawItems) > self::MAX_ITEMS) {
            throw self::invalid('order.items');
        }
        $items = [];
        foreach ($rawItems as $index => $rawItem) {
            $item = self::object($rawItem, "order.items[{$index}]");
            self::allowKeys($item, self::ITEM_KEYS, ['id', 'line', 'name', 'quantity']);
            try {
                $items[] = new OperationalOrderItem(
                    Uuid::v4(),
                    self::identifier($item['id'], "order.items[{$index}].id"),
                    self::positiveInt($item['line'], "order.items[{$index}].line"),
                    self::string($item['name'], "order.items[{$index}].name"),
                    self::optionalString($item['sku'] ?? null, "order.items[{$index}].sku"),
                    self::optionalString($item['variant'] ?? null, "order.items[{$index}].variant"),
                    self::optionalString($item['color'] ?? null, "order.items[{$index}].color"),
                    self::optionalNumber($item['width'] ?? null, "order.items[{$index}].width"),
                    self::optionalNumber($item['height'] ?? null, "order.items[{$index}].height"),
                    self::optionalString($item['unit'] ?? null, "order.items[{$index}].unit"),
                    self::optionalNumber($item['meters'] ?? null, "order.items[{$index}].meters"),
                    self::positiveInt($item['quantity'], "order.items[{$index}].quantity"),
                    self::options($item['options'] ?? null, "order.items[{$index}].options"),
                );
            } catch (InvalidArgumentException) {
                throw self::invalid("order.items[{$index}]");
            }
        }

        try {
            return new SourceOrderSnapshot(
                $sourceKey,
                self::identifier($order['id'], 'order.id'),
                self::string($payload['eventId'], 'eventId'),
                1,
                self::timestamp($payload['changedAt'], 'changedAt'),
                self::string($order['number'], 'order.number'),
                $stageId,
                $status === null ? null : self::string($status['code'], 'order.status.code'),
                $status === null ? null : self::optionalString($status['label'] ?? null, 'order.status.label'),
                self::optionalString($order['notes'] ?? null, 'order.notes'),
                $operationalStatus,
                array_key_exists('acceptedAt', $order) && $order['acceptedAt'] !== null ? self::timestamp($order['acceptedAt'], 'order.acceptedAt') : null,
                $items,
                self::delivery($order['delivery'] ?? null),
                self::payment($order['payment'] ?? null),
                self::submission($order['submission'] ?? null),
                self::pricing($order['pricing'] ?? null),
                self::dueDate($order['dueDate'] ?? null),
                self::financial($order['financial'] ?? null),
                self::contact($order['contact'] ?? null),
            );
        } catch (InvalidArgumentException) {
            throw self::invalid('order');
        }
    }

    /**
     * Optional pricing fingerprint (API 2.38.0): {"fingerprint": "sha256:<64 hex>"}. The source hashes its own currency,
     * totals and line prices; no amount ever reaches Arasya. A Confirmare approval is bound to it.
     */
    private static function pricing(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $pricing = self::object($value, 'order.pricing');
        self::allowKeys($pricing, ['fingerprint'], ['fingerprint']);
        if (!is_string($pricing['fingerprint']) || preg_match('/^sha256:[0-9a-f]{64}$/D', $pricing['fingerprint']) !== 1) {
            throw self::invalid('order.pricing.fingerprint');
        }
        return $pricing['fingerprint'];
    }

    /**
     * Optional Confirmare financial clearance (API 2.38.0), evaluated by the source on the order's final total:
     * {"state": "cleared"|"waiting"|"partial"|"unknown", "basis": "online_payment"|"zero_total"|"advance_collected"|null,
     *  "pricingFingerprint": "sha256:<64 hex>"|null}. `cleared` needs a basis and the pricing fingerprint the source
     * evaluated (so a later total change is detected); any other state has no basis. There is no exemption basis yet:
     * an exemption would need an authorized, reasoned and audited decision, so it cannot be stated. No amount is accepted.
     *
     * @return array{state: string, basis: string|null, pricingFingerprint: string|null}|null
     */
    private static function financial(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $financial = self::object($value, 'order.financial');
        self::allowKeys($financial, ['state', 'basis', 'pricingFingerprint', 'attention'], ['state', 'basis', 'pricingFingerprint']);
        $state = $financial['state'];
        $basis = $financial['basis'];
        $pricing = $financial['pricingFingerprint'];
        if (!in_array($state, self::FINANCIAL_STATES, true)) {
            throw self::invalid('order.financial.state');
        }
        if ($state === 'cleared' ? !in_array($basis, self::FINANCIAL_BASES, true) : $basis !== null) {
            throw self::invalid('order.financial.basis');
        }
        if ($pricing === null ? $state === 'cleared' : !is_string($pricing) || preg_match('/^sha256:[0-9a-f]{64}$/D', $pricing) !== 1) {
            throw self::invalid('order.financial.pricingFingerprint');
        }
        $attention = $financial['attention'] ?? null;
        if ($attention !== null && (!in_array($attention, self::FINANCIAL_ATTENTION, true) || $state === 'cleared')) {
            // A financial attention never clears: the shop states it only on a waiting, partial or unknown order.
            throw self::invalid('order.financial.attention');
        }
        // The key joins the array only when stated, so the receipt hash of every earlier event stays the same.
        return ['state' => $state, 'basis' => $basis, 'pricingFingerprint' => $pricing] + ($attention === null ? [] : ['attention' => $attention]);
    }

    /**
     * Optional contact fact (API 2.40.0): {"phone": true|false}, whether the shop holds a callable customer telephone
     * number on its real billing or delivery telephone field. A boolean only: the number never travels with an event.
     */
    private static function contact(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        $contact = self::object($value, 'order.contact');
        self::allowKeys($contact, ['phone'], ['phone']);
        if (!is_bool($contact['phone'])) {
            throw self::invalid('order.contact.phone');
        }
        return $contact['phone'];
    }

    /** Optional due date (API 2.38.0): a calendar date, Y-m-d. Orders due earlier are confirmed first. */
    private static function dueDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw self::invalid('order.dueDate');
        }
        return $value;
    }

    /**
     * Optional printable delivery identity. Email is not part of the contract and is rejected; the
     * phone is masked before it is stored.
     *
     * @return array{name: string|null, company: string|null, addressLines: list<string>, phoneMasked: string|null}|null
     */
    private static function delivery(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        try {
            return DeliveryContext::fromSource(self::object($value, 'order.delivery'));
        } catch (InvalidArgumentException) {
            throw self::invalid('order.delivery');
        }
    }

    /**
     * Optional advance payment facts (schema 1 extension, sent only by sources that saw the heartbeat's
     * paymentGateMode). Classes and states only, never amounts:
     * {"method": "cod"|"online"|"unverified", "advanceStatus": "not_required"|"waiting"|"partial"|"confirmed",
     *  "advanceRevision": int (number of recorded confirmations, never decreasing for one source order),
     *  "advanceConfirmedAt": ISO-8601 UTC|null (only with "confirmed")}. A verified online payment never needs an
     * advance (`not_required`); an unverified payment is always `waiting` (closed until the source confirms it).
     *
     * @return array{method: string, advanceStatus: string, advanceRevision: int, advanceConfirmedAt: DateTimeImmutable|null}|null
     */
    private static function payment(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $payment = self::object($value, 'order.payment');
        self::allowKeys($payment, self::PAYMENT_KEYS, ['method', 'advanceStatus', 'advanceRevision']);
        $method = $payment['method'];
        $status = $payment['advanceStatus'];
        $revision = $payment['advanceRevision'];
        if (!in_array($method, \Arasya\Operations\Production\PaymentGate::METHODS, true)) {
            throw self::invalid('order.payment.method');
        }
        if (!in_array($status, \Arasya\Operations\Production\PaymentGate::ADVANCE_STATUSES, true) || ($method === 'online' && $status !== 'not_required') || ($method === 'unverified' && $status !== 'waiting')) {
            throw self::invalid('order.payment.advanceStatus');
        }
        if (!is_int($revision) || $revision < 0 || $revision > self::MAX_ADVANCE_REVISION) {
            throw self::invalid('order.payment.advanceRevision');
        }
        $confirmedAt = null;
        if (array_key_exists('advanceConfirmedAt', $payment) && $payment['advanceConfirmedAt'] !== null) {
            if ($status !== 'confirmed') {
                throw self::invalid('order.payment.advanceConfirmedAt');
            }
            $confirmedAt = self::timestamp($payment['advanceConfirmedAt'], 'order.payment.advanceConfirmedAt');
        }
        return ['method' => $method, 'advanceStatus' => $status, 'advanceRevision' => $revision, 'advanceConfirmedAt' => $confirmedAt];
    }

    /**
     * Optional submission evidence (schema 1 extension, sent only by sources that saw the heartbeat's `confirmare`
     * state): {"submittedAt": ISO-8601 UTC, "evidence": one of WorkflowCohort::EVIDENCE_KINDS}. It is the first instant
     * the source held the order in a submitted status, never a checkout draft's creation, an update or a payment.
     * It decides the workflow cohort of a new canonical order once (WorkflowCohort) and is never printed.
     *
     * @return array{submittedAt: DateTimeImmutable, evidence: string}|null
     */
    private static function submission(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $submission = self::object($value, 'order.submission');
        self::allowKeys($submission, self::SUBMISSION_KEYS, self::SUBMISSION_KEYS);
        if (!in_array($submission['evidence'], \Arasya\Operations\Production\WorkflowCohort::EVIDENCE_KINDS, true)) {
            throw self::invalid('order.submission.evidence');
        }
        return ['submittedAt' => self::timestamp($submission['submittedAt'], 'order.submission.submittedAt'), 'evidence' => $submission['evidence']];
    }

    /**
     * Optional source manufacturing options exactly as the source states them (for example
     * "Confecționare: 2 bucăți"). Nothing is inferred when they are absent.
     *
     * @return array{options: list<array{label: string, value: string}>}|null
     */
    private static function options(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_OPTIONS) {
            throw self::invalid($field);
        }
        $options = [];
        foreach ($value as $index => $option) {
            $option = self::object($option, "{$field}[{$index}]");
            self::allowKeys($option, ['label', 'value'], ['label', 'value']);
            $label = self::string($option['label'], "{$field}[{$index}].label");
            $text = self::string($option['value'], "{$field}[{$index}].value");
            if (mb_strlen($label) > 80 || mb_strlen($text) > 200) {
                throw self::invalid("{$field}[{$index}]");
            }
            $options[] = ['label' => $label, 'value' => $text];
        }
        return $options === [] ? null : ['options' => $options];
    }

    /** @param array<string, mixed> $value @param list<string> $allowed @param list<string> $required */
    private static function allowKeys(array $value, array $allowed, array $required): void
    {
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw self::invalid((string) $key);
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                throw self::invalid($key);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::invalid($field);
        }
        return $value;
    }

    private static function string(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw self::invalid($field);
        }
        return trim($value);
    }

    private static function optionalString(mixed $value, string $field): ?string
    {
        return $value === null || $value === '' ? null : self::string($value, $field);
    }

    private static function identifier(mixed $value, string $field): string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        $string = self::string($value, $field);
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $string) !== 1) {
            throw self::invalid($field);
        }
        return $string;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 1 || $value > 1_000_000) {
            throw self::invalid($field);
        }
        return $value;
    }

    private static function optionalNumber(mixed $value, string $field): ?float
    {
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 1_000_000) {
            throw self::invalid($field);
        }
        return round((float) $value, 3);
    }

    private static function timestamp(mixed $value, string $field): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw self::invalid($field);
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw self::invalid($field);
        }
    }

    private static function invalid(string $field): ApiException
    {
        return new ApiException(422, 'SOURCE_PAYLOAD_INVALID', "Source payload field is invalid: {$field}.");
    }
}
