<?php
declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;

/** Persistence and identity snapshots used inside the command transaction. */
final readonly class OrderStore
{
    public const LINE_COLUMNS = [
        'id'=>'line_uuid', 'productCode'=>'product_code', 'productName'=>'product_name_snapshot',
        'variant'=>'variant_snapshot', 'color'=>'color_snapshot', 'kind'=>'item_type',
        'width'=>'width', 'height'=>'height', 'quantity'=>'quantity', 'meters'=>'meters',
        'pricingUnit'=>'pricing_unit', 'unitPriceNet'=>'unit_price_net',
        'discountPercent'=>'discount_percent', 'vatPercent'=>'vat_percent',
        'notes'=>'notes', 'productionNotes'=>'production_notes',
    ];
    public const SNAPSHOT_COLUMNS = [
        'companySnapshot'=>'company_snapshot', 'contactSnapshot'=>'contact_snapshot',
        'billingAddressSnapshot'=>'billing_address_snapshot', 'deliveryAddressSnapshot'=>'delivery_address_snapshot',
    ];

    public function __construct(private PDO $pdo) {}

    public function lines(string $id): array
    {
        $s=$this->pdo->prepare('SELECT * FROM b2b_order_lines WHERE order_uuid=? ORDER BY line_number');
        $s->execute([$id]);
        return array_map(static function(array $r): array {
            $line=[];
            foreach(self::LINE_COLUMNS as $field=>$column) $line[$field]=$r[$column];
            $line['quantity']=(int)$line['quantity'];
            return $line;
        },$s->fetchAll(PDO::FETCH_ASSOC));
    }

    public function fields(array $o): array
    {
        return [
            'companyId'=>$o['company_uuid'], 'currencyCode'=>$o['currency_code'],
            'contactId'=>$o['contact_uuid'], 'billingAddressId'=>$o['billing_address_uuid'],
            'deliveryAddressId'=>$o['delivery_address_uuid'], 'customerReference'=>$o['customer_reference'],
            'notes'=>$o['notes'], 'productionNotes'=>$o['production_notes'],
            'lines'=>$this->lines($o['order_uuid']),
        ];
    }

    public function snapshots(array $company,array $data,bool $clearInvalid=false): array
    {
        if($company['status']!=='active') throw new ApiException(409,'COMPANY_INACTIVE','Company is inactive.');
        $snap=['companySnapshot'=>[
            'legalName'=>$company['legal_name'], 'displayName'=>$company['display_name'],
            'countryCode'=>$company['country_code'], 'taxIdentifier'=>$company['tax_identifier'],
            'vatNumber'=>$company['vat_number'], 'registrationNumber'=>$company['registration_number'],
            'companyCode'=>$company['company_code'],
        ]];
        foreach(['contactId'=>'contactSnapshot','billingAddressId'=>'billingAddressSnapshot','deliveryAddressId'=>'deliveryAddressSnapshot'] as $field=>$target) {
            $snap[$target]=null;
            if(($data[$field]??null)===null) continue;
            $contact=$field==='contactId';
            $s=$this->pdo->prepare('SELECT * FROM '.($contact?'b2b_company_contacts WHERE contact_uuid':'b2b_company_addresses WHERE address_uuid').'=? AND company_uuid=? FOR UPDATE');
            $s->execute([$data[$field],$company['company_uuid']]);
            $r=$s->fetch(PDO::FETCH_ASSOC);
            if(!$r || $r['status']!=='active' || (!$contact && $r['address_type']!==($field==='billingAddressId'?'billing':'delivery'))) {
                if($clearInvalid) continue;
                throw new ApiException(422,'VALIDATION_FAILED','Selection is unavailable.',['fields'=>[$field=>'inactive']]);
            }
            $snap[$target]=$contact ? [
                'id'=>$r['contact_uuid'],'name'=>$r['full_name'],'jobTitle'=>$r['job_title'],'email'=>$r['email'],'phone'=>$r['phone'],
            ] : [
                'id'=>$r['address_uuid'],'type'=>$r['address_type'],'label'=>$r['label'],'countryCode'=>$r['country_code'],
                'countyRegion'=>$r['county_region'],'city'=>$r['city'],'postalCode'=>$r['postal_code'],
                'addressLine1'=>$r['address_line_1'],'addressLine2'=>$r['address_line_2'],
            ];
        }
        return $snap;
    }

    public function insert(EmployeeIdentity $actor,array $data,array $snap,?string $source,string $now): string
    {
        $this->pdo->prepare('INSERT INTO b2b_order_number_sequence(created_at) VALUES(?)')->execute([$now]);
        $number=(int)$this->pdo->lastInsertId();
        $id=Uuid::v4();
        $this->pdo->prepare('INSERT INTO b2b_orders(order_uuid,order_number,order_code,company_uuid,currency_code,source_order_uuid,
            company_snapshot,calculation,created_at,updated_at,created_by_employee_uuid,updated_by_employee_uuid,created_by_name,updated_by_name)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $id,$number,sprintf('B2B-ORD-%06d',$number),$data['companyId'],$data['currencyCode'],$source,
            self::json($snap['companySnapshot']),self::json(OrderCalculator::calculate($data['currencyCode'],$data['lines'])),
            $now,$now,$actor->employeeUuid,$actor->employeeUuid,$actor->displayName,$actor->displayName,
        ]);
        $this->save($id,$data,$snap,$actor,$now,false);
        return $id;
    }

    public function save(string $id,array $data,array $snap,EmployeeIdentity $actor,string $now,bool $increment=true): void
    {
        $calculation=OrderCalculator::calculate($data['currencyCode'],$data['lines']);
        $totals=$calculation['totals'];
        $this->pdo->prepare('UPDATE b2b_orders SET currency_code=?,contact_uuid=?,billing_address_uuid=?,delivery_address_uuid=?,
            customer_reference=?,notes=?,production_notes=?,company_snapshot=?,contact_snapshot=?,billing_address_snapshot=?,
            delivery_address_snapshot=?,calculation=?,net_total=?,vat_total=?,gross_total=?,
            version=version+?,updated_at=?,updated_by_employee_uuid=?,updated_by_name=? WHERE order_uuid=?')->execute([
            $data['currencyCode'],$data['contactId'],$data['billingAddressId'],$data['deliveryAddressId'],
            $data['customerReference'],$data['notes'],$data['productionNotes'],
            ...array_map(self::json(...),array_values($snap)),self::json($calculation),
            $totals['net']??null,$totals['vat']??null,$totals['gross']??null,
            (int)$increment,$now,$actor->employeeUuid,$actor->displayName,$id,
        ]);
        // Only editable draft rows reach this method. The aggregate lock protects the replacement and stable UUIDs.
        $this->pdo->prepare('DELETE FROM b2b_order_lines WHERE order_uuid=?')->execute([$id]);
        $columns=['order_uuid','line_number',...array_values(self::LINE_COLUMNS),'net_total','vat_total','gross_total'];
        $s=$this->pdo->prepare('INSERT INTO b2b_order_lines('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')');
        foreach($data['lines'] as $n=>$line) {
            $t=$calculation['lines'][$n]['totals'];
            $s->execute([$id,$n+1,...array_map(static fn(string $f)=>$line[$f],array_keys(self::LINE_COLUMNS)),
                $t['net']??null,$t['vat']??null,$t['gross']??null]);
        }
    }

    public function event(string $id,EmployeeIdentity $actor,string $action,array $fields,string $request,string $key,string $now,?string $lineId=null): void
    {
        $this->pdo->prepare('INSERT INTO b2b_order_activity_events(event_id,order_uuid,line_uuid,actor_employee_uuid,actor_name,
            action,changed_fields,request_id,idempotency_key,occurred_at) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([
            Uuid::v4(),$id,$lineId,$actor->employeeUuid,$actor->displayName,$action,self::json(array_values(array_unique($fields))),
            mb_substr($request,0,100),$key,$now,
        ]);
    }

    public static function json(mixed $value): ?string
    {
        return $value===null ? null : json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    public static function decode(?string $value): mixed
    {
        return $value===null ? null : json_decode($value,true,32,JSON_THROW_ON_ERROR);
    }
}
