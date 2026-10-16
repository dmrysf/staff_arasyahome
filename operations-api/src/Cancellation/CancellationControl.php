<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Cancellation control state machine (migration 033). Every method runs inside the caller's transaction, after the
 * caller locked the canonical order row, and writes the control row, its projection on the order row
 * (cancellation_state, production_stopped_at) and the append-only events together, or nothing.
 *
 *   inbound cancellation (signed source or marketplace sync)      -> held (origin source), even when production already
 *                                                                    started or finished; nothing is moved or deleted
 *   inbound cancellation while held                               -> evidence updated; a local request becomes confirmed
 *   inbound cancellation while released                           -> held again (a new stop)
 *   inbound "active" while held after a source cancellation       -> source_status reactivated; the stop stays
 *   inbound "active" for a legacy unavailable order (pre-033)     -> held (legacy_source_cancellation, reactivated)
 *   local stop (production.cancellation.manage)                   -> held (origin local), optional pending source request
 *   physical reconciliation (production.cancellation.manage)      -> physical_status verified, with evidence
 *   release (production.cancellation.release)                     -> released, only when every prerequisite holds
 *
 * Nothing here ever changes a stage, an owner, an exception, a track, a piece, a document or a QR, and no outbound
 * request is made: a pending source request is only recorded (Phase 2 may send it through a reviewed integration).
 */
