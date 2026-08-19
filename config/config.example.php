<?php

declare(strict_types=1);

// Copy values into cPanel environment configuration. Do not place production
// credentials in this file or anywhere under public/.
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
    'ARASYA_TRUST_PROXY' => 'false',
    'ARASYA_TRUSTED_PROXIES' => '',
];

