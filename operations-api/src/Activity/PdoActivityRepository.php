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

    /**
     * API 2.52.0: the office work of the same employee (Confirmare, invoices, money, shop operations), read from the
     * evidence each of those flows already keeps; nothing new is recorded. Only the employee's own completed actions:
     * a decision, a customer contact, a shop edit or operation that was applied, an invoice or finance action that was
     * applied, and the verification requests they opened, resubmitted or cancelled (the verification decisions
     * themselves are the finance actions). System events, reads and pending or rejected attempts are not history.
     * Every branch yields: event_id, occurred_at, kind, action, detail, order_uuid, global_order_id, source_key,
     * order_number, from_stage_id, from_stage_label_snapshot, to_stage_id, to_stage_label_snapshot, meters_snapshot.
     */
    private const OFFICE_BRANCHES = [
        "SELECT e.event_uuid, e.occurred_at, 'office', CONCAT('confirmare.', e.action), e.reason_code, o.order_uuid, o.global_order_id, o.source_key, o.order_number, NULL, NULL, NULL, NULL, NULL
            FROM order_confirmation_events e JOIN operational_orders o ON o.order_uuid = e.order_uuid
            WHERE e.actor_employee_uuid = ? AND e.occurred_at >= ? AND e.occurred_at < ? AND e.action IN ('approved', 'rejected', 'reopened', 'reassigned')",
        "SELECT c.contact_uuid, c.recorded_at, 'office', 'contact', c.outcome, o.order_uuid, o.global_order_id, o.source_key, o.order_number, NULL, NULL, NULL, NULL, NULL
            FROM order_confirmation_contacts c JOIN operational_orders o ON o.order_uuid = c.order_uuid
            WHERE c.employee_uuid = ? AND c.recorded_at >= ? AND c.recorded_at < ?",
        "SELECT x.edit_uuid, x.completed_at, 'office', 'shop.edit', NULL, o.order_uuid, o.global_order_id, o.source_key, o.order_number, NULL, NULL, NULL, NULL, NULL
            FROM order_confirmation_source_edits x JOIN operational_orders o ON o.order_uuid = x.order_uuid
            WHERE x.employee_uuid = ? AND x.completed_at >= ? AND x.completed_at < ? AND x.state = 'applied'",
        "SELECT x.operation_uuid, x.completed_at, 'office', CONCAT('shop.', x.kind), NULL, o.order_uuid, o.global_order_id, o.source_key, o.order_number, NULL, NULL, NULL, NULL, NULL
            FROM order_source_operations x JOIN operational_orders o ON o.order_uuid = x.order_uuid
            WHERE x.employee_uuid = ? AND x.completed_at >= ? AND x.completed_at < ? AND x.state = 'applied'",
        "SELECT x.action_uuid, x.completed_at, 'office', CONCAT('invoice.', x.action), NULL, o.order_uuid, o.global_order_id, o.source_key, o.order_number, NULL, NULL, NULL, NULL, NULL
            FROM order_invoice_actions x JOIN operational_orders o ON o.order_uuid = x.order_uuid
            WHERE x.employee_uuid = ? AND x.completed_at >= ? AND x.completed_at < ? AND x.state = 'applied'",
        "SELECT x.action_uuid, x.completed_at, 'office', CONCAT('finance.', x.action), x.decision, o.order_uuid, o.global_order_id, o.source_key, o.order_number, NULL, NULL, NULL, NULL, NULL
            FROM order_finance_actions x JOIN operational_orders o ON o.order_uuid = x.order_uuid
            WHERE x.employee_uuid = ? AND x.completed_at >= ? AND x.completed_at < ? AND x.state = 'applied'",
        "SELECT e.event_uuid, e.occurred_at, 'office', CONCAT('verification.', e.action), NULL, o.order_uuid, o.global_order_id, o.source_key, o.order_number, NULL, NULL, NULL, NULL, NULL
            FROM finance_verification_request_events e JOIN finance_verification_requests r ON r.request_uuid = e.request_uuid JOIN operational_orders o ON o.order_uuid = r.order_uuid
            WHERE e.actor_employee_uuid = ? AND e.occurred_at >= ? AND e.occurred_at < ? AND e.action IN ('requested', 'resubmitted', 'cancelled')",
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Only the authenticated employee's persisted activity events; with `$office` also their office work (API 2.52.0).
     *
     * @return array{items: list<array<string, mixed>>, nextCursor: string|null, summary: array{processed: int, meters: float, handedOver: int, inProgress: int, actions?: int}}
     */
    public function listForEmployee(string $employeeUuid, ActivityRange $range, ?string $cursor, int $limit = 50, bool $office = false): array
    {
        $from = $range->fromUtc->format('Y-m-d H:i:s.u');
        $to = $range->toUtc->format('Y-m-d H:i:s.u');
        [$events, $params] = $this->events($employeeUuid, $from, $to, $office);
        $cursorSql = '';
        if ($cursor !== null) {
            $decoded = $this->decodeCursor($cursor);
            if ($decoded === null) {
                throw new ApiException(400, 'INVALID_CURSOR', 'The provided cursor is invalid.');
            }
            $cursorSql = 'WHERE (occurred_at < ? OR (occurred_at = ? AND event_id < ?))';
            array_push($params, $decoded['at'], $decoded['at'], $decoded['id']);
        }
        $queryLimit = $limit + 1;
        $statement = $this->pdo->prepare("
            SELECT * FROM ({$events}) activity
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
                'orderNumber' => (string) $row['order_number'],
                'source' => (string) $row['source_key'],
            ];
            if ($row['kind'] === 'office') {
                // An office entry names no production stage; its detail is the contact outcome, the decision or the reason.
                $item['kind'] = 'office';
                if ($row['detail'] !== null) {
                    $item['detail'] = (string) $row['detail'];
                }
                return $item;
            }
            $item['fromStageId'] = (string) $row['from_stage_id'];
            $item['fromStageLabelSnapshot'] = (string) $row['from_stage_label_snapshot'];
            if ($row['to_stage_id'] !== null) {
                $item['toStageId'] = (string) $row['to_stage_id'];
                $item['toStageLabelSnapshot'] = (string) $row['to_stage_label_snapshot'];
            }
            if ($row['meters_snapshot'] !== null) {
                $item['meters'] = (float) $row['meters_snapshot'];
            }
            return $item;
        }, $rows);

        return ['items' => $items, 'nextCursor' => $nextCursor, 'summary' => $this->summary($employeeUuid, $from, $to, $office)];
    }

    /**
     * The employee's events in the range as one derived table (production first, then the office branches).
     *
     * @return array{string, list<string>}
     */
    private function events(string $employeeUuid, string $from, string $to, bool $office): array
    {
        $branches = ["SELECT event_id, occurred_at, 'production' AS kind, action, NULL AS detail, order_uuid, global_order_id, source_key, order_number_snapshot AS order_number,
                   from_stage_id, from_stage_label_snapshot, to_stage_id, to_stage_label_snapshot, meters_snapshot
            FROM order_activity_events
            WHERE employee_uuid = ? AND occurred_at >= ? AND occurred_at < ? AND action IN (" . self::STAFF_ACTIONS . ")"];
        if ($office) {
            array_push($branches, ...self::OFFICE_BRANCHES);
        }
        $params = [];
        foreach ($branches as $ignored) {
            array_push($params, $employeeUuid, $from, $to);
        }
        return [implode("\n            UNION ALL\n            ", $branches), $params];
    }

    /** @return array{processed: int, meters: float, handedOver: int, inProgress: int, actions?: int} */
    private function summary(string $employeeUuid, string $from, string $to, bool $office): array
    {
        [$events, $params] = $this->events($employeeUuid, $from, $to, $office);
        $statement = $this->pdo->prepare("
            SELECT COUNT(DISTINCT order_uuid) AS processed,
                   COALESCE(SUM(CASE WHEN action IN ('stage_completed', 'production_completed') THEN 1 ELSE 0 END), 0) AS handed_over,
                   COALESCE(SUM(CASE WHEN action IN ('stage_completed', 'production_completed') THEN meters_snapshot ELSE 0 END), 0) AS meters,
                   COALESCE(SUM(CASE WHEN kind = 'office' THEN 1 ELSE 0 END), 0) AS actions
            FROM ({$events}) activity
        ");
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $owned = $this->pdo->prepare('SELECT COUNT(*) FROM operational_orders WHERE production_owner_employee_uuid = ? AND production_completed_at IS NULL');
        $owned->execute([$employeeUuid]);
        $summary = [
            'processed' => (int) ($row['processed'] ?? 0),
            'meters' => round((float) ($row['meters'] ?? 0), 3),
            'handedOver' => (int) ($row['handed_over'] ?? 0),
            'inProgress' => (int) $owned->fetchColumn(),
        ];
        if ($office) {
            $summary['actions'] = (int) ($row['actions'] ?? 0);
        }
        return $summary;
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
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $data['id']) !== 1) {
            return null;
        }
        return ['at' => $data['u'], 'id' => $data['id']];
    }
}
