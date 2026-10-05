<?php
declare(strict_types=1);
use Arasya\Operations\B2B\OrderInput;
use Arasya\Operations\B2B\OrderCalculator;
use Arasya\Operations\Http\ApiException;
require dirname(__DIR__) . '/bootstrap.php';
$checks = 0;
function ok(bool $value, string $message): void { global $checks; $checks++; if (!$value) throw new RuntimeException($message); }
ok(class_exists(OrderInput::class) && class_exists(OrderCalculator::class), 'Order input/calculator domain is missing');
function line(array $extra = []): array { return $extra + ['productCode'=>'TEST', 'productName'=>null, 'variant'=>null, 'color'=>null, 'kind'=>'other', 'width'=>null, 'height'=>null, 'quantity'=>4, 'meters'=>null, 'pricingUnit'=>'piece', 'unitPriceNet'=>'100.00', 'discountPercent'=>'10', 'vatPercent'=>'19', 'notes'=>null]; }
function calc(array $lines, string $currency='RON'): array { $data=OrderInput::calculation(['currencyCode'=>$currency,'lines'=>$lines]); return OrderCalculator::calculate($data['currencyCode'],$data['lines']); }
foreach (['RON','EUR'] as $currency) {
 $c=calc([line()],$currency); ok($c['totals']===['net'=>'360.00','vat'=>'68.40','gross'=>'428.40'], 'piece exact totals');
 ok($c['lines'][0]['totals']['baseNet']==='400.00' && $c['lines'][0]['totals']['discountNet']==='40.00','line decomposition');
 ok(calc([line(['pricingUnit'=>'meter','meters'=>'13.5','unitPriceNet'=>'10','discountPercent'=>'0'])],$currency)['lines'][0]['totals']['baseNet']==='135.00','meters total, never multiplied by pieces');
}
ok(calc([line(['pricingUnit'=>'meter','meters'=>'0.005','unitPriceNet'=>'1','discountPercent'=>'0'])])['lines'][0]['totals']['baseNet']==='0.01','half-up meter');
ok(calc([line(['quantity'=>1,'unitPriceNet'=>'0.05'])])['lines'][0]['totals']['net']==='0.04','half-up discount');
ok(calc([line(['discountPercent'=>'100'])])['totals']['gross']==='0.00','full discount');
ok(calc([])['totals']===null && !calc([])['complete'],'empty draft');
foreach (['unitPriceNet','vatPercent'] as $field) ok(calc([line([$field=>null])])['totals']===null,'incomplete draft');
ok(calc([line(['pricingUnit'=>'meter','meters'=>'0'])])['complete']===false,'zero meter incomplete');
ok(calc([line(['unitPriceNet'=>'999999.99','quantity'=>99999,'vatPercent'=>'100','discountPercent'=>'0'])])['totals']['gross']==='199997998000.02','bounded max arithmetic');
ok(calc([line(['unitPriceNet'=>'0'])])['complete'],'zero price complete');
$invalid=[['unitPriceNet'=>1],['unitPriceNet'=>'1.001'],['unitPriceNet'=>'1e3'],['unitPriceNet'=>'-1'],['unitPriceNet'=>'999999.999'],['quantity'=>1.0],['quantity'=>100000],['width'=>'0'],['width'=>'100000'],['meters'=>'0.0001'],['vatPercent'=>'100.01'],['discountPercent'=>'-1'],['inventoryId'=>'secret'],['notes'=>['secret'=>'x']]];
foreach ($invalid as $extra) { try { calc([line($extra)]); ok(false,'invalid accepted '.json_encode(array_keys($extra))); } catch (ApiException $e) { ok($e->errorCode==='VALIDATION_FAILED','stable validation'); } }
try { OrderInput::calculation(['currencyCode'=>'USD','lines'=>[]]); ok(false,'USD accepted'); } catch (ApiException $e) { ok(true,'currency rejected'); }
try { calc(array_fill(0,101,line())); ok(false,'101 lines accepted'); } catch (ApiException $e) { ok(true,'line bound'); }
try { OrderInput::fields(['companyId'=>'00000000-0000-4000-8000-000000000000','status'=>'finalized']); ok(false,'unknown order key'); } catch (ApiException $e) { ok(true,'server fields rejected'); }
fwrite(STDOUT,"PASS order calculator/input {$checks} checks\n");
ok(calc([line(['quantity'=>1,'unitPriceNet'=>'0.10','discountPercent'=>'0','vatPercent'=>'5'])])['totals']['vat']==='0.01','VAT midpoint HALF-UP');
ok(calc([line(['quantity'=>1,'unitPriceNet'=>'0.09','discountPercent'=>'0','vatPercent'=>'5'])])['totals']['vat']==='0.00','VAT below midpoint');
ok(calc([line(['quantity'=>1,'unitPriceNet'=>'0.11','discountPercent'=>'0','vatPercent'=>'5'])])['totals']['vat']==='0.01','VAT above midpoint');
ok(calc([line(['pricingUnit'=>'meter','meters'=>'1.005','unitPriceNet'=>'0.01','discountPercent'=>'0.05','vatPercent'=>'19.50'])])['totals']===['net'=>'0.01','vat'=>'0.00','gross'=>'0.01'],'fractional rates and meters');
$one=line(['quantity'=>1,'unitPriceNet'=>'0.05','discountPercent'=>'10','vatPercent'=>'25']);
ok(calc(array_fill(0,100,$one))['totals']===['net'=>'4.00','vat'=>'1.00','gross'=>'5.00'],'sum rounded line results, not aggregate recomputation');
foreach([['quantity'=>0],['quantity'=>-1],['meters'=>'-0.001'],['unitPriceNet'=>'1000000.00'],['meters'=>'100000.000'],['discountPercent'=>'100.001']] as $invalid) {
    try { calc([line($invalid)]); ok(false,'invalid boundary accepted'); } catch(ApiException $e) { ok($e->errorCode==='VALIDATION_FAILED','boundary rejected'); }
}
ok(calc(array_fill(0,100,line(['unitPriceNet'=>'999999.99','quantity'=>99999,'vatPercent'=>'100','discountPercent'=>'0'])))['totals']['gross']==='19999799800002.00','100 maximum lines without overflow');
fwrite(STDOUT,"PASS all exact money boundaries {$checks} checks\n");
