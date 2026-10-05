-- B2B Current Account V1: an internal commercial receivables ledger per company and currency.
-- Additive only. Older API code ignores these tables; keep them (and every row) on rollback.
-- Movements, allocations, releases and activity are insert-only: the application never updates or deletes them,
-- and no foreign key cascades. Corrections are reversal movements; reallocation is a release row plus a new allocation.
CREATE TABLE IF NOT EXISTS b2b_account_movement_sequence (
 movement_number BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(movement_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS b2b_account_movements (
 movement_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 movement_number BIGINT UNSIGNED NOT NULL,
 movement_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 movement_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 direction VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount DECIMAL(16,2) NOT NULL,
 value_date DATE NOT NULL,
 order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 -- Set only on an order receivable: one finalized order can never post two receivables.
 receivable_order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 -- Set only on a reversal: a movement can never be reversed twice.
 reversed_movement_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 payment_method VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
 external_reference VARCHAR(160) NULL,
 note TEXT NULL,
 source_snapshot JSON NOT NULL,
 created_at DATETIME(6) NOT NULL,
 created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by_name VARCHAR(160) NOT NULL,
 PRIMARY KEY(movement_uuid),
 UNIQUE KEY uq_b2b_account_movement_number(movement_number),
 UNIQUE KEY uq_b2b_account_movement_code(movement_code),
 UNIQUE KEY uq_b2b_account_receivable_order(receivable_order_uuid),
 UNIQUE KEY uq_b2b_account_reversed_movement(reversed_movement_uuid),
 KEY idx_b2b_account_statement(company_uuid,currency_code,value_date,movement_number),
 KEY idx_b2b_account_company_type(company_uuid,movement_type,currency_code),
 KEY idx_b2b_account_order(order_uuid),
 CONSTRAINT fk_b2b_account_movement_number FOREIGN KEY(movement_number) REFERENCES b2b_account_movement_sequence(movement_number),
 CONSTRAINT fk_b2b_account_movement_company FOREIGN KEY(company_uuid) REFERENCES b2b_companies(company_uuid),
 CONSTRAINT fk_b2b_account_movement_order FOREIGN KEY(order_uuid) REFERENCES b2b_orders(order_uuid),
 CONSTRAINT fk_b2b_account_movement_receivable FOREIGN KEY(receivable_order_uuid) REFERENCES b2b_orders(order_uuid),
 CONSTRAINT fk_b2b_account_movement_reversed FOREIGN KEY(reversed_movement_uuid) REFERENCES b2b_account_movements(movement_uuid),
 CONSTRAINT fk_b2b_account_movement_actor FOREIGN KEY(created_by_employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT chk_b2b_account_currency CHECK(currency_code IN ('RON','EUR')),
 CONSTRAINT chk_b2b_account_type CHECK(movement_type IN ('order_receivable','payment','opening_balance','adjustment','reversal')),
 CONSTRAINT chk_b2b_account_direction CHECK(direction IN ('debit','credit')),
 CONSTRAINT chk_b2b_account_amount CHECK(amount>0),
 CONSTRAINT chk_b2b_account_method CHECK(payment_method IS NULL OR payment_method IN ('bank_transfer','cash','card','compensation','other')),
 -- Every branch is written so that a NULL can never make the whole check pass.
 CONSTRAINT chk_b2b_account_shape CHECK(
   (movement_type='order_receivable' AND direction='debit' AND order_uuid IS NOT NULL AND receivable_order_uuid IS NOT NULL
     AND receivable_order_uuid=order_uuid AND reversed_movement_uuid IS NULL AND payment_method IS NULL) OR
   (movement_type='payment' AND direction='credit' AND payment_method IS NOT NULL
     AND receivable_order_uuid IS NULL AND reversed_movement_uuid IS NULL AND order_uuid IS NULL) OR
   (movement_type IN ('opening_balance','adjustment') AND payment_method IS NULL
     AND receivable_order_uuid IS NULL AND reversed_movement_uuid IS NULL AND order_uuid IS NULL) OR
   (movement_type='reversal' AND reversed_movement_uuid IS NOT NULL AND payment_method IS NULL AND receivable_order_uuid IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS b2b_account_allocations (
 allocation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payment_movement_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 receivable_movement_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount DECIMAL(16,2) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 created_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by_name VARCHAR(160) NOT NULL,
 PRIMARY KEY(allocation_uuid),
 KEY idx_b2b_account_allocation_payment(payment_movement_uuid),
 KEY idx_b2b_account_allocation_receivable(receivable_movement_uuid),
 KEY idx_b2b_account_allocation_company(company_uuid,created_at),
 CONSTRAINT fk_b2b_account_allocation_company FOREIGN KEY(company_uuid) REFERENCES b2b_companies(company_uuid),
 CONSTRAINT fk_b2b_account_allocation_payment FOREIGN KEY(payment_movement_uuid) REFERENCES b2b_account_movements(movement_uuid),
 CONSTRAINT fk_b2b_account_allocation_receivable FOREIGN KEY(receivable_movement_uuid) REFERENCES b2b_account_movements(movement_uuid),
 CONSTRAINT fk_b2b_account_allocation_actor FOREIGN KEY(created_by_employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT chk_b2b_account_allocation_currency CHECK(currency_code IN ('RON','EUR')),
 CONSTRAINT chk_b2b_account_allocation_amount CHECK(amount>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An allocation is released at most once (primary key); the allocation row itself never changes.
CREATE TABLE IF NOT EXISTS b2b_account_allocation_releases (
 allocation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 release_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reason TEXT NULL,
 released_at DATETIME(6) NOT NULL,
 released_by_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 released_by_name VARCHAR(160) NOT NULL,
 PRIMARY KEY(allocation_uuid),
 CONSTRAINT fk_b2b_account_release_allocation FOREIGN KEY(allocation_uuid) REFERENCES b2b_account_allocations(allocation_uuid),
 CONSTRAINT fk_b2b_account_release_actor FOREIGN KEY(released_by_employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT chk_b2b_account_release_kind CHECK(release_kind IN ('manual','payment_reversed','receivable_reversed')),
 CONSTRAINT chk_b2b_account_release_reason CHECK(release_kind<>'manual' OR reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS b2b_account_activity_events (
 event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 movement_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 allocation_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 order_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
 actor_employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_name VARCHAR(160) NOT NULL,
 action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 occurred_at DATETIME(6) NOT NULL,
 PRIMARY KEY(event_id),
 KEY idx_b2b_account_activity_company(company_uuid,occurred_at,event_id),
 KEY idx_b2b_account_activity_movement(movement_uuid),
 CONSTRAINT fk_b2b_account_activity_company FOREIGN KEY(company_uuid) REFERENCES b2b_companies(company_uuid),
 CONSTRAINT fk_b2b_account_activity_movement FOREIGN KEY(movement_uuid) REFERENCES b2b_account_movements(movement_uuid),
 CONSTRAINT fk_b2b_account_activity_allocation FOREIGN KEY(allocation_uuid) REFERENCES b2b_account_allocations(allocation_uuid),
 CONSTRAINT fk_b2b_account_activity_order FOREIGN KEY(order_uuid) REFERENCES b2b_orders(order_uuid),
 CONSTRAINT fk_b2b_account_activity_actor FOREIGN KEY(actor_employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT chk_b2b_account_activity_action CHECK(action IN (
  'receivable_posted','receivable_reversed','payment_recorded','opening_balance_posted','adjustment_posted',
  'movement_reversed','allocation_created','allocation_released'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS b2b_account_idempotency (
 employee_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash BINARY(32) NOT NULL,
 company_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 response_json MEDIUMTEXT NOT NULL,
 created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(employee_uuid,idempotency_key),
 KEY idx_b2b_account_idempotency_retention(created_at),
 CONSTRAINT fk_b2b_account_idempotency_actor FOREIGN KEY(employee_uuid) REFERENCES employees(employee_uuid),
 CONSTRAINT fk_b2b_account_idempotency_company FOREIGN KEY(company_uuid) REFERENCES b2b_companies(company_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions(permission_key,category,label,description,role_grantable,created_at) VALUES
 ('b2b.accounts.view','b2b','Vizualizare conturi curente B2B','View company current accounts, balances, movements, allocations and statements',1,UTC_TIMESTAMP(6)),
 ('b2b.accounts.record_payment','b2b','Înregistrare plăți B2B','Record payments and allocate them to posted order receivables',1,UTC_TIMESTAMP(6)),
 ('b2b.accounts.adjust','b2b','Ajustări cont curent B2B','Post opening balances and manual debit or credit adjustments',1,UTC_TIMESTAMP(6)),
 ('b2b.accounts.reverse','b2b','Stornare mișcări cont curent B2B','Reverse posted current account movements',1,UTC_TIMESTAMP(6)),
 ('b2b.accounts.export','b2b','Export extras de cont B2B','Export current account statements as CSV or PDF',1,UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE permission_key=permission_key;
