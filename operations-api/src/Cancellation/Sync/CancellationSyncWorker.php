<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation\Sync;

use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use PDO;
use Throwable;

/**
 * The outbound cancellation worker (bin/cancellation-sync.php run). Delivery is at-least-once and every step is
 * idempotent; nothing is claimed to be exactly-once.
 *
 * One run:
 *   1. recovers leases that expired (a crashed worker): the operation becomes `uncertain`, because the provider may have
 *      received the request; an uncertain operation is never sent again by the worker (see below);
 *   2. escalates waits that took too long (no confirmation after 6 hours, no connector pickup after 24 hours) to manual
 *      review;
 *   3. for each due operation of an `api` provider whose gates are open: leases it in a short transaction, reads the
 *      provider's status OUTSIDE any transaction, sends only if the order is still open and cancellable and the operation
 *      is `queued` or `retry_scheduled` (never `uncertain` or `awaiting_confirmation`), then records
 *      the result in a second short transaction that first re-checks the lease. A result arriving after somebody else
 *      changed the operation (an inbound confirmation, a withdrawal) is recorded without changing the state.
 *
 * Uncertain outcomes are never replayed. Trendyol documents no idempotency, no request-status lookup and no consistency
 * window, so a package that still reads open after a lost answer, a 5xx or a crash does not prove that the notice was not
 * received. The worker keeps reading (every CONSISTENCY_WINDOW_SECONDS) and confirms when the package shows cancelled; if it
 * is still open after UNCERTAIN_OBSERVATION_SECONDS, the operation goes to manual review (UNCERTAIN_OUTCOME_UNRESOLVED) and a
 * person decides, after checking the Seller Panel, whether to retry. Production stays stopped meanwhile.
 *
 * Source-pull providers (WooCommerce) are never contacted: their connector pulls. With the server switch or a provider flag
 * off, step 3 does nothing for that provider; inbound confirmations still arrive through the ordinary synchronization.
 */
final class CancellationSyncWorker
{
    private string $owner;

    public function __construct(private PDO $pdo, private Clock $clock, private ProviderRegistry $providers)
    {
        $this->owner = 'worker-' . substr(Uuid::v4(), 0, 18);
    }

    /** @return array{recovered: int, escalated: int, processed: int, results: array<string, int>} */
    public function run(int $limit = 20): array
    {
        $operations = new SyncOperations($this->pdo, $this->clock);
        $summary = ['recovered' => $this->recoverLeases($operations), 'escalated' => $this->escalate($operations), 'processed' => 0, 'results' => []];
        $due = $this->pdo->prepare("SELECT operation_uuid, source_key FROM order_cancellation_sync_operations
            WHERE mechanism = 'api' AND state IN ('queued', 'retry_scheduled', 'uncertain', 'awaiting_confirmation') AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
            ORDER BY next_attempt_at, created_at LIMIT " . max(1, min(100, $limit)));
        $due->execute([$operations->now()]);
        foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            if (!$this->providers->active((string) $candidate['source_key'])) {
                continue;
            }
            $leased = $this->lease((string) $candidate['operation_uuid'], $operations);
            if ($leased === null) {
                continue;
            }
            [$operation, $prior] = $leased;
            $result = $this->process($operation, $prior, $operations);
            $summary['processed']++;
            $summary['results'][$result] = ($summary['results'][$result] ?? 0) + 1;
        }
        return $summary;
    }

    /** @return array{0: array<string, mixed>, 1: string}|null the leased operation and its state before the lease */
    private function lease(string $operationUuid, SyncOperations $operations): ?array
    {
        return $this->transaction(function () use ($operationUuid, $operations): ?array {
            $hint = $operations->byId($operationUuid, false);
            if ($hint === null) {
                return null;
            }
            $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE')->execute([$hint['order_uuid']]);
            $operation = $operations->byId($operationUuid);
            if ($operation === null || !in_array($operation['state'], [SyncState::QUEUED, SyncState::RETRY_SCHEDULED, SyncState::UNCERTAIN, SyncState::AWAITING_CONFIRMATION], true)
                || ($operation['next_attempt_at'] !== null && (string) $operation['next_attempt_at'] > $operations->now())) {
                return null;
            }
            $prior = (string) $operation['state'];
            $leased = $operations->transition($operation, SyncState::PROCESSING, ['lease_owner' => $this->owner, 'lease_expires_at' => $operations->at(SyncOperations::LEASE_SECONDS)],
                ['kind' => 'status_read', 'outcome' => 'leased', 'system' => $this->owner]);
            return [$leased, $prior];
        });
    }

