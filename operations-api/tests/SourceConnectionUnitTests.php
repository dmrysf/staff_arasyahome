<?php

declare(strict_types=1);

// Signed source connection foundation (no database). Included by run.php.

use Arasya\Operations\Config\Config;
use Arasya\Operations\Config\ConfigLoader;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Integration\SourceAuthorityQueries;
use Arasya\Operations\Integration\SourceIngestionController;
use Arasya\Operations\Integration\SourceMode;
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Integration\SourceSignatureVerifier;
use Arasya\Operations\Order\OrderProjectionWriter;
use Arasya\Operations\Production\CanonicalProductionWorkflowContract;
use Arasya\Operations\Production\ProductionAuthorityMode;
use Arasya\Operations\Production\ProductionAuthorityModes;
use Arasya\Operations\Production\QrAuthorityMode;
use Arasya\Operations\Security\ApiRateLimiter;
use Arasya\Operations\Tests\MutableClock;

const SOURCE_TEST_NOW = 1_790_000_000;
const SOURCE_TEST_TRENDHOME_SECRET = 'trendhome-unit-secret-000000000000000000000000';
const SOURCE_TEST_OUTLET_SECRET = 'outletperdele-unit-secret-11111111111111111111';
const SOURCE_TEST_FUTURE_SECRET = 'perdele-noi-unit-secret-2222222222222222222222';

/** @param array<string, array<string, string>> $settings @param array<string, string>|null $secrets @param list<string> $keys */
function sourceTestConfig(array $settings, ?array $secrets = null, array $keys = []): Config
{
    return new Config(
        'test', str_repeat('t', 32), 'localhost', 3306, 'db', 'u', 'p', ['https://staff.arasyahome.ro'], 3600, 300, 5, 30, 900, false, [],
        sourceSecrets: $secrets ?? ['trendhome' => SOURCE_TEST_TRENDHOME_SECRET, 'outletperdele' => SOURCE_TEST_OUTLET_SECRET],
        sourceKeys: $keys,
        sourceSettings: $settings,
    );
}

/** Records every statement so a test can prove that no database call happened at all. */
function sourceRecordingPdo(): PDO
{
    return new class ('sqlite::memory:') extends PDO {
        /** @var list<string> */
        public array $statements = [];

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $this->statements[] = $query;
            return parent::prepare($query, $options);
        }

        public function exec(string $statement): int|false
        {
            $this->statements[] = $statement;
            return parent::exec($statement);
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            $this->statements[] = $query;
            return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        }

        public function beginTransaction(): bool
        {
            $this->statements[] = 'BEGIN';
            return parent::beginTransaction();
        }
    };
}

/** @return array{controller: SourceIngestionController, pdo: PDO, limiter: object} */
function sourceTestController(Config $config): array
{
    $pdo = sourceRecordingPdo();
    $clock = new MutableClock(new DateTimeImmutable('@' . SOURCE_TEST_NOW));
    $limiter = new class implements ApiRateLimiter {
        /** @var list<string> */
        public array $hits = [];

        public function hit(string $scope, string $subject, int $limit, int $windowSeconds): bool
        {
            $this->hits[] = "{$scope}/{$subject}";
            return true;
        }
    };
    $controller = new SourceIngestionController(new SourceSignatureVerifier($config->sourceSecrets), new OrderProjectionWriter($pdo, $clock), $limiter, $clock, SourceRegistry::fromConfig($config), new SourceAuthorityQueries($pdo));
    return ['controller' => $controller, 'pdo' => $pdo, 'limiter' => $limiter];
}

/** @param array<string, mixed>|string $payload */
function sourceSignedRequest(array|string $payload, string $secret, ?int $timestamp = null, ?string $signature = null): Request
{
    $body = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $timestamp ??= SOURCE_TEST_NOW;
    return new Request('POST', '/integrations/sources/test', [
        'content-type' => 'application/json',
        'x-arasya-timestamp' => (string) $timestamp,
        'x-arasya-signature' => $signature ?? SourceSignatureVerifier::sign($secret, (string) $timestamp, $body),
    ], [], $body, '127.0.0.1', 'test', 'req-source', []);
}

/** @return array<string, mixed> */
function sourceTestPayload(?array $production = null, array $orderOverrides = []): array
{
    $payload = [
        'schemaVersion' => 1,
        'eventId' => 'wc-61833-20261007100000',
        'changedAt' => '2026-10-07T10:00:00Z',
        'order' => [
            'id' => 61833,
            'number' => 'TH-61833',
            'status' => ['code' => 'processing', 'label' => 'Se procesează'],
            'availability' => 'active',
            'notes' => 'Confidential note',
            'acceptedAt' => '2026-10-07T09:00:00Z',
            'items' => [[
                'id' => 981, 'line' => 1, 'name' => 'Draperie Secret Velvet', 'sku' => 'SKU-SECRET-302', 'variant' => 'Wave', 'color' => 'Bej',
                'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => 8.4, 'quantity' => 1,
                'options' => [['label' => 'Confecționare', 'value' => '2 bucăți']],
            ]],
            'delivery' => ['name' => 'Ion Clientescu', 'street' => 'Str. Privată 7', 'city' => 'Cluj-Napoca', 'postalCode' => '400000', 'country' => 'RO', 'phone' => '0722000111'],
            ...$orderOverrides,
        ],
    ];
    if ($production !== null) {
        $payload['production'] = $production;
    }
    return $payload;
}

function sourceExpectApi(string $code, int $status, Closure $callback): ApiException
{
    try {
        $callback();
    } catch (ApiException $error) {
        expect($error->errorCode === $code && $error->status === $status, "Expected {$status} {$code}, received {$error->status} {$error->errorCode}.");
        foreach ([SOURCE_TEST_TRENDHOME_SECRET, SOURCE_TEST_OUTLET_SECRET, SOURCE_TEST_FUTURE_SECRET] as $secret) {
            expect(!str_contains($error->getMessage(), $secret) && !str_contains(json_encode($error->details, JSON_THROW_ON_ERROR), $secret), 'An error must never carry a source secret.');
        }
        return $error;
    }
    throw new RuntimeException("Expected API error {$code}.");
}

