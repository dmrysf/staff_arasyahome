-- Finance V0.3.1: customer verification centre (legacy list staging, review flags, supervisory route, restricted field
-- history) and the opening-balance double-counting fix (GitHub finance_arasyahome#18).
-- DRAFT ONLY; NOT RUN IN PRODUCTION. Requires 047_finance_company_onboarding.sql.
--
-- Additive: new finance_* tables, new nullable or defaulted columns on 047 tables, two widened CHECK constraints and two
-- catalog permissions granted to nobody. No existing row is rewritten; no role, application grant or employee changes.
-- b2b_companies and b2b_account_movements are only referenced (foreign keys); they are not altered.
-- Re-runnable: every structural change is guarded by information_schema.
-- Rollback: an API without V0.3.1 ignores the new tables and columns. Keep them; they hold audit history.

-- ---- Import profile --------------------------------------------------------------------------------------------
-- LEGACY_CUSTOMER_LIST keeps every source row: the customer label is not a legal name, repeated identifiers stay
-- separate records and nameless rows still enter staging. content_fingerprint plus the occurrence within the file gives
-- each row its own stable fingerprint, so a reimport finds the same rows without ever merging two of them.
SET @arasya_048_batches = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_import_batches' AND column_name = 'import_profile');
SET @arasya_048_sql = IF(@arasya_048_batches,
    'ALTER TABLE finance_import_batches ADD COLUMN import_profile VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''STANDARD'' AFTER source_system, ADD CONSTRAINT chk_finance_import_batches_profile CHECK (import_profile IN (''STANDARD'', ''LEGACY_CUSTOMER_LIST''))',
    'DO 0');
PREPARE arasya_048_statement FROM @arasya_048_sql;
EXECUTE arasya_048_statement;
DEALLOCATE PREPARE arasya_048_statement;

SET @arasya_048_rows = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_import_rows' AND column_name = 'content_fingerprint');
SET @arasya_048_sql = IF(@arasya_048_rows,
    'ALTER TABLE finance_import_rows ADD COLUMN content_fingerprint BINARY(32) NULL AFTER fingerprint, ADD COLUMN flags_json JSON NULL AFTER issues_json, ADD KEY idx_finance_import_rows_content (batch_uuid, content_fingerprint, line_no)',
    'DO 0');
PREPARE arasya_048_statement FROM @arasya_048_sql;
EXECUTE arasya_048_statement;
DEALLOCATE PREPARE arasya_048_statement;

-- ---- Case provenance and review metadata ---------------------------------------------------------------------------
-- source_customer_name and source_customer_code hold the original label and code exactly as read; nothing updates them.
SET @arasya_048_cases = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_company_cases' AND column_name = 'source_customer_name');
SET @arasya_048_sql = IF(@arasya_048_cases,
    'ALTER TABLE finance_company_cases
        ADD COLUMN source_customer_name TEXT NULL AFTER company_uuid,
        ADD COLUMN source_customer_code VARCHAR(255) NULL AFTER source_customer_name,
        ADD COLUMN review_priority CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''P2'' AFTER duplicate_review,
        ADD COLUMN supervision_status VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''none'' AFTER review_priority,
        ADD KEY idx_finance_cases_source_code (source_customer_code),
        ADD KEY idx_finance_cases_priority (review_priority, status),
        ADD CONSTRAINT chk_finance_cases_priority CHECK (review_priority IN (''P0'', ''P1'', ''P2'')),
        ADD CONSTRAINT chk_finance_cases_supervision CHECK (supervision_status IN (''none'', ''required''))',
    'DO 0');
PREPARE arasya_048_statement FROM @arasya_048_sql;
EXECUTE arasya_048_statement;
DEALLOCATE PREPARE arasya_048_statement;

-- Review flags: data-quality indicators for a human reviewer. A flag is never a workflow status, risk colour, company
-- status, financial sanction or finding about any person. EMPLOYEE_REPORTED_PASSIVE_UNVERIFIED means only that an
-- employee claimed the legacy account was passive and the statement still needs independent verification.
CREATE TABLE IF NOT EXISTS finance_case_flags (
    flag_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    flag_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',
    origin VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    details_json JSON NULL,
    raised_at DATETIME(6) NOT NULL,
    resolution VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    resolution_note TEXT NULL,
    resolved_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    resolved_at DATETIME(6) NULL,
    PRIMARY KEY (flag_uuid),
    UNIQUE KEY uq_finance_case_flags_code (case_uuid, flag_code),
    KEY idx_finance_case_flags_queue (flag_code, status, case_uuid),
    CONSTRAINT fk_finance_case_flags_case FOREIGN KEY (case_uuid) REFERENCES finance_company_cases (case_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_case_flags_resolver FOREIGN KEY (resolved_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_case_flags_code CHECK (flag_code IN ('EMPLOYEE_REPORTED_PASSIVE_UNVERIFIED', 'CANCELLED_LOOKING_NAME', 'PLACEHOLDER_NAME', 'PLACEHOLDER_PHONE', 'MISSING_TAX_IDENTIFIER', 'MISSING_COUNTRY',
        'REPEATED_LEGACY_CODE', 'REPEATED_PHONE', 'DIFFERENT_NAMES_SHARED_IDENTIFIER', 'CONFLICTING_SOURCE_DATA', 'UNEXPLAINED_IDENTITY_CHANGE', 'MULTIPLE_RECORDS_SAME_COMPANY')),
    CONSTRAINT chk_finance_case_flags_status CHECK (status IN ('open', 'resolved')),
    CONSTRAINT chk_finance_case_flags_origin CHECK (origin IN ('import', 'detector')),
    CONSTRAINT chk_finance_case_flags_resolved CHECK (status = 'open' OR (resolved_by_employee_uuid IS NOT NULL AND resolved_at IS NOT NULL AND resolution IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Field-level change history with old and new values. Insert-only. These values may be personal data, so they live only
-- here and are returned only to holders of finance.audit.sensitive; finance_activity_events keeps field names only.
CREATE TABLE IF NOT EXISTS finance_case_field_changes (
    change_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    field_name VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    case_version INT UNSIGNED NOT NULL,
    reason TEXT NULL,
    evidence_ids JSON NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    changed_at DATETIME(6) NOT NULL,
    PRIMARY KEY (change_uuid),
    KEY idx_finance_field_changes_case (case_uuid, changed_at),
    KEY idx_finance_field_changes_actor (actor_employee_uuid, changed_at),
    CONSTRAINT fk_finance_field_changes_case FOREIGN KEY (case_uuid) REFERENCES finance_company_cases (case_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_field_changes_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recusals: an employee who must not work on one case. Declaring one is a neutral precaution, not a finding. Insert-only.
CREATE TABLE IF NOT EXISTS finance_case_recusals (
    recusal_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason TEXT NOT NULL,
    declared_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    declared_at DATETIME(6) NOT NULL,
    PRIMARY KEY (recusal_uuid),
    UNIQUE KEY uq_finance_case_recusals (case_uuid, employee_uuid),
    CONSTRAINT fk_finance_case_recusals_case FOREIGN KEY (case_uuid) REFERENCES finance_company_cases (case_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_case_recusals_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_case_recusals_declared FOREIGN KEY (declared_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reviewer comments. Insert-only.
CREATE TABLE IF NOT EXISTS finance_case_comments (
    comment_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    body TEXT NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (comment_uuid),
    KEY idx_finance_case_comments_case (case_uuid, created_at),
    CONSTRAINT fk_finance_case_comments_case FOREIGN KEY (case_uuid) REFERENCES finance_company_cases (case_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_case_comments_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Opening-balance overlap reconciliation (finance_arasyahome#18) -------------------------------------------------
-- One row per reconciliation attempt; a redraft leaves earlier attempts in place. Amounts are signed (debit positive).
-- incremental_signed = gross_signed - included_signed - existing_opening_signed, the only amount that may be posted.
CREATE TABLE IF NOT EXISTS finance_opening_reconciliations (
    reconciliation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    onboarding_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cycle_no INT UNSIGNED NOT NULL,
    currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cutoff_date DATE NOT NULL,
    gross_signed DECIMAL(16,2) NOT NULL,
    included_signed DECIMAL(16,2) NOT NULL,
    existing_opening_signed DECIMAL(16,2) NOT NULL,
    incremental_signed DECIMAL(16,2) NOT NULL,
    lines_fingerprint BINARY(32) NOT NULL,
    note TEXT NOT NULL,
    created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (reconciliation_uuid),
    KEY idx_finance_opening_reconciliations_onboarding (onboarding_uuid, cycle_no, created_at),
    CONSTRAINT fk_finance_opening_reconciliations_onboarding FOREIGN KEY (onboarding_uuid) REFERENCES finance_account_onboardings (onboarding_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_opening_reconciliations_creator FOREIGN KEY (created_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_opening_reconciliations_currency CHECK (currency_code IN ('RON', 'EUR')),
    CONSTRAINT chk_finance_opening_reconciliations_math CHECK (incremental_signed = gross_signed - included_signed - existing_opening_signed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each pre-cut-off ledger movement and how the stated opening balance treats it, with a copy of the movement as reviewed.
CREATE TABLE IF NOT EXISTS finance_opening_reconciliation_lines (
    line_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reconciliation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    movement_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    movement_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    movement_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    direction VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount DECIMAL(16,2) NOT NULL,
    value_date DATE NOT NULL,
    disposition VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    included_amount DECIMAL(16,2) NOT NULL,
    source_reference VARCHAR(255) NULL,
    note VARCHAR(500) NULL,
    PRIMARY KEY (line_uuid),
    UNIQUE KEY uq_finance_opening_lines (reconciliation_uuid, movement_uuid),
    CONSTRAINT fk_finance_opening_lines_reconciliation FOREIGN KEY (reconciliation_uuid) REFERENCES finance_opening_reconciliations (reconciliation_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_opening_lines_movement FOREIGN KEY (movement_uuid) REFERENCES b2b_account_movements (movement_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_opening_lines_disposition CHECK (disposition IN ('INCLUDED', 'PARTIAL', 'EXCLUDED', 'NEUTRAL_REVERSED')),
    CONSTRAINT chk_finance_opening_lines_direction CHECK (direction IN ('debit', 'credit')),
    CONSTRAINT chk_finance_opening_lines_amount CHECK (
        (disposition = 'INCLUDED' AND included_amount = amount AND source_reference IS NOT NULL)
        OR (disposition = 'PARTIAL' AND included_amount > 0 AND included_amount < amount AND source_reference IS NOT NULL)
        OR (disposition = 'EXCLUDED' AND included_amount = 0 AND source_reference IS NOT NULL AND note IS NOT NULL)
        OR (disposition = 'NEUTRAL_REVERSED' AND included_amount = 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @arasya_048_onboarding = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_account_onboardings' AND column_name = 'overlap_reconciliation_uuid');
SET @arasya_048_sql = IF(@arasya_048_onboarding,
    'ALTER TABLE finance_account_onboardings
        ADD COLUMN overlap_reconciliation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER overlap_resolution,
        ADD COLUMN other_currency_fingerprint BINARY(32) NULL AFTER currency_issue_note,
        ADD CONSTRAINT fk_finance_onboardings_reconciliation FOREIGN KEY (overlap_reconciliation_uuid) REFERENCES finance_opening_reconciliations (reconciliation_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT',
    'DO 0');
PREPARE arasya_048_statement FROM @arasya_048_sql;
EXECUTE arasya_048_statement;
DEALLOCATE PREPARE arasya_048_statement;

-- 'excluded_from_opening' stays a legal stored value but the API never writes it and treats it as unresolved.
SET @arasya_048_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_account_onboardings' AND constraint_name = 'chk_finance_onboardings_overlap');
SET @arasya_048_sql = IF(@arasya_048_old, 'ALTER TABLE finance_account_onboardings DROP CONSTRAINT chk_finance_onboardings_overlap', 'DO 0');
PREPARE arasya_048_statement FROM @arasya_048_sql;
EXECUTE arasya_048_statement;
DEALLOCATE PREPARE arasya_048_statement;

SET @arasya_048_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_account_onboardings' AND constraint_name = 'chk_finance_onboardings_overlap_048');
SET @arasya_048_sql = IF(@arasya_048_new,
    'ALTER TABLE finance_account_onboardings ADD CONSTRAINT chk_finance_onboardings_overlap_048 CHECK (overlap_resolution IS NULL OR overlap_resolution IN (''no_prior_movements'', ''excluded_from_opening'', ''reconciliation_required'', ''reconciled_by_lines''))',
    'DO 0');
PREPARE arasya_048_statement FROM @arasya_048_sql;
EXECUTE arasya_048_statement;
DEALLOCATE PREPARE arasya_048_statement;

-- A verified zero opening can need a real adjustment when the ledger already holds included movements.
SET @arasya_048_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_account_onboardings' AND constraint_name = 'chk_finance_onboardings_posted');
SET @arasya_048_sql = IF(@arasya_048_old, 'ALTER TABLE finance_account_onboardings DROP CONSTRAINT chk_finance_onboardings_posted', 'DO 0');
PREPARE arasya_048_statement FROM @arasya_048_sql;
EXECUTE arasya_048_statement;
DEALLOCATE PREPARE arasya_048_statement;

SET @arasya_048_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_account_onboardings' AND constraint_name = 'chk_finance_onboardings_posted_048');
SET @arasya_048_sql = IF(@arasya_048_new,
    'ALTER TABLE finance_account_onboardings ADD CONSTRAINT chk_finance_onboardings_posted_048 CHECK (posted_movement_uuid IS NULL OR status = ''RECONCILED'')',
    'DO 0');
PREPARE arasya_048_statement FROM @arasya_048_sql;
EXECUTE arasya_048_statement;
DEALLOCATE PREPARE arasya_048_statement;

-- ---- Narrow permissions --------------------------------------------------------------------------------------------
-- Usable only together with the Finance application grant and finance.access. No role or employee is given them here.
INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('finance.companies.supervise', 'finance', 'Revizuire independentă companii', 'Independent supervisory review of cases that require it; name a recused employee', 1, UTC_TIMESTAMP(6)),
    ('finance.audit.sensitive', 'finance', 'Audit valori sensibile', 'View old and new field values in the restricted change history', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE permission_key = permission_key;

-- Intentionally NO INSERT INTO employee_application_access, role_permissions or employee_role_assignments.
