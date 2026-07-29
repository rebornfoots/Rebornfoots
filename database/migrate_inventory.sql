-- Run once on an existing development database after migrate_products.sql.
-- Stock tracking defaults to off so existing products remain purchasable.

ALTER TABLE products
    ADD COLUMN track_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER purchasable,
    ADD COLUMN stock_quantity INT UNSIGNED NOT NULL DEFAULT 0 AFTER track_stock,
    ADD COLUMN low_stock_threshold SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER stock_quantity;

ALTER TABLE orders
    ADD COLUMN inventory_deducted TINYINT(1) NOT NULL DEFAULT 0 AFTER total;
