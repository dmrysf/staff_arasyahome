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
     * @param array<string, DocumentAuthorityMode> $documentModes
     * @param array<string, PaymentGateMode> $paymentGateModes
     */
    public function __construct(private array $modes = [], private array $qrModes = [], private array $documentModes = [], private array $paymentGateModes = [])
    {
    }

    public static function fromRegistry(SourceRegistry $registry): self
    {
        $modes = [];
        $qrModes = [];
        $documentModes = [];
        $paymentGateModes = [];
        foreach ($registry->all() as $source) {
            $modes[$source->key] = $source->authorityMode;
            $qrModes[$source->key] = $source->qrAuthorityMode;
            $documentModes[$source->key] = $source->documentAuthorityMode;
            $paymentGateModes[$source->key] = $source->paymentGateMode;
        }
        return new self($modes, $qrModes, $documentModes, $paymentGateModes);
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

    /** The production document authority mode of a signed source; LEGACY for every other source. */
    public function documentModeFor(string $sourceKey): DocumentAuthorityMode
    {
        return $this->documentModes[$sourceKey] ?? DocumentAuthorityMode::Legacy;
    }

    /** The advance payment gate mode of a signed source; OFF for every other source (B2B, Trendyol). */
    public function paymentGateModeFor(string $sourceKey): PaymentGateMode
    {
        return $this->paymentGateModes[$sourceKey] ?? PaymentGateMode::Off;
    }

    /** Whether the source is a signed commerce source whose authority can be managed at all. */
    public function isManagedSource(string $sourceKey): bool
    {
        return array_key_exists($sourceKey, $this->modes);
    }
}
