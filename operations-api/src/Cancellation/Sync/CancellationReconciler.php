<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation\Sync;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Cancellation\CancellationControl;
use Arasya\Operations\Cancellation\CancellationView;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\Trendyol\TrendyolEligibility;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use PDO;
use Throwable;

/**
 * Cancellation reconciliation (API 2.36.0). It compares Arasya's cancellation facts with each other and with the
 * stored copies of the providers' own states (inbound events, the Trendyol package copy kept by the intake). It never
 * calls a provider: the worker's status reads and the inbound synchronizations bring the providers' states in.
 *
 * Kinds (one finding per order and fact):
 *   source_cancelled_without_stop        the source cancelled an open order that was never stopped (legacy, before 033)
 *   local_stop_without_request           stopped locally, no commercial cancellation was requested from the source
 *   request_without_operation            a cancellation was requested but never handed to a provider
 *   operation_lease_expired              a worker died while holding an operation (the next run recovers it)
 *   operation_pickup_delayed             the source connector has not picked the request up for 30 minutes
 *   operation_uncertain                  the provider may have acted; the worker reads its status before anything else
 *   operation_manual_review              a person must decide (provider refused, timeout, paid order, no supported call)
 *   operation_refused_still_stopped      the provider refused or failed; production stays stopped until a person decides
 *   operation_confirmed_source_active    confirmed by a status read, but no inbound cancellation reached the order yet
 *   source_cancelled_operation_open      the source's cancellation arrived but the operation is still open (apply confirms)
 *   trendyol_cancelled_order_active      the stored Trendyol package is cancelled but the order is still active
 *   released_with_open_operation         invariant breach: production was released while an operation is open
 *
 * dryRun() lists the findings and changes nothing. apply() (operator CLI only) records them as discrepancy rows,
 * resolves rows that are no longer found and confirms source_cancelled_operation_open from the inbound evidence that is
 * already stored. It never stops, releases or reopens an order, never sends anything, and never acts on a Trendyol
 * order from before the intake baseline (the Fresh Start boundary): those are listed with beforeBaseline=true only.
 */
