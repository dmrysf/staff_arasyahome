<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Arasya\Operations\Analytics\AnalyticsTime;
use Arasya\Operations\Analytics\Metrics;
use Arasya\Operations\Analytics\Lifecycle;
use Arasya\Operations\Analytics\DateRange;
use Arasya\Operations\Analytics\OrderMetrics;
use Arasya\Operations\Analytics\EmployeeMetrics;
use Arasya\Operations\Analytics\ManagerMetrics;
use Arasya\Operations\Analytics\UtcPresentation;
$checks = 0;
$check = static function(bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException('FAIL ' . $message); };
$hours = [];
for ($d=1;$d<=7;$d++) $hours[]=['weekday'=>$d,'is_open'=>$d<7?1:0,'opens_at'=>$d<7?'05:00:00':null,'closes_at'=>$d<7?'20:00:00':null];
$time = new AnalyticsTime($hours, 60);
$check($time->duration('2026-10-05 16:55:00','2026-10-05 17:45:00') === ['wallSeconds'=>3000,'businessSeconds'=>300], 'employee business time has no manager grace');
$check($time->duration('2026-10-05 16:55:00','2026-10-05 17:45:00',true) === ['wallSeconds'=>3000,'businessSeconds'=>3000], '19:55 to 20:45 approval includes grace');
$check($time->duration('2026-10-05 16:55:00','2026-10-06 02:10:00',true)['businessSeconds']===4500,'grace ends at 21:00, resumes next open day');
$check($time->duration('2026-10-03 16:55:00','2026-10-05 02:10:00',true)['businessSeconds']===4500,'Saturday open plus grace, Sunday entirely closed');
$check($time->duration('2026-10-04 08:00:00','2026-10-04 14:00:00',true)['businessSeconds']===0,'closed Sunday receives no grace');
$check($time->duration('2026-10-24 01:00:00','2026-10-26 04:00:00')['businessSeconds']===57600,'fall DST uses each local day offset');
$custom=$hours;$custom[0]['closes_at']='18:00:00';
$check((new AnalyticsTime($custom,30))->duration('2026-10-05 14:55:00','2026-10-05 15:45:00',true)['businessSeconds']===2100,'server custom schedule and Root policy reflected');
$check($time->active('2026-10-05 02:00:00','2026-10-05 04:00:00',[
 ['start'=>'2026-10-05 02:15:00','end'=>'2026-10-05 03:15:00'],
 ['start'=>'2026-10-05 02:45:00','end'=>'2026-10-05 03:30:00']
])===['wallSeconds'=>2700,'businessSeconds'=>2700],'overlapping manager/exception blocks removed once');
$check(Metrics::meters(Metrics::units('8.400')+Metrics::units('9.000'))==='17.400','meters exact thousandths, never quantity times meters');
$check(Metrics::rate('0.000','0.000')['percent']===null,'zero denominator unavailable, no score');
$check(Metrics::rate('18.700','1842.000')===['numerator'=>'18.700','denominator'=>'1842.000','percent'=>'1.02'],'rate exposes numerator denominator and server percent');
$check(Metrics::distribution([60,120,180,240])['median']===150,'even sample median');
$check(Metrics::distribution([1,2,3,4])['p90']===null,'small-sample tails unavailable');
$check(Metrics::distribution(range(1,20))['p90']===18,'tail percentile nearest-rank with explicit sample threshold');
$range=DateRange::from(['period'=>'today'],new DateTimeImmutable('2026-10-25 12:00:00',new DateTimeZone('UTC')));
$check($range->toUtc->getTimestamp()-$range->fromUtc->getTimestamp()===90000,'Romanian DST fallback calendar day is 25 hours');
$range=DateRange::from(['from'=>'2026-03-29','to'=>'2026-03-29'],new DateTimeImmutable('2026-10-06',new DateTimeZone('UTC')));
$check($range->toUtc->getTimestamp()-$range->fromUtc->getTimestamp()===82800,'spring calendar day is 23 hours');
$events=[
 ['action'=>'claimed','employee_uuid'=>'A','from_stage_id'=>'material-preparation','to_stage_id'=>null,'occurred_at'=>'2026-10-05 02:00:00','production_version_after'=>2,'meters_snapshot'=>null],
 ['action'=>'owner_reassigned','employee_uuid'=>'B','new_owner_employee_uuid'=>'B','from_stage_id'=>'material-preparation','to_stage_id'=>null,'occurred_at'=>'2026-10-05 03:00:00','production_version_after'=>3,'meters_snapshot'=>'29.000'],
 ['action'=>'stage_completed','employee_uuid'=>'B','from_stage_id'=>'material-preparation','to_stage_id'=>'workshop-receiving','occurred_at'=>'2026-10-05 04:00:00','production_version_after'=>4,'meters_snapshot'=>'29.000'],
 ['action'=>'claimed','employee_uuid'=>'C','from_stage_id'=>'workshop-receiving','to_stage_id'=>null,'occurred_at'=>'2026-10-05 04:05:00','production_version_after'=>5,'meters_snapshot'=>null],
 ['action'=>'fault_returned','employee_uuid'=>'manager','new_owner_employee_uuid'=>'A','from_stage_id'=>'workshop-receiving','to_stage_id'=>'material-preparation','occurred_at'=>'2026-10-05 05:00:00','production_version_after'=>6,'meters_snapshot'=>'9.000'],
];
$model=Lifecycle::derive($events);
$check($model['cuttingCompletion']===null,'approved fault return invalidates previous successful handoff, without editing history');
$events[]=['action'=>'stage_completed','employee_uuid'=>'A','from_stage_id'=>'material-preparation','to_stage_id'=>'workshop-receiving','occurred_at'=>'2026-10-05 06:00:00','production_version_after'=>7,'meters_snapshot'=>'29.000'];
$events[]=['action'=>'production_completed','employee_uuid'=>'driver','from_stage_id'=>'delivery','to_stage_id'=>null,'occurred_at'=>'2026-10-05 12:00:00','production_version_after'=>30,'meters_snapshot'=>'29.000'];
$model=Lifecycle::derive($events);
$check($model['cuttingCompletion']['employee_uuid']==='A' && $model['cuttingCompletion']['meters_snapshot']==='29.000','entire final completion belongs to A, never split with B');
$check(count($model['intervals'])===4 && $model['intervals'][1]['employeeId']==='B','earlier transfer owner handling preserved separately');
$check($model['firstClaimAt']==='2026-10-05 02:00:00' && $model['completedAt']==='2026-10-05 12:00:00','first real claim and final Livrare from canonical actions');
$check($model['intervals'][3]['stageId']==='material-preparation' && $model['intervals'][3]['end']==='2026-10-05 06:00:00','fault-return owner is responsible worker, not approving manager');
$check(!array_key_exists('score',$model),'no net or weighted score');
$order=['qr_created_at'=>'2026-10-05 02:00:00','first_claim_at'=>'2026-10-05 02:20:00','completed_at'=>'2026-10-05 04:00:00','cutting_employee_uuid'=>'B','cutting_completed_at'=>'2026-10-05 03:30:00','operational_status'=>'in_progress','source_reported_unavailable_at'=>'2026-10-05 02:40:00'];
$intervals=[['employee_uuid'=>'A','stage_id'=>'material-preparation','started_at'=>'2026-10-05 02:20:00','ended_at'=>'2026-10-05 03:00:00','start_version'=>2],['employee_uuid'=>'B','stage_id'=>'material-preparation','started_at'=>'2026-10-05 03:00:00','ended_at'=>'2026-10-05 03:30:00','start_version'=>5]];
$transfers=[['requested_at'=>'2026-10-05 02:30:00','resolved_at'=>'2026-10-05 03:00:00','decided_at'=>'2026-10-05 02:50:00']];
$exceptions=[['reported_at'=>'2026-10-05 02:45:00','resolved_at'=>'2026-10-05 03:15:00']];
$decisions=[['opened_at'=>'2026-10-05 02:45:00','decided_at'=>'2026-10-05 03:05:00']];
$pools=[['production_version'=>1,'occurred_at'=>'2026-10-05 02:00:00']];
$life=OrderMetrics::calculate($order,$intervals,$transfers,$exceptions,$decisions,$time,'2026-10-05 05:00:00',$pools);
$check($life['blockedUnion']['wallSeconds']===2700 && $life['activeHandling']['wallSeconds']===1500,'overlap removed once, source cancellation after work does not end accepted internal work');
$check($life['managerWaiting']['wallSeconds']===2100 && $life['transferWaiting']['wallSeconds']===1800 && $life['exceptionBlocked']['wallSeconds']===1800,'approval, transfer and exception waits are separate non-additive dimensions');
$check($life['cuttingWaiting']['wallSeconds']===1200 && $life['firstClaimDelay']['wallSeconds']===1200,'canonical pool wait ends at the actual owner interval');
$check($life['completedCuttingHandling']['wallSeconds']===900 && $life['cuttingActive']['wallSeconds']===1500,'final completing owner time distinct from all worker intervals');
$check($life['qrToCompletion']['wallSeconds']===7200 && $life['nonWorker']['wallSeconds']===5700,'full delivery cycle includes raw nonworker time, never double adds approval waits');
$cancelled=$order; $cancelled['operational_status']='unavailable'; $cancelled['completed_at']=null; $cancelled['first_claim_at']=null;
$cancelled['source_reported_unavailable_at']='2026-10-05 02:10:00';
$life=OrderMetrics::calculate($cancelled,[],[],[],[],$time,'2026-10-05 05:00:00');
$check($life['closedByCancellation'] && !$life['isComplete'] && $life['measuredUntil']==='2026-10-05 02:10:00' && $life['qrToCompletion']===null,'genuine cancellation before work closes partial lifecycle but never fabricates delivery');
$check(OrderMetrics::hasWorkBefore(['first_claim_at'=>null,'work_recorded_at'=>'2026-10-05 02:05:00'],'2026-10-05 02:10:00'),'canonical completed stage proves work before cancellation without fabricating a real claim after Root assignment');
$check(!OrderMetrics::hasWorkBefore(['first_claim_at'=>null],'2026-10-05 02:10:00'),'a bare Root assignment does not fabricate actual work evidence');
$range=DateRange::from(['from'=>'2026-10-05','to'=>'2026-10-05'],new DateTimeImmutable('2026-10-06',new DateTimeZone('UTC')));
$employees=[]; foreach (['A','B','C'] as $person) $employees[]=['employee_uuid'=>$person,'display_name'=>$person,'position_title'=>null,'status'=>'active','department_id'=>1,'department_key'=>'pregatire-material','department_name'=>'Tăiere'];
$order+=['cutting_meters'=>'29.000','cutting_lines'=>3];
$data=['employees'=>$employees,'orders'=>['O'=>$order],'intervals'=>['O'=>$intervals],'transfers'=>[],'exceptions'=>[],'decisions'=>[],'pools'=>['O'=>$pools],
 'quality'=>['O'=>[['employee_uuid'=>'A','occurred_at'=>'2026-10-05 04:10:00','event_type'=>'cutting_fault','line_count'=>1,'meters'=>'9.000','is_repeat'=>0],['employee_uuid'=>'A','occurred_at'=>'2026-10-05 04:20:00','event_type'=>'cutting_fault','line_count'=>1,'meters'=>'8.400','is_repeat'=>1],['employee_uuid'=>'C','occurred_at'=>'2026-10-05 04:20:00','event_type'=>'fault_detected','line_count'=>1,'meters'=>'8.400','is_repeat'=>1]]],
 'stageCompletions'=>[['employee_uuid'=>'C','order_uuid'=>'O','from_stage_id'=>'waiting','action'=>'production_submitted']]];
