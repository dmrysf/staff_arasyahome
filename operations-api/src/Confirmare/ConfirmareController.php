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
 *   GET  /confirmare/pool?view=mine|open|all[&source=]
 *   GET  /confirmare/orders/{globalOrderId}
 *   POST /confirmare/next                                  {source?}
 *   POST /confirmare/orders/{globalOrderId}/claim          {expectedVersion}
 *   POST /confirmare/orders/{globalOrderId}/release        {expectedVersion}
 *   POST /confirmare/orders/{globalOrderId}/reassign       {expectedVersion, employeeId}
 *   POST /confirmare/orders/{globalOrderId}/approve        {expectedVersion, customerCall, confirmedLineIds, priceVerified, reviewFingerprint}
 *   POST /confirmare/orders/{globalOrderId}/reject         {expectedVersion, reasonCode}
 *   POST /confirmare/orders/{globalOrderId}/reopen         {expectedVersion}
 *   GET  /confirmare/availability
 *   PUT  /confirmare/availability                          {available, employeeId?}
 *   GET  /confirmare/alerts                                (supervisors)
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
    ];

    public function __construct(
        private ConfirmareService $service,
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
        if ($path === '/confirmare/alerts' && $request->method === 'GET') {
            return $this->json($this->service->alerts($actor));
        }
        if ($path === '/confirmare/availability' && $request->method === 'GET') {
            return $this->json($this->service->availability($actor));
        }
        if (preg_match('#^/confirmare/orders/([^/]{1,600})$#D', $path, $m) === 1 && $request->method === 'GET') {
            return $this->json($this->service->detail($actor, rawurldecode($m[1])));
        }
        $key = $request->header('idempotency-key') ?? '';
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
        if (preg_match('#^/confirmare/orders/([^/]{1,600})/(claim|release|reassign|approve|reject|reopen)$#D', $path, $m) === 1) {
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
                default => $this->service->reopen($actor, $globalId, $version, $key, $request->requestId),
            });
        }
        throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
    }

    /** @param list<string> $allowed @return array<string, mixed> */
    private function body(Request $request, array $allowed): array
    {
        $input = $request->json(1024);
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only ' . implode(', ', $allowed) . ' are accepted.');
        }
        return $input;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): Response
    {
        return Response::json($payload, 200, ['Cache-Control' => 'private, no-store']);
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
