# Quizontal Cloud InterServer provisioning (validation-only)

This FOSSBilling 0.7 module adds the `interserver` product type, branded location/OS selectors, private product mappings, validation-only activation, cost checks, and customer/admin service status cards.

## Safety

This version contains only `PUT /vps/order` validation. It contains no `POST /vps/order` purchase operation and cannot buy a VPS.

## Install

```bash
export FOSSBILLING_DIR=/opt/lampp/htdocs
sudo -E bash deploy/install-interserver-provisioning.sh
FOSSBILLING_URL=http://billing.localhost bash deploy/activate-interserver-provisioning.sh
php artisan fossbilling:sync-products --force
```

The activation helper copies the existing InterServer URL/key from Laravel `.env` into FossBilling's encrypted extension configuration. Product sync migrates synchronized products from `custom` to `interserver` and writes platform, plan-unit, and expected provider-cost mappings.

## Validation flow

After a wallet-paid invoice activates an order, the module:

1. Builds a request from private product mappings and customer choices.
2. Generates a temporary password that is never stored or displayed.
3. Sends `PUT /vps/order`.
4. Redacts password/secret fields from the stored response.
5. Compares current provider cost to imported cost.
6. Stores `validated`, `validation_failed`, or `price_review` status.
7. Leaves the order pending setup because no VPS was purchased.
