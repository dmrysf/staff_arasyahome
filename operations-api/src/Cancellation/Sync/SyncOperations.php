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
    public const LEASE_SECONDS = 120;

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
     * @param array{kind: string, outcome: string, http?: int|null, error?: string|null, providerStatus?: string|null, actor?: string|null, system?: string|null, correlation?: string|null} $attempt
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
            $attempt['error'] ?? null, $attempt['providerStatus'] ?? null, $attempt['actor'] ?? null, $attempt['system'] ?? null, $attempt['correlation'] ?? null, $now);
        return $this->byId((string) $operation['operation_uuid']) ?? throw new \RuntimeException('Operation disappeared.');
    }

    /** An attempt that changed nothing (a repeated report, a late worker answer, a pickup). @param array<string, mixed> $operation */
    public function note(array $operation, string $kind, string $outcome, ?string $error = null, ?string $actor = null, ?string $system = null, ?string $correlation = null, ?string $providerStatus = null): void
    {
        $this->attempt((string) $operation['operation_uuid'], (string) $operation['order_uuid'], $kind, (string) $operation['state'], (string) $operation['state'], $outcome, null, $error, $providerStatus, $actor, $system, $correlation, $this->now());
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

    /** Whether the operation was ever in the uncertain state: an earlier notice may have reached the channel. */
    public function wasUncertain(string $operationUuid): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM order_cancellation_sync_attempts WHERE operation_uuid = ? AND state_after = 'uncertain' LIMIT 1");
        $statement->execute([$operationUuid]);
        return $statement->fetchColumn() !== false;
    }

    private function attempt(string $operationUuid, string $orderUuid, string $kind, string $before, string $after, string $outcome, ?int $http, ?string $error, ?string $providerStatus,
        ?string $actor, ?string $system, ?string $correlation, string $now): void
    {
        $this->pdo->prepare('INSERT INTO order_cancellation_sync_attempts (operation_uuid, order_uuid, kind, state_before, state_after, outcome, http_status, error_code, provider_status,
                actor_employee_uuid, actor_system, correlation_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$operationUuid, $orderUuid, $kind, $before, $after, mb_substr($outcome, 0, 30), $http, $error === null ? null : mb_substr($error, 0, 60),
                $providerStatus === null ? null : mb_substr($providerStatus, 0, 60), $actor, $system, $correlation === null ? null : mb_substr($correlation, 0, 100), $now]);
    }
}
