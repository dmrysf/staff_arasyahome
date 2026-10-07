<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Production\ProductionAuthority;
use Arasya\Operations\Production\ProductionAuthorityMode;
use Arasya\Operations\Production\ProductionAuthorityModes;
use Arasya\Operations\Production\ProductionWorkflow;

/**
 * Server-side operational authority for one employee and one order.
 *
 * Visibility: an order is visible when the employee has a direct relation
 * with it, or when its current production stage is one of the employee's
 * allowed stages. Everything else is reported as not found.
 *
 * Production authority: while the order's source enforces the authority split, an order that is still
 * managed by its source (production_authority = 'source') cannot be worked on in Staff at all; a manager
 * must take it over explicitly first. In legacy and observe modes nothing changes here.
 */
final readonly class OrderAccessPolicy
{
    public const ACTION_CLAIM = 'claim';
    public const ACTION_COMPLETE_STAGE = 'complete_stage';
    public const ACTION_COMPLETE_PRODUCTION = 'complete_production';
    public const BLOCKED_AUTHORITY_SOURCE = 'production_authority_source';

    public function __construct(private AuthorizationService $authorization, private ?ProductionAuthorityModes $authorityModes = null)
    {
    }

    /** Whether Arasya must not act on this order because its source still owns production. */
    public function isSourceManagedUnderEnforcement(OperationalOrder $order): bool
    {
        return $order->productionAuthority === ProductionAuthority::SOURCE
            && $this->authorityModes?->modeFor($order->globalId->sourceKey) === ProductionAuthorityMode::Enforce;
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
        return $order->relation !== null && $order->relation->employeeUuid === $employee->employeeUuid
            || in_array($order->productionStageId, $employee->allowedStageIds, true);
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
        if ($workflow === null) {
            return $this->blocked('workflow_unavailable');
        }
        if ($this->isSourceManagedUnderEnforcement($order)) {
            return $this->blocked(self::BLOCKED_AUTHORITY_SOURCE);
        }
        $stageAllowed = in_array($order->productionStageId, $employee->allowedStageIds, true);
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

    /** @return array{action: null, blockedReason: string} */
    private function blocked(string $reason): array
    {
        return ['action' => null, 'blockedReason' => $reason];
    }
}
