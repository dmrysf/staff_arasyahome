<?php

declare(strict_types=1);

namespace Arasya\Operations\Audit;

use Arasya\Operations\Support\Uuid;
use JsonException;
use PDO;

final readonly class PdoAuditLogger implements AuditLogger
{
    public function __construct(private PDO $pdo, private string $appSecret)
    {
    }

    public function record(string $eventType, ?string $employeeUuid, ?string $usernameNormalized, string $ipAddress, string $userAgent, string $requestId, string $createdAt, array $metadata = []): void
    {
        foreach (array_keys($metadata) as $key) {
            if (preg_match('/password|token|csrf|secret/i', $key) === 1) {
                unset($metadata[$key]);
            }
        }
        try {
            $metadataJson = $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            $metadataJson = null;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO auth_audit_events (event_id, employee_uuid, username_hash, event_type, created_at, ip_hash, user_agent_hash, request_id, metadata_json)
             VALUES (:event_id, :employee_uuid, :username_hash, :event_type, :created_at, :ip_hash, :user_agent_hash, :request_id, :metadata_json)',
        );
        $statement->bindValue(':event_id', Uuid::v4());
        $statement->bindValue(':employee_uuid', $employeeUuid);
        $statement->bindValue(':username_hash', $usernameNormalized === null ? null : $this->hash('username', $usernameNormalized), $usernameNormalized === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $statement->bindValue(':event_type', $eventType);
        $statement->bindValue(':created_at', $createdAt);
        $statement->bindValue(':ip_hash', $this->hash('ip', $ipAddress), PDO::PARAM_LOB);
        $statement->bindValue(':user_agent_hash', $this->hash('user-agent', $userAgent), PDO::PARAM_LOB);
        $statement->bindValue(':request_id', $requestId);
        $statement->bindValue(':metadata_json', $metadataJson);
        $statement->execute();
    }

    private function hash(string $scope, string $value): string
    {
        return hash_hmac('sha256', "audit|{$scope}|{$value}", $this->appSecret, true);
    }
}
