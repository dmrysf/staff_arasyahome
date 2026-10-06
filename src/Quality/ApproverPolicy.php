<?php

declare(strict_types=1);

namespace Arasya\Operations\Quality;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Clock;
use PDO;

/**
 * Who may decide production exception requests, and through which authority.
 *
 * - root: always (it is above every business mechanism);
 * - operations_manager: holds production.exceptions.approve with Dashboard access (the shared
 *   "Manager operațional" role);
 * - backup_approver: holds an active, unrevoked operations_backup_approver assignment whose time
 *   window contains now, with Dashboard access. The window is re-checked on every request.
 *
 * The organisation's CEO principal is the only non-root identity that may appoint backups and the
 * tailoring intake responsible; see OrganizationService.
 */
final readonly class ApproverPolicy
{
    public const PERMISSION = 'production.exceptions.approve';
    public const VIA_ROOT = 'root';
    public const VIA_MANAGER = 'operations_manager';
    public const VIA_BACKUP = 'backup_approver';

    public function __construct(private PDO $pdo, private AuthorizationService $authorization, private Clock $clock)
    {
    }

    public function via(EmployeeIdentity $actor): ?string
    {
        if (!$actor->isOperationallyActive() || $actor->mustChangePassword || !$actor->hasApplication('dashboard')) {
            return null;
        }
        if ($actor->isRoot) {
            return self::VIA_ROOT;
        }
        if ($this->authorization->can($actor, self::PERMISSION)) {
            return self::VIA_MANAGER;
        }
        return $this->hasActiveBackup($actor->employeeUuid) ? self::VIA_BACKUP : null;
    }

    public function require(EmployeeIdentity $actor): string
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        return $this->via($actor) ?? throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');
    }

    public function hasActiveBackup(string $employeeUuid): bool
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM responsibility_assignments
             WHERE employee_uuid = ? AND responsibility_key = 'operations_backup_approver' AND revoked_at IS NULL
               AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?)
             LIMIT 1",
        );
        $statement->execute([$employeeUuid, $now, $now]);
        return $statement->fetchColumn() !== false;
    }

    /** Whether this actor may use exact order lookup (approvers need it to understand a request). */
    public function canLookup(EmployeeIdentity $actor): bool
    {
        return $actor->hasApplication('dashboard')
            && ($this->authorization->can($actor, 'orders.lookup_exact') || $this->authorization->can($actor, 'orders.view_all') || $this->via($actor) !== null);
    }
}
