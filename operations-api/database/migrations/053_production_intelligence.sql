-- Production Intelligence V2 (API 2.70.0): sold-meter evidence and its ledger. Additive only.
-- No existing table is altered, no existing row is written, nothing is granted, nothing cascades. Five new tables.
--
-- order_item_meter_facts: the pricing basis of a commercial line as the source stated it (order.items[].meterBasis,
-- heartbeat orderFacts "meterBasis"). It is kept outside the projection hash, the printed documents and the item row, so
-- receiving it never changes an order version, a production document or a Confirmare decision. Absence never erases it.
CREATE TABLE IF NOT EXISTS order_item_meter_facts (
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_item_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    basis_kind VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    priced_length DECIMAL(12,3) NULL,
    source_event_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_observed_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (order_uuid, source_item_id),
    KEY idx_meter_facts_updated (updated_at),
    CONSTRAINT fk_meter_facts_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_meter_facts_kind CHECK (basis_kind IN ('fabric_linear', 'rail_linear', 'unit', 'unknown')),
    CONSTRAINT chk_meter_facts_length CHECK (priced_length IS NULL OR (priced_length > 0 AND basis_kind IN ('fabric_linear', 'rail_linear')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- production_meter_ledger: insert-only signed sold-meter entries per commercial line. The unique key makes a re-sync, a
-- repeated ledger run or a backfill write nothing twice. Cancellations and corrections are new negative/positive entries.
CREATE TABLE IF NOT EXISTS production_meter_ledger (
    entry_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    metric VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_code_snapshot VARCHAR(120) NULL,
    variant_snapshot VARCHAR(160) NULL,
    delta_meters DECIMAL(15,3) NOT NULL,
    reason VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cause_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    formula_version SMALLINT UNSIGNED NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (entry_uuid),
    UNIQUE KEY uq_meter_ledger_cause (metric, item_uuid, cause_key),
    KEY idx_meter_ledger_period (occurred_at, metric, source_key),
    KEY idx_meter_ledger_item (item_uuid, metric),
    KEY idx_meter_ledger_order (order_uuid),
    CONSTRAINT fk_meter_ledger_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_meter_ledger_metric CHECK (metric IN ('sold_fabric', 'sold_rail')),
    CONSTRAINT chk_meter_ledger_reason CHECK (reason IN ('sale', 'correction', 'cancellation', 'reactivation', 'exclusion', 'line_removed')),
    CONSTRAINT chk_meter_ledger_delta CHECK (delta_meters <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- production_meter_exclusions: orders kept out of every meter report by an explicit, audited decision (owner K7: the B2B
-- acceptance-test orders). The order itself is never deleted or changed.
CREATE TABLE IF NOT EXISTS production_meter_exclusions (
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    note VARCHAR(300) NULL,
    decided_by_label VARCHAR(160) NOT NULL,
    decided_at DATETIME(6) NOT NULL,
    PRIMARY KEY (order_uuid),
    CONSTRAINT fk_meter_exclusions_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_meter_exclusions_reason CHECK (reason IN ('test_order', 'duplicate_order', 'not_a_sale'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- production_meter_ledger_cursor: where the automatic, incremental ledger run (bin/meter-ledger.php --changed) continues.
-- One row per cursor. The first automatic run only records "now" and processes nothing: history is a separate, approved
-- backfill. Order processing never reads or writes it.
CREATE TABLE IF NOT EXISTS production_meter_ledger_cursor (
    cursor_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    watermark_at DATETIME(6) NOT NULL,
    last_run_at DATETIME(6) NOT NULL,
    last_orders INT UNSIGNED NOT NULL,
    last_entries INT UNSIGNED NOT NULL,
    PRIMARY KEY (cursor_key),
    CONSTRAINT chk_meter_ledger_cursor_key CHECK (cursor_key IN ('sold_meters'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- shop_sourced_meter_lines: "Arasya Dışı / Mağaza Kaynaklı" (owner decision 2026-11-13). Lines of web-shop orders that the
-- shop holds but that never reached Arasya, as the shop export stated them (bin/meter-facts-import.php). They are not
-- canonical orders: no order, item, QR, document or activity is created for them, and they never enter production
-- metrics. Only a line whose order the shop verified as a sale (status completed with a recorded payment) counts as sold
-- meters, reported apart under its own origin. A row whose order later exists in Arasya is never counted again: Arasya's
-- own ledger counts that order. Each import replaces an order's rows with the shop's latest statement.
CREATE TABLE IF NOT EXISTS shop_sourced_meter_lines (
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_order_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_item_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    shop_status VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sale_verified TINYINT(1) NOT NULL,
    ordered_at DATETIME(6) NOT NULL,
    paid_at DATETIME(6) NULL,
    basis_kind VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    priced_length DECIMAL(12,3) NULL,
    quantity INT UNSIGNED NOT NULL,
    refunded_quantity INT UNSIGNED NOT NULL,
    product_code_snapshot VARCHAR(120) NULL,
    variant_snapshot VARCHAR(160) NULL,
    origin VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_imported_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (source_key, source_order_id, source_item_id),
    KEY idx_shop_sourced_global (global_order_id),
    KEY idx_shop_sourced_period (ordered_at, source_key),
    CONSTRAINT chk_shop_sourced_source CHECK (source_key IN ('trendhome', 'outletperdele')),
    CONSTRAINT chk_shop_sourced_kind CHECK (basis_kind IN ('fabric_linear', 'rail_linear', 'unit', 'unknown')),
    CONSTRAINT chk_shop_sourced_length CHECK (priced_length IS NULL OR (priced_length > 0 AND basis_kind IN ('fabric_linear', 'rail_linear'))),
    CONSTRAINT chk_shop_sourced_quantity CHECK (refunded_quantity <= quantity),
    CONSTRAINT chk_shop_sourced_verified CHECK (sale_verified IN (0, 1) AND (sale_verified = 0 OR (shop_status = 'completed' AND paid_at IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
