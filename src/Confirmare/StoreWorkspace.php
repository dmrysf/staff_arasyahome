<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Document\DocumentScopePolicy;
use Arasya\Operations\Document\DocumentService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessClient;
use Arasya\Operations\Integration\SourceAccess\SourceAccessException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * The shop employee's Store Workspace (API 2.83.0, owner brief "Store Workspace V1", 2026-11-26): one place for the
 * production documents, the FAN Courier AWBs and the order search of the employee's own shops (Trend Home, Outlet Perdele).
 *
 * Read only. Every action still goes through its existing route and checks (documents: DocumentService generate/print;
 * AWB: ConfirmareOperations awb_create / awb_cancel / address_update). This class only lists, counts and joins:
 *
 *   summary         counters of the employee's shops (Europe/Bucharest day): documents waiting, AWBs waiting, documents
 *                   printed today (first prints, reprints separately), AWBs created / cancelled / re-created today, parcels
 *                   and orders on today's active AWBs, AWB requests the shop refused (to correct).
 *   documents       tab todo (the production handoff list: ready / print, plus the documents that need a revision first),
 *                   today (prints of today), history (prints, paged, date range).
 *   awb             the AWB queue (ConfirmareOperations::awbQueue), paged; each page is joined with ONE signed shop read
 *                   per shop (YD SOFT StoreBoard rows: customer name, city, cash on delivery, blockers, address check).
 *   awbHistory      AWBs made through Staff, paged, by date range; a cancelled AWB is marked and linked to its replacement.
 *   search          order number, AWB number, or (one shop read per shop) customer name / telephone; only the employee's shops.
 *   order           one order: Arasya facts (stage n/14, document, invoice, AWB operations) and one shop read (products with
 *                   pictures, delivery block, money position read only).
 *   mine            the employee's own actions (prints, AWBs, cancellations, address corrections), paged.
 *
 * Who: an active Staff identity holding the invoice `issue` or `manage` scope of a shop, or the production document
 * `operate` scope (documents only). Customer data is never stored here: it comes from the shop read of the page.
 */
