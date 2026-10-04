<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\Auth\AuthenticatedSession;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Security\CsrfGuard;

/**
 * HTTP surface of /b2b/companies. The actor always comes from the central session cookie. Every mutation needs
 * the exact allowed Origin (enforced by the kernel), a valid CSRF token, an Idempotency-Key and a body with only
 * the documented fields. There is no DELETE and no generic PATCH.
 */
final readonly class CompanyController
{
    private const UPDATE_COMPANY_FIELDS = [...CompanyInput::COMPANY_FIELDS, 'expectedVersion'];
    private const UPDATE_CONTACT_FIELDS = [...CompanyInput::CONTACT_FIELDS, 'expectedVersion'];
    private const UPDATE_ADDRESS_FIELDS = [...CompanyInput::ADDRESS_FIELDS, 'expectedVersion'];

    public function __construct(
        private CompanyQueries $queries,
        private CompanyCommands $commands,
        private AuthenticationService $auth,
        private AuthorizationService $authorization,
        private CsrfGuard $csrf,
        private Config $config,
        private RequestContext $context,
    ) {
    }

    public function handle(Request $request): Response
    {
        $path = substr($request->path, strlen('/b2b/companies'));
        $method = $request->method;
        if (preg_match('#^(?:/([^/]{1,64})(?:/(activity|deactivate|reactivate|contacts|addresses)(?:/([^/]{1,64})(?:/(deactivate|reactivate))?)?)?)?$#D', $path, $m) !== 1) {
            throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
        }
        $companyId = $m[1] ?? '';
        $section = $m[2] ?? '';
        $childId = $m[3] ?? '';
        $childAction = $m[4] ?? '';
        if (($section === 'activity' || $section === 'deactivate' || $section === 'reactivate') && $childId !== '') {
            throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
        }

        $session = $this->session($request);
        $actor = $session->employee;
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
        }
        $key = $request->header('idempotency-key') ?? '';
        $id = $request->requestId;

        if ($method === 'GET') {
            return Response::json(match (true) {
                $companyId === '' => $this->queries->list($actor, $this->filters($request, ['search', 'status', 'country', 'cursor', 'limit'])),
                $section === '' => $this->queries->detail($actor, $companyId),
                $section === 'activity' => $this->queries->activity($actor, $companyId, $this->filters($request, ['cursor', 'limit'])),
                default => throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.'),
            });
        }

        $result = match (true) {
            $method === 'POST' && $companyId === '' => $this->commands->create(
                $actor,
                $this->body($request, [...CompanyInput::COMPANY_FIELDS, 'contact', 'address']),
                $key,
                $id,
            ),
            $method === 'PUT' && $companyId !== '' && $section === '' => (function () use ($request, $actor, $companyId, $key, $id): array {
                $input = $this->body($request, self::UPDATE_COMPANY_FIELDS, self::UPDATE_COMPANY_FIELDS);
                $version = $input['expectedVersion'];
                unset($input['expectedVersion']);
                return $this->commands->update($actor, $companyId, $input, $version, $key, $id);
            })(),
            $method === 'POST' && ($section === 'deactivate' || $section === 'reactivate') => $this->commands->setStatus(
                $actor,
                $companyId,
                $section === 'reactivate' ? 'active' : 'inactive',
                $this->body($request, ['expectedVersion'], ['expectedVersion'])['expectedVersion'],
                $key,
                $id,
            ),
            $method === 'POST' && $section === 'contacts' && $childId === '' => $this->commands->createContact($actor, $companyId, $this->body($request, CompanyInput::CONTACT_FIELDS), $key, $id),
            $method === 'PUT' && $section === 'contacts' && $childId !== '' && $childAction === '' => (function () use ($request, $actor, $companyId, $childId, $key, $id): array {
                $input = $this->body($request, self::UPDATE_CONTACT_FIELDS, self::UPDATE_CONTACT_FIELDS);
                $version = $input['expectedVersion'];
                unset($input['expectedVersion']);
                return $this->commands->updateContact($actor, $companyId, $childId, $input, $version, $key, $id);
            })(),
            $method === 'POST' && $section === 'contacts' && $childAction !== '' => $this->commands->setContactStatus(
                $actor,
                $companyId,
                $childId,
                $childAction === 'reactivate' ? 'active' : 'inactive',
                $this->body($request, ['expectedVersion'], ['expectedVersion'])['expectedVersion'],
                $key,
                $id,
            ),
            $method === 'POST' && $section === 'addresses' && $childId === '' => $this->commands->createAddress($actor, $companyId, $this->body($request, CompanyInput::ADDRESS_FIELDS), $key, $id),
            $method === 'PUT' && $section === 'addresses' && $childId !== '' && $childAction === '' => (function () use ($request, $actor, $companyId, $childId, $key, $id): array {
                $input = $this->body($request, self::UPDATE_ADDRESS_FIELDS, self::UPDATE_ADDRESS_FIELDS);
                $version = $input['expectedVersion'];
                unset($input['expectedVersion']);
                return $this->commands->updateAddress($actor, $companyId, $childId, $input, $version, $key, $id);
            })(),
            $method === 'POST' && $section === 'addresses' && $childAction !== '' => $this->commands->setAddressStatus(
                $actor,
                $companyId,
                $childId,
                $childAction === 'reactivate' ? 'active' : 'inactive',
                $this->body($request, ['expectedVersion'], ['expectedVersion'])['expectedVersion'],
                $key,
                $id,
            ),
            default => throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.'),
        };
        return Response::json($this->mutationResponse($actor, $result), (int) $result['status']);
    }

    /**
     * Mutations answer with the ids they produced and, when the actor may view companies, the current company
     * detail, so the client needs no second request. A replay answers the same way from current data.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function mutationResponse(EmployeeIdentity $actor, array $result): array
    {
        $response = ['companyId' => (string) $result['companyId']];
        foreach (['contactId', 'addressId'] as $created) {
            if (isset($result[$created])) {
                $response[$created] = (string) $result[$created];
            }
        }
        if ($this->authorization->can($actor, CompanyAccess::VIEW)) {
            $response += $this->queries->detailWithoutAuthorization($actor, (string) $result['companyId']);
        }
        return $response;
    }

    private function session(Request $request): AuthenticatedSession
    {
        $session = $this->auth->authenticate($request->cookie($this->config->cookieName()) ?? '', $request->ipAddress, $request->userAgent, $request->requestId);
        $this->context->authenticatedAs($session->employee->employeeUuid);
        return $session;
    }

    /**
     * Strict schema: unknown keys are rejected rather than ignored, and required keys must be present.
     *
     * @param list<string> $allowed
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private function body(Request $request, array $allowed, array $required = []): array
    {
        $input = $request->json(32_768);
        $keys = array_keys($input);
        if (array_diff($keys, $allowed) !== [] || array_diff($required, $keys) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
        }
        return $input;
    }

    /** @param list<string> $names @return array<string, string> */
    private function filters(Request $request, array $names): array
    {
        $filters = [];
        foreach ($names as $name) {
            $value = $request->query($name);
            if ($value !== null && $value !== '') {
                $filters[$name] = mb_substr($value, 0, 1400);
            }
        }
        return $filters;
    }
}
