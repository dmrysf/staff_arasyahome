<?php
declare(strict_types=1);
namespace Arasya\Operations\Cutting;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Order\QrReference;
use Arasya\Operations\Quality\LiveEvents;
use Arasya\Operations\Support\Uuid;
use PDO;

/** Hooks inside the existing canonical order transaction; never owns a second production state. */
final readonly class CuttingLifecycle
{
    public const STAGE = 'material-preparation';
    public function __construct(private PDO $pdo) {}
    public static function eligible(EmployeeIdentity $actor): bool
    {
        return !$actor->isRoot && !$actor->mustChangePassword && $actor->isOperationallyActive()
            && in_array($actor->departmentKey, ['pregatire-material','taiere'], true)
            && $actor->hasApplication('staff') && in_array(self::STAGE, $actor->allowedStageIds, true)
            && in_array('orders.claim', $actor->permissions, true) && in_array('orders.advance_stage', $actor->permissions, true);
    }
    public function ownedCount(string $employee): int
    {
        $s = $this->pdo->prepare("SELECT COUNT(*) FROM operational_orders WHERE production_stage_id = 'material-preparation' AND production_owner_employee_uuid = ? AND production_completed_at IS NULL");
        $s->execute([$employee]);
        return (int) $s->fetchColumn();
    }
    public function qr(string $order, mixed $payload): void
    {
        $qr = is_string($payload) ? QrReference::parsePayload($payload) : null;
        if ($qr === null) throw new ApiException(422, 'CUTTING_QR_REQUIRED', 'Scanează codul QR original al comenzii.');
        $s = $this->pdo->prepare("SELECT order_uuid FROM order_qr_references WHERE qr_reference = ? AND status = 'active'");
        $s->execute([$qr->value]);
        if ($s->fetchColumn() !== $order) throw new ApiException(409, 'QR_ORDER_MISMATCH', 'Codul QR nu aparține acestei comenzi.');
    }
    public function confirmMultiple(EmployeeIdentity $actor, array $intent, string $requestId, string $now): void
    {
        $count = $this->ownedCount($actor->employeeUuid);
        if ($count > 0 && (($intent['confirmedMultiple'] ?? false) !== true || ($intent['ownedCount'] ?? null) !== $count)) {
            throw new ApiException(409, 'CUTTING_MULTIPLE_CONFIRMATION_REQUIRED', "Ai deja {$count} comenzi neterminate. Confirmă explicit preluarea încă unei comenzi.", ['ownedCount' => $count]);
        }
        if (isset($intent['ownedCount']) && $intent['ownedCount'] !== $count) throw new ApiException(409, 'CUTTING_OWNED_COUNT_CHANGED', 'Numărul comenzilor tale s-a schimbat. Confirmă din nou.', ['ownedCount' => $count]);
        if ($count > 0) (new IamAuditLogger($this->pdo))->record($actor, 'cutting.multiple_claim_confirmed', 'employee', $actor->employeeUuid, $actor->displayName, ['unfinishedCount' => $count, 'confirmed' => true], $requestId, $now);
    }
    public function requireNoTransfer(string $order): void
    {
        $s = $this->pdo->prepare('SELECT transfer_uuid FROM cutting_transfers WHERE open_order_uuid = ?');
        $s->execute([$order]);
        if ($s->fetchColumn() !== false) throw new ApiException(409, 'CUTTING_TRANSFER_PENDING', 'Comanda așteaptă soluționarea transferului.');
    }
    public function fact(string $order, int $version, string $type, ?string $employee, string $now, ?string $meters = null, ?int $itemCount = null, ?int $activeSeconds = null): void
    {
        $this->pdo->prepare('INSERT INTO cutting_facts (fact_uuid, order_uuid, production_version, fact_type, employee_uuid, occurred_at, meters, item_count_snapshot, active_seconds_snapshot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE fact_uuid = fact_uuid')
            ->execute([Uuid::v4(), $order, $version, $type, $employee, $now, $meters, $itemCount, $activeSeconds]);
    }
    public function claimed(string $order, int $version, string $employee, string $now): void
    {
        $s = $this->pdo->prepare('UPDATE operational_orders SET cutting_first_claimed_at = ? WHERE order_uuid = ? AND cutting_first_claimed_at IS NULL');
        $s->execute([$now, $order]);
        if ($s->rowCount() === 1) $this->fact($order, $version, 'first_claim', $employee, $now);
        $this->fact($order, $version, 'interval_started', $employee, $now);
        (new LiveEvents($this->pdo))->cuttingChanged($now);
    }
    public function changed(string $order, int $version, string $fromStage, ?string $toStage, ?string $employee, string $now, ?string $meters): void
    {
        if ($fromStage === self::STAGE) {
            $this->fact($order, $version, 'interval_ended', $employee, $now);
            if ($toStage === 'workshop-receiving') {
                $s = $this->pdo->prepare('SELECT COUNT(*) FROM operational_order_items WHERE order_uuid = ?'); $s->execute([$order]);
                $itemCount = (int) $s->fetchColumn();
                $s = $this->pdo->prepare("SELECT occurred_at FROM cutting_facts WHERE order_uuid = ? AND employee_uuid = ? AND fact_type = 'interval_started' ORDER BY production_version DESC LIMIT 1"); $s->execute([$order, $employee]);
                $start = $s->fetchColumn();
                // Includes pre-V1 assignments whose first interval is in canonical activity only.
                if (!is_string($start)) {
                    $s = $this->pdo->prepare("SELECT occurred_at FROM order_activity_events WHERE order_uuid = ? AND employee_uuid = ? AND from_stage_id = 'material-preparation' AND action IN ('claimed','owner_reassigned') ORDER BY production_version_after DESC LIMIT 1"); $s->execute([$order, $employee]); $start = $s->fetchColumn();
                }
                $seconds = null;
                if (is_string($start)) {
                    $business = new BusinessTime($this->pdo->query('SELECT weekday, is_open, opens_at, closes_at FROM business_hours')->fetchAll(PDO::FETCH_ASSOC));
                    $seconds = $business->seconds($start, $now);
                    $s = $this->pdo->prepare("SELECT requested_at, resolved_at FROM cutting_transfers WHERE order_uuid = ? AND status IN ('rejected','cancelled') AND resolved_at > ? AND requested_at < ?"); $s->execute([$order, $start, $now]);
                    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $wait) $seconds -= $business->seconds(max($start, $wait['requested_at']), min($now, $wait['resolved_at']));
                    $seconds = max(0, $seconds);
                }
                $this->fact($order, $version, 'completed', $employee, $now, $meters, $itemCount, $seconds);
            }
        }
        if ($toStage === self::STAGE) $this->fact($order, $version, 'pool_entered', null, $now);
        if ($fromStage === self::STAGE || $toStage === self::STAGE) (new LiveEvents($this->pdo))->cuttingChanged($now);
    }
}
