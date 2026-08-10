# Porkbun domain registrar adapter

Files in this directory are domain registrar adapters. The deploy script
(`deploy/install-interserver-provisioning.sh`) copies each `*.php` file here
into FOSSBilling's `library/Registrar/Adapter/` directory, where FOSSBilling
0.7 discovers it automatically (the admin "Domain registrars" dropdown lists
every file in that directory).

## Porkbun.php

Full-featured adapter for the [Porkbun API v3](https://porkbun.com/api/json/v3/documentation):
availability checks, registration, renewal, inbound transfers, nameserver
updates, registrant contact updates, domain sync and automatic WHOIS privacy.

### Why Porkbun

- ICANN-accredited US registrar, highest Trustpilot rating in the industry
- Near-cost pricing (.com ~ $10.99 flat register/renew), frequent first-year
  promos, and promos apply to API orders because registration cost must match
  the live quote
- Free API with a real sandbox, dry-run support and idempotency keys
- No deposits, no slabs: registrations are billed from ordinary account credit

### One-time Porkbun account setup

1. Create a free account at porkbun.com and verify email **and phone**.
2. Buy any one domain through the **website** (a $1–2 promo TLD is enough).
   Porkbun only unlocks API registrations *after* an account has completed one
   normal registration.
3. At [porkbun.com/account/api](https://porkbun.com/account/api):
   - create a **sandbox key** (`pk1_sb_…` / `sk1_sb_…`) for testing
   - create a **live key** (`pk1_…` / `sk1_…`) for production; restrict it to
     your server's public IP (CIDR) for safety
   - enable **Opt In All Domains** so newly registered domains are immediately
     API-manageable
   - optionally set a monthly API spend cap + low-balance alerts
4. Top up account credit with small amounts as customer orders come in
   (one .com costs ~ $11).

### FOSSBilling setup

1. Deploy: `sudo -E bash deploy/install-interserver-provisioning.sh` (also
   copies this adapter; nothing else needs it).
2. Admin → **Domain registration → New domain registrar** → choose **Porkbun**.
3. Enter the sandbox keys and set **Test mode = Yes**. Save.
4. Attach TLDs (Domain registration → TLD management) and set your retail
   prices — use a lower registration price than renewal price for first-year
   promos. Mark each TLD as using the Porkbun registrar.
5. Test end-to-end (free, no charges): check availability from the store
   front, place an order, activate it — the adapter registers a fake domain in
   the sandbox. Renewals and transfers work the same way.
   `sandbox/reset` on the Porkbun side gives you a clean slate anytime.
6. Go live: edit the registrar, paste the **live keys**, set Test mode = No.

The adapter refuses to run when keys and Test mode disagree (live keys in
test mode or sandbox keys live), so a misconfiguration can never charge real
money by accident.

### Safety behaviour of the adapter

- Every billable call is preceded by Porkbun's **Dry Run** preview; the adapter
  only commits when `wouldSucceed` is true.
- Commits are sent with an **Idempotency-Key**, so a retried request can never
  register or charge twice.
- Prices are always quoted live (`checkDomain`); the adapter never invents a
  cost, and Porkbun rejects mismatched quotes instead of overcharging.
- After a successful registration, nameserver assignment is best-effort: if it
  fails the order still succeeds (the domain is already paid for) and the
  failure is logged for staff.
- API errors are logged without credentials; rate-limit responses include the
  number of seconds until retry is allowed.

### Not available via the API (by design of Porkbun)

These actions stay in the Porkbun control panel; the adapter says so when they
are requested instead of failing silently:

- Outbound transfer codes (EPP) — Domain Management → Details → Authorization
  Code
- Registrar lock/unlock — new domains are locked by default; toggle in the
  same Details page
- Domain deletion — domains simply expire when not renewed
- TLDs with special registry rules the API cannot submit (`.uk` transfers,
  `.us`, `.ca`, `.eu`, `.au` and similar are website-only) — the adapter checks
  `getRegistrationRequirements` and explains this instead of erroring

### Rate limits (defaults; configurable per key)

| Operation | Default |
|---|---|
| Availability check | 1 per 10 s |
| Register / renew / transfer | 1 attempt per 10 s, 50 successful per day |
| Dry runs | Not rate-limited |

Far above normal store volumes; Porkbun can raise them if ever needed.
