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
 * Manager routes of the production QR authority (cookie session + CSRF for mutations):
 *   GET  /orders/{globalOrderId}/production-qr
 *   POST /orders/{globalOrderId}/production-qr/rotate  {expectedQrRevision, reason}  (Idempotency-Key)
 */
final readonly class ProductionQrController
{
    public function __construct(
        private ProductionQrService $service,
        private AuthenticationService $auth,
        private CsrfGuard $csrf,
        private Config $config,
        private RequestContext $context,
    ) {
    }

    public function handle(Request $request, string $globalOrderId, string $action): Response
    {
        $session = $this->authenticate($request);
        if ($request->method === 'GET' && $action === '') {
            return Response::json($this->service->inspect($session->employee, $globalOrderId), 200, ['Cache-Control' => 'private, no-store']);
        }
        if ($request->method !== 'POST' || $action !== 'rotate') {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
        }
        $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
        $payload = $this->service->rotate($session->employee, $globalOrderId, $request->json(1024), $request->header('idempotency-key') ?? '', $request->requestId, $request->ipAddress, $request->userAgent);
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
