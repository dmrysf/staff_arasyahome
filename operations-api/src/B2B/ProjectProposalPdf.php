<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\B2B\Pdf\PdfDocument;
use Arasya\Operations\B2B\Pdf\TrueTypeFont;

/**
 * Customer project proposal (A4). Lays out the ProjectQueries::proposal dataset: identity, summary, then
 * Zone -> Room -> Opening -> Treatment with prices from the canonical Classic calculator. Rooms with an identical
 * configuration inside one zone are printed once with their count, so a 120-room hotel stays readable. A room block
 * is never split across pages unless it alone is taller than a page (then it breaks between openings).
 * It is a planning proposal: it never claims to be an invoice or a finalized order.
 */
final class ProjectProposalPdf
{
    private const INK=[0.063,0.071,0.086];
    private const GOLD=[0.839,0.643,0.341];
    private const ACCENT=[0.725,0.522,0.227];
    private const SOFT=[0.969,0.937,0.886];
    private const MUTED=[0.42,0.45,0.5];
    private const LEFT=42.0;
    private const BOTTOM=58.0;

    private const LABELS=[
        'ro'=>['title'=>'Ofertă de proiect','client'=>'Client','project'=>'Proiect','prepared'=>'Pregătit de','code'=>'Cod','tax'=>'Cod fiscal','vat'=>'Cod TVA',
            'type'=>'Tip','site'=>'Adresă','reference'=>'Referință client','revision'=>'Revizia','date'=>'Data','summary'=>'Rezumat',
            'zones'=>'Etaje / zone','rooms'=>'Camere','openings'=>'Goluri','treatments'=>'Produse','net'=>'Total net','vat_total'=>'TVA','gross'=>'Total',
            'incomplete'=>'Unele produse nu au încă preț complet; totalurile includ doar pozițiile calculate.','notes'=>'Note',
            'col_type'=>'Tip','col_product'=>'Produs','col_size'=>'Dimensiuni','col_qty'=>'Cant. / m','col_price'=>'Preț unitar','col_discount'=>'Red.','col_total'=>'Total',
            'identical'=>'%d camere cu aceeași configurație','per_room'=>'Subtotal pe cameră','times'=>'× %d camere','room_total'=>'Subtotal cameră',
            'zone_total'=>'Subtotal','room'=>'Cameră','sill'=>'parapet','rail'=>'șină','empty_room'=>'Nicio fereastră definită încă.',
            'final'=>'Total ofertă','page'=>'Pagina','currency'=>'Monedă','qty'=>'buc.','meters'=>'m',
            'disclaimer'=>'Ofertă informativă calculată din proiect la revizia indicată. Prețurile și cantitățile devin definitive numai prin comanda comercială finalizată.',
            'no_price'=>'fără preț','dim_width'=>'lățime','dim_length'=>'lungime','dim_ceiling'=>'tavan'],
        'tr'=>['title'=>'Proje teklifi','client'=>'Müşteri','project'=>'Proje','prepared'=>'Hazırlayan','code'=>'Kod','tax'=>'Vergi numarası','vat'=>'KDV numarası',
            'type'=>'Tür','site'=>'Adres','reference'=>'Müşteri referansı','revision'=>'Revizyon','date'=>'Tarih','summary'=>'Özet',
            'zones'=>'Katlar / bölgeler','rooms'=>'Odalar','openings'=>'Açıklıklar','treatments'=>'Ürünler','net'=>'Net toplam','vat_total'=>'KDV','gross'=>'Toplam',
            'incomplete'=>'Bazı ürünlerin fiyatı henüz eksik; toplamlar yalnızca hesaplanan kalemleri içerir.','notes'=>'Notlar',
            'col_type'=>'Tür','col_product'=>'Ürün','col_size'=>'Ölçüler','col_qty'=>'Adet / m','col_price'=>'Birim fiyat','col_discount'=>'İnd.','col_total'=>'Toplam',
            'identical'=>'Aynı yapılandırmaya sahip %d oda','per_room'=>'Oda başına ara toplam','times'=>'× %d oda','room_total'=>'Oda ara toplamı',
            'zone_total'=>'Ara toplam','room'=>'Oda','sill'=>'denizlik','rail'=>'ray','empty_room'=>'Henüz pencere tanımlanmadı.',
            'final'=>'Teklif toplamı','page'=>'Sayfa','currency'=>'Para birimi','qty'=>'adet','meters'=>'m',
            'disclaimer'=>'Belirtilen revizyondaki projeden hesaplanan bilgilendirme amaçlı tekliftir. Fiyat ve miktarlar yalnızca kesinleşen ticari siparişle kesinleşir.',
            'no_price'=>'fiyatsız','dim_width'=>'genişlik','dim_length'=>'uzunluk','dim_ceiling'=>'tavan'],
    ];

