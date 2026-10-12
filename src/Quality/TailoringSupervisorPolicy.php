<?php

declare(strict_types=1);

namespace Arasya\Operations\Quality;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Support\Clock;
use PDO;

/**
 * Who may supervise tailoring defects (stages 3-12) for an order source.
 *
 * Authority comes only from an active, unrevoked `tailoring_quality_supervisor` appointment of the
 * employee's UUID whose time window contains now, made by root or the CEO principal. A username, display
 * name, department, role or manager relationship never grants it. The supervisor must also be an active
 * Staff employee who works at tailoring intake for the order's source, because the defective order is
 * returned there under the supervisor's custody.
 *
 * This authority never decides the workshop-receiving -> material-preparation return: that stays with
 * ApproverPolicy, and an involved supervisor is refused there even when they also hold approval rights.
 */
final readonly class TailoringSupervisorPolicy
{
    public const RESPONSIBILITY = 'tailoring_quality_supervisor';
    public const CUSTODY_STAGE = 'workshop-receiving';
    /** Stages 3-12: tailoring and quality control. */
    public const TAILORING_STAGES = ['workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control'];
    /** Sources that use the supervised flow; B2B keeps the original intake-only return. */
    public const SOURCES = ['trendyol', 'trendhome', 'outletperdele'];

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /** Whether the supervised flow is active: someone currently holds the appointment. */
    public function isActive(): bool
    {
        return $this->appointedUuids() !== [];
    }

    /**
     * Employees whose appointment window contains now (at most one by construction). Inside a command
     * transaction the rows are read with a shared lock, so a concurrent revocation waits for the command
     * (or the command sees the revocation) instead of acting on a stale snapshot. @return list<string>
     */
    public function appointedUuids(): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare(
            'SELECT employee_uuid FROM responsibility_assignments
             WHERE responsibility_key = ? AND revoked_at IS NULL AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?)
             ORDER BY starts_at DESC' . ($this->pdo->inTransaction() ? ' LOCK IN SHARE MODE' : ''),
        );
        $statement->execute([self::RESPONSIBILITY, $now, $now]);
        return array_values(array_unique(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN))));
    }

    public function isAppointed(string $employeeUuid): bool
    {
        return in_array($employeeUuid, $this->appointedUuids(), true);
    }

    /** Full check for one source. Callers re-load the employee inside their transaction before relying on it. */
    public function supervises(EmployeeIdentity $employee, string $sourceKey): bool
    {
        return !$employee->isRoot
            && $employee->isOperationallyActive()
            && !$employee->mustChangePassword
            && $employee->hasApplication('staff')
            && in_array($sourceKey, self::SOURCES, true)
            && $employee->worksAt(self::CUSTODY_STAGE, $sourceKey)
            && $this->isAppointed($employee->employeeUuid);
    }

    /** Sources this employee supervises now. @return list<string> */
    public function sourcesOf(EmployeeIdentity $employee): array
    {
        return array_values(array_filter(self::SOURCES, fn (string $source): bool => $this->supervises($employee, $source)));
    }

    public static function supervisedFlowApplies(string $sourceKey): bool
    {
        return in_array($sourceKey, self::SOURCES, true);
    }
}
