-- Multi-channel cancellation synchronization (API 2.36.0, Phase 2). Builds on 033 (order cancellation control).
--
-- Additive. No existing row is rewritten. Everything that could send anything to a sales channel is created disabled:
-- the three provider flags below, and the ARASYA_CANCELLATION_SYNC server switch, which defaults to `disabled`.
-- 032 stays reserved for the simulation pull request. Every statement is resumable: tables use CREATE TABLE IF NOT
-- EXISTS, the flags and the permission are inserted idempotently, and hold_generation is added by one guarded ALTER that
-- runs only while the column is missing, so an interrupted run can simply be repeated.
--
-- Model (separate facts, never one boolean):
--   order_cancellation_controls.hold_generation
--        Counts the stops of one order (033 keeps one control row per order; a stop after a release is a new
--        generation). An outbound operation belongs to exactly one generation, so a retry never becomes a new
--        logical cancellation and a later stop never reuses an old operation.
--   order_cancellation_sync_operations   (durable outbox, one row per logical outbound cancellation)
--        state     queued | processing | awaiting_pickup | awaiting_confirmation | retry_scheduled | uncertain
--                  | confirmed | rejected | failed | manual_review | withdrawn | closed
--        mechanism api            Arasya calls the provider (Trendyol: PUT .../items/unsupplied)
--                  source_pull    the source's own connector pulls the request and reports a result (YD SOFT on
--                                 WooCommerce); Arasya never calls WooCommerce
--                  manual         the provider offers no supported call (B2B, or a provider not configured); a
--                                 person acts in the provider's own back office
--        Confirmation is always evidence from the provider itself: the inbound cancellation of the order (signed
--        source event or marketplace synchronization) or an authoritative status read. A provider's "accepted"
--        answer or a connector's report is recorded, never treated as confirmation.
--        open_order_uuid makes at most one operation per order open at a time (NULL once terminal).
--        lease_owner / lease_expires_at give one worker exclusive ownership; an expired lease is recovered.
--   order_cancellation_sync_attempts     (append-only history of every submit, status read, pickup and report)
--        evidence_json  what a person's decision rested on: for a retry after a possibly delivered notice, the
--                       server's own status read it relied on and the person's statement about their check in the
--                       channel (an attestation, never a provider confirmation)
--   order_cancellation_discrepancies     (reconciliation findings, one open row per fingerprint)
--   permission production.cancellation.sync  (send, retry, withdraw, close, reconcile); granted to no role
--   production_feature_flags cancellation_sync_trendyol / _trendhome / _outletperdele, created disabled

SET @arasya_034_generation_missing = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_cancellation_controls' AND column_name = 'hold_generation');

SET @arasya_034_alter = IF(@arasya_034_generation_missing,
    'ALTER TABLE order_cancellation_controls
        ADD COLUMN hold_generation INT UNSIGNED NOT NULL DEFAULT 1 AFTER version,
        ADD CONSTRAINT chk_order_cancellation_controls_generation CHECK (hold_generation >= 1)',
    'DO 0');

PREPARE arasya_034_statement FROM @arasya_034_alter;

EXECUTE arasya_034_statement;

DEALLOCATE PREPARE arasya_034_statement;

