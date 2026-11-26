-- Migration 063 (API 2.82.0, Operation Simplification V1, owner decision 2026-11-26): the operator decides an order with
-- one of three buttons (green: the customer confirmed, yellow: waiting, red: the customer refused) and the system does the
-- rest; a verified online payment goes to the shop employee without the operator.
--
-- 1. order_confirmations gains the optional "waiting" mark of a pending order (`hold_at`, `hold_by_employee_uuid`, an
--    optional short `hold_note` and an optional `callback_at`). Nothing is required and nothing locks the order.
-- 2. order_confirmation_events accepts the action `held` (the yellow decision); the system may record `approved` only with
--    the reason `online_payment_verified` (the shop verified the online payment with its payment provider; production stays
--    closed until the shop employee's customer check). The old CHECKs are replaced once, every other value unchanged.
-- 3. order_confirmation_idempotency accepts the operations `hold` and `undo`.
-- 4. confirmare_decision_followups: what the system still has to do after a decision (green: the customer's acceptance of
--    the current total in the shop, then the advance proforma through the existing proforma cycle; red: the order's
--    cancellation request after a short grace period, so a wrong click can be undone before anything leaves Arasya). One
--    active row per order; every step is sent with its own idempotency key and recorded like any other invoice action.
-- 5. confirmare_order_display: what the operator's list shows next to an order (the order total and currency the shop showed
--    at its last read, the number of lines, warnings by code), written by every shop read of the order and by the display
--    cron, never by hand. Display only: every decision reads the shop again; the list never asks the shop row by row.
--
-- Additive: nullable columns, one new table, wider CHECKs. No existing row changes. Idempotent (each step checks
-- information_schema first). Rollback: an older API never writes `held`, `hold`, `undo` or the system approval and never
-- reads the new columns or table; they can stay.

