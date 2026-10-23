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

    /**
     * A random base for numeric test order ids whose block [base, base + span) holds no order of any source yet. Each
     * suite passes its own range, and the ranges never overlap, so suites sharing one CI database never share an order
     * id; the database check also skips blocks used by earlier runs on a reused disposable database.
     *
     * Order id ranges (first..last): Confirmare operations 760000..859999, Confirmare Staff 870000..899999, payment gate
     * 900000..929999, source access 930000..959999, source edit 960000..989999, Confirmare finance 731000..759999.
     * Below them: Confirmare cohort (640000..730071, its own random base). 999999 stays unused (customer tracking: an
     * unknown order).
     */
    public static function freeOrderBase(PDO $pdo, int $first, int $last, int $span): int
    {
        $blocks = intdiv($last - $first + 1, $span);
        if ($blocks < 1) {
            throw new RuntimeException("The test order range {$first}..{$last} is smaller than one block of {$span}.");
        }
        $used = $pdo->prepare("SELECT COUNT(*) FROM operational_orders WHERE source_order_id REGEXP '^[0-9]{1,18}$' AND CAST(source_order_id AS UNSIGNED) BETWEEN ? AND ?");
        for ($attempt = 0; $attempt < 64; $attempt++) {
            $base = $first + random_int(0, $blocks - 1) * $span;
            $used->execute([$base, $base + $span - 1]);
            if ((int) $used->fetchColumn() === 0) {
                return $base;
            }
        }
        throw new RuntimeException("No free test order block in {$first}..{$last}; recreate the disposable test database.");
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
    /**
     * Disposable test databases only: removes per-piece rework rows (migration 028), all of them or those of the given
     * orders, in foreign-key order, so a test can delete its exceptions and orders afterwards. Production never deletes them.
     * @param list<string>|null $orderUuids
     */
    public static function clearPieces(PDO $pdo, ?array $orderUuids = null): void
    {
        if (!str_contains(strtolower((string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 'test')) throw new \RuntimeException('Test database required.');
        if ($orderUuids === []) return;
        $in = $orderUuids === null ? null : implode(',', array_map([$pdo, 'quote'], $orderUuids));
        $where = static fn (string $column): string => $in === null ? '' : " WHERE {$column} IN ({$in})";
        // Migration 033 rows reference the orders; a test that deletes its orders removes their cancellation control first.
        if ($pdo->query("SHOW TABLES LIKE 'order_cancellation_sync_operations'")->fetchColumn() !== false) {
            // Migration 034 rows reference the orders as well.
            $pdo->exec('DELETE FROM order_cancellation_discrepancies' . $where('order_uuid'));
            $pdo->exec('DELETE FROM order_cancellation_sync_attempts' . $where('order_uuid'));
            $pdo->exec('DELETE FROM order_cancellation_sync_operations' . $where('order_uuid'));
        }
        if ($pdo->query("SHOW TABLES LIKE 'order_cancellation_controls'")->fetchColumn() !== false) {
            $pdo->exec('DELETE FROM order_cancellation_events' . $where('order_uuid'));
            $pdo->exec('DELETE FROM order_cancellation_controls' . $where('order_uuid'));
            $pdo->exec('UPDATE operational_orders SET cancellation_state = NULL, production_stopped_at = NULL' . $where('order_uuid'));
        }
        if ($pdo->query("SHOW TABLES LIKE 'production_tracks'")->fetchColumn() === false) return;
        if ($pdo->query("SHOW TABLES LIKE 'production_line_plans'")->fetchColumn() !== false) {
            // Migration 029 rows first (they reference pieces, tracks and exceptions).
            $pdo->exec('UPDATE production_material_records SET corrects_record_uuid = NULL' . $where('order_uuid'));
            foreach (['production_assisted_checkpoint_events', 'production_assisted_checkpoints', 'production_route_reconciliation_stages', 'production_route_reconciliations', 'production_unit_verifications', 'production_material_records', 'production_custody_events', 'production_unit_custody', 'production_unit_replacements', 'production_unit_stage_log', 'production_route_decisions', 'production_pilot_cohort', 'production_feature_flag_events'] as $table) {
                $pdo->exec("DELETE FROM {$table}" . $where('order_uuid'));
            }
            $pdo->exec('DELETE c FROM production_unit_cycles c INNER JOIN production_work_units u ON u.unit_uuid = c.unit_uuid' . $where('u.order_uuid'));
            $pdo->exec('DELETE l FROM production_exception_links l INNER JOIN production_exceptions x ON x.exception_uuid = l.exception_uuid' . $where('x.order_uuid'));
            $pdo->exec('DELETE FROM production_line_plan_heads' . $where('order_uuid'));
            $pdo->exec('DELETE p FROM production_plan_pieces p INNER JOIN production_line_plans l ON l.plan_uuid = p.plan_uuid' . $where('l.order_uuid'));
            $pdo->exec('UPDATE production_line_plans SET supersedes_plan_uuid = NULL' . $where('order_uuid'));
            $pdo->exec('DELETE FROM production_line_plans' . $where('order_uuid'));
        }
        $pdo->exec('DELETE i FROM production_track_idempotency i INNER JOIN production_tracks t ON t.track_uuid = i.track_uuid' . $where('t.order_uuid'));
        $pdo->exec('DELETE FROM production_unit_labels' . $where('order_uuid'));
        $pdo->exec('DELETE u FROM production_exception_units u INNER JOIN production_exceptions x ON x.exception_uuid = u.exception_uuid' . $where('x.order_uuid'));
        $pdo->exec('DELETE FROM production_exception_scopes' . $where('order_uuid'));
        $pdo->exec('DELETE FROM production_track_events' . $where('order_uuid'));
        $pdo->exec('DELETE FROM production_work_units' . $where('order_uuid'));
        $pdo->exec('UPDATE production_tracks SET parent_track_uuid = NULL' . $where('order_uuid'));
        $pdo->exec('DELETE FROM production_tracks' . $where('order_uuid'));
    }

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
