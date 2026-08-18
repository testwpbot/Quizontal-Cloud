# Quizontal Cloud Free Trial for FOSSBilling

A self-service **7-day starter hosting trial** for FOSSBilling 0.7/0.8. A visitor
goes from "never heard of us" to a live, provisioned DirectAdmin account in one
page, without a payment method and without staff involvement.

## What the customer sees

1. **Email** — enter an address; a six-digit code is emailed immediately.
2. **Verify** — the code is typed into six auto-advancing boxes (paste works too).
3. **WhatsApp** — number is normalised to E.164 and checked for reuse.
4. **Domain** — an existing domain the customer already owns.
5. **Account** — first/last name and a password (skipped for signed-in customers).
6. **Review** — plan, price, dates, and everything entered, with a terms tick-box.
7. **Provisioning loader** — a staged progress view while the account is built.
8. **Redirect** — straight to the ordinary service details page,
   `/order/service/manage/<order_id>`. A trial is a normal service, not a special case.

The wizard lives at **`/free-trial`** (also reachable at `/quizontalfreetrial`).

## What happens on the server

`provision()` performs, in order:

1. Re-checks eligibility and the domain (things change while a customer types).
2. Writes the trial register row **first** — the UNIQUE keys are the atomic gate
   against two concurrent submissions.
3. Creates the FOSSBilling client (or reuses the signed-in one) and signs them in.
4. Creates a zero-priced, invoice-free order for the trial product, with
   `server_id` / `hosting_plan_id` taken from the product's own configuration —
   exactly the config a paid cart order would produce.
5. Calls `Order::activateOrder()`, which runs `Servicehosting::action_activate()`
   and creates the account on DirectAdmin.
6. Stamps `expires_at` on the order and the trial row.
7. Emails the customer their trial details.

Activation is called explicitly rather than via `createOrder(['activate' => true])`,
because the latter swallows provisioning errors into a silent `failed_setup`
order. Here a failure is recorded, shown to the customer in plain language, and
the row is marked `failed` so the same visitor may retry over it.

## One trial per customer

Four independent axes, each enforced in the wizard *and* by a database UNIQUE key:

| Axis | Protection |
|---|---|
| Email | Normalised first — plus-tags stripped everywhere, dots stripped for Gmail — so `a.b+free@gmail.com` and `ab@gmail.com` are one customer. |
| WhatsApp | Stored as `+94771234567` regardless of how it was typed. |
| Domain | Rejected if it is an existing hosting account, sits in a pending/active/suspended order, belongs to another trial, or is one of our own host names. |
| Client account | A signed-in customer with any prior trial row is refused. |

Plus a configurable **IP throttle** (default: 3 trials per connection per day),
per-email code send limits, per-code attempt limits, and a session fingerprint
that stops a code issued in one browser being redeemed in another.

Trial rows are **never deleted** — `uninstall()` deliberately keeps the tables.
A terminated trial still blocks a repeat signup.

## Lifecycle

Driven by the standard FOSSBilling cron through the `onBeforeAdminCronRun` hook,
so it runs *before* core's own expiry sweep and keeps exact control of the dates.
Every transition is guarded by its own timestamp column and is safe to re-run.

| When | Action | Email |
|---|---|---|
| 2 days before expiry | reminder | `mod_quizontalfreetrial_reminder` |
| At expiry | order suspended (DirectAdmin account suspended) | `mod_quizontalfreetrial_expired` |
| Grace period after expiry (default 7 days) | order cancelled (DirectAdmin account deleted) | `mod_quizontalfreetrial_terminated` |

Upgrading before the grace period ends restores the same account with its files,
databases and mailboxes intact.

## Install

From the Quizontal Cloud repository:

```bash
export FOSSBILLING_DIR=/var/www/fossbilling
sudo -E bash deploy/install-quizontal-free-trial.sh
bash deploy/activate-quizontal-free-trial.sh
```

The activation helper reads `FOSSBILLING_URL` and `FOSSBILLING_ADMIN_API_KEY`
from Laravel's `.env` without printing secrets. It activates the module,
connects the cron hook, generates and resets the five email templates, seeds the
configuration, and finally runs the provisioning self-check.

## Configuring the trial product

The trial product must be an **enabled hosting product** with a DirectAdmin
server and a hosting plan selected on its *Configuration* tab. By default the
module uses **product ID 98**; change it in the module settings or set
`FREE_TRIAL_PRODUCT_ID` in Laravel's `.env` before running the activation helper.

Recommended plan setup in DirectAdmin: a dedicated `trial` package with modest
quotas, so a trial account can never be mistaken for a paid one.

The admin register at **Orders → Free Trials** shows a red banner listing exactly
what is missing whenever provisioning is not ready, and the same check is
available through the API:

```bash
curl -u "admin:$FOSSBILLING_ADMIN_API_KEY" -X POST \
  "$FOSSBILLING_URL/api/admin/quizontalfreetrial/diagnose" -d '{}'
```

## Administration

**Orders → Free Trials** lists every trial with its status, domain, WhatsApp
number and end date, and offers:

- **+7 days** — extends the trial and unsuspends it if needed, without granting
  a second trial.
- **Terminate** — cancels the order, which deletes the DirectAdmin account.
- **Run lifecycle now** — processes reminders/suspensions/terminations
  immediately instead of waiting for the next cron tick.

## Settings

`Extensions → Settings → Quizontal Cloud Free Trial`

| Setting | Default | Notes |
|---|---|---|
| Accept new signups | on | Off closes the wizard; running trials continue. |
| Trial product ID | 98 | Must be an enabled hosting product. |
| Trial length (days) | 7 | |
| Grace period (days) | 7 | `0` deletes the account at expiry. |
| Reminder days before end | 2 | `0` disables the reminder. |
| Code validity (minutes) | 15 | |
| Seconds between resends | 60 | |
| Maximum code attempts | 6 | Per issued code. |
| Maximum codes per email per hour | 5 | Rolling window. |
| Maximum trials per IP per day | 3 | `0` disables the throttle. |
| Default country code | 94 | Applied to local numbers like `0771234567`. |
| Storefront URL | — | Blocked as a trial domain. |
| Terms note | — | Shown beside the tick-box on the review screen. |

## Database

| Table | Purpose |
|---|---|
| `quizontal_free_trial` | The permanent trial register and the one-per-customer gate. |
| `quizontal_free_trial_code` | Email verification codes (hashed), attempt counters and send windows. |

Codes are stored with the same password hasher FOSSBilling uses for accounts, so
a database leak never exposes a live verification code.

## Emails

| Template | Sent |
|---|---|
| `mod_quizontalfreetrial_code` | Verification code — sent immediately, never queued. |
| `mod_quizontalfreetrial_ready` | Trial activated, with next steps for pointing the domain. |
| `mod_quizontalfreetrial_reminder` | Before the trial ends. |
| `mod_quizontalfreetrial_expired` | Trial ended, account suspended, data kept. |
| `mod_quizontalfreetrial_terminated` | Grace period over, account removed. |

All five are branded to match the existing Quizontal Cloud transactional mail.
Re-running the activation helper resets them from the files in this directory.
