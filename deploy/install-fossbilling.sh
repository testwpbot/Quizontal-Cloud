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
# fossbilling.org's old /downloads/stable URL may disappear or return 404.
# Resolve the current signed release asset from the official GitHub release API instead.
release_json=$(curl --fail --silent --show-error --location --retry 3 \
  -H 'Accept: application/vnd.github+json' \
  -H 'User-Agent: Quizontal-Cloud-FossBilling-Installer' \
  https://api.github.com/repos/FOSSBilling/FOSSBilling/releases/latest)
release_url=$(printf '%s' "$release_json" | php -r '
    $release = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    foreach ($release["assets"] ?? [] as $asset) {
        if (preg_match("/^FOSSBilling-.*\\.zip$/", $asset["name"] ?? "")) {
            echo $asset["browser_download_url"];
            exit(0);
        }
    }
    exit(1);
') || { echo 'Could not parse the official FOSSBilling release metadata from GitHub.' >&2; exit 1; }
[[ -n "$release_url" ]] || { echo 'The latest FOSSBilling GitHub release did not contain a FOSSBilling zip asset.' >&2; exit 1; }
curl --fail --location --retry 3 "$release_url" --output "$tmp/fossbilling.zip"
unzip -q "$tmp/fossbilling.zip" -d "$tmp/extracted"
# Stable archives may either contain htdocs directly or inside one top-level directory.
source_dir="$tmp/extracted"
if [[ ! -d "$source_dir/htdocs" ]]; then
  source_dir=$(find "$tmp/extracted" -mindepth 1 -maxdepth 2 -type d -name htdocs -printf '%h\n' | head -n 1)
fi
[[ -n "$source_dir" && -d "$source_dir/htdocs" ]] || { echo 'Could not locate the FOSSBilling htdocs directory in the stable archive.' >&2; exit 1; }
cp -a "$source_dir"/. "$FOSSBILLING_DIR"/
chown -R www-data:www-data "$FOSSBILLING_DIR"
find "$FOSSBILLING_DIR" -type d -exec chmod 0750 {} +
find "$FOSSBILLING_DIR" -type f -exec chmod 0640 {} +

echo "FOSSBilling files are installed in $FOSSBILLING_DIR."
echo 'Next: point a billing subdomain virtual host to this directory, create an empty MySQL/MariaDB database, then complete the web installer.'
echo 'After installation, remove the installer as directed by FOSSBilling and add its cron job.'
