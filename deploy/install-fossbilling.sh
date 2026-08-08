#!/usr/bin/env bash
# Installs the official pre-built FOSSBilling stable package. Run on the production PHP host.
# Usage: FOSSBILLING_DIR=/var/www/billing sudo -E bash deploy/install-fossbilling.sh
set -Eeuo pipefail

: "${FOSSBILLING_DIR:=/var/www/fossbilling}"
if [[ $EUID -ne 0 ]]; then
  echo 'Run as root (normally: sudo -E bash deploy/install-fossbilling.sh).' >&2
  exit 1
fi
command -v php >/dev/null || { echo 'PHP 8.2+ is required.' >&2; exit 1; }
command -v curl >/dev/null || { echo 'curl is required.' >&2; exit 1; }
command -v unzip >/dev/null || { echo 'unzip is required.' >&2; exit 1; }

PHP_VERSION=$(php -r 'echo PHP_VERSION_ID;')
[[ "$PHP_VERSION" -ge 80200 ]] || { echo 'FOSSBilling requires PHP 8.2 or newer.' >&2; exit 1; }
for extension in intl openssl pdo_mysql xml dom iconv json zlib curl; do
  php -m | grep -qi "^${extension}$" || { echo "Missing required PHP extension: ${extension}" >&2; exit 1; }
done
if [[ -e "$FOSSBILLING_DIR" && -n "$(ls -A "$FOSSBILLING_DIR" 2>/dev/null)" ]]; then
  echo "$FOSSBILLING_DIR is not empty; refusing to overwrite it." >&2
  exit 1
fi

install -d -m 0750 -o www-data -g www-data "$FOSSBILLING_DIR"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
curl --fail --location --retry 3 https://fossbilling.org/downloads/stable --output "$tmp/fossbilling.zip"
unzip -q "$tmp/fossbilling.zip" -d "$tmp/extracted"
source_dir=$(find "$tmp/extracted" -mindepth 1 -maxdepth 1 -type d | head -n 1)
[[ -n "$source_dir" ]] || source_dir="$tmp/extracted"
cp -a "$source_dir"/. "$FOSSBILLING_DIR"/
chown -R www-data:www-data "$FOSSBILLING_DIR"
find "$FOSSBILLING_DIR" -type d -exec chmod 0750 {} +
find "$FOSSBILLING_DIR" -type f -exec chmod 0640 {} +

echo "FOSSBilling files are installed in $FOSSBILLING_DIR."
echo 'Next: point a billing subdomain virtual host to this directory, create an empty MySQL/MariaDB database, then complete the web installer.'
echo 'After installation, remove the installer as directed by FOSSBilling and add its cron job.'