    public static function language(mixed $value): string { return ProjectLabels::language($value); }

    public static function filename(array $data): string
    {
        return sprintf('oferta-%s-rev%d.pdf',$data['project']['code'],$data['project']['revision']);
    }

    public static function render(array $data,string $lang): string
    {
        $pages=self::layout($data,$lang,0)[1];
        return self::layout($data,$lang,$pages)[0]->output();
    }

    /** @return array{0:PdfDocument,1:int} */
    private static function layout(array $data,string $lang,int $total): array
    {
        $l=self::LABELS[$lang]; $t=ProjectLabels::TYPES[$lang];
        $fonts=__DIR__.'/Pdf/fonts/';
        $pdf=new PdfDocument(['R'=>new TrueTypeFont($fonts.'DejaVuSansCondensed.ttf','DejaVuSansCondensed'),
            'B'=>new TrueTypeFont($fonts.'DejaVuSansCondensed-Bold.ttf','DejaVuSansCondensed-Bold')],$l['title'].' '.$data['project']['code']);
        $p=$data['project']; $c=$data['commercial']; $currency=$c['currencyCode'];
        $right=PdfDocument::WIDTH-self::LEFT; $width=$right-self::LEFT;
        $date=substr($data['generatedAt'],0,10);
        $page=0; $y=0.0;
        $footer=function() use($pdf,$l,$p,$date,$right,&$page,$total): void {
            $pdf->line(self::LEFT,40,$right,40,0.4,0.82);
            $pdf->text(self::LEFT,28,'Arasya Home · '.$p['code'].' · '.$l['revision'].' '.$p['revision'].' · '.$date,'R',7.5,'left',self::MUTED);
            $pdf->text($right,28,$l['page'].' '.$page.($total>0?' / '.$total:''),'R',7.5,'right',self::MUTED);
        };
        $newPage=function(bool $first=false) use($pdf,$l,$p,$right,$footer,&$page,&$y): void {
            $pdf->addPage(); $page++;
            if($first) return;
            $pdf->fillRectRgb(0,PdfDocument::HEIGHT-46,PdfDocument::WIDTH,46,self::INK);
            $pdf->text(self::LEFT,PdfDocument::HEIGHT-28,'ARASYA HOME','B',9,'left',self::GOLD);
            $pdf->text(self::LEFT+80,PdfDocument::HEIGHT-28,$pdf->fit($p['name'],300,'B',9),'B',9,'left',[1,1,1]);
            $pdf->text($right,PdfDocument::HEIGHT-28,$p['code'],'R',9,'right',[0.85,0.85,0.85]);
            $footer();
            $y=PdfDocument::HEIGHT-72;
        };

        // Cover band and identity.
        $newPage(true);
        $pdf->fillRectRgb(0,PdfDocument::HEIGHT-150,PdfDocument::WIDTH,150,self::INK);
        $pdf->fillRectRgb(self::LEFT,PdfDocument::HEIGHT-74,26,26,self::GOLD);
        $pdf->text(self::LEFT+8.2,PdfDocument::HEIGHT-66.5,'A','B',14,'left',self::INK);
        $pdf->text(self::LEFT+36,PdfDocument::HEIGHT-58,'ARASYA HOME','B',10.5,'left',self::GOLD);
        $pdf->text(self::LEFT+36,PdfDocument::HEIGHT-71,$l['title'],'R',8.5,'left',[0.8,0.8,0.8]);
        $name=$pdf->wrap($p['name'],$width-150,'B',20,2);
        foreach($name as $i=>$line) $pdf->text(self::LEFT,PdfDocument::HEIGHT-104-$i*23,$line,'B',20,'left',[1,1,1]);
        $pdf->text($right,PdfDocument::HEIGHT-58,$p['code'],'B',11,'right',[1,1,1]);
        $pdf->text($right,PdfDocument::HEIGHT-73,$l['revision'].' '.$p['revision'].' · '.$date,'R',8.5,'right',[0.8,0.8,0.8]);
        $pdf->text($right,PdfDocument::HEIGHT-88,$t['property'][$p['propertyType']],'R',8.5,'right',self::GOLD);
        $footer();
        $y=PdfDocument::HEIGHT-182;
        $col=$width/3;
        $blocks=[
            [$l['client'],[[$data['company']['legalName'],'B'],[$l['code'].': '.$data['company']['code'],'R'],
                [$l['tax'].': '.$data['company']['countryCode'].' '.$data['company']['taxIdentifier'],'R'],
                ...($data['company']['vatNumber']?[[$l['vat'].': '.$data['company']['vatNumber'],'R']]:[])]],
            [$l['project'],[[$t['property'][$p['propertyType']],'B'],...($p['siteAddress']?[[$p['siteAddress'],'R']]:[]),
                ...($p['customerReference']?[[$l['reference'].': '.$p['customerReference'],'R']]:[]),[$l['currency'].': '.$currency,'R']]],
            [$l['prepared'],[[$p['createdBy']['displayName'],'B'],['Arasya Home','R'],[$l['date'].': '.$date,'R']]],
        ];
        $lowest=$y;
        foreach($blocks as $i=>[$title,$rows]) {
            $x=self::LEFT+$i*$col; $yy=$y;
            $pdf->text($x,$yy,mb_strtoupper($title),'B',7.5,'left',self::ACCENT); $yy-=15;
            foreach($rows as [$text,$font]) foreach($pdf->wrap($text,$col-14,$font,9.5,3) as $line) { $pdf->text($x,$yy,$line,$font,9.5); $yy-=13; }
            $lowest=min($lowest,$yy);
        }
        $y=$lowest-10;

        // Summary: counts and the canonical projection totals.
        $pdf->fillRectRgb(self::LEFT,$y-78,$width,78,self::SOFT);
        $pdf->text(self::LEFT+14,$y-20,mb_strtoupper($l['summary']),'B',7.5,'left',self::ACCENT);
        $counts=self::counts($data);
        foreach([['zones',$counts[0]],['rooms',$counts[1]],['openings',$counts[2]],['treatments',$counts[3]]] as $i=>[$key,$value]) {
            $x=self::LEFT+14+$i*68;
            $pdf->text($x,$y-46,(string)$value,'B',17,'left',self::INK);
            $pdf->text($x,$y-61,$l[$key],'R',7.5,'left',self::MUTED);
        }
        $totals=$c['totals'];
        $pdf->text($right-14,$y-22,$l['net'].'  '.ProjectLabels::money($totals['net']??null).' '.$currency,'R',8.5,'right',self::MUTED);
        $pdf->text($right-14,$y-36,$l['vat_total'].'  '.ProjectLabels::money($totals['vat']??null).' '.$currency,'R',8.5,'right',self::MUTED);
        $pdf->text($right-14,$y-60,ProjectLabels::money($totals['gross']??null).' '.$currency,'B',18,'right',self::INK);
        $y-=92;
        if(!$c['complete'] && $c['treatmentCount']>0) { foreach($pdf->wrap($l['incomplete'],$width,'R',8) as $line) { $pdf->text(self::LEFT,$y,$line,'R',8,'left',self::ACCENT); $y-=11; } $y-=4; }
        if($p['notes']) {
            $pdf->text(self::LEFT,$y,mb_strtoupper($l['notes']),'B',7.5,'left',self::ACCENT); $y-=13;
            foreach($pdf->wrap($p['notes'],$width,'R',9,6) as $line) { $pdf->text(self::LEFT,$y,$line,'R',9); $y-=12; }
            $y-=6;
        }

        $cols=['type'=>self::LEFT+8,'product'=>self::LEFT+92,'size'=>self::LEFT+236,'qty'=>$right-168,'price'=>$right-108,'discount'=>$right-68,'total'=>$right-6];
        $tableHeader=function() use($pdf,$l,$cols,$right,&$y): void {
            $pdf->text($cols['type'],$y,$l['col_type'],'B',7,'left',self::MUTED);
            $pdf->text($cols['product'],$y,$l['col_product'],'B',7,'left',self::MUTED);
            $pdf->text($cols['size'],$y,$l['col_size'],'B',7,'left',self::MUTED);
            foreach(['qty','price','discount','total'] as $k) $pdf->text($cols[$k],$y,$l['col_'.$k],'B',7,'right',self::MUTED);
            $y-=5; $pdf->line(self::LEFT,$y,$right,$y,0.4,0.8); $y-=11;
        };
        $ensure=function(float $height) use($newPage,&$y): bool {
            if($y-$height>=self::BOTTOM) return false;
            $newPage(); return true;
        };

        foreach($data['zones'] as $zone) {
            $zoneSummary=null;
            foreach($c['zones'] as $z) if($z['id']===$zone['id']) $zoneSummary=$z;
            $ensure(64);
            $y-=6;
            $pdf->fillRectRgb(self::LEFT,$y-8,$width,24,self::INK);
            $zoneTitle=self::zoneTitle($zone,$t);
            $pdf->text(self::LEFT+10,$y,$pdf->fit($zoneTitle,$width-170,'B',11),'B',11,'left',[1,1,1]);
            if($zoneSummary && $zoneSummary['totals']) $pdf->text($right-10,$y,$l['zone_total'].' '.ProjectLabels::money($zoneSummary['totals']['gross']).' '.$currency,'B',9,'right',self::GOLD);
            $y-=30;
            foreach(self::groups($zone['rooms']) as $group) {
                $room=$group[0];
                $height=self::roomHeight($room);
                if($height<=PdfDocument::HEIGHT-72-self::BOTTOM) $ensure($height);
                else $ensure(90);
                $title=self::roomRange(array_column($group,'name'));
                $pdf->text(self::LEFT,$y,$pdf->fit($title,$width-140,'B',11.5),'B',11.5,'left',self::INK);
                $dims=self::roomDimensions($room,$l);
                if($dims!=='') $pdf->text($right,$y,$dims,'R',8,'right',self::MUTED);
                $y-=14;
                if(count($group)>1) { $pdf->text(self::LEFT,$y,sprintf($l['identical'],count($group)),'R',8,'left',self::ACCENT); $y-=12; }
                $y-=8;
                if($room['openings']===[]) { $pdf->text(self::LEFT,$y,$l['empty_room'],'R',8.5,'left',self::MUTED); $y-=16; continue; }
                $roomSum=0; $roomComplete=true;
                foreach($room['openings'] as $opening) {
                    $ensure(30+14*count($opening['treatments']));
                    $pdf->fillRectRgb(self::LEFT,$y-5,$width,17,self::SOFT);
                    $meta=[$t['opening'][$opening['openingType']],ProjectLabels::dimensions($opening['width'],$opening['height'])];
                    if($opening['sillHeight']!==null) $meta[]=$l['sill'].' '.ProjectLabels::measure($opening['sillHeight']).' cm';
                    if($opening['mounting']!==null) $meta[]=$t['mounting'][$opening['mounting']];
                    if($opening['railType']!==null) $meta[]=$l['rail'].' '.$opening['railType'];
                    $pdf->text(self::LEFT+8,$y,$pdf->fit($opening['name'],120,'B',9),'B',9);
                    $pdf->text(self::LEFT+132,$y,$pdf->fit(implode(' · ',$meta),$width-140,'R',8.5),'R',8.5,'left',[0.24,0.26,0.3]);
                    $y-=20;
                    if($opening['treatments']!==[]) $tableHeader();
                    foreach($opening['treatments'] as $tr) {
                        $type=$t['treatment'][$tr['treatmentType']];
                        $product=trim($tr['productCode'].($tr['productName']?' — '.$tr['productName']:''));
                        $detail=implode(' · ',array_filter([$tr['color'],$tr['variant']]));
                        $pdf->text($cols['type'],$y,$pdf->fit($type,82,'R',8),'R',8);
                        $pdf->text($cols['product'],$y,$pdf->fit($product===''?'—':$product,138,'B',8.5),'B',8.5);
                        $pdf->text($cols['size'],$y,ProjectLabels::dimensions($tr['width'],$tr['height']),'R',8);
                        $qty=$tr['pricingUnit']==='meter'?ProjectLabels::measure($tr['meters']).' '.$l['meters']:$tr['quantity'].' '.$l['qty'];
                        $pdf->text($cols['qty'],$y,$qty,'R',8,'right');
                        $pdf->text($cols['price'],$y,$tr['unitPriceNet']===null?'—':ProjectLabels::money($tr['unitPriceNet']),'R',8,'right');
                        $pdf->text($cols['discount'],$y,$tr['discountPercent']==='0.00'?'—':ProjectLabels::measure($tr['discountPercent']).'%','R',8,'right');
                        $pdf->text($cols['total'],$y,$tr['totals']===null?$l['no_price']:ProjectLabels::money($tr['totals']['gross']),'B',8.5,'right',$tr['totals']===null?self::MUTED:self::INK);
                        if($tr['totals']===null) $roomComplete=false; else $roomSum+=OrderInput::fixed($tr['totals']['gross'],2);
                        if($detail!=='' || $tr['panelLayout']) {
                            $y-=10;
                            if($tr['panelLayout']) $pdf->text($cols['type'],$y,$t['layout'][$tr['panelLayout']],'R',7.5,'left',self::MUTED);
                            if($detail!=='') $pdf->text($cols['product'],$y,$pdf->fit($detail,138,'R',7.5),'R',7.5,'left',self::MUTED);
                        }
                        $y-=4; $pdf->line($cols['type'],$y,$right,$y,0.25,0.9); $y-=10;
                    }
                    $y-=4;
                }
                $sum=OrderInput::format($roomSum);
                if(count($group)>1) {
                    $pdf->text($right-6,$y,$l['per_room'].' '.ProjectLabels::money($sum).' '.$currency.'   '.sprintf($l['times'],count($group)).' = '.
                        ProjectLabels::money(OrderInput::format($roomSum*count($group))).' '.$currency,'B',9,'right',self::INK);
                } else $pdf->text($right-6,$y,$l['room_total'].' '.ProjectLabels::money($sum).' '.$currency.($roomComplete?'':' *'),'B',9,'right',self::INK);
                $y-=24;
            }
        }

        // Final totals and disclaimer, kept together.
        $ensure(110);
        $y-=6;
        $pdf->fillRectRgb(self::LEFT,$y-70,$width,70,self::INK);
        $pdf->text(self::LEFT+14,$y-24,mb_strtoupper($l['final']),'B',8,'left',self::GOLD);
        $pdf->text(self::LEFT+14,$y-42,$l['net'].'  '.ProjectLabels::money($totals['net']??null).' '.$currency.'    '.$l['vat_total'].'  '.ProjectLabels::money($totals['vat']??null).' '.$currency,'R',9,'left',[0.85,0.85,0.85]);
        $pdf->text($right-14,$y-44,ProjectLabels::money($totals['gross']??null).' '.$currency,'B',20,'right',[1,1,1]);
        $y-=86;
        foreach($pdf->wrap($l['disclaimer'],$width,'R',7.5) as $line) { $pdf->text(self::LEFT,$y,$line,'R',7.5,'left',self::MUTED); $y-=10; }
        return [$pdf,$page];
    }

