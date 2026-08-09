#!/usr/bin/env bash
set -Eeuo pipefail

: "${FOSSBILLING_DIR:=/var/www/fossbilling}"
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SOURCE_DIR="$SCRIPT_DIR/fossbilling/Quizontalbanktransfer"

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

install -d -m 0750 -o www-data -g www-data "$TARGET_DIR"
cp -a "$SOURCE_DIR"/. "$TARGET_DIR"/
chown -R www-data:www-data "$TARGET_DIR"
find "$TARGET_DIR" -type d -exec chmod 0750 {} +
find "$TARGET_DIR" -type f -exec chmod 0640 {} +

echo "Quizontal Cloud Bank Transfer module files installed in $TARGET_DIR."
echo 'Next: run deploy/activate-quizontal-bank-transfer.sh from the Quizontal Cloud repository, then configure the bank details at the settings URL it prints.'
