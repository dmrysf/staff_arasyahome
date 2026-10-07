<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Auth\AuthenticatedSession;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Security\CsrfGuard;

/**
 * Manager routes of the production authority control plane (cookie session + CSRF for mutations):
 *   GET  /orders/{globalOrderId}/production-authority
 *   POST /orders/{globalOrderId}/production-authority/takeover  {expectedVersion, stageId, workflowId?, workflowVersion?}
 *   POST /orders/{globalOrderId}/production-authority/release   {expectedVersion}
 * Mutations require an Idempotency-Key header.
 */
final readonly class ProductionAuthorityController
{
    public function __construct(
        private ProductionAuthorityService $service,
        private AuthenticationService $auth,
        private CsrfGuard $csrf,
        private Config $config,
        private RequestContext $context,
    ) {
    }

    public function handle(Request $request, string $globalOrderId, string $action): Response
    {
        $session = $this->authenticate($request);
        $actor = $session->employee;
        if ($request->method === 'GET' && $action === '') {
            return Response::json($this->service->inspect($actor, $globalOrderId), 200, ['Cache-Control' => 'private, no-store']);
        }
        if ($request->method !== 'POST' || !in_array($action, ['takeover', 'release'], true)) {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
        }
        $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
        $input = $request->json(1024);
        $key = $request->header('idempotency-key') ?? '';
        $payload = $action === 'takeover'
            ? $this->service->takeOver($actor, $globalOrderId, $input, $key, $request->requestId, $request->ipAddress, $request->userAgent)
            : (function () use ($input, $actor, $globalOrderId, $key, $request): array {
                if (array_keys($input) !== ['expectedVersion']) {
                    throw new ApiException(400, 'INVALID_REQUEST', 'Only expectedVersion is accepted.');
                }
                return $this->service->release($actor, $globalOrderId, $input['expectedVersion'], $key, $request->requestId, $request->ipAddress, $request->userAgent);
            })();
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
