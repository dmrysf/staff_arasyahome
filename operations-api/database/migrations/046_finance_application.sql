-- Finance application V0.2 (DRAFT ONLY; NOT RUN IN PRODUCTION).
-- Additive Central IAM registration, without any employee access grant or role assignment.
-- Existing Staff, B2B and Dashboard application records and permissions are unchanged.
-- Finance's read endpoints will deny all ordinary employees until finance.access is granted by
-- an explicit, separately authorized IAM action. Root follows existing system-root semantics.

INSERT INTO applications
  (application_key, name, description, status, access_permission_key, sort_order, created_at, updated_at)
VALUES
  ('finance', 'Finance', 'Centrul de control financiar Arasya', 'active', 'finance.access', 40, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE application_key=application_key;

INSERT INTO permissions
  (permission_key, category, label, description, role_grantable, created_at)
VALUES
  ('finance.access','applications','Acces Finance','Enter the Finance application',0,UTC_TIMESTAMP(6)),
  ('finance.accounts.view','finance','Vizualizare conturi B2B','View existing B2B company current-account balances in Finance',1,UTC_TIMESTAMP(6)),
  ('finance.companies.view','finance','Vizualizare companii','View minimal B2B company identities in Finance',1,UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE permission_key=permission_key;

-- Intentionally NO INSERT INTO employee_application_access, role_permissions or employee_role_assignments.
