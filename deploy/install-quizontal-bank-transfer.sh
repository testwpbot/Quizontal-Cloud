#!/usr/bin/env bash
set -Eeuo pipefail

: "${FOSSBILLING_DIR:=/var/www/fossbilling}"
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SOURCE_DIR="$SCRIPT_DIR/fossbilling/Quizontalbanktransfer"
TARGET_DIR="$FOSSBILLING_DIR/modules/Quizontalbanktransfer"

if [[ $EUID -ne 0 ]]; then
  echo 'Run as root: sudo -E bash deploy/install-quizontal-bank-transfer.sh' >&2
  exit 1
fi
[[ -d "$SOURCE_DIR" ]] || { echo "Module source not found: $SOURCE_DIR" >&2; exit 1; }
[[ -d "$FOSSBILLING_DIR/modules" ]] || { echo "FOSSBilling modules directory not found: $FOSSBILLING_DIR/modules" >&2; exit 1; }

install -d -m 0750 -o www-data -g www-data "$TARGET_DIR"
cp -a "$SOURCE_DIR"/. "$TARGET_DIR"/
chown -R www-data:www-data "$TARGET_DIR"
find "$TARGET_DIR" -type d -exec chmod 0750 {} +
find "$TARGET_DIR" -type f -exec chmod 0640 {} +

echo 'Quizontal Cloud Bank Transfer module files installed.'
echo 'Next: open FOSSBilling Admin > Extensions, activate Quizontal Cloud Bank Transfer, configure its settings, and reconnect hooks if requested.'
