<?php

declare(strict_types=1);

namespace Arasya\Operations\Http;

use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Production\ProductionWorkflowService;
use RuntimeException;

final readonly class ProductionWorkflowController
{
    public function __construct(
        private ProductionWorkflowService $workflows,
        private AuthenticationService $auth,
        private Config $config,
        private RequestContext $context,
    ) {
    }

    public function show(Request $request): Response
    {
        $session = $this->auth->authenticate(
            $request->cookie($this->config->cookieName()) ?? '',
            $request->ipAddress,
            $request->userAgent,
            $request->requestId,
        );
        $this->context->authenticatedAs($session->employee->employeeUuid);
        try {
            $workflow = $this->workflows->current();
        } catch (RuntimeException) {
            throw new ApiException(503, 'WORKFLOW_UNAVAILABLE', 'Production workflow is not ready.');
        }

        $etag = $workflow->etag();
        if ($this->etagMatches($request->header('if-none-match'), $etag)) {
            return new Response(304, null, ['ETag' => $etag, 'Cache-Control' => 'private, no-cache']);
        }
        return Response::json($workflow->toArray(), 200, ['ETag' => $etag, 'Cache-Control' => 'private, no-cache']);
    }

    private function etagMatches(?string $header, string $etag): bool
    {
        if ($header === null) {
            return false;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $etag || str_starts_with($candidate, 'W/') && substr($candidate, 2) === $etag) {
                return true;
            }
        }
        return false;
    }
}
