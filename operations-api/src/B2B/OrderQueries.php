<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final readonly class OrderQueries
{
    private OrderStore $store;
    public function __construct(private PDO $pdo,private AuthorizationService $authorization) { $this->store=new OrderStore($pdo); }

    public function list(EmployeeIdentity $actor,array $filters): array
    {
        OrderAccess::require($this->authorization,$actor,OrderAccess::VIEW);
        $limit=$this->limit($filters);
        $where=[]; $params=[];
        foreach(['status'=>['draft','finalized','cancelled','all'],'currency'=>['RON','EUR','all']] as $field=>$choices) {
            $value=$filters[$field]??'all';
            if(!in_array($value,$choices,true)) $this->invalid($field);
            if($value!=='all') { $where[]='o.'.($field==='status'?'status':'currency_code').'=?'; $params[]=$value; }
        }
        if(($filters['companyId']??'')!=='') {
            $where[]='o.company_uuid=?'; $params[]=CompanyQueries::uuidOrNotFound($filters['companyId'],'COMPANY_NOT_FOUND','Company was not found.');
        }
        foreach(['from'=>'>=','to'=>'<'] as $field=>$operator) {
            if(($filters[$field]??'')==='') continue;
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$filters[$field],new DateTimeZone('UTC'));
            if(!$date || $date->format('Y-m-d')!==$filters[$field]) $this->invalid($field);
            if($field==='to') $date=$date->modify('+1 day');
            $where[]='o.created_at'.$operator.'?'; $params[]=$date->format('Y-m-d H:i:s');
        }
        if(($filters['from']??'')!=='' && ($filters['to']??'')!=='' && $filters['from']>$filters['to']) $this->invalid('to');
        $search=trim($filters['search']??'');
        if(mb_strlen($search)>100) $this->invalid('search');
        if($search!=='') {
            $like='%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $prefix=strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $where[]="(o.order_code LIKE ? ESCAPE '!' OR o.customer_reference LIKE ? ESCAPE '!'
                OR JSON_UNQUOTE(JSON_EXTRACT(o.company_snapshot,'$.legalName')) LIKE ? ESCAPE '!'
                OR JSON_UNQUOTE(JSON_EXTRACT(o.company_snapshot,'$.companyCode')) LIKE ? ESCAPE '!'
                OR JSON_UNQUOTE(JSON_EXTRACT(o.company_snapshot,'$.taxIdentifier')) LIKE ? ESCAPE '!'
                OR EXISTS(SELECT 1 FROM b2b_order_lines l WHERE l.product_code LIKE ? ESCAPE '!' AND l.order_uuid=o.order_uuid))";
            array_push($params,$like,$like,$like,$like,$like,$prefix);
        }
        if(($filters['cursor']??'')!=='') {
            [$time,$id]=$this->cursor($filters['cursor']);
            $where[]='(o.created_at<? OR (o.created_at=? AND o.order_uuid<?))';
            array_push($params,$time,$time,$id);
        }
        $s=$this->pdo->prepare('SELECT o.* FROM b2b_orders o'.($where?' WHERE '.implode(' AND ',$where):'').
            ' ORDER BY o.created_at DESC,o.order_uuid DESC LIMIT '.($limit+1));
        $s->execute($params); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        $next=$this->next($rows,$limit,'created_at','order_uuid');
        return [
            'items'=>array_map(fn($r)=>[
                'id'=>$r['order_uuid'],'code'=>$r['order_code'],'companyId'=>$r['company_uuid'],
                'companyName'=>OrderStore::decode($r['company_snapshot'])['legalName'],
                'currencyCode'=>$r['currency_code'],'status'=>$r['status'],'version'=>(int)$r['version'],
                'customerReference'=>$r['customer_reference'],'createdAt'=>self::iso($r['created_at']),'updatedAt'=>self::iso($r['updated_at']),
                'createdBy'=>['id'=>$r['created_by_employee_uuid'],'displayName'=>$r['created_by_name']],
                'totals'=>OrderStore::decode($r['calculation'])['totals'],
            ],$rows),
            'nextCursor'=>$next,'capabilities'=>OrderAccess::capabilities($this->authorization,$actor),
        ];
    }

    public function detail(EmployeeIdentity $actor,string $id): array
    {
        OrderAccess::require($this->authorization,$actor,OrderAccess::VIEW);
        CompanyQueries::uuidOrNotFound($id,'ORDER_NOT_FOUND','Order was not found.');
        // The header, lines and calculation must belong to one committed aggregate version.
        $this->pdo->beginTransaction();
        try {
            $s=$this->pdo->prepare('SELECT o.*,EXISTS(SELECT 1 FROM b2b_production_handoffs h WHERE h.b2b_order_uuid=o.order_uuid) AS production_submitted FROM b2b_orders o WHERE o.order_uuid=?');
            $s->execute([$id]); $r=$s->fetch(PDO::FETCH_ASSOC);
            if(!$r) throw new ApiException(404,'ORDER_NOT_FOUND','Order was not found.');
            $order=$this->store->fields($r)+[
                'id'=>$id,'code'=>$r['order_code'],'status'=>$r['status'],'version'=>(int)$r['version'],
                'sourceOrderId'=>$r['source_order_uuid'],'createdAt'=>self::iso($r['created_at']),'updatedAt'=>self::iso($r['updated_at']),
                'finalizedAt'=>self::iso($r['finalized_at']),'cancelledAt'=>self::iso($r['cancelled_at']),
                'createdBy'=>['id'=>$r['created_by_employee_uuid'],'displayName'=>$r['created_by_name']],
                'updatedBy'=>['id'=>$r['updated_by_employee_uuid'],'displayName'=>$r['updated_by_name']],
                'finalizedBy'=>$r['finalized_by_employee_uuid']===null?null:['id'=>$r['finalized_by_employee_uuid'],'displayName'=>$r['finalized_by_name']],
                'calculation'=>OrderStore::decode($r['calculation']),
                'productionSubmitted'=>(bool)$r['production_submitted'],
            ];
            foreach(OrderStore::SNAPSHOT_COLUMNS as $field=>$column) $order[$field]=OrderStore::decode($r[$column]);
            $order['origin']=$this->origin($id);
            $this->pdo->commit();
            $capabilities=OrderAccess::capabilities($this->authorization,$actor);
            if($order['productionSubmitted']) $capabilities['canCancel']=false;
            return ['order'=>$order,'capabilities'=>$capabilities];
        } catch(Throwable $e) { if($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    /** Project-origin trace of an order: the source project and the frozen location of each converted line. */
    private function origin(string $id): ?array
    {
        $s=$this->pdo->prepare('SELECT po.project_uuid,p.project_code,p.name FROM b2b_project_orders po JOIN b2b_projects p ON p.project_uuid=po.project_uuid WHERE po.order_uuid=?');
        $s->execute([$id]); $project=$s->fetch(PDO::FETCH_ASSOC);
        if(!$project) return null;
        $s=$this->pdo->prepare('SELECT line_uuid,trace_context FROM b2b_project_order_lines WHERE order_uuid=?'); $s->execute([$id]);
        $lines=[];
        foreach($s->fetchAll(PDO::FETCH_KEY_PAIR) as $line=>$json) {
            $context=ProductionInput::project(OrderStore::decode($json));
            unset($context['project']);
            $lines[$line]=$context;
        }
        return ['projectId'=>$project['project_uuid'],'projectCode'=>$project['project_code'],'projectName'=>$project['name'],'lines'=>(object)$lines];
    }

    public function activity(EmployeeIdentity $actor,string $id,array $filters): array
    {
        OrderAccess::require($this->authorization,$actor,OrderAccess::VIEW);
        CompanyQueries::uuidOrNotFound($id,'ORDER_NOT_FOUND','Order was not found.');
        $s=$this->pdo->prepare('SELECT 1 FROM b2b_orders WHERE order_uuid=?'); $s->execute([$id]);
        if($s->fetchColumn()===false) throw new ApiException(404,'ORDER_NOT_FOUND','Order was not found.');
        $limit=$this->limit($filters); $where='order_uuid=?'; $params=[$id];
        if(($filters['cursor']??'')!=='') {
            [$time,$uuid]=$this->cursor($filters['cursor']);
            $where.=' AND (occurred_at<? OR (occurred_at=? AND event_id<?))';
            array_push($params,$time,$time,$uuid);
        }
        $s=$this->pdo->prepare('SELECT * FROM b2b_order_activity_events WHERE '.$where.' ORDER BY occurred_at DESC,event_id DESC LIMIT '.($limit+1));
        $s->execute($params); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        $next=$this->next($rows,$limit,'occurred_at','event_id');
        return ['items'=>array_map(fn($r)=>[
            'id'=>$r['event_id'],'action'=>$r['action'],
            'subject'=>['type'=>$r['line_uuid']===null?'order':'line','id'=>$r['line_uuid']??$id],
            'changedFields'=>OrderStore::decode($r['changed_fields']),
            'actor'=>['id'=>$r['actor_employee_uuid'],'displayName'=>$r['actor_name']],
            'requestId'=>$r['request_id'],'occurredAt'=>self::iso($r['occurred_at']),
        ],$rows),'nextCursor'=>$next];
    }

    private function limit(array $filters): int
    {
        $raw=$filters['limit']??'50';
        if(!in_array((string)$raw,['25','50','100'],true)) $this->invalid('limit');
        return (int)$raw;
    }
    private function invalid(string $field): never
    {
        throw new ApiException(422,'VALIDATION_FAILED','Invalid filter.',['fields'=>[$field=>'invalid']]);
    }
    private function cursor(string $value): array
    {
        if(strlen($value)>300) throw new ApiException(400,'INVALID_CURSOR','Invalid cursor.');
        $raw=base64_decode(strtr($value,'-_','+/'),true);
        $cursor=$raw===false ? null : json_decode($raw,true);
        if(!is_array($cursor) || count($cursor)!==2 || !isset($cursor['at'],$cursor['id']) ||
            !is_string($cursor['at']) || !is_string($cursor['id']) ||
            !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D',$cursor['at']) ||
            !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D',$cursor['id']))
            throw new ApiException(400,'INVALID_CURSOR','Invalid cursor.');
        return [$cursor['at'],$cursor['id']];
    }
    private function next(array &$rows,int $limit,string $time,string $uuid): ?string
    {
        if(count($rows)<=$limit) return null;
        array_pop($rows); $last=$rows[count($rows)-1];
        return rtrim(strtr(base64_encode(OrderStore::json(['at'=>$last[$time],'id'=>$last[$uuid]])),'+/','-_'),'=');
    }
    private static function iso(?string $time): ?string
    {
        return $time===null?null:(new DateTimeImmutable($time,new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
