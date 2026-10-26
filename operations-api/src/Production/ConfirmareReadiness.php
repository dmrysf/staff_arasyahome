<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Confirmare\ConfirmareScopePolicy;
use Arasya\Operations\Document\DocumentService;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
use Arasya\Operations\Integration\SourceAccess\SourceAccessTarget;
use Arasya\Operations\Integration\SourceDefinition;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Whether ONE source may be activated for Confirmare (API 2.45.0). It replaces the release-wide
 * ConfirmationGate::WORKFLOW_READY switch of Phase 1, which existed because nothing could approve a Confirmare order yet:
 * activating a source would have stopped its production. Since API 2.42.0 the workflow approves, routes and hands orders
 * over, so the question is per source: can THIS source's new orders get through every step, or would they wait at stage
 * 1 for good? Every activation step (schedule, activate, resume) is refused until each required check below passes. It
 * is evaluated on the server (bin/workflow-cohort.php), never in a screen, and the shop states its own side in the
 * signed heartbeat (source_connector_readiness, YD SOFT 2.19.0).
 *
 * Required:
 * - schema:         migrations 035 to 039 and 042 are applied (041 too when the source invoices through Arasya);
 * - permitted:      ARASYA_SOURCE_CONFIRMARE_<SOURCE> is on with production, QR and document authority in enforce;
 * - ingesting:      the source is enabled and active for real order ingestion;
 * - operators:      someone can confirm the orders (a working identity with the Confirmare `operate` scope);
 * - source_access:  the operator can open the shop order (ARASYA_SOURCE_ACCESS_<SOURCE> read or edit): the customer's
 *                   real telephone number and the current prices exist only there;
 * - finance:        the money of an order can be verified in Arasya (finance mode `record`) by two different people
 *                   (finance_four_eyes: one who records, another who approves);
 * - documents:      someone can generate the production document (revision 1) after the approval;
 * - invoicing:      only when the source invoices through Arasya (ARASYA_SOURCE_INVOICE_<SOURCE> observe or enforce):
 *                   migration 041 and someone with the invoice `issue` or `manage` scope;
 * - connector:      a signed readiness report of the shop, at most 15 minutes old, from YD SOFT 2.19.0 or later, with
 *                   its access schema ready, its own source access and finance modes at least what Arasya uses, and (when
 *                   Arasya invoices) the same invoice mode, SmartBill ready and, for `enforce`, the accountant's written
 *                   decision recorded in the shop.
 * Reported only: whether controlled edits are possible (`edit`), supervisors, and the Staff release (not visible to the
 * API: a deployment step).
 *
 * Read-only: SELECTs only, so it also runs inside the status command's read-only transaction.
 */
