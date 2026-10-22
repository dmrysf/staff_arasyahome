<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Employee\EmployeeIdentity;
use PDO;

/**
 * Which order sources an identity confirms (employee_confirmare_scopes, migration 036).
 *
 * - operate:   receives Confirmare orders of the source (automatic distribution or "next"), claims and releases them
 *              and decides the orders assigned to them;
 * - supervise: sees the whole pool of the source, reassigns and releases any order, decides any order and reopens a
 *              rejected one.
 *
 * Without a row nothing of the source is visible or decidable (default deny). Root is above the mechanism (every
 * source, always audited) but is never a distribution target. Scopes are granted per person by root only and are never
 * derived from a department, a title, a role name or an employee's name. Inside a command transaction they are read
 * with a shared lock after the order row is locked, so a concurrent scope change is either fully before or fully after
 * the command.
 */
final readonly class ConfirmareScopePolicy
{
    public const OPERATE = 'operate';
    public const SUPERVISE = 'supervise';
    public const CAPABILITIES = [self::OPERATE, self::SUPERVISE];

    /** Applications from which a person may work on Confirmare orders. */
    public const APPLICATIONS = ['staff', 'dashboard'];

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
        $statement = $this->pdo->prepare('SELECT source_key FROM employee_confirmare_scopes WHERE employee_uuid = ? AND capability = ? ORDER BY source_key' . ($lock ? ' LOCK IN SHARE MODE' : ''));
        $statement->execute([$actor->employeeUuid, $capability]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function allows(EmployeeIdentity $actor, string $capability, string $sourceKey, bool $lock = false): bool
    {
        $sources = $this->sources($actor, $capability, $lock);
        return $sources === null || in_array($sourceKey, $sources, true);
    }

    /** Whether the actor may see Confirmare orders of the source (either capability). */
    public function sees(EmployeeIdentity $actor, string $sourceKey, bool $lock = false): bool
    {
        return $this->allows($actor, self::OPERATE, $sourceKey, $lock) || $this->allows($actor, self::SUPERVISE, $sourceKey, $lock);
    }

    /** @return array{operate: list<string>, supervise: list<string>} */
    public function scopesOf(string $employeeUuid): array
    {
        $statement = $this->pdo->prepare('SELECT capability, source_key FROM employee_confirmare_scopes WHERE employee_uuid = ? ORDER BY capability, source_key');
        $statement->execute([$employeeUuid]);
        $scopes = [self::OPERATE => [], self::SUPERVISE => []];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $scopes[(string) $row['capability']][] = (string) $row['source_key'];
        }
        return $scopes;
    }

    /**
     * SQL that is true for an identity able to work now: active account and department, no pending password change,
     * and Staff or Dashboard access. `$alias` is the employees alias of the caller's query.
     */
    public static function workingIdentitySql(string $alias): string
    {
        $apps = "'" . implode("', '", self::APPLICATIONS) . "'";
        return "{$alias}.status = 'active' AND {$alias}.must_change_password = 0"
            . " AND EXISTS (SELECT 1 FROM departments wd WHERE wd.department_id = {$alias}.department_id AND wd.status = 'active')"
            . " AND EXISTS (SELECT 1 FROM employee_application_access wa WHERE wa.employee_uuid = {$alias}.employee_uuid AND wa.application_key IN ({$apps}))";
    }

    /** Whether the identity holds the capability for the source and can work now (a reassignment target, a kept assignee). */
    public function canWork(string $employeeUuid, string $capability, string $sourceKey, bool $lock = false): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM employee_confirmare_scopes s INNER JOIN employees e ON e.employee_uuid = s.employee_uuid
             WHERE s.employee_uuid = ? AND s.capability = ? AND s.source_key = ? AND ' . self::workingIdentitySql('e') . ($lock ? ' LOCK IN SHARE MODE' : ''),
        );
        $statement->execute([$employeeUuid, $capability, $sourceKey]);
        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Operators who may receive an automatic assignment of the source now: operate scope, able to work, accepting new
     * orders. Ordered for a fair distribution: fewest open assignments, then the longest without one.
     *
     * @return list<array{employee_uuid: string, display_name: string, open: int, last_assigned_at: string|null}>
     */
    public function distributionTargets(string $sourceKey): array
    {
        $statement = $this->pdo->prepare(
            "SELECT e.employee_uuid, e.display_name, st.last_assigned_at,
                    (SELECT COUNT(*) FROM order_confirmations c WHERE c.assigned_employee_uuid = e.employee_uuid AND c.status = 'pending') AS open_count
             FROM employee_confirmare_scopes s
             INNER JOIN employees e ON e.employee_uuid = s.employee_uuid
             INNER JOIN confirmare_operator_state st ON st.employee_uuid = e.employee_uuid AND st.accepting = 1
             WHERE s.source_key = ? AND s.capability = 'operate' AND " . self::workingIdentitySql('e') . '
             ORDER BY open_count, st.last_assigned_at IS NOT NULL, st.last_assigned_at, e.employee_uuid',
        );
        $statement->execute([$sourceKey]);
        return array_map(static fn (array $row): array => [
            'employee_uuid' => (string) $row['employee_uuid'],
            'display_name' => (string) $row['display_name'],
            'open' => (int) $row['open_count'],
            'last_assigned_at' => $row['last_assigned_at'] === null ? null : (string) $row['last_assigned_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * SQL condition restricting `$column` to the given sources: no condition for null (every source), a condition that
     * matches nothing for an empty scope.
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