final readonly class CancellationReconciler
{
    private const PICKUP_DELAY_SECONDS = 1800;
    private const LIMIT = 500;

    public function __construct(private PDO $pdo, private Clock $clock, private ProviderRegistry $providers, private AuthorizationService $authorization = new AuthorizationService())
    {
    }

    /** @return array<string, mixed> */
    public function dryRun(EmployeeIdentity $actor): array
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        $this->authorization->require($actor, CancellationSyncService::PERMISSION);
        $findings = $this->scan();
        return ['dryRun' => true, 'checkedAt' => CancellationView::iso($this->now()), 'counts' => self::counts($findings), 'findings' => array_map(self::publicFinding(...), $findings)];
    }

    /**
     * Records the findings and applies the only automatic remediation (confirmation from stored inbound evidence).
     * @return array<string, mixed>
     */
    public function apply(string $operator): array
    {
        if (!SyncSchema::ready($this->pdo)) {
            throw new \RuntimeException('Migration 034 is not applied.');
        }
        $findings = $this->scan();
        $confirmed = 0;
        foreach ($findings as $finding) {
            if ($finding['kind'] === 'source_cancelled_operation_open' && !$finding['beforeBaseline']) {
                $confirmed += (int) $this->confirm((string) $finding['orderUuid'], (string) $finding['operationUuid']);
            }
        }
        if ($confirmed > 0) {
            $findings = $this->scan();
        }
        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            $seen = [];
            foreach ($findings as $finding) {
                $fingerprint = $finding['fingerprint'];
                $seen[] = $fingerprint;
                $details = json_encode($finding['details'] + ['beforeBaseline' => $finding['beforeBaseline'], 'recordedBy' => mb_substr($operator, 0, 60)], JSON_THROW_ON_ERROR);
                $existing = $this->pdo->prepare('SELECT discrepancy_uuid FROM order_cancellation_discrepancies WHERE open_fingerprint = ? FOR UPDATE');
                $existing->execute([$fingerprint]);
                $uuid = $existing->fetchColumn();
                if (is_string($uuid)) {
                    $this->pdo->prepare('UPDATE order_cancellation_discrepancies SET last_seen_at = ?, severity = ?, remediation = ?, retry_eligible = ?, details_json = ? WHERE discrepancy_uuid = ?')
                        ->execute([$now, $finding['severity'], $finding['remediation'], (int) $finding['retryEligible'], $details, $uuid]);
                    continue;
                }
                $this->pdo->prepare('INSERT INTO order_cancellation_discrepancies (discrepancy_uuid, fingerprint, open_fingerprint, kind, severity, order_uuid, operation_uuid, source_key, remediation,
                        retry_eligible, details_json, state, detected_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'open\', ?, ?)')
                    ->execute([Uuid::v4(), $fingerprint, $fingerprint, $finding['kind'], $finding['severity'], $finding['orderUuid'], $finding['operationUuid'], $finding['source'],
                        $finding['remediation'], (int) $finding['retryEligible'], $details, $now, $now]);
            }
            $open = $this->pdo->query("SELECT discrepancy_uuid, fingerprint FROM order_cancellation_discrepancies WHERE state = 'open' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
            $resolved = 0;
            foreach ($open as $row) {
                if (!in_array($row['fingerprint'], $seen, true)) {
                    $this->pdo->prepare("UPDATE order_cancellation_discrepancies SET state = 'resolved', open_fingerprint = NULL, resolved_at = ? WHERE discrepancy_uuid = ?")->execute([$now, $row['discrepancy_uuid']]);
                    $resolved++;
                }
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return ['dryRun' => false, 'checkedAt' => CancellationView::iso($now), 'counts' => self::counts($findings), 'autoConfirmed' => $confirmed, 'resolved' => $resolved,
            'findings' => array_map(self::publicFinding(...), $findings)];
    }

    /**
     * Dashboard overview: the providers' capabilities and gates, operations by state and the recorded open discrepancies.
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    public function overview(EmployeeIdentity $actor, array $filters): array
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        $this->authorization->require($actor, 'orders.view_all');
        $ready = SyncSchema::ready($this->pdo);
        $providers = array_map(fn (string $source): array => $this->providers->view($source), ['trendyol', 'trendhome', 'outletperdele', 'b2b']);
        if (!$ready) {
            return ['ready' => false, 'providers' => $providers, 'operations' => [], 'discrepancies' => [], 'capabilities' => ['sync' => false]];
        }
        $states = [];
        foreach ($this->pdo->query('SELECT source_key, state, COUNT(*) AS total FROM order_cancellation_sync_operations GROUP BY source_key, state')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $states[(string) $row['source_key']][(string) $row['state']] = (int) $row['total'];
        }
        $state = ($filters['state'] ?? 'open') === 'resolved' ? 'resolved' : 'open';
        $statement = $this->pdo->prepare('SELECT d.*, o.global_order_id, o.order_number FROM order_cancellation_discrepancies d LEFT JOIN operational_orders o ON o.order_uuid = d.order_uuid
            WHERE d.state = ? ORDER BY FIELD(d.severity, \'high\', \'medium\', \'low\'), d.detected_at DESC LIMIT 200');
        $statement->execute([$state]);
        return [
            'ready' => true,
            'providers' => $providers,
            'operations' => $states,
            'discrepancies' => array_map(static fn (array $row): array => [
                'id' => (string) $row['discrepancy_uuid'], 'kind' => (string) $row['kind'], 'severity' => (string) $row['severity'],
                'orderId' => $row['global_order_id'] === null ? null : (string) $row['global_order_id'], 'orderNumber' => $row['order_number'] === null ? null : (string) $row['order_number'],
                'operationId' => $row['operation_uuid'] === null ? null : (string) $row['operation_uuid'], 'source' => (string) $row['source_key'],
                'remediation' => (string) $row['remediation'], 'retryEligible' => (int) $row['retry_eligible'] === 1, 'state' => (string) $row['state'],
                'details' => self::safeDetails($row['details_json'] === null ? [] : (json_decode((string) $row['details_json'], true) ?: [])),
                'detectedAt' => CancellationView::iso((string) $row['detected_at']), 'lastSeenAt' => CancellationView::iso((string) $row['last_seen_at']),
                'resolvedAt' => CancellationView::isoOrNull($row['resolved_at']),
            ], $statement->fetchAll(PDO::FETCH_ASSOC)),
            'capabilities' => ['sync' => $this->authorization->can($actor, CancellationSyncService::PERMISSION)],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function scan(): array
    {
        $ready = SyncSchema::ready($this->pdo);
        $baseline = $this->trendyolBaselineMillis();
        $findings = [];
        $add = function (string $kind, string $severity, array $row, ?string $operationUuid, string $remediation, bool $retry, array $details = []) use (&$findings, $baseline): void {
            $before = $row['source_key'] === 'trendyol' && $baseline !== null && isset($row['order_date_ms']) && $row['order_date_ms'] !== null && (int) $row['order_date_ms'] < $baseline;
            $generation = isset($row['hold_generation']) ? (int) $row['hold_generation'] : 0;
            $findings[] = [
                'fingerprint' => hash('sha256', implode('|', [$kind, $row['order_uuid'], $operationUuid ?? '-', $generation])),
                'kind' => $kind, 'severity' => $severity, 'orderUuid' => (string) $row['order_uuid'], 'globalOrderId' => (string) $row['global_order_id'],
                'orderNumber' => (string) $row['order_number'], 'source' => (string) $row['source_key'], 'operationUuid' => $operationUuid, 'remediation' => $remediation,
                'retryEligible' => $retry, 'beforeBaseline' => $before, 'details' => $details,
            ];
        };
        $orderColumns = 'o.order_uuid, o.global_order_id, o.order_number, o.source_key, o.operational_status, o.production_completed_at, tp.order_date_ms, tp.marketplace_status';
        $package = "LEFT JOIN trendyol_packages tp ON tp.released_order_uuid = o.order_uuid AND tp.intake_status = 'released'";

        // Legacy: a source cancellation that never became a stop (Phase 1 audit; stopping it needs an approved command).
        foreach ($this->rows("SELECT {$orderColumns} FROM operational_orders o {$package}
            WHERE o.cancellation_state IS NULL AND o.production_completed_at IS NULL AND (o.operational_status = 'unavailable' OR o.source_reported_unavailable_at IS NOT NULL) ORDER BY o.order_uuid LIMIT " . self::LIMIT) as $row) {
            $add('source_cancelled_without_stop', 'high', $row, null, 'stop_legacy_with_approval', false);
        }

        $generation = $ready ? 'c.hold_generation' : '1 AS hold_generation';
        $controls = $this->rows("SELECT {$orderColumns}, c.hold_status, c.origin, c.source_status, c.source_request_status, c.source_requested_at, {$generation}
            FROM order_cancellation_controls c JOIN operational_orders o ON o.order_uuid = c.order_uuid {$package} ORDER BY o.order_uuid LIMIT " . (self::LIMIT * 4));
        $operations = [];
        if ($ready) {
            foreach ($this->rows('SELECT * FROM order_cancellation_sync_operations WHERE open_order_uuid IS NOT NULL OR state IN (\'confirmed\', \'rejected\', \'failed\') ORDER BY created_at') as $operation) {
                $operations[(string) $operation['order_uuid']][(int) $operation['hold_generation']] = $operation;
            }
        }
        $now = $this->clock->now()->getTimestamp();
        foreach ($controls as $row) {
            $held = $row['hold_status'] === CancellationControl::HELD;
            $cancelled = $row['source_status'] === 'cancelled';
            $current = $operations[(string) $row['order_uuid']][(int) $row['hold_generation']] ?? null;
            $open = null;
            foreach ($operations[(string) $row['order_uuid']] ?? [] as $candidate) {
                if ($candidate['open_order_uuid'] !== null) {
                    $open = $candidate;
                }
            }
            if (!$held && $open !== null) {
                $add('released_with_open_operation', 'high', $row, (string) $open['operation_uuid'], 'investigate', false, ['state' => $open['state']]);
            }
            if ($held && !$cancelled && $row['origin'] === 'local' && $row['source_request_status'] === 'not_requested') {
                $add('local_stop_without_request', 'low', $row, null, 'decide_source_cancellation', false);
            }
            if ($held && !$cancelled && $row['source_request_status'] === 'pending' && $current === null && $open === null && $ready && !$this->hasGeneration((string) $row['order_uuid'], (int) $row['hold_generation'])) {
                $add('request_without_operation', 'medium', $row, null, 'send_to_source', true);
            }
            if ($open !== null && $cancelled) {
                $add('source_cancelled_operation_open', 'medium', $row, (string) $open['operation_uuid'], 'auto_confirm', false, ['state' => $open['state']]);
                continue;
            }
            if ($open !== null) {
                $state = (string) $open['state'];
                if ($state === SyncState::PROCESSING && $open['lease_expires_at'] !== null && strtotime((string) $open['lease_expires_at'] . ' UTC') < $now) {
                    $add('operation_lease_expired', 'medium', $row, (string) $open['operation_uuid'], 'worker_recovers', false);
                } elseif ($state === SyncState::AWAITING_PICKUP && $open['last_pulled_at'] === null && strtotime((string) $open['created_at'] . ' UTC') < $now - self::PICKUP_DELAY_SECONDS) {
                    $add('operation_pickup_delayed', 'low', $row, (string) $open['operation_uuid'], 'check_source_connector', false);
                } elseif ($state === SyncState::UNCERTAIN) {
                    $add('operation_uncertain', 'medium', $row, (string) $open['operation_uuid'], 'worker_status_read', false, ['error' => $open['last_error_code']]);
                } elseif ($state === SyncState::MANUAL_REVIEW) {
                    $add('operation_manual_review', 'medium', $row, (string) $open['operation_uuid'], 'person_decides', $open['mechanism'] !== 'manual', ['error' => $open['last_error_code']]);
                }
            }
            if ($held && !$cancelled && $current !== null && in_array($current['state'], [SyncState::REJECTED, SyncState::FAILED], true) && $open === null) {
                $add('operation_refused_still_stopped', 'medium', $row, (string) $current['operation_uuid'], 'retry_or_release_decision', true, ['state' => $current['state'], 'error' => $current['last_error_code']]);
            }
            if ($current !== null && $current['state'] === SyncState::CONFIRMED && !$cancelled && $row['operational_status'] !== 'unavailable') {
                $add('operation_confirmed_source_active', 'medium', $row, (string) $current['operation_uuid'], 'await_inbound_sync', false, ['confirmedBy' => $current['confirmed_by']]);
            }
        }

        // The stored Trendyol package copy says cancelled while the order is still active in Arasya.
        foreach ($this->rows("SELECT {$orderColumns}, NULL AS hold_generation FROM trendyol_packages tp JOIN operational_orders o ON o.order_uuid = tp.released_order_uuid
            WHERE tp.intake_status = 'released' AND o.operational_status <> 'unavailable' ORDER BY o.order_uuid LIMIT " . (self::LIMIT * 4)) as $row) {
            if (TrendyolEligibility::statusClass((string) $row['marketplace_status']) === TrendyolEligibility::CLASS_CANCELLED) {
                $add('trendyol_cancelled_order_active', 'high', $row, null, 'run_trendyol_reconcile', false, ['marketplaceStatus' => $row['marketplace_status']]);
            }
        }
        return $findings;
    }

    /** Confirms an open operation from the source cancellation already stored on the control row. */
    private function confirm(string $orderUuid, string $operationUuid): bool
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE')->execute([$orderUuid]);
            $control = $this->pdo->prepare('SELECT source_status, source_event_ref, hold_generation FROM order_cancellation_controls WHERE order_uuid = ? FOR UPDATE');
            $control->execute([$orderUuid]);
            $row = $control->fetch(PDO::FETCH_ASSOC);
            $operations = new SyncOperations($this->pdo, $this->clock);
            $operation = $operations->byId($operationUuid);
            $done = false;
            if (is_array($row) && $row['source_status'] === 'cancelled' && $operation !== null && $operation['open_order_uuid'] !== null && SyncState::allowed((string) $operation['state'], SyncState::CONFIRMED)) {
                $operations->transition($operation, SyncState::CONFIRMED, ['confirmed_by' => 'inbound_event'], ['kind' => 'inbound', 'outcome' => 'reconciled', 'system' => 'reconciler',
                    'correlation' => $row['source_event_ref'] === null ? null : mb_substr((string) $row['source_event_ref'], 0, 100)]);
                $done = true;
            }
            $this->pdo->commit();
            return $done;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function hasGeneration(string $orderUuid, int $generation): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM order_cancellation_sync_operations WHERE order_uuid = ? AND hold_generation = ?');
        $statement->execute([$orderUuid, $generation]);
        return $statement->fetchColumn() !== false;
    }

    private function trendyolBaselineMillis(): ?int
    {
        try {
            $value = $this->pdo->query('SELECT baseline_at FROM trendyol_intake_state WHERE state_id = 1')->fetchColumn();
        } catch (Throwable) {
            return null;
        }
        return is_string($value) ? (int) round((float) (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('U.u') * 1000) : null;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array<string, mixed>> $findings @return array<string, int> */
    private static function counts(array $findings): array
    {
        $counts = ['total' => count($findings), 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($findings as $finding) {
            $counts[$finding['severity']]++;
            $counts[$finding['kind']] = ($counts[$finding['kind']] ?? 0) + 1;
        }
        return $counts;
    }

    /** @param array<string, mixed> $finding @return array<string, mixed> */
    private static function publicFinding(array $finding): array
    {
        return ['kind' => $finding['kind'], 'severity' => $finding['severity'], 'orderId' => $finding['globalOrderId'], 'orderNumber' => $finding['orderNumber'],
            'source' => $finding['source'], 'operationId' => $finding['operationUuid'], 'remediation' => $finding['remediation'], 'retryEligible' => $finding['retryEligible'],
            'beforeBaseline' => $finding['beforeBaseline'], 'details' => self::safeDetails($finding['details'])];
    }

    /** Only short machine values leave the server (no customer data is ever part of a finding). @param array<string, mixed> $details @return array<string, mixed> */
    private static function safeDetails(array $details): array
    {
        $safe = [];
        foreach (['state', 'error', 'confirmedBy', 'marketplaceStatus', 'beforeBaseline'] as $key) {
            if (array_key_exists($key, $details) && (is_scalar($details[$key]) || $details[$key] === null)) {
                $safe[$key] = is_string($details[$key]) ? mb_substr($details[$key], 0, 60) : $details[$key];
            }
        }
        return $safe;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
