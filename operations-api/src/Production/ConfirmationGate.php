<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\OrderProjectionWriter;
use PDO;

/**
 * Confirmare gate: an order of the Confirmare cohort (or of an undetermined cohort) cannot start production before
 * the Confirmare workflow approved it (order_confirmations.status = 'approved').
 *
 * The gate belongs to the order, not to a switch: it follows the persisted workflow cohort, so neither a later
 * configuration change nor a timeout, a signature error or an older client can open it. Orders without a
 * Confirmare cohort (every order created before API 2.37.0, legacy-cohort orders, Trendyol, B2B) are never affected.
 *
 * Starting production means leaving stage 1 (`waiting`) in any way: the first production document (revision 1, by an
 * employee or by the source operator), a stage-1 claim or stage completion, the cutting QR intake, a manager
 * assignment at stage 1 and a manager takeover to a later stage. Every check runs inside the action's own
 * transaction on the order row it locked, so a stale screen can never pass it. Orders already past stage 1 are never
 * stopped or rewound by the gate. The Confirmare workflow that approves orders is not part of Phase 1
 * (WORKFLOW_READY), so a source cannot be activated yet (bin/workflow-cohort.php refuses).
 */
final class ConfirmationGate
{
    public const BLOCKED_REASON = 'confirmation_required';
    public const ERROR_CODE = 'CONFIRMARE_NECESARA';

    /** Phase 1: nothing can approve an order yet, so no source may be activated (an activation would stop production). */
    public const WORKFLOW_READY = false;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';

    private const MESSAGE = 'Comanda nu poate intra în producție. Comanda trebuie confirmată mai întâi cu clientul (Confirmare).';
    private const MESSAGE_UNDETERMINED = 'Comanda nu poate intra în producție. Momentul plasării comenzii nu a putut fi stabilit; comanda necesită verificare.';

    private function __construct()
    {
    }

    /**
     * Whether production may not start for an order in this cohort, production clearance and stage. API 2.38.0: only
     * order_confirmations.production_cleared opens it (ConfirmationClearance: approval, customer call, every cart line,
     * pricing fingerprint, unchanged review, payment); `approved` alone never does.
     */
    public static function blocksStart(?string $cohort, ?bool $cleared, string $stageId): bool
    {
        if ($stageId !== OrderProjectionWriter::INITIAL_STAGE_ID || !WorkflowCohort::needsConfirmation($cohort)) {
            return false;
        }
        return $cohort === WorkflowCohort::UNDETERMINED || $cleared !== true;
    }

    /**
     * Refuses a production start of an order whose row the caller locked in its transaction. The cohort and the
     * confirmation are read from that order; a Confirmare decision locks the same order row first, so it is either
     * fully before or fully after this check.
     */
    public static function assertOpen(PDO $pdo, string $orderUuid, string $stageId): void
    {
        // A locking read: the decision row is read as committed now, never from an older snapshot of this transaction
        // (API 2.38.0; a decision changes order_confirmations under the same order row lock).
        $closed = self::closedCohort($pdo, $orderUuid, $stageId, true);
        if ($closed !== false) {
            throw self::closed($closed, \Arasya\Operations\Confirmare\ConfirmationClearance::missing($pdo, $orderUuid));
        }
    }

    /** Whether production may not start for the stored order (read-only; the source document state uses it). */
    public static function isClosed(PDO $pdo, string $orderUuid, string $stageId): bool
    {
        return self::closedCohort($pdo, $orderUuid, $stageId) !== false;
    }

    /** The cohort of an order whose production start is closed, or false when it is open. */
    private static function closedCohort(PDO $pdo, string $orderUuid, string $stageId, bool $lock = false): string|false
    {
        if ($stageId !== OrderProjectionWriter::INITIAL_STAGE_ID) {
            return false;
        }
        $statement = $pdo->prepare('SELECT o.workflow_cohort, c.production_cleared FROM operational_orders o LEFT JOIN order_confirmations c ON c.order_uuid = o.order_uuid WHERE o.order_uuid = ?' . ($lock ? ' LOCK IN SHARE MODE' : ''));
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['workflow_cohort'] === null) {
            return false;
        }
        $cohort = (string) $row['workflow_cohort'];
        return self::blocksStart($cohort, $row['production_cleared'] === null ? null : (int) $row['production_cleared'] === 1, $stageId) ? $cohort : false;
    }

    /** @param list<string> $missing the clearance conditions not met (ConfirmationClearance::CONDITIONS) */
    public static function closed(?string $cohort, array $missing = []): ApiException
    {
        return new ApiException(409, self::ERROR_CODE, $cohort === WorkflowCohort::UNDETERMINED ? self::MESSAGE_UNDETERMINED : self::MESSAGE, ['workflowCohort' => $cohort ?? WorkflowCohort::CONFIRMARE] + ($missing === [] ? [] : ['missing' => $missing]));
    }
}
