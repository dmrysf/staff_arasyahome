<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Document\DocumentGuard;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Order\QrReference;
use Arasya\Operations\Order\UnitLabelReference;
use Arasya\Operations\Production\WorkUnits;
use Arasya\Operations\Quality\IdempotencyStore;
use Arasya\Operations\Support\Clock;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Dashboard commands and reads of the cancellation control (API 2.35.0).
 *
 *   GET  /management/orders/{id}/cancellation               orders.view_all
 *   POST /management/orders/{id}/cancellation/stop          production.cancellation.manage   { reason, requestSourceCancellation? }
 *   POST /management/orders/{id}/cancellation/physical      production.cancellation.manage   { expectedVersion, outcome, note, qrToken? }
 *   POST /management/orders/{id}/cancellation/release       production.cancellation.release  { expectedVersion, reason }
 *
 * Each command runs in one transaction: the order row is locked first, then the actor's employee row; the actor's
 * authority is re-read under that lock (a role removed while the screen was open decides nothing), a committed result
 * is replayed for the same Idempotency-Key, and the control row, the order projection, the events and the IAM audit
 * are written before commit. A refused release is recorded afterwards as release_refused. No command sends anything
 * to a source: "requestSourceCancellation" only records a pending request (outbound integrations are Phase 2).
 */
