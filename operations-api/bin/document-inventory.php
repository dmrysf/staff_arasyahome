<?php

declare(strict_types=1);

// Read-only production document inventory of one source's orders (bounded). It never creates, prints,
// supersedes or revokes anything: revision 1 of an eligible order is created when the order's ticket is
// first printed (Staff or the signed source), never in bulk. Prints one JSON line per operations order
// and a summary; source-authority orders are only counted. No customer data, no QR payload.
//
//   valid             active revision whose QR is the order's active QR
//   attention         stale or revoked document: needs a revision request and approval
//   pending_approval  an open revision request (pending or approved, not yet generated)
//   missing_eligible  no document yet, order open, Arasya document authority: created on first print
//   skipped_closed    no document, order completed or unavailable (cancelled): nothing to print
//   skipped_source    no document, the source still issues this order's ticket (document mode legacy)
//   anomaly           an active revision whose QR is not active, or more than one active revision

use Arasya\Operations\Document\DocumentService;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['source:', 'limit:']);
$source = $options['source'] ?? null;
if (!is_string($source) || preg_match('/^[a-z0-9_-]{1,40}$/D', $source) !== 1) {
    fwrite(STDERR, "Usage: php bin/document-inventory.php --source=<source> [--limit=200]\n");
    exit(2);
}
$limit = max(1, min(500, (int) ($options['limit'] ?? 200)));
$pdo = $container->pdo();
$modes = $container->authorityModes();
$statement = $pdo->prepare(
    "SELECT o.order_uuid, o.global_order_id, o.production_authority, o.production_completed_at, o.operational_status, o.document_status,
            (SELECT COUNT(*) FROM production_document_revisions r WHERE r.active_order_uuid = o.order_uuid) AS active_revisions,
            (SELECT r.revision_number FROM production_document_revisions r WHERE r.active_order_uuid = o.order_uuid) AS revision,
            (SELECT q.status FROM production_document_revisions r JOIN order_qr_references q ON q.qr_reference = r.qr_reference WHERE r.active_order_uuid = o.order_uuid) AS revision_qr_status,
            (SELECT x.status FROM production_document_revision_requests x WHERE x.open_order_uuid = o.order_uuid) AS open_request
     FROM operational_orders o WHERE o.source_key = ? ORDER BY o.created_at, o.order_uuid LIMIT {$limit}",
);
$statement->execute([$source]);
$summary = [];
$anomalies = 0;
foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $authority = DocumentService::documentAuthorityOf($modes, $source, (string) $row['production_authority']);
    $closed = $row['production_completed_at'] !== null || $row['operational_status'] === 'unavailable';
    $result = match (true) {
        (int) $row['active_revisions'] > 1 || ($row['document_status'] === 'active' && $row['revision_qr_status'] !== 'active') => 'anomaly',
        $row['open_request'] !== null => 'pending_approval',
        $row['document_status'] === 'active' => 'valid',
        in_array($row['document_status'], ['stale', 'revoked'], true) => 'attention',
        $authority !== 'arasya' => 'skipped_source',
        $closed => 'skipped_closed',
        default => 'missing_eligible',
    };
    $summary[$result] = ($summary[$result] ?? 0) + 1;
    $anomalies += $result === 'anomaly' ? 1 : 0;
    if ($row['production_authority'] === 'operations' || $result !== 'skipped_source') {
        fwrite(STDOUT, json_encode(['order' => $row['global_order_id'], 'productionAuthority' => $row['production_authority'], 'documentAuthority' => $authority,
            'documentStatus' => $row['document_status'], 'revision' => $row['revision'] === null ? null : (int) $row['revision'], 'operationalStatus' => $row['operational_status'],
            'completed' => $row['production_completed_at'] !== null, 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
fwrite(STDOUT, json_encode(['source' => $source, 'documentAuthorityMode' => $modes->documentModeFor($source)->value, 'summary' => $summary], JSON_THROW_ON_ERROR) . "\n");
exit($anomalies > 0 ? 3 : 0);
