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
        if ($path === '/confirmare/finance' && $request->method === 'GET') {
            return $this->json($this->finance->queue($actor, $request->query('task'), $request->query('source')));
        }
        if ($path === '/confirmare/availability' && $request->method === 'GET') {
            return $this->json($this->service->availability($actor));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})$#D', $path, $m) === 1 && $request->method === 'GET') {
            $detail = $this->service->detail($actor, rawurldecode($m[1]));
            $capabilities = $this->sourceAccess->capabilities($actor, rawurldecode($m[1]));
            return $this->json($detail + ['sourceAccess' => $capabilities + $this->sourceEdits->state($actor, rawurldecode($m[1]), $capabilities['read']), 'finance' => $this->finance->capabilities($actor, rawurldecode($m[1])), 'invoice' => $this->invoice->capabilities($actor, rawurldecode($m[1]))]);
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
                'contact' => $this->service->contact($actor, $globalId, $version, $input['outcome'] ?? null, $input['reviewFingerprint'] ?? null, $key, $request->requestId),
                default => $this->service->reopen($actor, $globalId, $version, $key, $request->requestId),
            });
        }
        throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
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
