-- Migration 044 (API 2.50.0): explicit finance verification requests, finance supervision and the `verify` finance scope.
--
-- Additive: three new tables, no row, no grant. Two CHECKs are replaced to accept one more value each: the capability
-- of employee_finance_scopes (`verify`) and the action of order_finance_actions (`verify_transfer`). Both tables hold few
-- rows (a short DDL lock); each swap is guarded by information_schema like 039, so a re-run or an interrupted run
-- completes and a completed run changes nothing. No row is read, written or rewritten. Applied by bin/migrate.php with the API deploy, never by hand.
-- Rollback: older API versions never read these tables and never meet a `verify` row unless root granted one; they are
-- left in place (no DROP), and bin/check-rollback.php keeps the floor at 2.50.0 while a request is open.
--
--   employee_finance_scopes `verify`
--        The new, explicitly granted capability to complete a bank verification alone (single verification). It acts
--        only when the financial policy of the source is `single` in BOTH Operations (ARASYA_SOURCE_FINANCE_VERIFICATION_
--        <SRC>) and the shop (`wp yd-arasya access finance-verification single`). Both default to `four_eyes`: until the
--        owner and the accountant sign off, a verification still needs a second employee. Never derived from a role,
--        a department, a title or a name; root grants it (PUT /management/employees/{id}/finance-scopes) and holds none.
--   employee_finance_supervision
--        Which finance employees a finance supervisor observes. Explicit pairs, granted by root only
--        (PUT /management/employees/{id}/finance-supervision), audited in iam_audit_events. Observation only: a pair never
--        grants a finance action; nobody supervises themselves.
--   finance_verification_requests
--        One payment verification a person asked for: one source, one canonical order, one purpose (`advance`), the
--        advance proforma it is about and the finance employee responsible for it. Created only by an explicit command of
--        the order's Confirmare operator or a supervisor (POST /confirmare/orders/{id}/finance-request), never by a
--        page load or derived from a money state. `open_order_uuid` holds the order while the request is open, so an
--        order has at most one open request (a refresh, a second tab or a second operator gets the open one). `version`
--        grows with every change; every command names the version it saw.
--        Minimized: only the proforma's series and number are kept, to notice a cancelled or replaced proforma. No
--        amount, currency, payment reference, IBAN or bank transaction is stored here: the task screen reads them from
--        the shop at the moment of the read (audited in order_finance_reads), and every decision reads the shop again.
--        The bank transaction found on the statement (amount, reference, value date) stays in the shop's finance
--        record; this table keeps the shop's entry id and an HMAC of the evidence only. No customer name, address,
--        telephone number or product is stored.
--   finance_verification_request_events
--        Append-only history of every request: who (or the system), when, the state before and after, the assignee,
--        the outcome and reason codes, the policy in force and the Idempotency-Key of the command.
CREATE TABLE IF NOT EXISTS employee_finance_supervision (
    supervisor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    granted_at DATETIME(6) NOT NULL,
    granted_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (supervisor_employee_uuid, employee_uuid),
    KEY idx_employee_finance_supervision_employee (employee_uuid),
    CONSTRAINT fk_employee_finance_supervision_supervisor FOREIGN KEY (supervisor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_finance_supervision_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_finance_supervision_granted_by FOREIGN KEY (granted_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_employee_finance_supervision_pair CHECK (supervisor_employee_uuid <> employee_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_verification_requests (
    request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    purpose VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version INT UNSIGNED NOT NULL,
    open_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    assigned_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    assigned_at DATETIME(6) NULL,
    requested_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    requested_at DATETIME(6) NOT NULL,
    request_idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    proforma_series VARCHAR(40) NULL,
    proforma_number VARCHAR(40) NOT NULL,
    policy VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    finance_idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    recorded_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_entry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    evidence_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    outcome VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reason_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decided_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decided_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (request_uuid),
    UNIQUE KEY uq_finance_verification_requests_open (open_order_uuid),
    UNIQUE KEY uq_finance_verification_requests_key (requested_by_employee_uuid, request_idempotency_key),
    KEY idx_finance_verification_requests_assignee (assigned_employee_uuid, state),
    KEY idx_finance_verification_requests_source (source_key, state, updated_at),
    KEY idx_finance_verification_requests_order (order_uuid, requested_at),
    CONSTRAINT fk_finance_verification_requests_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_verification_requests_source FOREIGN KEY (source_key) REFERENCES order_sources (source_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_verification_requests_assignee FOREIGN KEY (assigned_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_verification_requests_requester FOREIGN KEY (requested_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_verification_requests_decider FOREIGN KEY (decided_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_verification_requests_purpose CHECK (purpose IN ('advance')),
    CONSTRAINT chk_finance_verification_requests_state CHECK (state IN ('requested', 'assigned', 'checking', 'verified', 'not_received', 'needs_clarification', 'exception', 'cancelled')),
    CONSTRAINT chk_finance_verification_requests_open CHECK ((state IN ('verified', 'not_received', 'cancelled')) = (open_order_uuid IS NULL) AND (open_order_uuid IS NULL OR open_order_uuid = order_uuid)),
    CONSTRAINT chk_finance_verification_requests_assigned CHECK ((state <> 'requested' OR assigned_employee_uuid IS NULL) AND (state NOT IN ('assigned', 'checking') OR assigned_employee_uuid IS NOT NULL)),
    CONSTRAINT chk_finance_verification_requests_policy CHECK (policy IS NULL OR policy IN ('four_eyes', 'single')),
    CONSTRAINT chk_finance_verification_requests_decided CHECK ((state IN ('verified', 'not_received')) = (decided_at IS NOT NULL AND decided_by_employee_uuid IS NOT NULL AND outcome IS NOT NULL)),
    CONSTRAINT chk_finance_verification_requests_version CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_verification_request_events (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version_after INT UNSIGNED NOT NULL,
    action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    previous_state VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    new_state VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    assigned_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    outcome VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reason_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    policy VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_uuid),
    UNIQUE KEY uq_finance_verification_request_events_version (request_uuid, version_after),
    UNIQUE KEY uq_finance_verification_request_events_key (actor_employee_uuid, idempotency_key),
    KEY idx_finance_verification_request_events_time (occurred_at),
    CONSTRAINT fk_finance_verification_request_events_request FOREIGN KEY (request_uuid) REFERENCES finance_verification_requests (request_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_finance_verification_request_events_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_verification_request_events_state CHECK (new_state IN ('requested', 'assigned', 'checking', 'verified', 'not_received', 'needs_clarification', 'exception', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The 039 capability CHECK allowed view, record and approve. It is replaced once to also accept `verify`.
SET @arasya_044_capability_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'employee_finance_scopes' AND constraint_name = 'chk_employee_finance_scopes_capability');
SET @arasya_044_capability_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'employee_finance_scopes' AND constraint_name = 'chk_employee_finance_scopes_capability_044');

SET @arasya_044_drop = IF(@arasya_044_capability_old,
    'ALTER TABLE employee_finance_scopes DROP CONSTRAINT chk_employee_finance_scopes_capability',
    'DO 0');
PREPARE arasya_044_drop_statement FROM @arasya_044_drop;
EXECUTE arasya_044_drop_statement;
DEALLOCATE PREPARE arasya_044_drop_statement;

SET @arasya_044_add = IF(@arasya_044_capability_new,
    'ALTER TABLE employee_finance_scopes ADD CONSTRAINT chk_employee_finance_scopes_capability_044 CHECK (capability IN (''view'', ''record'', ''approve'', ''verify''))',
    'DO 0');
PREPARE arasya_044_add_statement FROM @arasya_044_add;
EXECUTE arasya_044_add_statement;
DEALLOCATE PREPARE arasya_044_add_statement;

-- The 039 action CHECK of order_finance_actions is replaced once to also accept `verify_transfer` (the single verification
-- relayed to the shop; the other actions and every other CHECK of the table are unchanged).
SET @arasya_044_action_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_finance_actions' AND constraint_name = 'chk_order_finance_actions_action');
SET @arasya_044_action_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_finance_actions' AND constraint_name = 'chk_order_finance_actions_action_044');

SET @arasya_044_action_drop = IF(@arasya_044_action_old,
    'ALTER TABLE order_finance_actions DROP CONSTRAINT chk_order_finance_actions_action',
    'DO 0');
PREPARE arasya_044_action_drop_statement FROM @arasya_044_action_drop;
EXECUTE arasya_044_action_drop_statement;
DEALLOCATE PREPARE arasya_044_action_drop_statement;

SET @arasya_044_action_add = IF(@arasya_044_action_new,
    'ALTER TABLE order_finance_actions ADD CONSTRAINT chk_order_finance_actions_action_044 CHECK (action IN (''submit_transfer'', ''decide_transfer'', ''acknowledge_liability'', ''submit_refund'', ''decide_refund'', ''verify_transfer''))',
    'DO 0');
PREPARE arasya_044_action_add_statement FROM @arasya_044_action_add;
EXECUTE arasya_044_action_add_statement;
DEALLOCATE PREPARE arasya_044_action_add_statement;
