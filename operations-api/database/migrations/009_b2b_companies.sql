-- Arasya B2B Companies V1: the wholesale company domain.
-- Additive only: new b2b_* tables and four new catalog permissions. No existing table, row, permission, role or
-- grant is modified, and no role receives the new permissions; administrators compose B2B roles in the Dashboard.
-- An API rolled back to 2.7.0 keeps working on this schema because nothing it reads changes.
--
-- No orders, balances, payments, prices or inventory references live here. Future modules reference
-- b2b_companies.company_uuid and snapshot the commercial fields they need at their own time.

-- Concurrency-safe source of company numbers. Each company takes one AUTO_INCREMENT value inside its own
-- transaction; a rolled-back creation leaves a gap, never a duplicate. Codes are B2B-000001 and up.
CREATE TABLE IF NOT EXISTS b2b_company_number_sequence (
    company_number BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (company_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS b2b_companies (
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_number BIGINT UNSIGNED NOT NULL,
    company_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    legal_name VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NULL,
    country_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tax_identifier VARCHAR(64) NOT NULL,
    tax_identifier_normalized VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    vat_number VARCHAR(64) NULL,
    registration_number VARCHAR(64) NULL,
    website VARCHAR(255) NULL,
    internal_notes TEXT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    status_changed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (company_uuid),
    UNIQUE KEY uq_b2b_companies_number (company_number),
    UNIQUE KEY uq_b2b_companies_code (company_code),
    -- One company per fiscal identity within a country. Names are deliberately not unique.
    UNIQUE KEY uq_b2b_companies_tax_identifier (country_code, tax_identifier_normalized),
    KEY idx_b2b_companies_status_name (status, legal_name, company_uuid),
    KEY idx_b2b_companies_name (legal_name, company_uuid),
    KEY idx_b2b_companies_country_status (country_code, status),
    KEY idx_b2b_companies_created_by (created_by_employee_uuid),
    KEY idx_b2b_companies_updated_by (updated_by_employee_uuid),
    CONSTRAINT fk_b2b_companies_number FOREIGN KEY (company_number) REFERENCES b2b_company_number_sequence (company_number) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_b2b_companies_created_by FOREIGN KEY (created_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_b2b_companies_updated_by FOREIGN KEY (updated_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_b2b_companies_status CHECK (status IN ('active', 'inactive')),
    CONSTRAINT chk_b2b_companies_country CHECK (country_code REGEXP '^[A-Z]{2}$'),
    CONSTRAINT chk_b2b_companies_tax_normalized CHECK (tax_identifier_normalized REGEXP '^[A-Z0-9]{1,64}$'),
    CONSTRAINT chk_b2b_companies_version CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Several contacts per company. At most one active primary contact: primary_company_uuid is set (to the company)
-- only on the primary row and is unique, so even two racing requests cannot both commit a primary. The CHECK keeps
-- it consistent with is_primary (IS NOT NULL matters: a CHECK that evaluates to NULL passes), and a deactivated
-- contact is never primary. (An explicit column rather than a
-- generated one: MariaDB 10.11 refuses string expressions in generated columns.)
CREATE TABLE IF NOT EXISTS b2b_company_contacts (
    contact_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    job_title VARCHAR(120) NULL,
    email VARCHAR(254) NULL,
    phone VARCHAR(40) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    primary_company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (contact_uuid),
    UNIQUE KEY uq_b2b_company_contacts_primary (primary_company_uuid),
    KEY idx_b2b_company_contacts_company (company_uuid, status, full_name),
    KEY idx_b2b_company_contacts_created_by (created_by_employee_uuid),
    KEY idx_b2b_company_contacts_updated_by (updated_by_employee_uuid),
    CONSTRAINT fk_b2b_company_contacts_company FOREIGN KEY (company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_b2b_company_contacts_created_by FOREIGN KEY (created_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_b2b_company_contacts_updated_by FOREIGN KEY (updated_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_b2b_company_contacts_status CHECK (status IN ('active', 'inactive')),
    CONSTRAINT chk_b2b_company_contacts_primary CHECK (
        (is_primary = 0 AND primary_company_uuid IS NULL)
        OR (is_primary = 1 AND status = 'active' AND primary_company_uuid IS NOT NULL AND primary_company_uuid = company_uuid)
    ),
    CONSTRAINT chk_b2b_company_contacts_version CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Several addresses per company, typed. At most one active primary address per company and type (for example
-- one primary billing and one primary delivery address), enforced the same way as primary contacts through the
-- unique (primary_company_uuid, primary_address_type) pair.
CREATE TABLE IF NOT EXISTS b2b_company_addresses (
    address_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    address_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    label VARCHAR(120) NULL,
    country_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    county_region VARCHAR(120) NULL,
    city VARCHAR(120) NOT NULL,
    postal_code VARCHAR(20) NULL,
    address_line_1 VARCHAR(255) NOT NULL,
    address_line_2 VARCHAR(255) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    primary_company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    primary_address_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (address_uuid),
    UNIQUE KEY uq_b2b_company_addresses_primary (primary_company_uuid, primary_address_type),
    KEY idx_b2b_company_addresses_company (company_uuid, status, address_type),
    KEY idx_b2b_company_addresses_city (city, status, company_uuid),
    KEY idx_b2b_company_addresses_created_by (created_by_employee_uuid),
    KEY idx_b2b_company_addresses_updated_by (updated_by_employee_uuid),
    CONSTRAINT fk_b2b_company_addresses_company FOREIGN KEY (company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_b2b_company_addresses_created_by FOREIGN KEY (created_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_b2b_company_addresses_updated_by FOREIGN KEY (updated_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_b2b_company_addresses_type CHECK (address_type IN ('billing', 'delivery', 'office', 'other')),
    CONSTRAINT chk_b2b_company_addresses_country CHECK (country_code REGEXP '^[A-Z]{2}$'),
    CONSTRAINT chk_b2b_company_addresses_status CHECK (status IN ('active', 'inactive')),
    CONSTRAINT chk_b2b_company_addresses_primary CHECK (
        (is_primary = 0 AND primary_company_uuid IS NULL AND primary_address_type IS NULL)
        OR (is_primary = 1 AND status = 'active' AND primary_company_uuid IS NOT NULL AND primary_address_type IS NOT NULL
            AND primary_company_uuid = company_uuid AND primary_address_type = address_type)
    ),
    CONSTRAINT chk_b2b_company_addresses_version CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable B2B business history; the application only inserts. It is separate from the IAM audit, which stays
-- about identity and security. changed_fields holds field names only, never values, so no contact data,
-- address or internal note is copied here.
CREATE TABLE IF NOT EXISTS b2b_company_activity_events (
    event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    changed_fields JSON NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_id),
    KEY idx_b2b_company_activity_company_time (company_uuid, occurred_at, event_id),
    KEY idx_b2b_company_activity_actor_time (actor_employee_uuid, occurred_at),
    CONSTRAINT fk_b2b_company_activity_company FOREIGN KEY (company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_b2b_company_activity_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_b2b_company_activity_action CHECK (action IN (
        'company_created', 'company_updated', 'company_deactivated', 'company_reactivated',
        'contact_created', 'contact_updated', 'contact_deactivated', 'contact_reactivated',
        'address_created', 'address_updated', 'address_deactivated', 'address_reactivated'
    )),
    CONSTRAINT chk_b2b_company_activity_subject CHECK (subject_type IN ('company', 'contact', 'address'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Replay store for B2B company mutations (per actor and Idempotency-Key). The production idempotency table is
-- bound to production orders and operations, so B2B keeps its own. Pruned by bin/maintenance.php.
CREATE TABLE IF NOT EXISTS b2b_company_idempotency (
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash BINARY(32) NOT NULL,
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_status SMALLINT UNSIGNED NOT NULL,
    response_json MEDIUMTEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (employee_uuid, idempotency_key),
    KEY idx_b2b_company_idempotency_retention (created_at),
    KEY idx_b2b_company_idempotency_company (company_uuid),
    CONSTRAINT fk_b2b_company_idempotency_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_b2b_company_idempotency_company FOREIGN KEY (company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Narrow, role-grantable company permissions. Each is usable only together with B2B application access
-- (b2b.access, unchanged). No role is given them here.
INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('b2b.companies.view', 'b2b', 'Vizualizare companii B2B', 'View wholesale companies, their contacts, addresses, notes and activity', 1, UTC_TIMESTAMP(6)),
    ('b2b.companies.create', 'b2b', 'Creare companii B2B', 'Create wholesale companies', 1, UTC_TIMESTAMP(6)),
    ('b2b.companies.update', 'b2b', 'Editare companii B2B', 'Edit company details, contacts, addresses and internal notes', 1, UTC_TIMESTAMP(6)),
    ('b2b.companies.manage_status', 'b2b', 'Activare/dezactivare companii B2B', 'Deactivate or reactivate wholesale companies', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE permission_key = permission_key;
