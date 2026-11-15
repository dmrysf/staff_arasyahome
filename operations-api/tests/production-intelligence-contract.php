<?php

declare(strict_types=1);

// Production Intelligence V2 (API 2.70.0): the sold-meter contract and the meterBasis payload fact, without a database.

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceOrderPayloadMapper;
use Arasya\Operations\Intelligence\MeterContract as C;

require dirname(__DIR__) . '/bootstrap.php';

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $label);
    }
};
$line = static fn (array $over): array => C::line($over + ['source_key' => 'trendhome', 'quantity' => 1, 'width_value' => null, 'measurement_unit' => null,
    'meters' => null, 'context_kind' => null, 'basis_kind' => null, 'priced_length' => null]);

// Web shops: L x quantity from the stated pricing basis only.
$check($line(['basis_kind' => 'fabric_linear', 'priced_length' => '2.500', 'quantity' => 2, 'width_value' => '2.500', 'measurement_unit' => 'm'])
    === ['status' => C::MEASURED, 'fabricUnits' => 5000, 'railUnits' => null], 'fabric L x quantity in millimetres');
$check($line(['source_key' => 'outletperdele', 'basis_kind' => 'rail_linear', 'priced_length' => '3.000', 'quantity' => 1])
    === ['status' => C::MEASURED, 'fabricUnits' => null, 'railUnits' => 3000], 'rail meters kept apart from fabric (K5)');
$check($line(['basis_kind' => 'unit'])['status'] === C::NOT_METERED, 'unit line carries no meter');
$check($line(['basis_kind' => null, 'width_value' => '2.500', 'measurement_unit' => 'm', 'quantity' => 3])
    === ['status' => C::UNKNOWN_BASIS, 'fabricUnits' => null, 'railUnits' => null], 'width alone never becomes sold meters');
$check($line(['basis_kind' => 'fabric_linear', 'priced_length' => null, 'width_value' => '2.500', 'measurement_unit' => 'm'])['status'] === C::UNKNOWN, 'linear basis without a length is unknown');
$check($line(['basis_kind' => 'fabric_linear', 'priced_length' => '3.000', 'width_value' => '2.500', 'measurement_unit' => 'm'])
    === ['status' => C::CONFLICT, 'fabricUnits' => null, 'railUnits' => null], 'stated length disagreeing with the printed width is a conflict, not a value');
$check($line(['basis_kind' => 'fabric_linear', 'priced_length' => '3.000', 'width_value' => '300.000', 'measurement_unit' => 'cm'])['fabricUnits'] === 3000, 'a width in another unit is not compared');
$check($line(['basis_kind' => 'unknown'])['status'] === C::UNKNOWN, 'unknown basis stays unknown');
$check($line(['basis_kind' => 'fabric_linear', 'priced_length' => '1.000', 'quantity' => 0])['status'] === C::UNKNOWN, 'no quantity, no meters');
// Trendyol: never estimated (K2), whatever the size says.
$check($line(['source_key' => 'trendyol', 'width_value' => '150.000', 'measurement_unit' => 'cm', 'quantity' => 2, 'context_kind' => 'drapery'])
    === ['status' => C::UNKNOWN_MARKETPLACE, 'fabricUnits' => null, 'railUnits' => null], 'Trendyol fabric is unknown, no pleat ratio');
// B2B: the entered whole-line meters, never multiplied by quantity.
$check($line(['source_key' => 'b2b', 'meters' => '6.250', 'quantity' => 2, 'context_kind' => 'curtain'])['fabricUnits'] === 6250, 'B2B whole-line meters');
$check($line(['source_key' => 'b2b', 'meters' => null, 'context_kind' => 'other'])['status'] === C::NOT_METERED, 'B2B accessory carries no meter');
$check($line(['source_key' => 'b2b', 'meters' => '0.000', 'context_kind' => 'drapery'])['status'] === C::UNKNOWN, 'B2B zero meters is unknown, not zero');
// Exclusion (K7) wins over everything.
$check($line(['basis_kind' => 'fabric_linear', 'priced_length' => '2.000', 'excluded' => true])['status'] === C::EXCLUDED, 'excluded order has no meters');
$check($line(['source_key' => 'unknown-source', 'basis_kind' => 'fabric_linear', 'priced_length' => '2.000'])['status'] === C::UNKNOWN, 'unknown source has no meters');
$check(C::units('12.345') === 12345 && C::units('7') === 7000 && C::units('abc') === null && C::units('-1.000') === null && C::units(null) === null, 'decimal parsing');
$check(C::meters(5000) === '5.000' && C::meters(-6250) === '-6.250' && C::meters(1) === '0.001', 'meter formatting');

