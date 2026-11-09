-- Finance V0.4.0: owner-direct risk colour and purchase-limit decisions (GitHub finance_arasyahome#19).
-- DRAFT ONLY; NOT RUN IN PRODUCTION. Requires 048_finance_verification_center.sql.
--
-- Owner decision (2026-11-08): only the explicitly provisioned decision makers (Mesut Yeman and Zelal Yeman, by
-- immutable employee UUID in ARASYA_FINANCE_RISK_LIMIT_AUTHORITY_UUIDS) may make a colour or limit effective, each
-- alone, without the other's approval. The database must therefore accept a single-actor decision, but only as an
-- explicitly marked OWNER_DIRECT row; every REVIEWED row keeps the 047 separation exactly as before.
--
-- Additive: one defaulted column on each of finance_risk_assessments and finance_credit_limits (existing rows become
-- REVIEWED, which is what they are), finance_credit_limits.risk_assessment_uuid becomes nullable for OWNER_DIRECT rows
-- only, three replaced CHECK constraints (REVIEWED branch identical to 047), and one insert-only decision table.
-- No existing row is rewritten; no permission, role, application grant or employee changes. b2b_companies,
-- b2b_account_movements and every Staff, B2B and production table are only referenced.
-- Re-runnable: every structural change is guarded by information_schema.
-- Rollback: an API without V0.4.0 never writes OWNER_DIRECT rows; keep the column and table, they hold audit history.

-- ---- Decision mode on risk assessments ---------------------------------------------------------------------------
SET @arasya_049_risk = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_risk_assessments' AND column_name = 'decision_mode');
SET @arasya_049_sql = IF(@arasya_049_risk,
    'ALTER TABLE finance_risk_assessments ADD COLUMN decision_mode VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''REVIEWED'' AFTER status, ADD CONSTRAINT chk_finance_risk_mode CHECK (decision_mode IN (''REVIEWED'', ''OWNER_DIRECT''))',
    'DO 0');
PREPARE arasya_049_statement FROM @arasya_049_sql;
EXECUTE arasya_049_statement;
DEALLOCATE PREPARE arasya_049_statement;

-- 047: an APPROVED or SUPERSEDED classification needs a decider other than the proposer. Kept verbatim for REVIEWED.
-- OWNER_DIRECT: one person proposes and decides in the same command (same actor, same instant) and the row is never a
-- pending proposal or a rejection.
SET @arasya_049_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_risk_assessments' AND constraint_name = 'chk_finance_risk_separation');
SET @arasya_049_sql = IF(@arasya_049_old, 'ALTER TABLE finance_risk_assessments DROP CONSTRAINT chk_finance_risk_separation', 'DO 0');
PREPARE arasya_049_statement FROM @arasya_049_sql;
EXECUTE arasya_049_statement;
DEALLOCATE PREPARE arasya_049_statement;

SET @arasya_049_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_risk_assessments' AND constraint_name = 'chk_finance_risk_separation_049');
SET @arasya_049_sql = IF(@arasya_049_new,
    'ALTER TABLE finance_risk_assessments ADD CONSTRAINT chk_finance_risk_separation_049 CHECK (
        (decision_mode = ''REVIEWED'' AND (status NOT IN (''APPROVED'', ''SUPERSEDED'') OR decided_by_employee_uuid <> proposed_by_employee_uuid))
        OR (decision_mode = ''OWNER_DIRECT'' AND status IN (''APPROVED'', ''SUPERSEDED'', ''SUSPENDED'')
            AND decided_by_employee_uuid = proposed_by_employee_uuid AND decided_at = proposed_at))',
    'DO 0');
PREPARE arasya_049_statement FROM @arasya_049_sql;
EXECUTE arasya_049_statement;
DEALLOCATE PREPARE arasya_049_statement;

-- ---- Decision mode on credit limits ------------------------------------------------------------------------------
SET @arasya_049_credit = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_credit_limits' AND column_name = 'decision_mode');
SET @arasya_049_sql = IF(@arasya_049_credit,
    'ALTER TABLE finance_credit_limits ADD COLUMN decision_mode VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''REVIEWED'' AFTER status, ADD CONSTRAINT chk_finance_credit_mode CHECK (decision_mode IN (''REVIEWED'', ''OWNER_DIRECT''))',
    'DO 0');
