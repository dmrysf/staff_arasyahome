<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Support\StructuredLogger;
use PDO;
use Throwable;

/**
 * Keeps refused money commands in the database (finance_refused_attempts, migration 052): a write on a Finance route or a
 * B2B account route that a signed-in person was refused for four eyes, a missing permission or application, a duplicate
 * bank transaction or a closed B2B write path. The web server error log is rotated within days; this table is not.
 *
 * Only the actor, the route, the status, the error code and the request id are stored: never the request body, an amount
 * or a bank identity. Recording never changes the answer: a failure is logged and the refusal is returned as before.
 */
final readonly class RefusedAttemptRecorder
{
    public const CODES = [
        'FOUR_EYES_REQUIRED', 'UNAUTHORIZED_ACTION', 'APPLICATION_ACCESS_DENIED', 'NOT_REQUEST_OWNER', 'CSRF_INVALID', 'ORIGIN_DENIED',
        'BANK_TRANSACTION_ALREADY_RECORDED', 'POSSIBLE_DUPLICATE_UNACKNOWLEDGED',
        'ACCOUNT_WRITES_MOVED_TO_FINANCE', 'MOVEMENT_FOLLOWS_FINANCE', 'REFUND_ALLOCATION_FIXED', 'FINANCE_COLLECTIONS_DISABLED',
    ];

    public function __construct(private PDO $pdo, private StructuredLogger $logger)
    {
    }

    public function record(Request $request, ApiException $error, ?string $employeeUuid): void
    {
        if ($employeeUuid === null || $request->method === 'GET' || $request->method === 'OPTIONS' || !in_array($error->errorCode, self::CODES, true)) {
            return;
        }
        if (!str_starts_with($request->path, '/finance/') && !str_starts_with($request->path, '/b2b/accounts/')) {
            return;
        }
        try {
            if ($this->pdo->inTransaction()) {
                // Every command rolls its own transaction back before it throws; an open one here is a defect elsewhere.
                throw new \LogicException('A transaction is still open.');
            }
            $route = substr((string) preg_replace('~[^A-Za-z0-9/_.-]~', '?', $request->path), 0, 255);
            $this->pdo->prepare('INSERT INTO finance_refused_attempts (employee_uuid, http_method, route, http_status, error_code, request_id, occurred_at)
                VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))')
                ->execute([$employeeUuid, substr($request->method, 0, 8), $route, $error->status, $error->errorCode, substr($request->requestId, 0, 64)]);
        } catch (Throwable $failure) {
            $this->logger->log('error', 'refused_attempt_not_recorded', $request->requestId, ['route' => $request->path, 'code' => $error->errorCode, 'reason' => $failure::class]);
        }
    }
}
