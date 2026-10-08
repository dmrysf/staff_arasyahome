<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\QrReference;
use PDO;

/**
 * Renders one stored revision. Rendering is on demand from the immutable revision snapshot, so the
 * bytes of a revision are reproducible and no binary copy (with customer data) is stored or exposed
 * through a filesystem path.
 */
final readonly class DocumentRenderer
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param bool $preview a screen preview: never a scannable QR, marked as not for production, no print recorded
     * @return array{0: string, 1: string} PDF bytes and the download filename
     */
    public function render(string $revisionUuid, bool $preview = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.revision_number, r.status, r.qr_reference, r.snapshot_json, r.generated_at, s.display_name
             FROM production_document_revisions r INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid
             INNER JOIN order_sources s ON s.source_key = o.source_key WHERE r.revision_uuid = ?',
        );
        $statement->execute([$revisionUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiException(404, 'DOCUMENT_NOT_FOUND', 'Documentul nu a fost găsit.');
        }
        $snapshot = TicketSnapshot::fromJson((string) $row['snapshot_json']);
        $revision = [
            'number' => (int) $row['revision_number'],
            'status' => (string) $row['status'],
            'generatedAt' => (string) $row['generated_at'],
            // Only an active revision carries its QR; an invalid document never prints a scannable code.
            'qrPayload' => $row['status'] === 'active' && !$preview ? QrReference::fromStored((string) $row['qr_reference'])->payload() : null,
            'sourceDisplayName' => (string) $row['display_name'],
            'preview' => $preview,
        ];
        $filename = ProductionTicketPdf::filename($snapshot, $revision['number']);
        return [ProductionTicketPdf::render($snapshot, $revision), $preview ? str_replace('.pdf', '-PREVIZUALIZARE.pdf', $filename) : $filename];
    }

    /** Inline preview of a revision: nothing is recorded, the document has no QR. @param array{0: string, 1: string} $document */
    public static function preview(array $document): \Arasya\Operations\Http\Response
    {
        return \Arasya\Operations\Http\Response::file($document[0], [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $document[1] . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array{0: string, 1: string} $document */
    public static function response(array $document, int $printNumber): \Arasya\Operations\Http\Response
    {
        return \Arasya\Operations\Http\Response::file($document[0], [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $document[1] . '"',
            'Cache-Control' => 'private, no-store',
            'X-Document-Print' => (string) $printNumber,
        ]);
    }
}
