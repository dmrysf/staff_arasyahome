<?php
declare(strict_types=1);

/**
 * Finance Smart Search V1 (API 2.85.0) performance check: the finance inbox (names and amounts with each task) and
 * GET /confirmare/finance/search through the real HTTP kernel, on a disposable database filled with SYNTHETIC orders,
 * customers and amounts only (no production data). Not part of `pnpm verify:api`; run by hand:
 *
 *   ARASYA_TEST_DB_NAME=arasya_finance_search_performance_test ARASYA_FSS_ORDERS=50000 ARASYA_FSS_TASKS=1000 \
 *     php operations-api/tests/finance-search-performance.php
 *
 * Prints the median of seven timed calls (after one warm-up) of each query and the size of each answer.
 */

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$db = T::requireTestDatabase();
if ($db === null) {
    echo "SKIP finance search performance: test database not configured\n";
    exit(0);
}
// Destructive fixture setup is constrained to this exact disposable database name.
if ($db !== 'arasya_finance_search_performance_test') {
    throw new RuntimeException('Dedicated finance search performance test database required (arasya_finance_search_performance_test)');
}
$orderCount = (int) (getenv('ARASYA_FSS_ORDERS') ?: 10000);
$taskCount = (int) (getenv('ARASYA_FSS_TASKS') ?: 500);
if ($orderCount < 1000 || $orderCount > 100000 || $taskCount < 1 || $taskCount > 1000 || $taskCount > $orderCount) {
    throw new RuntimeException('ARASYA_FSS_ORDERS 1000..100000, ARASYA_FSS_TASKS 1..1000 (at most the orders)');
}
$config = T::config($db);
$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config->dbHost, $config->dbPort), $config->dbUser, $config->dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec('DROP DATABASE IF EXISTS arasya_finance_search_performance_test');
$server->exec('CREATE DATABASE arasya_finance_search_performance_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $file) {
    (new SqlFileRunner($pdo))->run($file);
}
$c = new Container($config, $pdo);
$kernel = $c->kernel();
$temporary = (new RootBootstrapService($pdo, $c->passwordHasher(), $c->clock()))->bootstrap('fss-perf', 'fss.perf.root');
$first = T::login($kernel, 'fss.perf.root', $temporary);
$rootPassword = 'finance search performance root 2026';
if (T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => $rootPassword], ['x-csrf-token' => $first['csrf']], $first['cookie'])['status'] !== 200) {
    throw new RuntimeException('Test root setup failed');
}
$root = T::login($kernel, 'fss.perf.root', $rootPassword);
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'fss-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$password = 'finance search performance fixture 2026';
$fin = $c->employeeAdmin()->create('Test fin perf', 'fss.perf.fin', null, 'pregatire-material', 'employee', $password, [], 'fss-perf');
foreach ([["/management/employees/{$fin->employeeUuid}/applications", ['applications' => ['staff']]],
    ["/management/employees/{$fin->employeeUuid}/finance-scopes", ['view' => ['outletperdele', 'trendhome'], 'record' => ['outletperdele', 'trendhome'], 'approve' => []]]] as [$path, $body]) {
    if ($asRoot('PUT', $path, $body)['status'] !== 200) {
        throw new RuntimeException("Test setup failed: {$path}");
    }
}
$session = T::login($kernel, 'fss.perf.fin', $password);

