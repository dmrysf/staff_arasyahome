-- CEO Production Intelligence (API 2.73.0): the shop catalog category of each product code. Additive only.
-- No existing table is altered, no existing row is written, nothing is granted, nothing cascades. One new table.
--
-- product_catalog_categories: the main category (and the subcategory) that the shop's own WooCommerce catalog gives a
-- product code, as a read-only shop export stated it (bin/product-category-import.php). Reports only read it to group
-- lines by category; it never changes an order, an item, a document or a production rule. A product code missing here is
-- reported as "unknown", never guessed from a title. Each import replaces one shop's rows with that shop's latest export.
CREATE TABLE IF NOT EXISTS product_catalog_categories (
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_code VARCHAR(120) NOT NULL,
    category_label VARCHAR(120) NOT NULL,
    subcategory_label VARCHAR(255) NULL,
    product_name VARCHAR(255) NULL,
    category_rule VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    import_ref CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    imported_at DATETIME(6) NOT NULL,
    PRIMARY KEY (source_key, product_code),
    KEY idx_product_catalog_category (category_label),
    CONSTRAINT chk_product_catalog_source CHECK (source_key IN ('trendhome', 'outletperdele')),
    CONSTRAINT chk_product_catalog_rule CHECK (category_rule IN ('single_main_category', 'primary_category'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
