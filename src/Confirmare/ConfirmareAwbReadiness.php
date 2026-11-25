<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Production\WorkflowCohort;
use Arasya\Operations\Support\Clock;
use PDO;
use PDOException;
use Throwable;

/**
 * AWB readiness (API 2.77.0, owner 2026-11-19): every approved Confirmare order of Trend Home and Outlet Perdele at an AWB
 * stage (12 Control calitate, 13 Împachetare, 14 Livrare, also once stage 14 is completed) whose FAN Courier AWB is not
 * made yet is prepared so that its shop employee can make the AWB:
 *
 * 1. routing: nobody routed the order to a shop employee -> the system routes it (ConfirmareInvoice::autoRoute, the same
 *    rules as an operator's routing);
 * 2. final invoice: the shop has no final invoice -> it is issued in the name of the routed employee through the normal
 *    invoice action (every Arasya and shop check applies; one document; idempotent key `auto-awb-issue-<order>`);
 *    an invoice action whose answer was lost is settled with the shop's stored answer;
 * 3. AWB: an AWB request whose answer was lost is settled with the shop's stored answer.
 *
 * The AWB itself is never made here: the parcel count, the weight and the collection the employee sees stay theirs.
 * Nothing is done for an order that is cancelled, held, unavailable or not approved. A SmartBill outcome the shop itself
 * does not know is never retried (a manager checks SmartBill), exactly as for an employee.
 *
 * Run by bin/confirmare-awb-readiness.php (dry run unless --apply) and by the Confirmare cycle worker every 5 minutes.
 */
