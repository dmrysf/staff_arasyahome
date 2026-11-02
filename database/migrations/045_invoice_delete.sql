-- Migration 045 (API 2.54.0): the routed shop employee deletes the order's SmartBill invoice (owner decision 2026-11-02).
--
-- No table, no row, no grant. Two CHECKs of order_invoice_actions are replaced once to accept one more value each: the
-- action (`delete`) and the reason code, which a deletion carries like a re-issue (order_changed, customer_data,
-- wrong_document, other). The table holds few rows (a short DDL lock); each swap is guarded by information_schema like
-- 039 and 044, so a re-run or an interrupted run completes and a completed run changes nothing. No row is read, written
-- or rewritten. Applied by bin/migrate.php with the API deploy, never by hand.
-- Rollback: older API versions never send `delete`; a recorded deletion is history they show as an unknown action. The
-- wider CHECKs stay in place (no DROP back); bin/check-rollback.php keeps the floor at 2.54.0 while a deletion is not
-- settled. Re-running 044 alone after this migration would add its narrower action CHECK again: run 045 after it.

SET @arasya_045_action_041 = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_invoice_actions' AND constraint_name = 'chk_order_invoice_actions_action');
SET @arasya_045_action_041_drop = IF(@arasya_045_action_041,
    'ALTER TABLE order_invoice_actions DROP CONSTRAINT chk_order_invoice_actions_action',
    'DO 0');
PREPARE arasya_045_action_041_statement FROM @arasya_045_action_041_drop;
EXECUTE arasya_045_action_041_statement;
DEALLOCATE PREPARE arasya_045_action_041_statement;

SET @arasya_045_action_044 = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_invoice_actions' AND constraint_name = 'chk_order_invoice_actions_action_044');
SET @arasya_045_action_044_drop = IF(@arasya_045_action_044,
    'ALTER TABLE order_invoice_actions DROP CONSTRAINT chk_order_invoice_actions_action_044',
    'DO 0');
PREPARE arasya_045_action_044_statement FROM @arasya_045_action_044_drop;
EXECUTE arasya_045_action_044_statement;
DEALLOCATE PREPARE arasya_045_action_044_statement;

SET @arasya_045_action_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_invoice_actions' AND constraint_name = 'chk_order_invoice_actions_action_045');
SET @arasya_045_action_add = IF(@arasya_045_action_new,
    'ALTER TABLE order_invoice_actions ADD CONSTRAINT chk_order_invoice_actions_action_045 CHECK (action IN (''issue'', ''send_mail'', ''reissue'', ''resolve'', ''proforma_issue'', ''proforma_send_mail'', ''proforma_check'', ''proforma_resolve'', ''proforma_cancel'', ''total_accept'', ''advance_accept'', ''advance_difference'', ''delete''))',
    'DO 0');
PREPARE arasya_045_action_add_statement FROM @arasya_045_action_add;
EXECUTE arasya_045_action_add_statement;
DEALLOCATE PREPARE arasya_045_action_add_statement;

SET @arasya_045_reason_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_invoice_actions' AND constraint_name = 'chk_order_invoice_actions_reason');
SET @arasya_045_reason_drop = IF(@arasya_045_reason_old,
    'ALTER TABLE order_invoice_actions DROP CONSTRAINT chk_order_invoice_actions_reason',
    'DO 0');
PREPARE arasya_045_reason_drop_statement FROM @arasya_045_reason_drop;
EXECUTE arasya_045_reason_drop_statement;
DEALLOCATE PREPARE arasya_045_reason_drop_statement;

SET @arasya_045_reason_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_invoice_actions' AND constraint_name = 'chk_order_invoice_actions_reason_045');
SET @arasya_045_reason_add = IF(@arasya_045_reason_new,
    'ALTER TABLE order_invoice_actions ADD CONSTRAINT chk_order_invoice_actions_reason_045 CHECK (reason_code IS NULL OR (action IN (''reissue'', ''delete'') AND reason_code IN (''order_changed'', ''customer_data'', ''wrong_document'', ''other'')) OR (action = ''proforma_cancel'' AND reason_code IN (''order_changed'', ''customer_request'', ''wrong_details'', ''duplicate'', ''other'')))',
    'DO 0');
PREPARE arasya_045_reason_add_statement FROM @arasya_045_reason_add;
EXECUTE arasya_045_reason_add_statement;
DEALLOCATE PREPARE arasya_045_reason_add_statement;
