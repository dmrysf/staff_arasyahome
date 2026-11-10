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
--   b2b_production_handoffs.finance_decision  finance decision re-evaluated at production submission (FinanceGate
--                                         release check); NULL on every existing handoff
--   b2b_finance_gate_decisions            insert-only journal of every evaluated finance decision (shadow and enforce,
--                                         finalization and production submission), including refused attempts that
--                                         left no other trace because their transaction rolled back
--   operational_orders.service_route      the frozen type on the canonical production order; only source b2b may
--                                         carry one. NULL (every existing order, every other source) = full route.
--   production_route_decisions reason     adds 'order_service_route' (CHECK replaced, the other values unchanged)
-- No existing row is rewritten. A historical order without a type never gains a short route. No permission, role,
-- grant, employee, ledger, QR or stage history changes.
-- Re-runnable: every structural change is guarded by information_schema.
-- Rollback: an API without 2.67.0 ignores the columns, so bin/check-rollback.php requires 2.67.0 while a short-route
-- order is open before Livrare or finalized and not yet submitted. A short-route order at delivery stays there.

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

-- ---- b2b_production_handoffs.finance_decision ---------------------------------------------------------------------
SET @arasya_050_missing = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'b2b_production_handoffs' AND column_name = 'finance_decision');
SET @arasya_050_sql = IF(@arasya_050_missing,
    'ALTER TABLE b2b_production_handoffs ADD COLUMN finance_decision LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL, ADD CONSTRAINT chk_b2b_production_handoffs_finance_decision CHECK (finance_decision IS NULL OR JSON_VALID(finance_decision))',
    'DO 0');
PREPARE arasya_050_statement FROM @arasya_050_sql;
EXECUTE arasya_050_statement;
DEALLOCATE PREPARE arasya_050_statement;

-- ---- b2b_finance_gate_decisions ----------------------------------------------------------------------------------
-- Insert-only. One row per evaluated decision; mode off writes nothing. A BLOCKED row is written after the refused
-- transaction rolled back, so a refusal is auditable although the order itself did not change. No row is ever updated
-- or deleted by the application. It holds decision codes and the finance basis only: no contact data, no document.
CREATE TABLE IF NOT EXISTS b2b_finance_gate_decisions (
    decision_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    phase VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mode VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(28) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reasons_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    snapshot_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_name VARCHAR(190) NOT NULL,
    request_id VARCHAR(100) NOT NULL,
    decided_at DATETIME(6) NOT NULL,
    PRIMARY KEY (decision_uuid),
    KEY idx_b2b_finance_gate_order (order_uuid, decided_at),
    KEY idx_b2b_finance_gate_company (company_uuid, decided_at),
    KEY idx_b2b_finance_gate_outcome (outcome, decided_at),
    CONSTRAINT fk_b2b_finance_gate_order FOREIGN KEY (order_uuid) REFERENCES b2b_orders (order_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_b2b_finance_gate_company FOREIGN KEY (company_uuid) REFERENCES b2b_companies (company_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_b2b_finance_gate_actor FOREIGN KEY (actor_employee_uuid) REFERENCES employees (employee_uuid) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_b2b_finance_gate_phase CHECK (phase IN ('finalization', 'production_submission')),
    CONSTRAINT chk_b2b_finance_gate_mode CHECK (mode IN ('shadow', 'enforce')),
    CONSTRAINT chk_b2b_finance_gate_outcome CHECK (outcome IN ('ALLOWED', 'ALLOWED_BY_CEO_EXCEPTION', 'WOULD_BLOCK', 'BLOCKED')),
    CONSTRAINT chk_b2b_finance_gate_mode_outcome CHECK ((mode = 'shadow' AND outcome <> 'BLOCKED') OR (mode = 'enforce' AND outcome <> 'WOULD_BLOCK')),
    CONSTRAINT chk_b2b_finance_gate_reasons CHECK (JSON_VALID(reasons_json)),
    CONSTRAINT chk_b2b_finance_gate_snapshot CHECK (JSON_VALID(snapshot_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
