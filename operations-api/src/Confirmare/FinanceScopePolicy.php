<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Employee\EmployeeIdentity;
use PDO;

/**
 * Which order sources an identity handles Confirmare money for (employee_finance_scopes, migration 039, API 2.41.0).
 *
 * - view:    reads the financial summary (amounts read from the shop at that moment) and the finance queue;
 * - record:  also records a received bank transfer or a bank refund already paid out. A record is never money
 *            received: it waits for another employee's verification;
 * - approve: also verifies or rejects what ANOTHER employee recorded, and acknowledges a refund liability.
 *
 * Separate from customer communication (Confirmare `operate`) and from Confirmare supervision: neither grants a finance
 * capability, so a supervisor never verifies money through supervision. Without a row nothing is visible or possible
 * (default deny), root included: root grants scopes (PUT /management/employees/{id}/finance-scopes) but holds none
 * implicitly. Never derived from a department, a title, a role name or an employee's name. Inside a command transaction
 * the scopes are read with a shared lock, so a concurrent change is either fully before or fully after the command.
 */
final readonly class FinanceScopePolicy
{
    public const VIEW = 'view';
    public const RECORD = 'record';
    public const APPROVE = 'approve';
    public const CAPABILITIES = [self::VIEW, self::RECORD, self::APPROVE];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<string> the sources the capability reaches (record and approve also read) */
    public function sources(EmployeeIdentity $actor, string $capability, bool $lock = false): array
    {
        $capabilities = $capability === self::VIEW ? self::CAPABILITIES : [$capability];
        $statement = $this->pdo->prepare('SELECT DISTINCT source_key FROM employee_finance_scopes WHERE employee_uuid = ? AND capability IN ('
            . implode(', ', array_fill(0, count($capabilities), '?')) . ') ORDER BY source_key' . ($lock ? ' LOCK IN SHARE MODE' : ''));
        $statement->execute([$actor->employeeUuid, ...$capabilities]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function allows(EmployeeIdentity $actor, string $capability, string $sourceKey, bool $lock = false): bool
    {
        return in_array($sourceKey, $this->sources($actor, $capability, $lock), true);
    }

    /** @return array{view: list<string>, record: list<string>, approve: list<string>} the stored rows, by capability */
    public function scopesOf(string $employeeUuid): array
    {
        $statement = $this->pdo->prepare('SELECT capability, source_key FROM employee_finance_scopes WHERE employee_uuid = ? ORDER BY capability, source_key');
        $statement->execute([$employeeUuid]);
        $scopes = [self::VIEW => [], self::RECORD => [], self::APPROVE => []];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $scopes[(string) $row['capability']][] = (string) $row['source_key'];
        }
        return $scopes;
    }
}
