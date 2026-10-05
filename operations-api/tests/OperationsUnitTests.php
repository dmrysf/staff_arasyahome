<?php

declare(strict_types=1);

// Pure Staff operations tests (no database). Included by run.php.

use Arasya\Operations\Activity\ActivityRange;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Config\TrendyolCredentials;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiKernel;
use Arasya\Operations\Http\AuthController;
use Arasya\Operations\Http\CorsPolicy;
use Arasya\Operations\Http\HealthController;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Integration\SourceOrderPayloadMapper;
use Arasya\Operations\Integration\SourceSignatureVerifier;
use Arasya\Operations\Integration\Trendyol\TrendyolClient;
use Arasya\Operations\Integration\Trendyol\TrendyolOrderMapper;
use Arasya\Operations\Integration\Trendyol\TrendyolTransport;
use Arasya\Operations\Order\EmployeeOrderRelation;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Order\OperationalOrder;
use Arasya\Operations\Order\OrderAccessPolicy;
use Arasya\Operations\Order\OrderFreshness;
use Arasya\Operations\Order\OrderLookupCode;
use Arasya\Operations\Order\OrderOperationsService;
use Arasya\Operations\Order\OrderSerializer;
use Arasya\Operations\Order\QrReference;
use Arasya\Operations\Security\CookiePolicy;
use Arasya\Operations\Security\CsrfGuard;
use Arasya\Operations\Security\SessionTokenManager;
use Arasya\Operations\Support\StructuredLogger;

function operationsEmployee(array $stages, array $permissions = ['orders.scan', 'orders.view_mine', 'orders.claim', 'orders.advance_stage', 'orders.handover', 'history.view_mine', 'profile.view_self'], string $uuid = '11111111-1111-4111-8111-111111111111', string $status = 'active'): EmployeeIdentity
{
    return new EmployeeIdentity($uuid, null, 'ana', 'ana', 'hash', 'Ana', 'pregatire-material', 'Pregătire', 'active', 'employee', 'active', $status, $permissions, $stages);
}

function operationsOrder(string $stage, ?string $owner = null, ?EmployeeOrderRelation $relation = null, string $operationalStatus = 'in_progress', ?DateTimeImmutable $completedAt = null): OperationalOrder
{
    $now = new DateTimeImmutable('2026-10-04T08:00:00Z');
    return new OperationalOrder(
        '22222222-2222-4222-8222-222222222222',
        new GlobalOrderId('trendhome', '61833'),
        '61833',
        $stage,
        'processing',
        'Processing',
        null,
        $operationalStatus,
        new OrderFreshness('fresh', $now, $now),
        3,
        null,
        $now,
        [],
        $relation,
        2,
        $owner,
        $completedAt,
    );
}

test('QR references are opaque 128-bit Q1 payloads and parse strictly', function (): void {
    $reference = QrReference::generate();
    expect(preg_match('/^[A-Z2-7]{26}$/D', $reference->value) === 1);
    expect($reference->payload() === 'ARASYA:Q1:' . $reference->value);
    expect(QrReference::parsePayload(' ' . strtolower($reference->payload()) . "\n")?->value === $reference->value);
    expect(QrReference::generate()->value !== $reference->value);
    foreach (['', 'arasya:61833', 'ARASYA:Q1:', 'ARASYA:Q1:' . str_repeat('A', 25), 'ARASYA:Q1:' . str_repeat('A', 27), 'ARASYA:Q1:' . str_repeat('1', 26), 'ARASYA:Q2:' . str_repeat('A', 26), "ARASYA:Q1:AAAAAAAAAAAAAAAAAAAAAAAAA\0"] as $invalid) {
        expect(QrReference::parsePayload($invalid) === null, "Payload {$invalid} must be invalid.");
    }
    expectRuntime(fn () => QrReference::fromStored('lowercase-not-allowed-here'));
});

test('manual lookup codes are exact, bounded and never wildcards', function (): void {
    expect(OrderLookupCode::normalizeInput(' #61833 ') === '61833');
    expect(OrderLookupCode::normalizeInput('ty-1048') === 'TY-1048');
    expect(OrderLookupCode::fromOrderNumber('#ty-1048') === 'TY-1048');
    foreach (['', '%', '_', '61833%', "' OR 1=1 --", 'a b', str_repeat('1', 121), '../x', "618\n33"] as $invalid) {
        expect(OrderLookupCode::normalizeInput($invalid) === null, "Lookup {$invalid} must be invalid.");
    }
});

test('idempotency keys require bounded opaque values', function (): void {
    expect(OrderOperationsService::isValidIdempotencyKey('0f4c8d7e-5d1a-4e37-9c0b-8a1d2e3f4a5b'));
    foreach (['', 'short', str_repeat('a', 101), 'key with spaces 123456', 'key/with/slash/123456'] as $invalid) {
        expect(!OrderOperationsService::isValidIdempotencyKey($invalid));
    }
});