$metrics=EmployeeMetrics::calculate($data,$range,$time,'2026-10-06 06:00:00');
$check($metrics['A']['completedMeters']==='0.000' && $metrics['B']['completedMeters']==='29.000','prior owner receives handling only, final cutter owns whole successful volume');
$check($metrics['A']['faultMeters']==='17.400' && $metrics['C']['detectedMeters']==='8.400' && $metrics['C']['faultMeters']==='0.000','approved attributed versus detected quantities remain separate');
$check($metrics['A']['reworkCycles']===2 && $metrics['A']['repeatedCycles']===1 && $metrics['A']['repeatedOrders']===1 && $metrics['A']['repeatedMeters']==='8.400','distinct orders and repeated cycles/meters are not conflated');
$check($metrics['A']['daily']['2026-10-05']['faultMeters']==='17.400' && $metrics['C']['weekly']['2026-W41']['detectedMeters']==='8.400','daily and weekly exact quality meters stay separate');
$check($metrics['A']['faultMeterRate']['percent']===null && $metrics['C']['onlineSubmissions']===1,'zero completion denominator stays unavailable, online counts only canonical submissions');
$request=static fn(string $id,string $opened,?string $decided,string $status,?string $manager,?string $previous=null):array=>['decision_uuid'=>$id,'exception_uuid'=>'E','attempt_number'=>$previous===null?1:2,'previous_decision_uuid'=>$previous,'opened_at'=>$opened,'decided_at'=>$decided,'decided_by_employee_uuid'=>$manager,'status'=>$status,'decided_via'=>'backup_approver'];
$managerData=['employees'=>$employees,'decisions'=>['O'=>[$request('old','2026-10-04 12:00:00','2026-10-04 12:30:00','rejected','A'),$request('review','2026-10-05 02:00:00','2026-10-05 02:30:00','approved','B','old'),$request('pending','2026-10-04 12:00:00',null,'pending',null)]],
 'transfers'=>[],'transferDecisionTypes'=>[],'captured'=>[['request_type'=>'exception','request_uuid'=>'review'],['request_type'=>'exception','request_uuid'=>'pending']],
 'eligibility'=>[['request_type'=>'exception','request_uuid'=>'review','employee_uuid'=>'B','eligible_at'=>'2026-10-05 02:00:00'],['request_type'=>'exception','request_uuid'=>'pending','employee_uuid'=>'A','eligible_at'=>'2026-10-04 12:00:00']]];
