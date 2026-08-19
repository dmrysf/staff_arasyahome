<?php

declare(strict_types=1);

namespace Arasya\Operations\Http;

final readonly class CorsPolicy
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private array $allowedOrigins)
    {
    }

    public function isAllowed(?string $origin): bool
    {
        return $origin !== null && in_array($origin, $this->allowedOrigins, true);
    }

    public function requireUnsafeOrigin(Request $request): void
    {
        if (!in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        if (!$this->isAllowed($request->header('origin'))) {
            throw new ApiException(403, 'ORIGIN_DENIED', 'Request origin is not allowed.');
        }
    }

    public function preflight(Request $request): ?Response
    {
        if ($request->method !== 'OPTIONS') {
            return null;
        }
        $origin = $request->header('origin');
        $requestedMethod = strtoupper($request->header('access-control-request-method') ?? '');
        if (!$this->isAllowed($origin) || !in_array($requestedMethod, ['GET', 'POST'], true)) {
            throw new ApiException(403, 'ORIGIN_DENIED', 'CORS preflight denied.');
        }
        $allowedHeaders = ['content-type', 'idempotency-key', 'x-csrf-token', 'x-request-id'];
        $requestedHeaders = array_values(array_filter(array_map('trim', explode(',', strtolower($request->header('access-control-request-headers') ?? '')))));
        foreach ($requestedHeaders as $header) {
            if (!in_array($header, $allowedHeaders, true)) {
                throw new ApiException(403, 'CORS_HEADER_DENIED', 'CORS request header denied.');
            }
        }
        return new Response(204, null, $this->headers($origin));
    }

    /** @return array<string, string> */
    public function headers(?string $origin): array
    {
        if (!$this->isAllowed($origin)) {
            return [];
        }
        return [
            'Access-Control-Allow-Origin' => (string) $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Idempotency-Key, X-CSRF-Token, X-Request-ID',
            'Access-Control-Expose-Headers' => 'X-Request-ID',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
    }
}
