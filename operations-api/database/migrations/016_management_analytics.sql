-- Additive analytics. Immutable source events remain authoritative; these two projections are rebuildable.
ALTER TABLE production_exception_policy
 ADD COLUMN approval_grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
 ADD COLUMN analytics_policy_version INT UNSIGNED NOT NULL DEFAULT 1,
 ADD CONSTRAINT chk_analytics_approval_grace CHECK (approval_grace_minutes <= 240);

CREATE TABLE analytics_order_projection (
 order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 source_version INT UNSIGNED NOT NULL,
 formula_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL,
 qr_created_at DATETIME(6) NULL,
 first_claim_at DATETIME(6) NULL,
 completed_at DATETIME(6) NULL,
 cutting_completed_at DATETIME(6) NULL,
 cutting_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 cutting_meters DECIMAL(15,3) NULL,
 cutting_lines INT UNSIGNED NULL,
 source_key VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 KEY idx_analytics_created (created_at, order_uuid),
 KEY idx_analytics_delivery (completed_at, order_uuid),
 KEY idx_analytics_cutting (cutting_completed_at, cutting_employee_uuid, order_uuid),
 KEY idx_analytics_company (company_uuid, created_at, order_uuid),
 CONSTRAINT fk_analytics_projection_order FOREIGN KEY (order_uuid) REFERENCES operational_orders(order_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE analytics_ownership_intervals (
 order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 start_version INT UNSIGNED NOT NULL,
 employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 started_at DATETIME(6) NOT NULL,
 ended_at DATETIME(6) NULL,
 PRIMARY KEY (order_uuid, start_version),
 KEY idx_analytics_interval_end (ended_at, started_at, order_uuid),
 KEY idx_analytics_interval_employee (employee_uuid, stage_id, started_at, order_uuid),
 CONSTRAINT fk_analytics_interval_order FOREIGN KEY (order_uuid) REFERENCES operational_orders(order_uuid) ON DELETE CASCADE,
 CONSTRAINT chk_analytics_interval_dates CHECK (ended_at IS NULL OR ended_at >= started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New immutable observation, recorded only at request opening. No guessed historical recipients.
CREATE TABLE analytics_approval_requests (
 request_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 opened_at DATETIME(6) NOT NULL,
 PRIMARY KEY (request_type, request_uuid),
 KEY idx_analytics_request_period (opened_at, request_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE analytics_approval_eligibility (
 request_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 eligible_via VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 eligible_at DATETIME(6) NOT NULL,
 PRIMARY KEY (request_type, request_uuid, employee_uuid),
 KEY idx_analytics_eligible_employee (employee_uuid, eligible_at),
 KEY idx_analytics_eligible_date (eligible_at, employee_uuid),
 CONSTRAINT chk_analytics_eligible_type CHECK (request_type IN ('exception','transfer'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_analytics_quality_period ON production_quality_events (occurred_at, employee_uuid, event_type);
CREATE INDEX idx_analytics_decisions_period ON production_exception_decisions (decided_at, decided_by_employee_uuid);
CREATE INDEX idx_analytics_decisions_opened ON production_exception_decisions (opened_at, decision_uuid);
CREATE INDEX idx_analytics_transfer_period ON cutting_transfers (decided_at, decided_by_employee_uuid);
CREATE INDEX idx_analytics_transfer_requested ON cutting_transfers (requested_at, transfer_uuid);
-- Cover the bounded canonical work-proof and period activity reads without fetching full event payloads.
CREATE INDEX idx_analytics_activity_work ON order_activity_events (order_uuid, action, occurred_at);
CREATE INDEX idx_analytics_activity_period ON order_activity_events (occurred_at, action, employee_uuid, order_uuid, from_stage_id);

INSERT INTO permissions (permission_key,category,label,description,role_grantable,created_at) VALUES
 ('analytics.view','analytics','Analiză managerială','Read explainable production analytics, without production or IAM authority',1,UTC_TIMESTAMP(6));
INSERT INTO roles (role_key,name,description,authority_rank,is_template,status,created_at,updated_at) VALUES
 ('analytics-reader','Analiză managerială','Analiză în citire, fără administrare și fără operațiuni de producție',450,1,'active',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6));
INSERT INTO role_permissions (role_id,permission_id,created_at)
 SELECT r.role_id,p.permission_id,UTC_TIMESTAMP(6) FROM roles r JOIN permissions p ON p.permission_key='analytics.view' WHERE r.role_key='analytics-reader';
-- No employee assignments; grant the intended management identity through central IAM after review.
