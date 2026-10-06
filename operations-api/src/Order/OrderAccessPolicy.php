<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Production\ProductionWorkflow;

/**
 * Server-side operational authority for one employee and one order.
 *
 * Visibility: an order is visible when the employee has a direct relation
 * with it, or when its current production stage is one of the employee's
 * allowed stages. Everything else is reported as not found.
 */
final readonly class OrderAccessPolicy
{
    public const ACTION_CLAIM = 'claim';
    public const ACTION_COMPLETE_STAGE = 'complete_stage';
    public const ACTION_COMPLETE_PRODUCTION = 'complete_production';

    public function __construct(private AuthorizationService $authorization)
    {
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
        if ($workflow === null) {
            return $this->blocked('workflow_unavailable');
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
