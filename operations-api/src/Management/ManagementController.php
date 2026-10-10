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
use Arasya\Operations\Quality\ApproverPolicy;
use Arasya\Operations\Quality\CuttingFaultService;
use Arasya\Operations\Quality\ExceptionQueries;
use Arasya\Operations\Security\CsrfGuard;
use Arasya\Operations\Employee\EmployeeIdentity;

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
        private ?ProductionOverviewService $production = null,
        private ?OrderControlService $orders = null,
        private ?OrderOwnershipService $ownership = null,
        private ?ApproverPolicy $approvers = null,
        private ?CuttingFaultService $faults = null,
        private ?ExceptionQueries $exceptions = null,
        private ?OrderLookupService $lookup = null,
        private ?OrganizationService $organization = null,
        private ?ProductionSettingsService $settings = null,
        private ?\Arasya\Operations\Analytics\AnalyticsPolicy $analytics = null,
        private ?\Arasya\Operations\Document\RevisionApproverPolicy $documentApprovers = null,
        private ?\Arasya\Operations\Document\DocumentScopePolicy $documentScopes = null,
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
                $path === '/me' => $this->management->me($actor) + ['capabilities' => $this->capabilities($actor)],
                $path === '/production-exceptions' => $this->exceptionList($actor, $request->query('view') ?? 'pending'),
                preg_match('#^/production-exceptions/([0-9a-f-]{36})$#D', $path, $m) === 1 => $this->exceptionView($actor, $m[1]),
                $path === '/order-lookup' => $this->service($this->lookup)->search($actor, $request->query('number') ?? ''),
                preg_match('#^/order-lookup/([^/]{1,600})$#D', $path, $m) === 1 => $this->service($this->lookup)->detail($actor, rawurldecode($m[1])),
                $path === '/organization' => $this->service($this->organization)->view($actor),
                $path === '/production-settings' => $this->service($this->settings)->view($actor),
                $path === '/dashboard' => $this->management->overview($actor),
                $path === '/system' => $this->management->system($actor),
                $path === '/production-overview' => ($this->production ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Production overview is not ready.'))->overview($actor, $this->filters($request, ['source'])),
                $path === '/employees' => $this->management->listEmployees($actor, $this->filters($request, ['search', 'status', 'departmentId', 'application', 'roleId', 'cursor', 'limit'])),
                $path === '/orders' => $this->orderControl()->list($actor, $this->filters($request, ['search', 'source', 'stage', 'commerceStatus', 'ownerId', 'assignment', 'state', 'cursor', 'limit'])),
                preg_match('#^/orders/([^/]{1,600})$#D', $path, $m) === 1 => $this->orderControl()->detail($actor, rawurldecode($m[1])),
                preg_match('#^/orders/([^/]{1,600})/eligible-owners$#D', $path, $m) === 1 => $this->ownership()->eligibleOwners($actor, rawurldecode($m[1])),
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

        $key = $request->header('idempotency-key') ?? '';
        if ($method === 'POST' && preg_match('#^/production-exceptions/([0-9a-f-]{36})/(decision|cancel)$#D', $path, $m) === 1) {
            $faults = $this->service($this->faults);
            return Response::json($m[2] === 'decision'
                ? $this->withActions($actor, $faults->decide($actor, $m[1], $this->body($request, ['expectedVersion', 'decision', 'comment'], ['expectedVersion', 'decision']), $key, $id))
                : $this->withActions($actor, $faults->cancel($actor, $m[1], $this->body($request, ['expectedVersion', 'reason'], ['expectedVersion', 'reason']), $key, $id)));
        }
        if ($path === '/organization/ceo' && $method === 'PUT') {
            return Response::json($this->service($this->organization)->designateCeo($actor, $this->body($request, ['employeeId'], ['employeeId'])['employeeId'], $key, $id));
        }
        if ($path === '/organization/working-hours' && $method === 'PUT') {
            return Response::json($this->service($this->organization)->setWorkingHours($actor, $this->body($request, ['days'], ['days']), $key, $id));
        }
        if ($path === '/organization/responsibilities' && $method === 'POST') {
            return Response::json($this->service($this->organization)->assign($actor, $this->body($request, ['responsibility', 'employeeId', 'startsAt', 'endsAt', 'note'], ['responsibility', 'employeeId']), $key, $id), 201);
        }
        if ($method === 'POST' && preg_match('#^/organization/responsibilities/([0-9a-f-]{36})/revoke$#D', $path, $m) === 1) {
            return Response::json($this->service($this->organization)->revoke($actor, $m[1], $this->body($request, ['reason']), $key, $id));
        }
        if ($path === '/production-settings/reasons' && $method === 'POST') {
            return Response::json($this->service($this->settings)->createReason($actor, $this->body($request, ['key', 'label', 'requiresComment', 'sortOrder'], ['key', 'label']), $key, $id), 201);
        }
        if ($method === 'PATCH' && preg_match('#^/production-settings/reasons/([a-z][a-z0-9-]{1,59})$#D', $path, $m) === 1) {
            return Response::json($this->service($this->settings)->updateReason($actor, $m[1], $this->body($request, ['label', 'requiresComment', 'status', 'sortOrder']), $key, $id));
        }
        if ($method === 'PATCH' && preg_match('#^/production-settings/stages/([a-z][a-z0-9-]{1,99})$#D', $path, $m) === 1) {
            return Response::json($this->service($this->settings)->renameStage($actor, $m[1], $this->body($request, ['label'], ['label']), $key, $id));
        }

        // Supervisor owner interventions: exactly two operations, never a stage or any other field.
        if (preg_match('#^/orders/([^/]{1,600})/(release-owner|owner)$#D', $path, $m) === 1) {
            $orderId = rawurldecode($m[1]);
            $key = $request->header('idempotency-key') ?? '';
            return Response::json(match (true) {
                $method === 'POST' && $m[2] === 'release-owner' => $this->ownership()->release($actor, $orderId, $this->body($request, ['expectedVersion'], ['expectedVersion'])['expectedVersion'], $key, $id),
                $method === 'PUT' && $m[2] === 'owner' => (function () use ($request, $actor, $orderId, $key, $id): array {
                    $input = $this->body($request, ['employeeId', 'expectedVersion'], ['employeeId', 'expectedVersion']);
                    return $this->ownership()->reassign($actor, $orderId, $input['employeeId'], $input['expectedVersion'], $key, $id);
                })(),
                default => throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.'),
            });
        }
        if ($method === 'POST' && $path === '/employees') {
            return Response::json($this->management->createEmployee($actor, $this->body($request, self::EMPLOYEE_CREATE_FIELDS, ['displayName', 'username', 'departmentId']), $id), 201);
        }
        if (preg_match('#^/employees/([0-9a-f-]{36})(?:/(activate|deactivate|password-reset|applications|roles|stages|stage-scopes|manager|secondary-departments|document-scopes))?$#D', $path, $m) === 1) {
            $employeeId = $m[1];
            $action = $m[2] ?? '';
            return Response::json(match (true) {
                $method === 'PATCH' && $action === '' => $this->management->updateEmployee($actor, $employeeId, $this->body($request, self::EMPLOYEE_UPDATE_FIELDS), $id),
                $method === 'POST' && $action === 'activate' => $this->management->setStatus($actor, $employeeId, 'active', $this->emptyBody($request, $id)),
                $method === 'POST' && $action === 'deactivate' => $this->management->setStatus($actor, $employeeId, 'inactive', $this->emptyBody($request, $id)),
                $method === 'POST' && $action === 'password-reset' => $this->management->resetPassword($actor, $employeeId, $this->emptyBody($request, $id)),
                $method === 'PUT' && $action === 'applications' => $this->management->setApplications($actor, $employeeId, $this->body($request, ['applications'], ['applications'])['applications'], $id),
                $method === 'PUT' && $action === 'roles' => $this->management->setRoles($actor, $employeeId, $this->body($request, ['roleIds'], ['roleIds'])['roleIds'], $id),
                $method === 'PUT' && $action === 'stages' => $this->setStages($actor, $employeeId, $request, $id),
                $method === 'PUT' && $action === 'secondary-departments' => $this->management->setSecondaryDepartments($actor, $employeeId, $this->body($request, ['departmentIds'], ['departmentIds'])['departmentIds'], $id),
                $method === 'PUT' && $action === 'stage-scopes' => $this->management->setStageScope($actor, $employeeId, $this->body($request, ['stageId', 'sources', 'expectedSources', 'confirmWidening'], ['stageId', 'sources', 'expectedSources']), $id),
                $method === 'PUT' && $action === 'document-scopes' => $this->management->setDocumentScopes($actor, $employeeId, $this->body($request, ['operate', 'approve'], ['operate', 'approve']), $id),
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

    /**
     * Server-computed navigation capabilities for the Dashboard. The frontend shows sections from
     * these flags; every route still authorizes on its own.
     *
     * @return array<string, bool>
     */
    private function capabilities(EmployeeIdentity $actor): array
    {
        $approver = $this->approvers?->via($actor);
        $documentVia = $this->documentApprovers?->via($actor);
        return [
            'approveExceptions' => $approver !== null,
            'approvalViaBackup' => $approver === ApproverPolicy::VIA_BACKUP,
            'lookupOrders' => $this->approvers?->canLookup($actor) ?? false,
            'manageOrganization' => $this->organization?->canManage($actor) ?? false,
            'manageProductionSettings' => $actor->isRoot,
            'cancelExceptions' => $actor->isRoot,
            'viewAnalytics' => $this->analytics?->canView($actor) ?? false,
            'manageAnalyticsPolicy' => $actor->isRoot,
            // A business approver without an approval scope decides nothing, so the queue is not offered.
            'approveDocumentRevisions' => $documentVia !== null && $documentVia !== \Arasya\Operations\Document\RevisionApproverPolicy::VIA_ROOT
                && ($this->documentScopes?->sources($actor, \Arasya\Operations\Document\DocumentScopePolicy::APPROVE) ?? []) !== [],
            'documentRevisionViaBackup' => $documentVia === \Arasya\Operations\Document\RevisionApproverPolicy::VIA_BACKUP,
            'viewDocumentHistory' => $actor->isRoot || $documentVia !== null || in_array(\Arasya\Operations\Document\DocumentService::HISTORY, $actor->permissions, true),
            'revokeDocuments' => $actor->isRoot,
        ];
    }

    /** @return array<string, mixed> */
    private function exceptionList(EmployeeIdentity $actor, string $view): array
    {
        $this->service($this->approvers)->require($actor);
        return ['items' => $this->service($this->exceptions)->managerList($view, $actor->employeeUuid)];
    }

    /** @return array<string, mixed> */
    private function exceptionView(EmployeeIdentity $actor, string $exceptionId): array
    {
        $this->service($this->approvers)->require($actor);
        return $this->withActions($actor, $this->service($this->exceptions)->detail($exceptionId, null, true));
    }

    /** @param array<string, mixed> $detail @return array<string, mixed> */
    private function withActions(EmployeeIdentity $actor, array $detail): array
    {
        $row = $this->service($this->exceptions)->find((string) $detail['id']);
        $involved = $row !== null && in_array($actor->employeeUuid, [$row['detector_employee_uuid'], $row['responsible_employee_uuid']], true);
        $detail['actions'] = [
            'canDecide' => $detail['status'] === 'awaiting_approval' && !$involved && $this->approvers?->via($actor) !== null,
            'canCancel' => $actor->isRoot && in_array($detail['status'], ExceptionQueries::OPEN_STATUSES, true),
            'involved' => $involved,
        ];
        return $detail;
    }

    /** @template T of object @param T|null $service @return T */
    private function service(?object $service): object
    {
        return $service ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'This management area is not ready.');
    }

    private function orderControl(): OrderControlService
    {
        return $this->orders ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Order control is not ready.');
    }

    private function ownership(): OrderOwnershipService
    {
        return $this->ownership ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Order ownership control is not ready.');
    }

    private function session(Request $request): AuthenticatedSession
    {
        $session = $this->auth->authenticate($request->cookie($this->config->cookieName()) ?? '', $request->ipAddress, $request->userAgent, $request->requestId);
        $this->context->authenticatedAs($session->employee->employeeUuid);
        return $session;
    }

    /**
     * `stageIds` with optional, root-only `stageScopes` and `allSourcesStageIds` for the stages this request adds.
     *
     * @return array<string, mixed>
     */
    private function setStages(EmployeeIdentity $actor, string $employeeId, Request $request, string $requestId): array
    {
        $body = $this->body($request, ['stageIds', 'stageScopes', 'allSourcesStageIds'], ['stageIds']);
        return $this->management->setStages($actor, $employeeId, $body['stageIds'], $requestId, $body['stageScopes'] ?? null, $body['allSourcesStageIds'] ?? null);
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
