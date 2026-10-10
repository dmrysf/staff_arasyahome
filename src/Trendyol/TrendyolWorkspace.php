<?php

declare(strict_types=1);

namespace Arasya\Operations\Trendyol;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Document\DocumentService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\EmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\Trendyol\TrendyolClient;
use Arasya\Operations\Integration\Trendyol\TrendyolEligibility;
use Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore;
use Arasya\Operations\Integration\Trendyol\TrendyolPackage;
use Arasya\Operations\Integration\Trendyol\TrendyolSizeHint;
use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Order\OrderProjectionWriter;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Production\CanonicalProductionWorkflowContract;
use Arasya\Operations\Production\ProductionWorkflowService;
use Arasya\Operations\Quality\IdempotencyStore;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The Staff Trendyol workspace: authorized Trendyol personnel review incoming packages, complete their production
 * measurements and approve them into production.
 *
 * Access is source-scoped by permission: `trendyol.orders.view` reads the inbox, `trendyol.orders.prepare`
 * completes lines and dismisses or reopens packages, `trendyol.orders.release` approves. The permissions are
 * Trendyol-only and usable only with Staff application access; no other source is reachable from here.
 *
 * Approval (release) is the only way a Trendyol package becomes a production order. In one transaction it
 * creates the canonical operational order at the first canonical stage (`waiting`, managed by Operations), issues
 * its Arasya production QR, generates production document revision 1 with the canonical ticket, and records the
 * submission. Moving the order on to the next stage then uses the ordinary Staff claim and stage completion,
 * with their stage access, document and authority checks. Nothing is ever written back to Trendyol.
 */
