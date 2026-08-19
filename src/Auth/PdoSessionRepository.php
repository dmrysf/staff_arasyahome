<?php

declare(strict_types=1);

namespace Arasya\Operations\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class PdoSessionRepository implements SessionRepository
{
    private readonly DateTimeZone $utc;

    public function __construct(private readonly PDO $pdo)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function create(string $sessionId, string $employeeUuid, string $tokenHash, string $createdAt, string $expiresAt, string $ipHash, string $userAgentHash): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO auth_sessions (session_id, employee_uuid, token_hash, created_at, expires_at, last_seen_at, ip_hash, user_agent_hash)
             VALUES (:session_id, :employee_uuid, :token_hash, :created_at, :expires_at, :last_seen_at, :ip_hash, :user_agent_hash)',
        );
        $statement->bindValue(':session_id', $sessionId);
        $statement->bindValue(':employee_uuid', $employeeUuid);
        $statement->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
        $statement->bindValue(':created_at', $createdAt);
        $statement->bindValue(':expires_at', $expiresAt);
        $statement->bindValue(':last_seen_at', $createdAt);
        $statement->bindValue(':ip_hash', $ipHash, PDO::PARAM_LOB);
        $statement->bindValue(':user_agent_hash', $userAgentHash, PDO::PARAM_LOB);
        $statement->execute();
    }

    public function findByTokenHash(string $tokenHash): ?SessionRecord
    {
        $statement = $this->pdo->prepare(
            'SELECT session_id, employee_uuid, created_at, expires_at, last_seen_at, revoked_at FROM auth_sessions WHERE token_hash = :token_hash LIMIT 1',
        );
        $statement->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
        $statement->execute();
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        return new SessionRecord(
            sessionId: (string) $row['session_id'],
            employeeUuid: (string) $row['employee_uuid'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $this->utc),
            expiresAt: new DateTimeImmutable((string) $row['expires_at'], $this->utc),
            lastSeenAt: new DateTimeImmutable((string) $row['last_seen_at'], $this->utc),
            revokedAt: $row['revoked_at'] === null ? null : new DateTimeImmutable((string) $row['revoked_at'], $this->utc),
        );
    }

    public function touch(string $sessionId, string $lastSeenAt): void
    {
        $statement = $this->pdo->prepare('UPDATE auth_sessions SET last_seen_at = :last_seen_at WHERE session_id = :session_id AND revoked_at IS NULL');
        $statement->execute(['last_seen_at' => $lastSeenAt, 'session_id' => $sessionId]);
    }

    public function revokeByTokenHash(string $tokenHash, string $revokedAt): bool
    {
        $statement = $this->pdo->prepare('UPDATE auth_sessions SET revoked_at = :revoked_at WHERE token_hash = :token_hash AND revoked_at IS NULL');
        $statement->bindValue(':revoked_at', $revokedAt);
        $statement->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
        $statement->execute();
        return $statement->rowCount() === 1;
    }

    public function revokeAllForEmployee(string $employeeUuid, string $revokedAt): int
    {
        $statement = $this->pdo->prepare('UPDATE auth_sessions SET revoked_at = :revoked_at WHERE employee_uuid = :employee_uuid AND revoked_at IS NULL');
        $statement->execute(['revoked_at' => $revokedAt, 'employee_uuid' => $employeeUuid]);
        return $statement->rowCount();
    }

    public function rotate(string $sessionId, string $oldTokenHash, string $newSessionId, string $newTokenHash, string $now, string $expiresAt, string $ipHash, string $userAgentHash): bool
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT employee_uuid, revoked_at, expires_at FROM auth_sessions WHERE session_id = :session_id AND token_hash = :token_hash FOR UPDATE');
            $lock->bindValue(':session_id', $sessionId);
            $lock->bindValue(':token_hash', $oldTokenHash, PDO::PARAM_LOB);
            $lock->execute();
            $row = $lock->fetch();
            if (!is_array($row) || $row['revoked_at'] !== null || (string) $row['expires_at'] <= $now) {
                $this->pdo->rollBack();
                return false;
            }

            $revoke = $this->pdo->prepare('UPDATE auth_sessions SET revoked_at = :revoked_at WHERE session_id = :session_id AND revoked_at IS NULL');
            $revoke->execute(['revoked_at' => $now, 'session_id' => $sessionId]);
            $this->create($newSessionId, (string) $row['employee_uuid'], $newTokenHash, $now, $expiresAt, $ipHash, $userAgentHash);
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
