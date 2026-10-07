<?php

declare(strict_types=1);

use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Audit\AuditLogger;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Bootstrap\RuntimeLocator;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Config\ConfigLoader;
use Arasya\Operations\Employee\EmployeeAdminService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\EmployeeSerializer;
use Arasya\Operations\Database\AuthMaintenance;
use Arasya\Operations\Database\MigrationStatus;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\ApiKernel;
use Arasya\Operations\Http\AuthController;
use Arasya\Operations\Http\CorsPolicy;
use Arasya\Operations\Http\HealthController;
use Arasya\Operations\Http\ProductionWorkflowController;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Production\ProductionStage;
use Arasya\Operations\Production\ProductionWorkflow;
use Arasya\Operations\Production\ProductionWorkflowRepository;
use Arasya\Operations\Production\ProductionWorkflowService;
use Arasya\Operations\Production\PdoProductionWorkflowRepository;
use Arasya\Operations\Security\CookiePolicy;
use Arasya\Operations\Security\CsrfGuard;
use Arasya\Operations\Security\PasswordHasher;
use Arasya\Operations\Security\SessionTokenManager;
use Arasya\Operations\Security\UsernameNormalizer;
use Arasya\Operations\Tests\MemoryAuditLogger;
use Arasya\Operations\Tests\MemoryEmployeeRepository;
use Arasya\Operations\Tests\MemoryRateLimiter;
use Arasya\Operations\Tests\MemorySessionRepository;
use Arasya\Operations\Tests\MutableClock;
use Arasya\Operations\Support\StructuredLogger;
use Arasya\Operations\Support\SensitiveDataRedactor;
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/public/RuntimeLocator.php';
require __DIR__ . '/TestDoubles.php';

$tests = [];

function test(string $name, Closure $callback): void
{
    global $tests;
    $tests[] = [$name, $callback];
}

function expect(bool $condition, string $message = 'Expectation failed.'): void
{
    if (!$condition) {
        $trace = debug_backtrace();
        $line = $trace[0]['line'];
        throw new RuntimeException("$message at line $line");
    }
}

function expectApi(string $code, Closure $callback): void
{
    try {
        $callback();
    } catch (ApiException $error) {
        expect($error->errorCode === $code, "Expected {$code}, received {$error->errorCode}.");
        return;
    }
    throw new RuntimeException("Expected API error {$code}.");
}

function expectRuntime(Closure $callback): void
{
    try {
        $callback();
    } catch (\Throwable) {
        return;
    }
    throw new RuntimeException('Expected runtime validation failure.');
}

function expectApiConfigurationFailure(string $home, string $messageFragment): void
{
    try {
        Config::fromEnvironment(new ConfigLoader(['HOME' => $home]));
    } catch (RuntimeException $error) {
        expect(str_contains(strtolower($error->getMessage()), strtolower($messageFragment)), "Configuration failure did not mention {$messageFragment}.");
        return;
    }
    throw new RuntimeException('Invalid private configuration unexpectedly succeeded.');
}

function canonicalWorkflowFixture(): ProductionWorkflow
{
    $definitions = [
        ['waiting', 1, 'În așteptare'],
        ['material-preparation', 2, 'Pregătire material'],
        ['workshop-receiving', 3, 'Primire atelier'],
        ['labeling', 4, 'Etichetare'],
        ['material-straightening', 5, 'Îndreptare material'],
        ['bottom-hem', 6, 'Tivul de jos'],
        ['side-hem', 7, 'Tivul lateral'],
        ['ironing', 8, 'Călcare'],
        ['height', 9, 'Înălțime'],
        ['header-tape', 10, 'Rejansă'],
        ['sewing-finishing', 11, 'Finisare coasere'],
        ['quality-control', 12, 'Control calitate'],
        ['packing', 13, 'Împachetare'],
        ['delivery', 14, 'Livrare'],
    ];
    return new ProductionWorkflow(
        'curtain-production',
        'Flux producție Arasya',
        1,
        array_map(static fn (array $stage): ProductionStage => new ProductionStage($stage[0], $stage[1], $stage[2]), $definitions),
    );
}

/** @param callable(ProductionStage): ProductionStage|null $stageMapper */
function workflowVariant(?string $name = null, ?int $version = null, ?callable $stageMapper = null): ProductionWorkflow
{
    $canonical = canonicalWorkflowFixture();
    return new ProductionWorkflow(
        $canonical->id,
        $name ?? $canonical->name,
        $version ?? $canonical->version,
        $stageMapper === null ? $canonical->stages : array_map($stageMapper, $canonical->stages),
    );
}

/** @return array{AuthenticationService, MemoryEmployeeRepository, MemorySessionRepository, MemoryRateLimiter, MemoryAuditLogger, PasswordHasher, SessionTokenManager, MutableClock} */
function authFixture(int $usernameLimit = 5, int $ipLimit = 30): array
{
    $employees = new MemoryEmployeeRepository();
    $sessions = new MemorySessionRepository();
    $limiter = new MemoryRateLimiter($usernameLimit, $ipLimit);
    $audit = new MemoryAuditLogger();
    $passwords = new PasswordHasher();
    $tokens = new SessionTokenManager(str_repeat('s', 32));
    $clock = new MutableClock(new DateTimeImmutable('2026-08-19T08:00:00Z'));
    $employees->add(new EmployeeIdentity(
        '68ff2a20-a164-4ed8-8659-1872a37d2ced',
        'EMP-0042',
        'Mehmet.Yilmaz',
        'mehmet.yilmaz',
        $passwords->hash('correct horse battery staple'),
        'Mehmet Yılmaz',
        'pregatire-material',
        'Pregătire Material',
        'active',
        'employee',
        'active',
        'active',
        ['history.view_mine', 'orders.scan', 'orders.view_mine', 'profile.view_self'],
        ['material-preparation'],
    ));
    $auth = new AuthenticationService($employees, $sessions, $limiter, $audit, $passwords, $tokens, new UsernameNormalizer(), $clock, 36_000, 300);
    return [$auth, $employees, $sessions, $limiter, $audit, $passwords, $tokens, $clock];
}

test('password hashing uses PHP password APIs and supports verification', function (): void {
    $hasher = new PasswordHasher();
    $hash = $hasher->hash('a long test passphrase');
    expect($hash !== 'a long test passphrase');
    expect($hasher->verify('a long test passphrase', $hash));
    expect(!$hasher->verify('incorrect passphrase', $hash));
    $info = password_get_info($hash);
    expect(in_array($info['algoName'], ['argon2id', 'bcrypt'], true));
});

test('username normalization only trims and folds case', function (): void {
    expect((new UsernameNormalizer())->normalize('  Mehmet.Yilmaz ') === 'mehmet.yilmaz');
});

test('realistic login, session, refresh and logout lifecycle rotates opaque tokens', function (): void {
    [$auth, , $sessions, , $audit, , $tokens] = authFixture();
    $login = $auth->login('  MEHMET.YILMAZ ', 'correct horse battery staple', '192.0.2.10', 'test-agent', 'req-login');
    expect($login->employee->employeeUuid === '68ff2a20-a164-4ed8-8659-1872a37d2ced');
    expect(!$sessions->storesRawToken($login->rawToken), 'Raw session token was stored.');
    expect($sessions->findByTokenHash($tokens->hash($login->rawToken)) !== null);
    expect($auth->authenticate($login->rawToken, '192.0.2.10', 'test-agent', 'req-session')->employee->displayName === 'Mehmet Yılmaz');

    $refresh = $auth->refresh($login->rawToken, '192.0.2.10', 'test-agent', 'req-refresh');
    expect($refresh->rawToken !== $login->rawToken);
    expect($refresh->csrfToken !== $login->csrfToken);
    expectApi('SESSION_EXPIRED', fn () => $auth->authenticate($login->rawToken, '192.0.2.10', 'test-agent', 'req-old'));
    $current = $auth->authenticate($refresh->rawToken, '192.0.2.10', 'test-agent', 'req-new');
    expect($current->employee->username === 'Mehmet.Yilmaz');
    $auth->logout($current, '192.0.2.10', 'test-agent', 'req-logout');
    expectApi('SESSION_EXPIRED', fn () => $auth->authenticate($refresh->rawToken, '192.0.2.10', 'test-agent', 'req-after-logout'));
    expect(in_array('AUTH_LOGIN_SUCCESS', array_column($audit->events, 'eventType'), true));
    expect(in_array('AUTH_SESSION_REFRESH', array_column($audit->events, 'eventType'), true));
    expect(in_array('AUTH_LOGOUT', array_column($audit->events, 'eventType'), true));
});

test('wrong and unknown credentials share the same public error', function (): void {
    [$auth] = authFixture();
    expectApi('INVALID_CREDENTIALS', fn () => $auth->login('mehmet.yilmaz', 'wrong password', '192.0.2.10', 'test', 'wrong'));
    expectApi('INVALID_CREDENTIALS', fn () => $auth->login('unknown.employee', 'wrong password', '192.0.2.10', 'test', 'unknown'));
});

