<?php

declare(strict_types=1);

// Representative canonical production ticket snapshots (schema 1) for content, layout and QR tests.
// Test data only: invented TEST names and addresses; no real customer.

$line = static fn (int $number, array $overrides = []): array => $overrides + [
    'line' => $number, 'code' => null, 'name' => 'Produs', 'kind' => null, 'variant' => null, 'color' => null,
    'width' => null, 'height' => null, 'unit' => null, 'meters' => null, 'quantity' => 1,
    'notes' => null, 'productionNotes' => null, 'options' => [], 'project' => null,
];
$project = static fn (string $zone, string $room, string $opening, string $width, string $height, string $layout = 'pair', string $treatment = 'drapery'): array => [
    'project' => ['id' => 'p-1', 'code' => 'PRJ-000042', 'name' => 'TEST Hotel Lumina'],
    'zone' => ['id' => 'z-' . $zone, 'name' => 'Etaj ' . $zone, 'zoneType' => 'floor', 'level' => $zone, 'building' => 'Corp A'],
    'room' => ['id' => 'r-' . $room, 'name' => 'Camera ' . $room],
    'opening' => ['id' => 'o-' . $room . '-' . $opening, 'name' => 'Fereastra ' . $opening, 'openingType' => 'window', 'width' => $width, 'height' => $height, 'sillHeight' => null, 'mounting' => 'ceiling', 'railType' => 'R1'],
    'treatment' => ['id' => 't-' . $room . '-' . $opening, 'treatmentType' => $treatment, 'panelLayout' => $layout],
];

$large = [];
for ($i = 1; $i <= 48; $i++) {
    $room = (string) (100 + intdiv($i - 1, 4));
    $large[] = $line($i, [
        'code' => 'DV-' . (300 + $i % 7), 'name' => $i % 2 === 0 ? 'Draperie Velvet' : 'Perdea in', 'kind' => $i % 2 === 0 ? 'drapery' : 'curtain',
        'color' => $i % 3 === 0 ? 'Bej' : 'Alb', 'width' => '320.000', 'height' => '265.500', 'unit' => 'cm', 'meters' => '9.600', 'quantity' => 1,
        'productionNotes' => $i % 5 === 0 ? 'Tiv dublu jos, 10 cm.' : null,
        'project' => $project((string) intdiv($i - 1, 16), $room, (string) (1 + ($i - 1) % 2), '300.000', '250.000', $i % 2 === 0 ? 'pair' : 'single', $i % 2 === 0 ? 'drapery' : 'sheer'),
    ]);
}

return [
    'trendhome' => [
        'schema' => 1,
        'order' => ['number' => '84521', 'lookupCode' => '84521', 'source' => 'trendhome'],
        'customer' => ['name' => 'TEST Ioana Popescu', 'company' => null, 'contact' => null, 'addressLines' => ['Str. Exemplu nr. 12, bl. A3, ap. 7', '400000 Cluj-Napoca', 'Cluj', 'RO'], 'phoneMasked' => '07** *** ***'],
        'notes' => 'Clientul a cerut verificarea culorii înainte de tăiere.',
        'lines' => [
            $line(1, ['code' => 'DV-302', 'name' => 'Draperie Velvet', 'kind' => 'drapery', 'color' => 'Bej', 'variant' => 'Wave', 'width' => '300.000', 'height' => '260.000', 'unit' => 'cm', 'meters' => '8.400', 'quantity' => 1,
                'options' => [['label' => 'Confecționare', 'value' => '2 bucăți'], ['label' => 'Rejansă', 'value' => 'Rufflette 8 cm']], 'productionNotes' => 'Tiv 5 cm.']),
            $line(2, ['code' => 'PI-110', 'name' => 'Perdea in', 'kind' => 'curtain', 'color' => 'Alb', 'width' => '150.000', 'height' => '245.500', 'unit' => 'cm', 'meters' => '3.100', 'quantity' => 2]),
        ],
    ],
    'outletperdele' => [
        'schema' => 1,
        'order' => ['number' => '9001', 'lookupCode' => '9001', 'source' => 'outletperdele'],
        'customer' => ['name' => 'TEST Andrei Ionescu', 'company' => 'TEST Decor SRL', 'contact' => null, 'addressLines' => ['Bd. Test 5', '010101 București', 'RO'], 'phoneMasked' => '07** *** ***'],
        'notes' => null,
        'lines' => [
            $line(1, ['code' => 'OP-77', 'name' => 'Perdea voal', 'color' => 'Ivoire', 'width' => '400.000', 'height' => '280.000', 'unit' => 'cm', 'meters' => '12.000', 'quantity' => 1,
                'options' => [['label' => 'Segmentare', 'value' => '3 bucăți']]]),
        ],
    ],
    'trendyol' => [
        'schema' => 1,
        'order' => ['number' => '10930021', 'lookupCode' => '10930021', 'source' => 'trendyol'],
        'customer' => ['name' => 'TEST Maria Enache', 'company' => null, 'contact' => null, 'addressLines' => ['Str. Florilor 3', 'Iași'], 'phoneMasked' => null],
        'notes' => null,
        'lines' => [
            $line(1, ['code' => 'TY-555', 'name' => 'Draperie blackout gri', 'color' => 'Gri', 'variant' => '140x245', 'quantity' => 2]),
        ],
    ],
    'b2b' => [
        'schema' => 1,
        'order' => ['number' => 'B2B-ORD-000210', 'lookupCode' => 'B2B-ORD-000210', 'source' => 'b2b'],
        'customer' => ['name' => 'TEST Textile Partener SRL', 'company' => 'B2B-000017', 'contact' => 'TEST Elena Radu', 'addressLines' => ['Depozit central, Str. Industriei 9', '077190 Voluntari', 'Ilfov', 'RO'], 'phoneMasked' => '07** *** ***'],
        'notes' => 'Livrare paletizată.',
        'lines' => [
            $line(1, ['code' => 'CURTAIN', 'name' => 'Perdea en-gros', 'kind' => 'curtain', 'color' => 'Alb', 'variant' => 'Wave', 'width' => '200.000', 'height' => '260.000', 'unit' => 'cm', 'meters' => '13.500', 'quantity' => 4, 'notes' => 'Ambalare separată pe culori.']),
        ],
    ],
    'b2b-project' => [
        'schema' => 1,
        'order' => ['number' => 'B2B-ORD-000211', 'lookupCode' => 'B2B-ORD-000211', 'source' => 'b2b'],
        'customer' => ['name' => 'TEST Hotel Lumina SA', 'company' => 'B2B-000042', 'contact' => 'TEST Mihai Stan', 'addressLines' => ['Str. Hotelului 1', '500001 Brașov', 'Brașov', 'RO'], 'phoneMasked' => '07** *** ***'],
        'notes' => 'Montaj pe etaje, începând cu etajul 0.',
        'lines' => $large,
    ],
];
