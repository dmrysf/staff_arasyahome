-- Idempotent IAM reference metadata. Role templates and their permissions are seeded once by
-- migration 005 and are never re-imposed here, so administrator changes survive re-seeding.
UPDATE permissions SET category = 'staff', label = 'Scanare comenzi' WHERE permission_key = 'orders.scan';
UPDATE permissions SET category = 'staff', label = 'Comenzile mele' WHERE permission_key = 'orders.view_mine';
UPDATE permissions SET category = 'staff', label = 'Preluare comenzi' WHERE permission_key = 'orders.claim';
UPDATE permissions SET category = 'staff', label = 'Finalizare etapă' WHERE permission_key = 'orders.advance_stage';
UPDATE permissions SET category = 'staff', label = 'Predare comandă' WHERE permission_key = 'orders.handover';
UPDATE permissions SET category = 'staff', label = 'Istoricul meu' WHERE permission_key = 'history.view_mine';
UPDATE permissions SET category = 'staff', label = 'Profil propriu' WHERE permission_key = 'profile.view_self';

UPDATE applications SET name = 'Staff', description = 'Aplicația de producție pentru angajați' WHERE application_key = 'staff';
UPDATE applications SET name = 'Dashboard', description = 'Panoul central de administrare' WHERE application_key = 'dashboard';

INSERT INTO departments (department_key, name, description, status, created_at, updated_at)
VALUES ('conducere', 'Conducere', 'Conducerea companiei', 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE department_key = department_key;

-- On a fresh database the baseline role is created by seed 001 after migration 005 has run.
UPDATE roles SET is_template = 1, description = COALESCE(description, 'Angajat de producție') WHERE role_key = 'employee';
