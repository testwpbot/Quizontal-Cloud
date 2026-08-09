# Quizontal Cloud VPS provisioning

A FOSSBilling 0.7 module for wallet-paid VPS orders with branded order controls, test/live modes, provider validation, guarded live ordering, reconciliation, service synchronization, usage display, one-time encrypted credentials, and power controls.

## Install

```bash
export FOSSBILLING_DIR=/opt/lampp/htdocs
sudo -E bash deploy/install-interserver-provisioning.sh
FOSSBILLING_URL=http://billing.localhost bash deploy/activate-interserver-provisioning.sh
php artisan fossbilling:sync-products --force
```

Activation defaults to **Test mode**. Test mode sends validation requests only and never purchases a server.

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
