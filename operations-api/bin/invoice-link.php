<?php

declare(strict_types=1);

// Records a SmartBill final invoice that already exists (made outside Arasya) for its order (API 2.79.0, migration 058,
// owner brief 2026-11-23). Root only. Dry run by default.
//
//   php bin/invoice-link.php --order=<global order id> --series=<SMART> --number=<10012> --issued-on=<YYYY-MM-DD> \
//       --reason="<at least 20 characters>" [--apply --yes]
//
// The shop links the invoice first (YD SOFT 2.34.0: `wp yd-arasya invoice link <order> <series> <number> --reviewer=
// --note= --yes`, which checks the evidence and SmartBill's total). This script then reads the shop's invoice summary
// once (read-only) and, with --apply --yes, records an applied `link` action by root (the order counts as invoiced), the
// e-Factura follow-up from the invoice's own date and an `intervention.invoice-link` IAM audit entry. It never makes,
// sends, cancels or changes a document, a payment, an order or a production state, and never writes the shop. Prints
// order numbers and codes only.

use Arasya\Operations\Confirmare\InvoiceEfactura;
use Arasya\Operations\Confirmare\InvoiceLink;
use Arasya\Operations\Confirmare\InvoiceScopePolicy;
use Arasya\Operations\Iam\IamAuditLogger;

$container = require __DIR__ . '/cli-bootstrap.php';
$options = getopt('', ['order:', 'series:', 'number:', 'issued-on:', 'reason:', 'apply', 'yes']);
foreach (['order', 'series', 'number', 'issued-on', 'reason'] as $required) {
    if (!is_string($options[$required] ?? null) || trim($options[$required]) === '') {
        fwrite(STDERR, "Usage: php bin/invoice-link.php --order=<gid> --series=<series> --number=<number> --issued-on=<YYYY-MM-DD> --reason=\"...\" [--apply --yes]\n");
        exit(2);
    }
}
if (isset($options['apply']) && !isset($options['yes'])) {
    fwrite(STDERR, "Refused: --apply needs --yes.\n");
    exit(2);
}
$pdo = $container->pdo();
$rootUuid = $pdo->query('SELECT employee_uuid FROM system_root_identity LIMIT 1')->fetchColumn();
$root = is_string($rootUuid) ? $container->employeeRepository()->findByUuid($rootUuid) : null;
if ($root === null) {
    fwrite(STDERR, "Refused: no root identity.\n");
    exit(1);
}
$gid = trim($options['order']);
$requestId = 'invoice-link-' . bin2hex(random_bytes(6));
$read = $container->confirmareInvoice(null, true)->read($root, $gid, $requestId);
if (($read['available'] ?? false) !== true || !is_array($read['summary'] ?? null)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'shop_unreadable', 'reason' => $read['reason'] ?? null], JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
$link = new InvoiceLink($pdo, new InvoiceEfactura($pdo, $container->clock(), new InvoiceScopePolicy($pdo)), new IamAuditLogger($pdo), $container->clock());
$series = trim($options['series']);
$number = trim($options['number']);
$result = isset($options['apply'])
    ? $link->apply($root, $gid, $series, $number, $read['summary'], trim($options['issued-on']), (string) $options['reason'], $requestId)
    : $link->plan($gid, $series, $number, $read['summary'], trim($options['issued-on']), (string) $options['reason']);
unset($result['order']);
fwrite(STDOUT, json_encode(['order' => $gid, 'series' => $series, 'number' => $number, 'dryRun' => !isset($options['apply'])] + $result, JSON_UNESCAPED_SLASHES) . "\n");
exit($result['ok'] ? 0 : 1);