$management=ManagerMetrics::calculate($managerData,$range,$time,'2026-10-05 03:00:00'); $byId=array_column($management['items'],null,'id');
$check($byId['A']['rereviewsAfterRejection']===1 && $byId['A']['rejected']===0,'rereview count is factual, linked to prior rejection, not a wrong decision in this period');
$check($byId['B']['approved']===1 && $byId['B']['backupDecisions']===1 && $byId['B']['byType']['exception']['approved']===1,'actual decision date and backup via retained');
$check($byId['A']['pendingEligible']===1 && $byId['A']['knownEligibleReceived']===0 && $byId['A']['pendingElapsed']['businessSeconds']===3600,'earlier pending request shown without inflating received-in-period counts or counting closed Sunday');
$managerData['captured']=[];
$management=ManagerMetrics::calculate($managerData,$range,$time,'2026-10-05 03:00:00');
$check($management['historicalRequestsWithoutEligibility']===1 && $management['items'][0]['received']===null,'historical eligibility not inferred from current roles');
$wire=UtcPresentation::format(['asOf'=>'2026-10-05 10:00:00.123456','company'=>['name'=>'2026-10-05 10:00:00'],'timeline'=>[['occurred_at'=>'2026-10-05 11:00:00.000000']], 'unknown'=>null]);
$check($wire['asOf']==='2026-10-05T10:00:00.123Z' && $wire['timeline'][0]['occurred_at']==='2026-10-05T11:00:00.000Z','API presents explicit UTC timestamps at all nested levels');
$check($wire['company']['name']==='2026-10-05 10:00:00' && $wire['unknown']===null,'presentation never rewrites human names or invents unknown timestamps');
echo "PASS {$checks} analytics formula checks\n";
