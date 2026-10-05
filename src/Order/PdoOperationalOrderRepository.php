<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PdoOperationalOrderRepository implements OperationalOrderRepository
{
    private const SELECT = "
        SELECT
            o.order_uuid, o.global_order_id, o.order_number, o.production_stage_id,
            o.source_commerce_status_code, o.source_commerce_status_label, o.production_notes,
            o.operational_status, o.version, o.production_version, o.production_owner_employee_uuid,
            o.production_completed_at, o.accepted_at, o.updated_at, o.source_changed_at, o.last_source_seen_at,o.production_context,
            o.source_key,s.source_type,s.status AS source_status, s.last_contact_at AS source_last_contact_at,
            r.employee_uuid AS relation_employee_uuid, r.relation_type, r.status AS relation_status,
            r.started_at AS relation_started_at, r.last_action_at AS relation_last_action_at
        FROM operational_orders o
        INNER JOIN order_sources s ON s.source_key = o.source_key
    ";

    public function __construct(
        private PDO $pdo,
        private Clock $clock,
        private int $sourceFreshSeconds = 900,
        private int $sourceUnavailableSeconds = 3600,
    ) {
    }

    public function listMine(string $employeeUuid, int $limit, ?string $cursor): array
    {
        $limit = max(1, min(100, $limit));
        $params = [$employeeUuid];
        $cursorSql = '';
        if ($cursor !== null) {
            $cursorData = $this->decodeCursor($cursor);
            if ($cursorData === null) {
                throw new ApiException(400, 'INVALID_CURSOR', 'The provided cursor is invalid.');
            }
            $cursorSql = 'AND (r.last_action_at < ? OR (r.last_action_at = ? AND o.order_uuid < ?))';
            array_push($params, $cursorData['at'], $cursorData['at'], $cursorData['orderUuid']);
        }
        $queryLimit = $limit + 1;
        $statement = $this->pdo->prepare(self::SELECT . "
            INNER JOIN employee_order_relations r ON r.order_uuid = o.order_uuid
            WHERE r.employee_uuid = ? AND r.status = 'active'
            {$cursorSql}
            ORDER BY r.last_action_at DESC, o.order_uuid DESC
            LIMIT {$queryLimit}
        ");
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $nextCursor = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[count($rows) - 1];
            $nextCursor = $this->encodeCursor((string) $last['relation_last_action_at'], (string) $last['order_uuid']);
        }
        return ['items' => $this->hydrate($rows), 'nextCursor' => $nextCursor];
    }

    public function findByGlobalId(string $employeeUuid, string $globalOrderId): ?OperationalOrder
    {
        $statement = $this->pdo->prepare(self::SELECT . "
            LEFT JOIN employee_order_relations r ON r.order_uuid = o.order_uuid AND r.employee_uuid = ? AND r.status = 'active'
            WHERE o.global_order_id = ?
            LIMIT 1
        ");
        $statement->execute([$employeeUuid, $globalOrderId]);
        return $this->hydrate($statement->fetchAll(PDO::FETCH_ASSOC))[0] ?? null;
    }

    public function findByQrReference(string $employeeUuid, QrReference $reference): array
    {
        $statement = $this->pdo->prepare('SELECT q.status, q.expires_at, o.global_order_id FROM order_qr_references q INNER JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE q.qr_reference = ? LIMIT 1');
        $statement->execute([$reference->value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['status' => 'missing', 'order' => null];
        }
        if ($row['status'] !== 'active') {
            return ['status' => 'revoked', 'order' => null];
        }
        if ($row['expires_at'] !== null && $this->utc((string) $row['expires_at']) <= $this->clock->now()) {
            return ['status' => 'expired', 'order' => null];
        }
        return ['status' => 'active', 'order' => $this->findByGlobalId($employeeUuid, (string) $row['global_order_id'])];
    }

    public function findByLookupCode(string $employeeUuid, string $lookupCode, int $max): array
    {
        $max = max(1, min(20, $max));
        $statement = $this->pdo->prepare(self::SELECT . "
            LEFT JOIN employee_order_relations r ON r.order_uuid = o.order_uuid AND r.employee_uuid = ? AND r.status = 'active'
            WHERE o.order_lookup_code = ?
            ORDER BY o.updated_at DESC, o.order_uuid DESC
            LIMIT {$max}
        ");
        $statement->execute([$employeeUuid, $lookupCode]);
        return $this->hydrate($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<OperationalOrder>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $orderUuids = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['order_uuid'], $rows)));
        $placeholders = implode(',', array_fill(0, count($orderUuids), '?'));
        $statement = $this->pdo->prepare("
            SELECT item_uuid, order_uuid, source_item_id, line_number, name, product_code, variant, color,
                   width_value, height_value, measurement_unit, meters, quantity,production_context
            FROM operational_order_items
            WHERE order_uuid IN ({$placeholders})
            ORDER BY order_uuid ASC, line_number ASC
        ");
        $statement->execute($orderUuids);
        $itemsByOrder = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $itemsByOrder[(string) $item['order_uuid']][] = new OperationalOrderItem(
                (string) $item['item_uuid'],
                (string) $item['source_item_id'],
                (int) $item['line_number'],
                (string) $item['name'],
                $item['product_code'] === null ? null : (string) $item['product_code'],
                $item['variant'] === null ? null : (string) $item['variant'],
                $item['color'] === null ? null : (string) $item['color'],
                $item['width_value'] === null ? null : (float) $item['width_value'],
                $item['height_value'] === null ? null : (float) $item['height_value'],
                $item['measurement_unit'] === null ? null : (string) $item['measurement_unit'],
                $item['meters'] === null ? null : (float) $item['meters'],
                (int) $item['quantity'],
                $item['production_context']===null?null:json_decode($item['production_context'],true,flags:JSON_THROW_ON_ERROR),
            );
        }

        $orders = [];
        foreach ($rows as $row) {
            $relation = null;
            if ($row['relation_employee_uuid'] !== null) {
                $relation = new EmployeeOrderRelation(
                    (string) $row['relation_employee_uuid'],
                    (string) $row['relation_type'],
                    (string) $row['relation_status'],
                    $this->utc((string) $row['relation_started_at']),
                    $this->utc((string) $row['relation_last_action_at']),
                );
            }
            $lastSourceSeenAt = $this->utc((string) $row['last_source_seen_at']);
            $orders[] = new OperationalOrder(
                (string) $row['order_uuid'],
                GlobalOrderId::fromString((string) $row['global_order_id']),
                (string) $row['order_number'],
                (string) $row['production_stage_id'],
                $row['source_commerce_status_code'] === null ? null : (string) $row['source_commerce_status_code'],
                $row['source_commerce_status_label'] === null ? null : (string) $row['source_commerce_status_label'],
                $row['production_notes'] === null ? null : (string) $row['production_notes'],
                (string) $row['operational_status'],
                new OrderFreshness(
                    $row['source_key']==='b2b' && $row['source_type']==='internal' && $row['source_status']==='active' ? 'fresh' : $this->freshness((string) $row['source_status'], $row['source_last_contact_at'] === null ? $lastSourceSeenAt : $this->utc((string) $row['source_last_contact_at'])),
                    $this->utc((string) $row['source_changed_at']),
                    $lastSourceSeenAt,
                ),
                (int) $row['version'],
                $row['accepted_at'] === null ? null : $this->utc((string) $row['accepted_at']),
                $this->utc((string) $row['updated_at']),
                $itemsByOrder[(string) $row['order_uuid']] ?? [],
                $relation,
                (int) $row['production_version'],
                $row['production_owner_employee_uuid'] === null ? null : (string) $row['production_owner_employee_uuid'],
                $row['production_completed_at'] === null ? null : $this->utc((string) $row['production_completed_at']),
                $row['production_context']===null?null:json_decode($row['production_context'],true,flags:JSON_THROW_ON_ERROR),
            );
        }
        return $orders;
    }

    private function freshness(string $sourceStatus, DateTimeImmutable $lastContact): string
    {
        return OrderFreshness::classify($sourceStatus, $lastContact, $this->clock->now(), $this->sourceFreshSeconds, $this->sourceUnavailableSeconds);
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function encodeCursor(string $at, string $orderUuid): string
    {
        $json = json_encode(['u' => $at, 'id' => $orderUuid], JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{at: string, orderUuid: string}|null */
    private function decodeCursor(string $cursor): ?array
    {
        if (strlen($cursor) > 512 || preg_match('/^[a-zA-Z0-9_-]+={0,2}$/', $cursor) !== 1) {
            return null;
        }
        $padded = $cursor . str_repeat('=', (4 - strlen($cursor) % 4) % 4);
        $json = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || !isset($data['u'], $data['id']) || !is_string($data['u']) || !is_string($data['id'])) {
            return null;
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $data['id']) !== 1) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D', $data['u']) !== 1) {
            return null;
        }
        return ['at' => $data['u'], 'orderUuid' => $data['id']];
    }
}
