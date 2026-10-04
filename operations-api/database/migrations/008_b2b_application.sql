-- Arasya B2B Foundation: register B2B as a Central IAM application.
-- Data only and additive: no schema change and no B2B business tables. Existing applications, permissions,
-- roles and application grants are not modified, and nobody except root receives B2B access by this migration
-- (root holds every active application by definition). Safe to run more than once.
INSERT INTO applications (application_key, name, description, status, access_permission_key, sort_order, created_at, updated_at) VALUES
    ('b2b', 'B2B', 'Management vânzări en-gros', 'active', 'b2b.access', 30, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE application_key = application_key;

-- Like staff.access and dashboard.access, b2b.access comes only from employee_application_access and is never
-- role-grantable.
INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('b2b.access', 'applications', 'Acces B2B', 'Access the B2B wholesale application', 0, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE permission_key = permission_key;
