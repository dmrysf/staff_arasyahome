<?php

declare(strict_types=1);

namespace Arasya\Operations\Simulation;

use Arasya\Operations\Config\Config;
use PDO;
use RuntimeException;

/** Defense in depth. The runtime identity may access exactly one simulation schema. */
final class IsolationGuard
{
    /** The hosted simulation: two browser applications, one API. Exact strings; no other host, port or scheme. */
    public const STAFF_ORIGIN = 'https://sim.arasyahome.ro';
    public const DASHBOARD_ORIGIN = 'https://dashboard.sim.arasyahome.ro';
    public const API_ORIGIN = 'https://api.sim.arasyahome.ro';
    public const HOSTED_ORIGINS = [self::STAFF_ORIGIN, self::DASHBOARD_ORIGIN];

    /**
     * Browser origins of a simulation runtime: either only the exact hosted frontends, or only loopback HTTP
     * origins of a local rehearsal. Never a mix, a production host, a wildcard, a path or a duplicate.
     * @param list<string> $origins
     */
    public static function originsAllowed(array $origins): bool
    {
        if ($origins === [] || count($origins) !== count(array_unique($origins))) return false;
        $hosted = array_filter($origins, static fn(string $origin): bool => in_array($origin, self::HOSTED_ORIGINS, true));
        if ($hosted !== []) return count($hosted) === count($origins);
        foreach ($origins as $origin) {
            if (!preg_match('/^http:\/\/(?:127\.0\.0\.1|localhost|\[::1\]):[1-9][0-9]{0,4}$/D', $origin)) return false;
        }
        return true;
    }

    public static function configuration(Config $config): void
    {
        if ($config->environment !== 'simulation') return;
        if (!preg_match('/^(?:[a-z0-9]+_)?arasya_sim_(?:e2e_)?test_([a-f0-9]{12})$/D', $config->dbName, $m)
            || !preg_match('/^(?:[a-z0-9]+_)?sim_' . $m[1] . '$/D', $config->dbUser)
            || !in_array($config->dbHost, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('SIMULATION_DATABASE_BOUNDARY');
        }
        foreach ([$config->appSecret, ...array_values($config->sourceSecrets)] as $secret) {
            if (!preg_match('/^sim:[a-f0-9]{64}$/D', $secret)) throw new RuntimeException('SIMULATION_SECRET_BOUNDARY');
        }
        if ($config->trendyol !== null || $config->trendyolFixtureFile === null || !is_file($config->trendyolFixtureFile)) {
            throw new RuntimeException('SIMULATION_TRANSPORT_BOUNDARY');
        }
        if (!self::originsAllowed($config->allowedOrigins)) {
            throw new RuntimeException('SIMULATION_ORIGIN_BOUNDARY');
        }
        // Client addresses come from the socket only: a forwarded header can never pick the rate-limit identity.
        if ($config->trustProxy || $config->trustedProxies !== []) {
            throw new RuntimeException('SIMULATION_PROXY_BOUNDARY');
        }
    }

    /** Runs immediately after connecting, also when a PDO was supplied to Container. */
    public static function database(Config $config, PDO $pdo): void
    {
        if ($config->environment !== 'simulation') return;
        self::configuration($config);
        self::identityPrivileges($config,$pdo);
        $actual = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($actual !== $config->dbName) throw new RuntimeException('SIMULATION_DATABASE_MISMATCH');
        $row = $pdo->query('SELECT database_name, secret_hash FROM simulation_environment WHERE singleton_id = 1')->fetch();
        if (!is_array($row) || $row['database_name'] !== $actual || !hash_equals($row['secret_hash'], hash('sha256', $config->appSecret))) {
            throw new RuntimeException('SIMULATION_DATABASE_UNPROVISIONED');
        }
    }

    /** May run before the marker exists, but grants and actual schema are already enforced. */
    public static function identityPrivileges(Config $config, PDO $pdo): void
    {
        self::configuration($config);
        if ($config->environment !== 'simulation' || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $config->dbName) throw new RuntimeException('SIMULATION_DATABASE_MISMATCH');
        $identity = explode('@', (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn())[0];
        if ($identity !== $config->dbUser) throw new RuntimeException('SIMULATION_DATABASE_IDENTITY');
        foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if (preg_match('/^GRANT USAGE ON \*\.\* TO /', $grant)) continue;
            // Reject global, other-schema, role, wildcard and grant-option privileges.
            if (!str_starts_with($grant, 'GRANT ALL PRIVILEGES ON `' . str_replace('_', '\\_', $config->dbName) . '`.* TO ')
                || str_contains($grant, 'WITH GRANT OPTION')) {
                throw new RuntimeException('SIMULATION_DATABASE_PRIVILEGES');
            }
        }
    }
}
