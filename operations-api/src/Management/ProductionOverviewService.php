<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Authorization\AuthorizationService;
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
 * - active order: production not completed (production_completed_at IS NULL) and not withdrawn by its
 *   source (operational_status <> 'unavailable', i.e. not cancelled);
 * - waiting: active and currently at the first canonical stage, `waiting`;
 * - in work: active and past `waiting`;
 * - unassigned: active with no current production owner. Every canonical stage is claimed before it can
 *   be completed, so ownership applies to every active order;
 * - completed today: production completed within the current Europe/Bucharest calendar day;
 * - stage entered at: when the order reached its current stage (production_changed_at), or when it was
 *   imported if it never moved.
 *
 * Sections that need more than production.view are null when the actor lacks their permission:
 * oldest orders (orders.view_all), production activity (activity.view_all), sources (sources.view).
 */
final readonly class ProductionOverviewService
{
    public const TIMEZONE = 'Europe/Bucharest';
    private const OLDEST_LIMIT = 10;
    private const ACTIVITY_LIMIT = 12;
    private const ACTIVE = "o.production_completed_at IS NULL AND o.operational_status <> 'unavailable'";
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
             FROM operational_orders o WHERE ' . self::ACTIVE . $scope,
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
             FROM operational_orders o WHERE ' . self::ACTIVE . $scope . ' GROUP BY o.production_stage_id',
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
             WHERE ' . self::ACTIVE . $scope . '
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
        foreach ($this->pdo->query('SELECT o.source_key, COUNT(*) FROM operational_orders o WHERE ' . self::ACTIVE . ' GROUP BY o.source_key')->fetchAll(PDO::FETCH_NUM) as [$key, $count]) {
            $active[(string) $key] = (int) $count;
        }
        return array_map(function (array $row) use ($now, $active): array {
            $key = (string) $row['source_key'];
            $configured = $key === 'trendyol' ? $this->config->trendyol !== null : isset($this->config->sourceSecrets[$key]);
            $lastContact = $row['last_contact_at'] === null ? null : new DateTimeImmutable((string) $row['last_contact_at'], new DateTimeZone('UTC'));
            $health = match (true) {
                $row['status'] !== 'active' => 'disabled',
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
