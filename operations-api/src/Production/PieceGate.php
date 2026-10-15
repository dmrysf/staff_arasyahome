<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use PDO;
use PDOException;

/**
 * The order-level gate before packing and delivery (API 2.32.0). An order at packing or delivery cannot be claimed or
 * completed while:
 *
 *   pieces_in_rework           a piece is still on a rework track (or any track that went through a defect)
 *   pieces_awaiting_reunion    a piece follows its own product route and has not rejoined the order yet
 *   plan_confirmation_required a line's confirmed plan no longer matches its commercial quantity (the source changed it)
 *   pieces_incomplete          a line with a known plan does not have exactly one active deliverable piece per slot, or a
 *                              piece needs an identity review (a replaced original never counts; its replacement does)
 *   route_incomplete           a piece lacks a completion of a stage that applies to its product type in its current
 *                              production cycle (from cutting onward); a stage recorded as
 *                              not applicable never satisfies an applicable stage. A stage before the start of the
 *                              piece's ledger is satisfied only by verified historical evidence (RouteReconciliation)
 *
 * Lines whose plan was never confirmed keep the order-level behaviour (their progress is the order's). No partial
 * shipment exists. Read-only.
 */
final readonly class PieceGate
{
    public const GATED_STAGES = ['packing', 'delivery'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Blocking reason per order, for the given orders at a gated stage. @param array<string, string> $stageByOrder order uuid => stage
     * @return array<string, string> order uuid => reason (absent: not blocked)
     */
    public function reasons(array $stageByOrder): array
    {
        $gated = array_keys($stageByOrder);
        $reasons = [];
        foreach ($gated as $orderUuid) {
            $reason = $this->carrierReason((string) $orderUuid, null);
            if ($reason === null && in_array($stageByOrder[$orderUuid], self::GATED_STAGES, true)) $reason = $this->reason((string) $orderUuid, $stageByOrder[$orderUuid]);
            if ($reason !== null) {
                $reasons[(string) $orderUuid] = $reason;
            }
        }
        return $reasons;
    }

    /** A hold or changed plan blocks only the carrier that physically carries those pieces. */
    public function carrierReason(string $orderUuid, ?string $track): ?string
    {
        $units = $this->pdo->prepare("SELECT u.item_uuid, u.review_required, c.hold_reason FROM production_work_units u
            LEFT JOIN production_unit_custody c ON c.unit_uuid = u.unit_uuid
            WHERE u.order_uuid = ? AND u.status = 'active' AND u.track_uuid <=> ?");
        $units->execute([$orderUuid, $track]);
        $lines = [];
        foreach ((new ManufacturingPlans($this->pdo))->lines($orderUuid) as $line) $lines[$line['itemId']] = $line;
        foreach ($units->fetchAll(PDO::FETCH_ASSOC) as $unit) {
            if (!isset($lines[$unit['item_uuid']]) || !$lines[$unit['item_uuid']]['known']) return 'plan_confirmation_required';
            if ((int) $unit['review_required'] === 1) return 'pieces_incomplete';
            if ($unit['hold_reason'] !== null) return 'pieces_held';
        }
        return null;
    }

    public function reason(string $orderUuid, string $currentStage): ?string
    {
        try {
            $tracks = $this->pdo->prepare("SELECT origin_exception_uuid, verify_stage_id FROM production_tracks WHERE order_uuid = ? AND status = 'active'");
            $tracks->execute([$orderUuid]);
            $active = $tracks->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            if ($this->pdo->query("SHOW TABLES LIKE 'production_line_plan_heads'")->fetchColumn() !== false) throw $error;
            return null;
        }
        foreach ($active as $track) {
            if ($track['origin_exception_uuid'] !== null || $track['verify_stage_id'] !== null) {
                return 'pieces_in_rework';
            }
        }
        if ($active !== []) {
            return 'pieces_awaiting_reunion';
        }
        try {
            $lines = (new ManufacturingPlans($this->pdo))->lines($orderUuid);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return null;
        }
        $held = $this->pdo->prepare("SELECT 1 FROM production_unit_custody c INNER JOIN production_work_units u ON u.unit_uuid = c.unit_uuid WHERE u.order_uuid = ? AND u.status = 'active' AND c.hold_reason IS NOT NULL LIMIT 1");
        $held->execute([$orderUuid]);
        if ($held->fetchColumn() !== false) return 'pieces_held';
        $pending = $this->pdo->prepare("SELECT 1 FROM production_unit_verifications v INNER JOIN production_work_units u ON u.unit_uuid = v.unit_uuid AND u.rework_cycle = v.rework_cycle WHERE u.order_uuid = ? AND u.status = 'active' AND v.confirmed_at IS NULL LIMIT 1");
        $pending->execute([$orderUuid]);
        if ($pending->fetchColumn() !== false) return 'defect_unresolved';
        $workflow = (new PdoProductionWorkflowRepository($this->pdo))->current();
        $units = $this->pdo->prepare(
            "SELECT u.unit_uuid, u.item_uuid, u.rework_cycle, u.review_required, COALESCE(r.slot_index, u.unit_index) AS slot,
                    (SELECT c.started_stage_id FROM production_unit_cycles c WHERE c.unit_uuid = u.unit_uuid AND c.rework_cycle = u.rework_cycle) AS ledger_start
             FROM production_work_units u LEFT JOIN production_unit_replacements r ON r.replacement_unit_uuid = u.unit_uuid
             WHERE u.order_uuid = ? AND u.status = 'active'",
        );
        $units->execute([$orderUuid]);
        $byItem = [];
        foreach ($units->fetchAll(PDO::FETCH_ASSOC) as $unit) {
            $byItem[(string) $unit['item_uuid']][] = $unit;
        }
        $reconciled = (new RouteReconciliation($this->pdo))->reconciled($orderUuid);
        $log = $this->pdo->prepare('SELECT unit_uuid, rework_cycle, stage_id, outcome FROM production_unit_stage_log WHERE order_uuid = ?');
        $log->execute([$orderUuid]);
        $outcomes = [];
        foreach ($log->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $outcomes[(string) $row['unit_uuid'] . '|' . (int) $row['rework_cycle']][(string) $row['stage_id']] = (string) $row['outcome'];
        }
        foreach ($lines as $line) {
            if ($line['stale']) {
                return 'plan_confirmation_required';
            }
            if (!$line['known']) {
                if ((new FeatureActivation($this->pdo))->appliesTo(FeatureActivation::PARTIAL_REWORK, $orderUuid) || (new FeatureActivation($this->pdo))->appliesTo(FeatureActivation::PIECE_ROUTING, $orderUuid)) return 'plan_confirmation_required';
                continue;
            }
            $count = (int) $line['plan']['pieceCount'];
            $pieces = $byItem[$line['itemId']] ?? [];
            $slots = array_map(static fn (array $unit): int => (int) $unit['slot'], $pieces);
            sort($slots);
            if ($slots !== range(1, $count)) {
                return 'pieces_incomplete';
            }
            if ($workflow === null) {
                return 'route_incomplete';
            }
            $route = ProductRoutePolicy::route($workflow, (string) $line['plan']['productType']);
            foreach ($pieces as $piece) {
                if ((int) $piece['review_required'] === 1) {
                    return 'pieces_incomplete';
                }
                if ($piece['ledger_start'] === null) return 'route_incomplete';
                $recorded = $outcomes[(string) $piece['unit_uuid'] . '|' . (int) $piece['rework_cycle']] ?? [];
                $verified = $reconciled[(string) $piece['unit_uuid'] . '|' . (int) $piece['rework_cycle']] ?? [];
                $inLedger = false;
                $beforeStart = true;
                foreach ($workflow->stages as $stage) {
                    if ($stage->id === $currentStage) {
                        break;
                    }
                    $beforeStart = $beforeStart && $stage->id !== $piece['ledger_start'];
                    // Materializing a historical piece at packing is no evidence that earlier work happened.
                    // Require actual piece/cycle completions from cutting; never fill the gap from aggregate history.
                    // Only a stage before the piece's ledger start may instead carry verified evidence (migration 030).
                    $inLedger = $inLedger || $stage->id === (ProductRoutePolicy::canonical($workflow) ? \Arasya\Operations\Cutting\CuttingLifecycle::STAGE : $workflow->stages[0]->id);
                    if ($inLedger && in_array($stage->id, $route, true) && ($recorded[$stage->id] ?? null) !== 'completed' && !($beforeStart && isset($verified[$stage->id]))) {
                        return 'route_incomplete';
                    }
                }
            }
        }
        return null;
    }
}