test('order access policy derives visibility and the only allowed action server-side', function (): void {
    $policy = new OrderAccessPolicy(new AuthorizationService());
    $workflow = canonicalWorkflowFixture();
    $employee = operationsEmployee(['material-preparation', 'delivery']);
    $self = $employee->employeeUuid;
    $other = '33333333-3333-4333-8333-333333333333';

    expect($policy->canView($employee, operationsOrder('material-preparation')));
    expect(!$policy->canView($employee, operationsOrder('labeling')));
    $relation = new EmployeeOrderRelation($self, 'handover_out', 'active', new DateTimeImmutable(), new DateTimeImmutable());
    expect($policy->canView($employee, operationsOrder('labeling', null, $relation)));
    $foreignRelation = new EmployeeOrderRelation($other, 'claimed', 'active', new DateTimeImmutable(), new DateTimeImmutable());
    expect(!$policy->canView($employee, operationsOrder('labeling', null, $foreignRelation)));
    expect(!$policy->canView(operationsEmployee(['material-preparation'], status: 'suspended'), operationsOrder('material-preparation')));

    expect($policy->evaluate($employee, operationsOrder('material-preparation'), $workflow) === ['action' => 'claim', 'blockedReason' => null]);
    expect($policy->evaluate($employee, operationsOrder('material-preparation', $self), $workflow) === ['action' => 'complete_stage', 'blockedReason' => null]);
    expect($policy->evaluate($employee, operationsOrder('delivery', $self), $workflow) === ['action' => 'complete_production', 'blockedReason' => null]);
    expect($policy->evaluate($employee, operationsOrder('material-preparation', $other), $workflow)['blockedReason'] === 'claimed_by_other');
    expect($policy->evaluate($employee, operationsOrder('labeling', null, $relation), $workflow)['blockedReason'] === 'stage_not_allowed');
    expect($policy->evaluate($employee, operationsOrder('material-preparation', null, null, 'unavailable'), $workflow)['blockedReason'] === 'order_unavailable');
    expect($policy->evaluate($employee, operationsOrder('delivery', null, null, 'in_progress', new DateTimeImmutable()), $workflow)['blockedReason'] === 'production_completed');
    expect($policy->evaluate($employee, operationsOrder('material-preparation'), null)['blockedReason'] === 'workflow_unavailable');
    $viewer = operationsEmployee(['material-preparation'], ['orders.scan', 'orders.view_mine']);
    expect($policy->evaluate($viewer, operationsOrder('material-preparation'), $workflow)['blockedReason'] === 'permission_missing');
    expect($policy->evaluate($viewer, operationsOrder('material-preparation', $viewer->employeeUuid), $workflow)['blockedReason'] === 'permission_missing');
});

test('order serialization exposes the action and completion without other employee identities', function (): void {
    $order = operationsOrder('delivery', '33333333-3333-4333-8333-333333333333', null, 'in_progress', new DateTimeImmutable('2026-10-04T10:00:00Z'));
    $payload = (new OrderSerializer())->serializeOrder($order, ['action' => null, 'blockedReason' => 'production_completed']);
    expect($payload['status'] === 'handed_over' && $payload['productionCompletedAt'] === '2026-10-04T10:00:00.000Z');
    expect($payload['productionVersion'] === 2 && $payload['employeeActionBlockedReason'] === 'production_completed' && !isset($payload['employeeAllowedAction']));
    expect(!str_contains(json_encode($payload, JSON_THROW_ON_ERROR), '33333333-3333'));
    expect((new OrderSerializer())->serializeOrder(operationsOrder('waiting', null, null, 'unavailable'))['status'] === 'unavailable');
});

test('source freshness is deterministic and recovers on contact', function (): void {
    $now = new DateTimeImmutable('2026-10-04T12:00:00Z');
    expect(OrderFreshness::classify('active', $now->modify('-5 minutes'), $now, 900, 3600) === 'fresh');
    expect(OrderFreshness::classify('active', $now->modify('-30 minutes'), $now, 900, 3600) === 'stale');
    expect(OrderFreshness::classify('active', $now->modify('-2 hours'), $now, 900, 3600) === 'source_unavailable');
    expect(OrderFreshness::classify('inactive', $now, $now, 900, 3600) === 'source_unavailable');
});

test('activity ranges use Bucharest calendar days, including DST boundaries', function (): void {
    $summer = ActivityRange::resolve('today', null, null, new DateTimeImmutable('2026-07-10T22:30:00Z'));
    expect($summer->fromUtc->format('Y-m-d H:i') === '2026-07-10 21:00' && $summer->toUtc->format('Y-m-d H:i') === '2026-07-11 21:00');
    $winter = ActivityRange::resolve('today', null, null, new DateTimeImmutable('2026-01-10T10:00:00Z'));
    expect($winter->fromUtc->format('Y-m-d H:i') === '2026-01-09 22:00');
    $dst = ActivityRange::resolve('custom', '2026-10-25', '2026-10-25', new DateTimeImmutable('2026-10-26T10:00:00Z'));
    expect($dst->fromUtc->format('Y-m-d H:i') === '2026-10-24 21:00' && $dst->toUtc->format('Y-m-d H:i') === '2026-10-25 22:00');
    $week = ActivityRange::resolve('7days', null, null, new DateTimeImmutable('2026-10-04T10:00:00Z'));
    expect($week->fromUtc->format('Y-m-d') === '2026-09-27');
    $month = ActivityRange::resolve('month', null, null, new DateTimeImmutable('2026-10-04T10:00:00Z'));
    expect($month->fromUtc->format('Y-m-d H:i') === '2026-09-30 21:00');
    expectApi('INVALID_RANGE', fn () => ActivityRange::resolve('custom', '2026-10-05', '2026-10-04', new DateTimeImmutable()));
    expectApi('INVALID_RANGE', fn () => ActivityRange::resolve('custom', '2026-01-01', '2026-06-01', new DateTimeImmutable()));
    expectApi('INVALID_RANGE', fn () => ActivityRange::resolve('custom', '2026-02-30', '2026-03-01', new DateTimeImmutable()));
    expectApi('INVALID_RANGE', fn () => ActivityRange::resolve('today', '2026-10-01', null, new DateTimeImmutable()));
    expectApi('INVALID_RANGE', fn () => ActivityRange::resolve('year', null, null, new DateTimeImmutable()));
});

