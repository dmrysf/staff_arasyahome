<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Read model and immutable evidence of production QR revisions (order_qr_references + production_qr_events).
 *
 * A QR revision is one order_qr_references row. Its number is its position among the order's references,
 * oldest first (created_at, then reference), so it never changes once issued. At most one is active per
 * order (uq_order_qr_references_active). A retired reference is `superseded` (replaced by a rotation or a
 * new document revision) or `revoked` (security / root document revoke); rows retired before 2.19.0 carry
 * no reason and read as `retired`.
 *
 * Evidence rows hold a 10 character non-reversible hint of a reference, never the printed payload, and
 * never customer data.
 */
final class ProductionQrLedger
{
    public const ACTION_ISSUED = 'issued';
    public const ACTION_ROTATED = 'rotated';
    public const ACTION_REVOKED = 'revoked';
    public const ACTION_RECONCILED = 'reconciled';
    public const ACTION_SCAN_REJECTED = 'scan_rejected';
    public const RETIRED_SUPERSEDED = 'superseded';
    public const RETIRED_REVOKED = 'revoked';
    public const MODE_INTERNAL = 'internal';

    private function __construct()
    {
    }

    /** A short, non-reversible QR reference for audit and screens: never the printed payload. */
    public static function hint(string $reference): string
    {
        return substr(hash('sha256', $reference), 0, 10);
    }

    /** The QR authority mode recorded with evidence: the source's mode, or `internal` for unmanaged sources. */
    public static function modeLabel(?ProductionAuthorityModes $modes, string $sourceKey): string
    {
        return $modes !== null && $modes->isManagedSource($sourceKey) ? $modes->qrModeFor($sourceKey)->value : self::MODE_INTERNAL;
    }

