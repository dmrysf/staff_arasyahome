<?php

declare(strict_types=1);

namespace Arasya\Operations\Trendyol;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\Trendyol\TrendyolClient;
use Arasya\Operations\Integration\Trendyol\TrendyolLabelClient;
use Arasya\Operations\Order\QrReference;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The Trendyol AWB print station (API 2.65.0, migration 051).
 *
 * Owner rule (2026-11-09): a Trendyol order that reaches canonical stage 13 (`packing`, Împachetare) appears in the
 * station automatically; the employee prints the original Trendyol shipping label and may print it again at any time.
 * There is no approval, no extra stage, no stage 14 check and no forced print. Owner addition (API 2.66.0): open orders
 * at stage 14 (`delivery`, Livrare) are listed too, until stage 14 is completed.
 *
 * - The queue is derived on every request from operational_orders (source `trendyol`, stage `packing` or `delivery`,
 *   still open, not stopped). Nothing enqueues an order and nothing moves a stage. `stage=all|13|14` filters; without
 *   it the queue lists stage 13 only, exactly as API 2.65.0 did, so Staff 2.47.0 keeps working during the deployment.
 * - A label is fetched only when an employee asks for it: one fresh read of the exact linked Trendyol shipment package
 *   (the order's source identity `trendyol:<packageId>` and trendyol_packages.released_order_uuid must agree), then
 *   Trendyol's common label service for that package's current cargo tracking number, then the PDF from Trendyol's
 *   CDN. Nothing is written to Trendyol and no other courier is called. A changed tracking number is simply the one
 *   Trendyol returns now; a cancelled or returned package gets a short refusal, never an old label.
 * - The print history is minimal (trendyol_awb_print_events): label retrieved / failed (safe category) and print
 *   requested (initial or reprint). "Printed before" means a print was requested before: a browser cannot prove that
 *   a printer produced the label. No address, name, telephone, label or credential is stored or logged.
 *
 * Permissions (Staff application only): trendyol.awb.view (queue, search, history), trendyol.awb.print (label and
 * print record, initial and reprint alike). They grant nothing else: no stage, status, Finance, B2B or IAM authority.
 */
final readonly class AwbStation
{
    public const VIEW = 'trendyol.awb.view';
    public const PRINT = 'trendyol.awb.print';
    public const STAGE_ID = 'packing';
    public const STAGE_ORDINAL = 13;
    /** Stages whose open Trendyol orders the queue lists, with their canonical ordinal (13 Împachetare, 14 Livrare). */
    public const QUEUE_STAGES = ['packing' => 13, 'delivery' => 14];
    /** Queue filter => stages. No filter means stage 13 only (the API 2.65.0 contract). */
    private const STAGE_FILTERS = ['all' => ['packing', 'delivery'], '13' => ['packing'], '14' => ['delivery']];
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;
    /** Marketplace statuses whose forward label must never be printed (cancelled or coming back). */
    public const CLOSED_STATUSES = ['Cancelled', 'UnSupplied', 'UnPacked', 'Returned', 'UnDeliveredAndReturned'];

    /** Adapter error => [HTTP status, API code, stored outcome category]. */
    private const FAILURES = [
        'TRENDYOL_LABEL_NOT_READY' => [409, 'AWB_LABEL_NOT_READY', 'not_ready'],
        'TRENDYOL_LABEL_NOT_AVAILABLE' => [409, 'AWB_LABEL_NOT_AVAILABLE', 'not_available'],
        'TRENDYOL_LABEL_EXPIRED' => [409, 'AWB_LABEL_EXPIRED', 'expired'],
        'TRENDYOL_AUTH_FAILED' => [503, 'AWB_TRENDYOL_AUTH', 'auth_failed'],
        'TRENDYOL_RATE_LIMITED' => [429, 'AWB_TRENDYOL_RATE_LIMITED', 'rate_limited'],
        'TRENDYOL_UNAVAILABLE' => [503, 'AWB_TRENDYOL_UNAVAILABLE', 'unavailable'],
        'TRENDYOL_UPGRADE_REQUIRED' => [503, 'AWB_TRENDYOL_UNAVAILABLE', 'unavailable'],
        'TRENDYOL_REQUEST_FAILED' => [503, 'AWB_TRENDYOL_UNAVAILABLE', 'unavailable'],
        'TRENDYOL_LABEL_DOWNLOAD_FAILED' => [503, 'AWB_TRENDYOL_UNAVAILABLE', 'download_failed'],
        'TRENDYOL_MALFORMED_RESPONSE' => [502, 'AWB_TRENDYOL_INVALID', 'malformed'],
        'TRENDYOL_LABEL_URL_REFUSED' => [502, 'AWB_LABEL_INVALID', 'url_refused'],
        'TRENDYOL_LABEL_REDIRECT_REFUSED' => [502, 'AWB_LABEL_INVALID', 'redirect_refused'],
        'TRENDYOL_LABEL_FORMAT_UNSUPPORTED' => [502, 'AWB_LABEL_FORMAT', 'format_unsupported'],
        'TRENDYOL_LABEL_TOO_LARGE' => [502, 'AWB_LABEL_INVALID', 'invalid_document'],
        'TRENDYOL_LABEL_INVALID' => [502, 'AWB_LABEL_INVALID', 'invalid_document'],
        'AWB_PACKAGE_UNAVAILABLE' => [409, 'AWB_PACKAGE_UNAVAILABLE', 'package_unavailable'],
        'AWB_PACKAGE_MISMATCH' => [409, 'AWB_PACKAGE_MISMATCH', 'package_mismatch'],
        'AWB_PACKAGE_CLOSED' => [409, 'AWB_PACKAGE_CLOSED', 'package_closed'],
    ];

    private const ORDER_COLUMNS = 'o.order_uuid, o.global_order_id, o.source_key, o.source_order_id, o.order_number, o.production_stage_id,
        o.production_completed_at, o.operational_status, o.production_stopped_at, o.production_changed_at, o.updated_at';

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private Clock $clock,
        /** Read-only Order V2 client for the fresh package read; null (no credentials) refuses every label request. */
        private ?TrendyolClient $marketplace = null,
        private ?TrendyolLabelClient $labels = null,
    ) {
    }

    // ---------------------------------------------------------------- queries

    /**
     * Open Trendyol orders at stage 13 and/or 14 (`stage` = all, 13 or 14; default 13), newest arrival at their current
     * stage first. `q` narrows by order number prefix; `cursor` is the opaque value of the previous page.
     *
     * @return array<string, mixed>
     */
    public function queue(EmployeeIdentity $actor, ?string $search, ?string $cursor, ?string $limit, ?string $stage = null): array
    {
        $this->authorization->require($actor, self::VIEW);
        $filter = $stage === null || $stage === '' ? '13' : $stage;
        $stages = self::STAGE_FILTERS[$filter] ?? throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
        $size = $limit === null || $limit === '' ? self::DEFAULT_LIMIT : (preg_match('/^[1-9][0-9]{0,2}$/D', $limit) === 1 ? min(self::MAX_LIMIT, (int) $limit) : throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.'));
        $search = $search === null ? '' : trim($search);
        if (strlen($search) > 64 || preg_match('/[[:cntrl:]]/', $search) === 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
        }
        $after = $cursor === null || $cursor === '' ? null : $this->decodeCursor($cursor);

        $open = "o.source_key = 'trendyol' AND o.production_completed_at IS NULL AND o.operational_status = 'in_progress' AND o.production_stopped_at IS NULL";
        $where = $open . ' AND o.production_stage_id IN (' . implode(', ', array_fill(0, count($stages), '?')) . ')';
        $params = $stages;
        $searchSql = '';
        $searchParams = [];
        if ($search !== '') {
            $searchSql = " AND (o.order_number LIKE ? ESCAPE '!' OR o.source_order_id = ?)";
            $searchParams = [strtr(ltrim($search, '#'), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%', ltrim($search, '#')];
        }
        // The arrival at the current stage is the latest activity that moved the order into it (index order_uuid,
        // occurred_at); an order without such an event (older data) falls back to its last production change.
        $inner = 'SELECT ' . self::ORDER_COLUMNS . ",
                COALESCE((SELECT MAX(e.occurred_at) FROM order_activity_events e WHERE e.order_uuid = o.order_uuid AND e.to_stage_id = o.production_stage_id), o.production_changed_at, o.updated_at) AS entered_at
            FROM operational_orders o WHERE {$where}{$searchSql}";
        $sql = "SELECT * FROM ({$inner}) q";
        $outer = [...$params, ...$searchParams];
        if ($after !== null) {
            $sql .= ' WHERE q.entered_at < ? OR (q.entered_at = ? AND q.order_uuid < ?)';
            array_push($outer, $after[0], $after[0], $after[1]);
        }
        $sql .= ' ORDER BY q.entered_at DESC, q.order_uuid DESC LIMIT ' . ($size + 1);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($outer);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        $count = $this->pdo->prepare("SELECT o.production_stage_id, COUNT(*) FROM operational_orders o WHERE {$open} AND o.production_stage_id IN ('packing', 'delivery') GROUP BY o.production_stage_id");
        $count->execute();
        $totals = array_map('intval', $count->fetchAll(PDO::FETCH_KEY_PAIR));
        $perStage = [];
        foreach (self::QUEUE_STAGES as $id => $ordinal) {
            $perStage[] = ['id' => $id, 'ordinal' => $ordinal, 'total' => $totals[$id] ?? 0];
        }
        return [
            'generatedAt' => $this->iso($this->nowSql()),
            'stage' => ['id' => self::STAGE_ID, 'ordinal' => self::STAGE_ORDINAL],
            'filter' => $filter,
            'stages' => $perStage,
            'total' => array_sum(array_map(static fn (string $id): int => $totals[$id] ?? 0, $stages)),
            'items' => $this->items($rows),
            'nextCursor' => $more && $last !== null ? $this->encodeCursor((string) $last['entered_at'], (string) $last['order_uuid']) : null,
            'capabilities' => ['print' => $this->authorization->can($actor, self::PRINT)],
        ];
    }

    /**
     * Exact search for the scanner and the search field: an active Arasya order QR, `trendyol:<packageId>`, a Trendyol
     * order number or a package id. Only Trendyol orders are ever returned; any other order reads as not found. Several
     * packages of one marketplace order are all returned. An order no longer at stage 13 is found too (reprint).
     *
     * @return array<string, mixed>
     */
    public function resolve(EmployeeIdentity $actor, string $code): array
    {
        $this->authorization->require($actor, self::VIEW);
        $code = trim($code);
        if ($code === '' || strlen($code) > 128 || preg_match('/[[:cntrl:]]/', $code) === 1) {
            throw new ApiException(400, 'AWB_CODE_INVALID', 'The scanned code is not valid.');
        }
        $reference = QrReference::parsePayload($code);
        if ($reference !== null) {
            $statement = $this->pdo->prepare('SELECT q.status, q.expires_at, q.order_uuid FROM order_qr_references q WHERE q.qr_reference = ? LIMIT 1');
            $statement->execute([$reference->value]);
            $qr = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($qr)) {
                throw new ApiException(404, 'AWB_ORDER_NOT_FOUND', 'No Trendyol order matches this code.');
            }
            if ($qr['status'] !== 'active' || ($qr['expires_at'] !== null && (string) $qr['expires_at'] <= $this->nowSql())) {
                throw new ApiException(409, 'AWB_QR_INACTIVE', 'This QR code is no longer active.');
            }
            $rows = $this->ordersWhere('o.order_uuid = ?', [(string) $qr['order_uuid']]);
        } else {
            $value = ltrim($code, '#');
            if (str_starts_with(strtolower($value), 'trendyol:')) {
                $value = substr($value, 9);
            }
            if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $value) !== 1) {
                throw new ApiException(400, 'AWB_CODE_INVALID', 'The scanned code is not valid.');
            }
            $rows = $this->ordersWhere('(o.order_number = ? OR o.source_order_id = ?)', [$value, $value]);
        }
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['source_key'] === 'trendyol'));
        if ($rows === []) {
            throw new ApiException(404, 'AWB_ORDER_NOT_FOUND', 'No Trendyol order matches this code.');
        }
        return ['items' => $this->items($rows), 'capabilities' => ['print' => $this->authorization->can($actor, self::PRINT)]];
    }

    /** The minimal print history of one Trendyol order (latest 50 entries). @return array<string, mixed> */
    public function history(EmployeeIdentity $actor, string $orderId): array
    {
        $this->authorization->require($actor, self::VIEW);
        $order = $this->trendyolOrder($orderId);
        $statement = $this->pdo->prepare('SELECT e.event_id, e.event_type, e.print_kind, e.outcome, e.parcel_number, e.parcel_count, e.created_at, emp.display_name
            FROM trendyol_awb_print_events e INNER JOIN employees emp ON emp.employee_uuid = e.employee_uuid
            WHERE e.order_uuid = ? ORDER BY e.created_at DESC, e.event_id DESC LIMIT 50');
        $statement->execute([(string) $order['order_uuid']]);
        return [
            'order' => $this->items([$order])[0],
            'events' => array_map(fn (array $row): array => [
                'id' => (string) $row['event_id'],
                'type' => (string) $row['event_type'],
                'kind' => $row['print_kind'] === null ? null : (string) $row['print_kind'],
                'outcome' => (string) $row['outcome'],
                'parcel' => $row['parcel_number'] === null ? null : ['number' => (int) $row['parcel_number'], 'count' => (int) $row['parcel_count']],
                'at' => $this->iso((string) $row['created_at']),
                'by' => (string) $row['display_name'],
            ], $statement->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    // ---------------------------------------------------------------- label and print record

    /**
     * Retrieves the official label of the order's exact Trendyol package and records the retrieval (or its safe failure
     * category). Registers no print: the browser reports the print request separately (recordPrint).
     *
     * @return array{pdf: string, retrievalId: string, parcel: int, parcelCount: int}
     */
    public function label(EmployeeIdentity $actor, string $orderId, ?string $parcel, string $requestId): array
    {
        $this->authorization->require($actor, self::PRINT);
        $parcelNumber = $parcel === null || $parcel === '' ? 1 : (preg_match('/^[1-9][0-9]?$/D', $parcel) === 1 ? (int) $parcel : throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.'));
        $order = $this->trendyolOrder($orderId);
        $packageId = $this->linkedPackage($order);
        if ($order['production_stopped_at'] !== null) {
            throw new ApiException(409, 'AWB_ORDER_STOPPED', 'Production of this order is stopped (cancellation). The label is not printed.');
        }
        if ($this->marketplace === null || $this->labels === null) {
            throw new ApiException(503, 'AWB_LABELS_UNAVAILABLE', 'The Trendyol label service is not configured.');
        }
        $provider = null;
        $tracking = null;
        try {
            $raw = $this->marketplace->packagesByIds([$packageId])[$packageId] ?? null;
            if (!is_array($raw)) {
                throw new RuntimeException('AWB_PACKAGE_UNAVAILABLE');
            }
            $identity = self::shipmentIdentity($raw);
            $provider = $identity['provider'];
            $tracking = $identity['tracking'];
            if ($identity['packageId'] !== $packageId || $identity['orderNumber'] !== (string) $order['order_number']) {
                throw new RuntimeException('AWB_PACKAGE_MISMATCH');
            }
            if (in_array($identity['status'], self::CLOSED_STATUSES, true)) {
                throw new RuntimeException('AWB_PACKAGE_CLOSED');
            }
            if ($tracking === null) {
                throw new RuntimeException('TRENDYOL_LABEL_NOT_READY');
            }
            $labels = $this->labels->labels($tracking);
            if ($parcelNumber > count($labels)) {
                throw new ApiException(404, 'AWB_PARCEL_NOT_FOUND', 'This parcel has no label.', ['parcelCount' => count($labels)]);
            }
            $pdf = $this->labels->document($labels[$parcelNumber - 1]['url']);
        } catch (RuntimeException $error) {
            if ($error instanceof ApiException) {
                throw $error;
            }
            $failure = self::FAILURES[$error->getMessage()] ?? [503, 'AWB_TRENDYOL_UNAVAILABLE', 'unavailable'];
            $this->insertEvent((string) $order['order_uuid'], $packageId, $actor, 'label_failed', null, $failure[2], null, null, null, null, $provider, $tracking, $requestId);
            throw new ApiException($failure[0], $failure[1], 'The Trendyol label could not be retrieved.', ['reason' => $failure[2]]);
        }
        $retrievalId = $this->insertEvent((string) $order['order_uuid'], $packageId, $actor, 'label_retrieved', null, 'ok', $parcelNumber, count($labels), null, null, $provider, $tracking, $requestId);
        return ['pdf' => $pdf, 'retrievalId' => $retrievalId, 'parcel' => $parcelNumber, 'parcelCount' => count($labels)];
    }

    /**
     * The browser opened its print flow for a label this employee retrieved. Idempotent per (employee, attemptId).
     * The first request of an order is `initial`, every later one `reprint`; neither is ever refused or limited.
     *
     * @param array<string, mixed> $input {attemptId, retrievalId}
     * @return array<string, mixed>
     */
    public function recordPrint(EmployeeIdentity $actor, string $orderId, array $input, string $requestId): array
    {
        $this->authorization->require($actor, self::PRINT);
        $attempt = is_string($input['attemptId'] ?? null) ? strtolower($input['attemptId']) : '';
        $retrieval = is_string($input['retrievalId'] ?? null) ? strtolower($input['retrievalId']) : '';
        if (!self::isUuid($attempt) || !self::isUuid($retrieval)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
        }
        $order = $this->trendyolOrder($orderId);
        $packageId = $this->linkedPackage($order);
        return $this->transaction(function () use ($actor, $order, $packageId, $attempt, $retrieval, $requestId): array {
            // Serialises print records of one package (two stations at once get initial, then reprint).
            $this->pdo->prepare('SELECT package_id FROM trendyol_packages WHERE package_id = ? FOR UPDATE')->execute([$packageId]);
            $existing = $this->pdo->prepare('SELECT event_id, order_uuid, retrieval_event_id, print_kind, created_at FROM trendyol_awb_print_events WHERE employee_uuid = ? AND client_attempt_id = ?');
            $existing->execute([$actor->employeeUuid, $attempt]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if ($row['order_uuid'] !== $order['order_uuid'] || $row['retrieval_event_id'] !== $retrieval) {
                    throw new ApiException(409, 'IDEMPOTENCY_KEY_REUSED', 'This print attempt was already recorded for another label.');
                }
                return $this->printResponse((string) $order['order_uuid'], (string) $row['event_id'], (string) $row['print_kind'], (string) $row['created_at']);
            }
            $source = $this->pdo->prepare('SELECT order_uuid, employee_uuid, event_type, parcel_number, parcel_count, cargo_provider, tracking_suffix FROM trendyol_awb_print_events WHERE event_id = ?');
            $source->execute([$retrieval]);
            $retrieved = $source->fetch(PDO::FETCH_ASSOC);
            if (!is_array($retrieved) || $retrieved['event_type'] !== 'label_retrieved' || $retrieved['order_uuid'] !== $order['order_uuid'] || $retrieved['employee_uuid'] !== $actor->employeeUuid) {
                throw new ApiException(409, 'AWB_RETRIEVAL_UNKNOWN', 'Retrieve the label again before printing.');
            }
            $prior = $this->pdo->prepare("SELECT COUNT(*) FROM trendyol_awb_print_events WHERE order_uuid = ? AND event_type = 'print_requested'");
            $prior->execute([(string) $order['order_uuid']]);
            $kind = (int) $prior->fetchColumn() === 0 ? 'initial' : 'reprint';
            $now = $this->nowSql();
            $eventId = $this->insertEvent((string) $order['order_uuid'], $packageId, $actor, 'print_requested', $kind, 'ok',
                $retrieved['parcel_number'] === null ? null : (int) $retrieved['parcel_number'], $retrieved['parcel_count'] === null ? null : (int) $retrieved['parcel_count'],
                $retrieval, $attempt, $retrieved['cargo_provider'] === null ? null : (string) $retrieved['cargo_provider'], null, $requestId, $now,
                $retrieved['tracking_suffix'] === null ? null : (string) $retrieved['tracking_suffix']);
            return $this->printResponse((string) $order['order_uuid'], $eventId, $kind, $now);
        });
    }

    /**
     * The identity fields of a raw Order V2 package that the station relies on. Nothing else (address, customer, prices)
     * is read.
     *
     * @param array<string, mixed> $raw
     * @return array{packageId: int|null, orderNumber: string|null, status: string|null, tracking: string|null, provider: string|null}
     */
    public static function shipmentIdentity(array $raw): array
    {
        $id = $raw['shipmentPackageId'] ?? $raw['id'] ?? null;
        $number = $raw['orderNumber'] ?? null;
        $status = $raw['shipmentPackageStatus'] ?? $raw['status'] ?? null;
        $tracking = $raw['cargoTrackingNumber'] ?? null;
        $tracking = is_int($tracking) && $tracking > 0 ? (string) $tracking : (is_string($tracking) ? trim($tracking) : null);
        $provider = $raw['cargoProviderName'] ?? null;
        return [
            'packageId' => is_int($id) ? $id : null,
            'orderNumber' => is_int($number) ? (string) $number : (is_string($number) ? trim($number) : null),
            'status' => is_string($status) ? $status : null,
            'tracking' => $tracking !== null && TrendyolLabelClient::isTrackingNumber($tracking) ? $tracking : null,
            'provider' => is_string($provider) && preg_match('/^[\p{L}\p{N} ._&-]{1,60}$/uD', $provider) === 1 ? $provider : null,
        ];
    }

    // ---------------------------------------------------------------- internals

    /** @return array<string, mixed> */
    private function trendyolOrder(string $orderId): array
    {
        if (!self::isUuid(strtolower($orderId))) {
            throw new ApiException(404, 'AWB_ORDER_NOT_FOUND', 'No Trendyol order matches this code.');
        }
        $rows = $this->ordersWhere('o.order_uuid = ?', [strtolower($orderId)]);
        if ($rows === [] || $rows[0]['source_key'] !== 'trendyol') {
            throw new ApiException(404, 'AWB_ORDER_NOT_FOUND', 'No Trendyol order matches this code.');
        }
        return $rows[0];
    }

    /**
     * The Trendyol package of an order: its source identity and the intake release link must name the same package.
     *
     * @param array<string, mixed> $order
     */
    private function linkedPackage(array $order): int
    {
        $sourceId = (string) $order['source_order_id'];
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $sourceId) !== 1) {
            throw new ApiException(409, 'AWB_PACKAGE_MISMATCH', 'The order is not linked to exactly one Trendyol package.');
        }
        $statement = $this->pdo->prepare('SELECT package_id FROM trendyol_packages WHERE released_order_uuid = ?');
        $statement->execute([(string) $order['order_uuid']]);
        $linked = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        if ($linked !== [(int) $sourceId]) {
            throw new ApiException(409, 'AWB_PACKAGE_MISMATCH', 'The order is not linked to exactly one Trendyol package.');
        }
        return (int) $sourceId;
    }

    /** @param list<string> $params @return list<array<string, mixed>> */
    private function ordersWhere(string $condition, array $params): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::ORDER_COLUMNS . ",
                CASE WHEN o.production_stage_id IN ('packing', 'delivery')
                    THEN COALESCE((SELECT MAX(e.occurred_at) FROM order_activity_events e WHERE e.order_uuid = o.order_uuid AND e.to_stage_id = o.production_stage_id), o.production_changed_at, o.updated_at)
                    ELSE (SELECT MAX(e.occurred_at) FROM order_activity_events e WHERE e.order_uuid = o.order_uuid AND e.to_stage_id = 'packing') END AS entered_at
            FROM operational_orders o WHERE {$condition} ORDER BY o.created_at DESC, o.order_uuid DESC LIMIT 10");
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function items(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): string => (string) $row['order_uuid'], $rows);
        $statement = $this->pdo->prepare("SELECT order_uuid, COUNT(*) AS prints, MAX(created_at) AS last_at FROM trendyol_awb_print_events
            WHERE event_type = 'print_requested' AND order_uuid IN (" . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY order_uuid');
        $statement->execute($ids);
        $prints = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $prints[(string) $row['order_uuid']] = ['count' => (int) $row['prints'], 'lastAt' => $this->iso((string) $row['last_at'])];
        }
        return array_map(function (array $row) use ($prints): array {
            $atStation = isset(self::QUEUE_STAGES[(string) $row['production_stage_id']]) && $row['production_completed_at'] === null && $row['operational_status'] === 'in_progress' && $row['production_stopped_at'] === null;
            return [
                'orderId' => (string) $row['order_uuid'],
                'globalOrderId' => (string) $row['global_order_id'],
                'orderNumber' => (string) $row['order_number'],
                'packageId' => (string) $row['source_order_id'],
                'stage' => ['id' => (string) $row['production_stage_id'], 'ordinal' => \Arasya\Operations\Production\CanonicalProductionWorkflowContract::STAGES[(string) $row['production_stage_id']] ?? null],
                'inQueue' => $atStation,
                'productionCompleted' => $row['production_completed_at'] !== null,
                'productionStopped' => $row['production_stopped_at'] !== null,
                'enteredStageAt' => $row['entered_at'] === null ? null : $this->iso((string) $row['entered_at']),
                'printed' => $prints[(string) $row['order_uuid']] ?? ['count' => 0, 'lastAt' => null],
            ];
        }, $rows);
    }

    /** @return array<string, mixed> */
    private function printResponse(string $orderUuid, string $eventId, string $kind, string $at): array
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) AS prints, MAX(created_at) AS last_at FROM trendyol_awb_print_events WHERE order_uuid = ? AND event_type = 'print_requested'");
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: ['prints' => 0, 'last_at' => null];
        return [
            'print' => ['id' => $eventId, 'kind' => $kind, 'requestedAt' => $this->iso($at)],
            'printed' => ['count' => (int) $row['prints'], 'lastAt' => $row['last_at'] === null ? null : $this->iso((string) $row['last_at'])],
        ];
    }

    private function insertEvent(string $orderUuid, int $packageId, EmployeeIdentity $actor, string $type, ?string $kind, string $outcome, ?int $parcel, ?int $parcels,
        ?string $retrieval, ?string $attempt, ?string $provider, ?string $tracking, string $requestId, ?string $now = null, ?string $trackingSuffix = null): string
    {
        $eventId = Uuid::v4();
        $suffix = $trackingSuffix ?? ($tracking === null ? null : substr($tracking, -4));
        $this->pdo->prepare('INSERT INTO trendyol_awb_print_events (event_id, order_uuid, package_id, employee_uuid, event_type, print_kind, outcome, parcel_number, parcel_count,
                retrieval_event_id, client_attempt_id, cargo_provider, tracking_suffix, request_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$eventId, $orderUuid, $packageId, $actor->employeeUuid, $type, $kind, $outcome, $parcel, $parcels, $retrieval, $attempt, $provider, $suffix,
                mb_substr(preg_replace('/[^A-Za-z0-9._:-]/', '', $requestId) ?: 'none', 0, 100), $now ?? $this->nowSql()]);
        return $eventId;
    }

    /** @param callable(): array<string, mixed> $command @return array<string, mixed> */
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
                if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                    throw new ApiException(409, 'IDEMPOTENCY_KEY_REUSED', 'This print attempt was already recorded.');
                }
                throw $error;
            }
        }
    }

    private function encodeCursor(string $enteredAt, string $orderUuid): string
    {
        return rtrim(strtr(base64_encode($enteredAt . '|' . $orderUuid), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: string} */
    private function decodeCursor(string $cursor): array
    {
        $decoded = strlen($cursor) <= 120 ? base64_decode(strtr($cursor, '-_', '+/'), true) : false;
        $parts = is_string($decoded) ? explode('|', $decoded) : [];
        if (count($parts) !== 2 || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/D', $parts[0]) !== 1 || !self::isUuid($parts[1])) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Request fields are invalid.');
        }
        return [$parts[0], $parts[1]];
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function nowSql(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function iso(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
