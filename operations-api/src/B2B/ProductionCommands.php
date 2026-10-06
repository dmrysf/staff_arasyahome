<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\OrderOperationsService;
use Arasya\Operations\Order\OrderProjectionWriter;
use Arasya\Operations\Order\SourceOrderSnapshot;
use Arasya\Operations\Production\CanonicalProductionWorkflowContract;
use Arasya\Operations\Production\ProductionWorkflowService;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;
use Throwable;

/** Explicit handoff only; never updates a finalized aggregate or calls the financial ledger. */
final readonly class ProductionCommands
{
    public function __construct(private PDO $pdo,private AuthorizationService $auth,private Clock $clock,
        private ProductionWorkflowService $workflows,private OrderProjectionWriter $writer) {}

    public function submit(EmployeeIdentity $actor,string $id,int $version,string $key,string $request): array
    {
        ProductionAccess::require($this->auth,$actor,ProductionAccess::SUBMIT);
        if(!OrderOperationsService::isValidIdempotencyKey($key)) throw new ApiException(400,'INVALID_IDEMPOTENCY_KEY','A valid Idempotency-Key is required.');
        CompanyQueries::uuidOrNotFound($id,'ORDER_NOT_FOUND','Order was not found.');
        $s=$this->pdo->prepare('SELECT company_uuid FROM b2b_orders WHERE order_uuid=?');$s->execute([$id]);$company=$s->fetchColumn();
        if($company===false) throw new ApiException(404,'ORDER_NOT_FOUND','Order was not found.');
        $hash=hash('sha256','production_submit|'.$id.'|'.OrderStore::json(['expectedVersion'=>$version]),true);
        for($attempt=1;;$attempt++) {
            try {
                $this->pdo->beginTransaction();
                ProductionAccess::require($this->auth,$actor,ProductionAccess::SUBMIT);
                $s=$this->pdo->prepare('SELECT company_uuid FROM b2b_companies WHERE company_uuid=? FOR UPDATE');$s->execute([$company]);
                $s=$this->pdo->prepare('SELECT * FROM b2b_orders WHERE order_uuid=? FOR UPDATE');$s->execute([$id]);$order=$s->fetch(PDO::FETCH_ASSOC);
                if(!$order) throw new ApiException(404,'ORDER_NOT_FOUND','Order was not found.');
                $s=$this->pdo->prepare('SELECT operation,request_hash,response_json FROM b2b_order_idempotency WHERE employee_uuid=? AND idempotency_key=? FOR UPDATE');
                $s->execute([$actor->employeeUuid,$key]);$replay=$s->fetch(PDO::FETCH_ASSOC);
                if($replay) {
                    if($replay['operation']!=='production_submit' || !hash_equals($replay['request_hash'],$hash)) throw new ApiException(409,'IDEMPOTENCY_CONFLICT','Key was used for another request.');
                    $this->pdo->rollBack();return OrderStore::decode($replay['response_json']);
                }
                $s=$this->pdo->prepare('SELECT operational_order_uuid FROM b2b_production_handoffs WHERE b2b_order_uuid=? FOR UPDATE');$s->execute([$id]);$existing=$s->fetchColumn();
                if($existing!==false) $result=['status'=>200,'orderId'=>$id];
                else {
                    if($order['status']!=='finalized') throw new ApiException(409,'PRODUCTION_NOT_ELIGIBLE','Only finalized commercial orders may enter production.');
                    if((int)$order['version']!==$version) throw new ApiException(409,'ORDER_CHANGED','Order changed. Reload before submitting.');
                    $store=new OrderStore($this->pdo);
                    $companyContext=ProductionInput::company(OrderStore::decode($order['company_snapshot']));
                    // Project-origin lines carry their frozen location (immutable trace rows written at conversion).
                    $s=$this->pdo->prepare('SELECT line_uuid,trace_context FROM b2b_project_order_lines WHERE order_uuid=?');$s->execute([$id]);
                    $trace=array_map(static fn(string $json): array=>OrderStore::decode($json),$s->fetchAll(PDO::FETCH_KEY_PAIR));
                    $items=ProductionInput::items($store->lines($id,true),$trace);
                    try { $workflow=$this->workflows->current(); }
                    catch(\RuntimeException $e) { throw new ApiException(503,'WORKFLOW_UNAVAILABLE','Canonical production workflow is unavailable.'); }
                    if($workflow->id!==CanonicalProductionWorkflowContract::WORKFLOW_ID || $workflow->version!==CanonicalProductionWorkflowContract::VERSION)
                        throw new ApiException(503,'WORKFLOW_UNAVAILABLE','Canonical production workflow is unavailable.');
                    $now=$this->clock->now();$sql=$now->format('Y-m-d H:i:s.u');$waiting=$workflow->stages[0];
                    $snapshot=new SourceOrderSnapshot('b2b',$id,'handoff-'.$id,1,$now,$order['order_code'],'waiting','finalized','Finalizată',
                        $order['production_notes'],'in_progress',$now,$items);
                    $operational=$this->writer->createInternal($snapshot,['company'=>$companyContext]);
                    $this->pdo->prepare('INSERT INTO b2b_production_handoffs(b2b_order_uuid,operational_order_uuid,submitted_by_employee_uuid,submitted_by_name,submitted_at,request_id,idempotency_key)
                        VALUES(?,?,?,?,?,?,?)')->execute([$id,$operational,$actor->employeeUuid,$actor->displayName,$sql,mb_substr($request,0,100),$key]);
                    $this->pdo->prepare("INSERT INTO order_activity_events(event_id,employee_uuid,order_uuid,global_order_id,source_key,order_number_snapshot,action,
                        workflow_key,workflow_version,from_stage_id,from_stage_label_snapshot,production_version_before,production_version_after,request_id,idempotency_key,occurred_at)
                        VALUES(?,?,?,?,?,?,'production_submitted',?,?,?,?,0,1,?,?,?)")->execute([
                            Uuid::v4(),$actor->employeeUuid,$operational,'b2b:'.$id,'b2b',$order['order_code'],$workflow->id,$workflow->version,
                            $waiting->id,$waiting->label,mb_substr($request,0,100),'b2b-production:'.hash('sha256',$key),$sql]);
                    (new \Arasya\Operations\Analytics\AnalyticsCapture($this->pdo))->refreshOrder($operational);
                    $result=['status'=>201,'orderId'=>$id];
                }
                $this->pdo->prepare("INSERT INTO b2b_order_idempotency(employee_uuid,idempotency_key,operation,request_hash,order_uuid,response_json,created_at)
                    VALUES(?,?,'production_submit',?,?,?,?)")->execute([$actor->employeeUuid,$key,$hash,$id,OrderStore::json($result),$this->clock->now()->format('Y-m-d H:i:s.u')]);
                $this->pdo->commit();return $result;
            } catch(Throwable $e) {
                if($this->pdo->inTransaction()) $this->pdo->rollBack();
                if($e instanceof PDOException && $attempt<3 && in_array((int)($e->errorInfo[1]??0),[1062,1205,1213],true)) continue;
                throw $e;
            }
        }
    }
}