CREATE TABLE IF NOT EXISTS order_cancellation_sync_operations (
    operation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    open_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    hold_generation INT UNSIGNED NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_order_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mechanism VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason_code VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    note VARCHAR(1000) NULL,
    requested_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NULL,
    lease_owner VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    lease_expires_at DATETIME(6) NULL,
    dispatched_at DATETIME(6) NULL,
    last_pulled_at DATETIME(6) NULL,
    provider_result VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    provider_result_at DATETIME(6) NULL,
    provider_status VARCHAR(60) NULL,
    confirmed_at DATETIME(6) NULL,
    confirmed_by VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_error_code VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_error_at DATETIME(6) NULL,
    closed_at DATETIME(6) NULL,
    closed_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    version INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (operation_uuid),
    UNIQUE KEY uq_order_cancellation_sync_generation (order_uuid, hold_generation),
    UNIQUE KEY uq_order_cancellation_sync_open (open_order_uuid),
    KEY idx_order_cancellation_sync_due (state, next_attempt_at),
    KEY idx_order_cancellation_sync_source (source_key, state),
    KEY idx_order_cancellation_sync_lease (state, lease_expires_at),
    CONSTRAINT fk_order_cancellation_sync_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_cancellation_sync_requested_by FOREIGN KEY (requested_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_cancellation_sync_closed_by FOREIGN KEY (closed_by_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_cancellation_sync_mechanism CHECK (mechanism IN ('api', 'source_pull', 'manual')),
    CONSTRAINT chk_order_cancellation_sync_state CHECK (state IN ('queued', 'processing', 'awaiting_pickup', 'awaiting_confirmation', 'retry_scheduled', 'uncertain',
        'confirmed', 'rejected', 'failed', 'manual_review', 'withdrawn', 'closed')),
    CONSTRAINT chk_order_cancellation_sync_open CHECK (
        (state IN ('confirmed', 'rejected', 'failed', 'withdrawn', 'closed')) = (open_order_uuid IS NULL)),
    CONSTRAINT chk_order_cancellation_sync_open_order CHECK (open_order_uuid IS NULL OR open_order_uuid = order_uuid),
    CONSTRAINT chk_order_cancellation_sync_lease CHECK ((lease_owner IS NULL) = (lease_expires_at IS NULL) AND (state <> 'processing' OR lease_owner IS NOT NULL)),
    CONSTRAINT chk_order_cancellation_sync_confirmed CHECK ((state = 'confirmed') = (confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL)),
    CONSTRAINT chk_order_cancellation_sync_confirmed_by CHECK (confirmed_by IS NULL OR confirmed_by IN ('inbound_event', 'status_read')),
    CONSTRAINT chk_order_cancellation_sync_version CHECK (version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_cancellation_sync_attempts (
    attempt_seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state_before VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state_after VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    error_code VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
    provider_status VARCHAR(60) NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    actor_system VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    correlation_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    evidence_json JSON NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (attempt_seq),
    KEY idx_order_cancellation_sync_attempts_operation (operation_uuid, attempt_seq),
    CONSTRAINT fk_order_cancellation_sync_attempts_operation FOREIGN KEY (operation_uuid) REFERENCES order_cancellation_sync_operations (operation_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_cancellation_sync_attempts_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_cancellation_sync_attempts_kind CHECK (kind IN ('create', 'submit', 'status_read', 'pickup', 'report', 'inbound', 'lease_recovery', 'retry', 'recheck', 'withdraw', 'close', 'expire'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_cancellation_discrepancies (
    discrepancy_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    open_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    kind VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    severity VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    operation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    remediation VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    retry_eligible TINYINT(1) NOT NULL,
    details_json JSON NULL,
    state VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    detected_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    resolved_at DATETIME(6) NULL,
    PRIMARY KEY (discrepancy_uuid),
    UNIQUE KEY uq_order_cancellation_discrepancies_open (open_fingerprint),
    KEY idx_order_cancellation_discrepancies_state (state, severity, detected_at),
    KEY idx_order_cancellation_discrepancies_order (order_uuid),
    CONSTRAINT fk_order_cancellation_discrepancies_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_cancellation_discrepancies_state CHECK (state IN ('open', 'resolved')),
    CONSTRAINT chk_order_cancellation_discrepancies_open CHECK ((state = 'open') = (open_fingerprint IS NOT NULL) AND (open_fingerprint IS NULL OR open_fingerprint = fingerprint)),
    CONSTRAINT chk_order_cancellation_discrepancies_severity CHECK (severity IN ('low', 'medium', 'high')),
    CONSTRAINT chk_order_cancellation_discrepancies_retry CHECK (retry_eligible IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO production_feature_flags (flag_key, enabled, changed_by_employee_uuid, change_reason, changed_at) VALUES
    ('cancellation_sync_trendyol', 0, NULL, 'Created disabled by migration 034', UTC_TIMESTAMP(6)),
    ('cancellation_sync_trendhome', 0, NULL, 'Created disabled by migration 034', UTC_TIMESTAMP(6)),
    ('cancellation_sync_outletperdele', 0, NULL, 'Created disabled by migration 034', UTC_TIMESTAMP(6));

INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('production.cancellation.sync', 'production', 'Sincronizare anulare cu sursa', 'Send a stopped order''s cancellation to its sales channel, retry, withdraw or close a synchronization and run a reconciliation (never a refund)', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE category = VALUES(category), label = VALUES(label), description = VALUES(description), role_grantable = VALUES(role_grantable);
