<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
final class Lifecycle
{
    /** Projection only: canonical events are never updated or deleted. */
    public static function derive(array $events): array
    {
        usort($events,static fn(array $a,array $b):int => ((int)$a['production_version_after']<=>(int)$b['production_version_after']) ?: strcmp($a['event_id']??'',$b['event_id']??''));
        $intervals=[]; $open=null; $first=null; $completed=null; $cutting=null;
        foreach ($events as $event) {
            $action=$event['action']; $at=$event['occurred_at'];
            if (in_array($action,['claimed','owner_reassigned','owner_released','stage_completed','production_completed','fault_returned'],true) && $open!==null) {
                $open['end']=$at; $intervals[]=$open; $open=null;
            }
            if ($action==='claimed') $first ??= $at;
            if (in_array($action,['claimed','owner_reassigned','fault_returned'],true)) {
                $owner=$action==='claimed'?$event['employee_uuid']:($event['new_owner_employee_uuid']??null);
                if ($owner!==null) $open=['employeeId'=>$owner,'stageId'=>$action==='fault_returned'?$event['to_stage_id']:$event['from_stage_id'],
                    'start'=>$at,'end'=>null,'startVersion'=>(int)$event['production_version_after']];
            }
            if ($action==='fault_returned') $cutting=null;
            if ($action==='stage_completed' && $event['from_stage_id']==='material-preparation' && $event['to_stage_id']==='workshop-receiving') $cutting=$event;
            if ($action==='production_completed' && $event['from_stage_id']==='delivery') $completed=$at;
        }
        if ($open!==null) $intervals[]=$open;
        return ['intervals'=>$intervals,'firstClaimAt'=>$first,'completedAt'=>$completed,'cuttingCompletion'=>$cutting];
    }
}
