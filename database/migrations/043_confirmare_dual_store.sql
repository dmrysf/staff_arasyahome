-- Migration 043 (API 2.46.0): the dual-store operating workflow of Confirmare (owner master decisions 2026-10-25).
--
-- Additive only: six new tables, no row, no grant, no change to any other table. CREATE TABLE IF NOT EXISTS: running it
-- again changes nothing. Applied by bin/migrate.php with the API deploy, never by hand. Before it exists every new route
-- answers 409 CONFIRMARE_CYCLE_SCHEMA_MISSING and the cycle worker does nothing (fail closed). Rollback: older API
-- versions never read these tables; they are left in place (no DROP), and bin/check-rollback.php keeps the floor at
-- 2.46.0 while any of them holds open work.
--
-- Amounts, customer names, addresses, telephone numbers, document numbers and bank references are never stored here:
-- the shop (YD SOFT) keeps them. Rows hold codes, instants, employee ids and hashes.
--
--   confirmare_proforma_cycles
--        One row per advance proforma whose timer runs: from the instant the proforma was really sent to the customer,
--        a reminder after 24 h, a second one after 72 h, and after 120 h a check: without a bank check covering that
--        instant, an open payment verification or any money, the proforma is cancelled in SmartBill and the order's
--        cancellation is requested in the shop. `origin` says why the proforma exists (three unanswered calls, or the
--        operator); proformas sent before Arasya knew them (22 October backfill) never get a row, so no old proforma is
--        ever cancelled by a timer. `active_order_uuid` makes one running cycle per order. The worker claims a cycle with
--        a conditional UPDATE of claim_token (one worker wins); every step it sends to the shop carries a deterministic
--        idempotency key (confirmare_cycle_steps), so a retried step is answered from the shop's stored answer.
--   confirmare_cycle_steps
--        One row per step of a cycle sent to the shop (issue, mail, remind_1, remind_2, expire_cancel) or done in
--        Operations (order_cancel): its state, outcome code and instants. Unique per cycle and step.
--   confirmare_bank_checks
--        The finance employee's record that the bank statement of a shop's account was checked up to an instant
--        (`covers_until`). An expired proforma is cancelled only when a check of its shop covers its expiry instant.
--   confirmare_reactivations
--        "Clientul a revenit după anulare": a cancelled Confirmare order taken up again. The first one without payment is
--        `ordinary` and allowed once; any later one needs a verified real payment of the order (`paid_exception`, the
--        shop's finance entry id). Never revives an old proforma: a new cycle starts.
--   invoice_efactura_tasks
--        The e-Factura follow-up of each final SMART invoice for the shop employee who issued it: due 5 working days after
--        the invoice (Romania, OUG 89/2025), with the outcome the employee checked in SmartBill Cloud / SPV. SmartBill's API
--        gives no e-Factura status: nothing here is ever set automatically to accepted.
--   order_source_operations
--        Audit and idempotency of the operations relayed to the shop that are neither finance nor invoice actions: the
--        reactivation of a cancelled order, the FAN Courier AWB from stage 12 (create, address change task) and the NETOPIA
--        full card refund (request, record, verify). Codes and hashes only.

CREATE TABLE IF NOT EXISTS confirmare_proforma_cycles (
    cycle_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cycle_number INT UNSIGNED NOT NULL,
    origin VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    document_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sent_at DATETIME(6) NULL,
    reminder1_at DATETIME(6) NULL,
    reminder2_at DATETIME(6) NULL,
    expires_at DATETIME(6) NULL,
    bank_check_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    closed_reason VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claim_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claimed_at DATETIME(6) NULL,
    last_error VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    closed_at DATETIME(6) NULL,
    PRIMARY KEY (cycle_uuid),
    UNIQUE KEY uq_confirmare_proforma_cycles_active (active_order_uuid),
    UNIQUE KEY uq_confirmare_proforma_cycles_number (order_uuid, cycle_number),
    KEY idx_confirmare_proforma_cycles_state (state, updated_at),
    KEY idx_confirmare_proforma_cycles_source (source_key, state),
    CONSTRAINT fk_confirmare_proforma_cycles_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_confirmare_proforma_cycles_owner FOREIGN KEY (owner_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_confirmare_proforma_cycles_origin CHECK (origin IN ('no_answer', 'operator')),
    CONSTRAINT chk_confirmare_proforma_cycles_state CHECK (state IN ('issue_pending', 'mail_pending', 'running', 'waiting_bank_check', 'waiting_finance', 'cancel_pending',
        'cancel_unknown', 'order_cancel_pending', 'paid', 'cancelled', 'closed')),
    CONSTRAINT chk_confirmare_proforma_cycles_active CHECK ((state IN ('paid', 'cancelled', 'closed') AND active_order_uuid IS NULL AND closed_at IS NOT NULL)
        OR (state NOT IN ('paid', 'cancelled', 'closed') AND active_order_uuid = order_uuid AND closed_at IS NULL)),
    CONSTRAINT chk_confirmare_proforma_cycles_timer CHECK (sent_at IS NULL OR (expires_at IS NOT NULL AND expires_at > sent_at)),
    CONSTRAINT chk_confirmare_proforma_cycles_codes CHECK ((closed_reason IS NULL OR closed_reason REGEXP '^[a-z_]{2,40}$') AND (last_error IS NULL OR last_error REGEXP '^[a-z_]{2,40}$')),
    CONSTRAINT chk_confirmare_proforma_cycles_document CHECK (document_hash IS NULL OR document_hash REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_confirmare_proforma_cycles_number CHECK (cycle_number > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS confirmare_cycle_steps (
    step_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cycle_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    step VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    requested_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (step_uuid),
    UNIQUE KEY uq_confirmare_cycle_steps_step (cycle_uuid, step),
    UNIQUE KEY uq_confirmare_cycle_steps_key (idempotency_key),
    KEY idx_confirmare_cycle_steps_order (order_uuid, requested_at),
    CONSTRAINT fk_confirmare_cycle_steps_cycle FOREIGN KEY (cycle_uuid) REFERENCES confirmare_proforma_cycles (cycle_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_confirmare_cycle_steps_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_confirmare_cycle_steps_step CHECK (step IN ('issue', 'mail', 'remind_1', 'remind_2', 'expire_cancel', 'order_cancel')),
    CONSTRAINT chk_confirmare_cycle_steps_state CHECK (state IN ('pending', 'applied', 'rejected', 'unknown', 'skipped')),
    CONSTRAINT chk_confirmare_cycle_steps_outcome CHECK (outcome IS NULL OR outcome REGEXP '^[a-z_]{2,40}$'),
    CONSTRAINT chk_confirmare_cycle_steps_complete CHECK ((state IN ('pending', 'unknown') AND completed_at IS NULL) OR (state IN ('applied', 'rejected', 'skipped') AND completed_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS confirmare_bank_checks (
    check_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    covers_until DATETIME(6) NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (check_uuid),
    UNIQUE KEY uq_confirmare_bank_checks_key (employee_uuid, idempotency_key),
    KEY idx_confirmare_bank_checks_source (source_key, covers_until),
    CONSTRAINT fk_confirmare_bank_checks_source FOREIGN KEY (source_key) REFERENCES order_sources (source_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_confirmare_bank_checks_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_confirmare_bank_checks_time CHECK (covers_until <= recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS confirmare_reactivations (
    reactivation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sequence_number INT UNSIGNED NOT NULL,
    kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    previous_cycle_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    payment_entry_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reason_code VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    confirmation_version_before INT UNSIGNED NOT NULL,
    state VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    shop_outcome VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (reactivation_uuid),
    UNIQUE KEY uq_confirmare_reactivations_sequence (order_uuid, sequence_number),
    UNIQUE KEY uq_confirmare_reactivations_key (employee_uuid, idempotency_key),
    CONSTRAINT fk_confirmare_reactivations_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_confirmare_reactivations_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_confirmare_reactivations_kind CHECK ((kind = 'ordinary' AND payment_entry_id IS NULL) OR (kind = 'paid_exception' AND payment_entry_id REGEXP '^[A-Za-z0-9_-]{1,64}$')),
    CONSTRAINT chk_confirmare_reactivations_reason CHECK (reason_code IN ('customer_returned', 'customer_paid_late')),
    CONSTRAINT chk_confirmare_reactivations_state CHECK (state IN ('pending', 'applied', 'unknown', 'rejected')),
    CONSTRAINT chk_confirmare_reactivations_outcome CHECK (shop_outcome IS NULL OR shop_outcome REGEXP '^[a-z_]{2,40}$'),
    CONSTRAINT chk_confirmare_reactivations_sequence CHECK (sequence_number > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_efactura_tasks (
    task_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    invoice_action_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    issuer_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    issued_at DATETIME(6) NOT NULL,
    due_at DATETIME(6) NOT NULL,
    state VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recorded_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    recorded_at DATETIME(6) NULL,
    outcome_code VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (task_uuid),
    UNIQUE KEY uq_invoice_efactura_tasks_action (invoice_action_uuid),
    KEY idx_invoice_efactura_tasks_issuer (issuer_employee_uuid, state, due_at),
    KEY idx_invoice_efactura_tasks_source (source_key, state, due_at),
    CONSTRAINT fk_invoice_efactura_tasks_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_efactura_tasks_issuer FOREIGN KEY (issuer_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_invoice_efactura_tasks_state CHECK (state IN ('open', 'sent', 'accepted', 'rejected', 'resolved')),
    CONSTRAINT chk_invoice_efactura_tasks_outcome CHECK (outcome_code IS NULL OR outcome_code REGEXP '^[a-z_]{2,30}$'),
    CONSTRAINT chk_invoice_efactura_tasks_recorded CHECK ((state = 'open' AND recorded_at IS NULL) OR (state <> 'open' AND recorded_at IS NOT NULL AND recorded_by_employee_uuid IS NOT NULL AND outcome_code IS NOT NULL)),
    CONSTRAINT chk_invoice_efactura_tasks_due CHECK (due_at > issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_source_operations (
    operation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    access_role VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hmac BINARY(32) NOT NULL,
    state VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    result VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    requested_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (operation_uuid),
    UNIQUE KEY uq_order_source_operations_key (employee_uuid, idempotency_key),
    KEY idx_order_source_operations_order (order_uuid, kind, requested_at),
    KEY idx_order_source_operations_state (state, requested_at),
    CONSTRAINT fk_order_source_operations_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_source_operations_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_source_operations_kind CHECK (kind IN ('order_reactivate', 'awb_create', 'awb_address_task', 'awb_address_done', 'card_refund_full', 'card_refund_record', 'card_refund_verify')),
    CONSTRAINT chk_order_source_operations_role CHECK (access_role IN ('documents', 'manager', 'operator', 'finance')),
    CONSTRAINT chk_order_source_operations_state CHECK (state IN ('pending', 'applied', 'rejected', 'unknown')),
    CONSTRAINT chk_order_source_operations_outcome CHECK ((outcome IS NULL OR outcome REGEXP '^[a-z_]{2,40}$') AND (result IS NULL OR result REGEXP '^[a-z_]{2,20}$')),
    CONSTRAINT chk_order_source_operations_complete CHECK ((state IN ('pending', 'unknown') AND completed_at IS NULL) OR (state IN ('applied', 'rejected') AND completed_at IS NOT NULL AND outcome IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
