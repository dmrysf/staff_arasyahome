-- Verified historical stage evidence for physical pieces materialized after cutting (API 2.33.0).
--
-- Additive. Builds on migrations 028 and 029 (left exactly as they were applied) and never rewrites a row. Every
-- statement is CREATE TABLE IF NOT EXISTS, so an interrupted run can be resumed and a re-run changes nothing.
--
-- Why: the per-piece stage ledger of a piece starts where the piece was materialized (production_unit_cycles). A piece of
-- an order that was already past cutting when its plan was confirmed has no per-piece completion for the stages before
-- that point, and the packing gate (correctly) refuses it with ORDER_ROUTE_INCOMPLETE. Nothing may synthesize those
-- completions. This migration stores the separate, auditable record of an authorized person who reviewed the genuine
-- evidence of such a stage for that exact piece.
--
-- Model
--   production_route_reconciliations
--        One verification session of one piece in one production cycle: who verified, when, the explanation, which
--        stages the evidence supports and which stages remain uncertain (those keep blocking packing). Append-only.
--   production_route_reconciliation_stages
--        One row per supported stage of a piece cycle, with the evidence reviewed: either the order-level completion
--        event recorded at that time (order_activity_events, a stage_completed of that stage before the piece's ledger
--        started) or a physical inspection of the piece by the verifier. A stage can be reconciled once per piece and
--        cycle. Only stages before the start of the piece's ledger can ever be reconciled; the original event history
--        (order_activity_events, production_unit_stage_log) is never changed and never receives a synthesized row.

CREATE TABLE IF NOT EXISTS production_route_reconciliations (
    reconciliation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rework_cycle INT UNSIGNED NOT NULL,
    ledger_start_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    supported_stages_json JSON NOT NULL,
    uncertain_stages_json JSON NOT NULL,
    verified_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    verified_via VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    note VARCHAR(500) NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (reconciliation_uuid),
    KEY idx_route_reconciliations_order (order_uuid, occurred_at),
    KEY idx_route_reconciliations_unit (unit_uuid, rework_cycle),
    CONSTRAINT fk_route_reconciliations_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_route_reconciliations_unit FOREIGN KEY (unit_uuid) REFERENCES production_work_units (unit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_route_reconciliations_stage FOREIGN KEY (ledger_start_stage_id) REFERENCES production_stages (stage_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_route_reconciliations_employee FOREIGN KEY (verified_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_route_reconciliations_cycle CHECK (rework_cycle > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_route_reconciliation_stages (
    unit_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rework_cycle INT UNSIGNED NOT NULL,
    stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reconciliation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evidence_kind VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    activity_event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (unit_uuid, rework_cycle, stage_id),
    KEY idx_route_reconciliation_stages_order (order_uuid),
    KEY idx_route_reconciliation_stages_session (reconciliation_uuid),
    CONSTRAINT fk_route_reconciliation_stages_session FOREIGN KEY (reconciliation_uuid) REFERENCES production_route_reconciliations (reconciliation_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_route_reconciliation_stages_unit FOREIGN KEY (unit_uuid) REFERENCES production_work_units (unit_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_route_reconciliation_stages_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_route_reconciliation_stages_stage FOREIGN KEY (stage_id) REFERENCES production_stages (stage_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_route_reconciliation_stages_activity FOREIGN KEY (activity_event_id) REFERENCES order_activity_events (event_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_route_reconciliation_stages_evidence CHECK ((evidence_kind = 'order_activity' AND activity_event_id IS NOT NULL) OR (evidence_kind = 'physical_inspection' AND activity_event_id IS NULL)),
    CONSTRAINT chk_route_reconciliation_stages_cycle CHECK (rework_cycle > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
