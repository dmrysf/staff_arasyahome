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
 * stopped or rewound by the gate. Phase 1 had no workflow that approves orders (WORKFLOW_READY was false and every
 * activation was refused); since API 2.45.0 whether a source may be activated is decided per source by
 * ConfirmareReadiness.
 *
 * API 2.42.0 (Phase 6, production handoff): the stored clearance flag is refreshed by Confirmare decisions and source
 * snapshots only, so the gate also reads, live, whether a finance action of the order is pending or unknown
 * (ConfirmareFinance) or a shop change is not settled (ConfirmareSourceEdits). Either one closes production at once.
 * And a Confirmare-cohort order leaves stage 1 only with its first production document (DOCUMENT_ERROR_CODE): the
 * shop employee generates revision 1 once the order is cleared, the cutting QR scan of that document starts
 * production. Generating revision 1 itself needs only assertOpen().
 */
final class ConfirmationGate
{
    public const BLOCKED_REASON = 'confirmation_required';
    public const ERROR_CODE = 'CONFIRMARE_NECESARA';
    public const DOCUMENT_BLOCKED_REASON = 'confirmation_document_required';
    public const DOCUMENT_ERROR_CODE = 'CONFIRMARE_DOCUMENT_NECESAR';

    /**
     * The effective clearance of the order aliased `o`, as one SQL expression: NULL without a Confirmare row, 1 only when
     * order_confirmations.production_cleared is set, no finance action of the order is pending or unknown (the
     * condition of ConfirmareFinance::actionOpen) and no shop change is unsettled (ConfirmareSourceEdits::changePending).
     */
    public const CLEARED_SQL = "(SELECT c.production_cleared = 1
            AND NOT EXISTS (SELECT 1 FROM order_finance_actions fa WHERE fa.order_uuid = c.order_uuid AND fa.state IN ('pending', 'unknown'))
            AND NOT EXISTS (SELECT 1 FROM order_confirmation_source_edits se WHERE se.order_uuid = c.order_uuid
                AND (se.state IN ('pending', 'unknown') OR (se.state = 'applied' AND (o.source_changed_at IS NULL OR o.source_changed_at < se.source_modified_after))))
        FROM order_confirmations c WHERE c.order_uuid = o.order_uuid)";

    /**
     * Whether this release contains the workflow that approves Confirmare orders and hands them to production (approval
     * since API 2.38.0, production handoff since 2.42.0). It no longer gates activation by itself: a source is activated
     * only when its own ConfirmareReadiness passes (API 2.45.0), so one shop can run Confirmare while another stays off.
     */
    public const WORKFLOW_READY = true;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';

    private const MESSAGE = 'Comanda nu poate intra în producție. Comanda trebuie confirmată mai întâi cu clientul (Confirmare).';
    private const MESSAGE_UNDETERMINED = 'Comanda nu poate intra în producție. Momentul plasării comenzii nu a putut fi stabilit; comanda necesită verificare.';
    private const MESSAGE_DOCUMENT = 'Comanda nu poate intra în producție fără fișa de producție. Fișa (revizia 1) se generează din Confirmare, apoi tăierea scanează codul QR al fișei.';

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

    /**
     * Whether a Confirmare-cohort order at stage 1 still lacks its first production document: production does not leave
     * stage 1 before revision 1 exists and is usable (document_status `active`). Orders of every other cohort, orders
     * past stage 1 and the generation of revision 1 itself are never affected.
     */
    public static function needsDocument(?string $cohort, string $documentStatus, string $stageId): bool
    {
        return $stageId === OrderProjectionWriter::INITIAL_STAGE_ID && WorkflowCohort::needsConfirmation($cohort) && $documentStatus !== 'active';
    }

    /**
     * assertOpen() plus the first document of a Confirmare-cohort order, for a production start other than the document
     * itself (a manager assignment at stage 1, a takeover to a later stage). The caller locked the order row.
     */
    public static function assertStartable(PDO $pdo, string $orderUuid, string $stageId): void
    {
        self::assertOpen($pdo, $orderUuid, $stageId);
        $statement = $pdo->prepare('SELECT workflow_cohort, document_status FROM operational_orders WHERE order_uuid = ? LOCK IN SHARE MODE');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && self::needsDocument($row['workflow_cohort'] === null ? null : (string) $row['workflow_cohort'], (string) $row['document_status'], $stageId)) {
            throw self::documentRequired();
        }
    }

    public static function documentRequired(): ApiException
    {
        return new ApiException(409, self::DOCUMENT_ERROR_CODE, self::MESSAGE_DOCUMENT);
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
        $cleared = $row['production_cleared'] === null ? null : (int) $row['production_cleared'] === 1;
        if ($cleared === true && self::changePending($pdo, $orderUuid, $lock)) {
            $cleared = false;
        }
        return self::blocksStart($cohort, $cleared, $stageId) ? $cohort : false;
    }

    /**
     * Whether a finance action of the order is pending or unknown, or a shop change of it is not settled (API 2.42.0).
     * Locking reads when the caller holds the order row: both are written under that same order row lock.
     */
    private static function changePending(PDO $pdo, string $orderUuid, bool $lock): bool
    {
        $suffix = $lock ? ' LOCK IN SHARE MODE' : '';
        $finance = $pdo->prepare("SELECT 1 FROM order_finance_actions WHERE order_uuid = ? AND state IN ('pending', 'unknown') LIMIT 1" . $suffix);
        $finance->execute([$orderUuid]);
        if ($finance->fetchColumn() !== false) {
            return true;
        }
        $edits = $pdo->prepare("SELECT 1 FROM order_confirmation_source_edits e INNER JOIN operational_orders o ON o.order_uuid = e.order_uuid
            WHERE e.order_uuid = ? AND (e.state IN ('pending', 'unknown') OR (e.state = 'applied' AND (o.source_changed_at IS NULL OR o.source_changed_at < e.source_modified_after))) LIMIT 1" . $suffix);
        $edits->execute([$orderUuid]);
        return $edits->fetchColumn() !== false;
    }

    /** @param list<string> $missing the clearance conditions not met (ConfirmationClearance::CONDITIONS) */
    public static function closed(?string $cohort, array $missing = []): ApiException
    {
        return new ApiException(409, self::ERROR_CODE, $cohort === WorkflowCohort::UNDETERMINED ? self::MESSAGE_UNDETERMINED : self::MESSAGE, ['workflowCohort' => $cohort ?? WorkflowCohort::CONFIRMARE] + ($missing === [] ? [] : ['missing' => $missing]));
    }
}
