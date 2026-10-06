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

/**
 * Project workspace commands. Every write locks the project row first, so all writers of one project are serialized
 * and the node version checks below read current data. Conversion locks company -> project, the same company-first
 * order the Classic order commands use, and creates an ordinary Classic draft through OrderStore and OrderCalculator.
 * Nothing here touches finalized orders, the current account ledger or production.
 */
final readonly class ProjectCommands
{
    public const MAX_OPERATIONS=500;
    public const MAX_COPIES=200;
    public const MAX_TARGETS=500;
    public const LIMITS=['zone'=>200,'room'=>2000,'opening'=>10000,'treatment'=>20000];
    public const CHILD_LIMITS=['opening'=>60,'treatment'=>12];

    private ProjectStore $store;
    private OrderStore $orders;
    public function __construct(private PDO $pdo,private AuthorizationService $authorization,private Clock $clock)
    {
        $this->store=new ProjectStore($pdo);
        $this->orders=new OrderStore($pdo);
    }

    public function create(EmployeeIdentity $actor,array $input,string $key,string $request): array
    {
        ProjectAccess::require($this->authorization,$actor,ProjectAccess::CREATE);
        $data=ProjectInput::project($input);
        return $this->transaction($actor,ProjectAccess::CREATE,'create',null,$data['companyId'],$input,$key,
            function(?array $project,string $now) use($actor,$data,$key,$request): array {
                $s=$this->pdo->prepare('SELECT status FROM b2b_companies WHERE company_uuid=? FOR UPDATE');
                $s->execute([$data['companyId']]);
                $status=$s->fetchColumn();
                if($status===false) throw new ApiException(404,'COMPANY_NOT_FOUND','Company was not found.');
                if($status!=='active') throw new ApiException(409,'COMPANY_INACTIVE','Company is inactive.');
                $this->pdo->prepare('INSERT INTO b2b_project_number_sequence(created_at) VALUES(?)')->execute([$now]);
                $number=(int)$this->pdo->lastInsertId();
                $id=Uuid::v4();
                $this->pdo->prepare('INSERT INTO b2b_projects(project_uuid,project_number,project_code,company_uuid,name,property_type,currency_code,
                    site_address,customer_reference,notes,created_at,updated_at,created_by_employee_uuid,updated_by_employee_uuid,created_by_name,updated_by_name)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$number,sprintf('B2B-PRJ-%06d',$number),$data['companyId'],$data['name'],
                    $data['propertyType'],$data['currencyCode'],$data['siteAddress'],$data['customerReference'],$data['notes'],$now,$now,
                    $actor->employeeUuid,$actor->employeeUuid,$actor->displayName,$actor->displayName]);
                $this->events([[ $id,'project',$id,'project_created',['fields'=>ProjectInput::PROJECT_FIELDS] ]],$actor,$request,$key,$now);
                return ['status'=>201,'projectId'=>$id];
            });
    }

    public function update(EmployeeIdentity $actor,string $id,array $input,mixed $expectedVersion,string $key,string $request): array
    {
        ProjectAccess::require($this->authorization,$actor,ProjectAccess::UPDATE);
        $version=OrderInput::version($expectedVersion);
        $data=ProjectInput::project($input);
        return $this->transaction($actor,ProjectAccess::UPDATE,'update',$id,null,$input+['expectedVersion'=>$version],$key,
            function(array $project,string $now) use($actor,$data,$version,$id,$key,$request): array {
                $this->editable($project);
                if((int)$project['version']!==$version) throw new ApiException(409,'PROJECT_CHANGED','Project changed. Reload before continuing.',['subject'=>'project','id'=>$id]);
                if($data['companyId']!==$project['company_uuid']) throw new ApiException(422,'VALIDATION_FAILED','Company is immutable.',['fields'=>['companyId'=>'immutable']]);
                if($data['currencyCode']!==$project['currency_code']) {
                    $s=$this->pdo->prepare('SELECT 1 FROM b2b_project_treatments WHERE project_uuid=? AND unit_price_net IS NOT NULL LIMIT 1');
                    $s->execute([$id]);
                    if($s->fetchColumn()!==false) throw new ApiException(409,'CURRENCY_CHANGE_REQUIRES_EMPTY_PRICING','Clear treatment prices first.');
                }
                $columns=['name'=>'name','propertyType'=>'property_type','currencyCode'=>'currency_code','siteAddress'=>'site_address',
                    'customerReference'=>'customer_reference','notes'=>'notes'];
                $changed=array_keys(array_filter($columns,static fn(string $c,string $f): bool=>$project[$c]!==$data[$f],ARRAY_FILTER_USE_BOTH));
                if($changed!==[]) {
                    $this->pdo->prepare('UPDATE b2b_projects SET name=?,property_type=?,currency_code=?,site_address=?,customer_reference=?,notes=?,
                        version=version+1,revision=revision+1,updated_at=?,updated_by_employee_uuid=?,updated_by_name=? WHERE project_uuid=?')->execute([
                        $data['name'],$data['propertyType'],$data['currencyCode'],$data['siteAddress'],$data['customerReference'],$data['notes'],
                        $now,$actor->employeeUuid,$actor->displayName,$id]);
                    $this->events([[ $id,'project',$id,'project_updated',['fields'=>$changed] ]],$actor,$request,$key,$now);
                }
                return ['status'=>200,'projectId'=>$id];
            });
    }

    /** draft -> active needs update; any move to or from archived needs archive. */
    public function status(EmployeeIdentity $actor,string $id,mixed $status,mixed $expectedVersion,string $key,string $request): array
    {
        if(!in_array($status,['active','archived'],true)) throw new ApiException(422,'VALIDATION_FAILED','Invalid status.',['fields'=>['status'=>'invalid']]);
        $permission=$status==='archived'?ProjectAccess::ARCHIVE:null;
        $version=OrderInput::version($expectedVersion);
        ProjectAccess::require($this->authorization,$actor,$permission??ProjectAccess::VIEW);
        return $this->transaction($actor,$permission??ProjectAccess::VIEW,'status',$id,null,['status'=>$status,'expectedVersion'=>$version],$key,
            function(array $project,string $now) use($actor,$status,$version,$id,$key,$request): array {
                ProjectAccess::require($this->authorization,$actor,$status==='archived'||$project['status']==='archived'?ProjectAccess::ARCHIVE:ProjectAccess::UPDATE);
                if((int)$project['version']!==$version) throw new ApiException(409,'PROJECT_CHANGED','Project changed. Reload before continuing.',['subject'=>'project','id'=>$id]);
                if($project['status']===$status) throw new ApiException(409,'PROJECT_STATUS_UNCHANGED','Project already has this status.');
                $this->pdo->prepare('UPDATE b2b_projects SET status=?,archived_at=?,version=version+1,revision=revision+1,updated_at=?,updated_by_employee_uuid=?,updated_by_name=? WHERE project_uuid=?')
                    ->execute([$status,$status==='archived'?$now:null,$now,$actor->employeeUuid,$actor->displayName,$id]);
                $this->events([[ $id,'project',$id,'project_status_changed',['from'=>$project['status'],'to'=>$status] ]],$actor,$request,$key,$now);
                return ['status'=>200,'projectId'=>$id];
            });
    }

    /**
     * Applies a list of workspace operations atomically: all or none. Each node edit carries its own expectedVersion,
     * so two employees editing different rooms never conflict, while a stale edit of the same node fails with
     * PROJECT_CHANGED. Copies are explicit, independent rows (copiedFromId is a trace only, never a live link).
     */
    public function changes(EmployeeIdentity $actor,string $id,mixed $operations,string $key,string $request): array
    {
        ProjectAccess::require($this->authorization,$actor,ProjectAccess::UPDATE);
        if(!is_array($operations) || !array_is_list($operations) || $operations===[] || count($operations)>self::MAX_OPERATIONS)
            throw new ApiException(422,'VALIDATION_FAILED','Between 1 and '.self::MAX_OPERATIONS.' operations are required.',['fields'=>['operations'=>'invalid']]);
        return $this->transaction($actor,ProjectAccess::UPDATE,'changes',$id,null,['operations'=>$operations],$key,
            function(array $project,string $now) use($actor,$operations,$id,$key,$request): array {
                $this->editable($project);
                $run=new ProjectChangeSet($this->pdo,$this->store,$id,$now);
                foreach($operations as $n=>$operation) $run->apply($n,$operation);
                $this->limits($id);
                $this->pdo->prepare('UPDATE b2b_projects SET revision=revision+1,updated_at=?,updated_by_employee_uuid=?,updated_by_name=? WHERE project_uuid=?')
                    ->execute([$now,$actor->employeeUuid,$actor->displayName,$id]);
                $this->events(array_map(static fn(array $e): array=>[$id,...$e],$run->events),$actor,$request,$key,$now);
                return ['status'=>200,'projectId'=>$id,'revision'=>(int)$project['revision']+1,'versions'=>(object)$run->versions,'created'=>(object)$run->created];
            });
    }

    /**
     * Creates one Classic commercial draft from the selected treatments, exactly once. The draft then follows the
     * unchanged Classic lifecycle (finalize -> receivable -> explicit production). A treatment already on a live
     * order line cannot be converted again; one project may still create many orders over time.
     */
    public function convert(EmployeeIdentity $actor,string $id,array $input,string $key,string $request): array
    {
        ProjectAccess::require($this->authorization,$actor,ProjectAccess::CONVERT);
        OrderAccess::require($this->authorization,$actor,OrderAccess::CREATE);
        if(array_diff(array_keys($input),['treatmentIds','expectedRevision'])!==[] || !array_key_exists('treatmentIds',$input) || !array_key_exists('expectedRevision',$input))
            throw new ApiException(400,'INVALID_REQUEST','Invalid request fields.');
        $revision=OrderInput::version($input['expectedRevision']);
        $ids=$input['treatmentIds'];
        if(!is_array($ids) || !array_is_list($ids) || $ids===[] || count($ids)>100 || count(array_unique($ids,SORT_REGULAR))!==count($ids) ||
            array_filter($ids,static fn($v): bool=>!is_string($v) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$v)!==1)!==[])
            throw new ApiException(422,'INVALID_PROJECT_SCOPE','Select between 1 and 100 distinct treatments.',['reason'=>$ids===[]?'empty':(is_array($ids)&&count($ids)>100?'too_many':'invalid')]);
        $s=$this->pdo->prepare('SELECT company_uuid FROM b2b_projects WHERE project_uuid=?');
        $s->execute([CompanyQueries::uuidOrNotFound($id,'PROJECT_NOT_FOUND','Project was not found.')]);
        $companyId=$s->fetchColumn();
        if($companyId===false) throw new ApiException(404,'PROJECT_NOT_FOUND','Project was not found.');
        return $this->transaction($actor,ProjectAccess::CONVERT,'convert',$id,$companyId,['treatmentIds'=>$ids,'expectedRevision'=>$revision],$key,
            function(array $project,string $now,?array $company) use($actor,$ids,$revision,$id,$key,$request): array {
                OrderAccess::require($this->authorization,$actor,OrderAccess::CREATE);
                $this->editable($project);
                if((int)$project['revision']!==$revision) throw new ApiException(409,'PROJECT_CHANGED','Project changed. Reload before continuing.',['subject'=>'project','id'=>$id]);
                $placeholders=implode(',',array_fill(0,count($ids),'?'));
                $s=$this->pdo->prepare("SELECT t.*,o.room_uuid,o.position AS opening_position,o.name AS opening_name,o.opening_type,o.wall_index,o.width AS opening_width,
                    o.height AS opening_height,o.sill_height,o.mounting,o.rail_type,r.zone_uuid,r.name AS room_name,r.position AS room_position,
                    z.name AS zone_name,z.zone_type,z.level_number,z.building,z.position AS zone_position
                    FROM b2b_project_treatments t JOIN b2b_project_openings o ON o.opening_uuid=t.opening_uuid
                    JOIN b2b_project_rooms r ON r.room_uuid=o.room_uuid JOIN b2b_project_zones z ON z.zone_uuid=r.zone_uuid
                    WHERE t.project_uuid=? AND t.treatment_uuid IN ($placeholders)
                    ORDER BY z.position,z.zone_uuid,r.position,r.room_uuid,o.position,o.opening_uuid,t.position,t.treatment_uuid");
                $s->execute([$id,...$ids]);
                $rows=$s->fetchAll(PDO::FETCH_ASSOC);
                if(count($rows)!==count($ids)) throw new ApiException(422,'INVALID_PROJECT_SCOPE','Some treatments do not belong to this project.',['reason'=>'unknown']);
                $ordered=$this->store->orderedTreatments($id,$ids);
                if($ordered!==[]) throw new ApiException(409,'ORDER_ALREADY_CREATED_FROM_SCOPE','Some treatments are already on a commercial order.',
                    ['treatmentIds'=>array_keys($ordered),'orderCodes'=>array_values(array_unique(array_column($ordered,'orderCode')))]);
                $lines=[]; $trace=[];
                foreach($rows as $row) {
                    $treatment=ProjectStore::node('treatment',$row);
                    $line=ProjectStore::line($treatment);
                    $line['id']=Uuid::v4();
                    $lines[]=$line;
                    $trace[]=[$line['id'],$row,[
                        'project'=>['id'=>$id,'code'=>$project['project_code'],'name'=>$project['name'],'propertyType'=>$project['property_type']],
                        'zone'=>['id'=>$row['zone_uuid'],'name'=>$row['zone_name'],'zoneType'=>$row['zone_type'],
                            'level'=>$row['level_number']===null?null:(int)$row['level_number'],'building'=>$row['building']],
                        'room'=>['id'=>$row['room_uuid'],'name'=>$row['room_name']],
                        'opening'=>['id'=>$row['opening_uuid'],'name'=>$row['opening_name'],'openingType'=>$row['opening_type'],
                            'wallIndex'=>$row['wall_index']===null?null:(int)$row['wall_index'],'width'=>$row['opening_width'],'height'=>$row['opening_height'],
                            'sillHeight'=>$row['sill_height'],'mounting'=>$row['mounting'],'railType'=>$row['rail_type']],
                        'treatment'=>['id'=>$row['treatment_uuid'],'treatmentType'=>$row['treatment_type'],'panelLayout'=>$row['panel_layout']],
                    ]];
                }
                // The Classic validator and calculator own the commercial meaning of every converted line.
                $data=OrderInput::calculation(['currencyCode'=>$project['currency_code'],'lines'=>$lines]);
                $data+=['companyId'=>$project['company_uuid'],'contactId'=>null,'billingAddressId'=>null,'deliveryAddressId'=>null,
                    'customerReference'=>$project['customer_reference'],'notes'=>null,'productionNotes'=>null];
                $orderId=$this->orders->insert($actor,$data,$this->orders->snapshots($company,$data),null,$now);
                $this->orders->event($orderId,$actor,'order_created',OrderInput::FIELDS,$request,$key,$now);
                $this->pdo->prepare('INSERT INTO b2b_project_orders(order_uuid,project_uuid,project_revision,created_by_employee_uuid,created_by_name,created_at,request_id) VALUES(?,?,?,?,?,?,?)')
                    ->execute([$orderId,$id,$revision,$actor->employeeUuid,$actor->displayName,$now,mb_substr($request,0,100)]);
                $insert=$this->pdo->prepare('INSERT INTO b2b_project_order_lines(line_uuid,order_uuid,project_uuid,zone_uuid,room_uuid,opening_uuid,treatment_uuid,trace_context) VALUES(?,?,?,?,?,?,?,?)');
                foreach($trace as [$lineId,$row,$context])
                    $insert->execute([$lineId,$orderId,$id,$row['zone_uuid'],$row['room_uuid'],$row['opening_uuid'],$row['treatment_uuid'],OrderStore::json($context)]);
                $this->pdo->prepare('UPDATE b2b_projects SET revision=revision+1,updated_at=?,updated_by_employee_uuid=?,updated_by_name=? WHERE project_uuid=?')
                    ->execute([$now,$actor->employeeUuid,$actor->displayName,$id]);
                $s=$this->pdo->prepare('SELECT order_code FROM b2b_orders WHERE order_uuid=?'); $s->execute([$orderId]);
                $this->events([[ $id,'order',$orderId,'order_created',['orderCode'=>$s->fetchColumn(),'lineCount'=>count($lines)] ]],$actor,$request,$key,$now);
                return ['status'=>201,'projectId'=>$id,'orderId'=>$orderId];
            });
    }

    private function editable(array $project): void
    {
        if($project['status']==='archived') throw new ApiException(409,'PROJECT_NOT_EDITABLE','Archived projects are read-only.');
    }

    private function limits(string $project): void
    {
        foreach(self::LIMITS as $level=>$limit) {
            $s=$this->pdo->prepare('SELECT COUNT(*) FROM '.ProjectStore::LEVELS[$level]['table'].' WHERE project_uuid=?');
            $s->execute([$project]);
            if((int)$s->fetchColumn()>$limit) throw new ApiException(422,'PROJECT_LIMIT_EXCEEDED','The project is too large.',['level'=>$level,'limit'=>$limit]);
        }
        foreach(self::CHILD_LIMITS as $level=>$limit) {
            $meta=ProjectStore::LEVELS[$level];
            $s=$this->pdo->prepare('SELECT COUNT(*) c FROM '.$meta['table'].' WHERE project_uuid=? GROUP BY '.$meta['parent'].' ORDER BY c DESC LIMIT 1');
            $s->execute([$project]);
            if((int)$s->fetchColumn()>$limit) throw new ApiException(422,'PROJECT_LIMIT_EXCEEDED','Too many children for one parent.',['level'=>$level,'limit'=>$limit]);
        }
    }

    /** @param list<array{0:string,1:string,2:string,3:string,4:array}> $events [project, subjectType, subjectId, action, details] */
    private function events(array $events,EmployeeIdentity $actor,string $request,string $key,string $now): void
    {
        foreach(array_chunk($events,200) as $chunk) {
            $values=[]; $params=[];
            foreach($chunk as [$project,$type,$subject,$action,$details]) {
                $values[]='(?,?,?,?,?,?,?,?,?,?,?)';
                array_push($params,Uuid::v4(),$project,$type,$subject,$actor->employeeUuid,$actor->displayName,$action,OrderStore::json((object)$details),
                    mb_substr($request,0,100),$key,$now);
            }
            $this->pdo->prepare('INSERT INTO b2b_project_activity_events(event_id,project_uuid,subject_type,subject_uuid,actor_employee_uuid,actor_name,action,details,request_id,idempotency_key,occurred_at) VALUES '.implode(',',$values))
                ->execute($params);
        }
    }

    private function transaction(EmployeeIdentity $actor,string $permission,string $operation,?string $id,?string $companyId,array $body,string $key,callable $work): array
    {
        if(!OrderOperationsService::isValidIdempotencyKey($key)) throw new ApiException(400,'INVALID_IDEMPOTENCY_KEY','A valid Idempotency-Key is required.');
        if($id!==null) CompanyQueries::uuidOrNotFound($id,'PROJECT_NOT_FOUND','Project was not found.');
        $hash=hash('sha256','project_'.$operation.'|'.($id??'').'|'.OrderStore::json(self::canonical($body)),true);
        for($attempt=1;;$attempt++) {
            try {
                $this->pdo->beginTransaction();
                ProjectAccess::require($this->authorization,$actor,$permission);
                $company=null;
                if($operation==='convert') {
                    $s=$this->pdo->prepare('SELECT * FROM b2b_companies WHERE company_uuid=? FOR UPDATE');
                    $s->execute([$companyId]); $company=$s->fetch(PDO::FETCH_ASSOC);
                    if(!$company) throw new ApiException(404,'COMPANY_NOT_FOUND','Company was not found.');
                }
                $project=null;
                if($id!==null) {
                    $project=$this->store->project($id,true);
                    if($project===null) throw new ApiException(404,'PROJECT_NOT_FOUND','Project was not found.');
                    if($operation==='convert' && $project['company_uuid']!==$companyId) throw new ApiException(409,'PROJECT_CHANGED','Project changed. Reload before continuing.');
                }
                $s=$this->pdo->prepare('SELECT operation,request_hash,response_json FROM b2b_project_idempotency WHERE employee_uuid=? AND idempotency_key=? FOR UPDATE');
                $s->execute([$actor->employeeUuid,$key]); $replay=$s->fetch(PDO::FETCH_ASSOC);
                if($replay) {
                    if($replay['operation']!=='project_'.$operation || !hash_equals($replay['request_hash'],$hash))
                        throw new ApiException(409,'IDEMPOTENCY_CONFLICT','Key was used for another request.');
                    $this->pdo->rollBack();
                    // JSON maps keyed by operation index decode as lists; a replay must keep the original shape.
                    $result=OrderStore::decode($replay['response_json']);
                    foreach(['versions','created'] as $map) if(array_key_exists($map,$result)) $result[$map]=(object)$result[$map];
                    return $result;
                }
                $now=$this->clock->now()->format('Y-m-d H:i:s.u');
                $result=$work($project,$now,$company);
                $this->pdo->prepare('INSERT INTO b2b_project_idempotency(employee_uuid,idempotency_key,operation,request_hash,project_uuid,response_json,created_at) VALUES(?,?,?,?,?,?,?)')
                    ->execute([$actor->employeeUuid,$key,'project_'.$operation,$hash,$result['projectId'],OrderStore::json($result),$now]);
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