test('source signatures are HMAC-bound to timestamp and raw body with bounded skew', function (): void {
    $secret = str_repeat('s', 40);
    $verifier = new SourceSignatureVerifier(['trendhome' => $secret]);
    $now = new DateTimeImmutable('@1790000000');
    $body = '{"schemaVersion":1}';
    $verifier->verify('trendhome', '1790000000', SourceSignatureVerifier::sign($secret, '1790000000', $body), $body, $now);
    expectApi('SOURCE_SIGNATURE_INVALID', fn () => $verifier->verify('trendhome', '1790000000', SourceSignatureVerifier::sign($secret, '1790000000', $body), $body . ' ', $now));
    expectApi('SOURCE_SIGNATURE_INVALID', fn () => $verifier->verify('trendhome', '1789999000', SourceSignatureVerifier::sign($secret, '1789999000', $body), $body, $now));
    expectApi('SOURCE_SIGNATURE_INVALID', fn () => $verifier->verify('trendhome', '1790000000', null, $body, $now));
    expectApi('SOURCE_SIGNATURE_INVALID', fn () => $verifier->verify('trendhome', 'abc', 'v1=' . str_repeat('0', 64), $body, $now));
    expectApi('SOURCE_NOT_CONFIGURED', fn () => $verifier->verify('outletperdele', '1790000000', 'v1=' . str_repeat('0', 64), $body, $now));
});

test('source payload mapping is strict, deterministic and never infers production from commerce', function (): void {
    $payload = [
        'schemaVersion' => 1,
        'eventId' => 'wc-61833-1',
        'changedAt' => '2026-10-04T10:00:00+03:00',
        'order' => [
            'id' => 61833,
            'number' => '61833',
            'status' => ['code' => 'completed', 'label' => 'Finalizată'],
            'availability' => 'active',
            'items' => [['id' => 9, 'line' => 1, 'name' => 'Draperie', 'sku' => 'DV-302', 'width' => 300, 'height' => 260.5, 'unit' => 'cm', 'meters' => 8.4, 'quantity' => 1]],
        ],
    ];
    $snapshot = SourceOrderPayloadMapper::map('trendhome', $payload);
    expect($snapshot->productionStageId === null && $snapshot->sourceCommerceStatusCode === 'completed');
    expect($snapshot->sourceChangedAt->format('Y-m-d\TH:i:s\Z') === '2026-10-04T07:00:00Z');
    expect($snapshot->sourceOrderId === '61833' && $snapshot->items[0]->heightValue === 260.5);
    $withStage = SourceOrderPayloadMapper::map('trendhome', [...$payload, 'production' => ['workflowKey' => 'curtain-production', 'workflowVersion' => 1, 'stageId' => 'labeling', 'stageLabel' => 'Ignored']]);
    expect($withStage->productionStageId === 'labeling');
    expect(SourceOrderPayloadMapper::map('trendhome', ['schemaVersion' => 1, 'eventId' => 'e', 'changedAt' => '2026-10-04T10:00:00Z', 'order' => [...$payload['order'], 'availability' => 'cancelled']])->operationalStatus === 'unavailable');
    expectApi('SOURCE_STAGE_UNKNOWN', fn () => SourceOrderPayloadMapper::map('trendhome', [...$payload, 'production' => ['workflowKey' => 'curtain-production', 'workflowVersion' => 2, 'stageId' => 'labeling']]));
    expectApi('SOURCE_STAGE_UNKNOWN', fn () => SourceOrderPayloadMapper::map('trendhome', [...$payload, 'production' => ['workflowKey' => 'other', 'workflowVersion' => 1, 'stageId' => 'labeling']]));
    expectApi('SOURCE_SCHEMA_UNSUPPORTED', fn () => SourceOrderPayloadMapper::map('trendhome', [...$payload, 'schemaVersion' => 2]));
    expectApi('SOURCE_PAYLOAD_INVALID', fn () => SourceOrderPayloadMapper::map('trendhome', [...$payload, 'extra' => 1]));
    expectApi('SOURCE_PAYLOAD_INVALID', fn () => SourceOrderPayloadMapper::map('trendhome', [...$payload, 'changedAt' => 'yesterday']));
    expectApi('SOURCE_PAYLOAD_INVALID', fn () => SourceOrderPayloadMapper::map('trendhome', ['schemaVersion' => 1, 'eventId' => 'e', 'changedAt' => '2026-10-04T10:00:00Z', 'order' => [...$payload['order'], 'availability' => 'maybe']]));
    expectApi('SOURCE_PAYLOAD_INVALID', fn () => SourceOrderPayloadMapper::map('trendhome', ['schemaVersion' => 1, 'eventId' => 'e', 'changedAt' => '2026-10-04T10:00:00Z', 'order' => [...$payload['order'], 'items' => [['id' => 9, 'line' => 1, 'name' => 'X', 'quantity' => 0]]]]));
    expectApi('SOURCE_PAYLOAD_INVALID', fn () => SourceOrderPayloadMapper::map('trendhome', ['schemaVersion' => 1, 'eventId' => 'e', 'changedAt' => '2026-10-04T10:00:00Z', 'order' => [...$payload['order'], 'items' => [['id' => 9, 'line' => 1, 'name' => 'X', 'quantity' => 1, 'unit' => 'inch']]]]));
    expectApi('SOURCE_PAYLOAD_INVALID', fn () => SourceOrderPayloadMapper::map('trendhome', ['schemaVersion' => 1, 'eventId' => 'e', 'changedAt' => '2026-10-04T10:00:00Z', 'order' => [...$payload['order'], 'id' => '61/833']]));
});

