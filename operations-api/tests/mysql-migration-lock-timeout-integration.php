<?php

declare(strict_types=1);

// Migration 050 on a live database: what happens when an ALTER cannot get its metadata lock.
// An open transaction that read operational_orders holds a shared metadata lock. `bin/migrate.php --lock-wait-timeout=2`
// must then fail within seconds instead of waiting (server default 86400 s) and holding back every later query of the
// table. The statements before the failed one stay applied (DDL commits on its own), 050 is not recorded, and a second run
// after the transaction ends completes 050 exactly once. Disposable database, no data needed.

use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$db = T::requireTestDatabase();
if ($db === null) {
    echo "SKIP MySQL migration lock timeout: ARASYA_TEST_DB_NAME not configured.\n";
    exit;
}
$checks = 0;
function check(bool $value, string $message): void { global $checks; $checks++; if (!$value) throw new RuntimeException('FAIL ' . $message); }

$db = substr($db, 0, 40) . '_mig_lock';
$dsn = 'mysql:host=' . (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('ARASYA_TEST_DB_PORT') ?: '3306');
$user = (string) getenv('ARASYA_TEST_DB_USER');
$password = (string) getenv('ARASYA_TEST_DB_PASSWORD');
$server = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `{$db}`");
$server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = Connection::create(T::config($db));

// Production state before R4: every migration except 050 applied.
$source = dirname(__DIR__) . '/database/migrations';
$staging = sys_get_temp_dir() . '/arasya-mig-lock-' . bin2hex(random_bytes(4));
mkdir($staging, 0700);
foreach (glob($source . '/*.sql') as $file) {
    if (basename($file) !== '050_b2b_order_types.sql') copy($file, $staging . '/' . basename($file));
}
(new MigrationRunner($pdo))->migrate($staging);
array_map('unlink', glob($staging . '/*.sql'));
rmdir($staging);

