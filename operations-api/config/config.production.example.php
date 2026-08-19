<?php

declare(strict_types=1);

// Copy this file to $HOME/arasya-config/operations-api.php and replace every
// placeholder there. The real file must remain outside the API release and Git.
return [
    'ARASYA_APP_ENV' => 'production',
    'ARASYA_APP_SECRET' => 'replace-with-at-least-32-random-bytes',
    'ARASYA_DB_HOST' => 'localhost',
    'ARASYA_DB_PORT' => '3306',
    'ARASYA_DB_NAME' => 'cpanel_database_name',
    'ARASYA_DB_USER' => 'cpanel_runtime_user',
    'ARASYA_DB_PASSWORD' => 'replace-with-database-password',
    'ARASYA_ALLOWED_ORIGINS' => 'https://staff.arasyahome.ro',
    'ARASYA_SESSION_TTL' => '36000',
    'ARASYA_SESSION_TOUCH_INTERVAL' => '300',
    'ARASYA_LOGIN_USERNAME_LIMIT' => '5',
    'ARASYA_LOGIN_IP_LIMIT' => '30',
    'ARASYA_LOGIN_WINDOW' => '900',
    'ARASYA_TRUST_PROXY' => 'false',
    'ARASYA_TRUSTED_PROXIES' => '',
];
