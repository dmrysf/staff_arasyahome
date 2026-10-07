<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration;

use Arasya\Operations\Order\GlobalOrderId;
use PDO;

/**
 * Read-only production authority facts for a signed source about its own orders. Used by the source to
 * mirror which of its orders Arasya manages (central verification before a local ownership marker).
 * Only production facts are returned: no commerce data, items, QR values or customer data.
 */
final readonly class SourceAuthorityQueries
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<string> $sourceOrderIds already validated, unique
     * @return list<array<string, mixed>> in request order
     */
    public function forOrders(string $sourceKey, array $sourceOrderIds): array
    {
        $globalIds = array_map(static fn (string $id): string => (new GlobalOrderId($sourceKey, $id))->toString(), $sourceOrderIds);
        $rows = [];
        if ($globalIds !== []) {
            $marks = implode(',', array_fill(0, count($globalIds), '?'));
            $statement = $this->pdo->prepare("SELECT global_order_id, production_authority, production_stage_id, production_version, operational_status, production_completed_at
                FROM operational_orders WHERE source_key = ? AND global_order_id IN ({$marks})");
            $statement->execute([$sourceKey, ...$globalIds]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[(string) $row['global_order_id']] = $row;
            }
        }
        $result = [];
        foreach ($sourceOrderIds as $index => $orderId) {
            $row = $rows[$globalIds[$index]] ?? null;
            $result[] = [
                'orderId' => $orderId,
                'globalOrderId' => $globalIds[$index],
                'exists' => $row !== null,
                'productionAuthority' => $row === null ? null : (string) $row['production_authority'],
                'productionStageId' => $row === null ? null : (string) $row['production_stage_id'],
                'productionVersion' => $row === null ? null : (int) $row['production_version'],
                'operationalStatus' => $row === null ? null : (string) $row['operational_status'],
                'productionCompleted' => $row !== null && $row['production_completed_at'] !== null,
            ];
        }
        return $result;
    }
}
