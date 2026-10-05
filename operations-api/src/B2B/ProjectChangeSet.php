<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * One atomic batch of workspace operations inside a locked project transaction. Node edits are version checked;
 * copies (duplicate, apply to rooms, copy treatment set) load their source subtree once and insert every new row with
 * multi-row statements, so 20 or 200 copies cost a constant number of queries per level.
 */
final class ProjectChangeSet
{
    private const UUID='/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    /** @var array<string,int> node id => version after this batch */
    public array $versions=[];
    /** @var array<int,list<string>> operation index => created node ids */
    public array $created=[];
    /** @var list<array{0:string,1:string,2:string,3:array}> */
    public array $events=[];

    public function __construct(private PDO $pdo,private ProjectStore $store,private string $project,private string $now) {}

    public function apply(int $n,mixed $op): void
    {
        if(!is_array($op) || array_is_list($op) || !is_string($op['op']??null)) $this->invalid($n,'op');
        $name=$op['op'];
        if(preg_match('/^(zone|room|opening|treatment)\.(create|update|remove)$/D',$name,$m)) {
            $level=$m[1];
            match($m[2]) {
                'create'=>$this->create($n,$level,$op),
                'update'=>$this->update($n,$level,$op),
                'remove'=>$this->remove($n,$level,$op),
            };
            return;
        }
        match($name) {
            'room.move'=>$this->move($n,$op),
            'room.duplicate'=>$this->duplicateRoom($n,$op),
            'opening.duplicate'=>$this->duplicateOpening($n,$op),
            'treatment.duplicate'=>$this->duplicateTreatment($n,$op),
            'room.apply'=>$this->applyRoom($n,$op),
            'treatment.copySet'=>$this->copyTreatmentSet($n,$op),
            'reorder'=>$this->reorder($n,$op),
            default=>$this->invalid($n,'op'),
        };
    }

    private function create(int $n,string $level,array $op): void
    {
        $parentField=ProjectStore::PARENT_FIELD[$level];
        $this->keys($n,$op,array_values(array_filter(['op','id',$parentField,'fields'])));
        $id=$this->uuid($n,$op,'id');
        $meta=ProjectStore::LEVELS[$level];
        $s=$this->pdo->prepare('SELECT 1 FROM '.$meta['table'].' WHERE '.$meta['id'].'=?'); $s->execute([$id]);
        if($s->fetchColumn()!==false) $this->invalid($n,'id');
        $parent=null;
        if($parentField!==null) {
            $parent=$this->uuid($n,$op,$parentField);
            $this->load($n,$this->parentLevel($level),$parent);
        }
        $data=$this->fields($n,$level,$op['fields']??null);
        $row=[$meta['id']=>$id,'project_uuid'=>$this->project];
        if($parent!==null) $row[$meta['parent']]=$parent;
        $row['position']=$this->nextPosition($level,$parent);
        foreach(ProjectStore::COLUMNS[$level] as $field=>$column) $row[$column]=$data[$field];
        $row+=['version'=>1,'copied_from_uuid'=>null,'created_at'=>$this->now,'updated_at'=>$this->now];
        $this->insert($level,[$row]);
        $this->versions[$id]=1;
        $this->created[$n]=[$id];
        $this->events[]=[$level,$id,$level.'_created',[]];
    }

    private function update(int $n,string $level,array $op): void
    {
        $this->keys($n,$op,['op','id','expectedVersion','fields']);
        $id=$this->uuid($n,$op,'id');
        $row=$this->load($n,$level,$id,$op['expectedVersion']??null);
        $data=$this->fields($n,$level,$op['fields']??null);
        $before=ProjectStore::node($level,$row);
        $changed=array_values(array_filter(array_keys(ProjectStore::COLUMNS[$level]),static fn(string $f): bool=>$before[$f]!==$data[$f]));
        if($changed===[]) { $this->versions[$id]=(int)$row['version']; return; }
        $meta=ProjectStore::LEVELS[$level];
        $columns=ProjectStore::COLUMNS[$level];
        $this->pdo->prepare('UPDATE '.$meta['table'].' SET '.implode(',',array_map(static fn(string $c): string=>"$c=?",$columns)).
            ',version=version+1,updated_at=? WHERE '.$meta['id'].'=?')->execute([...array_map(static fn(string $f)=>$data[$f],array_keys($columns)),$this->now,$id]);
        $this->versions[$id]=(int)$row['version']+1;
        $this->events[]=[$level,$id,$level.'_updated',['fields'=>$changed]];
    }

