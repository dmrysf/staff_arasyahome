-- Confirmare source access (API 2.40.0, Phase 4). Additive and resumable: two new tables and three nullable columns.
-- Nothing is created for anybody, no order changes, and source access stays off until ARASYA_SOURCE_ACCESS_<SRC> is set
-- (and, in the shop, `wp yd-arasya access mode`). A source still cannot be activated (ConfirmationGate::WORKFLOW_READY).
--
--   operational_orders.source_contact_phone
--        Whether the shop holds a callable customer telephone number for the order (order.contact.phone, a boolean the
--        shop evaluates on its real billing and delivery telephone fields). NULL until a shop states it; the masked
--        delivery phone is then the fallback. Never the number.
--   operational_orders.source_financial_attention
--        Why a Confirmare order needs a financial decision after a change of its total (order.financial.attention):
--        additional_payment_due (paid less than the new total) or refund_due (paid more). Never an amount. It never
--        clears production; Phase 5 resolves it.
--   order_confirmation_source_reads
--        Append-only audit of every read of the shop's order detail through source access: who (employee, as assignee
--        or supervisor), which order, when, the outcome code, the shop's pricing fingerprint the read showed (a hash,
--        never an amount) and the request id. Never a telephone number, an address, an amount or a response body. No
--        code path updates or deletes a row. While source access is configured for a shop, an approval needs a
--        successful read by the approver showing the prices Arasya holds (API 2.40.0).
--   order_confirmation_source_edits
--        Append-only audit of every controlled order change sent to the shop (Phase 4B): who, which order, the
--        Idempotency-Key, the operations as codes (operation kinds, line ids and changed field names, never an amount),
--        the shop's order version before and after, the outcome and its state:
--          pending    sent, no answer yet;
--          applied    the shop confirmed the change;
--          rejected   the shop refused it (nothing changed);
--          unknown    the answer was lost (timeout); the same Idempotency-Key asks the shop again (reconciliation).
--        Rows are inserted as pending and completed exactly once (state and completion columns only).
--        source_modified_after is the shop's modification time of the edited order (the shop's clock, like
--        operational_orders.source_changed_at): an approval waits until Arasya has received that order update.

SET @arasya_038_contact_missing = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'operational_orders' AND column_name = 'source_contact_phone');

SET @arasya_038_contact = IF(@arasya_038_contact_missing,
    'ALTER TABLE operational_orders
        ADD COLUMN source_contact_phone TINYINT(1) NULL AFTER source_financial_pricing,
        ADD COLUMN source_financial_attention VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER source_contact_phone,
        ADD CONSTRAINT chk_operational_orders_contact_phone CHECK (source_contact_phone IS NULL OR source_contact_phone IN (0, 1)),
        ADD CONSTRAINT chk_operational_orders_financial_attention CHECK (source_financial_attention IS NULL OR (source_financial_attention IN (''additional_payment_due'', ''refund_due'') AND source_financial_state IN (''waiting'', ''partial'', ''unknown'')))',
    'DO 0');
PREPARE arasya_038_contact_statement FROM @arasya_038_contact;
EXECUTE arasya_038_contact_statement;
DEALLOCATE PREPARE arasya_038_contact_statement;

CREATE TABLE IF NOT EXISTS order_confirmation_source_reads (
    read_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    access_role VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    shop_pricing_fingerprint VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    read_at DATETIME(6) NOT NULL,
    PRIMARY KEY (read_uuid),
    KEY idx_order_confirmation_source_reads_order (order_uuid, read_at),
    KEY idx_order_confirmation_source_reads_employee (employee_uuid, read_at),
    CONSTRAINT fk_order_confirmation_source_reads_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_confirmation_source_reads_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_confirmation_source_reads_role CHECK (access_role IN ('assignee', 'supervisor')),
    CONSTRAINT chk_order_confirmation_source_reads_outcome CHECK (outcome REGEXP '^[a-z_]{2,40}$'),
    CONSTRAINT chk_order_confirmation_source_reads_pricing CHECK (shop_pricing_fingerprint IS NULL OR (outcome = 'ok' AND shop_pricing_fingerprint REGEXP '^sha256:[0-9a-f]{64}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_confirmation_source_edits (
    edit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    access_role VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash BINARY(32) NOT NULL,
    operations_json TEXT NOT NULL,
    source_version_before VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_version_after VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_modified_after DATETIME(6) NULL,
    state VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    confirmation_version INT UNSIGNED NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    requested_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (edit_uuid),
    UNIQUE KEY uq_order_confirmation_source_edits_key (employee_uuid, idempotency_key),
    KEY idx_order_confirmation_source_edits_order (order_uuid, requested_at),
    KEY idx_order_confirmation_source_edits_state (state, requested_at),
    CONSTRAINT fk_order_confirmation_source_edits_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_confirmation_source_edits_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_confirmation_source_edits_role CHECK (access_role IN ('assignee', 'supervisor')),
    CONSTRAINT chk_order_confirmation_source_edits_state CHECK (state IN ('pending', 'applied', 'rejected', 'unknown')),
    CONSTRAINT chk_order_confirmation_source_edits_versions CHECK (source_version_before REGEXP '^sha256:[0-9a-f]{64}$' AND (source_version_after IS NULL OR source_version_after REGEXP '^sha256:[0-9a-f]{64}$')),
    CONSTRAINT chk_order_confirmation_source_edits_complete CHECK ((state IN ('pending', 'unknown') AND completed_at IS NULL) OR (state = 'rejected' AND completed_at IS NOT NULL AND outcome IS NOT NULL) OR (state = 'applied' AND completed_at IS NOT NULL AND outcome IS NOT NULL AND source_version_after IS NOT NULL AND source_modified_after IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
