-- Applied only by the simulation provisioner; never a production migration.
CREATE TABLE simulation_environment (
 singleton_id TINYINT NOT NULL PRIMARY KEY,
 database_name VARCHAR(128) NOT NULL,
 secret_hash CHAR(64) NOT NULL,
 session_id CHAR(12) NOT NULL,
 baseline_ms BIGINT NOT NULL,
 created_at DATETIME(6) NOT NULL,
 CONSTRAINT chk_sim_singleton CHECK (singleton_id = 1)
);
CREATE TABLE simulation_runs (
 scenario_id VARCHAR(40) NOT NULL PRIMARY KEY,
 replay_number INT NOT NULL DEFAULT 0,
 state VARCHAR(20) NOT NULL DEFAULT 'not_run',
 result VARCHAR(20) NOT NULL DEFAULT 'NOT VERIFIED',
 order_id VARCHAR(256) NULL,
 package_id VARCHAR(20) NULL,
 evidence_json LONGTEXT NULL,
 changed_at DATETIME(6) NULL
);
CREATE TABLE simulation_control_events (
 event_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 scenario_id VARCHAR(40) NOT NULL,
 replay_number INT NOT NULL,
 actor_uuid CHAR(36) NOT NULL,
 action VARCHAR(30) NOT NULL,
 created_at DATETIME(6) NOT NULL
);
