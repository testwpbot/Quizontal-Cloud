#!/usr/bin/env bash
# Installs the Quizontal Cloud branded FOSSBilling error page.
#
# Replaces two FOSSBilling-branded error surfaces:
#   1. library/FOSSBilling/ErrorPage.php — the hardcoded "Powered by FOSSBilling"
#      page shown for uncaught errors in production. The original is backed up so
#      it can be restored after a FOSSBilling upgrade.
#   2. themes/<client-theme>/html_custom/error.html.twig — the client-area error
#      template (404s and app-level errors), overriding the stock huraga theme.
#
# Usage:
#   sudo -E FOSSBILLING_DIR=/var/www/billing bash deploy/install-custom-error-page.sh
#   sudo -E bash deploy/install-custom-error-page.sh --restore   # roll back to stock
#
# No shell access? See the "DirectAdmin / no SSH" section in deploy/CUSTOM_ERROR_PAGE.md
# — the same two files can be placed with the DirectAdmin File Manager.
set -Eeuo pipefail

: "${FOSSBILLING_DIR:=/var/www/fossbilling}"
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SOURCE_DIR="$SCRIPT_DIR/fossbilling/error-page"
THEME_OVERRIDES_DIR="$SCRIPT_DIR/fossbilling/theme-overrides"
REPO_ROOT=$(cd -- "$SCRIPT_DIR/.." && pwd)

if [[ ${1:-} == '--restore' ]]; then
    RESTORE=1
else
    RESTORE=0
fi

[[ $EUID -eq 0 ]] || { echo 'Run as root: sudo -E bash deploy/install-custom-error-page.sh' >&2; exit 1; }
[[ -f "$SOURCE_DIR/ErrorPage.php" ]] || { echo "Error page source missing: $SOURCE_DIR/ErrorPage.php" >&2; exit 1; }

# Locate the FOSSBilling library directory (release archives vary between a
# direct root, src/, and htdocs/ layout).
LIBRARY_DIR=''
for candidate in "$FOSSBILLING_DIR/library" "$FOSSBILLING_DIR/src/library" "$FOSSBILLING_DIR/htdocs/library"; do
    if [[ -d "$candidate/FOSSBilling" ]]; then
        LIBRARY_DIR=$candidate
        break
    fi
done
[[ -n "$LIBRARY_DIR" ]] || {
    echo "Could not locate the FOSSBilling library directory under $FOSSBILLING_DIR." >&2
    echo 'Set FOSSBILLING_DIR to the installation root containing library/ or src/library/.' >&2
    exit 1
}

ERROR_PAGE="$LIBRARY_DIR/FOSSBilling/ErrorPage.php"
BACKUP="$ERROR_PAGE.fossbilling-original"
WEB_USER=${FOSSBILLING_WEB_USER:-$(stat -c '%U' "$LIBRARY_DIR/FOSSBilling")}
WEB_GROUP=${FOSSBILLING_WEB_GROUP:-$(stat -c '%G' "$LIBRARY_DIR/FOSSBilling")}
APP_ROOT=$(dirname "$LIBRARY_DIR")

# Storefront link. Priority: STOREFRONT_URL env → APP_URL in the repo .env →
# empty (button omitted). Skip localhost/loopback values so a dev APP_URL never
# becomes a broken production link.
STOREFRONT_URL=${STOREFRONT_URL:-}
if [[ -z "$STOREFRONT_URL" && -f "$REPO_ROOT/.env" ]]; then
    RAW_APP_URL=$(grep -m1 '^APP_URL=' "$REPO_ROOT/.env" | cut -d= -f2- || true)
    STOREFRONT_URL=$(printf '%s' "$RAW_APP_URL" | tr -d "\"' \t\r")
