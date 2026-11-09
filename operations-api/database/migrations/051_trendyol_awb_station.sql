-- Migration 051 (API 2.65.0): the Trendyol AWB print station.
--
-- Additive only. No order, item, QR, document, production stage, workflow, employee, role assignment, stage grant or
-- scope row is created, changed or deleted, and nobody receives a permission.
--
-- The station queue is derived on every request from operational_orders (source_key = 'trendyol' and
-- production_stage_id = 'packing', canonical stage 13). Nothing here enqueues an order or moves a stage. The official
-- shipping label always comes from Trendyol (common label service) at the moment an employee asks for it; no label,
-- address, name, telephone or credential is stored. This table keeps only the minimal print history:
--
--   label_retrieved   the server fetched and validated the official label and streamed it to the browser
--   label_failed      the retrieval was refused or failed (safe error category only)
--   print_requested   the browser opened its print flow for a retrieved label (initial or reprint)
--
-- A browser cannot prove that a printer produced the label, so no row ever means "physically printed".
-- 050 is reserved by the B2B order types (draft pull request #123); 051 does not depend on it.

CREATE TABLE IF NOT EXISTS trendyol_awb_print_events (
    event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    package_id BIGINT UNSIGNED NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    print_kind VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    outcome VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    parcel_number SMALLINT UNSIGNED NULL,
    parcel_count SMALLINT UNSIGNED NULL,
    retrieval_event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    client_attempt_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    cargo_provider VARCHAR(60) NULL,
    tracking_suffix VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_id),
    UNIQUE KEY uq_trendyol_awb_print_attempt (employee_uuid, client_attempt_id),
    KEY idx_trendyol_awb_print_order (order_uuid, event_type, created_at),
    KEY idx_trendyol_awb_print_created (created_at),
    CONSTRAINT fk_trendyol_awb_print_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_trendyol_awb_print_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_trendyol_awb_print_retrieval FOREIGN KEY (retrieval_event_id) REFERENCES trendyol_awb_print_events (event_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_trendyol_awb_print_type CHECK (event_type IN ('label_retrieved', 'label_failed', 'print_requested')),
    CONSTRAINT chk_trendyol_awb_print_kind CHECK ((event_type = 'print_requested') = (print_kind IS NOT NULL) AND (print_kind IS NULL OR print_kind IN ('initial', 'reprint'))),
    CONSTRAINT chk_trendyol_awb_print_outcome CHECK ((event_type = 'label_failed') = (outcome <> 'ok')),
    CONSTRAINT chk_trendyol_awb_print_attempt CHECK ((event_type = 'print_requested') = (client_attempt_id IS NOT NULL AND retrieval_event_id IS NOT NULL)),
    CONSTRAINT chk_trendyol_awb_print_parcel CHECK (parcel_number IS NULL OR (parcel_number >= 1 AND parcel_count IS NOT NULL AND parcel_number <= parcel_count))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- permissions and role template

-- Staff-only, Trendyol-only printing permissions. They never reach another source, a stage, an order status, Finance,
-- B2B, products, employees or roles.
INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('trendyol.awb.view', 'trendyol', 'Stație AWB Trendyol: vizualizare', 'See Trendyol orders in packing (stage 13) and their print history in the Staff AWB station', 1, UTC_TIMESTAMP(6)),
    ('trendyol.awb.print', 'trendyol', 'Stație AWB Trendyol: imprimare și reimprimare', 'Retrieve and print the official Trendyol shipping label of a Trendyol order, as often as needed', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE permission_key = permission_key;

-- The functional station identity (a shared computer, not a person) receives this template and nothing else. It also
-- fits a named employee who prints labels. No employee is assigned here.
INSERT IGNORE INTO roles (role_key, name, description, authority_rank, is_template, status, created_at, updated_at) VALUES
    ('trendyol-awb-station', 'Stație AWB Trendyol', 'Vede comenzile Trendyol din împachetare și imprimă eticheta oficială Trendyol', 100, 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.permission_key IN ('trendyol.awb.view', 'trendyol.awb.print')
WHERE r.role_key = 'trendyol-awb-station';
-- No employee assignment: root assigns the template through central IAM after review.
