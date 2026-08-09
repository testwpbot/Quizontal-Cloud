# 🚀 How to Run Quizontal Cloud on Your Local PC

## Prerequisites

Make sure you have these installed:

| Tool | Version | Check with |
|------|---------|------------|
| **PHP** | 8.2 or higher | `php -v` |
| **Composer** | Latest | `composer -V` |
| **Node.js** | 18+ | `node -v` |
| **npm** | Comes with Node | `npm -v` |
| **Git** | Any recent | `git -v` |

### PHP Extensions needed
`curl`, `mbstring`, `xml`, `zip`, `sqlite3` (or `mysql` if you prefer MySQL)

---

## Option A: Quick Start (Laravel only — no FossBilling)

This gets the storefront running fast. Perfect for development.

### Step 1: Clone the repo
```bash
git clone https://github.com/testwpbot/Quizontal-Cloud.git
cd Quizontal-Cloud
```

### Step 2: Install PHP dependencies
```bash
composer install
```

### Step 3: Set up environment
```bash
cp .env.example .env
php artisan key:generate
```

### Step 4: Create the SQLite database
```bash
touch database/database.sqlite
php artisan migrate
```

### Step 5: Install frontend dependencies & build
```bash
npm install
npm run build
```

### Step 6: Run the dev server 🎉
```bash
php artisan serve --host=0.0.0.0 --port=8000
```
Open **http://localhost:8000** in your browser!

### Step 6 (Alternative): Run everything together (Laravel + Vite + Queue + Logs)
```bash
composer dev
```
This runs the Laravel server, Vite dev server, queue worker, and log tail all at once using `concurrently`.

---

## Option B: Full Setup (Laravel + FossBilling on one address)

This gives you:
- **Storefront** at `http://localhost/`
- **FossBilling client area** at `http://localhost/client-area/`

> ⚠️ This requires Nginx + PHP-FPM + MariaDB/MySQL. Best on Linux (Ubuntu/Debian).

See the detailed guide in [`deploy/LOCALHOST.md`](deploy/LOCALHOST.md).

**Quick summary:**

1. Install Nginx, MariaDB, PHP 8.3-FPM, Composer
2. Clone repo to `/var/www/quizontal-cloud`
3. Run `composer install`, `php artisan key:generate`
4. Create a MariaDB database for FossBilling
5. Run `sudo -E bash deploy/install-fossbilling.sh`
6. Copy the Nginx config: `deploy/nginx/quizontal-local.conf`
7. Complete FossBilling web installer
8. Import catalog: `php artisan interserver:import-products`

---

## Optional: Configure API Keys

Edit your `.env` file and add real keys to see live data:

```dotenv
# InterServer VPS catalog
INTERSERVER_API_KEY=your_interserver_api_key
EXCHANGERATE_API_KEY=your_exchangerate_api_key
PROFIT_USD=1

# FossBilling URLs (only if you set up FossBilling)
FOSSBILLING_URL=http://localhost/client-area
FOSSBILLING_ORDER_URL=
```

Then import real products:
```bash
php artisan interserver:import-products
```

Without these keys, the storefront shows a **demo catalog** — which is fine for local dev!

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| `composer install` fails | Make sure PHP 8.2+ is installed with required extensions |
| `npm run build` fails | Make sure Node.js 18+ is installed |
| Database errors | Run `touch database/database.sqlite` then `php artisan migrate` |
| Page looks unstyled | Run `npm run build` (or `npm run dev` for hot-reload) |
| Port 8000 in use | Use `php artisan serve --port=8001` |

---

## TL;DR (copy-paste this)

```bash
git clone https://github.com/testwpbot/Quizontal-Cloud.git
cd Quizontal-Cloud
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run build
php artisan serve --host=0.0.0.0 --port=8000
```

Then open **http://localhost:8000** 🎉
