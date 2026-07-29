CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(100) NOT NULL,
    phone VARCHAR(15) NOT NULL,
    address VARCHAR(500) NOT NULL,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'UPI',
    order_details JSON NOT NULL,
    total DECIMAL(10, 2) UNSIGNED NOT NULL,
    status ENUM('pending', 'confirmed', 'paid', 'packed', 'shipped', 'delivered', 'cancelled')
        NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_orders_phone (phone),
    INDEX idx_orders_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