final class StoreWorkspace
{
    public const SOURCES = ['trendhome', 'outletperdele'];
    public const PAGE_SIZE = 25;
    /** The canonical 14 production stages (curtain workflow v1), for the "n / 14" indicator. */
    public const STAGES = ['waiting', 'material-preparation', 'workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height',
        'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery'];
    private const ZONE = 'Europe/Bucharest';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly AuthorizationService $authorization,
        private readonly SourceAccessRegistry $registry,
        private readonly SourceAccessClient $client,
        private readonly InvoiceScopePolicy $invoices,
        private readonly DocumentScopePolicy $documents,
        private readonly ConfirmareService $service,
        private readonly ConfirmareOperations $operations,
    ) {
    }

    // ---- Scope ------------------------------------------------------------------------------------------------------------

    /** @return array{awb: list<string>, documents: list<string>, managed: list<string>, all: list<string>} */
    public function scope(EmployeeIdentity $actor): array
    {
        if ($actor->isRoot || !$actor->isOperationallyActive() || $actor->mustChangePassword || !$actor->hasApplication('staff')) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'The Store Workspace is for the shop employees.');
        }
        $awb = array_values(array_filter(self::SOURCES, fn (string $s): bool => $this->invoices->allows($actor, InvoiceScopePolicy::ISSUE, $s) || $this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, $s)));
        $managed = array_values(array_filter(self::SOURCES, fn (string $s): bool => $this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, $s)));
        $documents = $this->authorization->can($actor, DocumentService::GENERATE)
            ? array_values(array_filter(self::SOURCES, fn (string $s): bool => $this->documents->allows($actor, DocumentScopePolicy::OPERATE, $s)))
            : [];
        $all = array_values(array_unique(array_merge($awb, $documents)));
        if ($all === []) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'The Store Workspace is for the shop employees.');
        }
        return ['awb' => $awb, 'documents' => $documents, 'managed' => $managed, 'all' => $all];
    }

    // ---- Summary ----------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function summary(EmployeeIdentity $actor): array
    {
        $scope = $this->scope($actor);
        [$from, $to] = $this->day(0);
        $docs = $scope['documents'] === [] ? [] : $this->documentTodo($actor);
        $queue = $scope['awb'] === [] ? ['items' => []] : $this->operations->awbQueue($actor, null);
        $printed = ['print' => 0, 'reprint' => 0];
        if ($scope['documents'] !== []) {
            $params = [$from, $to];
            $in = $this->in($scope['documents'], $params);
            $statement = $this->pdo->prepare("SELECT p.print_kind, COUNT(DISTINCT p.order_uuid) AS orders, COUNT(*) AS prints FROM production_document_prints p
                INNER JOIN operational_orders o ON o.order_uuid = p.order_uuid WHERE p.printed_at >= ? AND p.printed_at < ? AND o.source_key IN ({$in}) GROUP BY p.print_kind");
            $statement->execute($params);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $printed[(string) $row['print_kind'] === 'reprint' ? 'reprint' : 'print'] = (string) $row['print_kind'] === 'reprint' ? (int) $row['prints'] : (int) $row['orders'];
            }
        }
        $awb = ['created' => 0, 'cancelled' => 0, 'recreated' => 0, 'parcels' => 0, 'orders' => 0];
        if ($scope['awb'] !== [] && $this->operations->storeColumns()) {
            $params = [$from, $to];
            $in = $this->in($scope['awb'], $params);
            $statement = $this->pdo->prepare("SELECT op.operation_uuid, op.order_uuid, op.kind, op.parcels, op.replaces_operation_uuid, op.requested_at,
                    EXISTS (SELECT 1 FROM order_source_operations c WHERE c.order_uuid = op.order_uuid AND c.kind IN ('awb_cancel', 'awb_reopened') AND c.state = 'applied' AND c.requested_at > op.requested_at) AS later_cancel
                FROM order_source_operations op WHERE op.state = 'applied' AND op.kind IN ('awb_create', 'awb_cancel') AND op.completed_at >= ? AND op.completed_at < ? AND op.source_key IN ({$in})");
            $statement->execute($params);
            $orders = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['kind'] === 'awb_cancel') {
                    $awb['cancelled']++;
                    continue;
                }
                $awb['created']++;
                if ($row['replaces_operation_uuid'] !== null) {
                    $awb['recreated']++;
                }
                if ((int) $row['later_cancel'] === 0) {
                    // Parcels and orders count only the AWBs still active: a cancelled AWB never leaves.
                    $awb['parcels'] += (int) ($row['parcels'] ?? 0);
                    $orders[(string) $row['order_uuid']] = true;
                }
            }
            $awb['orders'] = count($orders);
        }
        $needsFix = count(array_filter($queue['items'], static fn (array $i): bool => ($i['lastAttempt'] ?? null) === 'rejected'));
        return [
            'day' => (new DateTimeImmutable('now', new DateTimeZone(self::ZONE)))->setTimestamp($this->clock->now()->getTimestamp())->format('Y-m-d'),
            'zone' => self::ZONE,
            'sources' => $scope['all'],
            'can' => ['documents' => $scope['documents'] !== [], 'awb' => $scope['awb'] !== []],
            'documents' => ['waiting' => count(array_filter($docs, static fn (array $d): bool => in_array($d['step'], ['ready', 'print'], true))),
                'revision' => count(array_filter($docs, static fn (array $d): bool => $d['step'] === 'revision')), 'printedToday' => $printed['print'], 'reprintsToday' => $printed['reprint']],
            'awb' => ['waiting' => count($queue['items']), 'needsFix' => $needsFix, 'createdToday' => $awb['created'], 'cancelledToday' => $awb['cancelled'],
                'recreatedToday' => $awb['recreated'], 'parcelsToday' => $awb['parcels'], 'ordersToday' => $awb['orders'], 'tracked' => $this->operations->storeColumns()],
        ];
    }

    // ---- Documents --------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function documents(EmployeeIdentity $actor, string $tab, int $page, ?string $from, ?string $to): array
    {
        $scope = $this->scope($actor);
        if ($scope['documents'] === []) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Nu ai dreptul să generezi documente de producție.');
        }
        if ($tab === 'todo') {
            $items = $this->documentTodo($actor);
            $total = count($items);
            $slice = array_slice($items, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
            return ['tab' => 'todo', 'items' => $this->withShopRows($slice, 'globalOrderId'), 'page' => $page, 'pageSize' => self::PAGE_SIZE, 'total' => $total];
        }
        [$start, $end] = $tab === 'today' ? $this->day(0) : $this->range($from, $to, 30);
        $params = [$start, $end];
        $in = $this->in($scope['documents'], $params);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM production_document_prints p INNER JOIN operational_orders o ON o.order_uuid = p.order_uuid
            WHERE p.printed_at >= ? AND p.printed_at < ? AND o.source_key IN ({$in})");
        $count->execute($params);
        $statement = $this->pdo->prepare("SELECT p.print_number, p.print_kind, p.reason, p.printed_at, e.display_name, r.revision_number, o.global_order_id, o.order_number, o.source_key,
                o.document_status, o.production_stage_id
            FROM production_document_prints p INNER JOIN operational_orders o ON o.order_uuid = p.order_uuid
            INNER JOIN production_document_revisions r ON r.revision_uuid = p.revision_uuid LEFT JOIN employees e ON e.employee_uuid = p.printed_by_employee_uuid
            WHERE p.printed_at >= ? AND p.printed_at < ? AND o.source_key IN ({$in}) ORDER BY p.printed_at DESC, p.print_uuid DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE));
        $statement->execute($params);
        $items = array_map(fn (array $r): array => [
            'globalOrderId' => (string) $r['global_order_id'], 'orderNumber' => (string) $r['order_number'], 'source' => (string) $r['source_key'],
            'kind' => (string) $r['print_kind'], 'printNumber' => (int) $r['print_number'], 'revision' => (int) $r['revision_number'], 'reason' => $r['reason'] === null ? null : (string) $r['reason'],
            'at' => self::iso((string) $r['printed_at']), 'by' => (string) ($r['display_name'] ?? ''), 'documentStatus' => (string) $r['document_status'], 'stage' => (string) $r['production_stage_id'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
        return ['tab' => $tab, 'items' => $items, 'page' => $page, 'pageSize' => self::PAGE_SIZE, 'total' => (int) $count->fetchColumn(), 'from' => self::iso($start), 'to' => self::iso($end)];
    }

    /**
     * The documents still to print: the production handoff list (ready: no document yet, print: never printed) and the
     * documents of the same shops whose content changed (stale/revoked, still at stage 1): those need a revision first and
     * are never reprinted as they are.
     *
     * @return list<array<string, mixed>>
     */
    private function documentTodo(EmployeeIdentity $actor): array
    {
        $items = array_values(array_filter($this->service->handoffList($actor, null)['items'], static fn (array $i): bool => in_array($i['step'], ['ready', 'print'], true)
            && in_array($i['sourceKey'], self::SOURCES, true)));
        $items = array_map(static fn (array $i): array => $i + ['documentStatus' => $i['step'] === 'ready' ? 'none' : 'active'], $items);
        $sources = $this->documents->sources($actor, DocumentScopePolicy::OPERATE);
        $params = [];
        $scope = DocumentScopePolicy::condition($sources, 'o.source_key', $params);
        $in = $this->in(self::SOURCES, $params);
        $statement = $this->pdo->prepare("SELECT o.global_order_id, o.order_number, o.source_key, o.document_status, o.source_due_date, o.source_submitted_at FROM operational_orders o
            WHERE o.document_status IN ('stale', 'revoked') AND o.production_stage_id = 'waiting' AND o.production_completed_at IS NULL AND o.operational_status <> 'unavailable'
              AND {$scope} AND o.source_key IN ({$in}) ORDER BY o.updated_at DESC LIMIT 200");
        $statement->execute($params);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = ['globalOrderId' => (string) $r['global_order_id'], 'orderNumber' => (string) $r['order_number'], 'sourceKey' => (string) $r['source_key'],
                'dueDate' => $r['source_due_date'] === null ? null : (string) $r['source_due_date'], 'submittedAt' => $r['source_submitted_at'] === null ? null : self::iso((string) $r['source_submitted_at']),
                'approvedAt' => null, 'step' => 'revision', 'documentStatus' => (string) $r['document_status']];
        }
        return $items;
    }

    // ---- AWB ------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function awb(EmployeeIdentity $actor, int $page, string $filter, string $requestId): array
    {
        $scope = $this->scope($actor);
        if ($scope['awb'] === []) {
            throw new ApiException(403, 'CONFIRMARE_OPERATION_DENIED', 'Only the shop employee of the order makes its AWB.');
        }
        $items = $this->operations->awbQueue($actor, null)['items'];
        if ($filter === 'problem') {
            $items = array_values(array_filter($items, static fn (array $i): bool => ($i['lastAttempt'] ?? null) === 'rejected'));
        }
        $total = count($items);
        $slice = array_slice($items, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        $open = $this->openAwbOperations(array_map(static fn (array $i): string => (string) $i['globalOrderId'], $slice));
        $slice = array_map(static fn (array $i): array => $i + ['open' => $open[(string) $i['globalOrderId']] ?? null], $slice);
        return ['items' => $this->withShopRows($slice, 'globalOrderId', $requestId, $actor), 'page' => $page, 'pageSize' => self::PAGE_SIZE, 'total' => $total, 'filter' => $filter];
    }

    /** @return array<string, mixed> */
    public function awbHistory(EmployeeIdentity $actor, string $range, ?string $from, ?string $to, int $page, string $requestId = 'none'): array
    {
        $scope = $this->scope($actor);
        if ($scope['awb'] === []) {
            throw new ApiException(403, 'CONFIRMARE_OPERATION_DENIED', 'Only the shop employee of the order makes its AWB.');
        }
        [$start, $end] = match ($range) {
            'today' => $this->day(0),
            'yesterday' => $this->day(-1),
            '7d' => [$this->day(-6)[0], $this->day(0)[1]],
            '30d' => [$this->day(-29)[0], $this->day(0)[1]],
            default => $this->range($from, $to, 92),
        };
        if (!$this->operations->storeColumns()) {
            return ['items' => [], 'page' => $page, 'pageSize' => self::PAGE_SIZE, 'total' => 0, 'range' => $range, 'from' => self::iso($start), 'to' => self::iso($end), 'tracked' => false];
        }
        $params = [$start, $end];
        $in = $this->in($scope['awb'], $params);
        // The shop's invoice manager sees every AWB of the shop; the employee sees the AWBs of the orders routed to them and
        // the AWBs they made.
        $managed = $scope['managed'];
        $who = $managed === [] ? '(op.employee_uuid = ? OR a.employee_uuid = ?)' : '(op.source_key IN (' . implode(', ', array_fill(0, count($managed), '?')) . ') OR op.employee_uuid = ? OR a.employee_uuid = ?)';
        $params = [...$params, ...$managed, $actor->employeeUuid, $actor->employeeUuid];
        $base = "FROM order_source_operations op INNER JOIN operational_orders o ON o.order_uuid = op.order_uuid LEFT JOIN order_shop_assignments a ON a.active_order_uuid = op.order_uuid
            LEFT JOIN employees e ON e.employee_uuid = op.employee_uuid
            WHERE op.kind = 'awb_create' AND op.state = 'applied' AND op.completed_at >= ? AND op.completed_at < ? AND op.source_key IN ({$in}) AND {$who}";
        $count = $this->pdo->prepare("SELECT COUNT(*) {$base}");
        $count->execute($params);
        $statement = $this->pdo->prepare("SELECT op.operation_uuid, op.order_uuid, op.global_order_id, op.source_key, op.awb_number, op.parcels, op.replaces_operation_uuid, op.requested_at, op.completed_at,
                o.order_number, e.display_name {$base} ORDER BY op.completed_at DESC, op.operation_uuid DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE));
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        // One query for the other AWB operations of the page's orders: the cancellation that followed each AWB and the AWB
        // that replaced it.
        $related = [];
        $orderUuids = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['order_uuid'], $rows)));
        if ($orderUuids !== []) {
            $p = [];
            $marks = $this->in($orderUuids, $p);
            // awb_reopened: an AWB cancelled in the shop (before Store Workspace) whose AWB step root reopened (API 2.77.8).
            $ops = $this->pdo->prepare("SELECT operation_uuid, order_uuid, kind, awb_number, reason_code, replaces_operation_uuid, requested_at, completed_at FROM order_source_operations
                WHERE order_uuid IN ({$marks}) AND kind IN ('awb_create', 'awb_cancel', 'awb_reopened') AND state = 'applied' ORDER BY requested_at");
            $ops->execute($p);
            foreach ($ops->fetchAll(PDO::FETCH_ASSOC) as $op) {
                $related[(string) $op['order_uuid']][] = $op;
            }
        }
        $items = [];
        foreach ($rows as $r) {
            $cancel = null;
            $replacement = null;
            $list = $related[(string) $r['order_uuid']] ?? [];
            foreach ($list as $op) {
                if ($op['kind'] === 'awb_create' && $op['requested_at'] > $r['requested_at']) {
                    break; // the next AWB of the order: a cancellation after it is not this AWB's
                }
                if (in_array($op['kind'], ['awb_cancel', 'awb_reopened'], true) && $op['requested_at'] > $r['requested_at']) {
                    $cancel = $op;
                    break;
                }
            }
            if ($cancel !== null) {
                foreach ($list as $op) {
                    if ($op['kind'] === 'awb_create' && ($op['replaces_operation_uuid'] === $cancel['operation_uuid'] || ($cancel['kind'] === 'awb_reopened' && $op['requested_at'] > $cancel['requested_at']))) {
                        $replacement = $op;
                        break;
                    }
                }
            }
            // The order's current AWB: no later AWB and no cancellation after it (the one "Corectează AWB" acts on).
            $later = array_filter($list, static fn (array $op): bool => $op['kind'] === 'awb_create' && $op['requested_at'] > $r['requested_at']);
            $current = $cancel === null && $later === [];
            $replaced = null;
            if ($r['replaces_operation_uuid'] !== null) {
                foreach ($list as $op) {
                    if ($op['operation_uuid'] === $r['replaces_operation_uuid']) {
                        $replaced = $op['awb_number'] === null ? null : (string) $op['awb_number'];
                    }
                }
            }
            $items[] = [
                'globalOrderId' => (string) $r['global_order_id'], 'orderNumber' => (string) $r['order_number'], 'source' => (string) $r['source_key'],
                'awb' => $r['awb_number'] === null ? null : (string) $r['awb_number'], 'parcels' => $r['parcels'] === null ? null : (int) $r['parcels'],
                'at' => self::iso((string) $r['completed_at']), 'by' => (string) ($r['display_name'] ?? ''),
                'state' => $cancel === null ? 'active' : 'cancelled',
                'current' => $current,
                'cancelledAt' => $cancel === null ? null : self::iso((string) $cancel['completed_at']),
                'cancelReason' => $cancel === null ? null : ($cancel['kind'] === 'awb_reopened' ? 'cancelled_in_shop' : ($cancel['reason_code'] === null ? null : (string) $cancel['reason_code'])),
                'replacedBy' => $replacement === null || $replacement['awb_number'] === null ? null : (string) $replacement['awb_number'],
                'replaces' => $replaced,
            ];
        }
        // An AWB made before migration 064 has no number in Arasya: the current one is read from the shop (one read per shop
        // for the page, the same as the AWB list); an older, replaced one stays unknown ("—"), never guessed.
        $unknown = array_values(array_filter($items, static fn (array $i): bool => $i['awb'] === null && $i['current']));
        if ($unknown !== []) {
            $numbers = [];
            foreach ($this->withShopRows(array_map(static fn (array $i): array => ['globalOrderId' => $i['globalOrderId']], $unknown), 'globalOrderId', $requestId, $actor) as $r) {
                $number = $r['shop']['awb']['number'] ?? null;
                if (is_string($number) && ($r['shop']['awb']['state'] ?? null) === 'created') {
                    $numbers[(string) $r['globalOrderId']] = $number;
                }
            }
            $items = array_map(static fn (array $i): array => $i['awb'] === null && $i['current'] && isset($numbers[$i['globalOrderId']]) ? ['awb' => $numbers[$i['globalOrderId']], 'awbFromShop' => true] + $i : $i, $items);
        }
        // The open correction of each current AWB (an unconfirmed cancellation stays visible after a reload, with its key).
        $open = $this->openAwbOperations(array_values(array_unique(array_map(static fn (array $i): string => $i['globalOrderId'], array_filter($items, static fn (array $i): bool => $i['current'])))));
        $items = array_map(static fn (array $i): array => $i + ['open' => $i['current'] ? ($open[$i['globalOrderId']] ?? null) : null], $items);
        return ['items' => $items, 'page' => $page, 'pageSize' => self::PAGE_SIZE, 'total' => (int) $count->fetchColumn(), 'range' => $range, 'from' => self::iso($start), 'to' => self::iso($end), 'tracked' => true,
            'stats' => $this->awbStats($start, $end, $in, $who, $scope['awb'], $managed, $actor)];
    }

    /**
     * The AWB figures of a period (owner acceptance 2026-11-26), same orders as the list: orders with an AWB, real AWBs (each
     * applied awb_create is one FAN Courier AWB), parcels, cancelled AWBs, renewed AWBs (an AWB made for an order that had one
     * before). Parcels are known only from API 2.83.0 (migration 064): older AWBs are counted in `parcelsUnknown`, never
     * guessed. AWBs made outside Arasya (the shop's own panel) are not in these figures.
     *
     * @param list<string> $sources @param list<string> $managed
     * @return array<string, int>
     */
    private function awbStats(string $start, string $end, string $in, string $who, array $sources, array $managed, EmployeeIdentity $actor): array
    {
        $scope = "INNER JOIN operational_orders o ON o.order_uuid = op.order_uuid LEFT JOIN order_shop_assignments a ON a.active_order_uuid = op.order_uuid
            WHERE op.state = 'applied' AND op.completed_at >= ? AND op.completed_at < ? AND op.source_key IN ({$in}) AND {$who}";
        $params = [$start, $end, ...$sources, ...$managed, $actor->employeeUuid, $actor->employeeUuid];
        $made = $this->pdo->prepare("SELECT COUNT(*) AS awbs, COUNT(DISTINCT op.order_uuid) AS orders, COALESCE(SUM(op.parcels), 0) AS parcels, SUM(op.parcels IS NULL) AS unknown_parcels,
                SUM(EXISTS (SELECT 1 FROM order_source_operations p WHERE p.order_uuid = op.order_uuid AND p.kind = 'awb_create' AND p.state = 'applied' AND p.requested_at < op.requested_at)) AS renewed
            FROM order_source_operations op {$scope} AND op.kind = 'awb_create'");
        $made->execute($params);
        $m = $made->fetch(PDO::FETCH_ASSOC) ?: [];
        $cancelled = $this->pdo->prepare("SELECT COUNT(*) FROM order_source_operations op {$scope} AND op.kind IN ('awb_cancel', 'awb_reopened')");
        $cancelled->execute($params);
        return ['orders' => (int) ($m['orders'] ?? 0), 'awbs' => (int) ($m['awbs'] ?? 0), 'parcels' => (int) ($m['parcels'] ?? 0), 'parcelsUnknown' => (int) ($m['unknown_parcels'] ?? 0),
            'cancelled' => (int) $cancelled->fetchColumn(), 'renewed' => (int) ($m['renewed'] ?? 0)];
    }

    /**
     * The AWB operations still waiting for the shop's answer (pending or unknown) of these orders, with their key: Staff keeps
     * showing them after a reload and settles them with the shop (never sends them again).
     *
     * @param list<string> $globalIds
     * @return array<string, array{kind: string, state: string, at: string, idempotencyKey: string}>
     */
    private function openAwbOperations(array $globalIds): array
    {
        if ($globalIds === [] || !$this->operations->installed()) {
            return [];
        }
        $p = [];
        $marks = $this->in($globalIds, $p);
        $statement = $this->pdo->prepare("SELECT global_order_id, kind, state, idempotency_key, requested_at FROM order_source_operations
            WHERE global_order_id IN ({$marks}) AND kind IN ('awb_create', 'awb_cancel', 'address_update') AND state IN ('pending', 'unknown') ORDER BY requested_at");
        $statement->execute($p);
        $open = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $open[(string) $r['global_order_id']] = ['kind' => (string) $r['kind'], 'state' => (string) $r['state'], 'at' => self::iso((string) $r['requested_at']), 'idempotencyKey' => (string) $r['idempotency_key']];
        }
        return $open;
    }

    // ---- Search and order ---------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function search(EmployeeIdentity $actor, string $query, string $requestId): array
    {
        $scope = $this->scope($actor);
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if (mb_strlen($query) < 3 || mb_strlen($query) > 64 || preg_match('/[\x00-\x1F<>]/', $query) === 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Scrie cel puțin 3 caractere.', ['field' => 'q']);
        }
        $digits = (string) preg_replace('/\D+/', '', $query);
        $uuids = [];
        $params = [];
        $in = $this->in($scope['all'], $params);
        if ($digits !== '' && $digits === preg_replace('/[\s#]+/', '', $query)) {
            // A number: the order number (exact, then as a prefix) or an AWB number made through Staff.
            $statement = $this->pdo->prepare("SELECT o.order_uuid FROM operational_orders o WHERE o.source_key IN ({$in}) AND (o.order_number = ? OR o.order_number LIKE ?) ORDER BY o.order_number = ? DESC, o.source_submitted_at DESC LIMIT 20");
            $statement->execute([...$params, $digits, $digits . '%', $digits]);
            $uuids = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
            if (strlen($digits) >= 9 && $this->operations->storeColumns()) {
                $p = [];
                $marks = $this->in($scope['all'], $p);
                $awb = $this->pdo->prepare("SELECT DISTINCT order_uuid FROM order_source_operations WHERE awb_number = ? AND source_key IN ({$marks}) LIMIT 5");
                $awb->execute([$digits, ...$p]);
                $uuids = array_values(array_unique([...array_map('strval', $awb->fetchAll(PDO::FETCH_COLUMN)), ...$uuids]));
            }
        }
        $shopAsked = false;
        if ($uuids === [] || $digits !== preg_replace('/[\s#]+/', '', $query)) {
            // A name or a telephone: the shop searches its own orders (Arasya keeps no customer data).
            $shopAsked = true;
            foreach ($scope['all'] as $source) {
                $target = $this->registry->find($source);
                if ($target === null) {
                    continue;
                }
                try {
                    $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_STORE, ['mode' => 'search', 'query' => $query, 'actor' => ['employeeId' => $actor->employeeUuid, 'role' => $this->role($actor, $source)], 'requestId' => self::requestId($requestId)]);
                } catch (SourceAccessException) {
                    continue;
                }
                if ($answer['status'] !== 200 || !is_array($answer['body']['orders'] ?? null)) {
                    continue;
                }
                $ids = array_values(array_filter(array_map(static fn (mixed $o): ?string => is_array($o) && is_string($o['orderId'] ?? null) && preg_match('/^[1-9][0-9]{0,19}$/D', $o['orderId']) === 1 ? $o['orderId'] : null, $answer['body']['orders'])));
                if ($ids === []) {
                    continue;
                }
                $p = [$source];
                $marks = $this->in($ids, $p);
                $statement = $this->pdo->prepare("SELECT order_uuid FROM operational_orders WHERE source_key = ? AND source_order_id IN ({$marks})");
                $statement->execute($p);
                $uuids = array_values(array_unique([...$uuids, ...array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN))]));
            }
        }
        return ['query' => $query, 'items' => $this->orderRows(array_slice($uuids, 0, 20)), 'shopAsked' => $shopAsked];
    }

    /** @return array<string, mixed> */
    public function order(EmployeeIdentity $actor, string $globalOrderId, string $requestId): array
    {
        $scope = $this->scope($actor);
        try {
            $globalId = GlobalOrderId::fromString($globalOrderId)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        }
        $statement = $this->pdo->prepare('SELECT o.order_uuid, o.global_order_id, o.source_key, o.source_order_id, o.order_number, o.production_stage_id, o.production_completed_at, o.document_status,
                o.document_version, o.operational_status, o.cancellation_state, o.source_submitted_at, o.source_due_date, o.payment_method,
                r.revision_number, (SELECT COUNT(*) FROM production_document_prints p WHERE p.revision_uuid = o.active_document_revision_uuid) AS prints,
                (SELECT MAX(p.printed_at) FROM production_document_prints p WHERE p.revision_uuid = o.active_document_revision_uuid) AS last_print
            FROM operational_orders o LEFT JOIN production_document_revisions r ON r.revision_uuid = o.active_document_revision_uuid WHERE o.global_order_id = ?');
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !in_array((string) $row['source_key'], $scope['all'], true)) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Order not found.');
        }
        $items = $this->pdo->prepare('SELECT line_number, name, product_code, variant, color, width_value, height_value, measurement_unit, meters, quantity FROM operational_order_items WHERE order_uuid = ? ORDER BY line_number');
        $items->execute([$row['order_uuid']]);
        $issued = $this->pdo->prepare('SELECT COUNT(*) FROM order_invoice_actions i WHERE i.order_uuid = ? AND ' . ConfirmareInvoice::ISSUED_SQL);
        $issued->execute([$row['order_uuid']]);
        $stage = (string) $row['production_stage_id'];
        $index = array_search($stage, self::STAGES, true);
        $shop = null;
        $target = $this->registry->find((string) $row['source_key']);
        if ($target !== null) {
            try {
                $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_STORE, ['mode' => 'detail', 'orders' => [['orderId' => (string) $row['source_order_id'], 'orderNumber' => (string) $row['order_number']]],
                    'actor' => ['employeeId' => $actor->employeeUuid, 'role' => $this->role($actor, (string) $row['source_key'])], 'requestId' => self::requestId($requestId)]);
                $shop = $answer['status'] === 200 && is_array($answer['body']['rows'][0] ?? null) ? self::shopDetail($answer['body']['rows'][0]) : null;
            } catch (SourceAccessException) {
                $shop = null;
            }
        }
        return [
            'globalOrderId' => (string) $row['global_order_id'], 'orderNumber' => (string) $row['order_number'], 'source' => (string) $row['source_key'],
            'submittedAt' => $row['source_submitted_at'] === null ? null : self::iso((string) $row['source_submitted_at']), 'dueDate' => $row['source_due_date'] === null ? null : (string) $row['source_due_date'],
            'stage' => ['id' => $stage, 'number' => $index === false ? null : $index + 1, 'total' => count(self::STAGES), 'completed' => $row['production_completed_at'] !== null],
            'cancelled' => $row['operational_status'] === 'unavailable' || $row['cancellation_state'] === 'held',
            'document' => ['status' => (string) $row['document_status'], 'version' => (int) $row['document_version'], 'revision' => $row['revision_number'] === null ? null : (int) $row['revision_number'],
                'prints' => (int) $row['prints'], 'lastPrint' => $row['last_print'] === null ? null : self::iso((string) $row['last_print'])],
            'invoiceIssued' => (int) $issued->fetchColumn() > 0,
            // API 2.84.0: the automatic final invoice after the print (null before the first print or before migration 065).
            'autoInvoice' => StoreAutoInvoice::stateOf($this->pdo, (string) $row['order_uuid']),
            'products' => array_map(static fn (array $i): array => ['line' => (int) $i['line_number'], 'name' => (string) $i['name'], 'code' => $i['product_code'] === null ? null : (string) $i['product_code'],
                'variant' => $i['variant'] === null ? null : (string) $i['variant'], 'color' => $i['color'] === null ? null : (string) $i['color'],
                'width' => $i['width_value'] === null ? null : (string) $i['width_value'], 'height' => $i['height_value'] === null ? null : (string) $i['height_value'],
                'unit' => $i['measurement_unit'] === null ? null : (string) $i['measurement_unit'], 'meters' => $i['meters'] === null ? null : (string) $i['meters'], 'quantity' => (int) $i['quantity']], $items->fetchAll(PDO::FETCH_ASSOC)),
            'awbOperations' => $this->awbOperations((string) $row['order_uuid']),
            'shop' => $shop,
            'canAwb' => in_array((string) $row['source_key'], $scope['awb'], true),
            'canDocuments' => in_array((string) $row['source_key'], $scope['documents'], true),
        ];
    }

    /** @return array<string, mixed> */
    public function mine(EmployeeIdentity $actor, int $page, ?string $from, ?string $to): array
    {
        $this->scope($actor);
        [$start, $end] = $this->range($from, $to, 30);
        $ops = $this->operations->installed();
        $union = "SELECT 'print' AS kind, p.print_kind AS detail, p.printed_at AS at, o.global_order_id, o.order_number, o.source_key, NULL AS awb, NULL AS parcels, p.reason AS reason
                FROM production_document_prints p INNER JOIN operational_orders o ON o.order_uuid = p.order_uuid WHERE p.printed_by_employee_uuid = ? AND p.printed_at >= ? AND p.printed_at < ?";
        $params = [$actor->employeeUuid, $start, $end];
        if ($ops) {
            $columns = $this->operations->storeColumns() ? 'op.awb_number, op.parcels, op.reason_code' : 'NULL, NULL, NULL';
            $union .= " UNION ALL SELECT op.kind, op.state, op.requested_at, op.global_order_id, o.order_number, op.source_key, {$columns}
                FROM order_source_operations op INNER JOIN operational_orders o ON o.order_uuid = op.order_uuid
                WHERE op.employee_uuid = ? AND op.kind IN ('awb_create', 'awb_cancel', 'address_update') AND op.requested_at >= ? AND op.requested_at < ?";
            $params = [...$params, $actor->employeeUuid, $start, $end];
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM ({$union}) x");
        $count->execute($params);
        $statement = $this->pdo->prepare("SELECT * FROM ({$union}) x ORDER BY at DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE));
        $statement->execute($params);
        return ['items' => array_map(static fn (array $r): array => [
            'kind' => (string) $r['kind'], 'detail' => (string) $r['detail'], 'at' => self::iso((string) $r['at']), 'globalOrderId' => (string) $r['global_order_id'],
            'orderNumber' => (string) $r['order_number'], 'source' => (string) $r['source_key'], 'awb' => $r['awb'] === null ? null : (string) $r['awb'],
            'parcels' => $r['parcels'] === null ? null : (int) $r['parcels'], 'reason' => $r['reason'] === null ? null : (string) $r['reason'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC)), 'page' => $page, 'pageSize' => self::PAGE_SIZE, 'total' => (int) $count->fetchColumn(), 'from' => self::iso($start), 'to' => self::iso($end)];
    }

    // ---- Helpers --------------------------------------------------------------------------------------------------------

    /**
     * Joins a page of rows with ONE signed shop read per shop (YD SOFT StoreBoard rows). A shop that cannot be read leaves
     * its rows without customer data (`shop: null`); nothing is guessed.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withShopRows(array $rows, string $idKey, string $requestId = 'none', ?EmployeeIdentity $actor = null): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): string => (string) $r[$idKey], $rows);
        $p = [];
        $marks = $this->in($ids, $p);
        $statement = $this->pdo->prepare("SELECT o.global_order_id, o.source_key, o.source_order_id, o.order_number, o.production_stage_id FROM operational_orders o WHERE o.global_order_id IN ({$marks})");
        $statement->execute($p);
        $orders = [];
        $bySource = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $orders[(string) $o['global_order_id']] = $o;
            $bySource[(string) $o['source_key']][] = ['orderId' => (string) $o['source_order_id'], 'orderNumber' => (string) $o['order_number']];
        }
        $shop = [];
        foreach ($bySource as $source => $refs) {
            $target = $this->registry->find($source);
            if ($target === null) {
                continue;
            }
            try {
                $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_STORE, ['mode' => 'rows', 'orders' => $refs,
                    'actor' => ['employeeId' => $actor?->employeeUuid ?? '00000000-0000-4000-8000-000000000000', 'role' => $actor === null ? 'documents' : $this->role($actor, $source)], 'requestId' => self::requestId($requestId)]);
            } catch (SourceAccessException) {
                continue;
            }
            if ($answer['status'] !== 200 || !is_array($answer['body']['rows'] ?? null)) {
                continue;
            }
            foreach ($answer['body']['rows'] as $shopRow) {
                if (is_array($shopRow) && is_string($shopRow['orderId'] ?? null)) {
                    $shop[$source . ':' . $shopRow['orderId']] = self::shopRow($shopRow);
                }
            }
        }
        return array_map(function (array $r) use ($idKey, $orders, $shop): array {
            $o = $orders[(string) $r[$idKey]] ?? null;
            $r['stage'] = $r['stage'] ?? ($o === null ? null : (string) $o['production_stage_id']);
            $r['shop'] = $o === null ? null : ($shop[$o['source_key'] . ':' . $o['source_order_id']] ?? null);
            return $r;
        }, $rows);
    }

    /** @param list<string> $uuids @return list<array<string, mixed>> */
    private function orderRows(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        $p = [];
        $marks = $this->in($uuids, $p);
        $statement = $this->pdo->prepare("SELECT o.order_uuid, o.global_order_id, o.order_number, o.source_key, o.production_stage_id, o.document_status, o.source_submitted_at,
                " . ($this->operations->installed() ? ConfirmareOperations::awbDoneSql('o') : '0') . " AS awb_done
            FROM operational_orders o WHERE o.order_uuid IN ({$marks}) ORDER BY o.source_submitted_at DESC");
        $statement->execute($p);
        return array_map(function (array $r): array {
            $index = array_search((string) $r['production_stage_id'], self::STAGES, true);
            return ['globalOrderId' => (string) $r['global_order_id'], 'orderNumber' => (string) $r['order_number'], 'source' => (string) $r['source_key'],
                'stage' => (string) $r['production_stage_id'], 'stageNumber' => $index === false ? null : $index + 1, 'documentStatus' => (string) $r['document_status'],
                'awbDone' => (int) $r['awb_done'] === 1, 'submittedAt' => $r['source_submitted_at'] === null ? null : self::iso((string) $r['source_submitted_at'])];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    private function awbOperations(string $orderUuid): array
    {
        if (!$this->operations->installed()) {
            return [];
        }
        $columns = $this->operations->storeColumns() ? 'op.awb_number, op.parcels, op.reason_code' : 'NULL AS awb_number, NULL AS parcels, NULL AS reason_code';
        $statement = $this->pdo->prepare("SELECT op.kind, op.state, op.outcome, op.requested_at, op.completed_at, {$columns}, e.display_name FROM order_source_operations op
            LEFT JOIN employees e ON e.employee_uuid = op.employee_uuid
            WHERE op.order_uuid = ? AND op.kind IN ('awb_create', 'awb_cancel', 'address_update', 'awb_reopened') ORDER BY op.requested_at DESC LIMIT 20");
        $statement->execute([$orderUuid]);
        return array_map(static fn (array $r): array => ['kind' => (string) $r['kind'], 'state' => (string) $r['state'], 'outcome' => $r['outcome'] === null ? null : (string) $r['outcome'],
            'at' => self::iso((string) $r['requested_at']), 'awb' => $r['awb_number'] === null ? null : (string) $r['awb_number'], 'parcels' => $r['parcels'] === null ? null : (int) $r['parcels'],
            'reason' => $r['reason_code'] === null ? null : (string) $r['reason_code'], 'by' => (string) ($r['display_name'] ?? '')], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $r @return array<string, mixed> one shop row as Staff may show it (shapes and codes checked) */
    public static function shopRow(array $r): array
    {
        if (($r['available'] ?? false) !== true) {
            return ['available' => false];
        }
        $text = static fn (mixed $v, int $max = 120): string => is_string($v) ? mb_substr(strip_tags($v), 0, $max) : '';
        $money = static fn (mixed $v): ?string => is_string($v) && preg_match('/^(0|[1-9][0-9]{0,8})\.[0-9]{2}$/D', $v) === 1 ? $v : null;
        $codes = static fn (mixed $list, array $allowed): array => is_array($list) ? array_values(array_filter($list, static fn (mixed $c): bool => is_string($c) && in_array($c, $allowed, true))) : [];
        $awb = is_array($r['awb'] ?? null) ? $r['awb'] : [];
        $address = is_array($r['address'] ?? null) ? $r['address'] : [];
        return [
            'available' => true,
            'name' => $text($r['name'] ?? null),
            'city' => $text($r['city'] ?? null),
            'county' => $text($r['county'] ?? null, 10),
            'cod' => $money($r['cod'] ?? null),
            'awb' => ['state' => in_array($awb['state'] ?? null, ['none', 'created'], true) ? $awb['state'] : 'none',
                'number' => is_string($awb['number'] ?? null) && preg_match('/^[0-9]{6,20}$/D', $awb['number']) === 1 ? $awb['number'] : null, 'status' => $text($awb['status'] ?? null)],
            'blockers' => $codes($r['blockers'] ?? null, ConfirmareOperations::CODES),
            'address' => ['state' => in_array($address['state'] ?? null, ['ok', 'warning', 'attention'], true) ? $address['state'] : 'attention',
                'issues' => $codes($address['issues'] ?? null, ConfirmareOperations::ADDRESS_CODES), 'warnings' => $codes($address['warnings'] ?? null, ConfirmareOperations::ADDRESS_CODES)],
        ];
    }

    /** @param array<string, mixed> $r @return array<string, mixed>|null */
    private static function shopDetail(array $r): ?array
    {
        if (($r['available'] ?? false) !== true) {
            return null;
        }
        $text = static fn (mixed $v, int $max = 200): string => is_string($v) ? mb_substr(strip_tags($v), 0, $max) : '';
        $money = static fn (mixed $v): ?string => is_string($v) && preg_match('/^-?(0|[1-9][0-9]{0,8})\.[0-9]{2}$/D', $v) === 1 ? $v : null;
        $m = is_array($r['money'] ?? null) ? $r['money'] : [];
        $state = ConfirmareOperations::shopState(['delivery' => $r['delivery'] ?? null]);
        return [
            'status' => is_string($r['status'] ?? null) && preg_match('/^[a-z-]{2,30}$/D', $r['status']) === 1 ? $r['status'] : null,
            'lines' => array_values(array_map(static fn (mixed $l): array => [
                'name' => is_array($l) ? $text($l['name'] ?? null) : '', 'sku' => is_array($l) ? $text($l['sku'] ?? null, 80) : '',
                'quantity' => is_array($l) && is_int($l['quantity'] ?? null) ? max(0, $l['quantity']) : 0,
                'image' => is_array($l) && is_string($l['image'] ?? null) && preg_match('#^https://[^\s"<>]{1,400}$#D', $l['image']) === 1 ? $l['image'] : null,
            ], array_slice(is_array($r['lines'] ?? null) ? $r['lines'] : [], 0, 60))),
            'delivery' => $state['delivery'],
            'money' => ['currency' => is_string($m['currency'] ?? null) && preg_match('/^[A-Z]{3}$/D', $m['currency']) === 1 ? $m['currency'] : 'RON', 'known' => ($m['known'] ?? false) === true,
                'total' => $money($m['total'] ?? null), 'received' => $money($m['received'] ?? null), 'remaining' => $money($m['remaining'] ?? null)],
            'payment' => is_string($r['payment'] ?? null) && preg_match('/^[a-z0-9_-]{1,40}$/D', $r['payment']) === 1 ? $r['payment'] : null,
        ];
    }

    private function role(EmployeeIdentity $actor, string $source): string
    {
        return $this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, $source) ? 'manager' : 'documents';
    }

    /** @param list<string> $values @param list<mixed> $params */
    private function in(array $values, array &$params): string
    {
        foreach ($values as $value) {
            $params[] = $value;
        }
        return implode(', ', array_fill(0, max(1, count($values)), '?')) . ($values === [] ? '' : '');
    }

    /** @return array{0: string, 1: string} the UTC bounds of a Bucharest day ($offset days from today) */
    private function day(int $offset): array
    {
        $zone = new DateTimeZone(self::ZONE);
        $local = (new DateTimeImmutable('@' . $this->clock->now()->getTimestamp()))->setTimezone($zone)->setTime(0, 0)->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
        $utc = new DateTimeZone('UTC');
        return [$local->setTimezone($utc)->format('Y-m-d H:i:s.u'), $local->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s.u')];
    }

    /** @return array{0: string, 1: string} a custom Bucharest date range (YYYY-MM-DD, inclusive), at most $maxDays; default: the last $default days */
    private function range(?string $from, ?string $to, int $maxDays, int $default = 7): array
    {
        if ($from === null && $to === null) {
            return [$this->day(-($default - 1))[0], $this->day(0)[1]];
        }
        $valid = static fn (?string $d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) === 1 && DateTimeImmutable::createFromFormat('!Y-m-d', $d) !== false;
        if (!$valid($from) || !$valid($to) || $from > $to) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'from and to are dates (YYYY-MM-DD), from not after to.', ['field' => 'from']);
        }
        $zone = new DateTimeZone(self::ZONE);
        $start = new DateTimeImmutable($from . ' 00:00:00', $zone);
        $end = (new DateTimeImmutable($to . ' 00:00:00', $zone))->modify('+1 day');
        if ($end->getTimestamp() - $start->getTimestamp() > $maxDays * 86400 + 3600) {
            throw new ApiException(422, 'VALIDATION_FAILED', "The range is at most {$maxDays} days.", ['field' => 'to']);
        }
        $utc = new DateTimeZone('UTC');
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s.u'), $end->setTimezone($utc)->format('Y-m-d H:i:s.u')];
    }

    private static function iso(string $sql): string
    {
        return (new DateTimeImmutable($sql, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private static function requestId(string $requestId): string
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $requestId) === 1 ? $requestId : 'none';
    }
}
