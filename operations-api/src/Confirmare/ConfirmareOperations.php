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
    public const KINDS = ['order_reactivate', 'awb_create', 'awb_address_task', 'awb_address_done', 'card_refund_full', 'card_refund_record', 'card_refund_verify'];
    /** Production stages from which the AWB may be made (stage 12 Control calitate, 13 Împachetare, 14 Livrare). */
    public const AWB_STAGES = ['quality-control', 'packing', 'delivery'];
    public const REACTIVATION_REASONS = ['customer_returned', 'customer_paid_late'];
    private const FIELDS = [
        'order_reactivate' => ['reason', 'exceptionEntryId'],
        'awb_create' => ['parcels', 'weightKg', 'expectedCod'],
        'awb_address_task' => ['reasonNote'],
        'awb_address_done' => ['reasonNote'],
        'card_refund_full' => [],
        'card_refund_record' => ['amount', 'reference', 'valueDate'],
        'card_refund_verify' => ['entryId', 'decision'],
    ];
    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D';
    private const NOT_SENT = ['SOURCE_ACCESS_NOT_CONFIGURED', 'SOURCE_ADDRESS_REFUSED', 'SOURCE_ACCESS_DENIED'];
    /** Every refusal code the shop may name (anything else is SOURCE_ERROR). */
    public const CODES = ['invalid_request', 'order_busy', 'internal_error', 'reactivation_disabled', 'awb_disabled', 'card_refund_disabled', 'order_not_cancelled', 'order_refunded',
        'payment_not_found', 'order_closed', 'invoice_missing', 'finance_unavailable', 'payment_verifying', 'finance_attention', 'address_incomplete', 'phone_missing',
        'shipping_mismatch', 'awb_exists', 'awb_unavailable', 'cod_changed', 'awb_refused', 'awb_missing', 'label_unavailable', 'address_task_missing', 'address_task_open',
        'not_card_payment', 'card_payment_unverified', 'nothing_to_refund', 'refund_request_missing', 'refund_pending', 'finance_open', 'amount_exceeds_received',
        'duplicate_reference', 'entry_not_found', 'entry_not_pending', 'same_employee', 'refund_record_failed', 'idempotency_conflict'];
    public const RESULTS = ['reactivated', 'created', 'unknown', 'panel_required', 'panel_declared', 'manual_required', 'already_requested', 'refund_recorded', 'refund_verified', 'refund_rejected'];

    private ?bool $installed = null;

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
        $pdf = $answer['status'] === 200 && is_string($answer['body']['pdf'] ?? null) ? base64_decode($answer['body']['pdf'], true) : false;
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF')) {
            throw new ApiException(409, 'CONFIRMARE_AWB_LABEL_UNAVAILABLE', 'FAN Courier gave no label for this AWB now.');
        }
        return $pdf;
    }

    /**
     * The orders of the actor's shops waiting for their AWB (stage 12 or later, approved, not closed), for the shop
     * employee the order was routed to and the invoice managers. The shop's AWB state is read per order on its screen.
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
        $statement = $this->pdo->prepare("SELECT o.global_order_id, o.order_number, o.source_key, o.production_stage_id, a.employee_uuid AS assignee,
                (SELECT op.state FROM order_source_operations op WHERE op.order_uuid = o.order_uuid AND op.kind = 'awb_create' ORDER BY op.requested_at DESC LIMIT 1) AS last_awb
            FROM operational_orders o
            INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid AND c.status = 'approved'
            LEFT JOIN order_shop_assignments a ON a.active_order_uuid = o.order_uuid
            WHERE o.source_key IN ({$marks}) AND o.production_stage_id IN ({$stages}) AND o.production_completed_at IS NULL AND o.operational_status <> 'unavailable' AND o.cancellation_state IS NULL
            ORDER BY o.production_changed_at, o.order_uuid LIMIT 300");
        $statement->execute([...$sources, ...self::AWB_STAGES]);
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $manager = $this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, (string) $row['source_key']);
            if (!$manager && $row['assignee'] !== $actor->employeeUuid) {
                continue;
            }
            if ($row['last_awb'] === 'applied') {
                continue;
            }
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
        $role = match ($kind) {
            'order_reactivate' => $roles['reactivate'] ?? null,
            'card_refund_full' => $roles['refund'] ?? null,
            'card_refund_record' => $roles['financeRecord'] ?? null,
            'card_refund_verify' => $roles['financeApprove'] ?? null,
            default => $roles['awb'] ?? null,
        };
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
        if (in_array($kind, ['awb_create', 'awb_address_task', 'awb_address_done'], true)) {
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
            if ($kind === 'awb_create' && $this->operationOpen((string) $row['order_uuid'], 'awb_create')) {
                $codes[] = 'operation_open';
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
                } elseif (!$this->financeViewer($actor, (string) $row['source_key'])) {
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
            'cardRefund' => isset($roles['refund']),
            'cardRefundRecord' => isset($roles['financeRecord']),
            'cardRefundVerify' => isset($roles['financeApprove']),
        ];
    }

    /** @param array<string, mixed> $row */
    private function sees(EmployeeIdentity $actor, array $row): bool
    {
        $source = (string) $row['source_key'];
        return $this->confirmare->sees($actor, $source) || $this->financeViewer($actor, $source)
            || $this->invoices->allows($actor, InvoiceScopePolicy::ISSUE, $source) || $this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, $source);
    }

    private function financeViewer(EmployeeIdentity $actor, string $source): bool
    {
        foreach (FinanceScopePolicy::CAPABILITIES as $capability) {
            if ($this->finance->allows($actor, $capability, $source)) {
                return true;
            }
        }
        return false;
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
            $this->complete($uuid, $state, $state === 'applied' ? 'applied' : null, (string) $body['result'], $kind, $row, $key);
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
        return $this->conclude((string) $found['operation_uuid'], ['status' => (int) $answer['body']['status'], 'body' => $answer['body']['result']], (string) $found['kind'], $row, $key, $requestId);
    }

    /** @param array<string, mixed> $row */
    private function complete(string $uuid, string $state, ?string $outcome, ?string $result, string $kind, array $row, string $key): void
    {
        $done = in_array($state, ['applied', 'rejected'], true);
        $now = $this->now();
        $this->pdo->prepare("UPDATE order_source_operations SET state = ?, outcome = ?, result = ?, completed_at = ? WHERE operation_uuid = ? AND state IN ('pending', 'unknown')")
            ->execute([$state, $outcome === null ? ($done ? 'applied' : null) : substr(preg_replace('/[^a-z_]/', '', $outcome) ?: 'unknown', 0, 40), $result, $done ? $now : null, $uuid]);
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
            'features' => array_map(static fn (mixed $v): string => in_array($v, ['off', 'enforce', 'manual'], true) ? $v : 'off', is_array($state['features'] ?? null) ? array_intersect_key($state['features'], ['reactivation' => 1, 'awb' => 1, 'cardRefunds' => 1]) : []),
            'orderStatus' => is_string($state['order']['status'] ?? null) && preg_match('/^[a-z-]{2,30}$/D', $state['order']['status']) === 1 ? $state['order']['status'] : null,
            'awb' => [
                'state' => in_array($awb['state'] ?? null, ['none', 'created', 'unknown'], true) ? $awb['state'] : 'none',
                'number' => is_string($awb['number'] ?? null) && preg_match('/^[0-9]{6,20}$/D', $awb['number']) === 1 ? $awb['number'] : null,
                'createdAt' => $instant($awb['createdAt'] ?? null),
                'delivered' => ($awb['delivered'] ?? false) === true,
                'cod' => $money($awb['cod'] ?? null),
                'codCheck' => $codeCheck === null ? null : ['state' => in_array($codeCheck['state'] ?? null, ['none', 'match', 'mismatch', 'delivered', 'unknown'], true) ? $codeCheck['state'] : 'unknown',
                    'awbCod' => $money($codeCheck['awbCod'] ?? null), 'expected' => $money($codeCheck['expected'] ?? null)],
                'blockers' => array_values(array_filter((array) ($awb['blockers'] ?? []), static fn (mixed $b): bool => is_string($b) && in_array($b, self::CODES, true))),
                'address' => array_values(array_map(static fn (mixed $a): array => ['kind' => is_array($a) && ($a['kind'] ?? '') === 'done' ? 'done' : 'task', 'at' => is_array($a) ? $instant($a['at'] ?? null) : null],
                    array_slice((array) ($awb['address'] ?? []), -10))),
                'pickup' => 'not_tracked',
            ],
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
