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
    public const MAX_AUTHORITY_ORDERS = 50;
    public const TRACKING_REQUESTS_PER_MINUTE = 300;

    public function __construct(
        private SourceSignatureVerifier $verifier,
        private OrderProjectionWriter $writer,
        private ApiRateLimiter $rateLimiter,
        private Clock $clock,
        private SourceRegistry $registry,
        private ?SourceAuthorityQueries $authority = null,
        private ?\Arasya\Operations\Production\ProductionQrService $qr = null,
        private ?\Arasya\Operations\Document\DocumentService $documents = null,
        private ?\Arasya\Operations\Document\DocumentRenderer $renderer = null,
        private ?SourceTrackingQueries $tracking = null,
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
            'productionAuthorityMode' => $source->authorityMode->value,
            'qrAuthorityMode' => $source->qrAuthorityMode->value,
            'documentAuthorityMode' => $source->documentAuthorityMode->value,
            'trackingAuthorityMode' => $source->trackingAuthorityMode->value,
            'contract' => [
                'schemaVersion' => 1,
                'workflowId' => $workflow['id'],
                'workflowVersion' => $workflow['version'],
                'stageCount' => $workflow['stageCount'],
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Production authority of up to 50 of the source's own orders: {"orderIds": ["63366", ...]}.
     * Read-only and allowed in validation and active mode; nothing is written, not even the contact
     * time. Unknown orders are reported with exists=false, never as an error.
     */
    public function orderAuthority(Request $request, string $sourceKey): Response
    {
        $source = $this->authorize($request, $sourceKey);
        $queries = $this->authority ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Production authority queries are not ready.');
        $ids = self::orderIds($request);
        return Response::json([
            'ok' => true,
            'sourceKey' => $source->key,
            'productionAuthorityMode' => $source->authorityMode->value,
            'orders' => $queries->forOrders($source->key, $ids),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Production QR state of up to 50 of the source's own orders: {"orderIds": ["63366", ...]}. Read-only
     * (nothing is issued or written, not even the contact time) and allowed in validation and active mode.
     * The active QR payload is returned only for orders whose QR authority is Arasya (an operations order
     * of a source in QR observe or enforce); unknown orders are reported with exists=false.
     */
    public function orderQr(Request $request, string $sourceKey): Response
    {
        $source = $this->authorize($request, $sourceKey);
        $qr = $this->qr ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Production QR authority is not ready.');
        $ids = self::orderIds($request);
        return Response::json([
            'ok' => true,
            'sourceKey' => $source->key,
            'productionAuthorityMode' => $source->authorityMode->value,
            'qrAuthorityMode' => $source->qrAuthorityMode->value,
            'orders' => $qr->sourceStates($source->key, $ids),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Production document state of up to 50 of the source's own orders: {"orderIds": ["63366", ...]}.
     * Read-only (nothing is generated, printed or written) and allowed in validation and active mode. It
     * carries document facts only: no customer data, no QR payload (a short hint), no PDF.
     */
    public function orderDocuments(Request $request, string $sourceKey): Response
    {
        $source = $this->authorize($request, $sourceKey);
        $documents = $this->documents ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Production documents are not ready.');
        $ids = self::orderIds($request);
        return Response::json([
            'ok' => true,
            'sourceKey' => $source->key,
            'documentAuthorityMode' => $source->documentAuthorityMode->value,
            'orders' => $documents->sourceStates($source, $ids),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * The Arasya production ticket of one of the source's Arasya-managed orders, for printing by the source
     * operator: {"orderId": "63366", "issue": true, "revisionNumber": null, "actor": {"id": 7, "name": "…"}}.
     * Only in document mode ENFORCE. It may create revision 1 (never a later revision), records the print and
     * returns the immutable revision rendered on demand, base64 encoded with its SHA-256.
     */
    public function orderDocument(Request $request, string $sourceKey): Response
    {
        $source = $this->authorize($request, $sourceKey);
        $documents = $this->documents ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Production documents are not ready.');
        $renderer = $this->renderer ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Production documents are not ready.');
        $result = $documents->sourcePrint($source, $request->json(4096), $request->requestId);
        [$pdf, $filename] = $renderer->render($result['revisionUuid']);
        return Response::json([
            'ok' => true,
            'sourceKey' => $source->key,
            'documentAuthorityMode' => $source->documentAuthorityMode->value,
            'document' => ['orderId' => $result['orderId'], 'globalOrderId' => $source->key . ':' . $result['orderId']] + $result['state'] + [
                'issued' => $result['issued'],
                'printNumber' => $result['printNumber'],
                'filename' => $filename,
                'sha256' => hash('sha256', $pdf),
                'pdf' => base64_encode($pdf),
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Customer tracking facts of up to 50 of the source's own orders: {"orderIds": ["63366", ...]}. Read-only (nothing
     * is claimed, moved or written, not even the contact time) and only while the source's tracking authority mode is
     * observe or enforce. The source calls it only after it verified the customer itself; the answer carries the
     * customer milestone of each operations order and no customer, employee, document or QR data.
     */
    public function orderTracking(Request $request, string $sourceKey): Response
    {
        // Customer-driven reads have their own bucket: tracking traffic can never starve order ingestion.
        $source = $this->authorize($request, $sourceKey, 'source-tracking', self::TRACKING_REQUESTS_PER_MINUTE);
        $tracking = $this->tracking ?? throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Customer tracking is not ready.');
        $ids = self::orderIds($request);
        if (!$source->trackingAuthorityMode->isActive()) {
            throw new ApiException(409, 'TRACKING_CUTOVER_INACTIVE', 'Customer tracking authority is not active for this source.');
        }
        return Response::json([
            'ok' => true,
            'sourceKey' => $source->key,
            'productionAuthorityMode' => $source->authorityMode->value,
            'trackingAuthorityMode' => $source->trackingAuthorityMode->value,
            'orders' => $tracking->forOrders($source, $ids),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /** @return list<string> */
    private static function orderIds(Request $request): array
    {
        $input = $request->json(8192);
        $ids = $input['orderIds'] ?? null;
        if (array_keys($input) !== ['orderIds'] || !is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > self::MAX_AUTHORITY_ORDERS) {
            throw new ApiException(422, 'SOURCE_PAYLOAD_INVALID', 'orderIds must list between 1 and 50 order ids.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $id) !== 1) {
                throw new ApiException(422, 'SOURCE_PAYLOAD_INVALID', 'orderIds must list between 1 and 50 order ids.');
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new ApiException(422, 'SOURCE_PAYLOAD_INVALID', 'orderIds must be unique.');
        }
        return $ids;
    }

    private function authorize(Request $request, string $sourceKey, string $bucket = 'source-ingestion', int $perMinute = 1200): SourceDefinition
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
        if (!$this->rateLimiter->hit($bucket, $sourceKey, $perMinute, 60)) {
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
