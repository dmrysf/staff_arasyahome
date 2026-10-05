<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\Http\ApiException;
use RuntimeException;

/**
 * Exact integer fixed-point arithmetic. Prices are cents, meters thousandths and rates basis points.
 * Round base, discount and VAT HALF-UP at line level, then sum the rounded line results.
 * Input bounds keep every intermediate product and a 100-line aggregate below PHP_INT_MAX.
 */
final class OrderCalculator
{
    public static function calculate(string $currency,array $lines): array
    {
        if(PHP_INT_SIZE<8) throw new RuntimeException('B2B orders require 64-bit PHP integers.');
        $complete=count($lines)>0;
        $out=[];
        $sum=['net'=>0,'vat'=>0,'gross'=>0];
        foreach($lines as $line) {
            $totals=null;
            $billingQuantity=$line['pricingUnit']==='piece' ? $line['quantity'] :
                ($line['meters']===null ? null : OrderInput::fixed($line['meters'],3));
            if($line['unitPriceNet']!==null && $line['vatPercent']!==null && $billingQuantity!==null && $billingQuantity>0) {
                $price=OrderInput::fixed($line['unitPriceNet'],2);
                $base=$line['pricingUnit']==='piece' ? $billingQuantity*$price : self::round($billingQuantity*$price,1000);
                $discount=self::round($base*OrderInput::fixed($line['discountPercent'],2),10000);
                $net=$base-$discount;
                $vat=self::round($net*OrderInput::fixed($line['vatPercent'],2),10000);
                $gross=$net+$vat;
                $totals=array_map(OrderInput::format(...),[
                    'baseNet'=>$base,'discountNet'=>$discount,'net'=>$net,'vat'=>$vat,'gross'=>$gross,
                ]);
                $sum['net']+=$net; $sum['vat']+=$vat; $sum['gross']+=$gross;
            } else $complete=false;
            if(trim($line['productCode'])==='') $complete=false;
            $out[]=['totals'=>$totals];
        }
        return ['currencyCode'=>$currency,'lines'=>$out,'totals'=>$complete?array_map(OrderInput::format(...),$sum):null,'complete'=>$complete];
    }

    public static function requireComplete(array $lines): void
    {
        $errors=[];
        if($lines===[]) $errors['lines']='required';
        foreach($lines as $n=>$line) {
            foreach(['productCode','unitPriceNet','vatPercent'] as $field)
                if($line[$field]===null || $line[$field]==='') $errors["lines.{$n}.{$field}"]='required';
            if($line['pricingUnit']==='meter' && ($line['meters']===null || OrderInput::fixed($line['meters'],3)===0))
                $errors["lines.{$n}.meters"]='required';
        }
        if($errors!==[]) throw new ApiException(422,'VALIDATION_FAILED','The order is incomplete.',['fields'=>$errors]);
    }
    private static function round(int $numerator,int $denominator): int
    {
        return intdiv($numerator+intdiv($denominator,2),$denominator);
    }
}
