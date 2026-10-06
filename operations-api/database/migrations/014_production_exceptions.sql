-- Production Exceptions & Live Approvals V1 + Organization foundation.
-- Additive only. No existing row, activity event, audit event or permission grant is rewritten.
-- Existing roles keep their permissions; the only new grants go to the new operations-manager template.

-- ---------------------------------------------------------------- organization

-- Business principals designated by root. 'ceo' is the business owner: the only non-root identity
-- that may change working hours and appoint scoped responsibilities. One row per principal.
CREATE TABLE IF NOT EXISTS organization_principals (
    principal_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    assigned_at DATETIME(6) NOT NULL,
    assigned_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (principal_key),
    UNIQUE KEY uq_organization_principals_employee (employee_uuid),
    CONSTRAINT fk_organization_principals_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_organization_principals_assigned_by FOREIGN KEY (assigned_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_organization_principals_key CHECK (principal_key IN ('ceo'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Additional organisational functions of one identity (for example Depozit + Montaj). Membership only:
-- a secondary department never grants a permission, an application or a production stage.
CREATE TABLE IF NOT EXISTS employee_secondary_departments (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (employee_uuid, department_id),
    KEY idx_employee_secondary_departments_department (department_id),
    CONSTRAINT fk_employee_secondary_departments_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_secondary_departments_department FOREIGN KEY (department_id) REFERENCES departments (department_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scoped, optionally temporary responsibilities. A row is never deleted; revocation records who ended
-- it and when. Every change is also written to iam_audit_events with before/after.
CREATE TABLE IF NOT EXISTS responsibility_assignments (
    assignment_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    responsibility_key VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    starts_at DATETIME(6) NOT NULL,
    ends_at DATETIME(6) NULL,
    note VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL,
    created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revoked_at DATETIME(6) NULL,
    revoked_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    revoke_reason VARCHAR(500) NULL,
    PRIMARY KEY (assignment_uuid),
    KEY idx_responsibility_assignments_key_time (responsibility_key, revoked_at, starts_at, ends_at),
    KEY idx_responsibility_assignments_employee (employee_uuid, responsibility_key),
    CONSTRAINT fk_responsibility_assignments_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_responsibility_assignments_created_by FOREIGN KEY (created_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_responsibility_assignments_revoked_by FOREIGN KEY (revoked_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_responsibility_assignments_key CHECK (responsibility_key IN ('tailoring_intake_responsible', 'operations_backup_approver')),
    CONSTRAINT chk_responsibility_assignments_window CHECK (ends_at IS NULL OR ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Business working hours in Europe/Bucharest local time. ISO weekday: 1 = Monday ... 7 = Sunday.
-- Working hours never delete or hide events; they only inform future business-time analysis.
CREATE TABLE IF NOT EXISTS business_hours (
    weekday TINYINT UNSIGNED NOT NULL,
    is_open TINYINT(1) NOT NULL,
    opens_at TIME NULL,
    closes_at TIME NULL,
    updated_at DATETIME(6) NOT NULL,
    updated_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (weekday),
    CONSTRAINT fk_business_hours_updated_by FOREIGN KEY (updated_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_business_hours_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT chk_business_hours_is_open CHECK (is_open IN (0, 1)),
    CONSTRAINT chk_business_hours_window CHECK ((is_open = 0 AND opens_at IS NULL AND closes_at IS NULL) OR (is_open = 1 AND opens_at IS NOT NULL AND closes_at IS NOT NULL AND closes_at > opens_at))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO business_hours (weekday, is_open, opens_at, closes_at, updated_at) VALUES
    (1, 1, '05:00:00', '20:00:00', UTC_TIMESTAMP(6)),
    (2, 1, '05:00:00', '20:00:00', UTC_TIMESTAMP(6)),
    (3, 1, '05:00:00', '20:00:00', UTC_TIMESTAMP(6)),
    (4, 1, '05:00:00', '20:00:00', UTC_TIMESTAMP(6)),
    (5, 1, '05:00:00', '20:00:00', UTC_TIMESTAMP(6)),
    (6, 1, '05:00:00', '20:00:00', UTC_TIMESTAMP(6)),
    (7, 0, NULL, NULL, UTC_TIMESTAMP(6));

-- ---------------------------------------------------------------- root-owned production policy

-- Singleton exception policy. V1 knows only the strict blocking mode; a future mode is a new CHECK
-- value added by a later migration, never a reinterpretation of this one.
CREATE TABLE IF NOT EXISTS production_exception_policy (
    singleton_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    approval_mode VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    updated_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (singleton_id),
    CONSTRAINT fk_production_exception_policy_updated_by FOREIGN KEY (updated_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_exception_policy_singleton CHECK (singleton_id = 1),
    CONSTRAINT chk_production_exception_policy_mode CHECK (approval_mode IN ('blocking'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO production_exception_policy (singleton_id, approval_mode, updated_at) VALUES (1, 'blocking', UTC_TIMESTAMP(6));

-- Root-managed cutting fault reasons. Requests snapshot the label, so later edits never rewrite history.
CREATE TABLE IF NOT EXISTS production_fault_reasons (
    reason_key VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    label VARCHAR(160) NOT NULL,
    requires_comment TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (reason_key),
    CONSTRAINT chk_production_fault_reasons_key CHECK (reason_key REGEXP '^[a-z][a-z0-9-]{1,59}$'),
    CONSTRAINT chk_production_fault_reasons_comment CHECK (requires_comment IN (0, 1)),
    CONSTRAINT chk_production_fault_reasons_status CHECK (status IN ('active', 'inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO production_fault_reasons (reason_key, label, requires_comment, status, sort_order, created_at, updated_at) VALUES
    ('wrong-cut', 'Tăiere greșită', 0, 'active', 10, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
    ('wrong-meterage', 'Metraj greșit', 0, 'active', 20, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
    ('wrong-product', 'Produs / cod greșit', 0, 'active', 30, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
    ('wrong-variant', 'Culoare / variantă greșită', 0, 'active', 40, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
    ('material-defect', 'Defect material neobservat', 0, 'active', 50, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
    ('other', 'Alt motiv', 1, 'active', 90, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));

-- ---------------------------------------------------------------- production exceptions

-- One cutting-fault return request. Attribution (responsible employee and the activity event that
-- proves it) is derived by the server from production history, never taken from the request body.
CREATE TABLE IF NOT EXISTS production_exceptions (
    exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    exception_number BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    exception_type VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_number_snapshot VARCHAR(120) NOT NULL,
    detected_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    return_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    detector_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    responsible_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    responsible_activity_event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    arrival_number INT UNSIGNED NOT NULL,
    reason_key VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason_label_snapshot VARCHAR(160) NOT NULL,
    detector_comment VARCHAR(1000) NULL,
    line_count INT UNSIGNED NOT NULL,
    fault_meters DECIMAL(12,3) NOT NULL,
    production_version_at_report INT UNSIGNED NOT NULL,
    reported_at DATETIME(6) NOT NULL,
    acknowledged_at DATETIME(6) NULL,
    acknowledgment_comment VARCHAR(1000) NULL,
    qr_reference_verified CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
    qr_verified_at DATETIME(6) NULL,
    resolved_at DATETIME(6) NULL,
    rework_cycle INT UNSIGNED NULL,
    cancelled_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    cancel_reason VARCHAR(1000) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (exception_uuid),
    UNIQUE KEY uq_production_exceptions_number (exception_number),
    UNIQUE KEY uq_production_exceptions_rework_cycle (order_uuid, rework_cycle),
    KEY idx_production_exceptions_order (order_uuid, reported_at),
    KEY idx_production_exceptions_status (status, updated_at),
    KEY idx_production_exceptions_responsible (responsible_employee_uuid, status),
    KEY idx_production_exceptions_detector (detector_employee_uuid, status),
    CONSTRAINT fk_production_exceptions_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exceptions_detector FOREIGN KEY (detector_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exceptions_responsible FOREIGN KEY (responsible_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exceptions_activity FOREIGN KEY (responsible_activity_event_id) REFERENCES order_activity_events (event_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exceptions_reason FOREIGN KEY (reason_key) REFERENCES production_fault_reasons (reason_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exceptions_cancelled_by FOREIGN KEY (cancelled_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_exceptions_type CHECK (exception_type IN ('cutting_fault')),
    CONSTRAINT chk_production_exceptions_status CHECK (status IN ('awaiting_acknowledgment', 'awaiting_approval', 'approved', 'rejected', 'cancelled')),
    CONSTRAINT chk_production_exceptions_people CHECK (detector_employee_uuid <> responsible_employee_uuid),
    CONSTRAINT chk_production_exceptions_lines CHECK (line_count > 0),
    CONSTRAINT chk_production_exceptions_meters CHECK (fault_meters > 0),
    CONSTRAINT chk_production_exceptions_version CHECK (version > 0),
    CONSTRAINT chk_production_exceptions_arrival CHECK (arrival_number > 0),
    CONSTRAINT chk_production_exceptions_cycle CHECK (rework_cycle IS NULL OR rework_cycle > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exact affected lines with immutable snapshots. No foreign key to operational_order_items: a later
-- source re-projection may replace item rows, and the fault scope must never change with it.
CREATE TABLE IF NOT EXISTS production_exception_lines (
    exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    line_number INT UNSIGNED NOT NULL,
    name_snapshot VARCHAR(255) NOT NULL,
    product_code_snapshot VARCHAR(120) NULL,
    variant_snapshot VARCHAR(160) NULL,
    color_snapshot VARCHAR(160) NULL,
    quantity_snapshot INT UNSIGNED NOT NULL,
    meters_snapshot DECIMAL(12,3) NOT NULL,
    PRIMARY KEY (exception_uuid, item_uuid),
    CONSTRAINT fk_production_exception_lines_exception FOREIGN KEY (exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_exception_lines_meters CHECK (meters_snapshot > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manager decision attempts. A rejection is never overwritten: a re-review opens attempt N+1 that links
-- to the rejected attempt. The pending interval (opened_at -> decided_at) is the manager waiting time.
CREATE TABLE IF NOT EXISTS production_exception_decisions (
    decision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt_number INT UNSIGNED NOT NULL,
    previous_decision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    opened_reason VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    opened_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    opened_comment VARCHAR(1000) NULL,
    opened_at DATETIME(6) NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decided_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decided_via VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decision_comment VARCHAR(1000) NULL,
    decided_at DATETIME(6) NULL,
    PRIMARY KEY (decision_uuid),
    UNIQUE KEY uq_production_exception_decisions_attempt (exception_uuid, attempt_number),
    UNIQUE KEY uq_production_exception_decisions_previous (previous_decision_uuid),
    KEY idx_production_exception_decisions_status (status, opened_at),
    KEY idx_production_exception_decisions_decider (decided_by_employee_uuid, decided_at),
    CONSTRAINT fk_production_exception_decisions_exception FOREIGN KEY (exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exception_decisions_previous FOREIGN KEY (previous_decision_uuid) REFERENCES production_exception_decisions (decision_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exception_decisions_opened_by FOREIGN KEY (opened_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exception_decisions_decided_by FOREIGN KEY (decided_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_exception_decisions_reason CHECK (opened_reason IN ('acknowledged', 'rereview')),
    CONSTRAINT chk_production_exception_decisions_status CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
    CONSTRAINT chk_production_exception_decisions_via CHECK (decided_via IS NULL OR decided_via IN ('operations_manager', 'backup_approver', 'root')),
    CONSTRAINT chk_production_exception_decisions_attempt CHECK (attempt_number > 0),
    CONSTRAINT chk_production_exception_decisions_decided CHECK ((status = 'pending' AND decided_at IS NULL) OR (status <> 'pending' AND decided_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only timeline of each exception: who did what, when, with which snapshot.
CREATE TABLE IF NOT EXISTS production_exception_events (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    exception_version_after INT UNSIGNED NOT NULL,
    metadata_json JSON NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_uuid),
    UNIQUE KEY uq_production_exception_events_version (exception_uuid, exception_version_after),
    KEY idx_production_exception_events_exception (exception_uuid, occurred_at),
    KEY idx_production_exception_events_actor (actor_employee_uuid, occurred_at),
    CONSTRAINT fk_production_exception_events_exception FOREIGN KEY (exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exception_events_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_exception_events_action CHECK (action IN ('reported', 'acknowledged', 'approval_requested', 'approved', 'rejected', 'rereview_requested', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Objective, immutable quality facts. Never netted into a score; one row per exception and fact type.
CREATE TABLE IF NOT EXISTS production_quality_events (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rework_cycle INT UNSIGNED NOT NULL,
    is_repeat TINYINT(1) NOT NULL,
    line_count INT UNSIGNED NOT NULL,
    meters DECIMAL(12,3) NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_uuid),
    UNIQUE KEY uq_production_quality_events_once (exception_uuid, event_type),
    KEY idx_production_quality_events_employee (employee_uuid, event_type, occurred_at),
    KEY idx_production_quality_events_order (order_uuid, occurred_at),
    CONSTRAINT fk_production_quality_events_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_quality_events_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_quality_events_exception FOREIGN KEY (exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_quality_events_type CHECK (event_type IN ('cutting_fault', 'fault_detected')),
    CONSTRAINT chk_production_quality_events_repeat CHECK (is_repeat IN (0, 1)),
    CONSTRAINT chk_production_quality_events_meters CHECK (meters > 0 AND line_count > 0 AND rework_cycle > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_exception_idempotency (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash BINARY(32) NOT NULL,
    response_json MEDIUMTEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (employee_uuid, idempotency_key),
    KEY idx_production_exception_idempotency_retention (created_at),
    CONSTRAINT fk_production_exception_idempotency_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Live notification outbox read by the authenticated event stream. Payloads carry identifiers and
-- states only; readers re-fetch details through authorized endpoints. Not an audit table: retention
-- may prune it.
CREATE TABLE IF NOT EXISTS live_events (
    event_seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    audience VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recipient_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    event_type VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_seq),
    KEY idx_live_events_recipient (recipient_employee_uuid, event_seq),
    KEY idx_live_events_audience (audience, event_seq),
    KEY idx_live_events_retention (created_at),
    CONSTRAINT fk_live_events_recipient FOREIGN KEY (recipient_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_live_events_audience CHECK ((audience = 'employee' AND recipient_employee_uuid IS NOT NULL) OR (audience = 'approvers' AND recipient_employee_uuid IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The open exception that blocks an order. Set and cleared only under the order row lock.
ALTER TABLE operational_orders
    ADD COLUMN open_exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER production_version,
    ADD UNIQUE KEY uq_operational_orders_open_exception (open_exception_uuid),
    ADD CONSTRAINT fk_operational_orders_open_exception FOREIGN KEY (open_exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT;

-- New immutable production activity actions; every action allowed by 007 and 012 stays allowed. Existing rows are untouched.
ALTER TABLE order_activity_events DROP CONSTRAINT chk_order_activity_action;

ALTER TABLE order_activity_events
    ADD CONSTRAINT chk_order_activity_action CHECK (action IN ('claimed', 'stage_completed', 'production_completed', 'owner_released', 'owner_reassigned', 'production_submitted',
        'fault_reported', 'fault_rejected', 'fault_rereview_requested', 'fault_returned', 'fault_cancelled'));

-- ---------------------------------------------------------------- permissions and the narrow role

-- Staff baselines (never role-grantable; they come with Staff access and are gated by stage/ownership).
INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('orders.report_fault', 'staff', 'Raportare eroare de tăiere', 'Return an accepted order to cutting with the exact faulty lines', 0, UTC_TIMESTAMP(6)),
    ('orders.acknowledge_fault', 'staff', 'Confirmare eroare proprie', 'Acknowledge a cutting fault attributed to the employee', 0, UTC_TIMESTAMP(6)),
    ('production.exceptions.approve', 'production', 'Aprobare excepții producție', 'Approve or reject production exception requests', 1, UTC_TIMESTAMP(6)),
    ('orders.lookup_exact', 'orders', 'Căutare exactă comandă', 'Find one order by its exact number (no listing)', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE category = VALUES(category), label = VALUES(label), description = VALUES(description), role_grantable = VALUES(role_grantable);

-- One shared role for every operations manager. It carries no IAM, analytics or listing permission.
INSERT IGNORE INTO roles (role_key, name, description, authority_rank, is_template, status, created_at, updated_at) VALUES
    ('operations-manager', 'Manager operațional', 'Aprobări de producție și căutare exactă a comenzilor', 400, 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.permission_key IN ('production.exceptions.approve', 'orders.lookup_exact')
WHERE r.role_key = 'operations-manager';
