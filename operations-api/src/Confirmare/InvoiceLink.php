<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * API 2.79.0 (owner 2026-11-23, recovery orders' final invoices): Operations records a SmartBill final invoice that
 * already exists and that the shop has linked to the order (YD SOFT 2.34.0 `wp yd-arasya invoice link`), so the order
 * counts as invoiced (ISSUED_SQL), its e-Factura follow-up exists and the IAM audit names who linked it. Root only, from
 * bin/invoice-link.php (dry run by default). It never calls the shop for a write and never makes, sends, cancels or
 * changes a document, a payment, an order or a production state.
 *
 * Refused unless all of these hold: the order exists in Confirmare; Operations has no applied issue, re-issue or link of
 * it (`already_invoiced`) and no open invoice action (`invoice_action_open`); the shop's current summary names exactly
 * this invoice (`shop_document_differs`) through its own journal (`through` = arasya; a document only the SmartBill plugin
 * records is linked in the shop first: `shop_not_linked`) with no operation open (`shop_operation_open`); a reason of at
 * least 20 characters (`reason_missing`); the invoice date is a real date not in the future (`issued_at_invalid`).
 */
final class InvoiceLink
{
    public function __construct(private PDO $pdo, private ?InvoiceEfactura $efactura, private IamAuditLogger $audit, private Clock $clock)
    {
    }

    /**
     * @param array<string, mixed> $summary the shop's invoice summary (ConfirmareInvoice::read()['summary'])
     * @return array{ok: bool, error?: string, order?: array<string, string>}
     */
    public function plan(string $globalOrderId, string $series, string $number, array $summary, string $issuedAt, string $reason): array
    {
        $order = $this->order($globalOrderId);
        if ($order === null) {
            return ['ok' => false, 'error' => 'order_not_found'];
        }
        if (mb_strlen(trim($reason)) < 20) {
            return ['ok' => false, 'error' => 'reason_missing'];
        }
        if ($this->issuedAt($issuedAt) === null) {
            return ['ok' => false, 'error' => 'issued_at_invalid'];
        }
        $issued = $this->pdo->prepare('SELECT 1 FROM order_invoice_actions i WHERE i.order_uuid = ? AND ' . ConfirmareInvoice::ISSUED_SQL . ' LIMIT 1');
        $issued->execute([$order['order_uuid']]);
        if ($issued->fetchColumn() !== false) {
            return ['ok' => false, 'error' => 'already_invoiced'];
        }
        if (ConfirmareInvoice::actionOpen($this->pdo, $order['order_uuid'])) {
            return ['ok' => false, 'error' => 'invoice_action_open'];
        }
        $document = is_array($summary['document'] ?? null) ? $summary['document'] : [];
        $digits = static fn (mixed $v): string => ltrim((string) preg_replace('/\D/', '', (string) $v), '0');
        if (($document['exists'] ?? false) !== true || (string) ($document['series'] ?? '') !== $series || $digits($document['number'] ?? '') === '' || $digits($document['number'] ?? '') !== $digits($number)) {
            return ['ok' => false, 'error' => 'shop_document_differs'];
        }
        if (($document['through'] ?? null) !== 'arasya') {
            return ['ok' => false, 'error' => 'shop_not_linked'];
        }
        if (in_array($summary['operation']['state'] ?? 'none', ['in_flight', 'unknown'], true)) {
            return ['ok' => false, 'error' => 'shop_operation_open'];
        }
        return ['ok' => true, 'order' => $order];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array{ok: bool, error?: string, actionUuid?: string}
     */
    public function apply(EmployeeIdentity $root, string $globalOrderId, string $series, string $number, array $summary, string $issuedAt, string $reason, string $requestId): array
    {
        if (!$root->isRoot) {
            return ['ok' => false, 'error' => 'root_only'];
        }
        $plan = $this->plan($globalOrderId, $series, $number, $summary, $issuedAt, $reason);
        if (!$plan['ok']) {
            return $plan;
        }
        $order = $plan['order'];
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $actionUuid = Uuid::v4();
        $key = 'link-' . substr(hash('sha256', $globalOrderId . '|' . $series . '|' . $number), 0, 40);
        $revision = is_string($summary['revision'] ?? null) && preg_match('/^sha256:[0-9a-f]{64}$/D', $summary['revision']) === 1 ? $summary['revision'] : 'sha256:' . str_repeat('0', 64);
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
            $lock->execute([$order['order_uuid']]);
            $again = $this->plan($globalOrderId, $series, $number, $summary, $issuedAt, $reason);
            if (!$again['ok']) {
                $this->pdo->rollBack();
                return $again;
            }
            $this->pdo->prepare('INSERT INTO order_invoice_actions (action_uuid, order_uuid, global_order_id, source_key, employee_uuid, access_role, action, reason_code, idempotency_key, request_hmac,
                    revision_before, state, outcome, result, request_id, requested_at, completed_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $actionUuid, $order['order_uuid'], $globalOrderId, $order['source_key'], $root->employeeUuid, 'manager', 'link', $key,
                hash('sha256', json_encode([$globalOrderId, $series, $number, $issuedAt], JSON_THROW_ON_ERROR), true), $revision, 'applied', 'applied', 'issued', mb_substr($requestId, 0, 100), $now, $now,
            ]);
            $this->audit->record($root, 'intervention.invoice-link', 'order', $globalOrderId, $globalOrderId, ['reason' => trim($reason), 'series' => $series, 'number' => $number, 'issuedOn' => $issuedAt, 'outcome' => 'applied'], $requestId, $now);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        // The e-Factura follow-up runs from the invoice's own date (it may already be overdue: accounting checks SPV).
        $this->efactura?->onInvoiceIssued($actionUuid, $order['order_uuid'], $globalOrderId, $order['source_key'], $root->employeeUuid, $this->issuedAt($issuedAt));
        return ['ok' => true, 'actionUuid' => $actionUuid];
    }

    /** @return array{order_uuid: string, source_key: string}|null */
    private function order(string $globalOrderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT o.order_uuid, o.source_key FROM operational_orders o INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid WHERE o.global_order_id = ?');
        $statement->execute([$globalOrderId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['order_uuid' => (string) $row['order_uuid'], 'source_key' => (string) $row['source_key']] : null;
    }

    /** The invoice date (Y-m-d, Europe/Bucharest) as the instant the 5 working days run from; null when not a past or present day. */
    private function issuedAt(string $day): ?DateTimeImmutable
    {
        $tz = new DateTimeZone('Europe/Bucharest');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $tz);
        if ($date === false || $date->format('Y-m-d') !== $day || $date > $this->clock->now()->setTimezone($tz)) {
            return null;
        }
        return $date->setTime(12, 0);
    }
}
