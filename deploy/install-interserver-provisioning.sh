#!/usr/bin/env bash
set -Eeuo pipefail
: "${FOSSBILLING_DIR:=/opt/lampp/htdocs}"
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SOURCE_DIR="$SCRIPT_DIR/fossbilling/Serviceinterserver"
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
APP_ROOT=$(dirname "$MODULES_DIR")
[[ -d "$APP_ROOT/data/cache" ]] && find "$APP_ROOT/data/cache" -type f ! -name '.gitignore' -delete
echo "Quizontal InterServer validation module installed in $TARGET."
echo 'Next: run bash deploy/activate-interserver-provisioning.sh from the repository.'
