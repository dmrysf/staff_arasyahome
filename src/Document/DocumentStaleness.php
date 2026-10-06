<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\Quality\LiveEvents;
use Arasya\Operations\Support\Clock;
use PDO;

/**
 * Decides document staleness inside the canonical source projection transaction. It is created by the
 * projection writer itself, so no wiring can skip it: a printed-content change always blocks production
 * before the change commits.
 */
final readonly class DocumentStaleness
{
    private DocumentStore $store;
    private TicketSnapshot $snapshots;
    private LiveEvents $live;

    public function __construct(private PDO $pdo, private Clock $clock)
    {
        $this->store = new DocumentStore($pdo);
        $this->snapshots = new TicketSnapshot($pdo);
        $this->live = new LiveEvents($pdo);
    }

    /**
     * Called inside the source projection transaction after the canonical content changed, with the
     * order row locked. Makes an active document stale (blocking production, keeping stage and owner)
     * and supersedes an open request whose reviewed content no longer matches.
     */
    public function onContentChanged(string $orderUuid, string $requestId): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Document staleness is decided inside the canonical transaction.');
        }
        $order = $this->store->lockOrderByUuid($orderUuid);
        if (!in_array($order['document_status'], ['active', 'stale', 'revoked'], true) || $order['production_completed_at'] !== null) {
            return;
        }
        $snapshot = $this->snapshots->build($orderUuid);
        $fingerprint = TicketSnapshot::fingerprint($snapshot);
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $revision = $this->store->latestRevision($orderUuid);
        if ($order['document_status'] === 'active' && !hash_equals((string) $revision['fingerprint'], $fingerprint)) {
            $old = TicketSnapshot::fromJson((string) $revision['snapshot_json']);
            $cuttingDone = $this->store->cuttingCompleted($orderUuid, (string) $order['production_stage_id']);
            $processed = $cuttingDone ? TicketSnapshot::changedLineMeters($old, $snapshot) : null;
            $this->pdo->prepare('UPDATE production_document_revisions SET stale_at = ? WHERE revision_uuid = ? AND stale_at IS NULL')->execute([$now, $revision['revision_uuid']]);
            $this->store->updateOrderDocument($orderUuid, 'stale', (string) $revision['revision_uuid'], $now);
            $this->store->openBlock($order, (string) $revision['revision_uuid'], 'content_changed', $processed, $now);
            $this->store->event($orderUuid, 'content_changed', (int) $revision['revision_number'], (string) $revision['revision_uuid'], null, null,
                ['changes' => count(TicketSnapshot::diff($old, $snapshot)), 'stage' => $order['production_stage_id'], 'cuttingCompleted' => $cuttingDone], $requestId, $now);
            $this->store->notifyBlocked($order, $now);
        }
        $open = $this->pdo->prepare('SELECT request_uuid, fingerprint, target_revision_number, requested_by_employee_uuid, status FROM production_document_revision_requests WHERE open_order_uuid = ? FOR UPDATE');
        $open->execute([$orderUuid]);
        $request = $open->fetch(PDO::FETCH_ASSOC);
        if (is_array($request) && !hash_equals((string) $request['fingerprint'], $fingerprint)) {
            $this->pdo->prepare("UPDATE production_document_revision_requests SET status = 'superseded', open_order_uuid = NULL, version = version + 1, resolved_at = ?, resolution_note = 'Conținutul comenzii s-a schimbat din nou după cerere.' WHERE request_uuid = ?")
                ->execute([$now, $request['request_uuid']]);
            $this->store->event($orderUuid, 'request_superseded', (int) $request['target_revision_number'], null, (string) $request['request_uuid'], null, ['previousStatus' => $request['status']], $requestId, $now);
            $payload = ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number'], 'requestId' => (string) $request['request_uuid'], 'revisionNumber' => (int) $request['target_revision_number'], 'status' => 'superseded'];
            $this->live->toEmployee((string) $request['requested_by_employee_uuid'], 'document.request_superseded', $payload, $now);
            $this->live->toAudience(DocumentService::AUDIENCE_APPROVERS, 'document.request_resolved', $payload, $now);
            $this->live->toAudience(DocumentService::AUDIENCE_REQUESTERS, 'document.changed', ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number']], $now);
        }
    }
}
