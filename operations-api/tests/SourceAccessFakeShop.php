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
 *
 * Phase 5 (API 2.41.0): the finance routes (finance, finance-action, finance-status) with the shop's money rules that
 * matter to Operations (YD SOFT 2.15.0 FinanceSummary/FinanceActions): a recorded transfer is not money received until
 * another employee verifies it, the summary revision binds every action, bank references are unique across orders,
 * a refund needs `productionComplete`, an acknowledged liability and the manual refunds switch. Fixture key `finance`:
 * ['cod' => bool, 'total' => '650.00', 'paid' => '500.00' (online), 'collected' => '0.00' (COD), 'pricing' => 'sha256:…'].
 *
 * SmartBill invoicing (API 2.43.0): the invoice routes (invoice, invoice-action, invoice-status) with the shop's rules
 * that matter to Operations (YD SOFT 2.17.0 InvoiceSummary/InvoiceActions/InvoicePolicy): one document per order, the
 * summary revision, idempotency per employee and key, the money blockers, a resend confirmation, the manager's re-issue
 * statement, an unknown outcome blocking a new document until a manager resolves it. Fixture key `invoice`:
 * ['cleared' => bool, 'documentType' => 'invoice'|'proforma', 'autoEmail' => bool]. Knobs: `invoiceOutcome` of the next
 * SmartBill call (created | unknown | refused), `afterInvoice` (answer_lost | crash).
 * API 2.44.0 (YD SOFT 2.18.0): the operator's advance proforma (proforma-action, role operator; the proforma state in the
 * invoice summary). Fixture key `invoice.cod` (a cash-on-delivery order waiting for its advance: required 215.70).
 * Knobs: `proformaOutcome` (created | unknown | refused), `proformaMailOutcome` (sent | refused); `invoice.proformaInvoiced`
 * makes SmartBill report the proforma invoiced (the final invoice is then refused, as the shop does). Proformas and their
 * e-mails are counted in smartbillDocuments / smartbillMails under `pf:<orderId>`.
 *
 * API 2.48.0 (YD SOFT 2.22.0): the adoption route (adopt, role `backfill`) of an order of 22–26 October, which is not a
 * Confirmare order yet (so it is answered whatever `eligible` says). Fixture keys: `adoptReasons` (the shop's refusal
 * reasons, default none), `adopted` (recorded). An adoption makes the order `eligible`. Knob `afterAdopt`
 * ('answer_lost': the shop records the adoption, then the answer never arrives). Adoptions are counted in `adoptions`.
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
    /** Phase 5 knobs: 'answer_lost' / 'crash' after an applied finance action; the shop's own switches. */
    public ?string $afterFinance = null;
    /** YD SOFT 2.16 safety rules (owner rule 2, K-1): `finance_open` and `order_cancelled` refusals. Off = a 2.15 shop. */
    public bool $safetyRules = false;
    /** What happens to the answer of the next refusal after the shop stored it: 'answer_lost' or 'crash'. */
    public ?string $afterRefusal = null;
    /** The next finance action is answered with this refusal [code, status], stored like any refusal (protocol violations). */
    public ?array $forceRefusal = null;
    public bool $financeRecord = true;
    public string $financeRefunds = 'manual';
    /** YD SOFT 2.23.0: who completes a bank verification (four_eyes | single); null answers like an older shop (absent). */
    public ?string $financeVerification = null;
    /** @var array<string, array{order: string, hash: string, status: int, body: array<string, mixed>}> employee|key => stored answer */
    public array $financeAnswers = [];
    /** SmartBill knobs: the outcome of the next SmartBill call; 'answer_lost' / 'crash' after the shop ran an action. */
    public string $invoiceOutcome = 'created';
    public string $proformaOutcome = 'created';
    public string $proformaMailOutcome = 'sent';
    /** API 2.45.0 (YD SOFT 2.19.0): the next SmartBill cancellation: cancelled | refused | unknown. */
    public string $proformaCancelOutcome = 'cancelled';
    public ?string $afterInvoice = null;
    public bool $invoiceActions = true;
    /** @var array<string, mixed>|null YD SOFT 2.21.0: the next invoice-payment answer (null: no final invoice yet). */
    public ?array $invoicePayment = null;
    public int $invoicePaymentReads = 0;

    /** Overrides the SmartBill link of the next invoice states (a shop answering with a foreign link). */
    public ?string $invoiceLinkOverride = null;
    /** @var array<string, array{order: string, hash: string, status: int, body: array<string, mixed>}> employee|key => stored answer */
    public array $invoiceAnswers = [];
    /** YD SOFT 2.18.0: the shop's bank transfers that match no order (TransferExceptions), oldest first. */
    public array $exceptions = [];
    /** 'answer_lost' after the shop recorded an exception action. */
    public ?string $afterException = null;
    /** Documents "made in SmartBill" by this TEST shop: orderId => count. */
    public array $smartbillDocuments = [];
    /** E-mails "sent by SmartBill" by this TEST shop: orderId => count. */
    public array $smartbillMails = [];
    /** Cancellations "sent to SmartBill" by this TEST shop: orderId => count (2.19.0). */
    public array $smartbillCancels = [];
    /** Answer like a YD SOFT 2.18.0 shop: none of the 2.19.0 proforma fields or groups. */
    public bool $legacyProforma = false;
    /** @var array<string, int> reminders sent through SmartBill per order (2.20.0) */
    public array $smartbillReminders = [];
    /** The next reminder's SmartBill outcome: sent | refused. */
    public string $reminderOutcome = 'sent';
    /** R3 (2026-10-26): how many of the next proforma issues / e-mails SmartBill answers 429 (Retry-After 45 s). */
    public int $proformaRateLimits = 0;
    public int $mailRateLimits = 0;
    public int $rateLimitRetryAfter = 45;
    /** API 2.48.0: 'answer_lost' after the shop recorded an adoption. */
    public ?string $afterAdopt = null;
    /** @var array<string, int> adoptions recorded by this TEST shop: orderId => count */
    public array $adoptions = [];

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
        $routes = ['detail', 'catalog', 'product', 'preview', 'apply', 'edit-status', 'finance', 'finance-action', 'finance-status', 'invoice', 'invoice-action', 'invoice-status', 'invoice-payment', 'proforma-action',
            'transfer-exceptions', 'transfer-exception-action', 'operation', 'operation-status', 'adopt'];
        $action = substr($route, strlen('/yd-arasya/v1/confirmare/'));
        if (!str_starts_with($route, '/yd-arasya/v1/confirmare/') || !in_array($action, $routes, true) || !is_array($input)) {
            return $this->answer(400, ['error' => ['code' => 'invalid_request']], $nonce);
        }
        if (str_starts_with($action, 'transfer-exception')) {
            // No order fields: the shop's own finance record (YD SOFT 2.18.0), finance only.
            if (isset($input['orderId']) || ($input['actor']['role'] ?? null) !== 'finance') {
                return $this->answer(400, ['error' => ['code' => 'invalid_request']], $nonce);
            }
            [$status, $payload] = $this->exception($action, $input, $now);
            if ($status === 0) {
                throw new \Arasya\Operations\Integration\SourceAccess\SourceAccessException('SOURCE_UNREACHABLE');
            }
            return $this->answer($status, $payload, $nonce);
        }
        $order = $this->orders[(string) ($input['orderId'] ?? '')] ?? null;
        if ($order === null || ($order['number'] ?? $order['id']) !== ($input['orderNumber'] ?? null)) {
            return $this->answer(404, ['error' => ['code' => 'order_not_found']], $nonce);
        }
        if ($action === 'adopt') {
            [$status, $payload] = $this->adopt((string) $order['id'], $input, $now);
            if ($status === 0) {
                throw new \Arasya\Operations\Integration\SourceAccess\SourceAccessException('SOURCE_UNREACHABLE');
            }
            return $this->answer($status, $payload, $nonce);
        }
        // YD SOFT 2.23.0: an order outside Confirmare submitted from 22 October 2026 (`legacyFinance`) is readable by
        // the finance summary route only; everything else keeps refusing it.
        if (($order['eligible'] ?? true) !== true && !($action === 'finance' && ($order['legacyFinance'] ?? false) === true)) {
            return $this->answer(403, ['error' => ['code' => 'order_not_eligible']], $nonce);
        }
        if ($action === 'detail') {
            return $this->answer(200, self::detail($order, $now), $nonce);
        }
        if (str_starts_with($action, 'invoice') || $action === 'proforma-action') {
            [$status, $payload] = $action === 'proforma-action' ? $this->proforma((string) $order['id'], $input, $now) : $this->invoice($action, (string) $order['id'], $input, $now);
            if ($status === 0) {
                throw new \Arasya\Operations\Integration\SourceAccess\SourceAccessException('SOURCE_UNREACHABLE');
            }
            return $this->answer($status, $payload, $nonce);
        }
        if ($action === 'operation' || $action === 'operation-status') {
            [$status, $payload] = $this->operation($action, (string) $order['id'], $input, $now);
            if ($status === 0) {
                throw new \Arasya\Operations\Integration\SourceAccess\SourceAccessException('SOURCE_UNREACHABLE');
            }
            return $this->answer($status, $payload, $nonce);
        }
        if (str_starts_with($action, 'finance')) {
            [$status, $payload] = $this->finance($action, (string) $order['id'], $input, $now);
            if ($status === 0) {
                throw new \Arasya\Operations\Integration\SourceAccess\SourceAccessException('SOURCE_UNREACHABLE');
            }
            return $this->answer($status, $payload, $nonce);
        }
        [$status, $payload] = $this->edit($action, (string) $order['id'], $input, $now);
        if ($status === 0) {
            // The shop applied the edit; its answer is lost on the way (a timeout).
            throw new \Arasya\Operations\Integration\SourceAccess\SourceAccessException('SOURCE_UNREACHABLE');
        }
        return $this->answer($status, $payload, $nonce);
    }

    /**
     * The shop's adoption of an order of 22–26 October (YD SOFT 2.22.0 Adoption): its own reasons, a dry run, idempotent.
     *
     * @param array<string, mixed> $input
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function adopt(string $orderId, array $input, int $now): array
    {
        $order = &$this->orders[$orderId];
        $global = $this->sourceKey . ':' . ($order['number'] ?? $order['id']);
        $dry = $input['dryRun'] ?? null;
        if (!is_bool($dry) || ($input['globalOrderId'] ?? null) !== $global || ($input['actor']['role'] ?? null) !== 'backfill' || array_diff(array_keys($input), ['orderId', 'orderNumber', 'actor', 'requestId', 'globalOrderId', 'dryRun']) !== []) {
            return [400, ['error' => ['code' => 'invalid_request']]];
        }
        $adopted = ($order['adopted'] ?? false) === true;
        $reasons = $adopted ? [] : array_values($order['adoptReasons'] ?? []);
        if (!$dry && $reasons !== []) {
            return [409, ['error' => ['code' => 'not_adoptable', 'reasons' => $reasons]]];
        }
        if (!$dry && !$adopted) {
            $order['adopted'] = true;
            $order['eligible'] = true;
            $order['adoptedAt'] = gmdate('Y-m-d\TH:i:s', $now) . '.000000Z';
            $this->adoptions[$orderId] = ($this->adoptions[$orderId] ?? 0) + 1;
            if ($this->afterAdopt === 'answer_lost') {
                $this->afterAdopt = null;
                return [0, []];
            }
            $adopted = true;
        }
        return [200, ['protocol' => 1, 'globalOrderId' => $global, 'dryRun' => $dry, 'adoptable' => $reasons === [], 'reasons' => $reasons, 'adopted' => $adopted, 'adoptedAt' => $adopted ? $order['adoptedAt'] : null]];
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

    /**
     * @param array<string, mixed> $input
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function finance(string $route, string $orderId, array $input, int $now): array
    {
        $employee = (string) ($input['actor']['employeeId'] ?? '');
        $role = $input['actor']['role'] ?? null;
        if ($route === 'finance') {
            return in_array($role, ['finance', 'assignee', 'supervisor'], true) ? [200, $this->financeSummary($orderId, $now)] : [400, ['error' => ['code' => 'invalid_request']]];
        }
        if ($role !== 'finance') {
            return [400, ['error' => ['code' => 'invalid_request']]];
        }
        if ($route === 'finance-status') {
            $author = (string) ($input['actionEmployeeId'] ?? $employee);
            $stored = $this->financeAnswers[$author . '|' . ($input['idempotencyKey'] ?? '')] ?? null;
            if ($stored === null || $stored['order'] !== $orderId) {
                return [200, ['state' => 'absent']];
            }
            return [200, ['state' => $stored['status'] === 200 ? 'applied' : 'rejected', 'status' => $stored['status'], 'result' => $stored['body']]];
        }
        if (!$this->financeRecord) {
            return [409, ['error' => ['code' => 'finance_record_disabled']]];
        }
        $key = $employee . '|' . ($input['idempotencyKey'] ?? '');
        $request = $input;
        unset($request['requestId'], $request['actor']);
        $hash = hash('sha256', (string) json_encode($request));
        if (isset($this->financeAnswers[$key])) {
            $stored = $this->financeAnswers[$key];
            return $stored['hash'] === $hash && $stored['order'] === $orderId ? [$stored['status'], $stored['body']] : [409, ['error' => ['code' => 'idempotency_conflict']]];
        }
        $revision = $this->financeRevision($orderId);
        $refuse = function (string $code, int $status = 409) use ($key, $orderId, $hash, $revision): array {
            $this->financeAnswers[$key] = ['order' => $orderId, 'hash' => $hash, 'status' => $status, 'body' => ['error' => ['code' => $code], 'revision' => $revision]];
            $after = $this->afterRefusal;
            $this->afterRefusal = null;
            if ($after === 'answer_lost') {
                return [0, []];
            }
            if ($after === 'crash') {
                return [500, ['error' => ['code' => 'internal_error']]];
            }
            return [$status, ['error' => ['code' => $code], 'revision' => $revision]];
        };
        if ($this->forceRefusal !== null) {
            [$forcedCode, $forcedStatus] = $this->forceRefusal;
            $this->forceRefusal = null;
            return $refuse($forcedCode, $forcedStatus);
        }
        if (($input['expectedRevision'] ?? null) !== $revision) {
            return $refuse('revision_changed');
        }
        $cancelled = ($this->orders[$orderId]['status'] ?? 'processing') === 'cancelled';
        $f = &$this->orders[$orderId]['finance'];
        $f['entries'] ??= [];
        $numbers = $this->financeNumbers($orderId);
        $openOther = static function (array $entries, ?string $except) {
            foreach ($entries as $entry) {
                if ($entry['state'] === 'submitted' && in_array($entry['kind'], ['transfer_in', 'refund_out'], true) && $entry['id'] !== $except) {
                    return true;
                }
            }
            return false;
        };
        $entryId = null;
        $action = (string) ($input['action'] ?? '');
        if ($action === 'submit_transfer' || $action === 'submit_refund') {
            $kind = $action === 'submit_transfer' ? 'transfer_in' : 'refund_out';
            $amount = (float) ($input['amount'] ?? 0);
            if ($this->safetyRules && $action === 'submit_transfer' && $cancelled) {
                return $refuse('order_cancelled');
            }
            if ($this->safetyRules && $action === 'submit_refund' && $openOther($f['entries'], null)) {
                foreach ($f['entries'] as $entry) {
                    if ($entry['kind'] === 'transfer_in' && $entry['state'] === 'submitted') {
                        return $refuse('finance_open');
                    }
                }
            }
            if ($action === 'submit_refund') {
                if (($input['productionComplete'] ?? null) !== true) {
                    return $refuse('production_not_complete');
                }
                if ($this->financeRefunds !== 'manual') {
                    return $refuse('refund_disabled');
                }
                if (!$numbers['acknowledged']) {
                    return $refuse('liability_not_acknowledged');
                }
                if ($amount > $numbers['liability'] + 0.001) {
                    return $refuse('amount_exceeds_liability');
                }
            } else {
                if (($input['purpose'] ?? null) !== (($f['cod'] ?? false) ? 'advance' : 'additional_payment')) {
                    return $refuse('purpose_not_allowed');
                }
                // YD SOFT 2.19.0: cash needs its document and collector; an excess only with the explicit confirmation;
                // a payment for a cancelled proforma only with the finance decision.
                if (($input['method'] ?? 'bank_transfer') !== 'bank_transfer' && (!isset($input['receipt'], $input['collector']))) {
                    return $refuse('cash_document_required', 422);
                }
                if ($amount > max(0, $numbers['total'] - $numbers['received']) + 0.001 && ($input['overpaymentConfirmed'] ?? false) !== true) {
                    return $refuse('amount_exceeds_balance');
                }
                if (($f['proformaCancelled'] ?? false) && ($input['cancelledProforma'] ?? null) !== 'apply_to_order') {
                    return $refuse('proforma_cancelled');
                }
            }
            foreach ($f['entries'] as $entry) {
                if ($entry['kind'] === $kind && $entry['state'] === 'submitted') {
                    return $refuse($kind === 'transfer_in' ? 'transfer_pending' : 'refund_pending');
                }
            }
            $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($input['reference'] ?? '')));
            foreach ($this->orders as $other) {
                foreach ($other['finance']['entries'] ?? [] as $entry) {
                    if ($entry['kind'] === $kind && $entry['state'] !== 'rejected' && preg_replace('/[^A-Z0-9]/', '', strtoupper($entry['reference'])) === $normalized) {
                        return $refuse('duplicate_reference');
                    }
                }
            }
            $entryId = $this->nextEntryId();
            $f['entries'][] = ['id' => $entryId, 'kind' => $kind, 'purpose' => $kind === 'refund_out' ? 'refund' : (string) $input['purpose'], 'amount' => number_format($amount, 2, '.', ''),
                'reference' => (string) $input['reference'], 'valueDate' => (string) $input['valueDate'], 'state' => 'submitted', 'submittedBy' => $employee,
                'submittedAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'decidedBy' => null, 'decidedAt' => null, 'decisionCode' => null,
                'method' => (string) ($input['method'] ?? 'bank_transfer'), 'receipt' => $input['receipt'] ?? null, 'collector' => $input['collector'] ?? null,
                'flags' => array_values(array_filter([($input['overpaymentConfirmed'] ?? false) === true ? 'overpayment' : null, isset($input['cancelledProforma']) ? 'cancelled_proforma' : null]))];
        } elseif ($action === 'verify_transfer') {
            // YD SOFT 2.23.0: one employee records and verifies a bank transfer of the advance (policy `single` only).
            if ($this->financeVerification !== 'single') {
                return $refuse('single_verification_disabled');
            }
            if (($input['bankStatementConfirmed'] ?? null) !== true || ($input['purpose'] ?? null) !== 'advance') {
                return $refuse(($input['bankStatementConfirmed'] ?? null) !== true ? 'bank_statement_required' : 'purpose_not_allowed', 422);
            }
            if ($this->safetyRules && $cancelled) {
                return $refuse('order_cancelled');
            }
            if (!($f['cod'] ?? false)) {
                return $refuse('purpose_not_allowed');
            }
            $proforma = $this->orders[$orderId]['invoice']['pf']['document'] ?? null;
            if ($proforma === null || ($f['proformaCancelled'] ?? false)) {
                return $refuse('proforma_missing');
            }
            if (($input['currency'] ?? null) !== 'RON') {
                return $refuse('currency_mismatch', 422);
            }
            $expected = 'TH' . preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($this->orders[$orderId]['number'] ?? $this->orders[$orderId]['id'])));
            if (($input['paymentReference'] ?? null) !== $expected) {
                return $refuse('payment_reference_mismatch', 422);
            }
            foreach ($f['entries'] as $entry) {
                if ($entry['kind'] === 'transfer_in' && $entry['state'] === 'submitted') {
                    return $refuse('transfer_pending');
                }
            }
            $amount = (float) ($input['amount'] ?? 0);
            if ($amount > max(0, $numbers['total'] - $numbers['received']) + 0.001) {
                return $refuse('amount_exceeds_balance');
            }
            $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($input['reference'] ?? '')));
            foreach ($this->orders as $other) {
                foreach ($other['finance']['entries'] ?? [] as $entry) {
                    if ($entry['kind'] === 'transfer_in' && $entry['state'] !== 'rejected' && preg_replace('/[^A-Z0-9]/', '', strtoupper($entry['reference'])) === $normalized) {
                        return $refuse('duplicate_reference');
                    }
                }
            }
            $entryId = $this->nextEntryId();
            $at = gmdate('Y-m-d\TH:i:s\Z', $now);
            $f['entries'][] = ['id' => $entryId, 'kind' => 'transfer_in', 'purpose' => 'advance', 'amount' => number_format($amount, 2, '.', ''), 'reference' => (string) $input['reference'], 'valueDate' => (string) $input['valueDate'],
                'state' => 'verified', 'submittedBy' => $employee, 'submittedAt' => $at, 'decidedBy' => $employee, 'decidedAt' => $at, 'decisionCode' => 'verified',
                'method' => 'bank_transfer', 'receipt' => null, 'collector' => null, 'flags' => ['single_verification']];
        } elseif ($action === 'decide_transfer' || $action === 'decide_refund') {
            if ($action === 'decide_refund' && ($input['productionComplete'] ?? null) !== true) {
                return $refuse('production_not_complete');
            }
            $index = null;
            foreach ($f['entries'] as $i => $entry) {
                if ($entry['id'] === ($input['entryId'] ?? null) && $entry['kind'] === ($action === 'decide_transfer' ? 'transfer_in' : 'refund_out')) {
                    $index = $i;
                }
            }
            if ($index === null) {
                return $refuse('entry_not_found', 404);
            }
            if ($f['entries'][$index]['state'] !== 'submitted') {
                return $refuse('entry_not_pending');
            }
            if ($f['entries'][$index]['submittedBy'] === $employee) {
                return $refuse('same_employee', 403);
            }
            $verify = ($input['decision'] ?? null) === 'verify';
            if ($this->safetyRules && $verify && $action === 'decide_transfer' && $cancelled) {
                return $refuse('order_cancelled');
            }
            if ($this->safetyRules && $verify && $action === 'decide_refund' && $openOther($f['entries'], $f['entries'][$index]['id'])) {
                return $refuse('finance_open');
            }
            $f['entries'][$index] = array_merge($f['entries'][$index], ['state' => $verify ? 'verified' : 'rejected', 'decidedBy' => $employee, 'decidedAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'decisionCode' => $verify ? 'verified' : (string) ($input['reasonCode'] ?? 'other')]);
            $entryId = $f['entries'][$index]['id'];
        } elseif ($action === 'acknowledge_liability') {
            if ($numbers['liability'] < 0.01) {
                return $refuse('no_liability');
            }
            if ($numbers['acknowledged']) {
                return $refuse('already_acknowledged');
            }
            $entryId = $this->nextEntryId();
            $f['entries'][] = ['id' => $entryId, 'kind' => 'liability_ack', 'purpose' => 'liability', 'amount' => number_format($numbers['liability'], 2, '.', ''), 'reference' => '', 'valueDate' => null,
                'state' => 'verified', 'submittedBy' => $employee, 'submittedAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'decidedBy' => $employee, 'decidedAt' => gmdate('Y-m-d\TH:i:s\Z', $now),
                'decisionCode' => 'acknowledged', 'pricing' => (string) ($f['pricing'] ?? '')];
        } else {
            return $refuse('invalid_request', 400);
        }
        unset($f);
        $payload = ['protocol' => 1, 'applied' => true, 'action' => $action, 'entryId' => $entryId, 'summary' => $this->financeSummary($orderId, $now)];
        $this->financeAnswers[$key] = ['order' => $orderId, 'hash' => $hash, 'status' => 200, 'body' => $payload];
        $after = $this->afterFinance;
        $this->afterFinance = null;
        if ($after === 'answer_lost') {
            return [0, []];
        }
        if ($after === 'crash') {
            return [500, ['error' => ['code' => 'internal_error']]];
        }
        return [200, $payload];
    }

    /** The money state of a fixture order, like ConfirmareFinancial (TEST amounts). @return array<string, mixed> */
    public function financeNumbers(string $orderId): array
    {
        $f = $this->orders[$orderId]['finance'] ?? [];
        $cod = (bool) ($f['cod'] ?? false);
        $total = (float) ($f['total'] ?? '719.00');
        $transfers = 0.0;
        $advances = 0.0;
        $refunds = 0.0;
        $pendingTransfer = false;
        $pendingRefund = false;
        $ack = null;
        foreach ($f['entries'] ?? [] as $entry) {
            if ($entry['kind'] === 'transfer_in') {
                $pendingTransfer = $pendingTransfer || $entry['state'] === 'submitted';
                if ($entry['state'] === 'verified') {
                    $entry['purpose'] === 'advance' ? $advances += (float) $entry['amount'] : $transfers += (float) $entry['amount'];
                }
            } elseif ($entry['kind'] === 'refund_out') {
                $pendingRefund = $pendingRefund || $entry['state'] === 'submitted';
                $refunds += $entry['state'] === 'verified' ? (float) $entry['amount'] : 0;
            } elseif ($entry['kind'] === 'liability_ack') {
                $ack = $entry;
            }
        }
        $received = round($cod ? (float) ($f['collected'] ?? 0) + $advances - $refunds : (float) ($f['paid'] ?? $total) + $transfers - $refunds, 2);
        $required = $cod ? round($total * 0.3, 2) : $total;
        $liability = max(0, round($received - $total, 2));
        $acknowledged = $liability > 0 && $ack !== null && ($ack['pricing'] ?? '') === (string) ($f['pricing'] ?? '') && (float) $ack['amount'] + 0.01 >= $liability;
        $pricing = (string) ($f['pricing'] ?? '');
        $state = match (true) {
            $liability > 0 => $acknowledged ? ['cleared', $cod ? 'advance_collected' : 'online_payment', $pendingRefund ? 'refund_pending' : 'refund_liability'] : ['waiting', null, 'refund_due'],
            !$cod && $total > $received + 0.001 => ['waiting', null, $pendingTransfer ? 'transfer_pending' : 'additional_payment_due'],
            $cod && $received + 0.005 < $required => [$received > 0 ? 'partial' : 'waiting', null, $pendingTransfer ? 'transfer_pending' : null],
            default => ['cleared', $cod ? 'advance_collected' : 'online_payment', null],
        };
        return ['cod' => $cod, 'total' => $total, 'received' => $received, 'required' => $required, 'transfers' => $transfers, 'refunded' => $refunds, 'liability' => $liability, 'acknowledged' => $acknowledged,
            'state' => $state[0], 'basis' => $state[1], 'attention' => $state[2], 'pricing' => $pricing];
    }

    /** Entry ids continue across orders and requests (the E2E shop keeps its orders in a file between requests). */
    private function nextEntryId(): string
    {
        $count = 0;
        foreach ($this->orders as $order) {
            $count += count($order['finance']['entries'] ?? []);
        }
        return sprintf('00000000-0000-4000-9000-%012d', $count + 1);
    }

    private function financeRevision(string $orderId): string
    {
        return 'sha256:' . hash('sha256', (string) json_encode($this->orders[$orderId]['finance'] ?? []));
    }

    /** The FinanceSummary shape YD SOFT 2.15.0 answers (TEST amounts). @return array<string, mixed> */
    public function financeSummary(string $orderId, int $now): array
    {
        $o = $this->orders[$orderId];
        $n = $this->financeNumbers($orderId);
        $money = static fn (float $v): string => number_format($v, 2, '.', '');
        $f = $o['finance'] ?? [];
        return [
            'protocol' => 1, 'readAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'revision' => $this->financeRevision($orderId), 'currency' => 'RON',
            'order' => ['id' => (string) $o['id'], 'number' => (string) ($o['number'] ?? $o['id']), 'status' => 'processing', 'modifiedAt' => gmdate('Y-m-d\TH:i:s\Z', $now - 60),
                'method' => $n['cod'] ? 'cod' : 'netopiapayments', 'methodTitle' => $n['cod'] ? 'Ramburs' : 'Card online', 'cod' => $n['cod']],
            'totals' => ['current' => $money($n['total']), 'original' => $f['original'] ?? null, 'refunded' => $money($n['refunded'])],
            'received' => ['online' => $n['cod'] ? null : $money((float) ($f['paid'] ?? $n['total'])), 'advance' => $n['cod'] ? $money((float) ($f['collected'] ?? 0) + ($n['received'] - (float) ($f['collected'] ?? 0) + $n['refunded'])) : null,
                'transfers' => $money($n['transfers']), 'net' => $money($n['received'])],
            'payment' => ['onlineVerified' => !$n['cod'], 'paidRecorded' => !$n['cod'], 'evidence' => $n['cod'] ? 'none' : 'netopia_ipn'],
            'advance' => $n['cod'] ? ['percentage' => 30, 'required' => $money($n['required']), 'collected' => $money($n['received']), 'outstanding' => $money(max(0, $n['required'] - $n['received'])), 'onDelivery' => $money(max(0, $n['total'] - $n['received'])),
                'paymentReference' => 'TH' . preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($o['number'] ?? $o['id']))), 'proforma' => isset($o['invoice']['pf']['document']) ? ['series' => (string) $o['invoice']['pf']['document']['series'], 'number' => (string) $o['invoice']['pf']['document']['number'], 'amount' => '215.70', 'currency' => 'RON', 'reference' => 'TH' . preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($o['number'] ?? $o['id'])))] : null]
                + ($this->legacyProforma ? [] : ['proformaCancelled' => (bool) ($f['proformaCancelled'] ?? false)]) : null,
            'balance' => ['outstanding' => $n['cod'] ? null : $money(max(0, $n['total'] - $n['received'])), 'refundLiability' => $money($n['liability']), 'acknowledged' => $n['acknowledged']],
            'financial' => ['state' => $n['state'], 'basis' => $n['basis'], 'attention' => $n['attention']],
            'entries' => array_map(static fn (array $e): array => array_diff_key($e, ['pricing' => true]), $f['entries'] ?? []),
            'capabilities' => ['record' => $this->financeRecord && ($o['eligible'] ?? true) === true, 'refunds' => $this->financeRefunds, 'electronicRefund' => false] + ($this->financeVerification === null ? [] : ['verification' => $this->financeVerification])
                + ($this->financeVerification === null ? [] : ['ledger' => ($o['eligible'] ?? true) === true ? 'shop' : 'manual']),
        ] + ($this->legacyProforma ? [] : [
            // YD SOFT 2.19.0: the one money calculation (TEST numbers of this fake), no deadline, no AWB.
            'position' => ['currency' => 'RON', 'percentage' => $n['cod'] ? 30 : null, 'total' => $money($n['total']), 'advanceRequired' => $n['cod'] ? $money($n['required']) : null,
                'received' => $money($n['received'] + $n['refunded']), 'refunded' => $money($n['refunded']), 'net' => $money($n['received']), 'remaining' => $money(max(0, $n['total'] - $n['received'])),
                'excess' => $money(max(0, $n['received'] - $n['total'])), 'advanceGap' => $n['cod'] ? $money(max(0, $n['required'] - $n['received'])) : null, 'known' => true],
            'deadline' => ['state' => 'none', 'firstCollection' => null, 'deadline' => null, 'daysLeft' => null, 'level' => null],
            'cod' => ['state' => 'none', 'awb' => null, 'awbCod' => null, 'expected' => null, 'status' => null],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function invoice(string $route, string $orderId, array $input, int $now): array
    {
        $employee = (string) ($input['actor']['employeeId'] ?? '');
        $role = $input['actor']['role'] ?? null;
        $this->orders[$orderId]['invoice'] ??= [];
        $i = &$this->orders[$orderId]['invoice'];
        $i += self::invoiceDefaults();
        if ($route === 'invoice') {
            return in_array($role, ['documents', 'manager', 'finance', 'viewer', 'operator'], true) ? [200, $this->invoiceSummary($orderId, $now, (string) $role)] : [400, ['error' => ['code' => 'invalid_request']]];
        }
        if ($route === 'invoice-payment') {
            // YD SOFT 2.21.0: the read-only SmartBill payment reconciliation (answer set by the test in $invoicePayment).
            if (!in_array($role, ['documents', 'manager', 'finance'], true)) {
                return [400, ['error' => ['code' => 'invalid_request']]];
            }
            $this->invoicePaymentReads++;
            return [200, $this->invoicePayment ?? ['state' => 'no_invoice', 'severity' => 'ok', 'code' => null, 'checkedAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'currency' => 'RON', 'arasya' => null, 'smartbill' => null, 'invoice' => null]];
        }
        if (!in_array($role, $route === 'invoice-status' ? ['documents', 'manager', 'operator'] : ['documents', 'manager'], true)) {
            return [400, ['error' => ['code' => 'invalid_request']]];
        }
        if ($route === 'invoice-status') {
            $author = (string) ($input['actionEmployeeId'] ?? $employee);
            $stored = $this->invoiceAnswers[$author . '|' . ($input['idempotencyKey'] ?? '')] ?? null;
            if ($stored === null || $stored['order'] !== $orderId) {
                if ($i['opKey'] === $author . '|' . ($input['idempotencyKey'] ?? '')) {
                    return [200, ['state' => $i['op'] === 'issued' ? 'applied' : 'unknown']];
                }
                if ($i['pf']['opKey'] === $author . '|' . ($input['idempotencyKey'] ?? '')) {
                    return [200, ['state' => $i['pf']['op'] === 'issued' ? 'applied' : 'unknown']];
                }
                return [200, ['state' => 'absent']];
            }
            $state = $stored['status'] === 200 ? 'applied' : ($stored['status'] === 202 ? 'unknown' : 'rejected');
            return [200, ['state' => $state, 'status' => $stored['status'], 'result' => $stored['body']]];
        }
        if (!$this->invoiceActions) {
            return [409, ['error' => ['code' => 'invoice_disabled']]];
        }
        $action = (string) ($input['action'] ?? '');
        if (($action === 'reissue' || $action === 'resolve') && $role !== 'manager') {
            return [400, ['error' => ['code' => 'invalid_request']]];
        }
        $key = $employee . '|' . ($input['idempotencyKey'] ?? '');
        $request = $input;
        unset($request['requestId'], $request['actor']);
        $hash = hash('sha256', (string) json_encode($request));
        if (isset($this->invoiceAnswers[$key])) {
            $stored = $this->invoiceAnswers[$key];
            return $stored['hash'] === $hash && $stored['order'] === $orderId ? [$stored['status'], $stored['body']] : [409, ['error' => ['code' => 'idempotency_conflict']]];
        }
        $revision = $this->invoiceRevision($orderId);
        $keep = function (int $status, array $body) use ($key, $orderId, $hash): array {
            $this->invoiceAnswers[$key] = ['order' => $orderId, 'hash' => $hash, 'status' => $status, 'body' => $body];
            return [$status, $body];
        };
        $refuse = static fn (string $code, array $blockers = []) => $keep(409, ['error' => ['code' => $code], 'blockers' => $blockers === [] ? [$code] : $blockers, 'revision' => $revision]);
        if (($input['expectedRevision'] ?? null) !== $revision) {
            return $keep(409, ['error' => ['code' => 'revision_changed'], 'revision' => $revision]);
        }
        $blockers = $this->invoiceBlockers($orderId, $action);
        if ($action === 'send_mail' && in_array($i['mail'], ['smartbill', 'sent', 'unknown'], true) && ($input['confirmResend'] ?? false) !== true) {
            $blockers[] = 'resend_confirmation_required';
        }
        if ($action === 'reissue' && $i['documentType'] === 'invoice' && ($input['previousCancelled'] ?? false) !== true) {
            $blockers[] = 'reissue_attestation_required';
        }
        if ($blockers !== []) {
            return $refuse($blockers[0], $blockers);
        }
        if ($action === 'resolve') {
            if (($input['target'] ?? '') === 'document') {
                $i['op'] = ($input['resolution'] ?? '') === 'issued' ? 'issued' : ($i['document'] === null ? 'none' : 'issued');
                if ($i['op'] === 'issued' && $i['document'] === null) {
                    $i['document'] = ['series' => 'TST', 'number' => null];
                }
            } else {
                $i['mail'] = ($input['resolution'] ?? '') === 'sent' ? 'sent' : 'failed';
            }
            $i['history'][] = ['kind' => 'resolve_' . ($input['target'] ?? ''), 'at' => $now, 'by' => $employee, 'outcome' => (string) ($input['resolution'] ?? '')];
            return $this->invoiceAfter($keep(200, ['protocol' => 1, 'applied' => true, 'action' => 'resolve', 'result' => (string) $input['resolution'], 'summary' => $this->invoiceSummary($orderId, $now, (string) $role)]));
        }
        if ($action === 'send_mail') {
            $i['mail'] = 'sent';
            $i['mailCount']++;
            $this->smartbillMails[$orderId] = ($this->smartbillMails[$orderId] ?? 0) + 1;
            $i['history'][] = ['kind' => 'send_mail', 'at' => $now, 'by' => $employee, 'outcome' => 'sent'];
            return $this->invoiceAfter($keep(200, ['protocol' => 1, 'applied' => true, 'action' => 'send_mail', 'result' => 'sent', 'summary' => $this->invoiceSummary($orderId, $now, (string) $role)]));
        }
        // issue or reissue: one SmartBill call.
        $outcome = $this->invoiceOutcome;
        $this->invoiceOutcome = 'created';
        if ($outcome === 'refused') {
            $i['history'][] = ['kind' => $action, 'at' => $now, 'by' => $employee, 'outcome' => 'refused'];
            return $keep(409, ['error' => ['code' => 'smartbill_refused'], 'revision' => $this->invoiceRevision($orderId)]);
        }
        $this->smartbillDocuments[$orderId] = ($this->smartbillDocuments[$orderId] ?? 0) + 1;
        if ($outcome === 'unknown') {
            $i['op'] = 'unknown';
            $i['opKey'] = $key;
            $i['history'][] = ['kind' => $action, 'at' => $now, 'by' => $employee, 'outcome' => 'unknown'];
            return $this->invoiceAfter($keep(202, ['protocol' => 1, 'applied' => false, 'action' => $action, 'result' => 'unknown', 'summary' => $this->invoiceSummary($orderId, $now, (string) $role)]));
        }
        // Numbers continue across requests (the HTTP shop keeps smartbillDocuments in its fixture file).
        $i['document'] = ['series' => $i['documentType'] === 'proforma' ? 'PRF' : 'TST', 'number' => sprintf('%04d', array_sum($this->smartbillDocuments)), 'at' => $now, 'by' => $employee];
        $i['op'] = 'issued';
        $i['opKey'] = $key;
        if ($i['autoEmail']) {
            $i['mail'] = 'smartbill';
        } else {
            $i['mail'] = 'sent';
            $this->smartbillMails[$orderId] = ($this->smartbillMails[$orderId] ?? 0) + 1;
        }
        $i['mailCount']++;
        $i['history'][] = ['kind' => $action, 'at' => $now, 'by' => $employee, 'outcome' => 'issued'];
        return $this->invoiceAfter($keep(200, ['protocol' => 1, 'applied' => true, 'action' => $action, 'result' => 'issued', 'summary' => $this->invoiceSummary($orderId, $now, (string) $role)]));
    }

    /** @param array{0: int, 1: array<string, mixed>} $answer @return array{0: int, 1: array<string, mixed>} */
    private function invoiceAfter(array $answer): array
    {
        $after = $this->afterInvoice;
        $this->afterInvoice = null;
        return match ($after) {
            'answer_lost' => [0, []],
            'crash' => [500, ['error' => ['code' => 'internal_error']]],
            default => $answer,
        };
    }

    /** @return list<string> */
    private function invoiceBlockers(string $orderId, string $action): array
    {
        $i = $this->orders[$orderId]['invoice'];
        if ($action === 'resolve') {
            return $i['op'] === 'unknown' || $i['mail'] === 'unknown' ? [] : ['nothing_to_resolve'];
        }
        $blockers = [];
        if ($i['op'] === 'unknown') {
            $blockers[] = 'operation_unknown';
        }
        if ($action === 'send_mail') {
            return $i['document'] === null ? [...$blockers, 'document_missing'] : $blockers;
        }
        if ($action === 'issue' && $i['document'] !== null) {
            $blockers[] = 'document_exists';
        }
        if (in_array($action, ['issue', 'reissue'], true) && ($i['pf']['invoiced'] ?? null) === true) {
            $blockers[] = 'proforma_invoiced';
        }
        if ($action === 'reissue' && $i['document'] === null) {
            $blockers[] = 'document_missing';
        }
        if (!$i['cleared']) {
            $blockers[] = 'payment_not_cleared';
        }
        if (in_array($action, ['issue', 'reissue'], true) && ($i['shippingMismatch'] ?? false)) {
            $blockers[] = 'shipping_mismatch';
        }
        return $blockers;
    }

    private function invoiceRevision(string $orderId): string
    {
        $i = $this->orders[$orderId]['invoice'];
        unset($i['history']);
        // A fixture's proforma state without the later fields (cancel 2.19.0, reminders 2.20.0) is the same state.
        if (is_array($i['pf'] ?? null)) {
            $i['pf'] += ['cancel' => null, 'cancelled' => [], 'reminders' => []];
        }
        return 'sha256:' . hash('sha256', (string) json_encode($i));
    }

    /** @return array<string, mixed> */
    private static function invoiceDefaults(): array
    {
        return ['cleared' => true, 'documentType' => 'invoice', 'autoEmail' => false, 'document' => null, 'op' => 'none', 'opKey' => null, 'mail' => 'none', 'mailCount' => 0, 'history' => [],
            'cod' => false, 'proformaInvoiced' => false, 'pf' => ['document' => null, 'op' => 'none', 'opKey' => null, 'mail' => 'none', 'mailCount' => 0, 'invoiced' => null, 'history' => [], 'cancel' => null, 'cancelled' => [], 'reminders' => []],
            // 2.19.0: the customer accepted the total (true by default: the earlier scenarios), a partial verified advance, accepted.
            'totalAccepted' => true, 'partial' => false, 'advanceAccepted' => false];
    }

    /**
     * The advance proforma (YD SOFT 2.18.0 ProformaActions), TEST rules that matter to Operations.
     *
     * @param array<string, mixed> $input
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function proforma(string $orderId, array $input, int $now): array
    {
        $employee = (string) ($input['actor']['employeeId'] ?? '');
        $role = (string) ($input['actor']['role'] ?? '');
        $this->orders[$orderId]['invoice'] ??= [];
        $i = &$this->orders[$orderId]['invoice'];
        $i += self::invoiceDefaults();
        $p = &$i['pf'];
        $action = (string) ($input['action'] ?? '');
        $roles = ['proforma_issue' => ['operator', 'manager'], 'proforma_send_mail' => ['operator', 'manager'], 'proforma_check' => ['operator', 'documents', 'manager'], 'proforma_resolve' => ['manager'],
            'proforma_cancel' => ['operator', 'manager'], 'total_accept' => ['operator', 'manager'], 'advance_accept' => ['operator', 'manager'], 'proforma_remind' => ['operator', 'manager']];
        $p += ['cancel' => null, 'cancelled' => [], 'reminders' => []];
        if (!isset($roles[$action]) || !in_array($role, $roles[$action], true)) {
            return [400, ['error' => ['code' => 'invalid_request']]];
        }
        if (!$this->invoiceActions) {
            return [409, ['error' => ['code' => 'invoice_disabled']]];
        }
        $key = $employee . '|' . ($input['idempotencyKey'] ?? '');
        $request = $input;
        unset($request['requestId'], $request['actor']);
        $hash = hash('sha256', (string) json_encode($request));
        if (isset($this->invoiceAnswers[$key])) {
            $stored = $this->invoiceAnswers[$key];
            return $stored['hash'] === $hash && $stored['order'] === $orderId ? [$stored['status'], $stored['body']] : [409, ['error' => ['code' => 'idempotency_conflict']]];
        }
        $revision = $this->invoiceRevision($orderId);
        $keep = function (int $status, array $body) use ($key, $orderId, $hash): array {
            $this->invoiceAnswers[$key] = ['order' => $orderId, 'hash' => $hash, 'status' => $status, 'body' => $body];
            return [$status, $body];
        };
        if (($input['expectedRevision'] ?? null) !== $revision) {
            return $keep(409, ['error' => ['code' => 'revision_changed'], 'revision' => $revision]);
        }
        $blockers = $this->proformaBlockers($orderId, $action);
        if ($action === 'proforma_issue' && ($input['unconfirmed'] ?? false) === true) {
            // 2.20.0: after three unanswered calls the advance is a share of the order's current total (no acceptance yet).
            $blockers = array_values(array_diff($blockers, ['total_not_accepted']));
        }
        if ($action === 'proforma_remind') {
            $stage = (string) ($input['stage'] ?? '');
            if ($p['document'] === null) {
                $blockers[] = 'proforma_missing';
            } elseif ($p['mail'] !== 'sent') {
                $blockers[] = 'proforma_not_sent';
            }
            if (isset($p['reminders'][$stage])) {
                $blockers[] = 'reminder_already_sent';
            }
            if ($i['cleared'] || ($i['partial'] ?? false) || ($i['verifying'] ?? false)) {
                $blockers[] = 'advance_payment_received';
            }
        }
        if ($action === 'proforma_send_mail' && in_array($p['mail'], ['sent', 'unknown'], true) && ($input['confirmResend'] ?? false) !== true) {
            $blockers[] = 'resend_confirmation_required';
        }
        if ($action === 'proforma_cancel' && $blockers === [] && ($input['confirmNoPayment'] ?? false) !== true) {
            $blockers[] = 'no_payment_confirmation_required';
        }
        if ($action === 'total_accept' && $blockers === [] && ($input['declaration'] ?? false) !== true) {
            $blockers[] = 'declaration_required';
        }
        if ($action === 'total_accept' && $blockers === []) {
            // Owner business rule 2026-10-25: verified money below the new advance needs the operator's explicit decision.
            $decision = $input['advanceDecision'] ?? null;
            if (($i['advanceDecision'] ?? false) && $decision === null) {
                $blockers[] = 'advance_decision_required';
            } elseif (!($i['advanceDecision'] ?? false) && $decision !== null) {
                $blockers[] = 'advance_decision_not_applicable';
            }
        }
        if ($blockers !== []) {
            return $keep(409, ['error' => ['code' => $blockers[0]], 'blockers' => $blockers, 'revision' => $revision]);
        }
        $done = fn (int $status, string $result): array => $this->invoiceAfter($keep($status, ['protocol' => 1, 'applied' => $status === 200, 'action' => $action, 'result' => $result, 'summary' => $this->invoiceSummary($orderId, $now, $role)]));
        if ($action === 'proforma_remind') {
            $outcome = $this->reminderOutcome;
            $this->reminderOutcome = 'sent';
            if ($outcome === 'refused') {
                $p['history'][] = ['kind' => 'proforma_remind', 'at' => $now, 'by' => $employee, 'outcome' => 'failed'];
                return $keep(409, ['error' => ['code' => 'mail_refused'], 'revision' => $this->invoiceRevision($orderId)]);
            }
            $p['reminders'][(string) $input['stage']] = $now;
            $this->smartbillReminders['pf:' . $orderId] = ($this->smartbillReminders['pf:' . $orderId] ?? 0) + 1;
            $p['history'][] = ['kind' => 'proforma_remind', 'at' => $now, 'by' => $employee, 'outcome' => 'sent'];
            return $done(200, 'reminded');
        }
        if ($action === 'proforma_check') {
            $p['invoiced'] = (bool) $i['proformaInvoiced'];
            $p['history'][] = ['kind' => 'check', 'at' => $now, 'by' => $employee, 'outcome' => $p['invoiced'] ? 'invoiced' : 'not_invoiced'];
            return $done(200, $p['invoiced'] ? 'invoiced' : 'not_invoiced');
        }
        if ($action === 'total_accept') {
            $i['totalAccepted'] = true;
            // Owner business rule 2026-10-25: the money paid before a revision counts only when the operator accepts it.
            if (($input['advanceDecision'] ?? null) === 'accept_received') {
                $i['advanceAccepted'] = true;
                $i['cleared'] = true;
                $i['advanceKind'] = 'kept';
            }
            $i['advanceDecision'] = false;
            return $done(200, 'accepted');
        }
        if ($action === 'advance_accept') {
            $i['advanceAccepted'] = true;
            $i['cleared'] = true;
            return $done(200, 'accepted');
        }
        if ($action === 'proforma_cancel') {
            $outcome = $this->proformaCancelOutcome;
            $this->proformaCancelOutcome = 'cancelled';
            $this->smartbillCancels['pf:' . $orderId] = ($this->smartbillCancels['pf:' . $orderId] ?? 0) + 1;
            if ($outcome === 'refused') {
                $p['history'][] = ['kind' => 'proforma_cancel', 'at' => $now, 'by' => $employee, 'outcome' => 'refused'];
                return $keep(409, ['error' => ['code' => 'cancel_refused'], 'revision' => $this->invoiceRevision($orderId)]);
            }
            if ($outcome === 'unknown') {
                $p['cancel'] = ['state' => 'unknown', 'at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'by' => $employee, 'reason' => (string) $input['reasonCode']];
                $p['history'][] = ['kind' => 'proforma_cancel', 'at' => $now, 'by' => $employee, 'outcome' => 'unknown'];
                return $done(202, 'unknown');
            }
            $p['cancelled'][] = ['series' => $p['document']['series'], 'number' => $p['document']['number'], 'amount' => '215.70', 'currency' => 'RON', 'cancelledAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'cancelledBy' => $employee, 'reason' => (string) $input['reasonCode']];
            $p['document'] = null;
            $p['op'] = 'none';
            $p['cancel'] = null;
            $p['history'][] = ['kind' => 'proforma_cancel', 'at' => $now, 'by' => $employee, 'outcome' => 'cancelled'];
            return $done(200, 'cancelled');
        }
        if ($action === 'proforma_resolve') {
            if (($input['target'] ?? '') === 'cancel') {
                if (($input['resolution'] ?? '') === 'cancelled' && $p['document'] !== null) {
                    $p['cancelled'][] = ['series' => $p['document']['series'], 'number' => $p['document']['number'], 'amount' => '215.70', 'currency' => 'RON', 'cancelledAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'cancelledBy' => $employee, 'reason' => (string) ($p['cancel']['reason'] ?? 'other')];
                    $p['document'] = null;
                    $p['op'] = 'none';
                }
                $p['cancel'] = null;
                $p['history'][] = ['kind' => 'proforma_resolve_cancel', 'at' => $now, 'by' => $employee, 'outcome' => (string) ($input['resolution'] ?? '')];
                return $done(200, (string) $input['resolution']);
            }
            if (($input['target'] ?? '') === 'document') {
                $p['op'] = ($input['resolution'] ?? '') === 'issued' ? 'issued' : ($p['document'] === null ? 'none' : 'issued');
                if (($input['resolution'] ?? '') === 'issued') {
                    $p['document'] = ['series' => (string) $input['series'], 'number' => (string) $input['number'], 'at' => $now, 'by' => $employee];
                }
            } else {
                $p['mail'] = ($input['resolution'] ?? '') === 'sent' ? 'sent' : 'failed';
            }
            $p['history'][] = ['kind' => 'proforma_resolve_' . ($input['target'] ?? ''), 'at' => $now, 'by' => $employee, 'outcome' => (string) ($input['resolution'] ?? '')];
            return $done(200, (string) $input['resolution']);
        }
        if ($action === 'proforma_send_mail') {
            if ($this->mailRateLimits > 0) {
                $this->mailRateLimits--;
                $p['mail'] = 'failed';
                $p['history'][] = ['kind' => 'proforma_mail', 'at' => $now, 'by' => $employee, 'outcome' => 'failed'];
                return $keep(409, ['error' => ['code' => 'mail_rate_limited'], 'revision' => $this->invoiceRevision($orderId), 'retryAfter' => $this->rateLimitRetryAfter]);
            }
            $outcome = $this->proformaMailOutcome;
            $this->proformaMailOutcome = 'sent';
            if ($outcome === 'refused') {
                $p['mail'] = 'failed';
                $p['history'][] = ['kind' => 'proforma_mail', 'at' => $now, 'by' => $employee, 'outcome' => 'failed'];
                return $keep(409, ['error' => ['code' => 'mail_refused'], 'revision' => $this->invoiceRevision($orderId)]);
            }
            $p['mail'] = 'sent';
            $p['mailCount']++;
            $this->smartbillMails['pf:' . $orderId] = ($this->smartbillMails['pf:' . $orderId] ?? 0) + 1;
            $p['history'][] = ['kind' => 'proforma_mail', 'at' => $now, 'by' => $employee, 'outcome' => 'sent'];
            return $done(200, 'sent');
        }
        if ($this->proformaRateLimits > 0) {
            $this->proformaRateLimits--;
            $p['history'][] = ['kind' => 'proforma_issue', 'at' => $now, 'by' => $employee, 'outcome' => 'refused'];
            return $keep(409, ['error' => ['code' => 'smartbill_rate_limited'], 'revision' => $this->invoiceRevision($orderId), 'retryAfter' => $this->rateLimitRetryAfter]);
        }
        $outcome = $this->proformaOutcome;
        $this->proformaOutcome = 'created';
        if ($outcome === 'refused') {
            $p['history'][] = ['kind' => 'proforma_issue', 'at' => $now, 'by' => $employee, 'outcome' => 'refused'];
            return $keep(409, ['error' => ['code' => 'smartbill_refused'], 'revision' => $this->invoiceRevision($orderId)]);
        }
        $this->smartbillDocuments['pf:' . $orderId] = ($this->smartbillDocuments['pf:' . $orderId] ?? 0) + 1;
        $p['opKey'] = $key;
        if ($outcome === 'unknown') {
            $p['op'] = 'unknown';
            $p['history'][] = ['kind' => 'proforma_issue', 'at' => $now, 'by' => $employee, 'outcome' => 'unknown'];
            return $done(202, 'unknown');
        }
        $p['document'] = ['series' => 'PRF', 'number' => sprintf('%04d', array_sum($this->smartbillDocuments)), 'at' => $now, 'by' => $employee, 'unconfirmed' => ($input['unconfirmed'] ?? false) === true];
        $p['mail'] = 'none';
        $p['op'] = 'issued';
        $p['history'][] = ['kind' => 'proforma_issue', 'at' => $now, 'by' => $employee, 'outcome' => 'issued'];
        return $done(200, 'issued');
    }

    /** @return list<string> */
    private function proformaBlockers(string $orderId, string $action): array
    {
        $i = $this->orders[$orderId]['invoice'];
        $p = $i['pf'];
        $p += ['cancel' => null, 'cancelled' => []];
        if ($action === 'proforma_resolve') {
            return $p['op'] === 'unknown' || $p['mail'] === 'unknown' || $p['cancel'] !== null ? [] : ['nothing_to_resolve'];
        }
        if ($action === 'total_accept') {
            return ($i['totalAccepted'] ?? true) ? ['total_already_accepted'] : [];
        }
        if ($action === 'advance_accept') {
            $codes = !$i['cod'] ? ['advance_not_required'] : (($i['advanceAccepted'] ?? false) ? ['advance_already_accepted'] : ($i['cleared'] ? ['advance_already_collected'] : (!($i['partial'] ?? false) ? ['advance_not_received'] : [])));
            return ($i['totalAccepted'] ?? true) ? $codes : [...$codes, 'total_not_accepted'];
        }
        if ($action === 'proforma_cancel') {
            $codes = $p['document'] === null ? ['proforma_missing'] : [];
            if ($i['cleared'] || ($i['partial'] ?? false)) {
                $codes[] = 'advance_payment_received';
            }
            if ($i['verifying'] ?? false) {
                $codes[] = 'payment_verifying';
            }
            if ($i['document'] !== null) {
                $codes[] = 'invoice_exists';
            }
            return $codes;
        }
        $blockers = $p['op'] === 'unknown' ? ['operation_unknown'] : [];
        if ($p['cancel'] !== null) {
            $blockers[] = 'cancel_unknown';
        }
        if ($action === 'proforma_remind') {
            return $blockers; // the reminder's own checks are in proforma() (document, e-mail, stage, money)
        }
        if ($action === 'proforma_check') {
            return $p['document'] === null ? [...$blockers, 'proforma_missing'] : $blockers;
        }
        if ($p['invoiced'] === true) {
            $blockers[] = 'proforma_invoiced';
        }
        if ($action === 'proforma_send_mail') {
            return $p['document'] === null ? [...$blockers, 'proforma_missing'] : $blockers;
        }
        if (!$i['cod']) {
            $blockers[] = 'advance_not_required';
        } elseif ($i['cleared']) {
            $blockers[] = 'advance_already_collected';
        }
        if (!($i['totalAccepted'] ?? true)) {
            $blockers[] = 'total_not_accepted';
        }
        if ($p['document'] !== null) {
            $blockers[] = 'proforma_exists';
        }
        if ($i['shippingMismatch'] ?? false) {
            $blockers[] = 'shipping_mismatch';
        }
        return $blockers;
    }

    /** The InvoiceSummary shape YD SOFT 2.17.0 answers, with TEST data. @return array<string, mixed> */
    public function invoiceSummary(string $orderId, int $now, string $role): array
    {
        $o = $this->orders[$orderId];
        $i = $o['invoice'];
        $doc = $i['document'];
        $blockers = [];
        foreach (['issue' => 'issue', 'sendMail' => 'send_mail', 'reissue' => 'reissue', 'resolve' => 'resolve'] as $name => $action) {
            $blockers[$name] = $this->invoiceActions ? $this->invoiceBlockers($orderId, $action) : ['invoice_observe'];
        }
        return [
            'protocol' => 1,
            'readAt' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'revision' => $this->invoiceRevision($orderId),
            'mode' => $this->invoiceActions ? 'enforce' : 'observe',
            'smartbill' => ['available' => true, 'reason' => null, 'documentType' => $i['documentType'], 'autoEmail' => $i['autoEmail'], 'cashing' => false, 'draft' => false, 'mailSupported' => true, 'autoIssue' => false],
            'order' => ['id' => (string) $o['id'], 'number' => (string) ($o['number'] ?? $o['id']), 'status' => (string) ($o['shopStatus'] ?? 'processing'), 'total' => '719.00', 'currency' => 'RON', 'billingEmail' => true],
            'financial' => ['state' => $i['cleared'] ? 'cleared' : 'waiting', 'basis' => $i['cleared'] ? 'online_payment' : null, 'attention' => null, 'pending' => false],
            'document' => [
                'exists' => $doc !== null, 'type' => $doc === null ? null : $i['documentType'], 'series' => $doc['series'] ?? null, 'number' => $doc['number'] ?? null, 'status' => $doc === null ? null : 'final',
                'conflict' => false, 'through' => $doc === null ? null : 'arasya', 'issuedAt' => isset($doc['at']) ? gmdate('Y-m-d\TH:i:s\Z', $doc['at']) : null, 'issuedBy' => $doc['by'] ?? null,
                'total' => $doc === null ? null : '719.00', 'currency' => $doc === null ? null : 'RON', 'outdated' => $doc === null ? null : false,
                'link' => $doc !== null && $doc['number'] !== null && in_array($role, ['documents', 'manager'], true) ? ($this->invoiceLinkOverride ?? 'https://cloud.smartbill.ro/core/factura/vizualizare/?serie=TST&numar=' . $doc['number']) : null,
            ],
            'operation' => ['state' => $i['op'], 'kind' => $i['op'] === 'none' ? null : 'issue', 'startedAt' => null, 'by' => null, 'error' => null],
            'mail' => ['state' => $i['mail'], 'at' => null, 'by' => null, 'count' => $i['mailCount']],
            'history' => array_map(static fn (array $h): array => ['kind' => $h['kind'], 'at' => gmdate('Y-m-d\TH:i:s\Z', $h['at']), 'by' => $h['by'], 'outcome' => $h['outcome'], 'reason' => null, 'series' => null, 'number' => null], array_reverse($i['history'])),
            'blockers' => $blockers,
            'proforma' => $this->proformaSummary($orderId, $now, $role),
        ] + ($this->legacyProforma ? [] : ['eFactura' => ['state' => 'not_tracked']]);
    }

    /**
     * The shop's exception record (YD SOFT 2.18.0, TransferExceptions): list, record, resolve by ANOTHER employee.
     *
     * @param array<string, mixed> $input
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function exception(string $route, array $input, int $now): array
    {
        $listing = fn (): array => ['protocol' => 1, 'readAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'available' => true, 'currency' => 'RON', 'exceptions' => array_values($this->exceptions)];
        if ($route === 'transfer-exceptions') {
            return [200, $listing()];
        }
        $employee = (string) ($input['actor']['employeeId'] ?? '');
        $key = 'exc|' . $employee . '|' . (string) ($input['idempotencyKey'] ?? '');
        $request = $input;
        unset($request['requestId'], $request['actor']);
        $hash = hash('sha256', (string) json_encode($request));
        if (isset($this->financeAnswers[$key])) {
            $stored = $this->financeAnswers[$key];
            return $stored['hash'] === $hash ? [$stored['status'], $stored['body']] : [409, ['error' => ['code' => 'idempotency_conflict']]];
        }
        $action = $input['action'] ?? null;
        $refuse = function (int $status, string $code) use ($key, $hash): array {
            $this->financeAnswers[$key] = ['order' => '0', 'hash' => $hash, 'status' => $status, 'body' => ['error' => ['code' => $code]]];
            return [$status, ['error' => ['code' => $code]]];
        };
        $normal = static fn (string $reference): string => preg_replace('/[^A-Z0-9]/', '', strtoupper($reference));
        if ($action === 'record_exception') {
            foreach ($this->exceptions as $existing) {
                if ($normal($existing['reference']) === $normal((string) $input['reference'])) {
                    return $refuse(409, 'duplicate_reference');
                }
            }
            $id = sprintf('%08x-0000-4000-8000-%012x', count($this->exceptions) + 1, $now);
            $this->exceptions[$id] = ['id' => $id, 'amount' => number_format((float) $input['amount'], 2, '.', ''), 'currency' => (string) $input['currency'], 'reference' => (string) $input['reference'],
                'valueDate' => (string) $input['valueDate'], 'reason' => (string) $input['reason'], 'state' => 'open', 'submittedBy' => $employee, 'submittedAt' => gmdate('Y-m-d\TH:i:s\Z', $now),
                'resolvedBy' => null, 'resolvedAt' => null, 'resolution' => null, 'order' => null];
        } elseif ($action === 'resolve_exception') {
            $id = (string) ($input['entryId'] ?? '');
            $entry = $this->exceptions[$id] ?? null;
            if ($entry === null) {
                return $refuse(404, 'entry_not_found');
            }
            if ($entry['state'] !== 'open') {
                return $refuse(409, 'entry_not_pending');
            }
            if ($entry['submittedBy'] === $employee) {
                return $refuse(403, 'same_employee');
            }
            $order = null;
            if ($input['resolution'] === 'recorded_on_order') {
                $orderId = (string) ($input['resolvedOrderId'] ?? '');
                $found = false;
                foreach ($this->orders[$orderId]['finance']['entries'] ?? [] as $transfer) {
                    $found = $found || ($transfer['kind'] === 'transfer_in' && $transfer['state'] !== 'rejected' && $normal($transfer['reference']) === $normal($entry['reference']));
                }
                if (!$found) {
                    return $refuse(409, 'transfer_not_recorded');
                }
                $order = ['id' => $orderId, 'number' => (string) ($this->orders[$orderId]['number'] ?? $orderId)];
            }
            $this->exceptions[$id] = array_merge($entry, ['state' => 'resolved', 'resolvedBy' => $employee, 'resolvedAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'resolution' => (string) $input['resolution'], 'order' => $order]);
        } else {
            return [400, ['error' => ['code' => 'invalid_request']]];
        }
        $body = ['protocol' => 1, 'applied' => true, 'action' => $action, 'entryId' => $id, 'listing' => $listing()];
        $this->financeAnswers[$key] = ['order' => '0', 'hash' => $hash, 'status' => 200, 'body' => $body];
        if ($this->afterException === 'answer_lost') {
            $this->afterException = null;
            return [0, []];
        }
        return [200, $body];
    }

    /** The proforma part YD SOFT 2.18.0 answers. @return array<string, mixed> */
    private function proformaSummary(string $orderId, int $now, string $role): array
    {
        $i = $this->orders[$orderId]['invoice'];
        $p = $i['pf'];
        $doc = $p['document'];
        $amounts = in_array($role, ['operator', 'documents', 'manager', 'finance'], true);
        $blockers = [];
        foreach (['proformaIssue' => 'proforma_issue', 'proformaSendMail' => 'proforma_send_mail', 'proformaCheck' => 'proforma_check', 'proformaResolve' => 'proforma_resolve',
            'proformaCancel' => 'proforma_cancel', 'totalAccept' => 'total_accept', 'advanceAccept' => 'advance_accept'] as $name => $action) {
            if ($this->legacyProforma && in_array($action, ['proforma_cancel', 'total_accept', 'advance_accept'], true)) {
                continue; // a 2.18.0 shop does not send these groups
            }
            $blockers[$name] = $this->invoiceActions ? $this->proformaBlockers($orderId, $action) : ['invoice_observe'];
        }
        $p += ['cancel' => null, 'cancelled' => []];
        $received = $i['cod'] ? (($i['partial'] ?? false) ? 20000 : ($i['cleared'] ? 21670 : 0)) : 71900;
        $money = static fn (int $c): string => number_format($c / 100, 2, '.', '');
        $legacy = $this->legacyProforma;
        return [
            'advance' => ['required' => $i['cod'] && $amounts ? '215.70' : null, 'received' => $i['cod'] && $amounts ? (($i['partial'] ?? false) ? '200.00' : ($i['cleared'] ? '216.70' : '0.00')) : null,
                'outstanding' => $i['cod'] && $amounts ? (($i['partial'] ?? false) ? '15.70' : ($i['cleared'] ? '0.00' : '215.70')) : null, 'percentage' => $i['cod'] ? 30.0 : null, 'cod' => $i['cod'],
                // From the money evidence only (2.18.0 bank transfer decision): never from the proforma. 2.19.0: a smaller
                // advance the operator accepted stays `partial` (accepted: true).
                'state' => $i['cod'] ? (($i['partial'] ?? false) ? 'partial' : ($i['cleared'] ? 'paid' : 'waiting')) : null, 'verifying' => (bool) ($i['verifying'] ?? false)]
                + ($this->legacyProforma ? [] : ['accepted' => (bool) ($i['advanceAccepted'] ?? false)]),
            'transfer' => $amounts ? ['iban' => 'RO49AAAA1B31007593840000', 'bank' => 'Banca TEST', 'beneficiary' => 'TEST Trend Home SRL', 'reference' => 'TH' . $orderId] : null,
            'state' => $p['op'],
            'document' => $doc === null ? null : ['series' => $doc['series'], 'number' => $doc['number'], 'amount' => $amounts ? '215.70' : null, 'currency' => 'RON', 'reference' => 'TH' . $orderId,
                'issuedAt' => gmdate('Y-m-d\TH:i:s\Z', $doc['at']), 'issuedBy' => $doc['by'], 'outdated' => false, 'unconfirmed' => (bool) ($doc['unconfirmed'] ?? false),
                'link' => in_array($role, ['operator', 'documents', 'manager'], true) ? 'https://cloud.smartbill.ro/core/proforma/vizualizare/?serie=PRF&numar=' . $doc['number'] : null],
            'mail' => ['state' => $p['mail'], 'at' => null, 'by' => null, 'count' => $p['mailCount']],
            'invoiced' => $p['invoiced'] === null ? null : ['invoiced' => $p['invoiced'], 'at' => gmdate('Y-m-d\TH:i:s\Z', $now)],
            'error' => null,
            'history' => array_map(static fn (array $h): array => ['kind' => $h['kind'], 'at' => gmdate('Y-m-d\TH:i:s\Z', $h['at']), 'by' => $h['by'], 'outcome' => $h['outcome'], 'series' => null, 'number' => null], array_reverse($p['history'])),
            'blockers' => $blockers,
        ] + ($legacy ? [] : [
            // YD SOFT 2.19.0 (TEST data: total 719.00, advance 215.70).
            'money' => $amounts ? ['currency' => 'RON', 'percentage' => $i['cod'] ? 30.0 : null, 'total' => '719.00', 'advanceRequired' => $i['cod'] ? '215.70' : null, 'received' => $money($received), 'refunded' => '0.00',
                'net' => $money($received), 'remaining' => $money(max(0, 71900 - $received)), 'excess' => '0.00', 'advanceGap' => $i['cod'] ? $money(max(0, 21570 - $received)) : null, 'known' => true] : null,
            'totalAcceptance' => ($i['totalAccepted'] ?? true)
                ? ['state' => 'accepted', 'at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'by' => '00000000-0000-4000-8000-0000000000a1', 'previousTotal' => $amounts ? '719.00' : null, 'total' => $amounts ? '719.00' : null, 'changes' => [], 'note' => null, 'advanceDecisionRequired' => null]
                : ['state' => 'required', 'at' => null, 'by' => null, 'previousTotal' => $amounts ? '699.00' : null, 'total' => $amounts ? '719.00' : null, 'changes' => [['kind' => 'changed', 'name' => 'Draperie Velvet']], 'note' => null, 'advanceDecisionRequired' => (bool) ($i['advanceDecision'] ?? false)],
            'advanceAcceptance' => ($i['advanceAccepted'] ?? false) ? ['at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'by' => '00000000-0000-4000-8000-0000000000a1', 'required' => $amounts ? '215.70' : null, 'received' => $amounts ? '200.00' : null, 'reason' => null, 'low' => false, 'kind' => $i['advanceKind'] ?? 'accepted'] : null,
            'lowAdvance' => false,
            'cancel' => $p['cancel'],
            'cancelled' => array_map(static fn (array $c): array => ['amount' => $amounts ? $c['amount'] : null] + $c, array_reverse($p['cancelled'])),
            'superseded' => [],
            'deadline' => $received > 0 && $i['cod'] ? ['state' => $i['document'] !== null ? 'invoiced' : 'open', 'firstCollection' => '2026-10-24', 'deadline' => '2026-11-15', 'daysLeft' => $i['document'] !== null ? null : 22, 'level' => $i['document'] !== null ? null : 'none']
                : ['state' => 'none', 'firstCollection' => null, 'deadline' => null, 'daysLeft' => null, 'level' => null],
            'cod' => $amounts ? ['state' => 'none', 'awb' => null, 'awbCod' => null, 'expected' => null, 'status' => null] : null,
            'shipping' => ($i['shippingMismatch'] ?? false) ? ['state' => 'mismatch', 'code' => 'shipping_cost_mismatch', 'current' => $amounts ? '25.00' : null, 'expected' => $amounts ? '35.00' : null]
                : ['state' => 'match', 'code' => null, 'current' => $amounts ? '25.00' : null, 'expected' => $amounts ? '25.00' : null],
        ]);
    }

    /** @var array<string, array<string, mixed>> operation answers by employee|key (YD SOFT 2.20.0 Operations) */
    public array $operationAnswers = [];
    /** Feature switches of the TEST shop's operations. */
    public array $operationFeatures = ['reactivation' => 'enforce', 'awb' => 'enforce', 'cardRefunds' => 'manual'];
    /** The next AWB outcome: created | refused | unknown (FAN Courier did not answer) | lost (created, answer lost). */
    public string $awbOutcome = 'created';
    /** @var array<string, int> AWBs FAN Courier made per order (TEST) */
    public array $fanAwbs = [];

    /**
     * YD SOFT 2.20.0 Operations with TEST data: reactivation, the FAN Courier AWB (one per order, the collection is what
     * is still owed), the NETOPIA card refund request, record and verification (four eyes).
     *
     * @param array<string, mixed> $input
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function operation(string $route, string $orderId, array $input, int $now): array
    {
        $employee = (string) ($input['actor']['employeeId'] ?? '');
        $role = (string) ($input['actor']['role'] ?? '');
        $this->orders[$orderId]['ops'] ??= [];
        $o = &$this->orders[$orderId]['ops'];
        $o += ['status' => 'processing', 'awb' => null, 'refunds' => [], 'reactivations' => [], 'address' => [], 'owed' => '503.30', 'net' => '215.70', 'netopia' => 0, 'exceptions' => [], 'ledger' => [], 'invoice' => true];
        $key = $employee . '|' . ($input['idempotencyKey'] ?? '');
        if ($route === 'operation-status') {
            $stored = $this->operationAnswers[$key] ?? null;
            if ($stored === null || $stored['order'] !== $orderId) {
                return [200, ['protocol' => 1, 'state' => 'absent']];
            }
            return [200, ['protocol' => 1, 'state' => $stored['status'] === 200 ? 'applied' : ($stored['status'] === 202 ? 'unknown' : 'rejected'), 'status' => $stored['status'], 'result' => $stored['body']]];
        }
        $action = (string) ($input['action'] ?? '');
        $roles = ['order_reactivate' => ['operator', 'manager'], 'awb_create' => ['documents', 'manager'], 'awb_label' => ['documents', 'manager'], 'awb_address_task' => ['documents', 'manager'],
            'awb_address_done' => ['documents', 'manager'], 'card_refund_full' => ['operator', 'manager'], 'card_refund_record' => ['finance'], 'card_refund_verify' => ['finance'], 'operations_state' => ['operator', 'documents', 'manager', 'finance']];
        if (!isset($roles[$action]) || !in_array($role, $roles[$action], true)) {
            return [400, ['error' => ['code' => 'invalid_request']]];
        }
        $state = fn (): array => $this->operationState($orderId, $now);
        if ($action === 'operations_state') {
            return [200, $state()];
        }
        if ($action === 'awb_label') {
            return $o['awb'] === null ? [409, ['error' => ['code' => 'awb_missing']]] : [200, ['protocol' => 1, 'awb' => $o['awb']['number'], 'pdf' => base64_encode('%PDF-1.4 TEST AWB LABEL ' . $o['awb']['number'])]];
        }
        $request = $input;
        unset($request['requestId'], $request['actor']);
        $hash = hash('sha256', (string) json_encode($request));
        if (isset($this->operationAnswers[$key])) {
            $stored = $this->operationAnswers[$key];
            return $stored['hash'] === $hash && $stored['order'] === $orderId ? [$stored['status'], $stored['body']] : [409, ['error' => ['code' => 'idempotency_conflict']]];
        }
        $keep = function (int $status, array $body) use ($key, $orderId, $hash): array {
            $this->operationAnswers[$key] = ['order' => $orderId, 'hash' => $hash, 'status' => $status, 'body' => $body];
            return [$status, $body];
        };
        $refuse = fn (string $code): array => $keep(409, ['error' => ['code' => $code], 'blockers' => [$code], 'state' => $state()]);
        $done = fn (string $result, int $status = 200): array => $keep($status, ['protocol' => 1, 'applied' => $status === 200, 'action' => $action, 'result' => $result, 'state' => $state()]);
        $feature = ['order_reactivate' => 'reactivation', 'card_refund_full' => 'cardRefunds', 'card_refund_record' => 'cardRefunds', 'card_refund_verify' => 'cardRefunds'][$action] ?? 'awb';
        if (($this->operationFeatures[$feature] ?? 'off') === 'off') {
            return $keep(409, ['error' => ['code' => $feature === 'awb' ? 'awb_disabled' : ($feature === 'cardRefunds' ? 'card_refund_disabled' : 'reactivation_disabled')]]);
        }
        if ($action === 'order_reactivate') {
            if ($o['status'] !== 'cancelled') {
                return $refuse('order_not_cancelled');
            }
            if (isset($input['exceptionEntryId']) && !in_array($input['exceptionEntryId'], $o['exceptions'], true)) {
                return $refuse('payment_not_found');
            }
            $o['status'] = 'on-hold';
            $o['reactivations'][] = ['at' => $now, 'by' => $employee, 'reason' => (string) $input['reason'], 'exception' => $input['exceptionEntryId'] ?? null];
            return $done('reactivated');
        }
        if ($action === 'awb_create') {
            if (!$o['invoice']) {
                return $refuse('invoice_missing');
            }
            if ($o['awb'] !== null) {
                return $refuse('awb_exists');
            }
            if (($input['expectedCod'] ?? null) !== $o['owed']) {
                return $refuse('cod_changed');
            }
            $outcome = $this->awbOutcome;
            $this->awbOutcome = 'created';
            if ($outcome === 'refused') {
                return $keep(409, ['error' => ['code' => 'awb_refused'], 'blockers' => ['awb_refused'], 'state' => $state()]);
            }
            $this->fanAwbs[$orderId] = ($this->fanAwbs[$orderId] ?? 0) + 1;
            if ($outcome === 'unknown') {
                $o['awbUnknown'] = true;
                return $done('unknown', 202);
            }
            $o['awb'] = ['number' => (string) (2150900000000 + array_sum($this->fanAwbs)), 'parcels' => (int) $input['parcels'], 'cod' => $o['owed'], 'at' => $now];
            $answer = $done('created');
            return $outcome === 'lost' ? [0, []] : $answer;
        }
        if ($action === 'awb_address_task' || $action === 'awb_address_done') {
            if ($o['awb'] === null) {
                return $refuse('awb_missing');
            }
            $open = $o['address'] !== [] && end($o['address'])['kind'] === 'task';
            if ($action === 'awb_address_done' && !$open) {
                return $refuse('address_task_missing');
            }
            if ($action === 'awb_address_task' && $open) {
                return $refuse('address_task_open');
            }
            $o['address'][] = ['kind' => $action === 'awb_address_task' ? 'task' : 'done', 'at' => $now, 'by' => $employee];
            return $done($action === 'awb_address_task' ? 'panel_required' : 'panel_declared');
        }
        if ($action === 'card_refund_full') {
            if ($o['netopia'] < 1) {
                return $refuse('card_payment_unverified');
            }
            foreach ($o['refunds'] as $r) {
                if ($r['state'] === 'manual_required') {
                    return $done('already_requested');
                }
            }
            $o['refunds'][] = ['state' => 'manual_required', 'at' => $now, 'by' => $employee, 'amount' => $o['net'], 'transactions' => $o['netopia'], 'completedAt' => null, 'mailed' => false];
            return $done('manual_required');
        }
        $open = null;
        foreach ($o['refunds'] as $i => $r) {
            if ($r['state'] === 'manual_required') {
                $open = $i;
            }
        }
        if ($open === null) {
            return $refuse('refund_request_missing');
        }
        if ($action === 'card_refund_record') {
            foreach ($o['ledger'] as $entry) {
                if ($entry['state'] === 'submitted') {
                    return $refuse('refund_pending');
                }
            }
            $id = sprintf('00000000-0000-4000-8000-%012d', count($o['ledger']) + 1);
            $o['ledger'][] = ['id' => $id, 'amount' => (string) $input['amount'], 'state' => 'submitted', 'by' => $employee];
            return $keep(200, ['protocol' => 1, 'applied' => true, 'action' => $action, 'result' => 'refund_recorded', 'state' => $state(), 'entryId' => $id]);
        }
        foreach ($o['ledger'] as $i => $entry) {
            if ($entry['id'] === ($input['entryId'] ?? null)) {
                if ($entry['state'] !== 'submitted') {
                    return $refuse('entry_not_pending');
                }
                if ($entry['by'] === $employee) {
                    return $keep(403, ['error' => ['code' => 'same_employee']]);
                }
                $o['ledger'][$i]['state'] = ($input['decision'] ?? '') === 'verify' ? 'verified' : 'rejected';
                if ($o['ledger'][$i]['state'] === 'verified') {
                    $o['refunds'][$open]['state'] = 'completed';
                    $o['refunds'][$open]['completedAt'] = $now;
                    $o['refunds'][$open]['mailed'] = true;
                    return $done('refund_verified');
                }
                return $done('refund_rejected');
            }
        }
        return $refuse('entry_not_found');
    }

    /** @return array<string, mixed> */
    private function operationState(string $orderId, int $now): array
    {
        $o = $this->orders[$orderId]['ops'];
        $iso = static fn (int $t): string => gmdate('Y-m-d\TH:i:s\Z', $t);
        return ['protocol' => 1, 'readAt' => $iso($now), 'features' => $this->operationFeatures, 'order' => ['status' => $o['status'], 'paymentMethod' => $o['netopia'] > 0 ? 'netopiapayments' : 'cod'],
            'awb' => ['state' => $o['awb'] !== null ? 'created' : (($o['awbUnknown'] ?? false) ? 'unknown' : 'none'), 'number' => $o['awb']['number'] ?? null, 'createdAt' => isset($o['awb']) ? $iso($o['awb']['at']) : null,
                'status' => null, 'delivered' => false, 'cod' => $o['owed'], 'codCheck' => ['state' => $o['awb'] !== null ? 'match' : 'none', 'awb' => $o['awb']['number'] ?? null, 'awbCod' => $o['awb']['cod'] ?? null, 'expected' => $o['owed'], 'status' => null],
                'blockers' => $o['awb'] !== null ? ['awb_exists'] : [], 'address' => array_map(static fn (array $a): array => ['kind' => $a['kind'], 'at' => $iso($a['at']), 'by' => $a['by']], $o['address']), 'pickup' => 'not_tracked'],
            'cardRefund' => ['available' => $o['netopia'] > 0, 'transactions' => $o['netopia'], 'requests' => array_map(static fn (array $r): array => ['state' => $r['state'], 'at' => $iso($r['at']), 'by' => $r['by'], 'amount' => $r['amount'], 'currency' => 'RON',
                'transactions' => $r['transactions'], 'reason' => 'no_refund_api', 'completedAt' => $r['completedAt'] === null ? null : $iso($r['completedAt']), 'mailed' => $r['mailed']], $o['refunds'])],
            'reactivations' => array_map(static fn (array $r): array => ['at' => $iso($r['at']), 'by' => $r['by'], 'reason' => $r['reason'], 'payment' => $r['exception'] !== null], $o['reactivations'])];
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