// One real order through the projection, cloned: the SYNTHETIC copies differ in ids, numbers, shop, names and dates.
if (T::ingest($kernel, 'trendhome', T::sourceOrder('fss-perf-template', 'fss-perf-template-event', gmdate('Y-m-d\TH:i:s\Z', time() - 60), T::stage('waiting')))['status'] !== 200) {
    throw new RuntimeException('Test template setup failed');
}
$template = $pdo->query("SELECT * FROM operational_orders WHERE source_order_id = 'fss-perf-template'")->fetch(PDO::FETCH_ASSOC);
$columns = array_keys($template);
$insert = $pdo->prepare('INSERT INTO operational_orders (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
$givens = ['Maria', 'Ion', 'Elena', 'Andrei', 'Ioana', 'Mihai', 'Ana', 'Gheorghe', 'Cristina', 'Vasile', 'Mariana', 'Alexandru', 'Daniela', 'Florin', 'Gabriela', 'Adrian', 'Ali', 'Emil', 'Ştefan', 'Ionuț'];
$families = ['Popescu', 'Ionescu', 'Popa', 'Pop', 'Radu', 'Dumitru', 'Stan', 'Stoica', 'Gheorghe', 'Matei', 'Ciobanu', 'Rusu', 'Munteanu', 'Constantin', 'Tăbăraș', 'Marin', 'Lungu', 'Dobre', 'Barbu', 'Nistor',
    'Florea', 'Ene', 'Toma', 'Moldovan', 'Demir', 'Zamfirescu', 'Vlad', 'Ursu', 'Sandu', 'Cristea', 'Dinu', 'Oprea', 'Neagu', 'Preda', 'Șerban', 'Mocanu', 'Avram', 'Lazăr', 'Sima', 'Costea'];
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$orders = [];
$seed = microtime(true);
for ($n = 0; $n < $orderCount; $n++) {
    if ($n % 500 === 0) {
        $pdo->beginTransaction();
    }
    $source = $n % 3 === 0 ? 'outletperdele' : 'trendhome';
    $number = (string) (100000 + $n);
    $id = Uuid::v4();
    $at = $now->modify('-' . ($n % 400) . ' days')->modify('-' . ($n % 1440) . ' minutes')->format('Y-m-d H:i:s');
    $row = $template;
    $row['order_uuid'] = $id;
    $row['source_key'] = $source;
    $row['global_order_id'] = "{$source}:fss-{$n}";
    $row['source_order_id'] = "fss-{$n}";
    $row['order_number'] = $number;
    $row['source_event_id'] = "fss-event-{$n}";
    $row['projection_hash'] = hash('sha256', $row['global_order_id'], true);
    $row['workflow_cohort'] = 'confirmare';
    $row['document_context'] = json_encode(['name' => $givens[$n % 20] . ' ' . $families[intdiv($n, 20) % 40], 'company' => $n % 20 === 7 ? 'SC Textile ' . $families[$n % 40] . ' SRL' : null,
        'addressLines' => ['Strada Sintetica ' . ($n % 90)], 'phoneMasked' => null], JSON_UNESCAPED_UNICODE);
    foreach (['created_at', 'source_changed_at', 'last_source_seen_at', 'projected_at', 'updated_at', 'accepted_at', 'source_submitted_at'] as $column) {
        if (array_key_exists($column, $row)) {
            $row[$column] = $at;
        }
    }
    $insert->execute(array_values($row));
    $orders[] = [$id, $row['global_order_id'], $source];
    if ($n % 500 === 499 || $n === $orderCount - 1) {
        $pdo->commit();
    }
}
// Tasks: `$taskCount` open advance checks assigned to the employee and closed verified tasks on every fifth other order,
// each with the amounts of its last read (finance_task_amounts), as the amounts run keeps them.
$task = $pdo->prepare("INSERT INTO finance_verification_requests (request_uuid, order_uuid, global_order_id, source_key, purpose, state, version, open_order_uuid, assigned_employee_uuid, assigned_at,
        requested_by_employee_uuid, requested_at, request_idempotency_key, ledger, proforma_series, proforma_number, policy, outcome, decided_by_employee_uuid, decided_at, updated_at)
    VALUES (?, ?, ?, ?, 'advance', ?, 1, ?, ?, ?, ?, ?, ?, 'shop', 'ARS', ?, 'single', ?, ?, ?, ?)");
$amounts = $pdo->prepare("INSERT INTO finance_task_amounts (request_uuid, currency, required_amount, remaining_amount, verified_amount, percentage, read_at, updated_at, order_total, received_amount, cod_remaining)
    VALUES (?, 'RON', ?, ?, ?, 30, ?, ?, ?, ?, ?)");
$money = static fn (int $bani): string => intdiv($bani, 100) . '.' . str_pad((string) ($bani % 100), 2, '0', STR_PAD_LEFT);
$tasks = 0;
$pdo->beginTransaction();
foreach ($orders as $n => [$orderUuid, $global, $source]) {
    $open = $n < $taskCount;
    if (!$open && $n % 5 !== 0) {
        continue;
    }
    $request = Uuid::v4();
    $at = $now->modify('-' . ($open ? $n % 72 : 30 + $n % 300) . ' hours')->format('Y-m-d H:i:s.u');
    $required = 5000 + ($n * 7919) % 200000;
    $task->execute([$request, $orderUuid, $global, $source, $open ? 'assigned' : 'verified', $open ? $orderUuid : null, $fin->employeeUuid, $at, $fin->employeeUuid, $at, "fss-{$n}",
        str_pad((string) (1 + $n % 9999), 4, '0', STR_PAD_LEFT), $open ? null : 'verified_full', $open ? null : $fin->employeeUuid, $open ? null : $at, $at]);
    $total = $required * 3 + ($n % 97) * 13;
    $verified = $open ? 0 : $required;
    $amounts->execute([$request, $money($required), $money($open ? $required : 0), $money($verified), $at, $at, $money($total), $money($verified), $money($total - $verified)]);
    if (++$tasks % 500 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
    }
}
$pdo->commit();
$seedSeconds = round(microtime(true) - $seed, 1);

$time = static function (string $path) use ($kernel, $session, $pdo): array {
    $runs = [];
    $body = null;
    for ($i = 0; $i < 8; $i++) {
        $pdo->exec('DELETE FROM api_rate_limit_buckets');
        $start = hrtime(true);
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $parameters);
        $response = $route === '/confirmare/finance/search'
            ? T::call($kernel, 'POST', $route, $parameters, ['origin' => T::ORIGIN, 'x-csrf-token' => $session['csrf']], $session['cookie'])
            : T::call($kernel, 'GET', $route, null, [], $session['cookie'], $parameters);
        $elapsed = (hrtime(true) - $start) / 1e6;
        if ($response['status'] !== 200) {
            throw new RuntimeException("{$path}: HTTP {$response['status']} " . json_encode($response['body']));
        }
        $body = $response['body'];
        if ($i > 0) {
            $runs[] = $elapsed;
        }
    }
    sort($runs);
    return [round($runs[intdiv(count($runs), 2)], 1), count($body['items'] ?? []), $body['truncated'] ?? null];
};
$queries = [
    'inbox (names + amounts)' => '/confirmare/finance/requests',
    'name "popescu"' => '/confirmare/finance/search?names=popescu',
    'name "maria popescu"' => '/confirmare/finance/search?names=maria,popescu',
    'typo "popesku"' => '/confirmare/finance/search?names=popesku',
    'diacritics "tabaras mariana"' => '/confirmare/finance/search?names=tabaras,mariana',
    'amount 610 +-10 RON' => '/confirmare/finance/search?amounts=61000&tolerance=1000',
    'amount 54.88 exact' => '/confirmare/finance/search?amounts=5488&tolerance=0',
    'amount 610 +-50 RON' => '/confirmare/finance/search?amounts=61000&tolerance=5000',
    '"emil demir 610" +-10' => '/confirmare/finance/search?names=emil,demir&amounts=61000&tolerance=1000',
    'bank text (names, proforma, amount)' => '/confirmare/finance/search?names=emil,demir,ali&proformas=848&amounts=61000&tolerance=1000',
    'order number' => '/confirmare/finance/search?orders=' . (100000 + intdiv($orderCount, 2)),
    'proforma number' => '/confirmare/finance/search?proformas=848',
];
$open = (int) $pdo->query("SELECT COUNT(*) FROM finance_verification_requests WHERE open_order_uuid IS NOT NULL")->fetchColumn();
$all = (int) $pdo->query('SELECT COUNT(*) FROM finance_verification_requests')->fetchColumn();
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
fwrite(STDOUT, sprintf("Finance search performance (SYNTHETIC data, %s): %d orders, %d tasks (%d open for the employee), %d amount rows, seeded in %.1f s\n",
    $version, $orderCount, $all, $open, (int) $pdo->query('SELECT COUNT(*) FROM finance_task_amounts')->fetchColumn(), $seedSeconds));
foreach ($queries as $label => $path) {
    [$ms, $items, $truncated] = $time($path);
    fwrite(STDOUT, sprintf("  %-38s %8.1f ms  %4d items%s\n", $label, $ms, $items, $truncated ? ' (truncated)' : ''));
}
$writes = (int) $pdo->query('SELECT COUNT(*) FROM finance_verification_request_events')->fetchColumn();
fwrite(STDOUT, "  finance events written by the searches: {$writes}\n");
