-- Run once on an existing database to enable PIN-code serviceability.

ALTER TABLE orders
    ADD COLUMN postal_code CHAR(6) NULL AFTER address;

CREATE TABLE IF NOT EXISTS delivery_pincodes (
    pincode CHAR(6) PRIMARY KEY,
    area_name VARCHAR(100) NOT NULL DEFAULT '',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

