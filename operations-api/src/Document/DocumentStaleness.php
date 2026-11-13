<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\Quality\LiveEvents;
use Arasya\Operations\Support\Clock;
use PDO;

/**
 * Decides document staleness inside the canonical source projection transaction. It is created by the
 * projection writer itself, so no wiring can skip it: a printed manufacturing change always blocks production
 * before the change commits. A change of only the delivery identity (recipient, address, masked phone) of a
 * web shop or B2B order is recorded without blocking (API 2.68.1); Trendyol blocks on any printed change.
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
     * order row locked. Makes an active document stale (blocking production, keeping stage and owner) unless
     * only the delivery identity changed, and supersedes an open request whose reviewed content no longer matches.
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
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $revision = $this->store->latestRevision($orderUuid);
        // Compared in the revision's own schema: a newer layout contract alone never makes a document stale.
        $snapshot = $this->snapshots->build($orderUuid, (int) $revision['snapshot_schema']);
        $fingerprint = TicketSnapshot::fingerprint($snapshot);
        if ($order['document_status'] === 'active' && !hash_equals((string) $revision['fingerprint'], $fingerprint)) {
            $old = TicketSnapshot::fromJson((string) $revision['snapshot_json']);
            $changes = TicketSnapshot::diff($old, $snapshot);
            if (self::deliveryOnly((string) $order['source_key'], $changes)) {
                // Only the recipient, address or masked phone changed (a courier or invoice correction): nothing
                // to manufacture differs, so the printed document and its QR stay valid. Recorded once per new
                // identity. A later manufacturing change still compares with this revision and blocks, and its
                // revision then carries the corrected identity too.
                $this->recordDeliveryChange($order, $revision, $changes, $fingerprint, $requestId, $now);
            } else {
                $cuttingDone = $this->store->cuttingCompleted($orderUuid, (string) $order['production_stage_id']);
                $processed = $cuttingDone ? TicketSnapshot::changedLineMeters($old, $snapshot) : null;
                $this->pdo->prepare('UPDATE production_document_revisions SET stale_at = ? WHERE revision_uuid = ? AND stale_at IS NULL')->execute([$now, $revision['revision_uuid']]);
                $this->store->updateOrderDocument($orderUuid, 'stale', (string) $revision['revision_uuid'], $now);
                $this->store->openBlock($order, (string) $revision['revision_uuid'], 'content_changed', $processed, $now);
                $this->store->event($orderUuid, 'content_changed', (int) $revision['revision_number'], (string) $revision['revision_uuid'], null, null,
                    ['changes' => count($changes), 'stage' => $order['production_stage_id'], 'cuttingCompleted' => $cuttingDone], $requestId, $now);
                $this->store->notifyBlocked($order, $now);
            }
        }
        $open = $this->pdo->prepare('SELECT request_uuid, snapshot_schema, fingerprint, target_revision_number, requested_by_employee_uuid, status FROM production_document_revision_requests WHERE open_order_uuid = ? FOR UPDATE');
        $open->execute([$orderUuid]);
        $request = $open->fetch(PDO::FETCH_ASSOC);
        if (is_array($request) && (int) $request['snapshot_schema'] !== (int) $revision['snapshot_schema']) {
            $fingerprint = TicketSnapshot::fingerprint($this->snapshots->build($orderUuid, (int) $request['snapshot_schema']));
        }
        if (is_array($request) && !hash_equals((string) $request['fingerprint'], $fingerprint)) {
            $this->pdo->prepare("UPDATE production_document_revision_requests SET status = 'superseded', open_order_uuid = NULL, version = version + 1, resolved_at = ?, resolution_note = 'Conținutul comenzii s-a schimbat din nou după cerere.' WHERE request_uuid = ?")
                ->execute([$now, $request['request_uuid']]);
            $this->store->event($orderUuid, 'request_superseded', (int) $request['target_revision_number'], null, (string) $request['request_uuid'], null, ['previousStatus' => $request['status']], $requestId, $now);
            $payload = ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number'], 'requestId' => (string) $request['request_uuid'], 'revisionNumber' => (int) $request['target_revision_number'], 'status' => 'superseded'];
            $this->live->toEmployee((string) $request['requested_by_employee_uuid'], 'document.request_superseded', $payload, $now);
            $this->live->toAudience(DocumentService::AUDIENCE_APPROVERS, 'document.request_resolved', $payload, $now, (string) $order['source_key']);
            $this->live->toAudience(DocumentService::AUDIENCE_REQUESTERS, 'document.changed', ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number']], $now, (string) $order['source_key']);
        }
    }

    /**
     * One-time repair (API 2.68.1) of an order blocked before delivery-only changes stopped blocking: when the only
     * difference between the stale revision and the order is the delivery identity, the same revision becomes
     * usable again (same QR, nothing reprinted) and its open block ends. Anything else is left exactly as it is.
     * Runs inside the caller's transaction. Returns 'released' or the reason it was left unchanged.
     */
    public function releaseDeliveryOnly(string $orderUuid, string $requestId): string
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('A document release runs inside a transaction.');
        }
        $order = $this->store->lockOrderByUuid($orderUuid);
        if ($order['document_status'] !== 'stale') {
            return 'not_stale';
        }
        if ($order['production_completed_at'] !== null || $order['operational_status'] === 'unavailable') {
            return 'order_closed';
        }
        $revision = $this->store->latestRevision($orderUuid);
        if ($revision['status'] !== 'active' || $revision['revision_uuid'] !== $order['active_document_revision_uuid']) {
            return 'revision_not_active';
        }
        $open = $this->pdo->prepare('SELECT 1 FROM production_document_revision_requests WHERE open_order_uuid = ? FOR UPDATE');
        $open->execute([$orderUuid]);
        if ($open->fetchColumn() !== false) {
            return 'request_open';
        }
        $snapshot = $this->snapshots->build($orderUuid, (int) $revision['snapshot_schema']);
        $changes = TicketSnapshot::diff(TicketSnapshot::fromJson((string) $revision['snapshot_json']), $snapshot);
        if (!self::deliveryOnly((string) $order['source_key'], $changes)) {
            return 'blocking_change';
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $this->pdo->prepare('UPDATE production_document_revisions SET stale_at = NULL WHERE revision_uuid = ?')->execute([$revision['revision_uuid']]);
        $this->store->updateOrderDocument($orderUuid, 'active', (string) $revision['revision_uuid'], $now);
        $this->pdo->prepare('UPDATE production_document_blocks SET ended_at = ?, ended_by_revision_uuid = ?, open_order_uuid = NULL WHERE open_order_uuid = ?')
            ->execute([$now, $revision['revision_uuid'], $orderUuid]);
        $this->store->event($orderUuid, 'content_changed', (int) $revision['revision_number'], (string) $revision['revision_uuid'], null, null, [
            'changes' => count($changes),
            'blocking' => false,
            'scope' => 'delivery',
            'fields' => array_values(array_unique(array_column($changes, 'field'))),
            'stage' => $order['production_stage_id'],
            'fingerprint' => bin2hex(TicketSnapshot::fingerprint($snapshot)),
            'released' => true,
        ], $requestId, $now);
        $this->live->toAudience(DocumentService::AUDIENCE_REQUESTERS, 'document.changed', ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number']], $now, (string) $order['source_key']);
        $this->live->cuttingChanged($now);
        return 'released';
    }

    /**
     * Whether every printed difference is the delivery identity (recipient, company, contact, address, masked phone).
     * Trendyol keeps blocking on any printed change: its packages are re-issued through the Trendyol workflow. An
     * empty diff with a different fingerprint is a change the diff cannot name, so it blocks as before.
     *
     * @param list<array{field: string, line: int|null, before: string|null, after: string|null}> $changes
     */
    public static function deliveryOnly(string $sourceKey, array $changes): bool
    {
        if ($sourceKey === 'trendyol' || $changes === []) {
            return false;
        }
        foreach ($changes as $change) {
            if (!str_starts_with($change['field'], 'customer.')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Non-blocking history of a delivery identity correction, once per new identity: a replayed or commerce-only
     * source event with the same corrected identity records nothing again.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $revision
     * @param list<array{field: string, line: int|null, before: string|null, after: string|null}> $changes
     */
    private function recordDeliveryChange(array $order, array $revision, array $changes, string $fingerprint, string $requestId, string $now): void
    {
        $hex = bin2hex($fingerprint);
        $last = $this->pdo->prepare("SELECT metadata_json FROM production_document_events WHERE revision_uuid = ? AND event_type = 'content_changed' ORDER BY event_seq DESC LIMIT 1");
        $last->execute([$revision['revision_uuid']]);
        $previous = json_decode((string) $last->fetchColumn(), true);
        if (is_array($previous) && ($previous['fingerprint'] ?? null) === $hex) {
            return;
        }
        $this->store->event((string) $order['order_uuid'], 'content_changed', (int) $revision['revision_number'], (string) $revision['revision_uuid'], null, null, [
            'changes' => count($changes),
            'blocking' => false,
            'scope' => 'delivery',
            'fields' => array_values(array_unique(array_column($changes, 'field'))),
            'stage' => $order['production_stage_id'],
            'fingerprint' => $hex,
        ], $requestId, $now);
    }
}
