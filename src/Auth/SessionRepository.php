<?php

declare(strict_types=1);

namespace Arasya\Operations\Auth;

interface SessionRepository
{
    public function create(
        string $sessionId,
        string $employeeUuid,
        string $tokenHash,
        string $createdAt,
        string $expiresAt,
        string $ipHash,
        string $userAgentHash,
    ): void;

    public function findByTokenHash(string $tokenHash): ?SessionRecord;

    public function touch(string $sessionId, string $lastSeenAt): void;

    public function revokeByTokenHash(string $tokenHash, string $revokedAt): void;

    public function revokeAllForEmployee(string $employeeUuid, string $revokedAt): int;

    public function rotate(string $sessionId, string $oldTokenHash, string $newSessionId, string $newTokenHash, string $now, string $expiresAt, string $ipHash, string $userAgentHash): bool;
}

