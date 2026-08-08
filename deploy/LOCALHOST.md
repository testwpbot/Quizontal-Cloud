# Run Quizontal Cloud + FossBilling locally at one address

This configuration gives you exactly these URLs:

- Storefront: `http://localhost/`
- FossBilling client dashboard: `http://localhost/client-area/`

It requires a local web stack because `php artisan serve` can only serve Laravel; it cannot also route a second PHP application (FossBilling) under `/client-area`.

## 1. Install prerequisites (Debian/Ubuntu)

```bash
sudo apt update
sudo apt install -y nginx mariadb-server git unzip curl composer \
  php8.3-fpm php8.3-cli php8.3-curl php8.3-mbstring php8.3-xml \
  php8.3-zip php8.3-mysql php8.3-intl
sudo systemctl enable --now nginx mariadb php8.3-fpm
```

PHP 8.2 is also supported; replace `8.3` and the FPM socket paths below with your installed version.

## 2. Download and configure the Laravel storefront

```bash
sudo mkdir -p /var/www
cd /var/www
sudo git clone https://github.com/testwpbot/Quizontal-Cloud.git quizontal-cloud
cd quizontal-cloud
sudo git checkout arena/019fdff2-quizontal-cloud
sudo cp .env.example .env
sudo composer install --no-dev --optimize-autoloader
sudo php artisan key:generate
sudo chown -R www-data:www-data storage bootstrap/cache
```

Edit `/var/www/quizontal-cloud/.env` and use these local values:

```dotenv
APP_NAME="Quizontal Cloud"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

FOSSBILLING_URL=http://localhost/client-area
# Leave blank until you create an order URL/product flow in FossBilling.
FOSSBILLING_ORDER_URL=

INTERSERVER_API_URL=https://my.interserver.net/apiv2
INTERSERVER_API_KEY=PUT_A_NEW_ROTATED_INTERSERVER_KEY_HERE
EXCHANGERATE_API_KEY=PUT_A_NEW_EXCHANGERATE_KEY_HERE
PROFIT_USD=1
```

Do not use API keys that were previously pasted into chat. Rotate them first.

## 3. Create the FossBilling database

```bash
sudo mariadb
```

At the MariaDB prompt, run the following. Replace the password with a long unique password; it is only an example and should not be reused.

```sql
CREATE DATABASE fossbilling CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fossbilling'@'localhost' IDENTIFIED BY 'replace-with-a-long-random-password';
GRANT ALL PRIVILEGES ON fossbilling.* TO 'fossbilling'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 4. Install FossBilling files

```bash
cd /var/www/quizontal-cloud
export FOSSBILLING_DIR=/var/www/fossbilling
sudo -E bash deploy/install-fossbilling.sh
```

## 5. Enable the one-host Nginx configuration

```bash
sudo cp /var/www/quizontal-cloud/deploy/nginx/quizontal-local.conf /etc/nginx/sites-available/quizontal-local
# Check that the paths and php8.3-fpm socket in the copied file match your machine.
sudo ln -s /etc/nginx/sites-available/quizontal-local /etc/nginx/sites-enabled/quizontal-local
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

Now open `http://localhost/client-area/`. Finish the FossBilling installer with:

- **Database host:** `localhost`
- **Database name:** `fossbilling`
- **Database user:** `fossbilling`
- **Database password:** the password you created in step 3

Create your FossBilling administrator account in the installer. Remove/disable the installer when FossBilling tells you to do so.

## 6. Import the InterServer catalog

```bash
cd /var/www/quizontal-cloud
sudo -u www-data php artisan interserver:import-products
```

Visit `http://localhost/`. The homepage will now display imported LKR prices. It calculates each price as `(InterServer monthly USD + 1 USD) × current USD/LKR rate`.

## 7. Test the dashboard link

Click **Client area** in the website header. It should open `http://localhost/client-area/` without changing hosts. Sign in using the FossBilling account created during setup.

## Important limitations before accepting real orders

The catalog importer only reads InterServer plans and prices. Create equivalent products in the FossBilling admin area, configure payment gateways, and configure/test an InterServer provisioning module or webhook. Do not expose an API key to browser JavaScript and do not auto-provision a real VPS before confirmed payment.
