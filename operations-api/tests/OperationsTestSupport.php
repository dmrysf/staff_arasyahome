<?php

declare(strict_types=1);

namespace Arasya\Operations\Tests;

use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiKernel;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Integration\SourceSignatureVerifier;
use RuntimeException;

/** Shared helpers for MySQL-backed HTTP tests, race workers and the E2E fixture. Never used in production. */
final class OperationsTestSupport
{
    public const ORIGIN = 'http://127.0.0.1:4173';
    public const TRENDHOME_SECRET = 'trendhome-integration-secret-0123456789abcdef';
    public const OUTLET_SECRET = 'outletperdele-integration-secret-0123456789ab';

    public static function requireTestDatabase(): ?string
    {
        $name = getenv('ARASYA_TEST_DB_NAME');
        if ($name === false || $name === '') {
            return null;
        }
        if (!str_contains(strtolower($name), 'test')) {
            throw new RuntimeException('Integration tests require a database name containing "test".');
        }
        return $name;
    }

    public static function config(string $dbName, array $origins = [self::ORIGIN]): Config
    {
        return new Config(
            environment: 'test',
            appSecret: str_repeat('t', 32),
            dbHost: (string) (getenv('ARASYA_TEST_DB_HOST') ?: '127.0.0.1'),
            dbPort: (int) (getenv('ARASYA_TEST_DB_PORT') ?: 3306),
            dbName: $dbName,
            dbUser: (string) getenv('ARASYA_TEST_DB_USER'),
            dbPassword: (string) getenv('ARASYA_TEST_DB_PASSWORD'),
            allowedOrigins: $origins,
            sessionTtlSeconds: 36_000,
            sessionTouchIntervalSeconds: 300,
            loginUsernameLimit: 50,
            loginIpLimit: 500,
            loginWindowSeconds: 900,
            trustProxy: false,
            trustedProxies: [],
            sourceSecrets: ['trendhome' => self::TRENDHOME_SECRET, 'outletperdele' => self::OUTLET_SECRET],
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @return array{status: int, body: array<string, mixed>|null, headers: array<string, string>}
     */
    public static function call(ApiKernel $kernel, string $method, string $path, ?array $json = null, array $headers = [], ?string $cookie = null, array $query = [], ?string $rawBody = null): array
    {
        $normalized = ['user-agent' => 'operations-integration'];
        if ($method !== 'GET') {
            $normalized['origin'] = self::ORIGIN;
        }
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }
        $body = $rawBody ?? ($json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($body !== '' && !isset($normalized['content-type'])) {
            $normalized['content-type'] = 'application/json';
        }
        $response = $kernel->handle(new Request(
            $method,
            $path,
            array_filter($normalized, static fn (?string $value): bool => $value !== null),
            $cookie === null ? [] : ['arasya_session' => $cookie],
            $body,
            '127.0.0.1',
            'operations-integration',
            'test-' . bin2hex(random_bytes(6)),
            $query,
        ));
        return ['status' => $response->status, 'body' => $response->payload, 'headers' => $response->headers];
    }

    /** @return array{cookie: string, csrf: string, employeeUuid: string} */
    public static function login(ApiKernel $kernel, string $username, string $password): array
    {
        $response = self::call($kernel, 'POST', '/auth/login', ['username' => $username, 'password' => $password]);
        if ($response['status'] !== 200 || !preg_match('/^arasya_session=([^;]+);/', $response['headers']['Set-Cookie'] ?? '', $match)) {
            throw new RuntimeException("Login failed for {$username}: " . json_encode($response['body']));
        }
        return ['cookie' => rawurldecode($match[1]), 'csrf' => (string) $response['body']['csrfToken'], 'employeeUuid' => (string) $response['body']['employee']['employeeUuid']];
    }

    /** @param array<string, mixed> $payload @return array{status: int, body: array<string, mixed>|null, headers: array<string, string>} */
    public static function ingest(ApiKernel $kernel, string $sourceKey, array $payload, ?string $secret = null, ?int $timestamp = null, string $route = 'orders'): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp ??= time();
        $secret ??= $sourceKey === 'outletperdele' ? self::OUTLET_SECRET : self::TRENDHOME_SECRET;
        return self::call($kernel, 'POST', "/integrations/sources/{$sourceKey}/{$route}", null, [
            'origin' => null,
            'x-arasya-timestamp' => (string) $timestamp,
            'x-arasya-signature' => SourceSignatureVerifier::sign($secret, (string) $timestamp, $body),
        ], null, [], $body);
    }

    /** @param list<array<string, mixed>>|null $items @param array<string, mixed>|null $production @return array<string, mixed> */
    public static function sourceOrder(string $orderId, string $eventId, string $changedAt, ?array $production = null, string $status = 'processing', string $availability = 'active', ?array $items = null, ?string $number = null): array
    {
        $payload = [
            'schemaVersion' => 1,
            'eventId' => $eventId,
            'changedAt' => $changedAt,
            'order' => [
                'id' => $orderId,
                'number' => $number ?? $orderId,
                'status' => ['code' => $status, 'label' => ucfirst($status)],
                'availability' => $availability,
                'notes' => 'Verifică sensul materialului.',
                'acceptedAt' => '2026-10-01T08:00:00Z',
                'items' => $items ?? [[
                    'id' => 501, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej',
                    'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => 8.4, 'quantity' => 1,
                ]],
            ],
        ];
        if ($production !== null) {
            $payload['production'] = $production;
        }
        return $payload;
    }

    /** @return array<string, mixed> */
    public static function stage(string $stageId): array
    {
        return ['workflowKey' => 'curtain-production', 'workflowVersion' => 1, 'stageId' => $stageId, 'stageLabel' => 'diagnostic only'];
    }

    public static function kernel(Config $config): ApiKernel
    {
        return (new Container($config))->kernel();
    }
}