    private function remove(int $n,string $level,array $op): void
    {
        $this->keys($n,$op,['op','id','expectedVersion']);
        $id=$this->uuid($n,$op,'id');
        $this->load($n,$level,$id,$op['expectedVersion']??null);
        $this->deleteSubtree($level,[$id],true);
        unset($this->versions[$id]);
        $this->events[]=[$level,$id,$level.'_removed',[]];
    }

    private function move(int $n,array $op): void
    {
        $this->keys($n,$op,['op','id','expectedVersion','zoneId']);
        $id=$this->uuid($n,$op,'id');
        $row=$this->load($n,'room',$id,$op['expectedVersion']??null);
        $zone=$this->uuid($n,$op,'zoneId');
        $this->load($n,'zone',$zone);
        if($zone===$row['zone_uuid']) { $this->versions[$id]=(int)$row['version']; return; }
        $this->pdo->prepare('UPDATE b2b_project_rooms SET zone_uuid=?,position=?,version=version+1,updated_at=? WHERE room_uuid=?')
            ->execute([$zone,$this->nextPosition('room',$zone),$this->now,$id]);
        $this->versions[$id]=(int)$row['version']+1;
        $this->events[]=['room',$id,'room_moved',['fromZoneId'=>$row['zone_uuid'],'toZoneId'=>$zone]];
    }

    /** N independent copies of one room (openings and treatments included), appended to the target zone. */
    private function duplicateRoom(int $n,array $op): void
    {
        $this->keys($n,$op,['op','id','names','zoneId']);
        $id=$this->uuid($n,$op,'id');
        $source=$this->load($n,'room',$id);
        $names=$this->names($n,$op);
        $zone=($op['zoneId']??null)===null ? $source['zone_uuid'] : $this->uuid($n,$op,'zoneId');
        $this->load($n,'zone',$zone);
        $openings=$this->store->rows('opening',$this->project,[$id]);
        $treatments=$this->store->rows('treatment',$this->project,array_column($openings,'opening_uuid'));
        $position=$this->nextPosition('room',$zone);
        $rooms=[]; $newOpenings=[]; $newTreatments=[];
        foreach($names as $i=>$name) {
            $room=$this->copy($source,'room',['zone_uuid'=>$zone,'position'=>$position+$i,'name'=>$name]);
            $rooms[]=$room;
            $this->copyOpenings($openings,$treatments,$room['room_uuid'],1,$newOpenings,$newTreatments);
        }
        $this->insert('room',$rooms); $this->insert('opening',$newOpenings); $this->insert('treatment',$newTreatments);
        $this->created[$n]=array_column($rooms,'room_uuid');
        foreach($this->created[$n] as $new) $this->versions[$new]=1;
        $this->events[]=['room',$id,'room_duplicated',['count'=>count($rooms),'zoneId'=>$zone,'openings'=>count($newOpenings),'treatments'=>count($newTreatments)]];
    }

    /** N independent copies of one opening with its treatments, in the same room (for example 20 identical windows). */
    private function duplicateOpening(int $n,array $op): void
    {
        $this->keys($n,$op,['op','id','names']);
        $id=$this->uuid($n,$op,'id');
        $source=$this->load($n,'opening',$id);
        $names=$this->names($n,$op);
        $treatments=$this->store->rows('treatment',$this->project,[$id]);
        $position=$this->nextPosition('opening',$source['room_uuid']);
        $openings=[]; $newTreatments=[];
        foreach($names as $i=>$name) {
            $opening=$this->copy($source,'opening',['position'=>$position+$i,'name'=>$name]);
            $openings[]=$opening;
            foreach($treatments as $t) $newTreatments[]=$this->copy($t,'treatment',['opening_uuid'=>$opening['opening_uuid']]);
        }
        $this->insert('opening',$openings); $this->insert('treatment',$newTreatments);
        $this->created[$n]=array_column($openings,'opening_uuid');
        foreach($this->created[$n] as $new) $this->versions[$new]=1;
        $this->events[]=['opening',$id,'opening_duplicated',['count'=>count($openings),'treatments'=>count($newTreatments)]];
    }

    private function duplicateTreatment(int $n,array $op): void
    {
        $this->keys($n,$op,['op','id']);
        $id=$this->uuid($n,$op,'id');
        $source=$this->load($n,'treatment',$id);
        $copy=$this->copy($source,'treatment',['position'=>$this->nextPosition('treatment',$source['opening_uuid'])]);
        $this->insert('treatment',[$copy]);
        $this->created[$n]=[$copy['treatment_uuid']];
        $this->versions[$copy['treatment_uuid']]=1;
        $this->events[]=['treatment',$id,'treatment_duplicated',['count'=>1]];
    }

