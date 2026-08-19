<?php

declare(strict_types=1);

namespace Arasya\Operations\Http;

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
            $this->cors->requireUnsafeOrigin($request);
            $response = match ($request->method . ' ' . $request->path) {
                'GET /health' => $this->health->show(),
                'POST /auth/login' => $this->auth->login($request),
                'POST /auth/logout' => $this->auth->logout($request),
                'GET /auth/session' => $this->auth->session($request),
                'POST /auth/refresh' => $this->auth->refresh($request),
                'GET /employees/me' => $this->auth->employee($request),
                default => throw new ApiException(404, 'NOT_FOUND', 'API route was not found.'),
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

    private function secure(Response $response, Request $request): Response
    {
        return $response->withHeaders([
            ...$this->cors->headers($request->header('origin')),
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'X-Request-ID' => $request->requestId,
        ]);
    }
}
