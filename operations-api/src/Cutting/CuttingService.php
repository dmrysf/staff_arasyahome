<?php
declare(strict_types=1);
namespace Arasya\Operations\Cutting;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\EmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Production\ProductionWorkflowService;
use Arasya\Operations\Quality\ApproverPolicy;
use Arasya\Operations\Quality\IdempotencyStore;
use Arasya\Operations\Quality\LiveEvents;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;
use Throwable;

/** Cutting-only transfer intents on the existing canonical owner row. */
final readonly class CuttingService
{
    public const REASONS = ['illness' => 'Boală', 'unavailable' => 'Indisponibilitate / nu poate continua', 'other' => 'Alt motiv'];
    public function __construct(private PDO $pdo, private EmployeeRepository $employees, private ApproverPolicy $approvers,
        private ProductionWorkflowService $workflows, private IdempotencyStore $idem, private IamAuditLogger $audit, private LiveEvents $live, private Clock $clock) {}

    public function requireCutter(EmployeeIdentity $actor): void
    {
        if (!CuttingLifecycle::eligible($actor)) throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Acces permis doar angajaților eligibili pentru tăiere.');
    }

    public function pool(EmployeeIdentity $actor): array
    {
        $this->requireCutter($actor);
        $where = "o.production_stage_id = 'material-preparation' AND o.production_completed_at IS NULL AND o.operational_status <> 'unavailable' AND o.source_reported_unavailable_at IS NULL AND o.production_owner_employee_uuid IS NULL AND o.open_exception_uuid IS NULL AND o.document_status IN ('none', 'active') AND s.status = 'active'";
        // A source-scoped cutting grant (migration 023) sees and counts only its own sources.
        $sources = $actor->sourcesAt(CuttingLifecycle::STAGE) ?? [];
        $scoped = $actor->sourcesAt(CuttingLifecycle::STAGE) !== null;
        if ($scoped) $where .= ' AND o.source_key IN (' . ($sources === [] ? "''" : implode(',', array_fill(0, count($sources), '?'))) . ')';
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM operational_orders o JOIN order_sources s ON s.source_key = o.source_key WHERE {$where}");
        $count->execute($sources);
        $total = (int) $count->fetchColumn();
        $list = $this->pdo->prepare("SELECT o.order_uuid, o.global_order_id, o.order_number, o.source_key, o.production_version, o.production_changed_at FROM operational_orders o JOIN order_sources s ON s.source_key = o.source_key WHERE {$where} ORDER BY o.production_changed_at, o.global_order_id LIMIT 100");
        $list->execute($sources);
        $rows = $list->fetchAll(PDO::FETCH_ASSOC);
        return ['total' => $total, 'ownedCount' => (new CuttingLifecycle($this->pdo))->ownedCount($actor->employeeUuid), 'items' => array_map(static fn(array $row): array => [
            'id' => $row['global_order_id'], 'orderNumber' => $row['order_number'], 'source' => $row['source_key'], 'productionVersion' => (int) $row['production_version'],
        ], $rows), 'reasons' => self::REASONS];
    }

    public function candidates(EmployeeIdentity $actor): array
    {
        $this->requireCutter($actor);
        $s = $this->pdo->prepare("SELECT e.employee_uuid, e.display_name FROM employees e JOIN departments d ON d.department_id = e.department_id
          JOIN employee_stage_access st ON st.employee_uuid = e.employee_uuid AND st.stage_id = 'material-preparation'
          JOIN employee_application_access app ON app.employee_uuid = e.employee_uuid AND app.application_key = 'staff'
          WHERE e.department_id = ? AND e.status = 'active' AND d.status = 'active' AND e.employee_uuid <> ? ORDER BY e.display_name, e.employee_uuid LIMIT 200");
        $s->execute([$actor->departmentId, $actor->employeeUuid]);
        $items = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $candidate = $this->employees->findByUuid($row['employee_uuid']);
            if ($candidate !== null && CuttingLifecycle::eligible($candidate)) $items[] = ['id' => $candidate->employeeUuid, 'name' => $candidate->displayName];
        }
        return ['items' => $items];
    }

    public function list(EmployeeIdentity $actor, bool $management, string $view = 'pending'): array
    {
        if ($management) $this->approvers->require($actor);
        else (new AuthorizationService())->requireApplication($actor, 'staff');
        if ($management && !in_array($view, ['pending', 'waiting', 'mine'], true)) throw new ApiException(400, 'INVALID_REQUEST', 'Invalid queue view.');
        $where = $management ? match($view) { 'pending' => "t.status = 'pending'", 'waiting' => "t.status IN ('approved','accepted')", default => 't.decided_by_employee_uuid = ?' } : '(t.from_employee_uuid = ? OR t.to_employee_uuid = ?)';
        $s = $this->pdo->prepare($this->select() . " WHERE {$where} ORDER BY t.requested_at DESC, t.transfer_uuid LIMIT 100");
        $s->execute($management ? ($view === 'mine' ? [$actor->employeeUuid] : []) : [$actor->employeeUuid, $actor->employeeUuid]);
        return ['items' => array_map(fn(array $row): array => $this->present($row, $actor, $management), $s->fetchAll(PDO::FETCH_ASSOC))];
    }

    public function detail(EmployeeIdentity $actor, string $id, bool $management): array
    {
        if ($management) $this->approvers->require($actor);
        else (new AuthorizationService())->requireApplication($actor, 'staff');
        $s = $this->pdo->prepare($this->select() . ' WHERE t.transfer_uuid = ?');
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (!$management && !in_array($actor->employeeUuid, [$row['from_employee_uuid'], $row['to_employee_uuid']], true))) throw new ApiException(404, 'TRANSFER_NOT_FOUND', 'Cererea nu a fost găsită.');
        $result = $this->present($row, $actor, $management);
        $s = $this->pdo->prepare("SELECT action, actor_label, metadata_json, created_at FROM iam_audit_events WHERE target_type = 'cutting_transfer' AND target_id = ? ORDER BY created_at, event_id");
        $s->execute([$id]);
        $result['history'] = array_map(fn(array $r): array => ['action' => $r['action'], 'actor' => $r['actor_label'], 'at' => $this->iso($r['created_at']), 'facts' => json_decode($r['metadata_json'] ?? '{}', true)], $s->fetchAll(PDO::FETCH_ASSOC));
        return $result;
    }

    public function request(EmployeeIdentity $actor, string $globalId, array $input, string $key, string $requestId): array
    {
        $this->requireCutter($actor);
        $reason = $input['reasonKey'] ?? '';
        if (!is_string($reason) || !isset(self::REASONS[$reason])) throw new ApiException(422, 'INVALID_REASON', 'Alege motivul transferului.');
        $comment = $this->comment($input['comment'] ?? null, $reason === 'other');
        $targetId = $input['targetId'] ?? null;
        if (!is_string($targetId) || preg_match('/^[0-9a-f-]{36}$/D', $targetId) !== 1) throw new ApiException(400, 'INVALID_REQUEST', 'Invalid target.');
        $expected = $this->version($input);
        return $this->command($actor, 'transfer.request', $globalId, [$expected, $targetId, $reason, $comment], $key, function(EmployeeIdentity $actor, string $now) use ($globalId, $expected, $targetId, $reason, $comment, $requestId): array {
            $order = $this->lockOrder($globalId, false);
            $this->lockPeople([$actor->employeeUuid, $targetId]);
            $actor = $this->fresh($actor);
            $this->requireCutter($actor);
            $this->assertOrder($order, $actor->employeeUuid);
            if ((int) $order['production_version'] !== $expected) throw new ApiException(409, 'ORDER_CHANGED', 'Comanda s-a schimbat.');
            (new CuttingLifecycle($this->pdo))->requireNoTransfer($order['order_uuid']);
            $this->target($targetId, $actor, (string) $order['source_key']);
            $id = Uuid::v4();
            $this->pdo->prepare("INSERT INTO cutting_transfers (transfer_uuid, order_uuid, open_order_uuid, from_employee_uuid, to_employee_uuid, status, reason_key, reason_label, request_comment, requested_at) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)")
                ->execute([$id, $order['order_uuid'], $order['order_uuid'], $actor->employeeUuid, $targetId, $reason, self::REASONS[$reason], $comment, $now]);
            (new \Arasya\Operations\Analytics\ApprovalEligibility($this->pdo,$this->employees,$this->approvers))->capture('transfer',$id,$now,[$actor->employeeUuid,$targetId]);
            $this->bumpOrder($order, $now);
            $this->event($actor, $id, 'requested', ['orderId' => $globalId, 'targetId' => $targetId, 'reason' => $reason, 'comment' => $comment], $requestId, $now);
            $this->notify($id, $order, 'pending', $now);
            return $this->detail($actor, $id, false);
        });
    }

    public function change(EmployeeIdentity $actor, string $id, string $action, array $input, string $key, string $requestId): array
    {
        $management = in_array($action, ['decision', 'cancel'], true);
        if ($management) $this->approvers->require($actor);
        else (new AuthorizationService())->requireApplication($actor, 'staff');
        $expected = $this->version($input);
        $decision = $input['decision'] ?? null;
        if ($action === 'decision' && !in_array($decision, ['approve','reject'], true)) throw new ApiException(400, 'INVALID_REQUEST', 'Invalid decision.');
        $comment = $this->comment($input['comment'] ?? null, $action === 'cancel' || $decision === 'reject');
        if ($action === 'cancel' && !$actor->isRoot) throw new ApiException(403, 'ROOT_REQUIRED', 'Doar Root poate recupera o cerere blocată.');
        if ($action === 'accept' && ($input['confirmed'] ?? false) !== true) throw new ApiException(422, 'CONFIRMATION_REQUIRED', 'Confirmă explicit preluarea.');
        // Immutable order link is read before the transaction so it cannot establish a stale snapshot.
        $hint = $this->pdo->prepare('SELECT order_uuid FROM cutting_transfers WHERE transfer_uuid = ?');
        $hint->execute([$id]);
        $orderId = $hint->fetchColumn();
        if (!is_string($orderId)) throw new ApiException(404, 'TRANSFER_NOT_FOUND', 'Cererea nu a fost găsită.');
        return $this->command($actor, 'transfer.' . $action, $id, [$input, $comment], $key, function(EmployeeIdentity $actor, string $now) use ($id, $orderId, $action, $expected, $decision, $comment, $input, $management, $requestId, $key): array {
            $order = $this->lockOrder($orderId, true);
            $s = $this->pdo->prepare('SELECT * FROM cutting_transfers WHERE transfer_uuid = ? FOR UPDATE');
            $s->execute([$id]);
            $transfer = $s->fetch(PDO::FETCH_ASSOC);
            $this->lockPeople([$actor->employeeUuid, $transfer['from_employee_uuid'], $transfer['to_employee_uuid']]);
            $actor = $this->fresh($actor);
            if ($management) $this->approvers->require($actor);
            else (new AuthorizationService())->requireApplication($actor, 'staff');
            if ($action === 'cancel' && !$actor->isRoot) throw new ApiException(403, 'ROOT_REQUIRED', 'Root required.');
            if ((int) $transfer['version'] !== $expected) throw new ApiException(409, 'TRANSFER_CHANGED', 'Cererea s-a schimbat. Reîncarcă.');
            if (!in_array($transfer['status'], ['pending','approved','accepted'], true)) throw new ApiException(409, 'TRANSFER_ALREADY_RESOLVED', 'Cererea a fost deja soluționată.');
            if ($action !== 'cancel') $this->assertOrder($order, $transfer['from_employee_uuid']);
            if ($action === 'decision') {
                if ($transfer['status'] !== 'pending') throw new ApiException(409, 'TRANSFER_ALREADY_DECIDED', 'Alt manager a soluționat deja cererea.');
                if (in_array($actor->employeeUuid, [$transfer['from_employee_uuid'], $transfer['to_employee_uuid']], true)) throw new ApiException(403, 'SELF_DECISION_DENIED', 'Nu poți decide propria cerere.');
                if ($decision === 'approve') $this->target($transfer['to_employee_uuid'], $this->freshById($transfer['from_employee_uuid']), (string) $order['source_key']);
                $status = $decision === 'approve' ? 'approved' : 'rejected';
                $this->pdo->prepare('UPDATE cutting_transfers SET status = ?, open_order_uuid = ?, version = version + 1, decided_by_employee_uuid = ?, decided_via = ?, decision_comment = ?, decided_at = ?, resolved_at = ? WHERE transfer_uuid = ?')
                    ->execute([$status, $status === 'rejected' ? null : $order['order_uuid'], $actor->employeeUuid, $this->approvers->via($actor), $comment, $now, $status === 'rejected' ? $now : null, $id]);
            } elseif ($action === 'cancel') {
                $status = 'cancelled';
                $this->pdo->prepare("UPDATE cutting_transfers SET status = 'cancelled', open_order_uuid = NULL, version = version + 1, resolved_at = ? WHERE transfer_uuid = ?")->execute([$now, $id]);
            } else {
                if ($actor->employeeUuid !== $transfer['to_employee_uuid']) throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Doar destinatarul poate prelua transferul.');
                $this->target($actor->employeeUuid, $this->freshById($transfer['from_employee_uuid']), (string) $order['source_key']);
                if ($action === 'accept') {
                    if ($transfer['status'] !== 'approved') throw new ApiException(409, 'TRANSFER_STATE_INVALID', 'Transferul nu a fost aprobat.');
                    $status = 'accepted';
                    $this->pdo->prepare("UPDATE cutting_transfers SET status = 'accepted', version = version + 1, accepted_at = ? WHERE transfer_uuid = ?")->execute([$now, $id]);
                } else {
                    if ($transfer['status'] !== 'accepted') throw new ApiException(409, 'TRANSFER_STATE_INVALID', 'Acceptă transferul înainte de scanare.');
                    $life = new CuttingLifecycle($this->pdo);
                    $life->qr($order['order_uuid'], $input['qrToken'] ?? null);
                    $life->confirmMultiple($actor, $input, $requestId, $now);
                    $status = 'completed';
                    $this->pdo->prepare("UPDATE cutting_transfers SET status = 'completed', open_order_uuid = NULL, version = version + 1, qr_verified_at = ?, resolved_at = ? WHERE transfer_uuid = ?")->execute([$now, $now, $id]);
                    $this->pdo->prepare('UPDATE operational_orders SET production_owner_employee_uuid = ?, production_claimed_at = ? WHERE order_uuid = ?')->execute([$actor->employeeUuid, $now, $order['order_uuid']]);
                    $this->pdo->prepare("UPDATE employee_order_relations SET status = 'inactive', last_action_at = ?, updated_at = ? WHERE order_uuid = ? AND employee_uuid = ?")->execute([$now, $now, $order['order_uuid'], $transfer['from_employee_uuid']]);
                    $this->pdo->prepare("INSERT INTO employee_order_relations (employee_uuid, order_uuid, relation_type, status, started_at, last_action_at, updated_at) VALUES (?, ?, 'assigned', 'active', ?, ?, ?) ON DUPLICATE KEY UPDATE relation_type = 'assigned', status = 'active', last_action_at = VALUES(last_action_at), updated_at = VALUES(updated_at)")->execute([$actor->employeeUuid, $order['order_uuid'], $now, $now, $now]);
                    $this->ownershipActivity($actor, $order, $transfer, $key, $requestId, $now);
                    $life->fact($order['order_uuid'], (int) $order['production_version'] + 1, 'interval_ended', $transfer['from_employee_uuid'], $now);
                    $life->claimed($order['order_uuid'], (int) $order['production_version'] + 1, $actor->employeeUuid, $now);
                }
            }
            $this->bumpOrder($order, $now);
            $this->event($actor, $id, $status, ['orderId' => $order['global_order_id'], 'comment' => $comment, 'previousStatus' => $transfer['status'], 'ownerChanged' => $status === 'completed'], $requestId, $now);
            $this->notify($id, $order, $status, $now);
            return $this->detail($actor, $id, $management);
        });
    }

    private function command(EmployeeIdentity $actor, string $operation, string $target, array $intent, string $key, callable $write): array
    {
        IdempotencyStore::requireKey($key);
        $hash = IdempotencyStore::hash($operation, $target, $intent);
        for ($attempt = 0; ; $attempt++) {
            try {
                $this->pdo->beginTransaction();
                // One actor/key serializes even when the key is retried for a different target.
                $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 3)');
                $lockName = 'cutting-' . substr(hash('sha256', $actor->employeeUuid . $key), 0, 48);
                $lock->execute([$lockName]);
                if ((int) $lock->fetchColumn() !== 1) throw new ApiException(409, 'REQUEST_BUSY', 'Cererea este deja în curs.');
                try {
                    $replay = $this->idem->replay($actor->employeeUuid, $key, $operation, $target, $hash);
                    if ($replay !== null) { $this->pdo->commit(); return $replay; }
                    $result = $write($actor, $this->now());
                    $this->idem->store($actor->employeeUuid, $key, $operation, $target, $hash, $result, $this->now());
                    $this->pdo->commit();
                    return $result;
                } finally { $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                if ($error instanceof PDOException && $attempt < 2 && in_array((int) ($error->errorInfo[1] ?? 0), [1062,1205,1213], true)) continue;
                throw $error;
            }
        }
    }
    private function lockOrder(string $id, bool $uuid): array
    {
        $s = $this->pdo->prepare('SELECT * FROM operational_orders WHERE ' . ($uuid ? 'order_uuid' : 'global_order_id') . ' = ? FOR UPDATE');
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
        return $row;
    }
    private function lockPeople(array $ids): void
    {
        $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING);
        foreach ($ids as $id) $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$id]);
    }
    private function freshById(string $id): EmployeeIdentity
    {
        return $this->employees->findByUuid($id) ?? throw new ApiException(409, 'EMPLOYEE_UNAVAILABLE', 'Angajatul nu mai este disponibil.');
    }
    private function fresh(EmployeeIdentity $actor): EmployeeIdentity
    {
        $fresh = $this->freshById($actor->employeeUuid);
        if (!$fresh->isOperationallyActive() || $fresh->mustChangePassword) throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Accesul s-a schimbat.');
        return $fresh;
    }
    /** A transfer target must be an eligible cutter of the same department who may cut orders of this source. */
    private function target(string $id, EmployeeIdentity $from, string $sourceKey): EmployeeIdentity
    {
        $target = $this->freshById($id);
        if ($id === $from->employeeUuid || !CuttingLifecycle::eligible($target) || $target->departmentId !== $from->departmentId || !$target->worksAt(CuttingLifecycle::STAGE, $sourceKey)) throw new ApiException(422, 'INELIGIBLE_TRANSFER_TARGET', 'Alege un coleg activ din același departament de tăiere.');
        return $target;
    }
    private function assertOrder(array $order, string $owner): void
    {
        if ($order['production_stage_id'] !== CuttingLifecycle::STAGE || $order['production_owner_employee_uuid'] !== $owner || $order['production_completed_at'] !== null || $order['operational_status'] === 'unavailable' || $order['open_exception_uuid'] !== null) throw new ApiException(409, 'ORDER_CHANGED', 'Comanda nu mai poate fi transferată.');
        \Arasya\Operations\Document\DocumentGuard::assertOpen($order);
    }
    private function bumpOrder(array $order, string $now): void
    {
        $s = $this->pdo->prepare('UPDATE operational_orders SET production_version = production_version + 1, version = version + 1, updated_at = ? WHERE order_uuid = ? AND production_version = ?');
        $s->execute([$now, $order['order_uuid'], $order['production_version']]);
        if ($s->rowCount() !== 1) throw new ApiException(409, 'ORDER_CHANGED', 'Comanda s-a schimbat.');
        (new \Arasya\Operations\Analytics\AnalyticsCapture($this->pdo))->refreshOrder($order['order_uuid']);
    }
    private function ownershipActivity(EmployeeIdentity $actor, array $order, array $transfer, string $key, string $requestId, string $now): void
    {
        $workflow = $this->workflows->current();
        $stage = array_values(array_filter($workflow->stages, static fn($s): bool => $s->id === CuttingLifecycle::STAGE))[0];
        $s = $this->pdo->prepare('SELECT SUM(meters) FROM operational_order_items WHERE order_uuid = ?'); $s->execute([$order['order_uuid']]); $meters = $s->fetchColumn();
        $this->pdo->prepare("INSERT INTO order_activity_events (event_id, employee_uuid, order_uuid, global_order_id, source_key, order_number_snapshot, action, workflow_key, workflow_version, from_stage_id, from_stage_label_snapshot, production_version_before, production_version_after, meters_snapshot, request_id, idempotency_key, occurred_at, previous_owner_employee_uuid, new_owner_employee_uuid) VALUES (?, ?, ?, ?, ?, ?, 'owner_reassigned', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([Uuid::v4(), $actor->employeeUuid, $order['order_uuid'], $order['global_order_id'], $order['source_key'], $order['order_number'], $workflow->id, $workflow->version, $stage->id, $stage->label, $order['production_version'], (int) $order['production_version'] + 1, $meters, $requestId, $key, $now, $transfer['from_employee_uuid'], $actor->employeeUuid]);
    }
    private function event(EmployeeIdentity $actor, string $id, string $type, array $facts, string $requestId, string $now): void
    {
        $this->audit->record($actor, 'cutting.transfer.' . $type, 'cutting_transfer', $id, 'Transfer de tăiere', $facts, $requestId, $now);
    }
    private function notify(string $id, array $order, string $status, string $now): void
    {
        $s = $this->pdo->prepare('SELECT from_employee_uuid, to_employee_uuid FROM cutting_transfers WHERE transfer_uuid = ?'); $s->execute([$id]); $t = $s->fetch(PDO::FETCH_ASSOC);
        $payload = ['transferId' => $id, 'orderId' => $order['global_order_id'], 'orderNumber' => $order['order_number'], 'status' => $status];
        foreach ([$t['from_employee_uuid'], $t['to_employee_uuid']] as $employee) $this->live->toEmployee($employee, 'cutting.transfer.' . $status, $payload, $now);
        $this->live->toApprovers('cutting.transfer.' . $status, $payload, $now);
        $this->live->cuttingChanged($now);
    }
    private function select(): string
    {
        return 'SELECT t.*, o.global_order_id, o.order_number, o.source_key, o.production_version, f.display_name AS from_name, r.display_name AS to_name, d.display_name AS decided_name FROM cutting_transfers t JOIN operational_orders o ON o.order_uuid = t.order_uuid JOIN employees f ON f.employee_uuid = t.from_employee_uuid JOIN employees r ON r.employee_uuid = t.to_employee_uuid LEFT JOIN employees d ON d.employee_uuid = t.decided_by_employee_uuid';
    }
    private function present(array $row, EmployeeIdentity $actor, bool $management): array
    {
        $target = $actor->employeeUuid === $row['to_employee_uuid'];
        return ['id' => $row['transfer_uuid'], 'status' => $row['status'], 'version' => (int) $row['version'],
            'order' => ['id' => $row['global_order_id'], 'orderNumber' => $row['order_number'], 'source' => $row['source_key'], 'productionVersion' => (int) $row['production_version']],
            'from' => ['id' => $row['from_employee_uuid'], 'name' => $row['from_name']], 'to' => ['id' => $row['to_employee_uuid'], 'name' => $row['to_name']],
            'reason' => ['key' => $row['reason_key'], 'label' => $row['reason_label'], 'comment' => $row['request_comment']],
            'requestedAt' => $this->iso($row['requested_at']), 'decidedAt' => $this->iso($row['decided_at']), 'acceptedAt' => $this->iso($row['accepted_at']), 'qrVerifiedAt' => $this->iso($row['qr_verified_at']), 'resolvedAt' => $this->iso($row['resolved_at']),
            'decisionComment' => $row['decision_comment'], 'decidedBy' => $row['decided_name'],
            'actions' => ['canDecide' => $management && $row['status'] === 'pending' && !in_array($actor->employeeUuid, [$row['from_employee_uuid'], $row['to_employee_uuid']], true),
                'canCancel' => $actor->isRoot && in_array($row['status'], ['pending','approved','accepted'], true), 'canAccept' => !$management && $target && $row['status'] === 'approved', 'canVerify' => !$management && $target && $row['status'] === 'accepted']];
    }
    private function version(array $input): int
    {
        if (!is_int($input['expectedVersion'] ?? null) || $input['expectedVersion'] < 1) throw new ApiException(400, 'INVALID_REQUEST', 'Invalid expectedVersion.');
        return $input['expectedVersion'];
    }
    private function comment(mixed $value, bool $required): ?string
    {
        if ($value !== null && !is_string($value)) throw new ApiException(400, 'INVALID_REQUEST', 'Invalid comment.');
        $value = is_string($value) ? trim($value) : '';
        if (mb_strlen($value) > 1000 || ($required && mb_strlen($value) < 3)) throw new ApiException(422, 'COMMENT_REQUIRED', 'Scrie motivul (3–1000 caractere).');
        return $value === '' ? null : $value;
    }
    private function now(): string { return $this->clock->now()->format('Y-m-d H:i:s.u'); }
    private function iso(?string $value): ?string { return $value === null ? null : str_replace(' ', 'T', $value) . 'Z'; }
}
