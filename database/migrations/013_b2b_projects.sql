-- Additive project workspace (Visual Project Builder core). Old code ignores these tables; retain them on rollback.
-- A project is mutable planning data. Commercial truth stays in b2b_orders, financial truth in the current account
-- ledger and manufacturing truth in operational_orders. Nothing here cascades into or out of those records.
CREATE TABLE IF NOT EXISTS b2b_project_number_sequence (
 project_number BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(project_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_projects (
 project_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 project_number BIGINT UNSIGNED NOT NULL, project_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(160) NOT NULL,
 property_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
 currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'RON',
 site_address VARCHAR(300) NULL, customer_reference VARCHAR(160) NULL, notes TEXT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1, revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, archived_at DATETIME(6) NULL,
 created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 updated_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by_name VARCHAR(160) NOT NULL, updated_by_name VARCHAR(160) NOT NULL,
 PRIMARY KEY(project_uuid), UNIQUE KEY uq_b2b_project_number(project_number), UNIQUE KEY uq_b2b_project_code(project_code),
 KEY idx_b2b_project_company_time(company_uuid,updated_at,project_uuid),
 KEY idx_b2b_project_status_time(status,updated_at,project_uuid),
 KEY idx_b2b_project_time(updated_at,project_uuid),
 CONSTRAINT fk_b2b_project_number FOREIGN KEY(project_number) REFERENCES b2b_project_number_sequence(project_number),
 CONSTRAINT fk_b2b_project_company FOREIGN KEY(company_uuid) REFERENCES b2b_companies(company_uuid),
 CONSTRAINT fk_b2b_project_created_actor FOREIGN KEY(created_by_employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT fk_b2b_project_updated_actor FOREIGN KEY(updated_by_employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT chk_b2b_project_status CHECK(status IN ('draft','active','archived')),
 CONSTRAINT chk_b2b_project_type CHECK(property_type IN ('apartment','house','hotel','hospital','restaurant','office','commercial','other')),
 CONSTRAINT chk_b2b_project_currency CHECK(currency_code IN ('RON','EUR')),
 CONSTRAINT chk_b2b_project_versions CHECK(version>0 AND revision>0),
 CONSTRAINT chk_b2b_project_archive CHECK((status='archived')=(archived_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_project_zones (
 zone_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 project_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 position INT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL,
 zone_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'floor',
 level_number SMALLINT NULL, building VARCHAR(80) NULL, notes TEXT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 copied_from_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY(zone_uuid), KEY idx_b2b_project_zone_position(project_uuid,position),
 CONSTRAINT fk_b2b_project_zone_project FOREIGN KEY(project_uuid) REFERENCES b2b_projects(project_uuid),
 CONSTRAINT chk_b2b_project_zone_type CHECK(zone_type IN ('floor','zone')),
 CONSTRAINT chk_b2b_project_zone_version CHECK(version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_project_rooms (
 room_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 project_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 zone_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 position INT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL,
 width_cm DECIMAL(8,3) NULL, length_cm DECIMAL(8,3) NULL, ceiling_height_cm DECIMAL(8,3) NULL, notes TEXT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 copied_from_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY(room_uuid), KEY idx_b2b_project_room_position(project_uuid,zone_uuid,position), KEY idx_b2b_project_room_zone(zone_uuid),
 CONSTRAINT fk_b2b_project_room_project FOREIGN KEY(project_uuid) REFERENCES b2b_projects(project_uuid),
 CONSTRAINT fk_b2b_project_room_zone FOREIGN KEY(zone_uuid) REFERENCES b2b_project_zones(zone_uuid),
 CONSTRAINT chk_b2b_project_room_measures CHECK((width_cm IS NULL OR width_cm>0) AND (length_cm IS NULL OR length_cm>0) AND (ceiling_height_cm IS NULL OR ceiling_height_cm>0)),
 CONSTRAINT chk_b2b_project_room_version CHECK(version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_project_openings (
 opening_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 project_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 room_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 position INT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL,
 opening_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'window',
 wall_index TINYINT UNSIGNED NULL,
 width DECIMAL(8,3) NULL, height DECIMAL(8,3) NULL, sill_height DECIMAL(8,3) NULL, offset_left DECIMAL(8,3) NULL, wall_width DECIMAL(8,3) NULL,
 mounting VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL, rail_type VARCHAR(120) NULL, notes TEXT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 copied_from_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY(opening_uuid), KEY idx_b2b_project_opening_position(project_uuid,room_uuid,position), KEY idx_b2b_project_opening_room(room_uuid),
 CONSTRAINT fk_b2b_project_opening_project FOREIGN KEY(project_uuid) REFERENCES b2b_projects(project_uuid),
 CONSTRAINT fk_b2b_project_opening_room FOREIGN KEY(room_uuid) REFERENCES b2b_project_rooms(room_uuid),
 CONSTRAINT chk_b2b_project_opening_type CHECK(opening_type IN ('window','door','balcony_door','wall','other')),
 CONSTRAINT chk_b2b_project_opening_mounting CHECK(mounting IS NULL OR mounting IN ('ceiling','wall','recess')),
 CONSTRAINT chk_b2b_project_opening_wall CHECK(wall_index IS NULL OR wall_index BETWEEN 1 AND 12),
 CONSTRAINT chk_b2b_project_opening_measures CHECK((width IS NULL OR width>0) AND (height IS NULL OR height>0) AND (wall_width IS NULL OR wall_width>0)
   AND (sill_height IS NULL OR sill_height>=0) AND (offset_left IS NULL OR offset_left>=0)),
 CONSTRAINT chk_b2b_project_opening_version CHECK(version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Treatment commercial fields use exactly the Classic order line language (same names, scales and checks).
CREATE TABLE IF NOT EXISTS b2b_project_treatments (
 treatment_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 project_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 opening_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 position INT UNSIGNED NOT NULL,
 treatment_type VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 panel_layout VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
 product_code VARCHAR(160) NOT NULL DEFAULT '', product_name_snapshot VARCHAR(160) NULL,
 variant_snapshot VARCHAR(160) NULL, color_snapshot VARCHAR(160) NULL,
 width DECIMAL(8,3) NULL, height DECIMAL(8,3) NULL,
 quantity INT UNSIGNED NOT NULL DEFAULT 1, meters DECIMAL(8,3) NULL,
 pricing_unit VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'piece',
 unit_price_net DECIMAL(8,2) NULL, discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0, vat_percent DECIMAL(5,2) NULL,
 notes TEXT NULL, production_notes TEXT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 copied_from_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY(treatment_uuid), KEY idx_b2b_project_treatment_position(project_uuid,opening_uuid,position), KEY idx_b2b_project_treatment_opening(opening_uuid),
 CONSTRAINT fk_b2b_project_treatment_project FOREIGN KEY(project_uuid) REFERENCES b2b_projects(project_uuid),
 CONSTRAINT fk_b2b_project_treatment_opening FOREIGN KEY(opening_uuid) REFERENCES b2b_project_openings(opening_uuid),
 CONSTRAINT chk_b2b_project_treatment_type CHECK(treatment_type IN ('sheer','drapery','blackout','rail','accessory','other')),
 CONSTRAINT chk_b2b_project_treatment_layout CHECK(panel_layout IS NULL OR panel_layout IN ('single','pair','left','right')),
 CONSTRAINT chk_b2b_project_treatment_unit CHECK(pricing_unit IN ('piece','meter')),
 CONSTRAINT chk_b2b_project_treatment_quantity CHECK(quantity BETWEEN 1 AND 99999),
 CONSTRAINT chk_b2b_project_treatment_inputs CHECK(
   (width IS NULL OR width>0) AND (height IS NULL OR height>0) AND
   (meters IS NULL OR meters>=0) AND (unit_price_net IS NULL OR unit_price_net>=0) AND
   discount_percent BETWEEN 0 AND 100 AND (vat_percent IS NULL OR vat_percent BETWEEN 0 AND 100)),
 CONSTRAINT chk_b2b_project_treatment_version CHECK(version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- One project may create many commercial orders over time; one order comes from at most one project.
CREATE TABLE IF NOT EXISTS b2b_project_orders (
 order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 project_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 project_revision INT UNSIGNED NOT NULL,
 created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, created_by_name VARCHAR(160) NOT NULL,
 created_at DATETIME(6) NOT NULL, request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(order_uuid), KEY idx_b2b_project_orders_project(project_uuid,created_at,order_uuid),
 CONSTRAINT fk_b2b_project_orders_order FOREIGN KEY(order_uuid) REFERENCES b2b_orders(order_uuid),
 CONSTRAINT fk_b2b_project_orders_project FOREIGN KEY(project_uuid) REFERENCES b2b_projects(project_uuid),
 CONSTRAINT fk_b2b_project_orders_actor FOREIGN KEY(created_by_employee_uuid) REFERENCES employees(employee_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Immutable trace of each converted line. Workspace node UUIDs are plain references (nodes may later be edited or
-- removed); the frozen context keeps the labels and measurements as they were at conversion time.
CREATE TABLE IF NOT EXISTS b2b_project_order_lines (
 line_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 project_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 zone_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 room_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 opening_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 treatment_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 trace_context JSON NOT NULL,
 PRIMARY KEY(line_uuid), UNIQUE KEY uq_b2b_project_order_treatment(order_uuid,treatment_uuid),
 KEY idx_b2b_project_order_lines_treatment(project_uuid,treatment_uuid),
 CONSTRAINT fk_b2b_project_order_lines_order FOREIGN KEY(order_uuid) REFERENCES b2b_project_orders(order_uuid),
 CONSTRAINT fk_b2b_project_order_lines_project FOREIGN KEY(project_uuid) REFERENCES b2b_projects(project_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_project_activity_events (
 event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 project_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 subject_type VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 subject_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, actor_name VARCHAR(160) NOT NULL,
 action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, details JSON NOT NULL,
 request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 occurred_at DATETIME(6) NOT NULL,
 PRIMARY KEY(event_id), KEY idx_b2b_project_activity_time(project_uuid,occurred_at,event_id),
 CONSTRAINT fk_b2b_project_activity_project FOREIGN KEY(project_uuid) REFERENCES b2b_projects(project_uuid),
 CONSTRAINT fk_b2b_project_activity_actor FOREIGN KEY(actor_employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT chk_b2b_project_activity_subject CHECK(subject_type IN ('project','zone','room','opening','treatment','order')),
 CONSTRAINT chk_b2b_project_activity_action CHECK(action IN (
 'project_created','project_updated','project_status_changed',
 'zone_created','zone_updated','zone_removed',
 'room_created','room_updated','room_moved','room_removed','room_duplicated','room_configuration_applied',
 'opening_created','opening_updated','opening_removed','opening_duplicated',
 'treatment_created','treatment_updated','treatment_removed','treatment_duplicated','treatment_set_copied',
 'children_reordered','order_created'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_project_idempotency (
 employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, request_hash BINARY(32) NOT NULL,
 project_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 response_json MEDIUMTEXT NOT NULL, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(employee_uuid,idempotency_key), KEY idx_b2b_project_idempotency_retention(created_at),
 CONSTRAINT fk_b2b_project_idempotency_actor FOREIGN KEY(employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT fk_b2b_project_idempotency_project FOREIGN KEY(project_uuid) REFERENCES b2b_projects(project_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO permissions(permission_key,category,label,description,role_grantable,created_at) VALUES
 ('b2b.projects.view','b2b','Vizualizare proiecte B2B','View project workspaces, their structure, commercial projection and proposal PDF',1,UTC_TIMESTAMP(6)),
 ('b2b.projects.create','b2b','Creare proiecte B2B','Create project workspaces for active companies',1,UTC_TIMESTAMP(6)),
 ('b2b.projects.update','b2b','Editare proiecte B2B','Edit project structure, measurements, treatments and repetitions',1,UTC_TIMESTAMP(6)),
 ('b2b.projects.archive','b2b','Arhivare proiecte B2B','Archive and reactivate project workspaces',1,UTC_TIMESTAMP(6)),
 ('b2b.projects.convert','b2b','Comandă din proiect B2B','Create Classic commercial drafts from a selected project scope',1,UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE permission_key=permission_key;
