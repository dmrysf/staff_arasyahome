<?php

declare(strict_types=1);

namespace Arasya\Operations\Config;

use JsonException;
use RuntimeException;

final readonly class ConfigLoader
{
    /** @var list<string> */
    private const CONFIG_KEYS = [
        'ARASYA_APP_ENV',
        'ARASYA_APP_SECRET',
        'ARASYA_DB_HOST',
        'ARASYA_DB_PORT',
        'ARASYA_DB_NAME',
        'ARASYA_DB_USER',
        'ARASYA_DB_PASSWORD',
        'ARASYA_ALLOWED_ORIGINS',
        'ARASYA_SESSION_TTL',
        'ARASYA_SESSION_TOUCH_INTERVAL',
        'ARASYA_LOGIN_USERNAME_LIMIT',
        'ARASYA_LOGIN_IP_LIMIT',
        'ARASYA_LOGIN_WINDOW',
        'ARASYA_TRUST_PROXY',
        'ARASYA_TRUSTED_PROXIES',
        'ARASYA_SESSION_RECORD_RETENTION_DAYS',
        'ARASYA_LOGIN_ATTEMPT_RETENTION_DAYS',
        'ARASYA_RATE_LIMIT_RETENTION_DAYS',
        'ARASYA_AUTH_AUDIT_RETENTION_DAYS',
        'ARASYA_IDEMPOTENCY_RETENTION_DAYS',
        'ARASYA_LIVE_HOLD_SECONDS',
        'ARASYA_SOURCE_KEYS',
        'ARASYA_SOURCE_FRESH_SECONDS',
        'ARASYA_SOURCE_UNAVAILABLE_SECONDS',
        'ARASYA_TRENDYOL_SELLER_ID',
        'ARASYA_TRENDYOL_API_KEY',
        'ARASYA_TRENDYOL_API_SECRET',
        'ARASYA_TRENDYOL_API_BASE_URL',
    ];

    /**
     * Per-source settings are keyed by the source key in upper case (`-` becomes `_`), for example
     * ARASYA_SOURCE_SECRET_TRENDHOME or ARASYA_SOURCE_MODE_OUTLETPERDELE.
     */
    private const SOURCE_KEY_PATTERN = '/^ARASYA_SOURCE_(?:SECRET|MODE|ENABLED|NAME|TYPE)_[A-Z0-9_]{1,40}$/D';

    /** @var array<string, string> */
    private const ALIASES = [
        'DB_USER_NAME' => 'ARASYA_DB_USER',
        'DB_USER_PASSWORD' => 'ARASYA_DB_PASSWORD',
        'DB_NAME' => 'ARASYA_DB_NAME',
        'DB_HOST' => 'ARASYA_DB_HOST',
        'DB_PORT' => 'ARASYA_DB_PORT',
    ];

    /** @param array<string, scalar|null>|null $environment */
    public function __construct(
        private ?array $environment = null,
        private ?string $releaseRoot = null,
    )
    {
    }

    /** @return array<string, string> */
    public function load(): array
    {
        $overridePath = trim($this->environmentValue('ARASYA_CONFIG_FILE') ?? '');
        $home = $this->resolveHome($overridePath === '');
        $path = $this->selectPrivateFile($overridePath, $home);
        $privateValues = $path === null ? [] : $this->loadPrivateFile($path, $home);

        $values = [];
        foreach ($this->knownKeys($privateValues) as $key) {
            $environmentValue = $this->environmentValue($key);
            if ($environmentValue !== null) {
                $values[$key] = trim($environmentValue);
                continue;
            }
            if (array_key_exists($key, $privateValues)) {
                $values[$key] = $privateValues[$key];
            }
        }
        return $values;
    }

    /** @param array<string, string> $privateValues @return list<string> */
    private function knownKeys(array $privateValues): array
    {
        $names = array_keys($privateValues);
        foreach (array_keys($this->environment ?? getenv()) as $name) {
            $names[] = (string) $name;
        }
        $sourceKeys = array_filter($names, static fn (string $name): bool => preg_match(self::SOURCE_KEY_PATTERN, $name) === 1);
        return array_values(array_unique([...self::CONFIG_KEYS, ...$sourceKeys]));
    }

    public function privateFilePath(): ?string
    {
        $overridePath = trim($this->environmentValue('ARASYA_CONFIG_FILE') ?? '');
        $home = $this->resolveHome($overridePath === '');
        $path = $this->selectPrivateFile($overridePath, $home);
        if ($path !== null) {
            $this->assertPrivatePath($path, $home);
        }
        return $path;
    }

    private function resolveHome(bool $required): string
    {
        $environmentHome = trim($this->environmentValue('HOME') ?? '');
        if ($environmentHome !== '') {
            $resolved = $this->resolvedDirectory($environmentHome);
            if ($resolved === null) {
                throw new RuntimeException('HOME could not be resolved safely.');
            }
            return $resolved;
        }

        $releaseRoot = $this->actualReleaseRoot();
        $runtimeParent = dirname($releaseRoot);
        if (basename($releaseRoot) === 'current' && basename($runtimeParent) === 'arasya-operations-api') {
            $derivedHome = $this->resolvedDirectory(dirname($runtimeParent));
            if ($derivedHome !== null && $derivedHome !== DIRECTORY_SEPARATOR) {
                return $derivedHome;
            }
        }
        if (preg_match('/^[0-9a-f]{40}$/', basename($releaseRoot)) === 1
            && basename($runtimeParent) === 'releases'
            && basename(dirname($runtimeParent)) === 'arasya-operations-api') {
            $derivedHome = $this->resolvedDirectory(dirname($runtimeParent, 2));
            if ($derivedHome !== null && $derivedHome !== DIRECTORY_SEPARATOR) {
                return $derivedHome;
            }
        }

        if ($required) {
            throw new RuntimeException('The private configuration home could not be resolved safely.');
        }
        return '';
    }

    private function actualReleaseRoot(): string
    {
        $candidate = $this->releaseRoot ?? dirname(__DIR__, 2);
        $resolved = $this->resolvedDirectory($candidate);
        if ($resolved === null) {
            throw new RuntimeException('The API release root could not be resolved safely.');
        }
        return $resolved;
    }

    private function resolvedDirectory(string $candidate): ?string
    {
        if (!str_starts_with($candidate, DIRECTORY_SEPARATOR) || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $candidate) === 1) {
            return null;
        }
        $resolved = realpath($candidate);
        return $resolved !== false && is_dir($resolved) ? $resolved : null;
    }

    private function selectPrivateFile(string $overridePath, string $home): ?string
    {
        if ($overridePath !== '') {
            if (!is_file($overridePath) || !is_readable($overridePath)) {
                throw new RuntimeException('ARASYA_CONFIG_FILE does not reference a readable file.');
            }
            return $overridePath;
        }
        if ($home === '') {
            throw new RuntimeException('HOME is required when ARASYA_CONFIG_FILE is not configured.');
        }
        $configRoot = rtrim($home, DIRECTORY_SEPARATOR) . '/arasya-config';
        $jsonPath = $configRoot . '/secrets.json';
        if (is_file($jsonPath)) {
            return $jsonPath;
        }
        $legacyPhpPath = $configRoot . '/operations-api.php';
        return is_file($legacyPhpPath) ? $legacyPhpPath : null;
    }

    /** @return array<string, string> */
    private function loadPrivateFile(string $path, string $home): array
    {
        $this->assertPrivatePath($path, $home);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'json') {
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException('The private JSON configuration could not be read.');
            }
            try {
                $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('The private JSON configuration is malformed.');
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new RuntimeException('The private JSON configuration root must be an object.');
            }
            return $this->normalizePrivateValues($decoded);
        }
        if ($extension === 'php') {
            $loaded = (static fn (string $file): mixed => require $file)($path);
            if (!is_array($loaded) || array_is_list($loaded)) {
                throw new RuntimeException('The private PHP configuration must return an associative array.');
            }
            return $this->normalizePrivateValues($loaded);
        }
        throw new RuntimeException('Private configuration must use .json or .php.');
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    private function normalizePrivateValues(array $input): array
    {
        foreach (self::ALIASES as $alias => $canonical) {
            if (!array_key_exists($canonical, $input) && array_key_exists($alias, $input)) {
                $input[$canonical] = $input[$alias];
            }
        }

        $values = [];
        foreach (array_keys($input) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::CONFIG_KEYS, true) && preg_match(self::SOURCE_KEY_PATTERN, $key) !== 1) {
                continue;
            }
            $value = $input[$key];
            if ($key === 'ARASYA_ALLOWED_ORIGINS' && is_array($value)) {
                if (!array_is_list($value) || $value === []) {
                    throw new RuntimeException('ARASYA_ALLOWED_ORIGINS must be a non-empty list of exact origins.');
                }
                $origins = [];
                foreach ($value as $origin) {
                    if (!is_string($origin) || trim($origin) === '') {
                        throw new RuntimeException('ARASYA_ALLOWED_ORIGINS entries must be non-empty strings.');
                    }
                    $origins[] = trim($origin);
                }
                $values[$key] = implode(',', $origins);
                continue;
            }
            $values[$key] = trim($this->stringValue($key, $value));
        }
        return $values;
    }

    private function environmentValue(string $name): ?string
    {
        if ($this->environment !== null) {
            if (!array_key_exists($name, $this->environment) || $this->environment[$name] === null) {
                return null;
            }
            return $this->stringValue($name, $this->environment[$name]);
        }
        $value = getenv($name);
        return $value === false ? null : (string) $value;
    }

    private function stringValue(string $name, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new RuntimeException("Configuration value {$name} must be scalar.");
        }
        return (string) $value;
    }

    private function assertPrivatePath(string $path, string $home): void
    {
        $realPath = realpath($path);
        if ($realPath === false || !str_starts_with($realPath, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The private configuration path could not be resolved safely.');
        }

        $forbiddenRoots = [$this->actualReleaseRoot()];
        if ($home !== '') {
            $home = rtrim($home, DIRECTORY_SEPARATOR);
            $forbiddenRoots[] = $home . '/arasya-operations-api';
            $forbiddenRoots[] = $home . '/api.arasyahome.ro';
            $forbiddenRoots[] = $home . '/staff.arasyahome.ro';
        }
        foreach ($forbiddenRoots as $root) {
            $resolvedRoot = realpath($root);
            if ($resolvedRoot !== false && ($realPath === $resolvedRoot || str_starts_with($realPath, $resolvedRoot . DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('The private configuration file is inside a forbidden application or web root.');
            }
        }
    }
}
