<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Clock;
use PDO;

/**
 * Who may decide production document revision requests. This is a different authority from production
 * exception approvals: holding production.exceptions.approve (operations managers) grants nothing here.
 *
 * - revision_approver: holds production.documents.approve_revision with Dashboard access (the
 *   "Aprobare revizii documente" template that root assigns to the primary approver);
 * - backup_approver: holds an active, unrevoked document_revision_backup_approver assignment whose
 *   window contains now, with Dashboard access. Re-checked inside every decision transaction, so an
 *   expired or revoked delegation stops immediately;
 * - root: break-glass recovery only, always audited.
 */
final readonly class RevisionApproverPolicy
{
    public const PERMISSION = 'production.documents.approve_revision';
    public const RESPONSIBILITY = 'document_revision_backup_approver';
    public const VIA_APPROVER = 'revision_approver';
    public const VIA_BACKUP = 'backup_approver';
    public const VIA_ROOT = 'root';

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
            return self::VIA_APPROVER;
        }
        return $this->hasActiveBackup($actor->employeeUuid) ? self::VIA_BACKUP : null;
    }

    /** Business approvers only (root is not part of the everyday queue audience). */
    public function isBusinessApprover(EmployeeIdentity $actor): bool
    {
        $via = $this->via($actor);
        return $via === self::VIA_APPROVER || $via === self::VIA_BACKUP;
    }

    public function require(EmployeeIdentity $actor): string
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        return $this->via($actor) ?? throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Nu ai dreptul să aprobi revizii de document.');
    }

    public function hasActiveBackup(string $employeeUuid): bool
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM responsibility_assignments
             WHERE employee_uuid = ? AND responsibility_key = ? AND revoked_at IS NULL
               AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?)
             LIMIT 1',
        );
        $statement->execute([$employeeUuid, self::RESPONSIBILITY, $now, $now]);
        return $statement->fetchColumn() !== false;
    }
}
