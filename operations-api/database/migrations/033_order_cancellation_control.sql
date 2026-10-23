-- Authoritative production cancellation control (API 2.35.0, Phase 1 of the cancellation programme).
--
-- Additive. No existing row is rewritten, no history is reclassified and nothing is backfilled: an order that was
-- cancelled before this migration keeps exactly its stored facts. Every statement is resumable: tables use CREATE TABLE
-- IF NOT EXISTS, the permissions are upserted, and the operational_orders columns are added by one guarded ALTER that
-- runs only while the columns are missing, so an interrupted run can simply be repeated. The legacy candidates (open orders whose source
-- reports a cancellation but which were never stopped) are listed read-only by `bin/cancellation-control.php audit`;
-- placing a stop on them is a separate, explicitly approved operator command.
--
-- Number 032 is deliberately skipped: it is used by the simulation pull request (032_production_line_readiness.sql),
-- which must never run in production. Skipping it keeps the two files from colliding if both are ever merged.
--
-- Model
--   operational_orders.cancellation_state / production_stopped_at
--        Projection of the control row, maintained only by CancellationControl in the transaction that writes the
--        control row, with the order row locked. NULL/NULL: the order never had a cancellation control. 'held' with
--        production_stopped_at: production is frozen (the shared guard ProductionStop refuses every manufacturing
--        command). 'released' with NULL: an authorized release lifted the stop. Every production command reads these
--        two columns with a locking read of the order row it already holds, so the guard never depends on a gap lock
--        or on a transaction snapshot taken before the order lock.
--   order_cancellation_controls
--        One row per order that was ever stopped. Separate, explicit facts instead of one boolean:
--          hold_status            held | released                         (is production frozen inside Arasya)
--          origin                 source | local                          (who stopped it first)
--          source_status          cancelled | reactivated | not_reported  (what the source states now)
--          source_request_status  not_requested | pending | confirmed | withdrawn
--                                 (a locally requested commercial cancellation: pending = requested in Arasya and
--                                 never sent; Phase 1 performs no outbound write; confirmed = the source reported it)
--          production_phase       not_started | in_progress | completed   (at the time of the stop)
--          physical_status        not_required | pending | verified       (physical reconciliation)
--          physical_outcome       secured | nothing_produced | left_factory (only when verified)
--        The snapshot columns (*_at_hold) describe the order when the stop was applied: stage, production owner, open
--        exception, production version, completion time, first cutting claim and the active piece tracks. They are
--        evidence for people; they are never used to move the order.
--   order_cancellation_events
--        Append-only, ordered (event_seq) audit trail. A source event is recorded at most once per order and event
--        type (uq_order_cancellation_events_source), so a repeated network delivery never duplicates the audit.
--   permissions production.cancellation.manage / production.cancellation.release
--        Created role-grantable and granted to no role. Root holds every permission; anyone else needs an explicit
--        role assignment by a person.

SET @arasya_033_state_missing = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'operational_orders' AND column_name = 'cancellation_state');

SET @arasya_033_alter = IF(@arasya_033_state_missing,
    'ALTER TABLE operational_orders
        ADD COLUMN cancellation_state VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER source_reported_unavailable_at,
        ADD COLUMN production_stopped_at DATETIME(6) NULL AFTER cancellation_state,
        ADD KEY idx_operational_orders_cancellation (cancellation_state, production_stopped_at),
        ADD CONSTRAINT chk_operational_orders_cancellation CHECK (
            (cancellation_state IS NULL AND production_stopped_at IS NULL)
            OR (cancellation_state = ''held'' AND production_stopped_at IS NOT NULL)
            OR (cancellation_state = ''released'' AND production_stopped_at IS NULL))',
    'DO 0');

PREPARE arasya_033_statement FROM @arasya_033_alter;

EXECUTE arasya_033_statement;

DEALLOCATE PREPARE arasya_033_statement;

