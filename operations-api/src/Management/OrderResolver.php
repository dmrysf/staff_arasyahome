<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Confirmare\ConfirmationClearance;
use Arasya\Operations\Confirmare\FinanceVerificationRequests;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Support\Clock;
use DateTimeZone;
use PDO;

/**
 * The order resolver (API 2.75.0, owner request 2026-11-18): one order number in, one answer out. Where the order is,
 * whether it is blocked, in which system and waiting for whom, and the ONE next step that unblocks it.
 *
 * The resolver decides nothing on its own and writes nothing itself. Every step is a root intervention desk action
 * (RootInterventionService::act) or the finance request command (FinanceVerificationRequests::request) of the desk:
 * the same services Staff uses, with their locks, versions, idempotency, shop calls and refusals. The steps act under
 * the root identity (the shop and finance records name the principal administrator). Who pressed the button is kept
 * twice: in the desk's written reason and in the IAM audit (`intervention.resolve`, actor = that employee).
 *
 * Who may use it: root, and an employee holding `orders.intervene` (Dashboard application, owner decision 2026-11-18:
 * the "Rezolvare comenzi blocate" role, assigned by root). Such an employee may also record and verify an advance found
 * on the bank statement, like root (owner decision 2026-11-18).
 *
 * The step offered is computed again on the server when it is pressed: a fingerprint of the facts it was computed from
 * (Confirmare version, request version, the shop's money and invoice revisions, the target) must still match, or the
 * resolver answers RESOLVER_CHANGED with the fresh diagnosis. A button pressed on an old screen never acts.
 */
