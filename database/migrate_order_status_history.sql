CREATE TABLE IF NOT EXISTS order_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'confirmed', 'paid', 'packed', 'shipped', 'delivered', 'cancelled') NOT NULL,
    source ENUM('system', 'admin') NOT NULL DEFAULT 'system',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_status_history_order_created (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Give existing orders a baseline event without creating duplicates on reruns.
INSERT INTO order_status_history (order_id, status, source, created_at)
SELECT orders.id, orders.status, 'system', orders.updated_at
FROM orders
WHERE NOT EXISTS (
    SELECT 1 FROM order_status_history WHERE order_status_history.order_id = orders.id
);
