-- Cutting transfer intents and read-only wall devices. Current production owner remains canonical.
-- Rename only baseline presentation labels; stage IDs, ordinal and historical snapshots never change.
UPDATE production_stages SET display_name = 'Tăiere', updated_at = UTC_TIMESTAMP(6)
 WHERE stage_id = 'material-preparation' AND display_name = 'Pregătire material';
UPDATE production_stages SET display_name = 'Primire Croitorie', updated_at = UTC_TIMESTAMP(6)
 WHERE stage_id = 'workshop-receiving' AND display_name = 'Primire atelier';

CREATE TABLE cutting_transfers (
    transfer_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    from_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    to_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    reason_key VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason_label VARCHAR(100) NOT NULL,
    request_comment VARCHAR(1000) NULL,
    requested_at DATETIME(6) NOT NULL,
    decided_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decided_via VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decision_comment VARCHAR(1000) NULL,
    decided_at DATETIME(6) NULL,
    accepted_at DATETIME(6) NULL,
    qr_verified_at DATETIME(6) NULL,
    resolved_at DATETIME(6) NULL,
    open_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    UNIQUE KEY uq_cutting_transfer_open (open_order_uuid),
    KEY idx_cutting_transfer_queue (status, requested_at),
    KEY idx_cutting_transfer_target (to_employee_uuid, status, requested_at),
    KEY idx_cutting_transfer_history (order_uuid, requested_at),
    CONSTRAINT fk_cutting_transfer_order FOREIGN KEY (order_uuid) REFERENCES operational_orders(order_uuid),
    CONSTRAINT fk_cutting_transfer_from FOREIGN KEY (from_employee_uuid) REFERENCES employees(employee_uuid),
    CONSTRAINT fk_cutting_transfer_to FOREIGN KEY (to_employee_uuid) REFERENCES employees(employee_uuid),
    CONSTRAINT fk_cutting_transfer_decider FOREIGN KEY (decided_by_employee_uuid) REFERENCES employees(employee_uuid),
    CONSTRAINT chk_cutting_transfer_status CHECK (status IN ('pending','approved','accepted','completed','rejected','cancelled')),
    CONSTRAINT chk_cutting_transfer_reason CHECK (reason_key IN ('illness','unavailable','other')),
    CONSTRAINT chk_cutting_transfer_not_self CHECK (from_employee_uuid <> to_employee_uuid),
    CONSTRAINT chk_cutting_transfer_open CHECK
      ((status IN ('pending','approved','accepted') AND open_order_uuid IS NOT NULL AND open_order_uuid = order_uuid)
       OR (status IN ('completed','rejected','cancelled') AND open_order_uuid IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Facts are append-only observations, not mutable ownership or an employee score.
CREATE TABLE cutting_facts (
    fact_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    production_version INT UNSIGNED NOT NULL,
    fact_type VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    occurred_at DATETIME(6) NOT NULL,
    meters DECIMAL(15,3) NULL,
    item_count_snapshot INT UNSIGNED NULL,
    active_seconds_snapshot INT UNSIGNED NULL,
    UNIQUE KEY uq_cutting_fact (order_uuid, production_version, fact_type),
    KEY idx_cutting_fact_today (fact_type, occurred_at),
    CONSTRAINT fk_cutting_fact_order FOREIGN KEY (order_uuid) REFERENCES operational_orders(order_uuid),
    CONSTRAINT fk_cutting_fact_employee FOREIGN KEY (employee_uuid) REFERENCES employees(employee_uuid),
    CONSTRAINT chk_cutting_fact_type CHECK (fact_type IN ('pool_entered','first_claim','interval_started','interval_ended','completed','source_cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cutting_display_devices (
    device_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    pairing_hash BINARY(32) NULL,
    pairing_expires_at DATETIME(6) NULL,
    session_hash BINARY(32) NULL,
    session_expires_at DATETIME(6) NULL,
    paired_at DATETIME(6) NULL,
    last_seen_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_cutting_device_pair (pairing_hash),
    UNIQUE KEY uq_cutting_device_session (session_hash),
    CONSTRAINT fk_cutting_device_creator FOREIGN KEY (created_by_employee_uuid) REFERENCES employees(employee_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cutting_board_settings (
    singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    first_minutes INT UNSIGNED NOT NULL DEFAULT 15,
    second_minutes INT UNSIGNED NOT NULL DEFAULT 30,
    third_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT chk_cutting_settings_singleton CHECK (singleton_id = 1),
    CONSTRAINT chk_cutting_settings_times CHECK (first_minutes > 0 AND first_minutes < second_minutes AND second_minutes < third_minutes AND third_minutes <= 1440)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO cutting_board_settings (singleton_id, updated_at) VALUES (1, UTC_TIMESTAMP(6));

ALTER TABLE operational_orders ADD COLUMN cutting_first_claimed_at DATETIME(6) NULL,
    ADD COLUMN source_reported_unavailable_at DATETIME(6) NULL,
    ADD KEY idx_cutting_pool (production_stage_id, operational_status, production_completed_at, production_owner_employee_uuid, production_changed_at);

-- Daily board aggregates use an indexed time range, not a scan of all historical activity.
ALTER TABLE order_activity_events ADD KEY idx_cutting_completed_today (action, from_stage_id, to_stage_id, occurred_at);

-- Backfill objective historical first claims, never invent an actor or change current ownership.
UPDATE operational_orders o JOIN
  (SELECT order_uuid, MIN(occurred_at) AS first_at FROM order_activity_events
   WHERE from_stage_id = 'material-preparation' AND action IN ('claimed','owner_reassigned','stage_completed') GROUP BY order_uuid) h
  ON h.order_uuid = o.order_uuid SET o.cutting_first_claimed_at = h.first_at;
UPDATE operational_orders SET cutting_first_claimed_at = production_claimed_at
 WHERE cutting_first_claimed_at IS NULL AND production_stage_id = 'material-preparation' AND production_owner_employee_uuid IS NOT NULL;

ALTER TABLE live_events DROP CONSTRAINT chk_live_events_audience;
ALTER TABLE live_events ADD CONSTRAINT chk_live_events_audience CHECK
  ((audience = 'employee' AND recipient_employee_uuid IS NOT NULL) OR
   (audience IN ('approvers','cutting','display') AND recipient_employee_uuid IS NULL));
