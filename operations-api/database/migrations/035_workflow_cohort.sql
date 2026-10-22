-- Confirmare workflow cohort of signed WooCommerce source orders (API 2.37.0, Phase 1).
--
-- Additive only. No existing order, receipt, QR, document, payment fact or production event is rewritten. Every
-- statement is resumable: tables use CREATE TABLE IF NOT EXISTS and the operational_orders columns are added by one
-- guarded ALTER that runs only while the columns are missing, so an interrupted run can simply be repeated.
--
-- Model
--   workflow_cohort_activations
--        Append-only history of the Confirmare activation of each signed source, recorded by the operator CLI
--        (bin/workflow-cohort.php) with the server clock: `scheduled` (a future first-activation instant), `cancelled`
--        (an uncommitted schedule withdrawn), `activated` (the committed first activation at the scheduled instant, or a
--        resumption) and `suspended`. The FIRST `activated` instant classifies: every order submitted at or after it is
--        a Confirmare order for good; a suspension holds orders, it never hands them back. Empty after this migration.
--   workflow_cohort_arming
--        The latest signed report of each source that its local Confirmare fence is armed (heartbeat field
--        confirmareArmedAt). A first activation is committed only after the source reported arming at or before the
--        scheduled instant, so no order can fall into the legacy flow between the activation and the source's fence.
--   operational_orders.workflow_cohort
--        Decided once, when Arasya creates the canonical order, and never changed by a later source event:
--          NULL          the order existed before this release (pre-cohort: its established handling continues)
--          legacy        created by Arasya after this release, but not a Confirmare order (reason says why)
--          confirmare    genuinely submitted at or after the source's activation: production stays closed until the
--                        Confirmare workflow records an approval (order_confirmations.status = 'approved')
--          undetermined  the source could not prove when the order was submitted although the source is active:
--                        production stays closed and the order is reported for an explicit operator resolution
--        workflow_cohort_reason / workflow_cohort_at record why and when. source_submitted_at and
--        source_submission_evidence keep the source's submission evidence (UTC instant and its kind).
--   order_confirmations
--        The pre-production Confirmare state of one Confirmare-cohort order (never a production stage). Phase 1 only
--        opens rows (status 'pending'); no code path of this release decides one.

CREATE TABLE IF NOT EXISTS workflow_cohort_activations (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    effective_at DATETIME(6) NOT NULL,
    actor_label VARCHAR(120) NOT NULL,
    reason VARCHAR(200) NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_uuid),
    KEY idx_workflow_cohort_activations_source (source_key, effective_at),
    CONSTRAINT fk_workflow_cohort_activations_source FOREIGN KEY (source_key) REFERENCES order_sources (source_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_workflow_cohort_activations_action CHECK (action IN ('scheduled', 'cancelled', 'activated', 'suspended')),
    CONSTRAINT chk_workflow_cohort_activations_floor CHECK (effective_at >= '2026-10-21 21:00:00.000000')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_cohort_arming (
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    armed_at DATETIME(6) NOT NULL,
    reported_at DATETIME(6) NOT NULL,
    PRIMARY KEY (source_key),
    CONSTRAINT fk_workflow_cohort_arming_source FOREIGN KEY (source_key) REFERENCES order_sources (source_key) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @arasya_035_cohort_missing = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'operational_orders' AND column_name = 'workflow_cohort');

SET @arasya_035_alter = IF(@arasya_035_cohort_missing,
    'ALTER TABLE operational_orders
        ADD COLUMN workflow_cohort VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER payment_reversed_at,
        ADD COLUMN workflow_cohort_reason VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER workflow_cohort,
        ADD COLUMN workflow_cohort_at DATETIME(6) NULL AFTER workflow_cohort_reason,
        ADD COLUMN source_submitted_at DATETIME(6) NULL AFTER workflow_cohort_at,
        ADD COLUMN source_submission_evidence VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER source_submitted_at,
        ADD KEY idx_operational_orders_cohort (source_key, workflow_cohort),
        ADD CONSTRAINT chk_operational_orders_workflow_cohort CHECK (workflow_cohort IS NULL OR workflow_cohort IN (''legacy'', ''confirmare'', ''undetermined'')),
        ADD CONSTRAINT chk_operational_orders_workflow_cohort_complete CHECK ((workflow_cohort IS NULL) = (workflow_cohort_reason IS NULL) AND (workflow_cohort IS NULL) = (workflow_cohort_at IS NULL))',
    'DO 0');

PREPARE arasya_035_statement FROM @arasya_035_alter;

EXECUTE arasya_035_statement;

DEALLOCATE PREPARE arasya_035_statement;

CREATE TABLE IF NOT EXISTS order_confirmations (
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version INT UNSIGNED NOT NULL,
    opened_at DATETIME(6) NOT NULL,
    decided_at DATETIME(6) NULL,
    decided_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (order_uuid),
    UNIQUE KEY uq_order_confirmations_global (global_order_id),
    KEY idx_order_confirmations_queue (source_key, status, opened_at),
    CONSTRAINT fk_order_confirmations_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_confirmations_decider FOREIGN KEY (decided_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_confirmations_status CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
    CONSTRAINT chk_order_confirmations_decided CHECK ((status = 'pending') = (decided_at IS NULL) AND (decided_by_employee_uuid IS NULL OR decided_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
