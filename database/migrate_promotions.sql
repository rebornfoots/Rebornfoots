-- Run once before enabling coupons and combo offers.

ALTER TABLE orders
    ADD COLUMN coupon_code VARCHAR(30) NOT NULL DEFAULT '' AFTER delivery_fee,
    ADD COLUMN coupon_discount DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER coupon_code,
    ADD COLUMN combo_discount DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER coupon_discount,
    ADD COLUMN discount_total DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER combo_discount,
    ADD COLUMN promotions_json JSON NULL AFTER discount_total;

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

