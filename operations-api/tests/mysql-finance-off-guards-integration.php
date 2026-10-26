<?php

declare(strict_types=1);

// API 2.46.1 (owner, after the first live sign-ins of 2026-10-26):
// 1. A bank check is refused while finance is switched off for the shop (no source access, or finance not `record`), and
//    nobody is offered it (`canRecord`); with finance `record` it works as before.
// 2. The document worklist (`GET /production-documents/attention`) shows only orders a document can still be made for:
//    a cancelled (unavailable) or completed order with a stale or revoked document is left out, and its rows stay as
//    they are.
// TEST people and TEST orders only, on a disposable test database; no shop is ever called.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL finance-off guards integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}

const FOG_KEY = 'trendhome-access-key-test-0123456789abcdef-fog';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): void { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); };

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on', 'paymentGate' => 'off'];
$base = T::config($dbName, [T::ORIGIN], ['trendhome' => $full, 'outletperdele' => $full]);
// The live state before the activation: Trend Home read access with finance off, Outlet Perdele without source access.
$off = new Config(...[...get_object_vars($base), 'sourceAccess' => [
    'trendhome' => ['mode' => 'read', 'url' => 'https://trendhome.example', 'key' => 'th-test:' . FOG_KEY, 'finance' => '', 'invoice' => 'off'],
]]);
// The same shop after finance is switched on.
$on = new Config(...[...get_object_vars($base), 'sourceAccess' => [
    'trendhome' => ['mode' => 'read', 'url' => 'https://trendhome.example', 'key' => 'th-test:' . FOG_KEY, 'finance' => 'record', 'invoice' => 'off'],
]]);
$pdo = Connection::create($off);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$pdo->exec('DELETE FROM confirmare_bank_checks');

$container = new Container($off, $pdo);
$kernel = $container->kernel();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-fog-root', "fog.root.{$suffix}");
$first = T::login($kernel, "fog.root.{$suffix}", $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Guards root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, "fog.root.{$suffix}", 'Guards root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'fog-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$password = 'guards test passphrase 2026';
$uuids = [];
$roles = array_column($ok($asRoot('GET', '/management/roles'), 'roles')['items'], 'id', 'key');
foreach (['fogrec' => ['view' => ['outletperdele', 'trendhome'], 'record' => ['outletperdele', 'trendhome'], 'approve' => []], 'fogview' => ['view' => ['trendhome'], 'record' => [], 'approve' => []], 'fogdoc' => null] as $name => $finance) {
    // Generic TEST identities: access only from the grants below, never from a name.
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'test-fog');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['staff']]), "{$name} applications");
    if ($finance !== null) $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/finance-scopes", $finance), "{$name} finance scopes");
}
$ok($asRoot('PUT', "/management/employees/{$uuids['fogdoc']}/roles", ['roleIds' => [(int) $roles['production-documents-operator']]]), 'document role');
$ok($asRoot('PUT', "/management/employees/{$uuids['fogdoc']}/document-scopes", ['operate' => ['trendhome'], 'approve' => []]), 'document scope Trend Home');
$session = static function (Container $c, string $name) use ($suffix, $password): array { return T::login($c->kernel(), "{$name}.{$suffix}", $password); };
$post = static fn (Container $c, array $who, string $source, ?string $key = null): array => T::call($c->kernel(), 'POST', '/confirmare/finance/bank-check', ['source' => $source],
    ['origin' => T::ORIGIN, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? 'fog-bank-' . bin2hex(random_bytes(10))], $who['cookie']);
$list = static fn (Container $c, array $who): array => T::call($c->kernel(), 'GET', '/confirmare/finance/bank-checks', null, ['origin' => T::ORIGIN], $who['cookie']);
$rows = static fn (): int => (int) $one('SELECT COUNT(*) FROM confirmare_bank_checks');

// ---- 1. Bank check while finance is off -----------------------------------------------------------------------------
$rec = $session($container, 'fogrec');
$view = $session($container, 'fogview');
$listing = $ok($list($container, $rec), 'the recorder reads the bank checks while finance is off');
$check($listing['sources'] === ['trendhome', 'outletperdele'] && $listing['canRecord'] === [], 'finance off: the recorder still sees both shops, but no shop offers the bank check: ' . json_encode($listing['canRecord']));
$error($post($container, $rec, 'trendhome'), 409, 'CONFIRMARE_FINANCE_DISABLED', 'Trend Home (read access, finance off): the bank check is refused');
$error($post($container, $rec, 'outletperdele'), 409, 'CONFIRMARE_FINANCE_DISABLED', 'Outlet Perdele (no source access): the bank check is refused (it was a misleading 400 UNKNOWN_SOURCE before)');
$error($post($container, $rec, 'b2b'), 400, 'UNKNOWN_SOURCE', 'a source without Confirmare is still an unknown source');
$error($post($container, $view, 'trendhome'), 403, 'CONFIRMARE_FINANCE_DENIED', 'a viewer is refused for the permission, as before');
$check($rows() === 0, 'nothing was written while finance is off');

