-- Finance collections (API 2.68.0): four-eyes ledger requests, real bank transaction identity, refunds.
-- DRAFT ONLY; NOT RUN IN PRODUCTION. Requires 051_trendyol_awb_station.sql.
--
-- Owner decision (2026-11-10): the B2B current-account ledger (b2b_account_movements, written only through
-- B2B\AccountLedger) stays the single financial record. Finance never keeps a second ledger. A payment, an adjustment,
-- a reversal or a refund entered in Finance is first a request; it reaches the ledger only when a second, authorized
-- person approves it, in the same transaction as the ledger rows, under the company row lock.
--
-- Additive:
--   finance_ledger_request_sequence / finance_ledger_requests   the request workflow (DRAFT, PENDING_APPROVAL, APPROVED,
--                                                               REJECTED, WITHDRAWN, PAID_OUT, REVERSED)
--   b2b_account_movements: one more movement type, 'refund' (debit, with a payment method). CHECKs replaced; the other
--                          branches are unchanged. No ledger row is rewritten.
--   b2b_account_activity_events: one more action, 'refund_posted' (CHECK replaced, values kept)
--   finance_activity_events / finance_evidence_refs: entity type 'ledger_request' (CHECKs replaced, values kept)
--   finance_refused_attempts                                    refused Finance and B2B account commands (insert-only)
--   eight finance.* catalogue permissions, granted to nobody
-- Database guarantees, independent of the application:
--   * the decider of a request is never its creator, submitter or last editor (CHECK chk_finance_ledger_four_eyes);
--   * one real bank transaction (provider, account key, currency, transaction id, direction) can back at most one active
--     request (UNIQUE active_bank_transaction_key, kept consistent with the status by CHECK), and one refund payout
--     identity at most one refund (UNIQUE payout_bank_transaction_key);
--   * a request posts at most one movement (UNIQUE posted_movement_uuid) and is reversed at most once;
--   * at most one open reversal request per movement (UNIQUE active_reversal_target).
-- Re-runnable: every structural change is guarded by information_schema. Rollback: an API before 2.68.0 ignores the new
-- table; bin/check-rollback.php requires 2.68.0 while a refund movement or an open request exists.

