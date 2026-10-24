-- DRAFT (API 2.43.0, SmartBill invoicing through Arasya). NOT a migration: bin/migrate.php reads database/migrations
-- only, so this file is never applied by a deploy. It becomes a numbered migration only with the owner's approval of
-- the number (040 is not to be used without it). Until then the invoicing code finds no table, reports
-- `not_configured`, and nobody can read or act (fail closed). The integration tests apply it to their own test
-- database explicitly.
--
-- Additive only: three new tables. No order, document, employee, role, permission, grant or scope row is created,
-- changed or deleted, and nobody receives an invoice scope (default deny, root included: root grants, holds none).
-- No table here holds an amount, a document link, a customer e-mail, a tax identifier or a SmartBill credential: the
-- shop (WooCommerce and its SmartBill plugin) stays the authority for every document.
--
--   employee_invoice_scopes
--        Which order sources an identity handles SmartBill documents for. Granted by root only (PUT /management/
--        employees/{id}/invoice-scopes), recorded in iam_audit_events and the identity's authorization_version.
--          issue   issues the first document of a Confirmare order and sends it to the customer; only together with the
--                  production document permission and the document `operate` scope of the same source (the employee who
--                  prepares the production ticket and its QR), never the Confirmare operator who approved the order;
--          manage  also re-issues a document and closes an unknown outcome after checking SmartBill (a manager).
--   order_invoice_reads
--        Append-only audit of every invoice state read through source access: who, in which role (documents, manager,
--        finance, viewer), which order, the outcome code, the shop's invoice revision (a hash) and the request id.
--   order_invoice_actions
--        Append-only audit of every invoice action sent to the shop: who, in which role, the action and reason codes,
--        the Idempotency-Key, an HMAC of the request, the revision before, the state (pending, applied, rejected,
--        unknown, as for finance actions) and the shop's result code (issued, unknown, sent, ...).

CREATE TABLE IF NOT EXISTS employee_invoice_scopes (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    capability VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    granted_at DATETIME(6) NOT NULL,
    granted_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (employee_uuid, capability, source_key),
    KEY idx_employee_invoice_scopes_source (source_key, capability),
    CONSTRAINT fk_employee_invoice_scopes_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_invoice_scopes_source FOREIGN KEY (source_key) REFERENCES order_sources (source_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_invoice_scopes_granted_by FOREIGN KEY (granted_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_employee_invoice_scopes_capability CHECK (capability IN ('issue', 'manage'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_invoice_reads (
    read_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    access_role VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    invoice_revision VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    read_at DATETIME(6) NOT NULL,
    PRIMARY KEY (read_uuid),
    KEY idx_order_invoice_reads_order (order_uuid, read_at),
    KEY idx_order_invoice_reads_employee (employee_uuid, read_at),
    CONSTRAINT fk_order_invoice_reads_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_invoice_reads_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_invoice_reads_role CHECK (access_role IN ('documents', 'manager', 'finance', 'viewer')),
    CONSTRAINT chk_order_invoice_reads_outcome CHECK (outcome REGEXP '^[a-z_]{2,40}$'),
    CONSTRAINT chk_order_invoice_reads_revision CHECK (invoice_revision IS NULL OR (outcome = 'ok' AND invoice_revision REGEXP '^sha256:[0-9a-f]{64}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_invoice_actions (
    action_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    access_role VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason_code VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hmac BINARY(32) NOT NULL,
    revision_before VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    result VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    requested_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (action_uuid),
    UNIQUE KEY uq_order_invoice_actions_key (employee_uuid, idempotency_key),
    KEY idx_order_invoice_actions_order (order_uuid, requested_at),
    KEY idx_order_invoice_actions_state (state, requested_at),
    CONSTRAINT fk_order_invoice_actions_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_invoice_actions_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_invoice_actions_role CHECK (access_role IN ('documents', 'manager')),
    CONSTRAINT chk_order_invoice_actions_action CHECK (action IN ('issue', 'send_mail', 'reissue', 'resolve')),
    CONSTRAINT chk_order_invoice_actions_reason CHECK (reason_code IS NULL OR (action = 'reissue' AND reason_code IN ('order_changed', 'customer_data', 'wrong_document', 'other'))),
    CONSTRAINT chk_order_invoice_actions_state CHECK (state IN ('pending', 'applied', 'rejected', 'unknown')),
    CONSTRAINT chk_order_invoice_actions_result CHECK (result IS NULL OR result REGEXP '^[a-z_]{2,20}$'),
    CONSTRAINT chk_order_invoice_actions_revision CHECK (revision_before REGEXP '^sha256:[0-9a-f]{64}$'),
    CONSTRAINT chk_order_invoice_actions_complete CHECK ((state IN ('pending', 'unknown') AND completed_at IS NULL) OR (state IN ('applied', 'rejected') AND completed_at IS NOT NULL AND outcome IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
