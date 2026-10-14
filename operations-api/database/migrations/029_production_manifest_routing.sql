-- Manufacturing manifest, product routes, piece custody, material records and replacement lineage (API 2.32.0).
--
-- Additive. Builds on migration 028 (which is left exactly as it was applied) and never rewrites a row. Every new table
-- is CREATE TABLE IF NOT EXISTS, every seed is INSERT IGNORE. The only change to an existing table relaxes one column:
-- production_tracks.origin_exception_uuid becomes nullable, because pieces that only follow a different product route
-- (a PERDEA line skipping stages 6-7 inside a mixed order) travel on their own track without any defect. The MODIFY is
-- idempotent: re-running it leaves the same definition.
--
-- Model
--   production_line_plans / production_line_plan_heads / production_plan_pieces
--        The manufacturing plan of one commercial line, revisioned. A commercial quantity is never taken as the number
--        of physical pieces: piece_count stays NULL (unknown) until an authorized person confirms it, and every change
--        writes a new revision (the head points to the active one). product_type is the production classification
--        (perdea, draperii, other, unknown) with its source; per-piece measurements and fabric are NULL when unknown,
--        never zero and never divided automatically.
--   production_route_decisions
--        Append-only audit of every route decision of the central route policy: stages that are not applicable to a
--        product type (stage_not_applicable), pieces split onto their own route track (route_split), and carriers that
--        keep the full route because a classification or plan is missing (full_route_unverified).
--   production_unit_stage_log / production_unit_cycles
--        One row per physical piece per stage outcome in a production cycle: completed (with the employee, the event
--        and the version) or not_applicable (with the route decision). Written from the moment a piece is materialized
--        (production_unit_cycles records that stage); the packing gate reads it.
--   production_unit_replacements
--        Lineage of a full replacement: the original piece is retired, a new piece (new identity, new label) takes its
--        deliverable slot. One replacement per original, so a retry can never create two.
--   production_locations / production_unit_custody / production_custody_events
--        A controlled registry of physical places (created by an operator, never invented by the software), the current
--        custodian / location / hold of each piece, and the append-only history. Custody never changes a production
--        stage or owner.
--   production_material_records
--        Planned, actual, waste and replacement fabric per piece and cycle, append-only and idempotent. stock_effect is
--        always 'none': no authoritative stock ledger exists, so no warehouse quantity is ever changed.
--   production_exception_links
--        An administrative recovery (a report re-opened because a return was withdrawn by mistake) points to the
--        original report of the same physical defect, so quality facts are not counted twice.
--   production_pilot_cohort / production_feature_flag_events
--        Controlled activation: a feature applies to an order when its global flag is enabled or when the order is in
--        the audited pilot cohort of that feature. Every flag and cohort change is recorded.
--   Commands on plans, custody and material replay through the shared per-employee idempotency table
--   (production_exception_idempotency), like the Trendyol workspace.
--   production_feature_flags 'piece_routing'
--        The product route policy (PERDEA skips 6-7, mixed orders split by route), created disabled.

ALTER TABLE production_tracks MODIFY origin_exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL;

INSERT IGNORE INTO production_feature_flags (flag_key, enabled, changed_by_employee_uuid, change_reason, changed_at)
VALUES ('piece_routing', 0, NULL, 'Created disabled by migration 029', UTC_TIMESTAMP(6));

