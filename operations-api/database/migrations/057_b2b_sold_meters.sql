-- Migration 057 (API 2.76.0): B2B sold meters for the CEO meter reports, and the permanent B2B test cohort.
--
-- Additive only. Two new tables. No existing table is altered, no existing row is written, nothing is granted, nothing
-- cascades. The B2B current account (b2b_account_*), the B2B orders and lines, the production handoffs, the canonical
-- orders and the existing meter ledger are only referenced, never changed.
--
--   b2b_meter_test_cohort
--        One row per B2B commercial order that an owner decision marked as a test (owner decision 2026-11-19: every B2B
--        order that existed at the snapshot is a test). Written only by bin/b2b-meter-cohort.php with an IAM audit event.
--        The order itself is never changed or deleted. A cohort order never counts as sold meters, and its canonical
--        production order (if it was handed off, now or later) is kept out of every production metric through
--        production_meter_exclusions. An order created after the snapshot is a real sale unless a later, separate decision
--        adds it here (cohort_key names each decision).
--   b2b_sold_meter_ledger
--        Insert-only sold-meter entries of B2B commercial lines, by b2b order and line. A finalized order sells its lines'
--        entered meters (the line total, never multiplied by the quantity) at its finalization, without waiting for the
--        production handoff; a commercial cancellation is a dated negative entry. Lines of type `other` and lines without
--        meters carry no entry (not metered / unknown, never zero). The canonical production order of a handed-off B2B
--        order never books sold meters in production_meter_ledger, so a B2B sale is counted exactly once. The unique cause
--        key stops a repeated or concurrent run from writing an entry twice.
--
-- Rollback: API 2.75.0 never reads these tables; they may stay (no DROP). Applied by bin/migrate.php with the API deploy.
CREATE TABLE IF NOT EXISTS b2b_meter_test_cohort (
    b2b_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cohort_key VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_code_snapshot VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status_snapshot VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entered_meters_snapshot DECIMAL(15,3) NOT NULL,
    canonical_order_uuid_snapshot CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    note VARCHAR(300) NULL,
    decided_by_label VARCHAR(160) NOT NULL,
    decided_at DATETIME(6) NOT NULL,
    PRIMARY KEY (b2b_order_uuid),
    KEY idx_b2b_meter_cohort_decided (decided_at),
    KEY idx_b2b_meter_cohort_key (cohort_key),
    CONSTRAINT fk_b2b_meter_cohort_order FOREIGN KEY (b2b_order_uuid) REFERENCES b2b_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_b2b_meter_cohort_reason CHECK (reason IN ('test_order')),
    CONSTRAINT chk_b2b_meter_cohort_meters CHECK (entered_meters_snapshot >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS b2b_sold_meter_ledger (
    entry_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    metric VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    b2b_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    line_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_code_snapshot VARCHAR(160) NULL,
    variant_snapshot VARCHAR(160) NULL,
    delta_meters DECIMAL(15,3) NOT NULL,
    reason VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cause_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    formula_version SMALLINT UNSIGNED NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (entry_uuid),
    UNIQUE KEY uq_b2b_sold_meter_cause (metric, line_uuid, cause_key),
    KEY idx_b2b_sold_meter_period (occurred_at, metric),
    KEY idx_b2b_sold_meter_order (b2b_order_uuid),
    KEY idx_b2b_sold_meter_line (line_uuid, metric),
    CONSTRAINT fk_b2b_sold_meter_order FOREIGN KEY (b2b_order_uuid) REFERENCES b2b_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_b2b_sold_meter_metric CHECK (metric IN ('sold_fabric')),
    CONSTRAINT chk_b2b_sold_meter_reason CHECK (reason IN ('sale', 'correction', 'cancellation', 'reactivation', 'exclusion', 'line_removed')),
    CONSTRAINT chk_b2b_sold_meter_delta CHECK (delta_meters <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
