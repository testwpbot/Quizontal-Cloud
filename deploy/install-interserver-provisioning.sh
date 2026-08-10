#!/usr/bin/env bash
set -Eeuo pipefail
: "${FOSSBILLING_DIR:=/opt/lampp/htdocs}"
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SOURCE_DIR="$SCRIPT_DIR/fossbilling/Serviceinterserver"
CUSTOMER_API_DIR="$SCRIPT_DIR/fossbilling/Cloudvps"
THEME_OVERRIDES_DIR="$SCRIPT_DIR/fossbilling/theme-overrides"
[[ $EUID -eq 0 ]] || { echo 'Run with sudo -E.' >&2; exit 1; }
[[ -d "$SOURCE_DIR" ]] || { echo "Module source missing: $SOURCE_DIR" >&2; exit 1; }
MODULES_DIR=''
for candidate in "$FOSSBILLING_DIR/modules" "$FOSSBILLING_DIR/src/modules" "$FOSSBILLING_DIR/htdocs/modules"; do
  [[ -f "$candidate/Invoice/manifest.json" ]] && { MODULES_DIR=$candidate; break; }
done
[[ -n "$MODULES_DIR" ]] || { echo 'Could not locate the FOSSBilling modules directory.' >&2; exit 1; }
TARGET="$MODULES_DIR/Serviceinterserver"
WEB_USER=${FOSSBILLING_WEB_USER:-$(stat -c '%U' "$MODULES_DIR/Invoice")}
WEB_GROUP=${FOSSBILLING_WEB_GROUP:-$(stat -c '%G' "$MODULES_DIR/Invoice")}
install -d -m 0755 -o "$WEB_USER" -g "$WEB_GROUP" "$TARGET"
cp -a "$SOURCE_DIR"/. "$TARGET"/
chown -R "$WEB_USER:$WEB_GROUP" "$TARGET"
find "$TARGET" -type d -exec chmod 0755 {} +
find "$TARGET" -type f -exec chmod 0644 {} +
CUSTOMER_TARGET="$MODULES_DIR/Cloudvps"
install -d -m 0755 -o "$WEB_USER" -g "$WEB_GROUP" "$CUSTOMER_TARGET"
cp -a "$CUSTOMER_API_DIR"/. "$CUSTOMER_TARGET"/
chown -R "$WEB_USER:$WEB_GROUP" "$CUSTOMER_TARGET"
find "$CUSTOMER_TARGET" -type d -exec chmod 0755 {} +
find "$CUSTOMER_TARGET" -type f -exec chmod 0644 {} +
APP_ROOT=$(dirname "$MODULES_DIR")
if [[ -d "$THEME_OVERRIDES_DIR" && -d "$APP_ROOT/themes" ]]; then
  for theme_dir in "$APP_ROOT/themes"/*; do
    [[ -d "$theme_dir/html" ]] || continue
    install -d -m 0755 -o "$WEB_USER" -g "$WEB_GROUP" "$theme_dir/html_custom"
    for override in "$THEME_OVERRIDES_DIR"/*.twig; do
      [[ -f "$override" ]] || continue
      install -m 0644 -o "$WEB_USER" -g "$WEB_GROUP" "$override" "$theme_dir/html_custom/$(basename "$override")"
    done
  done
fi
REGISTRAR_ADAPTERS_DIR="$SCRIPT_DIR/fossbilling/registrar-adapters"
if [[ -d "$REGISTRAR_ADAPTERS_DIR" ]]; then
  ADAPTER_TARGET="$APP_ROOT/library/Registrar/Adapter"
  if [[ -d "$ADAPTER_TARGET" ]]; then
    for adapter in "$REGISTRAR_ADAPTERS_DIR"/*.php; do
      [[ -f "$adapter" ]] || continue
      install -m 0644 -o "$WEB_USER" -g "$WEB_GROUP" "$adapter" "$ADAPTER_TARGET/$(basename "$adapter")"
      echo "Installed domain registrar adapter: $(basename "$adapter")"
    done
  else
    echo "Registrar adapter directory not found ($ADAPTER_TARGET); skipping registrar adapters." >&2
  fi
fi
[[ -d "$APP_ROOT/data/cache" ]] && find "$APP_ROOT/data/cache" -type f ! -name '.gitignore' -delete
echo "Quizontal Cloud provisioning modules installed successfully."
echo 'Next: run bash deploy/activate-interserver-provisioning.sh from the repository.'
