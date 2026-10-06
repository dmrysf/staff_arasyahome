<?php
declare(strict_types=1);
namespace Arasya\Operations\Cutting;
use Arasya\Operations\Quality\LiveEvents;
use Arasya\Operations\Support\Clock;
use DateTimeZone;
use PDO;
use Throwable;

/** Allowlist-only wall projection. Never serialize orders, employees or commerce models. */
final readonly class BoardSnapshot
{
    public function __construct(private PDO $pdo, private Clock $clock, private DisplayDevices $devices) {}
    public function stateKey(): string
    {
        $now = $this->clock->now();
        $hours = $this->pdo->query('SELECT weekday, is_open, opens_at, closes_at FROM business_hours ORDER BY weekday')->fetchAll(PDO::FETCH_ASSOC);
        return $now->setTimezone(new DateTimeZone('Europe/Bucharest'))->format('Y-m-d') . ':' . ((new BusinessTime($hours))->isOpen($now) ? 'open' : 'closed');
    }
    public function snapshot(): array
    {
        $this->pdo->beginTransaction();
        try {
            $cursor = (new LiveEvents($this->pdo))->latestSequence();
            $now = $this->clock->now();
            $hours = $this->pdo->query('SELECT weekday, is_open, opens_at, closes_at FROM business_hours ORDER BY weekday')->fetchAll(PDO::FETCH_ASSOC);
            $business = new BusinessTime($hours);
            $local = $now->setTimezone(new DateTimeZone('Europe/Bucharest'));
            $start = $local->setTime(0,0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $end = $local->setTime(0,0)->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $daily = $this->pdo->prepare("SELECT COUNT(*) AS orders, COALESCE(SUM(meters_snapshot),0) AS meters FROM order_activity_events WHERE action = 'stage_completed' AND from_stage_id = 'material-preparation' AND to_stage_id = 'workshop-receiving' AND occurred_at >= ? AND occurred_at < ?");
            $daily->execute([$start, $end]); $today = $daily->fetch(PDO::FETCH_ASSOC);
            $waitingWhere = "o.production_stage_id = 'material-preparation' AND o.production_completed_at IS NULL AND o.operational_status <> 'unavailable' AND o.source_reported_unavailable_at IS NULL AND o.production_owner_employee_uuid IS NULL AND o.open_exception_uuid IS NULL AND o.document_status IN ('none', 'active') AND s.status = 'active'";
            $waitingCount = (int) $this->pdo->query("SELECT COUNT(*) FROM operational_orders o JOIN order_sources s ON s.source_key = o.source_key WHERE {$waitingWhere}")->fetchColumn();
            $active = $this->pdo->query("SELECT o.order_uuid, o.order_number, o.source_key, o.production_claimed_at, o.open_exception_uuid, o.operational_status, o.source_changed_at, e.display_name,
                COUNT(*) OVER (PARTITION BY e.employee_uuid) AS employee_active_count,
                t.status AS transfer_status, t.requested_at AS transfer_requested_at, t.decided_at AS transfer_decided_at, t.accepted_at AS transfer_accepted_at, ex.reported_at AS exception_reported_at, db.started_at AS document_blocked_at
              FROM operational_orders o JOIN employees e ON e.employee_uuid = o.production_owner_employee_uuid
              LEFT JOIN cutting_transfers t ON t.open_order_uuid = o.order_uuid
              LEFT JOIN production_exceptions ex ON ex.exception_uuid = o.open_exception_uuid
              LEFT JOIN production_document_blocks db ON db.open_order_uuid = o.order_uuid
              WHERE o.production_stage_id = 'material-preparation' AND o.production_completed_at IS NULL
              ORDER BY e.display_name, o.production_claimed_at, o.global_order_id")->fetchAll(PDO::FETCH_ASSOC);
            $waiting = $this->pdo->query("SELECT o.order_uuid, o.order_number, o.source_key FROM operational_orders o JOIN order_sources s ON s.source_key = o.source_key WHERE {$waitingWhere} ORDER BY o.production_changed_at, o.global_order_id LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            // Exactly one batched aggregate for all active and preview orders, no per-card item query.
            $ids = array_values(array_unique(array_column([...$active, ...$waiting], 'order_uuid')));
            $products = [];
            if ($ids !== []) {
                $s = $this->pdo->prepare('SELECT order_uuid, SUM(meters) AS meters FROM operational_order_items WHERE order_uuid IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY order_uuid');
                $s->execute($ids);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) $products[$p['order_uuid']] = ['meters' => $p['meters'] === null ? null : (string) $p['meters'], 'codes' => ''];
                // A second batched allowlist query avoids GROUP_CONCAT silently truncating long code lists.
                $s = $this->pdo->prepare('SELECT DISTINCT order_uuid, product_code FROM operational_order_items WHERE order_uuid IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND product_code IS NOT NULL ORDER BY order_uuid, product_code');
                $s->execute($ids); $codes = [];
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) $codes[$p['order_uuid']][] = $p['product_code'];
                foreach ($codes as $id => $values) $products[$id]['codes'] = implode(' · ', $values);
            }
            $thresholds = $this->devices->settings()['thresholds'];
            $nowSql = $now->format('Y-m-d H:i:s.u');
            $cards = array_map(function(array $r) use ($products, $business, $thresholds, $nowSql): array {
                $blocked = $r['transfer_status'] !== null || $r['open_exception_uuid'] !== null || $r['operational_status'] === 'unavailable' || $r['document_blocked_at'] !== null;
                $blockedAt = $r['transfer_requested_at'] ?? $r['exception_reported_at'] ?? $r['document_blocked_at'] ?? ($blocked ? $r['source_changed_at'] : null);
                $currentWaitAt = $r['transfer_status'] === 'accepted' ? $r['transfer_accepted_at'] : ($r['transfer_status'] === 'approved' ? $r['transfer_decided_at'] : $blockedAt);
                $seconds = $r['production_claimed_at'] === null ? 0 : $business->seconds($r['production_claimed_at'], $blockedAt ?? $nowSql);
                // Closed earlier transfer waits do not become active work after a rejection/cancel.
                // The batched historical wait query below subtracts those intervals.
                $state = $r['transfer_status'] === 'pending' ? 'Așteaptă aprobarea' : ($r['transfer_status'] !== null ? 'Așteaptă acceptarea / scanarea' : ($blocked ? 'Lucrare blocată' : 'În lucru'));
                return $this->card($r, $products) + ['employee' => $r['display_name'], 'employeeActiveCount' => (int) $r['employee_active_count'], 'activeSeconds' => $seconds, 'blockedSeconds' => $currentWaitAt === null ? 0 : $business->seconds($currentWaitAt, $nowSql), 'state' => $state, 'blocked' => $blocked, 'tone' => $blocked ? 'blocked' : $this->tone($seconds, $thresholds), 'startedAt' => $r['production_claimed_at']];
            }, $active);
            if ($active !== []) {
                $s = $this->pdo->prepare("SELECT order_uuid, requested_at, resolved_at FROM cutting_transfers WHERE order_uuid IN (" . implode(',', array_fill(0, count($active), '?')) . ") AND status IN ('rejected','cancelled') AND resolved_at IS NOT NULL");
                $s->execute(array_column($active, 'order_uuid'));
                $waits = [];
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $wait) $waits[$wait['order_uuid']][] = $wait;
                foreach ($active as $i => $r) {
                    foreach ($waits[$r['order_uuid']] ?? [] as $wait) {
                        if ($r['production_claimed_at'] !== null && $wait['resolved_at'] > $r['production_claimed_at']) $cards[$i]['activeSeconds'] = max(0, $cards[$i]['activeSeconds'] - $business->seconds(max($r['production_claimed_at'], $wait['requested_at']), $wait['resolved_at']));
                    }
                    $cards[$i]['tone'] = $cards[$i]['blocked'] ? 'blocked' : $this->tone($cards[$i]['activeSeconds'], $thresholds);
                    unset($cards[$i]['startedAt']);
                }
            }
            $result = ['title' => 'Zona de tăiere perdele', 'serverAt' => $now->format('Y-m-d\TH:i:s.u\Z'), 'day' => $local->format('Y-m-d'), 'open' => $business->isOpen($now), 'cursor' => $cursor, 'thresholds' => $thresholds,
              'counters' => ['completedOrders' => (int) $today['orders'], 'completedMeters' => (string) $today['meters'], 'inProgress' => count($cards), 'waiting' => $waitingCount],
              'active' => $cards, 'waiting' => array_map(fn(array $r): array => $this->card($r, $products), $waiting), 'waitingOverflow' => max(0, $waitingCount - count($waiting))];
            $this->pdo->commit(); return $result;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    private function card(array $r, array $products): array
    {
        return ['id' => substr(hash('sha256', $r['order_uuid']), 0, 24), 'orderNumber' => $r['order_number'], 'source' => $r['source_key'], 'codes' => $products[$r['order_uuid']]['codes'] ?? '', 'meters' => $products[$r['order_uuid']]['meters'] ?? null];
    }
    private function tone(int $seconds, array $thresholds): string
    {
        return $seconds >= $thresholds[2] * 60 ? 'severe' : ($seconds >= $thresholds[1] * 60 ? 'warning' : ($seconds >= $thresholds[0] * 60 ? 'attention' : 'normal'));
    }
}
