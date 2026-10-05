<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use PDO;

/**
 * Column maps and set-based reads of the project workspace. Every read loads one level of the hierarchy with a single
 * indexed query (never one query per node), so outline, room, scene and commercial reads stay O(levels) in queries.
 */
final readonly class ProjectStore
{
    public const LEVELS=[
        'zone'=>['table'=>'b2b_project_zones','id'=>'zone_uuid','parent'=>null,'child'=>'room'],
        'room'=>['table'=>'b2b_project_rooms','id'=>'room_uuid','parent'=>'zone_uuid','child'=>'opening'],
        'opening'=>['table'=>'b2b_project_openings','id'=>'opening_uuid','parent'=>'room_uuid','child'=>'treatment'],
        'treatment'=>['table'=>'b2b_project_treatments','id'=>'treatment_uuid','parent'=>'opening_uuid','child'=>null],
    ];
    public const COLUMNS=[
        'zone'=>['name'=>'name','zoneType'=>'zone_type','level'=>'level_number','building'=>'building','notes'=>'notes'],
        'room'=>['name'=>'name','widthCm'=>'width_cm','lengthCm'=>'length_cm','ceilingHeightCm'=>'ceiling_height_cm','notes'=>'notes'],
        'opening'=>['name'=>'name','openingType'=>'opening_type','wallIndex'=>'wall_index','width'=>'width','height'=>'height',
            'sillHeight'=>'sill_height','offsetLeft'=>'offset_left','wallWidth'=>'wall_width','mounting'=>'mounting','railType'=>'rail_type','notes'=>'notes'],
        'treatment'=>['treatmentType'=>'treatment_type','panelLayout'=>'panel_layout','productCode'=>'product_code','productName'=>'product_name_snapshot',
            'variant'=>'variant_snapshot','color'=>'color_snapshot','width'=>'width','height'=>'height','quantity'=>'quantity','meters'=>'meters',
            'pricingUnit'=>'pricing_unit','unitPriceNet'=>'unit_price_net','discountPercent'=>'discount_percent','vatPercent'=>'vat_percent',
            'notes'=>'notes','productionNotes'=>'production_notes'],
    ];
    private const INTEGERS=['level','wallIndex','quantity'];
    /** The parent field name in the API for each level. */
    public const PARENT_FIELD=['zone'=>null,'room'=>'zoneId','opening'=>'roomId','treatment'=>'openingId'];

    public function __construct(private PDO $pdo) {}

    /** API shape of one stored node row. */
    public static function node(string $level,array $row): array
    {
        $meta=self::LEVELS[$level];
        $out=['id'=>$row[$meta['id']]];
        if($meta['parent']!==null) $out[self::PARENT_FIELD[$level]]=$row[$meta['parent']];
        $out['position']=(int)$row['position'];
        foreach(self::COLUMNS[$level] as $field=>$column) {
            $value=$row[$column];
            $out[$field]=$value!==null && in_array($field,self::INTEGERS,true) ? (int)$value : $value;
        }
        if($level==='treatment') $out['kind']=ProjectInput::KIND[$out['treatmentType']];
        $out['version']=(int)$row['version'];
        $out['copiedFromId']=$row['copied_from_uuid'];
        return $out;
    }

    /** @return list<array<string,mixed>> raw rows of one level, optionally restricted to parent ids, in tree order. */
    public function rows(string $level,string $project,?array $parents=null,bool $forUpdate=false): array
    {
        $meta=self::LEVELS[$level];
        if($parents===[]) return [];
        $sql='SELECT * FROM '.$meta['table'].' WHERE project_uuid=?';
        $params=[$project];
        if($parents!==null) {
            $sql.=' AND '.$meta['parent'].' IN ('.implode(',',array_fill(0,count($parents),'?')).')';
            array_push($params,...array_values($parents));
        }
        $sql.=' ORDER BY '.($meta['parent']===null?'':$meta['parent'].',').'position,'.$meta['id'].($forUpdate?' FOR UPDATE':'');
        $s=$this->pdo->prepare($sql); $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** One project row, optionally locked. */
    public function project(string $id,bool $forUpdate=false): ?array
    {
        $s=$this->pdo->prepare('SELECT * FROM b2b_projects WHERE project_uuid=?'.($forUpdate?' FOR UPDATE':''));
        $s->execute([$id]);
        $row=$s->fetch(PDO::FETCH_ASSOC);
        return $row?:null;
    }

    /**
     * Treatments of the project that already sit on a live (not cancelled) commercial order line. A treatment whose
     * draft line was removed, or whose order was cancelled, is free again.
     * @return array<string,array{orderId:string,orderCode:string,status:string,lineId:string}>
     */
    public function orderedTreatments(string $project,?array $treatments=null): array
    {
        if($treatments===[]) return [];
        $sql='SELECT pl.treatment_uuid,pl.line_uuid,o.order_uuid,o.order_code,o.status FROM b2b_project_order_lines pl
            JOIN b2b_orders o ON o.order_uuid=pl.order_uuid JOIN b2b_order_lines l ON l.line_uuid=pl.line_uuid AND l.order_uuid=pl.order_uuid
            WHERE pl.project_uuid=? AND o.status<>\'cancelled\'';
        $params=[$project];
        if($treatments!==null) {
            $sql.=' AND pl.treatment_uuid IN ('.implode(',',array_fill(0,count($treatments),'?')).')';
            array_push($params,...array_values($treatments));
        }
        $s=$this->pdo->prepare($sql.' ORDER BY o.created_at,o.order_uuid'); $s->execute($params);
        $out=[];
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['treatment_uuid']]??=['orderId'=>$r['order_uuid'],'orderCode'=>$r['order_code'],'status'=>$r['status'],'lineId'=>$r['line_uuid']];
        return $out;
    }

    /** Treatment rows as Classic line inputs (same field names and decimal strings), used by projection and conversion. */
    public static function line(array $treatment): array
    {
        return ['id'=>null,'productCode'=>$treatment['productCode'],'productName'=>$treatment['productName'],'variant'=>$treatment['variant'],
            'color'=>$treatment['color'],'kind'=>$treatment['kind'],'width'=>$treatment['width'],'height'=>$treatment['height'],
            'quantity'=>$treatment['quantity'],'meters'=>$treatment['meters'],'pricingUnit'=>$treatment['pricingUnit'],
            'unitPriceNet'=>$treatment['unitPriceNet'],'discountPercent'=>$treatment['discountPercent'],'vatPercent'=>$treatment['vatPercent'],
            'notes'=>$treatment['notes'],'productionNotes'=>$treatment['productionNotes']];
    }
}
