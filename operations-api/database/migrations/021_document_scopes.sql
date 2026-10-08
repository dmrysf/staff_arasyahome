-- Source-scoped production document authority (API 2.22.0).
-- Additive only. No order, document, request, QR, role, permission, grant or employee row is created,
-- changed or deleted, and nobody receives a scope.
--
-- Holding a production document permission no longer reaches every order source. A non-root identity
-- now also needs an explicit scope row for the order's source, granted by root:
--   - `operate`: generate, reprint, request a revision and read documents of that source;
--   - `approve`: decide revision requests of that source (primary approver or temporary backup).
-- Without a row the identity sees and decides nothing of that source (default deny). Root keeps
-- break-glass authority over every source. The table holds the current state only; every change is
-- written to iam_audit_events with before/after and increments the identity's authorization_version.
CREATE TABLE IF NOT EXISTS employee_document_scopes (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    capability VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    granted_at DATETIME(6) NOT NULL,
    granted_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (employee_uuid, capability, source_key),
    KEY idx_employee_document_scopes_source (source_key, capability),
    CONSTRAINT fk_employee_document_scopes_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_document_scopes_source FOREIGN KEY (source_key) REFERENCES order_sources (source_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_document_scopes_granted_by FOREIGN KEY (granted_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_employee_document_scopes_capability CHECK (capability IN ('operate', 'approve'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Document group notifications carry the order's source so the live stream delivers them only to
-- approvers and requesters scoped to that source. Earlier rows keep NULL and reach no scoped reader.
ALTER TABLE live_events
    ADD COLUMN scope_source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER recipient_employee_uuid;