final readonly class ConfirmareReadiness
{
    public const MIN_CONNECTOR_VERSION = '2.19.0';
    public const REPORT_MAX_AGE_SECONDS = 900;
    /** Migrations the Confirmare workflow needs (035 cohort … 039 finance) and the connector readiness report (042). */
    public const WORKFLOW_MIGRATIONS = ['035_workflow_cohort.sql', '036_confirmare_operations.sql', '037_confirmare_contacts.sql', '038_confirmare_source_access.sql', '039_confirmare_finance.sql', '042_confirmare_connector_readiness.sql'];
    public const INVOICE_MIGRATION = '041_smartbill_invoicing.sql';
    public const REQUIRED = ['schema', 'permitted', 'ingesting', 'operators', 'source_access', 'finance', 'finance_four_eyes', 'documents', 'invoicing', 'connector'];

    public function __construct(
        private PDO $pdo,
        private SourceAccessRegistry $access,
        private PdoEmployeeRepository $employees,
        private AuthorizationService $authorization,
    ) {
    }

    /**
     * @return array{sourceKey: string, ready: bool, failed: list<string>, checks: list<array{check: string, ok: bool|null, detail: mixed}>}
     */
    public function evaluate(?SourceDefinition $source, DateTimeImmutable $now): array
    {
        $key = $source?->key ?? '';
        $target = $source === null ? null : $this->access->find($source->key);
        $invoicing = $target !== null && $target->invoice !== SourceAccessTarget::INVOICE_OFF;
        $checks = [];
        $add = static function (string $check, ?bool $ok, mixed $detail = null) use (&$checks): void {
            $checks[] = ['check' => $check, 'ok' => $ok, 'detail' => $detail];
        };

        $applied = $this->appliedMigrations();
        $needed = $invoicing ? [...self::WORKFLOW_MIGRATIONS, self::INVOICE_MIGRATION] : self::WORKFLOW_MIGRATIONS;
        $missing = array_values(array_diff($needed, $applied));
        sort($missing);
        $add('schema', $missing === [], ['missing' => $missing]);
        $add('permitted', $source !== null && $source->confirmarePermitted);
        $add('ingesting', $source !== null && $source->canIngest());
        $operators = $this->working('employee_confirmare_scopes', 'operate', $key);
        $add('operators', $operators !== [], ['count' => count($operators)]);
        $add('supervisors', null, ['count' => count($this->working('employee_confirmare_scopes', 'supervise', $key))]);
        $add('source_access', $target !== null && in_array($target->mode, [SourceAccessTarget::MODE_READ, SourceAccessTarget::MODE_EDIT], true), ['mode' => $target?->mode ?? 'off']);
        $add('controlled_edits', null, ['possible' => $target?->mode === SourceAccessTarget::MODE_EDIT]);
        $add('finance', $target !== null && $target->finance === SourceAccessTarget::FINANCE_RECORD, ['mode' => $target?->finance ?? 'off']);
        $recorders = $this->working('employee_finance_scopes', 'record', $key);
        $approvers = $this->working('employee_finance_scopes', 'approve', $key);
        $fourEyes = false;
        foreach ($recorders as $recorder) {
            foreach ($approvers as $approver) {
                $fourEyes = $fourEyes || $recorder !== $approver;
            }
        }
        $add('finance_four_eyes', $fourEyes, ['record' => count($recorders), 'approve' => count($approvers)]);
        $generators = array_values(array_filter($this->working('employee_document_scopes', 'operate', $key), fn (string $uuid): bool => $this->can($uuid, DocumentService::GENERATE)));
        $add('documents', $generators !== [], ['count' => count($generators)]);
        $issuers = $invoicing && in_array(self::INVOICE_MIGRATION, $applied, true)
            ? array_values(array_unique([...$this->working('employee_invoice_scopes', 'issue', $key), ...$this->working('employee_invoice_scopes', 'manage', $key)]))
            : [];
        $add('invoicing', $invoicing ? $issuers !== [] && in_array(self::INVOICE_MIGRATION, $applied, true) : true, ['mode' => $target?->invoice ?? 'off', 'issuers' => count($issuers)]);
        // Owner 2026-10-26 (reported only): whether the third unanswered call sends the 30 % advance proforma by itself. It
        // does only with invoice `enforce` and migration 043 (and the cycle worker's cron, which the API cannot see);
        // otherwise nothing is issued or e-mailed to the customer and the operator follows the order up by hand.
        $cycleSchema = in_array('043_confirmare_dual_store.sql', $applied, true);
        $automatic = $target !== null && $target->allowsInvoiceActions() && $cycleSchema;
        $add('advance_proforma_automatic', null, ['automatic' => $automatic,
            'reason' => $automatic ? null : ($target === null || !$target->allowsInvoiceActions() ? 'invoice_not_enforced' : 'cycle_schema_missing'),
            'effect' => $automatic
                ? 'After the third unanswered call the advance proforma is issued in SmartBill and e-mailed to the customer by the cycle worker.'
                : 'New Confirmare orders are confirmed by telephone as usual, but after the third unanswered call no advance proforma is issued or e-mailed: the order stays with its operator, who follows it up by hand. Arasya issues no SmartBill document for this shop.',
            // Owner 2026-10-26: invoice `enforce` is a separate step after go-live, never part of the code installation.
            'enforceOnlyAfter' => ['smartbill_real_document_test', 'proforma_bank_settings', 'accountant_approval', 'efactura_spv_check'],
            'worker' => 'php bin/confirmare-cycles.php every 5 minutes (cron; not visible to the API)']);
        [$connectorOk, $connector] = $this->connector($key, $target, $now, in_array('042_confirmare_connector_readiness.sql', $applied, true));
        $add('connector', $connectorOk, $connector);
        $add('staff_release', null, 'not visible to the API: Staff 2.36.0 is deployed right after the API (deployment step)');

        $failed = array_values(array_map(static fn (array $c): string => $c['check'], array_filter($checks, static fn (array $c): bool => $c['ok'] === false)));
        return ['sourceKey' => $key, 'ready' => $source !== null && $failed === [], 'failed' => $failed, 'checks' => $checks];
    }

    /** Throws 409 CONFIRMARE_NOT_READY with the failed checks, unless every required check passes. */
    public function assertReady(?SourceDefinition $source, DateTimeImmutable $now): void
    {
        $report = $this->evaluate($source, $now);
        if (!$report['ready']) {
            throw new ApiException(409, 'CONFIRMARE_NOT_READY', 'The source is not ready for Confirmare: ' . implode(', ', $report['failed']) . '. See bin/workflow-cohort.php --readiness.', ['failed' => $report['failed']]);
        }
    }

    /** @return list<string> */
    private function appliedMigrations(): array
    {
        try {
            return array_map('strval', $this->pdo->query('SELECT migration_name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Working identities (active, password changed, active department, Staff or Dashboard access) holding the scope.
     *
     * @return list<string>
     */
    private function working(string $table, string $capability, string $sourceKey): array
    {
        try {
            $statement = $this->pdo->prepare("SELECT DISTINCT s.employee_uuid FROM {$table} s INNER JOIN employees e ON e.employee_uuid = s.employee_uuid
                WHERE s.capability = ? AND s.source_key = ? AND " . ConfirmareScopePolicy::workingIdentitySql('e') . ' ORDER BY s.employee_uuid');
            $statement->execute([$capability, $sourceKey]);
            return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return [];
        }
    }

    private function can(string $employeeUuid, string $permission): bool
    {
        $identity = $this->employees->findByUuid($employeeUuid);
        return $identity !== null && $this->authorization->can($identity, $permission);
    }

    /**
     * The shop's latest signed readiness report (heartbeat field connectorReadiness, stored by the ingestion controller).
     *
     * @return array{0: bool, 1: array<string, mixed>}
     */
    private function connector(string $sourceKey, ?SourceAccessTarget $target, DateTimeImmutable $now, bool $schema): array
    {
        if (!$schema) {
            return [false, ['reason' => 'schema_missing']];
        }
        $statement = $this->pdo->prepare('SELECT connector_version, access_mode, finance_mode, invoice_mode, schema_ready, invoice_attested, smartbill_ready, reported_at FROM source_connector_readiness WHERE source_key = ?');
        $statement->execute([$sourceKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [false, ['reason' => 'no_report']];
        }
        $reportedAt = new DateTimeImmutable((string) $row['reported_at'], new DateTimeZone('UTC'));
        $report = [
            'version' => (string) $row['connector_version'],
            'reportedAt' => $reportedAt->format('Y-m-d\TH:i:s\Z'),
            'access' => (string) $row['access_mode'],
            'finance' => (string) $row['finance_mode'],
            'invoice' => (string) $row['invoice_mode'],
            'schemaReady' => (int) $row['schema_ready'] === 1,
            'invoiceAttested' => (int) $row['invoice_attested'] === 1,
            'smartbillReady' => (int) $row['smartbill_ready'] === 1,
        ];
        $problems = [];
        if ($reportedAt->getTimestamp() < $now->getTimestamp() - self::REPORT_MAX_AGE_SECONDS) {
            $problems[] = 'report_stale';
        }
        if (version_compare($report['version'], self::MIN_CONNECTOR_VERSION, '<')) {
            $problems[] = 'connector_too_old';
        }
        if (!$report['schemaReady']) {
            $problems[] = 'shop_schema_not_ready';
        }
        $rank = [SourceAccessTarget::MODE_READ => 1, SourceAccessTarget::MODE_EDIT => 2];
        if ($target === null || ($rank[$report['access']] ?? 0) < ($rank[$target->mode] ?? 1)) {
            $problems[] = 'shop_access_mode';
        }
        if ($target !== null && $target->finance === SourceAccessTarget::FINANCE_RECORD && $report['finance'] !== SourceAccessTarget::FINANCE_RECORD) {
            $problems[] = 'shop_finance_mode';
        }
        if ($target !== null && $target->invoice !== SourceAccessTarget::INVOICE_OFF) {
            if ($report['invoice'] !== $target->invoice) {
                $problems[] = 'shop_invoice_mode';
            }
            if (!$report['smartbillReady']) {
                $problems[] = 'shop_smartbill_not_ready';
            }
            if ($target->invoice === SourceAccessTarget::INVOICE_ENFORCE && !$report['invoiceAttested']) {
                $problems[] = 'shop_accountant_decision_missing';
            }
        }
        return [$problems === [], $report + ['problems' => $problems]];
    }
}
