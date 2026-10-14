<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Cutting\CuttingLifecycle;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Production\PaymentGate;
use Arasya\Operations\Production\ProductionAuthority;
use Arasya\Operations\Production\ProductionAuthorityMode;
use Arasya\Operations\Production\ProductionAuthorityModes;
use Arasya\Operations\Production\ProductionWorkflow;

/**
 * Server-side operational authority for one employee and one order.
 *
 * Visibility: an order is visible when the employee works at its current
 * production stage for its source (the stage is granted and, for a
 * source-scoped grant (migration 023), the order's source is one of the
 * granted sources), or when the employee has a direct relation with it and
 * still reaches its source at some granted stage. A Trendyol order at the
 * initial stage also needs the Trendyol view permission on both paths.
 * Everything else is reported as not found. Claim and stage completion
 * always use the current stage-and-source rule; a relation never authorizes
 * a mutation.
 *
 * Production authority: while the order's source enforces the authority split, an order that is still
 * managed by its source (production_authority = 'source') cannot be worked on in Staff at all; a manager
 * must take it over explicitly first. In legacy and observe modes nothing changes here.
 *
 * Cutting intake (evaluateCuttingIntake) is the one narrow exception to the current-stage rule: an eligible
 * cutter who scans the active Arasya QR of a stage-1 order may receive it into cutting, because that cutter is
 * authorized for the next stage. It authorizes only that command; canView and every other route are unchanged.
 */
