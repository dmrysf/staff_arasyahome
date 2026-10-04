<?php

declare(strict_types=1);

namespace Arasya\Operations\Activity;

use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Support\Clock;

final readonly class ActivityController
{
    private const ALLOWED_QUERY = ['range', 'from', 'to', 'cursor'];

    public function __construct(
        private PdoActivityRepository $activity,
        private AuthenticationService $auth,
        private AuthorizationService $authorization,
        private Config $config,
        private RequestContext $context,
        private Clock $clock,
    ) {
    }

    public function listMine(Request $request): Response
    {
        $session = $this->auth->authenticate($request->cookie($this->config->cookieName()) ?? '', $request->ipAddress, $request->userAgent, $request->requestId);
        $this->context->authenticatedAs($session->employee->employeeUuid);
        $this->authorization->require($session->employee, 'history.view_mine');
        foreach (array_keys($request->query) as $key) {
            if (!in_array($key, self::ALLOWED_QUERY, true) || !is_string($request->query[$key])) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Unsupported activity query parameter.');
            }
        }
        $range = ActivityRange::resolve($request->query('range', 'today') ?? 'today', $request->query('from'), $request->query('to'), $this->clock->now());
        $page = $this->activity->listForEmployee($session->employee->employeeUuid, $range, $request->query('cursor'));
        return Response::json($page, 200, ['Cache-Control' => 'private, no-store']);
    }
}
