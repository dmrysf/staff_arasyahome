<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration;

use Arasya\Operations\Config\Config;

/**
 * Config-backed registry of signed server-to-server sources (one entry per website).
 *
 * A source is usable only when its key is valid, unique, not reserved, has its own signing secret and
 * has valid settings. Anything else is left out (the source fails closed) and reported as an issue
 * for readiness diagnostics. The registry never holds or returns secret material.
 */
final readonly class SourceRegistry
{
    public const KEY_PATTERN = '/^[a-z0-9_-]{1,40}$/D';
    public const DEFAULT_INTEGRATION_TYPE = 'yd-soft-woocommerce';
    /** Internal or pull-based sources that can never be pushed through a signed source route. */
    public const RESERVED_KEYS = ['b2b', 'trendyol'];
    private const INTEGRATION_TYPES = [self::DEFAULT_INTEGRATION_TYPE];
    private const DEFAULT_DISPLAY_NAMES = ['trendhome' => 'Trendhome', 'outletperdele' => 'OutletPerdele'];

    /**
     * @param array<string, SourceDefinition> $sources
     * @param list<array{code: string, sourceKey: string|null}> $issues
     */
    private function __construct(private array $sources, private array $issues)
    {
    }

    public static function fromConfig(Config $config): self
    {
        $declared = $config->sourceKeys !== []
            ? $config->sourceKeys
            : array_values(array_unique([...array_map('strval', array_keys($config->sourceSecrets)), ...array_map('strval', array_keys($config->sourceSettings))]));

        $issues = [];
        $counts = [];
        $suffixes = [];
        foreach ($declared as $key) {
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            if (preg_match(self::KEY_PATTERN, $key) === 1) {
                $suffixes[Config::sourceEnvironmentSuffix($key)][$key] = true;
            }
        }

        $sources = [];
        foreach (array_keys($counts) as $key) {
            $key = (string) $key;
            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                // The raw value is never echoed: it may be a mistyped secret.
                $issues[] = ['code' => 'invalid_key', 'sourceKey' => null];
                continue;
            }
            if ($counts[$key] > 1) {
                $issues[] = ['code' => 'duplicate_key', 'sourceKey' => $key];
                continue;
            }
            if (count($suffixes[Config::sourceEnvironmentSuffix($key)]) > 1) {
                // `a-b` and `a_b` would read the same ARASYA_SOURCE_*_A_B values, including one shared secret.
                $issues[] = ['code' => 'environment_collision', 'sourceKey' => $key];
                continue;
            }
            if (in_array($key, self::RESERVED_KEYS, true)) {
                $issues[] = ['code' => 'reserved_key', 'sourceKey' => $key];
                continue;
            }
            $settings = $config->sourceSettings[$key] ?? [];
            $mode = SourceMode::tryFrom(strtolower($settings['mode'] ?? SourceMode::Validation->value));
            $enabled = filter_var($settings['enabled'] ?? 'true', FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $type = $settings['integrationType'] ?? self::DEFAULT_INTEGRATION_TYPE;
            $displayName = trim($settings['displayName'] ?? (self::DEFAULT_DISPLAY_NAMES[$key] ?? $key));
            $valid = true;
            foreach ([
                'missing_secret' => !isset($config->sourceSecrets[$key]),
                'invalid_mode' => $mode === null,
                'invalid_enabled' => $enabled === null,
                'invalid_type' => !in_array($type, self::INTEGRATION_TYPES, true),
                'invalid_display_name' => $displayName === '' || mb_strlen($displayName) > 100 || preg_match('/[\x00-\x1F\x7F]/', $displayName) === 1,
            ] as $code => $failed) {
                if ($failed) {
                    $issues[] = ['code' => $code, 'sourceKey' => $key];
                    $valid = false;
                }
            }
            if ($valid && $mode !== null && $enabled !== null) {
                $sources[$key] = new SourceDefinition($key, $displayName, $type, $enabled, $mode);
            }
        }
        return new self($sources, $issues);
    }

    /** A usable source (enabled or disabled), or null when unknown or misconfigured. */
    public function find(string $sourceKey): ?SourceDefinition
    {
        return $this->sources[$sourceKey] ?? null;
    }

    /** @return list<SourceDefinition> */
    public function all(): array
    {
        return array_values($this->sources);
    }

    /** @return list<array{code: string, sourceKey: string|null}> */
    public function issues(): array
    {
        return $this->issues;
    }
}
