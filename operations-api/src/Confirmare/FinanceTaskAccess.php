<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Employee\EmployeeIdentity;
use PDO;
use PDOException;

/**
 * Which orders a finance scope reaches (API 2.50.0). A finance scope of a source no longer opens every order of that
 * source: a finance employee reaches an order only through a finance task of it (default deny):
 *
 * - a finance verification request of the order assigned to them, or one they recorded or decided;
 * - a request waiting for its second check under the `four_eyes` policy, when they hold `approve` and did not record it;
 * - an exceptional money state the shop reported for the order (a transfer recorded and not decided, an additional
 *   payment, a refund liability or refund, a reconciliation), an unsettled finance action, or a card refund requested in
 *   Confirmare and not yet verified, when they hold `record` or `approve` on the source. These are the existing finance
 *   duties outside the request inbox.
 *
 * A Confirmare scope, an invoice scope or a document scope keeps its own rules: a finance scope never widens them, and
 * holding several scopes never turns a finance scope back into a source-wide one. Finance supervision is not a reach: a
 * supervisor reads the requests of the employees they supervise through the oversight routes only, never an order.
 */
final class FinanceTaskAccess
{
    /** The shop's money attentions that are finance work on their own (never the ordinary wait for an advance). */
    public const EXCEPTIONAL_ATTENTION = ['transfer_pending', 'refund_due', 'refund_liability', 'refund_pending', 'additional_payment_due', 'reconciliation_required'];

    private function __construct()
    {
    }

    public static function reaches(PDO $pdo, FinanceScopePolicy $finance, EmployeeIdentity $actor, string $orderUuid, string $sourceKey): bool
    {
        if ($actor->isRoot || !$actor->isOperationallyActive() || $actor->mustChangePassword) {
            return false;
        }
        try {
            $scopes = $finance->scopesOf($actor->employeeUuid);
        } catch (PDOException) {
            return false;
        }
        $any = false;
        foreach ($scopes as $sources) {
            $any = $any || in_array($sourceKey, $sources, true);
        }
        if (!$any) {
            return false;
        }
        $works = in_array($sourceKey, $scopes[FinanceScopePolicy::RECORD], true) || in_array($sourceKey, $scopes[FinanceScopePolicy::APPROVE], true);
        if (self::requestReaches($pdo, $actor, $orderUuid, in_array($sourceKey, $scopes[FinanceScopePolicy::APPROVE], true))) {
            return true;
        }
        if (!$works) {
            return false;
        }
        $statement = $pdo->prepare('SELECT source_financial_attention FROM operational_orders WHERE order_uuid = ? AND source_key = ?');
        $statement->execute([$orderUuid, $sourceKey]);
        $attention = $statement->fetchColumn();
        if (is_string($attention) && in_array($attention, self::EXCEPTIONAL_ATTENTION, true)) {
            return true;
        }
        try {
            $open = $pdo->prepare("SELECT 1 FROM order_finance_actions WHERE order_uuid = ? AND state IN ('pending', 'unknown') LIMIT 1");
            $open->execute([$orderUuid]);
            if ($open->fetchColumn() !== false) {
                return true;
            }
        } catch (PDOException) {
            return false;
        }
        return self::cardRefundOpen($pdo, $orderUuid);
    }

    /** A full card refund an operator requested (manual in the provider's panel) that no finance employee verified since. */
    private static function cardRefundOpen(PDO $pdo, string $orderUuid): bool
    {
        try {
            $statement = $pdo->prepare("SELECT 1 FROM order_source_operations r WHERE r.order_uuid = ? AND r.kind = 'card_refund_full' AND r.state = 'applied'
                AND r.result IN ('manual_required', 'already_requested')
                AND NOT EXISTS (SELECT 1 FROM order_source_operations v WHERE v.order_uuid = r.order_uuid AND v.kind = 'card_refund_verify'
                    AND v.state = 'applied' AND v.result = 'refund_verified' AND v.requested_at >= r.requested_at) LIMIT 1");
            $statement->execute([$orderUuid]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException) {
            // Before migration 043 no Confirmare operation exists.
            return false;
        }
    }

    private static function requestReaches(PDO $pdo, EmployeeIdentity $actor, string $orderUuid, bool $approver): bool
    {
        try {
            $statement = $pdo->prepare("SELECT 1 FROM finance_verification_requests WHERE order_uuid = ?
                AND (assigned_employee_uuid = ? OR recorded_by_employee_uuid = ? OR decided_by_employee_uuid = ?
                     OR (? = 1 AND state = 'checking' AND policy = 'four_eyes' AND source_entry_id IS NOT NULL AND recorded_by_employee_uuid <> ?)) LIMIT 1");
            $statement->execute([$orderUuid, $actor->employeeUuid, $actor->employeeUuid, $actor->employeeUuid, $approver ? 1 : 0, $actor->employeeUuid]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException) {
            // Before migration 044 no request exists.
            return false;
        }
    }
}
