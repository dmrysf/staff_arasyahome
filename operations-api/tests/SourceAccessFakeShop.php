<?php

declare(strict_types=1);

namespace Arasya\Operations\Tests;

use Arasya\Operations\Integration\SourceAccess\SourceAccessProtocol;
use Arasya\Operations\Integration\SourceAccess\SourceAccessTransport;
use Closure;

/**
 * Test-only stand-in for a YD SOFT shop's source access endpoint (never loaded in production). It implements the shop
 * side of protocol v1 exactly like `YdSoft\...\SourceAccess\Endpoint` (key id, +-60 s window, HMAC over the exact route,
 * source, key id, timestamp, nonce and body, single-use nonce, signed answer bound to the nonce) and answers from fixture
 * orders with invented TEST customers. Used in-process by the integration suite and over HTTP by the E2E shop router.
 *
 * Fixture order: ['id' => '123', 'number' => '123', 'eligible' => true, 'phone' => '0700000123', ...overrides].
 *
 * Phase 4B: the edit routes (catalog, product, preview, apply, edit-status) with the shop's rules that matter to
 * Operations: the order version, the preview token, blockers, idempotency per employee and key, bound to the order. The
 * fake never prices anything like a shop: an edit moves the total by `editDelta`, a fixed TEST amount.
 */
final class SourceAccessFakeShop implements SourceAccessTransport
{
    /** @var array<string, true> */
    public array $nonces = [];
    /** @var list<array{route: string, body: array<string, mixed>}> */
    public array $requests = [];
    /** Runs inside a request after it was authenticated (tests: revoke a scope or reassign the order meanwhile). */
    public ?Closure $during = null;
    /** Overrides the next answer: ['status' => int, 'body' => string, 'sign' => bool, 'nonce' => string]. */
    public ?array $tamper = null;
    public ?string $fail = null;
    /** Phase 4B knobs: the TEST amount an edit adds to the total, blockers of the next previews, a refusal code. */
    public string $editDelta = '35.00';
    /** @var list<array<string, mixed>> */
    public array $editBlockers = [];
    public ?string $editRefusal = null;
    /** 'answer_lost': the shop applies, then the answer never arrives; 'crash': the shop applies, then answers 500. */
    public ?string $afterApply = null;
    /** @var array<string, array{order: string, hash: string, status: int, body: array<string, mixed>}> employee|key => stored answer */
    public array $edits = [];
    /** @var array<string, array<string, mixed>> SKU => product */
    public array $products = [];

    /** @param array<string, array<string, mixed>> $orders orderId => fixture */
    public function __construct(
        public string $sourceKey,
        public string $kid,
        #[\SensitiveParameter] private string $key,
        public array $orders = [],
        private ?Closure $now = null,
    ) {
    }

