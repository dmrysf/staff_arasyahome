-- Migration 061 (API 2.80.0, YD SOFT 2.32.0, owner decision 2026-11-25: finance accepts the real money): the finance
-- employee corrects the advance bank transfer they verified (`correct_transfer`, relayed to the shop like every finance
-- action and audited in order_finance_actions), and the finance employee's closed tasks are listed by decision time.
--
-- 1. The 044 action CHECK of order_finance_actions is replaced once to also accept `correct_transfer` (every other action
--    and every other CHECK of the table unchanged).
-- 2. One index on finance_verification_requests (decided_by_employee_uuid, decided_at) for the history of closed tasks.
--
-- No row is changed. Idempotent (each step checks information_schema first): the 044 CHECK is dropped whenever it is
-- present and the 061 CHECK added once, so a manual re-run of 044 followed by 061 leaves exactly one action CHECK. Rollback: an older API never sends
-- `correct_transfer`; the wider CHECK and the index stay (MySQL may use the index for the decider foreign key, so it is
-- never dropped).
SET @arasya_061_action_old = (SELECT COUNT(*) > 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_finance_actions' AND constraint_name = 'chk_order_finance_actions_action_044');
SET @arasya_061_action_new = (SELECT COUNT(*) = 0 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'order_finance_actions' AND constraint_name = 'chk_order_finance_actions_action_061');

SET @arasya_061_action_drop = IF(@arasya_061_action_old,
    'ALTER TABLE order_finance_actions DROP CONSTRAINT chk_order_finance_actions_action_044',
    'DO 0');
PREPARE arasya_061_action_drop_statement FROM @arasya_061_action_drop;
EXECUTE arasya_061_action_drop_statement;
DEALLOCATE PREPARE arasya_061_action_drop_statement;

SET @arasya_061_action_add = IF(@arasya_061_action_new,
    'ALTER TABLE order_finance_actions ADD CONSTRAINT chk_order_finance_actions_action_061 CHECK (action IN (''submit_transfer'', ''decide_transfer'', ''acknowledge_liability'', ''submit_refund'', ''decide_refund'', ''verify_transfer'', ''correct_transfer''))',
    'DO 0');
PREPARE arasya_061_action_add_statement FROM @arasya_061_action_add;
EXECUTE arasya_061_action_add_statement;
DEALLOCATE PREPARE arasya_061_action_add_statement;

SET @arasya_061_index = (SELECT COUNT(*) = 0 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'finance_verification_requests' AND index_name = 'idx_finance_verification_requests_decider');
SET @arasya_061_index_add = IF(@arasya_061_index,
    'ALTER TABLE finance_verification_requests ADD INDEX idx_finance_verification_requests_decider (decided_by_employee_uuid, decided_at)',
    'DO 0');
PREPARE arasya_061_index_statement FROM @arasya_061_index_add;
EXECUTE arasya_061_index_statement;
DEALLOCATE PREPARE arasya_061_index_statement;
