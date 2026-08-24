-- Run once to enable automatic PIN-code state lookup and state delivery rates.

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

INSERT INTO delivery_state_rates
    (state_name, delivery_fee, min_delivery_days, max_delivery_days, active)
VALUES
    ('Tamil Nadu', 80.00, 2, 4, 1),
    ('Kerala', 90.00, 3, 5, 1),
    ('Karnataka', 90.00, 3, 5, 1),
    ('Telangana', 90.00, 3, 5, 1),
    ('*', 500.00, 5, 8, 1)
ON DUPLICATE KEY UPDATE
    delivery_fee = VALUES(delivery_fee),
    min_delivery_days = VALUES(min_delivery_days),
    max_delivery_days = VALUES(max_delivery_days),
    active = VALUES(active);
