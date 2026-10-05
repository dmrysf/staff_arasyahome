<?php
declare(strict_types=1);
use Arasya\Operations\B2B\ProductionInput;
use Arasya\Operations\Http\ApiException;
require dirname(__DIR__).'/bootstrap.php';
$checks=0;
function check(bool $ok,string $why): void { global $checks; $checks++; if(!$ok) throw new RuntimeException($why); }
$line=['id'=>'00000000-0000-4000-8000-000000000001','kind'=>'curtain','productCode'=>'C-1','productName'=>null,'variant'=>'Wave','color'=>'Alb',
    'width'=>'200.125','height'=>'260','quantity'=>4,'meters'=>'13.5','notes'=>'Note','productionNotes'=>'Workshop',
    'unitPriceNet'=>'10.00','vatPercent'=>'19','discountPercent'=>'0','pricingUnit'=>'meter'];
foreach(['curtain','drapery','other'] as $kind) {
    $items=ProductionInput::items([array_replace($line,['kind'=>$kind])]);
    check(count($items)===1,'no line omitted'); $item=$items[0];
    check($item->sourceItemId===$line['id'] && $item->name==='C-1','stable line/code fallback');
    check($item->meters===13.5 && $item->quantity===4 && $item->widthValue===200.125,'whole-line meters and measurements');
    check($item->productionContext===['kind'=>$kind,'notes'=>'Note','productionNotes'=>'Workshop'],'only manufacturing context');
}
foreach([['kind'=>'unsupported'],['productName'=>str_repeat('X',256)],['meters'=>'1000000000.000'],['width'=>'invalid']] as $change) {
    try { ProductionInput::items([array_replace($line,$change)]); throw new RuntimeException('unsafe line accepted'); }
    catch(ApiException $e) { check($e->errorCode==='PRODUCTION_LINE_UNSUPPORTED','stable error'); }
}
check(ProductionInput::company(['legalName'=>'Client','companyCode'=>'B2B-000001','countryCode'=>'RO','taxIdentifier'=>'123','internalNotes'=>'private'])===
    ['legalName'=>'Client','companyCode'=>'B2B-000001','countryCode'=>'RO','taxIdentifier'=>'123'],'minimal frozen identity');
echo "PASS B2B production input $checks checks\n";
