<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Employee\EmployeeIdentity;
use PDO;
use PDOException;

/**
 * Which orders a finance scope reaches (API 2.50.0). A finance scope of a source never opens the orders of that source:
 * a finance employee reaches an order only through a finance task assigned to them (default deny), whatever the order's
 * date or workflow cohort:
 *
 * - a finance task of the order (advance verification, second check, exception) assigned to them, or one they recorded
 *   or decided;
 * - an open task they are the named checker of (`checker_employee_uuid`: the second employee of a recorded transfer, or
 *   the approver of an exception task's refund or transfer), while they still hold `approve`;
 * - a finance action of the order they sent themselves and whose answer is still pending or unknown (to reconcile it).
 *
 * Holding `record` or `approve` alone reaches nothing: an exceptional money state, a transfer waiting for its second
 * check or a card refund to verify becomes reachable only as a task assigned to a named employee (FinanceTaskInventory).
 * A Confirmare scope, an invoice scope or a document scope keeps its own rules: a finance scope never widens them.
 * Finance supervision is not a reach: a supervisor reads the tasks of the employees they supervise through the oversight
 * routes only, never an order.
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
        if (self::taskReaches($pdo, $actor, $orderUuid, in_array($sourceKey, $scopes[FinanceScopePolicy::APPROVE], true))) {
            return true;
        }
        try {
            // Their own action whose answer is not settled yet: they reconcile it (never another employee's).
            $own = $pdo->prepare("SELECT 1 FROM order_finance_actions WHERE order_uuid = ? AND employee_uuid = ? AND state IN ('pending', 'unknown') LIMIT 1");
            $own->execute([$orderUuid, $actor->employeeUuid]);
            return $own->fetchColumn() !== false;
        } catch (PDOException) {
            return false;
        }
    }

    private static function taskReaches(PDO $pdo, EmployeeIdentity $actor, string $orderUuid, bool $approver): bool
    {
        try {
            $statement = $pdo->prepare("SELECT 1 FROM finance_verification_requests WHERE order_uuid = ?
                AND (assigned_employee_uuid = ? OR recorded_by_employee_uuid = ? OR decided_by_employee_uuid = ?
                     OR (? = 1 AND checker_employee_uuid = ? AND open_order_uuid IS NOT NULL)) LIMIT 1");
            $statement->execute([$orderUuid, $actor->employeeUuid, $actor->employeeUuid, $actor->employeeUuid, $approver ? 1 : 0, $actor->employeeUuid]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException) {
            // Before migration 044 no task exists.
            return false;
        }
    }

    /** A full card refund an operator requested (manual in the provider's panel) that no finance employee verified since. */
    public static function cardRefundOpen(PDO $pdo, string $orderUuid): bool
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
}
