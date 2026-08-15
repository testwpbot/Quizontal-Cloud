#!/usr/bin/env bash
set -Eeuo pipefail

# Quizontal Cloud — product re-import
# ---------------------------------------------------------------------------
# Pulls fresh pricing from the provider APIs and pushes it into FOSSBilling:
#   1. InterServer VPS catalog  ->  storage/app/private/catalog.json (LKR prices)
#   2. VPS products             ->  FOSSBilling products (admin API)
#   3. Domain TLDs (Porkbun)    ->  FOSSBilling TLD rows (admin API)
#   4. Domain product           ->  FOSSBilling "domain" product (idempotent)
#
# Usage:
#   bash deploy/import-products.sh            # full import
#   bash deploy/import-products.sh --dry-run  # preview only (no writes)
#
# TLD scope is controlled by DOMAIN_SYNC_TLDS. It defaults to "*" (every
# Porkbun extension). Override per-run with:
#   DOMAIN_SYNC_TLDS="com,net,org,io,dev,ai" bash deploy/import-products.sh
# ---------------------------------------------------------------------------

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
APP_ROOT=$(cd -- "$SCRIPT_DIR/.." && pwd)
PHP_BIN=${PHP_BIN:-php}

DRY_RUN=0
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=1

cd "$APP_ROOT"

# --- .env must exist with the credentials these commands depend on ----------
[[ -f .env ]] || { echo "No .env found in $APP_ROOT — copy .env.example and fill it in." >&2; exit 1; }
for key in FOSSBILLING_URL FOSSBILLING_ADMIN_API_KEY INTERSERVER_API_KEY EXCHANGERATE_API_KEY; do
  if ! grep -Eq "^${key}=.+" .env; then
    echo "Missing required key in .env: $key" >&2
    exit 1
  fi
done

# --- TLD scope (default: all Porkbun TLDs) -----------------------------------
DOMAIN_SYNC_TLDS=${DOMAIN_SYNC_TLDS:-\*}
if grep -q '^DOMAIN_SYNC_TLDS=' .env; then
  sed -i "s|^DOMAIN_SYNC_TLDS=.*|DOMAIN_SYNC_TLDS=${DOMAIN_SYNC_TLDS}|" .env
else
  printf '\nDOMAIN_SYNC_TLDS=%s\n' "$DOMAIN_SYNC_TLDS" >> .env
fi
echo "DOMAIN_SYNC_TLDS=${DOMAIN_SYNC_TLDS}"

# --- Make sure Laravel re-reads .env (config cache would freeze old values) --
"$PHP_BIN" artisan config:clear >/dev/null 2>&1 || true

DRY=""
[[ $DRY_RUN -eq 1 ]] && DRY="--dry-run"

echo ""
echo "==> [1/4] Importing InterServer VPS catalog (live USD/LKR + margin)"
"$PHP_BIN" artisan interserver:import-products

if [[ $DRY_RUN -eq 1 ]]; then
  echo ""
  echo "==> [dry-run] Syncing VPS products to FOSSBilling"
  "$PHP_BIN" artisan fossbilling:sync-products --dry-run || true
  echo ""
  echo "==> [dry-run] Syncing domain TLDs to FOSSBilling"
  "$PHP_BIN" artisan fossbilling:sync-domains --dry-run || true
  echo ""
  echo "==> [dry-run] Ensuring the FOSSBilling domain product"
  "$PHP_BIN" artisan fossbilling:ensure-domain-product --dry-run || true
else
  echo ""
  echo "==> [2/4] Syncing VPS products to FOSSBilling"
  "$PHP_BIN" artisan fossbilling:sync-products --force

  echo ""
  echo "==> [3/4] Syncing domain TLDs to FOSSBilling"
  "$PHP_BIN" artisan fossbilling:sync-domains --force

  echo ""
  echo "==> [4/4] Ensuring the FOSSBilling domain product"
  "$PHP_BIN" artisan fossbilling:ensure-domain-product
fi

# --- Refresh storefront cache so the site serves the fresh catalog -----------
"$PHP_BIN" artisan cache:clear >/dev/null 2>&1 || true

echo ""
echo "Product re-import complete."
echo "Remember to also redeploy the theme overrides if the billing panel changed:"
echo "  sudo -E bash deploy/install-interserver-provisioning.sh"
