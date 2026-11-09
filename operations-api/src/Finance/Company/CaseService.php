<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Company;

use Arasya\Operations\B2B\CompanyInput;
use Arasya\Operations\B2B\CountryCodes;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Finance\FinanceAccess as A;
use Arasya\Operations\Finance\FinanceInput as In;
use Arasya\Operations\Finance\FinanceStore;
use Arasya\Operations\Finance\Import\ImportNormalizer;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Company verification cases: creation (import, manual, existing B2B), assignment, editing, checklist, duplicate
 * resolution, submission and the second-person decision. A case never edits an existing B2B company. Approval links
 * the case to a canonical company, or creates one (inactive, so B2B ordering is unaffected) only when the tax
 * identifier was verified with evidence; otherwise the case stays a verified prospect in staging.
 */
final readonly class CaseService
{
    /** Request field => [column, max length, multiline] */
    public const FIELDS = [
        'legalName' => ['legal_name', 255, false], 'commercialName' => ['commercial_name', 255, false], 'countryCode' => ['country_code', 2, false],
        'taxIdentifier' => ['tax_identifier', 64, false], 'vatNumber' => ['vat_number', 64, false], 'registrationNumber' => ['registration_number', 64, false],
        'addressLine' => ['address_line', 255, false], 'city' => ['city', 120, false], 'postalCode' => ['postal_code', 20, false], 'countyRegion' => ['county_region', 120, false],
        'contactName' => ['contact_name', 160, false], 'phone' => ['phone', 40, false], 'email' => ['email', 254, false], 'website' => ['website', 255, false],
        'legacyCustomerCode' => ['legacy_customer_code', 64, false], 'notes' => ['notes', 2000, true],
    ];

    /** A change to one of these, on a value already present, without a reason raises UNEXPLAINED_IDENTITY_CHANGE. */
    private const IDENTITY_FIELDS = ['legalName', 'countryCode', 'taxIdentifier', 'vatNumber', 'registrationNumber'];

    /** Case-editing actions: whoever performed one in the current review cycle cannot be the final verifier. */
    private const EDITING_ACTIONS = ['case_updated', 'checklist_updated', 'case_claimed', 'company_linked', 'duplicate_linked', 'duplicate_kept_separate', 'duplicate_dismissed', 'flag_resolved'];

    private DuplicateFinder $finder;
    private CaseFlags $flags;

    public function __construct(private FinanceStore $store)
    {
        $this->finder = new DuplicateFinder($store->pdo());
        $this->flags = new CaseFlags($store->pdo());
    }

    // ---- creation -------------------------------------------------------------------------------------------

    /**
     * Inserts a case from a normalized candidate and triages it (duplicates, completeness). Runs inside the caller's
     * transaction. @param array<string, mixed> $v ImportNormalizer values
     * @return array{caseId: string, code: string, caseStatus: string}
     */
    public function createCase(array $v, string $origin, string $source, ?string $importRow, ?string $fingerprint, ?string $originCompany, EmployeeIdentity $actor, string $now, string $request, ?string $key): array
    {
        $pdo = $this->store->pdo();
        $pdo->prepare('INSERT INTO finance_case_number_sequence (created_at) VALUES (?)')->execute([$now]);
        $number = (int) $pdo->lastInsertId();
        $id = Uuid::v4();
        $code = sprintf('FC-%06d', $number);
        $meta = [];
        foreach (array_keys(self::FIELDS) as $field) {
            if (($v[$field] ?? null) !== null) {
                $meta[$field] = ['by' => $actor->displayName, 'at' => FinanceStore::iso($now), 'source' => $source];
            }
        }
        $pdo->prepare('INSERT INTO finance_company_cases (case_uuid, case_number, case_code, origin, source_system, import_row_uuid, source_fingerprint, origin_company_uuid, company_uuid,
                source_customer_name, source_customer_code, legal_name, commercial_name, name_key, country_code, tax_identifier, tax_normalized, vat_number, vat_normalized, registration_number, registration_normalized,
                address_line, city, postal_code, county_region, contact_name, phone, phone_key, email, website, legacy_customer_code, declared_currency, declared_debit, declared_credit,
                last_transaction_date, notes, status, checklist_json, field_meta_json, created_by_employee_uuid, created_at, updated_by_employee_uuid, updated_at, version)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'IMPORTED\', ?, ?, ?, ?, ?, ?, 1)')
            ->execute([$id, $number, $code, $origin, $source, $importRow, $fingerprint, $originCompany, $originCompany,
                $v['sourceCustomerName'] ?? null, $v['sourceCustomerCode'] ?? null,
                $v['legalName'] ?? null, $v['commercialName'] ?? null, $v['nameKey'] ?? null, $v['countryCode'] ?? null, $v['taxIdentifier'] ?? null, $v['taxNormalized'] ?? null,
                $v['vatNumber'] ?? null, $v['vatNormalized'] ?? null, $v['registrationNumber'] ?? null, $v['registrationNormalized'] ?? null,
                $v['addressLine'] ?? null, $v['city'] ?? null, $v['postalCode'] ?? null, $v['countyRegion'] ?? null, $v['contactName'] ?? null, $v['phone'] ?? null, $v['phoneKey'] ?? null,
                $v['email'] ?? null, $v['website'] ?? null, $v['legacyCustomerCode'] ?? null,
                ($v['currency'] ?? null) !== null && preg_match('/^[A-Z]{3}$/D', (string) $v['currency']) === 1 ? $v['currency'] : null,
                $v['historicalDebit'] ?? null, $v['historicalCredit'] ?? null, $v['lastTransactionDate'] ?? null, $v['notes'] ?? null,
                FinanceStore::json(CaseWorkflow::emptyChecklist()), FinanceStore::json((object) $meta), $actor->employeeUuid, $now, $actor->employeeUuid, $now]);
        $this->store->event('case', $id, $originCompany, $actor, 'case_created', null, 1, null, ['origin' => $origin, 'source' => $source], $request, $key, $now);
        $strongest = $this->refreshDuplicates($id, $v, $originCompany, $now);
        foreach ($v['reviewFlags'] ?? [] as $flag) {
            $this->flags->raise($id, (string) $flag, 'import', null, $now);
        }
        $this->flags->detectShared($id, $now);
        $complete = ($v['legalName'] ?? null) !== null && ($v['countryCode'] ?? null) !== null && ($v['taxNormalized'] ?? null) !== null;
        $status = in_array($strongest, ['exact', 'strong'], true) ? 'SUSPECTED_DUPLICATE' : ($complete ? 'NEEDS_REVIEW' : 'NEEDS_INFORMATION');
        CaseWorkflow::allow('IMPORTED', $status);
        $pdo->prepare('UPDATE finance_company_cases SET status = ?, duplicate_review = ?, version = 2 WHERE case_uuid = ?')
            ->execute([$status, $strongest === 'none' ? 'not_started' : 'open', $id]);
        $this->store->event('case', $id, $originCompany, $actor, 'case_triaged', 1, 2, null, ['status' => $status, 'duplicates' => $strongest], $request, $key, $now);
        return ['caseId' => $id, 'code' => $code, 'caseStatus' => $status];
    }

    public function createManual(EmployeeIdentity $actor, array $input, string $key, string $request): array
    {
        A::require($this->store->authorization(), $actor, A::COMPANIES_REVIEW);
        In::only($input, array_keys(self::FIELDS));
        $v = self::candidate($input);
        if ($v['legalName'] === null) {
            In::fail('legalName', 'required');
        }
        return $this->store->mutate($actor, 'case_manual', $input, $key, [A::COMPANIES_REVIEW], function (string $now) use ($actor, $v, $key, $request): array {
            return ['status' => 201] + $this->createCase($v, 'MANUAL', 'MANUAL', null, null, null, $actor, $now, $request, $key);
        });
    }

    /**
     * Opens verification cases for existing B2B companies that have none yet, oldest first, at most 200 per call.
     * The B2B company is only read; its id becomes the case's canonical company.
     */
    public function sweepB2b(EmployeeIdentity $actor, array $input, string $key, string $request): array
    {
        In::only($input, ['limit']);
        $limit = $input['limit'] ?? 100;
        if (!is_int($limit) || $limit < 1 || $limit > 200) {
            In::fail('limit', 'invalid');
        }
        return $this->store->mutate($actor, 'case_sweep_b2b', $input, $key, [A::COMPANIES_REVIEW], function (string $now) use ($actor, $limit, $key, $request): array {
            $pdo = $this->store->pdo();
            // A company already covered by any case (opened from B2B, linked or created by an approval) is skipped.
            $s = $pdo->prepare('SELECT c.* FROM b2b_companies c WHERE NOT EXISTS (SELECT 1 FROM finance_company_cases f WHERE f.origin_company_uuid = c.company_uuid OR f.company_uuid = c.company_uuid)
                ORDER BY c.company_number LIMIT ' . $limit . ' FOR UPDATE');
            $s->execute();
            $created = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $company) {
                $created[] = $this->createCase($this->fromB2b($company), 'EXISTING_B2B', 'EXISTING_B2B', null, null, (string) $company['company_uuid'], $actor, $now, $request, $key)['caseId'];
            }
            $remaining = (int) $pdo->query('SELECT COUNT(*) FROM b2b_companies c WHERE NOT EXISTS (SELECT 1 FROM finance_company_cases f WHERE f.origin_company_uuid = c.company_uuid OR f.company_uuid = c.company_uuid)')->fetchColumn();
            return ['status' => 200, 'created' => count($created), 'caseIds' => $created, 'remaining' => $remaining];
        });
    }

    // ---- review --------------------------------------------------------------------------------------------

    public function claim(EmployeeIdentity $actor, string $caseId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion']);
        $version = In::version($input);
        return $this->store->mutate($actor, 'case_claim:' . $caseId, $input, $key, [A::COMPANIES_REVIEW], function (string $now) use ($actor, $caseId, $version, $key, $request): array {
            $case = $this->lockCase($caseId);
            FinanceStore::expectVersion($case, $version, 'CASE_CHANGED');
            $this->assertNotRecused($case, $actor);
            if ($case['status'] === 'IN_REVIEW') {
                throw new ApiException(409, 'CASE_ASSIGNED', 'The case is already being reviewed.', ['assignedTo' => $this->name($case['assigned_employee_uuid'])]);
            }
            CaseWorkflow::allow($case['status'], 'IN_REVIEW');
            $this->set($case, ['status' => 'IN_REVIEW', 'assigned_employee_uuid' => $actor->employeeUuid], $actor, $now);
            $this->store->event('case', $caseId, $case['company_uuid'], $actor, 'case_claimed', (int) $case['version'], (int) $case['version'] + 1, null, ['from' => $case['status']], $request, $key, $now);
            return ['status' => 200, 'caseId' => $caseId, 'version' => (int) $case['version'] + 1];
        });
    }

    public function release(EmployeeIdentity $actor, string $caseId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion']);
        $version = In::version($input);
        return $this->store->mutate($actor, 'case_release:' . $caseId, $input, $key, [A::COMPANIES_REVIEW], function (string $now) use ($actor, $caseId, $version, $key, $request): array {
            $case = $this->assigned($caseId, $actor, $version);
            $this->set($case, ['status' => 'NEEDS_REVIEW', 'assigned_employee_uuid' => null], $actor, $now);
            $this->store->event('case', $caseId, $case['company_uuid'], $actor, 'case_released', (int) $case['version'], (int) $case['version'] + 1, null, null, $request, $key, $now);
            return ['status' => 200, 'caseId' => $caseId, 'version' => (int) $case['version'] + 1];
        });
    }

    /** Saves edited fields (draft). Changed identity fields reopen their checklist items and rerun duplicate detection. */
    public function update(EmployeeIdentity $actor, string $caseId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'fields', 'reason', 'evidence']);
        $version = In::version($input);
        $reason = In::reason($input, 'reason', false);
        $evidence = In::evidence($input);
        $fields = $input['fields'] ?? null;
        if (!is_array($fields) || array_is_list($fields) && $fields !== []) {
            In::fail('fields', 'invalid');
        }
        In::only($fields, array_keys(self::FIELDS));
        return $this->store->mutate($actor, 'case_update:' . $caseId, $input, $key, [A::COMPANIES_REVIEW], function (string $now) use ($actor, $caseId, $version, $fields, $reason, $evidence, $key, $request): array {
            $case = $this->assigned($caseId, $actor, $version);
            $current = self::values($case);
            $next = self::candidate(array_merge(array_intersect_key($current, self::FIELDS), $fields), array_keys($fields));
            if ($next['legalName'] === null && $next['commercialName'] === null && $case['source_customer_name'] === null) {
                In::fail('legalName', 'required');
            }
            // The imported label stays the identity key until a registered name exists; it is never rewritten.
            $next['nameKey'] ??= $case['source_customer_name'] === null ? null : ImportNormalizer::nameKey((string) $case['source_customer_name']);
            if ($case['source_customer_code'] !== null && array_key_exists('legacyCustomerCode', $fields) && $next['legacyCustomerCode'] !== $current['legacyCustomerCode']) {
                throw new ApiException(409, 'SOURCE_CODE_IMMUTABLE', 'The imported customer code is source data and cannot be changed.');
            }
            $changed = [];
            foreach (array_keys(self::FIELDS) as $field) {
                if (array_key_exists($field, $fields) && ($current[$field] ?? null) !== $next[$field]) {
                    $changed[] = $field;
                }
            }
            if ($changed === []) {
                return ['status' => 200, 'caseId' => $caseId, 'version' => (int) $case['version'], 'changed' => []];
            }
            $checklist = self::decode($case['checklist_json']);
            $meta = self::decode($case['field_meta_json']);
            $reopen = [];
            foreach ($changed as $field) {
                $meta[$field] = ['by' => $actor->displayName, 'at' => FinanceStore::iso($now), 'source' => 'MANUAL'];
                foreach (CaseWorkflow::FIELD_ITEMS[$field] as $item) {
                    $reopen[$item] = true;
                }
            }
            foreach (array_keys($reopen) as $item) {
                $checklist[$item] = ['state' => 'open', 'reopenedBy' => $actor->displayName, 'reopenedAt' => FinanceStore::iso($now)];
            }
            $columns = [];
            foreach (self::FIELDS as $field => [$column]) {
                $columns[$column] = $next[$field];
            }
            $columns += ['name_key' => $next['nameKey'], 'tax_normalized' => $next['taxNormalized'], 'vat_normalized' => $next['vatNormalized'],
                'registration_normalized' => $next['registrationNormalized'], 'phone_key' => $next['phoneKey'],
                'checklist_json' => FinanceStore::json($checklist), 'field_meta_json' => FinanceStore::json((object) $meta)];
            if (isset($reopen['tax_id'])) {
                $columns['tax_status'] = 'unverified';
            }
            if (isset($reopen['duplicate_search'])) {
                $strongest = $this->refreshDuplicates($caseId, $next, $case['origin_company_uuid'], $now);
                $columns['duplicate_review'] = $this->openStrong($caseId) ? 'open' : ($strongest === 'none' ? 'not_started' : 'resolved');
            }
            $this->set($case, $columns, $actor, $now);
            $evidenceIds = $this->store->evidence('case', $caseId, $evidence, $actor, $now);
            foreach ($changed as $field) {
                $this->recordChange($caseId, $field, $current[$field] ?? null, $next[$field], (int) $case['version'] + 1, $reason, $evidenceIds, $actor, $request, $now);
                if ($reason === null && ($current[$field] ?? null) !== null && in_array($field, self::IDENTITY_FIELDS, true)) {
                    $this->flags->raise($caseId, CaseFlags::IDENTITY_CHANGE, 'detector', ['field' => $field], $now, true);
                }
            }
            if (isset($reopen['duplicate_search'])) {
                $this->flags->detectShared($caseId, $now);
            }
            $this->store->event('case', $caseId, $case['company_uuid'], $actor, 'case_updated', (int) $case['version'], (int) $case['version'] + 1, $reason, ['fields' => $changed, 'reopened' => array_keys($reopen), 'evidence' => count($evidenceIds)], $request, $key, $now);
            return ['status' => 200, 'caseId' => $caseId, 'version' => (int) $case['version'] + 1, 'changed' => $changed];
        });
    }

    /** Marks one checklist item. tax_id "done" requires evidence (registry extract, etc.) and is what sets the tax status to verified. */
    public function checklist(EmployeeIdentity $actor, string $caseId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'item', 'state', 'note', 'evidence']);
        $version = In::version($input);
        $item = (string) In::enum($input, 'item', CaseWorkflow::CHECKLIST);
        $state = (string) In::enum($input, 'state', CaseWorkflow::CHECKLIST_STATES);
        $note = In::text($input, 'note', 500);
        $evidence = In::evidence($input);
        if ($state === 'pending' && $item !== 'tax_id') {
            In::fail('state', 'pending_only_for_tax_id');
        }
        if ($state === 'na' && !in_array($item, ['contact', 'documents', 'address'], true)) {
            In::fail('state', 'not_applicable_not_allowed');
        }
        if ($state === 'na' && $note === null) {
            In::fail('note', 'required');
        }
        if ($item === 'tax_id' && $state === 'done' && $evidence === []) {
            In::fail('evidence', 'required_for_tax_verification');
        }
        return $this->store->mutate($actor, 'case_checklist:' . $caseId, $input, $key, [A::COMPANIES_REVIEW], function (string $now) use ($actor, $caseId, $version, $item, $state, $note, $evidence, $key, $request): array {
            $case = $this->assigned($caseId, $actor, $version);
            $columns = [];
            if ($item === 'legal_name' && $state === 'done' && $case['legal_name'] === null) {
                throw new ApiException(409, 'FIELD_MISSING', 'The legal name is missing.');
            }
            if ($item === 'country' && $state === 'done' && $case['country_code'] === null) {
                throw new ApiException(409, 'FIELD_MISSING', 'The country is missing.');
            }
            if ($item === 'tax_id') {
                if ($state === 'done' && ($case['tax_normalized'] === null || $case['country_code'] === null)) {
                    throw new ApiException(409, 'FIELD_MISSING', 'A tax identifier and country are required to verify it.');
                }
                if ($state === 'done' && $case['country_code'] === 'RO' && !ImportNormalizer::validRomanianCui((string) $case['tax_normalized'])) {
                    throw new ApiException(409, 'TAX_IDENTIFIER_INVALID', 'The Romanian tax identifier fails its control digit and cannot be marked verified.');
                }
                $columns['tax_status'] = match ($state) { 'done' => 'verified', 'pending' => 'pending', default => 'unverified' };
            }
            if ($item === 'duplicate_search' && $state === 'done') {
                $this->refreshDuplicates($caseId, self::values($case), $case['origin_company_uuid'], $now);
                if ($this->openStrong($caseId)) {
                    throw new ApiException(409, 'DUPLICATES_UNRESOLVED', 'Resolve the exact and strong duplicate candidates first.');
                }
                $columns['duplicate_review'] = 'resolved';
            }
            $checklist = self::decode($case['checklist_json']);
            $checklist[$item] = ['state' => $state, 'by' => $actor->displayName, 'at' => FinanceStore::iso($now), 'version' => (int) $case['version'] + 1, 'note' => $note];
            $columns['checklist_json'] = FinanceStore::json($checklist);
            $this->store->evidence('case', $caseId, $evidence, $actor, $now);
            $this->set($case, $columns, $actor, $now);
            $this->store->event('case', $caseId, $case['company_uuid'], $actor, 'checklist_updated', (int) $case['version'], (int) $case['version'] + 1, null, ['item' => $item, 'state' => $state, 'evidence' => count($evidence)], $request, $key, $now);
            return ['status' => 200, 'caseId' => $caseId, 'version' => (int) $case['version'] + 1];
        });
    }

    /** IN_REVIEW (assignee) or READY_FOR_SECOND_CHECK (verifier returns it) -> NEEDS_INFORMATION; or IN_REVIEW -> SUSPECTED_DUPLICATE. */
    public function setAside(EmployeeIdentity $actor, string $caseId, string $target, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = (string) In::reason($input);
        // The reviewer sets an own case aside (review permission); a verifier returns a submitted case (verify permission).
        return $this->store->mutate($actor, 'case_aside:' . $target . ':' . $caseId, $input, $key, [], function (string $now) use ($actor, $caseId, $target, $version, $reason, $key, $request): array {
            $case = $this->lockCase($caseId);
            FinanceStore::expectVersion($case, $version, 'CASE_CHANGED');
            if ($case['status'] === 'READY_FOR_SECOND_CHECK') {
                A::require($this->store->authorization(), $actor, A::COMPANIES_VERIFY);
                $this->assertNotRecused($case, $actor);
                $this->requireSupervisorWhenNeeded($case, $actor);
                $decision = 'returned';
            } else {
                A::require($this->store->authorization(), $actor, A::COMPANIES_REVIEW);
                if ($case['status'] !== 'IN_REVIEW' || $case['assigned_employee_uuid'] !== $actor->employeeUuid) {
                    throw new ApiException(409, 'CASE_NOT_ASSIGNED_TO_YOU', 'Take the case before changing it.');
                }
                $decision = $target === 'SUSPECTED_DUPLICATE' ? 'suspected_duplicate' : 'needs_information';
            }
            CaseWorkflow::allow($case['status'], $target);
            $this->set($case, ['status' => $target, 'status_reason' => $reason, 'assigned_employee_uuid' => null, 'submitted_by_employee_uuid' => null, 'submitted_at' => null, 'submitted_version' => null], $actor, $now);
            $this->decision($caseId, $decision, (int) $case['version'] + 1, $actor, $reason, null, $now);
            $this->store->event('case', $caseId, $case['company_uuid'], $actor, 'case_' . $decision, (int) $case['version'], (int) $case['version'] + 1, $reason, ['to' => $target], $request, $key, $now);
            return ['status' => 200, 'caseId' => $caseId, 'version' => (int) $case['version'] + 1];
        });
    }

    // ---- duplicates ----------------------------------------------------------------------------------------

    /** Human decision on one duplicate candidate: link, kept_separate, rejected_duplicate, dismissed (weak only). */
    public function resolveDuplicate(EmployeeIdentity $actor, string $caseId, string $candidateId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'decision', 'reason', 'evidence']);
        $version = In::version($input);
        $decision = (string) In::enum($input, 'decision', ['linked', 'kept_separate', 'rejected_duplicate', 'dismissed']);
        $reason = (string) In::reason($input);
        $evidence = In::evidence($input);
        return $this->store->mutate($actor, 'case_duplicate:' . $caseId . ':' . $candidateId, $input, $key, [A::COMPANIES_RESOLVE_DUPLICATES], function (string $now) use ($actor, $caseId, $candidateId, $version, $decision, $reason, $evidence, $key, $request): array {
            $case = $this->lockCase($caseId);
            FinanceStore::expectVersion($case, $version, 'CASE_CHANGED');
            $this->assertNotRecused($case, $actor);
            if ($case['status'] !== 'IN_REVIEW') {
                throw new ApiException(409, 'CASE_NOT_IN_REVIEW', 'Duplicates are resolved while the case is in review.');
            }
            $candidate = $this->store->lock('finance_duplicate_candidates', 'candidate_uuid', $candidateId, 'CANDIDATE_NOT_FOUND', 'Duplicate candidate was not found.');
            if ($candidate['case_uuid'] !== $caseId) {
                throw new ApiException(404, 'CANDIDATE_NOT_FOUND', 'Duplicate candidate was not found.');
            }
            if ($candidate['status'] !== 'open') {
                throw new ApiException(409, 'CANDIDATE_RESOLVED', 'The candidate was already resolved.');
            }
            if ($decision === 'dismissed' && $candidate['strength'] !== 'weak') {
                throw new ApiException(409, 'DECISION_NOT_ALLOWED', 'Exact and strong candidates need an explicit decision.');
            }
            $columns = [];
            if ($decision === 'linked') {
                $target = $candidate['match_company_uuid'];
                if ($target === null) {
                    $s = $this->store->pdo()->prepare('SELECT company_uuid FROM finance_company_cases WHERE case_uuid = ?');
                    $s->execute([$candidate['match_case_uuid']]);
                    $target = $s->fetchColumn() ?: null;
                    if ($target === null) {
                        throw new ApiException(409, 'MATCH_HAS_NO_CANONICAL_COMPANY', 'The matching case has no canonical company yet. Keep separate or return it for information.');
                    }
                }
                if ($case['company_uuid'] !== null && $case['company_uuid'] !== $target) {
                    throw new ApiException(409, 'CASE_ALREADY_LINKED', 'The case is already linked to a different company.');
                }
                $columns['company_uuid'] = $target;
            }
            $this->store->pdo()->prepare('UPDATE finance_duplicate_candidates SET status = ?, resolved_by_employee_uuid = ?, resolved_at = ?, resolution_reason = ? WHERE candidate_uuid = ?')
                ->execute([$decision, $actor->employeeUuid, $now, $reason, $candidateId]);
            $this->store->evidence('case', $caseId, $evidence, $actor, $now);
            if ($decision === 'rejected_duplicate') {
                CaseWorkflow::allow($case['status'], 'REJECTED');
                $columns += ['status' => 'REJECTED', 'status_reason' => $reason, 'assigned_employee_uuid' => null];
            }
            $columns['duplicate_review'] = $this->openStrong($caseId) ? 'open' : 'resolved';
            $this->set($case, $columns, $actor, $now);
            if (isset($columns['company_uuid']) && $columns['company_uuid'] !== $case['company_uuid']) {
                $this->afterLink($case, (string) $columns['company_uuid'], $reason, $actor, $request, $now);
            }
            $this->decision($caseId, $decision, (int) $case['version'] + 1, $actor, $reason, ['candidateId' => $candidateId, 'companyId' => $columns['company_uuid'] ?? null], $now);
            $this->store->event('case', $caseId, $columns['company_uuid'] ?? $case['company_uuid'], $actor, 'duplicate_' . $decision, (int) $case['version'], (int) $case['version'] + 1, $reason, ['strength' => $candidate['strength']], $request, $key, $now);
            return ['status' => 200, 'caseId' => $caseId, 'version' => (int) $case['version'] + 1];
        });
    }

    /** Links the case to a chosen existing company (search result) when detection did not offer it. */
    public function linkCompany(EmployeeIdentity $actor, string $caseId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'companyId', 'reason', 'evidence']);
        $version = In::version($input);
        $companyId = In::uuid($input, 'companyId');
        $reason = (string) In::reason($input);
        $evidence = In::evidence($input);
        return $this->store->mutate($actor, 'case_link:' . $caseId, $input, $key, [A::COMPANIES_RESOLVE_DUPLICATES], function (string $now) use ($actor, $caseId, $version, $companyId, $reason, $evidence, $key, $request): array {
            $case = $this->lockCase($caseId);
            FinanceStore::expectVersion($case, $version, 'CASE_CHANGED');
            $this->assertNotRecused($case, $actor);
            if ($case['status'] !== 'IN_REVIEW') {
                throw new ApiException(409, 'CASE_NOT_IN_REVIEW', 'Link the company while the case is in review.');
            }
            $s = $this->store->pdo()->prepare('SELECT company_uuid FROM b2b_companies WHERE company_uuid = ?');
            $s->execute([$companyId]);
            if ($s->fetchColumn() === false) {
                throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
            }
            if ($case['company_uuid'] !== null && $case['company_uuid'] !== $companyId) {
                throw new ApiException(409, 'CASE_ALREADY_LINKED', 'The case is already linked to a different company.');
            }
            $this->store->pdo()->prepare("INSERT INTO finance_duplicate_candidates (candidate_uuid, case_uuid, match_company_uuid, match_case_uuid, strength, indicators, status, resolved_by_employee_uuid, resolved_at, resolution_reason, created_at)
                VALUES (?, ?, ?, NULL, 'weak', ?, 'linked', ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = 'linked', resolved_by_employee_uuid = VALUES(resolved_by_employee_uuid), resolved_at = VALUES(resolved_at), resolution_reason = VALUES(resolution_reason)")
                ->execute([Uuid::v4(), $caseId, $companyId, FinanceStore::json(['manual']), $actor->employeeUuid, $now, $reason, $now]);
            $this->store->evidence('case', $caseId, $evidence, $actor, $now);
            $this->set($case, ['company_uuid' => $companyId, 'duplicate_review' => $this->openStrong($caseId) ? 'open' : 'resolved'], $actor, $now);
            if ($case['company_uuid'] !== $companyId) {
                $this->afterLink($case, $companyId, $reason, $actor, $request, $now);
            }
            $this->decision($caseId, 'linked', (int) $case['version'] + 1, $actor, $reason, ['companyId' => $companyId], $now);
            $this->store->event('case', $caseId, $companyId, $actor, 'company_linked', (int) $case['version'], (int) $case['version'] + 1, $reason, null, $request, $key, $now);
            return ['status' => 200, 'caseId' => $caseId, 'version' => (int) $case['version'] + 1];
        });
    }

    // ---- four-eyes -----------------------------------------------------------------------------------------

    public function submit(EmployeeIdentity $actor, string $caseId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion']);
        $version = In::version($input);
        return $this->store->mutate($actor, 'case_submit:' . $caseId, $input, $key, [A::COMPANIES_REVIEW], function (string $now) use ($actor, $caseId, $version, $key, $request): array {
            $case = $this->assigned($caseId, $actor, $version);
            if ($case['legal_name'] === null || $case['country_code'] === null) {
                throw new ApiException(409, 'FIELD_MISSING', 'Legal name and country are required before submission.');
            }
            $missing = CaseWorkflow::incomplete(self::decode($case['checklist_json']));
            if ($missing !== []) {
                throw new ApiException(409, 'CHECKLIST_INCOMPLETE', 'Complete the verification checklist first.', ['items' => $missing]);
            }
            if ($this->openStrong($caseId)) {
                throw new ApiException(409, 'DUPLICATES_UNRESOLVED', 'Resolve the exact and strong duplicate candidates first.');
            }
            if ($this->flags->hasUnresolvedPassiveClaim($caseId)) {
                throw new ApiException(409, 'PASSIVE_CLAIM_UNRESOLVED', 'The employee-reported passive claim has not been independently verified.');
            }
            CaseWorkflow::allow('IN_REVIEW', 'READY_FOR_SECOND_CHECK');
            $next = (int) $case['version'] + 1;
            $this->set($case, ['status' => 'READY_FOR_SECOND_CHECK', 'assigned_employee_uuid' => null, 'submitted_by_employee_uuid' => $actor->employeeUuid, 'submitted_at' => $now, 'submitted_version' => $next, 'status_reason' => null], $actor, $now);
            $this->decision($caseId, 'submitted', $next, $actor, null, null, $now);
            $this->store->event('case', $caseId, $case['company_uuid'], $actor, 'case_submitted', (int) $case['version'], $next, null, null, $request, $key, $now);
            return ['status' => 200, 'caseId' => $caseId, 'version' => $next];
        });
    }

    /**
     * Second-person approval of exactly the submitted version. The approver must hold finance.companies.verify and
     * must not be the submitter or anyone who edited the case in this review cycle.
     */
    public function approve(EmployeeIdentity $actor, string $caseId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = In::reason($input, 'reason', false);
        return $this->store->mutate($actor, 'case_approve:' . $caseId, $input, $key, [A::COMPANIES_VERIFY], function (string $now) use ($actor, $caseId, $version, $reason, $key, $request): array {
            $case = $this->lockCase($caseId);
            FinanceStore::expectVersion($case, $version, 'CASE_CHANGED');
            CaseWorkflow::allow($case['status'], 'APPROVED');
            if ((int) $case['submitted_version'] !== (int) $case['version']) {
                throw new ApiException(409, 'APPROVAL_STALE', 'The case changed after submission.');
            }
            $this->requireSecondPerson($case, $actor);
            if ($this->flags->hasUnresolvedPassiveClaim($caseId)) {
                throw new ApiException(409, 'PASSIVE_CLAIM_UNRESOLVED', 'The employee-reported passive claim has not been independently verified.');
            }
            $companyId = $case['company_uuid'];
            $canonical = 'linked';
            if ($companyId === null) {
                if ($case['tax_status'] === 'verified') {
                    if ($case['country_code'] === 'RO' && !ImportNormalizer::validRomanianCui((string) $case['tax_normalized'])) {
                        throw new ApiException(409, 'TAX_IDENTIFIER_INVALID', 'The Romanian tax identifier fails its control digit.');
                    }
                    $companyId = $this->createCanonical($case, $actor, $now, $request, $key);
                    $canonical = 'created_inactive';
                } else {
                    $canonical = 'staging_prospect';
                }
            }
            $next = (int) $case['version'] + 1;
            $this->set($case, ['status' => 'APPROVED', 'company_uuid' => $companyId, 'approved_by_employee_uuid' => $actor->employeeUuid, 'approved_at' => $now, 'approved_version' => $next, 'status_reason' => $reason], $actor, $now);
            if ($companyId !== null) {
                $this->flags->detectSameCompany((string) $companyId, $now);
            }
            $this->decision($caseId, 'approved', $next, $actor, $reason, ['canonical' => $canonical, 'companyId' => $companyId], $now);
            $this->store->event('case', $caseId, $companyId, $actor, 'case_approved', (int) $case['version'], $next, $reason, ['canonical' => $canonical], $request, $key, $now);
            return ['status' => 200, 'caseId' => $caseId, 'version' => $next, 'companyId' => $companyId, 'canonical' => $canonical];
        });
    }

    public function reject(EmployeeIdentity $actor, string $caseId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = (string) In::reason($input);
        return $this->store->mutate($actor, 'case_reject:' . $caseId, $input, $key, [A::COMPANIES_VERIFY], function (string $now) use ($actor, $caseId, $version, $reason, $key, $request): array {
            $case = $this->lockCase($caseId);
            FinanceStore::expectVersion($case, $version, 'CASE_CHANGED');
            CaseWorkflow::allow($case['status'], 'REJECTED');
            if ($case['status'] === 'READY_FOR_SECOND_CHECK') {
                $this->requireSecondPerson($case, $actor);
            }
            if ($case['status'] === 'IN_REVIEW' && $case['assigned_employee_uuid'] !== $actor->employeeUuid) {
                throw new ApiException(409, 'CASE_ASSIGNED', 'The case is being reviewed by someone else.');
            }
            $this->assertNotRecused($case, $actor);
            $this->requireSupervisorWhenNeeded($case, $actor);
            $next = (int) $case['version'] + 1;
            $this->set($case, ['status' => 'REJECTED', 'status_reason' => $reason, 'assigned_employee_uuid' => null], $actor, $now);
            $this->decision($caseId, 'rejected', $next, $actor, $reason, null, $now);
            $this->store->event('case', $caseId, $case['company_uuid'], $actor, 'case_rejected', (int) $case['version'], $next, $reason, null, $request, $key, $now);
            return ['status' => 200, 'caseId' => $caseId, 'version' => $next];
        });
    }

    /** APPROVED or REJECTED -> REOPENED. The earlier approval no longer counts; nothing in B2B is changed. */
    public function reopen(EmployeeIdentity $actor, string $caseId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = (string) In::reason($input);
        return $this->store->mutate($actor, 'case_reopen:' . $caseId, $input, $key, [A::COMPANIES_VERIFY], function (string $now) use ($actor, $caseId, $version, $reason, $key, $request): array {
            $case = $this->lockCase($caseId);
            FinanceStore::expectVersion($case, $version, 'CASE_CHANGED');
            $this->assertNotRecused($case, $actor);
            $this->requireSupervisorWhenNeeded($case, $actor);
            CaseWorkflow::allow($case['status'], 'REOPENED');
            $next = (int) $case['version'] + 1;
            $this->set($case, ['status' => 'REOPENED', 'status_reason' => $reason, 'approved_by_employee_uuid' => null, 'approved_at' => null, 'approved_version' => null,
                'submitted_by_employee_uuid' => null, 'submitted_at' => null, 'submitted_version' => null], $actor, $now);
            $this->decision($caseId, 'reopened', $next, $actor, $reason, null, $now);
            $this->store->event('case', $caseId, $case['company_uuid'], $actor, 'case_reopened', (int) $case['version'], $next, $reason, ['from' => $case['status']], $request, $key, $now);
            return ['status' => 200, 'caseId' => $caseId, 'version' => $next];
        });
    }

    // ---- review flags, conflicts of interest, comments ---------------------------------------------------------

    /**
     * Records the outcome of reviewing one flag. The flag and the original source text stay on file; resolving a passive
     * claim changes no company status, balance, risk colour or limit. A passive claim needs evidence.
     */
    public function resolveFlag(EmployeeIdentity $actor, string $caseId, string $flagId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'resolution', 'note', 'evidence']);
        $version = In::version($input);
        $note = (string) In::reason($input, 'note');
        $evidence = In::evidence($input);
        $resolution = (string) In::enum($input, 'resolution', [...CaseFlags::PASSIVE_OUTCOMES, ...CaseFlags::REVIEW_OUTCOMES]);
        return $this->store->mutate($actor, 'case_flag:' . $caseId . ':' . $flagId, $input, $key, [A::COMPANIES_REVIEW], function (string $now) use ($actor, $caseId, $flagId, $version, $note, $evidence, $resolution, $key, $request): array {
            $case = $this->assigned($caseId, $actor, $version);
            $flag = $this->store->lock('finance_case_flags', 'flag_uuid', $flagId, 'FLAG_NOT_FOUND', 'Review flag was not found.');
            if ($flag['case_uuid'] !== $caseId) {
                throw new ApiException(404, 'FLAG_NOT_FOUND', 'Review flag was not found.');
            }
            $passive = $flag['flag_code'] === CaseFlags::PASSIVE_CLAIM;
            if (!in_array($resolution, $passive ? CaseFlags::PASSIVE_OUTCOMES : CaseFlags::REVIEW_OUTCOMES, true)) {
                In::fail('resolution', 'not_allowed_for_flag');
            }
            if ($passive && $evidence === [] && $resolution !== 'UNDETERMINED') {
                In::fail('evidence', 'required_for_passive_claim');
            }
            $this->store->pdo()->prepare("UPDATE finance_case_flags SET status = 'resolved', resolution = ?, resolution_note = ?, resolved_by_employee_uuid = ?, resolved_at = ? WHERE flag_uuid = ?")
                ->execute([$resolution, $note, $actor->employeeUuid, $now, $flagId]);
            $this->store->evidence('case', $caseId, $evidence, $actor, $now);
            $this->flags->refreshPriority($caseId);
            $this->set($case, [], $actor, $now);
            $this->store->event('case', $caseId, $case['company_uuid'], $actor, 'flag_resolved', (int) $case['version'], (int) $case['version'] + 1, $note, ['flag' => $flag['flag_code'], 'resolution' => $resolution, 'evidence' => count($evidence)], $request, $key, $now);
            return ['status' => 200, 'caseId' => $caseId, 'version' => (int) $case['version'] + 1];
        });
    }

    /**
     * Routes a case to independent supervisory review. self_conflict: the actor steps away from the case.
     * request_supervision: nobody steps away. recuse_other: a supervisor names another employee. Neutral precaution, not a finding.
     */
    public function supervision(EmployeeIdentity $actor, string $caseId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'declaration', 'reason', 'employeeId']);
        $version = In::version($input);
        $declaration = (string) In::enum($input, 'declaration', ['self_conflict', 'request_supervision', 'recuse_other']);
        $reason = (string) In::reason($input);
        $employee = $declaration === 'recuse_other' ? In::uuid($input, 'employeeId') : ($declaration === 'self_conflict' ? $actor->employeeUuid : null);
        return $this->store->mutate($actor, 'case_supervision:' . $caseId, $input, $key, [], function (string $now) use ($actor, $caseId, $version, $declaration, $reason, $employee, $key, $request): array {
            $auth = $this->store->authorization();
            if ($declaration === 'recuse_other') {
                A::require($auth, $actor, A::COMPANIES_SUPERVISE);
            } elseif (!A::can($auth, $actor, A::COMPANIES_REVIEW) && !A::can($auth, $actor, A::COMPANIES_VERIFY) && !A::can($auth, $actor, A::COMPANIES_SUPERVISE)) {
                A::require($auth, $actor, A::COMPANIES_REVIEW);
            }
            $case = $this->lockCase($caseId);
            FinanceStore::expectVersion($case, $version, 'CASE_CHANGED');
            if ($employee !== null) {
                $s = $this->store->pdo()->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ?');
                $s->execute([$employee]);
                if ($s->fetchColumn() === false) {
                    throw new ApiException(404, 'EMPLOYEE_NOT_FOUND', 'Employee was not found.');
                }
                $this->store->pdo()->prepare('INSERT INTO finance_case_recusals (recusal_uuid, case_uuid, employee_uuid, reason, declared_by_employee_uuid, declared_at) VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE recusal_uuid = recusal_uuid')->execute([Uuid::v4(), $caseId, $employee, $reason, $actor->employeeUuid, $now]);
            }
            $columns = ['supervision_status' => 'required'];
            if ($employee !== null && $case['status'] === 'IN_REVIEW' && $case['assigned_employee_uuid'] === $employee) {
                $columns += ['status' => 'NEEDS_REVIEW', 'assigned_employee_uuid' => null];
            }
            $this->set($case, $columns, $actor, $now);
            $this->store->event('case', $caseId, $case['company_uuid'], $actor, 'supervision_requested', (int) $case['version'], (int) $case['version'] + 1, null, ['declaration' => $declaration], $request, $key, $now);
            return ['status' => 200, 'caseId' => $caseId, 'version' => (int) $case['version'] + 1];
        });
    }

    public function comment(EmployeeIdentity $actor, string $caseId, array $input, string $key, string $request): array
    {
        In::only($input, ['body']);
        $body = (string) In::text($input, 'body', 2000, true, true);
        return $this->store->mutate($actor, 'case_comment:' . $caseId, $input, $key, [], function (string $now) use ($actor, $caseId, $body, $key, $request): array {
            $auth = $this->store->authorization();
            if (!A::can($auth, $actor, A::COMPANIES_REVIEW) && !A::can($auth, $actor, A::COMPANIES_VERIFY) && !A::can($auth, $actor, A::COMPANIES_SUPERVISE)) {
                A::require($auth, $actor, A::COMPANIES_REVIEW);
            }
            $case = $this->store->lock('finance_company_cases', 'case_uuid', $caseId, 'CASE_NOT_FOUND', 'Verification case was not found.');
            $id = Uuid::v4();
            $this->store->pdo()->prepare('INSERT INTO finance_case_comments (comment_uuid, case_uuid, body, actor_employee_uuid, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$id, $caseId, $body, $actor->employeeUuid, $now]);
            $this->store->event('case', $caseId, $case['company_uuid'], $actor, 'comment_added', null, null, null, null, $request, $key, $now);
            return ['status' => 201, 'commentId' => $id];
        });
    }

    /** Has this employee edited the case in the current review cycle? Identity based, so switching roles changes nothing. */
    public function editedThisCycle(string $caseId, string $employee): bool
    {
        $pdo = $this->store->pdo();
        $s = $pdo->prepare("SELECT MAX(decided_at) FROM finance_case_decisions WHERE case_uuid = ? AND decision = 'reopened'");
        $s->execute([$caseId]);
        $since = $s->fetchColumn() ?: '1970-01-01 00:00:00';
        $marks = implode(',', array_fill(0, count(self::EDITING_ACTIONS), '?'));
        $s = $pdo->prepare("SELECT COUNT(*) FROM finance_activity_events WHERE entity_type = 'case' AND entity_uuid = ? AND actor_employee_uuid = ? AND action IN ({$marks}) AND occurred_at >= ?");
        $s->execute([$caseId, $employee, ...self::EDITING_ACTIONS, $since]);
        return (int) $s->fetchColumn() > 0;
    }

    public function isRecused(string $caseId, string $employee): bool
    {
        $s = $this->store->pdo()->prepare('SELECT COUNT(*) FROM finance_case_recusals WHERE case_uuid = ? AND employee_uuid = ?');
        $s->execute([$caseId, $employee]);
        return (int) $s->fetchColumn() > 0;
    }

    private function assertNotRecused(array $case, EmployeeIdentity $actor): void
    {
        if ($this->isRecused((string) $case['case_uuid'], $actor->employeeUuid)) {
            throw new ApiException(403, 'CONFLICT_OF_INTEREST', 'You are recused from this case. It needs independent review.');
        }
    }

    private function requireSupervisorWhenNeeded(array $case, EmployeeIdentity $actor): void
    {
        if ($case['supervision_status'] === 'required' && !A::can($this->store->authorization(), $actor, A::COMPANIES_SUPERVISE)) {
            throw new ApiException(403, 'SUPERVISOR_REQUIRED', 'This case needs an independent supervisory reviewer.');
        }
    }

    /** Old and new values go to the access-restricted history only. */
    private function recordChange(string $caseId, string $field, mixed $old, mixed $new, int $version, ?string $reason, array $evidenceIds, EmployeeIdentity $actor, string $request, string $now): void
    {
        $this->store->pdo()->prepare('INSERT INTO finance_case_field_changes (change_uuid, case_uuid, field_name, old_value, new_value, case_version, reason, evidence_ids, actor_employee_uuid, request_id, changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([Uuid::v4(), $caseId, $field, $old === null ? null : (string) $old, $new === null ? null : (string) $new, $version, $reason, $evidenceIds === [] ? null : FinanceStore::json($evidenceIds), $actor->employeeUuid, substr($request, 0, 100), $now]);
    }

    private function afterLink(array $case, string $companyId, ?string $reason, EmployeeIdentity $actor, string $request, string $now): void
    {
        $caseId = (string) $case['case_uuid'];
        $this->recordChange($caseId, 'companyId', $case['company_uuid'], $companyId, (int) $case['version'] + 1, $reason, [], $actor, $request, $now);
        $s = $this->store->pdo()->prepare('SELECT country_code, tax_identifier_normalized FROM b2b_companies WHERE company_uuid = ?');
        $s->execute([$companyId]);
        $company = $s->fetch(PDO::FETCH_ASSOC);
        if ($company && (($case['country_code'] !== null && $case['country_code'] !== $company['country_code'])
            || ($case['tax_normalized'] !== null && $case['tax_normalized'] !== $company['tax_identifier_normalized']))) {
            $this->flags->raise($caseId, CaseFlags::CONFLICTING_SOURCE, 'detector', ['against' => 'canonical_company'], $now, true);
        }
        $this->flags->detectSameCompany($companyId, $now);
    }

    // ---- internals -----------------------------------------------------------------------------------------

    /** Re-runs detection and stores new candidates (resolved ones are kept). Returns the strongest open-or-new strength. */
    public function refreshDuplicates(string $caseId, array $v, ?string $excludeCompany, string $now): string
    {
        $matches = array_values(array_filter($this->finder->find($v, $caseId), static fn (array $m): bool => $m['companyId'] === null || $m['companyId'] !== $excludeCompany));
        $pdo = $this->store->pdo();
        $upsert = $pdo->prepare("INSERT INTO finance_duplicate_candidates (candidate_uuid, case_uuid, match_company_uuid, match_case_uuid, strength, indicators, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'open', ?)
            ON DUPLICATE KEY UPDATE strength = IF(status = 'open', VALUES(strength), strength), indicators = IF(status = 'open', VALUES(indicators), indicators)");
        foreach ($matches as $m) {
            $upsert->execute([Uuid::v4(), $caseId, $m['companyId'], $m['caseId'], $m['strength'], FinanceStore::json($m['indicators']), $now]);
            // The other open case learns about this one too, so neither reviewer can miss the pair.
            if ($m['caseId'] !== null && $m['strength'] !== 'weak') {
                $upsert->execute([Uuid::v4(), $m['caseId'], null, $caseId, $m['strength'], FinanceStore::json($m['indicators']), $now]);
                $pdo->prepare("UPDATE finance_company_cases SET duplicate_review = 'open' WHERE case_uuid = ? AND duplicate_review <> 'open' AND status NOT IN ('APPROVED', 'REJECTED')")->execute([$m['caseId']]);
            }
        }
        return DuplicateFinder::strongest($matches);
    }

    private function openStrong(string $caseId): bool
    {
        $s = $this->store->pdo()->prepare("SELECT COUNT(*) FROM finance_duplicate_candidates WHERE case_uuid = ? AND status = 'open' AND strength IN ('exact', 'strong')");
        $s->execute([$caseId]);
        return (int) $s->fetchColumn() > 0;
    }

    private function requireSecondPerson(array $case, EmployeeIdentity $actor): void
    {
        $this->assertNotRecused($case, $actor);
        $this->requireSupervisorWhenNeeded($case, $actor);
        if ($case['submitted_by_employee_uuid'] === $actor->employeeUuid) {
            throw new ApiException(403, 'FOUR_EYES_REQUIRED', 'The person who submitted the case cannot be its final verifier.');
        }
        if ($this->editedThisCycle((string) $case['case_uuid'], $actor->employeeUuid)) {
            throw new ApiException(403, 'FOUR_EYES_REQUIRED', 'An employee who edited this case in the current review cycle cannot be its final verifier.');
        }
    }

    /** Creates the canonical B2B company of an approved case (inactive: B2B ordering is a separate decision). */
    private function createCanonical(array $case, EmployeeIdentity $actor, string $now, string $request, string $key): string
    {
        $pdo = $this->store->pdo();
        $s = $pdo->prepare('SELECT company_uuid FROM b2b_companies WHERE country_code = ? AND tax_identifier_normalized = ? FOR UPDATE');
        $s->execute([$case['country_code'], $case['tax_normalized']]);
        $existing = $s->fetchColumn();
        if ($existing !== false) {
            throw new ApiException(409, 'CANONICAL_COMPANY_EXISTS', 'A company with this tax identifier already exists. Return the case and link it instead.', ['companyId' => (string) $existing]);
        }
        $pdo->prepare('INSERT INTO b2b_company_number_sequence (created_at) VALUES (?)')->execute([$now]);
        $number = (int) $pdo->lastInsertId();
        $id = Uuid::v4();
        $pdo->prepare("INSERT INTO b2b_companies (company_uuid, company_number, company_code, legal_name, display_name, country_code, tax_identifier, tax_identifier_normalized,
                vat_number, registration_number, website, internal_notes, status, status_changed_at, created_at, updated_at, created_by_employee_uuid, updated_by_employee_uuid, version)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'inactive', ?, ?, ?, ?, ?, 1)")
            ->execute([$id, $number, sprintf('B2B-%06d', $number), $case['legal_name'], $case['commercial_name'], $case['country_code'], $case['tax_identifier'], $case['tax_normalized'],
                $case['vat_number'], $case['registration_number'], $case['website'] !== null && preg_match('#^https?://#i', (string) $case['website']) === 1 ? $case['website'] : null,
                $now, $now, $now, $actor->employeeUuid, $actor->employeeUuid]);
        $fields = ['legalName', 'displayName', 'countryCode', 'taxIdentifier', 'vatNumber', 'registrationNumber'];
        $this->b2bEvent($id, $actor, 'company_created', 'company', $id, $fields, $request, $key, $now);
        if ($case['address_line'] !== null && $case['city'] !== null) {
            $address = Uuid::v4();
            $pdo->prepare("INSERT INTO b2b_company_addresses (address_uuid, company_uuid, address_type, label, country_code, county_region, city, postal_code, address_line_1, address_line_2,
                    is_primary, primary_company_uuid, primary_address_type, status, created_at, updated_at, created_by_employee_uuid, updated_by_employee_uuid, version)
                VALUES (?, ?, 'billing', NULL, ?, ?, ?, ?, ?, NULL, 1, ?, 'billing', 'active', ?, ?, ?, ?, 1)")
                ->execute([$address, $id, $case['country_code'], $case['county_region'], $case['city'], $case['postal_code'], $case['address_line'], $id, $now, $now, $actor->employeeUuid, $actor->employeeUuid]);
            $this->b2bEvent($id, $actor, 'address_created', 'address', $address, ['addressType', 'countryCode', 'city', 'addressLine1'], $request, $key, $now);
        }
        if ($case['contact_name'] !== null) {
            $contact = Uuid::v4();
            $pdo->prepare("INSERT INTO b2b_company_contacts (contact_uuid, company_uuid, full_name, job_title, email, phone, is_primary, primary_company_uuid, status, created_at, updated_at,
                    created_by_employee_uuid, updated_by_employee_uuid, version) VALUES (?, ?, ?, NULL, ?, ?, 1, ?, 'active', ?, ?, ?, ?, 1)")
                ->execute([$contact, $id, $case['contact_name'], $case['email'], $case['phone'], $id, $now, $now, $actor->employeeUuid, $actor->employeeUuid]);
            $this->b2bEvent($id, $actor, 'contact_created', 'contact', $contact, ['fullName'], $request, $key, $now);
        }
        return $id;
    }

    private function b2bEvent(string $company, EmployeeIdentity $actor, string $action, string $subject, string $subjectId, array $fields, string $request, string $key, string $now): void
    {
        $this->store->pdo()->prepare('INSERT INTO b2b_company_activity_events (event_id, company_uuid, actor_employee_uuid, action, subject_type, subject_uuid, changed_fields, request_id, idempotency_key, occurred_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([Uuid::v4(), $company, $actor->employeeUuid, $action, $subject, $subjectId, FinanceStore::json($fields), substr($request, 0, 100), $key, $now]);
    }

    /** @return array<string, mixed> */
    private function fromB2b(array $company): array
    {
        $pdo = $this->store->pdo();
        $s = $pdo->prepare("SELECT full_name, email, phone FROM b2b_company_contacts WHERE company_uuid = ? AND status = 'active' ORDER BY is_primary DESC, created_at LIMIT 1");
        $s->execute([$company['company_uuid']]);
        $contact = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        $s = $pdo->prepare("SELECT address_line_1, city, postal_code, county_region FROM b2b_company_addresses WHERE company_uuid = ? AND status = 'active' ORDER BY address_type = 'billing' DESC, is_primary DESC, created_at LIMIT 1");
        $s->execute([$company['company_uuid']]);
        $address = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        return self::candidate([
            'legalName' => $company['legal_name'], 'commercialName' => $company['display_name'], 'countryCode' => $company['country_code'],
            'taxIdentifier' => $company['tax_identifier'], 'vatNumber' => $company['vat_number'], 'registrationNumber' => $company['registration_number'],
            'addressLine' => $address['address_line_1'] ?? null, 'city' => $address['city'] ?? null, 'postalCode' => $address['postal_code'] ?? null, 'countyRegion' => $address['county_region'] ?? null,
            'contactName' => $contact['full_name'] ?? null, 'phone' => $contact['phone'] ?? null, 'email' => $contact['email'] ?? null, 'website' => $company['website'],
        ]);
    }

    /** Validates request fields into a normalized candidate (same keys as ImportNormalizer). @return array<string, mixed> */
    public static function candidate(array $input, ?array $strict = null): array
    {
        // An unchanged imported tax identifier that does not normalize stays unnormalized instead of blocking other edits.
        $lenientTax = $strict !== null && !in_array('taxIdentifier', $strict, true) && !in_array('countryCode', $strict, true);
        $v = [];
        foreach (self::FIELDS as $field => [, $max, $multiline]) {
            $v[$field] = In::text($input, $field, $max, false, $multiline);
        }
        if ($v['countryCode'] !== null) {
            $v['countryCode'] = CountryCodes::normalize($v['countryCode']) ?? In::fail('countryCode', 'invalid');
        }
        if ($v['email'] !== null) {
            $v['email'] = strtolower($v['email']);
            if (filter_var($v['email'], FILTER_VALIDATE_EMAIL) === false) {
                In::fail('email', 'invalid');
            }
        }
        $v['taxNormalized'] = null;
        if ($v['taxIdentifier'] !== null && $v['countryCode'] !== null) {
            $v['taxNormalized'] = CompanyInput::normalizeTaxIdentifier($v['taxIdentifier'], $v['countryCode']) ?? ($lenientTax ? null : In::fail('taxIdentifier', 'invalid'));
        }
        $v['vatNormalized'] = $v['vatNumber'] === null ? null : (strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $v['vatNumber'])) ?: null);
        $v['registrationNormalized'] = $v['registrationNumber'] === null ? null : (strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $v['registrationNumber'])) ?: null);
        $v['phoneKey'] = ImportNormalizer::phoneKey($v['phone']);
        $name = $v['legalName'] ?? $v['commercialName'];
        $v['nameKey'] = $name === null ? null : ImportNormalizer::nameKey($name);
        return $v;
    }

    /** Case row => candidate keys. @return array<string, mixed> */
    public static function values(array $case): array
    {
        $v = [];
        foreach (self::FIELDS as $field => [$column]) {
            $v[$field] = $case[$column];
        }
        return $v + ['taxNormalized' => $case['tax_normalized'], 'vatNormalized' => $case['vat_normalized'], 'registrationNormalized' => $case['registration_normalized'],
            'phoneKey' => $case['phone_key'], 'nameKey' => $case['name_key']];
    }

    private function lockCase(string $caseId): array
    {
        return $this->store->lock('finance_company_cases', 'case_uuid', $caseId, 'CASE_NOT_FOUND', 'Verification case was not found.');
    }

    private function assigned(string $caseId, EmployeeIdentity $actor, int $version): array
    {
        $case = $this->lockCase($caseId);
        FinanceStore::expectVersion($case, $version, 'CASE_CHANGED');
        if ($case['status'] !== 'IN_REVIEW' || $case['assigned_employee_uuid'] !== $actor->employeeUuid) {
            throw new ApiException(409, 'CASE_NOT_ASSIGNED_TO_YOU', 'Take the case before changing it.');
        }
        return $case;
    }

    /** @param array<string, mixed> $columns */
    private function set(array $case, array $columns, EmployeeIdentity $actor, string $now): void
    {
        $columns += ['updated_by_employee_uuid' => $actor->employeeUuid, 'updated_at' => $now];
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($columns)));
        $s = $this->store->pdo()->prepare("UPDATE finance_company_cases SET {$sets}, version = version + 1 WHERE case_uuid = ? AND version = ?");
        $s->execute([...array_values($columns), $case['case_uuid'], $case['version']]);
        if ($s->rowCount() !== 1) {
            throw new ApiException(409, 'CASE_CHANGED', 'The case changed. Reload it before continuing.');
        }
    }

    private function decision(string $caseId, string $decision, int $version, EmployeeIdentity $actor, ?string $reason, ?array $refs, string $now): void
    {
        $this->store->pdo()->prepare('INSERT INTO finance_case_decisions (decision_uuid, case_uuid, decision, case_version, actor_employee_uuid, reason, source_refs, decided_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([Uuid::v4(), $caseId, $decision, $version, $actor->employeeUuid, $reason, $refs === null ? null : FinanceStore::json($refs), $now]);
    }

    private function name(?string $employee): ?string
    {
        if ($employee === null) {
            return null;
        }
        $s = $this->store->pdo()->prepare('SELECT display_name FROM employees WHERE employee_uuid = ?');
        $s->execute([$employee]);
        return ($s->fetchColumn() ?: null);
    }

    /** @return array<string, mixed> */
    public static function decode(?string $json): array
    {
        $value = $json === null ? [] : json_decode($json, true);
        return is_array($value) ? $value : [];
    }
}
