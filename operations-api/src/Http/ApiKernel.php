<?php

declare(strict_types=1);

namespace Arasya\Operations\Http;

use Arasya\Operations\Activity\ActivityController;
use Arasya\Operations\Integration\SourceIngestionController;
use Arasya\Operations\Management\ManagementController;
use Arasya\Operations\Order\OperationalOrderController;
use Arasya\Operations\Security\CookiePolicy;
use Arasya\Operations\Support\StructuredLogger;
use Throwable;

final readonly class ApiKernel
{
    public function __construct(
        private AuthController $auth,
        private HealthController $health,
        private CorsPolicy $cors,
        private StructuredLogger $logger,
        private CookiePolicy $cookies,
        private RequestContext $context,
        private ?ProductionWorkflowController $workflow = null,
        private ?OperationalOrderController $orders = null,
        private ?ActivityController $activity = null,
        private ?SourceIngestionController $sources = null,
        private ?ManagementController $management = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        $this->context->reset();
        try {
            $preflight = $this->cors->preflight($request);
            if ($preflight !== null) {
                return $this->secure($preflight, $request);
            }
            // Signed server-to-server source routes carry no browser credentials and no Origin.
            if (!str_starts_with($request->path, '/integrations/')) {
                $this->cors->requireUnsafeOrigin($request);
            }
            $response = match ($request->method . ' ' . $request->path) {
                'GET /health' => $this->health->show(),
                'POST /auth/login' => $this->auth->login($request),
                'POST /auth/logout' => $this->auth->logout($request),
                'GET /auth/session' => $this->auth->session($request),
                'POST /auth/refresh' => $this->auth->refresh($request),
                'POST /auth/password' => $this->auth->changePassword($request),
                'GET /employees/me' => $this->auth->employee($request),
                'GET /b2b/access' => $this->auth->b2bAccess($request),
                'GET /production/workflow' => $this->workflow?->show($request) ?? throw new ApiException(503, 'WORKFLOW_UNAVAILABLE', 'Production workflow is not ready.'),
                'GET /orders/mine' => $this->ordersController()->listMine($request),
                'GET /orders/lookup' => $this->ordersController()->lookup($request),
                'POST /orders/resolve-qr' => $this->ordersController()->resolveQr($request),
                'GET /activity/mine' => ($this->activity ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Activity API is not ready.'))->listMine($request),
                default => $this->matchDynamicRoutes($request),
            };
            $this->logger->log('info', 'http_request', $request->requestId, ['route' => $request->path, 'method' => $request->method, 'status' => $response->status, ...$this->context->logContext()]);
            return $this->secure($response, $request);
        } catch (ApiException $error) {
            $this->logger->log('warning', 'api_error', $request->requestId, ['route' => $request->path, 'method' => $request->method, 'status' => $error->status, 'code' => $error->errorCode, ...$this->context->logContext()]);
            $response = Response::json(['error' => ['code' => $error->errorCode, 'message' => $error->getMessage(), 'requestId' => $request->requestId]], $error->status);
            if (in_array($error->errorCode, ['SESSION_EXPIRED', 'ACCOUNT_INACTIVE'], true)) {
                $response = $response->withHeaders(['Set-Cookie' => $this->cookies->clear()]);
            }
            return $this->secure($response, $request);
        } catch (Throwable $error) {
            $this->logger->log('error', 'internal_error', $request->requestId, ['route' => $request->path, 'method' => $request->method, 'status' => 500, 'exception' => $error::class, ...$this->context->logContext()]);
            return $this->secure(Response::json(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'The service could not complete the request.', 'requestId' => $request->requestId]], 500), $request);
        }
    }

    private function matchDynamicRoutes(Request $request): Response
    {
        if (str_starts_with($request->path, '/management/')) {
            return ($this->management ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Management API is not ready.'))->handle($request);
        }
        if (preg_match('#^/orders/([^/]{1,600})(?:/(claim|transition))?$#D', $request->path, $matches) === 1) {
            $globalIdString = rawurldecode($matches[1]);
            $action = $matches[2] ?? '';
            return match (true) {
                $request->method === 'GET' && $action === '' => $this->ordersController()->show($request, $globalIdString),
                $request->method === 'POST' && $action === 'claim' => $this->ordersController()->claim($request, $globalIdString),
                $request->method === 'POST' && $action === 'transition' => $this->ordersController()->transition($request, $globalIdString),
                default => throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.'),
            };
        }
        if ($request->method === 'POST' && preg_match('#^/integrations/sources/([a-z0-9_-]{1,40})/(orders|heartbeat)$#D', $request->path, $matches) === 1) {
            $sources = $this->sources ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Source ingestion is not ready.');
            return $matches[2] === 'orders' ? $sources->ingestOrder($request, $matches[1]) : $sources->heartbeat($request, $matches[1]);
        }

        throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
    }

    private function ordersController(): OperationalOrderController
    {
        return $this->orders ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Orders API is not ready.');
    }

    private function secure(Response $response, Request $request): Response
    {
        return $response->withHeaders([
            ...$this->cors->headers($request->header('origin')),
            'Cache-Control' => $response->headers['Cache-Control'] ?? 'private, no-cache',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'X-Request-ID' => $request->requestId,
        ]);
    }
}
