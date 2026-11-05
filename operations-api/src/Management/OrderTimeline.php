<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Quality\ExceptionQueries;
use PDO;

/**
 * The complete, read-only event history of one order for the Dashboard exact order lookup (API 2.59.0): who moved the
 * order through which stage and when. It only reads the existing append-only event tables; nothing new is recorded.
 *
 *   intake        Trendyol intake (received, marketplace status changes, line preparation, release), Trendyol orders only
 *   confirmation  Confirmare order confirmation (opened, assigned, claimed, approved...), shop orders
 *   production    stage claims, stage completions, submissions, faults, custody (order_activity_events)
 *   document      production document generated, printed, revised, revoked
 *   qr            production QR issued, rotated, revoked
 *   exception     production exception workflow (reported, approved, rejected...)
 *   cancellation  source cancellation control (received, hold, release...)
 *
 * Operational data only: employee display names, stage labels, codes, sizes and product titles. No customer data, no
 * contact data, no amount, no finance record, no free-text comment, no request payload.
 */
final readonly class OrderTimeline
{
    public const LIMIT = 1000;

    /** Trendyol intake detail keys that are operational codes and sizes, never customer data. */
    private const INTAKE_KEYS = ['status', 'from', 'to', 'lines', 'kind', 'widthCm', 'heightCm', 'meters', 'productionTitle', 'stageId'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{items: list<array<string, mixed>>, truncated: bool} */
    public function of(string $orderUuid, string $globalOrderId, string $sourceKey): array
    {
        $events = [];
        $actor = 'LEFT JOIN employees e ON e.employee_uuid = %s LEFT JOIN departments d ON d.department_id = e.department_id';
        $actorColumns = 'e.display_name AS actor_name, d.name AS actor_department';

        if ($sourceKey === 'trendyol' && str_starts_with($globalOrderId, 'trendyol:')) {
            foreach ($this->rows(
                "SELECT t.event_id AS id, t.occurred_at AS at, t.action, t.actor_label, t.details, {$actorColumns}
                 FROM trendyol_intake_events t " . sprintf($actor, 't.actor_employee_uuid') . '
                 WHERE t.package_id = ? ORDER BY t.occurred_at, t.event_id',
                [substr($globalOrderId, strlen('trendyol:'))],
            ) as $row) {
                $events[] = $this->event('intake', $row, ['detail' => $this->intakeDetail($row['details'])]);
            }
        }

        foreach ($this->rows(
            "SELECT c.event_uuid AS id, c.occurred_at AS at, c.action, c.actor_kind, c.previous_status, c.new_status, n.display_name AS assignee_name, {$actorColumns}
             FROM order_confirmation_events c " . sprintf($actor, 'c.actor_employee_uuid') . '
             LEFT JOIN employees n ON n.employee_uuid = c.new_assignee_uuid
             WHERE c.order_uuid = ? ORDER BY c.occurred_at, c.version_after',
            [$orderUuid],
        ) as $row) {
            $events[] = $this->event('confirmation', $row, ['detail' => array_filter([
                'from' => $row['previous_status'],
                'to' => $row['new_status'],
                'assignee' => $row['assignee_name'],
                'system' => $row['actor_kind'] === 'system' ? true : null,
            ], static fn (mixed $value): bool => $value !== null)]);
        }

        foreach ($this->rows(
            "SELECT a.event_id AS id, a.occurred_at AS at, a.action, a.from_stage_id, COALESCE(a.from_stage_label_snapshot, fs.display_name) AS from_label,
                    a.to_stage_id, COALESCE(a.to_stage_label_snapshot, ts.display_name) AS to_label, a.meters_snapshot, a.production_version_after, {$actorColumns}
             FROM order_activity_events a " . sprintf($actor, 'a.employee_uuid') . '
             LEFT JOIN production_stages fs ON fs.stage_id = a.from_stage_id
             LEFT JOIN production_stages ts ON ts.stage_id = a.to_stage_id
             WHERE a.order_uuid = ? ORDER BY a.occurred_at, a.production_version_after',
            [$orderUuid],
        ) as $row) {
            $events[] = $this->event('production', $row, [
                'fromStage' => $row['from_stage_id'] === null ? null : ['id' => (string) $row['from_stage_id'], 'label' => (string) ($row['from_label'] ?? $row['from_stage_id'])],
                'toStage' => $row['to_stage_id'] === null ? null : ['id' => (string) $row['to_stage_id'], 'label' => (string) ($row['to_label'] ?? $row['to_stage_id'])],
                'detail' => $row['meters_snapshot'] === null ? [] : ['meters' => (string) $row['meters_snapshot']],
            ]);
        }

        foreach ($this->rows(
            "SELECT v.event_uuid AS id, v.occurred_at AS at, v.event_type AS action, v.revision_number, {$actorColumns}
             FROM production_document_events v " . sprintf($actor, 'v.actor_employee_uuid') . '
             WHERE v.order_uuid = ? ORDER BY v.occurred_at, v.event_seq',
            [$orderUuid],
        ) as $row) {
            $events[] = $this->event('document', $row, ['detail' => $row['revision_number'] === null ? [] : ['revision' => (int) $row['revision_number']]]);
        }

        foreach ($this->rows(
            "SELECT q.event_uuid AS id, q.occurred_at AS at, q.action, q.qr_revision, q.reason_code, {$actorColumns}
             FROM production_qr_events q " . sprintf($actor, 'q.actor_employee_uuid') . '
             WHERE q.order_uuid = ? ORDER BY q.occurred_at, q.qr_revision',
            [$orderUuid],
        ) as $row) {
            $events[] = $this->event('qr', $row, ['detail' => array_filter(['revision' => $row['qr_revision'] === null ? null : (int) $row['qr_revision'], 'reason' => $row['reason_code']], static fn (mixed $value): bool => $value !== null)]);
        }

        foreach ($this->rows(
            "SELECT x.event_uuid AS id, x.occurred_at AS at, x.action, p.exception_number, {$actorColumns}
             FROM production_exception_events x
             INNER JOIN production_exceptions p ON p.exception_uuid = x.exception_uuid " . sprintf($actor, 'x.actor_employee_uuid') . '
             WHERE p.order_uuid = ? ORDER BY x.occurred_at, x.exception_version_after',
            [$orderUuid],
        ) as $row) {
            $events[] = $this->event('exception', $row, ['detail' => ['exception' => ExceptionQueries::number((int) $row['exception_number'])]]);
        }

        foreach ($this->rows(
            "SELECT k.event_uuid AS id, k.occurred_at AS at, k.event_type AS action, k.production_stage_id, {$actorColumns}
             FROM order_cancellation_events k " . sprintf($actor, 'k.actor_employee_uuid') . '
             WHERE k.order_uuid = ? ORDER BY k.occurred_at, k.event_seq',
            [$orderUuid],
        ) as $row) {
            $events[] = $this->event('cancellation', $row, ['detail' => $row['production_stage_id'] === null ? [] : ['stageId' => (string) $row['production_stage_id']]]);
        }

        // Stable chronological order; at the same instant the natural business order (intake before production, a
        // production submission before the document and QR it creates).
        $rank = ['intake' => 0, 'confirmation' => 1, 'production' => 2, 'qr' => 3, 'document' => 4, 'exception' => 5, 'cancellation' => 6];
        foreach ($events as $index => &$event) {
            $event['_sort'] = [(string) $event['at'], $rank[$event['kind']], $index];
        }
        unset($event);
        usort($events, static fn (array $a, array $b): int => $a['_sort'] <=> $b['_sort']);
        $truncated = count($events) > self::LIMIT;
        $events = array_slice($events, -self::LIMIT);
        return ['items' => array_map(static function (array $event): array {
            unset($event['_sort']);
            return $event;
        }, $events), 'truncated' => $truncated];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $extra @return array<string, mixed> */
    private function event(string $kind, array $row, array $extra): array
    {
        $actor = null;
        if (($row['actor_name'] ?? null) !== null) {
            $actor = ['displayName' => (string) $row['actor_name'], 'department' => $row['actor_department'] === null ? null : (string) $row['actor_department']];
        }
        return [
            'id' => $kind . ':' . $row['id'],
            'at' => ExceptionQueries::iso((string) $row['at']),
            'kind' => $kind,
            'action' => (string) $row['action'],
            'actor' => $actor,
            'system' => $actor === null ? (is_string($row['actor_label'] ?? null) && $row['actor_label'] !== '' ? (string) $row['actor_label'] : 'system') : null,
            'fromStage' => $extra['fromStage'] ?? null,
            'toStage' => $extra['toStage'] ?? null,
            'detail' => (object) ($extra['detail'] ?? []),
        ];
    }

    /** @return array<string, scalar> */
    private function intakeDetail(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $detail = [];
        foreach (self::INTAKE_KEYS as $key) {
            $value = $decoded[$key] ?? null;
            if (is_scalar($value) && $value !== '') {
                $detail[$key] = is_string($value) ? mb_substr($value, 0, 160) : $value;
            }
        }
        return $detail;
    }

    /** @param list<mixed> $parameters @return list<array<string, mixed>> */
    private function rows(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql . ' LIMIT ' . (self::LIMIT + 1));
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
