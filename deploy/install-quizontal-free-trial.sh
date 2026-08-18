#!/usr/bin/env bash
set -Eeuo pipefail

# Installs the Quizontal Cloud Free Trial module files into a FOSSBilling
# installation. Run the activation helper afterwards to switch the module on
# and bind its email templates.

: "${FOSSBILLING_DIR:=/var/www/fossbilling}"
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SOURCE_DIR="$SCRIPT_DIR/fossbilling/Quizontalfreetrial"

if [[ $EUID -ne 0 ]]; then
  echo 'Run as root: sudo -E bash deploy/install-quizontal-free-trial.sh' >&2
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

# The trial provisions through the core hosting module and the DirectAdmin
# server manager. Fail early rather than halfway through a customer signup.
[[ -f "$MODULES_DIR/Servicehosting/manifest.json" ]] || {
  echo 'The core Servicehosting module is missing — the free trial cannot provision without it.' >&2
  exit 1
}
APP_ROOT=$(dirname "$MODULES_DIR")
if [[ ! -f "$APP_ROOT/library/Server/Manager/Directadmin.php" ]]; then
  echo 'Warning: DirectAdmin server manager not found at library/Server/Manager/Directadmin.php.' >&2
  echo '         Run deploy/install-interserver-provisioning.sh or copy deploy/fossbilling/server-managers/Directadmin.php into place.' >&2
fi

TARGET_DIR="$MODULES_DIR/Quizontalfreetrial"
WEB_USER=${FOSSBILLING_WEB_USER:-$(stat -c '%U' "$MODULES_DIR/Invoice")}
WEB_GROUP=${FOSSBILLING_WEB_GROUP:-$(stat -c '%G' "$MODULES_DIR/Invoice")}

install -d -m 0755 -o "$WEB_USER" -g "$WEB_GROUP" "$TARGET_DIR"
cp -a "$SOURCE_DIR"/. "$TARGET_DIR"/
chown -R "$WEB_USER:$WEB_GROUP" "$TARGET_DIR"
# Module source and manifest contain no credentials. Keep them readable by the
# PHP-FPM worker even when the pool runs as a user other than www-data.
find "$TARGET_DIR" -type d -exec chmod 0755 {} +
find "$TARGET_DIR" -type f -exec chmod 0644 {} +

# FOSSBilling caches active module controllers and Twig paths on disk. Clear
# generated cache files so the /free-trial route is registered.
if [[ -d "$APP_ROOT/data/cache" ]]; then
  find "$APP_ROOT/data/cache" -type f ! -name '.gitignore' -delete
fi

echo "Quizontal Cloud Free Trial module files installed in $TARGET_DIR (owner $WEB_USER:$WEB_GROUP)."

# A machine can carry more than one FOSSBilling tree (an old /var/www copy plus
# a live XAMPP one, say). Installing into the wrong one succeeds quietly here
# and only fails later with "manifest file is missing" from the activation API,
# so name the other candidates now while the context is still on screen.
OTHER_INSTALLS=()
for other in /var/www/fossbilling /var/www/billing /opt/lampp/htdocs /var/www/html; do
  [[ "$other" == "$FOSSBILLING_DIR" ]] && continue
  for sub in '' /src /htdocs; do
    if [[ -f "$other$sub/modules/Invoice/manifest.json" ]]; then
      OTHER_INSTALLS+=("$other")
      break
    fi
  done
done
if [[ ${#OTHER_INSTALLS[@]} -gt 0 ]]; then
  echo
  echo "NOTE: another FOSSBilling installation exists at: ${OTHER_INSTALLS[*]}"
  echo "      Files were installed into $FOSSBILLING_DIR. This must be the same"
  echo '      installation that FOSSBILLING_URL in Laravel .env serves, otherwise'
  echo '      activation reports "Module quizontalfreetrial manifest file is missing".'
fi

echo
echo 'Next: run deploy/activate-quizontal-free-trial.sh from the Quizontal Cloud repository.'
