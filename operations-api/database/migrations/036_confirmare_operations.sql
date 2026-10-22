-- Confirmare operations (API 2.38.0, Phase 2): who may confirm orders, the order pool, the automatic distribution,
-- the decisions and their audit. Additive and resumable. No order, confirmation, employee, role, permission, grant or
-- scope row is created, changed or deleted, and nobody receives a scope: with no scope row nobody can see or decide a
-- Confirmare order (default deny). A source still cannot be activated (ConfirmationGate::WORKFLOW_READY = false).
--
--   employee_confirmare_scopes
--        Which order sources an identity confirms. Granted by root only (PUT /management/employees/{id}/confirmare-scopes),
--        recorded in iam_audit_events and in the identity's authorization_version, like document scopes (021):
--          operate    receives orders from the pool (automatic distribution or "next"), claims, releases and decides
--                     the orders assigned to them;
--          supervise  sees the whole pool of the source, reassigns, releases, decides any order and reopens a
--                     rejected one.
--        A scope grants no application: the identity also needs Staff or Dashboard access and an active account.
--   confirmare_operator_state
--        One row per identity that ever took part: whether it is available (default yes: an active identity with the
--        `operate` scope receives orders without opting in; leave is recorded here and returns its orders to the pool),
--        and when it last received one (fair distribution). Its row is the per-operator lock of the operator's own load.
--   order_confirmations (035) gains the current assignment, the decision reason (enumerated codes only) and the
--        production clearance: the confirmed customer call, the confirmed cart lines, the review fingerprint the
--        approval was given on, and production_cleared, which alone opens production (API 2.38.0: `approved` alone is
--        not enough).
--   operational_orders gains the source's pricing fingerprint (order.pricing, never an amount) and due date
--        (order.dueDate), both optional and only stored as sent.
--   confirmare_alerts
--        Supervisor alerts. An assignment without progress raises one alert; inactivity never reassigns by itself.
--   order_confirmation_events
--        Append-only audit of every change of a confirmation: opened, assigned, claimed, released, reassigned,
--        approved, rejected, reopened. Actor, before/after status and assignee, reason code, resulting version, request
--        and idempotency key. No customer data and no free text. No code path updates or deletes a row.
--   order_confirmation_idempotency
--        Replays of Confirmare commands (per employee and Idempotency-Key). Separate from order_operation_idempotency,
--        whose operation CHECK other open releases also extend.

