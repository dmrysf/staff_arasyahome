-- Migration 042 (API 2.45.0): the shop connector's latest signed readiness report for a Confirmare activation.
--
-- ConfirmationGate::WORKFLOW_READY (a release-wide "nothing can approve an order yet" switch of Phase 1) is replaced by a
-- per-source readiness (Production\ConfirmareReadiness). Part of it is the shop's own side, which only the shop knows: its
-- YD SOFT version, its source access, finance and invoice modes, whether its access schema and SmartBill are ready and
-- whether the accountant's written decision is recorded for SmartBill `enforce`. YD SOFT 2.19.0 sends it in the signed
-- heartbeat (connectorReadiness) once the server announces it; the latest report wins. A report never activates anything:
-- only the operator CLI (bin/workflow-cohort.php) does, after every check passes.
--
-- Additive only: one new table, no row, no grant, no change to any other table. CREATE TABLE IF NOT EXISTS: running it
-- again changes nothing. Applied by bin/migrate.php with the API deploy, never by hand. Before it exists no source can be
-- activated (the readiness check `schema` fails: fail closed). Rollback: the table is unused by older API versions and is
-- left in place (no DROP).
CREATE TABLE IF NOT EXISTS source_connector_readiness (
    source_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    connector_version VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    access_mode VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    finance_mode VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    invoice_mode VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    schema_ready TINYINT(1) NOT NULL,
    invoice_attested TINYINT(1) NOT NULL,
    smartbill_ready TINYINT(1) NOT NULL,
    reported_at DATETIME(6) NOT NULL,
    PRIMARY KEY (source_key),
    CONSTRAINT fk_source_connector_readiness_source FOREIGN KEY (source_key) REFERENCES order_sources (source_key) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_source_connector_readiness_modes CHECK (access_mode IN ('off', 'read', 'edit') AND finance_mode IN ('off', 'read', 'record') AND invoice_mode IN ('off', 'observe', 'enforce')),
    CONSTRAINT chk_source_connector_readiness_flags CHECK (schema_ready IN (0, 1) AND invoice_attested IN (0, 1) AND smartbill_ready IN (0, 1)),
    CONSTRAINT chk_source_connector_readiness_version CHECK (connector_version REGEXP '^[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
