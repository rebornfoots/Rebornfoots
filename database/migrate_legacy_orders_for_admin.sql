-- Run this file only when upgrading the original legacy orders table.
-- Do not run it after database/schema.sql, which already contains these fields.

ALTER TABLE orders
    ADD COLUMN status ENUM(
        'pending',
        'confirmed',
        'paid',
        'packed',
        'shipped',
        'delivered',
        'cancelled'
    ) NOT NULL DEFAULT 'pending' AFTER total,
    ADD COLUMN updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
        AFTER created_at,
    ADD INDEX idx_orders_phone (phone),
    ADD INDEX idx_orders_status_created (status, created_at);