    /** Reads, maybe sends, then records; returns the resulting state. @param array<string, mixed> $operation */
    private function process(array $operation, string $prior, SyncOperations $operations): string
    {
        $provider = $this->providers->for((string) $operation['source_key']);
        try {
            $read = $provider->readStatus($operation);
        } catch (Throwable) {
            $read = new ProviderResult('transient', null, 'PROVIDER_READ_FAILED');
        }
        $submitted = null;
        if ($read->outcome === 'open' && in_array($prior, [SyncState::QUEUED, SyncState::RETRY_SCHEDULED], true) && (int) $operation['attempt_count'] < SyncOperations::MAX_SUBMITS) {
            try {
                $submitted = $provider->submit($operation);
            } catch (Throwable) {
                $submitted = new ProviderResult('uncertain', null, 'PROVIDER_SUBMIT_FAILED');
            }
        }
        return $this->record($operation, $prior, $read, $submitted, $operations);
    }

    /** @param array<string, mixed> $leased */
    private function record(array $leased, string $prior, ProviderResult $read, ?ProviderResult $submitted, SyncOperations $operations): string
    {
        return $this->transaction(function () use ($leased, $prior, $read, $submitted, $operations): string {
            $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE')->execute([$leased['order_uuid']]);
            $operation = $operations->byId((string) $leased['operation_uuid']);
            if ($operation === null) {
                return 'missing';
            }
            $late = $operation['state'] !== SyncState::PROCESSING || $operation['lease_owner'] !== $this->owner;
            $attempt = ['system' => $this->owner, 'providerStatus' => $read->providerStatus];
            if ($late) {
                // Somebody else decided meanwhile (inbound confirmation, lease recovery): keep their decision, keep the evidence.
                $operations->note($operation, $submitted === null ? 'status_read' : 'submit', 'late_' . ($submitted?->outcome ?? $read->outcome), $submitted?->errorCode ?? $read->errorCode, null, $this->owner, null, $read->providerStatus);
                return 'late';
            }
            [$to, $changes, $kind, $outcome, $error, $http] = $this->decide($operation, $prior, $read, $submitted, $operations);
            $operations->transition($operation, $to, $changes, $attempt + ['kind' => $kind, 'outcome' => $outcome, 'error' => $error, 'http' => $http]);
            return $to;
        });
    }

