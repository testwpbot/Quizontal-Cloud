#!/usr/bin/env bash
set -Eeuo pipefail

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
ADMIN_EMAIL=${QUIZONTAL_ADMIN_EMAIL:-$(read_env QUIZONTAL_ADMIN_EMAIL)}
[[ -n "$ADMIN_EMAIL" ]] || ADMIN_EMAIL=$(read_env MAIL_FROM_ADDRESS)
[[ -n "$API_KEY" ]] || { echo 'FOSSBILLING_ADMIN_API_KEY is missing.' >&2; exit 1; }
[[ -n "$BILLING_URL" ]] || { echo 'FOSSBILLING_URL is missing.' >&2; exit 1; }
[[ -n "$ADMIN_EMAIL" ]] || { echo 'Set QUIZONTAL_ADMIN_EMAIL in Laravel .env.' >&2; exit 1; }
BILLING_URL=${BILLING_URL%/}

api_post() {
    local endpoint=$1 data=$2 response
    response=$(curl -sS -u "admin:$API_KEY" -H 'Content-Type: application/json' -X POST "$BILLING_URL/api/admin/$endpoint" -d "$data")
    if [[ $(jq -r '.error // empty' <<<"$response") != "" ]]; then
        jq -r '"FOSSBilling API error: " + (.error.message // "Unknown error")' <<<"$response" >&2
        exit 1
    fi
    jq . <<<"$response"
}

# Soft variant for steps that must never abort the activation run (e.g. a
# module endpoint on an installation where that module is not active).
api_post_soft() {
    local endpoint=$1 data=$2 response
    response=$(curl -sS -u "admin:$API_KEY" -H 'Content-Type: application/json' -X POST "$BILLING_URL/api/admin/$endpoint" -d "$data" || true)
    if [[ $(jq -r '.error // empty' <<<"$response" 2>/dev/null) != "" ]]; then
        jq -r '"Warning: " + (.error.message // "Unknown API error") + " (continuing)"' <<<"$response" >&2
        return 0
    fi
    jq . <<<"$response" 2>/dev/null || true
}

api_post 'extension/activate' '{"id":"quizontalbanktransfer","type":"mod"}'
# Customer DNS record manager — soft so an older module copy never blocks the
# rest of activation; re-run the main installer + this script to enable it.
api_post_soft 'extension/activate' '{"id":"quizontaldomains","type":"mod"}'
# Client profile picture upload (replaces the Gravatar flow).
api_post_soft 'extension/activate' '{"id":"quizontalavatar","type":"mod"}'
api_post_soft 'extension/activate' '{"id":"quizontalhostingtrial","type":"mod"}'
api_post_soft 'extension/activate' '{"id":"quizontalverification","type":"mod"}'
# Email confirmation stays required for a free trial, but must not lock every
# existing customer into their Profile page. The Quizontal modules send and
# verify emails themselves, then gate only the trial-start route.
CLIENT_CONFIG=$(api_post 'extension/config_get' '{"ext":"mod_client"}')
CLIENT_CONFIG_PAYLOAD=$(jq -c '(.result // {}) + {ext:"mod_client", require_email_confirmation:false}' <<<"$CLIENT_CONFIG")
api_post 'extension/config_save' "$CLIENT_CONFIG_PAYLOAD"
# Reconnect every active module so core Invoice, Support, Order, and Client
# lifecycle emails fire as well as the custom receipt notification.
api_post 'hook/batch_connect' '{}'
api_post 'email/batch_template_generate' '{}'
api_post 'email/batch_template_enable' '{}'
api_post 'email/template_reset' '{"code":"mod_quizontalbanktransfer_receipt_submitted"}'
api_post 'email/template_reset' '{"code":"mod_quizontalbanktransfer_receipt_status"}'
api_post_soft 'email/template_reset' '{"code":"mod_client_confirm"}'
for template_code in mod_quizontalhostingtrial_reminder mod_quizontalhostingtrial_suspended mod_quizontalhostingtrial_continued; do
    api_post_soft 'email/template_reset' "{\"code\":\"$template_code\"}"
done
for template_code in mod_invoice_created mod_invoice_paid mod_invoice_payment_reminder mod_invoice_due_after mod_support_ticket_open mod_support_ticket_staff_reply mod_support_ticket_staff_close mod_client_signup mod_client_password_reset_request; do
    api_post 'email/template_reset' "{\"code\":\"$template_code\"}"
done
# Server-ready notification: bind its database template to the shipped file.
# email_template rows persist forever once created — an early auto-generated
# stub row kept overriding the file and mailed customers FOSSBilling-branded
# placeholder content. The reset must run on every activation.
api_post_soft 'email/template_reset' '{"code":"mod_serviceinterserver_ready"}'
# Retired generic "Cloud service update" notifications (activated/renewed/
# suspended/unsuspended/canceled) had database rows created by batch template
# generation; deleting the module files never touches those rows, so purge
# them through the module API. No-op when they are already gone.
api_post_soft 'serviceinterserver/purge_retired_email_templates' '{}'
api_post 'quizontalbanktransfer/normalize_email_subjects' '{}'
api_post 'quizontalbanktransfer/enable_client_email_history' '{}'
api_post 'quizontalbanktransfer/configure_invoice_attachment_delivery' '{}'
EMAIL_PAYLOAD=$(jq -n --arg email "$ADMIN_EMAIL" '{email:$email}')
api_post 'quizontalbanktransfer/set_notification_email' "$EMAIL_PAYLOAD"

echo "Receipt notifications will be sent to customers and $ADMIN_EMAIL."
echo 'Quizontal Cloud Bank Transfer is active and its checkout hook is connected.'
echo "Settings: $BILLING_URL/admin/extension/settings/quizontalbanktransfer"
echo "Customer page: $BILLING_URL/quizontalbanktransfer"
