-- Informational PERDEA auxiliary-work status at bottom-hem (stage 6) and side-hem (stage 7) (API 2.34.0).
--
-- Additive. Builds on migrations 028, 029 and 030 (left exactly as they were applied) and never rewrites a row. Every
-- statement is CREATE TABLE IF NOT EXISTS or INSERT IGNORE, so an interrupted run can be resumed and a re-run changes
-- nothing.
--
-- Why: under route policy version 1 (piece_routing) a PERDEA piece skips stages 6 and 7 (stage_not_applicable). In the
-- factory a PERDEA piece may need an auxiliary operation at those stages, often done by another employee. Route policy
-- version 2 (feature perdea_assisted_checkpoints) keeps stages 6 and 7 on the PERDEA route and lets the stage employee
-- record, per physical piece, stage and production cycle, whether that auxiliary work was done. The status is
-- informational only: it never blocks a stage transition, a track completion, packing or delivery, and it is never a
-- manufacturing completion (no production_unit_stage_log row, no stage or piece-work credit).
--
-- Model
--   production_assisted_checkpoints
--        Current status of one piece, production cycle and stage (6 or 7):
--          unmarked        Neconfirmată  (nobody marked it; also recorded when the carrier leaves the stage unmarked)
--          performed       Efectuată
--          not_performed   Neefectuată
--        marked_by / marked_at / marked_via name the stage employee of the latest mark (NULL while never marked).
--        version is the optimistic-lock counter. A stage-6 row never stands for stage 7, a cycle-1 row never for cycle 2.
--   production_assisted_checkpoint_events
--        Append-only history of every mark (status before and after, actor, version, optional scan).
--   production_feature_flags 'perdea_assisted_checkpoints'
--        Route policy version 2, created disabled. Enabled globally or per audited pilot cohort (production_pilot_cohort).
--
-- Historical exemptions recorded under policy version 1 (production_route_decisions kind stage_not_applicable and the
-- matching production_unit_stage_log not_applicable rows) stay exactly as they are. Nothing is backfilled.
--
-- Revision note: this file was simplified (no helper take/return model, no mandatory verification) on 2026-10-15
-- before it was applied to any shared environment; production was at 027 and no environment recorded 031.

INSERT IGNORE INTO production_feature_flags (flag_key, enabled, changed_by_employee_uuid, change_reason, changed_at)
VALUES ('perdea_assisted_checkpoints', 0, NULL, 'Created disabled by migration 031', UTC_TIMESTAMP(6));

CREATE TABLE IF NOT EXISTS production_assisted_checkpoints (
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rework_cycle INT UNSIGNED NOT NULL,
    stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    marked_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    marked_at DATETIME(6) NULL,
    marked_via VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    carrier_track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    policy_version INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (unit_uuid, rework_cycle, stage_id),
    KEY idx_assisted_checkpoints_order (order_uuid, stage_id, status),
    KEY idx_assisted_checkpoints_marker (marked_by_employee_uuid, marked_at),
    KEY idx_assisted_checkpoints_track (carrier_track_uuid, stage_id),
    CONSTRAINT fk_assisted_checkpoints_unit FOREIGN KEY (unit_uuid) REFERENCES production_work_units (unit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_assisted_checkpoints_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_assisted_checkpoints_stage FOREIGN KEY (stage_id) REFERENCES production_stages (stage_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_assisted_checkpoints_marker FOREIGN KEY (marked_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_assisted_checkpoints_stage CHECK (stage_id IN ('bottom-hem', 'side-hem')),
    CONSTRAINT chk_assisted_checkpoints_status CHECK (status IN ('unmarked', 'performed', 'not_performed')),
    CONSTRAINT chk_assisted_checkpoints_marked CHECK ((marked_by_employee_uuid IS NULL) = (marked_at IS NULL) AND (marked_at IS NULL) = (marked_via IS NULL) AND (status = 'unmarked' OR marked_at IS NOT NULL)),
    CONSTRAINT chk_assisted_checkpoints_values CHECK (rework_cycle > 0 AND version > 0 AND policy_version > 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_assisted_checkpoint_events (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rework_cycle INT UNSIGNED NOT NULL,
    stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status_before VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status_after VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_via VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    carrier_track_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    version_after INT UNSIGNED NOT NULL,
    scanned VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
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
    CONSTRAINT chk_assisted_checkpoint_events_status CHECK (status_before IN ('unmarked', 'performed', 'not_performed') AND status_after IN ('unmarked', 'performed', 'not_performed') AND status_before <> status_after),
    CONSTRAINT chk_assisted_checkpoint_events_values CHECK (rework_cycle > 0 AND version_after > 0 AND (scanned IS NULL OR scanned IN ('piece_label', 'order_qr')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
