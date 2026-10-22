<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration;

use Arasya\Operations\Production\DocumentAuthorityMode;
use Arasya\Operations\Production\PaymentGateMode;
use Arasya\Operations\Production\ProductionAuthorityMode;
use Arasya\Operations\Production\QrAuthorityMode;
use Arasya\Operations\Production\TrackingAuthorityMode;

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
        public ProductionAuthorityMode $authorityMode = ProductionAuthorityMode::Legacy,
        public QrAuthorityMode $qrAuthorityMode = QrAuthorityMode::Legacy,
        public DocumentAuthorityMode $documentAuthorityMode = DocumentAuthorityMode::Legacy,
        public TrackingAuthorityMode $trackingAuthorityMode = TrackingAuthorityMode::Legacy,
        public PaymentGateMode $paymentGateMode = PaymentGateMode::Off,
        /**
         * ARASYA_SOURCE_CONFIRMARE_<SOURCE> (API 2.37.0): `on` only permits the operator to record the source's Confirmare
         * activation (bin/workflow-cohort.php). It never classifies an order by itself, and switching it off never
         * deactivates a recorded activation (readiness reports the mismatch). Default and fallback: off.
         */
        public bool $confirmarePermitted = false,
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
