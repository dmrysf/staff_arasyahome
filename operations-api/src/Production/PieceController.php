<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Auth\AuthenticatedSession;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Security\CsrfGuard;

/**
 * Manufacturing manifest and physical pieces (API 2.32.0). Identity from the session cookie, CSRF and an
 * Idempotency-Key on every mutation, strict bodies.
 *
 *   GET  /orders/{globalOrderId}/manufacturing-plan                          lines, plans, proposals, pieces, routes
 *   POST /orders/{globalOrderId}/manufacturing-plan/lines/{itemId}/confirm  confirm or revise the plan of one line
 *   GET  /production-locations                                               registered physical locations
 *   POST /production-units/{unitId}/custody                                  take | locate | hold | release (scan required)
 *   POST /production-units/reunite                                           pieces physically together (no stage change)
 *   POST /production-units/{unitId}/material                                 actual | replacement_actual | waste | remnant | correction
 *   GET  /production-units/{unitId}/history                                  lineage, labels, stage ledger, custody, material
 *   POST /production-units/{unitId}/route-evidence                           verified evidence of stages before the piece's ledger (030)
 *   POST /production-units/{unitId}/assisted-checkpoint                      informational PERDEA auxiliary-work status at stage 6 / 7 (031)
 */
final readonly class PieceController
{
    public function __construct(
        private PieceOperationsService $pieces,
        private AuthenticationService $auth,
        private CsrfGuard $csrf,
        private Config $config,
        private RequestContext $context,
    ) {
    }

    public function plan(Request $request, string $globalOrderId, ?string $itemId): Response
    {
        if ($itemId === null) {
            if ($request->method !== 'GET') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Metoda nu este permisă pentru această rută.');
            }
            return $this->json($this->pieces->planView($this->session($request)->employee, $globalOrderId));
        }
        if ($request->method !== 'POST') {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Metoda nu este permisă pentru această rută.');
        }
        $session = $this->mutationSession($request);
        $input = $this->body($request, ['expectedRevision', 'expectedSourceHash', 'productType', 'pieceCount', 'pieces', 'equalSplit', 'materialTotalMeters', 'note'], ['expectedRevision', 'expectedSourceHash', 'productType', 'pieceCount', 'note']);
        return $this->json($this->pieces->confirmPlan($session->employee, $globalOrderId, $itemId, $input, $request->header('idempotency-key') ?? '', $request->requestId));
    }

    public function handle(Request $request): Response
    {
        if ($request->path === '/production-locations') {
            if ($request->method !== 'GET') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Metoda nu este permisă pentru această rută.');
            }
            return $this->json($this->pieces->locations($this->session($request)->employee));
        }
        if ($request->path === '/production-units/reunite') {
            if ($request->method !== 'POST') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Metoda nu este permisă pentru această rută.');
            }
            $session = $this->mutationSession($request);
            $input = $this->body($request, ['unitIds', 'locationId', 'confirmed'], ['unitIds', 'confirmed']);
            return $this->json($this->pieces->reunite($session->employee, $input, $request->header('idempotency-key') ?? '', $request->requestId));
        }
        if (preg_match('#^/production-units/([0-9a-f-]{36})/(custody|material|history|route-evidence|assisted-checkpoint)$#D', $request->path, $m) !== 1) {
            throw new ApiException(404, 'NOT_FOUND', 'Ruta API nu a fost găsită.');
        }
        if ($m[2] === 'history') {
            if ($request->method !== 'GET') {
                throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Metoda nu este permisă pentru această rută.');
            }
            return $this->json($this->pieces->history($this->session($request)->employee, $m[1]));
        }
        if ($request->method !== 'POST') {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Metoda nu este permisă pentru această rută.');
        }
        $session = $this->mutationSession($request);
        $key = $request->header('idempotency-key') ?? '';
        if ($m[2] === 'route-evidence') {
            $input = $this->body($request, ['stages', 'uncertainStages', 'note'], ['note']);
            return $this->json($this->pieces->reconcileRoute($session->employee, $m[1], $input, $key, $request->requestId), 201);
        }
        if ($m[2] === 'assisted-checkpoint') {
            $input = $this->body($request, ['stageId', 'status', 'expectedVersion', 'qrToken', 'note'], ['stageId', 'status', 'expectedVersion']);
            return $this->json($this->pieces->assistedCheckpoint($session->employee, $m[1], $input, $key, $request->requestId));
        }
        if ($m[2] === 'custody') {
            $input = $this->body($request, ['action', 'expectedVersion', 'locationId', 'holdReason', 'qrToken', 'note'], ['action', 'expectedVersion', 'qrToken']);
            return $this->json($this->pieces->custody($session->employee, $m[1], $input, $key, $request->requestId));
        }
        $input = $this->body($request, ['kind', 'meters', 'note', 'correctsRecordId'], ['kind', 'meters']);
        return $this->json($this->pieces->recordMaterial($session->employee, $m[1], $input, $key, $request->requestId), 201);
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
        $input = $request->json(32_768);
        $keys = array_keys($input);
        if (array_diff($keys, $allowed) !== [] || array_diff($required, $keys) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Câmpurile cererii nu sunt valide.');
        }
        return $input;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status, ['Cache-Control' => 'private, no-store']);
    }
}
