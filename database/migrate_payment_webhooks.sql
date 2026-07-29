CREATE TABLE IF NOT EXISTS payment_webhook_events (
    event_id VARCHAR(100) PRIMARY KEY,
    event_type VARCHAR(80) NOT NULL,
    gateway_order_id VARCHAR(100) NOT NULL DEFAULT '',
    gateway_payment_id VARCHAR(100) NOT NULL DEFAULT '',
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_webhook_order (gateway_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

