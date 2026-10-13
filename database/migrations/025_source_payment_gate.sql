-- Advance payment gate facts of signed commerce sources (Trend Home, Outlet Perdele).
-- Additive only: new nullable columns, no row is rewritten, no history changes. NULL means "the source has not
-- stated its payment facts yet" (every order ingested before this release), which a gate in ENFORCE mode treats
-- as closed: a missing statement is never read as a confirmed advance. `unverified` is a non-COD order whose payment
-- the source cannot confirm (unverified gateway, or a pending, on-hold or failed online payment); it is closed too.
-- Amounts are deliberately not stored: the factory needs only whether production may start, and WooCommerce stays
-- the payment record.
ALTER TABLE operational_orders
    ADD COLUMN payment_method VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER document_version,
    ADD COLUMN advance_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER payment_method,
    ADD COLUMN advance_revision INT UNSIGNED NULL AFTER advance_status,
    ADD COLUMN advance_confirmed_at DATETIME(6) NULL AFTER advance_revision,
    ADD COLUMN payment_synced_at DATETIME(6) NULL AFTER advance_confirmed_at,
    ADD COLUMN payment_reversed_at DATETIME(6) NULL AFTER payment_synced_at,
    ADD CONSTRAINT chk_operational_orders_payment_method CHECK (payment_method IS NULL OR payment_method IN ('cod', 'online', 'unverified')),
    ADD CONSTRAINT chk_operational_orders_advance_status CHECK (advance_status IS NULL OR advance_status IN ('not_required', 'waiting', 'partial', 'confirmed')),
    ADD CONSTRAINT chk_operational_orders_payment_complete CHECK ((payment_method IS NULL) = (advance_status IS NULL) AND (payment_method IS NULL) = (advance_revision IS NULL) AND (payment_method IS NULL) = (payment_synced_at IS NULL)),
    ADD CONSTRAINT chk_operational_orders_payment_online CHECK (payment_method IS NULL OR payment_method = 'cod' OR (payment_method = 'online' AND advance_status = 'not_required') OR (payment_method = 'unverified' AND advance_status = 'waiting')),
    ADD CONSTRAINT chk_operational_orders_advance_confirmed CHECK (advance_confirmed_at IS NULL OR advance_status = 'confirmed');
