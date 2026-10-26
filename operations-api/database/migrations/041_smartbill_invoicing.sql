-- Migration 041 (API 2.45.0): SmartBill invoicing through Arasya (API 2.43.0), the advance proforma, shop routing and the
-- invoice QR check (API 2.44.0), the proforma cancellation, the telephone acceptance of the total and a smaller advance
-- accepted (API 2.45.0). Until 2026-10-25 this was the draft database/drafts/smartbill-invoicing.sql; the owner asked
-- for a free number on 2026-10-25. 040 stays reserved for unrelated work (no 040 file exists on any branch, stash or
-- remote of this repository when 041 was chosen); MigrationRunner applies every file not yet recorded in
-- schema_migrations in name order, so a later 040 is applied on its own and changes nothing here. Every statement is
-- CREATE TABLE IF NOT EXISTS: running it again changes nothing. Applied by bin/migrate.php with the API deploy, never by
-- hand; before it exists the invoicing code finds no table, reports `not_configured` and nobody can read or act (fail
-- closed). Rollback: the tables are additive and unused by older API versions; they are left in place (no DROP).
--
-- Additive only: five new tables. No order, document, employee, role, permission, grant or scope row is created,
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
--        2.44.0: also the Confirmare operator's advance proforma actions (role `operator`; proforma_issue,
--        proforma_send_mail, proforma_check, proforma_resolve).
--        2.45.0: proforma_cancel (with its reason code), total_accept (the customer's telephone acceptance of the total)
--        and advance_accept (a smaller verified advance accepted). No amount, note or customer data is stored here.
--   order_shop_assignments (2.44.0)
--        The operator routes a Confirmare order whose money is proven to ONE shop employee (production tickets and invoice
--        `issue` scope of the order's shop). At most one active assignment per order (unique `active_order_uuid`); a
--        reassignment releases the previous row (kept, never deleted). Codes and identities only.
--   order_invoice_qr_checks (2.44.0)
--        Append-only: the shop employee scanned the order's QR before the final invoice. Which employee, which QR, which
--        active document revision, the outcome (verified, or why not). The final invoice needs a verified check of the
--        current revision by the same employee within 30 minutes. The QR reference carries no order data.

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
    CONSTRAINT chk_order_invoice_reads_role CHECK (access_role IN ('documents', 'manager', 'finance', 'viewer', 'operator')),
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
    CONSTRAINT chk_order_invoice_actions_role CHECK (access_role IN ('documents', 'manager', 'operator')),
    CONSTRAINT chk_order_invoice_actions_action CHECK (action IN ('issue', 'send_mail', 'reissue', 'resolve', 'proforma_issue', 'proforma_send_mail', 'proforma_check', 'proforma_resolve',
        'proforma_cancel', 'total_accept', 'advance_accept')),
    CONSTRAINT chk_order_invoice_actions_reason CHECK (reason_code IS NULL OR (action = 'reissue' AND reason_code IN ('order_changed', 'customer_data', 'wrong_document', 'other'))
        OR (action = 'proforma_cancel' AND reason_code IN ('order_changed', 'customer_request', 'wrong_details', 'duplicate', 'other'))),
    CONSTRAINT chk_order_invoice_actions_state CHECK (state IN ('pending', 'applied', 'rejected', 'unknown')),
    CONSTRAINT chk_order_invoice_actions_result CHECK (result IS NULL OR result REGEXP '^[a-z_]{2,20}$'),
    CONSTRAINT chk_order_invoice_actions_revision CHECK (revision_before REGEXP '^sha256:[0-9a-f]{64}$'),
    CONSTRAINT chk_order_invoice_actions_complete CHECK ((state IN ('pending', 'unknown') AND completed_at IS NULL) OR (state IN ('applied', 'rejected') AND completed_at IS NOT NULL AND outcome IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_shop_assignments (
    assignment_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    assigned_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    assigned_role VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hmac BINARY(32) NOT NULL,
    state VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (IF(state = 'active', RTRIM(order_uuid), NULL)) STORED,
    assigned_at DATETIME(6) NOT NULL,
    released_at DATETIME(6) NULL,
    released_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    release_reason VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (assignment_uuid),
    UNIQUE KEY uq_order_shop_assignments_active (active_order_uuid),
    UNIQUE KEY uq_order_shop_assignments_key (assigned_by_employee_uuid, idempotency_key),
    KEY idx_order_shop_assignments_order (order_uuid, assigned_at),
    KEY idx_order_shop_assignments_employee (employee_uuid, state, assigned_at),
    CONSTRAINT fk_order_shop_assignments_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_shop_assignments_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_shop_assignments_by FOREIGN KEY (assigned_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_shop_assignments_released_by FOREIGN KEY (released_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_shop_assignments_role CHECK (assigned_role IN ('operator', 'manager')),
    CONSTRAINT chk_order_shop_assignments_state CHECK ((state = 'active' AND released_at IS NULL AND release_reason IS NULL)
        OR (state = 'released' AND released_at IS NOT NULL AND release_reason IN ('reassigned'))),
    CONSTRAINT chk_order_shop_assignments_not_self CHECK (employee_uuid <> assigned_by_employee_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_invoice_qr_checks (
    check_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    qr_reference CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    revision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    outcome VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    checked_at DATETIME(6) NOT NULL,
    PRIMARY KEY (check_uuid),
    KEY idx_order_invoice_qr_checks_order (order_uuid, employee_uuid, checked_at),
    CONSTRAINT fk_order_invoice_qr_checks_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_invoice_qr_checks_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_invoice_qr_checks_outcome CHECK (outcome IN ('verified', 'unknown_qr', 'wrong_order', 'inactive_qr', 'stale_document')),
    CONSTRAINT chk_order_invoice_qr_checks_verified CHECK (outcome <> 'verified' OR (qr_reference IS NOT NULL AND revision_uuid IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