test('Trendyol adapter maps fixture packages and authenticates without exposing credentials', function (): void {
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/trendyol-packages.json'), true, 64, JSON_THROW_ON_ERROR);
    $picking = TrendyolOrderMapper::map($fixture['content'][0]);
    expect($picking->sourceKey === 'trendyol' && $picking->sourceOrderId === '3318470214' && $picking->orderNumber === '10847291463');
    expect($picking->productionStageId === null && $picking->sourceCommerceStatusCode === 'Picking' && $picking->operationalStatus === 'in_progress');
    expect($picking->sourceEventId === 'package-3318470214-1759568400123' && $picking->sourceChangedAt->format('Y-m-d\TH:i:s.v\Z') === '2025-10-04T09:00:00.123Z');
    expect($picking->items[0]->productCode === 'PT-300-260' && $picking->items[0]->variant === '300x260' && $picking->items[0]->quantity === 2);
    expect(TrendyolOrderMapper::map($fixture['content'][1])->operationalStatus === 'unavailable');
    expectRuntime(fn () => TrendyolOrderMapper::map(['id' => 'x']));

    expect(TrendyolCredentials::fromValues('', '', '', '') === null);
    expectRuntime(fn () => TrendyolCredentials::fromValues('123', 'key', '', ''));
    expectRuntime(fn () => TrendyolCredentials::fromValues('123', 'key', 'secret', 'http://apigw.trendyol.com'));
    $credentials = TrendyolCredentials::fromValues('123456', 'api-key', 'api-secret', '');
    $transport = new class ($fixture) implements TrendyolTransport {
        public array $requests = [];
        public int $status = 200;
        public function __construct(private array $fixture)
        {
        }
        public function get(string $url, array $headers): array
        {
            $this->requests[] = [$url, $headers];
            return ['status' => $this->status, 'body' => json_encode($this->fixture, JSON_THROW_ON_ERROR)];
        }
    };
    $page = (new TrendyolClient($credentials, $transport))->packages(1000, 2000, 0);
    expect(count($page['content']) === 2 && $page['totalPages'] === 1);
    [$url, $headers] = $transport->requests[0];
    expect(str_starts_with($url, 'https://apigw.trendyol.com/integration/order/sellers/123456/orders?startDate=1000&endDate=2000&page=0&size=200'));
    expect($headers['Authorization'] === 'Basic ' . base64_encode('api-key:api-secret') && $headers['User-Agent'] === '123456 - SelfIntegration');
    $transport->status = 401;
    try {
        (new TrendyolClient($credentials, $transport))->packages(1000, 2000, 0);
        throw new RuntimeException('Expected Trendyol authentication failure.');
    } catch (RuntimeException $error) {
        expect($error->getMessage() === 'TRENDYOL_AUTH_FAILED' && !str_contains($error->getMessage(), 'api-secret'));
    }
});

test('operations routes fail closed, reject wrong methods and keep integrations outside browser CORS', function (): void {
    [$auth, $employees] = authFixture();
    $config = new Config('production', str_repeat('p', 32), 'localhost', 3306, 'db', 'user', 'pass', ['https://staff.arasyahome.ro'], 3600, 300, 5, 30, 900, false, []);
    $context = new RequestContext();
    $kernel = new ApiKernel(
        new AuthController($auth, new CsrfGuard(new SessionTokenManager(str_repeat('p', 32))), new CookiePolicy($config), $config, new AuthorizationService(), $context),
        new HealthController(new PDO('sqlite::memory:'), new \Arasya\Operations\Support\SystemClock()),
        new CorsPolicy($config->allowedOrigins),
        new StructuredLogger(static fn (string $line): null => null),
        new CookiePolicy($config),
        $context,
    );
    $request = static fn (string $method, string $path, array $headers = []): Request => new Request($method, $path, $headers, [], '', '127.0.0.1', 'test', 'req-ops', []);
    expect($kernel->handle($request('GET', '/orders/mine'))->payload['error']['code'] === 'SERVICE_UNAVAILABLE');
    expect($kernel->handle($request('GET', '/activity/mine'))->payload['error']['code'] === 'SERVICE_UNAVAILABLE');
    expect($kernel->handle($request('POST', '/orders/trendhome:1/claim'))->payload['error']['code'] === 'ORIGIN_DENIED');
    expect($kernel->handle($request('POST', '/orders/trendhome:1/claim', ['origin' => 'https://staff.arasyahome.ro']))->payload['error']['code'] === 'SERVICE_UNAVAILABLE');
    expect($kernel->handle($request('GET', '/orders/trendhome:1/claim'))->status === 405);
    expect($kernel->handle($request('DELETE', '/orders/trendhome:1', ['origin' => 'https://staff.arasyahome.ro']))->status === 405);
    expect($kernel->handle($request('POST', '/integrations/sources/trendhome/orders'))->payload['error']['code'] === 'SERVICE_UNAVAILABLE');
    expect($kernel->handle($request('POST', '/integrations/sources/Trend%20home/orders'))->status === 404);
    $preflight = $kernel->handle($request('OPTIONS', '/orders/trendhome:1/transition', ['origin' => 'https://staff.arasyahome.ro', 'access-control-request-method' => 'POST', 'access-control-request-headers' => 'content-type, idempotency-key, x-csrf-token, x-request-id']));
    expect($preflight->status === 204 && $preflight->headers['Access-Control-Allow-Origin'] === 'https://staff.arasyahome.ro');
    expect($kernel->handle($request('OPTIONS', '/orders/trendhome:1/transition', ['origin' => 'https://evil.example', 'access-control-request-method' => 'POST']))->status === 403);
    unset($employees);
});

test('configuration validates source secrets and Trendyol credentials fail closed', function (): void {
    expectRuntime(fn () => new Config('production', str_repeat('p', 32), 'localhost', 3306, 'db', 'u', 'p', ['https://staff.arasyahome.ro'], 3600, 300, 5, 30, 900, false, [], sourceSecrets: ['trendhome' => 'short']));
    expectRuntime(fn () => new Config('production', str_repeat('p', 32), 'localhost', 3306, 'db', 'u', 'p', ['https://staff.arasyahome.ro'], 3600, 300, 5, 30, 900, false, [], sourceFreshSeconds: 600, sourceUnavailableSeconds: 60));
    $config = new Config('production', str_repeat('p', 32), 'localhost', 3306, 'db', 'u', 'p', ['https://staff.arasyahome.ro'], 3600, 300, 5, 30, 900, false, [], sourceSecrets: ['trendhome' => str_repeat('x', 32)]);
    expect($config->sourceSecrets === ['trendhome' => str_repeat('x', 32)] && $config->trendyol === null);
});

