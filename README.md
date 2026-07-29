# InbornFoot

A lightweight, responsive storefront for traditional South Indian dairy products
and palm jaggery. The frontend uses semantic HTML, custom CSS and vanilla
JavaScript. Orders are validated by PHP, stored in MySQL and optionally announced
through Telegram.

## Requirements

- IIS or another web server with PHP 8.1+
- PHP extensions: `mysqli`, `mbstring`, `json` and preferably `curl`
- MySQL 5.7+ or MySQL 8+
- HTTPS in production

## Local setup

1. Create a MySQL database and run `database/schema.sql`.
2. Configure these environment variables in the PHP/IIS process:

   - `F2H_DB_HOST`
   - `F2H_DB_NAME`
   - `F2H_DB_USER`
   - `F2H_DB_PASSWORD`
   - `F2H_TELEGRAM_BOT_TOKEN` (optional)
   - `F2H_TELEGRAM_CHAT_ID` (optional)
   - `F2H_ADMIN_USERNAME`
   - `F2H_ADMIN_PASSWORD_HASH`

3. Serve this directory through a PHP-capable web server. For PHP's local server:

   ```sh
   php -S localhost:8080
   ```

4. Open `http://localhost:8080`.

`.env.example` documents the variables, but `place_order.php` intentionally reads
server environment variables rather than parsing an exposed web-root `.env` file.

## Admin dashboard

The protected dashboard is available at `/admin/` and includes:

- Orders and revenue overview
- Database-backed product, price and availability management
- Search by order number, customer name, or phone
- Status filtering and paginated order results
- Order delivery and item details
- Status updates protected by CSRF tokens
- Filtered CSV export
- Automatic logout after 30 minutes of inactivity

Generate a secure password hash without placing the plain password in source code:

```sh
php -r "echo password_hash('your-long-unique-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Set the resulting value as `F2H_ADMIN_PASSWORD_HASH` and set the desired login
name as `F2H_ADMIN_USERNAME`. The login screen remains disabled until both exist.
In production, `/admin/` must only be used over HTTPS.

For a database created with an older version of this project, run
`database/migrate_legacy_orders_for_admin.sql` once before opening the dashboard.
Fresh installations using `database/schema.sql` do not need the migration.

Run `database/migrate_products.sql` once to add and seed the product catalogue on
an existing database. The migration is idempotent, but its seed values overwrite
matching product fields, so use the admin dashboard for later catalogue changes.

Products are managed at `/admin/products.php`. The public `products.php` endpoint
returns only active products and is cached briefly for performance. The storefront
keeps its built-in HTML catalogue as a resilient fallback if the API is unavailable.
Checkout never trusts browser prices: it reloads current price, availability and
purchase eligibility from MySQL before creating every order.

Run `database/migrate_inventory.sql` once after the product migration to enable:

- Secure JPEG, PNG and WebP uploads up to 5 MB
- Optional stock tracking per product or pack-size variant
- Low-stock and out-of-stock storefront states
- Checkout quantity validation against current stock
- Transaction-safe stock deduction when an order is confirmed
- Automatic stock restoration when a deducted order is cancelled

Stock tracking is off by default. Enable it only after entering a verified available
quantity. Different pack sizes should be created as separate product records with
unique IDs so each variant has an independent price and stock count.

Run `database/migrate_product_delivery_and_catalog_data.sql` once after the inventory
migration to apply the current six-product prices, pack sizes, stock levels, warning
thresholds and free-delivery settings.

## Customer order tracking

Customers can track an order at `/track-order/` using the order number and the
matching checkout phone number. The page shows the current fulfilment stage, items,
total and last update, but never exposes the delivery address.

Tracking lookups use prepared queries, CSRF protection, generic failure messages,
session-based throttling, private no-store responses and search-engine blocking.
Cancelled orders display the support phone number instead of an active timeline.
Every new order and admin status change is recorded in `order_status_history` in
the same transaction as the underlying change. Run
`database/migrate_order_status_history.sql` once on an existing database to create
the audit table and backfill one baseline event per existing order.

After checkout, customers receive a clear on-screen receipt containing their order
number and a direct link to the tracking page. The admin orders table includes a
one-click WhatsApp action with a prefilled status update. This opens WhatsApp for
manual review and sending; it does not send messages automatically or require API
credentials.

## Order security

- The browser submits only product IDs and quantities.
- The PHP endpoint owns the product catalogue and authoritative prices.
- Every field and quantity is validated before insertion.
- SQL writes use a prepared statement.
- Customer content is escaped before Telegram HTML is generated.
- Detailed database failures are logged server-side and not returned to customers.

## Before production

The credentials previously committed to this repository must be considered
compromised. Rotate the database password and Telegram bot token before deploying.
If the Git remote was shared, remove the old secrets from repository history as a
separate, coordinated maintenance action.

Replace the externally hosted product photography with licensed, locally
optimized images before production.
