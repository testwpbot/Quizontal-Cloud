#!/usr/bin/env bash
set -Eeuo pipefail
REPO_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
ENV_FILE=${ENV_FILE:-$REPO_DIR/.env}
[[ -f "$ENV_FILE" ]] || { echo "Missing $ENV_FILE" >&2; exit 1; }
read_env() { sed -n "s/^$1=//p" "$ENV_FILE" | head -n1 | tr -d '\r' | sed -e 's/^"//' -e 's/"$//'; }
ADMIN_KEY=${FOSSBILLING_ADMIN_API_KEY:-$(read_env FOSSBILLING_ADMIN_API_KEY)}
BILLING_URL=${FOSSBILLING_URL:-$(read_env FOSSBILLING_URL)}
INTERSERVER_KEY=${INTERSERVER_API_KEY:-$(read_env INTERSERVER_API_KEY)}
INTERSERVER_URL=${INTERSERVER_API_URL:-$(read_env INTERSERVER_API_URL)}
[[ -n "$ADMIN_KEY" && -n "$BILLING_URL" && -n "$INTERSERVER_KEY" ]] || { echo 'FOSSBILLING_ADMIN_API_KEY, FOSSBILLING_URL, and INTERSERVER_API_KEY are required.' >&2; exit 1; }
BILLING_URL=${BILLING_URL%/}; INTERSERVER_URL=${INTERSERVER_URL:-https://my.interserver.net/apiv2}
api() { local endpoint=$1 data=$2 response; response=$(curl -sS -u "admin:$ADMIN_KEY" -H 'Content-Type: application/json' -X POST "$BILLING_URL/api/admin/$endpoint" -d "$data"); if [[ $(jq -r '.error // empty' <<<"$response") != '' ]]; then jq -r '.error.message' <<<"$response" >&2; exit 1; fi; jq . <<<"$response"; }
api extension/activate '{"id":"serviceinterserver","type":"mod"}'
api hook/batch_connect '{"mod":"serviceinterserver"}'
CONFIG=$(jq -n --arg url "$INTERSERVER_URL" --arg key "$INTERSERVER_KEY" '{ext:"mod_serviceinterserver",api_url:$url,api_key:$key,mode:"validate_only",cost_tolerance_usd:"0.01"}')
api extension/config_save "$CONFIG"
echo 'Serviceinterserver is active in VALIDATION-ONLY mode. No provider POST order operation exists in this module.'
echo "Settings: $BILLING_URL/admin/extension/settings/serviceinterserver"