    /** Copies the openings and treatments of one room into selected rooms; replace first removes their own openings. */
    private function applyRoom(int $n,array $op): void
    {
        $this->keys($n,$op,['op','sourceId','targetIds','mode']);
        $source=$this->uuid($n,$op,'sourceId');
        $this->load($n,'room',$source);
        $targets=$this->targets($n,$op,'targetIds','room',$source);
        $mode=$this->mode($n,$op);
        if($mode==='replace') $this->deleteSubtree('opening',array_column($this->store->rows('opening',$this->project,$targets),'opening_uuid'),true);
        $openings=$this->store->rows('opening',$this->project,[$source]);
        $treatments=$this->store->rows('treatment',$this->project,array_column($openings,'opening_uuid'));
        $next=$this->nextPositions('opening',$targets);
        $newOpenings=[]; $newTreatments=[];
        foreach($targets as $target) $this->copyOpenings($openings,$treatments,$target,$next[$target],$newOpenings,$newTreatments);
        $this->insert('opening',$newOpenings); $this->insert('treatment',$newTreatments);
        $this->bump('room',$targets);
        $this->created[$n]=array_column($newOpenings,'opening_uuid');
        foreach($this->created[$n] as $new) $this->versions[$new]=1;
        $this->events[]=['room',$source,'room_configuration_applied',['targetCount'=>count($targets),'mode'=>$mode,'openings'=>count($newOpenings),'treatments'=>count($newTreatments)]];
    }

    /** Copies the treatment set of one opening onto selected openings. */
    private function copyTreatmentSet(int $n,array $op): void
    {
        $this->keys($n,$op,['op','sourceOpeningId','targetOpeningIds','mode']);
        $source=$this->uuid($n,$op,'sourceOpeningId');
        $this->load($n,'opening',$source);
        $targets=$this->targets($n,$op,'targetOpeningIds','opening',$source);
        $mode=$this->mode($n,$op);
        if($mode==='replace') $this->deleteSubtree('treatment',array_column($this->store->rows('treatment',$this->project,$targets),'treatment_uuid'),true);
        $treatments=$this->store->rows('treatment',$this->project,[$source]);
        $next=$this->nextPositions('treatment',$targets);
        $rows=[];
        foreach($targets as $target) foreach($treatments as $i=>$t) $rows[]=$this->copy($t,'treatment',['opening_uuid'=>$target,'position'=>$next[$target]+$i]);
        $this->insert('treatment',$rows);
        $this->bump('opening',$targets);
        $this->created[$n]=array_column($rows,'treatment_uuid');
        foreach($this->created[$n] as $new) $this->versions[$new]=1;
        $this->events[]=['opening',$source,'treatment_set_copied',['targetCount'=>count($targets),'mode'=>$mode,'treatments'=>count($rows)]];
    }

    /** Sets the order of all children of one parent; the id list must be exactly the current children. */
    private function reorder(int $n,array $op): void
    {
        $this->keys($n,$op,['op','level','parentId','ids']);
        $level=$op['level']??null;
        if(!is_string($level) || !isset(ProjectStore::LEVELS[$level])) $this->invalid($n,'level');
        $meta=ProjectStore::LEVELS[$level];
        $parent=null;
        if($meta['parent']!==null) { $parent=$this->uuid($n,$op,'parentId'); $this->load($n,$this->parentLevel($level),$parent); }
        elseif(($op['parentId']??null)!==null) $this->invalid($n,'parentId');
        $ids=$op['ids']??null;
        $current=array_column($this->store->rows($level,$this->project,$parent===null?null:[$parent]),$meta['id']);
        if(!is_array($ids) || !array_is_list($ids) || count($ids)!==count($current) || count(array_unique($ids,SORT_REGULAR))!==count($ids) || array_diff($current,$ids)!==[])
            throw new ApiException(409,'PROJECT_CHANGED','Children changed. Reload before continuing.',['operation'=>$n,'subject'=>$level,'reason'=>'children']);
        if($ids===$current) return;
        $s=$this->pdo->prepare('UPDATE '.$meta['table'].' SET position=? WHERE '.$meta['id'].'=?');
        foreach($ids as $i=>$child) $s->execute([$i+1,$child]);
        $this->events[]=[$parent===null?'project':$this->parentLevel($level),$parent??$this->project,'children_reordered',['level'=>$level,'count'=>count($ids)]];
    }

