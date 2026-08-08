# FossBilling deployment for Quizontal Cloud

## Topology

Use two separate applications and databases. They may be on separate subdomains in production, or share one host using `/client-area/` in a local test environment:

| Address | Application | Purpose |
|---|---|---|
| `https://quizontalcloud.example` or `http://localhost/` | Laravel storefront | Marketing, LKR catalog, links to billing |
| `https://billing.quizontalcloud.example` or `http://localhost/client-area/` | FossBilling | Client dashboard, orders, payments, tickets, invoices |

Do **not** copy FOSSBilling under the Laravel `public` folder and do not share their databases. This keeps the Laravel application and billing system independently upgradeable and avoids exposing billing files through Laravel routing.

## Server prerequisites

FossBilling 0.7.x needs PHP 8.2–8.4, MySQL 8+ or MariaDB 10.3+, HTTPS, and PHP extensions `intl`, `openssl`, `pdo_mysql`, `xml`, `dom`, `iconv`, `json`, `zlib`, and `curl`. Laravel 12 requires PHP 8.2+ and Composer.

Create a dedicated database before installation:

```sql
CREATE DATABASE fossbilling CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fossbilling'@'localhost' IDENTIFIED BY 'USE_A_LONG_UNIQUE_PASSWORD';
GRANT ALL PRIVILEGES ON fossbilling.* TO 'fossbilling'@'localhost';
FLUSH PRIVILEGES;
```

## Install FOSSBilling

```bash
cd /var/www/quizontal-cloud
export FOSSBILLING_DIR=/var/www/billing
sudo -E bash deploy/install-fossbilling.sh
```

Create an Nginx/Apache virtual host for the billing subdomain using the official FOSSBilling web-root guidance, enable TLS, then browse to the billing subdomain and finish its installation wizard. Enter the dedicated database credentials. Follow the wizard's final instruction to remove/disable the installer and restrict permissions.

Set the Laravel application environment:

```dotenv
FOSSBILLING_URL=https://billing.quizontalcloud.example
FOSSBILLING_ORDER_URL=https://billing.quizontalcloud.example/order
```

The storefront's `/client-area` route now takes every customer to the FossBilling login/dashboard. Create client-facing VPS products in FossBilling. Configure its payment gateway and your InterServer provisioning integration before publishing purchase buttons.

## Scheduled tasks

FossBilling needs a cron invocation every five minutes. Its exact executable path can differ by release, so use the command shown by its installer/admin docs. Example:

```cron
*/5 * * * * www-data php /var/www/billing/cron.php >/dev/null 2>&1
```

The Laravel InterServer price/catalog importer can run daily:

```cron
15 2 * * * www-data cd /var/www/quizontal-cloud && php artisan interserver:import-products >/dev/null 2>&1
```

## Before launch

1. Rotate previously exposed API keys and place replacements only in Laravel `.env`.
2. Ensure Laravel `APP_DEBUG=false`, separate database passwords, and HTTPS are enabled.
3. Configure an off-site database backup policy for FossBilling.
4. Test an order, invoice, payment, provisioning callback, cancellation, and client portal login using test accounts.

For the localhost `/client-area/` setup, use [LOCALHOST.md](LOCALHOST.md) and the included Nginx configuration instead of a billing subdomain.
