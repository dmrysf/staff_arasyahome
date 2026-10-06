<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Quality\ApproverPolicy;
use Arasya\Operations\Quality\ExceptionQueries;
use Arasya\Operations\Quality\IdempotencyStore;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use PDO;
use Throwable;

/**
 * Organisation controls owned by the business principal.
 *
 * - Root designates the CEO principal (organization_principals.ceo). Nobody else can.
 * - Root and the CEO principal (and nobody else, whatever their permissions) may:
 *   appoint/replace/remove the tailoring intake responsible, appoint/revoke temporary operations backup
 *   approvers, and change working hours.
 * - Every change is idempotent, runs in one transaction and writes iam_audit_events with before/after.
 *
 * These controls are deliberately not permissions: a permission could be placed in a role by anyone
 * who holds it, while these powers must stay with exactly one business identity below root.
 */
final readonly class OrganizationService
{
    public const TIMEZONE = 'Europe/Bucharest';
    public const TAILORING_INTAKE = 'tailoring_intake_responsible';
    public const OPERATIONS_BACKUP = 'operations_backup_approver';
    private const BACKUP_MAX_DAYS = 90;

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private IdempotencyStore $idempotency,
        private IamAuditLogger $audit,
        private Clock $clock,
    ) {
    }

    public function isCeo(EmployeeIdentity $actor): bool
    {
        if ($actor->isRoot || !$actor->isOperationallyActive()) {
            return false;
        }
        $statement = $this->pdo->prepare("SELECT 1 FROM organization_principals WHERE principal_key = 'ceo' AND employee_uuid = ?");
        $statement->execute([$actor->employeeUuid]);
        return $statement->fetchColumn() !== false;
    }

    public function canManage(EmployeeIdentity $actor): bool
    {
        return $actor->hasApplication('dashboard') && $actor->isOperationallyActive() && !$actor->mustChangePassword && ($actor->isRoot || $this->isCeo($actor));
    }

    /** @return array<string, mixed> */
    public function view(EmployeeIdentity $actor): array
    {
        $this->requireManager($actor);
        $ceo = $this->pdo->query("SELECT p.employee_uuid, e.display_name, e.position_title, p.assigned_at FROM organization_principals p INNER JOIN employees e ON e.employee_uuid = p.employee_uuid WHERE p.principal_key = 'ceo'")->fetch(PDO::FETCH_ASSOC);
        $now = $this->now();
        $rows = $this->pdo->prepare(
            "SELECT a.assignment_uuid, a.responsibility_key, a.employee_uuid, e.display_name, a.starts_at, a.ends_at, a.note, a.created_at, c.display_name AS created_by,
                    a.revoked_at, r.display_name AS revoked_by, a.revoke_reason
             FROM responsibility_assignments a
             INNER JOIN employees e ON e.employee_uuid = a.employee_uuid
             INNER JOIN employees c ON c.employee_uuid = a.created_by_employee_uuid
             LEFT JOIN employees r ON r.employee_uuid = a.revoked_by_employee_uuid
             ORDER BY a.created_at DESC LIMIT 100",
        );
        $rows->execute();
        $items = array_map(fn (array $row): array => [
            'id' => (string) $row['assignment_uuid'],
            'responsibility' => (string) $row['responsibility_key'],
            'employee' => ['id' => (string) $row['employee_uuid'], 'displayName' => (string) $row['display_name']],
            'startsAt' => ExceptionQueries::iso((string) $row['starts_at']),
            'endsAt' => ExceptionQueries::iso($row['ends_at']),
            'note' => $row['note'],
            'createdAt' => ExceptionQueries::iso((string) $row['created_at']),
            'createdBy' => (string) $row['created_by'],
            'revokedAt' => ExceptionQueries::iso($row['revoked_at']),
            'revokedBy' => $row['revoked_by'],
            'revokeReason' => $row['revoke_reason'],
            'state' => $this->state($row, $now),
        ], $rows->fetchAll(PDO::FETCH_ASSOC));
        return [
            'ceo' => is_array($ceo) ? ['id' => (string) $ceo['employee_uuid'], 'displayName' => (string) $ceo['display_name'], 'positionTitle' => $ceo['position_title'], 'since' => ExceptionQueries::iso((string) $ceo['assigned_at'])] : null,
            'canDesignateCeo' => $actor->isRoot,
            'timezone' => self::TIMEZONE,
            'workingHours' => $this->workingHours(),
            'responsibilities' => $items,
        ];
    }

    /** @return array<string, mixed> */
    public function designateCeo(EmployeeIdentity $actor, mixed $employeeId, string $key, string $requestId): array
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        if (!$actor->isRoot) {
            throw new ApiException(403, 'ROOT_ONLY', 'Only the principal administrator can designate the CEO.');
        }
        IdempotencyStore::requireKey($key);
        $target = $this->uuid($employeeId);
        $hash = IdempotencyStore::hash('organization.ceo', 'ceo', [$target]);
        return $this->transaction(function () use ($actor, $target, $key, $hash, $requestId): array {
            $this->pdo->query("SELECT principal_key FROM organization_principals WHERE principal_key = 'ceo' FOR UPDATE")->fetchAll();
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'organization.ceo', 'ceo', $hash);
            if ($replay !== null) {
                return $replay;
            }
            $employee = $this->lockEligible($target);
            $before = $this->pdo->query("SELECT employee_uuid FROM organization_principals WHERE principal_key = 'ceo'")->fetchColumn();
            $now = $this->now();
            $this->pdo->prepare("DELETE FROM organization_principals WHERE principal_key = 'ceo'")->execute();
            $this->pdo->prepare("INSERT INTO organization_principals (principal_key, employee_uuid, assigned_at, assigned_by_employee_uuid) VALUES ('ceo', ?, ?, ?)")->execute([$target, $now, $actor->employeeUuid]);
            $this->audit->record($actor, 'organization.ceo_designated', 'organization', 'ceo', 'CEO', ['employee' => ['before' => $before === false ? null : (string) $before, 'after' => $target], 'displayName' => $employee['display_name']], $requestId, $now);
            $response = $this->view($actor);
            $this->idempotency->store($actor->employeeUuid, $key, 'organization.ceo', 'ceo', $hash, $response, $now);
            return $response;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function assign(EmployeeIdentity $actor, array $input, string $key, string $requestId): array
    {
        $this->requireManager($actor);
        IdempotencyStore::requireKey($key);
        $responsibility = $input['responsibility'] ?? null;
        if (!in_array($responsibility, [self::TAILORING_INTAKE, self::OPERATIONS_BACKUP], true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Unknown responsibility.');
        }
        $target = $this->uuid($input['employeeId'] ?? null);
        $now = $this->clock->now();
        $startsAt = array_key_exists('startsAt', $input) && $input['startsAt'] !== null ? $this->instant($input['startsAt'], 'startsAt') : $now;
        $endsAt = array_key_exists('endsAt', $input) && $input['endsAt'] !== null ? $this->instant($input['endsAt'], 'endsAt') : null;
        $note = $input['note'] ?? null;
        if ($note !== null && (!is_string($note) || mb_strlen(trim($note)) > 500)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'note is invalid.');
        }
        $note = $note === null || trim($note) === '' ? null : trim($note);
        if ($responsibility === self::OPERATIONS_BACKUP) {
            if ($endsAt === null) {
                throw new ApiException(422, 'END_REQUIRED', 'A temporary backup needs an end time.');
            }
            if ($endsAt->getTimestamp() - $startsAt->getTimestamp() > self::BACKUP_MAX_DAYS * 86_400) {
                throw new ApiException(422, 'WINDOW_TOO_LONG', 'A temporary backup can last at most 90 days.');
            }
        }
        if ($endsAt !== null && ($endsAt <= $startsAt || $endsAt <= $now)) {
            throw new ApiException(422, 'WINDOW_INVALID', 'The end must be after the start and in the future.');
        }
        $starts = $startsAt->format('Y-m-d H:i:s.u');
        $ends = $endsAt?->format('Y-m-d H:i:s.u');
        $hash = IdempotencyStore::hash('organization.assign', $responsibility, [$target, $starts, $ends, $note]);

        return $this->transaction(function () use ($actor, $responsibility, $target, $starts, $ends, $note, $key, $hash, $requestId): array {
            $this->lockResponsibility($responsibility);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'organization.assign', $responsibility, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $employee = $this->lockEligible($target);
            $identity = $this->pdo->prepare("SELECT application_key FROM employee_application_access WHERE employee_uuid = ? AND application_key = 'dashboard'");
            $identity->execute([$target]);
            $now = $this->now();
            $replaced = [];
            if ($responsibility === self::OPERATIONS_BACKUP) {
                if ($identity->fetchColumn() === false) {
                    throw new ApiException(422, 'EMPLOYEE_NEEDS_DASHBOARD', 'The backup approver needs Dashboard access to decide requests.');
                }
                $holds = $this->pdo->prepare(
                    "SELECT 1 FROM employee_role_assignments era INNER JOIN roles r ON r.role_id = era.role_id AND r.status = 'active'
                     INNER JOIN role_permissions rp ON rp.role_id = r.role_id INNER JOIN permissions p ON p.permission_id = rp.permission_id
                     WHERE era.employee_uuid = ? AND p.permission_key = ? LIMIT 1",
                );
                $holds->execute([$target, ApproverPolicy::PERMISSION]);
                if ($holds->fetchColumn() !== false) {
                    throw new ApiException(422, 'ALREADY_APPROVER', 'This employee already approves as an operations manager.');
                }
            } else {
                // One tailoring intake responsible at a time: the previous appointment ends now.
                $current = $this->pdo->prepare("SELECT assignment_uuid, employee_uuid FROM responsibility_assignments WHERE responsibility_key = ? AND revoked_at IS NULL AND (ends_at IS NULL OR ends_at > ?) FOR UPDATE");
                $current->execute([$responsibility, $now]);
                foreach ($current->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $this->pdo->prepare("UPDATE responsibility_assignments SET revoked_at = ?, revoked_by_employee_uuid = ?, revoke_reason = 'Înlocuit de o nouă numire' WHERE assignment_uuid = ?")
                        ->execute([$now, $actor->employeeUuid, $row['assignment_uuid']]);
                    $replaced[] = ['assignmentId' => (string) $row['assignment_uuid'], 'employeeId' => (string) $row['employee_uuid']];
                }
            }
            $uuid = Uuid::v4();
            $this->pdo->prepare(
                'INSERT INTO responsibility_assignments (assignment_uuid, responsibility_key, employee_uuid, starts_at, ends_at, note, created_at, created_by_employee_uuid) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([$uuid, $responsibility, $target, $starts, $ends, $note, $now, $actor->employeeUuid]);
            $this->audit->record($actor, 'responsibility.assigned', 'responsibility', $uuid, "{$responsibility} · {$employee['display_name']}", [
                'responsibility' => $responsibility,
                'employee' => ['before' => $replaced === [] ? null : array_column($replaced, 'employeeId'), 'after' => $target],
                'window' => ['startsAt' => $starts, 'endsAt' => $ends],
                'replaced' => $replaced,
                'authority' => $actor->isRoot ? 'root' : 'ceo',
            ], $requestId, $now);
            $response = $this->view($actor);
            $this->idempotency->store($actor->employeeUuid, $key, 'organization.assign', $responsibility, $hash, $response, $now);
            return $response;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function revoke(EmployeeIdentity $actor, string $assignmentId, array $input, string $key, string $requestId): array
    {
        $this->requireManager($actor);
        IdempotencyStore::requireKey($key);
        $uuid = $this->uuid($assignmentId);
        $reason = $input['reason'] ?? null;
        if ($reason !== null && (!is_string($reason) || mb_strlen(trim($reason)) > 500)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'reason is invalid.');
        }
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        $hash = IdempotencyStore::hash('organization.revoke', $uuid, [$reason]);
        return $this->transaction(function () use ($actor, $uuid, $reason, $key, $hash, $requestId): array {
            $statement = $this->pdo->prepare('SELECT a.*, e.display_name FROM responsibility_assignments a INNER JOIN employees e ON e.employee_uuid = a.employee_uuid WHERE a.assignment_uuid = ? FOR UPDATE');
            $statement->execute([$uuid]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: throw new ApiException(404, 'ASSIGNMENT_NOT_FOUND', 'The assignment was not found.');
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'organization.revoke', $uuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $now = $this->now();
            if ($row['revoked_at'] !== null || ($row['ends_at'] !== null && (string) $row['ends_at'] <= $now)) {
                throw new ApiException(409, 'ASSIGNMENT_ALREADY_ENDED', 'The assignment has already ended.');
            }
            $this->pdo->prepare('UPDATE responsibility_assignments SET revoked_at = ?, revoked_by_employee_uuid = ?, revoke_reason = ? WHERE assignment_uuid = ? AND revoked_at IS NULL')
                ->execute([$now, $actor->employeeUuid, $reason, $uuid]);
            $this->audit->record($actor, 'responsibility.revoked', 'responsibility', $uuid, "{$row['responsibility_key']} · {$row['display_name']}", [
                'responsibility' => (string) $row['responsibility_key'],
                'employeeId' => (string) $row['employee_uuid'],
                'endsAt' => ['before' => $row['ends_at'], 'after' => $now],
                'reason' => $reason,
                'authority' => $actor->isRoot ? 'root' : 'ceo',
            ], $requestId, $now);
            $response = $this->view($actor);
            $this->idempotency->store($actor->employeeUuid, $key, 'organization.revoke', $uuid, $hash, $response, $now);
            return $response;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function setWorkingHours(EmployeeIdentity $actor, array $input, string $key, string $requestId): array
    {
        $this->requireManager($actor);
        IdempotencyStore::requireKey($key);
        $days = $input['days'] ?? null;
        if (!is_array($days) || !array_is_list($days) || count($days) !== 7) {
            throw new ApiException(400, 'INVALID_REQUEST', 'days must list all seven weekdays.');
        }
        $parsed = [];
        foreach ($days as $day) {
            if (!is_array($day) || array_diff(array_keys($day), ['weekday', 'isOpen', 'opensAt', 'closesAt']) !== [] || !is_int($day['weekday'] ?? null) || !is_bool($day['isOpen'] ?? null)) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Each day needs weekday and isOpen.');
            }
            $weekday = $day['weekday'];
            if ($weekday < 1 || $weekday > 7 || isset($parsed[$weekday])) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Weekdays must be 1 to 7, once each.');
            }
            if ($day['isOpen']) {
                $opens = $this->time($day['opensAt'] ?? null);
                $closes = $this->time($day['closesAt'] ?? null);
                if ($closes <= $opens) {
                    throw new ApiException(422, 'HOURS_INVALID', 'Closing time must be after opening time.');
                }
                $parsed[$weekday] = [1, $opens, $closes];
            } else {
                if (($day['opensAt'] ?? null) !== null || ($day['closesAt'] ?? null) !== null) {
                    throw new ApiException(400, 'INVALID_REQUEST', 'A closed day has no hours.');
                }
                $parsed[$weekday] = [0, null, null];
            }
        }
        ksort($parsed);
        $hash = IdempotencyStore::hash('organization.hours', 'working-hours', $parsed);
        return $this->transaction(function () use ($actor, $parsed, $key, $hash, $requestId): array {
            $this->pdo->query('SELECT weekday FROM business_hours FOR UPDATE')->fetchAll();
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'organization.hours', 'working-hours', $hash);
            if ($replay !== null) {
                return $replay;
            }
            $before = $this->workingHours();
            $now = $this->now();
            $update = $this->pdo->prepare('UPDATE business_hours SET is_open = ?, opens_at = ?, closes_at = ?, updated_at = ?, updated_by_employee_uuid = ? WHERE weekday = ?');
            foreach ($parsed as $weekday => [$open, $opens, $closes]) {
                $update->execute([$open, $opens, $closes, $now, $actor->employeeUuid, $weekday]);
            }
            $after = $this->workingHours();
            $this->audit->record($actor, 'organization.working_hours_changed', 'organization', 'working-hours', 'Program de lucru', ['days' => ['before' => $before, 'after' => $after], 'timezone' => self::TIMEZONE, 'authority' => $actor->isRoot ? 'root' : 'ceo'], $requestId, $now);
            $response = $this->view($actor);
            $this->idempotency->store($actor->employeeUuid, $key, 'organization.hours', 'working-hours', $hash, $response, $now);
            return $response;
        });
    }

    /** @return list<array<string, mixed>> */
    public function workingHours(): array
    {
        return array_map(static fn (array $row): array => [
            'weekday' => (int) $row['weekday'],
            'isOpen' => (int) $row['is_open'] === 1,
            'opensAt' => $row['opens_at'] === null ? null : substr((string) $row['opens_at'], 0, 5),
            'closesAt' => $row['closes_at'] === null ? null : substr((string) $row['closes_at'], 0, 5),
        ], $this->pdo->query('SELECT weekday, is_open, opens_at, closes_at FROM business_hours ORDER BY weekday')->fetchAll(PDO::FETCH_ASSOC));
    }

    private function requireManager(EmployeeIdentity $actor): void
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        if (!$this->canManage($actor)) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Only the principal administrator or the CEO can change organisation controls.');
        }
    }

    /** Responsibilities are assigned one at a time per key: the lock serializes concurrent appointments. */
    private function lockResponsibility(string $responsibility): void
    {
        $statement = $this->pdo->prepare('SELECT assignment_uuid FROM responsibility_assignments WHERE responsibility_key = ? FOR UPDATE');
        $statement->execute([$responsibility]);
        $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    private function lockEligible(string $employeeUuid): array
    {
        $statement = $this->pdo->prepare('SELECT employee_uuid, display_name, status FROM employees WHERE employee_uuid = ? FOR UPDATE');
        $statement->execute([$employeeUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: throw new ApiException(404, 'EMPLOYEE_NOT_FOUND', 'Employee was not found.');
        $root = $this->pdo->prepare('SELECT COUNT(*) FROM system_root_identity WHERE employee_uuid = ?');
        $root->execute([$employeeUuid]);
        if ((int) $root->fetchColumn() !== 0) {
            throw new ApiException(403, 'ROOT_PROTECTED', 'The principal administrator is a protected system identity.');
        }
        if ($row['status'] !== 'active') {
            throw new ApiException(422, 'EMPLOYEE_INACTIVE', 'The employee is not active.');
        }
        return $row;
    }

    /** @param array<string, mixed> $row */
    private function state(array $row, string $now): string
    {
        return match (true) {
            $row['revoked_at'] !== null => 'revoked',
            $row['ends_at'] !== null && (string) $row['ends_at'] <= $now => 'ended',
            (string) $row['starts_at'] > $now => 'scheduled',
            default => 'active',
        };
    }

    private function instant(mixed $value, string $field): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw new ApiException(400, 'INVALID_REQUEST', "{$field} must be an ISO 8601 time with a time zone.");
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            throw new ApiException(400, 'INVALID_REQUEST', "{$field} is not a valid time.");
        }
    }

    private function time(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $value) !== 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Hours use the HH:MM format.');
        }
        return $value . ':00';
    }

    private function uuid(mixed $value): string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1
            ? $value
            : throw new ApiException(400, 'INVALID_REQUEST', 'An employee or assignment id is required.');
    }

    /** @template T @param callable(): T $operation @return T */
    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