test('inactive and suspended employees cannot login or retain sessions', function (): void {
    [$auth, $employees] = authFixture();
    $login = $auth->login('mehmet.yilmaz', 'correct horse battery staple', '192.0.2.10', 'test', 'active');
    $employees->updateStatus($login->employee->employeeUuid, 'suspended', '2026-08-19 08:01:00.000000');
    expectApi('ACCOUNT_INACTIVE', fn () => $auth->authenticate($login->rawToken, '192.0.2.10', 'test', 'disabled-session'));
    expectApi('ACCOUNT_INACTIVE', fn () => $auth->login('mehmet.yilmaz', 'correct horse battery staple', '192.0.2.10', 'test', 'disabled-login'));
});

test('inactive departments invalidate login, sessions and authorization; inactive roles do not lock accounts', function (): void {
    [$auth, $employees] = authFixture();
    $roleSession = $auth->login('mehmet.yilmaz', 'correct horse battery staple', '192.0.2.10', 'test', 'role-login');
    // With multiple roles an inactive role only stops contributing permissions (covered on MySQL);
    // it no longer locks the identity out of every application.
    $employees->updateRoleStatus($roleSession->employee->employeeUuid, 'inactive');
    expect($auth->authenticate($roleSession->rawToken, '192.0.2.10', 'test', 'role-session')->employee->employeeUuid === $roleSession->employee->employeeUuid);

    [$departmentAuth, $departmentEmployees] = authFixture();
    $departmentSession = $departmentAuth->login('mehmet.yilmaz', 'correct horse battery staple', '192.0.2.11', 'test', 'department-login');
    $departmentEmployees->updateDepartmentStatus($departmentSession->employee->employeeUuid, 'inactive');
    expectApi('ACCOUNT_INACTIVE', fn () => $departmentAuth->authenticate($departmentSession->rawToken, '192.0.2.11', 'test', 'department-session'));
    expectApi('ACCOUNT_INACTIVE', fn () => $departmentAuth->login('mehmet.yilmaz', 'correct horse battery staple', '192.0.2.11', 'test', 'department-login-denied'));
    $departmentInactive = $departmentEmployees->findByUuid($departmentSession->employee->employeeUuid);
    expect($departmentInactive !== null && !(new AuthorizationService())->can($departmentInactive, 'orders.scan'));
});

test('expired and revoked sessions fail closed', function (): void {
    [$auth, , , , , , , $clock] = authFixture();
    $login = $auth->login('mehmet.yilmaz', 'correct horse battery staple', '192.0.2.10', 'test', 'login');
    $clock->advance('+11 hours');
    expectApi('SESSION_EXPIRED', fn () => $auth->authenticate($login->rawToken, '192.0.2.10', 'test', 'expired'));
});

test('username limiter blocks repeated attacks while shared IP has a higher threshold', function (): void {
    [$auth] = authFixture(2, 5);
    expectApi('INVALID_CREDENTIALS', fn () => $auth->login('target', 'wrong password', '198.51.100.4', 'test', 'one'));
    expectApi('INVALID_CREDENTIALS', fn () => $auth->login('target', 'wrong password', '198.51.100.4', 'test', 'two'));
    expectApi('RATE_LIMITED', fn () => $auth->login('target', 'wrong password', '198.51.100.4', 'test', 'three'));
    expectApi('INVALID_CREDENTIALS', fn () => $auth->login('another', 'wrong password', '198.51.100.4', 'test', 'shared-ip'));
});

test('authorization resolves permissions centrally and denies unknown permissions', function (): void {
    [, $employees] = authFixture();
    $employee = $employees->findByUuid('68ff2a20-a164-4ed8-8659-1872a37d2ced');
    expect($employee !== null);
    $authorization = new AuthorizationService();
    expect($authorization->can($employee, 'orders.scan'));
    expect(!$authorization->can($employee, 'employees.manage'));
    expectApi('UNAUTHORIZED_ACTION', fn () => $authorization->require($employee, 'unknown.permission'));
    $permissionRemoved = new EmployeeIdentity($employee->employeeUuid, $employee->employeeCode, $employee->username, $employee->usernameNormalized, $employee->passwordHash, $employee->displayName, $employee->departmentKey, $employee->departmentName, $employee->departmentStatus, $employee->roleKey, $employee->roleStatus, $employee->status, [], $employee->allowedStageIds);
    expect(!$authorization->can($permissionRemoved, 'orders.scan'));
});

test('CSRF tokens are session-bound and exact-origin CORS never uses wildcard', function (): void {
    $tokens = new SessionTokenManager(str_repeat('x', 32));
    $guard = new CsrfGuard($tokens);
    $raw = $tokens->generate();
    $guard->requireValid($raw, $tokens->csrfToken($raw));
    expectApi('CSRF_INVALID', fn () => $guard->requireValid($raw, null));
    expectApi('CSRF_INVALID', fn () => $guard->requireValid($raw, $tokens->csrfToken($tokens->generate())));

    $cors = new CorsPolicy(['https://staff.arasyahome.ro', 'http://localhost:5173']);
    $request = new Request('POST', '/auth/login', ['origin' => 'https://staff.arasyahome.ro'], [], '{}', '127.0.0.1', 'test', 'cors-ok');
    $cors->requireUnsafeOrigin($request);
    expect(($cors->headers('https://staff.arasyahome.ro')['Access-Control-Allow-Origin'] ?? '') === 'https://staff.arasyahome.ro');
    expect(!in_array('*', $cors->headers('https://staff.arasyahome.ro'), true));
    $preflight = $cors->preflight(new Request('OPTIONS', '/auth/login', [
        'origin' => 'https://staff.arasyahome.ro',
        'access-control-request-method' => 'POST',
        'access-control-request-headers' => 'content-type, x-request-id',
    ], [], '', '127.0.0.1', 'test', 'preflight'));
    expect($preflight?->status === 204);
    $denied = new Request('POST', '/auth/login', ['origin' => 'https://evil.example'], [], '{}', '127.0.0.1', 'test', 'cors-bad');
    expectApi('ORIGIN_DENIED', fn () => $cors->requireUnsafeOrigin($denied));
    $missing = new Request('POST', '/auth/login', [], [], '{}', '127.0.0.1', 'test', 'cors-missing');
    expectApi('ORIGIN_DENIED', fn () => $cors->requireUnsafeOrigin($missing));
});

test('production workflow preflight allows conditional ETag headers and denies arbitrary headers', function (): void {
    $cors = new CorsPolicy(['https://staff.arasyahome.ro']);
    $allowed = $cors->preflight(new Request(
        'OPTIONS',
        '/production/workflow',
        [
            'origin' => 'https://staff.arasyahome.ro',
            'access-control-request-method' => 'GET',
            'access-control-request-headers' => 'if-none-match,x-request-id',
        ],
        [],
        '',
        '127.0.0.1',
        'cors-contract',
        'workflow-preflight',
    ));
    expect($allowed !== null && $allowed->status === 204 && $allowed->payload === null);
    expect(($allowed->headers['Access-Control-Allow-Origin'] ?? null) === 'https://staff.arasyahome.ro');
    expect(($allowed->headers['Access-Control-Allow-Credentials'] ?? null) === 'true');
    expect(str_contains($allowed->headers['Access-Control-Allow-Headers'] ?? '', 'If-None-Match'));
    expect(str_contains($allowed->headers['Access-Control-Allow-Methods'] ?? '', 'GET'));
    expect(str_contains($allowed->headers['Access-Control-Expose-Headers'] ?? '', 'ETag'));

    expectApi('CORS_HEADER_DENIED', fn () => $cors->preflight(new Request(
        'OPTIONS',
        '/production/workflow',
        [
            'origin' => 'https://staff.arasyahome.ro',
            'access-control-request-method' => 'GET',
            'access-control-request-headers' => 'if-none-match,x-arbitrary-secret-header',
        ],
        [],
        '',
        '127.0.0.1',
        'cors-contract',
        'workflow-preflight-denied',
    )));
});

test('workflow ETag is deterministic and changes with every API-visible semantic field', function (): void {
    $canonical = canonicalWorkflowFixture();
    expect($canonical->etag() === canonicalWorkflowFixture()->etag(), 'Equivalent workflows must have the same ETag.');
    expect($canonical->etag() !== workflowVariant('Flux producție administrat')->etag(), 'Workflow name must affect ETag.');
    expect($canonical->etag() !== workflowVariant(null, 2)->etag(), 'Workflow version must affect ETag.');
    expect($canonical->etag() !== workflowVariant(null, null, static fn (ProductionStage $stage): ProductionStage => $stage->id === 'quality-control'
        ? new ProductionStage($stage->id, $stage->ordinal, 'Verificare calitate')
        : $stage)->etag(), 'Stage label must affect ETag.');
    expect($canonical->etag() !== workflowVariant(null, 2, static fn (ProductionStage $stage): ProductionStage => $stage->id === 'delivery'
        ? new ProductionStage($stage->id, 15, $stage->label)
        : $stage)->etag(), 'Stage ordinal must affect ETag.');
    expect(str_starts_with($canonical->etag(), '"sha256-') && str_ends_with($canonical->etag(), '"'));
});

