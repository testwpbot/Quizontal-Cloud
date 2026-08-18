#!/usr/bin/env bash
set -Eeuo pipefail

# Activates the Quizontal Cloud Free Trial module, connects its cron hook and
# binds every trial email template to the files shipped in this repository.
# Safe to re-run: every step is idempotent.

REPO_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
ENV_FILE=${ENV_FILE:-$REPO_DIR/.env}
[[ -f "$ENV_FILE" ]] || { echo "Laravel environment file not found: $ENV_FILE" >&2; exit 1; }
command -v curl >/dev/null || { echo 'curl is required.' >&2; exit 1; }
command -v jq >/dev/null || { echo 'jq is required.' >&2; exit 1; }

read_env() {
    local key=$1
    sed -n "s/^${key}=//p" "$ENV_FILE" | head -n1 | tr -d '\r' | sed -e 's/^"//' -e 's/"$//'
}

API_KEY=${FOSSBILLING_ADMIN_API_KEY:-$(read_env FOSSBILLING_ADMIN_API_KEY)}
BILLING_URL=${FOSSBILLING_URL:-$(read_env FOSSBILLING_URL)}
STOREFRONT_URL=${APP_URL:-$(read_env APP_URL)}
TRIAL_PRODUCT_ID=${FREE_TRIAL_PRODUCT_ID:-$(read_env FREE_TRIAL_PRODUCT_ID)}
TRIAL_DAYS=${FREE_TRIAL_DAYS:-$(read_env FREE_TRIAL_DAYS)}
[[ -n "$API_KEY" ]] || { echo 'FOSSBILLING_ADMIN_API_KEY is missing.' >&2; exit 1; }
[[ -n "$BILLING_URL" ]] || { echo 'FOSSBILLING_URL is missing.' >&2; exit 1; }
[[ -n "$TRIAL_PRODUCT_ID" ]] || TRIAL_PRODUCT_ID=98
[[ -n "$TRIAL_DAYS" ]] || TRIAL_DAYS=7
BILLING_URL=${BILLING_URL%/}

api_post() {
    local endpoint=$1 data=$2 response
    response=$(curl -sS -u "admin:$API_KEY" -H 'Content-Type: application/json' -X POST "$BILLING_URL/api/admin/$endpoint" -d "$data")
    if [[ $(jq -r '.error // empty' <<<"$response") != "" ]]; then
        jq -r '"FOSSBilling API error: " + (.error.message // "Unknown error")' <<<"$response" >&2
        # By far the most common cause: the module files were installed into a
        # different FOSSBilling tree than the one FOSSBILLING_URL serves.
        if jq -e '.error.message // "" | test("manifest file is missing")' <<<"$response" >/dev/null 2>&1; then
            echo >&2
            echo "The billing application at $BILLING_URL cannot see the module files." >&2
            echo 'Re-run the installer against the installation that URL actually serves, for example:' >&2
            echo >&2
            echo '  sudo -E env FOSSBILLING_DIR=/opt/lampp/htdocs \' >&2
            echo '    bash deploy/install-quizontal-free-trial.sh' >&2
            echo >&2
            echo 'Installations that currently contain the module:' >&2
            for candidate in /var/www/fossbilling /var/www/billing /opt/lampp/htdocs /var/www/html; do
                for sub in '' /src /htdocs; do
                    [[ -d "$candidate$sub/modules/Quizontalfreetrial" ]] && echo "  - $candidate$sub" >&2
                done
            done
        fi
        exit 1
    fi
    jq . <<<"$response"
}

# Soft variant for steps that must never abort the run.
api_post_soft() {
    local endpoint=$1 data=$2 response
    response=$(curl -sS -u "admin:$API_KEY" -H 'Content-Type: application/json' -X POST "$BILLING_URL/api/admin/$endpoint" -d "$data" || true)
    if [[ $(jq -r '.error // empty' <<<"$response" 2>/dev/null) != "" ]]; then
        jq -r '"Warning: " + (.error.message // "Unknown API error") + " (continuing)"' <<<"$response" >&2
        return 0
    fi
    jq . <<<"$response" 2>/dev/null || true
}

api_post 'extension/activate' '{"id":"quizontalfreetrial","type":"mod"}'

# Reconnect every active module so the trial lifecycle cron hook is registered
# alongside the existing bank-transfer and provisioning listeners.
api_post 'hook/batch_connect' '{}'