CREATE TABLE IF NOT EXISTS employee_confirmare_scopes (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    capability VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    granted_at DATETIME(6) NOT NULL,
    granted_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (employee_uuid, capability, source_key),
    KEY idx_employee_confirmare_scopes_source (source_key, capability),
    CONSTRAINT fk_employee_confirmare_scopes_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_confirmare_scopes_source FOREIGN KEY (source_key) REFERENCES order_sources (source_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_confirmare_scopes_granted_by FOREIGN KEY (granted_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_employee_confirmare_scopes_capability CHECK (capability IN ('operate', 'supervise'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS confirmare_operator_state (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    available TINYINT(1) NOT NULL DEFAULT 1,
    available_changed_at DATETIME(6) NULL,
    available_changed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_assigned_at DATETIME(6) NULL,
    PRIMARY KEY (employee_uuid),
    CONSTRAINT fk_confirmare_operator_state_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_confirmare_operator_state_changed_by FOREIGN KEY (available_changed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_confirmare_operator_state_available CHECK (available IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @arasya_036_assignment_missing = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_confirmations' AND column_name = 'assigned_employee_uuid');

SET @arasya_036_alter = IF(@arasya_036_assignment_missing,
    'ALTER TABLE order_confirmations
        ADD COLUMN assigned_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER version,
        ADD COLUMN assigned_at DATETIME(6) NULL AFTER assigned_employee_uuid,
        ADD COLUMN assignment_kind VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER assigned_at,
        ADD COLUMN decision_reason_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER decided_by_employee_uuid,
        ADD COLUMN call_confirmed_at DATETIME(6) NULL AFTER decision_reason_code,
        ADD COLUMN call_confirmed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER call_confirmed_at,
        ADD COLUMN confirmed_line_ids TEXT NULL AFTER call_confirmed_by_employee_uuid,
        ADD COLUMN approved_review_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER confirmed_line_ids,
        ADD COLUMN production_cleared TINYINT(1) NOT NULL DEFAULT 0 AFTER approved_review_fingerprint,
        ADD COLUMN cleared_at DATETIME(6) NULL AFTER production_cleared,
        ADD KEY idx_order_confirmations_assignee (assigned_employee_uuid, status),
        ADD CONSTRAINT fk_order_confirmations_assignee FOREIGN KEY (assigned_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
        ADD CONSTRAINT chk_order_confirmations_assignment CHECK ((assigned_employee_uuid IS NULL AND assigned_at IS NULL AND assignment_kind IS NULL) OR (assigned_employee_uuid IS NOT NULL AND assigned_at IS NOT NULL AND assignment_kind IN (''auto'', ''claim'', ''supervisor''))),
        ADD CONSTRAINT chk_order_confirmations_assigned_pending CHECK (assigned_employee_uuid IS NULL OR status = ''pending''),
        ADD CONSTRAINT chk_order_confirmations_reason CHECK (decision_reason_code IS NULL OR status = ''rejected''),
        ADD CONSTRAINT fk_order_confirmations_caller FOREIGN KEY (call_confirmed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
        ADD CONSTRAINT chk_order_confirmations_cleared CHECK (production_cleared IN (0, 1) AND (production_cleared = 0 OR (status = ''approved'' AND approved_review_fingerprint IS NOT NULL AND call_confirmed_at IS NOT NULL AND cleared_at IS NOT NULL)))',
    'DO 0');
PREPARE arasya_036_statement FROM @arasya_036_alter;
EXECUTE arasya_036_statement;
DEALLOCATE PREPARE arasya_036_statement;

SET @arasya_036_facts_missing = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'operational_orders' AND column_name = 'source_pricing_fingerprint');

SET @arasya_036_facts = IF(@arasya_036_facts_missing,
    'ALTER TABLE operational_orders
        ADD COLUMN source_pricing_fingerprint VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER source_submission_evidence,
        ADD COLUMN source_due_date DATE NULL AFTER source_pricing_fingerprint,
        ADD CONSTRAINT chk_operational_orders_pricing_fingerprint CHECK (source_pricing_fingerprint IS NULL OR source_pricing_fingerprint REGEXP ''^sha256:[0-9a-f]{64}$'')',
    'DO 0');
PREPARE arasya_036_facts_statement FROM @arasya_036_facts;
EXECUTE arasya_036_facts_statement;
DEALLOCATE PREPARE arasya_036_facts_statement;

-- Confirmare financial clearance stated by the source (order.financial): states and bases only, never an amount.
SET @arasya_036_financial_missing = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'operational_orders' AND column_name = 'source_financial_state');

SET @arasya_036_financial = IF(@arasya_036_financial_missing,
    'ALTER TABLE operational_orders
        ADD COLUMN source_financial_state VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER source_due_date,
        ADD COLUMN source_financial_basis VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER source_financial_state,
        ADD COLUMN source_financial_pricing VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER source_financial_basis,
        ADD CONSTRAINT chk_operational_orders_financial_state CHECK (source_financial_state IS NULL OR source_financial_state IN (''cleared'', ''waiting'', ''partial'', ''unknown'')),
        ADD CONSTRAINT chk_operational_orders_financial_basis CHECK (source_financial_basis IS NULL OR source_financial_basis IN (''online_payment'', ''zero_total'', ''advance_collected'')),
        ADD CONSTRAINT chk_operational_orders_financial_pricing CHECK (source_financial_pricing IS NULL OR source_financial_pricing REGEXP ''^sha256:[0-9a-f]{64}$''),
        ADD CONSTRAINT chk_operational_orders_financial_complete CHECK (
            (source_financial_state IS NULL AND source_financial_basis IS NULL AND source_financial_pricing IS NULL)
            OR (source_financial_state = ''cleared'' AND source_financial_basis IS NOT NULL AND source_financial_pricing IS NOT NULL)
            OR (source_financial_state IN (''waiting'', ''partial'', ''unknown'') AND source_financial_basis IS NULL))',
    'DO 0');
PREPARE arasya_036_financial_statement FROM @arasya_036_financial;
EXECUTE arasya_036_financial_statement;
DEALLOCATE PREPARE arasya_036_financial_statement;

CREATE TABLE IF NOT EXISTS confirmare_alerts (
    alert_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    confirmation_version INT UNSIGNED NOT NULL,
    raised_at DATETIME(6) NOT NULL,
    resolved_at DATETIME(6) NULL,
    PRIMARY KEY (alert_uuid),
    UNIQUE KEY uq_confirmare_alerts_once (order_uuid, kind, confirmation_version),
    KEY idx_confirmare_alerts_open (source_key, resolved_at),
    CONSTRAINT fk_confirmare_alerts_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_confirmare_alerts_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_confirmare_alerts_kind CHECK (kind IN ('inactive_assignment'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_confirmation_events (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_kind VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    previous_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    new_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    previous_assignee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    new_assignee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reason_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    version_after INT UNSIGNED NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_uuid),
    UNIQUE KEY uq_order_confirmation_events_version (order_uuid, version_after),
    KEY idx_order_confirmation_events_source_time (source_key, occurred_at),
    KEY idx_order_confirmation_events_actor (actor_employee_uuid, occurred_at),
    CONSTRAINT fk_order_confirmation_events_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_confirmation_events_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_confirmation_events_action CHECK (action IN ('opened', 'assigned', 'claimed', 'released', 'reassigned', 'approved', 'rejected', 'reopened')),
    CONSTRAINT chk_order_confirmation_events_actor CHECK ((actor_kind = 'employee' AND actor_employee_uuid IS NOT NULL) OR (actor_kind = 'system' AND actor_employee_uuid IS NULL)),
    CONSTRAINT chk_order_confirmation_events_system CHECK (actor_kind = 'employee' OR action IN ('opened', 'assigned', 'released')),
    CONSTRAINT chk_order_confirmation_events_status CHECK (new_status IN ('pending', 'approved', 'rejected', 'cancelled') AND (previous_status IS NULL OR previous_status IN ('pending', 'approved', 'rejected', 'cancelled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_confirmation_idempotency (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_hash BINARY(32) NOT NULL,
    response_json TEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (employee_uuid, idempotency_key),
    KEY idx_order_confirmation_idempotency_created (created_at),
    CONSTRAINT fk_order_confirmation_idempotency_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_confirmation_idempotency_operation CHECK (operation IN ('next', 'claim', 'release', 'reassign', 'approve', 'reject', 'reopen', 'availability'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
