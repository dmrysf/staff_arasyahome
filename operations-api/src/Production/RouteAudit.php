<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use PDO;
use PDOException;

/**
 * Read-only readiness audit of open production orders for the per-piece model (API 2.33.0). Self-contained SQL: it runs
 * before and after migrations 028/029/030 and writes nothing.
 *
 * Every open order gets exactly one primary class (the first that applies):
 *
 *   unresolved_exception             an open production exception; resolve it through its own workflow first
 *   conflicting_data                 the stored facts disagree (stage unknown to the workflow, a stale plan, a piece flagged
 *                                    for identity review, a stage past cutting without any recorded cutting completion,
 *                                    a line without a positive quantity)
 *   requires_stage_evidence          the order is past cutting: pieces materialized now start their ledger later than
 *                                    cutting, so packing needs verified historical evidence per piece (RouteReconciliation).
 *                                    evidenceAvailable tells whether every applicable earlier stage has an order-level
 *                                    completion event a verifier can review; missingEvidence lists the stages without one
 *   requires_plan_confirmation       not past cutting, at least one line has no confirmed plan (piece count, type)
 *   safe_identity_materialization    not past cutting and every line has a confirmed plan: its pieces get a complete
 *                                    ledger from cutting on, nothing historical is needed
 *
 * Order-level history is never treated as per-piece evidence here; the audit only reports where evidence exists.
 * Route policy: a PERDEA-only order skips stages 6-7 under policy version 1; under version 2 (perdea_assisted_checkpoints
 * applies to the order: global flag, active cohort member or an existing checkpoint, migration 031) those stages are
 * required assisted checkpoints and need evidence like any other stage. routePolicyVersion reports which applied.
 */