function sourceJson(Response $response): string
{
    return json_encode($response->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function sourceResponseHasNoSecret(Response $response): bool
{
    foreach ([SOURCE_TEST_TRENDHOME_SECRET, SOURCE_TEST_OUTLET_SECRET, SOURCE_TEST_FUTURE_SECRET] as $secret) {
        if (str_contains(sourceJson($response), $secret) || str_contains(json_encode($response->headers, JSON_THROW_ON_ERROR), $secret)) {
            return false;
        }
    }
    return true;
}

test('canonical curtain-production@1 keeps exactly 14 stages in their exact order', function (): void {
    expect(CanonicalProductionWorkflowContract::WORKFLOW_ID === 'curtain-production' && CanonicalProductionWorkflowContract::VERSION === 1);
    expect(CanonicalProductionWorkflowContract::STAGES === [
        'waiting' => 1, 'material-preparation' => 2, 'workshop-receiving' => 3, 'labeling' => 4, 'material-straightening' => 5,
        'bottom-hem' => 6, 'side-hem' => 7, 'ironing' => 8, 'height' => 9, 'header-tape' => 10, 'sewing-finishing' => 11,
        'quality-control' => 12, 'packing' => 13, 'delivery' => 14,
    ], 'The canonical 14-stage structure changed.');
});

test('source registry recognizes configured sites, defaults to validation and fails closed on bad configuration', function (): void {
    $registry = SourceRegistry::fromConfig(sourceTestConfig(
        ['trendhome' => ['mode' => 'active'], 'outletperdele' => [], 'perdele-noi' => ['mode' => 'validation', 'displayName' => 'Perdele Noi']],
        ['trendhome' => SOURCE_TEST_TRENDHOME_SECRET, 'outletperdele' => SOURCE_TEST_OUTLET_SECRET, 'perdele-noi' => SOURCE_TEST_FUTURE_SECRET],
    ));
    expect($registry->find('trendhome')?->toArray() === ['sourceKey' => 'trendhome', 'displayName' => 'Trendhome', 'integrationType' => 'yd-soft-woocommerce', 'enabled' => true, 'mode' => 'active']);
    expect($registry->find('outletperdele')?->toArray() === ['sourceKey' => 'outletperdele', 'displayName' => 'OutletPerdele', 'integrationType' => 'yd-soft-woocommerce', 'enabled' => true, 'mode' => 'validation'], 'A source without a mode must default to validation.');
    expect($registry->find('perdele-noi')?->displayName === 'Perdele Noi' && $registry->find('perdele-noi')?->mode === SourceMode::Validation, 'A future configured site needs no new code.');
    expect($registry->find('unknown-site') === null && $registry->issues() === []);
    expect($registry->find('trendhome')->canIngest() && !$registry->find('outletperdele')->canIngest());

    $disabled = SourceRegistry::fromConfig(sourceTestConfig(['trendhome' => ['mode' => 'active', 'enabled' => 'false'], 'outletperdele' => []]));
    expect($disabled->find('trendhome')?->enabled === false && !$disabled->find('trendhome')->canIngest(), 'A disabled active source can never ingest.');

    $broken = SourceRegistry::fromConfig(sourceTestConfig(
        ['trendhome' => ['mode' => 'live'], 'outletperdele' => ['enabled' => 'maybe'], 'nosecret' => [], 'typed' => ['integrationType' => 'shopify']],
        ['trendhome' => SOURCE_TEST_TRENDHOME_SECRET, 'outletperdele' => SOURCE_TEST_OUTLET_SECRET, 'typed' => SOURCE_TEST_FUTURE_SECRET, 'b2b' => SOURCE_TEST_FUTURE_SECRET],
        ['trendhome', 'outletperdele', 'nosecret', 'typed', 'b2b', 'trendyol', 'dup', 'dup', 'a-b', 'a_b', 'Not Valid ' . SOURCE_TEST_FUTURE_SECRET],
    ));
    expect($broken->all() === [], 'Every misconfigured source must fail closed.');
    $codes = array_map(static fn (array $issue): string => $issue['code'] . ':' . ($issue['sourceKey'] ?? '-'), $broken->issues());
    sort($codes);
    $expected = ['duplicate_key:dup', 'environment_collision:a-b', 'environment_collision:a_b', 'invalid_enabled:outletperdele', 'invalid_key:-', 'invalid_mode:trendhome', 'invalid_type:typed', 'missing_secret:nosecret', 'reserved_key:b2b', 'reserved_key:trendyol'];
    sort($expected);
    expect($codes === $expected, 'Unexpected registry issues: ' . implode(',', $codes));
    expect(!str_contains(var_export($broken, true), SOURCE_TEST_FUTURE_SECRET) && !str_contains(var_export($broken->issues(), true), 'Not Valid'), 'Registry diagnostics never carry secrets or raw invalid values.');
});

test('source configuration loads one secret and mode per site from the environment and private file', function (): void {
    $home = sys_get_temp_dir() . '/arasya-source-config-' . bin2hex(random_bytes(6));
    mkdir($home . '/arasya-config', 0700, true);
    try {
        $base = ['HOME' => $home, 'ARASYA_APP_SECRET' => str_repeat('s', 32), 'ARASYA_DB_NAME' => 'db', 'ARASYA_DB_USER' => 'u', 'ARASYA_DB_PASSWORD' => 'p', 'ARASYA_ALLOWED_ORIGINS' => 'https://staff.arasyahome.ro'];
        $default = Config::fromEnvironment(new ConfigLoader($base));
        expect($default->sourceKeys === ['trendhome', 'outletperdele'] && $default->sourceSecrets === [], 'Both first-party sources stay declared by default.');
        expect(array_column(SourceRegistry::fromConfig($default)->issues(), 'code') === ['missing_secret', 'missing_secret']);

        $config = Config::fromEnvironment(new ConfigLoader([
            ...$base,
            'ARASYA_SOURCE_KEYS' => 'trendhome, outletperdele, perdele-noi',
            'ARASYA_SOURCE_SECRET_TRENDHOME' => SOURCE_TEST_TRENDHOME_SECRET,
            'ARASYA_SOURCE_MODE_TRENDHOME' => 'active',
            'ARASYA_SOURCE_SECRET_OUTLETPERDELE' => SOURCE_TEST_OUTLET_SECRET,
            'ARASYA_SOURCE_SECRET_PERDELE_NOI' => SOURCE_TEST_FUTURE_SECRET,
            'ARASYA_SOURCE_NAME_PERDELE_NOI' => 'Perdele Noi',
            'ARASYA_SOURCE_ENABLED_PERDELE_NOI' => 'false',
            'ARASYA_SOURCE_SECRET_NOT_DECLARED' => SOURCE_TEST_FUTURE_SECRET,
        ]));
        expect($config->sourceSecrets === ['trendhome' => SOURCE_TEST_TRENDHOME_SECRET, 'outletperdele' => SOURCE_TEST_OUTLET_SECRET, 'perdele-noi' => SOURCE_TEST_FUTURE_SECRET], 'Only declared sources load a secret.');
        $registry = SourceRegistry::fromConfig($config);
        expect($registry->find('trendhome')?->mode === SourceMode::Active && $registry->find('outletperdele')?->mode === SourceMode::Validation);
        expect($registry->find('perdele-noi')?->enabled === false && $registry->find('perdele-noi')?->displayName === 'Perdele Noi' && $registry->issues() === []);

        // The preferred private secrets.json carries the same per-source keys.
        file_put_contents($home . '/arasya-config/secrets.json', json_encode([
            'ARASYA_APP_SECRET' => str_repeat('j', 32), 'DB_NAME' => 'db', 'DB_USER_NAME' => 'u', 'DB_USER_PASSWORD' => 'p', 'ARASYA_ALLOWED_ORIGINS' => ['https://staff.arasyahome.ro'],
            'ARASYA_SOURCE_SECRET_OUTLETPERDELE' => SOURCE_TEST_OUTLET_SECRET, 'ARASYA_SOURCE_MODE_OUTLETPERDELE' => 'active',
        ], JSON_THROW_ON_ERROR));
        chmod($home . '/arasya-config/secrets.json', 0600);
        $file = SourceRegistry::fromConfig(Config::fromEnvironment(new ConfigLoader(['HOME' => $home])));
        expect($file->find('outletperdele')?->canIngest() === true && $file->find('trendhome') === null);

        expectRuntime(fn () => Config::fromEnvironment(new ConfigLoader([...$base, 'ARASYA_SOURCE_SECRET_TRENDHOME' => 'CHANGE_ME' . str_repeat('x', 40)])));
        expectRuntime(fn () => Config::fromEnvironment(new ConfigLoader([...$base, 'ARASYA_SOURCE_SECRET_TRENDHOME' => 'short'])));
    } finally {
        @unlink($home . '/arasya-config/secrets.json');
        @rmdir($home . '/arasya-config');
        @rmdir($home);
    }
});

test('source HMAC secrets are isolated per site and bound to timestamp, body and source key', function (): void {
    $config = sourceTestConfig(['trendhome' => ['mode' => 'active'], 'outletperdele' => ['mode' => 'active']]);
    ['controller' => $controller, 'limiter' => $limiter] = sourceTestController($config);
    $payload = sourceTestPayload();
    $trendhome = $controller->validateOrder(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET), 'trendhome');
    expect($trendhome->status === 200 && $trendhome->payload['sourceKey'] === 'trendhome');
    $outlet = $controller->validateOrder(sourceSignedRequest($payload, SOURCE_TEST_OUTLET_SECRET), 'outletperdele');
    expect($outlet->status === 200 && $outlet->payload['sourceKey'] === 'outletperdele');
    expect($limiter->hits === ['source-ingestion/trendhome', 'source-ingestion/outletperdele'], 'Each source has its own rate-limit identity.');

    foreach (['validateOrder', 'ingestOrder', 'heartbeat'] as $operation) {
        sourceExpectApi('SOURCE_SIGNATURE_INVALID', 401, fn () => $controller->{$operation}(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET), 'outletperdele'));
        sourceExpectApi('SOURCE_SIGNATURE_INVALID', 401, fn () => $controller->{$operation}(sourceSignedRequest($payload, SOURCE_TEST_OUTLET_SECRET), 'trendhome'));
    }
    // A captured valid trendhome request replayed under another source key is rejected.
    $captured = sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET);
    sourceExpectApi('SOURCE_SIGNATURE_INVALID', 401, fn () => $controller->validateOrder($captured, 'outletperdele'));

    sourceExpectApi('SOURCE_SIGNATURE_INVALID', 401, fn () => $controller->validateOrder(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET, null, 'v1=' . str_repeat('g', 64)), 'trendhome'));
    sourceExpectApi('SOURCE_SIGNATURE_INVALID', 401, fn () => $controller->validateOrder(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET, null, 'v2=' . hash_hmac('sha256', SOURCE_TEST_NOW . '.x', SOURCE_TEST_TRENDHOME_SECRET)), 'trendhome'));
    sourceExpectApi('SOURCE_SIGNATURE_INVALID', 401, fn () => $controller->validateOrder(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET, null, strtoupper(SourceSignatureVerifier::sign(SOURCE_TEST_TRENDHOME_SECRET, (string) SOURCE_TEST_NOW, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))), 'trendhome'));
    sourceExpectApi('SOURCE_SIGNATURE_INVALID', 401, fn () => $controller->validateOrder(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET, SOURCE_TEST_NOW - 301), 'trendhome'));
    sourceExpectApi('SOURCE_SIGNATURE_INVALID', 401, fn () => $controller->validateOrder(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET, SOURCE_TEST_NOW + 301), 'trendhome'));
    expect($controller->validateOrder(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET, SOURCE_TEST_NOW - 300), 'trendhome')->status === 200, 'The 300 second skew boundary is accepted.');
    expect($controller->validateOrder(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET, SOURCE_TEST_NOW + 300), 'trendhome')->status === 200);
});

