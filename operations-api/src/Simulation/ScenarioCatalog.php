<?php

declare(strict_types=1);
namespace Arasya\Operations\Simulation;

final class ScenarioCatalog
{
    public const WOO = [
        'WOO-TH-001' => ['trendhome', 'Perdea — măsură personalizată', [['Perdea Voal','Alb',2,'1,80','2,50'],['Perdea Premium','Crem',1,'3.40','2.70']]],
        'WOO-TH-002' => ['trendhome', 'Draperie și accesoriu', [['Draperie Blackout','Gri',2,'2,40','2,60'],['Accesoriu prindere','Alb',4,null,null]]],
        'WOO-OP-001' => ['outletperdele', 'Perdea, draperie și accesoriu', [['Perdea','Alb',2,'2,20','2,50'],['Draperie','Crem',1,'2.80','2.60'],['Accesoriu','Gri',6,null,null]]],
        'WOO-OP-002' => ['outletperdele', 'Numai produse standard — regulă de rutare de confirmat', [['Accesoriu','Alb',3,null,null],['Accesoriu variabil','Crem',2,null,null],['Produs gata','Gri',1,null,null]]],
    ];
    public const TY = [
        'TY-01' => ['Un produs, o variantă', 'Created'],
        'TY-02' => ['Un produs, 4 bucăți', 'Picking'],
        'TY-03' => ['Același model, 3 dimensiuni', 'Invoiced'],
        'TY-04' => ['Modele și culori diferite', 'Created'],
        'TY-05' => ['Titlu de producție lipsă', 'Created'],
        'TY-06' => ['Dimensiune neclară', 'Created'],
        'TY-07' => ['Cod merchantSku placeholder', 'Created'],
        'TY-08' => ['Cantitate schimbată la pregătire', 'Created'],
        'TY-09' => ['Anulare înainte de aprobare', 'Created'],
        'TY-10' => ['Anulare după aprobare', 'Created'],
        'TY-11' => ['Răspuns API repetat', 'Created'],
        'TY-12' => ['API 429 / timeout', 'Created'],
        'TY-13' => ['ReadyToShip — numai revizuire', 'ReadyToShip'],
        'TY-14' => ['Status necunoscut — revizuire', 'UnknownSimulationStatus'],
        'TY-15' => ['Pachete separate — aceeași comandă', 'Created'],
        'TY-16' => ['Istoric înainte de baseline', 'Created'],
    ];

    public static function all(): array
    {
        $all = [];
        foreach (self::WOO as $id => [$source, $title]) $all[] = ['id'=>$id,'source'=>$source,'title'=>$title,'expected'=>'Signed ingestion → normalized lines. Human workflow verification required.'];
        foreach (self::TY as $id => [$title, $status]) $all[] = ['id'=>$id,'source'=>'trendyol','title'=>$title,'expected'=> $status . ' → canonical intake policy; preparation/release requires explicit human decisions.'];
        return $all;
    }

    public static function woo(string $scenario, string $session, int $replay, int $baselineMs): array
    {
        require_once dirname(__DIR__, 3) . '/simulation/woocommerce-fixtures.php';
        return arasya_ops_build_payload(self::wooOrder($scenario,$session,$replay), gmdate('Y-m-d\TH:i:s\Z', intdiv($baselineMs,1000)));
    }

    public static function wooOrder(string $scenario, string $session, int $replay): object
    {
        [$source, , $specs] = self::WOO[$scenario];
        $id = 9_000_000_000_000 + (int) sprintf('%u', crc32($session . $scenario)) * 10000 + $replay;
        $items = [];
        foreach ($specs as $index => [$name,$color,$quantity,$width,$height]) {
            $meta = $width === null ? [] : ['_wck_fields'=>['lungimea'=>$width,'inaltime'=>$height,'manopera'=>'18:Rejansă 10 cm','buc'=>'1:2 buc.'], '_wck_stock_reduction_multiplier'=>$width,'Lungimea (L)'=>$width,'Inaltime (H)'=>$height,'buc'=>'2 buc.','Manopera'=>'Rejansă 10 cm'];
            $items[$index+1] = new class($name,$color,$quantity,$meta,$index) {
                public function __construct(private string $name, private string $color, private int $quantity, private array $meta, private int $index) {}
                public function get_name(): string { return 'TEST ' . $this->name; }
                public function get_quantity(): int { return $this->quantity; }
                public function get_id(): int { return $this->index+1; }
                public function get_variation_id(): int { return $this->index+100; }
                public function get_meta(string $key, bool $single): mixed { return $this->meta[$key] ?? ''; }
                public function get_product(): object { return new class($this->color,$this->index) {
                    public function __construct(public string $variant, private int $index) {}
                    public function get_variation_attributes(bool $prefix=false): array { return ['pa_culoare'=>$this->variant]; }
                    public function get_sku(): string { return 'TEST-WOO-' . $this->index . '-' . $this->variant; }
                    public function is_type(string $type): bool { return $type === 'variation'; }
                }; }
            };
        }
        $order = new class($items,$id,$scenario,$replay) {
            public function __construct(private array $items, private int $id, private string $scenario, private int $replay) {}
            public function get_id(): int { return $this->id; }
            public function get_order_number(): string { return 'TEST-' . $this->scenario . '-R' . $this->replay; }
            public function get_status(): string { return 'processing'; }
            public function get_items(string $type='line_item'): array { return $this->items; }
            public function get_date_created(): ?object { return null; }
            public function get_meta(string $key, bool $single): string { return in_array($key,['_arasya_production_notes','_yd_production_instruction'],true) ? 'SIMULARE / TEST — date sintetice' : ''; }
        };
        return $order;
    }

    public static function trendyol(string $scenario, string $session, int $replay, int $baselineMs): array
    {
        [, $status] = self::TY[$scenario];
        $id = 8_000_000_000_000 + (int) sprintf('%u', crc32($session . $scenario)) * 10000 + $replay;
        $sizes = $scenario === 'TY-03' ? ['100x250','150 × 250','200 X 260'] : ($scenario === 'TY-04' ? ['100x250','200x260'] : ['100x250']);
        $lines = [];
        foreach ($sizes as $i=>$size) $lines[] = [
            'lineId'=>$id+$i+10,'quantity'=>$scenario === 'TY-02' ? 4 : 1,
            'productName'=>'TEST Curtain ' . $size,'productSize'=>$scenario === 'TY-06' ? '' : $size,
            'productColor'=>$i === 0 ? 'Alb' : 'Crem',
            'stockCode'=>$scenario === 'TY-07' ? 'merchantSku' : 'TEST-TY-' . $size,
            'merchantSku'=>'merchantSku','sku'=>'TEST-TY-' . $size,'barcode'=>'TEST-BARCODE-' . $i,
        ];
        if ($scenario === 'TY-06') $lines[0]['productName'] = 'TEST Curtain — size requires confirmation';
        return ['shipmentPackageId'=>$id,'orderNumber'=>'TEST-' . ($scenario === 'TY-15' ? 'SPLIT' : $scenario) . '-R' . $replay,
            'shipmentPackageStatus'=>$status,'orderDate'=>$scenario === 'TY-16' ? $baselineMs-1 : $baselineMs+600_000,
            'lastModifiedDate'=>$baselineMs+600_000,'lines'=>$lines];
    }
}