    /** Builds copies of openings (and their treatments) under one room, starting at the given position. */
    private function copyOpenings(array $openings,array $treatments,string $room,int $start,array &$newOpenings,array &$newTreatments): void
    {
        $byOpening=[];
        foreach($treatments as $t) $byOpening[$t['opening_uuid']][]=$t;
        foreach($openings as $i=>$o) {
            $copy=$this->copy($o,'opening',['room_uuid'=>$room,'position'=>$start+$i]);
            $newOpenings[]=$copy;
            foreach($byOpening[$o['opening_uuid']]??[] as $t) $newTreatments[]=$this->copy($t,'treatment',['opening_uuid'=>$copy['opening_uuid']]);
        }
    }

    /** A new independent row: fresh id, version 1, copiedFrom trace, same business values unless overridden. */
    private function copy(array $row,string $level,array $overrides): array
    {
        $id=ProjectStore::LEVELS[$level]['id'];
        return array_replace($row,[$id=>Uuid::v4(),'version'=>1,'copied_from_uuid'=>$row[$id],'created_at'=>$this->now,'updated_at'=>$this->now],$overrides);
    }

    /** Multi-row INSERT in bounded chunks; FK order is the caller's responsibility (parents first). */
    private function insert(string $level,array $rows): void
    {
        if($rows===[]) return;
        $columns=array_keys($rows[0]);
        $table=ProjectStore::LEVELS[$level]['table'];
        foreach(array_chunk($rows,250) as $chunk) {
            $params=[];
            foreach($chunk as $row) foreach($columns as $c) $params[]=$row[$c];
            $tuple='('.implode(',',array_fill(0,count($columns),'?')).')';
            $this->pdo->prepare("INSERT INTO $table(".implode(',',$columns).') VALUES '.implode(',',array_fill(0,count($chunk),$tuple)))->execute($params);
        }
    }

    /** Deletes nodes and everything below them, children first. Never touches commercial or trace rows. */
    private function deleteSubtree(string $level,array $ids,bool $self): void
    {
        if($ids===[]) return;
        $meta=ProjectStore::LEVELS[$level];
        if($meta['child']!==null) {
            $child=$meta['child'];
            $this->deleteSubtree($child,array_column($this->store->rows($child,$this->project,$ids),ProjectStore::LEVELS[$child]['id']),true);
        }
        if($self) foreach(array_chunk($ids,500) as $chunk)
            $this->pdo->prepare('DELETE FROM '.$meta['table'].' WHERE project_uuid=? AND '.$meta['id'].' IN ('.implode(',',array_fill(0,count($chunk),'?')).')')
                ->execute([$this->project,...$chunk]);
    }

    private function bump(string $level,array $ids): void
    {
        $meta=ProjectStore::LEVELS[$level];
        $in=implode(',',array_fill(0,count($ids),'?'));
        $this->pdo->prepare('UPDATE '.$meta['table'].' SET version=version+1,updated_at=? WHERE '.$meta['id']." IN ($in)")->execute([$this->now,...$ids]);
        $s=$this->pdo->prepare('SELECT '.$meta['id'].',version FROM '.$meta['table'].' WHERE '.$meta['id']." IN ($in)"); $s->execute($ids);
        foreach($s->fetchAll(PDO::FETCH_NUM) as [$id,$version]) $this->versions[$id]=(int)$version;
    }

    private function nextPosition(string $level,?string $parent): int
    {
        $meta=ProjectStore::LEVELS[$level];
        $s=$this->pdo->prepare('SELECT COALESCE(MAX(position),0) FROM '.$meta['table'].' WHERE project_uuid=?'.($parent===null?'':' AND '.$meta['parent'].'=?'));
        $s->execute($parent===null?[$this->project]:[$this->project,$parent]);
        return (int)$s->fetchColumn()+1;
    }

    /** @return array<string,int> */
    private function nextPositions(string $level,array $parents): array
    {
        $meta=ProjectStore::LEVELS[$level];
        $out=array_fill_keys($parents,1);
        $s=$this->pdo->prepare('SELECT '.$meta['parent'].',MAX(position) FROM '.$meta['table'].' WHERE project_uuid=? AND '.$meta['parent'].' IN ('.
            implode(',',array_fill(0,count($parents),'?')).') GROUP BY '.$meta['parent']);
        $s->execute([$this->project,...$parents]);
        foreach($s->fetchAll(PDO::FETCH_NUM) as [$parent,$max]) $out[$parent]=(int)$max+1;
        return $out;
    }

