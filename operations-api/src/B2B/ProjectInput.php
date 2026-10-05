<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\Http\ApiException;

/**
 * Strict project workspace input. Measurements are bounded decimal strings in centimetres (3 decimals, the Classic
 * width/height scale); treatment commercial fields are validated by the Classic line validator itself, so a
 * treatment and an order line always speak the same language. No value passes through a binary float.
 */
final class ProjectInput
{
    public const PROJECT_FIELDS=['companyId','name','propertyType','currencyCode','siteAddress','customerReference','notes'];
    public const PROPERTY_TYPES=['apartment','house','hotel','hospital','restaurant','office','commercial','other'];
    public const ZONE_FIELDS=['name','zoneType','level','building','notes'];
    public const ROOM_FIELDS=['name','widthCm','lengthCm','ceilingHeightCm','notes'];
    public const OPENING_FIELDS=['name','openingType','wallIndex','width','height','sillHeight','offsetLeft','wallWidth','mounting','railType','notes'];
    public const TREATMENT_TYPES=['sheer','drapery','blackout','rail','accessory','other'];
    public const TREATMENT_FIELDS=['treatmentType','panelLayout','productCode','productName','variant','color','width','height','quantity','meters',
        'pricingUnit','unitPriceNet','discountPercent','vatPercent','notes','productionNotes'];
    /** Deterministic mapping to the Classic line kind; the treatment type only adds visual layer meaning. */
    public const KIND=['sheer'=>'curtain','drapery'=>'drapery','blackout'=>'drapery','rail'=>'other','accessory'=>'other','other'=>'other'];

    private array $errors=[];
    private function __construct(private string $prefix) {}

    public static function project(array $input): array
    {
        $v=new self('');
        $v->keys($input,self::PROJECT_FIELDS);
        $data=[
            'companyId'=>$v->uuid($input['companyId']??null,'companyId'),
            'name'=>$v->required($input['name']??null,'name',160),
            'propertyType'=>$v->choice($input['propertyType']??null,'propertyType',self::PROPERTY_TYPES),
            'currencyCode'=>$v->choice($input['currencyCode']??'RON','currencyCode',['RON','EUR']),
            'siteAddress'=>$v->text($input['siteAddress']??null,'siteAddress',300),
            'customerReference'=>$v->text($input['customerReference']??null,'customerReference',160),
            'notes'=>$v->text($input['notes']??null,'notes',2000,true),
        ];
        $v->finish();
        return $data;
    }

    public static function zone(mixed $input,string $prefix): array
    {
        $v=new self($prefix); $input=$v->object($input);
        $v->keys($input,self::ZONE_FIELDS);
        $level=$input['level']??null;
        if($level!==null && (!is_int($level) || $level<-20 || $level>300)) $v->fail('level');
        $data=['name'=>$v->required($input['name']??null,'name',120),'zoneType'=>$v->choice($input['zoneType']??'floor','zoneType',['floor','zone']),
            'level'=>$level,'building'=>$v->text($input['building']??null,'building',80),'notes'=>$v->text($input['notes']??null,'notes',2000,true)];
        $v->finish();
        return $data;
    }

    public static function room(mixed $input,string $prefix): array
    {
        $v=new self($prefix); $input=$v->object($input);
        $v->keys($input,self::ROOM_FIELDS);
        $data=['name'=>$v->required($input['name']??null,'name',120)];
        foreach(['widthCm','lengthCm','ceilingHeightCm'] as $field) $data[$field]=$v->measure($input[$field]??null,$field,true);
        $data['notes']=$v->text($input['notes']??null,'notes',2000,true);
        $v->finish();
        return $data;
    }

    public static function opening(mixed $input,string $prefix): array
    {
        $v=new self($prefix); $input=$v->object($input);
        $v->keys($input,self::OPENING_FIELDS);
        $wall=$input['wallIndex']??null;
        if($wall!==null && (!is_int($wall) || $wall<1 || $wall>12)) $v->fail('wallIndex');
        $data=['name'=>$v->required($input['name']??null,'name',120),
            'openingType'=>$v->choice($input['openingType']??'window','openingType',['window','door','balcony_door','wall','other']),'wallIndex'=>$wall];
        foreach(['width'=>true,'height'=>true,'sillHeight'=>false,'offsetLeft'=>false,'wallWidth'=>true] as $field=>$positive)
            $data[$field]=$v->measure($input[$field]??null,$field,$positive);
        $mounting=$input['mounting']??null;
        $data['mounting']=$mounting===null?null:$v->choice($mounting,'mounting',['ceiling','wall','recess']);
        $data['railType']=$v->text($input['railType']??null,'railType',120);
        $data['notes']=$v->text($input['notes']??null,'notes',2000,true);
        $v->finish();
        return $data;
    }

