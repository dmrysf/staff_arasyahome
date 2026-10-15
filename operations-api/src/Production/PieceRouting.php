<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Applies the central route policy (ProductRoutePolicy) to one stage completion of a carrier: the order (the pieces that
 * travel together) or a track. The same code serves normal production and rework replay, so a PERDEA piece skips
 * stages 6-7 in both and a DRAPERII piece never does.
 *
 *   one route    the carrier moves to the next applicable stage of its pieces; stages in between that apply to none of
 *                them are recorded as stage_not_applicable (never as completions, no time, no owner, no credit)
 *   two routes   (a mixed carrier whose pieces need different next stages, e.g. PERDEA and DRAPERII after stage 5) the
 *                pieces of the longest route keep the carrier; the others leave on a route track that starts, unowned, at
 *                their own next stage. No defect, no exception: a route track is ordinary production of those pieces
 *   unverified   a piece type is unknown, or the order's piece plan is not confirmed, so pieces cannot be routed apart:
 *                the carrier keeps every stage (the safe existing route) and full_route_unverified is recorded
 *
 * Every completion is also written per physical piece (production_unit_stage_log) with the cycle, employee, carrier
 * version and target stage; the packing gate reads that ledger. Under route policy version 2 (AssistedCheckpoints) a
 * PERDEA piece goes through stages 6-7 with its carrier; its auxiliary work there is informational, so no completion row
 * is written for it (assistedUnits), nobody earns piece work for it, and a piece left unmarked is recorded as unmarked. The product types used by a decision are locked in the
 * plan, so a later edit cannot silently re-route pieces already routed. Without the piece_routing feature for the order
 * (global flag or pilot cohort) every carrier keeps the full route, exactly as before; the ledger is still written.
 *
 * Runs inside the caller's transaction after the caller locked the order row (and the track row for a track).
 */
