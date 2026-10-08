<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\Auth\AuthenticatedSession;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Security\ApiRateLimiter;
use Arasya\Operations\Security\CsrfGuard;
use PDO;

/**
 * HTTP surface of the central production document engine (Staff and Dashboard; session cookie, CSRF on
 * every mutation, Idempotency-Key on every command, strict bodies):
 *
 *   GET  /production-documents/orders/{globalOrderId}                    document state, revisions, history
 *   POST /production-documents/orders/{globalOrderId}/generate           revision 1, or the approved next revision
 *   POST /production-documents/orders/{globalOrderId}/print              records a print/reprint, returns the PDF
 *   POST /production-documents/orders/{globalOrderId}/revision-requests  request approval for the next revision
 *   POST /production-documents/orders/{globalOrderId}/revoke             root emergency revoke
 *   GET  /production-documents/attention                                 requester worklist
 *   GET  /production-documents/lookup?number=                            exact order-number lookup (document users)
 *   GET  /production-documents/orders/{id}/revisions/{n}/preview         inline preview, no QR, nothing recorded
 *   GET  /production-documents/revision-requests?view=pending|history    approver queue
 *   GET  /production-documents/revision-requests/{id}                    request detail with the production diff
 *   POST /production-documents/revision-requests/{id}/decision           approve / reject (first decision wins)
 *   POST /production-documents/revision-requests/{id}/cancel             requester or root withdraws
 */
