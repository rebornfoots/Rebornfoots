-- Run once on an existing database before enabling Razorpay checkout.

ALTER TABLE orders
    ADD COLUMN gateway_order_id VARCHAR(100) NULL AFTER payment_method,
    ADD COLUMN gateway_payment_id VARCHAR(100) NULL AFTER gateway_order_id,
    ADD COLUMN payment_status ENUM('created', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'created' AFTER gateway_payment_id,
    ADD UNIQUE INDEX idx_orders_gateway_order (gateway_order_id),
    ADD UNIQUE INDEX idx_orders_gateway_payment (gateway_payment_id);
