INSERT IGNORE INTO production_workflows (workflow_key, name, version, status, created_at, updated_at)
VALUES ('curtain-production', 'Flux producție Arasya', 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));

INSERT IGNORE INTO production_stages (stage_id, workflow_id, display_name, ordinal, status, created_at, updated_at)
SELECT catalog.stage_id, workflow.workflow_id, catalog.display_name, catalog.ordinal, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
FROM production_workflows workflow
INNER JOIN (
    SELECT 'waiting' AS stage_id, 'În așteptare' AS display_name, 1 AS ordinal
    UNION ALL SELECT 'material-preparation', 'Pregătire material', 2
    UNION ALL SELECT 'workshop-receiving', 'Primire atelier', 3
    UNION ALL SELECT 'labeling', 'Etichetare', 4
    UNION ALL SELECT 'material-straightening', 'Îndreptare material', 5
    UNION ALL SELECT 'bottom-hem', 'Tivul de jos', 6
    UNION ALL SELECT 'side-hem', 'Tivul lateral', 7
    UNION ALL SELECT 'ironing', 'Călcare', 8
    UNION ALL SELECT 'height', 'Înălțime', 9
    UNION ALL SELECT 'header-tape', 'Rejansă', 10
    UNION ALL SELECT 'sewing-finishing', 'Finisare coasere', 11
    UNION ALL SELECT 'quality-control', 'Control calitate', 12
    UNION ALL SELECT 'packing', 'Împachetare', 13
    UNION ALL SELECT 'delivery', 'Livrare', 14
) catalog
WHERE workflow.workflow_key = 'curtain-production';