    /** "Etaj 1 · Corp A"; the level is added only when the zone name does not already carry it. */
    public static function zoneTitle(array $zone,array $t): string
    {
        $name=$zone['name'];
        if($zone['level']!==null && !preg_match('/(^|\D)'.preg_quote((string)$zone['level'],'/').'(\D|$)/u',$name)) $name=$t['zone'][$zone['zoneType']].' '.$zone['level'].' · '.$name;
        return $name.($zone['building']?' · '.$zone['building']:'');
    }

    /**
     * Title of a group of identical rooms that never hides a gap: "Camera 101, Camera 104–120" lists consecutive numbers
     * of one prefix as ranges and every other name explicitly (shortened after 12 names with the exact count kept).
     */
    public static function roomRange(array $names): string
    {
        $runs=[]; $other=[];
        foreach($names as $name) {
            if(preg_match('/^(.*?)(\d+)$/u',$name,$m)!==1) { $other[]=$name; continue; }
            $last=count($runs)-1;
            if($last>=0 && $runs[$last]['prefix']===$m[1] && (int)$m[2]===$runs[$last]['to']+1 && strlen($m[2])===$runs[$last]['width']) { $runs[$last]['to']++; $runs[$last]['last']=$name; continue; }
            $runs[]=['prefix'=>$m[1],'from'=>(int)$m[2],'to'=>(int)$m[2],'first'=>$name,'last'=>$name,'width'=>strlen($m[2])];
        }
        $parts=array_map(static function(array $r): string {
            if($r['from']===$r['to']) return $r['first'];
            return $r['first'].'–'.substr($r['last'],strlen($r['prefix']));
        },$runs);
        $parts=[...$parts,...$other];
        return count($parts)>12 ? implode(', ',array_slice($parts,0,12)).' …' : implode(', ',$parts);
    }

