<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\OperationalOrderItem;
use Arasya\Operations\Support\Uuid;

/** Frozen manufacturing context only. No money, live identity refresh, formulas or omitted lines. */
final class ProductionInput
{
    public static function company(?array $snapshot): array
    {
        $out=[];
        foreach(['legalName','companyCode','countryCode','taxIdentifier'] as $field) {
            if(!is_string($snapshot[$field]??null) || $snapshot[$field]==='')
                throw new ApiException(422,'PRODUCTION_SNAPSHOT_INVALID','The frozen company identity is incomplete.');
            $out[$field]=$snapshot[$field];
        }
        return $out;
    }

    /**
     * @param array<string,array> $trace frozen project location per line id (only for project-origin lines)
     * @return list<OperationalOrderItem>
     */
    public static function items(array $lines,array $trace=[]): array
    {
        if($lines===[]) throw new ApiException(422,'PRODUCTION_NOT_ELIGIBLE','No production lines exist.');
        $out=[];
        foreach($lines as $n=>$line) {
            try {
                if(!in_array($line['kind'],['curtain','drapery','other'],true)) throw new \InvalidArgumentException();
                $out[]=new OperationalOrderItem(Uuid::v4(),$line['id'],$n+1,$line['productName']??$line['productCode'],
                    $line['productCode'],$line['variant'],$line['color'],self::measurement($line['width']),self::measurement($line['height']),
                    'cm',self::measurement($line['meters']),$line['quantity'],
                    ['kind'=>$line['kind'],'notes'=>$line['notes'],'productionNotes'=>$line['productionNotes']]+
                        (isset($trace[$line['id']])?['project'=>self::project($trace[$line['id']])]:[]));
            } catch(\InvalidArgumentException $e) {
                throw new ApiException(422,'PRODUCTION_LINE_UNSUPPORTED','A line cannot be represented safely in production.',
                    ['lineId'=>$line['id'],'lineNumber'=>$n+1]);
            }
        }
        return $out;
    }

    /** Whitelisted, money-free project location: identities, labels and opening geometry frozen at conversion. */
    public static function project(array $trace): array
    {
        $pick=static fn(array $source,array $keys): array=>array_intersect_key($source,array_flip($keys))+array_fill_keys($keys,null);
        return [
            'project'=>$pick($trace['project']??[],['id','code','name','propertyType']),
            'zone'=>$pick($trace['zone']??[],['id','name','zoneType','level','building']),
            'room'=>$pick($trace['room']??[],['id','name']),
            'opening'=>$pick($trace['opening']??[],['id','name','openingType','wallIndex','width','height','sillHeight','mounting','railType']),
            'treatment'=>$pick($trace['treatment']??[],['id','treatmentType','panelLayout']),
        ];
    }

    private static function measurement(?string $value): ?float
    {
        // Canonical manufacturing DECIMAL(12,3); Classic meters are the total for the whole line.
        if($value===null) return null;
        if(!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D',$value)) throw new \InvalidArgumentException();
        return (float)$value;
    }
}
