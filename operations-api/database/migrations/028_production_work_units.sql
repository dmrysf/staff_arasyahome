-- Per-piece production rework (API 2.31.0, Staff 2.26.0, Dashboard 0.14.0).
--
-- Additive only. Every statement is CREATE TABLE IF NOT EXISTS or INSERT IGNORE, so the migration can be re-run after
-- an interruption and never locks, rewrites or deletes an existing row. No existing table is altered: the order row
-- (operational_orders) keeps carrying the stage, owner and version of the pieces that travel together ("main track"),
-- exactly as before, and every existing client keeps reading it unchanged.
--
-- Model
--   production_work_units      one row per physical piece (item line x quantity). Identity is (item_uuid, unit_index);
--                              unit_uuid is derived from it deterministically by the API, so a piece keeps the same
--                              identity whether it was materialized by the backfill or on first use. track_uuid NULL
--                              means the piece travels with the order (main track).
--   production_tracks          a separate production carrier for pieces that left the main track because only they
--                              were reported defective. A track has its own stage, owner, version and rework cycle and
--                              moves through the canonical workflow with ordinary claims and stage completions. It
--                              rejoins the order only at packing, so an order is never packed with a piece in rework.
--   production_track_events    append-only history of every track action (claims, stage completions, defect custody,
--                              approved return, merge), one row per action and version, with the rework cycle.
--   production_exception_scopes / production_exception_units
--                              the exact pieces of a defect report (for reports made by Staff 2.26.0+) and the carrier
--                              the report acts on (the order, or one track). Older reports have no scope row: they keep
--                              their recorded whole-line meaning.
--   production_unit_labels     printable piece label (QR payload ARASYA:U1:<26 base32>), one active label per piece.
--   production_feature_flags   controlled activation: partial (per-piece) reports stay refused until the flag is
--                              enabled after API, Staff and Dashboard are all deployed.
--
-- Rollback: the API 2.30.x code ignores every table below. Disabling the flag stops new partial reports; existing
-- tracks must be finished (or recovered) before an older API is activated, see docs/per-piece-rework.md.

