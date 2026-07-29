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

3. Serve this directory through a PHP-capable web server. For PHP's local server:

   ```sh
   php -S localhost:8080
   ```

4. Open `http://localhost:8080`.

`.env.example` documents the variables, but `place_order.php` intentionally reads
server environment variables rather than parsing an exposed web-root `.env` file.

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