    /**
     * @param array<string, mixed> $operation
     * @return array{0: string, 1: array<string, mixed>, 2: string, 3: string, 4: string|null, 5: int|null}
     */
    private function decide(array $operation, string $prior, ProviderResult $read, ?ProviderResult $submitted, SyncOperations $operations): array
    {
        $back = static fn (string $state): string => $state === SyncState::QUEUED ? SyncState::RETRY_SCHEDULED : $state;
        $exhausted = static fn (int $attempts): bool => $attempts >= SyncOperations::MAX_SUBMITS;
        switch ($read->outcome) {
            case 'cancelled':
                return [SyncState::CONFIRMED, ['confirmed_by' => 'status_read', 'provider_status' => $read->providerStatus], 'status_read', 'cancelled', null, null];
            case 'auth_failed':
                return [SyncState::FAILED, ['next_attempt_at' => null], 'status_read', 'auth_failed', $read->errorCode, null];
            case 'not_configured':
                return [SyncState::MANUAL_REVIEW, ['next_attempt_at' => null], 'status_read', 'not_configured', $read->errorCode, null];
            case 'rejected':
            case 'not_cancellable':
                // Refused before anything was sent; after a possible send the outcome needs a person.
                $sentBefore = $operation['dispatched_at'] !== null || $prior === SyncState::UNCERTAIN;
                return [$sentBefore ? SyncState::MANUAL_REVIEW : SyncState::REJECTED, ['next_attempt_at' => null, 'provider_status' => $read->providerStatus], 'status_read', $read->outcome, $read->errorCode, null];
            case 'transient':
                if (strtotime((string) $operation['created_at'] . ' UTC') <= $this->clock->now()->getTimestamp() - SyncOperations::CONFIRMATION_TIMEOUT_SECONDS) {
                    // The provider could not be read for six hours: a person decides, nothing is sent blind.
                    return [SyncState::MANUAL_REVIEW, ['next_attempt_at' => null], 'status_read', 'transient', 'PROVIDER_UNREACHABLE', null];
                }
                return [$back($prior), ['next_attempt_at' => $operations->backoff((int) $operation['attempt_count'] + 1)], 'status_read', 'transient', $read->errorCode, null];
        }
        // The provider shows the order still open.
        if ($prior === SyncState::AWAITING_CONFIRMATION) {
            $waited = $operation['dispatched_at'] !== null && strtotime((string) $operation['dispatched_at'] . ' UTC') <= $this->clock->now()->getTimestamp() - SyncOperations::CONFIRMATION_TIMEOUT_SECONDS;
            return $waited
                ? [SyncState::MANUAL_REVIEW, ['next_attempt_at' => null], 'status_read', 'still_open', 'AWAITING_CONFIRMATION_TIMEOUT', null]
                : [SyncState::AWAITING_CONFIRMATION, ['next_attempt_at' => $operations->at(120)], 'status_read', 'still_open', null, null];
        }
        if ($prior === SyncState::UNCERTAIN) {
            // Never sent again automatically: an open read does not prove the earlier notice was lost. Keep reading for the
            // observation window (a delayed or stale status), then a person decides.
            $since = strtotime((string) ($operation['dispatched_at'] ?? $operation['updated_at']) . ' UTC');
            return $since <= $this->clock->now()->getTimestamp() - SyncOperations::UNCERTAIN_OBSERVATION_SECONDS
                ? [SyncState::MANUAL_REVIEW, ['next_attempt_at' => null], 'status_read', 'still_open', 'UNCERTAIN_OUTCOME_UNRESOLVED', null]
                : [SyncState::UNCERTAIN, ['next_attempt_at' => $operations->at(SyncOperations::CONSISTENCY_WINDOW_SECONDS)], 'status_read', 'still_open', 'UNCERTAIN_STILL_OPEN', null];
        }
        if ($submitted === null) {
            return [SyncState::MANUAL_REVIEW, ['next_attempt_at' => null], 'status_read', 'attempts_exhausted', 'ATTEMPTS_EXHAUSTED', null];
        }
        $attempts = (int) $operation['attempt_count'] + 1;
        $base = ['attempt_count' => $attempts, 'provider_result' => $submitted->outcome, 'provider_result_at' => $operations->now()];
        return match ($submitted->outcome) {
            'accepted' => [SyncState::AWAITING_CONFIRMATION, $base + ['dispatched_at' => $operations->now(), 'next_attempt_at' => $operations->at(60)], 'submit', 'accepted', null, $submitted->httpStatus],
            'rejected', 'not_cancellable' => [SyncState::REJECTED, $base + ['next_attempt_at' => null], 'submit', $submitted->outcome, $submitted->errorCode, $submitted->httpStatus],
            'auth_failed' => [SyncState::FAILED, $base + ['next_attempt_at' => null], 'submit', 'auth_failed', $submitted->errorCode, $submitted->httpStatus],
            'transient' => $exhausted($attempts)
                ? [SyncState::MANUAL_REVIEW, $base + ['next_attempt_at' => null], 'submit', 'transient', 'ATTEMPTS_EXHAUSTED', $submitted->httpStatus]
                : [SyncState::RETRY_SCHEDULED, $base + ['next_attempt_at' => $operations->backoff($attempts)], 'submit', 'transient', $submitted->errorCode, $submitted->httpStatus],
            'uncertain' => [SyncState::UNCERTAIN, $base + ['dispatched_at' => $operations->now(), 'next_attempt_at' => $operations->at(SyncOperations::CONSISTENCY_WINDOW_SECONDS)], 'submit', 'uncertain', $submitted->errorCode, $submitted->httpStatus],
            default => [SyncState::MANUAL_REVIEW, $base + ['next_attempt_at' => null], 'submit', $submitted->outcome, $submitted->errorCode, $submitted->httpStatus],
        };
    }

