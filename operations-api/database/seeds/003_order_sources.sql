INSERT INTO order_sources (source_key, source_type, display_name, schema_version, status, created_at, updated_at) VALUES 
('trendhome', 'woocommerce', 'Trendhome', 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
('outletperdele', 'woocommerce', 'OutletPerdele', 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)),
('trendyol', 'marketplace', 'Trendyol', 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE 
    display_name = VALUES(display_name),
    updated_at = VALUES(updated_at);