$scalar = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); $v = $s->fetchColumn(); return $v === false ? null : $v; };
$column = static fn (string $table, string $name): bool => (int) $scalar('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $name]) === 1;
$constraint = static fn (string $table, string $name): bool => (int) $scalar('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ?', [$table, $name]) === 1;
$recorded = static fn (): int => (int) $scalar("SELECT COUNT(*) FROM schema_migrations WHERE migration_name = '050_b2b_order_types.sql'");
$migrations = static fn (): int => (int) $scalar('SELECT COUNT(*) FROM schema_migrations');

check($recorded() === 0 && !$column('b2b_orders', 'order_type') && !$column('operational_orders', 'service_route'), 'start: 050 pending, as in production before R4');
check($constraint('production_route_decisions', 'chk_production_route_decisions_reason'), 'start: the pre-050 route reason constraint exists');

$environment = ['HOME' => (string) getenv('HOME'), 'ARASYA_APP_ENV' => 'test', 'ARASYA_APP_SECRET' => str_repeat('t', 32), 'ARASYA_ALLOWED_ORIGINS' => T::ORIGIN,
    'ARASYA_DB_HOST' => (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1'), 'ARASYA_DB_PORT' => (string) (getenv('ARASYA_TEST_DB_PORT') ?: '3306'),
    'ARASYA_DB_NAME' => $db, 'ARASYA_DB_USER' => $user, 'ARASYA_DB_PASSWORD' => $password];
$migrate = static function (array $arguments) use ($environment): array {
    $started = microtime(true);
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/migrate.php', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => $out, 'err' => $err, 'seconds' => microtime(true) - $started];
};

// 1. A malformed option is refused before any database work.
$before = $migrations();
foreach (['--lock-wait-timeout=0', '--lock-wait-timeout=601', '--lock-wait-timeout=abc', '--lock-wait-timeout=', '--force', '-v'] as $bad) {
    $r = $migrate([$bad]);
    check($r['code'] === 2 && str_contains($r['err'], 'Usage: php bin/migrate.php') && $r['out'] === '', "1. {$bad} is refused with usage");
}
check($migrations() === $before && $recorded() === 0, '1. refused options changed nothing');

// 2. An open transaction holds a shared metadata lock on operational_orders (a long Staff read, a stuck worker).
$holder = new PDO($dsn . ';dbname=' . $db, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$holder->beginTransaction();
$holder->query('SELECT COUNT(*) FROM operational_orders')->fetchColumn();

$r = $migrate(['--lock-wait-timeout=2']);
check($r['code'] === 1, '2. the migration fails while the lock is held: ' . $r['out'] . $r['err']);
check($r['seconds'] < 30, '2. it gives up within seconds, not the server default (took ' . round($r['seconds'], 1) . ' s)');
check(str_contains($r['out'], 'Session lock_wait_timeout: 2 s'), '2. the session limit is announced');
check(str_contains($r['err'], 'Migration stopped:') && str_contains($r['err'], '1205'), '2. the lock wait timeout (1205) is reported');
check(!str_contains($r['err'], $password) || $password === '', '2. no password in the output');
check($recorded() === 0, '2. 050 is not recorded after the failure');
check($column('b2b_orders', 'order_type') && $column('b2b_production_handoffs', 'finance_decision'), '2. statements before the failed one stay applied (partial state)');
check((int) $scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'b2b_finance_gate_decisions'") === 1, '2. the journal table exists in the partial state');
check(!$column('operational_orders', 'service_route'), '2. the blocked ALTER left operational_orders unchanged');
check($constraint('production_route_decisions', 'chk_production_route_decisions_reason'), '2. later statements did not run');
$holder->query('SELECT 1')->fetchColumn();
check($holder->inTransaction(), '2. the blocking transaction itself was not disturbed');

// 3. The transaction ends; the same command completes 050 exactly once.
$holder->commit();
$r = $migrate(['--lock-wait-timeout=5']);
check($r['code'] === 0 && str_contains($r['out'], 'Applied: 050_b2b_order_types.sql'), '3. the second run applies 050: ' . $r['out'] . $r['err']);
check($recorded() === 1 && $scalar("SELECT checksum FROM schema_migrations WHERE migration_name = '050_b2b_order_types.sql'") === hash_file('sha256', $source . '/050_b2b_order_types.sql'), '3. 050 recorded once with its checksum');
foreach ([['b2b_orders', 'order_type'], ['b2b_orders', 'finance_decision'], ['b2b_production_handoffs', 'order_type'], ['b2b_production_handoffs', 'finance_decision'], ['operational_orders', 'service_route']] as [$table, $name]) {
    check($column($table, $name), "3. {$table}.{$name} exists once");
}
foreach ([['b2b_orders', 'chk_b2b_orders_order_type'], ['b2b_orders', 'chk_b2b_orders_finance_decision'], ['b2b_production_handoffs', 'chk_b2b_production_handoffs_order_type'],
    ['b2b_production_handoffs', 'chk_b2b_production_handoffs_finance_decision'], ['operational_orders', 'chk_operational_orders_service_route'],
    ['production_route_decisions', 'chk_production_route_decisions_reason_050']] as [$table, $name]) {
    check($constraint($table, $name), "3. constraint {$name} exists");
}
check(!$constraint('production_route_decisions', 'chk_production_route_decisions_reason'), '3. the old route reason constraint is replaced');

// 4. A third run changes nothing; the plain command (no option) behaves as before.
$r = $migrate([]);
check($r['code'] === 0 && trim($r['out']) === 'Database schema is current.', '4. without the option the command is unchanged: ' . $r['out'] . $r['err']);
check($recorded() === 1, '4. still one 050 record');

$server->exec("DROP DATABASE IF EXISTS `{$db}`");
echo "PASS migration lock timeout: {$checks} checks (050 partial failure and safe re-run).\n";
