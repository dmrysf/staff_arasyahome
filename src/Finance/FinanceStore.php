<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\OrderOperationsService;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;
use Throwable;

/**
 * Shared Finance write plumbing: one transaction per mutation with a per-actor Idempotency-Key replay, permission
 * re-check inside the transaction, immutable activity events and evidence references.
 */
final readonly class FinanceStore
{
    public function __construct(private PDO $pdo, private AuthorizationService $authorization, private Clock $clock)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function authorization(): AuthorizationService
    {
        return $this->authorization;
    }

    public function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    public function today(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('Y-m-d');
    }

    /**
     * Runs $work inside one transaction. A repeated Idempotency-Key with the same operation and body replays the stored
     * result; with a different request it is refused. Permissions are re-read inside the transaction.
     *
     * @param list<string> $permissions
     * @param callable(string $now): array<string, mixed> $work returns the result with a 'status' key
     * @return array<string, mixed>
     */
    public function mutate(EmployeeIdentity $actor, string $operation, array $body, string $key, array $permissions, callable $work): array
    {
        if (!OrderOperationsService::isValidIdempotencyKey($key)) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key is required.');
        }
        $hash = hash('sha256', $operation . '|' . self::json(self::canonical($body)), true);
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->pdo->beginTransaction();
                FinanceAccess::require($this->authorization, $actor, ...$permissions);
                $s = $this->pdo->prepare('SELECT operation, request_hash, response_status, response_json FROM finance_idempotency WHERE employee_uuid = ? AND idempotency_key = ? FOR UPDATE');
                $s->execute([$actor->employeeUuid, $key]);
                $replay = $s->fetch(PDO::FETCH_ASSOC);
                if ($replay) {
                    if ($replay['operation'] !== $operation || !hash_equals((string) $replay['request_hash'], $hash)) {
                        throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
                    }
                    $this->pdo->rollBack();
                    $result = json_decode((string) $replay['response_json'], true, 64, JSON_THROW_ON_ERROR);
                    $result['status'] = (int) $replay['response_status'];
                    $result['replayed'] = true;
                    return $result;
                }
                $now = $this->now();
                $result = $work($now);
                $status = (int) ($result['status'] ?? 200);
                $stored = $result;
                unset($stored['status']);
                $this->pdo->prepare('INSERT INTO finance_idempotency (employee_uuid, idempotency_key, operation, request_hash, response_status, response_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$actor->employeeUuid, $key, $operation, $hash, $status, self::json($stored), $now]);
                $this->pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($e instanceof PDOException && $attempt < 3 && in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /** @param array<string, mixed>|null $details codes and field names only, never values */
    public function event(string $entityType, string $entityUuid, ?string $companyUuid, EmployeeIdentity $actor, string $action, ?int $before, ?int $after, ?string $reason, ?array $details, string $requestId, ?string $key, string $now): void
    {
        $this->pdo->prepare('INSERT INTO finance_activity_events (event_id, entity_type, entity_uuid, company_uuid, actor_employee_uuid, action, version_before, version_after, reason, details_json, request_id, idempotency_key, occurred_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([Uuid::v4(), $entityType, $entityUuid, $companyUuid, $actor->employeeUuid, $action, $before, $after, $reason, $details === null ? null : self::json($details), substr($requestId, 0, 100), $key, $now]);
    }

    /** @param list<array<string, mixed>> $evidence validated by FinanceInput::evidence @return list<string> */
    public function evidence(string $entityType, string $entityUuid, array $evidence, EmployeeIdentity $actor, string $now): array
    {
        $ids = [];
        $insert = $this->pdo->prepare('INSERT INTO finance_evidence_refs (evidence_uuid, entity_type, entity_uuid, kind, source_system, reference, document_date, amount, currency_code, note, created_by_employee_uuid, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($evidence as $item) {
            $id = Uuid::v4();
            $insert->execute([$id, $entityType, $entityUuid, $item['kind'], $item['sourceSystem'], $item['reference'], $item['documentDate'], $item['amount'], $item['currency'], $item['note'], $actor->employeeUuid, $now]);
            $ids[] = $id;
        }
        return $ids;
    }

    /** @return list<array<string, mixed>> */
    public function evidenceOf(string $entityType, string $entityUuid): array
    {
        $s = $this->pdo->prepare('SELECT e.evidence_uuid, e.kind, e.source_system, e.reference, e.document_date, e.amount, e.currency_code, e.note, e.created_at, emp.display_name
            FROM finance_evidence_refs e JOIN employees emp ON emp.employee_uuid = e.created_by_employee_uuid
            WHERE e.entity_type = ? AND e.entity_uuid = ? ORDER BY e.created_at, e.evidence_uuid LIMIT 200');
        $s->execute([$entityType, $entityUuid]);
        return array_map(static fn (array $r): array => [
            'id' => $r['evidence_uuid'], 'kind' => $r['kind'], 'sourceSystem' => $r['source_system'], 'reference' => $r['reference'],
            'documentDate' => $r['document_date'], 'amount' => $r['amount'], 'currency' => $r['currency_code'], 'note' => $r['note'],
            'createdAt' => self::iso($r['created_at']), 'createdBy' => $r['display_name'],
        ], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function history(string $entityType, string $entityUuid, int $limit = 200): array
    {
        $s = $this->pdo->prepare('SELECT a.action, a.version_before, a.version_after, a.reason, a.details_json, a.occurred_at, e.display_name
            FROM finance_activity_events a JOIN employees e ON e.employee_uuid = a.actor_employee_uuid
            WHERE a.entity_type = ? AND a.entity_uuid = ? ORDER BY a.occurred_at DESC, a.event_id DESC LIMIT ' . max(1, min(500, $limit)));
        $s->execute([$entityType, $entityUuid]);
        return array_map(static fn (array $r): array => [
            'action' => $r['action'], 'versionBefore' => $r['version_before'] === null ? null : (int) $r['version_before'],
            'versionAfter' => $r['version_after'] === null ? null : (int) $r['version_after'], 'reason' => $r['reason'],
            'details' => $r['details_json'] === null ? null : json_decode((string) $r['details_json'], true),
            'at' => self::iso($r['occurred_at']), 'actor' => $r['display_name'],
        ], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Locks one row FOR UPDATE; 404 when missing. @return array<string, mixed> */
    public function lock(string $table, string $keyColumn, string $id, string $code, string $message): array
    {
        if (!FinanceInput::isUuid($id)) {
            throw new ApiException(404, $code, $message);
        }
        $s = $this->pdo->prepare("SELECT * FROM {$table} WHERE {$keyColumn} = ? FOR UPDATE");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ApiException(404, $code, $message);
        }
        return $row;
    }

    public static function expectVersion(array $row, int $expected, string $code = 'VERSION_CONFLICT'): void
    {
        if ((int) $row['version'] !== $expected) {
            throw new ApiException(409, $code, 'The record changed. Reload it before continuing.', ['currentVersion' => (int) $row['version']]);
        }
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function iso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::canonical(...), $value);
    }
}
