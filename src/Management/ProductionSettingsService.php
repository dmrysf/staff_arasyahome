<?php

declare(strict_types=1);

namespace Arasya\Operations\Management;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Quality\IdempotencyStore;
use Arasya\Operations\Support\Clock;
use PDO;
use Throwable;

/**
 * Root-only production system policy: the exception approval mode (read-only in V1, strict
 * blocking), the cutting fault reason catalog and the display labels of canonical stages.
 *
 * Stage labels are presentation only. Stage ids, ordinals, order state and history never change;
 * activity rows keep their label snapshots. A label change alters the workflow content ETag, so Staff
 * picks it up on its next revalidation. Every change is idempotent and audited with before/after.
 */
final readonly class ProductionSettingsService
{
    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private IdempotencyStore $idempotency,
        private IamAuditLogger $audit,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(EmployeeIdentity $actor): array
    {
        $this->requireRoot($actor);
        $policy = $this->pdo->query('SELECT approval_mode, updated_at FROM production_exception_policy WHERE singleton_id = 1')->fetch(PDO::FETCH_ASSOC);
        return [
            'policy' => ['approvalMode' => is_array($policy) ? (string) $policy['approval_mode'] : 'blocking', 'availableModes' => ['blocking']],
            'reasons' => array_map(static fn (array $row): array => [
                'key' => (string) $row['reason_key'],
                'label' => (string) $row['label'],
                'requiresComment' => (int) $row['requires_comment'] === 1,
                'status' => (string) $row['status'],
                'sortOrder' => (int) $row['sort_order'],
            ], $this->pdo->query('SELECT reason_key, label, requires_comment, status, sort_order FROM production_fault_reasons ORDER BY sort_order, reason_key')->fetchAll(PDO::FETCH_ASSOC)),
            'stages' => array_map(static fn (array $row): array => ['id' => (string) $row['stage_id'], 'ordinal' => (int) $row['ordinal'], 'label' => (string) $row['display_name']],
                $this->pdo->query("SELECT ps.stage_id, ps.ordinal, ps.display_name FROM production_stages ps INNER JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id AND pw.status = 'active' WHERE ps.status = 'active' ORDER BY ps.ordinal")->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createReason(EmployeeIdentity $actor, array $input, string $key, string $requestId): array
    {
        $this->requireRoot($actor);
        IdempotencyStore::requireKey($key);
        $reasonKey = $input['key'] ?? null;
        if (!is_string($reasonKey) || preg_match('/^[a-z][a-z0-9-]{1,59}$/D', $reasonKey) !== 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'key must use lowercase letters, digits and hyphens.');
        }
        $label = $this->label($input['label'] ?? null);
        $requiresComment = $input['requiresComment'] ?? false;
        $sortOrder = $input['sortOrder'] ?? 100;
        if (!is_bool($requiresComment) || !is_int($sortOrder) || $sortOrder < 0 || $sortOrder > 9999) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Reason fields are invalid.');
        }
        $hash = IdempotencyStore::hash('settings.reason.create', $reasonKey, [$label, $requiresComment, $sortOrder]);
        return $this->transaction(function () use ($actor, $reasonKey, $label, $requiresComment, $sortOrder, $key, $hash, $requestId): array {
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'settings.reason.create', $reasonKey, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $exists = $this->pdo->prepare('SELECT 1 FROM production_fault_reasons WHERE reason_key = ? FOR UPDATE');
            $exists->execute([$reasonKey]);
            if ($exists->fetchColumn() !== false) {
                throw new ApiException(409, 'REASON_EXISTS', 'A reason with this key already exists.');
            }
            $now = $this->now();
            $this->pdo->prepare("INSERT INTO production_fault_reasons (reason_key, label, requires_comment, status, sort_order, created_at, updated_at) VALUES (?, ?, ?, 'active', ?, ?, ?)")
                ->execute([$reasonKey, $label, $requiresComment ? 1 : 0, $sortOrder, $now, $now]);
            $this->audit->record($actor, 'production.fault_reason.created', 'fault_reason', $reasonKey, $label, ['label' => $label, 'requiresComment' => $requiresComment, 'sortOrder' => $sortOrder], $requestId, $now);
            $response = $this->view($actor);
            $this->idempotency->store($actor->employeeUuid, $key, 'settings.reason.create', $reasonKey, $hash, $response, $now);
            return $response;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function updateReason(EmployeeIdentity $actor, string $reasonKey, array $input, string $key, string $requestId): array
    {
        $this->requireRoot($actor);
        IdempotencyStore::requireKey($key);
        if ($input === []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Nothing to update.');
        }
        $changes = [];
        if (array_key_exists('label', $input)) {
            $changes['label'] = $this->label($input['label']);
        }
        if (array_key_exists('requiresComment', $input)) {
            $changes['requires_comment'] = is_bool($input['requiresComment']) ? ($input['requiresComment'] ? 1 : 0) : throw new ApiException(400, 'INVALID_REQUEST', 'requiresComment must be true or false.');
        }
        if (array_key_exists('status', $input)) {
            $changes['status'] = in_array($input['status'], ['active', 'inactive'], true) ? $input['status'] : throw new ApiException(400, 'INVALID_REQUEST', 'status must be active or inactive.');
        }
        if (array_key_exists('sortOrder', $input)) {
            $changes['sort_order'] = is_int($input['sortOrder']) && $input['sortOrder'] >= 0 && $input['sortOrder'] <= 9999 ? $input['sortOrder'] : throw new ApiException(400, 'INVALID_REQUEST', 'sortOrder is invalid.');
        }
        if ($reasonKey === 'other' && ($changes['requires_comment'] ?? 1) === 0) {
            throw new ApiException(422, 'COMMENT_REQUIRED_FOR_OTHER', 'The "Alt motiv" reason always requires a comment.');
        }
        $hash = IdempotencyStore::hash('settings.reason.update', $reasonKey, $changes);
        return $this->transaction(function () use ($actor, $reasonKey, $changes, $key, $hash, $requestId): array {
            $statement = $this->pdo->prepare('SELECT reason_key, label, requires_comment, status, sort_order FROM production_fault_reasons WHERE reason_key = ? FOR UPDATE');
            $statement->execute([$reasonKey]);
            $before = $statement->fetch(PDO::FETCH_ASSOC) ?: throw new ApiException(404, 'REASON_NOT_FOUND', 'The reason was not found.');
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'settings.reason.update', $reasonKey, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $now = $this->now();
            $set = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($changes)));
            $this->pdo->prepare("UPDATE production_fault_reasons SET {$set}, updated_at = ? WHERE reason_key = ?")->execute([...array_values($changes), $now, $reasonKey]);
            $metadata = [];
            foreach ($changes as $column => $value) {
                $metadata[$column] = ['before' => $before[$column], 'after' => $value];
            }
            $this->audit->record($actor, 'production.fault_reason.updated', 'fault_reason', $reasonKey, (string) $before['label'], $metadata, $requestId, $now);
            $response = $this->view($actor);
            $this->idempotency->store($actor->employeeUuid, $key, 'settings.reason.update', $reasonKey, $hash, $response, $now);
            return $response;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function renameStage(EmployeeIdentity $actor, string $stageId, array $input, string $key, string $requestId): array
    {
        $this->requireRoot($actor);
        IdempotencyStore::requireKey($key);
        if (preg_match('/^[a-z][a-z0-9-]{1,99}$/D', $stageId) !== 1) {
            throw new ApiException(404, 'STAGE_NOT_FOUND', 'The stage was not found.');
        }
        $label = $this->label($input['label'] ?? null);
        $hash = IdempotencyStore::hash('settings.stage.label', $stageId, [$label]);
        return $this->transaction(function () use ($actor, $stageId, $label, $key, $hash, $requestId): array {
            $statement = $this->pdo->prepare("SELECT ps.stage_id, ps.display_name FROM production_stages ps INNER JOIN production_workflows pw ON pw.workflow_id = ps.workflow_id AND pw.status = 'active' WHERE ps.stage_id = ? AND ps.status = 'active' FOR UPDATE");
            $statement->execute([$stageId]);
            $before = $statement->fetch(PDO::FETCH_ASSOC) ?: throw new ApiException(404, 'STAGE_NOT_FOUND', 'The stage was not found.');
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'settings.stage.label', $stageId, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $now = $this->now();
            $this->pdo->prepare('UPDATE production_stages SET display_name = ?, updated_at = ? WHERE stage_id = ?')->execute([$label, $now, $stageId]);
            $this->audit->record($actor, 'production.stage_label_changed', 'production_stage', $stageId, $label, ['label' => ['before' => (string) $before['display_name'], 'after' => $label]], $requestId, $now);
            $response = $this->view($actor);
            $this->idempotency->store($actor->employeeUuid, $key, 'settings.stage.label', $stageId, $hash, $response, $now);
            return $response;
        });
    }

    private function requireRoot(EmployeeIdentity $actor): void
    {
        $this->authorization->requireApplication($actor, 'dashboard');
        if (!$actor->isRoot) {
            throw new ApiException(403, 'ROOT_ONLY', 'Only the principal administrator can change production policy.');
        }
    }

    private function label(mixed $value): string
    {
        $text = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
        if (mb_strlen($text) < 2 || mb_strlen($text) > 160) {
            throw new ApiException(400, 'INVALID_REQUEST', 'The label must have 2 to 160 characters.');
        }
        return $text;
    }

    /** @template T @param callable(): T $operation @return T */
    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
