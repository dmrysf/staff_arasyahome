<?php

declare(strict_types=1);

namespace Arasya\Operations\Employee;

use Arasya\Operations\Audit\AuditLogger;
use Arasya\Operations\Auth\SessionRepository;
use Arasya\Operations\Security\PasswordHasher;
use Arasya\Operations\Security\UsernameNormalizer;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use RuntimeException;

final readonly class EmployeeAdminService
{
    public function __construct(
        private EmployeeRepository $employees,
        private SessionRepository $sessions,
        private PasswordHasher $passwords,
        private UsernameNormalizer $usernames,
        private AuditLogger $audit,
        private Clock $clock,
    ) {
    }

    /** @param list<string> $allowedStageIds */
    public function create(string $displayName, string $username, ?string $employeeCode, string $departmentKey, string $roleKey, string $password, array $allowedStageIds, string $requestId): EmployeeIdentity
    {
        $displayName = trim($displayName);
        $username = trim($username);
        $normalized = $this->usernames->normalize($username);
        if ($displayName === '' || mb_strlen($displayName) > 160 || $normalized === '' || mb_strlen($normalized) > 120) {
            throw new RuntimeException('Display name and username are required and must fit allowed lengths.');
        }
        if ($employeeCode !== null && preg_match('/^[A-Za-z0-9._-]{1,40}$/', trim($employeeCode)) !== 1) {
            throw new RuntimeException('Employee code must use 1–40 safe operational characters.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', trim($departmentKey)) !== 1 || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', trim($roleKey)) !== 1) {
            throw new RuntimeException('Department and role keys are invalid.');
        }
        if ($this->employees->findByNormalizedUsername($normalized) !== null) {
            throw new RuntimeException('The normalized username is already in use.');
        }
        $this->passwords->assertPolicy($password);
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $stages = array_values(array_unique(array_filter(array_map('trim', $allowedStageIds))));
        foreach ($stages as $stageId) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/', $stageId) !== 1) {
                throw new RuntimeException('One or more stage IDs are invalid.');
            }
        }
        $employee = $this->employees->create(
            Uuid::v4(),
            $employeeCode === null ? null : trim($employeeCode),
            $username,
            $normalized,
            $this->passwords->hash($password),
            $displayName,
            trim($departmentKey),
            trim($roleKey),
            $stages,
            $now,
        );
        $this->audit->record('AUTH_EMPLOYEE_CREATED', $employee->employeeUuid, $normalized, 'cli', 'cli', $requestId, $now);
        return $employee;
    }

    public function setStatus(string $employeeUuid, string $status, string $requestId): void
    {
        if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
            throw new RuntimeException('Unsupported employee status.');
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        if (!$this->employees->updateStatus($employeeUuid, $status, $now)) {
            throw new RuntimeException('Employee was not found.');
        }
        if ($status !== 'active') {
            $this->sessions->revokeAllForEmployee($employeeUuid, $now);
        }
        $this->audit->record($status === 'active' ? 'AUTH_ACCOUNT_ENABLED' : 'AUTH_ACCOUNT_INACTIVE', $employeeUuid, null, 'cli', 'cli', $requestId, $now, ['status' => $status]);
    }

    public function changePassword(string $employeeUuid, string $password, string $requestId): void
    {
        $this->passwords->assertPolicy($password);
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        if (!$this->employees->updatePasswordHash($employeeUuid, $this->passwords->hash($password), $now)) {
            throw new RuntimeException('Employee was not found.');
        }
        $revoked = $this->sessions->revokeAllForEmployee($employeeUuid, $now);
        $this->audit->record('AUTH_PASSWORD_CHANGED', $employeeUuid, null, 'cli', 'cli', $requestId, $now, ['revoked_sessions' => $revoked]);
    }

    public function revokeSessions(string $employeeUuid, string $requestId): int
    {
        if ($this->employees->findByUuid($employeeUuid) === null) {
            throw new RuntimeException('Employee was not found.');
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $revoked = $this->sessions->revokeAllForEmployee($employeeUuid, $now);
        $this->audit->record('AUTH_SESSION_REVOKED', $employeeUuid, null, 'cli', 'cli', $requestId, $now, ['revoked_sessions' => $revoked]);
        return $revoked;
    }
}
