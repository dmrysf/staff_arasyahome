<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Controlled activation of the per-piece capabilities (migrations 028, 029).
 *
 * A feature applies to an order when its global flag is enabled, or when the order is an active member of the audited
 * pilot cohort of that feature. Cohort membership is stored by order identity (never by a hardcoded order number) and
 * every flag or cohort change writes production_feature_flag_events with the operator and the reason. Before
 * migration 028/029 every feature is off.
 *
 *   partial_rework   reporting only some pieces of a carrier (a defective piece leaves on its own track)
 *   piece_routing    the product route policy for normal production (PERDEA skips 6-7; a mixed order splits by route)
 */
final readonly class FeatureActivation
{
    public const PARTIAL_REWORK = 'partial_rework';
    public const PIECE_ROUTING = 'piece_routing';
    public const FEATURES = [self::PARTIAL_REWORK, self::PIECE_ROUTING];

    public function __construct(private PDO $pdo)
    {
    }

    public function enabled(string $feature): bool
    {
        try {
            $statement = $this->pdo->prepare('SELECT enabled FROM production_feature_flags WHERE flag_key = ?');
            $statement->execute([$feature]);
            return (int) $statement->fetchColumn() === 1;
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return false;
        }
    }

    /** Whether the feature applies to this order (global flag, or active pilot cohort member). */
    public function appliesTo(string $feature, string $orderUuid): bool
    {
        if ($this->enabled($feature)) {
            return true;
        }
        try {
            $statement = $this->pdo->prepare("SELECT 1 FROM production_pilot_cohort WHERE order_uuid = ? AND feature_key = ? AND status = 'active'");
            $statement->execute([$orderUuid, $feature]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return false;
        }
    }

    /** Whether the feature applies to at least one order (global flag or a non-empty active cohort). */
    public function anywhere(string $feature): bool
    {
        if ($this->enabled($feature)) {
            return true;
        }
        try {
            $statement = $this->pdo->prepare("SELECT 1 FROM production_pilot_cohort WHERE feature_key = ? AND status = 'active' LIMIT 1");
            $statement->execute([$feature]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return false;
        }
    }

    /** @return array{enabled: bool, changed: bool} */
    public function setEnabled(string $feature, bool $enabled, string $operator, string $reason, string $now): array
    {
        self::requireFeature($feature);
        // Inside the caller's transaction when there is one; otherwise in its own.
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $statement = $this->pdo->prepare('SELECT enabled FROM production_feature_flags WHERE flag_key = ? FOR UPDATE');
            $statement->execute([$feature]);
            $current = $statement->fetchColumn();
            if ($current === false) {
                throw new RuntimeException("The {$feature} flag row is missing; apply migrations 028 and 029 first.");
            }
            if ((int) $current === ($enabled ? 1 : 0)) {
                $own && $this->pdo->commit();
                return ['enabled' => $enabled, 'changed' => false];
            }
            $this->pdo->prepare('UPDATE production_feature_flags SET enabled = ?, changed_by_employee_uuid = NULL, change_reason = ?, changed_at = ? WHERE flag_key = ?')
                ->execute([$enabled ? 1 : 0, mb_substr("{$operator}: {$reason}", 0, 255), $now, $feature]);
            $this->event($feature, $enabled ? 'enabled' : 'disabled', null, $operator, $reason, $now);
            $own && $this->pdo->commit();
            return ['enabled' => $enabled, 'changed' => true];
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{member: bool, changed: bool} */
    public function setCohortMember(string $feature, string $orderUuid, bool $member, string $operator, string $reason, string $now): array
    {
        self::requireFeature($feature);
        // Inside the caller's transaction when there is one; otherwise in its own.
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE')->execute([$orderUuid]);
            $statement = $this->pdo->prepare('SELECT status FROM production_pilot_cohort WHERE order_uuid = ? AND feature_key = ? FOR UPDATE');
            $statement->execute([$orderUuid, $feature]);
            $status = $statement->fetchColumn();
            $active = $status === 'active';
            if ($active === $member) {
                $own && $this->pdo->commit();
                return ['member' => $member, 'changed' => false];
            }
            if ($member && $status === false) {
                $this->pdo->prepare("INSERT INTO production_pilot_cohort (order_uuid, feature_key, status, added_by_operator, reason, added_at) VALUES (?, ?, 'active', ?, ?, ?)")
                    ->execute([$orderUuid, $feature, mb_substr($operator, 0, 100), mb_substr($reason, 0, 255), $now]);
            } elseif ($member) {
                $this->pdo->prepare("UPDATE production_pilot_cohort SET status = 'active', added_by_operator = ?, reason = ?, added_at = ?, removed_by_operator = NULL, removed_at = NULL WHERE order_uuid = ? AND feature_key = ?")
                    ->execute([mb_substr($operator, 0, 100), mb_substr($reason, 0, 255), $now, $orderUuid, $feature]);
            } else {
                $this->pdo->prepare("UPDATE production_pilot_cohort SET status = 'removed', removed_by_operator = ?, removed_at = ? WHERE order_uuid = ? AND feature_key = ?")
                    ->execute([mb_substr($operator, 0, 100), $now, $orderUuid, $feature]);
            }
            $this->event($feature, $member ? 'cohort_added' : 'cohort_removed', $orderUuid, $operator, $reason, $now);
            $own && $this->pdo->commit();
            return ['member' => $member, 'changed' => true];
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<array{orderUuid: string, globalOrderId: string, addedBy: string, reason: string, addedAt: string}> */
    public function cohort(string $feature): array
    {
        $statement = $this->pdo->prepare("SELECT c.order_uuid, o.global_order_id, c.added_by_operator, c.reason, c.added_at FROM production_pilot_cohort c
             INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid WHERE c.feature_key = ? AND c.status = 'active' ORDER BY c.added_at");
        $statement->execute([$feature]);
        return array_map(static fn (array $row): array => [
            'orderUuid' => (string) $row['order_uuid'], 'globalOrderId' => (string) $row['global_order_id'],
            'addedBy' => (string) $row['added_by_operator'], 'reason' => (string) $row['reason'], 'addedAt' => (string) $row['added_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function event(string $feature, string $action, ?string $orderUuid, string $operator, string $reason, string $now): void
    {
        $this->pdo->prepare('INSERT INTO production_feature_flag_events (event_uuid, feature_key, action, order_uuid, operator, reason, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([Uuid::v4(), $feature, $action, $orderUuid, mb_substr($operator, 0, 100), mb_substr($reason, 0, 255), $now]);
    }

    private static function requireFeature(string $feature): void
    {
        if (!in_array($feature, self::FEATURES, true)) {
            throw new \InvalidArgumentException('Unknown feature.');
        }
    }
}
