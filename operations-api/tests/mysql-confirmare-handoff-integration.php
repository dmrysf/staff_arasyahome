<?php

declare(strict_types=1);

// Confirmare production handoff (API 2.42.0, Phase 6) on a disposable database, through the HTTP kernel. After the
// Confirmare approval and the financial condition, an authorized shop employee generates revision 1 of the central
// production document and prints it; the cutting QR scan of that document starts production (the existing 1 -> 2
// transition). Covers: the handoff state and pool view, both conditions mandatory and independent, a pending finance
// action or shop change closing production at once, the shop scopes (Trend Home / Outlet Perdele), a double PDF and two
// employees generating at once, the mandatory reprint reason (Staff and the signed source), a double QR scan and two
// cutters scanning at once, an order revision through the existing approval, a cancellation, and the legacy flow.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Production\ConfirmationGate;
use Arasya\Operations\Production\WorkflowCohortActivation;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\MutableClock;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';
require __DIR__ . '/TestDoubles.php';

$dbName = T::requireTestDatabase();
if ($dbName === null) {
    fwrite(STDOUT, "SKIP MySQL Confirmare handoff integration: ARASYA_TEST_DB_NAME is not configured.\n");
    exit(0);
}

// Every server log line of this run goes to one file, scanned at the end for the customer's telephone number.
$logFile = tempnam(sys_get_temp_dir(), 'arasya-handoff-log-');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

const HO_DASHBOARD = 'http://127.0.0.1:4174';
const HO_PHONE = '0733555666';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException("FAIL {$label}"); };
$ok = static function (array $r, string $label, int $status = 200) use ($check): array { $body = $r['body']; unset($body['document']['pdf']); $check($r['status'] === $status, "{$label} HTTP {$r['status']} " . json_encode($body)); return is_array($r['body']) ? $r['body'] : []; };
$error = static function (array $r, int $status, string $code, string $label) use ($check): array { $check($r['status'] === $status && ($r['body']['error']['code'] ?? '') === $code, "{$label}: expected {$status} {$code}, got {$r['status']} " . json_encode($r['body'])); return $r['body'] ?? []; };

