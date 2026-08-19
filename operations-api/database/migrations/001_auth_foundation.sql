CREATE TABLE IF NOT EXISTS departments (
    department_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (department_id),
    UNIQUE KEY uq_departments_key (department_key),
    CONSTRAINT chk_departments_status CHECK (status IN ('active', 'inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    role_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (role_id),
    UNIQUE KEY uq_roles_key (role_key),
    CONSTRAINT chk_roles_status CHECK (status IN ('active', 'inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    permission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    permission_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (permission_id),
    UNIQUE KEY uq_permissions_key (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (role_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (permission_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employees (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    username VARCHAR(120) NOT NULL,
    username_normalized VARCHAR(120) COLLATE utf8mb4_bin NOT NULL,
    password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    password_changed_at DATETIME(6) NOT NULL,
    last_login_at DATETIME(6) NULL,
    PRIMARY KEY (employee_uuid),
    UNIQUE KEY uq_employees_username_normalized (username_normalized),
    UNIQUE KEY uq_employees_employee_code (employee_code),
    KEY idx_employees_department (department_id),
    KEY idx_employees_role (role_id),
    KEY idx_employees_status (status),
    CONSTRAINT fk_employees_department FOREIGN KEY (department_id) REFERENCES departments (department_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employees_role FOREIGN KEY (role_id) REFERENCES roles (role_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_employees_status CHECK (status IN ('active', 'inactive', 'suspended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_stage_access (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (employee_uuid, stage_id),
    KEY idx_employee_stage_stage (stage_id),
    CONSTRAINT fk_employee_stage_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_sessions (
    session_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hash BINARY(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    ip_hash BINARY(32) NOT NULL,
    user_agent_hash BINARY(32) NOT NULL,
    PRIMARY KEY (session_id),
    UNIQUE KEY uq_auth_sessions_token_hash (token_hash),
    KEY idx_auth_sessions_employee (employee_uuid),
    KEY idx_auth_sessions_expires (expires_at),
    KEY idx_auth_sessions_employee_active (employee_uuid, revoked_at, expires_at),
    CONSTRAINT fk_auth_sessions_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_login_attempts (
    attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username_hash BINARY(32) NOT NULL,
    ip_hash BINARY(32) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME(6) NOT NULL,
    PRIMARY KEY (attempt_id),
    KEY idx_login_attempts_username_time (username_hash, succeeded, attempted_at),
    KEY idx_login_attempts_ip_time (ip_hash, succeeded, attempted_at),
    KEY idx_login_attempts_retention (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_rate_limit_buckets (
    dimension_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dimension_hash BINARY(32) NOT NULL,
    bucket_started_at DATETIME(6) NOT NULL,
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (dimension_type, dimension_hash),
    KEY idx_rate_limit_retention (updated_at),
    CONSTRAINT chk_rate_limit_dimension CHECK (dimension_type IN ('username', 'ip'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_audit_events (
    event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    username_hash BINARY(32) NULL,
    event_type VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    ip_hash BINARY(32) NOT NULL,
    user_agent_hash BINARY(32) NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    metadata_json JSON NULL,
    PRIMARY KEY (event_id),
    KEY idx_auth_audit_employee_time (employee_uuid, created_at),
    KEY idx_auth_audit_event_time (event_type, created_at),
    KEY idx_auth_audit_retention (created_at),
    KEY idx_auth_audit_request (request_id),
    CONSTRAINT fk_auth_audit_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
