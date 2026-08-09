#!/usr/bin/env bash
set -Eeuo pipefail

: "${FOSSBILLING_DIR:=/var/www/fossbilling}"
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SOURCE_DIR="$SCRIPT_DIR/fossbilling/Quizontalbanktransfer"
THEME_OVERRIDES_DIR="$SCRIPT_DIR/fossbilling/theme-overrides"

if [[ $EUID -ne 0 ]]; then
  echo 'Run as root: sudo -E bash deploy/install-quizontal-bank-transfer.sh' >&2
  exit 1
fi
[[ -d "$SOURCE_DIR" ]] || { echo "Module source not found: $SOURCE_DIR" >&2; exit 1; }

# Release archives differ: some place the application directly in the install
# directory while others retain a src/ or htdocs/ web root.
MODULES_DIR=''
for candidate in "$FOSSBILLING_DIR/modules" "$FOSSBILLING_DIR/src/modules" "$FOSSBILLING_DIR/htdocs/modules"; do
  if [[ -f "$candidate/Invoice/manifest.json" ]]; then
    MODULES_DIR=$candidate
    break
  fi
done
[[ -n "$MODULES_DIR" ]] || {
  echo "Could not locate the active FOSSBilling modules directory under $FOSSBILLING_DIR." >&2
  echo 'Set FOSSBILLING_DIR to the installation root containing modules/ or src/modules/.' >&2
  exit 1
}
TARGET_DIR="$MODULES_DIR/Quizontalbanktransfer"
WEB_USER=${FOSSBILLING_WEB_USER:-$(stat -c '%U' "$MODULES_DIR/Invoice")}
WEB_GROUP=${FOSSBILLING_WEB_GROUP:-$(stat -c '%G' "$MODULES_DIR/Invoice")}

install -d -m 0755 -o "$WEB_USER" -g "$WEB_GROUP" "$TARGET_DIR"
cp -a "$SOURCE_DIR"/. "$TARGET_DIR"/
chown -R "$WEB_USER:$WEB_GROUP" "$TARGET_DIR"
# Module source and manifest contain no credentials. Keep them readable by the
# PHP-FPM worker even when the pool runs as a user other than www-data.
find "$TARGET_DIR" -type d -exec chmod 0755 {} +
find "$TARGET_DIR" -type f -exec chmod 0644 {} +

# Override the legacy wallet page through each client theme's supported
# html_custom directory so transfer status is always visible in Wallet.
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

# FOSSBilling caches active module controllers and Twig paths on disk. Clear
# generated cache files so newly added client/admin routes are registered.
if [[ -d "$APP_ROOT/data/cache" ]]; then
  find "$APP_ROOT/data/cache" -type f ! -name '.gitignore' -delete
fi

echo "Quizontal Cloud Bank Transfer module files installed in $TARGET_DIR (owner $WEB_USER:$WEB_GROUP)."
echo 'Next: run deploy/activate-quizontal-bank-transfer.sh from the Quizontal Cloud repository, then configure the bank details at the settings URL it prints.'
