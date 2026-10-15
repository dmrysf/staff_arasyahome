-- Assisted PERDEA checkpoints at bottom-hem (stage 6) and side-hem (stage 7) (API 2.34.0).
--
-- Additive. Builds on migrations 028, 029 and 030 (left exactly as they were applied) and never rewrites a row. Every
-- statement is CREATE TABLE IF NOT EXISTS or INSERT IGNORE, so an interrupted run can be resumed and a re-run changes
-- nothing.
--
-- Why: under route policy version 1 (piece_routing) a PERDEA piece skips stages 6 and 7 (stage_not_applicable). In the
-- factory a PERDEA piece does need work at those stages, done by a helper from another area, and the stage employee must
-- verify the result piece by piece. Route policy version 2 (feature perdea_assisted_checkpoints) makes stages 6 and 7
-- applicable to PERDEA with the requirement "assisted checkpoint": the carrier cannot leave the stage until every PERDEA
-- piece on it has a positive verification for that stage in its current production cycle. A verification is not a
-- manufacturing completion: it writes no production_unit_stage_log row and earns no stage or piece-work credit.
--
-- Model
--   production_assisted_checkpoints
--        Current state of one checkpoint: one physical piece, one production cycle, one stage (6 or 7). Created by the
--        first action on it; no row means "awaiting the auxiliary work". status:
--          taken_for_work   a helper took the piece for the work (helper_employee_uuid, taken_at)
--          work_returned    the helper returned the piece to the verification point (returned_at)
--          verified         the stage employee checked the piece: the work was done (verified_by, verified_at)
--          not_performed    the stage employee checked the piece: the work was not done (blocks like no row)
--        version is the optimistic-lock counter of the row. A stage-6 row never satisfies stage 7 and a row of an
--        earlier cycle never satisfies a later cycle (the key includes both).
--   production_assisted_checkpoint_events
--        Append-only history of every checkpoint action with the actor, the recorded helper, the scan and the version.
--   production_feature_flags 'perdea_assisted_checkpoints'
--        Route policy version 2, created disabled. Enabled globally or per audited pilot cohort (production_pilot_cohort),
--        like partial_rework and piece_routing.
--
-- Historical exemptions recorded under policy version 1 (production_route_decisions kind stage_not_applicable and the
-- matching production_unit_stage_log not_applicable rows) stay exactly as they are and remain valid for the piece and
-- cycle they were recorded for. Nothing is backfilled.

INSERT IGNORE INTO production_feature_flags (flag_key, enabled, changed_by_employee_uuid, change_reason, changed_at)
VALUES ('perdea_assisted_checkpoints', 0, NULL, 'Created disabled by migration 031', UTC_TIMESTAMP(6));

CREATE TABLE IF NOT EXISTS production_assisted_checkpoints (
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rework_cycle INT UNSIGNED NOT NULL,
    stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    helper_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    taken_at DATETIME(6) NULL,
    returned_at DATETIME(6) NULL,
    verified_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    verified_at DATETIME(6) NULL,
    verified_via VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    carrier_track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    policy_version INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (unit_uuid, rework_cycle, stage_id),
    KEY idx_assisted_checkpoints_order (order_uuid, stage_id, status),
    KEY idx_assisted_checkpoints_verifier (verified_by_employee_uuid, verified_at),
    KEY idx_assisted_checkpoints_helper (helper_employee_uuid, returned_at),
    CONSTRAINT fk_assisted_checkpoints_unit FOREIGN KEY (unit_uuid) REFERENCES production_work_units (unit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_assisted_checkpoints_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_assisted_checkpoints_stage FOREIGN KEY (stage_id) REFERENCES production_stages (stage_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_assisted_checkpoints_helper FOREIGN KEY (helper_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_assisted_checkpoints_verifier FOREIGN KEY (verified_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_assisted_checkpoints_stage CHECK (stage_id IN ('bottom-hem', 'side-hem')),
    CONSTRAINT chk_assisted_checkpoints_status CHECK (status IN ('taken_for_work', 'work_returned', 'verified', 'not_performed')),
    CONSTRAINT chk_assisted_checkpoints_helper CHECK ((status <> 'taken_for_work' AND status <> 'work_returned') OR (helper_employee_uuid IS NOT NULL AND taken_at IS NOT NULL)),
    CONSTRAINT chk_assisted_checkpoints_returned CHECK (status <> 'work_returned' OR returned_at IS NOT NULL),
    CONSTRAINT chk_assisted_checkpoints_verified CHECK ((status IN ('verified', 'not_performed')) = (verified_by_employee_uuid IS NOT NULL AND verified_at IS NOT NULL AND verified_via IS NOT NULL)),
    CONSTRAINT chk_assisted_checkpoints_values CHECK (rework_cycle > 0 AND version > 0 AND policy_version > 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_assisted_checkpoint_events (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rework_cycle INT UNSIGNED NOT NULL,
    stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status_before VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status_after VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    helper_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    actor_via VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    carrier_track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    version_after INT UNSIGNED NOT NULL,
    scanned VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    note VARCHAR(500) NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_uuid),
    UNIQUE KEY uq_assisted_checkpoint_events_version (unit_uuid, rework_cycle, stage_id, version_after),
    KEY idx_assisted_checkpoint_events_order (order_uuid, occurred_at),
    KEY idx_assisted_checkpoint_events_actor (actor_employee_uuid, occurred_at),
    CONSTRAINT fk_assisted_checkpoint_events_checkpoint FOREIGN KEY (unit_uuid, rework_cycle, stage_id) REFERENCES production_assisted_checkpoints (unit_uuid, rework_cycle, stage_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_assisted_checkpoint_events_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_assisted_checkpoint_events_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_assisted_checkpoint_events_helper FOREIGN KEY (helper_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_assisted_checkpoint_events_action CHECK (action IN ('taken_for_work', 'work_returned', 'verified', 'not_performed')),
    CONSTRAINT chk_assisted_checkpoint_events_status CHECK (status_after = action AND (status_before IS NULL OR status_before IN ('taken_for_work', 'work_returned', 'verified', 'not_performed'))),
    CONSTRAINT chk_assisted_checkpoint_events_values CHECK (rework_cycle > 0 AND version_after > 0 AND scanned IN ('piece_label', 'order_qr'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