    /** Loads one node of this project, optionally checking its expected version. */
    private function load(int $n,string $level,string $id,mixed $expectedVersion=false): array
    {
        $meta=ProjectStore::LEVELS[$level];
        $s=$this->pdo->prepare('SELECT * FROM '.$meta['table'].' WHERE '.$meta['id'].'=? AND project_uuid=?');
        $s->execute([$id,$this->project]);
        $row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new ApiException(409,'PROJECT_CHANGED','Part of the project was removed. Reload before continuing.',['operation'=>$n,'subject'=>$level,'id'=>$id,'reason'=>'missing']);
        if($expectedVersion!==false) {
            if(!is_int($expectedVersion) || $expectedVersion<1) $this->invalid($n,'expectedVersion');
            if((int)$row['version']!==$expectedVersion)
                throw new ApiException(409,'PROJECT_CHANGED','Project changed. Reload before continuing.',['operation'=>$n,'subject'=>$level,'id'=>$id,'reason'=>'version','currentVersion'=>(int)$row['version']]);
        }
        return $row;
    }

    private function fields(int $n,string $level,mixed $fields): array
    {
        $prefix="operations.$n.fields.";
        return match($level) {
            'zone'=>ProjectInput::zone($fields,$prefix), 'room'=>ProjectInput::room($fields,$prefix),
            'opening'=>ProjectInput::opening($fields,$prefix), 'treatment'=>ProjectInput::treatment($fields,$prefix),
        };
    }

    /** @return list<string> */
    private function names(int $n,array $op): array
    {
        $names=$op['names']??null;
        if(!is_array($names) || !array_is_list($names) || $names===[] || count($names)>ProjectCommands::MAX_COPIES) $this->invalid($n,'names');
        $out=[];
        foreach($names as $i=>$name) {
            $name=is_string($name)?trim($name):null;
            if($name===null || $name==='' || mb_strlen($name)>120 || !mb_check_encoding($name,'UTF-8') || preg_match('/[\x00-\x1F\x7F]/',$name)) $this->invalid($n,"names.$i");
            $out[]=$name;
        }
        return $out;
    }

    /** @return list<string> */
    private function targets(int $n,array $op,string $field,string $level,string $source): array
    {
        $ids=$op[$field]??null;
        if(!is_array($ids) || !array_is_list($ids) || $ids===[] || count($ids)>ProjectCommands::MAX_TARGETS || count(array_unique($ids,SORT_REGULAR))!==count($ids)) $this->invalid($n,$field);
        foreach($ids as $i=>$id) if(!is_string($id) || preg_match(self::UUID,$id)!==1 || $id===$source) $this->invalid($n,"$field.$i");
        $meta=ProjectStore::LEVELS[$level];
        $s=$this->pdo->prepare('SELECT '.$meta['id'].' FROM '.$meta['table'].' WHERE project_uuid=? AND '.$meta['id'].' IN ('.implode(',',array_fill(0,count($ids),'?')).')');
        $s->execute([$this->project,...$ids]);
        $found=$s->fetchAll(PDO::FETCH_COLUMN);
        if(count($found)!==count($ids))
            throw new ApiException(409,'PROJECT_CHANGED','Part of the project was removed. Reload before continuing.',['operation'=>$n,'subject'=>$level,'reason'=>'missing','ids'=>array_values(array_diff($ids,$found))]);
        return $ids;
    }

    private function mode(int $n,array $op): string
    {
        $mode=$op['mode']??null;
        if(!in_array($mode,['append','replace'],true)) $this->invalid($n,'mode');
        return $mode;
    }

    private function uuid(int $n,array $op,string $field): string
    {
        $value=$op[$field]??null;
        if(!is_string($value) || preg_match(self::UUID,$value)!==1) $this->invalid($n,$field);
        return $value;
    }

    private function keys(int $n,array $op,array $allowed): void
    {
        foreach(array_diff(array_keys($op),$allowed) as $key) $this->invalid($n,(string)$key,'unknown');
    }

    private function parentLevel(string $level): string
    {
        return match($level) { 'room'=>'zone','opening'=>'room','treatment'=>'opening' };
    }

    private function invalid(int $n,string $field,string $reason='invalid'): never
    {
        throw new ApiException(422,'VALIDATION_FAILED','Some operations are invalid.',['operation'=>$n,'fields'=>["operations.$n.$field"=>$reason]]);
    }
}
