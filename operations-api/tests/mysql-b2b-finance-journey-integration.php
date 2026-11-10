<?php

declare(strict_types=1);

// Integrated B2B + Finance journey (API 2.67.0, Workstream 5): one synthetic legacy customer goes from a Finance import to
// a handed-over B2B order, through the real HTTP kernel on a disposable database. Steps J1-J18 follow the program list:
// import, duplicate resolution, identity verification, opening balance, account currency, owner risk and limit, B2B order,
// order type, finance eligibility, finalization, one receivable, production submission, QR and ticket, routing, Livrare,
// exposure and history, audit evidence, and untouched non-B2B work. The blocked and shadow paths run separately on a
// second company (P1-P2). The CEO exception and invalid configuration paths are in mysql-b2b-order-types-integration.php
// (M4 and M2). Synthetic people, companies, amounts and orders only.

use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Tests\OperationsTestSupport as T;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/OperationsTestSupport.php';

$db = T::requireTestDatabase();
if ($db === null) {
    echo "SKIP MySQL B2B finance journey: ARASYA_TEST_DB_NAME not configured.\n";
    exit;
}
$checks = 0;
function check(bool $value, string $message): void { global $checks; $checks++; if (!$value) throw new RuntimeException('FAIL ' . $message); }
function ok(array $r, int $status, string $message): array { check($r['status'] === $status, $message . ' got ' . $r['status'] . ' ' . json_encode($r['body'])); return $r['body'] ?? []; }
function err(array $r, int $status, string $code, string $message): array { ok($r, $status, $message); check(($r['body']['error']['code'] ?? null) === $code, $message . ' code ' . json_encode($r['body'])); return $r['body']; }
function cui(string $base): string { $body = str_pad($base, 9, '0', STR_PAD_LEFT); $key = '753217532'; $sum = 0; for ($i = 0; $i < 9; $i++) { $sum += (int) $body[$i] * (int) $key[$i]; } $c = ($sum * 10) % 11; return $base . ($c === 10 ? 0 : $c); }
/** Decimal string to cents, for exact money comparisons in the test only. */
function cents(string $amount): int { [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0'); $sign = str_starts_with($whole, '-') ? -1 : 1; return $sign * (abs((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0')); }

$db = substr($db, 0, 40) . '_journey';
$server = new PDO('mysql:host=' . (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('ARASYA_TEST_DB_PORT') ?: '3306'), (string) getenv('ARASYA_TEST_DB_USER'), (string) getenv('ARASYA_TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `{$db}`");
$server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$config = T::config($db);
$pdo = Connection::create($config);
(new MigrationRunner($pdo))->migrate(dirname(__DIR__) . '/database/migrations');
foreach (glob(dirname(__DIR__) . '/database/seeds/*.sql') as $file) (new SqlFileRunner($pdo))->run($file);
$rows = static function (string $sql, array $params = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); };
$scalar = static function (string $sql, array $params = []) use ($rows): mixed { $r = $rows($sql, $params); return $r === [] ? null : array_values($r[0])[0]; };

// ---- People -----------------------------------------------------------------------------------------------------------
$base = new Container($config, $pdo);
$kernel = $base->kernel();
$suffix = bin2hex(random_bytes(4));
$pdo->exec('DELETE FROM system_root_identity');
$temporary = (new RootBootstrapService($pdo, $base->passwordHasher(), $base->clock()))->bootstrap('journey-test', 'journey.root.' . $suffix);
$first = T::login($kernel, 'journey.root.' . $suffix, $temporary);
ok(T::call($kernel, 'POST', '/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Journey root passphrase 2026!'], ['x-csrf-token' => $first['csrf']], $first['cookie']), 200, 'root password');
$root = T::login($kernel, 'journey.root.' . $suffix, 'Journey root passphrase 2026!');
$call = static fn (array $who, string $method, string $path, ?array $json = null, array $query = [], ?string $key = null, ?\Arasya\Operations\Http\ApiKernel $k = null): array
    => T::call($k ?? $kernel, $method, $path, $json, $method === 'GET' ? [] : ['x-csrf-token' => $who['csrf'], 'idempotency-key' => $key ?? ('journey-' . bin2hex(random_bytes(10)))], $who['cookie'], $query);
