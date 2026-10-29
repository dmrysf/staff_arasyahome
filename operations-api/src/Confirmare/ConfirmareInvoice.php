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
use Arasya\Operations\Integration\SourceAccess\SourceAccessTarget;
use Arasya\Operations\Integration\SourceAccess\SourceInvoice;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Order\QrReference;
use Arasya\Operations\Production\ProductionAuthorityModes;
use Arasya\Operations\Security\ApiRateLimiter;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * SmartBill invoicing of a Confirmare order (API 2.43.0): the shop's own SmartBill plugin issues, sends and re-issues
 * the documents; Operations decides WHO may ask for it and WHEN, relays the request through the signed source access
 * protocol, and keeps codes and hashes only (order_invoice_reads, order_invoice_actions). Never a document link, a
 * number, an amount or a customer address is stored here.
 *
 * Who (server side, on every call):
 * - documents: the employee who prepares the production ticket and its QR: the invoice `issue` scope of the source AND
 *              the production document generate permission AND the document `operate` scope of the source. Issues the
 *              first document and sends it to the customer;
 * - manager:   the invoice `manage` scope of the source. Also issues and sends, and alone re-issues and closes an
 *              unknown outcome after checking SmartBill;
 * - finance (any finance scope) and viewer (document operate scope with a document permission) read the state only,
 *              without the SmartBill link.
 * Never the Confirmare operator who approved the order or confirmed it with the customer by telephone (owner rule).
 *
 * When: a document is issued or re-issued only for an approved Confirmare order whose production handoff is ready or
 * further (ready, print, awaiting_cutting, in_production: the approval, the call, the lines, the price, the shop sync and
 * the financial condition all hold), with no cancellation or production stop, no open finance action and no open invoice
 * action. Issuing never starts production or changes the QR: the cutting scan still does. The shop checks the money, the
 * existing document, the SmartBill settings and the e-mail itself (InvoicePolicy) and refuses with codes.
 *
 * One action per order at a time; a lost answer is reconciled with the shop's stored answer of the same Idempotency-Key.
 * An outcome the shop itself does not know (SmartBill did not answer) is never retried: a manager checks SmartBill.
 *
 * API 2.44.0 (owner workflow, final pre-production):
 * - operator: the Confirmare operator of the order (its assignee with the `operate` scope of the source) or a Confirmare
 *   supervisor of the source asks the shop for the ADVANCE PROFORMA of an approved cash-on-delivery order and sends it to
 *   the customer (`/proforma/{issue|send-mail|check|resolve}`; resolve is a manager's). Never the final invoice.
 * - routing: once the money is proven (ConfirmareFinancial `cleared`), the operator routes the order to ONE shop employee
 *   of the order's shop who may issue invoices (`/shop-assignment`); one active assignment per order, audited.
 * - the final invoice is issued only by that assigned employee (a manager is exempt from the assignment), after the
 *   employee scanned the order's current production QR (`/invoice/qr`, valid 30 minutes for the active revision).
 */
final readonly class ConfirmareInvoice
{
    public const CALLS_PER_MINUTE = 30;
    /** An action the shop has no record of after this long was never received (SmartBill's own timeout is 120 s). */
    public const RECONCILE_AFTER_SECONDS = 240;
    public const ROLE_DOCUMENTS = 'documents';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_FINANCE = 'finance';
    public const ROLE_VIEWER = 'viewer';
    /** Handoff states in which a document may be issued (the order is cleared for production). */
    public const ISSUABLE = ['ready', 'print', 'awaiting_cutting', 'in_production'];
    public const ROLE_OPERATOR = 'operator';
    /** API 2.47.0: who may read the SmartBill payment reconciliation (the finance employee associates the money). */
    public const PAYMENT_ROLES = [self::ROLE_DOCUMENTS, self::ROLE_MANAGER, self::ROLE_FINANCE];
    /** How long a verified QR scan allows the final invoice (the same employee, the same active revision). */
    public const QR_VALID_SECONDS = 1800;
    private const PROFORMA_FIELDS = [
        'proforma_issue' => ['replaceOutdated'],
        'proforma_send_mail' => ['confirmResend'],
        'proforma_check' => [],
        'proforma_resolve' => ['target', 'resolution', 'series', 'number', 'reasonNote'],
        // API 2.45.0 (YD SOFT 2.19.0): cancel in SmartBill, the customer's telephone acceptance, a smaller advance accepted.
        'proforma_cancel' => ['reasonCode', 'reasonNote', 'confirmNoPayment'],
        // The operator's explicit advance decision after a revision (owner business rule 2026-10-25).
        'total_accept' => ['declaration', 'note', 'advanceDecision', 'confirmLow'],
        'advance_accept' => ['reasonNote', 'confirmLow'],
    ];
    /** The Staff route segment of each proforma action (`/proforma/{segment}`). */
    public const PROFORMA_ROUTES = ['issue' => 'proforma_issue', 'send-mail' => 'proforma_send_mail', 'check' => 'proforma_check', 'resolve' => 'proforma_resolve',
        'cancel' => 'proforma_cancel', 'accept-total' => 'total_accept', 'accept-advance' => 'advance_accept'];
    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    /** Failures where the shop certainly did not run the action. */
    private const NOT_SENT = ['SOURCE_ACCESS_NOT_CONFIGURED', 'SOURCE_ADDRESS_REFUSED', 'SOURCE_ACCESS_DENIED'];
    private const FIELDS = [
        'issue' => [],
        'send_mail' => ['confirmResend'],
        'reissue' => ['reasonCode', 'reasonNote', 'previousCancelled'],
        'resolve' => ['target', 'resolution', 'reasonNote'],
    ];

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private InvoiceScopePolicy $invoices,
        private FinanceScopePolicy $finance,
        private DocumentScopePolicy $documents,
        private SourceAccessRegistry $registry,
        private SourceAccessClient $client,
        private ApiRateLimiter $rateLimiter,
        private Clock $clock,
        #[\SensitiveParameter] private string $hmacKey,
        private ?ProductionAuthorityModes $modes = null,
        private ?ConfirmareScopePolicy $confirmare = null,
        private ?ConfirmareCycles $cycles = null,
        private ?InvoiceEfactura $efactura = null,
    ) {
    }

    /**
     * What the actor may do with the order's SmartBill document (shown with the Confirmare detail; never throws).
     *
     * @return array{configured: bool, read: bool, issue: bool, manage: bool}
     */
    public function capabilities(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $none = ['configured' => false, 'read' => false, 'issue' => false, 'manage' => false, 'proforma' => false, 'route' => false, 'payment' => false];
        try {
            $row = $this->row($this->globalId($globalOrderId));
            $target = $row === null ? null : $this->registry->find((string) $row['source_key']);
            $configured = $target !== null && $target->allowsInvoiceRead() && $this->invoices->installed();
            if ($row === null || !$configured || $row['workflow_cohort'] !== 'confirmare') {
                return ['configured' => $configured] + $none;
            }
            $role = $this->role($actor, $row);
            $operator = $this->operatorRole($actor, $row);
            $actions = $target->allowsInvoiceActions();
            return [
                'configured' => true,
                'read' => $role !== null || $operator !== null,
                'issue' => $actions && in_array($role, [self::ROLE_DOCUMENTS, self::ROLE_MANAGER], true),
                'manage' => $actions && $role === self::ROLE_MANAGER,
                'proforma' => $actions && $operator !== null,
                'route' => $operator !== null,
                // API 2.47.0: the SmartBill payment reconciliation of the final invoice (read-only).
                'payment' => in_array($role, self::PAYMENT_ROLES, true),
            ];
        } catch (ApiException|PDOException) {
            return $none;
        }
    }

    /** @return array<string, mixed> the state and what the actor may do next */
    public function read(EmployeeIdentity $actor, string $globalOrderId, string $requestId): array
    {
        ['row' => $row, 'role' => $role, 'globalId' => $globalId] = $this->context($actor, $globalOrderId);
        $target = $this->registry->find((string) $row['source_key']);
        if ($target === null || !$target->allowsInvoiceRead() || !$this->invoices->installed()) {
            return ['available' => false, 'reason' => 'not_configured'];
        }
        $this->rateLimit($actor);
        try {
            $summary = SourceInvoice::summary($this->client->call($target, SourceAccessClient::ROUTE_INVOICE, $this->payload($row, $actor, $role, $requestId)), (string) $row['source_order_id'], (string) $row['order_number']);
        } catch (SourceAccessException $e) {
            $this->audit($row, $actor, $role, strtolower($e->codeName), $requestId);
            return ['available' => false, 'reason' => strtolower($e->codeName)];
        }
        // The permission again, after the shop answered: a revoked scope during the read wins.
        $after = $this->row($globalId);
        if ($after === null || $this->readerRole($actor, $after) !== $role) {
            $this->audit($row, $actor, $role, 'revoked_during_read', $requestId);
            throw new ApiException(403, 'CONFIRMARE_INVOICE_DENIED', 'Your access to this order changed while the shop was read.');
        }
        if (!$this->audit($row, $actor, $role, 'ok', $requestId, $summary['revision'])) {
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The read could not be recorded, so nothing is shown. Try again.');
        }
        return ['available' => true, 'role' => $role] + $this->present($actor, $after, $target, $role, $summary);
    }

    /**
     * What SmartBill knows about the payment of the order's final invoice, next to the money Arasya verified (API 2.47.0,
     * YD SOFT 2.21.0; owner remediation 2026-10-26). Read-only in the shop and in SmartBill (one `GET
     * invoice/paymentstatus`); audited like a read. Arasya never writes a payment to SmartBill: `association_pending`
     * tells the finance employee to associate the verified money with this invoice in SmartBill Cloud.
     *
     * @return array<string, mixed>
     */
    public function payment(EmployeeIdentity $actor, string $globalOrderId, string $requestId): array
    {
        ['row' => $row, 'role' => $role, 'globalId' => $globalId] = $this->context($actor, $globalOrderId);
        if (!in_array($role, self::PAYMENT_ROLES, true)) {
            throw self::denied();
        }
        $target = $this->registry->find((string) $row['source_key']);
        if ($target === null || !$target->allowsInvoiceRead() || !$this->invoices->installed()) {
            return ['available' => false, 'reason' => 'not_configured'];
        }
        $this->rateLimit($actor);
        try {
            $payment = SourceInvoice::payment($this->client->call($target, SourceAccessClient::ROUTE_INVOICE_PAYMENT, $this->payload($row, $actor, $role, $requestId)));
        } catch (SourceAccessException $e) {
            $this->audit($row, $actor, $role, 'payment_' . strtolower($e->codeName), $requestId);
            return ['available' => false, 'reason' => strtolower($e->codeName)];
        }
        $after = $this->row($globalId);
        if ($after === null || $this->role($actor, $after) !== $role) {
            $this->audit($row, $actor, $role, 'revoked_during_read', $requestId);
            throw new ApiException(403, 'CONFIRMARE_INVOICE_DENIED', 'Your access to this order changed while the shop was read.');
        }
        if (!$this->audit($row, $actor, $role, 'payment_' . $payment['state'], $requestId)) {
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The read could not be recorded, so nothing is shown. Try again.');
        }
        return ['available' => true, 'role' => $role, 'payment' => $payment];
    }

    /**
     * Sends one invoice action to the shop (Idempotency-Key header). A retried request with the same key returns the
     * recorded outcome; with another request it is refused.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function act(EmployeeIdentity $actor, string $globalOrderId, string $action, array $input, string $key, string $requestId): array
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
        if (!isset(self::FIELDS[$action])) {
            throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
        }
        $fields = $this->fields($action, $input);
        ['row' => $row, 'role' => $role, 'globalId' => $globalId] = $this->context($actor, $globalOrderId);
        $manager = in_array($action, ['reissue', 'resolve'], true);
        if ($manager ? $role !== self::ROLE_MANAGER : !in_array($role, [self::ROLE_DOCUMENTS, self::ROLE_MANAGER], true)) {
            throw self::denied();
        }
        $target = $this->registry->find((string) $row['source_key']);
        if ($target === null || !$target->allowsInvoiceActions() || !$this->invoices->installed()) {
            throw new ApiException(409, 'CONFIRMARE_INVOICE_DISABLED', 'SmartBill invoicing is not enabled for this shop.');
        }
        $this->rateLimit($actor);
        $hmac = hash_hmac('sha256', json_encode([$globalId, $action, $fields], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->hmacKey, true);
        $existing = $this->action($actor->employeeUuid, $key);
        if ($existing !== null) {
            if ($existing['order_uuid'] !== $row['order_uuid'] || !hash_equals((string) $existing['request_hmac'], $hmac)) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
            }
            return $this->settled($existing, $row, $target, $actor, $role, $requestId);
        }
        if (in_array($action, ['issue', 'reissue', 'send_mail'], true) && $this->confirmedBy($actor->employeeUuid, (string) $row['order_uuid'])) {
            // Owner rule: the Confirmare operator who approved the order or confirmed it by telephone never invoices it.
            throw new ApiException(403, 'CONFIRMARE_INVOICE_SAME_EMPLOYEE', 'The employee who confirmed the order with the customer does not issue its invoice.');
        }
        $actionUuid = Uuid::v4();
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
            $lock->execute([(string) $row['order_uuid']]);
            // The person again, under the order lock: a revoked scope is either fully before or fully after the action.
            if ($lock->fetchColumn() === false || $this->role($actor, $row, true) !== $role) {
                throw self::denied();
            }
            $blockers = $this->blockers((string) $row['order_uuid'], $action, $actor, $role);
            if ($blockers !== []) {
                throw new ApiException(409, 'CONFIRMARE_INVOICE_NOT_ALLOWED', 'The order cannot be invoiced now.', ['reason' => $blockers[0], 'blockers' => $blockers]);
            }
            $this->pdo->prepare('INSERT INTO order_invoice_actions (action_uuid, order_uuid, global_order_id, source_key, employee_uuid, access_role, action, reason_code, idempotency_key, request_hmac,
                    revision_before, state, request_id, requested_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $actionUuid, (string) $row['order_uuid'], $globalId, (string) $row['source_key'], $actor->employeeUuid, $role, $action,
                $fields['reasonCode'] ?? null, $key, $hmac, $fields['expectedRevision'], 'pending', $this->requestId($requestId), $this->now(),
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            if ($e instanceof ApiException) {
                throw $e;
            }
            if ($e instanceof PDOException && $this->action($actor->employeeUuid, $key) !== null) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key is already in use.');
            }
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The action could not be recorded, so it was not sent. Try again.');
        }
        $body = $this->payload($row, $actor, $role, $requestId) + ['action' => $action, 'idempotencyKey' => $key] + $fields;
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_INVOICE_ACTION, $body);
        } catch (SourceAccessException $e) {
            if (in_array($e->codeName, self::NOT_SENT, true)) {
                $this->complete($actionUuid, 'rejected', strtolower($e->codeName));
                throw self::unavailable($e);
            }
            $this->complete($actionUuid, 'unknown', null);
            return ['state' => 'unknown', 'idempotencyKey' => $key];
        }
        return $this->conclude($actionUuid, $answer, $action, $row, $target, $actor, $role, $key);
    }

    /**
     * Settles a pending or unknown action with the shop's stored answer of its Idempotency-Key. A manager of the source
     * may settle another employee's action.
     *
     * @return array<string, mixed>
     */
    public function reconcile(EmployeeIdentity $actor, string $globalOrderId, mixed $key, string $requestId): array
    {
        if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(422, 'CONFIRMARE_INVOICE_INVALID', 'idempotencyKey is required.', ['field' => 'idempotencyKey']);
        }
        ['row' => $row, 'role' => $role] = $this->context($actor, $globalOrderId);
        if (!in_array($role, [self::ROLE_DOCUMENTS, self::ROLE_MANAGER], true) && $this->operatorRole($actor, $row) === null) {
            throw self::denied();
        }
        $statement = $this->pdo->prepare('SELECT * FROM order_invoice_actions WHERE order_uuid = ? AND idempotency_key = ? AND (employee_uuid = ? OR ?) ORDER BY requested_at LIMIT 1');
        $statement->execute([(string) $row['order_uuid'], $key, $actor->employeeUuid, $role === self::ROLE_MANAGER ? 1 : 0]);
        $found = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($found)) {
            throw new ApiException(404, 'CONFIRMARE_INVOICE_ACTION_NOT_FOUND', 'No such invoice action of this order was found.');
        }
        $target = $this->registry->find((string) $row['source_key']);
        if ($target === null || !$target->allowsInvoiceRead()) {
            throw new ApiException(409, 'CONFIRMARE_INVOICE_DISABLED', 'SmartBill invoicing is not enabled for this shop.');
        }
        $this->rateLimit($actor);
        return $this->settled($found, $row, $target, $actor, $role, $requestId);
    }

    /** Whether an invoice action of the order is not settled yet. */
    /**
     * API 2.46.0: a proforma step of the Confirmare cycle worker being sent to the shop right now (`pending`). An `unknown`
     * step does not count: the shop's own journal refuses a new document while SmartBill's outcome is unknown, and a
     * manager must be able to resolve it.
     */
    public static function cycleStepOpen(PDO $pdo, string $orderUuid): bool
    {
        try {
            $statement = $pdo->prepare("SELECT 1 FROM confirmare_cycle_steps WHERE order_uuid = ? AND state = 'pending' LIMIT 1");
            $statement->execute([$orderUuid]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException) {
            return false;
        }
    }

    public static function actionOpen(PDO $pdo, string $orderUuid): bool
    {
        try {
            $statement = $pdo->prepare("SELECT 1 FROM order_invoice_actions WHERE order_uuid = ? AND state IN ('pending', 'unknown') LIMIT 1");
            $statement->execute([$orderUuid]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException) {
            // Without the invoicing schema no invoice action can exist.
            return false;
        }
    }

    /**
     * What Operations itself refuses now for the action (codes; the shop adds its own).
     *
     * @return list<string>
     */
    private function blockers(string $orderUuid, string $action, ?EmployeeIdentity $actor = null, ?string $role = null): array
    {
        $statement = $this->pdo->prepare('SELECT o.cancellation_state, o.operational_status, o.source_reported_unavailable_at, c.status FROM operational_orders o INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid WHERE o.order_uuid = ?');
        $statement->execute([$orderUuid]);
        $order = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            return ['order_unavailable'];
        }
        $blockers = [];
        if (self::actionOpen($this->pdo, $orderUuid)) {
            $blockers[] = 'invoice_action_open';
        }
        if ($action === 'resolve') {
            return $blockers;
        }
        if ($order['cancellation_state'] === 'held') {
            // A manager stopped production for a cancellation request: no document is issued or sent while it holds
            // (`released` resumed the order; a confirmed cancellation makes the order unavailable, below).
            $blockers[] = 'order_cancellation';
        }
        if ($order['operational_status'] === 'unavailable' || $order['source_reported_unavailable_at'] !== null) {
            $blockers[] = 'order_unavailable';
        }
        if ($action === 'send_mail') {
            return $blockers;
        }
        if ($order['status'] !== 'approved') {
            $blockers[] = 'confirmare_not_approved';
        }
        $handoff = (new ConfirmareHandoff($this->pdo, $this->modes))->view($orderUuid, false, false);
        if (!in_array($handoff['state'], self::ISSUABLE, true)) {
            $blockers[] = 'handoff_not_ready';
        }
        if (ConfirmareFinance::actionOpen($this->pdo, $orderUuid)) {
            $blockers[] = 'finance_action_open';
        }
        if ($action === 'issue' && $actor !== null) {
            // 2.44.0: the final invoice belongs to the shop employee the operator routed the order to (a manager is exempt),
            // after that employee scanned the order's current production QR.
            $assignee = self::assignee($this->pdo, $orderUuid);
            if ($role !== self::ROLE_MANAGER) {
                if ($assignee === null) {
                    $blockers[] = 'not_assigned';
                } elseif ($assignee !== $actor->employeeUuid) {
                    $blockers[] = 'assigned_to_other';
                }
            }
            if (!$this->qrVerified($orderUuid, $actor->employeeUuid)) {
                $blockers[] = 'qr_not_verified';
            }
        }
        return array_values(array_unique($blockers));
    }

    /** The shop employee the order is routed to now (2.44.0), or null. Without the schema nobody is. */
    public static function assignee(PDO $pdo, string $orderUuid): ?string
    {
        try {
            $statement = $pdo->prepare("SELECT employee_uuid FROM order_shop_assignments WHERE active_order_uuid = ?");
            $statement->execute([$orderUuid]);
            $value = $statement->fetchColumn();
            return $value === false ? null : (string) $value;
        } catch (PDOException) {
            return null;
        }
    }

    /** Whether the employee scanned the order's current production QR recently (2.44.0). */
    private function qrVerified(string $orderUuid, string $employeeUuid): bool
    {
        try {
            $statement = $this->pdo->prepare("SELECT 1 FROM order_invoice_qr_checks q INNER JOIN operational_orders o ON o.order_uuid = q.order_uuid
                WHERE q.order_uuid = ? AND q.employee_uuid = ? AND q.outcome = 'verified' AND q.revision_uuid = o.active_document_revision_uuid
                  AND o.document_status = 'active' AND q.checked_at >= ? LIMIT 1");
            $statement->execute([$orderUuid, $employeeUuid, $this->clock->now()->modify('-' . self::QR_VALID_SECONDS . ' seconds')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * The state with what this employee may do now and the Arasya audit of the order's invoice actions.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function present(EmployeeIdentity $actor, array $row, SourceAccessTarget $target, string $role, array $summary): array
    {
        $orderUuid = (string) $row['order_uuid'];
        $handoff = (new ConfirmareHandoff($this->pdo, $this->modes))->view($orderUuid, false, false);
        $actions = $target->allowsInvoiceActions();
        $writer = $actions && in_array($role, [self::ROLE_DOCUMENTS, self::ROLE_MANAGER], true);
        $confirmed = $this->confirmedBy($actor->employeeUuid, $orderUuid);
        $arasya = [];
        foreach (['issue' => 'issue', 'sendMail' => 'send_mail', 'reissue' => 'reissue', 'resolve' => 'resolve'] as $name => $action) {
            $codes = $this->blockers($orderUuid, $action, $actor, $role);
            if (!$actions) {
                $codes[] = 'invoice_observe';
            } elseif (!$writer || (in_array($action, ['reissue', 'resolve'], true) && $role !== self::ROLE_MANAGER)) {
                $codes[] = 'not_allowed_for_role';
            } elseif ($confirmed && $action !== 'resolve') {
                $codes[] = 'same_employee';
            }
            $arasya[$name] = array_values(array_unique($codes));
        }
        $allowed = [];
        foreach ($arasya as $name => $codes) {
            if ($codes === [] && $summary['blockers'][$name] === []) {
                $allowed[] = $name;
            }
        }
        $timeline = $this->timeline($orderUuid);
        $operator = $this->operatorRole($actor, $row);
        $proforma = $this->proformaPresent($orderUuid, $operator, $actions, $summary['proforma'] ?? null);
        $assignment = $this->assignment($orderUuid);
        $names = array_filter([$summary['document']['issuedBy'], $summary['operation']['by'], $summary['mail']['by'], ...array_column($summary['history'], 'by'), ...array_column($timeline, 'employeeId'),
            $summary['proforma']['document']['issuedBy'] ?? null, $summary['proforma']['mail']['by'] ?? null, ...array_column($summary['proforma']['history'] ?? [], 'by'),
            $assignment['employeeId'] ?? null, $assignment['assignedBy'] ?? null]);
        return [
            'summary' => $summary,
            'handoff' => ['state' => $handoff['state'], 'blockers' => $handoff['blockers']],
            'blockers' => $arasya,
            'allowedActions' => $allowed,
            'actionOpen' => self::actionOpen($this->pdo, $orderUuid),
            'timeline' => $timeline,
            'proforma' => $proforma,
            'assignment' => $assignment,
            'routing' => $operator === null ? null : ['allowed' => $this->routeBlockers($orderUuid) === [], 'blockers' => $this->routeBlockers($orderUuid)],
            'qr' => ['verified' => $this->qrVerified($orderUuid, $actor->employeeUuid), 'validSeconds' => self::QR_VALID_SECONDS],
            // A JSON object also when empty (an empty PHP array would encode as a list).
            'employees' => (object) $this->names(array_values(array_unique($names))),
            'me' => $actor->employeeUuid,
        ];
    }

    /** @return list<array<string, mixed>> the invoice actions of the order as Arasya recorded them (codes only), oldest first */
    private function timeline(string $orderUuid): array
    {
        $statement = $this->pdo->prepare('SELECT employee_uuid, access_role, action, reason_code, idempotency_key, state, outcome, result, requested_at, completed_at
            FROM order_invoice_actions WHERE order_uuid = ? ORDER BY requested_at, action_uuid LIMIT 200');
        $statement->execute([$orderUuid]);
        return array_map(static fn (array $row): array => [
            'employeeId' => (string) $row['employee_uuid'],
            'role' => (string) $row['access_role'],
            'action' => (string) $row['action'],
            'reasonCode' => $row['reason_code'],
            'idempotencyKey' => (string) $row['idempotency_key'],
            'state' => (string) $row['state'],
            'outcome' => $row['outcome'],
            'result' => $row['result'],
            'requestedAt' => self::iso((string) $row['requested_at']),
            'completedAt' => $row['completed_at'] === null ? null : self::iso((string) $row['completed_at']),
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<string> $uuids
     * @return array<string, string> employee id => display name (Arasya employees only)
     */
    private function names(array $uuids): array
    {
        $uuids = array_values(array_filter($uuids, static fn (mixed $id): bool => is_string($id) && preg_match(self::UUID_PATTERN, $id) === 1));
        if ($uuids === []) {
            return [];
        }
        $statement = $this->pdo->prepare('SELECT employee_uuid, display_name FROM employees WHERE employee_uuid IN (' . implode(', ', array_fill(0, count($uuids), '?')) . ')');
        $statement->execute($uuids);
        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $employee) {
            $names[(string) $employee['employee_uuid']] = (string) $employee['display_name'];
        }
        return $names;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function fields(string $action, array $input): array
    {
        $allowed = [...self::FIELDS[$action], 'expectedRevision'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only ' . implode(', ', $allowed) . ' are accepted.');
        }
        $invalid = static fn (string $field): ApiException => new ApiException(422, 'CONFIRMARE_INVOICE_INVALID', 'The invoice action is not valid.', ['field' => $field]);
        $revision = $input['expectedRevision'] ?? null;
        if (!is_string($revision) || preg_match('/^sha256:[0-9a-f]{64}$/D', $revision) !== 1) {
            throw $invalid('expectedRevision');
        }
        $fields = ['expectedRevision' => $revision];
        foreach (['confirmResend', 'previousCancelled'] as $flag) {
            if (array_key_exists($flag, $input)) {
                if (!is_bool($input[$flag])) {
                    throw $invalid($flag);
                }
                $fields[$flag] = $input[$flag];
            }
        }
        if (array_key_exists('reasonNote', $input)) {
            $note = $input['reasonNote'];
            if (!is_string($note) || mb_strlen($note) > 300 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note) === 1 || !mb_check_encoding($note, 'UTF-8')) {
                throw $invalid('reasonNote');
            }
            $fields['reasonNote'] = trim($note);
        }
        if ($action === 'reissue') {
            if (!in_array($input['reasonCode'] ?? null, SourceInvoice::REASONS, true)) {
                throw $invalid('reasonCode');
            }
            $fields['reasonCode'] = $input['reasonCode'];
        }
        if ($action === 'resolve') {
            $target = $input['target'] ?? null;
            if (!is_string($target) || !isset(SourceInvoice::RESOLUTIONS[$target])) {
                throw $invalid('target');
            }
            if (!in_array($input['resolution'] ?? null, SourceInvoice::RESOLUTIONS[$target], true)) {
                throw $invalid('resolution');
            }
            $fields += ['target' => $target, 'resolution' => $input['resolution']];
        }
        return $fields;
    }

    /**
     * Who the actor is for this order's SmartBill document, or a refusal (404 for an order the actor cannot see).
     *
     * @return array{row: array<string, mixed>, role: string, globalId: string}
     */
    private function context(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $this->authorization->requireApplication($actor, $actor->hasApplication('staff') ? 'staff' : 'dashboard');
        $globalId = $this->globalId($globalOrderId);
        $row = $this->row($globalId);
        $role = $row === null ? null : ($this->role($actor, $row) ?? ($this->operatorRole($actor, $row) === self::ROLE_OPERATOR ? self::ROLE_OPERATOR : null));
        if ($row === null || $role === null) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        if ($row['workflow_cohort'] !== 'confirmare') {
            // Older orders keep their existing workflow: their SmartBill documents are handled in WordPress only.
            throw new ApiException(409, 'CONFIRMARE_SOURCE_NOT_ELIGIBLE', 'This order is not part of the Confirmare workflow.');
        }
        return ['row' => $row, 'role' => $role, 'globalId' => $globalId];
    }

    /** @param array<string, mixed> $row the actor's role for the order's document now (manager first), or null */
    private function role(EmployeeIdentity $actor, array $row, bool $lock = false): ?string
    {
        if (!$actor->isOperationallyActive() || $actor->mustChangePassword) {
            return null;
        }
        $source = (string) $row['source_key'];
        if ($this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, $source, $lock)) {
            return self::ROLE_MANAGER;
        }
        $documents = $this->documents->allows($actor, DocumentScopePolicy::OPERATE, $source, $lock);
        $generate = $this->authorization->can($actor, DocumentService::GENERATE);
        if ($this->invoices->allows($actor, InvoiceScopePolicy::ISSUE, $source, $lock) && $documents && $generate) {
            return self::ROLE_DOCUMENTS;
        }
        // API 2.50.0: a finance scope reads the document only of an order it reaches through a finance task (a row
        // without an order, such as an assignee check by source, never takes the finance role).
        $order = (string) ($row['order_uuid'] ?? '');
        if ($order !== '' && FinanceTaskAccess::reaches($this->pdo, $this->finance, $actor, $order, $source)) {
            return self::ROLE_FINANCE;
        }
        if ($documents && ($generate || $this->authorization->can($actor, DocumentService::REPRINT))) {
            return self::ROLE_VIEWER;
        }
        return null;
    }

    /**
     * The operator's advance proforma action (API 2.44.0): issue, send-mail, check, resolve (manager). Recorded like an
     * invoice action (order_invoice_actions, role `operator` or `manager`), relayed to the shop's proforma route.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function proforma(EmployeeIdentity $actor, string $globalOrderId, string $action, array $input, string $key, string $requestId): array
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
        if (!isset(self::PROFORMA_FIELDS[$action])) {
            throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
        }
        $fields = $this->proformaFields($action, $input);
        ['row' => $row, 'globalId' => $globalId] = $this->context($actor, $globalOrderId);
        $role = $this->operatorRole($actor, $row);
        if ($role === null && $action === 'proforma_check' && $this->role($actor, $row) === self::ROLE_DOCUMENTS) {
            // The shop employee may ask SmartBill whether the proforma was invoiced (before the final invoice).
            $role = self::ROLE_DOCUMENTS;
        }
        if ($role === null || ($action === 'proforma_resolve' && $role !== self::ROLE_MANAGER)) {
            throw self::denied();
        }
        $target = $this->registry->find((string) $row['source_key']);
        if ($target === null || !$target->allowsInvoiceActions() || !$this->invoices->installed()) {
            throw new ApiException(409, 'CONFIRMARE_INVOICE_DISABLED', 'SmartBill invoicing is not enabled for this shop.');
        }
        $this->rateLimit($actor);
        $hmac = hash_hmac('sha256', json_encode([$globalId, $action, $fields], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->hmacKey, true);
        $existing = $this->action($actor->employeeUuid, $key);
        if ($existing !== null) {
            if ($existing['order_uuid'] !== $row['order_uuid'] || !hash_equals((string) $existing['request_hmac'], $hmac)) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
            }
            return $this->settled($existing, $row, $target, $actor, $role, $requestId);
        }
        $actionUuid = Uuid::v4();
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
            $lock->execute([(string) $row['order_uuid']]);
            $now = $this->row($globalId);
            $again = $now === null ? null : ($role === self::ROLE_DOCUMENTS ? ($this->role($actor, $now, true) === self::ROLE_DOCUMENTS ? self::ROLE_DOCUMENTS : null) : $this->operatorRole($actor, $now, true));
            if ($lock->fetchColumn() === false || $again !== $role) {
                throw self::denied();
            }
            $blockers = $this->proformaBlockers((string) $row['order_uuid'], $action, ($fields['advanceDecision'] ?? null) === 'accept_received');
            if ($blockers !== []) {
                throw new ApiException(409, 'CONFIRMARE_INVOICE_NOT_ALLOWED', 'The proforma action is not allowed now.', ['reason' => $blockers[0], 'blockers' => $blockers]);
            }
            $this->pdo->prepare('INSERT INTO order_invoice_actions (action_uuid, order_uuid, global_order_id, source_key, employee_uuid, access_role, action, reason_code, idempotency_key, request_hmac,
                    revision_before, state, request_id, requested_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $actionUuid, (string) $row['order_uuid'], $globalId, (string) $row['source_key'], $actor->employeeUuid, $role, $action,
                $action === 'proforma_cancel' ? $fields['reasonCode'] : null, $key, $hmac, $fields['expectedRevision'], 'pending', $this->requestId($requestId), $this->now(),
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            if ($e instanceof ApiException) {
                throw $e;
            }
            if ($e instanceof PDOException && $this->action($actor->employeeUuid, $key) !== null) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key is already in use.');
            }
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The action could not be recorded, so it was not sent. Try again.');
        }
        $body = $this->payload($row, $actor, $role, $requestId) + ['action' => $action, 'idempotencyKey' => $key] + $fields;
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_PROFORMA_ACTION, $body);
        } catch (SourceAccessException $e) {
            if (in_array($e->codeName, self::NOT_SENT, true)) {
                $this->complete($actionUuid, 'rejected', strtolower($e->codeName));
                throw self::unavailable($e);
            }
            $this->complete($actionUuid, 'unknown', null);
            return ['state' => 'unknown', 'idempotencyKey' => $key];
        }
        return $this->conclude($actionUuid, $answer, $action, $row, $target, $actor, $role, $key);
    }

    /**
     * Routes an order whose money is proven to one shop employee (API 2.44.0). `expectedAssigneeId` names the assignee
     * the operator saw (null: none); another one in between refuses the request (two operators at once never both win).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function assignShop(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key): array
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
        if (array_diff(array_keys($input), ['employeeId', 'expectedAssigneeId']) !== [] || !array_key_exists('expectedAssigneeId', $input)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only employeeId and expectedAssigneeId are accepted.');
        }
        $employeeId = $input['employeeId'] ?? null;
        $expected = $input['expectedAssigneeId'];
        if (!is_string($employeeId) || preg_match(self::UUID_PATTERN, $employeeId) !== 1 || ($expected !== null && (!is_string($expected) || preg_match(self::UUID_PATTERN, $expected) !== 1))) {
            throw new ApiException(422, 'CONFIRMARE_ASSIGNMENT_INVALID', 'The shop employee is not valid.', ['field' => 'employeeId']);
        }
        ['row' => $row, 'globalId' => $globalId] = $this->context($actor, $globalOrderId);
        $role = $this->operatorRole($actor, $row);
        if ($role === null) {
            throw self::denied();
        }
        $hmac = hash_hmac('sha256', json_encode([$globalId, 'assign', $employeeId, $expected], JSON_THROW_ON_ERROR), $this->hmacKey, true);
        $orderUuid = (string) $row['order_uuid'];
        $this->pdo->beginTransaction();
        try {
            $previous = $this->pdo->prepare('SELECT * FROM order_shop_assignments WHERE assigned_by_employee_uuid = ? AND idempotency_key = ?');
            $previous->execute([$actor->employeeUuid, $key]);
            $replayed = $previous->fetch(PDO::FETCH_ASSOC);
            if (is_array($replayed)) {
                $this->pdo->rollBack();
                if ($replayed['order_uuid'] !== $orderUuid || !hash_equals((string) $replayed['request_hmac'], $hmac)) {
                    throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
                }
                return ['assignment' => $this->assignment($orderUuid), 'replayed' => true];
            }
            $lock = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
            $lock->execute([$orderUuid]);
            $now = $this->row($globalId);
            if ($lock->fetchColumn() === false || $now === null || $this->operatorRole($actor, $now, true) !== $role) {
                throw self::denied();
            }
            $blockers = $this->routeBlockers($orderUuid);
            if ($blockers !== []) {
                throw new ApiException(409, 'CONFIRMARE_ROUTING_NOT_ALLOWED', 'The order cannot be routed to the shop now.', ['reason' => $blockers[0], 'blockers' => $blockers]);
            }
            $current = $this->pdo->prepare('SELECT assignment_uuid, employee_uuid FROM order_shop_assignments WHERE active_order_uuid = ? FOR UPDATE');
            $current->execute([$orderUuid]);
            $active = $current->fetch(PDO::FETCH_ASSOC);
            $activeEmployee = is_array($active) ? (string) $active['employee_uuid'] : null;
            if ($activeEmployee !== $expected) {
                throw new ApiException(409, 'CONFIRMARE_ASSIGNMENT_CHANGED', 'The order was routed by someone else in the meantime. Read it again.', ['assigneeId' => $activeEmployee]);
            }
            if ($activeEmployee === $employeeId) {
                throw new ApiException(409, 'CONFIRMARE_ASSIGNMENT_UNCHANGED', 'The order is already routed to this employee.');
            }
            if (!$this->shopEligible($employeeId, (string) $row['source_key'], $orderUuid, true) || $employeeId === $actor->employeeUuid) {
                throw new ApiException(422, 'CONFIRMARE_SHOP_EMPLOYEE_NOT_ELIGIBLE', 'This employee cannot receive the order: production tickets and SmartBill invoices of this shop are needed, and never the employee who confirmed the order.');
            }
            $at = $this->now();
            if (is_array($active)) {
                $this->pdo->prepare("UPDATE order_shop_assignments SET state = 'released', released_at = ?, released_by_employee_uuid = ?, release_reason = 'reassigned' WHERE assignment_uuid = ? AND state = 'active'")
                    ->execute([$at, $actor->employeeUuid, (string) $active['assignment_uuid']]);
            }
            $this->pdo->prepare("INSERT INTO order_shop_assignments (assignment_uuid, order_uuid, global_order_id, source_key, employee_uuid, assigned_by_employee_uuid, assigned_role, idempotency_key, request_hmac, state, assigned_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)")->execute([Uuid::v4(), $orderUuid, $globalId, (string) $row['source_key'], $employeeId, $actor->employeeUuid, $role, $key, $hmac, $at]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof ApiException) {
                throw $e;
            }
            if ($e instanceof PDOException) {
                // A concurrent routing of the same order (the unique active row) or a reused key.
                throw new ApiException(409, 'CONFIRMARE_ASSIGNMENT_CHANGED', 'The order was routed by someone else in the meantime. Read it again.');
            }
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The routing could not be recorded. Try again.');
        }
        return ['assignment' => $this->assignment($orderUuid), 'replayed' => false];
    }

    /**
     * The shop employees an order can be routed to (API 2.44.0): production tickets (document `operate` scope and the
     * generate permission) and the invoice `issue` scope of the order's shop, active, never the employee who confirmed it.
     *
     * @return array{items: list<array{id: string, name: string}>}
     */
    public function shopCandidates(EmployeeIdentity $actor, string $globalOrderId): array
    {
        ['row' => $row] = $this->context($actor, $globalOrderId);
        if ($this->operatorRole($actor, $row) === null) {
            throw self::denied();
        }
        try {
            $statement = $this->pdo->prepare("SELECT DISTINCT e.employee_uuid, e.display_name FROM employees e
                INNER JOIN employee_invoice_scopes i ON i.employee_uuid = e.employee_uuid AND i.capability = 'issue' AND i.source_key = ?
                WHERE e.employee_uuid <> ? ORDER BY e.display_name LIMIT 200");
            $statement->execute([(string) $row['source_key'], $actor->employeeUuid]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return ['items' => []];
        }
        $items = [];
        foreach ($rows as $candidate) {
            if ($this->shopEligible((string) $candidate['employee_uuid'], (string) $row['source_key'], (string) $row['order_uuid'])) {
                $items[] = ['id' => (string) $candidate['employee_uuid'], 'name' => (string) $candidate['display_name']];
            }
        }
        return ['items' => $items];
    }

    /**
     * The orders routed to the actor (API 2.44.0), newest first: what the shop employee works on. Database only.
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function shopQueue(EmployeeIdentity $actor): array
    {
        $this->authorization->requireApplication($actor, 'staff');
        try {
            $statement = $this->pdo->prepare("SELECT a.global_order_id, a.source_key, a.assigned_at, a.assigned_by_employee_uuid, o.order_number, o.order_uuid, b.display_name AS assigned_by_name
                FROM order_shop_assignments a INNER JOIN operational_orders o ON o.order_uuid = a.order_uuid LEFT JOIN employees b ON b.employee_uuid = a.assigned_by_employee_uuid
                WHERE a.employee_uuid = ? AND a.state = 'active' ORDER BY a.assigned_at DESC LIMIT 200");
            $statement->execute([$actor->employeeUuid]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return ['items' => []];
        }
        $items = [];
        foreach ($rows as $item) {
            // The scope again: a revoked shop never shows its orders.
            if (!$this->invoices->allows($actor, InvoiceScopePolicy::ISSUE, (string) $item['source_key']) && !$this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, (string) $item['source_key'])) {
                continue;
            }
            $handoff = (new ConfirmareHandoff($this->pdo, $this->modes))->view((string) $item['order_uuid'], false, false);
            $items[] = [
                'globalOrderId' => (string) $item['global_order_id'],
                'source' => (string) $item['source_key'],
                'orderNumber' => (string) $item['order_number'],
                'assignedAt' => self::iso((string) $item['assigned_at']),
                'assignedBy' => (string) ($item['assigned_by_name'] ?? ''),
                'handoff' => $handoff['state'],
                'invoiced' => $this->invoiceIssued((string) $item['order_uuid']),
            ];
        }
        return ['items' => $items];
    }

    /**
     * The shop employee scans the order's production QR before the final invoice (API 2.44.0). Only the current QR of the
     * active production document of THIS order verifies; an unknown, foreign, revoked, expired or stale one is refused
     * and recorded.
     *
     * @return array<string, mixed>
     */
    public function verifyQr(EmployeeIdentity $actor, string $globalOrderId, mixed $token, string $requestId): array
    {
        if (!is_string($token) || strlen($token) > 200) {
            throw new ApiException(422, 'INVOICE_QR_INVALID', 'The scanned code is not an Arasya order code.', ['field' => 'qrToken']);
        }
        ['row' => $row, 'role' => $role] = $this->context($actor, $globalOrderId);
        if (!in_array($role, [self::ROLE_DOCUMENTS, self::ROLE_MANAGER], true)) {
            throw self::denied();
        }
        $this->rateLimit($actor);
        $reference = QrReference::parsePayload($token);
        if ($reference === null) {
            throw new ApiException(422, 'INVOICE_QR_INVALID', 'The scanned code is not an Arasya order code.', ['field' => 'qrToken']);
        }
        $orderUuid = (string) $row['order_uuid'];
        $statement = $this->pdo->prepare('SELECT q.order_uuid, q.status, q.expires_at, o.document_status, o.active_document_revision_uuid, r.qr_reference AS revision_qr
            FROM order_qr_references q INNER JOIN operational_orders o ON o.order_uuid = q.order_uuid
            LEFT JOIN production_document_revisions r ON r.revision_uuid = o.active_document_revision_uuid WHERE q.qr_reference = ?');
        $statement->execute([$reference->value]);
        $found = $statement->fetch(PDO::FETCH_ASSOC);
        $outcome = match (true) {
            !is_array($found) => 'unknown_qr',
            $found['order_uuid'] !== $orderUuid => 'wrong_order',
            $found['status'] !== 'active' || ($found['expires_at'] !== null && new \DateTimeImmutable((string) $found['expires_at'], new \DateTimeZone('UTC')) <= $this->clock->now()) => 'inactive_qr',
            $found['document_status'] !== 'active' || $found['revision_qr'] !== $reference->value => 'stale_document',
            default => 'verified',
        };
        $verified = $outcome === 'verified';
        try {
            $this->pdo->prepare('INSERT INTO order_invoice_qr_checks (check_uuid, order_uuid, global_order_id, source_key, employee_uuid, qr_reference, revision_uuid, outcome, request_id, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                Uuid::v4(), $orderUuid, (string) $row['global_order_id'], (string) $row['source_key'], $actor->employeeUuid,
                $verified ? $reference->value : null, $verified ? (string) $found['active_document_revision_uuid'] : null, $outcome, $this->requestId($requestId), $this->now(),
            ]);
        } catch (PDOException) {
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The scan could not be recorded. Try again.');
        }
        if (!$verified) {
            // Never says which order a foreign code belongs to.
            throw new ApiException(409, 'INVOICE_QR_REFUSED', 'This code is not the current production QR of this order.', ['reason' => $outcome]);
        }
        return ['verified' => true, 'validSeconds' => self::QR_VALID_SECONDS];
    }

    /**
     * What Operations refuses now for a proforma action (codes; the shop adds its own).
     *
     * @return list<string>
     */
    private function proformaBlockers(string $orderUuid, string $action, bool $acceptsAdvance = false): array
    {
        $statement = $this->pdo->prepare('SELECT o.cancellation_state, o.operational_status, o.source_reported_unavailable_at, o.payment_method, c.status FROM operational_orders o INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid WHERE o.order_uuid = ?');
        $statement->execute([$orderUuid]);
        $order = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            return ['order_unavailable'];
        }
        $blockers = [];
        if (self::actionOpen($this->pdo, $orderUuid) || ($action !== 'proforma_resolve' && self::cycleStepOpen($this->pdo, $orderUuid))) {
            // A worker step in flight blocks the operator, never the manager who resolves an unknown outcome.
            $blockers[] = 'invoice_action_open';
        }
        if (in_array($action, ['proforma_resolve', 'proforma_check', 'proforma_cancel'], true)) {
            // Cancelling the proforma is allowed during a cancellation hold too: the shop checks the money and SmartBill.
            return $blockers;
        }
        if ($order['cancellation_state'] === 'held') {
            $blockers[] = 'order_cancellation';
        }
        if ($order['operational_status'] === 'unavailable' || $order['source_reported_unavailable_at'] !== null) {
            $blockers[] = 'order_unavailable';
        }
        if ($action === 'proforma_issue' || $action === 'advance_accept') {
            // The customer accepted the advance on the telephone: the Confirmare approval (call, lines, price) is recorded.
            if ($order['status'] !== 'approved') {
                $blockers[] = 'confirmare_not_approved';
            }
            if ($order['payment_method'] !== 'cod') {
                $blockers[] = 'advance_not_required';
            }
        }
        if (($action === 'advance_accept' || $acceptsAdvance) && ConfirmareFinance::actionOpen($this->pdo, $orderUuid)) {
            // A finance action not settled yet: the money it concerns is not known (also for the telephone acceptance that
            // accepts the money received after a revision, owner business rule 2026-10-25).
            $blockers[] = 'finance_action_open';
        }
        return array_values(array_unique($blockers));
    }

    /**
     * Why the order cannot be routed to the shop now (codes): approved, money proven, the handoff not closed or blocked,
     * no cancellation hold, no open finance action.
     *
     * @return list<string>
     */
    private function routeBlockers(string $orderUuid): array
    {
        $statement = $this->pdo->prepare('SELECT o.cancellation_state, o.operational_status, o.source_reported_unavailable_at, o.source_financial_state, c.status FROM operational_orders o INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid WHERE o.order_uuid = ?');
        $statement->execute([$orderUuid]);
        $order = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            return ['order_unavailable'];
        }
        $blockers = [];
        if ($order['status'] !== 'approved') {
            $blockers[] = 'confirmare_not_approved';
        }
        if ($order['source_financial_state'] !== 'cleared') {
            // Only proven money: a verified online payment or a verified advance (never a proforma, an e-mail or a click).
            $blockers[] = 'advance_not_verified';
        }
        if ($order['cancellation_state'] === 'held') {
            $blockers[] = 'order_cancellation';
        }
        if ($order['operational_status'] === 'unavailable' || $order['source_reported_unavailable_at'] !== null) {
            $blockers[] = 'order_unavailable';
        }
        $handoff = (new ConfirmareHandoff($this->pdo, $this->modes))->view($orderUuid, false, false);
        if (!in_array($handoff['state'], self::ISSUABLE, true)) {
            $blockers[] = 'handoff_not_ready';
        }
        if (ConfirmareFinance::actionOpen($this->pdo, $orderUuid)) {
            $blockers[] = 'finance_action_open';
        }
        return array_values(array_unique($blockers));
    }

    /** @return array<string, mixed>|null the order's active shop assignment */
    private function assignment(string $orderUuid): ?array
    {
        try {
            $statement = $this->pdo->prepare('SELECT a.employee_uuid, a.assigned_by_employee_uuid, a.assigned_role, a.assigned_at FROM order_shop_assignments a WHERE a.active_order_uuid = ?');
            $statement->execute([$orderUuid]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return null;
        }
        return is_array($row) ? ['employeeId' => (string) $row['employee_uuid'], 'assignedBy' => (string) $row['assigned_by_employee_uuid'], 'role' => (string) $row['assigned_role'], 'assignedAt' => self::iso((string) $row['assigned_at'])] : null;
    }

    /** Whether an employee may receive a routed order of the source (and is not the one who confirmed it). */
    private function shopEligible(string $employeeUuid, string $source, string $orderUuid, bool $lock = false): bool
    {
        $statement = $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$employeeUuid]);
        if ($statement->fetchColumn() === false) {
            return false;
        }
        try {
            $employee = (new \Arasya\Operations\Employee\PdoEmployeeRepository($this->pdo))->findByUuid($employeeUuid);
        } catch (Throwable) {
            return false;
        }
        return $employee !== null && $employee->isOperationallyActive() && !$employee->mustChangePassword && $employee->hasApplication('staff')
            && $this->role($employee, ['source_key' => $source], $lock) === self::ROLE_DOCUMENTS
            && !$this->confirmedBy($employeeUuid, $orderUuid);
    }

    /** Whether Arasya recorded an applied final invoice of the order (the shop stays the authority). */
    private function invoiceIssued(string $orderUuid): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM order_invoice_actions WHERE order_uuid = ? AND action IN ('issue', 'reissue') AND state = 'applied' AND result = 'issued' LIMIT 1");
        $statement->execute([$orderUuid]);
        return $statement->fetchColumn() !== false;
    }

    /**
     * The proforma part shown to the actor: what this employee may do now, combined from Operations' and the shop's
     * refusals. Null when the shop sends no proforma state (YD SOFT before 2.18.0).
     *
     * @param array<string, mixed>|null $shop
     * @return array<string, mixed>|null
     */
    private function proformaPresent(string $orderUuid, ?string $operator, bool $actions, ?array $shop): ?array
    {
        if ($shop === null) {
            return null;
        }
        $blockers = [];
        $allowed = [];
        foreach (['proformaIssue' => 'proforma_issue', 'proformaSendMail' => 'proforma_send_mail', 'proformaCheck' => 'proforma_check', 'proformaResolve' => 'proforma_resolve',
            'proformaCancel' => 'proforma_cancel', 'totalAccept' => 'total_accept', 'advanceAccept' => 'advance_accept'] as $name => $action) {
            $codes = $this->proformaBlockers($orderUuid, $action);
            if (!$actions) {
                $codes[] = 'invoice_observe';
            } elseif ($operator === null || ($action === 'proforma_resolve' && $operator !== self::ROLE_MANAGER)) {
                $codes[] = 'not_allowed_for_role';
            }
            $blockers[$name] = array_values(array_unique($codes));
            if ($blockers[$name] === [] && ($shop['blockers'][$name] ?? ['missing']) === []) {
                $allowed[] = $name;
            }
        }
        return ['blockers' => $blockers, 'allowedActions' => $allowed, 'role' => $operator];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function proformaFields(string $action, array $input): array
    {
        $allowed = [...self::PROFORMA_FIELDS[$action], 'expectedRevision'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only ' . implode(', ', $allowed) . ' are accepted.');
        }
        $invalid = static fn (string $field): ApiException => new ApiException(422, 'CONFIRMARE_INVOICE_INVALID', 'The proforma action is not valid.', ['field' => $field]);
        $revision = $input['expectedRevision'] ?? null;
        if (!is_string($revision) || preg_match('/^sha256:[0-9a-f]{64}$/D', $revision) !== 1) {
            throw $invalid('expectedRevision');
        }
        $fields = ['expectedRevision' => $revision];
        foreach (['replaceOutdated', 'confirmResend', 'confirmNoPayment', 'declaration', 'confirmLow'] as $flag) {
            if (array_key_exists($flag, $input)) {
                if (!is_bool($input[$flag])) {
                    throw $invalid($flag);
                }
                $fields[$flag] = $input[$flag];
            }
        }
        foreach (['reasonNote', 'note'] as $text) {
            if (array_key_exists($text, $input)) {
                $note = $input[$text];
                if (!is_string($note) || !mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > 300 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note) === 1) {
                    throw $invalid($text);
                }
                $fields[$text] = trim($note);
            }
        }
        if (array_key_exists('advanceDecision', $input)) {
            if (!in_array($input['advanceDecision'], SourceInvoice::ADVANCE_DECISIONS, true)) {
                throw $invalid('advanceDecision');
            }
            $fields['advanceDecision'] = $input['advanceDecision'];
        }
        if ($action === 'proforma_cancel') {
            if (!in_array($input['reasonCode'] ?? null, SourceInvoice::CANCEL_REASONS, true)) {
                throw $invalid('reasonCode');
            }
            $fields['reasonCode'] = $input['reasonCode'];
        }
        if ($action === 'proforma_resolve') {
            $target = $input['target'] ?? null;
            if (!is_string($target) || !isset(SourceInvoice::PROFORMA_RESOLUTIONS[$target])) {
                throw $invalid('target');
            }
            if (!in_array($input['resolution'] ?? null, SourceInvoice::PROFORMA_RESOLUTIONS[$target], true)) {
                throw $invalid('resolution');
            }
            $fields += ['target' => $target, 'resolution' => $input['resolution']];
            $named = $target === 'document' && $input['resolution'] === 'issued';
            foreach (['series', 'number'] as $field) {
                $value = $input[$field] ?? null;
                if ($named ? (!is_string($value) || preg_match('/^[A-Za-z0-9-]{1,20}$/D', $value) !== 1) : $value !== null) {
                    throw $invalid($field);
                }
                if ($named) {
                    $fields[$field] = $value;
                }
            }
        }
        return $fields;
    }

    /**
     * The Confirmare operator of the order for the proforma and the routing (API 2.44.0): `manager` (invoice `manage`
     * scope of the source), `operator` (the order's Confirmare operator with the `operate` scope: the one it is assigned
     * to, or the one who approved it; or a Confirmare supervisor of the source), or null.
     *
     * @param array<string, mixed> $row
     */
    private function operatorRole(EmployeeIdentity $actor, array $row, bool $lock = false): ?string
    {
        // Root administers identities; it never holds a fiscal or Confirmare duty (its unlimited scopes do not count here).
        if ($actor->isRoot || !$actor->isOperationallyActive() || $actor->mustChangePassword || !$actor->hasApplication('staff')) {
            return null;
        }
        $source = (string) $row['source_key'];
        if ($this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, $source, $lock)) {
            return self::ROLE_MANAGER;
        }
        if ($this->confirmare === null) {
            return null;
        }
        if ($this->confirmare->allows($actor, ConfirmareScopePolicy::SUPERVISE, $source, $lock)
            || (in_array($actor->employeeUuid, [$row['assigned_employee_uuid'] ?? null, $row['decided_by_employee_uuid'] ?? null], true) && $this->confirmare->allows($actor, ConfirmareScopePolicy::OPERATE, $source, $lock))) {
            return self::ROLE_OPERATOR;
        }
        return null;
    }

    /** @param array<string, mixed> $row the role an invoice read is recorded with */
    private function readerRole(EmployeeIdentity $actor, array $row): ?string
    {
        return $this->role($actor, $row) ?? ($this->operatorRole($actor, $row) === self::ROLE_OPERATOR ? self::ROLE_OPERATOR : null);
    }

    /** Whether the employee approved the order in Confirmare or recorded the confirmed customer call. */
    private function confirmedBy(string $employeeUuid, string $orderUuid): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM order_confirmations WHERE order_uuid = ? AND decided_by_employee_uuid = ?
            UNION ALL SELECT 1 FROM order_confirmation_contacts WHERE order_uuid = ? AND employee_uuid = ? AND outcome = 'confirmed' LIMIT 1");
        $statement->execute([$orderUuid, $employeeUuid, $orderUuid, $employeeUuid]);
        return $statement->fetchColumn() !== false;
    }

    /**
     * The recorded outcome of an action; a pending or unknown one is asked again from the shop.
     *
     * @param array<string, mixed> $found
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function settled(array $found, array $row, SourceAccessTarget $target, EmployeeIdentity $actor, string $role, string $requestId): array
    {
        $key = (string) $found['idempotency_key'];
        if ($found['state'] === 'applied') {
            // The document state is never stored here: Staff reads it again.
            return ['state' => 'applied', 'idempotencyKey' => $key, 'result' => $found['result']];
        }
        if ($found['state'] === 'rejected') {
            throw self::refused(['code' => (string) $found['outcome'], 'blockers' => [], 'revision' => null]);
        }
        $input = ['idempotencyKey' => $key];
        if ($found['employee_uuid'] !== $actor->employeeUuid) {
            $input['actionEmployeeId'] = (string) $found['employee_uuid'];
        }
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_INVOICE_STATUS, $this->payload($row, $actor, $role, $requestId) + $input);
            if ($answer['status'] !== 200) {
                throw new SourceAccessException('SOURCE_ERROR');
            }
            $status = SourceInvoice::status($answer['body']);
        } catch (SourceAccessException) {
            return ['state' => (string) $found['state'], 'idempotencyKey' => $key, 'reachable' => false];
        }
        if ($status['state'] === 'absent') {
            $age = $this->clock->now()->getTimestamp() - (new \DateTimeImmutable((string) $found['requested_at'], new \DateTimeZone('UTC')))->getTimestamp();
            if ($age < self::RECONCILE_AFTER_SECONDS) {
                return ['state' => (string) $found['state'], 'idempotencyKey' => $key, 'reachable' => true];
            }
            $this->complete((string) $found['action_uuid'], 'rejected', 'not_received');
            throw self::refused(['code' => 'not_received', 'blockers' => [], 'revision' => null]);
        }
        if (!isset($status['status'])) {
            // The shop's journal says the operation is still running or its outcome is unknown: ask again later.
            return ['state' => (string) $found['state'], 'idempotencyKey' => $key, 'reachable' => true, 'shop' => $status['state']];
        }
        return $this->conclude((string) $found['action_uuid'], ['status' => (int) $status['status'], 'body' => $status['result']], (string) $found['action'], $row, $target, $actor, $role, $key);
    }

    /**
     * @param array{status: int, body: array<string, mixed>} $answer
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function conclude(string $actionUuid, array $answer, string $action, array $row, SourceAccessTarget $target, EmployeeIdentity $actor, string $role, string $key): array
    {
        if ($answer['status'] === 200 || $answer['status'] === 202) {
            try {
                $answered = SourceInvoice::answered($answer['body'], $action, (string) $row['source_order_id'], (string) $row['order_number']);
            } catch (SourceAccessException) {
                // The shop says something happened that we cannot read: reconcile, never guess.
                $this->complete($actionUuid, 'unknown', null);
                return ['state' => 'unknown', 'idempotencyKey' => $key];
            }
            // 202: the shop ran the action but SmartBill's outcome is unknown (the shop's journal blocks a new document).
            $this->complete($actionUuid, 'applied', $answer['status'] === 200 ? 'applied' : 'shop_unknown', $answered['result']);
            if (in_array($action, ['issue', 'reissue'], true) && $answer['status'] === 200 && $answered['result'] === 'issued') {
                // API 2.46.0 (owner K-04): the issuer follows the invoice's e-Factura (5 working days).
                $this->efactura?->onInvoiceIssued($actionUuid, (string) $row['order_uuid'], (string) $row['global_order_id'], (string) $row['source_key'], $actor->employeeUuid);
            }
            if ($action === 'proforma_send_mail' && $answer['status'] === 200 && $answered['result'] === 'sent') {
                // API 2.46.0: the proforma reached the customer, so its timer starts (reminders, 120 h expiry).
                $this->cycles?->proformaSent((string) $row['order_uuid'], $actor->employeeUuid, $answered['summary']);
            }
            $after = $this->row((string) $row['global_order_id']) ?? $row;
            return ['state' => 'applied', 'idempotencyKey' => $key, 'result' => $answered['result']] + $this->present($actor, $after, $target, $role, $answered['summary']);
        }
        if ($answer['status'] >= 400 && $answer['status'] < 500) {
            try {
                $refusal = SourceInvoice::refusal($answer['body']);
            } catch (SourceAccessException) {
                $refusal = null;
            }
            if ($refusal !== null) {
                $this->complete($actionUuid, 'rejected', $refusal['code']);
                throw self::refused($refusal);
            }
        }
        // A 5xx (the shop may have acted before failing to answer) or an unknown code: reconcile.
        $this->complete($actionUuid, 'unknown', null);
        return ['state' => 'unknown', 'idempotencyKey' => $key];
    }

    private function complete(string $actionUuid, string $state, ?string $outcome, ?string $result = null): void
    {
        $done = in_array($state, ['applied', 'rejected'], true);
        $this->pdo->prepare("UPDATE order_invoice_actions SET state = ?, outcome = ?, result = ?, completed_at = ? WHERE action_uuid = ? AND state IN ('pending', 'unknown')")->execute([
            $state,
            $outcome === null ? null : substr(preg_replace('/[^a-z_]/', '', $outcome) ?? 'unknown', 0, 40),
            $result,
            $done ? $this->now() : null,
            $actionUuid,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function audit(array $row, EmployeeIdentity $actor, string $role, string $outcome, string $requestId, ?string $revision = null): bool
    {
        try {
            $this->pdo->prepare('INSERT INTO order_invoice_reads (read_uuid, order_uuid, global_order_id, source_key, employee_uuid, access_role, outcome, invoice_revision, request_id, read_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                Uuid::v4(), (string) $row['order_uuid'], (string) $row['global_order_id'], (string) $row['source_key'], $actor->employeeUuid, $role,
                substr(preg_replace('/[^a-z_]/', '', $outcome) ?? 'unknown', 0, 40), $outcome === 'ok' ? $revision : null, $this->requestId($requestId), $this->now(),
            ]);
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function payload(array $row, EmployeeIdentity $actor, string $role, string $requestId): array
    {
        return [
            'orderId' => (string) $row['source_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'actor' => ['employeeId' => $actor->employeeUuid, 'role' => $role],
            'requestId' => $this->requestId($requestId),
        ];
    }

    /** @return array<string, mixed>|null */
    private function row(string $globalId): ?array
    {
        $statement = $this->pdo->prepare('SELECT o.order_uuid, o.global_order_id, o.source_key, o.source_order_id, o.order_number, o.workflow_cohort, c.status, c.assigned_employee_uuid, c.decided_by_employee_uuid
            FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE o.global_order_id = ?');
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function action(string $employeeUuid, string $key): ?array
    {
        try {
            $statement = $this->pdo->prepare('SELECT * FROM order_invoice_actions WHERE employee_uuid = ? AND idempotency_key = ?');
            $statement->execute([$employeeUuid, $key]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (PDOException) {
            return null;
        }
    }

    private function rateLimit(EmployeeIdentity $actor): void
    {
        if (!$this->rateLimiter->hit('confirmare-invoice', $actor->employeeUuid, self::CALLS_PER_MINUTE, 60)) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many shop requests. Try again in a minute.');
        }
    }

    private function globalId(string $globalOrderId): string
    {
        try {
            return GlobalOrderId::fromString($globalOrderId)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
    }

    /** @param array{code: string, blockers: list<string>, revision: ?string} $refusal */
    private static function refused(array $refusal): ApiException
    {
        return new ApiException(409, 'CONFIRMARE_INVOICE_REFUSED', 'The shop refused the invoice action; nothing was issued or sent.', ['reason' => $refusal['code'], 'blockers' => $refusal['blockers'], 'revision' => $refusal['revision']]);
    }

    private static function unavailable(SourceAccessException $e): ApiException
    {
        return new ApiException(503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'The shop could not be asked. Nothing was issued or sent.', ['reason' => strtolower($e->codeName)]);
    }

    private static function denied(): ApiException
    {
        return new ApiException(403, 'CONFIRMARE_INVOICE_DENIED', 'You have no SmartBill permission for this action on this order.');
    }

    private function requestId(string $requestId): string
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $requestId) === 1 ? $requestId : 'none';
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function iso(string $sql): string
    {
        return (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