test('WooCommerce connector payloads satisfy the Operations source contract and signature', function (): void {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }
    require_once dirname(__DIR__, 2) . '/integrations/woocommerce/arasya-operations-connector.php';
    $item = new class {
        public function get_product(): ?object { return new class { public function is_type(string $type): bool { return false; } public function get_sku(): string { return 'DV-302'; } }; }
        public function get_meta(string $key, bool $single): string { return ['_arasya_width' => '300', '_arasya_height' => '260,5', '_arasya_unit' => 'cm', '_arasya_meters' => '8.4'][$key] ?? ''; }
        public function get_name(): string { return 'Draperie Velvet'; }
        public function get_quantity(): int { return 2; }
    };
    $order = new class ($item) {
        public function __construct(private object $item) {}
        public function get_status(): string { return 'processing'; }
        public function get_items(): array { return [981 => $this->item]; }
        public function get_date_created(): DateTimeImmutable { return new DateTimeImmutable('2026-10-04T10:00:00+03:00'); }
        public function get_customer_note(): string { return 'Sună la 0712 345 678'; }
        public function get_meta(string $key, bool $single): string { return $key === '_arasya_production_notes' ? 'Tiv dublu' : ''; }
        public function get_id(): int { return 61833; }
        public function get_order_number(): string { return '61833'; }
    };
    $changedAt = arasya_ops_now();
    $payload = arasya_ops_build_payload($order, $changedAt);
    expect(($payload['order']['notes'] ?? null) === 'Tiv dublu' && !str_contains(json_encode($payload, JSON_THROW_ON_ERROR), '0712'));
    expect(!isset($payload['production']) && $payload['order']['availability'] === 'active' && $payload['eventId'] === 'wc-61833-' . preg_replace('/\D/', '', $changedAt));
    $withStatus = static fn (string $status): object => new class ($status) { public function __construct(private string $status) {} public function get_status(): string { return $this->status; } };
    expect(arasya_ops_should_send($withStatus('processing')) && arasya_ops_should_send($withStatus('se-proceseaza')) && arasya_ops_should_send($withStatus('expediat')) && arasya_ops_should_send($withStatus('cancelled')));
    expect(!arasya_ops_should_send($withStatus('pending')) && !arasya_ops_should_send($withStatus('checkout-draft')) && !arasya_ops_should_send($withStatus('failed')));
    $snapshot = SourceOrderPayloadMapper::map('trendhome', $payload);
    expect($snapshot->sourceOrderId === '61833' && $snapshot->productionStageId === null && $snapshot->items[0]->heightValue === 260.5 && $snapshot->items[0]->productCode === 'DV-302' && $snapshot->items[0]->quantity === 2);
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signed = arasya_ops_sign(str_repeat('k', 64), $body, 1790000000);
    (new SourceSignatureVerifier(['trendhome' => str_repeat('k', 64)]))->verify('trendhome', $signed['timestamp'], $signed['signature'], $body, new DateTimeImmutable('@1790000100'));
});

test('WC Kalkulator mapping turns real Trendhome L/H/manopera meta into Operations measurements and notes', function (): void {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }
    require_once dirname(__DIR__, 2) . '/integrations/woocommerce/arasya-operations-connector.php';
    require_once dirname(__DIR__, 2) . '/integrations/woocommerce/arasya-operations-wc-kalkulator.php';
    // Meta shape observed on live Trendhome orders (WC Kalkulator 1.6.1).
    $item = static fn (array $meta, int $quantity, string $sku): object => new class ($meta, $quantity, $sku) {
        public function __construct(private array $meta, private int $quantity, private string $sku) {}
        public function get_product(): object { return new class ($this->sku) { public function __construct(private string $sku) {} public function get_sku(): string { return $this->sku; } }; }
        public function get_meta(string $key, bool $single): mixed { return $this->meta[$key] ?? ''; }
        public function get_quantity(): int { return $this->quantity; }
    };
    $voal = $item(['_wck_fields' => ['lungimea' => '4.0', 'inaltime' => '2.2', 'manopera' => '18:Manopera Rejansa Bara (Țeavă)', 'buc' => '0:1 buc.', '_files' => []], '_wck_stock_reduction_multiplier' => '4'], 2, 'SIENA-V3');
    $plain = $item([], 1, 'ACC-1');
    $explicit = $item(['_wck_fields' => '{"lungimea":"3","inaltime":"2.3","manopera":"13:Manopera Rejansa Normal 6cm","buc":"1:2 buc."}', '_wck_stock_reduction_multiplier' => '3'], 1, '3707-Visiniu');

    $empty = ['width' => null, 'height' => null, 'unit' => null, 'meters' => null];
    expect(arasya_wck_item_measurements($empty, $voal) === ['width' => 4.0, 'height' => 2.2, 'unit' => 'm', 'meters' => 8.0]);
    expect(arasya_wck_item_measurements($empty, $plain) === $empty);
    expect(arasya_wck_item_measurements($empty, $explicit) === ['width' => 3.0, 'height' => 2.3, 'unit' => 'm', 'meters' => 3.0]);
    $kept = ['width' => 300.0, 'height' => 260.0, 'unit' => 'cm', 'meters' => 8.4];
    expect(arasya_wck_item_measurements($kept, $voal) === $kept);

    $order = new class ([$voal, $plain, $explicit]) {
        public function __construct(private array $items) {}
        public function get_items(): array { return $this->items; }
    };
    expect(arasya_wck_production_notes('Tiv dublu', $order) === "Tiv dublu\nLinia 1 (SIENA-V3): Manopera Rejansa Bara (Țeavă), 1 buc.\nLinia 3 (3707-Visiniu): Manopera Rejansa Normal 6cm, 2 buc.");
    expect(arasya_wck_production_notes('', new class { public function get_items(): array { return []; } }) === '');

    $snapshot = SourceOrderPayloadMapper::map('trendhome', ['schemaVersion' => 1, 'eventId' => 'wc-1-1', 'changedAt' => '2026-10-04T10:00:00.000000Z', 'order' => [
        'id' => 1, 'number' => '1', 'status' => ['code' => 'processing', 'label' => 'Procesare'], 'availability' => 'active',
        'notes' => arasya_wck_production_notes('', $order),
        'items' => [['id' => 9, 'line' => 1, 'name' => 'Perdea Voal', 'sku' => 'SIENA-V3', 'quantity' => 2] + arasya_wck_item_measurements($empty, $voal)],
    ]]);
    expect($snapshot->items[0]->widthValue === 4.0 && $snapshot->items[0]->heightValue === 2.2 && $snapshot->items[0]->measurementUnit === 'm' && $snapshot->items[0]->meters === 8.0);
});