# Generate the database rows for the module templates, enable them, then reset
# each one so it is rendered from the file shipped here rather than from an
# older auto-generated stub. email_template rows persist forever once created,
# so the reset must run on every activation.
api_post 'email/batch_template_generate' '{}'
api_post 'email/batch_template_enable' '{}'
for template_code in \
    mod_quizontalfreetrial_code \
    mod_quizontalfreetrial_ready \
    mod_quizontalfreetrial_reminder \
    mod_quizontalfreetrial_expired \
    mod_quizontalfreetrial_terminated
do
    api_post 'email/template_reset' "{\"code\":\"$template_code\"}"
done

# Seed the module configuration so the very first customer hits a working
# wizard instead of the default product ID guess.
CONFIG_PAYLOAD=$(jq -n \
    --arg product_id "$TRIAL_PRODUCT_ID" \
    --arg trial_days "$TRIAL_DAYS" \
    --arg storefront "$STOREFRONT_URL" \
    '{ext:"mod_quizontalfreetrial", enabled:"1", product_id:$product_id, trial_days:$trial_days,
      grace_days:"7", reminder_days_before:"2", code_ttl_minutes:"15", code_resend_seconds:"60",
      code_max_attempts:"6", code_max_sends_per_hour:"5", ip_max_trials_per_day:"3",
      default_country_code:"94", storefront_url:$storefront}')
api_post 'extension/config_save' "$CONFIG_PAYLOAD"

# FOSSBilling resolves a module from the first URL segment, so the wizard's own
# route can only ever be /quizontalfreetrial. The core Redirect module is loaded
# on every request, which makes it the supported way to publish the friendlier
# /free-trial path. Create the entry once, idempotently.
REDIRECTS=$(curl -sS -u "admin:$API_KEY" -H 'Content-Type: application/json' \
    -X POST "$BILLING_URL/api/admin/redirect/get_list" -d '{}' || true)
if jq -e '.result' <<<"$REDIRECTS" >/dev/null 2>&1; then
    if jq -e '.result[]? | select(.path == "free-trial")' <<<"$REDIRECTS" >/dev/null 2>&1; then
        echo 'Redirect /free-trial already present.'
    else
        REDIRECT_PAYLOAD=$(jq -n --arg target "$BILLING_URL/quizontalfreetrial" \
            '{path:"free-trial", target:$target}')
        api_post_soft 'redirect/create' "$REDIRECT_PAYLOAD"
        echo "Created redirect /free-trial -> $BILLING_URL/quizontalfreetrial"
    fi
else
    echo 'Note: the core Redirect module is not active, so only the canonical' >&2
    echo "      $BILLING_URL/quizontalfreetrial URL will work." >&2
    echo '      Activate Extensions -> Redirect and re-run this script to publish /free-trial.' >&2
fi

# template_reset only helps when FOSSBilling can find the module file. Rows
# generated while the files were missing keep a subject derived from the action
# code ("Mod Quizontalfreetrial Code") and drop the verification code from the
# subject line, so repair them directly as well.
REPAIRED=$(api_post_soft 'quizontalfreetrial/repair_email_templates' '{}')
jq -r '.result[]? | "Repaired email template: " + .' <<<"$REPAIRED" 2>/dev/null || true

echo
echo 'Checking that the trial product can actually provision…'DIAGNOSIS=$(api_post_soft 'quizontalfreetrial/diagnose' '{}')
if [[ $(jq -r '.result.ready // false' <<<"$DIAGNOSIS" 2>/dev/null) == 'true' ]]; then
    echo "Ready: $(jq -r '.result.product_title' <<<"$DIAGNOSIS") (product #$TRIAL_PRODUCT_ID) on $(jq -r '.result.server_manager' <<<"$DIAGNOSIS")."
else
    echo 'Not ready yet:' >&2
    jq -r '.result.problems[]? | "  - " + .' <<<"$DIAGNOSIS" >&2 || true
    echo "  Open $BILLING_URL/admin/product/manage/$TRIAL_PRODUCT_ID and set the DirectAdmin server and hosting plan." >&2
fi

echo
echo 'Quizontal Cloud Free Trial is active.'
echo "Customer wizard: $BILLING_URL/quizontalfreetrial"
echo "  also at:       $BILLING_URL/free-trial (via the Redirect module)"
echo "Admin register:  $BILLING_URL/admin/quizontalfreetrial"
echo "Settings:        $BILLING_URL/admin/extension/settings/quizontalfreetrial"
echo
echo 'Reminders, suspensions and terminations run from the standard FOSSBilling cron.'
