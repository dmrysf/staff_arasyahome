<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
use DateTimeImmutable;
use DateTimeZone;
final class EmployeeMetrics
{
    public static function calculate(array $data,DateRange $range,AnalyticsTime $time,string $asOf): array
    {
        $from=$range->fromUtc->format('Y-m-d H:i:s'); $to=min($range->toUtc->format('Y-m-d H:i:s'),$asOf);
        $inside=static fn(?string $date):bool => $date!==null && $date >= $from && $date < $to;
        $rows=[];
        foreach ($data['employees'] as $employee) {
            $rows[$employee['employee_uuid']]=['id'=>$employee['employee_uuid'],'name'=>$employee['display_name'],'positionTitle'=>$employee['position_title'],'status'=>$employee['status'],
                'department'=>['id'=>(int)$employee['department_id'],'key'=>$employee['department_key'],'name'=>$employee['department_name']],
                'completedOrders'=>0,'completedLines'=>0,'completedMeterUnits'=>0,'missingMeterSamples'=>0,'missingLineSamples'=>0,
                'faultOrders'=>[],'faultLines'=>0,'faultMeterUnits'=>0,'detectedOrders'=>[],'detectedLines'=>0,'detectedMeterUnits'=>0,'reworkCycles'=>0,'repeatedCycles'=>0,
                'repeatedOrders'=>[],'repeatedMeterUnits'=>0,'onlineSubmissions'=>0,
                'initiatedTransfers'=>0,'outgoingTransfers'=>0,'incomingTransfers'=>0,'activeHandling'=>['wallSeconds'=>0,'businessSeconds'=>0],
                'cuttingActiveHandling'=>['wallSeconds'=>0,'businessSeconds'=>0],'stageHandling'=>[],
                'completedHandling'=>['wallSeconds'=>[],'businessSeconds'=>[]],'stageCompletions'=>[], 'daily'=>[], 'relatedOrderIds'=>[]];
        }
        $day=static fn(string $date):string => (new DateTimeImmutable($date,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Bucharest'))->format('Y-m-d');
        foreach ($data['orders'] as $id=>$order) {
            $blocks=OrderMetrics::blocks($data['transfers'][$id]??[],$data['exceptions'][$id]??[],$asOf,$order);
            $worker=$order['cutting_employee_uuid'];
            if ($worker!==null && isset($rows[$worker]) && $inside($order['cutting_completed_at'])) {
                $r=&$rows[$worker]; $r['completedOrders']++; $r['relatedOrderIds'][$id]=true;
                if ($order['cutting_meters']!==null) $r['completedMeterUnits']+=Metrics::units($order['cutting_meters']); else $r['missingMeterSamples']++;
                if ($order['cutting_lines']!==null) $r['completedLines']+=(int)$order['cutting_lines']; else $r['missingLineSamples']++;
                $date=$day($order['cutting_completed_at']);
                $r['daily'][$date]['completedOrders']=($r['daily'][$date]['completedOrders']??0)+1;
                $r['daily'][$date]['meterUnits']=($r['daily'][$date]['meterUnits']??0)+Metrics::units($order['cutting_meters']??'0');
                unset($r);
                $lifecycle=OrderMetrics::calculate($order,$data['intervals'][$id]??[],$data['transfers'][$id]??[],$data['exceptions'][$id]??[],$data['decisions'][$id]??[],$time,$asOf,$data['pools'][$id]??[]);
                if ($lifecycle['completedCuttingHandling']!==null) foreach ($lifecycle['completedCuttingHandling'] as $clock=>$seconds) $rows[$worker]['completedHandling'][$clock][]=$seconds;
            }
            foreach ($data['intervals'][$id]??[] as $interval) {
                $employee=$interval['employee_uuid'];
                if (!isset($rows[$employee])) continue;
                $a=max($from,$interval['started_at']); $b=min($to,$interval['ended_at']??$asOf);
                if ($b<=$a) continue;
                $rows[$employee]['relatedOrderIds'][$id]=true;
                $active=$time->active($a,$b,$blocks);
                foreach ($active as $clock=>$seconds) $rows[$employee]['activeHandling'][$clock]+=$seconds;
                foreach ($active as $clock=>$seconds) {
                    $rows[$employee]['stageHandling'][$interval['stage_id']][$clock]=($rows[$employee]['stageHandling'][$interval['stage_id']][$clock]??0)+$seconds;
                    if ($interval['stage_id']==='material-preparation') $rows[$employee]['cuttingActiveHandling'][$clock]+=$seconds;
                }
                // Daily intersections use local calendar midnights, not fixed 24-hour UTC buckets.
                $local=(new DateTimeImmutable($a,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Bucharest'))->setTime(0,0);
                while ($local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')<$b) {
                    $next=$local->modify('+1 day'); $start=max($a,$local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
                    $end=min($b,$next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
                    $date=$local->format('Y-m-d'); $daily=$time->active($start,$end,$blocks);
                    foreach ($daily as $clock=>$seconds) $rows[$employee]['daily'][$date][$clock]=($rows[$employee]['daily'][$date][$clock]??0)+$seconds;
                    $local=$next;
                }
            }
            foreach ($data['quality'][$id]??[] as $event) {
                $employee=$event['employee_uuid']; if (!isset($rows[$employee]) || !$inside($event['occurred_at'])) continue;
                $r=&$rows[$employee]; $r['relatedOrderIds'][$id]=true; $fault=$event['event_type']==='cutting_fault'; $prefix=$fault?'fault':'detected';
                $r[$prefix.'Orders'][$id]=true; $r[$prefix.'Lines']+=(int)$event['line_count']; $r[$prefix.'MeterUnits']+=Metrics::units($event['meters']);
                if ($fault) { $r['reworkCycles']++; $r['repeatedCycles']+=(int)$event['is_repeat']; }
                if ($fault && (int)$event['is_repeat']===1) { $r['repeatedOrders'][$id]=true; $r['repeatedMeterUnits']+=Metrics::units($event['meters']); }
                $date=$day($event['occurred_at']); $r['daily'][$date][$prefix.'Cycles']=($r['daily'][$date][$prefix.'Cycles']??0)+1;
                $r['daily'][$date][$prefix.'MeterUnits']=($r['daily'][$date][$prefix.'MeterUnits']??0)+Metrics::units($event['meters']);
                unset($r);
            }
            foreach ($data['transfers'][$id]??[] as $transfer) {
                if ($inside($transfer['requested_at']) && isset($rows[$transfer['from_employee_uuid']])) {
                    $rows[$transfer['from_employee_uuid']]['initiatedTransfers']++; $rows[$transfer['from_employee_uuid']]['relatedOrderIds'][$id]=true;
                }
                if ($transfer['status']==='completed' && $inside($transfer['qr_verified_at'])) {
                    foreach (['from_employee_uuid'=>'outgoingTransfers','to_employee_uuid'=>'incomingTransfers'] as $field=>$metric) if (isset($rows[$transfer[$field]])) {
                        $rows[$transfer[$field]][$metric]++; $rows[$transfer[$field]]['relatedOrderIds'][$id]=true;
                    }
                }
            }
        }
        foreach ($data['stageCompletions'] as $event) {
            if (!isset($rows[$event['employee_uuid']])) continue;
            $r=&$rows[$event['employee_uuid']]; $stage=$event['from_stage_id'];
            if ($event['action']==='production_submitted') { $r['onlineSubmissions']++; $r['relatedOrderIds'][$event['order_uuid']]=true; unset($r); continue; }
            $r['stageCompletions'][$stage]=($r['stageCompletions'][$stage]??0)+1; unset($r);
        }
        foreach ($rows as &$r) {
            $r['completedMeters']=Metrics::meters($r['completedMeterUnits']);
            $r['repeatedOrders']=count($r['repeatedOrders']); $r['repeatedMeters']=Metrics::meters($r['repeatedMeterUnits']); unset($r['repeatedMeterUnits']);
            $r['averageMetersPerOrder']=$r['completedOrders']===0?null:Metrics::meters((int)round($r['completedMeterUnits']/$r['completedOrders']));
            foreach (['fault','detected'] as $prefix) {
                $r[$prefix.'Orders']=count($r[$prefix.'Orders']); $r[$prefix.'Meters']=Metrics::meters($r[$prefix.'MeterUnits']); unset($r[$prefix.'MeterUnits']);
            }
            $r['faultMeterRate']=Metrics::rate($r['faultMeters'],$r['completedMeters']);
            $r['faultOrderRate']=['numerator'=>$r['faultOrders'],'denominator'=>$r['completedOrders'],'percent'=>$r['completedOrders']===0?null:round($r['faultOrders']/$r['completedOrders']*100,2)];
            $r['faultLineRate']=['numerator'=>$r['faultLines'],'denominator'=>$r['missingLineSamples']?null:$r['completedLines'],'percent'=>$r['missingLineSamples']||$r['completedLines']===0?null:round($r['faultLines']/$r['completedLines']*100,2)];
            if ($r['missingMeterSamples']) { $r['averageMetersPerOrder']=null; $r['faultMeterRate']['percent']=null; }
            foreach ($r['completedHandling'] as $clock=>$values) $r['completedHandling'][$clock]=Metrics::distribution($values)+['totalSeconds'=>array_sum($values)];
            $weekly=[]; ksort($r['daily']);
            foreach ($r['daily'] as $date=>&$trend) {
                $trend += ['completedOrders'=>0,'meterUnits'=>0,'wallSeconds'=>0,'businessSeconds'=>0,'faultCycles'=>0,'detectedCycles'=>0,'faultMeterUnits'=>0,'detectedMeterUnits'=>0];
                $week=(new DateTimeImmutable($date))->format('o-\WW');
                foreach ($trend as $key=>$value) $weekly[$week][$key]=($weekly[$week][$key]??0)+$value;
                $trend['meters']=Metrics::meters($trend['meterUnits']); unset($trend['meterUnits']);
                foreach (['fault','detected'] as $prefix) { $trend[$prefix.'Meters']=Metrics::meters($trend[$prefix.'MeterUnits']); unset($trend[$prefix.'MeterUnits']); }
            }
            unset($trend);
            foreach ($weekly as &$trend) {
                $trend['meters']=Metrics::meters($trend['meterUnits']); unset($trend['meterUnits']);
                foreach (['fault','detected'] as $prefix) { $trend[$prefix.'Meters']=Metrics::meters($trend[$prefix.'MeterUnits']); unset($trend[$prefix.'MeterUnits']); }
            } unset($trend);
            $r['weekly']=$weekly; $r['relatedOrderIds']=array_keys($r['relatedOrderIds']); unset($r['completedMeterUnits']);
        }
        unset($r); return $rows;
    }
}
