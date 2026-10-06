<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
use InvalidArgumentException;
final class Metrics
{
    public static function units(string $meters): int
    {
        if (!preg_match('/^(\d{1,12})(?:\.(\d{1,3}))?$/D',$meters,$m)) throw new InvalidArgumentException('Invalid canonical meter value');
        return (int)$m[1]*1000 + (int)str_pad($m[2]??'',3,'0');
    }
    public static function meters(int $units): string { return intdiv($units,1000).'.'.str_pad((string)($units%1000),3,'0',STR_PAD_LEFT); }
    public static function rate(string $numerator, string $denominator): array
    {
        $n=self::units($numerator); $d=self::units($denominator);
        return ['numerator'=>self::meters($n),'denominator'=>self::meters($d),'percent'=>$d===0?null:number_format($n/$d*100,2,'.','')];
    }
    /** Median averages middle pair; quartiles and tails use nearest rank. Tails require N >= 20. */
    public static function distribution(array $values): array
    {
        sort($values,SORT_NUMERIC); $n=count($values);
        $rank=static fn(float $p): int|float|null => $n===0?null:$values[max(0,(int)ceil($p*$n)-1)];
        return ['sampleCount'=>$n,'mean'=>$n===0?null:array_sum($values)/$n,
            'median'=>$n===0?null:($n%2?$values[intdiv($n,2)]:($values[$n/2-1]+$values[$n/2])/2),
            'p25'=>$n>=4?$rank(.25):null,'p75'=>$n>=4?$rank(.75):null,
            'p90'=>$n>=20?$rank(.9):null,'p95'=>$n>=20?$rank(.95):null];
    }
}
