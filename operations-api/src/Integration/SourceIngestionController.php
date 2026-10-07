<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Order\OrderProjectionWriter;
use Arasya\Operations\Production\CanonicalProductionWorkflowContract;
use Arasya\Operations\Security\ApiRateLimiter;
use Arasya\Operations\Support\Clock;

/**
 * Signed server-to-server ingestion for first-party commerce sources.
 * It never uses browser cookies, never exposes source credentials and only
 * writes through the deterministic OrderProjectionWriter, and only for
 * sources the registry marks as enabled and active.
 */
final readonly class SourceIngestionController
{
    public const MAX_BODY_BYTES = 262_144;

    public function __construct(
        private SourceSignatureVerifier $verifier,
        private OrderProjectionWriter $writer,
        private ApiRateLimiter $rateLimiter,
        private Clock $clock,
        private SourceRegistry $registry,
    ) {
    }

    public function ingestOrder(Request $request, string $sourceKey): Response
    {
        $source = $this->authorize($request, $sourceKey);
        if (!$source->canIngest()) {
            throw new ApiException(403, 'SOURCE_NOT_ACTIVE', 'This source is not active for order ingestion.');
        }
        $snapshot = SourceOrderPayloadMapper::map($sourceKey, $request->json(self::MAX_BODY_BYTES));
        $outcome = $this->writer->apply($snapshot);
        $globalId = $snapshot->globalId()->toString();
        return Response::json([
            'outcome' => $outcome,
            'globalOrderId' => $globalId,
            'qr' => $this->writer->qrPayloadFor($globalId),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Parses a schemaVersion 1 order exactly like ingestion and answers with a contract summary only.
     * Nothing is persisted: no order, receipt, QR, document or analytics row is touched, so the same
     * eventId is still accepted later by ingestOrder(). No payload value is echoed back.
     */
    public function validateOrder(Request $request, string $sourceKey): Response
    {
        $source = $this->authorize($request, $sourceKey);
        $snapshot = SourceOrderPayloadMapper::map($sourceKey, $request->json(self::MAX_BODY_BYTES));
        // The writer checks the stage against the database; validation uses the canonical contract instead.
        if ($snapshot->productionStageId !== null && !array_key_exists($snapshot->productionStageId, CanonicalProductionWorkflowContract::STAGES)) {
            throw new ApiException(422, 'SOURCE_STAGE_UNKNOWN', 'The production stage does not belong to the active canonical workflow or is inactive.');
        }
        return Response::json([
            'ok' => true,
            'schemaVersion' => 1,
            'sourceKey' => $source->key,
            'mode' => $source->mode->value,
            'workflow' => self::workflowSummary(),
            'itemCount' => count($snapshot->items),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function heartbeat(Request $request, string $sourceKey): Response
    {
        $source = $this->authorize($request, $sourceKey);
        $input = $request->json(1024);
        if (array_keys($input) !== ['sentAt'] || !is_string($input['sentAt'])) {
            throw new ApiException(422, 'SOURCE_PAYLOAD_INVALID', 'Heartbeat payload is invalid.');
        }
        $this->writer->recordHeartbeat($sourceKey);
        $workflow = self::workflowSummary();
        return Response::json([
            'ok' => true,
            'sourceKey' => $source->key,
            'mode' => $source->mode->value,
            'contract' => [
                'schemaVersion' => 1,
                'workflowId' => $workflow['id'],
                'workflowVersion' => $workflow['version'],
                'stageCount' => $workflow['stageCount'],
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    private function authorize(Request $request, string $sourceKey): SourceDefinition
    {
        if (preg_match(SourceRegistry::KEY_PATTERN, $sourceKey) !== 1) {
            throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
        }
        // Unknown, misconfigured and disabled sources fail closed alike, before any body is parsed.
        $source = $this->registry->find($sourceKey);
        if ($source === null || !$source->enabled) {
            throw new ApiException(503, 'SOURCE_NOT_CONFIGURED', 'This source is not configured for signed delivery.');
        }
        $this->verifier->verify($sourceKey, $request->header('x-arasya-timestamp'), $request->header('x-arasya-signature'), $request->body, $this->clock->now());
        if (!$this->rateLimiter->hit('source-ingestion', $sourceKey, 1200, 60)) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many source requests.');
        }
        return $source;
    }

    /** @return array{id: string, version: int, stageCount: int} */
    private static function workflowSummary(): array
    {
        return [
            'id' => CanonicalProductionWorkflowContract::WORKFLOW_ID,
            'version' => CanonicalProductionWorkflowContract::VERSION,
            'stageCount' => count(CanonicalProductionWorkflowContract::STAGES),
        ];
    }
}
