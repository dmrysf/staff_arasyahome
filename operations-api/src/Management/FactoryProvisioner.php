<?php
declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\EmployeeRepository;
use Arasya\Operations\Iam\ApplicationAccess;
use Arasya\Operations\Iam\CredentialSink;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Production\PdoProductionWorkflowRepository;
use Arasya\Operations\Support\Clock;
use PDO;
use RuntimeException;
use Throwable;

/** Existing identities only. Exact, scoped factory profile; whole-group rollback and durable audit idempotency. */
final readonly class FactoryProvisioner
{
    private const STAFF_PERMISSIONS = ['staff.access', 'orders.scan', 'orders.view_mine', 'orders.claim', 'orders.advance_stage', 'orders.handover', 'history.view_mine', 'orders.report_fault', 'orders.acknowledge_fault', 'profile.view_self'];

    public function __construct(private PDO $pdo, private ManagementService $management, private EmployeeRepository $employees, private IamAuditLogger $audit, private Clock $clock, private FactoryOnboardingPlan $matrix) {}

    /** SELECT only; callers may wrap in a database-enforced READ ONLY transaction. */
    public function plan(?string $group = null): array
    {
        $wanted = $this->matrix->people($group);
        $root = $this->root();
        $this->management->me($root);
        $conflicts = [];
        if (ApplicationAccess::BASELINE_PERMISSIONS['staff'] !== self::STAFF_PERMISSIONS) $conflicts[] = 'STAFF_BASELINE_CHANGED';
        $workflow = (new PdoProductionWorkflowRepository($this->pdo))->current();
        if ($workflow === null || $workflow->id !== 'curtain-production' || $workflow->version !== 1) $conflicts[] = 'WORKFLOW_UNAVAILABLE';
        $sources = $this->pdo->query("SELECT source_key FROM order_sources WHERE status='active' ORDER BY source_key")->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff(FactoryOnboardingPlan::SOURCES, $sources) !== []) $conflicts[] = 'APPROVED_SOURCE_NOT_ACTIVE';
        if ($this->pdo->query("SELECT status FROM applications WHERE application_key='staff'")->fetchColumn() !== 'active') $conflicts[] = 'STAFF_APPLICATION_NOT_ACTIVE';
        // Name-token collision detection reads no password, session, or other person's grants.
        $byUsername = []; $byName = [];
        foreach ($this->pdo->query('SELECT employee_uuid,username_normalized,display_name FROM employees')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byUsername[$row['username_normalized']][] = $row;
            $byName[OrganizationRoster::nameKey($row['display_name'])][] = $row;
        }
        $people = [];
        foreach ($wanted as $person) {
            $matches = $byUsername[$person['username']] ?? [];
            $nameMatches = $byName[OrganizationRoster::nameKey($person['name'])] ?? [];
            if (count($matches) !== 1 || count($nameMatches) !== 1 || $matches[0]['employee_uuid'] !== $nameMatches[0]['employee_uuid']) {
                $conflicts[] = $person['username'] . ':IDENTITY_MISSING_OR_AMBIGUOUS';
                continue;
            }
            $id = $matches[0]['employee_uuid'];
            $identity = $this->employees->findByUuid($id);
            if ($identity === null || $identity->isRoot) { $conflicts[] = $person['username'] . ':IDENTITY_NOT_ELIGIBLE'; continue; }
            $view = $this->management->getEmployee($root, $id);
            $rawStages = $this->column('SELECT stage_id FROM employee_stage_access WHERE employee_uuid=? ORDER BY stage_id', $id);
            $scopes = (array) $view['stageSourceScopes'];
            $completed = $this->completed($id, $person['group']);
            $errors = [];
            if ($identity->departmentKey !== $person['departmentKey'] || $identity->departmentStatus !== 'active') $errors[] = 'DEPARTMENT_CONFLICT';
            if (array_diff($view['applications'], ['staff']) !== [] || $view['roles'] !== [] || $view['documentScopes'] !== ['operate' => [], 'approve' => []]) $errors[] = 'UNEXPECTED_ACCESS';
            if (array_diff($rawStages, $person['stageIds']) !== [] || array_diff(array_keys($scopes), $rawStages) !== []) $errors[] = 'UNEXPECTED_STAGE_OR_SCOPE';
            foreach ($rawStages as $stage) if ($this->sorted($scopes[$stage] ?? []) !== $this->sorted(FactoryOnboardingPlan::SOURCES)) $errors[] = 'UNSCOPED_OR_DIFFERENT_SOURCE_GRANT';
            $exact = $view['applications'] === ['staff'] && $this->sorted($rawStages) === $this->sorted($person['stageIds']) && $errors === [];
            if ($identity->status === 'active' && (!$completed || !$exact)) $errors[] = 'ACTIVE_IDENTITY_REQUIRES_REVIEW';
            if ($identity->status === 'inactive' && $completed) $errors[] = 'PREVIOUSLY_ONBOARDED_IDENTITY_DEACTIVATED';
            foreach (array_unique($errors) as $error) $conflicts[] = $person['username'] . ':' . $error;
            $before = ['id' => $id, 'username' => $identity->username, 'displayName' => $identity->displayName, 'departmentKey' => $identity->departmentKey, 'departmentId' => $identity->departmentId, 'departmentStatus' => $identity->departmentStatus, 'secondaryDepartments' => $view['secondaryDepartments'], 'manager' => $view['manager'], 'status' => $identity->status, 'applications' => $view['applications'], 'roles' => $view['roles'], 'stageIds' => $rawStages, 'stageSourceScopes' => $scopes, 'documentScopes' => $view['documentScopes'], 'effectivePermissions' => $identity->permissions, 'mustChangePassword' => $identity->mustChangePassword, 'authorizationVersion' => $identity->authorizationVersion, 'factoryOnboarded' => $completed];
            $people[] = ['id' => $id, 'username' => $person['username'], 'name' => $person['name'], 'group' => $person['group'], 'action' => $completed && $exact && $identity->status === 'active' ? 'unchanged' : 'activate', 'before' => $before, 'after' => ['status' => 'active', 'applications' => ['staff'], 'roles' => [], 'stageIds' => $person['stageIds'], 'stageSourceScopes' => $person['stageScopes'], 'documentScopes' => ['operate' => [], 'approve' => []], 'effectivePermissions' => self::STAFF_PERMISSIONS, 'mustChangePassword' => $completed ? $identity->mustChangePassword : true]];
        }
        $hashPayload = ['group' => $group, 'workflow' => $workflow?->toArray(), 'sources' => array_values(array_intersect($sources, FactoryOnboardingPlan::SOURCES)), 'people' => $people, 'conflicts' => $conflicts];
        return ['mode' => 'dry-run', 'group' => $group, 'planSha256' => hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'summary' => ['requested' => count($wanted), 'found' => count($people), 'activate' => count(array_filter($people, static fn($p) => $p['action'] === 'activate')), 'unchanged' => count(array_filter($people, static fn($p) => $p['action'] === 'unchanged')), 'conflicts' => count($conflicts), 'newIdentities' => 0, 'productionWrites' => 0], 'people' => $people, 'conflicts' => $conflicts];
    }

    /** Group approval is a separate required object; a dry-run cannot create credentials or invoke this method. */
    public function apply(FactoryApproval $approval, CredentialSink $credentials): array
    {
        $approval->requireCurrent($this->clock->now());
        if ($this->pdo->inTransaction()) throw new RuntimeException('FACTORY_APPLY_REQUIRES_OWN_TRANSACTION');
        if ((int) $this->pdo->query("SELECT GET_LOCK('arasya.factory-onboarding',0)")->fetchColumn() !== 1) throw new RuntimeException('FACTORY_RUN_ALREADY_ACTIVE');
        try {
            $initial = $this->plan($approval->group);
            if ($initial['conflicts'] !== []) throw new RuntimeException('FACTORY_PLAN_CONFLICT');
            if ($initial['summary']['activate'] === 0) return ['mode' => 'apply', 'group' => $approval->group, 'activated' => 0, 'unchanged' => count($initial['people']), 'credentials' => 0, 'credentialFile' => null];
            if (!hash_equals($initial['planSha256'], $approval->planSha256)) throw new RuntimeException('FACTORY_PLAN_CHANGED');
            $root = $this->root();
            $requestId = 'cli-factory-' . $approval->group . '-' . bin2hex(random_bytes(8));
            return $this->management->withLockedEmployees($root, array_column($initial['people'], 'id'), function () use ($approval, $credentials, $root, $requestId): array {
                $approval->requireCurrent($this->clock->now());
                $locked = $this->plan($approval->group);
                if ($locked['conflicts'] !== [] || !hash_equals($locked['planSha256'], $approval->planSha256)) throw new RuntimeException('FACTORY_PLAN_CHANGED');
                $activated = 0;
                foreach ($locked['people'] as $person) {
                    if ($person['action'] === 'unchanged') continue;
                    $id = $person['id']; $wanted = $person['after'];
                    if ($person['before']['applications'] !== ['staff']) $this->management->setApplications($root, $id, ['staff'], $requestId);
                    $held = $person['before']['stageIds'];
                    $added = array_values(array_diff($wanted['stageIds'], $held));
                    if ($added !== []) $this->management->setStages($root, $id, $wanted['stageIds'], $requestId, array_intersect_key($wanted['stageSourceScopes'], array_flip($added)));
                    $this->assertExact($root, $person);
                    $reset = $this->management->resetPassword($root, $id, $requestId);
                    try {
                        $credentials->write(['name' => $person['name'], 'username' => $person['username'], 'temporaryPassword' => $reset['temporaryPassword'], 'applications' => ['staff'], 'reason' => 'Activare fabrică — ' . $approval->group]);
                    } catch (Throwable) { throw new RuntimeException('FACTORY_CREDENTIAL_DELIVERY_FAILED'); }
                    unset($reset);
                    $this->management->setStatus($root, $id, 'active', $requestId);
                    $this->assertExact($root, $person);
                    $this->audit->record($root, 'employee.factory_onboarded', 'employee', $id, $person['name'] . ' (' . $person['username'] . ')', ['group' => $approval->group, 'planSha256' => $approval->planSha256, 'approvalReference' => $approval->reference, 'approvedBy' => $approval->approvedBy], $requestId, $this->clock->now()->format('Y-m-d H:i:s.u'));
                    $activated++;
                }
                return ['mode' => 'apply', 'group' => $approval->group, 'requestId' => $requestId, 'activated' => $activated, 'unchanged' => count($locked['people']) - $activated, 'credentials' => $credentials->count(), 'credentialFile' => $credentials->location()];
            });
        } finally { $this->pdo->query("SELECT RELEASE_LOCK('arasya.factory-onboarding')"); }
    }

    private function assertExact(EmployeeIdentity $root, array $person): void
    {
        $view = $this->management->getEmployee($root, $person['id']);
        if ($view['applications'] !== ['staff'] || $view['roles'] !== [] || $view['documentScopes'] !== ['operate' => [], 'approve' => []] || $this->sorted($view['stageIds']) !== $this->sorted($person['after']['stageIds'])) throw new RuntimeException('FACTORY_POSTCONDITION_FAILED');
        $scopes = (array) $view['stageSourceScopes'];
        if (count($scopes) !== count($view['stageIds'])) throw new RuntimeException('FACTORY_POSTCONDITION_FAILED');
        foreach ($view['stageIds'] as $stage) if ($this->sorted($scopes[$stage] ?? []) !== $this->sorted(FactoryOnboardingPlan::SOURCES)) throw new RuntimeException('FACTORY_POSTCONDITION_FAILED');
    }

    private function root(): EmployeeIdentity
    {
        $id = $this->pdo->query('SELECT employee_uuid FROM system_root_identity WHERE singleton_id=1')->fetchColumn();
        $root = is_string($id) ? $this->employees->findByUuid($id) : null;
        if ($root === null || !$root->isRoot || !$root->isOperationallyActive() || $root->mustChangePassword) throw new RuntimeException('FACTORY_ROOT_NOT_READY');
        return $root;
    }

    private function completed(string $id, string $group): bool
    {
        $query = $this->pdo->prepare("SELECT 1 FROM iam_audit_events WHERE target_id=? AND action='employee.factory_onboarded' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.group'))=? LIMIT 1");
        $query->execute([$id, $group]);
        return $query->fetchColumn() !== false;
    }

    private function column(string $sql, string $id): array { $query = $this->pdo->prepare($sql); $query->execute([$id]); return $query->fetchAll(PDO::FETCH_COLUMN); }
    private function sorted(array $values): array { sort($values, SORT_STRING); return $values; }
}
