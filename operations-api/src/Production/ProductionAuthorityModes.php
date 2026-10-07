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
    /** @param array<string, ProductionAuthorityMode> $modes */
    public function __construct(private array $modes = [])
    {
    }

    public static function fromRegistry(SourceRegistry $registry): self
    {
        $modes = [];
        foreach ($registry->all() as $source) {
            $modes[$source->key] = $source->authorityMode;
        }
        return new self($modes);
    }

    public function modeFor(string $sourceKey): ProductionAuthorityMode
    {
        return $this->modes[$sourceKey] ?? ProductionAuthorityMode::Legacy;
    }

    /** Whether the source is a signed commerce source whose authority can be managed at all. */
    public function isManagedSource(string $sourceKey): bool
    {
        return array_key_exists($sourceKey, $this->modes);
    }
}