CREATE TABLE IF NOT EXISTS finance_ledger_request_sequence (
    request_number BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (request_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_ledger_requests (
    request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_number BIGINT UNSIGNED NOT NULL,
    request_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount DECIMAL(16,2) NOT NULL,
    direction VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NULL,
    value_date DATE NOT NULL,
    payment_method VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    -- Real bank (or card processor, or cash register) identity of the money movement. Never an IBAN: the account key is
    -- an internal alias such as ING-RON-01. bank_transaction_key is the normalized identity; the active copy is unique.
    bank_provider VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    bank_account_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    bank_transaction_id VARCHAR(120) NULL,
    bank_transaction_key VARCHAR(220) CHARACTER SET ascii COLLATE ascii_bin NULL,
    active_bank_transaction_key VARCHAR(220) CHARACTER SET ascii COLLATE ascii_bin NULL,
    external_reference VARCHAR(160) NULL,
    note TEXT NULL,
    allocations_json JSON NULL,
    -- REVERSAL: the movement to reverse. REFUND: the payment the money came from.
    target_movement_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    active_reversal_target CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    posted_movement_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reversal_movement_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    possible_duplicates_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
    payout_bank_provider VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    payout_bank_account_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    payout_bank_transaction_id VARCHAR(120) NULL,
    payout_bank_transaction_key VARCHAR(220) CHARACTER SET ascii COLLATE ascii_bin NULL,
    payout_recorded_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    payout_recorded_at DATETIME(6) NULL,
    created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    last_edited_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    submitted_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    submitted_at DATETIME(6) NULL,
    decided_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decided_at DATETIME(6) NULL,
    decision_reason TEXT NULL,
    closed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    closed_at DATETIME(6) NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (request_uuid),
    UNIQUE KEY uq_finance_ledger_request_number (request_number),
    UNIQUE KEY uq_finance_ledger_request_code (request_code),
    UNIQUE KEY uq_finance_ledger_active_bank (active_bank_transaction_key),
    UNIQUE KEY uq_finance_ledger_payout_bank (payout_bank_transaction_key),
    UNIQUE KEY uq_finance_ledger_posted (posted_movement_uuid),
    UNIQUE KEY uq_finance_ledger_reversal (reversal_movement_uuid),
    UNIQUE KEY uq_finance_ledger_reversal_target (active_reversal_target),
    KEY idx_finance_ledger_queue (status, kind, submitted_at),
    KEY idx_finance_ledger_company (company_uuid, created_at),
    KEY idx_finance_ledger_creator (created_by_employee_uuid, created_at),
    KEY idx_finance_ledger_bank_key (bank_transaction_key),
    KEY idx_finance_ledger_value_date (value_date, currency_code),
    CONSTRAINT fk_finance_ledger_number FOREIGN KEY (request_number) REFERENCES finance_ledger_request_sequence (request_number) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_ledger_company FOREIGN KEY (company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_ledger_target FOREIGN KEY (target_movement_uuid) REFERENCES b2b_account_movements (movement_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_ledger_posted FOREIGN KEY (posted_movement_uuid) REFERENCES b2b_account_movements (movement_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_ledger_reversal FOREIGN KEY (reversal_movement_uuid) REFERENCES b2b_account_movements (movement_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_ledger_creator FOREIGN KEY (created_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_ledger_editor FOREIGN KEY (last_edited_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_ledger_submitter FOREIGN KEY (submitted_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_ledger_decider FOREIGN KEY (decided_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_ledger_closer FOREIGN KEY (closed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_ledger_payout_actor FOREIGN KEY (payout_recorded_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_ledger_kind CHECK (kind IN ('PAYMENT', 'ADJUSTMENT', 'REVERSAL', 'REFUND')),
    CONSTRAINT chk_finance_ledger_status CHECK (status IN ('DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'WITHDRAWN', 'PAID_OUT', 'REVERSED')),
    CONSTRAINT chk_finance_ledger_currency CHECK (currency_code IN ('RON', 'EUR')),
    CONSTRAINT chk_finance_ledger_amount CHECK (amount > 0),
    CONSTRAINT chk_finance_ledger_method CHECK (payment_method IS NULL OR payment_method IN ('bank_transfer', 'cash', 'card', 'compensation', 'other')),
    CONSTRAINT chk_finance_ledger_allocations CHECK (allocations_json IS NULL OR JSON_VALID(allocations_json)),
    -- Shape per kind. Every branch is written so that a NULL never makes the whole check pass.
    CONSTRAINT chk_finance_ledger_shape CHECK (
        (kind = 'PAYMENT' AND direction = 'credit' AND payment_method IS NOT NULL AND target_movement_uuid IS NULL)
        OR (kind = 'ADJUSTMENT' AND direction IN ('debit', 'credit') AND payment_method IS NULL AND target_movement_uuid IS NULL
            AND bank_transaction_key IS NULL AND allocations_json IS NULL)
        OR (kind = 'REVERSAL' AND direction IS NULL AND payment_method IS NULL AND target_movement_uuid IS NOT NULL
            AND bank_transaction_key IS NULL AND allocations_json IS NULL)
        OR (kind = 'REFUND' AND direction = 'debit' AND payment_method IS NOT NULL AND target_movement_uuid IS NOT NULL
            AND bank_transaction_key IS NULL AND allocations_json IS NULL)),
    -- Four eyes: whoever decides (approves or rejects) never created, submitted or last edited the request.
    CONSTRAINT chk_finance_ledger_four_eyes CHECK (decided_by_employee_uuid IS NULL OR (submitted_by_employee_uuid IS NOT NULL
        AND decided_by_employee_uuid <> created_by_employee_uuid AND decided_by_employee_uuid <> submitted_by_employee_uuid
        AND decided_by_employee_uuid <> last_edited_by_employee_uuid)),
    -- A refund payout is confirmed by someone other than the requester.
    CONSTRAINT chk_finance_ledger_payout_actor CHECK (payout_recorded_by_employee_uuid IS NULL OR payout_recorded_by_employee_uuid <> created_by_employee_uuid),
    CONSTRAINT chk_finance_ledger_lifecycle CHECK (
        (status = 'DRAFT' AND submitted_by_employee_uuid IS NULL AND decided_by_employee_uuid IS NULL AND posted_movement_uuid IS NULL AND closed_by_employee_uuid IS NULL)
        OR (status = 'PENDING_APPROVAL' AND submitted_by_employee_uuid IS NOT NULL AND submitted_at IS NOT NULL AND decided_by_employee_uuid IS NULL AND posted_movement_uuid IS NULL AND closed_by_employee_uuid IS NULL)
        OR (status = 'WITHDRAWN' AND decided_by_employee_uuid IS NULL AND posted_movement_uuid IS NULL AND closed_by_employee_uuid IS NOT NULL AND closed_at IS NOT NULL)
        OR (status = 'REJECTED' AND decided_by_employee_uuid IS NOT NULL AND decided_at IS NOT NULL AND decision_reason IS NOT NULL AND posted_movement_uuid IS NULL)
        OR (status = 'APPROVED' AND decided_by_employee_uuid IS NOT NULL AND decided_at IS NOT NULL AND posted_movement_uuid IS NOT NULL AND reversal_movement_uuid IS NULL AND payout_bank_transaction_key IS NULL)
        OR (status = 'PAID_OUT' AND kind = 'REFUND' AND decided_by_employee_uuid IS NOT NULL AND posted_movement_uuid IS NOT NULL AND reversal_movement_uuid IS NULL
            AND payout_bank_transaction_key IS NOT NULL AND payout_recorded_by_employee_uuid IS NOT NULL AND payout_recorded_at IS NOT NULL)
        OR (status = 'REVERSED' AND decided_by_employee_uuid IS NOT NULL AND posted_movement_uuid IS NOT NULL AND reversal_movement_uuid IS NOT NULL
            AND closed_by_employee_uuid IS NOT NULL AND closed_at IS NOT NULL)),
    -- The active bank identity exists exactly while the request is alive (draft, pending, approved or paid out).
    CONSTRAINT chk_finance_ledger_active_bank CHECK (
        (active_bank_transaction_key IS NULL AND (bank_transaction_key IS NULL OR status IN ('REJECTED', 'WITHDRAWN', 'REVERSED')))
        OR (active_bank_transaction_key IS NOT NULL AND active_bank_transaction_key = bank_transaction_key AND status IN ('DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'PAID_OUT'))),
    CONSTRAINT chk_finance_ledger_active_reversal CHECK (
        (active_reversal_target IS NULL AND (kind <> 'REVERSAL' OR status NOT IN ('DRAFT', 'PENDING_APPROVAL')))
        OR (active_reversal_target IS NOT NULL AND kind = 'REVERSAL' AND active_reversal_target = target_movement_uuid AND status IN ('DRAFT', 'PENDING_APPROVAL'))),
    CONSTRAINT chk_finance_ledger_bank_parts CHECK (
        (bank_transaction_key IS NULL AND bank_provider IS NULL AND bank_account_key IS NULL AND bank_transaction_id IS NULL)
        OR (bank_transaction_key IS NOT NULL AND bank_provider IS NOT NULL AND bank_account_key IS NOT NULL AND bank_transaction_id IS NOT NULL)),
    CONSTRAINT chk_finance_ledger_payout_parts CHECK (
        (payout_bank_transaction_key IS NULL AND payout_bank_provider IS NULL AND payout_bank_account_key IS NULL AND payout_bank_transaction_id IS NULL)
        OR (payout_bank_transaction_key IS NOT NULL AND payout_bank_provider IS NOT NULL AND payout_bank_account_key IS NOT NULL AND payout_bank_transaction_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Refused commands --------------------------------------------------------------------------------------------
-- A refused write on a Finance route or a B2B account route (four eyes, missing permission, duplicate bank transaction,
-- closed B2B write path...) is kept here, insert-only, because the web server error log is rotated within days. Only the
-- actor, the route, the status, the error code and the request id: never a request body, an amount or a bank identity.
-- No foreign key: recording a refusal must never fail because of it.
CREATE TABLE IF NOT EXISTS finance_refused_attempts (
    attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    http_method VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    route VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    http_status SMALLINT UNSIGNED NOT NULL,
    error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (attempt_id),
    KEY idx_finance_refused_time (occurred_at),
    KEY idx_finance_refused_employee (employee_uuid, occurred_at),
    KEY idx_finance_refused_code (error_code, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- b2b_account_movements: movement type 'refund' -------------------------------------------------------------
-- A refund returns company credit to the customer: a debit with the payment method used for the payout. It is posted
-- only by an approved Finance refund request and allocated against the payment it came from. Older branches unchanged.
SET @arasya_052_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'b2b_account_movements' AND constraint_name = 'chk_b2b_account_type');
SET @arasya_052_sql = IF(@arasya_052_old, 'ALTER TABLE b2b_account_movements DROP CONSTRAINT chk_b2b_account_type', 'DO 0');
PREPARE arasya_052_statement FROM @arasya_052_sql;
EXECUTE arasya_052_statement;
DEALLOCATE PREPARE arasya_052_statement;

SET @arasya_052_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'b2b_account_movements' AND constraint_name = 'chk_b2b_account_type_052');
SET @arasya_052_sql = IF(@arasya_052_new,
    'ALTER TABLE b2b_account_movements ADD CONSTRAINT chk_b2b_account_type_052 CHECK (movement_type IN (''order_receivable'', ''payment'', ''opening_balance'', ''adjustment'', ''reversal'', ''refund''))',
    'DO 0');
PREPARE arasya_052_statement FROM @arasya_052_sql;
EXECUTE arasya_052_statement;
DEALLOCATE PREPARE arasya_052_statement;

SET @arasya_052_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'b2b_account_movements' AND constraint_name = 'chk_b2b_account_shape');
SET @arasya_052_sql = IF(@arasya_052_old, 'ALTER TABLE b2b_account_movements DROP CONSTRAINT chk_b2b_account_shape', 'DO 0');
PREPARE arasya_052_statement FROM @arasya_052_sql;
EXECUTE arasya_052_statement;
DEALLOCATE PREPARE arasya_052_statement;

SET @arasya_052_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'b2b_account_movements' AND constraint_name = 'chk_b2b_account_shape_052');
SET @arasya_052_sql = IF(@arasya_052_new,
    'ALTER TABLE b2b_account_movements ADD CONSTRAINT chk_b2b_account_shape_052 CHECK(
       (movement_type=''order_receivable'' AND direction=''debit'' AND order_uuid IS NOT NULL AND receivable_order_uuid IS NOT NULL
         AND receivable_order_uuid=order_uuid AND reversed_movement_uuid IS NULL AND payment_method IS NULL) OR
       (movement_type=''payment'' AND direction=''credit'' AND payment_method IS NOT NULL
         AND receivable_order_uuid IS NULL AND reversed_movement_uuid IS NULL AND order_uuid IS NULL) OR
       (movement_type IN (''opening_balance'',''adjustment'') AND payment_method IS NULL
         AND receivable_order_uuid IS NULL AND reversed_movement_uuid IS NULL AND order_uuid IS NULL) OR
       (movement_type=''reversal'' AND reversed_movement_uuid IS NOT NULL AND payment_method IS NULL AND receivable_order_uuid IS NULL) OR
       (movement_type=''refund'' AND direction=''debit'' AND payment_method IS NOT NULL
         AND receivable_order_uuid IS NULL AND reversed_movement_uuid IS NULL AND order_uuid IS NULL))',
    'DO 0');
PREPARE arasya_052_statement FROM @arasya_052_sql;
EXECUTE arasya_052_statement;
DEALLOCATE PREPARE arasya_052_statement;

-- The B2B account activity trail names the refund posting (values of 011 unchanged).
SET @arasya_052_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'b2b_account_activity_events' AND constraint_name = 'chk_b2b_account_activity_action');
SET @arasya_052_sql = IF(@arasya_052_old, 'ALTER TABLE b2b_account_activity_events DROP CONSTRAINT chk_b2b_account_activity_action', 'DO 0');
PREPARE arasya_052_statement FROM @arasya_052_sql;
EXECUTE arasya_052_statement;
DEALLOCATE PREPARE arasya_052_statement;

SET @arasya_052_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'b2b_account_activity_events' AND constraint_name = 'chk_b2b_account_activity_action_052');
SET @arasya_052_sql = IF(@arasya_052_new,
    'ALTER TABLE b2b_account_activity_events ADD CONSTRAINT chk_b2b_account_activity_action_052 CHECK (action IN (''receivable_posted'', ''receivable_reversed'', ''payment_recorded'', ''opening_balance_posted'', ''adjustment_posted'', ''movement_reversed'', ''allocation_created'', ''allocation_released'', ''refund_posted''))',
    'DO 0');
PREPARE arasya_052_statement FROM @arasya_052_sql;
EXECUTE arasya_052_statement;
DEALLOCATE PREPARE arasya_052_statement;

-- ---- Finance audit and evidence: entity type 'ledger_request' -------------------------------------------------
SET @arasya_052_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_activity_events' AND constraint_name = 'chk_finance_activity_entity');
SET @arasya_052_sql = IF(@arasya_052_old, 'ALTER TABLE finance_activity_events DROP CONSTRAINT chk_finance_activity_entity', 'DO 0');
PREPARE arasya_052_statement FROM @arasya_052_sql;
EXECUTE arasya_052_statement;
DEALLOCATE PREPARE arasya_052_statement;

SET @arasya_052_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_activity_events' AND constraint_name = 'chk_finance_activity_entity_052');
SET @arasya_052_sql = IF(@arasya_052_new,
    'ALTER TABLE finance_activity_events ADD CONSTRAINT chk_finance_activity_entity_052 CHECK (entity_type IN (''import'', ''case'', ''onboarding'', ''risk'', ''policy'', ''credit'', ''exception'', ''ledger_request'', ''allocation''))',
    'DO 0');
PREPARE arasya_052_statement FROM @arasya_052_sql;
EXECUTE arasya_052_statement;
DEALLOCATE PREPARE arasya_052_statement;

SET @arasya_052_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_evidence_refs' AND constraint_name = 'chk_finance_evidence_entity');
SET @arasya_052_sql = IF(@arasya_052_old, 'ALTER TABLE finance_evidence_refs DROP CONSTRAINT chk_finance_evidence_entity', 'DO 0');
PREPARE arasya_052_statement FROM @arasya_052_sql;
EXECUTE arasya_052_statement;
DEALLOCATE PREPARE arasya_052_statement;

SET @arasya_052_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'finance_evidence_refs' AND constraint_name = 'chk_finance_evidence_entity_052');
SET @arasya_052_sql = IF(@arasya_052_new,
    'ALTER TABLE finance_evidence_refs ADD CONSTRAINT chk_finance_evidence_entity_052 CHECK (entity_type IN (''case'', ''onboarding'', ''risk'', ''credit'', ''exception'', ''ledger_request''))',
    'DO 0');
PREPARE arasya_052_statement FROM @arasya_052_sql;
EXECUTE arasya_052_statement;
DEALLOCATE PREPARE arasya_052_statement;

-- ---- Permissions (Finance application, role-grantable, granted to nobody) ---------------------------------------
INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('finance.collections.record', 'finance', 'Înregistrare încasări', 'Enter, edit, submit and withdraw payment requests (never posted without a second person)', 1, UTC_TIMESTAMP(6)),
    ('finance.collections.approve', 'finance', 'Verificare încasări', 'Approve or reject payment requests of other people; approval posts the payment to the ledger', 1, UTC_TIMESTAMP(6)),
    ('finance.ledger.request', 'finance', 'Cerere corecție cont curent', 'Request adjustments and reversals of current-account movements', 1, UTC_TIMESTAMP(6)),
    ('finance.ledger.approve', 'finance', 'Aprobare corecție cont curent', 'Approve or reject adjustment and reversal requests of other people', 1, UTC_TIMESTAMP(6)),
    ('finance.refunds.request', 'finance', 'Cerere rambursare', 'Request a refund of a customer payment', 1, UTC_TIMESTAMP(6)),
    ('finance.refunds.approve', 'finance', 'Aprobare rambursare', 'Approve, reject or cancel refunds requested by other people', 1, UTC_TIMESTAMP(6)),
    ('finance.refunds.payout', 'finance', 'Confirmare plată rambursare', 'Record that the approved refund was paid out by the bank or the cash desk', 1, UTC_TIMESTAMP(6)),
    ('finance.reconciliation.manage', 'finance', 'Reconciliere alocări', 'Allocate payments to receivables and release allocations (never changes a balance)', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE permission_key = permission_key;

-- Intentionally NO INSERT INTO employee_application_access, role_permissions or employee_role_assignments.