PREPARE arasya_049_statement FROM @arasya_049_sql;
EXECUTE arasya_049_statement;
DEALLOCATE PREPARE arasya_049_statement;

-- An owner limit is a decision on the company and its verified account, not on a colour: the colour may be missing,
-- and a RED colour never turns a stored limit into purchasing eligibility (see the profile contract). REVIEWED rows
-- still need the classification they were based on.
SET @arasya_049_nullable = (SELECT COUNT(*) > 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_credit_limits' AND column_name = 'risk_assessment_uuid' AND is_nullable = 'NO');
SET @arasya_049_sql = IF(@arasya_049_nullable,
    'ALTER TABLE finance_credit_limits MODIFY risk_assessment_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL',
    'DO 0');
PREPARE arasya_049_statement FROM @arasya_049_sql;
EXECUTE arasya_049_statement;
DEALLOCATE PREPARE arasya_049_statement;

SET @arasya_049_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_credit_limits' AND constraint_name = 'chk_finance_credit_risk_basis_049');
SET @arasya_049_sql = IF(@arasya_049_new,
    'ALTER TABLE finance_credit_limits ADD CONSTRAINT chk_finance_credit_risk_basis_049 CHECK (decision_mode = ''OWNER_DIRECT'' OR risk_assessment_uuid IS NOT NULL)',
    'DO 0');
PREPARE arasya_049_statement FROM @arasya_049_sql;
EXECUTE arasya_049_statement;
DEALLOCATE PREPARE arasya_049_statement;

-- 047: proposer, verifier and approver all distinct. Kept verbatim for REVIEWED. OWNER_DIRECT: the same owner is
-- proposer, verifier and approver at the same instant, and the row is never a proposal, review or rejection.
SET @arasya_049_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_credit_limits' AND constraint_name = 'chk_finance_credit_verified');
SET @arasya_049_sql = IF(@arasya_049_old, 'ALTER TABLE finance_credit_limits DROP CONSTRAINT chk_finance_credit_verified', 'DO 0');
PREPARE arasya_049_statement FROM @arasya_049_sql;
EXECUTE arasya_049_statement;
DEALLOCATE PREPARE arasya_049_statement;

SET @arasya_049_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_credit_limits' AND constraint_name = 'chk_finance_credit_approved');
SET @arasya_049_sql = IF(@arasya_049_old, 'ALTER TABLE finance_credit_limits DROP CONSTRAINT chk_finance_credit_approved', 'DO 0');
PREPARE arasya_049_statement FROM @arasya_049_sql;
EXECUTE arasya_049_statement;
DEALLOCATE PREPARE arasya_049_statement;

SET @arasya_049_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_credit_limits' AND constraint_name = 'chk_finance_credit_verified_049');
SET @arasya_049_sql = IF(@arasya_049_new,
    'ALTER TABLE finance_credit_limits ADD CONSTRAINT chk_finance_credit_verified_049 CHECK (
        (decision_mode = ''REVIEWED'' AND (status IN (''PROPOSED'', ''REJECTED'') OR (verified_by_employee_uuid IS NOT NULL AND verified_by_employee_uuid <> proposed_by_employee_uuid)))
        OR (decision_mode = ''OWNER_DIRECT'' AND status IN (''APPROVED'', ''SUSPENDED'', ''REVOKED'', ''SUPERSEDED'')
            AND verified_by_employee_uuid = proposed_by_employee_uuid AND approved_by_employee_uuid = proposed_by_employee_uuid
            AND verified_at = proposed_at AND approved_at = proposed_at))',
    'DO 0');
PREPARE arasya_049_statement FROM @arasya_049_sql;
EXECUTE arasya_049_statement;
DEALLOCATE PREPARE arasya_049_statement;

