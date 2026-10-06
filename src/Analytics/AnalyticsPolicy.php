<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use PDO;
final readonly class AnalyticsPolicy
{
    public function __construct(private PDO $pdo) {}
    public function canView(EmployeeIdentity $actor): bool
    {
        if (!$actor->isOperationallyActive() || $actor->mustChangePassword || !$actor->hasApplication('dashboard')) return false;
        if ($actor->isRoot) return true;
        $s=$this->pdo->prepare("SELECT 1 FROM organization_principals WHERE principal_key='ceo' AND employee_uuid=?");
        $s->execute([$actor->employeeUuid]);
        if ($s->fetchColumn()!==false) return true;
        // Operational approvers stay narrow even if a broad/custom role accidentally includes analytics.
        if ($actor->roleKey==='operations-manager' || in_array('operations-manager',$actor->roleKeys,true)) return false;
        return in_array('analytics.view',$actor->permissions,true);
    }
    public function require(EmployeeIdentity $actor): void
    {
        if (!$this->canView($actor)) throw new ApiException(403,'UNAUTHORIZED_ACTION','Permission denied.');
    }
}
