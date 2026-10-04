-- Inserts the baseline department and role only when missing; never overrides Dashboard changes.
INSERT INTO departments (department_key, name, status, created_at, updated_at)
VALUES ('pregatire-material', 'Pregătire Material', 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE department_key = department_key;

INSERT INTO roles (role_key, name, status, created_at, updated_at)
VALUES ('employee', 'Angajat', 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE role_key = role_key;

INSERT INTO permissions (permission_key, description, created_at) VALUES
    ('orders.scan', 'Scan production order codes', UTC_TIMESTAMP(6)),
    ('orders.view_mine', 'View orders directly related to the employee', UTC_TIMESTAMP(6)),
    ('orders.claim', 'Claim an eligible order', UTC_TIMESTAMP(6)),
    ('orders.advance_stage', 'Advance an owned order stage', UTC_TIMESTAMP(6)),
    ('orders.handover', 'Hand an owned order to the next stage', UTC_TIMESTAMP(6)),
    ('history.view_mine', 'View personal operations history', UTC_TIMESTAMP(6)),
    ('profile.view_self', 'View own employee identity', UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.permission_key IN (
    'orders.scan',
    'orders.view_mine',
    'orders.claim',
    'orders.advance_stage',
    'orders.handover',
    'history.view_mine',
    'profile.view_self'
)
WHERE r.role_key = 'employee';

