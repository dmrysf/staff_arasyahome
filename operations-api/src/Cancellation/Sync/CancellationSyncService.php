<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation\Sync;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Cancellation\CancellationControl;
use Arasya\Operations\Cancellation\CancellationView;
use Arasya\Operations\Confirmare\ConfirmareFinance;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Integration\Trendyol\TrendyolCancellationApi;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Quality\IdempotencyStore;
use Arasya\Operations\Support\Clock;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Commands and reads of the outbound cancellation synchronization (API 2.36.0).
 *
 * Management (Dashboard, permission production.cancellation.sync, re-checked under the actor's row lock):
 *   send      create the one operation of the current stop: Trendyol (api) needs one of Trendyol's reasons; a WooCommerce
 *             source (source_pull) is handed to its connector; a channel without a supported call goes to manual review
 *   retry     a failed, rejected or manual-review operation, or a scheduled retry, is due again now (never an uncertain one:
 *             the worker reads the provider status first). Once an earlier notice may have reached the provider, a retry
 *             needs both the server's own evidence (a status read that found the package open after the observation
 *             window, at most an hour old) and the person's signed statement of their check in the channel (time and
 *             note); the statement is recorded as an attestation, never as a provider confirmation
 *   recheck   one more read-only status read of a possibly delivered operation in manual review (never a send)
 *   withdraw  an operation that never reached the provider (queued, scheduled, or not yet picked up by the connector)
 *   close     a manual-review operation a person handled outside Arasya (this is not a confirmation)
 * Source connector (signed source routes, YD SOFT):
 *   pull      the source's operations waiting for pickup (only while both gates are open)
 *   report    the connector's result for one of its own operations; idempotent for the same outcome
 *
 * Every command locks the canonical order row first, then the operation row. No command contacts a provider: only the
 * worker does, outside any transaction. Nothing here starts a refund.
 */
