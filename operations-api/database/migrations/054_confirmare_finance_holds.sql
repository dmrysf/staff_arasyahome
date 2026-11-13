-- Confirmare finance holds (API 2.71.0): an authorized, reasoned, audited stop of the advance proforma expiry.
-- DRAFT ONLY; NOT RUN IN PRODUCTION. Requires 044_finance_verification_requests.sql. Independent of 053.
--
-- Owner decision (2026-11-13): an order whose customer may already have paid must never be cancelled by the 120 h
-- proforma expiry while a person is still looking for the money. Finance can record money on an order only through its
-- finance task, which exists only once the order is approved (API 2.50.0, unchanged), so a transfer that arrives BEFORE
-- the approval has no place on the order. A hold is that place: it stops the expiry (no SmartBill cancellation, no
-- production stop, no cancellation request to the shop) and keeps production closed, until another authorized employee
-- releases it with a reason. A hold records no money: payments are still recorded only by the finance rules.
--
-- Additive: one table, nothing else. No existing table is altered or written, no permission is granted.
-- Database guarantees, independent of the application:
--   * at most one open hold per order (UNIQUE open_order_uuid; NULL once released);
--   * an open hold has no release fields, a released hold has all of them (CHECK chk_confirmare_finance_holds_release);
--   * the employee who placed a hold never releases it (CHECK chk_confirmare_finance_holds_four_eyes);
--   * a repeated request of the same employee with the same idempotency key is the same hold (UNIQUE place key).
-- The row is the audit record (who, when, why, both ways); the API also writes an IAM audit event with codes only.
-- Re-runnable (IF NOT EXISTS). Rollback: an API before 2.71.0 ignores the table, so it would cancel a held order at the
-- expiry; bin/check-rollback.php therefore requires 2.71.0 while a hold is open.

CREATE TABLE IF NOT EXISTS confirmare_finance_holds (
    hold_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    open_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    note VARCHAR(500) NOT NULL,
    placed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    placed_role VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    placed_at DATETIME(6) NOT NULL,
    place_idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    place_request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    released_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    released_role VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    released_at DATETIME(6) NULL,
    release_reason_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    release_note VARCHAR(500) NULL,
    release_idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    release_request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (hold_uuid),
    UNIQUE KEY uq_confirmare_finance_holds_open (open_order_uuid),
    UNIQUE KEY uq_confirmare_finance_holds_place_key (placed_by_employee_uuid, place_idempotency_key),
    KEY idx_confirmare_finance_holds_order (order_uuid, placed_at),
    KEY idx_confirmare_finance_holds_source (source_key, released_at, placed_at),
    CONSTRAINT fk_confirmare_finance_holds_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_confirmare_finance_holds_source FOREIGN KEY (source_key) REFERENCES order_sources (source_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_confirmare_finance_holds_placer FOREIGN KEY (placed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_confirmare_finance_holds_releaser FOREIGN KEY (released_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_confirmare_finance_holds_source CHECK (source_key IN ('trendhome', 'outletperdele')),
    CONSTRAINT chk_confirmare_finance_holds_reason CHECK (reason_code IN ('early_transfer', 'transfer_exception', 'customer_reports_payment', 'finance_check_pending', 'other')),
    CONSTRAINT chk_confirmare_finance_holds_roles CHECK (placed_role IN ('finance', 'supervisor', 'operator') AND (released_role IS NULL OR released_role IN ('finance', 'supervisor'))),
    CONSTRAINT chk_confirmare_finance_holds_release CHECK (
        (released_at IS NULL AND open_order_uuid = order_uuid AND released_by_employee_uuid IS NULL AND released_role IS NULL AND release_reason_code IS NULL
            AND release_note IS NULL AND release_idempotency_key IS NULL AND release_request_id IS NULL)
        OR (released_at IS NOT NULL AND open_order_uuid IS NULL AND released_by_employee_uuid IS NOT NULL AND released_role IS NOT NULL
            AND release_reason_code IN ('payment_recorded', 'not_customer_payment', 'returned_to_payer', 'no_payment_found', 'other')
            AND release_note IS NOT NULL AND release_idempotency_key IS NOT NULL AND release_request_id IS NOT NULL AND released_at >= placed_at)),
    CONSTRAINT chk_confirmare_finance_holds_four_eyes CHECK (released_by_employee_uuid IS NULL OR released_by_employee_uuid <> placed_by_employee_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
