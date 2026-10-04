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
        public int $sessionRecordRetentionDays = 30,
        public int $loginAttemptRetentionDays = 30,
        public int $rateLimitRetentionDays = 7,
        public ?int $authAuditRetentionDays = null,
        /** @var array<string, string> source key => webhook HMAC secret */
        public array $sourceSecrets = [],
        public int $sourceFreshSeconds = 900,
        public int $sourceUnavailableSeconds = 3600,
        public ?TrendyolCredentials $trendyol = null,
        public int $idempotencyRetentionDays = 30,
    ) {
        if (strlen($this->appSecret) < 32) {
            throw new RuntimeException('ARASYA_APP_SECRET must contain at least 32 bytes.');
        }
        if ($this->allowedOrigins === []) {
            throw new RuntimeException('ARASYA_ALLOWED_ORIGINS must contain at least one exact origin.');
        }
        foreach ($this->sourceSecrets as $sourceKey => $secret) {
            if (preg_match('/^[a-z0-9_-]{1,40}$/D', (string) $sourceKey) !== 1 || strlen($secret) < 32 || str_starts_with($secret, 'replace-with') || str_starts_with($secret, '<')) {
                throw new RuntimeException('Source webhook secrets must contain at least 32 bytes.');
            }
        }
        if ($this->sourceUnavailableSeconds < $this->sourceFreshSeconds) {
            throw new RuntimeException('ARASYA_SOURCE_UNAVAILABLE_SECONDS must not be lower than ARASYA_SOURCE_FRESH_SECONDS.');
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

    public static function fromEnvironment(?ConfigLoader $loader = null): self
    {
        $values = ($loader ?? new ConfigLoader())->load();
        $environment = self::value($values, 'ARASYA_APP_ENV', 'production');
        return new self(
            environment: $environment,
            appSecret: self::required($values, 'ARASYA_APP_SECRET'),
            dbHost: self::value($values, 'ARASYA_DB_HOST', 'localhost'),
            dbPort: self::positiveInt($values, 'ARASYA_DB_PORT', 3306),
            dbName: self::required($values, 'ARASYA_DB_NAME'),
            dbUser: self::required($values, 'ARASYA_DB_USER'),
            dbPassword: self::required($values, 'ARASYA_DB_PASSWORD'),
            allowedOrigins: self::csv(self::required($values, 'ARASYA_ALLOWED_ORIGINS')),
            sessionTtlSeconds: self::positiveInt($values, 'ARASYA_SESSION_TTL', 36_000),
            sessionTouchIntervalSeconds: self::positiveInt($values, 'ARASYA_SESSION_TOUCH_INTERVAL', 300),
            loginUsernameLimit: self::positiveInt($values, 'ARASYA_LOGIN_USERNAME_LIMIT', 5),
            loginIpLimit: self::positiveInt($values, 'ARASYA_LOGIN_IP_LIMIT', 30),
            loginWindowSeconds: self::positiveInt($values, 'ARASYA_LOGIN_WINDOW', 900),
            trustProxy: filter_var(self::value($values, 'ARASYA_TRUST_PROXY', 'false'), FILTER_VALIDATE_BOOL),
            trustedProxies: self::csv(self::value($values, 'ARASYA_TRUSTED_PROXIES', '')),
            sessionRecordRetentionDays: self::positiveInt($values, 'ARASYA_SESSION_RECORD_RETENTION_DAYS', 30),
            loginAttemptRetentionDays: self::positiveInt($values, 'ARASYA_LOGIN_ATTEMPT_RETENTION_DAYS', 30),
            rateLimitRetentionDays: self::positiveInt($values, 'ARASYA_RATE_LIMIT_RETENTION_DAYS', 7),
            authAuditRetentionDays: self::optionalPositiveInt($values, 'ARASYA_AUTH_AUDIT_RETENTION_DAYS'),
            sourceSecrets: array_filter([
                'trendhome' => self::value($values, 'ARASYA_SOURCE_SECRET_TRENDHOME', ''),
                'outletperdele' => self::value($values, 'ARASYA_SOURCE_SECRET_OUTLETPERDELE', ''),
            ], static fn (string $secret): bool => $secret !== ''),
            sourceFreshSeconds: self::positiveInt($values, 'ARASYA_SOURCE_FRESH_SECONDS', 900),
            sourceUnavailableSeconds: self::positiveInt($values, 'ARASYA_SOURCE_UNAVAILABLE_SECONDS', 3600),
            trendyol: TrendyolCredentials::fromValues(
                self::value($values, 'ARASYA_TRENDYOL_SELLER_ID', ''),
                self::value($values, 'ARASYA_TRENDYOL_API_KEY', ''),
                self::value($values, 'ARASYA_TRENDYOL_API_SECRET', ''),
                self::value($values, 'ARASYA_TRENDYOL_API_BASE_URL', ''),
            ),
            idempotencyRetentionDays: self::positiveInt($values, 'ARASYA_IDEMPOTENCY_RETENTION_DAYS', 30),
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

    /** @param array<string, string> $values */
    private static function required(array $values, string $name): string
    {
        $value = trim($values[$name] ?? '');
        if ($value === '') {
            throw new RuntimeException("Missing required configuration value: {$name}");
        }
        return $value;
    }

    /** @param array<string, string> $values */
    private static function value(array $values, string $name, string $default): string
    {
        return array_key_exists($name, $values) ? trim($values[$name]) : $default;
    }

    /** @param array<string, string> $values */
    private static function positiveInt(array $values, string $name, int $default): int
    {
        $value = self::value($values, $name, (string) $default);
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException("{$name} must be a positive integer.");
        }
        return (int) $value;
    }

    /** @param array<string, string> $values */
    private static function optionalPositiveInt(array $values, string $name): ?int
    {
        if (!array_key_exists($name, $values) || trim($values[$name]) === '') {
            return null;
        }
        return self::positiveInt($values, $name, 1);
    }

    /** @return list<string> */
    private static function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
    }
}