// Payload: order.items[].meterBasis is strict, optional and announced as an order fact.
$check(in_array('meterBasis', SourceOrderPayloadMapper::ORDER_FACTS, true), 'heartbeat announces meterBasis');
$payload = static fn (array $items): array => ['schemaVersion' => 1, 'eventId' => 'evt-1', 'changedAt' => '2026-11-12T10:00:00Z',
    'order' => ['id' => '9001', 'number' => '9001', 'availability' => 'active', 'items' => $items]];
$item = static fn (array $extra): array => ['id' => '11', 'line' => 1, 'name' => 'Draperie', 'quantity' => 1, 'width' => 2.5, 'height' => 2.6, 'unit' => 'm'] + $extra;
$snapshot = SourceOrderPayloadMapper::map('trendhome', $payload([$item(['meterBasis' => ['kind' => 'fabric_linear', 'length' => 2.5]])]));
$check($snapshot->meterBasis === ['11' => ['kind' => 'fabric_linear', 'length' => 2.5]], 'meterBasis mapped by source item id');
$check($snapshot->items[0]->meters === null, 'meterBasis never fills the printed item meters');
$check(SourceOrderPayloadMapper::map('trendhome', $payload([$item([])]))->meterBasis === null, 'absent meterBasis stays null');
$check(SourceOrderPayloadMapper::map('trendhome', $payload([$item(['meterBasis' => ['kind' => 'unit']])]))->meterBasis === ['11' => ['kind' => 'unit', 'length' => null]], 'unit basis without length');
foreach ([
    'unknown kind' => ['kind' => 'pleated'],
    'length on a unit line' => ['kind' => 'unit', 'length' => 1.0],
    'zero length' => ['kind' => 'fabric_linear', 'length' => 0],
    'negative length' => ['kind' => 'fabric_linear', 'length' => -1.5],
    'string length' => ['kind' => 'fabric_linear', 'length' => '2.5'],
    'extra key' => ['kind' => 'fabric_linear', 'length' => 2.5, 'meters' => 5],
    'missing kind' => ['length' => 2.5],
    'list instead of object' => ['fabric_linear', 2.5],
    'scalar' => 'fabric_linear',
] as $label => $bad) {
    try {
        SourceOrderPayloadMapper::map('trendhome', $payload([$item(['meterBasis' => $bad])]));
        $check(false, "invalid meterBasis rejected: {$label}");
    } catch (ApiException $error) {
        $check($error->status === 422 && $error->errorCode === 'SOURCE_PAYLOAD_INVALID', "invalid meterBasis rejected: {$label}");
    }
}

// Trendyol panel width (API 2.73.0): panel width (cm) / 100 x quantity, only for proven measurements; never fabric.
$panel = static fn (array $over): array => Arasya\Operations\Intelligence\TrendyolPanelWidth::line($over + ['width_value' => '140.000', 'height_value' => '260.000', 'measurement_unit' => 'cm',
    'quantity' => 2, 'variant' => '140x260', 'ty_linked' => true, 'ty_quantity' => 2, 'ty_size' => '140 x 260', 'ty_prepared_width' => '140.000', 'ty_prepared_height' => '260.000']);
