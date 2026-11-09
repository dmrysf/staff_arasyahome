-- Finance V0.3: company data import, human verification, current-account onboarding, risk and credit limits.
-- DRAFT ONLY; NOT RUN IN PRODUCTION. Requires 046_finance_application.sql.
--
-- Additive only: new finance_* tables and new finance.* catalog permissions. No existing table, row, permission,
-- role, application grant or employee is modified, and no role or employee receives the new permissions.
-- The existing B2B tables stay the single source of truth: canonical companies are b2b_companies rows and the
-- only ledger is b2b_account_movements. Finance stores staging data, review state, decisions and evidence
-- references; it never stores a second balance or a second company master.
--
-- Rollback: an API without Finance V0.3 ignores these tables. Keep them (and every row) on rollback; they hold
-- audit history. Nothing here is destructive, so the migration has no destructive down step.

-- Import batches. A raw file is never stored: only its SHA-256, size and sanitized cell values per row.
-- active_file_sha256 is set while a batch is not cancelled, so the same file cannot be imported twice at once.
CREATE TABLE IF NOT EXISTS finance_import_batches (
    batch_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_system VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_format VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_name VARCHAR(200) NOT NULL,
    file_sha256 BINARY(32) NOT NULL,
    active_file_sha256 BINARY(32) NULL,
    file_bytes INT UNSIGNED NOT NULL,
    header_json JSON NOT NULL,
    mapping_json JSON NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    row_count INT UNSIGNED NOT NULL,
    analysis_cursor INT UNSIGNED NOT NULL DEFAULT 0,
    confirm_cursor INT UNSIGNED NOT NULL DEFAULT 0,
    ready_rows INT UNSIGNED NOT NULL DEFAULT 0,
    incomplete_rows INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_rows INT UNSIGNED NOT NULL DEFAULT 0,
    known_rows INT UNSIGNED NOT NULL DEFAULT 0,
    rejected_rows INT UNSIGNED NOT NULL DEFAULT 0,
    staged_rows INT UNSIGNED NOT NULL DEFAULT 0,
    created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (batch_uuid),
    UNIQUE KEY uq_finance_import_active_file (active_file_sha256),
    KEY idx_finance_import_batches_status (status, created_at),
    KEY idx_finance_import_batches_creator (created_by_employee_uuid, created_at),
    CONSTRAINT fk_finance_import_batches_creator FOREIGN KEY (created_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_import_batches_source CHECK (source_system IN ('CSV', 'XLSX', 'SAGA_EXPORT', 'SMARTBILL_EXPORT', 'OTHER_LEGACY')),
    CONSTRAINT chk_finance_import_batches_format CHECK (file_format IN ('csv', 'xlsx')),
    CONSTRAINT chk_finance_import_batches_status CHECK (status IN ('uploaded', 'mapped', 'analyzed', 'confirming', 'completed', 'cancelled')),
    CONSTRAINT chk_finance_import_batches_active CHECK ((status = 'cancelled' AND active_file_sha256 IS NULL) OR (status <> 'cancelled' AND active_file_sha256 IS NOT NULL AND active_file_sha256 = file_sha256)),
    CONSTRAINT chk_finance_import_batches_version CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per data row of an uploaded file. raw_json holds the sanitized cells exactly as read; normalized_json the
-- mapped and normalized candidate; issues_json validation codes per field. Outcome after analysis:
--   ready (complete enough for review), incomplete (enters review with missing data), duplicate_in_file,
--   known (the same normalized record was already staged by an earlier import: idempotent reimport), rejected
--   (no usable company name at all), staged (a verification case was created).
CREATE TABLE IF NOT EXISTS finance_import_rows (
    row_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    batch_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    line_no INT UNSIGNED NOT NULL,
    raw_json JSON NOT NULL,
    normalized_json JSON NULL,
    issues_json JSON NULL,
    fingerprint BINARY(32) NULL,
    identity_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    outcome VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
    duplicate_strength VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
    case_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (row_uuid),
    UNIQUE KEY uq_finance_import_rows_line (batch_uuid, line_no),
    KEY idx_finance_import_rows_outcome (batch_uuid, outcome, line_no),
    KEY idx_finance_import_rows_fingerprint (fingerprint),
    KEY idx_finance_import_rows_identity (batch_uuid, identity_key, line_no),
    CONSTRAINT fk_finance_import_rows_batch FOREIGN KEY (batch_uuid) REFERENCES finance_import_batches (batch_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_import_rows_outcome CHECK (outcome IN ('pending', 'ready', 'incomplete', 'duplicate_in_file', 'known', 'rejected', 'staged')),
    CONSTRAINT chk_finance_import_rows_strength CHECK (duplicate_strength IS NULL OR duplicate_strength IN ('none', 'weak', 'strong', 'exact')),
    CONSTRAINT chk_finance_import_rows_line CHECK (line_no > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_case_number_sequence (
    case_number BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (case_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A company verification case: one candidate company identity under human review. It is NOT a company master:
-- the canonical company is b2b_companies (company_uuid, set only by a link decision or by approval). A case without
-- a verified tax identifier stays a verified prospect in staging and never becomes a fabricated B2B company.
-- Unknown values stay NULL; nothing is defaulted to satisfy a constraint.
CREATE TABLE IF NOT EXISTS finance_company_cases (
    case_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_number BIGINT UNSIGNED NOT NULL,
    case_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    origin VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_system VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    import_row_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_fingerprint BINARY(32) NULL,
    origin_company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legal_name VARCHAR(255) NULL,
    commercial_name VARCHAR(255) NULL,
    name_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    country_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NULL,
    tax_identifier VARCHAR(64) NULL,
    tax_normalized VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    vat_number VARCHAR(64) NULL,
    vat_normalized VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    registration_number VARCHAR(64) NULL,
    registration_normalized VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    address_line VARCHAR(255) NULL,
    city VARCHAR(120) NULL,
    postal_code VARCHAR(20) NULL,
    county_region VARCHAR(120) NULL,
    contact_name VARCHAR(160) NULL,
    phone VARCHAR(40) NULL,
    phone_key VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    email VARCHAR(254) NULL,
    website VARCHAR(255) NULL,
    legacy_customer_code VARCHAR(64) NULL,
    declared_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    declared_debit DECIMAL(16,2) NULL,
    declared_credit DECIMAL(16,2) NULL,
    last_transaction_date DATE NULL,
    notes TEXT NULL,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status_reason TEXT NULL,
    assigned_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    tax_status VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unverified',
    duplicate_review VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'not_started',
    checklist_json JSON NOT NULL,
    field_meta_json JSON NOT NULL,
    submitted_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    submitted_at DATETIME(6) NULL,
    submitted_version INT UNSIGNED NULL,
    approved_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    approved_at DATETIME(6) NULL,
    approved_version INT UNSIGNED NULL,
    created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (case_uuid),
    UNIQUE KEY uq_finance_cases_number (case_number),
    UNIQUE KEY uq_finance_cases_code (case_code),
    UNIQUE KEY uq_finance_cases_import_row (import_row_uuid),
    UNIQUE KEY uq_finance_cases_fingerprint (source_fingerprint),
    UNIQUE KEY uq_finance_cases_origin_company (origin_company_uuid),
    KEY idx_finance_cases_queue (status, updated_at, case_uuid),
    KEY idx_finance_cases_assigned (assigned_employee_uuid, status),
    KEY idx_finance_cases_tax (country_code, tax_normalized),
    KEY idx_finance_cases_vat (vat_normalized),
    KEY idx_finance_cases_registration (registration_normalized),
    KEY idx_finance_cases_name (name_key),
    KEY idx_finance_cases_phone (phone_key),
    KEY idx_finance_cases_legacy (legacy_customer_code),
    KEY idx_finance_cases_company (company_uuid),
    KEY idx_finance_cases_source (source_system, status),
    KEY idx_finance_cases_country (country_code, status),
    CONSTRAINT fk_finance_cases_number FOREIGN KEY (case_number) REFERENCES finance_case_number_sequence (case_number) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_cases_import_row FOREIGN KEY (import_row_uuid) REFERENCES finance_import_rows (row_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_cases_origin_company FOREIGN KEY (origin_company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_cases_company FOREIGN KEY (company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_cases_assigned FOREIGN KEY (assigned_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_cases_submitted FOREIGN KEY (submitted_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_cases_approved FOREIGN KEY (approved_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_cases_created FOREIGN KEY (created_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_cases_updated FOREIGN KEY (updated_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_cases_origin CHECK (origin IN ('IMPORT', 'MANUAL', 'EXISTING_B2B')),
    CONSTRAINT chk_finance_cases_source CHECK (source_system IN ('MANUAL', 'CSV', 'XLSX', 'SAGA_EXPORT', 'SMARTBILL_EXPORT', 'EXISTING_B2B', 'OTHER_LEGACY')),
    CONSTRAINT chk_finance_cases_origin_shape CHECK (
        (origin = 'IMPORT' AND import_row_uuid IS NOT NULL AND origin_company_uuid IS NULL)
        OR (origin = 'MANUAL' AND import_row_uuid IS NULL AND origin_company_uuid IS NULL AND source_system = 'MANUAL')
        OR (origin = 'EXISTING_B2B' AND import_row_uuid IS NULL AND origin_company_uuid IS NOT NULL AND source_system = 'EXISTING_B2B')
    ),
    CONSTRAINT chk_finance_cases_status CHECK (status IN ('IMPORTED', 'NEEDS_REVIEW', 'NEEDS_INFORMATION', 'SUSPECTED_DUPLICATE', 'IN_REVIEW', 'READY_FOR_SECOND_CHECK', 'APPROVED', 'REJECTED', 'REOPENED')),
    CONSTRAINT chk_finance_cases_in_review CHECK (status <> 'IN_REVIEW' OR assigned_employee_uuid IS NOT NULL),
    CONSTRAINT chk_finance_cases_tax_status CHECK (tax_status IN ('unverified', 'pending', 'verified')),
    CONSTRAINT chk_finance_cases_tax_verified CHECK (tax_status <> 'verified' OR (tax_normalized IS NOT NULL AND country_code IS NOT NULL)),
    CONSTRAINT chk_finance_cases_duplicate CHECK (duplicate_review IN ('not_started', 'open', 'resolved')),
    CONSTRAINT chk_finance_cases_country CHECK (country_code IS NULL OR country_code REGEXP '^[A-Z]{2}$'),
    CONSTRAINT chk_finance_cases_currency CHECK (declared_currency IS NULL OR declared_currency REGEXP '^[A-Z]{3}$'),
    CONSTRAINT chk_finance_cases_declared CHECK ((declared_debit IS NULL OR declared_debit >= 0) AND (declared_credit IS NULL OR declared_credit >= 0)),
    CONSTRAINT chk_finance_cases_submitted CHECK (status <> 'READY_FOR_SECOND_CHECK' OR (submitted_by_employee_uuid IS NOT NULL AND submitted_version IS NOT NULL)),
    CONSTRAINT chk_finance_cases_approved CHECK (status <> 'APPROVED' OR (approved_by_employee_uuid IS NOT NULL AND submitted_by_employee_uuid IS NOT NULL AND approved_by_employee_uuid <> submitted_by_employee_uuid)),
    CONSTRAINT chk_finance_cases_version CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable verification decisions (four-eyes trail). The application only inserts.
CREATE TABLE IF NOT EXISTS finance_case_decisions (
    decision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decision VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_version INT UNSIGNED NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason TEXT NULL,
    source_refs JSON NULL,
    decided_at DATETIME(6) NOT NULL,
    PRIMARY KEY (decision_uuid),
    KEY idx_finance_case_decisions_case (case_uuid, decided_at),
    KEY idx_finance_case_decisions_actor (actor_employee_uuid, decided_at),
    CONSTRAINT fk_finance_case_decisions_case FOREIGN KEY (case_uuid) REFERENCES finance_company_cases (case_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_case_decisions_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_case_decisions_decision CHECK (decision IN ('submitted', 'approved', 'rejected', 'returned', 'reopened', 'needs_information', 'suspected_duplicate', 'linked', 'kept_separate', 'rejected_duplicate'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Possible duplicates of a case: an existing canonical company or another case. Never merged automatically.
CREATE TABLE IF NOT EXISTS finance_duplicate_candidates (
    candidate_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    match_company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    match_case_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    strength VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    indicators JSON NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',
    resolved_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    resolved_at DATETIME(6) NULL,
    resolution_reason TEXT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (candidate_uuid),
    UNIQUE KEY uq_finance_duplicates_company (case_uuid, match_company_uuid),
    UNIQUE KEY uq_finance_duplicates_case (case_uuid, match_case_uuid),
    KEY idx_finance_duplicates_open (case_uuid, status),
    KEY idx_finance_duplicates_match_case (match_case_uuid),
    KEY idx_finance_duplicates_match_company (match_company_uuid),
    CONSTRAINT fk_finance_duplicates_case FOREIGN KEY (case_uuid) REFERENCES finance_company_cases (case_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_duplicates_company FOREIGN KEY (match_company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_duplicates_match_case FOREIGN KEY (match_case_uuid) REFERENCES finance_company_cases (case_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_duplicates_resolver FOREIGN KEY (resolved_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_duplicates_target CHECK ((match_company_uuid IS NOT NULL AND match_case_uuid IS NULL) OR (match_company_uuid IS NULL AND match_case_uuid IS NOT NULL)),
    CONSTRAINT chk_finance_duplicates_strength CHECK (strength IN ('exact', 'strong', 'weak')),
    CONSTRAINT chk_finance_duplicates_status CHECK (status IN ('open', 'linked', 'kept_separate', 'rejected_duplicate', 'dismissed')),
    CONSTRAINT chk_finance_duplicates_resolved CHECK (status = 'open' OR (resolved_by_employee_uuid IS NOT NULL AND resolved_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Evidence references (registry extract, SAGA ledger, SmartBill report, bank statement...). A reference is a
-- pointer to where a human inspected the evidence, not the document itself. Insert-only.
CREATE TABLE IF NOT EXISTS finance_evidence_refs (
    evidence_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_system VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reference VARCHAR(255) NOT NULL,
    document_date DATE NULL,
    amount DECIMAL(16,2) NULL,
    currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    note VARCHAR(500) NULL,
    created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (evidence_uuid),
    KEY idx_finance_evidence_entity (entity_type, entity_uuid, created_at),
    CONSTRAINT fk_finance_evidence_creator FOREIGN KEY (created_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_evidence_entity CHECK (entity_type IN ('case', 'onboarding', 'risk', 'credit', 'exception')),
    CONSTRAINT chk_finance_evidence_kind CHECK (kind IN ('registry_extract', 'saga_ledger', 'smartbill_report', 'bank_statement', 'contract', 'correspondence', 'invoice', 'other')),
    CONSTRAINT chk_finance_evidence_source CHECK (source_system IN ('MANUAL', 'SAGA_EXPORT', 'SMARTBILL_EXPORT', 'EXISTING_B2B', 'BANK', 'REGISTRY', 'OTHER_LEGACY')),
    CONSTRAINT chk_finance_evidence_amount CHECK ((amount IS NULL AND currency_code IS NULL) OR (amount IS NOT NULL AND amount >= 0 AND currency_code IN ('RON', 'EUR')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Current-account onboarding of one canonical company. The account currency follows the verified registered
-- country (RO -> RON, every other country -> EUR); the CHECK makes any other pairing impossible. The opening
-- balance is only a reviewed proposal here; the only place a balance lives is the B2B ledger, where an approved
-- proposal is posted as one opening_balance movement (posted_movement_uuid, unique).
-- status: NO_INFORMATION (nothing known: never treated as zero), AWAITING_RECONCILIATION (draft), DISPUTED,
-- SUBMITTED (waiting for the second person), VERIFIED (approved, not yet posted), RECONCILED (posted, or a verified
-- zero that posts nothing).
CREATE TABLE IF NOT EXISTS finance_account_onboardings (
    onboarding_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    country_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    account_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    balance_kind VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
    amount DECIMAL(16,2) NULL,
    as_of_date DATE NULL,
    overlap_resolution VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    currency_issue VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',
    currency_issue_note TEXT NULL,
    ledger_fingerprint BINARY(32) NULL,
    proposal_note TEXT NULL,
    dispute_reason TEXT NULL,
    proposed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    proposed_at DATETIME(6) NULL,
    verified_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    verified_at DATETIME(6) NULL,
    posted_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    posted_at DATETIME(6) NULL,
    posted_movement_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    cycle_no INT UNSIGNED NOT NULL DEFAULT 1,
    created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (onboarding_uuid),
    UNIQUE KEY uq_finance_onboardings_company (company_uuid),
    UNIQUE KEY uq_finance_onboardings_movement (posted_movement_uuid),
    KEY idx_finance_onboardings_status (status, updated_at),
    CONSTRAINT fk_finance_onboardings_company FOREIGN KEY (company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_onboardings_case FOREIGN KEY (case_uuid) REFERENCES finance_company_cases (case_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_onboardings_movement FOREIGN KEY (posted_movement_uuid) REFERENCES b2b_account_movements (movement_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_onboardings_proposed FOREIGN KEY (proposed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_onboardings_verified FOREIGN KEY (verified_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_onboardings_posted FOREIGN KEY (posted_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_onboardings_created FOREIGN KEY (created_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_onboardings_currency_policy CHECK ((country_code = 'RO' AND account_currency = 'RON') OR (country_code <> 'RO' AND account_currency = 'EUR')),
    CONSTRAINT chk_finance_onboardings_country CHECK (country_code REGEXP '^[A-Z]{2}$'),
    CONSTRAINT chk_finance_onboardings_status CHECK (status IN ('NO_INFORMATION', 'AWAITING_RECONCILIATION', 'DISPUTED', 'SUBMITTED', 'VERIFIED', 'RECONCILED')),
    CONSTRAINT chk_finance_onboardings_kind CHECK (balance_kind IS NULL OR balance_kind IN ('ZERO', 'DEBIT', 'CREDIT')),
    CONSTRAINT chk_finance_onboardings_amount CHECK (
        (balance_kind IS NULL AND amount IS NULL)
        OR (balance_kind = 'ZERO' AND amount = 0)
        OR (balance_kind IN ('DEBIT', 'CREDIT') AND amount > 0)
    ),
    CONSTRAINT chk_finance_onboardings_unknown CHECK (status <> 'NO_INFORMATION' OR balance_kind IS NULL),
    CONSTRAINT chk_finance_onboardings_known CHECK (status NOT IN ('SUBMITTED', 'VERIFIED', 'RECONCILED') OR (balance_kind IS NOT NULL AND as_of_date IS NOT NULL AND proposed_by_employee_uuid IS NOT NULL)),
    CONSTRAINT chk_finance_onboardings_verified CHECK (status NOT IN ('VERIFIED', 'RECONCILED') OR (verified_by_employee_uuid IS NOT NULL AND verified_by_employee_uuid <> proposed_by_employee_uuid)),
    CONSTRAINT chk_finance_onboardings_posted CHECK (posted_movement_uuid IS NULL OR (status = 'RECONCILED' AND balance_kind IN ('DEBIT', 'CREDIT'))),
    CONSTRAINT chk_finance_onboardings_overlap CHECK (overlap_resolution IS NULL OR overlap_resolution IN ('no_prior_movements', 'excluded_from_opening')),
    CONSTRAINT chk_finance_onboardings_currency_issue CHECK (currency_issue IN ('none', 'open', 'resolved')),
    CONSTRAINT chk_finance_onboardings_version CHECK (version > 0 AND cycle_no > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versioned risk policy drafts. A policy only ever produces a suggestion shown to a human analyst; no
-- classification is published automatically, approved policy or not. Thresholds are management decisions and
-- nothing is seeded: the table starts empty.
CREATE TABLE IF NOT EXISTS finance_risk_policies (
    policy_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    policy_number INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    definition_json JSON NOT NULL,
    status VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    approved_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    approved_at DATETIME(6) NULL,
    retired_at DATETIME(6) NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (policy_uuid),
    UNIQUE KEY uq_finance_risk_policies_number (policy_number),
    CONSTRAINT fk_finance_risk_policies_creator FOREIGN KEY (created_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_risk_policies_approver FOREIGN KEY (approved_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_risk_policies_status CHECK (status IN ('draft', 'approved', 'retired')),
    CONSTRAINT chk_finance_risk_policies_approved CHECK (status = 'draft' OR (approved_by_employee_uuid IS NOT NULL AND approved_by_employee_uuid <> created_by_employee_uuid))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Human risk classifications. At most one approved (active) classification per company: active_company_uuid is set
-- only on that row and is unique. No approved row means UNVERIFIED, which is a pending state, never GREEN.
CREATE TABLE IF NOT EXISTS finance_risk_assessments (
    assessment_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    classification VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active_company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    indicators_json JSON NOT NULL,
    basis_json JSON NOT NULL,
    policy_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reason TEXT NOT NULL,
    proposed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    proposed_at DATETIME(6) NOT NULL,
    decided_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decided_at DATETIME(6) NULL,
    decision_reason TEXT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (assessment_uuid),
    UNIQUE KEY uq_finance_risk_active (active_company_uuid),
    KEY idx_finance_risk_company (company_uuid, proposed_at),
    KEY idx_finance_risk_status (status, proposed_at),
    CONSTRAINT fk_finance_risk_company FOREIGN KEY (company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_risk_policy FOREIGN KEY (policy_uuid) REFERENCES finance_risk_policies (policy_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_risk_proposed FOREIGN KEY (proposed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_risk_decided FOREIGN KEY (decided_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_risk_classification CHECK (classification IN ('GREEN', 'YELLOW', 'RED')),
    CONSTRAINT chk_finance_risk_status CHECK (status IN ('PROPOSED', 'APPROVED', 'REJECTED', 'SUPERSEDED', 'SUSPENDED')),
    CONSTRAINT chk_finance_risk_active CHECK ((status = 'APPROVED' AND active_company_uuid IS NOT NULL AND active_company_uuid = company_uuid) OR (status <> 'APPROVED' AND active_company_uuid IS NULL)),
    CONSTRAINT chk_finance_risk_decided CHECK (status = 'PROPOSED' OR (decided_by_employee_uuid IS NOT NULL AND decided_at IS NOT NULL)),
    CONSTRAINT chk_finance_risk_separation CHECK (status NOT IN ('APPROVED', 'SUPERSEDED') OR decided_by_employee_uuid <> proposed_by_employee_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Credit limits: formal, currency-bound authorizations. One current limit per company (APPROVED or SUSPENDED),
-- marked by the unique active_company_uuid. No row means NO APPROVED LIMIT, which is not a limit of zero; an
-- approved amount of 0.00 is an explicit zero limit. There is no unlimited value.
CREATE TABLE IF NOT EXISTS finance_credit_limits (
    limit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount DECIMAL(16,2) NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active_company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    effective_from DATE NOT NULL,
    review_date DATE NULL,
    expires_on DATE NULL,
    onboarding_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    onboarding_version INT UNSIGNED NOT NULL,
    risk_assessment_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    policy_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reason TEXT NOT NULL,
    proposed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    proposed_at DATETIME(6) NOT NULL,
    verified_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    verified_at DATETIME(6) NULL,
    approved_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    approved_at DATETIME(6) NULL,
    ended_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ended_at DATETIME(6) NULL,
    end_reason TEXT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (limit_uuid),
    UNIQUE KEY uq_finance_credit_active (active_company_uuid),
    KEY idx_finance_credit_company (company_uuid, proposed_at),
    KEY idx_finance_credit_status (status, proposed_at),
    CONSTRAINT fk_finance_credit_company FOREIGN KEY (company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_credit_onboarding FOREIGN KEY (onboarding_uuid) REFERENCES finance_account_onboardings (onboarding_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_credit_risk FOREIGN KEY (risk_assessment_uuid) REFERENCES finance_risk_assessments (assessment_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_credit_policy FOREIGN KEY (policy_uuid) REFERENCES finance_risk_policies (policy_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_credit_proposed FOREIGN KEY (proposed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_credit_verified FOREIGN KEY (verified_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_credit_approved FOREIGN KEY (approved_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_credit_ended FOREIGN KEY (ended_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_credit_currency CHECK (currency_code IN ('RON', 'EUR')),
    CONSTRAINT chk_finance_credit_amount CHECK (amount >= 0 AND amount <= 9999999999.99),
    CONSTRAINT chk_finance_credit_status CHECK (status IN ('PROPOSED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'SUSPENDED', 'REVOKED', 'SUPERSEDED')),
    CONSTRAINT chk_finance_credit_active CHECK ((status IN ('APPROVED', 'SUSPENDED') AND active_company_uuid IS NOT NULL AND active_company_uuid = company_uuid) OR (status NOT IN ('APPROVED', 'SUSPENDED') AND active_company_uuid IS NULL)),
    CONSTRAINT chk_finance_credit_dates CHECK ((review_date IS NULL OR review_date >= effective_from) AND (expires_on IS NULL OR expires_on >= effective_from)),
    CONSTRAINT chk_finance_credit_verified CHECK (status IN ('PROPOSED', 'REJECTED') OR (verified_by_employee_uuid IS NOT NULL AND verified_by_employee_uuid <> proposed_by_employee_uuid)),
    CONSTRAINT chk_finance_credit_approved CHECK (status NOT IN ('APPROVED', 'SUSPENDED', 'REVOKED', 'SUPERSEDED') OR (approved_by_employee_uuid IS NOT NULL
        AND approved_by_employee_uuid <> proposed_by_employee_uuid AND approved_by_employee_uuid <> verified_by_employee_uuid))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CEO exception records for a future B2B integration (data model only: nothing in B2B reads this table yet).
-- An exception is bound to one order, one amount and currency, one financial snapshot and an expiry. Only the
-- employee configured as ARASYA_FINANCE_CEO_EMPLOYEE_UUID may approve; no role or permission can substitute.
CREATE TABLE IF NOT EXISTS finance_credit_exceptions (
    exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount DECIMAL(16,2) NOT NULL,
    snapshot_json JSON NOT NULL,
    snapshot_sha256 BINARY(32) NOT NULL,
    status VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason TEXT NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    requested_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    requested_at DATETIME(6) NOT NULL,
    decided_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decided_at DATETIME(6) NULL,
    decision_reason TEXT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (exception_uuid),
    UNIQUE KEY uq_finance_exception_active_order (active_order_uuid),
    KEY idx_finance_exception_company (company_uuid, requested_at),
    KEY idx_finance_exception_status (status, requested_at),
    CONSTRAINT fk_finance_exception_company FOREIGN KEY (company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_exception_order FOREIGN KEY (order_uuid) REFERENCES b2b_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_exception_requested FOREIGN KEY (requested_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_exception_decided FOREIGN KEY (decided_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_exception_currency CHECK (currency_code IN ('RON', 'EUR')),
    CONSTRAINT chk_finance_exception_amount CHECK (amount > 0),
    CONSTRAINT chk_finance_exception_status CHECK (status IN ('REQUESTED', 'APPROVED', 'REJECTED', 'WITHDRAWN')),
    CONSTRAINT chk_finance_exception_active CHECK ((status IN ('REQUESTED', 'APPROVED') AND active_order_uuid IS NOT NULL AND active_order_uuid = order_uuid) OR (status NOT IN ('REQUESTED', 'APPROVED') AND active_order_uuid IS NULL)),
    CONSTRAINT chk_finance_exception_decided CHECK (status IN ('REQUESTED', 'WITHDRAWN') OR (decided_by_employee_uuid IS NOT NULL AND decided_by_employee_uuid <> requested_by_employee_uuid))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable Finance audit trail. details_json holds codes and field names, never field values, contact data or
-- amounts copied from imports. The application only inserts.
CREATE TABLE IF NOT EXISTS finance_activity_events (
    event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version_before INT UNSIGNED NULL,
    version_after INT UNSIGNED NULL,
    reason TEXT NULL,
    details_json JSON NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_id),
    KEY idx_finance_activity_entity (entity_type, entity_uuid, occurred_at),
    KEY idx_finance_activity_company (company_uuid, occurred_at),
    KEY idx_finance_activity_time (occurred_at, event_id),
    KEY idx_finance_activity_actor (actor_employee_uuid, occurred_at),
    CONSTRAINT fk_finance_activity_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_activity_company FOREIGN KEY (company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_activity_entity CHECK (entity_type IN ('import', 'case', 'onboarding', 'risk', 'policy', 'credit', 'exception')),
    CONSTRAINT chk_finance_activity_action CHECK (action REGEXP '^[a-z][a-z_]{1,47}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Replay store for Finance mutations (per actor and Idempotency-Key). Pruned by bin/maintenance.php.
CREATE TABLE IF NOT EXISTS finance_idempotency (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash BINARY(32) NOT NULL,
    response_status SMALLINT UNSIGNED NOT NULL,
    response_json MEDIUMTEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (employee_uuid, idempotency_key),
    KEY idx_finance_idempotency_retention (created_at),
    CONSTRAINT fk_finance_idempotency_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Narrow Finance operation permissions. Each is usable only together with the Finance application grant and
-- finance.access (046, not role-grantable). No role or employee is given them here.
INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('finance.companies.import', 'finance', 'Import date companii', 'Upload company files, map columns and stage reviewed rows', 1, UTC_TIMESTAMP(6)),
    ('finance.companies.review', 'finance', 'Revizuire companii', 'Take, complete and submit company verification cases', 1, UTC_TIMESTAMP(6)),
    ('finance.companies.verify', 'finance', 'Verificare finală companii', 'Second-person approval, rejection and reopening of company verification', 1, UTC_TIMESTAMP(6)),
    ('finance.companies.resolve_duplicates', 'finance', 'Rezolvare duplicate', 'Link a case to an existing company, keep it separate or reject it as a duplicate', 1, UTC_TIMESTAMP(6)),
    ('finance.accounts.reconcile', 'finance', 'Reconciliere cont curent', 'Open current-account onboarding and record discrepancies', 1, UTC_TIMESTAMP(6)),
    ('finance.accounts.propose_opening_balance', 'finance', 'Propunere sold inițial', 'Draft and submit an opening balance with evidence', 1, UTC_TIMESTAMP(6)),
    ('finance.accounts.verify_opening_balance', 'finance', 'Verificare sold inițial', 'Second-person verification of an opening balance proposal', 1, UTC_TIMESTAMP(6)),
    ('finance.accounts.post_approved_opening_balance', 'finance', 'Înregistrare sold inițial aprobat', 'Post a verified opening balance to the B2B ledger or reverse it', 1, UTC_TIMESTAMP(6)),
    ('finance.risk.view', 'finance', 'Vizualizare risc', 'View risk indicators, classifications and policies', 1, UTC_TIMESTAMP(6)),
    ('finance.risk.propose', 'finance', 'Propunere clasificare risc', 'Propose a risk classification or draft a risk policy', 1, UTC_TIMESTAMP(6)),
    ('finance.risk.approve', 'finance', 'Aprobare clasificare risc', 'Approve, reject or suspend risk classifications and policies', 1, UTC_TIMESTAMP(6)),
    ('finance.credit.view', 'finance', 'Vizualizare limite credit', 'View credit limits, exposure and exceptions', 1, UTC_TIMESTAMP(6)),
    ('finance.credit.propose', 'finance', 'Propunere limită credit', 'Propose a credit limit or request a CEO exception', 1, UTC_TIMESTAMP(6)),
    ('finance.credit.verify', 'finance', 'Verificare limită credit', 'Second-person verification of a credit limit proposal', 1, UTC_TIMESTAMP(6)),
    ('finance.credit.approve', 'finance', 'Aprobare limită credit', 'Final approval or rejection of a verified credit limit', 1, UTC_TIMESTAMP(6)),
    ('finance.credit.revoke', 'finance', 'Suspendare/revocare limită credit', 'Suspend, reinstate or revoke an approved credit limit', 1, UTC_TIMESTAMP(6)),
    ('finance.audit.view', 'finance', 'Vizualizare audit Finance', 'View the Finance activity trail', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE permission_key = permission_key;

-- Intentionally NO INSERT INTO employee_application_access, role_permissions or employee_role_assignments.