final readonly class DocumentController
{
    public function __construct(
        private DocumentService $documents,
        private DocumentQueries $queries,
        private RevisionApproverPolicy $approvers,
        private AuthenticationService $auth,
        private CsrfGuard $csrf,
        private Config $config,
        private RequestContext $context,
        private ApiRateLimiter $rateLimiter,
        private PDO $pdo,
    ) {
    }

    public function handle(Request $request): Response
    {
        $path = substr($request->path, strlen('/production-documents'));
        if (preg_match('#^/orders/([^/]{1,600})(?:/(generate|print|revision-requests|revoke))?$#D', $path, $m) === 1) {
            $globalId = rawurldecode($m[1]);
            $action = $m[2] ?? '';
            if ($request->method === 'GET' && $action === '') {
                $actor = $this->viewer($request);
                $after = $request->query('afterEvent');
                if ($after !== null && (!ctype_digit($after) || strlen($after) > 18)) {
                    throw new ApiException(400, 'INVALID_CURSOR', 'The provided cursor is invalid.');
                }
                return $this->json($this->queries->orderDocument($this->orderUuid($globalId), $this->documents->canViewHistory($actor) || $actor->isRoot, $after === null ? null : (int) $after));
            }
            if ($request->method !== 'POST' || $action === '') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $session = $this->mutation($request);
            $actor = $session->employee;
            $this->requireStaffOrDashboard($actor);
            $key = $request->header('idempotency-key') ?? '';
            return match ($action) {
                'generate' => $this->json($this->documents->generate($actor, $globalId, $this->body($request, ['expectedDocumentVersion', 'requestId'], ['expectedDocumentVersion']), $key, $request->requestId), 201),
                'revision-requests' => $this->json($this->documents->requestRevision($actor, $globalId, $this->body($request, ['expectedDocumentVersion', 'comment'], ['expectedDocumentVersion']), $key, $request->requestId), 201),
                'revoke' => $this->json($this->documents->revoke($actor, $globalId, $this->body($request, ['expectedDocumentVersion', 'reason'], ['expectedDocumentVersion', 'reason']), $key, $request->requestId)),
                default => $this->pdf($this->documents->recordPrint($actor, $globalId, $this->body($request, ['revisionNumber', 'reason'], ['revisionNumber']), $key, $request->requestId)),
            };
        }
        if (preg_match('#^/orders/([^/]{1,600})/revisions/([1-9][0-9]{0,5})/preview$#D', $path, $m) === 1) {
            if ($request->method !== 'GET') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $actor = $this->viewer($request);
            return DocumentRenderer::preview((new DocumentRenderer($this->pdo))->render($this->revisionUuid($this->orderUuid(rawurldecode($m[1])), (int) $m[2], $this->documents->canViewHistory($actor) || $actor->isRoot), true));
        }
        if ($request->method === 'GET' && $path === '/lookup') {
            $actor = $this->viewer($request);
            $code = \Arasya\Operations\Order\OrderLookupCode::normalizeInput($request->query('number') ?? '');
            if ($code === null) {
                throw new ApiException(400, 'INVALID_LOOKUP_CODE', 'The order code is not valid.');
            }
            return $this->json(['items' => $this->queries->lookup($code)]);
        }
        if ($request->method === 'GET' && $path === '/attention') {
            $actor = $this->session($request)->employee;
            $this->requireStaffOrDashboard($actor);
            if (!$this->documents->canRequest($actor) && !$this->documents->canGenerate($actor)) {
                throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');
            }
            return $this->json(['items' => $this->queries->attention()]);
        }
        if ($request->method === 'GET' && $path === '/revision-requests') {
            $actor = $this->session($request)->employee;
            $this->approvers->require($actor);
            $view = $request->query('view') ?? 'pending';
            if (!in_array($view, ['pending', 'history'], true)) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Unknown view.');
            }
            return $this->json(['items' => $this->queries->queue($view), 'pendingCount' => $this->queries->pendingCount()]);
        }
        if (preg_match('#^/revision-requests/([0-9a-f-]{36})(?:/(decision|cancel))?$#D', $path, $m) === 1) {
            $action = $m[2] ?? '';
            if ($request->method === 'GET' && $action === '') {
                $actor = $this->session($request)->employee;
                $detail = $this->queries->requestDetail($m[1]);
                $allowed = $this->approvers->via($actor) !== null || $this->documents->canViewHistory($actor)
                    || ($detail['requestedById'] === $actor->employeeUuid && $this->documents->canRequest($actor));
                if (!$allowed) {
                    throw new ApiException(404, 'DOCUMENT_REQUEST_NOT_FOUND', 'Cererea de revizie nu a fost găsită.');
                }
                return $this->json($detail);
            }
            if ($request->method !== 'POST' || $action === '') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed for this route.');
            }
            $session = $this->mutation($request);
            $key = $request->header('idempotency-key') ?? '';
            return $action === 'decision'
                ? $this->json($this->documents->decide($session->employee, $m[1], $this->body($request, ['expectedVersion', 'decision', 'comment'], ['expectedVersion', 'decision']), $key, $request->requestId))
                : $this->json($this->documents->cancel($session->employee, $m[1], $this->body($request, ['expectedVersion', 'reason'], ['expectedVersion', 'reason']), $key, $request->requestId));
        }
        throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
    }

    /** @param array{revisionUuid: string, printNumber: int} $print */
    private function pdf(array $print): Response
    {
        return DocumentRenderer::response((new DocumentRenderer($this->pdo))->render($print['revisionUuid']), $print['printNumber']);
    }

    /** A revision of the order; obsolete revisions only for history viewers. */
    private function revisionUuid(string $orderUuid, int $number, bool $history): string
    {
        $statement = $this->pdo->prepare('SELECT revision_uuid, status FROM production_document_revisions WHERE order_uuid = ? AND revision_number = ?');
        $statement->execute([$orderUuid, $number]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || ($row['status'] !== 'active' && !$history)) {
            throw new ApiException(404, 'DOCUMENT_NOT_FOUND', 'Documentul nu a fost găsit.');
        }
        return (string) $row['revision_uuid'];
    }

    private function orderUuid(string $globalId): string
    {
        $statement = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?');
        $statement->execute([$globalId]);
        $uuid = $statement->fetchColumn();
        return is_string($uuid) ? $uuid : throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
    }

    private function viewer(Request $request): EmployeeIdentity
    {
        $actor = $this->session($request)->employee;
        $this->requireStaffOrDashboard($actor);
        if (!$this->rateLimiter->hit('document-read', $actor->employeeUuid, 120, 60)) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many requests. Try again shortly.');
        }
        $allowed = $actor->isRoot || $this->documents->canViewHistory($actor) || $this->documents->canGenerate($actor)
            || $this->documents->canReprint($actor) || $this->documents->canRequest($actor);
        if (!$allowed) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');
        }
        return $actor;
    }

    private function requireStaffOrDashboard(EmployeeIdentity $actor): void
    {
        if (!$actor->isOperationallyActive() || (!$actor->hasApplication('staff') && !$actor->hasApplication('dashboard'))) {
            throw new ApiException(403, 'APPLICATION_ACCESS_DENIED', 'Application access denied.');
        }
        if ($actor->mustChangePassword) {
            throw new ApiException(403, 'PASSWORD_CHANGE_REQUIRED', 'The password must be changed before continuing.');
        }
    }

    private function session(Request $request): AuthenticatedSession
    {
        $session = $this->auth->authenticate($request->cookie($this->config->cookieName()) ?? '', $request->ipAddress, $request->userAgent, $request->requestId);
        $this->context->authenticatedAs($session->employee->employeeUuid);
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
        $input = $request->json(16_384);
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
