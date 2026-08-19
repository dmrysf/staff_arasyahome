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
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\ApiKernel;
use Arasya\Operations\Http\AuthController;
use Arasya\Operations\Http\CorsPolicy;
use Arasya\Operations\Http\HealthController;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
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
        throw new RuntimeException($message);
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
        ['stage-preparation'],
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

test('inactive roles and departments invalidate login, sessions and authorization', function (): void {
    [$auth, $employees] = authFixture();
    $roleSession = $auth->login('mehmet.yilmaz', 'correct horse battery staple', '192.0.2.10', 'test', 'role-login');
    $employees->updateRoleStatus($roleSession->employee->employeeUuid, 'inactive');
    expectApi('ACCOUNT_INACTIVE', fn () => $auth->authenticate($roleSession->rawToken, '192.0.2.10', 'test', 'role-session'));
    expectApi('ACCOUNT_INACTIVE', fn () => $auth->login('mehmet.yilmaz', 'correct horse battery staple', '192.0.2.10', 'test', 'role-login-denied'));
    $roleInactive = $employees->findByUuid($roleSession->employee->employeeUuid);
    expect($roleInactive !== null && !(new AuthorizationService())->can($roleInactive, 'orders.scan'));

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
    $created = $admin->create('Ana Popescu', '  Ana.Popescu ', 'EMP-0043', 'pregatire-material', 'employee', 'ana production passphrase', ['stage-preparation'], 'cli-create');
    expect($created->usernameNormalized === 'ana.popescu');
    expect($created->employeeUuid !== 'EMP-0043');
    expect($created->allowedStageIds === ['stage-preparation']);
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
    mkdir($configDirectory, 0700, true);
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
    } finally {
        if (is_file($jsonPath)) {
            unlink($jsonPath);
        }
        unlink($phpPath);
        rmdir($configDirectory);
        rmdir($home);
    }
});

test('private config path rejects runtime, API public and Staff public roots', function (): void {
    $home = sys_get_temp_dir() . '/arasya-config-path-' . bin2hex(random_bytes(6));
    $allowedDirectory = $home . '/arasya-config';
    $forbiddenDirectories = [
        $home . '/arasya-operations-api/current',
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
        @rmdir($home . '/arasya-operations-api');
        rmdir($home);
    }
});

test('runtime locator supports nested source, cPanel split root and safe override', function (): void {
    $sourceRoot = dirname(__DIR__);
    expect(RuntimeLocator::locate(null, $sourceRoot, null) === realpath($sourceRoot));

    $home = sys_get_temp_dir() . '/arasya-runtime-locator-' . bin2hex(random_bytes(6));
    $runtime = $home . '/arasya-operations-api/current';
    $public = $home . '/api.arasyahome.ro';
    mkdir($runtime, 0700, true);
    mkdir($public, 0700, true);
    file_put_contents($runtime . '/bootstrap.php', "<?php\ndeclare(strict_types=1);\n");
    try {
        expect(RuntimeLocator::locate(null, $home, $home) === realpath($runtime));
        expect(RuntimeLocator::locate($runtime, '/unused', null) === realpath($runtime));
        $unsafeDenied = false;
        try {
            RuntimeLocator::locate('../unsafe', $home, $home);
        } catch (RuntimeException) {
            $unsafeDenied = true;
        }
        expect($unsafeDenied, 'Unsafe runtime override unexpectedly succeeded.');
    } finally {
        unlink($runtime . '/bootstrap.php');
        rmdir($runtime);
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
    ]);
    $line = $lines[0] ?? '';
    expect(str_contains($line, '68ff2a20-a164-4ed8-8659-1872a37d2ced'));
    expect(!str_contains($line, 'must-not-log'));
    expect(!str_contains($line, 'password'));
    expect(!str_contains($line, 'csrf'));
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

$passed = 0;
foreach ($tests as [$name, $callback]) {
    try {
        $callback();
        $passed++;
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
        exit(1);
    }
}
fwrite(STDOUT, "Backend tests passed: {$passed}/" . count($tests) . "\n");
