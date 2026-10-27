<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Auth\AuthenticatedSession;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Security\CsrfGuard;

/**
 * Confirmare routes (cookie session; mutations need CSRF and an Idempotency-Key):
 *   GET  /confirmare/pool?view=mine|open|all|followup[&source=]
 *   GET  /confirmare/handoff[?source=]                     production handoff list (API 2.42.0; document permission, no Confirmare scope)
 *   GET  /confirmare/orders/{globalOrderId}               (adds `sourceAccess` {configured, read, edit}, API 2.40.0)
 *   GET  /confirmare/orders/{globalOrderId}/source        the shop's own order view (API 2.40.0, audited, no-store)
 *   GET  /confirmare/orders/{globalOrderId}/source/catalog        what can be changed (Phase 4B, access `edit`)
 *   POST /confirmare/orders/{globalOrderId}/source/product        {sku}: one product of the same shop by exact SKU
 *   POST /confirmare/orders/{globalOrderId}/source/preview        {expectedVersion, operations}: computed by the shop, never saved
 *   POST /confirmare/orders/{globalOrderId}/source/apply          {expectedVersion, operations, previewToken} + Idempotency-Key
 *   POST /confirmare/orders/{globalOrderId}/source/reconcile      {idempotencyKey}: settles a change whose answer was lost
 *   POST /confirmare/next                                  {source?}
 *   POST /confirmare/orders/{globalOrderId}/claim          {expectedVersion}
 *   POST /confirmare/orders/{globalOrderId}/release        {expectedVersion}
 *   POST /confirmare/orders/{globalOrderId}/reassign       {expectedVersion, employeeId}
 *   POST /confirmare/orders/{globalOrderId}/approve        {expectedVersion, customerCall, confirmedLineIds, priceVerified, reviewFingerprint}
 *   POST /confirmare/orders/{globalOrderId}/reject         {expectedVersion, reasonCode}
 *   POST /confirmare/orders/{globalOrderId}/reopen         {expectedVersion}
 *   POST /confirmare/orders/{globalOrderId}/contact        {expectedVersion, outcome, reviewFingerprint}   (API 2.39.0)
 *   GET  /confirmare/team                                  (supervisors, API 2.39.0)
 *   GET  /confirmare/availability
 *   PUT  /confirmare/availability                          {available, employeeId?}
 *   GET  /confirmare/alerts                                (supervisors)
 *   GET  /confirmare/finance?task=&source=                 the finance tasks of the actor's sources (API 2.41.0)
 *   GET  /confirmare/orders/{globalOrderId}/finance        the shop's financial summary (audited, no-store)
 *   POST /confirmare/orders/{globalOrderId}/finance/{submit-transfer|decide-transfer|acknowledge-liability|submit-refund|decide-refund}
 *                                                          {expectedRevision, ...} + Idempotency-Key (finance scopes)
 *   POST /confirmare/orders/{globalOrderId}/finance/reconcile    {idempotencyKey}: settles an action whose answer was lost
 *   GET  /confirmare/orders/{globalOrderId}/invoice        the shop's SmartBill document state (API 2.43.0, audited, no-store)
 *   POST /confirmare/orders/{globalOrderId}/invoice/{issue|send-mail|reissue|resolve}
 *                                                          {expectedRevision, ...} + Idempotency-Key (invoice scopes)
 *   POST /confirmare/orders/{globalOrderId}/invoice/reconcile    {idempotencyKey}: settles an action whose answer was lost
 *   GET  /confirmare/orders/{globalOrderId}/invoice/payment      SmartBill's paid/unpaid amounts of the final invoice next to
 *                                                          Arasya's verified money (API 2.47.0, read-only, audited)
 *   POST /confirmare/orders/{globalOrderId}/invoice/qr    {qrToken}: the shop employee scanned the production QR (API 2.44.0)
 *   POST /confirmare/orders/{globalOrderId}/proforma/{issue|send-mail|check|resolve|reconcile}  the advance proforma (2.44.0)
 *   GET  /confirmare/orders/{globalOrderId}/shop-employees  who the order can be routed to (2.44.0)
 *   POST /confirmare/orders/{globalOrderId}/shop-assignment {employeeId, expectedAssigneeId} + Idempotency-Key (2.44.0)
 *   GET  /confirmare/shop-queue                            the orders routed to me (2.44.0)
 *   GET  /confirmare/finance/transfer-exceptions?source=   the shop's bank transfers that match no order (2.44.0, finance)
 *   POST /confirmare/finance/transfer-exceptions/{record|resolve}?source=  + Idempotency-Key (finance record / approve)
 * API 2.46.0 (owner master decisions 2026-10-25, migration 043):
 *   GET  /confirmare/cycles[?source=]                      open proforma cycles of the actor's shops (timers, waiting states)
 *   GET  /confirmare/finance/bank-checks[?source=]         the latest bank checks and the proformas waiting for one
 *   POST /confirmare/finance/bank-check                    {source, coversUntil?} + Idempotency-Key (finance record/approve)
 *   POST /confirmare/orders/{globalOrderId}/cycle/advance  the order's proforma cycle now (its operator or a supervisor)
 *   POST /confirmare/orders/{globalOrderId}/cycle/retry    start the advance proforma again after a definite refusal (R1, 2026-10-26)
 *   The third `no_answer` of a cycle answers `proformaCycle` (ConfirmareCycles::afterNoAnswer).
 *   GET  /confirmare/orders/{globalOrderId}/operations     reactivation, AWB and card refund state (ConfirmareOperations)
 *   POST /confirmare/orders/{globalOrderId}/reactivate     {reason, exceptionEntryId?} + Idempotency-Key
 *   POST /confirmare/orders/{globalOrderId}/awb            {parcels, weightKg?, expectedCod} + Idempotency-Key (stage 12+)
 *   GET  /confirmare/orders/{globalOrderId}/awb/label      the FAN Courier label (application/pdf)
 *   POST /confirmare/orders/{globalOrderId}/awb/address    {step: task|done, reasonNote} + Idempotency-Key
 *   POST /confirmare/orders/{globalOrderId}/card-refund[/record|/verify]  + Idempotency-Key (NETOPIA full refund)
 *   POST /confirmare/orders/{globalOrderId}/operations/reconcile  {idempotencyKey}
 *   GET  /confirmare/awb-queue[?source=]                   orders at stage 12+ waiting for their AWB (the routed employee)
 *   GET  /confirmare/efactura[?source=&state=]             e-Factura follow-up of the invoices (issuer, invoice managers)
 *   POST /confirmare/efactura/{taskId}                     {outcome, expectedVersion}
 *   GET  /confirmare/health[?source=]                      operating counts per shop (supervisors, invoice managers, finance approvers, root)
 */
