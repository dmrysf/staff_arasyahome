-- Production authority control plane (API 2.18.0).
-- Additive only. No existing order, activity event, idempotency row or permission grant is rewritten,
-- and no order changes authority because of this migration: operational_orders.production_authority
-- keeps its existing values ('source' = production still managed by the commerce source, 'operations' =
-- managed in Arasya). Authority moves only through an explicit, audited operator command or, for a
-- source whose authority mode is `enforce`, for genuinely new orders created after that switch.

-- Immutable authority evidence. One row per authority decision: an explicit takeover or release by a
-- manager, the new-order policy of an enforcing source, and a Staff claim of a source-managed order
-- observed while the source is in `observe` mode. Never holds customer data or payload bodies.
CREATE TABLE IF NOT EXISTS production_authority_events (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    authority_mode VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    previous_authority VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    new_authority VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    previous_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    new_stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    production_version_before INT UNSIGNED NULL,
    production_version_after INT UNSIGNED NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reason_code VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_uuid),
    KEY idx_production_authority_events_order (order_uuid, occurred_at),
    KEY idx_production_authority_events_source_time (source_key, occurred_at),
    CONSTRAINT fk_production_authority_events_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_production_authority_events_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_authority_events_action CHECK (action IN ('takeover', 'release', 'new_order_policy', 'claim_observed')),
    CONSTRAINT chk_production_authority_events_mode CHECK (authority_mode IN ('legacy', 'observe', 'enforce')),
    CONSTRAINT chk_production_authority_events_previous CHECK (previous_authority IS NULL OR previous_authority IN ('source', 'operations')),
    CONSTRAINT chk_production_authority_events_new CHECK (new_authority IN ('source', 'operations')),
    CONSTRAINT chk_production_authority_events_actor CHECK ((action IN ('takeover', 'release', 'claim_observed') AND actor_employee_uuid IS NOT NULL) OR (action = 'new_order_policy' AND actor_employee_uuid IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The takeover and its restricted release appear in the immutable production timeline; every action
-- allowed by 007, 012 and 014 stays allowed. Existing rows are untouched.
ALTER TABLE order_activity_events DROP CONSTRAINT chk_order_activity_action;

ALTER TABLE order_activity_events
    ADD CONSTRAINT chk_order_activity_action CHECK (action IN ('claimed', 'stage_completed', 'production_completed', 'owner_released', 'owner_reassigned', 'production_submitted',
        'fault_reported', 'fault_rejected', 'fault_rereview_requested', 'fault_returned', 'fault_cancelled', 'authority_taken_over', 'authority_released'));

ALTER TABLE order_operation_idempotency DROP CONSTRAINT chk_order_operation_idempotency_operation;

ALTER TABLE order_operation_idempotency
    ADD CONSTRAINT chk_order_operation_idempotency_operation CHECK (operation IN ('claim', 'transition', 'release_owner', 'reassign_owner', 'authority_takeover', 'authority_release'));

-- Taking production authority over from a source is its own capability: it never covers ownership or
-- ordinary stage work. Only the two highest templates receive it; root holds every permission.
INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('production.manage_authority', 'production', 'Preluare autoritate producție', 'Take production authority over from a commerce source with an explicitly selected canonical stage, or release an untouched takeover', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE category = VALUES(category), label = VALUES(label), description = VALUES(description), role_grantable = VALUES(role_grantable);

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.permission_key = 'production.manage_authority'
WHERE r.role_key IN ('ceo', 'operations-director');
