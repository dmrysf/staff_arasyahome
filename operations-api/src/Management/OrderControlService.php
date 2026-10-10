<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Order\OrderLookupCode;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Production Control order workspace for the Dashboard (reads). Supervisor owner interventions live
 * in OrderOwnershipService; nothing in this class writes.
 *
 * Two independent state machines are reported side by side and never derived from each other:
 * - commerce: the source's own status (e.g. WooCommerce "processing"), received inbound only;
 * - production: the canonical curtain-production@1 stage, owner and completion, owned by Operations.
 * No Operations code path sends anything back to a source.
 *
 * Access: dashboard application + orders.view_all. The production timeline additionally needs
 * activity.view_all (otherwise `activity` is null). Rows carry no customer personal data; the source
 * contract never delivers it.
 */
final readonly class OrderControlService
{
    public const PAGE_SIZES = [25, 50, 100];
    private const DEFAULT_LIMIT = 50;
    private const ACTIVITY_LIMIT = 500;
    private const STATES = ['active', 'completed', 'cancelled'];
    private const ENTERED_AT = 'COALESCE(o.production_changed_at, o.created_at)';
    private const OWNER_IS_ROOT = 'EXISTS (SELECT 1 FROM system_root_identity sri WHERE sri.employee_uuid = o.production_owner_employee_uuid)';
    private const OWNER_ACTIVE = "(e.status = 'active' AND (od.status = 'active' OR " . self::OWNER_IS_ROOT . '))';
    private const OWNER_STAFF = '(' . self::OWNER_IS_ROOT . " OR EXISTS (SELECT 1 FROM employee_application_access eaa INNER JOIN applications a ON a.application_key = eaa.application_key AND a.status = 'active'
            WHERE eaa.employee_uuid = o.production_owner_employee_uuid AND eaa.application_key = 'staff'))";
    /** The owner still works at the order's stage for its source: the stage grant and, when scoped, the source (migration 023). */
    private const OWNER_STAGE = 'EXISTS (SELECT 1 FROM employee_stage_access esa WHERE esa.employee_uuid = o.production_owner_employee_uuid AND esa.stage_id = o.production_stage_id
            AND (NOT EXISTS (SELECT 1 FROM employee_stage_source_scopes sc WHERE sc.employee_uuid = esa.employee_uuid AND sc.stage_id = esa.stage_id)
                 OR EXISTS (SELECT 1 FROM employee_stage_source_scopes sc WHERE sc.employee_uuid = esa.employee_uuid AND sc.stage_id = esa.stage_id AND sc.source_key = o.source_key)))';
    private const ACTIVE_STATE = "o.production_completed_at IS NULL AND o.operational_status <> 'unavailable'";
    private const SUMMARY = 'SELECT o.order_uuid, o.global_order_id, o.order_number, o.source_key, s.display_name AS source_name,
            o.source_commerce_status_code, o.source_commerce_status_label, o.operational_status,
            o.production_stage_id, ps.display_name AS stage_label, ps.ordinal AS stage_ordinal,
            o.production_owner_employee_uuid, e.display_name AS owner_name, o.production_claimed_at,
            o.production_changed_at, o.production_completed_at, o.created_at, o.accepted_at, o.open_exception_uuid,
            ' . self::OWNER_ACTIVE . ' AS owner_active, ' . self::OWNER_STAFF . ' AS owner_staff, ' . self::OWNER_STAGE . ' AS owner_stage
        FROM operational_orders o
        INNER JOIN order_sources s ON s.source_key = o.source_key
        INNER JOIN production_stages ps ON ps.stage_id = o.production_stage_id
        LEFT JOIN employees e ON e.employee_uuid = o.production_owner_employee_uuid
        LEFT JOIN departments od ON od.department_id = e.department_id';

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
    ) {
    }

    /** @param array<string, string> $filters @return array<string, mixed> */
    public function list(EmployeeIdentity $actor, array $filters): array
    {
        $this->requireAccess($actor);
        $limit = (int) ($filters['limit'] ?? self::DEFAULT_LIMIT);
        if (!in_array($limit, self::PAGE_SIZES, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'limit must be 25, 50 or 100.');
        }
        [$where, $params] = $this->where($filters);
        if (($filters['cursor'] ?? '') !== '') {
            $cursor = $this->decodeCursor($filters['cursor']) ?? throw new ApiException(400, 'INVALID_CURSOR', 'The provided cursor is invalid.');
            $where[] = '(o.created_at < :cursor_at OR (o.created_at = :cursor_at_eq AND o.order_uuid < :cursor_id))';
            $params += ['cursor_at' => $cursor['at'], 'cursor_at_eq' => $cursor['at'], 'cursor_id' => $cursor['id']];
        }
        $statement = $this->pdo->prepare(self::SUMMARY . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY o.created_at DESC, o.order_uuid DESC LIMIT ' . ($limit + 1));
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[count($rows) - 1];
            $next = $this->encodeCursor((string) $last['created_at'], (string) $last['order_uuid']);
        }
        return [
            'items' => array_map(fn (array $row): array => $this->summary($row), $rows),
            'nextCursor' => $next,
            'facets' => $this->facets(),
            'counts' => $this->counts(),
        ];
    }

    /** Supervisor counters over active production, independent of the list filters. @return array{unassignedActive: int, ownerAttention: int} */
    private function counts(): array
    {
        $row = $this->pdo->query('SELECT COALESCE(SUM(o.production_owner_employee_uuid IS NULL), 0) AS unassigned,
                COALESCE(SUM(o.production_owner_employee_uuid IS NOT NULL AND NOT (' . self::OWNER_ACTIVE . ' AND ' . self::OWNER_STAFF . ' AND ' . self::OWNER_STAGE . ')), 0) AS attention
            FROM operational_orders o
            LEFT JOIN employees e ON e.employee_uuid = o.production_owner_employee_uuid
            LEFT JOIN departments od ON od.department_id = e.department_id
            WHERE ' . self::ACTIVE_STATE)->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['unassignedActive' => (int) ($row['unassigned'] ?? 0), 'ownerAttention' => (int) ($row['attention'] ?? 0)];
    }

    /** @return array<string, mixed> */
    public function detail(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $this->requireAccess($actor);
        try {
            $globalId = GlobalOrderId::fromString($globalOrderId)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order was not found.');
        }
        $statement = $this->pdo->prepare(self::SUMMARY . ' WHERE o.global_order_id = :global_id LIMIT 1');
        $statement->execute(['global_id' => $globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order was not found.');
        $extra = $this->pdo->prepare('SELECT production_notes, source_changed_at, last_source_seen_at, production_version FROM operational_orders WHERE order_uuid = :id');
        $extra->execute(['id' => $row['order_uuid']]);
        $more = $extra->fetch(PDO::FETCH_ASSOC);

        $items = $this->pdo->prepare('SELECT line_number, name, product_code, variant, color, width_value, height_value, measurement_unit, meters, quantity
            FROM operational_order_items WHERE order_uuid = :id ORDER BY line_number');
        $items->execute(['id' => $row['order_uuid']]);

        $detail = $this->summary($row);
        $detail['production'] += [
            'changedAt' => $this->isoOrNull($row['production_changed_at']),
            'version' => (int) $more['production_version'],
            'notes' => $more['production_notes'] === null ? null : (string) $more['production_notes'],
            'control' => [
                'canManageOwner' => $this->authorization->can($actor, OrderOwnershipService::PERMISSION),
                'blockedReason' => OrderOwnershipService::blockedReason($row),
            ],
        ];
        $detail['commerce'] += [
            'sourceChangedAt' => $this->iso((string) $more['source_changed_at']),
            'lastSourceSeenAt' => $this->iso((string) $more['last_source_seen_at']),
        ];
        $detail['items'] = array_map(static fn (array $item): array => [
            'line' => (int) $item['line_number'],
            'name' => (string) $item['name'],
            'sku' => $item['product_code'] === null ? null : (string) $item['product_code'],
            'variant' => $item['variant'] === null ? null : (string) $item['variant'],
            'color' => $item['color'] === null ? null : (string) $item['color'],
            'width' => $item['width_value'] === null ? null : (float) $item['width_value'],
            'height' => $item['height_value'] === null ? null : (float) $item['height_value'],
            'unit' => $item['measurement_unit'] === null ? null : (string) $item['measurement_unit'],
            'meters' => $item['meters'] === null ? null : (float) $item['meters'],
            'quantity' => (int) $item['quantity'],
        ], $items->fetchAll(PDO::FETCH_ASSOC));
        $detail['activity'] = null;
        $detail['activityTruncated'] = false;
        if ($this->authorization->can($actor, 'activity.view_all')) {
            $activity = $this->pdo->prepare('SELECT a.event_id, a.action, a.occurred_at, a.from_stage_id, a.from_stage_label_snapshot, a.to_stage_id, a.to_stage_label_snapshot,
                    a.production_version_after, e.employee_uuid, e.display_name,
                    a.previous_owner_employee_uuid, pe.display_name AS previous_owner_name, a.new_owner_employee_uuid, ne.display_name AS new_owner_name
                FROM order_activity_events a INNER JOIN employees e ON e.employee_uuid = a.employee_uuid
                LEFT JOIN employees pe ON pe.employee_uuid = a.previous_owner_employee_uuid
                LEFT JOIN employees ne ON ne.employee_uuid = a.new_owner_employee_uuid
                WHERE a.order_uuid = :id ORDER BY a.occurred_at ASC, a.production_version_after ASC LIMIT ' . (self::ACTIVITY_LIMIT + 1));
            $activity->execute(['id' => $row['order_uuid']]);
            $events = $activity->fetchAll(PDO::FETCH_ASSOC);
            $detail['activityTruncated'] = count($events) > self::ACTIVITY_LIMIT;
            $detail['activity'] = array_map(fn (array $event): array => [
                'id' => (string) $event['event_id'],
                'action' => (string) $event['action'],
                'occurredAt' => $this->iso((string) $event['occurred_at']),
                'employee' => ['id' => (string) $event['employee_uuid'], 'displayName' => (string) $event['display_name']],
                'fromStage' => ['id' => (string) $event['from_stage_id'], 'label' => (string) $event['from_stage_label_snapshot']],
                'toStage' => $event['to_stage_id'] === null ? null : ['id' => (string) $event['to_stage_id'], 'label' => (string) $event['to_stage_label_snapshot']],
                'previousOwner' => $event['previous_owner_employee_uuid'] === null ? null : ['id' => (string) $event['previous_owner_employee_uuid'], 'displayName' => (string) $event['previous_owner_name']],
                'newOwner' => $event['new_owner_employee_uuid'] === null ? null : ['id' => (string) $event['new_owner_employee_uuid'], 'displayName' => (string) $event['new_owner_name']],
                'productionVersion' => (int) $event['production_version_after'],
            ], array_slice($events, 0, self::ACTIVITY_LIMIT));
        }
        return $detail;
    }

    private function requireAccess(EmployeeIdentity $actor): void
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        $this->authorization->require($actor, 'orders.view_all');
    }

    /** @param array<string, string> $filters @return array{list<string>, array<string, string>} */
    private function where(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $code = OrderLookupCode::fromOrderNumber(mb_substr($search, 0, 120));
            $where[] = '(o.order_lookup_code LIKE :search_code OR o.global_order_id = :search_global)';
            $params['search_code'] = addcslashes($code, '%_\\') . '%';
            $params['search_global'] = mb_substr($search, 0, 191);
        }
        foreach (['source' => 'o.source_key', 'stage' => 'o.production_stage_id', 'commerceStatus' => 'o.source_commerce_status_code'] as $name => $column) {
            if (($filters[$name] ?? '') !== '') {
                $where[] = "{$column} = :{$name}";
                $params[$name] = mb_substr($filters[$name], 0, 100);
            }
        }
        if (($filters['ownerId'] ?? '') !== '') {
            if (preg_match('/^[0-9a-f-]{36}$/D', $filters['ownerId']) !== 1) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'ownerId must be an employee id.');
            }
            $where[] = 'o.production_owner_employee_uuid = :owner';
            $params['owner'] = $filters['ownerId'];
        }
        $where[] = match ($filters['assignment'] ?? '') {
            '' => '1 = 1',
            'assigned' => 'o.production_owner_employee_uuid IS NOT NULL',
            'unassigned' => 'o.production_owner_employee_uuid IS NULL',
            'owner_attention' => 'o.production_owner_employee_uuid IS NOT NULL AND NOT (' . self::OWNER_ACTIVE . ' AND ' . self::OWNER_STAFF . ' AND ' . self::OWNER_STAGE . ')',
            default => throw new ApiException(422, 'VALIDATION_FAILED', 'assignment must be assigned, unassigned or owner_attention.'),
        };
        $where[] = match ($filters['state'] ?? '') {
            '' => '1 = 1',
            'active' => self::ACTIVE_STATE,
            'completed' => 'o.production_completed_at IS NOT NULL',
            'cancelled' => "o.production_completed_at IS NULL AND o.operational_status = 'unavailable'",
            default => throw new ApiException(422, 'VALIDATION_FAILED', 'state must be active, completed or cancelled.'),
        };
        return [$where, $params];
    }

    /** Filter choices from indexed columns only; never a scan of order history. @return array<string, mixed> */
    private function facets(): array
    {
        return [
            'sources' => array_map(static fn (array $row): array => ['key' => (string) $row['source_key'], 'name' => (string) $row['display_name']],
                $this->pdo->query('SELECT source_key, display_name FROM order_sources ORDER BY display_name, source_key')->fetchAll(PDO::FETCH_ASSOC)),
            'stages' => array_map(static fn (array $row): array => ['id' => (string) $row['stage_id'], 'label' => (string) $row['display_name'], 'ordinal' => (int) $row['ordinal']],
                $this->pdo->query("SELECT ps.stage_id, ps.display_name, ps.ordinal FROM production_stages ps INNER JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id
                    WHERE pw.workflow_key = 'curtain-production' AND pw.version = 1 AND pw.status = 'active' AND ps.status = 'active' ORDER BY ps.ordinal")->fetchAll(PDO::FETCH_ASSOC)),
            'commerceStatuses' => array_map(static fn (array $row): array => ['code' => (string) $row['code'], 'label' => $row['label'] === null ? null : (string) $row['label']],
                $this->pdo->query('SELECT source_commerce_status_code AS code, MIN(source_commerce_status_label) AS label FROM operational_orders
                    WHERE source_commerce_status_code IS NOT NULL GROUP BY source_commerce_status_code ORDER BY source_commerce_status_code LIMIT 100')->fetchAll(PDO::FETCH_ASSOC)),
            'owners' => array_map(static fn (array $row): array => ['id' => (string) $row['employee_uuid'], 'displayName' => (string) $row['display_name']],
                $this->pdo->query('SELECT e.employee_uuid, e.display_name FROM employees e
                    WHERE e.employee_uuid IN (SELECT o.production_owner_employee_uuid FROM operational_orders o WHERE o.production_owner_employee_uuid IS NOT NULL)
                    ORDER BY e.display_name, e.employee_uuid LIMIT 200')->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function summary(array $row): array
    {
        $state = $row['production_completed_at'] !== null ? 'completed' : ($row['operational_status'] === 'unavailable' ? 'cancelled' : 'active');
        return [
            'globalOrderId' => (string) $row['global_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'source' => ['key' => (string) $row['source_key'], 'name' => (string) $row['source_name']],
            'commerce' => [
                'status' => $row['source_commerce_status_code'] === null ? null : [
                    'code' => (string) $row['source_commerce_status_code'],
                    'label' => $row['source_commerce_status_label'] === null ? null : (string) $row['source_commerce_status_label'],
                ],
                'availability' => $row['operational_status'] === 'unavailable' ? 'cancelled' : 'active',
            ],
            'production' => [
                'state' => $state,
                'stage' => ['id' => (string) $row['production_stage_id'], 'label' => (string) $row['stage_label'], 'ordinal' => (int) $row['stage_ordinal']],
                'owner' => $row['production_owner_employee_uuid'] === null ? null : ['id' => (string) $row['production_owner_employee_uuid'], 'displayName' => (string) $row['owner_name']],
                'claimedAt' => $this->isoOrNull($row['production_claimed_at']),
                'stageEnteredAt' => $this->iso((string) ($row['production_changed_at'] ?? $row['created_at'])),
                'completedAt' => $this->isoOrNull($row['production_completed_at']),
                'attention' => $state === 'active' ? $this->attention($row) : null,
            ],
            'importedAt' => $this->iso((string) $row['created_at']),
            'acceptedAt' => $this->isoOrNull($row['accepted_at']),
        ];
    }

    /**
     * Neutral, objective operational attention for active production. No SLA exists, so time never
     * produces attention. An ineligible owner still owns the order (nothing moves automatically); Staff
     * blocks that owner at operation time, and a supervisor can release or reassign.
     *
     * @param array<string, mixed> $row
     */
    private function attention(array $row): ?string
    {
        return match (true) {
            $row['production_owner_employee_uuid'] === null => 'unassigned',
            (int) $row['owner_active'] !== 1 => 'owner_inactive',
            (int) $row['owner_staff'] !== 1 => 'owner_no_staff_access',
            (int) $row['owner_stage'] !== 1 => 'owner_stage_not_allowed',
            default => null,
        };
    }

    private function encodeCursor(string $at, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['at' => $at, 'id' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{at: string, id: string}|null */
    private function decodeCursor(string $cursor): ?array
    {
        if (strlen($cursor) > 200 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
            return null;
        }
        $json = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
        $data = $json === false ? null : json_decode($json, true, 3);
        if (!is_array($data) || !is_string($data['at'] ?? null) || !is_string($data['id'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/D', $data['at']) !== 1
            || preg_match('/^[0-9a-f-]{36}$/D', $data['id']) !== 1) {
            return null;
        }
        return ['at' => $data['at'], 'id' => $data['id']];
    }

    private function isoOrNull(mixed $value): ?string
    {
        return $value === null ? null : $this->iso((string) $value);
    }

    private function iso(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
