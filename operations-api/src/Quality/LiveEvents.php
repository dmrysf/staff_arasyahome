<?php

declare(strict_types=1);

namespace Arasya\Operations\Quality;

use PDO;

/**
 * Live notification outbox. Writers append inside the business transaction, so a notification exists
 * exactly when the change committed. Payloads carry identifiers and states, never details: readers
 * re-fetch through endpoints that apply their own authorization.
 */
final readonly class LiveEvents
{
    public const AUDIENCE_EMPLOYEE = 'employee';
    public const AUDIENCE_APPROVERS = 'approvers';
    private const BATCH = 100;

    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string, mixed> $payload */
    public function toEmployee(string $employeeUuid, string $type, array $payload, string $now): void
    {
        $this->insert(self::AUDIENCE_EMPLOYEE, $employeeUuid, $type, $payload, $now);
    }

    /** @param array<string, mixed> $payload */
    public function toApprovers(string $type, array $payload, string $now): void
    {
        $this->insert(self::AUDIENCE_APPROVERS, null, $type, $payload, $now);
    }

    /**
     * Group audiences without a single recipient (document approvers or requesters). The order's source
     * is stored beside the event so the stream delivers it only to readers scoped to that source.
     *
     * @param array<string, mixed> $payload
     */
    public function toAudience(string $audience, string $type, array $payload, string $now, string $scopeSource): void
    {
        $this->insert($audience, null, $type, $payload, $now, $scopeSource);
    }

    public function latestSequence(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(event_seq), 0) FROM live_events')->fetchColumn();
    }

    /** Generic sanitized invalidation, never customer, IAM or exception detail. */
    public function cuttingChanged(string $now): void
    {
        $this->insert('cutting', null, 'cutting.changed', [], $now);
        $this->insert('display', null, 'cutting.changed', [], $now);
    }

    public function displayAfter(int $after): array
    {
        $statement = $this->pdo->prepare("SELECT event_seq, event_type FROM live_events WHERE audience = 'display' AND event_seq > ? ORDER BY event_seq LIMIT " . self::BATCH);
        $statement->execute([$after]);
        return array_map(static fn(array $row): array => ['seq' => (int) $row['event_seq'], 'type' => 'cutting.changed', 'payload' => []], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, list<string>|null> $audiences additional group audiences the caller is currently
     *        authorized for, each with the order sources it reaches (null: every source)
     * @return list<array{seq: int, type: string, payload: array<string, mixed>}>
     */
    public function after(string $employeeUuid, bool $approver, int $after, bool $cutter = false, array $audiences = []): array
    {
        $parameters = [$after, $employeeUuid];
        $groups = '';
        foreach (['document_approvers', 'document_requesters'] as $group) {
            if (!array_key_exists($group, $audiences)) {
                continue;
            }
            $sources = $audiences[$group];
            if ($sources === []) {
                continue;
            }
            $groups .= " OR (audience = '{$group}'" . ($sources === null ? '' : ' AND scope_source_key IN (' . implode(', ', array_fill(0, count($sources), '?')) . ')') . ')';
            if ($sources !== null) {
                array_push($parameters, ...array_values($sources));
            }
        }
        $statement = $this->pdo->prepare(
            'SELECT event_seq, event_type, payload_json FROM live_events
             WHERE event_seq > ? AND (recipient_employee_uuid = ?' . ($approver ? " OR audience = 'approvers'" : '') . ($cutter ? " OR audience = 'cutting'" : '')
             . $groups . ')
             ORDER BY event_seq LIMIT ' . self::BATCH,
        );
        $statement->execute($parameters);
        $events = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = json_decode((string) $row['payload_json'], true);
            $events[] = ['seq' => (int) $row['event_seq'], 'type' => (string) $row['event_type'], 'payload' => is_array($payload) ? $payload : []];
        }
        return $events;
    }

    /** @param array<string, mixed> $payload */
    private function insert(string $audience, ?string $recipient, string $type, array $payload, string $now, ?string $scopeSource = null): void
    {
        $this->pdo->prepare('INSERT INTO live_events (audience, recipient_employee_uuid, scope_source_key, event_type, payload_json, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$audience, $recipient, $scopeSource, $type, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now]);
    }
}
