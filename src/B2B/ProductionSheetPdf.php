<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\B2B\Pdf\PdfDocument;
use Arasya\Operations\B2B\Pdf\QrCode;
use Arasya\Operations\B2B\Pdf\TrueTypeFont;

/**
 * Workshop production sheet (A4) rendered only from the immutable manufacturing snapshot: the canonical operational
 * order, its items and their frozen production context. It carries no money, payment, balance or ledger data.
 * The canonical order QR (the same opaque reference Staff already scans) and the manual lookup code sit on every page,
 * but every measurement is printed large enough to work from paper alone. Item blocks are never split across pages.
 */
final class ProductionSheetPdf
{
    private const LEFT=36.0;
    private const BOTTOM=56.0;
    private const MUTED=[0.38,0.4,0.45];
    private const GOLD=[0.725,0.522,0.227];

    private const LABELS=[
        'ro'=>['title'=>'Fișă de producție','company'=>'Companie','source'=>'Sursă','project'=>'Proiect','manual'=>'Cod manual','scan'=>'Scanați cu Arasya Staff',
            'generated'=>'Generat','stage'=>'Etapă la tipărire','items'=>'Produse','order_notes'=>'Instrucțiuni comandă','width'=>'Lățime','height'=>'Înălțime',
            'quantity'=>'Cantitate','meters'=>'Metri total','notes'=>'Note','production_notes'=>'Producție','opening'=>'gol','rail'=>'șină','page'=>'Pagina',
            'no_qr'=>'Cod QR indisponibil — folosiți codul manual.','no_money'=>'Document de atelier — nu conține date financiare.','other_items'=>'Produse fără locație de proiect',
            'cm'=>'cm','pcs'=>'buc.','m'=>'m','workflow'=>'Flux'],
        'tr'=>['title'=>'Üretim föyü','company'=>'Şirket','source'=>'Kaynak','project'=>'Proje','manual'=>'Manuel kod','scan'=>'Arasya Staff ile tarayın',
            'generated'=>'Oluşturulma','stage'=>'Yazdırma anındaki aşama','items'=>'Ürünler','order_notes'=>'Sipariş talimatları','width'=>'Genişlik','height'=>'Yükseklik',
            'quantity'=>'Adet','meters'=>'Toplam metre','notes'=>'Notlar','production_notes'=>'Üretim','opening'=>'açıklık','rail'=>'ray','page'=>'Sayfa',
            'no_qr'=>'QR kod kullanılamıyor — manuel kodu kullanın.','no_money'=>'Atölye belgesi — finansal bilgi içermez.','other_items'=>'Proje konumu olmayan ürünler',
            'cm'=>'cm','pcs'=>'adet','m'=>'m','workflow'=>'Akış'],
    ];

    public static function language(mixed $value): string { return ProjectLabels::language($value); }

    public static function filename(array $sheet): string { return 'productie-'.$sheet['orderCode'].'.pdf'; }

    public static function render(array $sheet,string $lang): string
    {
        $pages=self::layout($sheet,$lang,0)[1];
        return self::layout($sheet,$lang,$pages)[0]->output();
    }

