<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Company;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Finance\FinanceAccess as A;
use Arasya\Operations\Finance\FinanceInput as In;
use Arasya\Operations\Finance\FinanceStore;
use Arasya\Operations\Http\ApiException;
use PDO;

/** Read models for the verification desk: real counts, a keyset-paginated queue and the full case detail. */
final readonly class CaseQueries
{
    private const STATUSES = ['IMPORTED', 'NEEDS_REVIEW', 'NEEDS_INFORMATION', 'SUSPECTED_DUPLICATE', 'IN_REVIEW', 'READY_FOR_SECOND_CHECK', 'APPROVED', 'REJECTED', 'REOPENED'];
    private const SOURCES = ['MANUAL', 'CSV', 'XLSX', 'SAGA_EXPORT', 'SMARTBILL_EXPORT', 'EXISTING_B2B', 'OTHER_LEGACY'];

    public function __construct(private FinanceStore $store)
    {
    }

    /** Overview counts, all computed from stored rows (never estimated). */
    public function summary(EmployeeIdentity $actor): array
    {
        A::require($this->store->authorization(), $actor, A::COMPANIES_VIEW);
        $pdo = $this->store->pdo();
        $byStatus = array_fill_keys(self::STATUSES, 0);
        foreach ($pdo->query('SELECT status, COUNT(*) n FROM finance_company_cases GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byStatus[$row['status']] = (int) $row['n'];
        }
        $imported = (int) $pdo->query('SELECT COUNT(*) FROM finance_import_rows')->fetchColumn();
        $out = [
            'importedRows' => $imported,
            'cases' => array_sum($byStatus),
            'byStatus' => $byStatus,
            'awaitingReview' => $byStatus['IMPORTED'] + $byStatus['NEEDS_REVIEW'] + $byStatus['REOPENED'],
            'missingInformation' => $byStatus['NEEDS_INFORMATION'],
            'suspectedDuplicates' => $byStatus['SUSPECTED_DUPLICATE'],
            'underReview' => $byStatus['IN_REVIEW'],
            'awaitingSecondCheck' => $byStatus['READY_FOR_SECOND_CHECK'],
            'approved' => $byStatus['APPROVED'],
            'rejected' => $byStatus['REJECTED'],
            'accounts' => null,
        ];
        if (A::can($this->store->authorization(), $actor, A::ACCOUNTS_VIEW)) {
            $accounts = ['NO_INFORMATION' => 0, 'AWAITING_RECONCILIATION' => 0, 'DISPUTED' => 0, 'SUBMITTED' => 0, 'VERIFIED' => 0, 'RECONCILED' => 0];
            foreach ($pdo->query('SELECT status, COUNT(*) n FROM finance_account_onboardings GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $accounts[$row['status']] = (int) $row['n'];
            }
            $approvedWithoutOnboarding = (int) $pdo->query("SELECT COUNT(DISTINCT c.company_uuid) FROM finance_company_cases c LEFT JOIN finance_account_onboardings o ON o.company_uuid = c.company_uuid
                WHERE c.status = 'APPROVED' AND c.company_uuid IS NOT NULL AND o.onboarding_uuid IS NULL")->fetchColumn();
            $readyForCredit = (int) $pdo->query("SELECT COUNT(*) FROM finance_account_onboardings o
                LEFT JOIN finance_credit_limits l ON l.active_company_uuid = o.company_uuid
                WHERE o.status = 'RECONCILED' AND l.limit_uuid IS NULL")->fetchColumn();
            $out['accounts'] = ['byStatus' => $accounts, 'notOpened' => $approvedWithoutOnboarding,
                'awaitingReconciliation' => $approvedWithoutOnboarding + $accounts['NO_INFORMATION'] + $accounts['AWAITING_RECONCILIATION'] + $accounts['DISPUTED'] + $accounts['SUBMITTED'] + $accounts['VERIFIED'],
                'readyForCreditReview' => $readyForCredit];
        }
        return $out;
    }

    public function list(EmployeeIdentity $actor, array $q): array
    {
        A::require($this->store->authorization(), $actor, A::COMPANIES_VIEW);
        In::only($q, ['q', 'status', 'country', 'source', 'assigned', 'taxStatus', 'duplicates', 'missing', 'cursor', 'limit']);
        $where = [];
        $params = [];
        if (isset($q['status'])) {
            $statuses = explode(',', (string) $q['status']);
            if (array_diff($statuses, self::STATUSES) !== [] || count($statuses) > 9) {
                In::fail('status', 'invalid');
            }
            $where[] = 'c.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }
        if (isset($q['country'])) {
            if (preg_match('/^[A-Z]{2}$/D', (string) $q['country']) !== 1) {
                In::fail('country', 'invalid');
            }
            $where[] = 'c.country_code = ?';
            $params[] = $q['country'];
        }
        if (isset($q['source'])) {
            if (!in_array($q['source'], self::SOURCES, true)) {
                In::fail('source', 'invalid');
            }
            $where[] = 'c.source_system = ?';
            $params[] = $q['source'];
        }
        if (isset($q['assigned'])) {
            if ($q['assigned'] === 'me') {
                $where[] = 'c.assigned_employee_uuid = ?';
                $params[] = $actor->employeeUuid;
            } elseif ($q['assigned'] === 'none') {
                $where[] = 'c.assigned_employee_uuid IS NULL';
            } else {
                In::fail('assigned', 'invalid');
            }
        }
        if (isset($q['taxStatus'])) {
            if (!in_array($q['taxStatus'], ['unverified', 'pending', 'verified'], true)) {
                In::fail('taxStatus', 'invalid');
            }
            $where[] = 'c.tax_status = ?';
            $params[] = $q['taxStatus'];
        }
        if (($q['duplicates'] ?? null) === 'open') {
            $where[] = "c.duplicate_review = 'open'";
        } elseif (isset($q['duplicates'])) {
            In::fail('duplicates', 'invalid');
        }
        if (isset($q['missing'])) {
            $where[] = match ($q['missing']) {
                'tax' => 'c.tax_normalized IS NULL', 'country' => 'c.country_code IS NULL', 'address' => '(c.address_line IS NULL OR c.city IS NULL)',
                'contact' => '(c.contact_name IS NULL AND c.phone IS NULL AND c.email IS NULL)', 'legalName' => 'c.legal_name IS NULL',
                default => In::fail('missing', 'invalid'),
            };
        }
        if (isset($q['q'])) {
            $term = trim((string) $q['q']);
            if (mb_strlen($term) < 2 || mb_strlen($term) > 120) {
                In::fail('q', 'invalid');
            }
            $alnum = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $term));
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%';
            $where[] = '(c.legal_name LIKE ? OR c.commercial_name LIKE ? OR c.case_code = ? OR c.legacy_customer_code = ?' . ($alnum !== '' ? ' OR c.tax_normalized = ? OR c.vat_normalized = ?' : '') . ')';
            array_push($params, $like, $like, strtoupper($term), $term);
            if ($alnum !== '') {
                array_push($params, preg_replace('/^RO(?=\d)/', '', $alnum), $alnum);
            }
        }
        $limit = (int) ($q['limit'] ?? 50);
        if (!in_array($limit, [25, 50, 100], true)) {
            In::fail('limit', 'invalid');
        }
        if (isset($q['cursor'])) {
            $decoded = base64_decode(strtr((string) $q['cursor'], '-_', '+/'), true);
            if (!is_string($decoded) || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6})\|([0-9a-f-]{36})$/D', $decoded, $m) !== 1) {
                throw new ApiException(400, 'INVALID_CURSOR', 'Invalid cursor.');
            }
            $where[] = '(c.updated_at < ? OR (c.updated_at = ? AND c.case_uuid < ?))';
            array_push($params, $m[1], $m[1], $m[2]);
        }
        $sql = 'SELECT c.*, e.display_name AS assigned_name, b.company_code, o.status AS onboarding_status, o.account_currency
            FROM finance_company_cases c
            LEFT JOIN employees e ON e.employee_uuid = c.assigned_employee_uuid
            LEFT JOIN b2b_companies b ON b.company_uuid = c.company_uuid
            LEFT JOIN finance_account_onboardings o ON o.company_uuid = c.company_uuid'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY c.updated_at DESC, c.case_uuid DESC LIMIT ' . ($limit + 1);
        $s = $this->store->pdo()->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > $limit) {
            $last = $rows[$limit - 1];
            $next = rtrim(strtr(base64_encode($last['updated_at'] . '|' . $last['case_uuid']), '+/', '-_'), '=');
            $rows = array_slice($rows, 0, $limit);
        }
        return ['items' => array_map(fn (array $r): array => $this->item($r, $actor->employeeUuid), $rows), 'nextCursor' => $next];
    }

    public function detail(EmployeeIdentity $actor, string $caseId): array
    {
        A::require($this->store->authorization(), $actor, A::COMPANIES_VIEW);
        if (!In::isUuid($caseId)) {
            throw new ApiException(404, 'CASE_NOT_FOUND', 'Verification case was not found.');
        }
        $pdo = $this->store->pdo();
        $s = $pdo->prepare('SELECT c.*, e.display_name AS assigned_name, b.company_code, o.status AS onboarding_status, o.account_currency,
                sb.display_name AS submitted_name, ab.display_name AS approved_name
            FROM finance_company_cases c
            LEFT JOIN employees e ON e.employee_uuid = c.assigned_employee_uuid
            LEFT JOIN employees sb ON sb.employee_uuid = c.submitted_by_employee_uuid
            LEFT JOIN employees ab ON ab.employee_uuid = c.approved_by_employee_uuid
            LEFT JOIN b2b_companies b ON b.company_uuid = c.company_uuid
            LEFT JOIN finance_account_onboardings o ON o.company_uuid = c.company_uuid
            WHERE c.case_uuid = ?');
        $s->execute([$caseId]);
        $case = $s->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            throw new ApiException(404, 'CASE_NOT_FOUND', 'Verification case was not found.');
        }
        $source = null;
        if ($case['import_row_uuid'] !== null) {
            $s = $pdo->prepare('SELECT r.line_no, r.raw_json, r.issues_json, b.batch_uuid, b.file_name, b.source_system, b.header_json, b.created_at
                FROM finance_import_rows r JOIN finance_import_batches b ON b.batch_uuid = r.batch_uuid WHERE r.row_uuid = ?');
            $s->execute([$case['import_row_uuid']]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $source = ['batchId' => $row['batch_uuid'], 'fileName' => $row['file_name'], 'source' => $row['source_system'], 'line' => (int) $row['line_no'],
                    'headers' => json_decode((string) $row['header_json'], true), 'cells' => json_decode((string) $row['raw_json'], true),
                    'issues' => $row['issues_json'] === null ? (object) [] : json_decode((string) $row['issues_json'], true), 'importedAt' => FinanceStore::iso($row['created_at'])];
            }
        }
        $s = $pdo->prepare('SELECT d.*, r.display_name AS resolver FROM finance_duplicate_candidates d LEFT JOIN employees r ON r.employee_uuid = d.resolved_by_employee_uuid
            WHERE d.case_uuid = ? ORDER BY FIELD(d.status, \'open\') DESC, FIELD(d.strength, \'exact\', \'strong\', \'weak\'), d.created_at LIMIT 50');
        $s->execute([$caseId]);
        $duplicates = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $duplicates[] = ['id' => $d['candidate_uuid'], 'strength' => $d['strength'], 'indicators' => json_decode((string) $d['indicators'], true), 'status' => $d['status'],
                'resolvedBy' => $d['resolver'], 'resolvedAt' => FinanceStore::iso($d['resolved_at']), 'reason' => $d['resolution_reason'],
                'match' => $d['match_company_uuid'] !== null ? $this->companyCard((string) $d['match_company_uuid']) : $this->caseCard((string) $d['match_case_uuid'])];
        }
        $s = $pdo->prepare('SELECT d.decision, d.case_version, d.reason, d.source_refs, d.decided_at, e.display_name FROM finance_case_decisions d JOIN employees e ON e.employee_uuid = d.actor_employee_uuid
            WHERE d.case_uuid = ? ORDER BY d.decided_at DESC LIMIT 100');
        $s->execute([$caseId]);
        $decisions = array_map(static fn (array $d): array => ['decision' => $d['decision'], 'version' => (int) $d['case_version'], 'reason' => $d['reason'],
            'refs' => $d['source_refs'] === null ? null : json_decode((string) $d['source_refs'], true), 'at' => FinanceStore::iso($d['decided_at']), 'actor' => $d['display_name']], $s->fetchAll(PDO::FETCH_ASSOC));
        $checklist = CaseService::decode($case['checklist_json']);
        return [
            'case' => $this->item($case, $actor->employeeUuid) + [
                'fields' => CaseService::values($case),
                'fieldMeta' => (object) CaseService::decode($case['field_meta_json']),
                'declared' => ['currency' => $case['declared_currency'], 'debit' => $case['declared_debit'], 'credit' => $case['declared_credit'], 'lastTransactionDate' => $case['last_transaction_date']],
                'statusReason' => $case['status_reason'],
                'checklist' => $checklist,
                'checklistMissing' => CaseWorkflow::incomplete($checklist),
                'submittedBy' => $case['submitted_name'], 'submittedAt' => FinanceStore::iso($case['submitted_at']), 'submittedVersion' => $case['submitted_version'] === null ? null : (int) $case['submitted_version'],
                'approvedBy' => $case['approved_name'], 'approvedAt' => FinanceStore::iso($case['approved_at']),
                'origin' => $case['origin'],
            ],
            'source' => $source,
            'canonical' => $case['company_uuid'] === null ? null : $this->companyCard((string) $case['company_uuid']),
            'duplicates' => $duplicates,
            'decisions' => $decisions,
            'evidence' => $this->store->evidenceOf('case', $caseId),
            'history' => $this->store->history('case', $caseId),
            'actions' => $this->actions($actor, $case),
        ];
    }

    /** Actions the current employee may take on the case now (the server enforces each one again). @return list<string> */
    private function actions(EmployeeIdentity $actor, array $case): array
    {
        $auth = $this->store->authorization();
        $review = A::can($auth, $actor, A::COMPANIES_REVIEW);
        $verify = A::can($auth, $actor, A::COMPANIES_VERIFY);
        $dupes = A::can($auth, $actor, A::COMPANIES_RESOLVE_DUPLICATES);
        $mine = $case['status'] === 'IN_REVIEW' && $case['assigned_employee_uuid'] === $actor->employeeUuid;
        $out = [];
        if ($review && in_array($case['status'], CaseWorkflow::OPEN_QUEUE, true)) {
            $out[] = 'claim';
        }
        if ($review && $mine) {
            array_push($out, 'save', 'checklist', 'release', 'request_information', 'mark_duplicate', 'submit');
        }
        if ($dupes && $case['status'] === 'IN_REVIEW') {
            array_push($out, 'resolve_duplicate', 'link_company');
        }
        if ($verify && $case['status'] === 'READY_FOR_SECOND_CHECK' && $case['submitted_by_employee_uuid'] !== $actor->employeeUuid) {
            array_push($out, 'approve', 'return', 'reject');
        }
        if ($verify && in_array($case['status'], ['NEEDS_REVIEW', 'NEEDS_INFORMATION', 'SUSPECTED_DUPLICATE', 'REOPENED', 'IMPORTED'], true)) {
            $out[] = 'reject';
        }
        if ($verify && in_array($case['status'], ['APPROVED', 'REJECTED'], true)) {
            $out[] = 'reopen';
        }
        return array_values(array_unique($out));
    }

    /** @return array<string, mixed> */
    private function item(array $r, string $actor): array
    {
        $missing = [];
        foreach (['legalName' => 'legal_name', 'countryCode' => 'country_code', 'taxIdentifier' => 'tax_normalized', 'addressLine' => 'address_line', 'city' => 'city'] as $field => $column) {
            if ($r[$column] === null) {
                $missing[] = $field;
            }
        }
        if ($r['contact_name'] === null && $r['phone'] === null && $r['email'] === null) {
            $missing[] = 'contact';
        }
        return [
            'id' => $r['case_uuid'], 'code' => $r['case_code'], 'legalName' => $r['legal_name'], 'commercialName' => $r['commercial_name'], 'countryCode' => $r['country_code'],
            'taxIdentifier' => $r['tax_identifier'], 'taxStatus' => $r['tax_status'], 'source' => $r['source_system'], 'status' => $r['status'],
            'assignedTo' => $r['assigned_name'], 'assignedToMe' => $r['assigned_employee_uuid'] === $actor, 'duplicateReview' => $r['duplicate_review'], 'missing' => $missing,
            'companyId' => $r['company_uuid'], 'companyCode' => $r['company_code'], 'onboardingStatus' => $r['onboarding_status'], 'accountCurrency' => $r['account_currency'],
            'legacyCustomerCode' => $r['legacy_customer_code'], 'updatedAt' => FinanceStore::iso($r['updated_at']), 'version' => (int) $r['version'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function companyCard(string $companyId): ?array
    {
        $s = $this->store->pdo()->prepare("SELECT c.company_uuid, c.company_code, c.legal_name, c.display_name, c.country_code, c.tax_identifier, c.vat_number, c.registration_number, c.status, c.website,
                a.address_line_1, a.city, a.postal_code, a.county_region, k.full_name, k.phone, k.email
            FROM b2b_companies c
            LEFT JOIN b2b_company_addresses a ON a.address_uuid = (SELECT a2.address_uuid FROM b2b_company_addresses a2 WHERE a2.company_uuid = c.company_uuid AND a2.status = 'active' ORDER BY a2.is_primary DESC, a2.created_at LIMIT 1)
            LEFT JOIN b2b_company_contacts k ON k.contact_uuid = (SELECT k2.contact_uuid FROM b2b_company_contacts k2 WHERE k2.company_uuid = c.company_uuid AND k2.status = 'active' ORDER BY k2.is_primary DESC, k2.created_at LIMIT 1)
            WHERE c.company_uuid = ?");
        $s->execute([$companyId]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            return null;
        }
        return ['kind' => 'company', 'id' => $c['company_uuid'], 'code' => $c['company_code'], 'status' => $c['status'], 'fields' => [
            'legalName' => $c['legal_name'], 'commercialName' => $c['display_name'], 'countryCode' => $c['country_code'], 'taxIdentifier' => $c['tax_identifier'],
            'vatNumber' => $c['vat_number'], 'registrationNumber' => $c['registration_number'], 'addressLine' => $c['address_line_1'], 'city' => $c['city'],
            'postalCode' => $c['postal_code'], 'countyRegion' => $c['county_region'], 'contactName' => $c['full_name'], 'phone' => $c['phone'], 'email' => $c['email'], 'website' => $c['website'],
        ]];
    }

    /** @return array<string, mixed>|null */
    private function caseCard(string $caseId): ?array
    {
        $s = $this->store->pdo()->prepare('SELECT * FROM finance_company_cases WHERE case_uuid = ?');
        $s->execute([$caseId]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        return $c ? ['kind' => 'case', 'id' => $c['case_uuid'], 'code' => $c['case_code'], 'status' => $c['status'], 'companyId' => $c['company_uuid'], 'fields' => CaseService::values($c)] : null;
    }
}
