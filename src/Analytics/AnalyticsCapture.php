<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
use PDO;
use LogicException;

/** Caller holds the canonical order lock. All derived writes share its transaction. */
final readonly class AnalyticsCapture
{
    public function __construct(private PDO $pdo) {}
    public function refreshOrder(string $id): void
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('Analytics capture requires the canonical transaction');
        $s=$this->pdo->prepare('SELECT order_uuid,production_version,created_at,source_key FROM operational_orders WHERE order_uuid=? FOR UPDATE');
        $s->execute([$id]); $order=$s->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new LogicException('Canonical order missing');
        $company=$order['source_key']==='b2b'?(new \Arasya\Operations\B2B\ProductionAnalyticsIdentity($this->pdo))->companyForOperationalOrder($id):null;
        $s=$this->pdo->prepare('SELECT event_id,employee_uuid,action,from_stage_id,to_stage_id,new_owner_employee_uuid,production_version_after,occurred_at,meters_snapshot FROM order_activity_events WHERE order_uuid=? ORDER BY production_version_after,event_id');
        $s->execute([$id]); $lifecycle=Lifecycle::derive($s->fetchAll(PDO::FETCH_ASSOC));
        $s=$this->pdo->prepare('SELECT MIN(created_at) FROM order_qr_references WHERE order_uuid=?'); $s->execute([$id]); $qr=$s->fetchColumn()?:null;
        $cut=$lifecycle['cuttingCompletion']; $lines=null;
        if ($cut!==null) {
            $s=$this->pdo->prepare("SELECT item_count_snapshot FROM cutting_facts WHERE order_uuid=? AND production_version=? AND fact_type='completed'");
            $s->execute([$id,$cut['production_version_after']]); $value=$s->fetchColumn(); $lines=$value===false||$value===null?null:(int)$value;
        }
        $this->pdo->prepare('INSERT INTO analytics_order_projection (order_uuid,source_version,created_at,qr_created_at,first_claim_at,completed_at,cutting_completed_at,cutting_employee_uuid,cutting_meters,cutting_lines,source_key,company_uuid) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source_version=VALUES(source_version),formula_version=1,qr_created_at=VALUES(qr_created_at),first_claim_at=VALUES(first_claim_at),completed_at=VALUES(completed_at),cutting_completed_at=VALUES(cutting_completed_at),cutting_employee_uuid=VALUES(cutting_employee_uuid),cutting_meters=VALUES(cutting_meters),cutting_lines=VALUES(cutting_lines),company_uuid=VALUES(company_uuid)')
            ->execute([$id,$order['production_version'],$order['created_at'],$qr,$lifecycle['firstClaimAt'],$lifecycle['completedAt'],$cut['occurred_at']??null,$cut['employee_uuid']??null,$cut['meters_snapshot']??null,$lines,$order['source_key'],$company]);
        $this->pdo->prepare('DELETE FROM analytics_ownership_intervals WHERE order_uuid=?')->execute([$id]);
        $insert=$this->pdo->prepare('INSERT INTO analytics_ownership_intervals (order_uuid,start_version,employee_uuid,stage_id,started_at,ended_at) VALUES (?,?,?,?,?,?)');
        foreach ($lifecycle['intervals'] as $interval) $insert->execute([$id,$interval['startVersion'],$interval['employeeId'],$interval['stageId'],$interval['start'],$interval['end']]);
    }
    /** Order UUID keyset, one transaction/order; concurrent capture and rebuild serialize on the same lock. */
    public function rebuild(int $batch=50): int
    {
        if ($batch<1 || $batch>500 || $this->pdo->inTransaction()) throw new LogicException('Invalid rebuild context');
        $cursor=''; $count=0;
        do {
            $s=$this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid>? ORDER BY order_uuid LIMIT '.(int)$batch);
            $s->execute([$cursor]); $ids=$s->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $this->pdo->beginTransaction();
                try { $this->refreshOrder($id); $this->pdo->commit(); }
                catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
                $cursor=$id; $count++;
            }
        } while (count($ids)===$batch);
        return $count;
    }
}
