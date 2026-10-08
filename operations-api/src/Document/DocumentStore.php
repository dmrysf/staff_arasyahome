<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Quality\LiveEvents;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Shared persistence of the document engine: order/revision locks, document state, blocked intervals,
 * append-only history and blocked-order notifications. Callers own the transaction and lock the order
 * row first.
 */
final readonly class DocumentStore
{
    private LiveEvents $live;

    public function __construct(private PDO $pdo)
    {
        $this->live = new LiveEvents($pdo);
    }

    public function updateOrderDocument(string $orderUuid, string $status, string $revisionUuid, string $now): void
    {
        $this->pdo->prepare('UPDATE operational_orders SET document_status = ?, active_document_revision_uuid = ?, document_version = document_version + 1, version = version + 1, updated_at = ? WHERE order_uuid = ?')
            ->execute([$status, $revisionUuid, $now, $orderUuid]);
    }

    /** @param array<string, mixed> $order @param array{meters: string|null, lines: int}|null $processed */
    public function openBlock(array $order, string $revisionUuid, string $cause, ?array $processed, string $now): void
    {
        $existing = $this->pdo->prepare('SELECT block_uuid FROM production_document_blocks WHERE open_order_uuid = ? FOR UPDATE');
        $existing->execute([$order['order_uuid']]);
        if ($existing->fetchColumn() !== false) {
            return;
        }
        $this->pdo->prepare('INSERT INTO production_document_blocks (block_uuid, order_uuid, revision_uuid, cause, stage_id_snapshot, owner_employee_uuid_snapshot, production_started, processed_meters, processed_lines, started_at, open_order_uuid)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                Uuid::v4(), $order['order_uuid'], $revisionUuid, $cause, $order['production_stage_id'], $order['production_owner_employee_uuid'],
                $this->productionStarted((string) $order['order_uuid']) ? 1 : 0, $processed['meters'] ?? null, $processed === null ? null : $processed['lines'], $now, $order['order_uuid'],
            ]);
    }

    /** @param array<string, mixed> $order */
    public function notifyBlocked(array $order, string $now): void
    {
        if ($order['production_owner_employee_uuid'] !== null) {
            $this->live->toEmployee((string) $order['production_owner_employee_uuid'], 'document.blocked', ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number']], $now);
        }
        $this->live->toAudience(DocumentService::AUDIENCE_REQUESTERS, 'document.changed', ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number']], $now, (string) $order['source_key']);
        $this->live->cuttingChanged($now);
    }

    /** Real production started when a worker first claimed the order (generation alone is not a start). */
    public function productionStarted(string $orderUuid): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM order_activity_events WHERE order_uuid = ? AND action IN ('claimed', 'stage_completed', 'production_completed') LIMIT 1");
        $statement->execute([$orderUuid]);
        return $statement->fetchColumn() !== false;
    }

    /** Cutting is complete when the order is past cutting and was not returned to it by a fault. */
    public function cuttingCompleted(string $orderUuid, string $stageId): bool
    {
        if (in_array($stageId, ['waiting', 'material-preparation'], true)) {
            return false;
        }
        $statement = $this->pdo->prepare("SELECT 1 FROM order_activity_events WHERE order_uuid = ? AND action = 'stage_completed' AND from_stage_id = 'material-preparation' LIMIT 1");
        $statement->execute([$orderUuid]);
        return $statement->fetchColumn() !== false;
    }

    /** @return array<string, mixed> */
    public function latestRevision(string $orderUuid): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM production_document_revisions WHERE order_uuid = ? ORDER BY revision_number DESC LIMIT 1 FOR UPDATE');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(409, 'DOCUMENT_NOT_GENERATED', 'Comanda nu are încă un document de producție.');
    }

    /** @return array<string, mixed> */
    public function lockOrder(string $globalId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT order_uuid, global_order_id, order_number, source_key, production_authority, production_stage_id, production_owner_employee_uuid, production_completed_at, operational_status,
                    document_status, document_version, active_document_revision_uuid
             FROM operational_orders WHERE global_order_id = ? FOR UPDATE',
        );
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
    }

    /** @return array<string, mixed> */
    public function lockOrderByUuid(string $orderUuid): array
    {
        $find = $this->pdo->prepare('SELECT global_order_id FROM operational_orders WHERE order_uuid = ?');
        $find->execute([$orderUuid]);
        $globalId = $find->fetchColumn();
        return is_string($globalId) ? $this->lockOrder($globalId) : throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
    }

    /** @param array<string, mixed>|null $metadata */
    public function event(string $orderUuid, string $type, ?int $revisionNumber, ?string $revisionUuid, ?string $requestUuid, ?string $actorUuid, ?array $metadata, string $requestId, string $now): void
    {
        $this->pdo->prepare('INSERT INTO production_document_events (event_uuid, order_uuid, event_type, revision_number, revision_uuid, request_uuid, actor_employee_uuid, metadata_json, request_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([Uuid::v4(), $orderUuid, $type, $revisionNumber, $revisionUuid, $requestUuid, $actorUuid,
                $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), mb_substr($requestId, 0, 100), $now]);
    }
}
