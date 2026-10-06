-- Canonical production ticket + QR revision engine V1.
-- Additive only. No existing order, QR, activity, audit or permission row is rewritten or deleted.
-- One canonical operational order keeps its identity; document revisions are an additional dimension.

-- ---------------------------------------------------------------- printable delivery identity

-- Normalized delivery identity printed on the workshop document: recipient, delivery address lines and
-- an already masked phone. A raw phone number or an email address is never stored here.
ALTER TABLE operational_orders
    ADD COLUMN document_context JSON NULL,
    ADD COLUMN document_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',
    ADD COLUMN active_document_revision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN document_version INT UNSIGNED NOT NULL DEFAULT 0,
    ADD CONSTRAINT chk_operational_orders_document_status CHECK (document_status IN ('none', 'active', 'stale', 'revoked')),
    ADD KEY idx_operational_orders_document (document_status, updated_at, order_uuid);

-- ---------------------------------------------------------------- revisions

-- One row per generated production document revision. At most one active revision per order is
-- guaranteed by uq_production_document_revisions_active (active_order_uuid is set only while active).
-- Every revision binds a distinct canonical QR reference; revision 1 binds the reference the order
-- received at intake, every later true revision a new one. The snapshot is the exact printable
-- content (workshop data only, never prices, payment or email) and is never rewritten.
CREATE TABLE IF NOT EXISTS production_document_revisions (
    revision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    qr_reference CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_schema SMALLINT UNSIGNED NOT NULL,
    fingerprint BINARY(32) NOT NULL,
    snapshot_json JSON NOT NULL,
    request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    generated_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    generated_at DATETIME(6) NOT NULL,
    stale_at DATETIME(6) NULL,
    superseded_at DATETIME(6) NULL,
    superseded_by_revision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    revoked_at DATETIME(6) NULL,
    revoked_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    revoke_reason VARCHAR(1000) NULL,
    PRIMARY KEY (revision_uuid),
    UNIQUE KEY uq_production_document_revisions_number (order_uuid, revision_number),
    UNIQUE KEY uq_production_document_revisions_active (active_order_uuid),
    UNIQUE KEY uq_production_document_revisions_qr (qr_reference),
    KEY idx_production_document_revisions_generated (generated_at, order_uuid),
    CONSTRAINT fk_production_document_revisions_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_revisions_qr FOREIGN KEY (qr_reference) REFERENCES order_qr_references (qr_reference) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_revisions_generated_by FOREIGN KEY (generated_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_revisions_revoked_by FOREIGN KEY (revoked_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_document_revisions_status CHECK (status IN ('active', 'superseded', 'revoked')),
    CONSTRAINT chk_production_document_revisions_number CHECK (revision_number > 0),
    CONSTRAINT chk_production_document_revisions_active CHECK
        ((status = 'active' AND active_order_uuid IS NOT NULL AND active_order_uuid = order_uuid)
         OR (status <> 'active' AND active_order_uuid IS NULL)),
    CONSTRAINT chk_production_document_revisions_revoked CHECK
        (status <> 'revoked' OR (revoked_at IS NOT NULL AND revoke_reason IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE operational_orders
    ADD CONSTRAINT fk_operational_orders_active_document FOREIGN KEY (active_document_revision_uuid) REFERENCES production_document_revisions (revision_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT;

-- ---------------------------------------------------------------- revision requests

-- A request asks the revision approver to authorize generating the next revision for exactly one
-- reviewed snapshot (fingerprint). A decision is written once; a re-request is a new row linked by
-- previous_request_uuid. open_order_uuid is set while pending or approved: one open request per order.
CREATE TABLE IF NOT EXISTS production_document_revision_requests (
    request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    base_revision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_revision_number INT UNSIGNED NOT NULL,
    previous_request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    open_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    snapshot_schema SMALLINT UNSIGNED NOT NULL,
    fingerprint BINARY(32) NOT NULL,
    snapshot_json JSON NOT NULL,
    diff_json JSON NOT NULL,
    production_started TINYINT(1) NOT NULL,
    stage_id_snapshot VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_employee_uuid_snapshot CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    requested_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_comment VARCHAR(1000) NULL,
    requested_at DATETIME(6) NOT NULL,
    decided_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decided_via VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decision_comment VARCHAR(1000) NULL,
    decided_at DATETIME(6) NULL,
    resolved_at DATETIME(6) NULL,
    resolved_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    resolution_note VARCHAR(1000) NULL,
    generated_revision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (request_uuid),
    UNIQUE KEY uq_production_document_requests_open (open_order_uuid),
    KEY idx_production_document_requests_queue (status, requested_at, request_uuid),
    KEY idx_production_document_requests_order (order_uuid, requested_at),
    KEY idx_production_document_requests_requester (requested_by_employee_uuid, status, requested_at),
    KEY idx_production_document_requests_decided (decided_at, decided_by_employee_uuid),
    CONSTRAINT fk_production_document_requests_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_requests_base FOREIGN KEY (base_revision_uuid) REFERENCES production_document_revisions (revision_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_requests_previous FOREIGN KEY (previous_request_uuid) REFERENCES production_document_revision_requests (request_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_requests_requested_by FOREIGN KEY (requested_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_requests_decided_by FOREIGN KEY (decided_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_requests_resolved_by FOREIGN KEY (resolved_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_requests_generated FOREIGN KEY (generated_revision_uuid) REFERENCES production_document_revisions (revision_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_document_requests_status CHECK (status IN ('pending', 'approved', 'rejected', 'superseded', 'generated', 'cancelled')),
    CONSTRAINT chk_production_document_requests_open CHECK
        ((status IN ('pending', 'approved') AND open_order_uuid IS NOT NULL AND open_order_uuid = order_uuid)
         OR (status NOT IN ('pending', 'approved') AND open_order_uuid IS NULL)),
    CONSTRAINT chk_production_document_requests_decision CHECK
        ((status = 'pending' AND decided_at IS NULL) OR status <> 'pending'),
    CONSTRAINT chk_production_document_requests_rejection CHECK (status <> 'rejected' OR decision_comment IS NOT NULL),
    CONSTRAINT chk_production_document_requests_via CHECK (decided_via IS NULL OR decided_via IN ('revision_approver', 'backup_approver', 'root')),
    CONSTRAINT chk_production_document_requests_started CHECK (production_started IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE production_document_revisions
    ADD CONSTRAINT fk_production_document_revisions_request FOREIGN KEY (request_uuid) REFERENCES production_document_revision_requests (request_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT;

-- ---------------------------------------------------------------- prints and history

-- Every physical print of a revision. A reprint keeps the revision and its QR; it is never a revision.
CREATE TABLE IF NOT EXISTS production_document_prints (
    print_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    print_number INT UNSIGNED NOT NULL,
    print_kind VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(500) NULL,
    printed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    printed_at DATETIME(6) NOT NULL,
    PRIMARY KEY (print_uuid),
    UNIQUE KEY uq_production_document_prints_number (revision_uuid, print_number),
    KEY idx_production_document_prints_order (order_uuid, printed_at),
    CONSTRAINT fk_production_document_prints_revision FOREIGN KEY (revision_uuid) REFERENCES production_document_revisions (revision_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_prints_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_prints_employee FOREIGN KEY (printed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_document_prints_kind CHECK (print_kind IN ('print', 'reprint')),
    CONSTRAINT chk_production_document_prints_number CHECK (print_number > 0 AND ((print_number = 1 AND print_kind = 'print') OR (print_number > 1 AND print_kind = 'reprint')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only document history of an order. Never updated, never deleted.
CREATE TABLE IF NOT EXISTS production_document_events (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision_number INT UNSIGNED NULL,
    revision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    metadata_json JSON NULL,
    request_id VARCHAR(100) NULL,
    occurred_at DATETIME(6) NOT NULL,
    event_seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (event_uuid),
    UNIQUE KEY uq_production_document_events_seq (event_seq),
    KEY idx_production_document_events_order (order_uuid, event_seq),
    CONSTRAINT fk_production_document_events_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_events_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_document_events_type CHECK (event_type IN (
        'generated', 'content_changed', 'revision_requested', 'revision_approved', 'revision_rejected',
        'request_superseded', 'request_cancelled', 'revision_generated', 'revision_superseded', 'revoked', 'printed', 'reprinted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Objective blocked intervals for analytics: from the moment the active document became unusable
-- (stale content or root revoke) until a new revision was activated. Never an employee fault.
-- processed_meters records whole-line meters already cut under the obsolete document, only when the
-- cutting stage was completed before the change (customer/source revision, not a worker error).
CREATE TABLE IF NOT EXISTS production_document_blocks (
    block_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cause VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stage_id_snapshot VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_employee_uuid_snapshot CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    production_started TINYINT(1) NOT NULL,
    processed_meters DECIMAL(15,3) NULL,
    processed_lines INT UNSIGNED NULL,
    started_at DATETIME(6) NOT NULL,
    ended_at DATETIME(6) NULL,
    ended_by_revision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    open_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (block_uuid),
    UNIQUE KEY uq_production_document_blocks_open (open_order_uuid),
    KEY idx_production_document_blocks_order (order_uuid, started_at),
    KEY idx_production_document_blocks_period (started_at, order_uuid),
    KEY idx_production_document_blocks_end (ended_at, order_uuid),
    CONSTRAINT fk_production_document_blocks_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_blocks_revision FOREIGN KEY (revision_uuid) REFERENCES production_document_revisions (revision_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_document_blocks_ended_by FOREIGN KEY (ended_by_revision_uuid) REFERENCES production_document_revisions (revision_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_document_blocks_cause CHECK (cause IN ('content_changed', 'root_revoked')),
    CONSTRAINT chk_production_document_blocks_started CHECK (production_started IN (0, 1)),
    CONSTRAINT chk_production_document_blocks_open CHECK
        ((ended_at IS NULL AND open_order_uuid IS NOT NULL AND open_order_uuid = order_uuid)
         OR (ended_at IS NOT NULL AND open_order_uuid IS NULL AND ended_at >= started_at))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- scoped responsibility and live audiences

-- New scoped responsibility: temporary document revision backup approver (appointed by the CEO or root).
ALTER TABLE responsibility_assignments DROP CONSTRAINT chk_responsibility_assignments_key;
ALTER TABLE responsibility_assignments ADD CONSTRAINT chk_responsibility_assignments_key
    CHECK (responsibility_key IN ('tailoring_intake_responsible', 'operations_backup_approver', 'document_revision_backup_approver'));

ALTER TABLE live_events DROP CONSTRAINT chk_live_events_audience;
ALTER TABLE live_events ADD CONSTRAINT chk_live_events_audience CHECK
  ((audience = 'employee' AND recipient_employee_uuid IS NOT NULL) OR
   (audience IN ('approvers', 'cutting', 'display', 'document_approvers', 'document_requesters') AND recipient_employee_uuid IS NULL));

-- ---------------------------------------------------------------- permissions and role templates

INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('production.documents.generate', 'production_documents', 'Generare document de producție', 'Generate the first production document and an approved revision', 1, UTC_TIMESTAMP(6)),
    ('production.documents.reprint', 'production_documents', 'Retipărire document de producție', 'Reprint the active revision with the same QR', 1, UTC_TIMESTAMP(6)),
    ('production.documents.request_revision', 'production_documents', 'Cerere revizie document', 'Request approval for a new production document revision', 1, UTC_TIMESTAMP(6)),
    ('production.documents.approve_revision', 'production_documents', 'Aprobare revizii documente', 'Approve or reject production document revisions', 1, UTC_TIMESTAMP(6)),
    ('production.documents.view_history', 'production_documents', 'Istoric documente de producție', 'View production document revision history', 1, UTC_TIMESTAMP(6));

-- Channel / order employees who print workshop documents and request revisions. No approval.
INSERT IGNORE INTO roles (role_key, name, description, authority_rank, is_template, status, created_at, updated_at) VALUES
    ('production-documents-operator', 'Documente de producție', 'Generare, tipărire și cerere de revizie pentru documentele de producție', 200, 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
    ('document-revision-approver', 'Aprobare revizii documente', 'Aprobarea reviziilor documentelor de producție și a noilor coduri QR', 450, 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.permission_key IN ('production.documents.generate', 'production.documents.reprint', 'production.documents.request_revision', 'production.documents.view_history')
WHERE r.role_key = 'production-documents-operator';

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.permission_key IN ('production.documents.approve_revision', 'production.documents.view_history', 'orders.lookup_exact')
WHERE r.role_key = 'document-revision-approver';
-- No employee assignment: root assigns these templates through central IAM after review.