test('central authorization gates temporary passwords, application scope and inactive identities', function (): void {
    $base = static fn (array $applications, bool $mustChange = false, string $status = 'active', bool $root = false, string $departmentStatus = 'active'): EmployeeIdentity => new EmployeeIdentity(
        'f2c7a9a0-2f1b-4c55-9a7e-111111111111', null, 'ana', 'ana', 'hash', 'Ana', 'productie', 'Producție', $departmentStatus, 'employee', 'active', $status,
        ['orders.scan', 'employees.view', 'dashboard.access'], [], $applications, $mustChange, 1, $root,
    );
    $authorization = new AuthorizationService();
    expect($authorization->can($base(['staff']), 'orders.scan'));
    expect(!$authorization->can($base(['dashboard']), 'orders.scan'), 'Staff permissions from a role are unusable without Staff access');
    expectApi('APPLICATION_ACCESS_DENIED', fn () => $authorization->require($base(['dashboard']), 'orders.scan'));
    expectApi('APPLICATION_ACCESS_DENIED', fn () => $authorization->requireApplication($base(['staff']), 'dashboard'));
    expectApi('PASSWORD_CHANGE_REQUIRED', fn () => $authorization->require($base(['staff'], true), 'orders.scan'));
    expectApi('PASSWORD_CHANGE_REQUIRED', fn () => $authorization->requireApplication($base(['dashboard'], true), 'dashboard'));
    expect(!$authorization->can($base(['staff'], false, 'inactive'), 'orders.scan'));
    expect(!$base(['staff'], false, 'active', false, 'inactive')->isOperationallyActive() && $base(['staff'], false, 'active', true, 'inactive')->isOperationallyActive(), 'root cannot be locked out through its department');
    expect(!$authorization->can($base(['staff']), 'system.manage'));
});

test('temporary passwords are long, mixed and unique; the chosen-password policy rejects weak values', function (): void {
    $seen = [];
    for ($i = 0; $i < 200; $i++) {
        $password = \Arasya\Operations\Iam\TemporaryPasswordGenerator::generate(24);
        expect(strlen($password) === 24 && preg_match('/[A-Z]/', $password) === 1 && preg_match('/[a-z]/', $password) === 1 && preg_match('/[2-9]/', $password) === 1 && preg_match('/[-_.!@#%+=]/', $password) === 1);
        expect(preg_match('/[O0Il1]/', $password) === 0, 'no ambiguous characters');
        $seen[$password] = true;
    }
    expect(count($seen) === 200);
    expectRuntime(fn () => \Arasya\Operations\Iam\TemporaryPasswordGenerator::generate(12));
    $policy = new \Arasya\Operations\Security\PasswordHasher();
    expect(!$policy->meetsPolicy('short pass') && !$policy->meetsPolicy('maria.ionescu-2026', 'maria.ionescu') && $policy->meetsPolicy('o frază lungă de 2026'));
});

test('CORS allows management methods only for exact origins and never with a wildcard', function (): void {
    $cors = new CorsPolicy(['https://staff.arasyahome.ro', 'https://dashboard.arasyahome.ro']);
    $preflight = static fn (string $origin, string $method, string $headers = 'content-type,x-csrf-token'): Request => new Request('OPTIONS', '/management/roles/1', ['origin' => $origin, 'access-control-request-method' => $method, 'access-control-request-headers' => $headers], [], '', '127.0.0.1', 'test', 'cors');
    foreach (['https://staff.arasyahome.ro', 'https://dashboard.arasyahome.ro'] as $origin) {
        foreach (['PATCH', 'PUT', 'DELETE'] as $method) {
            $response = $cors->preflight($preflight($origin, $method));
            expect($response !== null && $response->headers['Access-Control-Allow-Origin'] === $origin && $response->headers['Access-Control-Allow-Credentials'] === 'true');
        }
    }
    expectApi('ORIGIN_DENIED', fn () => $cors->preflight($preflight('https://dashboard.arasyahome.ro.evil.example', 'PATCH')));
    expectApi('ORIGIN_DENIED', fn () => $cors->preflight($preflight('https://dashboard.arasyahome.ro', 'TRACE')));
    expectApi('CORS_HEADER_DENIED', fn () => $cors->preflight($preflight('https://dashboard.arasyahome.ro', 'PATCH', 'x-employee-uuid')));
    expect($cors->headers('*') === [] && $cors->headers(null) === []);
});