final readonly class OrderAccessPolicy
{
    public const ACTION_CLAIM = 'claim';
    public const ACTION_COMPLETE_STAGE = 'complete_stage';
    public const ACTION_COMPLETE_PRODUCTION = 'complete_production';
    public const BLOCKED_AUTHORITY_SOURCE = 'production_authority_source';
    public const TRENDYOL_SOURCE = 'trendyol';
    public const TRENDYOL_VIEW = 'trendyol.orders.view';
    public const ACTION_CUTTING_INTAKE = 'cutting_intake';
    public const ACTION_ALREADY_MINE = 'already_mine';
    /** The order is at packing but some of its pieces are still in rework on a separate track. */
    public const BLOCKED_PIECES_IN_REWORK = 'pieces_in_rework';
    /** Stages an order may not be worked at while any of its pieces is away in rework. */
    public const PIECE_GATE_STAGES = ['packing', 'delivery'];
    /** Order sources whose stage-1 orders a cutter may receive by QR (B2B keeps its existing flow). */
    public const CUTTING_INTAKE_SOURCES = ['trendyol', 'trendhome', 'outletperdele'];

    public function __construct(private AuthorizationService $authorization, private ?ProductionAuthorityModes $authorityModes = null)
    {
    }

    /** Whether Arasya must not act on this order because its source still owns production. */
    public function isSourceManagedUnderEnforcement(OperationalOrder $order): bool
    {
        return $order->productionAuthority === ProductionAuthority::SOURCE
            && $this->authorityModes?->modeFor($order->globalId->sourceKey) === ProductionAuthorityMode::Enforce;
    }

    /**
     * Whether the source's enforced advance payment gate keeps this order at stage 1: every action that starts
     * production (claim, stage completion, cutting intake) is refused while the advance is waiting, partial or
     * unknown. Orders past stage 1 are never stopped by the gate.
     */
    public function blocksPaymentStart(OperationalOrder $order): bool
    {
        return $order->productionStageId === OrderProjectionWriter::INITIAL_STAGE_ID
            && PaymentGate::blocksStart($this->authorityModes, $order->globalId->sourceKey, $order->paymentMethod, $order->advanceStatus);
    }

    /** Observe mode: a Staff claim of a source-managed order is allowed but recorded. */
    public function isSourceManagedUnderObservation(OperationalOrder $order): bool
    {
        return $order->productionAuthority === ProductionAuthority::SOURCE
            && $this->authorityModes?->modeFor($order->globalId->sourceKey) === ProductionAuthorityMode::Observe;
    }

    public function canView(EmployeeIdentity $employee, OperationalOrder $order): bool
    {
        if (!$employee->isOperationallyActive()) {
            return false;
        }
        $source = $order->globalId->sourceKey;
        // A direct relation (an order the employee worked on) keeps the order visible after it moved on, but never
        // past the employee's current source authorization: once no granted stage reaches the order's source any
        // more (a narrowed scope or a removed stage), the relation alone shows nothing. The employee's own activity
        // ledger (GET /activity/mine) and the audit trail keep the historical record.
        $related = $order->relation !== null && $order->relation->employeeUuid === $employee->employeeUuid;
        // A piece in rework is work at its own stage: an employee of that stage (or its owner) sees the order too.
        $tracked = false;
        foreach ($order->activeTracks as $track) {
            if ($track['ownerEmployeeUuid'] === $employee->employeeUuid || $employee->worksAt($track['stageId'], $source)) {
                $tracked = true;
                break;
            }
        }
        if ($related || ($tracked && !$employee->worksAt($order->productionStageId, $source))
            ? !$employee->reachesSource($source) : !$employee->worksAt($order->productionStageId, $source)) {
            return false;
        }
        // An approved Trendyol order at the initial stage is still Trendyol preparation work: besides the stage, it
        // is visible only to explicitly authorized Trendyol personnel. From the next stage on it is ordinary work.
        return $source !== self::TRENDYOL_SOURCE
            || $order->productionStageId !== OrderProjectionWriter::INITIAL_STAGE_ID
            || $this->authorization->can($employee, self::TRENDYOL_VIEW);
    }

    /** @return array{action: string|null, blockedReason: string|null} */
    public function evaluate(EmployeeIdentity $employee, OperationalOrder $order, ?ProductionWorkflow $workflow): array
    {
        if ($order->operationalStatus === 'unavailable') {
            return $this->blocked('order_unavailable');
        }
        if ($order->isProductionCompleted()) {
            return $this->blocked('production_completed');
        }
        if ($order->isBlockedByException()) {
            return $this->blocked('exception_pending');
        }
        if ($order->isBlockedByDocument()) {
            return $this->blocked(\Arasya\Operations\Document\DocumentGuard::BLOCKED_REASON);
        }
        if ($this->blocksPaymentStart($order)) {
            return $this->blocked(PaymentGate::BLOCKED_REASON);
        }
        if ($workflow === null) {
            return $this->blocked('workflow_unavailable');
        }
        if ($this->isSourceManagedUnderEnforcement($order)) {
            return $this->blocked(self::BLOCKED_AUTHORITY_SOURCE);
        }
        // An order is never packed, delivered or completed while one of its pieces is still in rework: the pieces
        // rejoin the order at packing first (ProductionTracks::mergeAtGate).
        if ($order->hasActiveTracks() && in_array($order->productionStageId, self::PIECE_GATE_STAGES, true)) {
            return $this->blocked(self::BLOCKED_PIECES_IN_REWORK);
        }
        $stageAllowed = $employee->worksAt($order->productionStageId, $order->globalId->sourceKey);
        $owner = $order->productionOwnerEmployeeUuid;
        if ($owner !== null && $owner !== $employee->employeeUuid) {
            return $this->blocked('claimed_by_other');
        }
        if (!$stageAllowed) {
            return $this->blocked('stage_not_allowed');
        }
        if ($owner === null) {
            return $this->authorization->can($employee, 'orders.claim')
                ? ['action' => self::ACTION_CLAIM, 'blockedReason' => null]
                : $this->blocked('permission_missing');
        }
        if (!$this->authorization->can($employee, 'orders.advance_stage')) {
            return $this->blocked('permission_missing');
        }
        $last = $workflow->stages[count($workflow->stages) - 1] ?? null;
        return [
            'action' => $last !== null && $last->id === $order->productionStageId ? self::ACTION_COMPLETE_PRODUCTION : self::ACTION_COMPLETE_STAGE,
            'blockedReason' => null,
        ];
    }

    /** Whether the employee may use the QR cutting intake at all; checked before any order is read. */
    public function mayUseCuttingIntake(EmployeeIdentity $employee): bool
    {
        return CuttingLifecycle::eligible($employee) && $this->authorization->can($employee, 'orders.scan');
    }

    /** Whether the order is one the employee could receive by cutting intake (it may still be blocked). */
    public function reachesForCuttingIntake(EmployeeIdentity $employee, OperationalOrder $order): bool
    {
        $source = $order->globalId->sourceKey;
        return $this->mayUseCuttingIntake($employee)
            && $employee->worksAt(CuttingLifecycle::STAGE, $source)
            && in_array($source, self::CUTTING_INTAKE_SOURCES, true)
            && in_array($order->productionStageId, [OrderProjectionWriter::INITIAL_STAGE_ID, CuttingLifecycle::STAGE], true);
    }

    /**
     * Cutting intake from a scanned Arasya QR. The employee must be an eligible cutter (CuttingLifecycle::eligible
     * plus orders.scan) who works at the cutting stage for the order's source; the waiting-stage grant and the
     * Trendyol view permission are deliberately not required, because the order is received into cutting, never
     * worked on at stage 1. Every production block of evaluate() applies unchanged.
     *
     * Actions: cutting_intake (stage 1, unowned: move to cutting and claim), claim (cutting, unowned),
     * already_mine (cutting, owned by the employee). Reasons: intake_not_allowed, not_found (source not reached),
     * intake_not_applicable (another stage or source: the ordinary scan applies), plus the reasons of evaluate().
     *
     * @return array{action: string|null, blockedReason: string|null}
     */
    public function evaluateCuttingIntake(EmployeeIdentity $employee, OperationalOrder $order, ?ProductionWorkflow $workflow): array
    {
        if (!$this->mayUseCuttingIntake($employee)) {
            return $this->blocked('intake_not_allowed');
        }
        if (!$employee->worksAt(CuttingLifecycle::STAGE, $order->globalId->sourceKey)) {
            return $this->blocked('not_found');
        }
        if (!$this->reachesForCuttingIntake($employee, $order)) {
            return $this->blocked('intake_not_applicable');
        }
        if ($order->operationalStatus === 'unavailable') {
            return $this->blocked('order_unavailable');
        }
        if ($order->isProductionCompleted()) {
            return $this->blocked('production_completed');
        }
        if ($order->isBlockedByException()) {
            return $this->blocked('exception_pending');
        }
        if ($order->isBlockedByDocument()) {
            return $this->blocked(\Arasya\Operations\Document\DocumentGuard::BLOCKED_REASON);
        }
        if ($this->blocksPaymentStart($order)) {
            return $this->blocked(PaymentGate::BLOCKED_REASON);
        }
        // The handoff relies on cutting being the stage right after stage 1 of the active workflow.
        $ids = $workflow === null ? [] : array_map(static fn ($stage): string => $stage->id, $workflow->stages);
        if (array_slice($ids, 0, 2) !== [OrderProjectionWriter::INITIAL_STAGE_ID, CuttingLifecycle::STAGE]) {
            return $this->blocked('workflow_unavailable');
        }
        if ($this->isSourceManagedUnderEnforcement($order)) {
            return $this->blocked(self::BLOCKED_AUTHORITY_SOURCE);
        }
        $owner = $order->productionOwnerEmployeeUuid;
        if ($owner !== null && $owner !== $employee->employeeUuid) {
            return $this->blocked('claimed_by_other');
        }
        if ($order->productionStageId === OrderProjectionWriter::INITIAL_STAGE_ID) {
            // Owned at stage 1 by this employee (a waiting-stage claim): the ordinary stage completion applies.
            return $owner === null ? ['action' => self::ACTION_CUTTING_INTAKE, 'blockedReason' => null] : $this->blocked('intake_not_applicable');
        }
        return ['action' => $owner === null ? self::ACTION_CLAIM : self::ACTION_ALREADY_MINE, 'blockedReason' => null];
    }

    /** @return array{action: null, blockedReason: string} */
    private function blocked(string $reason): array
    {
        return ['action' => null, 'blockedReason' => $reason];
    }
}
