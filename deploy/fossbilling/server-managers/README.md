# Quizontal server-manager overrides

## Purpose

`Directadmin.php` here is a **drop-in replacement** for FOSSBilling 0.7.1 stock
`src/library/Server/Manager/Directadmin.php`, installed to
`$FOSSBILLING_DIR/library/Server/Manager/Directadmin.php` by
`deploy/install-interserver-provisioning.sh` (same pattern as the Porkbun
registrar adapter).

## What changes vs stock

Everything is **identical** (same API calls, same behaviour) **except** the
error messages shown to users:

| Stock message leaked | Now users see |
|---|---|
| `HttpClientException: HTTP/1.1 403 Forbidden returned for "https://<supplier-host>:2222/CMD_API_..."` | "We could not reach the hosting server..." (+ full detail in the app log) |
| `Failed to API_USER_PASSWD on the DirectAdmin server, check the error logs...` | "We could not apply this change to the hosting account..." |
| `DirectAdmin does not support username changes (9999)` | "Usernames cannot be changed after the hosting account is created." |
| `Failed to connect to the DirectAdmin server...` | "Failed to connect to the hosting server..." |
| `Server Manager DirectAdmin Error: "Account is not suspended"` | "This hosting account is not suspended." |
| `Failed to retrieve user packages: <raw>` | neutral message (+ raw reason in the app log) |

**Design rule:** no supplier names, API command names, URLs, HTTP codes or
credentials ever reach end users. Administrators keep full diagnostics in
`$FOSSBILLING_DIR/data/log/application.log`, e.g.:

```bash
tail -n 50 /opt/lampp/htdocs/data/log/application.log
# look for: "CMD_API_USER_PASSWD rejected by the hosting server: <reason>"
```

## Revert

Re-copy the stock file from a vanilla FOSSBilling 0.7.1 package:

```bash
curl -sL "https://raw.githubusercontent.com/FOSSBilling/FOSSBilling/0.7.1/src/library/Server/Manager/Directadmin.php" \
  | sudo tee /opt/lampp/htdocs/library/Server/Manager/Directadmin.php >/dev/null
```
