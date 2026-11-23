<?php

declare(strict_types=1);

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Finance\Company\CompanyOrdersQueries;
use Arasya\Operations\Finance\FinanceAccess as A;
use Arasya\Operations\Http\ApiException;

require dirname(__DIR__) . '/bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP SQLite company order history: pdo_sqlite is unavailable.\n";
    exit;
}

$pdo = new PDO('sqlite::memory:');
$pdo->exec(<<<'SQL'
    CREATE TABLE b2b_companies (company_uuid TEXT PRIMARY KEY);
    CREATE TABLE b2b_orders (order_uuid TEXT PRIMARY KEY, company_uuid TEXT, order_code TEXT, status TEXT,
        currency_code TEXT, finalized_at TEXT, notes TEXT);
    CREATE TABLE b2b_order_lines (order_uuid TEXT, line_number INTEGER, product_code TEXT, product_name_snapshot TEXT,
        variant_snapshot TEXT, color_snapshot TEXT, quantity INTEGER, notes TEXT);
    CREATE TABLE b2b_production_handoffs (b2b_order_uuid TEXT, operational_order_uuid TEXT, submitted_at TEXT);
    CREATE TABLE operational_orders (order_uuid TEXT, production_stage_id TEXT, production_completed_at TEXT);
    CREATE TABLE production_stages (stage_id TEXT, display_name TEXT);
    SQL);

function uuid(int $number): string { return sprintf('01234567-1234-4234-8123-%012d', $number); }
function user(array $permissions, array $applications = ['finance']): EmployeeIdentity
{
    return new EmployeeIdentity('test', null, 'test', 'test', 'none', 'Test', 'd', 'Test', 'active', 'r', 'active', 'active',
        [A::ACCESS, ...$permissions], [], $applications);
}
function same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) throw new RuntimeException($label . ' ' . json_encode([$expected, $actual], JSON_THROW_ON_ERROR));
}
function error(int $status, string $code, callable $read): void
{
    try { $read(); } catch (ApiException $error) { same([$status, $code], [$error->status, $error->errorCode], 'error'); return; }
    throw new RuntimeException('Expected ' . $code);
}

$companies = [uuid(101), uuid(102), uuid(103)];
$addCompany = $pdo->prepare('INSERT INTO b2b_companies VALUES (?)');
foreach ($companies as $company) $addCompany->execute([$company]);
[$alpha, $beta, $empty] = $companies;
$insertOrder = $pdo->prepare('INSERT INTO b2b_orders VALUES (?, ?, ?, ?, ?, ?, ?)');
for ($n = 1; $n <= 8; $n++) {
    $date = sprintf('2026-11-%02d 08:30:00.120000', $n === 3 ? 2 : $n);
    $insertOrder->execute([uuid($n), $alpha, "ORDER-$n", in_array($n, [2, 8], true) ? 'cancelled' : 'finalized', 'RON', $date, 'private']);
}
$insertOrder->execute([uuid(9), $alpha, 'DRAFT', 'draft', 'RON', null, 'private']);
$insertOrder->execute([uuid(10), $beta, 'FOREIGN', 'finalized', 'EUR', '2026-11-10 09:00:00', 'private']);
$insertOrder->execute([uuid(11), $alpha, 'CANCELLED DRAFT', 'cancelled', 'RON', null, 'private']);
$insertLine = $pdo->prepare('INSERT INTO b2b_order_lines VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [1, 1, 'A', 'Older A', null, null, 4], [1, 2, 'A', 'Older A', null, null, 7], [1, 3, 'B', 'B', null, null, 1],
    [2, 1, 'CANCEL', 'Excluded', null, null, 12], [3, 1, 'A', 'Mid A', '120×260', 'Bej', 2], [3, 2, 'C', 'C', null, null, 1],
    [4, 1, 'B', null, null, null, 1], [4, 2, 'A', null, null, null, 2], [5, 1, 'B', 'Current B', null, null, 6],
    [6, 1, 'A', 'Current A', null, null, 2], [6, 2, 'A', 'Second line name', null, null, 10],
    [7, 1, 'E', null, null, null, 1], [7, 2, 'D', 'D', null, null, 2],
    [8, 1, 'CANCEL', 'Excluded', null, null, 50], [9, 1, 'DRAFT', 'Excluded', null, null, 900],
    [10, 1, 'A', 'Another company snapshot', null, null, 1], [10, 2, 'FOREIGN', 'Other company', null, null, 1],
    [11, 1, 'CANCELLED DRAFT', 'Excluded', null, null, 11],
] as [$n, $position, $code, $name, $variant, $color, $quantity]) {
    $insertLine->execute([uuid($n), $position, $code, $name, $variant, $color, $quantity, 'private']);
}
$insertHandoff = $pdo->prepare('INSERT INTO b2b_production_handoffs VALUES (?, ?, ?)');
$insertOperational = $pdo->prepare('INSERT INTO operational_orders VALUES (?, ?, ?)');
foreach ([1, 3, 5, 6, 8] as $n) {
    $complete = in_array($n, [1, 5, 8], true);
    $insertHandoff->execute([uuid($n), "op-$n", '2026-11-15 10:00:00']);
    $insertOperational->execute(["op-$n", $complete ? 'delivery' : 'material-preparation', $complete ? '2026-11-20 15:00:00' : null]);
}
$pdo->exec("INSERT INTO production_stages VALUES ('delivery', 'Livrare'), ('material-preparation', 'Pregătire materiale')");