final readonly class ConfirmareController
{
    private const ORDER_FIELDS = [
        'claim' => ['expectedVersion'],
        'release' => ['expectedVersion'],
        'reassign' => ['expectedVersion', 'employeeId'],
        'approve' => ['expectedVersion', 'customerCall', 'confirmedLineIds', 'priceVerified', 'reviewFingerprint'],
        'reject' => ['expectedVersion', 'reasonCode'],
        'reopen' => ['expectedVersion'],
        'contact' => ['expectedVersion', 'outcome', 'reviewFingerprint'],
    ];

    private const SOURCE_FIELDS = [
        'product' => ['sku'],
        'preview' => ['expectedVersion', 'operations'],
        'apply' => ['expectedVersion', 'operations', 'previewToken'],
        'reconcile' => ['idempotencyKey'],
    ];

    public function __construct(
        private ConfirmareService $service,
        private ConfirmareSourceAccess $sourceAccess,
        private ConfirmareSourceEdits $sourceEdits,
        private ConfirmareFinance $finance,
        private ConfirmareInvoice $invoice,
        private FinanceTransferExceptions $exceptions,
        private ConfirmareCycles $cycles,
        private ConfirmareOperations $operations,
        private InvoiceEfactura $efactura,
        private ConfirmareHealth $health,
        private AuthenticationService $auth,
        private CsrfGuard $csrf,
        private Config $config,
        private RequestContext $context,
    ) {
    }

    public function handle(Request $request): Response
    {
        $session = $this->authenticate($request);
        $actor = $session->employee;
        $path = $request->path;
        if ($request->method === 'GET' && $path === '/confirmare/pool') {
            return $this->json($this->service->pool($actor, $request->query('source'), $request->query('view', 'open') ?? 'open'));
        }
        if ($path === '/confirmare/handoff' && $request->method === 'GET') {
            return $this->json($this->service->handoffList($actor, $request->query('source')));
        }
        if ($path === '/confirmare/alerts' && $request->method === 'GET') {
            return $this->json($this->service->alerts($actor));
        }
        if ($path === '/confirmare/team' && $request->method === 'GET') {
            return $this->json($this->service->team($actor));
        }
        if ($path === '/confirmare/finance/transfer-exceptions' && $request->method === 'GET') {
            return $this->json($this->exceptions->list($actor, $request->query('source'), $request->ipAddress, $request->userAgent, $request->requestId));
        }
        if (preg_match('#^/confirmare/finance/transfer-exceptions/(record|resolve)$#D', $path, $m) === 1) {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            return $this->json($this->exceptions->act($actor, $request->query('source'), $m[1] . '_exception', $request->json(1024), $request->header('idempotency-key') ?? '', $request->ipAddress, $request->userAgent, $request->requestId));
        }
        if ($path === '/confirmare/health' && $request->method === 'GET') {
            return $this->json($this->health->metrics($actor, $request->query('source')));
        }
        if ($path === '/confirmare/awb-queue' && $request->method === 'GET') {
            return $this->json($this->operations->awbQueue($actor, $request->query('source')));
        }
        if ($path === '/confirmare/efactura' && $request->method === 'GET') {
            return $this->json($this->efactura->queue($actor, $request->query('source'), $request->query('state')));
        }
        if (preg_match('#^/confirmare/efactura/([0-9a-f-]{36})$#D', $path, $m) === 1) {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            return $this->json($this->efactura->record($actor, $m[1], $request->json(512)));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/operations$#D', $path, $m) === 1 && $request->method === 'GET') {
            return $this->json($this->operations->read($actor, rawurldecode($m[1]), $request->requestId));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/awb/label$#D', $path, $m) === 1 && $request->method === 'GET') {
            $pdf = $this->operations->label($actor, rawurldecode($m[1]), $request->requestId);
            return Response::file($pdf, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="awb.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/(reactivate|awb|awb/address|card-refund|card-refund/record|card-refund/verify|operations/reconcile)$#D', $path, $m) === 1) {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            $globalId = rawurldecode($m[1]);
            // The one-click NETOPIA refund carries no field: an empty JSON object is its whole body.
            $input = trim($request->body) === '{}' && $m[2] === 'card-refund' ? [] : $request->json(2048);
            if ($m[2] === 'operations/reconcile') {
                return $this->json($this->operations->reconcile($actor, $globalId, $input['idempotencyKey'] ?? null, $request->requestId));
            }
            if ($m[2] === 'awb/address') {
                $step = $input['step'] ?? null;
                if (!in_array($step, ['task', 'done'], true)) {
                    throw new ApiException(422, 'VALIDATION_FAILED', 'step must be task or done.', ['field' => 'step']);
                }
                unset($input['step']);
                return $this->json($this->operations->act($actor, $globalId, 'awb_address_' . $step, $input, $request->header('idempotency-key') ?? '', $request->requestId));
            }
            $kind = ['reactivate' => 'order_reactivate', 'awb' => 'awb_create', 'card-refund' => 'card_refund_full', 'card-refund/record' => 'card_refund_record', 'card-refund/verify' => 'card_refund_verify'][$m[2]];
            return $this->json($this->operations->act($actor, $globalId, $kind, $input, $request->header('idempotency-key') ?? '', $request->requestId));
        }
        if ($path === '/confirmare/cycles' && $request->method === 'GET') {
            return $this->json($this->cycles->attention($actor, $request->query('source')));
        }
        if ($path === '/confirmare/finance/bank-checks' && $request->method === 'GET') {
            return $this->json($this->cycles->bankChecks($actor, $request->query('source')));
        }
        if ($path === '/confirmare/finance/bank-check') {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            $input = $request->json(512);
            return $this->json($this->cycles->recordBankCheck($actor, $input['source'] ?? null, $input, $request->header('idempotency-key') ?? '', $request->requestId));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/cycle/retry$#D', $path, $m) === 1) {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            return $this->json($this->cycles->retryForActor($actor, rawurldecode($m[1]), $request->requestId));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/cycle/advance$#D', $path, $m) === 1) {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            return $this->json($this->cycles->advanceForActor($actor, rawurldecode($m[1]), $request->requestId));
        }
        if ($path === '/confirmare/finance' && $request->method === 'GET') {
            return $this->json($this->finance->queue($actor, $request->query('task'), $request->query('source')));
        }
        if ($path === '/confirmare/availability' && $request->method === 'GET') {
            return $this->json($this->service->availability($actor));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})$#D', $path, $m) === 1 && $request->method === 'GET') {
            $detail = $this->service->detail($actor, rawurldecode($m[1]));
            $capabilities = $this->sourceAccess->capabilities($actor, rawurldecode($m[1]));
            return $this->json($detail + ['cycle' => $this->cycles->viewForActor($actor, rawurldecode($m[1])), 'sourceAccess' => $capabilities + $this->sourceEdits->state($actor, rawurldecode($m[1]), $capabilities['read']), 'finance' => $this->finance->capabilities($actor, rawurldecode($m[1])), 'invoice' => $this->invoice->capabilities($actor, rawurldecode($m[1]))]);
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/shop-employees$#D', $path, $m) === 1) {
            if ($request->method !== 'GET') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            return $this->json($this->invoice->shopCandidates($actor, rawurldecode($m[1])));
        }
        if ($path === '/confirmare/shop-queue' && $request->method === 'GET') {
            return $this->json($this->invoice->shopQueue($actor));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/finance$#D', $path, $m) === 1) {
            if ($request->method !== 'GET') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            return $this->json($this->finance->read($actor, rawurldecode($m[1]), $request->requestId));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/invoice$#D', $path, $m) === 1) {
            if ($request->method !== 'GET') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            return $this->json($this->invoice->read($actor, rawurldecode($m[1]), $request->requestId));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/invoice/payment$#D', $path, $m) === 1) {
            if ($request->method !== 'GET') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            return $this->json($this->invoice->payment($actor, rawurldecode($m[1]), $request->requestId));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/source$#D', $path, $m) === 1) {
            if ($request->method !== 'GET') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            return $this->json($this->sourceAccess->read($actor, rawurldecode($m[1]), $request->requestId));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/source/catalog$#D', $path, $m) === 1) {
            if ($request->method !== 'GET') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            return $this->json($this->sourceEdits->catalog($actor, rawurldecode($m[1]), $request->requestId));
        }
        $key = $request->header('idempotency-key') ?? '';
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/finance/(submit-transfer|decide-transfer|acknowledge-liability|submit-refund|decide-refund|reconcile)$#D', $path, $m) === 1) {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            $globalId = rawurldecode($m[1]);
            if ($m[2] === 'reconcile') {
                $input = $this->body($request, ['idempotencyKey']);
                return $this->json($this->finance->reconcile($actor, $globalId, $input['idempotencyKey'] ?? null, $request->requestId));
            }
            $input = $request->json(2048);
            return $this->json($this->finance->act($actor, $globalId, str_replace('-', '_', $m[2]), $input, $key, $request->requestId));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/proforma/(issue|send-mail|check|resolve|reconcile|cancel|accept-total|accept-advance)$#D', $path, $m) === 1) {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            $globalId = rawurldecode($m[1]);
            if ($m[2] === 'reconcile') {
                $input = $this->body($request, ['idempotencyKey']);
                return $this->json($this->invoice->reconcile($actor, $globalId, $input['idempotencyKey'] ?? null, $request->requestId));
            }
            $input = $request->json(2048);
            return $this->json($this->invoice->proforma($actor, $globalId, ConfirmareInvoice::PROFORMA_ROUTES[$m[2]], $input, $key, $request->requestId));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/shop-assignment$#D', $path, $m) === 1) {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            return $this->json($this->invoice->assignShop($actor, rawurldecode($m[1]), $request->json(1024), $key));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/invoice/qr$#D', $path, $m) === 1) {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            $input = $this->body($request, ['qrToken']);
            return $this->json($this->invoice->verifyQr($actor, rawurldecode($m[1]), $input['qrToken'] ?? null, $request->requestId));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/invoice/(issue|send-mail|reissue|resolve|reconcile)$#D', $path, $m) === 1) {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            $globalId = rawurldecode($m[1]);
            if ($m[2] === 'reconcile') {
                $input = $this->body($request, ['idempotencyKey']);
                return $this->json($this->invoice->reconcile($actor, $globalId, $input['idempotencyKey'] ?? null, $request->requestId));
            }
            $input = $request->json(2048);
            return $this->json($this->invoice->act($actor, $globalId, str_replace('-', '_', $m[2]), $input, $key, $request->requestId));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/source/(product|preview|apply|reconcile)$#D', $path, $m) === 1) {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            $globalId = rawurldecode($m[1]);
            $input = $this->body($request, self::SOURCE_FIELDS[$m[2]], 12288);
            return $this->json(match ($m[2]) {
                'product' => $this->sourceEdits->product($actor, $globalId, $input['sku'] ?? null, $request->requestId),
                'preview' => $this->sourceEdits->preview($actor, $globalId, $input['expectedVersion'] ?? null, $input['operations'] ?? null, $request->requestId),
                'apply' => $this->sourceEdits->apply($actor, $globalId, $input['expectedVersion'] ?? null, $input['operations'] ?? null, $input['previewToken'] ?? null, $key, $request->requestId),
                default => $this->sourceEdits->reconcile($actor, $globalId, $input['idempotencyKey'] ?? null, $request->requestId),
            });
        }
        if ($path === '/confirmare/next' && $request->method === 'POST') {
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            $input = $this->body($request, ['source']);
            $source = $input['source'] ?? null;
            if ($source !== null && !is_string($source)) {
                throw new ApiException(400, 'INVALID_REQUEST', 'source must be a string.');
            }
            return $this->json($this->service->next($actor, $source, $key, $request->requestId));
        }
        if ($path === '/confirmare/availability' && $request->method === 'PUT') {
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            $input = $this->body($request, ['available', 'employeeId']);
            $employeeId = $input['employeeId'] ?? null;
            if ($employeeId !== null && !is_string($employeeId)) {
                throw new ApiException(400, 'INVALID_REQUEST', 'employeeId must be a string.');
            }
            return $this->json($this->service->setAvailability($actor, $employeeId, $input['available'] ?? null, $key, $request->requestId));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/(claim|release|reassign|approve|reject|reopen|contact)$#D', $path, $m) === 1) {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            $globalId = rawurldecode($m[1]);
            $input = $this->body($request, self::ORDER_FIELDS[$m[2]]);
            $version = $input['expectedVersion'] ?? null;
            return $this->json(match ($m[2]) {
                'claim' => $this->service->claim($actor, $globalId, $version, $key, $request->requestId),
                'release' => $this->service->release($actor, $globalId, $version, $key, $request->requestId),
                'reassign' => $this->service->reassign($actor, $globalId, $version, $input['employeeId'] ?? null, $key, $request->requestId),
                'approve' => $this->service->approve($actor, $globalId, $version, array_diff_key($input, ['expectedVersion' => true]), $key, $request->requestId),
                'reject' => $this->service->reject($actor, $globalId, $version, $input['reasonCode'] ?? null, $key, $request->requestId),
                'contact' => $this->afterContact($actor, $this->service->contact($actor, $globalId, $version, $input['outcome'] ?? null, $input['reviewFingerprint'] ?? null, $key, $request->requestId), $globalId, $request->requestId),
                default => $this->service->reopen($actor, $globalId, $version, $key, $request->requestId),
            });
        }
        throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
    }

    /**
     * API 2.46.0: the third unanswered call of a cycle starts the advance proforma (never fails the recorded call).
     *
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private function afterContact(\Arasya\Operations\Employee\EmployeeIdentity $actor, array $answer, string $globalId, string $requestId): array
    {
        if (($answer['contact']['outcome'] ?? null) !== 'no_answer') {
            return $answer;
        }
        return $answer + ['proformaCycle' => $this->cycles->afterNoAnswerFor($actor, $globalId, $requestId)];
    }

    /** @param list<string> $allowed @return array<string, mixed> */
    private function body(Request $request, array $allowed, int $maxBytes = 1024): array
    {
        $input = $request->json($maxBytes);
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only ' . implode(', ', $allowed) . ' are accepted.');
        }
        return $input;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): Response
    {
        return Response::json($payload, 200, ['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache']);
    }

    private function authenticate(Request $request): AuthenticatedSession
    {
        $session = $this->auth->authenticate(
            $request->cookie($this->config->cookieName()) ?? '',
            $request->ipAddress,
            $request->userAgent,
            $request->requestId,
        );
        $this->context->authenticatedAs($session->employee->employeeUuid);
        return $session;
    }
}