test('CORS accepts the exact B2B origin next to Staff and Dashboard and rejects look-alikes', function (): void {
    $origins = ['https://staff.arasyahome.ro', 'https://dashboard.arasyahome.ro', 'https://b2b.arasyahome.ro'];
    $cors = new CorsPolicy($origins);
    foreach ($origins as $origin) {
        $response = $cors->preflight(new Request('OPTIONS', '/auth/login', ['origin' => $origin, 'access-control-request-method' => 'POST', 'access-control-request-headers' => 'content-type,x-csrf-token'], [], '', '127.0.0.1', 'test', 'cors-b2b'));
        expect($response !== null && $response->headers['Access-Control-Allow-Origin'] === $origin && $response->headers['Access-Control-Allow-Credentials'] === 'true');
        $cors->requireUnsafeOrigin(new Request('POST', '/auth/logout', ['origin' => $origin], [], '', '127.0.0.1', 'test', 'cors-b2b-post'));
    }
    foreach (['https://b2b.arasyahome.ro.evil.example', 'http://b2b.arasyahome.ro', 'https://b2b.arasyahome.ro/', 'https://b2b.arasyahome.ro:8443', 'https://evil.arasyahome.ro', 'https://arasyahome.ro', 'null'] as $hostile) {
        expect($cors->headers($hostile) === []);
        expectApi('ORIGIN_DENIED', fn () => $cors->requireUnsafeOrigin(new Request('POST', '/auth/login', ['origin' => $hostile], [], '{}', '127.0.0.1', 'test', 'cors-b2b-hostile')));
    }
});

test('B2B application access carries only its own baseline and no production permission', function (): void {
    $baseline = \Arasya\Operations\Iam\ApplicationAccess::baselineFor(['b2b']);
    sort($baseline);
    expect($baseline === ['b2b.access', 'profile.view_self']);
    foreach (\Arasya\Operations\Iam\ApplicationAccess::PERMISSION_APPLICATION as $permission => $application) {
        expect(!in_array($permission, $baseline, true));
        expect($application === 'b2b' ? (str_starts_with($permission, 'b2b.companies.') || str_starts_with($permission,'b2b.orders.')) : !str_starts_with($permission, 'b2b.'));
    }
    expect(!in_array('b2b.access', \Arasya\Operations\Iam\ApplicationAccess::baselineFor(['staff', 'dashboard']), true));
});

test('B2B company permissions need both B2B application access and the specific permission', function (): void {
    $authorization = new AuthorizationService();
    $identity = static fn (array $applications, array $permissions, bool $root = false): EmployeeIdentity => new EmployeeIdentity(
        employeeUuid: '00000000-0000-4000-8000-0000000000b2', employeeCode: null, username: 'seller', usernameNormalized: 'seller', passwordHash: 'x',
        displayName: 'Seller', departmentKey: 'vanzari', departmentName: 'Vânzări', departmentStatus: 'active', roleKey: 'employee', roleStatus: 'active',
        status: 'active', permissions: $permissions, allowedStageIds: [], applications: $applications, isRoot: $root,
    );
    foreach (\Arasya\Operations\B2B\CompanyAccess::ALL as $permission) {
        expect(\Arasya\Operations\Iam\ApplicationAccess::PERMISSION_APPLICATION[$permission] === 'b2b');
        expect(!in_array($permission, \Arasya\Operations\Iam\ApplicationAccess::baselineFor(['staff', 'dashboard', 'b2b']), true));
    }
    $roleOnly = $identity(['dashboard'], ['dashboard.access', 'b2b.companies.view']);
    expect(!$authorization->can($roleOnly, 'b2b.companies.view'));
    expectApi('APPLICATION_ACCESS_DENIED', fn () => \Arasya\Operations\B2B\CompanyAccess::require($authorization, $roleOnly, 'b2b.companies.view'));
    $gateOnly = $identity(['b2b'], ['b2b.access', 'profile.view_self']);
    expectApi('UNAUTHORIZED_ACTION', fn () => \Arasya\Operations\B2B\CompanyAccess::require($authorization, $gateOnly, 'b2b.companies.view'));
    $viewer = $identity(['b2b'], ['b2b.access', 'b2b.companies.view']);
    \Arasya\Operations\B2B\CompanyAccess::require($authorization, $viewer, 'b2b.companies.view');
    expectApi('UNAUTHORIZED_ACTION', fn () => \Arasya\Operations\B2B\CompanyAccess::require($authorization, $viewer, 'b2b.companies.create'));
    expect(\Arasya\Operations\B2B\CompanyAccess::granted($authorization, $viewer) === ['b2b.companies.view']);
    $root = $identity(['staff', 'dashboard', 'b2b'], ['b2b.access', ...\Arasya\Operations\B2B\CompanyAccess::ALL], true);
    expect(\Arasya\Operations\B2B\CompanyAccess::granted($authorization, $root) === \Arasya\Operations\B2B\CompanyAccess::ALL);
});

