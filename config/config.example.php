<?php

declare(strict_types=1);

// Environment-variable reference only. cPanel primarily loads the existing
// $HOME/arasya-config/secrets.json; config.production.example.php remains a
// supported legacy PHP alternative.
// Never place production credentials in this directory or anywhere under public/.
return [
    'ARASYA_APP_ENV' => 'production',
    'ARASYA_APP_SECRET' => 'replace-with-at-least-32-random-bytes',
    'ARASYA_DB_HOST' => '127.0.0.1',
    'ARASYA_DB_PORT' => '3306',
    'ARASYA_DB_NAME' => 'arasya_operations',
    'ARASYA_DB_USER' => 'arasya_runtime',
    'ARASYA_DB_PASSWORD' => 'configure-outside-git',
    'ARASYA_ALLOWED_ORIGINS' => 'https://staff.arasyahome.ro',
    'ARASYA_SESSION_TTL' => '36000',
    'ARASYA_SESSION_TOUCH_INTERVAL' => '300',
    'ARASYA_LOGIN_USERNAME_LIMIT' => '5',
    'ARASYA_LOGIN_IP_LIMIT' => '30',
    'ARASYA_LOGIN_WINDOW' => '900',
    'ARASYA_SESSION_RECORD_RETENTION_DAYS' => '30',
    'ARASYA_LOGIN_ATTEMPT_RETENTION_DAYS' => '30',
    'ARASYA_RATE_LIMIT_RETENTION_DAYS' => '7',
    // Audit-event deletion is disabled unless this is explicitly configured.
    // 'ARASYA_AUTH_AUDIT_RETENTION_DAYS' => '365',
    'ARASYA_TRUST_PROXY' => 'false',
    'ARASYA_TRUSTED_PROXIES' => '',
];