    public static function treatment(mixed $input,string $prefix): array
    {
        $v=new self($prefix); $input=$v->object($input);
        $v->keys($input,self::TREATMENT_FIELDS);
        $type=$input['treatmentType']??null;
        if(!is_string($type) || !in_array($type,self::TREATMENT_TYPES,true))
            throw new ApiException(422,'UNSUPPORTED_TREATMENT','This treatment type is not supported.',['fields'=>[$prefix.'treatmentType'=>'invalid']]);
        $layout=$input['panelLayout']??null;
        $layout=$layout===null?null:$v->choice($layout,'panelLayout',['single','pair','left','right']);
        $line=array_diff_key($input,['treatmentType'=>1,'panelLayout'=>1])+['kind'=>self::KIND[$type]];
        foreach(['productCode'=>'','discountPercent'=>'0','pricingUnit'=>'piece','quantity'=>1] as $field=>$default) $line[$field]??=$default;
        try { $normalized=OrderInput::line($line); }
        catch(ApiException $e) {
            foreach(($e->details['fields']??[]) as $field=>$reason) $v->errors[$prefix.preg_replace('/^lines\.0\./','',$field)]=$reason;
            $normalized=null;
        }
        $v->finish();
        unset($normalized['id'],$normalized['kind']);
        return ['treatmentType'=>$type,'panelLayout'=>$layout]+$normalized;
    }

    private function object(mixed $input): array
    {
        if(!is_array($input) || ($input!==[] && array_is_list($input)))
            throw new ApiException(422,'VALIDATION_FAILED','Some fields are invalid.',['fields'=>[rtrim($this->prefix,'.')=>'invalid']]);
        return $input;
    }
    private function keys(array $input,array $allowed): void
    {
        foreach(array_diff(array_keys($input),$allowed) as $key) $this->errors[$this->prefix.$key]='unknown';
    }
    private function fail(string $field,string $reason='invalid'): void { $this->errors[$this->prefix.$field]=$reason; }
    private function uuid(mixed $value,string $field): ?string
    {
        if(!is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$value)!==1) { $this->fail($field); return null; }
        return $value;
    }
    private function choice(mixed $value,string $field,array $allowed): ?string
    {
        if(!is_string($value) || !in_array($value,$allowed,true)) { $this->fail($field); return null; }
        return $value;
    }
    private function required(mixed $value,string $field,int $max): ?string
    {
        $text=$this->text($value,$field,$max);
        if($text===null && !isset($this->errors[$this->prefix.$field])) $this->fail($field,'required');
        return $text;
    }
    private function text(mixed $value,string $field,int $max,bool $multiline=false): ?string
    {
        if($value===null) return null;
        if(!is_string($value) || !mb_check_encoding($value,'UTF-8')) { $this->fail($field); return null; }
        $value=trim(str_replace("\r\n","\n",$value));
        if(mb_strlen($value)>$max) $this->fail($field,'too_long');
        if(preg_match($multiline?'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/':'/[\x00-\x1F\x7F]/',$value)) $this->fail($field);
        return $value===''?null:$value;
    }
    /** Centimetres with up to 3 decimals, at most 99999.999, the Classic width/height bounds. */
    private function measure(mixed $value,string $field,bool $positive): ?string
    {
        if($value===null) return null;
        if(!is_string($value) || preg_match('/^\d{1,5}(?:\.\d{1,3})?$/D',$value)!==1) { $this->fail($field); return null; }
        $fixed=OrderInput::fixed($value,3);
        if($positive && $fixed===0) $this->fail($field);
        return OrderInput::format($fixed,3);
    }
    private function finish(): void
    {
        if($this->errors!==[]) {
            ksort($this->errors);
            throw new ApiException(422,'VALIDATION_FAILED','Some fields are invalid.',['fields'=>$this->errors]);
        }
    }
}
