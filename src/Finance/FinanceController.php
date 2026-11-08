<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance;

use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Iam\ApplicationAccess;
use PDO;

/**
 * Finance-only, read-only facade over existing authoritative B2B tables.
 * Never uses b2b.access to authorize a Finance actor and never exposes payment commands.
 * This controller cannot be reached without an explicit Finance application grant.
 */
final readonly class FinanceController
{
    public function __construct(
        private AuthenticationService $auth,
        private AuthorizationService $authorization,
        private Config $config,
        private RequestContext $context,
        private PDO $pdo,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($request->method !== 'GET') {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Finance routes are read only.');
        }
        $session = $this->auth->authenticate(
            $request->cookie($this->config->cookieName()) ?? '',
            $request->ipAddress,
            $request->userAgent,
            $request->requestId,
        );
        $actor = $session->employee;
        $this->context->authenticatedAs($actor->employeeUuid);
        $this->authorization->requireApplication($actor, ApplicationAccess::FINANCE);
        $this->authorization->require($actor, 'finance.access');

        if ($request->path === '/finance/access') {
            if ($request->query !== []) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Unexpected filters.');
            }
            return $this->secure([
                'application' => ApplicationAccess::FINANCE,
                'employee' => [
                    'displayName' => $actor->displayName,
                    'username' => $actor->username,
                ],
                'authorizationVersion' => $actor->authorizationVersion,
                'capabilities' => [
                    'canViewAccounts' => $this->authorization->can($actor, 'finance.accounts.view'),
                    'canViewCompanies' => $this->authorization->can($actor, 'finance.companies.view'),
                ],
            ]);
        }

        $this->authorization->require($actor, match ($request->path) {
            '/finance/b2b/accounts' => 'finance.accounts.view',
            '/finance/companies' => 'finance.companies.view',
            default => throw new ApiException(404, 'NOT_FOUND', 'Finance route not found.'),
        });

        $limit = $this->filters($request);
        if ($request->path === '/finance/b2b/accounts') {
            return $this->secure($this->accounts($limit['size'], $limit['cursor']));
        }
        return $this->secure($this->companies($limit['size'], $limit['cursor']));
    }

    /** @return array{size: int, cursor: string} */
    private function filters(Request $request): array
    {
        if (array_diff(array_keys($request->query), ['limit', 'cursor']) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Unexpected filters.');
        }
        foreach ($request->query as $key => $value) {
            if (!is_string($value)) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Invalid filter type.');
            }
        }
        $size = $request->query('limit', '25');
        if (!in_array($size, ['25', '50'], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Invalid page size.');
        }
        $cursor = $request->query('cursor', '') ?? '';
        if ($cursor !== '' && preg_match('/^B2B-[0-9]{6,12}$/D', $cursor) !== 1) {
            throw new ApiException(400, 'INVALID_CURSOR', 'Invalid cursor.');
        }
        return ['size' => (int) $size, 'cursor' => $cursor];
    }

    /** @return array<string,mixed> */
    private function companies(int $size, string $cursor): array
    {
        $statement = $this->pdo->prepare(
            'SELECT company_uuid, company_code, legal_name, country_code, status
             FROM b2b_companies WHERE company_code > :cursor
             ORDER BY company_code LIMIT ' . ($size + 1)
        );
        $statement->execute(['cursor' => $cursor]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasNext = count($rows) > $size;
        if ($hasNext) {
            array_pop($rows);
        }
        $nextCursor = $hasNext && $rows !== [] ? (string) $rows[count($rows) - 1]['company_code'] : null;
        return [
            'items' => array_map(static fn (array $r): array => [
                'companyId' => (string) $r['company_uuid'],
                'companyCode' => (string) $r['company_code'],
                'legalName' => (string) $r['legal_name'],
                'countryCode' => (string) $r['country_code'],
                'companyStatus' => (string) $r['status'],
            ], $rows),
            'nextCursor' => $nextCursor,
        ];
    }

    /** @return array<string,mixed> */
    private function accounts(int $size, string $cursor): array
    {
        $statement = $this->pdo->prepare(
            "SELECT c.company_uuid, c.company_code, c.legal_name, c.status,
                COALESCE(SUM(CASE WHEN m.currency_code='RON' THEN
                    CASE m.direction WHEN 'debit' THEN m.amount ELSE -m.amount END
                    ELSE 0 END),0) AS ron,
                COALESCE(SUM(CASE WHEN m.currency_code='EUR' THEN
                    CASE m.direction WHEN 'debit' THEN m.amount ELSE -m.amount END
                    ELSE 0 END),0) AS eur
             FROM b2b_companies c
             LEFT JOIN b2b_account_movements m ON m.company_uuid=c.company_uuid
             WHERE c.company_code > :cursor
             GROUP BY c.company_uuid,c.company_code,c.legal_name,c.status
             ORDER BY c.company_code LIMIT " . ($size + 1)
        );
        $statement->execute(['cursor' => $cursor]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasNext = count($rows) > $size;
        if ($hasNext) {
            array_pop($rows);
        }
        $nextCursor = $hasNext && $rows !== [] ? (string) $rows[count($rows) - 1]['company_code'] : null;
        return [
            'items' => array_map(static fn (array $r): array => [
                'companyId' => (string) $r['company_uuid'],
                'companyCode' => (string) $r['company_code'],
                'legalName' => (string) $r['legal_name'],
                'companyStatus' => (string) $r['status'],
                'balances' => ['RON' => (string) $r['ron'], 'EUR' => (string) $r['eur']],
            ], $rows),
            'nextCursor' => $nextCursor,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function secure(array $payload): Response
    {
        return Response::json($payload, 200, ['Cache-Control' => 'no-store, private']);
    }
}
