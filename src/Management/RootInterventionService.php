<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Confirmare\ConfirmareFinance;
use Arasya\Operations\Confirmare\ConfirmareInvoice;
use Arasya\Operations\Confirmare\ConfirmareOperations;
use Arasya\Operations\Confirmare\ConfirmareSourceEdits;
use Arasya\Operations\Confirmare\InvoiceEfactura;
use Arasya\Operations\Confirmare\ConfirmareService;
use Arasya\Operations\Confirmare\ConfirmareSourceAccess;
use Arasya\Operations\Confirmare\ConfirmationClearance;
use Arasya\Operations\Confirmare\FinanceVerificationRequests;
use Arasya\Operations\Confirmare\RootInterventionSources;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Support\Clock;
use DateTimeZone;
use PDO;

/**
 * The root intervention desk (API 2.57.0, owner decision 2026-11-04): the principal administrator sees every Trend Home
 * and Outlet Perdele Confirmare order that waits for someone, why it waits and for whom, and unblocks it from the
 * Dashboard.
 *
 * Every action goes through the same service Staff uses (ConfirmareService, ConfirmareSourceAccess, ConfirmareInvoice,
 * ConfirmareFinance, FinanceVerificationRequests): the same locks, versions, idempotency, shop calls, reconciliation and
 * refusals. Nothing here writes an order, a confirmation, a document or a money record itself. The actor is the root
 * identity: the records name the principal administrator, never an employee, and nothing is recorded in an employee's
 * name. Each action carries a written reason, kept in the IAM audit (`intervention.<action>`) with its outcome.
 *
 * Only the instances of ConfirmareInvoice, ConfirmareFinance and FinanceVerificationRequests built for this desk
 * (Container::rootIntervention) let root act on proformas, money and finance requests; Staff routes keep root out.
 */
