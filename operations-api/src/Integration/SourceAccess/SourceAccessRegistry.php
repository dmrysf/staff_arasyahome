<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\SourceAccess;

use Arasya\Operations\Config\Config;

/**
 * The configured source access targets (API 2.40.0). Per source (suffix as for every ARASYA_SOURCE_* key):
 *
 *   ARASYA_SOURCE_ACCESS_<SRC>       off (default) | read | edit
 *   ARASYA_SOURCE_ACCESS_URL_<SRC>   the shop's base URL, e.g. https://trendhome.ro (the allowlist: nothing else is ever
 *                                    called; no path, query, credentials or fragment)
 *   ARASYA_SOURCE_ACCESS_KEY_<SRC>   <kid>:<key>, the access key of the shop's keyring (`wp yd-arasya access key-add`),
 *                                    at least 32 printable bytes and never the source's event secret
 *
 * A source with any invalid value stays `off` and is reported as an issue (code only, never the value): configuration
 * mistakes fail closed and never stop the API. Production only accepts HTTPS on the standard port; a non-production
 * environment may also name a loopback HTTP test shop.
 */
final readonly class SourceAccessRegistry
{
    public const MODES = ['off', SourceAccessTarget::MODE_READ, SourceAccessTarget::MODE_EDIT];

    /**
     * @param array<string, SourceAccessTarget> $targets
     * @param list<array{sourceKey: string, code: string}> $issues
     */
    private function __construct(private array $targets, private array $issues)
    {
    }

    public static function fromConfig(Config $config): self
    {
        $targets = [];
        $issues = [];
        foreach ($config->sourceAccess as $sourceKey => $raw) {
            $sourceKey = (string) $sourceKey;
            $mode = strtolower(trim($raw['mode'] ?? ''));
            if ($mode === '' || $mode === 'off') {
                continue;
            }
            $code = self::problem($config, $sourceKey, $mode, $raw);
            if ($code !== null) {
                $issues[] = ['sourceKey' => $sourceKey, 'code' => $code];
                continue;
            }
            [$kid, $key] = explode(':', trim($raw['key'] ?? ''), 2);
            $targets[$sourceKey] = new SourceAccessTarget($sourceKey, $mode, rtrim(trim($raw['url'] ?? ''), '/'), $kid, $key);
        }
        return new self($targets, $issues);
    }

    public function find(string $sourceKey): ?SourceAccessTarget
    {
        return $this->targets[$sourceKey] ?? null;
    }

    /** @return list<array{sourceKey: string, code: string}> */
    public function issues(): array
    {
        return $this->issues;
    }

    /** @param array<string, string> $raw */
    private static function problem(Config $config, string $sourceKey, string $mode, array $raw): ?string
    {
        if (!in_array($mode, self::MODES, true)) {
            return 'invalid_mode';
        }
        if (!isset($config->sourceSecrets[$sourceKey])) {
            return 'source_not_configured';
        }
        $url = rtrim(trim($raw['url'] ?? ''), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['path']) && $parts['path'] !== '') || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/^[a-z0-9.-]{1,253}$/D', strtolower($parts['host'])) !== 1) {
            return 'invalid_url';
        }
        $loopback = in_array(strtolower($parts['host']), ['127.0.0.1', 'localhost'], true);
        if ($config->isProduction()) {
            if ($parts['scheme'] !== 'https' || isset($parts['port']) || $loopback || filter_var($parts['host'], FILTER_VALIDATE_IP) !== false) {
                return 'invalid_url';
            }
        } elseif (!($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && $loopback))) {
            return 'invalid_url';
        }
        $key = trim($raw['key'] ?? '');
        $pair = explode(':', $key, 2);
        if (count($pair) !== 2 || preg_match(SourceAccessProtocol::KID_PATTERN, $pair[0]) !== 1) {
            return 'invalid_key';
        }
        $secret = $pair[1];
        if (strlen($secret) < 32 || strlen($secret) > 256 || preg_match('/^[\x21-\x7E]+$/D', $secret) !== 1
            || str_starts_with($secret, 'replace-with') || str_starts_with($secret, 'CHANGE_ME') || str_starts_with($secret, '<')) {
            return 'invalid_key';
        }
        if (hash_equals($config->sourceSecrets[$sourceKey], $secret)) {
            // The access key must differ from the event secret: a leaked event secret never opens customer data.
            return 'key_reuses_event_secret';
        }
        return null;
    }
}