    /** Only the measured room dimensions, each named, e.g. "lățime 400 cm" or "420 × 510 × 280 cm" when all three exist. */
    public static function roomDimensions(array $room,array $l): string
    {
        $values=['width'=>$room['widthCm']??null,'length'=>$room['lengthCm']??null,'ceiling'=>$room['ceilingHeightCm']??null];
        if(!in_array(null,$values,true)) return implode(' × ',array_map(ProjectLabels::measure(...),$values)).' cm';
        $present=array_filter($values,static fn($v): bool=>$v!==null);
        return implode(' · ',array_map(static fn(string $k,string $v): string=>$l['dim_'.$k].' '.ProjectLabels::measure($v).' cm',array_keys($present),$present));
    }

    /** Rooms with identical openings/treatments (names of the rooms excluded) form one printed group, in first-seen order. @return list<list<array>> */
    private static function groups(array $rooms): array
    {
        $groups=[];
        foreach($rooms as $room) {
            $signature=hash('sha256',json_encode([$room['widthCm'],$room['lengthCm'],$room['ceilingHeightCm'],array_map(static fn(array $o): array=>[
                array_diff_key($o,['id'=>1,'roomId'=>1,'position'=>1,'version'=>1,'copiedFromId'=>1,'treatments'=>1]),
                array_map(static fn(array $t): array=>array_diff_key($t,['id'=>1,'openingId'=>1,'position'=>1,'version'=>1,'copiedFromId'=>1,'ordered'=>1]),$o['treatments']),
            ],$room['openings'])],JSON_THROW_ON_ERROR));
            $groups[$signature][]=$room;
        }
        return array_values($groups);
    }

    private static function roomHeight(array $room): float
    {
        $h=40.0;
        foreach($room['openings'] as $o) {
            $h+=40;
            foreach($o['treatments'] as $t) $h+=14+(($t['color']??null)||($t['variant']??null)||($t['panelLayout']??null)?10:0);
        }
        return $h;
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private static function counts(array $data): array
    {
        $rooms=0; $openings=0; $treatments=0;
        foreach($data['zones'] as $z) foreach($z['rooms'] as $r) { $rooms++; foreach($r['openings'] as $o) { $openings++; $treatments+=count($o['treatments']); } }
        return [count($data['zones']),$rooms,$openings,$treatments];
    }
}