$password = 'journey test passphrase 2026';
$admin = $base->employeeAdmin();
$worker = static function (string $name, array $stages) use ($admin, $kernel, $password, $suffix): array {
    $employee = $admin->create("Synthetic {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, $stages, 'journey-test');
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['id' => $employee->employeeUuid];
};
$person = static function (string $name, array $permissions) use ($admin, $call, $root, $password, $suffix, $kernel): array {
    $employee = $admin->create("Synthetic {$name}", "{$name}.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'journey-test');
    ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/applications", ['applications' => ['finance']]), 200, "{$name} applications");
    $roleId = (int) ok($call($root, 'POST', '/management/roles', ['name' => "{$name} {$suffix}", 'description' => null, 'authorityRank' => 200, 'permissions' => $permissions]), 201, "role {$name}")['role']['id'];
    ok($call($root, 'PUT', "/management/employees/{$employee->employeeUuid}/roles", ['roleIds' => [$roleId]]), 200, "{$name} roles");
    return T::login($kernel, "{$name}.{$suffix}", $password) + ['id' => $employee->employeeUuid];
};
$intake = $worker('intake', ['waiting']);
$cutter = $worker('cutter', ['material-preparation']);
$livrare = $worker('livrare', ['delivery']);
$importer = $person('importer', ['finance.companies.view', 'finance.companies.import']);
$reviewer = $person('reviewer', ['finance.companies.view', 'finance.companies.review', 'finance.companies.resolve_duplicates']);
$verifier = $person('verifier', ['finance.companies.view', 'finance.companies.verify']);
$acc1 = $person('accone', ['finance.accounts.view', 'finance.accounts.reconcile', 'finance.accounts.propose_opening_balance']);
$acc2 = $person('acctwo', ['finance.accounts.view', 'finance.accounts.reconcile', 'finance.accounts.verify_opening_balance', 'finance.accounts.post_approved_opening_balance']);
$owner = $person('owner', ['finance.risk.view', 'finance.risk.approve', 'finance.credit.view', 'finance.credit.approve', 'finance.credit.revoke']);
$auditor = $person('auditor', ['finance.audit.view', 'finance.companies.view']);

// Kernels on the same database: the gate modes and opening-balance posting are server configuration only.
$args = [];
foreach ((new ReflectionClass(Config::class))->getConstructor()->getParameters() as $parameter) $args[$parameter->getName()] = $config->{$parameter->getName()};
$kernelWith = static fn (array $over): \Arasya\Operations\Http\ApiKernel => (new Container(new Config(...array_replace($args, ['financeRiskLimitAuthorityUuids' => $owner['id'], 'financeOpeningBalancePosting' => 'enabled'], $over)), $pdo))->kernel();
$enforce = $kernelWith(['b2bFinanceGate' => 'enforce']);
$shadow = $kernelWith(['b2bFinanceGate' => 'shadow']);

// ---- Baseline of work that is not B2B: a TrendHome order and the AWB station state ---------------------------------
$number = (string) random_int(92000000, 92999999);
check((T::ingest($kernel, 'trendhome', T::sourceOrder($number, "journey-{$suffix}", gmdate('Y-m-d\TH:i:s\Z', time() - 120), T::stage('material-preparation')))['body']['outcome'] ?? null) === 'applied', 'baseline TrendHome order');
$nonB2b = static function () use ($pdo): string {
    $out = [];
    foreach (["SELECT * FROM operational_orders WHERE source_key <> 'b2b'", "SELECT e.* FROM order_activity_events e JOIN operational_orders o ON o.order_uuid = e.order_uuid WHERE o.source_key <> 'b2b'",
        'SELECT * FROM trendyol_awb_print_events', "SELECT rp.* FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.permission_key LIKE 'trendyol.%'",
        "SELECT * FROM schema_migrations WHERE migration_name = '051_trendyol_awb_station.sql'"] as $sql) {
        $data = array_map(serialize(...), $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
        sort($data, SORT_STRING);
        $out[] = hash('sha256', implode("\n", $data));
    }
    return implode('|', $out);
};
$before = $nonB2b();

// ---- J1. Import a synthetic legacy customer ---------------------------------------------------------------------------
$legacyCui = cui((string) (6100000 + random_int(1, 99999)));
$existing = ok($call($root, 'POST', '/b2b/companies', ['legalName' => 'Journey Legacy SRL', 'countryCode' => 'RO', 'taxIdentifier' => 'RO' . $legacyCui,
    'address' => ['type' => 'delivery', 'countryCode' => 'RO', 'city' => 'Brasov', 'addressLine1' => 'Str. Journey 1', 'isPrimary' => true]]), 201, 'J1. the B2B customer already exists')['companyId'];
$csv = "Denumire;CUI;Tara;Adresa;Localitate;Cod client\n"
    . "Journey Legacy SRL;RO {$legacyCui};Romania;Str. Journey 1;Brasov;L001\n"
    . "JOURNEY LEGACY S.R.L.;{$legacyCui};RO;Str. Journey 1;Brasov;L002\n";
$batch = ok(T::call($kernel, 'POST', '/finance/imports', null, ['x-csrf-token' => $importer['csrf'], 'idempotency-key' => 'journey-up-' . $suffix, 'content-type' => 'text/csv'],
    $importer['cookie'], ['source' => 'SAGA_EXPORT', 'name' => 'journey legacy.csv'], $csv), 201, 'J1. legacy export uploaded')['batchId'];
$detail = ok($call($importer, 'GET', "/finance/imports/{$batch}"), 200, 'J1. import detail');
ok($call($importer, 'POST', "/finance/imports/{$batch}/mapping", ['expectedVersion' => 1, 'mapping' => (array) $detail['suggestedMapping']]), 200, 'J1. mapping');
$importVersion = 0;
for ($i = 0; $i < 50; $i++) {
    $r = ok($call($importer, 'POST', "/finance/imports/{$batch}/analyze", null), 200, 'J1. analysis chunk');
    if ($r['done']) { $importVersion = $r['version']; break; }
}
$preview = ok($call($importer, 'GET', "/finance/imports/{$batch}/rows"), 200, 'J1. dry-run preview')['items'];
check(array_column($preview, 'outcome') === ['ready', 'duplicate_in_file'] && $preview[0]['duplicateStrength'] === 'exact', 'J1. the legacy row matches the B2B customer; the repeated row is a duplicate in the file');
check((int) $scalar('SELECT COUNT(*) FROM finance_company_cases') === 0, 'J1. the analysis stages nothing');
check(ok($call($importer, 'POST', "/finance/imports/{$batch}/confirm", ['expectedVersion' => $importVersion, 'confirm' => true]), 200, 'J1. confirm')['staged'] === 1, 'J1. one case staged');
$caseId = (string) $scalar('SELECT c.case_uuid FROM finance_company_cases c JOIN finance_import_rows r ON r.row_uuid = c.import_row_uuid WHERE r.batch_uuid = ? AND r.line_no = 1', [$batch]);

// ---- J2. Resolve the duplicate candidate ------------------------------------------------------------------------------
$case = ok($call($reviewer, 'GET', "/finance/cases/{$caseId}"), 200, 'J2. case detail');
$candidate = $case['duplicates'][0];
check($candidate['strength'] === 'exact' && $candidate['match']['id'] === $existing, 'J2. exact candidate is the existing B2B company');
$v = ok($call($reviewer, 'POST', "/finance/cases/{$caseId}/claim", ['expectedVersion' => $case['case']['version']]), 200, 'J2. claim')['version'];
err($call($reviewer, 'POST', "/finance/cases/{$caseId}/duplicates/{$candidate['id']}/resolve", ['expectedVersion' => $v, 'decision' => 'dismissed', 'reason' => 'looks different']), 409, 'DECISION_NOT_ALLOWED', 'J2. an exact match cannot be dismissed');
$v = ok($call($reviewer, 'POST', "/finance/cases/{$caseId}/duplicates/{$candidate['id']}/resolve", ['expectedVersion' => $v, 'decision' => 'linked', 'reason' => 'Same CUI as the B2B customer']), 200, 'J2. linked')['version'];

// ---- J3. Verify the legal identity (two people) -----------------------------------------------------------------------
$evidence = [['kind' => 'registry_extract', 'sourceSystem' => 'REGISTRY', 'reference' => 'Synthetic registry extract journey', 'documentDate' => '2026-11-01']];
foreach (['legal_name', 'country', 'tax_id', 'duplicate_search', 'address', 'contact', 'sources', 'documents', 'conflicts'] as $item) {
    $v = ok($call($reviewer, 'POST', "/finance/cases/{$caseId}/checklist", ['expectedVersion' => $v, 'item' => $item, 'state' => 'done'] + ($item === 'tax_id' ? ['evidence' => $evidence] : [])), 200, "J3. checklist {$item}")['version'];
}
$v = ok($call($reviewer, 'POST', "/finance/cases/{$caseId}/submit", ['expectedVersion' => $v]), 200, 'J3. submitted')['version'];
$approved = ok($call($verifier, 'POST', "/finance/cases/{$caseId}/approve", ['expectedVersion' => $v]), 200, 'J3. second person approves');
check($approved['canonical'] === 'linked' && $approved['companyId'] === $existing, 'J3. linked to the existing company, never a second one');
check((int) $scalar('SELECT COUNT(*) FROM b2b_companies WHERE tax_identifier_normalized = ?', [$legacyCui]) === 1 && (int) $scalar('SELECT version FROM b2b_companies WHERE company_uuid = ?', [$existing]) === 1, 'J3. the B2B company is not edited');

// ---- J4 / J5. Opening balance and account currency --------------------------------------------------------------------
$opened = ok($call($acc1, 'POST', '/finance/accounts', ['companyId' => $existing]), 201, 'J4. account opened');
check($opened['accountCurrency'] === 'RON', 'J5. the account currency is RON');
$account = $opened['onboardingId'];
$yesterday = gmdate('Y-m-d', time() - 86400);
$v = ok($call($acc1, 'POST', "/finance/accounts/{$account}/draft", ['expectedVersion' => 1, 'balanceKind' => 'DEBIT', 'amount' => '150.00', 'asOfDate' => $yesterday, 'note' => 'Synthetic opening balance',
    'evidence' => [['kind' => 'correspondence', 'sourceSystem' => 'MANUAL', 'reference' => 'Synthetic balance confirmation journey']]]), 200, 'J4. opening draft')['version'];
$v = ok($call($acc1, 'POST', "/finance/accounts/{$account}/submit", ['expectedVersion' => $v]), 200, 'J4. submitted')['version'];
$v = ok($call($acc2, 'POST', "/finance/accounts/{$account}/verify", ['expectedVersion' => $v]), 200, 'J4. second person verifies')['version'];
err($call($acc2, 'POST', "/finance/accounts/{$account}/post", ['expectedVersion' => $v]), 409, 'FINANCE_POSTING_DISABLED', 'J4. posting stays off without the server switch');
$postKey = 'journey-post-' . $suffix;
$posted = ok($call($acc2, 'POST', "/finance/accounts/{$account}/post", ['expectedVersion' => $v], [], $postKey, $enforce), 200, 'J4. posted with the switch on');
ok($call($acc2, 'POST', "/finance/accounts/{$account}/post", ['expectedVersion' => $v], [], $postKey, $enforce), 200, 'J4. post replay');
check((int) $scalar("SELECT COUNT(*) FROM b2b_account_movements WHERE company_uuid = ? AND movement_type = 'opening_balance'", [$existing]) === 1
    && $scalar('SELECT amount FROM b2b_account_movements WHERE movement_uuid = ?', [$posted['movementId']]) === '150.00', 'J4. exactly one opening balance of 150.00 RON');
check($scalar('SELECT status FROM finance_account_onboardings WHERE onboarding_uuid = ?', [$account]) === 'RECONCILED', 'J4. the account is reconciled');

// ---- J6. Owner risk colour and purchase limit -------------------------------------------------------------------------
$state = static fn (string $company): string => ok($call($owner, 'GET', "/finance/owner/companies/{$company}", null, [], null, $enforce), 200, 'owner detail')['state'];
ok($call($owner, 'POST', "/finance/owner/companies/{$existing}/risk", ['expectedState' => $state($existing), 'classification' => 'GREEN', 'effect' => 'EFFECTIVE', 'reason' => 'Synthetic owner GREEN'], [], null, $enforce), 201, 'J6. owner GREEN');
ok($call($owner, 'POST', "/finance/owner/companies/{$existing}/credit", ['expectedState' => $state($existing), 'action' => 'SET', 'amount' => '500.00', 'currency' => 'RON', 'effect' => 'EFFECTIVE', 'reason' => 'Synthetic owner limit'], [], null, $enforce), 201, 'J6. owner limit 500.00 RON');
$profile = ok($call($owner, 'GET', "/finance/companies/{$existing}/credit-profile", null, [], null, $enforce), 200, 'J6. credit profile');
check($profile['creditPurchaseEligibility'] === 'ELIGIBLE' && cents((string) $profile['currentExposure']) === 15000 && cents((string) $profile['availableCredit']) === 35000, 'J6. eligible; exposure is the opening balance');

// ---- J7 / J8. B2B order with an explicit type -------------------------------------------------------------------------
$line = ['productCode' => 'JRN-1', 'productName' => 'Synthetic fabric', 'variant' => null, 'color' => 'Alb', 'kind' => 'curtain', 'width' => '200', 'height' => '260', 'quantity' => 1,
    'meters' => '6.5', 'pricingUnit' => 'meter', 'unitPriceNet' => '10.00', 'discountPercent' => '0', 'vatPercent' => '19', 'notes' => null, 'productionNotes' => null];
$order = ok($call($root, 'POST', '/b2b/orders', ['companyId' => $existing, 'currencyCode' => 'RON', 'orderType' => 'CUT_ONLY', 'lines' => [$line]], [], null, $enforce), 201, 'J7. draft')['detail']['order'];
check($order['status'] === 'draft' && $order['orderType'] === 'CUT_ONLY', 'J8. CUT_ONLY chosen on the draft');

// ---- J9. Finance eligibility (advisory preview; the binding decision is taken at the action) ------------------------
$read = static fn (string $id, \Arasya\Operations\Http\ApiKernel $k): array => ok($call($root, 'GET', "/b2b/orders/{$id}", null, [], null, $k), 200, 'order read')['order'];
check($read($order['id'], $enforce)['financeGate'] === ['configured' => true, 'finalizationMode' => 'enforce', 'releaseMode' => 'enforce', 'preview' => ['phase' => 'finalization', 'mode' => 'enforce', 'outcome' => 'ALLOWED', 'reasons' => []]], 'J9. the preview allows the order');

// ---- J10 / J11. Finalize once, one receivable --------------------------------------------------------------------------
$finalKey = 'journey-finalize-' . $suffix;
$final = ok($call($root, 'POST', "/b2b/orders/{$order['id']}/finalize", ['expectedVersion' => $order['version']], [], $finalKey, $enforce), 200, 'J10. finalized')['detail']['order'];
check($final['status'] === 'finalized' && $final['financeDecision']['outcome'] === 'ALLOWED' && $final['financeDecision']['mode'] === 'enforce', 'J10. finalized with an evaluated decision');
ok($call($root, 'POST', "/b2b/orders/{$order['id']}/finalize", ['expectedVersion' => $order['version']], [], $finalKey, $enforce), 200, 'J11. lost response replayed');
err($call($root, 'POST', "/b2b/orders/{$order['id']}/finalize", ['expectedVersion' => $order['version']], [], null, $enforce), 409, 'ORDER_CHANGED', 'J11. a double click with a new key is refused');
err($call($root, 'POST', "/b2b/orders/{$order['id']}/finalize", ['expectedVersion' => $final['version']], [], null, $enforce), 409, 'ORDER_FINALIZED', 'J11. finalizing a finalized order is refused');
$gross = (string) $final['calculation']['totals']['gross'];
$receivables = $rows("SELECT amount, currency_code FROM b2b_account_movements WHERE order_uuid = ? AND movement_type = 'order_receivable'", [$order['id']]);
check(count($receivables) === 1 && $receivables[0]['amount'] === $gross && $receivables[0]['currency_code'] === 'RON', 'J11. exactly one receivable of the order gross');

// ---- J12. Submit to canonical production ------------------------------------------------------------------------------
$submitKey = 'journey-submit-' . $suffix;
$progress = ok($call($root, 'POST', "/b2b/orders/{$order['id']}/production", ['expectedVersion' => $final['version']], [], $submitKey, $enforce), 201, 'J12. submitted')['production'];
$global = $progress['operationalOrderId'];
check($global === 'b2b:' . $order['id'], 'J12. one canonical identity');
ok($call($root, 'POST', "/b2b/orders/{$order['id']}/production", ['expectedVersion' => $final['version']], [], $submitKey, $enforce), 201, 'J12. submission replayed');
$handoff = $rows('SELECT * FROM b2b_production_handoffs WHERE b2b_order_uuid = ?', [$order['id']]);
$release = json_decode((string) $handoff[0]['finance_decision'], true);
check(count($handoff) === 1 && $release['phase'] === 'production_submission' && $release['outcome'] === 'ALLOWED' && $release['grandfathered'] === false && $release['changedSinceFinalization'] === [], 'J12. one handoff with the release decision');
$orderUuid = (string) $scalar('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?', [$global]);
check($scalar('SELECT service_route FROM operational_orders WHERE order_uuid = ?', [$orderUuid]) === 'CUT_ONLY', 'J12. the canonical order carries the frozen type');

// ---- J13. QR and production ticket ------------------------------------------------------------------------------------
$qr = $rows("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active'", [$orderUuid]);
$ticket = $rows("SELECT revision_number, qr_reference FROM production_document_revisions WHERE order_uuid = ? AND status = 'active'", [$orderUuid]);
check(count($qr) === 1 && count($ticket) === 1 && (int) $ticket[0]['revision_number'] === 1 && $ticket[0]['qr_reference'] === $qr[0]['qr_reference'], 'J13. one active QR and ticket revision 1 that carries it');
$payload = $base->projectionWriter()->qrPayloadFor($global);
check(ok($call($intake, 'POST', '/orders/resolve-qr', ['token' => $payload]), 200, 'J13. Staff resolves the QR')['id'] === $global, 'J13. the QR resolves the same order');

// ---- J14. Route: 1 -> 2 -> 14 ----------------------------------------------------------------------------------------
$version = static fn (): int => (int) $scalar('SELECT production_version FROM operational_orders WHERE global_order_id = ?', [$global]);
$claim = static fn (array $who): array => $call($who, 'POST', "/orders/{$global}/claim", T::cuttingClaim($pdo, $global, $who['id'], $version()));
$complete = static fn (array $who): array => $call($who, 'POST', "/orders/{$global}/transition", ['expectedVersion' => $version()]);
ok($claim($intake), 200, 'J14. stage 1 claim');
check(ok($complete($intake), 200, 'J14. stage 1 done')['productionStageId'] === 'material-preparation', 'J14. stage 1 leads to stage 2');
err($claim($livrare), 404, 'ORDER_NOT_FOUND', 'J14. Livrare cannot take it before stage 2');
ok($claim($cutter), 200, 'J14. stage 2 claim');
check(ok($complete($cutter), 200, 'J14. stage 2 done')['productionStageId'] === 'delivery', 'J14. stage 2 leads to Livrare');
check(count($rows("SELECT 1 FROM production_route_decisions WHERE order_uuid = ? AND reason = 'order_service_route' AND kind = 'stage_not_applicable'", [$orderUuid])) === 1, 'J14. stages 3-13 recorded as not applicable');

// ---- J15. Livrare completes stage 14 ----------------------------------------------------------------------------------
err($claim($cutter), 403, 'UNAUTHORIZED_ACTION', 'J15. the stage 2 employee has no Livrare authority');
ok($claim($livrare), 200, 'J15. Livrare receives');
check(isset(ok($complete($livrare), 200, 'J15. Livrare hands over')['productionCompletedAt']), 'J15. handed over');
$production = ok($call($root, 'GET', "/b2b/orders/{$order['id']}/production"), 200, 'J15. B2B production')['production'];
check($production['delivery'] === 'HANDED_OVER', 'J15. B2B shows the handover');

// ---- J16. Finance exposure and B2B history ----------------------------------------------------------------------------
$profile = ok($call($owner, 'GET', "/finance/companies/{$existing}/credit-profile", null, [], null, $enforce), 200, 'J16. credit profile');
check(cents((string) $profile['currentExposure']) === 15000 + cents($gross) && cents((string) $profile['availableCredit']) === 50000 - 15000 - cents($gross), 'J16. exposure = opening balance + order receivable; available = limit - exposure');
check((int) $scalar("SELECT COUNT(*) FROM b2b_account_movements WHERE company_uuid = ? AND movement_type NOT IN ('opening_balance', 'order_receivable')", [$existing]) === 0, 'J16. production and delivery post nothing to the ledger');
$history = array_column(ok($call($root, 'GET', "/b2b/orders/{$order['id']}/activity"), 200, 'J16. B2B activity')['items'], 'action');
check(in_array('order_created', $history, true) && in_array('order_finalized', $history, true), 'J16. B2B commercial history: created and finalized ' . json_encode($history));
check(array_column($rows('SELECT action FROM order_activity_events WHERE order_uuid = ? ORDER BY production_version_after, occurred_at', [$orderUuid]), 'action')[0] === 'production_submitted', 'J16. the factory history starts with the production submission');

// ---- J17. Immutable audit evidence ------------------------------------------------------------------------------------
$journal = $rows('SELECT phase, mode, outcome FROM b2b_finance_gate_decisions WHERE order_uuid = ? ORDER BY decided_at', [$order['id']]);
check($journal === [['phase' => 'finalization', 'mode' => 'enforce', 'outcome' => 'ALLOWED'], ['phase' => 'production_submission', 'mode' => 'enforce', 'outcome' => 'ALLOWED']], 'J17. the gate journal holds one decision per phase, replays added none');
$audit = ok($call($auditor, 'GET', '/finance/audit'), 200, 'J17. Finance audit')['items'];
$entities = array_unique(array_column(array_filter($audit, static fn (array $e): bool => $e['companyCode'] !== null && $e['companyName'] === 'Journey Legacy SRL'), 'entity'));
sort($entities);
check(array_values(array_intersect(['case', 'credit', 'onboarding', 'risk'], $entities)) === ['case', 'credit', 'onboarding', 'risk'], 'J17. case, account, risk and limit decisions are in the Finance audit ' . json_encode($entities));
check(in_array('production_completed', array_column($rows('SELECT action FROM order_activity_events WHERE order_uuid = ?', [$orderUuid]), 'action'), true), 'J17. the factory history records the handover');
$journalRead = ok($call($owner, 'GET', '/finance/b2b/gate-decisions', null, ['phase' => 'production_submission'], null, $enforce), 200, 'J17. journal read by Finance');
check(in_array($order['id'], array_column($journalRead['items'], 'orderId'), true), 'J17. Finance reads the release decision');

// ---- J18. Non-B2B work and the AWB station are unchanged --------------------------------------------------------------
check($nonB2b() === $before, 'J18. TrendHome order, its history, AWB print events, Trendyol grants and the 051 record are unchanged');
check($scalar('SELECT service_route FROM operational_orders WHERE global_order_id = ?', ['trendhome:' . $number]) === null, 'J18. the TrendHome order has no service route');

// ---- P1. Blocked path: an unverified company under enforce --------------------------------------------------------------
$blockedCompany = ok($call($root, 'POST', '/b2b/companies', ['legalName' => 'Journey Unverified SRL', 'countryCode' => 'RO', 'taxIdentifier' => 'JRNBLK' . $suffix,
    'address' => ['type' => 'delivery', 'countryCode' => 'RO', 'city' => 'Iasi', 'addressLine1' => 'Str. Blocked 2', 'isPrimary' => true]]), 201, 'P1. unverified company')['companyId'];
$blocked = ok($call($root, 'POST', '/b2b/orders', ['companyId' => $blockedCompany, 'currencyCode' => 'RON', 'orderType' => 'FULL_ROLL_BAG', 'lines' => [$line]], [], null, $enforce), 201, 'P1. draft')['detail']['order'];
$refusal = err($call($root, 'POST', "/b2b/orders/{$blocked['id']}/finalize", ['expectedVersion' => $blocked['version']], [], null, $enforce), 409, 'FINANCE_NOT_ELIGIBLE', 'P1. finalization refused');
check(in_array('IDENTITY_NOT_VERIFIED', $refusal['error']['details']['reasons'], true) && !str_contains(json_encode($refusal), '500.00'), 'P1. reasons named, no amount');
check($read($blocked['id'], $enforce)['status'] === 'draft' && (int) $scalar("SELECT COUNT(*) FROM b2b_account_movements WHERE order_uuid = ?", [$blocked['id']]) === 0, 'P1. still a draft, no receivable');
check($rows('SELECT outcome FROM b2b_finance_gate_decisions WHERE order_uuid = ?', [$blocked['id']]) === [['outcome' => 'BLOCKED']], 'P1. the refusal is journaled');

// ---- P2. Shadow path: the same order goes on, the refusal is only recorded; enforce then stops the release ----------------
$shadowed = ok($call($root, 'POST', "/b2b/orders/{$blocked['id']}/finalize", ['expectedVersion' => $blocked['version']], [], null, $shadow), 200, 'P2. finalized under shadow')['detail']['order'];
check($shadowed['financeDecision']['outcome'] === 'WOULD_BLOCK' && (int) $scalar("SELECT COUNT(*) FROM b2b_account_movements WHERE order_uuid = ? AND movement_type = 'order_receivable'", [$blocked['id']]) === 1, 'P2. WOULD_BLOCK recorded, one receivable');
err($call($root, 'POST', "/b2b/orders/{$blocked['id']}/production", ['expectedVersion' => $shadowed['version']], [], null, $enforce), 409, 'FINANCE_RELEASE_BLOCKED', 'P2. enforce stops the release');
check($rows('SELECT 1 FROM b2b_production_handoffs WHERE b2b_order_uuid = ?', [$blocked['id']]) === [] && $rows("SELECT 1 FROM operational_orders WHERE global_order_id = ?", ['b2b:' . $blocked['id']]) === [], 'P2. no handoff, no canonical order, no QR');
ok($call($root, 'POST', "/b2b/orders/{$blocked['id']}/production", ['expectedVersion' => $shadowed['version']], [], null, $shadow), 201, 'P2. shadow lets the release go on');
check(array_column($rows('SELECT phase, outcome FROM b2b_finance_gate_decisions WHERE order_uuid = ? ORDER BY decided_at', [$blocked['id']]), 'outcome') === ['BLOCKED', 'WOULD_BLOCK', 'BLOCKED', 'WOULD_BLOCK'], 'P2. every decision of the order is journaled in order');
check($nonB2b() === $before, 'P2. non-B2B work still unchanged');

// ---- F. Finance origin, central session and default-deny IAM (finance.arasyahome.ro readiness) --------------------------
// The Finance frontend has no login: it uses the central session cookie of api.arasyahome.ro and calls the API from its
// exact origin. Here the origin list holds the Finance origin (the test loopback stands in for https://finance.arasyahome.ro).
$financeOrigin = 'http://127.0.0.1:5175';
$financeKernel = (new Container(T::config($db, [T::ORIGIN, $financeOrigin]), $pdo))->kernel();
$fromFinance = static fn (string $method, string $path, ?array $who = null, array $headers = []): array
    => T::call($financeKernel, $method, $path, $method === 'GET' ? null : [], ['origin' => $financeOrigin] + $headers, $who['cookie'] ?? null);
$preflight = T::call($financeKernel, 'OPTIONS', '/finance/cases', null, ['origin' => $financeOrigin, 'access-control-request-method' => 'POST', 'access-control-request-headers' => 'content-type,x-csrf-token,idempotency-key']);
check($preflight['status'] === 204 && ($preflight['headers']['Access-Control-Allow-Origin'] ?? '') === $financeOrigin && ($preflight['headers']['Access-Control-Allow-Credentials'] ?? '') === 'true', 'F1. the exact Finance origin passes the credentialed preflight');
check(T::call($kernel, 'OPTIONS', '/finance/cases', null, ['origin' => $financeOrigin, 'access-control-request-method' => 'POST'])['status'] === 403, 'F1. without the Finance origin in the list the preflight is refused');
err(T::call($financeKernel, 'OPTIONS', '/finance/cases', null, ['origin' => $financeOrigin . '.evil.example', 'access-control-request-method' => 'POST']), 403, 'ORIGIN_DENIED', 'F1. a look-alike origin is refused');
$gate = $fromFinance('GET', '/finance/access', $importer);
check($gate['status'] === 200 && ($gate['headers']['Access-Control-Allow-Origin'] ?? '') === $financeOrigin, 'F2. the central session opens Finance from the Finance origin');
check($gate['body']['capabilities']['canImportCompanies'] === true && $gate['body']['capabilities']['canVerifyCompanies'] === false && $gate['body']['capabilities']['canViewCredit'] === false, 'F2. capabilities follow the role exactly');
$guest = $fromFinance('GET', '/finance/access');
check($guest['status'] === 401 && ($guest['headers']['Access-Control-Allow-Origin'] ?? '') === $financeOrigin, 'F3. without a session the gate answers 401, readable by the Finance origin');
err($fromFinance('POST', '/finance/cases', $importer, ['x-csrf-token' => 'not-the-token', 'idempotency-key' => 'journey-f-csrf-' . $suffix]), 403, 'CSRF_INVALID', 'F4. a Finance command without the in-memory CSRF token is refused');
err(T::call($financeKernel, 'POST', '/finance/cases', [], ['origin' => 'https://evil.example', 'x-csrf-token' => $importer['csrf'], 'idempotency-key' => 'journey-f-origin-' . $suffix], $importer['cookie']), 403, 'ORIGIN_DENIED', 'F4. a Finance command from another origin is refused');
err($fromFinance('GET', '/finance/access', $intake), 403, 'APPLICATION_ACCESS_DENIED', 'F5. a Staff employee without the Finance application is refused');
check($fromFinance('GET', '/finance/access', ['cookie' => 'forged-session-token'])['status'] === 401, 'F5. a forged cookie is no session');
// Finance application alone: the gate opens, every capability is false, every read is refused (default deny).
$bare = $admin->create('Synthetic bare finance', "barefinance.{$suffix}", null, 'pregatire-material', 'employee', $password, [], 'journey-test');
ok($call($root, 'PUT', "/management/employees/{$bare->employeeUuid}/applications", ['applications' => ['finance']]), 200, 'F6. Finance application only');
$bareSession = T::login($kernel, "barefinance.{$suffix}", $password);
$bareGate = ok($fromFinance('GET', '/finance/access', $bareSession), 200, 'F6. gate with the application only');
check(array_filter($bareGate['capabilities']) === [], 'F6. the application grant alone gives no Finance capability');
err($fromFinance('GET', '/finance/companies', $bareSession), 403, 'UNAUTHORIZED_ACTION', 'F6. no company read without finance.companies.view');
// Revocation applies on the next request, without a new login, and leaves the central session for other applications.
ok($call($root, 'PUT', "/management/employees/{$bare->employeeUuid}/applications", ['applications' => ['staff']]), 200, 'F7. Finance application removed');
err($fromFinance('GET', '/finance/access', $bareSession), 403, 'APPLICATION_ACCESS_DENIED', 'F7. the removed grant stops the next Finance request');
// Session cookie: host-only on the API (no Domain), HttpOnly, SameSite=Lax. finance.arasyahome.ro and api.arasyahome.ro are
// the same site, so the browser sends it on credentialed fetches; Secure is added in production (CookiePolicy).
$login = T::call($financeKernel, 'POST', '/auth/login', ['username' => "barefinance.{$suffix}", 'password' => $password], ['origin' => $financeOrigin]);
$cookieHeader = (string) ($login['headers']['Set-Cookie'] ?? '');
check($login['status'] === 200 && str_contains($cookieHeader, 'HttpOnly') && str_contains($cookieHeader, 'SameSite=Lax') && str_contains($cookieHeader, 'Path=/') && stripos($cookieHeader, 'Domain=') === false, 'F8. the session cookie is HttpOnly, SameSite=Lax and host-only');
check(!str_contains((string) json_encode($login['body']), 'arasya_session') && is_string($login['body']['csrfToken'] ?? null), 'F8. the token stays in the cookie; the CSRF token is returned for memory only');

$server->exec("DROP DATABASE IF EXISTS `{$db}`");
echo "PASS MySQL B2B finance journey: {$checks} checks (legacy import, duplicate link, two-person identity verification, opening balance posted once, account currency, owner colour and limit, typed order, eligibility preview, one finalization and one receivable, production submission with release decision, QR and ticket, 1 -> 2 -> 14, Livrare handover, exposure and history, audit evidence, non-B2B and AWB unchanged, blocked and shadow paths, Finance origin, session and default-deny IAM).\n";
