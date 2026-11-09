<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Company;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Finance\FinanceAccess as A;
use Arasya\Operations\Finance\FinanceInput as In;
use Arasya\Operations\Finance\FinanceStore;
use Arasya\Operations\Finance\Import\ImportNormalizer;
use Arasya\Operations\Http\ApiException;
use PDO;

/** Read models for the verification desk: real counts, a keyset-paginated queue and the full case detail. */
final readonly class CaseQueries
{
    private const STATUSES = ['IMPORTED', 'NEEDS_REVIEW', 'NEEDS_INFORMATION', 'SUSPECTED_DUPLICATE', 'IN_REVIEW', 'READY_FOR_SECOND_CHECK', 'APPROVED', 'REJECTED', 'REOPENED'];
    private const SOURCES = ['MANUAL', 'CSV', 'XLSX', 'SAGA_EXPORT', 'SMARTBILL_EXPORT', 'EXISTING_B2B', 'OTHER_LEGACY'];

    public function __construct(private FinanceStore $store, private CaseService $cases)
    {
    }

    /** Real counts and filter options for the review queue. */
    public function facets(EmployeeIdentity $actor): array
    {
        A::require($this->store->authorization(), $actor, A::COMPANIES_VIEW);
        $pdo = $this->store->pdo();
        $flags = array_fill_keys(CaseFlags::CODES, 0);
        foreach ($pdo->query("SELECT flag_code, COUNT(*) n FROM finance_case_flags WHERE status = 'open' GROUP BY flag_code")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $flags[$r['flag_code']] = (int) $r['n'];
        }
        $priority = ['P0' => 0, 'P1' => 0, 'P2' => 0];
        foreach ($pdo->query("SELECT review_priority, COUNT(*) n FROM finance_company_cases WHERE status NOT IN ('APPROVED', 'REJECTED') GROUP BY review_priority")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $priority[$r['review_priority']] = (int) $r['n'];
        }
        $status = array_fill_keys(self::STATUSES, 0);
        foreach ($pdo->query('SELECT status, COUNT(*) n FROM finance_company_cases GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $status[$r['status']] = (int) $r['n'];
        }
        $reviewers = $pdo->query('SELECT DISTINCT e.employee_uuid, e.display_name FROM finance_company_cases c JOIN employees e ON e.employee_uuid = c.assigned_employee_uuid ORDER BY e.display_name LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        $batches = $pdo->query("SELECT batch_uuid, file_name, import_profile, staged_rows, created_at FROM finance_import_batches WHERE status IN ('confirming', 'completed') ORDER BY created_at DESC, batch_uuid LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $countries = $pdo->query('SELECT DISTINCT country_code FROM finance_company_cases WHERE country_code IS NOT NULL ORDER BY country_code LIMIT 250')->fetchAll(PDO::FETCH_COLUMN);
        $duplicatesOpen = (int) $pdo->query("SELECT COUNT(*) FROM finance_company_cases WHERE duplicate_review = 'open'")->fetchColumn();
        return ['flags' => $flags, 'priority' => $priority, 'status' => $status, 'duplicatesOpen' => $duplicatesOpen, 'countries' => array_map('strval', $countries),
            'reviewers' => array_map(static fn (array $r): array => ['id' => $r['employee_uuid'], 'name' => $r['display_name']], $reviewers),
            'batches' => array_map(static fn (array $b): array => ['id' => $b['batch_uuid'], 'fileName' => $b['file_name'], 'profile' => $b['import_profile'], 'staged' => (int) $b['staged_rows'], 'importedAt' => FinanceStore::iso($b['created_at'])], $batches)];
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
        In::only($q, ['q', 'status', 'country', 'source', 'assigned', 'taxStatus', 'duplicates', 'missing', 'flag', 'priority', 'batch', 'sort', 'cursor', 'limit']);
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
        if (($q['country'] ?? null) === 'NONE') {
            $where[] = 'c.country_code IS NULL';
        } elseif (isset($q['country'])) {
            if (preg_match('/^[A-Z]{2}$/D', (string) $q['country']) !== 1) {
                In::fail('country', 'invalid');
            }
            $where[] = 'c.country_code = ?';
            $params[] = $q['country'];
        }
        if (isset($q['flag'])) {
            if (!in_array($q['flag'], CaseFlags::CODES, true)) {
                In::fail('flag', 'invalid');
            }
            $where[] = "EXISTS (SELECT 1 FROM finance_case_flags f WHERE f.case_uuid = c.case_uuid AND f.flag_code = ? AND f.status = 'open')";
            $params[] = $q['flag'];
        }
        if (isset($q['priority'])) {
            if (!in_array($q['priority'], ['P0', 'P1', 'P2'], true)) {
                In::fail('priority', 'invalid');
            }
            $where[] = 'c.review_priority = ?';
            $params[] = $q['priority'];
        }
        if (isset($q['batch'])) {
            if (!In::isUuid($q['batch'])) {
                In::fail('batch', 'invalid');
            }
            $where[] = 'EXISTS (SELECT 1 FROM finance_import_rows ir WHERE ir.row_uuid = c.import_row_uuid AND ir.batch_uuid = ?)';
            $params[] = $q['batch'];
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
            } elseif (In::isUuid($q['assigned'])) {
                $where[] = 'c.assigned_employee_uuid = ?';
                $params[] = $q['assigned'];
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
            $phone = ImportNormalizer::phoneKey($term);
            $where[] = '(c.legal_name LIKE ? OR c.commercial_name LIKE ? OR c.source_customer_name LIKE ? OR c.case_code = ? OR c.legacy_customer_code = ?' . ($alnum !== '' ? ' OR c.tax_normalized = ? OR c.vat_normalized = ?' : '') . ($phone !== null ? ' OR c.phone_key = ?' : '') . ')';
            array_push($params, $like, $like, $like, strtoupper($term), $term);
            if ($alnum !== '') {
                array_push($params, preg_replace('/^RO(?=\d)/', '', $alnum), $alnum);
            }
            if ($phone !== null) {
                $params[] = $phone;
            }
        }
        $sort = $q['sort'] ?? 'updated';
        if (!in_array($sort, ['updated', 'source'], true)) {
            In::fail('sort', 'invalid');
        }
        $limit = (int) ($q['limit'] ?? 50);
        if (!in_array($limit, [25, 50, 100], true)) {
            In::fail('limit', 'invalid');
        }
        if (isset($q['cursor']) && $sort === 'source') {
            if (preg_match('/^\d{1,18}$/D', (string) $q['cursor']) !== 1) {
                throw new ApiException(400, 'INVALID_CURSOR', 'Invalid cursor.');
            }
            $where[] = 'c.case_number > ?';
            $params[] = (int) $q['cursor'];
        } elseif (isset($q['cursor'])) {
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
            . ($sort === 'source' ? ' ORDER BY c.case_number' : ' ORDER BY c.updated_at DESC, c.case_uuid DESC') . ' LIMIT ' . ($limit + 1);
        $s = $this->store->pdo()->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > $limit) {
            $last = $rows[$limit - 1];
            $next = $sort === 'source' ? (string) $last['case_number'] : rtrim(strtr(base64_encode($last['updated_at'] . '|' . $last['case_uuid']), '+/', '-_'), '=');
            $rows = array_slice($rows, 0, $limit);
        }
        $flags = $this->openFlags(array_column($rows, 'case_uuid'));
        return ['items' => array_map(fn (array $r): array => $this->item($r, $actor->employeeUuid) + ['flags' => $flags[$r['case_uuid']] ?? []], $rows), 'nextCursor' => $next];
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
            $s = $pdo->prepare('SELECT r.line_no, r.raw_json, r.issues_json, b.batch_uuid, b.file_name, b.file_sha256, b.import_profile, b.source_system, b.header_json, b.created_at, e.display_name AS importer
                FROM finance_import_rows r JOIN finance_import_batches b ON b.batch_uuid = r.batch_uuid JOIN employees e ON e.employee_uuid = b.created_by_employee_uuid WHERE r.row_uuid = ?');
            $s->execute([$case['import_row_uuid']]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $source = ['batchId' => $row['batch_uuid'], 'fileName' => $row['file_name'], 'fileSha256' => bin2hex((string) $row['file_sha256']), 'profile' => $row['import_profile'], 'importedBy' => $row['importer'], 'source' => $row['source_system'], 'line' => (int) $row['line_no'],
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
        $auth = $this->store->authorization();
        $sensitive = A::can($auth, $actor, A::AUDIT_SENSITIVE);
        $oversight = $sensitive || A::can($auth, $actor, A::COMPANIES_SUPERVISE) || A::can($auth, $actor, A::AUDIT_VIEW);
        $s = $pdo->prepare('SELECT c.body, c.created_at, e.display_name FROM finance_case_comments c JOIN employees e ON e.employee_uuid = c.actor_employee_uuid WHERE c.case_uuid = ? ORDER BY c.created_at DESC, c.comment_uuid LIMIT 200');
        $s->execute([$caseId]);
        $comments = array_map(static fn (array $c): array => ['body' => $c['body'], 'at' => FinanceStore::iso($c['created_at']), 'actor' => $c['display_name']], $s->fetchAll(PDO::FETCH_ASSOC));
        $s = $pdo->prepare('SELECT r.reason, r.declared_at, e.display_name, d.display_name AS declared_by FROM finance_case_recusals r JOIN employees e ON e.employee_uuid = r.employee_uuid JOIN employees d ON d.employee_uuid = r.declared_by_employee_uuid WHERE r.case_uuid = ? ORDER BY r.declared_at');
        $s->execute([$caseId]);
        $recusals = $s->fetchAll(PDO::FETCH_ASSOC);
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
                'createdBy' => $this->employeeName($case['created_by_employee_uuid']),
                'createdAt' => FinanceStore::iso($case['created_at']),
                'updatedBy' => $this->employeeName($case['updated_by_employee_uuid']),
            ],
            'flags' => (new CaseFlags($pdo))->of($caseId),
            'comments' => $comments,
            'supervision' => ['required' => $case['supervision_status'] === 'required', 'recusedCount' => count($recusals), 'actorRecused' => $this->cases->isRecused($caseId, $actor->employeeUuid),
                'recusals' => $oversight ? array_map(static fn (array $r): array => ['employee' => $r['display_name'], 'reason' => $r['reason'], 'declaredBy' => $r['declared_by'], 'at' => FinanceStore::iso($r['declared_at'])], $recusals) : null],
            'fieldChanges' => $sensitive ? $this->fieldChanges($caseId) : null,
            'comparison' => $this->comparison($actor, $case, $caseId),
            'accountBalanceVerified' => $case['onboarding_status'] === 'RECONCILED',
            'source' => $source,
            'canonical' => $case['company_uuid'] === null ? null : $this->companyCard((string) $case['company_uuid']),
            'duplicates' => $duplicates,
            'decisions' => $decisions,
            'evidence' => $this->store->evidenceOf('case', $caseId),
            'history' => $this->store->history('case', $caseId),
            'actions' => $this->actions($actor, $case),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function fieldChanges(string $caseId): array
    {
        $s = $this->store->pdo()->prepare('SELECT f.field_name, f.old_value, f.new_value, f.case_version, f.reason, f.evidence_ids, f.request_id, f.changed_at, e.display_name FROM finance_case_field_changes f JOIN employees e ON e.employee_uuid = f.actor_employee_uuid WHERE f.case_uuid = ? ORDER BY f.changed_at DESC, f.change_uuid LIMIT 500');
        $s->execute([$caseId]);
        return array_map(static fn (array $f): array => ['field' => $f['field_name'], 'old' => $f['old_value'], 'new' => $f['new_value'], 'version' => (int) $f['case_version'], 'reason' => $f['reason'],
            'evidence' => $f['evidence_ids'] === null ? 0 : count((array) json_decode((string) $f['evidence_ids'], true)), 'requestId' => $f['request_id'], 'at' => FinanceStore::iso($f['changed_at']), 'actor' => $f['display_name']], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** What an independent reviewer compares the original code with. Counts only; balances stay behind the accounts permission. @return array<string, mixed> */
    private function comparison(EmployeeIdentity $actor, array $case, string $caseId): array
    {
        $pdo = $this->store->pdo();
        $sameCode = [];
        if ($case['legacy_customer_code'] !== null) {
            $s = $pdo->prepare('SELECT c.case_uuid, c.case_code, c.status, c.source_customer_name, c.legal_name, c.phone, r.line_no, b.file_name FROM finance_company_cases c LEFT JOIN finance_import_rows r ON r.row_uuid = c.import_row_uuid LEFT JOIN finance_import_batches b ON b.batch_uuid = r.batch_uuid
                WHERE c.legacy_customer_code = ? AND c.case_uuid <> ? ORDER BY c.case_number LIMIT 25');
            $s->execute([$case['legacy_customer_code'], $caseId]);
            $sameCode = array_map(static fn (array $r): array => ['id' => $r['case_uuid'], 'code' => $r['case_code'], 'status' => $r['status'], 'sourceName' => $r['source_customer_name'], 'legalName' => $r['legal_name'], 'phone' => $r['phone'], 'line' => $r['line_no'] === null ? null : (int) $r['line_no'], 'fileName' => $r['file_name']], $s->fetchAll(PDO::FETCH_ASSOC));
        }
        $s = $pdo->prepare('SELECT r.line_no, b.file_name, b.created_at FROM finance_import_rows r JOIN finance_import_batches b ON b.batch_uuid = r.batch_uuid WHERE r.case_uuid = ? AND r.row_uuid <> COALESCE(?, \'\') ORDER BY b.created_at LIMIT 25');
        $s->execute([$caseId, $case['import_row_uuid']]);
        $otherRows = array_map(static fn (array $r): array => ['line' => (int) $r['line_no'], 'fileName' => $r['file_name'], 'importedAt' => FinanceStore::iso($r['created_at'])], $s->fetchAll(PDO::FETCH_ASSOC));
        $s = $pdo->prepare('SELECT kind, COUNT(*) n FROM finance_evidence_refs WHERE entity_type = \'case\' AND entity_uuid = ? GROUP BY kind');
        $s->execute([$caseId]);
        $evidence = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $evidence[$r['kind']] = (int) $r['n'];
        }
        $relationship = null;
        if ($case['company_uuid'] !== null) {
            $s = $pdo->prepare('SELECT COUNT(*) orders, MAX(created_at) last_order FROM b2b_orders WHERE company_uuid = ?');
            $s->execute([$case['company_uuid']]);
            $orders = $s->fetch(PDO::FETCH_ASSOC);
            $s = $pdo->prepare('SELECT COUNT(*) FROM finance_company_cases WHERE company_uuid = ? AND case_uuid <> ?');
            $s->execute([$case['company_uuid'], $caseId]);
            $relationship = ['orders' => (int) $orders['orders'], 'lastOrderAt' => FinanceStore::iso($orders['last_order']), 'otherCases' => (int) $s->fetchColumn(), 'movements' => null];
            if (A::can($this->store->authorization(), $actor, A::ACCOUNTS_VIEW)) {
                $s = $pdo->prepare('SELECT currency_code, COUNT(*) n, MIN(value_date) first_date, MAX(value_date) last_date FROM b2b_account_movements WHERE company_uuid = ? GROUP BY currency_code ORDER BY currency_code');
                $s->execute([$case['company_uuid']]);
                $relationship['movements'] = array_map(static fn (array $r): array => ['currency' => $r['currency_code'], 'count' => (int) $r['n'], 'firstValueDate' => $r['first_date'], 'lastValueDate' => $r['last_date']], $s->fetchAll(PDO::FETCH_ASSOC));
            }
        }
        return ['sameCode' => $sameCode, 'otherSourceRows' => $otherRows, 'evidenceByKind' => (object) $evidence, 'relationship' => $relationship];
    }

    /** @param list<string> $caseIds @return array<string, list<string>> */
    private function openFlags(array $caseIds): array
    {
        if ($caseIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($caseIds), '?'));
        $s = $this->store->pdo()->prepare("SELECT case_uuid, flag_code FROM finance_case_flags WHERE status = 'open' AND case_uuid IN ({$marks}) ORDER BY flag_code");
        $s->execute($caseIds);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['case_uuid']][] = $r['flag_code'];
        }
        return $out;
    }

    private function employeeName(string $employee): ?string
    {
        $s = $this->store->pdo()->prepare('SELECT display_name FROM employees WHERE employee_uuid = ?');
        $s->execute([$employee]);
        return $s->fetchColumn() ?: null;
    }

    /** Actions the current employee may take on the case now (the server enforces each one again). @return list<string> */
    private function actions(EmployeeIdentity $actor, array $case): array
    {
        $auth = $this->store->authorization();
        $review = A::can($auth, $actor, A::COMPANIES_REVIEW);
        $verify = A::can($auth, $actor, A::COMPANIES_VERIFY);
        $dupes = A::can($auth, $actor, A::COMPANIES_RESOLVE_DUPLICATES);
        $supervise = A::can($auth, $actor, A::COMPANIES_SUPERVISE);
        $recused = $this->cases->isRecused((string) $case['case_uuid'], $actor->employeeUuid);
        $mine = $case['status'] === 'IN_REVIEW' && $case['assigned_employee_uuid'] === $actor->employeeUuid;
        $out = [];
        if ($review || $verify || $supervise) {
            $out[] = 'comment';
            if (!$recused) {
                array_push($out, 'declare_conflict', 'request_supervision');
            }
        }
        if ($supervise) {
            $out[] = 'recuse_employee';
        }
        if ($recused) {
            return array_values($out);
        }
        if ($review && in_array($case['status'], CaseWorkflow::OPEN_QUEUE, true)) {
            $out[] = 'claim';
        }
        if ($review && $mine) {
            array_push($out, 'save', 'checklist', 'release', 'request_information', 'mark_duplicate', 'submit', 'resolve_flag');
        }
        if ($dupes && $case['status'] === 'IN_REVIEW') {
            array_push($out, 'resolve_duplicate', 'link_company');
        }
        if ($verify && $case['status'] === 'READY_FOR_SECOND_CHECK' && $case['submitted_by_employee_uuid'] !== $actor->employeeUuid && !$this->cases->editedThisCycle((string) $case['case_uuid'], $actor->employeeUuid)
            && ($case['supervision_status'] !== 'required' || $supervise)) {
            array_push($out, 'approve', 'return', 'reject');
        }
        $mayClose = $case['supervision_status'] !== 'required' || $supervise;
        if ($verify && $mayClose && in_array($case['status'], ['NEEDS_REVIEW', 'NEEDS_INFORMATION', 'SUSPECTED_DUPLICATE', 'REOPENED', 'IMPORTED'], true)) {
            $out[] = 'reject';
        }
        if ($verify && $mayClose && in_array($case['status'], ['APPROVED', 'REJECTED'], true)) {
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
            'sourceCustomerName' => $r['source_customer_name'], 'sourceCustomerCode' => $r['source_customer_code'], 'priority' => $r['review_priority'], 'supervisionRequired' => $r['supervision_status'] === 'required',
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
