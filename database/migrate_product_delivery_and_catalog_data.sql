-- Run once after migrate_products.sql and migrate_inventory.sql.

ALTER TABLE products
    ADD COLUMN free_delivery TINYINT(1) NOT NULL DEFAULT 1 AFTER purchasable;

UPDATE products
SET price = 200.00,
    variant = '1 litre',
    purchasable = 1,
    free_delivery = 1,
    track_stock = 1,
    stock_quantity = 50,
    low_stock_threshold = 10
WHERE id IN ('peanut-oil', 'coconut-oil', 'sesame-oil', 'lamp-oil');

UPDATE products
SET price = 200.00,
    variant = '500 g',
    purchasable = 1,
    free_delivery = 1,
    track_stock = 1,
    stock_quantity = 100,
    low_stock_threshold = 20
WHERE id = 'turmeric-powder';

UPDATE products
SET price = 200.00,
    variant = '100 g',
    purchasable = 1,
    free_delivery = 1,
    track_stock = 1,
    stock_quantity = 100,
    low_stock_threshold = 20
WHERE id = 'organic-soap';
