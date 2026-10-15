<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Cancellation\CancellationSchema;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\OrderFreshness;
use Arasya\Operations\Production\CanonicalProductionWorkflowContract;
use Arasya\Operations\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Read-only, production-wide aggregates for the Dashboard home. Every number is computed in SQL from the
 * operational projection; nothing is cached, sampled or estimated.
 *
 * Definitions (shared with the Dashboard and its tests):
 * - active order: production not completed (production_completed_at IS NULL), not withdrawn by its
 *   source (operational_status <> 'unavailable', i.e. not cancelled) and not stopped inside Arasya
 *   (production_stopped_at IS NULL, API 2.35.0). A stopped or cancelled order is never counted as active
 *   production, and a completion is never erased by a later cancellation (completedToday keeps it);
 * - waiting: active and currently at the first canonical stage, `waiting`;
 * - in work: active and past `waiting`;
 * - unassigned: active with no current production owner. Every canonical stage is claimed before it can
 *   be completed, so ownership applies to every active order;
 * - completed today: production completed within the current Europe/Bucharest calendar day;
 * - stage entered at: when the order reached its current stage (production_changed_at), or when it was
 *   imported if it never moved.
 *
 * Sections that need more than production.view are null when the actor lacks their permission:
 * oldest orders, blockers and PERDEA information (orders.view_all), production activity (activity.view_all), sources
 * (sources.view).
 *
 * Blockers (API 2.34.0) are the server's genuine reasons why an active order cannot move on, each with who has to act:
 *   manager_decision   a production exception waits for an operations manager (awaiting_approval)
 *   physical_receipt   a returned piece waits to be physically received (awaiting_acknowledgment, awaiting_restore_receipt)
 *   supervisor_review  the tailoring supervisor must review, hold or return the piece, or release a physical hold
 *   employee_work      approved rework or pieces on their own route still need production work
 *   plan_review        a manufacturing plan, piece identity or route evidence needs an authorized review (PieceGate)
 * PERDEA auxiliary-work status (stages 6-7, route policy version 2) is information only and never appears as a blocker.
 */
