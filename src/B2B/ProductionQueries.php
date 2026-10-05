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
        $s=$this->pdo->prepare('SELECT c.order_code,h.submitted_at,o.global_order_id,o.production_stage_id,
            o.production_changed_at,o.production_completed_at
            FROM b2b_orders c LEFT JOIN b2b_production_handoffs h ON h.b2b_order_uuid=c.order_uuid
            LEFT JOIN operational_orders o ON o.order_uuid=h.operational_order_uuid WHERE c.order_uuid=?');
        $s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new ApiException(404,'ORDER_NOT_FOUND','Order was not found.');
        if($row['submitted_at']===null) return ['submitted'=>false,'orderCode'=>$row['order_code']];
        try { $workflow=$this->workflows->current(); }
        catch(\RuntimeException $e) { throw new ApiException(503,'WORKFLOW_UNAVAILABLE','Canonical production workflow is unavailable.'); }
        foreach($workflow->stages as $stage) if($stage->id===$row['production_stage_id']) return [
            'submitted'=>true,'orderCode'=>$row['order_code'],'operationalOrderId'=>$row['global_order_id'],
            'submittedAt'=>self::iso($row['submitted_at']),'stageChangedAt'=>self::iso($row['production_changed_at']),
            'completedAt'=>self::iso($row['production_completed_at']),
            'workflow'=>$workflow->id.'@'.$workflow->version,'totalStages'=>count($workflow->stages),
            'stage'=>['id'=>$stage->id,'label'=>$stage->label,'ordinal'=>$stage->ordinal],
        ];
        throw new ApiException(503,'WORKFLOW_UNAVAILABLE','Canonical stage is unavailable.');
    }
    private static function iso(?string $v): ?string { return $v===null?null:(new \DateTimeImmutable($v,new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); }
}
