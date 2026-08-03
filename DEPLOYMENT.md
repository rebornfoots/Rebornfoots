# InbornFood Test Deployment

## Hosting requirements

- IIS or Apache hosting with PHP 8.2+
- PHP extensions: curl, mysqli, mbstring and JSON
- HTTPS certificate
- Persistent writable `assets/images/products` directory
- Server-level environment variables
- MySQL access from the web server

## Database

For a new database, import `database/schema.sql`.

For the existing development database, the migrations are already applied. Never
reuse the historical database password for production; create a new application
database user with only the privileges this application requires.

The deployment ZIP intentionally excludes the `database` directory. Run database
migrations separately from a trusted workstation or hosting database console.

## Environment variables

Configure these in the hosting control panel, never in a public file:

```text
F2H_DB_HOST
F2H_DB_NAME
F2H_DB_USER
F2H_DB_PASSWORD
F2H_ADMIN_USERNAME
F2H_ADMIN_PASSWORD_HASH
```

## Verification

After upload, open:

```text
https://YOUR-DOMAIN/health.php
```

Every check must be `true`. Then verify the storefront, admin login, product API,
order tracking and one test order through the UPI-after-confirmation flow.
