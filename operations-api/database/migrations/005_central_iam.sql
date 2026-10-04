ALTER TABLE employees
    ADD COLUMN position_title VARCHAR(120) NULL AFTER display_name,
    ADD COLUMN manager_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER position_title,
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_changed_at,
    ADD COLUMN authorization_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER must_change_password,
    ADD KEY idx_employees_manager (manager_employee_uuid),
    ADD CONSTRAINT fk_employees_manager FOREIGN KEY (manager_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT chk_employees_not_own_manager CHECK (manager_employee_uuid IS NULL OR manager_employee_uuid <> employee_uuid),
    ADD CONSTRAINT chk_employees_must_change_password CHECK (must_change_password IN (0, 1)),
    ADD CONSTRAINT chk_employees_authorization_version CHECK (authorization_version > 0);

ALTER TABLE departments
    ADD COLUMN description VARCHAR(255) NULL AFTER name,
    ADD COLUMN parent_department_id BIGINT UNSIGNED NULL AFTER description,
    ADD KEY idx_departments_parent (parent_department_id),
    ADD CONSTRAINT fk_departments_parent FOREIGN KEY (parent_department_id) REFERENCES departments (department_id) ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE roles
    ADD COLUMN description VARCHAR(255) NULL AFTER name,
    ADD COLUMN authority_rank SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER description,
    ADD COLUMN is_template TINYINT(1) NOT NULL DEFAULT 0 AFTER authority_rank,
    ADD CONSTRAINT chk_roles_authority_rank CHECK (authority_rank BETWEEN 1 AND 999),
    ADD CONSTRAINT chk_roles_is_template CHECK (is_template IN (0, 1));

ALTER TABLE permissions
    ADD COLUMN category VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'staff' AFTER permission_key,
    ADD COLUMN label VARCHAR(160) NULL AFTER category,
    ADD COLUMN role_grantable TINYINT(1) NOT NULL DEFAULT 1 AFTER description,
    ADD CONSTRAINT chk_permissions_role_grantable CHECK (role_grantable IN (0, 1));

CREATE TABLE IF NOT EXISTS applications (
    application_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    access_permission_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (application_key),
    UNIQUE KEY uq_applications_access_permission (access_permission_key),
    CONSTRAINT chk_applications_key CHECK (application_key REGEXP '^[a-z][a-z0-9-]{1,39}$'),
    CONSTRAINT chk_applications_status CHECK (status IN ('active', 'inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO applications (application_key, name, description, status, access_permission_key, sort_order, created_at, updated_at) VALUES
    ('staff', 'Staff', 'Aplicația de producție pentru angajați', 'active', 'staff.access', 10, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
    ('dashboard', 'Dashboard', 'Panoul central de administrare', 'active', 'dashboard.access', 20, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));

CREATE TABLE IF NOT EXISTS employee_application_access (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    application_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    granted_at DATETIME(6) NOT NULL,
    PRIMARY KEY (employee_uuid, application_key),
    KEY idx_employee_application_access_application (application_key),
    CONSTRAINT fk_employee_application_access_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_application_access_application FOREIGN KEY (application_key) REFERENCES applications (application_key) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_role_assignments (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME(6) NOT NULL,
    PRIMARY KEY (employee_uuid, role_id),
    KEY idx_employee_role_assignments_role (role_id),
    CONSTRAINT fk_employee_role_assignments_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_role_assignments_role FOREIGN KEY (role_id) REFERENCES roles (role_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The single protected root identity. The primary key admits exactly one row; the unique
-- employee reference and RESTRICT foreign key keep the root employee from being deleted.
CREATE TABLE IF NOT EXISTS system_root_identity (
    singleton_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (singleton_id),
    UNIQUE KEY uq_system_root_identity_employee (employee_uuid),
    CONSTRAINT fk_system_root_identity_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_system_root_identity_singleton CHECK (singleton_id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permanent IAM audit. The application only inserts; maintenance never prunes this table.
CREATE TABLE IF NOT EXISTS iam_audit_events (
    event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    actor_label VARCHAR(200) NOT NULL,
    actor_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_label VARCHAR(200) NOT NULL,
    metadata_json JSON NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_id),
    KEY idx_iam_audit_created (created_at),
    KEY idx_iam_audit_actor_time (actor_employee_uuid, created_at),
    KEY idx_iam_audit_action_time (action, created_at),
    KEY idx_iam_audit_target_time (target_type, target_id, created_at),
    CONSTRAINT fk_iam_audit_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_iam_audit_actor_type CHECK (actor_type IN ('employee', 'root', 'cli'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing identities keep their role and, because they were provisioned for Staff, Staff access only.
INSERT IGNORE INTO employee_role_assignments (employee_uuid, role_id, assigned_at)
SELECT employee_uuid, role_id, UTC_TIMESTAMP(6) FROM employees;

INSERT IGNORE INTO employee_application_access (employee_uuid, application_key, granted_at)
SELECT employee_uuid, 'staff', UTC_TIMESTAMP(6) FROM employees;

-- Server-defined permission catalog. Application access permissions and system.manage are never
-- role-grantable: application access comes only from employee_application_access, system.manage only from root.
INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('staff.access', 'applications', 'Acces Staff', 'Access the Staff application', 0, UTC_TIMESTAMP(6)),
    ('dashboard.access', 'applications', 'Acces Dashboard', 'Access the management dashboard', 0, UTC_TIMESTAMP(6)),
    ('dashboard.overview.view', 'dashboard', 'Vizualizare panou de control', 'View the dashboard overview', 1, UTC_TIMESTAMP(6)),
    ('employees.view', 'employees', 'Vizualizare angajați', 'View employee identities', 1, UTC_TIMESTAMP(6)),
    ('employees.create', 'employees', 'Creare angajați', 'Create employee identities', 1, UTC_TIMESTAMP(6)),
    ('employees.update', 'employees', 'Editare profil angajați', 'Update employee profile, department and title', 1, UTC_TIMESTAMP(6)),
    ('employees.activate', 'employees', 'Activare angajați', 'Activate employee identities', 1, UTC_TIMESTAMP(6)),
    ('employees.deactivate', 'employees', 'Dezactivare angajați', 'Deactivate employee identities', 1, UTC_TIMESTAMP(6)),
    ('employees.manage_applications', 'employees', 'Gestionare acces aplicații', 'Grant or remove application access', 1, UTC_TIMESTAMP(6)),
    ('employees.manage_roles', 'employees', 'Gestionare roluri angajați', 'Assign or remove employee roles', 1, UTC_TIMESTAMP(6)),
    ('employees.manage_stages', 'employees', 'Gestionare etape Staff', 'Assign Staff production stages', 1, UTC_TIMESTAMP(6)),
    ('employees.manage_hierarchy', 'employees', 'Gestionare ierarhie', 'Change direct managers', 1, UTC_TIMESTAMP(6)),
    ('employees.reset_password', 'employees', 'Resetare parolă', 'Issue temporary passwords', 1, UTC_TIMESTAMP(6)),
    ('roles.view', 'roles', 'Vizualizare roluri', 'View roles and permissions', 1, UTC_TIMESTAMP(6)),
    ('roles.create', 'roles', 'Creare roluri', 'Create custom roles', 1, UTC_TIMESTAMP(6)),
    ('roles.update', 'roles', 'Editare roluri', 'Change roles and their permissions', 1, UTC_TIMESTAMP(6)),
    ('roles.delete', 'roles', 'Ștergere roluri', 'Delete unused roles', 1, UTC_TIMESTAMP(6)),
    ('roles.assign', 'roles', 'Atribuire roluri', 'Assign roles within the authority ceiling', 1, UTC_TIMESTAMP(6)),
    ('departments.view', 'departments', 'Vizualizare departamente', 'View departments', 1, UTC_TIMESTAMP(6)),
    ('departments.create', 'departments', 'Creare departamente', 'Create departments', 1, UTC_TIMESTAMP(6)),
    ('departments.update', 'departments', 'Editare departamente', 'Rename, move or (de)activate departments', 1, UTC_TIMESTAMP(6)),
    ('departments.delete', 'departments', 'Ștergere departamente', 'Delete unused departments', 1, UTC_TIMESTAMP(6)),
    ('applications.view', 'applications', 'Vizualizare aplicații', 'View registered applications', 1, UTC_TIMESTAMP(6)),
    ('applications.manage_access', 'applications', 'Administrare registru aplicații', 'Change the application registry (root only)', 0, UTC_TIMESTAMP(6)),
    ('orders.view_all', 'orders', 'Vizualizare toate comenzile', 'View all production orders', 1, UTC_TIMESTAMP(6)),
    ('production.view', 'production', 'Vizualizare producție', 'View production state', 1, UTC_TIMESTAMP(6)),
    ('production.manage_exceptions', 'production', 'Gestionare excepții producție', 'Handle production exceptions', 1, UTC_TIMESTAMP(6)),
    ('activity.view_all', 'activity', 'Vizualizare activitate', 'View all production activity', 1, UTC_TIMESTAMP(6)),
    ('sources.view', 'sources', 'Vizualizare surse', 'View commerce source health', 1, UTC_TIMESTAMP(6)),
    ('system.view', 'system', 'Vizualizare sistem', 'View safe system status', 1, UTC_TIMESTAMP(6)),
    ('system.manage', 'system', 'Administrare sistem', 'System-level management (root only)', 0, UTC_TIMESTAMP(6)),
    ('iam.audit.view', 'system', 'Vizualizare audit IAM', 'View the IAM audit trail', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE category = VALUES(category), label = VALUES(label), description = VALUES(description), role_grantable = VALUES(role_grantable);

UPDATE roles SET description = 'Angajat de producție', authority_rank = 100, is_template = 1 WHERE role_key = 'employee';

-- Role templates are seeded once here; afterwards they are edited only through the management API.
INSERT IGNORE INTO roles (role_key, name, description, authority_rank, is_template, status, created_at, updated_at) VALUES
    ('ceo', 'CEO / Proprietar', 'Conducerea companiei', 900, 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
    ('operations-director', 'Director operațional', 'Conducerea operațiunilor', 700, 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
    ('department-manager', 'Manager departament', 'Conducerea unui departament', 500, 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
    ('supervisor', 'Supervizor', 'Coordonarea unei echipe', 300, 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.role_grantable = 1
WHERE r.role_key = 'ceo';

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.permission_key IN (
    'dashboard.overview.view', 'employees.view', 'employees.create', 'employees.update', 'employees.activate', 'employees.deactivate',
    'employees.manage_applications', 'employees.manage_roles', 'employees.manage_stages', 'employees.manage_hierarchy', 'employees.reset_password',
    'roles.view', 'roles.create', 'roles.update', 'roles.assign', 'departments.view', 'departments.create', 'departments.update',
    'applications.view', 'orders.view_all', 'production.view', 'production.manage_exceptions', 'activity.view_all', 'sources.view',
    'system.view', 'iam.audit.view'
)
WHERE r.role_key = 'operations-director';

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.permission_key IN (
    'dashboard.overview.view', 'employees.view', 'employees.create', 'employees.update', 'employees.activate', 'employees.deactivate',
    'employees.manage_applications', 'employees.manage_roles', 'employees.manage_stages', 'employees.manage_hierarchy', 'employees.reset_password',
    'roles.view', 'roles.assign', 'departments.view', 'applications.view', 'orders.view_all', 'production.view', 'activity.view_all'
)
WHERE r.role_key = 'department-manager';

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.permission_key IN (
    'dashboard.overview.view', 'employees.view', 'employees.manage_stages', 'roles.view', 'departments.view',
    'orders.view_all', 'production.view', 'activity.view_all'
)
WHERE r.role_key = 'supervisor';