CREATE TABLE IF NOT EXISTS production_feature_flags (
    flag_key VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    changed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    change_reason VARCHAR(255) NULL,
    changed_at DATETIME(6) NOT NULL,
    PRIMARY KEY (flag_key),
    CONSTRAINT fk_production_feature_flags_actor FOREIGN KEY (changed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_feature_flags_enabled CHECK (enabled IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO production_feature_flags (flag_key, enabled, changed_by_employee_uuid, change_reason, changed_at)
VALUES ('partial_rework', 0, NULL, 'Created disabled by migration 028', UTC_TIMESTAMP(6));

CREATE TABLE IF NOT EXISTS production_tracks (
    track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    track_number BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    parent_track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    origin_exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claimed_at DATETIME(6) NULL,
    changed_at DATETIME(6) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    rework_cycle INT UNSIGNED NOT NULL DEFAULT 1,
    open_exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    verify_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    verify_exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    resolution_confirmed_at DATETIME(6) NULL,
    resolution_confirmed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    merged_at DATETIME(6) NULL,
    merged_reason VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (track_uuid),
    UNIQUE KEY uq_production_tracks_number (track_number),
    UNIQUE KEY uq_production_tracks_open_exception (open_exception_uuid),
    KEY idx_production_tracks_order (order_uuid, status),
    KEY idx_production_tracks_queue (status, stage_id, owner_employee_uuid),
    KEY idx_production_tracks_owner (owner_employee_uuid, status),
    CONSTRAINT fk_production_tracks_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_tracks_parent FOREIGN KEY (parent_track_uuid) REFERENCES production_tracks (track_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_tracks_origin FOREIGN KEY (origin_exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_tracks_open_exception FOREIGN KEY (open_exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_tracks_verify_exception FOREIGN KEY (verify_exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_tracks_stage FOREIGN KEY (stage_id) REFERENCES production_stages (stage_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_tracks_owner FOREIGN KEY (owner_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_tracks_resolver FOREIGN KEY (resolution_confirmed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_tracks_status CHECK (status IN ('active', 'merged')),
    CONSTRAINT chk_production_tracks_version CHECK (version > 0),
    CONSTRAINT chk_production_tracks_cycle CHECK (rework_cycle > 0),
    CONSTRAINT chk_production_tracks_merged CHECK ((status = 'merged') = (merged_at IS NOT NULL)),
    CONSTRAINT chk_production_tracks_merged_clean CHECK (status = 'active' OR (owner_employee_uuid IS NULL AND open_exception_uuid IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_work_units (
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- No foreign key: the source projection rewrites item rows (same item_uuid per source line). The pair
    -- (item_uuid, unit_index) is the stable identity of the physical piece.
    item_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unit_index INT UNSIGNED NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    rework_cycle INT UNSIGNED NOT NULL DEFAULT 1,
    identity_source VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    review_required TINYINT(1) NOT NULL DEFAULT 0,
    review_note VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    retired_at DATETIME(6) NULL,
    PRIMARY KEY (unit_uuid),
    UNIQUE KEY uq_production_work_units_identity (item_uuid, unit_index),
    KEY idx_production_work_units_order (order_uuid, status),
    KEY idx_production_work_units_track (track_uuid),
    CONSTRAINT fk_production_work_units_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_work_units_track FOREIGN KEY (track_uuid) REFERENCES production_tracks (track_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_work_units_index CHECK (unit_index > 0),
    CONSTRAINT chk_production_work_units_status CHECK (status IN ('active', 'retired')),
    CONSTRAINT chk_production_work_units_cycle CHECK (rework_cycle > 0),
    CONSTRAINT chk_production_work_units_source CHECK (identity_source IN ('backfill', 'on_demand')),
    CONSTRAINT chk_production_work_units_retired CHECK ((status = 'retired') = (retired_at IS NOT NULL)),
    CONSTRAINT chk_production_work_units_review CHECK (review_required IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_exception_scopes (
    exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- The carrier the report acts on: NULL is the order (main track), otherwise one track.
    track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    -- The track the report was made on, when the report split pieces off a track (NULL: made on the order).
    reported_on_track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    affected_quantity INT UNSIGNED NOT NULL,
    carrier_quantity_at_report INT UNSIGNED NOT NULL,
    order_quantity_at_report INT UNSIGNED NOT NULL,
    partial TINYINT(1) NOT NULL,
    -- When the cutting handoff the responsibility refers to happened on a track (a re-cut piece), its track event.
    -- production_exceptions.responsible_activity_event_id then keeps the order's original cutting event.
    responsible_track_event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (exception_uuid),
    KEY idx_production_exception_scopes_track (track_uuid),
    KEY idx_production_exception_scopes_order (order_uuid),
    KEY idx_production_exception_scopes_handoff (responsible_track_event_uuid),
    CONSTRAINT fk_production_exception_scopes_exception FOREIGN KEY (exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exception_scopes_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exception_scopes_track FOREIGN KEY (track_uuid) REFERENCES production_tracks (track_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exception_scopes_reported_on FOREIGN KEY (reported_on_track_uuid) REFERENCES production_tracks (track_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_exception_scopes_quantity CHECK (affected_quantity > 0 AND affected_quantity <= carrier_quantity_at_report AND carrier_quantity_at_report <= order_quantity_at_report),
    CONSTRAINT chk_production_exception_scopes_partial CHECK (partial IN (0, 1) AND (partial = 0 OR track_uuid IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_exception_units (
    exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unit_index INT UNSIGNED NOT NULL,
    unit_count_snapshot INT UNSIGNED NOT NULL,
    rework_cycle_at_report INT UNSIGNED NOT NULL,
    PRIMARY KEY (exception_uuid, unit_uuid),
    KEY idx_production_exception_units_unit (unit_uuid),
    CONSTRAINT fk_production_exception_units_exception FOREIGN KEY (exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exception_units_unit FOREIGN KEY (unit_uuid) REFERENCES production_work_units (unit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_exception_units_index CHECK (unit_index > 0 AND unit_index <= unit_count_snapshot AND rework_cycle_at_report > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_track_events (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    from_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    to_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    previous_owner_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    new_owner_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    version_before INT UNSIGNED NOT NULL,
    version_after INT UNSIGNED NOT NULL,
    rework_cycle INT UNSIGNED NOT NULL,
    exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    metadata_json JSON NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_uuid),
    UNIQUE KEY uq_production_track_events_version (track_uuid, version_after),
    UNIQUE KEY uq_production_track_events_idempotency (employee_uuid, idempotency_key),
    KEY idx_production_track_events_order (order_uuid, occurred_at),
    KEY idx_production_track_events_employee (employee_uuid, occurred_at),
    CONSTRAINT fk_production_track_events_track FOREIGN KEY (track_uuid) REFERENCES production_tracks (track_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_track_events_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_track_events_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_track_events_action CHECK (action IN ('created', 'claimed', 'stage_completed', 'merged',
        'fault_reported', 'defect_dismissed', 'defect_custody_taken', 'fault_returned', 'fault_rejected', 'fault_rereview_requested',
        'defect_restored', 'fault_cancelled', 'split')),
    CONSTRAINT chk_production_track_events_versions CHECK (version_after > version_before AND rework_cycle > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_track_idempotency (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash BINARY(32) NOT NULL,
    response_json MEDIUMTEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (employee_uuid, idempotency_key),
    KEY idx_production_track_idempotency_created (created_at),
    CONSTRAINT fk_production_track_idempotency_track FOREIGN KEY (track_uuid) REFERENCES production_tracks (track_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_unit_labels (
    label_reference CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    issued_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    issued_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (label_reference),
    KEY idx_production_unit_labels_unit (unit_uuid, status),
    CONSTRAINT fk_production_unit_labels_unit FOREIGN KEY (unit_uuid) REFERENCES production_work_units (unit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_labels_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_labels_issuer FOREIGN KEY (issued_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_unit_labels_status CHECK (status IN ('active', 'revoked') AND ((status = 'revoked') = (revoked_at IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
