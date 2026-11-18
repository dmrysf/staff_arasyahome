-- Migration 056 (API 2.75.0): the order resolver permission and its role template.
--
-- Additive: one permission and one role template, granted to nobody. No table is created, altered or dropped, no row of
-- an order, a confirmation, a finance record or an employee is read or written.
--
--   permission orders.intervene
--        Use the order resolver in the Dashboard (owner decision 2026-11-18): look an order up by its number, see where
--        it waits and why, and press the one step that unblocks it. Every step goes through the root intervention desk
--        (the same services Staff uses) under the root identity; the employee who pressed it is named in the desk's
--        written reason and in the IAM audit (`intervention.resolve`). It includes recording and verifying an advance
--        found on the bank statement (owner decision 2026-11-18). Dashboard application only.
--   role order-resolver ("Rezolvare comenzi blocate")
--        A template with orders.intervene and orders.lookup_exact. No employee assignment: root assigns it through central
--        IAM (Dashboard, employee roles).
--
-- Rollback: an older API never reads the permission; the rows may stay (no DROP). Applied by bin/migrate.php with the API
-- deploy, never by hand.
INSERT INTO permissions (permission_key, category, label, description, role_grantable, created_at) VALUES
    ('orders.intervene', 'orders', 'Rezolvare comenzi blocate', 'Use the Dashboard order resolver: find where an order waits and press the one step that unblocks it (root intervention desk, audited)', 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE category = VALUES(category), label = VALUES(label), description = VALUES(description), role_grantable = VALUES(role_grantable);

INSERT IGNORE INTO roles (role_key, name, description, authority_rank, is_template, status, created_at, updated_at) VALUES
    ('order-resolver', 'Rezolvare comenzi blocate', 'Găsește unde s-a blocat o comandă și o deblochează cu un singur pas (Dashboard)', 450, 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));

INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.role_id, p.permission_id, UTC_TIMESTAMP(6)
FROM roles r
INNER JOIN permissions p ON p.permission_key IN ('orders.intervene', 'orders.lookup_exact')
WHERE r.role_key = 'order-resolver';
-- No employee assignment: root assigns the role through central IAM after review.
