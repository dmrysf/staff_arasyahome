<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Order\OrderProjectionWriter;
use Arasya\Operations\Production\PaymentGate;
use Arasya\Operations\Production\ProductionAuthorityModes;
use PDO;

/**
 * Production handoff of a Confirmare order (API 2.42.0, Phase 6). Read-only: it tells the shop employee where the order
 * stands between the Confirmare approval and the cutting department, and never changes anything itself.
 *
 * Confirmare is not a production stage. The order stays at stage 1 (`waiting`) until the cutting QR intake moves it to
 * cutting (the existing 1 -> 2 transition). In between, an authorized shop employee generates revision 1 of the central
 * production document (DocumentService::generate, the only document and QR system) and prints it; the cutter scans its
 * QR. Production approval (ConfirmationClearance) and the financial condition are independent and both mandatory; the
 * enforced advance payment gate of the source applies too.
 *
 * States:
 *   closed           the shop cancelled the order (or it is no longer available) or production is completed
 *   blocked          a condition is missing (see `blockers`); no document, no QR, no production start
 *   ready            everything holds and revision 1 does not exist yet: generate it
 *   print            revision 1 exists and was never downloaded for printing
 *   awaiting_cutting revision 1 was printed; the cutting QR scan starts production
 *   in_production    the order left stage 1
 *
 * Blockers (codes only, never an amount): the ConfirmationClearance conditions (approval, call, lines, price, sync,
 * payment) and finance_action (a finance action is pending or unknown), shop_change (a shop change is not settled),
 * payment_gate (the source's enforced advance payment gate is closed), exception (an open production exception),
 * document_blocked (the document is stale or revoked: the revision approval flow applies).
 */
final readonly class ConfirmareHandoff
{
    public const STATES = ['closed', 'blocked', 'ready', 'print', 'awaiting_cutting', 'in_production'];

    public function __construct(private PDO $pdo, private ?ProductionAuthorityModes $modes = null)
    {
    }

    /**
     * @param bool $mayGenerate whether the viewer may generate documents for the order's source (permission and scope)
     * @param bool $mayPrint whether the viewer may print them (generate or reprint permission, and scope)
     * @return array<string, mixed>
     */
    public function view(string $orderUuid, bool $mayGenerate, bool $mayPrint): array
    {
        $statement = $this->pdo->prepare('SELECT o.source_key, o.production_stage_id, o.production_completed_at, o.operational_status, o.open_exception_uuid, o.document_status,
                o.payment_method, o.advance_status, r.revision_number, (SELECT COUNT(*) FROM production_document_prints p WHERE p.revision_uuid = r.revision_uuid) AS prints
            FROM operational_orders o LEFT JOIN production_document_revisions r ON r.revision_uuid = o.active_document_revision_uuid WHERE o.order_uuid = ?');
        $statement->execute([$orderUuid]);
        $order = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            return ['state' => 'closed', 'blockers' => [], 'stageId' => null, 'document' => null, 'actions' => ['generate' => false, 'print' => false]];
        }
        $stage = (string) $order['production_stage_id'];
        $documentStatus = (string) $order['document_status'];
        $revision = $order['revision_number'] === null ? null : (int) $order['revision_number'];
        $prints = (int) $order['prints'];
        $blockers = [];
        if ($stage === OrderProjectionWriter::INITIAL_STAGE_ID) {
            $blockers = ConfirmationClearance::missing($this->pdo, $orderUuid);
            if (ConfirmareFinance::actionOpen($this->pdo, $orderUuid)) {
                $blockers[] = 'finance_action';
            }
            if (ConfirmareSourceEdits::changePending($this->pdo, $orderUuid)) {
                $blockers[] = 'shop_change';
            }
            if (PaymentGate::blocksStart($this->modes, (string) $order['source_key'], self::text($order['payment_method']), self::text($order['advance_status']))) {
                $blockers[] = 'payment_gate';
            }
            if ($order['open_exception_uuid'] !== null) {
                $blockers[] = 'exception';
            }
            if (in_array($documentStatus, ['stale', 'revoked'], true)) {
                $blockers[] = 'document_blocked';
            }
        }
        $state = match (true) {
            $order['operational_status'] === 'unavailable' || $order['production_completed_at'] !== null => 'closed',
            $stage !== OrderProjectionWriter::INITIAL_STAGE_ID => 'in_production',
            $blockers !== [] => 'blocked',
            $documentStatus === 'none' => 'ready',
            $prints === 0 => 'print',
            default => 'awaiting_cutting',
        };
        return [
            'state' => $state,
            'blockers' => $state === 'closed' ? [] : array_values(array_unique($blockers)),
            'stageId' => $stage,
            'document' => ['status' => $documentStatus, 'revision' => $revision, 'prints' => $prints],
            // What the viewer can do here; the document commands check everything again on the locked order.
            'actions' => [
                'generate' => $mayGenerate && $state === 'ready',
                'print' => $mayPrint && in_array($state, ['print', 'awaiting_cutting'], true),
            ],
        ];
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
