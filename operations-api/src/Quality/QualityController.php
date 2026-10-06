<?php

declare(strict_types=1);

namespace Arasya\Operations\Quality;

use Arasya\Operations\Auth\AuthenticatedSession;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Security\CsrfGuard;
use Arasya\Operations\Support\Clock;
use PDO;

/**
 * Staff surface of production exceptions and the authenticated live event stream.
 *
 * Staff routes (identity from the session cookie, CSRF on every mutation, strict bodies):
 *   POST /orders/{globalOrderId}/fault-reports       report a cutting fault (tailoring intake)
 *   GET  /production-exceptions/mine                 my open and recent requests
 *   GET  /production-exceptions/reasons              active fault reasons
 *   GET  /production-exceptions/{id}                 detail (only for the detector or responsible employee)
 *   POST /production-exceptions/{id}/acknowledge     responsible employee confirms + QR of the same order
 *   POST /production-exceptions/{id}/rereview        ask for a new decision after a rejection
 *
 * Live: GET /live/events?after=<sequence> answers text/event-stream with the events addressed to the
 * caller (and, for approvers, the approval queue events). Without `after` it only reports the current
 * cursor, so a client loads state through REST first and never replays history twice.
 */
final readonly class QualityController
{
    public function __construct(
        private CuttingFaultService $faults,
        private ExceptionQueries $queries,
        private ApproverPolicy $approvers,
        private LiveEvents $live,
        private AuthenticationService $auth,
        private AuthorizationService $authorization,
        private CsrfGuard $csrf,
        private Config $config,
        private RequestContext $context,
        private PDO $pdo,
        private Clock $clock,
    ) {
    }

    public function report(Request $request, string $globalOrderId): Response
    {
        $session = $this->mutationSession($request);
        $input = $this->body($request, ['expectedVersion', 'itemIds', 'reasonKey', 'comment'], ['expectedVersion', 'itemIds', 'reasonKey']);
        return $this->json($this->faults->report($session->employee, $globalOrderId, $input, $request->header('idempotency-key') ?? '', $request->requestId), 201);
    }

    public function handle(Request $request): Response
    {
        $path = substr($request->path, strlen('/production-exceptions'));
        if ($request->method === 'GET') {
            $actor = $this->session($request)->employee;
            $this->authorization->requireApplication($actor, 'staff');
            if ($path === '/mine') {
                $since = $this->clock->now()->modify('-14 days')->format('Y-m-d H:i:s.u');
                return $this->json(['items' => $this->queries->staffList($actor->employeeUuid, $since)]);
            }
            if ($path === '/reasons') {
                $this->authorization->require($actor, 'orders.report_fault');
                $rows = $this->pdo->query("SELECT reason_key, label, requires_comment FROM production_fault_reasons WHERE status = 'active' ORDER BY sort_order, reason_key")->fetchAll(PDO::FETCH_ASSOC);
                return $this->json(['items' => array_map(static fn (array $row): array => ['key' => (string) $row['reason_key'], 'label' => (string) $row['label'], 'requiresComment' => (int) $row['requires_comment'] === 1], $rows)]);
            }
            if (preg_match('#^/([0-9a-f-]{36})$#D', $path, $m) === 1) {
                $row = $this->queries->find($m[1]);
                if ($row === null || !in_array($actor->employeeUuid, [$row['detector_employee_uuid'], $row['responsible_employee_uuid']], true)) {
                    throw new ApiException(404, 'EXCEPTION_NOT_FOUND', 'The request was not found.');
                }
                return $this->json($this->queries->detail($m[1], $actor->employeeUuid, false));
            }
            throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
        }
        if ($request->method === 'POST' && preg_match('#^/([0-9a-f-]{36})/(acknowledge|rereview)$#D', $path, $m) === 1) {
            $session = $this->mutationSession($request);
            $key = $request->header('idempotency-key') ?? '';
            return $this->json($m[2] === 'acknowledge'
                ? $this->faults->acknowledge($session->employee, $m[1], $this->body($request, ['expectedVersion', 'confirmed', 'qrToken', 'comment'], ['expectedVersion', 'confirmed', 'qrToken']), $key, $request->requestId)
                : $this->faults->requestRereview($session->employee, $m[1], $this->body($request, ['expectedVersion', 'comment'], ['expectedVersion', 'comment']), $key, $request->requestId));
        }
        throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
    }

    public function events(Request $request): Response
    {
        $actor = $this->session($request)->employee;
        if (!$actor->isOperationallyActive()) {
            throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Account is not active.');
        }
        if ($actor->mustChangePassword) {
            throw new ApiException(403, 'PASSWORD_CHANGE_REQUIRED', 'The password must be changed before continuing.');
        }
        $after = $request->query('after');
        $lines = ["retry: 3000\n\n"];
        if ($after === null || $after === '') {
            $lines[] = $this->frame(null, 'ready', ['cursor' => $this->live->latestSequence(), 'authorizationVersion' => $actor->authorizationVersion]);
            return $this->stream($lines);
        }
        if (!ctype_digit($after) || strlen($after) > 18) {
            throw new ApiException(400, 'INVALID_CURSOR', 'The provided cursor is invalid.');
        }
        $cursor = (int) $after;
        $approver = $this->approvers->via($actor) !== null;
        $deadline = microtime(true) + $this->config->liveHoldSeconds;
        do {
            $events = $this->live->after($actor->employeeUuid, $approver, $cursor);
            if ($events !== [] || microtime(true) >= $deadline) {
                break;
            }
            usleep(1_000_000);
        } while (true);
        foreach ($events as $event) {
            $lines[] = $this->frame($event['seq'], $event['type'], $event['payload']);
            $cursor = $event['seq'];
        }
        $lines[] = $this->frame(null, 'cursor', ['cursor' => $cursor, 'authorizationVersion' => $actor->authorizationVersion]);
        return $this->stream($lines);
    }

    /** @param array<string, mixed> $data */
    private function frame(?int $id, string $event, array $data): string
    {
        return ($id === null ? '' : "id: {$id}\n") . "event: {$event}\ndata: " . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    /** @param list<string> $frames */
    private function stream(array $frames): Response
    {
        return Response::file(implode('', $frames), ['Content-Type' => 'text/event-stream; charset=utf-8', 'Cache-Control' => 'private, no-store', 'X-Accel-Buffering' => 'no']);
    }

    private function session(Request $request): AuthenticatedSession
    {
        $session = $this->auth->authenticate($request->cookie($this->config->cookieName()) ?? '', $request->ipAddress, $request->userAgent, $request->requestId);
        $this->context->authenticatedAs($session->employee->employeeUuid);
        return $session;
    }

    private function mutationSession(Request $request): AuthenticatedSession
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
