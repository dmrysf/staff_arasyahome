<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Auth\AuthenticatedSession;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Security\CsrfGuard;

/**
 * HTTP surface of the root intervention desk (API 2.57.0), /management/interventions:
 *
 *   GET  /management/interventions?view=stuck|all&number=     the orders waiting for someone, and the unstaffed sources
 *   GET  /management/interventions/orders/{globalOrderId}     one order: state, history, money, proforma, finance request
 *   POST /management/interventions/orders/{globalOrderId}/{action}   one action (RootInterventionService::ACTIONS)
 *
 * The actor comes from the session cookie; every POST needs the CSRF token, an Idempotency-Key and the exact fields.
 * Authorization (root only, Dashboard application) lives in RootInterventionService.
 */
final readonly class RootInterventionController
{
    public function __construct(
        private RootInterventionService $service,
        private AuthenticationService $auth,
        private CsrfGuard $csrf,
        private Config $config,
        private RequestContext $context,
    ) {
    }

    public function handle(Request $request): Response
    {
        $path = substr($request->path, strlen('/management/interventions'));
        $session = $this->session($request);
        $actor = $session->employee;
        if ($request->method === 'GET' && $path === '') {
            return Response::json($this->service->overview($actor, $request->query('view') ?? 'stuck', $request->query('number')));
        }
        if ($request->method === 'GET' && preg_match('#^/orders/([^/]{1,600})$#D', $path, $m) === 1) {
            return Response::json($this->service->order($actor, rawurldecode($m[1]), $request->requestId));
        }
        if ($request->method === 'POST' && preg_match('#^/orders/([^/]{1,600})/([a-z-]{3,40})$#D', $path, $m) === 1) {
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
            $key = $request->header('idempotency-key') ?? '';
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{15,90}$/D', $key) !== 1) {
                throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
            }
            return Response::json($this->service->act($actor, rawurldecode($m[1]), $m[2], $request->json(32_768), $key, $request->requestId));
        }
        throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
    }

    private function session(Request $request): AuthenticatedSession
    {
        $session = $this->auth->authenticate($request->cookie($this->config->cookieName()) ?? '', $request->ipAddress, $request->userAgent, $request->requestId);
        $this->context->authenticatedAs($session->employee->employeeUuid);
        return $session;
    }
}