final readonly class RootInterventionService
{
    /** The actions of the desk and the fields each one takes besides `reason`. */
    public const ACTIONS = [
        'shop-read' => [],
        'reopen' => ['expectedVersion'],
        'assign' => ['expectedVersion', 'employeeId'],
        'approve' => ['expectedVersion'],
        'reject' => ['expectedVersion', 'reasonCode'],
        'proforma' => ['route', 'fields'],
        'invoice-reconcile' => ['idempotencyKey'],
        'advance' => ['expectedRevision', 'amount', 'reference', 'valueDate', 'overpayment'],
        'finance' => ['financeAction', 'fields'],
        'finance-reconcile' => ['idempotencyKey'],
        'request-cancel' => ['expectedVersion', 'reasonCode'],
        'request-resubmit' => ['expectedVersion'],
        // Phase 2 (owner 2026-11-04: "every blockage"): handoff to the shop employee, final invoice, e-Factura, the shop
        // operations (reactivation, AWB, card refund), controlled line edits of a pending order, and reconciliation of
        // any action whose answer was lost.
        'shop-assign' => ['employeeId', 'expectedAssigneeId'],
        'invoice' => ['invoiceAction', 'fields'],
        'efactura' => ['taskId', 'expectedVersion', 'outcome'],
        'operation' => ['kind', 'fields'],
        'operation-reconcile' => ['idempotencyKey'],
        'edit-catalog' => [],
        'edit-product' => ['sku'],
        'edit-preview' => ['expectedVersion', 'operations'],
        'edit-apply' => ['expectedVersion', 'operations', 'previewToken'],
        'edit-reconcile' => ['idempotencyKey'],
    ];
    /** Reads that need no reason (they change nothing but are still audited by the service that reads). */
    private const READS = ['shop-read', 'edit-catalog', 'edit-product', 'edit-preview'];
    private const LIST_LIMIT = 500;
    /** The facts every reason is computed from (one row per order; `?` is the "no answer for 2 minutes" instant, four times). */
    private const FACTS = "o.order_uuid, o.global_order_id, o.order_number, o.source_key, o.payment_method, o.production_stage_id, o.source_commerce_status_code, o.freshness_status,
            COALESCE(o.source_submitted_at, o.created_at) AS submitted_at, c.status, c.version, c.assigned_employee_uuid, a.display_name AS assignee, c.production_cleared, c.updated_at,
            (SELECT r.state FROM finance_verification_requests r WHERE r.order_uuid = o.order_uuid AND r.open_order_uuid IS NOT NULL LIMIT 1) AS request_state,
            (SELECT COUNT(*) FROM order_finance_actions x WHERE x.order_uuid = o.order_uuid AND x.state IN ('pending', 'unknown') AND x.requested_at < ?)
            + (SELECT COUNT(*) FROM order_invoice_actions x WHERE x.order_uuid = o.order_uuid AND x.state IN ('pending', 'unknown') AND x.requested_at < ?)
            + (SELECT COUNT(*) FROM order_confirmation_source_edits x WHERE x.order_uuid = o.order_uuid AND x.state IN ('pending', 'unknown') AND x.requested_at < ?)
            + (SELECT COUNT(*) FROM order_source_operations x WHERE x.order_uuid = o.order_uuid AND x.state IN ('pending', 'unknown') AND x.requested_at < ?) AS unanswered,
            EXISTS (SELECT 1 FROM order_shop_assignments s WHERE s.active_order_uuid = o.order_uuid) AS routed,
            EXISTS (SELECT 1 FROM invoice_efactura_tasks t WHERE t.order_uuid = o.order_uuid AND t.state IN ('open', 'sent', 'rejected') AND t.due_at < UTC_TIMESTAMP(6)) AS efactura_due,
            EXISTS (SELECT 1 FROM production_exceptions x WHERE x.order_uuid = o.order_uuid AND x.status IN ('awaiting_acknowledgment', 'awaiting_approval')) AS exception_open
        FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid LEFT JOIN employees a ON a.employee_uuid = c.assigned_employee_uuid";
    private const STALE_SECONDS = 120;

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private ConfirmareService $confirmare,
        private ConfirmareSourceAccess $sourceAccess,
        private ConfirmareInvoice $invoice,
        private ConfirmareFinance $finance,
        private FinanceVerificationRequests $requests,
        private IamAuditLogger $audit,
        private Clock $clock,
        private ConfirmareOperations $operations,
        private ConfirmareSourceEdits $edits,
        private InvoiceEfactura $efactura,
    ) {
    }

    /**
     * The orders waiting for someone, with the reasons in codes, plus the shop sources without a Confirmare operator or
     * supervisor. `view`: `stuck` (default: approved and not cleared, an open finance request needing attention, an
     * action without an answer, a pending order nobody has) or `all` (every pending or approved-not-cleared order).
     *
     * @return array<string, mixed>
     */
    public function overview(EmployeeIdentity $actor, string $view, ?string $number): array
    {
        $this->requireRoot($actor);
        if (!in_array($view, ['stuck', 'all'], true)) {
            throw new ApiException(422, 'INTERVENTION_INVALID', 'view must be stuck or all.', ['field' => 'view']);
        }
        if ($number !== null && preg_match('/^[0-9A-Za-z-]{1,40}$/D', $number) !== 1) {
            throw new ApiException(422, 'INTERVENTION_INVALID', 'The order number is not valid.', ['field' => 'number']);
        }
        $sources = RootInterventionSources::of($this->pdo);
        $staffing = [];
        foreach ($sources as $source) {
            $count = $this->pdo->prepare("SELECT s.capability, COUNT(*) FROM employee_confirmare_scopes s INNER JOIN employees e ON e.employee_uuid = s.employee_uuid
                WHERE s.source_key = ? AND e.status = 'active' GROUP BY s.capability");
            $count->execute([$source]);
            $counts = $count->fetchAll(PDO::FETCH_KEY_PAIR);
            $contact = $this->pdo->prepare('SELECT last_contact_at FROM order_sources WHERE source_key = ?');
            $contact->execute([$source]);
            $staffing[] = ['source' => $source, 'operators' => (int) ($counts['operate'] ?? 0), 'supervisors' => (int) ($counts['supervise'] ?? 0), 'lastContactAt' => $this->iso($contact->fetchColumn() ?: null)];
        }
        $placeholders = implode(', ', array_fill(0, count($sources), '?'));
        // Waiting in Confirmare, or cleared but not handed to a shop employee yet, or followed after the handoff (an
        // unanswered shop action, an overdue e-Factura, an open production exception), or cancelled by the shop's
        // cancellation hold while the customer may come back (reactivation). Delivered and finished orders never.
        $sql = 'SELECT ' . self::FACTS . " WHERE o.workflow_cohort = 'confirmare' AND o.source_key IN ({$placeholders}) AND (
                c.status = 'pending' OR (c.status = 'approved' AND c.production_cleared = 0)
                OR (c.status = 'approved' AND c.production_cleared = 1 AND o.production_stage_id = 'waiting' AND NOT EXISTS (SELECT 1 FROM order_shop_assignments s WHERE s.active_order_uuid = o.order_uuid))
                OR EXISTS (SELECT 1 FROM order_finance_actions x WHERE x.order_uuid = o.order_uuid AND x.state IN ('pending', 'unknown'))
                OR EXISTS (SELECT 1 FROM order_invoice_actions x WHERE x.order_uuid = o.order_uuid AND x.state IN ('pending', 'unknown'))
                OR EXISTS (SELECT 1 FROM order_source_operations x WHERE x.order_uuid = o.order_uuid AND x.state IN ('pending', 'unknown'))
                OR EXISTS (SELECT 1 FROM invoice_efactura_tasks t WHERE t.order_uuid = o.order_uuid AND t.state IN ('open', 'sent', 'rejected') AND t.due_at < UTC_TIMESTAMP(6))
                OR EXISTS (SELECT 1 FROM production_exceptions x WHERE x.order_uuid = o.order_uuid AND x.status IN ('awaiting_acknowledgment', 'awaiting_approval')))"
            . ($number === null ? '' : ' AND o.order_number = ?') . ' ORDER BY COALESCE(o.source_submitted_at, o.created_at) LIMIT ' . self::LIST_LIMIT;
        $stale = $this->clock->now()->modify('-' . self::STALE_SECONDS . ' seconds')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$stale, $stale, $stale, $stale, ...$sources, ...($number === null ? [] : [$number])]);
        $supervised = array_column(array_filter($staffing, static fn (array $s): bool => $s['supervisors'] > 0), 'source');
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $blockers = $this->blockers($row, in_array($row['source_key'], $supervised, true));
            if ($view === 'stuck' && $number === null && array_diff($blockers, ['waiting_call', 'payment_waiting', 'no_supervisor']) === []) {
                continue;
            }
            $items[] = [
                'globalOrderId' => (string) $row['global_order_id'],
                'orderNumber' => (string) $row['order_number'],
                'source' => (string) $row['source_key'],
                'paymentMethod' => $row['payment_method'],
                'status' => (string) $row['status'],
                'version' => (int) $row['version'],
                'assignee' => $row['assignee'] === null ? null : (string) $row['assignee'],
                'submittedAt' => $this->iso($row['submitted_at']),
                'updatedAt' => $this->iso($row['updated_at']),
                'requestState' => $row['request_state'],
                'blockers' => $blockers,
                'stage' => (string) $row['production_stage_id'],
            ];
        }
        return ['view' => $view, 'sources' => $staffing, 'items' => $items, 'limit' => self::LIST_LIMIT, 'otherFlows' => $this->otherFlows()];
    }

    /**
     * The flows the desk does not act on, so the owner sees them too: Trendyol and B2B orders not started in production,
     * and the older Trend Home / Outlet Perdele orders the shop still manages (before 1 November, or never determined).
     * They are handled where they live (Staff Trendyol workspace, the B2B application, WordPress).
     *
     * @return list<array{flow: string, open: int}>
     */
    private function otherFlows(): array
    {
        $count = function (string $sql): int {
            try {
                return (int) $this->pdo->query($sql)->fetchColumn();
            } catch (\PDOException) {
                return 0;
            }
        };
        $open = "production_stage_id = 'waiting' AND operational_status NOT IN ('completed', 'unavailable', 'cancelled')";
        return [
            ['flow' => 'trendyol', 'open' => $count("SELECT COUNT(*) FROM operational_orders WHERE source_key = 'trendyol' AND {$open}")],
            ['flow' => 'b2b', 'open' => $count("SELECT COUNT(*) FROM operational_orders WHERE source_key = 'b2b' AND {$open}")],
            ['flow' => 'shop_legacy', 'open' => $count("SELECT COUNT(*) FROM operational_orders WHERE source_key IN ('trendhome', 'outletperdele') AND (workflow_cohort IS NULL OR workflow_cohort <> 'confirmare')
                AND {$open} AND source_commerce_status_code NOT IN ('completed', 'cancelled', 'refunded', 'failed', 'trash')")],
        ];
    }

    /**
     * One order: its Confirmare state and history, what production clearance still misses, the shop's money and
     * proforma (read now, as root), its finance request, and who can be assigned. A shop that cannot be read leaves
     * that part `available: false` with the reason; the rest is still shown.
     *
     * @return array<string, mixed>
     */
    public function order(EmployeeIdentity $actor, string $globalOrderId, string $requestId): array
    {
        $this->requireRoot($actor);
        $detail = $this->confirmare->detail($actor, $globalOrderId);
        $globalId = (string) $detail['globalOrderId'];
        $row = $this->row($globalId) ?? throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order of a shop was found.');
        $part = static function (callable $read): array {
            try {
                return $read();
            } catch (ApiException $e) {
                return ['available' => false, 'reason' => $e->errorCode];
            }
        };
        return [
            'confirmare' => $detail,
            'missing' => ConfirmationClearance::missing($this->pdo, (string) $row['order_uuid']),
            'blockers' => $this->blockers($row, $this->hasSupervisor((string) $row['source_key'])),
            'finance' => $part(fn (): array => $this->finance->read($actor, $globalId, $requestId)),
            'invoice' => $part(fn (): array => $this->invoice->read($actor, $globalId, $requestId)),
            'request' => $part(fn (): array => $this->requests->forOrder($actor, $globalId)),
            'operators' => $this->operators((string) $row['source_key']),
            'interventions' => $this->history($globalId),
            'operations' => $part(fn (): array => $this->operations->read($actor, $globalId, $requestId)),
            'shop' => $this->shopAssignment((string) $row['order_uuid']) + ['candidates' => $part(fn (): array => $this->invoice->shopCandidates($actor, $globalId))['items'] ?? []],
            'unanswered' => $this->unanswered((string) $row['order_uuid']),
            'efactura' => $this->efacturaTasks((string) $row['order_uuid']),
            'production' => $this->production((string) $row['order_uuid']),
            'sync' => $this->sync((string) $row['order_uuid']),
        ];
    }

    /**
     * One desk action. `reason` (5–500 characters) is required for every change; the outcome (applied, or the refusal
     * code) is written to the IAM audit together with it.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function act(EmployeeIdentity $actor, string $globalOrderId, string $action, array $input, string $key, string $requestId): array
    {
        $this->requireRoot($actor);
        if (!isset(self::ACTIONS[$action])) {
            throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
        }
        $read = in_array($action, self::READS, true);
        $reason = $input['reason'] ?? null;
        unset($input['reason']);
        if (!$read && (!is_string($reason) || !mb_check_encoding($reason, 'UTF-8') || mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1)) {
            throw new ApiException(422, 'INTERVENTION_REASON_REQUIRED', 'Write why you intervene (5 to 500 characters).', ['field' => 'reason']);
        }
        if (array_diff(array_keys($input), self::ACTIONS[$action]) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only ' . implode(', ', ['reason', ...self::ACTIONS[$action]]) . ' are accepted.');
        }
        $globalId = (string) ($this->row($globalOrderId)['global_order_id'] ?? throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.'));
        try {
            $result = $this->run($actor, $globalId, $action, $input, $key, $requestId);
        } catch (ApiException $e) {
            if (!$read) {
                $this->record($actor, $globalId, $action, (string) $reason, ['outcome' => 'refused', 'code' => $e->errorCode], $requestId);
            }
            throw $e;
        }
        if (!$read) {
            $this->record($actor, $globalId, $action, (string) $reason, ['outcome' => (string) ($result['state'] ?? 'applied')], $requestId);
        }
        return $result;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function run(EmployeeIdentity $actor, string $globalId, string $action, array $input, string $key, string $requestId): array
    {
        $version = $input['expectedVersion'] ?? null;
        return match ($action) {
            'shop-read' => $this->sourceAccess->read($actor, $globalId, $requestId),
            'reopen' => $this->confirmare->reopen($actor, $globalId, $version, $key, $requestId),
            'assign' => $this->confirmare->reassign($actor, $globalId, $version, $input['employeeId'] ?? null, $key, $requestId),
            'approve' => $this->approve($actor, $globalId, $version, $key, $requestId),
            'reject' => $this->confirmare->reject($actor, $globalId, $version, $input['reasonCode'] ?? null, $key, $requestId),
            'proforma' => $this->proforma($actor, $globalId, $input, $key, $requestId),
            'invoice-reconcile' => $this->invoice->reconcile($actor, $globalId, $input['idempotencyKey'] ?? null, $requestId),
            'advance' => $this->advance($actor, $globalId, $input, $key, $requestId),
            'finance' => $this->finance->act($actor, $globalId, $this->string($input['financeAction'] ?? null, 'financeAction'), $this->fields($input['fields'] ?? []), $key, $requestId),
            'finance-reconcile' => $this->finance->reconcile($actor, $globalId, $input['idempotencyKey'] ?? null, $requestId),
            'request-cancel' => $this->requests->cancel($actor, $globalId, ['expectedVersion' => $version, 'reasonCode' => $input['reasonCode'] ?? null], $key, $requestId),
            'request-resubmit' => $this->requests->resubmit($actor, $globalId, ['expectedVersion' => $version], $key, $requestId),
            'shop-assign' => $this->invoice->assignShop($actor, $globalId, ['employeeId' => $input['employeeId'] ?? null, 'expectedAssigneeId' => $input['expectedAssigneeId'] ?? null], $key),
            'invoice' => $this->invoice->act($actor, $globalId, $this->string($input['invoiceAction'] ?? null, 'invoiceAction'), $this->fields($input['fields'] ?? []), $key, $requestId),
            'efactura' => $this->efacturaRecord($actor, $globalId, $input),
            'operation' => $this->operations->act($actor, $globalId, $this->string($input['kind'] ?? null, 'kind'), $this->fields($input['fields'] ?? []), $key, $requestId),
            'operation-reconcile' => $this->operations->reconcile($actor, $globalId, $input['idempotencyKey'] ?? null, $requestId),
            'edit-catalog' => $this->edits->catalog($actor, $globalId, $requestId),
            'edit-product' => $this->edits->product($actor, $globalId, $input['sku'] ?? null, $requestId),
            'edit-preview' => $this->edits->preview($actor, $globalId, $input['expectedVersion'] ?? null, $input['operations'] ?? null, $requestId),
            'edit-apply' => $this->edits->apply($actor, $globalId, $input['expectedVersion'] ?? null, $input['operations'] ?? null, $input['previewToken'] ?? null, $key, $requestId),
            'edit-reconcile' => $this->edits->reconcile($actor, $globalId, $input['idempotencyKey'] ?? null, $requestId),
        };
    }

    /** @param array<string, mixed> $input @return array<string, mixed> an e-Factura task of THIS order only */
    private function efacturaRecord(EmployeeIdentity $actor, string $globalId, array $input): array
    {
        $task = $this->string($input['taskId'] ?? null, 'taskId');
        $statement = $this->pdo->prepare('SELECT 1 FROM invoice_efactura_tasks WHERE task_uuid = ? AND global_order_id = ?');
        $statement->execute([$task, $globalId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException(404, 'EFACTURA_TASK_NOT_FOUND', 'No such e-Factura task of this order.');
        }
        return $this->efactura->record($actor, $task, ['outcome' => $input['outcome'] ?? null, 'expectedVersion' => $input['expectedVersion'] ?? null]);
    }

    /**
     * The owner approves on their own declaration: the shop order is read now with its current prices (as root), the
     * confirmed call is recorded under root on the current review unless one already is, and the order is approved with
     * every current line. Each step is the Staff step, with its own checks.
     *
     * @return array<string, mixed>
     */
    private function approve(EmployeeIdentity $actor, string $globalId, mixed $version, string $key, string $requestId): array
    {
        if (!is_int($version) || $version < 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'expectedVersion must be a positive integer.');
        }
        $current = $this->currentVersion($globalId);
        if ($current !== $version) {
            throw new ApiException(409, 'CONFIRMARE_CHANGED', 'The Confirmare state changed. Reload it before continuing.', ['currentVersion' => $current]);
        }
        $this->sourceAccess->read($actor, $globalId, $requestId);
        $review = $this->confirmare->detail($actor, $globalId)['review'] ?? null;
        if (!is_array($review) || !is_string($review['fingerprint'] ?? null) || !is_array($review['lineIds'] ?? null)) {
            throw new ApiException(409, 'CONFIRMARE_REVIEW_REQUIRED', 'The order has no current review to approve.');
        }
        $orderUuid = (string) $this->row($globalId)['order_uuid'];
        $called = $this->pdo->prepare("SELECT 1 FROM order_confirmation_contacts WHERE order_uuid = ? AND outcome = 'confirmed' AND review_fingerprint = ? LIMIT 1");
        $called->execute([$orderUuid, $review['fingerprint']]);
        if ($called->fetchColumn() === false) {
            $this->confirmare->contact($actor, $globalId, $this->currentVersion($globalId), 'confirmed', $review['fingerprint'], $key . '-call', $requestId);
        }
        return $this->confirmare->approve($actor, $globalId, $this->currentVersion($globalId), [
            'customerCall' => 'confirmed', 'priceVerified' => true, 'confirmedLineIds' => $review['lineIds'], 'reviewFingerprint' => $review['fingerprint'],
        ], $key, $requestId);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function proforma(EmployeeIdentity $actor, string $globalId, array $input, string $key, string $requestId): array
    {
        $route = $this->string($input['route'] ?? null, 'route');
        $action = ConfirmareInvoice::PROFORMA_ROUTES[$route] ?? throw new ApiException(422, 'INTERVENTION_INVALID', 'Unknown proforma action.', ['field' => 'route']);
        return $this->invoice->proforma($actor, $globalId, $action, $this->fields($input['fields'] ?? []), $key, $requestId);
    }

    /**
     * The advance the owner found on the bank statement: recorded as a bank transfer and verified by the same root
     * identity under the shop's `single` policy (two shop actions, each with its own key). An amount above what is still
     * owed needs `overpayment: true`; the excess stays a finance exception in the shop, never refunded automatically.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function advance(EmployeeIdentity $actor, string $globalId, array $input, string $key, string $requestId): array
    {
        $overpayment = $input['overpayment'] ?? false;
        if (!is_bool($overpayment)) {
            throw new ApiException(422, 'INTERVENTION_INVALID', 'overpayment must be true or false.', ['field' => 'overpayment']);
        }
        $recorded = $this->finance->act($actor, $globalId, 'submit_transfer', [
            'expectedRevision' => $input['expectedRevision'] ?? null, 'purpose' => 'advance', 'amount' => $input['amount'] ?? null,
            'reference' => $input['reference'] ?? null, 'valueDate' => $input['valueDate'] ?? null, 'method' => 'bank_transfer',
        ] + ($overpayment ? ['overpaymentConfirmed' => true] : []), $key, $requestId);
        if (($recorded['state'] ?? null) !== 'applied' || !is_string($recorded['entryId'] ?? null)) {
            return ['step' => 'recorded', 'record' => $recorded];
        }
        $revision = $recorded['summary']['revision'] ?? ($this->finance->read($actor, $globalId, $requestId)['summary']['revision'] ?? null);
        $verified = $this->finance->act($actor, $globalId, 'decide_transfer', [
            'expectedRevision' => $revision, 'entryId' => $recorded['entryId'], 'decision' => 'verify', 'singleVerification' => true,
        ], $key . '-verify', $requestId);
        return ['step' => ($verified['state'] ?? null) === 'applied' ? 'verified' : 'recorded', 'state' => $verified['state'] ?? 'unknown', 'entryId' => $recorded['entryId'], 'verify' => $verified];
    }

    /** @return array{employee: ?string, employeeId: ?string, assignedAt: ?string} the order's active shop routing */
    private function shopAssignment(string $orderUuid): array
    {
        $statement = $this->pdo->prepare('SELECT a.employee_uuid, e.display_name, a.assigned_at FROM order_shop_assignments a LEFT JOIN employees e ON e.employee_uuid = a.employee_uuid WHERE a.active_order_uuid = ? LIMIT 1');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['employee' => $row['display_name'], 'employeeId' => (string) $row['employee_uuid'], 'assignedAt' => $this->iso($row['assigned_at'])] : ['employee' => null, 'employeeId' => null, 'assignedAt' => null];
    }

    /**
     * Every shop action of the order still waiting for its answer (pending or unknown): the desk reconciles it with the
     * shop's stored answer of its Idempotency-Key (never guessed).
     *
     * @return list<array{kind: string, action: string, state: string, idempotencyKey: string, employee: ?string, requestedAt: ?string}>
     */
    private function unanswered(string $orderUuid): array
    {
        $out = [];
        foreach (['finance' => 'order_finance_actions', 'invoice' => 'order_invoice_actions', 'edit' => 'order_confirmation_source_edits', 'operation' => 'order_source_operations'] as $kind => $table) {
            $action = $kind === 'edit' ? "'edit'" : ($kind === 'operation' ? 'x.kind' : 'x.action');
            try {
                $statement = $this->pdo->prepare("SELECT {$action} AS action, x.state, x.idempotency_key, e.display_name, x.requested_at FROM {$table} x LEFT JOIN employees e ON e.employee_uuid = x.employee_uuid
                    WHERE x.order_uuid = ? AND x.state IN ('pending', 'unknown') ORDER BY x.requested_at");
                $statement->execute([$orderUuid]);
            } catch (\PDOException) {
                continue;
            }
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['kind' => $kind, 'action' => (string) $r['action'], 'state' => (string) $r['state'], 'idempotencyKey' => (string) $r['idempotency_key'], 'employee' => $r['display_name'], 'requestedAt' => $this->iso($r['requested_at'])];
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> the order's e-Factura follow-up tasks */
    private function efacturaTasks(string $orderUuid): array
    {
        try {
            $statement = $this->pdo->prepare('SELECT t.task_uuid, t.state, t.version, t.due_at, e.display_name FROM invoice_efactura_tasks t LEFT JOIN employees e ON e.employee_uuid = t.issuer_employee_uuid WHERE t.order_uuid = ? ORDER BY t.due_at');
            $statement->execute([$orderUuid]);
        } catch (\PDOException) {
            return [];
        }
        return array_map(fn (array $r): array => ['id' => (string) $r['task_uuid'], 'state' => (string) $r['state'], 'version' => (int) $r['version'], 'dueAt' => $this->iso($r['due_at']), 'issuer' => $r['display_name']], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> where the order is in production, and what is open there (handled on the order's production page) */
    private function production(string $orderUuid): array
    {
        $statement = $this->pdo->prepare('SELECT production_stage_id, document_status, operational_status, production_changed_at FROM operational_orders WHERE order_uuid = ?');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $count = static function (PDO $pdo, string $sql, string $orderUuid): int {
            try {
                $s = $pdo->prepare($sql);
                $s->execute([$orderUuid]);
                return (int) $s->fetchColumn();
            } catch (\PDOException) {
                return 0;
            }
        };
        return [
            'stage' => $row['production_stage_id'] ?? null, 'documentStatus' => $row['document_status'] ?? null, 'operationalStatus' => $row['operational_status'] ?? null,
            'changedAt' => $this->iso($row['production_changed_at'] ?? null),
            'openExceptions' => $count($this->pdo, "SELECT COUNT(*) FROM production_exceptions WHERE order_uuid = ? AND status NOT IN ('rejected', 'cancelled', 'closed', 'completed', 'resolved')", $orderUuid),
        ];
    }

    /** @return array<string, mixed> whether Arasya holds the shop's latest order (the shop pushes; nothing pulls) */
    private function sync(string $orderUuid): array
    {
        $statement = $this->pdo->prepare('SELECT o.freshness_status, o.source_changed_at, o.last_source_seen_at, s.last_contact_at FROM operational_orders o LEFT JOIN order_sources s ON s.source_key = o.source_key WHERE o.order_uuid = ?');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['freshness' => $row['freshness_status'] ?? null, 'shopChangedAt' => $this->iso($row['source_changed_at'] ?? null), 'receivedAt' => $this->iso($row['last_source_seen_at'] ?? null), 'shopContactAt' => $this->iso($row['last_contact_at'] ?? null)];
    }

    /**
     * Why the order waits, in codes (the Dashboard words them):
     * unassigned, waiting_call (pending, assigned, no decision yet), stale_approval (approved, the shop changed it
     * before production), payment_waiting (approved, the advance is not proven), request_<state> (an open finance
     * request that needs someone), unanswered_action (a shop action without an answer for 2 minutes), no_supervisor.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function blockers(array $row, bool $supervised): array
    {
        $blockers = [];
        if ($row['status'] === 'pending') {
            $blockers[] = $row['assigned_employee_uuid'] === null ? 'unassigned' : 'waiting_call';
        } elseif ($row['status'] === 'approved' && (int) $row['production_cleared'] === 1) {
            if ((int) ($row['routed'] ?? 0) === 0 && $row['production_stage_id'] === 'waiting') {
                $blockers[] = 'handoff_waiting';
            }
        } elseif ($row['status'] === 'approved') {
            $missing = ConfirmationClearance::missing($this->pdo, (string) $row['order_uuid']);
            if (array_intersect($missing, ['sync', 'lines', 'price']) !== []) {
                $blockers[] = 'stale_approval';
            }
            if (in_array('payment', $missing, true)) {
                $blockers[] = 'payment_waiting';
            }
        }
        $request = $row['request_state'] ?? null;
        if (in_array($request, ['needs_clarification', 'exception', 'requested'], true)) {
            $blockers[] = 'request_' . $request;
        }
        if ((int) ($row['unanswered'] ?? 0) > 0) {
            $blockers[] = 'unanswered_action';
        }
        if ((int) ($row['efactura_due'] ?? 0) === 1) {
            $blockers[] = 'efactura_due';
        }
        if ((int) ($row['exception_open'] ?? 0) === 1) {
            $blockers[] = 'production_exception';
        }
        if (($row['freshness_status'] ?? 'fresh') !== 'fresh') {
            $blockers[] = 'sync_stale';
        }
        if (!$supervised) {
            $blockers[] = 'no_supervisor';
        }
        return $blockers;
    }

    /** @return array<string, mixed>|null */
    private function row(string $globalOrderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::FACTS . " WHERE o.global_order_id = ? AND o.workflow_cohort = 'confirmare'");
        $stale = $this->clock->now()->modify('-' . self::STALE_SECONDS . ' seconds')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $statement->execute([$stale, $stale, $stale, $stale, $globalOrderId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !in_array($row['source_key'], RootInterventionSources::of($this->pdo), true)) {
            return null;
        }
        return $row;
    }

    private function currentVersion(string $globalId): int
    {
        $statement = $this->pdo->prepare('SELECT c.version FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE o.global_order_id = ?');
        $statement->execute([$globalId]);
        return (int) $statement->fetchColumn();
    }

    private function hasSupervisor(string $source): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM employee_confirmare_scopes s INNER JOIN employees e ON e.employee_uuid = s.employee_uuid WHERE s.source_key = ? AND s.capability = 'supervise' AND e.status = 'active' LIMIT 1");
        $statement->execute([$source]);
        return $statement->fetchColumn() !== false;
    }

    /** @return list<array{id: string, displayName: string, capability: string}> */
    private function operators(string $source): array
    {
        $statement = $this->pdo->prepare("SELECT e.employee_uuid, e.display_name, s.capability FROM employee_confirmare_scopes s INNER JOIN employees e ON e.employee_uuid = s.employee_uuid
            WHERE s.source_key = ? AND e.status = 'active' ORDER BY s.capability, e.display_name");
        $statement->execute([$source]);
        return array_map(static fn (array $r): array => ['id' => (string) $r['employee_uuid'], 'displayName' => (string) $r['display_name'], 'capability' => (string) $r['capability']], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> the desk's earlier actions on this order (newest first) */
    private function history(string $globalId): array
    {
        $statement = $this->pdo->prepare("SELECT action, metadata_json, created_at FROM iam_audit_events WHERE target_type = 'order' AND target_id = ? AND action LIKE 'intervention.%' ORDER BY created_at DESC LIMIT 50");
        $statement->execute([$globalId]);
        return array_map(function (array $r): array {
            $meta = json_decode((string) $r['metadata_json'], true);
            return ['action' => substr((string) $r['action'], strlen('intervention.')), 'reason' => is_array($meta) ? ($meta['reason'] ?? null) : null,
                'outcome' => is_array($meta) ? ($meta['outcome'] ?? null) : null, 'code' => is_array($meta) ? ($meta['code'] ?? null) : null, 'at' => $this->iso($r['created_at'])];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, string> $result */
    private function record(EmployeeIdentity $actor, string $globalId, string $action, string $reason, array $result, string $requestId): void
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->audit->record($actor, 'intervention.' . $action, 'order', $globalId, $globalId, ['reason' => trim($reason)] + $result, $requestId, $now);
    }

    private function requireRoot(EmployeeIdentity $actor): void
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        if (!$actor->isRoot) {
            throw new ApiException(403, 'ROOT_ONLY', 'Only the principal administrator can intervene.');
        }
    }

    private function string(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 64) {
            throw new ApiException(422, 'INTERVENTION_INVALID', "{$field} is required.", ['field' => $field]);
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private function fields(mixed $fields): array
    {
        if (!is_array($fields) || ($fields !== [] && array_is_list($fields))) {
            throw new ApiException(422, 'INTERVENTION_INVALID', 'fields must be an object.', ['field' => 'fields']);
        }
        return $fields;
    }

    private function iso(mixed $utc): ?string
    {
        return $utc === null ? null : (new \DateTimeImmutable((string) $utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