CREATE TABLE IF NOT EXISTS production_line_plans (
    plan_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- No foreign key to operational_order_items: the source projection rewrites item rows with the same item_uuid.
    item_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_item_id_snapshot VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    product_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    type_source VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    commercial_quantity_snapshot INT UNSIGNED NOT NULL,
    source_plan_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    piece_count INT UNSIGNED NULL,
    piece_count_source VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    material_total DECIMAL(12,3) NULL,
    material_unit VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'm',
    material_source VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    equal_split TINYINT(1) NOT NULL DEFAULT 0,
    confirmed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    confirmed_at DATETIME(6) NULL,
    confirmation_note VARCHAR(500) NULL,
    supersedes_plan_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (plan_uuid),
    UNIQUE KEY uq_production_line_plans_revision (item_uuid, revision),
    KEY idx_production_line_plans_order (order_uuid),
    CONSTRAINT fk_production_line_plans_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_line_plans_confirmer FOREIGN KEY (confirmed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_line_plans_supersedes FOREIGN KEY (supersedes_plan_uuid) REFERENCES production_line_plans (plan_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_line_plans_type CHECK (product_type IN ('perdea', 'draperii', 'other', 'unknown')),
    CONSTRAINT chk_production_line_plans_type_source CHECK (type_source IN ('source_classification', 'confirmed', 'none')),
    CONSTRAINT chk_production_line_plans_count CHECK ((piece_count IS NULL AND piece_count_source = 'none') OR (piece_count BETWEEN 1 AND 200 AND piece_count_source = 'confirmed')),
    CONSTRAINT chk_production_line_plans_material CHECK ((material_total IS NULL AND material_source = 'none') OR (material_total > 0 AND material_source IN ('source_line', 'confirmed'))),
    CONSTRAINT chk_production_line_plans_unit CHECK (material_unit = 'm'),
    CONSTRAINT chk_production_line_plans_revision CHECK (revision > 0 AND commercial_quantity_snapshot > 0 AND equal_split IN (0, 1)),
    CONSTRAINT chk_production_line_plans_confirmed CHECK ((confirmed_at IS NULL) = (confirmed_by_employee_uuid IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_line_plan_heads (
    item_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    plan_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    type_locked_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (item_uuid),
    UNIQUE KEY uq_production_line_plan_heads_plan (plan_uuid),
    KEY idx_production_line_plan_heads_order (order_uuid),
    CONSTRAINT fk_production_line_plan_heads_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_line_plan_heads_plan FOREIGN KEY (plan_uuid) REFERENCES production_line_plans (plan_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_plan_pieces (
    plan_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    piece_index INT UNSIGNED NOT NULL,
    width_value DECIMAL(12,3) NULL,
    height_value DECIMAL(12,3) NULL,
    measurement_unit VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    fabric_length DECIMAL(12,3) NULL,
    measurement_source VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    fabric_source VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (plan_uuid, piece_index),
    CONSTRAINT fk_production_plan_pieces_plan FOREIGN KEY (plan_uuid) REFERENCES production_line_plans (plan_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_plan_pieces_index CHECK (piece_index BETWEEN 1 AND 200),
    CONSTRAINT chk_production_plan_pieces_size CHECK ((width_value IS NULL OR width_value > 0) AND (height_value IS NULL OR height_value > 0)
        AND ((width_value IS NULL AND height_value IS NULL) OR measurement_unit IN ('mm', 'cm', 'm'))),
    CONSTRAINT chk_production_plan_pieces_fabric CHECK ((fabric_length IS NULL AND fabric_source = 'unknown') OR (fabric_length > 0 AND fabric_source IN ('confirmed', 'equal_split'))),
    CONSTRAINT chk_production_plan_pieces_source CHECK (measurement_source IN ('confirmed', 'line', 'unknown'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_route_decisions (
    decision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    new_track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    kind VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    from_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    to_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    skipped_stages_json JSON NULL,
    product_types_json JSON NOT NULL,
    units_json JSON NULL,
    reason VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    policy_version INT UNSIGNED NOT NULL,
    rework_cycle INT UNSIGNED NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (decision_uuid),
    KEY idx_production_route_decisions_order (order_uuid, occurred_at),
    KEY idx_production_route_decisions_track (track_uuid),
    CONSTRAINT fk_production_route_decisions_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_route_decisions_track FOREIGN KEY (track_uuid) REFERENCES production_tracks (track_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_route_decisions_new_track FOREIGN KEY (new_track_uuid) REFERENCES production_tracks (track_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_route_decisions_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_route_decisions_kind CHECK (kind IN ('stage_not_applicable', 'route_split', 'full_route_unverified')),
    CONSTRAINT chk_production_route_decisions_reason CHECK (reason IN ('product_route_policy', 'classification_unverified', 'plan_unverified'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_unit_stage_log (
    log_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rework_cycle INT UNSIGNED NOT NULL,
    stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    carrier_track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    to_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    carrier_version_before INT UNSIGNED NULL,
    route_decision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (log_uuid),
    UNIQUE KEY uq_production_unit_stage_log_once (unit_uuid, rework_cycle, stage_id),
    KEY idx_production_unit_stage_log_order (order_uuid),
    CONSTRAINT fk_production_unit_stage_log_unit FOREIGN KEY (unit_uuid) REFERENCES production_work_units (unit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_stage_log_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_stage_log_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_stage_log_decision FOREIGN KEY (route_decision_uuid) REFERENCES production_route_decisions (decision_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_unit_stage_log_outcome CHECK ((outcome = 'completed' AND employee_uuid IS NOT NULL) OR (outcome = 'not_applicable' AND route_decision_uuid IS NOT NULL)),
    CONSTRAINT chk_production_unit_stage_log_cycle CHECK (rework_cycle > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where the per-piece ledger of a production cycle starts: the stage a piece was at when it was materialized (cycle 1),
-- or material-preparation for a rework cycle. Earlier stages of cycle 1 are recorded at order level only.
CREATE TABLE IF NOT EXISTS production_unit_cycles (
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rework_cycle INT UNSIGNED NOT NULL,
    started_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    started_at DATETIME(6) NOT NULL,
    PRIMARY KEY (unit_uuid, rework_cycle),
    CONSTRAINT fk_production_unit_cycles_unit FOREIGN KEY (unit_uuid) REFERENCES production_work_units (unit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_cycles_stage FOREIGN KEY (started_stage_id) REFERENCES production_stages (stage_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_unit_cycles_cycle CHECK (rework_cycle > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_unit_replacements (
    replacement_unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    slot_index INT UNSIGNED NOT NULL,
    track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    rework_cycle INT UNSIGNED NOT NULL,
    original_disposition VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decided_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (replacement_unit_uuid),
    UNIQUE KEY uq_production_unit_replacements_original (original_unit_uuid),
    KEY idx_production_unit_replacements_slot (item_uuid, slot_index),
    CONSTRAINT fk_production_unit_replacements_new FOREIGN KEY (replacement_unit_uuid) REFERENCES production_work_units (unit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_replacements_original FOREIGN KEY (original_unit_uuid) REFERENCES production_work_units (unit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_replacements_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_replacements_track FOREIGN KEY (track_uuid) REFERENCES production_tracks (track_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_replacements_exception FOREIGN KEY (exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_replacements_actor FOREIGN KEY (decided_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_unit_replacements_disposition CHECK (original_disposition IN ('scrapped', 'quarantined')),
    CONSTRAINT chk_production_unit_replacements_values CHECK (slot_index > 0 AND rework_cycle > 0 AND replacement_unit_uuid <> original_unit_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_locations (
    location_id VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    label VARCHAR(160) NOT NULL,
    kind VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by_operator VARCHAR(100) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    retired_at DATETIME(6) NULL,
    PRIMARY KEY (location_id),
    CONSTRAINT fk_production_locations_stage FOREIGN KEY (stage_id) REFERENCES production_stages (stage_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_locations_kind CHECK (kind IN ('cutting', 'tailoring_intake', 'supervisor_table', 'rail', 'rework_hold', 'quality', 'packing_wait', 'other')),
    CONSTRAINT chk_production_locations_status CHECK (status IN ('active', 'retired') AND ((status = 'retired') = (retired_at IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_unit_custody (
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    custodian_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    location_id VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
    hold_reason VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    version INT UNSIGNED NOT NULL,
    confirmed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    confirmed_at DATETIME(6) NOT NULL,
    PRIMARY KEY (unit_uuid),
    KEY idx_production_unit_custody_order (order_uuid),
    KEY idx_production_unit_custody_custodian (custodian_employee_uuid),
    CONSTRAINT fk_production_unit_custody_unit FOREIGN KEY (unit_uuid) REFERENCES production_work_units (unit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_custody_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_custody_custodian FOREIGN KEY (custodian_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_custody_location FOREIGN KEY (location_id) REFERENCES production_locations (location_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_unit_custody_confirmer FOREIGN KEY (confirmed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_unit_custody_hold CHECK (hold_reason IS NULL OR hold_reason IN ('companion_rework', 'inspection', 'reunion_wait', 'quarantine')),
    CONSTRAINT chk_production_unit_custody_version CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_custody_events (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    custodian_before CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    custodian_after CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    location_before VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
    location_after VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
    hold_before VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    hold_after VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    production_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version_after INT UNSIGNED NOT NULL,
    metadata_json JSON NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_uuid),
    UNIQUE KEY uq_production_custody_events_version (unit_uuid, version_after),
    UNIQUE KEY uq_production_custody_events_idempotency (actor_employee_uuid, idempotency_key),
    KEY idx_production_custody_events_order (order_uuid, occurred_at),
    CONSTRAINT fk_production_custody_events_unit FOREIGN KEY (unit_uuid) REFERENCES production_work_units (unit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_custody_events_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_custody_events_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_custody_events_action CHECK (action IN ('custody_taken', 'location_confirmed', 'hold_set', 'hold_released', 'reunited'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_material_records (
    record_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    rework_cycle INT UNSIGNED NOT NULL,
    kind VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    quantity DECIMAL(12,3) NULL,
    unit VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'm',
    stock_effect VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',
    dedupe_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    corrects_record_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    note VARCHAR(500) NULL,
    recorded_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (record_uuid),
    UNIQUE KEY uq_production_material_records_once (dedupe_key),
    UNIQUE KEY uq_production_material_records_idempotency (recorded_by_employee_uuid, idempotency_key),
    KEY idx_production_material_records_order (order_uuid),
    KEY idx_production_material_records_unit (unit_uuid, rework_cycle),
    CONSTRAINT fk_production_material_records_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_material_records_unit FOREIGN KEY (unit_uuid) REFERENCES production_work_units (unit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_material_records_exception FOREIGN KEY (exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_material_records_corrects FOREIGN KEY (corrects_record_uuid) REFERENCES production_material_records (record_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_material_records_actor FOREIGN KEY (recorded_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_material_records_kind CHECK (kind IN ('planned', 'actual', 'waste', 'replacement_planned', 'replacement_actual', 'remnant', 'correction')),
    CONSTRAINT chk_production_material_records_quantity CHECK (quantity IS NULL OR quantity >= 0),
    CONSTRAINT chk_production_material_records_known CHECK (quantity IS NOT NULL OR kind IN ('planned', 'replacement_planned')),
    CONSTRAINT chk_production_material_records_stock CHECK (stock_effect = 'none' AND unit = 'm'),
    CONSTRAINT chk_production_material_records_correction CHECK ((kind = 'correction') = (corrects_record_uuid IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_exception_links (
    exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    linked_exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    link_type VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    linked_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (exception_uuid),
    UNIQUE KEY uq_production_exception_links_recovery (linked_exception_uuid, link_type),
    CONSTRAINT fk_production_exception_links_exception FOREIGN KEY (exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exception_links_linked FOREIGN KEY (linked_exception_uuid) REFERENCES production_exceptions (exception_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_exception_links_actor FOREIGN KEY (linked_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_exception_links_type CHECK (link_type IN ('administrative_recovery') AND exception_uuid <> linked_exception_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_pilot_cohort (
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    feature_key VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    added_by_operator VARCHAR(100) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    added_at DATETIME(6) NOT NULL,
    removed_by_operator VARCHAR(100) NULL,
    removed_at DATETIME(6) NULL,
    PRIMARY KEY (order_uuid, feature_key),
    KEY idx_production_pilot_cohort_feature (feature_key, status),
    CONSTRAINT fk_production_pilot_cohort_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_pilot_cohort_flag FOREIGN KEY (feature_key) REFERENCES production_feature_flags (flag_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_pilot_cohort_status CHECK (status IN ('active', 'removed') AND ((status = 'removed') = (removed_at IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_feature_flag_events (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    feature_key VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    operator VARCHAR(100) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_uuid),
    KEY idx_production_feature_flag_events_feature (feature_key, occurred_at),
    CONSTRAINT fk_production_feature_flag_events_flag FOREIGN KEY (feature_key) REFERENCES production_feature_flags (flag_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_feature_flag_events_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_feature_flag_events_action CHECK (action IN ('enabled', 'disabled', 'cohort_added', 'cohort_removed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Explicit resolution in the current physical cycle, including whole-carrier reports and replacements.
CREATE TABLE IF NOT EXISTS production_unit_verifications (
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rework_cycle INT UNSIGNED NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    exception_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    detected_stage_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    confirmed_at DATETIME(6) NULL,
    confirmed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    confirmed_stage_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    carrier_version_before INT UNSIGNED NULL,
    request_id VARCHAR(100) NULL,
    PRIMARY KEY (unit_uuid, rework_cycle),
    KEY idx_unit_verifications_pending (order_uuid, confirmed_at),
    CONSTRAINT fk_unit_verification_unit FOREIGN KEY (unit_uuid) REFERENCES production_work_units(unit_uuid) ON DELETE RESTRICT,
    CONSTRAINT fk_unit_verification_order FOREIGN KEY (order_uuid) REFERENCES operational_orders(order_uuid) ON DELETE RESTRICT,
    CONSTRAINT fk_unit_verification_exception FOREIGN KEY (exception_uuid) REFERENCES production_exceptions(exception_uuid) ON DELETE RESTRICT,
    CONSTRAINT fk_unit_verification_employee FOREIGN KEY (confirmed_by_employee_uuid) REFERENCES employees(employee_uuid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
