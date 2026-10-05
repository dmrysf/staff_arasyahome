-- Additive internal handoff. Keep these columns, links and history on rollback; never cascade.
INSERT IGNORE INTO order_sources(source_key,source_type,display_name,schema_version,status,created_at,updated_at)
VALUES('b2b','internal','Arasya B2B',1,'active',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6));
ALTER TABLE operational_orders
 ADD COLUMN production_context JSON NULL,
 ADD UNIQUE KEY uq_operational_handoff_identity(order_uuid,source_key,source_order_id);
ALTER TABLE operational_order_items ADD COLUMN production_context JSON NULL;
CREATE TABLE IF NOT EXISTS b2b_production_handoffs (
 b2b_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operational_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'b2b',
 submitted_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 submitted_by_name VARCHAR(160) NOT NULL,
 submitted_at DATETIME(6) NOT NULL,
 request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(b2b_order_uuid),
 UNIQUE KEY uq_b2b_handoff_operational(operational_order_uuid),
 CONSTRAINT fk_b2b_handoff_commercial FOREIGN KEY(b2b_order_uuid) REFERENCES b2b_orders(order_uuid),
 CONSTRAINT fk_b2b_handoff_identity FOREIGN KEY(operational_order_uuid,source_key,b2b_order_uuid)
   REFERENCES operational_orders(order_uuid,source_key,source_order_id),
 CONSTRAINT fk_b2b_handoff_actor FOREIGN KEY(submitted_by_employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT chk_b2b_handoff_source CHECK(source_key='b2b')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE order_activity_events DROP CONSTRAINT chk_order_activity_action;
ALTER TABLE order_activity_events ADD CONSTRAINT chk_order_activity_action CHECK(action IN
 ('claimed','stage_completed','production_completed','owner_released','owner_reassigned','production_submitted'));
INSERT INTO permissions(permission_key,category,label,description,role_grantable,created_at) VALUES
 ('b2b.production.view','b2b','Vizualizare producție B2B','Read canonical production progress of B2B orders',1,UTC_TIMESTAMP(6)),
 ('b2b.production.submit','b2b','Trimitere comenzi B2B în producție','Explicitly submit finalized commercial orders to Staff production',1,UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE permission_key=permission_key;
