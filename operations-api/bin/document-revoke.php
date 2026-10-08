<?php

declare(strict_types=1);

// Root emergency revoke of one order's active production document, from the server console. It runs the
// same DocumentService::revoke() as the root-only Dashboard action, as the system root identity: the active
// revision and every active QR of the order are revoked, production of the order is blocked and all history
// stays. The replacement follows the normal revision request and approval path.
// Dry run by default (prints the current document state); --apply needs --confirm=<the same order id>.
// Prints document facts and a QR hint only, never a QR payload or customer data.

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['order:', 'reason:', 'confirm:', 'apply']);
$order = $options['order'] ?? null;
$reason = trim((string) ($options['reason'] ?? ''));
if (!is_string($order) || preg_match('/^[a-z0-9_-]{1,40}:[A-Za-z0-9._-]{1,128}$/D', $order) !== 1 || $reason === '') {
    fwrite(STDERR, "Usage: php bin/document-revoke.php --order=<source:id> --reason=<text> [--apply --confirm=<source:id>]\n");
    exit(2);
}
$pdo = $container->pdo();
$state = $pdo->prepare('SELECT o.order_uuid, o.document_status, o.document_version, r.revision_number, r.qr_reference FROM operational_orders o LEFT JOIN production_document_revisions r ON r.revision_uuid = o.active_document_revision_uuid WHERE o.global_order_id = ?');
$state->execute([$order]);
$row = $state->fetch(PDO::FETCH_ASSOC);
if (!is_array($row)) {
    fwrite(STDERR, "ORDER_NOT_FOUND\n");
    exit(1);
}
$before = ['order' => $order, 'documentStatus' => $row['document_status'], 'documentVersion' => (int) $row['document_version'],
    'revision' => $row['revision_number'] === null ? null : (int) $row['revision_number'], 'qrHint' => $row['qr_reference'] === null ? null : substr(hash('sha256', (string) $row['qr_reference']), 0, 10)];
if (!array_key_exists('apply', $options)) {
    fwrite(STDOUT, json_encode(['dryRun' => true] + $before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}
if (($options['confirm'] ?? null) !== $order) {
    fwrite(STDERR, "Refused: --confirm must repeat the exact order id.\n");
    exit(2);
}
$rootUuid = $pdo->query('SELECT employee_uuid FROM system_root_identity WHERE singleton_id = 1')->fetchColumn();
$root = is_string($rootUuid) ? $container->employeeRepository()->findByUuid($rootUuid) : null;
if ($root === null || !$root->isRoot) {
    fwrite(STDERR, "ROOT_NOT_CONFIGURED\n");
    exit(1);
}
try {
    $result = $container->documentService()->revoke($root, $order, ['expectedDocumentVersion' => (int) $row['document_version'], 'reason' => mb_substr($reason, 0, 1000)], Uuid::v4(), 'document-revoke-' . gmdate('Ymd\THis\Z'));
} catch (ApiException $error) {
    fwrite(STDERR, $error->errorCode . "\n");
    exit(1);
}
fwrite(STDOUT, json_encode(['applied' => true, 'before' => $before, 'documentStatus' => $result['status'], 'documentVersion' => $result['version']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
