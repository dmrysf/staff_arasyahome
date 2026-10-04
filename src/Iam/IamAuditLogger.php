<?php

declare(strict_types=1);

namespace Arasya\Operations\Iam;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Append-only IAM audit. Events snapshot readable labels so they stay understandable after renames.
 * Callers must never pass passwords, hashes, cookies or CSRF values in metadata.
 */
final readonly class IamAuditLogger
{
    private const FORBIDDEN_METADATA_KEYS = ['password', 'temporaryPassword', 'passwordHash', 'password_hash', 'token', 'csrf', 'cookie'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function record(?EmployeeIdentity $actor, string $action, string $targetType, string $targetId, string $targetLabel, array $metadata, string $requestId, string $now): void
    {
        foreach (array_keys($metadata) as $key) {
            if (in_array($key, self::FORBIDDEN_METADATA_KEYS, true)) {
                throw new \LogicException('Sensitive values must never enter the IAM audit.');
            }
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO iam_audit_events (event_id, actor_employee_uuid, actor_label, actor_type, action, target_type, target_id, target_label, metadata_json, request_id, created_at)
             VALUES (:event_id, :actor_uuid, :actor_label, :actor_type, :action, :target_type, :target_id, :target_label, :metadata, :request_id, :created_at)',
        );
        $statement->execute([
            'event_id' => Uuid::v4(),
            'actor_uuid' => $actor?->employeeUuid,
            'actor_label' => $actor === null ? 'Consolă server' : mb_substr("{$actor->displayName} ({$actor->username})", 0, 200),
            'actor_type' => $actor === null ? 'cli' : ($actor->isRoot ? 'root' : 'employee'),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => mb_substr($targetId, 0, 100),
            'target_label' => mb_substr($targetLabel, 0, 200),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'request_id' => mb_substr($requestId, 0, 100),
            'created_at' => $now,
        ]);
    }
}