final readonly class PieceRouting
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Decides the route step of a carrier completing $current. Nothing is written.
     *
     * @return array{next: ?ProductionStage, skipped: list<string>, types: list<string>, units: list<array<string, mixed>>, splits: list<array{units: list<array<string, mixed>>, next: ?ProductionStage, skipped: list<string>, types: list<string>}>, unverified: ?string, routing: bool, assisted: bool, assistedUnits: list<string>}
     */
    public function plan(ProductionWorkflow $workflow, string $orderUuid, ?string $trackUuid, string $current, string $now): array
    {
        $full = ProductRoutePolicy::next($workflow, $current, ProductRoutePolicy::DRAPERII);
        // Route policy version 2: PERDEA keeps stages 6-7 (assisted checkpoints), so no type leaves the carrier any more.
        $assisted = (new AssistedCheckpoints($this->pdo))->appliesTo($orderUuid);
        $routing = (new FeatureActivation($this->pdo))->appliesTo(FeatureActivation::PIECE_ROUTING, $orderUuid);
        // Disabling activation stops new cohorts, not the routes of pieces already separated or exempted.
        if (!$routing) {
            $used = $this->pdo->prepare("SELECT 1 FROM production_route_decisions WHERE order_uuid = ? AND kind IN ('route_split', 'stage_not_applicable') LIMIT 1");
            $used->execute([$orderUuid]);
            $routing = $used->fetchColumn() !== false;
        }
        $plans = new ManufacturingPlans($this->pdo);
        $lines = [];
        foreach ($plans->lines($orderUuid) as $line) {
            $lines[(string) $line['itemId']] = $line;
        }
        $piecewise = $trackUuid !== null || array_filter($lines, static fn (array $line): bool => !$line['known']) === [];
        $units = (new WorkUnits($this->pdo))->carrierUnits($orderUuid, $trackUuid);
        foreach ($units as &$unit) {
            $unit['product_type'] = $lines[(string) $unit['item_uuid']]['plan']['productType'] ?? ProductRoutePolicy::UNKNOWN;
        }
        unset($unit);
        // The lines whose production type a decision uses (locked in their plan when the decision is written).
        $typeItems = $piecewise ? array_values(array_unique(array_map(static fn (array $unit): string => (string) $unit['item_uuid'], $units))) : array_keys($lines);
        $result = ['next' => $full['next'], 'skipped' => [], 'types' => [], 'units' => $units, 'splits' => [], 'unverified' => null, 'routing' => $routing, 'typeItems' => $typeItems,
            'assisted' => $assisted, 'assistedUnits' => $assisted ? AssistedCheckpoints::checkpointUnits($workflow, $units, $current) : []];
        if (!$routing) {
            return $result;
        }
        // The types the carrier holds: per piece when its pieces are known, otherwise per line (the order carries them all).
        $types = $piecewise ? array_map(static fn (array $unit): string => (string) $unit['product_type'], $units) : array_map(static fn (array $line): string => (string) $line['plan']['productType'], array_values($lines));
        $types = array_values(array_unique($types));
        $result['types'] = $types;
        if ($types === []) {
            return $result;
        }
        $groups = array_values(ProductRoutePolicy::groups($workflow, $current, $types, $assisted));
        $diverges = ProductRoutePolicy::next($workflow, $current, ProductRoutePolicy::PERDEA, $assisted)['next']?->id !== $full['next']?->id;
        if (count($groups) > 1 && !$piecewise) {
            $result['unverified'] = 'plan_unverified';
            return $result;
        }
        $result['next'] = $groups[0]['next'];
        $result['skipped'] = $groups[0]['skipped'];
        if (in_array(ProductRoutePolicy::UNKNOWN, $types, true) && $diverges) {
            $result['unverified'] = 'classification_unverified';
        }
        if (count($groups) > 1) {
            $stay = $groups[0]['types'];
            $result['units'] = array_values(array_filter($units, static fn (array $unit): bool => in_array($unit['product_type'], $stay, true)));
            foreach (array_slice($groups, 1) as $group) {
                $result['splits'][] = [
                    'units' => array_values(array_filter($units, static fn (array $unit): bool => in_array($unit['product_type'], $group['types'], true))),
                    'next' => $group['next'], 'skipped' => $group['skipped'], 'types' => $group['types'],
                ];
            }
            $result['types'] = $stay;
        }
        return $result;
    }

    /**
     * Writes the consequences of a planned step after the caller moved the carrier itself to $plan['next']: the per-piece
     * ledger, the route decisions and the route tracks of split pieces. @param array<string, mixed> $plan plan() result
     * @param array<string, mixed>|null $parentTrack the locked track row when the carrier is a track
     * @return list<string> new route track ids
     */
    public function apply(ProductionWorkflow $workflow, array $plan, string $orderUuid, ?array $parentTrack, string $current, string $actorUuid, int $versionBefore, string $key, string $requestId, string $now): array
    {
        $trackUuid = $parentTrack === null ? null : (string) $parentTrack['track_uuid'];
        $allUnits = [...$plan['units'], ...array_merge(...array_map(static fn (array $split): array => $split['units'], $plan['splits']) ?: [[]])];
        // The completion of the current stage, for every piece the carrier held; a PERDEA piece at stage 6 / 7 under policy
        // version 2 was not manufactured by the stage employee (its informational status row records what is known).
        $assistedUnits = $plan['assistedUnits'] ?? [];
        (new AssistedCheckpoints($this->pdo))->recordPassed($allUnits, $assistedUnits, $orderUuid, $trackUuid, $current, $now);
        $this->logCompleted(array_values(array_filter($allUnits, static fn (array $unit): bool => !in_array((string) $unit['unit_uuid'], $assistedUnits, true))), $orderUuid, $current, $trackUuid, $actorUuid, $plan['next']?->id, $versionBefore, $now);
        $usedItems = [];
        if ($plan['skipped'] !== []) {
            $decision = $this->decision($orderUuid, $trackUuid, null, 'stage_not_applicable', $current, $plan['next']?->id, $plan['skipped'], $plan['types'], $plan['units'], 'product_route_policy', $actorUuid, $parentTrack, $requestId, $now, $plan['assisted'] ?? false);
            $this->logNotApplicable($plan['units'], $orderUuid, $plan['skipped'], $trackUuid, $decision, $now);
            $usedItems = [...$usedItems, ...$plan['typeItems']];
        }
        if ($plan['unverified'] !== null) {
            $this->decision($orderUuid, $trackUuid, null, 'full_route_unverified', $current, $plan['next']?->id, [], $plan['types'], $plan['units'], $plan['unverified'], $actorUuid, $parentTrack, $requestId, $now, $plan['assisted'] ?? false);
        }
        $created = [];
        foreach ($plan['splits'] as $index => $split) {
            $unitIds = array_map(static fn (array $unit): string => (string) $unit['unit_uuid'], $split['units']);
            $cycle = max(array_map(static fn (array $unit): int => (int) $unit['rework_cycle'], $split['units']));
            $newTrack = (new ProductionTracks($this->pdo))->splitRoute($orderUuid, $parentTrack, $unitIds, $split['next']?->id ?? $current, $cycle, $actorUuid, $key . '.route.' . $index, $requestId, $now);
            // Pieces that travel apart can be told apart physically: each gets its printable label.
            (new WorkUnits($this->pdo))->issueLabels($orderUuid, $unitIds, $actorUuid, $now);
            $decision = $this->decision($orderUuid, $trackUuid, $newTrack, 'route_split', $current, $split['next']?->id, $split['skipped'], $split['types'], $split['units'], 'product_route_policy', $actorUuid, $parentTrack, $requestId, $now, $plan['assisted'] ?? false);
            $this->logNotApplicable($split['units'], $orderUuid, $split['skipped'], $newTrack, $decision, $now);
            $usedItems = [...$usedItems, ...array_column($split['units'], 'item_uuid')];
            $created[] = $newTrack;
        }
        if ($plan['splits'] !== []) {
            $usedItems = [...$usedItems, ...$plan['typeItems']];
        }
        if ($usedItems !== []) {
            (new ManufacturingPlans($this->pdo))->lockTypes($orderUuid, array_values(array_unique(array_map('strval', $usedItems))), $now);
        }
        return $created;
    }

    /** @param list<array<string, mixed>> $units */
    private function logCompleted(array $units, string $orderUuid, string $stageId, ?string $trackUuid, string $actorUuid, ?string $toStage, int $versionBefore, string $now): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO production_unit_stage_log (log_uuid, unit_uuid, order_uuid, rework_cycle, stage_id, outcome, carrier_track_uuid, employee_uuid, to_stage_id, carrier_version_before, route_decision_uuid, occurred_at)
             VALUES (?, ?, ?, ?, ?, 'completed', ?, ?, ?, ?, NULL, ?) ON DUPLICATE KEY UPDATE log_uuid = log_uuid",
        );
        foreach ($units as $unit) {
            $insert->execute([Uuid::v4(), $unit['unit_uuid'], $orderUuid, (int) $unit['rework_cycle'], $stageId, $trackUuid, $actorUuid, $toStage, $versionBefore, $now]);
        }
    }

    /** @param list<array<string, mixed>> $units @param list<string> $stages */
    private function logNotApplicable(array $units, string $orderUuid, array $stages, ?string $trackUuid, string $decision, string $now): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO production_unit_stage_log (log_uuid, unit_uuid, order_uuid, rework_cycle, stage_id, outcome, carrier_track_uuid, employee_uuid, to_stage_id, carrier_version_before, route_decision_uuid, occurred_at)
             VALUES (?, ?, ?, ?, ?, 'not_applicable', ?, NULL, NULL, NULL, ?, ?) ON DUPLICATE KEY UPDATE log_uuid = log_uuid",
        );
        foreach ($units as $unit) {
            foreach ($stages as $stage) {
                $insert->execute([Uuid::v4(), $unit['unit_uuid'], $orderUuid, (int) $unit['rework_cycle'], $stage, $trackUuid, $decision, $now]);
            }
        }
    }

    /**
     * @param list<string> $skipped @param list<string> $types @param list<array<string, mixed>> $units @param array<string, mixed>|null $parentTrack
     */
    private function decision(string $orderUuid, ?string $trackUuid, ?string $newTrack, string $kind, string $from, ?string $to, array $skipped, array $types, array $units, string $reason, string $actorUuid, ?array $parentTrack, string $requestId, string $now, bool $assisted): string
    {
        $uuid = Uuid::v4();
        $cycles = array_map(static fn (array $unit): int => (int) $unit['rework_cycle'], $units);
        $this->pdo->prepare(
            'INSERT INTO production_route_decisions (decision_uuid, order_uuid, track_uuid, new_track_uuid, kind, from_stage_id, to_stage_id, skipped_stages_json, product_types_json, units_json, reason, policy_version, rework_cycle, actor_employee_uuid, request_id, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $uuid, $orderUuid, $trackUuid, $newTrack, $kind, $from, $to, $skipped === [] ? null : json_encode($skipped, JSON_THROW_ON_ERROR), json_encode(array_values($types), JSON_THROW_ON_ERROR),
            $units === [] ? null : json_encode(array_map(static fn (array $unit): string => (string) $unit['unit_uuid'], $units), JSON_THROW_ON_ERROR),
            $reason, ProductRoutePolicy::version($assisted), $cycles === [] ? ($parentTrack === null ? null : (int) $parentTrack['rework_cycle']) : max($cycles), $actorUuid, mb_substr($requestId, 0, 100), $now,
        ]);
        return $uuid;
    }
}
