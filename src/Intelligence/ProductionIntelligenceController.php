<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;

/**
 * GET /management/production-intelligence/{summary|products|employees|series} (Root and canonical CEO only) and
 * GET /production-intelligence/mine (the signed-in employee's own work). Read only; every other method is 404.
 */
final readonly class ProductionIntelligenceController
{
    private const MANAGEMENT = '/management/production-intelligence/';
    private const SECTIONS = ['summary', 'products', 'employees', 'series'];
    private const MANAGEMENT_QUERY = ['period', 'from', 'to', 'source', 'bucket'];
    private const MINE_QUERY = ['period', 'from', 'to'];

    public function __construct(
        private ProductionIntelligenceService $service,
        private AuthenticationService $auth,
        private AuthorizationService $authorization,
        private Config $config,
        private RequestContext $context,
    ) {
    }

    public static function handles(string $path): bool
    {
        return str_starts_with($path, self::MANAGEMENT) || $path === '/production-intelligence/mine';
    }

    public function handle(Request $request): Response
    {
        $session = $this->auth->authenticate($request->cookie($this->config->cookieName()) ?? '', $request->ipAddress, $request->userAgent, $request->requestId);
        $this->context->authenticatedAs($session->employee->employeeUuid);
        if ($request->method !== 'GET') {
            throw new ApiException(404, 'NOT_FOUND', 'Production intelligence route not found.');
        }
        if ($request->path === '/production-intelligence/mine') {
            $this->authorization->require($session->employee, 'history.view_mine');
            return Response::json($this->service->mine($session->employee, $this->query($request, self::MINE_QUERY)), 200, ['Cache-Control' => 'no-store']);
        }
        $section = substr($request->path, strlen(self::MANAGEMENT));
        if (!in_array($section, self::SECTIONS, true)) {
            throw new ApiException(404, 'NOT_FOUND', 'Production intelligence route not found.');
        }
        return Response::json($this->service->report($session->employee, $section, $this->query($request, self::MANAGEMENT_QUERY), $request->requestId), 200, ['Cache-Control' => 'no-store']);
    }

    /** @param list<string> $allowed @return array<string, string> */
    private function query(Request $request, array $allowed): array
    {
        $query = [];
        foreach ($request->query as $key => $value) {
            if (!in_array($key, $allowed, true) || !is_string($value)) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Unsupported query parameter.');
            }
            $query[$key] = $value;
        }
        return $query;
    }
}
