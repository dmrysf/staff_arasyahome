-- Confirmare customer contact attempts (API 2.39.0, Phase 3). Additive and re-runnable: one new table, nothing else is
-- created, changed or deleted, nobody receives a scope, and a source still cannot be activated
-- (ConfirmationGate::WORKFLOW_READY = false).
--
--   order_confirmation_contacts
--        Append-only record of each telephone contact attempt an operator made and recorded on a Confirmare order:
--          confirmed          the customer confirmed the order on the telephone;
--          no_answer          nobody answered;
--          callback           the customer asked to be called again later;
--          needs_correction   the order must be corrected in the shop before it can be confirmed.
--        Each row names the employee who recorded it, the review fingerprint of the order as it was during the call
--        (a later change in the shop makes the attempt stale) and the confirmation version. A row is written only by an
--        explicit operator command, never by opening a screen or a telephone link. No customer data, no free text, no
--        call duration and no recording. No code path updates or deletes a row. The approval of a Confirmare order needs
--        the latest attempt to be `confirmed` on the current review (API 2.39.0).
--        attempt_number orders the attempts of one order (written under the order row lock); the Idempotency-Key of
--        the recording command is unique per employee, so a retried request never records a second attempt.

CREATE TABLE IF NOT EXISTS order_confirmation_contacts (
    contact_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    global_order_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt_number INT UNSIGNED NOT NULL,
    outcome VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    review_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    confirmation_version INT UNSIGNED NOT NULL,
    request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash BINARY(32) NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (contact_uuid),
    UNIQUE KEY uq_order_confirmation_contacts_attempt (order_uuid, attempt_number),
    UNIQUE KEY uq_order_confirmation_contacts_key (employee_uuid, idempotency_key),
    KEY idx_order_confirmation_contacts_source_time (source_key, recorded_at),
    CONSTRAINT fk_order_confirmation_contacts_order FOREIGN KEY (order_uuid) REFERENCES operational_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_confirmation_contacts_employee FOREIGN KEY (employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_order_confirmation_contacts_outcome CHECK (outcome IN ('confirmed', 'no_answer', 'callback', 'needs_correction')),
    CONSTRAINT chk_order_confirmation_contacts_attempt CHECK (attempt_number > 0 AND confirmation_version > 0),
    CONSTRAINT chk_order_confirmation_contacts_review CHECK (review_fingerprint REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