    private function recoverLeases(SyncOperations $operations): int
    {
        $expired = $this->pdo->prepare("SELECT operation_uuid FROM order_cancellation_sync_operations WHERE state = 'processing' AND lease_expires_at < ?");
        $expired->execute([$operations->now()]);
        $count = 0;
        foreach ($expired->fetchAll(PDO::FETCH_COLUMN) as $operationUuid) {
            $count += (int) $this->transaction(function () use ($operationUuid, $operations): bool {
                $hint = $operations->byId((string) $operationUuid, false);
                if ($hint === null) {
                    return false;
                }
                $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE')->execute([$hint['order_uuid']]);
                $operation = $operations->byId((string) $operationUuid);
                if ($operation === null || $operation['state'] !== SyncState::PROCESSING || (string) $operation['lease_expires_at'] >= $operations->now()) {
                    return false;
                }
                // The dead worker may have sent: from now on the operation counts as possibly dispatched.
                $operations->transition($operation, SyncState::UNCERTAIN, ['next_attempt_at' => $operations->now(), 'dispatched_at' => $operation['dispatched_at'] ?? $operations->now()],
                    ['kind' => 'lease_recovery', 'outcome' => 'lease_expired', 'error' => 'WORKER_LEASE_EXPIRED', 'system' => $this->owner]);
                return true;
            });
        }
        return $count;
    }

    private function escalate(SyncOperations $operations): int
    {
        $now = $this->clock->now();
        $confirmBefore = $now->modify('-' . SyncOperations::CONFIRMATION_TIMEOUT_SECONDS . ' seconds')->format('Y-m-d H:i:s.u');
        $pickupBefore = $now->modify('-' . SyncOperations::PICKUP_TIMEOUT_SECONDS . ' seconds')->format('Y-m-d H:i:s.u');
        // API operations escalate through their own status reads; these are the waits only a timeout can end.
        $statement = $this->pdo->prepare("SELECT operation_uuid FROM order_cancellation_sync_operations
            WHERE (mechanism = 'source_pull' AND state = 'awaiting_confirmation' AND dispatched_at < ?) OR (state = 'awaiting_pickup' AND created_at < ?)");
        $statement->execute([$confirmBefore, $pickupBefore]);
        $count = 0;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $operationUuid) {
            $count += (int) $this->transaction(function () use ($operationUuid, $operations): bool {
                $hint = $operations->byId((string) $operationUuid, false);
                if ($hint === null) {
                    return false;
                }
                $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE')->execute([$hint['order_uuid']]);
                $operation = $operations->byId((string) $operationUuid);
                if ($operation === null || !in_array($operation['state'], [SyncState::AWAITING_CONFIRMATION, SyncState::AWAITING_PICKUP], true)) {
                    return false;
                }
                $code = $operation['state'] === SyncState::AWAITING_PICKUP ? 'PICKUP_TIMEOUT' : 'AWAITING_CONFIRMATION_TIMEOUT';
                $operations->transition($operation, SyncState::MANUAL_REVIEW, ['next_attempt_at' => null], ['kind' => 'expire', 'outcome' => 'timeout', 'error' => $code, 'system' => $this->owner]);
                return true;
            });
        }
        return $count;
    }

    /** @template T @param callable(): T $operation @return T */
    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