final readonly class CancellationSyncService
{
    public const PERMISSION = 'production.cancellation.sync';
    public const REPORT_OUTCOMES = ['cancelled', 'already_cancelled', 'rejected_paid', 'rejected_status', 'not_found'];
    private const MAX_ATTEMPTS = 3;
    private const RETRYABLE = [1205, 1213];
    private const PULL_LIMIT = 20;
    /** The shop's financial attentions that mean a finance entry is not decided yet, or the money state is not known. */
    private const FINANCE_OPEN_ATTENTIONS = ['transfer_pending', 'refund_pending', 'reconciliation_required'];

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private IdempotencyStore $idempotency,
        private Clock $clock,
        private ProviderRegistry $providers,
    ) {
    }

    // ------------------------------------------------------------------ reads

    /**
     * The synchronization part of an order's cancellation view: the provider's capability and gates, the operation of
     * the current stop with its attempts, and the actor's allowed actions. @return array<string, mixed>
     */
    public function orderView(EmployeeIdentity $actor, array $order, ?array $control): array
    {
        $source = (string) $order['source_key'];
        $operation = null;
        if ($control !== null && isset($control['hold_generation'])) {
            $operation = (new SyncOperations($this->pdo, $this->clock))->forGeneration((string) $order['order_uuid'], (int) $control['hold_generation'], false);
        }
        $open = (new SyncOperations($this->pdo, $this->clock))->openFor((string) $order['order_uuid'], false);
        $can = $this->authorization->can($actor, self::PERMISSION);
        $held = $control !== null && $control['hold_status'] === CancellationControl::HELD;
        $provider = $this->providers->view($source);
        $sendable = $provider['mechanism'] === 'manual' || $provider['active'];
        return [
            'provider' => $provider,
            'operation' => $operation === null ? null : $this->operationView($operation),
            'openOperationId' => $open === null ? null : (string) $open['operation_uuid'],
            'required' => $held && $control['source_status'] !== 'cancelled',
            'actions' => [
                'send' => $can && $sendable && $held && $control['source_status'] !== 'cancelled' && $operation === null && $open === null,
                'retry' => $can && $operation !== null && in_array($operation['state'], [SyncState::FAILED, SyncState::REJECTED, SyncState::MANUAL_REVIEW, SyncState::RETRY_SCHEDULED], true) && $held,
                'recheck' => $can && $operation !== null && $this->recheckable($operation) && $held,
                'withdraw' => $can && $operation !== null && self::withdrawable($operation),
                'close' => $can && $operation !== null && $operation['state'] === SyncState::MANUAL_REVIEW,
            ],
        ];
    }

    /** @param array<string, mixed> $operation @return array<string, mixed> */
    public function operationView(array $operation): array
    {
        $attempts = $this->pdo->prepare('SELECT a.attempt_seq, a.kind, a.state_before, a.state_after, a.outcome, a.http_status, a.error_code, a.provider_status, a.actor_system, a.evidence_json, a.occurred_at,
                e.employee_uuid, e.display_name FROM order_cancellation_sync_attempts a LEFT JOIN employees e ON e.employee_uuid = a.actor_employee_uuid
            WHERE a.operation_uuid = ? ORDER BY a.attempt_seq DESC LIMIT 50');
        $attempts->execute([$operation['operation_uuid']]);
        $evidence = $operation['mechanism'] === 'api' ? (new SyncOperations($this->pdo, $this->clock))->providerEvidence((string) $operation['operation_uuid']) : ['status' => 'not_required'];
        return [
            'id' => (string) $operation['operation_uuid'],
            'state' => (string) $operation['state'],
            'staffState' => SyncState::staffView((string) $operation['state']),
            'mechanism' => (string) $operation['mechanism'],
            'generation' => (int) $operation['hold_generation'],
            'reasonCode' => $operation['reason_code'] === null ? null : (string) $operation['reason_code'],
            'attempts' => (int) $operation['attempt_count'],
            'nextAttemptAt' => CancellationView::isoOrNull($operation['next_attempt_at']),
            'dispatchedAt' => CancellationView::isoOrNull($operation['dispatched_at']),
            'lastPulledAt' => CancellationView::isoOrNull($operation['last_pulled_at']),
            'providerResult' => $operation['provider_result'] === null ? null : (string) $operation['provider_result'],
            'providerStatus' => $operation['provider_status'] === null ? null : (string) $operation['provider_status'],
            'confirmedAt' => CancellationView::isoOrNull($operation['confirmed_at']),
            'confirmedBy' => $operation['confirmed_by'] === null ? null : (string) $operation['confirmed_by'],
            'lastError' => $operation['last_error_code'] === null ? null : (string) $operation['last_error_code'],
            'lastErrorAt' => CancellationView::isoOrNull($operation['last_error_at']),
            // An earlier notice may already have reached the channel: a retry needs the server's evidence and a person's check.
            'providerCheckRequired' => $evidence['status'] !== 'not_required',
            'providerEvidence' => $evidence['status'] === 'not_required' ? null : [
                'status' => $evidence['status'], 'readAt' => CancellationView::isoOrNull($evidence['readAt']), 'providerStatus' => $evidence['providerStatus'],
                'maxAgeSeconds' => SyncOperations::RETRY_EVIDENCE_MAX_AGE_SECONDS, 'observationSeconds' => SyncOperations::UNCERTAIN_OBSERVATION_SECONDS,
            ],
            'createdAt' => CancellationView::iso((string) $operation['created_at']),
            'version' => (int) $operation['version'],
            'history' => array_map(static fn (array $row): array => [
                'sequence' => (int) $row['attempt_seq'], 'kind' => (string) $row['kind'], 'from' => (string) $row['state_before'], 'to' => (string) $row['state_after'],
                'outcome' => (string) $row['outcome'], 'httpStatus' => $row['http_status'] === null ? null : (int) $row['http_status'],
                'error' => $row['error_code'] === null ? null : (string) $row['error_code'], 'providerStatus' => $row['provider_status'] === null ? null : (string) $row['provider_status'],
                'actor' => $row['employee_uuid'] === null ? ($row['actor_system'] === null ? null : ['system' => (string) $row['actor_system']]) : ['id' => (string) $row['employee_uuid'], 'displayName' => (string) $row['display_name']],
                'evidence' => $row['evidence_json'] === null ? null : self::evidenceView((string) $row['evidence_json']),
                'occurredAt' => CancellationView::iso((string) $row['occurred_at']),
            ], $attempts->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    // ------------------------------------------------------------------ management commands

    /** @param array<string, mixed> $input @return array<string, mixed> the operation view */
    public function send(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId): array
    {
        $this->authorization->require($actor, self::PERMISSION);
        IdempotencyStore::requireKey($key);
        $globalId = self::globalId($globalOrderId);
        $expected = self::version($input['expectedVersion'] ?? null);
        $reason = $input['reasonCode'] ?? null;
        if ($reason !== null && (!is_int($reason) || !array_key_exists($reason, TrendyolCancellationApi::REASONS))) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'reasonCode must be one of Trendyol\'s documented reasons (500-506).', ['field' => 'reasonCode']);
        }
        $note = self::optionalText($input['note'] ?? null);
        $hash = IdempotencyStore::hash('cancellation.sync.send', $globalId, [$expected, $reason, $note]);
        return $this->command($actor, 'cancellation.sync.send', $globalId, $key, $hash, function (EmployeeIdentity $actor, array $order) use ($expected, $reason, $note, $requestId, $key): array {
            $control = $this->control((string) $order['order_uuid']);
            if ($control === null || $control['hold_status'] !== CancellationControl::HELD) {
                throw new ApiException(409, 'CANCELLATION_NOT_HELD', 'Producția acestei comenzi nu este oprită.');
            }
            if ((int) $control['version'] !== $expected) {
                throw new ApiException(409, 'CANCELLATION_CHANGED', 'Starea opririi s-a schimbat. Reîncarcă înainte de a continua.');
            }
            if ($control['source_status'] === 'cancelled') {
                throw new ApiException(409, 'SOURCE_ALREADY_CANCELLED', 'Sursa a anulat deja comanda; nu este necesară nicio cerere.');
            }
            $this->assertFinanceClosed($order);
            $operations = new SyncOperations($this->pdo, $this->clock);
            $generation = (int) $control['hold_generation'];
            if ($operations->forGeneration((string) $order['order_uuid'], $generation) !== null) {
                throw new ApiException(409, 'CANCELLATION_SYNC_EXISTS', 'Cererea către sursă pentru această oprire există deja. Folosește reîncercarea.');
            }
            if ($operations->openFor((string) $order['order_uuid']) !== null) {
                throw new ApiException(409, 'CANCELLATION_SYNC_OPEN', 'O cerere anterioară către sursă este încă deschisă.');
            }
            $source = (string) $order['source_key'];
            $provider = $this->providers->for($source);
            $external = $this->externalRef($order);
            if ($source === 'trendyol' && $this->beforeTrendyolBaseline((string) $order['order_uuid'])) {
                // Trendyol Fresh Start: nothing from before the intake baseline is ever re-cancelled or replayed.
                throw new ApiException(409, 'TRENDYOL_BEFORE_BASELINE', 'Comanda Trendyol este anterioară pragului de pornire; nu se trimite nicio anulare.');
            }
            $mechanism = $provider->mechanism();
            if ($mechanism !== 'manual' && $external === null) {
                $mechanism = 'manual';
            }
            if ($mechanism === 'api' && $reason === null) {
                throw new ApiException(422, 'REASON_REQUIRED', 'Alege motivul Trendyol (500-506). Nu se alege automat.', ['field' => 'reasonCode']);
            }
            if ($mechanism !== 'manual' && !$this->providers->active($source)) {
                throw new ApiException(409, 'CANCELLATION_SYNC_DISABLED', 'Sincronizarea anulării cu această sursă nu este activată.');
            }
            [$state, $error] = match ($mechanism) {
                'api' => [SyncState::QUEUED, null],
                'source_pull' => [SyncState::AWAITING_PICKUP, null],
                default => [SyncState::MANUAL_REVIEW, $provider instanceof ManualProvider ? $provider->reason() : ($external === null ? 'EXTERNAL_ORDER_UNKNOWN' : 'PROVIDER_UNSUPPORTED')],
            };
            (new CancellationControl($this->pdo, $this->clock))->requestSource((string) $order['order_uuid'], $actor->employeeUuid, $requestId, $key, $mechanism);
            $operation = $operations->create((string) $order['order_uuid'], $generation, $source, $external ?? (string) $order['global_order_id'], $mechanism, $state,
                $reason === null ? null : (string) $reason, $note, $actor->employeeUuid, $error, $requestId);
            $this->audit($actor, 'production.cancellation.sync_requested', $order, ['operation' => $operation['operation_uuid'], 'mechanism' => $mechanism, 'state' => $state, 'reasonCode' => $reason], $requestId);
            return $this->operationView($operation);
        });
    }

    /**
     * Owner master decision 2026-10-25 (K-05, API 2.46.0): an advance proforma still unpaid 120 hours after it was sent,
     * with a bank check of its shop covering that instant, is cancelled in SmartBill by the Confirmare cycle worker
     * (ConfirmareCycles), which then asks here for the order's cancellation in the shop. The trigger is a server rule, not
     * a person's choice, so no production.cancellation.sync permission is read; the cycle's owner (the operator who
     * started the proforma) is the recorded actor. The guards of `send` stay: an open finance entry refuses it (rule 2),
     * an order the shop already cancelled needs no request, and the operation of the current stop is created once (a
     * repeated call answers with the existing one).
     *
     * @return array{state: string, operation?: string, mechanism?: string}
     */
    public function requestForExpiredProforma(string $orderUuid, string $actorUuid, string $requestId, string $key): array
    {
        return $this->transactionWithRetry(function () use ($orderUuid, $actorUuid, $requestId, $key): array {
            $order = $this->lockOrder($orderUuid);
            if ($this->financeOpen($order)) {
                return ['state' => 'finance_open'];
            }
            (new CancellationControl($this->pdo, $this->clock))->stopLocally($orderUuid, $actorUuid, 'Proforma de avans a expirat fără plată (120 de ore, verificare bancară înregistrată).', true, $requestId, $key);
            $control = $this->control($orderUuid);
            if ($control === null || $control['hold_status'] !== CancellationControl::HELD) {
                return ['state' => 'not_held'];
            }
            if ($control['source_status'] === 'cancelled') {
                return ['state' => 'source_cancelled'];
            }
            $operations = new SyncOperations($this->pdo, $this->clock);
            $generation = (int) $control['hold_generation'];
            $existing = $operations->forGeneration($orderUuid, $generation);
            if ($existing !== null) {
                return ['state' => 'requested', 'operation' => (string) $existing['operation_uuid'], 'mechanism' => (string) $existing['mechanism']];
            }
            if ($operations->openFor($orderUuid) !== null) {
                return ['state' => 'sync_open'];
            }
            $source = (string) $order['source_key'];
            $provider = $this->providers->for($source);
            $external = $this->externalRef($order);
            $mechanism = $provider->mechanism();
            if ($mechanism !== 'manual' && ($external === null || !$this->providers->active($source))) {
                $mechanism = 'manual';
            }
            [$state, $error] = match ($mechanism) {
                'source_pull' => [SyncState::AWAITING_PICKUP, null],
                'api' => [SyncState::MANUAL_REVIEW, 'REASON_REQUIRED'],
                default => [SyncState::MANUAL_REVIEW, $provider instanceof ManualProvider ? $provider->reason() : ($external === null ? 'EXTERNAL_ORDER_UNKNOWN' : 'PROVIDER_UNSUPPORTED')],
            };
            if ($mechanism === 'api') {
                $mechanism = 'manual';
            }
            (new CancellationControl($this->pdo, $this->clock))->requestSource($orderUuid, $actorUuid, $requestId, $key, $mechanism);
            $operation = $operations->create($orderUuid, $generation, $source, $external ?? (string) $order['global_order_id'], $mechanism, $state, null,
                'Proforma de avans expirată fără plată (Confirmare).', $actorUuid, $error, $requestId);
            $actor = (new PdoEmployeeRepository($this->pdo))->findByUuid($actorUuid);
            if ($actor !== null) {
                $this->audit($actor, 'production.cancellation.sync_requested', $order, ['operation' => $operation['operation_uuid'], 'mechanism' => $mechanism, 'state' => $state, 'trigger' => 'proforma_expired'], $requestId);
            }
            return ['state' => 'requested', 'operation' => (string) $operation['operation_uuid'], 'mechanism' => $mechanism];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function retry(EmployeeIdentity $actor, string $operationId, array $input, string $key, string $requestId): array
    {
        return $this->operationCommand($actor, 'retry', $operationId, $input, $key, $requestId, function (EmployeeIdentity $actor, array $order, array $operation, SyncOperations $operations) use ($requestId, $input): array {
            $control = $this->control((string) $order['order_uuid']);
            if ($control === null || $control['hold_status'] !== CancellationControl::HELD || (int) $control['hold_generation'] !== (int) $operation['hold_generation']) {
                throw new ApiException(409, 'CANCELLATION_SYNC_STALE', 'Operația aparține unei opriri care nu mai este în vigoare.');
            }
            if ($control['source_status'] === 'cancelled') {
                throw new ApiException(409, 'SOURCE_ALREADY_CANCELLED', 'Sursa a anulat deja comanda; nu este necesară nicio reîncercare.');
            }
            $this->assertFinanceClosed($order);
            if (!in_array($operation['state'], [SyncState::FAILED, SyncState::REJECTED, SyncState::MANUAL_REVIEW, SyncState::RETRY_SCHEDULED], true)) {
                throw new ApiException(409, 'CANCELLATION_SYNC_RETRY_NOT_ELIGIBLE', 'Această operație nu poate fi reîncercată acum.', ['state' => $operation['state']]);
            }
            if ($operation['mechanism'] === 'manual' || !$this->providers->active((string) $operation['source_key'])) {
                throw new ApiException(409, 'CANCELLATION_SYNC_DISABLED', 'Sincronizarea anulării cu această sursă nu este activată.');
            }
            $other = $operations->openFor((string) $order['order_uuid']);
            if ($other !== null && $other['operation_uuid'] !== $operation['operation_uuid']) {
                throw new ApiException(409, 'CANCELLATION_SYNC_OPEN', 'O altă cerere către sursă este deschisă.');
            }
            // Once a notice may have reached the channel (accepted or lost answer, 5xx, crashed worker), the provider documents no
            // idempotency and no request lookup. A second notice then needs two things the client alone cannot supply:
            //   1. the server's own evidence: its last status read since the possible delivery found the package open, after
            //      the observation window, and is at most RETRY_EVIDENCE_MAX_AGE_SECONDS old (a recheck refreshes it);
            //   2. the person's statement of their own check in the channel: when they checked and what they saw (an
            //      attestation recorded with their identity, never shown as a provider confirmation).
            // A rescheduled retry (already retry_scheduled) only brings an authorized round forward and authorizes nothing new;
            // the worker independently refuses to send after a possible delivery without such a retry (sendAuthorized).
            $evidence = null;
            $possiblyDelivered = $operation['mechanism'] === 'api' && $operations->possiblyDelivered((string) $operation['operation_uuid']);
            if ($possiblyDelivered && $operation['state'] !== SyncState::RETRY_SCHEDULED) {
                $check = $this->providerCheck($input['providerCheck'] ?? null, $operations);
                $server = $operations->providerEvidence((string) $operation['operation_uuid']);
                if ($server['status'] !== 'ok') {
                    throw new ApiException(409, 'CANCELLATION_SYNC_PROVIDER_EVIDENCE_REQUIRED', match ($server['status']) {
                        'stale', 'missing', 'unknown' => 'Arasya nu are o citire recentă a stării din canal. Cere o recitire a stării înainte de a reîncerca.',
                        'observing' => 'Fereastra de observare după trimiterea anterioară nu s-a încheiat. Așteaptă rezultatul.',
                        default => 'Ultima citire a canalului arată că o nouă notificare nu este posibilă sau necesară.',
                    }, ['evidence' => $server['status']]);
                }
                $evidence = ['attestation' => $check + ['employeeId' => $actor->employeeUuid], 'serverRead' => ['attemptSeq' => $server['attemptSeq'], 'readAt' => CancellationView::isoOrNull($server['readAt']),
                    'providerStatus' => $server['providerStatus']], 'deliveryAttemptSeq' => $server['deliverySeq']];
            }
            $attempt = ['kind' => 'retry', 'outcome' => $evidence !== null ? 'retry_after_provider_check' : ($possiblyDelivered ? 'retry_rescheduled' : 'retry_requested'),
                'actor' => $actor->employeeUuid, 'correlation' => $requestId, 'evidence' => $evidence];
            if ($operation['state'] !== SyncState::RETRY_SCHEDULED) {
                // A new round: the attempt budget starts again, the history keeps every earlier attempt.
                $operation = $operations->transition($operation, SyncState::RETRY_SCHEDULED, ['next_attempt_at' => $operations->now(), 'attempt_count' => 0], $attempt);
            } else {
                $this->pdo->prepare('UPDATE order_cancellation_sync_operations SET next_attempt_at = ?, version = version + 1, updated_at = ? WHERE operation_uuid = ? AND version = ?')
                    ->execute([$operations->now(), $operations->now(), $operation['operation_uuid'], $operation['version']]);
                $operations->note($operation, 'retry', $attempt['outcome'], null, $actor->employeeUuid, null, $requestId, null, $evidence);
                $operation = $operations->byId((string) $operation['operation_uuid']) ?? $operation;
            }
            if ($operation['mechanism'] === 'source_pull') {
                $operation = $operations->transition($operation, SyncState::AWAITING_PICKUP, ['next_attempt_at' => null, 'last_pulled_at' => null], ['kind' => 'retry', 'outcome' => 'handed_to_source', 'actor' => $actor->employeeUuid, 'correlation' => $requestId]);
            }
            return $operation;
        });
    }

    /**
     * A person asks for one more status read of a possibly delivered operation in manual review (stale or missing
     * evidence, or to see whether the channel has acted meanwhile). The operation becomes `uncertain` and is due now: the
     * worker only reads it (it never sends from `uncertain`) and confirms, keeps it in manual review with fresh evidence,
     * or records what the channel now shows.
     * @param array<string, mixed> $input @return array<string, mixed>
     */
    public function recheck(EmployeeIdentity $actor, string $operationId, array $input, string $key, string $requestId): array
    {
        return $this->operationCommand($actor, 'recheck', $operationId, $input, $key, $requestId, function (EmployeeIdentity $actor, array $order, array $operation, SyncOperations $operations) use ($requestId): array {
            if (!$this->recheckable($operation)) {
                throw new ApiException(409, 'CANCELLATION_SYNC_RECHECK_NOT_ELIGIBLE', 'Recitirea stării este posibilă doar pentru o operație în verificare manuală, după o trimitere posibil ajunsă la canal.', ['state' => $operation['state']]);
            }
            if (!$this->providers->active((string) $operation['source_key'])) {
                throw new ApiException(409, 'CANCELLATION_SYNC_DISABLED', 'Sincronizarea anulării cu această sursă nu este activată.');
            }
            return $operations->transition($operation, SyncState::UNCERTAIN, ['next_attempt_at' => $operations->now()],
                ['kind' => 'recheck', 'outcome' => 'recheck_requested', 'actor' => $actor->employeeUuid, 'correlation' => $requestId]);
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function withdraw(EmployeeIdentity $actor, string $operationId, array $input, string $key, string $requestId): array
    {
        $reason = self::text($input['reason'] ?? null, 10, 'reason');
        return $this->operationCommand($actor, 'withdraw', $operationId, $input, $key, $requestId, function (EmployeeIdentity $actor, array $order, array $operation, SyncOperations $operations) use ($reason, $requestId): array {
            if (!self::withdrawable($operation)) {
                throw new ApiException(409, 'CANCELLATION_SYNC_NOT_WITHDRAWABLE', 'Cererea a ajuns deja la sursă sau este în lucru; nu mai poate fi retrasă. Așteaptă rezultatul.', ['state' => $operation['state']]);
            }
            return $operations->transition($operation, SyncState::WITHDRAWN, ['note' => $reason, 'next_attempt_at' => null, 'closed_at' => $operations->now(), 'closed_by_employee_uuid' => $actor->employeeUuid],
                ['kind' => 'withdraw', 'outcome' => 'withdrawn', 'actor' => $actor->employeeUuid, 'correlation' => $requestId]);
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function close(EmployeeIdentity $actor, string $operationId, array $input, string $key, string $requestId): array
    {
        $note = self::text($input['note'] ?? null, 10, 'note');
        return $this->operationCommand($actor, 'close', $operationId, $input, $key, $requestId, function (EmployeeIdentity $actor, array $order, array $operation, SyncOperations $operations) use ($note, $requestId): array {
            if ($operation['state'] !== SyncState::MANUAL_REVIEW) {
                throw new ApiException(409, 'CANCELLATION_SYNC_STATE', 'Doar o operație aflată în verificare manuală poate fi închisă.', ['state' => $operation['state']]);
            }
            // Closing records that a person handled it outside Arasya; it is never shown as a confirmation.
            return $operations->transition($operation, SyncState::CLOSED, ['note' => $note, 'next_attempt_at' => null, 'closed_at' => $operations->now(), 'closed_by_employee_uuid' => $actor->employeeUuid],
                ['kind' => 'close', 'outcome' => 'closed_by_person', 'actor' => $actor->employeeUuid, 'correlation' => $requestId]);
        });
    }

    // ------------------------------------------------------------------ source connector (signed routes)

    /** @return array<string, mixed> */
    public function pull(string $sourceKey, string $requestId): array
    {
        if (!$this->providers->active($sourceKey) || $this->providers->for($sourceKey)->mechanism() !== 'source_pull') {
            return ['ok' => true, 'sourceKey' => $sourceKey, 'enabled' => false, 'operations' => []];
        }
        $statement = $this->pdo->prepare("SELECT operation_uuid FROM order_cancellation_sync_operations WHERE source_key = ? AND state = 'awaiting_pickup' ORDER BY created_at, operation_uuid LIMIT " . self::PULL_LIMIT);
        $statement->execute([$sourceKey]);
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $operationUuid) {
            $item = $this->transaction(function () use ($operationUuid, $sourceKey, $requestId): ?array {
                $operations = new SyncOperations($this->pdo, $this->clock);
                $hint = $operations->byId((string) $operationUuid, false);
                if ($hint === null) {
                    return null;
                }
                $order = $this->lockOrder((string) $hint['order_uuid']);
                $operation = $operations->byId((string) $operationUuid);
                if ($operation === null || $operation['state'] !== SyncState::AWAITING_PICKUP || $operation['source_key'] !== $sourceKey) {
                    return null;
                }
                // Owner rule 2: while a finance entry of the order is open, the shop is not asked to cancel. The operation
                // stays awaiting_pickup (not handed out, nothing recorded) and is offered again once the entry is decided.
                if ($this->financeOpen($order)) {
                    return null;
                }
                if ($operation['last_pulled_at'] === null) {
                    $operations->note($operation, 'pickup', 'picked_up', null, null, 'source:' . $sourceKey, $requestId);
                }
                $this->pdo->prepare('UPDATE order_cancellation_sync_operations SET last_pulled_at = ? WHERE operation_uuid = ?')->execute([$operations->now(), $operationUuid]);
                return ['operationId' => (string) $operation['operation_uuid'], 'orderId' => (string) $operation['external_order_ref'], 'requestedAt' => CancellationView::iso((string) $operation['created_at'])];
            });
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return ['ok' => true, 'sourceKey' => $sourceKey, 'enabled' => true, 'operations' => $items];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function report(string $sourceKey, array $input, string $requestId): array
    {
        if (array_diff(array_keys($input), ['operationId', 'outcome', 'sourceStatus']) !== [] || !is_string($input['operationId'] ?? null) || preg_match('/^[0-9a-f-]{36}$/D', $input['operationId']) !== 1
            || !in_array($input['outcome'] ?? null, self::REPORT_OUTCOMES, true) || (isset($input['sourceStatus']) && (!is_string($input['sourceStatus']) || preg_match('/^[a-z0-9_-]{1,40}$/D', $input['sourceStatus']) !== 1))) {
            throw new ApiException(422, 'SOURCE_PAYLOAD_INVALID', 'Cancellation result payload is invalid.');
        }
        $outcome = (string) $input['outcome'];
        $status = isset($input['sourceStatus']) ? (string) $input['sourceStatus'] : null;
        return $this->transaction(function () use ($sourceKey, $input, $outcome, $status, $requestId): array {
            $operations = new SyncOperations($this->pdo, $this->clock);
            $hint = $operations->byId((string) $input['operationId'], false);
            // Another source's operation is reported exactly like a missing one: no cross-site identity is ever revealed.
            if ($hint === null || $hint['source_key'] !== $sourceKey || $hint['mechanism'] !== 'source_pull') {
                throw new ApiException(404, 'CANCELLATION_SYNC_NOT_FOUND', 'Operation not found.');
            }
            $this->lockOrder((string) $hint['order_uuid']);
            $operation = $operations->byId((string) $input['operationId']) ?? throw new ApiException(404, 'CANCELLATION_SYNC_NOT_FOUND', 'Operation not found.');
            [$target, $error] = match ($outcome) {
                'cancelled', 'already_cancelled' => [SyncState::AWAITING_CONFIRMATION, null],
                'rejected_paid' => [SyncState::MANUAL_REVIEW, 'PAID_ORDER_REQUIRES_PERSON'],
                'rejected_status' => [SyncState::REJECTED, 'SOURCE_STATUS_NOT_CANCELLABLE'],
                default => [SyncState::FAILED, 'SOURCE_ORDER_NOT_FOUND'],
            };
            $system = 'source:' . $sourceKey;
            if ($operation['state'] === SyncState::AWAITING_PICKUP) {
                $operation = $operations->transition($operation, $target, ['provider_result' => $outcome, 'provider_result_at' => $operations->now(), 'provider_status' => $status]
                    + ($target === SyncState::AWAITING_CONFIRMATION ? ['dispatched_at' => $operations->now()] : []), ['kind' => 'report', 'outcome' => $outcome, 'error' => $error, 'providerStatus' => $status, 'system' => $system, 'correlation' => $requestId]);
            } elseif ($operation['provider_result'] === $outcome || ($operation['state'] === SyncState::CONFIRMED && in_array($outcome, ['cancelled', 'already_cancelled'], true))) {
                // The same report again (a lost answer): nothing changes.
                $operations->note($operation, 'report', 'duplicate_report', null, null, $system, $requestId, $status);
            } else {
                $operations->note($operation, 'report', 'conflicting_report:' . $outcome, 'CANCELLATION_SYNC_CONFLICT', null, $system, $requestId, $status);
                throw new ApiException(409, 'CANCELLATION_SYNC_CONFLICT', 'The operation is no longer waiting for this result.', ['state' => $operation['state']]);
            }
            return ['ok' => true, 'operationId' => (string) $operation['operation_uuid'], 'state' => (string) $operation['state']];
        });
    }

    /** The heartbeat's cancellationSyncMode for a source connector: `enabled` only while both gates are open. */
    public function sourceMode(string $sourceKey): string
    {
        try {
            return SyncSchema::ready($this->pdo) && $this->providers->for($sourceKey)->mechanism() === 'source_pull' && $this->providers->active($sourceKey) ? 'enabled' : 'disabled';
        } catch (PDOException) {
            return 'disabled';
        }
    }

    // ------------------------------------------------------------------ internals

    private function beforeTrendyolBaseline(string $orderUuid): bool
    {
        $statement = $this->pdo->prepare("SELECT tp.order_date_ms, s.baseline_at FROM trendyol_packages tp CROSS JOIN trendyol_intake_state s
            WHERE tp.released_order_uuid = ? AND tp.intake_status = 'released' AND s.state_id = 1");
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['baseline_at'] === null) {
            // No released package (then there is no external reference either) or no baseline yet: nothing to compare.
            return false;
        }
        $baseline = (int) round((float) (new \DateTimeImmutable((string) $row['baseline_at'], new \DateTimeZone('UTC')))->format('U.u') * 1000);
        return (int) $row['order_date_ms'] < $baseline;
    }

    /** @param array<string, mixed> $operation */
    private function recheckable(array $operation): bool
    {
        return $operation['state'] === SyncState::MANUAL_REVIEW && $operation['mechanism'] === 'api'
            && (new SyncOperations($this->pdo, $this->clock))->possiblyDelivered((string) $operation['operation_uuid']);
    }

    /**
     * The person's statement of their own check in the channel: {checkedAt: ISO 8601 with offset, note: 10-1000 characters}.
     * checkedAt is at most PROVIDER_CHECK_MAX_AGE_SECONDS old and not in the future (two minutes of clock skew).
     * @return array{checkedAt: string, note: string}
     */
    private function providerCheck(mixed $value, SyncOperations $operations): array
    {
        if ($value === null) {
            throw new ApiException(409, 'CANCELLATION_SYNC_PROVIDER_CHECK_REQUIRED', 'Rezultatul trimiterii anterioare este necunoscut. Verifică întâi comanda în platforma canalului și descrie ce ai văzut.');
        }
        if (!is_array($value) || array_diff(array_keys($value), ['checkedAt', 'note']) !== [] || !is_string($value['checkedAt'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/D', $value['checkedAt']) !== 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'providerCheck needs checkedAt (ISO 8601 with offset) and note.', ['field' => 'providerCheck']);
        }
        $note = self::text($value['note'] ?? null, 10, 'providerCheck.note');
        try {
            $checkedAt = (new \DateTimeImmutable($value['checkedAt']))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'providerCheck.checkedAt is not a valid time.', ['field' => 'providerCheck.checkedAt']);
        }
        $now = $this->clock->now()->getTimestamp();
        if ($checkedAt->getTimestamp() > $now + 120 || $checkedAt->getTimestamp() < $now - SyncOperations::PROVIDER_CHECK_MAX_AGE_SECONDS) {
            throw new ApiException(409, 'CANCELLATION_SYNC_PROVIDER_CHECK_STALE', 'Verificarea în canal trebuie făcută cu cel mult 30 de minute înainte de reîncercare.', ['field' => 'providerCheck.checkedAt']);
        }
        return ['checkedAt' => $checkedAt->format('Y-m-d\TH:i:s\Z'), 'note' => $note];
    }

    /** @return array<string, mixed>|null */
    private static function evidenceView(string $json): ?array
    {
        $evidence = json_decode($json, true);
        if (!is_array($evidence)) {
            return null;
        }
        // The attestation is shown as what it is: a person's statement. The employee is already the attempt's actor.
        unset($evidence['attestation']['employeeId']);
        return $evidence;
    }

    /** @param array<string, mixed> $operation */
    public static function withdrawable(array $operation): bool
    {
        return in_array($operation['state'], [SyncState::QUEUED, SyncState::RETRY_SCHEDULED], true)
            || ($operation['state'] === SyncState::AWAITING_PICKUP && $operation['last_pulled_at'] === null);
    }

    /** The provider's identity of the order: the Trendyol package or the shop's own order id. @param array<string, mixed> $order */
    private function externalRef(array $order): ?string
    {
        if ($order['source_key'] === 'trendyol') {
            $statement = $this->pdo->prepare("SELECT package_id FROM trendyol_packages WHERE released_order_uuid = ? AND intake_status = 'released'");
            $statement->execute([$order['order_uuid']]);
            $package = $statement->fetchColumn();
            return $package === false ? null : (string) $package;
        }
        if ($order['source_key'] === 'b2b') {
            return null;
        }
        return (string) $order['source_order_id'];
    }

    /**
     * @param callable(EmployeeIdentity, array<string, mixed>, array<string, mixed>, SyncOperations): array<string, mixed> $write
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function operationCommand(EmployeeIdentity $actor, string $action, string $operationId, array $input, string $key, string $requestId, callable $write): array
    {
        $this->authorization->require($actor, self::PERMISSION);
        IdempotencyStore::requireKey($key);
        if (preg_match('/^[0-9a-f-]{36}$/D', $operationId) !== 1) {
            throw new ApiException(404, 'CANCELLATION_SYNC_NOT_FOUND', 'Operația nu a fost găsită.');
        }
        $expected = self::version($input['expectedVersion'] ?? null);
        $hash = IdempotencyStore::hash('cancellation.sync.' . $action, $operationId, $input);
        return $this->transactionWithRetry(function () use ($actor, $action, $operationId, $expected, $key, $hash, $requestId, $write): array {
            $operations = new SyncOperations($this->pdo, $this->clock);
            $hint = $operations->byId($operationId, false) ?? throw new ApiException(404, 'CANCELLATION_SYNC_NOT_FOUND', 'Operația nu a fost găsită.');
            $order = $this->lockOrder((string) $hint['order_uuid']);
            $fresh = $this->lockActor($actor);
            $replay = $this->idempotency->replay($fresh->employeeUuid, $key, 'cancellation.sync.' . $action, $operationId, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $operation = $operations->byId($operationId) ?? throw new ApiException(404, 'CANCELLATION_SYNC_NOT_FOUND', 'Operația nu a fost găsită.');
            if ((int) $operation['version'] !== $expected) {
                throw new ApiException(409, 'CANCELLATION_SYNC_CHANGED', 'Operația de sincronizare s-a schimbat. Reîncarcă.');
            }
            $operation = $write($fresh, $order, $operation, $operations);
            // The audit names the recorded outcome and, for a retry after a possible delivery, the evidence it rested on.
            $last = $this->pdo->prepare('SELECT outcome, evidence_json FROM order_cancellation_sync_attempts WHERE operation_uuid = ? ORDER BY attempt_seq DESC LIMIT 1');
            $last->execute([$operationId]);
            $recorded = $last->fetch(PDO::FETCH_ASSOC) ?: [];
            $this->audit($fresh, 'production.cancellation.sync_' . $action, $order, ['operation' => $operationId, 'state' => $operation['state'], 'outcome' => $recorded['outcome'] ?? null]
                + (isset($recorded['evidence_json']) ? ['evidence' => json_decode((string) $recorded['evidence_json'], true)] : []), $requestId);
            $response = $this->operationView($operation);
            $this->idempotency->store($fresh->employeeUuid, $key, 'cancellation.sync.' . $action, $operationId, $hash, $response, $operations->now());
            return $response;
        });
    }

    /**
     * @param callable(EmployeeIdentity, array<string, mixed>): array<string, mixed> $write
     * @return array<string, mixed>
     */
    private function command(EmployeeIdentity $actor, string $operation, string $globalId, string $key, string $hash, callable $write): array
    {
        return $this->transactionWithRetry(function () use ($actor, $operation, $globalId, $key, $hash, $write): array {
            $statement = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?');
            $statement->execute([$globalId]);
            $uuid = $statement->fetchColumn();
            if (!is_string($uuid)) {
                throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
            }
            $order = $this->lockOrder($uuid);
            $fresh = $this->lockActor($actor);
            $replay = $this->idempotency->replay($fresh->employeeUuid, $key, $operation, $globalId, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $response = $write($fresh, $order);
            $this->idempotency->store($fresh->employeeUuid, $key, $operation, $globalId, $hash, $response, (new SyncOperations($this->pdo, $this->clock))->now());
            return $response;
        });
    }

    private function lockActor(EmployeeIdentity $actor): EmployeeIdentity
    {
        $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$actor->employeeUuid]);
        $fresh = (new PdoEmployeeRepository($this->pdo))->findByUuid($actor->employeeUuid);
        if ($fresh === null || !$fresh->isOperationallyActive() || $fresh->mustChangePassword) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Contul nu mai este autorizat.');
        }
        $this->authorization->require($fresh, self::PERMISSION);
        return $fresh;
    }

    /** @return array<string, mixed> the locked canonical order row */
    private function lockOrder(string $orderUuid): array
    {
        $statement = $this->pdo->prepare('SELECT order_uuid, global_order_id, order_number, source_key, source_order_id, operational_status, production_stage_id, production_completed_at, cancellation_state,
                source_financial_attention FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
    }

    /**
     * Owner rule 2: an open finance entry of the order (a bank transfer or a refund recorded and not yet verified or
     * rejected in the shop, a finance action Arasya relayed whose answer is pending or unknown, or a money state the shop
     * could not reconcile) blocks the cancellation request to the shop. An unverified transfer is not money received,
     * but cancelling the order while it is open could leave real money on a cancelled order. Caller holds the order row
     * lock (the finance relay takes the same lock before it records an action).
     * @param array<string, mixed> $order
     */
    private function financeOpen(array $order): bool
    {
        return ConfirmareFinance::actionOpen($this->pdo, (string) $order['order_uuid'])
            || in_array($order['source_financial_attention'] ?? null, self::FINANCE_OPEN_ATTENTIONS, true);
    }

    /** @param array<string, mixed> $order */
    private function assertFinanceClosed(array $order): void
    {
        if ($this->financeOpen($order)) {
            throw new ApiException(409, 'CANCELLATION_SYNC_FINANCE_OPEN', 'O operațiune financiară a comenzii este deschisă (un transfer sau o rambursare neverificată, ori un răspuns necunoscut). Cererea de anulare către magazin se trimite după verificarea, respingerea sau reconcilierea ei.');
        }
    }

    /** @return array<string, mixed>|null */
    private function control(string $orderUuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM order_cancellation_controls WHERE order_uuid = ? FOR UPDATE');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $metadata */
    private function audit(EmployeeIdentity $actor, string $action, array $order, array $metadata, string $requestId): void
    {
        (new IamAuditLogger($this->pdo))->record($actor, $action, 'order', (string) $order['global_order_id'], '#' . $order['order_number'], ['order' => $order['global_order_id']] + $metadata, $requestId, (new SyncOperations($this->pdo, $this->clock))->now());
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

    /** @template T @param callable(): T $operation @return T */
    private function transactionWithRetry(callable $operation): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->transaction($operation);
            } catch (PDOException $error) {
                if ($attempt < self::MAX_ATTEMPTS && in_array((int) ($error->errorInfo[1] ?? 0), self::RETRYABLE, true)) {
                    continue;
                }
                throw $error;
            }
        }
    }

    private static function globalId(string $value): string
    {
        try {
            return GlobalOrderId::fromString($value)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
        }
    }

    private static function version(mixed $value): int
    {
        return is_int($value) && $value >= 1 ? $value : throw new ApiException(400, 'INVALID_REQUEST', 'expectedVersion must be a positive integer.');
    }

    private static function text(mixed $value, int $min, string $field): string
    {
        if (!is_string($value) || mb_strlen(trim($value)) < $min || mb_strlen(trim($value)) > 1000) {
            throw new ApiException(422, 'VALIDATION_FAILED', "{$field} must have between {$min} and 1000 characters.", ['field' => $field]);
        }
        return trim($value);
    }

    private static function optionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return self::text($value, 1, 'note');
    }
}
