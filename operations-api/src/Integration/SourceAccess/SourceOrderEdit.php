<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

/**
 * Phase 4B (API 2.40.0): the operations Operations sends for a controlled order edit, and strict validation of the shop's
 * edit answers (YD SOFT `OrderEdits`). Like SourceOrderDetail: only known fields are copied, anything malformed rejects
 * the whole answer (SOURCE_RESPONSE_INVALID), and the amounts are relayed to the browser once, never stored, logged or
 * cached by Operations.
 */
final class SourceOrderEdit
{
    use ValidatesSourceAnswer;

    public const MAX_OPERATIONS = 20;
    public const MAX_QUANTITY = 100;
    private const MAX_LINES = 200;
    private const MAX_FIELDS = 30;
    private const MAX_OPTIONS = 200;
    private const MAX_VARIATIONS = 200;
    private const MAX_SMALL = 20;
    private const ID = '/^[0-9]{1,20}$/D';
    private const FIELD_NAME = '/^[A-Za-z0-9_-]{1,64}$/D';
    private const METHOD = '/^[a-z0-9_-]{1,40}:[0-9]{1,10}$/D';
    private const CODE = '/^[a-z_]{2,60}$/D';
    public const IMPACTS = ['none', 'additional_payment_due', 'refund_due', 'advance_due'];

    /**
     * The shop's refusals that mean "nothing was changed" (the shop answered before or instead of changing the order).
     * Anything else (a 5xx, a lost or untrusted answer) leaves the outcome unknown until reconciliation.
     */
    public const REFUSALS = [
        'edit_disabled', 'order_status_not_editable', 'refund_exists', 'production_started', 'shipping_started', 'shipping_state_unknown',
        'financial_unknown', 'invalid_operations', 'invalid_quantity', 'invalid_fields', 'line_not_found', 'last_line', 'variation_invalid',
        'product_invalid', 'product_not_found', 'field_not_editable', 'calculator_unavailable', 'calculator_version_unsupported',
        'calculator_time_dependent', 'calculator_unsupported_field', 'calculator_invalid_input', 'calculator_error', 'calculator_state_unknown',
        'version_changed', 'preview_stale', 'blocked', 'order_busy', 'idempotency_conflict', 'no_change', 'invalid_request',
        'access_disabled', 'rate_limited', 'order_not_found', 'order_not_eligible',
    ];

    private function __construct()
    {
    }

    /**
     * Strict shape of the operations (the shop checks them again): one operation per line, ids as digit strings, quantity
     * 1..100, field values as short strings, a shipping method as `<method>:<instance>`.
     *
     * @return list<array<string, mixed>>
     */
    public static function operations(mixed $operations): array
    {
        if (!is_array($operations) || $operations === [] || !array_is_list($operations) || count($operations) > self::MAX_OPERATIONS) {
            throw new \InvalidArgumentException('operations');
        }
        $allowed = [
            'update_line' => ['op', 'lineId', 'quantity', 'variationId', 'fields'],
            'add_line' => ['op', 'productId', 'variationId', 'quantity', 'fields'],
            'remove_line' => ['op', 'lineId'],
            'set_shipping' => ['op', 'shippingLineId', 'method'],
        ];
        $seen = [];
        $out = [];
        foreach ($operations as $op) {
            if (!is_array($op) || !is_string($op['op'] ?? null) || !isset($allowed[$op['op']]) || array_diff(array_keys($op), $allowed[$op['op']]) !== []) {
                throw new \InvalidArgumentException('operations');
            }
            $kind = $op['op'];
            $id = static fn (mixed $value, bool $required): bool => ($value === null && !$required) || (is_string($value) && preg_match(self::ID, $value) === 1);
            if (!$id($op['lineId'] ?? null, in_array($kind, ['update_line', 'remove_line'], true)) || !$id($op['productId'] ?? null, $kind === 'add_line')
                || !$id($op['variationId'] ?? null, false) || !$id($op['shippingLineId'] ?? null, $kind === 'set_shipping')) {
                throw new \InvalidArgumentException('operations');
            }
            if ((array_key_exists('quantity', $op) || $kind === 'add_line') && (!is_int($op['quantity'] ?? null) || $op['quantity'] < 1 || $op['quantity'] > self::MAX_QUANTITY)) {
                throw new \InvalidArgumentException('quantity');
            }
            if (array_key_exists('fields', $op)) {
                if (!is_array($op['fields']) || ($op['fields'] !== [] && array_is_list($op['fields'])) || count($op['fields']) > self::MAX_FIELDS) {
                    throw new \InvalidArgumentException('fields');
                }
                foreach ($op['fields'] as $name => $value) {
                    if (!is_string($name) || preg_match(self::FIELD_NAME, $name) !== 1 || !is_string($value) || strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                        throw new \InvalidArgumentException('fields');
                    }
                }
            }
            if ($kind === 'set_shipping' && (!is_string($op['method'] ?? null) || preg_match(self::METHOD, $op['method']) !== 1)) {
                throw new \InvalidArgumentException('operations');
            }
            $target = isset($op['lineId']) ? 'line:' . $op['lineId'] : (isset($op['shippingLineId']) ? 'shipping:' . $op['shippingLineId'] : null);
            if ($target !== null) {
                if (isset($seen[$target])) {
                    throw new \InvalidArgumentException('operations');
                }
                $seen[$target] = true;
            }
            $out[] = $op;
        }
        return $out;
    }

