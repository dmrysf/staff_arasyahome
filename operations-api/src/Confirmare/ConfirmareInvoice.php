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
    ) {
    }

    /**
     * What the actor may do with the order's SmartBill document (shown with the Confirmare detail; never throws).
     *
     * @return array{configured: bool, read: bool, issue: bool, manage: bool}
     */
    public function capabilities(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $none = ['configured' => false, 'read' => false, 'issue' => false, 'manage' => false];
        try {
            $row = $this->row($this->globalId($globalOrderId));
            $target = $row === null ? null : $this->registry->find((string) $row['source_key']);
            $configured = $target !== null && $target->allowsInvoiceRead() && $this->invoices->installed();
            if ($row === null || !$configured || $row['workflow_cohort'] !== 'confirmare') {
                return ['configured' => $configured] + $none;
            }
            $role = $this->role($actor, $row);
            $actions = $target->allowsInvoiceActions();
            return [
                'configured' => true,
                'read' => $role !== null,
                'issue' => $actions && in_array($role, [self::ROLE_DOCUMENTS, self::ROLE_MANAGER], true),
                'manage' => $actions && $role === self::ROLE_MANAGER,
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
        if ($after === null || $this->role($actor, $after) !== $role) {
            $this->audit($row, $actor, $role, 'revoked_during_read', $requestId);
            throw new ApiException(403, 'CONFIRMARE_INVOICE_DENIED', 'Your access to this order changed while the shop was read.');
        }
        if (!$this->audit($row, $actor, $role, 'ok', $requestId, $summary['revision'])) {
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The read could not be recorded, so nothing is shown. Try again.');
        }
        return ['available' => true, 'role' => $role] + $this->present($actor, $after, $target, $role, $summary);
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
            $blockers = $this->blockers((string) $row['order_uuid'], $action);
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
        if (!in_array($role, [self::ROLE_DOCUMENTS, self::ROLE_MANAGER], true)) {
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
    private function blockers(string $orderUuid, string $action): array
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
        return array_values(array_unique($blockers));
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
            $codes = $this->blockers($orderUuid, $action);
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
        $names = array_filter([$summary['document']['issuedBy'], $summary['operation']['by'], $summary['mail']['by'], ...array_column($summary['history'], 'by'), ...array_column($timeline, 'employeeId')]);
        return [
            'summary' => $summary,
            'handoff' => ['state' => $handoff['state'], 'blockers' => $handoff['blockers']],
            'blockers' => $arasya,
            'allowedActions' => $allowed,
            'actionOpen' => self::actionOpen($this->pdo, $orderUuid),
            'timeline' => $timeline,
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
        $role = $row === null ? null : $this->role($actor, $row);
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
        if ($this->finance->allows($actor, FinanceScopePolicy::VIEW, $source)) {
            return self::ROLE_FINANCE;
        }
        if ($documents && ($generate || $this->authorization->can($actor, DocumentService::REPRINT))) {
            return self::ROLE_VIEWER;
        }
        return null;
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
        $statement = $this->pdo->prepare('SELECT o.order_uuid, o.global_order_id, o.source_key, o.source_order_id, o.order_number, o.workflow_cohort, c.status
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
