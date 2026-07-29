-- Run once on an existing database to enable courier tracking details.

ALTER TABLE orders
    ADD COLUMN courier_name VARCHAR(100) NOT NULL DEFAULT '' AFTER inventory_deducted,
    ADD COLUMN tracking_number VARCHAR(100) NOT NULL DEFAULT '' AFTER courier_name,
    ADD COLUMN tracking_url VARCHAR(500) NOT NULL DEFAULT '' AFTER tracking_number,
    ADD COLUMN estimated_delivery_date DATE NULL AFTER tracking_url;

