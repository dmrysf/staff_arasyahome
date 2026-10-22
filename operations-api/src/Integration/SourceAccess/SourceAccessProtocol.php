<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

/**
 * Source access protocol v1 (API 2.40.0, YD SOFT 2.14.0 `SourceAccess\Protocol`): the signed request and response
 * strings, identical on both sides and pinned by shared test vectors (tests/source-access-protocol.php here,
 * tests/source-access/run.php in YD SOFT).
 *
 *   request  = "arasya-access-v1\nPOST\n<route>\n<source key>\n<kid>\n<timestamp>\n<nonce>\n<sha256 hex of body>"
 *   response = "arasya-access-v1-response\n<source key>\n<kid>\n<nonce>\n<HTTP status>\n<sha256 hex of body>"
 *   signature header value = "v1=" . hex HMAC-SHA256(access key, string)
 *
 * <route> is the WordPress REST route without the `/wp-json` prefix (SourceAccessClient::ROUTES).
 */
final class SourceAccessProtocol
{
    public const HEADER_KEY = 'X-Arasya-Access-Key';
    public const HEADER_TIMESTAMP = 'X-Arasya-Access-Timestamp';
    public const HEADER_NONCE = 'X-Arasya-Access-Nonce';
    public const HEADER_SIGNATURE = 'X-Arasya-Access-Signature';
    public const KID_PATTERN = '/^[a-z0-9][a-z0-9-]{2,31}$/D';

    private function __construct()
    {
    }

    public static function requestString(string $route, string $sourceKey, string $kid, int $timestamp, string $nonce, string $body): string
    {
        return "arasya-access-v1\nPOST\n{$route}\n{$sourceKey}\n{$kid}\n{$timestamp}\n{$nonce}\n" . hash('sha256', $body);
    }

    public static function responseString(string $sourceKey, string $kid, string $nonce, int $status, string $body): string
    {
        return "arasya-access-v1-response\n{$sourceKey}\n{$kid}\n{$nonce}\n{$status}\n" . hash('sha256', $body);
    }

    public static function sign(#[\SensitiveParameter] string $key, string $string): string
    {
        return 'v1=' . hash_hmac('sha256', $string, $key);
    }

    public static function matches(#[\SensitiveParameter] string $key, string $string, string $header): bool
    {
        return strlen($header) === 67 && hash_equals(self::sign($key, $string), $header);
    }
}
