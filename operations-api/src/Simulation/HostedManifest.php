<?php

declare(strict_types=1);

namespace Arasya\Operations\Simulation;

use JsonException;
use RuntimeException;

/**
 * The private manifest of the hosted simulation (schema 2). It names the three simulation origins, the one
 * simulation database identity and the session, and carries the generated test secrets. Every consumer (manifest
 * creation, initialization, the web entry point, environment checks and both deploy scripts) validates it the same
 * way and fails closed; error messages are codes only and never contain a value from the file.
 */
final class HostedManifest
{
    public const SCHEMA_VERSION = 2;
    public const KIND = 'arasya-hosted-simulation';
    public const ALLOWED_ORIGINS = IsolationGuard::STAFF_ORIGIN . ',' . IsolationGuard::DASHBOARD_ORIGIN;
    /** Real marketplace and private-configuration inputs that a simulation manifest must never carry. */
    private const FORBIDDEN_KEYS = ['ARASYA_TRENDYOL_SELLER_ID', 'ARASYA_TRENDYOL_API_KEY', 'ARASYA_TRENDYOL_API_SECRET', 'ARASYA_TRENDYOL_API_BASE_URL', 'ARASYA_CONFIG_FILE', 'ARASYA_TRUST_PROXY', 'ARASYA_TRUSTED_PROXIES'];

    /** Reads and validates the private manifest file; it must be a 0600 file in its own 0700 session folder. */
    public static function load(string $path): array
    {
        $file = realpath($path);
        if ($file === false || !is_file($file) || is_link($path)) throw new RuntimeException('SIMULATION_PRIVATE_MANIFEST_REQUIRED');
        if ((fileperms($file) & 0077) !== 0 || (fileperms(dirname($file)) & 0077) !== 0) throw new RuntimeException('SIMULATION_PRIVATE_MANIFEST_PERMISSIONS');
        try {
            $manifest = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('SIMULATION_PRIVATE_MANIFEST_INVALID');
        }
        if (!is_array($manifest)) throw new RuntimeException('SIMULATION_PRIVATE_MANIFEST_INVALID');
        self::assertValid($manifest, dirname($file));
        return $manifest;
    }

    /** Validates the content against its (resolved) session directory. */
    public static function assertValid(array $manifest, string $sessionDirectory): void
    {
        $id = $manifest['sessionId'] ?? null;
        if (($manifest['schemaVersion'] ?? null) !== self::SCHEMA_VERSION || ($manifest['kind'] ?? null) !== self::KIND || !is_string($id) || !preg_match('/^[a-f0-9]{12}$/D', $id)) {
            throw new RuntimeException('SIMULATION_MANIFEST_SCHEMA');
        }
        self::assertPrivateDirectory($sessionDirectory, $id);
        $origins = $manifest['origins'] ?? null;
        if ($origins !== ['staff' => IsolationGuard::STAFF_ORIGIN, 'dashboard' => IsolationGuard::DASHBOARD_ORIGIN, 'api' => IsolationGuard::API_ORIGIN]) {
            throw new RuntimeException('SIMULATION_MANIFEST_ORIGINS');
        }
        $env = $manifest['environment'] ?? null;
        if (!is_array($env) || ($env['ARASYA_APP_ENV'] ?? null) !== 'simulation') throw new RuntimeException('SIMULATION_ENV_REQUIRED');
        foreach ($env as $key => $value) {
            if (!is_string($key) || !is_string($value) || !str_starts_with($key, 'ARASYA_')) throw new RuntimeException('SIMULATION_MANIFEST_ENVIRONMENT');
        }
        if (($env['ARASYA_ALLOWED_ORIGINS'] ?? null) !== self::ALLOWED_ORIGINS) throw new RuntimeException('SIMULATION_MANIFEST_ORIGINS');
        foreach (self::FORBIDDEN_KEYS as $key) {
            if (array_key_exists($key, $env)) throw new RuntimeException('SIMULATION_MANIFEST_FORBIDDEN_SETTING');
        }
        $database = $manifest['database'] ?? null;
        $name = $env['ARASYA_DB_NAME'] ?? '';
        $user = $env['ARASYA_DB_USER'] ?? '';
        $host = $env['ARASYA_DB_HOST'] ?? '';
        if (!preg_match('/^(?:[a-z0-9]+_)?arasya_sim_test_' . $id . '$/D', $name) || !preg_match('/^(?:[a-z0-9]+_)?sim_' . $id . '$/D', $user)
            || !in_array($host, ['localhost', '127.0.0.1'], true) || ($env['ARASYA_DB_PASSWORD'] ?? '') === ''
            || $database !== ['name' => $name, 'user' => $user, 'host' => $host]) {
            throw new RuntimeException('SIMULATION_DATABASE_BOUNDARY');
        }
        $secrets = array_filter($env, static fn(string $key): bool => $key === 'ARASYA_APP_SECRET' || str_starts_with($key, 'ARASYA_SOURCE_SECRET_'), ARRAY_FILTER_USE_KEY);
        if (!isset($secrets['ARASYA_APP_SECRET']) || count($secrets) < 2) throw new RuntimeException('SIMULATION_SECRET_BOUNDARY');
        foreach ($secrets as $secret) {
            if (!preg_match('/^sim:[a-f0-9]{64}$/D', $secret)) throw new RuntimeException('SIMULATION_SECRET_BOUNDARY');
        }
        if (count(array_unique($secrets)) !== count($secrets)) throw new RuntimeException('SIMULATION_SECRET_BOUNDARY');
        $fixture = realpath($env['ARASYA_TRENDYOL_FIXTURE_FILE'] ?? '');
        if ($fixture === false || dirname($fixture) !== realpath($sessionDirectory) || (fileperms($fixture) & 0077) !== 0) {
            throw new RuntimeException('SIMULATION_TRANSPORT_BOUNDARY');
        }
        if (($manifest['paths'] ?? null) !== ['session' => realpath($sessionDirectory), 'fixture' => $fixture]) {
            throw new RuntimeException('SIMULATION_MANIFEST_PATHS');
        }
    }

    /**
     * The session folder is `<private root>/arasya-simulation/sessions/<session id>`, 0700, and outside every web
     * root: no path segment may be a published host directory or public_html.
     */
    public static function assertPrivateDirectory(string $directory, string $id): void
    {
        $real = realpath($directory);
        if ($real === false || !is_dir($real) || basename($real) !== $id || basename(dirname($real)) !== 'sessions' || basename(dirname($real, 2)) !== 'arasya-simulation') {
            throw new RuntimeException('SIMULATION_PRIVATE_BOUNDARY');
        }
        if ((fileperms($real) & 0077) !== 0) throw new RuntimeException('SIMULATION_PRIVATE_MANIFEST_PERMISSIONS');
        if (self::insideWebRoot($real)) throw new RuntimeException('SIMULATION_PRIVATE_BOUNDARY');
    }

    public static function insideWebRoot(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['public_html', 'www', 'htdocs'], true) || preg_match('/(?:^|\.)arasyahome\.ro$/D', $segment)) return true;
        }
        return false;
    }

    /** The hosted metadata that must survive when initialization rewrites the manifest with its accounts. */
    public static function metadata(array $manifest): array
    {
        return array_intersect_key($manifest, array_flip(['schemaVersion', 'kind', 'origins', 'database', 'paths']));
    }
}
