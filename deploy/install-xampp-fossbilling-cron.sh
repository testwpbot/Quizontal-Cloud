#!/usr/bin/env bash
set -Eeuo pipefail

: "${FOSSBILLING_DIR:=/opt/lampp/htdocs}"
: "${XAMPP_PHP:=/opt/lampp/bin/php}"
: "${FOSSBILLING_CRON_USER:=daemon}"

[[ $EUID -eq 0 ]] || { echo 'Run with sudo -E.' >&2; exit 1; }
[[ -x "$XAMPP_PHP" ]] || { echo "XAMPP PHP not found: $XAMPP_PHP" >&2; exit 1; }
[[ -f "$FOSSBILLING_DIR/cron.php" ]] || { echo "FOSSBilling cron.php not found in $FOSSBILLING_DIR" >&2; exit 1; }
id "$FOSSBILLING_CRON_USER" >/dev/null 2>&1 || { echo "System user does not exist: $FOSSBILLING_CRON_USER" >&2; exit 1; }

cat > /etc/cron.d/quizontal-fossbilling <<EOF
# Quizontal Cloud: process FOSSBilling email queue and scheduled billing tasks.
*/5 * * * * $FOSSBILLING_CRON_USER $XAMPP_PHP $FOSSBILLING_DIR/cron.php >> $FOSSBILLING_DIR/data/log/cron.log 2>&1
EOF
chmod 0644 /etc/cron.d/quizontal-fossbilling

echo 'Installed /etc/cron.d/quizontal-fossbilling (runs every five minutes).'
echo "Test now: sudo -u $FOSSBILLING_CRON_USER $XAMPP_PHP $FOSSBILLING_DIR/cron.php"
