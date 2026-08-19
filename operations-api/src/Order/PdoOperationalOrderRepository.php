<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use Arasya\Operations\Http\ApiException;
use DateTimeImmutable;
use PDO;

final readonly class PdoOperationalOrderRepository implements OperationalOrderRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function listMine(string $employeeUuid, array $allowedStageIds, int $limit, ?string $cursor): array
    {
        if (empty($allowedStageIds)) {
            return ['items' => [], 'nextCursor' => null];
        }

        $params = [$employeeUuid];
        $cursorSql = '';
        if ($cursor !== null) {
            $cursorData = $this->decodeCursor($cursor);
            if ($cursorData === null) {
                throw new ApiException(400, 'INVALID_CURSOR', 'The provided cursor is invalid.');
            }
            $cursorSql = 'AND (o.updated_at < ? OR (o.updated_at = ? AND o.order_uuid < ?)) ';
            $params[] = $cursorData['updatedAt'];
            $params[] = $cursorData['updatedAt'];
            $params[] = $cursorData['orderUuid'];
        }

        $stagePlaceholders = implode(',', array_fill(0, count($allowedStageIds), '?'));
        foreach ($allowedStageIds as $stageId) {
            $params[] = $stageId;
        }

        $queryLimit = $limit + 1;
        $params[] = $queryLimit;

        $sql = "
            SELECT 
                o.order_uuid, o.global_order_id, o.source_key, o.source_order_id, o.order_number, 
                o.production_stage_id, o.source_commerce_status_code, o.source_commerce_status_label, 
                o.production_notes, o.operational_status, o.freshness_status, o.version, o.accepted_at, o.updated_at,
                o.source_changed_at, o.last_source_seen_at,
                r.employee_uuid, r.relation_type, r.status AS relation_status, r.started_at, r.last_action_at
            FROM operational_orders o
            INNER JOIN employee_order_relations r ON o.order_uuid = r.order_uuid
            WHERE r.employee_uuid = ? AND r.status = 'active'
            $cursorSql
            AND o.production_stage_id IN ($stagePlaceholders)
            ORDER BY o.updated_at DESC, o.order_uuid DESC
            LIMIT ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $nextCursor = null;
        if (count($rows) > $limit) {
            $nextRow = array_pop($rows);
            $nextCursor = $this->encodeCursor($rows[count($rows)-1]['updated_at'], $rows[count($rows)-1]['order_uuid']);
        }

        if (empty($rows)) {
            return ['items' => [], 'nextCursor' => null];
        }

        $orders = $this->hydrateOrders($rows);
        return ['items' => $orders, 'nextCursor' => $nextCursor];
    }

    public function findMineByGlobalId(string $employeeUuid, array $allowedStageIds, string $globalOrderId): ?OperationalOrder
    {
        if (empty($allowedStageIds)) {
            return null;
        }

        $stagePlaceholders = implode(',', array_fill(0, count($allowedStageIds), '?'));
        $params = [$globalOrderId, $employeeUuid];
        foreach ($allowedStageIds as $stageId) {
            $params[] = $stageId;
        }

        $sql = "
            SELECT 
                o.order_uuid, o.global_order_id, o.source_key, o.source_order_id, o.order_number, 
                o.production_stage_id, o.source_commerce_status_code, o.source_commerce_status_label, 
                o.production_notes, o.operational_status, o.freshness_status, o.version, o.accepted_at, o.updated_at,
                o.source_changed_at, o.last_source_seen_at,
                r.employee_uuid, r.relation_type, r.status AS relation_status, r.started_at, r.last_action_at
            FROM operational_orders o
            INNER JOIN employee_order_relations r ON o.order_uuid = r.order_uuid
            WHERE o.global_order_id = ? AND r.employee_uuid = ? AND r.status = 'active'
            AND o.production_stage_id IN ($stagePlaceholders)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $orders = $this->hydrateOrders([$row]);
        return $orders[0] ?? null;
    }

    private function hydrateOrders(array $rows): array
    {
        if (empty($rows)) return [];

        $orderUuids = array_column($rows, 'order_uuid');
        $uuidPlaceholders = implode(',', array_fill(0, count($orderUuids), '?'));

        $stmt = $this->pdo->prepare("
            SELECT item_uuid, order_uuid, source_item_id, line_number, name, product_code, variant, color, width_value, height_value, measurement_unit, meters, quantity 
            FROM operational_order_items 
            WHERE order_uuid IN ($uuidPlaceholders) 
            ORDER BY line_number ASC
        ");
        $stmt->execute($orderUuids);
        $itemRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $itemsByOrder = [];
        foreach ($itemRows as $itemRow) {
            $itemsByOrder[$itemRow['order_uuid']][] = new OperationalOrderItem(
                $itemRow['item_uuid'],
                $itemRow['source_item_id'],
                (int)$itemRow['line_number'],
                $itemRow['name'],
                $itemRow['product_code'],
                $itemRow['variant'],
                $itemRow['color'],
                $itemRow['width_value'] !== null ? (float)$itemRow['width_value'] : null,
                $itemRow['height_value'] !== null ? (float)$itemRow['height_value'] : null,
                $itemRow['measurement_unit'],
                $itemRow['meters'] !== null ? (float)$itemRow['meters'] : null,
                (int)$itemRow['quantity'],
            );
        }

        $orders = [];
        foreach ($rows as $row) {
            $relation = null;
            if (isset($row['relation_type'])) {
                $relation = new EmployeeOrderRelation(
                    $row['employee_uuid'],
                    $row['relation_type'],
                    $row['relation_status'],
                    new DateTimeImmutable($row['started_at']),
                    new DateTimeImmutable($row['last_action_at'])
                );
            }

            $freshness = new OrderFreshness(
                $row['freshness_status'],
                new DateTimeImmutable($row['source_changed_at']),
                new DateTimeImmutable($row['last_source_seen_at'])
            );

            $orders[] = new OperationalOrder(
                $row['order_uuid'],
                GlobalOrderId::fromString($row['global_order_id']),
                $row['order_number'],
                $row['production_stage_id'],
                $row['source_commerce_status_code'],
                $row['source_commerce_status_label'],
                $row['production_notes'],
                $row['operational_status'],
                $freshness,
                (int)$row['version'],
                $row['accepted_at'] ? new DateTimeImmutable($row['accepted_at']) : null,
                new DateTimeImmutable($row['updated_at']),
                $itemsByOrder[$row['order_uuid']] ?? [],
                $relation
            );
        }

        return $orders;
    }

    private function encodeCursor(string $updatedAt, string $orderUuid): string
    {
        $json = json_encode(['u' => $updatedAt, 'id' => $orderUuid], JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): ?array
    {
        $padded = $cursor . str_repeat('=', (4 - strlen($cursor) % 4) % 4);
        $json = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($json === false) return null;
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || !isset($data['u'], $data['id'])) return null;
        if (!is_string($data['u']) || !is_string($data['id'])) return null;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $data['id']) !== 1) return null;
        return ['updatedAt' => $data['u'], 'orderUuid' => $data['id']];
    }
}
