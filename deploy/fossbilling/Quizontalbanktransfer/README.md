# Quizontal Cloud Bank Transfer for FOSSBilling

A wallet-first bank transfer workflow for FOSSBilling 0.8.5:

- Customers submit a wallet deposit amount, bank reference, and JPG/PNG/PDF receipt.
- Receipts are stored under FOSSBilling's private `data/uploads` directory.
- Administrators review, approve, or reject submissions.
- Approval records the manual transaction, marks the deposit invoice paid, and credits the wallet.
- The `onBeforeClientCheckout` hook blocks product checkout when wallet balance is insufficient.

## Install

From the Quizontal Cloud repository:

```bash
export FOSSBILLING_DIR=/var/www/fossbilling
sudo -E bash deploy/install-quizontal-bank-transfer.sh
```

In FOSSBilling Admin:

1. Go to **Extensions** and activate **Quizontal Cloud Bank Transfer**.
2. Open the extension settings and enter bank details.
3. Enable FOSSBilling's **Custom** payment gateway, title it `Manual Bank Transfer`, accept `LKR`, and allow one-time payments.
4. Enable the **ClientBalance** gateway.
5. Keep **Invoice > Add Funds** enabled and set sensible minimum/maximum deposit values.
6. In **Hooks**, reconnect hooks if `onBeforeClientCheckout` was not connected automatically.

Customer page:

```text
/client-area/quizontal-bank-transfer
```

Admin review page:

```text
/client-area/admin/quizontal-bank-transfer
```

Paths vary if your FOSSBilling base/admin URLs are customized.

## Important controls

- Approval must happen only after checking the actual bank account, never from a receipt alone.
- Each bank transaction ID can be used only once.
- Only JPG, PNG, and PDF uploads are accepted; the default limit is 5 MB.
- Uploads are private and served only after client/admin authorization.
- The module retains its table and receipt files on uninstall for financial audit safety.
- Back up the FOSSBilling database and `data/uploads/quizontal-bank-transfer`.

## Upgrade warning

This module uses FOSSBilling module APIs available in 0.8.5. Test it in staging before upgrading FOSSBilling.
