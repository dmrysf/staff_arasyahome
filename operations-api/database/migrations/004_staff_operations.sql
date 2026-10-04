ALTER TABLE operational_orders
    ADD COLUMN order_lookup_code VARCHAR(120) COLLATE utf8mb4_bin NULL AFTER order_number,
    ADD COLUMN production_authority VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'source' AFTER production_stage_id,
    ADD COLUMN production_owner_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER production_authority,
    ADD COLUMN production_claimed_at DATETIME(6) NULL AFTER production_owner_employee_uuid,
    ADD COLUMN production_changed_at DATETIME(6) NULL AFTER production_claimed_at,
    ADD COLUMN production_completed_at DATETIME(6) NULL AFTER production_changed_at,
    ADD COLUMN production_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER production_completed_at,
    ADD KEY idx_operational_orders_lookup (order_lookup_code),
    ADD KEY idx_operational_orders_owner (production_owner_employee_uuid),
    ADD KEY idx_operational_orders_stage_open (production_stage_id, production_completed_at, production_owner_employee_uuid),
    ADD CONSTRAINT fk_operational_orders_owner FOREIGN KEY (production_owner_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT chk_operational_orders_authority CHECK (production_authority IN ('source', 'operations')),
    ADD CONSTRAINT chk_operational_orders_production_version CHECK (production_version > 0);

UPDATE operational_orders
SET order_lookup_code = UPPER(TRIM(LEADING '#' FROM TRIM(order_number)))
WHERE order_lookup_code IS NULL;

ALTER TABLE order_sources
    ADD COLUMN last_contact_at DATETIME(6) NULL AFTER status,
    ADD COLUMN last_event_at DATETIME(6) NULL AFTER last_contact_at,
    ADD COLUMN sync_cursor_at DATETIME(6) NULL AFTER last_event_at;

CREATE TABLE IF NOT EXISTS order_qr_references (
    qr_reference CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (qr_reference),
    KEY idx_order_qr_references_order (order_uuid, status),
    CONSTRAINT fk_order_qr_references_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_qr_references_status CHECK (status IN ('active', 'revoked'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_activity_events (
    event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_number_snapshot VARCHAR(120) NOT NULL,
    action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    workflow_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    workflow_version INT UNSIGNED NOT NULL,
    from_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    from_stage_label_snapshot VARCHAR(160) NOT NULL,
    to_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    to_stage_label_snapshot VARCHAR(160) NULL,
    production_version_before INT UNSIGNED NOT NULL,
    production_version_after INT UNSIGNED NOT NULL,
    meters_snapshot DECIMAL(12,3) NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_id),
    UNIQUE KEY uq_order_activity_idempotency (employee_uuid, idempotency_key),
    KEY idx_order_activity_employee_time (employee_uuid, occurred_at, event_id),
    KEY idx_order_activity_order_time (order_uuid, occurred_at),
    KEY idx_order_activity_retention (occurred_at),
    CONSTRAINT fk_order_activity_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_activity_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_activity_action CHECK (action IN ('claimed', 'stage_completed', 'production_completed')),
    CONSTRAINT chk_order_activity_versions CHECK (production_version_after > production_version_before)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_operation_idempotency (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash BINARY(32) NOT NULL,
    response_json MEDIUMTEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (employee_uuid, idempotency_key),
    KEY idx_order_operation_idempotency_retention (created_at),
    CONSTRAINT fk_order_operation_idempotency_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_operation_idempotency_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_operation_idempotency_operation CHECK (operation IN ('claim', 'transition'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_rate_limit_buckets (
    bucket_scope VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_hash BINARY(32) NOT NULL,
    window_started_at DATETIME(6) NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (bucket_scope, subject_hash),
    KEY idx_api_rate_limit_retention (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