    /** Revision number of one reference of an order (1 = first reference ever issued to it). */
    public static function revisionOf(PDO $pdo, string $orderUuid, string $reference): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM order_qr_references q
             INNER JOIN order_qr_references t ON t.qr_reference = ?
             WHERE q.order_uuid = ? AND (q.created_at < t.created_at OR (q.created_at = t.created_at AND q.qr_reference <= t.qr_reference))',
        );
        $statement->execute([$reference, $orderUuid]);
        return (int) $statement->fetchColumn();
    }

    /**
     * State of one scanned reference, or null when it is unknown.
     *
     * @return array{orderUuid: string, globalOrderId: string, sourceKey: string, status: string, state: string, revision: int, activeRevision: int|null}|null
     */
    public static function state(PDO $pdo, string $reference): ?array
    {
        $statement = $pdo->prepare('SELECT q.order_uuid, q.status, q.retired_reason, o.global_order_id, o.source_key FROM order_qr_references q INNER JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE q.qr_reference = ?');
        $statement->execute([$reference]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $orderUuid = (string) $row['order_uuid'];
        $active = $pdo->prepare('SELECT qr_reference FROM order_qr_references WHERE active_order_uuid = ?');
        $active->execute([$orderUuid]);
        $activeReference = $active->fetchColumn();
        return [
            'orderUuid' => $orderUuid,
            'globalOrderId' => (string) $row['global_order_id'],
            'sourceKey' => (string) $row['source_key'],
            'status' => (string) $row['status'],
            'state' => self::stateOf((string) $row['status'], $row['retired_reason'] === null ? null : (string) $row['retired_reason']),
            'revision' => self::revisionOf($pdo, $orderUuid, $reference),
            'activeRevision' => is_string($activeReference) ? self::revisionOf($pdo, $orderUuid, $activeReference) : null,
        ];
    }

    /**
     * Every QR revision of an order, oldest first, with the document revision bound to it (if any).
     *
     * @return list<array{reference: string, revision: int, state: string, issuedAt: string, retiredAt: string|null, documentRevision: int|null}>
     */
    public static function history(PDO $pdo, string $orderUuid): array
    {
        $statement = $pdo->prepare(
            'SELECT q.qr_reference, q.status, q.retired_reason, q.created_at, q.revoked_at, r.revision_number
             FROM order_qr_references q
             LEFT JOIN production_document_revisions r ON r.qr_reference = q.qr_reference
             WHERE q.order_uuid = ?
             ORDER BY q.created_at ASC, q.qr_reference ASC',
        );
        $statement->execute([$orderUuid]);
        $history = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $index => $row) {
            $history[] = [
                'reference' => (string) $row['qr_reference'],
                'revision' => $index + 1,
                'state' => self::stateOf((string) $row['status'], $row['retired_reason'] === null ? null : (string) $row['retired_reason']),
                'issuedAt' => (string) $row['created_at'],
                'retiredAt' => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
                'documentRevision' => $row['revision_number'] === null ? null : (int) $row['revision_number'],
            ];
        }
        return $history;
    }

    /**
     * Issues the order's active reference when it has none (caller holds the order row lock). The database
     * refuses a second active reference, so two concurrent issuers can never both succeed.
     *
     * @param array{order_uuid: string, global_order_id: string, source_key: string} $order
     * @return string|null the new reference, or null when an active one already existed
     */
    public static function ensureActive(PDO $pdo, array $order, string $mode, string $reason, ?string $actor, ?string $requestId, string $now): ?string
    {
        $statement = $pdo->prepare("SELECT 1 FROM order_qr_references WHERE active_order_uuid = ?");
        $statement->execute([$order['order_uuid']]);
        if ($statement->fetchColumn() !== false) {
            return null;
        }
        $reference = \Arasya\Operations\Order\QrReference::generate()->value;
        $pdo->prepare("INSERT INTO order_qr_references (qr_reference, order_uuid, status, created_at) VALUES (?, ?, 'active', ?)")->execute([$reference, $order['order_uuid'], $now]);
        self::record($pdo, $order, self::ACTION_ISSUED, $reference, null, $actor, $reason, $mode, $requestId, $now);
        return $reference;
    }

    /**
     * Retires every active reference of an order (caller holds the order row lock) and returns them.
     *
     * @return list<string>
     */
    public static function retireActive(PDO $pdo, string $orderUuid, string $reason, string $now): array
    {
        $statement = $pdo->prepare("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active' FOR UPDATE");
        $statement->execute([$orderUuid]);
        $references = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        if ($references !== []) {
            $pdo->prepare("UPDATE order_qr_references SET status = 'revoked', retired_reason = ?, revoked_at = ? WHERE order_uuid = ? AND status = 'active'")->execute([$reason, $now, $orderUuid]);
        }
        return $references;
    }

    /** @param array{order_uuid: string, global_order_id: string, source_key: string} $order */
    public static function record(PDO $pdo, array $order, string $action, ?string $reference, ?string $previous, ?string $actor, string $reason, string $mode, ?string $requestId, string $now): void
    {
        $pdo->prepare(
            'INSERT INTO production_qr_events (event_uuid, order_uuid, global_order_id, source_key, action, qr_revision, qr_hint, previous_qr_hint,
                qr_authority_mode, actor_employee_uuid, reason_code, request_id, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            Uuid::v4(), $order['order_uuid'], $order['global_order_id'], $order['source_key'], $action,
            $reference === null ? null : self::revisionOf($pdo, (string) $order['order_uuid'], $reference),
            $reference === null ? null : self::hint($reference),
            $previous === null ? null : self::hint($previous),
            $mode, $actor, $reason, $requestId === null ? null : mb_substr($requestId, 0, 100), $now,
        ]);
    }

    /**
     * The refusal for a scanned QR that is no longer the active production code of its order. Never
     * resolves the order; the active revision number is given only when the caller may see the order.
     *
     * @param array{state: string, revision: int} $state
     */
    public static function invalid(array $state, ?int $activeRevision): \Arasya\Operations\Http\ApiException
    {
        if ($state['state'] === self::RETIRED_SUPERSEDED) {
            return new \Arasya\Operations\Http\ApiException(410, 'QR_SUPERSEDED', 'COD QR ÎNLOCUIT. Acest cod nu mai este valabil pentru producție.' . ($activeRevision === null ? '' : " Folosește codul QR activ (revizia {$activeRevision})."), [
                'qrRevision' => $state['revision'],
                'activeQrRevision' => $activeRevision,
            ]);
        }
        return new \Arasya\Operations\Http\ApiException(410, 'QR_REVOKED', 'COD QR ANULAT. Acest cod a fost anulat și nu mai poate fi folosit pentru producție.', ['qrRevision' => $state['revision']]);
    }

    private static function stateOf(string $status, ?string $retiredReason): string
    {
        if ($status === 'active') {
            return 'active';
        }
        return $retiredReason ?? 'retired';
    }
}