    /**
     * What the audit keeps of the operations: kinds, ids, quantities and the NAMES of the changed fields. Never a field
     * value (measurements stay in the shop) and never an amount.
     *
     * @param list<array<string, mixed>> $operations
     * @return list<array<string, mixed>>
     */
    public static function auditCodes(array $operations): array
    {
        return array_map(static function (array $op): array {
            $code = array_intersect_key($op, ['op' => true, 'lineId' => true, 'productId' => true, 'variationId' => true, 'quantity' => true, 'shippingLineId' => true, 'method' => true]);
            if (isset($op['fields'])) {
                $code['fields'] = array_keys($op['fields']);
            }
            return $code;
        }, $operations);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function catalog(array $data): array
    {
        self::protocol($data);
        return [
            'version' => self::pattern($data['version'] ?? null, self::FINGERPRINT),
            'editable' => self::bool($data['editable'] ?? null),
            'reason' => self::code($data['reason'] ?? null, true),
            'lines' => self::list($data['lines'] ?? null, self::MAX_LINES, static fn (array $line): array => [
                'id' => self::pattern($line['id'] ?? null, self::ID),
                'productId' => self::pattern($line['productId'] ?? null, self::ID),
                'variationId' => self::pattern($line['variationId'] ?? null, self::ID),
                'name' => self::text($line['name'] ?? null, 300),
                'quantity' => self::quantity($line['quantity'] ?? null),
                'calculator' => ($line['calculator'] ?? null) === null ? null : ['fields' => self::fields(self::map($line['calculator'])['fields'] ?? null, true)],
                'variations' => self::variations($line['variations'] ?? null),
                'editable' => self::bool($line['editable'] ?? null),
                'reason' => self::code($line['reason'] ?? null, true),
            ]),
            'shipping' => self::list($data['shipping'] ?? null, self::MAX_SMALL, static fn (array $line): array => [
                'id' => self::pattern($line['id'] ?? null, self::ID),
                'method' => self::text($line['method'] ?? null, 60),
                'title' => self::text($line['title'] ?? null, 200, true),
            ]),
            'shippingOptions' => self::list($data['shippingOptions'] ?? null, self::MAX_SMALL, static fn (array $option): array => [
                'method' => self::pattern($option['method'] ?? null, self::METHOD),
                'title' => self::text($option['title'] ?? null, 200, true),
            ]),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function product(array $data): array
    {
        self::protocol($data);
        $product = self::map($data['product'] ?? null);
        $calculator = $product['calculator'] ?? null;
        if ($calculator !== null) {
            $calculator = self::map($calculator);
            $calculator = array_key_exists('unavailable', $calculator) ? ['unavailable' => self::code($calculator['unavailable'], false)] : ['fields' => self::fields($calculator['fields'] ?? null, false)];
        }
        $stock = $product['stock'] ?? null;
        return [
            'id' => self::pattern($product['id'] ?? null, self::ID),
            'name' => self::text($product['name'] ?? null, 300),
            'sku' => self::text($product['sku'] ?? null, 100),
            'type' => self::choice($product['type'] ?? null, ['simple', 'variable']),
            'purchasable' => self::bool($product['purchasable'] ?? null),
            'inStock' => self::bool($product['inStock'] ?? null),
            'stock' => $stock === null ? null : self::stock($stock),
            'variations' => self::variations($product['variations'] ?? null),
            'calculator' => $calculator,
        ];
    }

    /**
     * A preview (with its token) or an applied edit (with `applied` and the shop's money state).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function result(array $data, bool $applied): array
    {
        self::protocol($data);
        $financial = self::map($data['financial'] ?? null);
        $out = [
            'version' => self::pattern($data['version'] ?? null, self::FINGERPRINT),
            'applicable' => self::bool($data['applicable'] ?? null),
            'blockers' => self::blockers($data['blockers'] ?? null),
            'before' => self::editTotals($data['before'] ?? null),
            'after' => self::editTotals($data['after'] ?? null),
            'lines' => self::list($data['lines'] ?? null, self::MAX_LINES, static function (array $line): array {
                $state = self::choice($line['state'] ?? null, ['added', 'removed', 'changed', 'unchanged']);
                $before = $line['before'] ?? null;
                $after = $line['after'] ?? null;
                if (($state === 'added') !== ($before === null) || ($state === 'removed') !== ($after === null)) {
                    throw self::invalid();
                }
                return [
                    'lineId' => self::pattern($line['lineId'] ?? null, '/^([0-9]{1,20}|new:[0-9]{1,3})$/D'),
                    'state' => $state,
                    'before' => $before === null ? null : self::snapshot($before),
                    'after' => $after === null ? null : self::snapshot($after),
                ];
            }),
            'shipping' => self::list($data['shipping'] ?? null, self::MAX_SMALL, static fn (array $line): array => [
                'id' => self::pattern($line['id'] ?? null, self::ID),
                'method' => self::pattern($line['method'] ?? null, self::METHOD),
                'before' => self::money($line['before'] ?? null),
                'after' => self::money($line['after'] ?? null),
            ]),
            'financial' => [
                'delta' => self::money($financial['delta'] ?? null),
                'impact' => self::choice($financial['impact'] ?? null, self::IMPACTS),
                'cod' => self::bool($financial['cod'] ?? null),
            ],
        ];
        if ($out['applicable'] !== ($out['blockers'] === [])) {
            throw self::invalid();
        }
        if ($applied) {
            if (($data['applied'] ?? null) !== true || !$out['applicable']) {
                throw self::invalid();
            }
            $out['modifiedAt'] = self::instant($data['modifiedAt'] ?? null);
            $state = self::map($data['financialState'] ?? null);
            $out['financialState'] = [
                'state' => self::choice($state['state'] ?? null, ['cleared', 'waiting', 'partial', 'unknown']),
                'basis' => ($state['basis'] ?? null) === null ? null : self::choice($state['basis'], ['online_payment', 'zero_total', 'advance_collected']),
                'attention' => ($state['attention'] ?? null) === null ? null : self::choice($state['attention'], \Arasya\Operations\Integration\SourceOrderPayloadMapper::FINANCIAL_ATTENTION),
            ];
        } else {
            $out['previewToken'] = self::pattern($data['previewToken'] ?? null, '/^[0-9a-f]{64}$/D');
        }
        return $out;
    }

    /**
     * A signed refusal: its code (unknown codes are SOURCE_ERROR) and what the operator needs to see with it.
     *
     * @param array<string, mixed> $data
     * @return array{code: string, version: ?string, blockers: list<array<string, mixed>>}
     */
    public static function refusal(array $data): array
    {
        $error = self::map($data['error'] ?? null);
        $code = $error['code'] ?? null;
        if (!is_string($code) || !in_array($code, self::REFUSALS, true)) {
            throw new SourceAccessException('SOURCE_ERROR');
        }
        return [
            'code' => $code,
            'version' => $code === 'version_changed' && isset($data['version']) ? self::pattern($data['version'], self::FINGERPRINT) : null,
            'blockers' => $code === 'blocked' ? self::blockers($data['blockers'] ?? null) : [],
        ];
    }

    /**
     * The shop's stored answer of an Idempotency-Key (reconciliation).
     *
     * @param array<string, mixed> $data
     * @return array{state: string, result: ?array<string, mixed>, refusal: ?array<string, mixed>}
     */
    public static function status(array $data): array
    {
        $state = self::choice($data['state'] ?? null, ['absent', 'applied', 'rejected']);
        if ($state === 'absent') {
            return ['state' => 'absent', 'result' => null, 'refusal' => null];
        }
        $result = self::map($data['result'] ?? null);
        return $state === 'applied'
            ? ['state' => 'applied', 'result' => self::result($result, true), 'refusal' => null]
            : ['state' => 'rejected', 'result' => null, 'refusal' => self::refusal($result)];
    }

    /** @param array<string, mixed> $data */
    private static function protocol(array $data): void
    {
        if (($data['protocol'] ?? null) !== 1) {
            throw self::invalid();
        }
    }

    /** @return list<array<string, mixed>> */
    private static function fields(mixed $value, bool $withValue): array
    {
        return self::list($value, self::MAX_FIELDS, static function (array $field) use ($withValue): array {
            $out = [
                'name' => self::pattern($field['name'] ?? null, self::FIELD_NAME),
                'title' => self::text($field['title'] ?? null, 200, true),
                'type' => self::pattern($field['type'] ?? null, '/^[a-z_]{2,40}$/D'),
                'editable' => self::bool($field['editable'] ?? null),
                'required' => self::bool($field['required'] ?? null),
                'min' => self::number($field['min'] ?? null),
                'max' => self::number($field['max'] ?? null),
                'options' => !array_key_exists('options', $field) ? null : self::list($field['options'], self::MAX_OPTIONS, static fn (array $option): array => [
                    'value' => self::text($option['value'] ?? null, 200),
                    'title' => self::text($option['title'] ?? null, 300, true),
                ]),
            ];
            if ($withValue) {
                $out['value'] = ($field['value'] ?? null) === null ? null : self::text($field['value'], 200, true);
            }
            return $out;
        });
    }

    /** @return list<array<string, mixed>> */
    private static function variations(mixed $value): array
    {
        return self::list($value, self::MAX_VARIATIONS, static fn (array $variation): array => [
            'id' => self::pattern($variation['id'] ?? null, self::ID),
            'attributes' => self::list($variation['attributes'] ?? null, self::MAX_SMALL, static fn (array $attribute): array => [
                'label' => self::text($attribute['label'] ?? null, 200, true),
                'value' => self::text($attribute['value'] ?? null, 200, true),
            ]),
            'inStock' => self::bool($variation['inStock'] ?? null),
            'purchasable' => self::bool($variation['purchasable'] ?? null),
            'stock' => ($variation['stock'] ?? null) === null ? null : self::stock($variation['stock']),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function blockers(mixed $value): array
    {
        return self::list($value, 100, static function (array $blocker): array {
            $out = ['code' => self::code($blocker['code'] ?? null, false)];
            foreach (['productId' => self::ID, 'method' => '/^[a-z0-9_:-]{1,60}$/D'] as $key => $pattern) {
                if (isset($blocker[$key])) {
                    $out[$key] = self::pattern($blocker[$key], $pattern);
                }
            }
            if (isset($blocker['coupon'])) {
                $out['coupon'] = self::text($blocker['coupon'], 100);
            }
            foreach (['available', 'needed'] as $key) {
                if (isset($blocker[$key])) {
                    $out[$key] = self::stock($blocker[$key]);
                }
            }
            return $out;
        });
    }

    /** @return array<string, mixed> */
    private static function snapshot(mixed $value): array
    {
        $line = self::map($value);
        $fields = $line['fields'] ?? null;
        if ($fields !== null) {
            $fields = self::map($fields);
            if (count($fields) > self::MAX_FIELDS) {
                throw self::invalid();
            }
            foreach ($fields as $name => $field) {
                if (!is_string($name) || preg_match(self::FIELD_NAME, $name) !== 1) {
                    throw self::invalid();
                }
                self::text($field, 200, true);
            }
        }
        return [
            'name' => self::text($line['name'] ?? null, 300),
            'productId' => self::pattern($line['productId'] ?? null, self::ID),
            'variationId' => self::pattern($line['variationId'] ?? null, self::ID),
            'quantity' => self::quantity($line['quantity'] ?? null),
            'fields' => $fields,
            'subtotal' => self::money($line['subtotal'] ?? null),
            'total' => self::money($line['total'] ?? null),
            'tax' => self::money($line['tax'] ?? null),
        ];
    }

    /** @return array<string, string> */
    private static function editTotals(mixed $value): array
    {
        $totals = self::map($value);
        $out = [];
        foreach (['subtotal', 'subtotalTax', 'discount', 'discountTax', 'shipping', 'shippingTax', 'fees', 'tax', 'total'] as $key) {
            $out[$key] = self::money($totals[$key] ?? null);
        }
        return $out;
    }

    private static function code(mixed $value, bool $nullable): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }
        return self::pattern($value, self::CODE);
    }

    private static function quantity(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 100000) {
            throw self::invalid();
        }
        return $value;
    }

    private static function stock(mixed $value): int
    {
        if (!is_int($value) || $value < -1000000 || $value > 100000000) {
            throw self::invalid();
        }
        return $value;
    }

    private static function number(mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }
        if (!(is_int($value) || is_float($value)) || !is_finite((float) $value) || abs((float) $value) > 1e9) {
            throw self::invalid();
        }
        return $value;
    }
}
