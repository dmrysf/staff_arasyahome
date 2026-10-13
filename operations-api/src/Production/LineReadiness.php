<?php

declare(strict_types=1);
namespace Arasya\Operations\Production;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\OperationalOrder;
use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Support\Uuid;
use PDO;

/** Product applicability inside curtain-production@1; never an alternate workflow or authority. */
final readonly class LineReadiness
{
    public const TYPES = ['custom_curtain','custom_drapery','ready_made'];
    public const MANUFACTURING = ['material-preparation','workshop-receiving','labeling','material-straightening','bottom-hem','side-hem','ironing','height','header-tape','sewing-finishing','quality-control'];
    public function __construct(private PDO $pdo, private string $mode = 'legacy') {}
    public static function type(OperationalOrderItem $item): ?string
    {
        $explicit=$item->productionContext['manufacturingType']??null;
        if (in_array($explicit,self::TYPES,true)) return $explicit;
        // These are already explicitly validated by the marketplace's preparation/release service.
        return match($item->productionContext['kind']??null) { 'curtain'=>'custom_curtain','drapery'=>'custom_drapery',default=>null };
    }
    public static function applicable(OperationalOrderItem $item, string $stage): bool
    {
        return $stage==='packing' || (in_array($stage,self::MANUFACTURING,true) && self::type($item)!=='ready_made');
    }
    public static function fingerprint(OperationalOrderItem $i): string
    {
        return hash('sha256',json_encode([$i->sourceItemId,$i->name,$i->productCode,$i->variant,$i->color,$i->widthValue,$i->heightValue,$i->measurementUnit,$i->meters,$i->quantity,$i->productionContext],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
    }
    public function enabled(OperationalOrder $order): bool
    {
        if ($order->globalId->sourceKey==='b2b') return false; // existing explicitly approved B2B operation model
        return $this->mode==='enforce' || count(array_filter($order->items,static fn($i)=>isset($i->productionContext['manufacturingType'])))>0;
    }
    public function validate(OperationalOrder $order): void
    {
        $ids=[];
        if ($order->items===[]) throw new ApiException(409,'PRODUCT_CLASSIFICATION_REQUIRED','Classify every required product before production.');
        foreach($order->items as $i) {
            $type=self::type($i);
            if ($type===null || isset($ids[$i->sourceItemId])) throw new ApiException(409,'PRODUCT_CLASSIFICATION_REQUIRED','Every line needs a unique identity and explicit manufacturing type.');
            $ids[$i->sourceItemId]=true;
            if ($type!=='ready_made' && (($i->widthValue??0)<=0 || ($i->heightValue??0)<=0 || !in_array($i->measurementUnit,['m','cm','mm'],true))) throw new ApiException(409,'PRODUCT_MEASUREMENTS_REQUIRED','Custom fabric needs positive width, height and a valid unit.');
        }
    }
    public function view(OperationalOrder $order): ?array
    {
        if (!$this->enabled($order)) return null;
        $q=$this->pdo->prepare('SELECT * FROM production_line_checks WHERE order_uuid=?');$q->execute([$order->orderUuid]);$checks=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r) $checks[$r['source_item_id']][$r['stage_id']]=$r;
        $lines=[];
        foreach($order->items as $i) {
            $done=[];$hash=self::fingerprint($i);
            foreach($checks[$i->sourceItemId]??[] as $stage=>$r) if(hash_equals($r['content_hash'],$hash)) $done[$stage]=(int)$r['completed_quantity'];
            $required=self::type($i)==='ready_made'?[]:self::MANUFACTURING;
            $ready=self::type($i)!==null;
            foreach($required as $stage) $ready=$ready && ($done[$stage]??0)===$i->quantity;
            $lines[]=['itemId'=>$i->itemUuid,'sourceItemId'=>$i->sourceItemId,'manufacturingType'=>self::type($i),'quantity'=>$i->quantity,
                'requiredStages'=>$required,'completedQuantities'=>$done,'readyForPacking'=>$ready,'packed'=>($done['packing']??0)===$i->quantity,
                'requiredAtCurrentStage'=>self::applicable($i,$order->productionStageId)];
        }
        $standardOnly=$lines!==[] && count(array_filter($lines,static fn($l)=>$l['manufacturingType']==='ready_made'))===count($lines);
        return ['mode'=>$this->mode,'lines'=>$lines,'readyForPacking'=>$lines!==[] && count(array_filter($lines,static fn($l)=>$l['readyForPacking']))===count($lines),
            'nextStageId'=>$standardOnly && in_array($order->productionStageId,['waiting',...self::MANUFACTURING],true)?'packing':null];
    }
    public function confirm(OperationalOrder $order,array $input,string $actor,string $request,string $now): void
    {
        if(!$this->enabled($order)) throw new ApiException(409,'LINE_CHECKS_UNAVAILABLE','This order has not opted into validated line routing.');
        $this->validate($order);
        if(!isset($input['items']) || !is_array($input['items']) || !array_is_list($input['items']) || count($input['items'])<1 || count($input['items'])>200) throw new ApiException(400,'INVALID_REQUEST','Choose the products and completed quantities.');
        $byId=[];foreach($order->items as $i) $byId[$i->itemUuid]=$i;
        $seen=[];
        foreach($input['items'] as $entry) {
            if(!is_array($entry) || array_diff(array_keys($entry),['itemId','completedQuantity'])!==[] || !is_string($entry['itemId']??null) || !is_int($entry['completedQuantity']??null)) throw new ApiException(400,'INVALID_REQUEST','Invalid product check.');
            $i=$byId[$entry['itemId']]??null;$quantity=$entry['completedQuantity'];
            if($i===null || isset($seen[$i->itemUuid]) || !self::applicable($i,$order->productionStageId) || $quantity<1 || $quantity>$i->quantity) throw new ApiException(422,'LINE_CHECK_INVALID','Choose an applicable product and a quantity within its order line.');
            $seen[$i->itemUuid]=true;
            if($order->productionStageId==='packing' && !$this->view($order)['readyForPacking']) throw new ApiException(409,'LINES_NOT_READY','Required manufacturing is unfinished.');
            $hash=self::fingerprint($i);
            $q=$this->pdo->prepare('SELECT completed_quantity,content_hash FROM production_line_checks WHERE order_uuid=? AND source_item_id=? AND stage_id=?');$q->execute([$order->orderUuid,$i->sourceItemId,$order->productionStageId]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if($old && $old['content_hash']===$hash && $quantity<(int)$old['completed_quantity']) throw new ApiException(409,'LINE_QUANTITY_REGRESSION','A completed quantity cannot decrease.');
            $this->pdo->prepare('INSERT INTO production_line_checks VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE content_hash=VALUES(content_hash),completed_quantity=VALUES(completed_quantity),employee_uuid=VALUES(employee_uuid),checked_at=VALUES(checked_at)')->execute([$order->orderUuid,$i->sourceItemId,$order->productionStageId,$hash,$quantity,$actor,$now]);
            $this->event($order->orderUuid,$i->sourceItemId,$order->productionStageId,'confirmed',$quantity,$hash,$actor,$order->productionVersion+1,$request,$now);
        }
    }
    public function requireCompleted(OperationalOrder $order): void
    {
        if(!$this->enabled($order)) return;
        $this->validate($order);$view=$this->view($order);
        if(in_array($order->productionStageId,['packing','delivery'],true) && !$view['readyForPacking']) throw new ApiException(409,'LINES_NOT_READY','Every required product must finish manufacturing before packing.');
        foreach($view['lines'] as $line) {
            $stage=$order->productionStageId==='delivery'?'packing':$order->productionStageId;
            $required=$stage==='packing' || in_array($stage,$line['requiredStages'],true);
            if($required && ($line['completedQuantities'][$stage]??0)!==$line['quantity']) throw new ApiException(409,'LINES_UNFINISHED','Check every required line and its full quantity before handover.');
        }
        // Before entering packing, validate ALL earlier operations, not merely quality control.
        if($order->productionStageId==='quality-control' && !$view['readyForPacking']) throw new ApiException(409,'LINES_NOT_READY','Some required manufacturing operations remain unfinished.');
    }
    public function invalidate(string $order,string $from,string $actor,int $version,string $request,string $now): void
    {
        $all=[...self::MANUFACTURING,'packing'];$index=array_search($from,$all,true);
        if($index===false) return;
        foreach(array_slice($all,$index) as $stage) {
            $this->pdo->prepare('DELETE FROM production_line_checks WHERE order_uuid=? AND stage_id=?')->execute([$order,$stage]);
            $this->event($order,null,$stage,'invalidated',null,null,$actor,$version,$request,$now);
        }
    }
    public function event(string $order,?string $line,string $stage,string $action,?int $quantity,?string $hash,string $actor,int $version,string $request,string $now): void
    {
        $this->pdo->prepare('INSERT INTO production_line_events VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([Uuid::v4(),$order,$line,$stage,$action,$quantity,$hash,$actor,$version,mb_substr($request,0,100),$now]);
    }
}
