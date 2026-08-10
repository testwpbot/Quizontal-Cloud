# Quizontal Cloud Bank Transfer for FOSSBilling

A wallet-first bank transfer workflow for FOSSBilling 0.8.5:

- Customers submit a wallet deposit amount, bank reference, and JPG/PNG/PDF receipt.
- Receipts are stored under FOSSBilling's private `data/uploads` directory.
- Wallet pages show the complete submission history with color-coded statuses.
- Invoice payment methods use branded icons, clear labels, and wallet-only rules: deposits show Manual Bank Transfer; product and renewal invoices show Quizontal Cloud Wallet.
- Administrators review, approve, or reject submissions.
- Approval records the manual transaction, marks the deposit invoice paid, credits the wallet, and retries existing unpaid invoices against the new balance.
- The `onBeforeClientCheckout` hook blocks product checkout when wallet balance is insufficient.
- New orders and renewal invoices use FossBilling's built-in credit payment automatically when the wallet covers the total.

## Install

From the Quizontal Cloud repository:

```bash
export FOSSBILLING_DIR=/var/www/fossbilling
sudo -E bash deploy/install-quizontal-bank-transfer.sh
```

Activate the module and connect its checkout hook through the API (this works even when your admin theme does not show Extensions or Hooks menus):

```bash
bash deploy/activate-quizontal-bank-transfer.sh
```

The script reads `FOSSBILLING_URL`, `FOSSBILLING_ADMIN_API_KEY`, and `QUIZONTAL_ADMIN_EMAIL` from Laravel's `.env` without displaying secrets. It generates the receipt-submitted email template in FossBilling Admin, synchronizes the administrator recipient, and prints the direct module settings URL.

Then:

1. Open the printed extension settings URL and enter bank details.
2. Enable FOSSBilling's **Custom** payment gateway, title it `Manual Bank Transfer`, accept `LKR`, and allow one-time payments. Put the following in **Single payment information** (adjust the host if needed):

```html
<p>Transfer the invoice amount to the Quizontal Cloud bank account, then upload your receipt.</p>
<p><a class="btn btn-primary" href="http://billing.localhost/quizontalbanktransfer?invoice_hash={{ invoice.hash }}">Upload payment receipt</a></p>
```

3. Enable the **ClientBalance** gateway.
4. Keep **Invoice > Add Funds** enabled and set sensible minimum/maximum deposit values.

Customer page:

```text
/client-area/quizontalbanktransfer
```

Admin review page:

```text
/client-area/admin/quizontalbanktransfer
```

Paths vary if your FOSSBilling base/admin URLs are customized.

## Receipt submission email

Activation reconnects hooks for all active modules and enables generated transactional email templates. It installs Quizontal-branded defaults for FossBilling-generated invoices, payment confirmations/reminders, support ticket events, account signup, and password reset. Invoice emails use the core `mod_invoice_created` template and attach the official PDF returned by FossBilling's own invoice PDF endpoint; no separate/custom invoice document is generated. Activation also creates editable module templates:

```text
mod_quizontalbanktransfer_receipt_submitted
mod_quizontalbanktransfer_receipt_status
```

Each receipt submission sends an immediate confirmation to the customer, pauses briefly for local SMTP throttling, and then sends an administrator notification to `QUIZONTAL_ADMIN_EMAIL`. The admin version avoids attachments and external/action links; the administrator signs in normally and opens **Invoices → Bank Transfer Receipts**. Inbox placement still depends primarily on the sender domain's SPF, DKIM, DMARC, and reverse-DNS configuration.

Core FOSSBilling lifecycle emails are queued. Install `deploy/install-xampp-fossbilling-cron.sh` so invoice-created, invoice-paid, ticket-opened, staff-replied, renewal, and other queued notifications are delivered every five minutes. Activation also enables FOSSBilling email logging, which populates each customer's **Email** page for newly sent messages. Emails sent before logging was enabled are not reconstructed retroactively.

## Important controls

- Approval must happen only after checking the actual bank account, never from a receipt alone.
- Each bank transaction ID can be used only once.
- Only JPG, PNG, and PDF uploads are accepted; the default limit is 5 MB.
- Uploads are private and served only after client/admin authorization.
- The module retains its table and receipt files on uninstall for financial audit safety.
- Back up the FOSSBilling database and `data/uploads/quizontal-bank-transfer`.

## Upgrade warning

This module uses FOSSBilling module APIs available in 0.8.5. Test it in staging before upgrading FOSSBilling.