final readonly class CancellationService
{
    public const MANAGE = 'production.cancellation.manage';
    public const RELEASE = 'production.cancellation.release';
    private const MAX_ATTEMPTS = 3;
    private const RETRYABLE_MYSQL_ERRORS = [1205, 1213];
    private const EVENT_LIMIT = 200;

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private IdempotencyStore $idempotency,
        private Clock $clock,
        private ?Sync\CancellationSyncService $sync = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        $this->authorization->require($actor, 'orders.view_all');
        $order = $this->orderRow($this->globalId($globalOrderId), false);
        return $this->payload($actor, $order);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function stop(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId): array
    {
        $this->authorization->require($actor, self::MANAGE);
        IdempotencyStore::requireKey($key);
        $globalId = $this->globalId($globalOrderId);
        $reason = self::text($input['reason'] ?? null, 10, 'reason');
        $requestSource = $input['requestSourceCancellation'] ?? false;
        if (!is_bool($requestSource)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'requestSourceCancellation must be a boolean.');
        }
        $hash = IdempotencyStore::hash('cancellation.stop', $globalId, [$reason, $requestSource]);
        return $this->command($actor, self::MANAGE, 'cancellation.stop', $globalId, $key, $hash, function (EmployeeIdentity $actor, array $order, string $now) use ($reason, $requestSource, $requestId, $key): void {
            $result = $this->control()->stopLocally((string) $order['order_uuid'], $actor->employeeUuid, $reason, $requestSource, $requestId, $key);
            $this->audit($actor, $result['applied'] ? 'production.cancellation.stopped' : 'production.cancellation.stop_duplicate', $order, [
                'reason' => $reason, 'requestSourceCancellation' => $requestSource, 'outbound' => 'not_sent', 'stage' => $order['production_stage_id'],
            ], $requestId, $now);
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function physical(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId): array
    {
        $this->authorization->require($actor, self::MANAGE);
        IdempotencyStore::requireKey($key);
        $globalId = $this->globalId($globalOrderId);
        $expected = self::version($input['expectedVersion'] ?? null);
        $outcome = $input['outcome'] ?? null;
        if (!is_string($outcome) || !in_array($outcome, CancellationControl::PHYSICAL_OUTCOMES, true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'outcome must be secured, nothing_produced or left_factory.');
        }
        $note = self::text($input['note'] ?? null, 5, 'note');
        $token = $input['qrToken'] ?? null;
        if ($token !== null && (!is_string($token) || trim($token) === '' || strlen($token) > 200)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'qrToken is invalid.');
        }
        $hash = IdempotencyStore::hash('cancellation.physical', $globalId, [$expected, $outcome, $note, $token === null ? null : strtoupper(trim($token))]);
        return $this->command($actor, self::MANAGE, 'cancellation.physical', $globalId, $key, $hash, function (EmployeeIdentity $actor, array $order, string $now) use ($expected, $outcome, $note, $token, $requestId, $key): void {
            // The scanned code must be the current order QR or the active label of one of this order's pieces.
            $scan = $token === null ? null : $this->scan($order, trim($token));
            $this->control()->recordPhysical((string) $order['order_uuid'], $actor->employeeUuid, $expected, $outcome, $note, $scan, $requestId, $key);
            $this->audit($actor, 'production.cancellation.physical_recorded', $order, ['outcome' => $outcome, 'scan' => $scan, 'stage' => $order['production_stage_id']], $requestId, $now);
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function release(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId): array
    {
        $this->authorization->require($actor, self::RELEASE);
        IdempotencyStore::requireKey($key);
        $globalId = $this->globalId($globalOrderId);
        $expected = self::version($input['expectedVersion'] ?? null);
        $reason = self::text($input['reason'] ?? null, 10, 'reason');
        $hash = IdempotencyStore::hash('cancellation.release', $globalId, [$expected, $reason]);
        try {
            return $this->command($actor, self::RELEASE, 'cancellation.release', $globalId, $key, $hash, function (EmployeeIdentity $actor, array $order, string $now) use ($expected, $reason, $requestId, $key): void {
                $remaining = $this->control()->release((string) $order['order_uuid'], $actor->employeeUuid, $expected, $reason, $requestId, $key);
                $this->audit($actor, 'production.cancellation.released', $order, ['reason' => $reason, 'stage' => $order['production_stage_id'], 'remaining' => $remaining], $requestId, $now);
            });
        } catch (ApiException $error) {
            if (in_array($error->errorCode, ['RELEASE_SYNC_OPEN', 'RELEASE_SOURCE_CANCELLED', 'RELEASE_PHYSICAL_UNVERIFIED', 'RELEASE_DOCUMENT_NOT_READY', 'RELEASE_NOT_APPLICABLE', 'RELEASE_REFUSED'], true)) {
                $this->recordRefusal($actor, $globalId, $error->errorCode, $requestId, $key);
            }
            throw $error;
        }
    }

    /**
     * @param callable(EmployeeIdentity, array<string, mixed>, string): void $write
     * @return array<string, mixed>
     */
    private function command(EmployeeIdentity $actor, string $permission, string $operation, string $globalId, string $key, string $hash, callable $write): array
    {
        for ($attempt = 1; ; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                $order = $this->orderRow($globalId, true);
                // The actor's authority under its row lock: a role or application removed meanwhile applies here.
                $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$actor->employeeUuid]);
                $fresh = (new PdoEmployeeRepository($this->pdo))->findByUuid($actor->employeeUuid);
                if ($fresh === null || !$fresh->isOperationallyActive() || $fresh->mustChangePassword) {
                    throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Contul nu mai este autorizat.');
                }
                $this->authorization->require($fresh, $permission);
                $replay = $this->idempotency->replay($fresh->employeeUuid, $key, $operation, $globalId, $hash);
                if ($replay !== null) {
                    $this->pdo->rollBack();
                    return $replay;
                }
                $now = $this->now();
                $write($fresh, $order, $now);
                $response = $this->payload($fresh, $this->orderRow($globalId, true));
                $this->idempotency->store($fresh->employeeUuid, $key, $operation, $globalId, $hash, $response, $now);
                $this->pdo->commit();
                return $response;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($error instanceof PDOException && $attempt < self::MAX_ATTEMPTS && in_array((int) ($error->errorInfo[1] ?? 0), self::RETRYABLE_MYSQL_ERRORS, true)) {
                    continue;
                }
                throw $error;
            }
        }
    }

    private function recordRefusal(EmployeeIdentity $actor, string $globalId, string $code, string $requestId, string $key): void
    {
        try {
            $this->pdo->beginTransaction();
            $order = $this->orderRow($globalId, true);
            $this->control()->recordRefusal((string) $order['order_uuid'], $actor->employeeUuid, $code, $requestId, $key);
            $this->pdo->commit();
        } catch (Throwable) {
            // A failed refusal record must never turn the refusal into a different outcome.
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function payload(EmployeeIdentity $actor, array $order): array
    {
        $control = null;
        if ($order['cancellation_state'] !== null) {
            $statement = $this->pdo->prepare('SELECT c.*, oe.display_name AS owner_at_hold_name, pe.display_name AS physical_by_name, he.display_name AS held_by_name, re.display_name AS released_by_name
                FROM order_cancellation_controls c
                LEFT JOIN employees oe ON oe.employee_uuid = c.owner_employee_uuid_at_hold
                LEFT JOIN employees pe ON pe.employee_uuid = c.physical_verified_by_employee_uuid
                LEFT JOIN employees he ON he.employee_uuid = c.held_by_employee_uuid
                LEFT JOIN employees re ON re.employee_uuid = c.released_by_employee_uuid
                WHERE c.order_uuid = ?');
            $statement->execute([$order['order_uuid']]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $syncReady = $this->sync !== null && Sync\SyncSchema::ready($this->pdo);
            $control = is_array($row) ? CancellationView::management($row, $order, $syncReady && $this->control()->syncOpen((string) $order['order_uuid'])) : null;
            if ($control !== null && $syncReady) {
                // API 2.36.0: the outbound request of the current stop, its attempts and the actor's allowed actions.
                $control['sync'] = $this->sync->orderView($actor, $order, $row);
                $control['source']['outbound'] = $control['sync']['operation']['state'] ?? 'not_sent';
            }
        }
        $events = $this->pdo->prepare('SELECT x.event_seq, x.event_uuid, x.event_type, x.control_version, x.actor_employee_uuid, e.display_name AS actor_name, x.source_event_ref,
                x.production_stage_id, x.production_version, x.details_json, x.occurred_at
            FROM order_cancellation_events x LEFT JOIN employees e ON e.employee_uuid = x.actor_employee_uuid
            WHERE x.order_uuid = ? ORDER BY x.event_seq LIMIT ' . (self::EVENT_LIMIT + 1));
        $events->execute([$order['order_uuid']]);
        $rows = $events->fetchAll(PDO::FETCH_ASSOC);
        return [
            'orderId' => (string) $order['global_order_id'],
            'orderNumber' => (string) $order['order_number'],
            'source' => (string) $order['source_key'],
            'stage' => ['id' => (string) $order['production_stage_id']],
            'productionVersion' => (int) $order['production_version'],
            'productionCompleted' => $order['production_completed_at'] !== null,
            'sourceAvailability' => $order['operational_status'] === 'unavailable' ? 'cancelled' : 'active',
            'cancellation' => $control,
            'events' => array_map(static fn (array $event): array => [
                'id' => (string) $event['event_uuid'],
                'sequence' => (int) $event['event_seq'],
                'type' => (string) $event['event_type'],
                'controlVersion' => $event['control_version'] === null ? null : (int) $event['control_version'],
                'actor' => $event['actor_employee_uuid'] === null ? null : ['id' => (string) $event['actor_employee_uuid'], 'displayName' => (string) ($event['actor_name'] ?? $event['actor_employee_uuid'])],
                'sourceEventRef' => $event['source_event_ref'] === null ? null : (string) $event['source_event_ref'],
                'stageId' => (string) $event['production_stage_id'],
                'productionVersion' => (int) $event['production_version'],
                'details' => $event['details_json'] === null ? null : json_decode((string) $event['details_json'], true),
                'occurredAt' => CancellationView::iso((string) $event['occurred_at']),
            ], array_slice($rows, 0, self::EVENT_LIMIT)),
            'eventsTruncated' => count($rows) > self::EVENT_LIMIT,
            'capabilities' => [
                'stop' => $this->authorization->can($actor, self::MANAGE),
                'recordPhysical' => $this->authorization->can($actor, self::MANAGE),
                'release' => $this->authorization->can($actor, self::RELEASE),
                'sync' => $this->authorization->can($actor, Sync\CancellationSyncService::PERMISSION),
            ],
        ];
    }

    /** @param array<string, mixed> $order */
    private function scan(array $order, string $token): string
    {
        $label = UnitLabelReference::parsePayload($token);
        if ($label !== null) {
            $resolved = (new WorkUnits($this->pdo))->resolveLabel($label);
            if ($resolved === null || $resolved['status'] !== 'active') {
                throw new ApiException(422, 'INVALID_QR', 'Eticheta scanată nu este validă.');
            }
            if ($resolved['orderUuid'] !== $order['order_uuid']) {
                throw new ApiException(422, 'QR_ORDER_MISMATCH', 'Codul scanat aparține altei comenzi.');
            }
            return 'unit_label';
        }
        $reference = QrReference::parsePayload($token) ?? throw new ApiException(422, 'INVALID_QR', 'Codul scanat nu este un cod Arasya.');
        DocumentGuard::assertQrUsable($this->pdo, $reference->value);
        $statement = $this->pdo->prepare("SELECT order_uuid FROM order_qr_references WHERE qr_reference = ? AND status = 'active'");
        $statement->execute([$reference->value]);
        $owner = $statement->fetchColumn();
        if ($owner === false) {
            throw new ApiException(422, 'INVALID_QR', 'Codul scanat nu este codul curent al unei comenzi.');
        }
        if ($owner !== $order['order_uuid']) {
            throw new ApiException(422, 'QR_ORDER_MISMATCH', 'Codul scanat aparține altei comenzi.');
        }
        return 'order_qr';
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $metadata */
    private function audit(EmployeeIdentity $actor, string $action, array $order, array $metadata, string $requestId, string $now): void
    {
        (new IamAuditLogger($this->pdo))->record($actor, $action, 'order', (string) $order['global_order_id'], '#' . $order['order_number'], ['order' => $order['global_order_id']] + $metadata, $requestId, $now);
    }

    /** @return array<string, mixed> */
    private function orderRow(string $globalId, bool $lock): array
    {
        $statement = $this->pdo->prepare('SELECT order_uuid, global_order_id, order_number, source_key, operational_status, production_stage_id, production_owner_employee_uuid,
                open_exception_uuid, production_version, production_completed_at, document_status, cancellation_state, production_stopped_at
            FROM operational_orders WHERE global_order_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
    }

    private function globalId(string $value): string
    {
        try {
            return GlobalOrderId::fromString($value)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
        }
    }

    private static function text(mixed $value, int $min, string $field): string
    {
        if (!is_string($value)) {
            throw new ApiException(422, 'VALIDATION_FAILED', "{$field} is required.", ['field' => $field]);
        }
        $value = trim($value);
        if (mb_strlen($value) < $min || mb_strlen($value) > 1000) {
            throw new ApiException(422, 'VALIDATION_FAILED', "{$field} must have between {$min} and 1000 characters.", ['field' => $field]);
        }
        return $value;
    }

    private static function version(mixed $value): int
    {
        return is_int($value) && $value >= 1 ? $value : throw new ApiException(400, 'INVALID_REQUEST', 'expectedVersion must be a positive integer.');
    }

    private function control(): CancellationControl
    {
        return new CancellationControl($this->pdo, $this->clock);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
