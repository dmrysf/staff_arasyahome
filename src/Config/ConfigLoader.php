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
    ];

    /** @var array<string, string> */
    private const ALIASES = [
        'DB_USER_NAME' => 'ARASYA_DB_USER',
        'DB_USER_PASSWORD' => 'ARASYA_DB_PASSWORD',
        'DB_NAME' => 'ARASYA_DB_NAME',
        'DB_HOST' => 'ARASYA_DB_HOST',
        'DB_PORT' => 'ARASYA_DB_PORT',
    ];

    /** @param array<string, scalar|null>|null $environment */
    public function __construct(private ?array $environment = null)
    {
    }

    /** @return array<string, string> */
    public function load(): array
    {
        $home = trim($this->environmentValue('HOME') ?? '');
        $overridePath = trim($this->environmentValue('ARASYA_CONFIG_FILE') ?? '');
        $path = $this->selectPrivateFile($overridePath, $home);
        $privateValues = $path === null ? [] : $this->loadPrivateFile($path, $home);

        $values = [];
        foreach (self::CONFIG_KEYS as $key) {
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
        foreach (self::CONFIG_KEYS as $key) {
            if (!array_key_exists($key, $input)) {
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

        $forbiddenRoots = [dirname(__DIR__, 2)];
        if ($home !== '') {
            $home = rtrim($home, DIRECTORY_SEPARATOR);
            $forbiddenRoots[] = $home . '/arasya-operations-api/current';
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