CREATE TABLE IF NOT EXISTS order_cancellation_controls (
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    hold_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    origin VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason_note VARCHAR(1000) NULL,
    source_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_status_code VARCHAR(100) NULL,
    source_status_label VARCHAR(255) NULL,
    source_event_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_changed_at DATETIME(6) NULL,
    source_cancellation_received_at DATETIME(6) NULL,
    source_evidence_at DATETIME(6) NULL,
    source_request_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_requested_at DATETIME(6) NULL,
    source_requested_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    production_phase VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stage_id_at_hold VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_employee_uuid_at_hold CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    exception_uuid_at_hold CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    production_version_at_hold INT UNSIGNED NOT NULL,
    production_completed_at_hold DATETIME(6) NULL,
    cutting_started_at_hold DATETIME(6) NULL,
    active_tracks_at_hold JSON NULL,
    physical_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    physical_outcome VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    physical_note VARCHAR(1000) NULL,
    physical_scan VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    physical_verified_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    physical_verified_at DATETIME(6) NULL,
    held_at DATETIME(6) NOT NULL,
    held_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    released_at DATETIME(6) NULL,
    released_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    release_reason VARCHAR(1000) NULL,
    version INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (order_uuid),
    KEY idx_order_cancellation_controls_state (hold_status, physical_status, updated_at),
    KEY idx_order_cancellation_controls_source (source_key, hold_status),
    CONSTRAINT fk_order_cancellation_controls_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_cancellation_controls_stage FOREIGN KEY (stage_id_at_hold) REFERENCES production_stages (stage_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_cancellation_controls_held_by FOREIGN KEY (held_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_cancellation_controls_requested_by FOREIGN KEY (source_requested_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_cancellation_controls_physical_by FOREIGN KEY (physical_verified_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_cancellation_controls_released_by FOREIGN KEY (released_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_cancellation_controls_hold CHECK (hold_status IN ('held', 'released')),
    CONSTRAINT chk_order_cancellation_controls_origin CHECK (origin IN ('source', 'local')),
    CONSTRAINT chk_order_cancellation_controls_reason CHECK (reason_code IN ('source_cancelled', 'marketplace_cancelled', 'manager_stop', 'legacy_source_cancellation')),
    CONSTRAINT chk_order_cancellation_controls_source_status CHECK (source_status IN ('cancelled', 'reactivated', 'not_reported')),
    CONSTRAINT chk_order_cancellation_controls_request CHECK (source_request_status IN ('not_requested', 'pending', 'confirmed', 'withdrawn')),
    CONSTRAINT chk_order_cancellation_controls_phase CHECK (production_phase IN ('not_started', 'in_progress', 'completed')),
    CONSTRAINT chk_order_cancellation_controls_physical CHECK (physical_status IN ('not_required', 'pending', 'verified')),
    CONSTRAINT chk_order_cancellation_controls_outcome CHECK (
        (physical_status = 'verified' AND physical_outcome IN ('secured', 'nothing_produced', 'left_factory')
            AND physical_verified_by_employee_uuid IS NOT NULL AND physical_verified_at IS NOT NULL)
        OR (physical_status <> 'verified' AND physical_outcome IS NULL)),
    CONSTRAINT chk_order_cancellation_controls_origin_local CHECK (origin = 'source' OR held_by_employee_uuid IS NOT NULL OR reason_code <> 'manager_stop'),
    CONSTRAINT chk_order_cancellation_controls_released CHECK (
        (hold_status = 'released' AND released_at IS NOT NULL AND released_by_employee_uuid IS NOT NULL AND release_reason IS NOT NULL)
        OR (hold_status = 'held' AND released_at IS NULL AND released_by_employee_uuid IS NULL AND release_reason IS NULL)),
    CONSTRAINT chk_order_cancellation_controls_version CHECK (version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_cancellation_events (
    event_seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    control_version INT UNSIGNED NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_event_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    production_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    production_version INT UNSIGNED NOT NULL,
    details_json JSON NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_seq),
    UNIQUE KEY uq_order_cancellation_events_uuid (event_uuid),
    UNIQUE KEY uq_order_cancellation_events_source (order_uuid, event_type, source_event_ref),
    KEY idx_order_cancellation_events_order (order_uuid, event_seq),
    KEY idx_order_cancellation_events_type (event_type, occurred_at),
    CONSTRAINT fk_order_cancellation_events_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_cancellation_events_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_cancellation_events_type CHECK (event_type IN (
        'source_cancellation_received', 'hold_applied', 'source_evidence_updated', 'source_reactivated',
        'source_cancellation_requested', 'source_cancellation_confirmed', 'duplicate_ignored',
        'physical_reconciliation_requested', 'physical_reconciliation_recorded', 'release_approved', 'release_refused'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('production.cancellation.manage', 'production', 'Oprire producție și reconciliere fizică', 'Stop the production of an order inside Arasya and record the physical reconciliation of a stopped order (never a stage change)', 1, UTC_TIMESTAMP(6)),
    ('production.cancellation.release', 'production', 'Reluare producție după oprire', 'Release a production stop after the source, physical, document and quality prerequisites were verified', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE category = VALUES(category), label = VALUES(label), description = VALUES(description), role_grantable = VALUES(role_grantable);
