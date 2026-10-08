<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Integration\SourceRegistry;

/**
 * Read-only map of the production authority mode of each signed source. Sources that are not in the
 * signed registry (internal B2B, pull-based Trendyol, unknown keys) are always LEGACY.
 */
final readonly class ProductionAuthorityModes
{
    /**
     * @param array<string, ProductionAuthorityMode> $modes
     * @param array<string, QrAuthorityMode> $qrModes
     */
    public function __construct(private array $modes = [], private array $qrModes = [])
    {
    }

    public static function fromRegistry(SourceRegistry $registry): self
    {
        $modes = [];
        $qrModes = [];
        foreach ($registry->all() as $source) {
            $modes[$source->key] = $source->authorityMode;
            $qrModes[$source->key] = $source->qrAuthorityMode;
        }
        return new self($modes, $qrModes);
    }

    public function modeFor(string $sourceKey): ProductionAuthorityMode
    {
        return $this->modes[$sourceKey] ?? ProductionAuthorityMode::Legacy;
    }

    /** The production QR authority mode of a signed source; LEGACY for every other source. */
    public function qrModeFor(string $sourceKey): QrAuthorityMode
    {
        return $this->qrModes[$sourceKey] ?? QrAuthorityMode::Legacy;
    }

    /** Whether the source is a signed commerce source whose authority can be managed at all. */
    public function isManagedSource(string $sourceKey): bool
    {
        return array_key_exists($sourceKey, $this->modes);
    }
}
