<?php

declare(strict_types=1);

// Legacy PHP alternative to the preferred $HOME/arasya-config/secrets.json.
// Replace every placeholder in the private copy outside the API release and Git.
return [
    'ARASYA_APP_ENV' => 'production',
    'ARASYA_APP_SECRET' => 'replace-with-at-least-32-random-bytes',
    'ARASYA_DB_HOST' => 'localhost',
    'ARASYA_DB_PORT' => '3306',
    'ARASYA_DB_NAME' => 'cpanel_database_name',
    'ARASYA_DB_USER' => 'cpanel_runtime_user',
    'ARASYA_DB_PASSWORD' => 'replace-with-database-password',
    'ARASYA_ALLOWED_ORIGINS' => 'https://staff.arasyahome.ro,https://dashboard.arasyahome.ro,https://b2b.arasyahome.ro',
    'ARASYA_SESSION_TTL' => '36000',
    'ARASYA_SESSION_TOUCH_INTERVAL' => '300',
    'ARASYA_LOGIN_USERNAME_LIMIT' => '5',
    'ARASYA_LOGIN_IP_LIMIT' => '30',
    'ARASYA_LOGIN_WINDOW' => '900',
    'ARASYA_SESSION_RECORD_RETENTION_DAYS' => '30',
    'ARASYA_LOGIN_ATTEMPT_RETENTION_DAYS' => '30',
    'ARASYA_RATE_LIMIT_RETENTION_DAYS' => '7',
    // Set only after the owner chooses an audit-retention policy.
    // 'ARASYA_AUTH_AUDIT_RETENTION_DAYS' => '365',
    'ARASYA_IDEMPOTENCY_RETENTION_DAYS' => '30',
    // Signed source delivery (YD SOFT-powered WooCommerce sites). One entry per website; see
    // docs/yd-soft-source-integration.md. Per-source keys use the upper-case source key
    // (`-` becomes `_`). Each secret must be a unique random value of at least 32 bytes shared
    // only with that website: never reuse one secret for two sources.
    // Mode is `validation` (signed checks only, no writes) or `active` (real ingestion);
    // a missing mode means `validation`. ARASYA_SOURCE_ENABLED_<KEY> = 'false' disables a source.
    'ARASYA_SOURCE_KEYS' => 'trendhome,outletperdele',
    // 'ARASYA_SOURCE_SECRET_TRENDHOME' => '<64 random hex characters>',
    'ARASYA_SOURCE_MODE_TRENDHOME' => 'validation',
    // 'ARASYA_SOURCE_SECRET_OUTLETPERDELE' => '<64 random hex characters>',
    'ARASYA_SOURCE_MODE_OUTLETPERDELE' => 'validation',
    // Optional per source: ARASYA_SOURCE_NAME_<KEY> (display name), ARASYA_SOURCE_TYPE_<KEY>
    // (only 'yd-soft-woocommerce'), ARASYA_SOURCE_ENABLED_<KEY> ('true' or 'false').
    'ARASYA_SOURCE_FRESH_SECONDS' => '900',
    'ARASYA_SOURCE_UNAVAILABLE_SECONDS' => '3600',
    // Trendyol Seller API (read-only order packages, Order V2). All three or none. Credentials alone never import:
    // bin/trendyol-preview.php only reads, and the intake inbox also needs ARASYA_TRENDYOL_INTAKE = 'enabled'
    // plus an explicit `bin/trendyol-intake.php activate` (see docs/trendyol-intake.md).
    // 'ARASYA_TRENDYOL_SELLER_ID' => '<seller id>',
    // 'ARASYA_TRENDYOL_API_KEY' => '<api key>',
    // 'ARASYA_TRENDYOL_API_SECRET' => '<api secret>',
    // 'ARASYA_TRENDYOL_INTAKE' => 'disabled',
    'ARASYA_TRUST_PROXY' => 'false',
    'ARASYA_TRUSTED_PROXIES' => '',
];