SET @arasya_049_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_credit_limits' AND constraint_name = 'chk_finance_credit_approved_049');
SET @arasya_049_sql = IF(@arasya_049_new,
    'ALTER TABLE finance_credit_limits ADD CONSTRAINT chk_finance_credit_approved_049 CHECK (decision_mode = ''OWNER_DIRECT''
        OR status NOT IN (''APPROVED'', ''SUSPENDED'', ''REVOKED'', ''SUPERSEDED'')
        OR (approved_by_employee_uuid IS NOT NULL AND approved_by_employee_uuid <> proposed_by_employee_uuid AND approved_by_employee_uuid <> verified_by_employee_uuid))',
    'DO 0');
PREPARE arasya_049_statement FROM @arasya_049_sql;
EXECUTE arasya_049_statement;
DEALLOCATE PREPARE arasya_049_statement;

-- ---- Owner decision journal -----------------------------------------------------------------------------------------
-- One row per owner command, effective or not, in a per-company sequence. Insert-only: nothing updates or deletes a
-- row, so a later decision never overwrites an earlier one. NOT_EFFECTIVE rows are instructions recorded while the
-- basis (verified identity, reconciled account, currency) is missing; they never become effective by themselves.
-- The actor snapshot and the allowlist fingerprint make a decision by anyone outside the provisioned list detectable.
CREATE TABLE IF NOT EXISTS finance_owner_decisions (
    decision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sequence_no INT UNSIGNED NOT NULL,
    decision_type VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'OWNER_DIRECT',
    subject VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    effect VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    assessment_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    limit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    previous_json JSON NOT NULL,
    new_json JSON NOT NULL,
    currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reason TEXT NOT NULL,
    basis_json JSON NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_snapshot_json JSON NOT NULL,
    authority_fingerprint BINARY(32) NOT NULL,
    state_before CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state_after CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(100) NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decided_at DATETIME(6) NOT NULL,
    PRIMARY KEY (decision_uuid),
    UNIQUE KEY uq_finance_owner_decisions_sequence (company_uuid, sequence_no),
    KEY idx_finance_owner_decisions_actor (actor_employee_uuid, decided_at),
    KEY idx_finance_owner_decisions_assessment (assessment_uuid),
    KEY idx_finance_owner_decisions_limit (limit_uuid),
    CONSTRAINT fk_finance_owner_decisions_company FOREIGN KEY (company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_owner_decisions_assessment FOREIGN KEY (assessment_uuid) REFERENCES finance_risk_assessments (assessment_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_owner_decisions_limit FOREIGN KEY (limit_uuid) REFERENCES finance_credit_limits (limit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_owner_decisions_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_owner_decisions_type CHECK (decision_type = 'OWNER_DIRECT'),
    CONSTRAINT chk_finance_owner_decisions_subject CHECK (subject IN ('RISK', 'CREDIT')),
    CONSTRAINT chk_finance_owner_decisions_action CHECK ((subject = 'RISK' AND action IN ('SET', 'INSTRUCT'))
        OR (subject = 'CREDIT' AND action IN ('SET', 'SUSPEND', 'REINSTATE', 'REVOKE', 'INSTRUCT'))),
    CONSTRAINT chk_finance_owner_decisions_effect CHECK ((action = 'INSTRUCT' AND effect = 'NOT_EFFECTIVE' AND assessment_uuid IS NULL AND limit_uuid IS NULL)
        OR (action <> 'INSTRUCT' AND effect = 'EFFECTIVE' AND ((subject = 'RISK' AND assessment_uuid IS NOT NULL AND limit_uuid IS NULL) OR (subject = 'CREDIT' AND limit_uuid IS NOT NULL AND assessment_uuid IS NULL)))),
    CONSTRAINT chk_finance_owner_decisions_currency CHECK (currency_code IS NULL OR currency_code IN ('RON', 'EUR')),
    CONSTRAINT chk_finance_owner_decisions_reason CHECK (CHAR_LENGTH(reason) BETWEEN 3 AND 2000),
    CONSTRAINT chk_finance_owner_decisions_sequence CHECK (sequence_no >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentionally NO INSERT INTO permissions, employee_application_access, role_permissions or employee_role_assignments,
-- and no allowlist value: provisioning the two owners is a separate, explicitly approved step.
