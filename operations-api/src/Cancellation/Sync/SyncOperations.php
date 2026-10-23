<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation\Sync;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Persistence of outbound cancellation operations (migration 034). Every write runs inside the caller's transaction,
 * after the caller locked the canonical order row (the same lock order as every production command: order row, then
 * the operation row). A state change is accepted only along SyncState's transition table and only on the expected row
 * version, and it always appends one attempt row, so the history explains every state.
 */
final readonly class SyncOperations
{
    public const MAX_SUBMITS = 6;
    public const CONFIRMATION_TIMEOUT_SECONDS = 6 * 3600;
    public const PICKUP_TIMEOUT_SECONDS = 24 * 3600;
    /** Pause between two status reads of an uncertain operation (an answer that was lost, a 5xx, a crashed worker). */
    public const CONSISTENCY_WINDOW_SECONDS = 300;
    /**
     * How long an uncertain operation is observed (status reads only, never a send) before a person decides. Trendyol
     * documents no idempotency, no request-status lookup and no consistency window for the unsupplied notice, so an
     * open package after a lost answer does not prove the notice was not received.
     */
    public const UNCERTAIN_OBSERVATION_SECONDS = 1800;
    /** A person's retry of a possibly delivered notice relies on a server status read at most this old. */
    public const RETRY_EVIDENCE_MAX_AGE_SECONDS = 3600;
    /** The person's stated check in the channel must be at most this old when the retry is recorded. */
    public const PROVIDER_CHECK_MAX_AGE_SECONDS = 1800;
    public const LEASE_SECONDS = 120;
    /**
     * Attempts after which a notice may have reached the provider: an accepted or lost answer (also when it arrived after
     * the lease was recovered) and a recovered lease (the dead worker may have sent).
     */
    private const DELIVERY = "((kind = 'submit' AND outcome IN ('accepted', 'uncertain', 'late_accepted', 'late_uncertain')) OR kind = 'lease_recovery')";
    /** Outcomes of a completed status read (a lease row is not a read). */
    private const READ_OUTCOMES = ['still_open', 'attempts_exhausted', 'cancelled', 'not_cancellable', 'rejected', 'transient', 'auth_failed', 'not_configured'];

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /** @return array<string, mixed>|null the operation of one stop generation, locked when $lock */
    public function forGeneration(string $orderUuid, int $generation, bool $lock = true): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM order_cancellation_sync_operations WHERE order_uuid = ? AND hold_generation = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$orderUuid, $generation]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null the order's open operation (any generation), locked when $lock */
    public function openFor(string $orderUuid, bool $lock = true): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM order_cancellation_sync_operations WHERE open_order_uuid = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function byId(string $operationUuid, bool $lock = true): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM order_cancellation_sync_operations WHERE operation_uuid = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$operationUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * One logical cancellation per order and stop generation (unique key): a repeated request can never create a second.
     * @return array<string, mixed>
     */
    public function create(string $orderUuid, int $generation, string $sourceKey, string $externalRef, string $mechanism, string $state, ?string $reasonCode, ?string $note,
        string $actorUuid, ?string $errorCode, ?string $correlation): array
    {
        $now = $this->now();
        $uuid = Uuid::v4();
        $this->pdo->prepare('INSERT INTO order_cancellation_sync_operations (operation_uuid, order_uuid, open_order_uuid, hold_generation, source_key, external_order_ref, mechanism, state,
                reason_code, note, requested_by_employee_uuid, attempt_count, next_attempt_at, last_error_code, last_error_at, version, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 1, ?, ?)')
            ->execute([$uuid, $orderUuid, SyncState::isTerminal($state) ? null : $orderUuid, $generation, $sourceKey, $externalRef, $mechanism, $state, $reasonCode, $note, $actorUuid,
                $state === SyncState::QUEUED ? $now : null, $errorCode, $errorCode === null ? null : $now, $now, $now]);
        $this->attempt($uuid, $orderUuid, 'create', 'queued', $state, 'created', null, $errorCode, null, $actorUuid, null, $correlation, $now);
        return $this->byId($uuid) ?? throw new \RuntimeException('Operation was not created.');
    }

    /**
     * @param array<string, mixed> $operation the locked row
     * @param array<string, mixed> $changes extra columns
     * @param array{kind: string, outcome: string, http?: int|null, error?: string|null, providerStatus?: string|null, actor?: string|null, system?: string|null, correlation?: string|null, evidence?: array<string, mixed>|null} $attempt
     * @return array<string, mixed> the updated row
     */
    public function transition(array $operation, string $to, array $changes, array $attempt): array
    {
        $from = (string) $operation['state'];
        if (!SyncState::allowed($from, $to)) {
            throw new ApiException(409, 'CANCELLATION_SYNC_STATE', 'Operația de sincronizare nu permite această schimbare.', ['state' => $from]);
        }
        $now = $this->now();
        $changes['state'] = $to;
        $changes['open_order_uuid'] = SyncState::isTerminal($to) ? null : $operation['order_uuid'];
        if ($to !== SyncState::PROCESSING) {
            $changes += ['lease_owner' => null, 'lease_expires_at' => null];
        }
        if ($to === SyncState::CONFIRMED) {
            $changes += ['confirmed_at' => $now];
        }
        if (isset($attempt['error'])) {
            $changes += ['last_error_code' => $attempt['error'], 'last_error_at' => $now];
        }
        $sets = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($changes)));
        $statement = $this->pdo->prepare("UPDATE order_cancellation_sync_operations SET {$sets}, version = version + 1, updated_at = ? WHERE operation_uuid = ? AND version = ?");
        $statement->execute([...array_values($changes), $now, $operation['operation_uuid'], $operation['version']]);
        if ($statement->rowCount() !== 1) {
            throw new ApiException(409, 'CANCELLATION_SYNC_CHANGED', 'Operația de sincronizare s-a schimbat. Reîncarcă.');
        }
        $this->attempt((string) $operation['operation_uuid'], (string) $operation['order_uuid'], $attempt['kind'], $from, $to, $attempt['outcome'], $attempt['http'] ?? null,
            $attempt['error'] ?? null, $attempt['providerStatus'] ?? null, $attempt['actor'] ?? null, $attempt['system'] ?? null, $attempt['correlation'] ?? null, $now, $attempt['evidence'] ?? null);
        return $this->byId((string) $operation['operation_uuid']) ?? throw new \RuntimeException('Operation disappeared.');
    }

    /**
     * An attempt that changed nothing (a repeated report, a late worker answer, a pickup, a rescheduled retry).
     * @param array<string, mixed> $operation @param array<string, mixed>|null $evidence
     */
    public function note(array $operation, string $kind, string $outcome, ?string $error = null, ?string $actor = null, ?string $system = null, ?string $correlation = null, ?string $providerStatus = null, ?array $evidence = null): void
    {
        $this->attempt((string) $operation['operation_uuid'], (string) $operation['order_uuid'], $kind, (string) $operation['state'], (string) $operation['state'], $outcome, null, $error, $providerStatus, $actor, $system, $correlation, $this->now(), $evidence);
    }

    /**
     * Inbound evidence: the provider itself reported the order cancelled (signed source event or marketplace sync). The
     * operation of the current stop generation is confirmed from any state but withdrawn / closed / confirmed. The caller
     * holds the order lock (CancellationControl, inside the projection transaction).
     */
    public function confirmFromInbound(string $orderUuid, int $generation, ?string $evidenceRef): void
    {
        $operation = $this->forGeneration($orderUuid, $generation);
        if ($operation === null || !SyncState::allowed((string) $operation['state'], SyncState::CONFIRMED)) {
            return;
        }
        $this->transition($operation, SyncState::CONFIRMED, ['confirmed_by' => 'inbound_event'], ['kind' => 'inbound', 'outcome' => 'cancelled', 'system' => 'inbound', 'correlation' => $evidenceRef === null ? null : mb_substr($evidenceRef, 0, 100)]);
    }

    /** Bounded exponential backoff with jitter: 1, 2, 4, 8, 16, 32 minutes (+-20 %), at most one hour. */
    public function backoff(int $attempts): string
    {
        $seconds = min(3600, 60 * (2 ** max(0, $attempts - 1)));
        $seconds = (int) round($seconds * random_int(80, 120) / 100);
        return $this->clock->now()->modify("+{$seconds} seconds")->format('Y-m-d H:i:s.u');
    }

    public function at(int $seconds): string
    {
        return $this->clock->now()->modify("+{$seconds} seconds")->format('Y-m-d H:i:s.u');
    }

    public function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    /**
     * The last attempt after which a notice may have reached the provider, or null when none can have: then a retry sends
     * a first notice. Source-pull operations never have one (their connector cancels idempotently in the shop).
     * @return array{seq: int, occurredAt: string}|null
     */
    public function lastDelivery(string $operationUuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT attempt_seq, occurred_at FROM order_cancellation_sync_attempts WHERE operation_uuid = ? AND ' . self::DELIVERY . ' ORDER BY attempt_seq DESC LIMIT 1');
        $statement->execute([$operationUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['seq' => (int) $row['attempt_seq'], 'occurredAt' => (string) $row['occurred_at']] : null;
    }

    /** Whether an earlier notice of this operation may already have reached the provider. */
    public function possiblyDelivered(string $operationUuid): bool
    {
        return $this->lastDelivery($operationUuid) !== null;
    }

    /**
     * Whether the worker may send a notice now: nothing can have been delivered yet, or a person's retry after a provider
     * check (with the server's evidence, see providerEvidence) was recorded after the last possible delivery. This is the
     * worker's own guard; it does not trust the state alone.
     */
    public function sendAuthorized(string $operationUuid): bool
    {
        $delivery = $this->lastDelivery($operationUuid);
        if ($delivery === null) {
            return true;
        }
        $statement = $this->pdo->prepare("SELECT 1 FROM order_cancellation_sync_attempts WHERE operation_uuid = ? AND kind = 'retry' AND outcome = 'retry_after_provider_check' AND attempt_seq > ? LIMIT 1");
        $statement->execute([$operationUuid, $delivery['seq']]);
        return $statement->fetchColumn() !== false;
    }

    /**
     * What the server itself knows about the provider since the last possible delivery, from its own status reads (the
     * person's statement is never part of it):
     *   not_required  nothing can have been delivered
     *   missing       no completed status read since then
     *   unknown       the last read failed (unreachable, credentials, not configured)
     *   not_open      the last read found the package cancelled, not cancellable or unknown: a retry cannot help
     *   observing     the last read was open but inside the observation window after the possible delivery
     *   stale         the last read was open but is older than RETRY_EVIDENCE_MAX_AGE_SECONDS (ask for a recheck)
     *   ok            the last read was open, after the observation window, and recent
     * @return array{status: string, readAt: ?string, providerStatus: ?string, attemptSeq: ?int, deliverySeq: ?int}
     */
    public function providerEvidence(string $operationUuid): array
    {
        $delivery = $this->lastDelivery($operationUuid);
        if ($delivery === null) {
            return ['status' => 'not_required', 'readAt' => null, 'providerStatus' => null, 'attemptSeq' => null, 'deliverySeq' => null];
        }
        $in = implode(', ', array_fill(0, count(self::READ_OUTCOMES), '?'));
        $statement = $this->pdo->prepare("SELECT attempt_seq, outcome, provider_status, occurred_at FROM order_cancellation_sync_attempts
            WHERE operation_uuid = ? AND kind = 'status_read' AND attempt_seq > ? AND outcome IN ({$in}) ORDER BY attempt_seq DESC LIMIT 1");
        $statement->execute([$operationUuid, $delivery['seq'], ...self::READ_OUTCOMES]);
        $read = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($read)) {
            return ['status' => 'missing', 'readAt' => null, 'providerStatus' => null, 'attemptSeq' => null, 'deliverySeq' => $delivery['seq']];
        }
        $readAt = strtotime((string) $read['occurred_at'] . ' UTC');
        $status = match (true) {
            in_array($read['outcome'], ['transient', 'auth_failed', 'not_configured'], true) => 'unknown',
            !in_array($read['outcome'], ['still_open', 'attempts_exhausted'], true) => 'not_open',
            $readAt < strtotime($delivery['occurredAt'] . ' UTC') + self::UNCERTAIN_OBSERVATION_SECONDS => 'observing',
            $readAt < $this->clock->now()->getTimestamp() - self::RETRY_EVIDENCE_MAX_AGE_SECONDS => 'stale',
            default => 'ok',
        };
        return ['status' => $status, 'readAt' => (string) $read['occurred_at'], 'providerStatus' => $read['provider_status'] === null ? null : (string) $read['provider_status'],
            'attemptSeq' => (int) $read['attempt_seq'], 'deliverySeq' => $delivery['seq']];
    }

    /** @param array<string, mixed>|null $evidence */
    private function attempt(string $operationUuid, string $orderUuid, string $kind, string $before, string $after, string $outcome, ?int $http, ?string $error, ?string $providerStatus,
        ?string $actor, ?string $system, ?string $correlation, string $now, ?array $evidence = null): void
    {
        $this->pdo->prepare('INSERT INTO order_cancellation_sync_attempts (operation_uuid, order_uuid, kind, state_before, state_after, outcome, http_status, error_code, provider_status,
                actor_employee_uuid, actor_system, correlation_id, evidence_json, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$operationUuid, $orderUuid, $kind, $before, $after, mb_substr($outcome, 0, 30), $http, $error === null ? null : mb_substr($error, 0, 60),
                $providerStatus === null ? null : mb_substr($providerStatus, 0, 60), $actor, $system, $correlation === null ? null : mb_substr($correlation, 0, 100),
                $evidence === null ? null : json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $now]);
    }
}