final readonly class RouteAudit
{
    private const CUTTING = 'material-preparation';
    private const PERDEA_NOT_APPLICABLE = ['bottom-hem', 'side-hem'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{generatedAt: string, totals: array<string, int>, byStage: array<string, array<string, int>>, orders: list<array<string, mixed>>} */
    public function run(int $limit = 5000): array
    {
        $stages = $this->pdo->query("SELECT s.stage_id, s.ordinal FROM production_stages s INNER JOIN production_workflows w ON w.workflow_id = s.workflow_id WHERE w.status = 'active' ORDER BY s.ordinal")->fetchAll(PDO::FETCH_KEY_PAIR);
        $ordinal = array_map('intval', $stages);
        $order = array_keys($ordinal);
        $cutting = $ordinal[self::CUTTING] ?? null;
        $plans = $this->table('production_line_plan_heads');
        $units = $this->table('production_work_units');
        $orders = $this->pdo->query("SELECT order_uuid, global_order_id, source_key, production_stage_id, open_exception_uuid FROM operational_orders
            WHERE production_completed_at IS NULL AND operational_status <> 'unavailable' ORDER BY created_at LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
        $items = $this->pdo->prepare("SELECT item_uuid, quantity, JSON_UNQUOTE(JSON_EXTRACT(production_context, '$.kind')) AS kind FROM operational_order_items WHERE order_uuid = ?");
        $events = $this->pdo->prepare("SELECT DISTINCT from_stage_id FROM order_activity_events WHERE order_uuid = ? AND action = 'stage_completed'");
        $exceptions = $this->pdo->prepare('SELECT COUNT(*) FROM production_exceptions WHERE order_uuid = ? AND resolved_at IS NULL');
        $head = $plans ? $this->pdo->prepare('SELECT p.piece_count, p.product_type, p.commercial_quantity_snapshot FROM production_line_plan_heads h INNER JOIN production_line_plans p ON p.plan_uuid = h.plan_uuid WHERE h.item_uuid = ?') : null;
        $review = $units ? $this->pdo->prepare("SELECT COUNT(*) FROM production_work_units WHERE order_uuid = ? AND status = 'active' AND review_required = 1") : null;
        $assistedTables = $this->table('production_assisted_checkpoints');
        $assistedGlobal = $assistedTables && (int) $this->pdo->query("SELECT COALESCE(MAX(enabled), 0) FROM production_feature_flags WHERE flag_key = 'perdea_assisted_checkpoints'")->fetchColumn() === 1;
        $assistedOrder = $assistedTables ? $this->pdo->prepare("SELECT (SELECT COUNT(*) FROM production_pilot_cohort WHERE order_uuid = ? AND feature_key = 'perdea_assisted_checkpoints' AND status = 'active') + (SELECT COUNT(*) FROM production_assisted_checkpoints WHERE order_uuid = ?)") : null;
        $result = [];
        $totals = [];
        $byStage = [];
        foreach ($orders as $row) {
            $uuid = (string) $row['order_uuid'];
            $stage = (string) $row['production_stage_id'];
            $items->execute([$uuid]);
            $lines = $items->fetchAll(PDO::FETCH_ASSOC);
            $events->execute([$uuid]);
            $completed = array_flip($events->fetchAll(PDO::FETCH_COLUMN));
            $exceptions->execute([$uuid]);
            $open = (int) $exceptions->fetchColumn();
            $conflicts = [];
            $unconfirmed = 0;
            $unknownType = 0;
            $perdeaOnly = $lines !== [];
            foreach ($lines as $line) {
                if ((int) $line['quantity'] < 1) $conflicts[] = 'line_without_quantity';
                $plan = null;
                if ($head !== null) {
                    $head->execute([$line['item_uuid']]);
                    $plan = $head->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                $type = $plan['product_type'] ?? match ($line['kind']) { 'curtain' => 'perdea', 'drapery' => 'draperii', 'other' => 'other', default => 'unknown' };
                if ($plan === null || $plan['piece_count'] === null) {
                    $unconfirmed++;
                } elseif ((int) $plan['commercial_quantity_snapshot'] !== (int) $line['quantity']) {
                    $conflicts[] = 'stale_plan';
                }
                if ($type === 'unknown') $unknownType++;
                $perdeaOnly = $perdeaOnly && $type === 'perdea';
            }
            if ($review !== null) {
                $review->execute([$uuid]);
                if ((int) $review->fetchColumn() > 0) $conflicts[] = 'piece_identity_review';
            }
            if (!isset($ordinal[$stage])) $conflicts[] = 'stage_not_in_workflow';
            $assisted = $assistedGlobal;
            if (!$assisted && $assistedOrder !== null) {
                $assistedOrder->execute([$uuid, $uuid]);
                $assisted = (int) $assistedOrder->fetchColumn() > 0;
            }
            $pastCutting = $cutting !== null && isset($ordinal[$stage]) && $ordinal[$stage] > $cutting;
            if ($pastCutting && !isset($completed[self::CUTTING])) $conflicts[] = 'past_cutting_without_cutting_completion';
            // Applicable stages from cutting up to (not including) the current stage; without a confirmed PERDEA plan the
            // full route is assumed, which only ever asks for more evidence.
            $needed = [];
            if ($pastCutting) {
                foreach ($order as $id) {
                    if ($ordinal[$id] < $cutting) continue;
                    if ($id === $stage) break;
                    if ($perdeaOnly && !$assisted && in_array($id, self::PERDEA_NOT_APPLICABLE, true)) continue;
                    $needed[] = $id;
                }
            }
            $missing = array_values(array_filter($needed, static fn (string $id): bool => !isset($completed[$id])));
            $class = match (true) {
                $open > 0 || $row['open_exception_uuid'] !== null => 'unresolved_exception',
                $conflicts !== [] => 'conflicting_data',
                $pastCutting => 'requires_stage_evidence',
                $unconfirmed > 0 => 'requires_plan_confirmation',
                default => 'safe_identity_materialization',
            };
            $totals[$class] = ($totals[$class] ?? 0) + 1;
            $byStage[$stage][$class] = ($byStage[$stage][$class] ?? 0) + 1;
            $result[] = [
                'order' => (string) $row['global_order_id'], 'source' => (string) $row['source_key'], 'stage' => $stage, 'class' => $class,
                'lines' => count($lines), 'unconfirmedLines' => $unconfirmed, 'unknownTypeLines' => $unknownType, 'openExceptions' => $open,
                'conflicts' => array_values(array_unique($conflicts)), 'routePolicyVersion' => $assisted ? 2 : 1, 'evidenceAvailable' => $pastCutting ? $missing === [] : null, 'missingEvidence' => $missing,
            ];
        }
        ksort($totals);
        return ['generatedAt' => gmdate('c'), 'schema' => ['plans' => $plans, 'workUnits' => $units, 'reconciliation' => $this->table('production_route_reconciliation_stages'), 'assistedCheckpoints' => $assistedTables],
            'openOrders' => count($result), 'more' => count($orders) === max(1, $limit), 'totals' => $totals, 'byStage' => $byStage, 'orders' => $result];
    }

    private function table(string $name): bool
    {
        try {
            $statement = $this->pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $statement->execute([$name]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException) {
            return false;
        }
    }
}
