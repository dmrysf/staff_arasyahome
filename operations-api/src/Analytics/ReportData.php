<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
use PDO;

/** Range-indexed candidate discovery, then batched facts. No per-employee/order query loop. */
final readonly class ReportData
{
    public function __construct(private PDO $pdo) {}
    public function load(DateRange $range,bool $includePending=false): array
    {
        $from=$range->fromUtc->format('Y-m-d H:i:s'); $to=$range->toUtc->format('Y-m-d H:i:s');
        $periods=[['analytics_order_projection','created_at'],['analytics_order_projection','cutting_completed_at'],['analytics_order_projection','completed_at'],['production_quality_events','occurred_at'],['cutting_transfers','requested_at'],['cutting_transfers','decided_at'],['cutting_facts','occurred_at']];
        $ids=[];
        foreach ($periods as [$table,$column]) {
            $s=$this->pdo->prepare("SELECT DISTINCT order_uuid FROM {$table} WHERE {$column}>=? AND {$column}<?");
            $s->execute([$from,$to]); foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[$id]=true;
        }
        $s=$this->pdo->prepare('SELECT DISTINCT order_uuid FROM analytics_ownership_intervals WHERE ended_at>=? AND started_at<? UNION SELECT DISTINCT order_uuid FROM analytics_ownership_intervals WHERE ended_at IS NULL AND started_at<?');
        $s->execute([$from,$to,$to]); foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[$id]=true;
        $s=$this->pdo->prepare('SELECT DISTINCT e.order_uuid FROM production_exception_decisions d JOIN production_exceptions e ON e.exception_uuid=d.exception_uuid WHERE (d.opened_at>=? AND d.opened_at<?) OR (d.decided_at>=? AND d.decided_at<?)');
        $s->execute([$from,$to,$from,$to]); foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[$id]=true;
        if ($includePending) {
            $s=$this->pdo->prepare("SELECT order_uuid FROM production_exceptions WHERE status='awaiting_approval' AND reported_at<? UNION SELECT order_uuid FROM cutting_transfers WHERE status='pending' AND requested_at<?");
            $s->execute([$to,$to]); foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[$id]=true;
        }
        $orders=[]; $intervals=[]; $transfers=[]; $exceptions=[]; $decisions=[]; $quality=[]; $cancellations=[]; $transferDecisionTypes=[]; $captured=[]; $pools=[];
        foreach (array_chunk(array_keys($ids),400) as $chunk) {
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $s=$this->pdo->prepare("SELECT p.*,o.global_order_id,o.order_number,o.production_stage_id,o.operational_status,o.production_context,o.source_reported_unavailable_at FROM analytics_order_projection p JOIN operational_orders o ON o.order_uuid=p.order_uuid WHERE p.order_uuid IN ({$marks})");
            $s->execute($chunk); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $orders[$row['order_uuid']]=$row;
            $s=$this->pdo->prepare("SELECT order_uuid,COUNT(*) order_lines,SUM(meters) order_meters,SUM(meters IS NULL) missing_line_meters FROM operational_order_items WHERE order_uuid IN ({$marks}) GROUP BY order_uuid");
            $s->execute($chunk); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) if (isset($orders[$row['order_uuid']])) $orders[$row['order_uuid']]+=array_diff_key($row,['order_uuid'=>true]);
            // A completed stage also proves actual work, even if an old Root assignment had no claimed event.
            $s=$this->pdo->prepare("SELECT order_uuid,MIN(occurred_at) work_recorded_at FROM order_activity_events WHERE order_uuid IN ({$marks}) AND action IN ('claimed','stage_completed','production_completed') GROUP BY order_uuid");
            $s->execute($chunk); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) if (isset($orders[$row['order_uuid']])) $orders[$row['order_uuid']]['work_recorded_at']=$row['work_recorded_at'];
            foreach (['analytics_ownership_intervals'=>&$intervals,'cutting_transfers'=>&$transfers,'production_exceptions'=>&$exceptions,'production_quality_events'=>&$quality] as $table=>&$target) {
                $s=$this->pdo->prepare("SELECT * FROM {$table} WHERE order_uuid IN ({$marks})"); $s->execute($chunk);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $target[$row['order_uuid']][]=$row;
            }
            unset($target);
            $s=$this->pdo->prepare("SELECT d.*,e.order_uuid,e.resolved_at exception_resolved_at FROM production_exception_decisions d JOIN production_exceptions e ON e.exception_uuid=d.exception_uuid WHERE e.order_uuid IN ({$marks})");
            $s->execute($chunk); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $decisions[$row['order_uuid']][]=$row;
            $s=$this->pdo->prepare("SELECT * FROM cutting_facts WHERE fact_type IN ('source_cancelled','pool_entered') AND order_uuid IN ({$marks})");
            $s->execute($chunk); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['fact_type']==='source_cancelled') $cancellations[$row['order_uuid']][]=$row; else $pools[$row['order_uuid']][]=$row;
            }
            $s=$this->pdo->prepare("SELECT t.transfer_uuid,a.action FROM cutting_transfers t JOIN iam_audit_events a ON a.target_type='cutting_transfer' AND a.target_id=t.transfer_uuid WHERE t.order_uuid IN ({$marks}) AND a.action IN ('cutting.transfer.approved','cutting.transfer.rejected')");
            $s->execute($chunk); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $transferDecisionTypes[$row['transfer_uuid']]=$row['action']==='cutting.transfer.approved'?'approved':'rejected';
            $s=$this->pdo->prepare("SELECT a.* FROM analytics_approval_requests a JOIN production_exception_decisions d ON a.request_type='exception' AND a.request_uuid=d.decision_uuid JOIN production_exceptions e ON e.exception_uuid=d.exception_uuid WHERE e.order_uuid IN ({$marks}) UNION ALL SELECT a.* FROM analytics_approval_requests a JOIN cutting_transfers t ON a.request_type='transfer' AND a.request_uuid=t.transfer_uuid WHERE t.order_uuid IN ({$marks})");
            $s->execute([...$chunk,...$chunk]); array_push($captured,...$s->fetchAll(PDO::FETCH_ASSOC));
        }
        $employees=$this->pdo->query('SELECT e.employee_uuid,e.display_name,e.position_title,e.status,d.department_id,d.department_key,d.name department_name FROM employees e JOIN departments d ON d.department_id=e.department_id ORDER BY e.employee_uuid')->fetchAll(PDO::FETCH_ASSOC);
        $sourceNames=$this->pdo->query('SELECT source_key,display_name FROM order_sources')->fetchAll(PDO::FETCH_KEY_PAIR);
        $s=$this->pdo->prepare('SELECT request_type,request_uuid,employee_uuid,eligible_via,eligible_at FROM analytics_approval_eligibility WHERE eligible_at>=? AND eligible_at<?'); $s->execute([$from,$to]); $eligibility=$s->fetchAll(PDO::FETCH_ASSOC);
        if ($includePending) {
            $s=$this->pdo->prepare("SELECT a.* FROM analytics_approval_eligibility a JOIN production_exception_decisions d ON a.request_type='exception' AND a.request_uuid=d.decision_uuid WHERE d.status='pending' AND d.opened_at<? UNION SELECT a.* FROM analytics_approval_eligibility a JOIN cutting_transfers t ON a.request_type='transfer' AND a.request_uuid=t.transfer_uuid WHERE t.status='pending' AND t.requested_at<?");
            $s->execute([$to,$to]); $unique=[];
            foreach ([...$eligibility,...$s->fetchAll(PDO::FETCH_ASSOC)] as $row) $unique[$row['request_type'].':'.$row['request_uuid'].':'.$row['employee_uuid']]=$row;
            $eligibility=array_values($unique);
        }
        $s=$this->pdo->prepare("SELECT employee_uuid,order_uuid,from_stage_id,action FROM order_activity_events WHERE action IN ('stage_completed','production_completed','production_submitted') AND occurred_at>=? AND occurred_at<?");
        $s->execute([$from,$to]); $stageCompletions=$s->fetchAll(PDO::FETCH_ASSOC);
        $coverage=$this->pdo->query('SELECT (SELECT COUNT(*) FROM operational_orders) total_orders,(SELECT COUNT(*) FROM analytics_order_projection) projected_orders')->fetch(PDO::FETCH_ASSOC);
        return compact('orders','intervals','transfers','exceptions','decisions','quality','cancellations','employees','eligibility','captured','coverage','stageCompletions','transferDecisionTypes','sourceNames','pools');
    }
}
