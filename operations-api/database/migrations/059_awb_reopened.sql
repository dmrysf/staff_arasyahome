-- Migration 059 (API 2.77.8, owner 2026-11-25, Trend Home 64790): the `awb_reopened` kind of order_source_operations.
--
-- An AWB made through Staff and later cancelled in the shop (WP admin, FAN Courier panel) left its applied `awb_create`
-- request behind, and every AWB queue counted it as "AWB made" for ever. The root intervention desk records an
-- `awb_reopened` row after the shop answers it has no AWB (ConfirmareOperations::reopenAwb); an applied `awb_create` older
-- than the latest applied `awb_reopened` no longer counts.
--
-- The 044 kind CHECK (chk_order_source_operations_kind_044) is redefined under the SAME name to also accept `awb_reopened`,
-- so a later idempotent run of 044 (test suites re-run it) finds its constraint and adds nothing. Nothing else changes: no
-- column, no row. Idempotent: skipped once the constraint already names `awb_reopened`. Rollback: the 044 definition can be
-- put back only while no `awb_reopened` row exists.
SET @arasya_059_done = (SELECT COUNT(*) > 0 FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'chk_order_source_operations_kind_044' AND check_clause LIKE '%awb_reopened%');
SET @arasya_059_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_source_operations' AND constraint_name = 'chk_order_source_operations_kind_044');

SET @arasya_059_drop = IF(@arasya_059_old AND NOT @arasya_059_done,
    'ALTER TABLE order_source_operations DROP CONSTRAINT chk_order_source_operations_kind_044',
    'DO 0');
PREPARE arasya_059_drop_statement FROM @arasya_059_drop;
EXECUTE arasya_059_drop_statement;
DEALLOCATE PREPARE arasya_059_drop_statement;

SET @arasya_059_add = IF(NOT @arasya_059_done,
    'ALTER TABLE order_source_operations ADD CONSTRAINT chk_order_source_operations_kind_044 CHECK (kind IN (''order_reactivate'', ''awb_create'', ''awb_address_task'', ''awb_address_done'', ''awb_cod_corrected'', ''awb_reopened'', ''card_refund_full'', ''card_refund_record'', ''card_refund_verify''))',
    'DO 0');
PREPARE arasya_059_add_statement FROM @arasya_059_add;
EXECUTE arasya_059_add_statement;
DEALLOCATE PREPARE arasya_059_add_statement;