    /** @return array{0:PdfDocument,1:int} */
    private static function layout(array $sheet,string $lang,int $total): array
    {
        $l=self::LABELS[$lang]; $t=ProjectLabels::TYPES[$lang];
        $fonts=__DIR__.'/Pdf/fonts/';
        $pdf=new PdfDocument(['R'=>new TrueTypeFont($fonts.'DejaVuSansCondensed.ttf','DejaVuSansCondensed'),
            'B'=>new TrueTypeFont($fonts.'DejaVuSansCondensed-Bold.ttf','DejaVuSansCondensed-Bold')],$l['title'].' '.$sheet['orderCode']);
        $right=PdfDocument::WIDTH-self::LEFT; $width=$right-self::LEFT;
        $qr=$sheet['qrPayload']===null?null:QrCode::matrix($sheet['qrPayload'],'Q');
        $page=0; $y=0.0;
        $newPage=function() use($pdf,$l,$sheet,$qr,$right,$width,$t,&$page,&$y,$total): void {
            $pdf->addPage(); $page++;
            $top=PdfDocument::HEIGHT-34;
            $qrSize=$page===1?118.0:78.0;
            if($qr!==null) $pdf->qr($right-$qrSize,$top-$qrSize+8,$qrSize,$qr);
            else $pdf->text($right,$top-20,$l['no_qr'],'B',8,'right');
            $pdf->text(self::LEFT,$top-6,'ARASYA HOME · '.mb_strtoupper($l['title']),'B',9,'left',self::GOLD);
            $pdf->text(self::LEFT,$top-36,$sheet['orderCode'],'B',$page===1?28:20);
            $textWidth=$width-$qrSize-16;
            if($page===1) {
                $yy=$top-56;
                $pdf->text(self::LEFT,$yy,$pdf->fit($sheet['company']['legalName'],$textWidth,'B',13),'B',13); $yy-=15;
                $pdf->text(self::LEFT,$yy,$pdf->fit($sheet['company']['companyCode'].' · '.$sheet['company']['countryCode'].' '.$sheet['company']['taxIdentifier'],$textWidth,'R',9),'R',9,'left',self::MUTED); $yy-=15;
                if($sheet['projects']!==[]) {
                    $pdf->text(self::LEFT,$yy,$pdf->fit($l['project'].': '.implode(', ',array_map(static fn(array $p): string=>$p['code'].' · '.$p['name'],$sheet['projects'])),$textWidth,'B',10),'B',10); $yy-=14;
                }
                $pdf->text(self::LEFT,$yy,$l['source'].': B2B · '.$sheet['operationalOrderId'],'R',8,'left',self::MUTED); $yy-=12;
                $pdf->text(self::LEFT,$yy,$l['stage'].': '.$sheet['stage']['label'].' ('.$sheet['stage']['ordinal'].'/'.$sheet['totalStages'].') · '.$l['workflow'].' '.$sheet['workflow'],'R',8,'left',self::MUTED);
            } else {
                $pdf->text(self::LEFT,$top-54,$pdf->fit($sheet['company']['legalName'],$textWidth,'R',10),'R',10);
            }
            $pdf->text($right,$top-$qrSize-4,$l['manual'].': '.$sheet['lookupCode'],'B',$page===1?10:8.5,'right');
            if($page===1) $pdf->text($right,$top-$qrSize-16,$l['scan'],'R',7.5,'right',self::MUTED);
            $rule=$top-$qrSize-($page===1?26:14);
            $pdf->line(self::LEFT,$rule,$right,$rule,1.6,0.0);
            $y=$rule-26;
            $pdf->line(self::LEFT,40,$right,40,0.4,0.8);
            $pdf->text(self::LEFT,28,'Arasya Home · '.$sheet['orderCode'].' · '.$l['generated'].' '.$sheet['generatedAt'].' · '.$l['no_money'],'R',7.5,'left',self::MUTED);
            $pdf->text($right,28,$l['page'].' '.$page.($total>0?' / '.$total:''),'R',7.5,'right',self::MUTED);
        };
        $newPage();
        if($sheet['productionNotes']) {
            $lines=$pdf->wrap($sheet['productionNotes'],$width-24,'B',11,8);
            $h=24+14*count($lines);
            $pdf->strokeRect(self::LEFT,$y-$h+12,$width,$h,1.2,0.0);
            $pdf->text(self::LEFT+12,$y-4,mb_strtoupper($l['order_notes']),'B',8,'left',self::MUTED);
            $yy=$y-20;
            foreach($lines as $line) { $pdf->text(self::LEFT+12,$yy,$line,'B',11); $yy-=14; }
            $y-=$h+10;
        }
        $pdf->text(self::LEFT,$y,mb_strtoupper($l['items']).' · '.count($sheet['items']),'B',9,'left',self::MUTED); $y-=18;

        $currentGroup=null; $currentOpening=null;
        foreach(self::sorted($sheet['items']) as $item) {
            $project=$item['context']['project']??null;
            $group=$project===null?'':$project['zone']['id'].'|'.$project['room']['id'];
            $openingKey=$project===null?'':$project['opening']['id'];
            $block=self::blockHeight($pdf,$item,$width);
            $headerHeight=($group!==$currentGroup?30:0)+($openingKey!==$currentOpening && $project!==null?22:0);
            if($y-$block-$headerHeight<self::BOTTOM) { $newPage(); $currentGroup=null; $currentOpening=null; $headerHeight=($project!==null?52:30); }
            if($group!==$currentGroup) {
                $title=$project===null?$l['other_items']:ProjectProposalPdf::zoneTitle($project['zone'],$t).'  ›  '.$project['room']['name'];
                $pdf->fillRect(self::LEFT,$y-7,$width,22,0.12);
                $pdf->text(self::LEFT+10,$y,$pdf->fit($title,$width-20,'B',13),'B',13,'left',[1,1,1]);
                $y-=30; $currentGroup=$group; $currentOpening=null;
            }
            if($project!==null && $openingKey!==$currentOpening) {
                $o=$project['opening'];
                $meta=[$t['opening'][$o['openingType']],$l['opening'].' '.ProjectLabels::dimensions($o['width'],$o['height'])];
                if($o['mounting']!==null) $meta[]=$t['mounting'][$o['mounting']];
                if($o['railType']!==null) $meta[]=$l['rail'].' '.$o['railType'];
                $pdf->text(self::LEFT,$y,$pdf->fit($o['name'],150,'B',11),'B',11);
                $pdf->text(self::LEFT+156,$y,$pdf->fit(implode(' · ',$meta),$width-156,'R',10),'R',10);
                $y-=22; $currentOpening=$openingKey;
            }
            self::block($pdf,$item,$l,$t,$y,$width);
            $y-=$block+10;
        }
        return [$pdf,$page];
    }

