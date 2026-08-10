# Quizontal Cloud VPS provisioning

A FOSSBilling 0.7 module for wallet-paid VPS orders with branded order controls, test/live modes, provider validation, guarded live ordering, reconciliation, service synchronization, usage display, one-time encrypted credentials, and power controls.

## Install

```bash
export FOSSBILLING_DIR=/opt/lampp/htdocs
sudo -E bash deploy/install-interserver-provisioning.sh
FOSSBILLING_URL=http://billing.localhost bash deploy/activate-interserver-provisioning.sh
php artisan fossbilling:sync-products --force
```

The first activation defaults to **Test mode**. Later activation/helper runs update credentials without overwriting the administrator's existing Test/Live selection. Test mode sends validation requests only and never purchases a server.

**Upgrading an existing deployment:** copy the module and theme overrides again with `install-interserver-provisioning.sh`, then re-run `activate-interserver-provisioning.sh`. The activation step re-runs `hook/batch_connect`, which registers the new event listeners (`onBeforeProductAddedToCart`, `onBeforeClientCheckout`, `onAfterAdminCronRun`). The `ready_at` database column is created lazily and never requires manual migration.

## Hostname uniqueness guard

Hostnames must be unique across the cloud account. The module enforces this **before** money moves:

1. **Add to cart** (`onBeforeProductAddedToCart`) — the customer immediately sees “This hostname is already in use. Please enter a different hostname.” if the name exists in another live order, elsewhere in the same cart, or in the infrastructure account. The item is never added to the cart.
2. **Checkout** (`onBeforeClientCheckout`) — a second gate runs before FOSSBilling creates orders, invoices, or wallet charges, so a race between two tabs or sessions cannot result in a paid duplicate.
3. **Activation** — existing behavior keeps guarding the provider POST and reconciles retry races safely.

The infrastructure account lookup is fail-open: if the API is temporarily unreachable the local checks still apply and activation-time validation remains the final protection.

## Customer-facing lifecycle

A server is only shown as **Active** to the customer once the infrastructure account reports an IP address for it. Between payment and IP assignment the client portal shows a calm **“Setting up”** state with an honest expectation (“most servers are ready within 30 minutes of payment”), a progress tracker, and an auto-refreshing manage page. Power controls and password reveal unlock only when the server is actually ready.

Internally the module keeps its detailed statuses (`pending_validation`, `validated`, `submitting`, `provisioned`, `manual_review`, …) for administrators; customers see `provisioning_state` (`setting_up` / `attention` / `active`) which is exposed through `toApiArray`.

Each FOSSBilling cron run also synchronizes provisioned services that still have no IP (`onAfterAdminCronRun`, up to 15 services per run). The first sync that sees an IP sets `ready_at` and emails the customer with `mod_serviceinterserver_ready.html.twig`, so the portal state and the customer notification happen at the exact moment the server becomes usable.

## Test mode

After a wallet-paid invoice, the module validates the selected platform, plan size, location, OS, hostname, and current provider cost. It stores a redacted response and leaves the order pending setup without purchasing infrastructure.

## Live mode

Live mode is deliberately locked behind administrator settings and the exact confirmation phrase:

```text
ENABLE LIVE VPS ORDERS
```

Before a live POST, the module validates pricing and searches existing services for the same hostname. It records a `submitting` state before the request. If the response cannot be reconciled to a provider service ID, it switches to `manual_review` and blocks automatic retries to reduce duplicate-order risk.

Live services expose Quizontal-branded customer details: IP, hostname, power state, allocated CPU/RAM/storage/bandwidth, traffic when available, start/stop/restart controls, and a one-time root/administrator password. Provider names and private API data are not shown in customer-facing templates.

## Important

- Test with the smallest plan before enabling live mode.
- A POST can incur a real provider charge and may be non-refundable.
- Never retry a `submitting` or `manual_review` service without reconciling the provider account.
- API response shapes can change; verify the first live order manually.
- Keep database and encrypted configuration backups.
