<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Production\ProductionWorkflowService;
use PDO;
final readonly class ProductionQueries
{
    public function __construct(private PDO $pdo,private AuthorizationService $auth,private ProductionWorkflowService $workflows) {}
    public function read(EmployeeIdentity $actor,string $id): array
    {
        ProductionAccess::require($this->auth,$actor,ProductionAccess::VIEW);
        return $this->status($id);
    }
    /** Also the result of a submit-only command: only its own handoff/progress, never general Staff data. */
    public function status(string $id): array
    {
        CompanyQueries::uuidOrNotFound($id,'ORDER_NOT_FOUND','Order was not found.');
        $s=$this->pdo->prepare('SELECT c.order_code,c.order_type,h.submitted_at,h.order_type AS frozen_type,o.global_order_id,o.production_stage_id,
            o.production_changed_at,o.production_completed_at,o.production_owner_employee_uuid,o.service_route
            FROM b2b_orders c LEFT JOIN b2b_production_handoffs h ON h.b2b_order_uuid=c.order_uuid
            LEFT JOIN operational_orders o ON o.order_uuid=h.operational_order_uuid WHERE c.order_uuid=?');
        $s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new ApiException(404,'ORDER_NOT_FOUND','Order was not found.');
        if($row['submitted_at']===null) return ['submitted'=>false,'orderCode'=>$row['order_code'],'orderType'=>$row['order_type']];
        try { $workflow=$this->workflows->current(); }
        catch(\RuntimeException $e) { throw new ApiException(503,'WORKFLOW_UNAVAILABLE','Canonical production workflow is unavailable.'); }
        foreach($workflow->stages as $stage) if($stage->id===$row['production_stage_id']) return [
            'submitted'=>true,'orderCode'=>$row['order_code'],'operationalOrderId'=>$row['global_order_id'],
            'submittedAt'=>self::iso($row['submitted_at']),'stageChangedAt'=>self::iso($row['production_changed_at']),
            'completedAt'=>self::iso($row['production_completed_at']),
            'workflow'=>$workflow->id.'@'.$workflow->version,'totalStages'=>count($workflow->stages),
            'stage'=>['id'=>$stage->id,'label'=>$stage->label,'ordinal'=>$stage->ordinal],
            'document'=>$this->documentSummary((string)$row['global_order_id']),
        ]+$this->route($workflow,$row,$stage);
        throw new ApiException(503,'WORKFLOW_UNAVAILABLE','Canonical stage is unavailable.');
    }
    /**
     * Route of the submitted order (migration 050), decided by ProductRoutePolicy from the frozen service route: the
     * applicable stages, the next one, whether stage 2 is done, and the Livrare state. Stage 14 completion is the existing
     * production completion (internal handover by Livrare); no courier, AWB or customer delivery is recorded.
     * @return array<string, mixed>
     */
    private function route(\Arasya\Operations\Production\ProductionWorkflow $workflow,array $row,\Arasya\Operations\Production\ProductionStage $stage): array
    {
        $service=$row['service_route']===null?null:(string)$row['service_route'];
        $route=\Arasya\Operations\Production\ProductRoutePolicy::route($workflow,\Arasya\Operations\Production\ProductRoutePolicy::DRAPERII,false,$service);
        $completed=$row['production_completed_at']!==null;
        $next=$completed?null:\Arasya\Operations\Production\ProductRoutePolicy::next($workflow,$stage->id,\Arasya\Operations\Production\ProductRoutePolicy::DRAPERII,false,$service)['next'];
        $atDelivery=$stage->id==='delivery';
        return [
            'orderType'=>$row['frozen_type'],
            'route'=>['stages'=>$route,'shortRoute'=>\Arasya\Operations\Production\ProductRoutePolicy::shortService($service),
                'notApplicable'=>array_values(array_diff(array_map(static fn($s)=>$s->id,$workflow->stages),$route))],
            'nextStage'=>$next===null?null:['id'=>$next->id,'label'=>$next->label,'ordinal'=>$next->ordinal],
            'stage2Completed'=>$completed || $stage->ordinal>2,
            'delivery'=>$completed?'HANDED_OVER':($atDelivery?($row['production_owner_employee_uuid']===null?'READY_FOR_LIVRARE':'RECEIVED_BY_LIVRARE'):'IN_PRODUCTION'),
        ];
    }

    /** Canonical operational order id of a submitted B2B order, or null before the handoff. */
    public function operationalId(string $id): ?string
    {
        CompanyQueries::uuidOrNotFound($id,'ORDER_NOT_FOUND','Order was not found.');
        $s=$this->pdo->prepare('SELECT o.global_order_id FROM b2b_orders c LEFT JOIN b2b_production_handoffs h ON h.b2b_order_uuid=c.order_uuid
            LEFT JOIN operational_orders o ON o.order_uuid=h.operational_order_uuid WHERE c.order_uuid=?');
        $s->execute([$id]); $row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new ApiException(404,'ORDER_NOT_FOUND','Order was not found.');
        return $row['global_order_id']===null?null:(string)$row['global_order_id'];
    }
    /** Production document state (revision and blocking) of the canonical order. */
    public function documentSummary(string $globalOrderId): array
    {
        $s=$this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE global_order_id=?');$s->execute([$globalOrderId]);
        return (new \Arasya\Operations\Document\DocumentQueries($this->pdo))->summary((string)$s->fetchColumn());
    }
    private static function iso(?string $v): ?string { return $v===null?null:(new \DateTimeImmutable($v,new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); }
}
