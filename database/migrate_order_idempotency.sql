-- Run once on an existing database to make checkout retries safe.

ALTER TABLE orders
    ADD COLUMN request_key CHAR(36) NULL AFTER id,
    ADD UNIQUE INDEX idx_orders_request_key (request_key);

