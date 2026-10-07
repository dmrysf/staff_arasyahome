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
use PDO;

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

    /** @param array<string, array<string, string>>|null $sourceSettings defaults to both test sources active */
    public static function config(string $dbName, array $origins = [self::ORIGIN], ?array $sourceSettings = null, ?array $sourceSecrets = null): Config
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
            sourceSecrets: $sourceSecrets ?? ['trendhome' => self::TRENDHOME_SECRET, 'outletperdele' => self::OUTLET_SECRET],
            sourceSettings: $sourceSettings ?? ['trendhome' => ['mode' => 'active'], 'outletperdele' => ['mode' => 'active']],
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
        return ['status' => $response->status, 'body' => $response->payload, 'headers' => $response->headers, 'raw' => $response->body];
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
    public static function ingest(ApiKernel $kernel, string $sourceKey, array $payload, ?string $secret = null, ?int $timestamp = null, string $route = 'orders', ?string $signature = null): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp ??= time();
        $secret ??= $sourceKey === 'outletperdele' ? self::OUTLET_SECRET : self::TRENDHOME_SECRET;
        return self::call($kernel, 'POST', "/integrations/sources/{$sourceKey}/{$route}", null, [
            'origin' => null,
            'x-arasya-timestamp' => (string) $timestamp,
            'x-arasya-signature' => $signature ?? SourceSignatureVerifier::sign($secret, (string) $timestamp, $body),
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

    /** A TEST curtain order with a printable delivery identity, used by the production document E2E flow. @return array<string, mixed> */
    public static function e2eDocumentOrder(string $number, float $meters, int $minute): array
    {
        $payload = self::sourceOrder($number, "doc-e2e-{$number}-{$minute}", gmdate('Y-m-d\TH:i:s\Z', time() - 3600 + $minute), self::stage('material-preparation'), 'processing', 'active', [[
            'id' => (int) $number * 10 + 1, 'line' => 1, 'name' => 'Draperie Velvet', 'sku' => 'DV-302', 'color' => 'Bej', 'variant' => 'Wave',
            'width' => 300, 'height' => 260, 'unit' => 'cm', 'meters' => $meters, 'quantity' => 1, 'options' => [['label' => 'Confecționare', 'value' => '2 bucăți']],
        ]], $number);
        $payload['order']['delivery'] = ['name' => 'TEST Client Document', 'street' => 'Str. Test 1', 'city' => 'Cluj-Napoca', 'postalCode' => '400000', 'country' => 'RO', 'phone' => '0722000111'];
        return $payload;
    }

    /** Explicit scan/confirmation intent for legacy lifecycle fixtures, not used by negative QR tests. */
    public static function cuttingClaim(PDO $pdo, string $globalId, string $employee, int $version): array
    {
        static $intents = [];
        $intentKey = $employee . '/' . $globalId . '/' . $version;
        if (isset($intents[$intentKey])) return $intents[$intentKey];
        $s = $pdo->prepare("SELECT o.production_stage_id, q.qr_reference FROM operational_orders o LEFT JOIN order_qr_references q ON q.order_uuid = o.order_uuid AND q.status = 'active' WHERE o.global_order_id = ?");
        $s->execute([$globalId]); $row = $s->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['production_stage_id'] !== 'material-preparation') return ['expectedVersion' => $version];
        $count = (new \Arasya\Operations\Cutting\CuttingLifecycle($pdo))->ownedCount($employee);
        return $intents[$intentKey] = ['expectedVersion' => $version, 'qrToken' => 'ARASYA:Q1:' . $row['qr_reference'], 'confirmedMultiple' => $count > 0, 'ownedCount' => $count];
    }

    public static function kernel(Config $config): ApiKernel
    {
        return (new Container($config))->kernel();
    }
}
