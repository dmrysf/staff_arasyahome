<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\QrReference;
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
    /**
     * Dataset of the workshop production sheet: only the canonical manufacturing snapshot (operational order, items,
     * frozen production context), the current stage and the active canonical QR reference. No money of any kind.
     */
    public function sheet(EmployeeIdentity $actor,string $id,\DateTimeImmutable $now): array
    {
        ProductionAccess::require($this->auth,$actor,ProductionAccess::VIEW);
        CompanyQueries::uuidOrNotFound($id,'ORDER_NOT_FOUND','Order was not found.');
        $this->pdo->beginTransaction();
        try {
            $s=$this->pdo->prepare('SELECT c.order_code,o.order_uuid,o.global_order_id,o.order_lookup_code,o.production_stage_id,o.production_notes,o.production_context
                FROM b2b_orders c LEFT JOIN b2b_production_handoffs h ON h.b2b_order_uuid=c.order_uuid
                LEFT JOIN operational_orders o ON o.order_uuid=h.operational_order_uuid WHERE c.order_uuid=?');
            $s->execute([$id]); $row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row) throw new ApiException(404,'ORDER_NOT_FOUND','Order was not found.');
            if($row['order_uuid']===null) throw new ApiException(409,'PRODUCTION_NOT_SUBMITTED','The order has not been submitted to production.');
            $s=$this->pdo->prepare('SELECT line_number,name,product_code,variant,color,width_value,height_value,meters,quantity,production_context
                FROM operational_order_items WHERE order_uuid=? ORDER BY line_number');
            $s->execute([$row['order_uuid']]);
            $items=array_map(static fn(array $i): array=>['lineNumber'=>(int)$i['line_number'],'name'=>$i['name'],'productCode'=>$i['product_code'],
                'variant'=>$i['variant'],'color'=>$i['color'],'width'=>$i['width_value'],'height'=>$i['height_value'],'meters'=>$i['meters'],
                'quantity'=>(int)$i['quantity'],'context'=>$i['production_context']===null?['kind'=>null]:json_decode($i['production_context'],true,16,JSON_THROW_ON_ERROR)],
                $s->fetchAll(PDO::FETCH_ASSOC));
            $s=$this->pdo->prepare("SELECT qr_reference FROM order_qr_references WHERE order_uuid=? AND status='active' ORDER BY created_at DESC LIMIT 1");
            $s->execute([$row['order_uuid']]); $qr=$s->fetchColumn();
            $this->pdo->commit();
        } catch(\Throwable $e) { if($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
        $status=$this->status($id);
        $projects=[];
        foreach($items as $item) if(isset($item['context']['project']['project']['code'])) $projects[$item['context']['project']['project']['code']]=$item['context']['project']['project'];
        $company=json_decode((string)$row['production_context'],true)['company']??['legalName'=>'','companyCode'=>'','countryCode'=>'','taxIdentifier'=>''];
        return ['orderCode'=>$row['order_code'],'operationalOrderId'=>$row['global_order_id'],'lookupCode'=>$row['order_lookup_code'],
            'company'=>$company,'productionNotes'=>$row['production_notes'],'projects'=>array_values($projects),
            'stage'=>$status['stage'],'totalStages'=>$status['totalStages'],'workflow'=>$status['workflow'],
            'qrPayload'=>is_string($qr)?QrReference::fromStored($qr)->payload():null,
            'generatedAt'=>$now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC','items'=>$items];
    }
    private static function iso(?string $v): ?string { return $v===null?null:(new \DateTimeImmutable($v,new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); }
}
