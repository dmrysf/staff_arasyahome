<?php

declare(strict_types=1);

namespace Arasya\Operations\Trendyol;

use Arasya\Operations\Auth\AuthenticatedSession;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Iam\ApplicationAccess;
use Arasya\Operations\Security\CsrfGuard;

/**
 * HTTP surface of the Staff Trendyol workspace (session cookie, Staff application access, CSRF on every
 * mutation, Idempotency-Key on every command, strict bodies). It never calls Trendyol.
 *
 *   GET  /trendyol/overview                               intake state, counts, the caller's capabilities
 *   GET  /trendyol/packages?view=pending|released|closed|ignored
 *   GET  /trendyol/packages/{packageId}                   package, lines, readiness, production link, history
 *   PUT  /trendyol/packages/{packageId}/lines/{lineId}    {expectedVersion, kind, widthCm?, heightCm?, meters?, notes?}
 *   POST /trendyol/packages/{packageId}/dismiss           {expectedVersion, reason}
 *   POST /trendyol/packages/{packageId}/reopen            {expectedVersion}
 *   POST /trendyol/packages/{packageId}/release           {expectedVersion, confirm: true}
 */
final readonly class TrendyolWorkspaceController
{
    public function __construct(
        private TrendyolWorkspace $workspace,
        private AuthenticationService $auth,
        private CsrfGuard $csrf,
        private Config $config,
        private RequestContext $context,
    ) {
    }

    public function handle(Request $request): Response
    {
        $path = substr($request->path, strlen('/trendyol'));
        if ($request->method === 'GET' && $path === '/overview') {
            return $this->json($this->workspace->overview($this->session($request)->employee));
        }
        if ($request->method === 'GET' && $path === '/packages') {
            $view = $request->query('view') ?? 'pending';
            return $this->json($this->workspace->list($this->session($request)->employee, $view));
        }
        if (preg_match('#^/packages/([0-9]{1,19})$#D', $path, $m) === 1) {
            if ($request->method !== 'GET') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            return $this->json($this->workspace->detail($this->session($request)->employee, $m[1]));
        }
        if (preg_match('#^/packages/([0-9]{1,19})/lines/([0-9]{1,19})$#D', $path, $m) === 1) {
            if ($request->method !== 'PUT') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $session = $this->mutation($request);
            $input = $this->body($request, ['expectedVersion', 'kind', 'widthCm', 'heightCm', 'meters', 'notes'], ['expectedVersion', 'kind']);
            return $this->json($this->workspace->prepareLine($session->employee, $m[1], $m[2], $input, $request->header('idempotency-key') ?? '', $request->requestId));
        }
        if (preg_match('#^/packages/([0-9]{1,19})/(dismiss|reopen|release)$#D', $path, $m) === 1) {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $session = $this->mutation($request);
            $key = $request->header('idempotency-key') ?? '';
            return match ($m[2]) {
                'dismiss' => $this->json($this->workspace->dismiss($session->employee, $m[1], $this->body($request, ['expectedVersion', 'reason'], ['expectedVersion', 'reason']), $key, $request->requestId)),
                'reopen' => $this->json($this->workspace->reopen($session->employee, $m[1], $this->body($request, ['expectedVersion'], ['expectedVersion']), $key, $request->requestId)),
                default => $this->json($this->workspace->release($session->employee, $m[1], $this->body($request, ['expectedVersion', 'confirm'], ['expectedVersion', 'confirm']), $key, $request->requestId), 201),
            };
        }
        throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
    }

    private function session(Request $request): AuthenticatedSession
    {
        $session = $this->auth->authenticate($request->cookie($this->config->cookieName()) ?? '', $request->ipAddress, $request->userAgent, $request->requestId);
        $this->context->authenticatedAs($session->employee->employeeUuid);
        if (!$session->employee->hasApplication(ApplicationAccess::STAFF)) {
            throw new ApiException(403, 'APPLICATION_ACCESS_DENIED', 'The account has no access to this application.');
        }
        return $session;
    }

    private function mutation(Request $request): AuthenticatedSession
    {
        $session = $this->session($request);
        $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
        return $session;
    }

    /** @param list<string> $allowed @param list<string> $required @return array<string, mixed> */
    private function body(Request $request, array $allowed, array $required): array
    {
        $input = $request->json(8_192);
        $keys = array_keys($input);
        if (array_diff($keys, $allowed) !== [] || array_diff($required, $keys) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
        }
        return $input;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status, ['Cache-Control' => 'private, no-store']);
    }
}
