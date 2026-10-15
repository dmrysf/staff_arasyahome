<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Support\Uuid;
use PDO;

/** Records only an explicit, authenticated physical receipt, in the caller's locked order transaction. */
final readonly class PieceCustodyReceipt
{
    public function __construct(private PDO $pdo) {}

    public function forException(string $exception, string $actor, string $stage, string $requestId, string $key, string $now): void
    {
        $units = $this->pdo->prepare("SELECT u.unit_uuid, u.order_uuid FROM production_exception_units e INNER JOIN production_work_units u ON u.unit_uuid = e.unit_uuid WHERE e.exception_uuid = ? AND u.status = 'active'");
        $units->execute([$exception]);
        foreach ($units->fetchAll(PDO::FETCH_ASSOC) as $unit) $this->receive((string) $unit['unit_uuid'], (string) $unit['order_uuid'], $actor, $stage, ['exceptionId' => $exception, 'explicitPhysicalReceipt' => true], $requestId, $key, $now);
    }

    public function receive(string $unit, string $order, string $actor, string $stage, array $metadata, string $requestId, string $key, string $now): void
    {
        $find = $this->pdo->prepare('SELECT * FROM production_unit_custody WHERE unit_uuid = ? FOR UPDATE');
        $find->execute([$unit]);
        $before = $find->fetch(PDO::FETCH_ASSOC);
        $version = is_array($before) ? (int) $before['version'] + 1 : 1;
        // A transfer proves the custodian, not a rail/table position. Keep deliberate holds until explicit release.
        $hold = is_array($before) ? $before['hold_reason'] : null;
        $this->pdo->prepare('INSERT INTO production_unit_custody (unit_uuid, order_uuid, custodian_employee_uuid, location_id, hold_reason, version, confirmed_by_employee_uuid, confirmed_at)
            VALUES (?, ?, ?, NULL, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE custodian_employee_uuid = VALUES(custodian_employee_uuid), location_id = NULL, version = VALUES(version), confirmed_by_employee_uuid = VALUES(confirmed_by_employee_uuid), confirmed_at = VALUES(confirmed_at)')
            ->execute([$unit, $order, $actor, $hold, $version, $actor, $now]);
        $this->pdo->prepare("INSERT INTO production_custody_events (event_uuid, unit_uuid, order_uuid, action, actor_employee_uuid, custodian_before, custodian_after, location_before, location_after, hold_before, hold_after, production_stage_id, version_after, metadata_json, request_id, idempotency_key, occurred_at)
            VALUES (?, ?, ?, 'custody_taken', ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([Uuid::v4(), $unit, $order, $actor, $before['custodian_employee_uuid'] ?? null, $actor, $before['location_id'] ?? null, $hold, $hold, $stage, $version,
                json_encode($metadata, JSON_THROW_ON_ERROR), mb_substr($requestId, 0, 100), 'receipt-' . substr(hash('sha256', $unit . '|' . $key), 0, 60), $now]);
    }
}
