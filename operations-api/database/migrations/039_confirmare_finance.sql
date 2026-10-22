-- Confirmare finance (API 2.41.0, Phase 5). Additive and resumable: three new tables and one widened CHECK. No order,
-- confirmation, employee, role, permission, grant or scope row is created, changed or deleted, and nobody receives a
-- finance scope: without a row nobody sees an amount or acts (default deny, root included). Finance stays off until
-- ARASYA_SOURCE_FINANCE_<SRC> is set (and, in the shop, `wp yd-arasya access finance-mode`). A source still cannot be
-- activated (ConfirmationGate::WORKFLOW_READY). WooCommerce stays the authority for prices, payments, refunds and
-- balances: no table here holds an amount, a bank reference, a telephone number or a payment credential.
--
--   employee_finance_scopes
--        Which order sources an identity handles money for. Granted by root only (PUT /management/employees/{id}/
--        finance-scopes), recorded in iam_audit_events and the identity's authorization_version. Independent of the
--        Confirmare scopes: a Confirmare supervisor holds no finance capability through supervision.
--          view     reads the financial summary (amounts, read from the shop at the moment) and the finance queue;
--          record   also records a received bank transfer or a bank refund already paid out (never money received);
--          approve  also verifies or rejects what ANOTHER employee recorded and acknowledges a refund liability.
--   order_finance_reads
--        Append-only audit of every financial summary read through source access: who, in which role (finance,
--        assignee, supervisor), which order, the outcome code, the shop's summary revision (a hash) and the request id.
--   order_finance_actions
--        Append-only audit of every finance action sent to the shop: who, the action and decision codes, the shop's
--        finance entry id it concerns, the Idempotency-Key, an HMAC of the request (never the request: the amount is
--        low-entropy), the summary revision before and after, and the state:
--          pending   sent, no answer yet;  applied  the shop confirmed;  rejected  refused (nothing changed);
--          unknown   the answer was lost; the same key asks the shop again (reconciliation).
--        Rows are inserted as pending and completed exactly once. While one is pending or unknown, production cannot
--        start for the order (ConfirmationClearance `payment`).
--   operational_orders.source_financial_attention
--        Gains the Phase 5 classes (order.financial.attention, heartbeat fact `financeTasks`): transfer_pending and
--        reconciliation_required (with a closed state), refund_liability and refund_pending (an acknowledged refund
--        liability of a cleared order, to be refunded after the final manufacturing stage). Never an amount.

CREATE TABLE IF NOT EXISTS employee_finance_scopes (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    capability VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    granted_at DATETIME(6) NOT NULL,
    granted_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (employee_uuid, capability, source_key),
    KEY idx_employee_finance_scopes_source (source_key, capability),
    CONSTRAINT fk_employee_finance_scopes_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_finance_scopes_source FOREIGN KEY (source_key) REFERENCES order_sources (source_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_finance_scopes_granted_by FOREIGN KEY (granted_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_employee_finance_scopes_capability CHECK (capability IN ('view', 'record', 'approve'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_finance_reads (
    read_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    access_role VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    finance_revision VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    read_at DATETIME(6) NOT NULL,
    PRIMARY KEY (read_uuid),
    KEY idx_order_finance_reads_order (order_uuid, read_at),
    KEY idx_order_finance_reads_employee (employee_uuid, read_at),
    CONSTRAINT fk_order_finance_reads_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_finance_reads_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_finance_reads_role CHECK (access_role IN ('finance', 'assignee', 'supervisor')),
    CONSTRAINT chk_order_finance_reads_outcome CHECK (outcome REGEXP '^[a-z_]{2,40}$'),
    CONSTRAINT chk_order_finance_reads_revision CHECK (finance_revision IS NULL OR (outcome = 'ok' AND finance_revision REGEXP '^sha256:[0-9a-f]{64}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_finance_actions (
    action_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decision VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reason_code VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    purpose VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_entry_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hmac BINARY(32) NOT NULL,
    revision_before VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision_after VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NULL,
    state VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    requested_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (action_uuid),
    UNIQUE KEY uq_order_finance_actions_key (employee_uuid, idempotency_key),
    KEY idx_order_finance_actions_order (order_uuid, requested_at),
    KEY idx_order_finance_actions_state (state, requested_at),
    KEY idx_order_finance_actions_entry (source_entry_id),
    CONSTRAINT fk_order_finance_actions_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_finance_actions_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_finance_actions_action CHECK (action IN ('submit_transfer', 'decide_transfer', 'acknowledge_liability', 'submit_refund', 'decide_refund')),
    CONSTRAINT chk_order_finance_actions_decision CHECK ((decision IS NULL AND action NOT LIKE 'decide_%') OR (decision IN ('verify', 'reject') AND action LIKE 'decide_%')),
    CONSTRAINT chk_order_finance_actions_reason CHECK (reason_code IS NULL OR (decision = 'reject' AND reason_code IN ('not_received', 'amount_mismatch', 'wrong_order', 'duplicate', 'other'))),
    CONSTRAINT chk_order_finance_actions_purpose CHECK (purpose IS NULL OR purpose IN ('advance', 'additional_payment')),
    CONSTRAINT chk_order_finance_actions_state CHECK (state IN ('pending', 'applied', 'rejected', 'unknown')),
    CONSTRAINT chk_order_finance_actions_revisions CHECK (revision_before REGEXP '^sha256:[0-9a-f]{64}$' AND (revision_after IS NULL OR revision_after REGEXP '^sha256:[0-9a-f]{64}$')),
    CONSTRAINT chk_order_finance_actions_complete CHECK ((state IN ('pending', 'unknown') AND completed_at IS NULL) OR (state = 'rejected' AND completed_at IS NOT NULL AND outcome IS NOT NULL) OR (state = 'applied' AND completed_at IS NOT NULL AND outcome IS NOT NULL AND source_entry_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The 038 attention CHECK allowed only additional_payment_due and refund_due (never with `cleared`). It is replaced
-- once by the Phase 5 rule; a database that already has the new constraint keeps it.
SET @arasya_039_attention_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'operational_orders' AND constraint_name = 'chk_operational_orders_financial_attention');
SET @arasya_039_attention_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'operational_orders' AND constraint_name = 'chk_operational_orders_finance_attention');

SET @arasya_039_drop = IF(@arasya_039_attention_old,
    'ALTER TABLE operational_orders DROP CONSTRAINT chk_operational_orders_financial_attention',
    'DO 0');
PREPARE arasya_039_drop_statement FROM @arasya_039_drop;
EXECUTE arasya_039_drop_statement;
DEALLOCATE PREPARE arasya_039_drop_statement;

SET @arasya_039_add = IF(@arasya_039_attention_new,
    'ALTER TABLE operational_orders ADD CONSTRAINT chk_operational_orders_finance_attention CHECK (source_financial_attention IS NULL
        OR (source_financial_attention IN (''additional_payment_due'', ''refund_due'', ''transfer_pending'', ''reconciliation_required'') AND source_financial_state IN (''waiting'', ''partial'', ''unknown''))
        OR (source_financial_attention IN (''refund_liability'', ''refund_pending'') AND source_financial_state = ''cleared''))',
    'DO 0');
PREPARE arasya_039_add_statement FROM @arasya_039_add;
EXECUTE arasya_039_add_statement;
DEALLOCATE PREPARE arasya_039_add_statement;
