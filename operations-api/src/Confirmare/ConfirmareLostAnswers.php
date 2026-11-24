<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Production\WorkflowCohort;
use Arasya\Operations\Support\Clock;
use PDO;
use PDOException;
use Throwable;

/**
 * Lost answers of shop invoice actions (API 2.77.4, owner 2026-11-24, proforma crisis): the API waits 8 seconds for the shop.
 * When the shop answers later, the action (total acceptance, advance proforma issue or e-mail, final invoice) stays
 * `pending`/`unknown` in Arasya although the shop finished it, and every later invoice action of the order is refused with
 * `invoice_action_open`. An employee's own "check again" right after the click comes before the 4-minute window and settles
 * nothing; nobody came back, so orders stayed blocked for days.
 *
 * Run by the Confirmare cycle worker every 5 minutes: each unsettled action older than ConfirmareInvoice::RECONCILE_AFTER_SECONDS
 * (and at most 14 days old) is settled with the shop's STORED answer of its own Idempotency-Key, exactly as
 * ConfirmareInvoice::reconcile does for a manager. Nothing is ever sent to SmartBill again: an outcome the shop itself does
 * not know stays open for a manager. An action older than an hour is asked again only every 30 minutes.
 */
final class ConfirmareLostAnswers
{
    public const SOURCES = ['trendhome', 'outletperdele'];
    public const MAX_AGE_DAYS = 14;
    /** After the first hour an action still open is asked again once per this many seconds. */
    public const SLOW_EVERY_SECONDS = 1800;
    public const REQUEST_PREFIX = 'system-lost-answers';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly ConfirmareInvoice $invoice,
        private readonly PdoEmployeeRepository $employees,
    ) {
    }

    /**
     * The unsettled invoice actions due to be asked again now, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function due(int $limit = 20): array
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $marks = implode(', ', array_fill(0, count(self::SOURCES), '?'));
        try {
            $statement = $this->pdo->prepare("SELECT a.global_order_id, a.idempotency_key, a.employee_uuid, a.action, a.requested_at
                FROM order_invoice_actions a INNER JOIN operational_orders o ON o.order_uuid = a.order_uuid
                WHERE a.state IN ('pending', 'unknown') AND a.source_key IN ({$marks}) AND o.workflow_cohort = ?
                  AND a.requested_at < ? AND a.requested_at > ?
                ORDER BY a.requested_at LIMIT 500");
            $statement->execute([...self::SOURCES, WorkflowCohort::CONFIRMARE,
                $now->modify('-' . ConfirmareInvoice::RECONCILE_AFTER_SECONDS . ' seconds')->format('Y-m-d H:i:s.u'),
                $now->modify('-' . self::MAX_AGE_DAYS . ' days')->format('Y-m-d H:i:s.u')]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return [];
        }
        $due = [];
        foreach ($rows as $row) {
            $age = $now->getTimestamp() - (new \DateTimeImmutable((string) $row['requested_at'], new \DateTimeZone('UTC')))->getTimestamp();
            // The cycle worker runs every 5 minutes: after the first hour, one run in six asks again.
            if ($age >= 3600 && intdiv($age, 300) % intdiv(self::SLOW_EVERY_SECONDS, 300) !== 0) {
                continue;
            }
            $due[] = $row;
            if (count($due) >= max(1, $limit)) {
                break;
            }
        }
        return $due;
    }

    /**
     * Settles the due actions. The root identity (an invoice manager on the intervention desk) asks for the employee's own
     * key; the follow-ups (e-Factura, the proforma timer) stay the employee's and start when the action ran.
     *
     * @return array{seen: int, applied: int, rejected: int, open: int, skipped: int}
     */
    public function run(int $limit = 20): array
    {
        $counts = ['seen' => 0, 'applied' => 0, 'rejected' => 0, 'open' => 0, 'skipped' => 0];
        $rootUuid = $this->pdo->query('SELECT employee_uuid FROM system_root_identity LIMIT 1')->fetchColumn();
        $root = is_string($rootUuid) ? $this->employees->findByUuid($rootUuid) : null;
        foreach ($this->due($limit) as $row) {
            $counts['seen']++;
            $actor = $root ?? $this->employees->findByUuid((string) $row['employee_uuid']);
            if ($actor === null) {
                $counts['skipped']++;
                continue;
            }
            try {
                $settled = $this->invoice->reconcile($actor, (string) $row['global_order_id'], (string) $row['idempotency_key'], self::REQUEST_PREFIX . '-' . bin2hex(random_bytes(6)));
                $counts[($settled['state'] ?? null) === 'applied' ? 'applied' : 'open']++;
            } catch (ApiException $e) {
                if ($e->errorCode === 'RATE_LIMITED') {
                    break;
                }
                // A refusal the shop stored settles the action as rejected (the order is free again).
                $counts[$e->errorCode === 'CONFIRMARE_INVOICE_REFUSED' ? 'rejected' : 'skipped']++;
            } catch (Throwable) {
                $counts['skipped']++;
            }
        }
        return $counts;
    }
}