final readonly class OrderResolver
{
    public const PERMISSION = 'orders.intervene';
    /** The steps the resolver offers. */
    public const FIXES = ['reconcile', 'assign', 'approve', 'reopen', 'proforma', 'send-finance', 'resubmit', 'verify-advance', 'confirm-transfer', 'shop-assign', 'close-task'];
    private const CLOSED_COMMERCE = ['cancelled', 'refunded', 'failed', 'trash'];
    private const FINISHED_COMMERCE = ['completed', 'expediat'];
    private const RECONCILE = ['finance' => 'finance-reconcile', 'invoice' => 'invoice-reconcile', 'edit' => 'edit-reconcile', 'operation' => 'operation-reconcile'];

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private RootInterventionService $desk,
        private FinanceVerificationRequests $requests,
        private IamAuditLogger $audit,
        private Clock $clock,
    ) {
    }

    /** Whether the actor may use the resolver (the Dashboard shows it from this flag; every route checks again). */
    public function canUse(EmployeeIdentity $actor): bool
    {
        if (!$actor->hasApplication('dashboard') || !$actor->isOperationallyActive() || $actor->mustChangePassword) {
            return false;
        }
        return $actor->isRoot || $this->authorization->can($actor, self::PERMISSION);
    }

    /**
     * The blocked Trend Home and Outlet Perdele orders (the desk's stuck list), with the problem each one has.
     *
     * @return array<string, mixed>
     */
    public function stuck(EmployeeIdentity $actor): array
    {
        $root = $this->actingRoot($actor);
        $overview = $this->desk->overview($root, 'stuck', null);
        $items = array_map(static fn (array $item): array => [
            'globalOrderId' => $item['globalOrderId'], 'orderNumber' => $item['orderNumber'], 'source' => $item['source'], 'status' => $item['status'],
            'assignee' => $item['assignee'], 'submittedAt' => $item['submittedAt'], 'updatedAt' => $item['updatedAt'], 'blockers' => $item['blockers'],
        ], $overview['items']);
        return ['items' => $items, 'limit' => $overview['limit']];
    }

    /**
     * Every order with this number (one per source; usually one), each with its diagnosis.
     *
     * @return array{number: string, orders: list<array<string, mixed>>}
     */
    public function diagnose(EmployeeIdentity $actor, string $number, string $requestId): array
    {
        $root = $this->actingRoot($actor);
        $number = trim($number);
        if (preg_match('/^(?:(trendhome|outletperdele|trendyol|b2b):)?([0-9A-Za-z-]{1,40})$/D', $number, $m) !== 1) {
            throw new ApiException(422, 'RESOLVER_INVALID', 'Write an order number.', ['field' => 'number']);
        }
        $statement = $this->pdo->prepare('SELECT global_order_id FROM operational_orders WHERE order_number = ?' . ($m[1] !== '' ? ' AND source_key = ?' : '') . ' ORDER BY source_key LIMIT 5');
        $statement->execute($m[1] !== '' ? [$m[2], $m[1]] : [$m[2]]);
        $orders = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $globalId) {
            $orders[] = $this->card($root, (string) $globalId, $requestId)['card'];
        }
        return ['number' => $m[2], 'orders' => $orders];
    }

    /**
     * Presses the step offered for the order. `action` must be the step (or its alternative) the diagnosis offers now and
     * `fingerprint` the one the screen showed; `fields` carries what only a person can know (the bank statement line of an
     * advance). Answers the outcome and the fresh diagnosis.
     *
     * @param array<string, mixed> $input {action, fingerprint, fields?}
     * @return array<string, mixed>
     */
    public function resolve(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId): array
    {
        $root = $this->actingRoot($actor);
        if (array_diff(array_keys($input), ['action', 'fingerprint', 'fields']) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only action, fingerprint and fields are accepted.');
        }
        $action = $input['action'] ?? null;
        if (!is_string($action) || !in_array($action, self::FIXES, true) || !is_string($input['fingerprint'] ?? null)) {
            throw new ApiException(422, 'RESOLVER_INVALID', 'action and fingerprint are required.', ['field' => 'action']);
        }
        $fields = $input['fields'] ?? [];
        if (!is_array($fields) || ($fields !== [] && array_is_list($fields))) {
            throw new ApiException(422, 'RESOLVER_INVALID', 'fields must be an object.', ['field' => 'fields']);
        }
        $globalId = $this->globalId($globalOrderId);
        ['card' => $card, 'plans' => $plans] = $this->card($root, $globalId, $requestId);
        $plan = $plans[$action] ?? null;
        if ($plan === null || !hash_equals((string) $card['fingerprint'], (string) $input['fingerprint'])) {
            throw new ApiException(409, 'RESOLVER_CHANGED', 'The order changed since it was shown. Look at it again.', ['order' => $card]);
        }
        $reason = sprintf('Rezolvare comandă blocată (%s): %s', $actor->username, (string) ($card['problem']['code'] ?? 'none'));
        try {
            $result = $this->execute($root, $globalId, $plan, $fields, $reason, $key, $requestId);
        } catch (ApiException $e) {
            $this->record($actor, $globalId, $action, (string) ($card['problem']['code'] ?? ''), ['outcome' => 'refused', 'code' => $e->errorCode], $requestId);
            throw $e;
        }
        $outcome = (string) ($result['step'] ?? $result['state'] ?? $result['result'] ?? $result['status'] ?? 'applied');
        $this->record($actor, $globalId, $action, (string) ($card['problem']['code'] ?? ''), ['outcome' => substr(preg_replace('/[^a-z_]/', '', strtolower($outcome)) ?: 'applied', 0, 40)], $requestId);
        return ['action' => $action, 'outcome' => $outcome, 'order' => $this->card($root, $globalId, $requestId . '-after')['card']];
    }

    // ---- Diagnosis ------------------------------------------------------------------------------------------------------

    /**
     * @return array{card: array<string, mixed>, plans: array<string, array<string, mixed>>}
     */
    private function card(EmployeeIdentity $root, string $globalId, string $requestId): array
    {
        $statement = $this->pdo->prepare("SELECT o.order_uuid, o.global_order_id, o.order_number, o.source_key, o.workflow_cohort, o.payment_method, o.production_stage_id, o.operational_status,
                o.source_commerce_status_code, o.source_reported_unavailable_at, o.source_financial_state, o.source_financial_attention, o.freshness_status, o.document_status,
                o.active_document_revision_uuid, o.production_completed_at, COALESCE(o.source_submitted_at, o.created_at) AS submitted_at,
                st.display_name AS stage_name, st.ordinal AS stage_ordinal,
                c.status AS confirmation, c.version, c.production_cleared, c.updated_at AS confirmation_at, c.assigned_employee_uuid, a.display_name AS assignee
            FROM operational_orders o LEFT JOIN order_confirmations c ON c.order_uuid = o.order_uuid LEFT JOIN employees a ON a.employee_uuid = c.assigned_employee_uuid
            LEFT JOIN production_stages st ON st.stage_id = o.production_stage_id WHERE o.global_order_id = ?");
        $statement->execute([$globalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiException(404, 'RESOLVER_NOT_FOUND', 'No order with this number was found.');
        }
        $base = [
            'globalOrderId' => (string) $row['global_order_id'], 'orderNumber' => (string) $row['order_number'], 'source' => (string) $row['source_key'],
            'submittedAt' => $this->iso($row['submitted_at']), 'paymentMethod' => $row['payment_method'],
            'stage' => ['id' => (string) $row['production_stage_id'], 'name' => $row['stage_name'], 'ordinal' => $row['stage_ordinal'] === null ? null : (int) $row['stage_ordinal']],
        ];
        if ($row['workflow_cohort'] !== 'confirmare' || !in_array($row['source_key'], ['trendhome', 'outletperdele'], true) || $row['confirmation'] === null) {
            $flow = match (true) {
                $row['source_key'] === 'trendyol' => 'trendyol',
                $row['source_key'] === 'b2b' => 'b2b',
                default => 'shop_legacy',
            };
            $done = $row['production_completed_at'] !== null || in_array($row['operational_status'], ['completed', 'handed_over'], true);
            return ['card' => $base + [
                'flow' => $flow,
                'steps' => $this->otherSteps($row, $done),
                'problem' => ['code' => 'other_flow', 'system' => $flow, 'owner' => null, 'since' => null],
                'fix' => null, 'alternative' => null, 'facts' => ['operationalStatus' => $row['operational_status'], 'shopStatus' => $row['source_commerce_status_code']],
                'fingerprint' => $this->fingerprint([$globalId, 'other_flow']),
            ], 'plans' => []];
        }

        $detail = $this->desk->order($root, $globalId, $requestId);
        $finance = ($detail['finance']['available'] ?? false) === true ? $detail['finance'] : null;
        $invoice = ($detail['invoice']['available'] ?? true) !== false ? $detail['invoice'] : null;
        $request = is_array($detail['request']['request'] ?? null) ? $detail['request']['request'] : null;
        $openTask = $this->openTask((string) $row['order_uuid']);
        $closed = $row['operational_status'] === 'unavailable' || $row['source_reported_unavailable_at'] !== null || in_array($row['source_commerce_status_code'], self::CLOSED_COMMERCE, true) || $row['confirmation'] === 'rejected';
        $finished = $row['production_completed_at'] !== null || in_array($row['source_commerce_status_code'], self::FINISHED_COMMERCE, true);
        $summary = $finance['summary'] ?? null;
        $advance = is_array($summary['advance'] ?? null) ? $summary['advance'] : null;
        $proforma = $advance !== null && is_array($advance['proforma'] ?? null) && !($advance['proformaCancelled'] ?? false) ? $advance['proforma'] : null;
        $money = (string) ($summary['financial']['state'] ?? $row['source_financial_state'] ?? 'unknown');
        $attention = $summary['financial']['attention'] ?? $row['source_financial_attention'];
        $missing = $detail['missing'];
        $routed = $detail['shop']['employeeId'] !== null;
        $inProduction = $row['production_stage_id'] !== 'waiting';

        $facts = [
            'confirmation' => (string) $row['confirmation'], 'assignee' => $row['assignee'], 'money' => $money, 'attention' => $attention,
            'outstanding' => $advance['outstanding'] ?? null, 'currency' => $summary['currency'] ?? null,
            'proforma' => $proforma === null ? null : ['series' => $proforma['series'] ?? null, 'number' => $proforma['number'] ?? null, 'amount' => $proforma['amount'] ?? null],
            'paymentReference' => $advance['paymentReference'] ?? null,
            'financeTask' => $openTask === null ? ($request === null ? null : ['state' => $request['state'], 'assignee' => $request['assignee'], 'since' => $request['updatedAt'] ?? null, 'open' => false])
                : ['state' => $openTask['state'], 'assignee' => $openTask['assignee'], 'since' => $this->iso($openTask['updated_at']), 'open' => true],
            'shopEmployee' => $detail['shop']['employee'], 'shopStatus' => $row['source_commerce_status_code'], 'operationalStatus' => $row['operational_status'],
            'document' => $row['active_document_revision_uuid'] === null ? 'missing' : (string) $row['document_status'],
            'unanswered' => count($detail['unanswered']), 'shopReadable' => $finance !== null,
        ];
        $steps = [
            ['key' => 'confirmation', 'state' => $row['confirmation'] === 'approved' ? 'done' : 'current'],
            ['key' => 'payment', 'state' => $money === 'cleared' || (int) $row['production_cleared'] === 1 ? 'done' : ($row['confirmation'] === 'approved' ? 'current' : 'todo')],
            ['key' => 'handoff', 'state' => $routed || $inProduction ? 'done' : ((int) $row['production_cleared'] === 1 ? 'current' : 'todo')],
            ['key' => 'production', 'state' => $finished ? 'done' : ($inProduction ? 'current' : 'todo')],
            ['key' => 'delivery', 'state' => $finished ? 'done' : 'todo'],
        ];

        $problem = null;
        $plans = [];
        $fix = null;
        $alternative = null;
        $offer = function (string $action, array $plan, array $public = []) use (&$plans): array {
            $plans[$action] = ['action' => $action] + $plan;
            return ['action' => $action] + $public;
        };
        $unanswered = $this->staleUnanswered($detail['unanswered']);

        if ($closed || $finished) {
            if ($openTask !== null && in_array($openTask['state'], ['requested', 'assigned', 'needs_clarification'], true) && $openTask['finance_idempotency_key'] === null && $openTask['source_entry_id'] === null) {
                $problem = ['code' => 'task_on_closed_order', 'system' => 'finance', 'owner' => $openTask['assignee'], 'since' => $this->iso($openTask['updated_at'])];
                $fix = $offer('close-task', ['desk' => 'request-cancel', 'input' => ['expectedVersion' => (int) $openTask['version'], 'reasonCode' => $closed ? 'customer_withdrew' : 'already_settled']]);
            } elseif (!$closed && $finished) {
                $steps = array_map(static fn (array $s): array => ['key' => $s['key'], 'state' => 'done'], $steps);
            }
        } elseif ($unanswered !== null) {
            $problem = ['code' => 'unanswered_action', 'system' => 'shop', 'owner' => $unanswered['employee'], 'since' => $unanswered['requestedAt']];
            $fix = $offer('reconcile', ['desk' => self::RECONCILE[$unanswered['kind']], 'input' => ['idempotencyKey' => $unanswered['idempotencyKey']]], ['kind' => $unanswered['kind']]);
        } elseif ($row['confirmation'] === 'pending') {
            if ($row['assigned_employee_uuid'] === null) {
                $operator = $this->leastLoadedOperator($detail['operators']);
                $problem = ['code' => $operator === null ? 'no_operator' : 'unassigned', 'system' => 'confirmare', 'owner' => null, 'since' => $this->iso($row['submitted_at'])];
                if ($operator !== null) {
                    $fix = $offer('assign', ['desk' => 'assign', 'input' => ['expectedVersion' => (int) $row['version'], 'employeeId' => $operator['id']]], ['target' => $operator['displayName']]);
                }
            } else {
                $problem = ['code' => 'waiting_call', 'system' => 'confirmare', 'owner' => $row['assignee'], 'since' => $this->iso($row['confirmation_at'])];
                $fix = $offer('approve', ['desk' => 'approve', 'input' => ['expectedVersion' => (int) $row['version']]], ['confirm' => 'customer_confirmed']);
            }
        } elseif ($row['confirmation'] === 'approved' && (int) $row['production_cleared'] === 0) {
            if ($missing === ['call'] && \Arasya\Operations\Confirmare\ConfirmareService::awaitingCustomerCheck($this->pdo, (string) $row['order_uuid'])) {
                // API 2.77.6: manager-routed; the shop employee it is routed to checks it with the customer (no desk fix).
                $problem = ['code' => 'customer_check_waiting', 'system' => 'shop', 'owner' => $detail['shop']['employee'] ?? null, 'since' => $this->iso($row['confirmation_at'])];
            } elseif (array_intersect($missing, ['sync', 'lines', 'price']) !== []) {
                $problem = ['code' => 'stale_approval', 'system' => 'confirmare', 'owner' => $row['assignee'], 'since' => $this->iso($row['confirmation_at'])];
                $fix = $offer('reopen', ['desk' => 'reopen', 'input' => ['expectedVersion' => (int) $row['version']]]);
            } elseif ($row['payment_method'] !== 'cod') {
                $problem = ['code' => 'online_payment_waiting', 'system' => 'customer', 'owner' => null, 'since' => $this->iso($row['confirmation_at'])];
            } elseif ($finance === null) {
                $problem = ['code' => 'shop_unreadable', 'system' => 'shop', 'owner' => null, 'since' => null];
            } elseif ($attention === 'transfer_pending') {
                $entry = array_values(array_filter($summary['entries'] ?? [], static fn (array $e): bool => ($e['kind'] ?? '') === 'transfer_in' && ($e['state'] ?? '') === 'submitted'))[0] ?? null;
                $problem = ['code' => 'transfer_unconfirmed', 'system' => 'finance', 'owner' => $openTask['assignee'] ?? null, 'since' => $this->iso($openTask['updated_at'] ?? null)];
                if ($entry !== null) {
                    $fix = $offer('confirm-transfer', ['desk' => 'finance', 'input' => ['financeAction' => 'decide_transfer', 'fields' => ['expectedRevision' => (string) $summary['revision'], 'entryId' => (string) $entry['id'], 'decision' => 'verify', 'singleVerification' => true]]],
                        ['amount' => $entry['amount'] ?? null, 'reference' => $entry['reference'] ?? null]);
                }
            } elseif ($attention !== null || $money === 'unknown') {
                $problem = ['code' => 'money_attention', 'system' => 'finance', 'owner' => $openTask['assignee'] ?? null, 'since' => null, 'detail' => $attention ?? 'unknown'];
            } elseif ($proforma === null) {
                $problem = ['code' => 'proforma_missing', 'system' => 'shop', 'owner' => $row['assignee'], 'since' => $this->iso($row['confirmation_at'])];
                $revision = $invoice['summary']['revision'] ?? null;
                if (is_string($revision)) {
                    $fix = $offer('proforma', ['desk' => 'proforma', 'input' => ['route' => 'issue', 'fields' => ['expectedRevision' => $revision]]]);
                }
            } else {
                $verify = $offer('verify-advance', ['desk' => 'advance', 'revision' => (string) $summary['revision']],
                    ['needs' => ['amount', 'reference', 'valueDate'], 'defaults' => ['amount' => $advance['outstanding'] ?? null, 'valueDate' => gmdate('Y-m-d')]]);
                $state = $openTask['state'] ?? null;
                if ($state === null) {
                    $problem = ['code' => 'finance_not_sent', 'system' => 'finance', 'owner' => $row['assignee'], 'since' => $this->iso($row['confirmation_at']),
                        'detail' => $request === null ? 'never_sent' : (string) $request['state']];
                    $fix = $offer('send-finance', ['request' => true]);
                    $alternative = $verify;
                } elseif ($state === 'needs_clarification') {
                    $problem = ['code' => 'finance_clarification', 'system' => 'finance', 'owner' => $row['assignee'], 'since' => $this->iso($openTask['updated_at'])];
                    $fix = $offer('resubmit', ['desk' => 'request-resubmit', 'input' => ['expectedVersion' => (int) $openTask['version']]]);
                    $alternative = $verify;
                } else {
                    $problem = ['code' => 'finance_waiting', 'system' => 'finance', 'owner' => $openTask['assignee'], 'since' => $this->iso($openTask['updated_at']), 'detail' => (string) $state];
                    $fix = $verify;
                }
            }
        } elseif ($row['confirmation'] === 'approved' && !$inProduction && !$routed) {
            $candidate = $this->leastLoadedShop($detail['shop']['candidates']);
            $problem = ['code' => $candidate === null ? 'no_shop_employee' : 'handoff_waiting', 'system' => 'shop', 'owner' => null, 'since' => $this->iso($row['confirmation_at'])];
            if ($candidate !== null) {
                $fix = $offer('shop-assign', ['desk' => 'shop-assign', 'input' => ['employeeId' => $candidate['id'], 'expectedAssigneeId' => null]], ['target' => $candidate['name']]);
            }
        } elseif ((int) ($detail['production']['openExceptions'] ?? 0) > 0) {
            $problem = ['code' => 'production_exception', 'system' => 'production', 'owner' => null, 'since' => $detail['production']['changedAt'] ?? null];
        } elseif (array_filter($detail['efactura'], static fn (array $t): bool => in_array($t['state'], ['open', 'sent', 'rejected'], true) && $t['dueAt'] !== null && $t['dueAt'] < gmdate('Y-m-d\TH:i:s\Z')) !== []) {
            $problem = ['code' => 'efactura_due', 'system' => 'shop', 'owner' => $detail['shop']['employee'], 'since' => null];
        } elseif (!$inProduction) {
            $problem = ['code' => $row['active_document_revision_uuid'] === null ? 'document_waiting' : 'cutting_waiting', 'system' => 'shop', 'owner' => $detail['shop']['employee'], 'since' => $detail['shop']['assignedAt']];
        }
        if ($problem === null && ($row['freshness_status'] ?? 'fresh') !== 'fresh') {
            $problem = ['code' => 'sync_stale', 'system' => 'sync', 'owner' => null, 'since' => $detail['sync']['receivedAt'] ?? null];
        }
        if ($problem !== null) {
            foreach ($steps as $i => $step) {
                if ($step['state'] === 'current') {
                    $steps[$i]['state'] = 'blocked';
                    break;
                }
            }
        }
        $card = $base + ['flow' => 'confirmare', 'steps' => $steps, 'problem' => $problem, 'fix' => $fix, 'alternative' => $alternative, 'facts' => $facts];
        $card['fingerprint'] = $this->fingerprint([$globalId, $problem['code'] ?? null, $plans, (int) $row['version'], $openTask['version'] ?? null, $summary['revision'] ?? null]);
        return ['card' => $card, 'plans' => $plans];
    }

    /** @param array<string, mixed> $row @return list<array{key: string, state: string}> */
    private function otherSteps(array $row, bool $done): array
    {
        $started = $row['production_stage_id'] !== 'waiting';
        return [
            ['key' => 'intake', 'state' => 'done'],
            ['key' => 'production', 'state' => $done ? 'done' : ($started ? 'current' : 'todo')],
            ['key' => 'delivery', 'state' => $done ? 'done' : 'todo'],
        ];
    }

    // ---- Execution ------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function execute(EmployeeIdentity $root, string $globalId, array $plan, array $fields, string $reason, string $key, string $requestId): array
    {
        if (($plan['request'] ?? false) === true) {
            $result = $this->requests->request($root, $globalId, ['purpose' => 'advance'], $key, $requestId);
            return ['state' => (string) ($result['request']['state'] ?? 'requested')];
        }
        $input = $plan['input'] ?? [];
        if ($plan['action'] === 'verify-advance') {
            if (array_diff(array_keys($fields), ['amount', 'reference', 'valueDate', 'overpayment', 'statementChecked']) !== []) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Only amount, reference, valueDate, overpayment and statementChecked are accepted.');
            }
            if (($fields['statementChecked'] ?? false) !== true) {
                throw new ApiException(422, 'RESOLVER_STATEMENT_REQUIRED', 'Confirm that the payment is on the bank statement.', ['field' => 'statementChecked']);
            }
            $input = ['expectedRevision' => (string) $plan['revision'], 'amount' => $fields['amount'] ?? null, 'reference' => $fields['reference'] ?? null,
                'valueDate' => $fields['valueDate'] ?? null, 'overpayment' => ($fields['overpayment'] ?? false) === true];
        } elseif ($fields !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'This step takes no fields.');
        }
        return $this->desk->act($root, $globalId, (string) $plan['desk'], ['reason' => $reason] + $input, $key, $requestId);
    }

    // ---- Helpers --------------------------------------------------------------------------------------------------------

    /**
     * The identity the steps act under: root itself, or (for an employee holding orders.intervene) the root identity.
     */
    private function actingRoot(EmployeeIdentity $actor): EmployeeIdentity
    {
        if (!$actor->hasApplication('dashboard')) {
            throw new ApiException(403, 'APPLICATION_ACCESS_DENIED', 'This account cannot use the Dashboard.');
        }
        if (!$this->canUse($actor)) {
            throw new ApiException(403, 'RESOLVER_DENIED', 'Only root and the order resolvers can use the order resolver.');
        }
        if ($actor->isRoot) {
            return $actor;
        }
        $uuid = $this->pdo->query('SELECT employee_uuid FROM system_root_identity LIMIT 1')->fetchColumn();
        $root = is_string($uuid) ? (new PdoEmployeeRepository($this->pdo))->findByUuid($uuid) : null;
        if ($root === null || !$root->isRoot || !$root->isOperationallyActive() || $root->mustChangePassword) {
            throw new ApiException(503, 'RESOLVER_UNAVAILABLE', 'The root identity is not ready; the resolver cannot act.');
        }
        return $root;
    }

    /** @return array<string, mixed>|null the order's open finance task, with its assignee's name */
    private function openTask(string $orderUuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT r.request_uuid, r.state, r.version, r.updated_at, r.finance_idempotency_key, r.source_entry_id, e.display_name AS assignee
            FROM finance_verification_requests r LEFT JOIN employees e ON e.employee_uuid = r.assigned_employee_uuid WHERE r.open_order_uuid = ?');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * The oldest shop action without an answer for 2 minutes (a newer one may still be answered).
     *
     * @param list<array<string, mixed>> $unanswered
     * @return array<string, mixed>|null
     */
    private function staleUnanswered(array $unanswered): ?array
    {
        $limit = $this->clock->now()->modify('-120 seconds')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        foreach ($unanswered as $item) {
            if (isset(self::RECONCILE[$item['kind']]) && ($item['requestedAt'] ?? '') !== '' && $item['requestedAt'] <= $limit) {
                return $item;
            }
        }
        return null;
    }

    /**
     * @param list<array{id: string, displayName: string, capability: string}> $operators
     * @return array{id: string, displayName: string}|null the operator with the fewest pending orders
     */
    private function leastLoadedOperator(array $operators): ?array
    {
        $best = null;
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ? AND status = 'pending'");
        foreach ($operators as $operator) {
            if ($operator['capability'] !== 'operate') {
                continue;
            }
            $count->execute([$operator['id']]);
            $load = (int) $count->fetchColumn();
            if ($best === null || $load < $best[1]) {
                $best = [['id' => $operator['id'], 'displayName' => $operator['displayName']], $load];
            }
        }
        return $best[0] ?? null;
    }

    /**
     * @param list<array{id: string, name: string}> $candidates
     * @return array{id: string, name: string}|null the shop employee with the fewest open routed orders
     */
    private function leastLoadedShop(array $candidates): ?array
    {
        $best = null;
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM order_shop_assignments WHERE employee_uuid = ? AND active_order_uuid IS NOT NULL');
        foreach ($candidates as $candidate) {
            $count->execute([$candidate['id']]);
            $load = (int) $count->fetchColumn();
            if ($best === null || $load < $best[1]) {
                $best = [$candidate, $load];
            }
        }
        return $best[0] ?? null;
    }

    private function globalId(string $value): string
    {
        $value = rawurldecode($value);
        if (preg_match('/^(trendhome|outletperdele|trendyol|b2b):[0-9A-Za-z-]{1,40}$/D', $value) !== 1) {
            throw new ApiException(404, 'RESOLVER_NOT_FOUND', 'No order with this number was found.');
        }
        return $value;
    }

    /** @param array<string, string> $result */
    private function record(EmployeeIdentity $actor, string $globalId, string $action, string $problem, array $result, string $requestId): void
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->audit->record($actor, 'intervention.resolve', 'order', $globalId, $globalId, ['fix' => $action, 'problem' => $problem, 'actingAs' => $actor->isRoot ? 'self' : 'root'] + $result, $requestId, $now);
    }

    /** @param list<mixed> $parts */
    private function fingerprint(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function iso(mixed $utc): ?string
    {
        return $utc === null ? null : (new \DateTimeImmutable((string) $utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
