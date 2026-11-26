-- Migration 064 (API 2.83.0, Store Workspace V1, owner brief 2026-11-26): the shop employee's AWB correction and delivery
-- address correction, and the facts the store counters and the AWB history need.
--
-- 1. order_source_operations accepts two more kinds (the 044/059 kind CHECK is redefined under the SAME name, like 059):
--      awb_cancel      the AWB was cancelled at FAN Courier for a correction (YD SOFT 2.36.0 Operations awb_cancel: real
--                      FAN DELETE under the cancellation guard, only before the courier scanned the parcel); an applied
--                      awb_cancel later than the applied awb_create reopens the order's AWB step (awbDoneSql), the old AWB
--                      stays in the history marked "Anulat" and linked to the new one.
--      address_update  the delivery address/telephone was corrected (YD SOFT 2.36.0 Operations address_update).
-- 2. order_source_operations gains four nullable facts, written by API 2.83.0 only:
--      awb_number               the FAN Courier AWB an awb_create made (from the shop's answer) or an awb_cancel cancelled
--      parcels                  the parcels of an awb_create (what the employee asked for)
--      reason_code              the employee's reason of an awb_cancel
--      replaces_operation_uuid  on an awb_create: the awb_cancel it follows (the audit link old AWB -> new AWB)
--    and one index for the per-shop, per-day counters and the AWB history.
--
-- Additive: nullable columns, a wider CHECK, one index. No existing row changes. Idempotent (each step checks
-- information_schema first). Rollback: API 2.82.x never writes the new kinds or columns; an older API reading an applied
-- awb_cancel ignores it (the order would then count as "AWB made" again until root reopens it), so roll back only while no
-- awb_cancel row exists, or keep 2.83.0.

SET @arasya_064_done = (SELECT COUNT(*) > 0 FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'chk_order_source_operations_kind_044' AND check_clause LIKE '%address_update%');
SET @arasya_064_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_source_operations' AND constraint_name = 'chk_order_source_operations_kind_044');

SET @arasya_064_drop = IF(@arasya_064_old AND NOT @arasya_064_done,
    'ALTER TABLE order_source_operations DROP CONSTRAINT chk_order_source_operations_kind_044',
    'DO 0');
PREPARE arasya_064_drop_statement FROM @arasya_064_drop;
EXECUTE arasya_064_drop_statement;
DEALLOCATE PREPARE arasya_064_drop_statement;

SET @arasya_064_add = IF(NOT @arasya_064_done,
    'ALTER TABLE order_source_operations ADD CONSTRAINT chk_order_source_operations_kind_044 CHECK (kind IN (''order_reactivate'', ''awb_create'', ''awb_address_task'', ''awb_address_done'', ''awb_cod_corrected'', ''awb_reopened'', ''awb_cancel'', ''address_update'', ''card_refund_full'', ''card_refund_record'', ''card_refund_verify''))',
    'DO 0');
PREPARE arasya_064_add_statement FROM @arasya_064_add;
EXECUTE arasya_064_add_statement;
DEALLOCATE PREPARE arasya_064_add_statement;

SET @arasya_064_columns = (SELECT COUNT(*) = 0 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_source_operations' AND column_name = 'awb_number');
SET @arasya_064_columns_add = IF(@arasya_064_columns,
    'ALTER TABLE order_source_operations
        ADD COLUMN awb_number VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD COLUMN parcels SMALLINT UNSIGNED NULL,
        ADD COLUMN reason_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD COLUMN replaces_operation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD KEY idx_order_source_operations_shop_day (source_key, kind, state, completed_at),
        ADD KEY idx_order_source_operations_awb (awb_number),
        ADD CONSTRAINT chk_order_source_operations_store_064 CHECK ((awb_number IS NULL OR awb_number REGEXP ''^[0-9]{6,20}$'') AND (parcels IS NULL OR parcels BETWEEN 1 AND 20) AND (reason_code IS NULL OR reason_code REGEXP ''^[a-z_]{2,40}$''))',
    'DO 0');
PREPARE arasya_064_columns_statement FROM @arasya_064_columns_add;
EXECUTE arasya_064_columns_statement;
DEALLOCATE PREPARE arasya_064_columns_statement;