test('canonical curtain-production version 1 enforces exact stage identity and ordinal structure', function (): void {
    $canonical = canonicalWorkflowFixture();
    expect(count($canonical->toArray()['stages']) === 14);
    expect($canonical->etag() !== '');

    expectRuntime(fn () => workflowVariant(null, null, static fn (ProductionStage $stage): ProductionStage => $stage->ordinal === 3
        ? new ProductionStage('cutting', $stage->ordinal, $stage->label)
        : $stage));

    expectRuntime(fn () => new ProductionWorkflow(
        $canonical->id,
        $canonical->name,
        $canonical->version,
        array_values(array_filter($canonical->stages, static fn (ProductionStage $stage): bool => $stage->id !== 'height')),
    ));

    expectRuntime(fn () => new ProductionWorkflow(
        $canonical->id,
        $canonical->name,
        $canonical->version,
        [...$canonical->stages, new ProductionStage('extra-stage', 15, 'Etapă suplimentară')],
    ));

    expectRuntime(fn () => workflowVariant(null, null, static fn (ProductionStage $stage): ProductionStage => match ($stage->id) {
        'bottom-hem' => new ProductionStage('side-hem', $stage->ordinal, $stage->label),
        'side-hem' => new ProductionStage('bottom-hem', $stage->ordinal, $stage->label),
        default => $stage,
    }));
});

test('canonical structure ignores labels while content-aware ETag detects their change', function (): void {
    $canonical = canonicalWorkflowFixture();
    $renamed = workflowVariant(null, null, static fn (ProductionStage $stage): ProductionStage => $stage->id === 'quality-control'
        ? new ProductionStage($stage->id, $stage->ordinal, 'Verificare calitate')
        : $stage);
    expect(($renamed->toArray()['stages'][11]['id'] ?? null) === 'quality-control');
    expect(($renamed->toArray()['stages'][11]['label'] ?? null) === 'Verificare calitate');
    expect($renamed->etag() !== $canonical->etag());
});

test('future versions and other workflow identities retain generic validation flexibility', function (): void {
    $canonical = canonicalWorkflowFixture();
    $future = new ProductionWorkflow(
        'curtain-production',
        'Flux producție Arasya',
        2,
        [...$canonical->stages, new ProductionStage('future-stage', 15, 'Etapă viitoare')],
    );
    expect(count($future->stages) === 15);

    $other = new ProductionWorkflow('sample-production', 'Flux exemplu', 1, [
        new ProductionStage('sample-start', 1, 'Start'),
        new ProductionStage('sample-finish', 2, 'Final'),
    ]);
    expect(count($other->stages) === 2);

    expectRuntime(fn () => new ProductionWorkflow('sample-production', 'Flux exemplu', 1, [
        new ProductionStage('sample-start', 1, 'Start'),
        new ProductionStage('sample-start', 2, 'Duplicat'),
    ]));
});

test('employee serialization allowlists safe fields and never exposes password hashes', function (): void {
    [, $employees] = authFixture();
    $employee = $employees->findByUuid('68ff2a20-a164-4ed8-8659-1872a37d2ced');
    expect($employee !== null);
    $safe = EmployeeSerializer::safe($employee);
    $json = json_encode($safe, JSON_THROW_ON_ERROR);
    expect(!str_contains($json, 'password'));
    expect(!str_contains($json, $employee->passwordHash));
    expect($safe['employeeUuid'] === $employee->employeeUuid);
    expect($safe['permissions'] === $employee->permissions);
});

test('disable and password-change administration revoke all sessions', function (): void {
    [$auth, $employees, $sessions, , $audit, $passwords, , $clock] = authFixture();
    $admin = new EmployeeAdminService($employees, $sessions, $passwords, new UsernameNormalizer(), $audit, $clock);
    $first = $auth->login('mehmet.yilmaz', 'correct horse battery staple', '192.0.2.10', 'test', 'first');
    $admin->changePassword($first->employee->employeeUuid, 'a completely new passphrase', 'cli-password');
    expectApi('SESSION_EXPIRED', fn () => $auth->authenticate($first->rawToken, '192.0.2.10', 'test', 'old-session'));
    expectApi('INVALID_CREDENTIALS', fn () => $auth->login('mehmet.yilmaz', 'correct horse battery staple', '192.0.2.10', 'test', 'old-password'));
    $second = $auth->login('mehmet.yilmaz', 'a completely new passphrase', '192.0.2.10', 'test', 'new-password');
    $admin->setStatus($second->employee->employeeUuid, 'inactive', 'cli-disable');
    expectApi('SESSION_EXPIRED', fn () => $auth->authenticate($second->rawToken, '192.0.2.10', 'test', 'disabled'));
});

test('CLI administration foundation creates normalized employees without seeded credentials', function (): void {
    [$auth, $employees, $sessions, , $audit, $passwords, , $clock] = authFixture();
    $admin = new EmployeeAdminService($employees, $sessions, $passwords, new UsernameNormalizer(), $audit, $clock);
    $created = $admin->create('Ana Popescu', '  Ana.Popescu ', 'EMP-0043', 'pregatire-material', 'employee', 'ana production passphrase', ['material-preparation'], 'cli-create');
    expect($created->usernameNormalized === 'ana.popescu');
    expect($created->employeeUuid !== 'EMP-0043');
    expect($created->allowedStageIds === ['material-preparation']);
    expect($auth->login('ANA.POPESCU', 'ana production passphrase', '192.0.2.11', 'test', 'ana-login')->employee->employeeUuid === $created->employeeUuid);
});