    public function post(string $baseUrl, string $path, array $headers, string $body): array
    {
        if ($this->fail !== null) {
            throw new \Arasya\Operations\Integration\SourceAccess\SourceAccessException($this->fail);
        }
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower($name)] = $value;
        }
        return $this->handle(substr($path, strlen('/wp-json')), $lower, $body);
    }

    /**
     * @param array<string, string> $headers lower-case names
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(string $route, array $headers, string $body): array
    {
        $now = $this->now !== null ? ($this->now)() : time();
        $kid = $headers[strtolower(SourceAccessProtocol::HEADER_KEY)] ?? '';
        $timestamp = $headers[strtolower(SourceAccessProtocol::HEADER_TIMESTAMP)] ?? '';
        $nonce = $headers[strtolower(SourceAccessProtocol::HEADER_NONCE)] ?? '';
        $signature = $headers[strtolower(SourceAccessProtocol::HEADER_SIGNATURE)] ?? '';
        $denied = ['status' => 401, 'headers' => [], 'body' => '{"error":{"code":"access_denied"}}'];
        if ($kid !== $this->kid || preg_match('/^[0-9a-f]{32}$/D', $nonce) !== 1 || preg_match('/^[0-9]{9,11}$/D', $timestamp) !== 1 || abs((int) $timestamp - $now) > 60
            || !SourceAccessProtocol::matches($this->key, SourceAccessProtocol::requestString($route, $this->sourceKey, $kid, (int) $timestamp, $nonce, $body), $signature)
            || isset($this->nonces[$nonce])) {
            return $denied;
        }
        $this->nonces[$nonce] = true;
        $input = json_decode($body, true);
        $this->requests[] = ['route' => $route, 'body' => is_array($input) ? $input : []];
        if ($this->during !== null) {
            ($this->during)($input);
        }
        $routes = ['detail', 'catalog', 'product', 'preview', 'apply', 'edit-status'];
        $action = substr($route, strlen('/yd-arasya/v1/confirmare/'));
        if (!str_starts_with($route, '/yd-arasya/v1/confirmare/') || !in_array($action, $routes, true) || !is_array($input)) {
            return $this->answer(400, ['error' => ['code' => 'invalid_request']], $nonce);
        }
        $order = $this->orders[(string) ($input['orderId'] ?? '')] ?? null;
        if ($order === null || ($order['number'] ?? $order['id']) !== ($input['orderNumber'] ?? null)) {
            return $this->answer(404, ['error' => ['code' => 'order_not_found']], $nonce);
        }
        if (($order['eligible'] ?? true) !== true) {
            return $this->answer(403, ['error' => ['code' => 'order_not_eligible']], $nonce);
        }
        if ($action === 'detail') {
            return $this->answer(200, self::detail($order, $now), $nonce);
        }
        [$status, $payload] = $this->edit($action, (string) $order['id'], $input, $now);
        if ($status === 0) {
            // The shop applied the edit; its answer is lost on the way (a timeout).
            throw new \Arasya\Operations\Integration\SourceAccess\SourceAccessException('SOURCE_UNREACHABLE');
        }
        return $this->answer($status, $payload, $nonce);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function edit(string $action, string $orderId, array $input, int $now): array
    {
        $order = $this->orders[$orderId];
        $version = self::versionOf($order);
        $employee = (string) ($input['actor']['employeeId'] ?? '');
        if ($action === 'edit-status') {
            $author = (string) ($input['editEmployeeId'] ?? $employee);
            if ($author !== $employee && ($input['actor']['role'] ?? null) !== 'supervisor') {
                return [400, ['error' => ['code' => 'invalid_request']]];
            }
            $stored = $this->edits[$author . '|' . ($input['idempotencyKey'] ?? '')] ?? null;
            if ($stored === null || $stored['order'] !== $orderId) {
                return [200, ['state' => 'absent']];
            }
            return [200, ['state' => $stored['status'] === 200 ? 'applied' : 'rejected', 'status' => $stored['status'], 'result' => $stored['body']]];
        }
        if ($action === 'catalog') {
            return [200, ['protocol' => 1, 'version' => $version, 'editable' => $this->editRefusal === null, 'reason' => $this->editRefusal, 'lines' => [[
                'id' => (string) ((int) $orderId * 10 + 1), 'productId' => '501', 'variationId' => '0', 'name' => 'Draperie Velvet', 'quantity' => 1,
                'calculator' => ['fields' => [
                    ['name' => 'lungimea', 'title' => 'Lungimea (L)', 'type' => 'number', 'editable' => true, 'required' => true, 'min' => 0.5, 'max' => 12.0, 'value' => '3'],
                    ['name' => 'manopera', 'title' => 'Manopera', 'type' => 'select', 'editable' => true, 'required' => true, 'options' => [['value' => '13:Manopera Rejansa Normal 6cm', 'title' => 'Manopera Rejansa Normal 6cm'], ['value' => '18:Manopera Rejansa Bara', 'title' => 'Manopera Rejansa Bara']], 'value' => '13:Manopera Rejansa Normal 6cm'],
                ]],
                'variations' => [], 'editable' => true, 'reason' => null,
            ]], 'shipping' => [['id' => (string) ((int) $orderId * 10 + 9), 'method' => 'flat_rate:3', 'title' => 'Livrare prin curier']],
                'shippingOptions' => [['method' => 'flat_rate:3', 'title' => 'Livrare prin curier'], ['method' => 'free_shipping:4', 'title' => 'Livrare gratuită']]]];
        }
        if ($action === 'product') {
            $product = $this->products[(string) ($input['sku'] ?? '')] ?? null;
            return $product === null ? [404, ['error' => ['code' => 'product_not_found']]] : [200, ['protocol' => 1, 'product' => $product]];
        }
        if ($this->editRefusal !== null) {
            return [409, ['error' => ['code' => $this->editRefusal]]];
        }
        if ($action === 'preview') {
            if (($input['expectedVersion'] ?? null) !== $version) {
                return [409, ['error' => ['code' => 'version_changed'], 'version' => $version]];
            }
            $result = $this->editResult($order, $version);
            return [200, $result + ['previewToken' => $this->token($version, $input['operations'] ?? null, $result)]];
        }
        // apply
        $key = $employee . '|' . ($input['idempotencyKey'] ?? '');
        $hash = hash('sha256', (string) json_encode([$input['expectedVersion'] ?? null, $input['operations'] ?? null, $input['previewToken'] ?? null]));
        if (isset($this->edits[$key])) {
            $stored = $this->edits[$key];
            return $stored['hash'] === $hash && $stored['order'] === $orderId ? [$stored['status'], $stored['body']] : [409, ['error' => ['code' => 'idempotency_conflict']]];
        }
        if (($input['expectedVersion'] ?? null) !== $version) {
            return $this->store($key, $orderId, $hash, 409, ['error' => ['code' => 'version_changed'], 'version' => $version]);
        }
        $result = $this->editResult($order, $version);
        if (!hash_equals($this->token($version, $input['operations'] ?? null, $result), (string) ($input['previewToken'] ?? ''))) {
            return $this->store($key, $orderId, $hash, 409, ['error' => ['code' => 'preview_stale']]);
        }
        if ($result['blockers'] !== []) {
            return $this->store($key, $orderId, $hash, 409, ['error' => ['code' => 'blocked'], 'blockers' => $result['blockers']]);
        }
        // Applied: the order changes (TEST amounts), a new version and modification time.
        $this->orders[$orderId]['totals'] = array_merge(self::detail($order, $now)['totals'], ['total' => $result['after']['total']]);
        $this->orders[$orderId]['pricing'] = 'sha256:' . hash('sha256', $version . '|edited-price');
        $this->orders[$orderId]['version'] = 'sha256:' . hash('sha256', $version . '|edited');
        // WooCommerce keeps whole seconds; a later save of the same order is always a later second here.
        $modified = max($now, isset($order['modifiedAt']) ? (int) strtotime((string) $order['modifiedAt']) + 1 : $now);
        $this->orders[$orderId]['modifiedAt'] = gmdate('Y-m-d\TH:i:s\Z', $modified);
        $after = ['version' => $this->orders[$orderId]['version']] + $result;
        $payload = $after + ['applied' => true, 'financialState' => ['state' => 'waiting', 'basis' => null, 'attention' => 'additional_payment_due'], 'modifiedAt' => $this->orders[$orderId]['modifiedAt']];
        $this->store($key, $orderId, $hash, 200, $payload);
        $after = $this->afterApply;
        $this->afterApply = null;
        if ($after === 'answer_lost') {
            return [0, []];
        }
        if ($after === 'crash') {
            return [500, ['error' => ['code' => 'internal_error']]];
        }
        return [200, $payload];
    }

    /** @param array<string, mixed> $body @return array{0: int, 1: array<string, mixed>} */
    private function store(string $key, string $orderId, string $hash, int $status, array $body): array
    {
        $this->edits[$key] = ['order' => $orderId, 'hash' => $hash, 'status' => $status, 'body' => $body];
        return [$status, $body];
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function editResult(array $order, string $version): array
    {
        $total = (string) (($order['totals']['total'] ?? null) ?? '719.00');
        $after = number_format((float) $total + (float) $this->editDelta, 2, '.', '');
        $totals = static fn (string $t): array => ['subtotal' => '720.00', 'subtotalTax' => '114.95', 'discount' => '30.00', 'discountTax' => '4.79', 'shipping' => '25.00', 'shippingTax' => '3.99', 'fees' => '0.00', 'tax' => '114.16', 'total' => $t];
        $line = static fn (string $subtotal): array => ['name' => 'Draperie Velvet', 'productId' => '501', 'variationId' => '0', 'quantity' => 1, 'fields' => ['lungimea' => '3', 'manopera' => '13:Manopera Rejansa Normal 6cm'], 'subtotal' => $subtotal, 'total' => $subtotal, 'tax' => '67.06'];
        return [
            'protocol' => 1, 'version' => $version, 'applicable' => $this->editBlockers === [], 'blockers' => $this->editBlockers,
            'before' => $totals($total), 'after' => $totals($after),
            'lines' => [['lineId' => (string) ((int) $order['id'] * 10 + 1), 'state' => 'changed', 'before' => $line('420.00'), 'after' => $line(number_format(420 + (float) $this->editDelta, 2, '.', ''))]],
            'shipping' => [['id' => (string) ((int) $order['id'] * 10 + 9), 'method' => 'flat_rate:3', 'before' => '25.00', 'after' => '25.00']],
            'financial' => ['delta' => number_format((float) $this->editDelta, 2, '.', ''), 'impact' => 'additional_payment_due', 'cod' => false],
        ];
    }

    /** @param array<string, mixed> $result */
    private function token(string $version, mixed $operations, array $result): string
    {
        return hash('sha256', (string) json_encode([$version, $operations, $result['after'], $result['blockers']]));
    }

    /** @param array<string, mixed> $order */
    private static function versionOf(array $order): string
    {
        return $order['version'] ?? 'sha256:' . hash('sha256', 'version-' . $order['id']);
    }

    /** @param array<string, mixed> $payload @return array{status: int, headers: array<string, string>, body: string} */
    private function answer(int $status, array $payload, string $nonce): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $tamper = $this->tamper;
        $this->tamper = null;
        if ($tamper !== null) {
            $status = $tamper['status'] ?? $status;
            $body = $tamper['body'] ?? $body;
            $nonce = $tamper['nonce'] ?? $nonce;
            if (($tamper['sign'] ?? true) === false) {
                return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => $body];
            }
        }
        return ['status' => $status, 'headers' => [
            'content-type' => 'application/json',
            'cache-control' => 'no-store, private, max-age=0',
            strtolower(SourceAccessProtocol::HEADER_SIGNATURE) => SourceAccessProtocol::sign($this->key, SourceAccessProtocol::responseString($this->sourceKey, $this->kid, $nonce, $status, $body)),
        ], 'body' => $body];
    }

    /** The OrderDetail shape YD SOFT answers, with invented TEST data. @param array<string, mixed> $o @return array<string, mixed> */
    public static function detail(array $o, int $now): array
    {
        $phone = $o['phone'] ?? '0700000123';
        return [
            'protocol' => 1,
            'readAt' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'order' => [
                'id' => (string) $o['id'], 'number' => (string) ($o['number'] ?? $o['id']),
                'status' => ['code' => $o['status'] ?? 'processing', 'label' => $o['statusLabel'] ?? 'Processing'],
                'createdAt' => $o['createdAt'] ?? gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
                'modifiedAt' => $o['modifiedAt'] ?? gmdate('Y-m-d\TH:i:s\Z', $now - 3000),
                'storage' => $o['storage'] ?? 'hpos', 'currency' => 'RON', 'pricesIncludeTax' => true,
            ],
            'version' => $o['version'] ?? 'sha256:' . hash('sha256', 'version-' . $o['id']),
            'pricingFingerprint' => $o['pricing'] ?? null,
            'contact' => [
                'phoneAvailable' => strlen((string) preg_replace('/\D+/', '', $phone)) >= 9,
                'billing' => ['name' => $o['name'] ?? 'TEST Client Confirmare', 'company' => '', 'phone' => $phone, 'addressLines' => ['Str. Exemplu 7', '500000 Brașov', 'Brașov, RO']],
                'shipping' => ['name' => $o['name'] ?? 'TEST Client Confirmare', 'company' => '', 'phone' => '', 'addressLines' => ['Str. Exemplu 7', '500000 Brașov', 'Brașov, RO']],
            ],
            'lines' => $o['lines'] ?? [[
                'id' => (string) ((int) $o['id'] * 10 + 1), 'line' => 1, 'productId' => '501', 'variationId' => '0', 'name' => 'Draperie Velvet', 'sku' => 'DV-302',
                'width' => 3.0, 'height' => 2.6, 'unit' => 'm',
                'details' => [['label' => 'Lungimea (L)', 'value' => '3'], ['label' => 'Inaltime (H)', 'value' => '2.6'], ['label' => 'Manopera', 'value' => 'Manopera Rejansa Normal 6cm'], ['label' => 'buc', 'value' => '1 buc.']],
                'quantity' => 1, 'unitPrice' => '420.00', 'subtotal' => '420.00', 'discount' => '0.00', 'tax' => '67.06', 'total' => '420.00', 'calculator' => true,
            ], [
                'id' => (string) ((int) $o['id'] * 10 + 2), 'line' => 2, 'productId' => '502', 'variationId' => '0', 'name' => 'Perdea Voal Alb', 'sku' => 'PV-ALB',
                'width' => 4.0, 'height' => 2.5, 'unit' => 'm',
                'details' => [['label' => 'Lungimea (L)', 'value' => '4'], ['label' => 'Inaltime (H)', 'value' => '2.5'], ['label' => 'buc', 'value' => '2 buc.']],
                'quantity' => 2, 'unitPrice' => '150.00', 'subtotal' => '300.00', 'discount' => '30.00', 'tax' => '43.11', 'total' => '270.00', 'calculator' => true,
            ]],
            'fees' => [],
            'coupons' => $o['coupons'] ?? [['code' => 'test10', 'discount' => '30.00', 'discountTax' => '4.79']],
            'shipping' => [['id' => (string) ((int) $o['id'] * 10 + 9), 'methodId' => 'flat_rate', 'instanceId' => '3', 'title' => 'Livrare prin curier', 'total' => '25.00', 'tax' => '3.99']],
            'totals' => $o['totals'] ?? ['subtotal' => '720.00', 'discount' => '30.00', 'discountTax' => '4.79', 'shipping' => '25.00', 'shippingTax' => '3.99', 'fees' => '0.00', 'tax' => '114.16', 'total' => '719.00', 'refunded' => '0.00'],
            'payment' => $o['payment'] ?? ['method' => 'netopiapayments', 'methodTitle' => 'Card online', 'cod' => false, 'paidRecorded' => true, 'financial' => ['state' => 'cleared', 'basis' => 'online_payment']],
        ];
    }
}
