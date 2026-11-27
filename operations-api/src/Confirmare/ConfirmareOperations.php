<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Cancellation\CancellationControl;
use Arasya\Operations\Document\DocumentScopePolicy;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessClient;
use Arasya\Operations\Integration\SourceAccess\SourceAccessException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
use Arasya\Operations\Integration\SourceAccess\SourceAccessTarget;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Production\WorkflowCohort;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * The operations Operations relays to the shop that are neither finance nor invoice actions (API 2.46.0, owner master
 * decisions 2026-10-25, YD SOFT 2.20.0 Operations). Operations decides WHO and WHEN; the shop checks the order, the money
 * and the provider. Codes, hashes and instants only (order_source_operations, confirmare_reactivations).
 *
 * - reactivate (Confirmare operator of the order or supervisor): "Clientul a revenit după anulare". The first one of an
 *   order is ordinary (no payment needed); every later one needs the bank exception entry of the money that arrived on
 *   the cancelled order and an employee who also holds a finance scope of the shop (owner K-07). After the shop put the
 *   order back on hold, the cancellation hold is released once the shop's event arrived (`settleReactivations`, also in
 *   the cycle worker), an approved Confirmare returns to pending (everything is checked with the customer again) and a
 *   new call cycle starts.
 * - awb (the shop employee the order was routed to, or an invoice manager; production stage 12 or later): one FAN
 *   Courier AWB; the shop computes the collection from its one money position and refuses an amount the employee did not
 *   see. Address change after the AWB: a FAN Courier panel task and the employee's declaration (never "carrier confirmed").
 * - card refund (owner K-09/K-11): one click by the order's operator or a supervisor holding the finance `approve` scope of
 *   the shop; NETOPIA has no refund API, so the shop records a manual task; finance records the panel refund (record
 *   scope) and ANOTHER finance employee verifies it (approve scope), then the customer is e-mailed.
 */
final class ConfirmareOperations
{
    public const KINDS = ['order_reactivate', 'awb_create', 'awb_address_task', 'awb_address_done', 'awb_cod_corrected', 'awb_cancel', 'address_update', 'card_refund_full', 'card_refund_record', 'card_refund_verify'];
    /** API 2.83.0 (YD SOFT 2.36.0): why the employee corrects an AWB. */
    public const CANCEL_REASONS = ['wrong_parcels', 'wrong_weight', 'wrong_address', 'wrong_contact', 'other'];
    /** API 2.83.0: the delivery fields the employee may correct (YD SOFT Operations::ADDRESS_INPUT). */
    public const ADDRESS_FIELDS = ['firstName', 'lastName', 'phone', 'county', 'city', 'street', 'streetNo', 'building', 'entrance', 'floor', 'apartment', 'postcode', 'note'];
    /** API 2.83.0: the shop's address check codes (YD SOFT AddressCheck). */
    public const ADDRESS_CODES = ['name_missing', 'phone_missing', 'phone_invalid', 'county_missing', 'county_unknown', 'locality_missing', 'locality_unknown', 'street_missing',
        'number_missing', 'postcode_invalid', 'locality_unverified', 'locality_corrected', 'sector_missing'];
    /** Production stages from which the AWB may be made (stage 12 Control calitate, 13 Împachetare, 14 Livrare). */
    public const AWB_STAGES = ['quality-control', 'packing', 'delivery'];

