<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Security\CsrfGuard;

/**
 * /b2b/accounts routes. Reads need the session; writes also need the exact origin (kernel), CSRF and an
 * Idempotency-Key. Bodies are strict: unknown fields fail. There is no generic PATCH and no DELETE.
 */
final readonly class AccountController
{
    public function __construct(
        private AuthenticationService $auth,
        private AuthorizationService $authorization,
        private CsrfGuard $csrf,
        private Config $config,
        private RequestContext $context,
        private AccountQueries $queries,
        private AccountCommands $commands,
    ) {
    }

    public function handle(Request $request): Response
    {
        $path = substr($request->path, strlen('/b2b/accounts'));
        if (preg_match('#^(?:/([^/]{1,64})(?:/(movements|allocations|open-items|statement|statement\.csv|statement\.pdf|activity|payments|opening-balances|adjustments)(?:/([^/]{1,64})(?:/(reverse|release))?)?)?)?$#D', $path, $m) !== 1) {
            throw new ApiException(404, 'NOT_FOUND', 'Route was not found.');
        }
        [$companyId, $section, $childId, $action] = [$m[1] ?? '', $m[2] ?? '', $m[3] ?? '', $m[4] ?? ''];
        $session = $this->auth->authenticate($request->cookie($this->config->cookieName()) ?? '', $request->ipAddress, $request->userAgent, $request->requestId);
        $actor = $session->employee;
        $this->context->authenticatedAs($actor->employeeUuid);
        AccountAccess::require($this->authorization, $actor);

        if ($request->method === 'GET') {
            $q = $request->query;
            return match (true) {
                $companyId === '' => Response::json($this->queries->overview($actor, $this->filters($q, ['search', 'status', 'balance', 'cursor', 'limit']))),
                $section === '' => Response::json($this->queries->summary($actor, $companyId)),
                $section === 'movements' && $childId === '' => Response::json($this->queries->movements($actor, $companyId, $this->filters($q, ['currency', 'type', 'from', 'to', 'cursor', 'limit']))),
                $section === 'movements' && $action === '' => Response::json($this->queries->movementDetail($actor, $companyId, $childId)),
                $section === 'open-items' && $childId === '' => Response::json($this->queries->openItems($actor, $companyId, $this->filters($q, ['currency']))),
                $section === 'statement' && $childId === '' => Response::json($this->queries->statement($actor, $companyId, $this->filters($q, ['currency', 'from', 'to']))),
                ($section === 'statement.csv' || $section === 'statement.pdf') && $childId === '' => $this->export($actor, $companyId, $section, $this->filters($q, ['currency', 'from', 'to', 'lang'])),
                $section === 'activity' && $childId === '' => Response::json($this->queries->activity($actor, $companyId, $this->filters($q, ['cursor', 'limit']))),
                default => throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed.'),
            };
        }
        if ($request->method !== 'POST' || $companyId === '') throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed.');
        $this->csrf->requireValid($session->rawToken, $request->header('x-csrf-token'));
        $key = $request->header('idempotency-key') ?? '';
        $id = $request->requestId;
        $result = match (true) {
            $section === 'payments' && $childId === '' => $this->commands->recordPayment($actor, $companyId, $this->body($request, AccountInput::PAYMENT_FIELDS), $key, $id),
            $section === 'allocations' && $childId === '' => $this->commands->allocate($actor, $companyId, $this->body($request, AccountInput::ALLOCATION_FIELDS), $key, $id),
            $section === 'allocations' && $action === 'release' => $this->commands->releaseAllocation($actor, $companyId, $childId, $this->body($request, AccountInput::RELEASE_FIELDS), $key, $id),
            $section === 'opening-balances' && $childId === '' => $this->commands->openingBalance($actor, $companyId, $this->body($request, AccountInput::ENTRY_FIELDS), $key, $id),
            $section === 'adjustments' && $childId === '' => $this->commands->adjustment($actor, $companyId, $this->body($request, AccountInput::ENTRY_FIELDS), $key, $id),
            $section === 'movements' && $action === 'reverse' => $this->commands->reverse($actor, $companyId, $childId, $this->body($request, AccountInput::REVERSAL_FIELDS), $key, $id),
            default => throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method is not allowed.'),
        };
        $status = (int) $result['status'];
        unset($result['status']);
        // Replays answer with the recorded references plus the current summary, only while the actor may view it.
        $result['summary'] = $this->authorization->can($actor, AccountAccess::VIEW) ? $this->queries->summary($actor, $companyId) : null;
        return Response::json($result, $status);
    }

    private function export(\Arasya\Operations\Employee\EmployeeIdentity $actor, string $companyId, string $section, array $filters): Response
    {
        $lang = AccountStatementExport::language($filters['lang'] ?? null);
        unset($filters['lang']);
        $statement = $this->queries->statement($actor, $companyId, $filters, true);
        $csv = $section === 'statement.csv';
        $body = $csv ? AccountStatementExport::csv($statement, $lang) : AccountStatementExport::pdf($statement, $lang);
        return Response::file($body, [
            'Content-Type' => $csv ? 'text/csv; charset=utf-8' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . AccountStatementExport::filename($statement, $csv ? 'csv' : 'pdf') . '"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function body(Request $request, array $allowed): array
    {
        $data = $request->json(262_144);
        if (array_diff(array_keys($data), $allowed) !== []) throw new ApiException(400, 'INVALID_REQUEST', 'Invalid request fields.');
        return $data;
    }

    /** @param array<string, mixed> $query @param list<string> $allowed @return array<string, string> */
    private function filters(array $query, array $allowed): array
    {
        if (array_diff(array_keys($query), $allowed) !== []) throw new ApiException(400, 'INVALID_REQUEST', 'Unknown filter.');
        $out = [];
        foreach ($query as $field => $value) {
            if (!is_string($value) || strlen($value) > 1400) throw new ApiException(400, 'INVALID_REQUEST', 'Invalid filter.');
            if ($value !== '') $out[$field] = $value;
        }
        return $out;
    }
}
