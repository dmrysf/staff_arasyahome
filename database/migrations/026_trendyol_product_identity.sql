-- Trendyol product identity for production documents. Additive only.
-- 1. Trendyol answers the literal placeholder "merchantSku" in stockCode and merchantSku when the seller has no
--    stock code; the seller's own code is in the line's `sku`. The resolved seller code is kept apart from the raw
--    marketplace fields, which stay exactly as Trendyol sent them (the lines hash is unchanged, so no released
--    package is flagged and no prepared line is reset).
-- 2. Factory documents print a Romanian production title approved by a person, never a machine translation of the
--    English marketplace title. The original title stays on the line for traceability.
ALTER TABLE trendyol_package_lines
    ADD COLUMN product_code VARCHAR(120) NULL AFTER stock_code,
    ADD COLUMN prepared_title VARCHAR(255) NULL AFTER prepared_notes;

-- One approved Romanian production title per seller product code, kept by the person who approved it.
CREATE TABLE IF NOT EXISTS trendyol_product_titles (
    product_code VARCHAR(120) NOT NULL,
    title_ro VARCHAR(255) NOT NULL,
    approved_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    approved_at DATETIME(6) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (product_code),
    CONSTRAINT fk_trendyol_product_titles_approver FOREIGN KEY (approved_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_trendyol_product_titles_version CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only history of every approved title change.
CREATE TABLE IF NOT EXISTS trendyol_product_title_events (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_code VARCHAR(120) NOT NULL,
    title_ro VARCHAR(255) NOT NULL,
    previous_title_ro VARCHAR(255) NULL,
    package_id BIGINT UNSIGNED NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(100) NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_uuid),
    KEY idx_trendyol_product_title_events_code (product_code, occurred_at),
    CONSTRAINT fk_trendyol_product_title_events_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