    private static function blockHeight(PdfDocument $pdf,array $item,float $width): float
    {
        $h=96.0;
        foreach(['notes','productionNotes'] as $k) if(($item['context'][$k]??null)!==null) $h+=14+13*count($pdf->wrap($item['context'][$k],$width-110,'R',10,6));
        return $h;
    }

    private static function block(PdfDocument $pdf,array $item,array $l,array $t,float $y,float $width): void
    {
        $h=self::blockHeight($pdf,$item,$width);
        $x=self::LEFT; $right=$x+$width;
        $pdf->strokeRect($x,$y-$h+12,$width,$h,1.1,0.0);
        $pdf->fillRect($x,$y-$h+12,58,$h,0.93);
        $pdf->text($x+29-$pdf->font('B')->textWidth('#'.$item['lineNumber'],18)/2,$y-20,'#'.$item['lineNumber'],'B',18);
        $ctx=$item['context'];
        $project=$ctx['project']??null;
        $kind=$project!==null?$t['treatment'][$project['treatment']['treatmentType']]:($ctx['kind']!==null?$t['kind'][$ctx['kind']]:'');
        if($project!==null && $project['treatment']['panelLayout']) $kind.=' · '.$t['layout'][$project['treatment']['panelLayout']];
        $cx=$x+70;
        $pdf->text($cx,$y-8,$pdf->fit($item['productCode']??'—',$width-200,'B',13),'B',13);
        $pdf->text($right-10,$y-8,$kind,'B',10,'right',self::GOLD);
        $name=trim(($item['name']??'').(($item['color']||$item['variant'])?'  ·  '.implode(' · ',array_filter([$item['color'],$item['variant']])):''));
        $pdf->text($cx,$y-23,$pdf->fit($name,$width-80,'R',10),'R',10);
        $boxY=$y-74; $boxW=($width-80)/4;
        $values=[[$l['width'],ProjectLabels::measure($item['width']),$l['cm']],[$l['height'],ProjectLabels::measure($item['height']),$l['cm']],
            [$l['quantity'],(string)$item['quantity'],$l['pcs']],[$l['meters'],$item['meters']===null?'—':ProjectLabels::measure($item['meters']),$item['meters']===null?'':$l['m']]];
        foreach($values as $i=>[$label,$value,$unit]) {
            $bx=$cx+$i*$boxW;
            $pdf->strokeRect($bx,$boxY,$boxW-8,40,0.6,0.55);
            $pdf->text($bx+6,$boxY+29,mb_strtoupper($label),'B',7,'left',self::MUTED);
            $pdf->text($bx+6,$boxY+9,$value,'B',17);
            if($unit!=='' && $value!=='—') $pdf->text($bx+8+$pdf->font('B')->textWidth($value,17),$boxY+9,$unit,'R',9,'left',self::MUTED);
        }
        $yy=$boxY-14;
        foreach(['notes'=>$l['notes'],'productionNotes'=>$l['production_notes']] as $k=>$label) {
            if(($ctx[$k]??null)===null) continue;
            $pdf->text($cx,$yy,mb_strtoupper($label),'B',7.5,'left',self::MUTED); $yy-=13;
            foreach($pdf->wrap($ctx[$k],$width-110,'R',10,6) as $line) { $pdf->text($cx,$yy,$line,$k==='productionNotes'?'B':'R',10); $yy-=13; }
            $yy-=1;
        }
    }

    /** Project items in room order (zone, room, opening, treatment position as converted), others after, by line number. */
    private static function sorted(array $items): array
    {
        usort($items,static fn(array $a,array $b): int=>[($a['context']['project']??null)===null?1:0,$a['lineNumber']]<=>[($b['context']['project']??null)===null?1:0,$b['lineNumber']]);
        return $items;
    }
}
