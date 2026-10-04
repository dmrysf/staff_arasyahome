-- Production Control V1 (Operations API 2.5.0): read paths for the Dashboard order workspace.
-- Additive indexes only; no data change.
-- idx_operational_orders_created: keyset pagination of the order list, newest import first.
-- idx_operational_orders_commerce_status: commerce-status filter and its distinct-value list (covering).
ALTER TABLE operational_orders
    ADD KEY idx_operational_orders_created (created_at, order_uuid),
    ADD KEY idx_operational_orders_commerce_status (source_commerce_status_code, source_commerce_status_label);
