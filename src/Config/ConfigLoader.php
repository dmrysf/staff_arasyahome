<?php

declare(strict_types=1);

namespace Arasya\Operations\Config;

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

    /** @param array<string, scalar|null>|null $environment */
    public function __construct(private ?array $environment = null)
    {
    }

    /** @return array<string, string> */
    public function load(): array
    {
        $overridePath = trim($this->environmentValue('ARASYA_CONFIG_FILE') ?? '');
        $path = $overridePath;
        if ($path === '') {
            $home = trim($this->environmentValue('HOME') ?? '');
            if ($home === '') {
                throw new RuntimeException('HOME is required when ARASYA_CONFIG_FILE is not configured.');
            }
            $path = rtrim($home, DIRECTORY_SEPARATOR) . '/arasya-config/operations-api.php';
        }

        $privateValues = [];
        if (is_file($path)) {
            if (!is_readable($path)) {
                throw new RuntimeException('The private configuration file is not readable.');
            }
            $this->assertOutsideRelease($path);
            $loaded = (static fn (string $file): mixed => require $file)($path);
            if (!is_array($loaded)) {
                throw new RuntimeException('The private configuration file must return an array.');
            }
            $privateValues = $loaded;
        } elseif ($overridePath !== '') {
            throw new RuntimeException('ARASYA_CONFIG_FILE does not reference a readable file.');
        }

        $values = [];
        foreach (self::CONFIG_KEYS as $key) {
            $environmentValue = $this->environmentValue($key);
            if ($environmentValue !== null) {
                $values[$key] = trim($environmentValue);
                continue;
            }
            if (array_key_exists($key, $privateValues)) {
                $values[$key] = $this->stringValue($key, $privateValues[$key]);
            }
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

    private function assertOutsideRelease(string $path): void
    {
        $realPath = realpath($path);
        $releaseRoot = realpath(dirname(__DIR__, 2));
        if ($realPath === false || $releaseRoot === false) {
            throw new RuntimeException('The private configuration path could not be resolved safely.');
        }
        if ($realPath === $releaseRoot || str_starts_with($realPath, $releaseRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The private configuration file must be outside the API release directory.');
        }
    }
}
