-- Additive commercial domain. Old code ignores these tables; retain them on rollback.
CREATE TABLE IF NOT EXISTS b2b_order_number_sequence (
 order_number BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(order_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_orders (
 order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 order_number BIGINT UNSIGNED NOT NULL, order_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'RON',
 status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 source_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 contact_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 billing_address_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 delivery_address_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 customer_reference VARCHAR(160) NULL, notes TEXT NULL, production_notes TEXT NULL,
 company_snapshot JSON NOT NULL, contact_snapshot JSON NULL,
 billing_address_snapshot JSON NULL, delivery_address_snapshot JSON NULL, calculation JSON NOT NULL,
 net_total DECIMAL(16,2) NULL, vat_total DECIMAL(16,2) NULL, gross_total DECIMAL(16,2) NULL,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 finalized_at DATETIME(6) NULL, cancelled_at DATETIME(6) NULL,
 created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 updated_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 finalized_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_by_name VARCHAR(160) NOT NULL, updated_by_name VARCHAR(160) NOT NULL, finalized_by_name VARCHAR(160) NULL,
 PRIMARY KEY(order_uuid), UNIQUE KEY uq_b2b_order_number(order_number), UNIQUE KEY uq_b2b_order_code(order_code),
 KEY idx_b2b_order_company_time(company_uuid,created_at,order_uuid),
 KEY idx_b2b_order_status_time(status,created_at,order_uuid),
 KEY idx_b2b_order_time(created_at,order_uuid),
 CONSTRAINT fk_b2b_order_number FOREIGN KEY(order_number) REFERENCES b2b_order_number_sequence(order_number),
 CONSTRAINT fk_b2b_order_company FOREIGN KEY(company_uuid) REFERENCES b2b_companies(company_uuid),
 CONSTRAINT fk_b2b_order_source FOREIGN KEY(source_order_uuid) REFERENCES b2b_orders(order_uuid),
 CONSTRAINT fk_b2b_order_created_actor FOREIGN KEY(created_by_employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT fk_b2b_order_updated_actor FOREIGN KEY(updated_by_employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT fk_b2b_order_final_actor FOREIGN KEY(finalized_by_employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT chk_b2b_order_status CHECK(status IN ('draft','finalized','cancelled')),
 CONSTRAINT chk_b2b_order_currency CHECK(currency_code IN ('RON','EUR')),
 CONSTRAINT chk_b2b_order_version CHECK(version>0),
 CONSTRAINT chk_b2b_order_final CHECK(
   (finalized_at IS NULL AND finalized_by_employee_uuid IS NULL) OR
   (finalized_at IS NOT NULL AND finalized_by_employee_uuid IS NOT NULL)),
 CONSTRAINT chk_b2b_order_lifecycle CHECK(
   (status='draft' AND finalized_at IS NULL AND cancelled_at IS NULL) OR
   (status='finalized' AND finalized_at IS NOT NULL AND cancelled_at IS NULL) OR
   (status='cancelled' AND cancelled_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_order_lines (
 line_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 line_number SMALLINT UNSIGNED NOT NULL,
 product_code VARCHAR(160) NOT NULL, product_name_snapshot VARCHAR(160) NULL,
 variant_snapshot VARCHAR(160) NULL, color_snapshot VARCHAR(160) NULL,
 item_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 width DECIMAL(8,3) NULL, height DECIMAL(8,3) NULL,
 quantity INT UNSIGNED NOT NULL, meters DECIMAL(8,3) NULL,
 pricing_unit VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 unit_price_net DECIMAL(8,2) NULL, discount_percent DECIMAL(5,2) NOT NULL, vat_percent DECIMAL(5,2) NULL,
 net_total DECIMAL(16,2) NULL, vat_total DECIMAL(16,2) NULL, gross_total DECIMAL(16,2) NULL,
 notes TEXT NULL, production_notes TEXT NULL,
 PRIMARY KEY(line_uuid), UNIQUE KEY uq_b2b_order_line_position(order_uuid,line_number),
 KEY idx_b2b_order_product(product_code,order_uuid),
 CONSTRAINT fk_b2b_order_line_order FOREIGN KEY(order_uuid) REFERENCES b2b_orders(order_uuid),
 CONSTRAINT chk_b2b_order_line_number CHECK(line_number BETWEEN 1 AND 100),
 CONSTRAINT chk_b2b_order_line_kind CHECK(item_type IN ('curtain','drapery','other')),
 CONSTRAINT chk_b2b_order_line_unit CHECK(pricing_unit IN ('piece','meter')),
 CONSTRAINT chk_b2b_order_line_quantity CHECK(quantity BETWEEN 1 AND 99999),
 CONSTRAINT chk_b2b_order_line_inputs CHECK(
   (width IS NULL OR width>0) AND (height IS NULL OR height>0) AND
   (meters IS NULL OR meters>=0) AND (unit_price_net IS NULL OR unit_price_net>=0) AND
   discount_percent BETWEEN 0 AND 100 AND (vat_percent IS NULL OR vat_percent BETWEEN 0 AND 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_order_activity_events (
 event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 line_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, actor_name VARCHAR(160) NOT NULL,
 action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, changed_fields JSON NOT NULL,
 request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 occurred_at DATETIME(6) NOT NULL,
 PRIMARY KEY(event_id), KEY idx_b2b_order_activity_time(order_uuid,occurred_at,event_id),
 CONSTRAINT fk_b2b_order_activity_order FOREIGN KEY(order_uuid) REFERENCES b2b_orders(order_uuid),
 CONSTRAINT fk_b2b_order_activity_actor FOREIGN KEY(actor_employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT chk_b2b_order_activity_action CHECK(action IN (
 'order_created','order_updated','order_finalized','order_cancelled','order_duplicated',
 'line_created','line_updated','line_duplicated','line_removed','lines_reordered'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_order_idempotency (
 employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, request_hash BINARY(32) NOT NULL,
 order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 response_json MEDIUMTEXT NOT NULL, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(employee_uuid,idempotency_key), KEY idx_b2b_order_idempotency_retention(created_at),
 CONSTRAINT fk_b2b_order_idempotency_actor FOREIGN KEY(employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT fk_b2b_order_idempotency_order FOREIGN KEY(order_uuid) REFERENCES b2b_orders(order_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO permissions(permission_key,category,label,description,role_grantable,created_at) VALUES
 ('b2b.orders.view','b2b','Vizualizare comenzi B2B','View commercial orders and historical snapshots',1,UTC_TIMESTAMP(6)),
 ('b2b.orders.create','b2b','Creare comenzi B2B','Create and duplicate commercial drafts',1,UTC_TIMESTAMP(6)),
 ('b2b.orders.update','b2b','Editare comenzi B2B','Edit commercial drafts and lines',1,UTC_TIMESTAMP(6)),
 ('b2b.orders.manage_status','b2b','Gestionare stare comenzi B2B','Finalize and cancel commercial orders without production handoff',1,UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE permission_key=permission_key;
