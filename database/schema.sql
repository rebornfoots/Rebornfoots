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
    subtotal DECIMAL(10, 2) UNSIGNED NOT NULL,
    delivery_fee DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00,
    coupon_code VARCHAR(30) NOT NULL DEFAULT '',
    coupon_discount DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00,
    combo_discount DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00,
    discount_total DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00,
    promotions_json JSON NULL,
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
    delivery_fee DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00,
    min_delivery_days TINYINT UNSIGNED NOT NULL DEFAULT 2,
    max_delivery_days TINYINT UNSIGNED NOT NULL DEFAULT 5,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_blocked_pincodes (
    pincode CHAR(6) PRIMARY KEY,
    reason VARCHAR(150) NOT NULL DEFAULT '',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS postal_pincodes (
    pincode CHAR(6) PRIMARY KEY,
    district VARCHAR(100) NOT NULL DEFAULT '',
    state_name VARCHAR(100) NOT NULL,
    area_name VARCHAR(120) NOT NULL DEFAULT '',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_postal_pincodes_state (state_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_state_rates (
    state_name VARCHAR(100) PRIMARY KEY,
    delivery_fee DECIMAL(10, 2) UNSIGNED NOT NULL,
    min_delivery_days TINYINT UNSIGNED NOT NULL,
    max_delivery_days TINYINT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO delivery_state_rates (state_name, delivery_fee, min_delivery_days, max_delivery_days, active) VALUES
    ('Tamil Nadu', 80.00, 2, 4, 1),
    ('Kerala', 90.00, 3, 5, 1),
    ('Karnataka', 90.00, 3, 5, 1),
    ('Telangana', 90.00, 3, 5, 1),
    ('*', 500.00, 5, 8, 1)
ON DUPLICATE KEY UPDATE delivery_fee = VALUES(delivery_fee),
    min_delivery_days = VALUES(min_delivery_days), max_delivery_days = VALUES(max_delivery_days), active = 1;

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

CREATE TABLE IF NOT EXISTS coupons (
    code VARCHAR(30) PRIMARY KEY,
    description VARCHAR(150) NOT NULL DEFAULT '',
    discount_type ENUM('percent', 'fixed') NOT NULL,
    discount_value DECIMAL(10, 2) UNSIGNED NOT NULL,
    min_subtotal DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00,
    max_discount DECIMAL(10, 2) UNSIGNED NULL,
    usage_limit INT UNSIGNED NULL,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_coupons_active_dates (active, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS combo_offers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    image_url VARCHAR(500) NOT NULL DEFAULT '',
    discount_type ENUM('percent', 'fixed') NOT NULL,
    discount_value DECIMAL(10, 2) UNSIGNED NOT NULL,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_combo_active_priority (active, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS combo_offer_items (
    combo_id BIGINT UNSIGNED NOT NULL,
    product_id VARCHAR(64) NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (combo_id, product_id),
    INDEX idx_combo_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
