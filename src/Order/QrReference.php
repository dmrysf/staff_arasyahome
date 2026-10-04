<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use InvalidArgumentException;

/**
 * Opaque, server-owned order QR reference.
 *
 * The printed payload is `ARASYA:Q1:<26 base32 characters>` (128 random bits).
 * It contains no order data and no secret: a reference only resolves for an
 * authenticated employee who is operationally allowed to see the order.
 */
final readonly class QrReference
{
    public const PREFIX = 'ARASYA:Q1:';
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private function __construct(public string $value)
    {
    }

    public static function generate(): self
    {
        $bytes = random_bytes(16);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $bits = str_pad($bits, 130, '0');
        $value = '';
        foreach (str_split($bits, 5) as $chunk) {
            $value .= self::ALPHABET[bindec($chunk)];
        }
        return new self($value);
    }

    public static function fromStored(string $value): self
    {
        if (preg_match('/^[A-Z2-7]{26}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid stored QR reference.');
        }
        return new self($value);
    }

    /** Returns null for any payload that is not an exact Arasya Q1 reference. */
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
