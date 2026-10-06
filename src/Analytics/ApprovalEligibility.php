<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Quality\ApproverPolicy;
use PDO;
use LogicException;
final readonly class ApprovalEligibility
{
    public function __construct(private PDO $pdo,private PdoEmployeeRepository $employees,private ApproverPolicy $approvers) {}
    public function capture(string $type,string $id,string $now,array $excluded): void
    {
        if (!$this->pdo->inTransaction() || !in_array($type,['exception','transfer'],true)) throw new LogicException('Invalid eligibility capture context');
        $this->pdo->prepare('INSERT INTO analytics_approval_requests (request_type,request_uuid,opened_at) VALUES (?,?,?)')->execute([$type,$id,$now]);
        $ids=$this->pdo->query("SELECT e.employee_uuid FROM employees e JOIN employee_application_access a ON a.employee_uuid=e.employee_uuid AND a.application_key='dashboard' WHERE e.status='active'")->fetchAll(PDO::FETCH_COLUMN);
        $insert=$this->pdo->prepare('INSERT INTO analytics_approval_eligibility (request_type,request_uuid,employee_uuid,eligible_via,eligible_at) VALUES (?,?,?,?,?)');
        foreach ($ids as $employeeId) {
            if (in_array($employeeId,$excluded,true)) continue;
            $employee=$this->employees->findByUuid($employeeId);
            $via=$employee===null?null:$this->approvers->via($employee);
            if ($via!==null) $insert->execute([$type,$id,$employeeId,$via,$now]);
        }
    }
}
