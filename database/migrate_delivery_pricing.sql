-- Run once before enabling PIN-code delivery pricing.

ALTER TABLE delivery_pincodes
    ADD COLUMN delivery_fee DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER area_name,
    ADD COLUMN min_delivery_days TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER delivery_fee,
    ADD COLUMN max_delivery_days TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER min_delivery_days;

ALTER TABLE orders
    ADD COLUMN subtotal DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER order_details,
    ADD COLUMN delivery_fee DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER subtotal;

UPDATE orders SET subtotal = total WHERE subtotal = 0;

