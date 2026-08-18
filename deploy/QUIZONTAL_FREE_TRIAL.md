# Quizontal Cloud 7-day free trial

A self-service seven-day trial of the starter hosting package, provisioned
automatically on DirectAdmin with no payment details and no staff involvement.

See [`fossbilling/Quizontalfreetrial/README.md`](fossbilling/Quizontalfreetrial/README.md)
for the full reference. This page is the operator summary.

## Deploy

```bash
# Point this at the installation that FOSSBILLING_URL serves.
# Nginx/Apache guide layout: /var/www/fossbilling
# XAMPP for Linux:           /opt/lampp/htdocs
sudo -E env FOSSBILLING_DIR=/var/www/fossbilling \
  bash deploy/install-quizontal-free-trial.sh

bash deploy/activate-quizontal-free-trial.sh
```

If a machine carries more than one FOSSBilling tree, installing into the wrong
one succeeds quietly and activation then fails with
`Module quizontalfreetrial manifest file is missing`. Both scripts now name the
other candidates they can see, so the mismatch is obvious.

Optional Laravel `.env` values read by the activation helper:

```dotenv
# Trial plan in FOSSBilling. Must be an enabled hosting product with a
# DirectAdmin server and hosting plan on its configuration tab.
FREE_TRIAL_PRODUCT_ID=98
FREE_TRIAL_DAYS=7
# Shown as a "Start free trial" button on the trial plan's card on /hosting.
# Leave blank to derive it from FOSSBILLING_URL + /quizontalfreetrial.
FOSSBILLING_FREE_TRIAL_URL=
```

The activation helper finishes by verifying that the trial product can actually
provision, and prints exactly what is missing if it cannot.

## Customer journey

1. `https://billing.example.com/quizontalfreetrial` (`/free-trial` redirects here)
2. Email → six-digit code → WhatsApp number → existing domain → name and password
3. Final review screen, then a provisioning loader
4. Lands on the normal service details page, `/order/service/manage/<order_id>`,
   already signed in

## Lifecycle

| Day | What happens |
|---|---|
| 5 | Reminder email |
| 7 | Trial ends — order suspended, DirectAdmin account suspended, data kept |
| 14 | Grace period over — order cancelled, DirectAdmin account deleted |

Driven by the standard FOSSBilling cron. Confirm it is installed:

```cron
*/5 * * * * www-data php /var/www/billing/cron.php >/dev/null 2>&1
```

## Abuse protection

One trial per customer is enforced on the normalised email, the E.164 WhatsApp
number, the domain and the client account — in the wizard and again by database
UNIQUE keys. An IP throttle, per-email code send limits, per-code attempt limits
and a browser-session fingerprint sit on top. Trial records are retained
permanently, so terminating a trial does not hand out a second one.

## Operating

- **Orders → Free Trials** — register, health banner, extend, terminate, and a
  "Run lifecycle now" button.
- **Extensions → Settings → Quizontal Cloud Free Trial** — product ID, trial and
  grace length, code and throttle limits.

## Before launch

1. Create a dedicated DirectAdmin package for trials with modest quotas.
2. Point the trial product at it and confirm the health banner is green.
3. Run one end-to-end trial with a real mailbox and a spare domain.
4. Set the clock forward on a staging copy, or use **Run lifecycle now**, to
   confirm the reminder, suspension and termination emails all arrive.