final readonly class CancellationControl
{
    public const HELD = 'held';
    public const RELEASED = 'released';
    public const PHYSICAL_OUTCOMES = ['secured', 'nothing_produced', 'left_factory'];
    /** Stages where an order may sit without any physical manufacturing having started. */
    private const PRE_PRODUCTION_STAGES = ['waiting', 'material-preparation'];
    private const ORDER_COLUMNS = 'order_uuid, global_order_id, source_key, operational_status, production_stage_id, production_owner_employee_uuid,
        open_exception_uuid, production_version, production_completed_at, cutting_first_claimed_at, document_status, cancellation_state, production_stopped_at';

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /**
     * Inbound availability reported by a source. $wasUnavailable is the order's stored availability before this event.
     * @param array{reason: string, statusCode?: ?string, statusLabel?: ?string, eventRef?: ?string, sourceChangedAt?: ?string, previousReportedAt?: ?string} $evidence
     *   previousReportedAt: the stored source_reported_unavailable_at before this event (legacy cancellations);
     *   operator / note: the person and approval text of an operator CLI stop of a legacy cancellation
     */
    public function onSourceAvailability(string $orderUuid, bool $cancelled, bool $wasUnavailable, array $evidence): void
    {
        $this->applySourceAvailability($orderUuid, $cancelled, $wasUnavailable, $evidence);
        if ($cancelled) {
            // API 2.36.0: the provider's own cancellation is the confirmation of an outbound request of this stop.
            $this->confirmSync($orderUuid, isset($evidence['eventRef']) ? (string) $evidence['eventRef'] : null);
        }
    }

    /**
     * Marks a commercial cancellation at the source as requested (API 2.36.0, sending it through a provider). Nothing is
     * sent here; the caller creates the outbound operation in the same transaction.
     */
    public function requestSource(string $orderUuid, string $actorUuid, string $requestId, string $key, string $mechanism): void
    {
        $order = $this->order($orderUuid);
        $control = $order['cancellation_state'] === null ? null : $this->control($orderUuid);
        if ($control === null || $control['hold_status'] !== self::HELD) {
            throw new ApiException(409, 'CANCELLATION_NOT_HELD', 'Producția acestei comenzi nu este oprită.');
        }
        if ($control['source_request_status'] === 'pending') {
            return;
        }
        $now = $this->now();
        $this->update($order, $control, ['source_request_status' => 'pending', 'source_requested_at' => $now, 'source_requested_by_employee_uuid' => $actorUuid], $now);
        $this->event($order, (int) $control['version'] + 1, 'source_cancellation_requested', $actorUuid, null, ['outbound' => $mechanism], $requestId, $key, $now);
    }

    /** @param array<string, mixed> $evidence */
    private function applySourceAvailability(string $orderUuid, bool $cancelled, bool $wasUnavailable, array $evidence): void
    {
        $order = $this->order($orderUuid);
        $control = $order['cancellation_state'] === null ? null : $this->control($orderUuid);
        $now = $this->now();
        $ref = isset($evidence['eventRef']) ? mb_substr((string) $evidence['eventRef'], 0, 191) : null;
        if ($cancelled) {
            if ($control === null || $control['hold_status'] === self::RELEASED) {
                $this->event($order, null, 'source_cancellation_received', null, $ref, array_filter(['statusCode' => $evidence['statusCode'] ?? null,
                    'operator' => $evidence['operator'] ?? null, 'reportedAt' => $evidence['reason'] === 'legacy_source_cancellation' ? ($evidence['previousReportedAt'] ?? null) : null],
                    static fn (mixed $value): bool => $value !== null), null, null, $now);
                $this->hold($order, $control, 'source', $evidence['reason'], $evidence['note'] ?? null, null, 'cancelled', 'not_requested', $evidence, $now);
                return;
            }
            $changes = [];
            $events = [];
            if ($control['source_status'] !== 'cancelled') {
                $changes['source_status'] = 'cancelled';
                $events[] = ['source_evidence_updated', ['sourceStatus' => ['before' => $control['source_status'], 'after' => 'cancelled']]];
            }
            if ($control['source_request_status'] === 'pending') {
                $changes['source_request_status'] = 'confirmed';
                $events[] = ['source_cancellation_confirmed', ['sourceRequest' => ['before' => 'pending', 'after' => 'confirmed']]];
            }
            if ($control['source_cancellation_received_at'] === null) {
                $changes['source_cancellation_received_at'] = $now;
            }
            if (($evidence['statusCode'] ?? null) !== $control['source_status_code'] && $events === []) {
                $events[] = ['source_evidence_updated', ['statusCode' => ['before' => $control['source_status_code'], 'after' => $evidence['statusCode'] ?? null]]];
            }
            if ($events === []) {
                // The same cancellation again (a new source event with an unchanged meaning): no new stop, no audit row.
                return;
            }
            $this->update($order, $control, $changes + $this->evidence($evidence, $ref, $now), $now);
            foreach ($events as [$type, $details]) {
                $this->event($order, (int) $control['version'] + 1, $type, null, $ref, $details, null, null, $now);
            }
            return;
        }
        if ($control === null) {
            if ($wasUnavailable) {
                // Cancelled before migration 033 (no control row) and now active again at the source: the source never
                // releases production, so the cancellation becomes an explicit stop that a person must release.
                $this->hold($order, null, 'source', 'legacy_source_cancellation', null, null, 'reactivated', 'not_requested', $evidence, $now);
                $this->event($order, 1, 'source_reactivated', null, $ref, ['legacy' => true, 'statusCode' => $evidence['statusCode'] ?? null], null, null, $now);
            }
            return;
        }
        if ($control['hold_status'] === self::HELD && $control['source_status'] === 'cancelled') {
            $this->update($order, $control, ['source_status' => 'reactivated'] + $this->evidence($evidence, $ref, $now), $now);
            $this->event($order, (int) $control['version'] + 1, 'source_reactivated', null, $ref, ['statusCode' => $evidence['statusCode'] ?? null], null, null, $now);
        }
    }

    /**
     * A person stops production inside Arasya. Stopping is always the safe direction: it needs no expected version.
     * @return array{applied: bool} applied=false when the order was already stopped (recorded as duplicate_ignored)
     */
    public function stopLocally(string $orderUuid, string $actorUuid, string $reason, bool $requestSource, string $requestId, string $key): array
    {
        $order = $this->order($orderUuid);
        $control = $order['cancellation_state'] === null ? null : $this->control($orderUuid);
        $now = $this->now();
        if ($control !== null && $control['hold_status'] === self::HELD) {
            if ($requestSource && $control['source_request_status'] === 'not_requested' && $control['source_status'] !== 'cancelled') {
                $this->update($order, $control, ['source_request_status' => 'pending', 'source_requested_at' => $now, 'source_requested_by_employee_uuid' => $actorUuid], $now);
                $this->event($order, (int) $control['version'] + 1, 'source_cancellation_requested', $actorUuid, null, ['outbound' => 'not_sent'], $requestId, $key, $now);
                return ['applied' => true];
            }
            $this->event($order, (int) $control['version'], 'duplicate_ignored', $actorUuid, null, ['request' => 'local_stop'], $requestId, $key, $now);
            return ['applied' => false];
        }
        $sourceCancelled = $order['operational_status'] === 'unavailable';
        $this->hold($order, $control, 'local', 'manager_stop', $reason, $actorUuid, $sourceCancelled ? 'cancelled' : 'not_reported',
            $requestSource && !$sourceCancelled ? 'pending' : 'not_requested', ['requestId' => $requestId, 'key' => $key], $now);
        if ($requestSource && !$sourceCancelled) {
            $this->event($order, $this->versionOf($orderUuid), 'source_cancellation_requested', $actorUuid, null, ['outbound' => 'not_sent'], $requestId, $key, $now);
        }
        return ['applied' => true];
    }

    /**
     * Physical reconciliation of a stopped order: who verified, what was found and the scanned code. Never moves
     * production and never marks a piece repaired, replaced or recovered.
     */
    public function recordPhysical(string $orderUuid, string $actorUuid, int $expectedVersion, string $outcome, string $note, ?string $scan, string $requestId, string $key): void
    {
        $order = $this->order($orderUuid);
        $control = $order['cancellation_state'] === null ? null : $this->control($orderUuid);
        if ($control === null || $control['hold_status'] !== self::HELD) {
            throw new ApiException(409, 'CANCELLATION_NOT_HELD', 'Producția acestei comenzi nu este oprită.');
        }
        if ((int) $control['version'] !== $expectedVersion) {
            throw new ApiException(409, 'CANCELLATION_CHANGED', 'Starea opririi s-a schimbat. Reîncarcă înainte de a continua.');
        }
        if (!in_array($outcome, self::PHYSICAL_OUTCOMES, true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'outcome must be secured, nothing_produced or left_factory.');
        }
        if ($outcome === 'nothing_produced' && $control['production_phase'] === 'completed') {
            throw new ApiException(422, 'PHYSICAL_OUTCOME_INVALID', 'Producția a fost finalizată: nu poți declara că nu s-a produs nimic.');
        }
        if ($outcome === 'left_factory' && $control['production_phase'] !== 'completed' && !in_array($order['production_stage_id'], ['packing', 'delivery'], true)) {
            throw new ApiException(422, 'PHYSICAL_OUTCOME_INVALID', 'Doar o comandă ambalată sau finalizată poate fi declarată plecată din fabrică.');
        }
        if ($outcome === 'secured' && $scan === null) {
            throw new ApiException(422, 'QR_REQUIRED', 'Scanează codul QR al comenzii sau eticheta unei bucăți.');
        }
        $now = $this->now();
        $before = ['status' => $control['physical_status'], 'outcome' => $control['physical_outcome']];
        $this->update($order, $control, [
            'physical_status' => 'verified', 'physical_outcome' => $outcome, 'physical_note' => $note, 'physical_scan' => $scan,
            'physical_verified_by_employee_uuid' => $actorUuid, 'physical_verified_at' => $now,
        ], $now);
        $this->event($order, (int) $control['version'] + 1, 'physical_reconciliation_recorded', $actorUuid, null, ['before' => $before, 'after' => ['status' => 'verified', 'outcome' => $outcome], 'scan' => $scan], $requestId, $key, $now);
    }

    /**
     * Why a release is refused right now, or null when every prerequisite holds. The order row is locked.
     * @param array<string, mixed> $order @param array<string, mixed> $control
     */
    public static function releaseRefusal(array $order, array $control, bool $syncOpen = false): ?string
    {
        return match (true) {
            $control['hold_status'] !== self::HELD => 'not_held',
            // API 2.36.0: while a cancellation request may still reach (or be acted on by) the sales channel, production
            // never resumes; the operation must end first (confirmed, rejected, withdrawn or closed by a person).
            $syncOpen => 'sync_open',
            $control['production_phase'] === 'completed' || $order['production_completed_at'] !== null => 'production_completed',
            $control['physical_outcome'] === 'left_factory' => 'left_factory',
            $order['operational_status'] === 'unavailable' || $control['source_status'] === 'cancelled' => 'source_cancelled',
            $control['physical_status'] === 'pending' => 'physical_unverified',
            \Arasya\Operations\Document\DocumentGuard::isBlocked((string) $order['document_status']) => 'document_not_ready',
            default => null,
        };
    }

    /** @return array<string, mixed> the facts that stay in force after the release (shown to the person) */
    public function release(string $orderUuid, string $actorUuid, int $expectedVersion, string $reason, string $requestId, string $key): array
    {
        $order = $this->order($orderUuid);
        $control = $order['cancellation_state'] === null ? null : $this->control($orderUuid);
        if ($control === null) {
            throw new ApiException(409, 'CANCELLATION_NOT_HELD', 'Producția acestei comenzi nu este oprită.');
        }
        if ((int) $control['version'] !== $expectedVersion) {
            throw new ApiException(409, 'CANCELLATION_CHANGED', 'Starea opririi s-a schimbat. Reîncarcă înainte de a continua.');
        }
        $refusal = self::releaseRefusal($order, $control, $this->syncOpen($orderUuid));
        if ($refusal !== null) {
            throw self::refused($refusal);
        }
        $now = $this->now();
        $remaining = [
            'openException' => $order['open_exception_uuid'] !== null,
            'activeTracks' => (int) $this->scalar("SELECT COUNT(*) FROM production_tracks WHERE order_uuid = ? AND status = 'active'", [$orderUuid]),
        ];
        $lift = $this->pdo->prepare("UPDATE operational_orders SET cancellation_state = 'released', production_stopped_at = NULL, production_version = production_version + 1, version = version + 1, updated_at = ?
            WHERE order_uuid = ? AND cancellation_state = 'held'");
        $lift->execute([$now, $orderUuid]);
        if ($lift->rowCount() !== 1) {
            throw new ApiException(409, 'CANCELLATION_CHANGED', 'Starea opririi s-a schimbat. Reîncarcă înainte de a continua.');
        }
        $this->writeControl($orderUuid, [
            'hold_status' => self::RELEASED, 'released_at' => $now, 'released_by_employee_uuid' => $actorUuid, 'release_reason' => $reason,
            'source_request_status' => $control['source_request_status'] === 'pending' ? 'withdrawn' : $control['source_request_status'],
        ], (int) $control['version'], $now);
        $this->event($this->order($orderUuid), (int) $control['version'] + 1, 'release_approved', $actorUuid, null, [
            'before' => ['hold' => self::HELD, 'stage' => $order['production_stage_id'], 'productionVersion' => (int) $order['production_version']],
            'after' => ['hold' => self::RELEASED, 'stage' => $order['production_stage_id'], 'productionVersion' => (int) $order['production_version'] + 1],
            'evidence' => ['source' => $control['source_status'], 'physical' => $control['physical_outcome'] ?? $control['physical_status'], 'document' => $order['document_status']],
            'remaining' => $remaining,
        ], $requestId, $key, $now);
        return $remaining;
    }

    /** A refused release attempt, recorded after the refused transaction rolled back. Caller holds the order lock. */
    public function recordRefusal(string $orderUuid, string $actorUuid, string $reason, string $requestId, string $key): void
    {
        $order = $this->order($orderUuid);
        $control = $order['cancellation_state'] === null ? null : $this->control($orderUuid);
        if ($control !== null) {
            $this->event($order, (int) $control['version'], 'release_refused', $actorUuid, null, ['reason' => $reason], $requestId, $key, $this->now());
        }
    }

    public static function refused(string $reason): ApiException
    {
        return match ($reason) {
            'not_held' => new ApiException(409, 'CANCELLATION_NOT_HELD', 'Producția acestei comenzi nu este oprită.'),
            'production_completed' => new ApiException(409, 'RELEASE_NOT_APPLICABLE', 'Producția a fost deja finalizată; comanda nu se redeschide. Este necesară doar decizia fizică.'),
            'left_factory' => new ApiException(409, 'RELEASE_NOT_APPLICABLE', 'Produsul a plecat din fabrică; producția nu poate fi reluată.'),
            'source_cancelled' => new ApiException(409, 'RELEASE_SOURCE_CANCELLED', 'Sursa raportează în continuare comanda ca anulată. Producția nu poate fi reluată.'),
            'physical_unverified' => new ApiException(409, 'RELEASE_PHYSICAL_UNVERIFIED', 'Verifică și înregistrează mai întâi starea fizică a produsului.'),
            'sync_open' => new ApiException(409, 'RELEASE_SYNC_OPEN', 'O cerere de anulare către sursă este încă deschisă. Așteaptă rezultatul sau închide-o înainte de reluare.'),
            'document_not_ready' => new ApiException(409, 'RELEASE_DOCUMENT_NOT_READY', 'Documentul de producție nu este activ. Finalizează revizia înainte de reluare.'),
            default => new ApiException(409, 'RELEASE_REFUSED', 'Reluarea producției nu este posibilă acum.'),
        };
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param array<string, mixed> $order @param array<string, mixed>|null $control an existing released row, or null
     * @param array<string, mixed> $evidence
     */
    private function hold(array $order, ?array $control, string $origin, string $reason, ?string $note, ?string $actorUuid, string $sourceStatus, string $request, array $evidence, string $now): void
    {
        $orderUuid = (string) $order['order_uuid'];
        $tracks = $this->pdo->prepare("SELECT track_uuid, track_number, stage_id, owner_employee_uuid, open_exception_uuid FROM production_tracks WHERE order_uuid = ? AND status = 'active' ORDER BY track_number FOR UPDATE");
        $tracks->execute([$orderUuid]);
        $active = array_map(static fn (array $t): array => ['trackId' => (string) $t['track_uuid'], 'number' => (int) $t['track_number'], 'stageId' => (string) $t['stage_id'],
            'ownerId' => $t['owner_employee_uuid'], 'exceptionId' => $t['open_exception_uuid']], $tracks->fetchAll(PDO::FETCH_ASSOC));
        $completed = $order['production_completed_at'] !== null;
        $notStarted = !$completed && $order['cutting_first_claimed_at'] === null && $active === [] && in_array($order['production_stage_id'], self::PRE_PRODUCTION_STAGES, true);
        $phase = $completed ? 'completed' : ($notStarted ? 'not_started' : 'in_progress');
        $physical = $notStarted ? 'not_required' : 'pending';
        // The production version is not changed by a stop (nothing moved); a release changes it, so a request prepared
        // before the stop can never apply after the release.
        $update = $this->pdo->prepare("UPDATE operational_orders SET cancellation_state = 'held', production_stopped_at = ?, version = version + 1, updated_at = ?
            WHERE order_uuid = ? AND (cancellation_state IS NULL OR cancellation_state = 'released')");
        $update->execute([$now, $now, $orderUuid]);
        if ($update->rowCount() !== 1) {
            throw new \RuntimeException('The production stop could not be applied.');
        }
        $source = $origin === 'source';
        $row = [
            'source_key' => $order['source_key'], 'hold_status' => self::HELD, 'origin' => $origin, 'reason_code' => $reason, 'reason_note' => $note,
            'source_status' => $sourceStatus,
            'source_status_code' => $source ? self::cut($evidence['statusCode'] ?? null, 100) : null,
            'source_status_label' => $source ? self::cut($evidence['statusLabel'] ?? null, 255) : null,
            'source_event_ref' => $source ? self::cut($evidence['eventRef'] ?? null, 191) : null,
            'source_changed_at' => $source ? ($evidence['sourceChangedAt'] ?? null) : null,
            // When Arasya received the source's cancellation: now for an inbound one, the stored report for a local stop of
            // an order the source had already cancelled. Never earlier than Arasya actually knew.
            'source_cancellation_received_at' => $sourceStatus !== 'cancelled' ? null : ($source ? $now : ($order['source_reported_unavailable_at'] ?? null)),
            'source_evidence_at' => $source ? $now : null,
            'source_request_status' => $request,
            'source_requested_at' => $request === 'pending' ? $now : null,
            'source_requested_by_employee_uuid' => $request === 'pending' ? $actorUuid : null,
            'production_phase' => $phase,
            'stage_id_at_hold' => $order['production_stage_id'], 'owner_employee_uuid_at_hold' => $order['production_owner_employee_uuid'],
            'exception_uuid_at_hold' => $order['open_exception_uuid'], 'production_version_at_hold' => (int) $order['production_version'],
            'production_completed_at_hold' => $order['production_completed_at'], 'cutting_started_at_hold' => $order['cutting_first_claimed_at'],
            'active_tracks_at_hold' => $active === [] ? null : json_encode($active, JSON_THROW_ON_ERROR),
            'physical_status' => $physical, 'physical_outcome' => null, 'physical_note' => null, 'physical_scan' => null,
            'physical_verified_by_employee_uuid' => null, 'physical_verified_at' => null,
            'held_at' => $now, 'held_by_employee_uuid' => $actorUuid,
            'released_at' => null, 'released_by_employee_uuid' => null, 'release_reason' => null,
        ];
        if ($reason === 'legacy_source_cancellation') {
            // The legacy cancellation time is the stored source report, never "now".
            $row['source_cancellation_received_at'] = $evidence['previousReportedAt'] ?? $order['source_reported_unavailable_at'] ?? null;
        }
        if ($control === null) {
            $columns = array_keys($row);
            $this->pdo->prepare('INSERT INTO order_cancellation_controls (order_uuid, ' . implode(', ', $columns) . ', version, created_at, updated_at) VALUES (?' . str_repeat(', ?', count($columns)) . ', 1, ?, ?)')
                ->execute([$orderUuid, ...array_values($row), $now, $now]);
            $version = 1;
        } else {
            // A stop after a release is a new generation: an outbound operation of the earlier stop never applies to it.
            if (array_key_exists('hold_generation', $control)) {
                $row['hold_generation'] = (int) $control['hold_generation'] + 1;
            }
            $this->writeControl($orderUuid, $row, (int) $control['version'], $now);
            $version = (int) $control['version'] + 1;
        }
        $requestId = $evidence['requestId'] ?? null;
        $key = $evidence['key'] ?? null;
        $this->event($this->order($orderUuid), $version, 'hold_applied', $actorUuid, null, [
            'origin' => $origin, 'reason' => $reason, 'phase' => $phase, 'stage' => $order['production_stage_id'], 'owner' => $order['production_owner_employee_uuid'],
            'exception' => $order['open_exception_uuid'], 'productionVersion' => (int) $order['production_version'], 'tracks' => $active,
            'completedAt' => $order['production_completed_at'], 'cuttingStartedAt' => $order['cutting_first_claimed_at'],
        ], $requestId, $key, $now);
        if ($physical === 'pending') {
            $this->event($this->order($orderUuid), $version, 'physical_reconciliation_requested', null, null, ['phase' => $phase, 'stage' => $order['production_stage_id']], $requestId, $key, $now);
        }
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    private function evidence(array $evidence, ?string $ref, string $now): array
    {
        return [
            'source_status_code' => self::cut($evidence['statusCode'] ?? null, 100),
            'source_status_label' => self::cut($evidence['statusLabel'] ?? null, 255),
            'source_event_ref' => $ref,
            'source_changed_at' => $evidence['sourceChangedAt'] ?? null,
            'source_evidence_at' => $now,
        ];
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $control @param array<string, mixed> $changes */
    private function update(array $order, array $control, array $changes, string $now): void
    {
        $this->writeControl((string) $order['order_uuid'], $changes, (int) $control['version'], $now);
        // The order version tells every open screen to reload; the production version is unchanged (nothing moved).
        $this->pdo->prepare('UPDATE operational_orders SET version = version + 1, updated_at = ? WHERE order_uuid = ?')->execute([$now, $order['order_uuid']]);
    }

    /** @param array<string, mixed> $changes */
    private function writeControl(string $orderUuid, array $changes, int $expectedVersion, string $now): void
    {
        $sets = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($changes)));
        $statement = $this->pdo->prepare("UPDATE order_cancellation_controls SET {$sets}, version = version + 1, updated_at = ? WHERE order_uuid = ? AND version = ?");
        $statement->execute([...array_values($changes), $now, $orderUuid, $expectedVersion]);
        if ($statement->rowCount() !== 1) {
            throw new ApiException(409, 'CANCELLATION_CHANGED', 'Starea opririi s-a schimbat. Reîncarcă înainte de a continua.');
        }
    }

    /** @param array<string, mixed> $order @param array<string, mixed>|null $details */
    private function event(array $order, ?int $controlVersion, string $type, ?string $actorUuid, ?string $sourceRef, ?array $details, ?string $requestId, ?string $key, string $now): void
    {
        $this->pdo->prepare('INSERT INTO order_cancellation_events (event_uuid, order_uuid, event_type, control_version, actor_employee_uuid, source_key, source_event_ref,
                production_stage_id, production_version, details_json, request_id, idempotency_key, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([Uuid::v4(), $order['order_uuid'], $type, $controlVersion, $actorUuid, $order['source_key'], $sourceRef, $order['production_stage_id'], (int) $order['production_version'],
                $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $requestId === null ? null : mb_substr($requestId, 0, 100), $key, $now]);
    }

    /** @return array<string, mixed> the locked order row (latest committed version: a locking read) */
    private function order(string $orderUuid): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::ORDER_COLUMNS . ', source_reported_unavailable_at FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
    }

    /** @return array<string, mixed> */
    private function control(string $orderUuid): array
    {
        // Only read when the order row says a control row exists, so this locking read never takes a gap lock.
        $statement = $this->pdo->prepare('SELECT * FROM order_cancellation_controls WHERE order_uuid = ? FOR UPDATE');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new \RuntimeException('Cancellation control projection without a control row.');
    }

    private function versionOf(string $orderUuid): int
    {
        return (int) $this->scalar('SELECT version FROM order_cancellation_controls WHERE order_uuid = ?', [$orderUuid]);
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    private static function cut(mixed $value, int $length): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $length) : null;
    }

    /** Whether an outbound cancellation operation of this order is open (migration 034; none before it). */
    public function syncOpen(string $orderUuid): bool
    {
        if (!Sync\SyncSchema::ready($this->pdo)) {
            return false;
        }
        return (new Sync\SyncOperations($this->pdo, $this->clock))->openFor($orderUuid) !== null;
    }

    private function confirmSync(string $orderUuid, ?string $ref): void
    {
        if (!Sync\SyncSchema::ready($this->pdo)) {
            return;
        }
        $generation = (int) $this->scalar('SELECT hold_generation FROM order_cancellation_controls WHERE order_uuid = ? FOR UPDATE', [$orderUuid]);
        if ($generation > 0) {
            (new Sync\SyncOperations($this->pdo, $this->clock))->confirmFromInbound($orderUuid, $generation, $ref);
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
