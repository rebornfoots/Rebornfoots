-- Run once before enabling the admin refund action.

ALTER TABLE orders
    MODIFY COLUMN payment_status
        ENUM('created', 'paid', 'failed', 'refund_pending', 'refunded')
        NOT NULL DEFAULT 'created';

CREATE TABLE IF NOT EXISTS payment_refunds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    gateway_payment_id VARCHAR(100) NOT NULL,
    gateway_refund_id VARCHAR(100) NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    amount_paise INT UNSIGNED NOT NULL,
    status ENUM('initiating', 'pending', 'processed', 'failed') NOT NULL DEFAULT 'initiating',
    failure_reason VARCHAR(500) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_payment_refunds_order (order_id),
    UNIQUE INDEX idx_payment_refunds_gateway (gateway_refund_id),
    UNIQUE INDEX idx_payment_refunds_idempotency (idempotency_key),
    INDEX idx_payment_refunds_payment (gateway_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
