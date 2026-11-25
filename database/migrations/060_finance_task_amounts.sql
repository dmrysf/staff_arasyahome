-- Migration 060 (API 2.77.9, owner 2026-11-25, Contabilitate desk): the advance amount of a finance task as the shop last
-- showed it, for the finance employee's task list.
--
-- Before 2.77.9 the inbox carried no amount: the task screen read the money from the shop only when a task was opened,
-- and the list could show an amount only by asking the shop once per row. A row here is written by the employee's own
-- task read (FinanceVerificationRequests::live, audited in order_finance_reads) and by bin/finance-task-amounts.php (a
-- cron read under the root identity, audited the same way). The amount is display data only: every decision reads the
-- shop again and checks its revision, so a stale row never decides anything.
--
-- Additive: one new table, no change to any existing table or row. Idempotent (IF NOT EXISTS). Rollback: an older API
-- never reads the table; it can be dropped at any time (the list then shows no amounts until it is filled again).
CREATE TABLE IF NOT EXISTS finance_task_amounts (
    request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    required_amount DECIMAL(12,2) NULL,
    remaining_amount DECIMAL(12,2) NULL,
    verified_amount DECIMAL(12,2) NULL,
    percentage INT UNSIGNED NULL,
    finance_revision VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NULL,
    read_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (request_uuid),
    KEY idx_finance_task_amounts_read (read_at),
    CONSTRAINT fk_finance_task_amounts_request FOREIGN KEY (request_uuid) REFERENCES finance_verification_requests (request_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_finance_task_amounts_currency CHECK (currency REGEXP '^[A-Z]{3}$'),
    CONSTRAINT chk_finance_task_amounts_revision CHECK (finance_revision IS NULL OR finance_revision REGEXP '^sha256:[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
