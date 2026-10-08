<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration;

use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Production\CanonicalProductionWorkflowContract;
use Arasya\Operations\Production\ProductionAuthority;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Read-only customer tracking facts for a signed source about its own orders (API 2.21.0).
 *
 * The source shows them to a customer it has already verified itself (WooCommerce order + billing e-mail, a
 * logged-in owner or a WooCommerce order key); Arasya never sees the customer and never receives a credential.
 * The answer is derived from the canonical production state only and is reduced to what a customer timeline
 * needs: the customer milestone, the position in the 14-stage workflow and the time each milestone was reached.
 * It never carries customer data, items, notes, employees, exceptions, documents, QR values or audit payloads,
 * and nothing is written (not even the source contact time).
 *
 * Customer milestones are a presentation of the canonical stage. They are not a second state machine and never
 * change the 14 stages:
 *   received    waiting
 *   materials   material-preparation, workshop-receiving
 *   production  labeling … sewing-finishing (stages 4–11)
 *   quality     quality-control
 *   packing     packing, delivery (internal hand-over stage: the parcel is not with a courier yet)
 *   ready       production completed
 * Shipping (AWB, courier, delivered) is never derived here: the commerce source owns it.
 */
final readonly class SourceTrackingQueries
{
    public const MILESTONES = ['received', 'materials', 'production', 'quality', 'packing', 'ready'];

    /** @var array<string, string> canonical stage => customer milestone */
    public const STAGE_MILESTONES = [
        'waiting' => 'received',
        'material-preparation' => 'materials',
        'workshop-receiving' => 'materials',
        'labeling' => 'production',
        'material-straightening' => 'production',
        'bottom-hem' => 'production',
        'side-hem' => 'production',
        'ironing' => 'production',
        'height' => 'production',
        'header-tape' => 'production',
        'sewing-finishing' => 'production',
        'quality-control' => 'quality',
        'packing' => 'packing',
        'delivery' => 'packing',
    ];

    /** A busy order has a few dozen events; the bound keeps one answer cheap whatever happens to an order. */
    private const MAX_EVENTS = 500;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<string> $sourceOrderIds already validated, unique
     * @return list<array<string, mixed>> in request order
     */
    public function forOrders(SourceDefinition $source, array $sourceOrderIds): array
    {
        $orderStatement = $this->pdo->prepare(
            'SELECT order_uuid, production_authority, production_stage_id, production_completed_at, operational_status, accepted_at, created_at, production_changed_at
             FROM operational_orders WHERE source_key = ? AND global_order_id = ?',
        );
        $eventStatement = $this->pdo->prepare(
            'SELECT action, to_stage_id, occurred_at FROM order_activity_events
             WHERE order_uuid = ? AND (to_stage_id IS NOT NULL OR action = \'production_completed\')
             ORDER BY occurred_at, event_id LIMIT ' . self::MAX_EVENTS,
        );
        $result = [];
        foreach ($sourceOrderIds as $orderId) {
            $globalId = (new GlobalOrderId($source->key, $orderId))->toString();
            $orderStatement->execute([$source->key, $globalId]);
            $order = $orderStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                $result[] = ['orderId' => $orderId, 'globalOrderId' => $globalId, 'exists' => false, 'productionAuthority' => null, 'trackingAuthority' => null, 'tracking' => null];
                continue;
            }
            $authority = (string) $order['production_authority'];
            $arasya = $source->trackingAuthorityMode->isActive() && $authority === ProductionAuthority::OPERATIONS;
            $tracking = null;
            if ($arasya) {
                $eventStatement->execute([(string) $order['order_uuid']]);
                $tracking = self::tracking($order, $eventStatement->fetchAll(PDO::FETCH_ASSOC));
            }
            $result[] = [
                'orderId' => $orderId,
                'globalOrderId' => $globalId,
                'exists' => true,
                'productionAuthority' => $authority,
                'trackingAuthority' => $arasya ? 'arasya' : 'source',
                'tracking' => $tracking,
            ];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $events chronological stage entries
     * @return array<string, mixed>
     */
    public static function tracking(array $order, array $events): array
    {
        $stageId = (string) $order['production_stage_id'];
        $completed = $order['production_completed_at'] !== null;
        $stageNumber = CanonicalProductionWorkflowContract::STAGES[$stageId] ?? null;
        $stageMilestone = self::STAGE_MILESTONES[$stageId] ?? null;
        $state = $order['operational_status'] === 'unavailable' ? 'cancelled' : ($completed ? 'completed' : 'active');
        if ($stageNumber === null || $stageMilestone === null) {
            // Not a stage of curtain-production@1: nothing trustworthy to show (fail closed, never a guess).
            return ['state' => 'unavailable', 'milestone' => null, 'milestoneNumber' => null, 'milestoneCount' => count(self::MILESTONES),
                'stageNumber' => null, 'stageCount' => count(CanonicalProductionWorkflowContract::STAGES), 'milestones' => [], 'updatedAt' => self::iso($order['production_changed_at'])];
        }
        $current = $completed ? 'ready' : $stageMilestone;
        $currentIndex = (int) array_search($current, self::MILESTONES, true);

        // Replay the stage entries: a later return to an earlier stage (rework) makes the later milestones pending
        // again, so a milestone's time is always its latest entry on the way to the current state.
        $reached = ['received' => self::iso($order['accepted_at'] ?? null) ?? self::iso($order['created_at'])];
        $position = 0;
        foreach ($events as $event) {
            $milestone = $event['action'] === 'production_completed' ? 'ready' : (self::STAGE_MILESTONES[(string) $event['to_stage_id']] ?? null);
            if ($milestone === null) {
                continue;
            }
            $index = (int) array_search($milestone, self::MILESTONES, true);
            if ($index === $position) {
                continue;
            }
            foreach (self::MILESTONES as $i => $key) {
                if ($i > $index) {
                    unset($reached[$key]);
                }
            }
            if ($index > 0) {
                $reached[$milestone] = self::iso($event['occurred_at']);
            }
            $position = $index;
        }
        if ($completed) {
            $reached['ready'] = self::iso($order['production_completed_at']);
        }
        $milestones = [];
        foreach (self::MILESTONES as $i => $key) {
            $milestones[] = ['key' => $key, 'reached' => $i <= $currentIndex, 'reachedAt' => $i <= $currentIndex ? ($reached[$key] ?? null) : null];
        }
        return [
            'state' => $state,
            'milestone' => $current,
            'milestoneNumber' => $currentIndex + 1,
            'milestoneCount' => count(self::MILESTONES),
            'stageNumber' => $completed ? count(CanonicalProductionWorkflowContract::STAGES) : $stageNumber,
            'stageCount' => count(CanonicalProductionWorkflowContract::STAGES),
            'milestones' => $milestones,
            'updatedAt' => self::iso($order['production_changed_at']) ?? self::iso($order['created_at']),
        ];
    }

    private static function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $date === false ? null : $date->format('Y-m-d\TH:i:s\Z');
    }
}
