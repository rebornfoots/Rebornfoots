CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_key CHAR(36) NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    phone VARCHAR(15) NOT NULL,
    address VARCHAR(500) NOT NULL,
    postal_code CHAR(6) NOT NULL,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'UPI',
    gateway_order_id VARCHAR(100) NULL,
    gateway_payment_id VARCHAR(100) NULL,
    payment_status ENUM('created', 'paid', 'failed', 'refund_pending', 'refunded') NOT NULL DEFAULT 'created',
    order_details JSON NOT NULL,
    total DECIMAL(10, 2) UNSIGNED NOT NULL,
    inventory_deducted TINYINT(1) NOT NULL DEFAULT 0,
    courier_name VARCHAR(100) NOT NULL DEFAULT '',
    tracking_number VARCHAR(100) NOT NULL DEFAULT '',
    tracking_url VARCHAR(500) NOT NULL DEFAULT '',
    estimated_delivery_date DATE NULL,
    status ENUM('pending', 'confirmed', 'paid', 'packed', 'shipped', 'delivered', 'cancelled')
        NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_orders_request_key (request_key),
    UNIQUE INDEX idx_orders_gateway_order (gateway_order_id),
    UNIQUE INDEX idx_orders_gateway_payment (gateway_payment_id),
    INDEX idx_orders_phone (phone),
    INDEX idx_orders_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_pincodes (
    pincode CHAR(6) PRIMARY KEY,
    area_name VARCHAR(100) NOT NULL DEFAULT '',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id VARCHAR(64) PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(80) NOT NULL,
    description VARCHAR(500) NOT NULL,
    price DECIMAL(10, 2) UNSIGNED NULL,
    variant VARCHAR(100) NOT NULL DEFAULT '',
    badge VARCHAR(60) NOT NULL DEFAULT '',
    benefits JSON NULL,
    image_url VARCHAR(500) NOT NULL DEFAULT '',
    alt_text VARCHAR(250) NOT NULL DEFAULT '',
    purchasable TINYINT(1) NOT NULL DEFAULT 0,
    free_delivery TINYINT(1) NOT NULL DEFAULT 1,
    track_stock TINYINT(1) NOT NULL DEFAULT 0,
    stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    low_stock_threshold SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'confirmed', 'paid', 'packed', 'shipped', 'delivered', 'cancelled') NOT NULL,
    source ENUM('system', 'admin') NOT NULL DEFAULT 'system',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_status_history_order_created (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    event_id VARCHAR(100) PRIMARY KEY,
    event_type VARCHAR(80) NOT NULL,
    gateway_order_id VARCHAR(100) NOT NULL DEFAULT '',
    gateway_payment_id VARCHAR(100) NOT NULL DEFAULT '',
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_webhook_order (gateway_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
