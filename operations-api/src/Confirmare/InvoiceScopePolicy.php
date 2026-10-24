<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Employee\EmployeeIdentity;
use PDO;
use PDOException;

/**
 * Which order sources an identity handles SmartBill documents for (employee_invoice_scopes, API 2.43.0; the schema is
 * the DRAFT database/drafts/smartbill-invoicing.sql until the owner numbers it).
 *
 * - issue:  issues the first document of a Confirmare order and sends it to the customer. It acts only together with
 *           the production document permission and the document `operate` scope of the same source (ConfirmareInvoice):
 *           the employee who prepares the production ticket and its QR. Holding that document authority alone never
 *           grants SmartBill writes, and a finance or Confirmare scope never does either;
 * - manage: also re-issues a document and closes an unknown outcome after checking SmartBill (a manager of the source).
 *
 * Without a row nothing is possible (default deny), root included: root grants scopes (PUT /management/employees/{id}/
 * invoice-scopes) but holds none implicitly. Never derived from a department, a title, a role name or an employee's
 * name. Before the schema exists every check is false (fail closed).
 */
final readonly class InvoiceScopePolicy
{
    public const ISSUE = 'issue';
    public const MANAGE = 'manage';
    public const CAPABILITIES = [self::ISSUE, self::MANAGE];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<string> the sources the capability reaches */
    public function sources(EmployeeIdentity $actor, string $capability, bool $lock = false): array
    {
        try {
            $statement = $this->pdo->prepare('SELECT DISTINCT source_key FROM employee_invoice_scopes WHERE employee_uuid = ? AND capability = ? ORDER BY source_key' . ($lock ? ' LOCK IN SHARE MODE' : ''));
            $statement->execute([$actor->employeeUuid, $capability]);
            return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException) {
            return [];
        }
    }

    public function allows(EmployeeIdentity $actor, string $capability, string $sourceKey, bool $lock = false): bool
    {
        return in_array($sourceKey, $this->sources($actor, $capability, $lock), true);
    }

    /** Whether the invoicing schema exists (the draft was applied). */
    public function installed(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM employee_invoice_scopes LIMIT 1');
            $this->pdo->query('SELECT 1 FROM order_invoice_actions LIMIT 1');
            $this->pdo->query('SELECT 1 FROM order_invoice_reads LIMIT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** @return array{issue: list<string>, manage: list<string>} the stored rows, by capability */
    public function scopesOf(string $employeeUuid): array
    {
        $scopes = [self::ISSUE => [], self::MANAGE => []];
        try {
            $statement = $this->pdo->prepare('SELECT capability, source_key FROM employee_invoice_scopes WHERE employee_uuid = ? ORDER BY capability, source_key');
            $statement->execute([$employeeUuid]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $scopes[(string) $row['capability']][] = (string) $row['source_key'];
            }
        } catch (PDOException) {
            // No schema yet: no scope.
        }
        return $scopes;
    }
}
