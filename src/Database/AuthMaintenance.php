<?php

declare(strict_types=1);

namespace Arasya\Operations\Database;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class AuthMaintenance
{
    public const DEFAULT_BATCH_SIZE = 500;

    public function __construct(
        private PDO $pdo,
        private int $sessionRetentionDays,
        private int $loginAttemptRetentionDays,
        private int $rateLimitRetentionDays,
        private ?int $auditRetentionDays,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
        private int $idempotencyRetentionDays = 30,
    ) {
    }

    /** @return array{sessions: int, login_attempts: int, rate_limit_buckets: int, audit_events: int|null, idempotency_keys: int, api_rate_limit_buckets: int} */
    public function run(bool $dryRun, ?DateTimeImmutable $now = null): array
    {
        $lock = new DatabaseAdvisoryLock($this->pdo, 'arasya_operations_maintenance');
        $lock->acquire(0);
        try {
            $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $sessionCutoff = $this->cutoff($now, $this->sessionRetentionDays);
            $attemptCutoff = $this->cutoff($now, $this->loginAttemptRetentionDays);
            $rateCutoff = $this->cutoff($now, $this->rateLimitRetentionDays);
            return [
                'sessions' => $this->pruneSessions($sessionCutoff, $dryRun),
                'login_attempts' => $this->pruneSimple('auth_login_attempts', 'attempt_id', 'attempted_at', $attemptCutoff, $dryRun),
                'rate_limit_buckets' => $this->pruneRateLimits($rateCutoff, $dryRun),
                'audit_events' => $this->auditRetentionDays === null
                    ? null
                    : $this->pruneSimple('auth_audit_events', 'event_id', 'created_at', $this->cutoff($now, $this->auditRetentionDays), $dryRun),
                'idempotency_keys' => $this->pruneIdempotency($this->cutoff($now, $this->idempotencyRetentionDays), $dryRun),
                'api_rate_limit_buckets' => $this->pruneApiRateLimits($rateCutoff, $dryRun),
            ];
        } finally {
            $lock->release();
        }
    }

    private function cutoff(DateTimeImmutable $now, int $days): string
    {
        return $now->modify("-{$days} days")->format('Y-m-d H:i:s.u');
    }

    private function pruneSessions(string $cutoff, bool $dryRun): int
    {
        // Native MySQL prepares reject a repeated named placeholder, so each use is distinct.
        $where = '(expires_at < :expires_cutoff OR (revoked_at IS NOT NULL AND revoked_at < :revoked_cutoff))';
        return $this->pruneByQuery('auth_sessions', 'session_id', $where, ['expires_cutoff' => $cutoff, 'revoked_cutoff' => $cutoff], $dryRun);
    }

    private function pruneSimple(string $table, string $key, string $timestamp, string $cutoff, bool $dryRun): int
    {
        return $this->pruneByQuery($table, $key, "{$timestamp} < :cutoff", ['cutoff' => $cutoff], $dryRun);
    }

    /** @param array<string, string> $parameters */
    private function pruneByQuery(string $table, string $key, string $where, array $parameters, bool $dryRun): int
    {
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $count->execute($parameters);
        $candidates = (int) $count->fetchColumn();
        if ($dryRun) {
            return $candidates;
        }
        $deleted = 0;
        do {
            $select = $this->pdo->prepare("SELECT {$key} FROM {$table} WHERE {$where} ORDER BY {$key} ASC LIMIT {$this->batchSize}");
            $select->execute($parameters);
            $ids = array_values(array_filter($select->fetchAll(PDO::FETCH_COLUMN), static fn (mixed $id): bool => is_string($id) || is_int($id)));
            foreach ($ids as $id) {
                $delete = $this->pdo->prepare("DELETE FROM {$table} WHERE {$key} = :id");
                $delete->execute(['id' => $id]);
                $deleted += $delete->rowCount();
            }
        } while (count($ids) === $this->batchSize);
        return $deleted;
    }

    private function pruneRateLimits(string $cutoff, bool $dryRun): int
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM auth_rate_limit_buckets WHERE updated_at < :cutoff');
        $count->execute(['cutoff' => $cutoff]);
        $candidates = (int) $count->fetchColumn();
        if ($dryRun) {
            return $candidates;
        }
        $deleted = 0;
        do {
            $select = $this->pdo->prepare("SELECT dimension_type, dimension_hash FROM auth_rate_limit_buckets WHERE updated_at < :cutoff ORDER BY updated_at ASC LIMIT {$this->batchSize}");
            $select->execute(['cutoff' => $cutoff]);
            $rows = $select->fetchAll();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $delete = $this->pdo->prepare('DELETE FROM auth_rate_limit_buckets WHERE dimension_type = :dimension_type AND dimension_hash = :dimension_hash');
                $delete->bindValue(':dimension_type', (string) $row['dimension_type']);
                $delete->bindValue(':dimension_hash', $row['dimension_hash'], PDO::PARAM_LOB);
                $delete->execute();
                $deleted += $delete->rowCount();
            }
        } while (count($rows) === $this->batchSize);
        return $deleted;
    }

    private function pruneIdempotency(string $cutoff, bool $dryRun): int
    {
        return $this->pruneComposite('order_operation_idempotency', ['employee_uuid', 'idempotency_key'], 'created_at', $cutoff, $dryRun, false);
    }

    private function pruneApiRateLimits(string $cutoff, bool $dryRun): int
    {
        return $this->pruneComposite('api_rate_limit_buckets', ['bucket_scope', 'subject_hash'], 'updated_at', $cutoff, $dryRun, true);
    }

    /** @param list<string> $keys */
    private function pruneComposite(string $table, array $keys, string $timestamp, string $cutoff, bool $dryRun, bool $binarySecondKey): int
    {
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$timestamp} < :cutoff");
        $count->execute(['cutoff' => $cutoff]);
        $candidates = (int) $count->fetchColumn();
        if ($dryRun) {
            return $candidates;
        }
        $deleted = 0;
        $keyList = implode(', ', $keys);
        do {
            $select = $this->pdo->prepare("SELECT {$keyList} FROM {$table} WHERE {$timestamp} < :cutoff ORDER BY {$timestamp} ASC LIMIT {$this->batchSize}");
            $select->execute(['cutoff' => $cutoff]);
            $rows = $select->fetchAll();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $delete = $this->pdo->prepare("DELETE FROM {$table} WHERE {$keys[0]} = :first AND {$keys[1]} = :second");
                $delete->bindValue(':first', (string) $row[$keys[0]]);
                $delete->bindValue(':second', $row[$keys[1]], $binarySecondKey ? PDO::PARAM_LOB : PDO::PARAM_STR);
                $delete->execute();
                $deleted += $delete->rowCount();
            }
        } while (count($rows) === $this->batchSize);
        return $deleted;
    }
}