final readonly class TrendyolWorkspace
{
    public const VIEW = 'trendyol.orders.view';
    public const PREPARE = 'trendyol.orders.prepare';
    public const RELEASE = 'trendyol.orders.release';
    public const KINDS = ['curtain', 'drapery', 'other'];
    private const MEASURED_KINDS = ['curtain', 'drapery'];
    /**
     * Inbox views. `pending` lists pending packages in a new-order status (workable); `attention` lists pending
     * packages that cannot be released yet: payment pending, ReadyToShip or an unknown status (review), or
     * shipped/returned at the marketplace. Their class follows the marketplace status on every update.
     */
    private const VIEWS = ['pending' => ['pending'], 'attention' => ['pending'], 'released' => ['released'], 'closed' => ['dismissed', 'marketplace_cancelled']];
    private const MAX_CENTIMETRES = '10000';
    private const MAX_METERS = '100000';

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private EmployeeRepository $employees,
        private ProductionWorkflowService $workflows,
        private OrderProjectionWriter $writer,
        private DocumentService $documents,
        private IdempotencyStore $idempotency,
        private Clock $clock,
        /** Read-only Trendyol API used to verify a package at approval time; null fails every approval closed. */
        private ?TrendyolClient $marketplace = null,
        private ?TrendyolIntakeStore $intake = null,
    ) {
    }

    // ---------------------------------------------------------------- queries

    /** @return array<string, mixed> */
    public function overview(EmployeeIdentity $actor): array
    {
        $this->authorization->require($actor, self::VIEW);
        $state = $this->pdo->query('SELECT status, baseline_at, last_run_at, last_run_outcome FROM trendyol_intake_state WHERE state_id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts = array_fill_keys(array_keys(self::VIEWS), 0);
        foreach ($this->pdo->query('SELECT intake_status, marketplace_status, COUNT(*) AS n FROM trendyol_packages GROUP BY intake_status, marketplace_status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[self::viewOf((string) $row['intake_status'], (string) $row['marketplace_status'])] += (int) $row['n'];
        }
        return [
            'intake' => [
                'status' => (string) ($state['status'] ?? 'inactive'),
                'baselineAt' => self::iso($state['baseline_at'] ?? null),
                'lastRunAt' => self::iso($state['last_run_at'] ?? null),
                'lastRunOutcome' => isset($state['last_run_outcome']) ? (string) $state['last_run_outcome'] : null,
            ],
            'counts' => $counts + ['ignored' => (int) $this->pdo->query('SELECT COUNT(*) FROM trendyol_ignored_packages')->fetchColumn()],
            'capabilities' => $this->capabilities($actor),
        ];
    }

    /** The caller's own Trendyol work actions recorded in the intake audit. */
    private const OWN_ACTIONS = ['line_prepared', 'released', 'dismissed', 'reopened'];

    /**
     * The caller's own Trendyol work: today's counts (Europe/Bucharest business day) and the latest ten actions.
     * Only events the caller performed are returned; no other employee's activity is visible here.
     *
     * @return array<string, mixed>
     */
    public function myActivity(EmployeeIdentity $actor): array
    {
        $this->authorization->require($actor, self::VIEW);
        $zone = new DateTimeZone('Europe/Bucharest');
        $dayStart = $this->clock->now()->setTimezone($zone)->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $actions = "'" . implode("', '", self::OWN_ACTIONS) . "'";
        $today = array_fill_keys(self::OWN_ACTIONS, 0);
        $counts = $this->pdo->prepare("SELECT action, COUNT(*) AS n FROM trendyol_intake_events WHERE actor_employee_uuid = ? AND occurred_at >= ? AND action IN ({$actions}) GROUP BY action");
        $counts->execute([$actor->employeeUuid, $dayStart]);
        foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $today[(string) $row['action']] = (int) $row['n'];
        }
        $recent = $this->pdo->prepare("SELECT e.action, e.package_id, e.occurred_at, p.order_number FROM trendyol_intake_events e
            LEFT JOIN trendyol_packages p ON p.package_id = e.package_id
            WHERE e.actor_employee_uuid = ? AND e.action IN ({$actions}) ORDER BY e.event_id DESC LIMIT 10");
        $recent->execute([$actor->employeeUuid]);
        return [
            'today' => ['linesPrepared' => $today['line_prepared'], 'released' => $today['released'], 'dismissed' => $today['dismissed'], 'reopened' => $today['reopened']],
            'recent' => array_map(static fn (array $row): array => [
                'action' => (string) $row['action'],
                'packageId' => $row['package_id'] === null ? null : (string) $row['package_id'],
                'orderNumber' => $row['order_number'] === null ? null : (string) $row['order_number'],
                'at' => self::iso($row['occurred_at']),
            ], $recent->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function list(EmployeeIdentity $actor, string $view): array
    {
        $this->authorization->require($actor, self::VIEW);
        if ($view === 'ignored') {
            $rows = $this->pdo->query('SELECT package_id, order_number, reason, marketplace_status, order_date_ms, first_seen_at FROM trendyol_ignored_packages ORDER BY first_seen_at DESC, package_id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
            return ['items' => array_map(static fn (array $row): array => [
                'packageId' => (string) $row['package_id'],
                'orderNumber' => (string) $row['order_number'],
                'reason' => (string) $row['reason'],
                'marketplaceStatus' => (string) $row['marketplace_status'],
                'orderDate' => self::fromMillis($row['order_date_ms']),
                'firstSeenAt' => self::iso($row['first_seen_at']),
            ], $rows)];
        }
        $statuses = self::VIEWS[$view] ?? throw new ApiException(400, 'INVALID_VIEW', 'Unknown view.');
        $workList = $view === 'pending' || $view === 'attention';
        $statement = $this->pdo->prepare(
            'SELECT p.*, (SELECT COUNT(*) FROM trendyol_package_lines l WHERE l.package_id = p.package_id) AS line_count,
                    (SELECT COUNT(*) FROM trendyol_package_lines l WHERE l.package_id = p.package_id AND l.prepared_kind IS NOT NULL) AS prepared_count,
                    o.global_order_id, o.production_stage_id
             FROM trendyol_packages p LEFT JOIN operational_orders o ON o.order_uuid = p.released_order_uuid
             WHERE p.intake_status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')
             ORDER BY p.order_date_ms ' . ($workList ? 'ASC' : 'DESC') . ', p.package_id LIMIT 500',
        );
        $statement->execute($statuses);
        $baseline = $this->baselineMillis();
        $rows = array_slice(array_values(array_filter(
            $statement->fetchAll(PDO::FETCH_ASSOC),
            static fn (array $row): bool => self::viewOf((string) $row['intake_status'], (string) $row['marketplace_status']) === $view,
        )), 0, 200);
        return ['items' => array_map(fn (array $row): array => [
            ...$this->summary($row, $baseline),
            'lineCount' => (int) $row['line_count'],
            'preparedCount' => (int) $row['prepared_count'],
            'globalOrderId' => $row['global_order_id'] === null ? null : (string) $row['global_order_id'],
            'stageId' => $row['production_stage_id'] === null ? null : (string) $row['production_stage_id'],
        ], $rows)];
    }

    /** @return array<string, mixed> */
    public function detail(EmployeeIdentity $actor, string $packageId): array
    {
        $this->authorization->require($actor, self::VIEW);
        return $this->view($actor, $this->packageId($packageId));
    }

    // ---------------------------------------------------------------- commands

    /**
     * Records the production data of one line, confirmed by the employee (never inferred from Trendyol text).
     *
     * @param array<string, mixed> $input {expectedVersion, kind, widthCm?, heightCm?, meters?, notes?}
     */
    public function prepareLine(EmployeeIdentity $actor, string $packageId, string $lineId, array $input, string $key, string $requestId): array
    {
        $this->authorization->require($actor, self::PREPARE);
        IdempotencyStore::requireKey($key);
        $id = $this->packageId($packageId);
        $line = $this->lineId($lineId);
        $expected = $this->version($input['expectedVersion'] ?? null);
        $kind = $input['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw new ApiException(422, 'INVALID_KIND', 'Alege tipul produsului.');
        }
        $width = $this->decimal($input['widthCm'] ?? null, 'widthCm', self::MAX_CENTIMETRES);
        $height = $this->decimal($input['heightCm'] ?? null, 'heightCm', self::MAX_CENTIMETRES);
        $meters = $this->decimal($input['meters'] ?? null, 'meters', self::MAX_METERS);
        if (in_array($kind, self::MEASURED_KINDS, true) && ($width === null || $height === null)) {
            throw new ApiException(422, 'MEASUREMENTS_REQUIRED', 'Lățimea și înălțimea sunt obligatorii pentru perdele și draperii.');
        }
        $notes = $this->text($input['notes'] ?? null);
        $hash = IdempotencyStore::hash('trendyol.prepare_line', $id . ':' . $line, [$expected, $kind, $width, $height, $meters, $notes]);

        return $this->transaction(function () use ($actor, $id, $line, $expected, $kind, $width, $height, $meters, $notes, $key, $hash, $requestId): array {
            $package = $this->lockPackage($id);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'trendyol.prepare_line', (string) $id, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $fresh = $this->freshActor($actor, self::PREPARE);
            $this->assertPending($package, $expected);
            $now = $this->now();
            $statement = $this->pdo->prepare('UPDATE trendyol_package_lines SET prepared_kind = ?, prepared_width_cm = ?, prepared_height_cm = ?, prepared_meters = ?, prepared_notes = ?, prepared_by_employee_uuid = ?, prepared_at = ? WHERE package_id = ? AND line_id = ?');
            $statement->execute([$kind, $width, $height, $meters, $notes, $fresh->employeeUuid, $now, $id, $line]);
            if ($statement->rowCount() !== 1 && !$this->lineExists($id, $line)) {
                throw new ApiException(404, 'LINE_NOT_FOUND', 'Linia nu există în acest pachet.');
            }
            $this->pdo->prepare('UPDATE trendyol_packages SET version = version + 1 WHERE package_id = ?')->execute([$id]);
            $this->event($id, 'line_prepared', $fresh, ['lineId' => (string) $line, 'kind' => $kind, 'widthCm' => $width, 'heightCm' => $height, 'meters' => $meters], $requestId, $now);
            $response = $this->view($fresh, $id);
            $this->idempotency->store($actor->employeeUuid, $key, 'trendyol.prepare_line', (string) $id, $hash, $response, $now);
            return $response;
        });
    }

    /** A package that needs no factory work (for example a ready-made product) leaves the inbox; reason mandatory. */
    public function dismiss(EmployeeIdentity $actor, string $packageId, array $input, string $key, string $requestId): array
    {
        $this->authorization->require($actor, self::PREPARE);
        IdempotencyStore::requireKey($key);
        $id = $this->packageId($packageId);
        $expected = $this->version($input['expectedVersion'] ?? null);
        $reason = $this->text($input['reason'] ?? null) ?? throw new ApiException(422, 'REASON_REQUIRED', 'Motivul este obligatoriu.');
        $hash = IdempotencyStore::hash('trendyol.dismiss', (string) $id, [$expected, $reason]);
        return $this->transaction(function () use ($actor, $id, $expected, $reason, $key, $hash, $requestId): array {
            $package = $this->lockPackage($id);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'trendyol.dismiss', (string) $id, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $fresh = $this->freshActor($actor, self::PREPARE);
            $this->assertPending($package, $expected);
            $now = $this->now();
            $this->pdo->prepare("UPDATE trendyol_packages SET intake_status = 'dismissed', dismissed_by_employee_uuid = ?, dismissed_at = ?, dismiss_reason = ?, version = version + 1 WHERE package_id = ?")
                ->execute([$fresh->employeeUuid, $now, $reason, $id]);
            $this->event($id, 'dismissed', $fresh, ['reason' => $reason], $requestId, $now);
            $response = $this->view($fresh, $id);
            $this->idempotency->store($actor->employeeUuid, $key, 'trendyol.dismiss', (string) $id, $hash, $response, $now);
            return $response;
        });
    }

    public function reopen(EmployeeIdentity $actor, string $packageId, array $input, string $key, string $requestId): array
    {
        $this->authorization->require($actor, self::PREPARE);
        IdempotencyStore::requireKey($key);
        $id = $this->packageId($packageId);
        $expected = $this->version($input['expectedVersion'] ?? null);
        $hash = IdempotencyStore::hash('trendyol.reopen', (string) $id, [$expected]);
        return $this->transaction(function () use ($actor, $id, $expected, $key, $hash, $requestId): array {
            $package = $this->lockPackage($id);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'trendyol.reopen', (string) $id, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $fresh = $this->freshActor($actor, self::PREPARE);
            if ($package['intake_status'] !== 'dismissed') {
                throw new ApiException(409, 'PACKAGE_NOT_DISMISSED', 'Numai un pachet scos din lucru poate fi redeschis.');
            }
            if ((int) $package['version'] !== $expected) {
                throw $this->changed();
            }
            $now = $this->now();
            $status = TrendyolEligibility::withdrawn((string) $package['marketplace_status']) ? 'marketplace_cancelled' : 'pending';
            $this->pdo->prepare('UPDATE trendyol_packages SET intake_status = ?, dismissed_by_employee_uuid = NULL, dismissed_at = NULL, dismiss_reason = NULL, version = version + 1 WHERE package_id = ?')
                ->execute([$status, $id]);
            $this->event($id, 'reopened', $fresh, ['status' => $status], $requestId, $now);
            $response = $this->view($fresh, $id);
            $this->idempotency->store($actor->employeeUuid, $key, 'trendyol.reopen', (string) $id, $hash, $response, $now);
            return $response;
        });
    }

    /**
     * Explicit approval: creates the production order (stage 1), its Arasya QR and document revision 1.
     *
     * The package is verified against a fresh Trendyol snapshot first (one GET by shipmentPackageIds, outside any
     * database transaction so no lock waits on the network). The fresh copy is recorded exactly like a
     * synchronization would record it, so the employee sees any change. Approval is refused when Trendyol cannot be
     * read (fail closed), the package is gone, its status is not a new-order status, or its lines (identity, SKU,
     * quantity, product data) differ from what was prepared. The production transaction then re-checks, under the
     * package lock, that the row still carries the verified version, status and lines. A marketplace change in the
     * short interval between that GET and the commit cannot be excluded across two systems; the next synchronization
     * or reconciliation records it with the usual after-approval rules (cancellation makes the order unavailable).
     *
     * @param array<string, mixed> $input {expectedVersion, confirm: true}
     */
    public function release(EmployeeIdentity $actor, string $packageId, array $input, string $key, string $requestId): array
    {
        $this->authorization->require($actor, self::RELEASE);
        IdempotencyStore::requireKey($key);
        $id = $this->packageId($packageId);
        $expected = $this->version($input['expectedVersion'] ?? null);
        if (($input['confirm'] ?? null) !== true) {
            throw new ApiException(422, 'CONFIRMATION_REQUIRED', 'Confirmă verificarea comenzii înainte de aprobare.');
        }
        $hash = IdempotencyStore::hash('trendyol.release', (string) $id, [$expected]);
        // A replayed request answers without calling Trendyol again.
        $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'trendyol.release', (string) $id, $hash);
        if ($replay !== null) {
            return $replay;
        }
        // Local checks first (no lock): nothing is sent to Trendyol for a request that would fail anyway.
        $this->assertReleasable($this->readPackage($id), $expected);
        $verified = $this->verifyAtMarketplace($id);

        return $this->transaction(function () use ($actor, $id, $expected, $key, $hash, $requestId, $verified): array {
            $package = $this->lockPackage($id);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'trendyol.release', (string) $id, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $fresh = $this->freshActor($actor, self::RELEASE);
            // The row must still be exactly what Trendyol confirmed a moment ago (a sync or an edit in between
            // changes the version): otherwise the employee reloads and approves again.
            $this->assertReleasable($package, $verified['version']);
            if ((string) $package['marketplace_status'] !== $verified['status'] || !hash_equals((string) $package['lines_hash'], $verified['linesHash'])) {
                throw $this->changed();
            }
            $lines = $this->lines($id);
            try {
                $workflow = $this->workflows->current();
            } catch (\RuntimeException) {
                throw new ApiException(503, 'WORKFLOW_UNAVAILABLE', 'Canonical production workflow is unavailable.');
            }
            if ($workflow->id !== CanonicalProductionWorkflowContract::WORKFLOW_ID || $workflow->version !== CanonicalProductionWorkflowContract::VERSION) {
                throw new ApiException(503, 'WORKFLOW_UNAVAILABLE', 'Canonical production workflow is unavailable.');
            }
            $now = $this->clock->now();
            $nowSql = $now->format('Y-m-d H:i:s.u');
            $items = [];
            foreach ($lines as $line) {
                $items[] = new OperationalOrderItem(
                    Uuid::v4(),
                    (string) $line['line_id'],
                    (int) $line['line_number'],
                    (string) $line['product_name'],
                    $line['stock_code'] === null ? null : (string) $line['stock_code'],
                    $line['product_size'] === null ? null : (string) $line['product_size'],
                    $line['product_color'] === null ? null : (string) $line['product_color'],
                    $line['prepared_width_cm'] === null ? null : (float) $line['prepared_width_cm'],
                    $line['prepared_height_cm'] === null ? null : (float) $line['prepared_height_cm'],
                    $line['prepared_width_cm'] === null && $line['prepared_height_cm'] === null ? null : 'cm',
                    $line['prepared_meters'] === null ? null : (float) $line['prepared_meters'],
                    (int) $line['quantity'],
                    ['kind' => (string) $line['prepared_kind'], 'notes' => null, 'productionNotes' => $line['prepared_notes'] === null ? null : (string) $line['prepared_notes']],
                );
            }
            $status = (string) $package['marketplace_status'];
            $orderDate = (new DateTimeImmutable('@' . intdiv((int) $package['order_date_ms'], 1000)))->setTimezone(new DateTimeZone('UTC'));
            $snapshot = new SourceOrderSnapshot('trendyol', (string) $id, 'approval-' . $id, 1, $now, (string) $package['order_number'], OrderProjectionWriter::INITIAL_STAGE_ID,
                $status, $status, null, 'in_progress', $orderDate, $items);
            // No order-level context: the package link lives in trendyol_packages.released_order_uuid and the
            // order's source identity (trendyol:<packageId>), so existing Staff and Dashboard clients stay unchanged.
            $orderUuid = $this->writer->createInternal($snapshot, []);
            if ($package['delivery_context'] !== null) {
                $this->pdo->prepare('UPDATE operational_orders SET document_context = ? WHERE order_uuid = ?')->execute([(string) $package['delivery_context'], $orderUuid]);
            }
            $waiting = $workflow->stages[0];
            $this->pdo->prepare("INSERT INTO order_activity_events(event_id,employee_uuid,order_uuid,global_order_id,source_key,order_number_snapshot,action,
                workflow_key,workflow_version,from_stage_id,from_stage_label_snapshot,production_version_before,production_version_after,request_id,idempotency_key,occurred_at)
                VALUES(?,?,?,?,?,?,'production_submitted',?,?,?,?,0,1,?,?,?)")->execute([
                    Uuid::v4(), $fresh->employeeUuid, $orderUuid, 'trendyol:' . $id, 'trendyol', (string) $package['order_number'], $workflow->id, $workflow->version,
                    $waiting->id, $waiting->label, mb_substr($requestId, 0, 100), 'trendyol-release:' . hash('sha256', $key), $nowSql]);
            // Approval is the production submission: revision 1 of the canonical ticket (with the order's Arasya
            // production QR) is generated in the same transaction, exactly like the B2B handoff.
            $this->documents->generateInitialInTransaction($fresh, $orderUuid, $requestId);
            (new \Arasya\Operations\Analytics\AnalyticsCapture($this->pdo))->refreshOrder($orderUuid);
            $this->pdo->prepare("UPDATE trendyol_packages SET intake_status = 'released', released_order_uuid = ?, released_by_employee_uuid = ?, released_at = ?, version = version + 1 WHERE package_id = ?")
                ->execute([$orderUuid, $fresh->employeeUuid, $nowSql, $id]);
            $this->event($id, 'released', $fresh, ['globalOrderId' => 'trendyol:' . $id, 'stageId' => $waiting->id], $requestId, $nowSql);
            $response = $this->view($fresh, $id);
            $this->idempotency->store($actor->employeeUuid, $key, 'trendyol.release', (string) $id, $hash, $response, $nowSql);
            return $response;
        });
    }

    // ---------------------------------------------------------------- views

    /** @return array<string, mixed> */
    private function view(EmployeeIdentity $actor, int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.*, o.global_order_id, o.production_stage_id, o.document_status, o.operational_status, ps.display_name AS stage_label,
                    rb.display_name AS released_by_name, db.display_name AS dismissed_by_name
             FROM trendyol_packages p
             LEFT JOIN operational_orders o ON o.order_uuid = p.released_order_uuid
             LEFT JOIN production_stages ps ON ps.stage_id = o.production_stage_id
             LEFT JOIN employees rb ON rb.employee_uuid = p.released_by_employee_uuid
             LEFT JOIN employees db ON db.employee_uuid = p.dismissed_by_employee_uuid
             WHERE p.package_id = ?',
        );
        $statement->execute([$id]);
        $package = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($package)) {
            throw new ApiException(404, 'PACKAGE_NOT_FOUND', 'Pachetul Trendyol nu există.');
        }
        $lines = $this->lines($id);
        $siblings = $this->pdo->prepare('SELECT package_id, intake_status, marketplace_status FROM trendyol_packages WHERE order_number = ? AND package_id <> ? ORDER BY package_id');
        $siblings->execute([(string) $package['order_number'], $id]);
        $events = $this->pdo->prepare(
            'SELECT e.action, e.actor_label, e.details, e.occurred_at, x.display_name FROM trendyol_intake_events e LEFT JOIN employees x ON x.employee_uuid = e.actor_employee_uuid
             WHERE e.package_id = ? ORDER BY e.event_id DESC LIMIT 50',
        );
        $events->execute([$id]);
        $missing = self::missing($lines);
        $pending = $package['intake_status'] === 'pending';
        $releasable = TrendyolEligibility::releasable((string) $package['marketplace_status']);
        $capabilities = $this->capabilities($actor);
        $delivery = is_string($package['delivery_context']) ? json_decode($package['delivery_context'], true) : null;
        return [
            ...$this->summary($package, $this->baselineMillis()),
            'delivery' => is_array($delivery) ? [
                'name' => $delivery['name'] ?? null,
                'addressLines' => is_array($delivery['addressLines'] ?? null) ? $delivery['addressLines'] : [],
                'phoneMasked' => $delivery['phoneMasked'] ?? null,
            ] : null,
            'lines' => array_map(static fn (array $line): array => [
                'lineId' => (string) $line['line_id'],
                'lineNumber' => (int) $line['line_number'],
                'productName' => (string) $line['product_name'],
                'stockCode' => $line['stock_code'],
                'barcode' => $line['barcode'],
                'productSize' => $line['product_size'],
                'productColor' => $line['product_color'],
                'quantity' => (int) $line['quantity'],
                'sizeSuggestion' => TrendyolSizeHint::suggest($line['product_size'], $line['product_name']),
                'prepared' => $line['prepared_kind'] === null ? null : [
                    'kind' => (string) $line['prepared_kind'],
                    'widthCm' => $line['prepared_width_cm'] === null ? null : (string) $line['prepared_width_cm'],
                    'heightCm' => $line['prepared_height_cm'] === null ? null : (string) $line['prepared_height_cm'],
                    'meters' => $line['prepared_meters'] === null ? null : (string) $line['prepared_meters'],
                    'notes' => $line['prepared_notes'],
                    'preparedBy' => $line['prepared_by_name'],
                    'preparedAt' => self::iso($line['prepared_at']),
                ],
            ], $lines),
            'readiness' => [
                'ready' => $pending && $releasable && $missing === [], 'missingLines' => $missing, 'marketplaceReleasable' => $releasable,
                // Why a pending package cannot be released yet: the marketplace class, or unknown_status.
                'blockedReason' => !$pending || $releasable ? null
                    : (TrendyolEligibility::knownStatus((string) $package['marketplace_status']) ? TrendyolEligibility::statusClass((string) $package['marketplace_status']) : 'unknown_status'),
            ],
            'siblings' => array_map(static fn (array $row): array => ['packageId' => (string) $row['package_id'], 'intakeStatus' => (string) $row['intake_status'], 'marketplaceStatus' => (string) $row['marketplace_status']], $siblings->fetchAll(PDO::FETCH_ASSOC)),
            'production' => $package['released_order_uuid'] === null ? null : [
                'globalOrderId' => (string) $package['global_order_id'],
                'stageId' => (string) $package['production_stage_id'],
                'stageLabel' => $package['stage_label'] === null ? null : (string) $package['stage_label'],
                'documentStatus' => (string) $package['document_status'],
                'operationalStatus' => (string) $package['operational_status'],
                'releasedBy' => $package['released_by_name'] === null ? null : (string) $package['released_by_name'],
                'releasedAt' => self::iso($package['released_at']),
            ],
            'dismissal' => $package['dismissed_at'] === null ? null : [
                'reason' => (string) $package['dismiss_reason'],
                'by' => $package['dismissed_by_name'] === null ? null : (string) $package['dismissed_by_name'],
                'at' => self::iso($package['dismissed_at']),
            ],
            'history' => array_map(static fn (array $row): array => [
                'action' => (string) $row['action'],
                'actor' => $row['display_name'] !== null ? (string) $row['display_name'] : ($row['actor_label'] === null ? null : (string) $row['actor_label']),
                'at' => self::iso($row['occurred_at']),
            ], $events->fetchAll(PDO::FETCH_ASSOC)),
            'capabilities' => $capabilities + [
                'prepareNow' => $capabilities['prepare'] && $pending,
                'releaseNow' => $capabilities['release'] && $pending && $releasable && $missing === [],
                'dismissNow' => $capabilities['prepare'] && $pending,
                'reopenNow' => $capabilities['prepare'] && $package['intake_status'] === 'dismissed',
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private function summary(array $row, ?int $baselineMillis): array
    {
        $orderDateMillis = $row['order_date_ms'] === null ? null : (int) $row['order_date_ms'];
        return [
            'packageId' => (string) $row['package_id'],
            'orderNumber' => (string) $row['order_number'],
            'intakeStatus' => (string) $row['intake_status'],
            'marketplaceStatus' => (string) $row['marketplace_status'],
            'marketplaceClass' => TrendyolEligibility::statusClass((string) $row['marketplace_status']),
            'marketplaceStatusKnown' => TrendyolEligibility::knownStatus((string) $row['marketplace_status']),
            'orderDate' => self::fromMillis($row['order_date_ms']),
            'channelId' => $row['channel_id'] === null ? null : (int) $row['channel_id'],
            'orderDateNearActivation' => $baselineMillis !== null && TrendyolEligibility::nearActivation($orderDateMillis, $baselineMillis),
            'changedAfterRelease' => (int) $row['changed_after_release'] === 1,
            'firstSeenAt' => self::iso($row['first_seen_at']),
            'version' => (int) $row['version'],
        ];
    }

    /** The inbox view a package belongs to. */
    private static function viewOf(string $intakeStatus, string $marketplaceStatus): string
    {
        return match ($intakeStatus) {
            'pending' => TrendyolEligibility::releasable($marketplaceStatus) ? 'pending' : 'attention',
            'released' => 'released',
            default => 'closed',
        };
    }

    /** The activation baseline in epoch milliseconds, or null while intake was never activated. */
    private function baselineMillis(): ?int
    {
        $baseline = $this->pdo->query('SELECT baseline_at FROM trendyol_intake_state WHERE state_id = 1')->fetchColumn();
        return is_string($baseline) && $baseline !== ''
            ? (int) (new DateTimeImmutable($baseline, new DateTimeZone('UTC')))->format('Uv')
            : null;
    }

    /** @return array{view: bool, prepare: bool, release: bool} */
    private function capabilities(EmployeeIdentity $actor): array
    {
        return [
            'view' => $this->authorization->can($actor, self::VIEW),
            'prepare' => $this->authorization->can($actor, self::PREPARE),
            'release' => $this->authorization->can($actor, self::RELEASE),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function lines(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT l.*, e.display_name AS prepared_by_name FROM trendyol_package_lines l LEFT JOIN employees e ON e.employee_uuid = l.prepared_by_employee_uuid WHERE l.package_id = ? ORDER BY l.line_number');
        $statement->execute([$id]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array<string, mixed>> $lines @return list<int> line numbers without complete production data */
    private static function missing(array $lines): array
    {
        $missing = [];
        foreach ($lines as $line) {
            $kind = $line['prepared_kind'];
            if ($kind === null || in_array($kind, self::MEASURED_KINDS, true) && ($line['prepared_width_cm'] === null || $line['prepared_height_cm'] === null)) {
                $missing[] = (int) $line['line_number'];
            }
        }
        return $missing;
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function lockPackage(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM trendyol_packages WHERE package_id = ? FOR UPDATE');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(404, 'PACKAGE_NOT_FOUND', 'Pachetul Trendyol nu există.');
    }

    /** @param array<string, mixed> $package */
    private function assertPending(array $package, int $expected): void
    {
        if ($package['intake_status'] !== 'pending') {
            throw new ApiException(409, 'PACKAGE_NOT_PENDING', 'Pachetul nu mai este în lucru.', ['intakeStatus' => (string) $package['intake_status']]);
        }
        if ((int) $package['version'] !== $expected) {
            throw $this->changed();
        }
    }

    /** @param array<string, mixed> $package */
    private function assertReleasable(array $package, int $expected): void
    {
        if ($package['intake_status'] === 'released') {
            throw new ApiException(409, 'PACKAGE_ALREADY_RELEASED', 'Pachetul a fost deja aprobat pentru producție.');
        }
        $this->assertPending($package, $expected);
        if (!TrendyolEligibility::releasable((string) $package['marketplace_status'])) {
            throw new ApiException(409, 'MARKETPLACE_STATUS_NOT_RELEASABLE', 'Statusul actual din Trendyol nu permite intrarea în producție.', ['marketplaceStatus' => (string) $package['marketplace_status']]);
        }
        $missing = self::missing($this->lines((int) $package['package_id']));
        if ($missing !== []) {
            throw new ApiException(422, 'PACKAGE_NOT_PREPARED', 'Completează datele de producție pentru toate liniile.', ['lines' => $missing]);
        }
    }

    /**
     * One GET for the package's current marketplace copy; records it like a synchronization and returns the
     * verified version, status and lines hash. Fails closed on every Trendyol problem.
     *
     * @return array{version: int, status: string, linesHash: string}
     */
    private function verifyAtMarketplace(int $id): array
    {
        if ($this->marketplace === null || $this->intake === null) {
            throw new ApiException(503, 'TRENDYOL_VERIFICATION_UNAVAILABLE', 'Verificarea în Trendyol nu este configurată. Comanda nu poate fi aprobată.');
        }
        $before = $this->readPackage($id);
        try {
            $raw = $this->marketplace->packagesByIds([$id])[$id] ?? null;
            $package = $raw === null ? null : TrendyolPackage::fromApi($raw);
        } catch (RuntimeException $error) {
            $code = preg_match('/^TRENDYOL_[A-Z_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'TRENDYOL_REQUEST_FAILED';
            throw new ApiException(503, 'TRENDYOL_VERIFICATION_FAILED', 'Nu am putut verifica acum comanda în Trendyol. Încearcă din nou peste câteva minute.', ['reason' => $code]);
        } catch (\InvalidArgumentException) {
            throw new ApiException(503, 'TRENDYOL_VERIFICATION_FAILED', 'Nu am putut verifica acum comanda în Trendyol. Încearcă din nou peste câteva minute.', ['reason' => 'TRENDYOL_MALFORMED_RESPONSE']);
        }
        if ($package === null) {
            throw new ApiException(409, 'TRENDYOL_PACKAGE_UNAVAILABLE', 'Trendyol nu mai returnează acest pachet. Verifică în Seller Panel; comanda nu poate fi aprobată.');
        }
        if ($package->packageId !== $id) {
            throw new ApiException(503, 'TRENDYOL_VERIFICATION_FAILED', 'Nu am putut verifica acum comanda în Trendyol. Încearcă din nou peste câteva minute.', ['reason' => 'TRENDYOL_MALFORMED_RESPONSE']);
        }
        // The fresh copy goes through the synchronization rules (its own short transaction), so a cancellation,
        // a new status or changed lines are visible to the employee. Prepared data survives only on unchanged lines.
        $this->intake->record($package, $this->baselineMillis() ?? 0);
        if (!TrendyolEligibility::releasable($package->status)) {
            throw new ApiException(409, 'MARKETPLACE_STATUS_NOT_RELEASABLE', 'Statusul actual din Trendyol nu permite intrarea în producție.', ['marketplaceStatus' => $package->status]);
        }
        if (!hash_equals((string) $before['lines_hash'], $package->linesHash())) {
            throw new ApiException(409, 'MARKETPLACE_LINES_CHANGED', 'Produsele comenzii s-au schimbat în Trendyol. Verifică liniile și completează din nou datele de producție.');
        }
        return ['version' => (int) $this->readPackage($id)['version'], 'status' => $package->status, 'linesHash' => $package->linesHash()];
    }

    /** @return array<string, mixed> */
    private function readPackage(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM trendyol_packages WHERE package_id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : throw new ApiException(404, 'PACKAGE_NOT_FOUND', 'Pachetul Trendyol nu există.');
    }

    private function changed(): ApiException
    {
        return new ApiException(409, 'PACKAGE_CHANGED', 'Pachetul s-a schimbat între timp. Reîncarcă pagina.');
    }

    private function lineExists(int $id, int $line): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM trendyol_package_lines WHERE package_id = ? AND line_id = ?');
        $statement->execute([$id, $line]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $details */
    private function event(int $id, string $action, EmployeeIdentity $actor, array $details, string $requestId, string $now): void
    {
        $this->pdo->prepare('INSERT INTO trendyol_intake_events (package_id, action, actor_employee_uuid, details, request_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $action, $actor->employeeUuid, json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), mb_substr($requestId, 0, 100), $now]);
    }

    /** Re-reads the actor under a row lock so a permission removed meanwhile is honoured inside the command. */
    private function freshActor(EmployeeIdentity $actor, string $permission): EmployeeIdentity
    {
        $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$actor->employeeUuid]);
        $fresh = $this->employees->findByUuid($actor->employeeUuid);
        if ($fresh === null || !$fresh->isOperationallyActive()) {
            throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Account is not active.');
        }
        $this->authorization->require($fresh, $permission);
        return $fresh;
    }

    /** @param callable(): array<string, mixed> $command */
    private function transaction(callable $command): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->pdo->beginTransaction();
                $result = $command();
                $this->pdo->commit();
                return $result;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($error instanceof PDOException && $attempt < 3 && in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    continue;
                }
                throw $error;
            }
        }
    }

    private function packageId(string $value): int
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) {
            throw new ApiException(404, 'PACKAGE_NOT_FOUND', 'Pachetul Trendyol nu există.');
        }
        return (int) $value;
    }

    private function lineId(string $value): int
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) {
            throw new ApiException(404, 'LINE_NOT_FOUND', 'Linia nu există în acest pachet.');
        }
        return (int) $value;
    }

    private function version(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'expectedVersion must be a positive integer.');
        }
        return $value;
    }

    /** Exact decimal text with at most three decimals, greater than zero and at most $max; null when absent. */
    private function decimal(mixed $value, string $field, string $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^\d{1,6}(?:\.\d{1,3})?$/D', $value) !== 1 || (float) $value <= 0.0 || (float) $value > (float) $max) {
            throw new ApiException(422, 'INVALID_MEASUREMENT', 'Valoare invalidă.', ['field' => $field]);
        }
        return $value;
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > 1000 || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $value) === 1) {
            throw new ApiException(422, 'INVALID_TEXT', 'Text invalid (maxim 1000 caractere).');
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    private static function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private static function fromMillis(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return (new DateTimeImmutable('@' . intdiv((int) $value, 1000)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
