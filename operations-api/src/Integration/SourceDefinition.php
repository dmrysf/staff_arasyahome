<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration;

/**
 * Safe, non-secret metadata of one signed source. Trust comes only from the source key plus its own
 * HMAC secret (held by SourceSignatureVerifier), never from a hostname.
 */
final readonly class SourceDefinition
{
    public function __construct(
        public string $key,
        public string $displayName,
        public string $integrationType,
        public bool $enabled,
        public SourceMode $mode,
    ) {
    }

    public function canIngest(): bool
    {
        return $this->enabled && $this->mode === SourceMode::Active;
    }

    /** @return array{sourceKey: string, displayName: string, integrationType: string, enabled: bool, mode: string} */
    public function toArray(): array
    {
        return [
            'sourceKey' => $this->key,
            'displayName' => $this->displayName,
            'integrationType' => $this->integrationType,
            'enabled' => $this->enabled,
            'mode' => $this->mode->value,
        ];
    }
}
