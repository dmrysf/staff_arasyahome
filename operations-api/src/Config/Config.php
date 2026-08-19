<?php

declare(strict_types=1);

namespace Arasya\Operations\Config;

use RuntimeException;

final readonly class Config
{
    /** @param list<string> $allowedOrigins @param list<string> $trustedProxies */
    public function __construct(
        public string $environment,
        public string $appSecret,
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
        public array $allowedOrigins,
        public int $sessionTtlSeconds,
        public int $sessionTouchIntervalSeconds,
        public int $loginUsernameLimit,
        public int $loginIpLimit,
        public int $loginWindowSeconds,
        public bool $trustProxy,
        public array $trustedProxies,
    ) {
        if (strlen($this->appSecret) < 32) {
            throw new RuntimeException('ARASYA_APP_SECRET must contain at least 32 bytes.');
        }
        if ($this->allowedOrigins === []) {
            throw new RuntimeException('ARASYA_ALLOWED_ORIGINS must contain at least one exact origin.');
        }
        foreach ($this->allowedOrigins as $origin) {
            $parts = parse_url($origin);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['path'], $parts['query'], $parts['fragment'])) {
                throw new RuntimeException('Allowed origins must be exact scheme and host values.');
            }
            if ($this->isProduction() && $parts['scheme'] !== 'https') {
                throw new RuntimeException('Production origins must use HTTPS.');
            }
        }
    }

    public static function fromEnvironment(): self
    {
        $environment = self::env('ARASYA_APP_ENV', 'production');
        return new self(
            environment: $environment,
            appSecret: self::required('ARASYA_APP_SECRET'),
            dbHost: self::env('ARASYA_DB_HOST', '127.0.0.1'),
            dbPort: self::positiveInt('ARASYA_DB_PORT', 3306),
            dbName: self::required('ARASYA_DB_NAME'),
            dbUser: self::required('ARASYA_DB_USER'),
            dbPassword: self::required('ARASYA_DB_PASSWORD'),
            allowedOrigins: self::csv(self::required('ARASYA_ALLOWED_ORIGINS')),
            sessionTtlSeconds: self::positiveInt('ARASYA_SESSION_TTL', 36_000),
            sessionTouchIntervalSeconds: self::positiveInt('ARASYA_SESSION_TOUCH_INTERVAL', 300),
            loginUsernameLimit: self::positiveInt('ARASYA_LOGIN_USERNAME_LIMIT', 5),
            loginIpLimit: self::positiveInt('ARASYA_LOGIN_IP_LIMIT', 30),
            loginWindowSeconds: self::positiveInt('ARASYA_LOGIN_WINDOW', 900),
            trustProxy: filter_var(self::env('ARASYA_TRUST_PROXY', 'false'), FILTER_VALIDATE_BOOL),
            trustedProxies: self::csv(self::env('ARASYA_TRUSTED_PROXIES', '')),
        );
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    public function cookieName(): string
    {
        return $this->isProduction() ? '__Host-arasya_session' : 'arasya_session';
    }

    private static function required(string $name): string
    {
        $value = trim((string) getenv($name));
        if ($value === '') {
            throw new RuntimeException("Missing required environment variable: {$name}");
        }
        return $value;
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);
        return $value === false ? $default : trim($value);
    }

    private static function positiveInt(string $name, int $default): int
    {
        $value = self::env($name, (string) $default);
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException("{$name} must be a positive integer.");
        }
        return (int) $value;
    }

    /** @return list<string> */
    private static function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
    }
}

