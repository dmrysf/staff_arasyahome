<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use PDO;

/**
 * Who may read the management Production Intelligence (owner K4): only the Root identity and the canonical CEO
 * principal, through the Dashboard. No permission key, role or template grants it, `analytics.view` included, so an
 * IAM change can never widen it by accident.
 */
final readonly class ProductionIntelligencePolicy
{
    public function __construct(private PDO $pdo)
    {
    }

    public function canView(EmployeeIdentity $actor): bool
    {
        if (!$actor->isOperationallyActive() || $actor->mustChangePassword || !$actor->hasApplication('dashboard')) {
            return false;
        }
        if ($actor->isRoot) {
            return true;
        }
        $s = $this->pdo->prepare("SELECT 1 FROM organization_principals WHERE principal_key = 'ceo' AND employee_uuid = ?");
        $s->execute([$actor->employeeUuid]);
        return $s->fetchColumn() !== false;
    }

    public function require(EmployeeIdentity $actor): void
    {
        if (!$this->canView($actor)) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Permission denied.');
        }
    }
}