$check($panel([]) === ['status' => 'verified', 'widthUnits' => 2800, 'panelCm' => [140000, 260000]], 'panel 1.40 m x 2 = 2.800 m when every source agrees');
$check($panel(['variant' => null, 'ty_size' => null, 'ty_prepared_width' => null, 'ty_prepared_height' => null])['widthUnits'] === 2800, 'sources that state no size do not block');
$check($panel(['width_value' => '140.5', 'variant' => '140,5×260', 'ty_size' => null, 'ty_prepared_width' => '140.500'])['widthUnits'] === 2810, 'decimal comma and the multiplication sign');
$check($panel(['ty_prepared_height' => '240.000'])['status'] === 'conflict', 'a prepared height that disagrees is a conflict');
$check($panel(['variant' => '150x260'])['status'] === 'conflict', 'a variant that disagrees is a conflict');
$check($panel(['ty_size' => '140x250'])['status'] === 'conflict', 'a Trendyol size that disagrees is a conflict');
$check($panel(['measurement_unit' => 'm'])['status'] === 'no_measure', 'only centimetre panels are measured');
$check($panel(['width_value' => null])['status'] === 'no_measure', 'a missing width is no measure');
$check($panel(['width_value' => '0.000', 'variant' => null, 'ty_size' => null, 'ty_prepared_width' => null, 'ty_prepared_height' => null])['status'] === 'invalid_measure', 'a zero width is invalid');
$check($panel(['ty_linked' => false])['status'] === 'unlinked', 'a line without its marketplace line is unknown');
$check($panel(['ty_quantity' => 3])['status'] === 'quantity_mismatch' && $panel(['ty_quantity' => null])['status'] === 'quantity_mismatch', 'the marketplace quantity must equal the line quantity');
$check($panel(['quantity' => 2, 'ty_quantity' => 2])['widthUnits'] === 2 * 1400, 'the quantity multiplies once');
$check(Arasya\Operations\Intelligence\TrendyolPanelWidth::dims('Bej') === null && Arasya\Operations\Intelligence\TrendyolPanelWidth::dims('140x260 cm') === null, 'free text is no size');

// Product category import format (API 2.73.0): strict, the whole file validated, ambiguous codes left out.
$json = static fn (array $products, string $source = 'trendhome'): string => json_encode(['format' => 'arasya-product-categories', 'formatVersion' => 1, 'source' => $source, 'generatedAt' => 'x', 'products' => $products]);
[$rows, $ambiguous] = Arasya\Operations\Intelligence\ProductCategoryImport::parse('trendhome', $json([
    ['code' => ' DV-1 ', 'category' => 'Draperii', 'rule' => 'single_main_category'], ['code' => 'dv-1', 'category' => 'Draperii', 'rule' => 'primary_category'],
    ['code' => 'X-2', 'category' => 'Perdele', 'rule' => 'single_main_category'], ['code' => 'x-2', 'category' => 'Draperii', 'rule' => 'single_main_category'], ['code' => 'X-2', 'category' => 'Perdele', 'rule' => 'single_main_category'],
]));
$check(array_keys($rows) === ['dv-1'] && $rows['dv-1']['code'] === 'DV-1' && $ambiguous === 1, 'codes trimmed, case-insensitive, conflicting categories left out');
foreach (['other source' => ['trendyol', $json([])], 'source mismatch' => ['trendhome', $json([], 'outletperdele')], 'control character' => ['trendhome', $json([['code' => "A\x01", 'category' => 'B', 'rule' => 'primary_category']])],
    'long category' => ['trendhome', $json([['code' => 'A', 'category' => str_repeat('c', 121), 'rule' => 'primary_category']])], 'not a list' => ['trendhome', json_encode(['format' => 'arasya-product-categories', 'formatVersion' => 1, 'source' => 'trendhome', 'products' => ['a' => []]])]] as $label => [$source, $body]) {
    try { Arasya\Operations\Intelligence\ProductCategoryImport::parse($source, $body); $check(false, "category import rejects {$label}"); } catch (InvalidArgumentException) { $check(true, "category import rejects {$label}"); }
}

echo "PASS {$checks} production intelligence contract assertions\n";
