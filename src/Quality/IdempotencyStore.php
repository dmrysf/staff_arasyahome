<?php

declare(strict_types=1);

namespace Arasya\Operations\Quality;

use Arasya\Operations\Http\ApiException;
use JsonException;
use PDO;
use RuntimeException;

/**
 * Per-actor idempotency for exception and organisation commands. Must be called inside the command
 * transaction after the business row locks: the same key with the same intent replays the committed
 * result; the same key with a different intent is a 409.
 */
final readonly class IdempotencyStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public static function requireKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D', $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
    }

    /** @param array<string, mixed> $intent */
    public static function hash(string $operation, string $target, array $intent): string
    {
        return hash('sha256', $operation . '|' . $target . '|' . json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
    }

    /** @return array<string, mixed>|null */
    public function replay(string $actorUuid, string $key, string $operation, string $target, string $hash): ?array
    {
        $statement = $this->pdo->prepare('SELECT operation, target_id, request_hash, response_json FROM production_exception_idempotency WHERE employee_uuid = ? AND idempotency_key = ? FOR UPDATE');
        $statement->execute([$actorUuid, $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ($row['operation'] !== $operation || $row['target_id'] !== $target || !hash_equals((string) $row['request_hash'], $hash)) {
            throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
        }
        try {
            $decoded = json_decode((string) $row['response_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Stored idempotent result is unreadable.');
        }
        return is_array($decoded) ? $decoded : throw new RuntimeException('Stored idempotent result is unreadable.');
    }

    /** @param array<string, mixed> $response */
    public function store(string $actorUuid, string $key, string $operation, string $target, string $hash, array $response, string $now): void
    {
        $statement = $this->pdo->prepare('INSERT INTO production_exception_idempotency (employee_uuid, idempotency_key, operation, target_id, request_hash, response_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->bindValue(1, $actorUuid);
        $statement->bindValue(2, $key);
        $statement->bindValue(3, $operation);
        $statement->bindValue(4, $target);
        $statement->bindValue(5, $hash, PDO::PARAM_LOB);
        $statement->bindValue(6, json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $statement->bindValue(7, $now);
        $statement->execute();
    }
}
