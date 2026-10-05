<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\Http\ApiException;

/** Strict, bounded decimal strings. No financial input passes through a binary float. */
final class OrderInput
{
    public const FIELDS=['companyId','currencyCode','contactId','billingAddressId','deliveryAddressId','customerReference','notes','productionNotes','lines'];
    public const LINE_FIELDS=['id','productCode','productName','variant','color','kind','width','height','quantity','meters','pricingUnit','unitPriceNet','discountPercent','vatPercent','notes','productionNotes'];
    private array $errors=[];

    public static function fields(array $input): array
    {
        $v=new self();
        $v->keys($input,self::FIELDS,'');
        $data=['companyId'=>$v->uuid($input['companyId']??null,'companyId',true)]+$v->commercial($input);
        foreach(['contactId','billingAddressId','deliveryAddressId'] as $field) $data[$field]=$v->uuid($input[$field]??null,$field);
        $data['customerReference']=$v->text($input['customerReference']??null,'customerReference',160);
        foreach(['notes','productionNotes'] as $field) $data[$field]=$v->text($input[$field]??null,$field,2000,true);
        $v->finish();
        return $data;
    }
    public static function calculation(array $input): array
    {
        $v=new self(); $v->keys($input,['currencyCode','lines'],'');
        $data=$v->commercial($input); $v->finish(); return $data;
    }
    public static function line(array $input): array { return self::calculation(['lines'=>[$input]])['lines'][0]; }
    public static function version(mixed $value): int
    {
        if(!is_int($value) || $value<1 || $value>4294967294)
            throw new ApiException(400,'INVALID_REQUEST','expectedVersion must be a positive integer.');
        return $value;
    }
    private function commercial(array $input): array
    {
        $currency=$input['currencyCode']??'RON';
        if(!in_array($currency,['RON','EUR'],true)) $this->errors['currencyCode']='invalid';
        $lines=$input['lines']??[];
        if(!is_array($lines) || !array_is_list($lines) || count($lines)>100) { $this->errors['lines']='invalid'; $lines=[]; }
        $out=[]; $ids=[];
        foreach($lines as $n=>$line) {
            $prefix="lines.{$n}.";
            if(!is_array($line) || array_is_list($line)) { $this->errors["lines.{$n}"]='invalid'; continue; }
            $this->keys($line,self::LINE_FIELDS,$prefix);
            $data=['id'=>$this->uuid($line['id']??null,$prefix.'id')];
            if($data['id']!==null) {
                if(isset($ids[$data['id']])) $this->errors[$prefix.'id']='invalid';
                $ids[$data['id']]=true;
            }
            foreach(['productCode','productName','variant','color'] as $field)
                $data[$field]=$this->text($line[$field]??null,$prefix.$field,160)??($field==='productCode'?'':null);
            foreach(['kind'=>['curtain','drapery','other'],'pricingUnit'=>['piece','meter']] as $field=>$allowed) {
                $data[$field]=$line[$field]??null;
                if(!in_array($data[$field],$allowed,true)) $this->errors[$prefix.$field]='invalid';
            }
            $data['quantity']=$line['quantity']??null;
            if(!is_int($data['quantity']) || $data['quantity']<1 || $data['quantity']>99999) $this->errors[$prefix.'quantity']='invalid';
            foreach([
                'width'=>[3,99999999,true], 'height'=>[3,99999999,true], 'meters'=>[3,99999999,false],
                'unitPriceNet'=>[2,99999999,false], 'discountPercent'=>[2,10000,false], 'vatPercent'=>[2,10000,false],
            ] as $field=>[$scale,$max,$positive]) {
                $data[$field]=$this->decimal($line[$field]??($field==='discountPercent'?'0':null),$prefix.$field,$scale,$max,$positive);
            }
            foreach(['notes','productionNotes'] as $field) $data[$field]=$this->text($line[$field]??null,$prefix.$field,2000,true);
            $out[]=$data;
        }
        return ['currencyCode'=>$currency,'lines'=>$out];
    }
    private function keys(array $input,array $allowed,string $prefix): void
    {
        foreach(array_diff(array_keys($input),$allowed) as $key) $this->errors[$prefix.$key]='unknown';
    }
    private function uuid(mixed $value,string $field,bool $required=false): ?string
    {
        if($value===null && !$required) return null;
        if(!is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$value)!==1) {
            $this->errors[$field]='invalid'; return null;
        }
        return $value;
    }
    private function text(mixed $value,string $field,int $max,bool $multiline=false): ?string
    {
        if($value===null) return null;
        if(!is_string($value) || !mb_check_encoding($value,'UTF-8')) { $this->errors[$field]='invalid'; return null; }
        $value=trim(str_replace("\r\n","\n",$value));
        if(mb_strlen($value)>$max) $this->errors[$field]='too_long';
        if(preg_match($multiline?'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/':'/[\x00-\x1F\x7F]/',$value)) $this->errors[$field]='invalid';
        return $value===''?null:$value;
    }
    private function decimal(mixed $value,string $field,int $scale,int $max,bool $positive): ?string
    {
        if($value===null) return null;
        if(!is_string($value) || strlen($value)>16 || preg_match('/^\d{1,7}(?:\.\d{1,'.$scale.'})?$/D',$value)!==1) {
            $this->errors[$field]='invalid'; return null;
        }
        $fixed=self::fixed($value,$scale);
        if($fixed>$max || ($positive && $fixed===0)) $this->errors[$field]='invalid';
        return self::format($fixed,$scale);
    }
    public static function fixed(string $value,int $scale): int
    {
        $parts=explode('.',$value);
        return (int)$parts[0]*(10**$scale)+(int)str_pad($parts[1]??'',$scale,'0');
    }
    public static function format(int $value,int $scale=2): string
    {
        $unit=10**$scale;
        return intdiv($value,$unit).'.'.str_pad((string)($value%$unit),$scale,'0',STR_PAD_LEFT);
    }
    private function finish(): void
    {
        if($this->errors!==[]) {
            ksort($this->errors);
            throw new ApiException(422,'VALIDATION_FAILED','Some fields are invalid.',['fields'=>$this->errors]);
        }
    }
}
