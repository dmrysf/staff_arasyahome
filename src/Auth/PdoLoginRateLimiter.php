<?php

declare(strict_types=1);

namespace Arasya\Operations\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PdoLoginRateLimiter implements LoginRateLimiter
{
    public function __construct(
        private PDO $pdo,
        private int $usernameLimit,
        private int $ipLimit,
        private int $windowSeconds,
        private string $appSecret,
    ) {
    }

    public function isAllowed(string $usernameNormalized, string $ipAddress, string $now): bool
    {
        $timestamp = new DateTimeImmutable($now, new DateTimeZone('UTC'));
        $bucketEpoch = intdiv($timestamp->getTimestamp(), $this->windowSeconds) * $this->windowSeconds;
        $bucketStart = (new DateTimeImmutable('@' . $bucketEpoch))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $usernameCount = $this->reserve('username', $this->hash('username', $usernameNormalized), $bucketStart, $now);
        $ipCount = $this->reserve('ip', $this->hash('ip', $ipAddress), $bucketStart, $now);
        return $usernameCount <= $this->usernameLimit && $ipCount <= $this->ipLimit;
    }

    public function recordFailure(string $usernameNormalized, string $ipAddress, string $now): void
    {
        $statement = $this->pdo->prepare('INSERT INTO auth_login_attempts (username_hash, ip_hash, succeeded, attempted_at) VALUES (:username_hash, :ip_hash, 0, :attempted_at)');
        $statement->bindValue(':username_hash', $this->hash('username', $usernameNormalized), PDO::PARAM_LOB);
        $statement->bindValue(':ip_hash', $this->hash('ip', $ipAddress), PDO::PARAM_LOB);
        $statement->bindValue(':attempted_at', $now);
        $statement->execute();
    }

    public function recordSuccess(string $usernameNormalized, string $ipAddress, string $now): void
    {
        $delete = $this->pdo->prepare('DELETE FROM auth_login_attempts WHERE username_hash = :username_hash AND succeeded = 0');
        $delete->bindValue(':username_hash', $this->hash('username', $usernameNormalized), PDO::PARAM_LOB);
        $delete->execute();
        $statement = $this->pdo->prepare('INSERT INTO auth_login_attempts (username_hash, ip_hash, succeeded, attempted_at) VALUES (:username_hash, :ip_hash, 1, :attempted_at)');
        $statement->bindValue(':username_hash', $this->hash('username', $usernameNormalized), PDO::PARAM_LOB);
        $statement->bindValue(':ip_hash', $this->hash('ip', $ipAddress), PDO::PARAM_LOB);
        $statement->bindValue(':attempted_at', $now);
        $statement->execute();
        $reset = $this->pdo->prepare("UPDATE auth_rate_limit_buckets SET failures = 0, updated_at = :updated_at WHERE dimension_type = 'username' AND dimension_hash = :dimension_hash");
        $reset->bindValue(':updated_at', $now);
        $reset->bindValue(':dimension_hash', $this->hash('username', $usernameNormalized), PDO::PARAM_LOB);
        $reset->execute();
        $releaseIp = $this->pdo->prepare("UPDATE auth_rate_limit_buckets SET failures = IF(failures > 0, failures - 1, 0), updated_at = :updated_at WHERE dimension_type = 'ip' AND dimension_hash = :dimension_hash");
        $releaseIp->bindValue(':updated_at', $now);
        $releaseIp->bindValue(':dimension_hash', $this->hash('ip', $ipAddress), PDO::PARAM_LOB);
        $releaseIp->execute();
    }

    private function reserve(string $dimension, string $hash, string $bucketStart, string $now): int
    {
        if (!in_array($dimension, ['username', 'ip'], true)) {
            return PHP_INT_MAX;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO auth_rate_limit_buckets (dimension_type, dimension_hash, bucket_started_at, failures, updated_at)
             VALUES (:dimension_type, :dimension_hash, :bucket_started_at, 1, :updated_at)
             ON DUPLICATE KEY UPDATE
               failures = IF(bucket_started_at = VALUES(bucket_started_at), failures + 1, 1),
               bucket_started_at = VALUES(bucket_started_at),
               updated_at = VALUES(updated_at)',
        );
        $statement->bindValue(':dimension_type', $dimension);
        $statement->bindValue(':dimension_hash', $hash, PDO::PARAM_LOB);
        $statement->bindValue(':bucket_started_at', $bucketStart);
        $statement->bindValue(':updated_at', $now);
        $statement->execute();
        $read = $this->pdo->prepare('SELECT failures FROM auth_rate_limit_buckets WHERE dimension_type = :dimension_type AND dimension_hash = :dimension_hash');
        $read->bindValue(':dimension_type', $dimension);
        $read->bindValue(':dimension_hash', $hash, PDO::PARAM_LOB);
        $read->execute();
        return (int) $read->fetchColumn();
    }

    private function hash(string $scope, string $value): string
    {
        return hash_hmac('sha256', "rate-limit|{$scope}|{$value}", $this->appSecret, true);
    }
}
