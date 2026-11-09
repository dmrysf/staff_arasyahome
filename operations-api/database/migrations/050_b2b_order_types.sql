-- B2B order types and the order-level service route (API 2.67.0). DRAFT ONLY; NOT RUN IN PRODUCTION.
-- Requires 049_finance_owner_risk_limit_decisions.sql.
--
-- Owner decision (2026-11-09): a B2B commercial order has exactly one of three types.
--   FULL_TAILORING  Tam Dikim / Confectionare completa   the canonical route, stages 1 to 14
--   CUT_ONLY        Yalniz Kesim / Doar debitare        1 -> 2 -> 14: the stage 2 employee cuts, then Livrare
--   FULL_ROLL_BAG   Tam Poset-Rulo / Sac-rola intreaga  1 -> 2 -> 14: the stage 2 employee prepares the bag or roll,
--                                                        then Livrare
-- For the two short routes stages 3 to 13 are not applicable (recorded as such, never as completions).
--
-- Additive:
--   b2b_orders.order_type                 chosen on the draft, frozen at finalization (NULL on every existing order)
--   b2b_orders.finance_decision           finance decision snapshot written at finalization (NULL on existing orders)
--   b2b_production_handoffs.order_type    the frozen type copied at explicit production submission
--   operational_orders.service_route      the frozen type on the canonical production order; only source b2b may
--                                         carry one. NULL (every existing order, every other source) = full route.
--   production_route_decisions reason     adds 'order_service_route' (CHECK replaced, the other values unchanged)
-- No existing row is rewritten. A historical order without a type never gains a short route. No permission, role,
-- grant, employee, ledger, QR or stage history changes.
-- Re-runnable: every structural change is guarded by information_schema.
-- Rollback: an API without 2.67.0 ignores the columns; a short-route order already at delivery stays there.

-- ---- b2b_orders.order_type ----------------------------------------------------------------------------------------
SET @arasya_050_missing = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'b2b_orders' AND column_name = 'order_type');
SET @arasya_050_sql = IF(@arasya_050_missing,
    'ALTER TABLE b2b_orders ADD COLUMN order_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER currency_code, ADD CONSTRAINT chk_b2b_orders_order_type CHECK (order_type IS NULL OR order_type IN (''FULL_TAILORING'', ''CUT_ONLY'', ''FULL_ROLL_BAG''))',
    'DO 0');
PREPARE arasya_050_statement FROM @arasya_050_sql;
EXECUTE arasya_050_statement;
DEALLOCATE PREPARE arasya_050_statement;

-- ---- b2b_orders.finance_decision ----------------------------------------------------------------------------------
SET @arasya_050_missing = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'b2b_orders' AND column_name = 'finance_decision');
SET @arasya_050_sql = IF(@arasya_050_missing,
    'ALTER TABLE b2b_orders ADD COLUMN finance_decision LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL, ADD CONSTRAINT chk_b2b_orders_finance_decision CHECK (finance_decision IS NULL OR JSON_VALID(finance_decision))',
    'DO 0');
PREPARE arasya_050_statement FROM @arasya_050_sql;
EXECUTE arasya_050_statement;
DEALLOCATE PREPARE arasya_050_statement;

-- ---- b2b_production_handoffs.order_type ---------------------------------------------------------------------------
SET @arasya_050_missing = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'b2b_production_handoffs' AND column_name = 'order_type');
SET @arasya_050_sql = IF(@arasya_050_missing,
    'ALTER TABLE b2b_production_handoffs ADD COLUMN order_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD CONSTRAINT chk_b2b_production_handoffs_order_type CHECK (order_type IS NULL OR order_type IN (''FULL_TAILORING'', ''CUT_ONLY'', ''FULL_ROLL_BAG''))',
    'DO 0');
PREPARE arasya_050_statement FROM @arasya_050_sql;
EXECUTE arasya_050_statement;
DEALLOCATE PREPARE arasya_050_statement;

-- ---- operational_orders.service_route -----------------------------------------------------------------------------
-- Only an explicitly typed B2B order carries a service route. TrendHome, Outlet Perdele and Trendyol orders cannot.
SET @arasya_050_missing = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'operational_orders' AND column_name = 'service_route');
SET @arasya_050_sql = IF(@arasya_050_missing,
    'ALTER TABLE operational_orders ADD COLUMN service_route VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD CONSTRAINT chk_operational_orders_service_route CHECK (service_route IS NULL OR (source_key = ''b2b'' AND service_route IN (''FULL_TAILORING'', ''CUT_ONLY'', ''FULL_ROLL_BAG'')))',
    'DO 0');
PREPARE arasya_050_statement FROM @arasya_050_sql;
EXECUTE arasya_050_statement;
DEALLOCATE PREPARE arasya_050_statement;

-- ---- production_route_decisions.reason ----------------------------------------------------------------------------
-- 029 values unchanged; 'order_service_route' records stages 3-13 as not applicable because of the frozen order type.
SET @arasya_050_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'production_route_decisions' AND constraint_name = 'chk_production_route_decisions_reason');
SET @arasya_050_sql = IF(@arasya_050_old, 'ALTER TABLE production_route_decisions DROP CONSTRAINT chk_production_route_decisions_reason', 'DO 0');
PREPARE arasya_050_statement FROM @arasya_050_sql;
EXECUTE arasya_050_statement;
DEALLOCATE PREPARE arasya_050_statement;

SET @arasya_050_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'production_route_decisions' AND constraint_name = 'chk_production_route_decisions_reason_050');
SET @arasya_050_sql = IF(@arasya_050_new,
    'ALTER TABLE production_route_decisions ADD CONSTRAINT chk_production_route_decisions_reason_050 CHECK (reason IN (''product_route_policy'', ''classification_unverified'', ''plan_unverified'', ''order_service_route''))',
    'DO 0');
PREPARE arasya_050_statement FROM @arasya_050_sql;
EXECUTE arasya_050_statement;
DEALLOCATE PREPARE arasya_050_statement;
