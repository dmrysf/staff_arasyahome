-- Production Control V2: supervisor ownership interventions.
-- Additive only. Existing activity rows, idempotency rows and permissions are not modified.
--
-- order_activity_events.employee_uuid stays the actor. Owner interventions additionally record
-- whose ownership ended and who received it, so the immutable timeline can show "A -> B".
ALTER TABLE order_activity_events
    ADD COLUMN previous_owner_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER to_stage_label_snapshot,
    ADD COLUMN new_owner_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER previous_owner_employee_uuid,
    ADD CONSTRAINT fk_order_activity_previous_owner FOREIGN KEY (previous_owner_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_order_activity_new_owner FOREIGN KEY (new_owner_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT;

-- DROP CONSTRAINT works for CHECK constraints on both MySQL 8.0.19+ and MariaDB 10.2+.
ALTER TABLE order_activity_events DROP CONSTRAINT chk_order_activity_action;

ALTER TABLE order_activity_events
    ADD CONSTRAINT chk_order_activity_action CHECK (action IN ('claimed', 'stage_completed', 'production_completed', 'owner_released', 'owner_reassigned'));

ALTER TABLE order_operation_idempotency DROP CONSTRAINT chk_order_operation_idempotency_operation;

ALTER TABLE order_operation_idempotency
    ADD CONSTRAINT chk_order_operation_idempotency_operation CHECK (operation IN ('claim', 'transition', 'release_owner', 'reassign_owner'));

-- Intervening in production ownership is a separate capability from viewing production. It never
-- covers stage changes. Only the two highest templates receive it; root holds every permission.
INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('production.manage_owner', 'production', 'Intervenție responsabil producție', 'Release or reassign the current production owner of an order (never its stage)', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE category = VALUES(category), label = VALUES(label), description = VALUES(description), role_grantable = VALUES(role_grantable);

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.permission_key = 'production.manage_owner'
WHERE r.role_key IN ('ceo', 'operations-director');
