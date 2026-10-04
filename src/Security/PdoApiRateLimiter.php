<?php

declare(strict_types=1);

namespace Arasya\Operations\Security;

use Arasya\Operations\Support\Clock;
use PDO;

final readonly class PdoApiRateLimiter implements ApiRateLimiter
{
    public function __construct(private PDO $pdo, private Clock $clock, private string $appSecret)
    {
    }

    public function hit(string $scope, string $subject, int $limit, int $windowSeconds): bool
    {
        $now = $this->clock->now();
        $nowSql = $now->format('Y-m-d H:i:s.u');
        $windowStart = $now->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s.u');
        $hash = hash_hmac('sha256', "api-rate|{$scope}|{$subject}", $this->appSecret, true);
        $statement = $this->pdo->prepare(
            'INSERT INTO api_rate_limit_buckets (bucket_scope, subject_hash, window_started_at, hits, updated_at)
             VALUES (?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
                hits = IF(window_started_at < ?, 1, hits + 1),
                window_started_at = IF(window_started_at < ?, VALUES(window_started_at), window_started_at),
                updated_at = VALUES(updated_at)',
        );
        $statement->bindValue(1, $scope);
        $statement->bindValue(2, $hash, PDO::PARAM_LOB);
        $statement->bindValue(3, $nowSql);
        $statement->bindValue(4, $nowSql);
        $statement->bindValue(5, $windowStart);
        $statement->bindValue(6, $windowStart);
        $statement->execute();
        $read = $this->pdo->prepare('SELECT hits FROM api_rate_limit_buckets WHERE bucket_scope = :scope AND subject_hash = :hash');
        $read->bindValue(':scope', $scope);
        $read->bindValue(':hash', $hash, PDO::PARAM_LOB);
        $read->execute();
        return (int) $read->fetchColumn() <= $limit;
    }
}
