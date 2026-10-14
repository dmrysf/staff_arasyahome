<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

/**
 * Opaque, server-owned label of one physical piece (production work unit).
 *
 * The printed payload is `ARASYA:U1:<26 base32 characters>` (128 random bits), distinct from the order QR
 * (`ARASYA:Q1:`), so a scanner can never confuse the two. Like the order QR it carries no order data and no secret:
 * it resolves only for an authenticated employee who may see the order, and only while the label is active.
 */
final readonly class UnitLabelReference
{
    public const PREFIX = 'ARASYA:U1:';

    private function __construct(public string $value)
    {
    }

    public static function generate(): self
    {
        // Same 128-bit base32 alphabet as the order QR; only the prefix differs.
        return new self(QrReference::generate()->value);
    }

    public static function fromStored(string $value): self
    {
        if (preg_match('/^[A-Z2-7]{26}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid stored unit label reference.');
        }
        return new self($value);
    }

    /** Returns null for any payload that is not an exact Arasya U1 piece label. */
    public static function parsePayload(string $payload): ?self
    {
        $payload = trim($payload);
        if (strlen($payload) !== strlen(self::PREFIX) + 26) {
            return null;
        }
        $upper = strtoupper($payload);
        if (!str_starts_with($upper, self::PREFIX)) {
            return null;
        }
        $value = substr($upper, strlen(self::PREFIX));
        return preg_match('/^[A-Z2-7]{26}$/D', $value) === 1 ? new self($value) : null;
    }

    public function payload(): string
    {
        return self::PREFIX . $this->value;
    }
}
