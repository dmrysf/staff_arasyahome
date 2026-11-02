<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Production\WorkflowCohort;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * The 1 November cutoff (owner decision 2026-11-02, API 2.55.0): only Trend Home and Outlet Perdele orders submitted on
 * or after 2026-11-01 00:00 Europe/Bucharest are worked in Staff (operator, shop employee, production). An older
 * Confirmare order that nobody has worked yet leaves Confirmare and returns to the established WordPress-managed flow;
 * an older order already worked keeps everything it has.
 *
 * - withdrawn: cohort `confirmare`, confirmation still `pending`, at the initial stage, never cleared, and no work of any
 *              kind recorded: no proforma cycle, invoice action, finance action or request, shop routing, shop edit, shop
 *              operation (AWB), reactivation, e-Factura task or production document. Its cohort becomes `legacy` (reason
 *              withdrawn_before_cutoff, as every pre-activation order), its confirmation `cancelled` without an
 *              assignee, its open alerts are resolved and one system `released` event (reason withdrawn_before_cutoff)
 *              records it. Nothing is sent to the shop, nothing is deleted. A recorded contact attempt is not work that
 *              keeps an order (owner: "only 1 November and later").
 * - kept:      approved, rejected or cancelled already, past the initial stage, cleared, or any recorded work above.
 *
 * Every order is decided again under its own row lock in its own transaction, so an order an operator acts on at the
 * same moment is either withdrawn before or kept after. Read-only unless $apply. Order numbers and counts only.
 */
final class ConfirmareCutoff
{
    /** 2026-11-01 00:00 Europe/Bucharest (EET, UTC+2). */
    public const CUTOFF_UTC = '2026-10-31T22:00:00Z';
    public const REASON = WorkflowCohort::REASON_WITHDRAWN;
    public const SOURCES = ['trendhome', 'outletperdele'];

    /** Work that keeps an older order in Confirmare (one EXISTS per table, all keyed by order_uuid). */
    private const WORK = [
        'cycle' => 'confirmare_proforma_cycles',
        'invoice' => 'order_invoice_actions',
        'finance' => 'order_finance_actions',
        'request' => 'finance_verification_requests',
        'routed' => 'order_shop_assignments',
        'edit' => 'order_confirmation_source_edits',
        'operation' => 'order_source_operations',
        'reactivation' => 'confirmare_reactivations',
        'efactura' => 'invoice_efactura_tasks',
        'document' => 'production_document_revisions',
    ];

    public function __construct(private readonly PDO $pdo, private readonly Clock $clock)
    {
    }

    /** @return array<string, mixed> */
    public function run(string $source, bool $apply, DateTimeImmutable $cutoff): array
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('The cutoff applies to trendhome and outletperdele only.');
        }
        $at = $cutoff->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare("SELECT o.order_uuid FROM operational_orders o INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid
            WHERE o.source_key = ? AND o.workflow_cohort = 'confirmare' AND COALESCE(o.source_submitted_at, o.created_at) < ? ORDER BY COALESCE(o.source_submitted_at, o.created_at), o.order_uuid");
        $statement->execute([$source, $at]);
        $out = ['source' => $source, 'cutoff' => $cutoff->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'withdrawn' => [], 'kept' => []];
        foreach (array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)) as $orderUuid) {
            [$number, $keep] = $this->decide($orderUuid, $apply, $at);
            if ($number === null) {
                continue;
            }
            if ($keep === []) {
                $out['withdrawn'][] = $number;
            } else {
                $out['kept'][] = ['order' => $number, 'because' => $keep];
            }
        }
        $out['counts'] = ['withdrawn' => count($out['withdrawn']), 'kept' => count($out['kept'])];
        return $out;
    }

    /** @return array{0: ?string, 1: list<string>} the order number and why it is kept (empty: withdrawn) */
    private function decide(string $orderUuid, bool $apply, string $cutoff): array
    {
        $this->pdo->beginTransaction();
        try {
            $exists = implode(', ', array_map(static fn (string $key, string $table): string => "EXISTS (SELECT 1 FROM {$table} x WHERE x.order_uuid = o.order_uuid) AS {$key}", array_keys(self::WORK), self::WORK));
            $statement = $this->pdo->prepare("SELECT o.order_number, o.global_order_id, o.source_key, o.workflow_cohort, o.production_stage_id, COALESCE(o.source_submitted_at, o.created_at) AS submitted,
                    c.status, c.version, c.production_cleared, c.assigned_employee_uuid, {$exists}
                FROM operational_orders o INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid WHERE o.order_uuid = ? FOR UPDATE");
            $statement->execute([$orderUuid]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['workflow_cohort'] !== WorkflowCohort::CONFIRMARE || (string) $row['submitted'] >= $cutoff) {
                $this->pdo->rollBack();
                return [null, []];
            }
            $keep = array_keys(array_filter(array_intersect_key($row, self::WORK), static fn (mixed $value): bool => (int) $value === 1));
            if ($row['status'] !== 'pending') {
                array_unshift($keep, 'status_' . $row['status']);
            }
            if ((int) $row['production_cleared'] === 1) {
                $keep[] = 'cleared';
            }
            if ($row['production_stage_id'] !== 'waiting') {
                $keep[] = 'stage';
            }
            if ($keep !== [] || !$apply) {
                $this->pdo->rollBack();
                return [(string) $row['order_number'], $keep];
            }
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $this->pdo->prepare("UPDATE operational_orders SET workflow_cohort = 'legacy', workflow_cohort_reason = ?, workflow_cohort_at = ? WHERE order_uuid = ? AND workflow_cohort = 'confirmare'")
                ->execute([self::REASON, $now, $orderUuid]);
            $version = (int) $row['version'] + 1;
            $this->pdo->prepare("UPDATE order_confirmations SET status = 'cancelled', version = ?, decided_at = ?, decided_by_employee_uuid = NULL,
                    assigned_employee_uuid = NULL, assigned_at = NULL, assignment_kind = NULL, updated_at = ? WHERE order_uuid = ? AND status = 'pending'")
                ->execute([$version, $now, $now, $orderUuid]);
            $this->pdo->prepare("INSERT INTO order_confirmation_events (event_uuid, order_uuid, global_order_id, source_key, action, actor_kind, actor_employee_uuid, previous_status, new_status,
                    previous_assignee_uuid, new_assignee_uuid, reason_code, version_after, request_id, idempotency_key, occurred_at)
                VALUES (?, ?, ?, ?, 'released', 'system', NULL, 'pending', 'cancelled', ?, NULL, ?, ?, NULL, NULL, ?)")
                ->execute([Uuid::v4(), $orderUuid, $row['global_order_id'], $row['source_key'], $row['assigned_employee_uuid'], self::REASON, $version, $now]);
            $this->pdo->prepare('UPDATE confirmare_alerts SET resolved_at = ? WHERE order_uuid = ? AND resolved_at IS NULL')->execute([$now, $orderUuid]);
            $this->pdo->commit();
            return [(string) $row['order_number'], []];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