$full = ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'enforce', 'confirmare' => 'on', 'paymentGate' => 'off'];
$settings = ['trendhome' => $full, 'outletperdele' => $full];
$config = T::config($dbName, [T::ORIGIN, HO_DASHBOARD], $settings);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [] as $seed) (new SqlFileRunner($pdo))->run($seed);
$one = static function (string $sql, array $params = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); };
$all = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };
foreach (['order_finance_actions', 'order_finance_reads', 'employee_finance_scopes', 'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('DELETE FROM api_rate_limit_buckets');
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

$container = new Container($config, $pdo);
$kernel = $container->kernel();
$service = $container->confirmareService();
$registry = SourceRegistry::fromConfig($config);
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$pdo->exec('DELETE FROM system_root_identity');
$rootName = "ho.root.{$suffix}";
$temporary = (new RootBootstrapService($pdo, $container->passwordHasher(), $container->clock()))->bootstrap('test-ho-root', $rootName);
$first = T::login($kernel, $rootName, $temporary);
$ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Handoff root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 'root password');
$root = T::login($kernel, $rootName, 'Handoff root passphrase 2026!');
$asRoot = static fn (string $method, string $path, ?array $body = null): array => T::call($kernel, $method, $path, $body, ['x-csrf-token' => $root['csrf'], 'idempotency-key' => 'ho-root-' . bin2hex(random_bytes(10))], $root['cookie']);
$roleIds = [];
foreach ($ok($asRoot('GET', '/management/roles'), 'roles')['items'] as $role) $roleIds[$role['key']] = (int) $role['id'];
$password = 'handoff test passphrase 2026';
$uuids = [];
// Generic test identities: every access comes from the roles and scopes granted here, never from a name.
$identity = static function (string $name, array $stages, array $apps, array $roles = []) use ($container, $asRoot, $ok, $password, $suffix, &$uuids): void {
    $employee = $container->employeeAdmin()->create("Test {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'test-ho');
    $uuids[$name] = $employee->employeeUuid;
    $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => $apps]), "{$name} applications");
    if ($roles !== []) $ok($asRoot('PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => $roles]), "{$name} roles");
};
$documents = $roleIds['production-documents-operator'];
$identity('hoshopth', [], ['staff'], [$documents]);   // Trend Home: Confirmare operator and production documents
$identity('hoshopop', [], ['staff'], [$documents]);   // Outlet Perdele: the same, for its own shop only
$identity('hodocth', [], ['staff'], [$documents]);    // a second Trend Home document employee (no Confirmare scope)
$identity('hosupth', [], ['staff']);                  // Trend Home Confirmare supervisor, no document permission
$identity('hodoconly', [], ['staff'], [$documents]);  // Trend Home document employee only: no Confirmare scope at all
$identity('hocutth', ['material-preparation'], ['staff']);
$identity('hocutth2', ['material-preparation'], ['staff']);
$identity('hocutop', ['material-preparation'], ['staff']);
$identity('howaiter', ['waiting'], ['staff']);
$identity('hoapprover', [], ['dashboard'], [$roleIds['document-revision-approver']]);
foreach (['hoshopth' => ['operate' => ['trendhome'], 'supervise' => []], 'hoshopop' => ['operate' => ['outletperdele'], 'supervise' => []], 'hosupth' => ['operate' => [], 'supervise' => ['trendhome']]] as $name => $grant) {
    $ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/confirmare-scopes", $grant), "confirmare {$name}");
}
foreach (['hoshopth' => ['trendhome'], 'hoshopop' => ['outletperdele'], 'hodocth' => ['trendhome'], 'hodoconly' => ['trendhome']] as $name => $sources) {
    $ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/document-scopes", ['operate' => $sources, 'approve' => []]), "documents {$name}");
}
$ok($asRoot('PUT', "/management/employees/{$uuids['hoapprover']}/document-scopes", ['operate' => [], 'approve' => ['trendhome', 'outletperdele']]), 'approver scope');
foreach (['hocutth' => 'trendhome', 'hocutth2' => 'trendhome', 'hocutop' => 'outletperdele'] as $name => $src) {
    $ok($asRoot('PUT', "/management/employees/{$uuids[$name]}/stage-scopes", ['stageId' => 'material-preparation', 'sources' => [$src], 'expectedSources' => null]), "{$name} cutting scope");
}
$login = static fn (string $name): array => T::login($kernel, "{$name}.{$suffix}", $password);
$dash = static function (string $name) use ($kernel, $suffix, $password): array {
    $r = T::call($kernel, 'POST', '/auth/login', ['username' => "{$name}.{$suffix}", 'password' => $password], ['origin' => HO_DASHBOARD]);
    if ($r['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $r['headers']['Set-Cookie'] ?? '', $m)) throw new RuntimeException("login {$name}");
    return ['cookie' => rawurldecode($m[1]), 'csrf' => (string) $r['body']['csrfToken'], 'origin' => HO_DASHBOARD];
};
$shopTh = $login('hoshopth');
$shopOp = $login('hoshopop');
$docTh = $login('hodocth');
$supTh = $login('hosupth');
$docOnly = $login('hodoconly');
$cutTh = $login('hocutth');
$cutTh2 = $login('hocutth2');
$cutOp = $login('hocutop');
$waiter = $login('howaiter');
$approver = $dash('hoapprover');

$call = static fn (array $who, string $method, string $path, ?array $body = null, ?string $key = null, ?object $k = null): array => T::call($k ?? $kernel, $method, $path, $body,
    ['origin' => $who['origin'] ?? T::ORIGIN, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? 'ho-' . bin2hex(random_bytes(10))], $who['cookie']);
$get = static fn (array $who, string $path, array $query = []): array => T::call($kernel, 'GET', $path, null, ['origin' => $who['origin'] ?? T::ORIGIN], $who['cookie'], $query);
$version = static fn (string $g): int => (int) $one('SELECT version FROM order_confirmations WHERE global_order_id = ?', [$g]);
$act = static fn (array $who, string $g, string $action, array $body): array => $call($who, 'POST', '/confirmare/orders/' . rawurlencode($g) . '/' . $action, $body);
$detail = static fn (array $who, string $g): array => $ok($get($who, '/confirmare/orders/' . rawurlencode($g)), "detail {$g}");
$handoff = static fn (array $who, string $g): array => $detail($who, $g)['handoff'];
$approve = static function (array $who, string $g) use ($detail, $act, $version, $ok): array {
    $review = $detail($who, $g)['review'];
    $ok($act($who, $g, 'contact', ['expectedVersion' => $version($g), 'outcome' => 'confirmed', 'reviewFingerprint' => $review['fingerprint']]), "call {$g}");
    return $ok($act($who, $g, 'approve', ['expectedVersion' => $version($g), 'customerCall' => 'confirmed', 'confirmedLineIds' => $review['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $review['fingerprint']]), "approve {$g}");
};
$docPath = static fn (string $g, string $action = ''): string => '/production-documents/orders/' . rawurlencode($g) . ($action === '' ? '' : '/' . $action);
$docVersion = static fn (string $g): int => (int) $one('SELECT document_version FROM operational_orders WHERE global_order_id = ?', [$g]);
$generate = static fn (array $who, string $g, ?string $key = null): array => $call($who, 'POST', $docPath($g, 'generate'), ['expectedDocumentVersion' => $docVersion($g)], $key);
$print = static fn (array $who, string $g, int $revision, ?string $reason = null, ?string $key = null): array => $call($who, 'POST', $docPath($g, 'print'), ['revisionNumber' => $revision] + ($reason === null ? [] : ['reason' => $reason]), $key);
$sourcePrint = static fn (string $source, string $id, ?int $revision, array $extra = []): array => T::ingest($kernel, $source, ['orderId' => $id, 'issue' => $revision === null, 'revisionNumber' => $revision, 'actor' => ['id' => 7, 'name' => 'Operator Magazin']] + $extra, null, null, 'orders/document');
$qr = static fn (string $g): string => 'ARASYA:Q1:' . (string) $one("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? AND q.status = 'active'", [$g]);
$cutters = ['hocutth' => $cutTh, 'hocutth2' => $cutTh2, 'hocutop' => $cutOp];
$owned = static fn (string $name): int => (int) $one("SELECT COUNT(*) FROM operational_orders WHERE production_owner_employee_uuid = ? AND production_stage_id = 'material-preparation' AND production_completed_at IS NULL", [$uuids[$name]]);
// A cutter who already owns unfinished jobs confirms one more explicitly (the existing intake rule).
$scanBody = static fn (string $name, string $token): array => ['qrToken' => $token] + ($owned($name) > 0 ? ['confirmedMultiple' => true, 'ownedCount' => $owned($name)] : []);
$intake = static fn (string $name, string $token, ?string $key = null, ?array $body = null): array => $call($cutters[$name], 'POST', '/cutting/intake', $body ?? $scanBody($name, $token), $key);
$order = static fn (string $g): array => $all('SELECT order_uuid, production_stage_id, production_owner_employee_uuid, production_version, document_status, operational_status FROM operational_orders WHERE global_order_id = ?', [$g])[0];
$revisions = static fn (string $g): int => (int) $one('SELECT COUNT(*) FROM production_document_revisions r JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE o.global_order_id = ?', [$g]);
$events = static fn (string $g): array => array_column($all('SELECT e.action FROM order_activity_events e JOIN operational_orders o ON o.order_uuid = e.order_uuid WHERE o.global_order_id = ? ORDER BY e.production_version_after, e.action', [$g]), 'action');
$parallel = static function (array $calls) use ($dbName, $settings, $check): array {
    if (!function_exists('pcntl_fork')) return [];
    $files = []; $children = [];
    foreach ($calls as $index => [$who, $path, $body, $idem]) {
        $files[$index] = tempnam(sys_get_temp_dir(), 'handoff');
        $pid = pcntl_fork();
        if ($pid === 0) {
            $childKernel = (new Container(T::config($dbName, [T::ORIGIN, HO_DASHBOARD], $settings)))->kernel();
            usleep(20000);
            $response = T::call($childKernel, 'POST', $path, $body, ['origin' => T::ORIGIN, 'x-csrf-token' => $who['csrf'], 'idempotency-key' => $idem], $who['cookie']);
            file_put_contents($files[$index], json_encode(['status' => $response['status'], 'body' => $response['body']]));
            // End without destructors: the inherited parent connection must not be closed by the child.
            posix_kill(posix_getpid(), SIGKILL);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) pcntl_waitpid($pid, $status);
    $results = [];
    foreach ($files as $index => $file) { $results[$index] = json_decode((string) file_get_contents($file), true); unlink($file); }
    $check(count($results) === count($calls), 'every parallel request answered');
    return $results;
};

// ---- Fixtures: activated sources, Confirmare orders of both shops, a legacy order -------------------------------------
$iso = static fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutover = \Arasya\Operations\Production\WorkflowCohort::cutover();
if ($now < $cutover->modify('+10 minutes')) {
    fwrite(STDOUT, "SKIP MySQL Confirmare handoff integration: needs a clock after the Confirmare cutover.\n");
    exit(0);
}
$check(ConfirmationGate::WORKFLOW_READY === false, 'Phase 6 keeps WORKFLOW_READY false');
$activatedAt = max($cutover->modify('+1 second'), $now->modify('-2 hours'));
foreach (['trendhome', 'outletperdele'] as $src) {
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-20 minutes'))))->schedule($registry->find($src), $activatedAt, 'Test Owner', 'handoff test', 'ho-test', true);
    T::ingest($kernel, $src, ['sentAt' => gmdate('Y-m-d\TH:i:s\Z'), 'confirmareArmedAt' => $iso($activatedAt)], null, null, 'heartbeat');
    (new WorkflowCohortActivation($pdo, new MutableClock($activatedAt->modify('-1 minute'))))->activate($registry->find($src), 'Test Owner', 'handoff test', 'ho-test', true);
}
$base = T::freeOrderBase($pdo, 860000, 959999, 100);
$id = static fn (int $n): string => (string) ($base + $n);
$th = static fn (int $n): string => 'trendhome:' . $id($n);
$tick = 0;
$payload = static function (string $orderId, int $seconds, array $extra = []) use ($suffix, &$tick, $iso, $activatedAt): array {
    $tick++;
    $p = T::sourceOrder($orderId, "ho-{$orderId}-{$suffix}-{$tick}", gmdate('Y-m-d\TH:i:s', time() - 4000 + $tick) . '.' . sprintf('%06d', $tick) . 'Z', null, $extra['status'] ?? 'processing', $extra['availability'] ?? 'active', [[
        'id' => 9301, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej', 'width' => 3, 'height' => 2.6, 'unit' => 'm', 'quantity' => $extra['quantity'] ?? 1,
    ]]);
    unset($p['order']['notes']);
    $pricing = 'sha256:' . hash('sha256', "ho-price-{$orderId}-" . ($extra['quantity'] ?? 1));
    $p['order']['pricing'] = ['fingerprint' => $pricing];
    $cod = ($extra['payment'] ?? 'online') === 'cod';
    $p['order']['payment'] = $cod ? ['method' => 'cod', 'advanceStatus' => 'waiting', 'advanceRevision' => 0] : ['method' => 'online', 'advanceStatus' => 'not_required', 'advanceRevision' => 0];
    $p['order']['financial'] = $cod ? ['state' => 'waiting', 'basis' => null, 'pricingFingerprint' => $pricing] : ['state' => 'cleared', 'basis' => 'online_payment', 'pricingFingerprint' => $pricing];
    $p['order']['delivery'] = ['name' => 'TEST Client Predare', 'street' => 'Str. Exemplu 6', 'city' => 'Brasov', 'postalCode' => '500000', 'country' => 'RO', 'phone' => HO_PHONE];
    $p['order']['submission'] = ['submittedAt' => $iso($activatedAt->modify("{$seconds} seconds")), 'evidence' => 'checkout'];
    return $p;
};
foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $n) $ok(T::ingest($kernel, 'trendhome', $payload($id($n), 60 + $n, $n === 3 ? ['payment' => 'cod'] : [])), "trendhome order {$n}");
$ok(T::ingest($kernel, 'outletperdele', $payload($id(50), 120)), 'outlet order');
$ok(T::ingest($kernel, 'trendhome', $payload($id(60), -3600)), 'legacy order (submitted before the activation)');
$outlet = 'outletperdele:' . $id(50);
$check($order($th(1))['production_stage_id'] === 'waiting' && (string) $one('SELECT workflow_cohort FROM operational_orders WHERE global_order_id = ?', [$th(1)]) === 'confirmare'
    && (string) $one('SELECT workflow_cohort FROM operational_orders WHERE global_order_id = ?', [$th(60)]) === 'legacy', 'Confirmare orders wait at stage 1; the legacy order keeps its cohort');
$check($service->distribute()['assigned'] >= 9, 'each shop\'s orders go to its own operator');

// ---- 1. Before the decision: nothing to hand over ------------------------------------------------------------------
$h = $handoff($shopTh, $th(1));
$check($h['state'] === 'blocked' && in_array('approval', $h['blockers'], true) && in_array('call', $h['blockers'], true) && $h['actions'] === ['generate' => false, 'print' => false] && $h['stageId'] === 'waiting', 'pending order: blocked by the missing decision: ' . json_encode($h));
$check(!str_contains((string) json_encode($h), substr(HO_PHONE, -6)), 'the handoff state carries no customer data');
$error($generate($shopTh, $th(1)), 409, 'CONFIRMARE_NECESARA', 'no revision 1 before the approval');
$error($sourcePrint('trendhome', $id(1), null), 409, 'CONFIRMARE_NECESARA', 'nor through the signed shop');
$check($revisions($th(1)) === 0, 'no document, no new QR');

// ---- 2. Approved and paid: ready; the shop scopes separate Trend Home and Outlet Perdele ----------------------------
$approve($shopTh, $th(1));
$h = $handoff($shopTh, $th(1));
$check($h['state'] === 'ready' && $h['blockers'] === [] && $h['actions'] === ['generate' => true, 'print' => false] && $h['document'] === ['status' => 'none', 'revision' => null, 'prints' => 0], 'cleared: ready for revision 1: ' . json_encode($h));
$check($handoff($supTh, $th(1))['actions'] === ['generate' => false, 'print' => false], 'a supervisor without the document permission sees the state, never the action');
$pool = $ok($get($shopTh, '/confirmare/handoff'), 'handoff pool');
$check(in_array($th(1), array_column($pool['items'], 'globalOrderId'), true) && ($pool['items'][array_search($th(1), array_column($pool['items'], 'globalOrderId'), true)]['step'] ?? null) === 'ready', 'the handoff list shows the cleared order as ready');
$check(!in_array($th(2), array_column($pool['items'], 'globalOrderId'), true), 'an order still pending is not in the handoff list');
$error($get($supTh, '/confirmare/handoff'), 403, 'UNAUTHORIZED_ACTION', 'the handoff list needs the document permission (a Confirmare scope is not enough)');
$error($generate($supTh, $th(1)), 403, 'UNAUTHORIZED_ACTION', 'a Confirmare scope alone never creates a production document');
$check(!in_array($th(1), array_column($ok($get($shopOp, '/confirmare/handoff'), 'outlet handoff pool')['items'], 'globalOrderId'), true), 'Outlet Perdele never lists a Trend Home order');
$error($get($shopOp, '/confirmare/orders/' . rawurlencode($th(1))), 404, 'CONFIRMARE_NOT_FOUND', 'Outlet Perdele cannot open the Trend Home order');
$error($generate($shopOp, $th(1)), 404, 'ORDER_NOT_FOUND', 'an Outlet Perdele employee cannot generate a Trend Home document');
$check($revisions($th(1)) === 0, 'the refusals changed nothing');

// ---- 2b. The document authority is separate from the Confirmare distribution -----------------------------------------
// The server checks, each on its own: the order's shop (document `operate` scope of the source), the document generate
// permission, and the order's Confirmare and financial clearance (ConfirmationGate, PaymentGate). No Confirmare scope is
// needed to hand an order to production, and only the Confirmare `operate` scope joins the automatic distribution.
$targets = array_column((new \Arasya\Operations\Confirmare\ConfirmareScopePolicy($pdo))->distributionTargets('trendhome'), 'employee_uuid');
$check(in_array($uuids['hoshopth'], $targets, true) && !in_array($uuids['hodoconly'], $targets, true) && !in_array($uuids['hodocth'], $targets, true) && !in_array($uuids['hosupth'], $targets, true),
    'only the Confirmare operate scope is a distribution target; document employees and supervisors never are');
$check((int) $one('SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid IN (?, ?)', [$uuids['hodoconly'], $uuids['hodocth']]) === 0, 'no Confirmare order was ever assigned to a document employee');
$check(($ok($get($docOnly, '/employees/me'), 'document employee session')['confirmareScopes'] ?? null) === ['operate' => [], 'supervise' => []], 'the document employee holds no Confirmare scope');
$error($get($docOnly, '/confirmare/pool'), 403, 'CONFIRMARE_SCOPE_DENIED', 'no customer confirmation pool for a document employee');
$error($call($docOnly, 'POST', '/confirmare/next', ['source' => null]), 403, 'CONFIRMARE_SCOPE_DENIED', 'nor can it take the next order to call');
$error($get($docOnly, '/confirmare/orders/' . rawurlencode($th(1))), 404, 'CONFIRMARE_NOT_FOUND', 'nor open the Confirmare order (customer data)');
$list = $ok($get($docOnly, '/confirmare/handoff'), 'handoff list of a document employee');
$row = $list['items'][array_search($th(1), array_column($list['items'], 'globalOrderId'), true)] ?? null;
$check(is_array($row) && $row['step'] === 'ready' && array_keys($row) === ['globalOrderId', 'orderNumber', 'sourceKey', 'dueDate', 'submittedAt', 'approvedAt', 'step'], 'the handoff list: identity and next step only (no customer, assignee, call or money): ' . json_encode($row));
$check(!in_array($outlet, array_column($list['items'], 'globalOrderId'), true) && !str_contains((string) json_encode($list), substr(HO_PHONE, -6)), 'only the document scope\'s shop, no telephone number');
$check($ok($get($docOnly, '/confirmare/handoff', ['source' => 'outletperdele']), 'other shop filter')['items'] === [], 'filtering by another shop never widens the scope');
$error($generate($docOnly, $th(2)), 409, 'CONFIRMARE_NECESARA', 'the document permission never bypasses the Confirmare clearance');
$error($generate($docOnly, $outlet), 404, 'ORDER_NOT_FOUND', 'nor the shop scope');
for ($n = 1; $n <= 3; $n++) $service->distribute();
$check((int) $one('SELECT COUNT(*) FROM order_confirmations WHERE assigned_employee_uuid = ?', [$uuids['hodoconly']]) === 0, 'repeated distribution runs still assign nothing to the document employee');

// ---- 3. No production start without revision 1 ----------------------------------------------------------------------
$error($intake('hocutth', $qr($th(1))), 409, 'CONFIRMARE_DOCUMENT_NECESAR', 'the cutting scan of the intake QR cannot bypass the production document');
$error($call($waiter, 'POST', '/orders/' . rawurlencode($th(1)) . '/claim', ['expectedVersion' => (int) $order($th(1))['production_version']]), 409, 'CONFIRMARE_DOCUMENT_NECESAR', 'nor a stage-1 claim');
$check($order($th(1))['production_stage_id'] === 'waiting' && $order($th(1))['production_owner_employee_uuid'] === null, 'still at stage 1, nobody owns it');

// ---- 4. Double PDF: one revision 1, idempotent; two employees at once -----------------------------------------------
$key = 'ho-generate-' . $suffix . '-000001';
$generated = $ok($generate($docOnly, $th(1), $key), 'revision 1 by the document employee (no Confirmare scope)', 201);
$check($generated['status'] === 'active' && $generated['activeRevision']['number'] === 1 && $generated['reprintReasonRequired'] === true, 'revision 1 is active; reprints of this order need a reason');
$replayed = $ok($call($docOnly, 'POST', $docPath($th(1), 'generate'), ['expectedDocumentVersion' => 0], $key), 'double click (same key)', 201);
$check($replayed['activeRevision']['id'] === $generated['activeRevision']['id'] && $revisions($th(1)) === 1, 'the same request replays the one revision');
$error($call($docOnly, 'POST', $docPath($th(1), 'generate'), ['expectedDocumentVersion' => 0]), 409, 'DOCUMENT_CHANGED', 'a second generation from the old screen is refused');
$error($generate($docTh, $th(1)), 409, 'DOCUMENT_ALREADY_ACTIVE', 'another employee cannot create a second revision 1');
$check($revisions($th(1)) === 1 && (int) $one("SELECT COUNT(*) FROM order_qr_references q JOIN operational_orders o ON o.order_uuid = q.order_uuid WHERE o.global_order_id = ? AND q.status = 'active'", [$th(1)]) === 1, 'one revision, one active QR');
$approve($shopTh, $th(2));
$race = $parallel([
    [$shopTh, $docPath($th(2), 'generate'), ['expectedDocumentVersion' => 0], 'ho-race-a-' . $suffix . '-0001'],
    [$docTh, $docPath($th(2), 'generate'), ['expectedDocumentVersion' => 0], 'ho-race-b-' . $suffix . '-0001'],
]);
if ($race === []) {
    echo "NOT RUN parallel generation: pcntl is not available\n";
    $ok($generate($shopTh, $th(2)), 'sequential fallback', 201);
} else {
    $codes = array_map(static fn (array $r): string => $r['status'] === 201 ? 'ok' : (string) ($r['body']['error']['code'] ?? $r['status']), $race);
    sort($codes);
    $check(count(array_keys($codes, 'ok', true)) === 1 && array_values(array_diff($codes, ['ok'])) !== [] && array_diff($codes, ['ok', 'DOCUMENT_CHANGED', 'DOCUMENT_ALREADY_ACTIVE']) === [], 'two employees at once: exactly one revision 1: ' . json_encode($codes));
}
$check($revisions($th(2)) === 1, 'exactly one revision 1 after the race');

// ---- 5. Print, reprint with a mandatory reason --------------------------------------------------------------------------
$check($handoff($shopTh, $th(1))['state'] === 'print', 'generated, not printed yet');
$printed = $print($shopTh, $th(1), 1);
$check($printed['status'] === 200, 'the first print needs no reason: HTTP ' . $printed['status']);
$h = $handoff($shopTh, $th(1));
$check($h['state'] === 'awaiting_cutting' && $h['document']['prints'] === 1 && $h['actions']['print'] === true, 'printed: waiting for the cutting scan');
$error($print($shopTh, $th(1), 1), 422, 'DOCUMENT_REPRINT_REASON_REQUIRED', 'a reprint without a reason is refused');
$error($print($shopTh, $th(1), 1, '   '), 422, 'DOCUMENT_REPRINT_REASON_REQUIRED', 'a blank reason is no reason');
$check($print($docTh, $th(1), 1, 'Hârtie deteriorată la imprimantă')['status'] === 200, 'a reprint with a reason');
$check($all("SELECT print_kind, reason FROM production_document_prints p JOIN operational_orders o ON o.order_uuid = p.order_uuid WHERE o.global_order_id = ? ORDER BY print_number", [$th(1)]) === [['print_kind' => 'print', 'reason' => null], ['print_kind' => 'reprint', 'reason' => 'Hârtie deteriorată la imprimantă']], 'the reprint and its reason are recorded');
$error($sourcePrint('trendhome', $id(1), 1), 422, 'DOCUMENT_REPRINT_REASON_REQUIRED', 'the signed shop reprint needs a reason too');
$check($ok($sourcePrint('trendhome', $id(1), 1, ['reason' => 'Etichetă pierdută']), 'signed shop reprint with a reason')['document']['printNumber'] === 3, 'the shop reprint with a reason is recorded');
$check((string) $one("SELECT reason FROM production_document_prints p JOIN operational_orders o ON o.order_uuid = p.order_uuid WHERE o.global_order_id = ? AND print_number = 3", [$th(1)]) === 'Etichetă pierdută', 'the shop reason is stored with the print');
$error($sourcePrint('trendhome', $id(1), 1, ['reason' => "bad\x01"]), 400, 'INVALID_REQUEST', 'a reason with control characters is refused');

// ---- 6. Cutting QR scan: the existing 1 -> 2 transition, once ---------------------------------------------------------
$error($intake('hocutop', $qr($th(1))), 404, 'ORDER_NOT_FOUND', 'an Outlet Perdele cutter does not reach a Trend Home order');
$beforeScan = $order($th(1));
$scanKey = 'ho-scan-' . $suffix . '-00000001';
$scan1 = $scanBody('hocutth', $qr($th(1)));
$started = $ok($intake('hocutth', $qr($th(1)), $scanKey, $scan1), 'cutting scan');
$after = $order($th(1));
$check($started['outcome'] === 'started' && $after['production_stage_id'] === 'material-preparation' && $after['production_owner_employee_uuid'] === $uuids['hocutth'] && (int) $after['production_version'] === (int) $beforeScan['production_version'] + 2, 'stage 1 -> cutting, owned by the scanning cutter');
$check($events($th(1)) === ['stage_completed', 'claimed'], 'two canonical events (the existing intake)');
$check(json_encode($ok($intake('hocutth', $qr($th(1)), $scanKey, $scan1), 'lost answer retry')) === json_encode($started), 'the same scan replays its result');
$check($ok($intake('hocutth', $qr($th(1))), 'second scan')['outcome'] === 'already_mine' && $order($th(1)) === $after && count($events($th(1))) === 2, 'a second scan by the owner moves nothing');
$error($intake('hocutth2', $qr($th(1))), 409, 'ORDER_ALREADY_CLAIMED', 'another cutter cannot take it');
$check($order($th(1)) === $after, 'no wrong stage transition after repeated scans');
$check($handoff($shopTh, $th(1))['state'] === 'in_production' && $handoff($shopTh, $th(1))['blockers'] === [], 'the handoff is complete');
$check(!in_array($th(1), array_column($ok($get($shopTh, '/confirmare/handoff'), 'handoff pool after scan')['items'], 'globalOrderId'), true), 'and it left the handoff list');
$printed2 = $print($shopTh, $th(2), 1);
$check($printed2['status'] === 200, 'order 2 printed');
$scans = $parallel([
    [$cutTh, '/cutting/intake', $scanBody('hocutth', $qr($th(2))), 'ho-scan-race-a-' . $suffix],
    [$cutTh2, '/cutting/intake', $scanBody('hocutth2', $qr($th(2))), 'ho-scan-race-b-' . $suffix],
]);
if ($scans !== []) {
    $winners = array_values(array_filter($scans, static fn (array $r): bool => $r['status'] === 200));
    $check(count($winners) === 1 && $winners[0]['body']['outcome'] === 'started' && count(array_filter($scans, static fn (array $r): bool => $r['status'] === 409)) === 1, 'two cutters scan at once: one start: ' . json_encode(array_column($scans, 'status')));
} else {
    $ok($intake('hocutth2', $qr($th(2))), 'sequential fallback scan');
}
$check($order($th(2))['production_stage_id'] === 'material-preparation' && array_count_values($events($th(2))) == ['claimed' => 1, 'stage_completed' => 1], 'exactly one transition and one claim');

// ---- 7. Missing payment: approval alone never opens production ----------------------------------------------------------
$approve($shopTh, $th(3));
$h = $handoff($shopTh, $th(3));
$check($h['state'] === 'blocked' && $h['blockers'] === ['payment'] && $h['actions']['generate'] === false, 'approved, the advance not verified: blocked by payment: ' . json_encode($h));
$missing = $error($generate($shopTh, $th(3)), 409, 'CONFIRMARE_NECESARA', 'no revision 1 without the payment');
$check(($missing['error']['details']['missing'] ?? null) === ['payment'], 'the refusal names the payment');
$check(!in_array($th(3), array_column($ok($get($shopTh, '/confirmare/handoff'), 'handoff pool')['items'], 'globalOrderId'), true), 'not in the handoff list');

// ---- 8. A finance action pending in Arasya closes production at once -------------------------------------------------------
$approve($shopTh, $th(4));
$ok($generate($shopTh, $th(4)), 'order 4 revision 1', 201);
$check($print($shopTh, $th(4), 1)['status'] === 200, 'order 4 printed');
$uuid4 = $order($th(4))['order_uuid'];
$actionUuid = Uuid::v4();
$pdo->prepare("INSERT INTO order_finance_actions (action_uuid, order_uuid, global_order_id, source_key, employee_uuid, action, purpose, idempotency_key, request_hmac, revision_before, state, requested_at)
    VALUES (?, ?, ?, 'trendhome', ?, 'submit_transfer', 'additional_payment', ?, ?, ?, 'pending', UTC_TIMESTAMP(6))")
    ->execute([$actionUuid, $uuid4, $th(4), $uuids['hoshopth'], 'ho-fin-' . $suffix . '-000001', random_bytes(32), 'sha256:' . str_repeat('a', 64)]);
$check((int) $one('SELECT production_cleared FROM order_confirmations WHERE order_uuid = ?', [$uuid4]) === 1, 'fixture: the stored clearance flag is still set');
$h = $handoff($shopTh, $th(4));
$check($h['state'] === 'blocked' && in_array('finance_action', $h['blockers'], true) && in_array('payment', $h['blockers'], true), 'the pending finance action blocks the handoff: ' . json_encode($h));
$error($intake('hocutth', $qr($th(4))), 409, 'CONFIRMARE_NECESARA', 'the cutting scan is refused while the finance action is pending');
$pdo->prepare("UPDATE order_finance_actions SET state = 'unknown' WHERE action_uuid = ?")->execute([$actionUuid]);
$error($intake('hocutth', $qr($th(4))), 409, 'CONFIRMARE_NECESARA', 'and while its answer is unknown');
$pdo->prepare("UPDATE order_finance_actions SET state = 'rejected', outcome = 'refused', completed_at = UTC_TIMESTAMP(6) WHERE action_uuid = ?")->execute([$actionUuid]);
$check($handoff($shopTh, $th(4))['state'] === 'awaiting_cutting', 'once settled, the order waits for the cutting scan again');
$check($ok($intake('hocutth2', $qr($th(4))), 'scan after the finance action')['outcome'] === 'started', 'and the scan starts production');

// ---- 9. An unsettled shop change closes production -------------------------------------------------------------------------
$approve($shopTh, $th(5));
$uuid5 = $order($th(5))['order_uuid'];
$pdo->prepare("INSERT INTO order_confirmation_source_edits (edit_uuid, order_uuid, global_order_id, source_key, employee_uuid, access_role, idempotency_key, request_hash, operations_json, source_version_before, state, confirmation_version, requested_at)
    VALUES (?, ?, ?, 'trendhome', ?, 'assignee', ?, ?, '[]', ?, 'unknown', ?, UTC_TIMESTAMP(6))")
    ->execute([Uuid::v4(), $uuid5, $th(5), $uuids['hoshopth'], 'ho-edit-' . $suffix . '-000001', random_bytes(32), 'sha256:' . str_repeat('b', 64), $version($th(5))]);
$h = $handoff($shopTh, $th(5));
$check($h['state'] === 'blocked' && in_array('shop_change', $h['blockers'], true) && in_array('sync', $h['blockers'], true), 'an unsettled shop change blocks the handoff: ' . json_encode($h));
$error($generate($shopTh, $th(5)), 409, 'CONFIRMARE_NECESARA', 'no revision 1 while the shop change is unsettled');
$check(!in_array($th(5), array_column($ok($get($shopTh, '/confirmare/handoff'), 'handoff pool')['items'], 'globalOrderId'), true), 'not in the handoff list');
$check(in_array($th(5), array_column($ok($get($shopTh, '/confirmare/pool', ['view' => 'followup']), 'followup pool')['items'], 'globalOrderId'), true), 'it is followed up instead');

// ---- 10. Order revision: stale document, existing approval flow, a new review -----------------------------------------
$approve($shopTh, $th(6));
$ok($generate($shopTh, $th(6)), 'order 6 revision 1', 201);
$check($print($shopTh, $th(6), 1)['status'] === 200, 'order 6 printed');
$oldQr = $qr($th(6));
$ok(T::ingest($kernel, 'trendhome', $payload($id(6), 66, ['quantity' => 2])), 'the shop changes the quantity');
$check($order($th(6))['document_status'] === 'stale', 'the printed document is stale');
$h = $handoff($shopTh, $th(6));
$check($h['state'] === 'blocked' && in_array('sync', $h['blockers'], true) && in_array('document_blocked', $h['blockers'], true), 'the handoff names the new review and the document revision: ' . json_encode($h));
$error($intake('hocutth', $oldQr), 409, 'ORDER_BLOCKED_BY_DOCUMENT', 'the old QR cannot start the changed order');
$requested = $ok($call($shopTh, 'POST', $docPath($th(6), 'revision-requests'), ['expectedDocumentVersion' => $docVersion($th(6)), 'comment' => 'Cantitatea s-a schimbat.']), 'revision 2 requested', 201);
$requestId = (string) $requested['request']['id'];
$ok($call($approver, 'POST', "/production-documents/revision-requests/{$requestId}/decision", ['expectedVersion' => 1, 'decision' => 'approve']), 'the document approver approves revision 2');
$error($call($shopTh, 'POST', $docPath($th(6), 'generate'), ['expectedDocumentVersion' => $docVersion($th(6)), 'requestId' => $requestId]), 409, 'CONFIRMARE_NECESARA', 'revision 2 of a changed Confirmare order waits for the new review');
$check($revisions($th(6)) === 1 && $qr($th(6)) === $oldQr, 'nothing was generated or rotated');
$ok($act($supTh, $th(6), 'reopen', ['expectedVersion' => $version($th(6))]), 'the supervisor reopens the stale approval');
$approve($supTh, $th(6));
$r2 = $ok($call($shopTh, 'POST', $docPath($th(6), 'generate'), ['expectedDocumentVersion' => $docVersion($th(6)), 'requestId' => $requestId]), 'revision 2 after the new review', 201);
$check($r2['activeRevision']['number'] === 2 && $qr($th(6)) !== $oldQr, 'revision 2 with a new QR');
$check($print($shopTh, $th(6), 2)['status'] === 200, 'revision 2 printed');
$error($intake('hocutth', $oldQr), 410, 'DOCUMENT_SUPERSEDED', 'the QR of revision 1 never starts production');
$check($ok($intake('hocutth', $qr($th(6))), 'scan of revision 2')['outcome'] === 'started' && $order($th(6))['production_stage_id'] === 'material-preparation', 'the QR of revision 2 starts production');

// ---- 11. Cancellation ------------------------------------------------------------------------------------------------------
$approve($shopTh, $th(7));
$ok($generate($shopTh, $th(7)), 'order 7 revision 1', 201);
$ok(T::ingest($kernel, 'trendhome', $payload($id(7), 67, ['status' => 'cancelled', 'availability' => 'cancelled'])), 'the shop cancels order 7');
$check($handoff($shopTh, $th(7))['state'] === 'closed' && $handoff($shopTh, $th(7))['actions'] === ['generate' => false, 'print' => false], 'a cancelled order is closed for the handoff');
$error($intake('hocutth2', $qr($th(7))), 409, 'ORDER_UNAVAILABLE', 'a cancelled order never starts production');
$approve($shopTh, $th(8));
$ok(T::ingest($kernel, 'trendhome', $payload($id(8), 68, ['status' => 'cancelled', 'availability' => 'cancelled'])), 'the shop cancels order 8 before its document');
$error($generate($shopTh, $th(8)), 409, 'ORDER_UNAVAILABLE', 'no revision 1 for a cancelled order');
$check($revisions($th(8)) === 0 && $order($th(7))['production_stage_id'] === 'waiting', 'nothing started');

// ---- 12. Outlet Perdele works the same in its own scope; the legacy flow is unchanged -----------------------------------
$approve($shopOp, $outlet);
$error($generate($shopTh, $outlet), 404, 'ORDER_NOT_FOUND', 'a Trend Home employee cannot generate an Outlet Perdele document');
$ok($generate($shopOp, $outlet), 'Outlet Perdele revision 1', 201);
$check($print($shopOp, $outlet, 1)['status'] === 200, 'Outlet Perdele printed');
$error($intake('hocutth', $qr($outlet)), 404, 'ORDER_NOT_FOUND', 'a Trend Home cutter does not reach it');
$check($ok($intake('hocutop', $qr($outlet)), 'Outlet Perdele scan')['outcome'] === 'started', 'the Outlet Perdele cutter starts it');
$legacy = $th(60);
$check($ok($intake('hocutth2', $qr($legacy)), 'legacy scan without a document', 200)['outcome'] === 'started', 'a legacy order keeps the intake without a Confirmare document');
$check($error($get($shopTh, '/confirmare/orders/' . rawurlencode($legacy)), 404, 'CONFIRMARE_NOT_FOUND', 'a legacy order has no Confirmare handoff') !== [], 'legacy orders stay outside Confirmare');

// ---- 13. No telephone number in the handoff, the documents' audit or the log ----------------------------------------
$phone = static fn (string $text): bool => preg_match('/0733\s?555\s?666|733555666/', $text) === 1;
$check($phone('tel 0733 555 666') && !$phone('2026-10-22 07:33:55.566600'), 'the leak check finds the telephone number');
$orderIds = [$th(1), $th(2), $th(4), $th(6), $outlet];
$dump = json_encode([
    array_map(static fn (string $g): array => $handoff(str_starts_with($g, 'outlet') ? $shopOp : $shopTh, $g), $orderIds),
    $all('SELECT e.* FROM production_document_events e JOIN operational_orders o ON o.order_uuid = e.order_uuid WHERE o.global_order_id IN (?, ?, ?, ?, ?)', $orderIds),
    $all('SELECT p.reason, p.print_kind, p.printed_by_source_actor FROM production_document_prints p JOIN operational_orders o ON o.order_uuid = p.order_uuid WHERE o.global_order_id IN (?, ?, ?, ?, ?)', $orderIds),
    $all("SELECT action, metadata_json FROM iam_audit_events WHERE action IN ('production_document.generated', 'production_document.reprinted', 'production_document.revision_generated', 'cutting.intake_from_qr')"),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$check(!$phone($dump), 'the handoff state, the document events, prints and audit hold no telephone number');
$log = (string) file_get_contents($logFile);
$check(!$phone($log), 'the server log holds no telephone number');
@unlink($logFile);

// ---- Owner rule 1: an order whose production is stopped is never approved; the call record stays possible ------------
$ok(T::ingest($kernel, 'trendhome', $payload($id(70), 130)), 'rule 1: a Confirmare order');
$ok(T::ingest($kernel, 'trendhome', $payload($id(70), 130, ['status' => 'cancelled', 'availability' => 'cancelled'])), 'rule 1: the shop cancels it');
$ok(T::ingest($kernel, 'trendhome', $payload($id(70), 130)), 'rule 1: the shop reactivates it');
$check((string) $one('SELECT cancellation_state FROM operational_orders WHERE global_order_id = ?', [$th(70)]) === 'held', 'rule 1: production stays stopped until a release');
$service->distribute();
$review70 = $detail($shopTh, $th(70))['review'];
$ok($act($shopTh, $th(70), 'contact', ['expectedVersion' => $version($th(70)), 'outcome' => 'confirmed', 'reviewFingerprint' => $review70['fingerprint']]), 'rule 1: the call is recorded while stopped');
$status70 = (string) $one('SELECT status FROM order_confirmations WHERE global_order_id = ?', [$th(70)]);
$error($act($shopTh, $th(70), 'approve', ['expectedVersion' => $version($th(70)), 'customerCall' => 'confirmed', 'confirmedLineIds' => $review70['lineIds'], 'priceVerified' => true, 'reviewFingerprint' => $review70['fingerprint']]),
    409, 'ORDER_PRODUCTION_STOPPED', 'rule 1: the approval is refused while production is stopped');
$check((string) $one('SELECT status FROM order_confirmations WHERE global_order_id = ?', [$th(70)]) === $status70 && $revisions($th(70)) === 0, 'rule 1: the refused approval changed nothing and created no document');
$check($detail($shopTh, $th(70))['review']['fingerprint'] === $review70['fingerprint'], 'rule 1: the order stays readable');

// Leave the shared disposable database as the next suites expect it: no Confirmare or finance state.
foreach (['order_finance_actions', 'order_finance_reads', 'employee_finance_scopes', 'order_confirmation_source_edits', 'order_confirmation_source_reads', 'order_confirmation_contacts', 'order_confirmation_idempotency', 'confirmare_alerts', 'order_confirmation_events', 'order_confirmations', 'workflow_cohort_activations', 'employee_confirmare_scopes', 'confirmare_operator_state'] as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('UPDATE operational_orders SET workflow_cohort = NULL, workflow_cohort_reason = NULL, workflow_cohort_at = NULL WHERE workflow_cohort IS NOT NULL');

fwrite(STDOUT, "PASS MySQL Confirmare handoff integration: {$checks} checks (handoff state and list, document authority apart from the Confirmare distribution, both conditions, finance action, shop change, shop scopes, double PDF, concurrent employees, reprint reason, double QR scan, concurrent cutters, revision, cancellation, legacy, no telephone leak)\n");
