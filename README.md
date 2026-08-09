# Quizontal Cloud — Laravel + FossBilling

This repository is the **Laravel 12 storefront** for Quizontal Cloud. It displays an InterServer VPS catalog in LKR and sends customers to a dedicated **FossBilling client area** for billing, invoices, support and service management.

> FossBilling is a separate PHP application with its own database and installer. It must not be embedded inside Laravel's `public/` directory. Deploy it on a dedicated subdomain such as `https://billing.yourdomain.com`, then point the Laravel `FOSSBILLING_URL` at that address. The storefront's **Client area** button and `/client-area` route redirect there.

## Local Laravel setup

The project requires PHP 8.2+, Composer, and the PHP extensions required by Laravel. This coding environment does not include PHP or Composer, so dependency installation and automated tests must be run on your development machine or server.

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan serve --host=0.0.0.0 --port=8000
```

## InterServer catalog and LKR pricing

Set the following values in `.env`:

```dotenv
INTERSERVER_API_URL=https://my.interserver.net/apiv2
INTERSERVER_API_KEY=your_new_interserver_key
EXCHANGERATE_API_KEY=your_new_exchangerate_api_key
PROFIT_USD=1
FOSSBILLING_URL=https://billing.example.com
FOSSBILLING_ORDER_URL=https://billing.example.com/order
```

Then import products:

```bash
php artisan interserver:import-products
```

The command calls InterServer's VPS ordering-information endpoint, obtains the USD/LKR rate, adds exactly `PROFIT_USD` to the provider monthly USD price, converts it to LKR, and stores the private catalog in `storage/app/private/catalog.json`. Until then, the storefront shows a clearly marked demo catalog.

## FossBilling installation

On a PHP 8.2+ server with MySQL 8+/MariaDB 10.3+, run the deployment helper from this repository (or follow `deploy/FOSS_BILLING.md`):

```bash
export FOSSBILLING_DIR=/var/www/billing
sudo -E bash deploy/install-fossbilling.sh
```

Create a separate MySQL database/user first, complete FossBilling's web installer at the billing subdomain, and create matching products in its admin panel. Use a FossBilling payment gateway and an InterServer provisioning module/webhook before accepting orders. Do not place InterServer orders directly from the public browser.

## Wallet-first manual bank transfers

An installable FossBilling module for private receipt uploads, administrator approval, wallet credits, and wallet-only checkout is included in `deploy/fossbilling/Quizontalbanktransfer`. Follow [`deploy/QUIZONTAL_BANK_TRANSFER.md`](deploy/QUIZONTAL_BANK_TRANSFER.md) to install and configure it.

## Scheduled updates

Run this daily as the web-server user after Laravel is installed:

```cron
15 2 * * * cd /var/www/quizontal-cloud && php artisan interserver:import-products >> /dev/null 2>&1
```

FossBilling also needs its own scheduler, described in `deploy/FOSS_BILLING.md`.

## Security

- Never commit `.env`, provider keys, database passwords, or FossBilling admin credentials.
- Rotate any credential that has been pasted into a chat or other public location.
- Use HTTPS for both storefront and billing portal.
- Review provider prices, LKR rounding, taxes, gateway fees, refund terms, and local business requirements before enabling checkout.

## Local one-address testing

For a local setup where the public website is `http://localhost/` and the FossBilling dashboard is `http://localhost/client-area/`, follow [deploy/LOCALHOST.md](deploy/LOCALHOST.md). It includes exact Nginx, PHP, MariaDB, Laravel, FossBilling, and database setup steps.
