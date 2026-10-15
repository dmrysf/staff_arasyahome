-- Simulation line readiness (factory simulation, PR #64). Numbered 032, after the per-piece chain's 028-031
-- (028_production_work_units, 029_production_manifest_routing, 030_production_route_reconciliation,
-- 031_production_assisted_checkpoints), which share this migration directory and the schema_migrations registry.
-- It was first committed as 028_production_line_readiness.sql; that prefix collided with 028_production_work_units.sql.
-- Its tables are created only when missing, so a simulation database that already applied the old 028 name
-- re-applies this file without error (the old registry row stays and is ignored by the runner).
ALTER TABLE order_operation_idempotency DROP CONSTRAINT chk_order_operation_idempotency_operation;
ALTER TABLE order_operation_idempotency ADD CONSTRAINT chk_order_operation_idempotency_operation CHECK (operation IN ('claim','transition','release_owner','reassign_owner','authority_takeover','authority_release','qr_rotate','line_check'));
-- Additive line checks. Stable source_item_id survives source projection replacement.
CREATE TABLE IF NOT EXISTS production_line_checks (
 order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_item_id VARCHAR(128) NOT NULL,
 stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 completed_quantity INT UNSIGNED NOT NULL,
 employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 checked_at DATETIME(6) NOT NULL,
 PRIMARY KEY(order_uuid,source_item_id,stage_id),
 CONSTRAINT fk_line_check_order FOREIGN KEY(order_uuid) REFERENCES operational_orders(order_uuid),
 CONSTRAINT fk_line_check_employee FOREIGN KEY(employee_uuid) REFERENCES employees(employee_uuid)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS production_line_events (
 event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_item_id VARCHAR(128) NULL,
 stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 action VARCHAR(32) NOT NULL,
 completed_quantity INT UNSIGNED NULL,
 content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 production_version BIGINT UNSIGNED NOT NULL,
 request_id VARCHAR(100) NOT NULL,
 occurred_at DATETIME(6) NOT NULL,
 KEY idx_line_event_order(order_uuid,occurred_at),
 CONSTRAINT fk_line_event_order FOREIGN KEY(order_uuid) REFERENCES operational_orders(order_uuid),
 CONSTRAINT fk_line_event_employee FOREIGN KEY(employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT chk_line_event_action CHECK(action IN ('confirmed','invalidated','not_required'))
) ENGINE=InnoDB;
