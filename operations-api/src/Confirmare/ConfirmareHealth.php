<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Cancellation\Sync\SyncState;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

/**
 * The operating health of the two shops after the go-live (API 2.46.0, owner master decisions §19): counts per shop,
 * never an order number, a customer, an amount or a document number. For the people who watch the flow: Confirmare
 * supervisors, invoice managers and finance approvers of the shop, and root (break-glass).
 *
 * Each figure is one small indexed count; a table that does not exist yet (an older migration level) reads as null.
 */
final readonly class ConfirmareHealth
{
    public const SOURCES = ['trendhome', 'outletperdele'];
    /** R4 (2026-10-26): an unknown SmartBill outcome older than this is counted as overdue for a manager. */
    public const UNKNOWN_ATTENTION_MINUTES = 30;

    public function __construct(
        private PDO $pdo,
        private Clock $clock,
        private ConfirmareScopePolicy $confirmare,
        private InvoiceScopePolicy $invoices,
        private FinanceScopePolicy $finance,
    ) {
    }

    /** @return array<string, mixed> */
    public function metrics(EmployeeIdentity $actor, ?string $source): array
    {
        $sources = array_values(array_filter(self::SOURCES, fn (string $s): bool => ($source === null || $source === $s) && $this->watches($actor, $s)));
        if ($sources === []) {
            throw new ApiException(403, 'CONFIRMARE_HEALTH_DENIED', 'You do not watch the operating health of this shop.');
        }
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $dayStart = $now->setTimezone(new DateTimeZone('Europe/Bucharest'))->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $out = [];
        foreach ($sources as $s) {
            $lastContact = $this->value('SELECT last_contact_at FROM order_sources WHERE source_key = ?', [$s]);
            $out[$s] = [
                'newOrdersToday' => $this->count('SELECT COUNT(*) FROM operational_orders WHERE source_key = ? AND created_at >= ?', [$s, $dayStart]),
                'secondsSinceLastSourceContact' => is_string($lastContact) ? max(0, $now->getTimestamp() - (new DateTimeImmutable($lastContact, new DateTimeZone('UTC')))->getTimestamp()) : null,
                'confirmare' => [
                    'pending' => $this->count("SELECT COUNT(*) FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE c.source_key = ? AND c.status = 'pending' AND o.operational_status <> 'unavailable'", [$s]),
                    'unassigned' => $this->count("SELECT COUNT(*) FROM order_confirmations c INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE c.source_key = ? AND c.status = 'pending' AND c.assigned_employee_uuid IS NULL AND o.operational_status <> 'unavailable'", [$s]),
                    'neverCalled' => $this->count("SELECT COUNT(*) FROM order_confirmations c WHERE c.source_key = ? AND c.status = 'pending' AND NOT EXISTS (SELECT 1 FROM order_confirmation_contacts k WHERE k.order_uuid = c.order_uuid)", [$s]),
                    'undetermined' => $this->count("SELECT COUNT(*) FROM operational_orders WHERE source_key = ? AND workflow_cohort = 'undetermined'", [$s]),
                ],
                'proformaCycles' => $this->grouped("SELECT state, COUNT(*) FROM confirmare_proforma_cycles WHERE source_key = ? AND active_order_uuid IS NOT NULL GROUP BY state", [$s]),
                'proformaCycleErrors' => $this->count('SELECT COUNT(*) FROM confirmare_proforma_cycles WHERE source_key = ? AND active_order_uuid IS NOT NULL AND last_error IS NOT NULL', [$s]),
                // R4 (2026-10-26): an open cycle whose SmartBill outcome is unknown for more than 30 minutes: a manager checks
                // SmartBill and resolves it (the worker never sends it again).
                'proformaUnknownOverdue' => $this->count("SELECT COUNT(*) FROM confirmare_proforma_cycles WHERE source_key = ? AND active_order_uuid IS NOT NULL
                    AND (state = 'cancel_unknown' OR last_error IN ('issue_unknown', 'mail_unknown', 'step_unknown', 'answer_unreadable')) AND updated_at < ?",
                    [$s, $now->modify('-' . self::UNKNOWN_ATTENTION_MINUTES . ' minutes')->format('Y-m-d H:i:s.u')]),
                // R5 (2026-10-26): cycles closed by a refusal in the last 7 days (no longer open, so not in the counts above).
                'proformaFailed7d' => ($failed7d = $this->grouped("SELECT closed_reason, COUNT(*) FROM confirmare_proforma_cycles WHERE source_key = ? AND active_order_uuid IS NULL AND state = 'closed'
                    AND closed_at >= ? AND closed_reason IN (" . implode(', ', array_fill(0, count(ConfirmareCycles::RETRYABLE_CLOSED), '?')) . ') GROUP BY closed_reason',
                    [$s, $now->modify('-7 days')->format('Y-m-d H:i:s.u'), ...ConfirmareCycles::RETRYABLE_CLOSED])) === null ? null : array_sum($failed7d),
                'proformaFailed7dByReason' => $failed7d,
                'lastBankCheck' => $this->iso($this->value('SELECT MAX(covers_until) FROM confirmare_bank_checks WHERE source_key = ?', [$s])),
                'financeActionsOpen' => $this->count("SELECT COUNT(*) FROM order_finance_actions WHERE source_key = ? AND state IN ('pending', 'unknown')", [$s]),
                'invoiceActionsUnknown' => $this->count("SELECT COUNT(*) FROM order_invoice_actions WHERE source_key = ? AND state = 'unknown'", [$s]),
                'routedNotInvoiced' => $this->count("SELECT COUNT(*) FROM order_shop_assignments a WHERE a.source_key = ? AND a.state = 'active'
                    AND NOT EXISTS (SELECT 1 FROM order_invoice_actions i WHERE i.order_uuid = a.order_uuid AND i.action IN ('issue', 'reissue') AND i.state = 'applied' AND i.result = 'issued')", [$s]),
                'efactura' => [
                    'open' => $this->count("SELECT COUNT(*) FROM invoice_efactura_tasks WHERE source_key = ? AND state IN ('open', 'sent', 'rejected')", [$s]),
                    'overdue' => $this->count("SELECT COUNT(*) FROM invoice_efactura_tasks WHERE source_key = ? AND state IN ('open', 'rejected') AND due_at < ?", [$s, $now->format('Y-m-d H:i:s.u')]),
                ],
                'awbWaiting' => $this->count("SELECT COUNT(*) FROM operational_orders o INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid AND c.status = 'approved'
                    WHERE o.source_key = ? AND o.production_stage_id IN ('quality-control', 'packing', 'delivery') AND o.production_completed_at IS NULL AND o.cancellation_state IS NULL
                    AND NOT EXISTS (SELECT 1 FROM order_source_operations op WHERE op.order_uuid = o.order_uuid AND op.kind = 'awb_create' AND op.state = 'applied')", [$s]),
                'cardRefundsOpen' => $this->count("SELECT COUNT(*) FROM order_source_operations f WHERE f.source_key = ? AND f.kind = 'card_refund_full' AND f.state = 'applied' AND f.result = 'manual_required'
                    AND NOT EXISTS (SELECT 1 FROM order_source_operations v WHERE v.order_uuid = f.order_uuid AND v.kind = 'card_refund_verify' AND v.state = 'applied' AND v.result = 'refund_verified' AND v.requested_at > f.requested_at)", [$s]),
                'operationsUnknown' => $this->count("SELECT COUNT(*) FROM order_source_operations WHERE source_key = ? AND state = 'unknown'", [$s]),
                'cancellationSyncOpen' => $this->count('SELECT COUNT(*) FROM order_cancellation_sync_operations WHERE source_key = ? AND state IN (' . implode(', ', array_fill(0, count(SyncState::OPEN), '?')) . ')', [$s, ...SyncState::OPEN]),
                'productionStopped' => $this->count("SELECT COUNT(*) FROM operational_orders WHERE source_key = ? AND cancellation_state = 'held'", [$s]),
            ];
        }
        return ['generatedAt' => $now->format('Y-m-d\TH:i:s\Z'), 'sources' => $out];
    }

    private function watches(EmployeeIdentity $actor, string $source): bool
    {
        if ($actor->isRoot) {
            return true;
        }
        return $this->confirmare->allows($actor, ConfirmareScopePolicy::SUPERVISE, $source) || $this->invoices->allows($actor, InvoiceScopePolicy::MANAGE, $source)
            || $this->finance->allows($actor, FinanceScopePolicy::APPROVE, $source);
    }

    /** @param list<mixed> $params */
    private function count(string $sql, array $params): ?int
    {
        $value = $this->value($sql, $params);
        return $value === null ? null : (int) $value;
    }

    /** @param list<mixed> $params @return array<string, int>|null */
    private function grouped(string $sql, array $params): ?array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return array_map('intval', $statement->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (PDOException) {
            return null;
        }
    }

    /** @param list<mixed> $params */
    private function value(string $sql, array $params): mixed
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $value = $statement->fetchColumn();
            return $value === false ? null : $value;
        } catch (PDOException) {
            return null;
        }
    }

    private function iso(mixed $sql): ?string
    {
        return is_string($sql) ? (new DateTimeImmutable($sql, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z') : null;
    }
}