final readonly class ProductionOverviewService
{
    public const TIMEZONE = 'Europe/Bucharest';
    private const OLDEST_LIMIT = 10;
    private const ACTIVITY_LIMIT = 12;
    private const ACTIVE_BASE = "o.production_completed_at IS NULL AND o.operational_status <> 'unavailable'";
    private const NOT_STOPPED = ' AND o.production_stopped_at IS NULL';
    private const CANCELLATION_ITEMS = 50;
    private const RELEASED_RECENT_DAYS = 7;
    private const ENTERED_AT = 'COALESCE(o.production_changed_at, o.created_at)';

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private Clock $clock,
        private Config $config,
    ) {
    }

    /** @param array<string, string> $filters @return array<string, mixed> */
    public function overview(EmployeeIdentity $actor, array $filters): array
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        $this->authorization->require($actor, 'production.view');

        $sources = $this->pdo->query('SELECT source_key, source_type, display_name, status, last_contact_at, last_event_at FROM order_sources ORDER BY display_name, source_key')->fetchAll(PDO::FETCH_ASSOC);
        $source = $filters['source'] ?? '';
        if ($source !== '' && !in_array($source, array_column($sources, 'source_key'), true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Unknown source filter.');
        }
        $scope = $source === '' ? '' : ' AND o.source_key = :source';
        $params = $source === '' ? [] : ['source' => $source];
        $now = $this->clock->now();

        return [
            'generatedAt' => $this->iso($now),
            'timezone' => self::TIMEZONE,
            'filters' => ['source' => $source === '' ? null : $source],
            'summary' => $this->summary($now, $scope, $params),
            'stages' => $this->stages($scope, $params),
            'oldestOrders' => $this->authorization->can($actor, 'orders.view_all') ? $this->oldestOrders($scope, $params) : null,
            'activity' => $this->authorization->can($actor, 'activity.view_all') ? $this->activity($source) : null,
            'sources' => $this->authorization->can($actor, 'sources.view') ? $this->sources($sources, $now) : null,
            'blockers' => $this->authorization->can($actor, 'orders.view_all') ? $this->blockers($scope, $params) : null,
            'perdeaInformation' => $this->authorization->can($actor, 'orders.view_all') ? $this->perdeaInformation($scope, $params) : null,
            'cancellations' => $this->authorization->can($actor, 'orders.view_all') && CancellationSchema::ready($this->pdo) ? $this->cancellations($scope, $params, $now) : null,
        ];
    }

    /** @param array<string, string> $params @return array<string, int> */
    private function summary(DateTimeImmutable $now, string $scope, array $params): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS active,
                    COALESCE(SUM(o.production_stage_id = :waiting_a), 0) AS waiting,
                    COALESCE(SUM(o.production_stage_id <> :waiting_b), 0) AS in_work,
                    COALESCE(SUM(o.production_owner_employee_uuid IS NULL), 0) AS unassigned
             FROM operational_orders o WHERE ' . $this->active() . $scope,
        );
        $statement->execute(['waiting_a' => 'waiting', 'waiting_b' => 'waiting'] + $params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        [$dayStart, $dayEnd] = $this->businessDay($now);
        $completed = $this->pdo->prepare('SELECT COUNT(*) FROM operational_orders o WHERE o.production_completed_at >= :day_start AND o.production_completed_at < :day_end' . $scope);
        $completed->execute(['day_start' => $dayStart, 'day_end' => $dayEnd] + $params);

        return [
            'active' => (int) $row['active'],
            'waiting' => (int) $row['waiting'],
            'inWork' => (int) $row['in_work'],
            'unassigned' => (int) $row['unassigned'],
            'completedToday' => (int) $completed->fetchColumn(),
        ];
    }

    /** One grouped query for all stages; stages without orders report zero. @param array<string, string> $params @return list<array<string, mixed>> */
    private function stages(string $scope, array $params): array
    {
        $workflow = $this->pdo->prepare(
            "SELECT ps.stage_id, ps.display_name, ps.ordinal FROM production_stages ps
             INNER JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id
             WHERE pw.workflow_key = :workflow_key AND pw.version = :version AND pw.status = 'active' AND ps.status = 'active'
             ORDER BY ps.ordinal",
        );
        $workflow->execute(['workflow_key' => CanonicalProductionWorkflowContract::WORKFLOW_ID, 'version' => CanonicalProductionWorkflowContract::VERSION]);
        $counts = $this->pdo->prepare(
            'SELECT o.production_stage_id AS stage_id, COUNT(*) AS active, COALESCE(SUM(o.production_owner_employee_uuid IS NULL), 0) AS unassigned,
                    MIN(' . self::ENTERED_AT . ') AS oldest_entered_at
             FROM operational_orders o WHERE ' . $this->active() . $scope . ' GROUP BY o.production_stage_id',
        );
        $counts->execute($params);
        $byStage = [];
        foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byStage[(string) $row['stage_id']] = $row;
        }
        $stages = [];
        foreach ($workflow->fetchAll(PDO::FETCH_ASSOC) as $stage) {
            $row = $byStage[(string) $stage['stage_id']] ?? null;
            $stages[] = [
                'id' => (string) $stage['stage_id'],
                'label' => (string) $stage['display_name'],
                'ordinal' => (int) $stage['ordinal'],
                'active' => (int) ($row['active'] ?? 0),
                'unassigned' => (int) ($row['unassigned'] ?? 0),
                'oldestEnteredAt' => $row === null ? null : $this->isoSql((string) $row['oldest_entered_at']),
            ];
        }
        return $stages;
    }

    /** @param array<string, string> $params @return list<array<string, mixed>> */
    private function oldestOrders(string $scope, array $params): array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.global_order_id, o.order_number, o.source_key, s.display_name AS source_name, o.production_stage_id, ps.display_name AS stage_label,
                    ' . self::ENTERED_AT . ' AS entered_at, o.source_commerce_status_code, o.source_commerce_status_label,
                    e.employee_uuid AS owner_id, e.display_name AS owner_name, o.production_claimed_at
             FROM operational_orders o
             INNER JOIN order_sources s ON s.source_key = o.source_key
             INNER JOIN production_stages ps ON ps.stage_id = o.production_stage_id
             LEFT JOIN employees e ON e.employee_uuid = o.production_owner_employee_uuid
             WHERE ' . $this->active() . $scope . '
             ORDER BY entered_at ASC, o.order_uuid ASC
             LIMIT ' . self::OLDEST_LIMIT,
        );
        $statement->execute($params);
        return array_map(fn (array $row): array => [
            'globalOrderId' => (string) $row['global_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'source' => ['key' => (string) $row['source_key'], 'name' => (string) $row['source_name']],
            'stage' => ['id' => (string) $row['production_stage_id'], 'label' => (string) $row['stage_label']],
            'stageEnteredAt' => $this->isoSql((string) $row['entered_at']),
            'owner' => $row['owner_id'] === null ? null : ['id' => (string) $row['owner_id'], 'displayName' => (string) $row['owner_name']],
            'claimedAt' => $row['production_claimed_at'] === null ? null : $this->isoSql((string) $row['production_claimed_at']),
            'commerceStatus' => $row['source_commerce_status_code'] === null ? null : [
                'code' => (string) $row['source_commerce_status_code'],
                'label' => $row['source_commerce_status_label'] === null ? null : (string) $row['source_commerce_status_label'],
            ],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private const BLOCKER_LIMIT = 100;
    private const EXCEPTION_ACTIONS = [
        'awaiting_approval' => 'manager_decision', 'awaiting_acknowledgment' => 'physical_receipt', 'awaiting_restore_receipt' => 'physical_receipt',
        'awaiting_supervisor_review' => 'supervisor_review', 'in_supervisor_custody' => 'supervisor_review', 'return_rejected' => 'supervisor_review',
    ];
    private const GATE_ACTIONS = [
        'pieces_in_rework' => 'employee_work', 'pieces_awaiting_reunion' => 'employee_work', 'defect_unresolved' => 'employee_work',
        'pieces_held' => 'supervisor_review', 'plan_confirmation_required' => 'plan_review', 'pieces_incomplete' => 'plan_review', 'route_incomplete' => 'plan_review',
    ];

    /**
     * Genuine blockers of active orders: open production exceptions by state, active rework / route tracks, physical holds
     * and the PieceGate reason of orders that carry physical pieces or wait at packing / delivery. Read-only.
     * @param array<string, string> $params @return array{counts: array<string, int>, items: list<array<string, mixed>>, more: bool}
     */
    private function blockers(string $scope, array $params): array
    {
        $orders = $this->pdo->prepare('SELECT o.order_uuid, o.global_order_id, o.order_number, o.source_key, s.display_name AS source_name, o.production_stage_id, ps.display_name AS stage_label, ' . self::ENTERED_AT . ' AS entered_at
             FROM operational_orders o INNER JOIN order_sources s ON s.source_key = o.source_key INNER JOIN production_stages ps ON ps.stage_id = o.production_stage_id
             WHERE ' . $this->active() . $scope);
        $orders->execute($params);
        $byUuid = [];
        foreach ($orders->fetchAll(PDO::FETCH_ASSOC) as $row) $byUuid[(string) $row['order_uuid']] = $row;
        $items = [];
        $add = function (array $order, string $reason, string $action, ?array $exception = null) use (&$items): void {
            $items[] = [
                'globalOrderId' => (string) $order['global_order_id'], 'orderNumber' => (string) $order['order_number'],
                'source' => ['key' => (string) $order['source_key'], 'name' => (string) $order['source_name']],
                'stage' => ['id' => (string) $order['production_stage_id'], 'label' => (string) $order['stage_label']],
                'reason' => $reason, 'action' => $action, 'exception' => $exception, 'since' => $this->isoSql((string) $order['entered_at']),
            ];
        };
        if ($byUuid !== []) {
            $exceptions = $this->pdo->query("SELECT order_uuid, exception_number, status FROM production_exceptions WHERE resolved_at IS NULL ORDER BY exception_number")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($exceptions as $row) {
                $order = $byUuid[(string) $row['order_uuid']] ?? null;
                if ($order !== null && isset(self::EXCEPTION_ACTIONS[$row['status']])) {
                    $add($order, 'exception_' . $row['status'], self::EXCEPTION_ACTIONS[$row['status']], ['number' => sprintf('EX-%06d', (int) $row['exception_number']), 'status' => (string) $row['status']]);
                }
            }
            try {
                $pieceOrders = array_column($this->pdo->query("SELECT DISTINCT order_uuid FROM production_work_units WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC), 'order_uuid');
                $gate = [];
                foreach ($byUuid as $uuid => $order) {
                    if (in_array($order['production_stage_id'], \Arasya\Operations\Production\PieceGate::GATED_STAGES, true) || in_array($uuid, $pieceOrders, true)) $gate[$uuid] = (string) $order['production_stage_id'];
                }
                $reasons = (new \Arasya\Operations\Production\PieceGate($this->pdo))->reasons($gate);
                $tracks = $this->pdo->query("SELECT order_uuid, origin_exception_uuid, open_exception_uuid FROM production_tracks WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
                $trackReasons = [];
                foreach ($tracks as $track) {
                    if ($track['open_exception_uuid'] !== null) continue; // its open exception is listed above
                    $trackReasons[(string) $track['order_uuid']][$track['origin_exception_uuid'] === null ? 'pieces_awaiting_reunion' : 'pieces_in_rework'] = true;
                }
                foreach ($byUuid as $uuid => $order) {
                    $listed = [];
                    foreach (array_keys($trackReasons[$uuid] ?? []) as $reason) { $add($order, $reason, self::GATE_ACTIONS[$reason]); $listed[$reason] = true; }
                    $reason = $reasons[$uuid] ?? null;
                    if ($reason !== null && !isset($listed[$reason]) && isset(self::GATE_ACTIONS[$reason])) $add($order, $reason, self::GATE_ACTIONS[$reason]);
                }
            } catch (\PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
                // Before migrations 028/029 no order has physical pieces.
            }
        }
        $counts = ['manager_decision' => 0, 'physical_receipt' => 0, 'supervisor_review' => 0, 'employee_work' => 0, 'plan_review' => 0];
        foreach ($items as $item) $counts[$item['action']]++;
        usort($items, static fn (array $a, array $b): int => [$a['since'], $a['globalOrderId']] <=> [$b['since'], $b['globalOrderId']]);
        return ['counts' => $counts, 'items' => array_slice($items, 0, self::BLOCKER_LIMIT), 'more' => count($items) > self::BLOCKER_LIMIT];
    }

    /** Active production: not completed, not cancelled by the source and, once migration 033 exists, not stopped. */
    private function active(): string
    {
        return self::ACTIVE_BASE . (CancellationSchema::ready($this->pdo) ? self::NOT_STOPPED : '');
    }

    /**
     * Cancellation control (API 2.35.0): every stopped order and the releases of the last seven days, one category per
     * order (CancellationView), with the party that acts next. Counts cover every row; the list is bounded.
     * @param array<string, string> $params @return array<string, mixed>
     */
    private function cancellations(string $scope, array $params, DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare('SELECT c.order_uuid, c.hold_status, c.origin, c.reason_code, c.source_status, c.source_request_status, c.production_phase, c.physical_status,
                c.physical_outcome, c.held_at, c.released_at, c.version, o.global_order_id, o.order_number, o.source_key, s.display_name AS source_name,
                o.production_stage_id, ps.display_name AS stage_label, o.production_completed_at
             FROM order_cancellation_controls c
             INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid
             INNER JOIN order_sources s ON s.source_key = o.source_key
             INNER JOIN production_stages ps ON ps.stage_id = o.production_stage_id
             WHERE (c.hold_status = :held OR c.released_at >= :released_since)' . $scope . '
             ORDER BY c.held_at DESC, c.order_uuid DESC');
        $statement->execute(['held' => 'held', 'released_since' => $now->modify('-' . self::RELEASED_RECENT_DAYS . ' days')->format('Y-m-d H:i:s.u')] + $params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $counts = array_fill_keys(\Arasya\Operations\Cancellation\CancellationView::CATEGORIES, 0);
        $actions = ['physical_reconciliation' => 0, 'source_confirmation' => 0, 'release_decision' => 0];
        $items = [];
        foreach ($rows as $row) {
            $category = \Arasya\Operations\Cancellation\CancellationView::category($row);
            $next = \Arasya\Operations\Cancellation\CancellationView::nextAction($row);
            $counts[$category]++;
            if (isset($actions[$next])) $actions[$next]++;
            $items[] = [
                'globalOrderId' => (string) $row['global_order_id'], 'orderNumber' => (string) $row['order_number'],
                'source' => ['key' => (string) $row['source_key'], 'name' => (string) $row['source_name']],
                'stage' => ['id' => (string) $row['production_stage_id'], 'label' => (string) $row['stage_label']],
                'category' => $category, 'nextAction' => $next, 'origin' => (string) $row['origin'], 'reason' => (string) $row['reason_code'],
                'physical' => (string) $row['physical_status'], 'physicalOutcome' => $row['physical_outcome'] === null ? null : (string) $row['physical_outcome'],
                'productionCompleted' => $row['production_completed_at'] !== null,
                'heldAt' => $this->isoSql((string) $row['held_at']), 'releasedAt' => $row['released_at'] === null ? null : $this->isoSql((string) $row['released_at']),
            ];
        }
        return [
            'counts' => $counts,
            'stopped' => count($rows) - $counts['released'],
            'physicalPending' => count(array_filter($rows, static fn (array $row): bool => $row['hold_status'] === 'held' && $row['physical_status'] === 'pending')),
            'nextActions' => $actions,
            'items' => array_slice($items, 0, self::CANCELLATION_ITEMS),
            'more' => count($items) > self::CANCELLATION_ITEMS,
            'releasedWindowDays' => self::RELEASED_RECENT_DAYS,
        ];
    }

    /** Informational PERDEA auxiliary-work status of active orders (route policy version 2); never a blocker. @param array<string, string> $params @return array<string, mixed> */
    private function perdeaInformation(string $scope, array $params): array
    {
        $orders = $this->pdo->prepare('SELECT o.order_uuid FROM operational_orders o WHERE ' . $this->active() . $scope);
        $orders->execute($params);
        return ['informational' => true] + (new \Arasya\Operations\Production\AssistedCheckpoints($this->pdo))->overview(array_column($orders->fetchAll(PDO::FETCH_ASSOC), 'order_uuid'), 50);
    }

    /** Latest production events from the immutable activity log; IAM audit is a separate concern. @return list<array<string, mixed>> */
    private function activity(string $source): array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.event_id, a.action, a.order_number_snapshot, a.global_order_id, a.source_key, a.from_stage_id, a.from_stage_label_snapshot,
                    a.to_stage_id, a.to_stage_label_snapshot, a.occurred_at, e.employee_uuid, e.display_name,
                    a.previous_owner_employee_uuid, pe.display_name AS previous_owner_name, a.new_owner_employee_uuid, ne.display_name AS new_owner_name
             FROM order_activity_events a
             INNER JOIN employees e ON e.employee_uuid = a.employee_uuid
             LEFT JOIN employees pe ON pe.employee_uuid = a.previous_owner_employee_uuid
             LEFT JOIN employees ne ON ne.employee_uuid = a.new_owner_employee_uuid'
            . ($source === '' ? '' : ' WHERE a.source_key = :source')
            . ' ORDER BY a.occurred_at DESC, a.event_id DESC LIMIT ' . self::ACTIVITY_LIMIT,
        );
        $statement->execute($source === '' ? [] : ['source' => $source]);
        return array_map(fn (array $row): array => [
            'id' => (string) $row['event_id'],
            'action' => (string) $row['action'],
            'occurredAt' => $this->isoSql((string) $row['occurred_at']),
            'employee' => ['id' => (string) $row['employee_uuid'], 'displayName' => (string) $row['display_name']],
            'order' => ['globalOrderId' => (string) $row['global_order_id'], 'orderNumber' => (string) $row['order_number_snapshot'], 'source' => (string) $row['source_key']],
            'fromStage' => ['id' => (string) $row['from_stage_id'], 'label' => (string) $row['from_stage_label_snapshot']],
            'toStage' => $row['to_stage_id'] === null ? null : ['id' => (string) $row['to_stage_id'], 'label' => (string) $row['to_stage_label_snapshot']],
            'previousOwner' => $row['previous_owner_employee_uuid'] === null ? null : ['id' => (string) $row['previous_owner_employee_uuid'], 'displayName' => (string) $row['previous_owner_name']],
            'newOwner' => $row['new_owner_employee_uuid'] === null ? null : ['id' => (string) $row['new_owner_employee_uuid'], 'displayName' => (string) $row['new_owner_name']],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Health per registered source. `not_configured` means the API holds no credential for it, so silence is
     * expected and never reported as an outage; `disabled` is an inactive source; otherwise the age of the
     * last signed contact (event or heartbeat) decides `healthy`, `stale` or `offline` with the same
     * thresholds the order freshness uses, and `no_contact` means configured but never heard from.
     *
     * @param list<array<string, mixed>> $sources @return list<array<string, mixed>>
     */
    private function sources(array $sources, DateTimeImmutable $now): array
    {
        $active = [];
        foreach ($this->pdo->query('SELECT o.source_key, COUNT(*) FROM operational_orders o WHERE ' . $this->active() . ' GROUP BY o.source_key')->fetchAll(PDO::FETCH_NUM) as [$key, $count]) {
            $active[(string) $key] = (int) $count;
        }
        return array_map(function (array $row) use ($now, $active): array {
            $key = (string) $row['source_key'];
            $configured = $key === 'trendyol' ? $this->config->trendyol !== null : isset($this->config->sourceSecrets[$key]);
            $lastContact = $row['last_contact_at'] === null ? null : new DateTimeImmutable((string) $row['last_contact_at'], new DateTimeZone('UTC'));
            $health = match (true) {
                $row['status'] !== 'active' => 'disabled',
                $key === 'b2b' && $row['source_type'] === 'internal' => 'healthy',
                !$configured => 'not_configured',
                $lastContact === null => 'no_contact',
                default => match (OrderFreshness::classify('active', $lastContact, $now, $this->config->sourceFreshSeconds, $this->config->sourceUnavailableSeconds)) {
                    'fresh' => 'healthy',
                    'stale' => 'stale',
                    default => 'offline',
                },
            };
            return [
                'key' => $key,
                'name' => (string) $row['display_name'],
                'type' => (string) $row['source_type'],
                'health' => $health,
                'lastContactAt' => $lastContact === null ? null : $this->iso($lastContact),
                'lastEventAt' => $row['last_event_at'] === null ? null : $this->isoSql((string) $row['last_event_at']),
                'activeOrders' => $active[$key] ?? 0,
            ];
        }, $sources);
    }

    /** UTC bounds of the Europe/Bucharest calendar day containing $now, as SQL DATETIME strings. @return array{string, string} */
    private function businessDay(DateTimeImmutable $now): array
    {
        $local = $now->setTimezone(new DateTimeZone(self::TIMEZONE));
        $start = $local->setTime(0, 0);
        $end = $start->modify('+1 day');
        $utc = new DateTimeZone('UTC');
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s.u'), $end->setTimezone($utc)->format('Y-m-d H:i:s.u')];
    }

    private function isoSql(string $value): string
    {
        return $this->iso(new DateTimeImmutable($value, new DateTimeZone('UTC')));
    }

    private function iso(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
