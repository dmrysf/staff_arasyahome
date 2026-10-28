<?php

declare(strict_types=1);

namespace Arasya\Operations\Employee;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Support\Clock;
use PDO;
use Throwable;

/**
 * The signed-in employee chooses the Staff interface language of their own account (API 2.49.0, owner decision
 * 2026-10-28). Same column and the same IAM audit action as the management path (`employee.staff_locale_changed`),
 * with the employee as actor and target. Presentation only: the authorization version, the sessions, the roles, the
 * stages, the password and the account status stay exactly as they were. Choosing the language already in effect
 * (NULL reads as Romanian) writes nothing.
 */
final readonly class OwnStaffLocale
{
    public function __construct(private PDO $pdo, private IamAuditLogger $audit, private Clock $clock)
    {
    }

    /** @return bool whether the stored language changed */
    public function change(EmployeeIdentity $self, mixed $locale, string $requestId): bool
    {
        if (!StaffLocale::isSupported($locale)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'locale must be one of: ' . implode(', ', StaffLocale::SUPPORTED) . '.');
        }
        $this->pdo->beginTransaction();
        try {
            $current = $this->pdo->prepare('SELECT display_name, username, staff_locale FROM employees WHERE employee_uuid = :uuid FOR UPDATE');
            $current->execute(['uuid' => $self->employeeUuid]);
            $row = $current->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new ApiException(404, 'EMPLOYEE_NOT_FOUND', 'Employee not found.');
            }
            $before = is_string($row['staff_locale']) ? $row['staff_locale'] : null;
            if (StaffLocale::normalize($before) === $locale) {
                $this->pdo->commit();
                return false;
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $this->pdo->prepare('UPDATE employees SET staff_locale = :locale, updated_at = :now WHERE employee_uuid = :uuid')
                ->execute(['locale' => $locale, 'now' => $now, 'uuid' => $self->employeeUuid]);
            $this->audit->record($self, 'employee.staff_locale_changed', 'employee', $self->employeeUuid, "{$row['display_name']} ({$row['username']})", ['staffLocale' => ['before' => $before, 'after' => $locale], 'self' => true], $requestId, $now);
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