// ---- The same shop with finance `record`: unchanged behaviour ----------------------------------------------------------
$enabled = new Container($on, $pdo);
$recOn = $session($enabled, 'fogrec');
$check($ok($list($enabled, $recOn), 'finance on: bank checks')['canRecord'] === ['trendhome'], 'finance record on Trend Home: the recorder is offered the Trend Home bank check only');
$key = 'fog-bank-' . bin2hex(random_bytes(10));
$first = $ok($post($enabled, $recOn, 'trendhome', $key), 'finance on: the bank check is recorded');
$check($ok($post($enabled, $recOn, 'trendhome', $key), 'the same key again')['check'] === $first['check'] && $rows() === 1, 'finance on: one row, a retry answers the stored check');
$error($post($enabled, $recOn, 'outletperdele'), 409, 'CONFIRMARE_FINANCE_DISABLED', 'finance on for Trend Home only: Outlet Perdele stays refused');
$check($rows() === 1, 'still one row');

// ---- 2. The document worklist leaves out orders no document can be made for ---------------------------------------------
$base = T::freeOrderBase($pdo, 640000, 659999, 10);
$id = static fn (int $n): string => (string) ($base + $n);
$tick = 0;
$ingest = static function (int $n, string $availability) use ($kernel, $id, $suffix, &$tick, $ok): void {
    $tick++;
    $ok(T::ingest($kernel, 'trendhome', T::sourceOrder($id($n), "fog-{$n}-{$suffix}-{$tick}", gmdate('Y-m-d\TH:i:s', time() - 600 + $tick) . 'Z', null, 'processing', $availability)), "TEST order {$n} {$availability}");
};
foreach ([1, 2, 3, 4] as $n) $ingest($n, 'active');
$ingest(2, 'cancelled');
$global = static fn (int $n): string => 'trendhome:' . $id($n);
// TEST ONLY: the document states a real revoke, content change and completion leave behind (the document flows that
// produce them are covered by the production document suites).
$pdo->prepare("UPDATE operational_orders SET document_status = 'revoked', document_version = document_version + 1 WHERE global_order_id IN (?, ?)")->execute([$global(1), $global(2)]);
$pdo->prepare("UPDATE operational_orders SET document_status = 'stale', document_version = document_version + 1 WHERE global_order_id IN (?, ?)")->execute([$global(3), $global(4)]);
$pdo->prepare('UPDATE operational_orders SET production_completed_at = UTC_TIMESTAMP(6) WHERE global_order_id = ?')->execute([$global(4)]);
$check((string) $one('SELECT operational_status FROM operational_orders WHERE global_order_id = ?', [$global(2)]) === 'unavailable', 'the shop cancelled order 2 (unavailable)');
$state = static fn (): string => json_encode($pdo->query("SELECT global_order_id, document_status, document_version, operational_status, production_completed_at FROM operational_orders WHERE global_order_id LIKE 'trendhome:" . $base . "%' ORDER BY global_order_id")->fetchAll(PDO::FETCH_ASSOC));
$before = $state();
$doc = $session($container, 'fogdoc');
$items = $ok(T::call($kernel, 'GET', '/production-documents/attention', null, ['origin' => T::ORIGIN], $doc['cookie']), 'document worklist')['items'];
$listed = array_values(array_intersect(array_column($items, 'orderId'), [$global(1), $global(2), $global(3), $global(4)]));
sort($listed);
$expected = [$global(1), $global(3)];
sort($expected);
$check($listed === $expected, 'the worklist keeps the open revoked and stale orders and leaves out the cancelled and the completed one: ' . json_encode($listed));
$check($state() === $before, 'reading the worklist changes no order: the cancelled and completed orders keep their document state');
$error(T::call($kernel, 'POST', '/production-documents/orders/' . rawurlencode($global(2)) . '/generate', ['expectedDocumentVersion' => (int) $one('SELECT document_version FROM operational_orders WHERE global_order_id = ?', [$global(2)])],
    ['origin' => T::ORIGIN, 'x-csrf-token' => $doc['csrf'], 'idempotency-key' => 'fog-gen-' . bin2hex(random_bytes(8))], $doc['cookie']), 409, 'ORDER_UNAVAILABLE', 'the left-out cancelled order is indeed no work: generation is refused');

fwrite(STDOUT, "PASS MySQL finance-off guards integration: {$checks} checks (bank check refused while finance is off and never offered, unchanged with finance record; the document worklist leaves out cancelled and completed orders without touching them).\n");
