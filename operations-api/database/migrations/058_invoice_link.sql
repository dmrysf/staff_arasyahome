-- Migration 058 (API 2.79.0): Operations records a SmartBill final invoice that already exists (made outside Arasya) once
-- the shop linked it to its order (owner brief 2026-11-23, recovery orders' final invoices; YD SOFT 2.34.0
-- `wp yd-arasya invoice link`, then root's bin/link-invoice.php).
--
-- No table, no row, no grant. The action CHECK of order_invoice_actions is replaced once to accept one more value: `link`
-- (an applied row with result `issued`, recorded by root, counted by ConfirmareInvoice::ISSUED_SQL). The table holds few
-- rows (a short DDL lock); the swap is guarded by information_schema like 045, so a re-run or an interrupted run
-- completes and a completed run changes nothing. No row is read, written or rewritten. Applied by bin/migrate.php with
-- the API deploy, never by hand.
-- Rollback: an older API never writes `link`; a recorded link reads there as an unknown action in the timeline and does
-- not count as an invoice (the shop still refuses a second invoice: document_exists). The wider CHECK stays (no DROP
-- back). Re-running 045 alone after this migration would add its narrower CHECK again: run 058 after it.

SET @arasya_058_action_045 = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_invoice_actions' AND constraint_name = 'chk_order_invoice_actions_action_045');
SET @arasya_058_action_045_drop = IF(@arasya_058_action_045,
    'ALTER TABLE order_invoice_actions DROP CONSTRAINT chk_order_invoice_actions_action_045',
    'DO 0');
PREPARE arasya_058_action_045_statement FROM @arasya_058_action_045_drop;
EXECUTE arasya_058_action_045_statement;
DEALLOCATE PREPARE arasya_058_action_045_statement;

SET @arasya_058_action_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_invoice_actions' AND constraint_name = 'chk_order_invoice_actions_action_058');
SET @arasya_058_action_add = IF(@arasya_058_action_new,
    'ALTER TABLE order_invoice_actions ADD CONSTRAINT chk_order_invoice_actions_action_058 CHECK (action IN (''issue'', ''send_mail'', ''reissue'', ''resolve'', ''proforma_issue'', ''proforma_send_mail'', ''proforma_check'', ''proforma_resolve'', ''proforma_cancel'', ''total_accept'', ''advance_accept'', ''advance_difference'', ''delete'', ''link''))',
    'DO 0');
PREPARE arasya_058_action_add_statement FROM @arasya_058_action_add;
EXECUTE arasya_058_action_add_statement;
DEALLOCATE PREPARE arasya_058_action_add_statement;
