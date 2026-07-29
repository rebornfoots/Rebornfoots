# InbornFoot Test Deployment

## Hosting requirements

- IIS or Apache hosting with PHP 8.3+
- PHP extensions: curl, mysqli, mbstring and JSON
- HTTPS certificate
- Persistent writable `assets/images/products` directory
- Server-level environment variables
- Outbound HTTPS access to `api.razorpay.com`
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
F2H_RAZORPAY_KEY_ID
F2H_RAZORPAY_KEY_SECRET
F2H_RAZORPAY_WEBHOOK_SECRET
```

Use only `rzp_test_` keys for this deployment.

## Verification

After upload, open:

```text
https://YOUR-DOMAIN/health.php
```

Every check must be `true`. Then verify the storefront, admin login, product API,
order tracking and one Razorpay Test Mode payment.

## Razorpay webhook

In the Test Mode dashboard, add:

```text
https://YOUR-DOMAIN/razorpay_webhook.php
```

Use the same secret configured as `F2H_RAZORPAY_WEBHOOK_SECRET`. Subscribe to
`payment.captured`, `payment.failed`, `refund.created`, `refund.processed` and
`refund.failed`. Test delivery from the Razorpay dashboard
and confirm a `2xx` response.

## Production switch

Do not switch to Live Mode until merchant onboarding, policies, backups, monitoring,
refund handling and a complete live-payment checklist are finished.
