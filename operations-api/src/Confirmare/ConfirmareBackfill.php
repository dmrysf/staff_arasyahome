<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Integration\SourceAccess\SourceAccessClient;
use Arasya\Operations\Integration\SourceAccess\SourceAccessException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
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
 * 2026-10-22 00:00 Europe/Bucharest (WorkflowCohort::cutover) but before the source's first Confirmare activation are
 * not Confirmare orders: created before migration 035 they have no cohort at all (NULL), created after it and before
 * the activation they are `legacy` (not_activated), and a late event of an order submitted before the activation is
 * `legacy` (submitted_before_activation). Their instant is the shop's submission evidence, or the instant Arasya first
 * received the order when the shop never sent one (orders reach Arasya within minutes through the outbox). The owner's
 * newer decision takes them into Confirmare, without touching production that already started:
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
 * - payment_unknown:    the shop has not sent the order's payment facts yet (an order last sent by an older shop
 *                 connector): unchanged. The shop's backfill (`wp yd-arasya backfill --apply`) sends every order of the
 *                 window again with its current facts; run this again afterwards.
 * - unverified_payment: the payment is `unverified` (a TBI credit, whose financing flag an order without a cohort never
 *                 recorded, or a card payment without the gateway's evidence): unchanged, established flow, listed for
 *                 a person to check. Never guessed into Confirmare.
 *
 * API 2.48.0 (owner decision 2026-10-27, YD SOFT 2.22.0): the shop takes part in every adoption, so an order is never a
 * Confirmare order in Arasya while the shop still treats it as an order of the established flow. For an order Arasya
 * would take, the shop is asked first, signed with the source access key (SourceAccessClient::ROUTE_ADOPT, role
 * `backfill`): in a dry run it only answers whether it would adopt the order; with --apply it checks the order again and
 * records the adoption (its Confirmare fence then protects the order like a new Confirmare order). Arasya takes the order
 * only after the shop's signed `adopted`, inside the transaction that holds the order's row lock:
 * - shop_refused:     the shop's own checks refused it (its window, status, payment, an existing SmartBill document or
 *                     AWB, a refund or finance record): unchanged on both sides, the reasons listed;
 * - shop_unavailable: no source access for the shop, or no trusted answer: unchanged in Arasya. When the shop recorded
 *                     the adoption and the answer was lost, running the backfill again finds the order adopted (the shop
 *                     answers an adopted order with its record) and takes it; until then the shop only protects it more.
 * The window may end earlier than the activation (`until`, the owner's 27 October 00:00 Europe/Bucharest); the shop
 * applies its own fixed window as well. Without a shop client (the 2.46.0 constructor) nothing is ever taken.
 *
 * Read-only by default (`--apply` writes). Idempotent: an order taken once has its Confirmare row and is never selected
 * again; a crash between orders is resumed by running it again. Each order is one transaction under its row lock, and
 * the order is re-checked under that lock.
 */
final readonly class ConfirmareBackfill
{
    public const CATEGORIES = ['taken', 'in_production', 'closed', 'financing', 'payment_unknown', 'unverified_payment', 'shop_refused', 'shop_unavailable'];

    public function __construct(
        private PDO $pdo,
        private Clock $clock,
        private ?SourceAccessRegistry $registry = null,
        private ?SourceAccessClient $client = null,
        /** The employee the shop records as the actor of the adoption (the operator who runs the backfill). */
        private ?string $actorEmployeeUuid = null,
        /** Pause between two shop requests (the shop's source access accepts 240 requests a minute, shared with Staff). */
        private int $paceMicroseconds = 0,
    ) {
    }

    /** @return array{source: string, window: array{from: string, to: ?string}, counts: array<string, int>, applied: int, orders: array<string, list<string>>, refusals: array<string, list<string>>} */
    public function run(string $source, bool $apply, int $limit = 500, ?DateTimeImmutable $until = null): array
    {
        $activation = WorkflowCohort::firstActivation($this->pdo, $source);
        $from = WorkflowCohort::cutover();
        $to = $activation === null ? null : ($until !== null && $until < $activation ? $until : $activation);
        $out = ['source' => $source, 'window' => ['from' => $from->format('Y-m-d\TH:i:s\Z'), 'to' => $to?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')],
            'counts' => array_fill_keys(self::CATEGORIES, 0), 'applied' => 0, 'orders' => array_fill_keys(self::CATEGORIES, []), 'refusals' => []];
        if ($to === null) {
            // Not activated: every order is still legacy by rule; nothing to take.
            return $out;
        }
        $statement = $this->pdo->prepare("SELECT o.order_uuid, o.order_number FROM operational_orders o LEFT JOIN order_confirmations c ON c.order_uuid = o.order_uuid
            WHERE o.source_key = ? AND c.order_uuid IS NULL AND (o.workflow_cohort IS NULL OR (o.workflow_cohort = 'legacy' AND o.workflow_cohort_reason IN (?, ?, ?)))
              AND COALESCE(o.source_submitted_at, o.created_at) >= ? AND COALESCE(o.source_submitted_at, o.created_at) < ?
            ORDER BY COALESCE(o.source_submitted_at, o.created_at), o.order_uuid LIMIT " . max(1, min(5000, $limit)));
        $statement->execute([$source, WorkflowCohort::REASON_NOT_ACTIVATED, WorkflowCohort::REASON_BEFORE_ACTIVATION, WorkflowCohort::REASON_FINANCING, $from->format('Y-m-d H:i:s.u'), $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $reasons = [];
            $category = $apply ? $this->take((string) $candidate['order_uuid'], $reasons) : $this->preview((string) $candidate['order_uuid'], $reasons);
            if ($category === null) {
                continue;
            }
            $out['counts'][$category]++;
            $out['orders'][$category][] = (string) $candidate['order_number'];
            if ($reasons !== []) {
                $out['refusals'][(string) $candidate['order_number']] = $reasons;
            }
            if ($apply && $category === 'taken') {
                $out['applied']++;
            }
        }
        return $out;
    }

    /** Dry run: Arasya's category and, for an order Arasya would take, the shop's answer (nothing written anywhere). */
    private function preview(string $orderUuid, array &$reasons): ?string
    {
        $order = $this->order($orderUuid, false);
        $category = $this->category($order);
        if ($category !== 'taken' || $order === null) {
            return $category;
        }
        return $this->askShop($order, true, $reasons);
    }

    /** One order, under its row lock. @return string|null the category, null when it was taken meanwhile */
    private function take(string $orderUuid, array &$reasons): ?string
    {
        $this->pdo->beginTransaction();
        try {
            $order = $this->order($orderUuid, true);
            if ($order === null || $order['confirmation'] !== null || !in_array($order['workflow_cohort'], [null, WorkflowCohort::LEGACY], true)) {
                $this->pdo->rollBack();
                return null;
            }
            $category = $this->category($order);
            if ($category === 'taken') {
                // The shop adopts first (protection before exposure); Arasya takes the order only on its signed `adopted`.
                $category = $this->askShop($order, false, $reasons);
            }
            if ($category !== 'taken') {
                $this->pdo->rollBack();
                return $category;
            }
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $this->pdo->prepare("UPDATE operational_orders SET workflow_cohort = 'confirmare', workflow_cohort_reason = ?, workflow_cohort_at = ? WHERE order_uuid = ? AND (workflow_cohort IS NULL OR workflow_cohort = 'legacy')")
                ->execute([WorkflowCohort::REASON_BACKFILL, $now, $orderUuid]);
            $this->pdo->prepare("INSERT INTO order_confirmations (order_uuid, global_order_id, source_key, status, version, opened_at, updated_at) VALUES (?, ?, ?, 'pending', 1, ?, ?)")
                ->execute([$orderUuid, $order['global_order_id'], $order['source_key'], $now, $now]);
            $this->pdo->prepare("INSERT INTO order_confirmation_events (event_uuid, order_uuid, global_order_id, source_key, action, actor_kind, actor_employee_uuid, previous_status, new_status,
                    previous_assignee_uuid, new_assignee_uuid, reason_code, version_after, request_id, idempotency_key, occurred_at)
                VALUES (?, ?, ?, ?, 'opened', 'system', NULL, NULL, 'pending', NULL, NULL, 'backfill', 1, ?, NULL, ?)")
                ->execute([Uuid::v4(), $orderUuid, $order['global_order_id'], $order['source_key'], $order['adoption_request_id'] ?? null, $now]);
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

    /**
     * The shop's signed answer for an order Arasya would take: `taken` when it adopts (or, in a dry run, would adopt) it.
     *
     * @param array<string, mixed> $order
     * @param list<string> $reasons the shop's reasons of a refusal (filled)
     */
    private function askShop(array &$order, bool $dryRun, array &$reasons): string
    {
        $target = $this->registry?->find((string) $order['source_key']);
        if ($target === null || $this->client === null || $this->actorEmployeeUuid === null || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $this->actorEmployeeUuid) !== 1) {
            $reasons = ['source_access_not_configured'];
            return 'shop_unavailable';
        }
        if ($this->paceMicroseconds > 0) {
            usleep($this->paceMicroseconds);
        }
        $requestId = 'backfill:' . substr(str_replace('-', '', Uuid::v4()), 0, 24);
        try {
            ['status' => $status, 'body' => $body] = $this->client->exchange($target, SourceAccessClient::ROUTE_ADOPT, [
                'orderId' => (string) $order['source_order_id'],
                'orderNumber' => (string) $order['order_number'],
                'actor' => ['employeeId' => $this->actorEmployeeUuid, 'role' => 'backfill'],
                'requestId' => $requestId,
                'globalOrderId' => (string) $order['global_order_id'],
                'dryRun' => $dryRun,
            ]);
        } catch (SourceAccessException $e) {
            $reasons = [strtolower($e->codeName)];
            return 'shop_unavailable';
        }
        $list = static fn (mixed $value): array => is_array($value) && array_is_list($value) ? array_values(array_filter($value, static fn (mixed $r): bool => is_string($r) && preg_match('/^[a-z_]{1,40}$/D', $r) === 1)) : [];
        if ($status === 409 && ($body['error']['code'] ?? null) === 'not_adoptable') {
            $reasons = $list($body['error']['reasons'] ?? null) ?: ['not_adoptable'];
            return 'shop_refused';
        }
        if ($status !== 200 || ($body['globalOrderId'] ?? null) !== $order['global_order_id'] || ($body['dryRun'] ?? null) !== $dryRun || !is_bool($body['adoptable'] ?? null) || !is_bool($body['adopted'] ?? null)) {
            $reasons = [$status === 200 ? 'answer_invalid' : 'shop_' . (is_string($body['error']['code'] ?? null) ? preg_replace('/[^a-z_]/', '', $body['error']['code']) : 'error')];
            return 'shop_unavailable';
        }
        if (!$body['adoptable']) {
            $reasons = $list($body['reasons'] ?? null) ?: ['not_adoptable'];
            return 'shop_refused';
        }
        if (!$dryRun && $body['adopted'] !== true) {
            $reasons = ['answer_invalid'];
            return 'shop_unavailable';
        }
        $order['adoption_request_id'] = $requestId;
        return 'taken';
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
        if ($order['payment_method'] === null) {
            return 'payment_unknown';
        }
        if ($order['payment_method'] === 'unverified') {
            return 'unverified_payment';
        }
        return 'taken';
    }

    /** @return array<string, mixed>|null */
    private function order(string $orderUuid, bool $lock): ?array
    {
        $statement = $this->pdo->prepare('SELECT o.order_uuid, o.global_order_id, o.source_key, o.source_order_id, o.order_number, o.workflow_cohort, o.workflow_cohort_reason, o.operational_status, o.cancellation_state, o.production_completed_at,
                o.production_stage_id, o.document_status, o.payment_method, (SELECT c.order_uuid FROM order_confirmations c WHERE c.order_uuid = o.order_uuid) AS confirmation
            FROM operational_orders o WHERE o.order_uuid = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
