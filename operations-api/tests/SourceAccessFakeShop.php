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
    public bool $financeRecord = true;
    public string $financeRefunds = 'manual';
    /** @var array<string, array{order: string, hash: string, status: int, body: array<string, mixed>}> employee|key => stored answer */
    public array $financeAnswers = [];
    /** SmartBill knobs: the outcome of the next SmartBill call; 'answer_lost' / 'crash' after the shop ran an action. */
    public string $invoiceOutcome = 'created';
    public ?string $afterInvoice = null;
    public bool $invoiceActions = true;
    /** Overrides the SmartBill link of the next invoice states (a shop answering with a foreign link). */
    public ?string $invoiceLinkOverride = null;
    /** @var array<string, array{order: string, hash: string, status: int, body: array<string, mixed>}> employee|key => stored answer */
    public array $invoiceAnswers = [];
    /** Documents "made in SmartBill" by this TEST shop: orderId => count. */
    public array $smartbillDocuments = [];
    /** E-mails "sent by SmartBill" by this TEST shop: orderId => count. */
    public array $smartbillMails = [];

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
        $routes = ['detail', 'catalog', 'product', 'preview', 'apply', 'edit-status', 'finance', 'finance-action', 'finance-status', 'invoice', 'invoice-action', 'invoice-status'];
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
        if (str_starts_with($action, 'invoice')) {
            [$status, $payload] = $this->invoice($action, (string) $order['id'], $input, $now);
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
            return [$status, ['error' => ['code' => $code], 'revision' => $revision]];
        };
        if (($input['expectedRevision'] ?? null) !== $revision) {
            return $refuse('revision_changed');
        }
        $f = &$this->orders[$orderId]['finance'];
        $f['entries'] ??= [];
        $numbers = $this->financeNumbers($orderId);
        $entryId = null;
        $action = (string) ($input['action'] ?? '');
        if ($action === 'submit_transfer' || $action === 'submit_refund') {
            $kind = $action === 'submit_transfer' ? 'transfer_in' : 'refund_out';
            $amount = (float) ($input['amount'] ?? 0);
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
                if ($amount > max(0, $numbers['total'] - $numbers['received']) + 0.001) {
                    return $refuse('amount_exceeds_balance');
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
                'submittedAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'decidedBy' => null, 'decidedAt' => null, 'decisionCode' => null];
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
            'advance' => $n['cod'] ? ['percentage' => 30, 'required' => $money($n['required']), 'collected' => $money($n['received']), 'outstanding' => $money(max(0, $n['required'] - $n['received'])), 'onDelivery' => $money(max(0, $n['total'] - $n['received']))] : null,
            'balance' => ['outstanding' => $n['cod'] ? null : $money(max(0, $n['total'] - $n['received'])), 'refundLiability' => $money($n['liability']), 'acknowledged' => $n['acknowledged']],
            'financial' => ['state' => $n['state'], 'basis' => $n['basis'], 'attention' => $n['attention']],
            'entries' => array_map(static fn (array $e): array => array_diff_key($e, ['pricing' => true]), $f['entries'] ?? []),
            'capabilities' => ['record' => $this->financeRecord, 'refunds' => $this->financeRefunds, 'electronicRefund' => false],
        ];
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
        $i += ['cleared' => true, 'documentType' => 'invoice', 'autoEmail' => false, 'document' => null, 'op' => 'none', 'opKey' => null, 'mail' => 'none', 'mailCount' => 0, 'history' => []];
        if ($route === 'invoice') {
            return in_array($role, ['documents', 'manager', 'finance', 'viewer'], true) ? [200, $this->invoiceSummary($orderId, $now, (string) $role)] : [400, ['error' => ['code' => 'invalid_request']]];
        }
        if (!in_array($role, ['documents', 'manager'], true)) {
            return [400, ['error' => ['code' => 'invalid_request']]];
        }
        if ($route === 'invoice-status') {
            $author = (string) ($input['actionEmployeeId'] ?? $employee);
            $stored = $this->invoiceAnswers[$author . '|' . ($input['idempotencyKey'] ?? '')] ?? null;
            if ($stored === null || $stored['order'] !== $orderId) {
                if ($i['opKey'] === $author . '|' . ($input['idempotencyKey'] ?? '')) {
                    return [200, ['state' => $i['op'] === 'issued' ? 'applied' : 'unknown']];
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
        if ($action === 'reissue' && $i['document'] === null) {
            $blockers[] = 'document_missing';
        }
        if (!$i['cleared']) {
            $blockers[] = 'payment_not_cleared';
        }
        return $blockers;
    }

    private function invoiceRevision(string $orderId): string
    {
        $i = $this->orders[$orderId]['invoice'];
        unset($i['history']);
        return 'sha256:' . hash('sha256', (string) json_encode($i));
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
            'order' => ['id' => (string) $o['id'], 'number' => (string) ($o['number'] ?? $o['id']), 'status' => 'processing', 'total' => '719.00', 'currency' => 'RON', 'billingEmail' => true],
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
        ];
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
