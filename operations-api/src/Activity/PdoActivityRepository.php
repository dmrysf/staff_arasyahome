<?php

declare(strict_types=1);

namespace Arasya\Operations\Activity;

use Arasya\Operations\Http\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PdoActivityRepository
{
    /**
     * A Staff history lists the employee's own production work. Supervisor owner interventions are
     * recorded with the supervisor as actor and belong to the Dashboard timeline, not to Staff history.
     */
    private const STAFF_ACTIONS = "'claimed', 'stage_completed', 'production_completed'";

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Only the authenticated employee's persisted activity events.
     *
     * @return array{items: list<array<string, mixed>>, nextCursor: string|null, summary: array{processed: int, meters: float, handedOver: int, inProgress: int}}
     */
    public function listForEmployee(string $employeeUuid, ActivityRange $range, ?string $cursor, int $limit = 50): array
    {
        $from = $range->fromUtc->format('Y-m-d H:i:s.u');
        $to = $range->toUtc->format('Y-m-d H:i:s.u');
        $params = [$employeeUuid, $from, $to];
        $cursorSql = '';
        if ($cursor !== null) {
            $decoded = $this->decodeCursor($cursor);
            if ($decoded === null) {
                throw new ApiException(400, 'INVALID_CURSOR', 'The provided cursor is invalid.');
            }
            $cursorSql = 'AND (occurred_at < ? OR (occurred_at = ? AND event_id < ?))';
            array_push($params, $decoded['at'], $decoded['at'], $decoded['id']);
        }
        $queryLimit = $limit + 1;
        $statement = $this->pdo->prepare("
            SELECT event_id, occurred_at, action, global_order_id, source_key, order_number_snapshot,
                   from_stage_id, from_stage_label_snapshot, to_stage_id, to_stage_label_snapshot, meters_snapshot
            FROM order_activity_events
            WHERE employee_uuid = ? AND occurred_at >= ? AND occurred_at < ? AND action IN (" . self::STAFF_ACTIONS . ")
            {$cursorSql}
            ORDER BY occurred_at DESC, event_id DESC
            LIMIT {$queryLimit}
        ");
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $nextCursor = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[count($rows) - 1];
            $nextCursor = $this->encodeCursor((string) $last['occurred_at'], (string) $last['event_id']);
        }

        $items = array_map(function (array $row): array {
            $item = [
                'id' => (string) $row['event_id'],
                'occurredAt' => (new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
                'action' => (string) $row['action'],
                'orderId' => (string) $row['global_order_id'],
                'orderNumber' => (string) $row['order_number_snapshot'],
                'source' => (string) $row['source_key'],
                'fromStageId' => (string) $row['from_stage_id'],
                'fromStageLabelSnapshot' => (string) $row['from_stage_label_snapshot'],
            ];
            if ($row['to_stage_id'] !== null) {
                $item['toStageId'] = (string) $row['to_stage_id'];
                $item['toStageLabelSnapshot'] = (string) $row['to_stage_label_snapshot'];
            }
            if ($row['meters_snapshot'] !== null) {
                $item['meters'] = (float) $row['meters_snapshot'];
            }
            return $item;
        }, $rows);

        return ['items' => $items, 'nextCursor' => $nextCursor, 'summary' => $this->summary($employeeUuid, $from, $to)];
    }

    /** @return array{processed: int, meters: float, handedOver: int, inProgress: int} */
    private function summary(string $employeeUuid, string $from, string $to): array
    {
        $statement = $this->pdo->prepare("
            SELECT COUNT(DISTINCT order_uuid) AS processed,
                   COALESCE(SUM(CASE WHEN action IN ('stage_completed', 'production_completed') THEN 1 ELSE 0 END), 0) AS handed_over,
                   COALESCE(SUM(CASE WHEN action IN ('stage_completed', 'production_completed') THEN meters_snapshot ELSE 0 END), 0) AS meters
            FROM order_activity_events
            WHERE employee_uuid = ? AND occurred_at >= ? AND occurred_at < ? AND action IN (" . self::STAFF_ACTIONS . ")
        ");
        $statement->execute([$employeeUuid, $from, $to]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $owned = $this->pdo->prepare('SELECT COUNT(*) FROM operational_orders WHERE production_owner_employee_uuid = ? AND production_completed_at IS NULL');
        $owned->execute([$employeeUuid]);
        return [
            'processed' => (int) ($row['processed'] ?? 0),
            'meters' => round((float) ($row['meters'] ?? 0), 3),
            'handedOver' => (int) ($row['handed_over'] ?? 0),
            'inProgress' => (int) $owned->fetchColumn(),
        ];
    }

    private function encodeCursor(string $at, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['u' => $at, 'id' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{at: string, id: string}|null */
    private function decodeCursor(string $cursor): ?array
    {
        if (strlen($cursor) > 512 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
            return null;
        }
        $json = base64_decode(strtr($cursor . str_repeat('=', (4 - strlen($cursor) % 4) % 4), '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || !is_string($data['u'] ?? null) || !is_string($data['id'] ?? null)) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D', $data['u']) !== 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $data['id']) !== 1) {
            return null;
        }
        return ['at' => $data['u'], 'id' => $data['id']];
    }
}
