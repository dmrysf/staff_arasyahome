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
 * HTTP surface of /management/*. The actor always comes from the session cookie; every mutation needs
 * the exact allowed Origin (enforced by the kernel), a valid CSRF token and a body with exactly the
 * documented fields. Authorization lives in ManagementService.
 */
final readonly class ManagementController
{
    private const EMPLOYEE_CREATE_FIELDS = ['displayName', 'username', 'departmentId', 'positionTitle', 'managerId', 'applications', 'roleIds', 'stageIds', 'status'];
    private const EMPLOYEE_UPDATE_FIELDS = ['displayName', 'positionTitle', 'departmentId'];
    private const ROLE_CREATE_FIELDS = ['name', 'description', 'authorityRank', 'permissions'];
    private const ROLE_UPDATE_FIELDS = ['name', 'description', 'authorityRank', 'permissions', 'status'];
    private const DEPARTMENT_CREATE_FIELDS = ['name', 'description', 'parentId'];
    private const DEPARTMENT_UPDATE_FIELDS = ['name', 'description', 'parentId', 'status'];

    public function __construct(
        private ManagementService $management,
        private AuthenticationService $auth,
        private CsrfGuard $csrf,
        private Config $config,
        private RequestContext $context,
    ) {
    }

    public function handle(Request $request): Response
    {
        $path = substr($request->path, strlen('/management'));
        $method = $request->method;
        $session = $this->session($request);
        $actor = $session->employee;
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
        }
        $id = $request->requestId;

        if ($method === 'GET') {
            return Response::json(match (true) {
                $path === '/me' => $this->management->me($actor),
                $path === '/dashboard' => $this->management->overview($actor),
                $path === '/system' => $this->management->system($actor),
                $path === '/employees' => $this->management->listEmployees($actor, $this->filters($request, ['search', 'status', 'departmentId', 'application', 'roleId', 'cursor', 'limit'])),
                $path === '/applications' => $this->management->applications($actor),
                $path === '/permissions' => $this->management->permissions($actor),
                $path === '/roles' => $this->management->listRoles($actor),
                $path === '/departments' => $this->management->listDepartments($actor),
                $path === '/audit' => $this->management->audit($actor, $this->filters($request, ['actorId', 'action', 'targetType', 'targetId', 'search', 'from', 'to', 'cursor', 'limit'])),
                preg_match('#^/employees/([0-9a-f-]{36})$#D', $path, $m) === 1 => $this->management->getEmployee($actor, $m[1]),
                preg_match('#^/roles/(\d{1,10})$#D', $path, $m) === 1 => $this->management->getRole($actor, (int) $m[1]),
                default => throw new ApiException(404, 'NOT_FOUND', 'API route was not found.'),
            });
        }

        if ($method === 'POST' && $path === '/employees') {
            return Response::json($this->management->createEmployee($actor, $this->body($request, self::EMPLOYEE_CREATE_FIELDS, ['displayName', 'username', 'departmentId']), $id), 201);
        }
        if (preg_match('#^/employees/([0-9a-f-]{36})(?:/(activate|deactivate|password-reset|applications|roles|stages|manager))?$#D', $path, $m) === 1) {
            $employeeId = $m[1];
            $action = $m[2] ?? '';
            return Response::json(match (true) {
                $method === 'PATCH' && $action === '' => $this->management->updateEmployee($actor, $employeeId, $this->body($request, self::EMPLOYEE_UPDATE_FIELDS), $id),
                $method === 'POST' && $action === 'activate' => $this->management->setStatus($actor, $employeeId, 'active', $this->emptyBody($request, $id)),
                $method === 'POST' && $action === 'deactivate' => $this->management->setStatus($actor, $employeeId, 'inactive', $this->emptyBody($request, $id)),
                $method === 'POST' && $action === 'password-reset' => $this->management->resetPassword($actor, $employeeId, $this->emptyBody($request, $id)),
                $method === 'PUT' && $action === 'applications' => $this->management->setApplications($actor, $employeeId, $this->body($request, ['applications'], ['applications'])['applications'], $id),
                $method === 'PUT' && $action === 'roles' => $this->management->setRoles($actor, $employeeId, $this->body($request, ['roleIds'], ['roleIds'])['roleIds'], $id),
                $method === 'PUT' && $action === 'stages' => $this->management->setStages($actor, $employeeId, $this->body($request, ['stageIds'], ['stageIds'])['stageIds'], $id),
                $method === 'PUT' && $action === 'manager' => $this->management->setManager($actor, $employeeId, $this->body($request, ['managerId'], ['managerId'])['managerId'], $id),
                default => throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.'),
            });
        }
        if ($method === 'POST' && $path === '/roles') {
            return Response::json($this->management->createRole($actor, $this->body($request, self::ROLE_CREATE_FIELDS, ['name', 'authorityRank', 'permissions']), $id), 201);
        }
        if (preg_match('#^/roles/(\d{1,10})$#D', $path, $m) === 1) {
            if ($method === 'PATCH') {
                return Response::json($this->management->updateRole($actor, (int) $m[1], $this->body($request, self::ROLE_UPDATE_FIELDS), $id));
            }
            if ($method === 'DELETE') {
                $this->management->deleteRole($actor, (int) $m[1], $id);
                return Response::json(['ok' => true]);
            }
        }
        if ($method === 'POST' && $path === '/departments') {
            return Response::json($this->management->createDepartment($actor, $this->body($request, self::DEPARTMENT_CREATE_FIELDS, ['name']), $id), 201);
        }
        if (preg_match('#^/departments/(\d{1,10})$#D', $path, $m) === 1) {
            if ($method === 'PATCH') {
                return Response::json($this->management->updateDepartment($actor, (int) $m[1], $this->body($request, self::DEPARTMENT_UPDATE_FIELDS), $id));
            }
            if ($method === 'DELETE') {
                $this->management->deleteDepartment($actor, (int) $m[1], $id);
                return Response::json(['ok' => true]);
            }
        }
        throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
    }

    private function session(Request $request): AuthenticatedSession
    {
        $session = $this->auth->authenticate($request->cookie($this->config->cookieName()) ?? '', $request->ipAddress, $request->userAgent, $request->requestId);
        $this->context->authenticatedAs($session->employee->employeeUuid);
        return $session;
    }

    /**
     * Strict schema: unknown keys (for example an attempt to send isRoot, employeeUuid or a target
     * stage) are rejected rather than ignored, and required keys must be present.
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

    /** Action endpoints accept no body, or an empty JSON object. */
    private function emptyBody(Request $request, string $requestId): string
    {
        if (trim($request->body) !== '' && trim($request->body) !== '{}') {
            throw new ApiException(400, 'INVALID_REQUEST', 'This action takes no fields.');
        }
        return $requestId;
    }

    /** @param list<string> $names @return array<string, string> */
    private function filters(Request $request, array $names): array
    {
        $filters = [];
        foreach ($names as $name) {
            $value = $request->query($name);
            if ($value !== null && $value !== '') {
                $filters[$name] = mb_substr($value, 0, 200);
            }
        }
        return $filters;
    }
}
