<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Order\OrderProjectionWriter;
use Arasya\Operations\Security\ApiRateLimiter;
use Arasya\Operations\Support\Clock;

/**
 * Signed server-to-server ingestion for first-party commerce sources.
 * It never uses browser cookies, never exposes source credentials and only
 * writes through the deterministic OrderProjectionWriter.
 */
final readonly class SourceIngestionController
{
    public const MAX_BODY_BYTES = 262_144;

    public function __construct(
        private SourceSignatureVerifier $verifier,
        private OrderProjectionWriter $writer,
        private ApiRateLimiter $rateLimiter,
        private Clock $clock,
    ) {
    }

    public function ingestOrder(Request $request, string $sourceKey): Response
    {
        $this->authorize($request, $sourceKey);
        $snapshot = SourceOrderPayloadMapper::map($sourceKey, $request->json(self::MAX_BODY_BYTES));
        $outcome = $this->writer->apply($snapshot);
        $globalId = $snapshot->globalId()->toString();
        return Response::json([
            'outcome' => $outcome,
            'globalOrderId' => $globalId,
            'qr' => $this->writer->qrPayloadFor($globalId),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function heartbeat(Request $request, string $sourceKey): Response
    {
        $this->authorize($request, $sourceKey);
        $input = $request->json(1024);
        if (array_keys($input) !== ['sentAt'] || !is_string($input['sentAt'])) {
            throw new ApiException(422, 'SOURCE_PAYLOAD_INVALID', 'Heartbeat payload is invalid.');
        }
        $this->writer->recordHeartbeat($sourceKey);
        return Response::json(['ok' => true], 200, ['Cache-Control' => 'no-store']);
    }

    private function authorize(Request $request, string $sourceKey): void
    {
        if (preg_match('/^[a-z0-9_-]{1,40}$/D', $sourceKey) !== 1) {
            throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
        }
        $this->verifier->verify($sourceKey, $request->header('x-arasya-timestamp'), $request->header('x-arasya-signature'), $request->body, $this->clock->now());
        if (!$this->rateLimiter->hit('source-ingestion', $sourceKey, 1200, 60)) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many source requests.');
        }
    }
}