test('cookie policy uses the __Host contract in production and clears identically', function (): void {
    $config = new Config('production', str_repeat('s', 32), 'db', 3306, 'db', 'user', 'password', ['https://staff.arasyahome.ro'], 36_000, 300, 5, 30, 900, false, []);
    $policy = new CookiePolicy($config);
    $set = $policy->session('opaque', new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')));
    $clear = $policy->clear();
    foreach (['__Host-arasya_session', 'Path=/', 'Secure', 'HttpOnly', 'SameSite=Lax'] as $required) {
        expect(str_contains($set, $required));
        expect(str_contains($clear, $required));
    }
    expect(!str_contains($set, 'Domain='));
});

test('HTTP auth contract matches the Staff session shape without exposing credentials', function (): void {
    [$auth, , , , , , $tokens] = authFixture();
    $config = new Config('test', str_repeat('s', 32), 'db', 3306, 'db', 'user', 'password', ['http://localhost:5173'], 36_000, 300, 5, 30, 900, false, []);
    $controller = new AuthController($auth, new CsrfGuard($tokens), new CookiePolicy($config), $config, new AuthorizationService(), new RequestContext());
    $loginRequest = new Request(
        'POST',
        '/auth/login',
        ['content-type' => 'application/json', 'origin' => 'http://localhost:5173'],
        [],
        json_encode(['username' => 'mehmet.yilmaz', 'password' => 'correct horse battery staple'], JSON_THROW_ON_ERROR),
        '127.0.0.1',
        'contract-test',
        'contract-login',
    );
    $login = $controller->login($loginRequest);
    expect($login->status === 200);
    expect(($login->payload['employee']['displayName'] ?? null) === 'Mehmet Yılmaz');
    expect(is_string($login->payload['csrfToken'] ?? null));
    $encoded = json_encode($login->payload, JSON_THROW_ON_ERROR);
    expect(!str_contains($encoded, 'password'));
    expect(!str_contains($encoded, 'rawToken'));
    $cookie = $login->headers['Set-Cookie'] ?? '';
    expect(preg_match('/^arasya_session=([^;]+)/', $cookie, $matches) === 1);
    $rawToken = rawurldecode($matches[1]);
    $session = $controller->session(new Request('GET', '/auth/session', [], ['arasya_session' => rawurlencode($rawToken)], '', '127.0.0.1', 'contract-test', 'contract-session'));
    expect(($session->payload['employee']['employeeUuid'] ?? null) === '68ff2a20-a164-4ed8-8659-1872a37d2ced');
    expect(($session->payload['csrfToken'] ?? null) === $tokens->csrfToken($rawToken));
});

test('authenticated production workflow route returns the exact canonical catalog and supports ETag revalidation', function (): void {
    $workflow = canonicalWorkflowFixture();
    expect(count($workflow->stages) === 14);
    expect(array_map(static fn (ProductionStage $stage): array => [$stage->ordinal, $stage->id, $stage->label], $workflow->stages) === [
        [1, 'waiting', 'În așteptare'],
        [2, 'material-preparation', 'Pregătire material'],
        [3, 'workshop-receiving', 'Primire atelier'],
        [4, 'labeling', 'Etichetare'],
        [5, 'material-straightening', 'Îndreptare material'],
        [6, 'bottom-hem', 'Tivul de jos'],
        [7, 'side-hem', 'Tivul lateral'],
        [8, 'ironing', 'Călcare'],
        [9, 'height', 'Înălțime'],
        [10, 'header-tape', 'Rejansă'],
        [11, 'sewing-finishing', 'Finisare coasere'],
        [12, 'quality-control', 'Control calitate'],
        [13, 'packing', 'Împachetare'],
        [14, 'delivery', 'Livrare'],
    ]);

    $repository = new class($workflow) implements ProductionWorkflowRepository {
        public function __construct(private readonly ProductionWorkflow $workflow)
        {
        }

        public function current(): ?ProductionWorkflow
        {
            return $this->workflow;
        }
    };
    [$auth, , , , , , $tokens, $clock] = authFixture();
    $config = new Config('test', str_repeat('s', 32), 'db', 3306, 'db', 'user', 'password', ['http://localhost:5173'], 36_000, 300, 5, 30, 900, false, []);
    $cookies = new CookiePolicy($config);
    $context = new RequestContext();
    $authController = new AuthController($auth, new CsrfGuard($tokens), $cookies, $config, new AuthorizationService(), $context);
    $workflowController = new ProductionWorkflowController(new ProductionWorkflowService($repository), $auth, $config, $context);
    $kernel = new ApiKernel($authController, new HealthController(new PDO('sqlite::memory:'), $clock), new CorsPolicy(['http://localhost:5173']), new StructuredLogger(), $cookies, $context, $workflowController);
    $login = $auth->login('mehmet.yilmaz', 'correct horse battery staple', '127.0.0.1', 'workflow-test', 'workflow-login');
    $cookie = ['arasya_session' => rawurlencode($login->rawToken)];
    $response = $kernel->handle(new Request('GET', '/production/workflow', ['origin' => 'http://localhost:5173'], $cookie, '', '127.0.0.1', 'workflow-test', 'workflow-get'));
    expect($response->status === 200);
    expect(($response->payload['workflow']['id'] ?? null) === 'curtain-production');
    expect(($response->payload['workflow']['version'] ?? null) === 1);
    expect(count($response->payload['stages'] ?? []) === 14);
    expect(($response->headers['Cache-Control'] ?? null) === 'private, no-cache');
    expect(str_contains($response->headers['Access-Control-Expose-Headers'] ?? '', 'ETag'));
    expect(($response->headers['Strict-Transport-Security'] ?? null) === 'max-age=31536000; includeSubDomains');
    expect(($response->headers['Content-Security-Policy'] ?? null) === "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    expect(($response->headers['X-Frame-Options'] ?? null) === 'DENY');
    expect(($response->headers['Referrer-Policy'] ?? null) === 'no-referrer');
    expect(str_contains($response->headers['Permissions-Policy'] ?? '', 'camera=()'));
    expect(($response->headers['Cross-Origin-Resource-Policy'] ?? null) === 'cross-origin');
    $etag = $response->headers['ETag'] ?? '';
    expect($etag !== '');

    $notModified = $kernel->handle(new Request('GET', '/production/workflow', ['origin' => 'http://localhost:5173', 'if-none-match' => $etag], $cookie, '', '127.0.0.1', 'workflow-test', 'workflow-etag'));
    expect($notModified->status === 304 && $notModified->payload === null);
    expect(($notModified->headers['ETag'] ?? null) === $etag);

    $invalidRepository = new class implements ProductionWorkflowRepository {
        public function current(): ?ProductionWorkflow
        {
            throw new RuntimeException('Canonical production workflow structure is invalid: cutting at ordinal 3.');
        }
    };
    $invalidController = new ProductionWorkflowController(new ProductionWorkflowService($invalidRepository), $auth, $config, $context);
    $invalidKernel = new ApiKernel($authController, new HealthController(new PDO('sqlite::memory:'), $clock), new CorsPolicy(['http://localhost:5173']), new StructuredLogger(static function (string $line): void {}), $cookies, $context, $invalidController, null);
    $unavailable = $invalidKernel->handle(new Request('GET', '/production/workflow', ['origin' => 'http://localhost:5173'], $cookie, '', '127.0.0.1', 'workflow-test', 'workflow-invalid-catalog'));
    expect($unavailable->status === 503);
    expect(($unavailable->payload['error']['code'] ?? null) === 'WORKFLOW_UNAVAILABLE');
    expect(!str_contains(json_encode($unavailable->payload, JSON_THROW_ON_ERROR), 'cutting'));

    $unauthenticated = $kernel->handle(new Request('GET', '/production/workflow', ['origin' => 'http://localhost:5173'], [], '', '127.0.0.1', 'workflow-test', 'workflow-no-session'));
    expect($unauthenticated->status === 401);
    expect(($unauthenticated->payload['error']['code'] ?? null) === 'SESSION_EXPIRED');

    $health = $kernel->handle(new Request('GET', '/health', [], [], '', '127.0.0.1', 'workflow-test', 'health-stable'));
    expect($health->status === 200 && ($health->payload['version'] ?? null) === '2.17.0');
});

test('JSON auth input rejects malformed, oversized and unexpected payloads', function (): void {
    [$auth, , , , , , $tokens] = authFixture();
    $config = new Config('test', str_repeat('s', 32), 'db', 3306, 'db', 'user', 'password', ['http://localhost:5173'], 36_000, 300, 5, 30, 900, false, []);
    $controller = new AuthController($auth, new CsrfGuard($tokens), new CookiePolicy($config), $config, new AuthorizationService(), new RequestContext());
    expectApi('MALFORMED_JSON', fn () => $controller->login(new Request('POST', '/auth/login', ['content-type' => 'application/json'], [], '{', '127.0.0.1', 'test', 'malformed')));
    expectApi('REQUEST_TOO_LARGE', fn () => $controller->login(new Request('POST', '/auth/login', ['content-type' => 'application/json'], [], str_repeat('x', 8193), '127.0.0.1', 'test', 'large')));
    expectApi('INVALID_REQUEST', fn () => $controller->login(new Request('POST', '/auth/login', ['content-type' => 'application/json'], [], json_encode(['username' => 'employee', 'password' => 'password', 'employee_uuid' => 'forged'], JSON_THROW_ON_ERROR), '127.0.0.1', 'test', 'extra')));
});

test('expired HTTP sessions clear the exact cookie contract', function (): void {
    [$auth, , , , , , $tokens, $clock] = authFixture();
    $config = new Config('test', str_repeat('s', 32), 'db', 3306, 'db', 'user', 'password', ['http://localhost:5173'], 36_000, 300, 5, 30, 900, false, []);
    $cookies = new CookiePolicy($config);
    $context = new RequestContext();
    $controller = new AuthController($auth, new CsrfGuard($tokens), $cookies, $config, new AuthorizationService(), $context);
    $kernel = new ApiKernel($controller, new HealthController(new PDO('sqlite::memory:'), $clock), new CorsPolicy(['http://localhost:5173']), new StructuredLogger(), $cookies, $context);
    $login = $auth->login('mehmet.yilmaz', 'correct horse battery staple', '127.0.0.1', 'test', 'expired-login');
    $clock->advance('+11 hours');
    $previousLog = ini_get('error_log');
    ini_set('error_log', '/dev/null');
    try {
        $response = $kernel->handle(new Request('GET', '/auth/session', ['origin' => 'http://localhost:5173'], ['arasya_session' => rawurlencode($login->rawToken)], '', '127.0.0.1', 'test', 'expired-cookie'));
    } finally {
        ini_set('error_log', is_string($previousLog) ? $previousLog : '');
    }
    expect($response->status === 401);
    expect(($response->headers['Set-Cookie'] ?? null) === $cookies->clear());
});

test('HTTP logout is CSRF-protected, idempotent, traceable and never fakes revocation', function (): void {
    [$auth, , , , $audit, , $tokens, $clock] = authFixture();
    $config = new Config('test', str_repeat('s', 32), 'db', 3306, 'db', 'user', 'password', ['http://localhost:5173'], 36_000, 300, 5, 30, 900, false, []);
    $cookies = new CookiePolicy($config);
    $context = new RequestContext();
    $lines = [];
    $logger = new StructuredLogger(static function (string $line) use (&$lines): void { $lines[] = $line; });
    $controller = new AuthController($auth, new CsrfGuard($tokens), $cookies, $config, new AuthorizationService(), $context);
    $kernel = new ApiKernel($controller, new HealthController(new PDO('sqlite::memory:'), $clock), new CorsPolicy(['http://localhost:5173']), $logger, $cookies, $context);
    $login = $auth->login('mehmet.yilmaz', 'correct horse battery staple', '127.0.0.1', 'test', 'logout-login');
    $cookie = ['arasya_session' => rawurlencode($login->rawToken)];

    $invalid = $kernel->handle(new Request('POST', '/auth/logout', ['origin' => 'http://localhost:5173', 'x-csrf-token' => 'invalid'], $cookie, '', '127.0.0.1', 'test', 'logout-invalid-csrf'));
    expect($invalid->status === 403);
    expect(!isset($invalid->headers['Set-Cookie']), 'Invalid CSRF must not clear the session cookie.');
    expect($auth->authenticate($login->rawToken, '127.0.0.1', 'test', 'logout-still-valid')->employee->employeeUuid === $login->employee->employeeUuid);

    $valid = $kernel->handle(new Request('POST', '/auth/logout', ['origin' => 'http://localhost:5173', 'x-csrf-token' => $login->csrfToken], $cookie, '', '127.0.0.1', 'test', 'logout-valid'));
    expect($valid->status === 200 && ($valid->payload['ok'] ?? false) === true);
    expect(($valid->headers['Set-Cookie'] ?? null) === $cookies->clear());
    expectApi('SESSION_EXPIRED', fn () => $auth->authenticate($login->rawToken, '127.0.0.1', 'test', 'logout-revoked'));

    $repeat = $kernel->handle(new Request('POST', '/auth/logout', ['origin' => 'http://localhost:5173'], $cookie, '', '127.0.0.1', 'test', 'logout-repeat'));
    expect($repeat->status === 200 && ($repeat->headers['Set-Cookie'] ?? null) === $cookies->clear());
    expect(count(array_filter($audit->events, static fn (array $event): bool => $event['eventType'] === 'AUTH_LOGOUT')) === 1);
    $logOutput = implode("\n", $lines);
    expect(str_contains($logOutput, '"employee_uuid":"' . $login->employee->employeeUuid . '"'));
    expect(!str_contains($logOutput, $login->rawToken));
    expect(!str_contains($logOutput, $login->csrfToken));
});

test('JSON private config maps aliases, arrays and deterministic precedence', function (): void {
    $home = sys_get_temp_dir() . '/arasya-json-config-' . bin2hex(random_bytes(6));
    $configDirectory = $home . '/arasya-config';
    mkdir($configDirectory, 0700, true);
    $path = $configDirectory . '/secrets.json';
    $private = [
        'DB_USER_NAME' => 'alias-user',
        'ARASYA_DB_USER' => 'canonical-user',
        'DB_USER_PASSWORD' => 'private-password',
        'DB_NAME' => 'private-db',
        'DB_HOST' => 'json-host',
        'ARASYA_APP_SECRET' => str_repeat('j', 32),
        'ARASYA_ALLOWED_ORIGINS' => ['https://staff.arasyahome.ro'],
        'IGNORED_UNKNOWN_KEY' => ['does' => 'nothing'],
    ];
    file_put_contents($path, json_encode($private, JSON_THROW_ON_ERROR));
    try {
        $config = Config::fromEnvironment(new ConfigLoader(['HOME' => $home, 'ARASYA_DB_HOST' => 'environment-host']));
        expect($config->dbHost === 'environment-host');
        expect($config->dbName === 'private-db');
        expect($config->dbUser === 'canonical-user');
        expect($config->dbPassword === 'private-password');
        expect($config->allowedOrigins === ['https://staff.arasyahome.ro']);
        expect($config->sessionRecordRetentionDays === 30 && $config->loginAttemptRetentionDays === 30 && $config->rateLimitRetentionDays === 7);
        expect($config->authAuditRetentionDays === null);

        $privateWithoutSecret = $private;
        unset($privateWithoutSecret['ARASYA_APP_SECRET']);
        file_put_contents($path, json_encode($privateWithoutSecret, JSON_THROW_ON_ERROR));
        expectApiConfigurationFailure($home, 'ARASYA_APP_SECRET');

        file_put_contents($path, '{');
        expectApiConfigurationFailure($home, 'malformed');

        $private['ARASYA_ALLOWED_ORIGINS'] = ['*'];
        file_put_contents($path, json_encode($private, JSON_THROW_ON_ERROR));
        expectApiConfigurationFailure($home, 'origin');

        $private['ARASYA_ALLOWED_ORIGINS'] = ['https://staff.arasyahome.ro'];
        $private['ARASYA_DB_PORT'] = ['nested'];
        file_put_contents($path, json_encode($private, JSON_THROW_ON_ERROR));
        expectApiConfigurationFailure($home, 'scalar');
    } finally {
        unlink($path);
        rmdir($configDirectory);
        rmdir($home);
    }
});

test('explicit config override wins and legacy PHP private config remains supported', function (): void {
    $home = sys_get_temp_dir() . '/arasya-legacy-config-' . bin2hex(random_bytes(6));
    $configDirectory = $home . '/arasya-config';
    $unrelatedRelease = $home . '/unrelated-release';
    mkdir($configDirectory, 0700, true);
    mkdir($unrelatedRelease, 0700, true);
    $jsonPath = $configDirectory . '/secrets.json';
    $phpPath = $configDirectory . '/operations-api.php';
    $base = [
        'ARASYA_APP_SECRET' => str_repeat('l', 32),
        'ARASYA_DB_USER' => 'legacy-user',
        'ARASYA_DB_PASSWORD' => 'legacy-password',
        'ARASYA_DB_NAME' => 'legacy-db',
        'ARASYA_ALLOWED_ORIGINS' => 'https://staff.arasyahome.ro',
    ];
    file_put_contents($phpPath, "<?php\ndeclare(strict_types=1);\nreturn " . var_export($base, true) . ";\n");
    try {
        expect(Config::fromEnvironment(new ConfigLoader(['HOME' => $home]))->dbName === 'legacy-db');
        file_put_contents($jsonPath, json_encode([
            ...$base,
            'ARASYA_DB_NAME' => 'json-db',
            'ARASYA_ALLOWED_ORIGINS' => ['https://staff.arasyahome.ro'],
        ], JSON_THROW_ON_ERROR));
        expect(Config::fromEnvironment(new ConfigLoader(['HOME' => $home]))->dbName === 'json-db');
        expect(Config::fromEnvironment(new ConfigLoader(['HOME' => $home, 'ARASYA_CONFIG_FILE' => $phpPath]))->dbName === 'legacy-db');
        expect(Config::fromEnvironment(new ConfigLoader(['ARASYA_CONFIG_FILE' => $phpPath], $unrelatedRelease))->dbName === 'legacy-db');
    } finally {
        if (is_file($jsonPath)) {
            unlink($jsonPath);
        }
        unlink($phpPath);
        rmdir($unrelatedRelease);
        rmdir($configDirectory);
        rmdir($home);
    }
});

test('private config derives cPanel home only from the validated runtime layout', function (): void {
    $account = sys_get_temp_dir() . '/arasya-litespeed-config-' . bin2hex(random_bytes(6));
    $runtime = $account . '/arasya-operations-api/current';
    $versionedRuntime = $account . '/arasya-operations-api/releases/' . str_repeat('a', 40);
    $configDirectory = $account . '/arasya-config';
    $environmentHome = $account . '-environment-home';
    $environmentConfigDirectory = $environmentHome . '/arasya-config';
    mkdir($runtime, 0700, true);
    mkdir($versionedRuntime, 0700, true);
    mkdir($configDirectory, 0700, true);
    mkdir($environmentConfigDirectory, 0700, true);
    $path = $configDirectory . '/secrets.json';
    file_put_contents($path, json_encode([
        'ARASYA_APP_SECRET' => str_repeat('w', 32),
        'DB_USER_NAME' => 'web-user',
        'DB_USER_PASSWORD' => 'web-password',
        'DB_NAME' => 'web-database',
        'ARASYA_ALLOWED_ORIGINS' => ['https://staff.arasyahome.ro'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($environmentConfigDirectory . '/secrets.json', json_encode([
        'ARASYA_APP_SECRET' => str_repeat('e', 32),
        'DB_USER_NAME' => 'environment-home-user',
        'DB_USER_PASSWORD' => 'environment-home-password',
        'DB_NAME' => 'environment-home-database',
        'ARASYA_ALLOWED_ORIGINS' => ['https://staff.arasyahome.ro'],
    ], JSON_THROW_ON_ERROR));
    try {
        $config = Config::fromEnvironment(new ConfigLoader([], $runtime));
        expect($config->dbUser === 'web-user');
        expect($config->dbName === 'web-database');
        $versioned = Config::fromEnvironment(new ConfigLoader([], $versionedRuntime));
        expect($versioned->dbUser === 'web-user' && $versioned->dbName === 'web-database');
        $environmentSelected = Config::fromEnvironment(new ConfigLoader(['HOME' => $environmentHome], $versionedRuntime));
        expect($environmentSelected->dbUser === 'environment-home-user', 'HOME did not win over release-root derivation.');
    } finally {
        unlink($path);
        unlink($environmentConfigDirectory . '/secrets.json');
        rmdir($environmentConfigDirectory);
        rmdir($environmentHome);
        rmdir($configDirectory);
        rmdir($runtime);
        rmdir($versionedRuntime);
        rmdir($account . '/arasya-operations-api/releases');
        rmdir($account . '/arasya-operations-api');
        rmdir($account);
    }
});

test('private config never derives home from an arbitrary release path', function (): void {
    $workspace = sys_get_temp_dir() . '/arasya-untrusted-release-' . bin2hex(random_bytes(6));
    $release = $workspace . '/different-application/current';
    $configDirectory = $workspace . '/arasya-config';
    mkdir($release, 0700, true);
    mkdir($configDirectory, 0700, true);
    file_put_contents($configDirectory . '/secrets.json', '{}');
    try {
        $denied = false;
        try {
            (new ConfigLoader([], $release))->load();
        } catch (RuntimeException $error) {
            $denied = str_contains($error->getMessage(), 'home');
        }
        expect($denied, 'An arbitrary release path unexpectedly derived a private configuration home.');
    } finally {
        unlink($configDirectory . '/secrets.json');
        rmdir($configDirectory);
        rmdir($release);
        rmdir($workspace . '/different-application');
        rmdir($workspace);
    }
});

test('private config path rejects runtime, API public and Staff public roots', function (): void {
    $home = sys_get_temp_dir() . '/arasya-config-path-' . bin2hex(random_bytes(6));
    $allowedDirectory = $home . '/arasya-config';
    $forbiddenDirectories = [
        $home . '/arasya-operations-api/current',
        $home . '/arasya-operations-api/releases/' . str_repeat('b', 40),
        $home . '/api.arasyahome.ro',
        $home . '/staff.arasyahome.ro',
    ];
    mkdir($allowedDirectory, 0700, true);
    foreach ($forbiddenDirectories as $directory) {
        mkdir($directory, 0700, true);
    }
    $values = [
        'ARASYA_APP_SECRET' => str_repeat('s', 32),
        'DB_USER_NAME' => 'user',
        'DB_USER_PASSWORD' => 'password',
        'DB_NAME' => 'database',
        'ARASYA_ALLOWED_ORIGINS' => ['https://staff.arasyahome.ro'],
    ];
    $allowedPath = $allowedDirectory . '/secrets.json';
    file_put_contents($allowedPath, json_encode($values, JSON_THROW_ON_ERROR));
    try {
        expect(Config::fromEnvironment(new ConfigLoader(['HOME' => $home]))->dbHost === 'localhost');
        foreach ($forbiddenDirectories as $directory) {
            $forbiddenPath = $directory . '/secrets.json';
            file_put_contents($forbiddenPath, json_encode($values, JSON_THROW_ON_ERROR));
            try {
                Config::fromEnvironment(new ConfigLoader(['HOME' => $home, 'ARASYA_CONFIG_FILE' => $forbiddenPath]));
                throw new RuntimeException('Forbidden private config path unexpectedly succeeded.');
            } catch (RuntimeException $error) {
                expect(str_contains($error->getMessage(), 'forbidden'));
            }
            unlink($forbiddenPath);
        }
    } finally {
        unlink($allowedPath);
        rmdir($allowedDirectory);
        foreach (array_reverse($forbiddenDirectories) as $directory) {
            @rmdir($directory);
        }
        @rmdir($home . '/arasya-operations-api/releases');
        @rmdir($home . '/arasya-operations-api');
        rmdir($home);
    }
});

test('runtime locator supports nested, HOME, HOME-less LiteSpeed and safe override layouts', function (): void {
    $sourceRoot = dirname(__DIR__);
    expect(RuntimeLocator::locate(null, $sourceRoot, null, $sourceRoot . '/public') === realpath($sourceRoot));

    $home = sys_get_temp_dir() . '/arasya-runtime-locator-' . bin2hex(random_bytes(6));
    $runtime = $home . '/arasya-operations-api/current';
    $releases = $home . '/arasya-operations-api/releases';
    $releaseCommit = str_repeat('c', 40);
    $versionedRuntime = $releases . '/' . $releaseCommit;
    $public = $home . '/api.arasyahome.ro';
    mkdir($runtime, 0700, true);
    mkdir($versionedRuntime, 0700, true);
    mkdir($public, 0700, true);
    file_put_contents($runtime . '/bootstrap.php', "<?php\ndeclare(strict_types=1);\n");
    file_put_contents($versionedRuntime . '/bootstrap.php', "<?php\ndeclare(strict_types=1);\n");
    file_put_contents($public . '/index.php', "<?php\ndeclare(strict_types=1);\n");
    file_put_contents($public . '/RuntimeLocator.php', "<?php\ndeclare(strict_types=1);\n");
    try {
        expect(RuntimeLocator::locate(null, $home, $home, $public) === realpath($runtime));
        expect(RuntimeLocator::locate(null, $home, null, $public) === realpath($runtime));
        expect(RuntimeLocator::locate($runtime, '/unused', null, '/unused') === realpath($runtime));

        file_put_contents($home . '/arasya-operations-api/active-release', $releaseCommit . "\n");
        expect(RuntimeLocator::locate(null, $home, $home, $public) === realpath($versionedRuntime));
        expect(RuntimeLocator::locate(null, $home, null, $public) === realpath($versionedRuntime));

        file_put_contents($home . '/arasya-operations-api/active-release', '../current');
        expectRuntime(fn () => RuntimeLocator::locate(null, $home, $home, $public));
        file_put_contents($home . '/arasya-operations-api/active-release', str_repeat('d', 40));
        expectRuntime(fn () => RuntimeLocator::locate(null, $home, $home, $public));
        file_put_contents($home . '/arasya-operations-api/active-release', $releaseCommit);
        unlink($versionedRuntime . '/bootstrap.php');
        expectRuntime(fn () => RuntimeLocator::locate(null, $home, null, $public));
        $escapeCommit = str_repeat('e', 40);
        $escapeTarget = $home . '/outside-release';
        mkdir($escapeTarget, 0700);
        file_put_contents($escapeTarget . '/bootstrap.php', "<?php\ndeclare(strict_types=1);\n");
        symlink($escapeTarget, $releases . '/' . $escapeCommit);
        file_put_contents($home . '/arasya-operations-api/active-release', $escapeCommit);
        expectRuntime(fn () => RuntimeLocator::locate(null, $home, $home, $public));
        unlink($releases . '/' . $escapeCommit);
        unlink($escapeTarget . '/bootstrap.php');
        rmdir($escapeTarget);
        unlink($home . '/arasya-operations-api/active-release');
        $unsafeDenied = false;
        try {
            RuntimeLocator::locate('../unsafe', $home, $home, $public);
        } catch (RuntimeException) {
            $unsafeDenied = true;
        }
        expect($unsafeDenied, 'Unsafe runtime override unexpectedly succeeded.');

        unlink($runtime . '/bootstrap.php');
        $missingDenied = false;
        try {
            RuntimeLocator::locate(null, $home, null, $public);
        } catch (RuntimeException) {
            $missingDenied = true;
        }
        expect($missingDenied, 'A public parent without a valid private runtime unexpectedly succeeded.');
    } finally {
        if (is_file($runtime . '/bootstrap.php')) {
            unlink($runtime . '/bootstrap.php');
        }
        if (is_file($versionedRuntime . '/bootstrap.php')) {
            unlink($versionedRuntime . '/bootstrap.php');
        }
        if (is_file($home . '/arasya-operations-api/active-release')) {
            unlink($home . '/arasya-operations-api/active-release');
        }
        unlink($public . '/index.php');
        unlink($public . '/RuntimeLocator.php');
        rmdir($runtime);
        rmdir($versionedRuntime);
        rmdir($releases);
        rmdir($home . '/arasya-operations-api');
        rmdir($public);
        rmdir($home);
    }
});

test('structured logger includes safe employee context and removes sensitive fields', function (): void {
    $lines = [];
    $logger = new StructuredLogger(static function (string $line) use (&$lines): void { $lines[] = $line; });
    $logger->log('info', 'http_request', 'request-1', [
        'employee_uuid' => '68ff2a20-a164-4ed8-8659-1872a37d2ced',
        'route' => '/employees/me',
        'status' => 200,
        'password' => 'must-not-log',
        'csrf_token' => 'must-not-log-either',
        'nested' => [
            'access_token' => 'nested-sensitive-value',
            'items' => [['client_secret' => 'list-sensitive-value', 'stage_key' => 'quality-control']],
        ],
        'workflow_key' => 'curtain-production',
        'source_key' => 'trendhome',
    ]);
    $line = $lines[0] ?? '';
    expect(str_contains($line, '68ff2a20-a164-4ed8-8659-1872a37d2ced'));
    expect(!str_contains($line, 'must-not-log'));
    expect(!str_contains($line, 'password'));
    expect(!str_contains($line, 'csrf'));
    expect(!str_contains($line, 'nested-sensitive-value'));
    expect(!str_contains($line, 'list-sensitive-value'));
    expect(str_contains($line, 'stage_key') && str_contains($line, 'workflow_key') && str_contains($line, 'source_key'));
});

test('recursive sensitive-data redaction keeps operational key diagnostics', function (): void {
    $sanitized = SensitiveDataRedactor::sanitize([
        'password_hash' => 'top-secret-value',
        'context' => [
            'authorization' => 'nested-secret-value',
            'items' => [(object) ['api-key' => 'object-secret-value', 'stage_key' => 'bottom-hem']],
        ],
        'workflow_key' => 'curtain-production',
        'source_key' => 'outletperdele',
        'diagnostic' => 'catalog-read',
    ]);
    $encoded = json_encode($sanitized, JSON_THROW_ON_ERROR);
    expect(!str_contains($encoded, 'top-secret-value') && !str_contains($encoded, 'nested-secret-value') && !str_contains($encoded, 'object-secret-value'));
    expect(str_contains($encoded, 'stage_key') && str_contains($encoded, 'workflow_key') && str_contains($encoded, 'source_key') && str_contains($encoded, 'catalog-read'));
});

test('workflow repository builds one active metadata and stage result set and fails closed on malformed V1', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE production_workflows (workflow_id INTEGER PRIMARY KEY, workflow_key TEXT, name TEXT, version INTEGER, status TEXT)');
    $pdo->exec('CREATE TABLE production_stages (workflow_id INTEGER, stage_id TEXT, display_name TEXT, ordinal INTEGER, status TEXT)');
    $pdo->exec("INSERT INTO production_workflows VALUES (1, 'sample-production', 'Sample', 1, 'active')");
    $pdo->exec("INSERT INTO production_stages VALUES (1, 'first', 'First', 1, 'active'), (1, 'ignored', 'Ignored', 2, 'inactive'), (1, 'last', 'Last', 3, 'active')");
    $workflow = (new PdoProductionWorkflowRepository($pdo, 'sample-production'))->current();
    expect($workflow !== null && $workflow->name === 'Sample');
    expect(array_map(static fn (ProductionStage $stage): string => $stage->id, $workflow->stages) === ['first', 'last']);
    expect((new PdoProductionWorkflowRepository($pdo, 'missing'))->current() === null);

    $pdo->exec("DELETE FROM production_stages; DELETE FROM production_workflows;");
    $pdo->exec("INSERT INTO production_workflows VALUES (2, 'curtain-production', 'Broken', 1, 'active')");
    $pdo->exec("INSERT INTO production_stages VALUES (2, 'cutting', 'Wrong', 1, 'active')");
    expectRuntime(fn () => (new PdoProductionWorkflowRepository($pdo))->current());
});

test('migration status verifies checksums without mutating the database', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE schema_migrations (migration_name TEXT PRIMARY KEY, checksum TEXT, applied_at TEXT)');
    $directory = sys_get_temp_dir() . '/arasya-migration-status-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $first = $directory . '/001_first.sql';
    $second = $directory . '/002_second.sql';
    file_put_contents($first, 'SELECT 1;');
    file_put_contents($second, 'SELECT 2;');
    $checksum = hash_file('sha256', $first);
    expect(is_string($checksum));
    $pdo->prepare('INSERT INTO schema_migrations VALUES (:name, :checksum, :applied_at)')->execute([
        'name' => '001_first.sql',
        'checksum' => $checksum,
        'applied_at' => '2026-08-19 00:00:00',
    ]);
    try {
        $before = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        $status = (new MigrationStatus($pdo))->inspect($directory);
        expect(array_column($status, 'status') === ['APPLIED', 'PENDING']);
        expect((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === $before);
    } finally {
        unlink($first);
        unlink($second);
        rmdir($directory);
    }
});

test('auth maintenance dry-run is inert and bounded cleanup preserves current records', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE auth_sessions (session_id TEXT PRIMARY KEY, expires_at TEXT, revoked_at TEXT NULL)');
    $pdo->exec('CREATE TABLE auth_login_attempts (attempt_id INTEGER PRIMARY KEY, attempted_at TEXT)');
    $pdo->exec('CREATE TABLE auth_rate_limit_buckets (dimension_type TEXT, dimension_hash BLOB, updated_at TEXT, PRIMARY KEY (dimension_type, dimension_hash))');
    $pdo->exec('CREATE TABLE auth_audit_events (event_id TEXT PRIMARY KEY, created_at TEXT)');
    $pdo->exec('CREATE TABLE order_operation_idempotency (employee_uuid TEXT, idempotency_key TEXT, created_at TEXT, PRIMARY KEY (employee_uuid, idempotency_key))');
    $pdo->exec('CREATE TABLE api_rate_limit_buckets (bucket_scope TEXT, subject_hash BLOB, updated_at TEXT, PRIMARY KEY (bucket_scope, subject_hash))');
    $pdo->exec('CREATE TABLE b2b_company_idempotency (employee_uuid TEXT, idempotency_key TEXT, created_at TEXT, PRIMARY KEY (employee_uuid, idempotency_key))');
    $pdo->exec('CREATE TABLE b2b_order_idempotency (employee_uuid TEXT, idempotency_key TEXT, created_at TEXT, PRIMARY KEY (employee_uuid, idempotency_key))');
    $pdo->exec("INSERT INTO b2b_order_idempotency VALUES ('employee-b','old-order-replay','2026-01-01 00:00:00'),('employee-b','current-order-replay','2026-08-18 00:00:00')");
    $pdo->exec('CREATE TABLE b2b_account_idempotency (employee_uuid TEXT, idempotency_key TEXT, created_at TEXT, PRIMARY KEY (employee_uuid, idempotency_key))');
    $pdo->exec("INSERT INTO b2b_account_idempotency VALUES ('employee-c','old-account-replay','2026-01-01 00:00:00'),('employee-c','current-account-replay','2026-08-18 00:00:00')");
    $pdo->exec('CREATE TABLE b2b_project_idempotency (employee_uuid TEXT, idempotency_key TEXT, created_at TEXT, PRIMARY KEY (employee_uuid, idempotency_key))');
    $pdo->exec("INSERT INTO b2b_project_idempotency VALUES ('employee-d','old-project-replay','2026-01-01 00:00:00'),('employee-d','current-project-replay','2026-08-18 00:00:00')");
    $pdo->exec('CREATE TABLE production_exception_idempotency (employee_uuid TEXT, idempotency_key TEXT, created_at TEXT, PRIMARY KEY (employee_uuid, idempotency_key))');
    $pdo->exec("INSERT INTO production_exception_idempotency VALUES ('employee-e','old-exception-replay','2026-01-01 00:00:00'),('employee-e','current-exception-replay','2026-08-18 00:00:00')");
    $pdo->exec('CREATE TABLE live_events (event_seq INTEGER PRIMARY KEY, created_at TEXT)');
    $pdo->exec("INSERT INTO live_events VALUES (1, '2026-08-01 00:00:00'), (2, '2026-08-19 11:00:00')");
    $pdo->exec("INSERT INTO b2b_company_idempotency VALUES ('employee-b', 'old-b2b-key-000001', '2026-01-01 00:00:00'), ('employee-b', 'current-b2b-key-001', '2026-08-18 00:00:00')");
    $pdo->exec("INSERT INTO order_operation_idempotency VALUES ('employee-a', 'old-key-0000000001', '2026-01-01 00:00:00'), ('employee-a', 'current-key-0000001', '2026-08-18 00:00:00')");
    $insertApiBucket = $pdo->prepare('INSERT INTO api_rate_limit_buckets VALUES (:scope, :hash, :updated_at)');
    $insertApiBucket->bindValue(':scope', 'order-lookup');
    $insertApiBucket->bindValue(':hash', random_bytes(32), PDO::PARAM_LOB);
    $insertApiBucket->bindValue(':updated_at', '2026-01-01 00:00:00');
    $insertApiBucket->execute();
    $pdo->exec("INSERT INTO auth_sessions VALUES ('old-expired', '2026-01-01 00:00:00', NULL), ('old-revoked', '2027-01-01 00:00:00', '2026-01-01 00:00:00'), ('current', '2027-01-01 00:00:00', NULL)");
    $pdo->exec("INSERT INTO auth_login_attempts VALUES (1, '2026-01-01 00:00:00'), (2, '2026-08-18 00:00:00')");
    $oldHash = random_bytes(32);
    $currentHash = random_bytes(32);
    $insertBucket = $pdo->prepare('INSERT INTO auth_rate_limit_buckets VALUES (:type, :hash, :updated_at)');
    $insertBucket->bindValue(':type', 'ip');
    $insertBucket->bindValue(':hash', $oldHash, PDO::PARAM_LOB);
    $insertBucket->bindValue(':updated_at', '2026-01-01 00:00:00');
    $insertBucket->execute();
    $insertBucket->bindValue(':type', 'username');
    $insertBucket->bindValue(':hash', $currentHash, PDO::PARAM_LOB);
    $insertBucket->bindValue(':updated_at', '2026-08-18 00:00:00');
    $insertBucket->execute();
    $pdo->exec("INSERT INTO auth_audit_events VALUES ('old-audit', '2025-01-01 00:00:00')");
    $now = new DateTimeImmutable('2026-08-19T12:00:00Z');
    $maintenance = new AuthMaintenance($pdo, 30, 30, 7, null, 1);
    $dryRun = $maintenance->run(true, $now);
    expect($dryRun === ['sessions' => 2, 'login_attempts' => 1, 'rate_limit_buckets' => 1, 'audit_events' => null, 'idempotency_keys' => 1, 'api_rate_limit_buckets' => 1, 'b2b_idempotency_keys' => 1, 'b2b_order_idempotency_keys'=>1, 'b2b_account_idempotency_keys'=>1, 'b2b_project_idempotency_keys'=>1, 'exception_idempotency_keys' => 1, 'live_events' => 1]);
    expect((int) $pdo->query('SELECT COUNT(*) FROM auth_sessions')->fetchColumn() === 3);
    $deleted = $maintenance->run(false, $now);
    expect($deleted === $dryRun);
    expect($pdo->query("SELECT session_id FROM auth_sessions")->fetchColumn() === 'current');
    expect((int) $pdo->query('SELECT COUNT(*) FROM auth_login_attempts')->fetchColumn() === 1);
    expect((int) $pdo->query('SELECT COUNT(*) FROM auth_rate_limit_buckets')->fetchColumn() === 1);
    expect((int) $pdo->query('SELECT COUNT(*) FROM auth_audit_events')->fetchColumn() === 1);
    expect($pdo->query('SELECT idempotency_key FROM order_operation_idempotency')->fetchAll(PDO::FETCH_COLUMN) === ['current-key-0000001']);
    expect((int) $pdo->query('SELECT COUNT(*) FROM api_rate_limit_buckets')->fetchColumn() === 0);
    expect($pdo->query('SELECT idempotency_key FROM b2b_company_idempotency')->fetchAll(PDO::FETCH_COLUMN) === ['current-b2b-key-001']);
    expect($pdo->query('SELECT idempotency_key FROM b2b_order_idempotency')->fetchAll(PDO::FETCH_COLUMN) === ['current-order-replay']);
    expect($pdo->query('SELECT idempotency_key FROM b2b_account_idempotency')->fetchAll(PDO::FETCH_COLUMN) === ['current-account-replay']);
    expect($pdo->query('SELECT idempotency_key FROM b2b_project_idempotency')->fetchAll(PDO::FETCH_COLUMN) === ['current-project-replay']);
    $auditMaintenance = new AuthMaintenance($pdo, 30, 30, 7, 90, 1);
    expect($auditMaintenance->run(false, $now)['audit_events'] === 1);
});

test('repository failures return a generic error with request ID and no SQL or path leakage', function (): void {
    [, $employees, $sessions, $limiter, , $passwords, $tokens, $clock] = authFixture();
    $failingAudit = new class implements AuditLogger {
        public function record(string $eventType, ?string $employeeUuid, ?string $usernameNormalized, string $ipAddress, string $userAgent, string $requestId, string $createdAt, array $metadata = []): void
        {
            throw new RuntimeException('SQLSTATE[HY000] secret failure at /private/config.php');
        }
    };
    $auth = new AuthenticationService($employees, $sessions, $limiter, $failingAudit, $passwords, $tokens, new UsernameNormalizer(), $clock, 36_000, 300);
    $config = new Config('test', str_repeat('s', 32), 'db', 3306, 'db', 'user', 'password', ['http://localhost:5173'], 36_000, 300, 5, 30, 900, false, []);
    $cookies = new CookiePolicy($config);
    $context = new RequestContext();
    $controller = new AuthController($auth, new CsrfGuard($tokens), $cookies, $config, new AuthorizationService(), $context);
    $kernel = new ApiKernel($controller, new HealthController(new PDO('sqlite::memory:'), $clock), new CorsPolicy(['http://localhost:5173']), new StructuredLogger(), $cookies, $context);
    $request = new Request('POST', '/auth/login', ['origin' => 'http://localhost:5173', 'content-type' => 'application/json'], [], json_encode(['username' => 'unknown', 'password' => 'wrong password'], JSON_THROW_ON_ERROR), '127.0.0.1', 'test', 'failure-request-id');
    $previousLog = ini_get('error_log');
    ini_set('error_log', '/dev/null');
    try {
        $response = $kernel->handle($request);
    } finally {
        ini_set('error_log', is_string($previousLog) ? $previousLog : '');
    }
    $json = json_encode($response->payload, JSON_THROW_ON_ERROR);
    expect($response->status === 500);
    expect(str_contains($json, 'failure-request-id'));
    expect(!str_contains($json, 'SQLSTATE'));
    expect(!str_contains($json, '/private/'));
});

$success = 0;
$failed = 0;

test('GlobalOrderId formats and parses source identifiers strictly and deterministically', function (): void {
    $valid = new \Arasya\Operations\Order\GlobalOrderId('trendhome', 'TH-100.1');
    expect($valid->sourceKey === 'trendhome');
    expect($valid->sourceOrderId === 'TH-100.1');
    expect($valid->toString() === 'trendhome:TH-100.1');
    
    $parsed = \Arasya\Operations\Order\GlobalOrderId::fromString('b2b:CUST_99');
    expect($parsed->sourceKey === 'b2b');
    expect($parsed->sourceOrderId === 'CUST_99');
    
    // Test that sourceKey allows only lowercase alphanumeric, underscore, hyphen
    expectRuntime(fn () => new \Arasya\Operations\Order\GlobalOrderId('TrendHome', 'TH100'));
    expectRuntime(fn () => new \Arasya\Operations\Order\GlobalOrderId('trend/home', 'TH100'));
    expectRuntime(fn () => new \Arasya\Operations\Order\GlobalOrderId('unknown source key with spaces', 'TH100'));
    
    // Valid sourceOrderId formats
    expect((new \Arasya\Operations\Order\GlobalOrderId('trendhome', '61833'))->toString() === 'trendhome:61833');
    expect((new \Arasya\Operations\Order\GlobalOrderId('trendhome', 'TH100'))->toString() === 'trendhome:TH100');
    expect((new \Arasya\Operations\Order\GlobalOrderId('outletperdele', 'ORD-100'))->toString() === 'outletperdele:ORD-100');
    expect((new \Arasya\Operations\Order\GlobalOrderId('trendyol', '100.2'))->toString() === 'trendyol:100.2');
    expect((new \Arasya\Operations\Order\GlobalOrderId('trendhome', 'ABC_123-XY.5'))->toString() === 'trendhome:ABC_123-XY.5');

    // Invalid sourceOrderId formats
    expectRuntime(fn () => new \Arasya\Operations\Order\GlobalOrderId('trendhome', ''));
    expectRuntime(fn () => \Arasya\Operations\Order\GlobalOrderId::fromString(':61833'));
    expectRuntime(fn () => new \Arasya\Operations\Order\GlobalOrderId('trendhome', '61 833'));
    expectRuntime(fn () => new \Arasya\Operations\Order\GlobalOrderId('trendhome', '61/833'));
    expectRuntime(fn () => new \Arasya\Operations\Order\GlobalOrderId('trendhome', '61\\\\833'));
    expectRuntime(fn () => new \Arasya\Operations\Order\GlobalOrderId('trendhome', '61:833'));
    expectRuntime(fn () => new \Arasya\Operations\Order\GlobalOrderId('trendhome', '../61833'));
    expectRuntime(fn () => new \Arasya\Operations\Order\GlobalOrderId('trendhome', '%2F61833'));
    expectRuntime(fn () => new \Arasya\Operations\Order\GlobalOrderId('trendhome', "61833\n"));
    expectRuntime(fn () => new \Arasya\Operations\Order\GlobalOrderId('trendhome', '订单123'));
    
    // Test that fromString strictly checks colon separator
    expectRuntime(fn () => \Arasya\Operations\Order\GlobalOrderId::fromString('no-colon'));
});

require __DIR__ . '/OperationsUnitTests.php';
require __DIR__ . '/SourceConnectionUnitTests.php';

foreach ($tests as [$name, $callback]) {
    try {
        $callback();
        $success++;
        fwrite(STDOUT, "PASS $name\n");
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "FAIL $name\n");
        fwrite(STDERR, "     " . $error->getMessage() . "\n");
    }
}

if ($failed > 0) {
    fwrite(STDERR, "\nFAILED $failed tests. ($success passed)\n");
    exit(1);
}

fwrite(STDOUT, "\nSUCCESS: $success tests passed.\n");
exit(0);
