<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\Http\ApiException;

/**
 * Stale-document safety for every production mutation.
 *
 * An order whose active production document is stale (printed content changed) or revoked by root is
 * blocked for every production action until a new revision is activated: the order keeps its stage and
 * owner. An order without any central document ('none') and an order with an active document are open.
 * Callers lock the order row first, then check; SQL guards repeat the condition for compare-and-swap.
 */
final class DocumentGuard
{
    public const OPEN_SQL = "document_status IN ('none', 'active')";
    public const BLOCKED_REASON = 'document_revision_pending';

    private function __construct()
    {
    }

    public static function isBlocked(?string $documentStatus): bool
    {
        return in_array($documentStatus, ['stale', 'revoked'], true);
    }

    /** @param array<string, mixed> $order a locked operational_orders row */
    public static function assertOpen(array $order): void
    {
        if (self::isBlocked(isset($order['document_status']) ? (string) $order['document_status'] : null)) {
            throw self::blocked();
        }
    }

    /**
     * A QR of a replaced or revoked document revision can never act for production. Returns quietly for
     * an active or unknown reference (the caller's own QR checks still apply).
     */
    public static function assertQrUsable(\PDO $pdo, string $qrReference): void
    {
        $state = (new DocumentQueries($pdo))->qrRevision($qrReference);
        if ($state === null || $state['status'] === 'active') {
            return;
        }
        throw self::invalidQr($state);
    }

    /** @param array{revisionNumber: int, status: string, activeRevisionNumber: int|null} $state */
    public static function invalidQr(array $state): ApiException
    {
        if ($state['status'] === 'superseded') {
            $active = $state['activeRevisionNumber'];
            return new ApiException(410, 'DOCUMENT_SUPERSEDED', 'DOCUMENT INVALID. Acest document a fost înlocuit.' . ($active === null ? '' : ' Folosește REVIZIA ' . $active . '.'), [
                'revisionNumber' => $state['revisionNumber'],
                'activeRevisionNumber' => $active,
            ]);
        }
        return new ApiException(410, 'DOCUMENT_REVOKED', 'DOCUMENT INVALID. Acest document a fost anulat. Așteaptă documentul nou.', ['revisionNumber' => $state['revisionNumber']]);
    }

    public static function blocked(): ApiException
    {
        return new ApiException(409, 'ORDER_BLOCKED_BY_DOCUMENT', 'Document blocat. Comanda are o revizie de document în curs. Așteaptă aprobarea și documentul nou.');
    }
}
