<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Order\OrderProjectionWriter;
use Arasya\Operations\Production\WorkflowCohort;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * The 22 October backfill (owner decision K-06, 2026-10-25; API 2.46.0): the orders a shop submitted on or after
 * 2026-10-22 00:00 Europe/Bucharest (WorkflowCohort::cutover) but before the source's first Confirmare activation were
 * recorded `legacy` (submitted_before_activation). The owner's newer decision takes them into Confirmare, without
 * touching production that already started:
 *
 * - taken:        still at the initial stage, no production ticket generated, not cancelled, not stopped, not completed:
 *                 the cohort becomes `confirmare` (reason backfill_22_october) and the order opens in Confirmare
 *                 (pending, distributed to an operator like any new order). Its existing proformas, payments, invoices
 *                 and AWB stay in the shop: nothing is created, cancelled or sent here, and no timer starts (a proforma
 *                 Arasya did not send has no cycle: ConfirmareCycles).
 * - in_production: a stage after the initial one, or a production ticket already generated: it keeps its stage, owner
 *                 and documents in the established flow (never stopped by this).
 * - closed:       cancelled, stopped for a cancellation, or completed: unchanged.
 * - financing:    a TBI consumer credit order (owner K-12): unchanged, established flow.
 *
 * Read-only by default (`--apply` writes). Idempotent: an order taken once has its Confirmare row and is never selected
 * again; a crash between orders is resumed by running it again. Each order is one transaction under its row lock, and
 * the order is re-checked under that lock.
 */
final readonly class ConfirmareBackfill
{
    public const CATEGORIES = ['taken', 'in_production', 'closed', 'financing'];

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /** @return array{source: string, window: array{from: string, to: ?string}, counts: array<string, int>, applied: int, orders: array<string, list<string>>} */
    public function run(string $source, bool $apply, int $limit = 500): array
    {
        $activation = WorkflowCohort::firstActivation($this->pdo, $source);
        $from = WorkflowCohort::cutover();
        $out = ['source' => $source, 'window' => ['from' => $from->format('Y-m-d\TH:i:s\Z'), 'to' => $activation?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')],
            'counts' => array_fill_keys(self::CATEGORIES, 0), 'applied' => 0, 'orders' => array_fill_keys(self::CATEGORIES, [])];
        if ($activation === null) {
            // Not activated: every order is still legacy by rule; nothing to take.
            return $out;
        }
        $statement = $this->pdo->prepare("SELECT o.order_uuid, o.order_number FROM operational_orders o LEFT JOIN order_confirmations c ON c.order_uuid = o.order_uuid
            WHERE o.source_key = ? AND o.workflow_cohort = 'legacy' AND o.workflow_cohort_reason IN (?, ?) AND o.source_submitted_at >= ? AND o.source_submitted_at < ? AND c.order_uuid IS NULL
            ORDER BY o.source_submitted_at, o.order_uuid LIMIT " . max(1, min(5000, $limit)));
        $statement->execute([$source, WorkflowCohort::REASON_BEFORE_ACTIVATION, WorkflowCohort::REASON_FINANCING, $from->format('Y-m-d H:i:s.u'), $activation->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $category = $apply ? $this->take((string) $candidate['order_uuid']) : $this->category($this->order((string) $candidate['order_uuid'], false));
            if ($category === null) {
                continue;
            }
            $out['counts'][$category]++;
            $out['orders'][$category][] = (string) $candidate['order_number'];
            if ($apply && $category === 'taken') {
                $out['applied']++;
            }
        }
        return $out;
    }

    /** One order, under its row lock. @return string|null the category, null when it was taken meanwhile */
    private function take(string $orderUuid): ?string
    {
        $this->pdo->beginTransaction();
        try {
            $order = $this->order($orderUuid, true);
            if ($order === null || $order['confirmation'] !== null || $order['workflow_cohort'] !== WorkflowCohort::LEGACY) {
                $this->pdo->rollBack();
                return null;
            }
            $category = $this->category($order);
            if ($category !== 'taken') {
                $this->pdo->rollBack();
                return $category;
            }
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $this->pdo->prepare("UPDATE operational_orders SET workflow_cohort = 'confirmare', workflow_cohort_reason = ?, workflow_cohort_at = ? WHERE order_uuid = ? AND workflow_cohort = 'legacy'")
                ->execute([WorkflowCohort::REASON_BACKFILL, $now, $orderUuid]);
            $this->pdo->prepare("INSERT INTO order_confirmations (order_uuid, global_order_id, source_key, status, version, opened_at, updated_at) VALUES (?, ?, ?, 'pending', 1, ?, ?)")
                ->execute([$orderUuid, $order['global_order_id'], $order['source_key'], $now, $now]);
            $this->pdo->prepare("INSERT INTO order_confirmation_events (event_uuid, order_uuid, global_order_id, source_key, action, actor_kind, actor_employee_uuid, previous_status, new_status,
                    previous_assignee_uuid, new_assignee_uuid, reason_code, version_after, request_id, idempotency_key, occurred_at)
                VALUES (?, ?, ?, ?, 'opened', 'system', NULL, NULL, 'pending', NULL, NULL, 'backfill', 1, NULL, NULL, ?)")
                ->execute([Uuid::v4(), $orderUuid, $order['global_order_id'], $order['source_key'], $now]);
            ConfirmationClearance::refresh($this->pdo, $orderUuid, $now);
            $this->pdo->commit();
            return 'taken';
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string, mixed>|null $order */
    private function category(?array $order): ?string
    {
        if ($order === null) {
            return null;
        }
        if ($order['workflow_cohort_reason'] === WorkflowCohort::REASON_FINANCING) {
            return 'financing';
        }
        if ($order['operational_status'] === 'unavailable' || $order['cancellation_state'] !== null || $order['production_completed_at'] !== null) {
            return 'closed';
        }
        if ($order['production_stage_id'] !== OrderProjectionWriter::INITIAL_STAGE_ID || ($order['document_status'] ?? 'none') !== 'none') {
            return 'in_production';
        }
        return 'taken';
    }

    /** @return array<string, mixed>|null */
    private function order(string $orderUuid, bool $lock): ?array
    {
        $statement = $this->pdo->prepare('SELECT o.order_uuid, o.global_order_id, o.source_key, o.workflow_cohort, o.workflow_cohort_reason, o.operational_status, o.cancellation_state, o.production_completed_at,
                o.production_stage_id, o.document_status, (SELECT c.order_uuid FROM order_confirmations c WHERE c.order_uuid = o.order_uuid) AS confirmation
            FROM operational_orders o WHERE o.order_uuid = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
