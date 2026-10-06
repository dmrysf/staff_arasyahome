<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Quality\IdempotencyStore;
use Arasya\Operations\Support\Clock;
use PDO;
use Throwable;
use InvalidArgumentException;

final readonly class AnalyticsService
{
    public function __construct(private PDO $pdo,private AnalyticsPolicy $policy,private Clock $clock) {}
    public function policy(EmployeeIdentity $actor): array
    {
        $this->policy->require($actor);
        $row=$this->pdo->query('SELECT approval_grace_minutes,analytics_policy_version FROM production_exception_policy WHERE singleton_id=1')->fetch(PDO::FETCH_ASSOC);
        return ['approvalGraceMinutes'=>(int)$row['approval_grace_minutes'],'version'=>(int)$row['analytics_policy_version'],'canEdit'=>$actor->isRoot,
            'timezone'=>'Europe/Bucharest','days'=>$this->pdo->query('SELECT weekday,is_open,opens_at,closes_at FROM business_hours ORDER BY weekday')->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function updatePolicy(EmployeeIdentity $actor,array $input,string $key,string $requestId): array
    {
        $this->policy->require($actor);
        if (!$actor->isRoot) throw new ApiException(403,'ROOT_REQUIRED','Root required.');
        if (array_diff(array_keys($input),['approvalGraceMinutes','expectedVersion']) || count($input)!==2 || !is_int($input['approvalGraceMinutes']??null) || $input['approvalGraceMinutes']<0 || $input['approvalGraceMinutes']>240 || !is_int($input['expectedVersion']??null) || $input['expectedVersion']<1) throw new ApiException(400,'INVALID_REQUEST','Invalid analytics policy.');
        IdempotencyStore::requireKey($key); $store=new IdempotencyStore($this->pdo); $hash=IdempotencyStore::hash('analytics.policy','singleton',$input);
        $this->pdo->beginTransaction();
        try {
            $row=$this->pdo->query('SELECT approval_grace_minutes,analytics_policy_version FROM production_exception_policy WHERE singleton_id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            $replay=$store->replay($actor->employeeUuid,$key,'analytics.policy','singleton',$hash);
            if ($replay!==null) { $this->pdo->commit(); return $replay; }
            if ((int)$row['analytics_policy_version']!==$input['expectedVersion']) throw new ApiException(409,'ANALYTICS_POLICY_CHANGED','Policy changed.');
            $now=$this->clock->now()->format('Y-m-d H:i:s.u');
            $this->pdo->prepare('UPDATE production_exception_policy SET approval_grace_minutes=?,analytics_policy_version=analytics_policy_version+1,updated_at=?,updated_by_employee_uuid=? WHERE singleton_id=1')->execute([$input['approvalGraceMinutes'],$now,$actor->employeeUuid]);
            (new IamAuditLogger($this->pdo))->record($actor,'analytics.policy.changed','production_policy','singleton','Toleranță aprobare',
                ['before'=>(int)$row['approval_grace_minutes'],'after'=>$input['approvalGraceMinutes'],'timezone'=>'Europe/Bucharest'],$requestId,$now);
            $response=$this->policy($actor); $store->store($actor->employeeUuid,$key,'analytics.policy','singleton',$hash,$response,$now);
            $this->pdo->commit(); return $response;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    public function report(EmployeeIdentity $actor,string $section,array $query,?string $id=null): array
    {
        $this->policy->require($actor);
        try { $range=DateRange::from($query,$this->clock->now()); } catch (InvalidArgumentException $e) { throw new ApiException(400,'INVALID_DATE_RANGE','Invalid date range.'); }
        $limit=$this->limit($query); $cursor=$query['cursor']??'';
        if (!is_string($cursor) || ($cursor!=='' && !preg_match('/^[0-9a-f-]{36}$/D',$cursor))) throw new ApiException(400,'INVALID_CURSOR','Invalid cursor.');
        if ($this->pdo->inTransaction()) throw new \LogicException('Report requires an independent snapshot');
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();
        try {
            $settings=$this->policy($actor); $time=new AnalyticsTime($settings['days'],$settings['approvalGraceMinutes']);
            $asOf=$this->clock->now()->format('Y-m-d H:i:s.u');
            if ($section==='order') $body=$this->order($id??'', $time,$asOf,$limit,$query['afterVersion']??null);
            else {
                $data=(new ReportData($this->pdo))->load($range,$section==='managers');
                $employees=in_array($section,['employees','employee','departments'],true)?EmployeeMetrics::calculate($data,$range,$time,$asOf):[];
                $body=match($section) {
                    'employees'=>$this->employees($employees,$query,$limit,$cursor),
                    'employee'=>$this->employee($employees,$data,$id??'',$time,$asOf,$limit,$cursor),
                    'departments'=>$this->departments($employees,$data,$range,$time,$asOf),
                    'managers'=>$this->managers($data,$range,$time,$asOf,$limit,$query),
                    'sources'=>$this->groups($data,$time,$asOf,'source_key',$limit,$query),
                    'companies'=>$this->groups($data,$time,$asOf,'company_uuid',$limit,$query),
                    'company'=>$this->company($data,$time,$asOf,$id??'',$limit,$cursor),
                    'orders'=>$this->orders($data,$time,$asOf,$limit,$cursor,$query),
                    default=>throw new ApiException(404,'NOT_FOUND','Analytics route not found.')
                };
                $body['coverage']=['totalOrders'=>(int)$data['coverage']['total_orders'],'projectedOrders'=>(int)$data['coverage']['projected_orders'],
                    'rebuildRequired'=>(int)$data['coverage']['total_orders']!==(int)$data['coverage']['projected_orders']];
            }
            $this->pdo->commit();
            return UtcPresentation::format($body+['range'=>$range->metadata(),'asOf'=>$asOf,'formulaVersion'=>1,'approvalGraceMinutes'=>$settings['approvalGraceMinutes'],
                'semantics'=>['department'=>'current_primary_membership','completion'=>'latest_valid_whole_order_cutting_handoff','meters'=>'canonical_line_total_not_quantity_multiplied',
                    'tailMinimumSample'=>20,'quartileMinimumSample'=>4,'percentile'=>'nearest_rank','historicalEligibility'=>'unknown_without_snapshot','customerType'=>'unknown_without_canonical_type']]);
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    private function employees(array $employees,array $query,int $limit,string $cursor): array
    {
        $search=mb_strtolower(mb_substr((string)($query['search']??''),0,100)); $department=(string)($query['departmentId']??'');
        $items=array_values(array_filter($employees,static fn($r)=>($department==='' || (string)$r['department']['id']===$department) && ($search==='' || str_contains(mb_strtolower($r['name']),$search))));
        usort($items,static fn($a,$b)=>strcmp($a['id'],$b['id']));
        $total=count($items); $items=array_values(array_filter($items,static fn($r)=>$r['id']>$cursor));
        $next=count($items)>$limit?$items[$limit-1]['id']:null;
        $items=array_slice($items,0,$limit);
        foreach ($items as &$item) { unset($item['daily'],$item['weekly'],$item['relatedOrderIds']); } unset($item);
        return ['items'=>$items,'total'=>$total,'nextCursor'=>$next];
    }
    private function employee(array $employees,array $data,string $id,AnalyticsTime $time,string $asOf,int $limit,string $cursor): array
    {
        $employee=$employees[$id]??throw new ApiException(404,'EMPLOYEE_NOT_FOUND','Employee not found.');
        $related=array_fill_keys($employee['relatedOrderIds'],true); unset($employee['relatedOrderIds']);
        $subset=$data; $subset['orders']=array_intersect_key($data['orders'],$related);
        $orders=$this->orders($subset,$time,$asOf,$limit,$cursor,[]);
        $longest=[];
        foreach ($subset['orders'] as $orderId=>$order) foreach ($data['intervals'][$orderId]??[] as $interval) {
            if ($interval['employee_uuid']!==$id) continue;
            $duration=$time->active($interval['started_at'],$interval['ended_at']??$asOf,OrderMetrics::blocks($data['transfers'][$orderId]??[],$data['exceptions'][$orderId]??[],$asOf,$order));
            $longest[]=['orderId'=>$orderId,'orderNumber'=>$order['order_number'],'stageId'=>$interval['stage_id'],'start'=>$interval['started_at'],'end'=>$interval['ended_at'],'duration'=>$duration];
        }
        usort($longest,static fn($a,$b)=>$b['duration']['wallSeconds']<=>$a['duration']['wallSeconds']);
        return ['employee'=>$employee,'orders'=>$orders,'longestIntervals'=>array_slice($longest,0,10)];
    }
    private function departments(array $employees,array $data,DateRange $range,AnalyticsTime $time,string $asOf): array
    {
        $groups=[];
        foreach ($employees as $employee) {
            $key=$employee['department']['id']; $g=&$groups[$key];
            $g??=['department'=>$employee['department'],'employees'=>0,'completedOrders'=>0,'completedLines'=>0,'meterUnits'=>0,'faultLines'=>0,'faultMeterUnits'=>0,'detectedLines'=>0,'detectedMeterUnits'=>0,'activeHandling'=>['wallSeconds'=>0,'businessSeconds'=>0],'stageCompletions'=>[],'reworkCycles'=>0,'repeatedCycles'=>0,'onlineSubmissions'=>0,'managerWaitingForRelatedOrders'=>['wallSeconds'=>0,'businessSeconds'=>0],'orderIds'=>[]];
            $g['employees']++;
            foreach (['completedOrders','completedLines','faultLines','detectedLines','reworkCycles','repeatedCycles','onlineSubmissions'] as $field) $g[$field]+=$employee[$field];
            foreach ($employee['relatedOrderIds'] as $orderId) $g['orderIds'][$orderId]=true;
            $g['meterUnits']+=Metrics::units($employee['completedMeters']); $g['faultMeterUnits']+=Metrics::units($employee['faultMeters']); $g['detectedMeterUnits']+=Metrics::units($employee['detectedMeters']);
            foreach ($employee['activeHandling'] as $clock=>$seconds) $g['activeHandling'][$clock]+=$seconds;
            foreach ($employee['stageCompletions'] as $stage=>$count) $g['stageCompletions'][$stage]=($g['stageCompletions'][$stage]??0)+$count;
            unset($g);
        }
        foreach ($groups as &$group) {
            foreach (array_keys($group['orderIds']) as $orderId) {
                $waiting=$time->blocked($range->fromUtc->format('Y-m-d H:i:s'),min($range->toUtc->format('Y-m-d H:i:s'),$asOf),OrderMetrics::approvalWindows($data['transfers'][$orderId]??[],$data['decisions'][$orderId]??[],$asOf),true);
                foreach ($waiting as $clock=>$seconds) $group['managerWaitingForRelatedOrders'][$clock]+=$seconds;
            }
            unset($group['orderIds']);
            $group['completedMeters']=Metrics::meters($group['meterUnits']); $group['faultMeters']=Metrics::meters($group['faultMeterUnits']); $group['detectedMeters']=Metrics::meters($group['detectedMeterUnits']);
            $group['metricSet']=match($group['department']['key']) {
                'pregatire-material','taiere'=>'cutting','croitorie'=>'tailoring','ambalare'=>'packaging','livrare'=>'delivery','financiar'=>'financial_activity','vanzari-online'=>'canonical_online_activity',default=>'canonical_activity'
            };
            unset($group['meterUnits'],$group['faultMeterUnits'],$group['detectedMeterUnits']);
        } unset($group);
        return ['items'=>array_values($groups),'membership'=>'current_primary_department_not_historical_assignment'];
    }
    private function managers(array $data,DateRange $range,AnalyticsTime $time,string $asOf,int $limit,array $query): array
    {
        $result=ManagerMetrics::calculate($data,$range,$time,$asOf); $after=(string)($query['afterRequest']??'');
        if ($after!=='' && !preg_match('/^[0-9a-f-]{36}$/D',$after)) throw new ApiException(400,'INVALID_CURSOR','Invalid cursor.');
        usort($result['requests'],static fn($a,$b)=>strcmp($a['id'],$b['id']));
        $requests=array_values(array_filter($result['requests'],static fn($r)=>$r['id']>$after));
        $result['requestTotal']=count($result['requests']); $result['nextRequestCursor']=count($requests)>$limit?$requests[$limit-1]['id']:null;
        $result['requests']=array_slice($requests,0,$limit); return $result;
    }
    private function groups(array $data,AnalyticsTime $time,string $asOf,string $field,int $limit,array $query): array
    {
        $groups=[];
        foreach ($data['orders'] as $id=>$order) {
            $key=$order[$field]; if ($key===null) continue;
            $g=&$groups[$key]; $g??=['id'=>$key,'name'=>$field==='source_key'?($data['sourceNames'][$key]??$key):$this->companyName($order),'nameObservedAt'=>$order['created_at'],'orders'=>0,'completedOrders'=>0,'meterUnits'=>0,'knownLineCount'=>0,'missingLineSamples'=>0,'missingMeterSamples'=>0,'qrWall'=>[],'qrBusiness'=>[],'claimWall'=>[],'claimBusiness'=>[],'cancelledBeforeWork'=>[],'cancelledAfterWork'=>[],'customerType'=>null];
            if ($field==='company_uuid' && $order['created_at']>$g['nameObservedAt']) { $g['name']=$this->companyName($order); $g['nameObservedAt']=$order['created_at']; }
            $g['orders']++; $lifecycle=OrderMetrics::calculate($order,$data['intervals'][$id]??[],$data['transfers'][$id]??[],$data['exceptions'][$id]??[],$data['decisions'][$id]??[],$time,$asOf,$data['pools'][$id]??[]);
            foreach (['firstClaimDelay','cuttingWaiting','cuttingActive','managerWaiting','exceptionBlocked'] as $metric) {
                $g['samples'][$metric]??=['wallSeconds'=>[],'businessSeconds'=>[]];
                $include=$metric==='firstClaimDelay' || (in_array($metric,['cuttingWaiting','cuttingActive'],true)?$order['cutting_completed_at']!==null:$lifecycle['isComplete']);
                if ($include && $lifecycle[$metric]!==null) foreach (['wallSeconds','businessSeconds'] as $clock) if ($lifecycle[$metric][$clock]!==null) $g['samples'][$metric][$clock][]=$lifecycle[$metric][$clock];
            }
            if (($order['order_meters']??null)!==null) $g['meterUnits']+=Metrics::units($order['order_meters']);
            if (($order['order_meters']??null)===null || (int)($order['missing_line_meters']??1)>0) $g['missingMeterSamples']++;
            if (isset($order['order_lines'])) $g['knownLineCount']+=(int)$order['order_lines']; else $g['missingLineSamples']++;
            if ($lifecycle['isComplete']) $g['completedOrders']++;
            if ($lifecycle['qrToCompletion']!==null) { $g['qrWall'][]=$lifecycle['qrToCompletion']['wallSeconds']; $g['qrBusiness'][]=$lifecycle['qrToCompletion']['businessSeconds']; }
            if ($lifecycle['claimToCompletion']!==null) { $g['claimWall'][]=$lifecycle['claimToCompletion']['wallSeconds']; $g['claimBusiness'][]=$lifecycle['claimToCompletion']['businessSeconds']; }
            foreach ($data['cancellations'][$id]??[] as $cancelled) $g[OrderMetrics::hasWorkBefore($order,$cancelled['occurred_at'])?'cancelledAfterWork':'cancelledBeforeWork'][$id]=true;
            unset($g);
        }
        foreach ($groups as &$g) {
            foreach ($g['samples'] as $metric=>$clocks) foreach ($clocks as $clock=>$values) $g[$metric][$clock]=Metrics::distribution($values);
            unset($g['samples']);
            $g['cancelledBeforeWork']=count($g['cancelledBeforeWork']); $g['cancelledAfterWork']=count($g['cancelledAfterWork']); unset($g['nameObservedAt']);
            $g['meters']=Metrics::meters($g['meterUnits']); unset($g['meterUnits']);
            $g['qrToCompletion']=['wallSeconds'=>Metrics::distribution($g['qrWall']),'businessSeconds'=>Metrics::distribution($g['qrBusiness'])];
            $g['claimToCompletion']=['wallSeconds'=>Metrics::distribution($g['claimWall']),'businessSeconds'=>Metrics::distribution($g['claimBusiness'])];
            unset($g['qrWall'],$g['qrBusiness'],$g['claimWall'],$g['claimBusiness']);
        } unset($g);
        $items=array_values($groups); usort($items,static fn($a,$b)=>strcmp($a['id'],$b['id']));
        $after=(string)($query['afterGroup']??''); if (strlen($after)>60) throw new ApiException(400,'INVALID_CURSOR','Invalid cursor.');
        $total=count($items); $items=array_values(array_filter($items,static fn($r)=>$r['id']>$after));
        return ['items'=>array_slice($items,0,$limit),'total'=>$total,'nextGroupCursor'=>count($items)>$limit?$items[$limit-1]['id']:null,'selection'=>'orders_created_completed_or_active_in_range_not_sales'];
    }
    private function company(array $data,AnalyticsTime $time,string $asOf,string $id,int $limit,string $cursor): array
    {
        $data['orders']=array_filter($data['orders'],static fn($r)=>$r['company_uuid']===$id);
        if (!$data['orders']) {
            $name=(new \Arasya\Operations\B2B\ProductionAnalyticsIdentity($this->pdo))->companyLabel($id);
            if ($name===null) throw new ApiException(404,'COMPANY_NOT_FOUND','Company not found.');
            return ['company'=>['id'=>$id,'name'=>$name,'orders'=>0],'orders'=>['items'=>[],'total'=>0,'nextCursor'=>null]];
        }
        return ['company'=>$this->groups($data,$time,$asOf,'company_uuid',1,[])['items'][0],'orders'=>$this->orders($data,$time,$asOf,$limit,$cursor,[])];
    }
    private function orders(array $data,AnalyticsTime $time,string $asOf,int $limit,string $cursor,array $query): array
    {
        $orders=array_filter($data['orders'],static fn($r)=>(!isset($query['source']) || $r['source_key']===$query['source']) && (!isset($query['companyId']) || $r['company_uuid']===$query['companyId']));
        ksort($orders); $total=count($orders); $orders=array_filter($orders,static fn($key)=>$key>$cursor,ARRAY_FILTER_USE_KEY);
        $next=count($orders)>$limit?array_keys($orders)[$limit-1]:null; $items=[];
        foreach (array_slice($orders,0,$limit,true) as $id=>$order) $items[]=['id'=>$id,'globalOrderId'=>$order['global_order_id'],'number'=>$order['order_number'],'source'=>$order['source_key'],
            'companyId'=>$order['company_uuid'],'stageId'=>$order['production_stage_id'],'operationalStatus'=>$order['operational_status'],'qrCreatedAt'=>$order['qr_created_at'],
            'firstClaimAt'=>$order['first_claim_at'],'completedAt'=>$order['completed_at'],'cuttingMeters'=>$order['cutting_meters'],'cuttingLines'=>$order['cutting_lines'],
            'createdAt'=>$order['created_at'],'orderMeters'=>$order['order_meters']??null,'orderLines'=>isset($order['order_lines'])?(int)$order['order_lines']:null,
            'lifecycle'=>OrderMetrics::calculate($order,$data['intervals'][$id]??[],$data['transfers'][$id]??[],$data['exceptions'][$id]??[],$data['decisions'][$id]??[],$time,$asOf,$data['pools'][$id]??[])];
        foreach ($items as &$item) unset($item['lifecycle']['intervals']); unset($item);
        return ['items'=>$items,'total'=>$total,'nextCursor'=>$next];
    }
    private function order(string $id,AnalyticsTime $time,string $asOf,int $limit,mixed $afterVersion): array
    {
        $s=$this->pdo->prepare('SELECT p.*,o.global_order_id,o.order_number,o.production_stage_id,o.operational_status,o.source_changed_at,o.production_context,o.source_reported_unavailable_at FROM analytics_order_projection p JOIN operational_orders o ON o.order_uuid=p.order_uuid WHERE p.order_uuid=?');
        $s->execute([$id]); $order=$s->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $s=$this->pdo->prepare('SELECT 1 FROM operational_orders WHERE order_uuid=?'); $s->execute([$id]);
            if ($s->fetchColumn()) throw new ApiException(503,'ANALYTICS_REBUILD_REQUIRED','Projection rebuild required.');
            throw new ApiException(404,'ORDER_NOT_FOUND','Order not found.');
        }
        $rows=[];
        foreach (['analytics_ownership_intervals','cutting_transfers','production_exceptions'] as $table) { $s=$this->pdo->prepare("SELECT * FROM {$table} WHERE order_uuid=?"); $s->execute([$id]); $rows[$table]=$s->fetchAll(PDO::FETCH_ASSOC); }
        $s=$this->pdo->prepare('SELECT d.*,e.resolved_at exception_resolved_at FROM production_exception_decisions d JOIN production_exceptions e ON e.exception_uuid=d.exception_uuid WHERE e.order_uuid=?'); $s->execute([$id]); $decisions=$s->fetchAll(PDO::FETCH_ASSOC);
        if ($afterVersion!==null && (!is_scalar($afterVersion) || !ctype_digit((string)$afterVersion) || strlen((string)$afterVersion)>10)) throw new ApiException(400,'INVALID_CURSOR','Invalid cursor.');
        // Start with this order's version range, never an employee-first scan of unrelated history.
        $s=$this->pdo->prepare('SELECT a.event_id,a.employee_uuid,e.display_name employee_name,a.action,a.from_stage_id,a.from_stage_label_snapshot,a.to_stage_id,a.to_stage_label_snapshot,a.previous_owner_employee_uuid,a.new_owner_employee_uuid,a.production_version_after,a.meters_snapshot,a.occurred_at FROM order_activity_events a FORCE INDEX (idx_analytics_activity_timeline) STRAIGHT_JOIN employees e ON e.employee_uuid=a.employee_uuid WHERE a.order_uuid=? AND a.production_version_after>? ORDER BY a.production_version_after LIMIT '.($limit+1));
        $s->execute([$id,(int)($afterVersion??0)]); $timeline=$s->fetchAll(PDO::FETCH_ASSOC); $next=count($timeline)>$limit?(int)$timeline[$limit-1]['production_version_after']:null;
        $items=$this->pdo->prepare('SELECT line_number,meters,quantity FROM operational_order_items WHERE order_uuid=? ORDER BY line_number LIMIT 101'); $items->execute([$id]); $lines=$items->fetchAll(PDO::FETCH_ASSOC);
        $s=$this->pdo->prepare("SELECT production_version,occurred_at FROM cutting_facts WHERE order_uuid=? AND fact_type='pool_entered'"); $s->execute([$id]); $allPools=$s->fetchAll(PDO::FETCH_ASSOC);
        $s=$this->pdo->prepare("SELECT MIN(occurred_at) FROM order_activity_events FORCE INDEX (idx_analytics_activity_work) WHERE order_uuid=? AND action IN ('claimed','stage_completed','production_completed')"); $s->execute([$id]); $order['work_recorded_at']=$s->fetchColumn()?:null;
        $s=$this->pdo->prepare('SELECT block_uuid,cause,started_at,ended_at,processed_meters,processed_lines,production_started,stage_id_snapshot FROM production_document_blocks WHERE order_uuid=? ORDER BY started_at LIMIT 200'); $s->execute([$id]); $order['document_blocks']=$s->fetchAll(PDO::FETCH_ASSOC);
        $s=$this->pdo->prepare('SELECT revision_number,status,generated_at,stale_at,superseded_at,revoked_at FROM production_document_revisions WHERE order_uuid=? ORDER BY revision_number LIMIT 200'); $s->execute([$id]); $documentRevisions=$s->fetchAll(PDO::FETCH_ASSOC);
        $s=$this->pdo->prepare('SELECT request_uuid,target_revision_number,status,requested_at,decided_at,decided_via,resolved_at FROM production_document_revision_requests WHERE order_uuid=? ORDER BY requested_at LIMIT 200'); $s->execute([$id]); $documentRequests=$s->fetchAll(PDO::FETCH_ASSOC);
        usort($rows['analytics_ownership_intervals'],static fn($a,$b)=>(int)$a['start_version']<=>(int)$b['start_version']);
        $lifecycle=OrderMetrics::calculate($order,$rows['analytics_ownership_intervals'],$rows['cutting_transfers'],$rows['production_exceptions'],$decisions,$time,$asOf,$allPools);
        $rawIntervals=[];
        foreach ($lifecycle['intervals'] as $i=>$interval) {
            $version=(int)$rows['analytics_ownership_intervals'][$i]['start_version'];
            if ($version>(int)($afterVersion??0)) $rawIntervals[]=$interval+['startVersion'=>$version];
        }
        $intervalNext=count($rawIntervals)>$limit?$rawIntervals[$limit-1]['startVersion']:null;
        if ($intervalNext!==null) $next=$next===null?$intervalNext:min($next,$intervalNext);
        // At most three selected facts/version. Preserve complete version groups at the page boundary.
        $s=$this->pdo->prepare("SELECT fact_uuid,fact_type,production_version,occurred_at FROM cutting_facts WHERE order_uuid=? AND fact_type IN ('pool_entered','first_claim','source_cancelled') AND production_version>? ORDER BY production_version,fact_type LIMIT ".(3*($limit+1)));
        $s->execute([$id,(int)($afterVersion??0)]); $poolFacts=$s->fetchAll(PDO::FETCH_ASSOC);
        $factVersions=array_values(array_unique(array_column($poolFacts,'production_version')));
        $factNext=count($factVersions)>$limit?(int)$factVersions[$limit-1]:null;
        if ($factNext!==null) $next=$next===null?$factNext:min($next,$factNext);
        $lifecycle['intervals']=array_values(array_filter(array_slice($rawIntervals,0,$limit),static fn($r)=>$next===null || $r['startVersion']<=$next));
        // Personnel IDs only in request drilldown; never commercial comments, finance or contact information.
        $transferFacts=array_map(static fn($r)=>array_intersect_key($r,array_flip(['transfer_uuid','from_employee_uuid','to_employee_uuid','status','requested_at','decided_at','accepted_at','qr_verified_at','resolved_at','decided_by_employee_uuid','decided_via'])),$rows['cutting_transfers']);
        $exceptionFacts=array_map(static fn($r)=>array_intersect_key($r,array_flip(['exception_uuid','detector_employee_uuid','responsible_employee_uuid','status','reported_at','acknowledged_at','resolved_at','line_count','fault_meters','rework_cycle'])),$rows['production_exceptions']);
        $decisionFacts=array_map(static fn($r)=>array_intersect_key($r,array_flip(['decision_uuid','previous_decision_uuid','attempt_number','opened_at','status','decided_at','decided_by_employee_uuid','decided_via'])),$decisions);
        return ['order'=>['id'=>$id,'globalOrderId'=>$order['global_order_id'],'number'=>$order['order_number'],'source'=>$order['source_key'],'stageId'=>$order['production_stage_id'],
            'operationalStatus'=>$order['operational_status'],'sourceImportedAt'=>$order['created_at'],'sourceChangedAt'=>$order['source_changed_at'],'qrCreatedAt'=>$order['qr_created_at'],
            'documentCreatedAt'=>$documentRevisions[0]['generated_at']??null,'firstClaimAt'=>$order['first_claim_at'],'completedAt'=>$order['completed_at'],'companyId'=>$order['company_uuid'],'companyName'=>$this->companyName($order),
            'cancelledAt'=>$order['source_reported_unavailable_at'],'cancellationPhase'=>$order['source_reported_unavailable_at']===null?null:(OrderMetrics::hasWorkBefore($order,$order['source_reported_unavailable_at'])?'after_work':'before_work')],
            'lifecycle'=>$lifecycle,'timeline'=>array_values(array_filter(array_slice($timeline,0,$limit),static fn($r)=>$next===null || (int)$r['production_version_after']<=$next)),
            'poolFacts'=>array_values(array_filter($poolFacts,static fn($r)=>$next===null || (int)$r['production_version']<=$next)),
            // Document revision facts: counts, waits and decision durations; never a worker fault or score.
            'documents'=>['revisionCount'=>count($documentRevisions),'revisions'=>$documentRevisions,'blocks'=>array_map(static fn($r)=>array_diff_key($r,['block_uuid'=>true]),$order['document_blocks']),
                'requests'=>array_map(static fn($r)=>$r+['approvalSeconds'=>$r['decided_at']===null?null:max(0,(new \DateTimeImmutable($r['decided_at'],new \DateTimeZone('UTC')))->getTimestamp()-(new \DateTimeImmutable($r['requested_at'],new \DateTimeZone('UTC')))->getTimestamp())],$documentRequests)],
            'nextVersion'=>$next,'transfers'=>$transferFacts,'exceptions'=>$exceptionFacts,'decisions'=>$decisionFacts,
            'comparableLines'=>array_slice($lines,0,100),'moreComparableLines'=>count($lines)>100];
    }
    private function companyName(array $order): ?string
    {
        $context=json_decode($order['production_context']??'null',true);
        return is_array($context) && is_string($context['company']['legalName']??null)?$context['company']['legalName']:null;
    }
    private function limit(array $query): int
    {
        $raw=$query['limit']??'50'; if (!is_scalar($raw) || !ctype_digit((string)$raw) || (int)$raw<1 || (int)$raw>100) throw new ApiException(400,'INVALID_REQUEST','Invalid limit.');
        return (int)$raw;
    }
}
