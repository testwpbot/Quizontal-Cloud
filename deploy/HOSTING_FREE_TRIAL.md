# Verified 7-day web-hosting trial

This feature applies **only** to normal FOSSBilling `hosting` products provisioned through the existing DirectAdmin server manager. It does not apply to InterServer VPS, domains, or any other product type.

## Customer experience

1. Customer signs up in the FOSSBilling client area and verifies their email address.
2. Customer opens **Hosting Trial** in the client-area menu and saves a WhatsApp number with country code (for example, `+94771234567`).
3. Customer selects a hosting package marked as a 7-day trial. No credit/debit card is required.
4. FOSSBilling provisions the standard DirectAdmin hosting account and records an exact seven-day trial end time.
5. About 24 hours before expiry, the module changes the order to its normal recurring price, creates an invoice, and sends a **Pay and continue hosting** email.
6. If the continuation invoice is paid, hosting remains active. If payment reaches the system after suspension, the module unsuspends the DirectAdmin account.
7. If it is not paid when the trial ends, the module calls FOSSBilling's normal `Order::suspendFromOrder()` lifecycle. That calls the configured DirectAdmin manager's `suspendAccount()` method.

A suspended DirectAdmin account keeps its files and databases on the server, but its website is not publicly available. The customer can still access the Quizontal Cloud client area and pay the invoice. Do **not** configure automatic cancellation for the trial account until you have decided your data-retention policy.

## Required configuration

### 1. Email verification (mandatory)

In FOSSBilling Admin, open **Clients → Settings** and enable **Require email confirmation**. Test that a new customer receives the sign-up verification email and that the confirmation link sets `client.email_approved`.

The trial checkout guard rejects unverified accounts. Do not disable this setting: free hosting without verified email is easy to abuse.

### 2. Install and activate the module

From this repository, rerun the regular FOSSBilling deployment and activation scripts:

```bash
export FOSSBILLING_DIR=/var/www/billing
sudo -E bash deploy/install-interserver-provisioning.sh
bash deploy/activate-quizontal-bank-transfer.sh
```

The second script also activates `quizontalhostingtrial`, resets its three email templates, and reconnects FOSSBilling hooks. Confirm the module appears under **Extensions**.

FOSSBilling's cron must run at least every five minutes. The end time is exact, but the suspension/reminder happens on the first cron run at or after that time.

### 3. Mark only selected web-hosting products as trials

The only eligible product is the existing **Starter Hosting** product with ID **98**. Keep its normal recurring price and DirectAdmin configuration exactly as they are. Do not create a Rs. 0 product and do not publish a public coupon.

The trial-start route creates a short-lived verified intent and applies the private `QC_INTERNAL_STARTER_7D` 100% first-invoice promotion only at checkout. That promotion is limited to Product 98, does not recur on renewals, and is rejected if someone tries to enter it outside the verified trial flow. Business, Premium, domains, and VPS products remain paid-only.

### 4. Payment method

Keep the existing Manual Bank Transfer gateway enabled. The day-six invoice can be paid using the existing receipt-upload flow. A submitted but not yet approved receipt is still an unpaid invoice, so either approve genuine payments before expiry or tell customers to submit at least one business day early.

## Operational rules

- One historical trial is allowed for each customer account.
- Trial checkout requires **both** a verified email and a WhatsApp number.
- WhatsApp is stored in the module's `quizontal_hosting_trial_profile` table, not in a public page.
- The trial module does not send WhatsApp messages itself. It collects the number for your staff/support workflow; connect a WhatsApp Business provider later only after obtaining suitable consent.
- The trial account is suspended—not deleted—on expiry when its continuation invoice is unpaid.
- Files and databases remain in DirectAdmin during suspension. Define and publish your own final deletion period (recommended: send a final warning after 14 days and only then terminate, if desired).
- The module deliberately never gives free trials to VPS products.

## Test checklist

Use a non-production DirectAdmin account first:

1. Enable FOSSBilling email confirmation.
2. Sign up a test client and verify its email.
3. Visit `/hosting-trial`, save a WhatsApp number, and ensure the menu page is visible.
4. Add a trial hosting product to the cart and check out without a card.
5. Confirm a DirectAdmin hosting account is created and a row exists in `quizontal_hosting_trial`.
6. Temporarily set `ends_at` to a few minutes ahead in the database, run FOSSBilling cron, and confirm a continuation invoice plus reminder email are created.
7. Leave the invoice unpaid, run cron after expiry, and confirm the DirectAdmin account becomes suspended and the site is offline.
8. Approve a genuine/test invoice payment and confirm the account is unsuspended and the website returns.

Never test an automatic suspension against a customer site without their consent.
