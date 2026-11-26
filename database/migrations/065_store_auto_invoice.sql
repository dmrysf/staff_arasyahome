-- Migration 065 (API 2.84.0, Store Workspace V1.1, owner approval 2026-11-26 "FINAL AUTOMATION APPROVAL"): the final
-- invoice of a Trend Home / Outlet Perdele order is issued by the system after the shop employee printed the production
-- document, no longer by hand in the SmartBill panel.
--
-- order_invoice_auto_requests: one durable request per order, written in the SAME transaction as the employee's print
-- record (DocumentService::recordPrint), so a closed browser, a lost connection or a SmartBill timeout never loses it. The
-- worker (bin/confirmare-distribute.php, every minute) and the employee's own "now" call (POST .../invoice/auto) process
-- it through ConfirmareInvoice::act as the system identity, on behalf of the employee who printed:
--   trigger_employee_uuid   the real person whose print asked for the invoice (the audit's "who asked")
--   last_action_uuid        the system's order_invoice_actions row (the audit's "what the system did", role manager,
--                           key prefix `auto-invoice-`); every guard of a manual issue runs again on every attempt. A
--                           plain reference (no foreign key): the action table keeps its own lifecycle
--   state                   waiting (the worker tries again at next_check_at), done (issued, or a final invoice already
--                           exists: outcome), attention (a person must look: SmartBill did not confirm, the shop refused
--                           too often), stopped (no longer this employee's or the order closed)
--   sent_attempts           how many times the system really asked the shop (at most 3); a lost answer is never sent again
--                           (the lost-answers worker settles it with the same key)
--   claim_token/claim_until one run at a time per request (a conditional UPDATE claims it)
--
-- Additive: one new table, no existing row changes. Idempotent (CREATE TABLE IF NOT EXISTS). Rollback: API 2.83.x never
-- reads the table; drop it only after rolling back (no other table refers to it).

CREATE TABLE IF NOT EXISTS order_invoice_auto_requests (
    request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    trigger_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    trigger_print_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    triggered_at DATETIME(6) NOT NULL,
    state VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    sent_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    checks INT UNSIGNED NOT NULL DEFAULT 0,
    last_action_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    next_check_at DATETIME(6) NOT NULL,
    claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claim_until DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (request_uuid),
    UNIQUE KEY uq_order_invoice_auto_requests_order (order_uuid),
    KEY idx_order_invoice_auto_requests_due (state, next_check_at),
    KEY idx_order_invoice_auto_requests_trigger (trigger_employee_uuid, triggered_at),
    CONSTRAINT fk_order_invoice_auto_requests_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_invoice_auto_requests_trigger FOREIGN KEY (trigger_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_invoice_auto_requests_state CHECK (state IN ('waiting', 'done', 'attention', 'stopped')),
    CONSTRAINT chk_order_invoice_auto_requests_outcome CHECK (outcome IS NULL OR outcome REGEXP '^[a-z_]{2,40}$'),
    CONSTRAINT chk_order_invoice_auto_requests_attempts CHECK (sent_attempts <= 3),
    CONSTRAINT chk_order_invoice_auto_requests_done CHECK ((state = 'waiting' AND completed_at IS NULL) OR (state <> 'waiting' AND completed_at IS NOT NULL AND outcome IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
