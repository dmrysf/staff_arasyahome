<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\Http\ApiException;
use PDO;

/**
 * Read models of production documents. Bounded, indexed reads only: the active QR and the current
 * document state come from the order row and the unique active revision, never from scanning history.
 * Views carry workshop data and personnel names, never prices, payment, email or the QR payload
 * (only a short non-reversible hint).
 */
final readonly class DocumentQueries
{
    private const HISTORY_LIMIT = 50;
    private const QUEUE_LIMIT = 100;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Compact document state for every Staff order view (workers need to see a blocked document).
     *
     * @return array<string, mixed>
     */
    public function summary(string $orderUuid): array
    {
        $statement = $this->pdo->prepare(
            "SELECT o.document_status, o.document_version, r.revision_number, r.status AS revision_status,
                    q.request_uuid, q.status AS request_status, q.target_revision_number, q.version AS request_version
             FROM operational_orders o
             LEFT JOIN production_document_revisions r ON r.revision_uuid = o.active_document_revision_uuid
             LEFT JOIN production_document_revision_requests q ON q.open_order_uuid = o.order_uuid
             WHERE o.order_uuid = ?",
        );
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['status' => 'none', 'version' => 0, 'revisionNumber' => null, 'request' => null];
        }
        return [
            'status' => (string) $row['document_status'],
            'version' => (int) $row['document_version'],
            'revisionNumber' => $row['revision_number'] === null ? null : (int) $row['revision_number'],
            'request' => $row['request_uuid'] === null ? null : [
                'id' => (string) $row['request_uuid'],
                'status' => (string) $row['request_status'],
                'targetRevision' => (int) $row['target_revision_number'],
                'version' => (int) $row['request_version'],
            ],
        ];
    }

    /**
     * Full document view of one order: state, active revision, open or last request, revisions and history.
     *
     * @return array<string, mixed>
     */
    public function orderDocument(string $orderUuid, bool $withHistory, ?int $afterEvent = null): array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.order_uuid, o.global_order_id, o.order_number, o.source_key, o.production_stage_id, o.production_completed_at, o.operational_status,
                    o.document_status, o.document_version, o.production_owner_employee_uuid, ps.display_name AS stage_label, owner.display_name AS owner_name
             FROM operational_orders o
             LEFT JOIN production_stages ps ON ps.stage_id = o.production_stage_id
             LEFT JOIN employees owner ON owner.employee_uuid = o.production_owner_employee_uuid
             WHERE o.order_uuid = ?',
        );
        $statement->execute([$orderUuid]);
        $order = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
        }
        $revisions = $this->pdo->prepare(
            'SELECT r.revision_uuid, r.revision_number, r.status, r.qr_reference, r.generated_at, r.stale_at, r.superseded_at, r.revoked_at, r.revoke_reason,
                    g.display_name AS generated_by, v.display_name AS revoked_by, a.display_name AS approved_by, q.decided_at AS approved_at,
                    (SELECT COUNT(*) FROM production_document_prints p WHERE p.revision_uuid = r.revision_uuid) AS prints,
                    (SELECT MAX(p.printed_at) FROM production_document_prints p WHERE p.revision_uuid = r.revision_uuid) AS last_printed_at
             FROM production_document_revisions r
             INNER JOIN employees g ON g.employee_uuid = r.generated_by_employee_uuid
             LEFT JOIN employees v ON v.employee_uuid = r.revoked_by_employee_uuid
             LEFT JOIN production_document_revision_requests q ON q.request_uuid = r.request_uuid
             LEFT JOIN employees a ON a.employee_uuid = q.decided_by_employee_uuid
             WHERE r.order_uuid = ? ORDER BY r.revision_number DESC LIMIT 20',
        );
        $revisions->execute([$orderUuid]);
        $list = array_map(fn (array $row): array => [
            'id' => (string) $row['revision_uuid'],
            'number' => (int) $row['revision_number'],
            'status' => (string) $row['status'],
            'qrHint' => DocumentService::qrHint((string) $row['qr_reference']),
            'generatedAt' => $this->iso($row['generated_at']),
            'generatedBy' => (string) $row['generated_by'],
            'approvedBy' => $row['approved_by'] === null ? null : (string) $row['approved_by'],
            'approvedAt' => $this->iso($row['approved_at']),
            'staleAt' => $this->iso($row['stale_at']),
            'supersededAt' => $this->iso($row['superseded_at']),
            'revokedAt' => $this->iso($row['revoked_at']),
            'revokedBy' => $row['revoked_by'] === null ? null : (string) $row['revoked_by'],
            'revokeReason' => $row['revoke_reason'] === null ? null : (string) $row['revoke_reason'],
            'prints' => (int) $row['prints'],
            'lastPrintedAt' => $this->iso($row['last_printed_at']),
        ], $revisions->fetchAll(PDO::FETCH_ASSOC));
        $active = null;
        foreach ($list as $revision) {
            if ($revision['status'] === 'active') {
                $active = $revision;
            }
        }
        $request = $this->pdo->prepare('SELECT request_uuid FROM production_document_revision_requests WHERE order_uuid = ? ORDER BY requested_at DESC, request_uuid DESC LIMIT 1');
        $request->execute([$orderUuid]);
        $latestRequest = $request->fetchColumn();
        $result = [
            'order' => [
                'id' => (string) $order['global_order_id'],
                'number' => (string) $order['order_number'],
                'source' => (string) $order['source_key'],
                'stageId' => (string) $order['production_stage_id'],
                'stageLabel' => $order['stage_label'] === null ? null : (string) $order['stage_label'],
                'ownerName' => $order['owner_name'] === null ? null : (string) $order['owner_name'],
                'completed' => $order['production_completed_at'] !== null,
                'unavailable' => $order['operational_status'] === 'unavailable',
            ],
            'status' => (string) $order['document_status'],
            'version' => (int) $order['document_version'],
            'activeRevision' => $active,
            'latestRevisionNumber' => $list[0]['number'] ?? null,
            'request' => is_string($latestRequest) ? $this->requestDetail($latestRequest) : null,
            'revisions' => $list,
        ];
        if ($withHistory) {
            $result += $this->history($orderUuid, $afterEvent);
        }
        return $result;
    }

    /** Append-only history, oldest first, keyset paginated. @return array{history: list<array<string, mixed>>, nextHistoryCursor: int|null} */
    public function history(string $orderUuid, ?int $after): array
    {
        $statement = $this->pdo->prepare(
            'SELECT e.event_seq, e.event_type, e.revision_number, e.request_uuid, e.metadata_json, e.occurred_at, a.display_name AS actor_name
             FROM production_document_events e LEFT JOIN employees a ON a.employee_uuid = e.actor_employee_uuid
             WHERE e.order_uuid = ? AND e.event_seq > ? ORDER BY e.event_seq LIMIT ' . (self::HISTORY_LIMIT + 1),
        );
        $statement->execute([$orderUuid, $after ?? 0]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) > self::HISTORY_LIMIT ? (int) $rows[self::HISTORY_LIMIT - 1]['event_seq'] : null;
        return [
            'history' => array_map(fn (array $row): array => [
                'seq' => (int) $row['event_seq'],
                'type' => (string) $row['event_type'],
                'revisionNumber' => $row['revision_number'] === null ? null : (int) $row['revision_number'],
                'requestId' => $row['request_uuid'] === null ? null : (string) $row['request_uuid'],
                'actorName' => $row['actor_name'] === null ? null : (string) $row['actor_name'],
                'occurredAt' => $this->iso($row['occurred_at']),
                'details' => $this->details($row['metadata_json']),
            ], array_slice($rows, 0, self::HISTORY_LIMIT)),
            'nextHistoryCursor' => $next,
        ];
    }

    /** @return array<string, mixed> */
    public function requestDetail(string $requestUuid): array
    {
        $statement = $this->pdo->prepare(
            'SELECT q.*, o.global_order_id, o.order_number, o.source_key, o.production_stage_id, o.document_status, o.production_completed_at,
                    ps.display_name AS stage_label, req.display_name AS requested_by, decider.display_name AS decided_by, res.display_name AS resolved_by,
                    owner.display_name AS owner_name, base.revision_number AS base_number, base.status AS base_status
             FROM production_document_revision_requests q
             INNER JOIN operational_orders o ON o.order_uuid = q.order_uuid
             INNER JOIN production_document_revisions base ON base.revision_uuid = q.base_revision_uuid
             INNER JOIN employees req ON req.employee_uuid = q.requested_by_employee_uuid
             LEFT JOIN employees decider ON decider.employee_uuid = q.decided_by_employee_uuid
             LEFT JOIN employees res ON res.employee_uuid = q.resolved_by_employee_uuid
             LEFT JOIN employees owner ON owner.employee_uuid = o.production_owner_employee_uuid
             LEFT JOIN production_stages ps ON ps.stage_id = o.production_stage_id
             WHERE q.request_uuid = ?',
        );
        $statement->execute([$requestUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiException(404, 'DOCUMENT_REQUEST_NOT_FOUND', 'Cererea de revizie nu a fost găsită.');
        }
        $previous = $this->pdo->prepare('SELECT request_uuid, status, decision_comment, decided_at FROM production_document_revision_requests WHERE request_uuid = ?');
        $previous->execute([$row['previous_request_uuid']]);
        $prior = $row['previous_request_uuid'] === null ? false : $previous->fetch(PDO::FETCH_ASSOC);
        return [
            'id' => (string) $row['request_uuid'],
            'status' => (string) $row['status'],
            'version' => (int) $row['version'],
            'order' => [
                'id' => (string) $row['global_order_id'],
                'number' => (string) $row['order_number'],
                'source' => (string) $row['source_key'],
                'stageId' => (string) $row['production_stage_id'],
                'stageLabel' => $row['stage_label'] === null ? null : (string) $row['stage_label'],
                'ownerName' => $row['owner_name'] === null ? null : (string) $row['owner_name'],
                'documentStatus' => (string) $row['document_status'],
                'completed' => $row['production_completed_at'] !== null,
            ],
            'baseRevision' => ['number' => (int) $row['base_number'], 'status' => (string) $row['base_status']],
            'targetRevision' => (int) $row['target_revision_number'],
            'productionStarted' => (int) $row['production_started'] === 1,
            'stageAtRequest' => (string) $row['stage_id_snapshot'],
            'requestedBy' => (string) $row['requested_by'],
            'requestedById' => (string) $row['requested_by_employee_uuid'],
            'requestedAt' => $this->iso($row['requested_at']),
            'comment' => $row['request_comment'] === null ? null : (string) $row['request_comment'],
            'changes' => array_map(static fn (array $c): array => ['field' => (string) $c['field'], 'line' => $c['line'] ?? null, 'before' => $c['before'] ?? null, 'after' => $c['after'] ?? null], $this->details($row['diff_json']) ?? []),
            'decidedBy' => $row['decided_by'] === null ? null : (string) $row['decided_by'],
            'decidedVia' => $row['decided_via'] === null ? null : (string) $row['decided_via'],
            'decisionComment' => $row['decision_comment'] === null ? null : (string) $row['decision_comment'],
            'decidedAt' => $this->iso($row['decided_at']),
            'resolvedAt' => $this->iso($row['resolved_at']),
            'resolvedBy' => $row['resolved_by'] === null ? null : (string) $row['resolved_by'],
            'resolutionNote' => $row['resolution_note'] === null ? null : (string) $row['resolution_note'],
            'previousRequest' => is_array($prior) ? [
                'id' => (string) $prior['request_uuid'],
                'status' => (string) $prior['status'],
                'decisionComment' => $prior['decision_comment'] === null ? null : (string) $prior['decision_comment'],
                'decidedAt' => $this->iso($prior['decided_at']),
            ] : null,
        ];
    }

    /**
     * Approver queue: pending requests (oldest first) or the latest decided ones.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(string $view): array
    {
        $sql = $view === 'pending'
            ? "SELECT q.request_uuid FROM production_document_revision_requests q WHERE q.status = 'pending' ORDER BY q.requested_at, q.request_uuid LIMIT " . self::QUEUE_LIMIT
            : "SELECT q.request_uuid FROM production_document_revision_requests q WHERE q.decided_at IS NOT NULL ORDER BY q.decided_at DESC, q.request_uuid DESC LIMIT " . self::QUEUE_LIMIT;
        $ids = $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        return array_map(function (string $id): array {
            $detail = $this->requestDetail($id);
            $detail['changeCount'] = count($detail['changes']);
            $detail['changes'] = array_slice($detail['changes'], 0, 3);
            return $detail;
        }, $ids);
    }

    public function pendingCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM production_document_revision_requests WHERE status = 'pending'")->fetchColumn();
    }

    /**
     * Requester worklist: orders whose document needs attention (stale or revoked) and approved requests
     * waiting for generation. Bounded and served by the document status index.
     *
     * @return list<array<string, mixed>>
     */
    public function attention(): array
    {
        $statement = $this->pdo->query(
            "SELECT o.order_uuid, o.global_order_id, o.order_number, o.source_key, o.document_status, o.document_version, o.production_stage_id, o.updated_at,
                    r.revision_number, q.request_uuid, q.status AS request_status, q.target_revision_number, q.version AS request_version, req.display_name AS requested_by
             FROM operational_orders o
             LEFT JOIN production_document_revisions r ON r.revision_uuid = o.active_document_revision_uuid
             LEFT JOIN production_document_revision_requests q ON q.open_order_uuid = o.order_uuid
             LEFT JOIN employees req ON req.employee_uuid = q.requested_by_employee_uuid
             WHERE o.document_status IN ('stale', 'revoked')
             ORDER BY o.updated_at DESC, o.order_uuid DESC LIMIT " . self::QUEUE_LIMIT,
        );
        return array_map(fn (array $row): array => [
            'orderId' => (string) $row['global_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'source' => (string) $row['source_key'],
            'documentStatus' => (string) $row['document_status'],
            'documentVersion' => (int) $row['document_version'],
            'stageId' => (string) $row['production_stage_id'],
            'revisionNumber' => $row['revision_number'] === null ? null : (int) $row['revision_number'],
            'updatedAt' => $this->iso($row['updated_at']),
            'request' => $row['request_uuid'] === null ? null : [
                'id' => (string) $row['request_uuid'],
                'status' => (string) $row['request_status'],
                'targetRevision' => (int) $row['target_revision_number'],
                'version' => (int) $row['request_version'],
                'requestedBy' => (string) $row['requested_by'],
            ],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Exact order-number lookup for document requesters (indexed lookup code, at most five matches,
     * no customer data).
     *
     * @return list<array<string, mixed>>
     */
    public function lookup(string $lookupCode): array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.global_order_id, o.order_number, o.source_key, o.document_status, r.revision_number
             FROM operational_orders o LEFT JOIN production_document_revisions r ON r.revision_uuid = o.active_document_revision_uuid
             WHERE o.order_lookup_code = ? ORDER BY o.updated_at DESC, o.order_uuid DESC LIMIT 5',
        );
        $statement->execute([$lookupCode]);
        return array_map(static fn (array $row): array => [
            'orderId' => (string) $row['global_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'source' => (string) $row['source_key'],
            'documentStatus' => (string) $row['document_status'],
            'revisionNumber' => $row['revision_number'] === null ? null : (int) $row['revision_number'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Document state of a scanned QR reference (one indexed lookup by the unique QR key).
     *
     * @return array{revisionNumber: int, status: string, activeRevisionNumber: int|null, orderId: string}|null
     */
    public function qrRevision(string $qrReference): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.revision_number, r.status, active.revision_number AS active_number, o.global_order_id
             FROM production_document_revisions r
             INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid
             LEFT JOIN production_document_revisions active ON active.active_order_uuid = r.order_uuid
             WHERE r.qr_reference = ?',
        );
        $statement->execute([$qrReference]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? [
            'revisionNumber' => (int) $row['revision_number'],
            'status' => (string) $row['status'],
            'activeRevisionNumber' => $row['active_number'] === null ? null : (int) $row['active_number'],
            'orderId' => (string) $row['global_order_id'],
        ] : null;
    }

    /** @return array<string, mixed>|null */
    private function details(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true, 16);
        return is_array($decoded) ? $decoded : null;
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
