<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use Arasya\Operations\Order\SourceOrderSnapshot;
use PDO;
use PDOException;

/**
 * Stores the pricing basis a source stated for its commercial lines (order.items[].meterBasis, API 2.70.0), inside the
 * projection transaction, after the order row is locked.
 *
 * Deliberately outside every production path: the fact is not part of the projection hash, the item row, the printed
 * documents or the Confirmare clearance, so receiving it (also on a payload that is otherwise a duplicate) never changes
 * an order version, never makes a production document stale and never re-decides an approval. A snapshot without the
 * fact (an older shop build) leaves stored facts unchanged. Before migration 053 nothing is stored.
 */
final readonly class MeterFacts
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(string $orderUuid, SourceOrderSnapshot $snapshot, string $nowSql): void
    {
        if ($snapshot->meterBasis === null || $snapshot->meterBasis === []) {
            return;
        }
        try {
            $upsert = $this->pdo->prepare(
                'INSERT INTO order_item_meter_facts (order_uuid, source_item_id, basis_kind, priced_length, source_event_id, first_observed_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    source_event_id = IF(basis_kind <=> VALUES(basis_kind) AND priced_length <=> VALUES(priced_length), source_event_id, VALUES(source_event_id)),
                    updated_at = IF(basis_kind <=> VALUES(basis_kind) AND priced_length <=> VALUES(priced_length), updated_at, VALUES(updated_at)),
                    basis_kind = VALUES(basis_kind),
                    priced_length = VALUES(priced_length)'
            );
            foreach ($snapshot->meterBasis as $sourceItemId => $basis) {
                $length = $basis['length'] === null ? null : number_format($basis['length'], 3, '.', '');
                $upsert->execute([$orderUuid, (string) $sourceItemId, $basis['kind'], $length, $snapshot->sourceEventId, $nowSql, $nowSql]);
            }
        } catch (PDOException $error) {
            // 1146: migration 053 is not applied yet; the fact is optional evidence and never blocks ingestion.
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) {
                throw $error;
            }
        }
    }
}