fi
STOREFRONT_URL=${STOREFRONT_URL%/}
if [[ "$STOREFRONT_URL" =~ (^|//)(localhost|127\.0\.0\.1|::1)([:/]|$) ]]; then
    echo "Ignoring local STOREFRONT_URL ($STOREFRONT_URL); the error page will omit the storefront button."
    STOREFRONT_URL=''
fi

# ---- restore mode ----
if [[ $RESTORE -eq 1 ]]; then
    if [[ -f "$BACKUP" ]]; then
        cp -p "$BACKUP" "$ERROR_PAGE"
        chown "$WEB_USER:$WEB_GROUP" "$ERROR_PAGE"
        chmod 0644 "$ERROR_PAGE"
        echo "Restored original FOSSBilling error page: $ERROR_PAGE"
    else
        echo "No backup found at $BACKUP — nothing to restore for the core error page." >&2
    fi
    if [[ -d "$APP_ROOT/themes" ]]; then
        for theme_dir in "$APP_ROOT/themes"/*; do
            [[ -d "$theme_dir/html_custom" ]] || continue
            rm -f "$theme_dir/html_custom/error.html.twig"
        done
    fi
    [[ -d "$APP_ROOT/data/cache" ]] && find "$APP_ROOT/data/cache" -type f ! -name '.gitignore' -delete
    echo 'Quizontal Cloud branded error page removed; stock FOSSBilling error page restored.'
    exit 0
fi

# ---- install mode ----

# Safety: only replace the file when it is recognisably a FOSSBilling ErrorPage.
[[ -f "$ERROR_PAGE" ]] || { echo "ErrorPage.php not found at $ERROR_PAGE; is FOSSBilling installed?" >&2; exit 1; }
grep -q 'class ErrorPage' "$ERROR_PAGE" && grep -q 'renderPage' "$ERROR_PAGE" || {
    echo "Refusing to replace $ERROR_PAGE: it does not look like FOSSBilling's ErrorPage class." >&2
    exit 1
}

# Back up the original exactly once so a later --restore returns true stock.
if [[ ! -f "$BACKUP" ]]; then
    cp -p "$ERROR_PAGE" "$BACKUP"
    echo "Backed up original to $BACKUP"
fi

install -m 0644 -o "$WEB_USER" -g "$WEB_GROUP" "$SOURCE_DIR/ErrorPage.php" "$ERROR_PAGE"
echo "Installed branded ErrorPage.php -> $ERROR_PAGE"

# Client-area theme override. Only client themes (huraga) receive it — never admin
# themes, whose shared template names would crash the admin panel.
ADMIN_THEMES=${FOSSBILLING_ADMIN_THEMES:-admin_default}
is_admin_theme() {
    local name
    name=$(basename "$1")
    case " $ADMIN_THEMES " in *" $name "*) return 0 ;; esac
    return 1
}
if [[ -d "$THEME_OVERRIDES_DIR" && -d "$APP_ROOT/themes" ]]; then
    for theme_dir in "$APP_ROOT/themes"/*; do
        [[ -d "$theme_dir/html" ]] || continue
        if is_admin_theme "$theme_dir"; then
            rm -f "$theme_dir/html_custom/error.html.twig" 2>/dev/null || true
            continue
        fi
        install -d -m 0755 -o "$WEB_USER" -g "$WEB_GROUP" "$theme_dir/html_custom"
        install -m 0644 -o "$WEB_USER" -g "$WEB_GROUP" \
            "$THEME_OVERRIDES_DIR/error.html.twig" \
            "$theme_dir/html_custom/error.html.twig"
    done
    echo 'Installed client-area error template into theme html_custom/ directories.'
fi

# Inject the storefront URL into the two installed files (they ship with '' as
# the default so a manual install is always safe out of the box).
if [[ -n "$STOREFRONT_URL" ]]; then
    sed -i "s#private static string \$storefrontUrl = '';#private static string \$storefrontUrl = '${STOREFRONT_URL//#/%23}';#" "$ERROR_PAGE"
    for theme_dir in "$APP_ROOT/themes"/*; do
        [[ -f "$theme_dir/html_custom/error.html.twig" ]] || continue
        sed -i "s#{% set qc_store_url = '' %}#{% set qc_store_url = '${STOREFRONT_URL//#/%23}' %}#" \
            "$theme_dir/html_custom/error.html.twig"
    done
    echo "Storefront link configured: $STOREFRONT_URL"
else
    echo 'No storefront URL configured; the branded page will only link back to the client area.'
fi

# FOSSBilling caches Twig paths and controllers on disk — clear them.
if [[ -d "$APP_ROOT/data/cache" ]]; then
    find "$APP_ROOT/data/cache" -type f ! -name '.gitignore' -delete
fi

echo
echo 'Quizontal Cloud branded error page installed.'
echo '  Core page:   '"$ERROR_PAGE"
echo '  Theme page:  themes/*/html_custom/error.html.twig'
echo '  Roll back:   sudo -E FOSSBILLING_DIR='"$FOSSBILLING_DIR"' bash deploy/install-custom-error-page.sh --restore'
echo 'Re-run this script after every FOSSBilling upgrade (upgrades overwrite ErrorPage.php).'