$orders = new CompanyOrdersQueries($pdo, new AuthorizationService());
$finance = user([A::COMPANIES_VIEW, A::ACCOUNTS_VIEW]);
error(403, 'APPLICATION_ACCESS_DENIED', fn () => $orders->read(user([A::COMPANIES_VIEW, A::ACCOUNTS_VIEW], ['b2b']), $alpha));
error(403, 'UNAUTHORIZED_ACTION', fn () => $orders->read(user([A::COMPANIES_VIEW]), $alpha));
error(403, 'UNAUTHORIZED_ACTION', fn () => $orders->read(user([A::ACCOUNTS_VIEW]), $alpha));
error(404, 'COMPANY_NOT_FOUND', fn () => $orders->read($finance, uuid(999)));
foreach ([uuid(10), uuid(9), uuid(11), uuid(999), 'bad'] as $cursor) {
    error(400, 'INVALID_CURSOR', fn () => $orders->read($finance, $alpha, ['cursor' => $cursor]));
}
$first = $orders->read($finance, $alpha);
same(['total' => 8, 'completed' => 2, 'inProduction' => 2, 'notSubmitted' => 2, 'cancelled' => 2], $first['summary'], 'summary categories');
same([8, 7, 6, 5, 4, 3], array_column($first['orders'], 'ordinal'), 'first ordinals');
same(['ORDER-8', 'ORDER-7', 'ORDER-6', 'ORDER-5', 'ORDER-4', 'ORDER-3'], array_column($first['orders'], 'code'), 'descending date and stable tie');
same(['cancelled', 'not_submitted', 'in_production', 'completed', 'not_submitted', 'in_production'], array_column($first['orders'], 'status'), 'cancelled takes precedence');
same(uuid(3), $first['nextCursor'], 'cursor is last visible order');
same('2026-11-02T08:30:00.120Z', $first['orders'][5]['placedAt'], 'placed date');
same(['stageId' => 'material-preparation', 'stageLabel' => 'Pregătire materiale', 'completedAt' => null], $first['orders'][5]['production'], 'current production stage');
same(['stageId' => 'delivery', 'stageLabel' => 'Livrare', 'completedAt' => '2026-11-20T15:00:00.000Z'], $first['orders'][3]['production'], 'actual production completion');
same(null, $first['orders'][1]['production'], 'awaiting handoff');
same([['productCode' => 'A', 'productName' => 'Mid A', 'variant' => '120×260', 'color' => 'Bej', 'quantity' => 2],
    ['productCode' => 'C', 'productName' => 'C', 'variant' => null, 'color' => null, 'quantity' => 1]], $first['orders'][5]['items'], 'scoped frozen order line details');
same([['productCode' => 'A', 'productName' => 'Current A', 'orderCount' => 4],
    ['productCode' => 'B', 'productName' => 'Current B', 'orderCount' => 3],
    ['productCode' => 'C', 'productName' => 'C', 'orderCount' => 1],
    ['productCode' => 'D', 'productName' => 'D', 'orderCount' => 1],
    ['productCode' => 'E', 'productName' => null, 'orderCount' => 1]], $first['topProducts'], 'rank distinct orders, omit cancelled and other company, latest names');
$second = $orders->read($finance, $alpha, ['cursor' => $first['nextCursor']]);
same([2, 1], array_column($second['orders'], 'ordinal'), 'second page retains ordinals');
same(['ORDER-2', 'ORDER-1'], array_column($second['orders'], 'code'), 'second page keeps date ties');
same([$first['summary'], $first['topProducts'], null], [$second['summary'], $second['topProducts'], $second['nextCursor']], 'company totals remain on subsequent pages');
$other = $orders->read($finance, $beta);
same(['FOREIGN'], array_column($other['orders'], 'code'), 'second company has separate history');
same(['total' => 0, 'completed' => 0, 'inProduction' => 0, 'notSubmitted' => 0, 'cancelled' => 0],
    $orders->read($finance, $empty)['summary'], 'empty summary');
echo "PASS SQLite company order history, scopes, status, cursor, stable ordinals, items and distinct-product ranking.\n";