test('order validation parses schemaVersion 1 strictly and answers a contract summary without echoing data', function (): void {
    ['controller' => $controller] = sourceTestController(sourceTestConfig(['trendhome' => [], 'outletperdele' => []]));
    $response = $controller->validateOrder(sourceSignedRequest(sourceTestPayload(['workflowKey' => 'curtain-production', 'workflowVersion' => 1, 'stageId' => 'waiting', 'stageLabel' => 'În așteptare']), SOURCE_TEST_TRENDHOME_SECRET), 'trendhome');
    expect($response->status === 200 && $response->headers['Cache-Control'] === 'no-store');
    expect($response->payload === [
        'ok' => true,
        'schemaVersion' => 1,
        'sourceKey' => 'trendhome',
        'mode' => 'validation',
        'workflow' => ['id' => 'curtain-production', 'version' => 1, 'stageCount' => 14],
        'itemCount' => 1,
    ], 'Unexpected validation summary: ' . sourceJson($response));
    foreach (['Ion Clientescu', 'Privată', '0722', 'Confidential', 'SKU-SECRET', 'Draperie', 'TH-61833', '61833', 'wc-61833', 'bucăți', '8.4', '300'] as $private) {
        expect(!str_contains(sourceJson($response), $private), "Validation echoed a payload value: {$private}");
    }

    $valid = sourceTestPayload();
    $invalid = [
        'SOURCE_SCHEMA_UNSUPPORTED' => [...$valid, 'schemaVersion' => 2],
        'SOURCE_PAYLOAD_INVALID' => [...$valid, 'customer' => ['email' => 'x@example.com']],
    ];
    foreach ($invalid as $code => $payload) {
        sourceExpectApi($code, 422, fn () => $controller->validateOrder(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));
    }
    $unknownItemField = $valid;
    $unknownItemField['order']['items'][0]['price'] = 120;
    sourceExpectApi('SOURCE_PAYLOAD_INVALID', 422, fn () => $controller->validateOrder(sourceSignedRequest($unknownItemField, SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));
    $email = $valid;
    $email['order']['delivery']['email'] = 'client@example.com';
    $emailError = sourceExpectApi('SOURCE_PAYLOAD_INVALID', 422, fn () => $controller->validateOrder(sourceSignedRequest($email, SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));
    expect(!str_contains($emailError->getMessage(), 'client@example.com'), 'Errors name fields, never values.');
    $tooMany = $valid;
    $tooMany['order']['items'] = array_map(static fn (int $line): array => ['id' => $line, 'line' => $line, 'name' => 'Draperie', 'quantity' => 1], range(1, 201));
    sourceExpectApi('SOURCE_PAYLOAD_INVALID', 422, fn () => $controller->validateOrder(sourceSignedRequest($tooMany, SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));
    $maxItems = $valid;
    $maxItems['order']['items'] = array_slice($tooMany['order']['items'], 0, 200);
    expect($controller->validateOrder(sourceSignedRequest($maxItems, SOURCE_TEST_TRENDHOME_SECRET), 'trendhome')->payload['itemCount'] === 200, 'Exactly 200 items stay valid.');
    $options = $valid;
    $options['order']['items'][0]['options'] = [['label' => 'Confecționare', 'value' => '1 buc.', 'meaning' => 'pieces']];
    sourceExpectApi('SOURCE_PAYLOAD_INVALID', 422, fn () => $controller->validateOrder(sourceSignedRequest($options, SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));

    // Only explicit canonical stage IDs are accepted; labels, old 8-stage names and other workflows never map.
    // Malformed IDs (labels, diacritics, spaces, upper case) fail like real ingestion: as an invalid payload.
    foreach (['', 'Tăiere', 'In productie', 'MATERIAL-PREPARATION', 'În așteptare'] as $stageId) {
        sourceExpectApi('SOURCE_PAYLOAD_INVALID', 422, fn () => $controller->validateOrder(sourceSignedRequest(sourceTestPayload(['workflowKey' => 'curtain-production', 'workflowVersion' => 1, 'stageId' => $stageId]), SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));
    }
    foreach (['cutting', 'taiere', 'croitorie', 'in-productie', 'finalizat', 'sewing'] as $stageId) {
        sourceExpectApi('SOURCE_STAGE_UNKNOWN', 422, fn () => $controller->validateOrder(sourceSignedRequest(sourceTestPayload(['workflowKey' => 'curtain-production', 'workflowVersion' => 1, 'stageId' => $stageId]), SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));
    }
    sourceExpectApi('SOURCE_STAGE_UNKNOWN', 422, fn () => $controller->validateOrder(sourceSignedRequest(sourceTestPayload(['workflowKey' => 'yd-soft', 'workflowVersion' => 1, 'stageId' => 'waiting']), SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));
    sourceExpectApi('SOURCE_STAGE_UNKNOWN', 422, fn () => $controller->validateOrder(sourceSignedRequest(sourceTestPayload(['workflowKey' => 'curtain-production', 'workflowVersion' => 2, 'stageId' => 'waiting']), SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));
    foreach (array_keys(CanonicalProductionWorkflowContract::STAGES) as $stageId) {
        expect($controller->validateOrder(sourceSignedRequest(sourceTestPayload(['workflowKey' => 'curtain-production', 'workflowVersion' => 1, 'stageId' => $stageId]), SOURCE_TEST_TRENDHOME_SECRET), 'trendhome')->status === 200);
    }
    sourceExpectApi('REQUEST_TOO_LARGE', 413, fn () => $controller->validateOrder(sourceSignedRequest(str_repeat(' ', SourceIngestionController::MAX_BODY_BYTES + 1), SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));
});

test('order validation and the mode guard perform zero database statements', function (): void {
    ['controller' => $controller, 'pdo' => $pdo] = sourceTestController(sourceTestConfig(['trendhome' => ['mode' => 'validation'], 'outletperdele' => ['mode' => 'active']]));
    $payload = sourceTestPayload(['workflowKey' => 'curtain-production', 'workflowVersion' => 1, 'stageId' => 'material-preparation']);
    for ($i = 0; $i < 3; $i++) {
        expect($controller->validateOrder(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET), 'trendhome')->status === 200);
        expect($controller->validateOrder(sourceSignedRequest($payload, SOURCE_TEST_OUTLET_SECRET), 'outletperdele')->status === 200, 'An active source may also validate.');
    }
    expect($pdo->statements === [], 'Validation touched the database: ' . implode(' | ', $pdo->statements));

    sourceExpectApi('SOURCE_NOT_ACTIVE', 403, fn () => $controller->ingestOrder(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));
    expect($pdo->statements === [], 'A validation source reached the projection writer.');
});

test('unknown, reserved and disabled sources fail closed on every signed route', function (): void {
    $config = sourceTestConfig(
        ['trendhome' => ['mode' => 'active', 'enabled' => 'false'], 'outletperdele' => ['mode' => 'validation', 'enabled' => 'false']],
    );
    ['controller' => $controller, 'pdo' => $pdo, 'limiter' => $limiter] = sourceTestController($config);
    $payload = sourceTestPayload();
    foreach (['validateOrder', 'ingestOrder', 'heartbeat'] as $operation) {
        sourceExpectApi('SOURCE_NOT_CONFIGURED', 503, fn () => $controller->{$operation}(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));
        sourceExpectApi('SOURCE_NOT_CONFIGURED', 503, fn () => $controller->{$operation}(sourceSignedRequest($payload, SOURCE_TEST_OUTLET_SECRET), 'outletperdele'));
        sourceExpectApi('SOURCE_NOT_CONFIGURED', 503, fn () => $controller->{$operation}(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET), 'unknown-site'));
        sourceExpectApi('SOURCE_NOT_CONFIGURED', 503, fn () => $controller->{$operation}(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET), 'b2b'));
        sourceExpectApi('SOURCE_NOT_CONFIGURED', 503, fn () => $controller->{$operation}(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET), 'trendyol'));
        sourceExpectApi('NOT_FOUND', 404, fn () => $controller->{$operation}(sourceSignedRequest($payload, SOURCE_TEST_TRENDHOME_SECRET), 'Trendhome'));
    }
    expect($pdo->statements === [] && $limiter->hits === [], 'Rejected sources must not reach storage or consume a rate-limit identity.');

    // A secret configured for a reserved key never makes it usable.
    $reserved = sourceTestController(sourceTestConfig(['b2b' => ['mode' => 'active']], ['b2b' => SOURCE_TEST_FUTURE_SECRET]));
    sourceExpectApi('SOURCE_NOT_CONFIGURED', 503, fn () => $reserved['controller']->ingestOrder(sourceSignedRequest($payload, SOURCE_TEST_FUTURE_SECRET), 'b2b'));
});

test('signed heartbeat reports mode and contract only, for validation and active sources', function (): void {
    ['controller' => $controller, 'pdo' => $pdo] = sourceTestController(sourceTestConfig(['trendhome' => ['mode' => 'active'], 'outletperdele' => []]));
    $pdo->exec("CREATE TABLE order_sources (source_key TEXT PRIMARY KEY, status TEXT NOT NULL, last_contact_at TEXT NULL, updated_at TEXT NULL)");
    $pdo->exec("INSERT INTO order_sources (source_key, status) VALUES ('trendhome', 'active'), ('outletperdele', 'active')");
    foreach (['trendhome' => [SOURCE_TEST_TRENDHOME_SECRET, 'active'], 'outletperdele' => [SOURCE_TEST_OUTLET_SECRET, 'validation']] as $key => [$secret, $mode]) {
        $response = $controller->heartbeat(sourceSignedRequest(['sentAt' => '2026-10-07T10:00:00Z'], $secret), $key);
        expect($response->payload === ['ok' => true, 'sourceKey' => $key, 'mode' => $mode, 'productionAuthorityMode' => 'legacy', 'qrAuthorityMode' => 'legacy', 'documentAuthorityMode' => 'legacy', 'trackingAuthorityMode' => 'legacy', 'contract' => ['schemaVersion' => 1, 'workflowId' => 'curtain-production', 'workflowVersion' => 1, 'stageCount' => 14]], 'Unexpected heartbeat: ' . sourceJson($response));
        expect(sourceResponseHasNoSecret($response));
    }
    $contacts = $pdo->query('SELECT COUNT(*) FROM order_sources WHERE last_contact_at IS NOT NULL')->fetchColumn();
    expect((int) $contacts === 2, 'Heartbeat keeps recording source contact.');
    sourceExpectApi('SOURCE_PAYLOAD_INVALID', 422, fn () => $controller->heartbeat(sourceSignedRequest(['sentAt' => '2026-10-07T10:00:00Z', 'secret' => 'x'], SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));
});

test('signed source routes dispatch validate, ingest and heartbeat without browser CORS', function (): void {
    [$auth] = authFixture();
    $config = sourceTestConfig(['trendhome' => ['mode' => 'validation'], 'outletperdele' => ['mode' => 'validation']]);
    $context = new \Arasya\Operations\Http\RequestContext();
    $cookies = new \Arasya\Operations\Security\CookiePolicy($config);
    ['controller' => $controller, 'pdo' => $pdo] = sourceTestController($config);
    $kernel = new \Arasya\Operations\Http\ApiKernel(
        new \Arasya\Operations\Http\AuthController($auth, new \Arasya\Operations\Security\CsrfGuard(new \Arasya\Operations\Security\SessionTokenManager(str_repeat('p', 32))), $cookies, $config, new \Arasya\Operations\Authorization\AuthorizationService(), $context),
        new \Arasya\Operations\Http\HealthController(new PDO('sqlite::memory:'), new \Arasya\Operations\Support\SystemClock()),
        new \Arasya\Operations\Http\CorsPolicy($config->allowedOrigins),
        new \Arasya\Operations\Support\StructuredLogger(static fn (string $line): null => null),
        $cookies,
        $context,
        sources: $controller,
    );
    $send = static function (string $method, string $path, array|string $payload, string $secret) use ($kernel): Response {
        $signed = sourceSignedRequest($payload, $secret);
        return $kernel->handle(new Request($method, $path, $signed->headers, [], $signed->body, '127.0.0.1', 'test', 'req-route', []));
    };
    $payload = sourceTestPayload();
    $valid = $send('POST', '/integrations/sources/trendhome/orders/validate', $payload, SOURCE_TEST_TRENDHOME_SECRET);
    expect($valid->status === 200 && $valid->payload['ok'] === true && $valid->payload['mode'] === 'validation');
    expect(!isset($valid->headers['Access-Control-Allow-Origin']) && sourceResponseHasNoSecret($valid));
    $guarded = $send('POST', '/integrations/sources/trendhome/orders', $payload, SOURCE_TEST_TRENDHOME_SECRET);
    expect($guarded->status === 403 && $guarded->payload['error']['code'] === 'SOURCE_NOT_ACTIVE' && sourceResponseHasNoSecret($guarded));
    expect($send('POST', '/integrations/sources/trendhome/orders/validate/x', $payload, SOURCE_TEST_TRENDHOME_SECRET)->status === 404);
    expect($send('GET', '/integrations/sources/trendhome/orders/validate', $payload, SOURCE_TEST_TRENDHOME_SECRET)->status === 404);
    expect($send('POST', '/integrations/sources/trendhome/validate', $payload, SOURCE_TEST_TRENDHOME_SECRET)->status === 404);
    expect($pdo->statements === [], 'Validation-mode routes never reached the database.');
});

/** A PDOException shaped like a real MySQL failure, whose message deliberately carries SQL, a value and an email. */
function sourceMysqlStylePdoException(string $sqlState, int $driverCode): PDOException
{
    $error = new PDOException("SQLSTATE[{$sqlState}]: Integrity constraint violation: {$driverCode} Duplicate entry 'ion.clientescu@example.com-SKU-SECRET-302' for key 'uq_secret_marker' while running INSERT INTO operational_orders (secret_sql_marker) VALUES ('Ion Clientescu')");
    $error->errorInfo = [$sqlState, $driverCode, "Duplicate entry 'ion.clientescu@example.com-SKU-SECRET-302' for key 'uq_secret_marker'"];
    // Real PDO query errors carry the SQLSTATE string as their code.
    (new ReflectionProperty(Exception::class, 'code'))->setValue($error, $sqlState);
    return $error;
}

/** @return array{kernel: \Arasya\Operations\Http\ApiKernel, lines: ArrayObject<int, string>} */
function sourceKernelWithFailingDatabase(PDOException $failure): array
{
    [$auth] = authFixture();
    $config = sourceTestConfig(['trendhome' => ['mode' => 'active'], 'outletperdele' => ['mode' => 'validation']]);
    $failing = new class ('sqlite::memory:') extends PDO {
        public ?PDOException $failure = null;
        public function prepare(string $query, array $options = []): PDOStatement|false { throw $this->failure; }
        public function exec(string $statement): int|false { throw $this->failure; }
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { throw $this->failure; }
        public function beginTransaction(): bool { throw $this->failure; }
    };
    $failing->failure = $failure;
    $clock = new MutableClock(new DateTimeImmutable('@' . SOURCE_TEST_NOW));
    $limiter = new class implements ApiRateLimiter {
        public function hit(string $scope, string $subject, int $limit, int $windowSeconds): bool { return true; }
    };
    $controller = new SourceIngestionController(new SourceSignatureVerifier($config->sourceSecrets), new OrderProjectionWriter($failing, $clock), $limiter, $clock, SourceRegistry::fromConfig($config));
    $context = new \Arasya\Operations\Http\RequestContext();
    $cookies = new \Arasya\Operations\Security\CookiePolicy($config);
    $lines = new ArrayObject();
    $kernel = new \Arasya\Operations\Http\ApiKernel(
        new \Arasya\Operations\Http\AuthController($auth, new \Arasya\Operations\Security\CsrfGuard(new \Arasya\Operations\Security\SessionTokenManager(str_repeat('p', 32))), $cookies, $config, new \Arasya\Operations\Authorization\AuthorizationService(), $context),
        new \Arasya\Operations\Http\HealthController(new PDO('sqlite::memory:'), new \Arasya\Operations\Support\SystemClock()),
        new \Arasya\Operations\Http\CorsPolicy($config->allowedOrigins),
        new \Arasya\Operations\Support\StructuredLogger(static function (string $line) use ($lines): void { $lines[] = $line; }),
        $cookies,
        $context,
        sources: $controller,
    );
    return ['kernel' => $kernel, 'lines' => $lines];
}

function sourceSendSigned(\Arasya\Operations\Http\ApiKernel $kernel, string $path, array $payload, string $secret): Response
{
    $signed = sourceSignedRequest($payload, $secret);
    return $kernel->handle(new Request('POST', $path, $signed->headers, [], $signed->body, '127.0.0.1', 'test', 'req-pdo-diagnostics', []));
}

test('an ingestion database failure logs only safe SQLSTATE and driver code and answers a generic 500', function (): void {
    ['kernel' => $kernel, 'lines' => $lines] = sourceKernelWithFailingDatabase(sourceMysqlStylePdoException('23000', 1062));
    $response = sourceSendSigned($kernel, '/integrations/sources/trendhome/orders', sourceTestPayload(), SOURCE_TEST_TRENDHOME_SECRET);

    // The caller sees exactly the generic contract, nothing from the database.
    expect($response->status === 500);
    expect($response->payload === ['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'The service could not complete the request.', 'requestId' => 'req-pdo-diagnostics']]);
    $body = sourceJson($response);
    foreach (['23000', '1062', 'sqlstate', 'driver_code', 'PDOException', 'Duplicate', 'INSERT', 'operational_orders'] as $hidden) {
        expect(!str_contains($body, $hidden), "The public 500 response must not carry {$hidden}.");
    }

    expect(count($lines) === 1);
    $line = $lines[0];
    $logged = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    expect($logged['event'] === 'internal_error' && $logged['level'] === 'error' && $logged['status'] === 500);
    expect($logged['route'] === '/integrations/sources/trendhome/orders' && $logged['method'] === 'POST' && $logged['request_id'] === 'req-pdo-diagnostics');
    expect($logged['exception'] === 'PDOException' && $logged['sqlstate'] === '23000' && $logged['driver_code'] === 1062);
    expect(!array_key_exists('cause', $logged));
    // Neither the driver message, the SQL text, its parameters nor any payload value reaches the log.
    foreach (['Duplicate entry', 'Integrity constraint', 'uq_secret_marker', 'INSERT INTO', 'secret_sql_marker', 'VALUES', 'ion.clientescu@example.com', '@', 'Ion Clientescu', 'Draperie Secret Velvet', 'SKU-SECRET-302', 'Confidential note', 'Str. Privată', '0722000111', 'trace', '.php', SOURCE_TEST_TRENDHOME_SECRET] as $forbidden) {
        expect(!str_contains($line, $forbidden), "The internal_error log line must not contain {$forbidden}.");
    }
});

test('a real driver failure through the projection writer is logged with its SQLSTATE only', function (): void {
    // A schema-less SQLite database makes the real writer fail inside PDO itself (no such table).
    [$auth] = authFixture();
    $config = sourceTestConfig(['trendhome' => ['mode' => 'active'], 'outletperdele' => ['mode' => 'validation']]);
    $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $clock = new MutableClock(new DateTimeImmutable('@' . SOURCE_TEST_NOW));
    $limiter = new class implements ApiRateLimiter {
        public function hit(string $scope, string $subject, int $limit, int $windowSeconds): bool { return true; }
    };
    $controller = new SourceIngestionController(new SourceSignatureVerifier($config->sourceSecrets), new OrderProjectionWriter($pdo, $clock), $limiter, $clock, SourceRegistry::fromConfig($config), new SourceAuthorityQueries($pdo));
    $context = new \Arasya\Operations\Http\RequestContext();
    $cookies = new \Arasya\Operations\Security\CookiePolicy($config);
    $lines = [];
    $kernel = new \Arasya\Operations\Http\ApiKernel(
        new \Arasya\Operations\Http\AuthController($auth, new \Arasya\Operations\Security\CsrfGuard(new \Arasya\Operations\Security\SessionTokenManager(str_repeat('p', 32))), $cookies, $config, new \Arasya\Operations\Authorization\AuthorizationService(), $context),
        new \Arasya\Operations\Http\HealthController(new PDO('sqlite::memory:'), new \Arasya\Operations\Support\SystemClock()),
        new \Arasya\Operations\Http\CorsPolicy($config->allowedOrigins),
        new \Arasya\Operations\Support\StructuredLogger(static function (string $line) use (&$lines): void { $lines[] = $line; }),
        $cookies,
        $context,
        sources: $controller,
    );
    $response = sourceSendSigned($kernel, '/integrations/sources/trendhome/orders', sourceTestPayload(), SOURCE_TEST_TRENDHOME_SECRET);
    expect($response->status === 500 && $response->payload['error']['code'] === 'INTERNAL_ERROR');
    $logged = json_decode($lines[0] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
    expect(($logged['exception'] ?? null) === 'PDOException' && ($logged['sqlstate'] ?? null) === 'HY000' && is_int($logged['driver_code'] ?? null));
    expect(!str_contains($lines[0], 'no such table') && !str_contains($lines[0], 'SELECT') && !str_contains($lines[0], 'operational_orders'));
});

test('safe exception context keeps only validated structured PDO fields', function (): void {
    $of = static fn (Throwable $error): array => \Arasya\Operations\Support\SafeExceptionContext::of($error);

    // Non-PDO exceptions keep the previous behaviour: the class only.
    expect($of(new RuntimeException('Secret detail for customer@example.com')) === ['exception' => 'RuntimeException']);

    // A connection failure carries no errorInfo; its integer code is the driver code (MySQL 2002).
    $connection = new PDOException('SQLSTATE[HY000] [2002] Resource temporarily unavailable (host db.internal user arasya_app)', 2002);
    expect($of($connection) === ['exception' => 'PDOException', 'driver_code' => 2002]);

    // A wrapped PDO failure names the cause and its safe fields, never the wrapper's message.
    $wrapped = new RuntimeException('Writer failed for Ion Clientescu', 0, sourceMysqlStylePdoException('40001', 1213));
    expect($of($wrapped) === ['exception' => 'RuntimeException', 'cause' => 'PDOException', 'sqlstate' => '40001', 'driver_code' => 1213]);

    // Anything that is not a well-formed SQLSTATE or a bounded positive integer is dropped.
    $malformed = new PDOException('ignored');
    $malformed->errorInfo = ["23000'; DROP TABLE x;--", '1062 Duplicate entry customer@example.com', 'driver message'];
    expect($of($malformed) === ['exception' => 'PDOException']);
    $hostile = new PDOException('ignored');
    $hostile->errorInfo = ['00000', 999_999_999, 'driver message'];
    expect($of($hostile) === ['exception' => 'PDOException']);

    // The logger's redactor keeps these keys (they are diagnostics, not secrets).
    $sanitized = \Arasya\Operations\Support\SensitiveDataRedactor::sanitize($of(sourceMysqlStylePdoException('23000', 1062)));
    expect($sanitized === ['exception' => 'PDOException', 'sqlstate' => '23000', 'driver_code' => 1062]);
});

test('expected API errors and validation are unchanged by the PDO diagnostics', function (): void {
    ['kernel' => $kernel, 'lines' => $lines] = sourceKernelWithFailingDatabase(sourceMysqlStylePdoException('23000', 1062));
    // A validation-mode source is still refused before any database access, as a normal API error.
    $guarded = sourceSendSigned($kernel, '/integrations/sources/outletperdele/orders', sourceTestPayload(), SOURCE_TEST_OUTLET_SECRET);
    expect($guarded->status === 403 && $guarded->payload['error']['code'] === 'SOURCE_NOT_ACTIVE');
    // Zero-write validation never touches the (failing) database and still succeeds.
    $valid = sourceSendSigned($kernel, '/integrations/sources/trendhome/orders/validate', sourceTestPayload(), SOURCE_TEST_TRENDHOME_SECRET);
    expect($valid->status === 200 && $valid->payload['ok'] === true);
    $warning = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    expect($warning['event'] === 'api_error' && $warning['code'] === 'SOURCE_NOT_ACTIVE' && $warning['status'] === 403);
    expect(!array_key_exists('exception', $warning) && !array_key_exists('sqlstate', $warning) && !array_key_exists('driver_code', $warning));
});

test('migrations stay sequential through 021 and the canonical workflow keeps its 14 stages', function (): void {
    $migrations = array_map('basename', glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: []);
    expect(count($migrations) === 21 && str_starts_with($migrations[0], '001_') && str_starts_with($migrations[16], '017_') && $migrations[17] === '018_production_authority.sql' && $migrations[18] === '019_production_qr_authority.sql' && $migrations[19] === '020_production_document_authority.sql' && $migrations[20] === '021_document_scopes.sql');
    expect(count(CanonicalProductionWorkflowContract::STAGES) === 14);
    expect(array_keys(CanonicalProductionWorkflowContract::STAGES) === ['waiting', 'material-preparation', 'workshop-receiving', 'labeling', 'material-straightening', 'bottom-hem', 'side-hem', 'ironing', 'height', 'header-tape', 'sewing-finishing', 'quality-control', 'packing', 'delivery']);
    // 018 is additive: it never converts the authority of existing orders.
    $sql = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/018_production_authority.sql');
    expect(preg_match('/UPDATE\s+operational_orders|DELETE\s+FROM|DROP\s+TABLE|production_authority\s*=/i', $sql) === 0, '018 must not rewrite orders or drop data.');
});

test('document authority mode is per source, defaults to legacy and enforces only with QR authority enforce', function (): void {
    $registry = SourceRegistry::fromConfig(sourceTestConfig([
        'trendhome' => ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'enforce', 'documentAuthority' => 'Enforce'],
        'outletperdele' => ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'observe', 'documentAuthority' => 'enforce'],
    ]));
    expect($registry->find('trendhome')?->documentAuthorityMode === \Arasya\Operations\Production\DocumentAuthorityMode::Enforce);
    expect($registry->find('outletperdele')?->documentAuthorityMode === \Arasya\Operations\Production\DocumentAuthorityMode::Observe, 'Document enforce without QR enforce runs as observe.');
    expect(in_array(['code' => 'document_authority_requires_qr_enforce', 'sourceKey' => 'outletperdele'], $registry->issues(), true));
    $modes = ProductionAuthorityModes::fromRegistry($registry);
    expect($modes->documentModeFor('trendhome') === \Arasya\Operations\Production\DocumentAuthorityMode::Enforce && $modes->documentModeFor('b2b') === \Arasya\Operations\Production\DocumentAuthorityMode::Legacy);
    expect(\Arasya\Operations\Document\DocumentService::documentAuthorityOf($modes, 'trendhome', 'operations') === 'arasya');
    expect(\Arasya\Operations\Document\DocumentService::documentAuthorityOf($modes, 'trendhome', 'source') === 'source', 'Orders the source still manages keep the source ticket.');
    expect(\Arasya\Operations\Document\DocumentService::documentAuthorityOf($modes, 'b2b', 'operations') === 'arasya' && \Arasya\Operations\Document\DocumentService::documentAuthorityOf($modes, 'trendyol', 'source') === 'arasya', 'Internal and unmanaged sources are unchanged.');
    $unset = SourceRegistry::fromConfig(sourceTestConfig(['trendhome' => ['mode' => 'active', 'documentAuthority' => 'on']]));
    expect($unset->find('trendhome')?->documentAuthorityMode === \Arasya\Operations\Production\DocumentAuthorityMode::Legacy && $unset->find('trendhome')?->canIngest() === true);
    expect(in_array(['code' => 'invalid_document_authority_mode', 'sourceKey' => 'trendhome'], $unset->issues(), true));
    expect(\Arasya\Operations\Document\DocumentService::documentAuthorityOf(ProductionAuthorityModes::fromRegistry($unset), 'trendhome', 'operations') === 'source', 'Legacy: the source ticket stays.');
});

test('production authority mode is per source, defaults to legacy and never disables a source', function (): void {
    $registry = SourceRegistry::fromConfig(sourceTestConfig(['trendhome' => ['mode' => 'active', 'authority' => 'Enforce'], 'outletperdele' => ['mode' => 'active']]));
    expect($registry->find('trendhome')?->authorityMode === ProductionAuthorityMode::Enforce);
    expect($registry->find('outletperdele')?->authorityMode === ProductionAuthorityMode::Legacy, 'Unset authority mode is legacy.');
    $modes = ProductionAuthorityModes::fromRegistry($registry);
    expect($modes->modeFor('trendhome') === ProductionAuthorityMode::Enforce && $modes->modeFor('outletperdele') === ProductionAuthorityMode::Legacy);
    expect($modes->modeFor('b2b') === ProductionAuthorityMode::Legacy && !$modes->isManagedSource('b2b') && $modes->modeFor('trendyol') === ProductionAuthorityMode::Legacy);

    $invalid = SourceRegistry::fromConfig(sourceTestConfig(['trendhome' => ['mode' => 'active', 'authority' => 'on'], 'outletperdele' => ['authority' => 'observe']]));
    expect($invalid->find('trendhome')?->authorityMode === ProductionAuthorityMode::Legacy && $invalid->find('trendhome')?->canIngest() === true, 'An unreadable authority mode falls back to legacy and keeps ingestion working.');
    expect(in_array(['code' => 'invalid_authority_mode', 'sourceKey' => 'trendhome'], $invalid->issues(), true));
    expect($invalid->find('outletperdele')?->authorityMode === ProductionAuthorityMode::Observe);

    $home = sys_get_temp_dir() . '/arasya-authority-config-' . bin2hex(random_bytes(6));
    mkdir($home . '/arasya-config', 0700, true);
    try {
        file_put_contents($home . '/arasya-config/secrets.json', json_encode([
            'ARASYA_APP_SECRET' => str_repeat('j', 32), 'DB_NAME' => 'db', 'DB_USER_NAME' => 'u', 'DB_USER_PASSWORD' => 'p', 'ARASYA_ALLOWED_ORIGINS' => ['https://staff.arasyahome.ro'],
            'ARASYA_SOURCE_SECRET_TRENDHOME' => SOURCE_TEST_TRENDHOME_SECRET, 'ARASYA_SOURCE_MODE_TRENDHOME' => 'active', 'ARASYA_SOURCE_AUTHORITY_TRENDHOME' => 'observe',
            'ARASYA_SOURCE_SECRET_OUTLETPERDELE' => SOURCE_TEST_OUTLET_SECRET, 'ARASYA_SOURCE_MODE_OUTLETPERDELE' => 'active',
        ], JSON_THROW_ON_ERROR));
        chmod($home . '/arasya-config/secrets.json', 0600);
        $file = SourceRegistry::fromConfig(Config::fromEnvironment(new ConfigLoader(['HOME' => $home])));
        expect($file->find('trendhome')?->authorityMode === ProductionAuthorityMode::Observe, 'ARASYA_SOURCE_AUTHORITY_<SOURCE> is read from the private configuration file.');
        expect($file->find('outletperdele')?->authorityMode === ProductionAuthorityMode::Legacy && $file->issues() === []);
    } finally {
        @unlink($home . '/arasya-config/secrets.json');
        @rmdir($home . '/arasya-config');
        @rmdir($home);
    }
});

test('QR authority mode is per source, defaults to legacy and enforce requires production authority enforce', function (): void {
    $registry = SourceRegistry::fromConfig(sourceTestConfig([
        'trendhome' => ['mode' => 'active', 'authority' => 'enforce', 'qrAuthority' => 'Enforce'],
        'outletperdele' => ['mode' => 'active', 'authority' => 'enforce'],
    ]));
    expect($registry->find('trendhome')?->qrAuthorityMode === QrAuthorityMode::Enforce && $registry->issues() === []);
    expect($registry->find('outletperdele')?->qrAuthorityMode === QrAuthorityMode::Legacy, 'Unset QR authority mode is legacy.');
    $modes = ProductionAuthorityModes::fromRegistry($registry);
    expect($modes->qrModeFor('trendhome') === QrAuthorityMode::Enforce && $modes->qrModeFor('outletperdele') === QrAuthorityMode::Legacy && $modes->qrModeFor('b2b') === QrAuthorityMode::Legacy);
    expect($modes->modeFor('trendhome') === ProductionAuthorityMode::Enforce, 'The QR mode never changes the production authority mode.');

    $guarded = SourceRegistry::fromConfig(sourceTestConfig([
        'trendhome' => ['mode' => 'active', 'authority' => 'observe', 'qrAuthority' => 'enforce'],
        'outletperdele' => ['mode' => 'active', 'qrAuthority' => 'sometimes'],
    ]));
    expect($guarded->find('trendhome')?->qrAuthorityMode === QrAuthorityMode::Observe && $guarded->find('trendhome')?->canIngest() === true, 'QR enforce without production enforce runs as observe and keeps ingestion working.');
    expect(in_array(['code' => 'qr_authority_requires_production_enforce', 'sourceKey' => 'trendhome'], $guarded->issues(), true));
    expect($guarded->find('outletperdele')?->qrAuthorityMode === QrAuthorityMode::Legacy && in_array(['code' => 'invalid_qr_authority_mode', 'sourceKey' => 'outletperdele'], $guarded->issues(), true), 'An unreadable QR mode falls back to legacy.');

    $home = sys_get_temp_dir() . '/arasya-qr-config-' . bin2hex(random_bytes(6));
    mkdir($home . '/arasya-config', 0700, true);
    try {
        file_put_contents($home . '/arasya-config/secrets.json', json_encode([
            'ARASYA_APP_SECRET' => str_repeat('j', 32), 'DB_NAME' => 'db', 'DB_USER_NAME' => 'u', 'DB_USER_PASSWORD' => 'p', 'ARASYA_ALLOWED_ORIGINS' => ['https://staff.arasyahome.ro'],
            'ARASYA_SOURCE_SECRET_TRENDHOME' => SOURCE_TEST_TRENDHOME_SECRET, 'ARASYA_SOURCE_MODE_TRENDHOME' => 'active', 'ARASYA_SOURCE_AUTHORITY_TRENDHOME' => 'enforce', 'ARASYA_SOURCE_QR_AUTHORITY_TRENDHOME' => 'observe',
            'ARASYA_SOURCE_SECRET_OUTLETPERDELE' => SOURCE_TEST_OUTLET_SECRET, 'ARASYA_SOURCE_MODE_OUTLETPERDELE' => 'active',
        ], JSON_THROW_ON_ERROR));
        chmod($home . '/arasya-config/secrets.json', 0600);
        $file = SourceRegistry::fromConfig(Config::fromEnvironment(new ConfigLoader(['HOME' => $home])));
        expect($file->find('trendhome')?->qrAuthorityMode === QrAuthorityMode::Observe && $file->find('trendhome')?->authorityMode === ProductionAuthorityMode::Enforce, 'ARASYA_SOURCE_QR_AUTHORITY_<SOURCE> is read from the private configuration file.');
        expect($file->find('outletperdele')?->qrAuthorityMode === QrAuthorityMode::Legacy && $file->issues() === []);
    } finally {
        @unlink($home . '/arasya-config/secrets.json');
        @rmdir($home . '/arasya-config');
        @rmdir($home);
    }
});

test('heartbeat reports the source authority mode without changing the contract', function (): void {
    ['controller' => $controller, 'pdo' => $pdo] = sourceTestController(sourceTestConfig(['trendhome' => ['mode' => 'active', 'authority' => 'enforce'], 'outletperdele' => ['authority' => 'observe']]));
    $pdo->exec("CREATE TABLE order_sources (source_key TEXT PRIMARY KEY, status TEXT NOT NULL, last_contact_at TEXT NULL, updated_at TEXT NULL)");
    $pdo->exec("INSERT INTO order_sources (source_key, status) VALUES ('trendhome', 'active'), ('outletperdele', 'active')");
    $home = $controller->heartbeat(sourceSignedRequest(['sentAt' => '2026-10-07T10:00:00Z'], SOURCE_TEST_TRENDHOME_SECRET), 'trendhome');
    $outlet = $controller->heartbeat(sourceSignedRequest(['sentAt' => '2026-10-07T10:00:00Z'], SOURCE_TEST_OUTLET_SECRET), 'outletperdele');
    expect($home->payload['productionAuthorityMode'] === 'enforce' && $home->payload['mode'] === 'active' && $home->payload['contract']['stageCount'] === 14);
    expect($outlet->payload['productionAuthorityMode'] === 'observe' && $outlet->payload['mode'] === 'validation');
    expect($home->payload['qrAuthorityMode'] === 'legacy' && $outlet->payload['qrAuthorityMode'] === 'legacy', 'The heartbeat also reports the QR authority mode (legacy by default).');
});

test('signed order authority answers production facts only and rejects malformed requests without storage', function (): void {
    ['controller' => $controller, 'pdo' => $pdo] = sourceTestController(sourceTestConfig(['trendhome' => ['mode' => 'validation', 'authority' => 'observe'], 'outletperdele' => []]));
    foreach ([['orderIds' => []], ['orderIds' => array_map('strval', range(1, 51))], ['orderIds' => ['1', '1']], ['orderIds' => ['bad id']], ['orderIds' => [63366]], ['orderIds' => ['1'], 'x' => 1], ['ids' => ['1']]] as $bad) {
        sourceExpectApi('SOURCE_PAYLOAD_INVALID', 422, fn () => $controller->orderAuthority(sourceSignedRequest($bad, SOURCE_TEST_TRENDHOME_SECRET), 'trendhome'));
    }
    sourceExpectApi('SOURCE_SIGNATURE_INVALID', 401, fn () => $controller->orderAuthority(sourceSignedRequest(['orderIds' => ['1']], SOURCE_TEST_OUTLET_SECRET), 'trendhome'));
    expect($pdo->statements === [], 'Rejected authority requests never reach storage.');

    $pdo->exec("CREATE TABLE operational_orders (global_order_id TEXT PRIMARY KEY, source_key TEXT NOT NULL, production_authority TEXT NOT NULL, production_stage_id TEXT NOT NULL, production_version INTEGER NOT NULL, operational_status TEXT NOT NULL, production_completed_at TEXT NULL)");
    $pdo->exec("INSERT INTO operational_orders VALUES ('trendhome:63366', 'trendhome', 'operations', 'ironing', 4, 'in_progress', NULL), ('trendhome:63360', 'trendhome', 'source', 'waiting', 1, 'unavailable', NULL), ('outletperdele:63366', 'outletperdele', 'operations', 'delivery', 9, 'in_progress', '2026-10-07 10:00:00')");
    $before = count($pdo->statements);
    // Validation-mode sources may ask: the call is read-only.
    $response = $controller->orderAuthority(sourceSignedRequest(['orderIds' => ['63366', '63360', '1']], SOURCE_TEST_TRENDHOME_SECRET), 'trendhome');
    expect($response->status === 200 && $response->payload['ok'] === true && $response->payload['sourceKey'] === 'trendhome' && $response->payload['productionAuthorityMode'] === 'observe');
    expect($response->payload['orders'] === [
        ['orderId' => '63366', 'globalOrderId' => 'trendhome:63366', 'exists' => true, 'productionAuthority' => 'operations', 'productionStageId' => 'ironing', 'productionVersion' => 4, 'operationalStatus' => 'in_progress', 'productionCompleted' => false],
        ['orderId' => '63360', 'globalOrderId' => 'trendhome:63360', 'exists' => true, 'productionAuthority' => 'source', 'productionStageId' => 'waiting', 'productionVersion' => 1, 'operationalStatus' => 'unavailable', 'productionCompleted' => false],
        ['orderId' => '1', 'globalOrderId' => 'trendhome:1', 'exists' => false, 'productionAuthority' => null, 'productionStageId' => null, 'productionVersion' => null, 'operationalStatus' => null, 'productionCompleted' => false],
    ], 'Unexpected authority answer: ' . sourceJson($response));
    $writes = array_filter(array_slice($pdo->statements, $before), static fn (string $sql): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE|BEGIN)/i', $sql) === 1);
    expect($writes === [], 'The authority answer never writes, not even the contact time.');
    expect(sourceResponseHasNoSecret($response) && !str_contains(sourceJson($response), 'ARASYA:Q1'), 'No secret and no QR value is returned.');
});
