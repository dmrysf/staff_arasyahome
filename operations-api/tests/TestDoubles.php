<?php

declare(strict_types=1);

namespace Arasya\Operations\Tests;

use Arasya\Operations\Audit\AuditLogger;
use Arasya\Operations\Auth\LoginRateLimiter;
use Arasya\Operations\Auth\SessionRecord;
use Arasya\Operations\Auth\SessionRepository;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\EmployeeRepository;
use Arasya\Operations\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class MutableClock implements Clock
{
    public function __construct(public DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(string $modifier): void
    {
        $this->time = $this->time->modify($modifier);
    }
}

final class MemoryEmployeeRepository implements EmployeeRepository
{
    /** @var array<string, EmployeeIdentity> */
    public array $employees = [];

    public function findByNormalizedUsername(string $usernameNormalized): ?EmployeeIdentity
    {
        foreach ($this->employees as $employee) {
            if ($employee->usernameNormalized === $usernameNormalized) {
                return $employee;
            }
        }
        return null;
    }

    public function findByUuid(string $employeeUuid): ?EmployeeIdentity
    {
        return $this->employees[$employeeUuid] ?? null;
    }

    public function create(string $employeeUuid, ?string $employeeCode, string $username, string $usernameNormalized, string $passwordHash, string $displayName, string $departmentKey, string $roleKey, array $allowedStageIds, string $now): EmployeeIdentity
    {
        if ($this->findByNormalizedUsername($usernameNormalized) !== null) {
            throw new RuntimeException('Duplicate username.');
        }
        $employee = new EmployeeIdentity(
            $employeeUuid,
            $employeeCode,
            $username,
            $usernameNormalized,
            $passwordHash,
            $displayName,
            $departmentKey,
            'Pregătire Material',
            $roleKey,
            'active',
            ['history.view_mine', 'orders.scan', 'orders.view_mine', 'profile.view_self'],
            $allowedStageIds,
        );
        $this->employees[$employeeUuid] = $employee;
        return $employee;
    }

    public function updateStatus(string $employeeUuid, string $status, string $now): bool
    {
        $employee = $this->employees[$employeeUuid] ?? null;
        if ($employee === null) {
            return false;
        }
        $this->employees[$employeeUuid] = $this->copy($employee, status: $status);
        return true;
    }

    public function updatePasswordHash(string $employeeUuid, string $passwordHash, string $now): bool
    {
        $employee = $this->employees[$employeeUuid] ?? null;
        if ($employee === null) {
            return false;
        }
        $this->employees[$employeeUuid] = $this->copy($employee, passwordHash: $passwordHash);
        return true;
    }

    public function markLogin(string $employeeUuid, string $now): void
    {
    }

    public function add(EmployeeIdentity $employee): void
    {
        $this->employees[$employee->employeeUuid] = $employee;
    }

    private function copy(EmployeeIdentity $employee, ?string $status = null, ?string $passwordHash = null): EmployeeIdentity
    {
        return new EmployeeIdentity(
            $employee->employeeUuid,
            $employee->employeeCode,
            $employee->username,
            $employee->usernameNormalized,
            $passwordHash ?? $employee->passwordHash,
            $employee->displayName,
            $employee->departmentKey,
            $employee->departmentName,
            $employee->roleKey,
            $status ?? $employee->status,
            $employee->permissions,
            $employee->allowedStageIds,
        );
    }
}

final class MemorySessionRepository implements SessionRepository
{
    /** @var array<string, array{record: SessionRecord, tokenHash: string}> */
    public array $sessions = [];

    public function create(string $sessionId, string $employeeUuid, string $tokenHash, string $createdAt, string $expiresAt, string $ipHash, string $userAgentHash): void
    {
        $utc = new DateTimeZone('UTC');
        $this->sessions[$sessionId] = [
            'record' => new SessionRecord($sessionId, $employeeUuid, new DateTimeImmutable($createdAt, $utc), new DateTimeImmutable($expiresAt, $utc), new DateTimeImmutable($createdAt, $utc), null),
            'tokenHash' => $tokenHash,
        ];
    }

    public function findByTokenHash(string $tokenHash): ?SessionRecord
    {
        foreach ($this->sessions as $entry) {
            if (hash_equals($entry['tokenHash'], $tokenHash)) {
                return $entry['record'];
            }
        }
        return null;
    }

    public function touch(string $sessionId, string $lastSeenAt): void
    {
        $entry = $this->sessions[$sessionId] ?? null;
        if ($entry === null) {
            return;
        }
        $record = $entry['record'];
        $this->sessions[$sessionId]['record'] = new SessionRecord($record->sessionId, $record->employeeUuid, $record->createdAt, $record->expiresAt, new DateTimeImmutable($lastSeenAt, new DateTimeZone('UTC')), $record->revokedAt);
    }

    public function revokeByTokenHash(string $tokenHash, string $revokedAt): void
    {
        foreach ($this->sessions as $id => $entry) {
            if (hash_equals($entry['tokenHash'], $tokenHash)) {
                $this->revoke($id, $revokedAt);
            }
        }
    }

    public function revokeAllForEmployee(string $employeeUuid, string $revokedAt): int
    {
        $count = 0;
        foreach ($this->sessions as $id => $entry) {
            if ($entry['record']->employeeUuid === $employeeUuid && $entry['record']->revokedAt === null) {
                $this->revoke($id, $revokedAt);
                $count++;
            }
        }
        return $count;
    }

    public function rotate(string $sessionId, string $oldTokenHash, string $newSessionId, string $newTokenHash, string $now, string $expiresAt, string $ipHash, string $userAgentHash): bool
    {
        $entry = $this->sessions[$sessionId] ?? null;
        if ($entry === null || $entry['record']->revokedAt !== null || !hash_equals($entry['tokenHash'], $oldTokenHash)) {
            return false;
        }
        $employeeUuid = $entry['record']->employeeUuid;
        $this->revoke($sessionId, $now);
        $this->create($newSessionId, $employeeUuid, $newTokenHash, $now, $expiresAt, $ipHash, $userAgentHash);
        return true;
    }

    public function storesRawToken(string $rawToken): bool
    {
        foreach ($this->sessions as $entry) {
            if ($entry['tokenHash'] === $rawToken) {
                return true;
            }
        }
        return false;
    }

    private function revoke(string $id, string $revokedAt): void
    {
        $record = $this->sessions[$id]['record'];
        $this->sessions[$id]['record'] = new SessionRecord($record->sessionId, $record->employeeUuid, $record->createdAt, $record->expiresAt, $record->lastSeenAt, new DateTimeImmutable($revokedAt, new DateTimeZone('UTC')));
    }
}

final class MemoryRateLimiter implements LoginRateLimiter
{
    /** @var array<string, int> */
    public array $usernameFailures = [];
    /** @var array<string, int> */
    public array $ipFailures = [];

    public function __construct(public int $usernameLimit = 5, public int $ipLimit = 30)
    {
    }

    public function isAllowed(string $usernameNormalized, string $ipAddress, string $now): bool
    {
        $this->usernameFailures[$usernameNormalized] = ($this->usernameFailures[$usernameNormalized] ?? 0) + 1;
        $this->ipFailures[$ipAddress] = ($this->ipFailures[$ipAddress] ?? 0) + 1;
        return $this->usernameFailures[$usernameNormalized] <= $this->usernameLimit
            && $this->ipFailures[$ipAddress] <= $this->ipLimit;
    }

    public function recordFailure(string $usernameNormalized, string $ipAddress, string $now): void
    {
    }

    public function recordSuccess(string $usernameNormalized, string $ipAddress, string $now): void
    {
        $this->usernameFailures[$usernameNormalized] = 0;
        $this->ipFailures[$ipAddress] = max(0, ($this->ipFailures[$ipAddress] ?? 0) - 1);
    }
}

final class MemoryAuditLogger implements AuditLogger
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function record(string $eventType, ?string $employeeUuid, ?string $usernameNormalized, string $ipAddress, string $userAgent, string $requestId, string $createdAt, array $metadata = []): void
    {
        $this->events[] = compact('eventType', 'employeeUuid', 'usernameNormalized', 'ipAddress', 'userAgent', 'requestId', 'createdAt', 'metadata');
    }
}
