<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\Employee\EmployeeIdentity;
use PDO;

/**
 * Which order sources a production document permission reaches (employee_document_scopes, migration 021).
 *
 * A document permission alone never reaches an order. A non-root identity also needs an explicit scope
 * row for the order's source, granted by root:
 *
 * - operate: generate, reprint, request a revision and read the documents of that source;
 * - approve: decide revision requests of that source (as primary approver or temporary backup).
 *
 * Reading needs either scope. Without a row nothing of that source is visible or decidable (default
 * deny). Root is above the mechanism: every source, always audited. Scopes are read from the database
 * on every call; inside a command transaction they are read with a lock after the actor row is locked,
 * so a concurrent scope change (which locks the same actor row first) is either fully before or fully
 * after the command.
 *
 * The source is the only dimension the platform stores reliably for every order (the signed source
 * identity or the internal B2B handoff). It identifies the channel an order came through, not the
 * department that sold it, which is why scopes are granted per person and never derived from a
 * department, a title or a role name.
 */
final readonly class DocumentScopePolicy
{
    public const OPERATE = 'operate';
    public const APPROVE = 'approve';
    public const CAPABILITIES = [self::OPERATE, self::APPROVE];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * The sources the actor's capability reaches, or null for every source (root).
     *
     * @return list<string>|null
     */
    public function sources(EmployeeIdentity $actor, string $capability, bool $lock = false): ?array
    {
        if ($actor->isRoot) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT source_key FROM employee_document_scopes WHERE employee_uuid = ? AND capability = ? ORDER BY source_key' . ($lock ? ' FOR UPDATE' : ''),
        );
        $statement->execute([$actor->employeeUuid, $capability]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Sources whose documents the actor may read: the union of both capabilities, or null for root.
     *
     * @return list<string>|null
     */
    public function visibleSources(EmployeeIdentity $actor): ?array
    {
        if ($actor->isRoot) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT DISTINCT source_key FROM employee_document_scopes WHERE employee_uuid = ? ORDER BY source_key');
        $statement->execute([$actor->employeeUuid]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function allows(EmployeeIdentity $actor, string $capability, string $sourceKey, bool $lock = false): bool
    {
        $sources = $this->sources($actor, $capability, $lock);
        return $sources === null || in_array($sourceKey, $sources, true);
    }

    public function sees(EmployeeIdentity $actor, string $sourceKey): bool
    {
        $sources = $this->visibleSources($actor);
        return $sources === null || in_array($sourceKey, $sources, true);
    }

    /**
     * Current scopes of one identity (management views and audit snapshots).
     *
     * @return array{operate: list<string>, approve: list<string>}
     */
    public function scopesOf(string $employeeUuid): array
    {
        $statement = $this->pdo->prepare('SELECT capability, source_key FROM employee_document_scopes WHERE employee_uuid = ? ORDER BY capability, source_key');
        $statement->execute([$employeeUuid]);
        $scopes = [self::OPERATE => [], self::APPROVE => []];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $scopes[(string) $row['capability']][] = (string) $row['source_key'];
        }
        return $scopes;
    }

    /**
     * SQL condition restricting `$column` to the given sources: no condition for null (every source),
     * a condition that matches nothing for an empty scope.
     *
     * @param list<string>|null $sources
     * @param list<mixed> $parameters positional parameters, appended in place
     */
    public static function condition(?array $sources, string $column, array &$parameters): string
    {
        if ($sources === null) {
            return '1 = 1';
        }
        if ($sources === []) {
            return '1 = 0';
        }
        array_push($parameters, ...$sources);
        return $column . ' IN (' . implode(', ', array_fill(0, count($sources), '?')) . ')';
    }
}
