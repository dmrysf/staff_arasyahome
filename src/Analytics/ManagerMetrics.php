<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
final class ManagerMetrics
{
    public static function calculate(array $data,DateRange $range,AnalyticsTime $time,string $asOf): array
    {
        $from=$range->fromUtc->format('Y-m-d H:i:s'); $to=min($range->toUtc->format('Y-m-d H:i:s'),$asOf);
        $inside=static fn(?string $date):bool => $date!==null && $date >= $from && $date < $to;
        $names=[]; foreach ($data['employees'] as $employee) $names[$employee['employee_uuid']]=$employee['display_name'];
        $rows=[]; $requests=[]; $captured=[]; $historical=0; $pending=[]; $previous=[];
        foreach ($data['decisions'] as $attempts) foreach ($attempts as $attempt) $previous[$attempt['decision_uuid']]=$attempt;
        foreach ($data['captured'] as $request) $captured[$request['request_type'].':'.$request['request_uuid']]=true;
        $ensure=static function(string $employee) use(&$rows,$names):void {
            $rows[$employee]??=['id'=>$employee,'name'=>$names[$employee]??null,'knownEligibleReceived'=>0,'approved'=>0,'rejected'=>0,'decisions'=>0,'backupDecisions'=>0,'rereviewsAfterRejection'=>0,
                'pendingEligible'=>0,'pendingElapsed'=>['wallSeconds'=>0,'businessSeconds'=>0],'byType'=>['exception'=>['approved'=>0,'rejected'=>0],'transfer'=>['approved'=>0,'rejected'=>0]],'wall'=>[],'business'=>[]];
        };
        foreach ($data['eligibility'] as $eligible) { $ensure($eligible['employee_uuid']); if ($inside($eligible['eligible_at'])) $rows[$eligible['employee_uuid']]['knownEligibleReceived']++; }
        $add=static function(array $request) use(&$requests,&$rows,&$historical,&$pending,$previous,$captured,$inside,$ensure,$time,$asOf):void {
            $key=$request['type'].':'.$request['id'];
            if ($inside($request['openedAt']) && !isset($captured[$key])) $historical++;
            if (!$inside($request['openedAt']) && !$inside($request['decidedAt']) && !($request['decidedAt']===null && $request['status']==='pending')) return;
            $request['eligibilityCaptured']=isset($captured[$key]);
            $request['response']=$time->duration($request['openedAt'],$request['decidedAt']??$request['resolvedAt']??$asOf,true);
            $request['isPending']=$request['decidedAt']===null && $request['status']==='pending';
            if ($request['isPending']) $pending[$key]=$request['response'];
            $old=$previous[$request['previousDecisionId']??'']??null;
            if ($inside($request['openedAt']) && $old!==null && $old['status']==='rejected' && $old['decided_by_employee_uuid']!==null) {
                $ensure($old['decided_by_employee_uuid']); $rows[$old['decided_by_employee_uuid']]['rereviewsAfterRejection']++;
            }
            $requests[]=$request;
            $employee=$request['decidedBy'];
            if ($employee===null || !$inside($request['decidedAt']) || !in_array($request['status'],['approved','rejected'],true)) return;
            $ensure($employee); $r=&$rows[$employee]; $r[$request['status']]++; $r['decisions']++;
            $r['backupDecisions']+=$request['via']==='backup_approver'?1:0;
            $r['byType'][$request['type']][$request['status']]++;
            $r['wall'][]=$request['response']['wallSeconds']; $r['business'][]=$request['response']['businessSeconds']; unset($r);
        };
        foreach ($data['decisions'] as $orderId=>$attempts) foreach ($attempts as $attempt) $add([
            'id'=>$attempt['decision_uuid'],'type'=>'exception','orderId'=>$orderId,'exceptionId'=>$attempt['exception_uuid'],
            'attempt'=>(int)$attempt['attempt_number'],'previousDecisionId'=>$attempt['previous_decision_uuid'],
            'openedAt'=>$attempt['opened_at'],'decidedAt'=>$attempt['decided_at'],'decidedBy'=>$attempt['decided_by_employee_uuid'],
            'status'=>$attempt['status'],'via'=>$attempt['decided_via'],'resolvedAt'=>$attempt['exception_resolved_at']??null]);
        foreach ($data['transfers'] as $orderId=>$transfers) foreach ($transfers as $transfer) {
            // A later cancellation cannot rewrite an earlier approved decision. Read immutable IAM audit.
            $status=$data['transferDecisionTypes'][$transfer['transfer_uuid']]??($transfer['decided_at']===null?$transfer['status']:'unknown');
            $add(['id'=>$transfer['transfer_uuid'],'type'=>'transfer','orderId'=>$orderId,'attempt'=>1,'previousDecisionId'=>null,
                'openedAt'=>$transfer['requested_at'],'decidedAt'=>$transfer['decided_at'],'decidedBy'=>$transfer['decided_by_employee_uuid'],
                'status'=>$status,'via'=>$transfer['decided_via'],'resolvedAt'=>$transfer['resolved_at']]);
        }
        foreach ($data['eligibility'] as $eligible) {
            $key=$eligible['request_type'].':'.$eligible['request_uuid']; if (!isset($pending[$key])) continue;
            $rows[$eligible['employee_uuid']]['pendingEligible']++;
            foreach ($pending[$key] as $clock=>$seconds) $rows[$eligible['employee_uuid']]['pendingElapsed'][$clock]+=$seconds;
        }
        foreach ($rows as &$r) {
            $r['received']=$historical===0?$r['knownEligibleReceived']:null;
            $r['wallResponse']=Metrics::distribution($r['wall']); $r['businessResponse']=Metrics::distribution($r['business']); unset($r['wall'],$r['business']);
        } unset($r);
        usort($requests,static fn($a,$b)=>strcmp($a['openedAt'],$b['openedAt'])?:strcmp($a['id'],$b['id']));
        return ['items'=>array_values($rows),'requests'=>$requests,'historicalRequestsWithoutEligibility'=>$historical,'pendingIncludesEarlierRequests'=>true,'eligibilityMethod'=>'snapshot_at_opening_not_historical_role_inference'];
    }
}
