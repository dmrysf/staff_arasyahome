-- Production QR authority (API 2.19.0).
-- Additive only. No QR reference is issued, rotated, revoked or rewritten by this migration, no order
-- changes authority, stage, document or version, and no permission grant is added or removed.
--
-- A QR revision is one row of order_qr_references. Its revision number is its position among the order's
-- references (oldest first). At most one reference per order is active; from now on the database enforces
-- that invariant itself through a generated column that is only set while the reference is active.
-- The migration fails (and changes nothing) if any order already had two active references.
-- RTRIM keeps the expression independent of PAD_CHAR_TO_FULL_LENGTH, which MariaDB requires for a
-- stored generated column over a CHAR column; a UUID has no trailing space, so the value is unchanged.
ALTER TABLE order_qr_references
    ADD COLUMN active_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin
        GENERATED ALWAYS AS (IF(status = 'active', RTRIM(order_uuid), NULL)) STORED,
    ADD COLUMN retired_reason VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD UNIQUE KEY uq_order_qr_references_active (active_order_uuid),
    ADD CONSTRAINT chk_order_qr_references_retired CHECK
        ((status = 'active' AND retired_reason IS NULL)
         OR (status = 'revoked' AND (retired_reason IS NULL OR retired_reason IN ('superseded', 'revoked'))));

-- Immutable QR evidence: issue, rotation, revocation, reconciliation and refused scans of retired codes.
-- Only a short non-reversible hint of a reference is stored (never the printed payload), and never any
-- customer data. Rows are evidence that must outlive any later cleanup, so they carry no foreign key.
CREATE TABLE IF NOT EXISTS production_qr_events (
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    qr_revision INT UNSIGNED NULL,
    qr_hint CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    previous_qr_hint CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    qr_authority_mode VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reason_code VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_uuid),
    KEY idx_production_qr_events_order (order_uuid, occurred_at),
    KEY idx_production_qr_events_source_time (source_key, occurred_at),
    CONSTRAINT chk_production_qr_events_action CHECK (action IN ('issued', 'rotated', 'revoked', 'reconciled', 'scan_rejected')),
    CONSTRAINT chk_production_qr_events_mode CHECK (qr_authority_mode IN ('legacy', 'observe', 'enforce', 'internal'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A manager QR rotation is idempotent like every other order command. Existing rows are untouched.
ALTER TABLE order_operation_idempotency DROP CONSTRAINT chk_order_operation_idempotency_operation;

ALTER TABLE order_operation_idempotency
    ADD CONSTRAINT chk_order_operation_idempotency_operation CHECK (operation IN ('claim', 'transition', 'release_owner', 'reassign_owner', 'authority_takeover', 'authority_release', 'qr_rotate'));
