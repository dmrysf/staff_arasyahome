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
        /** Seconds the live event stream may wait for new events before answering (0 = answer at once). */
        public int $liveHoldSeconds = 0,
        /**
         * Declared signed sources in configuration order, exactly as written (may contain invalid or
         * duplicate entries, which the SourceRegistry reports and fails closed). Empty means "derive
         * from sourceSecrets/sourceSettings".
         * @var list<string>
         */
        public array $sourceKeys = [],
        /**
         * Non-secret per-source settings (mode, enabled, displayName, integrationType, authority, qrAuthority, documentAuthority, trackingAuthority) as raw strings.
         * @var array<string, array<string, string>>
         */
        public array $sourceSettings = [],
        /**
         * Trendyol intake switch as written (ARASYA_TRENDYOL_INTAKE). Only the exact value `enabled` lets the
         * synchronization read Trendyol into the intake inbox; credentials alone never do. Anything else is off.
         */
        public string $trendyolIntake = 'disabled',
        /**
         * Test-only stand-in for the Trendyol Order V2 API (ARASYA_TRENDYOL_FIXTURE_FILE): a JSON file answered by
         * FixtureTrendyolTransport. Refused in production, where only the real HTTPS API is ever called.
         */
        public ?string $trendyolFixtureFile = null,
        /**
         * Server switch of the outbound cancellation synchronization (ARASYA_CANCELLATION_SYNC, API 2.36.0). Only the
         * exact value `enabled` lets the worker contact a provider or hand a request to a source connector; the
         * per-provider database flags must be enabled as well. Anything else is off (the default).
         */
        public string $cancellationSync = 'disabled',
        /** Trendyol storeFrontCode header for the unsupplied notice (ARASYA_TRENDYOL_STORE_FRONT_CODE); null: not configured. */
        public ?string $trendyolStoreFrontCode = null,
        /**
         * Raw source access settings per source (API 2.40.0): mode, url and key (`<kid>:<key>`), validated by
         * SourceAccessRegistry, which keeps a source with any invalid value off. Never dumped.
         * @var array<string, array{mode: string, url: string, key: string}>
         */
        #[\SensitiveParameter] public array $sourceAccess = [],
        /**
         * Finance V0.3 opening-balance posting switch (ARASYA_FINANCE_OPENING_BALANCE_POSTING). Only the exact value
         * `enabled` lets a verified opening balance be written to the B2B ledger. Anything else is off (the default).
         */
        public string $financeOpeningBalancePosting = 'disabled',
        /**
         * Employee UUID of the only person who may decide Finance CEO exceptions (ARASYA_FINANCE_CEO_EMPLOYEE_UUID).
         * Empty (the default) means no one can. Never inferred from a name, username or role.
         */
        public string $financeCeoEmployeeUuid = '',
        /**
         * Comma-separated employee UUIDs allowed to make an effective risk-colour or credit-limit change
         * (ARASYA_FINANCE_RISK_LIMIT_AUTHORITY_UUIDS). Empty (the default) means no one, whatever role they hold.
         * Provisioned only on the owner's explicit instruction; never inferred from a name, username or role.
         */
        public string $financeRiskLimitAuthorityUuids = '',
        /**
         * Finance decision at B2B finalization (ARASYA_B2B_FINANCE_GATE, API 2.67.0): off (default), shadow or enforce.
         * The value is kept exactly as configured. Any other value is a configuration failure that FinanceGate refuses
         * with 503 on the gated B2B actions only; it is never read as off, and the rest of the API keeps working.
         * Activation is an explicit live decision (FinanceConfigGuard blocks an unreviewed one).
         */
        public string $b2bFinanceGate = 'off',
        /**
         * Finance release check at B2B production submission (ARASYA_B2B_FINANCE_RELEASE_GATE): off, shadow or enforce.
         * Empty (the default, key absent) inherits ARASYA_B2B_FINANCE_GATE. Any other value is refused like the gate.
         */
        public string $b2bFinanceReleaseGate = '',
        /**
         * Explicit B2B order types (ARASYA_B2B_ORDER_TYPES, API 2.68.0): only the exact value `enabled` announces the
         * `orderTypes` feature, accepts an order type on drafts and requires it at finalization. Anything else, and the
         * default (absent), keeps B2B on the full route without a type, exactly as API 2.66.0. Independent of the finance
         * gate: enabling one never enables the other.
         */
        public string $b2bOrderTypes = 'off',
        /**
         * Who writes B2B current-account movements by hand (ARASYA_B2B_ACCOUNT_WRITES, API 2.68.0). Absent or `enabled`:
         * the B2B payment, allocation, opening-balance, adjustment and reversal routes work as before. `finance_only`:
         * those routes refuse every request on the server and B2B shows the account read-only; payments, corrections and
         * refunds go through Finance requests with a second approver. Any other value is treated as `finance_only` (it
         * never widens what B2B may write). Order receivables and their cancellation reversals are unaffected.
         */
        public string $b2bAccountWrites = 'enabled',
        /**
         * Finance collections switch (ARASYA_FINANCE_COLLECTIONS, API 2.68.0). Only the exact value `enabled` lets Finance
         * create, approve or post payment, adjustment, reversal and refund requests or allocations. Reads always work.
         */
        public string $financeCollections = 'disabled',
    ) {
        foreach ($this->financeRiskLimitAuthorityList() as $uuid) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid) !== 1) {
                throw new RuntimeException('ARASYA_FINANCE_RISK_LIMIT_AUTHORITY_UUIDS must list employee UUIDs.');
            }
        }
        if ($this->financeCeoEmployeeUuid !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $this->financeCeoEmployeeUuid) !== 1) {
            throw new RuntimeException('ARASYA_FINANCE_CEO_EMPLOYEE_UUID must be an employee UUID.');
        }
        if ($this->trendyolStoreFrontCode !== null && preg_match('/^[A-Z]{2,5}$/D', $this->trendyolStoreFrontCode) !== 1) {
            throw new RuntimeException('ARASYA_TRENDYOL_STORE_FRONT_CODE must be 2-5 capital letters.');
        }
        if ($this->trendyolFixtureFile !== null && $this->isProduction()) {
            throw new RuntimeException('ARASYA_TRENDYOL_FIXTURE_FILE is not allowed in production.');
        }
        if ($this->liveHoldSeconds < 0 || $this->liveHoldSeconds > 25) {
            throw new RuntimeException('ARASYA_LIVE_HOLD_SECONDS must be between 0 and 25.');
        }
        if (strlen($this->appSecret) < 32) {
            throw new RuntimeException('ARASYA_APP_SECRET must contain at least 32 bytes.');
        }
        if ($this->allowedOrigins === []) {
            throw new RuntimeException('ARASYA_ALLOWED_ORIGINS must contain at least one exact origin.');
        }
        foreach ($this->sourceSecrets as $sourceKey => $secret) {
            if (preg_match('/^[a-z0-9_-]{1,40}$/D', (string) $sourceKey) !== 1 || strlen($secret) < 32 || str_starts_with($secret, 'replace-with') || str_starts_with($secret, 'CHANGE_ME') || str_starts_with($secret, '<')) {
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
        // The two first-party WooCommerce sources stay declared by default so existing installs keep working.
        $sourceKeys = self::csv(self::value($values, 'ARASYA_SOURCE_KEYS', 'trendhome,outletperdele'));
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
            sourceSecrets: self::sourceSecrets($values, $sourceKeys),
            sourceFreshSeconds: self::positiveInt($values, 'ARASYA_SOURCE_FRESH_SECONDS', 900),
            sourceUnavailableSeconds: self::positiveInt($values, 'ARASYA_SOURCE_UNAVAILABLE_SECONDS', 3600),
            trendyol: TrendyolCredentials::fromValues(
                self::value($values, 'ARASYA_TRENDYOL_SELLER_ID', ''),
                self::value($values, 'ARASYA_TRENDYOL_API_KEY', ''),
                self::value($values, 'ARASYA_TRENDYOL_API_SECRET', ''),
                self::value($values, 'ARASYA_TRENDYOL_API_BASE_URL', ''),
            ),
            idempotencyRetentionDays: self::positiveInt($values, 'ARASYA_IDEMPOTENCY_RETENTION_DAYS', 30),
            liveHoldSeconds: self::nonNegativeInt($values, 'ARASYA_LIVE_HOLD_SECONDS', 0),
            sourceKeys: $sourceKeys,
            sourceSettings: self::sourceSettings($values, $sourceKeys),
            trendyolIntake: self::value($values, 'ARASYA_TRENDYOL_INTAKE', 'disabled'),
            trendyolFixtureFile: self::value($values, 'ARASYA_TRENDYOL_FIXTURE_FILE', '') === '' ? null : self::value($values, 'ARASYA_TRENDYOL_FIXTURE_FILE', ''),
            cancellationSync: self::value($values, 'ARASYA_CANCELLATION_SYNC', 'disabled'),
            trendyolStoreFrontCode: self::value($values, 'ARASYA_TRENDYOL_STORE_FRONT_CODE', '') === '' ? null : self::value($values, 'ARASYA_TRENDYOL_STORE_FRONT_CODE', ''),
            sourceAccess: self::sourceAccess($values, $sourceKeys),
            financeOpeningBalancePosting: self::value($values, 'ARASYA_FINANCE_OPENING_BALANCE_POSTING', 'disabled'),
            financeCeoEmployeeUuid: strtolower(self::value($values, 'ARASYA_FINANCE_CEO_EMPLOYEE_UUID', '')),
            financeRiskLimitAuthorityUuids: strtolower(self::value($values, 'ARASYA_FINANCE_RISK_LIMIT_AUTHORITY_UUIDS', '')),
            b2bFinanceGate: self::value($values, 'ARASYA_B2B_FINANCE_GATE', 'off'),
            b2bFinanceReleaseGate: self::value($values, 'ARASYA_B2B_FINANCE_RELEASE_GATE', ''),
            b2bOrderTypes: self::value($values, 'ARASYA_B2B_ORDER_TYPES', 'off'),
            b2bAccountWrites: self::value($values, 'ARASYA_B2B_ACCOUNT_WRITES', 'enabled'),
            financeCollections: self::value($values, 'ARASYA_FINANCE_COLLECTIONS', 'disabled'),
        );
    }

    /** Whether the server switch allows outbound cancellation synchronization at all (provider flags still apply). */
    public function cancellationSyncEnabled(): bool
    {
        return $this->cancellationSync === 'enabled';
    }

    /** Whether verified Finance opening balances may be posted to the B2B ledger. */
    /** @return list<string> */
    public function financeRiskLimitAuthorityList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->financeRiskLimitAuthorityUuids)), static fn (string $uuid): bool => $uuid !== ''));
    }

    /** The configured finalization mode, unchanged. FinanceGate validates it; an unknown value is never read as off. */
    public function b2bFinanceGateMode(): string
    {
        return $this->b2bFinanceGate;
    }

    /** The configured production submission mode, unchanged, or null when the key is absent (inherits the gate mode). */
    public function b2bFinanceReleaseGateMode(): ?string
    {
        return $this->b2bFinanceReleaseGate === '' ? null : $this->b2bFinanceReleaseGate;
    }

    /** Whether B2B drafts carry an explicit order type (and finalization requires one). Default off. */
    public function b2bOrderTypesEnabled(): bool
    {
        return $this->b2bOrderTypes === 'enabled';
    }

    /** Whether the B2B current-account write routes are open. Only absent or exactly `enabled` keeps them open. */
    public function b2bAccountWritesEnabled(): bool
    {
        return $this->b2bAccountWrites === 'enabled';
    }

    /** Whether Finance may create and post ledger requests (payments, corrections, refunds) and allocations. */
    public function financeCollectionsEnabled(): bool
    {
        return $this->financeCollections === 'enabled';
    }

    /** Whether the finance gate evaluates anything (either check in shadow or enforce, or a value FinanceGate refuses). */
    public function b2bFinanceGateActive(): bool
    {
        $release = $this->b2bFinanceReleaseGateMode();
        return $this->b2bFinanceGate !== 'off' || ($release !== null && $release !== 'off');
    }

    public function financeOpeningBalancePostingEnabled(): bool
    {
        return $this->financeOpeningBalancePosting === 'enabled';
    }

    /** Whether the Trendyol intake switch is on (a database activation with a baseline is still required). */
    public function trendyolIntakeEnabled(): bool
    {
        return $this->trendyolIntake === 'enabled';
    }

    /** Environment suffix of a source key: `outlet-perdele` reads ARASYA_SOURCE_*_OUTLET_PERDELE. */
    public static function sourceEnvironmentSuffix(string $sourceKey): string
    {
        return strtoupper(str_replace('-', '_', $sourceKey));
    }

    /** @param array<string, string> $values @param list<string> $sourceKeys @return array<string, string> */
    private static function sourceSecrets(array $values, array $sourceKeys): array
    {
        $secrets = [];
        foreach ($sourceKeys as $sourceKey) {
            if (preg_match('/^[a-z0-9_-]{1,40}$/D', $sourceKey) !== 1) {
                continue;
            }
            $secret = self::value($values, 'ARASYA_SOURCE_SECRET_' . self::sourceEnvironmentSuffix($sourceKey), '');
            if ($secret !== '') {
                $secrets[$sourceKey] = $secret;
            }
        }
        return $secrets;
    }

    /** @param array<string, string> $values @param list<string> $sourceKeys @return array<string, array{mode: string, url: string, key: string, finance: string, invoice: string, verification: string}> */
    private static function sourceAccess(array $values, array $sourceKeys): array
    {
        $access = [];
        foreach ($sourceKeys as $sourceKey) {
            if (preg_match('/^[a-z0-9_-]{1,40}$/D', $sourceKey) !== 1) {
                continue;
            }
            $suffix = self::sourceEnvironmentSuffix($sourceKey);
            $mode = self::value($values, "ARASYA_SOURCE_ACCESS_{$suffix}", '');
            if ($mode !== '') {
                $access[$sourceKey] = [
                    'mode' => $mode,
                    'url' => self::value($values, "ARASYA_SOURCE_ACCESS_URL_{$suffix}", ''),
                    'key' => self::value($values, "ARASYA_SOURCE_ACCESS_KEY_{$suffix}", ''),
                    // API 2.41.0 (Confirmare Phase 5): off (default) | read | record.
                    'finance' => self::value($values, "ARASYA_SOURCE_FINANCE_{$suffix}", ''),
                    'invoice' => self::value($values, "ARASYA_SOURCE_INVOICE_{$suffix}", ''),
                    // API 2.50.0: four_eyes (default) | single.
                    'verification' => self::value($values, "ARASYA_SOURCE_FINANCE_VERIFICATION_{$suffix}", ''),
                    // API 2.50.0: off (default) | auto: the minute's distribution also runs the finance task inventory.
                    'tasks' => self::value($values, "ARASYA_SOURCE_FINANCE_TASKS_{$suffix}", ''),
                ];
            }
        }
        return $access;
    }

    /** @param array<string, string> $values @param list<string> $sourceKeys @return array<string, array<string, string>> */
    private static function sourceSettings(array $values, array $sourceKeys): array
    {
        $settings = [];
        foreach ($sourceKeys as $sourceKey) {
            if (preg_match('/^[a-z0-9_-]{1,40}$/D', $sourceKey) !== 1) {
                continue;
            }
            $suffix = self::sourceEnvironmentSuffix($sourceKey);
            $settings[$sourceKey] = array_filter([
                'mode' => self::value($values, "ARASYA_SOURCE_MODE_{$suffix}", ''),
                'enabled' => self::value($values, "ARASYA_SOURCE_ENABLED_{$suffix}", ''),
                'displayName' => self::value($values, "ARASYA_SOURCE_NAME_{$suffix}", ''),
                'integrationType' => self::value($values, "ARASYA_SOURCE_TYPE_{$suffix}", ''),
                'authority' => self::value($values, "ARASYA_SOURCE_AUTHORITY_{$suffix}", ''),
                'qrAuthority' => self::value($values, "ARASYA_SOURCE_QR_AUTHORITY_{$suffix}", ''),
                'documentAuthority' => self::value($values, "ARASYA_SOURCE_DOCUMENT_AUTHORITY_{$suffix}", ''),
                'trackingAuthority' => self::value($values, "ARASYA_SOURCE_TRACKING_AUTHORITY_{$suffix}", ''),
                'paymentGate' => self::value($values, "ARASYA_SOURCE_PAYMENT_GATE_{$suffix}", ''),
                'confirmare' => self::value($values, "ARASYA_SOURCE_CONFIRMARE_{$suffix}", ''),
            ], static fn (string $value): bool => $value !== '');
            // The advance payment gate stops production. Before 2.37.1 the loader dropped its key, so every deployment
            // ran with the gate OFF whatever the private file said. A value other than `off` is therefore honoured only
            // when ARASYA_SOURCE_PAYMENT_GATE_VERIFIED_<SOURCE> repeats it: deploying the loader fix changes nothing until
            // an operator confirms the reviewed value. An unconfirmed value runs as `off` and is reported (readiness FAIL).
            $gate = strtolower(trim($settings[$sourceKey]['paymentGate'] ?? ''));
            if ($gate !== '' && $gate !== 'off' && strtolower(trim(self::value($values, "ARASYA_SOURCE_PAYMENT_GATE_VERIFIED_{$suffix}", ''))) !== $gate) {
                $settings[$sourceKey]['paymentGate'] = 'off';
                $settings[$sourceKey]['paymentGateUnverified'] = $gate;
            }
        }
        return $settings;
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
    private static function nonNegativeInt(array $values, string $name, int $default): int
    {
        $value = self::value($values, $name, (string) $default);
        if (!ctype_digit($value)) {
            throw new RuntimeException("{$name} must be a non-negative integer.");
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
