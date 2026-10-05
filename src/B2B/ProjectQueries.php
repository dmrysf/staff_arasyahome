<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Read models of the project workspace. The outline loads zones and room summaries (no openings/treatments), a room
 * is loaded on demand, and the scene, commercial projection and proposal read the whole tree with one query per level.
 * Every multi-query read runs in one transaction so it describes a single committed revision.
 */
final readonly class ProjectQueries
{
    public const SCENE_SCHEMA='arasya.scene/1';
    private ProjectStore $store;
    public function __construct(private PDO $pdo,private AuthorizationService $authorization,private Clock $clock) { $this->store=new ProjectStore($pdo); }

    public function list(EmployeeIdentity $actor,array $filters): array
    {
        ProjectAccess::require($this->authorization,$actor,ProjectAccess::VIEW);
        $raw=$filters['limit']??'25';
        if(!in_array((string)$raw,['25','50','100'],true)) $this->invalid('limit');
        $limit=(int)$raw; $where=[]; $params=[];
        $status=$filters['status']??'open';
        if(!in_array($status,['open','draft','active','archived','all'],true)) $this->invalid('status');
        if($status==='open') $where[]="p.status<>'archived'";
        elseif($status!=='all') { $where[]='p.status=?'; $params[]=$status; }
        if(($filters['companyId']??'')!=='') { $where[]='p.company_uuid=?'; $params[]=CompanyQueries::uuidOrNotFound($filters['companyId'],'COMPANY_NOT_FOUND','Company was not found.'); }
        $search=trim($filters['search']??'');
        if(mb_strlen($search)>100) $this->invalid('search');
        if($search!=='') {
            $like='%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $where[]="(p.project_code LIKE ? ESCAPE '!' OR p.name LIKE ? ESCAPE '!' OR p.customer_reference LIKE ? ESCAPE '!' OR c.legal_name LIKE ? ESCAPE '!' OR c.company_code LIKE ? ESCAPE '!')";
            array_push($params,$like,$like,$like,$like,$like);
        }
        if(($filters['cursor']??'')!=='') {
            [$time,$id]=$this->cursor($filters['cursor']);
            $where[]='(p.updated_at<? OR (p.updated_at=? AND p.project_uuid<?))';
            array_push($params,$time,$time,$id);
        }
        $s=$this->pdo->prepare('SELECT p.*,c.legal_name,c.company_code,
            (SELECT COUNT(*) FROM b2b_project_rooms r WHERE r.project_uuid=p.project_uuid) AS room_count,
            (SELECT COUNT(*) FROM b2b_project_openings o WHERE o.project_uuid=p.project_uuid) AS opening_count
            FROM b2b_projects p JOIN b2b_companies c ON c.company_uuid=p.company_uuid'.($where?' WHERE '.implode(' AND ',$where):'').
            ' ORDER BY p.updated_at DESC,p.project_uuid DESC LIMIT '.($limit+1));
        $s->execute($params); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        $next=null;
        if(count($rows)>$limit) {
            array_pop($rows); $last=$rows[count($rows)-1];
            $next=rtrim(strtr(base64_encode(OrderStore::json(['at'=>$last['updated_at'],'id'=>$last['project_uuid']])),'+/','-_'),'=');
        }
        return ['items'=>array_map(fn(array $r): array=>[
            'id'=>$r['project_uuid'],'code'=>$r['project_code'],'name'=>$r['name'],'propertyType'=>$r['property_type'],'status'=>$r['status'],
            'currencyCode'=>$r['currency_code'],'company'=>['id'=>$r['company_uuid'],'code'=>$r['company_code'],'legalName'=>$r['legal_name']],
            'customerReference'=>$r['customer_reference'],'roomCount'=>(int)$r['room_count'],'openingCount'=>(int)$r['opening_count'],
            'version'=>(int)$r['version'],'revision'=>(int)$r['revision'],'updatedAt'=>self::iso($r['updated_at']),
            'updatedBy'=>['id'=>$r['updated_by_employee_uuid'],'displayName'=>$r['updated_by_name']],
        ],$rows),'nextCursor'=>$next,'capabilities'=>ProjectAccess::capabilities($this->authorization,$actor)];
    }

    /** Header + zones + room summaries with counts. Openings and treatments load per room. */
    public function detail(EmployeeIdentity $actor,string $id): array
    {
        ProjectAccess::require($this->authorization,$actor,ProjectAccess::VIEW);
        return $this->read($id,function(array $project) use($actor,$id): array {
            $zones=array_map(static fn(array $r): array=>ProjectStore::node('zone',$r)+['rooms'=>[]],$this->store->rows('zone',$id));
            $byZone=array_flip(array_column($zones,'id'));
            $counts=$this->counts($id);
            $roomCount=0;
            foreach($this->store->rows('room',$id) as $r) {
                $room=ProjectStore::node('room',$r);
                $room+=['openingCount'=>$counts['openings'][$room['id']]??0,'treatmentCount'=>$counts['treatments'][$room['id']]??0,'orderedCount'=>$counts['ordered'][$room['id']]??0];
                $zones[$byZone[$room['zoneId']]]['rooms'][]=$room;
                $roomCount++;
            }
            return ['project'=>$this->header($project)+['counts'=>[
                'zones'=>count($zones),'rooms'=>$roomCount,'openings'=>array_sum($counts['openings']),
                'treatments'=>array_sum($counts['treatments']),'orderedTreatments'=>array_sum($counts['ordered'])]],
                'zones'=>$zones,'capabilities'=>$this->capabilities($actor,$project)];
        });
    }

    /** One room with its openings and treatments, each treatment with its Classic line totals and order link. */
    public function room(EmployeeIdentity $actor,string $id,string $roomId): array
    {
        ProjectAccess::require($this->authorization,$actor,ProjectAccess::VIEW);
        CompanyQueries::uuidOrNotFound($roomId,'PROJECT_NODE_NOT_FOUND','Room was not found.');
        return $this->read($id,function(array $project) use($id,$roomId): array {
            $s=$this->pdo->prepare('SELECT * FROM b2b_project_rooms WHERE room_uuid=? AND project_uuid=?'); $s->execute([$roomId,$id]);
            $room=$s->fetch(PDO::FETCH_ASSOC);
            if(!$room) throw new ApiException(404,'PROJECT_NODE_NOT_FOUND','Room was not found.');
            $tree=$this->tree($project,[$roomId]);
            return ['revision'=>(int)$project['revision'],'currencyCode'=>$project['currency_code'],'status'=>$project['status'],
                'room'=>ProjectStore::node('room',$room)+['openings'=>$tree[$roomId]??[]]];
        });
    }

    /**
     * Renderer-neutral scene document consumed by the 2D view now and a future 3D renderer. Only structure and
     * centimetre measurements; no engine objects, camera, material or mesh state. Derived from authoritative data on
     * every read, never stored, so the renderer can never become the source of truth.
     */
    public function scene(EmployeeIdentity $actor,string $id,?string $roomId): array
    {
        ProjectAccess::require($this->authorization,$actor,ProjectAccess::VIEW);
        if($roomId!==null) CompanyQueries::uuidOrNotFound($roomId,'PROJECT_NODE_NOT_FOUND','Room was not found.');
        return $this->read($id,function(array $project) use($id,$roomId): array {
            $zones=array_column(array_map(static fn(array $r): array=>ProjectStore::node('zone',$r),$this->store->rows('zone',$id)),null,'id');
            $rooms=array_map(static fn(array $r): array=>ProjectStore::node('room',$r),$this->store->rows('room',$id));
            if($roomId!==null) {
                $rooms=array_values(array_filter($rooms,static fn(array $r): bool=>$r['id']===$roomId));
                if($rooms===[]) throw new ApiException(404,'PROJECT_NODE_NOT_FOUND','Room was not found.');
            }
            $tree=$this->tree($project,array_column($rooms,'id'),false);
            $n=static fn(?string $v): ?float=>$v===null?null:(float)$v;
            return ['schema'=>self::SCENE_SCHEMA,'unit'=>'cm','projectId'=>$id,'revision'=>(int)$project['revision'],
                'propertyType'=>$project['property_type'],
                'rooms'=>array_map(static function(array $room) use($zones,$tree,$n): array {
                    $zone=$zones[$room['zoneId']];
                    return ['id'=>$room['id'],'name'=>$room['name'],
                        'zone'=>['id'=>$zone['id'],'name'=>$zone['name'],'type'=>$zone['zoneType'],'level'=>$zone['level'],'building'=>$zone['building']],
                        'dimensions'=>['width'=>$n($room['widthCm']),'length'=>$n($room['lengthCm']),'ceilingHeight'=>$n($room['ceilingHeightCm'])],
                        'openings'=>array_map(static fn(array $o): array=>[
                            'id'=>$o['id'],'name'=>$o['name'],'type'=>$o['openingType'],'wallIndex'=>$o['wallIndex'],
                            'width'=>$n($o['width']),'height'=>$n($o['height']),'sillHeight'=>$n($o['sillHeight']),
                            'offsetLeft'=>$n($o['offsetLeft']),'wallWidth'=>$n($o['wallWidth']),'mounting'=>$o['mounting'],'railType'=>$o['railType'],
                            // Layer 1 hangs closest to the opening; order follows the treatment position.
                            'treatments'=>array_map(static fn(array $t,int $i): array=>[
                                'id'=>$t['id'],'layer'=>$i+1,'type'=>$t['treatmentType'],'kind'=>$t['kind'],'panelLayout'=>$t['panelLayout'],
                                'product'=>['code'=>$t['productCode'],'name'=>$t['productName'],'variant'=>$t['variant'],'color'=>$t['color']],
                                'width'=>$n($t['width']),'height'=>$n($t['height']),
                            ],$o['treatments'],array_keys($o['treatments'])),
                        ],$tree[$room['id']]??[]),
                    ];
                },$rooms)];
        });
    }

    /** Commercial projection of the workspace through the canonical Classic calculator. Planning figures, not a ledger. */
    public function commercial(EmployeeIdentity $actor,string $id): array
    {
        ProjectAccess::require($this->authorization,$actor,ProjectAccess::VIEW);
        return $this->read($id,fn(array $project): array=>$this->projection($project));
    }

    /** Commercial orders created from this project (traceability project -> orders). */
    public function orders(EmployeeIdentity $actor,string $id): array
    {
        ProjectAccess::require($this->authorization,$actor,ProjectAccess::VIEW);
        return $this->read($id,function(array $project) use($id): array {
            $s=$this->pdo->prepare('SELECT po.*,o.order_code,o.status,o.currency_code,o.net_total,o.vat_total,o.gross_total,
                (SELECT COUNT(*) FROM b2b_project_order_lines pl WHERE pl.order_uuid=po.order_uuid) AS line_count,
                EXISTS(SELECT 1 FROM b2b_production_handoffs h WHERE h.b2b_order_uuid=po.order_uuid) AS production_submitted
                FROM b2b_project_orders po JOIN b2b_orders o ON o.order_uuid=po.order_uuid WHERE po.project_uuid=? ORDER BY po.created_at DESC,po.order_uuid DESC');
            $s->execute([$id]);
            return ['items'=>array_map(static fn(array $r): array=>[
                'orderId'=>$r['order_uuid'],'code'=>$r['order_code'],'status'=>$r['status'],'currencyCode'=>$r['currency_code'],
                'totals'=>$r['gross_total']===null?null:['net'=>$r['net_total'],'vat'=>$r['vat_total'],'gross'=>$r['gross_total']],
                'lineCount'=>(int)$r['line_count'],'productionSubmitted'=>(bool)$r['production_submitted'],'projectRevision'=>(int)$r['project_revision'],
                'createdAt'=>self::iso($r['created_at']),'createdBy'=>['id'=>$r['created_by_employee_uuid'],'displayName'=>$r['created_by_name']],
            ],$s->fetchAll(PDO::FETCH_ASSOC))];
        });
    }

    public function activity(EmployeeIdentity $actor,string $id,array $filters): array
    {
        ProjectAccess::require($this->authorization,$actor,ProjectAccess::VIEW);
        CompanyQueries::uuidOrNotFound($id,'PROJECT_NOT_FOUND','Project was not found.');
        if($this->store->project($id)===null) throw new ApiException(404,'PROJECT_NOT_FOUND','Project was not found.');
        $raw=$filters['limit']??'50';
        if(!in_array((string)$raw,['25','50','100'],true)) $this->invalid('limit');
        $limit=(int)$raw; $where='project_uuid=?'; $params=[$id];
        if(($filters['cursor']??'')!=='') {
            [$time,$uuid]=$this->cursor($filters['cursor']);
            $where.=' AND (occurred_at<? OR (occurred_at=? AND event_id<?))';
            array_push($params,$time,$time,$uuid);
        }
        $s=$this->pdo->prepare('SELECT * FROM b2b_project_activity_events WHERE '.$where.' ORDER BY occurred_at DESC,event_id DESC LIMIT '.($limit+1));
        $s->execute($params); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        $next=null;
        if(count($rows)>$limit) {
            array_pop($rows); $last=$rows[count($rows)-1];
            $next=rtrim(strtr(base64_encode(OrderStore::json(['at'=>$last['occurred_at'],'id'=>$last['event_id']])),'+/','-_'),'=');
        }
        return ['items'=>array_map(static fn(array $r): array=>[
            'id'=>$r['event_id'],'action'=>$r['action'],'subject'=>['type'=>$r['subject_type'],'id'=>$r['subject_uuid']],
            'details'=>OrderStore::decode($r['details']),'actor'=>['id'=>$r['actor_employee_uuid'],'displayName'=>$r['actor_name']],
            'requestId'=>$r['request_id'],'occurredAt'=>self::iso($r['occurred_at']),
        ],$rows),'nextCursor'=>$next];
    }

    /** The full dataset of the customer proposal PDF: header, company, tree and commercial projection of one revision. */
    public function proposal(EmployeeIdentity $actor,string $id): array
    {
        ProjectAccess::require($this->authorization,$actor,ProjectAccess::VIEW);
        return $this->read($id,function(array $project) use($id): array {
            $s=$this->pdo->prepare('SELECT * FROM b2b_companies WHERE company_uuid=?'); $s->execute([$project['company_uuid']]);
            $company=$s->fetch(PDO::FETCH_ASSOC);
            $zones=array_map(static fn(array $r): array=>ProjectStore::node('zone',$r)+['rooms'=>[]],$this->store->rows('zone',$id));
            $byZone=array_flip(array_column($zones,'id'));
            $rooms=array_map(static fn(array $r): array=>ProjectStore::node('room',$r),$this->store->rows('room',$id));
            $tree=$this->tree($project,array_column($rooms,'id'));
            foreach($rooms as $room) $zones[$byZone[$room['zoneId']]]['rooms'][]=$room+['openings'=>$tree[$room['id']]??[]];
            return ['project'=>$this->header($project),'company'=>['code'=>$company['company_code'],'legalName'=>$company['legal_name'],
                'countryCode'=>$company['country_code'],'taxIdentifier'=>$company['tax_identifier'],'vatNumber'=>$company['vat_number']],
                'zones'=>$zones,'commercial'=>$this->projection($project),'generatedAt'=>$this->clock->now()->format('Y-m-d\TH:i:s\Z')];
        });
    }

    private function projection(array $project): array
    {
        $id=$project['project_uuid'];
        $zones=array_map(static fn(array $r): array=>ProjectStore::node('zone',$r),$this->store->rows('zone',$id));
        $rooms=array_map(static fn(array $r): array=>ProjectStore::node('room',$r),$this->store->rows('room',$id));
        $tree=$this->tree($project,array_column($rooms,'id'));
        $roomsByZone=[];
        foreach($rooms as $room) $roomsByZone[$room['zoneId']][]=$room;
        $sum=static fn(): array=>['net'=>0,'vat'=>0,'gross'=>0,'complete'=>true,'count'=>0];
        $add=static function(array &$into,?array $totals): void {
            $into['count']++;
            if($totals===null) { $into['complete']=false; return; }
            foreach(['net','vat','gross'] as $k) $into[$k]+=OrderInput::fixed($totals[$k],2);
        };
        $format=static fn(array $s): array=>['totals'=>$s['count']>0?['net'=>OrderInput::format($s['net']),'vat'=>OrderInput::format($s['vat']),'gross'=>OrderInput::format($s['gross'])]:null,
            'complete'=>$s['complete'] && $s['count']>0,'treatmentCount'=>$s['count']];
        $project_=$sum(); $out=[]; $treatments=[];
        foreach($zones as $zone) {
            $zoneSum=$sum(); $roomOut=[];
            foreach($roomsByZone[$zone['id']]??[] as $room) {
                $roomSum=$sum();
                foreach($tree[$room['id']]??[] as $opening) foreach($opening['treatments'] as $t) {
                    $add($roomSum,$t['totals']); $add($zoneSum,$t['totals']); $add($project_,$t['totals']);
                    $treatments[]=['id'=>$t['id'],'roomId'=>$room['id'],'openingId'=>$opening['id'],'totals'=>$t['totals'],'ordered'=>$t['ordered']];
                }
                $roomOut[]=['id'=>$room['id'],'name'=>$room['name']]+$format($roomSum);
            }
            $out[]=['id'=>$zone['id'],'name'=>$zone['name']]+$format($zoneSum)+['rooms'=>$roomOut];
        }
        return ['currencyCode'=>$project['currency_code'],'revision'=>(int)$project['revision']]+$format($project_)+['zones'=>$out,'treatments'=>$treatments];
    }

    /**
     * Openings with treatments for the given rooms: two queries regardless of size. Treatments carry their Classic
     * line totals and, when $commercial, the live order that already holds them.
     * @return array<string,list<array>> room id => openings
     */
    private function tree(array $project,array $roomIds,bool $commercial=true): array
    {
        $id=$project['project_uuid'];
        $openings=array_map(static fn(array $r): array=>ProjectStore::node('opening',$r)+['treatments'=>[]],$this->store->rows('opening',$id,$roomIds));
        $index=array_flip(array_column($openings,'id'));
        $treatments=array_map(static fn(array $r): array=>ProjectStore::node('treatment',$r),$this->store->rows('treatment',$id,array_column($openings,'id')));
        if($commercial && $treatments!==[]) {
            $calculation=OrderCalculator::calculate($project['currency_code'],array_map(ProjectStore::line(...),$treatments));
            $ordered=$this->store->orderedTreatments($id);
            foreach($treatments as $i=>&$t) { $t['totals']=$calculation['lines'][$i]['totals']; $t['ordered']=$ordered[$t['id']]??null; }
            unset($t);
        }
        foreach($treatments as $t) $openings[$index[$t['openingId']]]['treatments'][]=$t;
        $out=[];
        foreach($openings as $o) $out[$o['roomId']][]=$o;
        return $out;
    }

    /** @return array{openings:array<string,int>,treatments:array<string,int>,ordered:array<string,int>} per room */
    private function counts(string $id): array
    {
        $out=['openings'=>[],'treatments'=>[],'ordered'=>[]];
        $queries=[
            'openings'=>'SELECT room_uuid,COUNT(*) FROM b2b_project_openings WHERE project_uuid=? GROUP BY room_uuid',
            'treatments'=>'SELECT o.room_uuid,COUNT(*) FROM b2b_project_treatments t JOIN b2b_project_openings o ON o.opening_uuid=t.opening_uuid WHERE t.project_uuid=? GROUP BY o.room_uuid',
            'ordered'=>"SELECT o.room_uuid,COUNT(DISTINCT t.treatment_uuid) FROM b2b_project_order_lines pl
                JOIN b2b_orders c ON c.order_uuid=pl.order_uuid AND c.status<>'cancelled'
                JOIN b2b_order_lines l ON l.line_uuid=pl.line_uuid AND l.order_uuid=pl.order_uuid
                JOIN b2b_project_treatments t ON t.treatment_uuid=pl.treatment_uuid
                JOIN b2b_project_openings o ON o.opening_uuid=t.opening_uuid WHERE pl.project_uuid=? GROUP BY o.room_uuid",
        ];
        foreach($queries as $key=>$sql) {
            $s=$this->pdo->prepare($sql); $s->execute([$id]);
            foreach($s->fetchAll(PDO::FETCH_NUM) as [$room,$count]) $out[$key][$room]=(int)$count;
        }
        return $out;
    }

    private function header(array $p): array
    {
        $s=$this->pdo->prepare('SELECT company_code,legal_name,status FROM b2b_companies WHERE company_uuid=?'); $s->execute([$p['company_uuid']]);
        $c=$s->fetch(PDO::FETCH_ASSOC);
        return ['id'=>$p['project_uuid'],'code'=>$p['project_code'],'name'=>$p['name'],'propertyType'=>$p['property_type'],'status'=>$p['status'],
            'currencyCode'=>$p['currency_code'],'siteAddress'=>$p['site_address'],'customerReference'=>$p['customer_reference'],'notes'=>$p['notes'],
            'company'=>['id'=>$p['company_uuid'],'code'=>$c['company_code'],'legalName'=>$c['legal_name'],'status'=>$c['status']],
            'version'=>(int)$p['version'],'revision'=>(int)$p['revision'],
            'createdAt'=>self::iso($p['created_at']),'updatedAt'=>self::iso($p['updated_at']),'archivedAt'=>self::iso($p['archived_at']),
            'createdBy'=>['id'=>$p['created_by_employee_uuid'],'displayName'=>$p['created_by_name']],
            'updatedBy'=>['id'=>$p['updated_by_employee_uuid'],'displayName'=>$p['updated_by_name']]];
    }

    private function capabilities(EmployeeIdentity $actor,array $project): array
    {
        $caps=ProjectAccess::capabilities($this->authorization,$actor);
        if($project['status']==='archived') { $caps['canUpdate']=false; $caps['canConvert']=false; }
        return $caps;
    }

    private function read(string $id,callable $work): array
    {
        CompanyQueries::uuidOrNotFound($id,'PROJECT_NOT_FOUND','Project was not found.');
        $this->pdo->beginTransaction();
        try {
            $project=$this->store->project($id);
            if($project===null) throw new ApiException(404,'PROJECT_NOT_FOUND','Project was not found.');
            $result=$work($project);
            $this->pdo->commit();
            return $result;
        } catch(Throwable $e) { if($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
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
        if(!is_array($cursor) || count($cursor)!==2 || !isset($cursor['at'],$cursor['id']) || !is_string($cursor['at']) || !is_string($cursor['id']) ||
            !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D',$cursor['at']) ||
            !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D',$cursor['id']))
            throw new ApiException(400,'INVALID_CURSOR','Invalid cursor.');
        return [$cursor['at'],$cursor['id']];
    }
    public static function iso(?string $time): ?string
    {
        return $time===null?null:(new DateTimeImmutable($time,new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