SET @arasya_063_hold = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_confirmations' AND column_name = 'hold_at');
SET @arasya_063_hold_add = IF(@arasya_063_hold,
    'ALTER TABLE order_confirmations
        ADD COLUMN hold_at DATETIME(6) NULL,
        ADD COLUMN hold_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD COLUMN hold_note VARCHAR(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        ADD COLUMN callback_at DATETIME(6) NULL,
        ADD KEY idx_order_confirmations_hold (status, hold_at),
        ADD CONSTRAINT fk_order_confirmations_hold_by FOREIGN KEY (hold_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
        ADD CONSTRAINT chk_order_confirmations_hold CHECK ((hold_at IS NULL AND hold_by_employee_uuid IS NULL AND hold_note IS NULL AND callback_at IS NULL) OR (hold_at IS NOT NULL AND hold_by_employee_uuid IS NOT NULL AND status = ''pending''))',
    'DO 0');
PREPARE arasya_063_hold_statement FROM @arasya_063_hold_add;
EXECUTE arasya_063_hold_statement;
DEALLOCATE PREPARE arasya_063_hold_statement;

-- 2. Event actions and the system approval.
SET @arasya_063_action_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_confirmation_events' AND constraint_name = 'chk_order_confirmation_events_action');
SET @arasya_063_action_drop = IF(@arasya_063_action_old, 'ALTER TABLE order_confirmation_events DROP CONSTRAINT chk_order_confirmation_events_action', 'DO 0');
PREPARE arasya_063_action_drop_statement FROM @arasya_063_action_drop;
EXECUTE arasya_063_action_drop_statement;
DEALLOCATE PREPARE arasya_063_action_drop_statement;

SET @arasya_063_action_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_confirmation_events' AND constraint_name = 'chk_order_confirmation_events_action_063');
SET @arasya_063_action_add = IF(@arasya_063_action_new,
    'ALTER TABLE order_confirmation_events ADD CONSTRAINT chk_order_confirmation_events_action_063 CHECK (action IN (''opened'', ''assigned'', ''claimed'', ''released'', ''reassigned'', ''approved'', ''rejected'', ''reopened'', ''held''))',
    'DO 0');
PREPARE arasya_063_action_add_statement FROM @arasya_063_action_add;
EXECUTE arasya_063_action_add_statement;
DEALLOCATE PREPARE arasya_063_action_add_statement;

SET @arasya_063_system_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_confirmation_events' AND constraint_name = 'chk_order_confirmation_events_system');
SET @arasya_063_system_drop = IF(@arasya_063_system_old, 'ALTER TABLE order_confirmation_events DROP CONSTRAINT chk_order_confirmation_events_system', 'DO 0');
PREPARE arasya_063_system_drop_statement FROM @arasya_063_system_drop;
EXECUTE arasya_063_system_drop_statement;
DEALLOCATE PREPARE arasya_063_system_drop_statement;

SET @arasya_063_system_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_confirmation_events' AND constraint_name = 'chk_order_confirmation_events_system_063');
SET @arasya_063_system_add = IF(@arasya_063_system_new,
    'ALTER TABLE order_confirmation_events ADD CONSTRAINT chk_order_confirmation_events_system_063 CHECK (actor_kind = ''employee'' OR action IN (''opened'', ''assigned'', ''released'') OR (action = ''approved'' AND COALESCE(reason_code, '''') = ''online_payment_verified''))',
    'DO 0');
PREPARE arasya_063_system_add_statement FROM @arasya_063_system_add;
EXECUTE arasya_063_system_add_statement;
DEALLOCATE PREPARE arasya_063_system_add_statement;

-- 3. Idempotency operations.
SET @arasya_063_operation_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_confirmation_idempotency' AND constraint_name = 'chk_order_confirmation_idempotency_operation');
SET @arasya_063_operation_drop = IF(@arasya_063_operation_old, 'ALTER TABLE order_confirmation_idempotency DROP CONSTRAINT chk_order_confirmation_idempotency_operation', 'DO 0');
PREPARE arasya_063_operation_drop_statement FROM @arasya_063_operation_drop;
EXECUTE arasya_063_operation_drop_statement;
DEALLOCATE PREPARE arasya_063_operation_drop_statement;

SET @arasya_063_operation_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_confirmation_idempotency' AND constraint_name = 'chk_order_confirmation_idempotency_operation_063');
SET @arasya_063_operation_add = IF(@arasya_063_operation_new,
    'ALTER TABLE order_confirmation_idempotency ADD CONSTRAINT chk_order_confirmation_idempotency_operation_063 CHECK (operation IN (''next'', ''claim'', ''release'', ''reassign'', ''approve'', ''reject'', ''reopen'', ''availability'', ''hold'', ''undo''))',
    'DO 0');
PREPARE arasya_063_operation_add_statement FROM @arasya_063_operation_add;
EXECUTE arasya_063_operation_add_statement;
DEALLOCATE PREPARE arasya_063_operation_add_statement;

-- 4. What the system still does after a decision.
CREATE TABLE IF NOT EXISTS confirmare_decision_followups (
    followup_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decision_version INT UNSIGNED NOT NULL,
    state VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    step VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    step_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    due_at DATETIME(6) NOT NULL,
    claim_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claimed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    closed_at DATETIME(6) NULL,
    closed_reason VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (followup_uuid),
    UNIQUE KEY uq_confirmare_decision_followups_active (active_order_uuid),
    KEY idx_confirmare_decision_followups_due (state, due_at),
    KEY idx_confirmare_decision_followups_order (order_uuid, created_at),
    CONSTRAINT fk_confirmare_decision_followups_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_confirmare_decision_followups_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_confirmare_decision_followups_kind CHECK (kind IN ('confirmed', 'refused')),
    CONSTRAINT chk_confirmare_decision_followups_state CHECK (state IN ('pending', 'waiting', 'done', 'cancelled', 'failed')),
    CONSTRAINT chk_confirmare_decision_followups_step CHECK (step IN ('accept_total', 'start_proforma', 'request_cancel', 'finished')),
    CONSTRAINT chk_confirmare_decision_followups_active CHECK ((state IN ('done', 'cancelled', 'failed') AND active_order_uuid IS NULL AND closed_at IS NOT NULL)
        OR (state IN ('pending', 'waiting') AND active_order_uuid = order_uuid AND closed_at IS NULL)),
    CONSTRAINT chk_confirmare_decision_followups_codes CHECK ((last_error IS NULL OR last_error REGEXP '^[a-z_]{2,40}$') AND (closed_reason IS NULL OR closed_reason REGEXP '^[a-z_]{2,40}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. The operator list's display copy.
CREATE TABLE IF NOT EXISTS confirmare_order_display (
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_total DECIMAL(12,2) NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    line_count INT UNSIGNED NULL,
    warnings VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NULL,
    pricing_fingerprint VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NULL,
    read_at DATETIME(6) NOT NULL,
    PRIMARY KEY (order_uuid),
    KEY idx_confirmare_order_display_read (read_at),
    CONSTRAINT fk_confirmare_order_display_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_confirmare_order_display_warnings CHECK (warnings IS NULL OR warnings REGEXP '^[a-z_]{2,40}(,[a-z_]{2,40})*$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
