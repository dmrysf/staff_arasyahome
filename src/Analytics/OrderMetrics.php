<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
final class OrderMetrics
{
    public static function hasWorkBefore(array $order,string $at): bool
    {
        $evidence=$order['work_recorded_at']??$order['first_claim_at']??null;
        return $evidence!==null && $evidence<=$at;
    }
    public static function approvalWindows(array $transfers,array $decisions,string $end): array
    {
        $windows=[];
        foreach ($decisions as $row) $windows[]=['start'=>$row['opened_at'],'end'=>$row['decided_at']??$row['exception_resolved_at']??$end];
        foreach ($transfers as $row) $windows[]=['start'=>$row['requested_at'],'end'=>$row['decided_at']??$row['resolved_at']??$end];
        return $windows;
    }
    public static function blocks(array $transfers,array $exceptions,string $asOf,?array $order=null): array
    {
        $blocks=[];
        foreach ($transfers as $row) $blocks[]=['start'=>$row['requested_at'],'end'=>$row['resolved_at']??$asOf];
        foreach ($exceptions as $row) $blocks[]=['start'=>$row['reported_at'],'end'=>$row['resolved_at']??$asOf];
        if (($order['operational_status']??null)==='unavailable' && ($order['source_reported_unavailable_at']??null)!==null) $blocks[]=['start'=>$order['source_reported_unavailable_at'],'end'=>$asOf];
        // Waiting for a production document revision (stale content or root revoke) is never active work.
        foreach (self::documentWindows($order,$asOf) as $window) $blocks[]=$window;
        return $blocks;
    }
    /** @return list<array{start:string,end:string}> */
    public static function documentWindows(?array $order,string $asOf): array
    {
        $windows=[];
        foreach ($order['document_blocks']??[] as $row) $windows[]=['start'=>$row['started_at'],'end'=>$row['ended_at']??$asOf];
        return $windows;
    }
    public static function calculate(array $order,array $intervals,array $transfers,array $exceptions,array $decisions,AnalyticsTime $time,string $asOf,array $pools=[]): array
    {
        $cancelled=$order['completed_at']===null && ($order['operational_status']??null)==='unavailable' && ($order['source_reported_unavailable_at']??null)!==null;
        $end=$order['completed_at']??($cancelled?min($asOf,$order['source_reported_unavailable_at']):$asOf); $start=$order['qr_created_at'];
        $blocks=self::blocks($transfers,$exceptions,$end,$order);
        $active=['wallSeconds'=>0,'businessSeconds'=>0]; $cuttingActive=$active; $raw=[]; $completedHandling=null; $cuttingIntervals=[];
        foreach ($intervals as $interval) {
            $finish=min($interval['ended_at']??$end,$end);
            $duration=$time->active($interval['started_at'],$finish,$blocks);
            foreach ($active as $key=>$value) $active[$key]+=$duration[$key];
            if ($interval['stage_id']==='material-preparation') {
                $cuttingIntervals[]=$interval;
                foreach ($duration as $clock=>$seconds) $cuttingActive[$clock]+=$seconds;
            }
            $raw[]=['employeeId'=>$interval['employee_uuid'],'stageId'=>$interval['stage_id'],'start'=>$interval['started_at'],'end'=>$interval['ended_at'],'duration'=>$duration];
            if ($interval['stage_id']==='material-preparation' && $interval['employee_uuid']===$order['cutting_employee_uuid'] && $interval['ended_at']===$order['cutting_completed_at']) $completedHandling=$duration;
        }
        $waiting=static function(array $windows,bool $approval=false) use($time,$start,$end):array {
            if ($start===null) return ['wallSeconds'=>null,'businessSeconds'=>null];
            return $time->blocked($start,$end,$windows,$approval);
        };
        usort($cuttingIntervals,static fn($a,$b)=>(int)$a['start_version']<=>(int)$b['start_version']);
        usort($pools,static fn($a,$b)=>(int)$a['production_version']<=>(int)$b['production_version']);
        $poolWindows=[]; $ownerIndex=0;
        foreach ($pools as $pool) {
            while (isset($cuttingIntervals[$ownerIndex]) && ((int)$cuttingIntervals[$ownerIndex]['start_version']<(int)$pool['production_version'] || $cuttingIntervals[$ownerIndex]['started_at']<$pool['occurred_at'])) $ownerIndex++;
            $poolWindows[]=['start'=>$pool['occurred_at'],'end'=>$cuttingIntervals[$ownerIndex]['started_at']??$end];
        }
        $manager=self::approvalWindows($transfers,$decisions,$end); $transfer=[]; $exception=[];
        foreach ($transfers as $row) {
            $transfer[]=['start'=>$row['requested_at'],'end'=>$row['resolved_at']??$end];
        }
        foreach ($exceptions as $row) $exception[]=['start'=>$row['reported_at'],'end'=>$row['resolved_at']??$end];
        $qrClock=$start===null?null:$time->duration($start,$end);
        $nonWorker=$qrClock===null?null:['wallSeconds'=>max(0,$qrClock['wallSeconds']-$active['wallSeconds']),'businessSeconds'=>max(0,$qrClock['businessSeconds']-$active['businessSeconds'])];
        return ['isComplete'=>$order['completed_at']!==null,'closedByCancellation'=>$cancelled,'measuredUntil'=>$end,
            'qrToCompletion'=>$order['completed_at']!==null && $start!==null?$time->duration($start,$end):null,
            'claimToCompletion'=>$order['completed_at']!==null && $order['first_claim_at']!==null?$time->duration($order['first_claim_at'],$end):null,
            'elapsedFromQr'=>$qrClock,'firstClaimDelay'=>$start!==null && $order['first_claim_at']!==null?$time->duration($start,$order['first_claim_at']):null,
            'activeHandling'=>$active,'cuttingActive'=>$cuttingIntervals?$cuttingActive:null,'cuttingWaiting'=>$pools?$waiting($poolWindows):null,'completedCuttingHandling'=>$completedHandling,
            'managerWaiting'=>$waiting($manager,true),'transferWaiting'=>$waiting($transfer),'exceptionBlocked'=>$waiting($exception),
            'documentRevisionWaiting'=>$waiting(self::documentWindows($order,$end)),
            'blockedUnion'=>$waiting($blocks),'nonWorker'=>$nonWorker,'intervals'=>$raw];
    }
}
