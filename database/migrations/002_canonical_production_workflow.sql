CREATE TABLE IF NOT EXISTS production_workflows (
    workflow_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    workflow_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    version INT UNSIGNED NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (workflow_id),
    UNIQUE KEY uq_production_workflows_key (workflow_key),
    KEY idx_production_workflows_status (status),
    CONSTRAINT chk_production_workflows_version CHECK (version > 0),
    CONSTRAINT chk_production_workflows_status CHECK (status IN ('active', 'inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_stages (
    stage_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    workflow_id BIGINT UNSIGNED NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    ordinal SMALLINT UNSIGNED NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (stage_id),
    UNIQUE KEY uq_production_stages_workflow_ordinal (workflow_id, ordinal),
    KEY idx_production_stages_workflow_status_order (workflow_id, status, ordinal),
    CONSTRAINT fk_production_stages_workflow FOREIGN KEY (workflow_id) REFERENCES production_workflows (workflow_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_production_stages_ordinal CHECK (ordinal > 0),
    CONSTRAINT chk_production_stages_status CHECK (status IN ('active', 'inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
