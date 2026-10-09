-- Trendyol marketplace intake (API 2.24.0).
-- Additive only. No order, item, QR, document, role assignment, scope, grant or employee row is created,
-- changed or deleted, and nobody receives a permission.
--
-- Trendyol shipment packages never enter production by themselves. The pull synchronization writes them
-- into a separate intake inbox (trendyol_packages + trendyol_package_lines). Authorized Trendyol personnel
-- review each package in Staff, complete the production measurements, and an explicit approval creates the
-- canonical operational order at the first canonical stage (`waiting`), with its Arasya production QR and
-- production document revision 1, in one transaction. Nothing else creates a Trendyol production order.
--
-- Historical protection: synchronization is off until it is explicitly activated with a baseline instant.
-- Packages ordered before the baseline, or first seen in a status that is not a new order (shipped,
-- delivered, cancelled, returned, ...), are recorded once as ignored and never become intake work.

-- Single activation row. `inactive` until the operator activates intake with a baseline; the baseline
-- never moves backwards. The cursor is the last fully read package modification instant.
CREATE TABLE IF NOT EXISTS trendyol_intake_state (
    state_id TINYINT UNSIGNED NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    baseline_at DATETIME(6) NULL,
    cursor_at DATETIME(6) NULL,
    activated_at DATETIME(6) NULL,
    activated_by VARCHAR(120) NULL,
    changed_at DATETIME(6) NOT NULL,
    changed_by VARCHAR(120) NULL,
    last_run_at DATETIME(6) NULL,
    last_run_outcome VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (state_id),
    CONSTRAINT chk_trendyol_intake_state_singleton CHECK (state_id = 1),
    CONSTRAINT chk_trendyol_intake_state_status CHECK (status IN ('inactive', 'active', 'paused')),
    CONSTRAINT chk_trendyol_intake_state_baseline CHECK (status = 'inactive' OR baseline_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO trendyol_intake_state (state_id, status, changed_at) VALUES (1, 'inactive', UTC_TIMESTAMP(6));

-- One row per Trendyol shipment package that is intake work. Marketplace fields are read-only copies; the
-- delivery context holds the printable identity only (masked phone, no email, no identity number).
CREATE TABLE IF NOT EXISTS trendyol_packages (
    package_id BIGINT UNSIGNED NOT NULL,
    order_number VARCHAR(120) NOT NULL,
    intake_status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    marketplace_status VARCHAR(40) NOT NULL,
    marketplace_modified_ms BIGINT UNSIGNED NOT NULL,
    order_date_ms BIGINT UNSIGNED NOT NULL,
    channel_id INT NULL,
    delivery_context JSON NULL,
    lines_hash BINARY(32) NOT NULL,
    changed_after_release TINYINT(1) NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    released_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    released_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    released_at DATETIME(6) NULL,
    dismissed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    dismissed_at DATETIME(6) NULL,
    dismiss_reason VARCHAR(500) NULL,
    PRIMARY KEY (package_id),
    UNIQUE KEY uq_trendyol_packages_released_order (released_order_uuid),
    KEY idx_trendyol_packages_status (intake_status, order_date_ms),
    KEY idx_trendyol_packages_order_number (order_number),
    CONSTRAINT fk_trendyol_packages_released_order FOREIGN KEY (released_order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_trendyol_packages_released_by FOREIGN KEY (released_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_trendyol_packages_dismissed_by FOREIGN KEY (dismissed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_trendyol_packages_status CHECK (intake_status IN ('pending', 'released', 'dismissed', 'marketplace_cancelled')),
    CONSTRAINT chk_trendyol_packages_released CHECK ((intake_status = 'released') = (released_order_uuid IS NOT NULL AND released_at IS NOT NULL AND released_by_employee_uuid IS NOT NULL)),
    CONSTRAINT chk_trendyol_packages_dismissed CHECK ((intake_status = 'dismissed') = (dismissed_at IS NOT NULL AND dismissed_by_employee_uuid IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marketplace lines (read-only copy) plus the production data an authorized employee prepared for them.
CREATE TABLE IF NOT EXISTS trendyol_package_lines (
    package_id BIGINT UNSIGNED NOT NULL,
    line_id BIGINT UNSIGNED NOT NULL,
    line_number SMALLINT UNSIGNED NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    stock_code VARCHAR(120) NULL,
    barcode VARCHAR(120) NULL,
    product_size VARCHAR(160) NULL,
    product_color VARCHAR(160) NULL,
    quantity INT UNSIGNED NOT NULL,
    line_status VARCHAR(60) NULL,
    prepared_kind VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    prepared_width_cm DECIMAL(12,3) NULL,
    prepared_height_cm DECIMAL(12,3) NULL,
    prepared_meters DECIMAL(12,3) NULL,
    prepared_notes VARCHAR(1000) NULL,
    prepared_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    prepared_at DATETIME(6) NULL,
    PRIMARY KEY (package_id, line_id),
    UNIQUE KEY uq_trendyol_package_lines_number (package_id, line_number),
    CONSTRAINT fk_trendyol_package_lines_package FOREIGN KEY (package_id) REFERENCES trendyol_packages (package_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_trendyol_package_lines_prepared_by FOREIGN KEY (prepared_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_trendyol_package_lines_quantity CHECK (quantity > 0),
    CONSTRAINT chk_trendyol_package_lines_kind CHECK (prepared_kind IS NULL OR prepared_kind IN ('curtain', 'drapery', 'other')),
    CONSTRAINT chk_trendyol_package_lines_prepared CHECK ((prepared_kind IS NULL) = (prepared_at IS NULL) AND (prepared_at IS NULL) = (prepared_by_employee_uuid IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Packages that are not intake work, recorded once and never reclassified (historical protection).
CREATE TABLE IF NOT EXISTS trendyol_ignored_packages (
    package_id BIGINT UNSIGNED NOT NULL,
    order_number VARCHAR(120) NOT NULL,
    reason VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    marketplace_status VARCHAR(40) NOT NULL,
    order_date_ms BIGINT UNSIGNED NULL,
    first_seen_at DATETIME(6) NOT NULL,
    PRIMARY KEY (package_id),
    KEY idx_trendyol_ignored_packages_seen (first_seen_at),
    CONSTRAINT chk_trendyol_ignored_packages_reason CHECK (reason IN ('historical', 'status_not_eligible', 'order_date_missing'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only intake history: what the marketplace changed and what each employee did.
CREATE TABLE IF NOT EXISTS trendyol_intake_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    package_id BIGINT UNSIGNED NULL,
    action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    actor_label VARCHAR(120) NULL,
    details JSON NULL,
    request_id VARCHAR(100) NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_id),
    KEY idx_trendyol_intake_events_package (package_id, event_id),
    CONSTRAINT fk_trendyol_intake_events_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_trendyol_intake_events_action CHECK (action IN (
        'intake_activated', 'intake_paused', 'intake_resumed',
        'received', 'marketplace_updated', 'marketplace_lines_changed', 'marketplace_cancelled', 'changed_after_release',
        'line_prepared', 'dismissed', 'reopened', 'released'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per synchronization run (operator diagnostics; no customer data).
CREATE TABLE IF NOT EXISTS trendyol_sync_runs (
    run_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    window_start DATETIME(6) NOT NULL,
    window_end DATETIME(6) NOT NULL,
    outcome VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pages INT UNSIGNED NOT NULL DEFAULT 0,
    received INT UNSIGNED NOT NULL DEFAULT 0,
    updated INT UNSIGNED NOT NULL DEFAULT 0,
    unchanged INT UNSIGNED NOT NULL DEFAULT 0,
    ignored INT UNSIGNED NOT NULL DEFAULT 0,
    deferred INT UNSIGNED NOT NULL DEFAULT 0,
    rejected INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (run_id),
    KEY idx_trendyol_sync_runs_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Workspace commands replay through the shared per-employee idempotency table (production_exception_idempotency).

-- ---------------------------------------------------------------- permissions and role templates

-- Source-specific permissions: they reach Trendyol intake work only, never another source.
INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('trendyol.orders.view', 'trendyol', 'Vizualizare comenzi Trendyol', 'See incoming Trendyol packages in the Staff Trendyol workspace', 1, UTC_TIMESTAMP(6)),
    ('trendyol.orders.prepare', 'trendyol', 'Pregătire comenzi Trendyol', 'Complete production measurements of incoming Trendyol packages', 1, UTC_TIMESTAMP(6)),
    ('trendyol.orders.release', 'trendyol', 'Aprobare comenzi Trendyol pentru producție', 'Approve a prepared Trendyol package into production (stage 1, QR and document revision 1)', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE permission_key = permission_key;

INSERT IGNORE INTO roles (role_key, name, description, authority_rank, is_template, status, created_at, updated_at) VALUES
    ('trendyol-order-preparer', 'Pregătire comenzi Trendyol', 'Vede comenzile Trendyol primite și completează măsurile de producție', 200, 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
    ('trendyol-order-approver', 'Aprobare comenzi Trendyol', 'Pregătește și aprobă comenzile Trendyol pentru producție', 300, 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.permission_key IN ('trendyol.orders.view', 'trendyol.orders.prepare')
WHERE r.role_key = 'trendyol-order-preparer';

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.permission_key IN ('trendyol.orders.view', 'trendyol.orders.prepare', 'trendyol.orders.release')
WHERE r.role_key = 'trendyol-order-approver';
-- No employee assignment: root assigns these templates through central IAM after review.
