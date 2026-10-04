<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration;

use Arasya\Operations\Http\ApiException;
use DateTimeImmutable;

/**
 * Verifies server-to-server source requests.
 *
 * Signature: `X-Arasya-Signature: v1=<hex HMAC-SHA256(secret, "<timestamp>.<raw body>")>`
 * with `X-Arasya-Timestamp: <unix seconds>`. Requests outside a five-minute
 * window are rejected; replays inside the window are neutralized by
 * idempotent per-event receipts.
 */
final readonly class SourceSignatureVerifier
{
    public const MAX_SKEW_SECONDS = 300;

    /** @param array<string, string> $secrets */
    public function __construct(private array $secrets)
    {
    }

    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public function verify(string $sourceKey, ?string $timestamp, ?string $signature, string $body, DateTimeImmutable $now): void
    {
        $secret = $this->secrets[$sourceKey] ?? null;
        if ($secret === null) {
            throw new ApiException(503, 'SOURCE_NOT_CONFIGURED', 'This source is not configured for signed delivery.');
        }
        if ($timestamp === null || preg_match('/^[0-9]{9,12}$/D', $timestamp) !== 1 || abs($now->getTimestamp() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            throw new ApiException(401, 'SOURCE_SIGNATURE_INVALID', 'Source request signature is invalid or expired.');
        }
        if ($signature === null || preg_match('/^v1=[0-9a-f]{64}$/D', $signature) !== 1 || !hash_equals(self::sign($secret, $timestamp, $body), $signature)) {
            throw new ApiException(401, 'SOURCE_SIGNATURE_INVALID', 'Source request signature is invalid or expired.');
        }
    }
}