test('B2B company input is normalized on the server and failures name fields, never values', function (): void {
    $input = \Arasya\Operations\B2B\CompanyInput::class;
    expect($input::normalizeTaxIdentifier(' ro 12.345-678 ', 'RO') === '12345678');
    expect($input::normalizeTaxIdentifier('RO12345678', 'RO') === '12345678');
    expect($input::normalizeTaxIdentifier('12345678', 'RO') === '12345678');
    expect($input::normalizeTaxIdentifier('EL094014201', 'GR') === '094014201');
    expect($input::normalizeTaxIdentifier('ROMANIA', 'RO') === 'ROMANIA');
    expect($input::normalizeTaxIdentifier('1234567890', 'TR') === '1234567890');
    expect($input::normalizeTaxIdentifier('de 123/456', 'DE') === '123456');
    expect($input::normalizeTaxIdentifier('ș', 'RO') === null);
    expect(\Arasya\Operations\B2B\CountryCodes::normalize(' ro ') === 'RO' && \Arasya\Operations\B2B\CountryCodes::normalize('XX') === null && \Arasya\Operations\B2B\CountryCodes::normalize('ROU') === null);
    expect(count(\Arasya\Operations\B2B\CountryCodes::ALL) === 249);
    expect(\Arasya\Operations\B2B\CompanyCommands::code(1) === 'B2B-000001' && \Arasya\Operations\B2B\CompanyCommands::code(1234567) === 'B2B-1234567');

    $company = $input::company(['legalName' => '  S.C. Mobilă   Lux S.R.L. ', 'countryCode' => 'ro', 'taxIdentifier' => 'RO 123 456', 'website' => 'mobila.ro', 'internalNotes' => "Rând 1\r\nRând 2", 'displayName' => '  ']);
    expect($company['legalName'] === 'S.C. Mobilă   Lux S.R.L.', 'legal text is preserved apart from outer whitespace');
    expect($company['countryCode'] === 'RO' && $company['taxIdentifier'] === 'RO 123 456' && $company['taxIdentifierNormalized'] === '123456');
    expect($company['website'] === 'https://mobila.ro' && $company['displayName'] === null && $company['internalNotes'] === "Rând 1\nRând 2");

    $fields = static function (Closure $callback): array {
        try {
            $callback();
        } catch (\Arasya\Operations\Http\ApiException $error) {
            expect($error->errorCode === 'VALIDATION_FAILED' && $error->status === 422);
            expect(!str_contains(json_encode($error->details), 'secret-value'));
            return $error->details['fields'] ?? [];
        }
        throw new RuntimeException('Expected VALIDATION_FAILED.');
    };
    expect($fields(fn () => $input::company([])) === ['countryCode' => 'required', 'legalName' => 'required', 'taxIdentifier' => 'required']);
    expect($fields(fn () => $input::company(['legalName' => str_repeat('a', 256), 'countryCode' => 'XX', 'taxIdentifier' => 'secret-value<>', 'website' => 'javascript:alert(1)']))
        === ['countryCode' => 'invalid', 'legalName' => 'too_long', 'taxIdentifier' => 'invalid', 'website' => 'invalid']);
    expect($fields(fn () => $input::company(['legalName' => "Line\nbreak", 'countryCode' => 'RO', 'taxIdentifier' => '1', 'internalNotes' => str_repeat('n', 5001)])) === ['internalNotes' => 'too_long', 'legalName' => 'invalid']);
    expect($fields(fn () => $input::contact(['name' => 'Ana', 'email' => 'secret-value', 'phone' => '12', 'isPrimary' => 'yes'])) === ['email' => 'invalid', 'isPrimary' => 'invalid', 'phone' => 'invalid']);
    $contact = $input::contact(['name' => ' Ana Pop ', 'email' => ' Ana.Pop@Example.RO ', 'phone' => '+40 721 000 111']);
    expect($contact['email'] === 'ana.pop@example.ro' && $contact['phone'] === '+40 721 000 111' && $contact['isPrimary'] === false && $contact['jobTitle'] === null);
    expect($fields(fn () => $input::address(['type' => 'warehouse', 'countryCode' => 'RO'])) === ['addressLine1' => 'required', 'city' => 'required', 'type' => 'invalid']);
    expect($fields(fn () => $input::creation(['legalName' => 'X', 'countryCode' => 'RO', 'taxIdentifier' => '1', 'contact' => ['name' => ''], 'address' => ['unknown' => 1]])) === ['address' => 'invalid', 'contact.name' => 'required']);
    $creation = $input::creation(['legalName' => 'X', 'countryCode' => 'TR', 'taxIdentifier' => '1234567890', 'contact' => null]);
    expect($creation['contact'] === null && $creation['address'] === null);
});

test('B2B commercial orders are isolated from production and their audit is insert-only', function (): void {
    $root = dirname(__DIR__) . '/src';
    foreach (glob($root . '/B2B/Order*.php') as $file) {
        $source = (string) file_get_contents($file);
        expect(preg_match('/\b(operational_orders|operational_order_items|production_workflows|production_stages|order_activity_events|order_operation_idempotency)\b/', $source) !== 1, basename($file) . ' must not access production tables');
        expect(preg_match('/\b(UPDATE|DELETE\s+FROM)\s+b2b_order_activity_events\b/i', $source) !== 1, 'Order history is insert-only');
        expect(preg_match('/\bDELETE\s+FROM\s+b2b_orders\b/i', $source) !== 1, 'Commercial orders have no hard delete');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $source = (string) file_get_contents($file->getPathname());
        if (!str_starts_with($relative, 'B2B/') && $relative !== 'Database/AuthMaintenance.php')
            expect(preg_match('/\bb2b_order/', $source) !== 1, $relative . ' must not read commercial data');
    }
    $maintenance = (string) file_get_contents($root . '/Database/AuthMaintenance.php');
    preg_match_all('/b2b_order\w+/', $maintenance, $tables);
    $tableNames = array_filter($tables[0], static fn($name) => !str_ends_with($name, '_keys'));
    expect(array_values(array_unique($tableNames)) === ['b2b_order_idempotency']);
});

test('B2B company data stays inside the B2B module and its activity is insert-only', function (): void {
    $root = dirname(__DIR__) . '/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $readers = [];
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (preg_match('/\bb2b_compan/', $source) === 1) {
            $readers[] = $relative;
        }
        expect(preg_match('/\b(UPDATE|DELETE\s+FROM)\s+b2b_company_activity_events\b/i', $source) !== 1, "{$relative} must not change B2B activity");
        if (!str_starts_with($relative, 'B2B/') && $relative !== 'Database/AuthMaintenance.php') {
            expect(preg_match('/\bb2b_compan/', $source) !== 1, "{$relative} must not read B2B company data");
        }
    }
    sort($readers);
    expect($readers === ['B2B/CompanyCommands.php', 'B2B/CompanyQueries.php', 'B2B/OrderCommands.php', 'B2B/OrderStore.php', 'Database/AuthMaintenance.php']);
    $maintenance = (string) file_get_contents($root . '/Database/AuthMaintenance.php');
    expect(preg_match_all('/b2b_compan\w+/', $maintenance, $tables) >= 1 && array_unique($tables[0]) === ['b2b_company_idempotency']);
});