    /**
     * API 2.77.8 (owner 2026-11-25, Trend Home 64790): SQL condition "the order's AWB is made" for the order alias given:
     * an applied AWB request that no later root reopening (`awb_reopened`, see reopenAwb) replaced. An AWB cancelled in
     * the shop after it was made used to hide the order from every AWB queue for good.
     */
    public static function awbDoneSql(string $alias): string
    {
        return "EXISTS (SELECT 1 FROM order_source_operations awb_done WHERE awb_done.order_uuid = {$alias}.order_uuid AND awb_done.kind = 'awb_create' AND awb_done.state = 'applied'
            AND NOT EXISTS (SELECT 1 FROM order_source_operations awb_re WHERE awb_re.order_uuid = awb_done.order_uuid AND awb_re.kind IN ('awb_reopened', 'awb_cancel') AND awb_re.state = 'applied' AND awb_re.requested_at > awb_done.requested_at))";
    }
    public const REACTIVATION_REASONS = ['customer_returned', 'customer_paid_late'];
    private const FIELDS = [
        'order_reactivate' => ['reason', 'exceptionEntryId'],
        'awb_create' => ['parcels', 'weightKg', 'expectedCod'],
        'awb_address_task' => ['reasonNote'],
        'awb_address_done' => ['reasonNote'],
        // API 2.50.0 (YD SOFT 2.23.0): the employee states the cash on delivery corrected in the FAN Courier panel.
        'awb_cod_corrected' => ['amount', 'reasonNote'],
        // API 2.83.0 (YD SOFT 2.36.0, Store Workspace V1).
        'awb_cancel' => ['reason'],
        'address_update' => ['address'],
        'card_refund_full' => [],
        'card_refund_record' => ['amount', 'reference', 'valueDate'],
        'card_refund_verify' => ['entryId', 'decision'],
    ];
    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D';
    private const NOT_SENT = ['SOURCE_ACCESS_NOT_CONFIGURED', 'SOURCE_ADDRESS_REFUSED', 'SOURCE_ACCESS_DENIED'];
    /** Every refusal code the shop may name (anything else is SOURCE_ERROR). */
    public const CODES = ['invalid_request', 'order_busy', 'internal_error', 'reactivation_disabled', 'awb_disabled', 'card_refund_disabled', 'order_not_cancelled', 'order_refunded',
        'payment_not_found', 'order_closed', 'invoice_missing', 'finance_unavailable', 'payment_verifying', 'finance_attention', 'address_incomplete', 'phone_missing',
        'shipping_mismatch', 'awb_exists', 'awb_unavailable', 'cod_changed', 'cod_mismatch', 'cod_not_mismatched', 'awb_refused', 'awb_missing', 'label_unavailable', 'address_task_missing', 'address_task_open',
        'not_card_payment', 'card_payment_unverified', 'nothing_to_refund', 'refund_request_missing', 'refund_pending', 'finance_open', 'amount_exceeds_received',
        'duplicate_reference', 'entry_not_found', 'entry_not_pending', 'same_employee', 'refund_record_failed', 'idempotency_conflict',
        'awb_correction_disabled', 'address_edit_disabled', 'awb_picked_up', 'pickup_unknown', 'cancel_uncertain', 'cancel_refused', 'address_invalid', 'awb_not_listed'];
    public const RESULTS = ['reactivated', 'created', 'unknown', 'panel_required', 'panel_declared', 'manual_required', 'already_requested', 'refund_recorded', 'refund_verified', 'refund_rejected',
        'cancelled', 'address_saved', 'unchanged'];

    private ?bool $installed = null;
    private ?bool $storeColumns = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly SourceAccessRegistry $registry,
        private readonly SourceAccessClient $client,
        private readonly ConfirmareScopePolicy $confirmare,
        private readonly FinanceScopePolicy $finance,
        private readonly InvoiceScopePolicy $invoices,
        private readonly DocumentScopePolicy $documents,
        private readonly ConfirmareService $service,
        #[\SensitiveParameter] private readonly string $hmacKey,
    ) {
    }

    // ---- Reads -----------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> what the actor may do on the order and the shop's state of it */
    public function read(EmployeeIdentity $actor, string $globalOrderId, string $requestId): array
    {
        $row = $this->row($this->globalId($globalOrderId));
        if ($row === null || !$this->sees($actor, $row)) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        $roles = $this->roles($actor, $row);
        $target = $this->registry->find((string) $row['source_key']);
        $role = $roles['awb'] ?? $roles['reactivate'] ?? $roles['refund'] ?? $roles['finance'] ?? 'operator';
        $shop = null;
        if ($target !== null) {
            try {
                $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_OPERATION, $this->payload($row, $actor->employeeUuid, $role, $requestId) + ['action' => 'operations_state']);
                $shop = $answer['status'] === 200 ? self::shopState($answer['body']) : null;
            } catch (SourceAccessException) {
                $shop = null;
            }
        }
        return [
            'available' => $shop !== null,
            'shop' => $shop,
            'stage' => (string) $row['production_stage_id'],
            'awbStage' => in_array((string) $row['production_stage_id'], self::AWB_STAGES, true),
            'allowed' => $this->allowed($actor, $row, $roles),
            'reactivations' => $this->reactivations((string) $row['order_uuid']),
            'open' => $this->openOperations($actor, $row, $roles),
        ];
    }

    /** The FAN Courier label of the order's AWB (PDF bytes), for the AWB roles only. */
    public function label(EmployeeIdentity $actor, string $globalOrderId, string $requestId): string
    {
        $row = $this->row($this->globalId($globalOrderId));
        if ($row === null || !$this->sees($actor, $row)) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        $role = $this->roles($actor, $row)['awb'] ?? null;
        $target = $this->registry->find((string) $row['source_key']);
        if ($role === null || $target === null) {
            throw new ApiException(403, 'CONFIRMARE_OPERATION_DENIED', 'Only the shop employee of this order prints its AWB.');
        }
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_OPERATION, $this->payload($row, $actor->employeeUuid, $role, $requestId) + ['action' => 'awb_label']);
        } catch (SourceAccessException $e) {
            throw new ApiException(503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'The shop could not be asked.', ['reason' => strtolower($e->codeName)]);
        }
        if ($answer['status'] === 409 && ($answer['body']['error']['code'] ?? null) === 'cod_mismatch') {
            // YD SOFT 2.23.0: the AWB's cash on delivery differs from what the customer owes now; the parcel does not leave.
            throw new ApiException(409, 'CONFIRMARE_AWB_COD_MISMATCH', 'The AWB collects another amount than the customer owes now. Correct it in FAN Courier first.');
        }
        $pdf = $answer['status'] === 200 && is_string($answer['body']['pdf'] ?? null) ? base64_decode($answer['body']['pdf'], true) : false;
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF')) {
            throw new ApiException(409, 'CONFIRMARE_AWB_LABEL_UNAVAILABLE', 'FAN Courier gave no label for this AWB now.');
        }
        return $pdf;
    }

    /**
     * API 2.83.0 (owner acceptance 2026-11-26): READ ONLY check of a delivery address the employee prepares, also while the
     * order still has its AWB (the employee checks the new address BEFORE the AWB is cancelled, so a valid AWB is never
     * cancelled for an address the shop would refuse). The shop runs the same check as the address change (YD SOFT
     * `address_check`, switch `addressEdit`); nothing is written anywhere and nothing is recorded.
     *
     * @param array<string, mixed> $input {address}
     * @return array{issues: list<string>, warnings: list<string>, awbActive: bool}
     */
    public function addressCheck(EmployeeIdentity $actor, string $globalOrderId, array $input, string $requestId): array
    {
        $row = $this->row($this->globalId($globalOrderId));
        if ($row === null || !$this->sees($actor, $row)) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        $role = $this->roles($actor, $row)['awb'] ?? null;
        $target = $this->registry->find((string) $row['source_key']);
        if ($role === null || $target === null) {
            throw new ApiException(403, 'CONFIRMARE_OPERATION_DENIED', 'Only the shop employee of this order corrects its delivery address.');
        }
        if (array_diff(array_keys($input), ['address']) !== []) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Only address is accepted.', ['field' => 'address']);
        }
        self::validateAddress($input['address'] ?? null);
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_OPERATION, $this->payload($row, $actor->employeeUuid, $role, $requestId) + ['action' => 'address_check', 'address' => $input['address']]);
        } catch (SourceAccessException $e) {
            throw new ApiException(503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'The shop could not be asked.', ['reason' => strtolower($e->codeName)]);
        }
        $code = $answer['body']['error']['code'] ?? null;
        if ($answer['status'] === 409 && $code === 'address_edit_disabled') {
            throw new ApiException(409, 'CONFIRMARE_OPERATION_REFUSED', 'The delivery address correction is not switched on in this shop.', ['reason' => 'address_edit_disabled', 'blockers' => ['address_edit_disabled']]);
        }
        if ($answer['status'] !== 200 || ($answer['body']['checked'] ?? null) !== true) {
            throw new ApiException(503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'The shop gave no address check.', ['reason' => 'source_error']);
        }
        $codes = static fn (mixed $list): array => is_array($list) ? array_values(array_filter($list, static fn (mixed $c): bool => is_string($c) && in_array($c, self::ADDRESS_CODES, true))) : [];
        return ['issues' => $codes($answer['body']['issues'] ?? null), 'warnings' => $codes($answer['body']['warnings'] ?? null), 'awbActive' => ($answer['body']['awbActive'] ?? null) === true];
    }

    /**
     * The orders of the actor's shops waiting for their AWB (stage 12 or later, approved, not closed), for the shop
     * employee the order was routed to and the invoice managers. The shop's AWB state is read per order on its screen.
     * API 2.68.2 (owner 2026-11-13): an order whose stage 14 (Livrare) was completed without an AWB stays in the queue
     * until its AWB is made; completing production never hides a parcel that still has to leave.
     * API 2.77.3 (owner 2026-11-23): the orders whose AWB is made and the orders routed to another employee are left out in
     * SQL, before the 300-row limit. The limit used to be taken first: 420 orders with their AWB made, still at stage 14,
     * filled it and every newer order waiting for its AWB (26 that day) was never listed.
     *
     * @return array<string, mixed>
     */
    public function awbQueue(EmployeeIdentity $actor, ?string $source): array
    {
        $sources = array_values(array_filter(['trendhome', 'outletperdele'], fn (string $s): bool => ($source === null || $source === $s)
            && ($this->invoices->allows($actor, InvoiceScopePolicy::ISSUE, $s) || $this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, $s))));
        if ($sources === [] || $actor->isRoot) {
            return ['items' => [], 'sources' => []];
        }
        $marks = implode(', ', array_fill(0, count($sources), '?'));
        $stages = implode(', ', array_fill(0, count(self::AWB_STAGES), '?'));
        // The invoice manager of a shop sees all its waiting orders; any other employee only the orders routed to them.
        $managed = array_values(array_filter($sources, fn (string $s): bool => $this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, $s)));
        $mine = $managed === [] ? 'a.employee_uuid = ?' : '(o.source_key IN (' . implode(', ', array_fill(0, count($managed), '?')) . ') OR a.employee_uuid = ?)';
        // API 2.84.2: an attempt the shop never received (rejected `not_received`, settled by the lost-answer check) did
        // nothing at the shop or at FAN Courier and has no reason to correct, so it is not the order's last attempt. It used to
        // put the order under "Needs correction" (Trend Home 65714, 2026-11-25) although its AWB was ready to be made.
        $statement = $this->pdo->prepare("SELECT o.global_order_id, o.order_number, o.source_key, o.production_stage_id,
                (SELECT op.state FROM order_source_operations op WHERE op.order_uuid = o.order_uuid AND op.kind = 'awb_create'
                    AND NOT (op.state = 'rejected' AND op.outcome <=> 'not_received') ORDER BY op.requested_at DESC LIMIT 1) AS last_awb
            FROM operational_orders o
            INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid AND c.status = 'approved'
            LEFT JOIN order_shop_assignments a ON a.active_order_uuid = o.order_uuid
            WHERE o.source_key IN ({$marks}) AND o.production_stage_id IN ({$stages}) AND o.operational_status <> 'unavailable' AND o.cancellation_state IS NULL
              AND {$mine}
              AND NOT " . self::awbDoneSql('o') . "
            ORDER BY o.production_changed_at, o.order_uuid LIMIT 300");
        $statement->execute([...$sources, ...self::AWB_STAGES, ...$managed, $actor->employeeUuid]);
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = ['globalOrderId' => (string) $row['global_order_id'], 'orderNumber' => (string) $row['order_number'], 'source' => (string) $row['source_key'],
                'stage' => (string) $row['production_stage_id'], 'lastAttempt' => $row['last_awb'] === null ? null : (string) $row['last_awb']];
        }
        return ['items' => $items, 'sources' => $sources];
    }

    // ---- Actions ---------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function act(EmployeeIdentity $actor, string $globalOrderId, string $kind, array $input, string $key, string $requestId): array
    {
        $this->requireInstalled();
        if (!isset(self::FIELDS[$kind])) {
            throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
        }
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
        if (array_diff(array_keys($input), self::FIELDS[$kind]) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only ' . implode(', ', self::FIELDS[$kind]) . ' are accepted.');
        }
        $globalId = $this->globalId($globalOrderId);
        $row = $this->row($globalId);
        if ($row === null || !$this->sees($actor, $row)) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        $roles = $this->roles($actor, $row);
        $role = self::roleFor($kind, $roles);
        if ($role === null) {
            throw new ApiException(403, 'CONFIRMARE_OPERATION_DENIED', 'You have no permission for this operation on this order.');
        }
        $target = $this->registry->find((string) $row['source_key']);
        if ($target === null) {
            throw new ApiException(409, 'CONFIRMARE_OPERATION_DISABLED', 'Source access is not configured for this shop.');
        }
        $hmac = hash_hmac('sha256', json_encode([$globalId, $kind, $input], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->hmacKey, true);
        $existing = $this->operation($actor->employeeUuid, $key);
        if ($existing !== null) {
            if ($existing['order_uuid'] !== $row['order_uuid'] || !hash_equals((string) $existing['request_hmac'], $hmac)) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
            }
            return $this->settled($existing, $row, $target, $actor, $role, $requestId);
        }
        $uuid = Uuid::v4();
        $this->pdo->beginTransaction();
        try {
            $locked = $this->lockRow((string) $row['order_uuid']);
            $blockers = $locked === null ? ['order_unavailable'] : $this->blockers($kind, $locked, $input, $actor);
            if ($blockers !== []) {
                throw new ApiException(409, 'CONFIRMARE_OPERATION_NOT_ALLOWED', 'The operation is not allowed now.', ['reason' => $blockers[0], 'blockers' => $blockers]);
            }
            if ($kind === 'order_reactivate') {
                $this->insertReactivation($locked, $actor, $input, $key, $requestId);
            }
            $this->pdo->prepare('INSERT INTO order_source_operations (operation_uuid, order_uuid, global_order_id, source_key, kind, employee_uuid, access_role, idempotency_key, request_hmac, state, request_id, requested_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$uuid, $row['order_uuid'], $globalId, $row['source_key'], $kind, $actor->employeeUuid, $role, $key, $hmac, 'pending', self::requestId($requestId), $this->now()]);
            $this->recordRequestFacts($uuid, $kind, (string) $row['order_uuid'], $input);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof ApiException) {
                throw $e;
            }
            if ($e instanceof PDOException && $this->operation($actor->employeeUuid, $key) !== null) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key is already in use.');
            }
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The operation could not be recorded, so it was not sent. Try again.');
        }
        $body = $this->payload($row, $actor->employeeUuid, $role, $requestId) + ['action' => $kind, 'idempotencyKey' => $key] + $input;
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_OPERATION, $body);
        } catch (SourceAccessException $e) {
            if (in_array($e->codeName, self::NOT_SENT, true)) {
                $this->complete($uuid, 'rejected', strtolower($e->codeName), null, $kind, $row, $key);
                throw new ApiException(503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'The shop could not be asked. Nothing was done.', ['reason' => strtolower($e->codeName)]);
            }
            $this->complete($uuid, 'unknown', null, null, $kind, $row, $key);
            return ['state' => 'unknown', 'idempotencyKey' => $key];
        }
        return $this->conclude($uuid, $answer, $kind, $row, $key, $requestId);
    }

    /** Settles an operation whose answer was lost (the shop's stored answer of the key). @return array<string, mixed> */
    public function reconcile(EmployeeIdentity $actor, string $globalOrderId, mixed $key, string $requestId): array
    {
        if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'idempotencyKey is required.');
        }
        $row = $this->row($this->globalId($globalOrderId));
        $found = $this->operation($actor->employeeUuid, $key);
        if ($found === null && $row !== null && $this->sees($actor, $row)) {
            // API 2.74.1: a colleague's lost answer is settled by anyone holding the same role on the order (the open
            // operation blocks the next one for everybody, and its key is lost when the page that sent it is closed).
            $other = $this->operationOfOrder((string) $row['order_uuid'], $key);
            if ($other !== null && self::roleFor((string) $other['kind'], $this->roles($actor, $row)) !== null) {
                $found = $other;
            }
        }
        if ($row === null || $found === null || $found['order_uuid'] !== $row['order_uuid']) {
            throw new ApiException(404, 'CONFIRMARE_OPERATION_NOT_FOUND', 'No such operation of yours on this order.');
        }
        $target = $this->registry->find((string) $row['source_key']);
        if ($target === null) {
            throw new ApiException(409, 'CONFIRMARE_OPERATION_DISABLED', 'Source access is not configured for this shop.');
        }
        return $this->settled($found, $row, $target, $actor, (string) $found['access_role'], $requestId);
    }

    /**
     * After the shop reactivated an order: release the cancellation hold once the shop's event says the order is active
     * again (never while the source still reports it cancelled), and return an approved Confirmare to pending. Run by
     * the request itself and by the cycle worker; idempotent. @return int reactivations settled now
     */
    public function settleReactivations(int $limit = 50): int
    {
        if (!$this->installed()) {
            return 0;
        }
        $statement = $this->pdo->prepare("SELECT r.reactivation_uuid, r.order_uuid, r.global_order_id, r.employee_uuid, r.idempotency_key FROM confirmare_reactivations r
            INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE r.state = 'applied' AND o.cancellation_state = 'held' LIMIT " . max(1, min(500, $limit)));
        $statement->execute();
        $settled = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $reactivation) {
            $this->pdo->beginTransaction();
            try {
                $order = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
                $order->execute([$reactivation['order_uuid']]);
                $control = $this->pdo->prepare("SELECT version, source_status, hold_status FROM order_cancellation_controls WHERE order_uuid = ? FOR UPDATE");
                $control->execute([$reactivation['order_uuid']]);
                $c = $control->fetch(PDO::FETCH_ASSOC);
                if (!is_array($c) || $c['hold_status'] !== CancellationControl::HELD || $c['source_status'] === 'cancelled') {
                    $this->pdo->rollBack();
                    continue;
                }
                (new CancellationControl($this->pdo, $this->clock))->release((string) $reactivation['order_uuid'], (string) $reactivation['employee_uuid'], (int) $c['version'],
                    'Clientul a revenit după anulare (Confirmare).', 'reactivation-' . substr((string) $reactivation['reactivation_uuid'], 0, 8), (string) $reactivation['idempotency_key'] . '-release');
                $this->pdo->commit();
                $settled++;
            } catch (Throwable) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            }
        }
        return $settled;
    }

    /**
     * API 2.77.8 (owner 2026-11-25, Trend Home 64790): root reopens the AWB step of an order whose AWB was made through Staff
     * and later cancelled in the shop (WP admin, FAN Courier panel). Staff counted the applied request as "AWB made" for
     * ever, so the order left every AWB queue while the shop had no AWB at all.
     *
     * The shop is asked first and must answer that it has no AWB and no AWB attempt open (`awb.state` none), the order must
     * be at an AWB stage, approved, active and not completed in the shop. Nothing is sent to the shop and no AWB is made:
     * an `awb_reopened` row is recorded, so the order is back in its shop employee's AWB queue, who makes the AWB. Root only
     * (the desk writes the reason to the IAM audit). Idempotent per key.
     *
     * @return array<string, mixed>
     */
    public function reopenAwb(EmployeeIdentity $actor, string $globalOrderId, string $key, string $requestId): array
    {
        $this->requireInstalled();
        if (!$this->finance->rootActs($actor)) {
            throw new ApiException(403, 'CONFIRMARE_OPERATION_DENIED', 'Only the root intervention desk reopens an AWB.');
        }
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
        $globalId = $this->globalId($globalOrderId);
        $row = $this->row($globalId);
        if ($row === null) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        $hmac = hash_hmac('sha256', json_encode([$globalId, 'awb_reopened', []], JSON_THROW_ON_ERROR), $this->hmacKey, true);
        $existing = $this->operation($actor->employeeUuid, $key);
        if ($existing !== null) {
            if ($existing['order_uuid'] !== $row['order_uuid'] || !hash_equals((string) $existing['request_hmac'], $hmac)) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
            }
            return ['state' => (string) $existing['state'], 'idempotencyKey' => $key, 'result' => $existing['result']];
        }
        $refuse = static fn (string $reason, array $extra = []): ApiException => new ApiException(409, 'CONFIRMARE_OPERATION_NOT_ALLOWED', 'The AWB cannot be reopened now.', ['reason' => $reason, 'blockers' => [$reason]] + $extra);
        $target = $this->registry->find((string) $row['source_key']);
        if ($target === null) {
            throw new ApiException(409, 'CONFIRMARE_OPERATION_DISABLED', 'Source access is not configured for this shop.');
        }
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_OPERATION, $this->payload($row, $actor->employeeUuid, 'manager', $requestId) + ['action' => 'operations_state']);
        } catch (SourceAccessException $e) {
            throw new ApiException(503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'The shop could not be asked. Nothing was done.', ['reason' => strtolower($e->codeName)]);
        }
        if ($answer['status'] !== 200 || !is_array($answer['body']['awb'] ?? null)) {
            throw new ApiException(503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'The shop gave no AWB state. Nothing was done.', ['reason' => 'source_error']);
        }
        $shop = self::shopState($answer['body']);
        $this->pdo->beginTransaction();
        try {
            $locked = $this->lockRow((string) $row['order_uuid']);
            $reason = match (true) {
                $locked === null => 'order_unavailable',
                $locked['workflow_cohort'] !== WorkflowCohort::CONFIRMARE => 'not_confirmare',
                !in_array((string) $locked['production_stage_id'], self::AWB_STAGES, true) => 'stage_not_ready',
                $locked['status'] !== 'approved' => 'confirmare_not_approved',
                $locked['cancellation_state'] === 'held' || $locked['operational_status'] === 'unavailable' => 'order_cancellation',
                (int) $this->scalar('SELECT COUNT(*) FROM operational_orders oo WHERE oo.order_uuid = ? AND ' . self::awbDoneSql('oo'), [$row['order_uuid']]) === 0 => 'awb_not_made',
                $this->operationOpen((string) $row['order_uuid'], 'awb_create') => 'operation_open',
                $shop['awb']['state'] !== 'none' => $shop['awb']['state'] === 'created' ? 'awb_exists' : 'awb_unknown',
                in_array($shop['orderStatus'], ['completed', 'cancelled', 'refunded', 'failed'], true) => 'order_closed',
                default => null,
            };
            if ($reason !== null) {
                throw $refuse($reason, ['shop' => $shop]);
            }
            $now = $this->now();
            $this->pdo->prepare('INSERT INTO order_source_operations (operation_uuid, order_uuid, global_order_id, source_key, kind, employee_uuid, access_role, idempotency_key, request_hmac, state, outcome, result, request_id, requested_at, completed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([Uuid::v4(), $row['order_uuid'], $globalId, $row['source_key'], 'awb_reopened', $actor->employeeUuid, 'manager', $key, $hmac,
                'applied', 'applied', 'reopened', self::requestId($requestId), $now, $now]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof ApiException) {
                throw $e;
            }
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The reopening could not be recorded. Nothing was done.');
        }
        return ['state' => 'applied', 'idempotencyKey' => $key, 'result' => 'reopened', 'shop' => $shop];
    }

    // ---- Rules ------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row the locked order
     * @param array<string, mixed> $input
     * @return list<string>
     */
    private function blockers(string $kind, array $row, array $input, EmployeeIdentity $actor): array
    {
        $codes = [];
        if ($row['workflow_cohort'] !== WorkflowCohort::CONFIRMARE) {
            $codes[] = 'not_confirmare';
        }
        if (in_array($kind, ['awb_create', 'awb_address_task', 'awb_address_done', 'awb_cod_corrected', 'awb_cancel', 'address_update'], true)) {
            if (!in_array((string) $row['production_stage_id'], self::AWB_STAGES, true)) {
                // Stage 11 or earlier: the order is not ready for the courier.
                $codes[] = 'stage_not_ready';
            }
            if ($row['status'] !== 'approved') {
                $codes[] = 'confirmare_not_approved';
            }
            if ($row['cancellation_state'] === 'held' || $row['operational_status'] === 'unavailable') {
                $codes[] = 'order_cancellation';
            }
            // API 2.83.0: an AWB, its cancellation and a delivery address change never run side by side on one order.
            if (in_array($kind, ['awb_create', 'awb_cancel', 'address_update'], true)
                && ($this->operationOpen((string) $row['order_uuid'], 'awb_create') || $this->operationOpen((string) $row['order_uuid'], 'awb_cancel') || $this->operationOpen((string) $row['order_uuid'], 'address_update'))) {
                $codes[] = 'operation_open';
            }
            if ($kind === 'awb_cancel' && !in_array($input['reason'] ?? null, self::CANCEL_REASONS, true)) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'reason must be one of ' . implode(', ', self::CANCEL_REASONS) . '.', ['field' => 'reason']);
            }
            if ($kind === 'address_update') {
                self::validateAddress($input['address'] ?? null);
            }
        }
        if ($kind === 'order_reactivate') {
            if (!in_array($input['reason'] ?? null, self::REACTIVATION_REASONS, true)) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'reason must be customer_returned or customer_paid_late.', ['field' => 'reason']);
            }
            if ($row['production_stage_id'] !== \Arasya\Operations\Order\OrderProjectionWriter::INITIAL_STAGE_ID) {
                // Production started: a manager decides (the cancellation release of the factory).
                $codes[] = 'production_started';
            }
            if ($row['operational_status'] !== 'unavailable' && $row['cancellation_state'] !== 'held') {
                $codes[] = 'order_not_cancelled';
            }
            $previous = (int) $this->scalar("SELECT COUNT(*) FROM confirmare_reactivations WHERE order_uuid = ? AND state IN ('applied', 'pending', 'unknown')", [$row['order_uuid']]);
            if ((int) $this->scalar("SELECT COUNT(*) FROM confirmare_reactivations WHERE order_uuid = ? AND state IN ('pending', 'unknown')", [$row['order_uuid']]) > 0) {
                $codes[] = 'operation_open';
            }
            if ($previous >= 1) {
                // Owner K-07: after the first reactivation only real money proves the customer's intent.
                if (!isset($input['exceptionEntryId'])) {
                    $codes[] = 'payment_required';
                } elseif (!$this->hasFinanceScope($actor, (string) $row['source_key'])) {
                    $codes[] = 'finance_scope_required';
                }
            }
            if (isset($input['exceptionEntryId']) && (!is_string($input['exceptionEntryId']) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $input['exceptionEntryId']) !== 1)) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'exceptionEntryId names the bank exception entry of the payment.', ['field' => 'exceptionEntryId']);
            }
        }
        if (str_starts_with($kind, 'card_refund') && $this->operationOpen((string) $row['order_uuid'], $kind)) {
            $codes[] = 'operation_open';
        }
        if ($kind !== 'order_reactivate' && $kind !== 'card_refund_record' && $kind !== 'card_refund_verify' && $kind !== 'card_refund_full' && ConfirmareFinance::actionOpen($this->pdo, (string) $row['order_uuid'])) {
            $codes[] = 'finance_action_open';
        }
        return array_values(array_unique($codes));
    }

    /**
     * The roles of the actor on the order: reactivate/refund (the Confirmare operator of the order or a supervisor; the
     * refund also needs the finance approve scope), awb (the routed shop employee with the invoice issue scope and the
     * document permission, or an invoice manager), financeRecord / financeApprove.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function roles(EmployeeIdentity $actor, array $row): array
    {
        if ($this->finance->rootActs($actor)) {
            // API 2.57.0: the root intervention desk holds every operation role of the order (the shop still keeps four
            // eyes on a card refund: root records it, another finance employee verifies it, or the reverse).
            return ['reactivate' => 'operator', 'refund' => 'operator', 'awb' => 'manager', 'financeRecord' => 'finance', 'financeApprove' => 'finance', 'finance' => 'finance'];
        }
        if ($actor->isRoot || !$actor->isOperationallyActive() || $actor->mustChangePassword || !$actor->hasApplication('staff')) {
            return [];
        }
        $source = (string) $row['source_key'];
        $roles = [];
        $operates = $this->confirmare->allows($actor, ConfirmareScopePolicy::SUPERVISE, $source)
            || (in_array($actor->employeeUuid, [$row['assigned_employee_uuid'] ?? null, $row['decided_by_employee_uuid'] ?? null], true) && $this->confirmare->allows($actor, ConfirmareScopePolicy::OPERATE, $source));
        if ($operates) {
            $roles['reactivate'] = 'operator';
            if ($this->finance->allows($actor, FinanceScopePolicy::APPROVE, $source)) {
                $roles['refund'] = 'operator';
            }
        }
        if ($this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, $source)) {
            $roles['awb'] = 'manager';
        } elseif ($this->invoices->allows($actor, InvoiceScopePolicy::ISSUE, $source) && $this->documents->allows($actor, DocumentScopePolicy::OPERATE, $source)
            && $this->scalar('SELECT employee_uuid FROM order_shop_assignments WHERE active_order_uuid = ?', [$row['order_uuid']]) === $actor->employeeUuid) {
            $roles['awb'] = 'documents';
        }
        if ($this->finance->allows($actor, FinanceScopePolicy::RECORD, $source)) {
            $roles['financeRecord'] = 'finance';
            $roles['finance'] = 'finance';
        }
        if ($this->finance->allows($actor, FinanceScopePolicy::APPROVE, $source)) {
            $roles['financeApprove'] = 'finance';
            $roles['finance'] = 'finance';
        }
        return $roles;
    }

    /** @param array<string, mixed> $row @param array<string, string> $roles @return array<string, bool> */
    private function allowed(EmployeeIdentity $actor, array $row, array $roles): array
    {
        return [
            'reactivate' => isset($roles['reactivate']),
            'awb' => isset($roles['awb']) && in_array((string) $row['production_stage_id'], self::AWB_STAGES, true),
            // API 2.83.0: the AWB correction and the delivery address correction (the shop's own switches still decide).
            'awbCorrect' => isset($roles['awb']) && in_array((string) $row['production_stage_id'], self::AWB_STAGES, true),
            'addressEdit' => isset($roles['awb']) && in_array((string) $row['production_stage_id'], self::AWB_STAGES, true),
            'cardRefund' => isset($roles['refund']),
            'cardRefundRecord' => isset($roles['financeRecord']),
            'cardRefundVerify' => isset($roles['financeApprove']),
        ];
    }

    /** @param array<string, mixed> $row */
    private function sees(EmployeeIdentity $actor, array $row): bool
    {
        $source = (string) $row['source_key'];
        return $this->confirmare->sees($actor, $source) || $this->financeViewer($actor, $row)
            || $this->invoices->allows($actor, InvoiceScopePolicy::ISSUE, $source) || $this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, $source);
    }

    private function hasFinanceScope(EmployeeIdentity $actor, string $source): bool
    {
        foreach (FinanceScopePolicy::CAPABILITIES as $capability) {
            if ($this->finance->allows($actor, $capability, $source)) {
                return true;
            }
        }
        return false;
    }

    /** API 2.50.0: a finance scope reaches the order's operations only through a finance task of it (FinanceTaskAccess). */
    private function financeViewer(EmployeeIdentity $actor, array $row): bool
    {
        return FinanceTaskAccess::reaches($this->pdo, $this->finance, $actor, (string) $row['order_uuid'], (string) $row['source_key']);
    }

    // ---- Relay ------------------------------------------------------------------------------------------------------------

    /**
     * @param array{status: int, body: array<string, mixed>} $answer
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function conclude(string $uuid, array $answer, string $kind, array $row, string $key, string $requestId): array
    {
        $body = $answer['body'];
        if (($answer['status'] === 200 || $answer['status'] === 202) && ($body['action'] ?? null) === $kind && in_array($body['result'] ?? null, self::RESULTS, true)) {
            $state = $answer['status'] === 200 ? 'applied' : 'unknown';
            $this->complete($uuid, $state, $state === 'applied' ? 'applied' : null, (string) $body['result'], $kind, $row, $key, is_array($body['state'] ?? null) ? self::shopState($body['state']) : null);
            if ($kind === 'order_reactivate' && $state === 'applied') {
                // The shop put the order back on hold: an approved Confirmare is decided again with the customer.
                $this->service->reopenAfterReactivation((string) $row['global_order_id'], (string) $this->scalar('SELECT employee_uuid FROM order_source_operations WHERE operation_uuid = ?', [$uuid]), self::requestId($requestId), $key . '-reopen');
                $this->settleReactivations(5);
            }
            return ['state' => $state, 'idempotencyKey' => $key, 'result' => (string) $body['result'], 'shop' => is_array($body['state'] ?? null) ? self::shopState($body['state']) : null];
        }
        if ($answer['status'] >= 400 && $answer['status'] < 500) {
            $code = $body['error']['code'] ?? null;
            if (is_string($code) && in_array($code, self::CODES, true)) {
                $this->complete($uuid, 'rejected', $code, null, $kind, $row, $key);
                throw new ApiException(409, 'CONFIRMARE_OPERATION_REFUSED', 'The shop refused the operation; nothing was done.', ['reason' => $code,
                    'blockers' => array_values(array_filter((array) ($body['blockers'] ?? []), static fn (mixed $b): bool => is_string($b) && in_array($b, self::CODES, true))),
                    // API 2.83.0: FAN Courier's own refusal text (already stripped of telephone numbers and e-mails by the shop)
                    // and the address check codes, so the employee sees the real reason.
                    'message' => self::safeMessage($body['message'] ?? null),
                    'issues' => self::addressCodes($body['issues'] ?? null),
                    'warnings' => self::addressCodes($body['warnings'] ?? null),
                    'shop' => is_array($body['state'] ?? null) ? self::shopState($body['state']) : null]);
            }
        }
        $this->complete($uuid, 'unknown', null, null, $kind, $row, $key);
        return ['state' => 'unknown', 'idempotencyKey' => $key];
    }

    /** @param array<string, mixed> $found @param array<string, mixed> $row @return array<string, mixed> */
    private function settled(array $found, array $row, SourceAccessTarget $target, EmployeeIdentity $actor, string $role, string $requestId): array
    {
        $key = (string) $found['idempotency_key'];
        if ($found['state'] === 'applied') {
            return ['state' => 'applied', 'idempotencyKey' => $key, 'result' => $found['result']];
        }
        if ($found['state'] === 'rejected') {
            throw new ApiException(409, 'CONFIRMARE_OPERATION_REFUSED', 'The shop refused the operation; nothing was done.', ['reason' => (string) $found['outcome'], 'blockers' => []]);
        }
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_OPERATION_STATUS, $this->payload($row, (string) $found['employee_uuid'], $role, $requestId) + ['idempotencyKey' => $key]);
        } catch (SourceAccessException) {
            return ['state' => (string) $found['state'], 'idempotencyKey' => $key, 'reachable' => false];
        }
        $state = $answer['body']['state'] ?? null;
        if ($answer['status'] !== 200 || !in_array($state, ['absent', 'applied', 'rejected', 'unknown'], true)) {
            return ['state' => (string) $found['state'], 'idempotencyKey' => $key, 'reachable' => false];
        }
        if ($state === 'absent') {
            $age = $this->clock->now()->getTimestamp() - (new \DateTimeImmutable((string) $found['requested_at'], new DateTimeZone('UTC')))->getTimestamp();
            if ($age < 240) {
                return ['state' => (string) $found['state'], 'idempotencyKey' => $key, 'reachable' => true];
            }
            $this->complete((string) $found['operation_uuid'], 'rejected', 'not_received', null, (string) $found['kind'], $row, $key);
            throw new ApiException(409, 'CONFIRMARE_OPERATION_REFUSED', 'The shop never received the operation; nothing was done.', ['reason' => 'not_received', 'blockers' => []]);
        }
        if (!is_int($answer['body']['status'] ?? null) || !is_array($answer['body']['result'] ?? null)) {
            return ['state' => (string) $found['state'], 'idempotencyKey' => $key, 'reachable' => true, 'shop' => $state];
        }
        if ($state === 'unknown' && (string) $found['kind'] === 'awb_cancel') {
            // API 2.83.0: a cancellation FAN Courier did not confirm is settled by the shop (its cancellation guard and its
            // AWB record), never by sending it again.
            $settled = $this->settledCancel($found, $row, $target, $role, $key, $requestId);
            if ($settled !== null) {
                return $settled;
            }
        }
        if ($state === 'unknown' && (string) $found['kind'] === 'awb_create') {
            // API 2.77.1 (TH 65121): the shop keeps the 202 it answered while FAN Courier's outcome was unknown, and replays it
            // for ever. Once the shop's own AWB journal is settled (an AWB found, or the attempt closed without one after the
            // FAN Courier check), its current AWB state is the answer: never a guess, never a second AWB.
            $settled = $this->settledAwb($found, $row, $target, $role, $key, $requestId);
            if ($settled !== null) {
                return $settled;
            }
        }
        return $this->conclude((string) $found['operation_uuid'], ['status' => (int) $answer['body']['status'], 'body' => $answer['body']['result']], (string) $found['kind'], $row, $key, $requestId);
    }

    /**
     * The shop's current AWB state for an AWB operation whose stored answer is unknown: `created` settles it as applied,
     * `none` (no AWB and no attempt left open) as rejected `not_created`; still unknown or unreadable: null (ask again later).
     *
     * @param array<string, mixed> $found @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function settledAwb(array $found, array $row, SourceAccessTarget $target, string $role, string $key, string $requestId): ?array
    {
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_OPERATION, $this->payload($row, (string) $found['employee_uuid'], $role, $requestId) + ['action' => 'operations_state']);
        } catch (SourceAccessException) {
            return null;
        }
        if ($answer['status'] !== 200 || !is_array($answer['body']['awb'] ?? null)) {
            return null;
        }
        $shop = self::shopState($answer['body']);
        if ($shop['awb']['state'] === 'created' && $shop['awb']['number'] !== null) {
            $this->complete((string) $found['operation_uuid'], 'applied', 'applied', 'created', 'awb_create', $row, $key, $shop);
            return ['state' => 'applied', 'idempotencyKey' => $key, 'result' => 'created', 'shop' => $shop];
        }
        if ($shop['awb']['state'] === 'none') {
            $this->complete((string) $found['operation_uuid'], 'rejected', 'not_created', null, 'awb_create', $row, $key);
            throw new ApiException(409, 'CONFIRMARE_OPERATION_REFUSED', 'The shop settled the AWB attempt without an AWB; nothing was created.', ['reason' => 'not_created', 'blockers' => [], 'shop' => $shop]);
        }
        return null;
    }

    /**
     * The shop's current state for an AWB cancellation whose stored answer is unknown (API 2.83.0): the shop's latest
     * correction names this cancellation as `cancelled` (FAN Courier confirmed it, the AWB is closed) or `not_cancelled`
     * (settled in the shop without a cancellation); `uncertain` or unreadable: null (ask again later; never sent again).
     *
     * @param array<string, mixed> $found @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function settledCancel(array $found, array $row, SourceAccessTarget $target, string $role, string $key, string $requestId): ?array
    {
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_OPERATION, $this->payload($row, (string) $found['employee_uuid'], $role, $requestId) + ['action' => 'operations_state']);
        } catch (SourceAccessException) {
            return null;
        }
        if ($answer['status'] !== 200 || !is_array($answer['body']['awb'] ?? null)) {
            return null;
        }
        $shop = self::shopState($answer['body']);
        $correction = $shop['awb']['correction'];
        if ($correction['state'] === 'cancelled') {
            $this->complete((string) $found['operation_uuid'], 'applied', 'applied', 'cancelled', 'awb_cancel', $row, $key, $shop);
            return ['state' => 'applied', 'idempotencyKey' => $key, 'result' => 'cancelled', 'shop' => $shop];
        }
        if ($correction['state'] === 'not_cancelled') {
            $this->complete((string) $found['operation_uuid'], 'rejected', 'not_cancelled', null, 'awb_cancel', $row, $key);
            throw new ApiException(409, 'CONFIRMARE_OPERATION_REFUSED', 'The shop settled the cancellation: the AWB was not cancelled and stays active.', ['reason' => 'not_cancelled', 'blockers' => [], 'shop' => $shop]);
        }
        return null;
    }

    /**
     * API 2.83.0: the request facts of an AWB operation (columns of migration 064), inside the insert transaction: the
     * parcels of an AWB, the reason of a cancellation, and on a new AWB the cancellation it follows (old AWB -> new AWB).
     *
     * @param array<string, mixed> $input
     */
    private function recordRequestFacts(string $uuid, string $kind, string $orderUuid, array $input): void
    {
        if (!in_array($kind, ['awb_create', 'awb_cancel'], true) || !$this->storeColumns()) {
            return;
        }
        $parcels = $kind === 'awb_create' && is_int($input['parcels'] ?? null) && $input['parcels'] >= 1 && $input['parcels'] <= 20 ? $input['parcels'] : null;
        $reason = $kind === 'awb_cancel' && in_array($input['reason'] ?? null, self::CANCEL_REASONS, true) ? (string) $input['reason'] : null;
        $replaces = null;
        $awb = null;
        if ($kind === 'awb_create') {
            $replaces = $this->scalar("SELECT c.operation_uuid FROM order_source_operations c WHERE c.order_uuid = ? AND c.kind = 'awb_cancel' AND c.state = 'applied'
                AND c.requested_at > COALESCE((SELECT MAX(a.requested_at) FROM order_source_operations a WHERE a.order_uuid = c.order_uuid AND a.kind = 'awb_create' AND a.state = 'applied'), '1970-01-01')
                ORDER BY c.requested_at DESC LIMIT 1", [$orderUuid]);
        } else {
            // The AWB this cancellation is about: the latest AWB made through Staff (the shop names it again on success).
            $awb = $this->scalar("SELECT awb_number FROM order_source_operations WHERE order_uuid = ? AND kind = 'awb_create' AND state = 'applied' AND awb_number IS NOT NULL ORDER BY requested_at DESC LIMIT 1", [$orderUuid]);
        }
        $this->pdo->prepare('UPDATE order_source_operations SET parcels = ?, reason_code = ?, replaces_operation_uuid = ?, awb_number = ? WHERE operation_uuid = ?')
            ->execute([$parcels, $reason, is_string($replaces) ? $replaces : null, is_string($awb) ? $awb : null, $uuid]);
    }

    public function storeColumns(): bool
    {
        if ($this->storeColumns === null) {
            try {
                $statement = $this->pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_source_operations' AND column_name IN ('awb_number', 'parcels', 'reason_code', 'replaces_operation_uuid')");
                $this->storeColumns = $statement !== false && (int) $statement->fetchColumn() === 4;
            } catch (PDOException) {
                $this->storeColumns = false;
            }
        }
        return $this->storeColumns;
    }

    /** @param array<string, mixed> $row @param array<string, mixed>|null $shop the shop's state after an applied operation */
    private function complete(string $uuid, string $state, ?string $outcome, ?string $result, string $kind, array $row, string $key, ?array $shop = null): void
    {
        $done = in_array($state, ['applied', 'rejected'], true);
        $now = $this->now();
        $this->pdo->prepare("UPDATE order_source_operations SET state = ?, outcome = ?, result = ?, completed_at = ? WHERE operation_uuid = ? AND state IN ('pending', 'unknown')")
            ->execute([$state, $outcome === null ? ($done ? 'applied' : null) : substr(preg_replace('/[^a-z_]/', '', $outcome) ?: 'unknown', 0, 40), $result, $done ? $now : null, $uuid]);
        if ($state === 'applied' && $shop !== null && in_array($kind, ['awb_create', 'awb_cancel'], true) && $this->storeColumns()) {
            // API 2.83.0: the AWB number the shop names (the new AWB, or the AWB it cancelled), for the history and the counters.
            $number = $kind === 'awb_create' ? $shop['awb']['number'] : $shop['awb']['correction']['awb'];
            if (is_string($number)) {
                $this->pdo->prepare('UPDATE order_source_operations SET awb_number = ? WHERE operation_uuid = ?')->execute([$number, $uuid]);
            }
        }
        if ($kind === 'order_reactivate') {
            $this->pdo->prepare("UPDATE confirmare_reactivations SET state = ?, shop_outcome = ?, completed_at = ? WHERE idempotency_key = ? AND order_uuid = ? AND state IN ('pending', 'unknown')")
                ->execute([$state, $result ?? $outcome, $done ? $now : null, $key, $row['order_uuid']]);
        }
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $input */
    private function insertReactivation(array $row, EmployeeIdentity $actor, array $input, string $key, string $requestId): void
    {
        $sequence = (int) $this->scalar('SELECT COALESCE(MAX(sequence_number), 0) + 1 FROM confirmare_reactivations WHERE order_uuid = ?', [$row['order_uuid']]);
        $previousCycle = $this->scalar('SELECT cycle_uuid FROM confirmare_proforma_cycles WHERE order_uuid = ? ORDER BY cycle_number DESC LIMIT 1', [$row['order_uuid']]);
        $this->pdo->prepare('INSERT INTO confirmare_reactivations (reactivation_uuid, order_uuid, global_order_id, source_key, sequence_number, kind, previous_cycle_uuid, payment_entry_id, reason_code,
                employee_uuid, confirmation_version_before, state, idempotency_key, request_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            Uuid::v4(), $row['order_uuid'], $row['global_order_id'], $row['source_key'], $sequence, isset($input['exceptionEntryId']) ? 'paid_exception' : 'ordinary',
            is_string($previousCycle) ? $previousCycle : null, $input['exceptionEntryId'] ?? null, $input['reason'], $actor->employeeUuid, (int) $row['version'], 'pending', $key, self::requestId($requestId), $this->now(),
        ]);
    }

    /** @param array<string, string> $roles */
    private static function roleFor(string $kind, array $roles): ?string
    {
        return match ($kind) {
            'order_reactivate' => $roles['reactivate'] ?? null,
            'card_refund_full' => $roles['refund'] ?? null,
            'card_refund_record' => $roles['financeRecord'] ?? null,
            'card_refund_verify' => $roles['financeApprove'] ?? null,
            default => $roles['awb'] ?? null,
        };
    }

    /**
     * API 2.74.1: the operations of the order still waiting for the shop's answer (pending or unknown). Each one blocks the
     * next operation of its kind, so Staff shows it after a reload and offers the check with the shop. The key is given
     * only to the employee who sent it or to someone holding the same role on the order (who may settle it).
     *
     * @param array<string, mixed> $row @param array<string, string> $roles
     * @return list<array<string, mixed>>
     */
    private function openOperations(EmployeeIdentity $actor, array $row, array $roles): array
    {
        if (!$this->installed()) {
            return [];
        }
        $statement = $this->pdo->prepare("SELECT kind, state, idempotency_key, employee_uuid, requested_at FROM order_source_operations
            WHERE order_uuid = ? AND state IN ('pending', 'unknown') ORDER BY requested_at LIMIT 10");
        $statement->execute([(string) $row['order_uuid']]);
        $open = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $op) {
            $kind = (string) $op['kind'];
            if (!isset(self::FIELDS[$kind])) {
                continue;
            }
            $mine = $op['employee_uuid'] === $actor->employeeUuid;
            $open[] = ['kind' => $kind, 'state' => (string) $op['state'], 'at' => self::iso((string) $op['requested_at']), 'mine' => $mine,
                'idempotencyKey' => $mine || self::roleFor($kind, $roles) !== null ? (string) $op['idempotency_key'] : null];
        }
        return $open;
    }

    /** @return array<string, mixed>|null */
    private function operationOfOrder(string $orderUuid, string $key): ?array
    {
        try {
            $statement = $this->pdo->prepare('SELECT * FROM order_source_operations WHERE order_uuid = ? AND idempotency_key = ?');
            $statement->execute([$orderUuid, $key]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            return count($rows) === 1 ? $rows[0] : null; // keys are unique per employee only: never guess between two
        } catch (PDOException) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    private function reactivations(string $orderUuid): array
    {
        if (!$this->installed()) {
            return [];
        }
        $statement = $this->pdo->prepare('SELECT r.sequence_number, r.kind, r.reason_code, r.state, r.created_at, r.completed_at, e.display_name FROM confirmare_reactivations r
            INNER JOIN employees e ON e.employee_uuid = r.employee_uuid WHERE r.order_uuid = ? ORDER BY r.sequence_number');
        $statement->execute([$orderUuid]);
        return array_map(static fn (array $r): array => ['sequence' => (int) $r['sequence_number'], 'kind' => (string) $r['kind'], 'reason' => (string) $r['reason_code'], 'state' => (string) $r['state'],
            'by' => (string) $r['display_name'], 'at' => self::iso((string) $r['created_at']), 'completedAt' => $r['completed_at'] === null ? null : self::iso((string) $r['completed_at'])], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The shop's operations state as Staff may show it: shapes and codes checked, free text dropped.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function shopState(array $state): array
    {
        $money = static fn (mixed $v): ?string => is_string($v) && preg_match('/^(0|[1-9][0-9]{0,8})\.[0-9]{2}$/D', $v) === 1 ? $v : null;
        $instant = static fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $v) === 1 ? $v : null;
        $awb = is_array($state['awb'] ?? null) ? $state['awb'] : [];
        $refund = is_array($state['cardRefund'] ?? null) ? $state['cardRefund'] : [];
        $codeCheck = is_array($awb['codCheck'] ?? null) ? $awb['codCheck'] : null;
        return [
            'features' => array_map(static fn (mixed $v): string => in_array($v, ['off', 'enforce', 'manual'], true) ? $v : 'off', is_array($state['features'] ?? null) ? array_intersect_key($state['features'], ['reactivation' => 1, 'awb' => 1, 'cardRefunds' => 1, 'awbCorrection' => 1, 'addressEdit' => 1]) : []),
            'orderStatus' => is_string($state['order']['status'] ?? null) && preg_match('/^[a-z-]{2,30}$/D', $state['order']['status']) === 1 ? $state['order']['status'] : null,
            'awb' => [
                'state' => in_array($awb['state'] ?? null, ['none', 'created', 'unknown'], true) ? $awb['state'] : 'none',
                'number' => is_string($awb['number'] ?? null) && preg_match('/^[0-9]{6,20}$/D', $awb['number']) === 1 ? $awb['number'] : null,
                'createdAt' => $instant($awb['createdAt'] ?? null),
                'delivered' => ($awb['delivered'] ?? false) === true,
                'cod' => $money($awb['cod'] ?? null),
                'codCheck' => $codeCheck === null ? null : ['state' => in_array($codeCheck['state'] ?? null, ['none', 'match', 'mismatch', 'delivered', 'unknown'], true) ? $codeCheck['state'] : 'unknown',
                    'awbCod' => $money($codeCheck['awbCod'] ?? null), 'expected' => $money($codeCheck['expected'] ?? null), 'corrected' => ($codeCheck['corrected'] ?? false) === true],
                'blockers' => array_values(array_filter((array) ($awb['blockers'] ?? []), static fn (mixed $b): bool => is_string($b) && in_array($b, self::CODES, true))),
                'address' => array_values(array_map(static fn (mixed $a): array => ['kind' => is_array($a) && ($a['kind'] ?? '') === 'done' ? 'done' : 'task', 'at' => is_array($a) ? $instant($a['at'] ?? null) : null],
                    array_slice((array) ($awb['address'] ?? []), -10))),
                'pickup' => 'not_tracked',
                'statusId' => is_string($awb['statusId'] ?? null) && preg_match('/^[A-Za-z0-9]{1,10}$/D', $awb['statusId']) === 1 ? $awb['statusId'] : null,
                'status' => is_string($awb['status'] ?? null) ? mb_substr(strip_tags($awb['status']), 0, 120) : null,
                'correction' => self::correction(is_array($awb['correction'] ?? null) ? $awb['correction'] : [], $instant),
            ],
            // API 2.83.0 (YD SOFT 2.36.0): the delivery block, for the shop employee's address panel (never stored here).
            'delivery' => self::delivery(is_array($state['delivery'] ?? null) ? $state['delivery'] : null, $instant),
            'cardRefund' => [
                'available' => ($refund['available'] ?? false) === true,
                'transactions' => is_int($refund['transactions'] ?? null) ? max(0, $refund['transactions']) : 0,
                'requests' => array_values(array_map(static fn (mixed $r): array => [
                    'state' => is_array($r) && in_array($r['state'] ?? null, ['manual_required', 'completed'], true) ? $r['state'] : 'unknown',
                    'at' => is_array($r) ? $instant($r['at'] ?? null) : null, 'amount' => is_array($r) ? $money($r['amount'] ?? null) : null,
                    'transactions' => is_array($r) && is_int($r['transactions'] ?? null) ? $r['transactions'] : 0,
                    // YD SOFT 2.20.0: the card refunds finance verified for it (one per transaction; fewer = partly refunded).
                    'verified' => is_array($r) && is_int($r['verified'] ?? null) ? max(0, $r['verified']) : 0,
                    'completedAt' => is_array($r) ? $instant($r['completedAt'] ?? null) : null, 'mailed' => is_array($r) && ($r['mailed'] ?? false) === true,
                ], array_slice((array) ($refund['requests'] ?? []), -10))),
            ],
        ];
    }

    /** @param array<string, mixed> $c @return array{state: string, awb: string|null, at: string|null, reason: string|null} */
    private static function correction(array $c, callable $instant): array
    {
        return [
            'state' => in_array($c['state'] ?? null, ['none', 'cancelled', 'uncertain', 'not_cancelled'], true) ? $c['state'] : 'none',
            'awb' => is_string($c['awb'] ?? null) && preg_match('/^[0-9]{6,20}$/D', $c['awb']) === 1 ? $c['awb'] : null,
            'at' => $instant($c['at'] ?? null),
            'reason' => in_array($c['reason'] ?? null, self::CANCEL_REASONS, true) ? $c['reason'] : null,
        ];
    }

    /** @param array<string, mixed>|null $d @return array<string, mixed>|null */
    private static function delivery(?array $d, callable $instant): ?array
    {
        if ($d === null || !is_array($d['address'] ?? null)) {
            return null;
        }
        $address = ['target' => ($d['address']['target'] ?? '') === 'billing' ? 'billing' : 'shipping'];
        foreach (array_merge(self::ADDRESS_FIELDS, ['company']) as $field) {
            $value = $d['address'][$field] ?? '';
            $address[$field] = is_string($value) ? mb_substr(strip_tags($value), 0, 200) : '';
        }
        $check = is_array($d['check'] ?? null) ? $d['check'] : [];
        $fan = is_array($check['fan'] ?? null) ? ['county' => mb_substr((string) ($check['fan']['county'] ?? ''), 0, 60), 'locality' => mb_substr(strip_tags((string) ($check['fan']['locality'] ?? '')), 0, 120)] : null;
        return [
            'address' => $address,
            'check' => ['state' => in_array($check['state'] ?? null, ['ok', 'warning', 'attention'], true) ? $check['state'] : 'attention',
                'issues' => self::addressCodes($check['issues'] ?? null), 'warnings' => self::addressCodes($check['warnings'] ?? null), 'fan' => $fan],
            'editable' => ($d['editable'] ?? false) === true,
            'lastEdit' => is_array($d['lastEdit'] ?? null) ? ['at' => $instant($d['lastEdit']['at'] ?? null)] : null,
        ];
    }

    /** @return list<string> */
    private static function addressCodes(mixed $codes): array
    {
        return is_array($codes) ? array_values(array_unique(array_filter($codes, static fn (mixed $c): bool => is_string($c) && in_array($c, self::ADDRESS_CODES, true)))) : [];
    }

    /** FAN Courier's refusal text as the shop relayed it, checked again: no long digit run, no e-mail, no markup, 200 characters. */
    public static function safeMessage(mixed $message): ?string
    {
        if (!is_string($message) || trim($message) === '') {
            return null;
        }
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[e-mail]', strip_tags($message)) ?? '';
        $message = preg_replace('/\+?\d[\d .\-]{6,}\d/', '[număr]', $message) ?? '';
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
        return $message === '' ? null : mb_substr($message, 0, 200);
    }

    /** API 2.83.0: the employee's delivery address, checked before anything is recorded (the shop checks it again). */
    private static function validateAddress(mixed $address): void
    {
        if (!is_array($address) || $address === [] || array_is_list($address) || array_diff(array_keys($address), self::ADDRESS_FIELDS) !== []) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'address takes only ' . implode(', ', self::ADDRESS_FIELDS) . '.', ['field' => 'address']);
        }
        foreach ($address as $field => $value) {
            if (!is_string($value) || mb_strlen($value) > 200 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F<>]/', $value) === 1) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'address.' . $field . ' is not valid.', ['field' => 'address.' . $field]);
            }
        }
    }

    // ---- Storage -------------------------------------------------------------------------------------------------------

    public function installed(): bool
    {
        if ($this->installed === null) {
            try {
                $statement = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('order_source_operations', 'confirmare_reactivations')");
                $this->installed = $statement !== false && (int) $statement->fetchColumn() === 2;
            } catch (PDOException) {
                $this->installed = false;
            }
        }
        return $this->installed;
    }

    private function requireInstalled(): void
    {
        if (!$this->installed()) {
            throw new ApiException(409, 'CONFIRMARE_CYCLE_SCHEMA_MISSING', 'Migration 043 is not applied yet.');
        }
    }

    private function operationOpen(string $orderUuid, string $kind): bool
    {
        return $this->scalar("SELECT 1 FROM order_source_operations WHERE order_uuid = ? AND kind = ? AND state IN ('pending', 'unknown') LIMIT 1", [$orderUuid, $kind]) !== false;
    }

    /** @return array<string, mixed>|null */
    private function operation(string $employeeUuid, string $key): ?array
    {
        try {
            $statement = $this->pdo->prepare('SELECT * FROM order_source_operations WHERE employee_uuid = ? AND idempotency_key = ?');
            $statement->execute([$employeeUuid, $key]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (PDOException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function row(string $globalId): ?array
    {
        $statement = $this->pdo->prepare('SELECT o.order_uuid, o.global_order_id, o.source_key, o.source_order_id, o.order_number, o.workflow_cohort, o.production_stage_id, o.cancellation_state, o.operational_status,
                c.status, c.version, c.assigned_employee_uuid, c.decided_by_employee_uuid
            FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE o.global_order_id = ?');
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function lockRow(string $orderUuid): ?array
    {
        $lock = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
        $lock->execute([$orderUuid]);
        if ($lock->fetchColumn() === false) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT o.order_uuid, o.global_order_id, o.source_key, o.workflow_cohort, o.production_stage_id, o.cancellation_state, o.operational_status, c.status, c.version
            FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE o.order_uuid = ?');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function payload(array $row, string $employeeUuid, string $role, string $requestId): array
    {
        return ['orderId' => (string) $row['source_order_id'], 'orderNumber' => (string) $row['order_number'], 'actor' => ['employeeId' => $employeeUuid, 'role' => $role], 'requestId' => self::requestId($requestId)];
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    private function globalId(string $globalOrderId): string
    {
        try {
            return GlobalOrderId::fromString($globalOrderId)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function requestId(string $requestId): string
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $requestId) === 1 ? $requestId : 'none';
    }

    private static function iso(string $sql): string
    {
        return (new \DateTimeImmutable($sql, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
