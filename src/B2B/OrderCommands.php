<?php
declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\OrderOperationsService;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;
use Throwable;

/** Company -> aggregate lock order matches Companies V1. Every mutation includes an actor-scoped replay. */
final readonly class OrderCommands
{
    private OrderStore $store;
    public function __construct(private PDO $pdo,private AuthorizationService $authorization,private Clock $clock)
    {
        $this->store=new OrderStore($pdo);
    }

    public function create(EmployeeIdentity $actor,array $input,string $key,string $request): array
    {
        $this->authorize($actor,'create');
        $data=OrderInput::fields($input);
        return $this->transaction($actor,'create',null,$data['companyId'],$input,$key,
            function($company,$order,$now) use($actor,$data,$key,$request) {
                $data=$this->identities($data,[]);
                $id=$this->store->insert($actor,$data,$this->store->snapshots($company,$data),null,$now);
                $this->store->event($id,$actor,'order_created',OrderInput::FIELDS,$request,$key,$now);
                return ['status'=>201,'orderId'=>$id];
            });
    }

    public function update(EmployeeIdentity $actor,string $id,array $input,mixed $expectedVersion,string $key,string $request): array
    {
        $this->authorize($actor,'update');
        $version=OrderInput::version($expectedVersion);
        $data=OrderInput::fields($input);
        return $this->transaction($actor,'update',$id,null,$input+['expectedVersion'=>$version],$key,
            function($company,$order,$now) use($actor,$data,$version,$id,$key,$request) {
                $this->editable($order,$version);
                $before=$this->store->fields($order);
                if($data['companyId']!==$before['companyId']) throw new ApiException(422,'VALIDATION_FAILED','Company is immutable.',['fields'=>['companyId'=>'immutable']]);
                if($data['currencyCode']!==$before['currencyCode']) {
                    foreach([...$before['lines'],...$data['lines']] as $line) {
                        if($line['unitPriceNet']!==null) throw new ApiException(409,'CURRENCY_CHANGE_REQUIRES_EMPTY_PRICING','Save cleared prices first.');
                    }
                }
                $data=$this->identities($data,$before['lines']);
                $snap=$this->store->snapshots($company,$data);
                $changed=[];
                foreach($before as $field=>$value) {
                    $different=$field==='lines' ? !self::sameLines($value,$data['lines']) : $value!==$data[$field];
                    if($different) $changed[]=$field;
                }
                $this->store->save($id,$data,$snap,$actor,$now);
                $this->lineEvents($id,$before['lines'],$data['lines'],$actor,$request,$key,$now);
                $this->store->event($id,$actor,'order_updated',$changed,$request,$key,$now);
                return ['status'=>200,'orderId'=>$id];
            });
    }

    public function status(EmployeeIdentity $actor,string $id,string $action,mixed $expectedVersion,string $key,string $request): array
    {
        $this->authorize($actor,$action);
        $version=OrderInput::version($expectedVersion);
        return $this->transaction($actor,$action,$id,null,['expectedVersion'=>$version],$key,
            function($company,$order,$now) use($actor,$action,$version,$id,$key,$request) {
                $this->version($order,$version);
                if($action==='finalize') {
                    $this->editable($order,$version);
                    $data=$this->store->fields($order);
                    OrderCalculator::requireComplete($data['lines']);
                    $snap=$this->store->snapshots($company,$data);
                    // Refresh identity snapshots at freeze time, then permanently stop commercial writes.
                    $this->store->save($id,$data,$snap,$actor,$now);
                    $this->pdo->prepare("UPDATE b2b_orders SET status='finalized',finalized_at=?,finalized_by_employee_uuid=?,finalized_by_name=? WHERE order_uuid=?")
                        ->execute([$now,$actor->employeeUuid,$actor->displayName,$id]);
                } else {
                    if($order['status']==='cancelled') throw new ApiException(409,'ORDER_CANCELLED','Order is cancelled.');
                    $this->pdo->prepare("UPDATE b2b_orders SET status='cancelled',cancelled_at=?,version=version+1,updated_at=?,updated_by_employee_uuid=?,updated_by_name=? WHERE order_uuid=?")
                        ->execute([$now,$now,$actor->employeeUuid,$actor->displayName,$id]);
                }
                $this->store->event($id,$actor,$action==='finalize'?'order_finalized':'order_cancelled',['status'],$request,$key,$now);
                return ['status'=>200,'orderId'=>$id];
            });
    }

    public function duplicate(EmployeeIdentity $actor,string $id,mixed $expectedVersion,string $key,string $request): array
    {
        $this->authorize($actor,'duplicate');
        $version=OrderInput::version($expectedVersion);
        return $this->transaction($actor,'duplicate',$id,null,['expectedVersion'=>$version],$key,
            function($company,$order,$now) use($actor,$version,$id,$key,$request) {
                $this->version($order,$version);
                $data=$this->store->fields($order);
                foreach($data['lines'] as &$line) $line['id']=Uuid::v4();
                unset($line);
                $snap=$this->store->snapshots($company,$data,true);
                foreach(['contactId'=>'contactSnapshot','billingAddressId'=>'billingAddressSnapshot','deliveryAddressId'=>'deliveryAddressSnapshot'] as $field=>$target)
                    if($snap[$target]===null) $data[$field]=null;
                $new=$this->store->insert($actor,$data,$snap,$id,$now);
                $this->store->event($new,$actor,'order_duplicated',OrderInput::FIELDS,$request,$key,$now);
                return ['status'=>201,'orderId'=>$new];
            });
    }

    public function line(EmployeeIdentity $actor,string $id,string $action,?string $lineId,array $input,mixed $expectedVersion,string $key,string $request): array
    {
        $this->authorize($actor,'update');
        $version=OrderInput::version($expectedVersion);
        $operation='line_'.$action;
        return $this->transaction($actor,$operation,$id,null,['lineId'=>$lineId,'input'=>$input,'expectedVersion'=>$version],$key,
            function($company,$order,$now) use($actor,$version,$id,$lineId,$action,$input,$key,$request) {
                $this->editable($order,$version);
                $data=$this->store->fields($order);
                $lines=$data['lines'];
                $index=$lineId===null ? false : array_search($lineId,array_column($lines,'id'),true);
                if($lineId!==null && $index===false) throw new ApiException(404,'ORDER_LINE_NOT_FOUND','Line was not found.');
                $changed=OrderInput::LINE_FIELDS;
                switch($action) {
                    case 'create':
                        $line=OrderInput::line($input);
                        if($line['id']!==null) throw new ApiException(400,'INVALID_REQUEST','Line identity is server generated.');
                        $lineId=$line['id']=Uuid::v4();
                        $lines[]=$line;
                        break;
                    case 'update':
                        $line=OrderInput::line($input);
                        if($line['id']!==null && $line['id']!==$lineId) throw new ApiException(400,'INVALID_REQUEST','Line identity is immutable.');
                        $line['id']=$lineId;
                        $changed=self::lineChanges($lines[$index],$line);
                        $lines[$index]=$line;
                        break;
                    case 'duplicate':
                        $line=$lines[$index];
                        $lineId=$line['id']=Uuid::v4();
                        array_splice($lines,$index+1,0,[$line]);
                        break;
                    case 'remove':
                        array_splice($lines,$index,1);
                        $changed=['lines'];
                        break;
                    case 'reorder':
                        $ids=$input['lineIds']??null;
                        if(array_keys($input)!==['lineIds'] || !is_array($ids) || !array_is_list($ids) ||
                            count($ids)!==count($lines) || count(array_unique($ids,SORT_REGULAR))!==count($ids) ||
                            array_diff($ids,array_column($lines,'id'))!==[]) throw new ApiException(422,'VALIDATION_FAILED','Invalid line ordering.',['fields'=>['lineIds'=>'invalid']]);
                        $byId=array_column($lines,null,'id');
                        $lines=array_map(static fn($uuid)=>$byId[$uuid],$ids);
                        $changed=['position'];
                        break;
                    default: throw new ApiException(400,'INVALID_REQUEST','Invalid line action.');
                }
                if(count($lines)>100) throw new ApiException(422,'VALIDATION_FAILED','Maximum 100 lines.',['fields'=>['lines'=>'too_long']]);
                $data['lines']=$lines;
                // Reuse existing draft snapshots. Finalize revalidates active company and selected records.
                $snap=[];
                foreach(OrderStore::SNAPSHOT_COLUMNS as $field=>$column) $snap[$field]=OrderStore::decode($order[$column]);
                $this->store->save($id,$data,$snap,$actor,$now);
                $event=match($action){'create'=>'line_created','update'=>'line_updated','duplicate'=>'line_duplicated','remove'=>'line_removed','reorder'=>'lines_reordered'};
                // An explicit line update whose fields all match the stored line still saves the draft,
                // but it records no line_updated event: the history lists only real business changes.
                if($action!=='update' || $changed!==[]) $this->store->event($id,$actor,$event,$changed,$request,$key,$now,$lineId);
                return ['status'=>200,'orderId'=>$id,'lineId'=>$lineId];
            });
    }

    private function identities(array $data,array $old): array
    {
        $existing=array_column($old,null,'id');
        foreach($data['lines'] as &$line) {
            if($line['id']===null) $line['id']=Uuid::v4();
            elseif(!isset($existing[$line['id']])) {
                // A client can preallocate a new UUID to keep keyboard rows stable across explicit draft saves.
                $s=$this->pdo->prepare('SELECT order_uuid FROM b2b_order_lines WHERE line_uuid=?');
                $s->execute([$line['id']]);
                if($s->fetchColumn()!==false) throw new ApiException(422,'VALIDATION_FAILED','Invalid line identity.',['fields'=>['lines'=>'invalid']]);
            }
        }
        unset($line);
        return $data;
    }

    private function lineEvents(string $id,array $before,array $after,EmployeeIdentity $actor,string $request,string $key,string $now): void
    {
        $old=array_column($before,null,'id'); $new=array_column($after,null,'id');
        foreach($new as $uuid=>$line) {
            if(!isset($old[$uuid])) $this->store->event($id,$actor,'line_created',OrderInput::LINE_FIELDS,$request,$key,$now,$uuid);
            elseif(($changed=self::lineChanges($old[$uuid],$line))!==[]) $this->store->event($id,$actor,'line_updated',$changed,$request,$key,$now,$uuid);
        }
        foreach(array_diff_key($old,$new) as $uuid=>$line) $this->store->event($id,$actor,'line_removed',['lines'],$request,$key,$now,$uuid);
        if(array_keys($old)!==array_keys($new)) $this->store->event($id,$actor,'lines_reordered',['position'],$request,$key,$now);
    }

    /**
     * Business fields that differ between a stored line and its new value. Compared field by field, so the
     * array key order of a stored row versus a normalized request never reads as a change.
     * @return list<string>
     */
    private static function lineChanges(array $before,array $after): array
    {
        return array_values(array_filter(OrderInput::LINE_FIELDS,static fn(string $f): bool=>($before[$f]??null)!==($after[$f]??null)));
    }

    /** Same line identities in the same order, with no business field changed. */
    private static function sameLines(array $before,array $after): bool
    {
        if(array_column($before,'id')!==array_column($after,'id')) return false;
        foreach($before as $index=>$line) if(self::lineChanges($line,$after[$index])!==[]) return false;
        return true;
    }

    private function authorize(EmployeeIdentity $actor,string $operation): void
    {
        OrderAccess::require($this->authorization,$actor,match($operation) {
            'create','duplicate'=>OrderAccess::CREATE,
            'finalize','cancel'=>OrderAccess::FINALIZE,
            default=>OrderAccess::UPDATE,
        });
        if($operation==='duplicate') OrderAccess::require($this->authorization,$actor,OrderAccess::VIEW);
    }
    private function version(array $order,int $version): void
    {
        if((int)$order['version']!==$version) throw new ApiException(409,'ORDER_CHANGED','Order changed. Reload before continuing.');
    }
    private function editable(array $order,int $version): void
    {
        $this->version($order,$version);
        if($order['status']!=='draft') throw new ApiException(409,$order['status']==='finalized'?'ORDER_FINALIZED':'ORDER_CANCELLED','Order is immutable.');
    }

    private function transaction(EmployeeIdentity $actor,string $operation,?string $id,?string $companyId,array $body,string $key,callable $work): array
    {
        if(!OrderOperationsService::isValidIdempotencyKey($key)) throw new ApiException(400,'INVALID_IDEMPOTENCY_KEY','A valid Idempotency-Key is required.');
        if($id!==null) {
            CompanyQueries::uuidOrNotFound($id,'ORDER_NOT_FOUND','Order was not found.');
            $s=$this->pdo->prepare('SELECT company_uuid FROM b2b_orders WHERE order_uuid=?');
            $s->execute([$id]); $companyId=$s->fetchColumn();
            if($companyId===false) throw new ApiException(404,'ORDER_NOT_FOUND','Order was not found.');
        }
        $hash=hash('sha256',$operation.'|'.($id??'').'|'.OrderStore::json(self::canonical($body)),true);
        for($attempt=1;;$attempt++) {
            try {
                $this->pdo->beginTransaction();
                $this->authorize($actor,$operation);
                $s=$this->pdo->prepare('SELECT * FROM b2b_companies WHERE company_uuid=? FOR UPDATE');
                $s->execute([$companyId]); $company=$s->fetch(PDO::FETCH_ASSOC);
                if(!$company) throw new ApiException(404,'COMPANY_NOT_FOUND','Company was not found.');
                $order=null;
                if($id!==null) {
                    $s=$this->pdo->prepare('SELECT * FROM b2b_orders WHERE order_uuid=? FOR UPDATE');
                    $s->execute([$id]); $order=$s->fetch(PDO::FETCH_ASSOC);
                    if(!$order) throw new ApiException(404,'ORDER_NOT_FOUND','Order was not found.');
                }
                $s=$this->pdo->prepare('SELECT operation,request_hash,response_json FROM b2b_order_idempotency WHERE employee_uuid=? AND idempotency_key=? FOR UPDATE');
                $s->execute([$actor->employeeUuid,$key]); $replay=$s->fetch(PDO::FETCH_ASSOC);
                if($replay) {
                    if($replay['operation']!==$operation || !hash_equals($replay['request_hash'],$hash))
                        throw new ApiException(409,'IDEMPOTENCY_CONFLICT','Key was used for another request.');
                    $this->pdo->rollBack();
                    return OrderStore::decode($replay['response_json']);
                }
                $now=$this->clock->now()->format('Y-m-d H:i:s.u');
                $result=$work($company,$order,$now);
                $this->pdo->prepare('INSERT INTO b2b_order_idempotency(employee_uuid,idempotency_key,operation,request_hash,order_uuid,response_json,created_at) VALUES(?,?,?,?,?,?,?)')
                    ->execute([$actor->employeeUuid,$key,$operation,$hash,$result['orderId'],OrderStore::json($result),$now]);
                $this->pdo->commit();
                return $result;
            } catch(Throwable $e) {
                if($this->pdo->inTransaction()) $this->pdo->rollBack();
                if($e instanceof PDOException && $attempt<3 && in_array((int)($e->errorInfo[1]??0),[1062,1205,1213],true)) continue;
                throw $e;
            }
        }
    }
    private static function canonical(mixed $value): mixed
    {
        if(!is_array($value)) return $value;
        if(!array_is_list($value)) ksort($value);
        return array_map(self::canonical(...),$value);
    }
}
