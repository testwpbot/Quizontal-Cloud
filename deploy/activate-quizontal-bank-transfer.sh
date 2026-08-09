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
[[ -n "$API_KEY" ]] || { echo 'FOSSBILLING_ADMIN_API_KEY is missing.' >&2; exit 1; }
[[ -n "$BILLING_URL" ]] || { echo 'FOSSBILLING_URL is missing.' >&2; exit 1; }
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

api_post 'extension/activate' '{"id":"quizontalbanktransfer","type":"mod"}'
api_post 'hook/batch_connect' '{"mod":"quizontalbanktransfer"}'

echo 'Quizontal Cloud Bank Transfer is active and its checkout hook is connected.'
echo "Settings: $BILLING_URL/admin/extension/settings/quizontalbanktransfer"
echo "Customer page: $BILLING_URL/quizontalbanktransfer"
