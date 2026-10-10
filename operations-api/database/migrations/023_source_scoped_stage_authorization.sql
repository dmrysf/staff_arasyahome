-- Source-scoped production stage authorization (API 2.26.0).
-- Additive only. No order, stage grant, role assignment, document scope or employee row is created, changed or
-- deleted, and nobody gains or loses access: with no scope row every existing stage grant keeps its current
-- meaning (all order sources).
--
-- A stage grant (employee_stage_access) says WHICH stage an employee may work. A row here narrows ONE of those
-- grants to explicitly named commerce sources: once an (employee, stage) pair has at least one row, the employee
-- works at that stage only on orders of the listed sources. A row never grants a stage by itself (it is ignored
-- unless the stage grant exists), so the effective access is always the intersection, never a union.
--
-- Rows are written only by root through PUT /management/employees/{id}/stage-scopes, which refuses an empty
-- source list (removing the stage grant is the only way to revoke a stage completely), and removing a stage
-- grant removes its scope rows in the same transaction. Every change is recorded in iam_audit_events.
CREATE TABLE IF NOT EXISTS employee_stage_source_scopes (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    granted_at DATETIME(6) NOT NULL,
    granted_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (employee_uuid, stage_id, source_key),
    KEY idx_employee_stage_source_scopes_stage_source (stage_id, source_key),
    KEY idx_employee_stage_source_scopes_source (source_key),
    KEY idx_employee_stage_source_scopes_granted_by (granted_by_employee_uuid),
    CONSTRAINT fk_employee_stage_source_scopes_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_stage_source_scopes_stage FOREIGN KEY (stage_id) REFERENCES production_stages (stage_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_stage_source_scopes_source FOREIGN KEY (source_key) REFERENCES order_sources (source_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_stage_source_scopes_granted_by FOREIGN KEY (granted_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
