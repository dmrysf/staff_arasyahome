<?php

declare(strict_types=1);

namespace Arasya\Operations\Http;

use Arasya\Operations\Activity\ActivityController;
use Arasya\Operations\B2B\AccountController;
use Arasya\Operations\B2B\CompanyController;
use Arasya\Operations\B2B\OrderController;
use Arasya\Operations\B2B\ProjectController;
use Arasya\Operations\Integration\SourceIngestionController;
use Arasya\Operations\Management\ManagementController;
use Arasya\Operations\Order\OperationalOrderController;
use Arasya\Operations\Quality\QualityController;
use Arasya\Operations\Cutting\CuttingController;
use Arasya\Operations\Security\CookiePolicy;
use Arasya\Operations\Support\SafeExceptionContext;
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
        private ?CompanyController $b2bCompanies = null,
        private ?OrderController $b2bOrders = null,
        private ?AccountController $b2bAccounts = null,
        private ?ProjectController $b2bProjects = null,
        private ?QualityController $quality = null,
        private ?CuttingController $cutting = null,
        private ?\Arasya\Operations\Analytics\AnalyticsController $analytics = null,
        private ?\Arasya\Operations\Document\DocumentController $documents = null,
        private ?\Arasya\Operations\Production\ProductionAuthorityController $authority = null,
        private ?\Arasya\Operations\Production\ProductionQrController $productionQr = null,
        private ?\Arasya\Operations\Trendyol\TrendyolWorkspaceController $trendyol = null,
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
                'GET /orders/stage-queue' => $this->ordersController()->stageQueue($request),
                'GET /orders/stage-summary' => $this->ordersController()->stageSummary($request),
                'POST /orders/resolve-qr' => $this->ordersController()->resolveQr($request),
                'GET /live/events' => $request->query('scope') === 'cutting-display' ? $this->cuttingController()->display($request) : $this->qualityController()->events($request),
                'GET /activity/mine' => ($this->activity ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Activity API is not ready.'))->listMine($request),
                default => $this->matchDynamicRoutes($request),
            };
            $this->logger->log('info', 'http_request', $request->requestId, ['route' => $request->path, 'method' => $request->method, 'status' => $response->status, ...$this->context->logContext()]);
            return $this->secure($response, $request);
        } catch (ApiException $error) {
            $this->logger->log('warning', 'api_error', $request->requestId, ['route' => $request->path, 'method' => $request->method, 'status' => $error->status, 'code' => $error->errorCode, ...$this->context->logContext()]);
            $body = ['code' => $error->errorCode, 'message' => $error->getMessage(), 'requestId' => $request->requestId];
            if ($error->details !== []) {
                $body['details'] = $error->details;
            }
            $response = Response::json(['error' => $body], $error->status);
            if (in_array($error->errorCode, ['SESSION_EXPIRED', 'ACCOUNT_INACTIVE'], true)) {
                $response = $response->withHeaders(['Set-Cookie' => $this->cookies->clear()]);
            }
            return $this->secure($response, $request);
        } catch (Throwable $error) {
            $this->logger->log('error', 'internal_error', $request->requestId, ['route' => $request->path, 'method' => $request->method, 'status' => 500, ...SafeExceptionContext::of($error), ...$this->context->logContext()]);
            return $this->secure(Response::json(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'The service could not complete the request.', 'requestId' => $request->requestId]], 500), $request);
        }
    }

    private function matchDynamicRoutes(Request $request): Response
    {
        if (str_starts_with($request->path,'/management/analytics/')) return ($this->analytics ?? throw new ApiException(503,'SERVICE_UNAVAILABLE','Analytics unavailable.'))->handle($request);
        if (str_starts_with($request->path, '/cutting/') || str_starts_with($request->path, '/display/cutting/') || str_starts_with($request->path, '/management/cutting/')) return $this->cuttingController()->handle($request);
        if ($request->path === '/b2b/accounts' || str_starts_with($request->path, '/b2b/accounts/')) {
            return ($this->b2bAccounts ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'B2B current account API is not ready.'))->handle($request);
        }
        if ($request->path === '/b2b/projects' || str_starts_with($request->path, '/b2b/projects/')) {
            return ($this->b2bProjects ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'B2B projects API is not ready.'))->handle($request);
        }
        if ($request->path === '/b2b/orders' || str_starts_with($request->path, '/b2b/orders/')) {
            return ($this->b2bOrders ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'B2B orders API is not ready.'))->handle($request);
        }
        if ($request->path === '/b2b/companies' || str_starts_with($request->path, '/b2b/companies/')) {
            return ($this->b2bCompanies ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'B2B companies API is not ready.'))->handle($request);
        }
        if (str_starts_with($request->path, '/trendyol/')) {
            return ($this->trendyol ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Trendyol workspace is not ready.'))->handle($request);
        }
        if (str_starts_with($request->path, '/production-documents/')) {
            return ($this->documents ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Production documents are not ready.'))->handle($request);
        }
        if (str_starts_with($request->path, '/production-exceptions/')) {
            return $this->qualityController()->handle($request);
        }
        if (str_starts_with($request->path, '/management/')) {
            return ($this->management ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Management API is not ready.'))->handle($request);
        }
        if ($request->method === 'POST' && preg_match('#^/orders/([^/]{1,600})/fault-reports$#D', $request->path, $matches) === 1) {
            return $this->qualityController()->report($request, rawurldecode($matches[1]));
        }
        if (preg_match('#^/orders/([^/]{1,600})/production-authority(?:/(takeover|release))?$#D', $request->path, $matches) === 1) {
            return ($this->authority ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Production authority control is not ready.'))
                ->handle($request, rawurldecode($matches[1]), $matches[2] ?? '');
        }
        if (preg_match('#^/orders/([^/]{1,600})/production-qr(?:/(rotate))?$#D', $request->path, $matches) === 1) {
            return ($this->productionQr ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Production QR authority is not ready.'))
                ->handle($request, rawurldecode($matches[1]), $matches[2] ?? '');
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
        if ($request->method === 'POST' && preg_match('#^/integrations/sources/([a-z0-9_-]{1,40})/(orders|orders/validate|orders/authority|orders/qr|orders/documents|orders/document|orders/tracking|heartbeat)$#D', $request->path, $matches) === 1) {
            $sources = $this->sources ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Source ingestion is not ready.');
            return match ($matches[2]) {
                'orders' => $sources->ingestOrder($request, $matches[1]),
                'orders/validate' => $sources->validateOrder($request, $matches[1]),
                'orders/authority' => $sources->orderAuthority($request, $matches[1]),
                'orders/qr' => $sources->orderQr($request, $matches[1]),
                'orders/documents' => $sources->orderDocuments($request, $matches[1]),
                'orders/document' => $sources->orderDocument($request, $matches[1]),
                'orders/tracking' => $sources->orderTracking($request, $matches[1]),
                default => $sources->heartbeat($request, $matches[1]),
            };
        }

        throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
    }

    private function qualityController(): QualityController
    {
        return $this->quality ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Production exceptions are not ready.');
    }

    private function cuttingController(): CuttingController
    {
        return $this->cutting ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Cutting API is not ready.');
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
