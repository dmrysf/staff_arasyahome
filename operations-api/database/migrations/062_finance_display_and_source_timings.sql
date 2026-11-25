-- Migration 062 (API 2.81.0, P0 2026-11-25: connection and performance): the Contabilitate desk opens a task from
-- Arasya's own copy of what the shop last showed, and every call to a shop is measured.
--
-- 1. finance_task_amounts gains the display copy of a task's money (`display_json`, `display_read_at`) and the order's
--    money in columns (`order_total`, `received_amount`, `cod_remaining`, `financial_state`, the proforma series and
--    number). Written by every live read of the shop (the employee's task read, the amounts cron), never by hand. The
--    copy holds no customer data and no bank transfer: a task whose order has a transfer is never copied (it is read
--    live), and the bank account shown is the shop's own (IBAN masked). It is display data only: every decision reads
--    the shop again and checks its revision, so a stale copy never decides anything.
-- 2. source_access_timings: one row per call from Operations to a shop (host, route, milliseconds, HTTP status, outcome
--    code, request id). No body, no order, no customer data. Rows older than 14 days are deleted by the writer.
--
-- Additive only: nullable columns and one new table; no existing row changes. Idempotent (each step checks
-- information_schema first). Rollback: an older API never reads the new columns or the table; they can stay.
SET @arasya_062_display = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'finance_task_amounts' AND column_name = 'display_json');
SET @arasya_062_display_add = IF(@arasya_062_display,
    'ALTER TABLE finance_task_amounts
        ADD COLUMN display_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
        ADD COLUMN display_read_at DATETIME(6) NULL,
        ADD COLUMN order_total DECIMAL(12,2) NULL,
        ADD COLUMN received_amount DECIMAL(12,2) NULL,
        ADD COLUMN cod_remaining DECIMAL(12,2) NULL,
        ADD COLUMN financial_state VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD COLUMN proforma_series VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
        ADD COLUMN proforma_number VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
    'DO 0');
PREPARE arasya_062_display_statement FROM @arasya_062_display_add;
EXECUTE arasya_062_display_statement;
DEALLOCATE PREPARE arasya_062_display_statement;

CREATE TABLE IF NOT EXISTS source_access_timings (
    timing_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    called_at DATETIME(6) NOT NULL,
    shop_host VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    route VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    duration_ms INT UNSIGNED NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    outcome VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (timing_id),
    KEY idx_source_access_timings_host (shop_host, called_at),
    KEY idx_source_access_timings_called (called_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