final class ConfirmareAwbReadiness
{
    public const SOURCES = ['trendhome', 'outletperdele'];
    /** A shop read of an order already found ready or blocked is repeated at most this often by the cycle worker. */
    public const REREAD_SECONDS = 3600;
    public const REQUEST_PREFIX = 'system-awb-readiness';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly ConfirmareInvoice $invoice,
        private readonly ConfirmareOperations $operations,
        private readonly PdoEmployeeRepository $employees,
    ) {
    }

    /**
     * The orders waiting for their AWB (stage 12-14, approved, active, no applied AWB request), oldest stage change first.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(?string $source = null, int $limit = 300): array
    {
        $sources = $source === null ? self::SOURCES : array_values(array_intersect(self::SOURCES, [$source]));
        if ($sources === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($sources), '?'));
        $stages = implode(', ', array_fill(0, count(ConfirmareOperations::AWB_STAGES), '?'));
        $statement = $this->pdo->prepare("SELECT o.order_uuid, o.global_order_id, o.order_number, o.source_key, o.production_stage_id, o.production_completed_at
            FROM operational_orders o INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid AND c.status = 'approved'
            WHERE o.workflow_cohort = ? AND o.source_key IN ({$marks}) AND o.production_stage_id IN ({$stages})
              AND o.operational_status <> 'unavailable' AND o.cancellation_state IS NULL AND o.source_reported_unavailable_at IS NULL
              AND NOT " . ConfirmareOperations::awbDoneSql('o') . "
            ORDER BY o.production_changed_at, o.order_uuid LIMIT " . max(1, min(1000, $limit)));
        $statement->execute([WorkflowCohort::CONFIRMARE, ...$sources, ...ConfirmareOperations::AWB_STAGES]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Prepares the waiting orders. `$apply` false: reports what would be done (reads the shop, writes nothing but the read
     * audit). `$recent` true (the cycle worker): an order the system read within REREAD_SECONDS and found nothing to do
     * for is skipped, so the shop and the employee's rate limit are not asked every five minutes.
     *
     * @return list<array<string, mixed>> one report per order: number, source, stage, steps done, state, blockers
     */
    public function run(bool $apply, ?string $source = null, int $limit = 300, bool $recent = false): array
    {
        $reports = [];
        foreach ($this->candidates($source, $limit) as $order) {
            try {
                $reports[] = $this->prepare($order, $apply, $recent);
            } catch (Throwable $e) {
                $reports[] = self::report($order, [], 'error', [$e instanceof ApiException ? strtolower($e->errorCode) : 'internal_error']);
            }
        }
        return $reports;
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function prepare(array $order, bool $apply, bool $recent): array
    {
        $orderUuid = (string) $order['order_uuid'];
        $globalId = (string) $order['global_order_id'];
        $steps = [];
        $requestId = self::REQUEST_PREFIX . '-' . bin2hex(random_bytes(6));

        // 1. Routing.
        $assignee = ConfirmareInvoice::assignee($this->pdo, $orderUuid);
        if ($assignee === null) {
            if (!$apply) {
                return self::report($order, ['route'], 'would_prepare', []);
            }
            $routed = $this->invoice->autoRoute($globalId);
            if ($routed['state'] !== 'routed' && $routed['state'] !== 'already') {
                return self::report($order, $steps, 'blocked', [(string) ($routed['reason'] ?? 'not_routed')]);
            }
            if ($routed['state'] === 'routed') {
                $steps[] = 'routed';
            }
            $assignee = ConfirmareInvoice::assignee($this->pdo, $orderUuid);
            if ($assignee === null) {
                return self::report($order, $steps, 'blocked', ['not_routed']);
            }
        }
        $employee = $this->employees->findByUuid($assignee);
        if ($employee === null) {
            return self::report($order, $steps, 'blocked', ['assignee_missing']);
        }

        // 2. Lost answers: the invoice action and the AWB request are settled with the shop's stored answer.
        foreach ($this->openActions('order_invoice_actions', $orderUuid) as $open) {
            if (!$apply) {
                return self::report($order, ['reconcile_invoice'], 'would_prepare', []);
            }
            $actor = $this->employees->findByUuid((string) $open['employee_uuid']) ?? $employee;
            try {
                $this->invoice->reconcile($actor, $globalId, (string) $open['idempotency_key'], $requestId);
                $steps[] = 'invoice_reconciled';
            } catch (ApiException $e) {
                return self::report($order, $steps, 'blocked', ['invoice_' . strtolower($e->errorCode)]);
            }
        }
        foreach ($this->openActions('order_source_operations', $orderUuid, 'awb_create') as $open) {
            if (!$apply) {
                return self::report($order, ['reconcile_awb'], 'would_prepare', []);
            }
            $actor = $this->employees->findByUuid((string) $open['employee_uuid']) ?? $employee;
            try {
                $settled = $this->operations->reconcile($actor, $globalId, (string) $open['idempotency_key'], $requestId);
                $steps[] = 'awb_reconciled';
                if (($settled['state'] ?? null) === 'applied') {
                    return self::report($order, $steps, 'awb_created', []);
                }
            } catch (ApiException $e) {
                if ($e->errorCode !== 'CONFIRMARE_OPERATION_REFUSED') {
                    return self::report($order, $steps, 'blocked', ['awb_' . strtolower($e->errorCode)]);
                }
                $steps[] = 'awb_reconciled';
            }
        }

        // 3. The final invoice (the shop is the authority: one issued in SmartBill directly counts too).
        if ($steps === [] && $recent && $this->recentlyRead($orderUuid)) {
            return self::report($order, $steps, 'unchanged', []);
        }
        $state = $this->invoice->read($employee, $globalId, $requestId);
        if (($state['available'] ?? false) !== true) {
            return self::report($order, $steps, 'blocked', ['invoice_' . (string) ($state['reason'] ?? 'unavailable')]);
        }
        $document = $state['summary']['document'] ?? [];
        if (($document['exists'] ?? false) === true && ($document['status'] ?? null) === 'final') {
            return self::report($order, $steps, 'ready', []);
        }
        if (!in_array('issue', (array) ($state['allowedActions'] ?? []), true)) {
            $blockers = array_values(array_unique([...(array) ($state['blockers']['issue'] ?? []), ...(array) ($state['summary']['blockers']['issue'] ?? [])]));
            return self::report($order, $steps, 'blocked', $blockers === [] ? ['invoice_not_allowed'] : $blockers);
        }
        if (!$apply) {
            return self::report($order, [...$steps, 'issue_invoice'], 'would_prepare', []);
        }
        try {
            $issued = $this->invoice->act($employee, $globalId, 'issue', ['expectedRevision' => (string) $state['summary']['revision']], 'auto-awb-issue-' . $orderUuid, $requestId);
        } catch (ApiException $e) {
            $reason = $e->details['reason'] ?? null;
            return self::report($order, $steps, 'blocked', [is_string($reason) ? $reason : strtolower($e->errorCode)]);
        }
        if (($issued['state'] ?? null) === 'applied' && ($issued['result'] ?? null) === 'issued') {
            return self::report($order, [...$steps, 'invoice_issued'], 'ready', []);
        }
        return self::report($order, [...$steps, 'invoice_requested'], 'blocked', ['invoice_' . (string) ($issued['result'] ?? $issued['state'] ?? 'unknown')]);
    }

    /** @return list<array<string, mixed>> the order's unsettled actions (pending or unknown) old enough to be asked again */
    private function openActions(string $table, string $orderUuid, ?string $kind = null): array
    {
        $before = $this->clock->now()->modify('-' . ConfirmareInvoice::RECONCILE_AFTER_SECONDS . ' seconds')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        try {
            $statement = $this->pdo->prepare("SELECT employee_uuid, idempotency_key FROM {$table} WHERE order_uuid = ? AND state IN ('pending', 'unknown') AND requested_at < ?"
                . ($kind === null ? " AND action IN ('issue', 'reissue')" : ' AND kind = ?') . ' ORDER BY requested_at');
            $statement->execute($kind === null ? [$orderUuid, $before] : [$orderUuid, $before, $kind]);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return [];
        }
    }

    private function recentlyRead(string $orderUuid): bool
    {
        $since = $this->clock->now()->modify('-' . self::REREAD_SECONDS . ' seconds')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        try {
            $statement = $this->pdo->prepare('SELECT 1 FROM order_invoice_reads WHERE order_uuid = ? AND request_id LIKE ? AND read_at >= ? LIMIT 1');
            $statement->execute([$orderUuid, self::REQUEST_PREFIX . '-%', $since]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $order
     * @param list<string> $steps
     * @param list<string> $blockers
     * @return array<string, mixed>
     */
    private static function report(array $order, array $steps, string $state, array $blockers): array
    {
        return [
            'orderNumber' => (string) $order['order_number'],
            'source' => (string) $order['source_key'],
            'stage' => (string) $order['production_stage_id'],
            'completed' => $order['production_completed_at'] !== null,
            'steps' => $steps,
            'state' => $state,
            'blockers' => array_values($blockers),
        ];
    }
}
